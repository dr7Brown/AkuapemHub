<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../marketplace_functions.php';

require_login();
if (!is_admin_or_manager()) { header('Location: ../jobs.php'); exit; }

// This file also hosts Quote Requests oversight (folded in from its own former
// page) alongside the original Products/Shops/Orders/Boosts/Settings tabs —
// entry is allowed via EITHER permission, but which tabs are visible/reachable
// is still gated per-permission below, so a moderator granted only
// manage_quote_requests never sees product/shop/order data, and vice versa.
$hasProductsPerm = is_admin() || has_mod_permission('approve_products');
$hasQuotesPerm   = is_admin() || has_mod_permission('manage_quote_requests');
if (!$hasProductsPerm && !$hasQuotesPerm) {
    require_mod_permission('approve_products'); // neither permission held — standard 403
}

$adminUser = current_user();
$tab       = $_GET['tab'] ?? ($hasProductsPerm ? 'products' : 'quotes');
if (($tab === 'quotes' && !$hasQuotesPerm) || ($tab === 'categories' && !is_admin()) || (!in_array($tab, ['quotes','categories'], true) && !$hasProductsPerm)) {
    header('Location: marketplace.php?tab=' . ($hasQuotesPerm ? 'quotes' : 'products'));
    exit;
}

// ── POST actions ──────────────────────────────────────────────────────────────
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $postAction = $_POST['action'] ?? '';

    // Product approve / reject
    if (in_array($postAction, ['approve_product','reject_product'], true) && !empty($_POST['product_id'])) {
        $pid    = (int)$_POST['product_id'];
        $reason = trim($_POST['rejection_reason'] ?? '');
        $prodRow = $pdo->prepare('SELECT mp.*, ms.user_id AS owner_id, ms.shop_name FROM mp_products mp JOIN mp_shops ms ON mp.shop_id=ms.id WHERE mp.id=?');
        $prodRow->execute([$pid]);
        $prod = $prodRow->fetch();
        if ($prod) {
            if (in_array($postAction, ['approve_product','reject_product'], true) && check_mod_coi('product', $pid, $adminUser['id'])) {
                log_coi_violation($adminUser['id'], 'product', $pid, $postAction);
                flash('Conflict of interest: you cannot moderate your own product.', 'error');
                header('Location: marketplace.php?tab=products'); exit;
            }
            if ($postAction === 'approve_product') {
                $pdo->prepare("UPDATE mp_products SET status='approved', updated_at=NOW() WHERE id=?")->execute([$pid]);
                notify_user((int)$prod['owner_id'], 'Product Approved ✅', '"' . $prod['name'] . '" is now live on the marketplace!', 'success');
                log_audit_action($adminUser['id'], 'mp_product_approve', 'Approved product #' . $pid . ': ' . $prod['name']);
                log_mod_activity($adminUser['id'], 'marketplace', 'approve_product', $pid, $prod['name']);
                flash('Product approved.', 'success');
            } else {
                if (!$reason) { flash('Rejection reason is required.', 'error'); header('Location: marketplace.php?tab=products'); exit; }
                $pdo->prepare("UPDATE mp_products SET status='rejected', rejection_reason=?, updated_at=NOW() WHERE id=?")->execute([$reason, $pid]);
                notify_user((int)$prod['owner_id'], '❌ Product Rejected — Action Required',
                    '"' . $prod['name'] . '" was not approved.' . "\n\nReason: " . $reason . "\n\nClick to edit and resubmit.",
                    'error', 'seller_dashboard.php?tab=products');
                log_audit_action($adminUser['id'], 'mp_product_reject', 'Rejected product #' . $pid . ': ' . $prod['name'] . '. Reason: ' . $reason);
                log_mod_activity($adminUser['id'], 'marketplace', 'reject_product', $pid, $prod['name']);
                flash('Product rejected.', 'info');
            }
        }
        header('Location: marketplace.php?tab=products'); exit;
    }

    // Shop verify / reject / suspend / unsuspend
    if (in_array($postAction, ['approve_shop','reject_shop','suspend_shop','unsuspend_shop'], true) && !empty($_POST['shop_id'])) {
        $sid     = (int)$_POST['shop_id'];
        $reason  = trim($_POST['rejection_reason'] ?? '');
        $shopRow = $pdo->prepare('SELECT ms.*, u.id AS user_id, u.name AS owner_name FROM mp_shops ms JOIN users u ON ms.user_id=u.id WHERE ms.id=?');
        $shopRow->execute([$sid]);
        $shop = $shopRow->fetch();
        if ($shop) {
            if ($postAction === 'approve_shop') {
                $pdo->prepare("UPDATE mp_shops SET verification_status='approved', updated_at=NOW() WHERE id=?")->execute([$sid]);
                $pdo->prepare("UPDATE mp_shop_verifications SET status='approved', reviewed_at=NOW() WHERE shop_id=?")->execute([$sid]);
                notify_user((int)$shop['user_id'], 'Shop Verified ✅', $shop['shop_name'] . ' is now verified!', 'success');
                log_audit_action($adminUser['id'], 'mp_shop_verify', 'Verified shop #' . $sid . ': ' . $shop['shop_name']);
                log_mod_activity($adminUser['id'], 'marketplace', 'approve_shop', $sid, $shop['shop_name']);
                flash('Shop verified.', 'success');
            } elseif ($postAction === 'reject_shop') {
                $pdo->prepare("UPDATE mp_shops SET verification_status='rejected', rejection_reason=?, updated_at=NOW() WHERE id=?")->execute([$reason, $sid]);
                $pdo->prepare("UPDATE mp_shop_verifications SET status='rejected', rejection_reason=?, reviewed_at=NOW() WHERE shop_id=?")->execute([$reason, $sid]);
                notify_user((int)$shop['user_id'], 'Shop Verification Rejected', 'Reason: ' . $reason, 'error');
                log_audit_action($adminUser['id'], 'mp_shop_reject', 'Rejected shop verification #' . $sid . '. Reason: ' . $reason);
                flash('Verification rejected.', 'info');
            } elseif ($postAction === 'suspend_shop') {
                $pdo->prepare("UPDATE mp_shops SET status='suspended', updated_at=NOW() WHERE id=?")->execute([$sid]);
                notify_user((int)$shop['user_id'], 'Shop Suspended', $shop['shop_name'] . ' has been suspended. Contact support.', 'warning');
                log_audit_action($adminUser['id'], 'mp_shop_suspend', 'Suspended shop #' . $sid . ': ' . $shop['shop_name']);
                flash('Shop suspended.', 'warning');
            } elseif ($postAction === 'unsuspend_shop') {
                $pdo->prepare("UPDATE mp_shops SET status='active', updated_at=NOW() WHERE id=?")->execute([$sid]);
                notify_user((int)$shop['user_id'], 'Shop Reinstated ✅', $shop['shop_name'] . ' has been unsuspended and is active again.', 'success');
                log_audit_action($adminUser['id'], 'mp_shop_unsuspend', 'Unsuspended shop #' . $sid . ': ' . $shop['shop_name']);
                flash('Shop unsuspended.', 'success');
            }
        }
        header('Location: marketplace.php?tab=shops'); exit;
    }

    // Delete shop — hard delete (cascades to its products, orders, reviews,
    // subscriptions, etc. per FK ON DELETE CASCADE). Full admins only, since
    // this is irreversible and removes financial history along with it.
    // Blocked while the shop still has an unpaid wallet balance so a payout
    // owed to the seller can never simply vanish.
    if ($postAction === 'delete_shop' && !empty($_POST['shop_id']) && is_admin()) {
        $sid     = (int)$_POST['shop_id'];
        $shopRow = $pdo->prepare('SELECT * FROM mp_shops WHERE id=?');
        $shopRow->execute([$sid]);
        $shop = $shopRow->fetch();
        if ($shop) {
            $owed = (float)$shop['available_balance'] + (float)$shop['pending_balance'];
            if ($owed > 0) {
                flash('Cannot delete — this shop still has GH₵ ' . number_format($owed, 2) . ' in its wallet. Resolve payouts first.', 'error');
            } else {
                $pdo->prepare('DELETE FROM mp_shops WHERE id=?')->execute([$sid]);
                notify_user((int)$shop['user_id'], 'Shop Removed', 'Your shop "' . $shop['shop_name'] . '" has been removed from ' . APP_NAME . ' by an administrator.', 'warning');
                log_audit_action($adminUser['id'], 'mp_shop_delete', 'Deleted shop #' . $sid . ': ' . $shop['shop_name']);
                flash('Shop deleted.', 'success');
            }
        }
        header('Location: marketplace.php?tab=shops'); exit;
    }

    // Cancel order — reuses the exact same functions the abandoned-checkout
    // sweep and the admin refund flows (transaction_detail.php, disputes.php)
    // already use, so the money-handling logic isn't duplicated a third time:
    // unpaid orders just restore stock, paid orders go through the real
    // refund path (restores stock + reverses the seller's wallet credit).
    if ($postAction === 'cancel_order' && !empty($_POST['order_id'])) {
        $oid = (int)$_POST['order_id'];
        $reason = trim($_POST['cancel_reason'] ?? '') ?: 'Cancelled by admin';
        $orderRow = $pdo->prepare('SELECT * FROM mp_orders WHERE id=?');
        $orderRow->execute([$oid]);
        $order = $orderRow->fetch();
        if ($order && !in_array($order['status'], ['delivered', 'cancelled', 'refunded'], true)) {
            if ($order['payment_status'] === 'paid') {
                mp_refund_order($order, $reason);
            } else {
                mp_cancel_order_and_restore_stock([$oid], $reason);
            }
            notify_user((int)$order['customer_id'], 'Order Cancelled',
                'Your order #' . $oid . ' was cancelled by an administrator. Reason: ' . $reason
                . ($order['payment_status'] === 'paid' ? ' Your payment has been refunded.' : ''),
                'warning', 'orders.php');
            log_audit_action($adminUser['id'], 'mp_order_cancel', "Cancelled order #{$oid}. Reason: {$reason}");
            flash('Order #' . $oid . ' cancelled.', 'success');
        } else {
            flash('Order not found, already delivered, or already cancelled/refunded.', 'error');
        }
        header('Location: marketplace.php?tab=orders'); exit;
    }

    // Activate boost order
    if ($postAction === 'activate_boost' && !empty($_POST['boost_id'])) {
        $bid = (int)$_POST['boost_id'];
        $boostRow = $pdo->prepare('SELECT mb.*, ms.user_id, ms.shop_name FROM mp_boost_orders mb JOIN mp_shops ms ON mb.shop_id=ms.id WHERE mb.id=?');
        $boostRow->execute([$bid]);
        $boost = $boostRow->fetch();
        if ($boost && $boost['status'] === 'pending') {
            $pdo->prepare("UPDATE mp_boost_orders SET status='active', activated_by=?, activated_at=NOW() WHERE id=?")->execute([$adminUser['id'], $bid]);

            $col = match($boost['boost_type']) {
                'featured_product','sponsored_product' => 'is_featured',
                default => 'is_featured',
            };
            $isSponsored = str_contains($boost['boost_type'], 'sponsored');
            if (str_contains($boost['boost_type'], 'product') && $boost['product_id']) {
                $pdo->prepare("UPDATE mp_products SET is_featured=?, featured_end=?, is_sponsored=?, sponsored_end=? WHERE id=?")
                    ->execute([$isSponsored ? 0 : 1, $boost['end_date'], $isSponsored ? 1 : 0, $isSponsored ? $boost['end_date'] : null, $boost['product_id']]);
            } else {
                $pdo->prepare("UPDATE mp_shops SET is_featured=?, featured_end=?, is_sponsored=?, sponsored_end=? WHERE id=?")
                    ->execute([$isSponsored ? 0 : 1, $boost['end_date'], $isSponsored ? 1 : 0, $isSponsored ? $boost['end_date'] : null, $boost['shop_id']]);
            }

            notify_user((int)$boost['user_id'], 'Boost Activated! ⚡',
                'Your ' . ucwords(str_replace('_',' ',$boost['boost_type'])) . ' boost is now live until ' . date('d M Y', strtotime($boost['end_date'])) . '.', 'success');
            log_audit_action($adminUser['id'], 'mp_boost_activate', 'Activated boost #' . $bid . ' for ' . $boost['shop_name']);
            log_mod_activity($adminUser['id'], 'marketplace', 'activate_boost', $bid, $boost['shop_name']);
            flash('Boost activated.', 'success');
        }
        header('Location: marketplace.php?tab=boosts'); exit;
    }

    // Category CRUD — taxonomy shared by every shop, so full admins only.
    if ($postAction === 'save_category' && is_admin()) {
        $catId   = (int)($_POST['category_id'] ?? 0);
        $catName = trim($_POST['name'] ?? '');
        $catIcon = trim($_POST['icon'] ?? '');
        $catSort = max(0, (int)($_POST['sort_order'] ?? 0));
        $catShowCondition = isset($_POST['show_condition']) ? 1 : 0;
        if ($catName === '') {
            flash('Category name is required.', 'error');
        } else {
            $catSlug = mp_unique_slug($catName, 'mp_categories', 'slug', $pdo, $catId);
            if ($catId > 0) {
                $pdo->prepare('UPDATE mp_categories SET name=?, slug=?, icon=?, sort_order=?, show_condition=? WHERE id=?')->execute([$catName, $catSlug, $catIcon ?: null, $catSort, $catShowCondition, $catId]);
                log_audit_action($adminUser['id'], 'mp_category_update', "Updated category #{$catId}: {$catName}");
                flash('Category updated.', 'success');
            } else {
                $pdo->prepare('INSERT INTO mp_categories (name, slug, icon, sort_order, show_condition) VALUES (?,?,?,?,?)')->execute([$catName, $catSlug, $catIcon ?: null, $catSort, $catShowCondition]);
                log_audit_action($adminUser['id'], 'mp_category_create', "Created category: {$catName}");
                flash('Category added.', 'success');
            }
        }
        header('Location: marketplace.php?tab=categories'); exit;
    }
    if ($postAction === 'delete_category' && is_admin()) {
        $catId   = (int)($_POST['category_id'] ?? 0);
        $countSt = $pdo->prepare('SELECT COUNT(*) FROM mp_products WHERE category_id=?');
        $countSt->execute([$catId]);
        $inUse = (int)$countSt->fetchColumn();
        if ($inUse > 0) {
            flash("Can't delete — {$inUse} product(s) still use this category. Reassign or remove them first.", 'error');
        } else {
            $pdo->prepare('DELETE FROM mp_categories WHERE id=?')->execute([$catId]);
            log_audit_action($adminUser['id'], 'mp_category_delete', "Deleted category #{$catId}");
            flash('Category deleted.', 'success');
        }
        header('Location: marketplace.php?tab=categories'); exit;
    }

    // Save settings
    if ($postAction === 'save_settings' && is_admin()) {
        // mp_enabled itself is no longer set from here — it now lives solely
        // in Admin → Monetization → Settings → Module Availability, since a
        // form that doesn't render that checkbox would otherwise force it
        // off on every save.
        set_platform_setting('mp_require_product_approval', isset($_POST['mp_require_product_approval']) ? '1' : '0');
        set_platform_setting('mp_quotes_enabled', isset($_POST['mp_quotes_enabled']) ? '1' : '0');
        set_platform_setting('mp_quote_response_days', (string)max(1, (int)($_POST['mp_quote_response_days'] ?? 2)));
        $eligibleShops = in_array($_POST['mp_quote_eligible_shops'] ?? '', ['all','featured','verified'], true) ? $_POST['mp_quote_eligible_shops'] : 'all';
        set_platform_setting('mp_quote_eligible_shops', $eligibleShops);
        $validSorts = ['default','featured','newest','price_asc','price_desc','popular'];
        $defaultSort = in_array($_POST['mp_default_sort'] ?? '', $validSorts, true) ? $_POST['mp_default_sort'] : 'default';
        set_platform_setting('mp_default_sort', $defaultSort);
        log_audit_action($adminUser['id'], 'mp_settings_save', 'Saved marketplace settings');
        flash('Settings saved.', 'success');
        header('Location: marketplace.php?tab=settings'); exit;
    }
}

$flash = get_flash();

// ── Stats ─────────────────────────────────────────────────────────────────────
$totalShops    = (int)$pdo->query("SELECT COUNT(*) FROM mp_shops")->fetchColumn();
$activeShops   = (int)$pdo->query("SELECT COUNT(*) FROM mp_shops WHERE status='active'")->fetchColumn();
$totalProducts = (int)$pdo->query("SELECT COUNT(*) FROM mp_products")->fetchColumn();
$pendingProds  = (int)$pdo->query("SELECT COUNT(*) FROM mp_products WHERE status='pending_approval'")->fetchColumn();
$approvedProds = (int)$pdo->query("SELECT COUNT(*) FROM mp_products WHERE status='approved'")->fetchColumn();
$totalOrders   = (int)$pdo->query("SELECT COUNT(*) FROM mp_orders")->fetchColumn();
$pendingOrders = (int)$pdo->query("SELECT COUNT(*) FROM mp_orders WHERE status='pending'")->fetchColumn();
$revenue       = (float)$pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM mp_orders WHERE status='delivered'")->fetchColumn();
$pendingVerifs  = (int)$pdo->query("SELECT COUNT(*) FROM mp_shop_verifications WHERE status='pending'")->fetchColumn();
$pendingBoosts  = (int)$pdo->query("SELECT COUNT(*) FROM mp_boost_orders WHERE status='pending'")->fetchColumn();

// ── Tab data ──────────────────────────────────────────────────────────────────
$pendingProducts = $shops = $orders = [];

$mktPage    = max(1, (int)($_GET['page'] ?? 1));
$mktPerPage = 30;
$mktOffset  = ($mktPage - 1) * $mktPerPage;
$mktTotalPages = 1;
$mktTotal      = 0;

if ($tab === 'products') {
    $pf = $_GET['pf'] ?? 'pending_approval';
    $pw = in_array($pf,['pending_approval','approved','rejected','draft'],true) ? "AND mp.status='$pf'" : '';
    $mktTotal = (int)$pdo->query("SELECT COUNT(*) FROM mp_products mp JOIN mp_shops ms ON mp.shop_id=ms.id WHERE ms.status='active' $pw")->fetchColumn();
    $mktTotalPages = max(1, (int)ceil($mktTotal / $mktPerPage));
    $pendingProducts = $pdo->query(
        "SELECT mp.*, ms.shop_name, ms.id AS shop_id, ms.user_id AS owner_id,
                (SELECT image_path FROM mp_product_images WHERE product_id=mp.id AND is_primary=1 LIMIT 1) AS primary_image,
                mc.name AS cat_name
         FROM mp_products mp JOIN mp_shops ms ON mp.shop_id=ms.id LEFT JOIN mp_categories mc ON mp.category_id=mc.id
         WHERE ms.status='active' $pw ORDER BY mp.created_at ASC LIMIT $mktPerPage OFFSET $mktOffset"
    )->fetchAll();
}
if ($tab === 'shops') {
    $sf = $_GET['sf'] ?? 'all';
    $sw = '';
    if ($sf === 'pending_verification') $sw = "AND ms.verification_status='pending'";
    elseif ($sf === 'active') $sw = "AND ms.status='active'";
    elseif ($sf === 'suspended') $sw = "AND ms.status='suspended'";
    $mktTotal = (int)$pdo->query("SELECT COUNT(*) FROM mp_shops ms WHERE 1=1 $sw")->fetchColumn();
    $mktTotalPages = max(1, (int)ceil($mktTotal / $mktPerPage));
    $shops = $pdo->query(
        "SELECT ms.*, u.name AS owner_name, u.email AS owner_email, COUNT(mp.id) AS product_count
         FROM mp_shops ms JOIN users u ON ms.user_id=u.id LEFT JOIN mp_products mp ON mp.shop_id=ms.id AND mp.status='approved'
         WHERE 1=1 $sw GROUP BY ms.id ORDER BY ms.created_at DESC LIMIT $mktPerPage OFFSET $mktOffset"
    )->fetchAll();
}
if ($tab === 'orders') {
    $of = $_GET['of'] ?? 'all';
    $validOf = ['pending','confirmed','processing','ready_for_delivery','in_transit','at_storehouse','delivered','cancelled','refunded'];
    $ow = []; $owParams = [];
    if ($of !== 'all' && in_array($of, $validOf, true)) { $ow[] = 'mo.status = ?'; $owParams[] = $of; }
    $ordSort = $_GET['osort'] ?? 'newest';
    $ordOrderBy = match($ordSort) {
        'oldest'  => 'mo.created_at ASC',
        'amt_high'=> 'mo.total_amount DESC',
        'amt_low' => 'mo.total_amount ASC',
        default   => 'mo.created_at DESC',
    };
    $ordCountSt = $pdo->prepare("SELECT COUNT(*) FROM mp_orders mo WHERE 1=1 " . ($ow ? 'AND ' . implode(' AND ', $ow) : ''));
    $ordCountSt->execute($owParams);
    $mktTotal = (int)$ordCountSt->fetchColumn();
    $mktTotalPages = max(1, (int)ceil($mktTotal / $mktPerPage));
    $ordersSt = $pdo->prepare(
        "SELECT mo.*, ms.shop_name, cu.name AS customer_name
         FROM mp_orders mo JOIN mp_shops ms ON mo.shop_id=ms.id JOIN users cu ON mo.customer_id=cu.id
         WHERE 1=1 " . ($ow ? 'AND ' . implode(' AND ', $ow) : '') . "
         ORDER BY $ordOrderBy LIMIT $mktPerPage OFFSET $mktOffset"
    );
    $ordersSt->execute($owParams);
    $orders = $ordersSt->fetchAll();
}

// Boosts
$boostOrders = [];
if ($tab === 'boosts') {
    $mktTotal = (int)$pdo->query("SELECT COUNT(*) FROM mp_boost_orders WHERE status = 'pending'")->fetchColumn();
    $mktTotalPages = max(1, (int)ceil($mktTotal / $mktPerPage));
    $boostOrders = $pdo->query(
        "SELECT mb.*, ms.shop_name, u.name AS owner_name, mp.name AS product_name
         FROM mp_boost_orders mb
         JOIN mp_shops ms ON mb.shop_id = ms.id
         JOIN users u ON ms.user_id = u.id
         LEFT JOIN mp_products mp ON mb.product_id = mp.id
         WHERE mb.status = 'pending'
         ORDER BY mb.created_at ASC LIMIT $mktPerPage OFFSET $mktOffset"
    )->fetchAll();
}

// Categories
$categories = [];
if ($tab === 'categories') {
    $categories = $pdo->query(
        'SELECT mc.*, (SELECT COUNT(*) FROM mp_products WHERE category_id = mc.id) AS product_count
         FROM mp_categories mc ORDER BY mc.sort_order, mc.name'
    )->fetchAll();
}

// Quote requests — platform-wide, view-only oversight (folded in from the
// former standalone admin/quote_requests.php page)
$quoteRequests = [];
$qrItems       = [];
$qrItemCounts  = [];
$qrStatCounts  = [];
if ($tab === 'quotes') {
    $qStatusFilter  = $_GET['status'] ?? 'all';
    $qValidStatuses = ['pending','quoted','declined','cancelled','expired','paid'];
    $qSearch = trim($_GET['q'] ?? '');

    $qWhere  = ['1=1'];
    $qParams = [];
    if (in_array($qStatusFilter, $qValidStatuses, true)) { $qWhere[] = 'mqr.status = ?'; $qParams[] = $qStatusFilter; }
    if ($qSearch !== '') { $qWhere[] = '(ms.shop_name LIKE ? OR u.name LIKE ?)'; $like = '%' . $qSearch . '%'; $qParams[] = $like; $qParams[] = $like; }
    $qWhereClause = implode(' AND ', $qWhere);

    $qCountSt = $pdo->prepare("SELECT COUNT(*) FROM mp_quote_requests mqr JOIN mp_shops ms ON mqr.shop_id=ms.id JOIN users u ON mqr.customer_id=u.id WHERE $qWhereClause");
    $qCountSt->execute($qParams);
    $mktTotal = (int)$qCountSt->fetchColumn();
    $mktTotalPages = max(1, (int)ceil($mktTotal / $mktPerPage));

    $qListSt = $pdo->prepare(
        "SELECT mqr.*, ms.shop_name, ms.phone AS shop_phone, ms.email AS shop_email,
                u.name AS customer_name, u.phone AS customer_phone, u.email AS customer_email
         FROM mp_quote_requests mqr
         JOIN mp_shops ms ON mqr.shop_id = ms.id
         JOIN users u ON mqr.customer_id = u.id
         WHERE $qWhereClause
         ORDER BY mqr.created_at DESC
         LIMIT $mktPerPage OFFSET $mktOffset"
    );
    $qListSt->execute($qParams);
    $quoteRequests = $qListSt->fetchAll();

    if ($quoteRequests) {
        $ids = implode(',', array_column($quoteRequests, 'id'));
        $itemsStmt = $pdo->query("SELECT * FROM mp_quote_request_items WHERE quote_request_id IN ($ids) ORDER BY sort_order ASC");
        foreach ($itemsStmt->fetchAll() as $row) {
            $qrItems[$row['quote_request_id']][] = $row;
            $qrItemCounts[$row['quote_request_id']] = ($qrItemCounts[$row['quote_request_id']] ?? 0) + 1;
        }
    }
    // Stat chips — always unfiltered, independent of the current status/search filter
    $qrStatCounts = $pdo->query("SELECT status, COUNT(*) AS n FROM mp_quote_requests GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
}

function mkt_qstr(array $overrides = []): string {
    $base = [];
    foreach (['tab', 'pf', 'sf', 'of', 'osort', 'status', 'q', 'page'] as $k) {
        if (isset($_GET[$k]) && $_GET[$k] !== '') $base[$k] = $_GET[$k];
    }
    $merged = array_filter(array_merge($base, $overrides), fn($v) => $v !== null);
    return 'marketplace.php?' . http_build_query($merged);
}

function mkt_render_pagination(int $page, int $totalPages, int $total): void {
    if ($totalPages <= 1) return;
    echo '<div class="pagination">';
    if ($page > 1) echo '<a href="' . sanitize(mkt_qstr(['page' => $page - 1])) . '">‹ Prev</a>';
    $pStart = max(1, $page - 3);
    $pEnd   = min($totalPages, $page + 3);
    if ($pStart > 1) echo '<span>…</span>';
    for ($p = $pStart; $p <= $pEnd; $p++) {
        echo $p === $page
            ? '<span class="current">' . $p . '</span>'
            : '<a href="' . sanitize(mkt_qstr(['page' => $p])) . '">' . $p . '</a>';
    }
    if ($pEnd < $totalPages) echo '<span>…</span>';
    if ($page < $totalPages) echo '<a href="' . sanitize(mkt_qstr(['page' => $page + 1])) . '">Next ›</a>';
    echo '<span style="color:var(--text-muted,#6b7280);border:none;padding-left:4px;">Page ' . $page . ' of ' . $totalPages . ' (' . $total . ' total)</span>';
    echo '</div>';
}

// Settings
$cfg = [];
if ($tab === 'settings') {
    foreach (['mp_enabled','mp_require_product_approval',
              'mp_quotes_enabled','mp_quote_response_days','mp_quote_eligible_shops','mp_default_sort'] as $k) {
        $cfg[$k] = get_platform_setting($k, '');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Marketplace Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .adm-shell { max-width:1060px; margin:0 auto; padding:18px 16px 60px; }
        .adm-stats { display:grid; grid-template-columns:repeat(auto-fill,minmax(110px,1fr)); gap:10px; margin-bottom:20px; }
        .adm-stat  { background:var(--surface); border:1px solid var(--border); border-radius:12px; padding:12px; text-align:center; }
        .adm-stat strong { display:block; font-size:1.4rem; font-weight:900; color:var(--primary,#0f766e); }
        .adm-stat span   { font-size:.7rem; color:var(--text-muted,#6b7280); }
        .adm-tabs { display:flex; gap:4px; flex-wrap:wrap; border-bottom:1px solid var(--border); padding-bottom:12px; margin-bottom:16px; }
        .adm-tab  { padding:6px 14px; border-radius:8px; font-size:.8rem; font-weight:700; text-decoration:none; background:var(--surface); border:1px solid var(--border); color:var(--text-muted,#6b7280); }
        .adm-tab.active { background:var(--primary-soft,#d1fae5); border-color:var(--primary,#0f766e); color:var(--primary,#0f766e); }
        .adm-filter { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:12px; align-items:center; }
        .adm-filter a { padding:4px 11px; border-radius:20px; font-size:.73rem; font-weight:700; text-decoration:none; background:var(--surface); border:1px solid var(--border); color:var(--text-muted,#6b7280); }
        .adm-filter a.active { background:var(--primary-soft); border-color:var(--primary); color:var(--primary); }
        .adm-row { background:var(--surface); border:1px solid var(--border); border-radius:12px; padding:12px 16px; margin-bottom:8px; }
        .adm-badge { display:inline-block; padding:3px 9px; border-radius:20px; font-size:.7rem; font-weight:800; }
        .adm-pimg  { width:56px; height:56px; border-radius:8px; background:#f8fafc; overflow:hidden; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
        .adm-pimg img { width:100%; height:100%; object-fit:cover; }
        label { font-weight:600; font-size:.86rem; display:block; margin-bottom:4px; }
        .form-group { margin-bottom:12px; }
        .adm-set-section { background:var(--surface); border:1px solid var(--border); border-radius:14px; padding:18px; margin-bottom:14px; }
        .adm-set-title { font-size:.74rem; font-weight:800; text-transform:uppercase; letter-spacing:.07em; color:var(--text-muted,#6b7280); margin:0 0 14px; }
        .adm-grid2 { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
        @media(max-width:520px){ .adm-stats { grid-template-columns:repeat(3,1fr); } .adm-grid2 { grid-template-columns:1fr; } }
        .pagination { display:flex; gap:4px; flex-wrap:wrap; align-items:center; margin-top:14px; }
        .pagination a, .pagination span { padding:5px 10px; border-radius:6px; border:1px solid var(--border); text-decoration:none; font-size:.82rem; color:var(--text); }
        .pagination a:hover { background:var(--surface-muted,#f9fafb); }
        .pagination .current { background:var(--primary,#0f766e); color:#fff; border-color:var(--primary,#0f766e); }
    </style>
</head>
<body>

<header class="topbar">
    <a href="index.php" class="button button-secondary button-small">← Dashboard</a>
    <h1 style="margin:0;font-size:1rem;font-weight:800;">🛍️ Marketplace Management</h1>
</header>

<main class="adm-shell">

    <?php if ($flash): ?>
    <div class="alert alert-<?php echo sanitize($flash['type']); ?>"><?php echo sanitize($flash['message']); ?></div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="adm-stats">
        <div class="adm-stat"><strong><?php echo $totalShops; ?></strong><span>Shops</span></div>
        <div class="adm-stat"><strong><?php echo $activeShops; ?></strong><span>Active</span></div>
        <div class="adm-stat"><strong style="color:#f59e0b;"><?php echo $pendingVerifs; ?></strong><span>Verifications</span></div>
        <div class="adm-stat"><strong><?php echo $totalProducts; ?></strong><span>Products</span></div>
        <div class="adm-stat"><strong style="color:#f59e0b;"><?php echo $pendingProds; ?></strong><span>Pending</span></div>
        <div class="adm-stat"><strong style="color:#10b981;"><?php echo $approvedProds; ?></strong><span>Approved</span></div>
        <div class="adm-stat"><strong><?php echo $totalOrders; ?></strong><span>Orders</span></div>
        <div class="adm-stat"><strong style="color:#f59e0b;"><?php echo $pendingOrders; ?></strong><span>New Orders</span></div>
        <div class="adm-stat"><strong>GHS <?php echo number_format($revenue,2); ?></strong><span>Revenue</span></div>
    </div>

    <!-- Tabs -->
    <div class="adm-tabs">
        <?php if ($hasProductsPerm): ?>
        <a href="?tab=products" class="adm-tab <?php echo $tab==='products'?'active':''; ?>">
            Products <?php if ($pendingProds): ?><span style="background:#f59e0b;color:#fff;border-radius:10px;padding:0 6px;font-size:.65rem;margin-left:3px;"><?php echo $pendingProds; ?></span><?php endif; ?>
        </a>
        <a href="?tab=shops" class="adm-tab <?php echo $tab==='shops'?'active':''; ?>">
            Shops <?php if ($pendingVerifs): ?><span style="background:#10b981;color:#fff;border-radius:10px;padding:0 6px;font-size:.65rem;margin-left:3px;"><?php echo $pendingVerifs; ?></span><?php endif; ?>
        </a>
        <a href="?tab=orders" class="adm-tab <?php echo $tab==='orders'?'active':''; ?>">Orders</a>
        <a href="?tab=boosts" class="adm-tab <?php echo $tab==='boosts'?'active':''; ?>">
            &#9889; Boosts <?php if ($pendingBoosts): ?><span style="background:#f59e0b;color:#fff;border-radius:10px;padding:0 6px;font-size:.65rem;margin-left:3px;"><?php echo $pendingBoosts; ?></span><?php endif; ?>
        </a>
        <?php endif; ?>
        <?php if ($hasQuotesPerm): ?>
        <a href="?tab=quotes" class="adm-tab <?php echo $tab==='quotes'?'active':''; ?>">📝 Quote Requests</a>
        <?php endif; ?>
        <?php if (is_admin()): ?><a href="?tab=categories" class="adm-tab <?php echo $tab==='categories'?'active':''; ?>">🏷️ Categories</a><?php endif; ?>
        <?php if (is_admin()): ?><a href="?tab=settings" class="adm-tab <?php echo $tab==='settings'?'active':''; ?>">&#9881; Settings</a><?php endif; ?>
    </div>

    <!-- ═══ PRODUCTS ═══ -->
    <?php if ($tab === 'products'): ?>
    <?php $pf = $_GET['pf'] ?? 'pending_approval'; ?>
    <div class="adm-filter">
        <?php foreach (['pending_approval'=>'Pending','approved'=>'Approved','rejected'=>'Rejected','all'=>'All'] as $v=>$l): ?>
        <a href="?tab=products&pf=<?php echo $v; ?>" class="<?php echo $pf===$v?'active':''; ?>"><?php echo $l; ?></a>
        <?php endforeach; ?>
    </div>
    <?php if ($pendingProducts): foreach ($pendingProducts as $p): ?>
    <div class="adm-row">
        <div style="display:flex;gap:12px;align-items:flex-start;margin-bottom:10px;">
            <div class="adm-pimg">
                <?php if ($p['primary_image']): ?><img src="<?php echo sanitize('../'.$p['primary_image']); ?>" alt=""><?php else: ?><span style="font-size:1.5rem;opacity:.3;">📦</span><?php endif; ?>
            </div>
            <div style="flex:1;">
                <div style="font-weight:800;font-size:.9rem;"><?php echo sanitize($p['name']); ?></div>
                <div style="font-size:.76rem;color:var(--text-muted,#6b7280);">
                    🏪 <a href="../shop.php?id=<?php echo $p['shop_id']; ?>" target="_blank" style="color:var(--primary,#0f766e);"><?php echo sanitize($p['shop_name']); ?></a>
                    &nbsp;·&nbsp; <?php echo sanitize($p['cat_name']??'Uncategorised'); ?>
                    &nbsp;·&nbsp; GHS <?php echo number_format((float)$p['price'],2); ?>
                    &nbsp;·&nbsp; Stock: <?php echo $p['stock_quantity']; ?>
                    &nbsp;·&nbsp; <?php echo ucfirst($p['condition_type']); ?>
                </div>
            </div>
            <div style="text-align:right;flex-shrink:0;">
                <span class="adm-badge" style="background:<?php echo mp_product_status_bg($p['status']); ?>;color:<?php echo mp_product_status_color($p['status']); ?>;"><?php echo mp_product_status_label($p['status']); ?></span>
                <div style="margin-top:4px;"><a href="../product.php?id=<?php echo $p['id']; ?>" target="_blank" style="font-size:.74rem;color:var(--primary,#0f766e);">Preview &#8599;</a></div>
            </div>
        </div>
        <?php if ($p['description']): ?><div style="font-size:.8rem;color:var(--text-muted,#6b7280);margin-bottom:10px;"><?php echo sanitize(mb_substr(strip_tags($p['description']),0,120)); ?>…</div><?php endif; ?>
        <?php if ($p['rejection_reason']): ?><div style="font-size:.78rem;background:#fee2e2;border-radius:6px;padding:5px 9px;margin-bottom:10px;">Rejection: <?php echo sanitize($p['rejection_reason']); ?></div><?php endif; ?>
        <?php $mpCoi = !is_admin() && (int)($p['owner_id'] ?? 0) === (int)$adminUser['id']; ?>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <?php if ($mpCoi && in_array($p['status'],['pending_approval','draft'],true)): ?>
            <span style="background:#fef3c7;border:1px solid #f59e0b;color:#92400e;font-size:.72rem;font-weight:700;padding:4px 10px;border-radius:8px;">&#9888; Your product — cannot moderate</span>
            <?php else: ?>
            <?php if ($p['status'] !== 'approved'): ?>
            <form method="post" style="margin:0;"><?php echo csrf_field(); ?><input type="hidden" name="action" value="approve_product"><input type="hidden" name="product_id" value="<?php echo $p['id']; ?>"><button type="submit" class="button button-primary button-small">&#10003; Approve</button></form>
            <?php endif; ?>
            <?php if ($p['status'] !== 'rejected'): ?>
            <form method="post" style="margin:0;display:flex;gap:5px;align-items:center;"><?php echo csrf_field(); ?><input type="hidden" name="action" value="reject_product"><input type="hidden" name="product_id" value="<?php echo $p['id']; ?>"><input type="text" name="rejection_reason" placeholder="Rejection reason *" style="font-size:.76rem;padding:4px 9px;width:200px;" required><button type="submit" class="button button-small" style="background:#ef4444;color:#fff;border-color:transparent;">&#10007; Reject</button></form>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; else: ?><div class="empty-state">No products found.</div><?php endif; ?>
    <?php mkt_render_pagination($mktPage, $mktTotalPages, $mktTotal); ?>
    <?php endif; ?>

    <!-- ═══ SHOPS ═══ -->
    <?php if ($tab === 'shops'): ?>
    <?php $sf = $_GET['sf'] ?? 'all'; ?>
    <div class="adm-filter">
        <?php foreach (['all'=>'All','pending_verification'=>'Pending Verification','active'=>'Active','suspended'=>'Suspended'] as $v=>$l): ?>
        <a href="?tab=shops&sf=<?php echo $v; ?>" class="<?php echo $sf===$v?'active':''; ?>"><?php echo $l; ?></a>
        <?php endforeach; ?>
    </div>
    <?php if ($shops): foreach ($shops as $s): ?>
    <div class="adm-row">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:8px;flex-wrap:wrap;gap:6px;">
            <div>
                <div style="font-weight:800;"><?php echo sanitize($s['shop_name']); ?></div>
                <div style="font-size:.75rem;color:var(--text-muted,#6b7280);">
                    Owner: <?php echo sanitize($s['owner_name']); ?> (<?php echo sanitize($s['owner_email']); ?>)
                    &nbsp;·&nbsp; <?php echo $s['product_count']; ?> active products
                    &nbsp;·&nbsp; <?php echo number_format($s['total_sales']); ?> sales
                    &nbsp;·&nbsp; <?php echo sanitize($s['region']??''); ?>
                    &nbsp;·&nbsp; <?php echo time_ago($s['created_at']); ?>
                </div>
            </div>
            <div style="display:flex;gap:5px;flex-wrap:wrap;">
                <?php $vs=$s['verification_status']; $vsBg=['none'=>'#f3f4f6','pending'=>'#fef3c7','approved'=>'#d1fae5','rejected'=>'#fee2e2']; $vsCol=['none'=>'#6b7280','pending'=>'#b45309','approved'=>'#065f46','rejected'=>'#c0392b']; ?>
                <span class="adm-badge" style="background:<?php echo $vsBg[$vs]??'#f3f4f6'; ?>;color:<?php echo $vsCol[$vs]??'#6b7280'; ?>;">Verify: <?php echo ucfirst($vs); ?></span>
                <?php if ($s['status']==='suspended'): ?><span class="adm-badge" style="background:#fee2e2;color:#c0392b;">SUSPENDED</span><?php endif; ?>
            </div>
        </div>

        <?php
        // Verification docs
        $vdRow = $pdo->prepare('SELECT * FROM mp_shop_verifications WHERE shop_id=?');
        $vdRow->execute([$s['id']]);
        $vdocs = $vdRow->fetch();
        if ($vdocs):
        ?>
        <div style="display:flex;gap:8px;margin-bottom:8px;">
            <?php if ($vdocs['ghana_card_path']): ?><a href="<?php echo sanitize('../'.$vdocs['ghana_card_path']); ?>" target="_blank" class="button button-secondary button-small">&#128290; Ghana Card</a><?php endif; ?>
            <?php if ($vdocs['business_reg_path']): ?><a href="<?php echo sanitize('../'.$vdocs['business_reg_path']); ?>" target="_blank" class="button button-secondary button-small">&#128196; Business Reg</a><?php endif; ?>
        </div>
        <?php endif; ?>

        <div style="display:flex;gap:6px;flex-wrap:wrap;">
            <a href="../shop.php?id=<?php echo $s['id']; ?>" target="_blank" class="button button-secondary button-small">View &#8599;</a>
            <?php if ($vs !== 'approved'): ?>
            <form method="post" style="margin:0;"><?php echo csrf_field(); ?><input type="hidden" name="action" value="approve_shop"><input type="hidden" name="shop_id" value="<?php echo $s['id']; ?>"><button type="submit" class="button button-primary button-small">&#10003; Verify Shop</button></form>
            <?php endif; ?>
            <?php if ($vs === 'pending' || $vs === 'none'): ?>
            <form method="post" style="margin:0;display:flex;gap:5px;align-items:center;"><?php echo csrf_field(); ?><input type="hidden" name="action" value="reject_shop"><input type="hidden" name="shop_id" value="<?php echo $s['id']; ?>"><input type="text" name="rejection_reason" placeholder="Reason" style="font-size:.76rem;padding:4px 9px;width:160px;"><button type="submit" class="button button-small" style="background:#ef4444;color:#fff;border-color:transparent;">&#10007; Reject</button></form>
            <?php endif; ?>
            <?php if ($s['status'] === 'active'): ?>
            <form method="post" style="margin:0;" onsubmit="return confirm('Suspend this shop?');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="suspend_shop"><input type="hidden" name="shop_id" value="<?php echo $s['id']; ?>"><button type="submit" class="button button-small" style="background:#f59e0b;color:#fff;border-color:transparent;">&#9888; Suspend</button></form>
            <?php elseif ($s['status'] === 'suspended'): ?>
            <form method="post" style="margin:0;" onsubmit="return confirm('Unsuspend this shop?');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="unsuspend_shop"><input type="hidden" name="shop_id" value="<?php echo $s['id']; ?>"><button type="submit" class="button button-small" style="background:#22a06b;color:#fff;border-color:transparent;">&#10003; Unsuspend</button></form>
            <?php endif; ?>
            <?php if (is_admin()): ?>
            <form method="post" style="margin:0;" onsubmit="return confirm('Permanently delete &quot;<?php echo sanitize(addslashes($s['shop_name'])); ?>&quot;? This removes the shop, all its products, orders, and reviews. This cannot be undone.');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="delete_shop"><input type="hidden" name="shop_id" value="<?php echo $s['id']; ?>"><button type="submit" class="button button-small" style="background:#ef4444;color:#fff;border-color:transparent;">&#128465; Delete</button></form>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; else: ?><div class="empty-state">No shops found.</div><?php endif; ?>
    <?php mkt_render_pagination($mktPage, $mktTotalPages, $mktTotal); ?>
    <?php endif; ?>

    <!-- ═══ ORDERS ═══ -->
    <?php if ($tab === 'orders'): ?>
    <?php $of = $_GET['of'] ?? 'all'; ?>
    <div class="adm-filter">
        <?php foreach (['all'=>'All','pending'=>'Pending','confirmed'=>'Confirmed','processing'=>'Processing','ready_for_delivery'=>'Ready','in_transit'=>'In Transit','at_storehouse'=>'At Storehouse','delivered'=>'Delivered','cancelled'=>'Cancelled','refunded'=>'Refunded'] as $v=>$l): ?>
        <a href="?tab=orders&of=<?php echo $v; ?>" class="<?php echo $of===$v?'active':''; ?>"><?php echo $l; ?></a>
        <?php endforeach; ?>
    </div>
    <form method="get" action="marketplace.php" style="margin-bottom:12px;">
        <input type="hidden" name="tab" value="orders"><input type="hidden" name="of" value="<?php echo sanitize($of); ?>">
        <select name="osort" onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()" style="padding:7px 10px;border:1px solid var(--border);border-radius:8px;font-size:.82rem;">
            <option value="newest" <?php echo $ordSort==='newest'?'selected':''; ?>>Newest First</option>
            <option value="oldest" <?php echo $ordSort==='oldest'?'selected':''; ?>>Oldest First</option>
            <option value="amt_high" <?php echo $ordSort==='amt_high'?'selected':''; ?>>Highest Amount</option>
            <option value="amt_low" <?php echo $ordSort==='amt_low'?'selected':''; ?>>Lowest Amount</option>
        </select>
    </form>
    <?php if ($orders): foreach ($orders as $o): ?>
    <div class="adm-row" style="border-left:4px solid <?php echo mp_order_status_color($o['status']); ?>;">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:6px;margin-bottom:4px;">
            <div>
                <div style="font-weight:800;">Order #<?php echo $o['id']; ?></div>
                <div style="font-size:.75rem;color:var(--text-muted,#6b7280);">
                    &#128722; <?php echo sanitize($o['customer_name']); ?> &#8594; &#127978; <?php echo sanitize($o['shop_name']); ?> &nbsp;·&nbsp; <?php echo time_ago($o['created_at']); ?>
                </div>
            </div>
            <div>
                <span class="adm-badge" style="background:<?php echo mp_order_status_bg($o['status']); ?>;color:<?php echo mp_order_status_color($o['status']); ?>;"><?php echo mp_order_status_label($o['status']); ?></span>
                <div style="font-weight:800;color:var(--primary,#0f766e);margin-top:3px;text-align:right;">GHS <?php echo number_format((float)$o['total_amount'],2); ?></div>
            </div>
        </div>
        <div style="font-size:.78rem;color:var(--text-muted,#6b7280);">&#128205; <?php echo sanitize(mb_substr($o['delivery_address']??'',0,60)); ?></div>
        <?php if (!in_array($o['status'], ['delivered','cancelled','refunded'], true)): ?>
        <form method="post" action="marketplace.php?tab=orders" class="inline-form" style="margin-top:8px;display:flex;gap:6px;align-items:center;" onsubmit="return confirm('Cancel order #<?php echo $o['id']; ?>?<?php echo $o['payment_status']==='paid' ? ' This will refund the customer and reverse the seller\'s wallet credit.' : ''; ?>');">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="cancel_order">
            <input type="hidden" name="order_id" value="<?php echo $o['id']; ?>">
            <input type="text" name="cancel_reason" placeholder="Reason (optional)" style="font-size:.76rem;padding:4px 8px;flex:1;max-width:220px;">
            <button type="submit" class="button button-small" style="background:#fee2e2;color:#991b1b;border-color:#fca5a5;">Cancel Order<?php echo $o['payment_status']==='paid' ? ' & Refund' : ''; ?></button>
        </form>
        <?php endif; ?>
    </div>
    <?php endforeach; else: ?><div class="empty-state">No orders found.</div><?php endif; ?>
    <?php mkt_render_pagination($mktPage, $mktTotalPages, $mktTotal); ?>
    <?php endif; ?>

    <!-- ═══ BOOSTS ═══ -->
    <?php if ($tab === 'boosts'): ?>
    <?php if ($boostOrders): foreach ($boostOrders as $b): ?>
    <div class="adm-row">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:8px;">
            <div>
                <div style="font-weight:800;">
                    <?php echo sanitize(ucwords(str_replace('_',' ',$b['boost_type']))); ?>
                    <?php if ($b['product_name']): ?> — <?php echo sanitize($b['product_name']); ?><?php endif; ?>
                </div>
                <div style="font-size:.75rem;color:var(--text-muted,#6b7280);">
                    &#127978; <?php echo sanitize($b['shop_name']); ?> (<?php echo sanitize($b['owner_name']); ?>)
                    &nbsp;·&nbsp; <?php echo $b['package_days']; ?> days
                    &nbsp;·&nbsp; <?php echo time_ago($b['created_at']); ?>
                </div>
            </div>
            <div style="text-align:right;">
                <div style="font-weight:900;color:var(--primary,#0f766e);">GHS <?php echo number_format((float)$b['price_paid'],2); ?></div>
                <div style="font-size:.74rem;color:var(--text-muted,#6b7280);"><?php echo sanitize($b['payment_method']); ?><?php if ($b['mobi_number']): ?> (<?php echo sanitize($b['mobi_number']); ?>)<?php endif; ?></div>
            </div>
        </div>
        <div style="font-size:.78rem;color:var(--text-muted,#6b7280);margin-bottom:8px;">
            Would run <?php echo date('d M Y',strtotime($b['start_date'])); ?> → <?php echo date('d M Y',strtotime($b['end_date'])); ?>
        </div>
        <form method="post" style="margin:0;">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action"   value="activate_boost">
            <input type="hidden" name="boost_id" value="<?php echo $b['id']; ?>">
            <button type="submit" class="button button-primary button-small">&#9889; Activate Boost</button>
        </form>
    </div>
    <?php endforeach; else: ?>
    <div class="empty-state">No pending boost orders.</div>
    <?php endif; ?>
    <?php mkt_render_pagination($mktPage, $mktTotalPages, $mktTotal); ?>
    <?php endif; ?>

    <!-- ═══ QUOTE REQUESTS ═══ -->
    <?php if ($tab === 'quotes'): ?>
    <div class="adm-stats" style="grid-template-columns:repeat(auto-fill,minmax(90px,1fr));">
        <a href="<?php echo mkt_qstr(['status'=>null,'page'=>null]); ?>" class="adm-stat" style="text-decoration:none;color:inherit;"><strong><?php echo array_sum($qrStatCounts); ?></strong><span>All</span></a>
        <?php foreach (['pending'=>'Pending','quoted'=>'Quoted','paid'=>'Paid','declined'=>'Declined','cancelled'=>'Cancelled','expired'=>'Expired'] as $sv=>$sl): ?>
        <a href="<?php echo mkt_qstr(['status'=>$sv,'page'=>null]); ?>" class="adm-stat" style="text-decoration:none;color:inherit;"><strong><?php echo (int)($qrStatCounts[$sv] ?? 0); ?></strong><span><?php echo $sl; ?></span></a>
        <?php endforeach; ?>
    </div>

    <form method="get" class="adm-filter" style="flex-wrap:wrap;">
        <input type="hidden" name="tab" value="quotes">
        <select name="status" onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()" style="padding:5px 9px;border:1px solid var(--border);border-radius:8px;font-size:.78rem;background:var(--surface);">
            <option value="all" <?php echo $qStatusFilter==='all'?'selected':''; ?>>All Statuses</option>
            <?php foreach ($qValidStatuses as $vs): ?>
            <option value="<?php echo $vs; ?>" <?php echo $qStatusFilter===$vs?'selected':''; ?>><?php echo ucfirst($vs); ?></option>
            <?php endforeach; ?>
        </select>
        <input type="text" name="q" value="<?php echo sanitize($qSearch); ?>" placeholder="Search shop or customer name…" style="flex:1;min-width:180px;padding:5px 9px;border:1px solid var(--border);border-radius:8px;font-size:.78rem;">
        <button type="submit" class="button button-secondary button-small">Filter</button>
        <?php if ($qStatusFilter !== 'all' || $qSearch !== ''): ?><a href="?tab=quotes" class="button button-secondary button-small">Clear</a><?php endif; ?>
    </form>

    <?php if (!$quoteRequests): ?>
    <div class="empty-state">No quote requests match this filter.</div>
    <?php else: ?>
    <?php
    $qrColors = ['pending'=>'#f59e0b','quoted'=>'#3b82f6','declined'=>'#ef4444','cancelled'=>'#6b7280','expired'=>'#6b7280','paid'=>'#10b981'];
    foreach ($quoteRequests as $r): $qc = $qrColors[$r['status']] ?? '#6b7280';
    ?>
    <div class="adm-row" style="border-left:4px solid <?php echo $qc; ?>;">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:8px;margin-bottom:8px;">
            <div>
                <strong>Request #<?php echo $r['id']; ?></strong>
                <span style="font-size:.76rem;color:var(--text-muted,#6b7280);">&nbsp;·&nbsp; <?php echo $qrItemCounts[$r['id']] ?? 0; ?> item(s) <?php if ($r['total_amount'] !== null): ?>&nbsp;·&nbsp; GH&#8373; <?php echo number_format((float)$r['total_amount'],2); ?><?php endif; ?></span>
            </div>
            <span class="adm-badge" style="background:<?php echo $qc; ?>1a;color:<?php echo $qc; ?>;"><?php echo ucfirst($r['status']); ?></span>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:8px;font-size:.82rem;">
            <div>
                &#127978; <strong><?php echo sanitize($r['shop_name']); ?></strong>
                <?php if ($r['shop_phone']): ?><a href="tel:<?php echo sanitize($r['shop_phone']); ?>" style="color:var(--primary,#0f766e);text-decoration:none;font-weight:600;margin-left:6px;">📞 <?php echo sanitize($r['shop_phone']); ?></a><?php endif; ?>
                <?php if ($r['shop_email']): ?><a href="mailto:<?php echo sanitize($r['shop_email']); ?>" style="color:var(--primary,#0f766e);text-decoration:none;font-weight:600;margin-left:6px;">✉</a><?php endif; ?>
            </div>
            <div>
                🧑 <strong><?php echo sanitize($r['customer_name']); ?></strong>
                <?php if ($r['customer_phone']): ?><a href="tel:<?php echo sanitize($r['customer_phone']); ?>" style="color:var(--primary,#0f766e);text-decoration:none;font-weight:600;margin-left:6px;">📞 <?php echo sanitize($r['customer_phone']); ?></a><?php endif; ?>
                <?php if ($r['customer_email']): ?><a href="mailto:<?php echo sanitize($r['customer_email']); ?>" style="color:var(--primary,#0f766e);text-decoration:none;font-weight:600;margin-left:6px;">✉</a><?php endif; ?>
            </div>
        </div>
        <?php if ($r['buyer_notes']): ?><div style="font-size:.76rem;color:var(--text-muted,#6b7280);margin-bottom:4px;">📝 <?php echo sanitize(mb_substr($r['buyer_notes'],0,140)); ?></div><?php endif; ?>
        <?php if ($r['decline_reason']): ?><div style="font-size:.76rem;color:#c0392b;margin-bottom:4px;">Declined: <?php echo sanitize(mb_substr($r['decline_reason'],0,140)); ?></div><?php endif; ?>
        <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;">
            <div style="font-size:.76rem;color:var(--text-muted,#6b7280);">Sent <?php echo time_ago($r['created_at']); ?><?php if ($r['quoted_at']): ?> &nbsp;·&nbsp; Quoted <?php echo time_ago($r['quoted_at']); ?><?php endif; ?></div>
            <?php if (!empty($qrItems[$r['id']])): ?>
            <button type="button" class="button button-secondary button-small" onclick="mktToggleQrItems(<?php echo $r['id']; ?>, this)">▸ View Items</button>
            <?php endif; ?>
        </div>
        <?php if (!empty($qrItems[$r['id']])): ?>
        <div id="qr-items-<?php echo $r['id']; ?>" style="display:none;margin-top:10px;padding-top:10px;border-top:1px solid var(--border);">
            <?php foreach ($qrItems[$r['id']] as $qi): ?>
            <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;padding:4px 0;font-size:.82rem;<?php echo !$qi['is_available'] ? 'opacity:.5;' : ''; ?>">
                <div>
                    <?php echo !$qi['is_available'] ? '<s>' : ''; ?><?php echo sanitize($qi['item_name']); ?><?php if ($qi['quantity_note']): ?> <span style="color:var(--text-muted,#6b7280);font-weight:400;">(<?php echo sanitize($qi['quantity_note']); ?>)</span><?php endif; ?><?php echo !$qi['is_available'] ? '</s>' : ''; ?>
                    <?php if ($qi['buyer_note']): ?><div style="font-size:.74rem;color:var(--text-muted,#6b7280);">Note: <?php echo sanitize($qi['buyer_note']); ?></div><?php endif; ?>
                </div>
                <div style="white-space:nowrap;font-weight:700;">
                    <?php if (!$qi['is_available']): ?><span style="color:#c0392b;font-weight:700;">Unavailable</span>
                    <?php elseif ($qi['price'] !== null): ?>GH&#8373; <?php echo number_format((float)$qi['price'],2); ?>
                    <?php else: ?><span style="color:var(--text-muted,#6b7280);font-weight:400;">Not priced yet</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
    <?php mkt_render_pagination($mktPage, $mktTotalPages, $mktTotal); ?>
    <?php endif; ?>

    <!-- ═══ CATEGORIES ═══ -->
    <?php if ($tab === 'categories' && is_admin()): ?>
    <?php if ($categories): foreach ($categories as $c): $cJson = htmlspecialchars(json_encode($c), ENT_QUOTES, 'UTF-8'); ?>
    <div class="adm-row" style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
        <div style="display:flex;align-items:center;gap:10px;">
            <span style="font-size:1.4rem;"><?php echo $c['icon'] ?: '🏷️'; ?></span>
            <div>
                <div style="font-weight:800;"><?php echo sanitize($c['name']); ?></div>
                <div style="font-size:.75rem;color:var(--text-muted,#6b7280);">
                    <?php echo $c['product_count']; ?> product<?php echo $c['product_count']==1?'':'s'; ?> &nbsp;·&nbsp; sort <?php echo (int)$c['sort_order']; ?>
                    &nbsp;·&nbsp; Condition strip: <?php echo $c['show_condition'] ? '<span style="color:#065f46;">On</span>' : '<span style="color:#991b1b;">Off</span>'; ?>
                </div>
            </div>
        </div>
        <div style="display:flex;gap:6px;">
            <button type="button" class="button button-secondary button-small" onclick='mktEditCategory(<?php echo $cJson; ?>)'>Edit</button>
            <form method="post" class="inline-form" onsubmit="return confirm('Delete this category?');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="delete_category"><input type="hidden" name="category_id" value="<?php echo $c['id']; ?>"><button type="submit" class="button button-small" style="background:#fee2e2;color:#991b1b;border-color:#fca5a5;">Delete</button></form>
        </div>
    </div>
    <?php endforeach; else: ?><div class="empty-state">No categories yet.</div><?php endif; ?>

    <div class="adm-set-section" style="margin-top:16px;">
        <h3 id="cat-form-title" style="margin:0 0 14px;">Add Category</h3>
        <form method="post" action="marketplace.php?tab=categories" class="adm-grid2">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="save_category">
            <input type="hidden" name="category_id" id="cat_id" value="0">
            <div class="form-group"><label>Name</label><input type="text" name="name" id="cat_name" required></div>
            <div class="form-group"><label>Icon (emoji)</label><input type="text" name="icon" id="cat_icon" maxlength="10"></div>
            <div class="form-group"><label>Sort Order</label><input type="number" name="sort_order" id="cat_sort_order" min="0" value="0"></div>
            <div class="form-group">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="checkbox" name="show_condition" id="cat_show_condition" value="1" checked>
                    Show Condition strip (New/Used/Refurbished)
                </label>
            </div>
            <div class="form-group" style="align-self:end;"><button type="submit" class="button button-primary">Save</button> <button type="button" class="button button-secondary" onclick="mktResetCategoryForm()">Clear</button></div>
        </form>
    </div>
    <script>
    function mktEditCategory(c) {
        document.getElementById('cat_id').value = c.id;
        document.getElementById('cat_name').value = c.name;
        document.getElementById('cat_icon').value = c.icon || '';
        document.getElementById('cat_sort_order').value = c.sort_order;
        document.getElementById('cat_show_condition').checked = !!parseInt(c.show_condition, 10);
        document.getElementById('cat-form-title').textContent = 'Edit Category — ' + c.name;
    }
    function mktResetCategoryForm() {
        document.getElementById('cat_id').value = 0;
        document.getElementById('cat_name').value = '';
        document.getElementById('cat_icon').value = '';
        document.getElementById('cat_sort_order').value = 0;
        document.getElementById('cat_show_condition').checked = true;
        document.getElementById('cat-form-title').textContent = 'Add Category';
    }
    </script>
    <?php endif; ?>

    <!-- ═══ SETTINGS ═══ -->
    <?php if ($tab === 'settings' && is_admin()): ?>
    <form method="post" action="marketplace.php?tab=settings">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="save_settings">

        <div class="adm-set-section">
            <p class="adm-set-title">Module</p>
            <p class="meta" style="font-size:.78rem;margin-bottom:8px;">Turning the whole module on/off has moved to <a href="monetization.php?tab=settings">Admin → Monetization → Settings</a>, and boost/featured/subscription package pricing has moved to <a href="monetization.php?tab=marketplace">Admin → Monetization → Marketplace</a>.</p>
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                <input type="checkbox" name="mp_require_product_approval" value="1" <?php echo ($cfg['mp_require_product_approval']??'1')==='1'?'checked':''; ?>>
                Require admin to approve products before listing
            </label>
        </div>

        <div class="adm-set-section">
            <p class="adm-set-title">Product Listings</p>
            <div class="form-group" style="max-width:320px;">
                <label>Default sort order on the public Marketplace page</label>
                <select name="mp_default_sort">
                    <?php $sortOptions = [
                        'default'    => 'Default (Featured, then a mix of new & trending, then others)',
                        'featured'   => 'Featured First',
                        'newest'     => 'Newest',
                        'price_asc'  => 'Price: Low → High',
                        'price_desc' => 'Price: High → Low',
                        'popular'    => 'Most Viewed',
                    ]; ?>
                    <?php foreach ($sortOptions as $sv=>$sl): ?>
                    <option value="<?php echo $sv; ?>" <?php echo ($cfg['mp_default_sort']??'default')===$sv?'selected':''; ?>><?php echo $sl; ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="meta" style="margin-top:4px;">Applies whenever a visitor hasn't picked a sort themselves — they can still override it with the sort dropdown on the Marketplace page.</p>
            </div>
        </div>

        <div class="adm-set-section">
            <p class="adm-set-title">Quote Requests</p>
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-bottom:12px;">
                <input type="checkbox" name="mp_quotes_enabled" value="1" <?php echo ($cfg['mp_quotes_enabled']??'1')==='1'?'checked':''; ?>>
                Enable Quote Requests platform-wide
            </label>
            <div class="adm-grid2">
                <div class="form-group">
                    <label>Which shops can receive quote requests?</label>
                    <select name="mp_quote_eligible_shops">
                        <option value="all"      <?php echo ($cfg['mp_quote_eligible_shops']??'all')==='all'?'selected':''; ?>>All active shops</option>
                        <option value="featured"  <?php echo ($cfg['mp_quote_eligible_shops']??'all')==='featured'?'selected':''; ?>>Featured shops only</option>
                        <option value="verified"  <?php echo ($cfg['mp_quote_eligible_shops']??'all')==='verified'?'selected':''; ?>>Verified shops only</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Auto-expire unanswered requests after (days)</label>
                    <input type="number" name="mp_quote_response_days" min="1" max="30" value="<?php echo sanitize($cfg['mp_quote_response_days']??'2'); ?>">
                </div>
            </div>
            <p class="meta" style="font-size:.78rem;">Full oversight of every shop's quote requests lives on the <a href="marketplace.php?tab=quotes">📝 Quote Requests</a> tab above.</p>
        </div>

        <button type="submit" class="button button-primary">Save Settings</button>
    </form>
    <?php endif; ?>

</main>

<script>
function mktToggleQrItems(id, btn) {
    var box = document.getElementById('qr-items-' + id);
    if (!box) return;
    var open = box.style.display !== 'none';
    box.style.display = open ? 'none' : 'block';
    btn.textContent = open ? '▸ View Items' : '▾ Hide Items';
}
</script>
</body>
</html>
