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
    <main class="page-shell small-shell">
        <section class="card">
            <?php if (!$notifications): ?>
                <div class="empty-state">No notifications yet.</div>
            <?php else: ?>
                <?php foreach ($notifications as $notification): ?>
                    <div class="list-row">
                        <span class="menu-icon"><?php echo notification_icon($notification['type']); ?></span>
                        <span class="list-row-body">
                            <strong><?php echo sanitize($notification['title']); ?></strong>
                            <p><?php echo sanitize($notification['body']); ?></p>
                        </span>
                        <span class="list-row-meta"><?php echo sanitize(time_ago($notification['created_at'])); ?></span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </main>
    <?php $activeNav = 'home'; require __DIR__ . '/partials/bottom_nav.php'; ?>
</body>
</html>
