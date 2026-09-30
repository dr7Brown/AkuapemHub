<?php
/**
 * Registers (or refreshes) this device's push token against the logged-in
 * user. Called by assets/js/push-bridge.js — only ever runs inside the
 * Android/iOS app shell (mobile-app/), never from a plain browser tab.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

header('Content-Type: application/json');

$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not logged in.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

try {
    csrf_check();
} catch (Throwable $e) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Security check failed.']);
    exit;
}

$token    = trim($_POST['token'] ?? '');
$platform = trim($_POST['platform'] ?? '');

if ($token === '' || !in_array($platform, ['android', 'ios'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing or invalid token/platform.']);
    exit;
}

// A token belongs to exactly one device. If it was previously registered to
// a different user (e.g. someone signed out and a different person signed
// into the same phone), re-point it rather than erroring — the old owner no
// longer holds this token anyway once a fresh 'registration' event fires.
$pdo->prepare(
    'INSERT INTO push_tokens (user_id, platform, token, created_at, updated_at)
     VALUES (?, ?, ?, NOW(), NOW())
     ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), platform = VALUES(platform), updated_at = NOW()'
)->execute([$user['id'], $platform, $token]);

echo json_encode(['ok' => true]);
