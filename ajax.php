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
