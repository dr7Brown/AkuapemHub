<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/chat_functions.php';

require_login();
$user = current_user();

if ($user['role'] === 'worker') {
    header('Location: worker_history.php');
    exit;
}

$focusJobId = intval($_GET['id'] ?? 0);

// ── POST: approve / reject ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

    if ($app && $app['status'] === 'pending') {
        if ($act === 'approve') {
            if ((int)$app['workers_approved'] >= (int)$app['workers_needed']) {
                flash('This job has reached its worker limit.', 'error');
            } else {
                $pdo->beginTransaction();
                try {
                    $pdo->prepare("UPDATE applications SET status='approved' WHERE id=?")->execute([$appId]);
                    $newStatus = update_job_staffing_status((int)$app['request_id']);

                    if ((int)$app['workers_needed'] === 1) {
                        $pdo->prepare("UPDATE service_requests SET assigned_worker_id=? WHERE id=?")->execute([$app['worker_id'], $app['request_id']]);
                    }

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

$jobStatusOptions = [
    ''                  => 'All statuses',
    'open'              => 'Open',
    'partially_staffed' => 'Partially Staffed',
    'fully_staffed'     => 'Fully Staffed',
    'in_progress'       => 'In Progress',
    'completed'         => 'Completed',
    'cancelled'         => 'Cancelled',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"/>
    <meta name="viewport" content="width=device-width,initial-scale=1.0"/>
    <title><?php echo $focusJobId ? 'Applicants — ' . sanitize($focusJob['title'] ?? '') : 'Manage Applicants'; ?> — AkuapemHub</title>
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
    </style>
</head>
<body class="has-bottom-nav">
<header class="app-topbar">
    <?php if ($focusJobId): ?>
        <a href="manage_applicants.php" class="button button-secondary button-small">← All Jobs</a>
    <?php else: ?>
        <a href="dashboard.php" class="button button-secondary button-small">← Dashboard</a>
    <?php endif; ?>
    <span class="brand">
        <?php echo $focusJobId ? '👥 Applicants' : '👥 Manage Applicants'; ?>
    </span>
</header>
<main class="page-shell">
    <?php foreach (get_flashes() as $f): ?>
        <div class="alert alert-<?php echo sanitize($f['type']); ?>"><?php echo sanitize($f['message']); ?></div>
    <?php endforeach; ?>

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
            <?php if ($isFullyStaffed): ?>
                <div class="fully-staffed-banner" style="margin-top:10px;margin-bottom:0;">✅ This job is fully staffed — all positions filled.</div>
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
</script>
</body>
</html>
