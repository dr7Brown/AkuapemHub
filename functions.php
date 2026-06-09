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

function user_wants_email_notifications($userId) {
    global $pdo;
    $stmt = $pdo->prepare('SELECT email_notifications_enabled FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $value = $stmt->fetchColumn();
    return $value === false ? true : (bool)$value;
}

function send_email_notification($to, $subject, $message, $userId = null) {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    if ($userId !== null && !user_wants_email_notifications($userId)) {
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

function notify_user($userId, $title, $body, $type = 'info') {
    global $pdo;
    $stmt = $pdo->prepare('INSERT INTO notifications (user_id, title, body, type, is_read, created_at) VALUES (?, ?, ?, ?, 0, NOW())');
    return $stmt->execute([$userId, $title, $body, $type]);
}

function get_notifications($userId, $limit = 10) {
    global $pdo;
    $stmt = $pdo->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ?');
    $stmt->bindValue(1, $userId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function get_unread_notifications_count($userId) {
    global $pdo;
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
}

function mark_notifications_read($userId) {
    global $pdo;
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

function notify_admins_and_managers($title, $body, $type = 'info') {
    global $pdo;
    $stmt = $pdo->query("SELECT id FROM users WHERE role IN ('admin', 'manager')");
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $userId) {
        notify_user($userId, $title, $body, $type);
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
    $stmt = $pdo->prepare('SELECT p.*, sr.title, sr.budget, sr.status AS request_status, w.name AS worker_name FROM payments p JOIN service_requests sr ON p.request_id = sr.id LEFT JOIN users w ON sr.assigned_worker_id = w.id WHERE sr.customer_id = ? ORDER BY p.created_at DESC LIMIT ?');
    $stmt->bindValue(1, $customerId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function send_message($requestId, $senderId, $recipientId, $content) {
    global $pdo;
    $stmt = $pdo->prepare('INSERT INTO messages (request_id, sender_id, recipient_id, content, is_read, created_at) VALUES (?, ?, ?, ?, 0, NOW())');
    return $stmt->execute([$requestId, $senderId, $recipientId, $content]);
}

function get_message_thread($requestId, $userId, $limit = 50) {
    global $pdo;
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
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE recipient_id = ? AND is_read = 0');
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
}

function mark_messages_read($requestId, $userId) {
    global $pdo;
    $stmt = $pdo->prepare('UPDATE messages SET is_read = 1 WHERE request_id = ? AND recipient_id = ? AND is_read = 0');
    return $stmt->execute([$requestId, $userId]);
}

function get_user_conversations($userId, $limit = 30) {
    global $pdo;
    $stmt = $pdo->prepare(
        'SELECT m.request_id, IF(m.sender_id = ?, m.recipient_id, m.sender_id) AS other_user_id, MAX(m.created_at) AS last_at
         FROM messages m
         WHERE m.sender_id = ? OR m.recipient_id = ?
         GROUP BY m.request_id, other_user_id
         ORDER BY last_at DESC
         LIMIT ?'
    );
    $stmt->bindValue(1, $userId, PDO::PARAM_INT);
    $stmt->bindValue(2, $userId, PDO::PARAM_INT);
    $stmt->bindValue(3, $userId, PDO::PARAM_INT);
    $stmt->bindValue(4, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $conversations = [];
    foreach ($rows as $row) {
        $otherStmt = $pdo->prepare('SELECT name, username, profile_photo FROM users WHERE id = ?');
        $otherStmt->execute([$row['other_user_id']]);
        $other = $otherStmt->fetch();

        $requestStmt = $pdo->prepare('SELECT title FROM service_requests WHERE id = ?');
        $requestStmt->execute([$row['request_id']]);
        $request = $requestStmt->fetch();

        if (!$other || !$request) {
            continue;
        }

        $lastMsgStmt = $pdo->prepare('SELECT content, created_at FROM messages WHERE request_id = ? AND ((sender_id = ? AND recipient_id = ?) OR (sender_id = ? AND recipient_id = ?)) ORDER BY created_at DESC LIMIT 1');
        $lastMsgStmt->execute([$row['request_id'], $userId, $row['other_user_id'], $row['other_user_id'], $userId]);
        $lastMessage = $lastMsgStmt->fetch();

        $unreadStmt = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE request_id = ? AND recipient_id = ? AND sender_id = ? AND is_read = 0');
        $unreadStmt->execute([$row['request_id'], $userId, $row['other_user_id']]);

        $conversations[] = [
            'request_id' => $row['request_id'],
            'request_title' => $request['title'],
            'other_user_name' => display_name($other),
            'other_user_photo' => $other['profile_photo'],
            'last_message' => $lastMessage['content'] ?? '',
            'last_at' => $row['last_at'],
            'unread_count' => (int)$unreadStmt->fetchColumn(),
        ];
    }
    return $conversations;
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

function save_uploaded_image(array $file, $relativeDir) {
    $allowedTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $maxSize = 5 * 1024 * 1024;

    if (empty($file['name']) || $file['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    $mimeType = mime_content_type($file['tmp_name']);
    if (!isset($allowedTypes[$mimeType]) || $file['size'] > $maxSize) {
        return null;
    }

    $uploadDir = __DIR__ . '/' . $relativeDir;
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    $fileName = bin2hex(random_bytes(8)) . '.' . $allowedTypes[$mimeType];
    if (move_uploaded_file($file['tmp_name'], $uploadDir . '/' . $fileName)) {
        return $relativeDir . '/' . $fileName;
    }
    return null;
}

function is_valid_image_upload(array $file) {
    $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
    $maxSize = 5 * 1024 * 1024;

    if (empty($file['name']) || $file['error'] !== UPLOAD_ERR_OK) {
        return false;
    }
    return in_array(mime_content_type($file['tmp_name']), $allowedTypes, true) && $file['size'] <= $maxSize;
}

function get_payment_receipt($paymentId, $customerId) {
    global $pdo;
    $stmt = $pdo->prepare('SELECT p.*, sr.title, sr.description, sr.location, sr.budget, sr.commission_percent,
        c.name AS customer_name, c.email AS customer_email,
        w.name AS worker_name
        FROM payments p
        JOIN service_requests sr ON p.request_id = sr.id
        JOIN users c ON sr.customer_id = c.id
        LEFT JOIN users w ON sr.assigned_worker_id = w.id
        WHERE p.id = ? AND sr.customer_id = ?');
    $stmt->execute([$paymentId, $customerId]);
    return $stmt->fetch();
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

function send_business_message($userId, $phone, $message, $channel = 'whatsapp') {
    global $pdo;
    $cleanPhone = preg_replace('/[^0-9+]/', '', (string)$phone);
    if (!$cleanPhone) {
        return false;
    }

    $providerUrl = $channel === 'sms' ? SMS_PROVIDER_URL : WHATSAPP_PROVIDER_URL;
    $providerToken = $channel === 'sms' ? SMS_PROVIDER_TOKEN : WHATSAPP_PROVIDER_TOKEN;

    $status = 'skipped';
    $responseExcerpt = 'No provider configured — message logged only.';

    if ($providerUrl !== '') {
        $ch = curl_init($providerUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['to' => $cleanPhone, 'message' => $message, 'channel' => $channel]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $providerToken],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response !== false && $httpCode >= 200 && $httpCode < 300) {
            $status = 'sent';
            $responseExcerpt = substr((string)$response, 0, 255);
        } else {
            $status = 'failed';
            $responseExcerpt = substr($curlError !== '' ? $curlError : ('HTTP ' . $httpCode . ': ' . (string)$response), 0, 255);
        }
    }

    $pdo->prepare('INSERT INTO business_messages (user_id, phone, channel, message, status, response_excerpt, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())')
        ->execute([$userId, $cleanPhone, $channel, $message, $status, $responseExcerpt]);

    return $status === 'sent';
}

function get_business_message_log($limit = 50) {
    global $pdo;
    $stmt = $pdo->prepare('SELECT bm.*, u.name AS user_name FROM business_messages bm LEFT JOIN users u ON bm.user_id = u.id ORDER BY bm.created_at DESC LIMIT ?');
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function distance_km($lat1, $lon1, $lat2, $lon2) {
    if ($lat1 === null || $lon1 === null || $lat2 === null || $lon2 === null) {
        return null;
    }
    $earthRadiusKm = 6371;
    $latDelta = deg2rad((float)$lat2 - (float)$lat1);
    $lonDelta = deg2rad((float)$lon2 - (float)$lon1);
    $a = sin($latDelta / 2) ** 2
        + cos(deg2rad((float)$lat1)) * cos(deg2rad((float)$lat2)) * sin($lonDelta / 2) ** 2;
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $earthRadiusKm * $c;
}

function format_distance($km) {
    if ($km === null) {
        return null;
    }
    return $km < 1 ? round($km * 1000) . ' m away' : number_format($km, 1) . ' km away';
}

function time_ago($datetime) {
    $diff = max(0, time() - strtotime($datetime));
    if ($diff < 60) {
        return 'Just now';
    }
    $units = [
        31536000 => 'y',
        2592000 => 'mo',
        604800 => 'w',
        86400 => 'd',
        3600 => 'h',
        60 => 'm',
    ];
    foreach ($units as $seconds => $label) {
        $value = (int) floor($diff / $seconds);
        if ($value >= 1) {
            return $value . $label . ' ago';
        }
    }
    return 'Just now';
}

function notification_icon($type) {
    $icons = [
        'success' => '✅',
        'warning' => '⚠️',
        'error' => '❌',
        'info' => '🔔',
    ];
    return $icons[$type] ?? '🔔';
}

function count_relevant_skill_matches($skillsCsv, $category, $title, $description) {
    $skillsCsv = strtolower(trim((string)$skillsCsv));
    if ($skillsCsv === '') {
        return 0;
    }
    $haystack = strtolower($category . ' ' . $title . ' ' . $description);
    $matches = 0;
    foreach (array_filter(array_map('trim', explode(',', $skillsCsv))) as $skill) {
        if ($skill !== '' && strpos($haystack, $skill) !== false) {
            $matches++;
        }
    }
    return $matches;
}

function notify_workers_of_matching_job(array $request) {
    global $pdo;
    $stmt = $pdo->query("SELECT u.id, u.name, GROUP_CONCAT(DISTINCT ws.skill_name SEPARATOR ', ') AS skills_csv
        FROM users u
        JOIN worker_profiles w ON w.user_id = u.id
        JOIN worker_skills ws ON ws.worker_profile_id = w.id
        WHERE u.role = 'worker' AND u.banned = 0
        GROUP BY u.id, u.name");

    $extraContext = trim(($request['category_name'] ?? '') . ' ' . ($request['skills_needed'] ?? ''));
    foreach ($stmt->fetchAll() as $worker) {
        if (count_relevant_skill_matches($worker['skills_csv'], $extraContext, $request['title'], $request['description']) > 0) {
            notify_user($worker['id'], 'New job matches your skills', "A new job '{$request['title']}' was posted that matches your skills. Take a look before someone else does!", 'info');
        }
    }
}

function score_worker_for_request(array $worker, array $request) {
    $score = 0;
    $reasons = [];

    $skillMatches = count_relevant_skill_matches($worker['skills'] ?? '', $request['category_name'] ?? '', $request['title'] ?? '', $request['description'] ?? '');
    if ($skillMatches > 0) {
        $score += min(40, 21 + $skillMatches * 9);
        $reasons[] = $skillMatches > 1 ? 'Multiple matching skills' : 'Matching skill';
    }

    $distanceKm = distance_km($worker['latitude'] ?? null, $worker['longitude'] ?? null, $request['latitude'] ?? null, $request['longitude'] ?? null);
    if ($distanceKm !== null) {
        if ($distanceKm <= 5) { $score += 25; $reasons[] = 'Very close to the job'; }
        elseif ($distanceKm <= 15) { $score += 18; $reasons[] = 'Nearby'; }
        elseif ($distanceKm <= 40) { $score += 10; }
        else { $score += 3; }
    } else {
        $score += 8;
    }

    $rating = (float)($worker['avg_rating'] ?? 0);
    $score += (int)round(($rating / 5) * 20);
    if ($rating >= 4.5) {
        $reasons[] = 'Top-rated worker';
    }

    if (($worker['availability'] ?? '') === 'available') {
        $score += 10;
        $reasons[] = 'Available now';
    } elseif (($worker['availability'] ?? '') === 'busy') {
        $score += 3;
    }

    $score += min(5, (int)($worker['completed_jobs'] ?? 0));

    $today = date('Y-m-d');
    $wFeatEnd = $worker['featured_end_date'] ?? null;
    if (!empty($worker['is_featured']) && (empty($wFeatEnd) || $wFeatEnd >= $today)) {
        $score += 8;
        $reasons[] = 'Featured worker';
    }
    if (!empty($worker['is_verified'])) {
        $score += 5;
        $reasons[] = 'Verified worker';
    }

    return [
        'score' => (int)min(100, $score),
        'reasons' => $reasons,
        'distance_km' => $distanceKm,
    ];
}

function get_recommended_workers_for_request($request, $limit = 5) {
    global $pdo;
    $stmt = $pdo->query("SELECT u.id, u.name, w.id AS profile_id, w.location, w.latitude, w.longitude, w.availability, w.subscription_status,
        w.is_featured, w.featured_end_date, w.is_verified,
        COALESCE(AVG(r.score), 0) AS avg_rating,
        COALESCE(COUNT(DISTINCT sr.id), 0) AS completed_jobs,
        GROUP_CONCAT(DISTINCT ws.skill_name ORDER BY ws.skill_name SEPARATOR ', ') AS skills
        FROM users u
        JOIN worker_profiles w ON u.id = w.user_id
        LEFT JOIN worker_skills ws ON w.id = ws.worker_profile_id
        LEFT JOIN service_requests sr ON u.id = sr.assigned_worker_id AND sr.status = 'completed'
        LEFT JOIN ratings r ON sr.id = r.request_id AND r.worker_id = u.id
        WHERE u.role = 'worker'
        GROUP BY u.id, u.name, w.id, w.location, w.latitude, w.longitude, w.availability, w.subscription_status, w.is_featured, w.featured_end_date, w.is_verified");
    $workers = $stmt->fetchAll();

    foreach ($workers as &$worker) {
        $match = score_worker_for_request($worker, $request);
        $worker['match_score'] = $match['score'];
        $worker['match_reasons'] = $match['reasons'];
        $worker['distance_km'] = $match['distance_km'];
    }
    unset($worker);

    usort($workers, function ($a, $b) {
        return $b['match_score'] <=> $a['match_score'];
    });

    return array_slice($workers, 0, $limit);
}

function score_job_for_worker(array $job, array $worker) {
    $score = 0;
    $reasons = [];

    $skillMatches = count_relevant_skill_matches($worker['skills'] ?? '', $job['category_name'] ?? '', $job['title'] ?? '', $job['description'] ?? '');
    if ($skillMatches > 0) {
        $score += min(45, 24 + $skillMatches * 10);
        $reasons[] = 'Matches your skills';
    }

    $distanceKm = distance_km($worker['latitude'] ?? null, $worker['longitude'] ?? null, $job['latitude'] ?? null, $job['longitude'] ?? null);
    if ($distanceKm !== null) {
        if ($distanceKm <= 5) { $score += 30; $reasons[] = 'Close to you'; }
        elseif ($distanceKm <= 15) { $score += 20; $reasons[] = 'Nearby'; }
        elseif ($distanceKm <= 40) { $score += 10; }
        else { $score += 2; }
    } else {
        $score += 8;
    }

    if (!empty($job['featured'])) {
        $score += 10;
        $reasons[] = 'Featured job';
    }

    $hoursOld = (strtotime('now') - strtotime($job['created_at'])) / 3600;
    if ($hoursOld <= 24) {
        $score += 15;
        $reasons[] = 'Posted recently';
    } elseif ($hoursOld <= 72) {
        $score += 8;
    } else {
        $score += 2;
    }

    return [
        'score' => (int)min(100, $score),
        'reasons' => $reasons,
        'distance_km' => $distanceKm,
    ];
}

function rank_jobs_for_worker(array $jobs, array $worker) {
    $today = date('Y-m-d');
    foreach ($jobs as &$job) {
        $match = score_job_for_worker($job, $worker);
        $job['match_score'] = $match['score'];
        $job['match_reasons'] = $match['reasons'];
        $job['match_distance_km'] = $match['distance_km'];
        $job['_featured_active'] = (!empty($job['featured'])
            && (empty($job['featured_end_date']) || $job['featured_end_date'] >= $today))
            ? 1 : 0;
    }
    unset($job);

    usort($jobs, function ($a, $b) {
        if ($b['_featured_active'] !== $a['_featured_active']) {
            return $b['_featured_active'] <=> $a['_featured_active'];
        }
        return $b['match_score'] <=> $a['match_score'];
    });

    return $jobs;
}

function sweep_expired_featured() {
    global $pdo;
    $pdo->exec("UPDATE service_requests SET featured = 0 WHERE featured = 1 AND featured_end_date IS NOT NULL AND featured_end_date < CURDATE()");
    $pdo->exec("UPDATE worker_profiles SET is_featured = 0 WHERE is_featured = 1 AND featured_end_date IS NOT NULL AND featured_end_date < CURDATE()");
    $pdo->exec("UPDATE worker_profiles SET is_verified = 0, verification_status = 'expired' WHERE is_verified = 1 AND verification_expiry IS NOT NULL AND verification_expiry < CURDATE()");
    $pdo->exec("UPDATE worker_profiles SET service_fee_status = 'free' WHERE service_fee_status = 'paid' AND service_fee_expiry IS NOT NULL AND service_fee_expiry < CURDATE()");

    // H2: notify workers whose service listing expires within 7 days (once per expiry cycle)
    $expiring = $pdo->query("SELECT user_id, service_fee_expiry FROM worker_profiles WHERE service_fee_status = 'paid' AND service_fee_expiry IS NOT NULL AND service_fee_expiry BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) AND service_renewal_notice_sent = 0")->fetchAll();
    foreach ($expiring as $row) {
        notify_user($row['user_id'], 'Service listing expiring soon', 'Your service listing expires on ' . $row['service_fee_expiry'] . '. Renew now to stay visible in Find Workers.', 'warning');
        $pdo->prepare("UPDATE worker_profiles SET service_renewal_notice_sent = 1 WHERE user_id = ?")->execute([$row['user_id']]);
    }
}

function consume_job_post_credit(int $userId, int $jobId): bool {
    global $pdo;
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT id, posts_remaining FROM job_post_credits WHERE user_id = ? AND posts_remaining > 0 ORDER BY created_at ASC LIMIT 1 FOR UPDATE");
        $stmt->execute([$userId]);
        $credit = $stmt->fetch();
        if (!$credit) {
            $pdo->rollBack();
            return false;
        }
        $pdo->prepare("UPDATE job_post_credits SET posts_remaining = posts_remaining - 1 WHERE id = ?")->execute([$credit['id']]);
        $pdo->prepare("UPDATE service_requests SET posting_fee_status = 'paid' WHERE id = ?")->execute([$jobId]);
        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        return false;
    }
}

function get_job_post_credits_remaining(int $userId): int {
    global $pdo;
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(posts_remaining), 0) FROM job_post_credits WHERE user_id = ? AND posts_remaining > 0");
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
}

function extract_numeric_amount($text) {
    if (preg_match('/[\d,]+(?:\.\d+)?/', (string)$text, $matches)) {
        $value = (float)str_replace(',', '', $matches[0]);
        return $value > 0 ? $value : null;
    }
    return null;
}

function get_suggested_budget_range($categoryId, $location = '', $sampleLimit = 60) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT budget, location FROM service_requests
        WHERE category_id = ? AND status IN ('completed', 'in_progress', 'open')
        ORDER BY created_at DESC LIMIT ?");
    $stmt->bindValue(1, $categoryId, PDO::PARAM_INT);
    $stmt->bindValue(2, $sampleLimit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $location = trim($location);
    $nearbyAmounts = [];
    $allAmounts = [];
    foreach ($rows as $row) {
        $amount = extract_numeric_amount($row['budget']);
        if ($amount === null) {
            continue;
        }
        $allAmounts[] = $amount;
        if ($location !== '' && stripos($row['location'], $location) !== false) {
            $nearbyAmounts[] = $amount;
        }
    }

    if (count($nearbyAmounts) >= 3) {
        $amounts = $nearbyAmounts;
        $scope = 'nearby';
    } else {
        $amounts = $allAmounts;
        $scope = 'platform';
    }

    if (count($amounts) < 2) {
        return null;
    }

    return [
        'min' => min($amounts),
        'max' => max($amounts),
        'avg' => array_sum($amounts) / count($amounts),
        'count' => count($amounts),
        'scope' => $scope,
    ];
}

function get_category_keyword_map() {
    return [
        'Errand' => ['errand', 'delivery', 'deliver', 'pickup', 'pick up', 'shopping', 'shop for', 'grocery', 'groceries', 'laundry', 'wash clothes', 'dry cleaning', 'queue', 'drop off', 'courier', 'send a package', 'market run', 'fetch', 'collect'],
        'Electrical & Technical Skills' => ['electrician', 'electrical', 'wiring', 'ac repair', 'air conditioner', 'fridge repair', 'refrigerator repair', 'generator', 'appliance repair', 'solar installation'],
        'Plumbing Skills' => ['plumber', 'plumbing', 'pipe fitting', 'pipe', 'tap fixing', 'tap repair', 'toilet repair', 'water pump', 'leak repair', 'bathroom installation'],
        'Construction & Building Skills' => ['carpenter', 'carpentry', 'mason', 'masonry', 'bricklaying', 'plastering', 'painter', 'painting', 'tiling', 'roofing', 'renovation', 'construction', 'concrete', 'building'],
        'Welding & Metal Works' => ['welding', 'welder', 'gate fabrication', 'metal fabrication', 'burglar proof', 'aluminium works', 'aluminum works'],
        'Vehicle & Mechanical Skills' => ['mechanic', 'auto repair', 'car repair', 'motorcycle repair', 'tyre fixing', 'tire repair', 'battery servicing', 'car electrical', 'auto diagnostics', 'vehicle repair'],
        'Cleaning & Domestic Services' => ['cleaner', 'cleaning service', 'house cleaning', 'compound cleaning', 'laundry', 'deep cleaning', 'gardener', 'landscaping', 'post-construction cleaning'],
        'Personal Care Services' => ['hairdresser', 'barber', 'barbering', 'braiding', 'makeup', 'nail care', 'tailor', 'sewing'],
        'Education & Tutoring' => ['tutor', 'tutoring', 'lesson', 'homework help', 'exam prep', 'maths tutor', 'english tutor', 'science tutor', 'private lessons'],
        'Digital & Tech Skills' => ['graphic design', 'logo design', 'flyer design', 'website', 'web design', 'app development', 'programming', 'coding', 'video editing', 'photo editing', 'social media', 'computer repair', 'phone repair', 'data entry', 'typing'],
        'Event Services' => ['mc service', 'master of ceremony', 'dj service', 'event decoration', 'sound system', 'photography', 'videography', 'event planning', 'catering', 'event setup'],
        'Agriculture & Local Work' => ['farm labour', 'farm work', 'cocoa farm', 'harvesting', 'agro spraying', 'animal care', 'weeding', 'planting'],
        'Micro Job' => ['transcription', 'content writing', 'copywriting', 'translation', 'virtual assistant', 'voice over', 'proofreading', 'freelance', 'remote task'],
    ];
}

function guess_category_for_text($title, $description, array $categories) {
    $haystack = strtolower(trim($title . ' ' . $description));
    if ($haystack === '') {
        return null;
    }

    $bestCategoryName = null;
    $bestMatches = [];
    foreach (get_category_keyword_map() as $categoryName => $keywords) {
        $matched = [];
        foreach ($keywords as $keyword) {
            if (strpos($haystack, $keyword) !== false) {
                $matched[] = $keyword;
            }
        }
        if (count($matched) > count($bestMatches)) {
            $bestCategoryName = $categoryName;
            $bestMatches = $matched;
        }
    }

    if ($bestCategoryName === null) {
        return null;
    }

    foreach ($categories as $category) {
        if ($category['name'] === $bestCategoryName) {
            return [
                'category_id' => $category['id'],
                'category_name' => $category['name'],
                'matched_keywords' => $bestMatches,
            ];
        }
    }
    return null;
}

function get_request_risk_signals(array $request) {
    global $pdo;
    $signals = [];

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM service_requests WHERE customer_id = ? AND created_at >= (NOW() - INTERVAL 24 HOUR)');
    $stmt->execute([$request['customer_id']]);
    $recentCount = (int)$stmt->fetchColumn();
    if ($recentCount >= 3) {
        $signals[] = "Posted {$recentCount} requests in the last 24 hours (rapid-fire posting)";
    }

    $contact = trim((string)$request['contact_info']);
    if ($contact !== '') {
        $stmt = $pdo->prepare('SELECT COUNT(DISTINCT customer_id) FROM service_requests WHERE contact_info = ? AND customer_id != ?');
        $stmt->execute([$contact, $request['customer_id']]);
        $otherAccounts = (int)$stmt->fetchColumn();
        if ($otherAccounts > 0) {
            $signals[] = "Contact info '{$contact}' is also used by {$otherAccounts} other account(s)";
        }
    }

    $amount = extract_numeric_amount($request['budget']);
    if ($amount !== null && $amount <= 20) {
        $stmt = $pdo->prepare('SELECT budget FROM service_requests WHERE customer_id = ? AND id != ?');
        $stmt->execute([$request['customer_id'], $request['id']]);
        $lowBudgetCount = 0;
        foreach ($stmt->fetchAll() as $row) {
            $otherAmount = extract_numeric_amount($row['budget']);
            if ($otherAmount !== null && $otherAmount <= 20) {
                $lowBudgetCount++;
            }
        }
        if ($lowBudgetCount >= 2) {
            $signals[] = "Repeated very low budget postings (this one GH₵{$amount}, plus {$lowBudgetCount} others ≤ GH₵20)";
        }
    }

    return $signals;
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

function category_icon($categoryName) {
    $name = strtolower($categoryName);
    $icons = [
        'errand' => '🏃',
        'micro' => '⚡',
        'clean' => '🧹',
        'plumb' => '🔧',
        'electric' => '💡',
        'construction' => '🏗️',
        'carpen' => '🪚',
        'paint' => '🎨',
        'vehicle' => '🚗',
        'auto' => '🚗',
        'personal care' => '💇',
        'tutor' => '🎓',
        'education' => '🎓',
        'digital' => '💻',
        'tech' => '💻',
        'event' => '🎉',
        'agri' => '🌾',
        'farm' => '🌾',
        'weld' => '🔩',
    ];
    foreach ($icons as $keyword => $icon) {
        if (strpos($name, $keyword) !== false) {
            return $icon;
        }
    }
    return '🧰';
}

function get_towns() {
    global $pdo;
    $stmt = $pdo->query('SELECT id, name, district FROM towns ORDER BY district, name');
    return $stmt->fetchAll();
}

function get_towns_grouped_by_district() {
    $grouped = [];
    foreach (get_towns() as $town) {
        $grouped[$town['district']][] = $town;
    }
    return $grouped;
}

function get_town_name($townId) {
    global $pdo;
    if (!$townId) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT name FROM towns WHERE id = ?');
    $stmt->execute([$townId]);
    $name = $stmt->fetchColumn();
    return $name !== false ? $name : null;
}

function get_skill_categories() {
    global $pdo;
    $stmt = $pdo->query('SELECT id, name FROM skill_categories ORDER BY id');
    return $stmt->fetchAll();
}

function get_skill_taxonomy() {
    return [
        'Electrical & Technical Skills' => ['Electrical wiring', 'House wiring repair', 'Fault finding', 'Solar installation', 'Generator repair', 'Appliance repair (TV, fridge, fan)'],
        'Plumbing Skills' => ['Pipe fitting', 'Tap fixing', 'Toilet repairs', 'Water pump installation', 'Leak repairs', 'Bathroom installation'],
        'Construction & Building Skills' => ['Masonry (block laying)', 'Bricklaying', 'Plastering', 'Tiling', 'Roofing', 'Concrete work', 'Painting (building painting)'],
        'Welding & Metal Works' => ['Welding (general)', 'Gate fabrication', 'Metal fabrication', 'Burglar proof installation', 'Aluminium works'],
        'Vehicle & Mechanical Skills' => ['Car mechanics', 'Motorcycle repair', 'Auto diagnostics', 'Tyre fixing', 'Battery servicing', 'Car electrical repair'],
        'Cleaning & Domestic Services' => ['House cleaning', 'Compound cleaning', 'Laundry services', 'Deep cleaning', 'Post-construction cleaning'],
        'Errand & Support Services' => ['Delivery service', 'Shopping assistance', 'Moving assistance', 'Loading/unloading', 'Event setup assistance'],
        'Personal Care Services' => ['Barbering', 'Hairdressing', 'Braiding', 'Makeup artistry', 'Nail care'],
        'Education & Tutoring' => ['Primary school tutoring', 'JHS tutoring', 'SHS tutoring', 'Maths tutor', 'English tutor', 'Science tutor'],
        'Digital & Tech Skills' => ['Basic computer repair', 'Phone repair', 'Typing / data entry', 'Graphic design', 'Social media management', 'Website development'],
        'Event Services' => ['MC (Master of Ceremony)', 'DJ services', 'Event decoration', 'Sound system setup', 'Photography', 'Videography'],
        'Agriculture & Local Work' => ['Farm labour', 'Cocoa farm maintenance', 'Harvesting support', 'Agro spraying', 'Animal care'],
    ];
}

function get_skill_categories_with_skills() {
    $taxonomy = get_skill_taxonomy();
    $grouped = [];
    foreach (get_skill_categories() as $category) {
        $grouped[] = [
            'id' => $category['id'],
            'name' => $category['name'],
            'skills' => $taxonomy[$category['name']] ?? [],
        ];
    }
    return $grouped;
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

function display_name($user) {
    return !empty($user['username']) ? $user['username'] : $user['name'];
}

function get_platform_setting($key, $default = null) {
    global $pdo;
    try {
        $stmt = $pdo->prepare('SELECT setting_value FROM platform_settings WHERE setting_key = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value !== false ? $value : $default;
    } catch (Exception $e) {
        return $default;
    }
}

function set_platform_setting($key, $value) {
    global $pdo;
    $pdo->prepare('INSERT INTO platform_settings (setting_key, setting_value, updated_at) VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()')
        ->execute([$key, $value]);
}

function is_feature_paid($featureKey) {
    $mode = get_platform_setting('monetization_mode', 'free');
    if ($mode === 'free') return false;
    if ($mode === 'paid') return true;
    return (bool)get_platform_setting($featureKey, 0);
}

function log_audit_action($adminId, $action, $description) {
    global $pdo;
    $pdo->prepare('INSERT INTO audit_logs (admin_id, action, description, created_at) VALUES (?, ?, ?, NOW())')
        ->execute([$adminId, $action, $description]);
}

function get_active_packages($table) {
    global $pdo;
    static $allowed = ['featured_job_packages', 'worker_promotion_packages', 'verification_packages', 'job_posting_packages', 'worker_service_packages'];
    if (!in_array($table, $allowed, true)) return [];
    $stmt = $pdo->query("SELECT * FROM $table WHERE status = 'active' ORDER BY price ASC");
    return $stmt->fetchAll();
}

function get_top_workers($limit = 10) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT u.id, u.name, u.username, w.location, w.subscription_status, w.is_featured, w.featured_end_date, w.is_verified,
        COUNT(DISTINCT sr.id) AS completed_jobs,
        COALESCE(AVG(r.score), 0) AS avg_rating
        FROM users u
        JOIN worker_profiles w ON u.id = w.user_id
        LEFT JOIN service_requests sr ON u.id = sr.assigned_worker_id AND sr.status = 'completed'
        LEFT JOIN ratings r ON sr.id = r.request_id AND r.worker_id = u.id
        WHERE u.role = 'worker' AND u.banned = 0
        GROUP BY u.id, u.name, u.username, w.location, w.subscription_status, w.is_featured, w.featured_end_date, w.is_verified
        HAVING completed_jobs > 0
        ORDER BY
          (w.is_featured = 1 AND (w.featured_end_date IS NULL OR w.featured_end_date >= CURDATE()) AND w.is_verified = 1) DESC,
          (w.is_featured = 1 AND (w.featured_end_date IS NULL OR w.featured_end_date >= CURDATE())) DESC,
          w.is_verified DESC,
          avg_rating DESC,
          completed_jobs DESC
        LIMIT ?");
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}
