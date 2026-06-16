<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';

require_login();
if (!is_admin_or_manager()) {
    header('Location: ../jobs.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    if ($_POST['action'] === 'bulk' && !empty($_POST['selected_requests']) && is_array($_POST['selected_requests'])) {
        $requestIds   = array_map('intval', $_POST['selected_requests']);
        $bulkAction   = $_POST['bulk_action'] ?? '';
        $placeholders = implode(',', array_fill(0, count($requestIds), '?'));
        $stmt = $pdo->prepare("SELECT sr.*, u.email AS customer_email, u.name AS customer_name FROM service_requests sr JOIN users u ON sr.customer_id = u.id WHERE sr.id IN ($placeholders)");
        $stmt->execute($requestIds);
        $selectedRequests = $stmt->fetchAll();

        foreach ($selectedRequests as $request) {
            if ($bulkAction === 'approve') {
                $pdo->prepare('UPDATE service_requests SET status = ? WHERE id = ?')->execute(['open', $request['id']]);
                send_email_notification($request['customer_email'], 'Your request is approved', "Hello {$request['customer_name']},\n\nYour request '{$request['title']}' has been approved by admin and is now visible to workers.\n\nThank you.", $request['customer_id']);
                notify_user($request['customer_id'], 'Request approved', "Your request '{$request['title']}' is now approved and open to workers.", 'success');
                send_business_message($request['customer_id'], $request['contact_info'], "AkuapemHub: Your request '{$request['title']}' has been approved and is now visible to workers.", 'whatsapp');
                notify_workers_of_matching_job($request);
            } elseif ($bulkAction === 'remove') {
                $pdo->prepare('DELETE FROM service_requests WHERE id = ?')->execute([$request['id']]);
                send_email_notification($request['customer_email'], 'Your request has been removed', "Hello {$request['customer_name']},\n\nYour request '{$request['title']}' has been removed by the admin.\n\nContact support for more information.", $request['customer_id']);
                notify_user($request['customer_id'], 'Request removed', "Your request '{$request['title']}' was removed by admin.", 'warning');
            } elseif ($bulkAction === 'feature') {
                $pdo->prepare('UPDATE service_requests SET featured = 1 WHERE id = ?')->execute([$request['id']]);
                send_email_notification($request['customer_email'], 'Your request is featured', "Hello {$request['customer_name']},\n\nYour request '{$request['title']}' has been marked as featured by the admin.\n\nGreat job!\n", $request['customer_id']);
                notify_user($request['customer_id'], 'Request featured', "Your request '{$request['title']}' was marked as featured.", 'success');
            }
        }
    } elseif (!empty($_POST['request_id'])) {
        $requestId = intval($_POST['request_id']);
        $stmt = $pdo->prepare('SELECT sr.*, u.email AS customer_email, u.name AS customer_name FROM service_requests sr JOIN users u ON sr.customer_id = u.id WHERE sr.id = ?');
        $stmt->execute([$requestId]);
        $request = $stmt->fetch();

        if ($_POST['action'] === 'approve' && $request) {
            if (($request['posting_fee_status'] ?? 'free') === 'pending') {
                header('Location: requests.php?err=' . urlencode('Cannot approve — posting fee payment not yet confirmed.'));
                exit;
            }
            $pdo->prepare('UPDATE service_requests SET status = ? WHERE id = ?')->execute(['open', $requestId]);
            send_email_notification($request['customer_email'], 'Your request is approved', "Hello {$request['customer_name']},\n\nYour request '{$request['title']}' has been approved by admin and is now visible to workers.\n\nThank you.", $request['customer_id']);
            notify_user($request['customer_id'], 'Request approved', "Your request '{$request['title']}' is now approved and open to workers.", 'success');
            send_business_message($request['customer_id'], $request['contact_info'], "AkuapemHub: Your request '{$request['title']}' has been approved and is now visible to workers.", 'whatsapp');
            notify_workers_of_matching_job($request);
        } elseif ($_POST['action'] === 'remove' && $request) {
            $pdo->prepare('DELETE FROM service_requests WHERE id = ?')->execute([$requestId]);
            send_email_notification($request['customer_email'], 'Your request has been removed', "Hello {$request['customer_name']},\n\nYour request '{$request['title']}' has been removed by the admin.\n\nContact support for more information.", $request['customer_id']);
            notify_user($request['customer_id'], 'Request removed', "Your request '{$request['title']}' was removed by admin.", 'warning');
        } elseif ($_POST['action'] === 'feature' && $request) {
            $pdo->prepare('UPDATE service_requests SET featured = 1 WHERE id = ?')->execute([$requestId]);
            send_email_notification($request['customer_email'], 'Your request is featured', "Hello {$request['customer_name']},\n\nYour request '{$request['title']}' has been marked as featured by the admin.\n\nGreat job!\n", $request['customer_id']);
            notify_user($request['customer_id'], 'Request featured', "Your request '{$request['title']}' was marked as featured.", 'success');
        }
    }
    header('Location: requests.php');
    exit;
}

$errFlash = $_GET['err'] ?? '';
$filterStatus = $_GET['status'] ?? '';

// Pending-first sort so items needing action bubble to top
$stmt = $pdo->query("
    SELECT sr.*, sr.posting_fee_status, sr.workers_needed, sr.workers_approved,
           u.name AS customer_name, c.name AS category_name
    FROM service_requests sr
    JOIN users u ON sr.customer_id = u.id
    JOIN service_categories c ON sr.category_id = c.id
    ORDER BY
        FIELD(sr.status,'pending','pending_payment','open','partially_staffed','in_progress','fully_staffed','completed','cancelled'),
        sr.created_at DESC
");
$allRequests = $stmt->fetchAll();

// Count per status
$statusCounts = [];
foreach ($allRequests as $r) { $statusCounts[$r['status']] = ($statusCounts[$r['status']] ?? 0) + 1; }

// Apply filter
$requests = $filterStatus
    ? array_values(array_filter($allRequests, fn($r) => $r['status'] === $filterStatus))
    : $allRequests;

// Summary stats
$rpt = $pdo->query("
    SELECT
        COUNT(*) AS total_jobs,
        SUM(status='pending') AS pending_jobs,
        SUM(status='open') AS open_jobs,
        SUM(status='partially_staffed') AS partial_jobs,
        SUM(status='fully_staffed') AS full_jobs,
        SUM(status='completed') AS completed_jobs
    FROM service_requests WHERE status NOT IN ('cancelled')
")->fetch();

$statusMeta = [
    'pending'           => ['label' => 'Pending',       'color' => '#f59e0b'],
    'pending_payment'   => ['label' => 'Awaiting pay',  'color' => '#f97316'],
    'open'              => ['label' => 'Open',           'color' => '#16a34a'],
    'partially_staffed' => ['label' => 'Part. staffed', 'color' => '#2563eb'],
    'fully_staffed'     => ['label' => 'Fully staffed', 'color' => '#0891b2'],
    'in_progress'       => ['label' => 'In progress',   'color' => '#7c3aed'],
    'completed'         => ['label' => 'Completed',     'color' => '#6b7280'],
    'cancelled'         => ['label' => 'Cancelled',     'color' => '#ef4444'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Admin Requests — AkuapemHub</title>
    <link rel="stylesheet" href="../assets/css/style.css" />
    <style>
        /* ── compact request rows ───────────────────────────────── */
        .rq-list { display: flex; flex-direction: column; gap: 6px; }

        .rq-row {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            overflow: hidden;
            transition: box-shadow .15s;
        }
        .rq-row:hover { box-shadow: 0 2px 8px rgba(0,0,0,.08); }
        .rq-row.rq-fee-pending { border-left: 3px solid #f59e0b; }
        .rq-row.rq-has-risk    { border-left: 3px solid #ef4444; }
        .rq-row.rq-fee-pending.rq-has-risk { border-left: 3px solid #ef4444; }

        /* main compact strip */
        .rq-strip {
            display: grid;
            grid-template-columns: 32px 1fr auto;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
        }
        .rq-check { display: flex; align-items: center; justify-content: center; }
        .rq-check input[type=checkbox] { width: 16px; height: 16px; cursor: pointer; }

        .rq-body { min-width: 0; }
        .rq-title-row {
            display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
            margin-bottom: 3px;
        }
        .rq-title {
            font-weight: 600; font-size: .95rem;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
            max-width: 360px;
        }
        .rq-badges { display: flex; align-items: center; gap: 4px; flex-shrink: 0; }
        .rq-badge {
            display: inline-block; border-radius: 4px; padding: 1px 7px;
            font-size: .72rem; font-weight: 700; letter-spacing: .04em;
            text-transform: uppercase; white-space: nowrap;
        }
        .rq-meta {
            font-size: .8rem; color: #6b7280; white-space: nowrap;
            overflow: hidden; text-overflow: ellipsis;
        }

        /* actions on the right */
        .rq-actions {
            display: flex; align-items: center; gap: 6px; flex-shrink: 0; flex-wrap: wrap;
            justify-content: flex-end;
        }
        .rq-actions form { display: flex; gap: 4px; }

        /* expandable detail panel */
        .rq-detail {
            display: none;
            padding: 12px 14px 14px 56px; /* indent to align with title */
            border-top: 1px solid #f1f5f9;
            background: #f8fafc;
            font-size: .88rem;
            line-height: 1.6;
        }
        .rq-row.is-open .rq-detail { display: block; }
        .rq-detail .detail-grid {
            display: grid; grid-template-columns: 1fr 1fr; gap: 4px 24px; margin-bottom: 10px;
        }
        @media (max-width: 540px) { .rq-detail .detail-grid { grid-template-columns: 1fr; } }
        .rq-detail .detail-label { color: #9ca3af; font-size: .75rem; display: block; }
        .rq-detail .rq-desc {
            background: #fff; border: 1px solid #e2e8f0; border-radius: 6px;
            padding: 8px 12px; margin-bottom: 10px; color: #374151; white-space: pre-wrap;
            max-height: 120px; overflow-y: auto; font-size: .85rem;
        }
        .rq-toggle-btn {
            background: none; border: none; cursor: pointer;
            color: #6b7280; font-size: .78rem; padding: 0 4px;
            text-decoration: underline; text-underline-offset: 2px;
        }
        .rq-toggle-btn:hover { color: #374151; }

        /* filter tabs */
        .status-tabs {
            display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 14px;
        }
        .status-tab {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 5px 12px; border-radius: 20px; font-size: .8rem; font-weight: 600;
            text-decoration: none; border: 1.5px solid #e2e8f0;
            background: #fff; color: #374151; transition: all .15s;
        }
        .status-tab.active, .status-tab:hover { border-color: currentColor; }
        .status-tab .tab-count {
            background: currentColor; color: #fff; border-radius: 10px;
            padding: 0 6px; font-size: .7rem; line-height: 1.5;
        }
        .status-tab .tab-count-inner { color: inherit; mix-blend-mode: normal; }

        /* search */
        .rq-toolbar {
            display: flex; gap: 10px; align-items: center; flex-wrap: wrap;
            margin-bottom: 14px;
        }
        #rq-search {
            flex: 1; min-width: 180px; max-width: 320px;
            padding: 7px 12px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: .9rem;
        }
        .rq-count-note { font-size: .83rem; color: #6b7280; margin-left: auto; }

        /* stat cards */
        .stat-row { display: grid; grid-template-columns: repeat(auto-fit,minmax(100px,1fr)); gap: 8px; margin-bottom: 18px; }
        .stat-pill {
            background: #fff; border: 1px solid #e2e8f0; border-radius: 8px;
            padding: 10px 14px; text-align: center;
        }
        .stat-pill strong { display: block; font-size: 1.5rem; line-height: 1; }
        .stat-pill span { font-size: .75rem; color: #6b7280; }

        @media (max-width: 700px) {
            .rq-strip { grid-template-columns: 32px 1fr; }
            .rq-actions { grid-column: 1/-1; padding-left: 42px; }
            .rq-title { max-width: 200px; }
        }
    </style>
</head>
<body>
    <header class="topbar">
        <a href="index.php" class="button button-secondary button-small">← Back</a>
        <h1>Requests</h1>
        <a href="../logout.php" class="button button-secondary button-small">Logout</a>
    </header>
    <main class="page-shell">
        <?php if ($errFlash): ?>
            <div class="alert alert-error"><?php echo sanitize($errFlash); ?></div>
        <?php endif; ?>

        <!-- Stats row -->
        <div class="stat-row">
            <div class="stat-pill">
                <strong><?php echo (int)($rpt['total_jobs'] ?? 0); ?></strong>
                <span>Total</span>
            </div>
            <div class="stat-pill" style="border-color:#f59e0b;">
                <strong style="color:#f59e0b;"><?php echo (int)($rpt['pending_jobs'] ?? 0); ?></strong>
                <span>Pending</span>
            </div>
            <div class="stat-pill" style="border-color:#16a34a;">
                <strong style="color:#16a34a;"><?php echo (int)($rpt['open_jobs'] ?? 0); ?></strong>
                <span>Open</span>
            </div>
            <div class="stat-pill" style="border-color:#2563eb;">
                <strong style="color:#2563eb;"><?php echo (int)($rpt['partial_jobs'] ?? 0); ?></strong>
                <span>Part. staffed</span>
            </div>
            <div class="stat-pill" style="border-color:#0891b2;">
                <strong style="color:#0891b2;"><?php echo (int)($rpt['full_jobs'] ?? 0); ?></strong>
                <span>Full</span>
            </div>
            <div class="stat-pill" style="border-color:#6b7280;">
                <strong style="color:#6b7280;"><?php echo (int)($rpt['completed_jobs'] ?? 0); ?></strong>
                <span>Completed</span>
            </div>
        </div>

        <!-- Status filter tabs -->
        <div class="status-tabs">
            <a href="requests.php" class="status-tab <?php echo !$filterStatus ? 'active' : ''; ?>" style="color:#374151;">
                All
                <span class="tab-count" style="background:#374151;color:#fff;"><?php echo count($allRequests); ?></span>
            </a>
            <?php foreach ($statusMeta as $st => $sm): ?>
                <?php $cnt = $statusCounts[$st] ?? 0; if (!$cnt) continue; ?>
                <a href="requests.php?status=<?php echo urlencode($st); ?>"
                   class="status-tab <?php echo $filterStatus === $st ? 'active' : ''; ?>"
                   style="color:<?php echo $sm['color']; ?>;">
                    <?php echo $sm['label']; ?>
                    <span class="tab-count" style="background:<?php echo $sm['color']; ?>;color:#fff;"><?php echo $cnt; ?></span>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- Toolbar -->
        <div class="rq-toolbar">
            <form id="bulk-requests" method="post" action="requests.php" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <input type="hidden" name="action" value="bulk" />
                <select name="bulk_action" style="padding:7px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:.85rem;">
                    <option value="approve">Approve selected</option>
                    <option value="remove">Remove selected</option>
                    <option value="feature">Feature selected</option>
                </select>
                <button type="submit" class="button button-primary button-small">Apply</button>
                <label style="font-size:.8rem;color:#6b7280;display:flex;align-items:center;gap:5px;cursor:pointer;">
                    <input type="checkbox" id="select-all" /> Select all
                </label>
            </form>
            <input type="search" id="rq-search" placeholder="Search title or customer…" />
            <span class="rq-count-note" id="rq-count"><?php echo count($requests); ?> job<?php echo count($requests) !== 1 ? 's' : ''; ?></span>
        </div>

        <?php if (!$requests): ?>
            <div class="empty-state">No requests found<?php echo $filterStatus ? ' for this status' : ''; ?>.</div>
        <?php else: ?>
        <div class="rq-list" id="rq-list">
            <?php foreach ($requests as $request): ?>
                <?php
                    $riskSignals = get_request_risk_signals($request);
                    $feeStatus   = $request['posting_fee_status'] ?? 'free';
                    $arFeatEnd   = $request['featured_end_date'] ?? null;
                    $arFeatActive = !empty($request['featured']) && (empty($arFeatEnd) || $arFeatEnd >= date('Y-m-d'));
                    $workersLeft  = max(0, (int)$request['workers_needed'] - (int)$request['workers_approved']);
                    $sm           = $statusMeta[$request['status']] ?? ['label' => strtoupper($request['status']), 'color' => '#6b7280'];
                    $rowClass     = 'rq-row'
                        . ($feeStatus === 'pending' ? ' rq-fee-pending' : '')
                        . ($riskSignals            ? ' rq-has-risk'    : '');
                    $searchKey = strtolower($request['title'] . ' ' . $request['customer_name'] . ' ' . $request['location']);
                ?>
                <div class="<?php echo $rowClass; ?>"
                     data-search="<?php echo htmlspecialchars($searchKey, ENT_QUOTES); ?>"
                     data-status="<?php echo sanitize($request['status']); ?>">

                    <!-- compact strip -->
                    <div class="rq-strip">
                        <div class="rq-check">
                            <input type="checkbox" name="selected_requests[]" value="<?php echo $request['id']; ?>" form="bulk-requests" />
                        </div>

                        <div class="rq-body">
                            <div class="rq-title-row">
                                <span class="rq-title" title="<?php echo sanitize($request['title']); ?>">
                                    <?php echo sanitize(mb_strimwidth($request['title'], 0, 65, '…')); ?>
                                </span>
                                <div class="rq-badges">
                                    <span class="rq-badge" style="background:<?php echo $sm['color']; ?>22;color:<?php echo $sm['color']; ?>;">
                                        <?php echo $sm['label']; ?>
                                    </span>
                                    <?php if ($feeStatus === 'pending'): ?>
                                        <span class="rq-badge" style="background:#fef3c7;color:#92400e;" title="Posting fee not confirmed">💳 Fee due</span>
                                    <?php endif; ?>
                                    <?php if ($riskSignals): ?>
                                        <span class="rq-badge" style="background:#fee2e2;color:#991b1b;" title="<?php echo sanitize(implode('; ', $riskSignals)); ?>">⚠ <?php echo count($riskSignals); ?> risk</span>
                                    <?php endif; ?>
                                    <?php if ($arFeatActive): ?>
                                        <span class="rq-badge" style="background:#fefce8;color:#854d0e;">⭐ Featured</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="rq-meta">
                                <?php echo sanitize($request['category_name']); ?>
                                · <?php echo sanitize($request['location']); ?>
                                · GH₵ <?php echo sanitize($request['budget']); ?>
                                · <?php echo (int)$request['workers_approved']; ?>/<?php echo (int)$request['workers_needed']; ?> workers
                                · <?php echo sanitize($request['customer_name']); ?>
                                · <?php echo date('d M Y', strtotime($request['created_at'])); ?>
                                · <button class="rq-toggle-btn" type="button" onclick="toggleDetail(this)">details ▾</button>
                            </div>
                        </div>

                        <!-- action buttons -->
                        <div class="rq-actions">
                            <form method="post" action="requests.php">
                                <input type="hidden" name="request_id" value="<?php echo $request['id']; ?>" />
                                <?php if (!in_array($request['status'], ['open','partially_staffed','fully_staffed','completed','cancelled'], true)): ?>
                                    <?php if ($feeStatus === 'pending'): ?>
                                        <button type="button" class="button button-primary button-small" disabled title="Posting fee not confirmed">Approve</button>
                                    <?php else: ?>
                                        <button type="submit" name="action" value="approve" class="button button-primary button-small">Approve</button>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <button type="submit" name="action" value="feature" class="button button-secondary button-small"
                                    style="<?php echo $arFeatActive ? 'color:#854d0e;border-color:#f59e0b;' : ''; ?>">
                                    <?php echo $arFeatActive ? '⭐' : 'Feature'; ?>
                                </button>
                                <button type="submit" name="action" value="remove" class="button button-secondary button-small"
                                    style="color:#dc2626;" onclick="return confirm('Remove this request?')">✕</button>
                            </form>
                        </div>
                    </div>

                    <!-- expandable details -->
                    <div class="rq-detail">
                        <div class="detail-grid">
                            <div>
                                <span class="detail-label">Customer</span>
                                <?php echo sanitize($request['customer_name']); ?>
                            </div>
                            <div>
                                <span class="detail-label">Contact</span>
                                <?php echo sanitize($request['contact_info'] ?: '—'); ?>
                            </div>
                            <div>
                                <span class="detail-label">Payment mode</span>
                                <?php echo strtoupper($request['payment_mode'] ?? '—'); ?>
                            </div>
                            <div>
                                <span class="detail-label">Payment status</span>
                                <?php echo strtoupper($request['payment_status'] ?? '—'); ?>
                            </div>
                            <div>
                                <span class="detail-label">Posting fee</span>
                                <?php echo strtoupper($feeStatus); ?>
                            </div>
                            <div>
                                <span class="detail-label">Featured</span>
                                <?php if ($arFeatActive): ?>
                                    Yes<?php echo $arFeatEnd ? ' until ' . sanitize($arFeatEnd) : ''; ?>
                                <?php elseif (!empty($request['featured']) && !$arFeatActive): ?>
                                    Expired (<?php echo sanitize($arFeatEnd); ?>)
                                <?php else: ?>
                                    No
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if (!empty($request['description'])): ?>
                            <div class="rq-desc"><?php echo sanitize($request['description']); ?></div>
                        <?php endif; ?>

                        <?php if ($feeStatus === 'pending'): ?>
                            <p style="margin:0 0 6px;color:#92400e;font-size:.85rem;">
                                💳 <strong>Posting fee pending</strong> — cannot approve until payment is confirmed.
                                <a href="monetization.php?tab=payments" style="color:var(--primary);">Confirm payment →</a>
                            </p>
                        <?php endif; ?>

                        <?php if ($riskSignals): ?>
                            <p style="margin:0 0 4px;color:#991b1b;font-size:.85rem;"><strong>⚠ Risk signals:</strong></p>
                            <ul style="margin:0;padding-left:18px;font-size:.85rem;color:#991b1b;">
                                <?php foreach ($riskSignals as $sig): ?>
                                    <li><?php echo sanitize($sig); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </main>

    <script>
    // Toggle detail panel
    function toggleDetail(btn) {
        var row = btn.closest('.rq-row');
        var open = row.classList.toggle('is-open');
        btn.textContent = open ? 'details ▴' : 'details ▾';
    }

    // Live search
    var searchEl = document.getElementById('rq-search');
    var countEl  = document.getElementById('rq-count');
    if (searchEl) {
        searchEl.addEventListener('input', function () {
            var q = this.value.toLowerCase().trim();
            var rows = document.querySelectorAll('.rq-row');
            var visible = 0;
            rows.forEach(function (row) {
                var match = !q || (row.dataset.search || '').includes(q);
                row.style.display = match ? '' : 'none';
                if (match) visible++;
            });
            if (countEl) countEl.textContent = visible + ' job' + (visible !== 1 ? 's' : '');
        });
    }

    // Select all checkbox
    var selAll = document.getElementById('select-all');
    if (selAll) {
        selAll.addEventListener('change', function () {
            document.querySelectorAll('input[name="selected_requests[]"]').forEach(function (cb) {
                cb.checked = selAll.checked;
            });
        });
    }
    </script>
</body>
</html>
