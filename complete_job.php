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
$completionNotes = trim($_POST['completion_notes'] ?? '');
$stmt = $pdo->prepare('SELECT sr.*, u.email AS customer_email, u.name AS customer_name FROM service_requests sr JOIN users u ON sr.customer_id = u.id WHERE sr.id = ? AND sr.assigned_worker_id = ? AND sr.status = ?');
$stmt->execute([$requestId, $user['id'], 'in_progress']);
$request = $stmt->fetch();

if (!$request) {
    flash('Unable to complete this job. Please try again.', 'error');
    header('Location: dashboard.php');
    exit;
}

$pdo->beginTransaction();
try {
    $pdo->prepare('UPDATE service_requests SET status = ?, completion_notes = ?, updated_at = NOW() WHERE id = ?')->execute(['completed', $completionNotes ?: null, $requestId]);

    $message = "Hello {$request['customer_name']},\n\n" .
               "Your request titled '{$request['title']}' has been completed by the worker.\n" .
               (!empty($completionNotes) ? "Worker notes: {$completionNotes}\n\n" : '') .
               "Please review the work, leave a rating, and confirm payment if everything is good.\n\n" .
               "Thank you for using AkuapemHub.";

    send_email_notification($request['customer_email'], 'Your AkuapemHub request is complete', $message);
    notify_user($request['customer_id'], 'Job completed', "Your request '{$request['title']}' has been completed.", 'success');
    notify_user($user['id'], 'Job completed', "You marked '{$request['title']}' as completed.", 'success');

    $pdo->commit();
    flash('Job marked as completed. Customer has been notified.');
} catch (Exception $e) {
    $pdo->rollBack();
    flash('Unable to complete the job. Please try again.', 'error');
}

header('Location: dashboard.php');
exit;
