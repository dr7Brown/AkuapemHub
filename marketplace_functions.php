<?php
/**
 * Marketplace Module — shared helper functions.
 * Include with: require_once __DIR__ . '/marketplace_functions.php';
 */

// ── Slugs ─────────────────────────────────────────────────────────────────────

function mp_slugify(string $text): string {
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9\s-]/', '', $text);
    $text = preg_replace('/[\s-]+/', '-', $text);
    return trim($text, '-') ?: 'item';
}

function mp_unique_slug(string $base, string $table, string $column, PDO $pdo, int $excludeId = 0): string {
    $slug = mp_slugify($base);
    $try  = $slug;
    $i    = 0;
    do {
        $st = $pdo->prepare("SELECT id FROM $table WHERE $column = ? AND id != ?");
        $st->execute([$try, $excludeId]);
        if (!$st->fetchColumn()) break;
        $try = $slug . '-' . (++$i);
    } while (true);
    return $try;
}

// ── Fetchers ──────────────────────────────────────────────────────────────────

function get_shop_by_user(int $userId): ?array {
    global $pdo;
    $st = $pdo->prepare('SELECT * FROM mp_shops WHERE user_id = ?');
    $st->execute([$userId]);
    return $st->fetch() ?: null;
}

function get_shop(int $id): ?array {
    global $pdo;
    $st = $pdo->prepare('SELECT ms.*, u.name AS owner_name, u.username AS owner_username FROM mp_shops ms JOIN users u ON ms.user_id = u.id WHERE ms.id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

// ── Seller subscription / listing-limit enforcement ─────────────────────────

function get_shop_active_subscription(int $shopId): ?array {
    global $pdo;
    $st = $pdo->prepare(
        "SELECT mss.*, msp.name AS plan_name, msp.product_limit, msp.badge_name, msp.badge_color,
                msp.max_images, msp.unlimited_images, msp.featured_shop_included,
                msp.featured_products_included, msp.priority_ranking, msp.analytics_access,
                msp.support_level, msp.verification_included, msp.duration_days
         FROM mp_seller_subscriptions mss
         JOIN mp_seller_subscription_plans msp ON mss.plan_id = msp.id
         WHERE mss.shop_id = ? AND mss.status = 'active' AND mss.end_date >= CURDATE()
         ORDER BY mss.end_date DESC LIMIT 1"
    );
    $st->execute([$shopId]);
    return $st->fetch() ?: null;
}

// Whether the shop's owner has a complimentary membership — bypasses every
// subscription-based marketplace limit below.
function mp_shop_owner_is_complimentary(int $shopId): bool {
    global $pdo;
    $st = $pdo->prepare('SELECT user_id FROM mp_shops WHERE id = ?');
    $st->execute([$shopId]);
    $ownerId = $st->fetchColumn();
    return $ownerId ? user_has_complimentary_access((int)$ownerId) : false;
}

// Max images a shop's products may carry: -1 = unlimited, otherwise a count.
// Falls back to 5 (the app's long-standing hardcoded default) when the
// subscription module is off or the shop has no active plan, so behavior is
// unchanged for everyone until the admin opts in.
function mp_shop_max_images(int $shopId): int {
    if (mp_shop_owner_is_complimentary($shopId)) return -1;
    if (get_platform_setting('mp_subscription_enabled', '0') !== '1') {
        return 5;
    }
    $sub = get_shop_active_subscription($shopId);
    if (!$sub) return 5;
    return $sub['unlimited_images'] ? -1 : (int)$sub['max_images'];
}

// Active listing = a product that has been submitted for publishing (or is
// already live): pending_approval/approved/out_of_stock. A draft doesn't
// occupy a slot yet (it's not public), and archiving or selling through a
// listing frees its slot back up — per-design, the limit is on concurrently
// active listings, not a lifetime/monthly count.
function mp_shop_active_listing_count(int $shopId): int {
    global $pdo;
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM mp_products WHERE shop_id = ? AND status IN ('pending_approval','approved','out_of_stock')"
    );
    $st->execute([$shopId]);
    return (int)$st->fetchColumn();
}

/**
 * Whether a shop may list (or reactivate) one more product right now.
 * Returns ['allowed'=>bool, 'limit'=>int (-1=unlimited), 'used'=>int, 'unlimited'=>bool, 'no_subscription'=>bool].
 * When the module itself is off (mp_subscription_enabled='0'), this is
 * intentionally permissive — enforcement is entirely opt-in via that switch so
 * existing shops are unaffected until the admin turns it on. Once on, a shop
 * with no active subscription at all is blocked (the core business rule: every
 * shop must subscribe before publishing), distinct from a shop that has a plan
 * but has hit its limit.
 */
function mp_shop_can_list_product(int $shopId): array {
    if (mp_shop_owner_is_complimentary($shopId)) {
        return ['allowed' => true, 'limit' => -1, 'used' => 0, 'unlimited' => true, 'no_subscription' => false];
    }
    if (get_platform_setting('mp_subscription_enabled', '0') !== '1') {
        return ['allowed' => true, 'limit' => -1, 'used' => 0, 'unlimited' => true, 'no_subscription' => false];
    }
    $sub = get_shop_active_subscription($shopId);
    if (!$sub) {
        return ['allowed' => false, 'limit' => 0, 'used' => 0, 'unlimited' => false, 'no_subscription' => true];
    }
    $limit = (int)$sub['product_limit'];
    if ($limit === -1) {
        return ['allowed' => true, 'limit' => -1, 'used' => 0, 'unlimited' => true, 'no_subscription' => false];
    }
    $used = mp_shop_active_listing_count($shopId);
    return ['allowed' => $used < $limit, 'limit' => $limit, 'used' => $used, 'unlimited' => false, 'no_subscription' => false];
}

/**
 * Makes a (paid-for or free) mp_seller_subscriptions row take effect — the one
 * shared "activation" path for a first-time purchase, an upgrade, a renewal,
 * and a deferred downgrade reaching its scheduled start date.
 *
 * If the row's start_date is still in the future (a downgrade recorded ahead of
 * the current plan's expiry), it's marked 'pending_renewal' instead — paid for,
 * but not yet live — and mp_shops / the sibling subscription are left untouched
 * until sweep_expired_featured() calls this again once that date arrives.
 */
function mp_activate_subscription(int $subscriptionId, ?int $paymentId = null): void {
    global $pdo;

    $subSt = $pdo->prepare(
        "SELECT mss.*, msp.name AS plan_name, msp.price AS plan_price, ms.user_id, ms.shop_name
         FROM mp_seller_subscriptions mss
         JOIN mp_seller_subscription_plans msp ON mss.plan_id = msp.id
         JOIN mp_shops ms ON mss.shop_id = ms.id
         WHERE mss.id = ?"
    );
    $subSt->execute([$subscriptionId]);
    $sub = $subSt->fetch();
    if (!$sub) return;

    // The most recent other subscription for this shop — used to tell whether
    // this is a first purchase, a renewal of the same plan, an upgrade, or a
    // downgrade, and (for non-deferred cases) which row to supersede.
    $priorSt = $pdo->prepare(
        "SELECT mss.*, msp.price AS plan_price FROM mp_seller_subscriptions mss
         JOIN mp_seller_subscription_plans msp ON mss.plan_id = msp.id
         WHERE mss.shop_id = ? AND mss.id != ? AND mss.status IN ('active','pending_renewal')
         ORDER BY mss.end_date DESC LIMIT 1"
    );
    $priorSt->execute([$sub['shop_id'], $subscriptionId]);
    $prior = $priorSt->fetch();

    if (!$prior) {
        $event = 'purchased';
    } elseif ((int)$prior['plan_id'] === (int)$sub['plan_id']) {
        $event = 'renewed';
    } elseif ((float)$sub['plan_price'] > (float)$prior['plan_price']) {
        $event = 'upgraded';
    } else {
        $event = 'downgraded';
    }

    $deferred = $sub['start_date'] > date('Y-m-d');

    if ($deferred) {
        $pdo->prepare("UPDATE mp_seller_subscriptions SET status='pending_renewal', payment_id=?, activated_at=NOW() WHERE id=?")
            ->execute([$paymentId, $subscriptionId]);
    } else {
        $pdo->prepare("UPDATE mp_seller_subscriptions SET status='active', payment_id=?, activated_at=NOW() WHERE id=?")
            ->execute([$paymentId, $subscriptionId]);
        $pdo->prepare("UPDATE mp_shops SET is_subscribed=1, subscription_plan_id=?, subscription_end=?, updated_at=NOW() WHERE id=?")
            ->execute([$sub['plan_id'], $sub['end_date'], $sub['shop_id']]);
        if ($prior && $prior['status'] === 'active') {
            $pdo->prepare("UPDATE mp_seller_subscriptions SET status='cancelled', cancelled_at=NOW() WHERE id=?")->execute([$prior['id']]);
        }
        notify_user((int)$sub['user_id'], '⭐ Subscription Activated!',
            $sub['plan_name'] . ' subscription for ' . $sub['shop_name'] . ' is active until ' . date('d M Y', strtotime($sub['end_date'])) . '.', 'success');
    }

    // A deferred downgrade is logged once when scheduled (status pending_renewal)
    // and reaches this function again once its start date arrives (status
    // active) — only log history the first time, so a single downgrade doesn't
    // appear twice in the seller's history list.
    $countSt = $pdo->prepare("SELECT COUNT(*) FROM mp_subscription_history WHERE subscription_id=?");
    $countSt->execute([$subscriptionId]);
    if ((int)$countSt->fetchColumn() === 0) {
        $pdo->prepare("INSERT INTO mp_subscription_history (subscription_id, shop_id, event, from_plan_id, to_plan_id, notes) VALUES (?,?,?,?,?,?)")
            ->execute([$subscriptionId, $sub['shop_id'], $event, $prior['plan_id'] ?? null, $sub['plan_id'],
                $deferred ? 'Takes effect ' . date('d M Y', strtotime($sub['start_date'])) : null]);
    }
}

/**
 * Voluntary (seller- or admin-initiated) cancellation. Terminates immediately —
 * there's no auto-renew to "stop" in this build, so cancelling an active plan
 * simply ends it now rather than waiting out the paid-for period. A scheduled
 * (pending_renewal) downgrade can also be cancelled before it ever takes effect.
 */
function mp_cancel_subscription(int $subscriptionId): bool {
    global $pdo;
    $subSt = $pdo->prepare(
        "SELECT mss.*, ms.user_id, ms.shop_name FROM mp_seller_subscriptions mss
         JOIN mp_shops ms ON mss.shop_id = ms.id
         WHERE mss.id = ? AND mss.status IN ('active','pending_renewal')"
    );
    $subSt->execute([$subscriptionId]);
    $sub = $subSt->fetch();
    if (!$sub) return false;

    $pdo->prepare("UPDATE mp_seller_subscriptions SET status='cancelled', cancelled_at=NOW() WHERE id=?")->execute([$subscriptionId]);

    if ($sub['status'] === 'active') {
        $pdo->prepare("UPDATE mp_shops SET is_subscribed=0, subscription_plan_id=NULL, subscription_end=NULL WHERE id=?")->execute([$sub['shop_id']]);
        notify_user((int)$sub['user_id'], 'Subscription Cancelled', $sub['shop_name'] . '\'s marketplace subscription has been cancelled. Existing products remain saved but are hidden from customers until you resubscribe.', 'warning', 'pay_mp_subscription.php');
    }

    $pdo->prepare("INSERT INTO mp_subscription_history (subscription_id, shop_id, event, from_plan_id) VALUES (?,?,'cancelled',?)")
        ->execute([$subscriptionId, $sub['shop_id'], $sub['plan_id']]);

    return true;
}

function get_product(int $id): ?array {
    global $pdo;
    $st = $pdo->prepare(
        'SELECT mp.*, ms.shop_name, ms.slug AS shop_slug, ms.id AS shop_id,
                ms.user_id AS shop_owner_id, ms.rating AS shop_rating,
                ms.verification_status AS shop_verified,
                mc.name AS category_name, mc.slug AS category_slug,
                mc.icon AS category_icon
         FROM mp_products mp
         JOIN mp_shops ms ON mp.shop_id = ms.id
         LEFT JOIN mp_categories mc ON mp.category_id = mc.id
         WHERE mp.id = ?'
    );
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function get_product_images(int $productId): array {
    global $pdo;
    $st = $pdo->prepare('SELECT * FROM mp_product_images WHERE product_id = ? ORDER BY is_primary DESC, sort_order ASC');
    $st->execute([$productId]);
    return $st->fetchAll();
}

function get_product_primary_image(int $productId): ?string {
    global $pdo;
    $st = $pdo->prepare('SELECT image_path FROM mp_product_images WHERE product_id = ? ORDER BY is_primary DESC, sort_order ASC LIMIT 1');
    $st->execute([$productId]);
    return $st->fetchColumn() ?: null;
}

// ── Cart ──────────────────────────────────────────────────────────────────────

function mp_get_or_create_cart(int $userId): int {
    global $pdo;
    $st = $pdo->prepare('SELECT id FROM mp_cart WHERE user_id = ?');
    $st->execute([$userId]);
    $id = $st->fetchColumn();
    if ($id) return (int)$id;
    $pdo->prepare('INSERT INTO mp_cart (user_id) VALUES (?)')->execute([$userId]);
    return (int)$pdo->lastInsertId();
}

function mp_get_cart_count(int $userId): int {
    global $pdo;
    try {
        $st = $pdo->prepare('SELECT COALESCE(SUM(ci.quantity),0) FROM mp_cart c JOIN mp_cart_items ci ON ci.cart_id=c.id WHERE c.user_id=?');
        $st->execute([$userId]);
        return (int)$st->fetchColumn();
    } catch (Exception $e) { return 0; }
}

function mp_get_cart_items(int $userId): array {
    global $pdo;
    $st = $pdo->prepare(
        'SELECT ci.*, mp.name, mp.price, mp.discount_price, mp.stock_quantity, mp.status,
                ms.shop_name, ms.id AS shop_id, ms.slug AS shop_slug,
                mpi.image_path AS primary_image
         FROM mp_cart c
         JOIN mp_cart_items ci ON ci.cart_id = c.id
         JOIN mp_products mp   ON ci.product_id = mp.id
         JOIN mp_shops ms      ON mp.shop_id = ms.id
         LEFT JOIN mp_product_images mpi ON mpi.product_id = mp.id AND mpi.is_primary = 1
         WHERE c.user_id = ?
         ORDER BY ms.id, ci.added_at ASC'
    );
    $st->execute([$userId]);
    return $st->fetchAll();
}

// ── Pricing ───────────────────────────────────────────────────────────────────

function mp_effective_price(array $product): float {
    if (!empty($product['discount_price']) && (float)$product['discount_price'] > 0) {
        return (float)$product['discount_price'];
    }
    return (float)$product['price'];
}

function mp_discount_pct(array $product): int {
    if (empty($product['discount_price']) || (float)$product['discount_price'] <= 0) return 0;
    return (int)round((1 - $product['discount_price'] / $product['price']) * 100);
}

// ── Status labels ─────────────────────────────────────────────────────────────

function mp_order_status_label(string $status): string {
    return [
        'pending'              => 'Pending',
        'confirmed'            => 'Confirmed',
        'processing'           => 'Processing',
        'ready_for_delivery'   => 'Ready for Delivery',
        'in_transit'           => 'In Transit',
        'delivered'            => 'Delivered',
        'cancelled'            => 'Cancelled',
        'refunded'             => 'Refunded',
    ][$status] ?? ucfirst($status);
}

function mp_order_status_color(string $status): string {
    return [
        'pending'            => '#f59e0b',
        'confirmed'          => '#3b82f6',
        'processing'         => '#8b5cf6',
        'ready_for_delivery' => '#f97316',
        'in_transit'         => '#06b6d4',
        'delivered'          => '#10b981',
        'cancelled'          => '#ef4444',
        'refunded'           => '#6b7280',
    ][$status] ?? '#9ca3af';
}

function mp_order_status_bg(string $status): string {
    return [
        'pending'            => '#fef3c7',
        'confirmed'          => '#dbeafe',
        'processing'         => '#ede9fe',
        'ready_for_delivery' => '#ffedd5',
        'in_transit'         => '#cffafe',
        'delivered'          => '#d1fae5',
        'cancelled'          => '#fee2e2',
        'refunded'           => '#f3f4f6',
    ][$status] ?? '#f3f4f6';
}

function mp_product_status_label(string $status): string {
    return [
        'draft'            => 'Draft',
        'pending_approval' => 'Pending Review',
        'approved'         => 'Active',
        'rejected'         => 'Rejected',
        'out_of_stock'     => 'Out of Stock',
        'archived'         => 'Archived',
    ][$status] ?? ucfirst($status);
}

function mp_product_status_color(string $status): string {
    return ['approved'=>'#10b981','pending_approval'=>'#f59e0b','rejected'=>'#ef4444','draft'=>'#6b7280','out_of_stock'=>'#ef4444','archived'=>'#6b7280'][$status] ?? '#9ca3af';
}

function mp_product_status_bg(string $status): string {
    return ['approved'=>'#d1fae5','pending_approval'=>'#fef3c7','rejected'=>'#fee2e2','draft'=>'#f3f4f6','out_of_stock'=>'#fee2e2','archived'=>'#f3f4f6'][$status] ?? '#f3f4f6';
}

function mp_condition_label(string $c): string {
    return ['new'=>'New','used'=>'Used','refurbished'=>'Refurbished'][$c] ?? ucfirst($c);
}

function mp_condition_color(string $c): string {
    return ['new'=>'#10b981','used'=>'#f59e0b','refurbished'=>'#3b82f6'][$c] ?? '#9ca3af';
}

// ── Shop rating refresh ───────────────────────────────────────────────────────

function mp_refresh_shop_rating(int $shopId): void {
    global $pdo;
    $st = $pdo->prepare('SELECT AVG(rating) FROM mp_reviews WHERE shop_id = ? AND review_type = "seller"');
    $st->execute([$shopId]);
    $avg = (float)$st->fetchColumn();
    $pdo->prepare('UPDATE mp_shops SET rating = ? WHERE id = ?')->execute([round($avg, 2), $shopId]);
}

// ── Payment / stock reservation cleanup ───────────────────────────────────────

/**
 * Cancel one or more pending, unpaid marketplace orders and restore the stock
 * that was reserved for them at checkout. Used both when Paystack init fails
 * immediately, and by the abandoned-order cron sweep for checkouts the buyer
 * never completed.
 */
function mp_cancel_order_and_restore_stock(array $orderIds, string $reason = ''): void {
    global $pdo;
    if (!$orderIds) return;

    foreach ($orderIds as $orderId) {
        $orderId = (int)$orderId;
        $order = $pdo->prepare("SELECT * FROM mp_orders WHERE id=? AND payment_status='unpaid'");
        $order->execute([$orderId]);
        $order = $order->fetch();
        if (!$order) continue; // already paid or already cancelled — leave it alone

        $items = $pdo->prepare('SELECT product_id, quantity FROM mp_order_items WHERE order_id=?');
        $items->execute([$orderId]);
        foreach ($items->fetchAll() as $it) {
            if (!$it['product_id']) continue;
            $pdo->prepare('UPDATE mp_products SET stock_quantity = stock_quantity + ? WHERE id=?')
                ->execute([$it['quantity'], $it['product_id']]);
            // Bring a sold-out listing back if this restock gives it stock again
            $pdo->prepare("UPDATE mp_products SET status='approved' WHERE id=? AND status='out_of_stock' AND stock_quantity>0")
                ->execute([$it['product_id']]);
        }

        $pdo->prepare("UPDATE mp_orders SET status='cancelled', notes=CONCAT(COALESCE(notes,''), ?), updated_at=NOW() WHERE id=?")
            ->execute([$reason ? "\n[System] {$reason}" : '', $orderId]);
    }
}

/**
 * Refund a single PAID marketplace order: restores its reserved stock and
 * reverses whatever was already credited to the seller's wallet (from
 * whichever balance currently holds it), then marks the order refunded.
 * Called by admin after they've processed the actual Paystack refund.
 */
function mp_refund_order(array $order, string $reason = ''): void {
    global $pdo;
    if ($order['payment_status'] !== 'paid') return;

    $items = $pdo->prepare('SELECT product_id, quantity FROM mp_order_items WHERE order_id=?');
    $items->execute([$order['id']]);
    foreach ($items->fetchAll() as $it) {
        if (!$it['product_id']) continue;
        $pdo->prepare('UPDATE mp_products SET stock_quantity = stock_quantity + ? WHERE id=?')
            ->execute([$it['quantity'], $it['product_id']]);
        $pdo->prepare("UPDATE mp_products SET status='approved' WHERE id=? AND status='out_of_stock' AND stock_quantity>0")
            ->execute([$it['product_id']]);
    }

    if ($order['net_amount'] !== null) {
        $balCol = $order['payout_released'] ? 'available_balance' : 'pending_balance';
        $pdo->prepare("UPDATE mp_shops SET $balCol = $balCol - ? WHERE id=?")
            ->execute([$order['net_amount'], $order['shop_id']]);
        $pdo->prepare('INSERT INTO mp_wallet_transactions (shop_id, order_id, type, amount, created_at) VALUES (?,?,?,?,NOW())')
            ->execute([$order['shop_id'], $order['id'], 'reversal', -$order['net_amount']]);
    }

    $pdo->prepare("UPDATE mp_orders SET status='refunded', payment_status='refunded', notes=CONCAT(COALESCE(notes,''), ?), updated_at=NOW() WHERE id=?")
        ->execute([$reason ? "\n[System] {$reason}" : '', $order['id']]);

    $shopOwner = $pdo->prepare('SELECT user_id FROM mp_shops WHERE id=?');
    $shopOwner->execute([$order['shop_id']]);
    if ($uid = $shopOwner->fetchColumn()) {
        notify_user((int)$uid, 'Order Refunded',
            'Order #' . $order['id'] . ' was refunded to the buyer. GH₵ ' . number_format((float)$order['net_amount'], 2) . ' was reversed from your wallet balance.',
            'warning', 'seller_dashboard.php?tab=wallet');
    }
}

// ── Delivery integration ──────────────────────────────────────────────────────

/**
 * When a seller marks an order ready_for_delivery, auto-create a delivery_request.
 *
 * Skips the admin approval queue — an arbitrary courier request from a
 * stranger needs screening, but the product and shop here were already
 * vetted by the platform at listing/checkout time, so requiring a second
 * manual admin approval just adds delay with no real safety benefit.
 */
function mp_create_delivery_for_order(array $order, array $shop): ?int {
    global $pdo;
    try {
        $itemsStmt = $pdo->prepare('SELECT product_name, quantity FROM mp_order_items WHERE order_id = ?');
        $itemsStmt->execute([$order['id']]);
        $items    = $itemsStmt->fetchAll();
        $itemDesc = implode(', ', array_map(fn($i) => $i['quantity'] . 'x ' . $i['product_name'], $items));

        $stmt = $pdo->prepare(
            'INSERT INTO delivery_requests
             (customer_id, pickup_location, pickup_maps_link, pickup_contact_name, pickup_contact_phone,
              dropoff_location, receiver_name, receiver_phone,
              item_description, item_category, delivery_fee, payment_method,
              status, auto_approved, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,\'parcels\',?,\'cash\',\'approved\',1,NOW(),NOW())'
        );
        $stmt->execute([
            $order['customer_id'],
            $shop['shop_name'] . ($shop['region'] ? ', ' . $shop['region'] : ''),
            $shop['google_maps_link'] ?? null,
            $shop['shop_name'],
            $shop['phone'] ?? '',
            $order['delivery_address'],
            $order['receiver_name'],
            $order['receiver_phone'],
            'Marketplace order #' . $order['id'] . ': ' . mb_substr($itemDesc, 0, 200),
            $order['delivery_fee'],
        ]);
        $deliveryId = (int)$pdo->lastInsertId();

        // Notify active agents immediately — same notification the admin-approval
        // path sends, since this request is posted and open for applications now.
        $agents = $pdo->query(
            "SELECT user_id FROM delivery_agents WHERE verification_status='approved' AND availability_status IN('available','busy')"
        )->fetchAll();
        foreach ($agents as $ag) {
            notify_user((int)$ag['user_id'], 'New Delivery Job Available',
                "A new delivery request (#$deliveryId) is open. Open your agent dashboard to apply.", 'info');
        }

        notify_user((int)$order['customer_id'], 'Order Out for Delivery Matching 🚚',
            'Your order #' . $order['id'] . ' is ready and now visible to delivery riders nearby.', 'info',
            'delivery_detail.php?id=' . $deliveryId);
        $pdo->prepare('UPDATE mp_orders SET delivery_request_id = ? WHERE id = ?')
            ->execute([$deliveryId, $order['id']]);
        return $deliveryId;
    } catch (Exception $e) {
        return null;
    }
}

// ── Seller payouts (Paystack Transfers) ───────────────────────────────────────

/**
 * Fires (or retries) the actual Paystack transfer for a withdrawal request.
 * Used by BOTH auto mode (called right after the seller submits the request)
 * and manual mode (called when an admin clicks "Approve & Pay") — Paystack
 * always does the money movement, only the timing/human-checkpoint differs.
 *
 * Startable from 'pending' (first attempt) or 'failed' (admin retry).
 * Leaves the request in 'failed' with a reason on any error — the admin can
 * retry, or fall back to "Mark Paid Manually" if Paystack isn't viable for it.
 */
function process_marketplace_payout(int $payoutRequestId, int $adminId = 0): array {
    global $pdo;
    require_once __DIR__ . '/paystack.php';

    $req = $pdo->prepare("SELECT * FROM mp_payout_requests WHERE id=? AND status IN ('pending','failed')");
    $req->execute([$payoutRequestId]);
    $req = $req->fetch();
    if (!$req) {
        return ['success' => false, 'error' => 'Request not found or already processed.'];
    }
    if (!$req['payout_account_id']) {
        return ['success' => false, 'error' => 'This request has no saved payout account to transfer to — use "Mark Paid Manually" instead.'];
    }

    // Atomically "claim" the request first — this is the actual mutex against
    // two concurrent calls (e.g. a double-click) both processing the same
    // request; the balance check alone wouldn't stop that since a healthy
    // balance would let both deductions through.
    $claim = $pdo->prepare("UPDATE mp_payout_requests SET status='processing', failure_reason=NULL WHERE id=? AND status IN ('pending','failed')");
    $claim->execute([$payoutRequestId]);
    if ($claim->rowCount() === 0) {
        return ['success' => false, 'error' => 'Request not found or already being processed.'];
    }

    // Atomic — only deduct if the shop still has enough available balance.
    $upd = $pdo->prepare("UPDATE mp_shops SET available_balance = available_balance - ? WHERE id=? AND available_balance >= ?");
    $upd->execute([$req['amount'], $req['shop_id'], $req['amount']]);
    if ($upd->rowCount() === 0) {
        $pdo->prepare("UPDATE mp_payout_requests SET status='failed', failure_reason=? WHERE id=?")
            ->execute(['Shop no longer has enough available balance.', $payoutRequestId]);
        return ['success' => false, 'error' => 'Shop no longer has enough available balance for this payout.'];
    }

    $account = $pdo->prepare('SELECT * FROM mp_payout_accounts WHERE id=?');
    $account->execute([$req['payout_account_id']]);
    $account = $account->fetch();

    $fail = function (string $reason) use ($pdo, $req, $payoutRequestId, $adminId) {
        // Roll back the balance deduction and put the request back for retry.
        $pdo->prepare('UPDATE mp_shops SET available_balance = available_balance + ? WHERE id=?')
            ->execute([$req['amount'], $req['shop_id']]);
        $pdo->prepare("UPDATE mp_payout_requests SET status='failed', failure_reason=?, reviewed_by=?, reviewed_at=NOW() WHERE id=?")
            ->execute([$reason, $adminId ?: null, $payoutRequestId]);
        $shopOwner = $pdo->prepare('SELECT user_id, shop_name FROM mp_shops WHERE id=?');
        $shopOwner->execute([$req['shop_id']]);
        $shop = $shopOwner->fetch();
        notify_admins_and_managers('Seller Payout Failed',
            ($shop['shop_name'] ?? 'A shop') . "'s withdrawal of GH₵ " . number_format((float)$req['amount'], 2) . " failed: {$reason}. Review in Admin → Marketplace Payouts.",
            'error');
        log_audit_action($adminId, 'mp_payout_transfer_failed', "Payout #{$payoutRequestId} failed: {$reason}");
        return ['success' => false, 'error' => $reason];
    };

    if (!$account) {
        return $fail('Saved payout account was not found.');
    }

    $recipient = paystack_get_or_create_recipient($account);
    if (!$recipient['success']) {
        return $fail($recipient['error']);
    }

    $transfer = paystack_initiate_transfer($payoutRequestId, $recipient['recipient_code'], (float)$req['amount'], 'AkuapemConnect seller withdrawal #' . $payoutRequestId);
    if (!$transfer['success']) {
        return $fail($transfer['error']);
    }

    $finalStatus = $transfer['status'] === 'success' ? 'paid' : 'processing';
    $pdo->prepare("UPDATE mp_payout_requests SET status=?, paystack_transfer_code=?, paystack_transfer_reference=?, reviewed_by=?, reviewed_at=NOW(), paid_at=? WHERE id=?")
        ->execute([$finalStatus, $transfer['transfer_code'], $transfer['reference'], $adminId ?: null, $finalStatus === 'paid' ? date('Y-m-d H:i:s') : null, $payoutRequestId]);

    $pdo->prepare('INSERT INTO mp_wallet_transactions (shop_id, payout_id, type, amount, created_at) VALUES (?,?,\'withdrawal\',?,NOW())')
        ->execute([$req['shop_id'], $payoutRequestId, $req['amount']]);

    $shopOwner = $pdo->prepare('SELECT user_id FROM mp_shops WHERE id=?');
    $shopOwner->execute([$req['shop_id']]);
    if ($uid = $shopOwner->fetchColumn()) {
        notify_user((int)$uid,
            $finalStatus === 'paid' ? 'Withdrawal Paid 💵' : 'Withdrawal Processing ⏳',
            'Your withdrawal of GH₵ ' . number_format((float)$req['amount'], 2) . ($finalStatus === 'paid' ? ' has been paid.' : ' has been sent and is processing — you\'ll be notified once it completes.'),
            'success', 'seller_dashboard.php?tab=wallet');
    }
    log_audit_action($adminId, 'mp_payout_transfer_initiated', "Payout #{$payoutRequestId} — GHS " . number_format((float)$req['amount'], 2) . " — status: {$finalStatus}");

    return ['success' => true, 'status' => $finalStatus];
}

/**
 * Finalizes a payout once Paystack's transfer.success/failed/reversed webhook
 * arrives. Idempotent — only acts on requests still in 'processing', so
 * duplicate webhook deliveries (or a request already resolved another way)
 * are safely ignored.
 */
function finalize_marketplace_payout_transfer(string $reference, string $event): void {
    global $pdo;

    $req = $pdo->prepare("SELECT * FROM mp_payout_requests WHERE paystack_transfer_reference=? AND status='processing'");
    $req->execute([$reference]);
    $req = $req->fetch();
    if (!$req) return; // already finalized, or not one of ours

    if ($event === 'transfer.success') {
        $pdo->prepare("UPDATE mp_payout_requests SET status='paid', paid_at=NOW() WHERE id=?")->execute([$req['id']]);
        $shopOwner = $pdo->prepare('SELECT user_id FROM mp_shops WHERE id=?');
        $shopOwner->execute([$req['shop_id']]);
        if ($uid = $shopOwner->fetchColumn()) {
            notify_user((int)$uid, 'Withdrawal Paid 💵',
                'Your withdrawal of GH₵ ' . number_format((float)$req['amount'], 2) . ' has been paid.',
                'success', 'seller_dashboard.php?tab=wallet');
        }
        log_audit_action(0, 'mp_payout_transfer_confirmed', "Payout #{$req['id']} confirmed paid via Paystack webhook");
        return;
    }

    // transfer.failed or transfer.reversed — reverse the deduction so the
    // seller can retry or the admin can pay another way.
    $pdo->prepare('UPDATE mp_shops SET available_balance = available_balance + ? WHERE id=?')
        ->execute([$req['amount'], $req['shop_id']]);
    $pdo->prepare('INSERT INTO mp_wallet_transactions (shop_id, payout_id, type, amount, created_at) VALUES (?,?,\'reversal\',?,NOW())')
        ->execute([$req['shop_id'], $req['id'], -$req['amount']]);
    $pdo->prepare("UPDATE mp_payout_requests SET status='failed', failure_reason=? WHERE id=?")
        ->execute(["Paystack reported: {$event}", $req['id']]);

    $shopOwner = $pdo->prepare('SELECT user_id, shop_name FROM mp_shops WHERE id=?');
    $shopOwner->execute([$req['shop_id']]);
    $shop = $shopOwner->fetch();
    if ($shop) {
        notify_user((int)$shop['user_id'], 'Withdrawal Failed',
            'Your withdrawal of GH₵ ' . number_format((float)$req['amount'], 2) . ' could not be completed. The amount has been returned to your available balance.',
            'error', 'seller_dashboard.php?tab=wallet');
    }
    notify_admins_and_managers('Seller Payout Failed (Paystack)',
        ($shop['shop_name'] ?? 'A shop') . "'s withdrawal of GH₵ " . number_format((float)$req['amount'], 2) . " failed via webhook ({$event}). Review in Admin → Marketplace Payouts.",
        'error');
    log_audit_action(0, 'mp_payout_transfer_failed_webhook', "Payout #{$req['id']} failed via webhook: {$event}");
}
