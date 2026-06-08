<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_login();
$user = current_user();
mark_notifications_read($user['id']);
$notifications = get_notifications($user['id'], 20);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Notifications — AkuapemHub</title>
    <link rel="stylesheet" href="assets/css/style.css" />
</head>
<body class="has-bottom-nav">
    <header class="app-topbar">
        <span class="brand"><span class="brand-icon">🔔</span> Notifications</span>
    </header>
    <main class="page-shell">
        <section class="panel">
            <?php if (!$notifications): ?>
                <div class="empty-state">No notifications yet.</div>
            <?php else: ?>
                <?php foreach ($notifications as $notification): ?>
                    <article class="request-card">
                        <div class="request-head">
                            <h2><?php echo sanitize($notification['title']); ?></h2>
                            <span class="status status-<?php echo sanitize($notification['type']); ?>"><?php echo strtoupper($notification['type']); ?></span>
                        </div>
                        <p><?php echo nl2br(sanitize($notification['body'])); ?></p>
                        <p class="meta"><?php echo sanitize($notification['created_at']); ?></p>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </main>
    <?php $activeNav = 'home'; require __DIR__ . '/partials/bottom_nav.php'; ?>
</body>
</html>
