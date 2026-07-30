<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_module_enabled('news', 'News');
require_login();
$user = current_user();

$tab = in_array($_GET['tab'] ?? '', ['history']) ? 'history' : 'saved';

// Saved articles
$savedStmt = $pdo->prepare("
    SELECT n.id, n.title, n.slug, n.summary, n.featured_image, n.published_at, n.view_count, ns.created_at AS saved_at
    FROM news_saves ns
    JOIN news n ON n.id = ns.news_id AND n.status = 'published'
    WHERE ns.user_id = ?
    ORDER BY ns.created_at DESC
    LIMIT 50
");
$savedStmt->execute([$user['id']]);
$savedArticles = $savedStmt->fetchAll();

// View history
$histStmt = $pdo->prepare("
    SELECT n.id, n.title, n.slug, n.summary, n.featured_image, n.published_at, n.view_count, MAX(nv.viewed_at) AS viewed_at
    FROM news_views nv
    JOIN news n ON n.id = nv.news_id AND n.status = 'published'
    WHERE nv.user_id = ?
    GROUP BY n.id, n.title, n.slug, n.summary, n.featured_image, n.published_at, n.view_count
    ORDER BY viewed_at DESC
    LIMIT 50
");
$histStmt->execute([$user['id']]);
$historyArticles = $histStmt->fetchAll();

$activeList = $tab === 'history' ? $historyArticles : $savedArticles;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?php echo $tab === 'history' ? 'Reading History' : 'Saved Articles'; ?> — <?php echo sanitize(APP_NAME); ?></title>
    <link rel="stylesheet" href="assets/css/style.css" />
    <style>
        .ns-shell  { max-width:760px; margin:0 auto; padding:20px 16px 88px; }
        .ns-tabs   { display:flex; gap:4px; background:var(--surface); border:1px solid var(--border); border-radius:10px; padding:4px; margin-bottom:22px; width:fit-content; }
        .ns-tab    { padding:7px 20px; border-radius:8px; font-size:.88rem; font-weight:600; cursor:pointer; color:var(--text-muted); text-decoration:none; transition:all .15s; }
        .ns-tab.active { background:var(--primary); color:#fff; }
        .ns-list   { display:flex; flex-direction:column; gap:14px; }
        .ns-item   { display:flex; gap:14px; background:var(--surface); border:1px solid var(--border); border-radius:12px; overflow:hidden; transition:box-shadow .15s; }
        .ns-item:hover { box-shadow:0 4px 16px rgba(0,0,0,.08); }
        .ns-thumb  { width:100px; min-height:80px; flex-shrink:0; overflow:hidden; background:var(--surface-muted,#f3f4f6); }
        .ns-thumb img  { width:100%; height:100%; object-fit:cover; }
        .ns-thumb-ph   { width:100%; height:100%; display:flex; align-items:center; justify-content:center; font-size:1.8rem; }
        .ns-body   { flex:1; padding:12px 14px 12px 0; display:flex; flex-direction:column; justify-content:space-between; }
        .ns-title  { margin:0 0 6px; font-size:.95rem; font-weight:700; line-height:1.35; }
        .ns-title a { color:inherit; text-decoration:none; }
        .ns-title a:hover { color:var(--primary); }
        .ns-summary { font-size:.82rem; color:var(--text-muted); line-height:1.5; margin:0 0 8px; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
        .ns-meta   { display:flex; gap:12px; font-size:.76rem; color:var(--text-muted); align-items:center; flex-wrap:wrap; }
        .ns-empty  { text-align:center; padding:60px 20px; color:var(--text-muted); }
        .ns-empty-icon { font-size:3rem; margin-bottom:12px; }
        @media (max-width:440px) { .ns-thumb { width:72px; } }
    </style>
</head>
<body class="has-bottom-nav">
    <header class="app-topbar">
        <a href="news.php" class="button button-secondary button-small">← News</a>
        <span class="brand">My Reading</span>
    </header>

    <div class="ns-shell">
        <div class="ns-tabs">
            <a href="news_saved.php?tab=saved"   class="ns-tab <?php echo $tab === 'saved'   ? 'active' : ''; ?>">🔖 Saved (<?php echo count($savedArticles); ?>)</a>
            <a href="news_saved.php?tab=history" class="ns-tab <?php echo $tab === 'history' ? 'active' : ''; ?>">📊 History (<?php echo count($historyArticles); ?>)</a>
        </div>

        <?php if (!$activeList): ?>
        <div class="ns-empty">
            <div class="ns-empty-icon"><?php echo $tab === 'history' ? '📊' : '🔖'; ?></div>
            <p style="font-size:1rem;font-weight:600;margin:0 0 6px;">
                <?php echo $tab === 'history' ? 'No articles read yet' : 'No saved articles yet'; ?>
            </p>
            <p style="margin:0 0 16px;">
                <?php echo $tab === 'history' ? 'Articles you read will appear here.' : 'Tap the 🔖 Save button on any article to add it here.'; ?>
            </p>
            <a href="news.php" class="button button-primary">Browse News →</a>
        </div>
        <?php else: ?>
        <div class="ns-list">
            <?php foreach ($activeList as $a):
                $thumb   = $a['featured_image'] ? sanitize($a['featured_image']) : '';
                $slug    = urlencode($a['slug']);
                $pubDate = $a['published_at'] ? date('M j, Y', strtotime($a['published_at'])) : '';
                $timeAgo = $tab === 'history'
                    ? 'Viewed ' . date('M j, Y', strtotime($a['viewed_at']))
                    : 'Saved '  . date('M j, Y', strtotime($a['saved_at']));
            ?>
            <div class="ns-item">
                <div class="ns-thumb">
                    <?php if ($thumb): ?>
                        <img src="<?php echo $thumb; ?>" alt="<?php echo sanitize($a['title']); ?>">
                    <?php else: ?>
                        <div class="ns-thumb-ph">📰</div>
                    <?php endif; ?>
                </div>
                <div class="ns-body">
                    <div>
                        <h3 class="ns-title"><a href="news_article.php?slug=<?php echo $slug; ?>"><?php echo sanitize($a['title']); ?></a></h3>
                        <?php if ($a['summary']): ?><p class="ns-summary"><?php echo sanitize($a['summary']); ?></p><?php endif; ?>
                    </div>
                    <div class="ns-meta">
                        <?php if ($pubDate): ?><span>📅 <?php echo $pubDate; ?></span><?php endif; ?>
                        <span>👁 <?php echo number_format((int)$a['view_count']); ?></span>
                        <span><?php echo $timeAgo; ?></span>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <?php $activeNav = 'news'; require __DIR__ . '/partials/bottom_nav.php'; ?>
</body>
</html>
