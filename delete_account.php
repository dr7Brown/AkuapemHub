<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_login();
$user = current_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: settings.php');
    exit;
}

csrf_check();

$currentPassword = $_POST['current_password'] ?? '';
$reason          = trim($_POST['reason'] ?? '');

$stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
$stmt->execute([$user['id']]);
$hash = $stmt->fetchColumn();

if ($currentPassword === '' || !password_verify($currentPassword, $hash)) {
    flash('Incorrect password. Your account closure request was not submitted.', 'error');
    header('Location: settings.php?section=privacy');
    exit;
}
if ($reason === '') {
    flash('Please tell us why you want to close your account.', 'error');
    header('Location: settings.php?section=privacy');
    exit;
}

// One pending request at a time
$existing = $pdo->prepare("SELECT id FROM account_deletion_requests WHERE user_id=? AND status='pending' LIMIT 1");
$existing->execute([$user['id']]);
if ($existing->fetch()) {
    flash('You already have an account closure request pending review.', 'info');
    header('Location: settings.php?section=privacy');
    exit;
}

$pdo->prepare('INSERT INTO account_deletion_requests (user_id, reason, status, created_at) VALUES (?, ?, \'pending\', NOW())')
    ->execute([$user['id'], $reason]);

$admins = $pdo->query("SELECT id FROM users WHERE role='admin'")->fetchAll();
foreach ($admins as $adm) {
    notify_user((int)$adm['id'], 'Account Closure Request',
        display_name($user) . ' has requested to close their account. Review in Admin → Account Requests.',
        'warning');
}

flash('Your account closure request has been submitted for admin review. You\'ll be notified once it\'s been processed.', 'success');
header('Location: settings.php?section=privacy');
exit;
