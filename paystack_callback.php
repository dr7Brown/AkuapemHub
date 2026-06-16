<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/paystack.php';

// Paystack sends both ?reference= and ?trxref= (they're the same value)
$ref = trim($_GET['reference'] ?? $_GET['trxref'] ?? '');

if (!$ref) {
    flash('No payment reference provided.', 'error');
    header('Location: jobs.php');
    exit;
}

$result = verifyPayment($ref);

if ($result['success']) {
    $payment = $result['payment'];

    if (!empty($result['already_paid'])) {
        // Already confirmed — skip receipt, go straight to relevant page
        $alreadyRedirects = [
            'featured_job'        => 'request_detail.php?id=' . $payment['reference_id'],
            'featured_worker'     => 'worker_profile.php',
            'verification'        => 'worker_profile.php',
            'job_post'            => 'jobs.php',
            'worker_service'      => 'worker_profile.php',
            'escrow_payment'      => 'request_detail.php?id=' . $payment['reference_id'],
            'escrow_with_posting' => 'request_detail.php?id=' . $payment['reference_id'],
        ];
        flash('Payment already confirmed.', 'info');
        $redirect = $alreadyRedirects[$payment['payment_type']] ?? 'jobs.php';
    } elseif (($payment['payment_type'] ?? '') === 'escrow_payment') {
        // Special case: if posting fee is still pending and no combined payment was used,
        // skip the receipt and take the user straight to pay posting fee
        $jobId  = (int)$payment['reference_id'];
        $pfStmt = $pdo->prepare("SELECT posting_fee_status FROM service_requests WHERE id = ?");
        $pfStmt->execute([$jobId]);
        $pfRow = $pfStmt->fetch();
        if ($pfRow && $pfRow['posting_fee_status'] === 'pending') {
            flash('Escrow payment confirmed! Now pay the job posting fee to submit your job for review.', 'info');
            $redirect = 'pay_job_post.php?id=' . $jobId;
        } else {
            $redirect = 'platform_receipt.php?ref=' . urlencode($payment['paystack_reference']);
        }
    } else {
        // All other payment types — show receipt
        $redirect = 'platform_receipt.php?ref=' . urlencode($payment['paystack_reference']);
    }
    header('Location: ' . $redirect);
} else {
    $errorMsg = $result['error'] ?? 'Payment could not be confirmed.';
    flash($errorMsg, 'error');
    header('Location: my_payments.php');
}
exit;
