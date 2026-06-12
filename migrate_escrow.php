<?php
require_once __DIR__ . '/db.php';

echo "=== Escrow Payment Migration ===\n\n";

try {
    $cols = array_column($pdo->query("SHOW FULL COLUMNS FROM service_requests")->fetchAll(PDO::FETCH_ASSOC), 'Field');

    // Extend status ENUM to include pending_payment
    $statusRow = $pdo->query("SHOW COLUMNS FROM service_requests LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    if (strpos($statusRow['Type'], 'pending_payment') === false) {
        $pdo->exec("ALTER TABLE service_requests MODIFY COLUMN status ENUM('pending','pending_payment','open','in_progress','partially_staffed','fully_staffed','completed','cancelled') NOT NULL DEFAULT 'pending'");
        echo "Extended status ENUM: added pending_payment\n";
    } else {
        echo "status ENUM already has pending_payment\n";
    }

    // Extend payment_status ENUM to include escrowed
    $psRow = $pdo->query("SHOW COLUMNS FROM service_requests LIKE 'payment_status'")->fetch(PDO::FETCH_ASSOC);
    if (strpos($psRow['Type'], 'escrowed') === false) {
        $pdo->exec("ALTER TABLE service_requests MODIFY COLUMN payment_status ENUM('unpaid','escrowed','paid') NOT NULL DEFAULT 'unpaid'");
        echo "Extended payment_status ENUM: added escrowed\n";
    } else {
        echo "payment_status ENUM already has escrowed\n";
    }

    $newCols = [
        'payment_mode'   => "ALTER TABLE service_requests ADD COLUMN payment_mode ENUM('direct','escrow') NOT NULL DEFAULT 'direct' AFTER posting_fee_status",
        'budget_amount'  => "ALTER TABLE service_requests ADD COLUMN budget_amount DECIMAL(10,2) NULL AFTER payment_mode",
        'deadline_value' => "ALTER TABLE service_requests ADD COLUMN deadline_value SMALLINT UNSIGNED NULL AFTER budget_amount",
        'deadline_unit'  => "ALTER TABLE service_requests ADD COLUMN deadline_unit ENUM('hours','days','months') NULL AFTER deadline_value",
        'deadline_date'  => "ALTER TABLE service_requests ADD COLUMN deadline_date DATETIME NULL AFTER deadline_unit",
    ];
    foreach ($newCols as $col => $sql) {
        if (!in_array($col, $cols)) {
            $pdo->exec($sql);
            echo "Added column: {$col}\n";
        } else {
            echo "Column already exists: {$col}\n";
        }
    }

    $tables = $pdo->query("SHOW TABLES LIKE 'escrow_payments'")->fetchAll();
    if (!$tables) {
        $pdo->exec("CREATE TABLE escrow_payments (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            job_id INT UNSIGNED NOT NULL,
            client_id INT UNSIGNED NOT NULL,
            worker_id INT UNSIGNED NULL,
            budget_amount DECIMAL(10,2) NOT NULL,
            commission_rate DECIMAL(5,2) NOT NULL,
            commission_amount DECIMAL(10,2) NOT NULL,
            gross_amount DECIMAL(10,2) NOT NULL,
            net_amount DECIMAL(10,2) NOT NULL,
            status ENUM('awaiting_payment','held','released','refunded','disputed') NOT NULL DEFAULT 'awaiting_payment',
            platform_payment_id INT UNSIGNED NULL,
            paystack_reference VARCHAR(100) NULL,
            paid_at DATETIME NULL,
            auto_release_days TINYINT UNSIGNED NOT NULL DEFAULT 7,
            auto_release_at DATETIME NULL,
            released_at DATETIME NULL,
            refunded_at DATETIME NULL,
            release_initiated_by ENUM('client','admin','auto') NULL,
            admin_notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_escrow_job (job_id),
            INDEX idx_escrow_status (status),
            INDEX idx_escrow_client (client_id),
            INDEX idx_escrow_worker (worker_id),
            INDEX idx_escrow_auto_release (auto_release_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        echo "Created escrow_payments table\n";
    } else {
        echo "escrow_payments table already exists\n";
    }

    echo "\nDone.\n";
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
