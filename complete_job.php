<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_login();
require_role('worker');
$user = current_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['request_id'])) {
    header('Location: jobs.php');
    exit;
}
csrf_check();

$requestId = intval($_POST['request_id']);
$completionNotes = trim($_POST['completion_notes'] ?? '');
$stmt = $pdo->prepare("SELECT sr.*, u.email AS customer_email, u.name AS customer_name FROM service_requests sr JOIN users u ON sr.customer_id = u.id WHERE sr.id = ? AND sr.assigned_worker_id = ? AND sr.status IN ('in_progress','fully_staffed')");
$stmt->execute([$requestId, $user['id']]);
$request = $stmt->fetch();

if (!$request) {
    flash('Unable to complete this job. Please try again.', 'error');
    header('Location: jobs.php');
    exit;
}

$pdo->beginTransaction();
try {
    $pdo->prepare('UPDATE service_requests SET status = ?, completion_notes = ?, updated_at = NOW() WHERE id = ?')->execute(['completed', $completionNotes ?: null, $requestId]);

    // Points: worker completed a job
    require_once __DIR__ . '/modules/referrals/service.php';
    award_points((int)$user['id'], 'complete_job', $requestId);

    if (!empty($_FILES['completion_photos'])) {
        save_completion_photos($requestId, $_FILES['completion_photos']);
    }

    // Set escrow auto-release timer if this is an escrow job
    $escrowStmt = $pdo->prepare("SELECT id, auto_release_days FROM escrow_payments WHERE job_id = ? AND status = 'held'");
    $escrowStmt->execute([$requestId]);
    $escrowRec = $escrowStmt->fetch();
    if ($escrowRec) {
        $autoReleaseDays = (int)$escrowRec['auto_release_days'];
        $autoReleaseAt   = date('Y-m-d H:i:s', strtotime('+' . $autoReleaseDays . ' days'));
        $pdo->prepare("UPDATE escrow_payments SET worker_id = ?, auto_release_at = ? WHERE id = ?")
            ->execute([$user['id'], $autoReleaseAt, $escrowRec['id']]);

        $message = "Hello {$request['customer_name']},\n\n" .
                   "Your request titled '{$request['title']}' has been completed by the worker.\n" .
                   (!empty($completionNotes) ? "Worker notes: {$completionNotes}\n\n" : "\n") .
                   "Please review the work and release the escrow payment from your job detail page.\n" .
                   "If you do not release within {$autoReleaseDays} days, the payment will be auto-released to the worker.\n\n" .
                   "Thank you for using AkuapemHub.";
        send_email_notification($request['customer_email'], 'Your AkuapemHub request is complete — release escrow', $message, $request['customer_id']);
        notify_user($request['customer_id'], '💰 Release escrow payment',
            "The worker completed '{$request['title']}'. Review the work and release payment, or it auto-releases in {$autoReleaseDays} days.",
            'info');
    } else {
        $message = "Hello {$request['customer_name']},\n\n" .
                   "Your request titled '{$request['title']}' has been completed by the worker.\n" .
                   (!empty($completionNotes) ? "Worker notes: {$completionNotes}\n\n" : '') .
                   "Please review the work, leave a rating, and confirm payment if everything is good.\n\n" .
                   "Thank you for using AkuapemHub.";
        send_email_notification($request['customer_email'], 'Your AkuapemHub request is complete', $message, $request['customer_id']);
        notify_user($request['customer_id'], 'Job completed', "Your request '{$request['title']}' has been completed.", 'success');
        send_business_message($request['customer_id'], $request['contact_info'], "AkuapemHub: Your request '{$request['title']}' has been marked complete by the worker. Please review the work and confirm payment.", 'whatsapp');
    }

    notify_user($user['id'], 'Job completed', "You marked '{$request['title']}' as completed.", 'success');

    $pdo->commit();
    flash('Job marked as completed. Customer has been notified.');
} catch (Exception $e) {
    $pdo->rollBack();
    flash('Unable to complete the job. Please try again.', 'error');
}

header('Location: jobs.php');
exit;
