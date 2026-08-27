<?php
/**
 * My Rewards — user-facing milestone reward dashboard. Shows current points
 * balance (from the existing points module, unchanged) bucketed into
 * Available / Almost There / Locked milestones, plus a link to claim
 * history. See modules/rewards/service.php for get_user_reward_dashboard().
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/modules/referrals/service.php';
require_once __DIR__ . '/modules/rewards/service.php';

require_login();
$user = current_user();

if (!referrals_enabled() || !rewards_enabled()) {
    flash('The rewards programme is not currently active.', 'info');
    header('Location: jobs.php');
    exit;
}

$dash = get_user_reward_dashboard((int)$user['id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>My Rewards — AkuapemConnect</title>
    <link rel="stylesheet" href="assets/css/style.css" />
    <style>
        .mr-hero { background:var(--primary); color:#fff; border-radius:var(--radius); padding:24px; margin-bottom:20px; text-align:center; }
        .mr-hero .pts { font-size:2.6rem; font-weight:800; letter-spacing:-1px; }
        .mr-hero .lbl { font-size:0.88rem; opacity:0.85; margin:2px 0 12px; }
        .mr-hero .locked { font-size:0.8rem; opacity:0.85; }
        .mr-section-title { font-size:0.85rem; font-weight:800; text-transform:uppercase; letter-spacing:.04em; color:var(--muted); margin:22px 0 10px; }
        .mr-card { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-sm); padding:16px; margin-bottom:12px; }
        .mr-card.available { border-color:var(--primary); }
        .mr-card-title { font-weight:700; font-size:1rem; margin:0 0 4px; }
        .mr-card-reward { color:var(--primary); font-weight:700; }
        .mr-progress-track { background:var(--surface-muted); border-radius:20px; height:10px; overflow:hidden; margin:10px 0 6px; }
        .mr-progress-fill { background:var(--primary); height:100%; border-radius:20px; }
        .mr-progress-text { font-size:0.8rem; color:var(--muted); }
        .mr-lock-reason { font-size:0.82rem; color:var(--muted); margin-top:6px; }
        .mr-empty { text-align:center; padding:30px 16px; color:var(--muted); background:var(--surface-muted); border-radius:var(--radius-sm); }
        .mr-claim-btn { margin-top:10px; }
    </style>
</head>
<body class="has-bottom-nav">
    <header class="app-topbar">
        <a href="referrals.php" class="brand" style="text-decoration:none;">‹ Points</a>
        <span style="font-weight:600;">My Rewards</span>
        <a href="my_reward_claims.php" class="button button-secondary button-small">📜 Claim History</a>
    </header>
    <main class="page-shell" style="padding-bottom:80px;">
        <?php foreach (get_flashes() as $msg): ?>
            <div class="alert alert-<?php echo sanitize($msg['type']); ?>"><?php echo $msg['message']; ?></div>
        <?php endforeach; ?>

        <div class="mr-hero">
            <p class="pts">⭐ <?php echo number_format($dash['balance']); ?></p>
            <p class="lbl">Available points</p>
            <?php if ($dash['locked_points'] > 0): ?>
            <p class="locked">🔒 <?php echo number_format($dash['locked_points']); ?> points locked in pending claims</p>
            <?php endif; ?>
        </div>

        <!-- Available -->
        <p class="mr-section-title">🎉 Available Rewards</p>
        <?php if (!$dash['available']): ?>
        <div class="mr-empty">Keep earning points. Your next reward is on the way! <a href="referrals.php">Earn more points →</a></div>
        <?php else: foreach ($dash['available'] as $m): ?>
        <div class="mr-card available">
            <p class="mr-card-title">🎁 <?php echo sanitize($m['reward_description']); ?></p>
            <p class="meta"><?php echo sanitize($m['title']); ?> · Requires <?php echo number_format((int)$m['required_points']); ?> points</p>
            <a href="reward_claim_form.php?id=<?php echo $m['id']; ?>" class="button button-primary mr-claim-btn">CLAIM REWARD</a>
        </div>
        <?php endforeach; endif; ?>

        <!-- Almost there -->
        <?php if ($dash['almost_there']): ?>
        <p class="mr-section-title">🔥 Almost There</p>
        <?php foreach ($dash['almost_there'] as $m):
            $pct = min(100, (int)round(($dash['balance'] / max(1,$m['required_points'])) * 100));
            $toGo = max(0, (int)$m['required_points'] - $dash['balance']);
        ?>
        <div class="mr-card">
            <p class="mr-card-title"><?php echo sanitize($m['reward_description']); ?></p>
            <p class="meta"><?php echo sanitize($m['title']); ?></p>
            <div class="mr-progress-track"><div class="mr-progress-fill" style="width:<?php echo $pct; ?>%;"></div></div>
            <p class="mr-progress-text"><?php echo number_format($dash['balance']); ?> / <?php echo number_format((int)$m['required_points']); ?> points — <?php echo number_format($toGo); ?> to go</p>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>

        <!-- Locked -->
        <?php if ($dash['locked']): ?>
        <p class="mr-section-title">🔒 Locked</p>
        <?php foreach ($dash['locked'] as $m):
            $pct = min(100, (int)round(($dash['balance'] / max(1,$m['required_points'])) * 100));
        ?>
        <div class="mr-card">
            <p class="mr-card-title"><?php echo sanitize($m['reward_description']); ?></p>
            <p class="meta"><?php echo sanitize($m['title']); ?></p>
            <?php if (!empty($m['_reason'])): ?>
            <p class="mr-lock-reason">🔒 <?php echo sanitize($m['_reason']); ?></p>
            <?php else: ?>
            <div class="mr-progress-track"><div class="mr-progress-fill" style="width:<?php echo $pct; ?>%;"></div></div>
            <p class="mr-progress-text"><?php echo number_format($dash['balance']); ?> / <?php echo number_format((int)$m['required_points']); ?> points</p>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>

        <?php if (!$dash['available'] && !$dash['almost_there'] && !$dash['locked']): ?>
        <div class="mr-empty">No milestone rewards are currently available.</div>
        <?php endif; ?>
    </main>
    <?php $activeNav = 'settings'; require __DIR__ . '/partials/bottom_nav.php'; ?>
</body>
</html>
