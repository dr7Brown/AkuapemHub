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

    // ── Activate marketplace boost on payment ─────────────────────────────
    if (($payment['payment_type'] ?? '') === 'mp_boost' && empty($result['already_paid'])) {
        $boostStmt = $pdo->prepare('SELECT * FROM mp_boost_orders WHERE id = ?');
        $boostStmt->execute([$payment['reference_id']]);
        $boost = $boostStmt->fetch();
        if ($boost && $boost['status'] === 'pending') {
            require_once __DIR__ . '/marketplace_functions.php';
            // Activate the boost flags on product or shop
            $isSponsored = str_contains($boost['boost_type'], 'sponsored');
            $isProduct   = str_contains($boost['boost_type'], 'product');
            if ($isProduct && $boost['product_id']) {
                $pdo->prepare("UPDATE mp_products SET is_featured=?,featured_end=?,is_sponsored=?,sponsored_end=?,updated_at=NOW() WHERE id=?")
                    ->execute([$isSponsored?0:1,$isSponsored?null:$boost['end_date'],$isSponsored?1:0,$isSponsored?$boost['end_date']:null,$boost['product_id']]);
            } else {
                $pdo->prepare("UPDATE mp_shops SET is_featured=?,featured_end=?,is_sponsored=?,sponsored_end=?,updated_at=NOW() WHERE id=?")
                    ->execute([$isSponsored?0:1,$isSponsored?null:$boost['end_date'],$isSponsored?1:0,$isSponsored?$boost['end_date']:null,$boost['shop_id']]);
            }
            $pdo->prepare("UPDATE mp_boost_orders SET status='active',activated_at=NOW() WHERE id=?")->execute([$boost['id']]);

            $shopOwner = $pdo->prepare('SELECT user_id FROM mp_shops WHERE id=?');
            $shopOwner->execute([$boost['shop_id']]);
            if ($uid = $shopOwner->fetchColumn()) {
                notify_user((int)$uid, 'Boost Activated! ⚡',
                    ucwords(str_replace('_',' ',$boost['boost_type'])) . ' boost is now live until ' . date('d M Y', strtotime($boost['end_date'])) . '.',
                    'success');
            }
        }
    }

    // ── Activate delivery subscription on payment ─────────────────────────
    if (($payment['payment_type'] ?? '') === 'delivery_subscription' && empty($result['already_paid'])) {
        $subStmt = $pdo->prepare('SELECT ds.*, da.user_id AS agent_user_id FROM delivery_subscriptions ds JOIN delivery_agents da ON ds.agent_id=da.id WHERE ds.id=?');
        $subStmt->execute([$payment['reference_id']]);
        $sub = $subStmt->fetch();
        if ($sub && $sub['status'] === 'pending') {
            $pdo->prepare("UPDATE delivery_subscriptions SET status='active',activated_at=NOW() WHERE id=?")->execute([$sub['id']]);
            $pdo->prepare("UPDATE delivery_agents SET is_premium=1,premium_start=?,premium_end=?,updated_at=NOW() WHERE id=?")
                ->execute([$sub['start_date'], $sub['end_date'], $sub['agent_id']]);
            notify_user((int)$sub['agent_user_id'], 'Premium Subscription Activated! ⭐',
                ucfirst($sub['plan_type']) . ' Premium subscription is active until ' . date('d M Y', strtotime($sub['end_date'])) . '.',
                'success');
        }
    }

    // ── Activate delivery sponsored listing on payment ────────────────────
    if (($payment['payment_type'] ?? '') === 'delivery_sponsored' && empty($result['already_paid'])) {
        $spStmt = $pdo->prepare('SELECT dsl.*, da.user_id AS agent_user_id FROM delivery_sponsored_listings dsl JOIN delivery_agents da ON dsl.agent_id=da.id WHERE dsl.id=?');
        $spStmt->execute([$payment['reference_id']]);
        $sp = $spStmt->fetch();
        if ($sp && $sp['status'] === 'pending') {
            $pdo->prepare("UPDATE delivery_sponsored_listings SET status='active',activated_at=NOW() WHERE id=?")->execute([$sp['id']]);
            $pdo->prepare("UPDATE delivery_agents SET is_sponsored=1,sponsored_end=?,updated_at=NOW() WHERE id=?")
                ->execute([$sp['end_date'], $sp['agent_id']]);
            notify_user((int)$sp['agent_user_id'], 'Sponsored Listing Activated! 🌟',
                $sp['package_days'] . '-day sponsored listing is active until ' . date('d M Y', strtotime($sp['end_date'])) . '.',
                'success');
        }
    }

    if (!empty($result['already_paid'])) {
        // Already confirmed — skip receipt, go straight to relevant page
        $alreadyRedirects = [
            'featured_job'          => 'request_detail.php?id=' . $payment['reference_id'],
            'featured_worker'       => 'worker_profile.php',
            'verification'          => 'worker_profile.php',
            'job_post'              => 'jobs.php',
            'worker_service'        => 'worker_profile.php',
            'escrow_payment'        => 'request_detail.php?id=' . $payment['reference_id'],
            'escrow_with_posting'   => 'request_detail.php?id=' . $payment['reference_id'],
            'news_post'             => 'my_news.php',
            'event_post'            => 'my_events.php',
            'funeral_post'          => 'my_funerals.php',
            'featured_event'        => 'my_events.php',
            'featured_funeral'      => 'my_funerals.php',
            'featured_news'         => 'my_news.php',
            'mp_subscription'       => 'seller_dashboard.php',
            'mp_boost'              => 'seller_dashboard.php?tab=products',
            'mp_order'              => 'orders.php',
            'delivery_subscription' => 'delivery_agent_jobs.php',
            'delivery_sponsored'    => 'delivery_agent_jobs.php',
            'delivery_verification' => 'delivery_agent_jobs.php',
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
    } elseif (in_array($payment['payment_type'] ?? '', ['mp_boost','delivery_subscription','delivery_sponsored','delivery_verification'], true)) {
        // New marketing payment types — show universal receipt
        flash('Payment confirmed! Your boost/subscription is now active.', 'success');
        $redirect = 'payment_receipt.php?type=platform_payment&id=' . $payment['id'];
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
