<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';

require_login();
if (!is_admin()) {
    header('Location: ../dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action']) && !empty($_POST['request_id'])) {
    $requestId = intval($_POST['request_id']);
    $stmt = $pdo->prepare('SELECT sr.*, u.email AS customer_email, u.name AS customer_name FROM service_requests sr JOIN users u ON sr.customer_id = u.id WHERE sr.id = ?');
    $stmt->execute([$requestId]);
    $request = $stmt->fetch();

    if ($_POST['action'] === 'approve' && $request) {
        $pdo->prepare('UPDATE service_requests SET status = ? WHERE id = ?')->execute(['open', $requestId]);
        send_email_notification($request['customer_email'], 'Your request is approved', "Hello {$request['customer_name']},\n\nYour request '{$request['title']}' has been approved by admin and is now visible to workers.\n\nThank you.");
        notify_user($request['customer_id'], 'Request approved', "Your request '{$request['title']}' is now approved and open to workers.", 'success');
    } elseif ($_POST['action'] === 'remove' && $request) {
        $pdo->prepare('DELETE FROM service_requests WHERE id = ?')->execute([$requestId]);
        send_email_notification($request['customer_email'], 'Your request has been removed', "Hello {$request['customer_name']},\n\nYour request '{$request['title']}' has been removed by the admin.\n\nContact support for more information.");
        notify_user($request['customer_id'], 'Request removed', "Your request '{$request['title']}' was removed by admin.", 'warning');
    } elseif ($_POST['action'] === 'feature' && $request) {
        $pdo->prepare('UPDATE service_requests SET featured = 1 WHERE id = ?')->execute([$requestId]);
        send_email_notification($request['customer_email'], 'Your request is featured', "Hello {$request['customer_name']},\n\nYour request '{$request['title']}' has been marked as featured by the admin.\n\nGreat job!\n");
        notify_user($request['customer_id'], 'Request featured', "Your request '{$request['title']}' was marked as featured.", 'success');
    }
    header('Location: requests.php');
    exit;
}

$stmt = $pdo->query('SELECT sr.*, u.name AS customer_name, c.name AS category_name FROM service_requests sr JOIN users u ON sr.customer_id = u.id JOIN service_categories c ON sr.category_id = c.id ORDER BY sr.created_at DESC');
$requests = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Admin Requests — AkuapemHub</title>
    <link rel="stylesheet" href="../assets/css/style.css" />
</head>
<body>
    <header class="topbar">
        <a href="index.php" class="button button-secondary button-small">Back</a>
        <h1>Requests</h1>
        <a href="../logout.php" class="button button-secondary button-small">Logout</a>
    </header>
    <main class="page-shell">
        <section class="panel">
            <?php if (!$requests): ?>
                <div class="empty-state">No service requests available.</div>
            <?php else: ?>
                <?php foreach ($requests as $request): ?>
                    <article class="request-card">
                        <div class="request-head">
                            <h2><?php echo sanitize($request['title']); ?></h2>
                            <span class="status status-<?php echo sanitize($request['status']); ?>"><?php echo strtoupper(str_replace('_', ' ', $request['status'])); ?></span>
                        </div>
                        <p class="meta"><?php echo sanitize($request['category_name']); ?> • <?php echo sanitize($request['location']); ?> • GH₵ <?php echo sanitize($request['budget']); ?></p>
                        <p><?php echo sanitize($request['description']); ?></p>
                        <p>Customer: <?php echo sanitize($request['customer_name']); ?> • Contact: <?php echo sanitize($request['contact_info']); ?></p>
                        <p class="meta">Payment: <?php echo strtoupper($request['payment_status']); ?> • Featured: <?php echo $request['featured'] ? 'Yes' : 'No'; ?></p>
                        <div class="request-footer">
                            <form method="post" class="inline-form" action="requests.php">
                                <input type="hidden" name="request_id" value="<?php echo $request['id']; ?>" />
                                <?php if ($request['status'] !== 'open'): ?>
                                    <button type="submit" name="action" value="approve" class="button button-primary">Approve</button>
                                <?php endif; ?>
                                <button type="submit" name="action" value="remove" class="button button-secondary">Remove</button>
                                <button type="submit" name="action" value="feature" class="button button-primary">Feature</button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
