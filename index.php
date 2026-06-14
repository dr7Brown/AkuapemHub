<?php
require_once __DIR__ . '/auth.php';
$user = current_user();
if ($user) {
    header('Location: dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>AkuapemHub — Ghana Service Requests</title>
    <link rel="stylesheet" href="assets/css/style.css" />
</head>
<body>
    <main class="page-shell">
        <section class="hero-card">
            <h1>Welcome to AkuapemHub</h1>
            <p class="hero-description">Request errands, hire skilled workers, and post micro jobs in a single mobile-first system for Ghana.</p>
            <div class="hero-actions">
                <a href="register.php" class="button button-primary">Create account</a>
                <a href="login.php" class="button button-secondary">Sign in</a>
                <a href="find_workers.php" class="button button-secondary">Find workers</a>
                <a href="leaderboard.php" class="button button-secondary">Leaderboard</a>
            </div>
        </section>
        <section class="info-grid">
            <article>
                <h2>Quick service requests</h2>
                <p>Create an errand, skilled work order, or micro job in seconds.</p>
            </article>
            <article>
                <h2>Worker match</h2>
                <p>Workers see nearby jobs and accept tasks with one tap.</p>
            </article>
            <article>
                <h2>Admin control</h2>
                <p>Admins approve requests, manage users, and keep the platform safe.</p>
            </article>
        </section>
    </main>
    <footer style="text-align:center;padding:20px 16px 32px;font-size:.8rem;color:#6b7280;border-top:1px solid #e5e7eb;margin-top:20px;">
        &copy; <?php echo date('Y'); ?> AkuapemHub &nbsp;·&nbsp;
        <a href="contact.php" style="color:#0f766e;">Contact</a> &nbsp;·&nbsp;
        <a href="privacy.php" style="color:#0f766e;">Privacy Policy</a> &nbsp;·&nbsp;
        <a href="terms.php" style="color:#0f766e;">Terms of Service</a>
    </footer>
</body>
</html>
