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
$stmt = $pdo->prepare('SELECT * FROM service_requests WHERE id = ? AND status = ?');
$stmt->execute([$requestId, 'open']);
$request = $stmt->fetch();

if (!$request) {
    flash('This job is no longer available.', 'error');
    header('Location: dashboard.php');
    exit;
}

$pdo->beginTransaction();
try {
    $pdo->prepare('UPDATE service_requests SET assigned_worker_id = ?, status = ?, updated_at = NOW() WHERE id = ?')->execute([$user['id'], 'in_progress', $requestId]);
    $pdo->prepare('INSERT INTO applications (request_id, worker_id, status, applied_at) VALUES (?, ?, ?, NOW())')->execute([$requestId, $user['id'], 'accepted']);

    $customerStmt = $pdo->prepare('SELECT id, email, name FROM users WHERE id = ?');
    $customerStmt->execute([$request['customer_id']]);
    $customer = $customerStmt->fetch();
    if ($customer) {
        $message = "Hello {$customer['name']},\n\n" .
                   "Your request titled '{$request['title']}' has been accepted by a worker.\n" .
                   "Please login to AkuapemHub to view details and confirm the next steps.\n\n" .
                   "Thank you.";
        send_email_notification($customer['email'], 'Your AkuapemHub job has been accepted', $message);
        notify_user($customer['id'], 'Job accepted', "Your request '{$request['title']}' has been accepted by a worker.", 'success');
        send_business_message($customer['id'], $request['contact_info'], "AkuapemHub: Your request '{$request['title']}' has been accepted by a worker and is now in progress. Login to view details.", 'whatsapp');
    }

    notify_user($user['id'], 'Job accepted', "You accepted the request '{$request['title']}' and it is now in progress.", 'success');

    $pdo->commit();
    flash('Job accepted successfully.');
} catch (Exception $e) {
    $pdo->rollBack();
    flash('Unable to accept the job. Please try again.', 'error');
}

header('Location: dashboard.php');
exit;
