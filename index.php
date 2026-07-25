<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/delivery_functions.php';

$user  = current_user();
$today = date('Y-m-d');

$upcomingEvents = $pdo->query(
    "SELECT * FROM events WHERE status='published' AND start_date >= '$today'
     ORDER BY (featured=1 AND (featured_end_date IS NULL OR featured_end_date>=CURDATE())) DESC, start_date ASC LIMIT 4"
)->fetchAll();

$recentFunerals = $pdo->query(
    "SELECT * FROM funeral_announcements WHERE status='approved'
     ORDER BY (featured=1 AND (featured_end_date IS NULL OR featured_end_date>=CURDATE())) DESC, created_at DESC LIMIT 4"
)->fetchAll();

$latestNews = $pdo->query(
    "SELECT * FROM news WHERE status='published'
     ORDER BY published_at DESC LIMIT 3"
)->fetchAll();

$openJobs = $pdo->query(
    "SELECT sr.id, sr.title, sr.description, sr.budget_amount, sr.budget, sr.location, sr.created_at,
            sr.payment_status, sr.payment_mode, c.name AS category
     FROM service_requests sr
     LEFT JOIN service_categories c ON sr.category_id = c.id
     WHERE sr.status IN (" . public_job_statuses_sql() . ") AND sr.posting_fee_status != 'pending'
     ORDER BY (sr.featured=1 AND (sr.featured_end_date IS NULL OR sr.featured_end_date>=CURDATE())) DESC, sr.created_at DESC LIMIT 4"
)->fetchAll();

// Marketplace featured products
$featuredProducts = [];
try {
    $featuredProducts = $pdo->query(
        "SELECT mp.id, mp.name, mp.price, mp.discount_price, mp.condition_type, mp.is_sponsored, mp.sponsored_end, mp.is_featured, mp.featured_end,
                ms.shop_name, ms.id AS shop_id,
                mc.icon AS cat_icon,
                mpi.image_path AS primary_image
         FROM mp_products mp
         JOIN mp_shops ms ON mp.shop_id = ms.id
         LEFT JOIN mp_categories mc ON mp.category_id = mc.id
         LEFT JOIN mp_product_images mpi ON mpi.product_id = mp.id AND mpi.is_primary = 1
         WHERE mp.status = 'approved' AND ms.status = 'active'
         ORDER BY (mp.is_sponsored=1 AND mp.sponsored_end>=CURDATE()) DESC,
                  (mp.is_featured=1 AND mp.featured_end>=CURDATE()) DESC,
                  mp.created_at DESC
         LIMIT 4"
    )->fetchAll();
} catch (Exception $e) {}

// Admin can independently show/hide each delivery source on the homepage feed,
// and restrict the whole feed to delivery agents only — delivery agents still
// see every open job on their own dashboard regardless of these settings.
$openDeliveries = [];
try {
    $feedAudience  = get_platform_setting('homepage_delivery_feed_audience', 'everyone');
    $viewerIsAgent = $user && get_delivery_agent_for_user((int)$user['id']);

    if ($feedAudience !== 'agents_only' || $viewerIsAgent) {
        $showMpDeliveries       = get_platform_setting('homepage_show_marketplace_deliveries', '1') === '1';
        $showPersonalDeliveries = get_platform_setting('homepage_show_personal_deliveries', '1') === '1';

        $sourceFilter = '';
        if ($showMpDeliveries && !$showPersonalDeliveries) {
            $sourceFilter = 'AND EXISTS (SELECT 1 FROM mp_orders mo WHERE mo.delivery_request_id = dr.id)';
        } elseif (!$showMpDeliveries && $showPersonalDeliveries) {
            $sourceFilter = 'AND NOT EXISTS (SELECT 1 FROM mp_orders mo WHERE mo.delivery_request_id = dr.id)';
        }

        if ($showMpDeliveries || $showPersonalDeliveries) {
            $openDeliveries = $pdo->query(
                "SELECT dr.id, dr.item_description, dr.item_category,
                        dr.pickup_location, dr.dropoff_location,
                        dr.delivery_fee, dr.preferred_date, dr.created_at
                 FROM delivery_requests dr
                 WHERE dr.status = 'approved' AND dr.agent_id IS NULL $sourceFilter
                 ORDER BY dr.preferred_date ASC, dr.created_at DESC LIMIT 4"
            )->fetchAll();
        }
    }
} catch (Exception $e) { /* delivery tables not yet created */ }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php echo seo_meta([
        'title'       => APP_NAME . ' — Find Trusted Workers & Jobs in the Akuapem Area, Ghana',
        'description' => 'Hire verified local workers, post jobs and service requests, buy and sell on the marketplace, and stay updated with community news, events & funeral announcements across the Akuapem area of Ghana.',
        'url'         => rtrim(BASE_URL, '/') . '/',
    ]); ?>
    <script type="application/ld+json">
    <?php echo json_encode([
        '@context' => 'https://schema.org',
        '@graph'   => [
            [
                '@type' => 'Organization',
                'name'  => APP_NAME,
                'url'   => rtrim(BASE_URL, '/') . '/',
                'logo'  => rtrim(BASE_URL, '/') . '/assets/images/ac%20logo.png',
            ],
            [
                '@type'           => 'WebSite',
                'name'            => APP_NAME,
                'url'             => rtrim(BASE_URL, '/') . '/',
                'potentialAction' => [
                    '@type'       => 'SearchAction',
                    'target'      => rtrim(BASE_URL, '/') . '/browse_jobs.php?q={search_term_string}',
                    'query-input' => 'required name=search_term_string',
                ],
            ],
        ],
    ], JSON_UNESCAPED_SLASHES); ?>
    </script>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .cm-hero {
            background: linear-gradient(135deg, rgba(15,118,110,.80) 0%, rgba(6,95,70,.88) 100%),
                        url('assets/images/heroes/hero-home.jpg') center/cover no-repeat;
            color: #fff;
            padding: 56px 20px 48px;
            text-align: center;
        }
        .cm-hero h1 { font-size:clamp(1.6rem,5vw,2.4rem); font-weight:900; margin:0 0 10px; text-shadow:0 2px 8px rgba(0,0,0,.25); }
        .cm-hero p  { font-size:1rem; color:#a7f3d0; margin:0; text-shadow:0 1px 4px rgba(0,0,0,.2); }

        .cm-modules { display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:14px; max-width:900px; margin:-28px auto 0; padding:0 16px; position:relative; z-index:2; }
        .cm-mod     { background:var(--surface,#fff); border:1px solid var(--border,#e5e7eb); border-radius:14px; padding:20px 16px; text-align:center; text-decoration:none; color:inherit; transition:box-shadow .15s,transform .15s; }
        .cm-mod:hover { box-shadow:0 6px 24px rgba(0,0,0,.1); transform:translateY(-3px); }
        .cm-mod-icon  { font-size:2rem; margin-bottom:8px; }
        .cm-mod-title { font-weight:800; font-size:.95rem; margin-bottom:3px; }
        .cm-mod-desc  { font-size:.75rem; color:var(--muted,#6b7280); }

        .cm-shell  { max-width:1060px; margin:0 auto; padding:36px 16px 60px; }
        .cm-section { margin-bottom:36px; }
        .cm-section-head { display:flex; align-items:center; justify-content:space-between; margin-bottom:14px; }
        .cm-section-head h2 { font-size:.78rem; font-weight:800; text-transform:uppercase; letter-spacing:.07em; color:var(--muted,#6b7280); margin:0; }
        .cm-section-head a  { font-size:.82rem; font-weight:700; color:var(--primary,#0f766e); text-decoration:none; }

        /* ── Jobs cards ── */
        .cm-job-row   { display:grid; grid-template-columns:repeat(auto-fill,minmax(240px,1fr)); gap:14px; }
        .cm-job-card  { background:var(--surface,#fff); border:1px solid var(--border,#e5e7eb); border-radius:14px; padding:16px 16px 16px 20px; text-decoration:none; color:inherit; display:flex; flex-direction:column; position:relative; overflow:hidden; transition:box-shadow .2s,transform .2s; }
        .cm-job-card::before { content:''; position:absolute; left:0; top:0; bottom:0; width:4px; background:var(--primary,#0f766e); border-radius:4px 0 0 4px; }
        .cm-job-card:hover { box-shadow:0 8px 28px rgba(0,0,0,.1); transform:translateY(-2px); }
        .cm-job-cat   { display:inline-block; font-size:.67rem; font-weight:800; padding:3px 9px; border-radius:20px; background:#f0fdf4; color:#065f46; align-self:flex-start; margin-bottom:8px; letter-spacing:.04em; text-transform:uppercase; }
        .cm-job-title { font-weight:800; font-size:.92rem; line-height:1.4; padding-bottom:8px; }
        .cm-job-footer{ display:flex; align-items:flex-end; justify-content:space-between; padding-top:10px; border-top:1px solid var(--border,#e5e7eb); gap:8px; }
        .cm-job-budget{ font-size:.88rem; font-weight:900; color:var(--primary,#0f766e); white-space:nowrap; }
        .cm-job-meta  { font-size:.72rem; color:var(--muted,#6b7280); line-height:1.6; }

        /* ── Events cards ── */
        .cm-ev-row  { display:grid; grid-template-columns:repeat(auto-fill,minmax(230px,1fr)); gap:14px; }
        .cm-ev-card { background:var(--surface,#fff); border:1px solid var(--border,#e5e7eb); border-radius:16px; overflow:hidden; text-decoration:none; color:inherit; display:flex; flex-direction:column; transition:box-shadow .2s,transform .2s; }
        .cm-ev-card:hover { box-shadow:0 8px 28px rgba(0,0,0,.1); transform:translateY(-3px); }
        .cm-ev-img  { aspect-ratio:16/9; background:linear-gradient(135deg,#f0fdf4,#d1fae5); display:flex; align-items:center; justify-content:center; overflow:hidden; position:relative; flex-shrink:0; }
        .cm-ev-img img { width:100%; height:100%; object-fit:cover; }
        .cm-ev-img-icon { font-size:2.2rem; opacity:.45; }
        .cm-ev-date-badge { position:absolute; bottom:10px; left:10px; background:var(--primary,#0f766e); color:#fff; border-radius:8px; padding:4px 10px; font-size:.7rem; font-weight:800; letter-spacing:.02em; }
        .cm-ev-body { padding:12px 14px 14px; display:flex; flex-direction:column; flex:1; }
        .cm-ev-title { font-weight:800; font-size:.92rem; line-height:1.4; margin:0 0 6px; }
        .cm-ev-meta  { font-size:.73rem; color:var(--muted,#6b7280); line-height:1.55; }

        /* ── Funeral Announcement cards ── */
        .cm-fa-row  { display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:14px; }
        .cm-fa-card { background:var(--surface,#fff); border:1px solid var(--border,#e5e7eb); border-radius:16px; overflow:hidden; text-decoration:none; color:inherit; display:flex; flex-direction:column; transition:box-shadow .2s,transform .2s; }
        .cm-fa-card:hover { box-shadow:0 8px 28px rgba(0,0,0,.1); transform:translateY(-3px); }
        .cm-fa-img  { aspect-ratio:4/3; background:linear-gradient(135deg,#f5f0eb,#ede4d8); overflow:hidden; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
        .cm-fa-img img { width:100%; height:100%; object-fit:cover; object-position:top; }
        .cm-fa-initials { font-size:3rem; font-weight:900; color:#c4b09a; }
        .cm-fa-info { padding:12px 14px 14px; display:flex; flex-direction:column; gap:3px; }
        .cm-fa-name { font-weight:800; font-size:.92rem; margin-bottom:2px; }
        .cm-fa-meta { font-size:.73rem; color:var(--muted,#6b7280); line-height:1.55; }

        /* ── News cards ── */
        .cm-news-row { display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:14px; }
        .cm-news-card { background:var(--surface,#fff); border:1px solid var(--border,#e5e7eb); border-radius:16px; overflow:hidden; text-decoration:none; color:inherit; display:flex; flex-direction:column; transition:box-shadow .2s,transform .2s; }
        .cm-news-card:hover { box-shadow:0 8px 28px rgba(0,0,0,.1); transform:translateY(-3px); }
        .cm-news-img { aspect-ratio:16/8; background:linear-gradient(135deg,#f8fafc,#f1f5f9); overflow:hidden; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
        .cm-news-img img { width:100%; height:100%; object-fit:cover; }
        .cm-news-img-icon { font-size:2.2rem; opacity:.4; }
        .cm-news-body { padding:14px 16px 16px; flex:1; display:flex; flex-direction:column; }
        .cm-news-title { font-weight:800; font-size:.93rem; line-height:1.4; margin:0 0 6px; overflow:hidden; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; }
        .cm-news-excerpt { font-size:.78rem; color:var(--muted,#6b7280); line-height:1.55; margin:0 0 10px; overflow:hidden; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; flex:1; }
        .cm-news-footer { display:flex; align-items:center; justify-content:space-between; padding-top:10px; border-top:1px solid var(--border,#e5e7eb); }
        .cm-news-meta  { font-size:.72rem; color:var(--muted,#6b7280); }
        .cm-news-read  { font-size:.75rem; font-weight:700; color:var(--primary,#0f766e); }

        .cm-empty { text-align:center; color:var(--muted,#6b7280); font-size:.88rem; padding:24px; background:var(--surface,#fff); border:1px solid var(--border,#e5e7eb); border-radius:12px; }

        .cm-cta { background:linear-gradient(135deg,#1e293b,#0f172a); color:#fff; border-radius:16px; padding:24px 20px; display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap; }
        .cm-cta h3 { font-size:1rem; font-weight:800; margin:0 0 4px; }
        .cm-cta p  { font-size:.85rem; color:#94a3b8; margin:0; }
        .cm-cta a  { white-space:nowrap; }
        @media(max-width:760px){ .cm-cta-row { grid-template-columns:1fr !important; } }

        /* ── Marketplace product row (desktop grid) ── */
        .cm-mp-row { display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:12px; }
        .cm-mp-card { background:var(--surface,#fff); border:1px solid var(--border,#e5e7eb); border-radius:14px; overflow:hidden; text-decoration:none; color:inherit; display:flex; flex-direction:column; transition:box-shadow .15s,transform .15s; }
        .cm-mp-card:hover { box-shadow:0 6px 20px rgba(0,0,0,.1); transform:translateY(-2px); }
        .cm-mp-card--sponsored { border:2px solid #f59e0b; }

        /* ═══════════════════════════════════════════════
           MOBILE & TABLET  (≤ 767 px)
           All content rows → horizontal scroll strips.
           Module menu → horizontal scroll.
           3 cards visible per screen width.
        ═══════════════════════════════════════════════ */
        @media (max-width: 767px) {

            /* ── Guest top nav: horizontal scroll ── */
            header nav { flex-wrap:nowrap; overflow-x:auto; scrollbar-width:none; -webkit-overflow-scrolling:touch; padding-bottom:2px; }
            header nav::-webkit-scrollbar { display:none; }

            /* ── Hero: smaller on mobile ── */
            .cm-hero { padding:28px 16px 24px; }
            .cm-hero h1 { font-size:1.35rem; margin:0 0 6px; }
            .cm-hero p  { font-size:.86rem; }

            /* ── Module strip: horizontal scroll, ~4.5 per view ── */
            .cm-modules {
                display:flex;
                flex-wrap:nowrap;
                overflow-x:auto;
                gap:8px;
                padding:0 0 12px 16px;
                margin:-20px 0 0;
                scroll-snap-type:x mandatory;
                scrollbar-width:none;
                -webkit-overflow-scrolling:touch;
                width:100%;
                max-width:100%;
            }
            .cm-modules::-webkit-scrollbar { display:none; }
            .cm-mod {
                flex:0 0 calc(21vw);
                min-width:68px;
                max-width:100px;
                padding:12px 6px;
                scroll-snap-align:start;
            }
            .cm-mod-icon  { font-size:1.5rem; margin-bottom:4px; }
            .cm-mod-title { font-size:.65rem; margin-bottom:0; line-height:1.25; overflow:hidden; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; }
            .cm-mod-desc  { display:none; }

            /* ── Shared row layout: flex horizontal scroll ── */
            .cm-shell { padding:20px 0 60px; }
            .cm-section { margin-bottom:22px; }
            .cm-section-head { padding:0 16px; margin-bottom:10px; }
            .cm-section > div[style*="margin-top:14px"],
            .cm-section > div[style*="margin-top: 14px"] { padding:0 16px; }

            /* Common scroll row rules applied to all row types */
            .cm-job-row,
            .cm-ev-row,
            .cm-fa-row,
            .cm-news-row,
            .cm-mp-row {
                display:flex;
                flex-wrap:nowrap;
                overflow-x:auto;
                gap:10px;
                padding:0 16px 10px;
                scroll-snap-type:x mandatory;
                scrollbar-width:none;
                -webkit-overflow-scrolling:touch;
            }
            .cm-job-row::-webkit-scrollbar,
            .cm-ev-row::-webkit-scrollbar,
            .cm-fa-row::-webkit-scrollbar,
            .cm-news-row::-webkit-scrollbar,
            .cm-mp-row::-webkit-scrollbar { display:none; }

            /* ── Card widths: exactly 3 per screen ── */
            .cm-job-card,
            .cm-ev-card,
            .cm-fa-card,
            .cm-news-card,
            .cm-mp-card {
                flex:0 0 calc(33.333vw - 14px);
                min-width:96px;
                scroll-snap-align:start;
            }

            /* ── Job card mobile tweaks ── */
            .cm-job-card { padding:10px 10px 10px 14px; }
            .cm-job-cat   { font-size:.6rem; padding:2px 6px; margin-bottom:5px; }
            .cm-job-title { font-size:.8rem; line-height:1.35; padding-bottom:6px; -webkit-line-clamp:3; }
            .cm-job-footer{ padding-top:6px; gap:4px; flex-direction:column; align-items:flex-start; }
            .cm-job-budget{ font-size:.8rem; }
            .cm-job-meta  { font-size:.66rem; }

            /* ── Event card mobile tweaks ── */
            .cm-ev-img { aspect-ratio:4/3; }
            .cm-ev-date-badge { font-size:.6rem; padding:3px 6px; bottom:6px; left:6px; }
            .cm-ev-body { padding:8px 10px 10px; }
            .cm-ev-title { font-size:.8rem; -webkit-line-clamp:2; margin-bottom:3px; }
            .cm-ev-meta  { font-size:.66rem; }

            /* ── Funeral card mobile tweaks ── */
            .cm-fa-img { aspect-ratio:1/1; }
            .cm-fa-initials { font-size:2rem; }
            .cm-fa-info { padding:8px 10px 10px; gap:1px; }
            .cm-fa-name { font-size:.8rem; margin-bottom:1px; }
            .cm-fa-meta { font-size:.66rem; }

            /* ── News card mobile tweaks ── */
            .cm-news-img { aspect-ratio:16/9; }
            .cm-news-body { padding:8px 10px 10px; }
            .cm-news-title { font-size:.8rem; -webkit-line-clamp:2; margin-bottom:2px; }
            .cm-news-excerpt { display:none; }
            .cm-news-footer { padding-top:6px; }
            .cm-news-meta, .cm-news-read { font-size:.66rem; }

            /* ── Marketplace product card mobile tweaks ── */
            .cm-mp-card > div:first-child { aspect-ratio:1/1; }
            .cm-mp-card > div:last-child  { padding:7px 9px 9px; }

            /* ── Empty state: keep padded ── */
            .cm-empty { margin:0 16px; }

            /* ── CTA block ── */
            .cm-cta { margin:0 16px; border-radius:12px; padding:16px; }
        }

        /* ── Mid-tablet (540 – 767 px): ~4.5 module cards, 3 content cards ── */
        @media (min-width:540px) and (max-width:767px) {
            .cm-mod {
                flex:0 0 calc(21vw);
                min-width:80px;
                max-width:120px;
            }
            .cm-mod-desc { display:block; font-size:.66rem; }
            .cm-job-card,
            .cm-ev-card,
            .cm-fa-card,
            .cm-news-card,
            .cm-mp-card {
                flex:0 0 calc(33.333vw - 12px);
                min-width:140px;
            }
        }
    </style>
</head>
<body <?php echo $user ? 'class="has-bottom-nav"' : ''; ?>>

<?php if (!$user): ?>
<header style="background:var(--surface,#fff);border-bottom:1px solid var(--border,#e5e7eb);padding:12px 16px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
    <a href="index.php" style="text-decoration:none;display:flex;align-items:center;">
        <img src="assets/images/ac%20logo.png" alt="<?php echo APP_NAME; ?>" style="height:38px;width:auto;display:block;">
    </a>
    <nav style="display:flex;gap:12px;align-items:center;">
        <a href="browse_jobs.php"  style="font-size:.85rem;color:var(--muted,#6b7280);text-decoration:none;font-weight:600;">Jobs</a>
        <a href="marketplace.php"  style="font-size:.85rem;color:var(--muted,#6b7280);text-decoration:none;font-weight:600;">Marketplace</a>
        <a href="find_workers.php" style="font-size:.85rem;color:var(--muted,#6b7280);text-decoration:none;font-weight:600;">Workers</a>
        <a href="events.php"       style="font-size:.85rem;color:var(--muted,#6b7280);text-decoration:none;font-weight:600;">Events</a>
        <a href="news.php"         style="font-size:.85rem;color:var(--muted,#6b7280);text-decoration:none;font-weight:600;">News</a>
        <a href="about.php"        style="font-size:.85rem;color:var(--muted,#6b7280);text-decoration:none;font-weight:600;">About</a>
        <a href="login.php"    class="button button-secondary button-small">Sign in</a>
    </nav>
</header>
<?php else: ?>
<header class="app-topbar">
    <span class="brand"><span class="brand-icon">🌍</span> Community</span>
</header>
<?php endif; ?>

<div class="cm-hero">
    <h1>Welcome to <?php echo APP_NAME; ?></h1>
    <p>Find workers, post jobs, explore events, news &amp; community announcements — all in one place for Ghana</p>
    <?php if (!$user): ?>
    <div style="display:flex;gap:10px;justify-content:center;margin-top:18px;flex-wrap:wrap;">
        <a href="register.php"     class="button button-primary">Create free account</a>
        <a href="browse_jobs.php"  style="background:rgba(255,255,255,.15);color:#fff;padding:10px 20px;border-radius:10px;text-decoration:none;font-weight:700;font-size:.9rem;border:1px solid rgba(255,255,255,.3);">Browse Jobs</a>
    </div>
    <?php endif; ?>
</div>

<!-- Module cards -->
<div class="cm-modules">
    <a href="<?php echo $user ? 'jobs.php' : 'browse_jobs.php'; ?>" class="cm-mod"><div class="cm-mod-icon">💼</div><div class="cm-mod-title">Jobs &amp; Services</div><div class="cm-mod-desc">Browse open jobs &amp; post requests</div></a>
    <a href="news.php"         class="cm-mod"><div class="cm-mod-icon">📰</div><div class="cm-mod-title">News &amp; Updates</div><div class="cm-mod-desc">Latest articles &amp; platform news</div></a>
    <a href="events.php"       class="cm-mod"><div class="cm-mod-icon">📅</div><div class="cm-mod-title">Events</div><div class="cm-mod-desc">Community events &amp; programs</div></a>
    <a href="funerals.php"     class="cm-mod"><div class="cm-mod-icon">🕊️</div><div class="cm-mod-title">Funeral Announcements</div><div class="cm-mod-desc">Memorial notices</div></a>
    <a href="find_workers.php" class="cm-mod"><div class="cm-mod-icon">🔧</div><div class="cm-mod-title">Find Workers</div><div class="cm-mod-desc">Skilled professionals near you</div></a>
    <a href="delivery.php"    class="cm-mod"><div class="cm-mod-icon">🚚</div><div class="cm-mod-title">Delivery Services</div><div class="cm-mod-desc">Send &amp; receive parcels fast</div></a>
    <a href="marketplace.php" class="cm-mod"><div class="cm-mod-icon">🛍️</div><div class="cm-mod-title">Marketplace</div><div class="cm-mod-desc">Buy &amp; sell products locally</div></a>
</div>

<div class="cm-shell">

    <!-- Marketplace Featured Products -->
    <?php if ($featuredProducts): ?>
    <div class="cm-section">
        <div class="cm-section-head">
            <h2>🛍️ Marketplace</h2>
            <a href="marketplace.php">View all →</a>
        </div>
        <div class="cm-mp-row">
            <?php foreach ($featuredProducts as $fp):
                $effP = !empty($fp['discount_price']) ? (float)$fp['discount_price'] : (float)$fp['price'];
                $disc = (!empty($fp['discount_price']) && $fp['price'] > 0) ? (int)round((1 - $fp['discount_price']/$fp['price'])*100) : 0;
                $isSp = $fp['is_sponsored'] && !empty($fp['sponsored_end']) && $fp['sponsored_end'] >= date('Y-m-d');
            ?>
            <a href="product.php?id=<?php echo (int)$fp['id']; ?>" class="cm-mp-card<?php echo $isSp?' cm-mp-card--sponsored':''; ?>">
                <div style="aspect-ratio:1/1;background:#f8fafc;overflow:hidden;display:flex;align-items:center;justify-content:center;position:relative;">
                    <?php if ($fp['primary_image']): ?><img src="<?php echo sanitize($fp['primary_image']); ?>" style="width:100%;height:100%;object-fit:cover;" alt=""><?php else: ?><span style="font-size:2.5rem;opacity:.3;"><?php echo $fp['cat_icon']??'📦'; ?></span><?php endif; ?>
                    <?php if ($isSp): ?><span style="position:absolute;top:6px;left:6px;background:#f59e0b;color:#fff;font-size:.6rem;font-weight:800;padding:2px 7px;border-radius:10px;">SPONSORED</span><?php endif; ?>
                    <?php if ($disc>=10): ?><span style="position:absolute;top:<?php echo $isSp?'26px':'6px'; ?>;left:6px;background:#ef4444;color:#fff;font-size:.6rem;font-weight:800;padding:2px 6px;border-radius:10px;">-<?php echo $disc; ?>%</span><?php endif; ?>
                </div>
                <div style="padding:10px 12px 12px;">
                    <div style="font-weight:700;font-size:.84rem;line-height:1.4;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;margin-bottom:4px;"><?php echo sanitize($fp['name']); ?></div>
                    <div style="font-size:.72rem;color:var(--muted,#6b7280);">🏪 <?php echo sanitize(mb_substr($fp['shop_name'],0,28)); ?></div>
                    <div style="font-weight:900;color:var(--primary,#0f766e);font-size:.92rem;margin-top:6px;">
                        GH&#8373; <?php echo number_format($effP,2); ?>
                        <?php if ($disc>0): ?><span style="font-size:.76rem;color:var(--muted,#6b7280);font-weight:400;text-decoration:line-through;margin-left:4px;">GH&#8373; <?php echo number_format((float)$fp['price'],2); ?></span><?php endif; ?>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap;">
            <a href="marketplace.php" class="button button-secondary">🛍️ Browse All Products</a>
            <?php if ($user): ?><a href="seller_dashboard.php" class="button button-primary">➕ Start Selling</a><?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Open Delivery Requests -->
    <?php if ($openDeliveries): ?>
    <div class="cm-section">
        <div class="cm-section-head">
            <h2>Open Delivery Requests</h2>
            <a href="delivery.php">View all →</a>
        </div>
        <div class="cm-job-row">
            <?php
            $catIcons = ['documents'=>'📄','food'=>'🍔','electronics'=>'📱','clothing'=>'👕','medical_supplies'=>'💊','groceries'=>'🛒','parcels'=>'📦','other'=>'📦'];
            $catLabels= ['documents'=>'Documents','food'=>'Food','electronics'=>'Electronics','clothing'=>'Clothing','medical_supplies'=>'Medical','groceries'=>'Groceries','parcels'=>'Parcels','other'=>'Other'];
            foreach ($openDeliveries as $dr):
                $catIcon  = $catIcons[$dr['item_category']]  ?? '📦';
                $catLabel = $catLabels[$dr['item_category']] ?? 'Parcel';
            ?>
            <a href="delivery_detail.php?id=<?php echo (int)$dr['id']; ?>" class="cm-job-card" style="border-left-color:#f97316;">
                <span class="cm-job-cat" style="background:#fff7ed;color:#9a3412;"><?php echo $catIcon; ?> <?php echo sanitize($catLabel); ?></span>
                <div class="cm-job-title"><?php echo sanitize(mb_substr($dr['item_description'],0,72)).(mb_strlen($dr['item_description'])>72?'…':''); ?></div>
                <div class="cm-job-footer">
                    <div>
                        <div class="cm-job-meta">📍 <?php echo sanitize(mb_substr($dr['pickup_location'],0,36)); ?></div>
                        <div class="cm-job-meta">🏁 <?php echo sanitize(mb_substr($dr['dropoff_location'],0,36)); ?></div>
                        <?php if ($dr['preferred_date']): ?>
                        <div class="cm-job-meta">📅 <?php echo date('d M Y', strtotime($dr['preferred_date'])); ?></div>
                        <?php endif; ?>
                    </div>
                    <?php if ($dr['delivery_fee']): ?>
                    <div class="cm-job-budget" style="color:#ea580c;">GH₵ <?php echo number_format((float)$dr['delivery_fee'],2); ?></div>
                    <?php else: ?>
                    <div class="cm-job-budget" style="color:#9ca3af;font-size:.75rem;">Fee TBD</div>
                    <?php endif; ?>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap;">
            <a href="delivery.php" class="button button-secondary">🚚 Browse Deliveries</a>
            <?php if ($user): ?>
            <a href="delivery_request.php" class="button button-primary">➕ Request Delivery</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Open Jobs & Services -->
    <div class="cm-section">
        <div class="cm-section-head">
            <h2>Open Jobs &amp; Services</h2>
            <a href="<?php echo $user ? 'jobs.php' : 'browse_jobs.php'; ?>">View all →</a>
        </div>
        <?php if ($openJobs): ?>
        <div class="cm-job-row">
            <?php foreach ($openJobs as $job): ?>
            <a href="request_detail.php?id=<?php echo (int)$job['id']; ?>" class="cm-job-card">
                <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-bottom:6px;">
                    <?php if ($job['category']): ?><span class="cm-job-cat" style="margin-bottom:0;"><?php echo sanitize($job['category']); ?></span><?php endif; ?>
                    <?php
                    $pMode   = $job['payment_mode']   ?? 'direct';
                    $pStatus = $job['payment_status'] ?? 'unpaid';
                    if ($pMode === 'escrow' && $pStatus === 'paid'):
                    ?><span style="font-size:.63rem;font-weight:800;padding:2px 7px;border-radius:20px;background:#d1fae5;color:#065f46;white-space:nowrap;">🔒 Escrow Paid</span>
                    <?php elseif ($pMode === 'escrow' && $pStatus !== 'paid'): ?>
                    <span style="font-size:.63rem;font-weight:800;padding:2px 7px;border-radius:20px;background:#fef3c7;color:#92400e;white-space:nowrap;">🔒 Escrow</span>
                    <?php elseif ($pStatus === 'paid'): ?>
                    <span style="font-size:.63rem;font-weight:800;padding:2px 7px;border-radius:20px;background:#dbeafe;color:#1e40af;white-space:nowrap;">✓ Paid</span>
                    <?php else: ?>
                    <span style="font-size:.63rem;font-weight:800;padding:2px 7px;border-radius:20px;background:#f3f4f6;color:#6b7280;white-space:nowrap;">Unpaid</span>
                    <?php endif; ?>
                </div>
                <div class="cm-job-title"><?php echo sanitize($job['title']); ?></div>
                <?php if (!empty($job['description'])): ?>
                <div style="font-size:.78rem;color:var(--muted,#6b7280);line-height:1.5;margin-bottom:10px;
                            display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;">
                    <?php echo sanitize(strip_tags($job['description'])); ?>
                </div>
                <?php endif; ?>
                <div class="cm-job-footer">
                    <div>
                        <?php if ($job['location']): ?><div class="cm-job-meta">📍 <?php echo sanitize(mb_substr($job['location'],0,36)); ?></div><?php endif; ?>
                        <div class="cm-job-meta">🕐 <?php echo date('d M Y', strtotime($job['created_at'])); ?></div>
                    </div>
                    <div style="text-align:right;flex-shrink:0;">
                        <?php if ($job['budget_amount']): ?>
                        <div class="cm-job-budget">GH₵ <?php echo number_format((float)$job['budget_amount'],2); ?></div>
                        <?php elseif (!empty($job['budget'])): ?>
                        <div class="cm-job-budget" style="font-size:.8rem;"><?php echo sanitize(mb_substr($job['budget'],0,24)); ?></div>
                        <?php endif; ?>
                        <?php
                        $budgetLower = strtolower($job['budget'] ?? '');
                        if (str_contains($budgetLower,'negotiable') || str_contains($budgetLower,'open') || str_contains($budgetLower,'discuss')):
                        ?>
                        <div style="font-size:.67rem;font-weight:700;color:var(--muted,#6b7280);margin-top:1px;">Negotiable</div>
                        <?php endif; ?>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap;">
            <?php if ($user): ?>
            <a href="jobs.php"    class="button button-secondary">📋 Browse Jobs</a>
            <a href="request.php" class="button button-primary">➕ Post a Job</a>
            <?php else: ?>
            <a href="browse_jobs.php" class="button button-secondary">📋 Browse Jobs</a>
            <a href="register.php"    class="button button-primary">Sign up to Post a Job</a>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="cm-empty">
            No open jobs right now.
            <?php if ($user): ?>
            <a href="request.php" style="color:var(--primary,#0f766e);font-weight:700;">Be the first to post →</a>
            <?php else: ?>
            <a href="register.php" style="color:var(--primary,#0f766e);font-weight:700;">Sign up &amp; post a job →</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Upcoming Events -->
    <div class="cm-section">
        <div class="cm-section-head">
            <h2>Upcoming Events</h2>
            <a href="events.php">View all →</a>
        </div>
        <?php if ($upcomingEvents): ?>
        <div class="cm-ev-row">
            <?php foreach ($upcomingEvents as $ev): ?>
            <a href="event.php?slug=<?php echo urlencode($ev['slug']); ?>" class="cm-ev-card">
                <div class="cm-ev-img">
                    <?php if ($ev['featured_image']): ?>
                        <img src="<?php echo sanitize($ev['featured_image']); ?>" alt="">
                    <?php else: ?>
                        <span class="cm-ev-img-icon">📅</span>
                    <?php endif; ?>
                    <div class="cm-ev-date-badge"><?php echo date('d M', strtotime($ev['start_date'])); ?></div>
                </div>
                <div class="cm-ev-body">
                    <div class="cm-ev-title"><?php echo sanitize($ev['title']); ?></div>
                    <?php if ($ev['venue']): ?><div class="cm-ev-meta">📍 <?php echo sanitize(mb_substr($ev['venue'],0,40)); ?></div><?php endif; ?>
                    <?php if ($ev['start_time']): ?><div class="cm-ev-meta">🕐 <?php echo date('g:i A', strtotime($ev['start_time'])); ?></div><?php endif; ?>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="cm-empty">No upcoming events at the moment.</div>
        <?php endif; ?>
    </div>

    <!-- Recent Funeral Announcements -->
    <div class="cm-section">
        <div class="cm-section-head">
            <h2>Funeral Announcements</h2>
            <a href="funerals.php">View all →</a>
        </div>
        <?php if ($recentFunerals): ?>
        <div class="cm-fa-row">
            <?php foreach ($recentFunerals as $fa): ?>
            <a href="funeral.php?slug=<?php echo urlencode($fa['slug']); ?>" class="cm-fa-card">
                <div class="cm-fa-img">
                    <?php if ($fa['photograph']): ?>
                        <img src="<?php echo sanitize($fa['photograph']); ?>" alt="">
                    <?php else: ?>
                        <span class="cm-fa-initials"><?php echo mb_strtoupper(mb_substr($fa['deceased_name'],0,2)); ?></span>
                    <?php endif; ?>
                </div>
                <div class="cm-fa-info">
                    <div class="cm-fa-name"><?php echo sanitize($fa['deceased_name']); ?></div>
                    <?php if ($fa['burial_date']): ?><div class="cm-fa-meta">⚰️ <?php echo date('d M Y', strtotime($fa['burial_date'])); ?></div><?php endif; ?>
                    <?php if ($fa['venue']): ?><div class="cm-fa-meta">📍 <?php echo sanitize(mb_substr($fa['venue'],0,40)); ?></div><?php endif; ?>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="cm-empty">No funeral announcements yet.</div>
        <?php endif; ?>
    </div>

    <!-- Latest News -->
    <?php if ($latestNews): ?>
    <div class="cm-section">
        <div class="cm-section-head">
            <h2>Latest News</h2>
            <a href="news.php">View all →</a>
        </div>
        <div class="cm-news-row">
            <?php foreach ($latestNews as $n): ?>
            <a href="news_article.php?slug=<?php echo urlencode($n['slug']); ?>" class="cm-news-card">
                <div class="cm-news-img">
                    <?php if ($n['featured_image']): ?>
                        <img src="<?php echo sanitize($n['featured_image']); ?>" alt="">
                    <?php else: ?>
                        <span class="cm-news-img-icon">📰</span>
                    <?php endif; ?>
                </div>
                <div class="cm-news-body">
                    <div class="cm-news-title"><?php echo sanitize($n['title']); ?></div>
                    <?php $excerpt = mb_substr(strip_tags($n['content'] ?? ''), 0, 120); ?>
                    <?php if ($excerpt): ?><p class="cm-news-excerpt"><?php echo sanitize($excerpt); ?></p><?php endif; ?>
                    <div class="cm-news-footer">
                        <div class="cm-news-meta"><?php echo $n['published_at'] ? date('d M Y', strtotime($n['published_at'])) : ''; ?></div>
                        <span class="cm-news-read">Read →</span>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Community CTAs -->
    <div class="cm-cta-row" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div class="cm-cta" style="background:linear-gradient(135deg,#1e3a5f,#0f2040);">
            <div>
                <h3>🔧 Jobs &amp; Services</h3>
                <p>Find skilled workers or post a service request</p>
            </div>
            <div style="display:flex;flex-direction:column;gap:8px;align-items:flex-start;">
                <?php if ($user): ?>
                <a href="jobs.php"    class="button button-secondary" style="background:rgba(255,255,255,.12);border-color:rgba(255,255,255,.25);color:#fff;">Browse Jobs</a>
                <a href="request.php" class="button button-primary">Post a Job</a>
                <?php else: ?>
                <a href="browse_jobs.php" class="button button-secondary" style="background:rgba(255,255,255,.12);border-color:rgba(255,255,255,.25);color:#fff;">Browse Jobs</a>
                <a href="register.php"    class="button button-primary">Sign up</a>
                <?php endif; ?>
            </div>
        </div>
        <div class="cm-cta">
            <div>
                <h3>🕊️ Post Funeral Announcement</h3>
                <p>Share memorial information with the community</p>
            </div>
            <?php if ($user): ?>
            <a href="my_funerals.php" class="button button-primary">Post Announcement</a>
            <?php else: ?>
            <a href="register.php" class="button button-primary">Sign up to Post</a>
            <?php endif; ?>
        </div>
        <div class="cm-cta" style="background:linear-gradient(135deg,#1a3a20,#14532d);">
            <div>
                <h3>📅 Submit a Community Event</h3>
                <p>Share your event with the <?php echo APP_NAME; ?> community</p>
            </div>
            <?php if ($user): ?>
            <a href="my_events.php" class="button button-primary">Submit Event</a>
            <?php else: ?>
            <a href="register.php" class="button button-primary">Sign up to Post</a>
            <?php endif; ?>
        </div>
        <div class="cm-cta" style="background:linear-gradient(135deg,#14532d,#166534);">
            <div>
                <h3>✍️ Submit a News Article</h3>
                <p>Share a story or update with the community</p>
            </div>
            <?php if ($user): ?>
            <a href="my_news.php" class="button button-primary">Submit Article</a>
            <?php else: ?>
            <a href="register.php" class="button button-primary">Sign up to Post</a>
            <?php endif; ?>
        </div>
    </div>

</div>

<footer style="text-align:center;padding:20px 16px <?php echo $user ? '80px' : '32px'; ?>;font-size:.8rem;color:#6b7280;border-top:1px solid #e5e7eb;margin-top:8px;">
    &copy; <?php echo date('Y'); ?> <?php echo sanitize(APP_NAME); ?> &nbsp;·&nbsp;
    <a href="about.php"   style="color:#6b7280;">About</a> &nbsp;·&nbsp;
    <a href="support.php" style="color:#6b7280;">Support</a> &nbsp;·&nbsp;
    <a href="terms.php"   style="color:#6b7280;">Terms</a> &nbsp;·&nbsp;
    <a href="privacy.php" style="color:#6b7280;">Privacy</a> &nbsp;·&nbsp;
    <a href="contact.php" style="color:#6b7280;">Contact</a>
</footer>

<?php if ($user): $activeNav = 'community'; require __DIR__ . '/partials/bottom_nav.php'; endif; ?>
</body>
</html>
