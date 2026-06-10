<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';

require_login();
if (!is_admin_or_manager()) {
    header('Location: ../dashboard.php');
    exit;
}

// Admin is read-only for applications — hiring decisions belong to job owners
$statusFilter = $_GET['status'] ?? '';
$where = [];
$params = [];
if ($statusFilter) {
    $where[] = 'a.status = ?';
    $params[] = $statusFilter;
}
$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("
    SELECT a.*, sr.title AS request_title, sr.status AS request_status, sr.location,
           sr.budget, sr.workers_needed, sr.workers_approved, sr.customer_id,
           w.name AS worker_name, w.email AS worker_email,
           c.name AS customer_name,
           wp.is_verified AS worker_is_verified, wp.is_featured AS worker_is_featured
    FROM applications a
    JOIN service_requests sr ON a.request_id = sr.id
    JOIN users w ON a.worker_id = w.id
    JOIN users c ON sr.customer_id = c.id
    LEFT JOIN worker_profiles wp ON w.id = wp.user_id
    $whereClause
    ORDER BY FIELD(a.status,'pending','approved','rejected') ASC, a.applied_at DESC
    LIMIT 300
");
$params ? $stmt->execute($params) : $stmt->execute();
$applications = $stmt->fetchAll();

// Summary counts
$counts = $pdo->query("SELECT status, COUNT(*) AS n FROM applications GROUP BY status")->fetchAll();
$countMap = array_column($counts, 'n', 'status');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Job Applications (Monitor) — AkuapemHub</title>
    <link rel="stylesheet" href="../assets/css/style.css" />
    <style>
        .stat-row { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:16px; }
        .stat-chip { padding:6px 14px; border-radius:20px; font-size:0.82rem; font-weight:700; border:1px solid var(--border); }
    </style>
</head>
<body>
    <header class="topbar">
        <a href="index.php" class="button button-secondary button-small">Back</a>
        <h1>Job applications <span style="font-size:0.75rem;font-weight:400;color:var(--text-muted);">(read-only — hiring is managed by job owners)</span></h1>
        <a href="../logout.php" class="button button-secondary button-small">Logout</a>
    </header>
    <main class="page-shell">
        <div class="stat-row">
            <span class="stat-chip" style="background:#fef3c7;">⏳ Pending: <?php echo (int)($countMap['pending']??0); ?></span>
            <span class="stat-chip" style="background:#d1fae5;">✓ Approved: <?php echo (int)($countMap['approved']??0); ?></span>
            <span class="stat-chip" style="background:#fde8e8;">✗ Rejected: <?php echo (int)($countMap['rejected']??0); ?></span>
        </div>

        <div class="filter-form" style="margin-bottom:16px;">
            <a href="applications.php" class="button button-secondary button-small <?php echo !$statusFilter?'button-primary':''; ?>">All</a>
            <?php foreach (['pending','approved','rejected'] as $s): ?>
                <a href="applications.php?status=<?php echo $s; ?>" class="button button-secondary button-small <?php echo $statusFilter===$s?'button-primary':''; ?>"><?php echo ucfirst($s); ?></a>
            <?php endforeach; ?>
        </div>

        <section class="panel">
            <?php if (!$applications): ?>
                <div class="empty-state">No applications match the filter.</div>
            <?php else: ?>
                <?php foreach ($applications as $application): ?>
                    <article class="request-card">
                        <div class="request-head">
                            <div>
                                <h2><?php echo sanitize($application['request_title']); ?></h2>
                                <p class="meta">
                                    <?php echo sanitize($application['location']); ?> • GH₵ <?php echo sanitize($application['budget']); ?>
                                    • Job: <strong><?php echo strtoupper(str_replace('_',' ',$application['request_status'])); ?></strong>
                                    • Need <?php echo (int)$application['workers_needed']; ?>, Approved <?php echo (int)$application['workers_approved']; ?>, Remaining <?php echo max(0,(int)$application['workers_needed']-(int)$application['workers_approved']); ?>
                                </p>
                            </div>
                            <span class="status status-<?php echo sanitize($application['status']); ?>"><?php echo strtoupper($application['status']); ?></span>
                        </div>
                        <p>
                            Applicant: <strong><?php echo sanitize($application['worker_name']); ?></strong>
                            <?php if ($application['worker_is_verified']): ?>
                                <span style="display:inline-flex;align-items:center;background:#22a06b;color:#fff;border-radius:4px;padding:0 5px;font-size:0.78rem;margin-left:4px;vertical-align:middle;"><strong>✓</strong>erified</span>
                            <?php endif; ?>
                            <?php if ($application['worker_is_featured']): ?>
                                <span class="badge" style="background:var(--primary);color:#fff;font-size:0.75rem;margin-left:4px;vertical-align:middle;">Featured</span>
                            <?php endif; ?>
                            (<?php echo sanitize($application['worker_email']); ?>)
                        </p>
                        <p class="meta">Job owner: <?php echo sanitize($application['customer_name']); ?> · Applied <?php echo sanitize(time_ago($application['applied_at'])); ?></p>
                        <div class="request-footer">
                            <a href="../worker_profile_public.php?id=<?php echo $application['worker_id']; ?>" class="button button-secondary button-small">View worker profile</a>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
