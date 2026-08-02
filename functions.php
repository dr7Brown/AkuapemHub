<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/image_utils.php';

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

function get_flashes(): array {
    $f = get_flash();
    return $f ? [$f] : [];
}

function sanitize($value) {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/** Neutralize CSV/Excel formula injection: a cell starting with =, +, -, or @
 *  can be interpreted as a formula by Excel/Sheets when a free-text field
 *  (shop name, job title, item description, etc.) reaches a CSV export. */
function csv_safe($value): string {
    $value = (string)$value;
    return preg_match('/^[=+\-@]/', $value) ? "'" . $value : $value;
}

/**
 * Safely render HTML stored by the RichEditor.
 * Strips everything except safe formatting tags; removes on* attributes and
 * javascript: hrefs; adds target/rel to all links.
 * Falls back gracefully for legacy plain-text content.
 */
function render_rich(?string $html): string {
    if (empty(trim($html ?? ''))) return '';
    // Legacy plain text (no HTML tags) — convert to paragraphs
    if (!preg_match('/<[a-z][^>]*>/i', $html)) {
        $paras = preg_split('/\n{2,}/', trim($html));
        return '<p>' . implode('</p><p>', array_map(
            fn($p) => nl2br(htmlspecialchars(trim($p), ENT_QUOTES, 'UTF-8')),
            array_filter($paras)
        )) . '</p>';
    }
    $allowed = '<p><br><strong><b><em><i><u><s><del><h2><h3><ul><ol><li><a><blockquote><span>';
    $clean   = strip_tags($html, $allowed);
    // Strip dangerous event attributes
    $clean = preg_replace('/\s+on\w+\s*=\s*(?:"[^"]*"|\'[^\']*\')/i', '', $clean);
    // Strip javascript: and data: hrefs
    $clean = preg_replace('/\bhref\s*=\s*["\']?\s*(?:javascript|data):[^"\'>\s]*/i', 'href="#"', $clean);
    // Add target/rel to all links (idempotent)
    $clean = preg_replace('/<a\b(?![^>]*\btarget\s*=)/i', '<a target="_blank" rel="noopener noreferrer"', $clean);
    return $clean;
}

/**
 * Render a complete, consistent SEO <head> block: title, description,
 * canonical, robots, Open Graph, and Twitter Card tags. Echo the result
 * directly inside <head> — replaces ad-hoc per-page meta tag blocks.
 *
 * Options: title, description, image (absolute or site-relative URL),
 * url (defaults to the current request URL), type ('website'|'article'),
 * noindex (bool — set true for pages that shouldn't be indexed).
 */
function seo_meta(array $opts = []): string {
    $base = rtrim(BASE_URL, '/');

    $title       = trim($opts['title'] ?? APP_NAME);
    $description = trim($opts['description'] ?? (APP_NAME . ' — Find skilled workers, post service requests, and discover community news, events & funeral announcements across the Akuapem area of Ghana.'));
    $description = mb_substr($description, 0, 300);
    $type        = $opts['type'] ?? 'website';
    $noindex     = !empty($opts['noindex']);

    $image = $opts['image'] ?? ($base . '/assets/images/heroes/hero-home.jpg');
    if (!preg_match('#^https?://#i', $image)) $image = $base . '/' . ltrim($image, '/');

    $url = $opts['url'] ?? ($base . '/' . ltrim($_SERVER['REQUEST_URI'] ?? '', '/'));

    $e = fn($s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

    $out  = '<title>' . $e($title) . '</title>' . "\n";
    $out .= '    <meta name="description" content="' . $e($description) . '">' . "\n";
    $out .= '    <link rel="canonical" href="' . $e($url) . '">' . "\n";
    $out .= '    <meta name="robots" content="' . ($noindex ? 'noindex,nofollow' : 'index,follow') . '">' . "\n";
    $out .= '    <meta property="og:type" content="' . $e($type) . '">' . "\n";
    $out .= '    <meta property="og:site_name" content="' . $e(APP_NAME) . '">' . "\n";
    $out .= '    <meta property="og:title" content="' . $e($title) . '">' . "\n";
    $out .= '    <meta property="og:description" content="' . $e($description) . '">' . "\n";
    $out .= '    <meta property="og:url" content="' . $e($url) . '">' . "\n";
    $out .= '    <meta property="og:image" content="' . $e($image) . '">' . "\n";
    $out .= '    <meta name="twitter:card" content="summary_large_image">' . "\n";
    $out .= '    <meta name="twitter:title" content="' . $e($title) . '">' . "\n";
    $out .= '    <meta name="twitter:description" content="' . $e($description) . '">' . "\n";
    $out .= '    <meta name="twitter:image" content="' . $e($image) . '">';

    return $out;
}

function user_wants_email_notifications($userId) {
    global $pdo;
    $stmt = $pdo->prepare('SELECT email_notifications_enabled FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $value = $stmt->fetchColumn();
    return $value === false ? true : (bool)$value;
}

function send_email_notification($to, $subject, $message, $userId = null) {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;

    // Route through EmailService (handles SMTP + user opt-out + fallback to mail())
    $serviceFile = __DIR__ . '/services/EmailService.php';
    if (file_exists($serviceFile)) {
        if (!class_exists('EmailService', false)) require_once $serviceFile;
        return EmailService::send($to, $subject, $message, $userId);
    }

    // Hard fallback when EmailService not available
    if ($userId !== null && !user_wants_email_notifications($userId)) return false;
    $headers = 'From: ' . MAIL_FROM . "\r\nReply-To: " . MAIL_FROM
             . "\r\nContent-Type: text/html; charset=UTF-8\r\n";
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

function log_security_event(?int $userId, string $action, string $ip, string $userAgent = ''): void
{
    global $pdo;
    try {
        $pdo->prepare(
            'INSERT INTO security_logs (user_id, action, ip_address, user_agent, created_at)
             VALUES (?, ?, ?, ?, NOW())'
        )->execute([$userId, $action, $ip, $userAgent ?: null]);
    } catch (PDOException $e) {
        error_log('[security_log] ' . $e->getMessage());
    }
}

function notify_user($userId, $title, $body, $type = 'info', $link = null) {
    global $pdo;
    $stmt = $pdo->prepare('INSERT INTO notifications (user_id, title, body, type, is_read, link, created_at) VALUES (?, ?, ?, ?, 0, ?, NOW())');
    return $stmt->execute([$userId, $title, $body, $type, $link]);
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
    $stmt = $pdo->query(
        'SELECT COUNT(*) FROM service_requests sr JOIN users u ON sr.customer_id = u.id
         WHERE sr.status IN ("open","partially_staffed") AND u.banned = 0'
    );
    return (int)$stmt->fetchColumn();
}

function update_job_staffing_status(int $requestId): string {
    global $pdo;
    $job = $pdo->prepare("SELECT workers_needed FROM service_requests WHERE id = ?");
    $job->execute([$requestId]);
    $row = $job->fetch();
    if (!$row) return '';

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM applications WHERE request_id = ? AND status = 'approved'");
    $countStmt->execute([$requestId]);
    $approved = (int)$countStmt->fetchColumn();

    $needed  = max(1, (int)$row['workers_needed']);
    if ($approved === 0) {
        $newStatus = 'open';
    } elseif ($approved >= $needed) {
        $newStatus = 'fully_staffed';
    } else {
        $newStatus = 'partially_staffed';
    }

    $pdo->prepare("UPDATE service_requests SET status = ?, workers_approved = ?, updated_at = NOW() WHERE id = ? AND status NOT IN ('completed','cancelled','pending')")
        ->execute([$newStatus, $approved, $requestId]);

    return $newStatus;
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

function get_legacy_message_conversations($userId, $limit = 30) {
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
    $saved = [];

    if (empty($files['name']) || !is_array($files['name'])) {
        return $saved;
    }

    foreach ($files['name'] as $index => $name) {
        if ($files['error'][$index] !== UPLOAD_ERR_OK) {
            continue;
        }
        $file = [
            'name'     => $files['name'][$index],
            'type'     => $files['type'][$index],
            'tmp_name' => $files['tmp_name'][$index],
            'error'    => $files['error'][$index],
            'size'     => $files['size'][$index],
        ];
        $mimeType = mime_content_type($file['tmp_name']);
        if (!isset($allowedTypes[$mimeType]) || $file['size'] > $maxSize) {
            continue;
        }
        $relativePath = save_uploaded_image($file, 'uploads/completions/' . $requestId,
            (int)get_platform_setting('img_completion_maxwidth', '1200'),
            (int)get_platform_setting('img_completion_quality', '85'));
        if ($relativePath) {
            $pdo->prepare('INSERT INTO completion_photos (request_id, file_path, uploaded_at) VALUES (?, ?, NOW())')->execute([$requestId, $relativePath]);
            $saved[] = $relativePath;
        }
    }

    return $saved;
}

// ── ID verification helpers ───────────────────────────────────────────────────

function validate_ghana_card(string $number): bool {
    return (bool)preg_match('/^GHA-\d{9}-\d$/', $number);
}

function is_ghana_card_duplicate(string $number, int $excludeUserId = 0): bool {
    global $pdo;
    $stmt = $pdo->prepare("SELECT id FROM worker_profiles WHERE id_type = 'ghana_card' AND id_number = ? AND user_id != ? LIMIT 1");
    $stmt->execute([$number, $excludeUserId]);
    return (bool)$stmt->fetch();
}

function save_uploaded_image(array $file, $relativeDir, int $maxWidth = 0, int $quality = 85) {
    // Normalize MIME aliases (Windows may return image/x-png or image/pjpeg)
    $mimeAliases = [
        'image/x-png'  => 'image/png',
        'image/pjpeg'  => 'image/jpeg',
        'image/jpg'    => 'image/jpeg',
    ];
    $allowedTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $maxSize = 5 * 1024 * 1024;

    if (empty($file['name']) || $file['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    $mimeType = mime_content_type($file['tmp_name']);
    $mimeType = $mimeAliases[$mimeType] ?? $mimeType;

    if (!isset($allowedTypes[$mimeType]) || $file['size'] > $maxSize) {
        return null;
    }

    $uploadDir = __DIR__ . '/' . $relativeDir;
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        return null; // could not create directory
    }

    // Always save as JPEG for content images (smaller files, broad support)
    $ext      = ($maxWidth > 0) ? 'jpg' : $allowedTypes[$mimeType];
    $fileName = bin2hex(random_bytes(8)) . '.' . $ext;
    $destPath = $uploadDir . '/' . $fileName;

    if ($maxWidth > 0 && extension_loaded('gd')) {
        // GD resize + compress
        $src = match ($mimeType) {
            'image/jpeg' => @imagecreatefromjpeg($file['tmp_name']),
            'image/png'  => @imagecreatefrompng($file['tmp_name']),
            'image/webp' => @imagecreatefromwebp($file['tmp_name']),
            default      => false,
        };
        if ($src) {
            [$origW, $origH] = [imagesx($src), imagesy($src)];
            if ($origW > $maxWidth) {
                $ratio = $maxWidth / $origW;
                $newW  = $maxWidth;
                $newH  = (int)round($origH * $ratio);
            } else {
                [$newW, $newH] = [$origW, $origH];
            }
            $dst = imagecreatetruecolor($newW, $newH);
            imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
            $ok = imagejpeg($dst, $destPath, $quality);
            imagedestroy($src);
            imagedestroy($dst);
            if ($ok) return $relativeDir . '/' . $fileName;
            // imagejpeg failed — fall through to move_uploaded_file
        }
        // GD load failed — fall through to move_uploaded_file
    }

    if (move_uploaded_file($file['tmp_name'], $destPath)) {
        return $relativeDir . '/' . $fileName;
    }
    return null;
}

/**
 * Save an ID/verification document photo (Ghana Card, license, selfie, business
 * registration, etc.), compressed down until it's under $maxSizeBytes. Unlike
 * save_uploaded_image(), this targets a byte-size cap rather than a fixed quality —
 * ID text needs to stay legible, so it starts at a generous resolution/quality and
 * only trades away more of each as needed to hit the cap, rather than applying one
 * fixed compression level that might be too harsh for a large scan or too loose for
 * a huge phone photo.
 */
function save_uploaded_id_image(array $file, $relativeDir, int $maxSizeBytes = 1048576, int $maxWidth = 2000): ?string {
    $mimeAliases = [
        'image/x-png'  => 'image/png',
        'image/pjpeg'  => 'image/jpeg',
        'image/jpg'    => 'image/jpeg',
    ];
    $allowedTypes = ['image/jpeg' => true, 'image/png' => true, 'image/webp' => true];
    $maxUploadSize = 8 * 1024 * 1024; // raw phone photos are often 3-8MB before we compress them down

    if (empty($file['name']) || $file['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    $mimeType = mime_content_type($file['tmp_name']);
    $mimeType = $mimeAliases[$mimeType] ?? $mimeType;

    if (!isset($allowedTypes[$mimeType]) || $file['size'] > $maxUploadSize || !extension_loaded('gd')) {
        return null;
    }

    $src = match ($mimeType) {
        'image/jpeg' => @imagecreatefromjpeg($file['tmp_name']),
        'image/png'  => @imagecreatefrompng($file['tmp_name']),
        'image/webp' => @imagecreatefromwebp($file['tmp_name']),
        default      => false,
    };
    if (!$src) {
        return null;
    }

    $origW = imagesx($src);
    $origH = imagesy($src);
    $width = min($origW, $maxWidth);
    $bytes = null;

    for ($attempt = 0; $attempt < 4; $attempt++) {
        $scale    = $width / $origW;
        $w        = max(1, (int)round($origW * $scale));
        $h        = max(1, (int)round($origH * $scale));
        $resized  = imagecreatetruecolor($w, $h);
        imagefill($resized, 0, 0, imagecolorallocate($resized, 255, 255, 255));
        imagecopyresampled($resized, $src, 0, 0, 0, 0, $w, $h, $origW, $origH);

        $underCap = false;
        foreach ([85, 70, 55, 40, 28] as $quality) {
            ob_start();
            imagejpeg($resized, null, $quality);
            $data = ob_get_clean();
            $bytes = $data; // keep the smallest attempt so far as a fallback
            if (strlen($data) <= $maxSizeBytes) {
                $underCap = true;
                break;
            }
        }
        imagedestroy($resized);
        if ($underCap || $width < 500) {
            break;
        }
        $width = (int)round($width * 0.75); // still too big at every quality level — shrink and retry
    }
    imagedestroy($src);

    if ($bytes === null) {
        return null;
    }

    $uploadDir = __DIR__ . '/' . $relativeDir;
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        return null;
    }
    $fileName = bin2hex(random_bytes(8)) . '.jpg';
    $destPath = $uploadDir . '/' . $fileName;
    if (file_put_contents($destPath, $bytes) === false) {
        return null;
    }
    return $relativeDir . '/' . $fileName;
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

/**
 * Shared additive scorer behind both "workers for this job" (score_worker_for_request)
 * and "workers for this search" (score_worker_for_search) — same weight table,
 * generalized to a free-text match target and an origin coordinate pair instead
 * of a specific $request row.
 */
function score_worker_core(array $worker, ?string $matchText, ?float $originLat, ?float $originLng): array {
    $score = 0;
    $reasons = [];

    $skillMatches = count_relevant_skill_matches($worker['skills'] ?? '', $matchText ?? '', '', '');
    if ($skillMatches > 0) {
        $score += min(40, 21 + $skillMatches * 9);
        $reasons[] = $skillMatches > 1 ? 'Multiple matching skills' : 'Matching skill';
    }

    $distanceKm = distance_km($worker['latitude'] ?? null, $worker['longitude'] ?? null, $originLat, $originLng);
    if ($distanceKm !== null) {
        if ($distanceKm <= 5) { $score += 25; $reasons[] = 'Very close'; }
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

function score_worker_for_request(array $worker, array $request) {
    $matchText = trim(($request['category_name'] ?? '') . ' ' . ($request['title'] ?? '') . ' ' . ($request['description'] ?? ''));
    return score_worker_core($worker, $matchText, $request['latitude'] ?? null, $request['longitude'] ?? null);
}

/** Scores a worker against a browse/search context (find_workers.php) rather
 *  than a specific job — $skillFilter is the page's existing free-text ?skill=
 *  filter, $lat/$lng come from the "Find workers near me" geolocation button. */
function score_worker_for_search(array $worker, ?string $skillFilter, ?float $lat, ?float $lng): array {
    return score_worker_core($worker, $skillFilter, $lat, $lng);
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
    require_once __DIR__ . '/marketplace_functions.php';

    // ── Jobs & workers ────────────────────────────────────────────────────
    $pdo->exec("UPDATE service_requests SET featured = 0 WHERE featured = 1 AND featured_end_date IS NOT NULL AND featured_end_date < CURDATE()");
    $pdo->exec("UPDATE worker_profiles SET is_featured = 0 WHERE is_featured = 1 AND featured_end_date IS NOT NULL AND featured_end_date < CURDATE()");
    $pdo->exec("UPDATE worker_profiles SET is_verified = 0, verification_status = 'expired' WHERE is_verified = 1 AND verification_expiry IS NOT NULL AND verification_expiry < CURDATE()");
    $pdo->exec("UPDATE worker_profiles SET service_fee_status = 'free' WHERE service_fee_status = 'paid' AND service_fee_expiry IS NOT NULL AND service_fee_expiry < CURDATE()");

    // 2-day warning: featured jobs
    $featJobsExpiring = $pdo->query(
        "SELECT sr.id, sr.title, sr.customer_id, sr.featured_end_date
         FROM service_requests sr
         WHERE sr.featured = 1 AND sr.featured_end_date = DATE_ADD(CURDATE(), INTERVAL 2 DAY)"
    )->fetchAll();
    foreach ($featJobsExpiring as $fj) {
        notify_user((int)$fj['customer_id'], 'Featured Job Expires in 2 Days',
            '"' . $fj['title'] . '" will lose its featured placement on ' . $fj['featured_end_date'] . '. Renew to stay at the top of search results.',
            'warning', 'feature_job.php?id=' . $fj['id']);
    }

    // 2-day warning: featured worker profiles
    $featWorkersExpiring = $pdo->query(
        "SELECT wp.user_id, wp.featured_end_date, u.name
         FROM worker_profiles wp JOIN users u ON wp.user_id = u.id
         WHERE wp.is_featured = 1 AND wp.featured_end_date = DATE_ADD(CURDATE(), INTERVAL 2 DAY)"
    )->fetchAll();
    foreach ($featWorkersExpiring as $fw) {
        notify_user((int)$fw['user_id'], 'Featured Profile Expires in 2 Days',
            'Your featured worker profile expires on ' . $fw['featured_end_date'] . '. Renew to keep appearing at the top of Find Workers.',
            'warning', 'feature_worker.php');
    }

    // 7-day warning: worker service listing (once per cycle, flag prevents repeat)
    $expiring = $pdo->query("SELECT user_id, service_fee_expiry FROM worker_profiles WHERE service_fee_status = 'paid' AND service_fee_expiry IS NOT NULL AND service_fee_expiry BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) AND service_renewal_notice_sent = 0")->fetchAll();
    foreach ($expiring as $row) {
        notify_user($row['user_id'], 'Service Listing Expiring Soon', 'Your service listing expires on ' . $row['service_fee_expiry'] . '. Renew now to stay visible in Find Workers.', 'warning');
        $pdo->prepare("UPDATE worker_profiles SET service_renewal_notice_sent = 1 WHERE user_id = ?")->execute([$row['user_id']]);
    }

    // ── Marketplace products ──────────────────────────────────────────────
    try {
        $pdo->exec("UPDATE mp_products SET is_featured = 0, featured_end = NULL WHERE is_featured = 1 AND featured_end IS NOT NULL AND featured_end < CURDATE()");
        $pdo->exec("UPDATE mp_products SET is_sponsored = 0, sponsored_end = NULL WHERE is_sponsored = 1 AND sponsored_end IS NOT NULL AND sponsored_end < CURDATE()");

        // ── Marketplace shops ─────────────────────────────────────────────
        $pdo->exec("UPDATE mp_shops SET is_featured = 0, featured_end = NULL WHERE is_featured = 1 AND featured_end IS NOT NULL AND featured_end < CURDATE()");
        $pdo->exec("UPDATE mp_shops SET is_sponsored = 0, sponsored_end = NULL WHERE is_sponsored = 1 AND sponsored_end IS NOT NULL AND sponsored_end < CURDATE()");

        // Mark expired boost orders
        $pdo->exec("UPDATE mp_boost_orders SET status = 'expired' WHERE status = 'active' AND end_date < CURDATE()");

        // Notify sellers whose boost expires in 2 days
        $boostExpiring = $pdo->query(
            "SELECT mb.id, mb.boost_type, mb.end_date, ms.user_id, ms.shop_name
             FROM mp_boost_orders mb JOIN mp_shops ms ON mb.shop_id = ms.id
             WHERE mb.status = 'active' AND mb.end_date = DATE_ADD(CURDATE(), INTERVAL 2 DAY)"
        )->fetchAll();
        foreach ($boostExpiring as $b) {
            notify_user((int)$b['user_id'], 'Boost Listing Expires in 2 Days',
                ucwords(str_replace('_', ' ', $b['boost_type'])) . ' boost for ' . $b['shop_name'] . ' expires on ' . $b['end_date'] . '. Renew to keep your top visibility.',
                'warning');
        }
    } catch (Exception $e) { /* Marketplace tables not yet installed */ }

    // ── Community content: events / funerals / news featuring ─────────────
    try {
        // Expire featured listings whose end date has passed
        $pdo->exec("UPDATE events SET featured=0, featured_end_date=NULL WHERE featured=1 AND featured_end_date IS NOT NULL AND featured_end_date < CURDATE()");
        $pdo->exec("UPDATE funeral_announcements SET featured=0, featured_end_date=NULL WHERE featured=1 AND featured_end_date IS NOT NULL AND featured_end_date < CURDATE()");
        $pdo->exec("UPDATE news SET featured=0, featured_end_date=NULL WHERE featured=1 AND featured_end_date IS NOT NULL AND featured_end_date < CURDATE()");

        // 2-day expiry warnings for events
        $evExpiring = $pdo->query(
            "SELECT e.id, e.title, e.user_id, e.featured_end_date
             FROM events e
             WHERE e.featured = 1 AND e.featured_end_date = DATE_ADD(CURDATE(), INTERVAL 2 DAY)"
        )->fetchAll();
        foreach ($evExpiring as $e) {
            notify_user((int)$e['user_id'], 'Featured Event Expires in 2 Days',
                '"' . $e['title'] . '" will lose its featured placement on ' . $e['featured_end_date'] . '. Renew to stay at the top.',
                'warning', 'my_events.php');
        }

        // 2-day expiry warnings for funerals
        $faExpiring = $pdo->query(
            "SELECT fa.id, fa.deceased_name, fa.user_id, fa.featured_end_date
             FROM funeral_announcements fa
             WHERE fa.featured = 1 AND fa.featured_end_date = DATE_ADD(CURDATE(), INTERVAL 2 DAY)"
        )->fetchAll();
        foreach ($faExpiring as $fa) {
            notify_user((int)$fa['user_id'], 'Featured Announcement Expires in 2 Days',
                'The announcement for "' . $fa['deceased_name'] . '" will lose its featured placement on ' . $fa['featured_end_date'] . '.',
                'warning', 'my_funerals.php');
        }

        // 2-day expiry warnings for news articles
        $newsExpiring = $pdo->query(
            "SELECT n.id, n.title, n.user_id, n.featured_end_date
             FROM news n
             WHERE n.featured = 1 AND n.featured_end_date = DATE_ADD(CURDATE(), INTERVAL 2 DAY)"
        )->fetchAll();
        foreach ($newsExpiring as $n) {
            notify_user((int)$n['user_id'], 'Featured Article Expires in 2 Days',
                '"' . $n['title'] . '" will lose its featured placement on ' . $n['featured_end_date'] . '. Renew to stay at the top.',
                'warning', 'my_news.php');
        }
    } catch (Exception $e) {}

    // ── Marketplace seller subscriptions ──────────────────────────────────
    try {
        // Deferred downgrades: a 'pending_renewal' row whose scheduled start date
        // has arrived takes over now — mp_activate_subscription() supersedes the
        // still-active old row and mirrors mp_shops, since start_date <= today
        // makes it non-deferred at this point.
        $dueDowngrades = $pdo->query("SELECT id FROM mp_seller_subscriptions WHERE status='pending_renewal' AND start_date <= CURDATE()")->fetchAll();
        foreach ($dueDowngrades as $d) {
            mp_activate_subscription((int)$d['id']);
        }

        // Advance reminders — 14/7/3/1 day(s) before expiry, each fires exactly
        // once per subscription (mp_subscription_notifications dedup, since there
        // are multiple milestones here unlike the single-flag pattern elsewhere).
        foreach (['14d' => 14, '7d' => 7, '3d' => 3, '24h' => 1] as $milestone => $daysBefore) {
            $due = $pdo->prepare(
                "SELECT mss.id, mss.shop_id, ms.user_id, ms.phone, u.email, u.name AS user_name, msp.name AS plan_name, mss.end_date
                 FROM mp_seller_subscriptions mss
                 JOIN mp_shops ms ON mss.shop_id = ms.id
                 JOIN users u ON ms.user_id = u.id
                 JOIN mp_seller_subscription_plans msp ON mss.plan_id = msp.id
                 WHERE mss.status = 'active' AND mss.end_date = DATE_ADD(CURDATE(), INTERVAL ? DAY)
                   AND NOT EXISTS (SELECT 1 FROM mp_subscription_notifications msn WHERE msn.subscription_id = mss.id AND msn.milestone = ?)"
            );
            $due->execute([$daysBefore, $milestone]);
            foreach ($due->fetchAll() as $s) {
                $body = 'Your ' . $s['plan_name'] . ' marketplace subscription expires on ' . date('d M Y', strtotime($s['end_date'])) . '. Renew to keep your listings live.';
                notify_user((int)$s['user_id'], 'Subscription Expiring Soon', $body, 'warning', 'pay_mp_subscription.php');
                if (function_exists('send_business_message') && !empty($s['phone'])) {
                    send_business_message((int)$s['user_id'], $s['phone'], $body, 'sms');
                }
                if (!empty($s['email'])) {
                    require_once __DIR__ . '/services/EmailService.php';
                    EmailService::send($s['email'], 'Subscription Expiring Soon — ' . APP_NAME, '<p>Hi ' . htmlspecialchars($s['user_name']) . ',</p><p>' . htmlspecialchars($body) . '</p>', (int)$s['user_id']);
                }
                $pdo->prepare("INSERT IGNORE INTO mp_subscription_notifications (subscription_id, milestone) VALUES (?,?)")->execute([$s['id'], $milestone]);
            }
        }

        // On expiry
        $expired = $pdo->query("SELECT mss.id, mss.shop_id, ms.user_id FROM mp_seller_subscriptions mss JOIN mp_shops ms ON mss.shop_id=ms.id WHERE mss.status='active' AND mss.end_date < CURDATE()")->fetchAll();
        foreach ($expired as $sub) {
            $pdo->prepare("UPDATE mp_seller_subscriptions SET status='expired' WHERE id=?")->execute([$sub['id']]);
            $pdo->prepare("UPDATE mp_shops SET is_subscribed=0, subscription_plan_id=NULL, subscription_end=NULL WHERE id=?")->execute([$sub['shop_id']]);
            $pdo->prepare("INSERT INTO mp_subscription_history (subscription_id, shop_id, event) VALUES (?,?,'expired')")->execute([$sub['id'], $sub['shop_id']]);
            notify_user((int)$sub['user_id'], 'Seller Subscription Expired', 'Your seller subscription has expired. Products remain saved but are hidden from customers until you renew.', 'warning', 'pay_mp_subscription.php');
            $pdo->prepare("INSERT IGNORE INTO mp_subscription_notifications (subscription_id, milestone) VALUES (?,'on_expiry')")->execute([$sub['id']]);
        }

        // 7 days after expiry — one last nudge if still not renewed
        $stillExpired = $pdo->query(
            "SELECT mss.id, mss.shop_id, ms.user_id FROM mp_seller_subscriptions mss
             JOIN mp_shops ms ON mss.shop_id = ms.id
             WHERE mss.status = 'expired' AND mss.end_date = DATE_SUB(CURDATE(), INTERVAL 7 DAY)
               AND NOT EXISTS (SELECT 1 FROM mp_subscription_notifications msn WHERE msn.subscription_id = mss.id AND msn.milestone = '7d_after')"
        )->fetchAll();
        foreach ($stillExpired as $sub) {
            notify_user((int)$sub['user_id'], 'Still Want to Sell? Renew Your Subscription', 'Your marketplace subscription expired a week ago — your products are still saved. Renew any time to make them visible again.', 'info', 'pay_mp_subscription.php');
            $pdo->prepare("INSERT IGNORE INTO mp_subscription_notifications (subscription_id, milestone) VALUES (?,'7d_after')")->execute([$sub['id']]);
        }
    } catch (Exception $e) {}

    // ── Delivery agents ────────────────────────────────────────────────────
    try {
        $pdo->exec("UPDATE delivery_agents SET is_premium = 0, premium_start = NULL, premium_end = NULL WHERE is_premium = 1 AND premium_end IS NOT NULL AND premium_end < CURDATE()");
        $pdo->exec("UPDATE delivery_agents SET is_sponsored = 0, sponsored_end = NULL WHERE is_sponsored = 1 AND sponsored_end IS NOT NULL AND sponsored_end < CURDATE()");

        // Mark expired subscription & sponsored records
        $pdo->exec("UPDATE delivery_subscriptions SET status = 'expired' WHERE status = 'active' AND end_date < CURDATE()");
        $pdo->exec("UPDATE delivery_sponsored_listings SET status = 'expired' WHERE status = 'active' AND end_date < CURDATE()");

        // Notify delivery agents expiring within 3 days
        $dlExpiring = $pdo->query(
            "SELECT da.user_id, da.premium_end, da.sponsored_end, u.name
             FROM delivery_agents da JOIN users u ON da.user_id = u.id
             WHERE (
                 (da.is_premium = 1 AND da.premium_end = DATE_ADD(CURDATE(), INTERVAL 3 DAY))
              OR (da.is_sponsored = 1 AND da.sponsored_end = DATE_ADD(CURDATE(), INTERVAL 3 DAY))
             )"
        )->fetchAll();
        foreach ($dlExpiring as $ag) {
            notify_user((int)$ag['user_id'], 'Rider Boost Expiring Soon',
                'One of your Premium or Sponsored listings expires in 3 days. Renew from your Agent Dashboard to maintain your visibility.',
                'warning');
        }
    } catch (Exception $e) { /* Delivery tables not yet installed */ }
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
    // Excludes the 'Other' sentinel row (see get_other_town_id()) — that's an
    // FK target for users outside the Akuapem list, not a real pickable town.
    $stmt = $pdo->query("SELECT id, name, district FROM towns WHERE NOT (name='Other' AND district='Other') ORDER BY district, name");
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

/** ID of the sentinel 'Other' towns row used when a user's location isn't
 *  one of the listed Akuapem towns — see get_user_town_display(). */
function get_other_town_id(): int {
    global $pdo;
    static $id = null;
    if ($id !== null) return $id;
    $stmt = $pdo->query("SELECT id FROM towns WHERE name='Other' AND district='Other' LIMIT 1");
    $id = (int)($stmt->fetchColumn() ?: 0);
    return $id;
}

/** Display name for a user's location: their custom_town if set (they picked
 *  "Other"), otherwise their town's name. Pass the user row (needs town_id
 *  and custom_town keys). */
function get_user_town_display(array $user): ?string {
    if (!empty($user['custom_town'])) return $user['custom_town'];
    return get_town_name($user['town_id'] ?? null);
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

// ── Smart Application Tracking ───────────────────────────────────────────────

function log_application_status_change($appId, $oldStatus, $newStatus, $changedBy = null, $reason = null) {
    global $pdo;
    try {
        $pdo->prepare("INSERT INTO application_status_logs (application_id, old_status, new_status, changed_by, reason) VALUES (?,?,?,?,?)")
            ->execute([$appId, $oldStatus, $newStatus, $changedBy, $reason]);
    } catch (Exception $e) {}
}

function update_employer_score($userId, $delta, $reason = '') {
    global $pdo;
    try {
        $isNeg = $delta < 0 ? 1 : 0;
        $pdo->prepare("
            INSERT INTO employer_activity_scores (user_id, score, jobs_expired_without_review)
            VALUES (?, GREATEST(0, LEAST(200, 100 + ?)), ?)
            ON DUPLICATE KEY UPDATE
              score = GREATEST(0, LEAST(200, score + ?)),
              jobs_expired_without_review = jobs_expired_without_review + ?,
              updated_at = NOW()
        ")->execute([$userId, $delta, $isNeg, $delta, $isNeg]);
    } catch (Exception $e) {}
}

function get_employer_activity_score($userId) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT score FROM employer_activity_scores WHERE user_id = ?");
        $stmt->execute([$userId]);
        $result = $stmt->fetchColumn();
        return $result !== false ? (int)$result : 100;
    } catch (Exception $e) {
        return 100;
    }
}

function sweep_expired_jobs() {
    global $pdo;
    $stmt = $pdo->query("
        SELECT sr.id, sr.customer_id, sr.title
        FROM service_requests sr
        WHERE sr.deadline_date IS NOT NULL
          AND sr.deadline_date < NOW()
          AND sr.status IN ('open','partially_staffed','pending','pending_payment','in_progress')
    ");
    $expiredJobs = $stmt->fetchAll();
    $count = 0;

    foreach ($expiredJobs as $job) {
        $pdo->prepare("UPDATE service_requests SET status = 'expired' WHERE id = ?")
            ->execute([$job['id']]);

        $appStmt = $pdo->prepare("
            SELECT a.id, a.worker_id, a.status
            FROM applications a
            WHERE a.request_id = ?
              AND a.status NOT IN ('approved','hired','rejected','withdrawn','position_filled','expired','completed')
        ");
        $appStmt->execute([$job['id']]);
        $apps = $appStmt->fetchAll();

        foreach ($apps as $app) {
            $pdo->prepare("UPDATE applications SET status = 'expired' WHERE id = ?")
                ->execute([$app['id']]);
            log_application_status_change($app['id'], $app['status'], 'expired', null, 'Job deadline passed');
            notify_user((int)$app['worker_id'], 'Job Application Closed',
                "The recruitment for \"{$job['title']}\" has ended. Your application has been automatically closed.",
                'warning');
        }

        update_employer_score((int)$job['customer_id'], -5, 'Job expired without completing hiring');
        log_audit_action(0, 'job_auto_expired',
            "Job #{$job['id']} '{$job['title']}' auto-expired. " . count($apps) . " application(s) closed.");
        $count++;
    }

    return $count;
}

/**
 * Auto-release escrow payments whose auto_release_at has passed.
 * Called by _cron.php daily. Returns the number of payments released.
 */
function sweep_expired_escrow(): int {
    global $pdo;
    $count = 0;

    // Fetch all escrow payments that are held, the job is completed,
    // and the auto-release window has elapsed.
    $due = $pdo->query("
        SELECT ep.id, ep.job_id, ep.worker_id, ep.net_amount, ep.client_id
        FROM escrow_payments ep
        JOIN service_requests sr ON ep.job_id = sr.id
        WHERE ep.status = 'held'
          AND ep.auto_release_at IS NOT NULL
          AND ep.auto_release_at <= NOW()
          AND sr.status = 'completed'
    ")->fetchAll();

    foreach ($due as $ep) {
        // Atomic UPDATE — only succeeds if still held (prevents race conditions)
        $upd = $pdo->prepare(
            "UPDATE escrow_payments
             SET status = 'released', released_at = NOW(), release_initiated_by = 'auto'
             WHERE id = ? AND status = 'held'"
        );
        $upd->execute([$ep['id']]);

        if ($upd->rowCount() === 0) continue; // Already processed by another request

        // Mark job payment as paid
        $pdo->prepare("UPDATE service_requests SET payment_status = 'paid', updated_at = NOW() WHERE id = ?")
            ->execute([$ep['job_id']]);

        // Notify worker
        if ($ep['worker_id']) {
            notify_user(
                (int)$ep['worker_id'],
                '💸 Escrow Payment Auto-Released',
                'Your payment of GH₵ ' . number_format($ep['net_amount'], 2) .
                ' for job #' . $ep['job_id'] . ' was automatically released. ' .
                'The client did not dispute within the release window.',
                'success',
                'request_detail.php?id=' . $ep['job_id']
            );
        }

        // Notify client
        if ($ep['client_id']) {
            notify_user(
                (int)$ep['client_id'],
                'Escrow Released',
                'The escrow payment for job #' . $ep['job_id'] .
                ' was automatically released to the worker (GH₵ ' . number_format($ep['net_amount'], 2) . ').',
                'info',
                'request_detail.php?id=' . $ep['job_id']
            );
        }

        log_audit_action(0, 'escrow_auto_released',
            "Escrow #" . $ep['id'] . " auto-released for job #" . $ep['job_id'] .
            " — GH₵ " . number_format($ep['net_amount'], 2));

        $count++;
    }

    return $count;
}

/**
 * Release reserved stock for marketplace checkouts the buyer never completed
 * payment for (Paystack init failed silently, tab closed, etc). Mirrors the
 * 45-minute abandonment window used by resume_payment.php.
 */
function sweep_abandoned_marketplace_orders(): int {
    global $pdo;
    require_once __DIR__ . '/marketplace_functions.php';

    $due = $pdo->query("
        SELECT id FROM mp_orders
        WHERE payment_status = 'unpaid'
          AND status = 'pending'
          AND created_at < NOW() - INTERVAL 45 MINUTE
    ")->fetchAll();

    if (!$due) return 0;

    $orderIds = array_map(fn($r) => (int)$r['id'], $due);
    mp_cancel_order_and_restore_stock($orderIds, 'Checkout abandoned — payment not completed in time');

    log_audit_action(0, 'mp_orders_auto_cancelled',
        count($orderIds) . ' abandoned marketplace order(s) auto-cancelled, stock restored: ' . implode(',', $orderIds));

    return count($orderIds);
}

/**
 * Move marketplace seller earnings from pending_balance to available_balance
 * once the delivery-confirmation window has elapsed.
 */
function sweep_marketplace_payout_releases(): int {
    global $pdo;

    $due = $pdo->query("
        SELECT id, shop_id, net_amount
        FROM mp_orders
        WHERE status = 'delivered'
          AND payment_status = 'paid'
          AND payout_released = 0
          AND payout_release_at IS NOT NULL
          AND payout_release_at <= NOW()
    ")->fetchAll();

    $count = 0;
    foreach ($due as $order) {
        // Atomic — only succeeds if still un-released (prevents double-processing)
        $upd = $pdo->prepare("UPDATE mp_orders SET payout_released = 1, updated_at = NOW() WHERE id = ? AND payout_released = 0");
        $upd->execute([$order['id']]);
        if ($upd->rowCount() === 0) continue;

        $pdo->prepare("UPDATE mp_shops SET pending_balance = pending_balance - ?, available_balance = available_balance + ? WHERE id = ?")
            ->execute([$order['net_amount'], $order['net_amount'], $order['shop_id']]);

        $pdo->prepare("INSERT INTO mp_wallet_transactions (shop_id, order_id, type, amount, created_at) VALUES (?,?,?,?,NOW())")
            ->execute([$order['shop_id'], $order['id'], 'released_to_available', $order['net_amount']]);

        $shopOwner = $pdo->prepare('SELECT user_id FROM mp_shops WHERE id = ?');
        $shopOwner->execute([$order['shop_id']]);
        if ($uid = $shopOwner->fetchColumn()) {
            notify_user((int)$uid, '💰 Funds Available for Withdrawal',
                'GH₵ ' . number_format($order['net_amount'], 2) . ' from order #' . $order['id'] .
                ' has moved to your available balance and can now be withdrawn.',
                'success', 'seller_dashboard.php?tab=wallet');
        }

        log_audit_action(0, 'mp_payout_released',
            "Order #" . $order['id'] . " released GH₵ " . number_format($order['net_amount'], 2) . " to shop #" . $order['shop_id']);

        $count++;
    }

    return $count;
}

function send_job_deadline_reminders() {
    global $pdo;
    $sent = 0;
    $reminders = [
        ['days' => 7, 'col' => 'reminder_7_sent'],
        ['days' => 3, 'col' => 'reminder_3_sent'],
        ['days' => 1, 'col' => 'reminder_1_sent'],
    ];

    foreach ($reminders as $r) {
        $col = $r['col'];
        $days = $r['days'];
        $stmt = $pdo->query("
            SELECT sr.id, sr.customer_id, sr.title
            FROM service_requests sr
            WHERE sr.deadline_date IS NOT NULL
              AND sr.{$col} = 0
              AND sr.status IN ('open','partially_staffed')
              AND DATE(sr.deadline_date) = DATE_ADD(CURDATE(), INTERVAL {$days} DAY)
        ");
        $jobs = $stmt->fetchAll();

        foreach ($jobs as $job) {
            notify_user((int)$job['customer_id'], 'Job Vacancy Expiring Soon',
                "Your job \"{$job['title']}\" will expire in {$days} day(s). Review applicants before the deadline.",
                'warning');
            $pdo->prepare("UPDATE service_requests SET {$col} = 1 WHERE id = ?")
                ->execute([$job['id']]);
            $sent++;
        }
    }

    return $sent;
}

function mark_hiring_completed($requestId, $userId) {
    global $pdo;

    $stmt = $pdo->prepare("
        SELECT id, title, customer_id FROM service_requests
        WHERE id = ? AND (customer_id = ? OR ? = 1)
    ");
    $stmt->execute([$requestId, $userId, is_admin() ? 1 : 0]);
    $job = $stmt->fetch();
    if (!$job) return false;

    $pdo->prepare("UPDATE service_requests SET status = 'hiring_completed' WHERE id = ?")
        ->execute([$requestId]);

    $appStmt = $pdo->prepare("
        SELECT id, worker_id, status FROM applications
        WHERE request_id = ?
          AND status NOT IN ('approved','hired','rejected','withdrawn','expired','completed','position_filled')
    ");
    $appStmt->execute([$requestId]);

    foreach ($appStmt->fetchAll() as $app) {
        $pdo->prepare("UPDATE applications SET status = 'position_filled' WHERE id = ?")
            ->execute([$app['id']]);
        log_application_status_change($app['id'], $app['status'], 'position_filled', $userId,
            'Employer marked hiring as completed');
        notify_user((int)$app['worker_id'], 'Vacancy Filled',
            "The vacancy \"{$job['title']}\" has been filled. Thank you for applying.",
            'info');
    }

    update_employer_score((int)$job['customer_id'], 10, 'Job hiring completed');
    log_audit_action($userId, 'hiring_completed',
        "Job #{$requestId} '{$job['title']}' marked as hiring completed.");

    return true;
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

/**
 * Whether APCu is actually usable in this request — the extension can be
 * present but disabled (common on shared hosting, and often off by default
 * for the CLI SAPI even when on for web). Always degrade to "no cache"
 * rather than erroring, so a host without APCu behaves identically, just
 * slower.
 */
function apcu_is_available(): bool {
    static $available = null;
    if ($available === null) {
        $available = function_exists('apcu_fetch') && function_exists('apcu_store') && (bool)ini_get('apc.enabled');
    }
    return $available;
}

/**
 * Fetch $key from APCu, or compute it via $fn and cache it for $ttlSeconds.
 * Falls back to always calling $fn() when APCu isn't available — callers
 * never need their own "is caching on" branch.
 */
function apcu_remember(string $key, int $ttlSeconds, callable $fn) {
    if (!apcu_is_available()) return $fn();
    $hit = false;
    $value = apcu_fetch($key, $hit);
    if ($hit) return $value;
    $value = $fn();
    apcu_store($key, $value, $ttlSeconds);
    return $value;
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

/** Whether a platform module is switched on. $key is the settings prefix —
 *  e.g. module_enabled('mp') reads 'mp_enabled', module_enabled('jobs') reads
 *  'jobs_enabled'. Defaults to enabled so a missing row never silently
 *  disables something. */
function module_enabled(string $key): bool {
    return get_platform_setting("{$key}_enabled", '1') === '1';
}

/** Binary CIDR match, works for both IPv4 and IPv6 (inet_pton gives 4 or 16 raw bytes). */
function ip_in_cidr(string $ip, string $cidr): bool {
    [$subnet, $bits] = array_pad(explode('/', $cidr), 2, '32');
    $ipBin = @inet_pton($ip);
    $subnetBin = @inet_pton($subnet);
    if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) return false;
    $bits = (int)$bits;
    $bytes = intdiv($bits, 8);
    $remainder = $bits % 8;
    if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) return false;
    if ($remainder === 0) return true;
    $mask = (~(0xFF >> $remainder)) & 0xFF;
    return (ord($ipBin[$bytes]) & $mask) === (ord($subnetBin[$bytes]) & $mask);
}

/** Whether $ip falls in one of Cloudflare's published edge-node ranges
 *  (cloudflare.com/ips). Used to decide whether CF-Connecting-IP can be trusted. */
function is_cloudflare_edge_ip(string $ip): bool {
    static $ranges = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
        '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];
    foreach ($ranges as $range) {
        if (ip_in_cidr($ip, $range)) return true;
    }
    return false;
}

/** The visitor's real IP address. If the request came through Cloudflare (verified
 *  via REMOTE_ADDR being one of Cloudflare's published edge ranges), trusts the
 *  CF-Connecting-IP header it sets; otherwise falls back to REMOTE_ADDR directly.
 *  Use this everywhere instead of $_SERVER['REMOTE_ADDR'] so rate-limiting, security
 *  logs, and referral tracking keep working once the site is proxied through Cloudflare. */
function client_ip(): string {
    $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP']) && is_cloudflare_edge_ip($remote)) {
        $candidate = trim($_SERVER['HTTP_CF_CONNECTING_IP']);
        if (filter_var($candidate, FILTER_VALIDATE_IP)) {
            return $candidate;
        }
    }
    return $remote;
}

function set_platform_setting($key, $value) {
    global $pdo;
    $pdo->prepare('INSERT INTO platform_settings (setting_key, setting_value, updated_at) VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()')
        ->execute([$key, $value]);
}

/** Admin-managed social media links, filtered down to only the ones that
 *  currently have a URL set (empty ones are hidden, never shown as dead tiles). */
function get_social_links() {
    $platforms = [
        'facebook'         => 'Facebook',
        'instagram'        => 'Instagram',
        'tiktok'           => 'TikTok',
        'whatsapp_channel' => 'WhatsApp Channel',
    ];
    $links = [];
    foreach ($platforms as $key => $label) {
        $url = trim(get_platform_setting('social_' . $key, ''));
        if ($url !== '') {
            $links[] = ['key' => $key, 'label' => $label, 'url' => $url];
        }
    }
    return $links;
}

/** Whether email verification is currently required before the given action.
 *  $action: 'job_post' | 'job_apply' */
function requires_verified_email($action) {
    return get_platform_setting("require_verified_email_{$action}", '1') === '1';
}

/** SQL-ready list of job statuses that should appear in public listings. */
function public_job_statuses_sql() {
    $statuses = ['open', 'partially_staffed'];
    if (get_platform_setting('jobs_list_staffed_completed', '0') === '1') {
        $statuses[] = 'fully_staffed';
        $statuses[] = 'completed';
    }
    return "'" . implode("','", $statuses) . "'";
}

/**
 * Whether $userId (default: the logged-in user) has been granted a
 * complimentary membership by an admin — free access to every paid feature
 * on the platform. Always queries fresh (no session caching) so a revoke
 * takes effect on the member's very next action, not just their next login.
 */
function user_has_complimentary_access(?int $userId = null): bool {
    global $pdo;
    $userId = $userId ?? (current_user()['id'] ?? null);
    if (!$userId) return false;
    $stmt = $pdo->prepare('SELECT is_complimentary FROM users WHERE id=?');
    $stmt->execute([$userId]);
    return (bool)$stmt->fetchColumn();
}

function is_feature_paid($featureKey) {
    if (user_has_complimentary_access()) return false;
    $mode = get_platform_setting('monetization_mode', 'free');
    if ($mode === 'free') return false;
    if ($mode === 'paid') return true;
    return (bool)get_platform_setting($featureKey, 0);
}

// ── Login rate-limiting (5 failures per IP in 15 min → lockout) ─────────────

function login_rate_limit_exceeded(string $ip): bool {
    global $pdo;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ip_address VARCHAR(45) NOT NULL,
            attempted_at DATETIME NOT NULL,
            INDEX idx_ip_time (ip_address, attempted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $window = date('Y-m-d H:i:s', time() - 900); // 15-minute window
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND attempted_at > ?");
        $stmt->execute([$ip, $window]);
        return (int)$stmt->fetchColumn() >= 5;
    } catch (Exception $e) {
        return false;
    }
}

function login_rate_limit_record(string $ip): void {
    global $pdo;
    try {
        $pdo->prepare("INSERT INTO login_attempts (ip_address, attempted_at) VALUES (?, NOW())")->execute([$ip]);
        $pdo->exec("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
    } catch (Exception $e) {}
}

function login_rate_limit_clear(string $ip): void {
    global $pdo;
    try {
        $pdo->prepare("DELETE FROM login_attempts WHERE ip_address = ?")->execute([$ip]);
    } catch (Exception $e) {}
}

// ── Moderator Permission System ───────────────────────────────────────────────

/**
 * All available moderator permissions with human-readable labels.
 * Grouped by section for the admin UI.
 */
function all_mod_permissions(): array {
    return [
        'Content Approvals' => [
            'approve_jobs'              => 'Approve / reject job posts',
            'approve_products'          => 'Approve / reject marketplace products',
            'approve_shops'             => 'Verify / suspend marketplace shops',
            'approve_events'            => 'Approve / reject community events',
            'approve_funerals'          => 'Approve / reject funeral announcements',
            'approve_news'              => 'Approve / reject news articles',
            'approve_delivery_requests' => 'Approve / reject delivery requests',
            'approve_delivery_agents'   => 'Approve / reject delivery agent applications',
            'approve_verifications'     => 'Review rider & worker verification badges',
            'approve_boosts'            => 'Activate sponsored & featured listings',
            'manage_quote_requests'     => 'View marketplace quote requests platform-wide',
        ],
        'User & Community' => [
            'manage_users'     => 'View, ban, and manage user accounts',
            'manage_disputes'  => 'Mediate disputes between users',
            'manage_referrals' => 'View and manage the referral programme',
        ],
        'Platform' => [
            'manage_ads'            => 'Create, edit, and delete advertisements',
            'manage_communication'  => 'Send broadcast notifications to users',
            'view_reports'          => 'View analytics, revenue, and platform reports',
            'manage_master_catalog' => 'Manage the shared product catalog',
            'manage_towns'          => 'Manage the Akuapem towns list',
            'manage_media_settings' => 'Configure image upload sizes & quality',
        ],
    ];
}

/**
 * Returns all permission keys as a flat array (no groups).
 */
function all_mod_permission_keys(): array {
    $flat = [];
    foreach (all_mod_permissions() as $perms) {
        foreach ($perms as $key => $label) $flat[] = $key;
    }
    return $flat;
}

/**
 * Check if the current user has a specific permission.
 * Admins implicitly have every permission.
 * Managers have only what was explicitly granted.
 */
function has_mod_permission(string $permission): bool {
    static $cache    = [];
    static $tableOk  = null;
    if (is_admin()) return true;
    $user = current_user();
    if (!$user || $user['role'] !== 'manager') return false;

    $uid = (int)$user['id'];
    if (!isset($cache[$uid])) {
        global $pdo;
        // Auto-create the table if it was never migrated (v016)
        if ($tableOk === null) {
            try {
                $pdo->query('SELECT 1 FROM moderator_permissions LIMIT 1');
                $tableOk = true;
            } catch (Exception $e) {
                try {
                    $pdo->exec("CREATE TABLE IF NOT EXISTS moderator_permissions (
                        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        user_id    INT UNSIGNED NOT NULL,
                        permission VARCHAR(80) NOT NULL,
                        granted_by INT UNSIGNED NOT NULL,
                        granted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        UNIQUE KEY uq_mp_user_perm (user_id, permission),
                        KEY idx_mp_user (user_id),
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                    $tableOk = true;
                } catch (Exception $e2) {
                    $tableOk = false;
                }
            }
        }
        if ($tableOk) {
            try {
                $st = $pdo->prepare('SELECT permission FROM moderator_permissions WHERE user_id = ?');
                $st->execute([$uid]);
                $cache[$uid] = array_column($st->fetchAll(), 'permission');
            } catch (Exception $e) {
                $cache[$uid] = [];
            }
        } else {
            $cache[$uid] = [];
        }
    }
    return in_array($permission, $cache[$uid], true);
}

/**
 * Gate access by permission.
 * - GET requests that fail: render an inline HTML error (AJAX-safe — gets injected into the panel).
 * - POST requests that fail: flash + redirect.
 * - Admins always pass.
 */
function require_mod_permission(string $permission, string $back = 'index.php'): void {
    if (is_admin()) return;
    if (is_admin_or_manager() && has_mod_permission($permission)) return;

    $perm = htmlspecialchars($permission, ENT_QUOTES, 'UTF-8');
    $back = htmlspecialchars($back,       ENT_QUOTES, 'UTF-8');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        flash('You do not have the "' . $perm . '" permission.', 'error');
        header('Location: ' . $back);
    } else {
        // Return an embeddable fragment — when loaded via admin AJAX this gets injected cleanly
        http_response_code(403);
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
<link rel="stylesheet" href="../assets/css/style.css"></head><body>
<main class="page-shell" style="padding:48px 16px;text-align:center;">
<div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:14px;padding:28px 24px;max-width:480px;margin:0 auto;">
<div style="font-size:2.5rem;margin-bottom:12px;">⛔</div>
<h2 style="color:#c0392b;margin:0 0 10px;font-size:1.1rem;">Permission Denied</h2>
<p style="color:#374151;margin:0 0 10px;">You need the <strong>' . $perm . '</strong> permission to access this section.</p>
<p style="font-size:.84rem;color:#6b7280;margin:0;">Ask your administrator to grant it via
<strong>Admin → Moderators</strong> → your name → check the box → Save.</p>
</div></main></body></html>';
    }
    exit;
}

/**
 * Get all permissions granted to a specific user (by user ID).
 */
function get_user_mod_permissions(int $userId): array {
    global $pdo;
    try {
        $st = $pdo->prepare('SELECT permission FROM moderator_permissions WHERE user_id = ?');
        $st->execute([$userId]);
        return array_column($st->fetchAll(), 'permission');
    } catch (Exception $e) { return []; }
}

/**
 * Grant a permission to a moderator (idempotent — silently skips if already held).
 */
function grant_mod_permission(int $userId, string $permission, int $grantedBy): void {
    global $pdo;
    $pdo->prepare(
        'INSERT IGNORE INTO moderator_permissions (user_id, permission, granted_by) VALUES (?,?,?)'
    )->execute([$userId, $permission, $grantedBy]);
}

/**
 * Revoke a specific permission from a moderator.
 */
function revoke_mod_permission(int $userId, string $permission): void {
    global $pdo;
    $pdo->prepare('DELETE FROM moderator_permissions WHERE user_id=? AND permission=?')
        ->execute([$userId, $permission]);
}

/**
 * Revoke ALL permissions from a moderator (e.g., when demoting to regular user).
 */
function revoke_all_mod_permissions(int $userId): void {
    global $pdo;
    $pdo->prepare('DELETE FROM moderator_permissions WHERE user_id=?')->execute([$userId]);
}

/**
 * Per-feature user bans — lets an admin restrict a user from one module
 * (jobs, marketplace, news, events, funerals, delivery) without blocking
 * login or every other feature, unlike the whole-system users.banned flag.
 * Mirrors the moderator_permissions helpers above.
 */
function all_ban_features(): array {
    return [
        'jobs'     => 'Jobs & Services — post jobs, apply to jobs',
        'mp'       => 'Marketplace — sell & buy',
        'news'     => 'News — submit articles',
        'events'   => 'Events — submit events',
        'funerals' => 'Funeral Announcements — submit',
        'delivery' => 'Delivery Services — become / act as an agent',
    ];
}

/**
 * Check if a user is currently restricted from a specific feature.
 * Self-healing: creates the table if migrate/install.sql hasn't run yet.
 */
function is_banned_from_feature(int $userId, string $featureKey): bool {
    static $cache   = [];
    static $tableOk = null;
    global $pdo;

    if (!isset($cache[$userId])) {
        if ($tableOk === null) {
            try {
                $pdo->query('SELECT 1 FROM user_feature_bans LIMIT 1');
                $tableOk = true;
            } catch (Exception $e) {
                try {
                    $pdo->exec("CREATE TABLE IF NOT EXISTS user_feature_bans (
                        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        user_id     INT UNSIGNED NOT NULL,
                        feature_key VARCHAR(40) NOT NULL,
                        reason      VARCHAR(255) DEFAULT NULL,
                        banned_by   INT UNSIGNED NOT NULL,
                        banned_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        UNIQUE KEY uq_ufb_user_feature (user_id, feature_key),
                        KEY idx_ufb_user (user_id),
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                    $tableOk = true;
                } catch (Exception $e2) {
                    $tableOk = false;
                }
            }
        }
        if ($tableOk) {
            try {
                $st = $pdo->prepare('SELECT feature_key FROM user_feature_bans WHERE user_id = ?');
                $st->execute([$userId]);
                $cache[$userId] = array_column($st->fetchAll(), 'feature_key');
            } catch (Exception $e) {
                $cache[$userId] = [];
            }
        } else {
            $cache[$userId] = [];
        }
    }
    return in_array($featureKey, $cache[$userId], true);
}

/**
 * All feature keys a user is currently restricted from (flat array).
 */
function get_user_feature_bans(int $userId): array {
    global $pdo;
    try {
        $st = $pdo->prepare('SELECT feature_key FROM user_feature_bans WHERE user_id = ?');
        $st->execute([$userId]);
        return array_column($st->fetchAll(), 'feature_key');
    } catch (Exception $e) { return []; }
}

/**
 * Same as above but keyed by feature with reason/timestamp, for admin display.
 */
function get_user_feature_ban_details(int $userId): array {
    global $pdo;
    try {
        $st = $pdo->prepare('SELECT feature_key, reason, banned_at FROM user_feature_bans WHERE user_id = ?');
        $st->execute([$userId]);
        $out = [];
        foreach ($st->fetchAll() as $row) {
            $out[$row['feature_key']] = ['reason' => $row['reason'], 'banned_at' => $row['banned_at']];
        }
        return $out;
    } catch (Exception $e) { return []; }
}

/**
 * Restrict a user from a feature (idempotent — refreshes reason/banned_by if already restricted).
 */
function ban_user_from_feature(int $userId, string $featureKey, ?string $reason, int $bannedBy): void {
    global $pdo;
    $pdo->prepare(
        'INSERT INTO user_feature_bans (user_id, feature_key, reason, banned_by) VALUES (?,?,?,?)
         ON DUPLICATE KEY UPDATE reason=VALUES(reason), banned_by=VALUES(banned_by), banned_at=NOW()'
    )->execute([$userId, $featureKey, $reason, $bannedBy]);
}

/**
 * Lift a specific feature restriction from a user.
 */
function unban_user_from_feature(int $userId, string $featureKey): void {
    global $pdo;
    $pdo->prepare('DELETE FROM user_feature_bans WHERE user_id=? AND feature_key=?')->execute([$userId, $featureKey]);
}

/**
 * Shared save handler for the "Manage Restrictions" panel — used identically
 * by admin/users.php's modal and admin/user_edit.php's inline form so there is
 * exactly one code path that mutates ban state from a human admin action.
 * Returns ['granted'=>[...], 'revoked'=>[...], 'whole_changed'=>bool] so the
 * caller can build its own flash message.
 */
function apply_feature_ban_update(int $targetId, int $adminId, bool $wholeSystem, array $submittedFeatures, ?string $reason): array {
    global $pdo;

    $target = $pdo->prepare('SELECT name, banned FROM users WHERE id=?');
    $target->execute([$targetId]);
    $target = $target->fetch();
    if (!$target) return ['granted' => [], 'revoked' => [], 'whole_changed' => false];

    $wholeChanged = (bool)$target['banned'] !== $wholeSystem;
    $pdo->prepare('UPDATE users SET banned=? WHERE id=?')->execute([$wholeSystem ? 1 : 0, $targetId]);

    $validKeys = array_keys(all_ban_features());
    $submitted = array_intersect($submittedFeatures, $validKeys);
    $current   = get_user_feature_bans($targetId);
    $toBan     = array_diff($submitted, $current);
    $toUnban   = array_diff($current, $submitted);

    // Upsert every currently-checked feature (not just newly-checked ones) so a
    // retyped reason always takes effect, even without an uncheck/recheck cycle —
    // ban_user_from_feature() is idempotent (ON DUPLICATE KEY UPDATE).
    foreach ($submitted as $f) ban_user_from_feature($targetId, $f, $reason, $adminId);
    foreach ($toUnban   as $f) unban_user_from_feature($targetId, $f);

    $labels = all_ban_features();
    $bannedLabels   = array_map(fn($k) => $labels[$k], $toBan);
    $unbannedLabels = array_map(fn($k) => $labels[$k], $toUnban);

    $logParts = [];
    if ($wholeChanged) $logParts[] = 'whole-system ban ' . ($wholeSystem ? 'applied' : 'lifted');
    if ($toBan)   $logParts[] = 'restricted from: ' . implode(', ', $toBan);
    if ($toUnban) $logParts[] = 'restriction lifted from: ' . implode(', ', $toUnban);
    if ($logParts) {
        log_audit_action($adminId, 'user_feature_ban_update',
            "Updated restrictions for {$target['name']} (#{$targetId}): " . implode('; ', $logParts) . ($reason ? ". Reason: {$reason}" : ''));
    }

    if ($wholeChanged && $wholeSystem) {
        notify_user($targetId, 'Account Suspended', 'Your account has been suspended.' . ($reason ? " Reason: {$reason}" : '') . ' Contact support if you believe this is an error.', 'error');
    } elseif ($wholeChanged && !$wholeSystem) {
        notify_user($targetId, 'Account Restored', 'Your account suspension has been lifted.', 'success');
    }
    if ($toBan) {
        notify_user($targetId, 'Feature Restricted', 'You have been restricted from: ' . implode(', ', $bannedLabels) . '.' . ($reason ? " Reason: {$reason}" : ''), 'warning');
    }
    if ($toUnban) {
        notify_user($targetId, 'Restriction Lifted', 'Your restriction has been lifted for: ' . implode(', ', $unbannedLabels) . '.', 'success');
    }

    return ['granted' => $toBan, 'revoked' => $toUnban, 'whole_changed' => $wholeChanged];
}

/**
 * Notify all moderators who hold $permission about a new item needing review.
 * Called by admin pages when new content is submitted for approval.
 *
 * Example:
 *   notify_moderators('approve_products', 'New Product Pending', 'Seller X submitted "Widget" for review.');
 */
function notify_moderators(string $permission, string $title, string $body): void {
    global $pdo;
    try {
        // Get all managers who have this permission
        $st = $pdo->prepare(
            'SELECT mp.user_id FROM moderator_permissions mp
             JOIN users u ON mp.user_id = u.id
             WHERE mp.permission = ? AND u.role = "manager" AND u.banned = 0'
        );
        $st->execute([$permission]);
        foreach ($st->fetchAll() as $row) {
            notify_user((int)$row['user_id'], $title, $body, 'info');
        }
        // Also notify all admins
        $admins = $pdo->query("SELECT id FROM users WHERE role='admin' AND banned=0")->fetchAll();
        foreach ($admins as $admin) {
            notify_user((int)$admin['id'], $title, $body, 'info');
        }
    } catch (Exception $e) { /* silently skip if table not ready */ }
}

// ─────────────────────────────────────────────────────────────────────────────

// ── Moderator Performance & Rewards ──────────────────────────────────────────

/**
 * Log a moderator action and award the configured points.
 * Call this after every approve/reject/moderate action.
 *
 * @param int    $modId     User ID of the moderator who acted
 * @param string $module    Module slug: jobs|events|funerals|news|marketplace|delivery|users|disputes|ads
 * @param string $actionKey Must match a row in mod_point_config.action_key
 * @param int|null $recordId  ID of the item acted on (optional)
 * @param string|null $notes  Extra context (optional)
 */
function log_mod_activity(int $modId, string $module, string $actionKey, ?int $recordId = null, ?string $notes = null): void {
    global $pdo;
    if (!get_platform_setting('mod_perf_enabled', '1')) return;
    try {
        // Look up configured points (default 1 if not configured)
        $cfg = $pdo->prepare('SELECT points FROM mod_point_config WHERE action_key = ?');
        $cfg->execute([$actionKey]);
        $points = (float)($cfg->fetchColumn() ?: 1.0);

        $ip = client_ip();
        $pdo->prepare(
            'INSERT INTO mod_activity_log (mod_id, module, action_key, record_id, points, notes, ip_address)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([$modId, $module, $actionKey, $recordId, $points, $notes, $ip]);
    } catch (Exception $e) {
        // Silently skip if table not yet migrated
    }
}

/**
 * Get total points earned by a moderator for a time period.
 * $period: 'today' | 'week' | 'month' | 'year' | 'all'
 */
function get_mod_points(int $modId, string $period = 'all'): float {
    global $pdo;
    try {
        $dateFilter = match($period) {
            'today'  => 'AND DATE(created_at) = CURDATE()',
            'week'   => 'AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)',
            'month'  => 'AND MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())',
            'year'   => 'AND YEAR(created_at)=YEAR(NOW())',
            default  => '',
        };
        $st = $pdo->prepare("SELECT COALESCE(SUM(points),0) FROM mod_activity_log WHERE mod_id=? $dateFilter");
        $st->execute([$modId]);
        return (float)$st->fetchColumn();
    } catch (Exception $e) { return 0.0; }
}

/**
 * Get full performance stats for a moderator.
 */
function get_mod_performance(int $modId, string $period = 'month'): array {
    global $pdo;
    $dateFilter = match($period) {
        'today' => 'AND DATE(created_at) = CURDATE()',
        'week'  => 'AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)',
        'month' => 'AND MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())',
        'year'  => 'AND YEAR(created_at)=YEAR(NOW())',
        default => '',
    };
    try {
        $base  = "FROM mod_activity_log WHERE mod_id=? $dateFilter";
        $total = (int)$pdo->prepare("SELECT COUNT(*) $base")->execute([$modId]) ?
            (function() use ($pdo, $modId, $base){ $s=$pdo->prepare("SELECT COUNT(*) $base"); $s->execute([$modId]); return (int)$s->fetchColumn(); })() : 0;
        // cleaner:
        $s = $pdo->prepare("SELECT COUNT(*) $base"); $s->execute([$modId]); $total = (int)$s->fetchColumn();
        $s = $pdo->prepare("SELECT COALESCE(SUM(points),0) $base"); $s->execute([$modId]); $pts = (float)$s->fetchColumn();
        $s = $pdo->prepare("SELECT COUNT(*) $base AND action_key LIKE 'approve%'"); $s->execute([$modId]); $approvals = (int)$s->fetchColumn();
        $s = $pdo->prepare("SELECT COUNT(*) $base AND action_key LIKE 'reject%'");  $s->execute([$modId]); $rejections = (int)$s->fetchColumn();
        $s = $pdo->prepare("SELECT MIN(created_at) $base"); $s->execute([$modId]); $first = $s->fetchColumn();
        $s = $pdo->prepare("SELECT MAX(created_at) $base"); $s->execute([$modId]); $last  = $s->fetchColumn();

        // Redeemed points
        $s = $pdo->prepare("SELECT COALESCE(SUM(points_used),0) FROM mod_rewards WHERE mod_id=? AND status IN('approved','paid')");
        $s->execute([$modId]); $redeemed = (int)$s->fetchColumn();

        // Points balance
        $allPoints = get_mod_points($modId, 'all');
        $balance   = max(0, (int)$allPoints - $redeemed);

        return [
            'total_actions' => $total,
            'points'        => $pts,
            'points_all'    => $allPoints,
            'point_balance' => $balance,
            'approvals'     => $approvals,
            'rejections'    => $rejections,
            'approval_rate' => $total > 0 ? round($approvals / $total * 100, 1) : 0,
            'first_action'  => $first,
            'last_action'   => $last,
        ];
    } catch (Exception $e) {
        return ['total_actions'=>0,'points'=>0,'points_all'=>0,'point_balance'=>0,'approvals'=>0,'rejections'=>0,'approval_rate'=>0,'first_action'=>null,'last_action'=>null];
    }
}

/**
 * Check if a moderator should be auto-flagged and return flag reason or null.
 */
function get_mod_flag(?array $perf, int $modId): ?string {
    if (!$perf) return null;
    $lowAcc      = (int)get_platform_setting('mod_flag_low_accuracy',    '65');
    $inactDays   = (int)get_platform_setting('mod_flag_inactivity_days', '7');
    $highApproval= (int)get_platform_setting('mod_flag_high_approval',   '95');

    if ($inactDays > 0 && $perf['last_action'] && strtotime($perf['last_action']) < strtotime("-{$inactDays} days")) {
        return "Inactive for more than {$inactDays} days";
    }
    if ($lowAcc > 0 && $perf['total_actions'] >= 10 && $perf['approval_rate'] > 0 && (100 - $perf['approval_rate']) < $lowAcc && $perf['approval_rate'] < (100 - $lowAcc)) {
        // Rough check — in practice you'd compare approvals vs rejections vs correct decisions
    }
    if ($highApproval > 0 && $perf['total_actions'] >= 20 && $perf['approval_rate'] > $highApproval) {
        return "Very high approval rate ({$perf['approval_rate']}%) — may indicate rubber-stamping";
    }
    return null;
}

/**
 * Convert points to GHS reward value based on admin-configured tiers.
 */
function mod_points_to_ghs(int $points): float {
    $tiers = [
        1000 => (float)get_platform_setting('mod_reward_1000pts', '150.00'),
        500  => (float)get_platform_setting('mod_reward_500pts',  '60.00'),
        100  => (float)get_platform_setting('mod_reward_100pts',  '10.00'),
    ];
    $ghs = 0.0;
    foreach ($tiers as $threshold => $value) {
        $times = (int)floor($points / $threshold);
        $ghs  += $times * $value;
        $points -= $times * $threshold;
    }
    return $ghs;
}

// ─────────────────────────────────────────────────────────────────────────────

// ── Conflict of Interest (COI) Policy ────────────────────────────────────────

/**
 * Returns the user_id of the person who created/owns a given record.
 * Used to detect conflicts of interest before moderation actions.
 */
function get_record_owner_id(string $type, int $id): ?int {
    global $pdo;
    try {
        $q = match($type) {
            'job'              => 'SELECT customer_id  FROM service_requests WHERE id=?',
            'event'            => 'SELECT user_id      FROM events WHERE id=?',
            'funeral'          => 'SELECT user_id      FROM funeral_announcements WHERE id=?',
            'news'             => 'SELECT user_id      FROM news WHERE id=?',
            'product'          => 'SELECT ms.user_id   FROM mp_products mp JOIN mp_shops ms ON mp.shop_id=ms.id WHERE mp.id=?',
            'shop'             => 'SELECT user_id      FROM mp_shops WHERE id=?',
            'delivery_request' => 'SELECT customer_id  FROM delivery_requests WHERE id=?',
            'delivery_agent'   => 'SELECT user_id      FROM delivery_agents WHERE id=?',
            default            => null,
        };
        if (!$q) return null;
        $st = $pdo->prepare($q);
        $st->execute([$id]);
        $v = $st->fetchColumn();
        return $v !== false ? (int)$v : null;
    } catch (Exception $e) { return null; }
}

/**
 * Returns true if $modId is the creator/owner of the record — i.e. a conflict exists.
 * Admins are never conflicted (they can moderate anything).
 */
function check_mod_coi(string $type, int $id, int $modId): bool {
    if (is_admin()) return false;   // Admins exempt
    $ownerId = get_record_owner_id($type, $id);
    return $ownerId !== null && $ownerId === $modId;
}

/**
 * Log a COI violation, notify all admins, and optionally throw.
 * Call this when a moderator attempts to moderate their own record.
 */
function log_coi_violation(int $modId, string $type, int $id, string $attemptedAction): void {
    global $pdo;
    $ownerId = get_record_owner_id($type, $id) ?? $modId;
    $msg = "COI VIOLATION: Moderator #$modId attempted '$attemptedAction' on $type #$id (their own record, owner=$ownerId)";
    log_audit_action($modId, 'coi_violation', $msg);
    // Notify all admins
    try {
        $admins = $pdo->query("SELECT id FROM users WHERE role='admin' AND banned=0")->fetchAll();
        foreach ($admins as $a) {
            notify_user((int)$a['id'],
                '⚠️ Conflict of Interest Blocked',
                "Moderator (ID #$modId) tried to $attemptedAction their own $type (ID #$id). Action was automatically blocked.",
                'warning');
        }
    } catch (Exception $e) {}
}

// ─────────────────────────────────────────────────────────────────────────────

function log_audit_action($adminId, $action, $description) {
    global $pdo;
    $pdo->prepare('INSERT INTO audit_logs (admin_id, action, description, created_at) VALUES (?, ?, ?, NOW())')
        ->execute([$adminId ?: null, $action, $description]);
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
