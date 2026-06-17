<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

$user   = current_user();
$search = trim($_GET['q'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$perPage = 12;
$isAjax = !empty($_GET['ajax']);

function funeral_cards($pdo, $search, $page, $perPage, $featuredOnly = false) {
    $offset = ($page - 1) * $perPage;
    $like   = '%' . $search . '%';
    $params = [];
    $where  = "WHERE fa.status='approved'";
    if ($search) {
        $where .= " AND (fa.deceased_name LIKE ? OR fa.venue LIKE ? OR fa.organizer_name LIKE ?)";
        $params = [$like, $like, $like];
    }
    if ($featuredOnly) {
        $where .= " AND fa.featured=1";
    }
    $sql = "SELECT fa.* FROM funeral_announcements fa $where
            ORDER BY fa.featured DESC, fa.burial_date ASC
            LIMIT " . (int)$perPage . " OFFSET " . (int)$offset;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

$featured = [];
$cards    = [];

if (!$isAjax || $page === 1) {
    if (!$search) {
        $featured = funeral_cards($pdo, '', 1, 3, true);
    }
}

$cards = funeral_cards($pdo, $search, $page, $perPage);

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
    <title>Funeral Announcements — <?php echo APP_NAME; ?></title>
    <meta name="description" content="Funeral announcements and memorial information from the <?php echo APP_NAME; ?> community.">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .fa-shell { max-width:1060px; margin:0 auto; padding:20px 16px 60px; }
        .fa-hero  { background:linear-gradient(135deg,#1e293b 0%,#0f172a 100%); color:#fff; padding:36px 20px 32px; text-align:center; margin-bottom:28px; }
        .fa-hero h1 { font-size:clamp(1.4rem,4vw,2rem); font-weight:900; margin:0 0 8px; }
        .fa-hero p  { font-size:.95rem; color:#cbd5e1; margin:0 0 20px; }
        .fa-search-wrap { display:flex; gap:8px; max-width:440px; margin:0 auto; }
        .fa-search-wrap input { flex:1; padding:10px 14px; border-radius:10px; border:1px solid #334155; background:#1e293b; color:#fff; font-size:.9rem; }
        .fa-search-wrap input::placeholder { color:#64748b; }
        .fa-search-wrap button { padding:10px 18px; border-radius:10px; background:#0f766e; color:#fff; border:none; font-weight:700; cursor:pointer; }

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
    </style>
</head>
<body>
<!-- Guest top bar -->
<?php if (!$user): ?>
<header style="background:var(--surface,#fff);border-bottom:1px solid var(--border,#e5e7eb);padding:12px 16px;display:flex;align-items:center;justify-content:space-between;gap:12px;">
    <a href="index.php" style="font-weight:900;color:var(--primary,#0f766e);text-decoration:none;font-size:1.1rem;"><?php echo APP_NAME; ?></a>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <a href="community.php" style="font-size:.85rem;color:var(--text-muted,#6b7280);text-decoration:none;font-weight:600;">Community</a>
        <a href="events.php"    style="font-size:.85rem;color:var(--text-muted,#6b7280);text-decoration:none;font-weight:600;">Events</a>
        <a href="login.php"     class="button button-secondary button-small">Sign in</a>
        <a href="register.php"  class="button button-primary button-small">Register</a>
    </div>
</header>
<?php endif; ?>

<div class="fa-hero">
    <h1>🕊️ Funeral Announcements</h1>
    <p>Memorial information and funeral notices from our community</p>
    <form class="fa-search-wrap" method="get" action="funerals.php">
        <input type="search" name="q" placeholder="Search by name or location…" value="<?php echo sanitize($search); ?>">
        <button type="submit">Search</button>
    </form>
</div>

<div class="fa-shell">

    <?php if ($user): ?>
    <div class="fa-toolbar">
        <p style="margin:0;color:var(--text-muted);font-size:.9rem;"><?php echo $search ? 'Results for "' . sanitize($search) . '"' : 'All announcements'; ?></p>
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

    <?php if (count($cards) === $perPage): ?>
    <button class="fa-load-more" id="fa-load-more" data-page="2" data-search="<?php echo sanitize($search); ?>">Load more</button>
    <?php endif; ?>


</div>

<?php if ($user): require_once __DIR__ . '/partials/bottom_nav.php'; endif; ?>

<script>
(function(){
    var btn = document.getElementById('fa-load-more');
    if (!btn) return;
    btn.addEventListener('click', function(){
        var p = parseInt(btn.dataset.page);
        var q = btn.dataset.search;
        btn.disabled = true; btn.textContent = 'Loading…';
        fetch('funerals.php?ajax=1&page=' + p + (q ? '&q=' + encodeURIComponent(q) : ''))
            .then(function(r){ return r.text(); })
            .then(function(html){
                var grid = document.getElementById('fa-grid');
                var tmp  = document.createElement('div');
                tmp.innerHTML = html;
                var empty = tmp.querySelector('.fa-empty');
                tmp.querySelectorAll('.fa-card').forEach(function(c){ grid.appendChild(c); });
                if (empty || !tmp.querySelector('.fa-card')) {
                    btn.remove();
                } else {
                    btn.dataset.page = p + 1;
                    btn.disabled = false; btn.textContent = 'Load more';
                }
            });
    });
})();
</script>
</body>
</html>
