<?php
/**
 * Marketplace seller subscription checkout.
 * Creates a pending subscription record and redirects to Paystack.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/marketplace_functions.php';
require_once __DIR__ . '/paystack.php';

require_module_enabled('mp', 'Marketplace');
require_login();
$user  = current_user();
$shop  = get_shop_by_user((int)$user['id']);

if (!$shop) { flash('Create your shop first.', 'warning'); header('Location: seller_dashboard.php?tab=setup'); exit; }

if (get_platform_setting('mp_subscription_enabled','0') !== '1') {
    flash('Seller subscriptions are not currently available.', 'info');
    header('Location: seller_dashboard.php'); exit;
}

$plans = $pdo->query("SELECT * FROM mp_seller_subscription_plans WHERE status='active' ORDER BY display_order ASC, price ASC")->fetchAll();
if (!$plans) { flash('No subscription plans available. Contact admin.', 'info'); header('Location: seller_dashboard.php'); exit; }

// Active subscription check (joins the plan's price so upgrade/downgrade can be told apart)
$activeSub = null;
try {
    $asSt = $pdo->prepare("SELECT mss.*, msp.name AS plan_name, msp.price AS plan_price, msp.duration_days FROM mp_seller_subscriptions mss JOIN mp_seller_subscription_plans msp ON mss.plan_id=msp.id WHERE mss.shop_id=? AND mss.status='active' AND mss.end_date>=CURDATE() LIMIT 1");
    $asSt->execute([$shop['id']]);
    $activeSub = $asSt->fetch();
} catch(Exception $e){}

$prorationEnabled = get_platform_setting('mp_subscription_upgrade_proration', '0') === '1';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $planId = (int)($_POST['plan_id'] ?? 0);
    $planSt = $pdo->prepare("SELECT * FROM mp_seller_subscription_plans WHERE id=? AND status='active'");
    $planSt->execute([$planId]); $plan = $planSt->fetch();
    if (!$plan) { $error = 'Select a valid plan.'; }

    if (!$error) {
        // Yearly billing is only honored when the plan actually offers a yearly
        // price — otherwise silently falls back to the plan's normal monthly cycle.
        $billYearly = ($_POST['billing_cycle'] ?? 'monthly') === 'yearly' && $plan['yearly_price'] !== null;
        $planDurationDays = $billYearly ? 365 : (int)$plan['duration_days'];
        $planPrice        = $billYearly ? (float)$plan['yearly_price'] : (float)$plan['price'];

        $isRenewal  = $activeSub && (int)$activeSub['plan_id'] === $planId;
        $isDowngrade = $activeSub && !$isRenewal && $planPrice < (float)$activeSub['plan_price'];
        $charge = $planPrice;

        if ($isDowngrade) {
            // Takes effect only once the current plan's cycle ends — never charged
            // more than the plan's own price (no proration on the way down).
            $start = $activeSub['end_date'];
            $end   = date('Y-m-d', strtotime($start . " +{$planDurationDays} days"));
        } elseif ($isRenewal && $activeSub) {
            // Extend from the *current* expiry, not from today — renewing early
            // doesn't forfeit the remaining paid-for days.
            $start = date('Y-m-d');
            $end   = date('Y-m-d', strtotime($activeSub['end_date'] . " +{$planDurationDays} days"));
        } else {
            $start = date('Y-m-d');
            $end   = date('Y-m-d', strtotime("+{$planDurationDays} days"));
            // Upgrade while a cycle is still running: prorate the charge if enabled.
            // Cycle length is derived from the active sub's own recorded dates
            // (not the plan's default duration) so a yearly purchase prorates correctly.
            if ($activeSub && $prorationEnabled) {
                $remainingDays  = max(0, (strtotime($activeSub['end_date']) - strtotime(date('Y-m-d'))) / 86400);
                $activeCycleDays = max(1, (strtotime($activeSub['end_date']) - strtotime($activeSub['start_date'])) / 86400);
                $unusedCredit   = round($remainingDays / $activeCycleDays * (float)$activeSub['plan_price'], 2);
                $charge = max(0, round($charge - $unusedCredit, 2));
            }
        }

        if (user_has_complimentary_access()) $charge = 0;

        $pdo->prepare("INSERT INTO mp_seller_subscriptions (shop_id,plan_id,start_date,end_date,price_paid,status) VALUES (?,?,?,?,?,'pending')")
            ->execute([$shop['id'], $planId, $start, $end, $charge]);
        $subId = (int)$pdo->lastInsertId();

        if ($charge <= 0) {
            // Free plan, or a fully-credited upgrade — activate immediately, no Paystack step.
            mp_activate_subscription($subId, null);
            flash($isDowngrade ? 'Downgrade scheduled — it takes effect on ' . date('d M Y', strtotime($start)) . '.' : 'Subscription activated!', 'success');
            header('Location: seller_dashboard.php'); exit;
        }

        $result = initializePayment($user['id'], $user['email'], 'mp_subscription', $subId, $planId, $charge, ['plan_name'=>$plan['name'], 'shop_name'=>$shop['shop_name']]);
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
        <p style="margin:4px 0 0;font-size:.84rem;color:#065f46;">Active until <?php echo date('d M Y',strtotime($activeSub['end_date'])); ?>. Renew the same plan to extend from that date, upgrade to switch immediately, or downgrade to switch once this cycle ends.</p>
    </div>
    <?php endif; ?>

    <?php $anyYearly = array_filter($plans, fn($p) => $p['yearly_price'] !== null); ?>

    <div class="card">
        <h2 style="margin-top:0;font-size:1rem;">Choose a Plan</h2>
        <p style="font-size:.84rem;color:var(--muted,#6b7280);margin:0 0 4px;">Unlock more products, better search placement, and seller analytics.</p>

        <?php if ($anyYearly): ?>
        <div style="display:inline-flex;gap:2px;background:var(--surface-muted,#f1f5f9);border-radius:10px;padding:3px;margin:8px 0 14px;">
            <button type="button" id="bc-monthly" class="button button-small" style="background:var(--primary,#0f766e);color:#fff;border:none;" onclick="setBillingCycle('monthly')">Monthly</button>
            <button type="button" id="bc-yearly" class="button button-small" style="background:transparent;border:none;color:var(--muted,#6b7280);" onclick="setBillingCycle('yearly')">Yearly</button>
        </div>
        <?php endif; ?>

        <form method="post" action="pay_mp_subscription.php" id="sub-form">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="billing_cycle" id="billing_cycle" value="monthly">
            <div class="sub-grid">
            <?php foreach ($plans as $i => $plan): ?>
            <?php
                $planTag = '';
                if ($activeSub) {
                    if ((int)$activeSub['plan_id'] === (int)$plan['id']) $planTag = 'Renew';
                    elseif ((float)$plan['price'] > (float)$activeSub['plan_price']) $planTag = 'Upgrade — takes effect now';
                    else $planTag = 'Downgrade — starts ' . date('d M Y', strtotime($activeSub['end_date']));
                }
            ?>
            <?php
                $planBenefits = [];
                if ($plan['unlimited_images']) $planBenefits[] = 'Unlimited images';
                else $planBenefits[] = (int)$plan['max_images'] . ' images/product';
                if ($plan['featured_shop_included']) $planBenefits[] = 'Featured shop';
                if ((int)$plan['featured_products_included'] > 0) $planBenefits[] = (int)$plan['featured_products_included'] . ' featured products';
                if ((int)$plan['priority_ranking'] > 0) $planBenefits[] = 'Priority search ranking';
                if ((int)$plan['ad_credits'] > 0) $planBenefits[] = (int)$plan['ad_credits'] . ' ad credits';
                if ($plan['analytics_access']) $planBenefits[] = 'Analytics access';
                if ($plan['verification_included']) $planBenefits[] = 'Verification included';
                $planBenefits[] = ucfirst($plan['support_level']) . ' support';
            ?>
            <label class="sub-card" data-monthly-price="GH₵ <?php echo number_format((float)$plan['price'],2); ?>" data-yearly-price="<?php echo $plan['yearly_price']!==null ? 'GH₵ '.number_format((float)$plan['yearly_price'],2) : ''; ?>">
                <input type="radio" name="plan_id" value="<?php echo $plan['id']; ?>" <?php echo $i===0?'checked':''; ?>>
                <?php if ($planTag): ?><span style="position:absolute;top:10px;right:10px;font-size:.62rem;font-weight:800;background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:20px;"><?php echo sanitize($planTag); ?></span><?php endif; ?>
                <?php if ($plan['badge_name']): ?><span style="display:inline-block;font-size:.6rem;font-weight:800;background:<?php echo sanitize($plan['badge_color'] ?: '#e4f4ea'); ?>;color:#1a2230;padding:2px 8px;border-radius:20px;margin-bottom:4px;">⭐ <?php echo sanitize($plan['badge_name']); ?></span><?php endif; ?>
                <div class="name"><?php echo sanitize($plan['name']); ?></div>
                <div class="price plan-price">GH₵ <?php echo number_format((float)$plan['price'],2); ?></div>
                <div class="per"><?php echo (int)$plan['duration_days']; ?> days<?php if ($plan['yearly_price'] !== null): ?> · GH₵ <?php echo number_format((float)$plan['yearly_price'],2); ?>/yr<?php endif; ?></div>
                <div class="lim"><?php echo $plan['product_limit']==-1?'Unlimited products':((int)$plan['product_limit'].' active listing limit'); ?></div>
                <?php if ($plan['description']): ?><div class="desc"><?php echo sanitize($plan['description']); ?></div><?php endif; ?>
                <div class="desc" style="margin-top:6px;font-size:.74rem;color:var(--text-muted,#6b7280);"><?php echo sanitize(implode(' · ', $planBenefits)); ?></div>
            </label>
            <?php endforeach; ?>
            </div>
            <button type="submit" class="button button-primary" style="width:100%;padding:13px;font-size:.96rem;">
                🔒 Continue
            </button>
            <p style="font-size:.74rem;color:var(--muted,#6b7280);text-align:center;margin-top:8px;">Secure checkout · Card &amp; Mobile Money</p>
        </form>
    </div>
</main>
<?php if ($anyYearly): ?>
<script>
function setBillingCycle(cycle) {
    document.getElementById('billing_cycle').value = cycle;
    document.getElementById('bc-monthly').style.background = cycle === 'monthly' ? 'var(--primary,#0f766e)' : 'transparent';
    document.getElementById('bc-monthly').style.color = cycle === 'monthly' ? '#fff' : 'var(--muted,#6b7280)';
    document.getElementById('bc-yearly').style.background = cycle === 'yearly' ? 'var(--primary,#0f766e)' : 'transparent';
    document.getElementById('bc-yearly').style.color = cycle === 'yearly' ? '#fff' : 'var(--muted,#6b7280)';
    document.querySelectorAll('.sub-card').forEach(function (card) {
        var priceEl = card.querySelector('.plan-price');
        var yearly = card.getAttribute('data-yearly-price');
        if (cycle === 'yearly' && yearly) priceEl.textContent = yearly;
        else priceEl.textContent = card.getAttribute('data-monthly-price');
    });
}
</script>
<?php endif; ?>
<?php $activeNav = 'settings'; require __DIR__ . '/partials/bottom_nav.php'; ?>
</body>
</html>
