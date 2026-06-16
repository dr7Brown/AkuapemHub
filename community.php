<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

$user  = current_user();
$today = date('Y-m-d');

$upcomingEvents = $pdo->query(
    "SELECT * FROM events WHERE status='published' AND start_date >= '$today'
     ORDER BY featured DESC, start_date ASC LIMIT 4"
)->fetchAll();

$recentFunerals = $pdo->query(
    "SELECT * FROM funeral_announcements WHERE status='approved'
     ORDER BY featured DESC, created_at DESC LIMIT 4"
)->fetchAll();

$latestNews = $pdo->query(
    "SELECT * FROM news WHERE status='published'
     ORDER BY published_at DESC LIMIT 3"
)->fetchAll();

$openJobs = $pdo->query(
    "SELECT sr.id, sr.title, sr.budget_amount, sr.location, sr.created_at,
            c.name AS category
     FROM service_requests sr
     LEFT JOIN service_categories c ON sr.category_id = c.id
     WHERE sr.status = 'open'
     ORDER BY sr.featured DESC, sr.created_at DESC LIMIT 4"
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo APP_NAME; ?> — Community, Jobs &amp; Services</title>
    <meta name="description" content="<?php echo APP_NAME; ?> — Find skilled workers, post service requests, discover community events, news &amp; announcements in Ghana.">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .cm-hero { background:linear-gradient(135deg,#0f766e 0%,#065f46 100%); color:#fff; padding:40px 20px 36px; text-align:center; }
        .cm-hero h1 { font-size:clamp(1.5rem,5vw,2.2rem); font-weight:900; margin:0 0 8px; }
        .cm-hero p  { font-size:.95rem; color:#a7f3d0; margin:0; }

        .cm-modules { display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:14px; max-width:900px; margin:-28px auto 0; padding:0 16px; position:relative; z-index:2; }
        .cm-mod     { background:var(--surface,#fff); border:1px solid var(--border,#e5e7eb); border-radius:14px; padding:20px 16px; text-align:center; text-decoration:none; color:inherit; transition:box-shadow .15s,transform .15s; }
        .cm-mod:hover { box-shadow:0 6px 24px rgba(0,0,0,.1); transform:translateY(-3px); }
        .cm-mod-icon  { font-size:2rem; margin-bottom:8px; }
        .cm-mod-title { font-weight:800; font-size:.95rem; margin-bottom:3px; }
        .cm-mod-desc  { font-size:.75rem; color:var(--text-muted,#6b7280); }

        .cm-shell  { max-width:1060px; margin:0 auto; padding:36px 16px 60px; }
        .cm-section { margin-bottom:36px; }
        .cm-section-head { display:flex; align-items:center; justify-content:space-between; margin-bottom:14px; }
        .cm-section-head h2 { font-size:.78rem; font-weight:800; text-transform:uppercase; letter-spacing:.07em; color:var(--text-muted,#6b7280); margin:0; }
        .cm-section-head a  { font-size:.82rem; font-weight:700; color:var(--primary,#0f766e); text-decoration:none; }

        /* Events row */
        .cm-ev-row  { display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr)); gap:12px; }
        .cm-ev-card { background:var(--surface,#fff); border:1px solid var(--border,#e5e7eb); border-radius:12px; overflow:hidden; text-decoration:none; color:inherit; display:flex; flex-direction:column; transition:box-shadow .15s; }
        .cm-ev-card:hover { box-shadow:0 4px 16px rgba(0,0,0,.08); }
        .cm-ev-img  { aspect-ratio:16/9; background:#f3f4f6; display:flex; align-items:center; justify-content:center; overflow:hidden; }
        .cm-ev-img img { width:100%; height:100%; object-fit:cover; }
        .cm-ev-body { padding:10px 12px 12px; }
        .cm-ev-title { font-weight:700; font-size:.88rem; margin:0 0 4px; }
        .cm-ev-meta  { font-size:.76rem; color:var(--text-muted,#6b7280); }

        /* Funeral row */
        .cm-fa-row  { display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:12px; }
        .cm-fa-card { background:var(--surface,#fff); border:1px solid var(--border,#e5e7eb); border-radius:12px; overflow:hidden; text-decoration:none; color:inherit; display:flex; gap:0; transition:box-shadow .15s; }
        .cm-fa-card:hover { box-shadow:0 4px 16px rgba(0,0,0,.08); }
        .cm-fa-photo { width:70px; flex-shrink:0; background:#f3f4f6; display:flex; align-items:center; justify-content:center; overflow:hidden; }
        .cm-fa-photo img { width:70px; height:100%; min-height:70px; object-fit:cover; }
        .cm-fa-initials { font-size:1.4rem; font-weight:900; color:#d1d5db; }
        .cm-fa-info { padding:10px 12px; display:flex; flex-direction:column; gap:3px; }
        .cm-fa-name { font-weight:700; font-size:.88rem; }
        .cm-fa-meta { font-size:.74rem; color:var(--text-muted,#6b7280); }

        /* News row */
        .cm-news-row { display:grid; grid-template-columns:repeat(auto-fill,minmax(260px,1fr)); gap:12px; }
        .cm-news-card { background:var(--surface,#fff); border:1px solid var(--border,#e5e7eb); border-radius:12px; overflow:hidden; text-decoration:none; color:inherit; transition:box-shadow .15s; }
        .cm-news-card:hover { box-shadow:0 4px 16px rgba(0,0,0,.08); }
        .cm-news-img { aspect-ratio:16/7; background:#f3f4f6; overflow:hidden; display:flex; align-items:center; justify-content:center; }
        .cm-news-img img { width:100%; height:100%; object-fit:cover; }
        .cm-news-body { padding:10px 12px 12px; }
        .cm-news-title { font-weight:700; font-size:.88rem; margin:0 0 4px; }
        .cm-news-meta  { font-size:.74rem; color:var(--text-muted); }

        .cm-empty { text-align:center; color:var(--text-muted); font-size:.88rem; padding:24px; background:var(--surface,#fff); border:1px solid var(--border,#e5e7eb); border-radius:12px; }

        /* Job cards */
        .cm-job-row   { display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr)); gap:12px; }
        .cm-job-card  { background:var(--surface,#fff); border:1px solid var(--border,#e5e7eb); border-radius:12px; padding:14px; text-decoration:none; color:inherit; display:flex; flex-direction:column; gap:5px; transition:box-shadow .15s; }
        .cm-job-card:hover { box-shadow:0 4px 16px rgba(0,0,0,.08); }
        .cm-job-cat   { display:inline-block; font-size:.7rem; font-weight:700; padding:3px 8px; border-radius:20px; background:#f0fdf4; color:#065f46; align-self:flex-start; }
        .cm-job-title { font-weight:700; font-size:.9rem; line-height:1.35; }
        .cm-job-meta  { font-size:.75rem; color:var(--text-muted,#6b7280); }
        .cm-job-budget{ font-size:.82rem; font-weight:700; color:var(--primary,#0f766e); }

        /* Community CTAs */
        .cm-cta { background:linear-gradient(135deg,#1e293b,#0f172a); color:#fff; border-radius:16px; padding:24px 20px; display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap; }
        .cm-cta h3 { font-size:1rem; font-weight:800; margin:0 0 4px; }
        .cm-cta p  { font-size:.85rem; color:#94a3b8; margin:0; }
        .cm-cta a  { white-space:nowrap; }
        @media(max-width:760px){ .cm-cta-row { grid-template-columns:1fr !important; } }
    </style>
</head>
<body>
<?php if (!$user): ?>
<header style="background:var(--surface,#fff);border-bottom:1px solid var(--border,#e5e7eb);padding:12px 16px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
    <a href="community.php" style="font-weight:900;color:var(--primary,#0f766e);text-decoration:none;font-size:1.1rem;"><?php echo APP_NAME; ?></a>
    <nav style="display:flex;gap:12px;align-items:center;">
        <a href="find_workers.php" style="font-size:.85rem;color:var(--text-muted);text-decoration:none;font-weight:600;">Workers</a>
        <a href="events.php"       style="font-size:.85rem;color:var(--text-muted);text-decoration:none;font-weight:600;">Events</a>
        <a href="news.php"         style="font-size:.85rem;color:var(--text-muted);text-decoration:none;font-weight:600;">News</a>
        <a href="login.php"    class="button button-secondary button-small">Sign in</a>
        <a href="register.php" class="button button-primary button-small">Register</a>
    </nav>
</header>
<?php endif; ?>

<div class="cm-hero">
    <h1>Welcome to <?php echo APP_NAME; ?></h1>
    <p>Find workers, post jobs, explore events, news &amp; community announcements — all in one place for Ghana</p>
    <?php if (!$user): ?>
    <div style="display:flex;gap:10px;justify-content:center;margin-top:18px;flex-wrap:wrap;">
        <a href="register.php"    class="button button-primary">Create free account</a>
        <a href="find_workers.php" style="background:rgba(255,255,255,.15);color:#fff;padding:10px 20px;border-radius:10px;text-decoration:none;font-weight:700;font-size:.9rem;border:1px solid rgba(255,255,255,.3);">Browse Workers</a>
    </div>
    <?php endif; ?>
</div>

<!-- Module cards -->
<div class="cm-modules">
    <a href="find_workers.php" class="cm-mod"><div class="cm-mod-icon">🔧</div><div class="cm-mod-title">Jobs &amp; Services</div><div class="cm-mod-desc">Find workers &amp; post service requests</div></a>
    <a href="news.php"         class="cm-mod"><div class="cm-mod-icon">📰</div><div class="cm-mod-title">News &amp; Updates</div><div class="cm-mod-desc">Latest articles &amp; platform news</div></a>
    <a href="events.php"       class="cm-mod"><div class="cm-mod-icon">📅</div><div class="cm-mod-title">Events</div><div class="cm-mod-desc">Community events &amp; programs</div></a>
    <a href="funerals.php"     class="cm-mod"><div class="cm-mod-icon">🕊️</div><div class="cm-mod-title">Funeral Announcements</div><div class="cm-mod-desc">Memorial notices</div></a>
    <a href="news.php#ads"     class="cm-mod"><div class="cm-mod-icon">📣</div><div class="cm-mod-title">Advertisements</div><div class="cm-mod-desc">Sponsored posts &amp; promotions</div></a>
</div>

<div class="cm-shell">

    <!-- Open Jobs & Services -->
    <div class="cm-section">
        <div class="cm-section-head">
            <h2>Open Jobs &amp; Services</h2>
            <a href="find_workers.php">Find Workers →</a>
        </div>
        <?php if ($openJobs): ?>
        <div class="cm-job-row">
            <?php foreach ($openJobs as $job): ?>
            <a href="request_detail.php?id=<?php echo (int)$job['id']; ?>" class="cm-job-card">
                <?php if ($job['category']): ?><span class="cm-job-cat"><?php echo sanitize($job['category']); ?></span><?php endif; ?>
                <div class="cm-job-title"><?php echo sanitize($job['title']); ?></div>
                <?php if ($job['location']): ?><div class="cm-job-meta">📍 <?php echo sanitize(mb_substr($job['location'],0,40)); ?></div><?php endif; ?>
                <?php if ($job['budget_amount']): ?><div class="cm-job-budget">GH₵ <?php echo number_format((float)$job['budget_amount'],2); ?></div><?php endif; ?>
                <div class="cm-job-meta">🕐 <?php echo date('d M Y', strtotime($job['created_at'])); ?></div>
            </a>
            <?php endforeach; ?>
        </div>
        <div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap;">
            <a href="find_workers.php" class="button button-secondary">🔍 Find Workers</a>
            <?php if ($user): ?>
            <a href="request.php" class="button button-primary">➕ Post a Job</a>
            <?php else: ?>
            <a href="register.php" class="button button-primary">Sign up to Post a Job</a>
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
                    <?php if ($ev['featured_image']): ?><img src="<?php echo sanitize($ev['featured_image']); ?>" alt=""><?php else: ?><span style="font-size:2rem;">📅</span><?php endif; ?>
                </div>
                <div class="cm-ev-body">
                    <div class="cm-ev-title"><?php echo sanitize($ev['title']); ?></div>
                    <div class="cm-ev-meta">📅 <?php echo date('d M Y', strtotime($ev['start_date'])); ?><?php if ($ev['venue']): ?> · <?php echo sanitize(mb_substr($ev['venue'],0,40)); ?><?php endif; ?></div>
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
                <div class="cm-fa-photo">
                    <?php if ($fa['photograph']): ?><img src="<?php echo sanitize($fa['photograph']); ?>" alt=""><?php else: ?><span class="cm-fa-initials"><?php echo mb_strtoupper(mb_substr($fa['deceased_name'],0,2)); ?></span><?php endif; ?>
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
                <?php if ($n['featured_image']): ?>
                <div class="cm-news-img"><img src="<?php echo sanitize($n['featured_image']); ?>" alt=""></div>
                <?php endif; ?>
                <div class="cm-news-body">
                    <div class="cm-news-title"><?php echo sanitize($n['title']); ?></div>
                    <div class="cm-news-meta"><?php echo $n['published_at'] ? date('d M Y', strtotime($n['published_at'])) : ''; ?></div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Community CTAs (2×2 grid, 1-col on mobile) -->
    <div class="cm-cta-row" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div class="cm-cta" style="background:linear-gradient(135deg,#1e3a5f,#0f2040);">
            <div>
                <h3>🔧 Jobs &amp; Services</h3>
                <p>Find skilled workers or post a service request</p>
            </div>
            <div style="display:flex;flex-direction:column;gap:8px;align-items:flex-start;">
                <a href="find_workers.php" class="button button-secondary" style="background:rgba(255,255,255,.12);border-color:rgba(255,255,255,.25);color:#fff;">Find Workers</a>
                <?php if ($user): ?>
                <a href="request.php" class="button button-primary">Post a Job</a>
                <?php else: ?>
                <a href="register.php" class="button button-primary">Sign up</a>
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

<footer style="text-align:center;padding:20px 16px 32px;font-size:.8rem;color:#6b7280;border-top:1px solid #e5e7eb;margin-top:0;">
    &copy; <?php echo date('Y'); ?> <?php echo APP_NAME; ?> &nbsp;·&nbsp;
    <a href="find_workers.php" style="color:#0f766e;">Find Workers</a> &nbsp;·&nbsp;
    <a href="leaderboard.php"  style="color:#0f766e;">Leaderboard</a> &nbsp;·&nbsp;
    <a href="contact.php"      style="color:#0f766e;">Contact</a> &nbsp;·&nbsp;
    <a href="privacy.php"      style="color:#0f766e;">Privacy Policy</a> &nbsp;·&nbsp;
    <a href="terms.php"        style="color:#0f766e;">Terms of Service</a>
</footer>

<?php if ($user): $activeNav = 'community'; require_once __DIR__ . '/partials/bottom_nav.php'; endif; ?>
</body>
</html>
