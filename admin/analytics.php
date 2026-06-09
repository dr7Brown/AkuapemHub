<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';

require_login();
if (!is_admin()) {
    header('Location: ../dashboard.php');
    exit;
}

$totalUsers = $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$totalWorkers = $pdo->query('SELECT COUNT(*) FROM users WHERE role = "worker"')->fetchColumn();
$totalCustomers = $pdo->query('SELECT COUNT(*) FROM users WHERE role = "customer"')->fetchColumn();
$bannedUsers = $pdo->query('SELECT COUNT(*) FROM users WHERE banned = 1')->fetchColumn();

$totalRequests = $pdo->query('SELECT COUNT(*) FROM service_requests')->fetchColumn();
$pendingRequests = $pdo->query('SELECT COUNT(*) FROM service_requests WHERE status = "pending"')->fetchColumn();
$openRequests = $pdo->query('SELECT COUNT(*) FROM service_requests WHERE status = "open"')->fetchColumn();
$inProgressRequests = $pdo->query('SELECT COUNT(*) FROM service_requests WHERE status = "in_progress"')->fetchColumn();
$completedRequests = $pdo->query('SELECT COUNT(*) FROM service_requests WHERE status = "completed"')->fetchColumn();
$cancelledRequests = $pdo->query('SELECT COUNT(*) FROM service_requests WHERE status = "cancelled"')->fetchColumn();

$totalFeatured = $pdo->query('SELECT COUNT(*) FROM service_requests WHERE featured = 1')->fetchColumn();
$premiumWorkers = get_premium_worker_count();

$totalRatings = $pdo->query('SELECT COUNT(*) FROM ratings')->fetchColumn();
$avgRating = $pdo->query('SELECT COALESCE(AVG(score), 0) FROM ratings')->fetchColumn();

$totalPaidTransactions = $pdo->query('SELECT COUNT(*) FROM payments WHERE status = "paid"')->fetchColumn();
$paidAmountStmt = $pdo->prepare('SELECT COALESCE(SUM(CAST(amount AS DECIMAL(10,2))), 0) FROM payments WHERE status = "paid" AND amount REGEXP ?');
$paidAmountStmt->execute(['^[0-9]+(\.[0-9]{1,2})?$']);
$totalPaidAmount = $paidAmountStmt->fetchColumn();
if (!$totalPaidAmount) {
    $totalPaidAmount = $pdo->query('SELECT COALESCE(SUM(CAST(amount AS DECIMAL(10,2))), 0) FROM payments WHERE status = "paid"')->fetchColumn();
}

$topWorkers = $pdo->query('SELECT u.id, u.name, COUNT(sr.id) AS completed_jobs, COALESCE(AVG(r.score), 0) AS avg_rating FROM users u LEFT JOIN service_requests sr ON u.id = sr.assigned_worker_id AND sr.status = "completed" LEFT JOIN ratings r ON sr.id = r.request_id WHERE u.role = "worker" GROUP BY u.id ORDER BY completed_jobs DESC LIMIT 5')->fetchAll();

$topCategories = $pdo->query('SELECT c.name, COUNT(sr.id) AS job_count FROM service_categories c LEFT JOIN service_requests sr ON c.id = sr.category_id GROUP BY c.id ORDER BY job_count DESC LIMIT 5')->fetchAll();

$platformRevenue = $pdo->query("
    SELECT
        COALESCE(SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END), 0) AS total_confirmed,
        COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) AS total_pending,
        COUNT(CASE WHEN status = 'paid' THEN 1 END) AS count_confirmed,
        COUNT(CASE WHEN status = 'pending' THEN 1 END) AS count_pending,
        COALESCE(SUM(CASE WHEN payment_type = 'featured_job'    AND status = 'paid' THEN amount ELSE 0 END), 0) AS feat_job,
        COALESCE(SUM(CASE WHEN payment_type = 'featured_worker' AND status = 'paid' THEN amount ELSE 0 END), 0) AS feat_worker,
        COALESCE(SUM(CASE WHEN payment_type = 'verification'    AND status = 'paid' THEN amount ELSE 0 END), 0) AS verification,
        COALESCE(SUM(CASE WHEN payment_type = 'job_post'        AND status = 'paid' THEN amount ELSE 0 END), 0) AS job_post,
        COALESCE(SUM(CASE WHEN payment_type = 'worker_service'  AND status = 'paid' THEN amount ELSE 0 END), 0) AS worker_service
    FROM platform_payments
")->fetch();

// 30-day daily revenue for sparkline
$dailyRevStmt = $pdo->query("
    SELECT DATE(paid_at) AS day, COALESCE(SUM(amount), 0) AS revenue
    FROM platform_payments
    WHERE status = 'paid' AND paid_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
    GROUP BY DATE(paid_at)
    ORDER BY day ASC
");
$dailyRevRows = [];
foreach ($dailyRevStmt->fetchAll() as $r) {
    $dailyRevRows[$r['day']] = (float)$r['revenue'];
}
$chartLabels = [];
$chartData   = [];
for ($i = 29; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $chartLabels[] = date('M j', strtotime($d));
    $chartData[]   = $dailyRevRows[$d] ?? 0;
}

$verifiedWorkers = $pdo->query("SELECT COUNT(*) FROM worker_profiles WHERE is_verified = 1")->fetchColumn();
$activeListings  = $pdo->query("SELECT COUNT(*) FROM worker_profiles WHERE service_fee_status = 'paid' AND (service_fee_expiry IS NULL OR service_fee_expiry >= CURDATE())")->fetchColumn();
$featuredJobs    = $pdo->query("SELECT COUNT(*) FROM service_requests WHERE featured = 1 AND (featured_end_date IS NULL OR featured_end_date >= CURDATE())")->fetchColumn();
$featuredWorkers = $pdo->query("SELECT COUNT(*) FROM worker_profiles WHERE is_featured = 1 AND (featured_end_date IS NULL OR featured_end_date >= CURDATE())")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Admin Analytics — AkuapemHub</title>
    <link rel="stylesheet" href="../assets/css/style.css" />
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
</head>
<body>
    <header class="topbar">
        <a href="index.php" class="button button-secondary button-small">Back</a>
        <h1>Analytics</h1>
        <a href="../logout.php" class="button button-secondary button-small">Logout</a>
    </header>
    <main class="page-shell">
        <h2 style="margin-bottom: 16px;">Users</h2>
        <section class="panel stats-grid">
            <div class="stat-card">
                <h2><?php echo $totalUsers; ?></h2>
                <p>Total users</p>
            </div>
            <div class="stat-card">
                <h2><?php echo $totalWorkers; ?></h2>
                <p>Workers</p>
            </div>
            <div class="stat-card">
                <h2><?php echo $totalCustomers; ?></h2>
                <p>Customers</p>
            </div>
            <div class="stat-card">
                <h2><?php echo $bannedUsers; ?></h2>
                <p>Banned</p>
            </div>
            <div class="stat-card">
                <h2><?php echo $premiumWorkers; ?></h2>
                <p>Premium workers</p>
            </div>
        </section>

        <h2 style="margin: 32px 0 16px;">Requests</h2>
        <section class="panel stats-grid">
            <div class="stat-card">
                <h2><?php echo $totalRequests; ?></h2>
                <p>Total requests</p>
            </div>
            <div class="stat-card">
                <h2><?php echo $pendingRequests; ?></h2>
                <p>Pending approval</p>
            </div>
            <div class="stat-card">
                <h2><?php echo $openRequests; ?></h2>
                <p>Open</p>
            </div>
            <div class="stat-card">
                <h2><?php echo $inProgressRequests; ?></h2>
                <p>In progress</p>
            </div>
            <div class="stat-card">
                <h2><?php echo $completedRequests; ?></h2>
                <p>Completed</p>
            </div>
            <div class="stat-card">
                <h2><?php echo $totalFeatured; ?></h2>
                <p>Featured</p>
            </div>
        </section>

        <h2 style="margin: 32px 0 16px;">Ratings &amp; Revenue</h2>
        <section class="panel stats-grid">
            <div class="stat-card">
                <h2><?php echo $totalRatings; ?></h2>
                <p>Ratings received</p>
            </div>
            <div class="stat-card">
                <h2><?php echo number_format($avgRating, 1); ?>/5</h2>
                <p>Average rating</p>
            </div>
            <div class="stat-card">
                <h2><?php echo $totalPaidTransactions; ?></h2>
                <p>Paid transactions</p>
            </div>
            <div class="stat-card">
                <h2>GH₵ <?php echo number_format($totalPaidAmount, 2); ?></h2>
                <p>Total paid</p>
            </div>
        </section>

        <h2 style="margin: 32px 0 16px;">Platform Revenue</h2>
        <section class="panel stats-grid">
            <div class="stat-card">
                <h2>GH₵ <?php echo number_format($platformRevenue['total_confirmed'], 2); ?></h2>
                <p>Confirmed revenue</p>
            </div>
            <div class="stat-card">
                <h2>GH₵ <?php echo number_format($platformRevenue['total_pending'], 2); ?></h2>
                <p>Pending payments</p>
            </div>
            <div class="stat-card">
                <h2><?php echo (int)$platformRevenue['count_confirmed']; ?></h2>
                <p>Confirmed transactions</p>
            </div>
            <div class="stat-card">
                <h2><?php echo (int)$platformRevenue['count_pending']; ?></h2>
                <p>Pending transactions</p>
            </div>
        </section>
        <section class="panel">
            <h3 style="margin:0 0 12px;">Revenue by type (confirmed)</h3>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Type</th>
                            <th>Amount (GH₵)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td>Featured Jobs</td><td><?php echo number_format($platformRevenue['feat_job'], 2); ?></td></tr>
                        <tr><td>Featured Workers</td><td><?php echo number_format($platformRevenue['feat_worker'], 2); ?></td></tr>
                        <tr><td>Verification Badges</td><td><?php echo number_format($platformRevenue['verification'], 2); ?></td></tr>
                        <tr><td>Job Posting Fees</td><td><?php echo number_format($platformRevenue['job_post'], 2); ?></td></tr>
                        <tr><td>Worker Service Listings</td><td><?php echo number_format($platformRevenue['worker_service'], 2); ?></td></tr>
                    </tbody>
                </table>
            </div>
        </section>
        <section class="panel" style="margin-top:24px;">
            <h3 style="margin:0 0 16px;">Daily revenue — last 30 days</h3>
            <canvas id="revenueChart" height="90"></canvas>
            <script>
            (function() {
                var labels = <?php echo json_encode($chartLabels); ?>;
                var data   = <?php echo json_encode($chartData); ?>;
                var max    = Math.max.apply(null, data) || 1;
                new Chart(document.getElementById('revenueChart'), {
                    type: 'bar',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: 'GH₵ revenue',
                            data: data,
                            backgroundColor: 'rgba(79,140,255,0.7)',
                            borderRadius: 3,
                            borderSkipped: false,
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: { legend: { display: false }, tooltip: {
                            callbacks: { label: ctx => ' GH₵ ' + ctx.parsed.y.toFixed(2) }
                        }},
                        scales: {
                            x: { grid: { display: false }, ticks: { maxTicksLimit: 10, font: { size: 11 } } },
                            y: { beginAtZero: true, ticks: { callback: v => 'GH₵' + v } }
                        }
                    }
                });
            })();
            </script>
        </section>

        <section class="panel stats-grid" style="margin-bottom:0;">
            <div class="stat-card">
                <h2><?php echo (int)$verifiedWorkers; ?></h2>
                <p>Verified workers</p>
            </div>
            <div class="stat-card">
                <h2><?php echo (int)$activeListings; ?></h2>
                <p>Active service listings</p>
            </div>
            <div class="stat-card">
                <h2><?php echo (int)$featuredJobs; ?></h2>
                <p>Active featured jobs</p>
            </div>
            <div class="stat-card">
                <h2><?php echo (int)$featuredWorkers; ?></h2>
                <p>Active featured workers</p>
            </div>
        </section>

        <h2 style="margin: 32px 0 16px;">Top Workers</h2>
        <section class="panel">
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Completed jobs</th>
                            <th>Avg rating</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($topWorkers as $worker): ?>
                            <tr>
                                <td><?php echo sanitize($worker['name']); ?></td>
                                <td><?php echo $worker['completed_jobs']; ?></td>
                                <td><?php echo number_format($worker['avg_rating'], 1); ?>/5</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <h2 style="margin: 32px 0 16px;">Popular Categories</h2>
        <section class="panel">
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th>Job count</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($topCategories as $category): ?>
                            <tr>
                                <td><?php echo sanitize($category['name']); ?></td>
                                <td><?php echo $category['job_count']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</body>
</html>
