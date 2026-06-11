<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_login();
$user = current_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['request_id']) || !isset($_POST['current_status'])) {
    header('Location: dashboard.php');
    exit;
}
csrf_check();

$requestId = intval($_POST['request_id']);
$currentStatus = $_POST['current_status'] === 'paid' ? 'paid' : 'unpaid';
$newStatus = $currentStatus === 'paid' ? 'unpaid' : 'paid';

$stmt = $pdo->prepare('SELECT sr.id, sr.title, sr.budget, sr.assigned_worker_id, w.contact_phone AS worker_phone
    FROM service_requests sr
    LEFT JOIN worker_profiles w ON sr.assigned_worker_id = w.user_id
    WHERE sr.id = ? AND sr.customer_id = ?');
$stmt->execute([$requestId, $user['id']]);
$request = $stmt->fetch();

if (!$request) {
    header('Location: dashboard.php');
    exit;
}

$pdo->beginTransaction();
try {
    $pdo->prepare('UPDATE service_requests SET payment_status = ?, updated_at = NOW() WHERE id = ?')->execute([$newStatus, $requestId]);
    $note = $newStatus === 'paid' ? 'Payment marked paid.' : 'Payment marked unpaid.';
    $pdo->prepare('INSERT INTO payments (request_id, amount, status, note) VALUES (?, ?, ?, ?)')
        ->execute([$requestId, $request['budget'], $newStatus, $note]);

    if ($newStatus === 'paid' && $request['assigned_worker_id'] && !empty($request['worker_phone'])) {
        send_business_message($request['assigned_worker_id'], $request['worker_phone'], "AkuapemHub: Payment of GH₵ {$request['budget']} for '{$request['title']}' has been marked as paid by the customer.", 'whatsapp');
    }

    $pdo->commit();
    flash('Payment status updated.');
} catch (Exception $e) {
    $pdo->rollBack();
    flash('Unable to update payment status. Please try again.', 'error');
}
header('Location: dashboard.php');
exit;
