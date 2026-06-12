<?php
require_once __DIR__ . '/db.php';

echo "=== Payments Admin Migration ===\n\n";

try {
    // Extend payment_type ENUM to include escrow_payment
    $ptRow = $pdo->query("SHOW COLUMNS FROM platform_payments LIKE 'payment_type'")->fetch(PDO::FETCH_ASSOC);
    if (strpos($ptRow['Type'], 'escrow_payment') === false) {
        $pdo->exec("ALTER TABLE platform_payments MODIFY COLUMN payment_type ENUM('featured_job','featured_worker','verification','job_post','worker_service','escrow_payment') NOT NULL");
        echo "Extended payment_type ENUM: added escrow_payment\n";
    } else {
        echo "payment_type ENUM already has escrow_payment\n";
    }

    // Extend status ENUM to include refunded (may already exist)
    $stRow = $pdo->query("SHOW COLUMNS FROM platform_payments LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    if (strpos($stRow['Type'], 'refunded') === false) {
        $pdo->exec("ALTER TABLE platform_payments MODIFY COLUMN status ENUM('pending','paid','failed','abandoned','refunded') NOT NULL DEFAULT 'pending'");
        echo "Extended status ENUM: added refunded\n";
    } else {
        echo "status ENUM already has refunded\n";
    }

    $cols = array_column($pdo->query("SHOW COLUMNS FROM platform_payments")->fetchAll(PDO::FETCH_ASSOC), 'Field');

    if (!in_array('admin_notes', $cols)) {
        $pdo->exec("ALTER TABLE platform_payments ADD COLUMN admin_notes TEXT NULL");
        echo "Added column: admin_notes\n";
    } else {
        echo "Column already exists: admin_notes\n";
    }

    if (!in_array('flagged', $cols)) {
        $pdo->exec("ALTER TABLE platform_payments ADD COLUMN flagged TINYINT(1) NOT NULL DEFAULT 0");
        echo "Added column: flagged\n";
    } else {
        echo "Column already exists: flagged\n";
    }

    echo "\nDone.\n";
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
