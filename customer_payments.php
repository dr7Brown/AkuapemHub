<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_login();
require_role('customer');
$user = current_user();

$paymentHistory = get_customer_payment_history($user['id'], 50);
$totalSpent = get_customer_spending_total($user['id']);
$paidCount = 0;
foreach ($paymentHistory as $p) {
    if ($p['status'] === 'paid') $paidCount++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Payment History — AkuapemConnect</title>
    <link rel="stylesheet" href="assets/css/style.css" />
</head>
<body class="has-bottom-nav">
    <header class="app-topbar">
        <span class="brand"><span class="brand-icon">💳</span> Payment History</span>
        <a href="logout.php" class="button button-secondary button-small">Logout</a>
    </header>
    <main class="page-shell">
        <section class="panel stats-grid">
            <div class="stat-card">
                <h2>GH₵ <?php echo number_format($totalSpent, 2); ?></h2>
                <p>Total spent</p>
            </div>
            <div class="stat-card">
                <h2><?php echo $paidCount; ?></h2>
                <p>Paid transactions</p>
            </div>
            <div class="stat-card">
                <h2><?php echo count($paymentHistory); ?></h2>
                <p>Total transactions</p>
            </div>
        </section>
        <section class="panel">
            <h2>Payment records</h2>
            <?php if (!$paymentHistory): ?>
                <div class="empty-state">No payment records yet.</div>
            <?php else: ?>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Job title</th>
                                <th>Worker</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Note</th>
                                <th>Date</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($paymentHistory as $payment): ?>
                                <tr>
                                    <td><a href="request_detail.php?id=<?php echo $payment['request_id']; ?>" style="color: #0f766e; text-decoration: none;"><?php echo sanitize($payment['title']); ?></a></td>
                                    <td><?php echo sanitize($payment['worker_name'] ?: 'Not assigned'); ?></td>
                                    <td>GH₵ <?php echo sanitize($payment['amount']); ?></td>
                                    <td><span class="status status-<?php echo $payment['status'] === 'paid' ? 'success' : 'warning'; ?>"><?php echo strtoupper($payment['status']); ?></span></td>
                                    <td><?php echo sanitize($payment['note'] ?: '—'); ?></td>
                                    <td><?php echo sanitize(date('M j, Y', strtotime($payment['created_at']))); ?></td>
                                    <td>
                                        <?php if ($payment['status'] === 'paid'): ?>
                                            <a href="receipt.php?payment_id=<?php echo $payment['id']; ?>" class="button button-secondary button-small">Receipt</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </main>
    <?php $activeNav = 'jobs'; require __DIR__ . '/partials/bottom_nav.php'; ?>
</body>
</html>
