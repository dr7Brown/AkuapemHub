<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

$user    = current_user();
$isAjax  = !empty($_GET['ajax']);
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 8;
$offset  = ($page - 1) * $perPage;

// If page 1 we show 1 featured + 8 cards = 9 DB rows; page 2+ just 8 cards
$fetchLimit = ($page === 1) ? $perPage + 1 : $perPage + 1; // +1 to detect hasMore

$search  = trim($_GET['q'] ?? '');
$nFilter = in_array($_GET['filter'] ?? '', ['latest','popular']) ? $_GET['filter'] : 'latest';
$orderBy = $nFilter === 'popular' ? 'view_count DESC, COALESCE(published_at, created_at) DESC'
                                  : 'COALESCE(published_at, created_at) DESC';

$whereExtra = '';
$stmtParams = [$fetchLimit, $offset];
if ($search) {
    $whereExtra  = " AND (title LIKE ? OR summary LIKE ?)";
    $stmtParams  = ["%$search%", "%$search%", $fetchLimit, $offset];
}

$stmt = $pdo->prepare("
    SELECT id, title, slug, summary, featured_image, view_count, published_at
    FROM news
    WHERE status='published' AND (published_at IS NULL OR published_at <= NOW())
    $whereExtra
    ORDER BY $orderBy
    LIMIT ? OFFSET ?
");
$stmt->execute($stmtParams);
$all      = $stmt->fetchAll();
$hasMore  = count($all) > $perPage;
$articles = array_slice($all, 0, $perPage);

// Active ads
$bannerAds    = $pdo->query("SELECT * FROM advertisements WHERE status='active' AND ad_type='banner' AND (start_date IS NULL OR start_date<=CURDATE()) AND (end_date IS NULL OR end_date>=CURDATE()) ORDER BY RAND() LIMIT 3")->fetchAll();
$sponsoredAds = $pdo->query("SELECT * FROM advertisements WHERE status='active' AND ad_type='sponsored' AND (start_date IS NULL OR start_date<=CURDATE()) AND (end_date IS NULL OR end_date>=CURDATE()) ORDER BY RAND() LIMIT 2")->fetchAll();

// ── AJAX fragment: return cards for page 2+ ──────────────────────────────────
if ($isAjax) {
    header('Content-Type: text/html; charset=utf-8');
    $sIdx = 0;
    foreach ($articles as $i => $a):
        $thumb = $a['featured_image'] ? sanitize($a['featured_image']) : '';
        $date  = $a['published_at'] ? date('M j, Y', strtotime($a['published_at'])) : '';
        $slug  = urlencode($a['slug']);
?>
<article class="nc-card">
    <?php if ($thumb): ?>
    <a href="news_article.php?slug=<?php echo $slug; ?>" class="nc-img-wrap">
        <img src="<?php echo $thumb; ?>" alt="<?php echo sanitize($a['title']); ?>" loading="lazy">
    </a>
    <?php else: ?>
    <a href="news_article.php?slug=<?php echo $slug; ?>" class="nc-img-wrap nc-img-placeholder">
        <span>📰</span>
    </a>
    <?php endif; ?>
    <div class="nc-body">
        <h3 class="nc-title"><a href="news_article.php?slug=<?php echo $slug; ?>"><?php echo sanitize($a['title']); ?></a></h3>
        <?php if ($a['summary']): ?><p class="nc-summary"><?php echo sanitize($a['summary']); ?></p><?php endif; ?>
        <div class="nc-foot">
            <?php if ($date): ?><span class="nc-date">📅 <?php echo $date; ?></span><?php endif; ?>
            <a href="news_article.php?slug=<?php echo $slug; ?>" class="nc-more">Read more →</a>
        </div>
    </div>
</article>
<?php
        if (($i + 1) % 4 === 0 && $sIdx < count($sponsoredAds)):
            $sp = $sponsoredAds[$sIdx++]; $spSlug = (int)$sp['id'];
?>
<article class="nc-card nc-sponsored">
    <span class="nc-sponsored-badge">Sponsored</span>
    <?php if ($sp['image']): ?>
    <a href="ad_click.php?id=<?php echo $spSlug; ?>" target="_blank" rel="noopener" class="nc-img-wrap">
        <img src="<?php echo sanitize($sp['image']); ?>" alt="<?php echo sanitize($sp['title']); ?>" loading="lazy">
    </a>
    <?php endif; ?>
    <div class="nc-body">
        <h3 class="nc-title"><?php echo sanitize($sp['title']); ?></h3>
        <div class="nc-foot"><a href="ad_click.php?id=<?php echo $spSlug; ?>" class="nc-more" target="_blank" rel="noopener">Learn more →</a></div>
    </div>
</article>
<?php endif; endforeach; ?>
<?php if ($hasMore): ?>
<div class="nc-load-wrap" id="nc-load-wrap" style="grid-column:1/-1;text-align:center;padding:20px 0;">
    <button class="button button-secondary" onclick="loadMoreNews(<?php echo $page + 1; ?>)">Load more articles</button>
</div>
<?php else: ?>
<p class="nc-end" style="grid-column:1/-1;">You've reached the end of the feed.</p>
<?php endif;
    exit;
}

// ── Separate featured from grid articles on page 1 ───────────────────────────
$featured  = ($page === 1 && $articles) ? $articles[0] : null;
$gridItems = ($page === 1 && $articles) ? array_slice($articles, 1) : $articles;
$topAd     = $bannerAds[0] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>News &amp; Updates — <?php echo sanitize(APP_NAME); ?></title>
    <meta name="description" content="Stay informed with the latest news, updates and stories from <?php echo sanitize(APP_NAME); ?>.">
    <meta property="og:title"       content="News — <?php echo sanitize(APP_NAME); ?>">
    <meta property="og:description" content="Latest news, updates and community stories from <?php echo sanitize(APP_NAME); ?>.">
    <meta property="og:type"        content="website">
    <link rel="stylesheet" href="assets/css/style.css" />
    <style>
        /* ── Layout ─────────────────────────────────────────── */
        .nc-shell    { max-width:1060px; margin:0 auto; padding:24px 16px 88px; }
        /* ── Hero ─────────────────────────────────────────────── */
        .nc-hero { background:linear-gradient(135deg,#14532d 0%,#166534 60%,#0f766e 100%); color:#fff; padding:36px 20px 32px; text-align:center; }
        .nc-hero h1 { font-size:clamp(1.4rem,4vw,2rem); font-weight:900; margin:0 0 8px; }
        .nc-hero p  { font-size:.95rem; color:#bbf7d0; margin:0 0 20px; }
        .nc-search-wrap { display:flex; gap:8px; max-width:440px; margin:0 auto; }
        .nc-search-wrap input  { flex:1; padding:10px 14px; border-radius:10px; border:1px solid #14532d; background:#166534; color:#fff; font-size:.9rem; }
        .nc-search-wrap input::placeholder { color:#6ee7b7; }
        .nc-search-wrap button { padding:10px 18px; border-radius:10px; background:var(--primary,#0f766e); color:#fff; border:none; font-weight:700; cursor:pointer; white-space:nowrap; }
        /* ── Filter tabs ──────────────────────────────────────── */
        .nc-filters { background:var(--surface,#fff); border-bottom:1px solid var(--border,#e5e7eb); padding:0 16px; display:flex; gap:0; overflow-x:auto; }
        .nc-filter-btn { padding:12px 18px; border:none; background:none; font-size:.85rem; font-weight:700; color:var(--text-muted,#6b7280); cursor:pointer; border-bottom:2px solid transparent; white-space:nowrap; text-decoration:none; }
        .nc-filter-btn.active { color:var(--primary,#0f766e); border-bottom-color:var(--primary,#0f766e); }
        .nc-filter-btn:hover  { color:var(--text,#111); }
        /* ── Toolbar ──────────────────────────────────────────── */
        .nc-toolbar { display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:20px; flex-wrap:wrap; }
        /* ── Featured story ──────────────────────────────────── */
        .nc-featured { position:relative; border-radius:20px; overflow:hidden; margin-bottom:32px; min-height:380px; background:var(--surface); display:block; text-decoration:none; color:inherit; }
        .nc-featured-bg { width:100%; height:420px; object-fit:cover; display:block; }
        .nc-featured-no-img { height:380px; background:linear-gradient(135deg,var(--primary) 0%,#0d5e57 100%); display:flex; align-items:flex-end; }
        .nc-featured-overlay { position:absolute; inset:0; background:linear-gradient(to top, rgba(0,0,0,.82) 0%, rgba(0,0,0,.35) 55%, transparent 100%); padding:28px 32px; display:flex; flex-direction:column; justify-content:flex-end; color:#fff; }
        .nc-featured-overlay.no-img { position:static; padding:28px 32px; }
        .nc-feat-date  { font-size:.78rem; font-weight:600; letter-spacing:.05em; text-transform:uppercase; opacity:.8; margin-bottom:10px; }
        .nc-feat-title { font-size:clamp(1.4rem,3vw,2rem); font-weight:900; line-height:1.2; margin:0 0 10px; }
        .nc-feat-sum   { font-size:.95rem; line-height:1.55; opacity:.9; margin:0 0 16px; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
        .nc-feat-cta   { display:inline-flex; align-items:center; gap:6px; border:1.5px solid rgba(255,255,255,.65); padding:7px 18px; border-radius:25px; font-size:.85rem; font-weight:700; backdrop-filter:blur(4px); align-self:flex-start; transition:background .2s; }
        .nc-featured:hover .nc-feat-cta { background:rgba(255,255,255,.18); }
        /* ── Banner ad ───────────────────────────────────────── */
        .nc-banner-ad  { text-align:center; margin-bottom:28px; }
        .nc-banner-ad a { display:inline-block; max-width:728px; width:100%; }
        .nc-banner-ad img { width:100%; border-radius:10px; }
        .nc-ad-label   { font-size:.68rem; letter-spacing:.07em; text-transform:uppercase; color:var(--text-muted); margin-bottom:5px; }
        .nc-ad-text-fallback { max-width:728px; margin:0 auto; background:var(--surface); border:1px solid var(--border); border-radius:10px; padding:18px; font-weight:600; color:var(--text-muted); }
        /* ── Article grid ────────────────────────────────────── */
        .nc-grid      { display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:22px; }
        /* ── Article card ────────────────────────────────────── */
        .nc-card      { background:var(--surface); border:1px solid var(--border); border-radius:16px; overflow:hidden; display:flex; flex-direction:column; transition:box-shadow .2s,transform .2s; }
        .nc-card:hover { box-shadow:0 8px 30px rgba(0,0,0,.10); transform:translateY(-2px); }
        .nc-img-wrap  { display:block; aspect-ratio:16/9; overflow:hidden; background:var(--surface-muted,#f3f4f6); }
        .nc-img-wrap img { width:100%; height:100%; object-fit:cover; transition:transform .35s; }
        .nc-card:hover .nc-img-wrap img { transform:scale(1.04); }
        .nc-img-placeholder { display:flex; align-items:center; justify-content:center; font-size:2.5rem; }
        .nc-body      { padding:16px 18px 18px; flex:1; display:flex; flex-direction:column; }
        .nc-title     { margin:0 0 8px; font-size:1rem; font-weight:700; line-height:1.4; }
        .nc-title a   { color:inherit; text-decoration:none; }
        .nc-title a:hover { color:var(--primary); }
        .nc-summary   { margin:0 0 12px; font-size:.84rem; color:var(--text-muted); line-height:1.55; display:-webkit-box; -webkit-line-clamp:3; -webkit-box-orient:vertical; overflow:hidden; }
        .nc-foot      { display:flex; justify-content:space-between; align-items:center; margin-top:auto; font-size:.8rem; }
        .nc-date      { color:var(--text-muted); }
        .nc-more      { color:var(--primary); font-weight:600; text-decoration:none; white-space:nowrap; }
        .nc-more:hover { text-decoration:underline; }
        /* ── Sponsored card ──────────────────────────────────── */
        .nc-sponsored { border-color:var(--primary); position:relative; }
        .nc-sponsored-badge { position:absolute; top:10px; left:10px; background:var(--primary); color:#fff; font-size:.66rem; font-weight:800; padding:3px 9px; border-radius:20px; z-index:1; letter-spacing:.04em; text-transform:uppercase; }
        /* ── Load more ───────────────────────────────────────── */
        .nc-load-wrap { grid-column:1/-1; text-align:center; padding:24px 0 0; }
        .nc-end       { grid-column:1/-1; text-align:center; color:var(--text-muted); font-size:.85rem; padding:24px 0 0; }
        /* ── Empty state ─────────────────────────────────────── */
        .nc-empty     { text-align:center; padding:60px 20px; color:var(--text-muted); }
        .nc-empty-icon { font-size:3rem; margin-bottom:12px; }
        /* ── Responsive ──────────────────────────────────────── */
        @media (max-width:520px) {
            .nc-feat-title { font-size:1.35rem; }
            .nc-featured-overlay { padding:18px 20px; }
            .nc-featured-bg { height:300px; }
        }
    </style>
</head>
<body <?php echo $user ? 'class="has-bottom-nav"' : ''; ?>>

    <?php if (!$user): ?>
    <header style="background:var(--surface,#fff);border-bottom:1px solid var(--border,#e5e7eb);padding:12px 16px;display:flex;align-items:center;justify-content:space-between;gap:12px;">
        <a href="index.php" style="font-weight:900;color:var(--primary,#0f766e);text-decoration:none;font-size:1.1rem;"><?php echo sanitize(APP_NAME); ?></a>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <a href="community.php" style="font-size:.85rem;color:var(--text-muted);text-decoration:none;font-weight:600;">Community</a>
            <a href="events.php"    style="font-size:.85rem;color:var(--text-muted);text-decoration:none;font-weight:600;">Events</a>
            <a href="login.php"     class="button button-secondary button-small">Sign in</a>
            <a href="register.php"  class="button button-primary button-small">Register</a>
        </div>
    </header>
    <?php endif; ?>

    <!-- Hero -->
    <div class="nc-hero">
        <h1>📰 News &amp; Updates</h1>
        <p>The latest stories and updates from <?php echo sanitize(APP_NAME); ?></p>
        <form class="nc-search-wrap" method="get" action="news.php">
            <input type="hidden" name="filter" value="<?php echo sanitize($nFilter); ?>">
            <input type="search" name="q" placeholder="Search articles…" value="<?php echo sanitize($search); ?>">
            <button type="submit">Search</button>
        </form>
    </div>

    <!-- Filter tabs -->
    <div class="nc-filters">
        <?php $baseQ = $search ? '&q=' . urlencode($search) : ''; ?>
        <a href="news.php?filter=latest<?php echo $baseQ; ?>"  class="nc-filter-btn <?php echo $nFilter === 'latest'  ? 'active' : ''; ?>">Latest</a>
        <a href="news.php?filter=popular<?php echo $baseQ; ?>" class="nc-filter-btn <?php echo $nFilter === 'popular' ? 'active' : ''; ?>">Popular</a>
        <?php if ($user): ?>
        <a href="news_saved.php" class="nc-filter-btn">🔖 Saved</a>
        <?php endif; ?>
    </div>

    <div class="nc-shell">

        <!-- Toolbar: result label + submit button -->
        <div class="nc-toolbar">
            <p style="margin:0;color:var(--text-muted);font-size:.9rem;">
                <?php echo $search ? 'Results for "' . sanitize($search) . '"' : ucfirst($nFilter) . ' articles'; ?>
            </p>
            <?php if ($user): ?>
            <a href="my_news.php" class="button button-primary button-small">✍️ Submit Article</a>
            <?php else: ?>
            <a href="login.php" class="button button-secondary button-small">Sign in to post</a>
            <?php endif; ?>
        </div>

        <?php if (!$articles && $page === 1): ?>
        <div class="nc-empty">
            <div class="nc-empty-icon">📰</div>
            <p style="font-size:1.05rem;font-weight:600;margin:0 0 6px;">No articles published yet</p>
            <p style="margin:0;">Check back soon for the latest updates.</p>
        </div>
        <?php else: ?>

        <!-- Featured story (page 1 only) -->
        <?php if ($featured): ?>
        <?php
            $fSlug  = urlencode($featured['slug']);
            $fDate  = $featured['published_at'] ? date('F j, Y', strtotime($featured['published_at'])) : '';
            $fThumb = $featured['featured_image'] ? sanitize($featured['featured_image']) : '';
        ?>
        <a href="news_article.php?slug=<?php echo $fSlug; ?>" class="nc-featured">
            <?php if ($fThumb): ?>
                <img src="<?php echo $fThumb; ?>" alt="<?php echo sanitize($featured['title']); ?>" class="nc-featured-bg">
                <div class="nc-featured-overlay">
            <?php else: ?>
                <div class="nc-featured-no-img">
                <div class="nc-featured-overlay no-img">
            <?php endif; ?>
                    <?php if ($fDate): ?><div class="nc-feat-date">📅 <?php echo $fDate; ?></div><?php endif; ?>
                    <h2 class="nc-feat-title"><?php echo sanitize($featured['title']); ?></h2>
                    <?php if ($featured['summary']): ?><p class="nc-feat-sum"><?php echo sanitize($featured['summary']); ?></p><?php endif; ?>
                    <span class="nc-feat-cta">Read article &rarr;</span>
                </div>
            <?php if (!$fThumb): ?></div><?php endif; ?>
        </a>
        <?php endif; ?>

        <!-- Top banner ad -->
        <?php if ($topAd): ?>
        <div class="nc-banner-ad">
            <div class="nc-ad-label">Advertisement</div>
            <a href="ad_click.php?id=<?php echo (int)$topAd['id']; ?>" target="_blank" rel="noopener sponsored">
                <?php if ($topAd['image']): ?>
                    <img src="<?php echo sanitize($topAd['image']); ?>" alt="<?php echo sanitize($topAd['title']); ?>">
                <?php else: ?>
                    <div class="nc-ad-text-fallback"><?php echo sanitize($topAd['title']); ?></div>
                <?php endif; ?>
            </a>
        </div>
        <?php endif; ?>

        <!-- Article grid -->
        <div class="nc-grid" id="nc-grid">
            <?php $sIdx = 0; foreach ($gridItems as $i => $a):
                $thumb = $a['featured_image'] ? sanitize($a['featured_image']) : '';
                $date  = $a['published_at'] ? date('M j, Y', strtotime($a['published_at'])) : '';
                $slug  = urlencode($a['slug']);
            ?>
            <article class="nc-card">
                <?php if ($thumb): ?>
                <a href="news_article.php?slug=<?php echo $slug; ?>" class="nc-img-wrap">
                    <img src="<?php echo $thumb; ?>" alt="<?php echo sanitize($a['title']); ?>" loading="lazy">
                </a>
                <?php else: ?>
                <a href="news_article.php?slug=<?php echo $slug; ?>" class="nc-img-wrap nc-img-placeholder">
                    <span>📰</span>
                </a>
                <?php endif; ?>
                <div class="nc-body">
                    <h3 class="nc-title"><a href="news_article.php?slug=<?php echo $slug; ?>"><?php echo sanitize($a['title']); ?></a></h3>
                    <?php if ($a['summary']): ?><p class="nc-summary"><?php echo sanitize($a['summary']); ?></p><?php endif; ?>
                    <div class="nc-foot">
                        <?php if ($date): ?><span class="nc-date">📅 <?php echo $date; ?></span><?php endif; ?>
                        <a href="news_article.php?slug=<?php echo $slug; ?>" class="nc-more">Read more →</a>
                    </div>
                </div>
            </article>

            <?php if (($i + 1) % 4 === 0 && $sIdx < count($sponsoredAds)):
                $sp = $sponsoredAds[$sIdx++]; ?>
            <article class="nc-card nc-sponsored">
                <span class="nc-sponsored-badge">Sponsored</span>
                <?php if ($sp['image']): ?>
                <a href="ad_click.php?id=<?php echo (int)$sp['id']; ?>" target="_blank" rel="noopener" class="nc-img-wrap">
                    <img src="<?php echo sanitize($sp['image']); ?>" alt="<?php echo sanitize($sp['title']); ?>" loading="lazy">
                </a>
                <?php else: ?>
                <a href="ad_click.php?id=<?php echo (int)$sp['id']; ?>" target="_blank" rel="noopener" class="nc-img-wrap nc-img-placeholder">
                    <span>📣</span>
                </a>
                <?php endif; ?>
                <div class="nc-body">
                    <h3 class="nc-title"><?php echo sanitize($sp['title']); ?></h3>
                    <div class="nc-foot"><a href="ad_click.php?id=<?php echo (int)$sp['id']; ?>" class="nc-more" target="_blank" rel="noopener">Learn more →</a></div>
                </div>
            </article>
            <?php endif; ?>
            <?php endforeach; ?>

            <?php if ($hasMore): ?>
            <div class="nc-load-wrap" id="nc-load-wrap">
                <button class="button button-secondary" onclick="loadMoreNews(2)" style="min-width:180px;">Load more articles</button>
            </div>
            <?php elseif ($gridItems): ?>
            <p class="nc-end">You're all caught up.</p>
            <?php endif; ?>
        </div>

        <?php endif; ?>
    </div>

    <?php if ($user): ?>
        <?php $activeNav = 'news'; require __DIR__ . '/partials/bottom_nav.php'; ?>
    <?php else: ?>
        <footer style="text-align:center;padding:20px 16px 32px;font-size:.8rem;color:#6b7280;border-top:1px solid #e5e7eb;margin-top:40px;">
            &copy; <?php echo date('Y'); ?> <?php echo sanitize(APP_NAME); ?> &nbsp;·&nbsp;
            <a href="privacy.php">Privacy</a> &nbsp;·&nbsp; <a href="terms.php">Terms</a>
        </footer>
    <?php endif; ?>

    <script>
    function loadMoreNews(page) {
        var wrap = document.getElementById('nc-load-wrap');
        if (!wrap) return;
        var btn = wrap.querySelector('button');
        if (btn) { btn.disabled = true; btn.textContent = 'Loading…'; }
        var params = new URLSearchParams(window.location.search);
        params.set('ajax', '1');
        params.set('page', page);
        fetch('news.php?' + params.toString())
            .then(function(r){ return r.text(); })
            .then(function(html) {
                wrap.remove();
                var tmp = document.createElement('div');
                tmp.innerHTML = html;
                var grid = document.getElementById('nc-grid');
                Array.from(tmp.childNodes).forEach(function(n) { grid.appendChild(n.cloneNode(true)); });
                var newWrap = document.getElementById('nc-load-wrap');
                if (newWrap) {
                    var newBtn = newWrap.querySelector('button');
                    if (newBtn) { var np = page + 1; newBtn.onclick = function(){ loadMoreNews(np); }; }
                }
            })
            .catch(function() { if (btn) { btn.disabled = false; btn.textContent = 'Try again'; } });
    }
    </script>
</body>
</html>
