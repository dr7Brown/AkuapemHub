<?php
/**
 * Marketplace seller subscription checkout.
 * Creates a pending subscription record and redirects to Paystack.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/marketplace_functions.php';
require_once __DIR__ . '/paystack.php';

require_login();
$user  = current_user();
$shop  = get_shop_by_user((int)$user['id']);
$flash = get_flash();

if (!$shop) { flash('Create your shop first.', 'warning'); header('Location: seller_dashboard.php?tab=setup'); exit; }

if (get_platform_setting('mp_subscription_enabled','0') !== '1') {
    flash('Seller subscriptions are not currently available.', 'info');
    header('Location: seller_dashboard.php'); exit;
}

$plans = $pdo->query("SELECT * FROM mp_seller_subscription_plans WHERE status='active' ORDER BY price ASC")->fetchAll();
if (!$plans) { flash('No subscription plans available. Contact admin.', 'info'); header('Location: seller_dashboard.php'); exit; }

// Active subscription check
$activeSub = null;
try {
    $asSt = $pdo->prepare("SELECT mss.*, msp.name AS plan_name FROM mp_seller_subscriptions mss JOIN mp_seller_subscription_plans msp ON mss.plan_id=msp.id WHERE mss.shop_id=? AND mss.status='active' AND mss.end_date>=CURDATE() LIMIT 1");
    $asSt->execute([$shop['id']]);
    $activeSub = $asSt->fetch();
} catch(Exception $e){}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $planId = (int)($_POST['plan_id'] ?? 0);
    $planSt = $pdo->prepare("SELECT * FROM mp_seller_subscription_plans WHERE id=? AND status='active'");
    $planSt->execute([$planId]); $plan = $planSt->fetch();
    if (!$plan) { $error = 'Select a valid plan.'; }
    if (!$error) {
        $start = date('Y-m-d');
        $end   = date('Y-m-d', strtotime("+{$plan['duration_days']} days"));
        $pdo->prepare("INSERT INTO mp_seller_subscriptions (shop_id,plan_id,start_date,end_date,price_paid,status) VALUES (?,?,?,?,?,'pending')")
            ->execute([$shop['id'], $planId, $start, $end, $plan['price']]);
        $subId = (int)$pdo->lastInsertId();

        if ((float)$plan['price'] <= 0) {
            // Free plan — activate immediately
            $pdo->prepare("UPDATE mp_seller_subscriptions SET status='active', activated_by=?, activated_at=NOW() WHERE id=?")->execute([$user['id'],$subId]);
            $pdo->prepare("UPDATE mp_shops SET is_subscribed=1, subscription_plan_id=?, subscription_end=? WHERE id=?")->execute([$planId,$end,$shop['id']]);
            notify_user((int)$user['id'], '⭐ Subscription Activated!', $plan['name'].' plan is active until '.date('d M Y',strtotime($end)).'.', 'success');
            flash('Subscription activated!', 'success');
            header('Location: seller_dashboard.php'); exit;
        }

        $result = initializePayment($user['id'], $user['email'], 'mp_subscription', $subId, $planId, (float)$plan['price'], ['plan_name'=>$plan['name'], 'shop_name'=>$shop['shop_name']]);
        if (isset($result['error'])) { $error = $result['error']; $pdo->prepare("DELETE FROM mp_seller_subscriptions WHERE id=?")->execute([$subId]); }
        else { header('Location: '.$result['checkout_url']); exit; }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Seller Subscription — <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .sub-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr)); gap:14px; margin:18px 0; }
        .sub-card { border:2px solid var(--border); border-radius:14px; padding:20px; cursor:pointer; transition:border-color .15s; position:relative; }
        .sub-card:has(input:checked) { border-color:var(--primary,#0f766e); background:var(--primary-soft,#d1fae5); }
        .sub-card input { position:absolute; opacity:0; }
        .sub-card .price { font-size:1.8rem; font-weight:900; color:var(--primary,#0f766e); line-height:1; margin:10px 0 4px; }
        .sub-card .per  { font-size:.78rem; color:var(--muted,#6b7280); }
        .sub-card .name { font-size:1rem; font-weight:800; }
        .sub-card .lim  { font-size:.78rem; color:var(--muted,#6b7280); margin-top:6px; }
        .sub-card .desc { font-size:.8rem; color:var(--text,#1a2230); margin-top:8px; line-height:1.5; }
    </style>
</head>
<body class="has-bottom-nav">
<header class="app-topbar">
    <a href="seller_dashboard.php" class="button button-secondary button-small">← Dashboard</a>
    <span class="brand">⭐ Seller Subscription</span>
</header>
<main class="page-shell small-shell">
    <?php foreach (get_flashes() as $f): ?>
    <div class="alert alert-<?php echo sanitize($f['type']); ?>"><?php echo sanitize($f['message']); ?></div>
    <?php endforeach; ?>
    <?php if ($error): ?><div class="alert alert-error"><?php echo sanitize($error); ?></div><?php endif; ?>

    <?php if ($activeSub): ?>
    <div style="background:#d1fae5;border:1px solid #6ee7b7;border-radius:12px;padding:14px 16px;margin-bottom:16px;">
        <strong>⭐ Active: <?php echo sanitize($activeSub['plan_name']); ?></strong>
        <p style="margin:4px 0 0;font-size:.84rem;color:#065f46;">Active until <?php echo date('d M Y',strtotime($activeSub['end_date'])); ?>. You can subscribe again to extend.</p>
    </div>
    <?php endif; ?>

    <div class="card">
        <h2 style="margin-top:0;font-size:1rem;">Choose a Plan</h2>
        <p style="font-size:.84rem;color:var(--muted,#6b7280);margin:0 0 4px;">Unlock more products, better search placement, and seller analytics.</p>

        <form method="post" action="pay_mp_subscription.php">
            <?php echo csrf_field(); ?>
            <div class="sub-grid">
            <?php foreach ($plans as $i => $plan): ?>
            <label class="sub-card">
                <input type="radio" name="plan_id" value="<?php echo $plan['id']; ?>" <?php echo $i===0?'checked':''; ?>>
                <div class="name"><?php echo sanitize($plan['name']); ?></div>
                <div class="price">GH₵ <?php echo number_format((float)$plan['price'],2); ?></div>
                <div class="per"><?php echo (int)$plan['duration_days']; ?> days</div>
                <div class="lim"><?php echo $plan['product_limit']==-1?'Unlimited products':((int)$plan['product_limit'].' product limit'); ?></div>
                <?php if ($plan['description']): ?><div class="desc"><?php echo sanitize($plan['description']); ?></div><?php endif; ?>
            </label>
            <?php endforeach; ?>
            </div>
            <button type="submit" class="button button-primary" style="width:100%;padding:13px;font-size:.96rem;">
                🔒 Subscribe via Paystack
            </button>
            <p style="font-size:.74rem;color:var(--muted,#6b7280);text-align:center;margin-top:8px;">Secure checkout · Card &amp; Mobile Money</p>
        </form>
    </div>
</main>
<?php $activeNav = 'settings'; require __DIR__ . '/partials/bottom_nav.php'; ?>
</body>
</html>
