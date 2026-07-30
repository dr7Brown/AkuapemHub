<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/paystack.php';

require_module_enabled('news', 'News');
require_login();
$user      = current_user();
$articleId = (int)($_GET['id'] ?? $_POST['article_id'] ?? 0);

if (!$articleId) { header('Location: my_news.php'); exit; }

$stmt = $pdo->prepare("SELECT * FROM news WHERE id=? AND user_id=? LIMIT 1");
$stmt->execute([$articleId, $user['id']]);
$article = $stmt->fetch();

if (!$article) { flash('Article not found or not yours.', 'error'); header('Location: my_news.php'); exit; }
if ($article['status'] !== 'published') { flash('Only published articles can be featured.', 'info'); header('Location: my_news.php'); exit; }

$isPaid     = is_feature_paid('enable_paid_featured_news');
$featEnd    = $article['featured_end_date'] ?? null;
$featActive = !empty($article['featured']) && ($featEnd === null || $featEnd >= date('Y-m-d'));
$renewSoon  = !empty($article['featured']) && $featEnd !== null && $featEnd < date('Y-m-d', strtotime('+7 days'));

$existing = $pdo->prepare("SELECT id, reference_code FROM platform_payments WHERE user_id=? AND payment_type='featured_news' AND reference_id=? AND status='pending' LIMIT 1");
$existing->execute([$user['id'], $articleId]);
$existingPay = $existing->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if ($existingPay) { flash('A payment is already in progress. Complete it first.', 'info'); header('Location: my_payments.php'); exit; }

    if (!$isPaid) {
        $pdo->prepare("UPDATE news SET featured=1, featured_end_date=DATE_ADD(CURDATE(),INTERVAL 30 DAY) WHERE id=?")->execute([$articleId]);
        log_audit_action($user['id'], 'feature_news', "Featured article #{$articleId} '{$article['title']}' — free mode");
        flash('Article is now featured for 30 days!', 'success');
        header('Location: my_news.php'); exit;
    }

    $packageId = (int)($_POST['package_id'] ?? 0);
    $pkg = $pdo->prepare("SELECT * FROM featured_news_packages WHERE id=? AND status='active'");
    $pkg->execute([$packageId]); $package = $pkg->fetch();
    if (!$package) { flash('Select a valid package.', 'error'); header('Location: feature_news.php?id=' . $articleId); exit; }

    $result = initializePayment($user['id'], $user['email'], 'featured_news', $articleId, $packageId, (float)$package['price'], ['article_title' => $article['title']]);
    if (isset($result['error'])) { flash($result['error'], 'error'); header('Location: feature_news.php?id=' . $articleId); exit; }

    log_audit_action($user['id'], 'feature_news_checkout', "Checkout for featuring article #{$articleId} — {$package['name']} GH₵{$package['price']}");
    header('Location: ' . $result['checkout_url']); exit;
}

$packages = get_active_packages('featured_news_packages');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Feature Article — <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .pkg-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px;margin:16px 0;}
        .pkg-card{border:2px solid var(--border);border-radius:12px;padding:16px;text-align:center;cursor:pointer;transition:border-color .15s;}
        .pkg-card:has(input:checked){border-color:var(--primary,#0f766e);background:var(--primary-soft,#d1fae5);}
        .pkg-card input{display:none;}.pkg-card strong{display:block;font-size:1rem;font-weight:900;}
        .pkg-card .price{font-size:1.3rem;font-weight:900;color:var(--primary,#0f766e);margin:6px 0;}
        .pkg-card .days{font-size:.76rem;color:var(--muted,#6b7280);}
    </style>
</head>
<body class="has-bottom-nav">
<header class="app-topbar">
    <a href="my_news.php" class="button button-secondary button-small">← My Articles</a>
    <span class="brand">⭐ Feature Article</span>
</header>
<main class="page-shell small-shell">
    <?php foreach (get_flashes() as $f): ?>
    <div class="alert alert-<?php echo sanitize($f['type']); ?>"><?php echo sanitize($f['message']); ?></div>
    <?php endforeach; ?>
    <div class="card">
        <div style="background:var(--surface-muted,#f8fafc);border-radius:10px;padding:12px 14px;margin-bottom:18px;border:1px solid var(--border);">
            <div style="font-weight:800;font-size:.92rem;">📰 <?php echo sanitize(mb_substr($article['title'],0,80)); ?></div>
            <div style="font-size:.78rem;color:var(--muted,#6b7280);margin-top:3px;">Published <?php echo date('d M Y', strtotime($article['published_at'] ?: $article['created_at'])); ?></div>
            <?php if ($featActive): ?>
            <div style="margin-top:8px;font-size:.78rem;background:#fef3c7;border-radius:6px;padding:4px 8px;display:inline-block;">
                ⭐ Featured until <strong><?php echo date('d M Y', strtotime($featEnd)); ?></strong>
            </div>
            <?php endif; ?>
        </div>
        <h3 style="font-size:.9rem;font-weight:800;margin:0 0 6px;">Why feature your article?</h3>
        <ul style="font-size:.84rem;color:var(--muted,#6b7280);padding-left:18px;line-height:1.9;margin:0 0 16px;">
            <li>Pinned to the top of the news feed with a ⭐ badge</li>
            <li>Highlighted on the community homepage</li>
            <li>Gets significantly more views and engagement</li>
        </ul>
        <?php if ($existingPay): ?>
        <div class="alert alert-info">A payment is already in progress (ref: <strong><?php echo sanitize(strtoupper($existingPay['reference_code'])); ?></strong>).
            <br><a href="resume_payment.php?id=<?php echo (int)$existingPay['id']; ?>" style="color:var(--primary);">Complete payment →</a></div>
        <?php elseif (!$isPaid): ?>
        <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:10px;padding:14px;text-align:center;margin-bottom:16px;">
            <p style="margin:0;font-size:.86rem;color:#166534;font-weight:700;">✨ Featuring is currently free!</p>
        </div>
        <form method="post">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="article_id" value="<?php echo $articleId; ?>">
            <button type="submit" class="button button-primary" style="width:100%;padding:13px;">⭐ Feature This Article (Free)</button>
        </form>
        <?php elseif ($packages): ?>
        <form method="post">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="article_id" value="<?php echo $articleId; ?>">
            <p style="font-size:.82rem;color:var(--muted,#6b7280);margin:0 0 4px;">Choose a featuring package:</p>
            <div class="pkg-grid">
            <?php foreach ($packages as $pkg): ?>
            <label class="pkg-card">
                <input type="radio" name="package_id" value="<?php echo $pkg['id']; ?>" required>
                <strong><?php echo sanitize($pkg['name']); ?></strong>
                <div class="price">GH₵ <?php echo number_format((float)$pkg['price'],2); ?></div>
                <div class="days"><?php echo (int)$pkg['duration_days']; ?> days</div>
            </label>
            <?php endforeach; ?>
            </div>
            <button type="submit" class="button button-primary" style="width:100%;padding:13px;">🔒 Pay &amp; Feature This Article</button>
            <p style="font-size:.74rem;color:var(--muted,#6b7280);text-align:center;margin-top:8px;">Secure checkout via Paystack · Card &amp; Mobile Money</p>
        </form>
        <?php else: ?>
        <div class="empty-state">No featuring packages available. Contact admin.</div>
        <?php endif; ?>
    </div>
</main>
<?php $activeNav = 'community'; require __DIR__ . '/partials/bottom_nav.php'; ?>
</body>
</html>
