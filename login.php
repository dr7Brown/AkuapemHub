<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

$redirectTo = safe_redirect_target($_POST['redirect'] ?? $_GET['redirect'] ?? null);

if (current_user()) {
    header('Location: ' . ($redirectTo !== '' ? $redirectTo : 'community.php'));
    exit;
}

$error = '';
$info  = '';
if (($_GET['msg'] ?? '') === 'password_changed') {
    $info = 'Your password was changed. Please log in with your new password.';
} elseif (($_GET['msg'] ?? '') === 'session_expired') {
    $info = 'Your session has expired due to inactivity. Please log in again — you\'ll be taken right back to where you left off.';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $ip       = client_ip();
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

        if (!$user || !password_verify($password, $user['password_hash'])) {
            login_rate_limit_record($ip);
            $error = 'Invalid email or password.';
        } elseif ($user['banned']) {
            login_rate_limit_record($ip);
            $error = 'Your account has been blocked. Please contact support for assistance.';
        } elseif (requires_verified_email('login') && !$user['email_verified']) {
            // Don't count as a failed attempt — credentials are correct, just unverified
            $error = 'Please verify your email address before logging in. <a href="resend_verification.php?email=' . urlencode($user['email']) . '" style="color:var(--primary,#0f766e);font-weight:700;">Resend verification email →</a>';
        } else {
            login_rate_limit_clear($ip);
            login_user($user);
            if (!empty($_POST['remember_me'])) {
                create_remember_token((int)$user['id']);
            }
            header('Location: ' . ($redirectTo !== '' ? $redirectTo : 'community.php'));
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
    <title>Login — AkuapemConnect</title>
    <link rel="stylesheet" href="assets/css/style.css" />
</head>
<body>
    <main class="page-shell small-shell">
        <div style="text-align: center; margin-bottom: var(--space-4);">
            <img src="assets/images/ac%20logo%20removedbg.png" alt="AkuapemConnect" style="height:72px;width:auto;margin-bottom:12px;">
            <h1 style="margin: 0;">Welcome back</h1>
            <p class="meta">Sign in to AkuapemConnect</p>
        </div>
        <form class="card form-card" method="post" action="login.php">
            <?php echo csrf_field(); ?>
            <?php if ($redirectTo !== ''): ?><input type="hidden" name="redirect" value="<?php echo sanitize($redirectTo); ?>"><?php endif; ?>
            <?php if ($info): ?>
                <div class="alert alert-info"><?php echo sanitize($info); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo $error; /* may contain a safe resend link */ ?></div>
            <?php endif; ?>
            <label>Email</label>
            <input type="email" name="email" required autocomplete="email" />
            <label>Password</label>
            <input type="password" name="password" id="login-password" required autocomplete="current-password" />
            <label class="pw-show-label">
              <input type="checkbox" onchange="togglePasswordField('login-password', this.checked)" />
              Show password
            </label>
            <label class="pw-show-label">
              <input type="checkbox" name="remember_me" value="1" />
              Stay logged in
            </label>
            <button type="submit" class="button button-primary">Login</button>
            <p class="small-note">
              <a href="forgot_password.php">Forgot password?</a> &nbsp;·&nbsp;
              New here? <a href="register.php">Create account</a>
            </p>
        </form>
    </main>
    <script src="assets/js/password-toggle.js"></script>
</body>
</html>
