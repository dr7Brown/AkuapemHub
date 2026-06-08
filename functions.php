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

    return [
        'score' => (int)min(100, $score),
        'reasons' => $reasons,
        'distance_km' => $distanceKm,
    ];
}

function get_recommended_workers_for_request($request, $limit = 5) {
    global $pdo;
    $stmt = $pdo->query("SELECT u.id, u.name, w.id AS profile_id, w.location, w.latitude, w.longitude, w.availability, w.subscription_status,
        COALESCE(AVG(r.score), 0) AS avg_rating,
        COALESCE(COUNT(DISTINCT sr.id), 0) AS completed_jobs,
        GROUP_CONCAT(DISTINCT ws.skill_name ORDER BY ws.skill_name SEPARATOR ', ') AS skills
        FROM users u
        JOIN worker_profiles w ON u.id = w.user_id
        LEFT JOIN worker_skills ws ON w.id = ws.worker_profile_id
        LEFT JOIN service_requests sr ON u.id = sr.assigned_worker_id AND sr.status = 'completed'
        LEFT JOIN ratings r ON sr.id = r.request_id AND r.worker_id = u.id
        WHERE u.role = 'worker'
        GROUP BY u.id, u.name, w.id, w.location, w.latitude, w.longitude, w.availability, w.subscription_status");
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
    foreach ($jobs as &$job) {
        $match = score_job_for_worker($job, $worker);
        $job['match_score'] = $match['score'];
        $job['match_reasons'] = $match['reasons'];
        $job['match_distance_km'] = $match['distance_km'];
    }
    unset($job);

    usort($jobs, function ($a, $b) {
        return $b['match_score'] <=> $a['match_score'];
    });

    return $jobs;
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
