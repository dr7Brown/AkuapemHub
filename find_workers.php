<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

$searchQuery = trim($_GET['q'] ?? '');
$locationFilter = trim($_GET['location'] ?? '');
$skillFilter = trim($_GET['skill'] ?? '');
$sortBy = $_GET['sort'] ?? 'rating';

$where = ["u.role = 'worker'", "u.banned = 0", "w.id IS NOT NULL"];
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

$sql = "SELECT u.id, u.name, u.created_at, w.location, w.subscription_status, w.availability,
        COALESCE(COUNT(DISTINCT sr.id), 0) AS completed_jobs,
        COALESCE(AVG(r.score), 0) AS avg_rating,
        GROUP_CONCAT(DISTINCT ws.skill_name ORDER BY ws.skill_name SEPARATOR ', ') AS skills
        FROM users u
        LEFT JOIN worker_profiles w ON u.id = w.user_id
        LEFT JOIN worker_skills ws ON w.id = ws.worker_profile_id
        LEFT JOIN service_requests sr ON u.id = sr.assigned_worker_id AND sr.status = 'completed'
        LEFT JOIN ratings r ON sr.id = r.request_id
        WHERE " . implode(' AND ', $where) . "
        GROUP BY u.id, u.name, u.created_at, w.location, w.subscription_status, w.availability
        ORDER BY " . $orderBy . "
        LIMIT 100";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$workers = $stmt->fetchAll();

$allSkills = $pdo->query('SELECT DISTINCT skill_name FROM worker_skills ORDER BY skill_name')->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Find Workers — AkuapemHub</title>
    <link rel="stylesheet" href="assets/css/style.css" />
</head>
<body>
    <header class="topbar">
        <a href="<?php echo current_user() ? 'dashboard.php' : 'index.php'; ?>" class="button button-secondary button-small">Back</a>
        <h1>Find workers</h1>
        <a href="leaderboard.php" class="button button-secondary button-small">Leaderboard</a>
        <?php if (current_user()): ?>
            <a href="logout.php" class="button button-secondary button-small">Logout</a>
        <?php else: ?>
            <a href="login.php" class="button button-secondary button-small">Login</a>
        <?php endif; ?>
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
                </select>
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
                                <h2><?php echo sanitize($worker['name']); ?></h2>
                                <p class="meta"><?php echo sanitize($worker['location'] ?: 'Location not set'); ?> • <?php echo sanitize(ucfirst($worker['subscription_status'])); ?></p>
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
</body>
</html>
