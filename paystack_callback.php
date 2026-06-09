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
        flash('Payment already confirmed — your feature is active.', 'info');
    } else {
        flash('Payment confirmed! Your feature has been activated.', 'success');
    }

    $redirects = [
        'featured_job'    => 'request_detail.php?id=' . $payment['reference_id'],
        'featured_worker' => 'worker_profile.php',
        'verification'    => 'worker_profile.php',
        'job_post'        => 'dashboard.php',
        'worker_service'  => 'worker_profile.php',
    ];
    $redirect = $redirects[$payment['payment_type']] ?? 'dashboard.php';
    header('Location: ' . $redirect);
} else {
    $errorMsg = $result['error'] ?? 'Payment could not be confirmed.';
    flash($errorMsg, 'error');
    header('Location: my_payments.php');
}
exit;
