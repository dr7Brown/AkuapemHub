<?php
/**
 * My Reward Claims — the user's own claim history. Users can only ever see
 * their own claims (WHERE user_id=$user['id'] on every query) and can only
 * cancel a claim while it's still 'pending' (via user_cancel_reward_claim()).
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/modules/referrals/service.php';
require_once __DIR__ . '/modules/rewards/service.php';

require_login();
$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_claim') {
    csrf_check();
    $result = user_cancel_reward_claim((int)$user['id'], (int)($_POST['claim_id'] ?? 0));
    flash($result['ok'] ? 'Claim cancelled and points returned.' : $result['error'], $result['ok'] ? 'success' : 'error');
    header('Location: my_reward_claims.php'); exit;
}

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$claims  = get_user_reward_claims((int)$user['id'], $perPage, ($page - 1) * $perPage);

$focusRef = trim($_GET['ref'] ?? '');
$statusLabels = reward_claim_status_labels();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>My Reward Claims — AkuapemConnect</title>
    <link rel="stylesheet" href="assets/css/style.css" />
    <style>
        .mc-card { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-sm); padding:14px 16px; margin-bottom:10px; cursor:pointer; }
        .mc-card.focus { border-color:var(--primary); box-shadow:0 0 0 2px var(--primary-soft); }
        .mc-head { display:flex; justify-content:space-between; align-items:flex-start; gap:8px; }
        .mc-title { font-weight:700; margin:0; }
        .mc-badge { display:inline-block; padding:2px 9px; border-radius:20px; font-size:0.7rem; font-weight:700; color:#fff; white-space:nowrap; }
        .mc-meta { font-size:0.8rem; color:var(--muted); margin-top:4px; }
        .mc-detail { display:none; margin-top:12px; padding-top:12px; border-top:1px solid var(--border); font-size:0.86rem; }
        .mc-detail dt { font-size:0.72rem; color:var(--muted); text-transform:uppercase; margin-top:8px; }
        .mc-detail dt:first-child { margin-top:0; }
        .mc-detail dd { margin:2px 0 0; }
        .mr-empty { text-align:center; padding:30px 16px; color:var(--muted); background:var(--surface-muted); border-radius:var(--radius-sm); }
    </style>
</head>
<body class="has-bottom-nav">
    <header class="app-topbar">
        <a href="my_rewards.php" class="brand" style="text-decoration:none;">‹ My Rewards</a>
        <span style="font-weight:600;">My Reward Claims</span>
    </header>
    <main class="page-shell small-shell" style="padding-bottom:80px;">
        <?php foreach (get_flashes() as $msg): ?>
            <div class="alert alert-<?php echo sanitize($msg['type']); ?>"><?php echo $msg['message']; ?></div>
        <?php endforeach; ?>

        <?php if (!$claims): ?>
        <div class="mr-empty">You haven't claimed any rewards yet. <a href="my_rewards.php">View available rewards →</a></div>
        <?php else: foreach ($claims as $c):
            $details = json_decode($c['claim_details'] ?? '[]', true) ?: [];
            $isFocus = $focusRef !== '' && strcasecmp($focusRef, $c['reference_code']) === 0;
        ?>
        <div class="mc-card<?php echo $isFocus ? ' focus' : ''; ?>" onclick="this.querySelector('.mc-detail').style.display = this.querySelector('.mc-detail').style.display === 'block' ? 'none' : 'block';">
            <div class="mc-head">
                <div>
                    <p class="mc-title"><?php echo sanitize($c['milestone_title']); ?></p>
                    <p class="mc-meta"><?php echo number_format((int)$c['points_locked']); ?> points · <?php echo date('d M Y', strtotime($c['created_at'])); ?> · <span style="font-family:monospace;"><?php echo sanitize($c['reference_code']); ?></span></p>
                </div>
                <span class="mc-badge" style="background:<?php echo reward_claim_status_color($c['status']); ?>;"><?php echo $statusLabels[$c['status']]; ?></span>
            </div>
            <div class="mc-detail"<?php echo $isFocus ? ' style="display:block;"' : ''; ?>>
                <dt>Claim Reference</dt><dd style="font-family:monospace;"><?php echo sanitize($c['reference_code']); ?></dd>
                <?php if ($details): ?>
                <dt>Submitted Information</dt>
                <dd>
                    <?php foreach ($details as $k => $v): ?>
                        <div><?php echo sanitize(ucwords(str_replace('_',' ',$k))); ?>: <strong><?php echo sanitize((string)$v); ?></strong></div>
                    <?php endforeach; ?>
                </dd>
                <?php endif; ?>
                <?php if ($c['status'] === 'rejected'): ?>
                <dt>Rejection Reason</dt><dd><?php echo sanitize($c['rejection_reason']); ?></dd>
                <?php if ($c['admin_note']): ?><dt>Message from Admin</dt><dd><?php echo sanitize($c['admin_note']); ?></dd><?php endif; ?>
                <?php endif; ?>
                <?php if ($c['status'] === 'fulfilled'): ?>
                <dt>Fulfilled</dt><dd><?php echo date('d M Y', strtotime($c['fulfilled_at'])); ?><?php if ($c['fulfillment_note']): ?> — <?php echo sanitize($c['fulfillment_note']); ?><?php endif; ?></dd>
                <?php endif; ?>
                <?php if ($c['approved_at']): ?><dt>Approved</dt><dd><?php echo date('d M Y', strtotime($c['approved_at'])); ?></dd><?php endif; ?>
                <?php if ($c['status'] === 'pending'): ?>
                <form method="post" onclick="event.stopPropagation();" onsubmit="return confirm('Cancel this claim? Your points will be returned.');" style="margin-top:10px;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="cancel_claim">
                    <input type="hidden" name="claim_id" value="<?php echo $c['id']; ?>">
                    <button type="submit" class="button button-small" style="background:#fee2e2;color:#991b1b;border-color:#fca5a5;">Cancel Claim</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; endif; ?>

        <?php if (count($claims) === $perPage): ?>
        <div style="text-align:center;margin-top:12px;">
            <a href="?page=<?php echo $page+1; ?>" class="button button-secondary button-small">Load older claims</a>
        </div>
        <?php endif; ?>
    </main>
    <?php $activeNav = 'settings'; require __DIR__ . '/partials/bottom_nav.php'; ?>
</body>
</html>
