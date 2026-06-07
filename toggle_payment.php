<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_login();
$user = current_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['request_id']) || !isset($_POST['current_status'])) {
    header('Location: dashboard.php');
    exit;
}

$requestId = intval($_POST['request_id']);
$currentStatus = $_POST['current_status'] === 'paid' ? 'paid' : 'unpaid';
$newStatus = $currentStatus === 'paid' ? 'unpaid' : 'paid';

$stmt = $pdo->prepare('SELECT id, budget FROM service_requests WHERE id = ? AND customer_id = ?');
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
    $pdo->commit();
    flash('Payment status updated.');
} catch (Exception $e) {
    $pdo->rollBack();
    flash('Unable to update payment status. Please try again.', 'error');
}
header('Location: dashboard.php');
exit;
