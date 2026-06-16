<?php
require_once __DIR__ . '/db.php';

echo "=== Content Publishing Fee Migration ===\n\n";

try {
    // 1. Extend platform_payments.payment_type ENUM
    $ptRow = $pdo->query("SHOW COLUMNS FROM platform_payments LIKE 'payment_type'")->fetch(PDO::FETCH_ASSOC);
    $needTypes = ['news_post', 'event_post', 'funeral_post', 'escrow_with_posting'];
    $missing   = array_filter($needTypes, fn($v) => strpos($ptRow['Type'], $v) === false);
    if ($missing) {
        $pdo->exec("ALTER TABLE platform_payments MODIFY COLUMN payment_type ENUM('featured_job','featured_worker','verification','job_post','worker_service','escrow_payment','escrow_with_posting','news_post','event_post','funeral_post') NOT NULL");
        echo "Extended payment_type ENUM: added " . implode(', ', $missing) . "\n";
    } else {
        echo "payment_type ENUM already includes all content fee types\n";
    }

    // 2. Extend news.status ENUM to add pending_payment
    $nsRow = $pdo->query("SHOW COLUMNS FROM news LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    if (strpos($nsRow['Type'], 'pending_payment') === false) {
        $pdo->exec("ALTER TABLE news MODIFY COLUMN status ENUM('pending_payment','draft','published') NOT NULL DEFAULT 'draft'");
        echo "Extended news.status ENUM: added pending_payment\n";
    } else {
        echo "news.status already has pending_payment\n";
    }

    // 3. Extend events.status ENUM to add pending_payment
    $esRow = $pdo->query("SHOW COLUMNS FROM events LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    if (strpos($esRow['Type'], 'pending_payment') === false) {
        $pdo->exec("ALTER TABLE events MODIFY COLUMN status ENUM('pending_payment','draft','published','cancelled') NOT NULL DEFAULT 'draft'");
        echo "Extended events.status ENUM: added pending_payment\n";
    } else {
        echo "events.status already has pending_payment\n";
    }

    echo "\nDone.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
