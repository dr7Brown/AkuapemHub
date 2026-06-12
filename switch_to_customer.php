<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_login();
require_role('worker');
$user = current_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: settings.php');
    exit;
}

$pdo->prepare('UPDATE users SET role = ? WHERE id = ?')->execute(['customer', $user['id']]);

$stmt = $pdo->prepare('SELECT id, name, email, email_verified, role, phone, town_id, latitude, longitude, profile_photo, email_notifications_enabled, banned FROM users WHERE id = ?');
$stmt->execute([$user['id']]);
$_SESSION['user'] = $stmt->fetch();

notify_user($user['id'], 'Switched to customer mode', 'You are now browsing AkuapemHub as a customer. Your worker profile and skills are kept — switch back any time from Settings.', 'info');
flash('You are now in customer mode. Your worker profile is kept, so you can switch back any time.');
header('Location: dashboard.php');
exit;
