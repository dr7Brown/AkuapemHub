<?php
/**
 * Location & Mapping API — JSON endpoint.
 *
 * GET  ?action=search&q=Akropong          → Nominatim search results
 * GET  ?action=reverse&lat=5.77&lng=-0.1  → Reverse geocode
 * POST action=save  (lat, lng, name, address) → Save location, return id
 * GET  ?action=nearby&lat=&lng=&r=5       → Nearby locations from DB
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/location_functions.php';

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

$action = $_REQUEST['action'] ?? '';

// ── search ────────────────────────────────────────────────────────────────────
if ($action === 'search') {
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 2) { echo json_encode([]); exit; }

    $results = nominatim_search($q);
    $out = [];
    foreach ($results as $r) {
        $out[] = [
            'display_name' => $r['display_name'] ?? '',
            'name'         => $r['name'] ?? ($r['display_name'] ?? ''),
            'lat'          => (float)($r['lat'] ?? 0),
            'lng'          => (float)($r['lon'] ?? 0),
            'address'      => $r['display_name'] ?? '',
        ];
    }
    echo json_encode($out);
    exit;
}

// ── reverse ───────────────────────────────────────────────────────────────────
if ($action === 'reverse') {
    $lat = (float)($_GET['lat'] ?? 0);
    $lng = (float)($_GET['lng'] ?? 0);
    if (!$lat || !$lng) { echo json_encode(['error' => 'Missing lat/lng']); exit; }

    $result = nominatim_reverse($lat, $lng);
    if (!$result) { echo json_encode(['error' => 'Geocoding failed']); exit; }
    echo json_encode([
        'display_name' => $result['display_name'] ?? '',
        'name'         => $result['name'] ?? ($result['display_name'] ?? ''),
        'lat'          => $lat,
        'lng'          => $lng,
        'address'      => $result['display_name'] ?? '',
    ]);
    exit;
}

// ── save (requires login) ─────────────────────────────────────────────────────
if ($action === 'save') {
    if (!current_user()) { echo json_encode(['error' => 'Login required']); exit; }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_check();

    $lat     = (float)($_POST['lat']     ?? 0);
    $lng     = (float)($_POST['lng']     ?? 0);
    $name    = trim($_POST['name']    ?? '');
    $address = trim($_POST['address'] ?? '');

    if (!$lat || !$lng) { echo json_encode(['error' => 'Invalid coordinates']); exit; }

    $id = save_location($name ?: 'Unnamed location', $address ?: '', $lat, $lng);
    echo json_encode([
        'id'         => $id,
        'google_url' => location_google_maps_url($lat, $lng),
        'osm_url'    => location_osm_url($lat, $lng),
    ]);
    exit;
}

// ── nearby ────────────────────────────────────────────────────────────────────
if ($action === 'nearby') {
    $lat    = (float)($_GET['lat'] ?? 0);
    $lng    = (float)($_GET['lng'] ?? 0);
    $radius = min(50, max(0.5, (float)($_GET['r'] ?? 5)));
    if (!$lat || !$lng) { echo json_encode([]); exit; }

    $locations = get_nearby_locations($lat, $lng, $radius, 20);
    echo json_encode(array_map(fn($l) => [
        'id'           => (int)$l['id'],
        'name'         => $l['location_name'],
        'address'      => $l['formatted_address'],
        'lat'          => (float)$l['latitude'],
        'lng'          => (float)$l['longitude'],
        'distance_km'  => round((float)($l['distance_km'] ?? 0), 2),
        'google_url'   => $l['google_maps_url'],
    ], $locations));
    exit;
}

echo json_encode(['error' => 'Unknown action']);
