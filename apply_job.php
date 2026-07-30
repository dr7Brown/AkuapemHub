<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_module_enabled('jobs', 'Jobs & Services');
require_login();
require_role('worker');
$user = current_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['request_id'])) {
    header('Location: jobs.php');
    exit;
}
csrf_check();

if (requires_verified_email('job_apply') && !is_email_verified()) {
    flash('Please verify your email address before applying for jobs. Check your inbox.', 'error');
    header('Location: request_detail.php?id=' . intval($_POST['request_id']));
    exit;
}

$requestId = intval($_POST['request_id']);
$stmt = $pdo->prepare("SELECT * FROM service_requests WHERE id = ? AND status IN ('open','partially_staffed')");
$stmt->execute([$requestId]);
$request = $stmt->fetch();

if (!$request) {
    flash('This job is no longer accepting applications.', 'error');
    header('Location: jobs.php');
    exit;
}

// Inline deadline check — expire the job immediately if its deadline has passed
if (!empty($request['deadline_date']) && $request['deadline_date'] < date('Y-m-d H:i:s')) {
    $pdo->prepare("UPDATE service_requests SET status='expired' WHERE id=? AND status IN ('open','partially_staffed')")->execute([$requestId]);
    flash('This job has passed its application deadline and is no longer accepting applications.', 'error');
    header('Location: request_detail.php?id=' . $requestId);
    exit;
}

if ((int)$request['customer_id'] === (int)$user['id']) {
    flash("You can't apply for your own job.", 'error');
    header('Location: request_detail.php?id=' . $requestId);
    exit;
}

if ($request['status'] === 'fully_staffed') {
    flash('This job is fully staffed and no longer accepting applications.', 'error');
    header('Location: request_detail.php?id=' . $requestId);
    exit;
}

if (is_feature_paid('enable_paid_worker_service')) {
    $wpStmt = $pdo->prepare("SELECT service_fee_status, service_fee_expiry FROM worker_profiles WHERE user_id = ?");
    $wpStmt->execute([$user['id']]);
    $wp = $wpStmt->fetch();
    $feeOk = $wp && ($wp['service_fee_status'] === 'free'
        || ($wp['service_fee_status'] === 'paid' && (empty($wp['service_fee_expiry']) || $wp['service_fee_expiry'] >= date('Y-m-d'))));
    if (!$feeOk) {
        flash('You must pay the worker service listing fee before applying for jobs. <a href="pay_worker_service.php">Pay now →</a>', 'error');
        header('Location: request_detail.php?id=' . $requestId);
        exit;
    }
}

$existingStmt = $pdo->prepare("SELECT id FROM applications WHERE request_id = ? AND worker_id = ? AND status IN ('pending','approved','accepted')");
$existingStmt->execute([$requestId, $user['id']]);
if ($existingStmt->fetch()) {
    flash('You have already applied for this job.', 'error');
    header('Location: request_detail.php?id=' . $requestId);
    exit;
}

// Enforce daily application limit
$maxDaily = (int)get_platform_setting('max_daily_applications', '0');
if ($maxDaily > 0) {
    $todayStmt = $pdo->prepare("SELECT COUNT(*) FROM applications WHERE worker_id = ? AND DATE(applied_at) = CURDATE()");
    $todayStmt->execute([$user['id']]);
    $todayCount = (int)$todayStmt->fetchColumn();
    if ($todayCount >= $maxDaily) {
        flash("You've reached your daily limit of {$maxDaily} application" . ($maxDaily !== 1 ? 's' : '') . ". Try again tomorrow.", 'error');
        header('Location: request_detail.php?id=' . $requestId);
        exit;
    }
}

$pdo->prepare('INSERT INTO applications (request_id, worker_id, status, applied_at) VALUES (?, ?, ?, NOW())')->execute([$requestId, $user['id'], 'pending']);

// Notify job owner (not just admins)
notify_user((int)$request['customer_id'], 'New application received',
    "{$user['name']} applied for your job '{$request['title']}'. <a href=\"manage_applicants.php?id={$request['id']}\">Review applicants →</a>", 'info');
notify_admins_and_managers('New job application', "{$user['name']} applied for '{$request['title']}'.", 'info');

flash('Application submitted. You will be notified once it is reviewed.');
header('Location: request_detail.php?id=' . $requestId);
exit;
