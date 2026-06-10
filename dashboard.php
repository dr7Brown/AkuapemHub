<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_login();
$user = current_user();
$flash = get_flash();
sweep_expired_featured();
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
    $workerWhere = array_merge(["sr.status IN ('open','partially_staffed')"], $where);
    $sql = 'SELECT sr.*, u.name AS customer_name, wc.name AS category_name, w.user_id AS worker_user_id
            FROM service_requests sr
            JOIN users u ON sr.customer_id = u.id
            JOIN service_categories wc ON sr.category_id = wc.id
            LEFT JOIN worker_profiles w ON sr.assigned_worker_id = w.user_id
            WHERE ' . implode(' AND ', $workerWhere);
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
    $workerPendingVerif = false;
    if ($profile) {
        $skillRows = $pdo->prepare('SELECT skill_name FROM worker_skills WHERE worker_profile_id = ?');
        $skillRows->execute([$profile['id']]);
        $workerSkillsCsv = implode(', ', array_column($skillRows->fetchAll(), 'skill_name'));

        $pvStmt = $pdo->prepare("SELECT id FROM platform_payments WHERE user_id = ? AND payment_type = 'verification' AND status = 'pending'");
        $pvStmt->execute([$user['id']]);
        $workerPendingVerif = (bool) $pvStmt->fetch();
    }
    $matchContext = [
        'latitude' => $profile['latitude'] ?? null,
        'longitude' => $profile['longitude'] ?? null,
        'skills' => $workerSkillsCsv,
    ];

    $openJobs = [];
    $myJobs = [];
    foreach ($requests as $request) {
        if (in_array($request['status'], ['open','partially_staffed'], true)) {
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
    $customerWhere = array_merge(['sr.customer_id = ?'], $where);
    $customerParams = array_merge([$user['id']], $params);
    $sql = 'SELECT sr.*, sr.posting_fee_status, wc.name AS category_name, u.name AS assigned_worker_name, r.score AS rating_score, r.comment AS rating_comment
        FROM service_requests sr
        JOIN service_categories wc ON sr.category_id = wc.id
        LEFT JOIN users u ON sr.assigned_worker_id = u.id
        LEFT JOIN ratings r ON sr.id = r.request_id AND r.customer_id = sr.customer_id
        WHERE ' . implode(' AND ', $customerWhere) . '
        ORDER BY sr.created_at DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($customerParams);
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
                <span class="avatar"><?php echo sanitize(strtoupper(substr(display_name($user), 0, 1))); ?></span>
            <?php endif; ?>
        </div>
    </header>
    <main class="page-shell">
        <?php if ($flash): ?>
            <div class="alert alert-<?php echo sanitize($flash['type']); ?>"><?php echo sanitize($flash['message']); ?></div>
        <?php endif; ?>

        <section class="hero-card">
            <p class="meta" style="color: rgba(255,255,255,0.85); margin-bottom: 4px;">👋 Hello, <?php echo sanitize(display_name($user)); ?></p>
            <?php if (is_worker()): ?>
                <h1>Find trusted jobs near you in Akuapem</h1>
                <div class="button-group">
                    <a href="#open-jobs" class="button button-primary">Browse open jobs</a>
                    <a href="job_applications.php" class="button button-secondary">📋 My Applications</a>
                    <a href="request.php" class="button button-secondary">➕ Post Job</a>
                </div>
            <?php else: ?>
                <h1>Find trusted workers for any job in Akuapem</h1>
                <a href="request.php" class="button button-primary">➕ Post Job</a>
            <?php endif; ?>
        </section>

        <?php if (is_worker() && $profile):
            $dbFeatEnd    = $profile['featured_end_date'] ?? null;
            $dbFeatActive = !empty($profile['is_featured']) && (empty($dbFeatEnd) || $dbFeatEnd >= date('Y-m-d'));
            $dbRenewSoon  = !empty($profile['is_featured']) && !empty($dbFeatEnd) && $dbFeatEnd < date('Y-m-d', strtotime('+7 days'));
        ?>
            <div style="display:flex;align-items:center;gap:10px;padding:12px 16px;border-radius:10px;background:var(--surface);border:1px solid var(--border);margin-bottom:8px;">
                <span style="font-size:1.25rem;">🛡️</span>
                <div style="flex:1;min-width:0;">
                    <span style="font-size:0.82rem;color:var(--text-muted);display:block;margin-bottom:3px;">Profile status</span>
                    <?php if ($profile['is_verified']): ?>
                        <span style="display:inline-flex;align-items:center;gap:3px;background:#22a06b;color:#fff;border-radius:5px;padding:2px 9px;font-size:0.92rem;font-weight:600;"><strong style="font-size:1.05em;">✓</strong>erified</span>
                        <?php if ($profile['verification_expiry']): ?>
                            <span class="meta" style="margin-left:6px;font-size:0.8rem;">until <?php echo sanitize($profile['verification_expiry']); ?></span>
                        <?php endif; ?>
                    <?php elseif ($workerPendingVerif): ?>
                        <a href="my_payments.php" style="display:inline-flex;align-items:center;gap:4px;background:#f59e0b;color:#fff;border-radius:5px;padding:2px 9px;font-size:0.87rem;font-weight:600;text-decoration:none;">⏳ Verification payment pending</a>
                    <?php else: ?>
                        <span style="color:var(--text-muted);font-size:0.87rem;">Not verified</span>
                        <a href="<?php echo is_feature_paid('enable_paid_verification_badges') ? 'request_verification.php' : '#'; ?>" class="button button-secondary button-small" style="margin-left:10px;font-size:0.8rem;">Get <strong>✓</strong>erified</a>
                    <?php endif; ?>
                    <?php if ($dbFeatActive && !$dbRenewSoon): ?>
                        <span style="display:inline-flex;align-items:center;background:var(--primary);color:#fff;border-radius:5px;padding:2px 9px;font-size:0.87rem;font-weight:600;margin-left:6px;">⭐ Featured<?php echo $dbFeatEnd ? ' · ' . sanitize($dbFeatEnd) : ''; ?></span>
                    <?php elseif ($dbRenewSoon): ?>
                        <a href="feature_worker.php" style="display:inline-flex;align-items:center;gap:4px;background:#f59e0b;color:#fff;border-radius:5px;padding:2px 9px;font-size:0.87rem;font-weight:600;text-decoration:none;margin-left:6px;">⭐ Renew feature</a>
                    <?php elseif (!$dbFeatActive): ?>
                        <a href="feature_worker.php" class="button button-secondary button-small" style="margin-left:10px;font-size:0.8rem;">⭐ Feature profile</a>
                    <?php endif; ?>
                </div>
                <a href="worker_profile.php" style="font-size:0.8rem;color:var(--primary);white-space:nowrap;">My profile →</a>
            </div>
            <?php
                $svcFeeStatus = $profile['service_fee_status'] ?? 'free';
                $svcFeeExpiry = $profile['service_fee_expiry'] ?? null;
                $listingRequired = is_feature_paid('enable_paid_worker_service');
            ?>
            <?php if ($svcFeeStatus === 'pending'): ?>
                <div class="alert alert-warning" style="margin-bottom:8px;font-size:0.9rem;">
                    💳 <strong>Service listing fee pending</strong> — your profile won't appear in Find Workers until confirmed.
                    <a href="my_payments.php" style="color:var(--primary);margin-left:4px;">Track payment →</a>
                    &nbsp;·&nbsp;<a href="pay_worker_service.php" style="color:var(--primary);">Pay now →</a>
                </div>
            <?php elseif ($svcFeeStatus === 'paid'): ?>
                <div style="display:flex;align-items:center;gap:10px;padding:10px 16px;border-radius:10px;background:var(--surface);border:1px solid var(--border);margin-bottom:8px;font-size:0.9rem;">
                    <span>📋</span>
                    <div style="flex:1;">
                        <strong>Service listing</strong>: active
                        <?php if ($svcFeeExpiry): ?>
                            — expires <strong><?php echo sanitize($svcFeeExpiry); ?></strong>
                            <?php if ($svcFeeExpiry <= date('Y-m-d', strtotime('+7 days'))): ?>
                                <a href="pay_worker_service.php" style="color:var(--primary);margin-left:6px;">Renew →</a>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php elseif ($listingRequired): ?>
                <div class="alert alert-info" style="margin-bottom:8px;font-size:0.9rem;">
                    A service listing fee is required to appear in Find Workers.
                    <a href="pay_worker_service.php" style="color:var(--primary);margin-left:4px;">Pay listing fee →</a>
                </div>
            <?php endif; ?>
        <?php endif; ?>

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
                    <div class="jobs-grid">
                    <?php foreach ($displayJobs as $request): ?>
                        <?php $jobDistance = $request['match_distance_km'] ?? distance_km($profile['latitude'] ?? null, $profile['longitude'] ?? null, $request['latitude'], $request['longitude']); ?>
                        <article class="request-card">
                            <div class="request-head">
                                <div>
                                    <h2><?php echo sanitize($request['title']); ?></h2>
                                    <?php if (!empty($request['featured']) && (empty($request['featured_end_date']) || $request['featured_end_date'] >= date('Y-m-d'))): ?>
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
                                    <a href="request_detail.php?id=<?php echo $request['id']; ?>" class="button button-secondary button-small">Details</a>
                                    <?php if (in_array($request['status'], ['open','partially_staffed'], true)): ?>
                                        <?php $myAppStatus = $myApplicationStatuses[$request['id']] ?? null; ?>
                                        <?php if ($myAppStatus === 'pending'): ?>
                                            <span class="button button-secondary button-small" style="opacity: 0.7; cursor: default;">Application pending</span>
                                        <?php elseif ($myAppStatus === 'approved'): ?>
                                            <span class="button button-secondary button-small" style="opacity: 0.7; cursor: default; background:#d1fae5; color:#065f46;">✓ Approved</span>
                                        <?php elseif ($myAppStatus === 'rejected'): ?>
                                            <span class="button button-secondary button-small" style="opacity: 0.7; cursor: default;">Not selected</span>
                                        <?php else: ?>
                                            <form method="post" action="apply_job.php">
                                                <input type="hidden" name="request_id" value="<?php echo $request['id']; ?>" />
                                                <button type="submit" class="button button-primary">Apply for this job</button>
                                            </form>
                                        <?php endif; ?>
                                    <?php elseif (in_array($request['status'], ['in_progress','fully_staffed'], true) && $request['assigned_worker_id'] === $user['id']): ?>
                                        <form method="post" action="complete_job.php">
                                            <input type="hidden" name="request_id" value="<?php echo $request['id']; ?>" />
                                            <button type="submit" class="button button-primary">Mark completed</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                    </div>
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
                    <div style="display:flex;gap:8px;">
                        <a href="job_applications.php" class="button button-secondary">👥 Applicants</a>
                        <a href="request.php" class="button button-primary">Create request</a>
                    </div>
                </div>
                <form method="get" class="filter-form">
                    <select name="category">
                        <option value="">All categories</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?php echo $category['id']; ?>" <?php echo $categoryFilter == $category['id'] ? 'selected' : ''; ?>><?php echo sanitize($category['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="status">
                        <option value="">All statuses</option>
                        <?php foreach (['pending', 'open', 'partially_staffed', 'fully_staffed', 'in_progress', 'completed', 'cancelled'] as $statusOption): ?>
                            <option value="<?php echo $statusOption; ?>" <?php echo $statusFilter === $statusOption ? 'selected' : ''; ?>><?php echo strtoupper(str_replace('_', ' ', $statusOption)); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="q" value="<?php echo sanitize($searchQuery); ?>" placeholder="Search requests" />
                    <input type="text" name="location" value="<?php echo sanitize($locationFilter); ?>" placeholder="Location" />
                    <button type="submit" class="button button-primary">Filter</button>
                </form>
                <?php if (!$requests): ?>
                    <div class="empty-state">You have no service requests yet.</div>
                <?php else: ?>
                    <div class="jobs-grid">
                    <?php foreach ($requests as $request): ?>
                        <article class="request-card">
                            <?php $feeStatus = $request['posting_fee_status'] ?? 'free'; ?>
                            <?php if ($feeStatus === 'pending'): ?>
                                <div class="alert alert-warning" style="margin-bottom:8px;font-size:0.9rem;">
                                    💳 <strong>Posting fee pending</strong> — your job is not yet visible until the fee is confirmed.
                                    <a href="pay_job_post.php?id=<?php echo $request['id']; ?>" style="color:var(--primary);margin-left:4px;">Pay now →</a>
                                </div>
                            <?php endif; ?>
                            <div class="request-head">
                                <div>
                                    <h2><?php echo sanitize($request['title']); ?></h2>
                                    <?php if (!empty($request['featured']) && (empty($request['featured_end_date']) || $request['featured_end_date'] >= date('Y-m-d'))): ?>
                                        <span class="badge badge-featured">Featured</span>
                                    <?php endif; ?>
                                    <p class="meta"><?php echo sanitize($request['category_name']); ?> • <?php echo sanitize($request['location']); ?></p>
                                </div>
                                <span class="status status-<?php echo sanitize($request['status']); ?>"><?php echo strtoupper(str_replace('_', ' ', $request['status'])); ?></span>
                            </div>
                            <p><?php echo sanitize($request['description']); ?></p>
                            <div class="request-footer">
                                <span>Budget: GH₵ <?php echo sanitize($request['budget']); ?></span>
                                <?php if (($request['workers_needed'] ?? 1) > 1 || ($request['workers_approved'] ?? 0) > 0): ?>
                                    <span>Hired: <?php echo (int)($request['workers_approved'] ?? 0); ?>/<?php echo (int)($request['workers_needed'] ?? 1); ?></span>
                                <?php else: ?>
                                    <span>Worker: <?php echo sanitize($request['assigned_worker_name'] ?: 'Not assigned'); ?></span>
                                <?php endif; ?>
                                <span>Payment: <?php echo strtoupper($request['payment_status']); ?></span>
                            </div>
                            <div class="request-footer">
                                <?php if (!in_array($request['status'], ['pending', 'cancelled'], true)): ?>
                                    <a href="job_applications.php?id=<?php echo $request['id']; ?>" class="button button-primary button-small">👥 Manage Applicants</a>
                                <?php endif; ?>
                                <a href="<?php echo whatsapp_share_link($request['title'], $request['location'], $request['budget'], BASE_URL . '/dashboard.php'); ?>" target="_blank" class="button button-secondary button-small">Share WhatsApp</a>
                                <a href="request_detail.php?id=<?php echo $request['id']; ?>" class="button button-secondary button-small">Details</a>
                                <?php
                                    $cFeatEnd    = $request['featured_end_date'] ?? null;
                                    $cFeatActive = !empty($request['featured']) && (empty($cFeatEnd) || $cFeatEnd >= date('Y-m-d'));
                                    $cRenewSoon  = !empty($request['featured']) && !empty($cFeatEnd) && $cFeatEnd < date('Y-m-d', strtotime('+7 days'));
                                ?>
                                <?php if (in_array($request['status'], ['pending', 'open'], true) && (!$cFeatActive || $cRenewSoon)): ?>
                                    <a href="feature_job.php?id=<?php echo $request['id']; ?>" class="button button-secondary button-small"><?php echo $cRenewSoon ? '⭐ Renew feature' : '⭐ Feature job'; ?></a>
                                <?php endif; ?>
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
                    </div>
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
