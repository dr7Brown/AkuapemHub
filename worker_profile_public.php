<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

$workerId = intval($_GET['id'] ?? 0);
if ($workerId <= 0) {
    render_not_found('find_workers.php', 'Find Workers', 'Worker not found.');
}

$stmt = $pdo->prepare('
    SELECT u.id, u.name, u.username, u.created_at, u.profile_photo, u.banned,
           w.bio, w.location, w.availability, w.subscription_status,
           w.contact_phone, w.is_featured, w.featured_end_date,
           w.is_verified, w.verification_expiry, w.view_count,
           w.id AS worker_profile_id
    FROM users u
    LEFT JOIN worker_profiles w ON u.id = w.user_id
    WHERE u.id = ? AND u.role = "worker" AND u.banned = 0
');
$stmt->execute([$workerId]);
$worker = $stmt->fetch();

if (!$worker) {
    render_not_found('find_workers.php', 'Find Workers', 'This profile is no longer available.');
}

if (empty($_SESSION['viewed_worker_profile'][$worker['worker_profile_id']])) {
    $pdo->prepare("UPDATE worker_profiles SET view_count=view_count+1 WHERE id=?")->execute([$worker['worker_profile_id']]);
    $_SESSION['viewed_worker_profile'][$worker['worker_profile_id']] = true;
}

$completedJobs = get_worker_completed_jobs($workerId);
$avgRating = get_worker_average_rating($workerId);

$skillStmt = $pdo->prepare('SELECT ws.skill_name FROM worker_skills ws WHERE ws.worker_profile_id = ?');
$skillStmt->execute([$worker['worker_profile_id']]);
$skills = array_column($skillStmt->fetchAll(), 'skill_name');

$schedule = get_worker_schedule($worker['worker_profile_id']);

// Portfolio — showcased past projects, each with its own photo gallery.
$portfolioItems = $pdo->prepare(
    'SELECT id, title, description FROM worker_portfolio_items WHERE worker_profile_id=? ORDER BY sort_order ASC, created_at DESC'
);
$portfolioItems->execute([$worker['worker_profile_id']]);
$portfolioItems = $portfolioItems->fetchAll();

$portfolioImages = [];
if ($portfolioItems) {
    $pIds = array_column($portfolioItems, 'id');
    $pPlaceholders = implode(',', array_fill(0, count($pIds), '?'));
    $pImgSt = $pdo->prepare("SELECT * FROM worker_portfolio_images WHERE item_id IN ($pPlaceholders) ORDER BY is_primary DESC, sort_order ASC");
    $pImgSt->execute($pIds);
    foreach ($pImgSt->fetchAll() as $img) {
        $portfolioImages[$img['item_id']][] = $img['image_path'];
    }
}

$recentStmt = $pdo->prepare('
    SELECT sr.title, c.name AS category_name, r.score AS rating_score, r.comment, sr.updated_at
    FROM service_requests sr
    JOIN service_categories c ON sr.category_id = c.id
    LEFT JOIN ratings r ON sr.id = r.request_id AND r.worker_id = sr.assigned_worker_id
    WHERE sr.assigned_worker_id = ? AND sr.status = "completed"
    ORDER BY sr.updated_at DESC LIMIT 8
');
$recentStmt->execute([$workerId]);
$recentJobs = $recentStmt->fetchAll();

$isActive = $worker['is_featured'] && (!$worker['featured_end_date'] || $worker['featured_end_date'] >= date('Y-m-d'));
$user = current_user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?php echo sanitize(display_name($worker)); ?> — AkuapemConnect</title>
    <link rel="stylesheet" href="assets/css/style.css" />
</head>
<body class="<?php echo $user ? 'has-bottom-nav' : ''; ?>">
    <header class="app-topbar">
        <a href="javascript:history.back()" class="button button-secondary button-small">← Back</a>
        <span class="brand">Worker Profile</span>
        <?php if (!$user): ?>
            <a href="login.php?redirect=<?php echo urlencode(current_request_path()); ?>" class="button button-secondary button-small">Sign in</a>
        <?php endif; ?>
    </header>
    <main class="page-shell small-shell">

        <!-- Profile hero -->
        <section class="wpp-hero">
            <div class="wpp-hero-top">
                <?php if (!empty($worker['profile_photo'])): ?>
                    <img src="<?php echo sanitize($worker['profile_photo']); ?>" alt="" class="avatar avatar-lg wpp-avatar" />
                <?php else: ?>
                    <span class="avatar avatar-lg wpp-avatar wpp-avatar-fallback"><?php echo sanitize(strtoupper(substr(display_name($worker), 0, 1))); ?></span>
                <?php endif; ?>
                <div class="wpp-identity">
                    <h1 class="wpp-name">
                        <?php echo sanitize(display_name($worker)); ?>
                        <?php if ($worker['is_verified']): ?>
                            <span class="wpp-verified-pill"><strong>✓</strong> Verified</span>
                        <?php endif; ?>
                        <?php if ($isActive): ?>
                            <span class="wpp-featured-pill">⭐ Featured</span>
                        <?php endif; ?>
                    </h1>
                    <p class="wpp-meta">
                        <?php if ($worker['location']): ?><?php echo sanitize($worker['location']); ?> · <?php endif; ?>
                        Member since <?php echo sanitize(date('M Y', strtotime($worker['created_at']))); ?>
                    </p>
                    <span class="status status-<?php echo sanitize($worker['availability']); ?>"><?php echo strtoupper(sanitize($worker['availability'])); ?></span>
                </div>
            </div>

            <?php if ($user && $user['id'] !== $workerId): ?>
                <a href="chat_start.php?user_id=<?php echo $workerId; ?>" class="button wpp-message-btn">
                    ✉️ Send Message
                </a>
            <?php endif; ?>
        </section>

        <!-- Stats row -->
        <div class="stats-grid wpp-stats">
            <div class="stat-card">
                <h2><?php echo $completedJobs; ?></h2>
                <p>💼 Jobs done</p>
            </div>
            <div class="stat-card">
                <h2><?php echo number_format($avgRating, 1); ?><small style="font-size:0.6em;font-weight:400;"> /5</small></h2>
                <p>⭐ Avg rating</p>
            </div>
            <div class="stat-card">
                <h2><?php echo count($skills); ?></h2>
                <p>🛠️ Skills</p>
            </div>
            <div class="stat-card">
                <h2><?php echo number_format((int)$worker['view_count']); ?></h2>
                <p>👁️ Views</p>
            </div>
        </div>

        <!-- About -->
        <?php if ($worker['bio']): ?>
            <section class="panel">
                <h3 class="wpp-section-title">👤 About</h3>
                <div class="wpp-bio"><?php echo render_rich($worker['bio']); ?></div>
            </section>
        <?php endif; ?>

        <!-- Skills -->
        <?php if (!empty($skills)): ?>
            <section class="panel">
                <h3 class="wpp-section-title">🛠️ Skills</h3>
                <div class="wpp-skill-list">
                    <?php foreach ($skills as $skill): ?>
                        <span class="wpp-skill-chip"><?php echo sanitize($skill); ?></span>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <!-- Weekly schedule -->
        <?php if (!empty($schedule)): ?>
            <section class="panel">
                <h3 class="wpp-section-title">🗓️ Availability schedule</h3>
                <div class="wpp-schedule">
                    <?php foreach (get_weekday_names() as $dayNum => $dayName): ?>
                        <?php if (!empty($schedule[$dayNum])): ?>
                            <div class="wpp-schedule-row">
                                <strong><?php echo sanitize($dayName); ?></strong>
                                <span class="meta"><?php echo sanitize(implode(', ', array_map(function ($s) {
                                    return format_time_range($s['start_time'], $s['end_time']);
                                }, $schedule[$dayNum]))); ?></span>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <!-- Portfolio -->
        <?php if (!empty($portfolioItems)): ?>
            <section class="panel">
                <h3 class="wpp-section-title">🛠️ Portfolio</h3>
                <div class="wpp-portfolio-grid">
                    <?php foreach ($portfolioItems as $it):
                        $itImages = $portfolioImages[$it['id']] ?? [];
                        $itData = json_encode([
                            'title'       => $it['title'],
                            'description' => trim(strip_tags(render_rich($it['description'] ?? ''))),
                            'images'      => $itImages,
                        ], JSON_UNESCAPED_UNICODE);
                    ?>
                    <button type="button" class="wpp-portfolio-card" onclick='wppOpenPortfolio(<?php echo htmlspecialchars($itData, ENT_QUOTES, 'UTF-8'); ?>)'>
                        <span class="wpp-portfolio-img">
                            <?php if ($itImages): ?><img src="<?php echo sanitize($itImages[0]); ?>" alt="<?php echo sanitize($it['title']); ?>">
                            <?php else: ?><span class="wpp-portfolio-fallback">🛠️</span><?php endif; ?>
                        </span>
                        <span class="wpp-portfolio-title"><?php echo sanitize($it['title']); ?></span>
                    </button>
                    <?php endforeach; ?>
                </div>
            </section>

            <div class="wpp-lightbox" id="wpp-lightbox" onclick="if(event.target===this) wppClosePortfolio()">
                <div class="wpp-lightbox-box">
                    <button type="button" class="wpp-lightbox-close" onclick="wppClosePortfolio()">×</button>
                    <div class="wpp-lightbox-img" id="wpp-lightbox-main"></div>
                    <div class="wpp-lightbox-thumbs" id="wpp-lightbox-thumbs"></div>
                    <h3 id="wpp-lightbox-title"></h3>
                    <p id="wpp-lightbox-desc" class="meta"></p>
                </div>
            </div>
        <?php endif; ?>

        <!-- Recent work -->
        <?php if (!empty($recentJobs)): ?>
            <section class="panel">
                <h3 class="wpp-section-title">📋 Recent completed work</h3>
                <div class="wpp-job-list">
                    <?php foreach ($recentJobs as $job): ?>
                        <div class="wpp-job-card">
                            <div class="wpp-job-head">
                                <div>
                                    <strong class="wpp-job-title"><?php echo sanitize(substr($job['title'], 0, 50)); ?></strong>
                                    <p class="meta" style="margin:2px 0 0;"><?php echo sanitize($job['category_name']); ?></p>
                                </div>
                                <?php if ($job['rating_score']): ?>
                                    <span class="wpp-job-rating">★ <?php echo sanitize($job['rating_score']); ?>/5</span>
                                <?php endif; ?>
                            </div>
                            <?php if ($job['comment']): ?>
                                <p class="wpp-job-comment">"<?php echo sanitize($job['comment']); ?>"</p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

    </main>
    <style>
        .wpp-hero {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            padding: var(--space-4);
            margin: var(--space-3) 0 var(--space-3);
            color: #fff;
        }
        .wpp-hero-top { display:flex; align-items:center; gap:16px; }
        .wpp-avatar { border: 3px solid rgba(255,255,255,.65); box-shadow: 0 4px 14px rgba(0,0,0,.18); }
        .wpp-avatar-fallback { background: rgba(255,255,255,.18); color:#fff; }
        .wpp-identity { flex:1; min-width:0; }
        .wpp-name { margin:0 0 6px; font-size:1.35rem; display:flex; align-items:center; flex-wrap:wrap; gap:8px; color:#fff; }
        .wpp-verified-pill { display:inline-flex; align-items:center; gap:3px; background:rgba(255,255,255,.2); border:1px solid rgba(255,255,255,.4); color:#fff; border-radius:999px; padding:2px 10px; font-size:0.72rem; font-weight:700; letter-spacing:.02em; }
        .wpp-featured-pill { display:inline-flex; align-items:center; background:var(--secondary); color:#fff; border-radius:999px; padding:2px 10px; font-size:0.72rem; font-weight:700; letter-spacing:.02em; }
        .wpp-meta { margin:0 0 10px; color:rgba(255,255,255,.85); font-size:0.88rem; }
        .wpp-message-btn { width:100%; margin-top:16px; text-align:center; display:block; background:#fff; color:var(--primary-dark); font-weight:700; border:none; }
        .wpp-message-btn:hover { background:rgba(255,255,255,.92); text-decoration:none; }
        .stats-grid.wpp-stats { margin-bottom: var(--space-3); grid-template-columns: 1fr 1fr; }
        .stats-grid.wpp-stats .stat-card p { white-space: nowrap; }
        .wpp-section-title { margin:0 0 14px; font-size:1.02rem; }
        .wpp-bio { line-height:1.7; color:var(--text); }
        .wpp-skill-list { display:flex; flex-wrap:wrap; gap:8px; }
        .wpp-skill-chip { background:var(--primary-soft); color:var(--primary-dark); border-radius:999px; padding:6px 14px; font-size:0.87rem; font-weight:600; }
        .wpp-schedule { display:flex; flex-direction:column; gap:8px; }
        .wpp-schedule-row { display:flex; justify-content:space-between; align-items:center; padding:10px 14px; background:var(--surface-muted); border-radius:var(--radius-sm); font-size:0.9rem; }
        .wpp-job-list { display:flex; flex-direction:column; gap:12px; }
        .wpp-job-card { padding:14px 16px; background:var(--surface-muted); border-radius:var(--radius-sm); border-left:3px solid var(--primary); }
        .wpp-job-head { display:flex; justify-content:space-between; align-items:flex-start; gap:8px; }
        .wpp-job-title { font-size:0.95rem; }
        .wpp-job-rating { background:#fef9c3; color:#92400e; border-radius:6px; padding:3px 9px; font-size:0.85rem; font-weight:700; white-space:nowrap; flex-shrink:0; }
        .wpp-job-comment { margin:8px 0 0; font-size:0.87rem; color:var(--muted); font-style:italic; }
        .wpp-portfolio-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(120px,1fr)); gap:10px; }
        .wpp-portfolio-card { display:flex; flex-direction:column; gap:6px; background:none; border:none; padding:0; cursor:pointer; text-align:left; font:inherit; color:inherit; }
        .wpp-portfolio-img { aspect-ratio:1/1; border-radius:var(--radius-sm); overflow:hidden; background:var(--surface-muted); display:flex; align-items:center; justify-content:center; }
        .wpp-portfolio-img img { width:100%; height:100%; object-fit:cover; }
        .wpp-portfolio-fallback { font-size:1.8rem; opacity:.35; }
        .wpp-portfolio-title { font-size:.82rem; font-weight:700; line-height:1.35; overflow:hidden; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; }
        .wpp-lightbox { display:none; position:fixed; inset:0; background:rgba(0,0,0,.75); z-index:1000; align-items:center; justify-content:center; padding:16px; }
        .wpp-lightbox.open { display:flex; }
        .wpp-lightbox-box { background:var(--surface,#fff); border-radius:var(--radius-lg); padding:18px; max-width:520px; width:100%; max-height:90vh; overflow-y:auto; position:relative; }
        .wpp-lightbox-close { position:absolute; top:10px; right:10px; width:30px; height:30px; border-radius:50%; border:none; background:var(--surface-muted,#f1f5f9); font-size:1.1rem; cursor:pointer; line-height:1; }
        .wpp-lightbox-img { aspect-ratio:4/3; background:var(--surface-muted); border-radius:var(--radius-sm); overflow:hidden; display:flex; align-items:center; justify-content:center; margin-bottom:8px; }
        .wpp-lightbox-img img { width:100%; height:100%; object-fit:contain; }
        .wpp-lightbox-thumbs { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:12px; }
        .wpp-lightbox-thumbs img { width:48px; height:48px; object-fit:cover; border-radius:6px; border:2px solid transparent; cursor:pointer; }
        .wpp-lightbox-thumbs img.active { border-color:var(--primary); }
        #wpp-lightbox-title { margin:0 0 6px; font-size:1.05rem; }
        @media (max-width:480px) {
            .wpp-hero-top { flex-direction:column; text-align:center; }
            .wpp-name { justify-content:center; }
        }
    </style>
    <script>
        function wppOpenPortfolio(data) {
            var lb = document.getElementById('wpp-lightbox');
            document.getElementById('wpp-lightbox-title').textContent = data.title;
            document.getElementById('wpp-lightbox-desc').textContent = data.description || '';
            var main   = document.getElementById('wpp-lightbox-main');
            var thumbs = document.getElementById('wpp-lightbox-thumbs');
            main.innerHTML = '';
            thumbs.innerHTML = '';
            var images = data.images && data.images.length ? data.images : [];
            if (!images.length) {
                main.innerHTML = '<span style="font-size:2.5rem;opacity:.3;">🛠️</span>';
            } else {
                var mainImg = document.createElement('img');
                mainImg.src = images[0];
                main.appendChild(mainImg);
                if (images.length > 1) {
                    images.forEach(function (src, i) {
                        var t = document.createElement('img');
                        t.src = src;
                        if (i === 0) t.classList.add('active');
                        t.addEventListener('click', function () {
                            mainImg.src = src;
                            thumbs.querySelectorAll('img').forEach(function (x) { x.classList.remove('active'); });
                            t.classList.add('active');
                        });
                        thumbs.appendChild(t);
                    });
                }
            }
            lb.classList.add('open');
        }
        function wppClosePortfolio() {
            document.getElementById('wpp-lightbox').classList.remove('open');
        }
    </script>
    <?php require __DIR__ . '/partials/site_footer.php'; ?>
    <?php if ($user): ?>
        <?php $activeNav = 'workers'; require __DIR__ . '/partials/bottom_nav.php'; ?>
    <?php endif; ?>
</body>
</html>
