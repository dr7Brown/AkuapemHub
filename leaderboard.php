<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

$user = current_user();
$topWorkers = get_top_workers(10);
$trendingCategories = get_trending_categories(5, 30);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Leaderboard — AkuapemConnect</title>
    <link rel="stylesheet" href="assets/css/style.css" />
</head>
<body class="<?php echo $user ? 'has-bottom-nav' : ''; ?>">
    <header class="app-topbar">
        <span class="brand"><span class="brand-icon">🏆</span> Leaderboard</span>
        <?php if ($user): ?>
            <a href="logout.php" class="button button-secondary button-small">Logout</a>
        <?php else: ?>
            <a href="login.php?redirect=<?php echo urlencode(current_request_path()); ?>" class="button button-secondary button-small">Sign in</a>
        <?php endif; ?>
    </header>
    <main class="page-shell">
        <section class="panel">
            <h2>Top rated workers</h2>
            <?php if (empty($topWorkers)): ?>
                <div class="empty-state">No completed jobs yet — check back soon.</div>
            <?php else: ?>
                <?php foreach ($topWorkers as $index => $worker): ?>
                    <article class="request-card">
                        <div class="request-head">
                            <div>
                                <h2>
                                    #<?php echo $index + 1; ?> <?php echo sanitize($worker['name']); ?>
                                    <?php if (!empty($worker['is_verified'])): ?>
                                        <span style="display:inline-block;background:#22a06b;color:#fff;font-size:0.72rem;font-weight:700;padding:1px 7px;border-radius:20px;vertical-align:middle;margin-left:4px;">✓ Verified</span>
                                    <?php endif; ?>
                                    <?php
                                        $wFeatActive = !empty($worker['is_featured']) &&
                                            (empty($worker['featured_end_date']) || $worker['featured_end_date'] >= date('Y-m-d'));
                                    ?>
                                    <?php if ($wFeatActive): ?>
                                        <span style="display:inline-block;background:#f59e0b;color:#fff;font-size:0.72rem;font-weight:700;padding:1px 7px;border-radius:20px;vertical-align:middle;margin-left:4px;">⭐ Featured</span>
                                    <?php endif; ?>
                                </h2>
                                <p class="meta"><?php echo sanitize($worker['location'] ?: 'Location not set'); ?> • <?php echo sanitize(ucfirst($worker['subscription_status'])); ?></p>
                            </div>
                            <span class="status status-completed"><?php echo number_format($worker['avg_rating'], 1); ?>/5 <?php echo rating_stars(round($worker['avg_rating'])); ?></span>
                        </div>
                        <div class="request-footer">
                            <span><?php echo $worker['completed_jobs']; ?> jobs completed</span>
                            <a href="worker_profile_public.php?id=<?php echo $worker['id']; ?>" class="button button-primary button-small">View profile</a>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>

        <section class="panel" style="margin-top: 20px;">
            <h2>Trending categories (last 30 days)</h2>
            <?php if (empty($trendingCategories)): ?>
                <div class="empty-state">No requests yet.</div>
            <?php else: ?>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Category</th>
                                <th>Requests in last 30 days</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($trendingCategories as $category): ?>
                                <tr>
                                    <td><?php echo sanitize($category['name']); ?></td>
                                    <td><?php echo (int)$category['request_count']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </main>
    <?php if ($user): ?>
        <?php $activeNav = 'home'; require __DIR__ . '/partials/bottom_nav.php'; ?>
    <?php endif; ?>
</body>
</html>
