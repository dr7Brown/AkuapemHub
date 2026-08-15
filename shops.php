<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/marketplace_functions.php';

require_module_enabled('mp', 'Marketplace');

$user      = current_user();
$cartCount = $user ? mp_get_cart_count((int)$user['id']) : 0;

$q       = trim($_GET['q'] ?? '');
$region  = trim($_GET['region'] ?? '');
$sort    = $_GET['sort'] ?? 'featured';
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 24;
$offset  = ($page - 1) * $perPage;

// ms.market_id IS NULL — a market's hidden "system shop" isn't a real
// storefront, it exists only as the FK anchor custom orders need.
$where  = ["ms.status = 'active'", "ms.market_id IS NULL", "u.banned = 0"];
$params = [];

if ($q !== '') {
    $where[]  = '(ms.shop_name LIKE ? OR ms.description LIKE ?)';
    $like = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
}
if ($region !== '') {
    $where[]  = 'ms.region LIKE ?';
    $params[] = '%' . $region . '%';
}

$orderBy = match($sort) {
    'newest'   => 'ms.created_at DESC',
    'rating'   => 'ms.rating DESC, ms.total_sales DESC',
    'popular'  => 'ms.view_count DESC',
    'products' => 'product_count DESC',
    default    => '(ms.is_featured=1 AND (ms.featured_end IS NULL OR ms.featured_end>=CURDATE())) DESC, ms.rating DESC, ms.created_at DESC',
};

$whereClause = implode(' AND ', $where);

$countSt = $pdo->prepare("SELECT COUNT(*) FROM mp_shops ms JOIN users u ON ms.user_id = u.id WHERE $whereClause");
$countSt->execute($params);
$total      = (int)$countSt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));

$st = $pdo->prepare(
    "SELECT ms.*,
            (SELECT COUNT(*) FROM mp_products mp WHERE mp.shop_id = ms.id AND mp.status = 'approved') AS product_count
     FROM mp_shops ms
     JOIN users u ON ms.user_id = u.id
     WHERE $whereClause
     ORDER BY $orderBy
     LIMIT $perPage OFFSET $offset"
);
$st->execute($params);
$shops = $st->fetchAll();

// Regions with at least one active shop, for the filter dropdown
$regions = $pdo->query(
    "SELECT DISTINCT ms.region FROM mp_shops ms JOIN users u ON ms.user_id = u.id
     WHERE ms.status='active' AND ms.market_id IS NULL AND u.banned=0 AND ms.region IS NOT NULL AND ms.region != ''
     ORDER BY ms.region ASC"
)->fetchAll(PDO::FETCH_COLUMN);

function shops_page_url(int $page): string {
    $p = $_GET; $p['page'] = $page;
    return 'shops.php?' . http_build_query($p);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php echo seo_meta([
        'title'       => 'Shops — Browse Sellers in the Akuapem Area | ' . APP_NAME,
        'description' => 'Browse every shop selling on ' . APP_NAME . ' across the Akuapem area of Ghana.',
        'url'         => rtrim(BASE_URL, '/') . '/shops.php',
        'noindex'     => !empty($_GET['q']) || !empty($_GET['page']),
    ]); ?>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .mp-topbar { background:var(--surface); border-bottom:1px solid var(--border); padding:12px 120px 12px 16px; display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; }
        .mp-brand  { font-weight:900; font-size:1.05rem; color:var(--primary,#0f766e); text-decoration:none; display:flex; align-items:center; gap:6px; }
        .mp-nav-actions { display:flex; gap:8px; align-items:center; }
        .mp-cart-btn { position:relative; text-decoration:none; }
        .mp-cart-count { position:absolute; top:-6px; right:-8px; background:#ef4444; color:#fff; font-size:.62rem; font-weight:800; padding:1px 5px; border-radius:10px; min-width:16px; text-align:center; }

        .mp-shell { max-width:1200px; margin:0 auto; padding:16px 16px 60px; }
        .mp-search-row { display:flex; gap:8px; margin-bottom:16px; }
        .mp-search-row input { flex:1; }
        .mp-search-row button { flex-shrink:0; }
        .mp-filters { display:flex; gap:8px; margin-bottom:16px; flex-wrap:wrap; align-items:center; }
        .mp-filters select, .mp-filters input[type=text] { padding:6px 10px; border-radius:8px; border:1px solid var(--border); font-size:.82rem; background:var(--surface); }
        .mp-results-head { display:flex; align-items:center; justify-content:space-between; margin-bottom:12px; flex-wrap:wrap; gap:8px; }
        .mp-results-count { font-size:.82rem; color:var(--text-muted,#6b7280); }

        .sh-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr)); gap:14px; }
        .sh-card { background:var(--surface); border:1px solid var(--border); border-radius:14px; overflow:hidden; text-decoration:none; color:inherit; display:flex; flex-direction:column; transition:box-shadow .15s,transform .15s; }
        .sh-card:hover { box-shadow:0 6px 24px rgba(0,0,0,.1); transform:translateY(-2px); }
        .sh-card.featured { border:2px solid var(--primary,#0f766e); }
        .sh-banner { height:64px; background:linear-gradient(135deg,#f8fafc,#f1f5f9); overflow:hidden; }
        .sh-banner img { width:100%; height:100%; object-fit:cover; }
        .sh-body { padding:0 14px 14px; position:relative; }
        .sh-logo { width:56px; height:56px; border-radius:14px; background:var(--surface); border:3px solid var(--surface); margin-top:-28px; overflow:hidden; display:flex; align-items:center; justify-content:center; font-size:1.6rem; box-shadow:0 2px 8px rgba(0,0,0,.12); }
        .sh-logo img { width:100%; height:100%; object-fit:cover; }
        .sh-name { font-weight:800; font-size:.92rem; margin:8px 0 2px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .sh-badges { display:flex; gap:4px; flex-wrap:wrap; margin-bottom:4px; }
        .sh-badge { font-size:.6rem; font-weight:800; padding:2px 7px; border-radius:10px; }
        .sh-meta { font-size:.76rem; color:var(--text-muted,#6b7280); }
        .sh-stats { display:flex; gap:10px; margin-top:8px; font-size:.76rem; color:var(--text-muted,#6b7280); }
        @media(max-width:480px){ .sh-grid { grid-template-columns:repeat(2,1fr); } }

        .mp-pages { display:flex; gap:4px; flex-wrap:wrap; align-items:center; margin-top:20px; }
        .mp-page { padding:6px 11px; border-radius:8px; border:1px solid var(--border); text-decoration:none; font-size:.82rem; color:var(--text); }
        .mp-page.active { background:var(--primary,#0f766e); color:#fff; border-color:var(--primary,#0f766e); }
    </style>
</head>
<body class="<?php echo $user ? 'has-bottom-nav' : ''; ?>">

<header class="mp-topbar">
    <a href="index.php" style="font-weight:900;color:var(--primary,#0f766e);text-decoration:none;font-size:1.05rem;">← Back Home</a>
    <a href="marketplace.php" class="mp-brand" style="font-size:.88rem;">/ 🏪 Shops</a>
    <div class="mp-nav-actions">
        <a href="marketplace.php" class="button button-secondary button-small">🛍️ Products</a>
        <?php if ($user): ?>
        <a href="cart.php" class="mp-cart-btn button button-secondary button-small">
            🛒 Cart<?php if ($cartCount > 0): ?><span class="mp-cart-count"><?php echo $cartCount; ?></span><?php endif; ?>
        </a>
        <a href="seller_dashboard.php" class="button button-primary button-small">My Shop</a>
        <?php else: ?>
        <a href="login.php?redirect=<?php echo urlencode(current_request_path()); ?>" class="button button-secondary button-small">Sign in</a>
        <a href="register.php" class="button button-primary button-small">Join</a>
        <?php endif; ?>
    </div>
</header>

<main class="mp-shell">

    <!-- Search -->
    <form method="get" action="shops.php" class="mp-search-row">
        <input type="text" name="q" value="<?php echo sanitize($q); ?>" placeholder="Search shops…">
        <button type="submit" class="button button-primary">Search</button>
        <?php if ($q || $region): ?><a href="shops.php" class="button button-secondary">Clear</a><?php endif; ?>
    </form>

    <!-- Filters -->
    <form method="get" action="shops.php" class="mp-filters">
        <?php if ($q): ?><input type="hidden" name="q" value="<?php echo sanitize($q); ?>"><?php endif; ?>

        <select name="region" onchange="this.form.submit()">
            <option value="">All Regions/Towns</option>
            <?php foreach ($regions as $r): ?>
            <option value="<?php echo sanitize($r); ?>" <?php echo $region===$r?'selected':''; ?>><?php echo sanitize($r); ?></option>
            <?php endforeach; ?>
        </select>

        <select name="sort" onchange="this.form.submit()">
            <option value="featured" <?php echo $sort==='featured'?'selected':''; ?>>Featured First</option>
            <option value="newest"   <?php echo $sort==='newest'?'selected':''; ?>>Newest</option>
            <option value="rating"   <?php echo $sort==='rating'?'selected':''; ?>>Top Rated</option>
            <option value="popular"  <?php echo $sort==='popular'?'selected':''; ?>>Most Viewed</option>
            <option value="products" <?php echo $sort==='products'?'selected':''; ?>>Most Products</option>
        </select>

        <button type="submit" class="button button-secondary button-small">Filter</button>
    </form>

    <!-- Results header -->
    <div class="mp-results-head">
        <span class="mp-results-count">
            <?php echo number_format($total); ?> shop<?php echo $total !== 1 ? 's' : ''; ?>
            <?php if ($region): ?> in <strong><?php echo sanitize($region); ?></strong><?php endif; ?>
            <?php if ($q): ?> for "<strong><?php echo sanitize($q); ?></strong>"<?php endif; ?>
        </span>
        <?php if ($user): ?>
        <a href="seller_dashboard.php" style="font-size:.8rem;font-weight:700;color:var(--primary,#0f766e);text-decoration:none;">+ Open a Shop</a>
        <?php endif; ?>
    </div>

    <!-- Shop grid -->
    <?php if ($shops): ?>
    <div class="sh-grid">
        <?php foreach ($shops as $s):
            $isFeatured = $s['is_featured'] && (!$s['featured_end'] || $s['featured_end'] >= date('Y-m-d'));
            $isPro      = !empty($s['is_subscribed']) && !empty($s['subscription_end']) && $s['subscription_end'] >= date('Y-m-d');
        ?>
        <a href="shop.php?id=<?php echo $s['id']; ?>" class="sh-card <?php echo $isFeatured ? 'featured' : ''; ?>">
            <div class="sh-banner">
                <?php if ($s['banner_path']): ?><img src="<?php echo sanitize($s['banner_path']); ?>" alt=""><?php endif; ?>
            </div>
            <div class="sh-body">
                <div class="sh-logo">
                    <?php if ($s['logo_path']): ?><img src="<?php echo sanitize($s['logo_path']); ?>" alt=""><?php else: ?>🏪<?php endif; ?>
                </div>
                <div class="sh-name"><?php echo sanitize($s['shop_name']); ?></div>
                <div class="sh-badges">
                    <?php if ($isFeatured): ?><span class="sh-badge" style="background:var(--primary-soft,#d1fae5);color:var(--primary,#0f766e);">⭐ Featured</span><?php endif; ?>
                    <?php if ($s['verification_status']==='approved'): ?><span class="sh-badge" style="background:#d1fae5;color:#065f46;">✓ Verified</span><?php endif; ?>
                    <?php if ($isPro): ?><span class="sh-badge" style="background:#fef3c7;color:#92400e;">⭐ PRO</span><?php endif; ?>
                </div>
                <?php if ($s['region']): ?><div class="sh-meta">📍 <?php echo sanitize($s['region']); ?></div><?php endif; ?>
                <div class="sh-stats">
                    <?php if ($s['rating'] > 0): ?><span>⭐ <?php echo number_format((float)$s['rating'],1); ?></span><?php endif; ?>
                    <span><?php echo number_format((int)$s['product_count']); ?> products</span>
                    <span><?php echo number_format((int)$s['total_sales']); ?> sales</span>
                </div>
            </div>
        </a>
        <?php endforeach; ?>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="mp-pages">
        <?php if ($page > 1): ?><a href="<?php echo shops_page_url($page-1); ?>" class="mp-page">← Prev</a><?php endif; ?>
        <?php for ($i = max(1,$page-2); $i <= min($totalPages,$page+2); $i++): ?>
        <a href="<?php echo shops_page_url($i); ?>" class="mp-page <?php echo $i===$page?'active':''; ?>"><?php echo $i; ?></a>
        <?php endfor; ?>
        <?php if ($page < $totalPages): ?><a href="<?php echo shops_page_url($page+1); ?>" class="mp-page">Next →</a><?php endif; ?>
    </div>
    <?php endif; ?>

    <?php else: ?>
    <div style="text-align:center;padding:60px 20px;color:var(--text-muted,#6b7280);">
        <div style="font-size:3rem;opacity:.4;margin-bottom:14px;">🏪</div>
        <p style="margin:0 0 16px;">No shops found<?php echo $q || $region ? ' matching your search' : ''; ?>.</p>
        <?php if ($user): ?><a href="seller_dashboard.php" class="button button-primary">Be the first to open a shop →</a><?php endif; ?>
    </div>
    <?php endif; ?>

</main>

<?php require __DIR__ . '/partials/site_footer.php'; ?>
<?php if ($user): require_once __DIR__ . '/partials/bottom_nav.php'; endif; ?>
</body>
</html>
