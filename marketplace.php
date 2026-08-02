<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/marketplace_functions.php';

require_module_enabled('mp', 'Marketplace');

$user     = current_user();
$flash    = get_flash();
$cartCount= $user ? mp_get_cart_count((int)$user['id']) : 0;

// ── Filters ────────────────────────────────────────────────────────────────
$q         = trim($_GET['q']         ?? '');
$catSlug   = trim($_GET['cat']       ?? '');
$condition = trim($_GET['condition'] ?? '');
$minPrice  = (float)($_GET['min'] ?? 0);
$maxPrice  = (float)($_GET['max'] ?? 0);
$town      = trim($_GET['town']      ?? '');
$sort      = $_GET['sort']           ?? get_platform_setting('mp_default_sort', 'default');
$page      = max(1, (int)($_GET['page'] ?? 1));
$perPage   = 24;
$offset    = ($page - 1) * $perPage;

// ── Categories ─────────────────────────────────────────────────────────────
// Rarely changes (admin-managed), fetched on every single page load — a
// cheap, high-value APCu cache candidate.
$categories = apcu_remember('mp_categories_list_v1', 300, function () use ($pdo) {
    return $pdo->query('SELECT * FROM mp_categories ORDER BY sort_order, name')->fetchAll();
});
$activeCat  = null;
foreach ($categories as $c) { if ($c['slug'] === $catSlug) { $activeCat = $c; break; } }

// ── Build query ────────────────────────────────────────────────────────────
$where  = ["mp.status = 'approved'", "ms.status = 'active'", "ms.user_id NOT IN (SELECT id FROM users WHERE banned=1)"];
$params = [];

if ($q !== '') {
    $where[]  = '(mp.name LIKE ? OR mp.description LIKE ? OR ms.shop_name LIKE ?)';
    $like = '%' . $q . '%';
    $params = array_merge($params, [$like, $like, $like]);
}
if ($activeCat) {
    $where[] = 'mp.category_id = ?';
    $params[] = $activeCat['id'];
}
if ($condition && in_array($condition, ['new','used','refurbished'], true)) {
    $where[] = 'mp.condition_type = ?';
    $params[] = $condition;
}
if ($minPrice > 0) { $where[] = 'COALESCE(mp.discount_price, mp.price) >= ?'; $params[] = $minPrice; }
if ($maxPrice > 0) { $where[] = 'COALESCE(mp.discount_price, mp.price) <= ?'; $params[] = $maxPrice; }
if ($town !== '') {
    $where[] = 'ms.region LIKE ?';
    $params[] = '%' . $town . '%';
}

// 'default' — the blended "smart" listing order: featured/sponsored pinned to
// the top, then a daily-reshuffled mix of recently-posted and popular items,
// then everything else. RAND() is seeded by the day number (not per-request)
// so pagination stays stable within a day while the mix still varies day to
// day, instead of the same items being permanently stuck in the same order.
// The "popular" cutoff (average view count across all approved products) is
// cached — it was previously a subquery re-run on every single request that
// used this sort, which is the majority of traffic once 'default' is the
// platform's own default.
$avgViewCount = (float)apcu_remember('mp_avg_view_count_v1', 60, function () use ($pdo) {
    return $pdo->query("SELECT AVG(view_count) FROM mp_products WHERE status='approved'")->fetchColumn();
});
$defaultOrderBy =
    "(CASE
        WHEN mp.is_sponsored=1 AND mp.sponsored_end>=CURDATE() THEN 3
        WHEN mp.is_featured=1 AND mp.featured_end>=CURDATE() THEN 2
        WHEN mp.created_at >= NOW() - INTERVAL 14 DAY
          OR mp.view_count >= $avgViewCount THEN 1
        ELSE 0
      END) DESC, RAND(TO_DAYS(CURDATE()))";

$orderBy = match($sort) {
    'price_asc'  => 'COALESCE(mp.discount_price,mp.price) ASC',
    'price_desc' => 'COALESCE(mp.discount_price,mp.price) DESC',
    'newest'     => 'mp.created_at DESC',
    'popular'    => 'mp.view_count DESC',
    'featured'   => '(CASE WHEN mp.is_sponsored=1 AND mp.sponsored_end>=CURDATE() THEN 2 WHEN mp.is_featured=1 AND mp.featured_end>=CURDATE() THEN 1 ELSE 0 END) DESC, mp.created_at DESC',
    default      => $defaultOrderBy, // covers 'default' and any unrecognized value
};

$whereClause = implode(' AND ', $where);

// The plain, unfiltered browse (no search/category/condition/price/town) is
// the overwhelming majority of Marketplace traffic — everyone landing on the
// page fresh. That case has only sort+page as inputs, so it's cached as a
// unit (count + rows together) for a short window; any filter at all falls
// back to a live, uncached query since the filter-combination space is
// unbounded and each one is comparatively rare.
$noFiltersApplied = $q === '' && !$activeCat && $condition === '' && $minPrice <= 0 && $maxPrice <= 0 && $town === '';

$fetchListing = function () use ($pdo, $whereClause, $params, $orderBy, $perPage, $offset) {
    $countSt = $pdo->prepare("SELECT COUNT(*) FROM mp_products mp JOIN mp_shops ms ON mp.shop_id=ms.id WHERE $whereClause");
    $countSt->execute($params);
    $total = (int)$countSt->fetchColumn();

    $st = $pdo->prepare(
        "SELECT mp.*, ms.shop_name, ms.slug AS shop_slug, ms.id AS shop_id,
                mc.name AS cat_name, mc.icon AS cat_icon,
                mpi.image_path AS primary_image
         FROM mp_products mp
         JOIN mp_shops ms ON mp.shop_id = ms.id
         LEFT JOIN mp_categories mc ON mp.category_id = mc.id
         LEFT JOIN mp_product_images mpi ON mpi.product_id = mp.id AND mpi.is_primary = 1
         WHERE $whereClause
         ORDER BY $orderBy
         LIMIT $perPage OFFSET $offset"
    );
    $st->execute($params);
    return ['total' => $total, 'products' => $st->fetchAll()];
};

$listing = $noFiltersApplied
    ? apcu_remember("mp_listing_v1:$sort:$page", 45, $fetchListing)
    : $fetchListing();

$total      = $listing['total'];
$products   = $listing['products'];
$totalPages = max(1, (int)ceil($total / $perPage));

// URL helper for pagination
function mp_page_url(int $page): string {
    $p = $_GET; $p['page'] = $page;
    return 'marketplace.php?' . http_build_query($p);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php echo seo_meta([
        'title'       => 'Marketplace — Buy & Sell in the Akuapem Area | ' . APP_NAME,
        'description' => 'Browse products from local shops and sellers across the Akuapem area of Ghana, or open your own shop and start selling on ' . APP_NAME . '.',
        'url'         => rtrim(BASE_URL, '/') . '/marketplace.php',
        'noindex'     => !empty($_GET['q']) || !empty($_GET['page']),
    ]); ?>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .mp-topbar { background:var(--surface); border-bottom:1px solid var(--border); padding:12px 64px 12px 16px; display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; }
        .mp-brand  { font-weight:900; font-size:1.05rem; color:var(--primary,#0f766e); text-decoration:none; display:flex; align-items:center; gap:6px; }
        .mp-nav-actions { display:flex; gap:8px; align-items:center; }
        .mp-cart-btn { position:relative; text-decoration:none; }
        .mp-cart-count { position:absolute; top:-6px; right:-8px; background:#ef4444; color:#fff; font-size:.62rem; font-weight:800; padding:1px 5px; border-radius:10px; min-width:16px; text-align:center; }

        .mp-shell { max-width:1200px; margin:0 auto; padding:16px 16px 60px; }

        /* Search bar */
        .mp-search-row { display:flex; gap:8px; margin-bottom:16px; }
        .mp-search-row input { flex:1; }
        .mp-search-row button { flex-shrink:0; }

        /* Categories */
        .mp-cats { display:flex; gap:6px; overflow-x:auto; padding-bottom:4px; margin-bottom:16px; scrollbar-width:none; }
        .mp-cats::-webkit-scrollbar { display:none; }
        .mp-cat { display:inline-flex; align-items:center; gap:5px; padding:6px 12px; border-radius:20px; border:1px solid var(--border); font-size:.8rem; font-weight:700; text-decoration:none; color:var(--text-muted,#6b7280); background:var(--surface); white-space:nowrap; transition:all .1s; }
        .mp-cat:hover, .mp-cat.active { background:var(--primary-soft,#d1fae5); border-color:var(--primary,#0f766e); color:var(--primary,#0f766e); }

        /* Filters row */
        .mp-filters { display:flex; gap:8px; margin-bottom:16px; flex-wrap:wrap; align-items:center; }
        .mp-filters select, .mp-filters input[type=number] { padding:6px 10px; border-radius:8px; border:1px solid var(--border); font-size:.82rem; background:var(--surface); }

        /* Results header */
        .mp-results-head { display:flex; align-items:center; justify-content:space-between; margin-bottom:12px; flex-wrap:wrap; gap:8px; }
        .mp-results-count { font-size:.82rem; color:var(--text-muted,#6b7280); }

        /* Product grid */
        .mp-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(190px,1fr)); gap:14px; }

        /* Product card */
        .mp-card { background:var(--surface); border:1px solid var(--border); border-radius:14px; overflow:hidden; text-decoration:none; color:inherit; display:flex; flex-direction:column; transition:box-shadow .15s,transform .15s; }
        .mp-card:hover { box-shadow:0 6px 24px rgba(0,0,0,.1); transform:translateY(-2px); }
        .mp-card.sponsored { border:2px solid #f59e0b; }
        .mp-card.featured  { border:2px solid var(--primary,#0f766e); }
        .mp-card-img { aspect-ratio:1/1; background:linear-gradient(135deg,#f8fafc,#f1f5f9); overflow:hidden; display:flex; align-items:center; justify-content:center; position:relative; flex-shrink:0; }
        .mp-card-img img { width:100%; height:100%; object-fit:cover; }
        .mp-card-img-icon { font-size:2.5rem; opacity:.3; }
        .mp-card-badges { position:absolute; top:6px; left:6px; display:flex; flex-direction:column; gap:3px; }
        .mp-card-badge { font-size:.6rem; font-weight:800; padding:2px 6px; border-radius:10px; }
        .mp-card-body { padding:10px 12px 12px; flex:1; display:flex; flex-direction:column; }
        .mp-card-name { font-weight:700; font-size:.86rem; line-height:1.4; margin:0 0 4px; overflow:hidden; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; }
        .mp-card-shop { font-size:.72rem; color:var(--text-muted,#6b7280); margin-bottom:6px; }
        .mp-card-price { font-weight:900; font-size:.95rem; color:var(--primary,#0f766e); margin-top:auto; }
        .mp-card-orig  { font-size:.76rem; color:var(--text-muted,#6b7280); text-decoration:line-through; margin-left:4px; }
        .mp-card-cond  { font-size:.68rem; font-weight:700; padding:2px 6px; border-radius:10px; display:inline-block; margin-bottom:4px; }
        .mp-card-foot  { display:flex; align-items:center; justify-content:space-between; margin-top:8px; padding-top:8px; border-top:1px solid var(--border); }

        /* Pagination */
        .mp-pages { display:flex; gap:6px; justify-content:center; margin-top:24px; flex-wrap:wrap; }
        .mp-page  { padding:6px 12px; border-radius:8px; border:1px solid var(--border); font-size:.82rem; font-weight:700; text-decoration:none; color:var(--text-muted,#6b7280); }
        .mp-page.active { background:var(--primary,#0f766e); color:#fff; border-color:var(--primary,#0f766e); }
        .mp-page:hover  { border-color:var(--primary,#0f766e); color:var(--primary,#0f766e); }

        @media(max-width:480px){ .mp-grid { grid-template-columns:repeat(2,1fr); } }
    </style>
</head>
<body class="<?php echo $user ? 'has-bottom-nav' : ''; ?>">

<!-- Header -->
<header class="mp-topbar">
    <a href="index.php" style="font-weight:900;color:var(--primary,#0f766e);text-decoration:none;font-size:1.05rem;"><?php echo APP_NAME; ?></a>
    <a href="marketplace.php" class="mp-brand" style="font-size:.88rem;">/ 🛍️ Marketplace</a>
    <div class="mp-nav-actions">
        <a href="shops.php" class="button button-secondary button-small">🏪 Shops</a>
        <?php if ($user): ?>
        <a href="cart.php" class="mp-cart-btn button button-secondary button-small">
            🛒 Cart<?php if ($cartCount > 0): ?><span class="mp-cart-count"><?php echo $cartCount; ?></span><?php endif; ?>
        </a>
        <a href="seller_dashboard.php" class="button button-primary button-small">My Shop</a>
        <?php else: ?>
        <a href="login.php" class="button button-secondary button-small">Sign in</a>
        <a href="register.php" class="button button-primary button-small">Join</a>
        <?php endif; ?>
    </div>
</header>

<?php if ($flash): ?>
<div class="alert alert-<?php echo sanitize($flash['type']); ?>" style="margin:10px 16px 0;"><?php echo sanitize($flash['message']); ?></div>
<?php endif; ?>

<main class="mp-shell">

    <!-- Search -->
    <form method="get" action="marketplace.php" class="mp-search-row">
        <?php if ($catSlug): ?><input type="hidden" name="cat" value="<?php echo sanitize($catSlug); ?>"><?php endif; ?>
        <input type="text" name="q" value="<?php echo sanitize($q); ?>" placeholder="Search products, shops…">
        <button type="submit" class="button button-primary">Search</button>
        <?php if ($q || $catSlug || $condition || $minPrice || $maxPrice): ?>
        <a href="marketplace.php" class="button button-secondary">Clear</a>
        <?php endif; ?>
    </form>

    <!-- Category pills -->
    <div class="mp-cats">
        <a href="marketplace.php<?php echo $q ? '?q='.urlencode($q) : ''; ?>" class="mp-cat <?php echo !$catSlug?'active':''; ?>">All</a>
        <?php foreach ($categories as $c): ?>
        <a href="marketplace.php?cat=<?php echo urlencode($c['slug']); ?><?php echo $q?'&q='.urlencode($q):''; ?>"
           class="mp-cat <?php echo $catSlug===$c['slug']?'active':''; ?>">
            <?php echo $c['icon'] ? sanitize($c['icon']).' ' : ''; ?><?php echo sanitize($c['name']); ?>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- Filters -->
    <form method="get" action="marketplace.php" class="mp-filters">
        <?php if ($catSlug): ?><input type="hidden" name="cat" value="<?php echo sanitize($catSlug); ?>"><?php endif; ?>
        <?php if ($q): ?><input type="hidden" name="q" value="<?php echo sanitize($q); ?>"><?php endif; ?>

        <select name="condition" onchange="this.form.submit()">
            <option value="">All Conditions</option>
            <?php foreach (['new'=>'New','used'=>'Used','refurbished'=>'Refurbished'] as $v=>$l): ?>
            <option value="<?php echo $v; ?>" <?php echo $condition===$v?'selected':''; ?>><?php echo $l; ?></option>
            <?php endforeach; ?>
        </select>

        <input type="number" name="min" value="<?php echo $minPrice ?: ''; ?>" placeholder="Min GHS" min="0" step="0.01" style="width:90px;">
        <input type="number" name="max" value="<?php echo $maxPrice ?: ''; ?>" placeholder="Max GHS" min="0" step="0.01" style="width:90px;">

        <select name="sort" onchange="this.form.submit()">
            <option value="default"    <?php echo $sort==='default'?'selected':''; ?>>Default</option>
            <option value="featured"   <?php echo $sort==='featured'?'selected':''; ?>>Featured First</option>
            <option value="newest"     <?php echo $sort==='newest'?'selected':''; ?>>Newest</option>
            <option value="price_asc"  <?php echo $sort==='price_asc'?'selected':''; ?>>Price: Low → High</option>
            <option value="price_desc" <?php echo $sort==='price_desc'?'selected':''; ?>>Price: High → Low</option>
            <option value="popular"    <?php echo $sort==='popular'?'selected':''; ?>>Most Viewed</option>
        </select>

        <button type="submit" class="button button-secondary button-small">Filter</button>
    </form>

    <!-- Results header -->
    <div class="mp-results-head">
        <span class="mp-results-count">
            <?php echo number_format($total); ?> product<?php echo $total !== 1 ? 's' : ''; ?>
            <?php if ($activeCat): ?> in <strong><?php echo sanitize($activeCat['icon'].' '.$activeCat['name']); ?></strong><?php endif; ?>
            <?php if ($q): ?> for "<strong><?php echo sanitize($q); ?></strong>"<?php endif; ?>
        </span>
        <?php if ($user): ?>
        <a href="seller_dashboard.php" style="font-size:.8rem;font-weight:700;color:var(--primary,#0f766e);text-decoration:none;">+ Sell on Marketplace</a>
        <?php endif; ?>
    </div>

    <!-- Product grid -->
    <?php if ($products): ?>
    <div class="mp-grid">
        <?php foreach ($products as $p):
            $effPrice   = mp_effective_price($p);
            $discPct    = mp_discount_pct($p);
            $isSponsored= $p['is_sponsored'] && !empty($p['sponsored_end']) && $p['sponsored_end'] >= date('Y-m-d');
            $isFeatured = $p['is_featured']  && !empty($p['featured_end'])  && $p['featured_end']  >= date('Y-m-d');
        ?>
        <a href="product.php?id=<?php echo $p['id']; ?>" class="mp-card <?php echo $isSponsored?'sponsored':($isFeatured?'featured':''); ?>">
            <div class="mp-card-img">
                <?php if ($p['primary_image']): ?>
                    <img src="<?php echo sanitize($p['primary_image']); ?>" alt="<?php echo sanitize($p['name']); ?>">
                <?php else: ?>
                    <span class="mp-card-img-icon"><?php echo $p['cat_icon'] ?? '📦'; ?></span>
                <?php endif; ?>
                <div class="mp-card-badges">
                    <?php if ($isSponsored): ?><span class="mp-card-badge" style="background:#f59e0b;color:#fff;">SPONSORED</span><?php endif; ?>
                    <?php if ($isFeatured && !$isSponsored): ?><span class="mp-card-badge" style="background:var(--primary,#0f766e);color:#fff;">FEATURED</span><?php endif; ?>
                    <?php if ($discPct >= 10): ?><span class="mp-card-badge" style="background:#ef4444;color:#fff;">-<?php echo $discPct; ?>%</span><?php endif; ?>
                </div>
            </div>
            <div class="mp-card-body">
                <span class="mp-card-cond" style="background:<?php echo ['new'=>'#d1fae5','used'=>'#fef3c7','refurbished'=>'#dbeafe'][$p['condition_type']]??'#f3f4f6'; ?>;color:<?php echo mp_condition_color($p['condition_type']); ?>;"><?php echo mp_condition_label($p['condition_type']); ?></span>
                <div class="mp-card-name"><?php echo sanitize($p['name']); ?></div>
                <div class="mp-card-shop">🏪 <?php echo sanitize(mb_substr($p['shop_name'],0,30)); ?></div>
                <div class="mp-card-price">
                    GH&#8373; <?php echo number_format($effPrice, 2); ?>
                    <?php if ($discPct > 0): ?><span class="mp-card-orig">GH&#8373; <?php echo number_format((float)$p['price'],2); ?></span><?php endif; ?>
                </div>
            </div>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="mp-pages">
        <?php if ($page > 1): ?><a href="<?php echo mp_page_url($page-1); ?>" class="mp-page">← Prev</a><?php endif; ?>
        <?php for ($i = max(1,$page-2); $i <= min($totalPages,$page+2); $i++): ?>
        <a href="<?php echo mp_page_url($i); ?>" class="mp-page <?php echo $i===$page?'active':''; ?>"><?php echo $i; ?></a>
        <?php endfor; ?>
        <?php if ($page < $totalPages): ?><a href="<?php echo mp_page_url($page+1); ?>" class="mp-page">Next →</a><?php endif; ?>
    </div>
    <?php endif; ?>

    <?php else: ?>
    <div style="text-align:center;padding:60px 20px;color:var(--text-muted,#6b7280);">
        <div style="font-size:3rem;opacity:.4;margin-bottom:14px;">🛍️</div>
        <p style="margin:0 0 16px;">No products found<?php echo ($q||$catSlug) ? ' matching your search' : ''; ?>.</p>
        <?php if ($user): ?><a href="seller_dashboard.php" class="button button-primary">Be the first to sell →</a><?php endif; ?>
    </div>
    <?php endif; ?>

</main>

<?php if ($user): require_once __DIR__ . '/partials/bottom_nav.php'; endif; ?>
</body>
</html>
