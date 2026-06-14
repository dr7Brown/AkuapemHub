<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

$user    = current_user();
$isAjax  = !empty($_GET['ajax']);
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 9;
$offset  = ($page - 1) * $perPage;

// Fetch published articles
$stmt = $pdo->prepare("
    SELECT id, title, slug, summary, featured_image, view_count, published_at
    FROM news
    WHERE status='published' AND (published_at IS NULL OR published_at <= NOW())
    ORDER BY COALESCE(published_at, created_at) DESC
    LIMIT ? OFFSET ?
");
$stmt->execute([$perPage + 1, $offset]);
$all      = $stmt->fetchAll();
$hasMore  = count($all) > $perPage;
$articles = array_slice($all, 0, $perPage);

// Active ads for the feed
$bannerAds     = $pdo->query("SELECT * FROM advertisements WHERE status='active' AND ad_type='banner'     AND (start_date IS NULL OR start_date<=CURDATE()) AND (end_date IS NULL OR end_date>=CURDATE()) ORDER BY RAND() LIMIT 3")->fetchAll();
$sponsoredAds  = $pdo->query("SELECT * FROM advertisements WHERE status='active' AND ad_type='sponsored' AND (start_date IS NULL OR start_date<=CURDATE()) AND (end_date IS NULL OR end_date>=CURDATE()) ORDER BY RAND() LIMIT 2")->fetchAll();

// If AJAX, return card fragment only
if ($isAjax) {
    header('Content-Type: text/html; charset=utf-8');
    $sIdx = 0;
    foreach ($articles as $i => $a):
        $thumb = $a['featured_image'] ? sanitize($a['featured_image']) : '';
        $date  = $a['published_at'] ? date('M j, Y', strtotime($a['published_at'])) : date('M j, Y', strtotime($a['created_at'] ?? 'now'));
?>
<article class="nc-card">
    <?php if ($thumb): ?>
        <a href="news_article.php?slug=<?php echo urlencode($a['slug']); ?>" class="nc-img-wrap">
            <img src="<?php echo $thumb; ?>" alt="<?php echo sanitize($a['title']); ?>">
        </a>
    <?php endif; ?>
    <div class="nc-body">
        <h2 class="nc-title"><a href="news_article.php?slug=<?php echo urlencode($a['slug']); ?>"><?php echo sanitize($a['title']); ?></a></h2>
        <?php if ($a['summary']): ?><p class="nc-summary"><?php echo sanitize($a['summary']); ?></p><?php endif; ?>
        <div class="nc-meta"><span>📅 <?php echo $date; ?></span><span>👁 <?php echo (int)$a['view_count']; ?></span></div>
        <a href="news_article.php?slug=<?php echo urlencode($a['slug']); ?>" class="button button-primary button-small" style="margin-top:8px;">Read More →</a>
    </div>
</article>
<?php
        // Inject sponsored post after every 3rd article
        if (($i + 1) % 3 === 0 && $sIdx < count($sponsoredAds)):
            $sp = $sponsoredAds[$sIdx++];
?>
<article class="nc-card nc-sponsored">
    <span class="nc-sponsored-badge">Sponsored</span>
    <?php if ($sp['image']): ?>
        <a href="ad_click.php?id=<?php echo (int)$sp['id']; ?>" target="_blank" rel="noopener" class="nc-img-wrap">
            <img src="<?php echo sanitize($sp['image']); ?>" alt="<?php echo sanitize($sp['title']); ?>">
        </a>
    <?php endif; ?>
    <div class="nc-body">
        <h2 class="nc-title"><?php echo sanitize($sp['title']); ?></h2>
        <a href="ad_click.php?id=<?php echo (int)$sp['id']; ?>" class="button button-small" target="_blank" rel="noopener" style="margin-top:8px;">Learn More →</a>
    </div>
</article>
<?php endif; endforeach; ?>
<?php if ($hasMore): ?>
<div class="nc-load-wrap" id="nc-load-wrap" style="grid-column:1/-1;text-align:center;padding:16px 0;">
    <button class="button button-secondary" onclick="loadMoreNews(<?php echo $page + 1; ?>)">Load more →</button>
</div>
<?php else: ?>
<p class="nc-end-msg" style="grid-column:1/-1;text-align:center;color:var(--text-muted);padding:16px 0;">You've reached the end.</p>
<?php endif; ?>
<?php exit; } ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>News — <?php echo sanitize(APP_NAME); ?></title>
    <meta name="description" content="Latest news, updates and community stories from <?php echo sanitize(APP_NAME); ?>.">
    <meta property="og:title" content="News — <?php echo sanitize(APP_NAME); ?>">
    <meta property="og:description" content="Latest news, updates and community stories.">
    <meta property="og:type" content="website">
    <link rel="stylesheet" href="assets/css/style.css" />
    <style>
        .nc-shell   { max-width: 1040px; margin: 0 auto; padding: 20px 16px 80px; }
        .nc-hero    { display:flex; align-items:center; justify-content:space-between; margin-bottom: 22px; flex-wrap:wrap; gap:10px; }
        .nc-hero h1 { margin:0; font-size:1.5rem; }
        .nc-grid    { display:grid; grid-template-columns: repeat(auto-fill, minmax(290px,1fr)); gap:20px; }
        .nc-card    { background:var(--surface); border:1px solid var(--border); border-radius:12px; overflow:hidden; display:flex; flex-direction:column; transition:box-shadow .15s; }
        .nc-card:hover { box-shadow: 0 4px 18px rgba(0,0,0,.08); }
        .nc-img-wrap { display:block; aspect-ratio:16/9; overflow:hidden; }
        .nc-img-wrap img { width:100%; height:100%; object-fit:cover; transition:transform .25s; }
        .nc-card:hover .nc-img-wrap img { transform:scale(1.03); }
        .nc-body    { padding:14px 16px 16px; flex:1; display:flex; flex-direction:column; }
        .nc-title   { margin:0 0 8px; font-size:1rem; line-height:1.4; }
        .nc-title a { color:inherit; text-decoration:none; }
        .nc-title a:hover { color:var(--primary); }
        .nc-summary { margin:0 0 8px; font-size:.85rem; color:var(--text-muted); line-height:1.5; display:-webkit-box; -webkit-line-clamp:3; -webkit-box-orient:vertical; overflow:hidden; }
        .nc-meta    { display:flex; gap:14px; font-size:.78rem; color:var(--text-muted); margin-bottom:4px; }
        .nc-sponsored { border-color:var(--primary); position:relative; }
        .nc-sponsored-badge { position:absolute; top:10px; right:10px; background:var(--primary); color:#fff; font-size:.7rem; font-weight:700; padding:2px 8px; border-radius:20px; z-index:1; }
        .nc-banner-ad { grid-column:1/-1; text-align:center; padding:4px 0; }
        .nc-banner-ad a { display:inline-block; max-width:728px; width:100%; }
        .nc-banner-ad img { width:100%; border-radius:8px; }
        .nc-banner-ad .nc-ad-label { font-size:.7rem; color:var(--text-muted); margin-bottom:4px; }
        .nc-load-wrap  { grid-column:1/-1; text-align:center; padding:16px 0; }
        .nc-end-msg    { grid-column:1/-1; }
        @media (max-width:480px) { .nc-grid { grid-template-columns:1fr; } }
    </style>
</head>
<body <?php echo $user ? 'class="has-bottom-nav"' : ''; ?>>
    <header class="app-topbar">
        <a href="<?php echo $user ? 'dashboard.php' : 'index.php'; ?>" class="button button-secondary button-small">← Back</a>
        <span class="brand"><?php echo sanitize(APP_NAME); ?></span>
        <?php if (!$user): ?>
            <a href="login.php" class="button button-primary button-small">Sign in</a>
        <?php else: ?>
            <a href="dashboard.php" class="button button-secondary button-small">Dashboard</a>
        <?php endif; ?>
    </header>

    <div class="nc-shell">
        <div class="nc-hero">
            <h1>📰 News &amp; Updates</h1>
            <p style="margin:0;color:var(--text-muted);font-size:.9rem;">Latest stories from <?php echo sanitize(APP_NAME); ?></p>
        </div>

        <?php if ($bannerAds): $topAd = $bannerAds[0]; ?>
        <div class="nc-banner-ad" style="margin-bottom:20px;">
            <div class="nc-ad-label">Advertisement</div>
            <a href="ad_click.php?id=<?php echo (int)$topAd['id']; ?>" target="_blank" rel="noopener sponsored">
                <?php if ($topAd['image']): ?>
                    <img src="<?php echo sanitize($topAd['image']); ?>" alt="<?php echo sanitize($topAd['title']); ?>">
                <?php else: ?>
                    <div style="background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:20px;font-weight:600;color:var(--text-muted);"><?php echo sanitize($topAd['title']); ?></div>
                <?php endif; ?>
            </a>
        </div>
        <?php endif; ?>

        <?php if (!$articles): ?>
            <div class="alert" style="text-align:center;padding:40px 20px;color:var(--text-muted);">
                <p style="font-size:2rem;margin:0 0 8px;">📰</p>
                <p>No news articles yet. Check back soon!</p>
            </div>
        <?php else: ?>

        <div class="nc-grid" id="nc-grid">
            <?php $sIdx = 0; foreach ($articles as $i => $a):
                $thumb = $a['featured_image'] ? sanitize($a['featured_image']) : '';
                $date  = $a['published_at'] ? date('M j, Y', strtotime($a['published_at'])) : '';
            ?>
            <article class="nc-card">
                <?php if ($thumb): ?>
                    <a href="news_article.php?slug=<?php echo urlencode($a['slug']); ?>" class="nc-img-wrap">
                        <img src="<?php echo $thumb; ?>" alt="<?php echo sanitize($a['title']); ?>" loading="lazy">
                    </a>
                <?php endif; ?>
                <div class="nc-body">
                    <h2 class="nc-title"><a href="news_article.php?slug=<?php echo urlencode($a['slug']); ?>"><?php echo sanitize($a['title']); ?></a></h2>
                    <?php if ($a['summary']): ?><p class="nc-summary"><?php echo sanitize($a['summary']); ?></p><?php endif; ?>
                    <div class="nc-meta"><span>📅 <?php echo $date; ?></span><span>👁 <?php echo (int)$a['view_count']; ?></span></div>
                    <a href="news_article.php?slug=<?php echo urlencode($a['slug']); ?>" class="button button-primary button-small" style="margin-top:auto;padding-top:8px;">Read More →</a>
                </div>
            </article>
            <?php
            // Inject sponsored post after every 3rd article
            if (($i + 1) % 3 === 0 && $sIdx < count($sponsoredAds)):
                $sp = $sponsoredAds[$sIdx++];
            ?>
            <article class="nc-card nc-sponsored">
                <span class="nc-sponsored-badge">Sponsored</span>
                <?php if ($sp['image']): ?>
                    <a href="ad_click.php?id=<?php echo (int)$sp['id']; ?>" target="_blank" rel="noopener" class="nc-img-wrap">
                        <img src="<?php echo sanitize($sp['image']); ?>" alt="<?php echo sanitize($sp['title']); ?>" loading="lazy">
                    </a>
                <?php endif; ?>
                <div class="nc-body">
                    <h2 class="nc-title"><?php echo sanitize($sp['title']); ?></h2>
                    <a href="ad_click.php?id=<?php echo (int)$sp['id']; ?>" class="button button-small" target="_blank" rel="noopener" style="margin-top:auto;padding-top:8px;">Learn More →</a>
                </div>
            </article>
            <?php endif; ?>
            <?php endforeach; ?>

            <?php if ($hasMore): ?>
            <div class="nc-load-wrap" id="nc-load-wrap">
                <button class="button button-secondary" onclick="loadMoreNews(2)">Load more articles →</button>
            </div>
            <?php else: ?>
            <p style="grid-column:1/-1;text-align:center;color:var(--text-muted);padding:16px 0;">You've reached the end.</p>
            <?php endif; ?>

            <?php if (!empty($bannerAds[1])): $midAd = $bannerAds[1]; ?>
            <!-- Removed from grid, shown below -->
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
        if (wrap) wrap.innerHTML = '<span style="color:var(--text-muted)">Loading…</span>';
        fetch('news.php?ajax=1&page=' + page)
            .then(function(r){ return r.text(); })
            .then(function(html) {
                if (wrap) wrap.remove();
                var tmp = document.createElement('div');
                tmp.innerHTML = html;
                var grid = document.getElementById('nc-grid');
                Array.from(tmp.childNodes).forEach(function(n){ grid.appendChild(n.cloneNode(true)); });
                // Re-attach new load-more button handler
                var newWrap = document.getElementById('nc-load-wrap');
                if (newWrap) {
                    var btn = newWrap.querySelector('button');
                    if (btn) {
                        var nextPage = page + 1;
                        btn.onclick = function(){ loadMoreNews(nextPage); };
                    }
                }
            })
            .catch(function(){ if (wrap) wrap.innerHTML = '<p style="color:red;">Failed to load. Please refresh.</p>'; });
    }
    </script>
</body>
</html>
