<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_module_enabled('funerals', 'Funeral Announcements');

$user    = current_user();
$search  = trim($_GET['q']       ?? '');
$month   = trim($_GET['month']   ?? '');   // YYYY-MM
$gender  = trim($_GET['gender']  ?? '');   // male | female | other
$venue   = trim($_GET['venue']   ?? '');   // free-text venue/location
$sort    = $_GET['sort']          ?? 'burial_asc'; // burial_asc | burial_desc | newest
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 12;
$isAjax  = !empty($_GET['ajax']);

function funeral_cards($pdo, array $filters, int $page, int $perPage, bool $featuredOnly = false): array {
    $offset = ($page - 1) * $perPage;
    $where  = ["fa.status='approved'", "(fa.user_id IS NULL OR fa.user_id NOT IN (SELECT id FROM users WHERE banned=1))"];
    $params = [];

    if (!empty($filters['q'])) {
        $like = '%' . $filters['q'] . '%';
        $where[] = "(fa.deceased_name LIKE ? OR fa.venue LIKE ? OR fa.organizer_name LIKE ? OR fa.gps_address LIKE ?)";
        array_push($params, $like, $like, $like, $like);
    }
    if (!empty($filters['month'])) {
        $where[] = "DATE_FORMAT(fa.burial_date,'%Y-%m') = ?";
        $params[] = $filters['month'];
    }
    if (!empty($filters['gender']) && in_array($filters['gender'], ['male','female','other'], true)) {
        $where[] = "fa.gender = ?";
        $params[] = $filters['gender'];
    }
    if (!empty($filters['venue'])) {
        $like = '%' . $filters['venue'] . '%';
        $where[] = "(fa.venue LIKE ? OR fa.gps_address LIKE ?)";
        array_push($params, $like, $like);
    }
    if ($featuredOnly) {
        $where[] = "fa.featured=1";
    }

    $featExpr = '(fa.featured=1 AND (fa.featured_end_date IS NULL OR fa.featured_end_date>=CURDATE()))';
    $orderBy = match($filters['sort'] ?? 'burial_asc') {
        'burial_desc' => "fa.burial_date DESC, {$featExpr} DESC",
        'newest'      => 'fa.created_at DESC',
        default       => "{$featExpr} DESC, fa.burial_date ASC",
    };

    $whereClause = 'WHERE ' . implode(' AND ', $where);
    $sql = "SELECT fa.* FROM funeral_announcements fa $whereClause
            ORDER BY $orderBy
            LIMIT " . (int)$perPage . " OFFSET " . (int)$offset;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function funeral_count($pdo, array $filters): int {
    $where  = ["fa.status='approved'", "(fa.user_id IS NULL OR fa.user_id NOT IN (SELECT id FROM users WHERE banned=1))"];
    $params = [];
    if (!empty($filters['q'])) { $like='%'.$filters['q'].'%'; $where[]="(fa.deceased_name LIKE ? OR fa.venue LIKE ?)"; $params[]=$like; $params[]=$like; }
    if (!empty($filters['month'])) { $where[]="DATE_FORMAT(fa.burial_date,'%Y-%m')=?"; $params[]=$filters['month']; }
    if (!empty($filters['gender']) && in_array($filters['gender'],['male','female','other'],true)) { $where[]="fa.gender=?"; $params[]=$filters['gender']; }
    if (!empty($filters['venue'])) { $like='%'.$filters['venue'].'%'; $where[]="(fa.venue LIKE ? OR fa.gps_address LIKE ?)"; $params[]=$like; $params[]=$like; }
    $st = $pdo->prepare("SELECT COUNT(*) FROM funeral_announcements fa WHERE " . implode(' AND ', $where));
    $st->execute($params);
    return (int)$st->fetchColumn();
}

$activeFilters = ['q' => $search, 'month' => $month, 'gender' => $gender, 'venue' => $venue, 'sort' => $sort];

// Distinct months that have approved burials — for the month dropdown
$availableMonths = $pdo->query(
    "SELECT DISTINCT DATE_FORMAT(burial_date,'%Y-%m') AS ym, DATE_FORMAT(burial_date,'%M %Y') AS label
     FROM funeral_announcements WHERE status='approved' AND burial_date IS NOT NULL
     ORDER BY burial_date DESC LIMIT 24"
)->fetchAll();

$featured = [];
$cards    = [];
$hasFilters = $search || $month || $gender || $venue;
$totalCount = funeral_count($pdo, $activeFilters);
$totalPages = max(1, (int)ceil($totalCount / $perPage));

if (!$isAjax || $page === 1) {
    if (!$hasFilters) {
        $featured = funeral_cards($pdo, ['q'=>'','month'=>'','gender'=>'','venue'=>'','sort'=>'burial_asc'], 1, 3, true);
    }
}

$cards = funeral_cards($pdo, $activeFilters, $page, $perPage);

// Sidebar data
$today           = date('Y-m-d');
$sidebarEvents   = $pdo->query("SELECT title, slug, start_date, start_time FROM events WHERE status='published' AND start_date >= '$today' ORDER BY (featured=1 AND (featured_end_date IS NULL OR featured_end_date>=CURDATE())) DESC, start_date ASC LIMIT 5")->fetchAll();
$sidebarNews     = $pdo->query("SELECT title, slug, published_at FROM news WHERE status='published' ORDER BY COALESCE(published_at,created_at) DESC LIMIT 4")->fetchAll();
$sidebarAd       = $pdo->query("SELECT * FROM advertisements WHERE status='active' AND ad_type='banner' AND (start_date IS NULL OR start_date<=CURDATE()) AND (end_date IS NULL OR end_date>=CURDATE()) ORDER BY RAND() LIMIT 1")->fetch();

if ($isAjax) {
    foreach ($cards as $fa): ?>
    <div class="fa-card">
        <a href="funeral.php?slug=<?php echo urlencode($fa['slug']); ?>" class="fa-card-inner">
            <div class="fa-card-photo">
                <?php if ($fa['photograph']): ?>
                    <img src="<?php echo sanitize($fa['photograph']); ?>" alt="<?php echo sanitize($fa['deceased_name']); ?>">
                <?php else: ?>
                    <span class="fa-card-initials"><?php echo mb_strtoupper(mb_substr($fa['deceased_name'], 0, 2)); ?></span>
                <?php endif; ?>
                <?php if ($fa['featured']): ?><span class="fa-badge-featured">Featured</span><?php endif; ?>
            </div>
            <div class="fa-card-body">
                <h3 class="fa-card-name"><?php echo sanitize($fa['deceased_name']); ?></h3>
                <?php if ($fa['age']): ?>
                <p class="fa-card-age">Age <?php echo (int)$fa['age']; ?></p>
                <?php endif; ?>
                <?php if ($fa['burial_date']): ?>
                <p class="fa-card-date">⚰️ <?php echo date('D, d M Y', strtotime($fa['burial_date'])); ?></p>
                <?php endif; ?>
                <?php if ($fa['venue']): ?>
                <p class="fa-card-venue">📍 <?php echo sanitize(mb_substr($fa['venue'], 0, 60)); ?></p>
                <?php endif; ?>
                <span class="fa-card-btn">View Details →</span>
            </div>
        </a>
    </div>
    <?php endforeach;
    if (!$cards) echo '<p class="fa-empty">No more announcements.</p>';
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php echo seo_meta([
        'title'       => 'Funeral Announcements — Akuapem Area, Ghana | ' . APP_NAME,
        'description' => 'Find funeral announcements, obituaries, and burial/memorial service details for families across the Akuapem area of Ghana.',
        'url'         => rtrim(BASE_URL, '/') . '/funerals.php',
        'noindex'     => !empty($_GET['q']) || !empty($_GET['page']),
    ]); ?>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .fa-shell { max-width:1060px; margin:0 auto; padding:20px 16px 60px; }
        .fa-hero  { background:linear-gradient(135deg,#1e293b 0%,#0f172a 100%); color:#fff; padding:36px 20px 32px; text-align:center; margin-bottom:28px; }
        .fa-hero h1 { font-size:clamp(1.4rem,4vw,2rem); font-weight:900; margin:0 0 8px; }
        .fa-hero p  { font-size:.95rem; color:#cbd5e1; margin:0 0 20px; }
        .fa-search-wrap { display:flex; gap:8px; max-width:480px; margin:0 auto 14px; }
        .fa-search-wrap input { flex:1; padding:10px 14px; border-radius:10px; border:1px solid #334155; background:#1e293b; color:#fff; font-size:.9rem; }
        .fa-search-wrap input::placeholder { color:#64748b; }
        .fa-search-wrap button { padding:10px 18px; border-radius:10px; background:#0f766e; color:#fff; border:none; font-weight:700; cursor:pointer; }
        /* Filter bar */
        .fa-filters { display:flex; gap:8px; flex-wrap:wrap; justify-content:center; max-width:700px; margin:0 auto; }
        .fa-filters select { padding:7px 12px; border-radius:8px; border:1px solid #334155; background:#1e293b; color:#fff; font-size:.82rem; }
        .fa-filter-active { background:rgba(15,118,110,.25); border:1px solid #0f766e; border-radius:8px; padding:3px 10px; font-size:.76rem; color:#a7f3d0; display:inline-flex; align-items:center; gap:4px; }
        .fa-filter-active a { color:#a7f3d0; text-decoration:none; }

        /* Featured strip */
        .fa-featured-strip { margin-bottom:28px; }
        .fa-featured-strip h2 { font-size:.78rem; font-weight:800; text-transform:uppercase; letter-spacing:.06em; color:var(--text-muted,#6b7280); margin:0 0 12px; }
        .fa-featured-row { display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:14px; }
        .fa-featured-card { background:var(--surface,#fff); border:2px solid #f59e0b; border-radius:14px; overflow:hidden; display:flex; gap:0; }
        .fa-featured-thumb { width:90px; flex-shrink:0; background:#f3f4f6; display:flex; align-items:center; justify-content:center; }
        .fa-featured-thumb img { width:90px; height:90px; object-fit:cover; }
        .fa-featured-initials { font-size:1.5rem; font-weight:900; color:#d1d5db; }
        .fa-featured-info { padding:12px 14px; display:flex; flex-direction:column; gap:4px; }
        .fa-featured-name { font-weight:800; font-size:.95rem; }
        .fa-featured-meta { font-size:.78rem; color:var(--text-muted,#6b7280); }
        .fa-featured-link { font-size:.8rem; color:var(--primary,#0f766e); font-weight:700; text-decoration:none; margin-top:auto; }

        /* Grid */
        .fa-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr)); gap:16px; }
        .fa-card { background:var(--surface,#fff); border:1px solid var(--border,#e5e7eb); border-radius:14px; overflow:hidden; transition:box-shadow .15s, transform .15s; }
        .fa-card:hover { box-shadow:0 6px 24px rgba(0,0,0,.1); transform:translateY(-2px); }
        .fa-card-inner { display:flex; flex-direction:column; text-decoration:none; color:inherit; height:100%; }
        .fa-card-photo { aspect-ratio:1/1; background:#f3f4f6; display:flex; align-items:center; justify-content:center; position:relative; overflow:hidden; }
        .fa-card-photo img { width:100%; height:100%; object-fit:cover; }
        .fa-card-initials { font-size:2.5rem; font-weight:900; color:#d1d5db; }
        .fa-badge-featured { position:absolute; top:8px; right:8px; background:#f59e0b; color:#fff; font-size:.65rem; font-weight:800; padding:3px 8px; border-radius:20px; letter-spacing:.04em; }
        .fa-card-body { padding:12px 14px 14px; display:flex; flex-direction:column; gap:4px; flex:1; }
        .fa-card-name  { font-weight:800; font-size:.95rem; margin:0; }
        .fa-card-age   { font-size:.78rem; color:var(--text-muted,#6b7280); margin:0; }
        .fa-card-date  { font-size:.8rem; color:var(--text,#111); margin:0; }
        .fa-card-venue { font-size:.76rem; color:var(--text-muted,#6b7280); margin:0; }
        .fa-card-btn   { display:inline-block; margin-top:auto; padding-top:8px; font-size:.78rem; color:var(--primary,#0f766e); font-weight:700; }

        .fa-toolbar { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:16px; flex-wrap:wrap; }
        .fa-empty  { text-align:center; padding:40px; color:var(--text-muted,#6b7280); }
        .fa-load-more { display:block; margin:24px auto 0; padding:11px 28px; background:var(--primary,#0f766e); color:#fff; border:none; border-radius:10px; font-weight:700; font-size:.9rem; cursor:pointer; }
        .fa-load-more:disabled { opacity:.5; cursor:default; }

        @media(max-width:480px) { .fa-grid { grid-template-columns:repeat(2,1fr); } }

        /* ── Two-column layout ── */
        /* Sidebar renders inline below main content on mobile (it already
           sits right after .fa-main in the HTML), and becomes a sticky side
           column at the wider breakpoint. */
        .fa-layout  { display:block; }
        .fa-sidebar { display:flex; flex-direction:column; gap:20px; margin-top:24px; }
        @media(min-width:900px) {
            .fa-shell   { max-width:1200px; }
            .fa-layout  { display:grid; grid-template-columns:1fr 280px; gap:32px; align-items:start; }
            .fa-sidebar { position:sticky; top:16px; margin-top:0; }
        }
        /* ── Sidebar widgets ── */
        .nsb-widget { background:var(--surface,#fff); border:1px solid var(--border,#e5e7eb); border-radius:14px; overflow:hidden; }
        .nsb-head   { padding:12px 16px; border-bottom:1px solid var(--border,#e5e7eb); font-size:.8rem; font-weight:800; text-transform:uppercase; letter-spacing:.06em; color:var(--muted,#6b7280); }
        .nsb-list   { list-style:none; margin:0; padding:0; }
        .nsb-item   { display:flex; gap:10px; align-items:flex-start; padding:10px 14px; border-bottom:1px solid var(--border,#e5e7eb); }
        .nsb-item:last-child { border-bottom:none; }
        .nsb-num    { flex-shrink:0; width:22px; height:22px; border-radius:6px; background:var(--primary,#0f766e); color:#fff; font-size:.7rem; font-weight:900; display:flex; align-items:center; justify-content:center; }
        .nsb-text   { flex:1; min-width:0; }
        .nsb-text a { font-size:.82rem; font-weight:700; color:var(--text,#111); text-decoration:none; line-height:1.35; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
        .nsb-text a:hover { color:var(--primary,#0f766e); }
        .nsb-meta   { font-size:.72rem; color:var(--muted,#6b7280); margin-top:3px; }
        .nsb-event  { padding:10px 14px; border-bottom:1px solid var(--border,#e5e7eb); }
        .nsb-event:last-child { border-bottom:none; }
        .nsb-ev-date  { font-size:.7rem; font-weight:700; color:var(--primary,#0f766e); text-transform:uppercase; letter-spacing:.04em; margin-bottom:2px; }
        .nsb-ev-title a { font-size:.82rem; font-weight:600; color:var(--text,#111); text-decoration:none; }
        .nsb-ev-title a:hover { color:var(--primary,#0f766e); }
        .nsb-cta    { padding:16px; text-align:center; }
        .nsb-ad     { padding:10px; text-align:center; }
        .nsb-ad img { width:100%; border-radius:8px; }
        .nsb-ad-label { font-size:.65rem; text-transform:uppercase; letter-spacing:.07em; color:var(--muted,#6b7280); margin-bottom:6px; }
    </style>
</head>
<body<?php echo $user ? ' class="has-bottom-nav no-own-topbar"' : ''; ?>>
<!-- Guest top bar -->
<?php if (!$user): ?>
<header style="background:var(--surface,#fff);border-bottom:1px solid var(--border,#e5e7eb);padding:12px 16px;display:flex;align-items:center;justify-content:space-between;gap:12px;">
    <a href="index.php" style="font-weight:900;color:var(--primary,#0f766e);text-decoration:none;font-size:1.1rem;">← Back Home</a>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <a href="community.php" style="font-size:.85rem;color:var(--text-muted,#6b7280);text-decoration:none;font-weight:600;">Community</a>
        <a href="events.php"    style="font-size:.85rem;color:var(--text-muted,#6b7280);text-decoration:none;font-weight:600;">Events</a>
        <a href="login.php?redirect=<?php echo urlencode(current_request_path()); ?>" class="button button-secondary button-small">Sign in</a>
    </div>
</header>
<?php endif; ?>

<div class="fa-hero">
    <h1>🕊️ Funeral Announcements</h1>
    <p>Memorial information and funeral notices from our community</p>

    <form id="fa-filter-form" method="get" action="funerals.php">
        <!-- Search row -->
        <div class="fa-search-wrap">
            <input type="search" name="q" placeholder="Search name, venue, organiser…" value="<?php echo sanitize($search); ?>">
            <button type="submit">Search</button>
        </div>

        <!-- Filter pills -->
        <div class="fa-filters">
            <!-- Month -->
            <select name="month" onchange="this.form.submit()">
                <option value="">📅 All dates</option>
                <?php foreach ($availableMonths as $m): ?>
                <option value="<?php echo sanitize($m['ym']); ?>" <?php echo $month===$m['ym']?'selected':''; ?>>
                    <?php echo sanitize($m['label']); ?>
                </option>
                <?php endforeach; ?>
            </select>

            <!-- Gender -->
            <select name="gender" onchange="this.form.submit()">
                <option value="">👤 All</option>
                <option value="male"   <?php echo $gender==='male'?'selected':''; ?>>Male</option>
                <option value="female" <?php echo $gender==='female'?'selected':''; ?>>Female</option>
                <option value="other"  <?php echo $gender==='other'?'selected':''; ?>>Other</option>
            </select>

            <!-- Venue/location -->
            <input type="text" name="venue" placeholder="📍 Venue / area"
                   value="<?php echo sanitize($venue); ?>"
                   style="padding:7px 12px;border-radius:8px;border:1px solid #334155;background:#1e293b;color:#fff;font-size:.82rem;width:140px;">

            <!-- Sort -->
            <select name="sort" onchange="this.form.submit()">
                <option value="burial_asc"  <?php echo $sort==='burial_asc'?'selected':''; ?>>⬆ Burial (soonest)</option>
                <option value="burial_desc" <?php echo $sort==='burial_desc'?'selected':''; ?>>⬇ Burial (latest)</option>
                <option value="newest"      <?php echo $sort==='newest'?'selected':''; ?>>🆕 Newest posted</option>
            </select>

            <?php if ($hasFilters): ?>
            <a href="funerals.php" class="fa-filter-active">✕ Clear filters</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Active filter summary -->
<?php if ($hasFilters): ?>
<div style="max-width:1060px;margin:-14px auto 10px;padding:0 16px;font-size:.8rem;color:var(--text-muted,#6b7280);">
    <?php echo number_format($totalCount); ?> announcement<?php echo $totalCount!==1?'s':''; ?> found
    <?php if ($search): ?> · matching "<strong><?php echo sanitize($search); ?></strong>"<?php endif; ?>
    <?php if ($month): ?> · burial in <strong><?php echo sanitize(date('F Y', strtotime($month.'-01'))); ?></strong><?php endif; ?>
    <?php if ($gender): ?> · <strong><?php echo ucfirst($gender); ?></strong><?php endif; ?>
    <?php if ($venue): ?> · venue contains "<strong><?php echo sanitize($venue); ?></strong>"<?php endif; ?>
</div>
<?php endif; ?>

<div class="fa-shell">
<div class="fa-layout">

<!-- ── Main column ── -->
<div class="fa-main">

    <?php if ($user): ?>
    <div class="fa-toolbar">
        <p style="margin:0;color:var(--muted,#6b7280);font-size:.88rem;">
            <?php echo number_format($totalCount); ?> announcement<?php echo $totalCount!==1?'s':''; ?>
            <?php if ($hasFilters): ?><a href="funerals.php" style="margin-left:8px;color:var(--primary,#0f766e);font-size:.8rem;">Clear filters ✕</a><?php endif; ?>
        </p>
        <a href="my_funerals.php" class="button button-small">My Submissions</a>
    </div>
    <?php endif; ?>

    <!-- Featured strip (page 1 only, no search) -->
    <?php if (!$search && $featured): ?>
    <div class="fa-featured-strip">
        <h2>Featured</h2>
        <div class="fa-featured-row">
            <?php foreach ($featured as $fa): ?>
            <div class="fa-featured-card">
                <div class="fa-featured-thumb">
                    <?php if ($fa['photograph']): ?>
                        <img src="<?php echo sanitize($fa['photograph']); ?>" alt="<?php echo sanitize($fa['deceased_name']); ?>">
                    <?php else: ?>
                        <span class="fa-featured-initials"><?php echo mb_strtoupper(mb_substr($fa['deceased_name'],0,2)); ?></span>
                    <?php endif; ?>
                </div>
                <div class="fa-featured-info">
                    <div class="fa-featured-name"><?php echo sanitize($fa['deceased_name']); ?></div>
                    <?php if ($fa['burial_date']): ?>
                    <div class="fa-featured-meta">⚰️ <?php echo date('D, d M Y', strtotime($fa['burial_date'])); ?></div>
                    <?php endif; ?>
                    <?php if ($fa['venue']): ?>
                    <div class="fa-featured-meta">📍 <?php echo sanitize(mb_substr($fa['venue'],0,50)); ?></div>
                    <?php endif; ?>
                    <a href="funeral.php?slug=<?php echo urlencode($fa['slug']); ?>" class="fa-featured-link">View Details →</a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Main grid -->
    <div class="fa-grid" id="fa-grid">
        <?php foreach ($cards as $fa): ?>
        <div class="fa-card">
            <a href="funeral.php?slug=<?php echo urlencode($fa['slug']); ?>" class="fa-card-inner">
                <div class="fa-card-photo">
                    <?php if ($fa['photograph']): ?>
                        <img src="<?php echo sanitize($fa['photograph']); ?>" alt="<?php echo sanitize($fa['deceased_name']); ?>">
                    <?php else: ?>
                        <span class="fa-card-initials"><?php echo mb_strtoupper(mb_substr($fa['deceased_name'],0,2)); ?></span>
                    <?php endif; ?>
                    <?php if ($fa['featured']): ?><span class="fa-badge-featured">Featured</span><?php endif; ?>
                </div>
                <div class="fa-card-body">
                    <p class="fa-card-name"><?php echo sanitize($fa['deceased_name']); ?></p>
                    <?php if ($fa['age']): ?><p class="fa-card-age">Age <?php echo (int)$fa['age']; ?></p><?php endif; ?>
                    <?php if ($fa['burial_date']): ?><p class="fa-card-date">⚰️ <?php echo date('D, d M Y', strtotime($fa['burial_date'])); ?></p><?php endif; ?>
                    <?php if ($fa['venue']): ?><p class="fa-card-venue">📍 <?php echo sanitize(mb_substr($fa['venue'],0,50)); ?></p><?php endif; ?>
                    <span class="fa-card-btn">View Details →</span>
                </div>
            </a>
        </div>
        <?php endforeach; ?>
        <?php if (!$cards): ?><p class="fa-empty" style="grid-column:1/-1;">No announcements found<?php echo $search ? ' for "' . sanitize($search) . '"' : ' yet'; ?>.</p><?php endif; ?>
    </div>

    <?php if (count($cards) === $perPage && $page < $totalPages): ?>
    <button class="fa-load-more" id="fa-load-more"
            data-page="<?php echo $page + 1; ?>"
            data-q="<?php echo sanitize($search); ?>"
            data-month="<?php echo sanitize($month); ?>"
            data-gender="<?php echo sanitize($gender); ?>"
            data-venue="<?php echo sanitize($venue); ?>"
            data-sort="<?php echo sanitize($sort); ?>">
        Load more (<?php echo $totalCount - ($page * $perPage); ?> remaining)
    </button>
    <?php endif; ?>

</div><!-- /fa-main -->

<!-- ── Sidebar ── -->
<aside class="fa-sidebar">

    <?php if ($user): ?>
    <div class="nsb-widget">
        <div class="nsb-cta">
            <p style="font-size:.85rem;font-weight:700;margin:0 0 10px;">Share an announcement</p>
            <a href="my_funerals.php" class="button button-primary button-small" style="width:100%;justify-content:center;">🕊️ Submit Notice</a>
        </div>
    </div>
    <?php else: ?>
    <div class="nsb-widget">
        <div class="nsb-cta">
            <p style="font-size:.85rem;font-weight:700;margin:0 0 4px;">Share an announcement</p>
            <p style="font-size:.78rem;color:var(--muted,#6b7280);margin:0 0 10px;">Sign in to post a funeral notice.</p>
            <a href="register.php" class="button button-primary button-small" style="width:100%;justify-content:center;">Join free</a>
        </div>
    </div>
    <?php endif; ?>

    <!-- Upcoming Events -->
    <?php if ($sidebarEvents): ?>
    <div class="nsb-widget">
        <div class="nsb-head">📅 Upcoming Events</div>
        <?php foreach ($sidebarEvents as $ev): ?>
        <div class="nsb-event">
            <div class="nsb-ev-date"><?php echo date('D, M j', strtotime($ev['start_date'])); ?><?php if ($ev['start_time']): ?> · <?php echo date('g:i A', strtotime($ev['start_time'])); ?><?php endif; ?></div>
            <div class="nsb-ev-title"><a href="event.php?slug=<?php echo urlencode($ev['slug']); ?>"><?php echo sanitize($ev['title']); ?></a></div>
        </div>
        <?php endforeach; ?>
        <div style="padding:10px 14px;">
            <a href="events.php" style="font-size:.8rem;color:var(--primary,#0f766e);font-weight:700;text-decoration:none;">All events →</a>
        </div>
    </div>
    <?php endif; ?>

    <!-- Latest News -->
    <?php if ($sidebarNews): ?>
    <div class="nsb-widget">
        <div class="nsb-head">📰 Latest News</div>
        <ul class="nsb-list">
            <?php foreach ($sidebarNews as $n => $art): ?>
            <li class="nsb-item">
                <span class="nsb-num"><?php echo $n + 1; ?></span>
                <div class="nsb-text">
                    <a href="news_article.php?slug=<?php echo urlencode($art['slug']); ?>"><?php echo sanitize($art['title']); ?></a>
                    <?php if ($art['published_at']): ?>
                    <div class="nsb-meta"><?php echo date('M j, Y', strtotime($art['published_at'])); ?></div>
                    <?php endif; ?>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
        <div style="padding:10px 14px;">
            <a href="news.php" style="font-size:.8rem;color:var(--primary,#0f766e);font-weight:700;text-decoration:none;">All articles →</a>
        </div>
    </div>
    <?php endif; ?>

    <!-- Ad -->
    <?php if ($sidebarAd): ?>
    <div class="nsb-widget">
        <div class="nsb-ad">
            <div class="nsb-ad-label">Advertisement</div>
            <a href="ad_click.php?id=<?php echo (int)$sidebarAd['id']; ?>" target="_blank" rel="noopener sponsored">
                <?php if ($sidebarAd['image']): ?>
                    <img src="<?php echo sanitize($sidebarAd['image']); ?>" alt="<?php echo sanitize($sidebarAd['title']); ?>">
                <?php else: ?>
                    <p style="font-size:.82rem;font-weight:600;color:var(--muted,#6b7280);margin:0;"><?php echo sanitize($sidebarAd['title']); ?></p>
                <?php endif; ?>
            </a>
        </div>
    </div>
    <?php endif; ?>

</aside><!-- /fa-sidebar -->

</div><!-- /fa-layout -->
</div><!-- /fa-shell -->

<?php require __DIR__ . '/partials/site_footer.php'; ?>
<?php if ($user): require_once __DIR__ . '/partials/bottom_nav.php'; endif; ?>

<script>
(function(){
    var btn = document.getElementById('fa-load-more');
    if (!btn) return;
    btn.addEventListener('click', function(){
        var p      = parseInt(btn.dataset.page);
        var params = new URLSearchParams({
            ajax:   1,
            page:   p,
            q:      btn.dataset.q      || '',
            month:  btn.dataset.month  || '',
            gender: btn.dataset.gender || '',
            venue:  btn.dataset.venue  || '',
            sort:   btn.dataset.sort   || 'burial_asc',
        });
        btn.disabled = true; btn.textContent = 'Loading…';
        fetch('funerals.php?' + params.toString())
            .then(function(r){ return r.text(); })
            .then(function(html){
                var grid = document.getElementById('fa-grid');
                var tmp  = document.createElement('div');
                tmp.innerHTML = html;
                var cards = tmp.querySelectorAll('.fa-card');
                cards.forEach(function(c){ grid.appendChild(c); });
                if (!cards.length || tmp.querySelector('.fa-empty')) {
                    btn.remove();
                } else {
                    btn.dataset.page = p + 1;
                    btn.disabled = false;
                    btn.textContent = 'Load more';
                }
            });
    });
})();
</script>
</body>
</html>
