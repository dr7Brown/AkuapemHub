<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/marketplace_functions.php';

require_module_enabled('mp', 'Marketplace');
require_login();
$user  = current_user();
$flash = get_flash();

$shop = get_active_seller_shop((int)$user['id']);
$tab  = $_GET['tab'] ?? ($shop ? 'products' : 'setup');

// ── Handle shop setup / edit ──────────────────────────────────────────────
$shopError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'shop_settings') {
    csrf_check();

    // Posted id (0 = create a new shop) is the source of truth, not which
    // shop happens to be "active".
    $editId = (int)($_POST['id'] ?? 0);
    $editingShop = null;
    if ($editId) {
        if (!$shop || (int)$shop['id'] !== $editId) { flash('Shop not found.', 'error'); header('Location: seller_dashboard.php?tab=setup'); exit; }
        $editingShop = $shop;
    }

    $shopName   = trim($_POST['shop_name']    ?? '');
    $desc       = trim($_POST['description']  ?? '');
    $phone      = trim($_POST['phone']        ?? '');
    $email      = trim($_POST['email']        ?? '');
    $region     = trim($_POST['region']       ?? '');
    $townId     = (int)($_POST['town_id'] ?? 0) ?: null;
    $mapsLink   = trim($_POST['google_maps_link'] ?? '');

    if ($shopName === '') $shopError = 'Shop name is required.';
    elseif (!$editingShop && strlen($shopName) < 3) $shopError = 'Shop name must be at least 3 characters.';
    elseif (!$editingShop && requires_verified_email('shop_create') && !is_email_verified()) {
        $shopError = 'Please verify your email address before creating a shop.';
    } elseif (!$editingShop && is_banned_from_feature((int)$user['id'], 'mp')) {
        $shopError = 'You have been restricted from the Marketplace. Contact support if you believe this is an error.';
    } elseif ($mapsLink !== '' && !filter_var($mapsLink, FILTER_VALIDATE_URL)) {
        $shopError = 'Enter a valid Google Maps link (or leave it blank).';
    }

    if (!$shopError) {
        try {
            if ($editingShop) {
                $pdo->prepare('UPDATE mp_shops SET shop_name=?, description=?, phone=?, email=?, region=?, town_id=?, google_maps_link=?, updated_at=NOW() WHERE id=?')
                    ->execute([$shopName, $desc ?: null, $phone ?: null, $email ?: null, $region ?: null, $townId, $mapsLink ?: null, $editingShop['id']]);
                $shopId = $editingShop['id'];
            } else {
                $slug = mp_unique_slug($shopName, 'mp_shops', 'slug', $pdo);
                $pdo->prepare('INSERT INTO mp_shops (user_id, shop_name, slug, description, phone, email, region, town_id, google_maps_link) VALUES (?,?,?,?,?,?,?,?,?)')
                    ->execute([$user['id'], $shopName, $slug, $desc ?: null, $phone ?: null, $email ?: null, $region ?: null, $townId, $mapsLink ?: null]);
                $shopId = (int)$pdo->lastInsertId();
                $_SESSION['seller_active_shop_id'] = $shopId; // switch into the newly created shop
            }

            // Handle logo upload
            if (!empty($_FILES['logo']['name']) && is_valid_image_upload($_FILES['logo'])) {
                $logoPath = save_uploaded_image($_FILES['logo'], 'uploads/marketplace/shops/' . $shopId . '/logo',
                    (int)get_platform_setting('img_mp_logo_maxwidth', '500'),
                    (int)get_platform_setting('img_mp_logo_quality', '85'));
                if ($logoPath) $pdo->prepare('UPDATE mp_shops SET logo_path=? WHERE id=?')->execute([$logoPath, $shopId]);
            }

            // Handle banner upload
            if (!empty($_FILES['banner']['name']) && is_valid_image_upload($_FILES['banner'])) {
                $bannerPath = save_uploaded_image($_FILES['banner'], 'uploads/marketplace/shops/' . $shopId . '/banner',
                    (int)get_platform_setting('img_mp_banner_maxwidth', '1200'),
                    (int)get_platform_setting('img_mp_banner_quality', '85'));
                if ($bannerPath) $pdo->prepare('UPDATE mp_shops SET banner_path=? WHERE id=?')->execute([$bannerPath, $shopId]);
            }

            flash($editingShop ? 'Shop settings saved.' : 'Shop created! Now add your first product.', 'success');
            header('Location: seller_dashboard.php?tab=products');
            exit;
        } catch (PDOException $e) {
            $shopError = (int)$e->errorInfo[1] === 1062
                ? 'You already have a shop.'
                : 'Could not save shop — please try again.';
        }
    }
    $tab = 'setup';
}

// ── Handle order status update ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'order_status') {
    csrf_check();
    if (!$shop) { flash('Create your shop first.', 'warning'); header('Location: seller_dashboard.php'); exit; }

    $orderId   = (int)$_POST['order_id'];
    $newStatus = $_POST['new_status'] ?? '';

    $orderRow = $pdo->prepare('SELECT * FROM mp_orders WHERE id = ? AND shop_id = ?');
    $orderRow->execute([$orderId, $shop['id']]);
    $order = $orderRow->fetch();

    // Collapsed: "confirmed" was pure busywork between pending and processing
    // with no operational difference, so one click now takes an order straight
    // from pending to processing ("Accept Order"). 'confirmed' stays a valid
    // transition target only so any pre-existing order stuck there isn't stranded.
    // Market orders skip the delivery-agent leg entirely — the assigned market
    // manager drives processing → at_storehouse → delivered from
    // admin/market_deliveries.php instead, so sellers aren't offered
    // "ready_for_delivery" for those orders. Keyed off the ORDER's own
    // snapshotted market_id, not the shop's current one — the shop's market
    // assignment can change after the order was placed.
    $validTransitions = [
        'pending'    => ['processing','cancelled'],
        'confirmed'  => ['processing','cancelled'],
        'processing' => !empty($order['market_id']) ? ['cancelled'] : ['ready_for_delivery','cancelled'],
    ];

    // Once a buyer has paid, sellers can no longer self-cancel — the money has
    // already been credited to the shop's pending balance, so cancelling here
    // would let the seller keep both the stock and the funds. Admin must
    // process any post-payment cancellation via the transaction refund page.
    if ($order && $newStatus === 'cancelled' && $order['payment_status'] === 'paid') {
        flash('This order has already been paid. Contact an admin to process a cancellation/refund.', 'error');
        header('Location: seller_dashboard.php?tab=orders');
        exit;
    }

    if ($order && in_array($newStatus, $validTransitions[$order['status']] ?? [], true)) {
        $pdo->prepare('UPDATE mp_orders SET status=?, updated_at=NOW() WHERE id=?')->execute([$newStatus, $orderId]);

        // Delivery integration: when ready_for_delivery, auto-create delivery
        // request — unless the buyer chose to arrange their own pickup at
        // checkout (mp_orders.delivery_mode), in which case there's nothing
        // to create and the seller coordinates handoff directly.
        $deliveryFailed = false;
        if ($newStatus === 'ready_for_delivery' && !$order['delivery_request_id'] && ($order['delivery_mode'] ?? 'platform') !== 'self_arranged') {
            $fullOrder = $pdo->prepare('SELECT * FROM mp_orders WHERE id = ?');
            $fullOrder->execute([$orderId]);
            $orderData = $fullOrder->fetch();
            $deliveryId = mp_create_delivery_for_order($orderData, (array)$shop);
            if ($deliveryId) {
                $pdo->prepare('UPDATE mp_orders SET delivery_request_id=? WHERE id=?')->execute([$deliveryId, $orderId]);
            } else {
                $deliveryFailed = true;
            }
        }

        notify_user((int)$order['customer_id'], 'Order Update 📦',
            'Your order #' . $orderId . ' is now: ' . mp_order_status_label($newStatus), 'info');

        if ($deliveryFailed) {
            flash('Order #' . $orderId . ' marked ready, but the delivery request could not be created. Use "Retry Delivery Request" on the order below.', 'error');
        } else {
            flash('Order #' . $orderId . ' updated to ' . mp_order_status_label($newStatus), 'success');
        }
    }
    header('Location: seller_dashboard.php?tab=orders');
    exit;
}

// ── Retry a delivery request that failed to auto-create ──────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'retry_delivery') {
    csrf_check();
    if (!$shop) { flash('Create your shop first.', 'warning'); header('Location: seller_dashboard.php'); exit; }

    $orderId = (int)$_POST['order_id'];
    $orderRow = $pdo->prepare("SELECT * FROM mp_orders WHERE id=? AND shop_id=? AND status='ready_for_delivery' AND delivery_request_id IS NULL AND delivery_mode != 'self_arranged'");
    $orderRow->execute([$orderId, $shop['id']]);
    $order = $orderRow->fetch();

    if ($order) {
        $deliveryId = mp_create_delivery_for_order($order, (array)$shop);
        if ($deliveryId) {
            $pdo->prepare('UPDATE mp_orders SET delivery_request_id=? WHERE id=?')->execute([$deliveryId, $orderId]);
            flash('Delivery request created for order #' . $orderId . '.', 'success');
        } else {
            flash('Still could not create the delivery request. Please contact support.', 'error');
        }
    }
    header('Location: seller_dashboard.php?tab=orders');
    exit;
}

// ── Handle quote request response (price it or decline) ──────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'quote_respond') {
    csrf_check();
    if (!$shop) { flash('Create your shop first.', 'warning'); header('Location: seller_dashboard.php'); exit; }

    $quoteId = (int)$_POST['quote_id'];
    $qrRow = $pdo->prepare("SELECT * FROM mp_quote_requests WHERE id=? AND shop_id=? AND status='pending'");
    $qrRow->execute([$quoteId, $shop['id']]);
    $qr = $qrRow->fetch();

    if ($qr) {
        $itemsStmt = $pdo->prepare('SELECT * FROM mp_quote_request_items WHERE quote_request_id=?');
        $itemsStmt->execute([$quoteId]);
        $qrItems = $itemsStmt->fetchAll();

        $prices      = $_POST['price'] ?? [];
        $unavailable = array_map('intval', $_POST['unavailable'] ?? []);
        $total       = 0;
        $anyPriced   = false;

        $updStmt = $pdo->prepare('UPDATE mp_quote_request_items SET price=?, is_available=? WHERE id=? AND quote_request_id=?');
        foreach ($qrItems as $item) {
            $isAvailable = !in_array((int)$item['id'], $unavailable, true);
            $price = isset($prices[$item['id']]) ? (float)$prices[$item['id']] : null;
            if ($isAvailable && $price > 0) {
                $anyPriced = true;
                $total += $price;
            } elseif (!$isAvailable) {
                $price = null;
            }
            $updStmt->execute([$price, $isAvailable ? 1 : 0, $item['id'], $quoteId]);
        }

        if (!$anyPriced) {
            flash('Enter a price for at least one available item.', 'error');
        } else {
            $pdo->prepare("UPDATE mp_quote_requests SET status='quoted', total_amount=?, quoted_at=NOW() WHERE id=?")
                ->execute([$total, $quoteId]);
            notify_user((int)$qr['customer_id'], 'Your Quote is Ready 💰',
                $shop['shop_name'] . ' priced your shopping list — total GH₵ ' . number_format($total, 2) . '. Review and pay to confirm your order.',
                'success', 'orders.php?view=quotes');
            flash('Quote sent to the customer.', 'success');
        }
    }
    header('Location: seller_dashboard.php?tab=quotes');
    exit;
}

// ── Handle quote request decline ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'quote_decline') {
    csrf_check();
    if (!$shop) { flash('Create your shop first.', 'warning'); header('Location: seller_dashboard.php'); exit; }

    $quoteId = (int)$_POST['quote_id'];
    $reason  = trim($_POST['decline_reason'] ?? '');
    $qrRow = $pdo->prepare("SELECT * FROM mp_quote_requests WHERE id=? AND shop_id=? AND status='pending'");
    $qrRow->execute([$quoteId, $shop['id']]);
    $qr = $qrRow->fetch();

    if ($qr) {
        $pdo->prepare("UPDATE mp_quote_requests SET status='declined', decline_reason=? WHERE id=?")
            ->execute([$reason ?: null, $quoteId]);
        notify_user((int)$qr['customer_id'], 'Quote Request Declined',
            $shop['shop_name'] . ' could not fulfil your shopping list.' . ($reason ? ' Reason: ' . $reason : ''),
            'info', 'orders.php?view=quotes');
        flash('Request declined.', 'info');
    }
    header('Location: seller_dashboard.php?tab=quotes');
    exit;
}

// ── Handle payout / withdrawal request ────────────────────────────────────
$payoutError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'request_payout') {
    csrf_check();
    if (!$shop) { flash('Create your shop first.', 'warning'); header('Location: seller_dashboard.php'); exit; }

    $amount          = (float)($_POST['amount'] ?? 0);
    $payoutAccountId = (int)($_POST['payout_account_id'] ?? 0);

    $pendSt = $pdo->prepare("SELECT COUNT(*) FROM mp_payout_requests WHERE shop_id=? AND status IN ('pending','processing')");
    $pendSt->execute([$shop['id']]);
    $hasPendingPayout = (int)$pendSt->fetchColumn() > 0;

    $payoutAccount = null;
    if ($payoutAccountId) {
        $paStmt = $pdo->prepare('SELECT * FROM mp_payout_accounts WHERE id=? AND shop_id=?');
        $paStmt->execute([$payoutAccountId, $shop['id']]);
        $payoutAccount = $paStmt->fetch();
    }

    if ($hasPendingPayout) $payoutError = 'You already have a pending withdrawal request. Wait for it to be processed.';
    elseif ($amount < 1) $payoutError = 'Enter a valid withdrawal amount.';
    elseif ($amount > (float)$shop['available_balance']) $payoutError = 'You only have GH₵ ' . number_format($shop['available_balance'], 2) . ' available.';
    elseif (!$payoutAccount) $payoutError = 'Select which account to pay out to.';

    if (!$payoutError) {
        $pdo->prepare(
            'INSERT INTO mp_payout_requests
                (shop_id, amount, payout_account_id, method, account_name, account_number, bank_name, bank_code, momo_number, status)
             VALUES (?,?,?,?,?,?,?,?,?,\'pending\')'
        )->execute([
            $shop['id'], $amount, $payoutAccount['id'], $payoutAccount['method'],
            $payoutAccount['account_name'], $payoutAccount['account_number'],
            $payoutAccount['bank_name'], $payoutAccount['bank_code'],
            $payoutAccount['method'] === 'momo' ? $payoutAccount['account_number'] : null,
        ]);
        $newRequestId = (int)$pdo->lastInsertId();
        log_audit_action((int)$user['id'], 'mp_payout_request', "Requested withdrawal of GHS " . number_format($amount, 2) . " via {$payoutAccount['method']} #{$payoutAccount['id']}");

        if (get_platform_setting('mp_payout_mode', 'manual') === 'auto') {
            require_once __DIR__ . '/marketplace_functions.php';
            process_marketplace_payout($newRequestId, 0);
            flash('Withdrawal request submitted and sent for payout.', 'success');
        } else {
            flash('Withdrawal request submitted! An admin will review it shortly.', 'success');
        }
        header('Location: seller_dashboard.php?tab=wallet');
        exit;
    }
}

// ── Handle product delete / toggle ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'product_action') {
    csrf_check();
    if (!$shop) { flash('Create your shop first.', 'warning'); header('Location: seller_dashboard.php'); exit; }
    $productId = (int)$_POST['product_id'];
    $action    = $_POST['action'] ?? '';

    // Verify product belongs to this shop
    $chk = $pdo->prepare('SELECT id, status FROM mp_products WHERE id=? AND shop_id=?');
    $chk->execute([$productId, $shop['id']]);
    $prod = $chk->fetch();

    if ($prod) {
        if ($action === 'delete') {
            $pdo->prepare('DELETE FROM mp_products WHERE id=?')->execute([$productId]);
            flash('Product deleted.', 'info');
        } elseif ($action === 'archive' && $prod['status'] === 'approved') {
            $pdo->prepare("UPDATE mp_products SET status='archived' WHERE id=?")->execute([$productId]);
            flash('Product archived.', 'info');
        } elseif ($action === 'reactivate' && $prod['status'] === 'archived') {
            $listCheck = mp_shop_can_list_product((int)$shop['id']);
            if (!$listCheck['allowed']) {
                if ($listCheck['no_subscription']) {
                    // Send them straight to checkout with the reason, rather than
                    // just showing the message on the products tab they can't act on.
                    flash('You need an active marketplace subscription before you can list products. Subscribe to a package to continue.', 'error');
                    header('Location: pay_mp_subscription.php');
                    exit;
                }
                flash('You have reached your monthly product limit. Upgrade your subscription or remove existing listings.', 'error');
            } else {
                $pdo->prepare("UPDATE mp_products SET status='pending_approval' WHERE id=?")->execute([$productId]);
                flash('Product resubmitted for approval.', 'success');
            }
        }
    }
    header('Location: seller_dashboard.php?tab=products');
    exit;
}

// ── Cancel own subscription ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'cancel_subscription') {
    csrf_check();
    if ($shop) {
        $subId = (int)($_POST['subscription_id'] ?? 0);
        $chk = $pdo->prepare("SELECT id FROM mp_seller_subscriptions WHERE id=? AND shop_id=?");
        $chk->execute([$subId, $shop['id']]);
        if ($chk->fetch() && mp_cancel_subscription($subId)) {
            flash('Subscription cancelled.', 'success');
        } else {
            flash('Could not cancel — subscription not found.', 'error');
        }
    }
    header('Location: seller_dashboard.php?tab=subscription');
    exit;
}

// ── Load data ─────────────────────────────────────────────────────────────
$products = $orders = [];
$stats = ['products'=>0,'active'=>0,'pending_orders'=>0,'total_revenue'=>0];

$productStatusFilter = $_GET['pstatus'] ?? 'all';
$productQ            = trim($_GET['pq'] ?? '');
$validProductStatuses = ['draft','pending_approval','approved','rejected','out_of_stock','archived'];

$orderStatusFilter = $_GET['ostatus'] ?? 'all';
$orderQ            = trim($_GET['oq'] ?? '');
$validOrderStatuses = ['pending','confirmed','processing','ready_for_delivery','in_transit','at_storehouse','delivered','cancelled','refunded'];

if ($shop) {
    $pWhere  = ['mp.shop_id = ?'];
    $pParams = [$shop['id']];
    if (in_array($productStatusFilter, $validProductStatuses, true)) {
        $pWhere[] = 'mp.status = ?';
        $pParams[] = $productStatusFilter;
    }
    if ($productQ !== '') {
        $pWhere[] = 'mp.name LIKE ?';
        $pParams[] = '%' . $productQ . '%';
    }
    $products = $pdo->prepare(
        'SELECT mp.*, mc.name AS cat_name,
                (SELECT image_path FROM mp_product_images WHERE product_id=mp.id AND is_primary=1 LIMIT 1) AS primary_image
         FROM mp_products mp
         LEFT JOIN mp_categories mc ON mp.category_id=mc.id
         WHERE ' . implode(' AND ', $pWhere) . '
         ORDER BY mp.created_at DESC LIMIT 60'
    );
    $products->execute($pParams);
    $products = $products->fetchAll();

    $oWhere  = ['mo.shop_id = ?'];
    $oParams = [$shop['id']];
    if (in_array($orderStatusFilter, $validOrderStatuses, true)) {
        $oWhere[] = 'mo.status = ?';
        $oParams[] = $orderStatusFilter;
    }
    if ($orderQ !== '') {
        $oWhere[] = '(u.name LIKE ? OR mo.id = ?)';
        $oParams[] = '%' . $orderQ . '%';
        $oParams[] = (int)$orderQ;
    }
    $orderSort = $_GET['osort'] ?? 'newest';
    $orderOrderBy = match($orderSort) {
        'oldest'  => 'mo.created_at ASC',
        'amt_high'=> 'mo.total_amount DESC',
        'amt_low' => 'mo.total_amount ASC',
        default   => 'mo.created_at DESC',
    };
    $orders = $pdo->prepare(
        'SELECT mo.*, u.name AS customer_name
         FROM mp_orders mo JOIN users u ON mo.customer_id=u.id
         WHERE ' . implode(' AND ', $oWhere) . "
         ORDER BY $orderOrderBy LIMIT 40"
    );
    $orders->execute($oParams);
    $orders = $orders->fetchAll();

    // Lazy-expire quote requests the seller never responded to — no cron needed.
    $qrResponseHours = max(1, (int)get_platform_setting('mp_quote_response_days', '2')) * 24;
    $pdo->prepare("UPDATE mp_quote_requests SET status='expired' WHERE shop_id=? AND status='pending' AND created_at < NOW() - INTERVAL $qrResponseHours HOUR")
        ->execute([$shop['id']]);

    $quoteRequests = $pdo->prepare(
        "SELECT mqr.*, u.name AS customer_name
         FROM mp_quote_requests mqr JOIN users u ON mqr.customer_id=u.id
         WHERE mqr.shop_id=?
         ORDER BY FIELD(mqr.status,'pending','quoted','declined','cancelled','expired','paid'), mqr.created_at DESC
         LIMIT 40"
    );
    $quoteRequests->execute([$shop['id']]);
    $quoteRequests = $quoteRequests->fetchAll();

    $quoteRequestItems = [];
    if ($quoteRequests) {
        $qrIds = implode(',', array_column($quoteRequests, 'id'));
        $qriStmt = $pdo->query("SELECT * FROM mp_quote_request_items WHERE quote_request_id IN ($qrIds) ORDER BY sort_order ASC");
        foreach ($qriStmt->fetchAll() as $qri) {
            $quoteRequestItems[$qri['quote_request_id']][] = $qri;
        }
    }

    // Dedicated unfiltered count — $quoteRequests above is capped at 40 and
    // must not skew this badge, same reasoning as the stats below.
    $pendingQuoteCountSt = $pdo->prepare("SELECT COUNT(*) FROM mp_quote_requests WHERE shop_id=? AND status='pending'");
    $pendingQuoteCountSt->execute([$shop['id']]);
    $pendingQuoteCount = (int)$pendingQuoteCountSt->fetchColumn();

    // Dedicated unfiltered queries — the product/order filters above must not
    // skew these dashboard-wide stat cards.
    $productsCountSt = $pdo->prepare('SELECT COUNT(*), SUM(status="approved") FROM mp_products WHERE shop_id=?');
    $productsCountSt->execute([$shop['id']]);
    [$stats['products'], $stats['active']] = $productsCountSt->fetch(PDO::FETCH_NUM);
    $stats['products'] = (int)$stats['products'];
    $stats['active']   = (int)$stats['active'];

    $ordersStatsSt = $pdo->prepare('SELECT SUM(status="pending"), SUM(IF(status="delivered", total_amount, 0)) FROM mp_orders WHERE shop_id=?');
    $ordersStatsSt->execute([$shop['id']]);
    [$stats['pending_orders'], $stats['total_revenue']] = $ordersStatsSt->fetch(PDO::FETCH_NUM);
    $stats['pending_orders'] = (int)$stats['pending_orders'];
    $stats['total_revenue']  = (float)$stats['total_revenue'];
}

// ── Analytics (own queries — the $orders array above is capped at 40 rows) ──
$analytics = null;
if ($shop && $tab === 'analytics') {
    $paidStatuses = "'confirmed','processing','ready_for_delivery','in_transit','at_storehouse','delivered'"; // excludes pending/cancelled/refunded

    $period = $_GET['period'] ?? 'month';
    $periodDateFilter = match($period) {
        'today' => 'AND mo.created_at >= CURDATE()',
        'week'  => 'AND mo.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)',
        'month' => "AND mo.created_at >= DATE_FORMAT(NOW(),'%Y-%m-01')",
        'year'  => 'AND YEAR(mo.created_at)=YEAR(NOW())',
        default => '',
    };

    $rev = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM mp_orders WHERE shop_id=? AND status IN ($paidStatuses)");
    $rev->execute([$shop['id']]);
    $totalRevenue = (float)$rev->fetchColumn();

    $revPeriod = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM mp_orders mo WHERE shop_id=? AND status IN ($paidStatuses) $periodDateFilter");
    $revPeriod->execute([$shop['id']]);
    $monthRevenue = (float)$revPeriod->fetchColumn();

    $ordCount = $pdo->prepare("SELECT COUNT(*) FROM mp_orders mo WHERE shop_id=? AND status IN ($paidStatuses) $periodDateFilter");
    $ordCount->execute([$shop['id']]);
    $orderCount = (int)$ordCount->fetchColumn();

    $cancelCount = $pdo->prepare("SELECT COUNT(*) FROM mp_orders mo WHERE shop_id=? AND status IN ('cancelled','refunded') $periodDateFilter");
    $cancelCount->execute([$shop['id']]);
    $cancelledCount = (int)$cancelCount->fetchColumn();

    $avgOrder = $orderCount > 0 ? $monthRevenue / $orderCount : 0;

    // Daily revenue, last 30 days (for the chart)
    $daily = $pdo->prepare("
        SELECT DATE(created_at) AS d, SUM(total_amount) AS rev
        FROM mp_orders
        WHERE shop_id=? AND status IN ($paidStatuses) AND created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
        GROUP BY DATE(created_at)
    ");
    $daily->execute([$shop['id']]);
    $dailyMap = array_column($daily->fetchAll(), 'rev', 'd');
    $dailyLabels = $dailyRevenue = [];
    for ($i = 29; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-{$i} days"));
        $dailyLabels[] = date('d M', strtotime($d));
        $dailyRevenue[] = (float)($dailyMap[$d] ?? 0);
    }

    // Top products by revenue (paid orders only, within the selected period)
    $topProducts = $pdo->prepare("
        SELECT moi.product_name, SUM(moi.quantity) AS units_sold, SUM(moi.subtotal) AS revenue
        FROM mp_order_items moi JOIN mp_orders mo ON moi.order_id = mo.id
        WHERE mo.shop_id=? AND mo.status IN ($paidStatuses) $periodDateFilter
        GROUP BY moi.product_name ORDER BY revenue DESC LIMIT 5
    ");
    $topProducts->execute([$shop['id']]);
    $topProducts = $topProducts->fetchAll();
    $topProductsMax = $topProducts ? max(array_column($topProducts, 'revenue')) : 0;

    // Most viewed products (lifetime views, not scoped to the revenue period above)
    $topViewedProducts = $pdo->prepare("
        SELECT name, view_count FROM mp_products
        WHERE shop_id = ? AND status = 'approved'
        ORDER BY view_count DESC LIMIT 5
    ");
    $topViewedProducts->execute([$shop['id']]);
    $topViewedProducts = $topViewedProducts->fetchAll();
    $topViewedProductsMax = $topViewedProducts ? max(array_column($topViewedProducts, 'view_count')) : 0;

    $analytics = compact('totalRevenue','monthRevenue','orderCount','cancelledCount','avgOrder','dailyLabels','dailyRevenue','topProducts','topProductsMax','topViewedProducts','topViewedProductsMax');
}

// ── Wallet (balances, pending releases, payout history, activity ledger) ──
$payoutRequests = [];
if ($shop && $tab === 'wallet') {
    $withdrawalStatusFilter = $_GET['wstatus'] ?? 'all';
    $ledgerTypeFilter       = $_GET['ltype'] ?? 'all';

    $payoutWhere  = 'WHERE shop_id=?';
    $payoutParams = [$shop['id']];
    if (in_array($withdrawalStatusFilter, ['pending','approved','processing','paid','rejected','failed'], true)) {
        $payoutWhere .= ' AND status=?';
        $payoutParams[] = $withdrawalStatusFilter;
    }
    $payoutStmt = $pdo->prepare("SELECT * FROM mp_payout_requests $payoutWhere ORDER BY created_at DESC LIMIT 20");
    $payoutStmt->execute($payoutParams);
    $payoutRequests = $payoutStmt->fetchAll();

    // "Pending request" guard for the request form always checks the true state,
    // not the filtered list — otherwise filtering could hide an active pending one.
    $truePendingStmt = $pdo->prepare("SELECT COUNT(*) FROM mp_payout_requests WHERE shop_id=? AND status='pending'");
    $truePendingStmt->execute([$shop['id']]);
    $hasPendingPayoutDisplay = (bool)$truePendingStmt->fetchColumn();

    // Orders still contributing to the pending balance — paid but not yet released
    $pendingReleaseStmt = $pdo->prepare(
        "SELECT mo.id, mo.net_amount, mo.status, mo.payout_release_at,
                EXISTS (SELECT 1 FROM delivery_disputes dd WHERE dd.delivery_request_id = mo.delivery_request_id AND dd.status IN ('open','investigating')) AS has_open_dispute
         FROM mp_orders mo
         WHERE mo.shop_id=? AND mo.payment_status='paid' AND mo.payout_released=0
         ORDER BY (mo.payout_release_at IS NULL) DESC, mo.payout_release_at ASC, mo.created_at DESC
         LIMIT 15"
    );
    $pendingReleaseStmt->execute([$shop['id']]);
    $pendingReleases = $pendingReleaseStmt->fetchAll();

    // Recent wallet ledger — sales credited, releases, withdrawals, reversals
    $ledgerWhere  = 'WHERE shop_id=?';
    $ledgerParams = [$shop['id']];
    if (in_array($ledgerTypeFilter, ['sale_pending','released_to_available','withdrawal','reversal'], true)) {
        $ledgerWhere .= ' AND type=?';
        $ledgerParams[] = $ledgerTypeFilter;
    }
    $ledgerStmt = $pdo->prepare("SELECT * FROM mp_wallet_transactions $ledgerWhere ORDER BY created_at DESC LIMIT 25");
    $ledgerStmt->execute($ledgerParams);
    $walletLedger = $ledgerStmt->fetchAll();

    $confirmDaysDisplay = (int)get_platform_setting('mp_payout_confirmation_days', 3);
}

$categories = $pdo->query('SELECT * FROM mp_categories ORDER BY sort_order, name')->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Seller Dashboard — AkuapemConnect</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        /* ── Hero + stats ── */
        .sd-hero { background:linear-gradient(135deg, #189586 0%, var(--primary-dark,#0b5c56) 100%); padding:16px 16px 18px; position:relative; overflow:hidden; }
        .sd-hero::after { content:''; position:absolute; top:-70px; right:-50px; width:220px; height:220px; border-radius:50%; background:radial-gradient(circle, rgba(255,255,255,.2), transparent 70%); pointer-events:none; }
        .sd-stats { position:relative; display:flex; gap:9px; overflow-x:auto; scrollbar-width:none; }
        .sd-stats::-webkit-scrollbar { display:none; }
        .sd-stat  { flex:1; min-width:90px; background:rgba(255,255,255,.18); backdrop-filter:blur(6px); border-radius:14px; padding:11px 8px; text-align:center; }
        .sd-stat strong { display:block; font-size:1.22rem; font-weight:900; color:#fff; line-height:1.15; }
        .sd-stat span   { font-size:.64rem; font-weight:700; color:rgba(255,255,255,.88); text-transform:uppercase; letter-spacing:.03em; }

        /* ── Tabs ── */
        .sd-tabs-wrap { background:var(--surface); border-bottom:1px solid var(--border); }
        .sd-tabs  { display:flex; gap:5px; padding:9px 12px; overflow-x:auto; scrollbar-width:none; max-width:980px; margin:0 auto; }
        .sd-tabs::-webkit-scrollbar { display:none; }
        .sd-tab   { flex-shrink:0; display:inline-flex; align-items:center; gap:5px; padding:8px 14px; font-size:.82rem; font-weight:700; text-decoration:none; color:var(--text-muted,#6b7280); border-radius:20px; transition:background .15s, color .15s; white-space:nowrap; }
        .sd-tab:hover { background:var(--surface-muted,#f8fafc); }
        .sd-tab.active { color:var(--primary,#0f766e); background:var(--primary-soft,#d1fae5); }

        .sd-shell { max-width:980px; margin:0 auto; padding:18px 16px 80px; }

        /* ── Shared elevated cards ── */
        .sd-card, .sd-set-section { background:var(--surface); border:1px solid var(--border); border-radius:16px; padding:18px; margin-bottom:14px; box-shadow:0 1px 3px rgba(0,0,0,.04); }
        .sd-card-title, .sd-set-title { font-size:.74rem; font-weight:800; text-transform:uppercase; letter-spacing:.07em; color:var(--text-muted,#6b7280); margin:0 0 14px; }

        .sd-prod-card, .sd-ord-card { background:var(--surface); border:1px solid var(--border); border-radius:16px; padding:14px 16px; margin-bottom:10px; box-shadow:0 1px 3px rgba(0,0,0,.04); transition:box-shadow .15s, transform .15s; }
        .sd-prod-card { display:flex; align-items:center; gap:12px; }
        .sd-prod-card:hover, .sd-ord-card:hover { box-shadow:0 10px 24px -12px rgba(0,0,0,.16); transform:translateY(-1px); }
        .sd-prod-img  { width:52px; height:52px; border-radius:10px; background:var(--surface-muted,#f8fafc); overflow:hidden; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
        .sd-prod-img img { width:100%; height:100%; object-fit:cover; }

        .sd-badge { display:inline-block; padding:3px 10px; border-radius:20px; font-size:.68rem; font-weight:800; }

        /* ── Status banners (verify tab) ── */
        .sd-status-banner { display:flex; align-items:center; gap:12px; border-radius:14px; padding:14px 16px; margin-bottom:16px; font-size:.86rem; }
        .sd-status-icon { flex-shrink:0; width:38px; height:38px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:1.1rem; box-shadow:0 1px 3px rgba(0,0,0,.08); }

        /* ── Filter bar ── */
        .sd-filters { display:flex; gap:8px; flex-wrap:wrap; align-items:center; background:var(--surface); border:1px solid var(--border); border-radius:14px; padding:10px; margin-bottom:14px; }
        .sd-filters select, .sd-filters input[type=text] { padding:8px 11px; border:1px solid var(--border); border-radius:9px; font-size:.82rem; background:var(--surface-muted,#f8fafc); }
        .sd-filters input[type=text] { flex:1; min-width:160px; }

        label { font-weight:600; font-size:.86rem; display:block; margin-bottom:4px; }
        .form-group { margin-bottom:14px; }
        .sd-desc-group .rte-editor { max-height:16.5em; }
    </style>
</head>
<body class="has-bottom-nav">

<header class="app-topbar">
    <a href="marketplace.php" class="button button-secondary button-small" style="flex-shrink:0;">← Marketplace</a>
    <span class="brand" style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">🏪 <?php echo $shop ? sanitize(mb_substr($shop['shop_name'],0,20)) : 'My Shop'; ?>
        <?php if ($shop && !empty($shop['is_subscribed']) && !empty($shop['subscription_end']) && $shop['subscription_end'] >= date('Y-m-d')):
            $__navSub = get_shop_active_subscription((int)$shop['id']); ?>
        <span style="font-size:.62rem;font-weight:800;background:<?php echo sanitize($__navSub['badge_color'] ?? '#fef3c7'); ?>;color:#92400e;padding:1px 7px;border-radius:20px;margin-left:4px;">⭐ <?php echo sanitize($__navSub['badge_name'] ?? 'PRO'); ?></span>
        <?php endif; ?>
    </span>
</header>

<?php if ($flash): ?>
<div class="alert alert-<?php echo sanitize($flash['type']); ?>" style="margin:10px 16px 0;"><?php echo sanitize($flash['message']); ?></div>
<?php endif; ?>

<?php if ($shop): ?>
<!-- Hero + stats -->
<div class="sd-hero">
    <div class="sd-stats">
        <div class="sd-stat"><strong><?php echo $stats['products']; ?></strong><span>Products</span></div>
        <div class="sd-stat"><strong><?php echo $stats['active']; ?></strong><span>Active</span></div>
        <div class="sd-stat"><strong style="<?php echo $stats['pending_orders']>0?'color:#fbbf24;':''; ?>"><?php echo $stats['pending_orders']; ?></strong><span>New Orders</span></div>
        <div class="sd-stat"><strong>GH&#8373; <?php echo number_format($stats['total_revenue'],2); ?></strong><span>Revenue</span></div>
        <div class="sd-stat"><strong><?php echo number_format($shop['view_count']); ?></strong><span>Shop Views</span></div>
    </div>
</div>
<?php endif; ?>

<!-- Tabs -->
<div class="sd-tabs-wrap">
<div class="sd-tabs">
    <?php if ($shop): ?>
    <a href="?tab=products" class="sd-tab <?php echo $tab==='products'?'active':''; ?>">📦 Products</a>
    <a href="?tab=orders"   class="sd-tab <?php echo $tab==='orders'?'active':''; ?>">
        📋 Orders <?php if ($stats['pending_orders']): ?><span style="background:#f59e0b;color:#fff;border-radius:10px;padding:0 5px;font-size:.62rem;margin-left:3px;"><?php echo $stats['pending_orders']; ?></span><?php endif; ?>
    </a>
    <a href="?tab=quotes" class="sd-tab <?php echo $tab==='quotes'?'active':''; ?>">
        📝 Quote Requests <?php if ($pendingQuoteCount): ?><span style="background:#f59e0b;color:#fff;border-radius:10px;padding:0 5px;font-size:.62rem;margin-left:3px;"><?php echo $pendingQuoteCount; ?></span><?php endif; ?>
    </a>
    <a href="?tab=analytics" class="sd-tab <?php echo $tab==='analytics'?'active':''; ?>">📊 Analytics</a>
    <a href="?tab=wallet"    class="sd-tab <?php echo $tab==='wallet'?'active':''; ?>">💳 Wallet</a>
    <?php if (get_platform_setting('mp_subscription_enabled','0')==='1'): ?>
    <a href="?tab=subscription" class="sd-tab <?php echo $tab==='subscription'?'active':''; ?>">⭐ Subscription</a>
    <?php endif; ?>
    <?php endif; ?>
    <a href="?tab=setup" class="sd-tab <?php echo $tab==='setup'?'active':''; ?>">🏪 <?php echo $shop ? 'Shop Settings' : 'Create Shop'; ?></a>
    <?php if ($shop): ?>
    <a href="?tab=verify" class="sd-tab <?php echo $tab==='verify'?'active':''; ?>">
        ✅ Verification <?php if ($shop['verification_status']==='approved'): ?><span style="color:#10b981;">✓</span><?php endif; ?>
    </a>
    <?php endif; ?>
</div>
</div>

<div class="sd-shell">

<?php if (!$shop && $tab !== 'setup'): ?>
<div style="text-align:center;padding:48px 20px;color:var(--text-muted,#6b7280);">
    <div style="font-size:3rem;opacity:.4;margin-bottom:14px;">🏪</div>
    <p style="margin:0 0 16px;">Set up your shop to start selling.</p>
    <a href="?tab=setup" class="button button-primary">Create Your Shop →</a>
</div>

<?php elseif ($tab === 'products' && $shop): ?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:8px;">
    <p style="margin:0;font-size:.78rem;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted,#6b7280);"><?php echo count($products); ?> Products</p>
    <a href="seller_boost.php" class="button button-secondary button-small">⚡ Boost</a>
    <a href="browse_master_catalog.php" class="button button-secondary button-small">📚 Add from Catalog</a>
    <a href="seller_product_form.php" class="button button-primary button-small">+ Add Product</a>
</div>

<form method="get" class="sd-filters">
    <input type="hidden" name="tab" value="products">
    <select name="pstatus" onchange="this.form.submit()">
        <option value="all" <?php echo $productStatusFilter==='all'?'selected':''; ?>>All Statuses</option>
        <?php foreach ($validProductStatuses as $vs): ?>
        <option value="<?php echo $vs; ?>" <?php echo $productStatusFilter===$vs?'selected':''; ?>><?php echo mp_product_status_label($vs); ?></option>
        <?php endforeach; ?>
    </select>
    <input type="text" name="pq" value="<?php echo sanitize($productQ); ?>" placeholder="Search product name…">
    <button type="submit" class="button button-secondary button-small">Filter</button>
    <?php if ($productStatusFilter !== 'all' || $productQ !== ''): ?><a href="?tab=products" class="button button-secondary button-small">Clear</a><?php endif; ?>
</form>
<?php if ($products): foreach ($products as $p): ?>
<div class="sd-prod-card">
    <div class="sd-prod-img">
        <?php if ($p['primary_image']): ?><img src="<?php echo sanitize($p['primary_image']); ?>" alt=""><?php else: ?><span style="font-size:1.2rem;opacity:.3;">📦</span><?php endif; ?>
    </div>
    <div style="flex:1;min-width:0;">
        <div style="font-weight:700;font-size:.88rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo sanitize($p['name']); ?></div>
        <div style="font-size:.76rem;color:var(--text-muted,#6b7280);">GH&#8373; <?php echo number_format(mp_effective_price($p),2); ?><?php if (!empty($p['price_unit'])): ?> / <?php echo sanitize($p['price_unit']); ?><?php endif; ?> &nbsp;·&nbsp; Stock: <?php echo $p['stock_quantity']; ?></div>
        <?php if ($p['status'] === 'rejected' && !empty($p['rejection_reason'])): ?>
        <div style="background:#fee2e2;border-radius:6px;padding:5px 9px;margin-top:5px;font-size:.76rem;line-height:1.5;">
            ❌ <strong>Rejected:</strong> <?php echo nl2br(sanitize($p['rejection_reason'])); ?>
            <span style="color:var(--text-muted,#6b7280);margin-left:4px;">— <a href="seller_product_form.php?id=<?php echo $p['id']; ?>" style="color:var(--primary,#0f766e);font-weight:700;">Edit &amp; Resubmit →</a></span>
        </div>
        <?php elseif ($p['status'] === 'pending_approval'): ?>
        <div style="font-size:.74rem;color:#b45309;margin-top:3px;">⏳ Awaiting admin review</div>
        <?php endif; ?>
    </div>
    <span class="sd-badge" style="background:<?php echo mp_product_status_bg($p['status']); ?>;color:<?php echo mp_product_status_color($p['status']); ?>;">
        <?php echo mp_product_status_label($p['status']); ?>
    </span>
    <div style="display:flex;gap:5px;">
        <a href="seller_product_form.php?id=<?php echo $p['id']; ?>" class="button button-secondary button-small">Edit</a>
        <?php if ($p['status']==='approved'): ?>
        <a href="seller_boost.php?type=featured_product&product_id=<?php echo $p['id']; ?>" class="button button-small" style="background:#f59e0b;color:#fff;border-color:transparent;" title="Boost this product">⚡</a>
        <?php endif; ?>
        <?php if ($p['status']==='approved'): ?>
        <form method="post" style="margin:0;"><?php echo csrf_field(); ?><input type="hidden" name="form" value="product_action"><input type="hidden" name="product_id" value="<?php echo $p['id']; ?>"><input type="hidden" name="action" value="archive"><button type="submit" class="button button-small" style="background:#f59e0b;color:#fff;border-color:transparent;" onclick="return confirm('Archive?');">Archive</button></form>
        <?php elseif ($p['status']==='archived'): ?>
        <form method="post" style="margin:0;"><?php echo csrf_field(); ?><input type="hidden" name="form" value="product_action"><input type="hidden" name="product_id" value="<?php echo $p['id']; ?>"><input type="hidden" name="action" value="reactivate"><button type="submit" class="button button-primary button-small">Reactivate</button></form>
        <?php endif; ?>
        <form method="post" style="margin:0;"><?php echo csrf_field(); ?><input type="hidden" name="form" value="product_action"><input type="hidden" name="product_id" value="<?php echo $p['id']; ?>"><input type="hidden" name="action" value="delete"><button type="submit" class="button button-small" style="background:#ef4444;color:#fff;border-color:transparent;" onclick="return confirm('Delete permanently?');">&#10007;</button></form>
    </div>
</div>
<?php endforeach; else: ?>
<div style="text-align:center;padding:40px;color:var(--text-muted,#6b7280);"><p><?php echo ($productStatusFilter !== 'all' || $productQ !== '') ? 'No products match this filter.' : 'No products yet. <a href="seller_product_form.php" style="color:var(--primary,#0f766e);">Add your first product →</a> or <a href="browse_master_catalog.php" style="color:var(--primary,#0f766e);">browse the catalog →</a>'; ?></p></div>
<?php endif; ?>

<?php elseif ($tab === 'orders' && $shop): ?>
<?php
// Storehouse info for any markets this shop's orders reference — keyed by
// each order's own snapshotted market_id, not the shop's current one.
$sdOrderMarketIds = array_filter(array_unique(array_column($orders, 'market_id')));
$sdMarketsById = [];
if ($sdOrderMarketIds) {
    $sdMktStmt = $pdo->query('SELECT * FROM markets WHERE id IN (' . implode(',', array_map('intval', $sdOrderMarketIds)) . ')');
    foreach ($sdMktStmt->fetchAll() as $mk) { $sdMarketsById[$mk['id']] = $mk; }
}
?>
<form method="get" class="sd-filters">
    <input type="hidden" name="tab" value="orders">
    <select name="ostatus" onchange="this.form.submit()">
        <option value="all" <?php echo $orderStatusFilter==='all'?'selected':''; ?>>All Statuses</option>
        <?php foreach ($validOrderStatuses as $vs): ?>
        <option value="<?php echo $vs; ?>" <?php echo $orderStatusFilter===$vs?'selected':''; ?>><?php echo mp_order_status_label($vs); ?></option>
        <?php endforeach; ?>
    </select>
    <input type="text" name="oq" value="<?php echo sanitize($orderQ); ?>" placeholder="Search customer name or order #…">
    <select name="osort" onchange="this.form.submit()">
        <option value="newest" <?php echo $orderSort==='newest'?'selected':''; ?>>Newest First</option>
        <option value="oldest" <?php echo $orderSort==='oldest'?'selected':''; ?>>Oldest First</option>
        <option value="amt_high" <?php echo $orderSort==='amt_high'?'selected':''; ?>>Highest Amount</option>
        <option value="amt_low" <?php echo $orderSort==='amt_low'?'selected':''; ?>>Lowest Amount</option>
    </select>
    <button type="submit" class="button button-secondary button-small">Filter</button>
    <?php if ($orderStatusFilter !== 'all' || $orderQ !== '' || $orderSort !== 'newest'): ?><a href="?tab=orders" class="button button-secondary button-small">Clear</a><?php endif; ?>
</form>
<?php if ($orders): foreach ($orders as $order):
    $oItems = $pdo->prepare('SELECT product_name, quantity, subtotal FROM mp_order_items WHERE order_id=?');
    $oItems->execute([$order['id']]);
    $oItems = $oItems->fetchAll();
    $oStockIssues = $pdo->prepare('SELECT product_name, requested_qty FROM mp_order_stock_issues WHERE order_id=?');
    $oStockIssues->execute([$order['id']]);
    $oStockIssues = $oStockIssues->fetchAll();
    $oDispute = null;
    if ($order['delivery_request_id']) {
        $oDisputeStmt = $pdo->prepare("SELECT status, dispute_type FROM delivery_disputes WHERE delivery_request_id=? AND status IN('open','investigating') ORDER BY created_at DESC LIMIT 1");
        $oDisputeStmt->execute([$order['delivery_request_id']]);
        $oDispute = $oDisputeStmt->fetch();
    }
?>
<div class="sd-ord-card" style="border-left:4px solid <?php echo mp_order_status_color($order['status']); ?>;">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:8px;flex-wrap:wrap;gap:6px;">
        <div>
            <div style="font-weight:800;">Order #<?php echo $order['id']; ?></div>
            <div style="font-size:.76rem;color:var(--text-muted,#6b7280);">
                <?php echo sanitize($order['customer_name']); ?> &nbsp;·&nbsp; <?php echo time_ago($order['created_at']); ?>
                &nbsp;·&nbsp; 📍 <?php echo sanitize(mb_substr($order['delivery_address']??'',0,40)); ?>
            </div>
        </div>
        <span class="sd-badge" style="background:<?php echo mp_order_status_bg($order['status']); ?>;color:<?php echo mp_order_status_color($order['status']); ?>;"><?php echo mp_order_status_label($order['status']); ?></span>
    </div>
    <?php foreach ($oItems as $oi): ?>
    <div style="font-size:.82rem;padding:2px 0;">• <?php echo sanitize($oi['product_name']); ?> ×<?php echo $oi['quantity']; ?> — GH&#8373; <?php echo number_format((float)$oi['subtotal'],2); ?></div>
    <?php endforeach; ?>
    <?php foreach ($oStockIssues as $si): ?>
    <div style="font-size:.78rem;padding:3px 6px;margin-top:4px;background:#fef3c7;border-radius:6px;color:#92400e;">⚠️ Also requested: <?php echo sanitize($si['product_name']); ?> ×<?php echo $si['requested_qty']; ?> — sold out at checkout, excluded &amp; not charged</div>
    <?php endforeach; ?>
    <?php if ($oDispute): ?>
    <div style="font-size:.78rem;padding:6px 8px;margin-top:6px;background:#fee2e2;border-radius:6px;color:#c0392b;">
        🚩 Buyer filed a complaint (<?php echo sanitize(ucfirst(str_replace('_',' ',$oDispute['dispute_type']))); ?>) — <?php echo $oDispute['status'] === 'investigating' ? 'under admin review' : 'awaiting admin review'; ?>. Payout release is paused.
    </div>
    <?php endif; ?>
    <?php $sdOrderMarket = $order['market_id'] ? ($sdMarketsById[$order['market_id']] ?? null) : null; ?>
    <?php if ($sdOrderMarket && in_array($order['status'], ['pending','confirmed','processing'], true)): ?>
    <div style="font-size:.78rem;padding:6px 8px;margin-top:6px;background:#e0f2fe;border-radius:6px;color:#075985;">
        🏬 <strong><?php echo sanitize($sdOrderMarket['name']); ?></strong> order — bring this to the storehouse<?php if ($sdOrderMarket['storehouse_location']): ?>: <?php echo sanitize($sdOrderMarket['storehouse_location']); ?><?php endif; ?> once you accept it. A market manager handles handoff from there — no delivery request needed.
    </div>
    <?php endif; ?>
    <?php if (($order['delivery_mode'] ?? 'platform') === 'self_arranged'): ?>
    <div style="font-size:.78rem;padding:6px 8px;margin-top:6px;background:#e0f2fe;border-radius:6px;color:#075985;">
        🤝 Buyer chose to arrange their own pickup — no delivery request will be created. Coordinate handoff directly with them.
    </div>
    <?php elseif ($order['status'] === 'ready_for_delivery' && !$order['delivery_request_id']): ?>
    <div style="font-size:.78rem;padding:6px 8px;margin-top:6px;background:#fee2e2;border-radius:6px;color:#c0392b;display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;">
        <span>⚠️ Delivery request failed to create.</span>
        <form method="post" style="margin:0;">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="form" value="retry_delivery">
            <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
            <button type="submit" class="button button-small" style="background:#c0392b;color:#fff;border-color:transparent;">Retry Delivery Request</button>
        </form>
    </div>
    <?php endif; ?>
    <div style="display:flex;align-items:center;justify-content:space-between;margin-top:10px;flex-wrap:wrap;gap:8px;">
        <div style="font-weight:900;color:var(--primary,#0f766e);">Total: GH&#8373; <?php echo number_format((float)$order['total_amount'],2); ?></div>
        <?php
        $orderStatusMap = [
            'pending'    => ['processing','cancelled'],
            'confirmed'  => ['processing','cancelled'],
            'processing' => $sdOrderMarket ? ['cancelled'] : ['ready_for_delivery','cancelled'],
        ];
        ?>
        <?php if (isset($orderStatusMap[$order['status']])): ?>
        <div style="display:flex;gap:6px;flex-wrap:wrap;">
            <?php foreach ($orderStatusMap[$order['status']] as $ns):
                if ($ns === 'cancelled' && $order['payment_status'] === 'paid') continue; // paid orders: admin-only cancellation
                $nsColors=['confirmed'=>'#3b82f6','processing'=>'#8b5cf6','ready_for_delivery'=>'#f97316','cancelled'=>'#ef4444'];
                $nsLabels=['confirmed'=>'Confirm','processing'=>($order['status']==='pending'?'Accept Order':'Start Processing'),'ready_for_delivery'=>'Mark Ready for Delivery','cancelled'=>'Cancel'];
            ?>
            <form method="post" style="margin:0;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="form" value="order_status">
                <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                <input type="hidden" name="new_status" value="<?php echo $ns; ?>">
                <button type="submit" class="button button-small" style="background:<?php echo $nsColors[$ns]; ?>;color:#fff;border-color:transparent;"><?php echo $nsLabels[$ns]; ?></button>
            </form>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; else: ?>
<div style="text-align:center;padding:40px;color:var(--text-muted,#6b7280);"><?php echo ($orderStatusFilter !== 'all' || $orderQ !== '') ? 'No orders match this filter.' : 'No orders yet.'; ?></div>
<?php endif; ?>

<?php elseif ($tab === 'quotes' && $shop): ?>
<p style="margin:0 0 12px;font-size:.78rem;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted,#6b7280);"><?php echo count($quoteRequests); ?> Quote Requests</p>
<?php if (!$quoteRequests): ?>
<div style="text-align:center;padding:40px;color:var(--text-muted,#6b7280);">No quote requests yet. Buyers can send you a shopping list from your shop page.</div>
<?php else: foreach ($quoteRequests as $qr):
    $qrItemsList = $quoteRequestItems[$qr['id']] ?? [];
    $qrStatusColors = ['pending'=>'#f59e0b','quoted'=>'#3b82f6','declined'=>'#ef4444','cancelled'=>'#6b7280','expired'=>'#6b7280','paid'=>'#10b981'];
    $qrColor = $qrStatusColors[$qr['status']] ?? '#6b7280';
?>
<div class="sd-ord-card" style="border-left:4px solid <?php echo $qrColor; ?>;">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:8px;flex-wrap:wrap;gap:6px;">
        <div>
            <div style="font-weight:800;">Quote Request #<?php echo $qr['id']; ?></div>
            <div style="font-size:.76rem;color:var(--text-muted,#6b7280);"><?php echo sanitize($qr['customer_name']); ?> &nbsp;·&nbsp; <?php echo time_ago($qr['created_at']); ?></div>
        </div>
        <span class="sd-badge" style="background:<?php echo $qrColor; ?>1a;color:<?php echo $qrColor; ?>;"><?php echo ucfirst($qr['status']); ?></span>
    </div>
    <?php if ($qr['buyer_notes']): ?><div style="font-size:.8rem;background:var(--surface-muted,#f8fafc);border-radius:8px;padding:6px 9px;margin-bottom:8px;">📝 <?php echo sanitize($qr['buyer_notes']); ?></div><?php endif; ?>

    <?php if ($qr['status'] === 'pending'): ?>
    <form method="post">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="form" value="quote_respond">
        <input type="hidden" name="quote_id" value="<?php echo $qr['id']; ?>">
        <?php foreach ($qrItemsList as $qi): ?>
        <div style="display:grid;grid-template-columns:2fr 1fr auto;gap:8px;align-items:center;padding:6px 0;border-bottom:1px solid var(--border);">
            <div>
                <strong style="font-size:.85rem;"><?php echo sanitize($qi['item_name']); ?></strong>
                <?php if ($qi['quantity_note']): ?><span style="color:var(--text-muted,#6b7280);font-size:.78rem;"> — <?php echo sanitize($qi['quantity_note']); ?></span><?php endif; ?>
                <?php if ($qi['buyer_note']): ?><div style="font-size:.74rem;color:var(--text-muted,#6b7280);">Note: <?php echo sanitize($qi['buyer_note']); ?></div><?php endif; ?>
            </div>
            <input type="number" step="0.01" min="0" name="price[<?php echo $qi['id']; ?>]" placeholder="GH₵ price" style="padding:6px 9px;border:1px solid var(--border);border-radius:8px;font-size:.82rem;">
            <label style="font-size:.74rem;display:flex;align-items:center;gap:4px;white-space:nowrap;"><input type="checkbox" name="unavailable[]" value="<?php echo $qi['id']; ?>"> Unavailable</label>
        </div>
        <?php endforeach; ?>
        <div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap;">
            <button type="submit" class="button button-primary button-small">Send Quote</button>
        </div>
    </form>
    <form method="post" style="margin-top:6px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="form" value="quote_decline">
        <input type="hidden" name="quote_id" value="<?php echo $qr['id']; ?>">
        <input type="text" name="decline_reason" placeholder="Reason (optional)" style="flex:1;min-width:160px;padding:6px 9px;border:1px solid var(--border);border-radius:8px;font-size:.82rem;">
        <button type="submit" class="button button-secondary button-small" style="color:#b91c1c;">Decline Request</button>
    </form>
    <?php else: ?>
    <?php foreach ($qrItemsList as $qi): ?>
    <div style="font-size:.82rem;padding:2px 0;<?php echo !$qi['is_available'] ? 'opacity:.5;text-decoration:line-through;' : ''; ?>">
        • <?php echo sanitize($qi['item_name']); ?><?php if ($qi['quantity_note']): ?> — <?php echo sanitize($qi['quantity_note']); ?><?php endif; ?>
        <?php if ($qi['is_available'] && $qi['price'] !== null): ?> — GH&#8373; <?php echo number_format((float)$qi['price'],2); ?><?php elseif (!$qi['is_available']): ?> (unavailable)<?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php if ($qr['decline_reason']): ?><div style="font-size:.78rem;padding:6px 8px;margin-top:6px;background:#fee2e2;border-radius:6px;color:#c0392b;">Reason: <?php echo sanitize($qr['decline_reason']); ?></div><?php endif; ?>
    <?php if ($qr['total_amount'] !== null): ?><div style="font-weight:900;color:var(--primary,#0f766e);margin-top:8px;">Total: GH&#8373; <?php echo number_format((float)$qr['total_amount'],2); ?></div><?php endif; ?>
    <?php endif; ?>
</div>
<?php endforeach; endif; ?>

<?php elseif ($tab === 'analytics' && $shop): ?>
<?php $periodLabels = ['today'=>'Today','week'=>'7 Days','month'=>'This Month','year'=>'This Year','all'=>'All Time']; ?>
<div class="sd-an-seg">
    <?php foreach ($periodLabels as $pv=>$pl): ?>
    <a href="?tab=analytics&period=<?php echo $pv; ?>" class="<?php echo $period===$pv?'active':''; ?>"><?php echo $pl; ?></a>
    <?php endforeach; ?>
</div>
<div class="sd-an-stats">
    <div class="sd-an-tile"><strong>GH&#8373; <?php echo number_format($analytics['totalRevenue'],2); ?></strong><span>All-Time Revenue</span></div>
    <div class="sd-an-tile"><strong>GH&#8373; <?php echo number_format($analytics['monthRevenue'],2); ?></strong><span><?php echo $periodLabels[$period]; ?> Revenue</span></div>
    <div class="sd-an-tile"><strong><?php echo number_format($analytics['orderCount']); ?></strong><span>Paid Orders (<?php echo $periodLabels[$period]; ?>)</span></div>
    <div class="sd-an-tile"><strong>GH&#8373; <?php echo number_format($analytics['avgOrder'],2); ?></strong><span>Avg. Order Value</span></div>
    <div class="sd-an-tile"><strong><?php echo number_format($analytics['cancelledCount']); ?></strong><span>Cancelled/Refunded (<?php echo $periodLabels[$period]; ?>)</span></div>
</div>

<div class="sd-an-card">
    <p class="sd-an-title">Revenue — Last 30 Days</p>
    <div style="position:relative;height:220px;"><canvas id="sd-revenue-chart"></canvas></div>
</div>

<div class="sd-an-card">
    <p class="sd-an-title">Top Products (<?php echo $periodLabels[$period]; ?>)</p>
    <?php if (!$analytics['topProducts']): ?>
    <p class="meta">No sales in this period yet.</p>
    <?php else: ?>
    <?php foreach ($analytics['topProducts'] as $tp): $pct = $analytics['topProductsMax'] > 0 ? (float)$tp['revenue'] / $analytics['topProductsMax'] * 100 : 0; ?>
    <div class="sd-an-prod-row">
        <div style="display:flex;justify-content:space-between;font-size:.85rem;margin-bottom:4px;">
            <span style="font-weight:600;"><?php echo sanitize($tp['product_name']); ?></span>
            <span style="color:var(--text-muted,#6b7280);"><?php echo (int)$tp['units_sold']; ?> sold · GH&#8373; <?php echo number_format((float)$tp['revenue'],2); ?></span>
        </div>
        <div class="sd-an-bar-track"><div class="sd-an-bar-fill" style="width:<?php echo max(3,$pct); ?>%;"></div></div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<div class="sd-an-card">
    <p class="sd-an-title">Most Viewed Products (all-time)</p>
    <?php if (!$analytics['topViewedProducts']): ?>
    <p class="meta">No product views yet.</p>
    <?php else: ?>
    <?php foreach ($analytics['topViewedProducts'] as $vp): $pct = $analytics['topViewedProductsMax'] > 0 ? (float)$vp['view_count'] / $analytics['topViewedProductsMax'] * 100 : 0; ?>
    <div class="sd-an-prod-row">
        <div style="display:flex;justify-content:space-between;font-size:.85rem;margin-bottom:4px;">
            <span style="font-weight:600;"><?php echo sanitize($vp['name']); ?></span>
            <span style="color:var(--text-muted,#6b7280);">👁️ <?php echo number_format((int)$vp['view_count']); ?> view<?php echo (int)$vp['view_count'] !== 1 ? 's' : ''; ?></span>
        </div>
        <div class="sd-an-bar-track"><div class="sd-an-bar-fill" style="width:<?php echo max(3,$pct); ?>%;"></div></div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<style>
.sd-an-seg { display:inline-flex; flex-wrap:wrap; gap:2px; background:var(--surface-muted,#f1f5f9); border-radius:10px; padding:3px; margin-bottom:16px; }
.sd-an-seg a { padding:7px 14px; border-radius:8px; font-size:.8rem; font-weight:700; text-decoration:none; color:var(--text-muted,#6b7280); transition:background .15s, color .15s; white-space:nowrap; }
.sd-an-seg a.active { background:var(--surface,#fff); color:var(--primary,#0f766e); box-shadow:0 1px 4px rgba(0,0,0,.1); }
.sd-an-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:10px; margin-bottom:16px; }
.sd-an-tile  { background:var(--surface); border:1px solid var(--border); border-radius:14px; padding:14px; text-align:center; box-shadow:0 1px 3px rgba(0,0,0,.04); transition:box-shadow .15s, transform .15s; }
.sd-an-tile:hover { box-shadow:0 10px 22px -12px rgba(0,0,0,.16); transform:translateY(-1px); }
.sd-an-tile strong { display:block; font-size:1.15rem; font-weight:900; color:var(--primary,#0f766e); }
.sd-an-tile span   { font-size:.72rem; color:var(--text-muted,#6b7280); }
.sd-an-card  { background:var(--surface); border:1px solid var(--border); border-radius:16px; padding:16px; margin-bottom:14px; box-shadow:0 1px 3px rgba(0,0,0,.04); }
.sd-an-title { font-size:.75rem; font-weight:800; text-transform:uppercase; letter-spacing:.07em; color:var(--text-muted,#6b7280); margin:0 0 12px; }
.sd-an-prod-row { margin-bottom:12px; }
.sd-an-prod-row:last-child { margin-bottom:0; }
.sd-an-bar-track { background:var(--surface-muted,#f1f5f9); border-radius:6px; height:8px; overflow:hidden; }
.sd-an-bar-fill  { background:var(--primary,#0f766e); height:100%; border-radius:6px; }
</style>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function () {
    var ctx = document.getElementById('sd-revenue-chart');
    if (!ctx) return;
    var style = getComputedStyle(document.documentElement);
    var primary = style.getPropertyValue('--primary').trim() || '#0f766e';
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($analytics['dailyLabels']); ?>,
            datasets: [{
                label: 'Revenue (GHS)',
                data: <?php echo json_encode($analytics['dailyRevenue']); ?>,
                backgroundColor: primary,
                borderRadius: 4,
                maxBarThickness: 18
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { grid: { display: false }, ticks: { maxTicksLimit: 8 } },
                y: { beginAtZero: true, grid: { color: 'rgba(128,128,128,.15)' } }
            }
        }
    });
})();
</script>

<?php elseif ($tab === 'wallet' && $shop): ?>

<div class="sdw-card">
    <div class="sdw-card-shine"></div>
    <div class="sdw-card-row">
        <span class="sdw-card-eyebrow">Available Balance</span>
        <span class="sdw-card-chip">GHS</span>
    </div>
    <div class="sdw-card-amount">GH&#8373; <?php echo number_format((float)$shop['available_balance'],2); ?></div>
    <div class="sdw-card-sub">
        <span class="sdw-dot"></span>
        GH&#8373; <?php echo number_format((float)$shop['pending_balance'],2); ?> pending release
    </div>
</div>
<p class="sdw-hint">💡 Funds move from Pending to Available <?php echo $confirmDaysDisplay; ?> day(s) after an order is marked delivered.</p>
<?php if (!empty($shop['fast_payout_enabled'])): ?>
<p class="sdw-hint">⚡ Fast Payout is on for this shop — eligible orders settle straight to your bank once cleared and won't pass through Available Balance. <a href="seller_payout_accounts.php">Manage</a></p>
<?php endif; ?>

<?php if ($pendingReleases): ?>
<div class="sdw-panel">
    <p class="sdw-panel-title">⏳ Pending Releases <span class="sdw-count"><?php echo count($pendingReleases); ?></span></p>
    <?php foreach ($pendingReleases as $pr):
        $progressPct = null;
        if ($pr['has_open_dispute']) {
            $releaseLabel = 'Paused — complaint under review';
            $releaseColor = '#c0392b';
        } elseif ($pr['status'] !== 'delivered' || !$pr['payout_release_at']) {
            $releaseLabel = 'Awaiting delivery confirmation';
            $releaseColor = '#6b7280';
        } else {
            $secsLeft = strtotime($pr['payout_release_at']) - time();
            $totalSecs = max(1, $confirmDaysDisplay * 86400);
            $progressPct = max(0, min(100, (($totalSecs - $secsLeft) / $totalSecs) * 100));
            if ($secsLeft <= 0) { $releaseLabel = 'Releasing soon'; $releaseColor = '#2f8f5b'; }
            else {
                $daysLeft = max(1, (int)ceil($secsLeft / 86400));
                $releaseLabel = 'Releases in ' . $daysLeft . ' day' . ($daysLeft === 1 ? '' : 's');
                $releaseColor = '#b45309';
            }
        }
    ?>
    <div class="sdw-release">
        <div class="sdw-release-top">
            <div>
                <span class="sdw-release-order">Order #<?php echo $pr['id']; ?></span>
                <span class="sdw-release-status" style="color:<?php echo $releaseColor; ?>;"><?php echo sanitize($releaseLabel); ?></span>
            </div>
            <strong class="sdw-release-amt">GH&#8373; <?php echo number_format((float)$pr['net_amount'],2); ?></strong>
        </div>
        <?php if ($progressPct !== null): ?>
        <div class="sdw-progress-track"><div class="sdw-progress-fill" style="width:<?php echo $progressPct; ?>%;background:<?php echo $releaseColor; ?>;"></div></div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="sdw-panel">
    <div style="display:flex;justify-content:space-between;align-items:center;">
        <p class="sdw-panel-title" style="margin:0;">💸 Request Withdrawal</p>
        <a href="seller_payout_accounts.php" style="font-size:.78rem;font-weight:700;">⚙️ Payout Accounts</a>
    </div>
    <?php if ($payoutError): ?><div class="alert alert-error"><?php echo sanitize($payoutError); ?></div><?php endif; ?>
    <?php
    $payoutAccountsStmt = $pdo->prepare('SELECT * FROM mp_payout_accounts WHERE shop_id=? ORDER BY is_default DESC');
    $payoutAccountsStmt->execute([$shop['id']]);
    $sellerPayoutAccounts = $payoutAccountsStmt->fetchAll();
    ?>
    <?php if (!$sellerPayoutAccounts): ?>
    <div class="sdw-empty">
        <span class="sdw-empty-icon">⚙️</span>
        <p>Set up a MoMo or bank account to receive withdrawals.</p>
        <a href="seller_payout_accounts.php" class="button button-primary button-small">Set Up Payout Account →</a>
    </div>
    <?php elseif ($hasPendingPayoutDisplay): ?>
    <div class="sdw-notice">⏳ You have a pending withdrawal request. Please wait for it to be processed before requesting another.</div>
    <?php elseif ((float)$shop['available_balance'] < 1): ?>
    <div class="sdw-empty">
        <span class="sdw-empty-icon">💳</span>
        <p>No funds available to withdraw yet.</p>
    </div>
    <?php else: ?>
    <form method="post" action="seller_dashboard.php?tab=wallet">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="form" value="request_payout">
        <div class="sdw-input-group">
            <label for="amount">Amount (GH&#8373;)</label>
            <div class="sdw-input-wrap">
                <input type="number" id="amount" name="amount" step="0.01" min="1" max="<?php echo (float)$shop['available_balance']; ?>" value="<?php echo sanitize($_POST['amount'] ?? number_format((float)$shop['available_balance'],2,'.','')); ?>" required>
                <button type="button" class="sdw-max-btn" onclick="document.getElementById('amount').value='<?php echo number_format((float)$shop['available_balance'],2,'.',''); ?>'">MAX</button>
            </div>
            <p class="sdw-input-hint">Max GH&#8373; <?php echo number_format((float)$shop['available_balance'],2); ?> available.</p>
        </div>
        <div class="sdw-input-group">
            <label>Pay To</label>
            <?php foreach ($sellerPayoutAccounts as $pa): ?>
            <label style="display:flex;align-items:center;gap:8px;font-weight:500;font-size:.86rem;padding:6px 0;cursor:pointer;">
                <input type="radio" name="payout_account_id" value="<?php echo $pa['id']; ?>" style="width:auto;" <?php echo ($_POST['payout_account_id'] ?? ($pa['is_default'] ? $pa['id'] : null)) == $pa['id'] ? 'checked' : ''; ?> required>
                <?php echo $pa['method'] === 'momo' ? '📱' : '🏦'; ?> <?php echo sanitize($pa['bank_name']); ?> — •••• <?php echo sanitize(substr($pa['account_number'], -4)); ?>
            </label>
            <?php endforeach; ?>
            <p class="sdw-input-hint"><a href="seller_payout_accounts.php">Manage payout accounts →</a></p>
        </div>
        <button type="submit" class="sdw-submit-btn">Request Withdrawal →</button>
    </form>
    <?php endif; ?>
</div>

<div class="sdw-panel">
    <p class="sdw-panel-title">📋 Withdrawal History</p>
    <div class="sdw-seg">
        <?php foreach (['all'=>'All','pending'=>'Pending','processing'=>'Processing','paid'=>'Paid','failed'=>'Failed','rejected'=>'Rejected'] as $wv=>$wl): ?>
        <a href="?tab=wallet&wstatus=<?php echo $wv; ?>" class="<?php echo $withdrawalStatusFilter===$wv?'active':''; ?>"><?php echo $wl; ?></a>
        <?php endforeach; ?>
    </div>
    <?php if (!$payoutRequests): ?>
    <div class="sdw-empty"><span class="sdw-empty-icon">📭</span><p>No withdrawal requests match this filter.</p></div>
    <?php else: ?>
    <?php $poIcons = ['pending'=>'⏳','approved'=>'✅','processing'=>'🔄','paid'=>'💵','rejected'=>'❌','failed'=>'⚠️']; ?>
    <?php $poColors = ['pending'=>['#fef3c7','#b45309'],'approved'=>['#dbeafe','#1d4ed8'],'processing'=>['#dbeafe','#1d4ed8'],'paid'=>['#d1fae5','#065f46'],'rejected'=>['#fee2e2','#c0392b'],'failed'=>['#fee2e2','#c0392b']]; ?>
    <?php foreach ($payoutRequests as $po): [$bg,$col] = $poColors[$po['status']] ?? ['#f3f4f6','#6b7280']; ?>
    <div class="sdw-row" style="border-left-color:<?php echo $col; ?>;">
        <div class="sdw-row-icon" style="background:<?php echo $bg; ?>;"><?php echo $poIcons[$po['status']] ?? '•'; ?></div>
        <div class="sdw-row-body">
            <div class="sdw-row-title">GH&#8373; <?php echo number_format((float)$po['amount'],2); ?></div>
            <div class="sdw-row-meta"><?php echo sanitize($po['bank_name'] ?: 'Mobile Money'); ?> — •••• <?php echo sanitize(substr((string)$po['account_number'], -4)); ?> &nbsp;·&nbsp; <?php echo time_ago($po['created_at']); ?></div>
            <?php if ($po['status']==='rejected' && $po['admin_notes']): ?>
            <div class="sdw-row-reason">Reason: <?php echo sanitize($po['admin_notes']); ?></div>
            <?php endif; ?>
            <?php if ($po['status']==='failed' && $po['failure_reason']): ?>
            <div class="sdw-row-reason">Issue: <?php echo sanitize($po['failure_reason']); ?></div>
            <?php endif; ?>
        </div>
        <span class="sdw-badge" style="background:<?php echo $bg; ?>;color:<?php echo $col; ?>;"><?php echo ucfirst($po['status']); ?></span>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<div class="sdw-panel">
    <p class="sdw-panel-title">📜 Recent Wallet Activity</p>
    <div class="sdw-seg">
        <?php foreach (['all'=>'All','sale_pending'=>'Sales','released_to_available'=>'Released','withdrawal'=>'Withdrawals','reversal'=>'Reversals'] as $lv=>$ll): ?>
        <a href="?tab=wallet&ltype=<?php echo $lv; ?>" class="<?php echo $ledgerTypeFilter===$lv?'active':''; ?>"><?php echo $ll; ?></a>
        <?php endforeach; ?>
    </div>
    <?php if (!$walletLedger): ?>
    <div class="sdw-empty"><span class="sdw-empty-icon">📜</span><p>No wallet activity matches this filter.</p></div>
    <?php else: ?>
    <?php
    $ledgerMeta = [
        'sale_pending'          => ['icon'=>'💰','label'=>'Sale credited (pending)', 'sign'=>1,  'bg'=>'#e4f4ea'],
        'released_to_available' => ['icon'=>'🔓','label'=>'Released to available',  'sign'=>0,  'bg'=>'#f1f5f9'],
        'withdrawal'            => ['icon'=>'💸','label'=>'Withdrawal approved',     'sign'=>-1, 'bg'=>'#fee2e2'],
        'reversal'              => ['icon'=>'↩️','label'=>'Refund reversal',         'sign'=>-1, 'bg'=>'#fee2e2'],
    ];
    ?>
    <?php foreach ($walletLedger as $tx):
        $meta = $ledgerMeta[$tx['type']] ?? ['icon'=>'•','label'=>ucfirst(str_replace('_',' ',$tx['type'])),'sign'=>0,'bg'=>'#f1f5f9'];
        $amt  = abs((float)$tx['amount']);
        if ($meta['sign'] > 0)      { $color = '#065f46'; $prefix = '+'; $accent = '#2f8f5b'; }
        elseif ($meta['sign'] < 0)  { $color = '#c0392b'; $prefix = '−'; $accent = '#c0392b'; }
        else                        { $color = 'var(--text-muted,#6b7280)'; $prefix = ''; $accent = '#94a3b8'; }
    ?>
    <div class="sdw-row" style="border-left-color:<?php echo $accent; ?>;">
        <div class="sdw-row-icon" style="background:<?php echo $meta['bg']; ?>;"><?php echo $meta['icon']; ?></div>
        <div class="sdw-row-body">
            <div class="sdw-row-title"><?php echo sanitize($meta['label']); ?></div>
            <div class="sdw-row-meta">
                <?php if ($tx['order_id']): ?>Order #<?php echo $tx['order_id']; ?> &nbsp;·&nbsp; <?php endif; ?>
                <?php echo time_ago($tx['created_at']); ?>
            </div>
        </div>
        <strong class="sdw-row-amt" style="color:<?php echo $color; ?>;"><?php echo $prefix; ?>GH&#8373; <?php echo number_format($amt,2); ?></strong>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<style>
/* ── Wallet card ── */
.sdw-card { position:relative; overflow:hidden; border-radius:20px; padding:22px 24px; margin-bottom:10px;
    background:linear-gradient(135deg, var(--primary,#2f8f5b) 0%, var(--primary-dark,#246b45) 100%);
    color:#fff; box-shadow:0 12px 28px -12px rgba(47,143,91,.45); }
.sdw-card-shine { position:absolute; top:-70px; right:-40px; width:220px; height:220px; border-radius:50%;
    background:radial-gradient(circle, rgba(255,255,255,.20), transparent 70%); pointer-events:none; }
.sdw-card-row { display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; position:relative; }
.sdw-card-eyebrow { font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.08em; opacity:.85; }
.sdw-card-chip { font-size:.65rem; font-weight:800; background:rgba(255,255,255,.2); padding:3px 10px; border-radius:20px; letter-spacing:.05em; }
.sdw-card-amount { font-size:2.15rem; font-weight:900; letter-spacing:-.02em; position:relative; margin-bottom:16px; }
.sdw-card-sub { display:flex; align-items:center; gap:8px; font-size:.83rem; font-weight:600; opacity:.95; position:relative; padding-top:13px; border-top:1px solid rgba(255,255,255,.22); }
.sdw-dot { width:7px; height:7px; border-radius:50%; background:#fbbf24; flex-shrink:0; box-shadow:0 0 0 3px rgba(251,191,36,.28); }
.sdw-hint { font-size:.78rem; color:var(--text-muted,#6b7280); text-align:center; margin:0 0 18px; }

/* ── Panels ── */
.sdw-panel { background:var(--surface); border:1px solid var(--border); border-radius:16px; padding:18px; margin-bottom:14px; box-shadow:0 1px 3px rgba(0,0,0,.03); }
.sdw-panel-title { font-size:.74rem; font-weight:800; text-transform:uppercase; letter-spacing:.07em; color:var(--text-muted,#6b7280); margin:0 0 14px; display:flex; align-items:center; gap:7px; }
.sdw-count { background:var(--primary-soft,#e4f4ea); color:var(--primary,#2f8f5b); font-size:.68rem; font-weight:800; padding:1px 8px; border-radius:20px; }

/* ── Segmented filter control ── */
.sdw-seg { display:inline-flex; flex-wrap:wrap; gap:2px; background:var(--surface-muted,#f1f5f9); border-radius:10px; padding:3px; margin-bottom:14px; }
.sdw-seg a { padding:6px 13px; border-radius:8px; font-size:.76rem; font-weight:700; text-decoration:none; color:var(--text-muted,#6b7280); transition:background .15s, color .15s; white-space:nowrap; }
.sdw-seg a.active { background:var(--surface,#fff); color:var(--primary,#2f8f5b); box-shadow:0 1px 4px rgba(0,0,0,.1); }

/* ── Pending release rows with progress ── */
.sdw-release { padding:10px 0; border-bottom:1px solid var(--border); }
.sdw-release:last-child { border-bottom:none; padding-bottom:0; }
.sdw-release-top { display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:7px; }
.sdw-release-order { font-weight:700; font-size:.88rem; }
.sdw-release-status { font-size:.76rem; margin-left:7px; font-weight:600; }
.sdw-release-amt { color:var(--primary,#2f8f5b); }
.sdw-progress-track { background:var(--surface-muted,#f1f5f9); border-radius:6px; height:6px; overflow:hidden; }
.sdw-progress-fill { height:100%; border-radius:6px; transition:width .3s; }

/* ── Notice / empty states ── */
.sdw-notice { background:#fef3c7; border:1px solid #f59e0b; border-radius:12px; padding:14px; color:#92400e; font-size:.86rem; }
.sdw-empty { text-align:center; padding:28px 10px; color:var(--text-muted,#6b7280); }
.sdw-empty-icon { display:block; font-size:1.8rem; margin-bottom:8px; opacity:.6; }
.sdw-empty p { margin:0; font-size:.86rem; }

/* ── Withdrawal form ── */
.sdw-input-group { margin-bottom:14px; }
.sdw-input-group label { font-weight:600; font-size:.86rem; display:block; margin-bottom:4px; }
.sdw-input-wrap { position:relative; display:flex; }
.sdw-input-wrap input { flex:1; padding-right:56px; }
.sdw-max-btn { position:absolute; right:6px; top:50%; transform:translateY(-50%); background:var(--primary-soft,#e4f4ea); color:var(--primary,#2f8f5b); border:none; border-radius:7px; font-size:.68rem; font-weight:800; padding:5px 9px; cursor:pointer; letter-spacing:.03em; }
.sdw-max-btn:hover { background:var(--primary,#2f8f5b); color:#fff; }
.sdw-input-hint { font-size:.74rem; color:var(--text-muted,#6b7280); margin:4px 0 0; }
.sdw-submit-btn { width:100%; padding:13px; border:none; border-radius:11px; background:linear-gradient(135deg, var(--primary,#2f8f5b), var(--primary-dark,#246b45)); color:#fff; font-weight:800; font-size:.92rem; cursor:pointer; box-shadow:0 6px 16px -6px rgba(47,143,91,.5); transition:transform .12s, box-shadow .12s; }
.sdw-submit-btn:hover { transform:translateY(-1px); box-shadow:0 8px 20px -6px rgba(47,143,91,.55); }
.sdw-submit-btn:active { transform:translateY(0); }

/* ── Generic list row (history + ledger) ── */
.sdw-row { display:flex; align-items:center; gap:12px; padding:11px 4px 11px 12px; border-left:3px solid transparent; border-radius:8px; margin-bottom:2px; transition:background .12s; }
.sdw-row:hover { background:var(--surface-muted,#f8fafc); }
.sdw-row-icon { width:34px; height:34px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:1rem; flex-shrink:0; }
.sdw-row-body { flex:1; min-width:0; }
.sdw-row-title { font-weight:700; font-size:.87rem; }
.sdw-row-meta { font-size:.74rem; color:var(--text-muted,#6b7280); margin-top:1px; }
.sdw-row-reason { font-size:.75rem; color:#c0392b; margin-top:3px; }
.sdw-row-amt { white-space:nowrap; font-size:.9rem; }
.sdw-badge { display:inline-block; padding:3px 10px; border-radius:20px; font-size:.68rem; font-weight:800; white-space:nowrap; }

@media(max-width:420px){ .sdw-card-amount { font-size:1.8rem; } }
</style>

<?php elseif ($tab === 'subscription' && $shop):
    $curSub    = get_shop_active_subscription((int)$shop['id']);
    $listCheck = mp_shop_can_list_product((int)$shop['id']);
    $subHistory = $pdo->prepare(
        "SELECT h.*, fp.name AS from_plan_name, tp.name AS to_plan_name
         FROM mp_subscription_history h
         LEFT JOIN mp_seller_subscription_plans fp ON h.from_plan_id = fp.id
         LEFT JOIN mp_seller_subscription_plans tp ON h.to_plan_id = tp.id
         WHERE h.shop_id = ? ORDER BY h.created_at DESC LIMIT 20"
    );
    $subHistory->execute([$shop['id']]);
    $subHistory = $subHistory->fetchAll();
    $subPayments = $pdo->prepare(
        "SELECT amount, status, paid_at, reference_code FROM platform_payments
         WHERE user_id = ? AND payment_type = 'mp_subscription' ORDER BY created_at DESC LIMIT 20"
    );
    $subPayments->execute([$user['id']]);
    $subPayments = $subPayments->fetchAll();
    $historyEventLabels = ['purchased'=>'Purchased','upgraded'=>'Upgraded','downgraded'=>'Downgrade scheduled','renewed'=>'Renewed','cancelled'=>'Cancelled','expired'=>'Expired'];
?>

    <?php if (!$curSub): ?>
    <div class="sd-card">
        <strong>No active subscription.</strong>
        <p style="font-size:.86rem;color:var(--text-muted,#6b7280);margin:6px 0 12px;">
            <?php echo get_platform_setting('mp_subscription_enabled','0')==='1' ? 'You need an active subscription to list products.' : 'Subscriptions aren\'t required to list products right now, but you can subscribe for extra benefits.'; ?>
        </p>
        <a href="pay_mp_subscription.php" class="button button-primary">View Plans →</a>
    </div>
    <?php else: ?>
    <div class="sd-card">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px;">
            <div>
                <strong style="font-size:1.1rem;">⭐ <?php echo sanitize($curSub['plan_name']); ?></strong>
                <?php if ($curSub['badge_name']): ?><span style="font-size:.62rem;font-weight:800;background:<?php echo sanitize($curSub['badge_color'] ?: '#fef3c7'); ?>;color:#92400e;padding:2px 8px;border-radius:20px;margin-left:6px;"><?php echo sanitize($curSub['badge_name']); ?></span><?php endif; ?>
                <p style="font-size:.84rem;color:var(--text-muted,#6b7280);margin:4px 0 0;">
                    Active until <?php echo date('d M Y', strtotime($curSub['end_date'])); ?>
                    (<?php $daysLeft = max(0, (int)ceil((strtotime($curSub['end_date']) - strtotime(date('Y-m-d'))) / 86400)); echo $daysLeft; ?> day<?php echo $daysLeft===1?'':'s'; ?> remaining)
                </p>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <a href="pay_mp_subscription.php" class="button button-primary button-small">Renew / Change Plan</a>
                <form method="post" action="seller_dashboard.php?tab=subscription" onsubmit="return confirm('Cancel your subscription now? Your products stay saved but become hidden from customers immediately.');">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="form" value="cancel_subscription">
                    <input type="hidden" name="subscription_id" value="<?php echo (int)$curSub['id']; ?>">
                    <button type="submit" class="button button-secondary button-small" style="color:#c0392b;">Cancel</button>
                </form>
            </div>
        </div>

        <div style="margin-top:16px;">
            <div style="display:flex;justify-content:space-between;font-size:.8rem;color:var(--text-muted,#6b7280);margin-bottom:4px;">
                <span>Active listings used</span>
                <span><?php echo $listCheck['unlimited'] ? 'Unlimited' : $listCheck['used'] . ' / ' . $listCheck['limit']; ?></span>
            </div>
            <?php if (!$listCheck['unlimited']): $pct = $listCheck['limit'] > 0 ? min(100, round($listCheck['used'] / $listCheck['limit'] * 100)) : 100; ?>
            <div style="background:var(--surface-muted,#f1f5f9);border-radius:20px;height:8px;overflow:hidden;">
                <div style="background:<?php echo $pct>=100?'#c0392b':'var(--primary,#2f8f5b)'; ?>;height:100%;width:<?php echo $pct; ?>%;"></div>
            </div>
            <?php endif; ?>
        </div>

        <div style="display:flex;flex-wrap:wrap;gap:14px;margin-top:16px;font-size:.78rem;color:var(--text-muted,#6b7280);">
            <span>Max images: <?php echo $curSub['unlimited_images'] ? 'Unlimited' : (int)$curSub['max_images']; ?></span>
            <?php if ($curSub['featured_shop_included']): ?><span>✓ Featured shop</span><?php endif; ?>
            <?php if ((int)$curSub['featured_products_included'] > 0): ?><span>✓ <?php echo (int)$curSub['featured_products_included']; ?> featured products</span><?php endif; ?>
            <?php if ($curSub['analytics_access']): ?><span>✓ Analytics access</span><?php endif; ?>
            <?php if ($curSub['verification_included']): ?><span>✓ Verification included</span><?php endif; ?>
            <span>Support: <?php echo sanitize(ucfirst($curSub['support_level'])); ?></span>
        </div>
    </div>
    <?php endif; ?>

    <div class="sd-card">
        <p class="sd-card-title">Subscription History</p>
        <?php if (!$subHistory): ?>
        <p style="font-size:.84rem;color:var(--text-muted,#6b7280);">No subscription activity yet.</p>
        <?php else: ?>
        <?php foreach ($subHistory as $h): ?>
        <div class="sdw-row">
            <div class="sdw-row-body">
                <div class="sdw-row-title"><?php echo sanitize($historyEventLabels[$h['event']] ?? ucfirst($h['event'])); ?><?php if ($h['to_plan_name']): ?> — <?php echo sanitize($h['to_plan_name']); ?><?php endif; ?></div>
                <div class="sdw-row-meta"><?php echo date('d M Y, H:i', strtotime($h['created_at'])); ?><?php if ($h['notes']): ?> · <?php echo sanitize($h['notes']); ?><?php endif; ?></div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="sd-card">
        <p class="sd-card-title">Payment History</p>
        <?php if (!$subPayments): ?>
        <p style="font-size:.84rem;color:var(--text-muted,#6b7280);">No payments yet.</p>
        <?php else: ?>
        <?php foreach ($subPayments as $p): ?>
        <div class="sdw-row">
            <div class="sdw-row-body">
                <div class="sdw-row-title">GH&#8373; <?php echo number_format((float)$p['amount'],2); ?></div>
                <div class="sdw-row-meta">Ref <?php echo sanitize(strtoupper($p['reference_code'])); ?> · <?php echo $p['paid_at'] ? date('d M Y, H:i', strtotime($p['paid_at'])) : 'Not yet paid'; ?></div>
            </div>
            <span class="sdw-badge" style="background:<?php echo $p['status']==='paid'?'#d1fae5':'#fee2e2'; ?>;color:<?php echo $p['status']==='paid'?'#065f46':'#991b1b'; ?>;"><?php echo ucfirst($p['status']); ?></span>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

<?php elseif ($tab === 'setup'): ?>
<?php if ($shopError): ?><div class="alert alert-error"><?php echo sanitize($shopError); ?></div><?php endif; ?>
<form method="post" action="seller_dashboard.php?tab=setup" enctype="multipart/form-data">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="form" value="shop_settings">
    <input type="hidden" name="id" value="<?php echo $shop['id'] ?? 0; ?>">
    <div class="sd-set-section">
        <p class="sd-set-title"><?php echo $shop ? 'Shop Information — ' . sanitize($shop['shop_name']) : 'Create Your Shop'; ?></p>
        <div class="form-group">
            <label for="shop_name">Shop Name *</label>
            <input type="text" id="shop_name" name="shop_name" required value="<?php echo sanitize($_POST['shop_name'] ?? ($shop['shop_name']??'')); ?>">
        </div>
        <div class="form-group sd-desc-group">
            <label for="description">Shop Description</label>
            <textarea id="description" name="description" class="rich-editor" rows="10" placeholder="Tell customers about your shop…"><?php echo $_POST['description'] ?? ($shop['description']??''); ?></textarea>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div class="form-group">
                <label for="phone">Phone Number</label>
                <input type="tel" id="phone" name="phone" value="<?php echo sanitize($_POST['phone'] ?? ($shop['phone']??$user['phone']??'')); ?>">
            </div>
            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" value="<?php echo sanitize($_POST['email'] ?? ($shop['email']??$user['email']??'')); ?>">
            </div>
            <div class="form-group">
                <label for="town_id">Town</label>
                <?php $selShopTown = $_POST['town_id'] ?? ($shop['town_id'] ?? ''); ?>
                <select id="town_id" name="town_id">
                    <option value="">— Select town —</option>
                    <?php foreach (get_towns_grouped_by_district() as $district => $ts): ?>
                    <optgroup label="<?php echo sanitize($district); ?>">
                        <?php foreach ($ts as $t): ?>
                        <option value="<?php echo $t['id']; ?>" <?php echo $selShopTown==$t['id']?'selected':''; ?>><?php echo sanitize($t['name']); ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="region">Venue / Location</label>
                <input type="text" id="region" name="region" placeholder="e.g. Near Aburi Market" value="<?php echo sanitize($_POST['region'] ?? ($shop['region']??'')); ?>">
            </div>
            <div class="form-group">
                <label for="google_maps_link">Google Maps Pickup Link</label>
                <div style="display:flex;gap:8px;">
                    <input type="url" id="google_maps_link" name="google_maps_link" placeholder="https://maps.google.com/…" value="<?php echo sanitize($_POST['google_maps_link'] ?? ($shop['google_maps_link']??'')); ?>" style="flex:1;">
                    <button type="button" id="sd-get-maps-link" class="button button-secondary button-small" style="flex-shrink:0;">📍 Get Link</button>
                </div>
                <p class="meta" style="margin-top:4px;" id="sd-maps-link-status">Paste a Google Maps share link to your shop/pickup location, or tap "Get Link" to use your current location — this helps delivery riders find you when picking up orders.</p>
            </div>
        </div>
        <div class="form-group">
            <label for="logo">Shop Logo</label>
            <input type="file" id="logo" name="logo" accept="image/jpeg,image/png,image/webp">
            <?php if ($shop && $shop['logo_path']): ?><div style="margin-top:6px;"><img src="<?php echo sanitize($shop['logo_path']); ?>" style="height:50px;width:50px;object-fit:cover;border-radius:8px;border:1px solid var(--border);" alt="Current logo"></div><?php endif; ?>
        </div>
        <div class="form-group">
            <label for="banner">Shop Banner</label>
            <input type="file" id="banner" name="banner" accept="image/jpeg,image/png,image/webp">
            <p class="meta" style="margin-top:4px;">Wide cover image shown at the top of your shop page (e.g. 1200×300px). Optional — the banner area is hidden if you don't add one.</p>
            <?php if ($shop && $shop['banner_path']): ?><div style="margin-top:6px;"><img src="<?php echo sanitize($shop['banner_path']); ?>" style="height:70px;width:100%;max-width:280px;object-fit:cover;border-radius:8px;border:1px solid var(--border);" alt="Current banner"></div><?php endif; ?>
        </div>
    </div>
    <button type="submit" class="button button-primary" style="width:100%;padding:13px;"><?php echo $shop ? 'Save Shop Settings' : 'Create Shop'; ?></button>
</form>
<script>
(function () {
    var btn    = document.getElementById('sd-get-maps-link');
    var input  = document.getElementById('google_maps_link');
    var status = document.getElementById('sd-maps-link-status');
    if (!btn) return;
    var defaultStatus = status.textContent;
    btn.addEventListener('click', function () {
        if (!navigator.geolocation) {
            status.textContent = 'Geolocation is not supported by your browser.';
            return;
        }
        status.textContent = 'Locating…';
        navigator.geolocation.getCurrentPosition(function (position) {
            var lat      = position.coords.latitude;
            var lng      = position.coords.longitude;
            var accuracy = position.coords.accuracy; // metres (radius of uncertainty)
            input.value  = 'https://maps.google.com/?q=' + lat + ',' + lng;
            if (accuracy > 1000) {
                status.textContent = '⚠️ Low-confidence location (accurate to ~' + Math.round(accuracy / 1000) + 'km) — likely from your WiFi/IP, not GPS. Open the link to check the pin, or use a phone with GPS for a precise result.';
            } else if (accuracy > 100) {
                status.textContent = 'Location captured (accurate to ~' + Math.round(accuracy) + 'm) — please open the link to confirm the pin lands on your shop before saving.';
            } else {
                status.textContent = 'Location captured: ' + lat.toFixed(5) + ', ' + lng.toFixed(5) + '. Click "Save Shop Settings" to store it.';
            }
        }, function () {
            status.textContent = 'Unable to retrieve your location. Please allow location access and try again, or paste a link manually.';
        }, { enableHighAccuracy: true });
    });
})();
</script>

<?php elseif ($tab === 'verify' && $shop): ?>
<?php
$verStmt = $pdo->prepare('SELECT * FROM mp_shop_verifications WHERE shop_id=?');
$verStmt->execute([$shop['id']]);
$verification = $verStmt->fetch() ?: null;
?>
<?php if ($shop['verification_status'] === 'approved'): ?>
<div class="sd-status-banner" style="background:#d1fae5;border:1px solid #6ee7b7;">
    <span class="sd-status-icon" style="background:#fff;">✅</span>
    <div><strong>Verified Seller!</strong><br>Your shop has the Verified badge.</div>
</div>
<?php elseif ($verification && $verification['status'] === 'pending'): ?>
<div class="sd-status-banner" style="background:#fef3c7;border:1px solid #f59e0b;">
    <span class="sd-status-icon" style="background:#fff;">⏳</span>
    <div><strong>Verification under review.</strong><br>Admin will notify you once processed.</div>
</div>
<?php elseif ($verification && $verification['status'] === 'rejected'): ?>
<div class="sd-status-banner" style="background:#fee2e2;border:1px solid #fca5a5;">
    <span class="sd-status-icon" style="background:#fff;">❌</span>
    <div><strong>Rejected.</strong> <?php echo sanitize($verification['rejection_reason']??''); ?> — You can resubmit below.</div>
</div>
<?php endif; ?>
<?php if ($shop['verification_status'] !== 'approved' && (!$verification || $verification['status'] !== 'pending')): ?>
<form method="post" action="marketplace_ajax.php" enctype="multipart/form-data">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="action" value="submit_shop_verification">
    <div class="sd-set-section">
        <p class="sd-set-title">Submit Verification Documents</p>
        <div class="form-group">
            <label>Ghana Card Photo *</label>
            <input type="file" name="ghana_card" accept="image/jpeg,image/png,image/webp" required>
        </div>
        <div class="form-group">
            <label>Business Registration Certificate (optional)</label>
            <input type="file" name="business_reg" accept="image/jpeg,image/png,image/webp">
        </div>
    </div>
    <button type="submit" class="button button-primary">Submit for Verification</button>
</form>
<?php endif; ?>

<?php endif; ?>

</div><!-- /sd-shell -->

<script src="assets/js/rich-editor.js"></script>
<?php require_once __DIR__ . '/partials/bottom_nav.php'; ?>
</body>
</html>
