<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';

require_login();
if (!is_admin_or_manager()) { header('Location: ../jobs.php'); exit; }

$user  = current_user();
$modId = (int)$user['id'];
$error = '';
$flash = get_flash();

// Only managers (not admins) use this page to request rewards
// Admins can view via mod_performance.php

// Load stats
$allPts = (int)get_mod_points($modId, 'all');
try {
    $redSt = $pdo->prepare("SELECT COALESCE(SUM(points_used),0) FROM mod_rewards WHERE mod_id=? AND status IN('approved','paid')");
    $redSt->execute([$modId]); $redeemedPts = (int)$redSt->fetchColumn();
} catch(Exception $e){ $redeemedPts = 0; }
$balance = max(0, $allPts - $redeemedPts);

// Pending request check
try {
    $pendSt = $pdo->prepare("SELECT COUNT(*) FROM mod_rewards WHERE mod_id=? AND status='pending'");
    $pendSt->execute([$modId]); $hasPending = (int)$pendSt->fetchColumn() > 0;
} catch(Exception $e){ $hasPending = false; }

// Tier info
$tiers = [
    100  => (float)get_platform_setting('mod_reward_100pts',  '10.00'),
    500  => (float)get_platform_setting('mod_reward_500pts',  '60.00'),
    1000 => (float)get_platform_setting('mod_reward_1000pts','150.00'),
];

// Past reward history
$history = [];
try {
    $histSt = $pdo->prepare("SELECT * FROM mod_rewards WHERE mod_id=? ORDER BY created_at DESC LIMIT 20");
    $histSt->execute([$modId]); $history = $histSt->fetchAll();
} catch(Exception $e){}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $pointsToRedeem = (int)($_POST['points_redeem'] ?? 0);
    $method         = $_POST['payment_method'] ?? '';
    $mobi           = trim($_POST['mobi_number'] ?? '');

    if ($hasPending) $error = 'You already have a pending reward request. Wait for it to be processed.';
    elseif ($pointsToRedeem < 100) $error = 'Minimum redemption is 100 points.';
    elseif ($pointsToRedeem > $balance) $error = 'You only have ' . $balance . ' redeemable points.';
    elseif (!in_array($method, ['mtn_momo','telecel','airtel'], true)) $error = 'Select a valid payment method.';
    elseif (!$mobi) $error = 'Enter your mobile money number.';

    if (!$error) {
        $ghsValue = mod_points_to_ghs($pointsToRedeem);
        try {
            $pdo->prepare(
                'INSERT INTO mod_rewards (mod_id, reward_type, amount_ghs, points_used, mobi_number, status) VALUES (?,?,?,?,?,?)'
            )->execute([$modId, 'cash', $ghsValue, $pointsToRedeem, $mobi, 'pending']);
            log_audit_action($modId, 'mod_reward_request', "Requested reward: {$pointsToRedeem} pts = GHS ".number_format($ghsValue,2)." via $method ($mobi)");
            flash('Reward request submitted! An admin will process it shortly.', 'success');
        } catch(Exception $e){
            flash('Failed to submit. Please try again or contact admin.', 'error');
        }
        header('Location: mod_reward_request.php'); exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request Reward — Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .rr-shell { max-width:640px; margin:0 auto; padding:20px 16px 60px; }
        .rr-card  { background:var(--surface); border:1px solid var(--border); border-radius:14px; padding:20px; margin-bottom:14px; }
        .rr-title { font-size:.75rem; font-weight:800; text-transform:uppercase; letter-spacing:.07em; color:var(--text-muted,#6b7280); margin:0 0 14px; }
        .rr-stats { display:grid; grid-template-columns:repeat(3,1fr); gap:10px; margin-bottom:14px; }
        .rr-stat  { text-align:center; background:var(--surface-muted,#f8fafc); border-radius:10px; padding:10px; }
        .rr-stat strong { display:block; font-size:1.2rem; font-weight:900; color:var(--primary,#0f766e); }
        .rr-stat span   { font-size:.7rem; color:var(--text-muted,#6b7280); }
        .rr-tier  { display:flex; align-items:center; justify-content:space-between; padding:8px 0; border-bottom:1px solid var(--border); font-size:.85rem; }
        .rr-tier:last-child { border-bottom:none; }
        label { font-weight:600; font-size:.86rem; display:block; margin-bottom:4px; }
        .form-group { margin-bottom:12px; }
        .form-hint  { font-size:.74rem; color:var(--text-muted,#6b7280); margin-top:3px; }
        .rr-hist-row { display:flex; justify-content:space-between; align-items:center; padding:8px 0; border-bottom:1px solid var(--border); font-size:.83rem; gap:8px; flex-wrap:wrap; }
        .rr-hist-row:last-child { border-bottom:none; }
    </style>
</head>
<body>

<header class="topbar">
    <a href="index.php" class="button button-secondary button-small">← Dashboard</a>
    <h1 style="margin:0;font-size:1rem;font-weight:800;">💰 Request Reward</h1>
</header>

<main class="rr-shell">

    <?php if ($flash): ?>
    <div class="alert alert-<?php echo sanitize($flash['type']); ?>" style="margin-bottom:14px;"><?php echo sanitize($flash['message']); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="alert alert-error" style="margin-bottom:14px;"><?php echo sanitize($error); ?></div>
    <?php endif; ?>

    <!-- Balance summary -->
    <div class="rr-card">
        <p class="rr-title">Your Point Balance</p>
        <div class="rr-stats">
            <div class="rr-stat"><strong><?php echo number_format($allPts); ?></strong><span>Total Earned</span></div>
            <div class="rr-stat"><strong><?php echo number_format($redeemedPts); ?></strong><span>Redeemed</span></div>
            <div class="rr-stat"><strong style="color:#16a34a;"><?php echo number_format($balance); ?></strong><span>Available</span></div>
        </div>
        <div style="font-size:.86rem;color:var(--text-muted,#6b7280);">
            Your <?php echo number_format($balance); ?> available points = <strong style="color:var(--primary,#0f766e);">GHS <?php echo number_format(mod_points_to_ghs($balance),2); ?></strong>
        </div>
    </div>

    <!-- Tier table -->
    <div class="rr-card">
        <p class="rr-title">Reward Tiers</p>
        <?php foreach ($tiers as $pts => $ghs): ?>
        <div class="rr-tier">
            <span><?php echo number_format($pts); ?> points</span>
            <span style="font-weight:800;color:var(--primary,#0f766e);">GHS <?php echo number_format($ghs,2); ?></span>
            <span style="font-size:.76rem;color:var(--text-muted,#6b7280);"><?php echo $balance >= $pts ? '✅ Eligible' : "Need ".($pts-$balance)." more"; ?></span>
        </div>
        <?php endforeach; ?>
        <p style="font-size:.76rem;color:var(--text-muted,#6b7280);margin:10px 0 0;">Tiers stack: 350 pts = GHS <?php echo number_format(mod_points_to_ghs(350),2); ?> (3×100 pts)</p>
    </div>

    <!-- Request form -->
    <?php if ($hasPending): ?>
    <div style="background:#fef3c7;border:1px solid #f59e0b;border-radius:12px;padding:14px 16px;margin-bottom:14px;">
        <strong>⏳ Pending request</strong> — You have a reward request being reviewed. You can submit another once it's processed.
    </div>
    <?php elseif ($balance < 100): ?>
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:14px 16px;color:var(--text-muted,#6b7280);font-size:.86rem;">
        You need at least <strong>100 points</strong> to request a reward. You have <?php echo $balance; ?> points — earn <?php echo 100-$balance; ?> more!
    </div>
    <?php else: ?>
    <div class="rr-card">
        <p class="rr-title">Submit Reward Request</p>
        <form method="post" action="mod_reward_request.php">
            <?php echo csrf_field(); ?>
            <div class="form-group">
                <label>Points to Redeem</label>
                <input type="number" name="points_redeem" min="100" max="<?php echo $balance; ?>"
                       step="100" value="<?php echo min(1000, (int)floor($balance/100)*100); ?>"
                       id="pts-input" oninput="updateGhs()">
                <p class="form-hint">Min 100 pts. Max <?php echo number_format($balance); ?> pts.
                   Value: <strong id="ghs-val">GHS <?php echo number_format(mod_points_to_ghs(min(1000,(int)floor($balance/100)*100)),2); ?></strong>
                </p>
            </div>
            <div class="form-group">
                <label>Payment Method</label>
                <?php foreach (['mtn_momo'=>'MTN Mobile Money','telecel'=>'Telecel Cash','airtel'=>'AirtelTigo Money'] as $v=>$l): ?>
                <label style="display:flex;align-items:center;gap:8px;padding:5px 0;cursor:pointer;font-size:.88rem;font-weight:400;">
                    <input type="radio" name="payment_method" value="<?php echo $v; ?>" <?php echo $v==='mtn_momo'?'checked':''; ?>>
                    <?php echo sanitize($l); ?>
                </label>
                <?php endforeach; ?>
            </div>
            <div class="form-group">
                <label>Mobile Money Number *</label>
                <input type="tel" name="mobi_number" placeholder="e.g. 0244000000" required
                       value="<?php echo sanitize($user['phone']??''); ?>">
            </div>
            <button type="submit" class="button button-primary" style="width:100%;padding:13px;">
                Submit Reward Request
            </button>
            <p style="font-size:.76rem;color:var(--text-muted,#6b7280);text-align:center;margin-top:8px;">
                An admin will review and process your request within 1–3 business days.
            </p>
        </form>
    </div>
    <?php endif; ?>

    <!-- History -->
    <?php if ($history): ?>
    <div class="rr-card">
        <p class="rr-title">Request History</p>
        <?php foreach ($history as $r): ?>
        <div class="rr-hist-row">
            <div>
                <div style="font-weight:700;font-size:.86rem;"><?php echo number_format((int)$r['points_used']); ?> pts → GHS <?php echo number_format((float)$r['amount_ghs'],2); ?></div>
                <div style="font-size:.74rem;color:var(--text-muted,#6b7280);"><?php echo sanitize($r['mobi_number']??''); ?> · <?php echo date('d M Y', strtotime($r['created_at'])); ?></div>
            </div>
            <?php $sColors=['pending'=>['#fef3c7','#b45309'],'approved'=>['#dbeafe','#1d4ed8'],'paid'=>['#d1fae5','#065f46'],'rejected'=>['#fee2e2','#c0392b']]; [$bg,$col]=$sColors[$r['status']]??['#f3f4f6','#6b7280']; ?>
            <span style="background:<?php echo $bg; ?>;color:<?php echo $col; ?>;font-size:.7rem;font-weight:800;padding:2px 8px;border-radius:10px;"><?php echo ucfirst($r['status']); ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</main>

<script>
var tierData = <?php echo json_encode(array_map(fn($k,$v)=>['pts'=>$k,'ghs'=>$v], array_keys($tiers), array_values($tiers))); ?>;
function mod_points_to_ghs(pts) {
    var ghs = 0;
    tierData.slice().sort(function(a,b){return b.pts-a.pts;}).forEach(function(t){
        var times = Math.floor(pts / t.pts);
        ghs += times * t.ghs;
        pts -= times * t.pts;
    });
    return ghs;
}
function updateGhs() {
    var pts = parseInt(document.getElementById('pts-input').value) || 0;
    document.getElementById('ghs-val').textContent = 'GHS ' + mod_points_to_ghs(pts).toFixed(2);
}
</script>
</body>
</html>
