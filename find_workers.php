<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

$searchQuery = trim($_GET['q'] ?? '');
$locationFilter = trim($_GET['location'] ?? '');
$skillFilter = trim($_GET['skill'] ?? '');
$sortBy = $_GET['sort'] ?? 'rating';
$userLat = ($_GET['lat'] ?? '') !== '' ? (float)$_GET['lat'] : null;
$userLng = ($_GET['lng'] ?? '') !== '' ? (float)$_GET['lng'] : null;

$where = ["u.role = 'worker'", "u.banned = 0", "w.id IS NOT NULL", "w.service_fee_status != 'pending'"];
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
        ORDER BY (w.is_featured = 1 AND (w.featured_end_date IS NULL OR w.featured_end_date >= CURDATE())) DESC, " . $orderBy . "
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Find Workers — AkuapemHub</title>
    <link rel="stylesheet" href="assets/css/style.css" />
</head>
<body class="<?php echo $user ? 'has-bottom-nav' : ''; ?>">
    <header class="app-topbar">
        <span class="brand"><span class="brand-icon">🔍</span> Find Workers</span>
        <div style="display: flex; align-items: center; gap: 8px;">
            <a href="leaderboard.php" class="button button-secondary button-small">Leaderboard</a>
            <?php if (!$user): ?>
                <a href="login.php" class="button button-primary button-small">Login</a>
            <?php endif; ?>
        </div>
    </header>
    <main class="page-shell">
        <section class="panel">
            <form method="get" class="filter-form" style="flex-direction: column; gap: 14px;">
                <input type="text" name="q" value="<?php echo sanitize($searchQuery); ?>" placeholder="Search by name or bio" />
                <input type="text" name="location" value="<?php echo sanitize($locationFilter); ?>" placeholder="Location" />
                <select name="skill">
                    <option value="">All skills</option>
                    <?php foreach ($allSkills as $skillOption): ?>
                        <option value="<?php echo sanitize($skillOption['skill_name']); ?>" <?php echo $skillFilter === $skillOption['skill_name'] ? 'selected' : ''; ?>><?php echo sanitize($skillOption['skill_name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="sort">
                    <option value="rating" <?php echo $sortBy === 'rating' ? 'selected' : ''; ?>>Sort by rating</option>
                    <option value="completed" <?php echo $sortBy === 'completed' ? 'selected' : ''; ?>>Most completed</option>
                    <option value="newest" <?php echo $sortBy === 'newest' ? 'selected' : ''; ?>>Newest</option>
                    <option value="distance" <?php echo $sortBy === 'distance' ? 'selected' : ''; ?>>Nearest to me</option>
                </select>
                <input type="hidden" name="lat" id="lat" value="<?php echo $userLat !== null ? sanitize($userLat) : ''; ?>" />
                <input type="hidden" name="lng" id="lng" value="<?php echo $userLng !== null ? sanitize($userLng) : ''; ?>" />
                <button type="button" id="find-near-me" class="button button-secondary">Find workers near me</button>
                <p class="meta" id="near-me-status"><?php echo ($userLat !== null) ? 'Showing distances from your shared location.' : 'Tap "Find workers near me" to sort and show distances based on your current location.'; ?></p>
                <button type="submit" class="button button-primary">Search</button>
            </form>
        </section>

        <section class="panel">
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
                document.querySelector('.filter-form').submit();
            }, function () {
                status.textContent = 'Unable to retrieve your location. Please allow location access and try again.';
            });
        });
    </script>
</body>
</html>
