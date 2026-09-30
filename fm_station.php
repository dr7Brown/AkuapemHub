<?php
/**
 * FM Station detail page — live player, on-air-now, and the weekly
 * programme schedule. Public, no login required (matches accommodation_
 * detail.php/funeral.php). The browser connects directly to the station's
 * own stream URL — this server never proxies or relays the audio.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/fm_functions.php';

require_module_enabled('fm', 'FM Stations');
$user = current_user();

$slug = trim($_GET['slug'] ?? '');
$station = $slug !== '' ? fm_get_station($slug) : null;

if (!$station || $station['status'] !== 'active') {
    render_not_found('fm_stations.php', 'Browse FM Stations', 'This station is no longer available.');
}

// View count, deduped per session per station (same pattern as shop.php).
$svKey = 'viewed_fm_station_' . $station['id'];
if (empty($_SESSION[$svKey])) {
    $pdo->prepare('UPDATE fm_stations SET view_count = view_count + 1 WHERE id = ?')->execute([$station['id']]);
    $_SESSION[$svKey] = true;
}

$shades = fm_color_shades($station['theme_color'] ?? null);

$isLive           = fm_station_is_live($station);
$currentProgramme = fm_get_current_programme((int)$station['id']);
$weeklySchedule   = fm_get_weekly_schedule((int)$station['id']);
$weekdayNames     = get_weekday_names();
$today            = (int)date('w');

$todayShowCount = count($weeklySchedule[$today] ?? []);
$nextProgramme  = fm_get_next_programme($weeklySchedule, $today);
$nextDayLabel   = null;
if ($nextProgramme) {
    $daysAhead = $nextProgramme['_days_ahead'];
    $nextDayLabel = $daysAhead === 0 ? 'Later today' : ($daysAhead === 1 ? 'Tomorrow' : $weekdayNames[($today + $daysAhead) % 7]);
}

// Purely cosmetic: how far into the current programme we are, for the
// progress bar under "On Air Now". Works across midnight-crossing slots too.
$onAirProgress = null;
if ($currentProgramme) {
    $toSec = fn($t) => (int)substr($t, 0, 2) * 3600 + (int)substr($t, 3, 2) * 60 + (int)substr($t, 6, 2);
    $startSec = $toSec($currentProgramme['start_time']);
    $endSec   = $toSec($currentProgramme['end_time']);
    $nowSec   = $toSec(date('H:i:s'));
    $duration = $endSec - $startSec; if ($duration <= 0) $duration += 86400;
    $elapsed  = $nowSec - $startSec; if ($elapsed < 0) $elapsed += 86400;
    $onAirProgress = $duration > 0 ? min(100, max(0, ($elapsed / $duration) * 100)) : 0;
}

$shareTitle = $station['name'];
$shareText  = 'Listen to ' . $station['name'] . ' on ' . APP_NAME . '.';
$shareUrl   = rtrim(BASE_URL, '/') . '/fm_station.php?slug=' . $station['slug'];

$announcements = fm_get_active_announcements((int)$station['id']);

$stationNews = $pdo->prepare(
    "SELECT id, title, slug, summary, featured_image, published_at FROM news
     WHERE fm_station_id = ? AND status = 'published' ORDER BY published_at DESC LIMIT 3"
);
$stationNews->execute([$station['id']]);
$stationNews = $stationNews->fetchAll(PDO::FETCH_ASSOC);

$otherStations = $pdo->prepare(
    "SELECT id, name, slug, logo_path, stream_url, live_mode FROM fm_stations WHERE status = 'active' AND id != ? ORDER BY RAND() LIMIT 6"
);
$otherStations->execute([$station['id']]);
$otherStations = $otherStations->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php echo seo_meta([
        'title'       => $station['name'] . ($station['frequency'] ? ' ' . $station['frequency'] : '') . ' — Listen Live | ' . APP_NAME,
        'description' => trim(strip_tags($station['description'] ?? '')) ?: ('Listen live to ' . $station['name'] . ($station['frequency'] ? ' (' . $station['frequency'] . ')' : '') . ($station['town_name'] ? ' from ' . $station['town_name'] : '') . ' on ' . APP_NAME . '.'),
        'image'       => $station['cover_path'] ?: $station['logo_path'] ?: null,
        'url'         => $shareUrl,
        'type'        => 'article',
    ]); ?>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .fms-shell { max-width:760px; margin:0 auto; padding:0 16px 80px; }

        @keyframes fmsFadeUp { from { opacity:0; transform:translateY(16px); } to { opacity:1; transform:translateY(0); } }
        .fms-fade { animation:fmsFadeUp .55s ease both; }

        /* ── Hero: full-bleed poster-style cover with identity overlaid at the
           bottom, mirroring event.php's .ed-hero immersive treatment. ── */
        .fms-hero {
            position:relative; overflow:hidden; margin:0 -16px; min-height:280px;
            display:flex; align-items:flex-end;
            background:linear-gradient(135deg,var(--fm-base),var(--fm-dark));
        }
        .fms-hero-img { position:absolute; inset:0; width:100%; height:100%; object-fit:cover; }
        .fms-hero::after {
            content:''; position:absolute; inset:0;
            background:linear-gradient(180deg,rgba(0,0,0,.05) 0%,rgba(0,0,0,.35) 55%,rgba(0,0,0,.82) 100%);
        }
        .fms-hero-badge {
            position:absolute; top:16px; right:16px; z-index:3; display:inline-flex; align-items:center; gap:6px;
            font-size:.72rem; font-weight:800; letter-spacing:.03em; padding:5px 11px; border-radius:20px;
            backdrop-filter:blur(6px);
        }
        .fms-hero-badge.on  { background:rgba(220,38,38,.85); color:#fff; }
        .fms-hero-badge.off { background:rgba(0,0,0,.4); color:#e5e7eb; }
        .fms-hero-dot { width:7px; height:7px; border-radius:50%; background:currentColor; }
        .fms-hero-badge.on .fms-hero-dot { animation:fms-pulse 1.4s infinite; }
        @keyframes fms-pulse { 0%,100% { opacity:1; } 50% { opacity:.35; } }
        .fms-hero-feat {
            position:absolute; top:16px; left:16px; z-index:3; background:rgba(0,0,0,.4); backdrop-filter:blur(6px);
            color:#fbbf24; font-size:.72rem; font-weight:800; padding:5px 11px; border-radius:20px;
        }

        .fms-identity { position:relative; z-index:2; padding:18px 18px 22px; width:100%; display:flex; align-items:center; gap:14px; color:#fff; }
        .fms-logo-wrap { position:relative; width:76px; height:76px; flex-shrink:0; }
        .fms-logo, .fms-logo-fallback {
            width:76px; height:76px; border-radius:20px; object-fit:cover; background:var(--surface-muted,#f1f7f3);
            border:3px solid rgba(255,255,255,.9); box-shadow:0 8px 22px rgba(0,0,0,.35);
        }
        .fms-logo-fallback { display:flex; align-items:center; justify-content:center; font-size:1.8rem; background:var(--fm-soft); color:var(--fm-dark); }
        .fms-logo-wrap.is-live::before {
            content:''; position:absolute; inset:-6px; border-radius:24px; border:2px solid #ef4444;
            animation:fms-ring 1.8s ease-out infinite;
        }
        @keyframes fms-ring { 0% { transform:scale(.92); opacity:.9; } 100% { transform:scale(1.2); opacity:0; } }
        .fms-name { font-size:1.35rem; font-weight:900; margin:0 0 6px; text-shadow:0 2px 10px rgba(0,0,0,.35); letter-spacing:-.01em; }
        .fms-meta-row { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
        .fms-chip {
            display:inline-flex; align-items:center; gap:4px; font-size:.76rem; font-weight:700;
            background:rgba(255,255,255,.16); backdrop-filter:blur(4px); color:#fff; padding:4px 11px; border-radius:20px;
        }
        .fms-chip--freq { background:rgba(255,255,255,.92); color:var(--fm-dark); }

        /* ── Floating glass stat bar overlapping the hero's bottom edge ── */
        .fms-statbar {
            position:relative; z-index:2; margin:-20px 0 26px; padding:14px 10px;
            background:var(--surface,#fff); border:1px solid var(--border,#eef0f2); border-radius:var(--radius-lg,20px);
            box-shadow:0 14px 34px -14px rgba(15,23,42,.22);
            display:grid; grid-template-columns:repeat(3,1fr); text-align:center;
        }
        .fms-stat + .fms-stat { border-left:1px solid var(--border,#eef0f2); }
        .fms-stat-val { font-size:.92rem; font-weight:900; color:var(--text,#111827); }
        .fms-stat-val.is-live { color:#dc2626; }
        .fms-stat-label { font-size:.66rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:var(--muted,#6b7280); margin-top:2px; }
        .fms-announce { display:flex; align-items:flex-start; gap:9px; background:#fffbeb; border:1px solid #fde68a; color:#92400e; border-radius:14px; padding:11px 14px; font-size:.85rem; font-weight:600; margin-bottom:14px; }
        .fms-announce > span { flex-shrink:0; }
        .fms-announce .rich-content { flex:1; min-width:0; }
        .fms-announce .rich-content p { margin:0 0 6px; }
        .fms-announce .rich-content p:last-child { margin-bottom:0; }

        /* ── Player ── */
        .fms-player {
            background:radial-gradient(120% 160% at 50% -30%, rgba(255,255,255,.14) 0%, rgba(255,255,255,0) 60%),
                       radial-gradient(80% 120% at 100% 100%, rgba(251,191,36,.18) 0%, rgba(251,191,36,0) 60%),
                       linear-gradient(135deg,var(--fm-base),var(--fm-dark));
            color:#fff; border-radius:var(--radius-lg,20px); padding:26px 20px; margin-bottom:16px; text-align:center;
            box-shadow:var(--shadow-md,0 12px 28px rgba(15,23,42,.08)); position:relative; overflow:hidden;
        }
        .fms-eq { display:inline-flex; align-items:flex-end; gap:2px; height:12px; margin-right:2px; }
        .fms-eq span { width:3px; background:currentColor; border-radius:2px; height:4px; }
        .fms-player.is-playing .fms-eq span,
        .fms-miniplayer.visible .fms-eq span { animation:fms-eq 0.9s ease-in-out infinite; }
        .fms-eq span:nth-child(1){ animation-delay:0s; } .fms-eq span:nth-child(2){ animation-delay:.15s; } .fms-eq span:nth-child(3){ animation-delay:.3s; }
        @keyframes fms-eq { 0%,100% { height:4px; } 50% { height:12px; } }

        .fms-play-wrap { position:relative; width:92px; height:92px; margin:6px auto 14px; }
        .fms-play-wrap::before, .fms-play-wrap::after {
            content:''; position:absolute; inset:0; border-radius:50%; border:2px solid rgba(255,255,255,.5); opacity:0;
        }
        .fms-play-wrap.pulsing::before { animation:fms-wave 2.2s ease-out infinite; }
        .fms-play-wrap.pulsing::after  { animation:fms-wave 2.2s ease-out 1.1s infinite; }
        @keyframes fms-wave { 0% { transform:scale(.75); opacity:.7; } 100% { transform:scale(1.5); opacity:0; } }
        .fms-play-btn {
            position:relative; z-index:2; width:92px; height:92px; border-radius:50%; background:#fff; color:var(--fm-dark);
            border:none; font-size:2rem; cursor:pointer; display:flex; align-items:center; justify-content:center;
            box-shadow:0 10px 26px rgba(0,0,0,.28); transition:transform .15s;
        }
        .fms-play-btn:hover { transform:scale(1.05); }
        .fms-play-btn.playing { background:#ef4444; color:#fff; }
        .fms-player-status { font-size:.85rem; opacity:.9; min-height:1.2em; font-weight:600; }
        .fms-volume { display:flex; align-items:center; gap:8px; justify-content:center; margin-top:14px; }
        .fms-volume input[type=range] {
            width:130px; height:4px; border-radius:4px; -webkit-appearance:none; appearance:none; outline:none; cursor:pointer;
            background:linear-gradient(to right,#fff var(--vol,80%),rgba(255,255,255,.28) var(--vol,80%));
        }
        .fms-volume input[type=range]::-webkit-slider-thumb {
            -webkit-appearance:none; appearance:none; width:14px; height:14px; border-radius:50%; background:#fff;
            box-shadow:0 2px 6px rgba(0,0,0,.35); cursor:pointer; margin-top:0;
        }
        .fms-volume input[type=range]::-moz-range-thumb { width:14px; height:14px; border-radius:50%; background:#fff; border:none; box-shadow:0 2px 6px rgba(0,0,0,.35); cursor:pointer; }
        .fms-volume input[type=range]::-moz-range-track { height:4px; border-radius:4px; background:rgba(255,255,255,.28); }

        .fms-section-title { font-size:.78rem; font-weight:800; text-transform:uppercase; letter-spacing:.06em; color:var(--muted,#6b7280); margin:0 0 12px; display:flex; align-items:center; gap:6px; }
        .fms-panel { background:var(--surface,#fff); border:1px solid var(--border,#e5e7eb); border-radius:var(--radius-lg,20px); padding:18px; margin-bottom:16px; box-shadow:var(--shadow-sm,0 2px 8px rgba(15,23,42,.05)); }

        /* ── On Air Now: tinted spotlight card with glowing artwork ── */
        .fms-panel--onair {
            background:linear-gradient(160deg,var(--fm-soft) 0%, var(--surface,#fff) 55%);
            position:relative; overflow:hidden;
        }
        .fms-panel--onair::before {
            content:'📡'; position:absolute; right:-6px; top:-10px; font-size:5rem; opacity:.06; transform:rotate(12deg);
        }
        .fms-onair { display:flex; gap:16px; align-items:center; position:relative; }
        .fms-onair-art-wrap { position:relative; width:72px; height:72px; flex-shrink:0; }
        .fms-onair-img { width:72px; height:72px; border-radius:18px; object-fit:cover; background:var(--surface-muted,#f1f7f3); box-shadow:0 6px 18px rgba(0,0,0,.12); }
        .fms-onair-art-wrap.is-live::after {
            content:''; position:absolute; inset:-5px; border-radius:22px; border:2px solid #dc2626; opacity:.8;
            animation:fms-ring 1.8s ease-out infinite;
        }
        .fms-onair-live-pill {
            display:inline-flex; align-items:center; gap:5px; font-size:.64rem; font-weight:800; letter-spacing:.03em;
            color:#dc2626; background:#fee2e2; padding:2px 9px; border-radius:20px; margin-bottom:5px;
        }
        .fms-onair-live-pill span { width:5px; height:5px; border-radius:50%; background:currentColor; animation:fms-pulse 1.4s infinite; }
        .fms-onair-name { font-weight:800; font-size:1.05rem; margin:0; }
        .fms-onair-time { font-size:.8rem; color:var(--fm-dark); font-weight:700; margin:3px 0; }
        .fms-onair-host { font-size:.8rem; color:var(--muted,#6b7280); margin:0; }
        .fms-onair-body { flex:1; min-width:0; }
        .fms-progress { position:relative; height:6px; border-radius:4px; background:rgba(var(--fm-rgb),.14); margin-top:10px; overflow:visible; }
        .fms-progress-bar { height:100%; border-radius:4px; background:linear-gradient(90deg,var(--fm-base),var(--fm-dark)); position:relative; }
        .fms-progress-bar::after {
            content:''; position:absolute; right:-4px; top:50%; transform:translateY(-50%); width:10px; height:10px;
            border-radius:50%; background:var(--fm-dark); box-shadow:0 0 0 3px var(--surface,#fff),0 2px 6px rgba(0,0,0,.25);
        }
        .fms-onair-remain { font-size:.7rem; color:var(--muted,#6b7280); margin-top:6px; }
        .fms-upnext { display:flex; align-items:center; gap:10px; margin-top:14px; padding-top:14px; border-top:1px dashed var(--border,#e5e7eb); position:relative; }
        .fms-upnext-icon { font-size:1.1rem; flex-shrink:0; }
        .fms-upnext-label { font-size:.66rem; font-weight:800; text-transform:uppercase; letter-spacing:.05em; color:var(--muted,#6b7280); }
        .fms-upnext-name { font-size:.86rem; font-weight:700; margin:1px 0 0; }
        .fms-upnext-when { font-size:.76rem; color:var(--fm-dark); font-weight:700; white-space:nowrap; margin-left:auto; }

        /* ── Programme Schedule: pill tabs + vertical timeline ── */
        .fms-day-tabs { display:flex; gap:6px; overflow-x:auto; scrollbar-width:none; margin-bottom:16px; padding-bottom:2px; }
        .fms-day-tabs::-webkit-scrollbar { display:none; }
        .fms-day-tab { flex-shrink:0; padding:7px 15px; border-radius:20px; font-size:.8rem; font-weight:700; background:var(--surface-muted,#f1f7f3); color:var(--muted,#6b7280); border:none; cursor:pointer; transition:background .15s,color .15s; position:relative; }
        .fms-day-tab.active { background:var(--fm-base); color:#fff; box-shadow:0 4px 10px rgba(var(--fm-rgb),.3); }
        .fms-day-tab.is-today:not(.active)::after {
            content:''; position:absolute; bottom:2px; left:50%; transform:translateX(-50%); width:4px; height:4px; border-radius:50%; background:var(--fm-base);
        }
        .fms-timeline { position:relative; padding-left:2px; }
        .fms-prog-row { display:flex; gap:14px; padding:0 0 12px; position:relative; }
        .fms-prog-row:last-child { padding-bottom:0; }
        .fms-prog-rail { position:relative; width:10px; flex-shrink:0; display:flex; align-items:center; justify-content:center; }
        .fms-prog-rail::before { content:''; position:absolute; top:calc(50% + 8px); bottom:-12px; width:2px; background:var(--border,#e5e7eb); }
        .fms-prog-row:last-child .fms-prog-rail::before { display:none; }
        .fms-prog-dot { width:10px; height:10px; border-radius:50%; background:var(--surface-muted,#e5e7eb); border:2px solid var(--border,#e5e7eb); z-index:1; flex-shrink:0; }
        .fms-prog-row.is-now .fms-prog-dot { background:#dc2626; border-color:#dc2626; box-shadow:0 0 0 4px #fee2e2; }
        .fms-prog-card { flex:1; min-width:0; display:flex; align-items:center; gap:12px; background:var(--surface-muted,#f8faf9); border-radius:14px; padding:8px 12px 8px 8px; transition:background .15s; }
        .fms-prog-row.is-now .fms-prog-card { background:linear-gradient(90deg,var(--fm-soft),var(--surface,#fff)); box-shadow:0 0 0 1px rgba(var(--fm-rgb),.25) inset; }
        .fms-prog-thumb { width:60px; height:60px; border-radius:14px; object-fit:cover; flex-shrink:0; background:var(--surface,#fff); box-shadow:0 2px 8px rgba(0,0,0,.1); }
        .fms-prog-thumb-fallback { width:60px; height:60px; border-radius:14px; flex-shrink:0; background:var(--fm-soft); color:var(--fm-dark); display:flex; align-items:center; justify-content:center; font-size:1.5rem; }
        .fms-prog-body { flex:1; min-width:0; display:flex; flex-direction:column; justify-content:center; gap:2px; }
        .fms-prog-toprow { display:flex; align-items:baseline; justify-content:space-between; gap:10px; }
        .fms-prog-time { font-size:.72rem; font-weight:800; color:var(--fm-base); text-transform:uppercase; letter-spacing:.03em; white-space:nowrap; flex-shrink:0; }
        .fms-prog-name { font-weight:700; font-size:.92rem; margin:0; display:flex; align-items:center; gap:6px; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .fms-prog-host { font-size:.78rem; color:var(--muted,#6b7280); margin:0; }
        .fms-now-tag { font-size:.6rem; font-weight:800; color:#fff; background:#dc2626; padding:1px 7px; border-radius:10px; letter-spacing:.03em; }
        .fms-prog-row.is-past { opacity:.75; }
        .fms-prog-row.is-past .fms-prog-dot { background:var(--surface-muted,#e5e7eb); border-color:var(--border,#e5e7eb); }

        /* ── About: icon-tile contact grid + colored social buttons ── */
        .fms-about-desc { line-height:1.75; font-size:.92rem; margin-bottom:14px; }
        .fms-contact-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:10px; margin-bottom:4px; }
        .fms-contact-tile {
            display:flex; align-items:center; gap:10px; padding:12px; border-radius:14px; background:var(--surface-muted,#f8faf9);
            border:1px solid var(--border,#eef0f2); text-decoration:none; color:inherit; transition:transform .15s,box-shadow .15s;
        }
        .fms-contact-tile:hover { transform:translateY(-2px); box-shadow:var(--shadow-sm,0 2px 8px rgba(15,23,42,.05)); }
        .fms-info-icon { width:34px; height:34px; border-radius:10px; background:var(--fm-soft); color:var(--fm-dark); display:flex; align-items:center; justify-content:center; flex-shrink:0; font-size:.95rem; }
        .fms-contact-label { font-size:.66rem; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:var(--muted,#6b7280); }
        .fms-contact-val { font-size:.84rem; font-weight:700; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .fms-social { display:flex; gap:10px; flex-wrap:wrap; margin-top:14px; }
        .fms-social a {
            display:inline-flex; align-items:center; justify-content:center; width:42px; height:42px; border-radius:50%;
            color:#fff; text-decoration:none; transition:transform .15s,box-shadow .15s;
        }
        .fms-social a:hover { transform:translateY(-2px); box-shadow:0 6px 16px rgba(0,0,0,.2); }
        .fms-social-wa { background:#25D366; }
        .fms-social-fb { background:#1877F2; }
        .fms-social-yt { background:#FF0000; }

        .fms-other-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(100px,1fr)); gap:12px; }
        .fms-other-card { text-align:center; text-decoration:none; color:inherit; transition:transform .15s; }
        .fms-other-card:hover { transform:translateY(-4px); }
        .fms-other-logo-wrap { position:relative; width:60px; height:60px; margin:0 auto 7px; }
        .fms-other-logo { width:60px; height:60px; border-radius:16px; object-fit:cover; background:var(--surface-muted,#f1f7f3); box-shadow:var(--shadow-sm,0 2px 8px rgba(15,23,42,.05)); transition:box-shadow .15s; }
        .fms-other-card:hover .fms-other-logo { box-shadow:var(--shadow-md,0 12px 28px rgba(15,23,42,.08)); }
        .fms-other-live-dot { position:absolute; bottom:-1px; right:-1px; width:13px; height:13px; border-radius:50%; border:2px solid var(--surface,#fff); }
        .fms-other-live-dot.on  { background:#dc2626; }
        .fms-other-live-dot.off { background:#9ca3af; }
        .fms-other-name { font-size:.76rem; font-weight:700; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }

        .fms-news-row { display:flex; align-items:center; gap:12px; text-decoration:none; color:inherit; padding:9px 0; border-bottom:1px solid var(--border,#e5e7eb); }
        .fms-news-row:last-child { border-bottom:none; }
        .fms-news-thumb { width:52px; height:52px; border-radius:12px; object-fit:cover; flex-shrink:0; }
        .fms-news-thumb-fallback { width:52px; height:52px; border-radius:12px; background:var(--fm-soft); color:var(--fm-dark); display:flex; align-items:center; justify-content:center; font-size:1.2rem; flex-shrink:0; }
        .fms-news-title { font-weight:700; font-size:.86rem; margin:0; }
        .fms-news-date { font-size:.72rem; color:var(--muted,#6b7280); margin:2px 0 0; }

        /* ── Mobile refinements ── */
        @media(max-width:420px) {
            .fms-hero { min-height:230px; }
            .fms-logo-wrap, .fms-logo, .fms-logo-fallback { width:64px; height:64px; }
            .fms-name { font-size:1.15rem; }
            .fms-play-wrap, .fms-play-btn { width:76px; height:76px; }
            .fms-play-btn { font-size:1.7rem; }
            .fms-statbar { padding:12px 4px; }
            .fms-stat-val { font-size:.82rem; }
            .fms-onair-art-wrap, .fms-onair-img { width:56px; height:56px; }
        }

        /* ── Sticky mini-player: follows you once the main player scrolls out
           of view while a stream is actually playing, so you can keep
           listening while you browse the schedule/about section. ── */
        .fms-miniplayer {
            position:fixed; left:12px; right:12px; bottom:12px; z-index:40; max-width:730px; margin:0 auto;
            display:flex; align-items:center; gap:12px; padding:10px 14px; border-radius:16px;
            background:linear-gradient(135deg,var(--fm-base),var(--fm-dark)); color:#fff;
            box-shadow:0 14px 34px rgba(0,0,0,.28);
            transform:translateY(140%); opacity:0; pointer-events:none;
            transition:transform .3s ease, opacity .3s ease;
        }
        .fms-miniplayer.visible { transform:translateY(0); opacity:1; pointer-events:auto; }
        body.has-bottom-nav .fms-miniplayer { bottom:calc(var(--bottom-nav-height,64px) + 12px); }
        .fms-mini-logo { width:38px; height:38px; border-radius:10px; object-fit:cover; flex-shrink:0; }
        .fms-mini-logo-fallback { display:flex; align-items:center; justify-content:center; background:rgba(255,255,255,.2); font-size:1.1rem; }
        .fms-mini-info { flex:1; min-width:0; }
        .fms-mini-name { font-weight:800; font-size:.85rem; margin:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .fms-mini-status { font-size:.72rem; opacity:.85; margin:1px 0 0; display:flex; align-items:center; gap:5px; }
        .fms-mini-btn { width:38px; height:38px; border-radius:50%; background:#fff; color:var(--fm-dark); border:none; font-size:1rem; cursor:pointer; flex-shrink:0; display:flex; align-items:center; justify-content:center; }
    </style>
</head>
<body class="<?php echo $user ? 'has-bottom-nav' : ''; ?>" style="--fm-base:<?php echo $shades['base']; ?>; --fm-dark:<?php echo $shades['dark']; ?>; --fm-soft:<?php echo $shades['soft']; ?>; --fm-rgb:<?php echo $shades['rgb']; ?>;">

<header class="app-topbar">
    <a href="fm_stations.php" class="button button-secondary button-small">‹ FM Stations</a>
    <span class="brand"><?php echo sanitize($station['name']); ?></span>
</header>

<main class="fms-shell">

    <!-- Station identity: immersive full-bleed poster hero -->
    <div class="fms-hero fms-fade">
        <?php if (!empty($station['cover_path'])): ?><img src="<?php echo sanitize($station['cover_path']); ?>" alt="" class="fms-hero-img"><?php endif; ?>
        <?php if ($station['featured']): ?><span class="fms-hero-feat">⭐ Featured</span><?php endif; ?>
        <span class="fms-hero-badge <?php echo $isLive ? 'on' : 'off'; ?>">
            <span class="fms-hero-dot"></span> <?php echo $isLive ? 'LIVE NOW' : 'OFFLINE'; ?>
        </span>
        <div class="fms-identity">
            <div class="fms-logo-wrap<?php echo $isLive ? ' is-live' : ''; ?>">
                <?php if (!empty($station['logo_path'])): ?>
                <img src="<?php echo sanitize($station['logo_path']); ?>" alt="" class="fms-logo">
                <?php else: ?>
                <span class="fms-logo-fallback">📻</span>
                <?php endif; ?>
            </div>
            <div style="min-width:0;">
                <p class="fms-name"><?php echo sanitize($station['name']); ?></p>
                <div class="fms-meta-row">
                    <?php if ($station['frequency']): ?><span class="fms-chip fms-chip--freq">📶 <?php echo sanitize($station['frequency']); ?></span><?php endif; ?>
                    <?php if ($station['town_name']): ?><span class="fms-chip">📍 <?php echo sanitize($station['town_name']); ?></span><?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Glanceable stats, overlapping the hero -->
    <div class="fms-statbar fms-fade">
        <div class="fms-stat">
            <div class="fms-stat-val <?php echo $isLive ? 'is-live' : ''; ?>"><?php echo $isLive ? '🔴 Live' : '⚪ Offline'; ?></div>
            <div class="fms-stat-label">Status</div>
        </div>
        <div class="fms-stat">
            <div class="fms-stat-val"><?php echo $station['frequency'] ? sanitize($station['frequency']) : '—'; ?></div>
            <div class="fms-stat-label">Frequency</div>
        </div>
        <div class="fms-stat">
            <div class="fms-stat-val"><?php echo $todayShowCount; ?></div>
            <div class="fms-stat-label"><?php echo $todayShowCount === 1 ? 'Show Today' : 'Shows Today'; ?></div>
        </div>
    </div>

    <?php foreach ($announcements as $an): ?>
    <div class="fms-announce fms-fade"><span>📢</span><div class="rich-content"><?php echo render_rich($an['message']); ?></div></div>
    <?php endforeach; ?>

    <!-- Live player -->
    <div class="fms-player fms-fade" id="fms-player" style="animation-delay:.05s;">
        <?php if (!empty($station['stream_url'])): ?>
        <div class="fms-play-wrap" id="fms-play-wrap">
            <button type="button" class="fms-play-btn" id="fms-play-btn" onclick="fmsTogglePlay()" aria-label="Play">▶</button>
        </div>
        <p class="fms-player-status">
            <span class="fms-eq"><span></span><span></span><span></span></span><span id="fms-player-status">Tap play to start listening</span>
        </p>
        <div class="fms-volume">
            🔊 <input type="range" id="fms-volume" min="0" max="100" value="80" oninput="fmsSetVolume(this.value)">
        </div>
        <audio id="fms-audio" preload="none"></audio>
        <?php else: ?>
        <p class="fms-player-status" style="opacity:.9;">📻 No live stream has been configured for this station.</p>
        <?php endif; ?>
    </div>

    <!-- On air now -->
    <div class="fms-panel fms-panel--onair fms-fade" style="animation-delay:.1s;">
        <p class="fms-section-title">📡 On Air Now</p>
        <?php if ($currentProgramme): ?>
        <div class="fms-onair">
            <div class="fms-onair-art-wrap<?php echo $isLive ? ' is-live' : ''; ?>">
                <?php if (!empty($currentProgramme['image_path'])): ?>
                <img src="<?php echo sanitize($currentProgramme['image_path']); ?>" alt="" class="fms-onair-img" loading="lazy">
                <?php else: ?>
                <div class="fms-onair-img" style="display:flex;align-items:center;justify-content:center;font-size:1.6rem;">🎙️</div>
                <?php endif; ?>
            </div>
            <div class="fms-onair-body">
                <?php if ($isLive): ?><span class="fms-onair-live-pill"><span></span> ON AIR</span><?php endif; ?>
                <?php $curPresenter = fm_get_presenter_by_id($currentProgramme['presenter_id'] ?? null); ?>
                <?php if (!empty($currentProgramme['slug'])): ?>
                <a href="fm_programme.php?station=<?php echo urlencode($station['slug']); ?>&slug=<?php echo urlencode($currentProgramme['slug']); ?>" class="fms-onair-name" style="text-decoration:none;color:inherit;display:block;"><?php echo sanitize($currentProgramme['name']); ?></a>
                <?php else: ?>
                <p class="fms-onair-name"><?php echo sanitize($currentProgramme['name']); ?></p>
                <?php endif; ?>
                <p class="fms-onair-time"><?php echo format_time_range($currentProgramme['start_time'], $currentProgramme['end_time']); ?></p>
                <?php if ($curPresenter): ?>
                <p class="fms-onair-host">Host: <a href="fm_presenter.php?station=<?php echo urlencode($station['slug']); ?>&slug=<?php echo urlencode($curPresenter['slug']); ?>" style="color:inherit;"><?php echo sanitize($curPresenter['name']); ?></a></p>
                <?php elseif ($currentProgramme['host']): ?>
                <p class="fms-onair-host">Host: <?php echo sanitize($currentProgramme['host']); ?></p>
                <?php endif; ?>
                <div class="fms-progress"><div class="fms-progress-bar" style="width:<?php echo round($onAirProgress); ?>%;"></div></div>
                <?php
                $remainMin = (int)round((100 - $onAirProgress) / 100 * (strtotime('1970-01-01 ' . $currentProgramme['end_time']) - strtotime('1970-01-01 ' . $currentProgramme['start_time']) + ($currentProgramme['end_time'] <= $currentProgramme['start_time'] ? 86400 : 0)) / 60);
                ?>
                <?php if ($remainMin > 0): ?><p class="fms-onair-remain">⏱️ <?php echo $remainMin; ?> min left</p><?php endif; ?>
            </div>
        </div>
        <?php else: ?>
        <p class="meta" style="margin:0;">Programme information unavailable.</p>
        <?php endif; ?>

        <?php if ($nextProgramme): ?>
        <div class="fms-upnext">
            <span class="fms-upnext-icon">⏭️</span>
            <div>
                <div class="fms-upnext-label">Up Next · <?php echo sanitize($nextDayLabel); ?></div>
                <?php if (!empty($nextProgramme['slug'])): ?>
                <a href="fm_programme.php?station=<?php echo urlencode($station['slug']); ?>&slug=<?php echo urlencode($nextProgramme['slug']); ?>" class="fms-upnext-name" style="text-decoration:none;color:inherit;display:block;"><?php echo sanitize($nextProgramme['name']); ?></a>
                <?php else: ?>
                <p class="fms-upnext-name"><?php echo sanitize($nextProgramme['name']); ?></p>
                <?php endif; ?>
            </div>
            <span class="fms-upnext-when"><?php echo date('g:i A', strtotime($nextProgramme['start_time'])); ?></span>
        </div>
        <?php endif; ?>
    </div>

    <!-- Weekly schedule -->
    <div class="fms-panel fms-fade" style="animation-delay:.15s;">
        <p class="fms-section-title">🗓️ Programme Schedule</p>
        <div class="fms-day-tabs" id="fms-day-tabs">
            <?php foreach ($weekdayNames as $dayNum => $dayName): ?>
            <button type="button" class="fms-day-tab <?php echo $dayNum === $today ? 'active is-today' : ''; ?>" data-day="<?php echo $dayNum; ?>" onclick="fmsShowDay(<?php echo $dayNum; ?>)"><?php echo strtoupper(substr($dayName, 0, 3)); ?></button>
            <?php endforeach; ?>
        </div>
        <?php foreach ($weekdayNames as $dayNum => $dayName): ?>
        <div class="fms-day-programmes" id="fms-day-<?php echo $dayNum; ?>" <?php echo $dayNum === $today ? '' : 'hidden'; ?>>
            <?php if (empty($weeklySchedule[$dayNum])): ?>
            <p class="meta" style="margin:0;">No programme schedule available for <?php echo sanitize($dayName); ?>.</p>
            <?php else: ?>
            <div class="fms-timeline">
                <?php
                $nowTime = date('H:i:s');
                foreach ($weeklySchedule[$dayNum] as $p):
                    $isNowRow  = $currentProgramme && (int)$p['id'] === (int)$currentProgramme['id'];
                    // "Past" only means something on today's own timeline — a
                    // non-wrapping slot whose end time has already gone by.
                    $isPastRow = !$isNowRow && $dayNum === $today && $p['start_time'] <= $p['end_time'] && $p['end_time'] <= $nowTime;
                ?>
                <?php
                    $progTag  = !empty($p['slug']) ? 'a' : 'div';
                    $progHref = !empty($p['slug']) ? ' href="fm_programme.php?station=' . urlencode($station['slug']) . '&slug=' . urlencode($p['slug']) . '"' : '';
                    $progPresenter = fm_get_presenter_by_id($p['presenter_id'] ?? null);
                ?>
                <div class="fms-prog-row<?php echo $isNowRow ? ' is-now' : ($isPastRow ? ' is-past' : ''); ?>">
                    <div class="fms-prog-rail"><span class="fms-prog-dot"></span></div>
                    <<?php echo $progTag . $progHref; ?> class="fms-prog-card" style="text-decoration:none;color:inherit;">
                        <?php if (!empty($p['image_path'])): ?>
                        <img src="<?php echo sanitize($p['image_path']); ?>" alt="" class="fms-prog-thumb" loading="lazy">
                        <?php else: ?>
                        <span class="fms-prog-thumb-fallback">🎙️</span>
                        <?php endif; ?>
                        <div class="fms-prog-body">
                            <div class="fms-prog-toprow">
                                <p class="fms-prog-name"><?php echo sanitize($p['name']); ?><?php if ($isNowRow): ?><span class="fms-now-tag">NOW</span><?php endif; ?></p>
                                <span class="fms-prog-time"><?php echo date('g:i A', strtotime($p['start_time'])); ?></span>
                            </div>
                            <?php if ($progPresenter): ?>
                            <p class="fms-prog-host">🎙️ <?php echo sanitize($progPresenter['name']); ?></p>
                            <?php elseif ($p['host']): ?>
                            <p class="fms-prog-host">🎙️ <?php echo sanitize($p['host']); ?></p>
                            <?php endif; ?>
                        </div>
                    </<?php echo $progTag; ?>>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- About / contact -->
    <div class="fms-panel fms-fade" style="animation-delay:.2s;">
        <p class="fms-section-title">ℹ️ About</p>
        <?php if ($station['description']): ?>
        <div class="rich-content fms-about-desc"><?php echo render_rich($station['description']); ?></div>
        <?php else: ?>
        <p class="meta" style="margin:0 0 10px;">No description provided.</p>
        <?php endif; ?>

        <?php if ($station['phone'] || $station['website_url']): ?>
        <div class="fms-contact-grid">
            <?php if ($station['phone']): ?>
            <a href="tel:<?php echo sanitize($station['phone']); ?>" class="fms-contact-tile">
                <span class="fms-info-icon">📞</span>
                <span><span class="fms-contact-label">Phone</span><br><span class="fms-contact-val"><?php echo sanitize($station['phone']); ?></span></span>
            </a>
            <?php endif; ?>
            <?php if ($station['website_url']): ?>
            <a href="<?php echo sanitize($station['website_url']); ?>" target="_blank" rel="noopener" class="fms-contact-tile">
                <span class="fms-info-icon">🌐</span>
                <span><span class="fms-contact-label">Website</span><br><span class="fms-contact-val"><?php echo sanitize(preg_replace('#^https?://#', '', $station['website_url'])); ?></span></span>
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php $waLink = $station['whatsapp'] ? whatsapp_contact_link($station['whatsapp'], $station['name']) : false; ?>
        <?php if ($waLink || $station['facebook_url'] || $station['youtube_url']): ?>
        <div class="fms-social">
            <?php if ($waLink): ?>
            <a href="<?php echo sanitize($waLink); ?>" target="_blank" rel="noopener" class="fms-social-wa" title="WhatsApp" aria-label="WhatsApp">
                <svg viewBox="0 0 24 24" width="19" height="19" fill="#fff"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347M12.05 21.785h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884M20.463 3.488A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413"/></svg>
            </a>
            <?php endif; ?>
            <?php if ($station['facebook_url']): ?>
            <a href="<?php echo sanitize($station['facebook_url']); ?>" target="_blank" rel="noopener" class="fms-social-fb" title="Facebook" aria-label="Facebook">
                <svg viewBox="0 0 24 24" width="17" height="17" fill="#fff"><path d="M9.101 23.691v-7.98H6.627v-3.667h2.474v-1.58c0-4.085 1.848-5.978 5.858-5.978.401 0 .955.042 1.468.103a8.68 8.68 0 0 1 1.141.195v3.325a8.623 8.623 0 0 0-.653-.036 26.805 26.805 0 0 0-.732-.009c-.954 0-1.639.267-2.05.68-.412.415-.622 1.16-.622 2.269v1.03h3.884l-.505 3.667h-3.379v7.98H9.101z"/></svg>
            </a>
            <?php endif; ?>
            <?php if ($station['youtube_url']): ?>
            <a href="<?php echo sanitize($station['youtube_url']); ?>" target="_blank" rel="noopener" class="fms-social-yt" title="YouTube" aria-label="YouTube">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="#fff"><path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/></svg>
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($stationNews): ?>
    <!-- Station News -->
    <div class="fms-panel fms-fade" style="animation-delay:.22s;">
        <p class="fms-section-title">📰 Station News</p>
        <?php foreach ($stationNews as $n): ?>
        <a href="news_article.php?slug=<?php echo urlencode($n['slug']); ?>" class="fms-news-row">
            <?php if (!empty($n['featured_image'])): ?>
            <img src="<?php echo sanitize($n['featured_image']); ?>" alt="" class="fms-news-thumb" loading="lazy">
            <?php else: ?>
            <span class="fms-news-thumb-fallback">📰</span>
            <?php endif; ?>
            <div class="fms-news-body">
                <p class="fms-news-title"><?php echo sanitize($n['title']); ?></p>
                <p class="fms-news-date"><?php echo date('d M Y', strtotime($n['published_at'])); ?></p>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Share -->
    <div class="fms-panel fms-fade" style="animation-delay:.25s;">
        <?php require __DIR__ . '/partials/share_buttons.php'; ?>
    </div>

    <?php if ($otherStations): ?>
    <div class="fms-panel fms-fade" style="animation-delay:.3s;">
        <p class="fms-section-title">📻 Other Stations</p>
        <div class="fms-other-grid">
            <?php foreach ($otherStations as $osIdx => $os): $osLive = fm_station_is_live($os); ?>
            <a href="fm_station.php?slug=<?php echo urlencode($os['slug']); ?>" class="fms-other-card fms-fade" style="animation-delay:<?php echo .3 + min($osIdx * 0.04, 0.24); ?>s;">
                <div class="fms-other-logo-wrap">
                    <?php if (!empty($os['logo_path'])): ?>
                    <img src="<?php echo sanitize($os['logo_path']); ?>" alt="" class="fms-other-logo" loading="lazy">
                    <?php else: ?>
                    <div class="fms-other-logo" style="display:flex;align-items:center;justify-content:center;font-size:1.3rem;">📻</div>
                    <?php endif; ?>
                    <span class="fms-other-live-dot <?php echo $osLive ? 'on' : 'off'; ?>"></span>
                </div>
                <div class="fms-other-name"><?php echo sanitize($os['name']); ?></div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</main>

<?php if (!empty($station['stream_url'])): ?>
<div class="fms-miniplayer" id="fms-miniplayer">
    <?php if (!empty($station['logo_path'])): ?>
    <img src="<?php echo sanitize($station['logo_path']); ?>" alt="" class="fms-mini-logo">
    <?php else: ?>
    <span class="fms-mini-logo fms-mini-logo-fallback">📻</span>
    <?php endif; ?>
    <div class="fms-mini-info">
        <p class="fms-mini-name"><?php echo sanitize($station['name']); ?></p>
        <p class="fms-mini-status"><span class="fms-eq"><span></span><span></span><span></span></span><span id="fms-mini-status">Now playing</span></p>
    </div>
    <button type="button" class="fms-mini-btn" id="fms-mini-btn" onclick="fmsTogglePlay()" aria-label="Play or pause">❚❚</button>
</div>
<?php endif; ?>

<?php if ($station['stream_type'] === 'hls' && !empty($station['stream_url'])): ?>
<script src="https://cdn.jsdelivr.net/npm/hls.js@1.5.17/dist/hls.min.js"></script>
<?php endif; ?>
<script>
var fmsAudio     = document.getElementById('fms-audio');
var fmsBtn       = document.getElementById('fms-play-btn');
var fmsStatus    = document.getElementById('fms-player-status');
var fmsPlayer    = document.getElementById('fms-player');
var fmsPlayWrap  = document.getElementById('fms-play-wrap');
var fmsMini      = document.getElementById('fms-miniplayer');
var fmsMiniBtn   = document.getElementById('fms-mini-btn');
var fmsMiniStatus= document.getElementById('fms-mini-status');
var fmsStreamUrl = <?php echo json_encode($station['stream_url'] ?: ''); ?>;
var fmsStreamType = <?php echo json_encode($station['stream_type']); ?>;
var fmsHls       = null;
var fmsStarted   = false;
var fmsPlayerInView = true;

// Shows the sticky mini-player only once the main player has scrolled out of
// view *and* the stream is actually playing — it exists purely so a listener
// can keep the station going while browsing the schedule, not as decoration.
function fmsUpdateMiniVisibility() {
    if (!fmsMini) return;
    var isPlaying = fmsAudio && !fmsAudio.paused && fmsStarted;
    fmsMini.classList.toggle('visible', isPlaying && !fmsPlayerInView);
}

// HLS (.m3u8) needs MediaSource-based playback on every browser except
// Safari, which plays it natively — hls.js is only ever fetched (see the
// conditional <script> above) when this station is actually stream_type
// 'hls', so plain mp3/aac/Icecast/Shoutcast stations never pay for it.
function fmsStartStream() {
    if (fmsStarted) return;
    fmsStarted = true;
    if (fmsStreamType === 'hls' && window.Hls && Hls.isSupported() && !fmsAudio.canPlayType('application/vnd.apple.mpegurl')) {
        fmsHls = new Hls();
        fmsHls.loadSource(fmsStreamUrl);
        fmsHls.attachMedia(fmsAudio);
        fmsHls.on(Hls.Events.ERROR, function (event, data) {
            if (data.fatal) {
                fmsStatus.textContent = 'Unable to play this station right now. Please try again later.';
                fmsBtn.classList.remove('playing');
                fmsBtn.textContent = '▶';
            }
        });
    } else {
        fmsAudio.src = fmsStreamUrl;
    }
}

function fmsTogglePlay() {
    if (!fmsAudio || !fmsStreamUrl) return;
    if (fmsAudio.paused) {
        fmsStartStream(); // only ever touches the stream once the user presses play
        fmsStatus.textContent = 'Connecting…';
        fmsAudio.play().catch(function () {
            fmsStatus.textContent = 'Unable to play this station right now. Please try again later.';
            fmsBtn.classList.remove('playing');
            fmsBtn.textContent = '▶';
        });
    } else {
        fmsAudio.pause();
    }
}
function fmsSetVolume(v) {
    if (fmsAudio) fmsAudio.volume = v / 100;
    var slider = document.getElementById('fms-volume');
    if (slider) slider.style.setProperty('--vol', v + '%');
}
if (fmsAudio) {
    fmsAudio.volume = 0.8;
    fmsSetVolume(80);
    fmsAudio.addEventListener('playing', function () {
        fmsBtn.classList.add('playing');
        fmsBtn.textContent = '❚❚';
        fmsStatus.textContent = 'Now playing';
        if (fmsPlayer) fmsPlayer.classList.add('is-playing');
        if (fmsPlayWrap) fmsPlayWrap.classList.add('pulsing');
        if (fmsMiniBtn) fmsMiniBtn.textContent = '❚❚';
        if (fmsMiniStatus) fmsMiniStatus.textContent = 'Now playing';
        fmsUpdateMiniVisibility();
    });
    fmsAudio.addEventListener('pause', function () {
        fmsBtn.classList.remove('playing');
        fmsBtn.textContent = '▶';
        fmsStatus.textContent = 'Paused';
        if (fmsPlayer) fmsPlayer.classList.remove('is-playing');
        if (fmsPlayWrap) fmsPlayWrap.classList.remove('pulsing');
        if (fmsMiniBtn) fmsMiniBtn.textContent = '▶';
        if (fmsMiniStatus) fmsMiniStatus.textContent = 'Paused';
        fmsUpdateMiniVisibility();
    });
    fmsAudio.addEventListener('error', function () {
        fmsStatus.textContent = 'Unable to play this station right now. Please try again later.';
        fmsBtn.classList.remove('playing');
        fmsBtn.textContent = '▶';
        if (fmsPlayer) fmsPlayer.classList.remove('is-playing');
        if (fmsPlayWrap) fmsPlayWrap.classList.remove('pulsing');
        fmsUpdateMiniVisibility();
    });
}
if (fmsMini && fmsPlayer && window.IntersectionObserver) {
    new IntersectionObserver(function (entries) {
        fmsPlayerInView = entries[0].isIntersecting;
        fmsUpdateMiniVisibility();
    }, { threshold: 0 }).observe(fmsPlayer);
}
function fmsShowDay(day) {
    document.querySelectorAll('.fms-day-programmes').forEach(function (el) { el.hidden = true; });
    document.querySelectorAll('.fms-day-tab').forEach(function (el) { el.classList.remove('active'); });
    var panel = document.getElementById('fms-day-' + day);
    if (panel) panel.hidden = false;
    var tab = document.querySelector('.fms-day-tab[data-day="' + day + '"]');
    if (tab) tab.classList.add('active');
}
</script>

<?php require __DIR__ . '/partials/site_footer.php'; ?>
<?php if ($user): require_once __DIR__ . '/partials/bottom_nav.php'; endif; ?>
</body>
</html>
