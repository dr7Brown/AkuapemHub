<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_login();

$customerId = intval($_GET['id'] ?? 0);
if ($customerId <= 0) {
    header('Location: dashboard.php');
    exit;
}

$stmt = $pdo->prepare("SELECT id, name, username, created_at, profile_photo, town_id, role FROM users WHERE id = ? AND role IN ('customer','worker') AND banned = 0");
$stmt->execute([$customerId]);
$customer = $stmt->fetch();

if (!$customer) {
    header('Location: dashboard.php');
    exit;
}

// Public stats
$statsStmt = $pdo->prepare("SELECT
    COUNT(*) AS total_posted,
    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS total_completed,
    SUM(CASE WHEN status IN ('open','pending','in_progress') THEN 1 ELSE 0 END) AS total_active
    FROM service_requests WHERE customer_id = ?");
$statsStmt->execute([$customerId]);
$stats = $statsStmt->fetch();

// Recent jobs posted (public-safe: title, category, status, date)
$jobsStmt = $pdo->prepare("SELECT sr.title, c.name AS category_name, sr.status, sr.location, sr.created_at
    FROM service_requests sr
    JOIN service_categories c ON sr.category_id = c.id
    WHERE sr.customer_id = ? AND sr.status IN ('open','in_progress','completed')
    ORDER BY sr.created_at DESC LIMIT 8");
$jobsStmt->execute([$customerId]);
$recentJobs = $jobsStmt->fetchAll();

// Ratings this customer gave (shows they engage, and workers can see what they value)
$ratingsStmt = $pdo->prepare("SELECT r.score, r.comment, c.name AS category_name, sr.title, r.created_at
    FROM ratings r
    JOIN service_requests sr ON r.request_id = sr.id
    JOIN service_categories c ON sr.category_id = c.id
    WHERE r.customer_id = ?
    ORDER BY r.created_at DESC LIMIT 6");
$ratingsStmt->execute([$customerId]);
$ratingsGiven = $ratingsStmt->fetchAll();

$avgGiven = count($ratingsGiven)
    ? round(array_sum(array_column($ratingsGiven, 'score')) / count($ratingsGiven), 1)
    : null;

$currentUser = current_user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?php echo sanitize(display_name($customer)); ?> — AkuapemHub</title>
    <link rel="stylesheet" href="assets/css/style.css" />
</head>
<body class="has-bottom-nav">
    <header class="app-topbar">
        <a href="javascript:history.back()" class="button button-secondary button-small">← Back</a>
        <span class="brand">Customer Profile</span>
    </header>
    <main class="page-shell small-shell">

        <!-- Profile hero -->
        <section class="panel">
            <div style="display:flex;align-items:flex-start;gap:16px;">
                <?php if (!empty($customer['profile_photo'])): ?>
                    <img src="<?php echo sanitize($customer['profile_photo']); ?>" alt="" class="avatar" style="width:64px;height:64px;flex-shrink:0;" />
                <?php else: ?>
                    <span class="avatar" style="width:64px;height:64px;font-size:1.6rem;flex-shrink:0;"><?php echo sanitize(strtoupper(substr(display_name($customer), 0, 1))); ?></span>
                <?php endif; ?>
                <div style="flex:1;min-width:0;">
                    <h2 style="margin:0 0 4px;font-size:1.2rem;"><?php echo sanitize(display_name($customer)); ?></h2>
                    <?php if (!empty($customer['username']) && $customer['username'] !== $customer['name']): ?>
                        <p class="meta" style="margin:0 0 4px;">@<?php echo sanitize($customer['username']); ?></p>
                    <?php endif; ?>
                    <p class="meta" style="margin:0;">
                        <?php if ($customer['town_id']): ?>📍 <?php echo sanitize(get_town_name((int)$customer['town_id'])); ?> · <?php endif; ?>
                        Member since <?php echo sanitize(date('M Y', strtotime($customer['created_at']))); ?>
                    </p>
                </div>
            </div>

            <!-- Stats -->
            <div class="stats-grid" style="margin-top:20px;">
                <div class="stat-card">
                    <h2><?php echo (int)$stats['total_posted']; ?></h2>
                    <p>Jobs posted</p>
                </div>
                <div class="stat-card">
                    <h2><?php echo (int)$stats['total_completed']; ?></h2>
                    <p>Completed</p>
                </div>
                <div class="stat-card">
                    <h2><?php echo $avgGiven !== null ? $avgGiven : '—'; ?></h2>
                    <p>Avg rating given</p>
                </div>
            </div>
        </section>

        <!-- Recent jobs -->
        <?php if (!empty($recentJobs)): ?>
            <section class="panel">
                <h3 style="margin-top:0;">Recent jobs posted</h3>
                <div style="display:flex;flex-direction:column;gap:8px;">
                    <?php foreach ($recentJobs as $job): ?>
                        <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;padding:10px 12px;background:var(--surface);border-radius:8px;border:1px solid var(--border);">
                            <div style="min-width:0;">
                                <strong style="font-size:0.93rem;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo sanitize(substr($job['title'], 0, 48)); ?></strong>
                                <span class="meta" style="font-size:0.8rem;"><?php echo sanitize($job['category_name']); ?><?php if ($job['location']): ?> · <?php echo sanitize($job['location']); ?><?php endif; ?></span>
                            </div>
                            <span class="status status-<?php echo sanitize($job['status']); ?>" style="flex-shrink:0;"><?php echo strtoupper(sanitize($job['status'])); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <!-- Ratings given -->
        <?php if (!empty($ratingsGiven)): ?>
            <section class="panel">
                <h3 style="margin-top:0;">Reviews left by this customer</h3>
                <div style="display:flex;flex-direction:column;gap:10px;">
                    <?php foreach ($ratingsGiven as $r): ?>
                        <div style="padding:12px;background:var(--surface);border-radius:10px;border:1px solid var(--border);">
                            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;margin-bottom:4px;">
                                <span style="font-size:0.88rem;color:var(--text-muted);"><?php echo sanitize($r['category_name']); ?> · <?php echo sanitize(substr($r['title'], 0, 40)); ?></span>
                                <span style="background:#fef9c3;color:#92400e;border-radius:5px;padding:2px 8px;font-size:0.83rem;white-space:nowrap;flex-shrink:0;">★ <?php echo sanitize($r['score']); ?>/5</span>
                            </div>
                            <?php if ($r['comment']): ?>
                                <p style="margin:0;font-size:0.87rem;font-style:italic;color:var(--text-muted);">"<?php echo sanitize($r['comment']); ?>"</p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if (empty($recentJobs) && empty($ratingsGiven)): ?>
            <div class="empty-state">This customer hasn't posted any jobs yet.</div>
        <?php endif; ?>

    </main>
    <?php $activeNav = 'home'; require __DIR__ . '/partials/bottom_nav.php'; ?>
</body>
</html>
