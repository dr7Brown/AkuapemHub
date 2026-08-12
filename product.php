<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/marketplace_functions.php';
require_once __DIR__ . '/delivery_functions.php'; // render_stars() for the review form

require_module_enabled('mp', 'Marketplace');

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: marketplace.php'); exit; }

$product = get_product($id);
$user    = current_user();

// Admins/managers and the shop owner can preview any status; everyone else can
// view approved or (out of stock — still a real listing, just unavailable to buy)
$isShopOwner   = $user && (int)$product['shop_owner_id'] === (int)$user['id'];
$isAdminViewer = is_admin_or_manager();
$publicStatuses = ['approved', 'out_of_stock'];

if (!$product || ((!in_array($product['status'], $publicStatuses, true) || $product['shop_owner_banned']) && !$isAdminViewer && !$isShopOwner)) {
    header('Location: marketplace.php');
    exit;
}
$flash     = get_flash();
$cartCount = $user ? mp_get_cart_count((int)$user['id']) : 0;
$images    = get_product_images($id);
$effPrice  = mp_effective_price($product);
$discPct   = mp_discount_pct($product);

// Only count views for publicly visible products
$viewKey = 'viewed_product_' . $id;
if (in_array($product['status'], $publicStatuses, true) && empty($_SESSION[$viewKey])) {
    $pdo->prepare('UPDATE mp_products SET view_count = view_count + 1 WHERE id = ?')->execute([$id]);
    $_SESSION[$viewKey] = true;
}

// Saved status
$isSaved = false;
if ($user) {
    $sv = $pdo->prepare('SELECT id FROM mp_saved_products WHERE user_id=? AND product_id=?');
    $sv->execute([$user['id'], $id]);
    $isSaved = (bool)$sv->fetchColumn();
}

// Reviews for this product
$reviewStmt = $pdo->prepare(
    'SELECT mr.*, u.name AS reviewer_name, u.profile_photo AS reviewer_photo
     FROM mp_reviews mr JOIN users u ON mr.reviewer_id = u.id
     WHERE mr.product_id = ? AND mr.review_type = "product"
     ORDER BY mr.created_at DESC LIMIT 10'
);
$reviewStmt->execute([$id]);
$reviews = $reviewStmt->fetchAll();
$avgRating = count($reviews) ? array_sum(array_column($reviews,'rating')) / count($reviews) : 0;

// Can review? (delivered order containing this product and no review yet)
$canReview = false;
if ($user) {
    $cr = $pdo->prepare(
        'SELECT mo.id FROM mp_orders mo
         JOIN mp_order_items moi ON moi.order_id = mo.id
         LEFT JOIN mp_reviews mr ON mr.order_id = mo.id AND mr.product_id = ? AND mr.reviewer_id = ?
         WHERE mo.customer_id = ? AND moi.product_id = ? AND mo.status = "delivered" AND mr.id IS NULL
         LIMIT 1'
    );
    $cr->execute([$id, $user['id'], $user['id'], $id]);
    $reviewableOrderId = $cr->fetchColumn();
    $canReview = (bool)$reviewableOrderId;
}

// Handle review POST
$reviewError = '';
if ($user && $canReview && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'review') {
    csrf_check();
    $rating  = (int)($_POST['rating'] ?? 0);
    $comment = trim($_POST['comment'] ?? '');
    if ($rating < 1 || $rating > 5) {
        $reviewError = 'Please select a star rating.';
    } else {
        $pdo->prepare(
            'INSERT INTO mp_reviews (reviewer_id, order_id, product_id, shop_id, rating, comment, review_type) VALUES (?,?,?,?,?,?,?)'
        )->execute([$user['id'], $reviewableOrderId, $id, $product['shop_id'], $rating, $comment ?: null, 'product']);
        flash('Review submitted!', 'success');
        header('Location: product.php?id=' . $id);
        exit;
    }
}

// Related products (same category, different product)
$related = [];
if ($product['category_id']) {
    $relSt = $pdo->prepare(
        'SELECT mp.id, mp.name, mp.price, mp.discount_price, mp.condition_type,
                mpi.image_path AS primary_image
         FROM mp_products mp
         LEFT JOIN mp_product_images mpi ON mpi.product_id=mp.id AND mpi.is_primary=1
         WHERE mp.category_id=? AND mp.id!=? AND mp.status="approved"
         ORDER BY (mp.is_featured=1 AND (mp.featured_end IS NULL OR mp.featured_end>=CURDATE())) DESC, mp.created_at DESC LIMIT 4'
    );
    $relSt->execute([$product['category_id'], $id]);
    $related = $relSt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php
    $pdIsPublic = in_array($product['status'], ['approved', 'out_of_stock'], true);
    $pdImage    = $images ? (rtrim(BASE_URL,'/') . '/' . ltrim($images[0]['image_path'],'/')) : null;
    echo seo_meta([
        'title'       => $product['name'] . ' — GHS ' . number_format((float)$effPrice, 2) . ' | ' . APP_NAME . ' Marketplace',
        'description' => mb_substr(strip_tags($product['description'] ?? ''), 0, 300) ?: ('Buy ' . $product['name'] . ' on ' . APP_NAME . ' Marketplace.'),
        'image'       => $pdImage,
        'url'         => rtrim(BASE_URL, '/') . '/product.php?id=' . (int)$product['id'],
        'type'        => 'product',
        'noindex'     => !$pdIsPublic,
    ]);
    $shareTitle = $product['name'];
    $shareUrl   = rtrim(BASE_URL, '/') . '/product.php?id=' . (int)$product['id'];
    ?>
    <?php if ($pdIsPublic): ?>
    <script type="application/ld+json">
    <?php
    $pdLd = [
        '@context'    => 'https://schema.org/',
        '@type'       => 'Product',
        'name'        => $product['name'],
        'description' => mb_substr(strip_tags($product['description'] ?? ''), 0, 500) ?: $product['name'],
        'sku'         => $product['sku'] ?: (string)$product['id'],
        'offers'      => [
            '@type'         => 'Offer',
            'url'           => rtrim(BASE_URL,'/') . '/product.php?id=' . (int)$product['id'],
            'priceCurrency' => 'GHS',
            'price'         => (float)$effPrice,
            'availability'  => (int)$product['stock_quantity'] > 0 ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
            'itemCondition' => $product['condition_type'] === 'new' ? 'https://schema.org/NewCondition' : 'https://schema.org/UsedCondition',
        ],
    ];
    if ($pdImage) $pdLd['image'] = [$pdImage];
    echo json_encode($pdLd, JSON_UNESCAPED_SLASHES);
    ?>
    </script>
    <?php endif; ?>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .pd-topbar { background:var(--surface); border-bottom:1px solid var(--border); padding:12px 120px 12px 16px; display:flex; align-items:center; justify-content:space-between; gap:10px; }
        .pd-shell  { max-width:1060px; margin:0 auto; padding:16px 16px 60px; }
        .pd-layout { display:grid; grid-template-columns:1fr 1fr; gap:28px; margin-bottom:28px; }
        @media(max-width:680px){ .pd-layout { grid-template-columns:1fr; } }

        /* Images */
        .pd-main-img { aspect-ratio:1/1; background:#f8fafc; border-radius:14px; overflow:hidden; display:flex; align-items:center; justify-content:center; margin-bottom:10px; }
        .pd-main-img img { width:100%; height:100%; object-fit:contain; padding:8px; }
        .pd-thumbs { display:flex; gap:8px; flex-wrap:wrap; }
        .pd-thumb  { width:60px; height:60px; border-radius:8px; overflow:hidden; border:2px solid var(--border); cursor:pointer; flex-shrink:0; }
        .pd-thumb.active { border-color:var(--primary,#0f766e); }
        .pd-thumb img { width:100%; height:100%; object-fit:cover; }

        /* Info */
        .pd-breadcrumb { font-size:.75rem; color:var(--text-muted,#6b7280); margin-bottom:10px; }
        .pd-breadcrumb a { color:var(--primary,#0f766e); text-decoration:none; }
        .pd-name { font-size:1.3rem; font-weight:900; margin:0 0 8px; line-height:1.3; }
        .pd-price { font-size:1.6rem; font-weight:900; color:var(--primary,#0f766e); }
        .pd-orig  { font-size:1rem; color:var(--text-muted,#6b7280); text-decoration:line-through; margin-left:8px; }
        .pd-disc  { background:#fee2e2; color:#c0392b; font-size:.78rem; font-weight:800; padding:3px 8px; border-radius:20px; margin-left:8px; }
        .pd-meta  { display:flex; gap:10px; flex-wrap:wrap; margin:12px 0; font-size:.83rem; }
        .pd-meta-item { display:flex; align-items:center; gap:5px; color:var(--text-muted,#6b7280); }
        .pd-stock  { font-weight:700; }
        .pd-actions { display:flex; gap:10px; margin-top:18px; flex-wrap:wrap; }
        .pd-qty  { display:flex; align-items:center; gap:6px; }
        .pd-qty button { width:30px; height:30px; border:1px solid var(--border); background:var(--surface); border-radius:6px; font-size:1rem; cursor:pointer; display:flex; align-items:center; justify-content:center; }
        .pd-qty input { width:48px; text-align:center; border:1px solid var(--border); border-radius:6px; padding:4px 0; font-size:.9rem; font-weight:700; }

        /* Shop card */
        .pd-shop-card { background:var(--surface); border:1px solid var(--border); border-radius:12px; padding:14px; display:flex; gap:12px; align-items:center; }
        .pd-shop-logo { width:48px; height:48px; border-radius:10px; object-fit:cover; background:var(--primary-soft,#d1fae5); display:flex; align-items:center; justify-content:center; font-size:1.3rem; font-weight:800; color:var(--primary,#0f766e); flex-shrink:0; overflow:hidden; }
        .pd-shop-logo img { width:100%; height:100%; object-fit:cover; }

        /* Reviews */
        .pd-review { background:var(--surface); border:1px solid var(--border); border-radius:12px; padding:14px; margin-bottom:10px; }
        .pd-review-head { display:flex; align-items:center; gap:10px; margin-bottom:8px; }
        .pd-rav { width:32px; height:32px; border-radius:50%; background:var(--primary-soft,#d1fae5); display:flex; align-items:center; justify-content:center; font-size:.8rem; font-weight:800; color:var(--primary,#0f766e); flex-shrink:0; overflow:hidden; }
        .pd-rav img { width:100%; height:100%; object-fit:cover; }
        .pd-star-full  { color:#f59e0b; }
        .pd-star-empty { color:#e5e7eb; }

        /* Related */
        .pd-related-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(150px,1fr)); gap:12px; margin-top:12px; }
        .mp-card { background:var(--surface); border:1px solid var(--border); border-radius:12px; overflow:hidden; text-decoration:none; color:inherit; transition:box-shadow .15s; }
        .mp-card:hover { box-shadow:0 4px 16px rgba(0,0,0,.1); }
        .mp-card-img { aspect-ratio:1/1; background:#f8fafc; display:flex; align-items:center; justify-content:center; overflow:hidden; }
        .mp-card-img img { width:100%; height:100%; object-fit:cover; }
        .mp-card-body { padding:8px 10px; }
        .mp-card-name { font-weight:700; font-size:.82rem; overflow:hidden; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; }
        .mp-card-price { font-weight:900; font-size:.88rem; color:var(--primary,#0f766e); margin-top:4px; }
    </style>
</head>
<body class="<?php echo $user ? 'has-bottom-nav' : ''; ?>">

<?php if ($product['status'] !== 'approved'): ?>
<div style="background:#fef3c7;border-bottom:2px solid #f59e0b;padding:10px 16px;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;font-size:.85rem;">
    <span>
        ⚠️ <strong>Admin Preview</strong> — this product is
        <strong style="color:<?php echo mp_product_status_color($product['status']); ?>;">
            <?php echo mp_product_status_label($product['status']); ?>
        </strong>
        and not yet visible to the public.
        <?php if ($product['rejection_reason']): ?>
            Rejection reason: <em><?php echo sanitize($product['rejection_reason']); ?></em>
        <?php endif; ?>
    </span>
    <?php if ($isAdminViewer && $product['status'] === 'pending_approval'): ?>
    <div style="display:flex;gap:8px;">
        <form method="post" action="marketplace_ajax.php">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action"     value="approve_product">
            <input type="hidden" name="product_id" value="<?php echo $id; ?>">
            <button type="submit" class="button button-primary button-small">✅ Approve</button>
        </form>
        <form method="post" action="marketplace_ajax.php" style="display:flex;gap:6px;">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action"     value="reject_product">
            <input type="hidden" name="product_id" value="<?php echo $id; ?>">
            <input type="text" name="rejection_reason" placeholder="Rejection reason" style="font-size:.78rem;padding:4px 8px;width:180px;" required>
            <button type="submit" class="button button-small" style="background:#ef4444;color:#fff;border-color:transparent;"
                    onclick="return confirm('Reject this product?');">❌ Reject</button>
        </form>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<header class="pd-topbar">
    <a href="marketplace.php" class="button button-secondary button-small">← Marketplace</a>
    <div style="display:flex;gap:8px;">
        <?php if ($user): ?>
        <a href="cart.php" class="button button-secondary button-small">
            🛒<?php echo $cartCount > 0 ? " ($cartCount)" : ''; ?>
        </a>
        <?php else: ?>
        <a href="login.php?redirect=<?php echo urlencode(current_request_path()); ?>" class="button button-secondary button-small">Sign in</a>
        <?php endif; ?>
    </div>
</header>

<?php if ($flash): ?>
<div class="alert alert-<?php echo sanitize($flash['type']); ?>" style="margin:10px 16px 0;"><?php echo sanitize($flash['message']); ?></div>
<?php endif; ?>

<main class="pd-shell">

    <!-- Breadcrumb -->
    <div class="pd-breadcrumb">
        <a href="marketplace.php">Marketplace</a>
        <?php if ($product['category_name']): ?>
        &rsaquo; <a href="marketplace.php?cat=<?php echo urlencode($product['category_slug']); ?>"><?php echo sanitize($product['category_icon'].' '.$product['category_name']); ?></a>
        <?php endif; ?>
        &rsaquo; <?php echo sanitize(mb_substr($product['name'],0,40)); ?>
    </div>

    <div class="pd-layout">
        <!-- Images -->
        <div>
            <div class="pd-main-img" id="main-img">
                <?php $firstImg = $images[0]['image_path'] ?? null; ?>
                <?php if ($firstImg): ?>
                    <img id="main-img-el" src="<?php echo sanitize($firstImg); ?>" alt="">
                <?php else: ?>
                    <span style="font-size:4rem;opacity:.3;"><?php echo $product['category_icon'] ?? '📦'; ?></span>
                <?php endif; ?>
            </div>
            <?php if (count($images) > 1): ?>
            <div class="pd-thumbs">
                <?php foreach ($images as $i => $img): ?>
                <div class="pd-thumb <?php echo $i===0?'active':''; ?>" onclick="switchImg(this,'<?php echo sanitize($img['image_path']); ?>')">
                    <img src="<?php echo sanitize($img['image_path']); ?>" alt="">
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Info -->
        <div>
            <h1 class="pd-name"><?php echo sanitize($product['name']); ?></h1>

            <div style="display:flex;align-items:baseline;flex-wrap:wrap;gap:4px;margin-bottom:12px;">
                <span class="pd-price">GH&#8373; <?php echo number_format($effPrice, 2); ?><?php if (!empty($product['price_unit'])): ?> <span style="font-size:.62em;font-weight:600;color:var(--text-muted,#6b7280);">/ <?php echo sanitize($product['price_unit']); ?></span><?php endif; ?></span>
                <?php if ($discPct > 0): ?>
                    <span class="pd-orig">GH&#8373; <?php echo number_format((float)$product['price'], 2); ?></span>
                    <span class="pd-disc">-<?php echo $discPct; ?>%</span>
                <?php endif; ?>
            </div>

            <?php if (!empty($product['market_id'])): ?>
            <div style="margin-bottom:12px;padding:9px 12px;border-radius:10px;font-size:.82rem;background:<?php echo $product['market_status']==='open'?'#d1fae5':'#fee2e2'; ?>;color:<?php echo $product['market_status']==='open'?'#065f46':'#c0392b'; ?>;">
                🏬 <strong><?php echo sanitize($product['market_name']); ?></strong> — <?php echo $product['market_status']==='open' ? 'Open for orders now' : 'Currently closed for checkout'; ?>
                <?php if ($product['market_schedule_note']): ?><span style="opacity:.85;">&nbsp;·&nbsp; <?php echo sanitize($product['market_schedule_note']); ?></span><?php endif; ?>
                <?php if ($product['market_status']!=='open'): ?><div style="margin-top:2px;font-size:.78rem;">You can still add this to your cart — checkout opens once this market is marked Open.</div><?php endif; ?>
            </div>
            <?php endif; ?>

            <div style="margin-bottom:12px;">
                <?php require __DIR__ . '/partials/share_buttons.php'; ?>
            </div>

            <div class="pd-meta">
                <span class="pd-meta-item">
                    <span style="background:<?php echo ['new'=>'#d1fae5','used'=>'#fef3c7','refurbished'=>'#dbeafe'][$product['condition_type']]??'#f3f4f6'; ?>;color:<?php echo mp_condition_color($product['condition_type']); ?>;padding:2px 8px;border-radius:10px;font-size:.72rem;font-weight:800;"><?php echo mp_condition_label($product['condition_type']); ?></span>
                </span>
                <span class="pd-meta-item">
                    <span class="pd-stock" style="color:<?php echo $product['stock_quantity'] > 0 ? '#10b981' : '#ef4444'; ?>">
                        <?php echo $product['stock_quantity'] > 0 ? $product['stock_quantity'] . ' in stock' : 'Out of stock'; ?>
                    </span>
                </span>
                <?php if ($product['sku']): ?>
                <span class="pd-meta-item">SKU: <?php echo sanitize($product['sku']); ?></span>
                <?php endif; ?>
                <?php if ($avgRating > 0): ?>
                <span class="pd-meta-item">
                    <?php echo str_repeat('<span class="pd-star-full">★</span>', (int)round($avgRating)); ?><?php echo str_repeat('<span class="pd-star-empty">★</span>', 5 - (int)round($avgRating)); ?>
                    (<?php echo count($reviews); ?>)
                </span>
                <?php endif; ?>
            </div>

            <!-- Actions -->
            <?php if ($product['stock_quantity'] > 0): ?>
            <form method="post" action="marketplace_ajax.php">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="add_to_cart">
                <input type="hidden" name="product_id" value="<?php echo $id; ?>">
                <div class="pd-actions">
                    <?php if ($user): ?>
                    <div class="pd-qty">
                        <button type="button" onclick="adjustQty(-1)">−</button>
                        <input type="number" id="qty" name="quantity" value="1" min="1" max="<?php echo (int)$product['stock_quantity']; ?>">
                        <button type="button" onclick="adjustQty(1)">+</button>
                    </div>
                    <button type="submit" class="button button-primary">🛒 Add to Cart</button>
                    <button type="submit" form="save-form" class="button button-secondary" title="<?php echo $isSaved?'Unsave':'Save'; ?>">
                        <?php echo $isSaved ? '❤️' : '🤍'; ?>
                    </button>
                    <?php else: ?>
                    <a href="login.php?r=product.php?id=<?php echo $id; ?>" class="button button-primary">Sign in to Buy</a>
                    <?php endif; ?>
                </div>
            </form>
            <?php if ($user): ?>
            <form id="save-form" method="post" action="marketplace_ajax.php">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="toggle_save">
                <input type="hidden" name="product_id" value="<?php echo $id; ?>">
            </form>
            <?php endif; ?>
            <?php else: ?>
            <div style="background:#fee2e2;border-radius:10px;padding:12px 14px;margin-top:12px;font-size:.88rem;">⚠️ This product is currently <strong>out of stock</strong>.</div>
            <?php endif; ?>

            <?php if ($product['delivery_available']): ?>
            <div style="margin-top:12px;font-size:.82rem;color:var(--text-muted,#6b7280);">🚚 Delivery available via AkuapemConnect riders</div>
            <?php endif; ?>

            <!-- Shop info -->
            <div style="margin-top:18px;">
                <p style="font-size:.75rem;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted,#6b7280);margin:0 0 8px;">Sold by</p>
                <a href="shop.php?id=<?php echo $product['shop_id']; ?>" class="pd-shop-card" style="text-decoration:none;color:inherit;">
                    <div class="pd-shop-logo">🏪</div>
                    <div>
                        <div style="font-weight:800;"><?php echo sanitize($product['shop_name']); ?></div>
                        <div style="font-size:.76rem;color:var(--text-muted,#6b7280);">
                            <?php if ($product['shop_verified']==='approved'): ?>✓ Verified · <?php endif; ?>
                            ⭐ <?php echo $product['shop_rating'] > 0 ? number_format((float)$product['shop_rating'],1) : 'New'; ?>
                        </div>
                    </div>
                    <span style="margin-left:auto;font-size:.8rem;color:var(--primary,#0f766e);font-weight:700;">View Shop →</span>
                </a>
            </div>
        </div>
    </div>

    <!-- Description -->
    <?php if ($product['description']): ?>
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:18px;margin-bottom:20px;">
        <p style="font-size:.76rem;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted,#6b7280);margin:0 0 12px;">Product Description</p>
        <div style="font-size:.88rem;line-height:1.7;"><?php echo render_rich($product['description']); ?></div>
    </div>
    <?php endif; ?>

    <!-- Reviews -->
    <div id="reviews" style="margin-bottom:20px;">
        <p style="font-size:.76rem;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted,#6b7280);margin:0 0 12px;">
            Reviews (<?php echo count($reviews); ?>)
        </p>

        <?php if ($canReview && !$reviewError): ?>
        <div style="background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:16px;margin-bottom:14px;">
            <p style="font-weight:700;margin:0 0 10px;font-size:.9rem;">Leave a Review</p>
            <?php if ($reviewError): ?><div class="alert alert-error"><?php echo sanitize($reviewError); ?></div><?php endif; ?>
            <form method="post" action="product.php?id=<?php echo $id; ?>" onsubmit="return document.querySelector('input[name=rating]:checked') || (alert('Select a rating'), false);">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="form" value="review">
                <div class="form-group">
                    <label style="font-weight:600;font-size:.85rem;">Rating *</label>
                    <?php echo render_stars(0, true, 'rating'); ?>
                </div>
                <div class="form-group">
                    <label style="font-weight:600;font-size:.85rem;">Comment (optional)</label>
                    <textarea name="comment" rows="2" placeholder="How is the product?"></textarea>
                </div>
                <button type="submit" class="button button-primary button-small">Submit Review</button>
            </form>
        </div>
        <?php endif; ?>

        <?php if ($reviews): ?>
        <?php foreach ($reviews as $r): ?>
        <div class="pd-review">
            <div class="pd-review-head">
                <div class="pd-rav">
                    <?php if (!empty($r['reviewer_photo'])): ?><img src="<?php echo sanitize($r['reviewer_photo']); ?>" alt=""><?php else: ?><?php echo strtoupper(substr($r['reviewer_name'],0,1)); ?><?php endif; ?>
                </div>
                <div>
                    <div style="font-weight:700;font-size:.86rem;"><?php echo sanitize($r['reviewer_name']); ?></div>
                    <div><?php echo str_repeat('<span class="pd-star-full">★</span>',$r['rating']); ?><?php echo str_repeat('<span class="pd-star-empty">★</span>',5-$r['rating']); ?></div>
                </div>
                <span style="margin-left:auto;font-size:.72rem;color:var(--text-muted,#6b7280);"><?php echo time_ago($r['created_at']); ?></span>
            </div>
            <?php if ($r['comment']): ?><p style="margin:0;font-size:.86rem;"><?php echo sanitize($r['comment']); ?></p><?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php else: ?>
        <div style="text-align:center;padding:20px;color:var(--text-muted,#6b7280);font-size:.85rem;">No reviews yet. Be the first!</div>
        <?php endif; ?>
    </div>

    <!-- Related Products -->
    <?php if ($related): ?>
    <p style="font-size:.76rem;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted,#6b7280);margin:0 0 8px;">Related Products</p>
    <div class="pd-related-grid">
        <?php foreach ($related as $r): ?>
        <a href="product.php?id=<?php echo $r['id']; ?>" class="mp-card">
            <div class="mp-card-img">
                <?php if ($r['primary_image']): ?><img src="<?php echo sanitize($r['primary_image']); ?>" alt=""><?php else: ?><span style="font-size:2rem;opacity:.3;">📦</span><?php endif; ?>
            </div>
            <div class="mp-card-body">
                <div class="mp-card-name"><?php echo sanitize($r['name']); ?></div>
                <div class="mp-card-price">GH&#8373; <?php echo number_format(mp_effective_price($r),2); ?></div>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</main>

<?php if ($user): require_once __DIR__ . '/partials/bottom_nav.php'; endif; ?>

<script>
function switchImg(thumb, src) {
    document.getElementById('main-img-el').src = src;
    document.querySelectorAll('.pd-thumb').forEach(t => t.classList.remove('active'));
    thumb.classList.add('active');
}
function adjustQty(delta) {
    var el = document.getElementById('qty');
    var v  = parseInt(el.value) + delta;
    el.value = Math.max(1, Math.min(parseInt(el.max), v));
}
</script>
</body>
</html>
