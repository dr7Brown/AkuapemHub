<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

$user = current_user();
$slug = trim($_GET['slug'] ?? '');

if (!$slug) { header('Location: events.php'); exit; }

$stmt = $pdo->prepare("SELECT * FROM events WHERE slug=? AND status='published' LIMIT 1");
$stmt->execute([$slug]);
$ev = $stmt->fetch();
if (!$ev) { header('Location: events.php'); exit; }

if (empty($_SESSION['viewed_event'][$ev['id']])) {
    $pdo->prepare("UPDATE events SET view_count=view_count+1 WHERE id=?")->execute([$ev['id']]);
    $_SESSION['viewed_event'][$ev['id']] = true;
}

$pageUrl   = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
$shareText = $ev['title'] . ' — ' . APP_NAME;
$today     = date('Y-m-d');
$isPast    = $ev['start_date'] < $today;
$isCancelled = $ev['status'] === 'cancelled';

// Sidebar data
$sbEvents    = $pdo->prepare("SELECT title, slug, start_date, start_time, featured_image FROM events WHERE status='published' AND start_date >= ? AND id != ? ORDER BY featured DESC, start_date ASC LIMIT 5");
$sbEvents->execute([$today, $ev['id']]);
$sbEvents    = $sbEvents->fetchAll();
$sbFunerals  = $pdo->query("SELECT deceased_name, slug, burial_date, venue FROM funeral_announcements WHERE status='approved' ORDER BY created_at DESC LIMIT 4")->fetchAll();
$sbNews      = $pdo->query("SELECT title, slug, published_at FROM news WHERE status='published' ORDER BY COALESCE(published_at,created_at) DESC LIMIT 4")->fetchAll();
$sbAd        = $pdo->query("SELECT * FROM advertisements WHERE status='active' AND ad_type='banner' AND (start_date IS NULL OR start_date<=CURDATE()) AND (end_date IS NULL OR end_date>=CURDATE()) ORDER BY RAND() LIMIT 1")->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo sanitize($ev['title']); ?> — <?php echo APP_NAME; ?></title>
    <meta name="description" content="<?php echo sanitize(mb_substr(strip_tags($ev['description'] ?? ''), 0, 160)); ?>">
    <meta property="og:title"       content="<?php echo sanitize($ev['title']); ?>">
    <meta property="og:description" content="<?php echo sanitize(mb_substr(strip_tags($ev['description'] ?? ''), 0, 200)); ?>">
    <?php if ($ev['featured_image']): ?><meta property="og:image" content="<?php echo sanitize($ev['featured_image']); ?>"><?php endif; ?>
    <meta property="og:url" content="<?php echo sanitize($pageUrl); ?>">
    <meta name="twitter:card" content="summary_large_image">
    <link rel="canonical" href="<?php echo sanitize($pageUrl); ?>">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .ed-wrap   { max-width:1200px; margin:0 auto; padding:24px 16px 60px; }
        .ed-back   { display:inline-flex; align-items:center; gap:6px; color:var(--text-muted,#6b7280); text-decoration:none; font-size:.85rem; font-weight:600; margin-bottom:20px; }
        .ed-back:hover { color:var(--primary,#0f766e); }

        /* Two-column layout */
        .ed-layout { display:block; }
        .ed-sidebar { display:none; }
        @media(min-width:900px){
            .ed-layout  { display:grid; grid-template-columns:1fr 280px; gap:28px; align-items:start; }
            .ed-sidebar { display:flex; flex-direction:column; gap:20px; position:sticky; top:16px; }
        }

        .ed-status-bar { display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-bottom:16px; }
        .ed-badge { display:inline-block; font-size:.7rem; font-weight:800; padding:4px 10px; border-radius:20px; letter-spacing:.04em; }
        .ed-badge-featured   { background:#2563eb; color:#fff; }
        .ed-badge-upcoming   { background:#d1fae5; color:#065f46; }
        .ed-badge-past       { background:#f3f4f6; color:#6b7280; }
        .ed-badge-cancelled  { background:#fee2e2; color:#991b1b; }
        .ed-badge-free       { background:#d1fae5; color:#065f46; }
        .ed-badge-paid       { background:#fef3c7; color:#92400e; }
        .ed-badge-registration { background:#e0e7ff; color:#3730a3; }

        .ed-hero-img { width:100%; max-height:380px; object-fit:cover; border-radius:16px; margin-bottom:20px; }
        .ed-title    { font-size:clamp(1.4rem,4vw,2rem); font-weight:900; margin:0 0 16px; }

        .ed-info-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:12px; margin-bottom:24px; }
        .ed-info-card { background:var(--surface,#fff); border:1px solid var(--border,#e5e7eb); border-radius:10px; padding:12px 14px; }
        .ed-info-label { font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:var(--text-muted,#6b7280); margin-bottom:3px; }
        .ed-info-val   { font-size:.9rem; font-weight:700; }

        .ed-desc   { background:var(--surface,#fff); border:1px solid var(--border,#e5e7eb); border-radius:12px; padding:18px; line-height:1.8; font-size:.95rem; margin-bottom:22px; }

        .ed-ticket { background:linear-gradient(135deg,#1e3a5f,#0f2040); color:#fff; border-radius:14px; padding:20px 22px; margin-bottom:22px; display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap; }
        .ed-ticket-info { }
        .ed-ticket-type { font-size:.75rem; font-weight:800; text-transform:uppercase; letter-spacing:.06em; color:#93c5fd; margin-bottom:4px; }
        .ed-ticket-price { font-size:1.5rem; font-weight:900; }
        .ed-ticket-note  { font-size:.8rem; color:#93c5fd; margin-top:4px; }
        .ed-register-btn { background:#2563eb; color:#fff; padding:12px 22px; border-radius:10px; text-decoration:none; font-weight:800; font-size:.9rem; }

        .ed-share  { display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
        .ed-share span { font-size:.82rem; font-weight:700; color:var(--text-muted); }
        .ed-share a, .ed-share button { display:inline-flex; align-items:center; gap:5px; padding:8px 14px; border-radius:10px; font-size:.82rem; font-weight:700; text-decoration:none; color:#fff; border:none; cursor:pointer; }
        .ed-share-wa { background:#25D366; }
        .ed-share-fb { background:#1877F2; }
        .ed-share-tw { background:#1DA1F2; }
        .ed-share-copy { background:var(--surface,#fff); color:var(--text,#111); border:1px solid var(--border,#e5e7eb) !important; }
        .ed-views  { font-size:.78rem; color:var(--text-muted); margin-top:10px; }

        .ed-section h2 { font-size:.78rem; font-weight:800; text-transform:uppercase; letter-spacing:.06em; color:var(--text-muted,#6b7280); margin:0 0 10px; }

        /* Sidebar widgets */
        .nsb-widget { background:var(--surface,#fff); border:1px solid var(--border,#e5e7eb); border-radius:14px; overflow:hidden; }
        .nsb-head   { padding:12px 16px; font-size:.72rem; font-weight:800; text-transform:uppercase; letter-spacing:.06em; color:var(--primary,#0f766e); border-bottom:1px solid var(--border,#e5e7eb); background:#f0fdf4; }
        .nsb-list   { list-style:none; margin:0; padding:0; }
        .nsb-item   { display:flex; align-items:flex-start; gap:10px; padding:10px 14px; border-bottom:1px solid var(--border,#e5e7eb); }
        .nsb-item:last-child { border-bottom:none; }
        .nsb-num    { font-size:.72rem; font-weight:900; color:var(--primary,#0f766e); min-width:18px; padding-top:2px; }
        .nsb-text   { flex:1; min-width:0; }
        .nsb-text a { font-size:.83rem; font-weight:700; color:var(--text,#111); text-decoration:none; display:block; line-height:1.35; }
        .nsb-text a:hover { color:var(--primary,#0f766e); }
        .nsb-meta   { font-size:.72rem; color:var(--muted,#6b7280); margin-top:2px; }
        .nsb-event  { display:flex; align-items:flex-start; gap:10px; padding:10px 14px; border-bottom:1px solid var(--border,#e5e7eb); }
        .nsb-event:last-child { border-bottom:none; }
        .nsb-event-date { background:var(--primary,#0f766e); color:#fff; border-radius:8px; text-align:center; padding:4px 8px; min-width:42px; }
        .nsb-event-date .nsb-day { font-size:1rem; font-weight:900; line-height:1; }
        .nsb-event-date .nsb-mon { font-size:.6rem; font-weight:700; text-transform:uppercase; }
        .nsb-event-info a { font-size:.83rem; font-weight:700; color:var(--text,#111); text-decoration:none; display:block; line-height:1.35; }
        .nsb-event-info a:hover { color:var(--primary,#0f766e); }
        .nsb-event-info .nsb-meta { margin-top:2px; }
        .nsb-funeral      { display:flex; align-items:flex-start; gap:10px; padding:10px 14px; border-bottom:1px solid var(--border,#e5e7eb); }
        .nsb-funeral:last-child { border-bottom:none; }
        .nsb-funeral-icon { font-size:1.3rem; padding-top:1px; }
        .nsb-funeral-name { font-size:.83rem; font-weight:700; color:var(--text,#111); }
        .nsb-funeral-name a { color:inherit; text-decoration:none; }
        .nsb-funeral-name a:hover { color:var(--primary,#0f766e); }
        .nsb-funeral-meta { font-size:.72rem; color:var(--muted,#6b7280); margin-top:2px; }
        .nsb-cta  { padding:16px; text-align:center; }
        .nsb-cta p { font-size:.82rem; color:var(--muted,#6b7280); margin:0 0 10px; line-height:1.4; }
        .nsb-cta a { display:block; background:var(--primary,#0f766e); color:#fff; padding:10px; border-radius:10px; font-weight:800; font-size:.85rem; text-decoration:none; }
        .nsb-cta a:hover { opacity:.9; }
        .nsb-ad { padding:12px; }
        .nsb-ad img { width:100%; border-radius:8px; display:block; }
        .nsb-ad a { display:block; }
    </style>
</head>
<body>
<?php if (!$user): ?>
<header style="background:var(--surface,#fff);border-bottom:1px solid var(--border,#e5e7eb);padding:12px 16px;display:flex;align-items:center;justify-content:space-between;gap:12px;">
    <a href="index.php" style="font-weight:900;color:var(--primary,#0f766e);text-decoration:none;font-size:1.1rem;"><?php echo APP_NAME; ?></a>
    <div style="display:flex;gap:8px;">
        <a href="events.php"   style="font-size:.85rem;color:var(--text-muted);text-decoration:none;font-weight:600;">Events</a>
        <a href="login.php"    class="button button-secondary button-small">Sign in</a>
    </div>
</header>
<?php endif; ?>

<div class="ed-wrap">
    <a href="events.php" class="ed-back">← Back to Events</a>

    <div class="ed-layout">
    <div class="ed-main">

    <?php if ($ev['featured_image']): ?>
    <img src="<?php echo sanitize($ev['featured_image']); ?>" alt="<?php echo sanitize($ev['title']); ?>" class="ed-hero-img">
    <?php endif; ?>

    <div class="ed-status-bar">
        <?php if ($ev['featured']): ?><span class="ed-badge ed-badge-featured">⭐ Featured</span><?php endif; ?>
        <?php if ($isCancelled): ?><span class="ed-badge ed-badge-cancelled">Cancelled</span>
        <?php elseif ($isPast): ?><span class="ed-badge ed-badge-past">Past Event</span>
        <?php else: ?><span class="ed-badge ed-badge-upcoming">Upcoming</span><?php endif; ?>
        <span class="ed-badge ed-badge-<?php echo $ev['ticket_type']; ?>"><?php echo $ev['ticket_type'] === 'paid' ? '🎟️ Paid Entry' : ($ev['ticket_type'] === 'registration' ? '📝 Registration Required' : 'Free Entry'); ?></span>
    </div>

    <h1 class="ed-title"><?php echo sanitize($ev['title']); ?></h1>

    <!-- Info grid -->
    <div class="ed-info-grid">
        <div class="ed-info-card">
            <div class="ed-info-label">Date</div>
            <div class="ed-info-val">
                <?php echo date('D, d M Y', strtotime($ev['start_date'])); ?>
                <?php if ($ev['end_date'] && $ev['end_date'] !== $ev['start_date']): ?>
                <br><small style="font-weight:500;color:var(--text-muted);">to <?php echo date('D, d M Y', strtotime($ev['end_date'])); ?></small>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($ev['start_time']): ?>
        <div class="ed-info-card">
            <div class="ed-info-label">Time</div>
            <div class="ed-info-val"><?php echo date('g:i A', strtotime($ev['start_time'])); ?><?php if ($ev['end_time']): ?> – <?php echo date('g:i A', strtotime($ev['end_time'])); ?><?php endif; ?></div>
        </div>
        <?php endif; ?>
        <?php if ($ev['venue']): ?>
        <div class="ed-info-card">
            <div class="ed-info-label">Venue</div>
            <div class="ed-info-val"><?php echo sanitize($ev['venue']); ?></div>
        </div>
        <?php endif; ?>
        <?php if ($ev['gps_address']): ?>
        <div class="ed-info-card">
            <div class="ed-info-label">GPS Address</div>
            <div class="ed-info-val"><?php echo sanitize($ev['gps_address']); ?></div>
        </div>
        <?php endif; ?>
        <?php if ($ev['organizer_name']): ?>
        <div class="ed-info-card">
            <div class="ed-info-label">Organizer</div>
            <div class="ed-info-val"><?php echo sanitize($ev['organizer_name']); ?></div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Description -->
    <?php if ($ev['description']): ?>
    <div class="ed-section" style="margin-bottom:20px;">
        <h2>About this Event</h2>
        <div class="ed-desc rich-content"><?php echo render_rich($ev['description']); ?></div>
    </div>
    <?php endif; ?>

    <?php if ($ev['google_maps_link']): ?>
    <div class="ed-section" style="margin-bottom:20px;">
        <a href="<?php echo sanitize($ev['google_maps_link']); ?>" target="_blank" rel="noopener"
           class="button button-secondary" style="display:inline-flex;align-items:center;gap:6px;">
            📍 Open in Google Maps
        </a>
    </div>
    <?php endif; ?>

    <!-- Ticket / Registration -->
    <?php if ($ev['ticket_type'] !== 'free' || $ev['registration_link']): ?>
    <div class="ed-ticket">
        <div class="ed-ticket-info">
            <div class="ed-ticket-type"><?php echo $ev['ticket_type'] === 'paid' ? 'Paid Entry' : 'Registration Required'; ?></div>
            <?php if ($ev['ticket_type'] === 'paid' && $ev['ticket_price'] > 0): ?>
            <div class="ed-ticket-price">GH₵ <?php echo number_format((float)$ev['ticket_price'], 2); ?></div>
            <?php endif; ?>
            <?php if ($ev['ticket_type'] === 'registration'): ?>
            <div class="ed-ticket-note">Complete the registration form to attend this event.</div>
            <?php endif; ?>
        </div>
        <?php if ($ev['registration_link'] && !$isPast && !$isCancelled): ?>
        <a href="<?php echo sanitize($ev['registration_link']); ?>" target="_blank" rel="noopener" class="ed-register-btn">
            <?php echo $ev['ticket_type'] === 'paid' ? 'Get Tickets →' : 'Register Now →'; ?>
        </a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Share -->
    <div class="ed-share">
        <span>Share:</span>
        <a href="https://wa.me/?text=<?php echo urlencode($shareText . ' ' . $pageUrl); ?>" target="_blank" rel="noopener" class="ed-share-wa">📱 WhatsApp</a>
        <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo urlencode($pageUrl); ?>" target="_blank" rel="noopener" class="ed-share-fb">Facebook</a>
        <a href="https://twitter.com/intent/tweet?text=<?php echo urlencode($shareText); ?>&url=<?php echo urlencode($pageUrl); ?>" target="_blank" rel="noopener" class="ed-share-tw">Twitter</a>
        <button class="ed-share ed-share-copy" onclick="navigator.clipboard.writeText('<?php echo addslashes($pageUrl); ?>').then(function(){this.textContent='Copied!';}.bind(this))">🔗 Copy Link</button>
    </div>
    <p class="ed-views">👁️ <?php echo number_format((int)$ev['view_count']); ?> view<?php echo $ev['view_count'] !== 1 ? 's' : ''; ?></p>

    </div><!-- /.ed-main -->

    <aside class="ed-sidebar">

        <?php if ($sbEvents): ?>
        <div class="nsb-widget">
            <div class="nsb-head">📅 More Upcoming Events</div>
            <ul class="nsb-list">
            <?php foreach ($sbEvents as $se): ?>
            <li class="nsb-event">
                <div class="nsb-event-date">
                    <div class="nsb-day"><?php echo date('d', strtotime($se['start_date'])); ?></div>
                    <div class="nsb-mon"><?php echo date('M', strtotime($se['start_date'])); ?></div>
                </div>
                <div class="nsb-event-info">
                    <a href="event.php?slug=<?php echo urlencode($se['slug']); ?>"><?php echo sanitize($se['title']); ?></a>
                    <?php if ($se['start_time']): ?><div class="nsb-meta"><?php echo date('g:i A', strtotime($se['start_time'])); ?></div><?php endif; ?>
                </div>
            </li>
            <?php endforeach; ?>
            </ul>
            <div style="padding:8px 14px 12px;"><a href="events.php" style="font-size:.8rem;color:var(--primary,#0f766e);font-weight:700;text-decoration:none;">View all events →</a></div>
        </div>
        <?php endif; ?>

        <?php if ($sbNews): ?>
        <div class="nsb-widget">
            <div class="nsb-head">📰 Latest News</div>
            <ul class="nsb-list">
            <?php foreach ($sbNews as $i => $sn): ?>
            <li class="nsb-item">
                <span class="nsb-num"><?php echo $i + 1; ?></span>
                <div class="nsb-text">
                    <a href="news_article.php?slug=<?php echo urlencode($sn['slug']); ?>"><?php echo sanitize($sn['title']); ?></a>
                    <?php if ($sn['published_at']): ?><div class="nsb-meta"><?php echo date('d M Y', strtotime($sn['published_at'])); ?></div><?php endif; ?>
                </div>
            </li>
            <?php endforeach; ?>
            </ul>
            <div style="padding:8px 14px 12px;"><a href="news.php" style="font-size:.8rem;color:var(--primary,#0f766e);font-weight:700;text-decoration:none;">All news →</a></div>
        </div>
        <?php endif; ?>

        <?php if ($sbFunerals): ?>
        <div class="nsb-widget">
            <div class="nsb-head">🕊️ Recent Funeral Notices</div>
            <ul class="nsb-list">
            <?php foreach ($sbFunerals as $sf): ?>
            <li class="nsb-funeral">
                <div class="nsb-funeral-icon">🕊️</div>
                <div>
                    <div class="nsb-funeral-name"><a href="funeral.php?slug=<?php echo urlencode($sf['slug']); ?>"><?php echo sanitize($sf['deceased_name']); ?></a></div>
                    <div class="nsb-funeral-meta">
                        <?php if ($sf['burial_date']): ?>Burial: <?php echo date('d M Y', strtotime($sf['burial_date'])); ?><?php endif; ?>
                        <?php if ($sf['venue']): ?><br><?php echo sanitize($sf['venue']); ?><?php endif; ?>
                    </div>
                </div>
            </li>
            <?php endforeach; ?>
            </ul>
            <div style="padding:8px 14px 12px;"><a href="funerals.php" style="font-size:.8rem;color:var(--primary,#0f766e);font-weight:700;text-decoration:none;">View all notices →</a></div>
        </div>
        <?php endif; ?>

        <?php if ($sbAd): ?>
        <div class="nsb-widget">
            <div class="nsb-head">Sponsored</div>
            <div class="nsb-ad">
                <?php if ($sbAd['image_url']): ?>
                <a href="<?php echo sanitize($sbAd['target_url'] ?? '#'); ?>" target="_blank" rel="noopener sponsored">
                    <img src="<?php echo sanitize($sbAd['image_url']); ?>" alt="<?php echo sanitize($sbAd['title']); ?>">
                </a>
                <?php else: ?>
                <a href="<?php echo sanitize($sbAd['target_url'] ?? '#'); ?>" target="_blank" rel="noopener sponsored"
                   style="display:block;background:var(--primary,#0f766e);color:#fff;text-align:center;padding:20px 12px;border-radius:8px;font-weight:800;text-decoration:none;">
                    <?php echo sanitize($sbAd['title']); ?>
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="nsb-widget">
            <div class="nsb-cta">
                <p>Have an event to share with the community?</p>
                <a href="<?php echo $user ? 'my_events.php' : 'login.php'; ?>">Submit an Event</a>
            </div>
        </div>

    </aside><!-- /.ed-sidebar -->
    </div><!-- /.ed-layout -->
</div>

<?php if ($user): require_once __DIR__ . '/partials/bottom_nav.php'; endif; ?>
</body>
</html>
