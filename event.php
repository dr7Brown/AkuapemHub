<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_module_enabled('events', 'Events');

$user = current_user();
$slug = trim($_GET['slug'] ?? '');

if (!$slug) { header('Location: events.php'); exit; }

$stmt = $pdo->prepare("SELECT * FROM events WHERE slug=? AND status='published' AND (user_id IS NULL OR user_id NOT IN (SELECT id FROM users WHERE banned=1)) LIMIT 1");
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
$sbEvents    = $pdo->prepare("SELECT title, slug, start_date, start_time, featured_image FROM events WHERE status='published' AND start_date >= ? AND id != ? ORDER BY (featured=1 AND (featured_end_date IS NULL OR featured_end_date>=CURDATE())) DESC, start_date ASC LIMIT 5");
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
    <?php if ($ev['featured_image']): ?><meta property="og:image" content="<?php echo sanitize(rtrim(BASE_URL,'/') . '/' . ltrim($ev['featured_image'],'/')); ?>"><?php endif; ?>
    <meta property="og:url" content="<?php echo sanitize($pageUrl); ?>">
    <meta name="twitter:card" content="summary_large_image">
    <link rel="canonical" href="<?php echo sanitize($pageUrl); ?>">
    <?php if (!$isCancelled): ?>
    <script type="application/ld+json">
    <?php
    $evStartIso = $ev['start_date'] . ($ev['start_time'] ? 'T' . $ev['start_time'] : 'T00:00:00');
    $evEndIso   = ($ev['end_date'] ?: $ev['start_date']) . ($ev['end_time'] ? 'T' . $ev['end_time'] : '');
    $evLd = [
        '@context'    => 'https://schema.org',
        '@type'       => 'Event',
        'name'        => $ev['title'],
        'startDate'   => $evStartIso,
        'eventStatus' => 'https://schema.org/EventScheduled',
        'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
        'location'    => [
            '@type'   => 'Place',
            'name'    => $ev['venue'] ?: $ev['title'],
            'address' => [
                '@type'          => 'PostalAddress',
                'addressLocality'=> $ev['venue'] ?: 'Akuapem Area',
                'addressRegion'  => 'Eastern Region',
                'addressCountry' => 'GH',
            ],
        ],
        'description' => mb_substr(strip_tags($ev['description'] ?? ''), 0, 500),
        'organizer'   => [
            '@type' => 'Organization',
            'name'  => $ev['organizer_name'] ?: APP_NAME,
        ],
    ];
    if (!empty($ev['end_date'])) $evLd['endDate'] = $evEndIso;
    if (!empty($ev['featured_image'])) $evLd['image'] = [rtrim(BASE_URL,'/') . '/' . ltrim($ev['featured_image'],'/')];
    $evLd['offers'] = [
        '@type'         => 'Offer',
        'url'           => $pageUrl,
        'price'         => $ev['ticket_type'] === 'paid' ? (float)$ev['ticket_price'] : 0,
        'priceCurrency' => 'GHS',
        'availability'  => 'https://schema.org/InStock',
    ];
    echo json_encode($evLd, JSON_UNESCAPED_SLASHES);
    ?>
    </script>
    <?php endif; ?>
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

        @keyframes edFadeUp { from { opacity:0; transform:translateY(14px); } to { opacity:1; transform:translateY(0); } }
        .ed-fade { animation:edFadeUp .5s ease both; }

        /* ── Hero: full-bleed image with title/badges overlaid ── */
        .ed-hero {
            position:relative; border-radius:22px; overflow:hidden; margin-bottom:0;
            min-height:320px; display:flex; align-items:flex-end;
            background:linear-gradient(135deg,#1e3a5f,#0f2040);
        }
        .ed-hero-img { position:absolute; inset:0; width:100%; height:100%; object-fit:cover; }
        .ed-hero::after {
            content:''; position:absolute; inset:0;
            background:linear-gradient(180deg,rgba(15,32,64,.15) 0%,rgba(10,18,38,.55) 55%,rgba(8,14,28,.92) 100%);
        }
        .ed-hero-content { position:relative; z-index:1; padding:28px 28px 26px; color:#fff; width:100%; }
        .ed-title    { font-size:clamp(1.5rem,4vw,2.3rem); font-weight:900; margin:10px 0 0; letter-spacing:-.015em; line-height:1.15; text-shadow:0 2px 12px rgba(0,0,0,.25); }

        .ed-status-bar { display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-bottom:0; }
        .ed-badge { display:inline-block; font-size:.7rem; font-weight:800; padding:5px 11px; border-radius:20px; letter-spacing:.04em; -webkit-backdrop-filter:blur(6px); backdrop-filter:blur(6px); }
        .ed-badge-featured   { background:rgba(37,99,235,.9); color:#fff; }
        .ed-badge-upcoming   { background:rgba(209,250,229,.92); color:#065f46; }
        .ed-badge-past       { background:rgba(243,244,246,.85); color:#374151; }
        .ed-badge-cancelled  { background:rgba(254,226,226,.92); color:#991b1b; }
        .ed-badge-free       { background:rgba(209,250,229,.92); color:#065f46; }
        .ed-badge-paid       { background:rgba(254,243,199,.92); color:#92400e; }
        .ed-badge-registration { background:rgba(224,231,255,.92); color:#3730a3; }

        /* ── Floating glass info bar, overlapping the hero bottom edge ── */
        .ed-info-bar {
            position:relative; z-index:2; margin:-28px 14px 26px; padding:18px 20px;
            background:var(--surface,#fff); border:1px solid var(--border,#eef0f2); border-radius:16px;
            box-shadow:0 14px 34px -12px rgba(15,23,42,.18);
            display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:16px;
        }
        @media(min-width:640px) { .ed-info-bar { margin:-30px 24px 28px; } }
        .ed-info-item { display:flex; gap:10px; align-items:flex-start; }
        .ed-info-icon { font-size:1.15rem; line-height:1.3; flex-shrink:0; }
        .ed-info-label { font-size:.68rem; font-weight:800; text-transform:uppercase; letter-spacing:.06em; color:var(--text-muted,#6b7280); margin-bottom:2px; }
        .ed-info-val   { font-size:.9rem; font-weight:700; line-height:1.35; }

        .ed-desc   { background:var(--surface,#fff); border:1px solid var(--border,#e5e7eb); border-radius:16px; padding:22px 24px; line-height:1.85; font-size:.95rem; margin-bottom:22px; }

        .ed-ticket {
            position:relative; overflow:hidden; color:#fff; border-radius:18px; padding:24px 26px; margin-bottom:22px;
            display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap;
            background:linear-gradient(135deg,#0f2040 0%,#1e3a5f 55%,#0b4a6f 100%);
        }
        .ed-ticket::before {
            content:''; position:absolute; inset:0; pointer-events:none;
            background:radial-gradient(circle at 90% 10%, rgba(56,189,248,.3), transparent 55%);
        }
        .ed-ticket-info { position:relative; z-index:1; }
        .ed-ticket-type { font-size:.75rem; font-weight:800; text-transform:uppercase; letter-spacing:.06em; color:#93c5fd; margin-bottom:4px; }
        .ed-ticket-price { font-size:1.6rem; font-weight:900; }
        .ed-ticket-note  { font-size:.8rem; color:#93c5fd; margin-top:4px; }
        .ed-register-btn { position:relative; z-index:1; background:#2563eb; color:#fff; padding:13px 24px; border-radius:12px; text-decoration:none; font-weight:800; font-size:.9rem; box-shadow:0 8px 22px rgba(37,99,235,.4); transition:transform .15s ease; }
        .ed-register-btn:hover { transform:translateY(-2px); }

        .ed-share  { display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
        .ed-share span { font-size:.82rem; font-weight:700; color:var(--text-muted); }
        .ed-share a, .ed-share button {
            display:inline-flex; align-items:center; justify-content:center; gap:6px; width:42px; height:42px; padding:0;
            border-radius:50%; font-size:.82rem; font-weight:700; text-decoration:none; color:#fff; border:none; cursor:pointer;
            transition:transform .15s ease, box-shadow .15s ease;
        }
        .ed-share a:hover, .ed-share button:hover { transform:translateY(-2px); box-shadow:0 6px 16px rgba(0,0,0,.2); }
        .ed-share-wa { background:#25D366; }
        .ed-share-fb { background:#1877F2; }
        .ed-share-tw { background:#000; }
        .ed-share-copy { background:var(--surface,#fff); color:var(--text,#111); border:1px solid var(--border,#e5e7eb) !important; width:auto !important; padding:0 16px !important; border-radius:21px !important; }
        .ed-views  { font-size:.78rem; color:var(--text-muted); margin-top:12px; }

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
    <a href="index.php" style="font-weight:900;color:var(--primary,#0f766e);text-decoration:none;font-size:1.1rem;">← Back Home</a>
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

    <div class="ed-hero ed-fade">
        <?php if ($ev['featured_image']): ?>
        <img src="<?php echo sanitize($ev['featured_image']); ?>" alt="<?php echo sanitize($ev['title']); ?>" class="ed-hero-img">
        <?php endif; ?>
        <div class="ed-hero-content">
            <div class="ed-status-bar">
                <?php if ($ev['featured']): ?><span class="ed-badge ed-badge-featured">⭐ Featured</span><?php endif; ?>
                <?php if ($isCancelled): ?><span class="ed-badge ed-badge-cancelled">Cancelled</span>
                <?php elseif ($isPast): ?><span class="ed-badge ed-badge-past">Past Event</span>
                <?php else: ?><span class="ed-badge ed-badge-upcoming">Upcoming</span><?php endif; ?>
                <span class="ed-badge ed-badge-<?php echo $ev['ticket_type']; ?>"><?php echo $ev['ticket_type'] === 'paid' ? '🎟️ Paid Entry' : ($ev['ticket_type'] === 'registration' ? '📝 Registration Required' : 'Free Entry'); ?></span>
            </div>
            <h1 class="ed-title"><?php echo sanitize($ev['title']); ?></h1>
        </div>
    </div>

    <!-- Floating info bar -->
    <div class="ed-info-bar ed-fade" style="animation-delay:.08s;">
        <div class="ed-info-item">
            <span class="ed-info-icon">📅</span>
            <div>
                <div class="ed-info-label">Date</div>
                <div class="ed-info-val">
                    <?php echo date('D, d M Y', strtotime($ev['start_date'])); ?>
                    <?php if ($ev['end_date'] && $ev['end_date'] !== $ev['start_date']): ?>
                    <br><small style="font-weight:500;color:var(--text-muted);">to <?php echo date('D, d M Y', strtotime($ev['end_date'])); ?></small>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php if ($ev['start_time']): ?>
        <div class="ed-info-item">
            <span class="ed-info-icon">🕐</span>
            <div>
                <div class="ed-info-label">Time</div>
                <div class="ed-info-val"><?php echo date('g:i A', strtotime($ev['start_time'])); ?><?php if ($ev['end_time']): ?> – <?php echo date('g:i A', strtotime($ev['end_time'])); ?><?php endif; ?></div>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($ev['venue']): ?>
        <div class="ed-info-item">
            <span class="ed-info-icon">📍</span>
            <div>
                <div class="ed-info-label">Venue</div>
                <div class="ed-info-val"><?php echo sanitize($ev['venue']); ?></div>
                <?php if ($ev['gps_address']): ?><div style="font-size:.78rem;color:var(--text-muted);margin-top:2px;"><?php echo sanitize($ev['gps_address']); ?></div><?php endif; ?>
                <?php if (!empty($ev['google_maps_link'])): ?>
                <a href="<?php echo sanitize($ev['google_maps_link']); ?>" target="_blank" rel="noopener" style="font-size:.78rem;font-weight:700;color:var(--primary,#0f766e);text-decoration:none;display:inline-block;margin-top:3px;">🗺 View on Maps ↗</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($ev['organizer_name']): ?>
        <div class="ed-info-item">
            <span class="ed-info-icon">👤</span>
            <div>
                <div class="ed-info-label">Organizer</div>
                <div class="ed-info-val"><?php echo sanitize($ev['organizer_name']); ?></div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Description -->
    <?php if ($ev['description']): ?>
    <div class="ed-section ed-fade" style="margin-bottom:20px;animation-delay:.14s;">
        <h2>About this Event</h2>
        <div class="ed-desc rich-content"><?php echo render_rich($ev['description']); ?></div>
    </div>
    <?php endif; ?>

    <!-- Ticket / Registration -->
    <?php if ($ev['ticket_type'] !== 'free' || $ev['registration_link']): ?>
    <div class="ed-ticket ed-fade">
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
        <a href="https://wa.me/?text=<?php echo urlencode($shareText . ' ' . $pageUrl); ?>" target="_blank" rel="noopener" class="ed-share-wa" title="Share on WhatsApp" aria-label="Share on WhatsApp">
            <svg viewBox="0 0 24 24" width="19" height="19" fill="#fff" xmlns="http://www.w3.org/2000/svg"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347M12.05 21.785h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884M20.463 3.488A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413"/></svg>
        </a>
        <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo urlencode($pageUrl); ?>" target="_blank" rel="noopener" class="ed-share-fb" title="Share on Facebook" aria-label="Share on Facebook">
            <svg viewBox="0 0 24 24" width="17" height="17" fill="#fff" xmlns="http://www.w3.org/2000/svg"><path d="M9.101 23.691v-7.98H6.627v-3.667h2.474v-1.58c0-4.085 1.848-5.978 5.858-5.978.401 0 .955.042 1.468.103a8.68 8.68 0 0 1 1.141.195v3.325a8.623 8.623 0 0 0-.653-.036 26.805 26.805 0 0 0-.732-.009c-.954 0-1.639.267-2.05.68-.412.415-.622 1.16-.622 2.269v1.03h3.884l-.505 3.667h-3.379v7.98H9.101z"/></svg>
        </a>
        <a href="https://twitter.com/intent/tweet?text=<?php echo urlencode($shareText); ?>&url=<?php echo urlencode($pageUrl); ?>" target="_blank" rel="noopener" class="ed-share-tw" title="Share on X (Twitter)" aria-label="Share on X (Twitter)">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="#fff" xmlns="http://www.w3.org/2000/svg"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
        </a>
        <button class="ed-share-copy" onclick="navigator.clipboard.writeText('<?php echo addslashes($pageUrl); ?>').then(function(){this.textContent='Copied!';}.bind(this))">🔗 Copy Link</button>
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
                <?php if ($sbAd['image']): ?>
                <a href="<?php echo sanitize($sbAd['destination_url'] ?? '#'); ?>" target="_blank" rel="noopener sponsored">
                    <img src="<?php echo sanitize($sbAd['image']); ?>" alt="<?php echo sanitize($sbAd['title']); ?>">
                </a>
                <?php else: ?>
                <a href="<?php echo sanitize($sbAd['destination_url'] ?? '#'); ?>" target="_blank" rel="noopener sponsored"
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
