<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

$user = current_user();
$slug = trim($_GET['slug'] ?? '');
if (!$slug) { header('Location: news.php'); exit; }

$stmt = $pdo->prepare("SELECT * FROM news WHERE slug=? AND status='published' AND (published_at IS NULL OR published_at<=NOW()) LIMIT 1");
$stmt->execute([$slug]);
$article = $stmt->fetch();
if (!$article) { header('HTTP/1.0 404 Not Found'); echo '<h1>Article not found.</h1><a href="news.php">← Back to News</a>'; exit; }

// Increment view count once per session
if (!isset($_SESSION['viewed_news'])) $_SESSION['viewed_news'] = [];
if (!in_array($article['id'], $_SESSION['viewed_news'])) {
    $pdo->prepare("UPDATE news SET view_count=view_count+1 WHERE id=?")->execute([$article['id']]);
    $_SESSION['viewed_news'][] = $article['id'];
    $article['view_count']++;
}

// User interaction state
$userLiked = false;
$userSaved = false;
if ($user) {
    $likeCheck = $pdo->prepare("SELECT 1 FROM news_likes WHERE news_id=? AND user_id=? LIMIT 1");
    $likeCheck->execute([$article['id'], $user['id']]);
    $userLiked = (bool)$likeCheck->fetch();

    $saveCheck = $pdo->prepare("SELECT 1 FROM news_saves WHERE news_id=? AND user_id=? LIMIT 1");
    $saveCheck->execute([$article['id'], $user['id']]);
    $userSaved = (bool)$saveCheck->fetch();
}
$stmt2 = $pdo->prepare("SELECT COUNT(*) FROM news_likes WHERE news_id=?");
$stmt2->execute([$article['id']]);
$likeCount = (int)$stmt2->fetchColumn();

// Comments
$cStmt = $pdo->prepare("SELECT nc.*, u.name AS user_name, u.profile_image FROM news_comments nc JOIN users u ON u.id=nc.user_id WHERE nc.news_id=? ORDER BY nc.created_at ASC");
$cStmt->execute([$article['id']]);
$comments = $cStmt->fetchAll();

// Related banner ad
$bannerAd = $pdo->query("SELECT * FROM advertisements WHERE status='active' AND ad_type='banner' AND (start_date IS NULL OR start_date<=CURDATE()) AND (end_date IS NULL OR end_date>=CURDATE()) ORDER BY RAND() LIMIT 1")->fetch();

$pageUrl  = 'https://' . ($_SERVER['HTTP_HOST'] ?? APP_NAME) . '/news_article.php?slug=' . urlencode($slug);
$ogImage  = $article['featured_image'] ? 'https://' . ($_SERVER['HTTP_HOST'] ?? '') . '/' . $article['featured_image'] : '';
$pubDate  = $article['published_at'] ?? $article['created_at'];
$csrfField = csrf_field();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?php echo sanitize($article['title']); ?> — <?php echo sanitize(APP_NAME); ?></title>
    <meta name="description" content="<?php echo sanitize(mb_substr($article['summary'] ?: strip_tags($article['content']), 0, 160)); ?>">
    <meta property="og:title"       content="<?php echo sanitize($article['title']); ?>">
    <meta property="og:description" content="<?php echo sanitize(mb_substr($article['summary'] ?: strip_tags($article['content']), 0, 160)); ?>">
    <meta property="og:type"        content="article">
    <meta property="og:url"         content="<?php echo sanitize($pageUrl); ?>">
    <?php if ($ogImage): ?><meta property="og:image" content="<?php echo sanitize($ogImage); ?>"><?php endif; ?>
    <meta name="twitter:card"  content="summary_large_image">
    <meta name="twitter:title" content="<?php echo sanitize($article['title']); ?>">
    <link rel="stylesheet" href="assets/css/style.css" />
    <style>
        .na-shell   { max-width: 760px; margin: 0 auto; padding: 20px 16px 80px; }
        .na-featured { width:100%; aspect-ratio:16/9; object-fit:cover; border-radius:12px; margin-bottom:24px; }
        .na-meta    { display:flex; gap:16px; flex-wrap:wrap; font-size:.82rem; color:var(--text-muted); margin-bottom:20px; }
        .na-content { line-height:1.75; font-size:1rem; }
        .na-content p  { margin:0 0 16px; }
        .na-content h2, .na-content h3 { margin:24px 0 10px; }
        .na-content img { max-width:100%; border-radius:8px; }
        .na-actions { display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin:24px 0; padding:16px 0; border-top:1px solid var(--border); border-bottom:1px solid var(--border); }
        .na-action-btn { background:none; border:1px solid var(--border); border-radius:20px; padding:6px 16px; font-size:.88rem; cursor:pointer; display:flex; align-items:center; gap:6px; transition:background .15s,border-color .15s; }
        .na-action-btn:hover  { background:var(--surface-muted); }
        .na-action-btn.active { border-color:var(--primary); color:var(--primary); background:var(--primary-soft,#e6f4f1); }
        .na-share   { display:flex; gap:8px; flex-wrap:wrap; margin:20px 0; }
        .na-share a { display:inline-flex; align-items:center; gap:6px; padding:7px 14px; border-radius:8px; font-size:.84rem; font-weight:600; text-decoration:none; color:#fff; }
        .na-share .sh-wa  { background:#25d366; }
        .na-share .sh-fb  { background:#1877f2; }
        .na-share .sh-tw  { background:#1da1f2; }
        .na-share .sh-cp  { background:var(--surface); color:var(--text); border:1px solid var(--border); }
        .na-banner-ad { text-align:center; margin:28px 0; }
        .na-banner-ad img { max-width:728px; width:100%; border-radius:8px; }
        .na-banner-ad .ad-label { font-size:.7rem; color:var(--text-muted); margin-bottom:4px; }
        .nc-comment { display:flex; gap:12px; margin-bottom:16px; }
        .nc-comment-avatar { width:36px; height:36px; border-radius:50%; background:var(--primary); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:.9rem; flex-shrink:0; overflow:hidden; }
        .nc-comment-avatar img { width:100%; height:100%; object-fit:cover; }
        .nc-comment-body { flex:1; }
        .nc-comment-name { font-weight:600; font-size:.88rem; }
        .nc-comment-date { font-size:.76rem; color:var(--text-muted); }
        .nc-comment-text { font-size:.9rem; margin-top:4px; line-height:1.5; }
        .na-login-prompt { background:var(--surface); border:1px solid var(--border); border-radius:10px; padding:18px; text-align:center; font-size:.9rem; margin-top:20px; }
    </style>
</head>
<body <?php echo $user ? 'class="has-bottom-nav"' : ''; ?>>
    <header class="app-topbar">
        <a href="news.php" class="button button-secondary button-small">← News</a>
        <span class="brand"><?php echo sanitize(APP_NAME); ?></span>
        <?php if (!$user): ?><a href="login.php" class="button button-primary button-small">Sign in</a><?php endif; ?>
    </header>

    <div class="na-shell">
        <?php if ($article['featured_image']): ?>
            <img src="<?php echo sanitize($article['featured_image']); ?>" alt="<?php echo sanitize($article['title']); ?>" class="na-featured">
        <?php endif; ?>

        <h1 style="margin:0 0 12px;font-size:1.6rem;line-height:1.3;"><?php echo sanitize($article['title']); ?></h1>

        <div class="na-meta">
            <?php if ($pubDate): ?><span>📅 <?php echo date('F j, Y', strtotime($pubDate)); ?></span><?php endif; ?>
            <span>👁 <?php echo (int)$article['view_count']; ?> views</span>
            <span>❤️ <span id="like-count"><?php echo $likeCount; ?></span> likes</span>
        </div>

        <?php if ($article['summary']): ?>
            <p style="font-size:1.05rem;font-weight:500;color:var(--text-muted);border-left:3px solid var(--primary);padding-left:14px;margin:0 0 20px;">
                <?php echo sanitize($article['summary']); ?>
            </p>
        <?php endif; ?>

        <div class="na-content">
            <?php echo $article['content']; // Admin-controlled HTML, trusted ?>
        </div>

        <!-- Share buttons -->
        <div style="margin:24px 0 8px;font-weight:600;font-size:.9rem;">Share this article</div>
        <div class="na-share">
            <a href="https://wa.me/?text=<?php echo rawurlencode($article['title'] . ' - ' . $pageUrl); ?>" class="sh-wa" target="_blank" rel="noopener">📲 WhatsApp</a>
            <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo rawurlencode($pageUrl); ?>" class="sh-fb" target="_blank" rel="noopener">📘 Facebook</a>
            <a href="https://twitter.com/intent/tweet?text=<?php echo rawurlencode($article['title']); ?>&url=<?php echo rawurlencode($pageUrl); ?>" class="sh-tw" target="_blank" rel="noopener">🐦 X / Twitter</a>
            <a href="#" class="sh-cp" onclick="copyLink(event)">🔗 Copy Link</a>
        </div>

        <!-- Like / Save actions -->
        <div class="na-actions">
            <?php if ($user): ?>
                <button class="na-action-btn <?php echo $userLiked ? 'active' : ''; ?>" id="like-btn" onclick="toggleLike()">
                    ❤️ <span id="like-label"><?php echo $userLiked ? 'Liked' : 'Like'; ?></span>
                </button>
                <button class="na-action-btn <?php echo $userSaved ? 'active' : ''; ?>" id="save-btn" onclick="toggleSave()">
                    🔖 <span id="save-label"><?php echo $userSaved ? 'Saved' : 'Save'; ?></span>
                </button>
            <?php else: ?>
                <a href="login.php?redirect=<?php echo urlencode($_SERVER['REQUEST_URI']); ?>" class="na-action-btn">❤️ Like</a>
                <a href="login.php?redirect=<?php echo urlencode($_SERVER['REQUEST_URI']); ?>" class="na-action-btn">🔖 Save</a>
            <?php endif; ?>
        </div>

        <!-- Banner ad mid-article -->
        <?php if ($bannerAd): ?>
        <div class="na-banner-ad">
            <div class="ad-label">Advertisement</div>
            <a href="ad_click.php?id=<?php echo (int)$bannerAd['id']; ?>" target="_blank" rel="noopener sponsored">
                <?php if ($bannerAd['image']): ?>
                    <img src="<?php echo sanitize($bannerAd['image']); ?>" alt="<?php echo sanitize($bannerAd['title']); ?>">
                <?php else: ?>
                    <div style="background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:20px;"><?php echo sanitize($bannerAd['title']); ?></div>
                <?php endif; ?>
            </a>
        </div>
        <?php endif; ?>

        <!-- Comments -->
        <div style="margin-top:32px;">
            <h2 style="font-size:1.1rem;margin-bottom:16px;">💬 Comments (<?php echo count($comments); ?>)</h2>

            <div id="comments-list">
            <?php foreach ($comments as $c):
                $initial = mb_strtoupper(mb_substr($c['user_name'], 0, 1));
            ?>
            <div class="nc-comment">
                <div class="nc-comment-avatar">
                    <?php if ($c['profile_image']): ?>
                        <img src="<?php echo sanitize($c['profile_image']); ?>" alt="">
                    <?php else: ?><?php echo $initial; ?><?php endif; ?>
                </div>
                <div class="nc-comment-body">
                    <span class="nc-comment-name"><?php echo sanitize($c['user_name']); ?></span>
                    <span class="nc-comment-date">&nbsp;·&nbsp;<?php echo date('M j, Y', strtotime($c['created_at'])); ?></span>
                    <p class="nc-comment-text"><?php echo nl2br(sanitize($c['comment'])); ?></p>
                </div>
            </div>
            <?php endforeach; ?>
            </div>

            <?php if ($user): ?>
            <form id="comment-form" style="margin-top:16px;" onsubmit="postComment(event)">
                <?php echo $csrfField; ?>
                <textarea name="comment" id="comment-input" class="form-control" rows="3" placeholder="Write a comment…" style="margin-bottom:8px;" required></textarea>
                <button type="submit" class="button button-primary button-small" id="comment-btn">Post comment</button>
            </form>
            <?php else: ?>
            <div class="na-login-prompt">
                <a href="login.php?redirect=<?php echo urlencode($_SERVER['REQUEST_URI']); ?>">Sign in</a> to leave a comment.
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($user): ?>
        <?php $activeNav = 'news'; require __DIR__ . '/partials/bottom_nav.php'; ?>
    <?php else: ?>
        <footer style="text-align:center;padding:20px 16px 32px;font-size:.8rem;color:#6b7280;border-top:1px solid #e5e7eb;margin-top:40px;">
            &copy; <?php echo date('Y'); ?> <?php echo sanitize(APP_NAME); ?>
        </footer>
    <?php endif; ?>

    <script>
    var newsId  = <?php echo (int)$article['id']; ?>;
    var csrfTok = <?php echo json_encode($_SESSION['csrf_token'] ?? ''); ?>;

    function toggleLike() {
        fetch('news_ajax.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'action=like&news_id='+newsId+'&csrf_token='+encodeURIComponent(csrfTok) })
            .then(function(r){ return r.json(); })
            .then(function(d) {
                if (d.error) return;
                document.getElementById('like-count').textContent = d.count;
                document.getElementById('like-label').textContent = d.liked ? 'Liked' : 'Like';
                document.getElementById('like-btn').classList.toggle('active', d.liked);
            });
    }

    function toggleSave() {
        fetch('news_ajax.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'action=save&news_id='+newsId+'&csrf_token='+encodeURIComponent(csrfTok) })
            .then(function(r){ return r.json(); })
            .then(function(d) {
                if (d.error) return;
                document.getElementById('save-label').textContent = d.saved ? 'Saved' : 'Save';
                document.getElementById('save-btn').classList.toggle('active', d.saved);
            });
    }

    function postComment(e) {
        e.preventDefault();
        var input = document.getElementById('comment-input');
        var btn   = document.getElementById('comment-btn');
        var txt   = input.value.trim();
        if (!txt) return;
        btn.disabled = true; btn.textContent = 'Posting…';
        fetch('news_ajax.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'action=comment&news_id='+newsId+'&comment='+encodeURIComponent(txt)+'&csrf_token='+encodeURIComponent(csrfTok) })
            .then(function(r){ return r.json(); })
            .then(function(d) {
                if (d.error) { alert(d.error); } else {
                    var list = document.getElementById('comments-list');
                    list.insertAdjacentHTML('beforeend', d.html);
                    input.value = '';
                }
            })
            .finally(function(){ btn.disabled=false; btn.textContent='Post comment'; });
    }

    function copyLink(e) {
        e.preventDefault();
        navigator.clipboard.writeText(window.location.href).then(function(){ alert('Link copied!'); });
    }
    </script>
</body>
</html>
