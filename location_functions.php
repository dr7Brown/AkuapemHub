<?php
/**
 * Location & Mapping Module — shared PHP helpers.
 * Include with: require_once __DIR__ . '/location_functions.php';
 */

/**
 * Save (upsert) a location record and return its ID.
 * If the same lat/lng already exists within ~10m, returns the existing ID.
 */
function save_location(string $name, string $address, float $lat, float $lng): int {
    global $pdo;

    // Check for existing near-duplicate (within ~0.0001 degrees ≈ 11m)
    $existing = $pdo->prepare(
        'SELECT id FROM locations
         WHERE ABS(latitude - ?) < 0.0001 AND ABS(longitude - ?) < 0.0001
         LIMIT 1'
    );
    $existing->execute([$lat, $lng]);
    if ($id = $existing->fetchColumn()) {
        // Update name/address if provided
        $pdo->prepare('UPDATE locations SET location_name=?, formatted_address=?, updated_at=NOW() WHERE id=?')
            ->execute([$name, $address, $id]);
        return (int)$id;
    }

    $googleUrl = location_google_maps_url($lat, $lng);
    $osmUrl    = location_osm_url($lat, $lng);

    $pdo->prepare(
        'INSERT INTO locations (location_name, formatted_address, latitude, longitude, google_maps_url, osm_maps_url)
         VALUES (?,?,?,?,?,?)'
    )->execute([$name, $address, $lat, $lng, $googleUrl, $osmUrl]);

    return (int)$pdo->lastInsertId();
}

function get_location(int $id): ?array {
    global $pdo;
    $st = $pdo->prepare('SELECT * FROM locations WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function location_google_maps_url(float $lat, float $lng): string {
    return 'https://maps.google.com/?q=' . $lat . ',' . $lng;
}

function location_osm_url(float $lat, float $lng): string {
    return 'https://www.openstreetmap.org/?mlat=' . $lat . '&mlon=' . $lng . '&zoom=16';
}

/** Generate navigation link (Google Maps, Apple Maps, or Waze). */
function location_nav_url(float $lat, float $lng, string $app = 'google'): string {
    return match($app) {
        'apple'  => 'maps://maps.apple.com/?daddr=' . $lat . ',' . $lng,
        'waze'   => 'https://waze.com/ul?ll=' . $lat . ',' . $lng . '&navigate=yes',
        default  => 'https://maps.google.com/?daddr=' . $lat . ',' . $lng,
    };
}

/**
 * Haversine formula — distance in km between two coordinate pairs.
 */
function haversine_distance(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $R    = 6371; // Earth radius in km
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a    = sin($dLat / 2) ** 2
          + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

/**
 * Find saved locations within $radiusKm of a point.
 * Returns rows sorted by distance ASC.
 */
function get_nearby_locations(float $lat, float $lng, float $radiusKm = 5, int $limit = 20): array {
    global $pdo;
    // Rough bounding box first for index efficiency
    $deg = $radiusKm / 111.0;
    $st  = $pdo->prepare(
        'SELECT *, (
            6371 * 2 * ASIN(SQRT(
                POW(SIN((RADIANS(latitude)  - RADIANS(?)) / 2), 2) +
                COS(RADIANS(?)) * COS(RADIANS(latitude)) *
                POW(SIN((RADIANS(longitude) - RADIANS(?)) / 2), 2)
            ))
         ) AS distance_km
         FROM locations
         WHERE latitude  BETWEEN ? AND ?
           AND longitude BETWEEN ? AND ?
         HAVING distance_km <= ?
         ORDER BY distance_km ASC
         LIMIT ?'
    );
    $st->execute([
        $lat, $lat, $lng,
        $lat - $deg, $lat + $deg,
        $lng - $deg, $lng + $deg,
        $radiusKm,
        $limit,
    ]);
    return $st->fetchAll();
}

/**
 * Proxy a Nominatim search via PHP (avoids browser CORS and adds our User-Agent).
 * Returns decoded JSON array or [].
 */
function nominatim_search(string $query, int $limit = 6): array {
    $url = 'https://nominatim.openstreetmap.org/search?'
         . http_build_query([
             'q'              => $query,
             'format'         => 'json',
             'limit'          => $limit,
             'addressdetails' => 1,
             'countrycodes'   => 'gh',   // prefer Ghana results
         ]);
    $ctx = stream_context_create(['http' => [
        'method'  => 'GET',
        'header'  => 'User-Agent: AkuapemConnect/1.0 (contact@akuapemconnect.com)',
        'timeout' => 5,
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    return $raw ? (json_decode($raw, true) ?? []) : [];
}

/**
 * Reverse-geocode a lat/lng via Nominatim.
 * Returns decoded JSON object or null.
 */
function nominatim_reverse(float $lat, float $lng): ?array {
    $url = 'https://nominatim.openstreetmap.org/reverse?'
         . http_build_query([
             'lat'    => $lat,
             'lon'    => $lng,
             'format' => 'json',
         ]);
    $ctx = stream_context_create(['http' => [
        'method'  => 'GET',
        'header'  => 'User-Agent: AkuapemConnect/1.0 (contact@akuapemconnect.com)',
        'timeout' => 5,
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    return $raw ? (json_decode($raw, true) ?? null) : null;
}
