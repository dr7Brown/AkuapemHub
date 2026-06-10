<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';

require_login();
if (!is_admin_or_manager()) {
    header('Location: ../dashboard.php');
    exit;
}

$userStmt = $pdo->query('SELECT COUNT(*) FROM users');
$totalUsers = $userStmt->fetchColumn();
$requestStmt = $pdo->query('SELECT COUNT(*) FROM service_requests');
$totalRequests = $requestStmt->fetchColumn();
$openStmt = $pdo->query('SELECT COUNT(*) FROM service_requests WHERE status = "open"');
$openRequests = $openStmt->fetchColumn();
$pendingStmt = $pdo->query('SELECT COUNT(*) FROM service_requests WHERE status = "pending"');
$pendingRequests = $pendingStmt->fetchColumn();
$completedStmt = $pdo->query('SELECT COUNT(*) FROM service_requests WHERE status = "completed"');
$completedRequests = $completedStmt->fetchColumn();
$premiumWorkers = get_premium_worker_count();
$pendingPlatformPayments = $pdo->query("SELECT COUNT(*) FROM platform_payments WHERE status = 'pending'")->fetchColumn();
$pendingPostingFeeJobs   = $pdo->query("SELECT COUNT(*) FROM service_requests WHERE posting_fee_status = 'pending'")->fetchColumn();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Admin Dashboard — AkuapemHub</title>
    <link rel="stylesheet" href="../assets/css/style.css" />
</head>
<body>
    <header class="topbar">
        <h1><?php echo is_admin() ? 'Admin panel' : 'Manager panel'; ?></h1>
        <div class="topbar-actions">
            <?php if (is_admin()): ?>
                <a href="users.php" class="button button-small">Users</a>
            <?php endif; ?>
            <a href="requests.php" class="button button-small">Requests</a>
            <a href="applications.php" class="button button-small">Applications</a>
            <?php if (is_admin()): ?>
                <a href="disputes.php" class="button button-small">Disputes</a>
                <a href="business_messages.php" class="button button-small">Messages</a>
                <a href="analytics.php" class="button button-small">Analytics</a>
                <a href="monetization.php" class="button button-small">Monetization</a>
                <a href="communication.php" class="button button-small">Communication</a>
                <a href="theme.php" class="button button-small">🎨 Theme</a>
            <?php endif; ?>
            <a href="../settings.php" class="button button-secondary button-small">Settings</a>
            <a href="../logout.php" class="button button-secondary button-small">Logout</a>
        </div>
    </header>
    <main class="page-shell">
        <?php if ($pendingPlatformPayments > 0): ?>
            <div class="alert alert-warning" style="margin-bottom:16px;">
                💳 <strong><?php echo (int)$pendingPlatformPayments; ?> pending payment<?php echo $pendingPlatformPayments > 1 ? 's' : ''; ?></strong> awaiting confirmation.
                <a href="monetization.php?tab=payments" style="color:var(--primary);margin-left:6px;">Review in Monetization →</a>
                <?php if ($pendingPostingFeeJobs > 0): ?>
                    &nbsp;·&nbsp; <?php echo (int)$pendingPostingFeeJobs; ?> job<?php echo $pendingPostingFeeJobs > 1 ? 's' : ''; ?> blocked by unpaid posting fee.
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <section class="panel stats-grid">
            <div class="stat-card">
                <h2><?php echo $totalUsers; ?></h2>
                <p>Registered users</p>
            </div>
            <div class="stat-card">
                <h2><?php echo $totalRequests; ?></h2>
                <p>Total service requests</p>
            </div>
            <div class="stat-card">
                <h2><?php echo $openRequests; ?></h2>
                <p>Open requests</p>
            </div>
            <div class="stat-card">
                <h2><?php echo $pendingRequests; ?></h2>
                <p>Pending approval</p>
            </div>
            <div class="stat-card">
                <h2><?php echo $completedRequests; ?></h2>
                <p>Completed jobs</p>
            </div>
            <div class="stat-card">
                <h2><?php echo $premiumWorkers; ?></h2>
                <p>Premium workers</p>
            </div>
        </section>
    </main>
</body>
</html>
