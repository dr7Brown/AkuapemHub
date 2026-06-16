<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

$user = current_user();
$slug = trim($_GET['slug'] ?? '');
if (!$slug) { header('Location: news.php'); exit; }

$stmt = $pdo->prepare("SELECT * FROM news WHERE slug=? AND status='published' LIMIT 1");
$stmt->execute([$slug]);
$article = $stmt->fetch();
if (!$article) {
    http_response_code(404);
    echo '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:40px;text-align:center;"><h1>Article not found</h1><p><a href="news.php">← Back to News</a></p></body></html>';
    exit;
}

// Increment view count once per session
if (!isset($_SESSION['viewed_news'])) $_SESSION['viewed_news'] = [];
if (!in_array($article['id'], $_SESSION['viewed_news'])) {
    $pdo->prepare("UPDATE news SET view_count=view_count+1 WHERE id=?")->execute([$article['id']]);
    $_SESSION['viewed_news'][] = $article['id'];
    $article['view_count']++;

    // Log view to per-user history table
    if ($user) {
        $pdo->prepare("INSERT INTO news_views (news_id, user_id) VALUES (?,?)")->execute([$article['id'], $user['id']]);
    }
}

// Logged-in interaction state
$userLiked = false;
$userSaved = false;
if ($user) {
    $lk = $pdo->prepare("SELECT 1 FROM news_likes WHERE news_id=? AND user_id=? LIMIT 1");
    $lk->execute([$article['id'], $user['id']]);
    $userLiked = (bool)$lk->fetch();

    $sv = $pdo->prepare("SELECT 1 FROM news_saves WHERE news_id=? AND user_id=? LIMIT 1");
    $sv->execute([$article['id'], $user['id']]);
    $userSaved = (bool)$sv->fetch();
}

$likeStmt = $pdo->prepare("SELECT COUNT(*) FROM news_likes WHERE news_id=?");
$likeStmt->execute([$article['id']]);
$likeCount = (int)$likeStmt->fetchColumn();

// Comments
$cStmt = $pdo->prepare("SELECT nc.*, u.name AS user_name, u.profile_photo FROM news_comments nc JOIN users u ON u.id=nc.user_id WHERE nc.news_id=? ORDER BY nc.created_at ASC");
$cStmt->execute([$article['id']]);
$comments = $cStmt->fetchAll();

// Related/sidebar banner ad
$bannerAd = $pdo->query("SELECT * FROM advertisements WHERE status='active' AND ad_type='banner' AND (start_date IS NULL OR start_date<=CURDATE()) AND (end_date IS NULL OR end_date>=CURDATE()) ORDER BY RAND() LIMIT 1")->fetch();

// Estimated read time
$wordCount = str_word_count(strip_tags($article['content'] ?? ''));
$readMins  = max(1, ceil($wordCount / 200));

$pageUrl  = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/news_article.php?slug=' . urlencode($slug);
$ogImage  = $article['featured_image'] ? (isset($_SERVER['HTTP_HOST']) ? 'https://' . $_SERVER['HTTP_HOST'] . '/' : '') . $article['featured_image'] : '';
$pubDate  = $article['published_at'] ?? $article['created_at'];
$metaDesc = mb_substr(strip_tags($article['summary'] ?: $article['content']), 0, 160);
$csrfTok  = $_SESSION['csrf_token'] ?? '';
$csrfField = csrf_field();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?php echo sanitize($article['title']); ?> — <?php echo sanitize(APP_NAME); ?></title>
    <meta name="description"        content="<?php echo sanitize($metaDesc); ?>">
    <meta property="og:title"       content="<?php echo sanitize($article['title']); ?>">
    <meta property="og:description" content="<?php echo sanitize($metaDesc); ?>">
    <meta property="og:type"        content="article">
    <meta property="og:url"         content="<?php echo sanitize($pageUrl); ?>">
    <?php if ($ogImage): ?><meta property="og:image" content="<?php echo sanitize($ogImage); ?>"><?php endif; ?>
    <?php if ($pubDate): ?><meta property="article:published_time" content="<?php echo date('c', strtotime($pubDate)); ?>"><?php endif; ?>
    <meta name="twitter:card"       content="summary_large_image">
    <meta name="twitter:title"      content="<?php echo sanitize($article['title']); ?>">
    <meta name="twitter:description" content="<?php echo sanitize($metaDesc); ?>">
    <?php if ($ogImage): ?><meta name="twitter:image" content="<?php echo sanitize($ogImage); ?>"><?php endif; ?>
    <link rel="canonical" href="<?php echo sanitize($pageUrl); ?>">
    <link rel="stylesheet" href="assets/css/style.css" />
    <style>
        /* ── Article layout ──────────────────────────────────── */
        .na-wrap      { max-width:760px; margin:0 auto; padding:0 16px 88px; }
        /* ── Hero image ──────────────────────────────────────── */
        .na-hero      { margin:0 -16px 32px; overflow:hidden; }
        .na-hero img  { width:100%; max-height:460px; object-fit:cover; display:block; }
        .na-hero-placeholder { height:200px; background:linear-gradient(135deg,var(--primary) 0%,#0d5e57 100%); }
        /* ── Breadcrumb ──────────────────────────────────────── */
        .na-breadcrumb { font-size:.8rem; color:var(--text-muted); margin-bottom:14px; }
        .na-breadcrumb a { color:var(--primary); text-decoration:none; }
        .na-breadcrumb a:hover { text-decoration:underline; }
        /* ── Title ───────────────────────────────────────────── */
        .na-title     { font-size:clamp(1.5rem,4vw,2.15rem); font-weight:900; line-height:1.2; letter-spacing:-.5px; margin:0 0 18px; }
        /* ── Byline ──────────────────────────────────────────── */
        .na-byline    { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; padding:14px 0; border-top:1px solid var(--border); border-bottom:1px solid var(--border); margin-bottom:24px; }
        .na-author    { display:flex; align-items:center; gap:10px; }
        .na-avatar    { width:36px; height:36px; border-radius:50%; background:var(--primary); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:800; font-size:.9rem; flex-shrink:0; }
        .na-author-name  { font-weight:700; font-size:.9rem; display:block; }
        .na-author-meta  { font-size:.78rem; color:var(--text-muted); }
        .na-byline-stats { font-size:.8rem; color:var(--text-muted); display:flex; gap:14px; }
        /* ── Lead / summary ──────────────────────────────────── */
        .na-lead      { font-size:1.1rem; line-height:1.7; color:var(--text-muted); border-left:4px solid var(--primary); padding:2px 0 2px 18px; margin:0 0 24px; font-style:italic; }
        /* ── Body typography ─────────────────────────────────── */
        .na-content   { font-size:1.05rem; line-height:1.85; color:var(--text); }
        .na-content p  { margin:0 0 22px; }
        .na-content h2 { font-size:1.35rem; font-weight:800; margin:32px 0 12px; }
        .na-content h3 { font-size:1.1rem; font-weight:700; margin:24px 0 8px; }
        .na-content ul, .na-content ol { margin:0 0 22px; padding-left:22px; }
        .na-content li { margin-bottom:6px; }
        .na-content a  { color:var(--primary); }
        .na-content img { max-width:100%; border-radius:12px; margin:12px 0; display:block; }
        .na-content blockquote { border-left:4px solid var(--primary); padding:14px 20px; margin:24px 0; background:var(--surface-muted,#f8fafc); border-radius:0 10px 10px 0; font-style:italic; }
        .na-content blockquote p { margin:0; }
        /* ── Share row ───────────────────────────────────────── */
        .na-share-section { margin:32px 0; padding:22px 0; border-top:1px solid var(--border); }
        .na-share-title   { font-size:.82rem; font-weight:700; letter-spacing:.05em; text-transform:uppercase; color:var(--text-muted); margin-bottom:12px; }
        .na-share-row     { display:flex; gap:8px; flex-wrap:wrap; }
        .na-share-btn     { display:inline-flex; align-items:center; gap:6px; padding:8px 16px; border-radius:8px; font-size:.84rem; font-weight:700; text-decoration:none; color:#fff; transition:opacity .15s; }
        .na-share-btn:hover { opacity:.88; }
        .sh-wa  { background:#25d366; }
        .sh-fb  { background:#1877f2; }
        .sh-tw  { background:#000; }
        .sh-cp  { background:var(--surface); color:var(--text); border:1px solid var(--border); }
        /* ── Actions (like/save) ─────────────────────────────── */
        .na-actions   { display:flex; gap:10px; flex-wrap:wrap; margin:20px 0 28px; }
        .na-act-btn   { background:var(--surface); border:1.5px solid var(--border); border-radius:25px; padding:8px 20px; font-size:.88rem; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:7px; transition:all .15s; color:var(--text); }
        .na-act-btn:hover  { border-color:var(--primary); background:var(--primary-soft,#e6f4f1); color:var(--primary); }
        .na-act-btn.active { border-color:var(--primary); color:var(--primary); background:var(--primary-soft,#e6f4f1); }
        .na-act-link  { background:var(--surface); border:1.5px solid var(--border); border-radius:25px; padding:8px 20px; font-size:.88rem; font-weight:600; text-decoration:none; display:inline-flex; align-items:center; gap:7px; color:var(--text-muted); }
        /* ── Banner ad ───────────────────────────────────────── */
        .na-banner-ad { text-align:center; margin:28px 0; }
        .na-banner-ad img { max-width:728px; width:100%; border-radius:10px; }
        .na-ad-label  { font-size:.68rem; letter-spacing:.07em; text-transform:uppercase; color:var(--text-muted); margin-bottom:5px; }
        /* ── Comments ────────────────────────────────────────── */
        .na-comments  { margin-top:36px; padding-top:28px; border-top:1px solid var(--border); }
        .na-com-hd    { font-size:1.05rem; font-weight:800; margin:0 0 20px; }
        .nc-comment   { display:flex; gap:12px; margin-bottom:18px; }
        .nc-com-av    { width:38px; height:38px; border-radius:50%; background:var(--primary); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:800; font-size:.9rem; flex-shrink:0; overflow:hidden; }
        .nc-com-av img { width:100%; height:100%; object-fit:cover; }
        .nc-com-body  { flex:1; background:var(--surface); border:1px solid var(--border); border-radius:12px; padding:12px 14px; }
        .nc-com-name  { font-weight:700; font-size:.88rem; }
        .nc-com-date  { font-size:.75rem; color:var(--text-muted); margin-left:8px; }
        .nc-com-text  { font-size:.9rem; margin-top:6px; line-height:1.55; }
        .na-com-form  { margin-top:20px; }
        .na-com-form textarea { width:100%; box-sizing:border-box; border-radius:12px; }
        .na-login-prompt { background:var(--surface); border:1px solid var(--border); border-radius:12px; padding:20px; text-align:center; color:var(--text-muted); margin-top:16px; }
        /* ── Responsive ──────────────────────────────────────── */
        @media (max-width:520px) { .na-hero img { max-height:240px; } }
    </style>
</head>
<body <?php echo $user ? 'class="has-bottom-nav"' : ''; ?>>

    <header class="app-topbar">
        <a href="news.php" class="button button-secondary button-small">← News</a>
        <span class="brand"><?php echo sanitize(APP_NAME); ?></span>
        <?php if (!$user): ?>
            <a href="login.php"    class="button button-secondary button-small">Sign in</a>
            <a href="register.php" class="button button-primary button-small">Register</a>
        <?php endif; ?>
    </header>

    <div class="na-wrap">

        <!-- Hero image -->
        <div class="na-hero">
            <?php if ($article['featured_image']): ?>
                <img src="<?php echo sanitize($article['featured_image']); ?>" alt="<?php echo sanitize($article['title']); ?>">
            <?php else: ?>
                <div class="na-hero-placeholder"></div>
            <?php endif; ?>
        </div>

        <!-- Breadcrumb -->
        <nav class="na-breadcrumb" aria-label="Breadcrumb">
            <a href="news.php">News</a> &rsaquo; <?php echo sanitize(mb_substr($article['title'], 0, 45)) . (mb_strlen($article['title']) > 45 ? '…' : ''); ?>
        </nav>

        <!-- Title -->
        <h1 class="na-title"><?php echo sanitize($article['title']); ?></h1>

        <!-- Byline -->
        <div class="na-byline">
            <div class="na-author">
                <div class="na-avatar">A</div>
                <div>
                    <span class="na-author-name"><?php echo sanitize(APP_NAME); ?> Team</span>
                    <span class="na-author-meta">
                        <?php echo $pubDate ? date('F j, Y', strtotime($pubDate)) : 'Published'; ?>
                        &nbsp;·&nbsp; <?php echo $readMins; ?> min read
                    </span>
                </div>
            </div>
            <div class="na-byline-stats">
                <span>👁 <?php echo number_format((int)$article['view_count']); ?></span>
                <span>❤️ <span id="like-count"><?php echo number_format($likeCount); ?></span></span>
                <span>💬 <?php echo count($comments); ?></span>
            </div>
        </div>

        <!-- Summary / lead -->
        <?php if ($article['summary']): ?>
        <p class="na-lead"><?php echo sanitize($article['summary']); ?></p>
        <?php endif; ?>

        <!-- Body -->
        <div class="na-content rich-content">
            <?php echo render_rich($article['content']); ?>
        </div>

        <!-- Share -->
        <div class="na-share-section">
            <div class="na-share-title">Share this article</div>
            <div class="na-share-row">
                <a class="na-share-btn sh-wa" href="https://wa.me/?text=<?php echo rawurlencode($article['title'] . ' — ' . $pageUrl); ?>" target="_blank" rel="noopener">📲 WhatsApp</a>
                <a class="na-share-btn sh-fb" href="https://www.facebook.com/sharer/sharer.php?u=<?php echo rawurlencode($pageUrl); ?>" target="_blank" rel="noopener">📘 Facebook</a>
                <a class="na-share-btn sh-tw" href="https://twitter.com/intent/tweet?text=<?php echo rawurlencode($article['title']); ?>&url=<?php echo rawurlencode($pageUrl); ?>" target="_blank" rel="noopener">𝕏 Twitter</a>
                <a class="na-share-btn sh-cp" href="#" onclick="copyArticleLink(event)">🔗 Copy link</a>
            </div>
        </div>

        <!-- Like / Save actions -->
        <div class="na-actions">
            <?php if ($user): ?>
                <button class="na-act-btn <?php echo $userLiked ? 'active' : ''; ?>" id="like-btn" onclick="toggleLike()">
                    ❤️ <span id="like-label"><?php echo $userLiked ? 'Liked' : 'Like'; ?></span>
                </button>
                <button class="na-act-btn <?php echo $userSaved ? 'active' : ''; ?>" id="save-btn" onclick="toggleSave()">
                    🔖 <span id="save-label"><?php echo $userSaved ? 'Saved' : 'Save for later'; ?></span>
                </button>
                <a href="news_saved.php" class="na-act-link">📚 Reading list</a>
            <?php else: ?>
                <a href="login.php?redirect=<?php echo urlencode($_SERVER['REQUEST_URI']); ?>" class="na-act-link">❤️ Like</a>
                <a href="login.php?redirect=<?php echo urlencode($_SERVER['REQUEST_URI']); ?>" class="na-act-link">🔖 Save</a>
            <?php endif; ?>
        </div>

        <!-- Banner ad mid-article -->
        <?php if ($bannerAd): ?>
        <div class="na-banner-ad">
            <div class="na-ad-label">Advertisement</div>
            <a href="ad_click.php?id=<?php echo (int)$bannerAd['id']; ?>" target="_blank" rel="noopener sponsored">
                <?php if ($bannerAd['image']): ?>
                    <img src="<?php echo sanitize($bannerAd['image']); ?>" alt="<?php echo sanitize($bannerAd['title']); ?>">
                <?php else: ?>
                    <div style="background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:20px;text-align:center;"><?php echo sanitize($bannerAd['title']); ?></div>
                <?php endif; ?>
            </a>
        </div>
        <?php endif; ?>

        <!-- Comments -->
        <div class="na-comments">
            <h2 class="na-com-hd">Comments <span style="font-weight:400;font-size:.85rem;color:var(--text-muted);">(<?php echo count($comments); ?>)</span></h2>

            <div id="comments-list">
            <?php foreach ($comments as $c):
                $initial = mb_strtoupper(mb_substr($c['user_name'], 0, 1));
            ?>
            <div class="nc-comment">
                <div class="nc-com-av">
                    <?php if ($c['profile_photo']): ?><img src="<?php echo sanitize($c['profile_photo']); ?>" alt=""><?php else: ?><?php echo $initial; ?><?php endif; ?>
                </div>
                <div class="nc-com-body">
                    <span class="nc-com-name"><?php echo sanitize($c['user_name']); ?></span>
                    <span class="nc-com-date"><?php echo date('M j, Y', strtotime($c['created_at'])); ?></span>
                    <p class="nc-com-text"><?php echo nl2br(sanitize($c['comment'])); ?></p>
                </div>
            </div>
            <?php endforeach; ?>
            </div>

            <?php if ($user): ?>
            <div class="na-com-form">
                <form id="comment-form" onsubmit="postComment(event)">
                    <?php echo $csrfField; ?>
                    <textarea id="comment-input" name="comment" class="form-control" rows="3"
                              placeholder="Share your thoughts…" style="margin-bottom:10px;" required></textarea>
                    <button type="submit" class="button button-primary" id="comment-btn" style="min-width:140px;">Post comment</button>
                </form>
            </div>
            <?php else: ?>
            <div class="na-login-prompt">
                <a href="login.php?redirect=<?php echo urlencode($_SERVER['REQUEST_URI']); ?>" style="color:var(--primary);font-weight:600;">Sign in</a> to join the conversation.
            </div>
            <?php endif; ?>
        </div>

    </div><!-- .na-wrap -->

    <?php if ($user): ?>
        <?php $activeNav = 'news'; require __DIR__ . '/partials/bottom_nav.php'; ?>
    <?php else: ?>
        <footer style="text-align:center;padding:20px 16px 32px;font-size:.8rem;color:#6b7280;border-top:1px solid #e5e7eb;margin-top:40px;">
            &copy; <?php echo date('Y'); ?> <?php echo sanitize(APP_NAME); ?>
        </footer>
    <?php endif; ?>

    <script>
    var newsId  = <?php echo (int)$article['id']; ?>;
    var csrfTok = <?php echo json_encode($csrfTok); ?>;

    function toggleLike() {
        fetch('news_ajax.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=like&news_id=' + newsId + '&csrf_token=' + encodeURIComponent(csrfTok)
        }).then(function(r){ return r.json(); }).then(function(d) {
            if (d.error) return;
            document.getElementById('like-count').textContent = d.count.toLocaleString();
            document.getElementById('like-label').textContent = d.liked ? 'Liked' : 'Like';
            document.getElementById('like-btn').classList.toggle('active', d.liked);
        });
    }

    function toggleSave() {
        fetch('news_ajax.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=save&news_id=' + newsId + '&csrf_token=' + encodeURIComponent(csrfTok)
        }).then(function(r){ return r.json(); }).then(function(d) {
            if (d.error) return;
            document.getElementById('save-label').textContent = d.saved ? 'Saved' : 'Save for later';
            document.getElementById('save-btn').classList.toggle('active', d.saved);
        });
    }

    function postComment(e) {
        e.preventDefault();
        var inp = document.getElementById('comment-input');
        var btn = document.getElementById('comment-btn');
        var txt = inp.value.trim();
        if (!txt) return;
        btn.disabled = true; btn.textContent = 'Posting…';
        fetch('news_ajax.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=comment&news_id=' + newsId + '&comment=' + encodeURIComponent(txt) + '&csrf_token=' + encodeURIComponent(csrfTok)
        }).then(function(r){ return r.json(); }).then(function(d) {
            if (d.error) { alert(d.error); }
            else {
                document.getElementById('comments-list').insertAdjacentHTML('beforeend', d.html);
                inp.value = '';
                var hd = document.querySelector('.na-com-hd');
                if (hd) {
                    var cur = parseInt(hd.querySelector('span').textContent) || 0;
                    hd.querySelector('span').textContent = '(' + (cur + 1) + ')';
                }
            }
        }).finally(function(){ btn.disabled = false; btn.textContent = 'Post comment'; });
    }

    function copyArticleLink(e) {
        e.preventDefault();
        navigator.clipboard.writeText(window.location.href).then(function(){
            var btn = e.currentTarget;
            var orig = btn.textContent;
            btn.textContent = '✓ Copied!';
            setTimeout(function(){ btn.textContent = orig; }, 2000);
        });
    }
    </script>
</body>
</html>
