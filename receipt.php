<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_login();
require_role('customer');
$user = current_user();

$paymentId = intval($_GET['payment_id'] ?? 0);
if ($paymentId <= 0) {
    header('Location: customer_payments.php');
    exit;
}

$receipt = get_payment_receipt($paymentId, $user['id']);
if (!$receipt || $receipt['status'] !== 'paid') {
    flash('Receipt not available for this transaction.', 'error');
    header('Location: customer_payments.php');
    exit;
}

$amount = is_numeric($receipt['amount']) ? number_format((float)$receipt['amount'], 2) : sanitize($receipt['amount']);
$receiptNumber = 'AKH-' . str_pad($receipt['id'], 6, '0', STR_PAD_LEFT);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Receipt <?php echo sanitize($receiptNumber); ?> — AkuapemHub</title>
    <link rel="stylesheet" href="assets/css/style.css" />
    <style>
        .receipt-card { max-width: 640px; margin: 0 auto; background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 32px; }
        .receipt-head { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #0f766e; padding-bottom: 16px; margin-bottom: 20px; }
        .receipt-table { width: 100%; border-collapse: collapse; margin: 16px 0; }
        .receipt-table td { padding: 8px 0; border-bottom: 1px solid #f1f5f9; }
        .receipt-table td:last-child { text-align: right; }
        .receipt-total { font-size: 1.3rem; font-weight: 700; color: #0f766e; }
        @media print {
            .no-print { display: none !important; }
            body { background: #fff; }
            .receipt-card { border: none; padding: 0; }
        }
    </style>
</head>
<body>
    <header class="topbar no-print">
        <a href="customer_payments.php" class="button button-secondary button-small">Back</a>
        <h1>Receipt</h1>
        <a href="logout.php" class="button button-secondary button-small">Logout</a>
    </header>
    <main class="page-shell small-shell">
        <div class="no-print" style="margin-bottom: 16px; text-align: right;">
            <button onclick="window.print()" class="button button-primary">Print / Save as PDF</button>
        </div>
        <div class="receipt-card">
            <div class="receipt-head">
                <div>
                    <h2 style="margin: 0;"><?php echo sanitize(APP_NAME); ?></h2>
                    <p class="meta" style="margin: 4px 0 0;">Payment receipt</p>
                </div>
                <div style="text-align: right;">
                    <p style="margin: 0;"><strong>Receipt #:</strong> <?php echo sanitize($receiptNumber); ?></p>
                    <p style="margin: 0;"><strong>Date:</strong> <?php echo sanitize(date('M j, Y', strtotime($receipt['created_at']))); ?></p>
                </div>
            </div>

            <p><strong>Billed to:</strong> <?php echo sanitize($receipt['customer_name']); ?> (<?php echo sanitize($receipt['customer_email']); ?>)</p>
            <p><strong>Service provider:</strong> <?php echo sanitize($receipt['worker_name'] ?: 'Not assigned'); ?></p>

            <table class="receipt-table">
                <tbody>
                    <tr>
                        <td>
                            <strong><?php echo sanitize($receipt['title']); ?></strong><br />
                            <span class="meta"><?php echo sanitize($receipt['location']); ?></span>
                        </td>
                        <td>GH₵ <?php echo $amount; ?></td>
                    </tr>
                    <?php if (!empty($receipt['note'])): ?>
                        <tr>
                            <td colspan="2"><span class="meta">Note: <?php echo sanitize($receipt['note']); ?></span></td>
                        </tr>
                    <?php endif; ?>
                    <tr>
                        <td><strong>Total paid</strong></td>
                        <td class="receipt-total">GH₵ <?php echo $amount; ?></td>
                    </tr>
                </tbody>
            </table>

            <p class="meta" style="margin-top: 24px;">Thank you for using <?php echo sanitize(APP_NAME); ?>. This receipt was generated automatically and reflects the payment status recorded in the system.</p>
        </div>
    </main>
</body>
</html>
