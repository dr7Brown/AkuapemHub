<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

if (current_user()) {
    header('Location: community.php');
    exit;
}

$error = '';
$info  = '';
if (($_GET['msg'] ?? '') === 'password_changed') {
    $info = 'Your password was changed. Please log in with your new password.';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $ip       = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (login_rate_limit_exceeded($ip)) {
        $error = 'Too many failed attempts. Please wait 15 minutes before trying again.';
    } elseif ($email === '' || $password === '') {
        $error = 'Email and password are required.';
    } else {
        $stmt = $pdo->prepare('SELECT id, name, username, email, email_verified, password_hash, role, phone, town_id, latitude, longitude, profile_photo, email_notifications_enabled, banned FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || $user['banned'] || !password_verify($password, $user['password_hash'])) {
            login_rate_limit_record($ip);
            $error = 'Invalid credentials or account is blocked.';
        } else {
            login_rate_limit_clear($ip);
            login_user($user);
            header('Location: community.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Login — AkuapemHub</title>
    <link rel="stylesheet" href="assets/css/style.css" />
</head>
<body>
    <main class="page-shell small-shell">
        <div style="text-align: center; margin-bottom: var(--space-4);">
            <span style="display: inline-flex; align-items: center; justify-content: center; width: 56px; height: 56px; font-size: 28px; border-radius: 50%; background: var(--primary-soft); margin-bottom: 10px;">🏠</span>
            <h1 style="margin: 0;">Welcome back</h1>
            <p class="meta">Sign in to AkuapemHub</p>
        </div>
        <form class="card form-card" method="post" action="login.php">
            <?php echo csrf_field(); ?>
            <?php if ($info): ?>
                <div class="alert alert-info"><?php echo sanitize($info); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo sanitize($error); ?></div>
            <?php endif; ?>
            <label>Email</label>
            <input type="email" name="email" required autocomplete="email" />
            <label>Password</label>
            <input type="password" name="password" required autocomplete="current-password" />
            <button type="submit" class="button button-primary">Login</button>
            <p class="small-note">
              <a href="forgot_password.php">Forgot password?</a> &nbsp;·&nbsp;
              New here? <a href="register.php">Create account</a>
            </p>
        </form>
    </main>
</body>
</html>
