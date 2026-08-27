<?php
/**
 * Accommodation listing package checkout. Simplified sibling of
 * pay_mp_subscription.php — no market scope, no yearly billing, no
 * proration, since listing packages don't carry those marketplace-specific
 * complications. Creates a pending accommodation_listing_subscriptions row
 * and redirects to Paystack (or activates immediately if free).
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/accommodation_functions.php';
require_once __DIR__ . '/paystack.php';

require_module_enabled('accommodation', 'Accommodation');
require_login();
$user = current_user();

if (!is_feature_paid('enable_paid_accommodation_listing')) {
    flash('Accommodation listing packages are not currently required.', 'info');
    header('Location: my_accommodation.php'); exit;
}

$plansStmt = $pdo->query("SELECT * FROM accommodation_listing_packages WHERE status='active' ORDER BY price ASC");
$plans = $plansStmt->fetchAll();
if (!$plans) { flash('No listing packages available. Contact admin.', 'info'); header('Location: my_accommodation.php'); exit; }

$activeSub = get_user_active_accommodation_subscription((int)$user['id']);

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $packageId = (int)($_POST['package_id'] ?? 0);
    $planSt = $pdo->prepare("SELECT * FROM accommodation_listing_packages WHERE id=? AND status='active'");
    $planSt->execute([$packageId]); $plan = $planSt->fetch();
    if (!$plan) { $error = 'Select a valid package.'; }

    if (!$error) {
        $isRenewal = $activeSub && (int)$activeSub['package_id'] === $packageId;
        $start = date('Y-m-d');
        $end   = $isRenewal
            ? date('Y-m-d', strtotime($activeSub['end_date'] . ' +' . (int)$plan['duration_days'] . ' days'))
            : date('Y-m-d', strtotime("+{$plan['duration_days']} days"));
        $charge = (float)$plan['price'];
        if (user_has_complimentary_access(null, 'enable_paid_accommodation_listing')) $charge = 0;

        $pdo->prepare("INSERT INTO accommodation_listing_subscriptions (user_id,package_id,start_date,end_date,price_paid,status) VALUES (?,?,?,?,?,'pending')")
            ->execute([$user['id'], $packageId, $start, $end, $charge]);
        $subId = (int)$pdo->lastInsertId();

        if ($charge <= 0) {
            accommodation_activate_subscription($subId, null);
            accommodation_publish_pending_draft((int)$user['id']);
            flash('Listing package activated!', 'success');
            header('Location: my_accommodation.php'); exit;
        }

        $result = initializePayment($user['id'], $user['email'], 'accommodation_subscription', $subId, $packageId, $charge, ['package_name' => $plan['name']]);
        if (isset($result['error'])) { $error = $result['error']; $pdo->prepare("DELETE FROM accommodation_listing_subscriptions WHERE id=?")->execute([$subId]); }
        else { header('Location: '.$result['checkout_url']); exit; }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Listing Package — <?php echo APP_NAME; ?></title>
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
    <a href="my_accommodation.php" class="button button-secondary button-small">← My Listings</a>
    <span class="brand">📦 Listing Package</span>
</header>
<main class="page-shell small-shell">
    <?php foreach (get_flashes() as $f): ?>
    <div class="alert alert-<?php echo sanitize($f['type']); ?>"><?php echo sanitize($f['message']); ?></div>
    <?php endforeach; ?>
    <?php if ($error): ?><div class="alert alert-error"><?php echo sanitize($error); ?></div><?php endif; ?>

    <?php if ($activeSub): ?>
    <div style="background:#d1fae5;border:1px solid #6ee7b7;border-radius:12px;padding:14px 16px;margin-bottom:16px;">
        <strong>⭐ Active: <?php echo sanitize($activeSub['package_name']); ?></strong>
        <p style="margin:4px 0 0;font-size:.84rem;color:#065f46;">Active until <?php echo date('d M Y',strtotime($activeSub['end_date'])); ?>. Renewing the same package extends from that date.</p>
    </div>
    <?php endif; ?>

    <div class="card">
        <h2 style="margin-top:0;font-size:1rem;">Choose a Listing Package</h2>
        <p style="font-size:.84rem;color:var(--muted,#6b7280);margin:0 0 4px;">A listing package lets you publish accommodation listings on AkuapemConnect.</p>

        <form method="post" action="pay_accommodation_subscription.php" id="sub-form">
            <?php echo csrf_field(); ?>
            <div class="sub-grid">
            <?php foreach ($plans as $i => $plan): ?>
            <?php $planTag = $activeSub ? ((int)$activeSub['package_id'] === (int)$plan['id'] ? 'Renew' : 'Switch') : ''; ?>
            <label class="sub-card">
                <input type="radio" name="package_id" value="<?php echo $plan['id']; ?>" <?php echo $i===0?'checked':''; ?>>
                <?php if ($planTag): ?><span style="position:absolute;top:10px;right:10px;font-size:.62rem;font-weight:800;background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:20px;"><?php echo sanitize($planTag); ?></span><?php endif; ?>
                <div class="name"><?php echo sanitize($plan['name']); ?></div>
                <div class="price">GH₵ <?php echo number_format((float)$plan['price'],2); ?></div>
                <div class="per"><?php echo (int)$plan['duration_days']; ?> days</div>
                <div class="lim"><?php echo $plan['listing_limit']==-1?'Unlimited listings':((int)$plan['listing_limit'].' active listing limit'); ?></div>
                <?php if ($plan['description']): ?><div class="desc"><?php echo sanitize($plan['description']); ?></div><?php endif; ?>
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
<?php $activeNav = 'settings'; require __DIR__ . '/partials/bottom_nav.php'; ?>
</body>
</html>
