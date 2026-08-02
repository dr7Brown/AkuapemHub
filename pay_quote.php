<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/marketplace_functions.php';
require_once __DIR__ . '/paystack.php';

require_module_enabled('mp', 'Marketplace');
require_login();
$user = current_user();

$quoteId = (int)($_GET['id'] ?? $_POST['quote_id'] ?? 0);
$qrStmt = $pdo->prepare(
    "SELECT mqr.*, ms.shop_name
     FROM mp_quote_requests mqr JOIN mp_shops ms ON mqr.shop_id = ms.id
     WHERE mqr.id=? AND mqr.customer_id=? AND mqr.status='quoted'"
);
$qrStmt->execute([$quoteId, $user['id']]);
$quote = $qrStmt->fetch();
if (!$quote) {
    flash('This quote request is no longer available to pay.', 'error');
    header('Location: orders.php?view=quotes');
    exit;
}

// An order (and payment) was already started for this quote on an earlier
// submit — status stays 'quoted' until the payment webhook confirms, so a
// double-click or back-button resubmit here must not create a second order.
// Send them to the existing pending payment instead.
if ($quote['order_id']) {
    header('Location: resume_payment.php?id=' . (int)$quote['platform_payment_id']);
    exit;
}

$itemsStmt = $pdo->prepare("SELECT * FROM mp_quote_request_items WHERE quote_request_id=? AND is_available=1 AND price IS NOT NULL ORDER BY sort_order ASC");
$itemsStmt->execute([$quoteId]);
$quoteItems = $itemsStmt->fetchAll();

if (!$quoteItems || (float)$quote['total_amount'] <= 0) {
    flash('This quote has no priced items to pay for.', 'error');
    header('Location: orders.php?view=quotes');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $deliveryAddress  = trim($_POST['delivery_address']  ?? '');
    $deliveryMapsLink = trim($_POST['delivery_maps_link'] ?? '') ?: null;
    $receiverName     = trim($_POST['receiver_name']    ?? $user['name']);
    $receiverPhone    = trim($_POST['receiver_phone']   ?? $user['phone']);
    $notes            = trim($_POST['notes'] ?? '');

    if ($deliveryAddress === '') $error = 'Please enter a delivery address.';
    elseif ($receiverName === '')  $error = 'Please enter the receiver name.';
    elseif ($receiverPhone === '') $error = 'Please enter the receiver phone number.';
    elseif (!paystack_configured()) $error = 'Payment gateway is not configured. Please contact support.';

    if (!$error) {
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO mp_orders (customer_id, shop_id, total_amount, delivery_address, delivery_maps_link, receiver_name, receiver_phone, payment_method, notes, status) VALUES (?,?,?,?,?,?,?,\'paystack\',?,\'pending\')'
            )->execute([$user['id'], $quote['shop_id'], $quote['total_amount'], $deliveryAddress, $deliveryMapsLink, $receiverName, $receiverPhone, $notes ?: null]);
            $orderId = (int)$pdo->lastInsertId();

            foreach ($quoteItems as $qi) {
                $itemLabel = $qi['item_name'] . ($qi['quantity_note'] ? ' (' . $qi['quantity_note'] . ')' : '');
                $pdo->prepare(
                    'INSERT INTO mp_order_items (order_id, product_id, product_name, price, quantity, subtotal) VALUES (?,NULL,?,?,1,?)'
                )->execute([$orderId, mb_substr($itemLabel, 0, 255), $qi['price'], $qi['price']]);
            }

            $pdo->commit();

            $result = initializePayment(
                (int)$user['id'], $user['email'], 'mp_order', $orderId, 0, (float)$quote['total_amount'],
                ['order_ids' => [$orderId], 'quote_request_id' => $quoteId]
            );

            if (isset($result['error'])) {
                mp_cancel_order_and_restore_stock([$orderId], 'Payment could not be started: ' . $result['error']);
                flash('Could not start payment: ' . $result['error'] . '. Please try again.', 'error');
                header('Location: orders.php?view=quotes');
                exit;
            }

            $pdo->prepare('UPDATE mp_orders SET platform_payment_id=? WHERE id=?')
                ->execute([$result['payment_id'], $orderId]);
            $pdo->prepare('UPDATE mp_quote_requests SET platform_payment_id=?, order_id=? WHERE id=?')
                ->execute([$result['payment_id'], $orderId, $quoteId]);

            header('Location: ' . $result['checkout_url']);
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Failed to place order. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pay Quote — AkuapemConnect</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .co-shell { max-width:700px; margin:0 auto; padding:16px 16px 80px; }
        .co-card  { background:var(--surface); border:1px solid var(--border); border-radius:14px; padding:18px; margin-bottom:14px; }
        .co-section-title { font-size:.75rem; font-weight:800; text-transform:uppercase; letter-spacing:.07em; color:var(--text-muted,#6b7280); margin:0 0 14px; }
        label { font-weight:600; font-size:.86rem; display:block; margin-bottom:4px; }
        .form-group { margin-bottom:14px; }
        .co-item { display:flex; gap:10px; align-items:center; padding:8px 0; border-bottom:1px solid var(--border); }
        .co-item:last-child { border-bottom:none; }
        .co-grand { display:flex; justify-content:space-between; font-size:1.1rem; font-weight:900; padding-top:10px; margin-top:8px; border-top:2px solid var(--border); }
    </style>
</head>
<body class="has-bottom-nav">

<header class="app-topbar">
    <a href="orders.php?view=quotes" class="button button-secondary button-small">← Quote Requests</a>
    <span class="brand">Pay Quote</span>
</header>

<main class="co-shell">

    <?php if ($error): ?><div class="alert alert-error"><?php echo sanitize($error); ?></div><?php endif; ?>

    <form method="post" action="pay_quote.php?id=<?php echo $quoteId; ?>">
        <?php echo csrf_field(); ?>

        <div class="co-card">
            <p class="co-section-title">📍 Delivery Details</p>
            <div class="form-group">
                <label for="delivery_address">Delivery Address *</label>
                <textarea id="delivery_address" name="delivery_address" rows="2" required
                          placeholder="Full address where items should be delivered"><?php echo sanitize($_POST['delivery_address'] ?? ''); ?></textarea>
            </div>
            <div class="form-group">
                <label for="delivery_maps_link">Google Maps Link <span style="font-weight:400;color:var(--text-muted,#6b7280);">(optional — helps seller find you)</span></label>
                <input type="url" id="delivery_maps_link" name="delivery_maps_link"
                       placeholder="https://maps.google.com/…"
                       value="<?php echo sanitize($_POST['delivery_maps_link'] ?? ''); ?>">
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div class="form-group">
                    <label for="receiver_name">Receiver Name *</label>
                    <input type="text" id="receiver_name" name="receiver_name" required
                           value="<?php echo sanitize($_POST['receiver_name'] ?? $user['name']); ?>">
                </div>
                <div class="form-group">
                    <label for="receiver_phone">Receiver Phone *</label>
                    <input type="tel" id="receiver_phone" name="receiver_phone" required
                           value="<?php echo sanitize($_POST['receiver_phone'] ?? ($user['phone']??'')); ?>">
                </div>
            </div>
            <div class="form-group">
                <label for="notes">Order Notes (optional)</label>
                <input type="text" id="notes" name="notes" placeholder="Any special instructions?"
                       value="<?php echo sanitize($_POST['notes'] ?? ''); ?>">
            </div>
        </div>

        <div class="co-card">
            <p class="co-section-title">💳 Payment</p>
            <p class="meta" style="margin:0;">You'll be redirected to Paystack's secure checkout to pay by card or mobile money.</p>
        </div>

        <div class="co-card">
            <p class="co-section-title">🛍️ Quote Summary — <?php echo sanitize($quote['shop_name']); ?></p>
            <?php foreach ($quoteItems as $qi): ?>
            <div class="co-item">
                <div style="flex:1;font-size:.85rem;font-weight:600;">
                    <?php echo sanitize($qi['item_name']); ?>
                    <?php if ($qi['quantity_note']): ?><span style="color:var(--text-muted,#6b7280);"> (<?php echo sanitize($qi['quantity_note']); ?>)</span><?php endif; ?>
                </div>
                <div style="font-weight:800;font-size:.88rem;">GH&#8373; <?php echo number_format((float)$qi['price'],2); ?></div>
            </div>
            <?php endforeach; ?>
            <div class="co-grand"><span>Total</span><span>GH&#8373; <?php echo number_format((float)$quote['total_amount'],2); ?></span></div>
            <p style="font-size:.75rem;color:var(--text-muted,#6b7280);margin:8px 0 0;">Delivery fees are negotiated with riders during delivery.</p>
        </div>

        <button type="submit" class="button button-primary" style="width:100%;padding:14px;font-size:1rem;">
            Pay with Paystack →
        </button>
    </form>

</main>

<?php require_once __DIR__ . '/partials/bottom_nav.php'; ?>
</body>
</html>
