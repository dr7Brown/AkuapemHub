<?php
/**
 * Email Verification Migration
 * Run via CLI: php migrate_email_verification.php
 */

if (php_sapi_name() !== 'cli' && empty($_GET['run'])) {
    die('Run via CLI: php migrate_email_verification.php');
}

require_once __DIR__ . '/db.php';

$steps = [];

// email_verified flag
if (!$pdo->query("SHOW COLUMNS FROM users LIKE 'email_verified'")->fetch()) {
    $pdo->exec("ALTER TABLE users ADD COLUMN email_verified TINYINT(1) NOT NULL DEFAULT 0 AFTER email");
    $steps[] = 'users.email_verified: ADDED';
} else {
    $steps[] = 'users.email_verified: already exists';
}

// token column
if (!$pdo->query("SHOW COLUMNS FROM users LIKE 'email_verification_token'")->fetch()) {
    $pdo->exec("ALTER TABLE users ADD COLUMN email_verification_token VARCHAR(64) NULL AFTER email_verified,
                                 ADD INDEX idx_evt (email_verification_token)");
    $steps[] = 'users.email_verification_token: ADDED';
} else {
    $steps[] = 'users.email_verification_token: already exists';
}

// token sent timestamp (used for expiry + resend rate limit)
if (!$pdo->query("SHOW COLUMNS FROM users LIKE 'email_verification_sent_at'")->fetch()) {
    $pdo->exec("ALTER TABLE users ADD COLUMN email_verification_sent_at DATETIME NULL AFTER email_verification_token");
    $steps[] = 'users.email_verification_sent_at: ADDED';
} else {
    $steps[] = 'users.email_verification_sent_at: already exists';
}

// Mark all pre-existing accounts as verified — they were already active before this feature
$affected = $pdo->exec("UPDATE users SET email_verified = 1 WHERE email_verified = 0");
$steps[] = "Pre-existing accounts marked verified: {$affected}";

foreach ($steps as $s) {
    echo $s . PHP_EOL;
}
echo 'Done.' . PHP_EOL;
