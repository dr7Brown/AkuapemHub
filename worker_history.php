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
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Job title</th>
                                <th>Customer</th>
                                <th>Category</th>
                                <th>Budget</th>
                                <th>Status</th>
                                <th>Rating</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($jobHistory as $job): ?>
                                <tr>
                                    <td><a href="request_detail.php?id=<?php echo $job['id']; ?>" style="color: #0f766e; text-decoration: none;"><?php echo sanitize($job['title']); ?></a></td>
                                    <td><?php echo sanitize($job['customer_name']); ?></td>
                                    <td><?php echo sanitize($job['category_name']); ?></td>
                                    <td>GH₵ <?php echo sanitize($job['budget']); ?></td>
                                    <td><span class="status status-<?php echo sanitize($job['status']); ?>"><?php echo strtoupper(str_replace('_', ' ', $job['status'])); ?></span></td>
                                    <td><?php echo $job['rating_score'] ? sanitize($job['rating_score']) . '/5' : '—'; ?></td>
                                    <td><?php echo sanitize(date('M j, Y', strtotime($job['updated_at']))); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </main>
    <?php $activeNav = 'jobs'; require __DIR__ . '/partials/bottom_nav.php'; ?>
</body>
</html>
