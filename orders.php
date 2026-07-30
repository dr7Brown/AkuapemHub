<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/marketplace_functions.php';

require_module_enabled('mp', 'Marketplace');
require_login();
$user  = current_user();
$flash = get_flash();

$statusFilter = $_GET['status'] ?? 'all';
$validStatuses = ['pending','confirmed','processing','ready_for_delivery','in_transit','delivered','cancelled','refunded'];
$where  = ['mo.customer_id = ?'];
$params = [$user['id']];
if ($statusFilter !== 'all' && in_array($statusFilter, $validStatuses, true)) {
    $where[] = 'mo.status = ?';
    $params[] = $statusFilter;
}
$whereClause = implode(' AND ', $where);

$ordersStmt = $pdo->prepare(
    "SELECT mo.*, ms.shop_name, ms.slug AS shop_slug
     FROM mp_orders mo
     JOIN mp_shops ms ON mo.shop_id = ms.id
     WHERE $whereClause
     ORDER BY mo.created_at DESC LIMIT 40"
);
$ordersStmt->execute($params);
$orders = $ordersStmt->fetchAll();

// For each order, fetch items
$orderItems = [];
if ($orders) {
    $ids = implode(',', array_column($orders, 'id'));
    $itemStmt = $pdo->query("SELECT moi.*, mpi.image_path AS img FROM mp_order_items moi LEFT JOIN mp_product_images mpi ON mpi.product_id = moi.product_id AND mpi.is_primary = 1 WHERE moi.order_id IN ($ids)");
    foreach ($itemStmt->fetchAll() as $item) {
        $orderItems[$item['order_id']][] = $item;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Orders — AkuapemConnect</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .ord-shell { max-width:760px; margin:0 auto; padding:16px 16px 80px; }
        .ord-tabs  { display:flex; gap:5px; overflow-x:auto; padding-bottom:4px; margin-bottom:16px; scrollbar-width:none; }
        .ord-tabs::-webkit-scrollbar { display:none; }
        .ord-tab   { padding:6px 12px; border-radius:20px; border:1px solid var(--border); font-size:.78rem; font-weight:700; text-decoration:none; color:var(--text-muted,#6b7280); white-space:nowrap; }
        .ord-tab.active { background:var(--primary-soft,#d1fae5); border-color:var(--primary,#0f766e); color:var(--primary,#0f766e); }
        .ord-card  { background:var(--surface); border:1px solid var(--border); border-radius:14px; margin-bottom:12px; overflow:hidden; }
        .ord-head  { display:flex; align-items:flex-start; justify-content:space-between; padding:12px 14px; border-bottom:1px solid var(--border); gap:8px; flex-wrap:wrap; }
        .ord-item  { display:flex; gap:10px; align-items:center; padding:10px 14px; border-bottom:1px solid var(--border); }
        .ord-item:last-child { border-bottom:none; }
        .ord-img   { width:46px; height:46px; border-radius:8px; background:#f8fafc; overflow:hidden; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
        .ord-img img { width:100%; height:100%; object-fit:cover; }
        .ord-badge { display:inline-block; padding:3px 9px; border-radius:20px; font-size:.7rem; font-weight:800; }
        .ord-foot  { padding:10px 14px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; }
    </style>
</head>
<body class="has-bottom-nav">

<header class="app-topbar">
    <a href="marketplace.php" class="button button-secondary button-small">← Marketplace</a>
    <span class="brand">My Orders</span>
</header>

<?php if ($flash): ?>
<div class="alert alert-<?php echo sanitize($flash['type']); ?>" style="margin:10px 16px 0;"><?php echo sanitize($flash['message']); ?></div>
<?php endif; ?>

<main class="ord-shell">

    <!-- Status filter tabs -->
    <div class="ord-tabs">
        <a href="orders.php" class="ord-tab <?php echo $statusFilter==='all'?'active':''; ?>">All</a>
        <?php $tabMap = ['pending'=>'Pending','confirmed'=>'Confirmed','processing'=>'Processing','ready_for_delivery'=>'Ready','in_transit'=>'In Transit','delivered'=>'Delivered','cancelled'=>'Cancelled']; ?>
        <?php foreach ($tabMap as $v=>$l): ?>
        <a href="orders.php?status=<?php echo $v; ?>" class="ord-tab <?php echo $statusFilter===$v?'active':''; ?>"><?php echo $l; ?></a>
        <?php endforeach; ?>
    </div>

    <?php if ($orders): foreach ($orders as $order):
        $oItems = $orderItems[$order['id']] ?? [];
    ?>
    <div class="ord-card">
        <div class="ord-head">
            <div>
                <div style="font-weight:800;font-size:.9rem;">Order #<?php echo $order['id']; ?></div>
                <div style="font-size:.75rem;color:var(--text-muted,#6b7280);">
                    🏪 <a href="shop.php?id=<?php echo $order['shop_id']; ?>" style="color:var(--primary,#0f766e);text-decoration:none;"><?php echo sanitize($order['shop_name']); ?></a>
                    &nbsp;·&nbsp; <?php echo time_ago($order['created_at']); ?>
                </div>
            </div>
            <span class="ord-badge" style="background:<?php echo mp_order_status_bg($order['status']); ?>;color:<?php echo mp_order_status_color($order['status']); ?>;">
                <?php echo mp_order_status_label($order['status']); ?>
            </span>
        </div>

        <?php foreach ($oItems as $item): ?>
        <div class="ord-item">
            <div class="ord-img">
                <?php if ($item['img']): ?><img src="<?php echo sanitize($item['img']); ?>" alt=""><?php else: ?><span style="font-size:1rem;opacity:.3;">📦</span><?php endif; ?>
            </div>
            <div style="flex:1;">
                <div style="font-weight:700;font-size:.86rem;"><?php echo sanitize($item['product_name']); ?></div>
                <div style="font-size:.76rem;color:var(--text-muted,#6b7280);">Qty: <?php echo $item['quantity']; ?> · GH&#8373; <?php echo number_format((float)$item['price'],2); ?> each</div>
            </div>
            <div style="font-weight:800;font-size:.86rem;">GH&#8373; <?php echo number_format((float)$item['subtotal'],2); ?></div>
        </div>
        <?php endforeach; ?>

        <div class="ord-foot">
            <div style="font-size:.82rem;color:var(--text-muted,#6b7280);">
                📍 <?php echo sanitize(mb_substr($order['delivery_address']??'',0,50)); ?>
                <?php if (!empty($order['delivery_maps_link'])): ?>
                <a href="<?php echo sanitize($order['delivery_maps_link']); ?>" target="_blank" rel="noopener"
                   style="margin-left:8px;font-size:.76rem;font-weight:700;color:var(--primary,#0f766e);text-decoration:none;white-space:nowrap;">🗺 Map ↗</a>
                <?php endif; ?>
            </div>
            <a href="payment_receipt.php?type=marketplace_order&id=<?php echo $order['id']; ?>" class="button button-secondary button-small" style="flex-shrink:0;">🧾 Receipt</a>
            <?php if (!empty($order['delivery_request_id'])): ?>
            <a href="delivery_detail.php?id=<?php echo $order['delivery_request_id']; ?>" class="button button-secondary button-small" style="flex-shrink:0;">🚚 Track Delivery</a>
            <?php endif; ?>
            <div style="font-weight:900;color:var(--primary,#0f766e);">Total: GH&#8373; <?php echo number_format((float)$order['total_amount'],2); ?></div>
        </div>

        <?php if (in_array($order['status'], ['delivered']) && !empty($orderItems[$order['id']])): ?>
        <div style="padding:8px 14px;border-top:1px solid var(--border);display:flex;gap:8px;">
            <?php foreach ($oItems as $item): if (!$item['product_id']) continue; ?>
            <a href="product.php?id=<?php echo $item['product_id']; ?>#reviews" class="button button-secondary button-small">★ Review</a>
            <?php break; endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; else: ?>
    <div style="text-align:center;padding:60px 20px;color:var(--text-muted,#6b7280);">
        <div style="font-size:3rem;opacity:.4;margin-bottom:14px;">📋</div>
        <p style="margin:0 0 14px;">No orders<?php echo $statusFilter!=='all'?' with this status':''; ?> yet.</p>
        <a href="marketplace.php" class="button button-primary">Start Shopping →</a>
    </div>
    <?php endif; ?>

</main>

<?php require_once __DIR__ . '/partials/bottom_nav.php'; ?>
</body>
</html>
