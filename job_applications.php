<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/chat_functions.php';

require_login();
$user = current_user();

// Workers see their own applications; customers see applications for their jobs
$isWorker = $user['role'] === 'worker';

// ── POST: job owner approve / reject ─────────────────────────────────────────
if (!$isWorker && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $act   = $_POST['action'] ?? '';
    $appId = intval($_POST['application_id'] ?? 0);

    $appStmt = $pdo->prepare("
        SELECT a.*, sr.title AS job_title, sr.customer_id, sr.workers_needed, sr.workers_approved,
               sr.status AS job_status, w.name AS worker_name, w.email AS worker_email
        FROM applications a
        JOIN service_requests sr ON a.request_id = sr.id
        JOIN users w ON a.worker_id = w.id
        WHERE a.id = ? AND sr.customer_id = ?
    ");
    $appStmt->execute([$appId, $user['id']]);
    $app = $appStmt->fetch();

    if ($app && $app['status'] === 'pending') {
        if ($act === 'approve') {
            if ((int)$app['workers_approved'] >= (int)$app['workers_needed']) {
                flash('This job has reached its worker limit.', 'error');
            } else {
                $pdo->beginTransaction();
                try {
                    $pdo->prepare("UPDATE applications SET status='approved' WHERE id=?")->execute([$appId]);
                    $newStatus = update_job_staffing_status((int)$app['request_id']);

                    // For single-worker jobs, also set assigned_worker_id so complete flow works
                    if ((int)$app['workers_needed'] === 1) {
                        $pdo->prepare("UPDATE service_requests SET assigned_worker_id=? WHERE id=?")->execute([$app['worker_id'], $app['request_id']]);
                    }

                    // Auto-create chat between job owner and approved worker
                    get_or_create_conversation($user['id'], (int)$app['worker_id'], 'job_hired', (int)$app['request_id']);

                    $pdo->commit();

                    notify_user((int)$app['worker_id'], 'Application approved',
                        "Your application for '{$app['job_title']}' was approved by the job owner.", 'success');

                    if ($newStatus === 'fully_staffed') {
                        notify_user($user['id'], 'Job fully staffed',
                            "Your job '{$app['job_title']}' is now fully staffed.", 'success');
                    }

                    flash('Application approved.' . ($newStatus === 'fully_staffed' ? ' Job is now fully staffed.' : ''));
                } catch (Exception $e) {
                    $pdo->rollBack();
                    flash('Unable to approve. Please try again.', 'error');
                }
            }
        } elseif ($act === 'reject') {
            $pdo->prepare("UPDATE applications SET status='rejected' WHERE id=?")->execute([$appId]);
            notify_user((int)$app['worker_id'], 'Application not accepted',
                "Your application for '{$app['job_title']}' was not selected this time.", 'warning');
            flash('Application rejected.');
        }
    }

    header('Location: job_applications.php');
    exit;
}

// ── Data ───────────────────────────────────────────────────────────────────────
if ($isWorker) {
    // Worker: their own application history grouped by status
    $appStmt = $pdo->prepare("
        SELECT a.*, sr.title AS job_title, sr.status AS job_status, sr.location, sr.budget,
               sr.customer_id, u.name AS customer_name
        FROM applications a
        JOIN service_requests sr ON a.request_id = sr.id
        JOIN users u ON sr.customer_id = u.id
        WHERE a.worker_id = ?
        ORDER BY FIELD(a.status,'pending','approved','rejected','withdrawn','completed') ASC, a.applied_at DESC
    ");
    $appStmt->execute([$user['id']]);
    $myApplications = $appStmt->fetchAll();

} else {
    // Customer/job-owner: their jobs with applicants
    $jobsStmt = $pdo->prepare("
        SELECT sr.id, sr.title, sr.status, sr.workers_needed, sr.workers_approved, sr.created_at
        FROM service_requests sr
        WHERE sr.customer_id = ? AND sr.status NOT IN ('pending','cancelled')
        ORDER BY sr.created_at DESC
    ");
    $jobsStmt->execute([$user['id']]);
    $myJobs = $jobsStmt->fetchAll();

    $jobIds = array_column($myJobs, 'id');
    $jobApplications = [];
    if ($jobIds) {
        $placeholders = implode(',', array_fill(0, count($jobIds), '?'));
        $appsStmt = $pdo->prepare("
            SELECT a.*, u.name AS worker_name, u.email AS worker_email,
                   wp.is_verified, wp.is_featured, wp.bio, wp.location AS worker_location
            FROM applications a
            JOIN users u ON a.worker_id = u.id
            LEFT JOIN worker_profiles wp ON u.id = wp.user_id
            WHERE a.request_id IN ($placeholders)
            ORDER BY FIELD(a.status,'pending','approved','rejected') ASC, a.applied_at ASC
        ");
        $appsStmt->execute($jobIds);
        foreach ($appsStmt->fetchAll() as $app) {
            $jobApplications[$app['request_id']][] = $app;
        }
    }
}

$statusLabels = [
    'pending'   => ['label' => 'Pending', 'class' => 'status-pending'],
    'approved'  => ['label' => 'Approved', 'class' => 'status-completed'],
    'rejected'  => ['label' => 'Rejected', 'class' => 'status-cancelled'],
    'withdrawn' => ['label' => 'Withdrawn', 'class' => 'status-cancelled'],
    'completed' => ['label' => 'Completed', 'class' => 'status-completed'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"/>
    <meta name="viewport" content="width=device-width,initial-scale=1.0"/>
    <title><?php echo $isWorker ? 'My Applications' : 'Manage Applicants'; ?> — AkuapemHub</title>
    <link rel="stylesheet" href="assets/css/style.css"/>
    <style>
        .staffing-bar { display:flex; gap:16px; padding:10px 0; font-size:0.88rem; flex-wrap:wrap; }
        .staffing-bar span { font-weight:600; }
        .applicant-card { border:1px solid var(--border); border-radius:10px; padding:14px; margin-bottom:10px; }
        .applicant-head { display:flex; justify-content:space-between; align-items:flex-start; gap:8px; flex-wrap:wrap; }
        .fully-staffed-banner { background:#d1fae5; border:1px solid #6ee7b7; border-radius:8px; padding:10px 14px; color:#065f46; font-weight:600; margin-bottom:12px; }
    </style>
</head>
<body class="has-bottom-nav">
<header class="app-topbar">
    <a href="dashboard.php" class="button button-secondary button-small">← Back</a>
    <span class="brand"><?php echo $isWorker ? '📋 My Applications' : '👥 Manage Applicants'; ?></span>
</header>
<main class="page-shell">
    <?php foreach (get_flashes() as $f): ?>
        <div class="alert alert-<?php echo sanitize($f['type']); ?>"><?php echo sanitize($f['message']); ?></div>
    <?php endforeach; ?>

    <?php if ($isWorker): ?>
        <!-- Worker view -->
        <?php if (empty($myApplications)): ?>
            <div class="empty-state">You haven't applied to any jobs yet.</div>
        <?php else: ?>
            <?php
            $grouped = ['pending'=>[], 'approved'=>[], 'rejected'=>[], 'withdrawn'=>[], 'completed'=>[]];
            foreach ($myApplications as $a) { $grouped[$a['status']][] = $a; }
            $order = ['pending','approved','rejected','completed','withdrawn'];
            ?>
            <?php foreach ($order as $statusKey): ?>
                <?php if (empty($grouped[$statusKey])) continue; ?>
                <section class="panel">
                    <h2 style="margin-top:0;"><?php echo $statusLabels[$statusKey]['label'] ?? ucfirst($statusKey); ?> (<?php echo count($grouped[$statusKey]); ?>)</h2>
                    <?php foreach ($grouped[$statusKey] as $a): ?>
                        <article class="request-card">
                            <div class="request-head">
                                <div>
                                    <h2><a href="request_detail.php?id=<?php echo $a['request_id']; ?>" style="color:inherit;text-decoration:none;"><?php echo sanitize($a['job_title']); ?></a></h2>
                                    <p class="meta"><?php echo sanitize($a['location']); ?> · GH₵ <?php echo sanitize($a['budget']); ?></p>
                                </div>
                                <span class="status <?php echo $statusLabels[$statusKey]['class'] ?? ''; ?>"><?php echo strtoupper($statusLabels[$statusKey]['label'] ?? $statusKey); ?></span>
                            </div>
                            <p class="meta">Applied <?php echo sanitize(time_ago($a['applied_at'])); ?></p>
                            <?php if ($statusKey === 'approved'): ?>
                                <div style="margin-top:8px;">
                                    <a href="chat_start.php?user_id=<?php echo $a['customer_id']; ?>&job_id=<?php echo $a['request_id']; ?>" class="button button-primary button-small">💬 Message Job Owner</a>
                                </div>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </section>
            <?php endforeach; ?>
        <?php endif; ?>

    <?php else: ?>
        <!-- Job owner view -->
        <?php if (empty($myJobs)): ?>
            <div class="empty-state">You have no active jobs with applications yet.</div>
        <?php else: ?>
            <?php foreach ($myJobs as $job): ?>
                <?php
                $remaining = max(0, (int)$job['workers_needed'] - (int)$job['workers_approved']);
                $apps      = $jobApplications[$job['id']] ?? [];
                $pending   = array_filter($apps, fn($a) => $a['status'] === 'pending');
                $isFullyStaffed = $job['status'] === 'fully_staffed';
                ?>
                <section class="panel">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:8px;">
                        <div>
                            <h2 style="margin:0;"><a href="request_detail.php?id=<?php echo $job['id']; ?>" style="color:inherit;text-decoration:none;"><?php echo sanitize($job['title']); ?></a></h2>
                            <div class="staffing-bar">
                                <span>Need: <?php echo (int)$job['workers_needed']; ?></span>
                                <span style="color:#22a06b;">Approved: <?php echo (int)$job['workers_approved']; ?></span>
                                <span style="color:<?php echo $remaining > 0 ? 'var(--primary)' : '#22a06b'; ?>;">Remaining: <?php echo $remaining; ?></span>
                                <?php if (count($pending) > 0): ?>
                                    <span style="color:#f59e0b;">Pending: <?php echo count($pending); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <span class="status status-<?php echo sanitize($job['status']); ?>"><?php echo strtoupper(str_replace('_',' ',$job['status'])); ?></span>
                    </div>

                    <?php if ($isFullyStaffed): ?>
                        <div class="fully-staffed-banner">✅ This job is fully staffed — all positions filled.</div>
                    <?php endif; ?>

                    <?php if (empty($apps)): ?>
                        <div class="empty-state" style="padding:12px 0;">No applications yet.</div>
                    <?php else: ?>
                        <?php foreach ($apps as $app): ?>
                            <div class="applicant-card">
                                <div class="applicant-head">
                                    <div>
                                        <strong><?php echo sanitize($app['worker_name']); ?></strong>
                                        <?php if ($app['is_verified']): ?>
                                            <span style="display:inline-flex;align-items:center;background:#22a06b;color:#fff;border-radius:4px;padding:0 6px;font-size:0.76rem;margin-left:4px;">✓ Verified</span>
                                        <?php endif; ?>
                                        <?php if ($app['is_featured']): ?>
                                            <span style="display:inline-flex;align-items:center;background:var(--primary);color:#fff;border-radius:4px;padding:0 6px;font-size:0.76rem;margin-left:4px;">⭐ Featured</span>
                                        <?php endif; ?>
                                        <?php if ($app['worker_location']): ?>
                                            <p class="meta" style="margin:2px 0 0;"><?php echo sanitize($app['worker_location']); ?></p>
                                        <?php endif; ?>
                                        <p class="meta" style="margin:2px 0 0;">Applied <?php echo sanitize(time_ago($app['applied_at'])); ?></p>
                                    </div>
                                    <span class="status <?php echo $statusLabels[$app['status']]['class'] ?? ''; ?>"><?php echo strtoupper($statusLabels[$app['status']]['label'] ?? $app['status']); ?></span>
                                </div>
                                <?php if (!empty($app['bio'])): ?>
                                    <p style="font-size:0.85rem;margin:8px 0 4px;color:var(--text-muted);"><?php echo sanitize(mb_substr($app['bio'], 0, 120)) . (mb_strlen($app['bio']) > 120 ? '…' : ''); ?></p>
                                <?php endif; ?>
                                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;">
                                    <a href="worker_profile_public.php?id=<?php echo $app['worker_id']; ?>" class="button button-secondary button-small">View Profile</a>
                                    <a href="chat_start.php?user_id=<?php echo $app['worker_id']; ?>&job_id=<?php echo $job['id']; ?>" class="button button-secondary button-small">💬 Message</a>
                                    <?php if ($app['status'] === 'pending' && !$isFullyStaffed): ?>
                                        <form method="post" style="display:contents;">
                                            <input type="hidden" name="application_id" value="<?php echo $app['id']; ?>">
                                            <button name="action" value="approve" class="button button-primary button-small">✓ Approve</button>
                                            <button name="action" value="reject" class="button button-secondary button-small" onclick="return confirm('Reject this applicant?')">✗ Reject</button>
                                        </form>
                                    <?php elseif ($app['status'] === 'pending' && $isFullyStaffed): ?>
                                        <span class="button button-secondary button-small" style="opacity:0.5;cursor:default;">Job full</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php endif; ?>
</main>
<?php $activeNav = 'jobs'; require __DIR__ . '/partials/bottom_nav.php'; ?>
</body>
</html>
