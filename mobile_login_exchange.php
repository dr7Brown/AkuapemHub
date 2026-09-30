<?php
/**
 * Second half of the mobile Google Sign-In handoff (see google_callback.php
 * and assets/js/google-auth-bridge.js). Google OAuth had to run in the
 * system browser, so this is the request that actually happens inside the
 * app's own WebView — the one place a session cookie set here is visible
 * to the app itself.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

function exchange_bounce(): never {
    flash('Your sign-in link expired or was already used. Please try again.', 'error');
    header('Location: login.php');
    exit;
}

$token = trim($_GET['token'] ?? '');
if ($token === '') exchange_bounce();

$stmt = $pdo->prepare(
    'SELECT * FROM mobile_login_tokens WHERE token = ? AND used_at IS NULL AND expires_at > NOW() LIMIT 1'
);
$stmt->execute([$token]);
$row = $stmt->fetch();
if (!$row) exchange_bounce();

// Single-use regardless of what happens next.
$pdo->prepare('UPDATE mobile_login_tokens SET used_at = NOW() WHERE id = ?')->execute([$row['id']]);

$userStmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$userStmt->execute([$row['user_id']]);
$user = $userStmt->fetch();
if (!$user || !empty($user['banned'])) exchange_bounce();

login_user($user);

// dest comes from our own google_callback.php via the deep link, but treat
// it as untrusted input anyway (defense in depth) — same-site relative
// paths only, never an absolute/external URL.
$dest = (string)($_GET['dest'] ?? 'jobs.php');
if ($dest === '' || str_contains($dest, '://') || str_starts_with($dest, '//') || str_starts_with($dest, '\\')) {
    $dest = 'jobs.php';
}

header('Location: ' . $dest);
exit;
