<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

if (current_user()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] === 'worker' ? 'worker' : 'customer';

    if ($name === '' || $email === '' || $password === '') {
        $error = 'All fields are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please use a valid email address.';
    } else {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error = 'This email is already registered.';
        } else {
            $passwordHash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare('INSERT INTO users (name, email, password_hash, role, created_at) VALUES (?, ?, ?, ?, NOW())');
            $stmt->execute([$name, $email, $passwordHash, $role]);
            $userId = $pdo->lastInsertId();

            if ($role === 'worker') {
                $stmt = $pdo->prepare('INSERT INTO worker_profiles (user_id, bio, location, contact_phone, availability, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
                $stmt->execute([$userId, '', '', '', 'available']);
            }

            $stmt = $pdo->prepare('SELECT id, name, email, role, banned FROM users WHERE id = ?');
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
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
    <title>Register — AkuapemHub</title>
    <link rel="stylesheet" href="assets/css/style.css" />
</head>
<body>
    <main class="page-shell small-shell">
        <form class="card form-card" method="post" action="register.php">
            <h1>Create account</h1>
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo sanitize($error); ?></div>
            <?php endif; ?>
            <label>Name</label>
            <input type="text" name="name" required />
            <label>Email</label>
            <input type="email" name="email" required />
            <label>Password</label>
            <input type="password" name="password" required minlength="6" />
            <label>Role</label>
            <select name="role" required>
                <option value="customer">Customer</option>
                <option value="worker">Worker</option>
            </select>
            <button type="submit" class="button button-primary">Register</button>
            <p class="small-note">Already registered? <a href="login.php">Sign in</a></p>
        </form>
    </main>
</body>
</html>
