<?php
/**
 * FM Stations — public directory. Anyone can browse (no login required),
 * matching how funerals.php/events.php/find_workers.php work.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/fm_functions.php';

require_module_enabled('fm', 'FM Stations');
$user = current_user();

$q       = trim($_GET['q'] ?? '');
$townId  = (int)($_GET['town'] ?? 0);

$where  = ["fs.status = 'active'"];
$params = [];
if ($q !== '') {
    $where[] = '(fs.name LIKE ? OR fs.frequency LIKE ?)';
    $like = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
}
if ($townId > 0) {
    $where[] = 'fs.town_id = ?';
    $params[] = $townId;
}
$whereSql = implode(' AND ', $where);

$stmt = $pdo->prepare(
    "SELECT fs.*, t.name AS town_name FROM fm_stations fs
     LEFT JOIN towns t ON t.id = fs.town_id
     WHERE {$whereSql}
     ORDER BY fs.featured DESC, fs.display_order ASC, fs.name ASC"
);
$stmt->execute($params);
$stations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Spotlight strip only makes sense on the unfiltered directory — once someone
// is searching/filtering, repeating featured stations above the results they
// asked for would just be confusing.
$isUnfiltered = ($q === '' && $townId === 0);
$spotlight    = $isUnfiltered ? array_filter($stations, fn($s) => (bool)$s['featured']) : [];
$gridStations = $isUnfiltered ? array_filter($stations, fn($s) => !$s['featured']) : $stations;

$towns = get_towns();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php echo seo_meta([
        'title'       => 'FM Stations — Listen Live | ' . APP_NAME,
        'description' => 'Discover and listen live to FM radio stations across the Akuapem area of Ghana, right from your browser.',
    ]); ?>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .fmx-hero {
            position:relative; overflow:hidden;
            background:radial-gradient(120% 160% at 15% -20%, rgba(255,255,255,.16) 0%, rgba(255,255,255,0) 55%),
                       linear-gradient(135deg, var(--primary,#2f8f5b) 0%, var(--primary-dark,#246b45) 100%);
            color:#fff; padding:40px 20px 52px; text-align:center;
        }
        .fmx-hero::before, .fmx-hero::after {
            content:''; position:absolute; border:2px solid rgba(255,255,255,.16); border-radius:50%;
            top:50%; left:50%; transform:translate(-50%,-50%); pointer-events:none;
        }
        .fmx-hero::before { width:340px; height:340px; }
        .fmx-hero::after   { width:520px; height:520px; border-color:rgba(255,255,255,.08); }
        .fmx-hero-icon { font-size:2.4rem; margin-bottom:6px; filter:drop-shadow(0 4px 10px rgba(0,0,0,.25)); position:relative; }
        .fmx-hero h1 { font-size:clamp(1.5rem,5vw,2.1rem); font-weight:900; margin:0 0 6px; text-shadow:0 2px 8px rgba(0,0,0,.2); position:relative; }
        .fmx-hero p  { font-size:.92rem; color:rgba(255,255,255,.88); margin:0; position:relative; }

        .fmx-toolbar {
            display:flex; gap:8px; flex-wrap:wrap; align-items:center;
            background:var(--surface,#fff); border:1px solid var(--border,#e5e7eb); border-radius:var(--radius-lg,20px);
            box-shadow:var(--shadow-md,0 12px 28px rgba(15,23,42,.08)); padding:12px; margin:-34px auto 22px; max-width:760px; position:relative; z-index:2;
        }
        .fmx-toolbar input, .fmx-toolbar select {
            padding:10px 14px; border:1px solid var(--border,#e5e7eb); border-radius:var(--radius-sm,10px); font-size:.88rem; background:var(--surface-muted,#f1f7f3);
        }
        .fmx-toolbar input { flex:1; min-width:160px; }
        .fmx-toolbar input:focus, .fmx-toolbar select:focus { outline:none; border-color:var(--primary,#2f8f5b); box-shadow:0 0 0 3px var(--primary-soft,#e4f4ea); }

        .fmx-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(240px,1fr)); gap:18px; }
        .fmx-card {
            background:var(--surface,#fff); border:1px solid var(--border,#e5e7eb); border-radius:var(--radius-lg,20px);
            overflow:hidden; text-decoration:none; color:inherit; display:flex; flex-direction:column;
            transition:box-shadow .25s ease,transform .25s ease; position:relative; box-shadow:var(--shadow-sm,0 2px 8px rgba(15,23,42,.05));
        }
        .fmx-card:hover { box-shadow:var(--shadow-md,0 12px 28px rgba(15,23,42,.08)); transform:translateY(-4px); }
        .fmx-card--featured { box-shadow:0 0 0 2px #f59e0b, var(--shadow-sm,0 2px 8px rgba(15,23,42,.05)); }
        .fmx-card--featured:hover { box-shadow:0 0 0 2px #f59e0b, var(--shadow-md,0 12px 28px rgba(15,23,42,.08)); }
        .fmx-cover {
            aspect-ratio:16/9; position:relative; overflow:hidden;
            background:linear-gradient(135deg,var(--primary,#2f8f5b),var(--primary-dark,#246b45));
        }
        .fmx-cover img { width:100%; height:100%; object-fit:cover; transition:transform .35s ease; }
        .fmx-card:hover .fmx-cover img { transform:scale(1.06); }
        .fmx-cover::after { content:''; position:absolute; inset:0; background:linear-gradient(180deg,rgba(0,0,0,0) 45%,rgba(0,0,0,.45) 100%); }
        .fmx-logo-fallback { font-size:2.2rem; width:100%; height:100%; display:flex; align-items:center; justify-content:center; }
        .fmx-logo-ring {
            position:absolute; left:14px; bottom:-24px; width:56px; height:56px; border-radius:16px; z-index:2;
            border:3px solid var(--surface,#fff); box-shadow:0 6px 16px rgba(0,0,0,.2); overflow:hidden; background:var(--surface,#fff);
        }
        .fmx-logo-ring img { width:100%; height:100%; object-fit:cover; display:block; }
        .fmx-logo-ring-fallback { width:100%; height:100%; display:flex; align-items:center; justify-content:center; background:var(--primary-soft,#e4f4ea); color:var(--primary-dark,#246b45); font-size:1.3rem; }
        .fmx-freq-chip {
            position:absolute; right:10px; bottom:-16px; z-index:2; background:var(--surface,#fff); color:var(--primary-dark,#246b45);
            font-size:.72rem; font-weight:800; padding:4px 10px; border-radius:20px; box-shadow:0 4px 10px rgba(0,0,0,.15);
        }
        .fmx-body { padding:32px 14px 14px; flex:1; display:flex; flex-direction:column; }
        .fmx-name { font-weight:800; font-size:1rem; margin:0 0 3px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .fmx-meta { font-size:.78rem; color:var(--muted,#6b7280); margin:0 0 10px; display:flex; align-items:center; gap:4px; }
        .fmx-live { display:inline-flex; align-items:center; gap:6px; font-size:.7rem; font-weight:800; letter-spacing:.03em; padding:4px 10px; border-radius:20px; margin-top:auto; width:fit-content; }
        .fmx-live.on  { background:#fee2e2; color:#991b1b; }
        .fmx-live.off { background:var(--surface-muted,#f1f7f3); color:var(--muted,#6b7280); }
        .fmx-live-dot { width:7px; height:7px; border-radius:50%; background:currentColor; }
        .fmx-live.on .fmx-live-dot { animation:fmx-pulse 1.4s infinite; }
        @keyframes fmx-pulse { 0%,100% { opacity:1; box-shadow:0 0 0 0 rgba(153,27,27,.4); } 50% { opacity:.5; box-shadow:0 0 0 4px rgba(153,27,27,0); } }
        .fmx-feat-badge {
            position:absolute; top:10px; left:10px; z-index:2; background:rgba(0,0,0,.35); backdrop-filter:blur(4px);
            color:#fbbf24; font-size:.68rem; font-weight:800; padding:3px 10px; border-radius:20px; display:flex; align-items:center; gap:4px;
        }
        .fmx-onair-line { font-size:.72rem; color:var(--primary-dark,#246b45); font-weight:700; margin:0 0 8px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; display:flex; align-items:center; gap:5px; }

        /* ── Decorative equalizer bars in the hero, purely cosmetic ── */
        .fmx-hero-eq { display:flex; align-items:flex-end; justify-content:center; gap:4px; height:26px; margin:0 0 10px; position:relative; opacity:.85; }
        .fmx-hero-eq span { width:4px; border-radius:3px; background:rgba(255,255,255,.75); animation:fmx-eq-bar 1.2s ease-in-out infinite; }
        .fmx-hero-eq span:nth-child(1){ height:10px; animation-delay:-1.1s; }
        .fmx-hero-eq span:nth-child(2){ height:22px; animation-delay:-0.9s; }
        .fmx-hero-eq span:nth-child(3){ height:14px; animation-delay:-0.7s; }
        .fmx-hero-eq span:nth-child(4){ height:24px; animation-delay:-0.5s; }
        .fmx-hero-eq span:nth-child(5){ height:12px; animation-delay:-0.3s; }
        @keyframes fmx-eq-bar { 0%,100% { transform:scaleY(.4); } 50% { transform:scaleY(1); } }

        /* ── Entrance animation ── */
        @keyframes fmxFadeUp { from { opacity:0; transform:translateY(16px); } to { opacity:1; transform:translateY(0); } }
        .fmx-fade { animation:fmxFadeUp .5s ease both; }

        /* ── Spotlight strip: bigger poster-style tiles for featured stations ── */
        .fmx-spotlight-title { font-size:.78rem; font-weight:800; text-transform:uppercase; letter-spacing:.06em; color:var(--muted,#6b7280); margin:0 0 12px; display:flex; align-items:center; gap:6px; }
        .fmx-spot-row { display:flex; gap:14px; overflow-x:auto; scrollbar-width:none; padding-bottom:4px; margin-bottom:26px; scroll-snap-type:x mandatory; -webkit-overflow-scrolling:touch; }
        .fmx-spot-row::-webkit-scrollbar { display:none; }
        .fmx-spot-card {
            flex:0 0 240px; scroll-snap-align:start; position:relative; aspect-ratio:4/3; border-radius:var(--radius-lg,20px);
            overflow:hidden; text-decoration:none; color:#fff; display:flex; align-items:flex-end;
            background:linear-gradient(135deg,var(--primary,#2f8f5b),var(--primary-dark,#246b45));
            box-shadow:var(--shadow-md,0 12px 28px rgba(15,23,42,.08)); transition:transform .25s ease;
        }
        .fmx-spot-card:hover { transform:translateY(-4px) scale(1.01); }
        .fmx-spot-card img.fmx-spot-bg { position:absolute; inset:0; width:100%; height:100%; object-fit:cover; transition:transform .4s ease; }
        .fmx-spot-card:hover img.fmx-spot-bg { transform:scale(1.08); }
        .fmx-spot-card::after { content:''; position:absolute; inset:0; background:linear-gradient(180deg,rgba(0,0,0,0) 30%,rgba(0,0,0,.75) 100%); }
        .fmx-spot-ribbon {
            position:absolute; top:12px; left:12px; z-index:2; display:inline-flex; align-items:center; gap:4px;
            background:linear-gradient(120deg,#fbbf24,#f59e0b); color:#78350f; font-size:.68rem; font-weight:800;
            padding:4px 10px; border-radius:20px; overflow:hidden;
        }
        .fmx-spot-ribbon::before {
            content:''; position:absolute; top:0; left:-60%; width:40%; height:100%;
            background:linear-gradient(120deg,transparent,rgba(255,255,255,.75),transparent);
            animation:fmx-shine 2.6s ease-in-out infinite;
        }
        @keyframes fmx-shine { 0% { left:-60%; } 60%,100% { left:130%; } }
        .fmx-spot-live {
            position:absolute; top:12px; right:12px; z-index:2; display:inline-flex; align-items:center; gap:5px;
            font-size:.66rem; font-weight:800; padding:4px 9px; border-radius:20px; backdrop-filter:blur(5px);
        }
        .fmx-spot-live.on  { background:rgba(220,38,38,.85); }
        .fmx-spot-live.off { background:rgba(0,0,0,.4); color:#e5e7eb; }
        .fmx-spot-live-dot { width:6px; height:6px; border-radius:50%; background:currentColor; }
        .fmx-spot-live.on .fmx-spot-live-dot { animation:fmx-pulse 1.4s infinite; }
        .fmx-spot-body { position:relative; z-index:2; padding:14px; width:100%; }
        .fmx-spot-name { font-weight:900; font-size:1.02rem; margin:0 0 3px; text-shadow:0 2px 8px rgba(0,0,0,.3); }
        .fmx-spot-meta { font-size:.76rem; opacity:.92; margin:0; }

        .fmx-count-chip {
            display:inline-flex; align-items:center; gap:5px; font-size:.78rem; font-weight:700; color:var(--muted,#6b7280);
            margin:0 0 14px;
        }

        .empty-state .empty-icon { font-size:2.6rem; margin-bottom:8px; display:block; opacity:.85; }
    </style>
</head>
<body class="<?php echo $user ? 'has-bottom-nav' : ''; ?>">

<header class="app-topbar">
    <a href="<?php echo $user ? 'jobs.php' : 'index.php'; ?>" class="button button-secondary button-small">‹ Home</a>
    <span class="brand">📻 FM Stations</span>
</header>

<div class="fmx-hero">
    <div class="fmx-hero-eq"><span></span><span></span><span></span><span></span><span></span></div>
    <div class="fmx-hero-icon">📻</div>
    <h1>FM Stations</h1>
    <p>Listen live to local radio from across the Akuapem area</p>
</div>

<main class="page-shell" style="padding-top:0;">
    <form method="get" class="fmx-toolbar fmx-fade">
        <input type="text" name="q" value="<?php echo sanitize($q); ?>" placeholder="🔍 Search station name or frequency…">
        <select name="town" onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()">
            <option value="">All towns</option>
            <?php foreach ($towns as $t): ?>
            <option value="<?php echo (int)$t['id']; ?>" <?php echo $townId === (int)$t['id'] ? 'selected' : ''; ?>><?php echo sanitize($t['name']); ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="button button-primary">Search</button>
        <?php if ($q !== '' || $townId > 0): ?><a href="fm_stations.php" class="button button-secondary">Clear</a><?php endif; ?>
    </form>

    <?php if (!$stations): ?>
    <div class="empty-state">
        <span class="empty-icon">📻</span>
        <p>No FM stations found<?php echo ($q !== '' || $townId > 0) ? ' matching your search' : ' yet'; ?>.</p>
    </div>
    <?php else: ?>

    <?php if ($spotlight): ?>
    <p class="fmx-spotlight-title fmx-fade">⭐ Featured Stations</p>
    <div class="fmx-spot-row fmx-fade">
        <?php foreach ($spotlight as $s):
            $isLive = fm_station_is_live($s);
        ?>
        <a href="fm_station.php?slug=<?php echo urlencode($s['slug']); ?>" class="fmx-spot-card">
            <?php if (!empty($s['cover_path'] ?? $s['logo_path'])): ?>
            <img src="<?php echo sanitize($s['cover_path'] ?: $s['logo_path']); ?>" alt="" class="fmx-spot-bg" loading="lazy">
            <?php endif; ?>
            <span class="fmx-spot-ribbon">⭐ Featured</span>
            <span class="fmx-spot-live <?php echo $isLive ? 'on' : 'off'; ?>"><span class="fmx-spot-live-dot"></span> <?php echo $isLive ? 'LIVE' : 'Offline'; ?></span>
            <div class="fmx-spot-body">
                <p class="fmx-spot-name"><?php echo sanitize($s['name']); ?></p>
                <p class="fmx-spot-meta">
                    <?php echo $s['frequency'] ? sanitize($s['frequency']) : ''; ?>
                    <?php if ($s['frequency'] && $s['town_name']) echo ' · '; ?>
                    <?php echo $s['town_name'] ? sanitize($s['town_name']) : ''; ?>
                </p>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($gridStations): ?>
    <p class="fmx-count-chip"><?php echo count($gridStations); ?> station<?php echo count($gridStations) === 1 ? '' : 's'; ?><?php echo $spotlight ? ' more' : ''; ?></p>
    <div class="fmx-grid">
        <?php foreach ($gridStations as $idx => $s):
            $isFeatured = (bool)$s['featured'];
            $isLive = fm_station_is_live($s);
            $onAir = $isLive ? fm_get_current_programme((int)$s['id']) : null;
        ?>
        <a href="fm_station.php?slug=<?php echo urlencode($s['slug']); ?>" class="fmx-card fmx-fade<?php echo $isFeatured ? ' fmx-card--featured' : ''; ?>" style="animation-delay:<?php echo min($idx * 0.05, 0.4); ?>s;">
            <?php if ($isFeatured): ?><span class="fmx-feat-badge">⭐ Featured</span><?php endif; ?>
            <div class="fmx-cover">
                <?php if (!empty($s['logo_path'])): ?>
                <img src="<?php echo sanitize($s['logo_path']); ?>" alt="" loading="lazy">
                <?php else: ?>
                <span class="fmx-logo-fallback">📻</span>
                <?php endif; ?>
                <div class="fmx-logo-ring">
                    <?php if (!empty($s['logo_path'])): ?>
                    <img src="<?php echo sanitize($s['logo_path']); ?>" alt="" loading="lazy">
                    <?php else: ?>
                    <span class="fmx-logo-ring-fallback">📻</span>
                    <?php endif; ?>
                </div>
                <?php if ($s['frequency']): ?><span class="fmx-freq-chip"><?php echo sanitize($s['frequency']); ?></span><?php endif; ?>
            </div>
            <div class="fmx-body">
                <p class="fmx-name"><?php echo sanitize($s['name']); ?></p>
                <p class="fmx-meta"><?php echo $s['town_name'] ? '📍 ' . sanitize($s['town_name']) : ''; ?></p>
                <?php if ($onAir): ?><p class="fmx-onair-line">🎙️ <?php echo sanitize($onAir['name']); ?></p><?php endif; ?>
                <span class="fmx-live <?php echo $isLive ? 'on' : 'off'; ?>">
                    <span class="fmx-live-dot"></span> <?php echo $isLive ? 'LIVE NOW' : 'Offline'; ?>
                </span>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; // gridStations ?>
    <?php endif; // !$stations ?>
</main>

<?php require __DIR__ . '/partials/site_footer.php'; ?>
<?php if ($user): require_once __DIR__ . '/partials/bottom_nav.php'; endif; ?>
</body>
</html>
