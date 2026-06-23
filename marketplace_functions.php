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

// ── Delivery integration ──────────────────────────────────────────────────────

/**
 * When a seller marks an order ready_for_delivery, auto-create a delivery_request.
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
             (customer_id, pickup_location, pickup_contact_name, pickup_contact_phone,
              dropoff_location, receiver_name, receiver_phone,
              item_description, item_category, delivery_fee, payment_method,
              status, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,\'parcels\',?,\'cash\',\'pending_approval\',NOW(),NOW())'
        );
        $stmt->execute([
            $order['customer_id'],
            $shop['shop_name'] . ($shop['region'] ? ', ' . $shop['region'] : ''),
            $shop['shop_name'],
            $shop['phone'] ?? '',
            $order['delivery_address'],
            $order['receiver_name'],
            $order['receiver_phone'],
            'Marketplace order #' . $order['id'] . ': ' . mb_substr($itemDesc, 0, 200),
            $order['delivery_fee'],
        ]);
        $deliveryId = (int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE mp_orders SET delivery_request_id = ? WHERE id = ?')
            ->execute([$deliveryId, $order['id']]);
        return $deliveryId;
    } catch (Exception $e) {
        return null;
    }
}
