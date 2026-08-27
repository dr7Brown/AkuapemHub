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

// Whether AkuapemConnect delivery is even offered for anything in the cart —
// a seller can turn this off per product (seller_product_form.php's
// "Available for delivery" checkbox). If nothing in the cart supports it,
// there's no real choice to offer the buyer: skip the Delivery Method
// question entirely and go straight to self-arranged pickup.
$anyDeliverable = false;
foreach ($items as $item) {
    if (!empty($item['delivery_available'])) { $anyDeliverable = true; break; }
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
$customerCharge = get_mp_customer_charge($grandTotal);

// Fast Payout: a Paystack split targets exactly one subaccount per
// transaction, so it's only usable when this checkout is a single shop's
// cart. A cart spanning multiple shops always falls back to the standard
// flow, even if every shop involved has Fast Payout enabled.
$fastPayoutShop = null;
if (count($byShop) === 1 && get_platform_setting('mp_fast_payout_module_enabled', '0') === '1') {
    $onlyShopId = array_key_first($byShop);
    $fpStmt = $pdo->prepare("SELECT id, paystack_subaccount_code FROM mp_shops WHERE id=? AND fast_payout_enabled=1 AND paystack_subaccount_code IS NOT NULL");
    $fpStmt->execute([$onlyShopId]);
    $fastPayoutShop = $fpStmt->fetch() ?: null;

    // Skip the split when an active promotion discount applies — Paystack's
    // subaccount split runs against the ACTUAL (discounted) charged amount
    // set inside initializePayment(), which would then no longer match this
    // app's own net_amount math (based on the order's undiscounted
    // total_amount). The standard flow doesn't have this problem since the
    // platform captures 100% itself regardless of what Paystack settles.
    if ($fastPayoutShop) {
        $promoStmt = $pdo->prepare("SELECT 1 FROM promotion_claims WHERE user_id=? AND payment_type='mp_order' AND status='active' AND expiry_date >= CURDATE() AND discount_percent IS NOT NULL LIMIT 1");
        $promoStmt->execute([$user['id']]);
        if ($promoStmt->fetchColumn()) $fastPayoutShop = null;
    }
}

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
    // Not a real choice when nothing in the cart supports platform delivery —
    // force self-arranged server-side regardless of what was submitted.
    $deliveryMode = !$anyDeliverable ? 'self_arranged'
        : (($_POST['delivery_mode'] ?? 'platform') === 'self_arranged' ? 'self_arranged' : 'platform');

    if ($error) {
        // Closed-market gate already tripped above — leave it as the error.
    } elseif ($deliveryMode === 'platform' && $deliveryAddress === '') $error = 'Please enter a delivery address.';
    elseif ($deliveryMode === 'platform' && $receiverName === '')  $error = 'Please enter the receiver name.';
    elseif ($deliveryMode === 'platform' && $receiverPhone === '') $error = 'Please enter the receiver phone number.';
    elseif (is_banned_from_feature((int)$user['id'], 'mp')) $error = 'You have been restricted from the Marketplace. Contact support if you believe this is an error.';
    elseif (!paystack_configured()) $error = 'Payment gateway is not configured. Please contact support.';

    if (!$error) {
        // Resolve Fast Payout eligibility (and lock the subaccount to
        // 'manual') BEFORE any order rows are created, so mp_orders.fast_payout
        // always matches whether the split will actually be used — never set
        // it optimistically and fall back later, or the sweep would treat an
        // order as fast-payout'd when its money never left the platform's
        // own balance.
        $fastPayoutSubaccountCode = null;
        if ($fastPayoutShop) {
            if (mp_ensure_fast_payout_locked((int)$fastPayoutShop['id'])) {
                $fastPayoutSubaccountCode = $fastPayoutShop['paystack_subaccount_code'];
            } else {
                $fastPayoutShop = null; // lock couldn't be confirmed — use the standard flow instead
            }
        }

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
                    'INSERT INTO mp_orders (customer_id, shop_id, market_id, total_amount, delivery_address, delivery_maps_link, receiver_name, receiver_phone, payment_method, notes, status, fast_payout, delivery_mode) VALUES (?,?,?,?,?,?,?,?,\'paystack\',?,\'pending\',?,?)'
                )->execute([$user['id'], $shopId, $group['market_id'], $actualSubtotal, $deliveryAddress, $deliveryMapsLink, $receiverName, $receiverPhone, $notes ?: null, $fastPayoutShop ? 1 : 0, $deliveryMode]);
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
            $checkoutCharge = get_mp_customer_charge($payTotal);

            // Record the charge on each order (reusing mp_orders.system_charge,
            // already used by the Nearby Markets custom-quote flow) so receipts
            // and per-order reporting can see it — split proportionally by each
            // order's share of the total, with any rounding remainder absorbed
            // by the last order so the parts always sum exactly to the whole.
            if ($checkoutCharge > 0) {
                $chargeRemaining = $checkoutCharge;
                $orderIdList = array_keys($orderMeta);
                foreach ($orderIdList as $idx => $oid) {
                    $isLast = $idx === count($orderIdList) - 1;
                    $share = $isLast ? $chargeRemaining : round($orderMeta[$oid]['subtotal'] / $payTotal * $checkoutCharge, 2);
                    $chargeRemaining -= $share;
                    $pdo->prepare('UPDATE mp_orders SET system_charge=? WHERE id=?')->execute([$share, $oid]);
                }
            }

            // If splitting to a Fast Payout subaccount, override its stored
            // percentage_charge with the exact amount that must stay with
            // the platform for THIS transaction — commission plus the full
            // buyer-side checkout charge — so the seller's subaccount lands
            // on precisely the same net_amount the ledger will record in
            // activatePurchasedFeature()'s mp_order case below, not a flat
            // percentage of a total that includes fees that aren't theirs.
            $fastPayoutMainSharePesewas = null;
            if ($fastPayoutSubaccountCode) {
                $commissionPct = (float)get_platform_setting('mp_commission_percent', '10');
                $commissionAmt = round($payTotal * $commissionPct / 100, 2);
                $netAmt        = round($payTotal - $commissionAmt, 2);
                $mainShare     = round(($payTotal + $checkoutCharge) - $netAmt, 2);
                $fastPayoutMainSharePesewas = (int)round($mainShare * 100);
            }

            $result = initializePayment(
                (int)$user['id'], $user['email'], 'mp_order', $orderIds[0], 0, $payTotal + $checkoutCharge,
                ['order_ids' => $orderIds], $fastPayoutSubaccountCode, $fastPayoutMainSharePesewas
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
            <?php if (!$anyDeliverable): ?>
            <input type="hidden" name="delivery_mode" value="self_arranged">
            <div class="form-group">
                <p style="font-size:.82rem;padding:10px 12px;background:#e0f2fe;border-radius:8px;color:#075985;margin:0;">
                    🤝 The seller(s) in this order don't offer AkuapemConnect delivery for these items — you'll arrange pickup or delivery directly with them after checkout.
                </p>
            </div>
            <?php else: ?>
            <div class="form-group">
                <label>Delivery Method *</label>
                <?php $selectedDeliveryMode = $_POST['delivery_mode'] ?? 'platform'; ?>
                <label class="co-delivery-mode-option" style="display:flex;gap:10px;align-items:flex-start;padding:10px 12px;border:1.5px solid var(--border);border-radius:10px;margin-bottom:8px;cursor:pointer;font-weight:400;">
                    <input type="radio" name="delivery_mode" value="platform" onchange="coToggleDeliveryFields(this.value)" style="width:auto;margin-top:2px;" <?php echo $selectedDeliveryMode !== 'self_arranged' ? 'checked' : ''; ?>>
                    <span>
                        <strong style="display:block;font-size:.88rem;">🚚 AkuapemConnect Delivery Riders</strong>
                        <span style="font-size:.78rem;color:var(--text-muted,#6b7280);">Recommended. Once the seller marks your order ready, a delivery request goes out to verified local riders automatically.</span>
                    </span>
                </label>
                <label class="co-delivery-mode-option" style="display:flex;gap:10px;align-items:flex-start;padding:10px 12px;border:1.5px solid var(--border);border-radius:10px;cursor:pointer;font-weight:400;">
                    <input type="radio" name="delivery_mode" value="self_arranged" onchange="coToggleDeliveryFields(this.value)" style="width:auto;margin-top:2px;" <?php echo $selectedDeliveryMode === 'self_arranged' ? 'checked' : ''; ?>>
                    <span>
                        <strong style="display:block;font-size:.88rem;">🤝 I'll Arrange My Own Pickup</strong>
                        <span style="font-size:.78rem;color:var(--text-muted,#6b7280);">No delivery request will be created. Coordinate pickup or delivery directly with the seller after checkout.</span>
                    </span>
                </label>
            </div>
            <div id="co-platform-delivery-fields">
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
                <div style="display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:12px;">
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
            </div>
            <div id="co-self-arranged-note" style="display:none;font-size:.82rem;padding:10px 12px;background:#e0f2fe;border-radius:8px;color:#075985;margin-bottom:14px;">
                🤝 You'll coordinate pickup or delivery details directly with the seller after checkout — no address is needed here.
            </div>
            <?php endif; ?>
            <div class="form-group">
                <label for="notes">Order Notes (optional)</label>
                <input type="text" id="notes" name="notes" placeholder="Any special instructions?"
                       value="<?php echo sanitize($_POST['notes'] ?? ''); ?>">
            </div>
            <?php if ($anyDeliverable): ?>
            <script>
            function coToggleDeliveryFields(mode) {
                var isSelfArranged = mode === 'self_arranged';
                document.getElementById('co-platform-delivery-fields').style.display = isSelfArranged ? 'none' : '';
                document.getElementById('co-self-arranged-note').style.display = isSelfArranged ? '' : 'none';
                ['delivery_address', 'receiver_name', 'receiver_phone'].forEach(function (id) {
                    document.getElementById(id).required = !isSelfArranged;
                });
            }
            coToggleDeliveryFields(document.querySelector('input[name="delivery_mode"]:checked').value);
            </script>
            <?php endif; ?>
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
                    <div style="flex:1;min-width:0;overflow-wrap:break-word;font-size:.85rem;font-weight:600;"><?php echo sanitize(mb_substr($item['name'],0,50)); ?> <span style="color:var(--text-muted,#6b7280);">× <?php echo $item['quantity']; ?></span></div>
                    <div style="flex-shrink:0;font-weight:800;font-size:.88rem;">GH&#8373; <?php echo number_format(mp_effective_price($item)*$item['quantity'],2); ?></div>
                </div>
                <?php endforeach; ?>
                <div class="co-total-row" style="margin-top:6px;"><span>Subtotal</span><span><strong>GH&#8373; <?php echo number_format($group['subtotal'],2); ?></strong></span></div>
            </div>
            <?php endforeach; ?>
            <?php if ($customerCharge > 0): ?>
            <div class="co-total-row"><span>Service Charge</span><span>GH&#8373; <?php echo number_format($customerCharge,2); ?></span></div>
            <?php endif; ?>
            <div class="co-grand"><span>Grand Total</span><span>GH&#8373; <?php echo number_format($grandTotal + $customerCharge,2); ?></span></div>
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
