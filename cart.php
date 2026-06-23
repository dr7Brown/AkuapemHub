<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/marketplace_functions.php';

require_login();
$user  = current_user();
$flash = get_flash();

$items = mp_get_cart_items((int)$user['id']);

// Group by shop
$byShop = [];
foreach ($items as $item) {
    $byShop[$item['shop_id']] = $byShop[$item['shop_id']] ?? ['shop_name'=>$item['shop_name'],'shop_slug'=>$item['shop_slug'],'items'=>[]];
    $byShop[$item['shop_id']]['items'][] = $item;
}

// Grand total
$grandTotal = 0;
foreach ($items as $item) {
    $grandTotal += mp_effective_price($item) * $item['quantity'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shopping Cart — AkuapemConnect</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .cart-shell { max-width:760px; margin:0 auto; padding:16px 16px 80px; }
        .cart-shop  { background:var(--surface); border:1px solid var(--border); border-radius:14px; margin-bottom:14px; overflow:hidden; }
        .cart-shop-head { padding:10px 14px; border-bottom:1px solid var(--border); font-weight:800; font-size:.86rem; background:var(--surface-muted,#f8fafc); }
        .cart-item  { display:flex; align-items:center; gap:12px; padding:12px 14px; border-bottom:1px solid var(--border); }
        .cart-item:last-child { border-bottom:none; }
        .cart-img   { width:60px; height:60px; border-radius:8px; object-fit:cover; background:#f8fafc; flex-shrink:0; overflow:hidden; display:flex; align-items:center; justify-content:center; }
        .cart-img img { width:100%; height:100%; object-fit:cover; }
        .cart-qty   { display:flex; align-items:center; gap:5px; }
        .cart-qty button { width:26px; height:26px; border:1px solid var(--border); background:var(--surface); border-radius:6px; font-size:.9rem; cursor:pointer; }
        .cart-qty input  { width:38px; text-align:center; border:1px solid var(--border); border-radius:6px; padding:3px 0; font-size:.86rem; font-weight:700; }
        .cart-total { background:var(--surface); border:1px solid var(--border); border-radius:14px; padding:16px 18px; }
        .cart-total-row { display:flex; justify-content:space-between; font-size:.88rem; padding:4px 0; }
        .cart-total-grand { display:flex; justify-content:space-between; font-size:1.1rem; font-weight:900; padding-top:10px; margin-top:8px; border-top:2px solid var(--border); }
    </style>
</head>
<body class="has-bottom-nav">

<header class="app-topbar">
    <a href="marketplace.php" class="button button-secondary button-small">← Marketplace</a>
    <span class="brand">🛒 Shopping Cart</span>
</header>

<?php if ($flash): ?>
<div class="alert alert-<?php echo sanitize($flash['type']); ?>" style="margin:10px 16px 0;"><?php echo sanitize($flash['message']); ?></div>
<?php endif; ?>

<main class="cart-shell">

<?php if ($items): ?>

    <?php foreach ($byShop as $shopId => $shopGroup): ?>
    <div class="cart-shop">
        <div class="cart-shop-head">
            <a href="shop.php?id=<?php echo $shopId; ?>" style="text-decoration:none;color:inherit;">🏪 <?php echo sanitize($shopGroup['shop_name']); ?></a>
        </div>
        <?php foreach ($shopGroup['items'] as $item): ?>
        <div class="cart-item">
            <a href="product.php?id=<?php echo $item['product_id']; ?>" class="cart-img">
                <?php if ($item['primary_image']): ?><img src="<?php echo sanitize($item['primary_image']); ?>" alt=""><?php else: ?><span style="font-size:1.4rem;opacity:.4;">📦</span><?php endif; ?>
            </a>
            <div style="flex:1;min-width:0;">
                <a href="product.php?id=<?php echo $item['product_id']; ?>" style="font-weight:700;font-size:.88rem;text-decoration:none;color:inherit;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo sanitize($item['name']); ?></a>
                <div style="font-size:.78rem;color:var(--text-muted,#6b7280);">GH&#8373; <?php echo number_format(mp_effective_price($item),2); ?> each</div>
                <?php if ($item['status'] !== 'approved'): ?><div style="font-size:.72rem;color:#ef4444;">⚠ No longer available</div><?php endif; ?>
            </div>
            <div style="display:flex;flex-direction:column;align-items:flex-end;gap:6px;">
                <div style="font-weight:900;color:var(--primary,#0f766e);">GH&#8373; <?php echo number_format(mp_effective_price($item) * $item['quantity'],2); ?></div>
                <div class="cart-qty">
                    <form method="post" action="marketplace_ajax.php"><?php echo csrf_field(); ?><input type="hidden" name="action" value="update_quantity"><input type="hidden" name="product_id" value="<?php echo $item['product_id']; ?>"><input type="hidden" name="quantity" value="<?php echo max(1,$item['quantity']-1); ?>"><button type="submit">−</button></form>
                    <span style="width:28px;text-align:center;font-weight:700;font-size:.86rem;"><?php echo $item['quantity']; ?></span>
                    <form method="post" action="marketplace_ajax.php"><?php echo csrf_field(); ?><input type="hidden" name="action" value="update_quantity"><input type="hidden" name="product_id" value="<?php echo $item['product_id']; ?>"><input type="hidden" name="quantity" value="<?php echo $item['quantity']+1; ?>"><button type="submit" <?php echo $item['quantity']>=$item['stock_quantity']?'disabled':''; ?>>+</button></form>
                </div>
                <form method="post" action="marketplace_ajax.php"><?php echo csrf_field(); ?><input type="hidden" name="action" value="remove_from_cart"><input type="hidden" name="product_id" value="<?php echo $item['product_id']; ?>"><button type="submit" style="border:none;background:none;color:#ef4444;cursor:pointer;font-size:.78rem;">Remove</button></form>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>

    <!-- Total -->
    <div class="cart-total">
        <div class="cart-total-row"><span>Subtotal (<?php echo count($items); ?> item<?php echo count($items)!==1?'s':''; ?>)</span><span>GH&#8373; <?php echo number_format($grandTotal,2); ?></span></div>
        <div class="cart-total-row" style="color:var(--text-muted,#6b7280);font-size:.78rem;"><span>Delivery fees negotiated with riders</span></div>
        <div class="cart-total-grand"><span>Total</span><span>GH&#8373; <?php echo number_format($grandTotal,2); ?></span></div>
        <a href="checkout.php" class="button button-primary" style="width:100%;text-align:center;display:block;margin-top:14px;padding:13px;font-size:1rem;">
            Proceed to Checkout →
        </a>
        <a href="marketplace.php" class="button button-secondary" style="width:100%;text-align:center;display:block;margin-top:8px;">Continue Shopping</a>
    </div>

<?php else: ?>
    <div style="text-align:center;padding:60px 20px;color:var(--text-muted,#6b7280);">
        <div style="font-size:3rem;opacity:.4;margin-bottom:14px;">🛒</div>
        <p style="margin:0 0 16px;">Your cart is empty.</p>
        <a href="marketplace.php" class="button button-primary">Browse Products →</a>
    </div>
<?php endif; ?>

</main>

<?php require_once __DIR__ . '/partials/bottom_nav.php'; ?>
</body>
</html>
