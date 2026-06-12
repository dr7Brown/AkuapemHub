<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/paystack.php';

require_login();
$user = current_user();

$jobId = intval($_GET['id'] ?? 0);
if ($jobId <= 0) {
    header('Location: dashboard.php');
    exit;
}

$stmt = $pdo->prepare('SELECT sr.*, sc.name AS category_name FROM service_requests sr JOIN service_categories sc ON sr.category_id = sc.id WHERE sr.id = ? AND sr.customer_id = ? AND sr.payment_mode = ?');
$stmt->execute([$jobId, $user['id'], 'escrow']);
$job = $stmt->fetch();

if (!$job) {
    flash('Job not found or not eligible for escrow checkout.', 'error');
    header('Location: dashboard.php');
    exit;
}

// Already paid — job moved to pending/open
if ($job['status'] !== 'pending_payment') {
    flash('This job has already been submitted.', 'info');
    header('Location: request_detail.php?id=' . $jobId);
    exit;
}

$escrow = $pdo->prepare('SELECT * FROM escrow_payments WHERE job_id = ? AND client_id = ?');
$escrow->execute([$jobId, $user['id']]);
$escrow = $escrow->fetch();

if (!$escrow) {
    flash('Escrow record not found. Please contact support.', 'error');
    header('Location: dashboard.php');
    exit;
}

if ($escrow['status'] === 'held') {
    // Payment confirmed via webhook before user hit callback
    flash('Payment already confirmed. Job is pending admin review.', 'info');
    header('Location: request_detail.php?id=' . $jobId);
    exit;
}

$paystackError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if (!paystack_configured()) {
        $paystackError = 'Payment gateway is not configured. Please contact support.';
    } else {
        $result = initializePayment(
            $user['id'],
            $user['email'],
            'escrow_payment',
            $jobId,
            0,
            (float)$escrow['gross_amount'],
            ['job_title' => $job['title'], 'escrow_id' => $escrow['id']]
        );

        if (isset($result['error'])) {
            $paystackError = $result['error'];
        } else {
            // Store the Paystack reference on the escrow record for quick lookup
            $pdo->prepare('UPDATE escrow_payments SET paystack_reference = ?, platform_payment_id = ? WHERE id = ?')
                ->execute([$result['reference'], $result['payment_id'], $escrow['id']]);

            header('Location: ' . $result['checkout_url']);
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Escrow Payment — AkuapemHub</title>
    <link rel="stylesheet" href="assets/css/style.css" />
</head>
<body class="has-bottom-nav">
    <header class="app-topbar">
        <a href="request_detail.php?id=<?php echo $jobId; ?>" class="brand" style="text-decoration:none;">
            <span class="brand-icon">‹</span> Escrow Payment
        </a>
    </header>
    <main class="page-shell small-shell">
        <?php if ($paystackError): ?>
            <div class="alert alert-error"><?php echo sanitize($paystackError); ?></div>
        <?php endif; ?>

        <section class="card" style="margin-bottom:16px;">
            <h1 style="font-size:1.1rem;margin:0 0 4px;"><?php echo sanitize($job['title']); ?></h1>
            <p class="meta" style="margin:0;"><?php echo sanitize($job['category_name']); ?> · <?php echo sanitize($job['location']); ?></p>
        </section>

        <section class="card" style="margin-bottom:16px;">
            <p style="margin:0 0 12px;font-weight:600;">Payment summary</p>
            <table style="width:100%;border-collapse:collapse;font-size:0.95rem;">
                <tr style="border-bottom:1px solid var(--border);">
                    <td style="padding:8px 0;">Worker receives</td>
                    <td style="text-align:right;font-weight:600;">GH₵ <?php echo number_format($escrow['net_amount'], 2); ?></td>
                </tr>
                <tr style="border-bottom:1px solid var(--border);">
                    <td style="padding:8px 0;">Platform fee (<?php echo number_format($escrow['commission_rate'], 0); ?>%)</td>
                    <td style="text-align:right;">GH₵ <?php echo number_format($escrow['commission_amount'], 2); ?></td>
                </tr>
                <tr>
                    <td style="padding:10px 0 4px;font-weight:700;font-size:1.05rem;">Total due today</td>
                    <td style="text-align:right;font-weight:700;font-size:1.15rem;color:var(--primary);">GH₵ <?php echo number_format($escrow['gross_amount'], 2); ?></td>
                </tr>
            </table>
        </section>

        <section class="card" style="margin-bottom:16px;background:var(--surface-muted);">
            <p style="margin:0 0 6px;font-weight:600;">How escrow works</p>
            <ol style="margin:0;padding-left:18px;line-height:1.8;font-size:0.9rem;">
                <li>You pay <strong>GH₵ <?php echo number_format($escrow['gross_amount'], 2); ?></strong> now via Paystack.</li>
                <li>AkuapemHub holds the funds securely after admin approves your job.</li>
                <li>A worker accepts and completes the job.</li>
                <li>You confirm satisfactory completion and release the payment to the worker.</li>
                <li>If you don't release within 7 days of job completion, the payment is auto-released to the worker.</li>
            </ol>
        </section>

        <?php if (paystack_configured()): ?>
            <form method="post" action="escrow_checkout.php?id=<?php echo $jobId; ?>">
                <?php echo csrf_field(); ?>
                <button type="submit" class="button button-primary" style="width:100%;font-size:1.05rem;">
                    Pay GH₵ <?php echo number_format($escrow['gross_amount'], 2); ?> with Paystack
                </button>
            </form>
        <?php else: ?>
            <div class="alert alert-warning">Payment gateway is not yet configured. Please contact an admin to complete setup.</div>
        <?php endif; ?>

        <p class="meta" style="text-align:center;margin-top:12px;">
            <a href="dashboard.php">Save for later</a> — your job draft is saved and you can return any time.
        </p>
    </main>
    <?php $activeNav = 'home'; require __DIR__ . '/partials/bottom_nav.php'; ?>
</body>
</html>
