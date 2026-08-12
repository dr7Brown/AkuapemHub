<?php
/**
 * Central Ajax endpoint.  All actions require a logged-in session and a valid CSRF token.
 * CSRF token may be sent as POST field csrf_token OR HTTP header X-CSRF-TOKEN.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_login();
$user = current_user();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

csrf_check();

$action = trim($_POST['action'] ?? '');

switch ($action) {

    // ── Mark all notifications read ───────────────────────────────────────────
    case 'mark_notifications_read':
        mark_notifications_read($user['id']);
        echo json_encode(['ok' => true]);
        break;

    // ── Mark single notification read ────────────────────────────────────────
    case 'mark_notification_read':
        $nid = (int)($_POST['notification_id'] ?? 0);
        if ($nid) {
            $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?')
                ->execute([$nid, $user['id']]);
        }
        echo json_encode(['ok' => true]);
        break;

    // ── Recent notifications for the popup opened from the bell icon ─────────
    case 'get_recent_notifications':
        $stmt = $pdo->prepare(
            'SELECT id, title, body, type, link, is_read, created_at FROM notifications
             WHERE user_id = ? ORDER BY is_read ASC, created_at DESC LIMIT 8'
        );
        $stmt->execute([$user['id']]);
        $rows = $stmt->fetchAll();
        $out = array_map(function ($n) {
            return [
                'id'         => (int)$n['id'],
                'title'      => $n['title'],
                'preview'    => mb_substr(strip_tags($n['body']), 0, 120),
                'type'       => $n['type'],
                'link'       => $n['link'],
                'is_read'    => (bool)$n['is_read'],
                'time_ago'   => time_ago($n['created_at']),
            ];
        }, $rows);
        echo json_encode(['ok' => true, 'notifications' => $out]);
        break;

    // ── Delete a draft ────────────────────────────────────────────────────────
    case 'delete_draft':
        $id = intval($_POST['id'] ?? 0);
        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing id']);
            exit;
        }
        $pdo->prepare("DELETE FROM service_requests WHERE id = ? AND status = 'draft' AND customer_id = ?")
            ->execute([$id, $user['id']]);
        echo json_encode(['ok' => true]);
        break;

    // ── Update worker availability ────────────────────────────────────────────
    case 'update_availability':
        if (!is_worker()) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            exit;
        }
        $av    = $_POST['availability'] ?? '';
        $valid = array_keys(get_availability_options());
        if (!in_array($av, $valid, true)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid value']);
            exit;
        }
        $pdo->prepare("UPDATE worker_profiles SET availability = ?, updated_at = NOW() WHERE user_id = ?")
            ->execute([$av, $user['id']]);
        echo json_encode(['ok' => true, 'availability' => $av]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
}
