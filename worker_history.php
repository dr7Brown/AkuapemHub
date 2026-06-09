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

// Earnings ledger: individual paid payments for this worker's jobs
$ledgerStmt = $pdo->prepare("
    SELECT p.id, p.amount, p.created_at AS paid_at,
           sr.id AS job_id, sr.title AS job_title,
           u.name AS customer_name
    FROM payments p
    JOIN service_requests sr ON p.request_id = sr.id
    JOIN users u ON sr.customer_id = u.id
    WHERE sr.assigned_worker_id = ? AND p.status = 'paid'
    ORDER BY p.created_at ASC
");
$ledgerStmt->execute([$user['id']]);
$ledgerRows = $ledgerStmt->fetchAll();
// Compute cumulative running total (ascending), then reverse for display
$running = 0;
foreach ($ledgerRows as &$row) {
    $running += (float)$row['amount'];
    $row['cumulative'] = $running;
}
unset($row);
$ledgerRows = array_reverse($ledgerRows);
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
        <?php if (!empty($ledgerRows)): ?>
        <section class="panel" style="margin-bottom:0;">
            <h2 style="margin-top:0;">Earnings ledger</h2>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Job</th>
                            <th>Customer</th>
                            <th style="text-align:right;">Amount</th>
                            <th style="text-align:right;">Running total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ledgerRows as $row): ?>
                            <tr>
                                <td class="meta"><?php echo sanitize(date('M j, Y', strtotime($row['paid_at']))); ?></td>
                                <td><a href="request_detail.php?id=<?php echo $row['job_id']; ?>" style="color:var(--primary);"><?php echo sanitize($row['job_title']); ?></a></td>
                                <td><?php echo sanitize($row['customer_name']); ?></td>
                                <td style="text-align:right;">GH₵ <?php echo number_format((float)$row['amount'], 2); ?></td>
                                <td style="text-align:right;color:var(--success,#2d9c4f);font-weight:600;">GH₵ <?php echo number_format($row['cumulative'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php endif; ?>

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
