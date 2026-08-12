<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/delivery_functions.php';

require_module_enabled('delivery', 'Delivery Services');
require_login();
$user         = current_user();
$agentProfile = get_delivery_agent_for_user((int)$user['id']);

if (!$agentProfile || $agentProfile['verification_status'] !== 'approved') {
    flash('You must be an approved delivery agent to subscribe.', 'warning');
    header('Location: delivery_agent_jobs.php');
    exit;
}

$flash  = get_flash();
$error  = '';

// Pricing from settings
$prices = [
    'monthly'   => (float)get_platform_setting('delivery_premium_monthly_price',   '20.00'),
    'quarterly' => (float)get_platform_setting('delivery_premium_quarterly_price', '50.00'),
    'yearly'    => (float)get_platform_setting('delivery_premium_yearly_price',    '180.00'),
];
$durations = ['monthly' => 30, 'quarterly' => 90, 'yearly' => 365];
$requiresPayment = get_platform_setting('delivery_premium_requires_payment', '0') === '1' && !user_has_complimentary_access(null, 'delivery_premium');
$enabled = get_platform_setting('delivery_enable_premium', '0') === '1';

// Current active subscription
$subStmt = $pdo->prepare("SELECT * FROM delivery_subscriptions WHERE agent_id = ? AND status = 'active' AND end_date >= CURDATE() ORDER BY end_date DESC LIMIT 1");
$subStmt->execute([$agentProfile['id']]);
$activeSub = $subStmt->fetch() ?: null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $plan   = $_POST['plan_type'] ?? '';
    $method = $_POST['payment_method'] ?? '';
    $mobi   = trim($_POST['mobi_number'] ?? '');

    if (!isset($prices[$plan])) $error = 'Please select a subscription plan.';
    elseif (!in_array($method, ['mtn_momo','telecel','airtel','wallet'], true)) $error = 'Select a payment method.';
    elseif ($requiresPayment && $method !== 'wallet' && $mobi === '') $error = 'Enter your mobile money number.';

    if (!$error) {
        $price = $prices[$plan];
        $days  = $durations[$plan];
        $start = date('Y-m-d');
        $end   = date('Y-m-d', strtotime("+$days days"));
        $status = $requiresPayment ? 'pending' : 'active';

        $pdo->beginTransaction();
        try {
            // Create subscription record
            $pdo->prepare(
                'INSERT INTO delivery_subscriptions (agent_id, plan_type, price_paid, payment_method, mobi_number, start_date, end_date, status) VALUES (?,?,?,?,?,?,?,?)'
            )->execute([$agentProfile['id'], $plan, $price, $method, $mobi ?: null, $start, $end, $status]);
            $subId = (int)$pdo->lastInsertId();

            // Create transaction record
            $pdo->prepare(
                'INSERT INTO delivery_transactions (agent_id, transaction_type, amount, payment_method, mobi_number, status, related_id) VALUES (?,?,?,?,?,?,?)'
            )->execute([$agentProfile['id'], 'subscription', $price, $method, $mobi ?: null, $requiresPayment ? 'pending' : 'completed', $subId]);

            if (!$requiresPayment) {
                // Activate immediately (admin granted free)
                $pdo->prepare("UPDATE delivery_agents SET is_premium=1, premium_start=?, premium_end=?, updated_at=NOW() WHERE id=?")
                    ->execute([$start, $end, $agentProfile['id']]);
                notify_user((int)$user['id'], 'Premium Subscription Activated! ⭐', "Your " . ucfirst($plan) . " Premium subscription is now active until " . date('d M Y', strtotime($end)) . ".", 'success');
            } else {
                notify_moderators('approve_boosts', 'New Premium Rider Subscription Request',
                    display_name($user) . " submitted a " . ucfirst($plan) . " Premium subscription (GHS " . number_format($price,2) . ").");
                notify_user((int)$user['id'], 'Subscription Request Received', "Your Premium subscription request is being processed. You'll be notified once payment is confirmed.", 'info');
            }

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Failed to process request. Please try again.';
        }

        if (!$error) {
            if ($requiresPayment && $method !== 'wallet') {
                // Use Paystack for card/MoMo payment
                require_once __DIR__ . '/paystack.php';
                $result = initializePayment(
                    (int)$user['id'], $user['email'],
                    'delivery_subscription', $subId, 0, $price,
                    ['plan_type' => $plan, 'agent_id' => $agentProfile['id']]
                );
                if (empty($result['error'])) {
                    header('Location: ' . $result['checkout_url']);
                    exit;
                }
                $error = $result['error'];
            } else {
                flash($requiresPayment ? 'Subscription request submitted! Await admin confirmation.' : 'Premium subscription activated!', 'success');
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
    <title>Premium Rider Subscription — AkuapemConnect</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .sub-shell { max-width:640px; margin:0 auto; padding:20px 16px 80px; }
        .sub-plans { display:grid; grid-template-columns:repeat(auto-fill,minmax(170px,1fr)); gap:12px; margin-bottom:16px; }
        .sub-plan input { display:none; }
        .sub-plan label { display:block; cursor:pointer; border:2px solid var(--border); border-radius:14px; padding:16px; text-align:center; transition:all .12s; }
        .sub-plan input:checked + label { border-color:var(--primary,#0f766e); background:var(--primary-soft,#d1fae5); }
        .sub-price { font-size:1.4rem; font-weight:900; color:var(--primary,#0f766e); display:block; }
        .sub-plan-name { font-size:.78rem; font-weight:700; color:var(--text-muted,#6b7280); display:block; margin-top:2px; }
        .sub-card { background:var(--surface); border:1px solid var(--border); border-radius:14px; padding:18px; margin-bottom:16px; }
        .sub-section-title { font-size:.75rem; font-weight:800; text-transform:uppercase; letter-spacing:.07em; color:var(--text-muted,#6b7280); margin:0 0 14px; }
        .sub-benefit { display:flex; align-items:center; gap:8px; font-size:.86rem; padding:4px 0; }
    </style>
</head>
<body class="has-bottom-nav">

<header class="app-topbar">
    <a href="delivery_agent_jobs.php" class="button button-secondary button-small">← Back</a>
    <span class="brand">⭐ Premium Subscription</span>
</header>

<?php if ($flash): ?>
<div class="alert alert-<?php echo sanitize($flash['type']); ?>" style="margin:10px 16px 0;"><?php echo sanitize($flash['message']); ?></div>
<?php endif; ?>

<main class="sub-shell">

    <?php if ($activeSub): ?>
    <div style="background:#d1fae5;border:1px solid #6ee7b7;border-radius:12px;padding:14px 16px;margin-bottom:16px;">
        <strong>⭐ Premium Active</strong> — expires <?php echo date('d M Y', strtotime($activeSub['end_date'])); ?>
        (<?php echo ucfirst($activeSub['plan_type']); ?> plan)
    </div>
    <?php endif; ?>

    <?php if (!$enabled && !$activeSub): ?>
    <div style="background:#f0fdf4;border:1px solid #a7f3d0;border-radius:12px;padding:14px 16px;margin-bottom:16px;font-size:.86rem;">
        ✅ Premium subscriptions are currently <strong>free</strong> — contact the admin to activate your premium badge.
    </div>
    <?php endif; ?>

    <!-- Benefits -->
    <div class="sub-card">
        <p class="sub-section-title">Premium Benefits</p>
        <?php $benefits = ['Appears before free riders in search','Premium badge on your profile','Priority visibility on homepage','Featured in rider directory','Access to delivery statistics','Priority access to new requests']; ?>
        <?php foreach ($benefits as $b): ?>
        <div class="sub-benefit"><span style="color:var(--primary,#0f766e);">✓</span> <?php echo sanitize($b); ?></div>
        <?php endforeach; ?>
    </div>

    <?php if ($error): ?><div class="alert alert-error"><?php echo sanitize($error); ?></div><?php endif; ?>

    <form method="post" action="delivery_subscribe.php">
        <?php echo csrf_field(); ?>

        <!-- Plans -->
        <div class="sub-card">
            <p class="sub-section-title">Choose Your Plan</p>
            <div class="sub-plans">
                <?php foreach ($prices as $plan => $price): ?>
                <div class="sub-plan">
                    <input type="radio" name="plan_type" id="plan_<?php echo $plan; ?>" value="<?php echo $plan; ?>" <?php echo $plan==='monthly'?'checked':''; ?>>
                    <label for="plan_<?php echo $plan; ?>">
                        <span class="sub-price"><?php echo $requiresPayment ? 'GHS '.number_format($price,2) : 'Free'; ?></span>
                        <span class="sub-plan-name"><?php echo ucfirst($plan); ?> (<?php echo $durations[$plan]; ?> days)</span>
                    </label>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Payment -->
        <?php if ($requiresPayment): ?>
        <div class="sub-card">
            <p class="sub-section-title">Payment Method</p>
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
            <?php echo $requiresPayment ? 'Submit Subscription Request' : 'Activate Premium Free'; ?>
        </button>
    </form>

</main>

<?php require_once __DIR__ . '/partials/bottom_nav.php'; ?>
<script>
document.querySelectorAll('input[name="payment_method"]').forEach(function(r){
    r.addEventListener('change',function(){
        document.getElementById('mobi-row').style.display = r.value==='wallet' ? 'none' : 'block';
    });
});
</script>
</body>
</html>
