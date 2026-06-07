<?php
require_once __DIR__ . '/db.php';

$pdo->exec("CREATE TABLE IF NOT EXISTS disputes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id INT UNSIGNED NOT NULL,
    reported_by INT UNSIGNED NOT NULL,
    reported_user_id INT UNSIGNED NOT NULL,
    dispute_type ENUM('quality','payment','communication','no_show','other') NOT NULL,
    description TEXT NOT NULL,
    status ENUM('open','investigating','resolved','closed') NOT NULL DEFAULT 'open',
    resolution_notes TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    INDEX idx_disputes_request_id (request_id),
    INDEX idx_disputes_reported_by (reported_by),
    INDEX idx_disputes_reported_user_id (reported_user_id),
    INDEX idx_disputes_status (status),
    FOREIGN KEY (request_id) REFERENCES service_requests(id) ON DELETE CASCADE,
    FOREIGN KEY (reported_by) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (reported_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

echo "✓ Disputes table created successfully.";
