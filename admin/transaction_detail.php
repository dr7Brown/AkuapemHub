<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../paystack.php';

require_login();
if (!is_admin() && !is_manager()) {
    header('Location: ../jobs.php');
    exit;
}
require_mod_permission('view_reports');

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: transactions.php');
    exit;
}

$txStmt = $pdo->prepare("SELECT pp.*, u.name AS user_name, u.email AS user_email, u.username, u.phone, u.role AS user_role
    FROM platform_payments pp JOIN users u ON u.id = pp.user_id WHERE pp.id = ?");
$txStmt->execute([$id]);
$tx = $txStmt->fetch();

if (!$tx) {
    flash('Transaction not found.', 'error');
    header('Location: transactions.php');
    exit;
}

$actionError = '';

// ── POST: handle admin actions ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_admin()) {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'approve' && $tx['status'] === 'pending') {
        // Manually confirm and activate the feature
        $pdo->prepare("UPDATE platform_payments SET status='paid', paid_at=NOW() WHERE id=? AND status='pending'")
            ->execute([$id]);
        if ($pdo->rowCount() > 0) {
            $tx['status']  = 'paid';
            $tx['paid_at'] = date('Y-m-d H:i:s');
            activatePurchasedFeature($tx);
            log_audit_action($user['id'], 'payment_manually_approved', "Payment #{$id} manually approved by admin {$user['id']}");
            flash('Payment approved and feature activated.', 'success');
        }
        header('Location: transaction_detail.php?id=' . $id);
        exit;

    } elseif ($action === 'refund' && in_array($tx['status'], ['paid','pending'], true)) {
        $refundOk = false;
        $refundMsg = '';
        if (!empty($tx['paystack_transaction_id'])) {
            $result = paystack_refund((string)$tx['paystack_transaction_id']);
            $refundOk  = $result['success'];
            $refundMsg = $result['error'] ?? '';
        } else {
            // No Paystack transaction ID — manual refund (gateway not called)
            $refundOk  = true;
            $refundMsg = 'No Paystack transaction ID on record — status updated only (manual).';
        }

        if ($refundOk) {
            $pdo->prepare("UPDATE platform_payments SET status='refunded' WHERE id=?")->execute([$id]);
            // Cascade refund to escrow record if applicable
            if ($tx['payment_type'] === 'escrow_payment') {
                $pdo->prepare("UPDATE escrow_payments SET status='refunded', refunded_at=NOW() WHERE job_id=? AND status IN ('awaiting_payment','held')")
                    ->execute([$tx['reference_id']]);
                $pdo->prepare("UPDATE service_requests SET payment_status='unpaid', updated_at=NOW() WHERE id=? AND payment_status='escrowed'")
                    ->execute([$tx['reference_id']]);
            }

            // Cascade refund to marketplace orders: restore reserved stock, reverse
            // whatever was credited to the seller's wallet, mark orders refunded.
            // (A single Paystack payment can cover several shops' orders at once.)
            if ($tx['payment_type'] === 'mp_order') {
                require_once __DIR__ . '/../marketplace_functions.php';
                $ordStmt = $pdo->prepare("SELECT * FROM mp_orders WHERE platform_payment_id=? AND payment_status='paid'");
                $ordStmt->execute([$id]);
                foreach ($ordStmt->fetchAll() as $mpOrder) {
                    mp_refund_order($mpOrder, 'Refunded by admin (payment #' . $id . ')');
                }
            }
            log_audit_action($user['id'], 'payment_refunded', "Payment #{$id} refunded by admin {$user['id']}. " . $refundMsg);
            notify_user((int)$tx['user_id'], '↩️ Payment refunded',
                "Your payment of {$tx['currency']} " . number_format($tx['amount'], 2) . " (Ref: {$tx['reference_code']}) has been refunded by admin. Please allow 3–5 business days for the funds to appear.",
                'info');
            flash('Refund processed.' . ($refundMsg ? ' Note: ' . $refundMsg : ''), 'success');
        } else {
            flash('Refund failed: ' . $refundMsg, 'error');
        }
        header('Location: transaction_detail.php?id=' . $id);
        exit;

    } elseif ($action === 'flag') {
        $newFlag = $tx['flagged'] ? 0 : 1;
        $pdo->prepare("UPDATE platform_payments SET flagged=? WHERE id=?")->execute([$newFlag, $id]);
        log_audit_action($user['id'], $newFlag ? 'payment_flagged' : 'payment_unflagged', "Payment #{$id} " . ($newFlag ? 'flagged' : 'unflagged') . " by admin {$user['id']}");
        flash($newFlag ? 'Transaction flagged for review.' : 'Flag removed.', 'info');
        header('Location: transaction_detail.php?id=' . $id);
        exit;

    } elseif ($action === 'notes') {
        $notes = trim($_POST['admin_notes'] ?? '');
        $pdo->prepare("UPDATE platform_payments SET admin_notes=? WHERE id=?")->execute([$notes ?: null, $id]);
        log_audit_action($user['id'], 'payment_notes_updated', "Admin notes updated for payment #{$id}");
        flash('Notes saved.', 'success');
        header('Location: transaction_detail.php?id=' . $id);
        exit;

    } elseif ($action === 'delete' && is_admin()) {
        // Soft delete: mark abandoned (hard delete is too destructive)
        if ($tx['status'] === 'pending') {
            $pdo->prepare("UPDATE platform_payments SET status='abandoned' WHERE id=?")->execute([$id]);
            log_audit_action($user['id'], 'payment_abandoned', "Payment #{$id} marked abandoned by admin {$user['id']}");
            flash('Pending transaction removed.', 'info');
            header('Location: transactions.php');
            exit;
        } else {
            flash('Only pending transactions can be deleted.', 'error');
            header('Location: transaction_detail.php?id=' . $id);
            exit;
        }
    }
}

// Reload fresh data after actions
$txStmt->execute([$id]);
$tx = $txStmt->fetch();

// Escrow record (if escrow_payment type)
$escrow = null;
if ($tx['payment_type'] === 'escrow_payment') {
    $escStmt = $pdo->prepare("SELECT ep.*, sr.title AS job_title, sr.status AS job_status
        FROM escrow_payments ep JOIN service_requests sr ON sr.id = ep.job_id
        WHERE ep.job_id = ?");
    $escStmt->execute([$tx['reference_id']]);
    $escrow = $escStmt->fetch();
}

// Build a simple payment timeline
$timeline = [];
$timeline[] = ['created', $tx['created_at'], 'Transaction initiated'];
if ($tx['paid_at']) {
    $timeline[] = ['paid', $tx['paid_at'], 'Payment confirmed'];
}
if ($tx['status'] === 'refunded') {
    $timeline[] = ['refunded', null, 'Refunded'];
}
if ($tx['status'] === 'failed') {
    $timeline[] = ['failed', null, 'Payment failed'];
}
if ($tx['status'] === 'abandoned') {
    $timeline[] = ['abandoned', null, 'Payment abandoned'];
}

$typeLabels = [
    'featured_job'    => 'Featured Job',
    'featured_worker' => 'Featured Worker',
    'verification'    => 'Verification',
    'job_post'        => 'Job Posting Fee',
    'worker_service'  => 'Worker Service',
    'escrow_payment'  => 'Escrow Payment',
];
$statusColors = [
    'paid'      => '#16a34a',
    'pending'   => '#f59e0b',
    'failed'    => '#dc2626',
    'abandoned' => '#94a3b8',
    'refunded'  => '#7c3aed',
];
$methodLabels = [
    'card'         => 'Card',
    'mobile_money' => 'Mobile Money',
    'bank'         => 'Bank Transfer',
    'ussd'         => 'USSD',
    'paystack'     => 'Paystack',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Transaction #<?php echo $id; ?> — AkuapemConnect Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css" />
    <style>
        .pay-nav { display:flex; gap:4px; padding:0 0 16px; flex-wrap:wrap; }
        .pay-nav a { padding:6px 14px; border-radius:var(--radius-sm); background:var(--surface-muted); color:var(--text); text-decoration:none; font-size:0.88rem; border:1px solid var(--border); }
        .pay-nav a.active { background:var(--primary); color:#fff; border-color:var(--primary); }
        .info-card { background:var(--surface-muted); border:1px solid var(--border); border-radius:var(--radius-sm); padding:16px; margin-bottom:16px; }
        .info-card h2 { font-size:0.85rem; text-transform:uppercase; letter-spacing:0.05em; color:var(--muted); margin:0 0 12px; }
        .info-row { display:flex; justify-content:space-between; align-items:center; padding:5px 0; border-bottom:1px solid var(--border); font-size:0.9rem; }
        .info-row:last-child { border-bottom:none; }
        .info-row .label { color:var(--muted); }
        .info-row .value { font-weight:600; text-align:right; }
        .badge-status { display:inline-block; padding:3px 10px; border-radius:4px; font-size:0.8rem; font-weight:600; color:#fff; }
        .timeline { list-style:none; padding:0; margin:0; }
        .timeline li { display:flex; gap:12px; padding-bottom:16px; position:relative; }
        .timeline li::before { content:''; width:10px; height:10px; border-radius:50%; background:var(--primary); flex-shrink:0; margin-top:4px; }
        .timeline li:not(:last-child)::after { content:''; position:absolute; left:4px; top:14px; width:2px; background:var(--border); bottom:0; }
        .action-group { display:flex; flex-wrap:wrap; gap:8px; margin-top:8px; }
    </style>
</head>
<body>
    <header class="topbar">
        <a href="transactions.php" style="text-decoration:none;color:inherit;font-weight:700;">‹ Transactions</a>
        <span style="font-weight:700;margin-left:12px;">Transaction #<?php echo $id; ?></span>
        <div class="topbar-actions">
            <?php if ($tx['flagged']): ?>
                <span style="color:#f59e0b;font-weight:600;">⚑ Flagged</span>
            <?php endif; ?>
        </div>
    </header>
    <main class="page-shell small-shell" style="padding-bottom:40px;">
        <?php foreach (get_flashes() as $msg): ?>
            <div class="alert alert-<?php echo sanitize($msg['type']); ?>"><?php echo $msg['message']; ?></div>
        <?php endforeach; ?>

        <nav class="pay-nav">
            <a href="payments.php">Overview</a>
            <a href="transactions.php" class="active">Transactions</a>
            <a href="escrow.php">Escrow</a>
            <a href="monetization.php">Settings</a>
        </nav>

        <!-- Payment summary -->
        <div class="info-card">
            <h2>Payment</h2>
            <div class="info-row">
                <span class="label">Status</span>
                <span class="value">
                    <span class="badge-status" style="background:<?php echo $statusColors[$tx['status']] ?? '#888'; ?>;">
                        <?php echo strtoupper($tx['status']); ?>
                    </span>
                    <?php if ($tx['flagged']): ?> <span style="color:#f59e0b;">⚑</span><?php endif; ?>
                </span>
            </div>
            <div class="info-row">
                <span class="label">Amount</span>
                <span class="value" style="font-size:1.15rem;color:var(--primary);"><?php echo $tx['currency'] ?? 'GHS'; ?> <?php echo number_format($tx['amount'], 2); ?></span>
            </div>
            <div class="info-row">
                <span class="label">Service type</span>
                <span class="value"><?php echo sanitize($typeLabels[$tx['payment_type']] ?? ucwords(str_replace('_', ' ', $tx['payment_type']))); ?></span>
            </div>
            <div class="info-row">
                <span class="label">Gateway</span>
                <span class="value"><?php echo sanitize(ucfirst($tx['gateway'] ?? 'Paystack')); ?></span>
            </div>
            <div class="info-row">
                <span class="label">Payment method</span>
                <span class="value"><?php
                    $method = $tx['payment_method'] ?: '—';
                    echo sanitize($methodLabels[$method] ?? ucwords(str_replace('_', ' ', $method)));
                ?></span>
            </div>
            <div class="info-row">
                <span class="label">Reference</span>
                <span class="value" style="font-size:0.8rem;font-family:monospace;"><?php echo sanitize($tx['reference_code'] ?? '—'); ?></span>
            </div>
            <?php if ($tx['paystack_reference']): ?>
            <div class="info-row">
                <span class="label">Paystack reference</span>
                <span class="value" style="font-size:0.8rem;font-family:monospace;"><?php echo sanitize($tx['paystack_reference']); ?></span>
            </div>
            <?php endif; ?>
            <?php if ($tx['paystack_transaction_id']): ?>
            <div class="info-row">
                <span class="label">Paystack TX ID</span>
                <span class="value" style="font-family:monospace;"><?php echo sanitize((string)$tx['paystack_transaction_id']); ?></span>
            </div>
            <?php endif; ?>
            <div class="info-row">
                <span class="label">Created</span>
                <span class="value"><?php echo date('d M Y, g:i a', strtotime($tx['created_at'])); ?></span>
            </div>
            <?php if ($tx['paid_at']): ?>
            <div class="info-row">
                <span class="label">Paid at</span>
                <span class="value"><?php echo date('d M Y, g:i a', strtotime($tx['paid_at'])); ?></span>
            </div>
            <?php endif; ?>
        </div>

        <!-- User info -->
        <div class="info-card">
            <h2>User</h2>
            <div class="info-row">
                <span class="label">Name</span>
                <span class="value"><?php echo sanitize($tx['user_name']); ?></span>
            </div>
            <div class="info-row">
                <span class="label">Email</span>
                <span class="value"><?php echo sanitize($tx['user_email']); ?></span>
            </div>
            <div class="info-row">
                <span class="label">Username</span>
                <span class="value"><?php echo sanitize($tx['username']); ?></span>
            </div>
            <div class="info-row">
                <span class="label">Phone</span>
                <span class="value"><?php echo sanitize($tx['phone'] ?? '—'); ?></span>
            </div>
            <div class="info-row">
                <span class="label">Role</span>
                <span class="value"><span class="badge"><?php echo sanitize(ucfirst($tx['user_role'])); ?></span></span>
            </div>
            <div class="info-row">
                <span class="label">User ID</span>
                <span class="value">#<?php echo $tx['user_id']; ?></span>
            </div>
        </div>

        <!-- Escrow details (if applicable) -->
        <?php if ($escrow): ?>
        <div class="info-card" style="border-color:#2563eb;">
            <h2>Escrow Details</h2>
            <?php if (!empty($escrow['job_title'])): ?>
            <div class="info-row">
                <span class="label">Job</span>
                <span class="value">
                    <a href="../request_detail.php?id=<?php echo $tx['reference_id']; ?>" style="color:var(--primary);"><?php echo sanitize($escrow['job_title']); ?></a>
                </span>
            </div>
            <div class="info-row">
                <span class="label">Job status</span>
                <span class="value"><?php echo sanitize(strtoupper(str_replace('_',' ',$escrow['job_status']))); ?></span>
            </div>
            <?php endif; ?>
            <div class="info-row">
                <span class="label">Escrow status</span>
                <span class="value"><?php echo sanitize(ucwords(str_replace('_',' ',$escrow['status']))); ?></span>
            </div>
            <div class="info-row">
                <span class="label">Worker receives</span>
                <span class="value">GH₵ <?php echo number_format($escrow['net_amount'], 2); ?></span>
            </div>
            <div class="info-row">
                <span class="label">Commission</span>
                <span class="value">GH₵ <?php echo number_format($escrow['commission_amount'], 2); ?> (<?php echo number_format($escrow['commission_rate'], 0); ?>%)</span>
            </div>
            <?php if (!empty($escrow['auto_release_at'])): ?>
            <div class="info-row">
                <span class="label">Auto-release</span>
                <span class="value"><?php echo date('d M Y', strtotime($escrow['auto_release_at'])); ?></span>
            </div>
            <?php endif; ?>
            <?php if (!empty($escrow['released_at'])): ?>
            <div class="info-row">
                <span class="label">Released</span>
                <span class="value"><?php echo date('d M Y g:i a', strtotime($escrow['released_at'])); ?> by <?php echo sanitize(ucfirst($escrow['release_initiated_by'] ?? '')); ?></span>
            </div>
            <?php endif; ?>
            <?php if ($escrow['status'] === 'held'): ?>
                <div class="action-group" style="margin-top:12px;">
                    <a href="escrow.php" class="button button-secondary button-small">Manage in Escrow Panel →</a>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Payment timeline -->
        <div class="info-card">
            <h2>Timeline</h2>
            <ul class="timeline">
                <?php foreach ($timeline as [$step, $time, $label]): ?>
                    <li>
                        <div>
                            <strong><?php echo sanitize($label); ?></strong>
                            <?php if ($time): ?>
                                <br><span class="meta"><?php echo date('d M Y, g:i a', strtotime($time)); ?></span>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <!-- Admin notes -->
        <?php if (is_admin()): ?>
        <div class="info-card">
            <h2>Admin Notes</h2>
            <form method="post" action="transaction_detail.php?id=<?php echo $id; ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="notes" />
                <textarea name="admin_notes" rows="4" style="width:100%;box-sizing:border-box;" placeholder="Internal notes (not visible to users)…"><?php echo sanitize($tx['admin_notes'] ?? ''); ?></textarea>
                <button type="submit" class="button button-secondary button-small" style="margin-top:8px;">Save notes</button>
            </form>
        </div>
        <?php endif; ?>

        <!-- Admin actions -->
        <?php if (is_admin()): ?>
        <div class="info-card">
            <h2>Actions</h2>
            <div class="action-group">
                <?php if ($tx['status'] === 'pending'): ?>
                    <form method="post" action="transaction_detail.php?id=<?php echo $id; ?>"
                          onsubmit="return confirm('Manually approve this transaction and activate the feature for the user?')">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="approve" />
                        <button type="submit" class="button button-primary button-small">Approve &amp; Activate</button>
                    </form>
                <?php endif; ?>

                <?php if (in_array($tx['status'], ['paid','pending'], true)): ?>
                    <form method="post" action="transaction_detail.php?id=<?php echo $id; ?>"
                          onsubmit="return confirm('Issue a refund for this transaction? This will call the Paystack refund API and mark the transaction as refunded.')">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="refund" />
                        <button type="submit" class="button button-secondary button-small" style="border-color:#dc2626;color:#dc2626;">Refund</button>
                    </form>
                <?php endif; ?>

                <form method="post" action="transaction_detail.php?id=<?php echo $id; ?>">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="flag" />
                    <button type="submit" class="button button-secondary button-small" style="<?php echo $tx['flagged'] ? 'border-color:#f59e0b;color:#f59e0b;' : ''; ?>">
                        <?php echo $tx['flagged'] ? '⚑ Unflag' : '⚑ Flag'; ?>
                    </button>
                </form>

                <?php if ($tx['status'] === 'pending'): ?>
                    <form method="post" action="transaction_detail.php?id=<?php echo $id; ?>"
                          onsubmit="return confirm('Delete this pending transaction? It will be marked abandoned. This cannot be undone.')">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="delete" />
                        <button type="submit" class="button button-secondary button-small" style="border-color:#dc2626;color:#dc2626;">Delete</button>
                    </form>
                <?php endif; ?>
            </div>
            <p class="meta" style="margin-top:8px;">All actions are logged in the audit trail.</p>
        </div>
        <?php endif; ?>

        <a href="transactions.php" class="button button-secondary" style="margin-top:4px;">← Back to Transactions</a>
    </main>
</body>
</html>
