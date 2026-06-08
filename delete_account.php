<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_login();
$user = current_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: settings.php');
    exit;
}

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
unset($_SESSION['user']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Account closed — AkuapemHub</title>
    <link rel="stylesheet" href="assets/css/style.css" />
</head>
<body>
    <main class="page-shell small-shell">
        <section class="card form-card">
            <h1>Account closed</h1>
            <p class="meta">Your AkuapemHub account has been deactivated and you have been signed out. Contact support if you'd like it reactivated.</p>
            <a href="login.php" class="button button-primary">Back to sign in</a>
        </section>
    </main>
</body>
</html>
