<?php
/**
 * resume_payment.php — let users complete or restart a pending payment.
 *
 * GET  ?id={payment_id}           → show the pending payment detail + action
 * POST action=resume              → redirect to stored URL or re-initialize
 * POST action=cancel              → mark abandoned, redirect back to origin
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/paystack.php';

require_login();
$user      = current_user();
$paymentId = (int)($_GET['id'] ?? $_POST['payment_id'] ?? 0);

if (!$paymentId) { flash('Invalid payment.', 'error'); header('Location: my_payments.php'); exit; }

// Load payment — must belong to this user and be pending
$stmt = $pdo->prepare(
    "SELECT pp.*,
        CASE pp.payment_type
            WHEN 'featured_job'    THEN sr.title
            WHEN 'job_post'        THEN sr2.title
            ELSE NULL
        END AS job_title,
        CASE pp.payment_type
            WHEN 'featured_job'    THEN COALESCE(fp.name,'—')
            WHEN 'featured_worker' THEN COALESCE(wp.name,'—')
            WHEN 'verification'    THEN COALESCE(vp.name,'—')
            WHEN 'job_post'        THEN COALESCE(jp.name,'—')
            WHEN 'worker_service'  THEN COALESCE(ws.name,'—')
            ELSE '—'
        END AS package_name
     FROM platform_payments pp
     LEFT JOIN service_requests sr  ON pp.payment_type='featured_job' AND sr.id=pp.reference_id
     LEFT JOIN service_requests sr2 ON pp.payment_type='job_post'     AND sr2.id=pp.reference_id
     LEFT JOIN featured_job_packages fp      ON pp.payment_type='featured_job'    AND fp.id=pp.package_id
     LEFT JOIN worker_promotion_packages wp  ON pp.payment_type='featured_worker' AND wp.id=pp.package_id
     LEFT JOIN verification_packages vp      ON pp.payment_type='verification'    AND vp.id=pp.package_id
     LEFT JOIN job_posting_packages jp       ON pp.payment_type='job_post'        AND jp.id=pp.package_id
     LEFT JOIN worker_service_packages ws    ON pp.payment_type='worker_service'  AND ws.id=pp.package_id
     WHERE pp.id=? AND pp.user_id=?"
);
$stmt->execute([$paymentId, $user['id']]);
$payment = $stmt->fetch();

if (!$payment) { flash('Payment not found.', 'error'); header('Location: my_payments.php'); exit; }

// Already completed
if ($payment['status'] === 'paid') {
    flash('This payment has already been completed.', 'info');
    header('Location: my_payments.php'); exit;
}

$isPaystack  = ($payment['gateway'] ?? 'manual') === 'paystack';
$ageMinutes  = (int)round((time() - strtotime($payment['created_at'])) / 60);
$urlStillFresh = !empty($payment['authorization_url']) && $ageMinutes < 45;

$typeLabel = [
    'featured_job'          => 'Featured Job',
    'featured_worker'       => 'Featured Profile',
    'verification'          => 'Verification Badge',
    'job_post'              => 'Job Posting Fee',
    'worker_service'        => 'Service Listing',
    'escrow_payment'        => 'Escrow Payment',
    'escrow_with_posting'   => 'Escrow + Posting Fee',
    'delivery_fee'          => 'Delivery Fee',
    'event_post'            => 'Event Posting',
    'funeral_post'          => 'Funeral Announcement',
    'news_post'             => 'News Article',
    'mp_boost'              => 'Marketplace Boost',
    'mp_order'              => 'Marketplace Order',
    'mp_subscription'       => 'Marketplace Subscription',
    'featured_event'        => 'Featured Event',
    'featured_funeral'      => 'Featured Funeral Announcement',
    'featured_news'         => 'Featured News Article',
    'delivery_subscription' => 'Delivery Premium Subscription',
    'delivery_sponsored'    => 'Delivery Sponsored Listing',
    'delivery_verification' => 'Delivery Rider Verification',
    'delivery_commission'   => 'Delivery Commission',
];

// Origin page to return to after cancelling
$originMap = [
    'featured_job'          => 'feature_job.php',
    'featured_worker'       => 'feature_worker.php',
    'verification'          => 'request_verification.php',
    'job_post'              => 'pay_job_post.php',
    'worker_service'        => 'pay_worker_service.php',
    'event_post'            => 'pay_event.php',
    'funeral_post'          => 'pay_funeral.php',
    'news_post'             => 'pay_news.php',
    'mp_boost'              => 'seller_boost.php',
    'mp_subscription'       => 'pay_mp_subscription.php',
    'mp_order'              => 'cart.php',
    'featured_event'        => 'my_events.php',
    'featured_funeral'      => 'my_funerals.php',
    'featured_news'         => 'my_news.php',
    'delivery_subscription' => 'delivery_subscribe.php',
    'delivery_sponsored'    => 'delivery_agent_jobs.php',
    'delivery_verification' => 'delivery_agent_jobs.php',
    'delivery_commission'   => 'pay_delivery_commission.php',
    'sponsor'               => 'become_sponsor.php',
];
$originPage = $originMap[$payment['payment_type']] ?? 'my_payments.php';

// A cart checkout and a paid quote request both create 'mp_order' payments,
// but a quote-derived order has no cart to go back to — send it back to the
// quote list instead.
if ($payment['payment_type'] === 'mp_order') {
    $qrOriginCheck = $pdo->prepare("SELECT id FROM mp_quote_requests WHERE order_id=? LIMIT 1");
    $qrOriginCheck->execute([$payment['reference_id']]);
    if ($qrOriginCheck->fetchColumn()) $originPage = 'orders.php?view=quotes';
}

// Complimentary members should never be pushed through Paystack for a fee
// that was created before their membership was granted — void the stale
// pending payment and send them back to the feature's own entry point, whose
// fresh page-load already resolves them as free (every one of the ~15 origin
// pages checks complimentary status live, not from a value frozen at
// payment-creation time). Excludes delivery_commission — that's money already
// owed to the platform for services rendered, not a feature-access toggle,
// same category as escrow commission which complimentary membership never waives.
// mp_order (marketplace cart checkout) is likewise excluded — it's a product
// purchase, not a feature-access fee, so complimentary membership never
// applies to it either. escrow_payment / escrow_with_posting are excluded
// too: both always include the job's real escrow deposit (money held for
// the worker, never comped), and escrow_checkout.php already builds
// escrow_with_posting's total via is_feature_paid('enable_paid_job_posting')
// — a complimentary job-posting grant means that combined type is never
// created for them in the first place, so there's nothing to bypass here.
$complimentaryFeatureMap = [
    'featured_job'     => 'enable_paid_featured_jobs',
    'featured_worker'  => 'enable_paid_featured_workers',
    'verification'     => 'enable_paid_verification_badges',
    'job_post'         => 'enable_paid_job_posting',
    'worker_service'   => 'enable_paid_worker_service',
    'worker_premium'   => 'enable_paid_worker_premium',
    'event_post'       => 'event_fee',
    'funeral_post'     => 'funeral_fee',
    'news_post'        => 'news_fee',
    'featured_event'   => 'enable_paid_featured_events',
    'featured_funeral' => 'enable_paid_featured_funerals',
    'featured_news'    => 'enable_paid_featured_news',
    'mp_boost'         => 'mp_boost',
    'mp_subscription'  => 'mp_subscription',
    'delivery_subscription' => 'delivery_premium',
    'delivery_sponsored'    => 'delivery_sponsored',
    'delivery_verification' => 'delivery_verification',
];
$complimentaryFeatureKey = $complimentaryFeatureMap[$payment['payment_type']] ?? null;
if ($payment['status'] === 'pending' && $complimentaryFeatureKey !== null && user_has_complimentary_access($user['id'], $complimentaryFeatureKey)) {
    $pdo->prepare("UPDATE platform_payments SET status='abandoned' WHERE id=? AND user_id=? AND status='pending'")
        ->execute([$paymentId, $user['id']]);
    flash('You have a complimentary membership — this fee no longer applies.', 'success');
    // mp_boost is order-based, not one-shot: send them back to the specific
    // pending order (which now auto-activates for free) rather than the
    // creation page, so a duplicate order doesn't get created.
    $resumeTarget = $payment['payment_type'] === 'mp_boost'
        ? 'pay_mp_boost.php?boost_id=' . (int)$payment['reference_id']
        : $originPage;
    header('Location: ' . $resumeTarget); exit;
}

// ── POST handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    // Cancel — mark abandoned, allow them to start fresh
    if ($action === 'cancel') {
        $pdo->prepare("UPDATE platform_payments SET status='abandoned' WHERE id=? AND user_id=? AND status='pending'")
            ->execute([$paymentId, $user['id']]);

        // Marketplace orders reserve stock at checkout — release it immediately
        // instead of waiting for the 45-minute abandoned-order cron sweep.
        if ($payment['payment_type'] === 'mp_order') {
            require_once __DIR__ . '/marketplace_functions.php';
            $ordStmt = $pdo->prepare("SELECT id FROM mp_orders WHERE platform_payment_id=? AND payment_status='unpaid'");
            $ordStmt->execute([$paymentId]);
            $orderIds = array_column($ordStmt->fetchAll(), 'id');
            if ($orderIds) {
                mp_cancel_order_and_restore_stock($orderIds, 'Buyer cancelled payment');
            }
        }

        log_audit_action($user['id'], 'payment_abandoned', "User cancelled pending payment #{$paymentId} ({$payment['payment_type']})");
        flash('Payment cancelled. You can start a new one below.', 'info');
        header('Location: ' . $originPage); exit;
    }

    // Resume — redirect to stored URL or re-initialize
    if ($action === 'resume') {
        if (!$isPaystack) {
            flash('This is a manual payment — please follow the bank/MoMo instructions and wait for admin confirmation.', 'info');
            header('Location: resume_payment.php?id=' . $paymentId); exit;
        }

        if ($urlStillFresh) {
            // Stored URL is still valid (< 45 min old) — send them straight there
            header('Location: ' . $payment['authorization_url']); exit;
        }

        // URL has expired — re-initialize a fresh Paystack transaction
        // Update the existing record with the new reference (avoids duplicate row)
        $newRef = 'AH-' . strtoupper(str_replace('_', '', $payment['payment_type'])) . '-'
                . time() . '-' . strtoupper(bin2hex(random_bytes(3)));

        $data = paystack_request('POST', '/transaction/initialize', [
            'email'        => $user['email'],
            'amount'       => (int)round((float)$payment['amount'] * 100),
            'reference'    => $newRef,
            'currency'     => 'GHS',
            'callback_url' => rtrim(BASE_URL, '/') . '/paystack_callback.php',
            'metadata'     => ['payment_id' => $paymentId, 'payment_type' => $payment['payment_type']],
            'channels'     => ['card', 'mobile_money'],
        ]);

        if (!$data || !($data['status'] ?? false)) {
            flash('Could not connect to payment gateway. Please try again.', 'error');
            header('Location: resume_payment.php?id=' . $paymentId); exit;
        }

        $newUrl = $data['data']['authorization_url'];
        $pdo->prepare(
            "UPDATE platform_payments
             SET reference_code=?, paystack_reference=?, authorization_url=?, created_at=NOW()
             WHERE id=? AND user_id=?"
        )->execute([$newRef, $newRef, $newUrl, $paymentId, $user['id']]);

        log_audit_action($user['id'], 'payment_resumed', "Re-initialized payment #{$paymentId} with new ref {$newRef}");
        header('Location: ' . $newUrl); exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Complete Payment — AkuapemConnect</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .rp-card { background:var(--surface); border:2px solid #f59e0b; border-radius:16px; padding:24px; max-width:480px; margin:24px auto; }
        .rp-amount { font-size:2rem; font-weight:900; color:var(--primary,#0f766e); letter-spacing:-.02em; margin:0 0 4px; }
        .rp-label  { font-size:.86rem; color:var(--muted,#6b7280); font-weight:600; margin:0 0 16px; }
        .rp-ref    { font-family:monospace; font-size:.82rem; background:var(--surface-muted,#f3f4f6); border-radius:8px; padding:8px 12px; word-break:break-all; }
        .rp-age    { font-size:.76rem; color:var(--muted,#6b7280); margin-top:8px; }
        .rp-notice { background:#fef3c7; border:1px solid #f59e0b; border-radius:10px; padding:12px 14px; font-size:.84rem; margin:16px 0; }
        .rp-manual { background:var(--surface-muted,#f8fafc); border:1px solid var(--border); border-radius:12px; padding:16px; margin:16px 0; }
        .rp-actions { display:flex; gap:10px; flex-wrap:wrap; margin-top:20px; }
    </style>
</head>
<body class="has-bottom-nav">

<header class="app-topbar">
    <a href="my_payments.php" class="button button-secondary button-small">← My Payments</a>
    <span class="brand">Complete Payment</span>
</header>

<main class="page-shell small-shell">

    <?php foreach (get_flashes() as $f): ?>
    <div class="alert alert-<?php echo sanitize($f['type']); ?>"><?php echo $f['message']; ?></div>
    <?php endforeach; ?>

    <div class="rp-card">
        <!-- Amount + type -->
        <p class="rp-amount">GH₵ <?php echo number_format((float)$payment['amount'], 2); ?></p>
        <p class="rp-label">
            <?php echo sanitize($typeLabel[$payment['payment_type']] ?? $payment['payment_type']); ?>
            <?php if ($payment['package_name'] && $payment['package_name'] !== '—'): ?> — <?php echo sanitize($payment['package_name']); ?><?php endif; ?>
            <?php if ($payment['job_title']): ?><br>📋 <?php echo sanitize(mb_substr($payment['job_title'], 0, 60)); ?><?php endif; ?>
        </p>

        <!-- Reference -->
        <p style="font-size:.74rem;font-weight:700;color:var(--muted,#6b7280);margin:0 0 4px;">Reference</p>
        <div class="rp-ref"><?php echo sanitize(strtoupper($payment['reference_code'])); ?></div>
        <p class="rp-age">
            Initiated <?php echo time_ago($payment['created_at']); ?>
            <?php if ($isPaystack && !$urlStillFresh): ?>
            · <span style="color:#f59e0b;font-weight:700;">Link expired — a new one will be generated</span>
            <?php elseif ($isPaystack): ?>
            · <span style="color:#10b981;font-weight:700;">Checkout link still active</span>
            <?php endif; ?>
        </p>

        <?php if ($isPaystack): ?>
        <!-- Paystack pending -->
        <div class="rp-notice">
            ⏳ Your payment was started but not completed. Click below to continue where you left off.
            <?php if (!$urlStillFresh): ?>
            A fresh checkout session will be created — same amount, same package.
            <?php endif; ?>
        </div>

        <div class="rp-actions">
            <form method="post" action="resume_payment.php" style="flex:1;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="payment_id" value="<?php echo $paymentId; ?>">
                <input type="hidden" name="action" value="resume">
                <button type="submit" class="button button-primary" style="width:100%;padding:13px;font-size:.96rem;">
                    💳 <?php echo $urlStillFresh ? 'Continue to Checkout →' : 'Restart Payment →'; ?>
                </button>
            </form>
        </div>
        <form method="post" action="resume_payment.php" style="margin-top:10px;">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="payment_id" value="<?php echo $paymentId; ?>">
            <input type="hidden" name="action" value="cancel">
            <button type="submit" class="button button-secondary" style="width:100%;"
                    onclick="return confirm('Cancel this payment? You can start a new one after.')">
                ✕ Cancel &amp; Start Fresh
            </button>
        </form>

        <?php else: ?>
        <!-- Manual / bank transfer pending -->
        <div class="rp-manual">
            <p style="font-weight:800;font-size:.9rem;margin:0 0 10px;">How to complete your payment</p>
            <ol style="margin:0;padding-left:18px;font-size:.84rem;line-height:1.9;color:var(--text,#1a2230);">
                <li>Transfer <strong>GH₵ <?php echo number_format((float)$payment['amount'], 2); ?></strong> via Mobile Money or bank transfer.</li>
                <li>Use reference <strong><?php echo sanitize(strtoupper($payment['reference_code'])); ?></strong> as your payment note/narration.</li>
                <li>Send your proof of payment to admin for confirmation.</li>
                <li>Your account will be activated within 24 hours of confirmation.</li>
            </ol>
        </div>
        <p style="font-size:.78rem;color:var(--muted,#6b7280);text-align:center;margin:12px 0 0;">
            Already paid? <a href="settings.php" style="color:var(--primary,#0f766e);font-weight:700;">Contact support →</a>
        </p>
        <form method="post" action="resume_payment.php" style="margin-top:14px;">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="payment_id" value="<?php echo $paymentId; ?>">
            <input type="hidden" name="action" value="cancel">
            <button type="submit" class="button button-secondary" style="width:100%;"
                    onclick="return confirm('Cancel this payment?')">
                ✕ Cancel Payment
            </button>
        </form>
        <?php endif; ?>
    </div>

</main>

<?php $activeNav = 'settings'; require __DIR__ . '/partials/bottom_nav.php'; ?>
</body>
</html>
