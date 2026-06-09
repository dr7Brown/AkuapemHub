<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';

require_login();
if (!is_admin_or_manager()) {
    header('Location: ../dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    if ($_POST['action'] === 'bulk' && !empty($_POST['selected_requests']) && is_array($_POST['selected_requests'])) {
        $requestIds = array_map('intval', $_POST['selected_requests']);
        $bulkAction = $_POST['bulk_action'] ?? '';
        $placeholders = implode(',', array_fill(0, count($requestIds), '?'));
        $stmt = $pdo->prepare("SELECT sr.*, u.email AS customer_email, u.name AS customer_name FROM service_requests sr JOIN users u ON sr.customer_id = u.id WHERE sr.id IN ($placeholders)");
        $stmt->execute($requestIds);
        $selectedRequests = $stmt->fetchAll();

        foreach ($selectedRequests as $request) {
            if ($bulkAction === 'approve') {
                $pdo->prepare('UPDATE service_requests SET status = ? WHERE id = ?')->execute(['open', $request['id']]);
                send_email_notification($request['customer_email'], 'Your request is approved', "Hello {$request['customer_name']},\n\nYour request '{$request['title']}' has been approved by admin and is now visible to workers.\n\nThank you.", $request['customer_id']);
                notify_user($request['customer_id'], 'Request approved', "Your request '{$request['title']}' is now approved and open to workers.", 'success');
                send_business_message($request['customer_id'], $request['contact_info'], "AkuapemHub: Your request '{$request['title']}' has been approved and is now visible to workers.", 'whatsapp');
                notify_workers_of_matching_job($request);
            } elseif ($bulkAction === 'remove') {
                $pdo->prepare('DELETE FROM service_requests WHERE id = ?')->execute([$request['id']]);
                send_email_notification($request['customer_email'], 'Your request has been removed', "Hello {$request['customer_name']},\n\nYour request '{$request['title']}' has been removed by the admin.\n\nContact support for more information.", $request['customer_id']);
                notify_user($request['customer_id'], 'Request removed', "Your request '{$request['title']}' was removed by admin.", 'warning');
            } elseif ($bulkAction === 'feature') {
                $pdo->prepare('UPDATE service_requests SET featured = 1 WHERE id = ?')->execute([$request['id']]);
                send_email_notification($request['customer_email'], 'Your request is featured', "Hello {$request['customer_name']},\n\nYour request '{$request['title']}' has been marked as featured by the admin.\n\nGreat job!\n", $request['customer_id']);
                notify_user($request['customer_id'], 'Request featured', "Your request '{$request['title']}' was marked as featured.", 'success');
            }
        }
    } elseif (!empty($_POST['request_id'])) {
        $requestId = intval($_POST['request_id']);
        $stmt = $pdo->prepare('SELECT sr.*, u.email AS customer_email, u.name AS customer_name FROM service_requests sr JOIN users u ON sr.customer_id = u.id WHERE sr.id = ?');
        $stmt->execute([$requestId]);
        $request = $stmt->fetch();

        if ($_POST['action'] === 'approve' && $request) {
            if (($request['posting_fee_status'] ?? 'free') === 'pending') {
                // Block approval until posting fee is confirmed
                header('Location: requests.php?err=' . urlencode('Cannot approve — posting fee payment not yet confirmed.'));
                exit;
            }
            $pdo->prepare('UPDATE service_requests SET status = ? WHERE id = ?')->execute(['open', $requestId]);
            send_email_notification($request['customer_email'], 'Your request is approved', "Hello {$request['customer_name']},\n\nYour request '{$request['title']}' has been approved by admin and is now visible to workers.\n\nThank you.", $request['customer_id']);
            notify_user($request['customer_id'], 'Request approved', "Your request '{$request['title']}' is now approved and open to workers.", 'success');
            send_business_message($request['customer_id'], $request['contact_info'], "AkuapemHub: Your request '{$request['title']}' has been approved and is now visible to workers.", 'whatsapp');
            notify_workers_of_matching_job($request);
        } elseif ($_POST['action'] === 'remove' && $request) {
            $pdo->prepare('DELETE FROM service_requests WHERE id = ?')->execute([$requestId]);
            send_email_notification($request['customer_email'], 'Your request has been removed', "Hello {$request['customer_name']},\n\nYour request '{$request['title']}' has been removed by the admin.\n\nContact support for more information.", $request['customer_id']);
            notify_user($request['customer_id'], 'Request removed', "Your request '{$request['title']}' was removed by admin.", 'warning');
        } elseif ($_POST['action'] === 'feature' && $request) {
            $pdo->prepare('UPDATE service_requests SET featured = 1 WHERE id = ?')->execute([$requestId]);
            send_email_notification($request['customer_email'], 'Your request is featured', "Hello {$request['customer_name']},\n\nYour request '{$request['title']}' has been marked as featured by the admin.\n\nGreat job!\n", $request['customer_id']);
            notify_user($request['customer_id'], 'Request featured', "Your request '{$request['title']}' was marked as featured.", 'success');
        }
    }
    header('Location: requests.php');
    exit;
}

$errFlash = $_GET['err'] ?? '';
$stmt = $pdo->query('SELECT sr.*, sr.posting_fee_status, u.name AS customer_name, c.name AS category_name FROM service_requests sr JOIN users u ON sr.customer_id = u.id JOIN service_categories c ON sr.category_id = c.id ORDER BY sr.created_at DESC');
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
        <?php if ($errFlash): ?>
            <div class="alert alert-error"><?php echo sanitize($errFlash); ?></div>
        <?php endif; ?>
        <section class="panel">
            <form id="bulk-requests" method="post" action="requests.php" class="filter-form" style="margin-bottom: 16px; gap: 8px; flex-wrap: wrap;">
                <input type="hidden" name="action" value="bulk" />
                <label style="display: flex; align-items: center; gap: 8px;">
                    <span>Bulk action</span>
                    <select name="bulk_action">
                        <option value="approve">Approve selected</option>
                        <option value="remove">Remove selected</option>
                        <option value="feature">Feature selected</option>
                    </select>
                </label>
                <button type="submit" class="button button-primary button-small">Apply</button>
            </form>

            <?php if (!$requests): ?>
                <div class="empty-state">No service requests available.</div>
            <?php else: ?>
                <?php foreach ($requests as $request): ?>
                    <?php $riskSignals = get_request_risk_signals($request); ?>
                    <article class="request-card">
                        <label style="display: block; margin-bottom: 8px; font-size: 0.95rem;">
                            <input type="checkbox" name="selected_requests[]" value="<?php echo $request['id']; ?>" form="bulk-requests" />
                            Select this request
                        </label>
                        <div class="request-head">
                            <h2><?php echo sanitize($request['title']); ?></h2>
                            <span class="status status-<?php echo sanitize($request['status']); ?>"><?php echo strtoupper(str_replace('_', ' ', $request['status'])); ?></span>
                        </div>
                        <?php $feeStatus = $request['posting_fee_status'] ?? 'free'; ?>
                        <?php if ($feeStatus === 'pending'): ?>
                            <div class="alert alert-warning" style="margin-bottom:8px;">
                                💳 <strong>Posting fee pending</strong> — payment not yet confirmed. Cannot approve until fee is paid.
                                <a href="../admin/monetization.php?tab=payments" style="color:var(--primary);margin-left:6px;">Confirm payment →</a>
                            </div>
                        <?php endif; ?>
                        <?php if ($riskSignals): ?>
                            <div class="alert alert-warning">
                                ⚠ Possible spam/fraud signals — review before approving:
                                <ul>
                                    <?php foreach ($riskSignals as $signal): ?>
                                        <li><?php echo sanitize($signal); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                        <p class="meta"><?php echo sanitize($request['category_name']); ?> • <?php echo sanitize($request['location']); ?> • GH₵ <?php echo sanitize($request['budget']); ?></p>
                        <p><?php echo sanitize($request['description']); ?></p>
                        <p>Customer: <?php echo sanitize($request['customer_name']); ?> • Contact: <?php echo sanitize($request['contact_info']); ?></p>
                        <p class="meta">Payment: <?php echo strtoupper($request['payment_status']); ?> • Featured: <?php echo $request['featured'] ? 'Yes' : 'No'; ?></p>
                        <div class="request-footer">
                            <form method="post" class="inline-form" action="requests.php">
                                <input type="hidden" name="request_id" value="<?php echo $request['id']; ?>" />
                                <?php if ($request['status'] !== 'open'): ?>
                                    <?php if ($feeStatus === 'pending'): ?>
                                        <button type="button" class="button button-primary" disabled title="Posting fee not confirmed">Approve (fee pending)</button>
                                    <?php else: ?>
                                        <button type="submit" name="action" value="approve" class="button button-primary">Approve</button>
                                    <?php endif; ?>
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
