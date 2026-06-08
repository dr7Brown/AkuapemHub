<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

if (current_user()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = 'Email and password are required.';
    } else {
        $stmt = $pdo->prepare('SELECT id, name, email, password_hash, role, phone, town_id, latitude, longitude, profile_photo, banned FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || $user['banned'] || !password_verify($password, $user['password_hash'])) {
            $error = 'Invalid credentials or account is blocked.';
        } else {
            login_user($user);
            header('Location: dashboard.php');
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
        <form class="card form-card" method="post" action="login.php">
            <h1>Sign in</h1>
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo sanitize($error); ?></div>
            <?php endif; ?>
            <label>Email</label>
            <input type="email" name="email" required />
            <label>Password</label>
            <input type="password" name="password" required />
            <button type="submit" class="button button-primary">Login</button>
            <p class="small-note">New here? <a href="register.php">Create account</a></p>
        </form>
    </main>
</body>
</html>
