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

    if ($shopName === '') $shopError = 'Shop name is required.';
    elseif (!$shop && strlen($shopName) < 3) $shopError = 'Shop name must be at least 3 characters.';

    if (!$shopError) {
        if ($shop) {
            // Update
            $pdo->prepare('UPDATE mp_shops SET shop_name=?, description=?, phone=?, email=?, region=?, updated_at=NOW() WHERE id=?')
                ->execute([$shopName, $desc ?: null, $phone ?: null, $email ?: null, $region ?: null, $shop['id']]);
        } else {
            // Create
            $slug = mp_unique_slug($shopName, 'mp_shops', 'slug', $pdo);
            $pdo->prepare('INSERT INTO mp_shops (user_id, shop_name, slug, description, phone, email, region) VALUES (?,?,?,?,?,?,?)')
                ->execute([$user['id'], $shopName, $slug, $desc ?: null, $phone ?: null, $email ?: null, $region ?: null]);
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

    if ($order && in_array($newStatus, $validTransitions[$order['status']] ?? [], true)) {
        $pdo->prepare('UPDATE mp_orders SET status=?, updated_at=NOW() WHERE id=?')->execute([$newStatus, $orderId]);

        // Delivery integration: when ready_for_delivery, auto-create delivery request
        if ($newStatus === 'ready_for_delivery' && !$order['delivery_request_id']) {
            $fullOrder = $pdo->prepare('SELECT * FROM mp_orders WHERE id = ?');
            $fullOrder->execute([$orderId]);
            $orderData = $fullOrder->fetch();
            $deliveryId = mp_create_delivery_for_order($orderData, (array)$shop);
            if ($deliveryId) {
                $pdo->prepare('UPDATE mp_orders SET delivery_request_id=? WHERE id=?')->execute([$deliveryId, $orderId]);
            }
        }

        notify_user((int)$order['customer_id'], 'Order Update 📦',
            'Your order #' . $orderId . ' is now: ' . mp_order_status_label($newStatus), 'info');

        flash('Order #' . $orderId . ' updated to ' . mp_order_status_label($newStatus), 'success');
    }
    header('Location: seller_dashboard.php?tab=orders');
    exit;
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
    <span class="brand">🏪 <?php echo $shop ? sanitize(mb_substr($shop['shop_name'],0,20)) : 'My Shop'; ?></span>
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
            ❌ <strong>Rejected:</strong> <?php echo sanitize($p['rejection_reason']); ?>
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
    <div style="display:flex;align-items:center;justify-content:space-between;margin-top:10px;flex-wrap:wrap;gap:8px;">
        <div style="font-weight:900;color:var(--primary,#0f766e);">Total: GH&#8373; <?php echo number_format((float)$order['total_amount'],2); ?></div>
        <?php if (isset($orderStatusMap[$order['status']])): ?>
        <div style="display:flex;gap:6px;flex-wrap:wrap;">
            <?php foreach ($orderStatusMap[$order['status']] as $ns):
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
