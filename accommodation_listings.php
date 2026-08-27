<?php
/**
 * Accommodation browse/search/filter — one engine for both "Find a Room/House"
 * and "Hotels & Guest Houses" (distinguished by ?category=), reusing the same
 * WHERE-array + params query-building pattern marketplace.php uses.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/accommodation_functions.php';

require_module_enabled('accommodation', 'Accommodation');

$user = current_user();

$category  = in_array($_GET['category'] ?? '', ['room_house', 'hotel'], true) ? $_GET['category'] : '';
$typeId    = (int)($_GET['type'] ?? 0);
$q         = trim($_GET['q'] ?? '');
$townId    = (int)($_GET['town'] ?? 0);
$minPrice  = (float)($_GET['min'] ?? 0);
$maxPrice  = (float)($_GET['max'] ?? 0);
$availOnly = isset($_GET['available_only']);
$guests    = (int)($_GET['guests'] ?? 0);
$facilityIds = array_filter(array_map('intval', (array)($_GET['facility'] ?? [])));
$page      = max(1, (int)($_GET['page'] ?? 1));
$perPage   = 24;
$offset    = ($page - 1) * $perPage;

$where  = [accommodation_public_where()];
$params = [];

if ($category) { $where[] = 'at.category = ?'; $params[] = $category; }
if ($typeId)    { $where[] = 'al.accommodation_type_id = ?'; $params[] = $typeId; }
if ($q !== '')  { $where[] = '(al.title LIKE ? OR al.description LIKE ? OR al.area LIKE ?)'; $like = '%'.$q.'%'; $params = array_merge($params, [$like,$like,$like]); }
if ($townId)    { $where[] = 'al.town_id = ?'; $params[] = $townId; }
if ($minPrice > 0) { $where[] = 'al.price >= ?'; $params[] = $minPrice; }
if ($maxPrice > 0) { $where[] = 'al.price <= ?'; $params[] = $maxPrice; }
if ($availOnly) { $where[] = "al.availability_status = 'available'"; }
if ($guests > 0) { $where[] = 'al.guests_capacity >= ?'; $params[] = $guests; }
foreach ($facilityIds as $fid) {
    $where[] = 'JSON_CONTAINS(al.facilities, ?)';
    $params[] = (string)$fid;
}

$whereClause = implode(' AND ', $where);

$countSt = $pdo->prepare("SELECT COUNT(*) FROM accommodation_listings al JOIN accommodation_types at ON al.accommodation_type_id = at.id WHERE $whereClause");
$countSt->execute($params);
$total = (int)$countSt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));

$st = $pdo->prepare(
    "SELECT al.*, at.name AS type_name, at.icon AS type_icon, at.category AS type_category,
            t.name AS town_name,
            (SELECT image_path FROM accommodation_images WHERE listing_id = al.id AND is_primary = 1 LIMIT 1) AS primary_image
     FROM accommodation_listings al
     JOIN accommodation_types at ON al.accommodation_type_id = at.id
     LEFT JOIN towns t ON al.town_id = t.id
     WHERE $whereClause
     ORDER BY (al.featured=1 AND (al.featured_end_date IS NULL OR al.featured_end_date>=CURDATE())) DESC, al.created_at DESC
     LIMIT $perPage OFFSET $offset"
);
$st->execute($params);
$listings = $st->fetchAll();

$types      = get_accommodation_types($category ?: null);
$towns      = get_towns();
$facilities = get_accommodation_facilities();

function ac_page_url(int $page): string {
    $p = $_GET; $p['page'] = $page;
    return 'accommodation_listings.php?' . http_build_query($p);
}

$categoryLabel = $category === 'hotel' ? 'Hotels & Guest Houses' : ($category === 'room_house' ? 'Rooms & Houses' : 'Accommodation');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php echo seo_meta([
        'title'       => $categoryLabel . ' | ' . APP_NAME,
        'description' => 'Browse ' . strtolower($categoryLabel) . ' available in the Akuapem area of Ghana.',
        'url'         => rtrim(BASE_URL, '/') . '/accommodation_listings.php' . ($category ? '?category='.$category : ''),
        'noindex'     => !empty($_GET['q']) || !empty($_GET['page']),
    ]); ?>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .al-shell { max-width: 1200px; margin: 0 auto; padding: 16px 16px 60px; }
        .al-search-row { display: flex; gap: 8px; margin-bottom: 14px; }
        .al-search-row input { flex: 1; }
        .al-filters { display: flex; gap: 8px; margin-bottom: 14px; flex-wrap: wrap; align-items: center; }
        .al-filters select, .al-filters input[type=number] { padding: 6px 10px; border-radius: 8px; border: 1px solid var(--border); font-size: .82rem; background: var(--surface); }
        .al-facility-chips { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 14px; }
        .al-facility-chip { display: inline-flex; align-items: center; gap: 4px; padding: 5px 10px; border-radius: 16px; border: 1px solid var(--border); font-size: .78rem; cursor: pointer; user-select: none; }
        .al-facility-chip input { margin: 0; }
        .al-results-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; flex-wrap: wrap; gap: 8px; }
        .al-results-count { font-size: .82rem; color: var(--text-muted,#6b7280); }
        .al-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px,1fr)); gap: 14px; }
        .al-card { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; overflow: hidden; text-decoration: none; color: inherit; display: flex; flex-direction: column; transition: box-shadow .15s, transform .15s; }
        .al-card:hover { box-shadow: 0 6px 24px rgba(0,0,0,.1); transform: translateY(-2px); }
        .al-card--featured { border: 2px solid #f59e0b; }
        .al-card-img { aspect-ratio: 4/3; background: linear-gradient(135deg,#f8fafc,#f1f5f9); display: flex; align-items: center; justify-content: center; overflow: hidden; flex-shrink: 0; }
        .al-card-img img { width: 100%; height: 100%; object-fit: cover; }
        .al-card-icon { font-size: 2.2rem; opacity: .3; }
        .al-card-body { padding: 10px 12px 12px; flex: 1; display: flex; flex-direction: column; }
        .al-card-type { font-size: .68rem; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; color: var(--primary,#0f766e); margin-bottom: 3px; }
        .al-card-title { font-weight: 700; font-size: .88rem; line-height: 1.35; margin: 0 0 4px; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
        .al-card-class { font-size: .72rem; font-weight: 800; color: var(--primary,#0f766e); margin: -2px 0 4px; }
        .al-card-loc { font-size: .74rem; color: var(--text-muted,#6b7280); margin-bottom: 6px; }
        .al-card-price { font-weight: 900; font-size: .92rem; color: var(--primary,#0f766e); margin-top: auto; }
        .al-card-avail { font-size: .66rem; font-weight: 700; padding: 2px 7px; border-radius: 10px; display: inline-block; margin-top: 6px; }
        .al-pages { display: flex; gap: 6px; justify-content: center; margin-top: 24px; flex-wrap: wrap; }
        .al-page  { padding: 6px 12px; border-radius: 8px; border: 1px solid var(--border); font-size: .82rem; font-weight: 700; text-decoration: none; color: var(--text-muted,#6b7280); }
        .al-page.active { background: var(--primary,#0f766e); color: #fff; border-color: var(--primary,#0f766e); }
        @media (max-width: 480px) { .al-grid { grid-template-columns: repeat(2,1fr); } }
    </style>
</head>
<body class="<?php echo $user ? 'has-bottom-nav' : ''; ?>">

<header class="app-topbar">
    <a href="accommodation.php" class="button button-secondary button-small">← Accommodation</a>
    <span class="brand"><?php echo sanitize($categoryLabel); ?></span>
    <?php if ($user): ?><a href="accommodation_form.php" class="button button-primary button-small">+ List</a><?php endif; ?>
</header>

<main class="al-shell">

    <form method="get" action="accommodation_listings.php" class="al-search-row">
        <input type="hidden" name="category" value="<?php echo sanitize($category); ?>">
        <input type="text" name="q" value="<?php echo sanitize($q); ?>" placeholder="Search title, area…">
        <button type="submit" class="button button-primary">Search</button>
    </form>

    <form method="get" action="accommodation_listings.php" class="al-filters">
        <input type="hidden" name="category" value="<?php echo sanitize($category); ?>">
        <?php if ($q): ?><input type="hidden" name="q" value="<?php echo sanitize($q); ?>"><?php endif; ?>

        <select name="type" onchange="this.form.submit()">
            <option value="0">All Types</option>
            <?php foreach ($types as $t): ?>
            <option value="<?php echo $t['id']; ?>" <?php echo $typeId===(int)$t['id']?'selected':''; ?>><?php echo $t['icon'].' '; ?><?php echo sanitize($t['name']); ?></option>
            <?php endforeach; ?>
        </select>

        <select name="town" onchange="this.form.submit()">
            <option value="0">All Towns</option>
            <?php foreach ($towns as $t): ?>
            <option value="<?php echo $t['id']; ?>" <?php echo $townId===(int)$t['id']?'selected':''; ?>><?php echo sanitize($t['name']); ?></option>
            <?php endforeach; ?>
        </select>

        <input type="number" name="min" value="<?php echo $minPrice ?: ''; ?>" placeholder="Min GHS" min="0" step="0.01" style="width:90px;">
        <input type="number" name="max" value="<?php echo $maxPrice ?: ''; ?>" placeholder="Max GHS" min="0" step="0.01" style="width:90px;">

        <?php if ($category === 'hotel'): ?>
        <input type="number" name="guests" value="<?php echo $guests ?: ''; ?>" placeholder="Guests" min="1" style="width:80px;">
        <?php endif; ?>

        <label style="display:flex;align-items:center;gap:5px;font-size:.82rem;">
            <input type="checkbox" name="available_only" value="1" <?php echo $availOnly?'checked':''; ?> onchange="this.form.submit()"> Available only
        </label>

        <button type="submit" class="button button-secondary button-small">Filter</button>
        <?php if ($q || $typeId || $townId || $minPrice || $maxPrice || $availOnly || $guests || $facilityIds): ?>
        <a href="accommodation_listings.php?category=<?php echo sanitize($category); ?>" class="button button-secondary button-small">Clear</a>
        <?php endif; ?>
    </form>

    <?php if ($facilities): ?>
    <form method="get" action="accommodation_listings.php" class="al-facility-chips" id="facility-form">
        <input type="hidden" name="category" value="<?php echo sanitize($category); ?>">
        <?php foreach (['type'=>$typeId,'town'=>$townId,'min'=>$minPrice,'max'=>$maxPrice,'guests'=>$guests] as $k=>$v): if ($v): ?><input type="hidden" name="<?php echo $k; ?>" value="<?php echo sanitize((string)$v); ?>"><?php endif; endforeach; ?>
        <?php if ($q): ?><input type="hidden" name="q" value="<?php echo sanitize($q); ?>"><?php endif; ?>
        <?php if ($availOnly): ?><input type="hidden" name="available_only" value="1"><?php endif; ?>
        <?php foreach ($facilities as $f): ?>
        <label class="al-facility-chip">
            <input type="checkbox" name="facility[]" value="<?php echo $f['id']; ?>" <?php echo in_array((int)$f['id'],$facilityIds,true)?'checked':''; ?> onchange="document.getElementById('facility-form').submit()">
            <?php echo $f['icon'] ? $f['icon'].' ' : ''; ?><?php echo sanitize($f['name']); ?>
        </label>
        <?php endforeach; ?>
    </form>
    <?php endif; ?>

    <div class="al-results-head">
        <span class="al-results-count"><?php echo number_format($total); ?> listing<?php echo $total!==1?'s':''; ?></span>
    </div>

    <?php if ($listings): ?>
    <div class="al-grid">
        <?php foreach ($listings as $l):
            $isFeatured = !empty($l['featured']) && (empty($l['featured_end_date']) || $l['featured_end_date'] >= date('Y-m-d'));
        ?>
        <a href="accommodation_detail.php?id=<?php echo $l['id']; ?>" class="al-card<?php echo $isFeatured?' al-card--featured':''; ?>">
            <div class="al-card-img" style="position:relative;">
                <?php if ($l['primary_image']): ?>
                    <img src="<?php echo sanitize($l['primary_image']); ?>" alt="<?php echo sanitize($l['title']); ?>">
                <?php else: ?>
                    <span class="al-card-icon"><?php echo $l['type_icon'] ?? '🏠'; ?></span>
                <?php endif; ?>
                <?php if ($isFeatured): ?><span style="position:absolute;top:6px;left:6px;background:#f59e0b;color:#fff;font-size:.6rem;font-weight:800;padding:2px 7px;border-radius:10px;">⭐ FEATURED</span><?php endif; ?>
            </div>
            <div class="al-card-body">
                <div class="al-card-type"><?php echo sanitize($l['type_name']); ?></div>
                <div class="al-card-title"><?php echo sanitize($l['title']); ?></div>
                <?php if (!empty($l['room_class'])): ?><div class="al-card-class"><?php echo sanitize($l['room_class']); ?></div><?php endif; ?>
                <div class="al-card-loc">📍 <?php echo sanitize(accommodation_location_label($l['area'], $l['town_name'] ?? null)); ?></div>
                <div class="al-card-price">
                    <?php if ($l['price']): ?>GH&#8373; <?php echo number_format((float)$l['price'],2); ?> <small><?php echo accommodation_price_period_label($l['price_period']); ?></small>
                    <?php else: ?><?php echo ucfirst(accommodation_price_period_label($l['price_period'])); ?><?php endif; ?>
                </div>
                <span class="al-card-avail" style="background:<?php echo $l['availability_status']==='available'?'#d1fae5':'#fee2e2'; ?>;color:<?php echo $l['availability_status']==='available'?'#065f46':'#991b1b'; ?>;"><?php echo accommodation_availability_label($l['availability_status']); ?></span>
            </div>
        </a>
        <?php endforeach; ?>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="al-pages">
        <?php if ($page > 1): ?><a href="<?php echo ac_page_url($page-1); ?>" class="al-page">← Prev</a><?php endif; ?>
        <?php for ($i = max(1,$page-2); $i <= min($totalPages,$page+2); $i++): ?>
        <a href="<?php echo ac_page_url($i); ?>" class="al-page <?php echo $i===$page?'active':''; ?>"><?php echo $i; ?></a>
        <?php endfor; ?>
        <?php if ($page < $totalPages): ?><a href="<?php echo ac_page_url($page+1); ?>" class="al-page">Next →</a><?php endif; ?>
    </div>
    <?php endif; ?>

    <?php else: ?>
    <div style="text-align:center;padding:60px 20px;color:var(--text-muted,#6b7280);">
        <div style="font-size:3rem;opacity:.4;margin-bottom:14px;">🏠</div>
        <p style="margin:0 0 16px;">No listings found<?php echo ($q||$typeId||$townId) ? ' matching your search' : ''; ?>.</p>
        <?php if ($user): ?><a href="accommodation_form.php" class="button button-primary">List yours →</a><?php endif; ?>
    </div>
    <?php endif; ?>

</main>

<?php require __DIR__ . '/partials/site_footer.php'; ?>
<?php if ($user): require __DIR__ . '/partials/bottom_nav.php'; endif; ?>
</body>
</html>
