<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/marketplace_functions.php';

require_module_enabled('mp', 'Marketplace');
require_login();
$user = current_user();
$shop = get_active_seller_shop((int)$user['id']);

if (!$shop) {
    flash('Create your shop first.', 'warning');
    header('Location: seller_dashboard.php?tab=setup');
    exit;
}

$catalogTypes = $pdo->query('SELECT slug, name FROM catalog_types ORDER BY sort_order, name')->fetchAll(PDO::FETCH_KEY_PAIR);

// A shop must explicitly pick which catalog to browse — there's only one
// today (Provision Shop), but more (Electrical, Bookshop, ...) are coming,
// so this step never gets silently skipped even while there's just one.
$catalogType = $_GET['catalog_type'] ?? '';
$pickingCatalog = !array_key_exists($catalogType, $catalogTypes);

if (!$pickingCatalog) {
    $q        = trim($_GET['q'] ?? '');
    $category = trim($_GET['category'] ?? '');

    $where  = ['mp.catalog_type = ?', "mp.status = 'active'"];
    $params = [$catalogType];
    if ($q !== '') {
        $where[] = '(mp.name LIKE ? OR mp.brand LIKE ? OR mp.sku LIKE ? OR mp.search_keywords LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like, $like);
    }
    if ($category !== '') { $where[] = 'mp.category = ?'; $params[] = $category; }
    $whereSql = implode(' AND ', $where);

    $page    = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 24;
    $offset  = ($page - 1) * $perPage;

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM master_products mp WHERE $whereSql");
    $countStmt->execute($params);
    $totalCatalogProducts = (int)$countStmt->fetchColumn();
    $totalPages           = max(1, (int)ceil($totalCatalogProducts / $perPage));

    $stmt = $pdo->prepare("SELECT mp.* FROM master_products mp WHERE $whereSql ORDER BY mp.name LIMIT $perPage OFFSET $offset");
    $stmt->execute($params);
    $catalogProducts = $stmt->fetchAll();

    $catStmt = $pdo->prepare("SELECT DISTINCT category FROM master_products WHERE catalog_type = ? AND category IS NOT NULL AND category != '' ORDER BY category");
    $catStmt->execute([$catalogType]);
    $categories = $catStmt->fetchAll(PDO::FETCH_COLUMN);
}

function bc_qstr(array $overrides = []): string {
    $base = [];
    foreach (['catalog_type', 'q', 'category', 'page'] as $k) {
        if (isset($_GET[$k]) && $_GET[$k] !== '') $base[$k] = $_GET[$k];
    }
    return '?' . http_build_query(array_merge($base, $overrides));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add from Catalog — <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .bc-shell { max-width:1060px; margin:0 auto; padding:20px 16px 80px; }
        .bc-header { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; margin-bottom:6px; }
        .bc-sub { font-size:.85rem; color:var(--text-muted,#6b7280); margin:0 0 18px; }
        .bc-filters { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:18px; }
        .bc-filters input, .bc-filters select { padding:8px 12px; border:1px solid var(--border); border-radius:10px; font-size:.85rem; }
        .bc-filters input[type=search] { flex:1; min-width:200px; }
        .bc-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(190px,1fr)); gap:16px; }
        .bc-card { background:var(--surface,#fff); border:1px solid var(--border,#e5e7eb); border-radius:14px; overflow:hidden; display:flex; flex-direction:column; transition:box-shadow .15s,transform .15s; }
        .bc-card:hover { box-shadow:0 8px 24px rgba(0,0,0,.1); transform:translateY(-2px); }
        .bc-card-img { aspect-ratio:1/1; background:#f3f4f6; display:flex; align-items:center; justify-content:center; overflow:hidden; }
        .bc-card-img img { width:100%; height:100%; object-fit:cover; }
        .bc-no-img { font-size:2.2rem; opacity:.3; }
        .bc-card-body { padding:12px 14px 14px; display:flex; flex-direction:column; gap:3px; flex:1; }
        .bc-card-title { font-weight:800; font-size:.9rem; margin:0; line-height:1.35; }
        .bc-card-meta { font-size:.76rem; color:var(--text-muted,#6b7280); margin:0; }
        .bc-card-btn { margin-top:auto; padding-top:10px; }
        .bc-empty { text-align:center; padding:48px 20px; color:var(--text-muted,#6b7280); grid-column:1/-1; }
        .pagination { display:flex; gap:4px; flex-wrap:wrap; align-items:center; margin-top:18px; }
        .pagination a, .pagination span { padding:6px 11px; border-radius:8px; border:1px solid var(--border); text-decoration:none; font-size:.82rem; color:var(--text); }
        .pagination a:hover { background:var(--surface-muted,#f9fafb); }
        .pagination .current { background:var(--primary,#0f766e); color:#fff; border-color:var(--primary,#0f766e); }
        .bc-catalog-tile { display:block; background:var(--surface,#fff); border:1px solid var(--border,#e5e7eb); border-radius:14px; padding:20px; text-decoration:none; color:inherit; transition:box-shadow .15s,transform .15s; }
        .bc-catalog-tile:hover { box-shadow:0 8px 24px rgba(0,0,0,.1); transform:translateY(-2px); }
        .bc-catalog-tile h3 { margin:0 0 4px; font-size:1rem; }
        .bc-catalog-tile p { margin:0; font-size:.8rem; color:var(--text-muted,#6b7280); }
    </style>
</head>
<body class="has-bottom-nav">

<div class="bc-shell">
    <a href="seller_dashboard.php?tab=products" class="button button-secondary button-small" style="margin-bottom:14px;display:inline-block;">← Back to Products</a>

    <div class="bc-header">
        <h1 style="margin:0;font-size:1.3rem;">📚 Add from Catalog</h1>
    </div>

    <?php if ($pickingCatalog): ?>
    <p class="bc-sub">Choose which catalog to browse.</p>
    <div class="bc-grid">
        <?php foreach ($catalogTypes as $ctSlug => $ctName): ?>
        <a href="browse_master_catalog.php?catalog_type=<?php echo urlencode($ctSlug); ?>" class="bc-catalog-tile">
            <h3>📚 <?php echo sanitize($ctName); ?></h3>
            <p>Browse the <?php echo sanitize($ctName); ?> catalog</p>
        </a>
        <?php endforeach; ?>
        <?php if (!$catalogTypes): ?>
        <p class="bc-empty">No catalogs are set up yet.<br><a href="seller_product_form.php" style="color:var(--primary,#0f766e);font-weight:700;text-decoration:none;">+ Add a product manually →</a></p>
        <?php endif; ?>
    </div>
    <?php else: ?>

    <p class="bc-sub">Pick an existing product from the shared <?php echo sanitize($catalogTypes[$catalogType]); ?> catalog instead of building one from scratch — you'll just set your own price, stock, and condition.</p>
    <p style="margin:-10px 0 18px;"><a href="browse_master_catalog.php" style="font-size:.82rem;color:var(--primary,#0f766e);font-weight:700;text-decoration:none;">← Choose a different catalog</a></p>

    <form class="bc-filters" method="get" action="browse_master_catalog.php">
        <input type="hidden" name="catalog_type" value="<?php echo sanitize($catalogType); ?>">
        <input type="search" name="q" value="<?php echo sanitize($q); ?>" placeholder="Search products, brands, SKU…">
        <select name="category" onchange="this.form.submit()">
            <option value="">All categories</option>
            <?php foreach ($categories as $c): ?>
            <option value="<?php echo sanitize($c); ?>" <?php echo $category === $c ? 'selected' : ''; ?>><?php echo sanitize($c); ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="button button-secondary button-small">Search</button>
    </form>

    <div class="bc-grid">
        <?php foreach ($catalogProducts as $cp): ?>
        <div class="bc-card">
            <div class="bc-card-img">
                <?php if ($cp['default_image']): ?>
                    <img src="<?php echo sanitize($cp['default_image']); ?>" alt="<?php echo sanitize($cp['name']); ?>" loading="lazy">
                <?php else: ?>
                    <span class="bc-no-img">📦</span>
                <?php endif; ?>
            </div>
            <div class="bc-card-body">
                <h3 class="bc-card-title"><?php echo sanitize($cp['name']); ?></h3>
                <?php if ($cp['brand']): ?><p class="bc-card-meta"><?php echo sanitize($cp['brand']); ?></p><?php endif; ?>
                <?php if ($cp['package_size']): ?><p class="bc-card-meta"><?php echo sanitize($cp['package_size']); ?></p><?php endif; ?>
                <div class="bc-card-btn">
                    <a href="seller_product_form.php?catalog_id=<?php echo $cp['id']; ?>" class="button button-primary button-small" style="width:100%;justify-content:center;">Use this product</a>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if (!$catalogProducts): ?>
        <p class="bc-empty">
            <?php echo $q || $category ? 'No catalog products match your search.' : 'No products in this catalog yet — check back soon, or add the product yourself.'; ?><br>
            <a href="seller_product_form.php" style="color:var(--primary,#0f766e);font-weight:700;text-decoration:none;">+ Add a product manually →</a>
        </p>
        <?php endif; ?>
    </div>
    <?php if ($totalPages > 1): ?>
    <div class="pagination">
        <?php if ($page > 1): ?><a href="<?php echo bc_qstr(['page' => $page - 1]); ?>">‹ Prev</a><?php endif; ?>
        <?php
        $pStart = max(1, $page - 3);
        $pEnd   = min($totalPages, $page + 3);
        if ($pStart > 1) echo '<span>…</span>';
        for ($p = $pStart; $p <= $pEnd; $p++): ?>
            <?php if ($p === $page): ?><span class="current"><?php echo $p; ?></span>
            <?php else: ?><a href="<?php echo bc_qstr(['page' => $p]); ?>"><?php echo $p; ?></a><?php endif; ?>
        <?php endfor;
        if ($pEnd < $totalPages) echo '<span>…</span>';
        ?>
        <?php if ($page < $totalPages): ?><a href="<?php echo bc_qstr(['page' => $page + 1]); ?>">Next ›</a><?php endif; ?>
        <span style="color:var(--text-muted,#6b7280);border:none;padding-left:4px;">Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/partials/bottom_nav.php'; ?>
</body>
</html>
