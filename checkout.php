<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/marketplace_functions.php';

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
    $byShop[$sid] = $byShop[$sid] ?? ['shop_name'=>$item['shop_name'],'shop_id'=>$sid,'items'=>[],'subtotal'=>0];
    $byShop[$sid]['items'][] = $item;
    $byShop[$sid]['subtotal'] += mp_effective_price($item) * $item['quantity'];
}
$grandTotal = array_sum(array_column($byShop,'subtotal'));

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $deliveryAddress  = trim($_POST['delivery_address']  ?? '');
    $deliveryMapsLink = trim($_POST['delivery_maps_link'] ?? '') ?: null;
    $receiverName     = trim($_POST['receiver_name']    ?? $user['name']);
    $receiverPhone    = trim($_POST['receiver_phone']   ?? $user['phone']);
    $paymentMethod   = $_POST['payment_method'] ?? 'cash_on_delivery';
    $notes           = trim($_POST['notes'] ?? '');

    $validPayments = ['cash_on_delivery','mobile_money','card','wallet'];
    if ($deliveryAddress === '') $error = 'Please enter a delivery address.';
    elseif ($receiverName === '')  $error = 'Please enter the receiver name.';
    elseif ($receiverPhone === '') $error = 'Please enter the receiver phone number.';
    elseif (!in_array($paymentMethod, $validPayments, true)) $error = 'Select a payment method.';

    if (!$error) {
        $pdo->beginTransaction();
        try {
            $orderIds = [];
            foreach ($byShop as $shopId => $group) {
                // Create order
                $pdo->prepare(
                    'INSERT INTO mp_orders (customer_id, shop_id, total_amount, delivery_address, delivery_maps_link, receiver_name, receiver_phone, payment_method, notes, status) VALUES (?,?,?,?,?,?,?,?,?,\'pending\')'
                )->execute([$user['id'], $shopId, $group['subtotal'], $deliveryAddress, $deliveryMapsLink, $receiverName, $receiverPhone, $paymentMethod, $notes ?: null]);
                $orderId = (int)$pdo->lastInsertId();
                $orderIds[] = $orderId;

                // Create order items
                foreach ($group['items'] as $item) {
                    $price = mp_effective_price($item);
                    $pdo->prepare(
                        'INSERT INTO mp_order_items (order_id, product_id, product_name, price, quantity, subtotal) VALUES (?,?,?,?,?,?)'
                    )->execute([$orderId, $item['product_id'], $item['name'], $price, $item['quantity'], $price * $item['quantity']]);

                    // Deduct stock
                    $pdo->prepare('UPDATE mp_products SET stock_quantity = stock_quantity - ? WHERE id = ? AND stock_quantity >= ?')
                        ->execute([$item['quantity'], $item['product_id'], $item['quantity']]);
                }

                // Notify seller
                $shopOwnerRow = $pdo->prepare('SELECT user_id FROM mp_shops WHERE id = ?');
                $shopOwnerRow->execute([$shopId]);
                $ownerId = $shopOwnerRow->fetchColumn();
                if ($ownerId) {
                    notify_user((int)$ownerId, 'New Order Received! 🛍️',
                        'You have a new order #' . $orderId . ' from ' . display_name($user) . '. Open your seller dashboard.',
                        'success');
                }
            }

            // Clear cart
            $cartRow = $pdo->prepare('SELECT id FROM mp_cart WHERE user_id = ?');
            $cartRow->execute([$user['id']]);
            $cartId = $cartRow->fetchColumn();
            if ($cartId) $pdo->prepare('DELETE FROM mp_cart_items WHERE cart_id = ?')->execute([$cartId]);

            // Notify customer (in-app + email receipt)
            notify_user((int)$user['id'], 'Order Placed Successfully ✅',
                'Your order' . (count($orderIds) > 1 ? 's have' : ' has') . ' been placed. The seller will confirm shortly.',
                'success');
            if (class_exists('EmailService') || file_exists(__DIR__ . '/services/EmailService.php')) {
                if (!class_exists('EmailService', false)) require_once __DIR__ . '/services/EmailService.php';
                foreach ($orderIds as $oid) {
                    $shopName = $byShop[array_keys($byShop)[array_search($oid, $orderIds)] ?? array_key_first($byShop)]['shop_name'] ?? '';
                    EmailService::sendReceipt(
                        $user['email'], $user['name'],
                        'MKT-' . str_pad($oid, 6, '0', STR_PAD_LEFT),
                        'Marketplace Order — ' . $shopName,
                        (float)($byShop[array_keys($byShop)[array_search($oid, $orderIds)] ?? array_key_first($byShop)]['subtotal'] ?? 0),
                        date('d M Y, g:i A'),
                        (int)$user['id']
                    );
                }
            }

            $pdo->commit();

            flash('Orders placed successfully! Sellers have been notified.', 'success');
            // Show receipt for first order; user can see all orders in orders.php
            $firstOrderId = $orderIds[0] ?? null;
            header('Location: ' . ($firstOrderId ? 'payment_receipt.php?type=marketplace_order&id=' . $firstOrderId : 'orders.php'));
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

        <!-- Payment Method -->
        <div class="co-card">
            <p class="co-section-title">💳 Payment Method</p>
            <?php $selPm = $_POST['payment_method'] ?? 'cash_on_delivery';
            $pmOptions = ['cash_on_delivery'=>'Cash on Delivery','mobile_money'=>'Mobile Money','card'=>'Card Payment','wallet'=>'Wallet Balance']; ?>
            <?php foreach ($pmOptions as $v => $l): ?>
            <label style="display:flex;align-items:center;gap:8px;padding:7px 0;cursor:pointer;font-size:.88rem;">
                <input type="radio" name="payment_method" value="<?php echo $v; ?>" <?php echo $selPm===$v?'checked':''; ?>>
                <?php echo sanitize($l); ?>
            </label>
            <?php endforeach; ?>
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
            Place Order →
        </button>
    </form>

</main>

<?php require_once __DIR__ . '/partials/bottom_nav.php'; ?>
</body>
</html>
