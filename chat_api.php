<?php
/**
 * Chat AJAX endpoint.
 * Actions: send, poll, mark_read, report, soft_delete, get_conversations
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/chat_functions.php';

header('Content-Type: application/json');

if (!current_user()) {
    echo json_encode(['error' => 'Unauthenticated']); exit;
}
$user   = current_user();
$action = $_POST['action'] ?? $_GET['action'] ?? '';

function json_out(array $d): void { echo json_encode($d); exit; }
function json_err(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['error' => $msg]); exit;
}

// ── send ─────────────────────────────────────────────────────────────────────
if ($action === 'send') {
    $convId = intval($_POST['conversation_id'] ?? 0);
    $text   = trim($_POST['message'] ?? '');
    if (!$convId) json_err('Invalid conversation.');
    if (!can_access_conversation($convId, $user['id'])) json_err('Access denied.', 403);

    $status = get_user_chat_status($user['id']);
    if (!$status['can_send']) json_err($status['banned']
        ? 'Your messaging is suspended until ' . date('M j, Y', strtotime($status['ban_until'])) . '.'
        : 'Your messaging privileges have been disabled.');

    // Check conversation still active
    $convStmt = $pdo->prepare("SELECT status FROM conversations WHERE id = ?");
    $convStmt->execute([$convId]);
    $conv = $convStmt->fetch();
    if (!$conv || $conv['status'] !== 'active') json_err('This conversation is no longer active.');

    $msgType  = 'text';
    $filePath = null;

    if (!empty($_FILES['file']['tmp_name'])) {
        $allowed = ['image/jpeg','image/png','image/webp','image/gif','application/pdf'];
        $mime    = mime_content_type($_FILES['file']['tmp_name']);
        if (!in_array($mime, $allowed)) json_err('File type not allowed.');
        if ($_FILES['file']['size'] > 5 * 1024 * 1024) json_err('File too large (max 5 MB).');
        $ext      = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
        $saveName = 'chat_' . $user['id'] . '_' . uniqid() . '.' . $ext;
        $dir      = __DIR__ . '/uploads/chat/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        move_uploaded_file($_FILES['file']['tmp_name'], $dir . $saveName);
        $filePath = 'uploads/chat/' . $saveName;
        $msgType  = in_array($mime, ['image/jpeg','image/png','image/webp','image/gif']) ? 'image' : 'file';
        if (!$text) $text = $msgType === 'image' ? '[Image]' : '[File: ' . htmlspecialchars($_FILES['file']['name'], ENT_QUOTES) . ']';
    }

    if (!$text) json_err('Message cannot be empty.');
    if (mb_strlen($text) > 2000) json_err('Message too long (max 2000 chars).');

    // Suspicion flags
    $flags     = detect_suspicious_content($text);
    $isFlagged = !empty($flags) ? 1 : 0;
    $flagReason = $isFlagged ? implode(', ', $flags) : null;

    $pdo->prepare("INSERT INTO chat_messages (conversation_id, sender_id, message, message_type, file_path, is_flagged, flag_reason, created_at) VALUES (?,?,?,?,?,?,?,NOW())")
        ->execute([$convId, $user['id'], $text, $msgType, $filePath, $isFlagged, $flagReason]);
    $msgId = (int)$pdo->lastInsertId();

    // Touch conversation updated_at equivalent — update status column to 'active' (no-op, but forces MySQL to register change time)
    // We rely on message created_at for ordering so no extra column needed.

    json_out(['ok' => true, 'message_id' => $msgId, 'flagged' => (bool)$isFlagged]);
}

// ── poll ─────────────────────────────────────────────────────────────────────
if ($action === 'poll') {
    $convId  = intval($_GET['conversation_id'] ?? 0);
    $afterId = intval($_GET['after_id'] ?? 0);
    if (!$convId) json_err('Invalid conversation.');
    if (!can_access_conversation($convId, $user['id'])) json_err('Access denied.', 403);

    $msgs = get_conversation_messages($convId, $user['id'], 50, $afterId);

    // Mark fetched messages as read
    if ($msgs) {
        $ids = array_column($msgs, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = $ids;
        $params[] = $user['id'];
        $pdo->prepare("UPDATE chat_messages SET is_read=1 WHERE id IN ($placeholders) AND sender_id != ? AND is_read=0")
            ->execute($params);
    }

    json_out(['messages' => $msgs, 'unread_total' => get_total_unread_chat_count($user['id'])]);
}

// ── mark_read ────────────────────────────────────────────────────────────────
if ($action === 'mark_read') {
    $convId = intval($_POST['conversation_id'] ?? 0);
    if (!$convId || !can_access_conversation($convId, $user['id'])) json_err('Invalid.');
    $pdo->prepare("UPDATE chat_messages SET is_read=1 WHERE conversation_id=? AND sender_id!=? AND is_read=0")
        ->execute([$convId, $user['id']]);
    json_out(['ok' => true]);
}

// ── report ────────────────────────────────────────────────────────────────────
if ($action === 'report') {
    $msgId  = intval($_POST['message_id'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');
    if (!$msgId || !$reason) json_err('Missing fields.');
    // Verify the reporter can see this message
    $stmt = $pdo->prepare("SELECT cm.conversation_id FROM chat_messages cm JOIN conversation_participants cp ON cm.conversation_id=cp.conversation_id AND cp.user_id=? WHERE cm.id=? LIMIT 1");
    $stmt->execute([$user['id'], $msgId]);
    if (!$stmt->fetch()) json_err('Access denied.', 403);
    $pdo->prepare("INSERT INTO message_reports (message_id, reported_by, reason, status, created_at) VALUES (?,?,?,'pending',NOW())")
        ->execute([$msgId, $user['id'], substr($reason, 0, 500)]);
    json_out(['ok' => true]);
}

// ── soft_delete ────────────────────────────────────────────────────────────────
if ($action === 'soft_delete') {
    $msgId = intval($_POST['message_id'] ?? 0);
    if (!$msgId) json_err('Invalid.');
    $stmt = $pdo->prepare("SELECT * FROM chat_messages WHERE id=?");
    $stmt->execute([$msgId]);
    $msg = $stmt->fetch();
    if (!$msg) json_err('Not found.');
    if ((int)$msg['sender_id'] === $user['id']) {
        $pdo->prepare("UPDATE chat_messages SET deleted_by_sender=1 WHERE id=?")->execute([$msgId]);
    } else {
        // check recipient
        if (!can_access_conversation((int)$msg['conversation_id'], $user['id'])) json_err('Access denied.', 403);
        $pdo->prepare("UPDATE chat_messages SET deleted_by_receiver=1 WHERE id=?")->execute([$msgId]);
    }
    json_out(['ok' => true]);
}

// ── get_conversations ──────────────────────────────────────────────────────────
if ($action === 'get_conversations') {
    json_out(['conversations' => get_user_conversations($user['id'])]);
}

json_err('Unknown action.');
