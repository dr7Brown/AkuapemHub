<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/paystack.php';

require_module_enabled('news', 'News');
require_login();
$user = current_user();

$articleId = (int)($_GET['id'] ?? 0);
if (!$articleId) { header('Location: my_news.php'); exit; }

$stmt = $pdo->prepare("SELECT * FROM news WHERE id = ? AND user_id = ? LIMIT 1");
$stmt->execute([$articleId, $user['id']]);
$article = $stmt->fetch();

if (!$article) {
    flash('Article not found.', 'error');
    header('Location: my_news.php'); exit;
}

if ($article['status'] !== 'pending_payment') {
    flash('This article is not awaiting payment.', 'info');
    header('Location: my_news.php'); exit;
}

$feeEnabled = (bool)(int)get_platform_setting('news_fee_enabled', '0') && !user_has_complimentary_access();
$feeAmount  = (float)get_platform_setting('news_fee_amount', '10');

if (!$feeEnabled) {
    $pdo->prepare("UPDATE news SET status = 'draft', updated_at = NOW() WHERE id = ?")->execute([$articleId]);
    flash('The publishing fee has been waived. Your article is now pending admin review.', 'success');
    header('Location: my_news.php'); exit;
}

$existing = $pdo->prepare("SELECT id, reference_code FROM platform_payments WHERE user_id = ? AND payment_type = 'news_post' AND reference_id = ? AND status = 'pending' LIMIT 1");
$existing->execute([$user['id'], $articleId]);
$existingPay = $existing->fetch();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if ($existingPay) {
        flash('A payment is already in progress for this article. Complete it or wait for it to expire.', 'info');
        header('Location: my_payments.php'); exit;
    }
    $result = initializePayment($user['id'], $user['email'], 'news_post', $articleId, 0, $feeAmount, ['article_title' => $article['title']]);
    if (isset($result['error'])) {
        $error = $result['error'];
    } else {
        header('Location: ' . $result['checkout_url']); exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pay Publishing Fee — <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-bottom-nav">
    <header class="app-topbar">
        <a href="my_news.php" class="button button-secondary button-small">← My Articles</a>
        <span class="brand">Publishing Fee</span>
    </header>
    <main class="page-shell small-shell">
        <?php foreach (get_flashes() as $f): ?>
        <div class="alert alert-<?php echo sanitize($f['type']); ?>"><?php echo sanitize($f['message']); ?></div>
        <?php endforeach; ?>
        <?php if ($error): ?>
        <div class="alert alert-error"><?php echo sanitize($error); ?></div>
        <?php endif; ?>

        <div class="card">
            <h2 style="margin-top:0;">Publishing fee required</h2>
            <p style="color:var(--muted);font-size:.9rem;">A one-time fee is required to submit this article for admin review and publication.</p>

            <div style="padding:12px;background:var(--surface);border-radius:8px;margin-bottom:20px;border:1px solid var(--border);">
                <strong><?php echo sanitize($article['title']); ?></strong>
                <p style="margin:4px 0 0;font-size:.82rem;color:var(--muted);">Article · Submitted <?php echo date('d M Y', strtotime($article['created_at'])); ?></p>
            </div>

            <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:10px;padding:16px 18px;margin-bottom:20px;text-align:center;">
                <p style="margin:0;font-size:.85rem;color:#166534;">Publishing fee</p>
                <p style="margin:4px 0 0;font-size:1.6rem;font-weight:900;color:#15803d;">GH₵ <?php echo number_format($feeAmount, 2); ?></p>
            </div>

            <?php if ($existingPay): ?>
            <div class="alert alert-info">
                A Paystack payment is already in progress for this article (ref: <?php echo sanitize($existingPay['reference_code']); ?>).
                <br><a href="my_payments.php" style="color:var(--primary);">Track your payment →</a>
            </div>
            <?php else: ?>
            <form method="post">
                <?php echo csrf_field(); ?>
                <button type="submit" class="button button-primary" style="width:100%;padding:14px;font-size:1rem;">
                    🔒 Pay GH₵ <?php echo number_format($feeAmount, 2); ?> with Paystack
                </button>
            </form>
            <p style="font-size:.76rem;color:var(--muted);text-align:center;margin-top:10px;">Card · Mobile Money · Secure checkout</p>
            <?php endif; ?>
        </div>
    </main>
    <?php $activeNav = 'community'; require __DIR__ . '/partials/bottom_nav.php'; ?>
</body>
</html>
