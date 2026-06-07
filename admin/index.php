<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';

require_login();
if (!is_admin()) {
    header('Location: ../dashboard.php');
    exit;
}

$userStmt = $pdo->query('SELECT COUNT(*) FROM users');
$totalUsers = $userStmt->fetchColumn();
$requestStmt = $pdo->query('SELECT COUNT(*) FROM service_requests');
$totalRequests = $requestStmt->fetchColumn();
$openStmt = $pdo->query('SELECT COUNT(*) FROM service_requests WHERE status = "open"');
$openRequests = $openStmt->fetchColumn();

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
        <h1>Admin panel</h1>
        <div class="topbar-actions">
            <a href="users.php" class="button button-small">Users</a>
            <a href="requests.php" class="button button-small">Requests</a>
            <a href="../logout.php" class="button button-secondary button-small">Logout</a>
        </div>
    </header>
    <main class="page-shell">
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
        </section>
    </main>
</body>
</html>
