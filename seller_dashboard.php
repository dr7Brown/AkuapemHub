<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/marketplace_functions.php';

require_login();
$user  = current_user();
$flash = get_flash();
$shop  = get_shop_by_user((int)$user['id']);
$tab   = $_GET['tab'] ?? ($shop ? 'products' : 'setup');

// ── Handle shop setup / edit ──────────────────────────────────────────────
$shopError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'shop_settings') {
    csrf_check();
    $shopName   = trim($_POST['shop_name']    ?? '');
    $desc       = trim($_POST['description']  ?? '');
    $phone      = trim($_POST['phone']        ?? '');
    $email      = trim($_POST['email']        ?? '');
    $region     = trim($_POST['region']       ?? '');
    $mapsLink   = trim($_POST['google_maps_link'] ?? '');

    if ($shopName === '') $shopError = 'Shop name is required.';
    elseif (!$shop && strlen($shopName) < 3) $shopError = 'Shop name must be at least 3 characters.';
    elseif (!$shop && requires_verified_email('shop_create') && !is_email_verified()) {
        $shopError = 'Please verify your email address before creating a shop.';
    } elseif ($mapsLink !== '' && !filter_var($mapsLink, FILTER_VALIDATE_URL)) {
        $shopError = 'Enter a valid Google Maps link (or leave it blank).';
    }

    if (!$shopError) {
        if ($shop) {
            // Update
            $pdo->prepare('UPDATE mp_shops SET shop_name=?, description=?, phone=?, email=?, region=?, google_maps_link=?, updated_at=NOW() WHERE id=?')
                ->execute([$shopName, $desc ?: null, $phone ?: null, $email ?: null, $region ?: null, $mapsLink ?: null, $shop['id']]);
        } else {
            // Create
            $slug = mp_unique_slug($shopName, 'mp_shops', 'slug', $pdo);
            $pdo->prepare('INSERT INTO mp_shops (user_id, shop_name, slug, description, phone, email, region, google_maps_link) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$user['id'], $shopName, $slug, $desc ?: null, $phone ?: null, $email ?: null, $region ?: null, $mapsLink ?: null]);
        }

        // Handle logo upload
        if (!empty($_FILES['logo']['name']) && is_valid_image_upload($_FILES['logo'])) {
            $shopId = $shop ? $shop['id'] : (int)$pdo->lastInsertId();
            $logoPath = save_uploaded_image($_FILES['logo'], 'uploads/marketplace/shops/' . $shopId . '/logo');
            if ($logoPath) $pdo->prepare('UPDATE mp_shops SET logo_path=? WHERE id=?')->execute([$logoPath, $shopId]);
        }

        flash($shop ? 'Shop settings saved.' : 'Shop created! Now add your first product.', 'success');
        header('Location: seller_dashboard.php?tab=products');
        exit;
    }
    $shop = get_shop_by_user((int)$user['id']);
    $tab  = 'setup';
}

// ── Handle order status update ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'order_status') {
    csrf_check();
    if (!$shop) { flash('Create your shop first.', 'warning'); header('Location: seller_dashboard.php'); exit; }

    $orderId   = (int)$_POST['order_id'];
    $newStatus = $_POST['new_status'] ?? '';
    $validTransitions = [
        'pending'    => ['confirmed','cancelled'],
        'confirmed'  => ['processing','cancelled'],
        'processing' => ['ready_for_delivery','cancelled'],
    ];

    $orderRow = $pdo->prepare('SELECT * FROM mp_orders WHERE id = ? AND shop_id = ?');
    $orderRow->execute([$orderId, $shop['id']]);
    $order = $orderRow->fetch();

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

        // Delivery integration: when ready_for_delivery, auto-create delivery request
        $deliveryFailed = false;
        if ($newStatus === 'ready_for_delivery' && !$order['delivery_request_id']) {
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
    $orderRow = $pdo->prepare("SELECT * FROM mp_orders WHERE id=? AND shop_id=? AND status='ready_for_delivery' AND delivery_request_id IS NULL");
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

// ── Handle payout / withdrawal request ────────────────────────────────────
$payoutError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'request_payout') {
    csrf_check();
    if (!$shop) { flash('Create your shop first.', 'warning'); header('Location: seller_dashboard.php'); exit; }

    $amount = (float)($_POST['amount'] ?? 0);
    $momo   = trim($_POST['momo_number'] ?? '');

    $pendSt = $pdo->prepare("SELECT COUNT(*) FROM mp_payout_requests WHERE shop_id=? AND status='pending'");
    $pendSt->execute([$shop['id']]);
    $hasPendingPayout = (int)$pendSt->fetchColumn() > 0;

    if ($hasPendingPayout) $payoutError = 'You already have a pending withdrawal request. Wait for it to be processed.';
    elseif ($amount < 1) $payoutError = 'Enter a valid withdrawal amount.';
    elseif ($amount > (float)$shop['available_balance']) $payoutError = 'You only have GH₵ ' . number_format($shop['available_balance'], 2) . ' available.';
    elseif (!$momo) $payoutError = 'Enter your mobile money number.';

    if (!$payoutError) {
        $pdo->prepare('INSERT INTO mp_payout_requests (shop_id, amount, momo_number, status) VALUES (?,?,?,\'pending\')')
            ->execute([$shop['id'], $amount, $momo]);
        log_audit_action((int)$user['id'], 'mp_payout_request', "Requested withdrawal of GHS " . number_format($amount, 2) . " via $momo");
        flash('Withdrawal request submitted! An admin will review it shortly.', 'success');
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
            $pdo->prepare("UPDATE mp_products SET status='pending_approval' WHERE id=?")->execute([$productId]);
            flash('Product resubmitted for approval.', 'success');
        }
    }
    header('Location: seller_dashboard.php?tab=products');
    exit;
}

// ── Load data ─────────────────────────────────────────────────────────────
$products = $orders = [];
$stats = ['products'=>0,'active'=>0,'pending_orders'=>0,'total_revenue'=>0];

if ($shop) {
    $products = $pdo->prepare(
        'SELECT mp.*, mc.name AS cat_name,
                (SELECT image_path FROM mp_product_images WHERE product_id=mp.id AND is_primary=1 LIMIT 1) AS primary_image
         FROM mp_products mp
         LEFT JOIN mp_categories mc ON mp.category_id=mc.id
         WHERE mp.shop_id=? ORDER BY mp.created_at DESC LIMIT 60'
    );
    $products->execute([$shop['id']]);
    $products = $products->fetchAll();

    $orders = $pdo->prepare(
        'SELECT mo.*, u.name AS customer_name
         FROM mp_orders mo JOIN users u ON mo.customer_id=u.id
         WHERE mo.shop_id=? ORDER BY mo.created_at DESC LIMIT 40'
    );
    $orders->execute([$shop['id']]);
    $orders = $orders->fetchAll();

    $stats['products']      = count($products);
    $stats['active']        = count(array_filter($products, fn($p) => $p['status']==='approved'));
    $stats['pending_orders']= count(array_filter($orders, fn($o) => $o['status']==='pending'));
    $stats['total_revenue'] = array_sum(array_column(array_filter($orders, fn($o) => $o['status']==='delivered'), 'total_amount'));
}

// ── Analytics (own queries — the $orders array above is capped at 40 rows) ──
$analytics = null;
if ($shop && $tab === 'analytics') {
    $paidStatuses = "'confirmed','processing','ready_for_delivery','in_transit','delivered'"; // excludes pending/cancelled/refunded

    $rev = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM mp_orders WHERE shop_id=? AND status IN ($paidStatuses)");
    $rev->execute([$shop['id']]);
    $totalRevenue = (float)$rev->fetchColumn();

    $revMonth = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM mp_orders WHERE shop_id=? AND status IN ($paidStatuses) AND created_at >= DATE_FORMAT(NOW(),'%Y-%m-01')");
    $revMonth->execute([$shop['id']]);
    $monthRevenue = (float)$revMonth->fetchColumn();

    $ordCount = $pdo->prepare("SELECT COUNT(*) FROM mp_orders WHERE shop_id=? AND status IN ($paidStatuses)");
    $ordCount->execute([$shop['id']]);
    $orderCount = (int)$ordCount->fetchColumn();

    $cancelCount = $pdo->prepare("SELECT COUNT(*) FROM mp_orders WHERE shop_id=? AND status IN ('cancelled','refunded')");
    $cancelCount->execute([$shop['id']]);
    $cancelledCount = (int)$cancelCount->fetchColumn();

    $avgOrder = $orderCount > 0 ? $totalRevenue / $orderCount : 0;

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

    // Top products by revenue (paid orders only)
    $topProducts = $pdo->prepare("
        SELECT moi.product_name, SUM(moi.quantity) AS units_sold, SUM(moi.subtotal) AS revenue
        FROM mp_order_items moi JOIN mp_orders mo ON moi.order_id = mo.id
        WHERE mo.shop_id=? AND mo.status IN ($paidStatuses)
        GROUP BY moi.product_name ORDER BY revenue DESC LIMIT 5
    ");
    $topProducts->execute([$shop['id']]);
    $topProducts = $topProducts->fetchAll();
    $topProductsMax = $topProducts ? max(array_column($topProducts, 'revenue')) : 0;

    $analytics = compact('totalRevenue','monthRevenue','orderCount','cancelledCount','avgOrder','dailyLabels','dailyRevenue','topProducts','topProductsMax');
}

// ── Wallet (balances, pending releases, payout history, activity ledger) ──
$payoutRequests = [];
if ($shop && $tab === 'wallet') {
    $payoutStmt = $pdo->prepare('SELECT * FROM mp_payout_requests WHERE shop_id=? ORDER BY created_at DESC LIMIT 20');
    $payoutStmt->execute([$shop['id']]);
    $payoutRequests = $payoutStmt->fetchAll();
    $hasPendingPayoutDisplay = (bool)array_filter($payoutRequests, fn($p) => $p['status'] === 'pending');

    // Orders still contributing to the pending balance — paid but not yet released
    $pendingReleaseStmt = $pdo->prepare(
        "SELECT id, net_amount, status, payout_release_at
         FROM mp_orders
         WHERE shop_id=? AND payment_status='paid' AND payout_released=0
         ORDER BY (payout_release_at IS NULL) DESC, payout_release_at ASC, created_at DESC
         LIMIT 15"
    );
    $pendingReleaseStmt->execute([$shop['id']]);
    $pendingReleases = $pendingReleaseStmt->fetchAll();

    // Recent wallet ledger — sales credited, releases, withdrawals, reversals
    $ledgerStmt = $pdo->prepare('SELECT * FROM mp_wallet_transactions WHERE shop_id=? ORDER BY created_at DESC LIMIT 25');
    $ledgerStmt->execute([$shop['id']]);
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
        .sd-stats { display:flex; gap:10px; padding:14px 16px; background:var(--surface); border-bottom:1px solid var(--border); flex-wrap:wrap; }
        .sd-stat  { flex:1; min-width:80px; text-align:center; }
        .sd-stat strong { display:block; font-size:1.4rem; font-weight:900; color:var(--primary,#0f766e); line-height:1.1; }
        .sd-stat span   { font-size:.72rem; color:var(--text-muted,#6b7280); }
        .sd-tabs  { display:flex; background:var(--surface); border-bottom:1px solid var(--border); overflow-x:auto; scrollbar-width:none; }
        .sd-tabs::-webkit-scrollbar { display:none; }
        .sd-tab   { flex-shrink:0; padding:12px 16px; font-size:.82rem; font-weight:700; text-decoration:none; color:var(--text-muted,#6b7280); border-bottom:3px solid transparent; }
        .sd-tab.active { color:var(--primary,#0f766e); border-bottom-color:var(--primary,#0f766e); }
        .sd-shell { max-width:900px; margin:0 auto; padding:16px 16px 80px; }
        .sd-prod-card { background:var(--surface); border:1px solid var(--border); border-radius:12px; padding:12px 14px; margin-bottom:8px; display:flex; align-items:center; gap:12px; }
        .sd-prod-img  { width:50px; height:50px; border-radius:8px; background:#f8fafc; overflow:hidden; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
        .sd-prod-img img { width:100%; height:100%; object-fit:cover; }
        .sd-ord-card  { background:var(--surface); border:1px solid var(--border); border-radius:12px; padding:14px; margin-bottom:10px; }
        .sd-badge { display:inline-block; padding:3px 9px; border-radius:20px; font-size:.7rem; font-weight:800; }
        .sd-set-section { background:var(--surface); border:1px solid var(--border); border-radius:14px; padding:18px; margin-bottom:14px; }
        .sd-set-title { font-size:.74rem; font-weight:800; text-transform:uppercase; letter-spacing:.07em; color:var(--text-muted,#6b7280); margin:0 0 14px; }
        label { font-weight:600; font-size:.86rem; display:block; margin-bottom:4px; }
        .form-group { margin-bottom:14px; }
    </style>
</head>
<body class="has-bottom-nav">

<header class="app-topbar">
    <a href="marketplace.php" class="button button-secondary button-small">← Marketplace</a>
    <span class="brand">🏪 <?php echo $shop ? sanitize(mb_substr($shop['shop_name'],0,20)) : 'My Shop'; ?>
        <?php if (!empty($shop['is_subscribed']) && !empty($shop['subscription_end']) && $shop['subscription_end'] >= date('Y-m-d')): ?>
        <span style="font-size:.62rem;font-weight:800;background:#fef3c7;color:#92400e;padding:1px 7px;border-radius:20px;margin-left:4px;">⭐ PRO</span>
        <?php endif; ?>
    </span>
    <?php if (get_platform_setting('mp_subscription_enabled','0')==='1'): ?>
    <a href="pay_mp_subscription.php" class="button button-small" style="background:#fef3c7;color:#92400e;border-color:#f59e0b;font-size:.76rem;">⭐ Subscribe</a>
    <?php endif; ?>
</header>

<?php if ($flash): ?>
<div class="alert alert-<?php echo sanitize($flash['type']); ?>" style="margin:10px 16px 0;"><?php echo sanitize($flash['message']); ?></div>
<?php endif; ?>

<?php if ($shop): ?>
<!-- Stats -->
<div class="sd-stats">
    <div class="sd-stat"><strong><?php echo $stats['products']; ?></strong><span>Products</span></div>
    <div class="sd-stat"><strong><?php echo $stats['active']; ?></strong><span>Active</span></div>
    <div class="sd-stat"><strong style="color:<?php echo $stats['pending_orders']>0?'#f59e0b':'inherit'; ?>"><?php echo $stats['pending_orders']; ?></strong><span>New Orders</span></div>
    <div class="sd-stat"><strong>GH&#8373; <?php echo number_format($stats['total_revenue'],2); ?></strong><span>Revenue</span></div>
    <div class="sd-stat"><strong><?php echo number_format($shop['view_count']); ?></strong><span>Shop Views</span></div>
</div>
<?php endif; ?>

<!-- Tabs -->
<div class="sd-tabs">
    <?php if ($shop): ?>
    <a href="?tab=products" class="sd-tab <?php echo $tab==='products'?'active':''; ?>">Products</a>
    <a href="?tab=orders"   class="sd-tab <?php echo $tab==='orders'?'active':''; ?>">
        Orders <?php if ($stats['pending_orders']): ?><span style="background:#f59e0b;color:#fff;border-radius:10px;padding:0 5px;font-size:.62rem;margin-left:3px;"><?php echo $stats['pending_orders']; ?></span><?php endif; ?>
    </a>
    <a href="?tab=analytics" class="sd-tab <?php echo $tab==='analytics'?'active':''; ?>">Analytics</a>
    <a href="?tab=wallet"    class="sd-tab <?php echo $tab==='wallet'?'active':''; ?>">Wallet</a>
    <?php endif; ?>
    <a href="?tab=setup" class="sd-tab <?php echo $tab==='setup'?'active':''; ?>"><?php echo $shop ? 'Shop Settings' : 'Create Shop'; ?></a>
    <?php if ($shop): ?>
    <a href="?tab=verify" class="sd-tab <?php echo $tab==='verify'?'active':''; ?>">
        Verification <?php if ($shop['verification_status']==='approved'): ?><span style="color:#10b981;">✓</span><?php endif; ?>
    </a>
    <?php endif; ?>
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
    <a href="seller_product_form.php" class="button button-primary button-small">+ Add Product</a>
</div>
<?php if ($products): foreach ($products as $p): ?>
<div class="sd-prod-card">
    <div class="sd-prod-img">
        <?php if ($p['primary_image']): ?><img src="<?php echo sanitize($p['primary_image']); ?>" alt=""><?php else: ?><span style="font-size:1.2rem;opacity:.3;">📦</span><?php endif; ?>
    </div>
    <div style="flex:1;min-width:0;">
        <div style="font-weight:700;font-size:.88rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo sanitize($p['name']); ?></div>
        <div style="font-size:.76rem;color:var(--text-muted,#6b7280);">GH&#8373; <?php echo number_format(mp_effective_price($p),2); ?> &nbsp;·&nbsp; Stock: <?php echo $p['stock_quantity']; ?></div>
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
<div style="text-align:center;padding:40px;color:var(--text-muted,#6b7280);"><p>No products yet. <a href="seller_product_form.php" style="color:var(--primary,#0f766e);">Add your first product →</a></p></div>
<?php endif; ?>

<?php elseif ($tab === 'orders' && $shop): ?>
<?php $orderStatusMap = ['pending'=>['confirmed','cancelled'],'confirmed'=>['processing','cancelled'],'processing'=>['ready_for_delivery','cancelled']]; ?>
<?php if ($orders): foreach ($orders as $order):
    $oItems = $pdo->prepare('SELECT product_name, quantity, subtotal FROM mp_order_items WHERE order_id=?');
    $oItems->execute([$order['id']]);
    $oItems = $oItems->fetchAll();
    $oStockIssues = $pdo->prepare('SELECT product_name, requested_qty FROM mp_order_stock_issues WHERE order_id=?');
    $oStockIssues->execute([$order['id']]);
    $oStockIssues = $oStockIssues->fetchAll();
?>
<div class="sd-ord-card">
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
    <?php if ($order['status'] === 'ready_for_delivery' && !$order['delivery_request_id']): ?>
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
        <?php if (isset($orderStatusMap[$order['status']])): ?>
        <div style="display:flex;gap:6px;flex-wrap:wrap;">
            <?php foreach ($orderStatusMap[$order['status']] as $ns):
                if ($ns === 'cancelled' && $order['payment_status'] === 'paid') continue; // paid orders: admin-only cancellation
                $nsColors=['confirmed'=>'#3b82f6','processing'=>'#8b5cf6','ready_for_delivery'=>'#f97316','cancelled'=>'#ef4444'];
                $nsLabels=['confirmed'=>'Confirm','processing'=>'Start Processing','ready_for_delivery'=>'Mark Ready for Delivery','cancelled'=>'Cancel'];
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
<div style="text-align:center;padding:40px;color:var(--text-muted,#6b7280);">No orders yet.</div>
<?php endif; ?>

<?php elseif ($tab === 'analytics' && $shop): ?>
<div class="sd-an-stats">
    <div class="sd-an-tile"><strong>GH&#8373; <?php echo number_format($analytics['totalRevenue'],2); ?></strong><span>Total Revenue</span></div>
    <div class="sd-an-tile"><strong>GH&#8373; <?php echo number_format($analytics['monthRevenue'],2); ?></strong><span>This Month</span></div>
    <div class="sd-an-tile"><strong><?php echo number_format($analytics['orderCount']); ?></strong><span>Paid Orders</span></div>
    <div class="sd-an-tile"><strong>GH&#8373; <?php echo number_format($analytics['avgOrder'],2); ?></strong><span>Avg. Order Value</span></div>
    <div class="sd-an-tile"><strong><?php echo number_format($analytics['cancelledCount']); ?></strong><span>Cancelled/Refunded</span></div>
</div>

<div class="sd-an-card">
    <p class="sd-an-title">Revenue — Last 30 Days</p>
    <div style="position:relative;height:220px;"><canvas id="sd-revenue-chart"></canvas></div>
</div>

<div class="sd-an-card">
    <p class="sd-an-title">Top Products</p>
    <?php if (!$analytics['topProducts']): ?>
    <p class="meta">No sales yet — once orders come in, your best sellers will show up here.</p>
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

<style>
.sd-an-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:10px; margin-bottom:16px; }
.sd-an-tile  { background:var(--surface); border:1px solid var(--border); border-radius:12px; padding:14px; text-align:center; }
.sd-an-tile strong { display:block; font-size:1.15rem; font-weight:900; color:var(--primary,#0f766e); }
.sd-an-tile span   { font-size:.72rem; color:var(--text-muted,#6b7280); }
.sd-an-card  { background:var(--surface); border:1px solid var(--border); border-radius:14px; padding:16px; margin-bottom:14px; }
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
<div class="sd-wal-hero">
    <div class="sd-wal-balance sd-wal-balance-pending">
        <div class="sd-wal-bal-icon">⏳</div>
        <div>
            <strong>GH&#8373; <?php echo number_format((float)$shop['pending_balance'],2); ?></strong>
            <span>Pending Balance</span>
        </div>
    </div>
    <div class="sd-wal-balance sd-wal-balance-available">
        <div class="sd-wal-bal-icon">💰</div>
        <div>
            <strong>GH&#8373; <?php echo number_format((float)$shop['available_balance'],2); ?></strong>
            <span>Available Balance</span>
        </div>
    </div>
</div>
<p class="meta" style="margin:0 0 16px;text-align:center;">💡 Funds move from Pending to Available <?php echo $confirmDaysDisplay; ?> day(s) after an order is marked delivered.</p>

<?php if ($pendingReleases): ?>
<div class="sd-an-card">
    <p class="sd-an-title">⏳ Pending Releases (<?php echo count($pendingReleases); ?>)</p>
    <?php foreach ($pendingReleases as $pr):
        if ($pr['status'] !== 'delivered' || !$pr['payout_release_at']) {
            $releaseLabel = 'Awaiting delivery confirmation';
            $releaseColor = '#6b7280';
        } else {
            $secsLeft = strtotime($pr['payout_release_at']) - time();
            if ($secsLeft <= 0) { $releaseLabel = 'Releasing soon'; $releaseColor = '#0f766e'; }
            else {
                $daysLeft = max(1, (int)ceil($secsLeft / 86400));
                $releaseLabel = 'Releases in ' . $daysLeft . ' day' . ($daysLeft === 1 ? '' : 's');
                $releaseColor = '#b45309';
            }
        }
    ?>
    <div class="sd-wal-release-row">
        <div>
            <span style="font-weight:700;">Order #<?php echo $pr['id']; ?></span>
            <span style="font-size:.76rem;color:<?php echo $releaseColor; ?>;margin-left:6px;font-weight:600;"><?php echo sanitize($releaseLabel); ?></span>
        </div>
        <strong style="color:var(--primary,#0f766e);">GH&#8373; <?php echo number_format((float)$pr['net_amount'],2); ?></strong>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="sd-an-card">
    <p class="sd-an-title">💸 Request Withdrawal</p>
    <?php if ($payoutError): ?><div class="alert alert-error"><?php echo sanitize($payoutError); ?></div><?php endif; ?>
    <?php if ($hasPendingPayoutDisplay): ?>
    <div class="sd-wal-notice">⏳ You have a pending withdrawal request. Please wait for it to be processed before requesting another.</div>
    <?php elseif ((float)$shop['available_balance'] < 1): ?>
    <p class="meta">No funds available to withdraw yet.</p>
    <?php else: ?>
    <form method="post" action="seller_dashboard.php?tab=wallet">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="form" value="request_payout">
        <div class="form-group">
            <label for="amount">Amount (GH&#8373;)</label>
            <input type="number" id="amount" name="amount" step="0.01" min="1" max="<?php echo (float)$shop['available_balance']; ?>" value="<?php echo sanitize($_POST['amount'] ?? number_format((float)$shop['available_balance'],2,'.','')); ?>" required>
            <p style="font-size:.74rem;color:var(--text-muted,#6b7280);margin:4px 0 0;">Max GH&#8373; <?php echo number_format((float)$shop['available_balance'],2); ?> available.</p>
        </div>
        <div class="form-group">
            <label for="momo_number">Mobile Money Number</label>
            <input type="tel" id="momo_number" name="momo_number" placeholder="e.g. 024xxxxxxx" value="<?php echo sanitize($_POST['momo_number'] ?? ($shop['phone']??'')); ?>" required>
        </div>
        <button type="submit" class="button button-primary" style="width:100%;padding:12px;">Request Withdrawal</button>
    </form>
    <?php endif; ?>
</div>

<div class="sd-an-card">
    <p class="sd-an-title">📋 Withdrawal History</p>
    <?php if (!$payoutRequests): ?>
    <p class="meta">No withdrawal requests yet.</p>
    <?php else: ?>
    <?php $poIcons = ['pending'=>'⏳','approved'=>'✅','paid'=>'💵','rejected'=>'❌']; ?>
    <?php $poColors = ['pending'=>['#fef3c7','#b45309'],'approved'=>['#dbeafe','#1d4ed8'],'paid'=>['#d1fae5','#065f46'],'rejected'=>['#fee2e2','#c0392b']]; ?>
    <?php foreach ($payoutRequests as $po): [$bg,$col] = $poColors[$po['status']] ?? ['#f3f4f6','#6b7280']; ?>
    <div class="sd-wal-history-row">
        <div>
            <div style="font-weight:700;">GH&#8373; <?php echo number_format((float)$po['amount'],2); ?></div>
            <div style="font-size:.76rem;color:var(--text-muted,#6b7280);"><?php echo sanitize($po['momo_number']); ?> &nbsp;·&nbsp; <?php echo time_ago($po['created_at']); ?></div>
            <?php if ($po['status']==='rejected' && $po['admin_notes']): ?>
            <div style="font-size:.76rem;color:#c0392b;margin-top:2px;">Reason: <?php echo sanitize($po['admin_notes']); ?></div>
            <?php endif; ?>
        </div>
        <span class="sd-badge" style="background:<?php echo $bg; ?>;color:<?php echo $col; ?>;"><?php echo $poIcons[$po['status']] ?? ''; ?> <?php echo ucfirst($po['status']); ?></span>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<div class="sd-an-card">
    <p class="sd-an-title">📜 Recent Wallet Activity</p>
    <?php if (!$walletLedger): ?>
    <p class="meta">No wallet activity yet.</p>
    <?php else: ?>
    <?php
    $ledgerMeta = [
        'sale_pending'          => ['icon'=>'💰','label'=>'Sale credited (pending)', 'sign'=>1],
        'released_to_available' => ['icon'=>'🔓','label'=>'Released to available',  'sign'=>0],
        'withdrawal'            => ['icon'=>'💸','label'=>'Withdrawal approved',     'sign'=>-1],
        'reversal'              => ['icon'=>'↩️','label'=>'Refund reversal',         'sign'=>-1],
    ];
    ?>
    <?php foreach ($walletLedger as $tx):
        $meta = $ledgerMeta[$tx['type']] ?? ['icon'=>'•','label'=>ucfirst(str_replace('_',' ',$tx['type'])),'sign'=>0];
        $amt  = abs((float)$tx['amount']);
        if ($meta['sign'] > 0)      { $color = '#065f46'; $prefix = '+'; }
        elseif ($meta['sign'] < 0)  { $color = '#c0392b'; $prefix = '−'; }
        else                        { $color = 'var(--text-muted,#6b7280)'; $prefix = ''; }
    ?>
    <div class="sd-wal-ledger-row">
        <div style="display:flex;align-items:center;gap:10px;">
            <span class="sd-wal-ledger-icon"><?php echo $meta['icon']; ?></span>
            <div>
                <div style="font-weight:600;font-size:.85rem;"><?php echo sanitize($meta['label']); ?></div>
                <div style="font-size:.74rem;color:var(--text-muted,#6b7280);">
                    <?php if ($tx['order_id']): ?>Order #<?php echo $tx['order_id']; ?> &nbsp;·&nbsp; <?php endif; ?>
                    <?php echo time_ago($tx['created_at']); ?>
                </div>
            </div>
        </div>
        <strong style="color:<?php echo $color; ?>;white-space:nowrap;"><?php echo $prefix; ?>GH&#8373; <?php echo number_format($amt,2); ?></strong>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<style>
.sd-wal-hero { display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:10px; }
.sd-wal-balance { display:flex; align-items:center; gap:12px; border-radius:14px; padding:16px; border:1px solid var(--border); }
.sd-wal-balance strong { display:block; font-size:1.25rem; font-weight:900; line-height:1.15; }
.sd-wal-balance span { font-size:.72rem; color:var(--text-muted,#6b7280); }
.sd-wal-balance-pending   { background:linear-gradient(135deg,#fef9ee,#fef3c7); }
.sd-wal-balance-available { background:linear-gradient(135deg,#ecfdf5,#d1fae5); }
.sd-wal-balance-pending strong   { color:#b45309; }
.sd-wal-balance-available strong { color:#065f46; }
.sd-wal-bal-icon { font-size:1.5rem; flex-shrink:0; }
.sd-wal-notice { background:#fef3c7; border:1px solid #f59e0b; border-radius:12px; padding:14px; color:#92400e; font-size:.86rem; }
.sd-wal-release-row, .sd-wal-history-row, .sd-wal-ledger-row { display:flex; align-items:center; justify-content:space-between; padding:10px 0; border-bottom:1px solid var(--border); gap:8px; }
.sd-wal-release-row:last-child, .sd-wal-history-row:last-child, .sd-wal-ledger-row:last-child { border-bottom:none; padding-bottom:0; }
.sd-wal-ledger-icon { font-size:1.1rem; width:30px; height:30px; border-radius:50%; background:var(--surface-muted,#f1f5f9); display:flex; align-items:center; justify-content:center; flex-shrink:0; }
@media(max-width:420px){ .sd-wal-hero { grid-template-columns:1fr; } }
</style>

<?php elseif ($tab === 'setup'): ?>
<?php if ($shopError): ?><div class="alert alert-error"><?php echo sanitize($shopError); ?></div><?php endif; ?>
<form method="post" action="seller_dashboard.php?tab=setup" enctype="multipart/form-data">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="form" value="shop_settings">
    <div class="sd-set-section">
        <p class="sd-set-title"><?php echo $shop ? 'Shop Information' : 'Create Your Shop'; ?></p>
        <div class="form-group">
            <label for="shop_name">Shop Name *</label>
            <input type="text" id="shop_name" name="shop_name" required value="<?php echo sanitize($_POST['shop_name'] ?? ($shop['shop_name']??'')); ?>">
        </div>
        <div class="form-group">
            <label for="description">Shop Description</label>
            <textarea id="description" name="description" rows="3" placeholder="Tell customers about your shop…"><?php echo sanitize($_POST['description'] ?? ($shop['description']??'')); ?></textarea>
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
                <label for="region">Region / Location</label>
                <input type="text" id="region" name="region" placeholder="e.g. Akuapem North, Eastern Region" value="<?php echo sanitize($_POST['region'] ?? ($shop['region']??'')); ?>">
            </div>
            <div class="form-group">
                <label for="google_maps_link">Google Maps Pickup Link</label>
                <input type="url" id="google_maps_link" name="google_maps_link" placeholder="https://maps.google.com/…" value="<?php echo sanitize($_POST['google_maps_link'] ?? ($shop['google_maps_link']??'')); ?>">
                <p class="meta" style="margin-top:4px;">Paste a Google Maps share link to your shop/pickup location — this helps delivery riders find you when picking up orders.</p>
            </div>
        </div>
        <div class="form-group">
            <label for="logo">Shop Logo</label>
            <input type="file" id="logo" name="logo" accept="image/jpeg,image/png,image/webp">
            <?php if ($shop && $shop['logo_path']): ?><div style="margin-top:6px;"><img src="<?php echo sanitize($shop['logo_path']); ?>" style="height:50px;width:50px;object-fit:cover;border-radius:8px;border:1px solid var(--border);" alt="Current logo"></div><?php endif; ?>
        </div>
    </div>
    <button type="submit" class="button button-primary" style="width:100%;padding:13px;"><?php echo $shop ? 'Save Shop Settings' : 'Create Shop'; ?></button>
</form>

<?php elseif ($tab === 'verify' && $shop): ?>
<?php
$verStmt = $pdo->prepare('SELECT * FROM mp_shop_verifications WHERE shop_id=?');
$verStmt->execute([$shop['id']]);
$verification = $verStmt->fetch() ?: null;
?>
<?php if ($shop['verification_status'] === 'approved'): ?>
<div style="background:#d1fae5;border:1px solid #6ee7b7;border-radius:12px;padding:16px;margin-bottom:16px;">
    ✅ <strong>Verified Seller!</strong> Your shop has the Verified badge.
</div>
<?php elseif ($verification && $verification['status'] === 'pending'): ?>
<div style="background:#fef3c7;border:1px solid #f59e0b;border-radius:12px;padding:16px;">
    ⏳ <strong>Verification under review.</strong> Admin will notify you once processed.
</div>
<?php elseif ($verification && $verification['status'] === 'rejected'): ?>
<div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:12px;padding:16px;margin-bottom:16px;">
    ❌ <strong>Rejected.</strong> <?php echo sanitize($verification['rejection_reason']??''); ?> — You can resubmit below.
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

<?php require_once __DIR__ . '/partials/bottom_nav.php'; ?>
</body>
</html>
