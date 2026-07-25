<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

sweep_expired_featured();
$searchQuery = trim($_GET['q'] ?? '');
$locationFilter = trim($_GET['location'] ?? '');
$skillFilter = trim($_GET['skill'] ?? '');
$sortBy = $_GET['sort'] ?? 'rating';
$userLat = ($_GET['lat'] ?? '') !== '' ? (float)$_GET['lat'] : null;
$userLng = ($_GET['lng'] ?? '') !== '' ? (float)$_GET['lng'] : null;

$where = [
    "u.role = 'worker'",
    "u.banned = 0",
    "w.id IS NOT NULL",
];
if (is_feature_paid('enable_paid_worker_service')) {
    // Only show workers who have paid (active/free) when the listing fee feature is on
    $where[] = "(w.service_fee_status = 'free' OR (w.service_fee_status = 'paid' AND (w.service_fee_expiry IS NULL OR w.service_fee_expiry >= CURDATE())))";
}
$params = [];

if ($searchQuery) {
    $where[] = "(u.name LIKE ? OR w.bio LIKE ? OR w.location LIKE ?)";
    $params[] = '%' . $searchQuery . '%';
    $params[] = '%' . $searchQuery . '%';
    $params[] = '%' . $searchQuery . '%';
}

if ($locationFilter) {
    $where[] = "w.location LIKE ?";
    $params[] = '%' . $locationFilter . '%';
}

if ($skillFilter) {
    $where[] = "EXISTS (SELECT 1 FROM worker_skills wss WHERE wss.worker_profile_id = w.id AND wss.skill_name LIKE ?)";
    $params[] = '%' . $skillFilter . '%';
}

$orderBy = 'u.created_at DESC';
if ($sortBy === 'rating') {
    $orderBy = 'avg_rating DESC, completed_jobs DESC';
} elseif ($sortBy === 'newest') {
    $orderBy = 'u.created_at DESC';
} elseif ($sortBy === 'completed') {
    $orderBy = 'completed_jobs DESC';
}

$sql = "SELECT u.id, u.name, u.username, u.created_at, w.location, w.latitude, w.longitude, w.subscription_status, w.availability,
        w.is_featured, w.featured_end_date, w.is_verified,
        COALESCE(COUNT(DISTINCT sr.id), 0) AS completed_jobs,
        COALESCE(AVG(r.score), 0) AS avg_rating,
        GROUP_CONCAT(DISTINCT ws.skill_name ORDER BY ws.skill_name SEPARATOR ', ') AS skills
        FROM users u
        LEFT JOIN worker_profiles w ON u.id = w.user_id
        LEFT JOIN worker_skills ws ON w.id = ws.worker_profile_id
        LEFT JOIN service_requests sr ON u.id = sr.assigned_worker_id AND sr.status = 'completed'
        LEFT JOIN ratings r ON sr.id = r.request_id
        WHERE " . implode(' AND ', $where) . "
        GROUP BY u.id, u.name, u.username, u.created_at, w.location, w.latitude, w.longitude, w.subscription_status, w.availability, w.is_featured, w.featured_end_date, w.is_verified
        ORDER BY
          (w.is_featured = 1 AND (w.featured_end_date IS NULL OR w.featured_end_date >= CURDATE()) AND w.is_verified = 1) DESC,
          (w.is_featured = 1 AND (w.featured_end_date IS NULL OR w.featured_end_date >= CURDATE())) DESC,
          w.is_verified DESC,
          " . $orderBy . "
        LIMIT 100";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$workers = $stmt->fetchAll();

foreach ($workers as &$worker) {
    $worker['distance_km'] = ($userLat !== null && $userLng !== null)
        ? distance_km($userLat, $userLng, $worker['latitude'], $worker['longitude'])
        : null;
}
unset($worker);

if ($sortBy === 'distance' && $userLat !== null && $userLng !== null) {
    usort($workers, function ($a, $b) {
        if ($a['distance_km'] === null && $b['distance_km'] === null) return 0;
        if ($a['distance_km'] === null) return 1;
        if ($b['distance_km'] === null) return -1;
        return $a['distance_km'] <=> $b['distance_km'];
    });
}

$allSkills = $pdo->query('SELECT DISTINCT skill_name FROM worker_skills ORDER BY skill_name')->fetchAll();
$user = current_user();

// Ajax partial: return only the results grid HTML
if (!empty($_GET['ajax'])) {
    header('Content-Type: text/html; charset=utf-8');
    if (empty($workers)) {
        echo '<div class="empty-state">No workers found.</div>';
    } else {
        foreach ($workers as $worker) {
            $distHtml = $worker['distance_km'] !== null ? ' • ' . htmlspecialchars(format_distance($worker['distance_km']), ENT_QUOTES, 'UTF-8') : '';
            $verBadge = $worker['is_verified']
                ? '<span title="Verified worker" style="display:inline-flex;align-items:center;background:#22a06b;color:#fff;border-radius:4px;padding:1px 6px;font-size:0.78rem;margin-left:4px;vertical-align:middle;"><strong style="font-size:1em;">✓</strong>erified</span>'
                : '';
            $featBadge = ($worker['is_featured'] && (!$worker['featured_end_date'] || $worker['featured_end_date'] >= date('Y-m-d')))
                ? '<span class="badge" style="background:var(--primary);color:#fff;font-size:0.75rem;padding:2px 7px;">Featured</span>'
                : '';
            echo '<article class="request-card">';
            echo '<div class="request-head"><div>';
            echo '<h2>' . htmlspecialchars(display_name($worker), ENT_QUOTES, 'UTF-8') . $verBadge . $featBadge . '</h2>';
            echo '<p class="meta">' . htmlspecialchars($worker['location'] ?: 'Location not set', ENT_QUOTES, 'UTF-8') . $distHtml . '</p>';
            echo '</div>';
            echo '<span class="status status-' . htmlspecialchars($worker['availability'], ENT_QUOTES, 'UTF-8') . '">' . strtoupper(htmlspecialchars($worker['availability'], ENT_QUOTES, 'UTF-8')) . '</span>';
            echo '</div>';
            echo '<div class="request-footer" style="flex-direction:column;gap:8px;align-items:flex-start;">';
            echo '<div style="display:flex;flex-wrap:wrap;gap:8px;">';
            echo '<span>' . (int)$worker['completed_jobs'] . ' jobs completed</span>';
            echo '<span>' . number_format($worker['avg_rating'], 1) . '/5 rating</span>';
            echo '</div>';
            if (!empty($worker['skills'])) {
                echo '<p style="margin:0;font-size:0.95rem;color:#333;">Skills: ' . htmlspecialchars($worker['skills'], ENT_QUOTES, 'UTF-8') . '</p>';
            }
            echo '<a href="worker_profile_public.php?id=' . (int)$worker['id'] . '" class="button button-primary button-small">View profile</a>';
            echo '</div></article>';
        }
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <?php echo seo_meta([
        'title'       => 'Find Skilled Workers in the Akuapem Area, Ghana | ' . APP_NAME,
        'description' => 'Browse verified skilled workers and tradespeople across the Akuapem area of Ghana. Filter by skill, location, and rating to hire the right person for your job.',
        'url'         => rtrim(BASE_URL, '/') . '/find_workers.php',
        'noindex'     => !empty($_GET['q']) || !empty($_GET['skill']) || !empty($_GET['location']),
    ]); ?>
    <link rel="stylesheet" href="assets/css/style.css" />
</head>
<body class="<?php echo $user ? 'has-bottom-nav' : ''; ?>">
    <header class="app-topbar">
        <a href="index.php" style="font-weight:900;color:var(--primary,#0f766e);text-decoration:none;font-size:1.05rem;"><?php echo APP_NAME; ?></a>
        <span class="brand" style="font-size:.88rem;color:var(--muted,#6b7280);">/ Find Workers</span>
        <div style="display: flex; align-items: center; gap: 8px;">
            <a href="leaderboard.php" class="button button-secondary button-small">Leaderboard</a>
            <?php if (!$user): ?>
                <a href="login.php"    class="button button-secondary button-small">Sign in</a>
            <?php endif; ?>
        </div>
    </header>
    <main class="page-shell">
        <style>
            /* Desktop pill bar */
            #wf-bar { display:flex; gap:0; border:2px solid var(--primary); border-radius:40px; overflow:hidden; background:#fff; }
            .wf-field { display:flex; align-items:center; gap:8px; padding:0 14px; border-right:1px solid var(--border); }
            .wf-field input, .wf-field select {
                border:none; outline:none; background:transparent;
                padding:12px 0; width:100%; font-size:0.95rem; cursor:pointer;
            }
            #wf-bar .wf-submit {
                border-radius:0 38px 38px 0; padding:12px 24px; font-size:0.95rem;
                white-space:nowrap; flex-shrink:0; margin:0; border:none;
            }
            /* Mobile: below 640px — break pill into a stacked card */
            @media (max-width: 640px) {
                #wf-bar {
                    flex-direction: column;
                    border-radius: 12px;
                    overflow: visible;
                    border: none;
                    gap: 10px;
                    background: transparent;
                }
                .wf-field {
                    border: 1.5px solid var(--border);
                    border-radius: 10px;
                    background: #fff;
                    padding: 0 14px;
                    border-right: 1.5px solid var(--border);
                }
                #wf-bar .wf-submit {
                    border-radius: 10px;
                    width: 100%;
                    padding: 13px;
                }
                #wf-nearme-row { flex-direction: column; align-items: stretch; }
                #wf-nearme-row button { width: 100%; justify-content: center; }
            }
        </style>
        <section class="panel" style="padding:20px 24px;">
            <form method="get" id="worker-filter-form">
                <input type="hidden" name="lat" id="lat" value="<?php echo $userLat !== null ? sanitize($userLat) : ''; ?>" />
                <input type="hidden" name="lng" id="lng" value="<?php echo $userLng !== null ? sanitize($userLng) : ''; ?>" />

                <div id="wf-bar">
                    <div class="wf-field" style="flex:2;min-width:0;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--text-muted)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        <input type="text" name="q" value="<?php echo sanitize($searchQuery); ?>" placeholder="Search by name or skill…" />
                    </div>
                    <div class="wf-field" style="flex:1;min-width:0;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--text-muted)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                        <input type="text" name="location" value="<?php echo sanitize($locationFilter); ?>" placeholder="Location" />
                    </div>
                    <div class="wf-field" style="flex:1;min-width:0;padding:0 10px;">
                        <select name="skill" style="color:<?php echo $skillFilter ? 'inherit' : 'var(--text-muted)'; ?>;">
                            <option value="">All skills</option>
                            <?php foreach ($allSkills as $skillOption): ?>
                                <option value="<?php echo sanitize($skillOption['skill_name']); ?>" <?php echo $skillFilter === $skillOption['skill_name'] ? 'selected' : ''; ?>><?php echo sanitize($skillOption['skill_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="wf-field" style="flex:0 0 auto;min-width:0;padding:0 10px;">
                        <select name="sort">
                            <option value="rating" <?php echo $sortBy === 'rating' ? 'selected' : ''; ?>>By rating</option>
                            <option value="completed" <?php echo $sortBy === 'completed' ? 'selected' : ''; ?>>Most jobs done</option>
                            <option value="newest" <?php echo $sortBy === 'newest' ? 'selected' : ''; ?>>Newest</option>
                            <option value="distance" <?php echo $sortBy === 'distance' ? 'selected' : ''; ?>>Nearest to me</option>
                        </select>
                    </div>
                    <button type="submit" class="button button-primary wf-submit">Search</button>
                </div>

                <div id="wf-nearme-row" style="display:flex;align-items:center;gap:12px;margin-top:12px;flex-wrap:wrap;">
                    <button type="button" id="find-near-me" class="button button-secondary button-small" style="border-radius:20px;display:flex;align-items:center;gap:6px;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="1"/><line x1="12" y1="2" x2="12" y2="6"/><line x1="12" y1="18" x2="12" y2="22"/><line x1="2" y1="12" x2="6" y2="12"/><line x1="18" y1="12" x2="22" y2="12"/></svg>
                        Find workers near me
                    </button>
                    <span class="meta" id="near-me-status" style="font-size:0.82rem;"><?php echo ($userLat !== null) ? '📍 Showing distances from your shared location.' : ''; ?></span>
                </div>
            </form>
        </section>

        <section class="panel" id="workers-results">
            <?php if (empty($workers)): ?>
                <div class="empty-state">No workers found.</div>
            <?php else: ?>
                <?php foreach ($workers as $worker): ?>
                    <article class="request-card">
                        <div class="request-head">
                            <div>
                                <h2>
                                    <?php echo sanitize(display_name($worker)); ?>
                                    <?php if ($worker['is_verified']): ?>
                                        <span title="Verified worker" style="display:inline-flex;align-items:center;background:#22a06b;color:#fff;border-radius:4px;padding:1px 6px;font-size:0.78rem;margin-left:4px;vertical-align:middle;"><strong style="font-size:1em;">✓</strong>erified</span>
                                    <?php endif; ?>
                                    <?php if ($worker['is_featured'] && (!$worker['featured_end_date'] || $worker['featured_end_date'] >= date('Y-m-d'))): ?>
                                        <span class="badge" style="background:var(--primary);color:#fff;font-size:0.75rem;padding:2px 7px;">Featured</span>
                                    <?php endif; ?>
                                </h2>
                                <p class="meta">
                                    <?php echo sanitize($worker['location'] ?: 'Location not set'); ?>
                                    <?php if ($worker['distance_km'] !== null): ?>
                                        • <?php echo sanitize(format_distance($worker['distance_km'])); ?>
                                    <?php endif; ?>
                                </p>
                            </div>
                            <span class="status status-<?php echo $worker['availability']; ?>"><?php echo strtoupper($worker['availability']); ?></span>
                        </div>
                        <div class="request-footer" style="flex-direction: column; gap: 8px; align-items: flex-start;">
                            <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                                <span><?php echo $worker['completed_jobs']; ?> jobs completed</span>
                                <span><?php echo number_format($worker['avg_rating'], 1); ?>/5 rating</span>
                            </div>
                            <?php if (!empty($worker['skills'])): ?>
                                <p style="margin: 0; font-size: 0.95rem; color: #333;">Skills: <?php echo sanitize($worker['skills']); ?></p>
                            <?php endif; ?>
                            <a href="worker_profile_public.php?id=<?php echo $worker['id']; ?>" class="button button-primary button-small">View profile</a>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </main>
    <?php if ($user): ?>
        <?php $activeNav = 'workers'; require __DIR__ . '/partials/bottom_nav.php'; ?>
    <?php endif; ?>
    <script>
        var resultsSection = document.getElementById('workers-results');
        var filterForm = document.getElementById('worker-filter-form');

        function fetchWorkers(params) {
            resultsSection.style.opacity = '0.45';
            var url = 'find_workers.php?' + params.toString() + '&ajax=1';
            fetch(url)
                .then(function (r) { return r.text(); })
                .then(function (html) {
                    resultsSection.innerHTML = html;
                    resultsSection.style.opacity = '1';
                    // update browser URL (without &ajax=1)
                    var cleanParams = new URLSearchParams(params.toString());
                    cleanParams.delete('ajax');
                    history.pushState({}, '', '?' + cleanParams.toString());
                })
                .catch(function () { resultsSection.style.opacity = '1'; });
        }

        filterForm.addEventListener('submit', function (e) {
            e.preventDefault();
            fetchWorkers(new URLSearchParams(new FormData(filterForm)));
        });

        document.getElementById('find-near-me').addEventListener('click', function () {
            var status = document.getElementById('near-me-status');
            if (!navigator.geolocation) {
                status.textContent = 'Geolocation is not supported by your browser.';
                return;
            }
            status.textContent = 'Locating…';
            navigator.geolocation.getCurrentPosition(function (position) {
                document.getElementById('lat').value = position.coords.latitude;
                document.getElementById('lng').value = position.coords.longitude;
                document.querySelector('select[name="sort"]').value = 'distance';
                status.textContent = '📍 Showing distances from your location.';
                fetchWorkers(new URLSearchParams(new FormData(filterForm)));
            }, function () {
                status.textContent = 'Unable to retrieve your location. Please allow location access.';
            });
        });

        // Handle browser back/forward
        window.addEventListener('popstate', function () {
            var params = new URLSearchParams(window.location.search);
            fetchWorkers(params);
        });
    </script>
</body>
</html>
