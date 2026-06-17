<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

$workerId = intval($_GET['id'] ?? 0);
if ($workerId <= 0) {
    header('Location: find_workers.php');
    exit;
}

$stmt = $pdo->prepare('
    SELECT u.id, u.name, u.username, u.created_at, u.profile_photo, u.banned,
           w.bio, w.location, w.availability, w.subscription_status,
           w.contact_phone, w.is_featured, w.featured_end_date,
           w.is_verified, w.verification_expiry,
           w.id AS worker_profile_id
    FROM users u
    LEFT JOIN worker_profiles w ON u.id = w.user_id
    WHERE u.id = ? AND u.role = "worker" AND u.banned = 0
');
$stmt->execute([$workerId]);
$worker = $stmt->fetch();

if (!$worker) {
    header('Location: find_workers.php');
    exit;
}

$completedJobs = get_worker_completed_jobs($workerId);
$avgRating = get_worker_average_rating($workerId);

$skillStmt = $pdo->prepare('SELECT ws.skill_name FROM worker_skills ws WHERE ws.worker_profile_id = ?');
$skillStmt->execute([$worker['worker_profile_id']]);
$skills = array_column($skillStmt->fetchAll(), 'skill_name');

$schedule = get_worker_schedule($worker['worker_profile_id']);

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
            <a href="login.php"    class="button button-secondary button-small">Sign in</a>
        <?php endif; ?>
    </header>
    <main class="page-shell small-shell">

        <!-- Profile hero -->
        <section class="panel" style="padding-bottom: 20px;">
            <div style="display:flex;align-items:flex-start;gap:16px;">
                <?php if (!empty($worker['profile_photo'])): ?>
                    <img src="<?php echo sanitize($worker['profile_photo']); ?>" alt="" class="avatar" style="width:64px;height:64px;flex-shrink:0;" />
                <?php else: ?>
                    <span class="avatar" style="width:64px;height:64px;font-size:1.6rem;flex-shrink:0;"><?php echo sanitize(strtoupper(substr(display_name($worker), 0, 1))); ?></span>
                <?php endif; ?>
                <div style="flex:1;min-width:0;">
                    <h2 style="margin:0 0 4px;font-size:1.2rem;display:flex;align-items:center;flex-wrap:wrap;gap:6px;">
                        <?php echo sanitize(display_name($worker)); ?>
                        <?php if ($worker['is_verified']): ?>
                            <span style="display:inline-flex;align-items:center;background:#22a06b;color:#fff;border-radius:4px;padding:1px 7px;font-size:0.8rem;"><strong>✓</strong>erified</span>
                        <?php endif; ?>
                        <?php if ($isActive): ?>
                            <span class="badge" style="background:var(--primary);color:#fff;font-size:0.78rem;">Featured</span>
                        <?php endif; ?>
                    </h2>
                    <p class="meta" style="margin:0 0 6px;">
                        <?php if ($worker['location']): ?><?php echo sanitize($worker['location']); ?> · <?php endif; ?>
                        Member since <?php echo sanitize(date('M Y', strtotime($worker['created_at']))); ?>
                    </p>
                    <span class="status status-<?php echo sanitize($worker['availability']); ?>"><?php echo strtoupper(sanitize($worker['availability'])); ?></span>
                </div>
            </div>

            <!-- Stats row -->
            <div class="stats-grid" style="margin-top:20px;">
                <div class="stat-card">
                    <h2><?php echo $completedJobs; ?></h2>
                    <p>Jobs done</p>
                </div>
                <div class="stat-card">
                    <h2><?php echo number_format($avgRating, 1); ?><small style="font-size:0.65em;font-weight:400;">/5</small></h2>
                    <p>Avg rating</p>
                </div>
                <div class="stat-card">
                    <h2><?php echo count($skills); ?></h2>
                    <p>Skills</p>
                </div>
            </div>

            <!-- Contact buttons -->
            <?php if ($user && $user['id'] !== $workerId): ?>
                <a href="chat_start.php?user_id=<?php echo $workerId; ?>" class="button button-secondary" style="width:100%;margin-top:10px;text-align:center;display:block;">
                    ✉️ Send Message
                </a>
            <?php endif; ?>
        </section>

        <!-- About -->
        <?php if ($worker['bio']): ?>
            <section class="panel">
                <h3 style="margin-top:0;">About</h3>
                <p style="margin:0;line-height:1.6;"><?php echo nl2br(sanitize($worker['bio'])); ?></p>
            </section>
        <?php endif; ?>

        <!-- Skills -->
        <?php if (!empty($skills)): ?>
            <section class="panel">
                <h3 style="margin-top:0;">Skills</h3>
                <div style="display:flex;flex-wrap:wrap;gap:8px;">
                    <?php foreach ($skills as $skill): ?>
                        <span style="background:var(--surface);border:1px solid var(--border);border-radius:20px;padding:4px 12px;font-size:0.87rem;"><?php echo sanitize($skill); ?></span>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <!-- Weekly schedule -->
        <?php if (!empty($schedule)): ?>
            <section class="panel">
                <h3 style="margin-top:0;">Availability schedule</h3>
                <div style="display:flex;flex-direction:column;gap:6px;">
                    <?php foreach (get_weekday_names() as $dayNum => $dayName): ?>
                        <?php if (!empty($schedule[$dayNum])): ?>
                            <div style="display:flex;justify-content:space-between;padding:8px 12px;background:var(--surface);border-radius:8px;font-size:0.9rem;">
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

        <!-- Recent work -->
        <?php if (!empty($recentJobs)): ?>
            <section class="panel">
                <h3 style="margin-top:0;">Recent completed work</h3>
                <div style="display:flex;flex-direction:column;gap:10px;">
                    <?php foreach ($recentJobs as $job): ?>
                        <div style="padding:12px;background:var(--surface);border-radius:10px;border:1px solid var(--border);">
                            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;">
                                <div>
                                    <strong style="font-size:0.95rem;"><?php echo sanitize(substr($job['title'], 0, 50)); ?></strong>
                                    <p class="meta" style="margin:2px 0 0;"><?php echo sanitize($job['category_name']); ?></p>
                                </div>
                                <?php if ($job['rating_score']): ?>
                                    <span style="background:#fef9c3;color:#92400e;border-radius:5px;padding:2px 8px;font-size:0.85rem;white-space:nowrap;flex-shrink:0;">★ <?php echo sanitize($job['rating_score']); ?>/5</span>
                                <?php endif; ?>
                            </div>
                            <?php if ($job['comment']): ?>
                                <p style="margin:8px 0 0;font-size:0.87rem;color:var(--text-muted);font-style:italic;">"<?php echo sanitize($job['comment']); ?>"</p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

    </main>
    <?php if ($user): ?>
        <?php $activeNav = 'workers'; require __DIR__ . '/partials/bottom_nav.php'; ?>
    <?php endif; ?>
</body>
</html>
