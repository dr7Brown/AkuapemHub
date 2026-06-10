<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_login();
require_role('worker');
$user = current_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['request_id'])) {
    header('Location: dashboard.php');
    exit;
}

$requestId = intval($_POST['request_id']);
$stmt = $pdo->prepare("SELECT * FROM service_requests WHERE id = ? AND status IN ('open','partially_staffed')");
$stmt->execute([$requestId]);
$request = $stmt->fetch();

if (!$request) {
    flash('This job is no longer accepting applications.', 'error');
    header('Location: dashboard.php');
    exit;
}

if ($request['status'] === 'fully_staffed') {
    flash('This job is fully staffed and no longer accepting applications.', 'error');
    header('Location: request_detail.php?id=' . $requestId);
    exit;
}

$existingStmt = $pdo->prepare("SELECT id FROM applications WHERE request_id = ? AND worker_id = ? AND status IN ('pending','approved','accepted')");
$existingStmt->execute([$requestId, $user['id']]);
if ($existingStmt->fetch()) {
    flash('You have already applied for this job.', 'error');
    header('Location: request_detail.php?id=' . $requestId);
    exit;
}

$pdo->prepare('INSERT INTO applications (request_id, worker_id, status, applied_at) VALUES (?, ?, ?, NOW())')->execute([$requestId, $user['id'], 'pending']);

// Notify job owner (not just admins)
notify_user((int)$request['customer_id'], 'New application received',
    "{$user['name']} applied for your job '{$request['title']}'. <a href=\"manage_applicants.php?id={$request['id']}\">Review applicants →</a>", 'info');
notify_admins_and_managers('New job application', "{$user['name']} applied for '{$request['title']}'.", 'info');

flash('Application submitted. You will be notified once it is reviewed.');
header('Location: request_detail.php?id=' . $requestId);
exit;
