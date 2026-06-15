<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
$user = current_user();
if ($user) {
    header('Location: dashboard.php');
    exit;
}

// Active banner ad for homepage
$homeBannerAd = $pdo->query("SELECT * FROM advertisements WHERE status='active' AND ad_type='banner' AND (start_date IS NULL OR start_date<=CURDATE()) AND (end_date IS NULL OR end_date>=CURDATE()) ORDER BY RAND() LIMIT 1")->fetch();

// Latest 3 published news articles (teaser)
$latestNews = $pdo->query("SELECT title, slug, summary, featured_image, published_at FROM news WHERE status='published' AND (published_at IS NULL OR published_at<=NOW()) ORDER BY COALESCE(published_at, created_at) DESC LIMIT 3")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>AkuapemHub — Ghana Service Requests</title>
    <link rel="stylesheet" href="assets/css/style.css" />
</head>
<body>
    <main class="page-shell">
        <section class="hero-card">
            <h1>Welcome to AkuapemHub</h1>
            <p class="hero-description">Request errands, hire skilled workers, and post micro jobs in a single mobile-first system for Ghana.</p>
            <div class="hero-actions">
                <a href="register.php" class="button button-primary">Create account</a>
                <a href="login.php" class="button button-secondary">Sign in</a>
                <a href="find_workers.php" class="button button-secondary">Find workers</a>
                <a href="leaderboard.php" class="button button-secondary">Leaderboard</a>
            </div>
        </section>
        <?php if ($homeBannerAd): ?>
        <div style="text-align:center;margin:0 0 20px;">
            <div style="font-size:.68rem;letter-spacing:.07em;text-transform:uppercase;color:#9ca3af;margin-bottom:5px;">Advertisement</div>
            <a href="ad_click.php?id=<?php echo (int)$homeBannerAd['id']; ?>" target="_blank" rel="noopener sponsored" style="display:inline-block;max-width:728px;width:100%;">
                <?php if ($homeBannerAd['image']): ?>
                    <img src="<?php echo htmlspecialchars($homeBannerAd['image'], ENT_QUOTES); ?>" alt="<?php echo htmlspecialchars($homeBannerAd['title'], ENT_QUOTES); ?>" style="width:100%;border-radius:10px;">
                <?php else: ?>
                    <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:18px;font-weight:600;color:#6b7280;"><?php echo htmlspecialchars($homeBannerAd['title'], ENT_QUOTES); ?></div>
                <?php endif; ?>
            </a>
        </div>
        <?php endif; ?>

        <section class="info-grid">
            <article>
                <h2>Quick service requests</h2>
                <p>Create an errand, skilled work order, or micro job in seconds.</p>
            </article>
            <article>
                <h2>Worker match</h2>
                <p>Workers see nearby jobs and accept tasks with one tap.</p>
            </article>
            <article>
                <h2>Admin control</h2>
                <p>Admins approve requests, manage users, and keep the platform safe.</p>
            </article>
        </section>

        <?php if ($latestNews): ?>
        <section style="margin:20px 0 0;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
                <h2 style="margin:0;font-size:1.1rem;">📰 Latest News</h2>
                <a href="news.php" style="font-size:.85rem;color:#0f766e;font-weight:600;text-decoration:none;">View all →</a>
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:14px;">
            <?php foreach ($latestNews as $n):
                $nThumb = $n['featured_image'] ? htmlspecialchars($n['featured_image'], ENT_QUOTES) : '';
                $nDate  = $n['published_at'] ? date('M j, Y', strtotime($n['published_at'])) : '';
            ?>
            <a href="news_article.php?slug=<?php echo urlencode($n['slug']); ?>" style="display:flex;flex-direction:column;background:#fff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;text-decoration:none;color:inherit;transition:box-shadow .15s;">
                <?php if ($nThumb): ?>
                    <img src="<?php echo $nThumb; ?>" alt="" style="width:100%;aspect-ratio:16/9;object-fit:cover;">
                <?php else: ?>
                    <div style="width:100%;aspect-ratio:16/9;background:linear-gradient(135deg,#0f766e,#0d5e57);display:flex;align-items:center;justify-content:center;font-size:1.5rem;">📰</div>
                <?php endif; ?>
                <div style="padding:10px 12px 12px;">
                    <p style="margin:0 0 4px;font-weight:700;font-size:.88rem;line-height:1.35;"><?php echo htmlspecialchars($n['title'], ENT_QUOTES); ?></p>
                    <?php if ($nDate): ?><p style="margin:0;font-size:.75rem;color:#6b7280;">📅 <?php echo $nDate; ?></p><?php endif; ?>
                </div>
            </a>
            <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>
    </main>
    <footer style="text-align:center;padding:20px 16px 32px;font-size:.8rem;color:#6b7280;border-top:1px solid #e5e7eb;margin-top:20px;">
        &copy; <?php echo date('Y'); ?> AkuapemHub &nbsp;·&nbsp;
        <a href="contact.php" style="color:#0f766e;">Contact</a> &nbsp;·&nbsp;
        <a href="privacy.php" style="color:#0f766e;">Privacy Policy</a> &nbsp;·&nbsp;
        <a href="terms.php" style="color:#0f766e;">Terms of Service</a>
    </footer>
</body>
</html>
