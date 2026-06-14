<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/paystack.php';

// Paystack sends both ?reference= and ?trxref= (they're the same value)
$ref = trim($_GET['reference'] ?? $_GET['trxref'] ?? '');

if (!$ref) {
    flash('No payment reference provided.', 'error');
    header('Location: dashboard.php');
    exit;
}

$result = verifyPayment($ref);

if ($result['success']) {
    $payment = $result['payment'];

    if (!empty($result['already_paid'])) {
        flash('Payment already confirmed.', 'info');
        $redirect = 'dashboard.php';
    } elseif (($payment['payment_type'] ?? '') === 'escrow_payment') {
        $jobId = (int)$payment['reference_id'];
        $pfStmt = $pdo->prepare("SELECT posting_fee_status FROM service_requests WHERE id = ?");
        $pfStmt->execute([$jobId]);
        $pfRow = $pfStmt->fetch();
        if ($pfRow && $pfRow['posting_fee_status'] === 'pending') {
            flash('Escrow payment confirmed! Now pay the job posting fee to submit your job for review.', 'info');
            $redirect = 'pay_job_post.php?id=' . $jobId;
        } else {
            flash('Escrow payment confirmed! Your job is now pending admin review.', 'success');
            $redirect = 'request_detail.php?id=' . $jobId;
        }
    } else {
        flash('Payment confirmed! Your feature has been activated.', 'success');
        $redirects = [
            'featured_job'    => 'request_detail.php?id=' . $payment['reference_id'],
            'featured_worker' => 'worker_profile.php',
            'verification'    => 'worker_profile.php',
            'job_post'        => 'dashboard.php',
            'worker_service'  => 'worker_profile.php',
        ];
        $redirect = $redirects[$payment['payment_type']] ?? 'dashboard.php';
    }
    header('Location: ' . $redirect);
} else {
    $errorMsg = $result['error'] ?? 'Payment could not be confirmed.';
    flash($errorMsg, 'error');
    header('Location: my_payments.php');
}
exit;
