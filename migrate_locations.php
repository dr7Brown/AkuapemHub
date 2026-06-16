<?php
require_once __DIR__ . '/db.php';

echo "=== Location & Mapping Module Migration ===\n\n";

try {
    // 1. Create locations table
    try {
        $pdo->query("SHOW COLUMNS FROM locations");
        echo "Table 'locations' already exists\n";
    } catch (PDOException $e) {
        $pdo->exec("CREATE TABLE locations (
            id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            location_name   VARCHAR(255) NOT NULL DEFAULT '',
            formatted_address VARCHAR(512) NOT NULL DEFAULT '',
            latitude        DECIMAL(10,7) NOT NULL,
            longitude       DECIMAL(10,7) NOT NULL,
            google_maps_url VARCHAR(512) NOT NULL DEFAULT '',
            osm_maps_url    VARCHAR(512) NOT NULL DEFAULT '',
            created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_lat_lng (latitude, longitude)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        echo "Created table: locations\n";
    }

    // 2. events.location_id
    $evCols = array_column($pdo->query("SHOW COLUMNS FROM events")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    if (!in_array('location_id', $evCols)) {
        $pdo->exec("ALTER TABLE events ADD COLUMN location_id INT UNSIGNED NULL DEFAULT NULL");
        echo "Added column: events.location_id\n";
    } else {
        echo "Column already exists: events.location_id\n";
    }

    // 3. funeral_announcements.location_id
    $faCols = array_column($pdo->query("SHOW COLUMNS FROM funeral_announcements")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    if (!in_array('location_id', $faCols)) {
        $pdo->exec("ALTER TABLE funeral_announcements ADD COLUMN location_id INT UNSIGNED NULL DEFAULT NULL");
        echo "Added column: funeral_announcements.location_id\n";
    } else {
        echo "Column already exists: funeral_announcements.location_id\n";
    }

    // 4. service_requests.location_id
    $srCols = array_column($pdo->query("SHOW COLUMNS FROM service_requests")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    if (!in_array('location_id', $srCols)) {
        $pdo->exec("ALTER TABLE service_requests ADD COLUMN location_id INT UNSIGNED NULL DEFAULT NULL");
        echo "Added column: service_requests.location_id\n";
    } else {
        echo "Column already exists: service_requests.location_id\n";
    }

    echo "\nDone.\n";
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
