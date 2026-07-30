<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

if (empty($_SESSION['impersonator_admin_id'])) {
    header('Location: index.php');
    exit;
}

$adminId = (int)$_SESSION['impersonator_admin_id'];
$backTo  = (int)($_SESSION['impersonator_return_id'] ?? 0);

$stmt = $pdo->prepare('SELECT id, name, username, email, email_verified, role, phone, town_id, custom_town, latitude, longitude, profile_photo, email_notifications_enabled, banned FROM users WHERE id=?');
$stmt->execute([$adminId]);
$admin = $stmt->fetch();

log_audit_action($adminId, 'user_impersonation_end', 'Exited impersonation');

session_regenerate_id(true);
unset($_SESSION['impersonator_admin_id'], $_SESSION['impersonator_admin_name'], $_SESSION['impersonator_return_id']);
$_SESSION['user'] = $admin ?: null;
$_SESSION['session_started_at'] = time();
unset($_SESSION['_pw_check_at']);

if (!$admin) {
    // Admin account no longer exists — safe fallback is a fresh login.
    header('Location: login.php');
    exit;
}

header('Location: admin/user_edit.php' . ($backTo ? '?id=' . $backTo : ''));
exit;
