<?php
/**
 * Programme-specific page — one page per named show at a station, covering
 * every day/time it airs (fm_get_programme() aggregates by shared slug).
 * Public, no login required, matching the rest of the FM module. Listener
 * reactions are session-deduped (no accounts), matching this module's
 * existing view-count dedup pattern.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/fm_functions.php';

require_module_enabled('fm', 'FM Stations');
$user = current_user();

$stationSlug = trim($_GET['station'] ?? '');
$progSlug    = trim($_GET['slug'] ?? '');
$station     = $stationSlug !== '' ? fm_get_station($stationSlug) : null;

if (!$station || $station['status'] !== 'active') {
    render_not_found('fm_stations.php', 'Browse FM Stations', 'This station is no longer available.');
}

$programme = $progSlug !== '' ? fm_get_programme((int)$station['id'], $progSlug) : null;
if (!$programme) {
    render_not_found('fm_station.php?slug=' . urlencode($station['slug']), 'Back to ' . $station['name'], 'This programme is no longer available.');
}

$presenter = $programme['presenter_id'] ? (function () use ($pdo, $programme) {
    $stmt = $pdo->prepare("SELECT * FROM fm_presenters WHERE id=? AND status='active' LIMIT 1");
    $stmt->execute([$programme['presenter_id']]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
})() : null;

$shades = fm_color_shades($station['theme_color'] ?? null);
$weekdayNames = get_weekday_names();

// Reaction tap — plain form POST + redirect (no accounts, so this stays a
// simple server round-trip rather than a new AJAX/JSON contract).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['react'])) {
    csrf_check();
    fm_record_reaction((int)$station['id'], $programme['slug'], (string)$_POST['react'], fm_session_key());
    header('Location: fm_programme.php?station=' . urlencode($station['slug']) . '&slug=' . urlencode($progSlug) . '#reactions');
    exit;
}
$reactionCounts = fm_get_reaction_counts((int)$station['id'], $programme['slug']);

$commentsStmt = $pdo->prepare(
    "SELECT c.*, u.name AS user_name, u.profile_photo FROM fm_programme_comments c
     JOIN users u ON u.id = c.user_id
     WHERE c.station_id = ? AND c.programme_slug = ? ORDER BY c.created_at ASC"
);
$commentsStmt->execute([(int)$station['id'], $programme['slug']]);
$comments = $commentsStmt->fetchAll(PDO::FETCH_ASSOC);

$shareTitle = $programme['name'] . ' — ' . $station['name'];
$shareText  = 'Check out ' . $programme['name'] . ' on ' . $station['name'] . ' via ' . APP_NAME . '.';
$shareUrl   = rtrim(BASE_URL, '/') . '/fm_programme.php?station=' . $station['slug'] . '&slug=' . $programme['slug'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php echo seo_meta([
        'title'       => $programme['name'] . ' — ' . $station['name'] . ' | ' . APP_NAME,
        'description' => trim(strip_tags($programme['description'] ?? '')) ?: ('Listen to ' . $programme['name'] . ' on ' . $station['name'] . '.'),
        'image'       => $programme['image_path'] ?: ($station['logo_path'] ?? null),
        'url'         => $shareUrl,
        'type'        => 'article',
    ]); ?>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .fmp-shell { max-width:680px; margin:0 auto; padding:16px 16px 80px; }
        @keyframes fmpFadeUp { from { opacity:0; transform:translateY(14px); } to { opacity:1; transform:translateY(0); } }
        .fmp-fade { animation:fmpFadeUp .5s ease both; }
        .fmp-hero { background:linear-gradient(135deg,var(--fm-base),var(--fm-dark)); border-radius:var(--radius-lg,20px); padding:24px 20px; color:#fff; text-align:center; margin-bottom:18px; }
        .fmp-hero-img { width:96px; height:96px; border-radius:22px; object-fit:cover; margin:0 auto 12px; border:3px solid rgba(255,255,255,.8); box-shadow:0 8px 22px rgba(0,0,0,.25); }
        .fmp-hero-img-fallback { width:96px; height:96px; border-radius:22px; margin:0 auto 12px; background:rgba(255,255,255,.16); display:flex; align-items:center; justify-content:center; font-size:2.2rem; }
        .fmp-hero h1 { font-size:1.4rem; font-weight:900; margin:0 0 6px; }
        .fmp-hero-station { font-size:.85rem; opacity:.9; text-decoration:none; color:#fff; }
        .fmp-hero-station:hover { text-decoration:underline; }
        .fmp-panel { background:var(--surface,#fff); border:1px solid var(--border,#e5e7eb); border-radius:var(--radius-lg,20px); padding:18px; margin-bottom:16px; box-shadow:var(--shadow-sm,0 2px 8px rgba(15,23,42,.05)); }
        .fmp-section-title { font-size:.78rem; font-weight:800; text-transform:uppercase; letter-spacing:.06em; color:var(--muted,#6b7280); margin:0 0 12px; }
        .fmp-presenter { display:flex; align-items:center; gap:12px; text-decoration:none; color:inherit; }
        .fmp-presenter-photo { width:52px; height:52px; border-radius:50%; object-fit:cover; }
        .fmp-presenter-photo-fallback { width:52px; height:52px; border-radius:50%; background:var(--fm-soft); color:var(--fm-dark); display:flex; align-items:center; justify-content:center; font-size:1.3rem; }
        .fmp-presenter-name { font-weight:800; font-size:.95rem; }
        .fmp-presenter-link { font-size:.78rem; color:var(--fm-base); }
        .fmp-slot-row { display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid var(--border,#e5e7eb); font-size:.86rem; }
        .fmp-slot-row:last-child { border-bottom:none; }
        .fmp-slot-day { font-weight:700; }
        .fmp-slot-time { color:var(--fm-base); font-weight:700; }
        .fmp-reactions { display:flex; gap:10px; flex-wrap:wrap; }
        .fmp-react-btn { display:flex; flex-direction:column; align-items:center; gap:4px; background:var(--surface-muted,#f1f7f3); border:1px solid var(--border,#e5e7eb); border-radius:14px; padding:10px 16px; cursor:pointer; font-size:1.3rem; transition:transform .15s,box-shadow .15s; }
        .fmp-react-btn:hover { transform:translateY(-2px); box-shadow:var(--shadow-sm,0 2px 8px rgba(15,23,42,.05)); }
        .fmp-react-count { font-size:.72rem; font-weight:700; color:var(--muted,#6b7280); }

        /* ── Comments — mirrors news_article.php's comment styling ── */
        .fmp-com-hd   { font-size:.78rem; font-weight:800; text-transform:uppercase; letter-spacing:.06em; color:var(--muted,#6b7280); margin:0 0 12px; }
        .fmp-comment  { display:flex; gap:12px; margin-bottom:16px; }
        .fmp-com-av   { width:36px; height:36px; border-radius:50%; background:var(--fm-base); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:800; font-size:.85rem; flex-shrink:0; overflow:hidden; }
        .fmp-com-av img { width:100%; height:100%; object-fit:cover; }
        .fmp-com-body { flex:1; min-width:0; background:var(--surface-muted,#f8faf9); border-radius:12px; padding:10px 13px; }
        .fmp-com-name { font-weight:700; font-size:.85rem; }
        .fmp-com-date { font-size:.72rem; color:var(--muted,#6b7280); margin-left:6px; }
        .fmp-com-text { font-size:.87rem; margin:5px 0 0; line-height:1.55; }
        .fmp-com-form textarea { width:100%; box-sizing:border-box; border-radius:12px; margin-bottom:10px; }
        .fmp-login-prompt { text-align:center; color:var(--muted,#6b7280); font-size:.86rem; padding:10px 0; }
        .fmp-login-prompt a { color:var(--fm-base); font-weight:700; }
    </style>
</head>
<body class="<?php echo $user ? 'has-bottom-nav' : ''; ?>" style="--fm-base:<?php echo $shades['base']; ?>; --fm-dark:<?php echo $shades['dark']; ?>; --fm-soft:<?php echo $shades['soft']; ?>; --fm-rgb:<?php echo $shades['rgb']; ?>;">

<header class="app-topbar">
    <a href="fm_station.php?slug=<?php echo urlencode($station['slug']); ?>" class="button button-secondary button-small">‹ <?php echo sanitize($station['name']); ?></a>
    <span class="brand"><?php echo sanitize($programme['name']); ?></span>
</header>

<main class="fmp-shell">
    <div class="fmp-hero fmp-fade">
        <?php if (!empty($programme['image_path'])): ?>
        <img src="<?php echo sanitize($programme['image_path']); ?>" alt="" class="fmp-hero-img">
        <?php else: ?>
        <div class="fmp-hero-img-fallback">🎙️</div>
        <?php endif; ?>
        <h1><?php echo sanitize($programme['name']); ?></h1>
        <a href="fm_station.php?slug=<?php echo urlencode($station['slug']); ?>" class="fmp-hero-station">📻 <?php echo sanitize($station['name']); ?></a>
    </div>

    <?php if ($presenter): ?>
    <div class="fmp-panel fmp-fade" style="animation-delay:.05s;">
        <p class="fmp-section-title">🎙️ Hosted By</p>
        <a href="fm_presenter.php?station=<?php echo urlencode($station['slug']); ?>&slug=<?php echo urlencode($presenter['slug']); ?>" class="fmp-presenter">
            <?php if (!empty($presenter['photo_path'])): ?>
            <img src="<?php echo sanitize($presenter['photo_path']); ?>" alt="" class="fmp-presenter-photo">
            <?php else: ?>
            <span class="fmp-presenter-photo-fallback">🎙️</span>
            <?php endif; ?>
            <span>
                <span class="fmp-presenter-name" style="display:block;"><?php echo sanitize($presenter['name']); ?></span>
                <span class="fmp-presenter-link">View profile →</span>
            </span>
        </a>
    </div>
    <?php elseif (!empty($programme['host'])): ?>
    <div class="fmp-panel fmp-fade" style="animation-delay:.05s;">
        <p class="fmp-section-title">🎙️ Hosted By</p>
        <p style="margin:0;font-weight:700;"><?php echo sanitize($programme['host']); ?></p>
    </div>
    <?php endif; ?>

    <?php if (!empty($programme['description'])): ?>
    <div class="fmp-panel fmp-fade" style="animation-delay:.1s;">
        <p class="fmp-section-title">ℹ️ About This Programme</p>
        <div class="rich-content" style="line-height:1.7;"><?php echo render_rich($programme['description']); ?></div>
    </div>
    <?php endif; ?>

    <div class="fmp-panel fmp-fade" style="animation-delay:.15s;">
        <p class="fmp-section-title">🗓️ Airs</p>
        <?php foreach ($programme['slots'] as $slot): ?>
        <div class="fmp-slot-row">
            <span class="fmp-slot-day"><?php echo sanitize($weekdayNames[$slot['day_of_week']]); ?></span>
            <span class="fmp-slot-time"><?php echo format_time_range($slot['start_time'], $slot['end_time']); ?></span>
        </div>
        <?php endforeach; ?>
    </div>

    <div id="reactions" class="fmp-panel fmp-fade" style="animation-delay:.2s;">
        <p class="fmp-section-title">💬 Listener Reactions</p>
        <div class="fmp-reactions">
            <?php foreach (fm_reaction_types() as $type => $emoji): ?>
            <form method="post" action="fm_programme.php?station=<?php echo urlencode($station['slug']); ?>&slug=<?php echo urlencode($progSlug); ?>#reactions">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="react" value="<?php echo sanitize($type); ?>">
                <button type="submit" class="fmp-react-btn">
                    <span><?php echo $emoji; ?></span>
                    <span class="fmp-react-count"><?php echo (int)$reactionCounts[$type]; ?></span>
                </button>
            </form>
            <?php endforeach; ?>
        </div>
    </div>

    <div id="comments" class="fmp-panel fmp-fade" style="animation-delay:.22s;">
        <p class="fmp-com-hd">💬 Comments (<span id="fmp-com-count"><?php echo count($comments); ?></span>)</p>
        <div id="fmp-comments-list">
            <?php foreach ($comments as $c): $cInitial = mb_strtoupper(mb_substr($c['user_name'], 0, 1)); ?>
            <div class="fmp-comment">
                <div class="fmp-com-av">
                    <?php if (!empty($c['profile_photo'])): ?><img src="<?php echo sanitize($c['profile_photo']); ?>" alt=""><?php else: ?><?php echo $cInitial; ?><?php endif; ?>
                </div>
                <div class="fmp-com-body">
                    <span class="fmp-com-name"><?php echo sanitize($c['user_name']); ?></span>
                    <span class="fmp-com-date"><?php echo date('M j, Y', strtotime($c['created_at'])); ?></span>
                    <p class="fmp-com-text"><?php echo nl2br(sanitize($c['comment'])); ?></p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if ($user): ?>
        <div class="fmp-com-form">
            <form id="fmp-comment-form" onsubmit="fmpPostComment(event)">
                <textarea id="fmp-comment-input" rows="3" placeholder="Share your thoughts on this programme…" required></textarea>
                <button type="submit" class="button button-primary" id="fmp-comment-btn">Post comment</button>
            </form>
        </div>
        <?php else: ?>
        <p class="fmp-login-prompt"><a href="login.php?redirect=<?php echo urlencode($_SERVER['REQUEST_URI']); ?>">Sign in</a> to join the conversation.</p>
        <?php endif; ?>
    </div>

    <div class="fmp-panel fmp-fade" style="animation-delay:.25s;">
        <?php require __DIR__ . '/partials/share_buttons.php'; ?>
    </div>
</main>

<?php if ($user): ?>
<script>
var fmpStationId = <?php echo (int)$station['id']; ?>;
var fmpProgSlug  = <?php echo json_encode($programme['slug']); ?>;
var fmpCsrfTok   = <?php echo json_encode(csrf_token()); ?>;

function fmpPostComment(e) {
    e.preventDefault();
    var inp = document.getElementById('fmp-comment-input');
    var btn = document.getElementById('fmp-comment-btn');
    var txt = inp.value.trim();
    if (!txt) return;
    btn.disabled = true; btn.textContent = 'Posting…';
    fetch('fm_ajax.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=comment&station_id=' + fmpStationId + '&programme_slug=' + encodeURIComponent(fmpProgSlug) +
              '&comment=' + encodeURIComponent(txt) + '&csrf_token=' + encodeURIComponent(fmpCsrfTok)
    }).then(function (r) { return r.json(); }).then(function (d) {
        if (d.error) { alert(d.error); }
        else {
            document.getElementById('fmp-comments-list').insertAdjacentHTML('beforeend', d.html);
            inp.value = '';
            var countEl = document.getElementById('fmp-com-count');
            countEl.textContent = (parseInt(countEl.textContent) || 0) + 1;
        }
    }).finally(function () { btn.disabled = false; btn.textContent = 'Post comment'; });
}
</script>
<?php endif; ?>

<?php require __DIR__ . '/partials/site_footer.php'; ?>
<?php if ($user): require_once __DIR__ . '/partials/bottom_nav.php'; endif; ?>
</body>
</html>
