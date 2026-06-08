<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_login();
require_role('worker');
$user = current_user();

$jobHistory = get_worker_job_history($user['id'], 50);
$completedCount = get_worker_completed_jobs($user['id']);
$avgRating = get_worker_average_rating($user['id']);
$earnings = get_paid_total_by_worker($user['id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Job History — AkuapemHub</title>
    <link rel="stylesheet" href="assets/css/style.css" />
</head>
<body class="has-bottom-nav">
    <header class="app-topbar">
        <span class="brand"><span class="brand-icon">🧰</span> My Jobs</span>
        <a href="logout.php" class="button button-secondary button-small">Logout</a>
    </header>
    <main class="page-shell">
        <section class="panel stats-grid">
            <div class="stat-card">
                <h2><?php echo $completedCount; ?></h2>
                <p>Completed jobs</p>
            </div>
            <div class="stat-card">
                <h2><?php echo number_format($avgRating, 1); ?>/5</h2>
                <p>Average rating</p>
            </div>
            <div class="stat-card">
                <h2>GH₵ <?php echo number_format($earnings, 2); ?></h2>
                <p>Paid earnings</p>
            </div>
        </section>
        <section class="panel">
            <h2>Job timeline</h2>
            <?php if (!$jobHistory): ?>
                <div class="empty-state">No jobs yet.</div>
            <?php else: ?>
                <?php foreach ($jobHistory as $job): ?>
                    <a href="request_detail.php?id=<?php echo $job['id']; ?>" class="job-timeline-card">
                        <div class="job-timeline-head">
                            <h3><?php echo sanitize($job['title']); ?></h3>
                            <span class="status status-<?php echo sanitize($job['status']); ?>"><?php echo strtoupper(str_replace('_', ' ', $job['status'])); ?></span>
                        </div>
                        <div class="job-timeline-meta">
                            <span><?php echo category_icon($job['category_name']); ?> <?php echo sanitize($job['category_name']); ?></span>
                            <span>👤 <?php echo sanitize($job['customer_name']); ?></span>
                            <span>💵 GH₵ <?php echo sanitize($job['budget']); ?></span>
                            <?php if ($job['rating_score']): ?>
                                <span class="rating-pill">⭐ <?php echo sanitize($job['rating_score']); ?>/5</span>
                            <?php endif; ?>
                            <span>📅 <?php echo sanitize(date('M j, Y', strtotime($job['updated_at']))); ?></span>
                        </div>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </main>
    <?php $activeNav = 'jobs'; require __DIR__ . '/partials/bottom_nav.php'; ?>
</body>
</html>
