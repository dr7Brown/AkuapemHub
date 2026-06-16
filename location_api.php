<?php
/**
 * Location & Mapping API
 * Central endpoint for all location-related operations.
 *
 * Public actions  : get, nearby, distance, directions
 * Auth-required   : search, reverse  (read-only Nominatim proxy, login prevents abuse)
 * Auth + CSRF     : save
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

$action = trim($_POST['action'] ?? '');
$user   = current_user();

// ── Auth gate ─────────────────────────────────────────────────────────────────
$needsLogin = ['search', 'reverse', 'save'];
if (in_array($action, $needsLogin, true) && !$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Login required']);
    exit;
}

// ── Nominatim proxy helper ────────────────────────────────────────────────────
function nominatim_get(string $url): ?array {
    $ctx = stream_context_create(['http' => [
        'method'  => 'GET',
        'header'  => "User-Agent: AkuapemHub/1.0 (djimateyevans@gmail.com)\r\nAccept: application/json\r\n",
        'timeout' => 8,
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    return $raw !== false ? (json_decode($raw, true) ?: null) : null;
}

switch ($action) {

    // ── Search (Nominatim proxy) ──────────────────────────────────────────────
    case 'search':
        $q = trim($_POST['q'] ?? '');
        if (strlen($q) < 2) { echo json_encode(['results' => []]); exit; }

        $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
            'q'              => $q,
            'format'         => 'json',
            'addressdetails' => 1,
            'limit'          => 8,
            'countrycodes'   => 'gh',   // Ghana-focused; remove for global search
        ]);
        $data = nominatim_get($url);
        if ($data === null) { echo json_encode(['results' => [], 'error' => 'Search unavailable']); exit; }

        $results = [];
        foreach ($data as $r) {
            $results[] = [
                'display_name' => $r['display_name'],
                'name'         => $r['name'] ?? strtok($r['display_name'], ','),
                'lat'          => (float)$r['lat'],
                'lng'          => (float)$r['lon'],
            ];
        }
        echo json_encode(['results' => $results]);
        break;

    // ── Reverse geocode ───────────────────────────────────────────────────────
    case 'reverse':
        $lat = (float)($_POST['lat'] ?? 0);
        $lng = (float)($_POST['lng'] ?? 0);
        if (!$lat && !$lng) { echo json_encode(['error' => 'Missing coordinates']); exit; }

        $url  = 'https://nominatim.openstreetmap.org/reverse?' . http_build_query([
            'lat' => $lat, 'lon' => $lng, 'format' => 'json',
        ]);
        $data = nominatim_get($url);
        if (!$data) { echo json_encode(['display_name' => 'Unknown location', 'name' => 'Unknown']); exit; }

        $addr = $data['address'] ?? [];
        $name = $data['name']
             ?? $addr['road']
             ?? $addr['suburb']
             ?? $addr['town']
             ?? $addr['city']
             ?? strtok($data['display_name'] ?? '', ',');

        echo json_encode(['display_name' => $data['display_name'] ?? '', 'name' => $name]);
        break;

    // ── Save / upsert location ────────────────────────────────────────────────
    case 'save':
        csrf_check();
        $lat  = (float)($_POST['lat']     ?? 0);
        $lng  = (float)($_POST['lng']     ?? 0);
        $name = trim($_POST['name']    ?? '');
        $addr = trim($_POST['address'] ?? '') ?: $name;

        if (!$lat || !$lng || $name === '') {
            http_response_code(400);
            echo json_encode(['error' => 'lat, lng and name are required']);
            exit;
        }

        $gmUrl  = 'https://www.google.com/maps?q=' . urlencode($lat . ',' . $lng);
        $osmUrl = 'https://www.openstreetmap.org/?mlat=' . $lat . '&mlon=' . $lng . '&zoom=16';

        // Deduplicate within ~50 m
        $dup = $pdo->prepare(
            "SELECT id FROM locations WHERE ABS(latitude-?) < 0.0005 AND ABS(longitude-?) < 0.0005 LIMIT 1"
        );
        $dup->execute([$lat, $lng]);
        $existing = $dup->fetch();

        if ($existing) {
            $locId = (int)$existing['id'];
            $pdo->prepare(
                "UPDATE locations SET location_name=?,formatted_address=?,google_maps_url=?,osm_maps_url=?,updated_at=NOW() WHERE id=?"
            )->execute([$name, $addr, $gmUrl, $osmUrl, $locId]);
        } else {
            $pdo->prepare(
                "INSERT INTO locations (location_name,formatted_address,latitude,longitude,google_maps_url,osm_maps_url,created_at,updated_at)
                 VALUES (?,?,?,?,?,?,NOW(),NOW())"
            )->execute([$name, $addr, $lat, $lng, $gmUrl, $osmUrl]);
            $locId = (int)$pdo->lastInsertId();
        }

        echo json_encode(['ok' => true, 'id' => $locId, 'name' => $name, 'address' => $addr, 'lat' => $lat, 'lng' => $lng]);
        break;

    // ── Get location by ID ────────────────────────────────────────────────────
    case 'get':
        $id = intval($_POST['id'] ?? 0);
        if (!$id) { http_response_code(400); echo json_encode(['error' => 'Missing id']); exit; }

        $stmt = $pdo->prepare("SELECT * FROM locations WHERE id=? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) { http_response_code(404); echo json_encode(['error' => 'Not found']); exit; }

        echo json_encode(['location' => $row]);
        break;

    // ── Nearby records ────────────────────────────────────────────────────────
    case 'nearby':
        $lat    = (float)($_POST['lat']    ?? 0);
        $lng    = (float)($_POST['lng']    ?? 0);
        $radius = min(50, max(1, (float)($_POST['radius'] ?? 10)));  // km, clamp 1–50
        $module = trim($_POST['module'] ?? 'events');

        $tableMap = [
            'events'               => ['events',               'title', "AND status='published'"],
            'service_requests'     => ['service_requests',     'title', "AND status='pending'"],
            'funeral_announcements'=> ['funeral_announcements','deceased_name AS title', "AND status='approved'"],
        ];
        if (!isset($tableMap[$module])) {
            http_response_code(400); echo json_encode(['error' => 'Invalid module']); exit;
        }
        [$table, $titleCol, $statusFilter] = $tableMap[$module];

        $latD = $radius / 111.0;
        $lngD = $radius / (111.0 * cos(deg2rad($lat)));

        $sql = "SELECT t.id, {$titleCol}, l.latitude, l.longitude, l.location_name, l.formatted_address,
                       ROUND(6371 * ACOS(GREATEST(-1, LEAST(1,
                           COS(RADIANS(?)) * COS(RADIANS(l.latitude))
                           * COS(RADIANS(l.longitude) - RADIANS(?))
                           + SIN(RADIANS(?)) * SIN(RADIANS(l.latitude))
                       ))), 2) AS distance_km
                FROM {$table} t
                JOIN locations l ON l.id = t.location_id
                WHERE l.latitude  BETWEEN ? AND ?
                  AND l.longitude BETWEEN ? AND ?
                  {$statusFilter}
                HAVING distance_km <= ?
                ORDER BY distance_km
                LIMIT 20";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$lat, $lng, $lat, $lat - $latD, $lat + $latD, $lng - $lngD, $lng + $lngD, $radius]);
        echo json_encode(['results' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        break;

    // ── Haversine distance ────────────────────────────────────────────────────
    case 'distance':
        $lat1 = (float)($_POST['lat1'] ?? 0);
        $lng1 = (float)($_POST['lng1'] ?? 0);
        $lat2 = (float)($_POST['lat2'] ?? 0);
        $lng2 = (float)($_POST['lng2'] ?? 0);
        $km   = haversine_distance($lat1, $lng1, $lat2, $lng2);
        echo json_encode(['km' => round($km, 2), 'miles' => round($km * 0.621371, 2)]);
        break;

    // ── Direction links ───────────────────────────────────────────────────────
    case 'directions':
        $lat  = (float)($_POST['lat']  ?? 0);
        $lng  = (float)($_POST['lng']  ?? 0);
        $name = trim($_POST['name'] ?? '');
        echo json_encode(location_direction_links($lat, $lng, $name));
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action: ' . htmlspecialchars($action, ENT_QUOTES)]);
}
