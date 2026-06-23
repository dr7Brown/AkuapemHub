<?php
/**
 * Delivery Services Module — shared helper functions.
 * Include with: require_once __DIR__ . '/delivery_functions.php';
 */

function get_delivery_agent_for_user(int $userId): ?array {
    global $pdo;
    $stmt = $pdo->prepare('SELECT * FROM delivery_agents WHERE user_id = ?');
    $stmt->execute([$userId]);
    return $stmt->fetch() ?: null;
}

function get_delivery_request(int $id): ?array {
    global $pdo;
    $stmt = $pdo->prepare(
        'SELECT dr.*,
                cu.name AS customer_name, cu.username AS customer_username,
                cu.phone AS customer_phone, cu.profile_photo AS customer_photo,
                da.id AS da_id, da.vehicle_type, da.rating AS agent_rating,
                da.availability_status AS agent_availability,
                au.id AS agent_user_id, au.name AS agent_name,
                au.username AS agent_username, au.phone AS agent_phone,
                au.profile_photo AS agent_photo
         FROM delivery_requests dr
         JOIN users cu ON dr.customer_id = cu.id
         LEFT JOIN delivery_agents da ON dr.agent_id = da.id
         LEFT JOIN users au ON da.user_id = au.id
         WHERE dr.id = ?'
    );
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function delivery_status_label(string $status): string {
    return [
        'pending'    => 'Pending',
        'accepted'   => 'Accepted',
        'picked_up'  => 'Picked Up',
        'in_transit' => 'In Transit',
        'delivered'  => 'Delivered',
        'cancelled'  => 'Cancelled',
        'failed'     => 'Failed Delivery',
    ][$status] ?? ucfirst($status);
}

function delivery_status_color(string $status): string {
    return [
        'pending'    => '#f59e0b',
        'accepted'   => '#3b82f6',
        'picked_up'  => '#8b5cf6',
        'in_transit' => '#f97316',
        'delivered'  => '#10b981',
        'cancelled'  => '#ef4444',
        'failed'     => '#ef4444',
    ][$status] ?? '#6b7280';
}

function delivery_status_bg(string $status): string {
    return [
        'pending'    => '#fef3c7',
        'accepted'   => '#dbeafe',
        'picked_up'  => '#ede9fe',
        'in_transit' => '#ffedd5',
        'delivered'  => '#d1fae5',
        'cancelled'  => '#fee2e2',
        'failed'     => '#fee2e2',
    ][$status] ?? '#f3f4f6';
}

function vehicle_type_label(string $type): string {
    return [
        'motorbike'        => 'Motorbike',
        'bicycle'          => 'Bicycle',
        'car'              => 'Car',
        'van'              => 'Van',
        'truck'            => 'Truck',
        'public_transport' => 'Public Transport',
        'other'            => 'Other Vehicle',
    ][$type] ?? ucfirst($type);
}

function vehicle_type_icon(string $type): string {
    return [
        'motorbike'        => '🛵',
        'bicycle'          => '🚲',
        'car'              => '🚗',
        'van'              => '🚐',
        'truck'            => '🚛',
        'public_transport' => '🚌',
        'other'            => '🚚',
    ][$type] ?? '🚗';
}

function item_category_label(string $cat): string {
    return [
        'documents'        => 'Documents',
        'food'             => 'Food',
        'electronics'      => 'Electronics',
        'clothing'         => 'Clothing',
        'medical_supplies' => 'Medical Supplies',
        'groceries'        => 'Groceries',
        'parcels'          => 'Parcels',
        'other'            => 'Other',
    ][$cat] ?? ucfirst($cat);
}

function item_category_icon(string $cat): string {
    return [
        'documents'        => '📄',
        'food'             => '🍔',
        'electronics'      => '📱',
        'clothing'         => '👕',
        'medical_supplies' => '💊',
        'groceries'        => '🛒',
        'parcels'          => '📦',
        'other'            => '📦',
    ][$cat] ?? '📦';
}

/** Returns next status options an agent can move a delivery to. */
function delivery_agent_next_statuses(string $current): array {
    return [
        'accepted'   => ['picked_up'],
        'picked_up'  => ['in_transit'],
        'in_transit' => ['delivered', 'failed'],
    ][$current] ?? [];
}

/** Notify both customer and agent of a delivery status change. */
function notify_delivery_update(array $request, string $title, string $body, string $type = 'info'): void {
    notify_user((int)$request['customer_id'], $title, $body, $type);
    if (!empty($request['agent_id'])) {
        global $pdo;
        $agentUserId = $pdo->prepare('SELECT user_id FROM delivery_agents WHERE id = ?');
        $agentUserId->execute([$request['agent_id']]);
        $uid = $agentUserId->fetchColumn();
        if ($uid) notify_user((int)$uid, $title, $body, $type);
    }
}

/** Recalculate and persist an agent's average rating and completed deliveries count. */
function refresh_agent_stats(int $agentId): void {
    global $pdo;
    $row = $pdo->prepare(
        'SELECT AVG(dr.customer_rating) AS avg_r,
                (SELECT COUNT(*) FROM delivery_requests WHERE agent_id = ? AND status = "delivered") AS done_cnt
         FROM delivery_ratings dr
         JOIN delivery_requests req ON dr.delivery_request_id = req.id
         WHERE req.agent_id = ? AND dr.customer_rating IS NOT NULL'
    );
    $row->execute([$agentId, $agentId]);
    $data = $row->fetch();
    $pdo->prepare('UPDATE delivery_agents SET rating = ?, completed_deliveries = ? WHERE id = ?')
        ->execute([
            $data['avg_r'] !== null ? round((float)$data['avg_r'], 2) : 0.00,
            (int)$data['done_cnt'],
            $agentId,
        ]);
}

// ── v012: Monetization & Workflow helpers ────────────────────────────────────

/** True when agent's sponsored listing is currently active. */
function agent_is_sponsored(array $agent): bool {
    return !empty($agent['is_sponsored'])
        && !empty($agent['sponsored_end'])
        && $agent['sponsored_end'] >= date('Y-m-d');
}

/** True when agent's premium subscription is currently active. */
function agent_is_premium(array $agent): bool {
    return !empty($agent['is_premium'])
        && !empty($agent['premium_end'])
        && $agent['premium_end'] >= date('Y-m-d');
}

/** True when agent has the Verified Rider badge. */
function agent_is_verified(array $agent): bool {
    return !empty($agent['is_verified']);
}

/**
 * Returns integer priority for ORDER BY (higher = shown first).
 * Sponsored=4 > Premium+Verified=3 > Premium=2 > Verified=1 > Basic=0
 */
function agent_priority(array $agent): int {
    $sp = agent_is_sponsored($agent);
    $pm = agent_is_premium($agent);
    $vf = agent_is_verified($agent);
    if ($sp)        return 4;
    if ($pm && $vf) return 3;
    if ($pm)        return 2;
    if ($vf)        return 1;
    return 0;
}

/** SQL expression for ORDER BY priority (can be embedded directly in a query). */
function agent_priority_sql(string $alias = 'da'): string {
    return "(CASE
        WHEN {$alias}.is_sponsored = 1 AND {$alias}.sponsored_end >= CURDATE() THEN 4
        WHEN {$alias}.is_premium = 1 AND {$alias}.premium_end >= CURDATE() AND {$alias}.is_verified = 1 THEN 3
        WHEN {$alias}.is_premium = 1 AND {$alias}.premium_end >= CURDATE() THEN 2
        WHEN {$alias}.is_verified = 1 THEN 1
        ELSE 0
    END)";
}

/**
 * Returns HTML badge chips for a rider card.
 */
function agent_badges_html(array $agent): string {
    $html = '';
    if (agent_is_sponsored($agent)) {
        $html .= '<span style="background:#f59e0b;color:#fff;font-size:.62rem;font-weight:800;padding:2px 7px;border-radius:20px;letter-spacing:.04em;">SPONSORED</span> ';
    }
    if (agent_is_premium($agent)) {
        $html .= '<span style="background:#8b5cf6;color:#fff;font-size:.62rem;font-weight:800;padding:2px 7px;border-radius:20px;letter-spacing:.04em;">PREMIUM</span> ';
    }
    if (agent_is_verified($agent)) {
        $html .= '<span style="background:#10b981;color:#fff;font-size:.62rem;font-weight:800;padding:2px 7px;border-radius:20px;letter-spacing:.04em;">&#10003; VERIFIED</span> ';
    }
    return $html;
}

/** Get this agent's existing application for a delivery request (or null). */
function get_delivery_application(int $requestId, int $agentId): ?array {
    global $pdo;
    $stmt = $pdo->prepare('SELECT * FROM delivery_applications WHERE delivery_request_id = ? AND agent_id = ?');
    $stmt->execute([$requestId, $agentId]);
    return $stmt->fetch() ?: null;
}

/**
 * Decide if a customer qualifies for auto-approval (based on platform settings).
 * Returns true → approve immediately; false → queue for admin review.
 */
function is_auto_approve_eligible(int $userId): bool {
    global $pdo;
    if (!get_platform_setting('delivery_require_approval', '1')) return true;
    $minDeliveries = (int)get_platform_setting('delivery_auto_approve_min_deliveries', '10');
    $minDays       = (int)get_platform_setting('delivery_auto_approve_min_days', '60');

    $userRow = $pdo->prepare('SELECT created_at FROM users WHERE id = ?');
    $userRow->execute([$userId]);
    $userCreated = $userRow->fetchColumn();
    if (!$userCreated) return false;

    $ageDays = (int)floor((time() - strtotime($userCreated)) / 86400);
    if ($ageDays < $minDays) return false;

    $done = $pdo->prepare('SELECT COUNT(*) FROM delivery_requests WHERE customer_id = ? AND status = "delivered"');
    $done->execute([$userId]);
    return (int)$done->fetchColumn() >= $minDeliveries;
}

/**
 * Runs basic fraud checks on a delivery request submission.
 * Returns a flag reason string if suspicious, null if clean.
 */
function check_delivery_fraud(array $post, int $customerId): ?string {
    global $pdo;

    // More than 5 requests in 24 hours
    $rate = $pdo->prepare('SELECT COUNT(*) FROM delivery_requests WHERE customer_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)');
    $rate->execute([$customerId]);
    if ($rate->fetchColumn() >= 5) return 'Excessive posting: more than 5 requests in 24 hours.';

    // Identical pickup+dropoff submitted within 1 hour
    $dup = $pdo->prepare('SELECT COUNT(*) FROM delivery_requests WHERE customer_id = ? AND pickup_location = ? AND dropoff_location = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)');
    $dup->execute([$customerId, $post['pickup_location'] ?? '', $post['dropoff_location'] ?? '']);
    if ($dup->fetchColumn() > 0) return 'Duplicate request: same pickup/drop-off submitted recently.';

    // Suspiciously low fee
    $fee = (float)($post['delivery_fee'] ?? 0);
    if ($fee > 0 && $fee < 1.0) return 'Suspiciously low delivery fee (under GHS 1.00).';

    return null;
}

/**
 * Render star rating HTML.
 * Static mode:      render_stars(4.2)
 * Interactive mode: render_stars(0, true, 'rating')  — CSS-only reverse trick
 */
function render_stars(float $rating, bool $interactive = false, string $name = 'rating'): string {
    $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    if ($interactive) {
        // Reverse-order radio inputs so CSS sibling selectors work correctly.
        // The hidden inputs are in order 5→1 (rendered right-to-left via CSS flex-direction:row-reverse).
        $out = '<div class="dl-stars-input" role="group" aria-label="Star rating">';
        for ($i = 5; $i >= 1; $i--) {
            $id = 'star' . $i . '_' . $safeName;
            $out .= '<input type="radio" name="' . $safeName . '" id="' . $id . '" value="' . $i . '" aria-label="' . $i . ' star' . ($i > 1 ? 's' : '') . '">';
            $out .= '<label for="' . $id . '" title="' . $i . ' stars">★</label>';
        }
        $out .= '</div>';
        $out .= '<p class="form-hint" style="margin-top:4px;">Click a star to rate</p>';
    } else {
        $full  = (int)floor($rating);
        $empty = max(0, 5 - $full);
        $out   = '<span class="dl-stars" aria-label="' . number_format($rating, 1) . ' out of 5 stars">';
        $out  .= str_repeat('<span class="dl-star-full" aria-hidden="true">★</span>', $full);
        $out  .= str_repeat('<span class="dl-star-empty" aria-hidden="true">★</span>', $empty);
        $out  .= ' <span class="dl-star-val">' . number_format($rating, 1) . '</span></span>';
    }
    return $out;
}
