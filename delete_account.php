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
$stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
$stmt->execute([$user['id']]);
$hash = $stmt->fetchColumn();

if ($currentPassword === '' || !password_verify($currentPassword, $hash)) {
    flash('Incorrect password. Your account was not closed.', 'error');
    header('Location: settings.php');
    exit;
}

$pdo->prepare('UPDATE users SET banned = 1 WHERE id = ?')->execute([$user['id']]);
logout_user(); // Destroys session and clears cookie
flash('Your account has been deactivated. Contact support if you\'d like it reactivated.', 'info');
header('Location: login.php');
exit;
