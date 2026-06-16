<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';

require_login();
if (!is_admin_or_manager()) {
    header('Location: ../jobs.php');
    exit;
}

$filterStatus = $_GET['status'] ?? '';
$searchQ      = strtolower(trim($_GET['q'] ?? ''));

$where  = [];
$params = [];
if ($filterStatus) {
    $where[]  = 'a.status = ?';
    $params[] = $filterStatus;
}
$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("
    SELECT a.*,
           sr.title AS request_title, sr.status AS request_status,
           sr.location, sr.budget, sr.workers_needed, sr.workers_approved,
           sr.customer_id, sr.description AS request_desc,
           w.name AS worker_name, w.email AS worker_email, w.phone AS worker_phone,
           c.name AS customer_name,
           wp.is_verified AS worker_is_verified,
           wp.is_featured AS worker_is_featured,
           wp.availability AS worker_availability
    FROM applications a
    JOIN service_requests sr ON a.request_id = sr.id
    JOIN users w ON a.worker_id = w.id
    JOIN users c ON sr.customer_id = c.id
    LEFT JOIN worker_profiles wp ON w.id = wp.user_id
    $whereClause
    ORDER BY FIELD(a.status,'pending','approved','rejected') ASC, a.applied_at DESC
    LIMIT 500
");
$params ? $stmt->execute($params) : $stmt->execute();
$allApps = $stmt->fetchAll();

// Status counts (unfiltered)
$counts  = $pdo->query("SELECT status, COUNT(*) AS n FROM applications GROUP BY status")->fetchAll();
$countMap = array_column($counts, 'n', 'status');
$totalAll = array_sum(array_column($counts, 'n'));

// Stats
$rpt = $pdo->query("
    SELECT
        COUNT(*)                      AS total,
        SUM(status='pending')         AS pending,
        SUM(status='approved')        AS approved,
        SUM(status='rejected')        AS rejected,
        COUNT(DISTINCT a.request_id)  AS unique_jobs,
        COUNT(DISTINCT a.worker_id)   AS unique_workers
    FROM applications a
")->fetch();

$statusMeta = [
    'pending'  => ['label' => 'Pending',  'color' => '#f59e0b'],
    'approved' => ['label' => 'Approved', 'color' => '#16a34a'],
    'rejected' => ['label' => 'Rejected', 'color' => '#ef4444'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Applications — AkuapemHub Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css" />
    <style>
        /* ── Compact application rows (mirrors requests.php) ── */
        .ap-list { display: flex; flex-direction: column; gap: 6px; }

        .ap-row {
            background: #fff; border: 1px solid #e2e8f0;
            border-radius: 8px; overflow: hidden; transition: box-shadow .15s;
        }
        .ap-row:hover { box-shadow: 0 2px 8px rgba(0,0,0,.08); }
        .ap-row.ap-pending  { border-left: 3px solid #f59e0b; }
        .ap-row.ap-approved { border-left: 3px solid #16a34a; }
        .ap-row.ap-rejected { border-left: 3px solid #ef4444; }

        .ap-strip {
            display: grid;
            grid-template-columns: 1fr auto;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
        }
        .ap-body { min-width: 0; }
        .ap-title-row {
            display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
            margin-bottom: 3px;
        }
        .ap-title {
            font-weight: 600; font-size: .95rem;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
            max-width: 380px;
        }
        .ap-badges { display: flex; align-items: center; gap: 4px; flex-shrink: 0; }
        .ap-badge {
            display: inline-block; border-radius: 4px; padding: 1px 7px;
            font-size: .72rem; font-weight: 700; letter-spacing: .04em;
            text-transform: uppercase; white-space: nowrap;
        }
        .ap-meta {
            font-size: .8rem; color: #6b7280;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }

        .ap-actions { display: flex; align-items: center; gap: 6px; flex-shrink: 0; }

        .ap-detail {
            display: none;
            padding: 12px 14px 14px;
            border-top: 1px solid #f1f5f9;
            background: #f8fafc;
            font-size: .88rem; line-height: 1.6;
        }
        .ap-row.is-open .ap-detail { display: block; }
        .ap-detail .detail-grid {
            display: grid; grid-template-columns: 1fr 1fr; gap: 4px 24px; margin-bottom: 10px;
        }
        @media (max-width: 540px) { .ap-detail .detail-grid { grid-template-columns: 1fr; } }
        .detail-label { color: #9ca3af; font-size: .75rem; display: block; }
        .ap-toggle-btn {
            background: none; border: none; cursor: pointer;
            color: #6b7280; font-size: .78rem; padding: 0 4px;
            text-decoration: underline; text-underline-offset: 2px;
        }
        .ap-toggle-btn:hover { color: #374151; }

        /* stat cards */
        .stat-row {
            display: grid; grid-template-columns: repeat(auto-fit,minmax(100px,1fr));
            gap: 8px; margin-bottom: 18px;
        }
        .stat-pill {
            background: #fff; border: 1px solid #e2e8f0;
            border-radius: 8px; padding: 10px 14px; text-align: center;
        }
        .stat-pill strong { display: block; font-size: 1.5rem; line-height: 1; }
        .stat-pill span   { font-size: .75rem; color: #6b7280; }

        /* filter tabs */
        .status-tabs { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 14px; }
        .status-tab {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 5px 12px; border-radius: 20px; font-size: .8rem; font-weight: 600;
            text-decoration: none; border: 1.5px solid #e2e8f0;
            background: #fff; color: #374151; transition: all .15s;
        }
        .status-tab.active, .status-tab:hover { border-color: currentColor; }
        .status-tab .tab-count {
            border-radius: 10px; padding: 0 6px; font-size: .7rem; line-height: 1.5;
        }

        /* toolbar */
        .ap-toolbar { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 14px; }
        #ap-search {
            flex: 1; min-width: 180px; max-width: 320px;
            padding: 7px 12px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: .9rem;
        }
        .ap-count-note { font-size: .83rem; color: #6b7280; margin-left: auto; }

        @media (max-width: 700px) {
            .ap-strip { grid-template-columns: 1fr; }
            .ap-actions { justify-content: flex-start; }
            .ap-title { max-width: 220px; }
        }
    </style>
</head>
<body>
    <header class="topbar">
        <a href="index.php" class="button button-secondary button-small">← Back</a>
        <h1>Applications <span style="font-size:.72rem;font-weight:400;color:var(--text-muted);">read-only · hiring managed by job owners</span></h1>
        <a href="../logout.php" class="button button-secondary button-small">Logout</a>
    </header>

    <main class="page-shell">

        <!-- Stats -->
        <div class="stat-row">
            <div class="stat-pill">
                <strong><?php echo (int)($rpt['total'] ?? 0); ?></strong>
                <span>Total</span>
            </div>
            <div class="stat-pill" style="border-color:#f59e0b;">
                <strong style="color:#f59e0b;"><?php echo (int)($rpt['pending'] ?? 0); ?></strong>
                <span>Pending</span>
            </div>
            <div class="stat-pill" style="border-color:#16a34a;">
                <strong style="color:#16a34a;"><?php echo (int)($rpt['approved'] ?? 0); ?></strong>
                <span>Approved</span>
            </div>
            <div class="stat-pill" style="border-color:#ef4444;">
                <strong style="color:#ef4444;"><?php echo (int)($rpt['rejected'] ?? 0); ?></strong>
                <span>Rejected</span>
            </div>
            <div class="stat-pill" style="border-color:#7c3aed;">
                <strong style="color:#7c3aed;"><?php echo (int)($rpt['unique_jobs'] ?? 0); ?></strong>
                <span>Jobs</span>
            </div>
            <div class="stat-pill" style="border-color:#0891b2;">
                <strong style="color:#0891b2;"><?php echo (int)($rpt['unique_workers'] ?? 0); ?></strong>
                <span>Workers</span>
            </div>
        </div>

        <!-- Status filter tabs -->
        <div class="status-tabs">
            <a href="applications.php" class="status-tab <?php echo !$filterStatus ? 'active' : ''; ?>" style="color:#374151;">
                All
                <span class="tab-count" style="background:#374151;color:#fff;"><?php echo $totalAll; ?></span>
            </a>
            <?php foreach ($statusMeta as $st => $sm): ?>
                <?php $cnt = $countMap[$st] ?? 0; if (!$cnt) continue; ?>
                <a href="applications.php?status=<?php echo urlencode($st); ?>"
                   class="status-tab <?php echo $filterStatus === $st ? 'active' : ''; ?>"
                   style="color:<?php echo $sm['color']; ?>;">
                    <?php echo $sm['label']; ?>
                    <span class="tab-count" style="background:<?php echo $sm['color']; ?>;color:#fff;"><?php echo $cnt; ?></span>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- Toolbar -->
        <div class="ap-toolbar">
            <input type="search" id="ap-search" placeholder="Search job, worker, customer…" value="<?php echo sanitize($searchQ); ?>" />
            <span class="ap-count-note" id="ap-count"><?php echo count($allApps); ?> application<?php echo count($allApps) !== 1 ? 's' : ''; ?></span>
        </div>

        <!-- Application rows -->
        <?php if (!$allApps): ?>
            <div class="empty-state">No applications<?php echo $filterStatus ? ' with this status' : ''; ?>.</div>
        <?php else: ?>
        <div class="ap-list" id="ap-list">
            <?php foreach ($allApps as $app): ?>
                <?php
                    $sm         = $statusMeta[$app['status']] ?? ['label' => strtoupper($app['status']), 'color' => '#6b7280'];
                    $rowCls     = 'ap-row ap-' . $app['status'];
                    $workersLeft = max(0, (int)$app['workers_needed'] - (int)$app['workers_approved']);
                    $searchStr  = strtolower(
                        $app['request_title'] . ' ' .
                        $app['worker_name']   . ' ' .
                        $app['customer_name'] . ' ' .
                        ($app['location'] ?? '')
                    );
                ?>
                <div class="<?php echo $rowCls; ?>"
                     data-search="<?php echo htmlspecialchars($searchStr, ENT_QUOTES); ?>">

                    <div class="ap-strip">
                        <div class="ap-body">
                            <div class="ap-title-row">
                                <span class="ap-title" title="<?php echo sanitize($app['request_title']); ?>">
                                    <?php echo sanitize(mb_strimwidth($app['request_title'], 0, 70, '…')); ?>
                                </span>
                                <div class="ap-badges">
                                    <span class="ap-badge" style="background:<?php echo $sm['color']; ?>22;color:<?php echo $sm['color']; ?>;">
                                        <?php echo $sm['label']; ?>
                                    </span>
                                    <?php if ($app['worker_is_verified']): ?>
                                        <span class="ap-badge" style="background:#d1fae5;color:#065f46;">✓ Verified</span>
                                    <?php endif; ?>
                                    <?php if ($app['worker_is_featured']): ?>
                                        <span class="ap-badge" style="background:#fefce8;color:#854d0e;">⭐ Featured</span>
                                    <?php endif; ?>
                                    <?php if (($app['worker_availability'] ?? '') === 'busy'): ?>
                                        <span class="ap-badge" style="background:#fee2e2;color:#991b1b;">Busy</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="ap-meta">
                                Worker: <strong style="color:var(--text);"><?php echo sanitize($app['worker_name']); ?></strong>
                                · Job owner: <?php echo sanitize($app['customer_name']); ?>
                                · <?php echo sanitize($app['location'] ?? '—'); ?>
                                · GH₵ <?php echo sanitize($app['budget']); ?>
                                · <?php echo (int)$app['workers_approved']; ?>/<?php echo (int)$app['workers_needed']; ?> hired
                                · <?php echo time_ago($app['applied_at']); ?>
                                · <button class="ap-toggle-btn" type="button" onclick="toggleApp(this)">details ▾</button>
                            </div>
                        </div>

                        <div class="ap-actions">
                            <a href="../worker_profile_public.php?id=<?php echo $app['worker_id']; ?>"
                               class="button button-secondary button-small" target="_blank">View worker</a>
                            <a href="../request_detail.php?id=<?php echo $app['request_id']; ?>"
                               class="button button-secondary button-small" target="_blank">View job</a>
                        </div>
                    </div>

                    <!-- Expandable detail -->
                    <div class="ap-detail">
                        <div class="detail-grid">
                            <div>
                                <span class="detail-label">Worker</span>
                                <?php echo sanitize($app['worker_name']); ?>
                                <?php if ($app['worker_is_verified']): ?> <span style="color:#16a34a;font-size:.8rem;">✓ Verified</span><?php endif; ?>
                            </div>
                            <div>
                                <span class="detail-label">Worker email</span>
                                <?php echo sanitize($app['worker_email']); ?>
                            </div>
                            <div>
                                <span class="detail-label">Worker phone</span>
                                <?php echo sanitize($app['worker_phone'] ?? '—'); ?>
                            </div>
                            <div>
                                <span class="detail-label">Availability</span>
                                <?php echo ucfirst(str_replace('_', ' ', $app['worker_availability'] ?? '—')); ?>
                            </div>
                            <div>
                                <span class="detail-label">Job owner</span>
                                <?php echo sanitize($app['customer_name']); ?>
                            </div>
                            <div>
                                <span class="detail-label">Job status</span>
                                <?php echo strtoupper(str_replace('_', ' ', $app['request_status'])); ?>
                            </div>
                            <div>
                                <span class="detail-label">Workers needed</span>
                                <?php echo (int)$app['workers_needed']; ?> needed · <?php echo (int)$app['workers_approved']; ?> hired · <?php echo $workersLeft; ?> remaining
                            </div>
                            <div>
                                <span class="detail-label">Applied</span>
                                <?php echo sanitize(date('d M Y, H:i', strtotime($app['applied_at']))); ?>
                            </div>
                        </div>
                        <?php if (!empty($app['cover_letter'])): ?>
                            <div style="background:#fff;border:1px solid #e2e8f0;border-radius:6px;padding:8px 12px;font-size:.85rem;color:#374151;white-space:pre-wrap;max-height:100px;overflow-y:auto;">
                                <?php echo sanitize($app['cover_letter']); ?>
                            </div>
                        <?php endif; ?>
                        <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;">
                            <a href="../worker_profile_public.php?id=<?php echo $app['worker_id']; ?>" class="button button-secondary button-small" target="_blank">Worker profile ↗</a>
                            <a href="../request_detail.php?id=<?php echo $app['request_id']; ?>"       class="button button-secondary button-small" target="_blank">Job detail ↗</a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </main>

    <script>
    function toggleApp(btn) {
        var row = btn.closest('.ap-row');
        var open = row.classList.toggle('is-open');
        btn.textContent = open ? 'details ▴' : 'details ▾';
    }

    var searchEl = document.getElementById('ap-search');
    var countEl  = document.getElementById('ap-count');
    if (searchEl) {
        function runSearch() {
            var q    = searchEl.value.toLowerCase().trim();
            var rows = document.querySelectorAll('.ap-row');
            var vis  = 0;
            rows.forEach(function(row) {
                var match = !q || (row.dataset.search || '').includes(q);
                row.style.display = match ? '' : 'none';
                if (match) vis++;
            });
            if (countEl) countEl.textContent = vis + ' application' + (vis !== 1 ? 's' : '');
        }
        searchEl.addEventListener('input', runSearch);
        if (searchEl.value) runSearch(); // pre-fill from URL
    }
    </script>
</body>
</html>
