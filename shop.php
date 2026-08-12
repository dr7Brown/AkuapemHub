<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/marketplace_functions.php';
require_once __DIR__ . '/delivery_functions.php'; // render_stars() for the review form

require_module_enabled('mp', 'Marketplace');

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: marketplace.php'); exit; }

$shop = get_shop($id);
if (!$shop || $shop['status'] !== 'active' || $shop['owner_banned']) { header('Location: marketplace.php'); exit; }
// A market's hidden "system shop" isn't a real storefront (no products,
// never browsable) — it exists only as the FK anchor custom orders need.
if (!empty($shop['market_id'])) { header('Location: markets.php'); exit; }

$shareTitle = $shop['shop_name'];
$shareUrl   = rtrim(BASE_URL, '/') . '/shop.php?id=' . $id;

$user      = current_user();
$cartCount = $user ? mp_get_cart_count((int)$user['id']) : 0;

// Increment shop views
$svKey = 'viewed_shop_' . $id;
if (empty($_SESSION[$svKey])) {
    $pdo->prepare('UPDATE mp_shops SET view_count = view_count + 1 WHERE id = ?')->execute([$id]);
    $_SESSION[$svKey] = true;
}

// Shop's products
$sort = $_GET['sort'] ?? 'newest';
$orderBy = match($sort) {
    'price_asc'  => 'COALESCE(mp.discount_price,mp.price) ASC',
    'price_desc' => 'COALESCE(mp.discount_price,mp.price) DESC',
    'popular'    => 'mp.view_count DESC',
    default      => '(mp.is_featured=1 AND (mp.featured_end IS NULL OR mp.featured_end>=CURDATE())) DESC, mp.created_at DESC',
};
$prodStmt = $pdo->prepare(
    "SELECT mp.*, mc.name AS cat_name, mc.icon AS cat_icon,
            mpi.image_path AS primary_image
     FROM mp_products mp
     LEFT JOIN mp_categories mc ON mp.category_id = mc.id
     LEFT JOIN mp_product_images mpi ON mpi.product_id = mp.id AND mpi.is_primary = 1
     WHERE mp.shop_id = ? AND mp.status = 'approved'
     ORDER BY $orderBy LIMIT 40"
);
$prodStmt->execute([$id]);
$products = $prodStmt->fetchAll();

// Shop reviews (seller reviews)
$revStmt = $pdo->prepare(
    'SELECT mr.*, u.name AS reviewer_name, u.profile_photo FROM mp_reviews mr
     JOIN users u ON mr.reviewer_id = u.id
     WHERE mr.shop_id = ? AND mr.review_type = "seller"
     ORDER BY mr.created_at DESC LIMIT 5'
);
$revStmt->execute([$id]);
$shopReviews = $revStmt->fetchAll();

// Can review this shop? (a delivered order from it, not already reviewed as 'seller')
$canReviewShop = false;
if ($user) {
    $cr = $pdo->prepare(
        'SELECT mo.id FROM mp_orders mo
         LEFT JOIN mp_reviews mr ON mr.order_id = mo.id AND mr.shop_id = ? AND mr.reviewer_id = ? AND mr.review_type = "seller"
         WHERE mo.customer_id = ? AND mo.shop_id = ? AND mo.status = "delivered" AND mr.id IS NULL
         LIMIT 1'
    );
    $cr->execute([$id, $user['id'], $user['id'], $id]);
    $reviewableShopOrderId = $cr->fetchColumn();
    $canReviewShop = (bool)$reviewableShopOrderId;
}

// Handle shop review POST
$shopReviewError = '';
if ($user && $canReviewShop && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'shop_review') {
    csrf_check();
    $srRating  = (int)($_POST['rating'] ?? 0);
    $srComment = trim($_POST['comment'] ?? '');
    if ($srRating < 1 || $srRating > 5) {
        $shopReviewError = 'Please select a star rating.';
    } else {
        $pdo->prepare(
            'INSERT INTO mp_reviews (reviewer_id, order_id, product_id, shop_id, rating, comment, review_type) VALUES (?,?,NULL,?,?,?,?)'
        )->execute([$user['id'], $reviewableShopOrderId, $id, $srRating, $srComment ?: null, 'seller']);
        mp_refresh_shop_rating($id);
        flash('Review submitted!', 'success');
        header('Location: shop.php?id=' . $id . '#reviews');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo sanitize($shop['shop_name']); ?> — AkuapemConnect Marketplace</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .sp-banner { height:180px; background:linear-gradient(135deg,var(--primary-soft,#d1fae5),#a7f3d0); position:relative; overflow:hidden; }
        .sp-banner img { width:100%; height:100%; object-fit:cover; }
        .sp-header { background:var(--surface); border-bottom:1px solid var(--border); padding:0 16px 14px; }
        .sp-logo-wrap { margin-top:10px; display:flex; align-items:flex-end; gap:14px; margin-bottom:10px; }
        .sp-logo { width:80px; height:80px; border-radius:14px; border:3px solid var(--surface); background:var(--primary-soft,#d1fae5); display:flex; align-items:center; justify-content:center; font-size:2rem; overflow:hidden; flex-shrink:0; }
        .sp-logo img { width:100%; height:100%; object-fit:cover; }
        .sp-name { font-size:1.2rem; font-weight:900; line-height:1.2; }
        .sp-meta { font-size:.78rem; color:var(--text-muted,#6b7280); }
        .sp-shell { max-width:1060px; margin:0 auto; padding:16px 16px 60px; }
        .mp-grid  { display:grid; grid-template-columns:repeat(auto-fill,minmax(175px,1fr)); gap:12px; }
        .mp-card  { background:var(--surface); border:1px solid var(--border); border-radius:12px; overflow:hidden; text-decoration:none; color:inherit; transition:box-shadow .15s; display:flex; flex-direction:column; }
        .mp-card:hover { box-shadow:0 4px 18px rgba(0,0,0,.1); }
        .mp-card-img { aspect-ratio:1/1; background:#f8fafc; display:flex; align-items:center; justify-content:center; overflow:hidden; }
        .mp-card-img img { width:100%; height:100%; object-fit:cover; }
        .mp-card-body { padding:9px 11px 11px; flex:1; display:flex; flex-direction:column; }
        .mp-card-name { font-weight:700; font-size:.84rem; overflow:hidden; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; }
        .mp-card-price { font-weight:900; font-size:.9rem; color:var(--primary,#0f766e); margin-top:auto; padding-top:6px; }
        .pd-star-full { color:#f59e0b; }
        .pd-star-empty { color:#e5e7eb; }
        @media(max-width:480px){ .mp-grid { grid-template-columns:repeat(2,1fr); } }
    </style>
</head>
<body class="<?php echo $user ? 'has-bottom-nav' : ''; ?>">

<header style="background:var(--surface);border-bottom:1px solid var(--border);padding:10px 120px 10px 16px;display:flex;align-items:center;justify-content:space-between;gap:8px;">
    <div style="display:flex;gap:8px;">
        <a href="marketplace.php" class="button button-secondary button-small">← Marketplace</a>
        <a href="shops.php" class="button button-secondary button-small">🏪 All Shops</a>
    </div>
    <?php if ($user): ?><a href="cart.php" class="button button-secondary button-small">🛒<?php echo $cartCount>0?" ($cartCount)":''; ?></a><?php else: ?><a href="login.php?redirect=<?php echo urlencode(current_request_path()); ?>" class="button button-secondary button-small">Sign in</a><?php endif; ?>
</header>

<?php if ($shop['banner_path']): ?>
<!-- Banner -->
<div class="sp-banner">
    <img src="<?php echo sanitize($shop['banner_path']); ?>" alt="">
</div>
<?php endif; ?>

<!-- Shop header -->
<div class="sp-header">
    <div class="sp-logo-wrap">
        <div class="sp-logo">
            <?php if ($shop['logo_path']): ?><img src="<?php echo sanitize($shop['logo_path']); ?>" alt=""><?php else: ?>🏪<?php endif; ?>
        </div>
        <div style="flex:1;min-width:0;">
            <div class="sp-name">
                <?php echo sanitize($shop['shop_name']); ?>
                <?php if ($shop['verification_status']==='approved'): ?><span style="background:#10b981;color:#fff;font-size:.6rem;font-weight:800;padding:2px 7px;border-radius:10px;margin-left:6px;">✓ VERIFIED</span><?php endif; ?>
                <?php if (!empty($shop['is_subscribed']) && !empty($shop['subscription_end']) && $shop['subscription_end'] >= date('Y-m-d')): ?><span style="background:#fef3c7;color:#92400e;font-size:.6rem;font-weight:800;padding:2px 7px;border-radius:10px;margin-left:4px;">⭐ PRO</span><?php endif; ?>
            </div>
            <div class="sp-meta">
                <?php if ($shop['rating']>0): ?>⭐ <?php echo number_format((float)$shop['rating'],1); ?><?php endif; ?>
                &nbsp;·&nbsp; <?php echo count($products); ?> products
                &nbsp;·&nbsp; <?php echo number_format($shop['total_sales']); ?> sales
                <?php if ($shop['region']): ?>&nbsp;·&nbsp; 📍 <?php echo sanitize($shop['region']); ?><?php endif; ?>
            </div>
        </div>
    </div>
    <?php if ($shop['description']): ?>
    <p style="margin:0;font-size:.85rem;color:var(--text-muted,#6b7280);max-width:600px;"><?php echo sanitize(mb_substr($shop['description'],0,200)); ?></p>
    <?php endif; ?>
    <div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap;">
        <?php if ($user): ?>
        <?php if ($shop['phone']): ?><a href="tel:<?php echo sanitize($shop['phone']); ?>" class="button button-secondary button-small">📞 Call</a><?php endif; ?>
        <?php if ($shop['email']): ?><a href="mailto:<?php echo sanitize($shop['email']); ?>" class="button button-secondary button-small">✉ Email</a><?php endif; ?>
        <?php elseif ($shop['phone'] || $shop['email']): ?>
        <a href="login.php?redirect=<?php echo urlencode(current_request_path()); ?>" class="button button-secondary button-small">🔒 Sign in to contact</a>
        <?php endif; ?>
        <?php if (!empty($shop['google_maps_link'])): ?><a href="<?php echo sanitize($shop['google_maps_link']); ?>" target="_blank" rel="noopener" class="button button-secondary button-small">📍 Pickup Location</a><?php endif; ?>
        <?php if ((!$user || (int)$user['id'] !== (int)$shop['user_id']) && mp_shop_can_receive_quotes($shop)): ?><a href="request_quote.php?shop_id=<?php echo $id; ?>" class="button button-primary button-small">📝 Request a Custom Order</a><?php endif; ?>
    </div>
    <div style="margin-top:10px;">
        <?php require __DIR__ . '/partials/share_buttons.php'; ?>
    </div>
</div>

<main class="sp-shell">

    <!-- Sort -->
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:8px;">
        <p style="margin:0;font-size:.78rem;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted,#6b7280);"><?php echo count($products); ?> Products</p>
        <form method="get" style="display:flex;gap:6px;align-items:center;">
            <input type="hidden" name="id" value="<?php echo $id; ?>">
            <select name="sort" onchange="this.form.submit()" style="font-size:.8rem;padding:5px 8px;border:1px solid var(--border);border-radius:8px;">
                <option value="newest"    <?php echo $sort==='newest'?'selected':''; ?>>Newest</option>
                <option value="featured"  <?php echo $sort==='featured'?'selected':''; ?>>Featured</option>
                <option value="price_asc" <?php echo $sort==='price_asc'?'selected':''; ?>>Price ↑</option>
                <option value="price_desc"<?php echo $sort==='price_desc'?'selected':''; ?>>Price ↓</option>
                <option value="popular"   <?php echo $sort==='popular'?'selected':''; ?>>Popular</option>
            </select>
        </form>
    </div>

    <?php if ($products): ?>
    <div class="mp-grid">
        <?php foreach ($products as $p): ?>
        <a href="product.php?id=<?php echo $p['id']; ?>" class="mp-card">
            <div class="mp-card-img">
                <?php if ($p['primary_image']): ?><img src="<?php echo sanitize($p['primary_image']); ?>" alt=""><?php else: ?><span style="font-size:2rem;opacity:.3;"><?php echo $p['cat_icon']??'📦'; ?></span><?php endif; ?>
            </div>
            <div class="mp-card-body">
                <div class="mp-card-name"><?php echo sanitize($p['name']); ?></div>
                <div class="mp-card-price">GH&#8373; <?php echo number_format(mp_effective_price($p),2); ?></div>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div style="text-align:center;padding:48px;color:var(--text-muted,#6b7280);">No products listed yet.</div>
    <?php endif; ?>

    <!-- Shop reviews -->
    <div id="reviews" style="margin-top:28px;">
        <p style="font-size:.76rem;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted,#6b7280);margin:0 0 12px;">Shop Reviews (<?php echo count($shopReviews); ?>)</p>

        <?php if ($canReviewShop): ?>
        <div style="background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:16px;margin-bottom:14px;">
            <p style="font-weight:700;margin:0 0 10px;font-size:.9rem;">Leave a Review</p>
            <?php if ($shopReviewError): ?><div class="alert alert-error"><?php echo sanitize($shopReviewError); ?></div><?php endif; ?>
            <form method="post" action="shop.php?id=<?php echo $id; ?>#reviews" onsubmit="return document.querySelector('input[name=rating]:checked') || (alert('Select a rating'), false);">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="form" value="shop_review">
                <div class="form-group">
                    <label style="font-weight:600;font-size:.85rem;">Rating *</label>
                    <?php echo render_stars(0, true, 'rating'); ?>
                </div>
                <div class="form-group">
                    <label style="font-weight:600;font-size:.85rem;">Comment (optional)</label>
                    <textarea name="comment" rows="2" placeholder="How was this seller?"></textarea>
                </div>
                <button type="submit" class="button button-primary button-small">Submit Review</button>
            </form>
        </div>
        <?php endif; ?>

        <?php if ($shopReviews): ?>
        <?php foreach ($shopReviews as $r): ?>
        <div style="background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:12px 14px;margin-bottom:8px;">
            <div style="display:flex;gap:8px;align-items:center;margin-bottom:6px;">
                <div style="width:28px;height:28px;border-radius:50%;background:var(--primary-soft,#d1fae5);display:flex;align-items:center;justify-content:center;font-size:.7rem;font-weight:800;color:var(--primary,#0f766e);flex-shrink:0;"><?php echo strtoupper(substr($r['reviewer_name'],0,1)); ?></div>
                <div style="flex:1;font-weight:700;font-size:.86rem;"><?php echo sanitize($r['reviewer_name']); ?></div>
                <span style="font-size:.72rem;color:var(--text-muted,#6b7280);"><?php echo time_ago($r['created_at']); ?></span>
            </div>
            <div><?php echo str_repeat('<span class="pd-star-full">★</span>',$r['rating']); ?><?php echo str_repeat('<span class="pd-star-empty">★</span>',5-$r['rating']); ?></div>
            <?php if ($r['comment']): ?><p style="margin:6px 0 0;font-size:.84rem;"><?php echo sanitize($r['comment']); ?></p><?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php else: ?>
        <div style="text-align:center;padding:20px;color:var(--text-muted,#6b7280);font-size:.85rem;">No reviews yet. Be the first!</div>
        <?php endif; ?>
    </div>

</main>

<?php if ($user): require_once __DIR__ . '/partials/bottom_nav.php'; endif; ?>
</body>
</html>
