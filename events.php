<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

$user    = current_user();
$search  = trim($_GET['q'] ?? '');
$filter  = in_array($_GET['filter'] ?? '', ['upcoming','past','featured']) ? $_GET['filter'] : 'upcoming';
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 12;
$isAjax  = !empty($_GET['ajax']);
$today   = date('Y-m-d');

function event_cards($pdo, $search, $filter, $page, $perPage) {
    $offset = ($page - 1) * $perPage;
    $like   = '%' . $search . '%';
    $params = [];
    $where  = "WHERE e.status='published'";
    if ($search) {
        $where .= " AND (e.title LIKE ? OR e.venue LIKE ? OR e.organizer_name LIKE ?)";
        $params = [$like, $like, $like];
    }
    global $today;
    if ($filter === 'past') {
        $where .= " AND e.start_date < ?"; $params[] = $today;
        $order  = "ORDER BY e.start_date DESC";
    } elseif ($filter === 'featured') {
        $where .= " AND e.featured=1";
        $order  = "ORDER BY e.start_date ASC";
    } else { // upcoming (default)
        $where .= " AND e.start_date >= ?"; $params[] = $today;
        $order  = "ORDER BY (e.featured=1 AND (e.featured_end_date IS NULL OR e.featured_end_date>=CURDATE())) DESC, e.start_date ASC";
    }
    $sql = "SELECT e.* FROM events e $where $order
            LIMIT " . (int)$perPage . " OFFSET " . (int)$offset;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

$events = event_cards($pdo, $search, $filter, $page, $perPage);

// Sidebar data
$sidebarNews     = $pdo->query("SELECT title, slug, published_at FROM news WHERE status='published' ORDER BY COALESCE(published_at,created_at) DESC LIMIT 5")->fetchAll();
$sidebarFunerals = $pdo->query("SELECT deceased_name, slug, burial_date, venue FROM funeral_announcements WHERE status='approved' ORDER BY created_at DESC LIMIT 4")->fetchAll();
$sidebarAd       = $pdo->query("SELECT * FROM advertisements WHERE status='active' AND ad_type='banner' AND (start_date IS NULL OR start_date<=CURDATE()) AND (end_date IS NULL OR end_date>=CURDATE()) ORDER BY RAND() LIMIT 1")->fetch();

if ($isAjax) {
    foreach ($events as $ev): ?>
    <div class="ev-card<?php echo $ev['featured'] ? ' ev-featured' : ''; ?>">
        <a href="event.php?slug=<?php echo urlencode($ev['slug']); ?>" class="ev-card-inner">
            <div class="ev-card-img">
                <?php if ($ev['featured_image']): ?>
                    <img src="<?php echo sanitize($ev['featured_image']); ?>" alt="<?php echo sanitize($ev['title']); ?>">
                <?php else: ?>
                    <span class="ev-no-img">📅</span>
                <?php endif; ?>
                <?php if ($ev['featured']): ?><span class="ev-badge-featured">Featured</span><?php endif; ?>
                <span class="ev-badge-<?php echo $ev['ticket_type']; ?>"><?php echo $ev['ticket_type'] === 'paid' ? '🎟️ Paid' : ($ev['ticket_type'] === 'registration' ? '📝 Register' : 'Free'); ?></span>
            </div>
            <div class="ev-card-body">
                <h3 class="ev-card-title"><?php echo sanitize($ev['title']); ?></h3>
                <p class="ev-card-date">📅 <?php echo date('D, d M Y', strtotime($ev['start_date'])); ?><?php if ($ev['start_time']): ?> · <?php echo date('g:i A', strtotime($ev['start_time'])); ?><?php endif; ?></p>
                <?php if ($ev['venue']): ?><p class="ev-card-venue">📍 <?php echo sanitize(mb_substr($ev['venue'],0,60)); ?></p><?php endif; ?>
                <span class="ev-card-btn">View Event →</span>
            </div>
        </a>
    </div>
    <?php endforeach;
    if (!$events) echo '<p class="ev-empty">No more events.</p>';
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php echo seo_meta([
        'title'       => 'Community Events — Akuapem Area, Ghana | ' . APP_NAME,
        'description' => 'Discover upcoming community events, conferences, festivals, and church programs across the Akuapem area of Ghana.',
        'url'         => rtrim(BASE_URL, '/') . '/events.php',
        'noindex'     => !empty($_GET['q']) || !empty($_GET['page']),
    ]); ?>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .ev-shell { max-width:1060px; margin:0 auto; padding:20px 16px 60px; }
        .ev-hero  { background:linear-gradient(135deg,#1e3a5f 0%,#0f2040 100%); color:#fff; padding:36px 20px 32px; text-align:center; margin-bottom:0; }
        .ev-hero h1 { font-size:clamp(1.4rem,4vw,2rem); font-weight:900; margin:0 0 8px; }
        .ev-hero p  { font-size:.95rem; color:#93c5fd; margin:0 0 20px; }
        .ev-search-wrap { display:flex; gap:8px; max-width:440px; margin:0 auto; }
        .ev-search-wrap input  { flex:1; padding:10px 14px; border-radius:10px; border:1px solid #1e3a5f; background:#0f2040; color:#fff; font-size:.9rem; }
        .ev-search-wrap input::placeholder { color:#64748b; }
        .ev-search-wrap button { padding:10px 18px; border-radius:10px; background:#2563eb; color:#fff; border:none; font-weight:700; cursor:pointer; }

        /* Filter tabs */
        .ev-filters { background:var(--surface,#fff); border-bottom:1px solid var(--border,#e5e7eb); padding:0 16px; display:flex; gap:0; overflow-x:auto; }
        .ev-filter-btn { padding:12px 18px; border:none; background:none; font-size:.85rem; font-weight:700; color:var(--text-muted,#6b7280); cursor:pointer; border-bottom:2px solid transparent; white-space:nowrap; text-decoration:none; }
        .ev-filter-btn.active { color:var(--primary,#0f766e); border-bottom-color:var(--primary,#0f766e); }
        .ev-filter-btn:hover  { color:var(--text,#111); }

        .ev-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(240px,1fr)); gap:16px; }
        .ev-card { background:var(--surface,#fff); border:1px solid var(--border,#e5e7eb); border-radius:14px; overflow:hidden; transition:box-shadow .15s,transform .15s; }
        .ev-card.ev-featured { border-color:#2563eb; }
        .ev-card:hover { box-shadow:0 6px 24px rgba(0,0,0,.1); transform:translateY(-2px); }
        .ev-card-inner { display:flex; flex-direction:column; text-decoration:none; color:inherit; height:100%; }
        .ev-card-img  { aspect-ratio:16/9; background:#f3f4f6; position:relative; overflow:hidden; display:flex; align-items:center; justify-content:center; }
        .ev-card-img img { width:100%; height:100%; object-fit:cover; }
        .ev-no-img    { font-size:2.5rem; }
        .ev-badge-featured { position:absolute; top:8px; left:8px; background:#2563eb; color:#fff; font-size:.65rem; font-weight:800; padding:3px 8px; border-radius:20px; }
        .ev-badge-free  { position:absolute; top:8px; right:8px; background:#d1fae5; color:#065f46; font-size:.65rem; font-weight:800; padding:3px 8px; border-radius:20px; }
        .ev-badge-paid  { position:absolute; top:8px; right:8px; background:#fef3c7; color:#92400e; font-size:.65rem; font-weight:800; padding:3px 8px; border-radius:20px; }
        .ev-badge-registration { position:absolute; top:8px; right:8px; background:#e0e7ff; color:#3730a3; font-size:.65rem; font-weight:800; padding:3px 8px; border-radius:20px; }
        .ev-card-body  { padding:12px 14px 14px; display:flex; flex-direction:column; gap:4px; flex:1; }
        .ev-card-title { font-weight:800; font-size:.95rem; margin:0; }
        .ev-card-date  { font-size:.8rem; color:var(--text,#111); margin:0; }
        .ev-card-venue { font-size:.76rem; color:var(--text-muted,#6b7280); margin:0; }
        .ev-card-btn   { display:inline-block; margin-top:auto; padding-top:8px; font-size:.78rem; color:var(--primary,#0f766e); font-weight:700; }

        .ev-toolbar { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; margin:16px 0; }
        .ev-empty  { text-align:center; padding:48px 20px; color:var(--text-muted,#6b7280); grid-column:1/-1; }
        .ev-load-more { display:block; margin:24px auto 0; padding:11px 28px; background:var(--primary,#0f766e); color:#fff; border:none; border-radius:10px; font-weight:700; font-size:.9rem; cursor:pointer; }
        .ev-load-more:disabled { opacity:.5; cursor:default; }

        @media(max-width:480px) { .ev-grid { grid-template-columns:1fr 1fr; } }

        /* ── Two-column layout ── */
        .ev-layout  { display:block; }
        .ev-sidebar { display:none; }
        @media(min-width:900px) {
            .ev-shell   { max-width:1200px; }
            .ev-layout  { display:grid; grid-template-columns:1fr 280px; gap:32px; align-items:start; }
            .ev-sidebar { display:flex; flex-direction:column; gap:20px; position:sticky; top:16px; }
        }
        /* ── Sidebar widgets (shared nsb-* from news.php) ── */
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
        .nsb-funeral { padding:10px 14px; border-bottom:1px solid var(--border,#e5e7eb); }
        .nsb-funeral:last-child { border-bottom:none; }
        .nsb-funeral-name { font-size:.82rem; font-weight:700; }
        .nsb-funeral-name a { color:var(--text,#111); text-decoration:none; }
        .nsb-funeral-name a:hover { color:var(--primary,#0f766e); }
        .nsb-funeral-meta { font-size:.72rem; color:var(--muted,#6b7280); margin-top:2px; }
        .nsb-cta    { padding:16px; text-align:center; }
        .nsb-ad     { padding:10px; text-align:center; }
        .nsb-ad img { width:100%; border-radius:8px; }
        .nsb-ad-label { font-size:.65rem; text-transform:uppercase; letter-spacing:.07em; color:var(--muted,#6b7280); margin-bottom:6px; }
    </style>
</head>
<body>
<?php if (!$user): ?>
<header style="background:var(--surface,#fff);border-bottom:1px solid var(--border,#e5e7eb);padding:12px 16px;display:flex;align-items:center;justify-content:space-between;gap:12px;">
    <a href="index.php" style="font-weight:900;color:var(--primary,#0f766e);text-decoration:none;font-size:1.1rem;"><?php echo APP_NAME; ?></a>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <a href="community.php"  style="font-size:.85rem;color:var(--text-muted);text-decoration:none;font-weight:600;">Community</a>
        <a href="funerals.php"   style="font-size:.85rem;color:var(--text-muted);text-decoration:none;font-weight:600;">Funerals</a>
        <a href="login.php"      class="button button-secondary button-small">Sign in</a>
    </div>
</header>
<?php endif; ?>

<div class="ev-hero">
    <h1>📅 Community Events</h1>
    <p>Conferences, festivals, church programs, weddings &amp; more</p>
    <form class="ev-search-wrap" method="get" action="events.php">
        <input type="hidden" name="filter" value="<?php echo sanitize($filter); ?>">
        <input type="search" name="q" placeholder="Search events or venues…" value="<?php echo sanitize($search); ?>">
        <button type="submit">Search</button>
    </form>
</div>

<div class="ev-filters">
    <?php $baseQ = $search ? '&q=' . urlencode($search) : ''; ?>
    <a href="events.php?filter=upcoming<?php echo $baseQ; ?>" class="ev-filter-btn <?php echo $filter==='upcoming' ? 'active' : ''; ?>">Upcoming</a>
    <a href="events.php?filter=featured<?php echo $baseQ; ?>" class="ev-filter-btn <?php echo $filter==='featured' ? 'active' : ''; ?>">Featured</a>
    <a href="events.php?filter=past<?php echo $baseQ; ?>"     class="ev-filter-btn <?php echo $filter==='past'     ? 'active' : ''; ?>">Past Events</a>
</div>

<div class="ev-shell">
<div class="ev-layout">

<!-- ── Main column ── -->
<div class="ev-main">
    <div class="ev-toolbar">
        <p style="margin:0;color:var(--text-muted,#6b7280);font-size:.9rem;">
            <?php echo $search ? 'Results for "' . sanitize($search) . '"' : ucfirst($filter) . ' events'; ?>
        </p>
        <?php if ($user): ?>
        <a href="my_events.php" class="button button-primary button-small">➕ Submit Event</a>
        <?php else: ?>
        <a href="login.php" class="button button-secondary button-small">Sign in to post</a>
        <?php endif; ?>
    </div>

    <div class="ev-grid" id="ev-grid">
        <?php foreach ($events as $ev): ?>
        <div class="ev-card<?php echo $ev['featured'] ? ' ev-featured' : ''; ?>">
            <a href="event.php?slug=<?php echo urlencode($ev['slug']); ?>" class="ev-card-inner">
                <div class="ev-card-img">
                    <?php if ($ev['featured_image']): ?>
                        <img src="<?php echo sanitize($ev['featured_image']); ?>" alt="<?php echo sanitize($ev['title']); ?>">
                    <?php else: ?>
                        <span class="ev-no-img">📅</span>
                    <?php endif; ?>
                    <?php if ($ev['featured']): ?><span class="ev-badge-featured">Featured</span><?php endif; ?>
                    <span class="ev-badge-<?php echo $ev['ticket_type']; ?>"><?php echo $ev['ticket_type'] === 'paid' ? '🎟️ Paid' : ($ev['ticket_type'] === 'registration' ? '📝 Register' : 'Free'); ?></span>
                </div>
                <div class="ev-card-body">
                    <h3 class="ev-card-title"><?php echo sanitize($ev['title']); ?></h3>
                    <p class="ev-card-date">📅 <?php echo date('D, d M Y', strtotime($ev['start_date'])); ?><?php if ($ev['start_time']): ?> · <?php echo date('g:i A', strtotime($ev['start_time'])); ?><?php endif; ?></p>
                    <?php if ($ev['venue']): ?><p class="ev-card-venue">📍 <?php echo sanitize(mb_substr($ev['venue'],0,60)); ?></p><?php endif; ?>
                    <span class="ev-card-btn">View Event →</span>
                </div>
            </a>
        </div>
        <?php endforeach; ?>
        <?php if (!$events): ?><p class="ev-empty">No <?php echo $filter; ?> events found<?php echo $search ? ' for "' . sanitize($search) . '"' : ''; ?>.</p><?php endif; ?>
    </div>

    <?php if (count($events) === $perPage): ?>
    <button class="ev-load-more" id="ev-load-more" data-page="2" data-search="<?php echo sanitize($search); ?>" data-filter="<?php echo sanitize($filter); ?>">Load more</button>
    <?php endif; ?>
</div><!-- /ev-main -->

<!-- ── Sidebar ── -->
<aside class="ev-sidebar">

    <?php if ($user): ?>
    <div class="nsb-widget">
        <div class="nsb-cta">
            <p style="font-size:.85rem;font-weight:700;margin:0 0 10px;">Organising something?</p>
            <a href="my_events.php" class="button button-primary button-small" style="width:100%;justify-content:center;">➕ Submit an Event</a>
        </div>
    </div>
    <?php else: ?>
    <div class="nsb-widget">
        <div class="nsb-cta">
            <p style="font-size:.85rem;font-weight:700;margin:0 0 4px;">Organising something?</p>
            <p style="font-size:.78rem;color:var(--muted,#6b7280);margin:0 0 10px;">Sign in to submit your event.</p>
            <a href="register.php" class="button button-primary button-small" style="width:100%;justify-content:center;">Join free</a>
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

    <!-- Recent Funerals -->
    <?php if ($sidebarFunerals): ?>
    <div class="nsb-widget">
        <div class="nsb-head">🕊️ Recent Announcements</div>
        <?php foreach ($sidebarFunerals as $fa): ?>
        <div class="nsb-funeral">
            <div class="nsb-funeral-name">
                <a href="funeral.php?slug=<?php echo urlencode($fa['slug']); ?>"><?php echo sanitize($fa['deceased_name']); ?></a>
            </div>
            <div class="nsb-funeral-meta">
                <?php if ($fa['burial_date']): ?>⚰️ <?php echo date('D, M j', strtotime($fa['burial_date'])); ?><?php endif; ?>
                <?php if ($fa['venue']): ?> · <?php echo sanitize(mb_substr($fa['venue'],0,35)); ?><?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <div style="padding:10px 14px;">
            <a href="funerals.php" style="font-size:.8rem;color:var(--primary,#0f766e);font-weight:700;text-decoration:none;">All announcements →</a>
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

</aside><!-- /ev-sidebar -->

</div><!-- /ev-layout -->
</div><!-- /ev-shell -->

<?php if ($user): require_once __DIR__ . '/partials/bottom_nav.php'; endif; ?>

<script>
(function(){
    var btn = document.getElementById('ev-load-more');
    if (!btn) return;
    btn.addEventListener('click', function(){
        var p = parseInt(btn.dataset.page);
        var q = btn.dataset.search;
        var f = btn.dataset.filter;
        btn.disabled = true; btn.textContent = 'Loading…';
        var qs = 'events.php?ajax=1&page=' + p + '&filter=' + encodeURIComponent(f) + (q ? '&q=' + encodeURIComponent(q) : '');
        fetch(qs)
            .then(function(r){ return r.text(); })
            .then(function(html){
                var grid = document.getElementById('ev-grid');
                var tmp  = document.createElement('div');
                tmp.innerHTML = html;
                tmp.querySelectorAll('.ev-card').forEach(function(c){ grid.appendChild(c); });
                if (!tmp.querySelector('.ev-card')) { btn.remove(); return; }
                btn.dataset.page = p + 1;
                btn.disabled = false; btn.textContent = 'Load more';
            });
    });
})();
</script>
</body>
</html>
