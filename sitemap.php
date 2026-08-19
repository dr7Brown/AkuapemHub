<?php
/**
 * Dynamic XML sitemap — lists all public, indexable URLs. Referenced from
 * robots.txt. Regenerated fresh on every request (no caching) since content
 * changes constantly; the DB queries here are cheap, indexed lookups.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

header('Content-Type: application/xml; charset=UTF-8');

$base = rtrim(BASE_URL, '/');
$urls = [];

$urls[] = ['loc' => $base . '/', 'changefreq' => 'daily', 'priority' => '1.0'];

$staticPages = [
    'browse_jobs.php'    => ['daily',   '0.9'],
    'find_workers.php'   => ['daily',   '0.9'],
    'news.php'           => ['daily',   '0.8'],
    'events.php'         => ['daily',   '0.8'],
    'funerals.php'       => ['daily',   '0.7'],
    'marketplace.php'    => ['daily',   '0.8'],
    'shops.php'          => ['daily',   '0.7'],
    'markets.php'        => ['daily',   '0.7'],
    'quick_services.php' => ['weekly',  '0.6'],
    'about.php'          => ['monthly', '0.4'],
    'contact.php'        => ['monthly', '0.4'],
    'support.php'        => ['monthly', '0.3'],
    'terms.php'          => ['yearly',  '0.2'],
    'privacy.php'        => ['yearly',  '0.2'],
];
foreach ($staticPages as $page => [$freq, $prio]) {
    $urls[] = ['loc' => $base . '/' . $page, 'changefreq' => $freq, 'priority' => $prio];
}

// Open jobs — public, actively accepting applicants. deadline_date filter
// matters because request_detail.php only flips status to 'expired' lazily,
// the moment someone actually loads the page — a job whose deadline has
// passed but hasn't been visited since is still 'open' in the DB, and a
// guest (including Googlebot) hitting it triggers that inline expiry and
// gets redirected to jobs.php instead of seeing the job.
try {
    $stmt = $pdo->query(
        "SELECT id, updated_at FROM service_requests
         WHERE status IN ('open','partially_staffed') AND posting_fee_status != 'pending'
           AND (deadline_date IS NULL OR deadline_date >= NOW())
         ORDER BY created_at DESC LIMIT 2000"
    );
    foreach ($stmt->fetchAll() as $row) {
        $urls[] = [
            'loc' => $base . '/request_detail.php?id=' . (int)$row['id'],
            'lastmod' => date('Y-m-d', strtotime($row['updated_at'])),
            'changefreq' => 'weekly', 'priority' => '0.6',
        ];
    }
} catch (Throwable $e) { /* skip on error */ }

// Published news articles
try {
    $stmt = $pdo->query("SELECT slug, updated_at FROM news WHERE status='published' ORDER BY published_at DESC LIMIT 2000");
    foreach ($stmt->fetchAll() as $row) {
        $urls[] = [
            'loc' => $base . '/news_article.php?slug=' . urlencode($row['slug']),
            'lastmod' => date('Y-m-d', strtotime($row['updated_at'])),
            'changefreq' => 'monthly', 'priority' => '0.6',
        ];
    }
} catch (Throwable $e) {}

// Published events
try {
    $stmt = $pdo->query("SELECT slug, updated_at FROM events WHERE status='published' ORDER BY start_date DESC LIMIT 2000");
    foreach ($stmt->fetchAll() as $row) {
        $urls[] = [
            'loc' => $base . '/event.php?slug=' . urlencode($row['slug']),
            'lastmod' => date('Y-m-d', strtotime($row['updated_at'])),
            'changefreq' => 'weekly', 'priority' => '0.6',
        ];
    }
} catch (Throwable $e) {}

// Approved funeral announcements
try {
    $stmt = $pdo->query("SELECT slug, updated_at FROM funeral_announcements WHERE status='approved' ORDER BY created_at DESC LIMIT 2000");
    foreach ($stmt->fetchAll() as $row) {
        $urls[] = [
            'loc' => $base . '/funeral.php?slug=' . urlencode($row['slug']),
            'lastmod' => date('Y-m-d', strtotime($row['updated_at'])),
            'changefreq' => 'weekly', 'priority' => '0.5',
        ];
    }
} catch (Throwable $e) {}

// Marketplace: approved products and shops. Joined against mp_shops/users
// with the same filters marketplace.php's own browse query uses — an
// otherwise-approved product whose shop is suspended, a market's hidden
// system shop, or owned by a banned user would 302 on visit (product.php
// checks shop_owner_banned; shop.php checks status/market_id) if listed.
try {
    $stmt = $pdo->query(
        "SELECT mp.id, mp.updated_at FROM mp_products mp
         JOIN mp_shops ms ON mp.shop_id = ms.id
         WHERE mp.status='approved' AND ms.status='active' AND ms.market_id IS NULL
           AND ms.user_id NOT IN (SELECT id FROM users WHERE banned=1)
         ORDER BY mp.created_at DESC LIMIT 2000"
    );
    foreach ($stmt->fetchAll() as $row) {
        $urls[] = [
            'loc' => $base . '/product.php?id=' . (int)$row['id'],
            'lastmod' => date('Y-m-d', strtotime($row['updated_at'])),
            'changefreq' => 'weekly', 'priority' => '0.6',
        ];
    }
    // market_id IS NULL — a market's hidden "system shop" isn't a real
    // browsable shop; shop.php redirects it straight to markets.php, so
    // listing it here would just hand Google a 302.
    $stmt = $pdo->query("SELECT id, updated_at FROM mp_shops WHERE status='active' AND market_id IS NULL ORDER BY created_at DESC LIMIT 1000");
    foreach ($stmt->fetchAll() as $row) {
        $urls[] = [
            'loc' => $base . '/shop.php?id=' . (int)$row['id'],
            'lastmod' => date('Y-m-d', strtotime($row['updated_at'])),
            'changefreq' => 'weekly', 'priority' => '0.5',
        ];
    }
} catch (Throwable $e) {}

// Public worker profiles
try {
    // u.role = 'worker' — worker_profile_public.php redirects to
    // find_workers.php for anyone whose role has since changed away from
    // 'worker' (e.g. promoted to admin), even if their old profile row
    // is still sitting in the table.
    $stmt = $pdo->query(
        "SELECT w.user_id, w.updated_at FROM worker_profiles w
         JOIN users u ON u.id = w.user_id
         WHERE u.banned = 0 AND u.role = 'worker' ORDER BY w.created_at DESC LIMIT 2000"
    );
    foreach ($stmt->fetchAll() as $row) {
        $urls[] = [
            'loc' => $base . '/worker_profile_public.php?id=' . (int)$row['user_id'],
            'lastmod' => $row['updated_at'] ? date('Y-m-d', strtotime($row['updated_at'])) : date('Y-m-d'),
            'changefreq' => 'monthly', 'priority' => '0.5',
        ];
    }
} catch (Throwable $e) {}

// Nearby markets — market_view.php doesn't filter by status (a 'closed'
// market just isn't accepting orders today, it's still a real page), so
// listing every row here matches markets.php's own unfiltered query.
try {
    $stmt = $pdo->query('SELECT id, updated_at FROM markets ORDER BY name');
    foreach ($stmt->fetchAll() as $row) {
        $urls[] = [
            'loc' => $base . '/market_view.php?id=' . (int)$row['id'],
            'lastmod' => date('Y-m-d', strtotime($row['updated_at'])),
            'changefreq' => 'weekly', 'priority' => '0.5',
        ];
    }
} catch (Throwable $e) {}

// Quick Services: only the browse list is public — quick_service.php itself
// requires login to view a specific service, so an individual service page
// would just hand Google a login redirect. quick_services.php is already
// covered via $staticPages above.

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as $u) {
    echo "  <url>\n";
    echo '    <loc>' . htmlspecialchars($u['loc'], ENT_QUOTES, 'UTF-8') . "</loc>\n";
    if (!empty($u['lastmod'])) echo '    <lastmod>' . $u['lastmod'] . "</lastmod>\n";
    echo '    <changefreq>' . $u['changefreq'] . "</changefreq>\n";
    echo '    <priority>' . $u['priority'] . "</priority>\n";
    echo "  </url>\n";
}
echo '</urlset>';
