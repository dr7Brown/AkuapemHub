<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_login();
if (!is_admin()) {
    header('Location: dashboard.php');
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
$totalPaidAmount = $pdo->query('SELECT COALESCE(SUM(CAST(amount AS DECIMAL(10,2))), 0) FROM payments WHERE status = "paid" AND amount REGEXP ?', ['^[0-9]+(\.[0-9]{1,2})?$']);
if (!$totalPaidAmount) {
    $totalPaidAmount = $pdo->query('SELECT COALESCE(SUM(CAST(amount AS DECIMAL(10,2))), 0) FROM payments WHERE status = "paid"')->fetchColumn();
}

$topWorkers = $pdo->query('SELECT u.id, u.name, COUNT(sr.id) AS completed_jobs, COALESCE(AVG(r.score), 0) AS avg_rating FROM users u LEFT JOIN service_requests sr ON u.id = sr.assigned_worker_id AND sr.status = "completed" LEFT JOIN ratings r ON sr.id = r.request_id WHERE u.role = "worker" GROUP BY u.id ORDER BY completed_jobs DESC LIMIT 5')->fetchAll();

$topCategories = $pdo->query('SELECT c.name, COUNT(sr.id) AS job_count FROM service_categories c LEFT JOIN service_requests sr ON c.id = sr.category_id GROUP BY c.id ORDER BY job_count DESC LIMIT 5')->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Admin Analytics — AkuapemHub</title>
    <link rel="stylesheet" href="assets/css/style.css" />
</head>
<body>
    <header class="topbar">
        <a href="admin/index.php" class="button button-secondary button-small">Admin Panel</a>
        <h1>Analytics</h1>
        <a href="logout.php" class="button button-secondary button-small">Logout</a>
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
