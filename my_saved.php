<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/marketplace_functions.php';

require_login();
$user      = current_user();
$flash     = get_flash();
$cartCount = mp_get_cart_count((int)$user['id']);

// Fetch saved products
$savedStmt = $pdo->prepare(
    'SELECT sp.saved_at,
            mp.id AS product_id, mp.name, mp.price, mp.discount_price,
            mp.stock_quantity, mp.status, mp.condition_type,
            ms.shop_name, ms.id AS shop_id,
            mc.icon AS cat_icon,
            mpi.image_path AS primary_image
     FROM mp_saved_products sp
     JOIN mp_products mp   ON sp.product_id = mp.id
     JOIN mp_shops ms      ON mp.shop_id = ms.id
     LEFT JOIN mp_categories mc  ON mp.category_id = mc.id
     LEFT JOIN mp_product_images mpi ON mpi.product_id = mp.id AND mpi.is_primary = 1
     WHERE sp.user_id = ?
     ORDER BY sp.saved_at DESC'
);
$savedStmt->execute([$user['id']]);
$saved = $savedStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Saved Products — AkuapemConnect</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .sv-shell { max-width:1060px; margin:0 auto; padding:16px 16px 80px; }
        .sv-grid  { display:grid; grid-template-columns:repeat(auto-fill,minmax(185px,1fr)); gap:14px; }
        .sv-card  { background:var(--surface); border:1px solid var(--border); border-radius:14px; overflow:hidden; display:flex; flex-direction:column; transition:box-shadow .15s,transform .15s; }
        .sv-card:hover { box-shadow:0 6px 22px rgba(0,0,0,.1); transform:translateY(-2px); }
        .sv-img   { aspect-ratio:1/1; background:#f8fafc; overflow:hidden; display:flex; align-items:center; justify-content:center; position:relative; }
        .sv-img img { width:100%; height:100%; object-fit:cover; }
        .sv-unavail { position:absolute; inset:0; background:rgba(0,0,0,.45); display:flex; align-items:center; justify-content:center; color:#fff; font-size:.78rem; font-weight:800; }
        .sv-body  { padding:10px 12px 12px; flex:1; display:flex; flex-direction:column; }
        .sv-name  { font-weight:700; font-size:.86rem; line-height:1.4; overflow:hidden; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; margin-bottom:4px; }
        .sv-shop  { font-size:.72rem; color:var(--text-muted,#6b7280); margin-bottom:6px; }
        .sv-price { font-weight:900; font-size:.92rem; color:var(--primary,#0f766e); }
        .sv-orig  { font-size:.76rem; color:var(--text-muted,#6b7280); text-decoration:line-through; margin-left:4px; }
        .sv-foot  { display:flex; gap:6px; padding:8px 12px; border-top:1px solid var(--border); background:var(--surface-muted,#f8fafc); }
        @media(max-width:480px){ .sv-grid { grid-template-columns:repeat(2,1fr); } }
    </style>
</head>
<body class="has-bottom-nav">

<header class="app-topbar">
    <a href="marketplace.php" class="button button-secondary button-small">← Marketplace</a>
    <span class="brand">❤️ Saved Products</span>
    <a href="cart.php" class="button button-secondary button-small">
        🛒<?php echo $cartCount > 0 ? " ($cartCount)" : ''; ?>
    </a>
</header>

<?php if ($flash): ?>
<div class="alert alert-<?php echo sanitize($flash['type']); ?>" style="margin:10px 16px 0;"><?php echo sanitize($flash['message']); ?></div>
<?php endif; ?>

<main class="sv-shell">

    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
        <p style="margin:0;font-size:.78rem;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted,#6b7280);">
            <?php echo count($saved); ?> saved item<?php echo count($saved) !== 1 ? 's' : ''; ?>
        </p>
    </div>

    <?php if ($saved): ?>
    <div class="sv-grid">
        <?php foreach ($saved as $p):
            $effPrice  = mp_effective_price($p);
            $discPct   = mp_discount_pct($p);
            $available = $p['status'] === 'approved' && $p['stock_quantity'] > 0;
        ?>
        <div class="sv-card">
            <a href="product.php?id=<?php echo (int)$p['product_id']; ?>" style="text-decoration:none;color:inherit;display:flex;flex-direction:column;flex:1;">
                <div class="sv-img">
                    <?php if ($p['primary_image']): ?>
                        <img src="<?php echo sanitize($p['primary_image']); ?>" alt="">
                    <?php else: ?>
                        <span style="font-size:2.5rem;opacity:.3;"><?php echo $p['cat_icon'] ?? '📦'; ?></span>
                    <?php endif; ?>
                    <?php if (!$available): ?>
                        <div class="sv-unavail"><?php echo $p['status'] !== 'approved' ? 'Unavailable' : 'Out of Stock'; ?></div>
                    <?php endif; ?>
                    <?php if ($discPct >= 10): ?>
                        <span style="position:absolute;top:6px;left:6px;background:#ef4444;color:#fff;font-size:.6rem;font-weight:800;padding:2px 6px;border-radius:10px;">-<?php echo $discPct; ?>%</span>
                    <?php endif; ?>
                </div>
                <div class="sv-body">
                    <div class="sv-name"><?php echo sanitize($p['name']); ?></div>
                    <div class="sv-shop">🏪 <?php echo sanitize(mb_substr($p['shop_name'], 0, 28)); ?></div>
                    <div class="sv-price">
                        GH&#8373; <?php echo number_format($effPrice, 2); ?>
                        <?php if ($discPct > 0): ?><span class="sv-orig">GH&#8373; <?php echo number_format((float)$p['price'], 2); ?></span><?php endif; ?>
                    </div>
                    <div style="font-size:.72rem;color:var(--text-muted,#6b7280);margin-top:4px;">Saved <?php echo time_ago($p['saved_at']); ?></div>
                </div>
            </a>
            <div class="sv-foot">
                <?php if ($available): ?>
                <form method="post" action="marketplace_ajax.php" style="margin:0;flex:1;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action"     value="add_to_cart">
                    <input type="hidden" name="product_id" value="<?php echo $p['product_id']; ?>">
                    <input type="hidden" name="quantity"   value="1">
                    <button type="submit" class="button button-primary button-small" style="width:100%;">Add to Cart</button>
                </form>
                <?php else: ?>
                <span style="flex:1;text-align:center;font-size:.78rem;color:var(--text-muted,#6b7280);padding:6px 0;">Not available</span>
                <?php endif; ?>
                <form method="post" action="marketplace_ajax.php" style="margin:0;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action"     value="toggle_save">
                    <input type="hidden" name="product_id" value="<?php echo $p['product_id']; ?>">
                    <button type="submit" class="button button-secondary button-small" title="Remove from saved" onclick="return confirm('Remove from saved?');">❌</button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php else: ?>
    <div style="text-align:center;padding:60px 20px;color:var(--text-muted,#6b7280);">
        <div style="font-size:3rem;opacity:.4;margin-bottom:14px;">❤️</div>
        <p style="margin:0 0 16px;">No saved products yet.</p>
        <a href="marketplace.php" class="button button-primary">Browse Marketplace →</a>
    </div>
    <?php endif; ?>

</main>

<?php require_once __DIR__ . '/partials/bottom_nav.php'; ?>
</body>
</html>
