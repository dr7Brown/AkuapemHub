<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_login();
$user = current_user();
$flash = get_flash();
$categories = get_categories();
$notificationCount = get_unread_notifications_count($user['id']);

$categoryFilter = $_GET['category'] ?? '';
$locationFilter = $_GET['location'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$searchQuery = trim($_GET['q'] ?? '');

$where = [];
$params = [];

if ($categoryFilter) {
    $where[] = 'sr.category_id = ?';
    $params[] = $categoryFilter;
}
if ($locationFilter) {
    $where[] = 'sr.location LIKE ?';
    $params[] = '%' . $locationFilter . '%';
}
if ($statusFilter) {
    $where[] = 'sr.status = ?';
    $params[] = $statusFilter;
}
if ($searchQuery) {
    $where[] = '(sr.title LIKE ? OR sr.description LIKE ? OR wc.name LIKE ? OR sr.location LIKE ?)';
    $params[] = '%' . $searchQuery . '%';
    $params[] = '%' . $searchQuery . '%';
    $params[] = '%' . $searchQuery . '%';
    $params[] = '%' . $searchQuery . '%';
}

if (is_worker()) {
    $sql = 'SELECT sr.*, u.name AS customer_name, wc.name AS category_name, w.user_id AS worker_user_id 
            FROM service_requests sr
            JOIN users u ON sr.customer_id = u.id
            JOIN service_categories wc ON sr.category_id = wc.id
            LEFT JOIN worker_profiles w ON sr.assigned_worker_id = w.user_id';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY sr.created_at DESC LIMIT 100';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $requests = $stmt->fetchAll();

    $stmt = $pdo->prepare('SELECT * FROM worker_profiles WHERE user_id = ?');
    $stmt->execute([$user['id']]);
    $profile = $stmt->fetch();
    $workerCounts = get_worker_request_counts($user['id']);
    $availableJobs = get_open_jobs_count();
    $workerEarnings = get_paid_total_by_worker($user['id']);

    $workerSkillsCsv = '';
    if ($profile) {
        $skillRows = $pdo->prepare('SELECT skill_name FROM worker_skills WHERE worker_profile_id = ?');
        $skillRows->execute([$profile['id']]);
        $workerSkillsCsv = implode(', ', array_column($skillRows->fetchAll(), 'skill_name'));
    }
    $matchContext = [
        'latitude' => $profile['latitude'] ?? null,
        'longitude' => $profile['longitude'] ?? null,
        'skills' => $workerSkillsCsv,
    ];

    $openJobs = [];
    $myJobs = [];
    foreach ($requests as $request) {
        if ($request['status'] === 'open') {
            $openJobs[] = $request;
        } elseif ($request['assigned_worker_id'] === $user['id']) {
            $myJobs[] = $request;
        }
    }
    $openJobs = rank_jobs_for_worker($openJobs, $matchContext);

    $myApplicationStatuses = [];
    $appStmt = $pdo->prepare('SELECT request_id, status FROM applications WHERE worker_id = ? ORDER BY applied_at ASC');
    $appStmt->execute([$user['id']]);
    foreach ($appStmt->fetchAll() as $appRow) {
        $myApplicationStatuses[$appRow['request_id']] = $appRow['status'];
    }

} elseif (is_customer()) {
    $stmt = $pdo->prepare('SELECT sr.*, wc.name AS category_name, u.name AS assigned_worker_name, r.score AS rating_score, r.comment AS rating_comment 
        FROM service_requests sr
        JOIN service_categories wc ON sr.category_id = wc.id
        LEFT JOIN users u ON sr.assigned_worker_id = u.id
        LEFT JOIN ratings r ON sr.id = r.request_id AND r.customer_id = sr.customer_id
        WHERE sr.customer_id = ?
        ORDER BY sr.created_at DESC');
    $stmt->execute([$user['id']]);
    $requests = $stmt->fetchAll();
    $customerCounts = get_request_status_counts($user['id']);
    $customerSpent = get_customer_spending_total($user['id']);

} else {
    header('Location: admin/index.php');
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Home — AkuapemHub</title>
    <link rel="stylesheet" href="assets/css/style.css" />
</head>
<body class="has-bottom-nav">
    <header class="app-topbar">
        <span class="brand"><span class="brand-icon">🏠</span> AkuapemHub</span>
        <div style="display: flex; align-items: center; gap: 10px;">
            <a href="notifications.php" class="bottom-nav-item" style="flex: none; flex-direction: row; gap: 6px; color: var(--text);">
                <span class="nav-icon<?php echo $notificationCount ? ' nav-badge' : ''; ?>" <?php echo $notificationCount ? 'data-count="' . (int)$notificationCount . '"' : ''; ?>>🔔</span>
            </a>
            <?php if (!empty($user['profile_photo'])): ?>
                <img src="<?php echo sanitize($user['profile_photo']); ?>" alt="Profile picture" class="avatar" />
            <?php else: ?>
                <span class="avatar"><?php echo sanitize(strtoupper(substr($user['name'], 0, 1))); ?></span>
            <?php endif; ?>
        </div>
    </header>
    <main class="page-shell">
        <?php if ($flash): ?>
            <div class="alert alert-<?php echo sanitize($flash['type']); ?>"><?php echo sanitize($flash['message']); ?></div>
        <?php endif; ?>

        <section class="hero-card">
            <p class="meta" style="color: rgba(255,255,255,0.85); margin-bottom: 4px;">👋 Hello, <?php echo sanitize($user['name']); ?></p>
            <?php if (is_worker()): ?>
                <h1>Find trusted jobs near you in Akuapem</h1>
                <a href="#open-jobs" class="button button-primary">Browse open jobs</a>
            <?php else: ?>
                <h1>Find trusted workers for any job in Akuapem</h1>
                <a href="request.php" class="button button-primary">Post a Job</a>
            <?php endif; ?>
        </section>

        <h2 style="margin: 0 0 12px;">Popular categories</h2>
        <div class="category-grid" id="category-grid">
            <?php foreach ($categories as $index => $category): ?>
                <a href="<?php echo is_worker() ? 'dashboard.php?category=' . $category['id'] : 'find_workers.php?category=' . $category['id']; ?>" class="chip-pick<?php echo $index >= 5 ? ' category-extra' : ''; ?>"<?php echo $index >= 5 ? ' hidden' : ''; ?>>
                    <span class="chip-icon"><?php echo category_icon($category['name']); ?></span>
                    <span><?php echo sanitize($category['name']); ?></span>
                </a>
            <?php endforeach; ?>
        </div>
        <?php if (count($categories) > 5): ?>
            <button type="button" id="toggle-categories" class="button button-secondary button-small" style="margin-bottom: var(--space-4);" data-expanded="0">View all categories</button>
        <?php endif; ?>

        <?php if (is_worker()): ?>
            <section class="panel" id="open-jobs">
                <div class="stats-grid">
                    <div class="stat-card">
                        <h2><?php echo $availableJobs; ?></h2>
                        <p>Jobs available</p>
                    </div>
                    <div class="stat-card">
                        <h2><?php echo $workerCounts['in_progress']; ?></h2>
                        <p>Jobs in progress</p>
                    </div>
                    <div class="stat-card">
                        <h2><?php echo $workerCounts['completed']; ?></h2>
                        <p>Completed jobs</p>
                    </div>
                    <div class="stat-card">
                        <h2>GH₵ <?php echo number_format($workerEarnings, 2); ?></h2>
                        <p>Paid earnings</p>
                    </div>
                </div>
                <div class="panel-header">
                    <h1>Jobs for you</h1>
                    <form method="get" class="filter-form">
                        <select name="category">
                            <option value="">All categories</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo $category['id']; ?>" <?php echo $categoryFilter == $category['id'] ? 'selected' : ''; ?>><?php echo sanitize($category['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" name="q" value="<?php echo sanitize($searchQuery); ?>" placeholder="Search jobs" />
                        <input type="text" name="location" value="<?php echo sanitize($locationFilter); ?>" placeholder="Location" />
                        <button type="submit" class="button button-primary">Filter</button>
                    </form>
                </div>
                <?php if ($openJobs): ?>
                    <p class="small-note" style="text-align: left; margin-bottom: 14px;">🎯 Open jobs are ranked by how well they match your skills, location, and recent activity.</p>
                <?php endif; ?>
                <?php $displayJobs = array_merge($openJobs, $myJobs); ?>
                <?php if (!$displayJobs): ?>
                    <div class="empty-state">No jobs match your filters.</div>
                <?php else: ?>
                    <?php foreach ($displayJobs as $request): ?>
                        <?php $jobDistance = $request['match_distance_km'] ?? distance_km($profile['latitude'] ?? null, $profile['longitude'] ?? null, $request['latitude'], $request['longitude']); ?>
                        <article class="request-card">
                            <div class="request-head">
                                <div>
                                    <h2><?php echo sanitize($request['title']); ?></h2>
                                    <?php if ($request['featured']): ?>
                                        <span class="badge badge-featured">Featured</span>
                                    <?php endif; ?>
                                    <p class="meta">
                                        <?php echo sanitize($request['category_name']); ?> • <?php echo sanitize($request['location']); ?>
                                        <?php if ($jobDistance !== null): ?>
                                            • <?php echo sanitize(format_distance($jobDistance)); ?>
                                        <?php endif; ?>
                                    </p>
                                    <?php if (isset($request['match_score'])): ?>
                                        <p class="meta match-meta">🎯 <?php echo (int)$request['match_score']; ?>% match for you<?php if (!empty($request['match_reasons'])): ?> — <?php echo sanitize(implode(' • ', $request['match_reasons'])); ?><?php endif; ?></p>
                                    <?php endif; ?>
                                </div>
                                <span class="status status-<?php echo sanitize($request['status']); ?>"><?php echo strtoupper(str_replace('_', ' ', $request['status'])); ?></span>
                            </div>
                            <p><?php echo sanitize($request['description']); ?></p>
                            <div class="request-footer">
                                <span>Budget: GH₵ <?php echo sanitize($request['budget']); ?></span>
                                <div class="button-group">
                                    <a href="<?php echo whatsapp_share_link($request['title'], $request['location'], $request['budget'], BASE_URL . '/dashboard.php'); ?>" target="_blank" class="button button-secondary button-small">Share WhatsApp</a>
                                    <?php $contactUrl = whatsapp_contact_link($request['contact_info'], $request['title']); ?>
                                    <?php if ($contactUrl): ?>
                                        <a href="<?php echo $contactUrl; ?>" target="_blank" class="button button-secondary button-small">Contact via WhatsApp</a>
                                    <?php endif; ?>
                                    <a href="request_detail.php?id=<?php echo $request['id']; ?>" class="button button-secondary button-small">Details</a>
                                    <?php if ($request['status'] === 'open'): ?>
                                        <?php $myAppStatus = $myApplicationStatuses[$request['id']] ?? null; ?>
                                        <?php if ($myAppStatus === 'pending'): ?>
                                            <span class="button button-secondary button-small" style="opacity: 0.7; cursor: default;">Application pending review</span>
                                        <?php elseif ($myAppStatus === 'declined'): ?>
                                            <span class="button button-secondary button-small" style="opacity: 0.7; cursor: default;">Application declined</span>
                                        <?php else: ?>
                                            <form method="post" action="apply_job.php">
                                                <input type="hidden" name="request_id" value="<?php echo $request['id']; ?>" />
                                                <button type="submit" class="button button-primary">Apply for this job</button>
                                            </form>
                                        <?php endif; ?>
                                    <?php elseif ($request['status'] === 'in_progress' && $request['assigned_worker_id'] === $user['id']): ?>
                                        <form method="post" action="complete_job.php">
                                            <input type="hidden" name="request_id" value="<?php echo $request['id']; ?>" />
                                            <button type="submit" class="button button-primary">Mark completed</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </section>
            <section class="panel">
                <h2>Your worker profile</h2>
                <p>Availability: <?php echo sanitize($profile['availability']); ?></p>
                <p>Location: <?php echo sanitize($profile['location'] ?: 'Not set'); ?></p>
            </section>
        <?php else: ?>
            <section class="panel">
                <div class="stats-grid">
                    <div class="stat-card">
                        <h2><?php echo $customerCounts['pending']; ?></h2>
                        <p>Pending approval</p>
                    </div>
                    <div class="stat-card">
                        <h2><?php echo $customerCounts['open']; ?></h2>
                        <p>Open requests</p>
                    </div>
                    <div class="stat-card">
                        <h2><?php echo $customerCounts['in_progress']; ?></h2>
                        <p>In progress</p>
                    </div>
                    <div class="stat-card">
                        <h2>GH₵ <?php echo number_format($customerSpent, 2); ?></h2>
                        <p>Paid amount</p>
                    </div>
                </div>
                <div class="panel-header">
                    <h1>Your service requests</h1>
                    <a href="request.php" class="button button-primary">Create request</a>
                </div>
                <?php if (!$requests): ?>
                    <div class="empty-state">You have no service requests yet.</div>
                <?php else: ?>
                    <?php foreach ($requests as $request): ?>
                        <article class="request-card">
                            <div class="request-head">
                                <div>
                                    <h2><?php echo sanitize($request['title']); ?></h2>
                                    <?php if ($request['featured']): ?>
                                        <span class="badge badge-featured">Featured</span>
                                    <?php endif; ?>
                                    <p class="meta"><?php echo sanitize($request['category_name']); ?> • <?php echo sanitize($request['location']); ?></p>
                                </div>
                                <span class="status status-<?php echo sanitize($request['status']); ?>"><?php echo strtoupper(str_replace('_', ' ', $request['status'])); ?></span>
                            </div>
                            <p><?php echo sanitize($request['description']); ?></p>
                            <div class="request-footer">
                                <span>Budget: GH₵ <?php echo sanitize($request['budget']); ?></span>
                                <span>Worker: <?php echo sanitize($request['assigned_worker_name'] ?: 'Not assigned'); ?></span>
                                <span>Payment: <?php echo strtoupper($request['payment_status']); ?></span>
                            </div>
                            <div class="request-footer">
                                <a href="<?php echo whatsapp_share_link($request['title'], $request['location'], $request['budget'], BASE_URL . '/dashboard.php'); ?>" target="_blank" class="button button-secondary button-small">Share WhatsApp</a>
                                <?php $contactUrl = whatsapp_contact_link($request['contact_info'], $request['title']); ?>
                                <?php if ($contactUrl): ?>
                                    <a href="<?php echo $contactUrl; ?>" target="_blank" class="button button-secondary button-small">Contact via WhatsApp</a>
                                <?php endif; ?>
                                <a href="request_detail.php?id=<?php echo $request['id']; ?>" class="button button-secondary button-small">Details</a>
                            </div>
                            <?php if ($request['status'] === 'completed'): ?>
                                <div class="request-footer">
                                    <?php if ($request['rating_score'] === null && $request['assigned_worker_id']): ?>
                                        <a href="rate_job.php?request_id=<?php echo $request['id']; ?>" class="button button-secondary button-small">Rate worker</a>
                                    <?php elseif ($request['rating_score'] !== null): ?>
                                        <span>Rated: <?php echo sanitize($request['rating_score']); ?>/5</span>
                                    <?php endif; ?>
                                    <form method="post" action="toggle_payment.php" class="inline-form">
                                        <input type="hidden" name="request_id" value="<?php echo $request['id']; ?>" />
                                        <input type="hidden" name="current_status" value="<?php echo sanitize($request['payment_status']); ?>" />
                                        <button type="submit" class="button button-primary button-small">
                                            Mark as <?php echo $request['payment_status'] === 'paid' ? 'Unpaid' : 'Paid'; ?>
                                        </button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </main>
    <?php $activeNav = 'home'; require __DIR__ . '/partials/bottom_nav.php'; ?>
    <script>
        var toggleCategories = document.getElementById('toggle-categories');
        if (toggleCategories) {
            toggleCategories.addEventListener('click', function () {
                var expanded = this.dataset.expanded === '1';
                document.querySelectorAll('.category-extra').forEach(function (el) {
                    el.hidden = expanded;
                });
                this.textContent = expanded ? 'View all categories' : 'Show fewer categories';
                this.dataset.expanded = expanded ? '0' : '1';
            });
        }
    </script>
</body>
</html>
