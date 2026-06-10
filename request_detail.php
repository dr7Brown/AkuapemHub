<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_login();
$user = current_user();
$requestId = intval($_GET['id'] ?? 0);
if ($requestId <= 0) {
    header('Location: dashboard.php');
    exit;
}

$stmt = $pdo->prepare('SELECT sr.*, c.name AS customer_name, c.username AS customer_username, c.profile_photo AS customer_photo, c.email AS customer_email, w.name AS worker_name, w.username AS worker_username, wc.name AS category_name FROM service_requests sr JOIN users c ON sr.customer_id = c.id JOIN service_categories wc ON sr.category_id = wc.id LEFT JOIN users w ON sr.assigned_worker_id = w.id WHERE sr.id = ?');
$stmt->execute([$requestId]);
$request = $stmt->fetch();

if (!$request) {
    header('Location: dashboard.php');
    exit;
}

$canView = is_admin() || $request['customer_id'] === $user['id'] || (is_worker() && ($request['status'] === 'open' || $request['assigned_worker_id'] === $user['id']));
if (!$canView) {
    header('Location: dashboard.php');
    exit;
}

$myApplicationStatus = null;
if (is_worker()) {
    $appStmt = $pdo->prepare('SELECT status FROM applications WHERE request_id = ? AND worker_id = ? ORDER BY applied_at DESC LIMIT 1');
    $appStmt->execute([$requestId, $user['id']]);
    $myApplication = $appStmt->fetch();
    $myApplicationStatus = $myApplication['status'] ?? null;
}
$canApply = is_worker()
    && in_array($request['status'], ['open','partially_staffed'], true)
    && !in_array($myApplicationStatus, ['pending','approved','accepted'], true);
$canComplete = is_worker() && $request['status'] === 'in_progress' && $request['assigned_worker_id'] === $user['id'];
$canMarkPaid = is_customer() && $request['status'] === 'completed' && $request['customer_id'] === $user['id'];
$canRate = is_customer() && $request['status'] === 'completed' && $request['customer_id'] === $user['id'];

$ratingExists = false;
if ($canRate) {
    $ratingStmt = $pdo->prepare('SELECT id FROM ratings WHERE request_id = ? AND customer_id = ?');
    $ratingStmt->execute([$requestId, $user['id']]);
    $ratingExists = (bool)$ratingStmt->fetch();
}

$completionPhotos = get_completion_photos($requestId);

$recommendedWorkers = [];
if (is_customer() && $request['customer_id'] === $user['id'] && in_array($request['status'], ['pending', 'open'], true)) {
    $recommendedWorkers = get_recommended_workers_for_request($request, 5);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Request details — AkuapemHub</title>
    <link rel="stylesheet" href="assets/css/style.css" />
</head>
<body class="has-bottom-nav">
    <header class="app-topbar">
        <a href="javascript:history.back()" class="brand" style="text-decoration: none;">
            <span class="brand-icon">‹</span> Job Details
        </a>
    </header>
    <main class="page-shell small-shell">
        <?php $feeStatus = $request['posting_fee_status'] ?? 'free'; ?>
        <?php if ($feeStatus === 'pending' && $request['customer_id'] === $user['id']): ?>
            <div class="alert alert-warning" style="margin-bottom:12px;">
                💳 <strong>Posting fee required</strong> — this job is not yet visible to workers until you confirm your posting fee payment.
                <a href="pay_job_post.php?id=<?php echo $request['id']; ?>" class="button button-primary button-small" style="margin-left:8px;">Pay posting fee →</a>
            </div>
        <?php endif; ?>
        <section class="card">
            <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 10px;">
                <h1 style="margin: 0; font-size: 1.25rem;"><?php echo sanitize($request['title']); ?></h1>
                <span class="status status-<?php echo sanitize($request['status']); ?>"><?php echo strtoupper(str_replace('_', ' ', $request['status'])); ?></span>
            </div>
            <div style="margin-top: 8px;">
                <span class="badge" style="margin-left: 0;"><?php echo sanitize($request['category_name']); ?></span>
                <?php
                    $rdFeatEnd    = $request['featured_end_date'] ?? null;
                    $rdFeatActive = !empty($request['featured']) && (empty($rdFeatEnd) || $rdFeatEnd >= date('Y-m-d'));
                ?>
                <?php if ($rdFeatActive): ?>
                    <span class="badge badge-featured">⭐ Featured<?php echo $rdFeatEnd ? ' · until ' . sanitize($rdFeatEnd) : ''; ?></span>
                <?php endif; ?>
                <?php if ($request['status'] === 'fully_staffed'): ?>
                    <span class="badge" style="background:#22a06b;color:#fff;margin-left:4px;">✅ Fully Staffed</span>
                <?php elseif ($request['status'] === 'partially_staffed'): ?>
                    <span class="badge" style="background:#f59e0b;color:#fff;margin-left:4px;">Partially Staffed</span>
                <?php endif; ?>
                <?php if (($request['workers_needed'] ?? 1) > 1): ?>
                    <span class="badge" style="background:var(--surface);color:var(--text);border:1px solid var(--border);margin-left:4px;"><?php echo (int)($request['workers_approved']??0); ?>/<?php echo (int)$request['workers_needed']; ?> workers</span>
                <?php endif; ?>
            </div>

            <a href="customer_profile.php?id=<?php echo $request['customer_id']; ?>" style="display:flex;align-items:center;gap:10px;margin-top:18px;text-decoration:none;color:inherit;">
                <?php if (!empty($request['customer_photo'])): ?>
                    <img src="<?php echo sanitize($request['customer_photo']); ?>" alt="" class="avatar avatar-sm" style="object-fit:cover;" />
                <?php else: ?>
                    <span class="avatar avatar-sm"><?php echo sanitize(strtoupper(substr($request['customer_username'] ?: $request['customer_name'], 0, 1))); ?></span>
                <?php endif; ?>
                <div>
                    <p class="meta" style="margin: 0;">Posted by</p>
                    <strong><?php echo sanitize($request['customer_username'] ?: $request['customer_name']); ?></strong>
                </div>
            </a>
            <p class="meta" style="margin-top: 10px;">📍 <?php echo sanitize($request['location']); ?> · <?php echo sanitize(time_ago($request['created_at'])); ?></p>

            <div class="info-grid" style="margin: 18px 0;">
                <div>
                    <p class="meta" style="margin: 0 0 2px;">Budget</p>
                    <strong>GH₵ <?php echo sanitize($request['budget']); ?></strong>
                </div>
                <div>
                    <p class="meta" style="margin: 0 0 2px;">Payment</p>
                    <strong><?php echo strtoupper($request['payment_status']); ?></strong>
                </div>
            </div>

            <h2 style="font-size: 1rem; margin-bottom: 6px;">Description</h2>
            <p><?php echo nl2br(sanitize($request['description'])); ?></p>

            <h2 style="font-size: 1rem; margin: 16px 0 6px;">Skills needed</h2>
            <?php if (!empty($request['skills_needed'])): ?>
                <?php foreach (array_filter(array_map('trim', explode(',', $request['skills_needed']))) as $skillName): ?>
                    <span class="badge" style="margin-left: 0; margin-right: 6px;"><?php echo sanitize($skillName); ?></span>
                <?php endforeach; ?>
            <?php else: ?>
                <span class="badge" style="margin-left: 0;"><?php echo sanitize($request['category_name']); ?></span>
            <?php endif; ?>

            <h2 style="font-size: 1rem; margin: 16px 0 6px;">Location</h2>
            <p style="margin: 0;"><?php echo sanitize($request['location']); ?></p>

            <?php if ($request['assigned_worker_id']): ?>
                <h2 style="font-size: 1rem; margin: 16px 0 6px;">Assigned worker</h2>
                <a href="worker_profile_public.php?id=<?php echo $request['assigned_worker_id']; ?>" style="color:var(--primary);font-weight:600;">
                    <?php echo sanitize($request['worker_username'] ?: $request['worker_name'] ?: 'Worker'); ?>
                </a>
            <?php endif; ?>

            <?php if (!empty($request['completion_notes'])): ?>
                <h2 style="font-size: 1rem; margin: 16px 0 6px;">Completion notes</h2>
                <p style="margin: 0;"><?php echo nl2br(sanitize($request['completion_notes'])); ?></p>
            <?php endif; ?>

            <?php if (!empty($completionPhotos)): ?>
                <h2 style="font-size: 1rem; margin: 16px 0 6px;">Completion photos</h2>
                <div style="display: flex; flex-wrap: wrap; gap: 10px;">
                    <?php foreach ($completionPhotos as $photo): ?>
                        <a href="<?php echo sanitize($photo['file_path']); ?>" target="_blank">
                            <img src="<?php echo sanitize($photo['file_path']); ?>" alt="Completion evidence" style="width: 100px; height: 100px; object-fit: cover; border-radius: 8px; border: 1px solid #e5e7eb;" />
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <?php if ($canComplete): ?>
            <section class="card form-card">
                <h2 style="margin-top: 0;">Mark this job as completed</h2>
                <form method="post" action="complete_job.php" enctype="multipart/form-data">
                    <input type="hidden" name="request_id" value="<?php echo $request['id']; ?>" />
                    <textarea name="completion_notes" rows="3" placeholder="Add completion notes or evidence summary..."></textarea>
                    <label class="meta">Attach photo evidence (JPG/PNG/WebP, up to 5MB each)</label>
                    <input type="file" name="completion_photos[]" accept="image/jpeg,image/png,image/webp" multiple />
                    <button type="submit" class="button button-primary">Mark completed</button>
                </form>
            </section>
        <?php endif; ?>

        <?php
        $primaryAction = null;
        if (is_customer() && $request['customer_id'] === $user['id']) {
            $primaryAction = ['link', 'job_applications.php?id=' . $request['id'], '👥 Manage Applicants'];
        } elseif ($canApply) {
            $primaryAction = ['form', 'apply_job.php', 'Apply for this job'];
        } elseif (is_worker() && $request['status'] === 'fully_staffed' && $myApplicationStatus !== 'approved') {
            $primaryAction = ['info', null, 'This job is fully staffed'];
        } elseif (is_worker() && $myApplicationStatus === 'pending') {
            $primaryAction = ['info', null, 'Application pending review'];
        } elseif (is_worker() && in_array($myApplicationStatus, ['rejected','declined'], true)) {
            $primaryAction = ['info', null, 'Application not selected'];
        } elseif ($canMarkPaid) {
            $primaryAction = ['form_paid', null, 'Mark as ' . ($request['payment_status'] === 'paid' ? 'Unpaid' : 'Paid')];
        } elseif ($canRate && !$ratingExists) {
            $primaryAction = ['link', 'rate_job.php?request_id=' . $request['id'], 'Rate worker'];
        }

        $secondaryActions = [];
        // In-app chat: customer → assigned worker, worker → customer
        if (is_customer() && $request['customer_id'] === $user['id'] && $request['assigned_worker_id']) {
            $secondaryActions[] = ['link', 'chat_start.php?user_id=' . $request['assigned_worker_id'] . '&job_id=' . $request['id'], '💬 Message Worker'];
        } elseif (is_worker() && ($request['assigned_worker_id'] === $user['id'] || $myApplicationStatus === 'pending' || $myApplicationStatus === 'accepted')) {
            $secondaryActions[] = ['link', 'chat_start.php?user_id=' . $request['customer_id'] . '&job_id=' . $request['id'], '💬 Message Customer'];
        } else {
            $secondaryActions[] = ['link', 'chat.php', '💬 Messages'];
        }
        if (is_customer() && $request['customer_id'] === $user['id'] && $request['status'] !== 'completed' && $request['status'] !== 'cancelled') {
            $rdRenewSoon = !empty($request['featured']) && !empty($rdFeatEnd) && $rdFeatEnd < date('Y-m-d', strtotime('+7 days'));
            if (!$rdFeatActive || $rdRenewSoon) {
                $secondaryActions[] = ['link', 'feature_job.php?id=' . $request['id'], $rdRenewSoon ? '⭐ Renew feature' : '⭐ Feature job'];
            }
            $secondaryActions[] = ['link', 'cancel_request.php?request_id=' . $request['id'], 'Cancel request'];
            $secondaryActions[] = ['link', 'file_dispute.php?request_id=' . $request['id'], 'File dispute'];
        } elseif (is_worker() && $request['assigned_worker_id'] === $user['id'] && $request['status'] !== 'cancelled') {
            $secondaryActions[] = ['link', 'file_dispute.php?request_id=' . $request['id'], 'File dispute'];
        }
        $secondaryActions[] = ['link_external', whatsapp_share_link($request['title'], $request['location'], $request['budget'], BASE_URL . '/request_detail.php?id=' . $request['id']), '🔗 Share'];
        $secondaryActions = array_slice($secondaryActions, 0, $primaryAction ? 3 : 4);
        ?>
        <div class="job-action-bar">
            <?php if ($primaryAction): ?>
                <?php if ($primaryAction[0] === 'form'): ?>
                    <form method="post" action="<?php echo sanitize($primaryAction[1]); ?>">
                        <input type="hidden" name="request_id" value="<?php echo $request['id']; ?>" />
                        <button type="submit" class="button button-primary"><?php echo sanitize($primaryAction[2]); ?></button>
                    </form>
                <?php elseif ($primaryAction[0] === 'info'): ?>
                    <span class="button button-secondary" style="text-align: center; opacity: 0.7; cursor: default;"><?php echo sanitize($primaryAction[2]); ?></span>
                <?php elseif ($primaryAction[0] === 'form_paid'): ?>
                    <form method="post" action="toggle_payment.php">
                        <input type="hidden" name="request_id" value="<?php echo $request['id']; ?>" />
                        <input type="hidden" name="current_status" value="<?php echo sanitize($request['payment_status']); ?>" />
                        <button type="submit" class="button button-primary"><?php echo sanitize($primaryAction[2]); ?></button>
                    </form>
                <?php else: ?>
                    <a href="<?php echo sanitize($primaryAction[1]); ?>" class="button button-primary"><?php echo sanitize($primaryAction[2]); ?></a>
                <?php endif; ?>
            <?php endif; ?>
            <div class="job-action-bar-row">
                <?php foreach ($secondaryActions as $action): ?>
                    <a href="<?php echo sanitize($action[1]); ?>" <?php echo $action[0] === 'link_external' ? 'target="_blank"' : ''; ?> class="button button-secondary button-small"><?php echo sanitize($action[2]); ?></a>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if ($recommendedWorkers): ?>
            <section class="panel">
                <h1>🎯 Recommended workers for this job</h1>
                <p class="meta">Ranked by skill match, distance, rating, availability, and track record.</p>
                <?php foreach ($recommendedWorkers as $worker): ?>
                    <div class="match-card">
                        <div class="match-card-head">
                            <div>
                                <strong><?php echo sanitize($worker['name']); ?></strong>
                                <?php if (!empty($worker['is_verified'])): ?>
                                    <span style="display:inline-flex;align-items:center;background:#22a06b;color:#fff;border-radius:4px;padding:0 5px;font-size:0.78rem;margin-left:4px;vertical-align:middle;"><strong>✓</strong>erified</span>
                                <?php endif; ?>
                                <?php if (!empty($worker['is_featured'])): ?>
                                    <span class="badge" style="background:var(--primary);color:#fff;font-size:0.75rem;margin-left:4px;vertical-align:middle;">Featured</span>
                                <?php endif; ?>
                                <?php if ($worker['subscription_status'] === 'premium'): ?>
                                    <span class="badge">PREMIUM</span>
                                <?php endif; ?>
                            </div>
                            <span class="match-score-pill"><?php echo (int)$worker['match_score']; ?>% match</span>
                        </div>
                        <p class="meta">
                            <?php echo sanitize(rating_stars(round($worker['avg_rating']))); ?> (<?php echo number_format($worker['avg_rating'], 1); ?>)
                            • <?php echo (int)$worker['completed_jobs']; ?> jobs completed
                            • <span class="status status-<?php echo sanitize($worker['availability']); ?>"><?php echo strtoupper($worker['availability']); ?></span>
                            <?php if ($worker['distance_km'] !== null): ?>
                                • <?php echo sanitize(format_distance($worker['distance_km'])); ?>
                            <?php endif; ?>
                        </p>
                        <?php if (!empty($worker['skills'])): ?>
                            <p class="meta">Skills: <?php echo sanitize($worker['skills']); ?></p>
                        <?php endif; ?>
                        <?php if (!empty($worker['match_reasons'])): ?>
                            <p class="match-meta">Why this match: <?php echo sanitize(implode(' • ', $worker['match_reasons'])); ?></p>
                        <?php endif; ?>
                        <div class="button-group">
                            <a href="worker_profile_public.php?id=<?php echo $worker['id']; ?>" class="button button-secondary button-small">View profile</a>
                            <a href="chat_start.php?user_id=<?php echo $worker['id']; ?>&job_id=<?php echo $request['id']; ?>" class="button button-secondary button-small">💬 Message</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>
    </main>
    <?php $activeNav = 'jobs'; require __DIR__ . '/partials/bottom_nav.php'; ?>
</body>
</html>
