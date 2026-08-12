<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/marketplace_functions.php';
require_once __DIR__ . '/paystack.php';

require_module_enabled('mp', 'Marketplace');
require_login();
$user = current_user();

$items = mp_get_cart_items((int)$user['id']);
if (!$items) {
    flash('Your cart is empty.', 'info');
    header('Location: cart.php');
    exit;
}

// Group by shop
$byShop = [];
foreach ($items as $item) {
    $sid = $item['shop_id'];
    $byShop[$sid] = $byShop[$sid] ?? ['shop_name'=>$item['shop_name'],'shop_id'=>$sid,'market_id'=>$item['market_id'] ?: null,'items'=>[],'subtotal'=>0];
    $byShop[$sid]['items'][] = $item;
    $byShop[$sid]['subtotal'] += mp_effective_price($item) * $item['quantity'];
}
$grandTotal = array_sum(array_column($byShop,'subtotal'));

// Market shops can only be checked out while their market is marked Open —
// browsing/adding to cart stays allowed regardless (see marketplace.php /
// product.php), this is the sole purchase gate.
$closedMarketNames = [];
$marketShopIds = array_filter(array_unique(array_column($items, 'market_id')));
if ($marketShopIds) {
    $mktSt = $pdo->prepare('SELECT name FROM markets WHERE id IN (' . implode(',', array_fill(0, count($marketShopIds), '?')) . ") AND status != 'open'");
    $mktSt->execute(array_values($marketShopIds));
    $closedMarketNames = $mktSt->fetchAll(PDO::FETCH_COLUMN);
}

$error = '';
if ($closedMarketNames) {
    $error = 'Checkout is unavailable — ' . implode(', ', $closedMarketNames) . ' ' . (count($closedMarketNames) === 1 ? 'is' : 'are') . ' currently closed. Remove items from that market or wait until it reopens on the next market day.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $deliveryAddress  = trim($_POST['delivery_address']  ?? '');
    $deliveryMapsLink = trim($_POST['delivery_maps_link'] ?? '') ?: null;
    $receiverName     = trim($_POST['receiver_name']    ?? $user['name']);
    $receiverPhone    = trim($_POST['receiver_phone']   ?? $user['phone']);
    $notes            = trim($_POST['notes'] ?? '');

    if ($error) {
        // Closed-market gate already tripped above — leave it as the error.
    } elseif ($deliveryAddress === '') $error = 'Please enter a delivery address.';
    elseif ($receiverName === '')  $error = 'Please enter the receiver name.';
    elseif ($receiverPhone === '') $error = 'Please enter the receiver phone number.';
    elseif (is_banned_from_feature((int)$user['id'], 'mp')) $error = 'You have been restricted from the Marketplace. Contact support if you believe this is an error.';
    elseif (!paystack_configured()) $error = 'Payment gateway is not configured. Please contact support.';

    if (!$error) {
        $pdo->beginTransaction();
        try {
            $orderIds = [];
            $orderMeta = []; // orderId => ['shop_name'=>..., 'subtotal'=>actual fulfilled amount]
            $droppedItems = []; // across all shops, for the customer-facing message
            $reservedProductIds = []; // for rollback if payment init fails below

            foreach ($byShop as $shopId => $group) {
                // Deduct stock FIRST and only keep items that actually had stock —
                // the "stock_quantity >= ?" guard means a losing UPDATE (0 rows
                // affected) means someone else already took the last unit. This
                // reserves inventory for this checkout attempt; if payment is
                // never completed, the abandoned-order sweep in _cron.php
                // restores it (see sweep_abandoned_marketplace_orders()).
                $fulfilledItems = [];
                $shopDropped    = [];
                foreach ($group['items'] as $item) {
                    $upd = $pdo->prepare('UPDATE mp_products SET stock_quantity = stock_quantity - ? WHERE id = ? AND stock_quantity >= ?');
                    $upd->execute([$item['quantity'], $item['product_id'], $item['quantity']]);
                    if ($upd->rowCount() > 0) {
                        $fulfilledItems[] = $item;
                        $reservedProductIds[] = $item['product_id'];
                        // Flip to out_of_stock the moment the last unit sells — keeps
                        // the listing visible (seller keeps their reviews/history)
                        // but marketplace.php/product.php show it as unavailable.
                        $pdo->prepare("UPDATE mp_products SET status='out_of_stock' WHERE id=? AND stock_quantity=0 AND status='approved'")
                            ->execute([$item['product_id']]);
                    } else {
                        $shopDropped[] = $item;
                    }
                }

                if (!$fulfilledItems) {
                    // Nothing in this shop's group could be fulfilled — no order created.
                    $droppedItems = array_merge($droppedItems, $shopDropped);
                    continue;
                }

                $actualSubtotal = 0;
                foreach ($fulfilledItems as $item) $actualSubtotal += mp_effective_price($item) * $item['quantity'];

                // Create order (total reflects only what was actually fulfilled).
                // payment_status stays 'unpaid' / status 'pending' until Paystack confirms.
                $pdo->prepare(
                    'INSERT INTO mp_orders (customer_id, shop_id, market_id, total_amount, delivery_address, delivery_maps_link, receiver_name, receiver_phone, payment_method, notes, status) VALUES (?,?,?,?,?,?,?,?,\'paystack\',?,\'pending\')'
                )->execute([$user['id'], $shopId, $group['market_id'], $actualSubtotal, $deliveryAddress, $deliveryMapsLink, $receiverName, $receiverPhone, $notes ?: null]);
                $orderId = (int)$pdo->lastInsertId();
                $orderIds[] = $orderId;
                $orderMeta[$orderId] = ['shop_name' => $group['shop_name'], 'subtotal' => $actualSubtotal];

                // Create order items for what was fulfilled
                foreach ($fulfilledItems as $item) {
                    $price = mp_effective_price($item);
                    $pdo->prepare(
                        'INSERT INTO mp_order_items (order_id, product_id, product_name, price, quantity, subtotal) VALUES (?,?,?,?,?,?)'
                    )->execute([$orderId, $item['product_id'], $item['name'], $price, $item['quantity'], $price * $item['quantity']]);
                }

                // Record what was dropped, visible to the seller on this order
                foreach ($shopDropped as $item) {
                    $pdo->prepare(
                        'INSERT INTO mp_order_stock_issues (order_id, product_id, product_name, requested_qty) VALUES (?,?,?,?)'
                    )->execute([$orderId, $item['product_id'], $item['name'], $item['quantity']]);
                }
                $droppedItems = array_merge($droppedItems, $shopDropped);
            }

            if (!$orderIds) {
                // Every item in the cart sold out before checkout completed.
                $pdo->rollBack();
                flash('Sorry — everything in your cart just sold out. Please check your cart and try again.', 'error');
                header('Location: cart.php');
                exit;
            }

            // Clear cart — items are now reserved as pending orders
            $cartRow = $pdo->prepare('SELECT id FROM mp_cart WHERE user_id = ?');
            $cartRow->execute([$user['id']]);
            $cartId = $cartRow->fetchColumn();
            if ($cartId) $pdo->prepare('DELETE FROM mp_cart_items WHERE cart_id = ?')->execute([$cartId]);

            $pdo->commit();

            // ── Charge the buyer via Paystack for the actual (post-adjustment) total ──
            $payTotal = array_sum(array_column($orderMeta, 'subtotal'));
            $result = initializePayment(
                (int)$user['id'], $user['email'], 'mp_order', $orderIds[0], 0, $payTotal,
                ['order_ids' => $orderIds]
            );

            if (isset($result['error'])) {
                // Payment couldn't even start — release the reservation instead of
                // leaving the customer with a paid-for-nothing pending order.
                mp_cancel_order_and_restore_stock($orderIds, 'Payment could not be started: ' . $result['error']);
                flash('Could not start payment: ' . $result['error'] . '. Please try again.', 'error');
                header('Location: cart.php');
                exit;
            }

            $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
            $pdo->prepare("UPDATE mp_orders SET platform_payment_id=? WHERE id IN ($placeholders)")
                ->execute(array_merge([$result['payment_id']], $orderIds));

            if ($droppedItems) {
                $names = implode(', ', array_map(fn($i) => $i['quantity'] . '× ' . $i['name'], $droppedItems));
                flash("Note: {$names} just sold out and could not be included in your order.", 'warning');
            }

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
    <title>Checkout — AkuapemConnect</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .co-shell { max-width:700px; margin:0 auto; padding:16px 16px 80px; }
        .co-card  { background:var(--surface); border:1px solid var(--border); border-radius:14px; padding:18px; margin-bottom:14px; }
        .co-section-title { font-size:.75rem; font-weight:800; text-transform:uppercase; letter-spacing:.07em; color:var(--text-muted,#6b7280); margin:0 0 14px; }
        label { font-weight:600; font-size:.86rem; display:block; margin-bottom:4px; }
        .form-group { margin-bottom:14px; }
        .co-item { display:flex; gap:10px; align-items:center; padding:8px 0; border-bottom:1px solid var(--border); }
        .co-item:last-child { border-bottom:none; }
        .co-item-img { width:44px; height:44px; border-radius:8px; background:#f8fafc; flex-shrink:0; overflow:hidden; display:flex; align-items:center; justify-content:center; }
        .co-item-img img { width:100%; height:100%; object-fit:cover; }
        .co-total-row { display:flex; justify-content:space-between; font-size:.88rem; padding:4px 0; }
        .co-grand { display:flex; justify-content:space-between; font-size:1.1rem; font-weight:900; padding-top:10px; margin-top:8px; border-top:2px solid var(--border); }
    </style>
</head>
<body class="has-bottom-nav">

<header class="app-topbar">
    <a href="cart.php" class="button button-secondary button-small">← Cart</a>
    <span class="brand">Checkout</span>
</header>

<main class="co-shell">

    <?php if ($error): ?><div class="alert alert-error"><?php echo sanitize($error); ?></div><?php endif; ?>

    <form method="post" action="checkout.php">
        <?php echo csrf_field(); ?>

        <!-- Delivery Details -->
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
                <p style="font-size:.74rem;color:var(--text-muted,#6b7280);margin-top:3px;">Open Google Maps → find your delivery location → Share → Copy link.</p>
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

        <!-- Payment -->
        <div class="co-card">
            <p class="co-section-title">💳 Payment</p>
            <p class="meta" style="margin:0;">You'll be redirected to Paystack's secure checkout to pay by card or mobile money.</p>
        </div>

        <!-- Order Summary -->
        <div class="co-card">
            <p class="co-section-title">🛍️ Order Summary</p>
            <?php foreach ($byShop as $group): ?>
            <div style="margin-bottom:12px;">
                <div style="font-size:.78rem;font-weight:800;color:var(--text-muted,#6b7280);margin-bottom:6px;">🏪 <?php echo sanitize($group['shop_name']); ?></div>
                <?php foreach ($group['items'] as $item): ?>
                <div class="co-item">
                    <div class="co-item-img">
                        <?php if ($item['primary_image']): ?><img src="<?php echo sanitize($item['primary_image']); ?>" alt=""><?php else: ?><span style="font-size:1rem;opacity:.4;">📦</span><?php endif; ?>
                    </div>
                    <div style="flex:1;font-size:.85rem;font-weight:600;"><?php echo sanitize(mb_substr($item['name'],0,50)); ?> <span style="color:var(--text-muted,#6b7280);">× <?php echo $item['quantity']; ?></span></div>
                    <div style="font-weight:800;font-size:.88rem;">GH&#8373; <?php echo number_format(mp_effective_price($item)*$item['quantity'],2); ?></div>
                </div>
                <?php endforeach; ?>
                <div class="co-total-row" style="margin-top:6px;"><span>Subtotal</span><span><strong>GH&#8373; <?php echo number_format($group['subtotal'],2); ?></strong></span></div>
            </div>
            <?php endforeach; ?>
            <div class="co-grand"><span>Grand Total</span><span>GH&#8373; <?php echo number_format($grandTotal,2); ?></span></div>
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
