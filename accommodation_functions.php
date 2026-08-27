<?php
/**
 * Accommodation module — shared helper functions.
 * Include with: require_once __DIR__ . '/accommodation_functions.php';
 */

function get_accommodation_listing(int $id): ?array {
    global $pdo;
    $st = $pdo->prepare(
        'SELECT al.*, at.name AS type_name, at.slug AS type_slug, at.category AS type_category,
                t.name AS town_name, t.district AS town_district,
                u.name AS owner_name, u.username AS owner_username, u.banned AS owner_banned, u.phone AS owner_phone
         FROM accommodation_listings al
         JOIN accommodation_types at ON al.accommodation_type_id = at.id
         JOIN users u ON al.user_id = u.id
         LEFT JOIN towns t ON al.town_id = t.id
         WHERE al.id = ?'
    );
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function get_accommodation_images(int $listingId): array {
    global $pdo;
    $st = $pdo->prepare('SELECT * FROM accommodation_images WHERE listing_id = ? ORDER BY is_primary DESC, sort_order ASC');
    $st->execute([$listingId]);
    return $st->fetchAll();
}

function get_accommodation_primary_image(int $listingId): ?string {
    global $pdo;
    $st = $pdo->prepare('SELECT image_path FROM accommodation_images WHERE listing_id = ? ORDER BY is_primary DESC, sort_order ASC LIMIT 1');
    $st->execute([$listingId]);
    return $st->fetchColumn() ?: null;
}

/** All active accommodation_types, optionally narrowed to one category. */
function get_accommodation_types(?string $category = null): array {
    global $pdo;
    if ($category) {
        $st = $pdo->prepare("SELECT * FROM accommodation_types WHERE status='active' AND category=? ORDER BY sort_order, name");
        $st->execute([$category]);
    } else {
        $st = $pdo->query("SELECT * FROM accommodation_types WHERE status='active' ORDER BY category, sort_order, name");
    }
    return $st->fetchAll();
}

function get_accommodation_facilities(): array {
    global $pdo;
    return $pdo->query("SELECT * FROM accommodation_facilities WHERE status='active' ORDER BY sort_order, name")->fetchAll();
}

/** Facility id => name map, for rendering a listing's stored facility-id JSON as labels. */
function accommodation_facility_labels(): array {
    static $map = null;
    if ($map === null) {
        global $pdo;
        $map = [];
        foreach ($pdo->query('SELECT id, name, icon FROM accommodation_facilities')->fetchAll() as $f) {
            $map[(int)$f['id']] = $f;
        }
    }
    return $map;
}

function accommodation_status_label(string $status): string {
    return [
        'draft'            => 'Draft',
        'pending_approval' => 'Pending Review',
        'approved'         => 'Active',
        'rejected'         => 'Rejected',
        'archived'         => 'Archived',
    ][$status] ?? ucfirst($status);
}

function accommodation_status_color(string $status): string {
    return ['approved'=>'#10b981','pending_approval'=>'#f59e0b','rejected'=>'#ef4444','draft'=>'#6b7280','archived'=>'#6b7280'][$status] ?? '#9ca3af';
}

function accommodation_availability_label(string $status): string {
    return [
        'available'               => 'Available',
        'unavailable'              => 'Unavailable',
        'rented'                   => 'Rented',
        'temporarily_unavailable'  => 'Temporarily Unavailable',
        'fully_booked'             => 'Fully Booked',
    ][$status] ?? ucfirst(str_replace('_', ' ', $status));
}

/** "Area - Town" when both are set, otherwise whichever one is present. */
function accommodation_location_label(?string $area, ?string $town): string {
    $parts = array_filter([trim((string)$area), trim((string)$town)], fn($v) => $v !== '');
    return implode(' - ', $parts);
}

function accommodation_price_period_label(string $period): string {
    return [
        'night'       => 'per night',
        'week'        => 'per week',
        'month'       => 'per month',
        'year'        => 'per year',
        'negotiable'  => 'negotiable',
        'on_request'  => 'price on request',
    ][$period] ?? $period;
}

/**
 * Shared WHERE fragment for every public listing query — approved status,
 * and never surface a listing owned by a banned user, mirroring
 * marketplace.php's browse query (ms.user_id NOT IN (SELECT id FROM users
 * WHERE banned=1)).
 */
function accommodation_public_where(): string {
    return "al.status = 'approved' AND al.user_id NOT IN (SELECT id FROM users WHERE banned = 1)";
}

// ── Listing Packages (subscription gating the right to publish) ────────────
// Mirrors marketplace_functions.php's get_shop_active_subscription() /
// mp_shop_can_list_product() / mp_activate_subscription(), scoped to a user
// instead of a shop.

function get_user_active_accommodation_subscription(int $userId): ?array {
    global $pdo;
    $st = $pdo->prepare(
        "SELECT als.*, alp.name AS package_name, alp.listing_limit
         FROM accommodation_listing_subscriptions als
         JOIN accommodation_listing_packages alp ON als.package_id = alp.id
         WHERE als.user_id = ? AND als.status = 'active' AND als.end_date >= CURDATE()
         ORDER BY als.end_date DESC LIMIT 1"
    );
    $st->execute([$userId]);
    return $st->fetch() ?: null;
}

function accommodation_active_listing_count(int $userId): int {
    global $pdo;
    $st = $pdo->prepare("SELECT COUNT(*) FROM accommodation_listings WHERE user_id = ? AND status IN ('pending_approval','approved')");
    $st->execute([$userId]);
    return (int)$st->fetchColumn();
}

/**
 * Whether $userId may publish (or resubmit) one more accommodation listing
 * right now. Intentionally permissive — same opt-in-only behavior as
 * mp_shop_can_list_product() — until the admin turns the subscription
 * requirement on, and complimentary members always bypass it.
 * @return array{allowed:bool, limit:int, used:int, unlimited:bool, no_subscription:bool}
 */
function accommodation_can_list(int $userId): array {
    // Same Free/Hybrid/Paid monetization_mode every other paid feature
    // respects (is_feature_paid() in functions.php) — not a standalone
    // toggle. In Free mode this is always false regardless of the
    // 'enable_paid_accommodation_listing' setting; in Paid mode always true.
    // Complimentary-access bypass is handled inside is_feature_paid() itself
    // (checks the 'enable_paid_accommodation_listing' key, registered in
    // all_complimentary_features()), so no separate check is needed here.
    if (!is_feature_paid('enable_paid_accommodation_listing')) {
        return ['allowed' => true, 'limit' => -1, 'used' => 0, 'unlimited' => true, 'no_subscription' => false];
    }
    $sub = get_user_active_accommodation_subscription($userId);
    if (!$sub) {
        return ['allowed' => false, 'limit' => 0, 'used' => 0, 'unlimited' => false, 'no_subscription' => true];
    }
    $limit = (int)$sub['listing_limit'];
    if ($limit === -1) {
        return ['allowed' => true, 'limit' => -1, 'used' => 0, 'unlimited' => true, 'no_subscription' => false];
    }
    $used = accommodation_active_listing_count($userId);
    return ['allowed' => $used < $limit, 'limit' => $limit, 'used' => $used, 'unlimited' => false, 'no_subscription' => false];
}

/**
 * Activates a (paid or free) accommodation_listing_subscriptions row — the
 * one shared path for a first purchase, a renewal, or a plain plan switch.
 * Kept deliberately simpler than mp_activate_subscription() (no deferred
 * downgrades/proration) since listing packages don't carry those
 * marketplace-specific complications.
 */
function accommodation_activate_subscription(int $subscriptionId, ?int $paymentId = null): void {
    global $pdo;
    $subSt = $pdo->prepare(
        "SELECT als.*, alp.name AS package_name FROM accommodation_listing_subscriptions als
         JOIN accommodation_listing_packages alp ON als.package_id = alp.id WHERE als.id = ?"
    );
    $subSt->execute([$subscriptionId]);
    $sub = $subSt->fetch();
    if (!$sub) return;

    $pdo->prepare("UPDATE accommodation_listing_subscriptions SET status='active', payment_id=?, activated_at=NOW() WHERE id=?")
        ->execute([$paymentId, $subscriptionId]);
    // Superseding any other still-active subscription for this user — a
    // plain replace, not a deferred switch, matching this feature's
    // deliberately simpler scope.
    $pdo->prepare("UPDATE accommodation_listing_subscriptions SET status='cancelled', cancelled_at=NOW() WHERE user_id=? AND id!=? AND status='active'")
        ->execute([$sub['user_id'], $subscriptionId]);

    notify_user((int)$sub['user_id'], '⭐ Listing Package Activated!',
        $sub['package_name'] . ' is active until ' . date('d M Y', strtotime($sub['end_date'])) . '. You can now list accommodation.',
        'success', 'my_accommodation.php');
}

/**
 * If accommodation_form.php had to save a first-time listing as a draft
 * because the user had no active listing package yet, this publishes that
 * draft the moment a package actually activates — free/instant or paid via
 * the Paystack webhook. Exact mirror of marketplace_functions.php's
 * mp_publish_pending_draft(), keyed by its own session variable so the two
 * pending-draft flows (marketplace product vs. accommodation listing) never
 * collide with each other.
 */
function accommodation_publish_pending_draft(int $userId): void {
    if (empty($_SESSION['accommodation_pending_publish_id'])) return;
    global $pdo;
    $listingId = (int)$_SESSION['accommodation_pending_publish_id'];
    unset($_SESSION['accommodation_pending_publish_id']);

    $st = $pdo->prepare("SELECT id, title FROM accommodation_listings WHERE id=? AND user_id=? AND status='draft'");
    $st->execute([$listingId, $userId]);
    $listing = $st->fetch();
    if (!$listing) return;

    $pdo->prepare("UPDATE accommodation_listings SET status='pending_approval', updated_at=NOW() WHERE id=?")->execute([$listingId]);
    notify_moderators('manage_accommodation', 'New Accommodation Listing Pending Approval',
        'A listing "' . $listing['title'] . '" was submitted for review. Check Admin → Accommodation.');
}
