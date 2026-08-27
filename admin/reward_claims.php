<?php
/**
 * Admin → Rewards → Claims. Review queue for reward_claims — approve/reject/
 * process/fulfill via modules/rewards/service.php's guarded state machine
 * (never writes claim status directly).
 */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../modules/referrals/service.php';
require_once __DIR__ . '/../modules/rewards/service.php';

require_login();
if (!is_admin_or_manager()) { header('Location: index.php'); exit; }
require_mod_permission('manage_rewards');
$adminUser = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action  = $_POST['action'] ?? '';
    $claimId = (int)($_POST['claim_id'] ?? 0);
    $result  = ['ok' => false, 'error' => 'Unknown action.'];

    if ($action === 'under_review') $result = admin_mark_claim_under_review($claimId, (int)$adminUser['id']);
    elseif ($action === 'approve')  $result = admin_approve_reward_claim($claimId, (int)$adminUser['id']);
    elseif ($action === 'processing') $result = admin_mark_claim_processing($claimId, (int)$adminUser['id'], trim($_POST['note'] ?? '') ?: null);
    elseif ($action === 'fulfilled') $result = admin_mark_claim_fulfilled($claimId, (int)$adminUser['id'], trim($_POST['note'] ?? '') ?: null, trim($_POST['fulfillment_reference'] ?? '') ?: null);
    elseif ($action === 'reject') {
        $reason = $_POST['rejection_reason'] ?? '';
        $labels = reward_rejection_reasons();
        $reasonLabel = $labels[$reason] ?? 'Other';
        if (!isset($labels[$reason])) { $result = ['ok' => false, 'error' => 'Please select a valid rejection reason.']; }
        else $result = admin_reject_reward_claim($claimId, (int)$adminUser['id'], $reasonLabel, trim($_POST['admin_note'] ?? '') ?: null);
    }

    flash($result['ok'] ? 'Claim updated.' : $result['error'], $result['ok'] ? 'success' : 'error');
    header('Location: reward_claims.php?' . http_build_query(array_filter(['status' => $_GET['status'] ?? null, 'q' => $_GET['q'] ?? null, 'view' => $claimId]))); exit;
}

$stats = get_reward_claim_stats();
$statusFilter = $_GET['status'] ?? 'all';
$search = trim($_GET['q'] ?? '');
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset  = ($page - 1) * $perPage;

$where  = ['1=1'];
$params = [];
if ($statusFilter !== 'all' && isset(reward_claim_status_labels()[$statusFilter])) {
    $where[] = 'rc.status = ?';
    $params[] = $statusFilter;
}
if ($search !== '') {
    $where[] = '(rc.reference_code LIKE ? OR u.name LIKE ? OR u.email LIKE ? OR rm.title LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
}
$whereClause = implode(' AND ', $where);

$countSt = $pdo->prepare("SELECT COUNT(*) FROM reward_claims rc JOIN users u ON rc.user_id=u.id JOIN reward_milestones rm ON rc.milestone_id=rm.id WHERE $whereClause");
$countSt->execute($params);
$total = (int)$countSt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));

$listSt = $pdo->prepare(
    "SELECT rc.*, u.name AS user_name, u.email AS user_email, rm.title AS milestone_title
     FROM reward_claims rc JOIN users u ON rc.user_id=u.id JOIN reward_milestones rm ON rc.milestone_id=rm.id
     WHERE $whereClause ORDER BY rc.created_at DESC LIMIT $perPage OFFSET $offset"
);
$listSt->execute($params);
$claims = $listSt->fetchAll(PDO::FETCH_ASSOC);

$viewClaim = null;
if (!empty($_GET['view'])) $viewClaim = get_reward_claim((int)$_GET['view']);

function rcqs(array $overrides = []): string {
    $base = [];
    foreach (['status', 'q', 'page'] as $k) if (isset($_GET[$k]) && $_GET[$k] !== '') $base[$k] = $_GET[$k];
    $merged = array_filter(array_merge($base, $overrides), fn($v) => $v !== null);
    return 'reward_claims.php?' . http_build_query($merged);
}

$statusLabels = reward_claim_status_labels();
$rejectionReasons = reward_rejection_reasons();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Reward Claims — Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css" />
    <style>
        .rc-shell { max-width:960px; margin:0 auto; padding:18px 16px 60px; }
        .stat-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(110px,1fr)); gap:10px; margin-bottom:20px; }
        .stat-box { background:var(--surface-muted); border:1px solid var(--border); border-radius:var(--radius-sm); padding:12px 14px; }
        .stat-box .val { font-size:1.3rem; font-weight:700; color:var(--primary); margin:0 0 2px; }
        .stat-box .lbl { font-size:0.72rem; color:var(--muted); margin:0; }
        .rc-filter { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:14px; }
        .rc-filter a { padding:5px 12px; border-radius:20px; border:1px solid var(--border); font-size:.78rem; text-decoration:none; color:var(--muted); }
        .rc-filter a.active { background:var(--primary); color:#fff; border-color:var(--primary); }
        table.data-table { width:100%; border-collapse:collapse; font-size:0.85rem; }
        table.data-table th { text-align:left; padding:8px; border-bottom:2px solid var(--border); font-size:0.72rem; color:var(--muted); text-transform:uppercase; }
        table.data-table td { padding:8px; border-bottom:1px solid var(--border); vertical-align:middle; }
        .rc-badge { display:inline-block; padding:2px 9px; border-radius:20px; font-size:0.7rem; font-weight:700; color:#fff; white-space:nowrap; }
        .rc-detail { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-sm); padding:18px; margin-bottom:20px; }
        .rc-detail dt { font-size:0.72rem; color:var(--muted); text-transform:uppercase; margin-top:10px; }
        .rc-detail dd { margin:2px 0 0; font-size:0.92rem; }
        .rc-actions { display:flex; gap:8px; flex-wrap:wrap; margin-top:16px; }
        .pagination { display:flex; gap:4px; flex-wrap:wrap; align-items:center; margin-top:14px; }
        .pagination a, .pagination span { padding:5px 10px; border-radius:6px; border:1px solid var(--border); text-decoration:none; font-size:.82rem; color:var(--text); }
        .pagination .current { background:var(--primary); color:#fff; border-color:var(--primary); }
        .rwd-module-nav { display:flex; gap:6px; margin-bottom:16px; flex-wrap:wrap; }
        .rwd-module-nav a { padding:6px 14px; border-radius:20px; background:var(--surface-muted,#f3f4f6); border:1px solid var(--border); font-size:.82rem; font-weight:700; text-decoration:none; color:var(--text-muted,#6b7280); }
        .rwd-module-nav a.active { background:var(--primary,#0f766e); color:#fff; border-color:var(--primary,#0f766e); }
    </style>
</head>
<body>
    <header class="topbar">
        <a href="index.php" style="text-decoration:none;color:inherit;font-weight:700;">‹ Admin</a>
        <span style="font-weight:700;margin-left:12px;">🎁 Reward Claims</span>
    </header>
    <main class="rc-shell">
        <?php foreach (get_flashes() as $msg): ?>
            <div class="alert alert-<?php echo sanitize($msg['type']); ?>"><?php echo sanitize($msg['message']); ?></div>
        <?php endforeach; ?>

        <div class="rwd-module-nav">
            <a href="referrals.php">🔗 Referrals &amp; Points</a>
            <a href="reward_milestones.php">🏁 Reward Milestones</a>
            <a href="reward_claims.php" class="active">🎁 Reward Claims</a>
        </div>

        <div class="stat-grid">
            <div class="stat-box"><p class="val"><?php echo $stats['pending']; ?></p><p class="lbl">Pending</p></div>
            <div class="stat-box"><p class="val"><?php echo $stats['under_review']; ?></p><p class="lbl">Under Review</p></div>
            <div class="stat-box"><p class="val"><?php echo $stats['approved']; ?></p><p class="lbl">Approved</p></div>
            <div class="stat-box"><p class="val"><?php echo $stats['processing']; ?></p><p class="lbl">Processing</p></div>
            <div class="stat-box"><p class="val"><?php echo $stats['fulfilled']; ?></p><p class="lbl">Fulfilled</p></div>
            <div class="stat-box"><p class="val"><?php echo $stats['rejected']; ?></p><p class="lbl">Rejected</p></div>
            <div class="stat-box"><p class="val"><?php echo number_format((int)$stats['points_redeemed']); ?></p><p class="lbl">Points Redeemed</p></div>
        </div>

        <?php if ($viewClaim):
            $detailsArr = json_decode($viewClaim['claim_details'] ?? '[]', true) ?: [];
            $validNext = reward_claim_valid_transitions()[$viewClaim['status']] ?? [];
        ?>
        <div class="rc-detail">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:8px;">
                <div>
                    <h3 style="margin:0;">Claim <?php echo sanitize($viewClaim['reference_code']); ?></h3>
                    <span class="meta"><?php echo sanitize($viewClaim['user_name']); ?> (<?php echo sanitize($viewClaim['user_email']); ?>)</span>
                </div>
                <span class="rc-badge" style="background:<?php echo reward_claim_status_color($viewClaim['status']); ?>;"><?php echo $statusLabels[$viewClaim['status']]; ?></span>
            </div>
            <dl>
                <dt>Reward</dt><dd><?php echo sanitize($viewClaim['milestone_title']); ?> — <?php echo sanitize($viewClaim['reward_description']); ?></dd>
                <dt>Points Locked</dt><dd><?php echo number_format((int)$viewClaim['points_locked']); ?></dd>
                <dt>Submitted</dt><dd><?php echo date('d M Y, g:i a', strtotime($viewClaim['created_at'])); ?></dd>
                <?php if ($detailsArr): ?>
                <dt>Submitted Information</dt>
                <dd>
                    <?php foreach ($detailsArr as $k => $v): ?>
                        <div><strong><?php echo sanitize(ucwords(str_replace('_',' ',$k))); ?>:</strong> <?php echo sanitize((string)$v); ?></div>
                    <?php endforeach; ?>
                </dd>
                <?php endif; ?>
                <?php if ($viewClaim['approved_at']): ?><dt>Approved</dt><dd><?php echo date('d M Y, g:i a', strtotime($viewClaim['approved_at'])); ?> by <?php echo sanitize($viewClaim['approved_by_name'] ?? '—'); ?></dd><?php endif; ?>
                <?php if ($viewClaim['fulfilled_at']): ?><dt>Fulfilled</dt><dd><?php echo date('d M Y, g:i a', strtotime($viewClaim['fulfilled_at'])); ?><?php if ($viewClaim['fulfillment_reference']): ?> — Ref: <?php echo sanitize($viewClaim['fulfillment_reference']); ?><?php endif; ?><?php if ($viewClaim['fulfillment_note']): ?><br><?php echo sanitize($viewClaim['fulfillment_note']); ?><?php endif; ?></dd><?php endif; ?>
                <?php if ($viewClaim['rejected_at']): ?><dt>Rejected</dt><dd><?php echo date('d M Y, g:i a', strtotime($viewClaim['rejected_at'])); ?> — <?php echo sanitize($viewClaim['rejection_reason']); ?><?php if ($viewClaim['admin_note']): ?><br><?php echo sanitize($viewClaim['admin_note']); ?><?php endif; ?></dd><?php endif; ?>
            </dl>

            <div class="rc-actions">
                <?php if (in_array('under_review', $validNext, true)): ?>
                <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="under_review"><input type="hidden" name="claim_id" value="<?php echo $viewClaim['id']; ?>"><button class="button button-secondary button-small">Mark Under Review</button></form>
                <?php endif; ?>
                <?php if (in_array('approved', $validNext, true)): ?>
                <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="approve"><input type="hidden" name="claim_id" value="<?php echo $viewClaim['id']; ?>"><button class="button button-primary button-small">✅ Approve</button></form>
                <?php endif; ?>
                <?php if (in_array('processing', $validNext, true)): ?>
                <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="processing"><input type="hidden" name="claim_id" value="<?php echo $viewClaim['id']; ?>"><button class="button button-secondary button-small">⚙️ Mark Processing</button></form>
                <?php endif; ?>
                <?php if (in_array('fulfilled', $validNext, true)): ?>
                <form method="post" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
                    <?php echo csrf_field(); ?><input type="hidden" name="action" value="fulfilled"><input type="hidden" name="claim_id" value="<?php echo $viewClaim['id']; ?>">
                    <input type="text" name="fulfillment_reference" placeholder="Transaction reference" style="padding:6px 8px;border:1px solid var(--border);border-radius:6px;">
                    <input type="text" name="note" placeholder="Fulfillment note" style="padding:6px 8px;border:1px solid var(--border);border-radius:6px;">
                    <button class="button button-primary button-small">🎁 Mark Fulfilled</button>
                </form>
                <?php endif; ?>
                <?php if (in_array('rejected', $validNext, true)): ?>
                <form method="post" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
                    <?php echo csrf_field(); ?><input type="hidden" name="action" value="reject"><input type="hidden" name="claim_id" value="<?php echo $viewClaim['id']; ?>">
                    <select name="rejection_reason" required style="padding:6px 8px;border:1px solid var(--border);border-radius:6px;">
                        <option value="">Reason…</option>
                        <?php foreach ($rejectionReasons as $rv => $rl): ?><option value="<?php echo $rv; ?>"><?php echo sanitize($rl); ?></option><?php endforeach; ?>
                    </select>
                    <input type="text" name="admin_note" placeholder="Message to user (optional)" style="padding:6px 8px;border:1px solid var(--border);border-radius:6px;flex:1;min-width:160px;">
                    <button class="button button-small" style="background:#fee2e2;color:#991b1b;border-color:#fca5a5;">❌ Reject</button>
                </form>
                <?php endif; ?>
                <a href="reward_claims.php" class="button button-secondary button-small">Close</a>
            </div>
        </div>
        <?php endif; ?>

        <div class="rc-filter">
            <a href="<?php echo rcqs(['status'=>'all','page'=>null]); ?>" class="<?php echo $statusFilter==='all'?'active':''; ?>">All</a>
            <?php foreach ($statusLabels as $sv => $sl): ?>
            <a href="<?php echo rcqs(['status'=>$sv,'page'=>null]); ?>" class="<?php echo $statusFilter===$sv?'active':''; ?>"><?php echo $sl; ?></a>
            <?php endforeach; ?>
        </div>
        <form method="get" style="display:flex;gap:8px;margin-bottom:14px;">
            <?php if ($statusFilter !== 'all'): ?><input type="hidden" name="status" value="<?php echo sanitize($statusFilter); ?>"><?php endif; ?>
            <input type="text" name="q" value="<?php echo sanitize($search); ?>" placeholder="Search reference, user, or reward…" style="flex:1;padding:7px 10px;border:1px solid var(--border);border-radius:6px;">
            <button type="submit" class="button button-primary button-small">Search</button>
        </form>

        <?php if (!$claims): ?>
        <p class="meta" style="text-align:center;padding:30px 0;">No claims found.</p>
        <?php else: ?>
        <div style="overflow-x:auto;">
            <table class="data-table">
                <thead><tr><th>Reference</th><th>User</th><th>Reward</th><th>Points</th><th>Status</th><th>Date</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($claims as $c): ?>
                <tr>
                    <td style="font-family:monospace;font-size:0.82rem;"><?php echo sanitize($c['reference_code']); ?></td>
                    <td><?php echo sanitize($c['user_name']); ?></td>
                    <td><?php echo sanitize($c['milestone_title']); ?></td>
                    <td><?php echo number_format((int)$c['points_locked']); ?></td>
                    <td><span class="rc-badge" style="background:<?php echo reward_claim_status_color($c['status']); ?>;"><?php echo $statusLabels[$c['status']]; ?></span></td>
                    <td style="white-space:nowrap;font-size:0.8rem;"><?php echo date('d M Y', strtotime($c['created_at'])); ?></td>
                    <td><a href="<?php echo rcqs(['view'=>$c['id']]); ?>" class="button button-small button-secondary">Review</a></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?><a href="<?php echo rcqs(['page'=>$page-1]); ?>">‹ Prev</a><?php endif; ?>
            <?php for ($p = max(1,$page-3); $p <= min($totalPages,$page+3); $p++): ?>
                <?php if ($p === $page): ?><span class="current"><?php echo $p; ?></span><?php else: ?><a href="<?php echo rcqs(['page'=>$p]); ?>"><?php echo $p; ?></a><?php endif; ?>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?><a href="<?php echo rcqs(['page'=>$page+1]); ?>">Next ›</a><?php endif; ?>
            <span style="color:var(--muted);border:none;">Page <?php echo $page; ?> of <?php echo $totalPages; ?> (<?php echo $total; ?> total)</span>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </main>
</body>
</html>
