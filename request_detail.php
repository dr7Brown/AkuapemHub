<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_login();
$user = current_user();
$requestId = intval($_GET['id'] ?? 0);
if ($requestId <= 0) {
    header('Location: dashboard.php');
    exit;
}

$stmt = $pdo->prepare('SELECT sr.*, c.name AS customer_name, c.email AS customer_email, w.name AS worker_name, wc.name AS category_name FROM service_requests sr JOIN users c ON sr.customer_id = c.id JOIN service_categories wc ON sr.category_id = wc.id LEFT JOIN users w ON sr.assigned_worker_id = w.id WHERE sr.id = ?');
$stmt->execute([$requestId]);
$request = $stmt->fetch();

if (!$request) {
    header('Location: dashboard.php');
    exit;
}

$canView = is_admin() || $request['customer_id'] === $user['id'] || (is_worker() && ($request['status'] === 'open' || $request['assigned_worker_id'] === $user['id']));
if (!$canView) {
    header('Location: dashboard.php');
    exit;
}

$canAccept = is_worker() && $request['status'] === 'open';
$canComplete = is_worker() && $request['status'] === 'in_progress' && $request['assigned_worker_id'] === $user['id'];
$canMarkPaid = is_customer() && $request['status'] === 'completed' && $request['customer_id'] === $user['id'];
$canRate = is_customer() && $request['status'] === 'completed' && $request['customer_id'] === $user['id'];

$ratingExists = false;
if ($canRate) {
    $ratingStmt = $pdo->prepare('SELECT id FROM ratings WHERE request_id = ? AND customer_id = ?');
    $ratingStmt->execute([$requestId, $user['id']]);
    $ratingExists = (bool)$ratingStmt->fetch();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Request details — AkuapemHub</title>
    <link rel="stylesheet" href="assets/css/style.css" />
</head>
<body>
    <header class="topbar">
        <a href="dashboard.php" class="button button-secondary button-small">Back</a>
        <h1>Request details</h1>
        <a href="logout.php" class="button button-secondary button-small">Logout</a>
    </header>
    <main class="page-shell">
        <section class="panel">
            <div class="request-head">
                <div>
                    <h2><?php echo sanitize($request['title']); ?></h2>
                    <p class="meta"><?php echo sanitize($request['category_name']); ?> • <?php echo sanitize($request['location']); ?></p>
                </div>
                <span class="status status-<?php echo sanitize($request['status']); ?>"><?php echo strtoupper(str_replace('_', ' ', $request['status'])); ?></span>
            </div>
            <p><?php echo sanitize($request['description']); ?></p>
            <p><strong>Budget:</strong> GH₵ <?php echo sanitize($request['budget']); ?></p>
            <p><strong>Customer:</strong> <?php echo sanitize($request['customer_name']); ?> (<?php echo sanitize($request['customer_email']); ?>)</p>
            <p><strong>Contact:</strong> <?php echo sanitize($request['contact_info']); ?></p>
            <?php if ($request['assigned_worker_id']): ?>
                <p><strong>Assigned worker:</strong> <?php echo sanitize($request['worker_name'] ?: 'Worker'); ?></p>
            <?php endif; ?>
            <?php if (!empty($request['completion_notes'])): ?>
                <p><strong>Completion notes:</strong> <?php echo sanitize($request['completion_notes']); ?></p>
            <?php endif; ?>
            <p><strong>Featured:</strong> <?php echo $request['featured'] ? 'Yes' : 'No'; ?></p>
            <p><strong>Payment status:</strong> <?php echo strtoupper($request['payment_status']); ?></p>
            <div class="request-footer">
                <a href="<?php echo whatsapp_share_link($request['title'], $request['location'], $request['budget'], BASE_URL . '/request_detail.php?id=' . $request['id']); ?>" target="_blank" class="button button-secondary button-small">Share WhatsApp</a>
                <?php $contactUrl = whatsapp_contact_link($request['contact_info'], $request['title']); ?>
                <?php if ($contactUrl): ?>
                    <a href="<?php echo $contactUrl; ?>" target="_blank" class="button button-secondary button-small">Contact via WhatsApp</a>
                <?php endif; ?>
                <a href="messages.php?request_id=<?php echo $request['id']; ?>" class="button button-secondary button-small">Messages</a>
                <?php if ($canAccept): ?>
                    <form method="post" action="accept_job.php">
                        <input type="hidden" name="request_id" value="<?php echo $request['id']; ?>" />
                        <button type="submit" class="button button-primary">Accept job</button>
                    </form>
                <?php endif; ?>
                <?php if ($canComplete): ?>
                    <form method="post" action="complete_job.php" style="display: flex; flex-direction: column; gap: 8px; width: 100%; max-width: 480px;">
                        <input type="hidden" name="request_id" value="<?php echo $request['id']; ?>" />
                        <textarea name="completion_notes" rows="3" placeholder="Add completion notes or evidence summary..."></textarea>
                        <button type="submit" class="button button-primary">Mark completed</button>
                    </form>
                <?php endif; ?>
                <?php if ($canMarkPaid): ?>
                    <form method="post" action="toggle_payment.php" class="inline-form">
                        <input type="hidden" name="request_id" value="<?php echo $request['id']; ?>" />
                        <input type="hidden" name="current_status" value="<?php echo sanitize($request['payment_status']); ?>" />
                        <button type="submit" class="button button-primary button-small">Mark as <?php echo $request['payment_status'] === 'paid' ? 'Unpaid' : 'Paid'; ?></button>
                    </form>
                <?php endif; ?>
                <?php if ($canRate && !$ratingExists): ?>
                    <a href="rate_job.php?request_id=<?php echo $request['id']; ?>" class="button button-secondary button-small">Rate worker</a>
                <?php endif; ?>
                <?php if (is_customer() && $request['customer_id'] === $user['id'] && $request['status'] !== 'completed' && $request['status'] !== 'cancelled'): ?>
                    <a href="cancel_request.php?request_id=<?php echo $request['id']; ?>" class="button button-secondary button-small">Cancel request</a>
                    <a href="file_dispute.php?request_id=<?php echo $request['id']; ?>" class="button button-secondary button-small">File dispute</a>
                <?php elseif (is_worker() && $request['assigned_worker_id'] === $user['id'] && $request['status'] !== 'cancelled'): ?>
                    <a href="file_dispute.php?request_id=<?php echo $request['id']; ?>" class="button button-secondary button-small">File dispute</a>
                <?php endif; ?>
            </div>
        </section>
    </main>
</body>
</html>
