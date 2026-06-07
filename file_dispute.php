<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_login();
$user = current_user();

$requestId = intval($_GET['request_id'] ?? 0);
if ($requestId <= 0) {
    flash('Invalid request.', 'error');
    header('Location: dashboard.php');
    exit;
}

$stmt = $pdo->prepare('SELECT sr.*, c.name AS customer_name, w.name AS worker_name FROM service_requests sr JOIN users c ON sr.customer_id = c.id LEFT JOIN users w ON sr.assigned_worker_id = w.id WHERE sr.id = ?');
$stmt->execute([$requestId]);
$request = $stmt->fetch();

if (!$request) {
    flash('Request not found.', 'error');
    header('Location: dashboard.php');
    exit;
}

$canReport = is_admin() || $user['id'] === $request['customer_id'] || $user['id'] === $request['assigned_worker_id'];
if (!$canReport) {
    flash('You cannot report this request.', 'error');
    header('Location: dashboard.php');
    exit;
}

$reportedUserId = 0;
if (is_customer() && $user['id'] === $request['customer_id']) {
    $reportedUserId = $request['assigned_worker_id'];
} elseif (is_worker() && $user['id'] === $request['assigned_worker_id']) {
    $reportedUserId = $request['customer_id'];
}

if (!$reportedUserId) {
    flash('Cannot report this request.', 'error');
    header('Location: dashboard.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $disputeType = $_POST['dispute_type'] ?? '';
    $description = trim($_POST['description'] ?? '');
    
    if (!in_array($disputeType, ['quality', 'payment', 'communication', 'no_show', 'other'])) {
        $error = 'Invalid dispute type.';
    } elseif ($description === '') {
        $error = 'Please provide a description.';
    } else {
        try {
            $stmt = $pdo->prepare('INSERT INTO disputes (request_id, reported_by, reported_user_id, dispute_type, description, status, created_at) VALUES (?, ?, ?, ?, ?, "open", NOW())');
            $stmt->execute([$requestId, $user['id'], $reportedUserId, $disputeType, $description]);
            
            notify_user($reportedUserId, 'Dispute filed', "A dispute has been filed against you for request '{$request['title']}'.", 'warning');
            notify_user(1, 'New dispute', "A new dispute has been filed for request '{$request['title']}'.", 'warning');
            
            send_email_notification(ADMIN_EMAIL, 'New dispute filed', "A dispute has been filed:\n\nRequest: {$request['title']}\nType: {$disputeType}\n\nDescription:\n{$description}");
            
            flash('Dispute submitted successfully. An admin will review your report.');
            header('Location: dashboard.php');
            exit;
        } catch (Exception $e) {
            $error = 'Unable to file dispute. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>File dispute — AkuapemHub</title>
    <link rel="stylesheet" href="assets/css/style.css" />
</head>
<body>
    <header class="topbar">
        <a href="dashboard.php" class="button button-secondary button-small">Back</a>
        <h1>File dispute</h1>
        <a href="logout.php" class="button button-secondary button-small">Logout</a>
    </header>
    <main class="page-shell small-shell">
        <form class="card form-card" method="post" action="file_dispute.php?request_id=<?php echo $requestId; ?>">
            <h2><?php echo sanitize($request['title']); ?></h2>
            <p class="meta">Request status: <?php echo strtoupper(str_replace('_', ' ', $request['status'])); ?></p>
            
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo sanitize($error); ?></div>
            <?php endif; ?>
            
            <label>Dispute type</label>
            <select name="dispute_type" required>
                <option value="">Select type</option>
                <option value="quality">Quality of work</option>
                <option value="payment">Payment issue</option>
                <option value="communication">Poor communication</option>
                <option value="no_show">Worker no-show</option>
                <option value="other">Other</option>
            </select>
            
            <label>Description</label>
            <textarea name="description" rows="5" required placeholder="Please describe the issue in detail..."></textarea>
            
            <p class="meta" style="margin-top: 16px;">
                An admin will review your dispute and contact you if more information is needed. We take all reports seriously.
            </p>
            
            <button type="submit" class="button button-primary" style="margin-top: 16px;">Submit dispute</button>
        </form>
    </main>
</body>
</html>
