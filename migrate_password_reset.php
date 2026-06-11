<?php
/**
 * Password Reset System Migration
 * Run via CLI: php migrate_password_reset.php
 * Or browser: migrate_password_reset.php?run=1  (admin only — delete after running)
 */

if (php_sapi_name() !== 'cli' && empty($_GET['run'])) {
    die('Run via CLI: php migrate_password_reset.php');
}

require_once __DIR__ . '/db.php';

$steps = [];

// 1. password_reset_otps
$pdo->exec("CREATE TABLE IF NOT EXISTS password_reset_otps (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED NOT NULL,
    otp_hash     VARCHAR(255) NOT NULL,
    email        VARCHAR(255) NULL,
    phone        VARCHAR(50)  NULL,
    status       ENUM('pending','used','expired') NOT NULL DEFAULT 'pending',
    attempts     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    expires_at   DATETIME NOT NULL,
    created_at   DATETIME NOT NULL DEFAULT NOW(),
    used_at      DATETIME NULL,
    INDEX idx_pro_user    (user_id),
    INDEX idx_pro_status  (status),
    INDEX idx_pro_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$steps[] = 'password_reset_otps table: OK';

// 2. security_logs
$pdo->exec("CREATE TABLE IF NOT EXISTS security_logs (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED NULL,
    action       VARCHAR(100) NOT NULL,
    ip_address   VARCHAR(45)  NOT NULL DEFAULT '',
    user_agent   VARCHAR(500) NULL,
    created_at   DATETIME NOT NULL DEFAULT NOW(),
    INDEX idx_sl_user    (user_id),
    INDEX idx_sl_action  (action),
    INDEX idx_sl_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$steps[] = 'security_logs table: OK';

// 3. users.password_changed_at
$col = $pdo->query("SHOW COLUMNS FROM users LIKE 'password_changed_at'")->fetch();
if (!$col) {
    $pdo->exec("ALTER TABLE users ADD COLUMN password_changed_at DATETIME NULL AFTER password_hash");
    $steps[] = 'users.password_changed_at column: ADDED';
} else {
    $steps[] = 'users.password_changed_at column: already exists';
}

foreach ($steps as $s) {
    echo $s . PHP_EOL;
}
echo 'Migration complete.' . PHP_EOL;
