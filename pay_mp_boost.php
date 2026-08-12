<?php
/**
 * Paystack checkout for a pending marketplace boost order.
 * Called from seller_boost.php after the pending order is created.
 * Usage: pay_mp_boost.php?boost_id=42
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/marketplace_functions.php';
require_once __DIR__ . '/paystack.php';

require_module_enabled('mp', 'Marketplace');
require_login();
$user = current_user();

$boostId = (int)($_GET['boost_id'] ?? $_POST['boost_id'] ?? 0);
if (!$boostId) { header('Location: seller_dashboard.php?tab=products'); exit; }

// Load the pending boost order and verify it belongs to this user's shop
$boostStmt = $pdo->prepare(
    'SELECT mb.*, ms.shop_name, ms.user_id AS shop_owner_id
     FROM mp_boost_orders mb
     JOIN mp_shops ms ON mb.shop_id = ms.id
     WHERE mb.id = ? AND ms.user_id = ? AND mb.status = ?'
);
$boostStmt->execute([$boostId, $user['id'], 'pending']);
$boost = $boostStmt->fetch();

if (!$boost) {
    flash('Boost order not found or already processed.', 'error');
    header('Location: seller_dashboard.php?tab=products');
    exit;
}

// If payment is free (admin set price to 0), or the order's owner has since
// been granted a complimentary membership, activate immediately — a
// complimentary member should never be asked to pay for an order that was
// created before the membership was granted.
if ((float)$boost['price_paid'] <= 0 || user_has_complimentary_access((int)$boost['shop_owner_id'], 'mp_boost')) {
    activateBoostOrder($boostId, $boost, 0, $pdo);
    flash('Boost activated!', 'success');
    header('Location: seller_dashboard.php?tab=products');
    exit;
}

// Check for existing pending Paystack payment for this boost
$existing = $pdo->prepare(
    "SELECT id, reference_code FROM platform_payments WHERE user_id=? AND payment_type='mp_boost' AND reference_id=? AND status='pending'"
);
$existing->execute([$user['id'], $boostId]);
$existingPayment = $existing->fetch();

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $boostLabel = ucwords(str_replace('_', ' ', $boost['boost_type']));
    $result = initializePayment(
        (int)$user['id'],
        $user['email'],
        'mp_boost',
        $boostId,
        0,
        (float)$boost['price_paid'],
        ['boost_type' => $boost['boost_type'], 'shop_name' => $boost['shop_name']]
    );

    if (!empty($result['error'])) {
        $error = $result['error'];
    } else {
        header('Location: ' . $result['checkout_url']);
        exit;
    }
}

$boostLabels = [
    'featured_product'  => 'Featured Product',
    'sponsored_product' => 'Sponsored Product',
    'featured_shop'     => 'Featured Shop',
    'sponsored_shop'    => 'Sponsored Shop',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pay for Boost — AkuapemConnect</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .pay-shell { max-width:520px; margin:0 auto; padding:20px 16px 80px; }
        .pay-card  { background:var(--surface); border:1px solid var(--border); border-radius:14px; padding:20px; margin-bottom:14px; }
        .pay-label { font-size:.74rem; font-weight:800; text-transform:uppercase; letter-spacing:.07em; color:var(--text-muted,#6b7280); margin:0 0 6px; }
        .pay-row   { display:flex; justify-content:space-between; padding:6px 0; font-size:.88rem; border-bottom:1px solid #f1f5f9; }
        .pay-total { display:flex; justify-content:space-between; padding-top:10px; font-size:1.05rem; font-weight:900; color:var(--primary,#0f766e); }
    </style>
</head>
<body class="has-bottom-nav">

<header class="app-topbar">
    <a href="seller_boost.php" class="button button-secondary button-small">← Back</a>
    <span class="brand">Pay for Boost</span>
</header>

<main class="pay-shell">

    <?php if ($error): ?>
    <div class="alert alert-error"><?php echo sanitize($error); ?></div>
    <?php endif; ?>

    <div class="pay-card">
        <p class="pay-label">Order Summary</p>
        <div class="pay-row"><span>Boost type</span><span><strong><?php echo sanitize($boostLabels[$boost['boost_type']] ?? ucfirst($boost['boost_type'])); ?></strong></span></div>
        <div class="pay-row"><span>Shop</span><span><?php echo sanitize($boost['shop_name']); ?></span></div>
        <div class="pay-row"><span>Duration</span><span><?php echo $boost['package_days']; ?> days</span></div>
        <div class="pay-row"><span>Starts</span><span><?php echo date('d M Y', strtotime($boost['start_date'])); ?></span></div>
        <div class="pay-row"><span>Expires</span><span><?php echo date('d M Y', strtotime($boost['end_date'])); ?></span></div>
        <div class="pay-total"><span>Total</span><span>GHS <?php echo number_format((float)$boost['price_paid'], 2); ?></span></div>
    </div>

    <div class="pay-card" style="background:#f0fdf4;border-color:#a7f3d0;">
        <p style="margin:0;font-size:.86rem;line-height:1.6;">
            ✅ Payment is processed securely through <strong>Paystack</strong>.
            Accepted: MTN Mobile Money, Telecel Cash, AirtelTigo Money, Visa/Mastercard.
            Your boost activates <strong>automatically</strong> within minutes of payment confirmation.
        </p>
    </div>

    <form method="post" action="pay_mp_boost.php?boost_id=<?php echo $boostId; ?>">
        <?php echo csrf_field(); ?>
        <button type="submit" class="button button-primary" style="width:100%;padding:14px;font-size:1rem;">
            Pay GHS <?php echo number_format((float)$boost['price_paid'], 2); ?> via Paystack →
        </button>
    </form>

    <p style="text-align:center;font-size:.78rem;color:var(--text-muted,#6b7280);margin-top:12px;">
        You will be redirected to Paystack's secure checkout page.
    </p>

</main>

<?php require_once __DIR__ . '/partials/bottom_nav.php'; ?>
</body>
</html>

<?php
/**
 * Helper: activate a boost order (called here for free boosts or from paystack_callback.php)
 */
function activateBoostOrder(int $boostId, array $boost, int $activatedBy, PDO $pdo): void {
    $pdo->prepare("UPDATE mp_boost_orders SET status='active', activated_by=?, activated_at=NOW() WHERE id=?")
        ->execute([$activatedBy, $boostId]);

    $isSponsored = str_contains($boost['boost_type'], 'sponsored');
    $isProduct   = str_contains($boost['boost_type'], 'product');

    if ($isProduct && $boost['product_id']) {
        $pdo->prepare("UPDATE mp_products SET is_featured=?, featured_end=?, is_sponsored=?, sponsored_end=?, updated_at=NOW() WHERE id=?")
            ->execute([$isSponsored?0:1, $isSponsored?null:$boost['end_date'], $isSponsored?1:0, $isSponsored?$boost['end_date']:null, $boost['product_id']]);
    } else {
        $pdo->prepare("UPDATE mp_shops SET is_featured=?, featured_end=?, is_sponsored=?, sponsored_end=?, updated_at=NOW() WHERE id=?")
            ->execute([$isSponsored?0:1, $isSponsored?null:$boost['end_date'], $isSponsored?1:0, $isSponsored?$boost['end_date']:null, $boost['shop_id']]);
    }
}
