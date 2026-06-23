<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../marketplace_functions.php';

require_login();
if (!is_admin_or_manager()) { header('Location: ../jobs.php'); exit; }

require_mod_permission('approve_products');
$adminUser = current_user();
$tab       = $_GET['tab'] ?? 'products';

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
            if ($postAction === 'approve_product') {
                $pdo->prepare("UPDATE mp_products SET status='approved', updated_at=NOW() WHERE id=?")->execute([$pid]);
                notify_user((int)$prod['owner_id'], 'Product Approved ✅', '"' . $prod['name'] . '" is now live on the marketplace!', 'success');
                log_audit_action($adminUser['id'], 'mp_product_approve', 'Approved product #' . $pid . ': ' . $prod['name']);
                flash('Product approved.', 'success');
            } else {
                if (!$reason) { flash('Rejection reason is required.', 'error'); header('Location: marketplace.php?tab=products'); exit; }
                $pdo->prepare("UPDATE mp_products SET status='rejected', rejection_reason=?, updated_at=NOW() WHERE id=?")->execute([$reason, $pid]);
                notify_user((int)$prod['owner_id'], 'Product Rejected', '"' . $prod['name'] . '" was not approved. Reason: ' . $reason, 'error');
                log_audit_action($adminUser['id'], 'mp_product_reject', 'Rejected product #' . $pid . ': ' . $prod['name'] . '. Reason: ' . $reason);
                flash('Product rejected.', 'info');
            }
        }
        header('Location: marketplace.php?tab=products'); exit;
    }

    // Shop verify / reject / suspend
    if (in_array($postAction, ['approve_shop','reject_shop','suspend_shop'], true) && !empty($_POST['shop_id'])) {
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
            }
        }
        header('Location: marketplace.php?tab=shops'); exit;
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
            flash('Boost activated.', 'success');
        }
        header('Location: marketplace.php?tab=boosts'); exit;
    }

    // Save settings
    if ($postAction === 'save_settings' && is_admin()) {
        $keys = ['mp_enabled','mp_require_product_approval','mp_featured_product_7day_price','mp_featured_product_30day_price',
                 'mp_featured_shop_7day_price','mp_featured_shop_30day_price','mp_seller_subscription_price','mp_verified_seller_fee'];
        foreach ($keys as $k) {
            if (isset($_POST[$k])) set_platform_setting($k, trim($_POST[$k]));
        }
        foreach (['mp_enabled','mp_require_product_approval'] as $ck) {
            set_platform_setting($ck, isset($_POST[$ck]) ? '1' : '0');
        }
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

if ($tab === 'products') {
    $pf = $_GET['pf'] ?? 'pending_approval';
    $pw = in_array($pf,['pending_approval','approved','rejected','draft'],true) ? "AND mp.status='$pf'" : '';
    $pendingProducts = $pdo->query(
        "SELECT mp.*, ms.shop_name, ms.id AS shop_id,
                (SELECT image_path FROM mp_product_images WHERE product_id=mp.id AND is_primary=1 LIMIT 1) AS primary_image,
                mc.name AS cat_name
         FROM mp_products mp JOIN mp_shops ms ON mp.shop_id=ms.id LEFT JOIN mp_categories mc ON mp.category_id=mc.id
         WHERE ms.status='active' $pw ORDER BY mp.created_at ASC LIMIT 60"
    )->fetchAll();
}
if ($tab === 'shops') {
    $sf = $_GET['sf'] ?? 'all';
    $sw = '';
    if ($sf === 'pending_verification') $sw = "AND ms.verification_status='pending'";
    elseif ($sf === 'active') $sw = "AND ms.status='active'";
    elseif ($sf === 'suspended') $sw = "AND ms.status='suspended'";
    $shops = $pdo->query(
        "SELECT ms.*, u.name AS owner_name, u.email AS owner_email, COUNT(mp.id) AS product_count
         FROM mp_shops ms JOIN users u ON ms.user_id=u.id LEFT JOIN mp_products mp ON mp.shop_id=ms.id AND mp.status='approved'
         WHERE 1=1 $sw GROUP BY ms.id ORDER BY ms.created_at DESC LIMIT 60"
    )->fetchAll();
}
if ($tab === 'orders') {
    $of = $_GET['of'] ?? 'all';
    $ow = $of !== 'all' ? "AND mo.status='$of'" : '';
    $orders = $pdo->query(
        "SELECT mo.*, ms.shop_name, cu.name AS customer_name
         FROM mp_orders mo JOIN mp_shops ms ON mo.shop_id=ms.id JOIN users cu ON mo.customer_id=cu.id
         WHERE 1=1 $ow ORDER BY mo.created_at DESC LIMIT 60"
    )->fetchAll();
}

// Boosts
$boostOrders = [];
if ($tab === 'boosts') {
    $boostOrders = $pdo->query(
        "SELECT mb.*, ms.shop_name, u.name AS owner_name, mp.name AS product_name
         FROM mp_boost_orders mb
         JOIN mp_shops ms ON mb.shop_id = ms.id
         JOIN users u ON ms.user_id = u.id
         LEFT JOIN mp_products mp ON mb.product_id = mp.id
         WHERE mb.status = 'pending'
         ORDER BY mb.created_at ASC LIMIT 60"
    )->fetchAll();
}

// Settings
$cfg = [];
if ($tab === 'settings') {
    foreach (['mp_enabled','mp_require_product_approval','mp_featured_product_7day_price','mp_featured_product_30day_price',
              'mp_featured_shop_7day_price','mp_featured_shop_30day_price','mp_seller_subscription_price','mp_verified_seller_fee'] as $k) {
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
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <?php if ($p['status'] !== 'approved'): ?>
            <form method="post" style="margin:0;"><?php echo csrf_field(); ?><input type="hidden" name="action" value="approve_product"><input type="hidden" name="product_id" value="<?php echo $p['id']; ?>"><button type="submit" class="button button-primary button-small">&#10003; Approve</button></form>
            <?php endif; ?>
            <?php if ($p['status'] !== 'rejected'): ?>
            <form method="post" style="margin:0;display:flex;gap:5px;align-items:center;"><?php echo csrf_field(); ?><input type="hidden" name="action" value="reject_product"><input type="hidden" name="product_id" value="<?php echo $p['id']; ?>"><input type="text" name="rejection_reason" placeholder="Rejection reason *" style="font-size:.76rem;padding:4px 9px;width:200px;" required><button type="submit" class="button button-small" style="background:#ef4444;color:#fff;border-color:transparent;">&#10007; Reject</button></form>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; else: ?><div class="empty-state">No products found.</div><?php endif; ?>
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
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; else: ?><div class="empty-state">No shops found.</div><?php endif; ?>
    <?php endif; ?>

    <!-- ═══ ORDERS ═══ -->
    <?php if ($tab === 'orders'): ?>
    <?php $of = $_GET['of'] ?? 'all'; ?>
    <div class="adm-filter">
        <?php foreach (['all'=>'All','pending'=>'Pending','confirmed'=>'Confirmed','delivered'=>'Delivered','cancelled'=>'Cancelled'] as $v=>$l): ?>
        <a href="?tab=orders&of=<?php echo $v; ?>" class="<?php echo $of===$v?'active':''; ?>"><?php echo $l; ?></a>
        <?php endforeach; ?>
    </div>
    <?php if ($orders): foreach ($orders as $o): ?>
    <div class="adm-row">
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
    </div>
    <?php endforeach; else: ?><div class="empty-state">No orders found.</div><?php endif; ?>
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
    <?php endif; ?>

    <!-- ═══ SETTINGS ═══ -->
    <?php if ($tab === 'settings' && is_admin()): ?>
    <form method="post" action="marketplace.php?tab=settings">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="save_settings">

        <div class="adm-set-section">
            <p class="adm-set-title">Module</p>
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-bottom:8px;">
                <input type="checkbox" name="mp_enabled" value="1" <?php echo ($cfg['mp_enabled']??'1')==='1'?'checked':''; ?>>
                Enable Marketplace module
            </label>
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                <input type="checkbox" name="mp_require_product_approval" value="1" <?php echo ($cfg['mp_require_product_approval']??'1')==='1'?'checked':''; ?>>
                Require admin to approve products before listing
            </label>
        </div>

        <div class="adm-set-section">
            <p class="adm-set-title">Monetization Prices (GHS)</p>
            <div class="adm-grid2">
                <?php $priceFields = [
                    'mp_featured_product_7day_price'=>'Featured Product 7 days',
                    'mp_featured_product_30day_price'=>'Featured Product 30 days',
                    'mp_featured_shop_7day_price'=>'Featured Shop 7 days',
                    'mp_featured_shop_30day_price'=>'Featured Shop 30 days',
                    'mp_seller_subscription_price'=>'Premium Seller (monthly)',
                    'mp_verified_seller_fee'=>'Verified Seller badge fee (0=free)',
                ]; ?>
                <?php foreach ($priceFields as $k=>$l): ?>
                <div class="form-group"><label><?php echo $l; ?></label><input type="number" name="<?php echo $k; ?>" min="0" step="0.01" value="<?php echo sanitize($cfg[$k]??'0.00'); ?>"></div>
                <?php endforeach; ?>
            </div>
        </div>

        <button type="submit" class="button button-primary">Save Settings</button>
    </form>
    <?php endif; ?>

</main>
</body>
</html>
