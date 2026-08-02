<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../marketplace_functions.php';
require_once __DIR__ . '/../paystack.php';

require_login();
if (!is_admin()) { header('Location: index.php'); exit; }

$adminUser = current_user();
$tab       = $_GET['tab'] ?? 'pending';
$flash     = get_flash();
$period    = $_GET['period'] ?? 'month';
$reqStatus = $_GET['req_status'] ?? 'all';
$q         = trim($_GET['q'] ?? '');

// ── POST: payout review actions ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $postAction = $_POST['action'] ?? '';

    // Approve & Pay / Retry both fire the same real Paystack transfer —
    // process_marketplace_payout() is also what auto-mode calls immediately
    // on request creation, so behavior is identical either way, only the
    // timing/human-checkpoint differs.
    if (($postAction === 'approve_and_pay_payout' || $postAction === 'retry_payout') && !empty($_POST['payout_id'])) {
        $pid    = (int)$_POST['payout_id'];
        $result = process_marketplace_payout($pid, $adminUser['id']);
        if ($result['success']) {
            flash($result['status'] === 'paid' ? 'Payout approved and paid.' : 'Payout sent — processing via Paystack.', 'success');
        } else {
            flash('Payout could not be processed: ' . $result['error'], 'error');
        }
    }

    // Escape hatch for when Paystack transfer isn't viable for a request
    // (no Paystack account, unsupported corridor, etc.) — the admin pays the
    // seller some other way themselves and records it here, same as the
    // original fully-manual flow this feature is layered on top of.
    if ($postAction === 'mark_paid_manually_payout' && !empty($_POST['payout_id'])) {
        $pid = (int)$_POST['payout_id'];
        $req = $pdo->prepare("SELECT * FROM mp_payout_requests WHERE id=? AND status IN ('pending','failed')");
        $req->execute([$pid]);
        $req = $req->fetch();
        if ($req) {
            $upd = $pdo->prepare("UPDATE mp_shops SET available_balance = available_balance - ? WHERE id=? AND available_balance >= ?");
            $upd->execute([$req['amount'], $req['shop_id'], $req['amount']]);
            if ($upd->rowCount() > 0) {
                $pdo->prepare("UPDATE mp_payout_requests SET status='paid', failure_reason=NULL, reviewed_by=?, reviewed_at=NOW(), paid_at=NOW() WHERE id=?")
                    ->execute([$adminUser['id'], $pid]);
                $pdo->prepare("INSERT INTO mp_wallet_transactions (shop_id, payout_id, type, amount, created_at) VALUES (?,?,?,?,NOW())")
                    ->execute([$req['shop_id'], $pid, 'withdrawal', $req['amount']]);
                $shopOwner = $pdo->prepare('SELECT user_id FROM mp_shops WHERE id=?');
                $shopOwner->execute([$req['shop_id']]);
                if ($uid = $shopOwner->fetchColumn()) {
                    notify_user((int)$uid, 'Withdrawal Paid 💵',
                        'Your withdrawal request of GH₵ ' . number_format($req['amount'], 2) . ' has been paid.',
                        'success', 'seller_dashboard.php?tab=wallet');
                }
                log_audit_action($adminUser['id'], 'mp_payout_mark_paid_manual', "Manually marked payout #$pid paid (GHS " . number_format($req['amount'],2) . ")");
                flash('Payout marked as paid.', 'success');
            } else {
                flash('Shop no longer has enough available balance for this payout.', 'error');
            }
        }
    }

    if ($postAction === 'check_transfer_status' && !empty($_POST['payout_id'])) {
        $pid = (int)$_POST['payout_id'];
        $req = $pdo->prepare("SELECT * FROM mp_payout_requests WHERE id=? AND status='processing'");
        $req->execute([$pid]);
        $req = $req->fetch();
        if ($req && $req['paystack_transfer_code']) {
            $check = paystack_check_transfer_status($req['paystack_transfer_code']);
            if ($check['success'] && $check['status'] === 'success') {
                finalize_marketplace_payout_transfer($req['paystack_transfer_reference'], 'transfer.success');
                flash('Transfer confirmed — payout marked paid.', 'success');
            } elseif ($check['success'] && in_array($check['status'], ['failed', 'reversed'], true)) {
                finalize_marketplace_payout_transfer($req['paystack_transfer_reference'], 'transfer.' . $check['status']);
                flash('Transfer ' . $check['status'] . ' — balance restored.', 'error');
            } else {
                flash('Still processing on Paystack\'s side — check again shortly.', 'info');
            }
        }
    }

    if ($postAction === 'sync_banks') {
        $sync = paystack_sync_banks();
        if ($sync['success']) {
            log_audit_action($adminUser['id'], 'mp_banks_synced', "Synced {$sync['count']} banks/networks from Paystack");
            flash("Synced {$sync['count']} banks/networks from Paystack.", 'success');
        } else {
            flash('Could not sync banks: ' . $sync['error'], 'error');
        }
    }

    // Legacy: finalizes any request already sitting in 'approved' from before
    // the two steps were merged above.
    if ($postAction === 'mark_paid_payout' && !empty($_POST['payout_id'])) {
        $pid = (int)$_POST['payout_id'];
        $pdo->prepare("UPDATE mp_payout_requests SET status='paid', paid_at=NOW() WHERE id=? AND status='approved'")
            ->execute([$pid]);
        log_audit_action($adminUser['id'], 'mp_payout_paid', "Marked payout #$pid as paid");
        flash('Payout marked as paid.', 'success');
    }

    if ($postAction === 'reject_payout' && !empty($_POST['payout_id'])) {
        $pid = (int)$_POST['payout_id'];
        $notes = trim($_POST['admin_notes'] ?? '');
        $pdo->prepare("UPDATE mp_payout_requests SET status='rejected', admin_notes=?, reviewed_by=?, reviewed_at=NOW() WHERE id=? AND status='pending'")
            ->execute([$notes ?: null, $adminUser['id'], $pid]);
        $shopId = $pdo->prepare('SELECT shop_id FROM mp_payout_requests WHERE id=?');
        $shopId->execute([$pid]);
        if ($sid = $shopId->fetchColumn()) {
            $shopOwner = $pdo->prepare('SELECT user_id FROM mp_shops WHERE id=?');
            $shopOwner->execute([$sid]);
            if ($uid = $shopOwner->fetchColumn()) {
                notify_user((int)$uid, 'Withdrawal Rejected',
                    'Your withdrawal request was rejected.' . ($notes ? ' Reason: ' . $notes : ''),
                    'error', 'seller_dashboard.php?tab=wallet');
            }
        }
        log_audit_action($adminUser['id'], 'mp_payout_reject', "Rejected payout #$pid");
        flash('Payout rejected.', 'info');
    }

    if ($postAction === 'save_settings') {
        if (isset($_POST['mp_commission_percent'])) {
            set_platform_setting('mp_commission_percent', max(0, min(100, (float)$_POST['mp_commission_percent'])));
        }
        if (isset($_POST['mp_payout_confirmation_days'])) {
            set_platform_setting('mp_payout_confirmation_days', max(0, (int)$_POST['mp_payout_confirmation_days']));
        }
        if (in_array($_POST['mp_payout_mode'] ?? '', ['manual', 'auto'], true)) {
            set_platform_setting('mp_payout_mode', $_POST['mp_payout_mode']);
        }
        log_audit_action($adminUser['id'], 'mp_payout_settings_save', 'Updated marketplace commission/payout settings');
        flash('Settings saved.', 'success');
    }

    $redirectQs = http_build_query(array_filter([
        'tab' => $tab, 'period' => $period !== 'month' ? $period : null,
        'req_status' => $reqStatus !== 'all' ? $reqStatus : null, 'q' => $q ?: null,
    ]));
    header('Location: mp_payouts.php?' . $redirectQs);
    exit;
}

// ── Load data ───────────────────────────────────────────────────────────────
$reqWhere  = [];
$reqParams = [];
if (in_array($reqStatus, ['pending','approved','processing','paid','rejected','failed'], true)) {
    $reqWhere[] = 'pr.status = ?';
    $reqParams[] = $reqStatus;
}
if ($q !== '') {
    $reqWhere[] = '(ms.shop_name LIKE ? OR u.name LIKE ? OR u.email LIKE ?)';
    $like = '%' . $q . '%';
    array_push($reqParams, $like, $like, $like);
}
$reqWhereSql = $reqWhere ? 'WHERE ' . implode(' AND ', $reqWhere) : '';

$payoutPage    = max(1, (int)($_GET['page'] ?? 1));
$payoutPerPage = 30;
$payoutOffset  = ($payoutPage - 1) * $payoutPerPage;

$reqCountStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM mp_payout_requests pr JOIN mp_shops ms ON pr.shop_id = ms.id JOIN users u ON ms.user_id = u.id $reqWhereSql"
);
$reqCountStmt->execute($reqParams);
$payoutTotal      = (int)$reqCountStmt->fetchColumn();
$payoutTotalPages = max(1, (int)ceil($payoutTotal / $payoutPerPage));

$payoutStmt = $pdo->prepare(
    "SELECT pr.*, ms.shop_name, u.name AS owner_name, u.email AS owner_email
     FROM mp_payout_requests pr
     JOIN mp_shops ms ON pr.shop_id = ms.id
     JOIN users u ON ms.user_id = u.id
     $reqWhereSql
     ORDER BY pr.created_at DESC LIMIT $payoutPerPage OFFSET $payoutOffset"
);
$payoutStmt->execute($reqParams);

function mpp_payout_qstr(array $overrides = []): string {
    $base = [];
    foreach (['tab', 'period', 'req_status', 'q', 'page'] as $k) {
        if (isset($_GET[$k]) && $_GET[$k] !== '') $base[$k] = $_GET[$k];
    }
    $merged = array_filter(array_merge($base, $overrides), fn($v) => $v !== null);
    return 'mp_payouts.php?' . http_build_query($merged);
}
$payoutRequests = $payoutStmt->fetchAll();

$pendingCount  = (int)$pdo->query("SELECT COUNT(*) FROM mp_payout_requests WHERE status='pending'")->fetchColumn();

$totalPending   = (float)$pdo->query("SELECT COALESCE(SUM(pending_balance),0) FROM mp_shops")->fetchColumn();
$totalAvailable = (float)$pdo->query("SELECT COALESCE(SUM(available_balance),0) FROM mp_shops")->fetchColumn();
$totalPaidOut   = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM mp_payout_requests WHERE status='paid'")->fetchColumn();

$commissionPct  = get_platform_setting('mp_commission_percent', '10');
$confirmDays    = get_platform_setting('mp_payout_confirmation_days', '3');
$payoutMode     = get_platform_setting('mp_payout_mode', 'manual');

// ── Analytics (period-filtered) ────────────────────────────────────────────
if ($tab === 'analytics') {
    $dateFilter = match($period) {
        'today' => 'AND DATE(created_at) = CURDATE()',
        'week'  => 'AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)',
        'month' => 'AND MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())',
        'year'  => 'AND YEAR(created_at)=YEAR(NOW())',
        default => '',
    };

    $paidOutDateFilter = match($period) {
        'today' => 'AND DATE(paid_at) = CURDATE()',
        'week'  => 'AND paid_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)',
        'month' => 'AND MONTH(paid_at)=MONTH(NOW()) AND YEAR(paid_at)=YEAR(NOW())',
        'year'  => 'AND YEAR(paid_at)=YEAR(NOW())',
        default => '',
    };

    $anCommission = (float)$pdo->query("SELECT COALESCE(SUM(commission_amount),0) FROM mp_orders WHERE payment_status IN ('paid','refunded') $dateFilter")->fetchColumn();
    $anGmv        = (float)$pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM mp_orders WHERE payment_status IN ('paid','refunded') $dateFilter")->fetchColumn();
    $anOrderCount = (int)$pdo->query("SELECT COUNT(*) FROM mp_orders WHERE payment_status IN ('paid','refunded') $dateFilter")->fetchColumn();
    $anRefunded   = (float)$pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM mp_orders WHERE payment_status='refunded' $dateFilter")->fetchColumn();
    $anPaidOut    = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM mp_payout_requests WHERE status='paid' $paidOutDateFilter")->fetchColumn();

    $anStatusCounts = ['pending'=>0,'approved'=>0,'processing'=>0,'paid'=>0,'rejected'=>0,'failed'=>0];
    foreach ($pdo->query("SELECT status, COUNT(*) AS cnt FROM mp_payout_requests GROUP BY status")->fetchAll() as $row) {
        $anStatusCounts[$row['status']] = (int)$row['cnt'];
    }

    // Daily commission — fixed 30-day window regardless of period pill, same
    // convention as the other admin analytics charts in this app.
    $anDaily = $pdo->query(
        "SELECT DATE(created_at) AS d, SUM(commission_amount) AS rev
         FROM mp_orders
         WHERE payment_status IN ('paid','refunded') AND created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
         GROUP BY DATE(created_at)"
    )->fetchAll();
    $anDailyMap = array_column($anDaily, 'rev', 'd');
    $anDailyLabels = $anDailyRevenue = [];
    for ($i = 29; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-{$i} days"));
        $anDailyLabels[] = date('d M', strtotime($d));
        $anDailyRevenue[] = (float)($anDailyMap[$d] ?? 0);
    }

    // mp_shops also has a created_at column, so the bare $dateFilter would be an
    // ambiguous-column error here now that this query joins both tables — qualify it.
    $topShopsDateFilter = str_replace('created_at', 'mo.created_at', $dateFilter);
    $anTopShops = $pdo->query(
        "SELECT ms.shop_name, SUM(mo.commission_amount) AS commission, COUNT(*) AS orders
         FROM mp_orders mo JOIN mp_shops ms ON mo.shop_id = ms.id
         WHERE mo.payment_status='paid' $topShopsDateFilter
         GROUP BY mo.shop_id ORDER BY commission DESC LIMIT 5"
    )->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Seller Payouts — Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .mp-shell { max-width:1100px; margin:0 auto; padding:18px 16px 60px; }
        .mp-stats { display:grid; grid-template-columns:repeat(auto-fill,minmax(150px,1fr)); gap:10px; margin-bottom:20px; }
        .mp-stat  { background:var(--surface); border:1px solid var(--border); border-radius:12px; padding:12px; text-align:center; }
        .mp-stat strong { display:block; font-size:1.35rem; font-weight:900; color:var(--primary,#0f766e); line-height:1.1; }
        .mp-stat span   { font-size:.7rem; color:var(--text-muted,#6b7280); }
        .mp-tabs { display:flex; gap:5px; flex-wrap:wrap; border-bottom:1px solid var(--border); padding-bottom:12px; margin-bottom:18px; }
        .mp-tab  { padding:7px 16px; border-radius:8px; font-size:.82rem; font-weight:700; text-decoration:none; background:var(--surface); border:1px solid var(--border); color:var(--text-muted,#6b7280); }
        .mp-tab.active { background:var(--primary-soft,#d1fae5); border-color:var(--primary,#0f766e); color:var(--primary,#0f766e); }
        .mp-table { width:100%; border-collapse:collapse; font-size:.84rem; }
        .mp-table th { padding:9px 12px; text-align:left; font-size:.72rem; font-weight:800; text-transform:uppercase; letter-spacing:.05em; color:var(--text-muted,#6b7280); border-bottom:1px solid var(--border); background:var(--surface-muted,#f9fafb); }
        .mp-table td { padding:10px 12px; border-bottom:1px solid var(--border,#f1f5f9); vertical-align:middle; }
        .mp-table tr:last-child td { border-bottom:none; }
        label { font-weight:600; font-size:.86rem; display:block; margin-bottom:4px; }
        .form-group { margin-bottom:12px; }
        .mp-set-section { background:var(--surface); border:1px solid var(--border); border-radius:14px; padding:18px; margin-bottom:16px; }
        .mp-set-title { font-size:.74rem; font-weight:800; text-transform:uppercase; letter-spacing:.07em; color:var(--text-muted,#6b7280); margin:0 0 14px; }
        .mp-grid2 { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
        @media(max-width:520px){ .mp-stats { grid-template-columns:repeat(2,1fr); } .mp-grid2 { grid-template-columns:1fr; } }
        .pagination { display:flex; gap:4px; flex-wrap:wrap; align-items:center; margin-top:14px; }
        .pagination a, .pagination span { padding:5px 10px; border-radius:6px; border:1px solid var(--border); text-decoration:none; font-size:.82rem; color:var(--text); }
        .pagination a:hover { background:var(--surface-muted,#f9fafb); }
        .pagination .current { background:var(--primary,#0f766e); color:#fff; border-color:var(--primary,#0f766e); }
    </style>
</head>
<body>

<header class="topbar">
    <a href="index.php" class="button button-secondary button-small">← Dashboard</a>
    <h1 style="margin:0;font-size:1rem;font-weight:800;">🏪 Seller Payouts</h1>
    <a href="marketplace.php" class="button button-secondary button-small">Marketplace</a>
</header>

<main class="mp-shell">

    <?php if ($flash): ?>
    <div class="alert alert-<?php echo sanitize($flash['type']); ?>" style="margin-bottom:14px;"><?php echo sanitize($flash['message']); ?></div>
    <?php endif; ?>

    <div class="mp-stats">
        <div class="mp-stat"><strong>GH&#8373; <?php echo number_format($totalPending,2); ?></strong><span>Total Pending Balance</span></div>
        <div class="mp-stat"><strong style="color:#10b981;">GH&#8373; <?php echo number_format($totalAvailable,2); ?></strong><span>Total Available Balance</span></div>
        <div class="mp-stat"><strong style="color:#f59e0b;"><?php echo $pendingCount; ?></strong><span>Pending Requests</span></div>
        <div class="mp-stat"><strong>GH&#8373; <?php echo number_format($totalPaidOut,2); ?></strong><span>Total Paid Out</span></div>
    </div>

    <div class="mp-tabs">
        <a href="?tab=pending" class="mp-tab <?php echo $tab==='pending'?'active':''; ?>">
            💰 Requests <?php if ($pendingCount): ?><span style="background:#f59e0b;color:#fff;border-radius:10px;padding:0 6px;font-size:.65rem;margin-left:3px;"><?php echo $pendingCount; ?></span><?php endif; ?>
        </a>
        <a href="?tab=analytics" class="mp-tab <?php echo $tab==='analytics'?'active':''; ?>">📊 Analytics</a>
        <a href="?tab=settings" class="mp-tab <?php echo $tab==='settings'?'active':''; ?>">⚙️ Settings</a>
    </div>

    <!-- ═══ PAYOUT REQUESTS ═══ -->
    <?php if ($tab === 'pending'): ?>
    <form method="get" action="mp_payouts.php" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px;">
        <input type="hidden" name="tab" value="pending">
        <select name="req_status" onchange="this.form.submit()" style="padding:7px 10px;border:1px solid var(--border);border-radius:8px;font-size:.82rem;">
            <?php foreach (['all'=>'All Statuses','pending'=>'Pending','processing'=>'Processing','paid'=>'Paid','failed'=>'Failed','rejected'=>'Rejected'] as $v=>$l): ?>
            <option value="<?php echo $v; ?>" <?php echo $reqStatus===$v?'selected':''; ?>><?php echo $l; ?></option>
            <?php endforeach; ?>
        </select>
        <input type="text" name="q" value="<?php echo sanitize($q); ?>" placeholder="Search shop, owner, email…" style="flex:1;min-width:160px;padding:7px 10px;border:1px solid var(--border);border-radius:8px;font-size:.82rem;">
        <button type="submit" class="button button-secondary button-small">Filter</button>
        <?php if ($reqStatus !== 'all' || $q !== ''): ?><a href="?tab=pending" class="button button-secondary button-small">Clear</a><?php endif; ?>
    </form>
    <?php if ($payoutRequests): ?>
    <div style="overflow-x:auto;background:var(--surface);border:1px solid var(--border);border-radius:14px;">
    <table class="mp-table">
        <thead><tr><th>Shop</th><th>Amount</th><th>Pay To</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($payoutRequests as $r): ?>
        <tr>
            <td><strong><?php echo sanitize($r['shop_name']); ?></strong><br><span style="font-size:.74rem;color:var(--text-muted,#6b7280);"><?php echo sanitize($r['owner_name']); ?> — <?php echo sanitize($r['owner_email']); ?></span></td>
            <td><strong>GH&#8373; <?php echo number_format((float)$r['amount'],2); ?></strong></td>
            <td style="font-size:.8rem;"><?php echo sanitize($r['bank_name'] ?: 'Mobile Money'); ?><br>•••• <?php echo sanitize(substr((string)$r['account_number'], -4)); ?></td>
            <td>
                <?php $sc=['pending'=>['#fef3c7','#b45309'],'approved'=>['#dbeafe','#1d4ed8'],'processing'=>['#dbeafe','#1d4ed8'],'paid'=>['#d1fae5','#065f46'],'rejected'=>['#fee2e2','#c0392b'],'failed'=>['#fee2e2','#c0392b']]; [$bg,$col]=$sc[$r['status']]??['#f3f4f6','#6b7280']; ?>
                <span style="background:<?php echo $bg; ?>;color:<?php echo $col; ?>;font-size:.7rem;font-weight:800;padding:2px 8px;border-radius:10px;"><?php echo ucfirst($r['status']); ?></span>
                <?php if ($r['status']==='rejected' && $r['admin_notes']): ?><div style="font-size:.72rem;color:#c0392b;margin-top:3px;"><?php echo sanitize($r['admin_notes']); ?></div><?php endif; ?>
                <?php if ($r['status']==='failed' && $r['failure_reason']): ?><div style="font-size:.72rem;color:#c0392b;margin-top:3px;max-width:220px;"><?php echo sanitize($r['failure_reason']); ?></div><?php endif; ?>
            </td>
            <td style="font-size:.78rem;color:var(--text-muted,#6b7280);"><?php echo date('d M Y', strtotime($r['created_at'])); ?></td>
            <td>
                <div style="display:flex;gap:5px;flex-wrap:wrap;">
                <?php if ($r['status']==='pending'): ?>
                <form method="post" style="margin:0;"><?php echo csrf_field(); ?><input type="hidden" name="action" value="approve_and_pay_payout"><input type="hidden" name="payout_id" value="<?php echo $r['id']; ?>"><button type="submit" class="button button-primary button-small" onclick="return confirm('Send GH₵ <?php echo number_format((float)$r['amount'],2); ?> via Paystack now?');">✅ Approve &amp; Pay</button></form>
                <form method="post" style="margin:0;display:flex;gap:4px;">
                    <?php echo csrf_field(); ?><input type="hidden" name="action" value="reject_payout"><input type="hidden" name="payout_id" value="<?php echo $r['id']; ?>">
                    <input type="text" name="admin_notes" placeholder="Reason (optional)" style="width:120px;padding:5px 8px;font-size:.76rem;border:1px solid var(--border);border-radius:6px;">
                    <button type="submit" class="button button-small" style="background:#ef4444;color:#fff;border-color:transparent;" onclick="return confirm('Reject this request?');">Reject</button>
                </form>
                <form method="post" style="margin:0;"><?php echo csrf_field(); ?><input type="hidden" name="action" value="mark_paid_manually_payout"><input type="hidden" name="payout_id" value="<?php echo $r['id']; ?>"><button type="submit" class="button button-small" onclick="return confirm('Only confirm once you have ALREADY paid this seller yourself outside the system.');">Mark Paid Manually</button></form>
                <?php elseif ($r['status']==='approved'): ?>
                <form method="post" style="margin:0;"><?php echo csrf_field(); ?><input type="hidden" name="action" value="mark_paid_payout"><input type="hidden" name="payout_id" value="<?php echo $r['id']; ?>"><button type="submit" class="button button-primary button-small">Mark Paid</button></form>
                <?php elseif ($r['status']==='processing'): ?>
                <form method="post" style="margin:0;"><?php echo csrf_field(); ?><input type="hidden" name="action" value="check_transfer_status"><input type="hidden" name="payout_id" value="<?php echo $r['id']; ?>"><button type="submit" class="button button-secondary button-small">🔄 Check Status</button></form>
                <?php elseif ($r['status']==='failed'): ?>
                <form method="post" style="margin:0;"><?php echo csrf_field(); ?><input type="hidden" name="action" value="retry_payout"><input type="hidden" name="payout_id" value="<?php echo $r['id']; ?>"><button type="submit" class="button button-primary button-small">🔁 Retry via Paystack</button></form>
                <form method="post" style="margin:0;"><?php echo csrf_field(); ?><input type="hidden" name="action" value="mark_paid_manually_payout"><input type="hidden" name="payout_id" value="<?php echo $r['id']; ?>"><button type="submit" class="button button-small" onclick="return confirm('Only confirm once you have ALREADY paid this seller yourself outside the system.');">Mark Paid Manually</button></form>
                <?php endif; ?>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php if ($payoutTotalPages > 1): ?>
    <div class="pagination">
        <?php if ($payoutPage > 1): ?><a href="<?php echo mpp_payout_qstr(['page' => $payoutPage - 1]); ?>">‹ Prev</a><?php endif; ?>
        <?php
        $ppStart = max(1, $payoutPage - 3);
        $ppEnd   = min($payoutTotalPages, $payoutPage + 3);
        if ($ppStart > 1) echo '<span>…</span>';
        for ($pp = $ppStart; $pp <= $ppEnd; $pp++): ?>
            <?php if ($pp === $payoutPage): ?><span class="current"><?php echo $pp; ?></span>
            <?php else: ?><a href="<?php echo mpp_payout_qstr(['page' => $pp]); ?>"><?php echo $pp; ?></a><?php endif; ?>
        <?php endfor;
        if ($ppEnd < $payoutTotalPages) echo '<span>…</span>';
        ?>
        <?php if ($payoutPage < $payoutTotalPages): ?><a href="<?php echo mpp_payout_qstr(['page' => $payoutPage + 1]); ?>">Next ›</a><?php endif; ?>
        <span style="color:var(--text-muted,#6b7280);border:none;padding-left:4px;">Page <?php echo $payoutPage; ?> of <?php echo $payoutTotalPages; ?> (<?php echo $payoutTotal; ?> total)</span>
    </div>
    <?php endif; ?>
    <?php else: ?><div class="empty-state">No withdrawal requests match this filter.</div><?php endif; ?>
    <?php endif; ?>

    <!-- ═══ ANALYTICS ═══ -->
    <?php if ($tab === 'analytics'): ?>
    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:16px;">
        <?php foreach (['today'=>'Today','week'=>'7 Days','month'=>'This Month','year'=>'This Year','all'=>'All Time'] as $v=>$l): ?>
        <a href="?tab=analytics&period=<?php echo $v; ?>" class="button <?php echo $period===$v?'button-primary':'button-secondary'; ?> button-small"><?php echo $l; ?></a>
        <?php endforeach; ?>
    </div>

    <div class="mp-stats" style="margin-bottom:16px;">
        <div class="mp-stat"><strong>GH&#8373; <?php echo number_format($anCommission,2); ?></strong><span>Commission Earned</span></div>
        <div class="mp-stat"><strong>GH&#8373; <?php echo number_format($anGmv,2); ?></strong><span>Gross Sales (GMV)</span></div>
        <div class="mp-stat"><strong><?php echo number_format($anOrderCount); ?></strong><span>Paid Orders</span></div>
        <div class="mp-stat"><strong style="color:#ef4444;">GH&#8373; <?php echo number_format($anRefunded,2); ?></strong><span>Refunded</span></div>
        <div class="mp-stat"><strong style="color:#10b981;">GH&#8373; <?php echo number_format($anPaidOut,2); ?></strong><span>Paid Out to Sellers</span></div>
    </div>

    <div class="mp-set-section">
        <p class="mp-set-title">Commission Revenue — Last 30 Days</p>
        <div style="position:relative;height:220px;"><canvas id="mp-commission-chart"></canvas></div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
        <div class="mp-set-section" style="margin-bottom:0;">
            <p class="mp-set-title">Withdrawal Requests by Status</p>
            <?php foreach (['pending'=>'#f59e0b','processing'=>'#3b82f6','paid'=>'#10b981','rejected'=>'#ef4444','failed'=>'#ef4444'] as $st=>$color): ?>
            <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--border);font-size:.86rem;">
                <span><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?php echo $color; ?>;margin-right:6px;"></span><?php echo ucfirst($st); ?></span>
                <strong><?php echo $anStatusCounts[$st]; ?></strong>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="mp-set-section" style="margin-bottom:0;">
            <p class="mp-set-title">Top Shops by Commission (this period)</p>
            <?php if (!$anTopShops): ?>
            <p class="meta">No sales in this period.</p>
            <?php else: ?>
            <?php foreach ($anTopShops as $ts): ?>
            <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--border);font-size:.86rem;">
                <span><?php echo sanitize($ts['shop_name']); ?> <span style="color:var(--text-muted,#6b7280);">(<?php echo (int)$ts['orders']; ?> orders)</span></span>
                <strong>GH&#8373; <?php echo number_format((float)$ts['commission'],2); ?></strong>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script>
    (function () {
        var ctx = document.getElementById('mp-commission-chart');
        if (!ctx) return;
        var style = getComputedStyle(document.documentElement);
        var primary = style.getPropertyValue('--primary').trim() || '#0f766e';
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($anDailyLabels); ?>,
                datasets: [{
                    label: 'Commission (GHS)',
                    data: <?php echo json_encode($anDailyRevenue); ?>,
                    backgroundColor: primary,
                    borderRadius: 4,
                    maxBarThickness: 18
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { display: false }, ticks: { maxTicksLimit: 8 } },
                    y: { beginAtZero: true, grid: { color: 'rgba(128,128,128,.15)' } }
                }
            }
        });
    })();
    </script>
    <?php endif; ?>

    <!-- ═══ SETTINGS ═══ -->
    <?php if ($tab === 'settings'): ?>
    <form method="post" action="mp_payouts.php?tab=settings">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="save_settings">
        <div class="mp-set-section">
            <p class="mp-set-title">Commission &amp; Payout Timing</p>
            <div class="mp-grid2">
                <div class="form-group">
                    <label>Platform commission (%)</label>
                    <input type="number" name="mp_commission_percent" min="0" max="100" step="0.5" value="<?php echo sanitize($commissionPct); ?>">
                    <p style="font-size:.74rem;color:var(--text-muted,#6b7280);margin-top:3px;">Taken from every paid marketplace order before crediting the seller.</p>
                </div>
                <div class="form-group">
                    <label>Payout confirmation window (days)</label>
                    <input type="number" name="mp_payout_confirmation_days" min="0" value="<?php echo sanitize($confirmDays); ?>">
                    <p style="font-size:.74rem;color:var(--text-muted,#6b7280);margin-top:3px;">Days after delivery before funds move from Pending to Available balance.</p>
                </div>
            </div>
        </div>
        <div class="mp-set-section">
            <p class="mp-set-title">Payout Method</p>
            <div style="display:flex;gap:16px;margin-bottom:10px;">
                <label><input type="radio" name="mp_payout_mode" value="manual" <?php echo $payoutMode !== 'auto' ? 'checked' : ''; ?>> Manual — admin approves each withdrawal</label>
                <label><input type="radio" name="mp_payout_mode" value="auto" <?php echo $payoutMode === 'auto' ? 'checked' : ''; ?>> Automatic — Paystack pays instantly</label>
            </div>
            <p style="font-size:.74rem;color:var(--text-muted,#6b7280);">Either way, Paystack does the actual transfer — this only decides whether a human approves first. For true "zero-touch" automatic payouts, "Finalize Transfers with OTP" must be disabled on your Paystack account (Settings → Preferences) — otherwise Paystack will hold each transfer for manual OTP finalization in their dashboard.</p>
        </div>
        <button type="submit" class="button button-primary">Save Settings</button>
    </form>

    <div class="mp-set-section">
        <p class="mp-set-title">Bank &amp; Mobile Money List</p>
        <p style="font-size:.74rem;color:var(--text-muted,#6b7280);margin-bottom:10px;">Refresh the list of banks/MoMo networks sellers can choose from when setting up their payout account.</p>
        <form method="post" style="margin:0;"><?php echo csrf_field(); ?><input type="hidden" name="action" value="sync_banks"><button type="submit" class="button button-secondary">Sync Banks from Paystack</button></form>
    </div>
    <?php endif; ?>

</main>
</body>
</html>
