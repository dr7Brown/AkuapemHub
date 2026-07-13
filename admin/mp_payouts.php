<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';

require_login();
if (!is_admin()) { header('Location: index.php'); exit; }

$adminUser = current_user();
$tab       = $_GET['tab'] ?? 'pending';
$flash     = get_flash();

// ── POST: payout review actions ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $postAction = $_POST['action'] ?? '';

    if ($postAction === 'approve_payout' && !empty($_POST['payout_id'])) {
        $pid = (int)$_POST['payout_id'];
        $req = $pdo->prepare("SELECT * FROM mp_payout_requests WHERE id=? AND status='pending'");
        $req->execute([$pid]);
        $req = $req->fetch();
        if ($req) {
            // Atomic — only deduct if the shop still has enough available balance
            $upd = $pdo->prepare("UPDATE mp_shops SET available_balance = available_balance - ? WHERE id=? AND available_balance >= ?");
            $upd->execute([$req['amount'], $req['shop_id'], $req['amount']]);
            if ($upd->rowCount() > 0) {
                $pdo->prepare("UPDATE mp_payout_requests SET status='approved', reviewed_by=?, reviewed_at=NOW() WHERE id=?")
                    ->execute([$adminUser['id'], $pid]);
                $pdo->prepare("INSERT INTO mp_wallet_transactions (shop_id, payout_id, type, amount, created_at) VALUES (?,?,?,?,NOW())")
                    ->execute([$req['shop_id'], $pid, 'withdrawal', $req['amount']]);
                $shopOwner = $pdo->prepare('SELECT user_id FROM mp_shops WHERE id=?');
                $shopOwner->execute([$req['shop_id']]);
                if ($uid = $shopOwner->fetchColumn()) {
                    notify_user((int)$uid, 'Withdrawal Approved ✅',
                        'Your withdrawal request of GH₵ ' . number_format($req['amount'], 2) . ' has been approved and will be paid out shortly.',
                        'success', 'seller_dashboard.php?tab=wallet');
                }
                log_audit_action($adminUser['id'], 'mp_payout_approve', "Approved payout #$pid (GHS " . number_format($req['amount'],2) . ")");
                flash('Payout approved.', 'success');
            } else {
                flash('Shop no longer has enough available balance for this payout.', 'error');
            }
        }
    }

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
        log_audit_action($adminUser['id'], 'mp_payout_settings_save', 'Updated marketplace commission/payout settings');
        flash('Settings saved.', 'success');
    }

    header('Location: mp_payouts.php?tab=' . $tab);
    exit;
}

// ── Load data ───────────────────────────────────────────────────────────────
$payoutRequests = $pdo->query(
    "SELECT pr.*, ms.shop_name, u.name AS owner_name, u.email AS owner_email
     FROM mp_payout_requests pr
     JOIN mp_shops ms ON pr.shop_id = ms.id
     JOIN users u ON ms.user_id = u.id
     ORDER BY pr.created_at DESC LIMIT 100"
)->fetchAll();

$pendingCount  = count(array_filter($payoutRequests, fn($r) => $r['status'] === 'pending'));
$approvedCount = count(array_filter($payoutRequests, fn($r) => $r['status'] === 'approved'));

$totalPending   = (float)$pdo->query("SELECT COALESCE(SUM(pending_balance),0) FROM mp_shops")->fetchColumn();
$totalAvailable = (float)$pdo->query("SELECT COALESCE(SUM(available_balance),0) FROM mp_shops")->fetchColumn();
$totalPaidOut   = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM mp_payout_requests WHERE status='paid'")->fetchColumn();

$commissionPct  = get_platform_setting('mp_commission_percent', '10');
$confirmDays    = get_platform_setting('mp_payout_confirmation_days', '3');
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
        <a href="?tab=settings" class="mp-tab <?php echo $tab==='settings'?'active':''; ?>">⚙️ Settings</a>
    </div>

    <!-- ═══ PAYOUT REQUESTS ═══ -->
    <?php if ($tab === 'pending'): ?>
    <?php if ($payoutRequests): ?>
    <div style="overflow-x:auto;background:var(--surface);border:1px solid var(--border);border-radius:14px;">
    <table class="mp-table">
        <thead><tr><th>Shop</th><th>Amount</th><th>MoMo</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($payoutRequests as $r): ?>
        <tr>
            <td><strong><?php echo sanitize($r['shop_name']); ?></strong><br><span style="font-size:.74rem;color:var(--text-muted,#6b7280);"><?php echo sanitize($r['owner_name']); ?> — <?php echo sanitize($r['owner_email']); ?></span></td>
            <td><strong>GH&#8373; <?php echo number_format((float)$r['amount'],2); ?></strong></td>
            <td style="font-size:.8rem;"><?php echo sanitize($r['momo_number']); ?></td>
            <td>
                <?php $sc=['pending'=>['#fef3c7','#b45309'],'approved'=>['#dbeafe','#1d4ed8'],'paid'=>['#d1fae5','#065f46'],'rejected'=>['#fee2e2','#c0392b']]; [$bg,$col]=$sc[$r['status']]??['#f3f4f6','#6b7280']; ?>
                <span style="background:<?php echo $bg; ?>;color:<?php echo $col; ?>;font-size:.7rem;font-weight:800;padding:2px 8px;border-radius:10px;"><?php echo ucfirst($r['status']); ?></span>
                <?php if ($r['status']==='rejected' && $r['admin_notes']): ?><div style="font-size:.72rem;color:#c0392b;margin-top:3px;"><?php echo sanitize($r['admin_notes']); ?></div><?php endif; ?>
            </td>
            <td style="font-size:.78rem;color:var(--text-muted,#6b7280);"><?php echo date('d M Y', strtotime($r['created_at'])); ?></td>
            <td>
                <div style="display:flex;gap:5px;flex-wrap:wrap;">
                <?php if ($r['status']==='pending'): ?>
                <form method="post" style="margin:0;"><?php echo csrf_field(); ?><input type="hidden" name="action" value="approve_payout"><input type="hidden" name="payout_id" value="<?php echo $r['id']; ?>"><button type="submit" class="button button-primary button-small" onclick="return confirm('Approve this withdrawal? GH₵ <?php echo number_format((float)$r['amount'],2); ?> will be deducted from the shop\'s available balance.');">Approve</button></form>
                <form method="post" style="margin:0;display:flex;gap:4px;">
                    <?php echo csrf_field(); ?><input type="hidden" name="action" value="reject_payout"><input type="hidden" name="payout_id" value="<?php echo $r['id']; ?>">
                    <input type="text" name="admin_notes" placeholder="Reason (optional)" style="width:120px;padding:5px 8px;font-size:.76rem;border:1px solid var(--border);border-radius:6px;">
                    <button type="submit" class="button button-small" style="background:#ef4444;color:#fff;border-color:transparent;" onclick="return confirm('Reject this request?');">Reject</button>
                </form>
                <?php elseif ($r['status']==='approved'): ?>
                <form method="post" style="margin:0;"><?php echo csrf_field(); ?><input type="hidden" name="action" value="mark_paid_payout"><input type="hidden" name="payout_id" value="<?php echo $r['id']; ?>"><button type="submit" class="button button-primary button-small">Mark Paid</button></form>
                <?php endif; ?>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php else: ?><div class="empty-state">No withdrawal requests yet.</div><?php endif; ?>
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
        <button type="submit" class="button button-primary">Save Settings</button>
    </form>
    <?php endif; ?>

</main>
</body>
</html>
