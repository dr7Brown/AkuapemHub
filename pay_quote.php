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
    "SELECT mqr.*, ms.shop_name, ms.market_id,
            m.name AS market_name, m.status AS market_status, m.recurrence_type, m.recurrence_weekdays,
            m.recurrence_week_of_month, m.preorder_days, m.order_close_time, m.pickup_fee
     FROM mp_quote_requests mqr
     JOIN mp_shops ms ON mqr.shop_id = ms.id
     LEFT JOIN markets m ON ms.market_id = m.id
     WHERE mqr.id=? AND mqr.customer_id=? AND mqr.status='quoted'"
);
$qrStmt->execute([$quoteId, $user['id']]);
$quote = $qrStmt->fetch();
if (!$quote) {
    flash('This quote request is no longer available to pay.', 'error');
    header('Location: orders.php?view=quotes');
    exit;
}

// A market custom order can only be paid for during its pre-order/market-day
// window — same purchase gate checkout.php enforces for cart orders, just
// schedule-aware. Pricing stays allowed any time; this is the sole
// payment-step gate.
if ($quote['market_id']) {
    $marketSched = get_market_schedule(['status' => $quote['market_status'], 'recurrence_type' => $quote['recurrence_type'],
        'recurrence_weekdays' => $quote['recurrence_weekdays'], 'recurrence_week_of_month' => $quote['recurrence_week_of_month'],
        'preorder_days' => $quote['preorder_days'], 'order_close_time' => $quote['order_close_time']]);
    if (!$marketSched['is_payment_open']) {
        if ($marketSched['is_market_day']) {
            $msg = $quote['market_name'] . ' has stopped taking payments for today' .
                ($quote['order_close_time'] ? ' — orders closed at ' . date('g:ia', strtotime($quote['order_close_time'])) : '') .
                '. Try again on the next market day.';
        } elseif ($marketSched['is_scheduled'] && $marketSched['next_market_date']) {
            $msg = $quote['market_name'] . ' isn\'t open for payment right now — next market day is ' . $marketSched['next_market_date']->format('j F') . '.';
        } else {
            $msg = $quote['market_name'] . ' is currently closed. You can pay for this order once it reopens on the next market day.';
        }
        flash($msg, 'error');
        header('Location: orders.php?view=quotes');
        exit;
    }
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

// Market orders choose Pickup (at the market's fixed pickup_fee) or Delivery
// to one of the market's priced towns — the assigned agent fulfils either
// personally, same as they already handle storehouse handoff. Regular
// (non-market) shop orders are untouched: always a real shipped delivery,
// address always required, no fulfillment-method concept.
$isMarketOrder = (bool)$quote['market_id'];
$deliveryTowns = [];
if ($isMarketOrder) {
    $dtStmt = $pdo->prepare(
        "SELECT mdt.town_id, mdt.delivery_fee, t.name, t.district
         FROM market_delivery_towns mdt JOIN towns t ON mdt.town_id = t.id
         WHERE mdt.market_id = (SELECT market_id FROM mp_shops WHERE id = ?) AND mdt.status = 'active'
         ORDER BY t.district, t.name"
    );
    $dtStmt->execute([$quote['shop_id']]);
    $deliveryTowns = $dtStmt->fetchAll();
}

// Platform-wide charge, distinct from the market's own pickup/delivery fee —
// same amount regardless of fulfillment method, so it's computed once from
// the item total alone. Not applied to regular (non-market) shop orders.
$systemCharge = $isMarketOrder ? get_market_system_charge((float)$quote['total_amount']) : 0.0;

$error = '';
$selectedMethod = ($_POST['fulfillment_method'] ?? 'pickup') === 'delivery' ? 'delivery' : 'pickup';
$selectedTownId = (int)($_POST['delivery_town_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $deliveryAddress  = trim($_POST['delivery_address']  ?? '');
    $deliveryMapsLink = trim($_POST['delivery_maps_link'] ?? '') ?: null;
    $receiverName     = trim($_POST['receiver_name']    ?? $user['name']);
    $receiverPhone    = trim($_POST['receiver_phone']   ?? $user['phone']);
    $notes            = trim($_POST['notes'] ?? '');

    $fulfillmentMethod = 'pickup';
    $fulfillmentFee    = 0.0;
    $deliveryTownId    = null;

    if ($isMarketOrder) {
        if ($selectedMethod === 'delivery' && $deliveryTowns) {
            $chosenTown = null;
            foreach ($deliveryTowns as $t) { if ((int)$t['town_id'] === $selectedTownId) { $chosenTown = $t; break; } }
            if (!$chosenTown) {
                $error = 'Please select a valid delivery town.';
            } else {
                $fulfillmentMethod = 'delivery';
                $fulfillmentFee    = (float)$chosenTown['delivery_fee'];
                $deliveryTownId    = (int)$chosenTown['town_id'];
                if ($deliveryAddress === '') $error = 'Please enter a delivery address within ' . $chosenTown['name'] . '.';
            }
        } else {
            $fulfillmentMethod = 'pickup';
            $fulfillmentFee    = (float)$quote['pickup_fee'];
            $deliveryAddress   = null;
            $deliveryMapsLink  = null;
        }
        if (!$error && $receiverName === '')  $error = 'Please enter the receiver name.';
        if (!$error && $receiverPhone === '') $error = 'Please enter the receiver phone number.';
    } else {
        if ($deliveryAddress === '') $error = 'Please enter a delivery address.';
        elseif ($receiverName === '')  $error = 'Please enter the receiver name.';
        elseif ($receiverPhone === '') $error = 'Please enter the receiver phone number.';
    }
    if (!$error && !paystack_configured()) $error = 'Payment gateway is not configured. Please contact support.';

    $grandTotal = round((float)$quote['total_amount'] + $fulfillmentFee + $systemCharge, 2);

    if (!$error) {
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO mp_orders (customer_id, shop_id, market_id, fulfillment_method, delivery_town_id, total_amount, delivery_fee, system_charge, delivery_address, delivery_maps_link, receiver_name, receiver_phone, payment_method, notes, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,\'paystack\',?,\'pending\')'
            )->execute([$user['id'], $quote['shop_id'], $quote['market_id'] ?: null, $fulfillmentMethod, $deliveryTownId, $grandTotal, $fulfillmentFee, $systemCharge, $deliveryAddress, $deliveryMapsLink, $receiverName, $receiverPhone, $notes ?: null]);
            $orderId = (int)$pdo->lastInsertId();

            foreach ($quoteItems as $qi) {
                $itemLabel = $qi['item_name'] . ($qi['quantity_note'] ? ' (' . $qi['quantity_note'] . ')' : '');
                $pdo->prepare(
                    'INSERT INTO mp_order_items (order_id, product_id, product_name, price, quantity, subtotal) VALUES (?,NULL,?,?,1,?)'
                )->execute([$orderId, mb_substr($itemLabel, 0, 255), $qi['price'], $qi['price']]);
            }

            $pdo->commit();

            $result = initializePayment(
                (int)$user['id'], $user['email'], 'mp_order', $orderId, 0, $grandTotal,
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

        <?php if ($isMarketOrder): ?>
        <div class="co-card">
            <p class="co-section-title">🚚 How do you want it?</p>
            <label style="display:flex;align-items:flex-start;gap:8px;padding:8px 0;cursor:pointer;font-weight:400;">
                <input type="radio" name="fulfillment_method" value="pickup" onchange="pqUpdateFulfillment()" id="pq-method-pickup" <?php echo $selectedMethod==='pickup'?'checked':''; ?> style="margin-top:3px;">
                <span><strong>Pickup at Storehouse</strong> — GH&#8373; <?php echo number_format((float)$quote['pickup_fee'],2); ?><br><span style="font-size:.78rem;color:var(--text-muted,#6b7280);">Collect it yourself once it's ready.</span></span>
            </label>
            <?php if ($deliveryTowns): ?>
            <label style="display:flex;align-items:flex-start;gap:8px;padding:8px 0;cursor:pointer;font-weight:400;border-top:1px solid var(--border);">
                <input type="radio" name="fulfillment_method" value="delivery" onchange="pqUpdateFulfillment()" id="pq-method-delivery" <?php echo $selectedMethod==='delivery'?'checked':''; ?> style="margin-top:3px;">
                <span><strong>Deliver to My Town</strong><br><span style="font-size:.78rem;color:var(--text-muted,#6b7280);">The market agent brings it to your town for a fee that depends where you are.</span></span>
            </label>
            <div id="pq-town-panel" style="<?php echo $selectedMethod==='delivery'?'':'display:none;'; ?>padding-left:26px;">
                <div class="form-group">
                    <label for="delivery_town_id">Town *</label>
                    <select id="delivery_town_id" name="delivery_town_id" onchange="pqUpdateFulfillment()">
                        <option value="">Select your town…</option>
                        <?php foreach ($deliveryTowns as $t): ?>
                        <option value="<?php echo $t['town_id']; ?>" data-fee="<?php echo sanitize($t['delivery_fee']); ?>" <?php echo $selectedTownId===(int)$t['town_id']?'selected':''; ?>>
                            <?php echo sanitize($t['name']); ?>, <?php echo sanitize($t['district']); ?> — GH&#8373; <?php echo number_format((float)$t['delivery_fee'],2); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="delivery_address">Delivery Address *</label>
                    <textarea id="delivery_address" name="delivery_address" rows="2"
                              placeholder="Specific address / landmark within the town"><?php echo sanitize($_POST['delivery_address'] ?? ''); ?></textarea>
                </div>
                <div class="form-group">
                    <label for="delivery_maps_link">Google Maps Link <span style="font-weight:400;color:var(--text-muted,#6b7280);">(optional)</span></label>
                    <input type="url" id="delivery_maps_link" name="delivery_maps_link"
                           placeholder="https://maps.google.com/…"
                           value="<?php echo sanitize($_POST['delivery_maps_link'] ?? ''); ?>">
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="co-card">
            <p class="co-section-title">📍 Contact Details</p>
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
        <?php else: ?>
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
        <?php endif; ?>

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
            <?php if ($isMarketOrder): ?>
            <?php if ($systemCharge > 0): ?>
            <div class="co-item"><div style="flex:1;font-size:.85rem;font-weight:600;">System Charge</div><div style="font-weight:800;font-size:.88rem;">GH&#8373; <?php echo number_format($systemCharge,2); ?></div></div>
            <?php endif; ?>
            <div class="co-item"><div style="flex:1;font-size:.85rem;font-weight:600;" id="pq-fee-label">Pickup Fee</div><div style="font-weight:800;font-size:.88rem;" id="pq-fee-amount">GH&#8373; <?php echo number_format((float)$quote['pickup_fee'],2); ?></div></div>
            <div class="co-grand"><span>Total</span><span id="pq-grand-total">GH&#8373; <?php echo number_format((float)$quote['total_amount'] + (float)$quote['pickup_fee'] + $systemCharge,2); ?></span></div>
            <?php else: ?>
            <div class="co-grand"><span>Total</span><span>GH&#8373; <?php echo number_format((float)$quote['total_amount'],2); ?></span></div>
            <p style="font-size:.75rem;color:var(--text-muted,#6b7280);margin:8px 0 0;">Delivery fees are negotiated with riders during delivery.</p>
            <?php endif; ?>
        </div>

        <button type="submit" class="button button-primary" style="width:100%;padding:14px;font-size:1rem;">
            Pay with Paystack →
        </button>
    </form>

    <?php if ($isMarketOrder): ?>
    <script>
    var pqItemsTotal   = <?php echo (float)$quote['total_amount']; ?>;
    var pqPickupFee    = <?php echo (float)$quote['pickup_fee']; ?>;
    var pqSystemCharge = <?php echo $systemCharge; ?>;
    function pqUpdateFulfillment() {
        var isDelivery = document.getElementById('pq-method-delivery') && document.getElementById('pq-method-delivery').checked;
        var townPanel = document.getElementById('pq-town-panel');
        if (townPanel) townPanel.style.display = isDelivery ? '' : 'none';

        var fee = pqPickupFee;
        var label = 'Pickup Fee';
        if (isDelivery) {
            var sel = document.getElementById('delivery_town_id');
            var opt = sel ? sel.options[sel.selectedIndex] : null;
            fee = (opt && opt.value) ? parseFloat(opt.getAttribute('data-fee')) : 0;
            label = 'Delivery Fee';
        }
        document.getElementById('pq-fee-label').textContent = label;
        document.getElementById('pq-fee-amount').textContent = 'GH₵ ' + fee.toFixed(2);
        document.getElementById('pq-grand-total').textContent = 'GH₵ ' + (pqItemsTotal + pqSystemCharge + fee).toFixed(2);
    }
    pqUpdateFulfillment();
    </script>
    <?php endif; ?>

</main>

<?php require_once __DIR__ . '/partials/bottom_nav.php'; ?>
</body>
</html>
