<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_login();
$user = current_user();

// ── Job Applications (workers only) ─────────────────────────────────────────
$myJobApps = [];
if (is_worker()) {
    $appStmt = $pdo->prepare("
        SELECT sr.id AS job_id, sr.title, sr.location, sr.budget, sr.budget_amount,
               a.id AS app_id, a.status AS app_status, a.applied_at,
               c.name AS category_name,
               u.name AS customer_name
        FROM applications a
        JOIN service_requests sr ON a.request_id = sr.id
        LEFT JOIN service_categories c ON sr.category_id = c.id
        LEFT JOIN users u ON sr.customer_id = u.id
        WHERE a.worker_id = ?
        ORDER BY a.applied_at DESC
        LIMIT 100
    ");
    $appStmt->execute([$user['id']]);
    $myJobApps = $appStmt->fetchAll();
}

// ── Funeral Announcements ────────────────────────────────────────────────────
$funeralStmt = $pdo->prepare("
    SELECT id, deceased_name, burial_date, status, created_at
    FROM funeral_announcements
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT 100
");
$funeralStmt->execute([$user['id']]);
$myFunerals = $funeralStmt->fetchAll();

// ── Events ───────────────────────────────────────────────────────────────────
$eventStmt = $pdo->prepare("
    SELECT id, title, venue, start_date, status, created_at
    FROM events
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT 100
");
$eventStmt->execute([$user['id']]);
$myEvents = $eventStmt->fetchAll();

// ── News Articles ─────────────────────────────────────────────────────────────
$newsStmt = $pdo->prepare("
    SELECT id, title, status, created_at
    FROM news
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT 100
");
$newsStmt->execute([$user['id']]);
$myNews = $newsStmt->fetchAll();

$notificationCount = get_unread_notifications_count((int)$user['id']);

// ── Job application stats for dashboard ─────────────────────────────────────
$jobAppStats = ['total' => 0, 'under_review' => 0, 'shortlisted' => 0, 'offered' => 0, 'approved' => 0, 'hired' => 0, 'rejected' => 0, 'expired' => 0];
if (is_worker() && $myJobApps) {
    foreach ($myJobApps as $a) {
        $jobAppStats['total']++;
        $s = $a['app_status'];
        if (isset($jobAppStats[$s])) $jobAppStats[$s]++;
    }
}

$activeTab = $_GET['tab'] ?? 'jobs';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>My Applications — AkuapemConnect</title>
    <link rel="stylesheet" href="assets/css/style.css" />
    <style>
        .tabs { display:flex; gap:0; border-bottom:2px solid var(--border,#e5e7eb); margin-bottom:20px; overflow-x:auto; }
        .tab-btn { flex-shrink:0; padding:10px 16px; font-weight:600; font-size:.88rem; color:var(--text-muted); border:none; background:none; cursor:pointer; border-bottom:3px solid transparent; margin-bottom:-2px; white-space:nowrap; }
        .tab-btn.active { color:var(--primary,#0f766e); border-bottom-color:var(--primary,#0f766e); }
        .tab-panel { display:none; }
        .tab-panel.active { display:block; }
        .app-badge { display:inline-block; padding:2px 8px; border-radius:12px; font-size:.75rem; font-weight:700; }
        .app-badge.pending { background:#fef9c3; color:#a16207; }
        .app-badge.under_review { background:#dbeafe; color:#1d4ed8; }
        .app-badge.shortlisted { background:#ede9fe; color:#6d28d9; }
        .app-badge.interview_scheduled { background:#fae8ff; color:#86198f; }
        .app-badge.offered { background:#d1fae5; color:#065f46; }
        .app-badge.approved,.app-badge.hired,.app-badge.accepted { background:#d1fae5; color:#065f46; }
        .app-badge.rejected,.app-badge.declined,.app-badge.position_filled { background:#fee2e2; color:#991b1b; }
        .app-badge.expired,.app-badge.withdrawn { background:#f3f4f6; color:#6b7280; }
        .app-badge.draft    { background:#f3f4f6; color:#6b7280; }
        .app-badge.published,.app-badge.active { background:#d1fae5; color:#065f46; }
        .app-badge.cancelled { background:#f3f4f6; color:#6b7280; }
        .stat-strip { display:grid; grid-template-columns:repeat(auto-fit,minmax(90px,1fr)); gap:8px; margin-bottom:20px; }
        .stat-chip { background:var(--surface,#fff); border:1px solid var(--border,#e5e7eb); border-radius:10px; padding:10px 8px; text-align:center; }
        .stat-chip .n { font-size:1.4rem; font-weight:700; color:var(--primary,#0f766e); display:block; }
        .stat-chip .l { font-size:0.7rem; color:var(--muted,#6b7280); }
        .stat-chip.blue .n { color:#1d4ed8; }
        .stat-chip.purple .n { color:#6d28d9; }
        .stat-chip.green .n { color:#16a34a; }
        .stat-chip.red .n { color:#dc2626; }
        .stat-chip.grey .n { color:#6b7280; }
        .app-row { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; padding:14px 0; border-bottom:1px solid var(--border,#e5e7eb); flex-wrap:wrap; }
        .app-row:last-child { border-bottom:none; }
        .app-info h3 { margin:0 0 3px; font-size:.95rem; }
        .app-info .meta { margin:0; }
    </style>
</head>
<body class="has-bottom-nav">
    <header class="app-topbar">
        <span class="brand"><span class="brand-icon">📋</span> My Applications</span>
        <div style="display:flex;gap:8px;align-items:center;">
            <?php if ($notificationCount > 0): ?>
                <a href="notifications.php" class="button button-secondary button-small" style="position:relative;">
                    🔔 <span style="position:absolute;top:-4px;right:-4px;background:var(--error,#ef4444);color:#fff;border-radius:50%;width:16px;height:16px;font-size:10px;display:flex;align-items:center;justify-content:center;"><?php echo (int)$notificationCount; ?></span>
                </a>
            <?php endif; ?>
        </div>
    </header>

    <main class="page-shell">
        <div class="tabs">
            <?php
            $tabs = [
                'jobs'    => ['label' => '💼 Jobs', 'count' => count($myJobApps)],
                'funeral' => ['label' => '⚰️ Funerals', 'count' => count($myFunerals)],
                'events'  => ['label' => '🎉 Events', 'count' => count($myEvents)],
                'news'    => ['label' => '📰 News', 'count' => count($myNews)],
            ];
            foreach ($tabs as $key => $tab):
            ?>
                <button class="tab-btn <?php echo $activeTab === $key ? 'active' : ''; ?>"
                        onclick="switchTab('<?php echo $key; ?>')">
                    <?php echo $tab['label']; ?>
                    <?php if ($tab['count'] > 0): ?>
                        <span style="background:var(--primary-soft,#ccfbf1);color:var(--primary,#0f766e);border-radius:10px;padding:1px 7px;font-size:.75rem;margin-left:4px;"><?php echo $tab['count']; ?></span>
                    <?php endif; ?>
                </button>
            <?php endforeach; ?>
        </div>

        <!-- ── Job Applications ─────────────────────────────────────────── -->
        <div class="tab-panel <?php echo $activeTab === 'jobs' ? 'active' : ''; ?>" id="tab-jobs">
            <?php if (!is_worker()): ?>
                <div class="empty-state">
                    <p>Job applications are for workers.</p>
                    <a href="become_worker.php" class="button button-primary">Become a Worker →</a>
                </div>
            <?php elseif (!$myJobApps): ?>
                <div class="empty-state">You haven't applied for any jobs yet. <a href="jobs.php">Browse open jobs →</a></div>
            <?php else: ?>

                <?php if ($jobAppStats['total'] > 0): ?>
                <div class="stat-strip">
                    <div class="stat-chip">
                        <span class="n"><?php echo $jobAppStats['total']; ?></span>
                        <div class="l">Total</div>
                    </div>
                    <?php if ($jobAppStats['under_review']): ?>
                    <div class="stat-chip blue">
                        <span class="n"><?php echo $jobAppStats['under_review']; ?></span>
                        <div class="l">Under Review</div>
                    </div>
                    <?php endif; ?>
                    <?php if ($jobAppStats['shortlisted']): ?>
                    <div class="stat-chip purple">
                        <span class="n"><?php echo $jobAppStats['shortlisted']; ?></span>
                        <div class="l">Shortlisted</div>
                    </div>
                    <?php endif; ?>
                    <?php if ($jobAppStats['offered'] || $jobAppStats['approved'] || $jobAppStats['hired']): ?>
                    <div class="stat-chip green">
                        <span class="n"><?php echo $jobAppStats['offered'] + $jobAppStats['approved'] + $jobAppStats['hired']; ?></span>
                        <div class="l">Offered/Hired</div>
                    </div>
                    <?php endif; ?>
                    <?php if ($jobAppStats['rejected']): ?>
                    <div class="stat-chip red">
                        <span class="n"><?php echo $jobAppStats['rejected']; ?></span>
                        <div class="l">Rejected</div>
                    </div>
                    <?php endif; ?>
                    <?php if ($jobAppStats['expired']): ?>
                    <div class="stat-chip grey">
                        <span class="n"><?php echo $jobAppStats['expired']; ?></span>
                        <div class="l">Expired</div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <div class="panel">
                <?php
                $groupOrder = ['offered','shortlisted','interview_scheduled','under_review','approved','hired','pending','rejected','position_filled','expired','withdrawn'];
                $jobStatusGroups = [];
                foreach ($myJobApps as $app) {
                    $s = $app['app_status'];
                    $jobStatusGroups[$s][] = $app;
                }
                $groupLabels = [
                    'offered'              => '🎉 Offered',
                    'shortlisted'          => '⭐ Shortlisted',
                    'interview_scheduled'  => '📅 Interview Scheduled',
                    'under_review'         => '🔍 Under Review',
                    'approved'             => '✅ Approved',
                    'hired'                => '🏆 Hired',
                    'pending'              => '⏳ Pending',
                    'rejected'             => '❌ Rejected',
                    'position_filled'      => '🔒 Position Filled',
                    'expired'              => '⌛ Expired',
                    'withdrawn'            => '↩ Withdrawn',
                ];
                foreach ($groupOrder as $status):
                    if (empty($jobStatusGroups[$status])) continue;
                    $label = $groupLabels[$status] ?? ucfirst(str_replace('_', ' ', $status));
                ?>
                    <h3 style="font-size:.9rem;color:var(--muted,#6b7280);margin:16px 0 8px;text-transform:uppercase;letter-spacing:.05em;"><?php echo $label; ?></h3>
                    <?php foreach ($jobStatusGroups[$status] as $app): ?>
                    <div class="app-row">
                        <div class="app-info">
                            <h3><?php echo sanitize($app['title']); ?></h3>
                            <p class="meta"><?php echo sanitize($app['category_name']); ?><?php if ($app['location']): ?> · <?php echo sanitize($app['location']); ?><?php endif; ?> · <?php echo date('d M Y', strtotime($app['applied_at'])); ?></p>
                            <?php if ($app['budget'] || $app['budget_amount']): ?>
                            <p class="meta">GH₵ <?php echo sanitize($app['budget'] ?: number_format((float)$app['budget_amount'], 2)); ?></p>
                            <?php endif; ?>
                        </div>
                        <div style="display:flex;flex-direction:column;align-items:flex-end;gap:6px;flex-shrink:0;">
                            <span class="app-badge <?php echo sanitize($app['app_status']); ?>"><?php echo strtoupper(str_replace('_', ' ', $app['app_status'])); ?></span>
                            <a href="request_detail.php?id=<?php echo (int)$app['job_id']; ?>" class="button button-small">View Job</a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- ── Funeral Announcements ────────────────────────────────────── -->
        <div class="tab-panel <?php echo $activeTab === 'funeral' ? 'active' : ''; ?>" id="tab-funeral">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                <span></span>
                <a href="my_funerals.php" class="button button-primary button-small">+ New Announcement</a>
            </div>
            <?php if (!$myFunerals): ?>
                <div class="empty-state">You have no funeral announcements yet.</div>
            <?php else: ?>
                <div class="panel">
                <?php foreach ($myFunerals as $fa): ?>
                <div class="app-row">
                    <div class="app-info">
                        <h3><?php echo sanitize($fa['deceased_name']); ?></h3>
                        <p class="meta">Submitted <?php echo date('d M Y', strtotime($fa['created_at'])); ?><?php if ($fa['burial_date']): ?> · Burial: <?php echo date('d M Y', strtotime($fa['burial_date'])); ?><?php endif; ?></p>
                    </div>
                    <div style="display:flex;flex-direction:column;align-items:flex-end;gap:6px;flex-shrink:0;">
                        <span class="app-badge <?php echo sanitize($fa['status']); ?>"><?php echo strtoupper(str_replace('_', ' ', $fa['status'])); ?></span>
                        <a href="my_funerals.php" class="button button-small">Manage</a>
                    </div>
                </div>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- ── Events ───────────────────────────────────────────────────── -->
        <div class="tab-panel <?php echo $activeTab === 'events' ? 'active' : ''; ?>" id="tab-events">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                <span></span>
                <a href="my_events.php" class="button button-primary button-small">+ New Event</a>
            </div>
            <?php if (!$myEvents): ?>
                <div class="empty-state">You have no event submissions yet.</div>
            <?php else: ?>
                <div class="panel">
                <?php foreach ($myEvents as $ev): ?>
                <div class="app-row">
                    <div class="app-info">
                        <h3><?php echo sanitize($ev['title']); ?></h3>
                        <p class="meta"><?php if ($ev['venue']): ?><?php echo sanitize($ev['venue']); ?><?php if ($ev['start_date']): ?> · <?php endif; ?><?php endif; ?><?php if ($ev['start_date']): ?><?php echo date('d M Y', strtotime($ev['start_date'])); ?><?php endif; ?></p>
                        <p class="meta">Submitted <?php echo date('d M Y', strtotime($ev['created_at'])); ?></p>
                    </div>
                    <div style="display:flex;flex-direction:column;align-items:flex-end;gap:6px;flex-shrink:0;">
                        <span class="app-badge <?php echo sanitize($ev['status']); ?>"><?php echo strtoupper(str_replace('_', ' ', $ev['status'])); ?></span>
                        <a href="my_events.php" class="button button-small">Manage</a>
                    </div>
                </div>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- ── News Articles ────────────────────────────────────────────── -->
        <div class="tab-panel <?php echo $activeTab === 'news' ? 'active' : ''; ?>" id="tab-news">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                <span></span>
                <a href="my_news.php" class="button button-primary button-small">+ New Article</a>
            </div>
            <?php if (!$myNews): ?>
                <div class="empty-state">You have no news article submissions yet.</div>
            <?php else: ?>
                <div class="panel">
                <?php foreach ($myNews as $art): ?>
                <div class="app-row">
                    <div class="app-info">
                        <h3><?php echo sanitize($art['title']); ?></h3>
                        <p class="meta">Submitted <?php echo date('d M Y', strtotime($art['created_at'])); ?></p>
                    </div>
                    <div style="display:flex;flex-direction:column;align-items:flex-end;gap:6px;flex-shrink:0;">
                        <span class="app-badge <?php echo sanitize($art['status']); ?>"><?php echo strtoupper($art['status']); ?></span>
                        <a href="my_news.php" class="button button-small">Manage</a>
                    </div>
                </div>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <?php $activeNav = 'myapps'; require __DIR__ . '/partials/bottom_nav.php'; ?>

    <script>
    function switchTab(key) {
        document.querySelectorAll('.tab-btn').forEach(function(b) { b.classList.remove('active'); });
        document.querySelectorAll('.tab-panel').forEach(function(p) { p.classList.remove('active'); });
        document.querySelector('[onclick="switchTab(\'' + key + '\')"]').classList.add('active');
        document.getElementById('tab-' + key).classList.add('active');
        history.replaceState(null, '', '?tab=' + key);
    }
    </script>
</body>
</html>
