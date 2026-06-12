<?php
/**
 * Paystack payment service layer.
 * Requires auth.php + functions.php to already be included.
 * Never outputs HTML — returns data or calls exit on webhook.
 */

// ── Key helpers ───────────────────────────────────────────────────────────────

function paystack_keys(): array {
    return [
        'secret'  => get_platform_setting('paystack_secret_key', ''),
        'public'  => get_platform_setting('paystack_public_key', ''),
        'webhook' => get_platform_setting('paystack_webhook_secret', ''),
        'mode'    => get_platform_setting('paystack_mode', 'test'),
    ];
}

function paystack_configured(): bool {
    return paystack_keys()['secret'] !== '';
}

// ── Core API call ─────────────────────────────────────────────────────────────

function paystack_request(string $method, string $endpoint, array $body = []): ?array {
    $keys = paystack_keys();
    if (!$keys['secret']) return null;

    $ch = curl_init('https://api.paystack.co' . $endpoint);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $keys['secret'],
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 15,
    ];
    if ($method === 'POST') {
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = json_encode($body);
    }
    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);
    curl_close($ch);

    return $response ? json_decode($response, true) : null;
}

// ── Initialize payment ────────────────────────────────────────────────────────
// Returns: ['checkout_url'=>'...', 'reference'=>'...', 'payment_id'=>int]
// Or:      ['error'=>'...'] on failure
// Calling code must handle redirect.

function initializePayment(
    int $userId,
    string $email,
    string $paymentType,
    int $referenceId,
    int $packageId,
    float $amount,
    array $meta = []
): array {
    global $pdo;

    if (!paystack_configured()) {
        return ['error' => 'Payment gateway is not configured. Please contact the administrator.'];
    }

    // Unique reference: AH-TYPE-TIMESTAMP-RANDOM6
    $ref = 'AH-' . strtoupper(str_replace('_', '', $paymentType)) . '-'
         . time() . '-' . strtoupper(bin2hex(random_bytes(3)));

    // Insert pending record
    $pdo->prepare('INSERT INTO platform_payments
        (user_id, payment_type, reference_id, package_id, amount, status, reference_code, paystack_reference, currency, gateway, created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,NOW())')
        ->execute([$userId, $paymentType, $referenceId, $packageId, $amount, 'pending', $ref, $ref, 'GHS', 'paystack']);
    $paymentId = (int)$pdo->lastInsertId();

    $callbackUrl = rtrim(BASE_URL, '/') . '/paystack_callback.php';

    $data = paystack_request('POST', '/transaction/initialize', [
        'email'        => $email,
        'amount'       => (int)round($amount * 100), // GHS pesewas
        'reference'    => $ref,
        'currency'     => 'GHS',
        'callback_url' => $callbackUrl,
        'metadata'     => array_merge(['payment_id' => $paymentId, 'payment_type' => $paymentType], $meta),
        'channels'     => ['card', 'mobile_money'],
    ]);

    if (!$data || !($data['status'] ?? false)) {
        // Roll back the insert so duplicate-check doesn't false-block next attempt
        $pdo->prepare('DELETE FROM platform_payments WHERE id = ?')->execute([$paymentId]);
        return ['error' => $data['message'] ?? 'Could not reach payment gateway. Please try again.'];
    }

    return [
        'payment_id'   => $paymentId,
        'checkout_url' => $data['data']['authorization_url'],
        'access_code'  => $data['data']['access_code'],
        'reference'    => $ref,
    ];
}

// ── Verify & activate ─────────────────────────────────────────────────────────
// Returns: ['success'=>true, 'payment'=>[...]]
// Or:      ['success'=>false, 'error'=>'...']

function verifyPayment(string $reference): array {
    global $pdo;

    // Fetch our local record first
    $stmt = $pdo->prepare("SELECT * FROM platform_payments WHERE paystack_reference = ?");
    $stmt->execute([$reference]);
    $payment = $stmt->fetch();

    if (!$payment) {
        return ['success' => false, 'error' => 'Payment record not found.'];
    }

    // Idempotency — already processed
    if ($payment['status'] === 'paid') {
        return ['success' => true, 'already_paid' => true, 'payment' => $payment];
    }

    $data = paystack_request('GET', '/transaction/verify/' . urlencode($reference));

    if (!$data || !($data['status'] ?? false)) {
        return ['success' => false, 'error' => $data['message'] ?? 'Verification request failed.'];
    }

    $tx = $data['data'];

    if ($tx['status'] === 'success') {
        // Verify amount (Paystack returns in pesewas)
        $expectedPesewas = (int)round($payment['amount'] * 100);
        if ((int)$tx['amount'] < $expectedPesewas) {
            return ['success' => false, 'error' => 'Amount mismatch — payment not confirmed.'];
        }

        $txId = isset($tx['id']) ? (int)$tx['id'] : null;

        // Atomic UPDATE — only succeeds once; prevents double-activation on concurrent
        // webhook + callback calls for the same reference.
        $upd = $pdo->prepare("UPDATE platform_payments SET status = 'paid', paystack_transaction_id = ?, paid_at = NOW() WHERE id = ? AND status = 'pending'");
        $upd->execute([$txId, $payment['id']]);

        if ($upd->rowCount() === 0) {
            // Another process already marked it paid
            $payment['status'] = 'paid';
            return ['success' => true, 'already_paid' => true, 'payment' => $payment];
        }

        $payment['status'] = 'paid';
        $payment['paystack_transaction_id'] = $txId;

        activatePurchasedFeature($payment);
        return ['success' => true, 'payment' => $payment];
    }

    // Map Paystack terminal statuses
    $terminalMap = ['abandoned' => 'abandoned', 'failed' => 'failed'];
    if (isset($terminalMap[$tx['status']])) {
        $pdo->prepare("UPDATE platform_payments SET status = ? WHERE id = ?")
            ->execute([$terminalMap[$tx['status']], $payment['id']]);
    }

    return ['success' => false, 'error' => 'Payment was ' . $tx['status'] . '.'];
}

// ── Feature activation ────────────────────────────────────────────────────────

function activatePurchasedFeature(array $payment): void {
    global $pdo;

    switch ($payment['payment_type']) {

        case 'featured_job':
            $pkg = $pdo->prepare("SELECT duration_days FROM featured_job_packages WHERE id = ?");
            $pkg->execute([$payment['package_id']]);
            $pkg = $pkg->fetch();
            $days = $pkg ? (int)$pkg['duration_days'] : 30;
            $pdo->prepare("UPDATE service_requests
                SET featured = 1, featured_start_date = CURDATE(), featured_end_date = DATE_ADD(CURDATE(), INTERVAL ? DAY)
                WHERE id = ?")
                ->execute([$days, $payment['reference_id']]);
            notify_user($payment['user_id'], '⭐ Job featured!', "Your job has been featured for {$days} days and will appear at the top of listings.", 'success');
            break;

        case 'featured_worker':
            $pkg = $pdo->prepare("SELECT duration_days FROM worker_promotion_packages WHERE id = ?");
            $pkg->execute([$payment['package_id']]);
            $pkg = $pkg->fetch();
            $days = $pkg ? (int)$pkg['duration_days'] : 30;
            $pdo->prepare("UPDATE worker_profiles
                SET is_featured = 1, featured_start_date = CURDATE(), featured_end_date = DATE_ADD(CURDATE(), INTERVAL ? DAY)
                WHERE id = ?")
                ->execute([$days, $payment['reference_id']]);
            notify_user($payment['user_id'], '⭐ Profile featured!', "Your profile is now featured for {$days} days and appears at the top of search results.", 'success');
            break;

        case 'verification':
            // Payment unlocks the admin review process — NOT automatic approval
            $pdo->prepare("UPDATE worker_profiles SET verification_status = 'pending', verification_rejection_reason = NULL WHERE id = ?")
                ->execute([$payment['reference_id']]);
            notify_user($payment['user_id'], '✓ Verification payment confirmed', 'Your payment was received. An admin will review your documents and approve your ✓erified badge.', 'info');
            notify_admins_and_managers(
                'Verification payment confirmed — review required',
                'Worker ID ' . $payment['reference_id'] . ' (' . $payment['user_id'] . ') paid for verification. Review documents in Monetization → Verification.',
                'info'
            );
            break;

        case 'job_post':
            $pkg = $pdo->prepare("SELECT post_count FROM job_posting_packages WHERE id = ?");
            $pkg->execute([$payment['package_id']]);
            $pkg = $pkg->fetch();
            $postCount = $pkg ? (int)$pkg['post_count'] : 1;
            if ($postCount > 1) {
                $pdo->prepare('INSERT INTO job_post_credits (user_id, payment_id, posts_total, posts_remaining, created_at) VALUES (?,?,?,?,NOW())')
                    ->execute([$payment['user_id'], $payment['id'], $postCount, $postCount - 1]);
            }
            $pdo->prepare("UPDATE service_requests SET posting_fee_status = 'paid' WHERE id = ?")
                ->execute([$payment['reference_id']]);
            notify_user($payment['user_id'], 'Job posting fee confirmed', 'Your posting fee was confirmed. Your job is now pending admin approval.', 'success');
            break;

        case 'worker_service':
            $pkg = $pdo->prepare("SELECT duration_days FROM worker_service_packages WHERE id = ?");
            $pkg->execute([$payment['package_id']]);
            $pkg = $pkg->fetch();
            $days = $pkg ? (int)$pkg['duration_days'] : 30;
            $pdo->prepare("UPDATE worker_profiles
                SET service_fee_status = 'paid', service_fee_expiry = DATE_ADD(CURDATE(), INTERVAL ? DAY), service_renewal_notice_sent = 0
                WHERE id = ?")
                ->execute([$days, $payment['reference_id']]);
            notify_user($payment['user_id'], 'Service listing active', "Your service listing is now active for {$days} days. You'll appear in Find Workers.", 'success');
            break;

        case 'escrow_payment':
            $jobId = (int)$payment['reference_id'];

            // Mark escrow as held
            $pdo->prepare("UPDATE escrow_payments
                SET status = 'held', platform_payment_id = ?, paystack_reference = ?, paid_at = NOW()
                WHERE job_id = ? AND status = 'awaiting_payment'")
                ->execute([$payment['id'], $payment['paystack_reference'], $jobId]);

            // Move job to pending for admin approval, mark payment as escrowed
            $pdo->prepare("UPDATE service_requests
                SET status = 'pending', payment_status = 'escrowed', updated_at = NOW()
                WHERE id = ? AND status = 'pending_payment'")
                ->execute([$jobId]);

            $jobRow = $pdo->prepare("SELECT title FROM service_requests WHERE id = ?");
            $jobRow->execute([$jobId]);
            $jobRow = $jobRow->fetch();
            $jobTitle = $jobRow ? $jobRow['title'] : "Job #{$jobId}";

            notify_user($payment['user_id'], '💳 Escrow payment received',
                "Your escrow payment for \"{$jobTitle}\" has been received and is now held securely. The job is pending admin review — you'll be notified once it goes live.",
                'success');
            notify_admins_and_managers(
                'New escrow job awaiting approval',
                "Job #{$jobId} \"{$jobTitle}\" was posted with escrow payment (GH₵ " . number_format($payment['amount'], 2) . ") held. Review and approve it in the admin panel.",
                'info'
            );
            break;
    }

    log_audit_action(
        0,
        'paystack_feature_activated',
        "Feature '{$payment['payment_type']}' activated for user {$payment['user_id']} via Paystack ref {$payment['paystack_reference']} (payment ID {$payment['id']})"
    );
}

// ── Webhook handler ───────────────────────────────────────────────────────────
// Call from paystack_webhook.php; exits with HTTP response.

function handleWebhook(): void {
    $keys    = paystack_keys();
    $payload = file_get_contents('php://input');
    $sig     = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '';

    if (!$keys['secret'] || !$payload) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing payload']);
        exit;
    }

    $expected = hash_hmac('sha512', $payload, $keys['secret']);
    if (!hash_equals($expected, $sig)) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid signature']);
        exit;
    }

    $event = json_decode($payload, true);
    if (!$event || !isset($event['event'])) {
        http_response_code(400);
        exit;
    }

    if ($event['event'] === 'charge.success') {
        $ref = $event['data']['reference'] ?? '';
        if ($ref) {
            verifyPayment($ref); // idempotent — handles duplicate webhooks
        }
    }

    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'ok']);
    exit;
}
