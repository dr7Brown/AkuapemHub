<?php
/**
 * Delivery Services Module — database migration.
 * Run once: visit /migrate_delivery.php in your browser.
 * DELETE this file immediately after the success message appears.
 */
require_once __DIR__ . '/config.php';

$errors = [];
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // ── 1. delivery_agents ───────────────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS delivery_agents (
        id                   INT AUTO_INCREMENT PRIMARY KEY,
        user_id              INT NOT NULL,
        vehicle_type         ENUM('motorbike','bicycle','car','van','truck','public_transport','other') NOT NULL DEFAULT 'motorbike',
        vehicle_registration VARCHAR(100),
        license_number       VARCHAR(100),
        service_area         VARCHAR(500),
        bio                  TEXT,
        availability_status  ENUM('available','busy','offline') NOT NULL DEFAULT 'offline',
        verification_status  ENUM('none','pending','approved','rejected') NOT NULL DEFAULT 'none',
        rejection_reason     TEXT,
        id_type              VARCHAR(100),
        id_type_custom       VARCHAR(100),
        id_number            VARCHAR(100),
        id_document_path     VARCHAR(255),
        rating               DECIMAL(3,2) NOT NULL DEFAULT 0.00,
        completed_deliveries INT NOT NULL DEFAULT 0,
        created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_user (user_id),
        KEY idx_verification (verification_status),
        KEY idx_availability (availability_status),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ── 2. delivery_requests ─────────────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS delivery_requests (
        id                   INT AUTO_INCREMENT PRIMARY KEY,
        customer_id          INT NOT NULL,
        agent_id             INT,
        pickup_location      VARCHAR(500) NOT NULL,
        pickup_lat           DECIMAL(10,7),
        pickup_lng           DECIMAL(10,7),
        pickup_contact_name  VARCHAR(255) NOT NULL,
        pickup_contact_phone VARCHAR(50) NOT NULL,
        dropoff_location     VARCHAR(500) NOT NULL,
        dropoff_lat          DECIMAL(10,7),
        dropoff_lng          DECIMAL(10,7),
        receiver_name        VARCHAR(255) NOT NULL,
        receiver_phone       VARCHAR(50) NOT NULL,
        item_description     TEXT NOT NULL,
        item_category        ENUM('documents','food','electronics','clothing','medical_supplies','groceries','parcels','other') NOT NULL DEFAULT 'parcels',
        package_weight       DECIMAL(6,2),
        delivery_notes       TEXT,
        delivery_fee         DECIMAL(10,2),
        payment_method       ENUM('cash','mobile_money','card','wallet') NOT NULL DEFAULT 'cash',
        payment_status       ENUM('unpaid','paid') NOT NULL DEFAULT 'unpaid',
        preferred_date       DATE,
        preferred_time       TIME,
        status               ENUM('pending','accepted','picked_up','in_transit','delivered','cancelled','failed') NOT NULL DEFAULT 'pending',
        cancelled_reason     TEXT,
        created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_customer (customer_id),
        KEY idx_agent (agent_id),
        KEY idx_status (status),
        KEY idx_created (created_at),
        FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (agent_id) REFERENCES delivery_agents(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ── 3. delivery_ratings ──────────────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS delivery_ratings (
        id                  INT AUTO_INCREMENT PRIMARY KEY,
        delivery_request_id INT NOT NULL,
        customer_rating     TINYINT,
        customer_comment    TEXT,
        agent_rating        TINYINT,
        agent_comment       TEXT,
        created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_request (delivery_request_id),
        FOREIGN KEY (delivery_request_id) REFERENCES delivery_requests(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ── 4. Platform settings for delivery ────────────────────────────────────────
    $pdo->exec("INSERT IGNORE INTO platform_settings (setting_key, setting_value, description) VALUES
        ('delivery_enabled',   '1',    'Whether the Delivery Services module is active (1=yes, 0=no)'),
        ('delivery_base_fee',  '0.00', 'Default delivery base fee in GH₵; 0 means customer and agent negotiate'),
        ('delivery_fee_mode',  'free', 'free = negotiate, fixed = use base_fee')
    ");

} catch (PDOException $e) {
    $errors[] = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Delivery Migration — AkuapemConnect</title>
<style>body{font-family:sans-serif;max-width:600px;margin:60px auto;padding:20px;line-height:1.6;}
h2{margin-top:0;}code{background:#f1f5f9;padding:2px 6px;border-radius:4px;font-size:.9em;}</style>
</head>
<body>
<?php if ($errors): ?>
    <h2 style="color:#c0392b;">Migration failed</h2>
    <?php foreach ($errors as $e): ?>
        <p style="color:#c0392b;background:#fee2e2;padding:10px 14px;border-radius:8px;"><?php echo htmlspecialchars($e); ?></p>
    <?php endforeach; ?>
<?php else: ?>
    <h2 style="color:#0f766e;">Migration complete ✓</h2>
    <p>Tables created: <code>delivery_agents</code>, <code>delivery_requests</code>, <code>delivery_ratings</code></p>
    <p>Platform settings registered for the delivery module.</p>
    <p style="background:#fef3c7;padding:10px 14px;border-radius:8px;"><strong>⚠ Delete this file now</strong> — it must not remain on a production server.</p>
    <a href="index.php" style="color:#0f766e;font-weight:700;">← Back to site</a>
<?php endif; ?>
</body>
</html>
