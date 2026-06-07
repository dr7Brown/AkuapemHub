<?php
require_once __DIR__ . '/db.php';

function flash($message, $type = 'success') {
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function get_flash() {
    if (!empty($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

function sanitize($value) {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function send_email_notification($to, $subject, $message) {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $headers = 'From: ' . MAIL_FROM . "\r\n" .
               'Reply-To: ' . MAIL_FROM . "\r\n" .
               'Content-Type: text/plain; charset=UTF-8' . "\r\n";
    return mail($to, $subject, $message, $headers);
}

function whatsapp_share_link($title, $location, $budget, $url) {
    $text = "{$title}\nLocation: {$location}\nBudget: GH₵ {$budget}\nView: {$url}";
    return 'https://wa.me/?text=' . rawurlencode($text);
}

function whatsapp_contact_link($contactInfo, $title) {
    $cleanPhone = preg_replace('/[^0-9+]/', '', $contactInfo);
    if (!$cleanPhone) {
        return false;
    }
    $text = "Hello, I am interested in your request: {$title}";
    return 'https://wa.me/' . rawurlencode($cleanPhone) . '?text=' . rawurlencode($text);
}

function ensure_notifications_table() {
    global $pdo;
    $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        title VARCHAR(160) NOT NULL,
        body TEXT NOT NULL,
        type ENUM('info','success','warning','error') NOT NULL DEFAULT 'info',
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_notifications_user_id (user_id),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function notify_user($userId, $title, $body, $type = 'info') {
    global $pdo;
    ensure_notifications_table();
    $stmt = $pdo->prepare('INSERT INTO notifications (user_id, title, body, type, is_read, created_at) VALUES (?, ?, ?, ?, 0, NOW())');
    return $stmt->execute([$userId, $title, $body, $type]);
}

function get_notifications($userId, $limit = 10) {
    global $pdo;
    ensure_notifications_table();
    $stmt = $pdo->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ?');
    $stmt->bindValue(1, $userId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function get_unread_notifications_count($userId) {
    global $pdo;
    ensure_notifications_table();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
}

function mark_notifications_read($userId) {
    global $pdo;
    ensure_notifications_table();
    $stmt = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0');
    return $stmt->execute([$userId]);
}

function notify_admins($title, $body, $type = 'info') {
    global $pdo;
    $stmt = $pdo->prepare('SELECT id FROM users WHERE role = ?');
    $stmt->execute([ADMIN_ROLE]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $adminId) {
        notify_user($adminId, $title, $body, $type);
    }
}

function get_request_status_counts($customerId) {
    global $pdo;
    $stmt = $pdo->prepare('SELECT status, COUNT(*) AS total FROM service_requests WHERE customer_id = ? GROUP BY status');
    $stmt->execute([$customerId]);
    $counts = ['pending' => 0, 'open' => 0, 'in_progress' => 0, 'completed' => 0, 'cancelled' => 0];
    foreach ($stmt->fetchAll() as $row) {
        $counts[$row['status']] = (int)$row['total'];
    }
    return $counts;
}

function get_worker_request_counts($workerId) {
    global $pdo;
    $stmt = $pdo->prepare('SELECT status, COUNT(*) AS total FROM service_requests WHERE assigned_worker_id = ? GROUP BY status');
    $stmt->execute([$workerId]);
    $counts = ['open' => 0, 'in_progress' => 0, 'completed' => 0, 'pending' => 0, 'cancelled' => 0];
    foreach ($stmt->fetchAll() as $row) {
        $counts[$row['status']] = (int)$row['total'];
    }
    return $counts;
}

function get_open_jobs_count() {
    global $pdo;
    $stmt = $pdo->query('SELECT COUNT(*) FROM service_requests WHERE status = "open"');
    return (int)$stmt->fetchColumn();
}

function get_premium_worker_count() {
    global $pdo;
    $stmt = $pdo->query('SELECT COUNT(*) FROM worker_profiles WHERE subscription_status = "premium"');
    return (int)$stmt->fetchColumn();
}

function get_paid_total_by_worker($workerId) {
    global $pdo;
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(CAST(p.amount AS DECIMAL(10,2))), 0) FROM payments p JOIN service_requests sr ON p.request_id = sr.id WHERE sr.assigned_worker_id = ? AND p.status = "paid" AND p.amount REGEXP ?');
    $stmt->execute([$workerId, '^[0-9]+(\.[0-9]{1,2})?$']);
    return $stmt->fetchColumn();
}

function get_customer_spending_total($customerId) {
    global $pdo;
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(CAST(p.amount AS DECIMAL(10,2))), 0) FROM payments p JOIN service_requests sr ON p.request_id = sr.id WHERE sr.customer_id = ? AND p.status = "paid" AND p.amount REGEXP ?');
    $stmt->execute([$customerId, '^[0-9]+(\.[0-9]{1,2})?$']);
    return $stmt->fetchColumn();
}

function get_worker_average_rating($workerId) {
    global $pdo;
    $stmt = $pdo->prepare('SELECT COALESCE(AVG(score), 0) FROM ratings WHERE worker_id = ?');
    $stmt->execute([$workerId]);
    return (float)$stmt->fetchColumn();
}

function get_worker_completed_jobs($workerId) {
    global $pdo;
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM service_requests WHERE assigned_worker_id = ? AND status = "completed"');
    $stmt->execute([$workerId]);
    return (int)$stmt->fetchColumn();
}

function get_worker_job_history($workerId, $limit = 20) {
    global $pdo;
    $stmt = $pdo->prepare('SELECT sr.*, u.name AS customer_name, c.name AS category_name, r.score AS rating_score, r.comment AS rating_comment FROM service_requests sr JOIN users u ON sr.customer_id = u.id JOIN service_categories c ON sr.category_id = c.id LEFT JOIN ratings r ON sr.id = r.request_id AND r.worker_id = sr.assigned_worker_id WHERE sr.assigned_worker_id = ? ORDER BY sr.updated_at DESC LIMIT ?');
    $stmt->bindValue(1, $workerId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function get_customer_payment_history($customerId, $limit = 20) {
    global $pdo;
    $stmt = $pdo->prepare('SELECT p.*, sr.title, sr.budget, sr.status, w.name AS worker_name FROM payments p JOIN service_requests sr ON p.request_id = sr.id LEFT JOIN users w ON sr.assigned_worker_id = w.id WHERE sr.customer_id = ? ORDER BY p.created_at DESC LIMIT ?');
    $stmt->bindValue(1, $customerId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function ensure_messages_table() {
    global $pdo;
    $pdo->exec("CREATE TABLE IF NOT EXISTS messages (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        request_id INT UNSIGNED NOT NULL,
        sender_id INT UNSIGNED NOT NULL,
        recipient_id INT UNSIGNED NOT NULL,
        content TEXT NOT NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_messages_request_id (request_id),
        INDEX idx_messages_sender_id (sender_id),
        INDEX idx_messages_recipient_id (recipient_id),
        FOREIGN KEY (request_id) REFERENCES service_requests(id) ON DELETE CASCADE,
        FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function send_message($requestId, $senderId, $recipientId, $content) {
    global $pdo;
    ensure_messages_table();
    $stmt = $pdo->prepare('INSERT INTO messages (request_id, sender_id, recipient_id, content, is_read, created_at) VALUES (?, ?, ?, ?, 0, NOW())');
    return $stmt->execute([$requestId, $senderId, $recipientId, $content]);
}

function get_message_thread($requestId, $userId, $limit = 50) {
    global $pdo;
    ensure_messages_table();
    $stmt = $pdo->prepare('SELECT m.*, u.name AS sender_name FROM messages m JOIN users u ON m.sender_id = u.id WHERE m.request_id = ? AND (m.sender_id = ? OR m.recipient_id = ?) ORDER BY m.created_at ASC LIMIT ?');
    $stmt->bindValue(1, $requestId, PDO::PARAM_INT);
    $stmt->bindValue(2, $userId, PDO::PARAM_INT);
    $stmt->bindValue(3, $userId, PDO::PARAM_INT);
    $stmt->bindValue(4, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function get_unread_messages_count($userId) {
    global $pdo;
    ensure_messages_table();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE recipient_id = ? AND is_read = 0');
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
}

function mark_messages_read($requestId, $userId) {
    global $pdo;
    ensure_messages_table();
    $stmt = $pdo->prepare('UPDATE messages SET is_read = 1 WHERE request_id = ? AND recipient_id = ? AND is_read = 0');
    return $stmt->execute([$requestId, $userId]);
}

function get_completion_photos($requestId) {
    global $pdo;
    $stmt = $pdo->prepare('SELECT * FROM completion_photos WHERE request_id = ? ORDER BY uploaded_at ASC');
    $stmt->execute([$requestId]);
    return $stmt->fetchAll();
}

function save_completion_photos($requestId, array $files) {
    global $pdo;
    $allowedTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $maxSize = 5 * 1024 * 1024;
    $uploadDir = __DIR__ . '/uploads/completions/' . $requestId;
    $saved = [];

    if (empty($files['name']) || !is_array($files['name'])) {
        return $saved;
    }

    foreach ($files['name'] as $index => $name) {
        if ($files['error'][$index] !== UPLOAD_ERR_OK) {
            continue;
        }
        $tmpPath = $files['tmp_name'][$index];
        $size = $files['size'][$index];
        $mimeType = mime_content_type($tmpPath);
        if (!isset($allowedTypes[$mimeType]) || $size > $maxSize) {
            continue;
        }
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        $fileName = bin2hex(random_bytes(8)) . '.' . $allowedTypes[$mimeType];
        $destination = $uploadDir . '/' . $fileName;
        if (move_uploaded_file($tmpPath, $destination)) {
            $relativePath = 'uploads/completions/' . $requestId . '/' . $fileName;
            $pdo->prepare('INSERT INTO completion_photos (request_id, file_path, uploaded_at) VALUES (?, ?, NOW())')->execute([$requestId, $relativePath]);
            $saved[] = $relativePath;
        }
    }

    return $saved;
}

function get_top_workers($limit = 10) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT u.id, u.name, w.location, w.subscription_status,
        COUNT(DISTINCT sr.id) AS completed_jobs,
        COALESCE(AVG(r.score), 0) AS avg_rating
        FROM users u
        JOIN worker_profiles w ON u.id = w.user_id
        LEFT JOIN service_requests sr ON u.id = sr.assigned_worker_id AND sr.status = 'completed'
        LEFT JOIN ratings r ON sr.id = r.request_id AND r.worker_id = u.id
        WHERE u.role = 'worker' AND u.banned = 0
        GROUP BY u.id, u.name, w.location, w.subscription_status
        HAVING completed_jobs > 0
        ORDER BY avg_rating DESC, completed_jobs DESC
        LIMIT ?");
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function get_trending_categories($limit = 5, $days = 30) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT c.id, c.name, COUNT(sr.id) AS request_count
        FROM service_categories c
        LEFT JOIN service_requests sr ON sr.category_id = c.id AND sr.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        GROUP BY c.id, c.name
        ORDER BY request_count DESC
        LIMIT ?");
    $stmt->bindValue(1, $days, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function build_location_filter($location) {
    return trim($location);
}

function rating_stars($rating) {
    $stars = '';
    for ($i = 1; $i <= 5; $i++) {
        $stars .= $i <= $rating ? '★' : '☆';
    }
    return $stars;
}

function get_categories() {
    global $pdo;
    $stmt = $pdo->query('SELECT id, name FROM service_categories ORDER BY id');
    return $stmt->fetchAll();
}

function get_weekday_names() {
    return [0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday'];
}

function get_worker_schedule($workerProfileId) {
    global $pdo;
    $stmt = $pdo->prepare('SELECT * FROM worker_availability_slots WHERE worker_profile_id = ? ORDER BY day_of_week ASC, start_time ASC');
    $stmt->execute([$workerProfileId]);
    $slots = [];
    foreach ($stmt->fetchAll() as $row) {
        $slots[(int)$row['day_of_week']][] = $row;
    }
    return $slots;
}

function save_worker_schedule($workerProfileId, array $days, array $startTimes, array $endTimes) {
    global $pdo;
    $pdo->prepare('DELETE FROM worker_availability_slots WHERE worker_profile_id = ?')->execute([$workerProfileId]);
    $stmt = $pdo->prepare('INSERT INTO worker_availability_slots (worker_profile_id, day_of_week, start_time, end_time) VALUES (?, ?, ?, ?)');
    foreach (array_keys(get_weekday_names()) as $day) {
        if (empty($days[$day])) {
            continue;
        }
        $start = $startTimes[$day] ?? '';
        $end = $endTimes[$day] ?? '';
        if ($start === '' || $end === '' || $start >= $end) {
            continue;
        }
        $stmt->execute([$workerProfileId, $day, $start, $end]);
    }
}

function format_time_range($start, $end) {
    return date('g:i A', strtotime($start)) . ' – ' . date('g:i A', strtotime($end));
}

function get_availability_options() {
    return [
        'available' => 'Available',
        'busy' => 'Busy',
        'offline' => 'Offline',
    ];
}

function get_status_options() {
    return [
        'pending' => 'Pending',
        'open' => 'Open',
        'in_progress' => 'In Progress',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ];
}
