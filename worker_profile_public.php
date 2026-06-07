<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

$workerId = intval($_GET['id'] ?? 0);
if ($workerId <= 0) {
    header('Location: dashboard.php');
    exit;
}

$stmt = $pdo->prepare('SELECT u.id, u.name, u.created_at, u.banned, w.* FROM users u LEFT JOIN worker_profiles w ON u.id = w.user_id WHERE u.id = ? AND u.role = "worker" AND u.banned = 0');
$stmt->execute([$workerId]);
$worker = $stmt->fetch();

if (!$worker) {
    header('Location: dashboard.php');
    exit;
}

$completedJobs = get_worker_completed_jobs($workerId);
$avgRating = get_worker_average_rating($workerId);

$stmt = $pdo->prepare('SELECT ws.skill_name FROM worker_skills ws WHERE ws.worker_profile_id = ?');
$stmt->execute([$worker['id']]);
$skills = array_column($stmt->fetchAll(), 'skill_name');

$stmt = $pdo->prepare('SELECT sr.*, c.name AS category_name, r.score AS rating_score FROM service_requests sr JOIN service_categories c ON sr.category_id = c.id LEFT JOIN ratings r ON sr.id = r.request_id AND r.worker_id = sr.assigned_worker_id WHERE sr.assigned_worker_id = ? AND sr.status = "completed" ORDER BY sr.updated_at DESC LIMIT 10');
$stmt->execute([$workerId]);
$recentJobs = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?php echo sanitize($worker['name']); ?> — AkuapemHub</title>
    <link rel="stylesheet" href="assets/css/style.css" />
</head>
<body>
    <header class="topbar">
        <a href="<?php echo current_user() ? 'dashboard.php' : 'index.php'; ?>" class="button button-secondary button-small">Back</a>
        <h1>Worker profile</h1>
        <?php if (current_user()): ?>
            <a href="logout.php" class="button button-secondary button-small">Logout</a>
        <?php else: ?>
            <a href="login.php" class="button button-secondary button-small">Login</a>
        <?php endif; ?>
    </header>
    <main class="page-shell">
        <section class="panel">
            <div class="request-head">
                <div>
                    <h2><?php echo sanitize($worker['name']); ?></h2>
                    <p class="meta">Member since <?php echo sanitize(date('M Y', strtotime($worker['created_at']))); ?></p>
                </div>
            </div>
            
            <div style="margin: 20px 0;">
                <p><strong>Location:</strong> <?php echo sanitize($worker['location'] ?: 'Not specified'); ?></p>
                <p><strong>Availability:</strong> <?php echo sanitize(ucfirst($worker['availability'])); ?></p>
                <p><strong>Subscription:</strong> <?php echo sanitize(ucfirst($worker['subscription_status'])); ?></p>
                <p><strong>Contact:</strong> <a href="<?php echo whatsapp_contact_link($worker['contact_phone'], 'Hi'); ?>" target="_blank" class="button button-secondary button-small">Contact via WhatsApp</a></p>
            </div>

            <?php if ($worker['bio']): ?>
                <div style="margin: 20px 0; padding: 16px; background: #f9fafb; border-radius: 12px;">
                    <h3 style="margin-top: 0;">About</h3>
                    <p><?php echo nl2br(sanitize($worker['bio'])); ?></p>
                </div>
            <?php endif; ?>

            <?php if (!empty($skills)): ?>
                <div style="margin: 20px 0; padding: 16px; background: #f9fafb; border-radius: 12px;">
                    <h3 style="margin-top: 0;">Skills</h3>
                    <p><?php echo sanitize(implode(', ', $skills)); ?></p>
                </div>
            <?php endif; ?>

            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; margin: 20px 0;">
                <div style="padding: 16px; background: #d1fae5; border-radius: 12px; text-align: center;">
                    <h3 style="margin: 0 0 8px; font-size: 1.8rem;"><?php echo $completedJobs; ?></h3>
                    <p style="margin: 0; color: #166534;">Completed jobs</p>
                </div>
                <div style="padding: 16px; background: #fef9c3; border-radius: 12px; text-align: center;">
                    <h3 style="margin: 0 0 8px; font-size: 1.8rem;"><?php echo number_format($avgRating, 1); ?>/5</h3>
                    <p style="margin: 0; color: #92400e;">Average rating</p>
                </div>
            </div>

            <?php if (!empty($recentJobs)): ?>
                <div style="margin-top: 24px;">
                    <h3>Recent completed work</h3>
                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th>Job</th>
                                    <th>Category</th>
                                    <th>Rating</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentJobs as $job): ?>
                                    <tr>
                                        <td><?php echo sanitize(substr($job['title'], 0, 40)); ?></td>
                                        <td><?php echo sanitize($job['category_name']); ?></td>
                                        <td><?php echo $job['rating_score'] ? sanitize($job['rating_score']) . '/5' : '—'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
