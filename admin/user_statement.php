<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';

require_login();
if (!is_admin_or_manager()) { header('Location: ../jobs.php'); exit; }
require_mod_permission('view_reports');

$targetId = (int)($_GET['id'] ?? 0);
if (!$targetId) { header('Location: users.php'); exit; }

$userStmt = $pdo->prepare('SELECT * FROM users WHERE id=?');
$userStmt->execute([$targetId]);
$target = $userStmt->fetch();
if (!$target) { flash('User not found.', 'error'); header('Location: users.php'); exit; }

// ── Filters ──────────────────────────────────────────────────────────────────
$service = $_GET['service'] ?? 'all';
$period  = $_GET['period']  ?? 'all';
$from    = trim($_GET['from'] ?? '');
$to      = trim($_GET['to']   ?? '');

// Capture whether this was actually a quick-pill click (no explicit from/to in
// the URL) BEFORE the resolution below overwrites $from/$to with real dates —
// otherwise every pill except the exact default looks inactive after resolving.
$isQuickPeriod = ($from === '' && $to === '');

if ($from === '' && $to === '' && $period !== 'custom') {
    switch ($period) {
        case 'month':      $from = date('Y-m-01'); $to = date('Y-m-d'); break;
        case 'last_month': $from = date('Y-m-01', strtotime('first day of last month')); $to = date('Y-m-t', strtotime('last day of last month')); break;
        case 'year':       $from = date('Y-01-01'); $to = date('Y-m-d'); break;
        default:           $from = ''; $to = ''; break; // 'all'
    }
}
$fromSql = $from !== '' ? $from . ' 00:00:00' : '1970-01-01 00:00:00';
$toSql   = $to   !== '' ? $to   . ' 23:59:59' : '2099-12-31 23:59:59';

$serviceLabels = [
    'all'                 => 'All Services (Combined)',
    'platform'            => 'Platform Payments',
    'escrow'              => 'Job Escrow Payments',
    'mp_orders'           => 'Marketplace Orders (Buyer)',
    'mp_wallet'           => 'Marketplace Earnings (Seller)',
    'delivery'            => 'Delivery Fees &amp; Earnings',
    'delivery_commission' => 'Delivery Commission (Rider)',
    'mod_rewards'         => 'Moderator Rewards',
];
if (!isset($serviceLabels[$service])) $service = 'all';

// Shop / agent identity for this user, if any — a seller may have more
// than one shop (regular + one per periodic market), so gather all of them.
$shopIdsStmt = $pdo->prepare('SELECT id FROM mp_shops WHERE user_id=?');
$shopIdsStmt->execute([$targetId]);
$shopIds = array_map('intval', $shopIdsStmt->fetchAll(PDO::FETCH_COLUMN));

$agentId = $pdo->prepare('SELECT id FROM delivery_agents WHERE user_id=?');
$agentId->execute([$targetId]);
$agentId = $agentId->fetchColumn();

// ── Gather rows from every relevant source, normalized ──────────────────────
$rows = [];

// Platform Payments — generic one-off fees (escrow & marketplace orders have their
// own dedicated sources below, so they're excluded here to avoid double-counting).
if ($service === 'all' || $service === 'platform') {
    $paymentTypeLabels = [
        'featured_job'          => 'Featured Job Listing',
        'featured_worker'       => 'Featured Worker Profile',
        'verification'          => 'Rider Verification Badge',
        'job_post'              => 'Job Posting Fee',
        'worker_service'        => 'Worker Service Listing Fee',
        'news_post'             => 'News Article Posting Fee',
        'event_post'            => 'Event Posting Fee',
        'funeral_post'          => 'Funeral Announcement Posting Fee',
        'mp_boost'              => 'Marketplace Product Boost',
        'delivery_subscription' => 'Delivery Premium Subscription',
        'delivery_sponsored'    => 'Delivery Sponsored Listing',
        'delivery_verification' => 'Delivery Rider Verification',
        'featured_event'        => 'Featured Event Listing',
        'featured_funeral'      => 'Featured Funeral Announcement',
        'featured_news'         => 'Featured News Article',
        'mp_subscription'       => 'Marketplace Seller Subscription',
        'worker_premium'        => 'Worker Premium Subscription',
        'sponsor'               => 'Sponsorship',
    ];
    // Whitelist (not blacklist) the types that belong in this catch-all bucket —
    // escrow & mp_order have their own dedicated sources below. A whitelist means
    // any stray/corrupted payment_type value can never slip through and duplicate
    // another section's row, which a NOT-IN blacklist would silently allow.
    $allowedTypes = array_keys($paymentTypeLabels);
    $placeholders = implode(',', array_fill(0, count($allowedTypes), '?'));
    $stmt = $pdo->prepare(
        "SELECT * FROM platform_payments
         WHERE user_id=? AND status='paid' AND payment_type IN ($placeholders)
           AND paid_at BETWEEN ? AND ?
         ORDER BY paid_at DESC"
    );
    $stmt->execute([$targetId, ...$allowedTypes, $fromSql, $toSql]);
    foreach ($stmt->fetchAll() as $p) {
        $label = $paymentTypeLabels[$p['payment_type']] ?? ucwords(str_replace('_', ' ', $p['payment_type']));
        $rows[] = [
            'date' => $p['paid_at'], 'service' => 'Platform',
            'description' => $label . ' — Ref ' . strtoupper($p['reference_code']),
            'direction' => 'out', 'amount' => (float)$p['amount'], 'status' => 'Paid',
        ];
    }
}

// Job Escrow — client side (funded) and worker side (released)
if ($service === 'all' || $service === 'escrow') {
    $stmt = $pdo->prepare(
        "SELECT ep.*, sr.title FROM escrow_payments ep JOIN service_requests sr ON ep.job_id=sr.id
         WHERE ep.client_id=? AND ep.paid_at IS NOT NULL AND ep.paid_at BETWEEN ? AND ? ORDER BY ep.paid_at DESC"
    );
    $stmt->execute([$targetId, $fromSql, $toSql]);
    foreach ($stmt->fetchAll() as $e) {
        $rows[] = [
            'date' => $e['paid_at'], 'service' => 'Job Escrow',
            'description' => 'Escrow funded — ' . mb_substr($e['title'], 0, 60),
            'direction' => 'out', 'amount' => (float)$e['gross_amount'], 'status' => ucfirst($e['status']),
        ];
    }
    $stmt2 = $pdo->prepare(
        "SELECT ep.*, sr.title FROM escrow_payments ep JOIN service_requests sr ON ep.job_id=sr.id
         WHERE ep.worker_id=? AND ep.status='released' AND ep.released_at BETWEEN ? AND ? ORDER BY ep.released_at DESC"
    );
    $stmt2->execute([$targetId, $fromSql, $toSql]);
    foreach ($stmt2->fetchAll() as $e) {
        $rows[] = [
            'date' => $e['released_at'], 'service' => 'Job Escrow',
            'description' => 'Escrow released — ' . mb_substr($e['title'], 0, 60),
            'direction' => 'in', 'amount' => (float)$e['net_amount'], 'status' => 'Released',
        ];
    }
}

// Marketplace Orders — as buyer. Matches on EITHER the purchase date or the
// refund date falling in-range, since a refund can land in a different period
// than the original order — then each of the two synthetic rows below is
// independently checked against the range before being included.
if ($service === 'all' || $service === 'mp_orders') {
    $stmt = $pdo->prepare(
        "SELECT mo.*, ms.shop_name FROM mp_orders mo JOIN mp_shops ms ON mo.shop_id=ms.id
         WHERE mo.customer_id=? AND mo.payment_status IN ('paid','refunded')
           AND (mo.created_at BETWEEN ? AND ? OR (mo.payment_status='refunded' AND mo.updated_at BETWEEN ? AND ?))
         ORDER BY mo.created_at DESC"
    );
    $stmt->execute([$targetId, $fromSql, $toSql, $fromSql, $toSql]);
    foreach ($stmt->fetchAll() as $o) {
        // Always show the original charge — a refund doesn't erase that it happened,
        // it's a separate later transaction, same as a real bank statement would show.
        if ($o['created_at'] >= $fromSql && $o['created_at'] <= $toSql) {
            $rows[] = [
                'date' => $o['created_at'], 'service' => 'Marketplace',
                'description' => 'Order #' . $o['id'] . ' — ' . $o['shop_name'],
                'direction' => 'out', 'amount' => (float)$o['total_amount'],
                'status' => $o['payment_status'] === 'refunded' ? 'Refunded' : 'Paid',
            ];
        }
        if ($o['payment_status'] === 'refunded') {
            $refundDate = $o['updated_at']; // no dedicated refunded_at column; updated_at is set at refund time
            if ($refundDate >= $fromSql && $refundDate <= $toSql) {
                $rows[] = [
                    'date' => $refundDate, 'service' => 'Marketplace',
                    'description' => 'Refund — Order #' . $o['id'] . ' — ' . $o['shop_name'],
                    'direction' => 'in', 'amount' => (float)$o['total_amount'], 'status' => 'Refunded',
                ];
            }
        }
    }
}

// Marketplace Earnings — as seller (wallet ledger; skip the purely-internal
// pending→available transfer since it's not new money, already counted once)
if (($service === 'all' || $service === 'mp_wallet') && $shopIds) {
    $shopInClause = implode(',', array_fill(0, count($shopIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT * FROM mp_wallet_transactions WHERE shop_id IN ($shopInClause) AND type != 'released_to_available'
         AND created_at BETWEEN ? AND ? ORDER BY created_at DESC"
    );
    $stmt->execute(array_merge($shopIds, [$fromSql, $toSql]));
    $wtLabels = ['sale_pending' => 'Order sale credited', 'withdrawal' => 'Withdrawal paid out', 'reversal' => 'Refund reversal'];
    foreach ($stmt->fetchAll() as $w) {
        $rows[] = [
            'date' => $w['created_at'], 'service' => 'Marketplace Earnings',
            'description' => $wtLabels[$w['type']] ?? ucfirst($w['type']),
            'direction' => $w['type'] === 'sale_pending' ? 'in' : 'out',
            'amount' => abs((float)$w['amount']), 'status' => 'Completed',
        ];
    }
}

// Delivery — fee paid as customer
if ($service === 'all' || $service === 'delivery') {
    $stmt = $pdo->prepare(
        "SELECT * FROM delivery_requests WHERE customer_id=? AND payment_status='paid' AND delivery_fee IS NOT NULL
         AND updated_at BETWEEN ? AND ? ORDER BY updated_at DESC"
    );
    $stmt->execute([$targetId, $fromSql, $toSql]);
    foreach ($stmt->fetchAll() as $d) {
        $rows[] = [
            'date' => $d['updated_at'], 'service' => 'Delivery',
            'description' => 'Delivery fee — #' . $d['id'] . ' ' . mb_substr($d['item_description'], 0, 40),
            'direction' => 'out', 'amount' => (float)$d['delivery_fee'], 'status' => 'Paid',
        ];
    }
    // fee earned as rider
    if ($agentId) {
        $stmt2 = $pdo->prepare(
            "SELECT * FROM delivery_requests WHERE agent_id=? AND status='delivered' AND delivery_fee IS NOT NULL
             AND updated_at BETWEEN ? AND ? ORDER BY updated_at DESC"
        );
        $stmt2->execute([$agentId, $fromSql, $toSql]);
        foreach ($stmt2->fetchAll() as $d) {
            $rows[] = [
                'date' => $d['updated_at'], 'service' => 'Delivery Earnings',
                'description' => 'Delivery fee earned — #' . $d['id'],
                'direction' => 'in', 'amount' => (float)$d['delivery_fee'], 'status' => 'Delivered',
            ];
        }
    }
}

// Delivery Commission — rider's ledger with the platform
if (($service === 'all' || $service === 'delivery_commission') && $agentId) {
    $stmt = $pdo->prepare('SELECT * FROM delivery_commission_ledger WHERE agent_id=? AND created_at BETWEEN ? AND ? ORDER BY created_at DESC');
    $stmt->execute([$agentId, $fromSql, $toSql]);
    $clLabels = ['commission_owed' => 'Commission accrued (owed)', 'settlement' => 'Commission settled with admin', 'reversal' => 'Commission reversed'];
    foreach ($stmt->fetchAll() as $c) {
        $rows[] = [
            'date' => $c['created_at'], 'service' => 'Delivery Commission',
            'description' => $clLabels[$c['type']] ?? ucfirst($c['type']),
            'direction' => $c['type'] === 'reversal' ? 'in' : 'out',
            'amount' => abs((float)$c['amount']), 'status' => 'Completed',
        ];
    }
}

// Moderator Rewards
if ($service === 'all' || $service === 'mod_rewards') {
    $stmt = $pdo->prepare("SELECT * FROM mod_rewards WHERE mod_id=? AND status='paid' AND paid_at BETWEEN ? AND ? ORDER BY paid_at DESC");
    $stmt->execute([$targetId, $fromSql, $toSql]);
    foreach ($stmt->fetchAll() as $r) {
        $rows[] = [
            'date' => $r['paid_at'], 'service' => 'Moderator Reward',
            'description' => ucfirst($r['reward_type']) . ' reward (' . (int)$r['points_used'] . ' pts redeemed)',
            'direction' => 'in', 'amount' => (float)$r['amount_ghs'], 'status' => 'Paid',
        ];
    }
}

usort($rows, fn($a, $b) => strcmp($b['date'], $a['date']));

$totalIn  = array_sum(array_column(array_filter($rows, fn($r) => $r['direction'] === 'in'), 'amount'));
$totalOut = array_sum(array_column(array_filter($rows, fn($r) => $r['direction'] === 'out'), 'amount'));

// ── CSV export ────────────────────────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'csv' && is_admin()) {
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="statement_' . preg_replace('/[^a-z0-9]+/i', '_', $target['name']) . '_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date', 'Service', 'Description', 'Direction', 'Amount (GHS)', 'Status']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['date'], $r['service'], csv_safe($r['description']), $r['direction'] === 'in' ? 'Credit' : 'Debit', number_format($r['amount'], 2, '.', ''), $r['status']]);
    }
    fputcsv($out, []);
    fputcsv($out, ['', '', '', 'Total Credits', number_format($totalIn, 2, '.', ''), '']);
    fputcsv($out, ['', '', '', 'Total Debits', number_format($totalOut, 2, '.', ''), '']);
    fputcsv($out, ['', '', '', 'Net', number_format($totalIn - $totalOut, 2, '.', ''), '']);
    fclose($out);
    exit;
}

$qs = http_build_query(['id' => $targetId, 'service' => $service, 'period' => $period !== 'all' ? $period : null, 'from' => $from ?: null, 'to' => $to ?: null]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Statement — <?php echo sanitize($target['name']); ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .st-shell { max-width:980px; margin:0 auto; padding:18px 16px 60px; }
        .st-head { background:linear-gradient(135deg, var(--primary,#2f8f5b) 0%, var(--primary-dark,#246b45) 100%); color:#fff; border-radius:18px; padding:22px 24px; margin-bottom:16px; }
        .st-head h2 { margin:0 0 4px; font-size:1.3rem; font-weight:900; }
        .st-head p { margin:0; font-size:.84rem; opacity:.9; }
        .st-head .st-meta { display:flex; gap:16px; flex-wrap:wrap; margin-top:12px; padding-top:12px; border-top:1px solid rgba(255,255,255,.25); font-size:.8rem; }
        .st-filters { background:var(--surface); border:1px solid var(--border); border-radius:14px; padding:16px; margin-bottom:16px; }
        .st-filters form { display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; }
        .st-filters .fg { display:flex; flex-direction:column; gap:4px; }
        .st-filters label { font-size:.72rem; font-weight:700; color:var(--text-muted,#6b7280); text-transform:uppercase; letter-spacing:.04em; }
        .st-filters select, .st-filters input[type=date] { padding:7px 10px; border:1px solid var(--border); border-radius:8px; font-size:.84rem; }
        .st-seg { display:inline-flex; flex-wrap:wrap; gap:2px; background:var(--surface-muted,#f1f5f9); border-radius:10px; padding:3px; margin-bottom:14px; }
        .st-seg a { padding:6px 13px; border-radius:8px; font-size:.76rem; font-weight:700; text-decoration:none; color:var(--text-muted,#6b7280); }
        .st-seg a.active { background:var(--surface,#fff); color:var(--primary,#2f8f5b); box-shadow:0 1px 4px rgba(0,0,0,.1); }
        .st-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:10px; margin-bottom:16px; }
        .st-stat { background:var(--surface); border:1px solid var(--border); border-radius:12px; padding:14px; text-align:center; }
        .st-stat strong { display:block; font-size:1.2rem; font-weight:900; }
        .st-stat span { font-size:.7rem; color:var(--text-muted,#6b7280); text-transform:uppercase; letter-spacing:.05em; }
        .st-table-wrap { background:var(--surface); border:1px solid var(--border); border-radius:14px; overflow:hidden; overflow-x:auto; }
        .st-table { width:100%; border-collapse:collapse; font-size:.84rem; }
        .st-table th { padding:10px 14px; text-align:left; font-size:.7rem; font-weight:800; text-transform:uppercase; letter-spacing:.05em; color:var(--text-muted,#6b7280); border-bottom:1px solid var(--border); background:var(--surface-muted,#f9fafb); }
        .st-table td { padding:11px 14px; border-bottom:1px solid var(--border,#f1f5f9); vertical-align:middle; }
        .st-table tr:last-child td { border-bottom:none; }
        .st-chip { display:inline-block; padding:2px 9px; border-radius:20px; font-size:.68rem; font-weight:700; background:var(--primary-soft,#e4f4ea); color:var(--primary-dark,#246b45); }
        .st-empty { text-align:center; padding:50px 20px; color:var(--text-muted,#6b7280); }
        .st-actions { display:flex; gap:8px; margin-bottom:16px; flex-wrap:wrap; }
        @media print {
            .no-print { display:none !important; }
            body { background:#fff !important; }
            .st-shell { padding:0; max-width:100%; }
            .st-head { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
            .st-table-wrap { border:none; }
        }
    </style>
</head>
<body>

<header class="topbar no-print">
    <a href="user_edit.php?id=<?php echo $targetId; ?>" class="button button-secondary button-small">← Back to User</a>
    <h1 style="margin:0;font-size:1rem;font-weight:800;">Account Statement</h1>
    <a href="users.php" class="button button-secondary button-small">All Users</a>
</header>

<main class="st-shell">

    <div class="st-head">
        <h2><?php echo sanitize($target['name']); ?></h2>
        <p><?php echo sanitize($target['email']); ?> &nbsp;·&nbsp; <?php echo ucfirst($target['role']); ?> &nbsp;·&nbsp; Statement generated <?php echo date('d M Y, H:i'); ?></p>
        <div class="st-meta">
            <span><strong><?php echo sanitize($serviceLabels[$service]); ?></strong></span>
            <span><?php echo $from !== '' ? date('d M Y', strtotime($from)) : 'Beginning'; ?> → <?php echo $to !== '' ? date('d M Y', strtotime($to)) : 'Now'; ?></span>
            <span><?php echo count($rows); ?> transaction<?php echo count($rows) === 1 ? '' : 's'; ?></span>
        </div>
    </div>

    <div class="st-filters no-print">
        <div class="st-seg">
            <?php foreach (['all' => 'All Time', 'month' => 'This Month', 'last_month' => 'Last Month', 'year' => 'This Year'] as $pv => $pl): ?>
            <a href="?id=<?php echo $targetId; ?>&service=<?php echo $service; ?>&period=<?php echo $pv; ?>" class="<?php echo ($period === $pv && $isQuickPeriod) ? 'active' : ''; ?>"><?php echo $pl; ?></a>
            <?php endforeach; ?>
        </div>
        <form method="get">
            <input type="hidden" name="id" value="<?php echo $targetId; ?>">
            <input type="hidden" name="period" value="custom">
            <div class="fg">
                <label>Service</label>
                <select name="service" onchange="this.form.submit()">
                    <?php foreach ($serviceLabels as $sv => $sl): ?>
                    <option value="<?php echo $sv; ?>" <?php echo $service === $sv ? 'selected' : ''; ?>><?php echo $sl; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="fg">
                <label>From</label>
                <input type="date" name="from" value="<?php echo sanitize($from); ?>">
            </div>
            <div class="fg">
                <label>To</label>
                <input type="date" name="to" value="<?php echo sanitize($to); ?>">
            </div>
            <button type="submit" class="button button-primary button-small">Apply</button>
        </form>
    </div>

    <div class="st-stats">
        <div class="st-stat"><strong style="color:#065f46;">GH&#8373; <?php echo number_format($totalIn, 2); ?></strong><span>Total Credits</span></div>
        <div class="st-stat"><strong style="color:#c0392b;">GH&#8373; <?php echo number_format($totalOut, 2); ?></strong><span>Total Debits</span></div>
        <div class="st-stat"><strong style="color:var(--primary,#2f8f5b);">GH&#8373; <?php echo number_format($totalIn - $totalOut, 2); ?></strong><span>Net</span></div>
        <div class="st-stat"><strong><?php echo count($rows); ?></strong><span>Transactions</span></div>
    </div>

    <div class="st-actions no-print">
        <button onclick="window.print()" class="button button-primary button-small">🖨 Print / Save PDF</button>
        <a href="?<?php echo $qs; ?>&export=csv" class="button button-secondary button-small">⬇ Download CSV</a>
    </div>

    <div class="st-table-wrap">
        <?php if (!$rows): ?>
        <div class="st-empty">No transactions found for this service and period.</div>
        <?php else: ?>
        <table class="st-table">
            <thead><tr><th>Date</th><th>Service</th><th>Description</th><th>Status</th><th style="text-align:right;">Amount</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
            <tr>
                <td style="white-space:nowrap;"><?php echo date('d M Y, H:i', strtotime($r['date'])); ?></td>
                <td><span class="st-chip"><?php echo sanitize($r['service']); ?></span></td>
                <td><?php echo sanitize($r['description']); ?></td>
                <td><?php echo sanitize($r['status']); ?></td>
                <td style="text-align:right;white-space:nowrap;font-weight:700;color:<?php echo $r['direction'] === 'in' ? '#065f46' : '#c0392b'; ?>;">
                    <?php echo $r['direction'] === 'in' ? '+' : '−'; ?>GH&#8373; <?php echo number_format($r['amount'], 2); ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

</main>
</body>
</html>
