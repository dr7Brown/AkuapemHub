<?php
/**
 * Presenter profile page — public, no login required, reached from a
 * programme page or the station page. Mirrors worker_profile_public.php's
 * general hero-then-sections shape, but slug-based like the rest of the
 * FM module rather than that page's ?id= convention.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/fm_functions.php';

require_module_enabled('fm', 'FM Stations');
$user = current_user();

$stationSlug = trim($_GET['station'] ?? '');
$presenterSlug = trim($_GET['slug'] ?? '');
$station = $stationSlug !== '' ? fm_get_station($stationSlug) : null;

if (!$station || $station['status'] !== 'active') {
    render_not_found('fm_stations.php', 'Browse FM Stations', 'This station is no longer available.');
}

$presenter = $presenterSlug !== '' ? fm_get_presenter((int)$station['id'], $presenterSlug) : null;
if (!$presenter || $presenter['status'] !== 'active') {
    render_not_found('fm_station.php?slug=' . urlencode($station['slug']), 'Back to ' . $station['name'], 'This presenter profile is no longer available.');
}

$programmes = fm_get_presenter_programmes((int)$presenter['id']);
$shades = fm_color_shades($station['theme_color'] ?? null);

$shareTitle = $presenter['name'] . ' — ' . $station['name'];
$shareUrl   = rtrim(BASE_URL, '/') . '/fm_presenter.php?station=' . $station['slug'] . '&slug=' . $presenter['slug'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php echo seo_meta([
        'title'       => $presenter['name'] . ' — ' . $station['name'] . ' | ' . APP_NAME,
        'description' => trim(strip_tags($presenter['bio'] ?? '')) ?: ($presenter['name'] . ', presenter at ' . $station['name'] . '.'),
        'image'       => $presenter['photo_path'] ?: ($station['logo_path'] ?? null),
        'url'         => $shareUrl,
        'type'        => 'profile',
    ]); ?>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .fmr-shell { max-width:680px; margin:0 auto; padding:16px 16px 80px; }
        @keyframes fmrFadeUp { from { opacity:0; transform:translateY(14px); } to { opacity:1; transform:translateY(0); } }
        .fmr-fade { animation:fmrFadeUp .5s ease both; }
        .fmr-hero { background:linear-gradient(135deg,var(--fm-base),var(--fm-dark)); border-radius:var(--radius-lg,20px); padding:28px 20px; color:#fff; text-align:center; margin-bottom:18px; }
        .fmr-photo { width:108px; height:108px; border-radius:50%; object-fit:cover; margin:0 auto 14px; border:4px solid rgba(255,255,255,.85); box-shadow:0 10px 26px rgba(0,0,0,.28); }
        .fmr-photo-fallback { width:108px; height:108px; border-radius:50%; margin:0 auto 14px; background:rgba(255,255,255,.16); display:flex; align-items:center; justify-content:center; font-size:2.6rem; }
        .fmr-hero h1 { font-size:1.5rem; font-weight:900; margin:0 0 6px; }
        .fmr-hero-station { font-size:.85rem; opacity:.9; text-decoration:none; color:#fff; }
        .fmr-hero-station:hover { text-decoration:underline; }
        .fmr-panel { background:var(--surface,#fff); border:1px solid var(--border,#e5e7eb); border-radius:var(--radius-lg,20px); padding:18px; margin-bottom:16px; box-shadow:var(--shadow-sm,0 2px 8px rgba(15,23,42,.05)); }
        .fmr-section-title { font-size:.78rem; font-weight:800; text-transform:uppercase; letter-spacing:.06em; color:var(--muted,#6b7280); margin:0 0 12px; }
        .fmr-prog-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(140px,1fr)); gap:12px; }
        .fmr-prog-card { text-decoration:none; color:inherit; background:var(--surface-muted,#f8faf9); border-radius:14px; padding:12px; display:flex; align-items:center; gap:10px; transition:transform .15s; }
        .fmr-prog-card:hover { transform:translateY(-2px); }
        .fmr-prog-img { width:40px; height:40px; border-radius:10px; object-fit:cover; flex-shrink:0; }
        .fmr-prog-img-fallback { width:40px; height:40px; border-radius:10px; background:var(--fm-soft); color:var(--fm-dark); display:flex; align-items:center; justify-content:center; font-size:1rem; flex-shrink:0; }
        .fmr-prog-name { font-weight:700; font-size:.84rem; }
        .fmr-social { display:flex; gap:10px; }
        .fmr-social a { display:inline-flex; align-items:center; justify-content:center; width:42px; height:42px; border-radius:50%; background:#1877F2; color:#fff; text-decoration:none; }
    </style>
</head>
<body class="<?php echo $user ? 'has-bottom-nav' : ''; ?>" style="--fm-base:<?php echo $shades['base']; ?>; --fm-dark:<?php echo $shades['dark']; ?>; --fm-soft:<?php echo $shades['soft']; ?>; --fm-rgb:<?php echo $shades['rgb']; ?>;">

<header class="app-topbar">
    <a href="fm_station.php?slug=<?php echo urlencode($station['slug']); ?>" class="button button-secondary button-small">‹ <?php echo sanitize($station['name']); ?></a>
    <span class="brand"><?php echo sanitize($presenter['name']); ?></span>
</header>

<main class="fmr-shell">
    <div class="fmr-hero fmr-fade">
        <?php if (!empty($presenter['photo_path'])): ?>
        <img src="<?php echo sanitize($presenter['photo_path']); ?>" alt="" class="fmr-photo">
        <?php else: ?>
        <div class="fmr-photo-fallback">🎙️</div>
        <?php endif; ?>
        <h1><?php echo sanitize($presenter['name']); ?></h1>
        <a href="fm_station.php?slug=<?php echo urlencode($station['slug']); ?>" class="fmr-hero-station">📻 <?php echo sanitize($station['name']); ?></a>
    </div>

    <?php if (!empty($presenter['bio'])): ?>
    <div class="fmr-panel fmr-fade" style="animation-delay:.05s;">
        <p class="fmr-section-title">ℹ️ About</p>
        <div class="rich-content" style="line-height:1.7;"><?php echo render_rich($presenter['bio']); ?></div>
    </div>
    <?php endif; ?>

    <?php if ($programmes): ?>
    <div class="fmr-panel fmr-fade" style="animation-delay:.1s;">
        <p class="fmr-section-title">🎙️ Programmes</p>
        <div class="fmr-prog-grid">
            <?php foreach ($programmes as $pg): ?>
            <a href="fm_programme.php?station=<?php echo urlencode($station['slug']); ?>&slug=<?php echo urlencode($pg['slug']); ?>" class="fmr-prog-card">
                <?php if (!empty($pg['image_path'])): ?>
                <img src="<?php echo sanitize($pg['image_path']); ?>" alt="" class="fmr-prog-img">
                <?php else: ?>
                <span class="fmr-prog-img-fallback">🎙️</span>
                <?php endif; ?>
                <span class="fmr-prog-name"><?php echo sanitize($pg['name']); ?></span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($presenter['facebook_url'])): ?>
    <div class="fmr-panel fmr-fade" style="animation-delay:.15s;">
        <p class="fmr-section-title">🔗 Connect</p>
        <div class="fmr-social">
            <a href="<?php echo sanitize($presenter['facebook_url']); ?>" target="_blank" rel="noopener" title="Facebook" aria-label="Facebook">
                <svg viewBox="0 0 24 24" width="17" height="17" fill="#fff"><path d="M9.101 23.691v-7.98H6.627v-3.667h2.474v-1.58c0-4.085 1.848-5.978 5.858-5.978.401 0 .955.042 1.468.103a8.68 8.68 0 0 1 1.141.195v3.325a8.623 8.623 0 0 0-.653-.036 26.805 26.805 0 0 0-.732-.009c-.954 0-1.639.267-2.05.68-.412.415-.622 1.16-.622 2.269v1.03h3.884l-.505 3.667h-3.379v7.98H9.101z"/></svg>
            </a>
        </div>
    </div>
    <?php endif; ?>

    <div class="fmr-panel fmr-fade" style="animation-delay:.2s;">
        <?php require __DIR__ . '/partials/share_buttons.php'; ?>
    </div>
</main>

<?php require __DIR__ . '/partials/site_footer.php'; ?>
<?php if ($user): require_once __DIR__ . '/partials/bottom_nav.php'; endif; ?>
</body>
</html>
