<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_login();
require_role('customer');
$user = current_user();

$requestId = intval($_GET['request_id'] ?? 0);
if ($requestId <= 0) {
    flash('Invalid request.', 'error');
    header('Location: jobs.php');
    exit;
}

$stmt = $pdo->prepare('SELECT sr.*, u.email AS assigned_worker_email, u.name AS assigned_worker_name FROM service_requests sr LEFT JOIN users u ON sr.assigned_worker_id = u.id WHERE sr.id = ? AND sr.customer_id = ?');
$stmt->execute([$requestId, $user['id']]);
$request = $stmt->fetch();

if (!$request) {
    flash('Request not found.', 'error');
    header('Location: jobs.php');
    exit;
}

if ($request['status'] === 'completed' || $request['status'] === 'cancelled') {
    flash('This request cannot be cancelled.', 'error');
    header('Location: jobs.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $reason = trim($_POST['reason'] ?? '');
    
    if ($reason === '') {
        $error = 'Please provide a reason for cancellation.';
    } else {
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE service_requests SET status = ?, updated_at = NOW() WHERE id = ?')->execute(['cancelled', $requestId]);
            
            notify_user($user['id'], 'Request cancelled', "Your request '{$request['title']}' has been cancelled.", 'warning');
            
            if ($request['assigned_worker_id']) {
                $message = "Hello {$request['assigned_worker_name']},\n\n" .
                           "The request '{$request['title']}' has been cancelled by the customer.\n\n" .
                           "Reason: {$reason}\n\n" .
                           "Thank you.";
                send_email_notification($request['assigned_worker_email'], 'Request cancelled', $message, $request['assigned_worker_id']);
                notify_user($request['assigned_worker_id'], 'Request cancelled', "The request '{$request['title']}' has been cancelled by customer.", 'warning');
            }
            
            send_email_notification(ADMIN_EMAIL, 'Request cancelled', "Request '{$request['title']}' (ID: {$requestId}) has been cancelled by customer.\n\nReason: {$reason}");
            
            $pdo->commit();
            flash('Request cancelled successfully.');
            header('Location: jobs.php');
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Unable to cancel request. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Cancel request — AkuapemHub</title>
    <link rel="stylesheet" href="assets/css/style.css" />
</head>
<body class="has-bottom-nav">
    <header class="app-topbar">
        <span class="brand"><span class="brand-icon">🚫</span> Cancel Request</span>
        <a href="logout.php" class="button button-secondary button-small">Logout</a>
    </header>
    <main class="page-shell small-shell">
        <form class="card form-card" method="post" action="cancel_request.php?request_id=<?php echo $requestId; ?>">
            <?php echo csrf_field(); ?>
            <h2><?php echo sanitize($request['title']); ?></h2>
            <p class="meta">Status: <?php echo strtoupper(str_replace('_', ' ', $request['status'])); ?></p>
            
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo sanitize($error); ?></div>
            <?php endif; ?>
            
            <label>Reason for cancellation</label>
            <textarea name="reason" rows="4" required placeholder="Please tell us why you're cancelling this request..."></textarea>
            
            <p class="meta" style="margin-top: 16px;">
                If this request was accepted, the assigned worker will be notified. The admin will also receive a notification.
            </p>
            
            <button type="submit" class="button button-primary" style="margin-top: 16px;">Cancel request</button>
        </form>
    </main>
    <?php $activeNav = 'jobs'; require __DIR__ . '/partials/bottom_nav.php'; ?>
</body>
</html>
