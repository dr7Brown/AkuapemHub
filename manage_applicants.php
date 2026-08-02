<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/chat_functions.php';

require_module_enabled('jobs', 'Jobs & Services');
require_login();
require_not_banned_from('jobs');
$user = current_user();

if ($user['role'] === 'worker') {
    header('Location: worker_history.php');
    exit;
}

$focusJobId = intval($_GET['id'] ?? 0);

// ── POST: approve / reject ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act      = $_POST['action'] ?? '';
    $appId    = intval($_POST['application_id'] ?? 0);
    $returnId = intval($_POST['return_id'] ?? 0);

    $appStmt = $pdo->prepare("
        SELECT a.*, sr.title AS job_title, sr.customer_id, sr.workers_needed, sr.workers_approved,
               sr.status AS job_status, w.name AS worker_name, w.email AS worker_email
        FROM applications a
        JOIN service_requests sr ON a.request_id = sr.id
        JOIN users w ON a.worker_id = w.id
        WHERE a.id = ? AND (sr.customer_id = ? OR ? = 1)
    ");
    $appStmt->execute([$appId, $user['id'], is_admin() ? 1 : 0]);
    $app = $appStmt->fetch();

    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    $newAppStatus = null;
    $newJobStatus = null;
    $workersApproved = (int)($app['workers_approved'] ?? 0);
    $errMsg = null;

    // ── mark_hiring_completed (no application needed) ────────────────────────
    if ($act === 'mark_hiring_completed') {
        $reqId = intval($_POST['request_id'] ?? 0);
        if (mark_hiring_completed($reqId, $user['id'])) {
            if (!$isAjax) flash('Job marked as Hiring Completed. Remaining applicants notified.');
        } else {
            $errMsg = 'Could not mark hiring as completed.';
            if (!$isAjax) flash($errMsg, 'error');
        }
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($errMsg ? ['error' => $errMsg] : ['ok' => true, 'hiring_completed' => true]);
            exit;
        }
        $redirect = 'manage_applicants.php';
        if ($reqId) $redirect .= '?id=' . $reqId;
        header('Location: ' . $redirect);
        exit;
    }

    // ── update_status (intermediate status changes by employer) ─────────────
    if ($app && $act === 'update_status') {
        $newStatus = $_POST['new_status'] ?? '';
        $allowed   = ['under_review','shortlisted','interview_scheduled','offered','rejected'];
        if (!in_array($newStatus, $allowed, true)) {
            $errMsg = 'Invalid status.';
        } else {
            $oldStatus = $app['status'];
            $pdo->prepare("UPDATE applications SET status = ? WHERE id = ?")
                ->execute([$newStatus, $appId]);
            log_application_status_change($appId, $oldStatus, $newStatus, $user['id'], 'Employer updated status');

            $msgs = [
                'under_review'        => "Your application for \"{$app['job_title']}\" is now under review.",
                'shortlisted'         => "Great news! You've been shortlisted for \"{$app['job_title']}\".",
                'interview_scheduled' => "An interview has been scheduled for your application to \"{$app['job_title']}\". Check your messages.",
                'offered'             => "Congratulations! You've received a job offer for \"{$app['job_title']}\".",
                'rejected'            => "We regret that your application for \"{$app['job_title']}\" was not selected.",
            ];
            $types = ['shortlisted' => 'success', 'interview_scheduled' => 'success', 'offered' => 'success',
                      'rejected' => 'warning'];
            notify_user((int)$app['worker_id'], 'Application Status Update',
                $msgs[$newStatus] ?? "Your application status for \"{$app['job_title']}\" has been updated.",
                $types[$newStatus] ?? 'info');

            $newAppStatus = $newStatus;
            if (!$isAjax) flash('Status updated to ' . str_replace('_', ' ', $newStatus) . '.');
        }
        if ($errMsg && !$isAjax) flash($errMsg, 'error');
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($errMsg ? ['error' => $errMsg] : [
                'ok' => true, 'application_id' => $appId, 'new_status' => $newAppStatus,
            ]);
            exit;
        }
        $redirect = 'manage_applicants.php';
        if ((int)($_POST['return_id'] ?? 0)) $redirect .= '?id=' . (int)$_POST['return_id'];
        header('Location: ' . $redirect);
        exit;
    }

    if ($app && $app['status'] === 'pending') {
        if ($act === 'approve') {
            if ((int)$app['workers_approved'] >= (int)$app['workers_needed']) {
                $errMsg = 'This job has reached its worker limit.';
                if (!$isAjax) flash($errMsg, 'error');
            } else {
                $pdo->beginTransaction();
                try {
                    $pdo->prepare("UPDATE applications SET status='approved' WHERE id=?")->execute([$appId]);
                    $newJobStatus = update_job_staffing_status((int)$app['request_id']);

                    if ((int)$app['workers_needed'] === 1) {
                        $pdo->prepare("UPDATE service_requests SET assigned_worker_id=? WHERE id=?")->execute([$app['worker_id'], $app['request_id']]);
                    }

                    get_or_create_conversation($user['id'], (int)$app['worker_id'], 'job_hired', (int)$app['request_id']);
                    $pdo->commit();

                    require_once __DIR__ . '/modules/referrals/service.php';
                    award_points((int)$app['customer_id'], 'hire_worker', (int)$app['request_id']);

                    notify_user((int)$app['worker_id'], 'Application approved',
                        "Your application for '{$app['job_title']}' was approved by the job owner.", 'success');

                    if ($newJobStatus === 'fully_staffed') {
                        notify_user($user['id'], 'Job fully staffed',
                            "Your job '{$app['job_title']}' is now fully staffed.", 'success');
                    }

                    $newAppStatus = 'approved';
                    // Re-fetch updated workers_approved count
                    $updatedJob = $pdo->prepare("SELECT workers_approved, workers_needed FROM service_requests WHERE id=?");
                    $updatedJob->execute([$app['request_id']]);
                    $updatedJob = $updatedJob->fetch();
                    $workersApproved = (int)$updatedJob['workers_approved'];

                    if (!$isAjax) flash('Application approved.' . ($newJobStatus === 'fully_staffed' ? ' Job is now fully staffed.' : ''));
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $errMsg = 'Unable to approve. Please try again.';
                    if (!$isAjax) flash($errMsg, 'error');
                }
            }
        } elseif ($act === 'reject') {
            $pdo->prepare("UPDATE applications SET status='rejected' WHERE id=?")->execute([$appId]);
            notify_user((int)$app['worker_id'], 'Application not accepted',
                "Your application for '{$app['job_title']}' was not selected this time.", 'warning');
            $newAppStatus = 'rejected';
            if (!$isAjax) flash('Application rejected.');
        }
    }

    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        if ($errMsg) {
            echo json_encode(['error' => $errMsg]);
        } else {
            echo json_encode([
                'ok'               => true,
                'application_id'   => $appId,
                'new_status'       => $newAppStatus,
                'job_status'       => $newJobStatus ?? $app['job_status'],
                'workers_approved' => $workersApproved,
                'workers_needed'   => (int)$app['workers_needed'],
                'worker_id'        => (int)$app['worker_id'],
                'request_id'       => (int)$app['request_id'],
            ]);
        }
        exit;
    }

    $redirect = 'manage_applicants.php';
    if ($returnId) $redirect .= '?id=' . $returnId;
    header('Location: ' . $redirect);
    exit;
}

// ── Data ──────────────────────────────────────────────────────────────────────
if ($focusJobId) {
    $jobStmt = $pdo->prepare("SELECT * FROM service_requests WHERE id = ? AND (customer_id = ? OR ? = 1)");
    $jobStmt->execute([$focusJobId, $user['id'], is_admin() ? 1 : 0]);
    $focusJob = $jobStmt->fetch();

    if (!$focusJob) {
        flash('Job not found.', 'error');
        header('Location: manage_applicants.php');
        exit;
    }

    $appsStmt = $pdo->prepare("
        SELECT a.*, u.name AS worker_name, u.email AS worker_email,
               wp.is_verified, wp.is_featured, wp.bio, wp.location AS worker_location
        FROM applications a
        JOIN users u ON a.worker_id = u.id
        LEFT JOIN worker_profiles wp ON u.id = wp.user_id
        WHERE a.request_id = ?
        ORDER BY FIELD(a.status,'pending','approved','rejected','withdrawn','completed') ASC, a.applied_at ASC
    ");
    $appsStmt->execute([$focusJobId]);
    $focusApps = $appsStmt->fetchAll();

} else {
    $searchQ = trim($_GET['q'] ?? '');
    $statusF = $_GET['status'] ?? '';

    $ownerWhere = is_admin() ? '' : ' AND sr.customer_id = ?';
    $params     = is_admin() ? [] : [$user['id']];
    $where      = ['1=1'];
    if (!is_admin()) $where[] = 'sr.customer_id = ?';
    $params     = is_admin() ? [] : [$user['id']];
    if ($statusF) { $where[] = 'sr.status = ?'; $params[] = $statusF; }
    if ($searchQ) { $where[] = 'sr.title LIKE ?'; $params[] = '%' . $searchQ . '%'; }

    $jobsStmt = $pdo->prepare("
        SELECT sr.id, sr.title, sr.status, sr.workers_needed, sr.workers_approved, sr.created_at,
               (SELECT COUNT(*) FROM applications a WHERE a.request_id = sr.id) AS total_apps,
               (SELECT COUNT(*) FROM applications a WHERE a.request_id = sr.id AND a.status = 'pending') AS pending_apps
        FROM service_requests sr
        WHERE " . implode(' AND ', $where) . "
        ORDER BY FIELD(sr.status,'open','partially_staffed','fully_staffed','in_progress','completed','cancelled','pending') ASC, sr.created_at DESC
    ");
    $jobsStmt->execute($params);
    $allJobs = $jobsStmt->fetchAll();

    $jobIds = array_column($allJobs, 'id');
    $allApplications = [];
    if ($jobIds) {
        $pl = implode(',', array_fill(0, count($jobIds), '?'));
        $appsStmt = $pdo->prepare("
            SELECT a.*, u.name AS worker_name, u.email AS worker_email,
                   wp.is_verified, wp.is_featured, wp.bio, wp.location AS worker_location
            FROM applications a
            JOIN users u ON a.worker_id = u.id
            LEFT JOIN worker_profiles wp ON u.id = wp.user_id
            WHERE a.request_id IN ($pl)
            ORDER BY FIELD(a.status,'pending','approved','rejected','withdrawn','completed') ASC, a.applied_at ASC
        ");
        $appsStmt->execute($jobIds);
        foreach ($appsStmt->fetchAll() as $app) {
            $allApplications[$app['request_id']][] = $app;
        }
    }
}

$statusLabels = [
    'pending'              => ['label' => 'Pending',              'class' => 'status-pending'],
    'under_review'         => ['label' => 'Under Review',         'class' => 'status-in_progress'],
    'shortlisted'          => ['label' => 'Shortlisted',          'class' => 'status-partially_staffed'],
    'interview_scheduled'  => ['label' => 'Interview Scheduled',  'class' => 'status-open'],
    'offered'              => ['label' => 'Offered',              'class' => 'status-fully_staffed'],
    'approved'             => ['label' => 'Approved',             'class' => 'status-fully_staffed'],
    'hired'                => ['label' => 'Hired',                'class' => 'status-completed'],
    'rejected'             => ['label' => 'Rejected',             'class' => 'status-cancelled'],
    'withdrawn'            => ['label' => 'Withdrawn',            'class' => 'status-cancelled'],
    'expired'              => ['label' => 'Expired',              'class' => 'status-cancelled'],
    'position_filled'      => ['label' => 'Position Filled',      'class' => 'status-cancelled'],
    'completed'            => ['label' => 'Completed',            'class' => 'status-completed'],
    'accepted'             => ['label' => 'Accepted',             'class' => 'status-completed'],
    'declined'             => ['label' => 'Declined',             'class' => 'status-cancelled'],
];

$jobStatusOptions = [
    ''                  => 'All statuses',
    'open'              => 'Open',
    'partially_staffed' => 'Partially Staffed',
    'fully_staffed'     => 'Fully Staffed',
    'in_progress'       => 'In Progress',
    'completed'         => 'Completed',
    'expired'           => 'Expired',
    'hiring_completed'  => 'Hiring Completed',
    'cancelled'         => 'Cancelled',
];

// ── Employer stats (list view only) ─────────────────────────────────────────
$employerStats = [];
if (!$focusJobId) {
    $statsStmt = $pdo->prepare("
        SELECT
          SUM(sr.status IN ('open','partially_staffed')) AS active_jobs,
          SUM(sr.status = 'expired')                     AS expired_jobs,
          SUM(sr.status = 'hiring_completed')            AS completed_jobs,
          SUM(a.status = 'pending')                      AS pending_decisions,
          SUM(sr.deadline_date IS NOT NULL
              AND sr.deadline_date > NOW()
              AND sr.deadline_date < DATE_ADD(NOW(), INTERVAL 7 DAY)
              AND sr.status IN ('open','partially_staffed')) AS expiring_soon
        FROM service_requests sr
        LEFT JOIN applications a ON a.request_id = sr.id
        WHERE " . (is_admin() ? '1=1' : 'sr.customer_id = ?') . "
    ");
    $statsStmt->execute(is_admin() ? [] : [$user['id']]);
    $employerStats = $statsStmt->fetch();
    $employerScore = get_employer_activity_score((int)$user['id']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"/>
    <meta name="viewport" content="width=device-width,initial-scale=1.0"/>
    <title><?php echo $focusJobId ? 'Applicants — ' . sanitize($focusJob['title'] ?? '') : 'Manage Applicants'; ?> — AkuapemConnect</title>
    <link rel="stylesheet" href="assets/css/style.css"/>
    <style>
        .staffing-bar { display:flex; gap:14px; padding:6px 0; font-size:0.85rem; flex-wrap:wrap; }
        .staffing-bar span { font-weight:600; }
        .fully-staffed-banner { background:#d1fae5; border:1px solid #6ee7b7; border-radius:8px; padding:10px 14px; color:#065f46; font-weight:600; margin-bottom:12px; }
        .job-tree-node { background:var(--surface); border:1px solid var(--border); border-radius:12px; margin-bottom:20px; overflow:hidden; }
        .job-tree-header { padding:14px 16px; display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:8px; border-bottom:1px solid var(--border); cursor:pointer; }
        .job-tree-header:hover { background:rgba(0,0,0,0.02); }
        .job-tree-body { padding:14px 16px; }
        .tree-connector { border-left:2px solid var(--border); margin-left:12px; padding-left:16px; }
        .filter-strip { display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; margin-bottom:18px; padding:14px 16px; background:var(--surface); border:1px solid var(--border); border-radius:10px; }
        .filter-strip input, .filter-strip select { padding:7px 10px; border:1px solid var(--border); border-radius:7px; font-size:0.87rem; }
        .filter-strip input { flex:1; min-width:140px; }
        .badge-count { background:var(--primary); color:#fff; border-radius:20px; padding:1px 8px; font-size:0.72rem; font-weight:700; }
        .job-focus-header { background:var(--surface); border:1px solid var(--border); border-radius:12px; padding:16px; margin-bottom:20px; }
        .emp-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(120px,1fr)); gap:10px; margin-bottom:20px; }
        .emp-stat-card { background:var(--surface,#fff); border:1px solid var(--border,#e5e7eb); border-radius:10px; padding:12px 14px; text-align:center; }
        .emp-stat-card .num { font-size:1.5rem; font-weight:700; color:var(--primary,#0f766e); display:block; }
        .emp-stat-card .lbl { font-size:0.73rem; color:var(--muted,#6b7280); margin-top:2px; }
        .emp-stat-card.warn .num { color:#d97706; }
        .emp-stat-card.danger .num { color:#dc2626; }
        .emp-stat-card.good .num { color:#16a34a; }
        .score-badge { display:inline-flex; align-items:center; gap:4px; padding:3px 10px; border-radius:20px; font-size:0.8rem; font-weight:700; }
        .score-badge.good { background:#d1fae5; color:#065f46; }
        .score-badge.ok   { background:#fef9c3; color:#92400e; }
        .score-badge.poor { background:#fee2e2; color:#991b1b; }
        .status-select { padding:4px 8px; border:1px solid var(--border,#e5e7eb); border-radius:6px; font-size:0.82rem; background:var(--surface,#fff); }
        .hiring-completed-banner { background:#f0f9ff; border:1px solid #bae6fd; border-radius:8px; padding:10px 14px; color:#0369a1; font-weight:600; margin-top:10px; }
    </style>
</head>
<body class="has-bottom-nav">
<header class="app-topbar">
    <?php if ($focusJobId): ?>
        <a href="manage_applicants.php" class="button button-secondary button-small">← All Jobs</a>
    <?php else: ?>
        <a href="jobs.php" class="button button-secondary button-small">← Dashboard</a>
    <?php endif; ?>
    <span class="brand">
        <?php echo $focusJobId ? '👥 Applicants' : '👥 Manage Applicants'; ?>
    </span>
</header>
<main class="page-shell">
    <?php foreach (get_flashes() as $f): ?>
        <div class="alert alert-<?php echo sanitize($f['type']); ?>"><?php echo sanitize($f['message']); ?></div>
    <?php endforeach; ?>

    <?php if (!$focusJobId && !empty($employerStats)): ?>
        <div class="emp-stats">
            <div class="emp-stat-card">
                <span class="num"><?php echo (int)($employerStats['active_jobs'] ?? 0); ?></span>
                <div class="lbl">Active Jobs</div>
            </div>
            <div class="emp-stat-card <?php echo (int)($employerStats['pending_decisions'] ?? 0) > 0 ? 'warn' : ''; ?>">
                <span class="num"><?php echo (int)($employerStats['pending_decisions'] ?? 0); ?></span>
                <div class="lbl">Pending Decisions</div>
            </div>
            <div class="emp-stat-card <?php echo (int)($employerStats['expiring_soon'] ?? 0) > 0 ? 'warn' : ''; ?>">
                <span class="num"><?php echo (int)($employerStats['expiring_soon'] ?? 0); ?></span>
                <div class="lbl">Expiring in 7 Days</div>
            </div>
            <div class="emp-stat-card good">
                <span class="num"><?php echo (int)($employerStats['completed_jobs'] ?? 0); ?></span>
                <div class="lbl">Hiring Completed</div>
            </div>
            <div class="emp-stat-card <?php echo (int)($employerStats['expired_jobs'] ?? 0) > 0 ? 'danger' : ''; ?>">
                <span class="num"><?php echo (int)($employerStats['expired_jobs'] ?? 0); ?></span>
                <div class="lbl">Expired</div>
            </div>
            <?php if (!is_admin()):
                $sc = $employerScore;
                $scoreClass = $sc >= 80 ? 'good' : ($sc >= 50 ? 'ok' : 'poor');
            ?>
            <div class="emp-stat-card">
                <span class="num"><span class="score-badge <?php echo $scoreClass; ?>"><?php echo $sc; ?></span></span>
                <div class="lbl">Activity Score</div>
            </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($focusJobId): ?>
        <?php
        $remaining      = max(0, (int)$focusJob['workers_needed'] - (int)$focusJob['workers_approved']);
        $isFullyStaffed = $focusJob['status'] === 'fully_staffed';
        $pendingCount   = count(array_filter($focusApps, fn($a) => $a['status'] === 'pending'));
        ?>
        <div class="job-focus-header">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:8px;">
                <div>
                    <h2 style="margin:0 0 4px;"><a href="request_detail.php?id=<?php echo $focusJob['id']; ?>" style="color:inherit;text-decoration:none;"><?php echo sanitize($focusJob['title']); ?></a></h2>
                    <div class="staffing-bar">
                        <span>Need: <?php echo (int)$focusJob['workers_needed']; ?></span>
                        <span style="color:#22a06b;">Approved: <?php echo (int)$focusJob['workers_approved']; ?></span>
                        <span style="color:<?php echo $remaining > 0 ? 'var(--primary)' : '#22a06b'; ?>;">Remaining: <?php echo $remaining; ?></span>
                        <?php if ($pendingCount > 0): ?>
                            <span style="color:#f59e0b;">Pending review: <?php echo $pendingCount; ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <span class="status status-<?php echo sanitize($focusJob['status']); ?>"><?php echo strtoupper(str_replace('_',' ',$focusJob['status'])); ?></span>
            </div>
            <?php if (!empty($focusJob['deadline_date'])): ?>
                <div style="font-size:0.82rem;color:var(--muted,#6b7280);margin-top:6px;">
                    ⏰ Deadline: <strong><?php echo date('d M Y', strtotime($focusJob['deadline_date'])); ?></strong>
                    <?php
                    $dlDiff = (strtotime($focusJob['deadline_date']) - time()) / 86400;
                    if ($dlDiff < 0): ?><span style="color:#dc2626;"> · expired</span>
                    <?php elseif ($dlDiff <= 3): ?><span style="color:#d97706;"> · <?php echo ceil($dlDiff); ?> day(s) left</span>
                    <?php elseif ($dlDiff <= 7): ?><span style="color:#ca8a04;"> · <?php echo ceil($dlDiff); ?> days left</span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <?php if ($isFullyStaffed): ?>
                <div class="fully-staffed-banner" style="margin-top:10px;margin-bottom:0;">✅ This job is fully staffed — all positions filled.</div>
            <?php endif; ?>
            <?php if ($focusJob['status'] === 'hiring_completed'): ?>
                <div class="hiring-completed-banner">🎉 Hiring completed — recruitment closed.</div>
            <?php elseif (in_array($focusJob['status'], ['open','partially_staffed','fully_staffed','in_progress'], true)): ?>
                <div style="margin-top:12px;">
                    <form method="post" style="display:inline;" onsubmit="return confirm('Mark hiring as completed? Remaining applicants will be notified that the position is filled.');">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="mark_hiring_completed">
                        <input type="hidden" name="request_id" value="<?php echo $focusJob['id']; ?>">
                        <button type="submit" class="button button-secondary button-small" style="border-color:#0369a1;color:#0369a1;">🎉 Mark Hiring Completed</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>

        <?php if (empty($focusApps)): ?>
            <div class="empty-state">No applications yet for this job.</div>
        <?php else: ?>
            <div class="tree-connector">
                <?php foreach ($focusApps as $app): ?>
                    <?php include __DIR__ . '/partials/_applicant_card.php'; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    <?php else: ?>
        <form method="get" class="filter-strip">
            <input type="text" name="q" value="<?php echo sanitize($searchQ ?? ''); ?>" placeholder="Search job title…">
            <select name="status">
                <?php foreach ($jobStatusOptions as $val => $label): ?>
                    <option value="<?php echo $val; ?>" <?php echo ($statusF ?? '') === $val ? 'selected' : ''; ?>><?php echo $label; ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="button button-primary button-small">Filter</button>
            <?php if (!empty($searchQ) || !empty($statusF)): ?>
                <a href="manage_applicants.php" class="button button-secondary button-small">Clear</a>
            <?php endif; ?>
        </form>

        <?php if (empty($allJobs)): ?>
            <div class="empty-state">No jobs match your filters.</div>
        <?php else: ?>
            <?php foreach ($allJobs as $job): ?>
                <?php
                $apps           = $allApplications[$job['id']] ?? [];
                $remaining      = max(0, (int)$job['workers_needed'] - (int)$job['workers_approved']);
                $isFullyStaffed = $job['status'] === 'fully_staffed';
                $pendingCnt     = (int)$job['pending_apps'];
                $totalCnt       = (int)$job['total_apps'];
                ?>
                <div class="job-tree-node">
                    <div class="job-tree-header" onclick="toggleTree(<?php echo $job['id']; ?>)">
                        <div style="flex:1;min-width:0;">
                            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                <strong style="font-size:1rem;"><?php echo sanitize($job['title']); ?></strong>
                                <?php if ($totalCnt > 0): ?>
                                    <span class="badge-count"><?php echo $totalCnt; ?> applicant<?php echo $totalCnt !== 1 ? 's' : ''; ?></span>
                                <?php endif; ?>
                                <?php if ($pendingCnt > 0): ?>
                                    <span style="background:#fef3c7;color:#92400e;border-radius:20px;padding:1px 8px;font-size:0.72rem;font-weight:700;"><?php echo $pendingCnt; ?> pending</span>
                                <?php endif; ?>
                            </div>
                            <div class="staffing-bar">
                                <span>Need: <?php echo (int)$job['workers_needed']; ?></span>
                                <span style="color:#22a06b;">Approved: <?php echo (int)$job['workers_approved']; ?></span>
                                <span>Remaining: <?php echo $remaining; ?></span>
                            </div>
                        </div>
                        <div style="display:flex;align-items:center;gap:8px;flex-shrink:0;">
                            <span class="status status-<?php echo sanitize($job['status']); ?>"><?php echo strtoupper(str_replace('_',' ',$job['status'])); ?></span>
                            <span style="color:var(--muted);" id="tree-arrow-<?php echo $job['id']; ?>">▼</span>
                        </div>
                    </div>
                    <div class="job-tree-body" id="tree-body-<?php echo $job['id']; ?>">
                        <div style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap;">
                            <a href="request_detail.php?id=<?php echo $job['id']; ?>" class="button button-secondary button-small">View Job</a>
                            <a href="manage_applicants.php?id=<?php echo $job['id']; ?>" class="button button-secondary button-small">Focus view →</a>
                        </div>
                        <?php if ($isFullyStaffed): ?>
                            <div class="fully-staffed-banner">✅ Fully staffed — all positions filled.</div>
                        <?php endif; ?>
                        <?php if (empty($apps)): ?>
                            <p class="meta" style="padding:8px 0;">No applications yet.</p>
                        <?php else: ?>
                            <div class="tree-connector">
                                <?php foreach ($apps as $app): ?>
                                    <?php include __DIR__ . '/partials/_applicant_card.php'; ?>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php endif; ?>
</main>
<?php $activeNav = 'jobs'; require __DIR__ . '/partials/bottom_nav.php'; ?>
<script>
function toggleTree(id) {
    var body  = document.getElementById('tree-body-' + id);
    var arrow = document.getElementById('tree-arrow-' + id);
    var open  = body.style.display !== 'none';
    body.style.display = open ? 'none' : 'block';
    arrow.textContent  = open ? '▶' : '▼';
}

var STATUS_LABELS = {
    pending: 'PENDING', under_review: 'UNDER REVIEW', shortlisted: 'SHORTLISTED',
    interview_scheduled: 'INTERVIEW SCHEDULED', offered: 'OFFERED', approved: 'APPROVED',
    hired: 'HIRED', rejected: 'REJECTED', withdrawn: 'WITHDRAWN',
    expired: 'EXPIRED', position_filled: 'POSITION FILLED'
};
var STATUS_CLASSES = {
    pending: 'status-pending', under_review: 'status-in_progress', shortlisted: 'status-partially_staffed',
    interview_scheduled: 'status-open', offered: 'status-fully_staffed', approved: 'status-fully_staffed',
    hired: 'status-completed', rejected: 'status-cancelled', withdrawn: 'status-cancelled',
    expired: 'status-cancelled', position_filled: 'status-cancelled'
};

// Ajax form handler: approve, reject, update_status
document.addEventListener('submit', function (e) {
    var form = e.target;

    // update_status: select + button with data-action="update_status"
    var actionInput = form.querySelector('input[name="action"]');
    var actionBtn   = form.querySelector('button[name="action"]');
    var act = (actionInput ? actionInput.value : null) || (actionBtn ? actionBtn.value : null);

    if (!act || !['approve','reject','update_status'].includes(act)) return;
    e.preventDefault();

    // update_status via select
    if (act === 'update_status') {
        var sel = form.querySelector('select[name="new_status"]');
        if (!sel || !sel.value) { alert('Please select a status.'); return; }
        var submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn) submitBtn.disabled = true;

        var body = new URLSearchParams(new FormData(form));
        fetch('manage_applicants.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
            body: body.toString()
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.error) { if (submitBtn) submitBtn.disabled = false; alert(data.error); return; }
            // Full reload so stat cards, staffing bars, and every other
            // applicant's card (not just this one) stay in sync.
            window.location.reload();
        })
        .catch(function () { if (submitBtn) submitBtn.disabled = false; });
        return;
    }

    // approve / reject
    if (!confirm(act === 'approve' ? 'Approve this worker?' : 'Reject this application?')) return;
    if (actionBtn) actionBtn.disabled = true;

    var body = new URLSearchParams(new FormData(form));
    body.set('action', act);

    fetch('manage_applicants.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: body.toString()
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        if (data.error) { alert(data.error); if (actionBtn) actionBtn.disabled = false; return; }
        // Full reload so stat cards, staffing bars, and every other
        // applicant's card (not just this one) stay in sync.
        window.location.reload();
    })
    .catch(function () { if (actionBtn) actionBtn.disabled = false; });
});
</script>
</body>
</html>
