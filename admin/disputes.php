<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../marketplace_functions.php';

require_login();
if (!is_admin_or_manager()) {
    header('Location: ../jobs.php');
    exit;
}

require_mod_permission('manage_disputes');
$adminUser = current_user();

$type = ($_GET['type'] ?? 'jobs') === 'delivery' ? 'delivery' : 'jobs';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action']) && !empty($_POST['dispute_id'])) {
    csrf_check();
    $disputeId = intval($_POST['dispute_id']);
    $action = $_POST['action'];
    $resolutionNotes = trim($_POST['resolution_notes'] ?? '');
    $scope = ($_POST['scope'] ?? 'jobs') === 'delivery' ? 'delivery' : 'jobs';

    if ($scope === 'jobs') {
        $stmt = $pdo->prepare('SELECT * FROM disputes WHERE id = ?');
        $stmt->execute([$disputeId]);
        $dispute = $stmt->fetch();

        if ($dispute) {
            if ($action === 'investigating') {
                $pdo->prepare('UPDATE disputes SET status = ? WHERE id = ?')->execute(['investigating', $disputeId]);
                notify_user($dispute['reported_user_id'], 'Dispute update', 'A dispute involving you is being investigated by admin.', 'info');
            } elseif ($action === 'resolved' && $resolutionNotes !== '') {
                $pdo->prepare('UPDATE disputes SET status = ?, resolution_notes = ?, updated_at = NOW() WHERE id = ?')->execute(['resolved', $resolutionNotes, $disputeId]);
                notify_user($dispute['reported_by'], 'Dispute resolved', 'Your dispute has been resolved by admin.', 'success');
                notify_user($dispute['reported_user_id'], 'Dispute resolved', 'A dispute involving you has been resolved.', 'info');
            } elseif ($action === 'closed') {
                $pdo->prepare('UPDATE disputes SET status = ? WHERE id = ?')->execute(['closed', $disputeId]);
                notify_user($dispute['reported_by'], 'Dispute closed', 'Your dispute has been closed.', 'info');
            }
        }
    } else {
        // ── Delivery complaint actions ──────────────────────────────────────────
        $stmt = $pdo->prepare('SELECT * FROM delivery_disputes WHERE id = ?');
        $stmt->execute([$disputeId]);
        $dispute = $stmt->fetch();

        if ($dispute) {
            if ($action === 'investigating') {
                $pdo->prepare('UPDATE delivery_disputes SET status = ?, updated_at = NOW() WHERE id = ?')->execute(['investigating', $disputeId]);
                notify_user((int)$dispute['reported_user_id'], 'Complaint Under Review', 'A delivery complaint involving you is being investigated by admin.', 'info');

            } elseif ($action === 'resolved') {
                // Complaint upheld — refund the linked marketplace order, if any.
                $pdo->prepare('UPDATE delivery_disputes SET status = ?, resolution_notes = ?, updated_at = NOW() WHERE id = ?')
                    ->execute(['resolved', $resolutionNotes ?: null, $disputeId]);

                $mpOrderStmt = $pdo->prepare("SELECT * FROM mp_orders WHERE delivery_request_id=? AND payment_status='paid'");
                $mpOrderStmt->execute([$dispute['delivery_request_id']]);
                $mpOrder = $mpOrderStmt->fetch();

                if ($mpOrder) {
                    // Actually call Paystack for the refund — mp_refund_order() only
                    // does the internal bookkeeping (stock, wallet, order status).
                    require_once __DIR__ . '/../paystack.php';
                    $refundNote = '';
                    if (!empty($mpOrder['platform_payment_id'])) {
                        $payRow = $pdo->prepare('SELECT paystack_transaction_id FROM platform_payments WHERE id=?');
                        $payRow->execute([$mpOrder['platform_payment_id']]);
                        $txId = $payRow->fetchColumn();
                        if ($txId) {
                            $refundResult = paystack_refund((string)$txId, (float)$mpOrder['total_amount']);
                            if (!$refundResult['success']) {
                                $refundNote = ' Paystack refund failed (' . ($refundResult['error'] ?? 'unknown error') . ') — refund the buyer manually.';
                            }
                        } else {
                            $refundNote = ' No Paystack transaction on record — refund the buyer manually.';
                        }
                    } else {
                        $refundNote = ' No payment record linked — refund the buyer manually.';
                    }

                    mp_refund_order($mpOrder, 'Delivery complaint #' . $disputeId . ' resolved in the buyer\'s favor');

                    $refundStatusPhrase = $refundNote
                        ? 'and a refund of GH₵ ' . number_format((float)$mpOrder['total_amount'], 2) . ' is being arranged'
                        : 'and GH₵ ' . number_format((float)$mpOrder['total_amount'], 2) . ' has been refunded';
                    notify_user((int)$mpOrder['customer_id'], 'Complaint Resolved',
                        'Your complaint on order #' . $mpOrder['id'] . ' was upheld ' . $refundStatusPhrase . '.' . ($resolutionNotes ? ' Note: ' . $resolutionNotes : ''),
                        'success');

                    // A rider who never actually delivered the item shouldn't owe
                    // commission on that delivery's fee — reverse it.
                    if ($dispute['dispute_type'] === 'not_delivered') {
                        $ledgerRow = $pdo->prepare("SELECT agent_id, amount FROM delivery_commission_ledger WHERE delivery_request_id=? AND type='commission_owed' LIMIT 1");
                        $ledgerRow->execute([$dispute['delivery_request_id']]);
                        if ($lr = $ledgerRow->fetch()) {
                            $pdo->prepare('UPDATE delivery_agents SET commission_owed = GREATEST(0, commission_owed - ?) WHERE id=?')
                                ->execute([$lr['amount'], $lr['agent_id']]);
                            $pdo->prepare("INSERT INTO delivery_commission_ledger (agent_id, delivery_request_id, type, amount) VALUES (?,?,'reversal',?)")
                                ->execute([$lr['agent_id'], $dispute['delivery_request_id'], -$lr['amount']]);
                        }
                    }

                    if ($refundNote) flash('Complaint upheld.' . $refundNote, 'error');
                } else {
                    notify_user((int)$dispute['reported_by'], 'Complaint Resolved', 'Your delivery complaint was reviewed and upheld.' . ($resolutionNotes ? ' Note: ' . $resolutionNotes : ''), 'success');
                }

                notify_user((int)$dispute['reported_user_id'], 'Delivery Complaint Resolved', 'A delivery complaint against you was upheld by admin.' . ($resolutionNotes ? ' Note: ' . $resolutionNotes : ''), 'warning');

            } elseif ($action === 'dismissed') {
                // No fault found — resume the payout release timer if it was paused.
                $pdo->prepare('UPDATE delivery_disputes SET status = ?, resolution_notes = ?, updated_at = NOW() WHERE id = ?')
                    ->execute(['dismissed', $resolutionNotes ?: null, $disputeId]);

                $mpOrderStmt = $pdo->prepare("SELECT id FROM mp_orders WHERE delivery_request_id=? AND payment_status='paid' AND payout_released=0 AND payout_release_at IS NULL");
                $mpOrderStmt->execute([$dispute['delivery_request_id']]);
                if ($mpOrderId = $mpOrderStmt->fetchColumn()) {
                    $confirmDays = (int)get_platform_setting('mp_payout_confirmation_days', 3);
                    $pdo->prepare('UPDATE mp_orders SET payout_release_at=NOW() + INTERVAL ? DAY, updated_at=NOW() WHERE id=?')
                        ->execute([$confirmDays, $mpOrderId]);
                }

                notify_user((int)$dispute['reported_by'], 'Complaint Reviewed', 'Admin reviewed your delivery complaint and found no issue.' . ($resolutionNotes ? ' Note: ' . $resolutionNotes : ''), 'info');
                notify_user((int)$dispute['reported_user_id'], 'Complaint Dismissed', 'A delivery complaint against you was reviewed and dismissed.', 'info');
            }
            log_audit_action($adminUser['id'], 'delivery_dispute_' . $action, "Delivery complaint #$disputeId set to $action");
        }
    }
    header('Location: disputes.php?type=' . $scope . '&status=' . urlencode($_GET['status'] ?? 'open'));
    exit;
}

$statusFilter = $_GET['status'] ?? 'open';

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 30;
$offset  = ($page - 1) * $perPage;

if ($type === 'jobs') {
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM disputes WHERE status = ?');
    $countStmt->execute([$statusFilter]);
    $disputesTotal = (int)$countStmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT d.*, u1.name AS reported_by_name, u2.name AS reported_user_name, sr.title AS request_title
         FROM disputes d JOIN users u1 ON d.reported_by = u1.id JOIN users u2 ON d.reported_user_id = u2.id JOIN service_requests sr ON d.request_id = sr.id
         WHERE d.status = ? ORDER BY d.created_at DESC, d.id DESC LIMIT $perPage OFFSET $offset");
    $stmt->execute([$statusFilter]);
    $disputes = $stmt->fetchAll();
} else {
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM delivery_disputes WHERE status = ?');
    $countStmt->execute([$statusFilter]);
    $disputesTotal = (int)$countStmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT dd.*, u1.name AS reported_by_name, u2.name AS reported_user_name,
                dr.item_description, dr.pickup_location, dr.dropoff_location
         FROM delivery_disputes dd
         JOIN users u1 ON dd.reported_by = u1.id
         JOIN users u2 ON dd.reported_user_id = u2.id
         JOIN delivery_requests dr ON dd.delivery_request_id = dr.id
         WHERE dd.status = ? ORDER BY dd.created_at DESC, dd.id DESC LIMIT $perPage OFFSET $offset"
    );
    $stmt->execute([$statusFilter]);
    $disputes = $stmt->fetchAll();
}
$disputesTotalPages = max(1, (int)ceil($disputesTotal / $perPage));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Admin Disputes — AkuapemConnect</title>
    <link rel="stylesheet" href="../assets/css/style.css" />
</head>
<body>
    <header class="topbar">
        <a href="index.php" class="button button-secondary button-small">Back</a>
        <h1>Disputes</h1>
        <a href="../logout.php" class="button button-secondary button-small">Logout</a>
    </header>
    <main class="page-shell">
        <section class="panel">
            <?php $flash = get_flash(); if ($flash): ?>
            <div class="alert alert-<?php echo sanitize($flash['type']); ?>" style="margin-bottom:14px;"><?php echo sanitize($flash['message']); ?></div>
            <?php endif; ?>

            <div class="filter-form" style="margin-bottom: 12px; display:flex; gap:6px;">
                <a href="disputes.php?type=jobs&status=open" class="button <?php echo $type === 'jobs' ? 'button-primary' : 'button-secondary'; ?> button-small">📋 Job Disputes</a>
                <a href="disputes.php?type=delivery&status=open" class="button <?php echo $type === 'delivery' ? 'button-primary' : 'button-secondary'; ?> button-small">🚚 Delivery Complaints</a>
            </div>

            <div class="filter-form" style="margin-bottom: 16px;">
                <a href="disputes.php?type=<?php echo $type; ?>&status=open" class="button <?php echo $statusFilter === 'open' ? 'button-primary' : 'button-secondary'; ?> button-small">Open</a>
                <a href="disputes.php?type=<?php echo $type; ?>&status=investigating" class="button <?php echo $statusFilter === 'investigating' ? 'button-primary' : 'button-secondary'; ?> button-small">Investigating</a>
                <a href="disputes.php?type=<?php echo $type; ?>&status=resolved" class="button <?php echo $statusFilter === 'resolved' ? 'button-primary' : 'button-secondary'; ?> button-small">Resolved</a>
                <?php if ($type === 'delivery'): ?>
                <a href="disputes.php?type=delivery&status=dismissed" class="button <?php echo $statusFilter === 'dismissed' ? 'button-primary' : 'button-secondary'; ?> button-small">Dismissed</a>
                <?php endif; ?>
            </div>

            <?php if (empty($disputes)): ?>
                <div class="empty-state">No <?php echo $type === 'delivery' ? 'delivery complaints' : 'disputes'; ?> with status "<?php echo sanitize($statusFilter); ?>".</div>
            <?php elseif ($type === 'jobs'): ?>
                <?php foreach ($disputes as $dispute): ?>
                    <article class="request-card">
                        <div class="request-head">
                            <div>
                                <h2><?php echo sanitize($dispute['request_title']); ?></h2>
                                <p class="meta">Reported by <?php echo sanitize($dispute['reported_by_name']); ?> against <?php echo sanitize($dispute['reported_user_name']); ?></p>
                            </div>
                            <span class="status status-<?php echo sanitize($dispute['status']); ?>"><?php echo strtoupper($dispute['status']); ?></span>
                        </div>
                        <p><strong>Type:</strong> <?php echo strtoupper(str_replace('_', ' ', $dispute['dispute_type'])); ?></p>
                        <p><?php echo sanitize($dispute['description']); ?></p>
                        <?php if ($dispute['resolution_notes']): ?>
                            <p><strong>Resolution:</strong> <?php echo sanitize($dispute['resolution_notes']); ?></p>
                        <?php endif; ?>
                        <p class="meta"><?php echo sanitize(date('M j, Y H:i', strtotime($dispute['created_at']))); ?></p>

                        <div class="request-footer">
                            <?php if ($dispute['status'] === 'open'): ?>
                                <form method="post" class="inline-form">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="dispute_id" value="<?php echo $dispute['id']; ?>" />
                                    <input type="hidden" name="scope" value="jobs" />
                                    <input type="hidden" name="action" value="investigating" />
                                    <button type="submit" class="button button-primary button-small">Investigate</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($dispute['status'] !== 'closed'): ?>
                                <form method="post" style="display: flex; gap: 8px;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="dispute_id" value="<?php echo $dispute['id']; ?>" />
                                    <input type="hidden" name="scope" value="jobs" />
                                    <textarea name="resolution_notes" rows="2" placeholder="Resolution notes..." style="flex: 1;"></textarea>
                                    <input type="hidden" name="action" value="resolved" />
                                    <button type="submit" class="button button-primary button-small">Resolve</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php else: ?>
                <?php foreach ($disputes as $dispute): ?>
                    <article class="request-card">
                        <div class="request-head">
                            <div>
                                <h2>Delivery #<?php echo (int)$dispute['delivery_request_id']; ?> — <?php echo sanitize(mb_substr($dispute['item_description'], 0, 60)); ?></h2>
                                <p class="meta">Reported by <?php echo sanitize($dispute['reported_by_name']); ?> against agent <?php echo sanitize($dispute['reported_user_name']); ?></p>
                                <p class="meta">📍 <?php echo sanitize(mb_substr($dispute['pickup_location'], 0, 40)); ?> → 🏁 <?php echo sanitize(mb_substr($dispute['dropoff_location'], 0, 40)); ?></p>
                            </div>
                            <span class="status status-<?php echo sanitize($dispute['status']); ?>"><?php echo strtoupper($dispute['status']); ?></span>
                        </div>
                        <p><strong>Type:</strong> <?php echo strtoupper(str_replace('_', ' ', $dispute['dispute_type'])); ?></p>
                        <p><?php echo sanitize($dispute['description']); ?></p>
                        <?php if ($dispute['resolution_notes']): ?>
                            <p><strong>Resolution:</strong> <?php echo sanitize($dispute['resolution_notes']); ?></p>
                        <?php endif; ?>
                        <p class="meta"><?php echo sanitize(date('M j, Y H:i', strtotime($dispute['created_at']))); ?>
                            &nbsp;·&nbsp; <a href="../delivery_detail.php?id=<?php echo (int)$dispute['delivery_request_id']; ?>" target="_blank">View delivery →</a>
                        </p>

                        <div class="request-footer">
                            <?php if ($dispute['status'] === 'open'): ?>
                                <form method="post" class="inline-form">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="dispute_id" value="<?php echo $dispute['id']; ?>" />
                                    <input type="hidden" name="scope" value="delivery" />
                                    <input type="hidden" name="action" value="investigating" />
                                    <button type="submit" class="button button-primary button-small">Investigate</button>
                                </form>
                            <?php endif; ?>
                            <?php if (in_array($dispute['status'], ['open','investigating'], true)): ?>
                                <form method="post" style="display: flex; gap: 8px; flex-wrap: wrap; align-items: flex-start;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="dispute_id" value="<?php echo $dispute['id']; ?>" />
                                    <input type="hidden" name="scope" value="delivery" />
                                    <textarea name="resolution_notes" rows="2" placeholder="Resolution notes..." style="flex: 1; min-width:180px;"></textarea>
                                    <button type="submit" name="action" value="resolved" class="button button-small" style="background:#ef4444;color:#fff;border-color:transparent;" onclick="return confirm('Uphold this complaint? If linked to a paid marketplace order, it will be refunded and the seller\'s wallet reversed.');">Uphold &amp; Refund</button>
                                    <button type="submit" name="action" value="dismissed" class="button button-primary button-small">Dismiss</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if ($disputesTotalPages > 1): ?>
            <div style="display:flex;gap:8px;justify-content:center;margin-top:16px;flex-wrap:wrap;">
                <?php for ($p = 1; $p <= $disputesTotalPages; $p++): ?>
                <a href="disputes.php?type=<?php echo $type; ?>&status=<?php echo urlencode($statusFilter); ?>&page=<?php echo $p; ?>"
                   class="button button-small <?php echo $p === $page ? 'button-primary' : 'button-secondary'; ?>"><?php echo $p; ?></a>
                <?php endfor; ?>
            </div>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
