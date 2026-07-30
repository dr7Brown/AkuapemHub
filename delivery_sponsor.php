<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/delivery_functions.php';

require_module_enabled('delivery', 'Delivery Services');
require_login();
$user         = current_user();
$agentProfile = get_delivery_agent_for_user((int)$user['id']);

if (!$agentProfile || $agentProfile['verification_status'] !== 'approved') {
    flash('You must be an approved delivery agent to purchase sponsored listings.', 'warning');
    header('Location: delivery_agent_jobs.php');
    exit;
}

$flash = get_flash();
$error = '';

$packages = [
    ['days' => 7,  'price' => (float)get_platform_setting('delivery_sponsored_7day_price',  '10.00'), 'label' => '7 Days'],
    ['days' => 30, 'price' => (float)get_platform_setting('delivery_sponsored_30day_price', '30.00'), 'label' => '30 Days'],
    ['days' => 90, 'price' => (float)get_platform_setting('delivery_sponsored_90day_price', '70.00'), 'label' => '90 Days'],
];
$requiresPayment = get_platform_setting('delivery_sponsored_requires_payment', '0') === '1' && !user_has_complimentary_access();

$activeSponsored = null;
if (agent_is_sponsored($agentProfile)) {
    $activeSponsored = $agentProfile['sponsored_end'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $days   = (int)($_POST['package_days'] ?? 0);
    $method = $_POST['payment_method'] ?? '';
    $mobi   = trim($_POST['mobi_number'] ?? '');

    $validDays = [7,30,90];
    if (!in_array($days, $validDays, true)) $error = 'Select a package.';
    elseif (!in_array($method, ['mtn_momo','telecel','airtel','wallet'], true)) $error = 'Select a payment method.';
    elseif ($requiresPayment && $method !== 'wallet' && $mobi === '') $error = 'Enter your mobile money number.';

    if (!$error) {
        $pkg   = array_values(array_filter($packages, fn($p) => $p['days'] === $days))[0];
        $price = $pkg['price'];
        $start = date('Y-m-d');
        $end   = date('Y-m-d', strtotime("+{$days} days"));
        $status = $requiresPayment ? 'pending' : 'active';

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO delivery_sponsored_listings (agent_id, package_days, price_paid, payment_method, mobi_number, start_date, end_date, status) VALUES (?,?,?,?,?,?,?,?)'
            )->execute([$agentProfile['id'], $days, $price, $method, $mobi ?: null, $start, $end, $status]);
            $spId = (int)$pdo->lastInsertId();

            $pdo->prepare(
                'INSERT INTO delivery_transactions (agent_id, transaction_type, amount, payment_method, mobi_number, status, related_id) VALUES (?,?,?,?,?,?,?)'
            )->execute([$agentProfile['id'], 'sponsored', $price, $method, $mobi ?: null, $requiresPayment ? 'pending' : 'completed', $spId]);

            if (!$requiresPayment) {
                $pdo->prepare("UPDATE delivery_agents SET is_sponsored=1, sponsored_end=?, updated_at=NOW() WHERE id=?")
                    ->execute([$end, $agentProfile['id']]);
                notify_user((int)$user['id'], 'Sponsored Listing Activated! 🌟', "Your {$days}-day sponsored listing is active until " . date('d M Y', strtotime($end)) . ".", 'success');
            } else {
                notify_moderators('approve_boosts', 'New Sponsored Rider Listing Request',
                    display_name($user) . " has requested a {$days}-day sponsored listing (GHS " . number_format($price,2) . ").");
                notify_user((int)$user['id'], 'Sponsored Listing Request Received', "Your sponsored listing request is pending admin approval.", 'info');
            }
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Failed to process request. Please try again.';
        }

        if (!$error) {
            if ($requiresPayment && $method !== 'wallet') {
                require_once __DIR__ . '/paystack.php';
                $result = initializePayment(
                    (int)$user['id'], $user['email'],
                    'delivery_sponsored', $spId, 0, $price,
                    ['package_days' => $days, 'agent_id' => $agentProfile['id']]
                );
                if (empty($result['error'])) {
                    header('Location: ' . $result['checkout_url']);
                    exit;
                }
                $error = $result['error'];
            } else {
                flash($requiresPayment ? 'Sponsored listing request submitted!' : 'Sponsored listing activated!', 'success');
                header('Location: delivery_agent_jobs.php');
                exit;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sponsored Listing — AkuapemConnect</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .sp-shell { max-width:640px; margin:0 auto; padding:20px 16px 80px; }
        .sp-pkgs   { display:grid; grid-template-columns:repeat(3,1fr); gap:12px; margin-bottom:16px; }
        .sp-pkg input { display:none; }
        .sp-pkg label { display:block; cursor:pointer; border:2px solid var(--border); border-radius:14px; padding:16px; text-align:center; transition:all .12s; }
        .sp-pkg input:checked + label { border-color:#f59e0b; background:#fffbeb; }
        .sp-price { font-size:1.4rem; font-weight:900; color:#b45309; display:block; }
        .sp-days  { font-size:.78rem; font-weight:700; color:var(--text-muted,#6b7280); display:block; margin-top:2px; }
        .sp-card  { background:var(--surface); border:1px solid var(--border); border-radius:14px; padding:18px; margin-bottom:16px; }
        .sp-section-title { font-size:.75rem; font-weight:800; text-transform:uppercase; letter-spacing:.07em; color:var(--text-muted,#6b7280); margin:0 0 14px; }
    </style>
</head>
<body class="has-bottom-nav">

<header class="app-topbar">
    <a href="delivery_agent_jobs.php" class="button button-secondary button-small">← Back</a>
    <span class="brand">🌟 Sponsored Listing</span>
</header>

<?php if ($flash): ?><div class="alert alert-<?php echo sanitize($flash['type']); ?>" style="margin:10px 16px 0;"><?php echo sanitize($flash['message']); ?></div><?php endif; ?>

<main class="sp-shell">

    <?php if ($activeSponsored): ?>
    <div style="background:#fef3c7;border:1px solid #f59e0b;border-radius:12px;padding:14px 16px;margin-bottom:16px;">
        <strong>🌟 Sponsored listing active</strong> — expires <?php echo date('d M Y', strtotime($activeSponsored)); ?>
    </div>
    <?php endif; ?>

    <div class="sp-card">
        <p class="sp-section-title">Sponsored Benefits</p>
        <?php foreach (['Appear at the top of all rider searches','Featured on the homepage delivery section','Highlighted card with Sponsored badge','Maximum visibility before Premium and Verified riders'] as $b): ?>
        <div style="display:flex;align-items:center;gap:8px;font-size:.86rem;padding:4px 0;"><span style="color:#f59e0b;">★</span> <?php echo sanitize($b); ?></div>
        <?php endforeach; ?>
    </div>

    <?php if ($error): ?><div class="alert alert-error"><?php echo sanitize($error); ?></div><?php endif; ?>

    <form method="post" action="delivery_sponsor.php">
        <?php echo csrf_field(); ?>

        <div class="sp-card">
            <p class="sp-section-title">Choose Package</p>
            <div class="sp-pkgs">
                <?php foreach ($packages as $pkg): ?>
                <div class="sp-pkg">
                    <input type="radio" name="package_days" id="pkg_<?php echo $pkg['days']; ?>" value="<?php echo $pkg['days']; ?>" <?php echo $pkg['days']===7?'checked':''; ?>>
                    <label for="pkg_<?php echo $pkg['days']; ?>">
                        <span class="sp-price"><?php echo $requiresPayment ? 'GHS '.number_format($pkg['price'],2) : 'Free'; ?></span>
                        <span class="sp-days"><?php echo $pkg['label']; ?></span>
                    </label>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if ($requiresPayment): ?>
        <div class="sp-card">
            <p class="sp-section-title">Payment Method</p>
            <?php $methods = ['mtn_momo'=>'MTN Mobile Money','telecel'=>'Telecel Cash','airtel'=>'AirtelTigo Money','wallet'=>'Wallet Balance']; ?>
            <?php foreach ($methods as $v=>$l): ?>
            <label style="display:flex;align-items:center;gap:8px;padding:6px 0;cursor:pointer;font-size:.88rem;">
                <input type="radio" name="payment_method" value="<?php echo $v; ?>" <?php echo $v==='mtn_momo'?'checked':''; ?>>
                <?php echo sanitize($l); ?>
            </label>
            <?php endforeach; ?>
            <div style="margin-top:10px;" id="mobi-row">
                <label style="font-weight:600;font-size:.85rem;">Mobile Money Number</label>
                <input type="tel" name="mobi_number" placeholder="e.g. 0244000000" style="margin-top:4px;">
            </div>
        </div>
        <?php else: ?>
        <input type="hidden" name="payment_method" value="free">
        <?php endif; ?>

        <button type="submit" class="button button-primary" style="width:100%;font-size:1rem;padding:14px;">
            <?php echo $requiresPayment ? 'Submit Sponsored Listing Request' : 'Activate Sponsored Listing Free'; ?>
        </button>
    </form>
</main>

<?php require_once __DIR__ . '/partials/bottom_nav.php'; ?>
<script>
document.querySelectorAll('input[name="payment_method"]').forEach(function(r){
    r.addEventListener('change',function(){
        var mobi = document.getElementById('mobi-row');
        if(mobi) mobi.style.display = r.value==='wallet' ? 'none' : 'block';
    });
});
</script>
</body>
</html>
