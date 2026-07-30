<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_module_enabled('jobs', 'Jobs & Services');
require_login();
$user = current_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: jobs.php');
    exit;
}
csrf_check();

$jobId = intval($_POST['job_id'] ?? 0);
if ($jobId <= 0) {
    flash('Invalid job.', 'error');
    header('Location: jobs.php');
    exit;
}

$isAdmin = is_admin() || is_manager();

// Fetch job + escrow in one go
$stmt = $pdo->prepare("SELECT sr.title, sr.status, sr.customer_id, ep.id AS escrow_id, ep.status AS escrow_status, ep.net_amount, ep.worker_id
    FROM service_requests sr
    JOIN escrow_payments ep ON ep.job_id = sr.id
    WHERE sr.id = ?");
$stmt->execute([$jobId]);
$row = $stmt->fetch();

if (!$row) {
    flash('Escrow record not found.', 'error');
    header('Location: jobs.php');
    exit;
}

// Only the job's client or an admin/manager can release
if (!$isAdmin && (int)$row['customer_id'] !== $user['id']) {
    flash('You are not authorised to release this escrow payment.', 'error');
    header('Location: request_detail.php?id=' . $jobId);
    exit;
}

if ($row['escrow_status'] !== 'held') {
    flash('This escrow payment is not in a releasable state (status: ' . sanitize($row['escrow_status']) . ').', 'error');
    header('Location: request_detail.php?id=' . $jobId);
    exit;
}

if (!$isAdmin && $row['status'] !== 'completed') {
    flash('You can only release the escrow after the worker marks the job as completed.', 'error');
    header('Location: request_detail.php?id=' . $jobId);
    exit;
}

$initiatedBy = $isAdmin ? 'admin' : 'client';
$adminNotes  = $isAdmin ? trim($_POST['admin_notes'] ?? '') : null;

$pdo->prepare("UPDATE escrow_payments
    SET status = 'released', released_at = NOW(), release_initiated_by = ?, admin_notes = ?
    WHERE id = ? AND status = 'held'")
    ->execute([$initiatedBy, $adminNotes ?: null, $row['escrow_id']]);

$pdo->prepare("UPDATE service_requests SET payment_status = 'paid', updated_at = NOW() WHERE id = ?")
    ->execute([$jobId]);

// Points: client explicitly released escrow (marks job confirmed)
if ($initiatedBy === 'client') {
    require_once __DIR__ . '/modules/referrals/service.php';
    award_points((int)$row['customer_id'], 'mark_job_completed', $jobId);
}

if ($row['worker_id']) {
    notify_user((int)$row['worker_id'], '💸 Payment released',
        "The client has released your escrow payment of GH₵ " . number_format($row['net_amount'], 2) . " for \"{$row['title']}\". Thank you for your great work!",
        'success');
}

notify_user((int)$row['customer_id'], '✅ Escrow payment released',
    "You have released the escrow payment of GH₵ " . number_format($row['net_amount'], 2) . " for \"{$row['title']}\".",
    'success');

log_audit_action($user['id'], 'escrow_released', "Escrow for job #{$jobId} released by {$initiatedBy} (user {$user['id']})");

flash('Escrow payment released successfully.', 'success');
header('Location: request_detail.php?id=' . $jobId);
exit;
