<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

$user = current_user();
$slug = trim($_GET['slug'] ?? '');

if (!$slug) { header('Location: funerals.php'); exit; }

$stmt = $pdo->prepare("SELECT * FROM funeral_announcements WHERE slug=? AND status='approved' LIMIT 1");
$stmt->execute([$slug]);
$fa = $stmt->fetch();
if (!$fa) { header('Location: funerals.php'); exit; }

// Track view (once per session)
if (empty($_SESSION['viewed_funeral'][$fa['id']])) {
    $pdo->prepare("UPDATE funeral_announcements SET view_count=view_count+1 WHERE id=?")->execute([$fa['id']]);
    $_SESSION['viewed_funeral'][$fa['id']] = true;
}

$pageUrl   = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
$shareText = 'Funeral announcement: ' . $fa['deceased_name'] . ' — ' . APP_NAME;

function fmt_date($d, $format = 'D, j M Y, g:i A') {
    return $d ? date($format, strtotime($d)) : '—';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>In Memoriam: <?php echo sanitize($fa['deceased_name']); ?> — <?php echo APP_NAME; ?></title>
    <meta name="description" content="<?php echo sanitize(mb_substr($fa['biography'] ?? 'Funeral announcement from ' . APP_NAME, 0, 160)); ?>">
    <meta property="og:title"       content="In Memoriam: <?php echo sanitize($fa['deceased_name']); ?>">
    <meta property="og:description" content="<?php echo sanitize(mb_substr($fa['biography'] ?? '', 0, 200)); ?>">
    <?php if ($fa['photograph']): ?><meta property="og:image" content="<?php echo sanitize($fa['photograph']); ?>"><?php endif; ?>
    <meta property="og:url" content="<?php echo sanitize($pageUrl); ?>">
    <meta name="twitter:card" content="summary_large_image">
    <link rel="canonical" href="<?php echo sanitize($pageUrl); ?>">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .fd-wrap   { max-width:760px; margin:0 auto; padding:24px 16px 60px; }
        .fd-back   { display:inline-flex; align-items:center; gap:6px; color:var(--text-muted,#6b7280); text-decoration:none; font-size:.85rem; font-weight:600; margin-bottom:20px; }
        .fd-back:hover { color:var(--primary,#0f766e); }

        /* Hero */
        .fd-hero   { display:flex; gap:20px; align-items:flex-start; margin-bottom:28px; flex-wrap:wrap; }
        .fd-photo  { width:160px; height:160px; border-radius:14px; object-fit:cover; flex-shrink:0; background:#f3f4f6; display:flex; align-items:center; justify-content:center; overflow:hidden; border:3px solid var(--border,#e5e7eb); }
        .fd-photo img { width:100%; height:100%; object-fit:cover; }
        .fd-photo-initials { font-size:3rem; font-weight:900; color:#d1d5db; }
        .fd-hero-info { flex:1; min-width:200px; }
        .fd-hero-name { font-size:clamp(1.4rem,4vw,1.9rem); font-weight:900; margin:0 0 4px; }
        .fd-hero-sub  { color:var(--text-muted,#6b7280); font-size:.9rem; margin:0 0 10px; }
        .fd-badge-featured { display:inline-block; background:#f59e0b; color:#fff; font-size:.68rem; font-weight:800; padding:3px 10px; border-radius:20px; letter-spacing:.04em; margin-bottom:8px; }

        /* Dates grid */
        .fd-dates  { display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:10px; margin-bottom:24px; }
        .fd-date-card { background:var(--surface,#fff); border:1px solid var(--border,#e5e7eb); border-radius:10px; padding:12px 14px; }
        .fd-date-label { font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:var(--text-muted,#6b7280); margin-bottom:3px; }
        .fd-date-val   { font-size:.9rem; font-weight:700; color:var(--text,#111); }

        /* Poster */
        .fd-poster { margin:20px 0; text-align:center; }
        .fd-poster img { max-width:100%; border-radius:12px; box-shadow:0 4px 20px rgba(0,0,0,.12); }
        .fd-poster-label { font-size:.78rem; color:var(--text-muted); margin-top:6px; }

        /* Biography */
        .fd-section { margin-bottom:24px; }
        .fd-section h2 { font-size:.78rem; font-weight:800; text-transform:uppercase; letter-spacing:.06em; color:var(--text-muted,#6b7280); margin:0 0 10px; }
        .fd-bio    { background:var(--surface,#fff); border:1px solid var(--border,#e5e7eb); border-radius:12px; padding:18px; line-height:1.7; font-size:.95rem; white-space:pre-wrap; }

        /* Organizer */
        .fd-org    { background:var(--surface,#fff); border:1px solid var(--border,#e5e7eb); border-radius:12px; padding:16px 18px; }
        .fd-org-name  { font-weight:800; margin-bottom:4px; }
        .fd-org-links { display:flex; gap:12px; flex-wrap:wrap; margin-top:8px; }
        .fd-org-links a { font-size:.85rem; color:var(--primary,#0f766e); text-decoration:none; font-weight:600; }

        /* Share */
        .fd-share  { display:flex; gap:10px; flex-wrap:wrap; margin-top:24px; align-items:center; }
        .fd-share span { font-size:.82rem; font-weight:700; color:var(--text-muted); }
        .fd-share a { display:inline-flex; align-items:center; gap:5px; padding:8px 14px; border-radius:10px; font-size:.82rem; font-weight:700; text-decoration:none; color:#fff; }
        .fd-share-wa { background:#25D366; }
        .fd-share-fb { background:#1877F2; }
        .fd-share-tw { background:#1DA1F2; }
        .fd-share-copy { background:var(--surface,#fff); color:var(--text,#111); border:1px solid var(--border,#e5e7eb); cursor:pointer; }
        .fd-views { font-size:.78rem; color:var(--text-muted); margin-top:10px; }

        @media(max-width:480px) { .fd-photo { width:110px; height:110px; } }
    </style>
</head>
<body>
<?php if (!$user): ?>
<header style="background:var(--surface,#fff);border-bottom:1px solid var(--border,#e5e7eb);padding:12px 16px;display:flex;align-items:center;justify-content:space-between;gap:12px;">
    <a href="index.php" style="font-weight:900;color:var(--primary,#0f766e);text-decoration:none;font-size:1.1rem;"><?php echo APP_NAME; ?></a>
    <div style="display:flex;gap:8px;">
        <a href="funerals.php" style="font-size:.85rem;color:var(--text-muted);text-decoration:none;font-weight:600;">Announcements</a>
        <a href="login.php"    class="button button-secondary button-small">Sign in</a>
        <a href="register.php" class="button button-primary button-small">Register</a>
    </div>
</header>
<?php endif; ?>

<div class="fd-wrap">
    <a href="funerals.php" class="fd-back">← Back to Announcements</a>

    <?php if ($fa['featured']): ?><span class="fd-badge-featured">⭐ Featured</span><br><br><?php endif; ?>

    <!-- Hero -->
    <div class="fd-hero">
        <div class="fd-photo">
            <?php if ($fa['photograph']): ?>
                <img src="<?php echo sanitize($fa['photograph']); ?>" alt="<?php echo sanitize($fa['deceased_name']); ?>">
            <?php else: ?>
                <span class="fd-photo-initials"><?php echo mb_strtoupper(mb_substr($fa['deceased_name'],0,2)); ?></span>
            <?php endif; ?>
        </div>
        <div class="fd-hero-info">
            <h1 class="fd-hero-name"><?php echo sanitize($fa['deceased_name']); ?></h1>
            <p class="fd-hero-sub">
                <?php echo ucfirst($fa['gender']); ?>
                <?php if ($fa['age']): ?> &nbsp;·&nbsp; Age <?php echo (int)$fa['age']; ?><?php endif; ?>
                <?php if ($fa['date_of_birth']): ?> &nbsp;·&nbsp; Born <?php echo date('d M Y', strtotime($fa['date_of_birth'])); ?><?php endif; ?>
                <?php if ($fa['date_of_death']): ?> &nbsp;·&nbsp; Died <?php echo date('d M Y', strtotime($fa['date_of_death'])); ?><?php endif; ?>
            </p>
            <?php if ($fa['venue']): ?>
            <p style="font-size:.88rem;color:var(--text-muted);margin:0;">📍 <?php echo sanitize($fa['venue']); ?></p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Key dates -->
    <?php $hasDates = $fa['wake_keeping_date'] || $fa['burial_date'] || $fa['thanksgiving_date']; ?>
    <?php if ($hasDates): ?>
    <div class="fd-section">
        <h2>Key Dates &amp; Times</h2>
        <div class="fd-dates">
            <?php if ($fa['wake_keeping_date']): ?>
            <div class="fd-date-card">
                <div class="fd-date-label">Wake Keeping</div>
                <div class="fd-date-val"><?php echo fmt_date($fa['wake_keeping_date']); ?></div>
            </div>
            <?php endif; ?>
            <?php if ($fa['burial_date']): ?>
            <div class="fd-date-card" style="border-color:#dc2626;">
                <div class="fd-date-label" style="color:#dc2626;">Burial</div>
                <div class="fd-date-val"><?php echo fmt_date($fa['burial_date']); ?></div>
            </div>
            <?php endif; ?>
            <?php if ($fa['thanksgiving_date']): ?>
            <div class="fd-date-card">
                <div class="fd-date-label">Thanksgiving Service</div>
                <div class="fd-date-val"><?php echo fmt_date($fa['thanksgiving_date']); ?></div>
            </div>
            <?php endif; ?>
            <?php if ($fa['gps_address']): ?>
            <div class="fd-date-card">
                <div class="fd-date-label">GPS Address</div>
                <div class="fd-date-val"><?php echo sanitize($fa['gps_address']); ?></div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($fa['google_maps_link']): ?>
    <div class="fd-section">
        <h2>Location &amp; Directions</h2>
        <a href="<?php echo sanitize($fa['google_maps_link']); ?>" target="_blank" rel="noopener"
           class="button button-secondary" style="display:inline-flex;align-items:center;gap:6px;">
            📍 Open in Google Maps
        </a>
    </div>
    <?php endif; ?>

    <!-- Funeral Poster -->
    <?php if ($fa['funeral_poster']): ?>
    <div class="fd-poster">
        <img src="<?php echo sanitize($fa['funeral_poster']); ?>" alt="Funeral poster for <?php echo sanitize($fa['deceased_name']); ?>">
        <p class="fd-poster-label">Funeral Programme / Poster</p>
    </div>
    <?php endif; ?>

    <!-- Biography -->
    <?php if ($fa['biography']): ?>
    <div class="fd-section">
        <h2>Biography</h2>
        <div class="fd-bio rich-content"><?php echo render_rich($fa['biography']); ?></div>
    </div>
    <?php endif; ?>

    <!-- Organizer -->
    <?php if ($fa['organizer_name']): ?>
    <div class="fd-section">
        <h2>Contact / Organizer</h2>
        <div class="fd-org">
            <div class="fd-org-name"><?php echo sanitize($fa['organizer_name']); ?></div>
            <div class="fd-org-links">
                <?php if ($fa['organizer_phone']): ?>
                <a href="tel:<?php echo sanitize($fa['organizer_phone']); ?>">📞 <?php echo sanitize($fa['organizer_phone']); ?></a>
                <?php endif; ?>
                <?php if ($fa['organizer_email']): ?>
                <a href="mailto:<?php echo sanitize($fa['organizer_email']); ?>">✉️ <?php echo sanitize($fa['organizer_email']); ?></a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Share -->
    <div class="fd-share">
        <span>Share:</span>
        <a href="https://wa.me/?text=<?php echo urlencode($shareText . ' ' . $pageUrl); ?>" target="_blank" rel="noopener" class="fd-share-wa">📱 WhatsApp</a>
        <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo urlencode($pageUrl); ?>" target="_blank" rel="noopener" class="fd-share-fb">Facebook</a>
        <a href="https://twitter.com/intent/tweet?text=<?php echo urlencode($shareText); ?>&url=<?php echo urlencode($pageUrl); ?>" target="_blank" rel="noopener" class="fd-share-tw">Twitter</a>
        <button class="fd-share fd-share-copy" onclick="navigator.clipboard.writeText('<?php echo addslashes($pageUrl); ?>').then(function(){this.textContent='Copied!';}.bind(this))">🔗 Copy Link</button>
    </div>
    <p class="fd-views">👁️ <?php echo number_format((int)$fa['view_count']); ?> view<?php echo $fa['view_count'] !== 1 ? 's' : ''; ?></p>
</div>

<?php if ($user): require_once __DIR__ . '/partials/bottom_nav.php'; endif; ?>
</body>
</html>
