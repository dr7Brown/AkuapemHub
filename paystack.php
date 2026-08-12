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

    // Persist the authorization_url so resume_payment.php can redirect without a re-init
    $pdo->prepare('UPDATE platform_payments SET authorization_url=? WHERE id=?')
        ->execute([$data['data']['authorization_url'], $paymentId]);

    return [
        'payment_id'   => $paymentId,
        'checkout_url' => $data['data']['authorization_url'],
        'access_code'  => $data['data']['access_code'],
        'reference'    => $ref,
    ];
}

// ── Refund ───────────────────────────────────────────────────────────────────
// Calls Paystack refund API. Pass null $amount for full refund.
// Returns: ['success'=>true] | ['success'=>false, 'error'=>'...']

function paystack_refund(string $paystackTransactionId, ?float $amount = null): array {
    $body = ['transaction' => $paystackTransactionId];
    if ($amount !== null) {
        $body['amount'] = (int)round($amount * 100);
    }
    $data = paystack_request('POST', '/refund', $body);
    if (!$data || !($data['status'] ?? false)) {
        return ['success' => false, 'error' => $data['message'] ?? 'Refund request failed.'];
    }
    return ['success' => true, 'data' => $data['data'] ?? []];
}

// ── Transfers (seller payouts) ─────────────────────────────────────────────
// Automated seller withdrawals. Ghana mobile money recipients use Paystack's
// 'mobile_money' recipient type; bank accounts use 'ghipss'.

function paystack_sync_banks(): array {
    global $pdo;
    $types  = ['ghipss' => 'bank', 'mobile_money' => 'mobile_money'];
    $synced = 0;
    foreach ($types as $paystackType => $localType) {
        $data = paystack_request('GET', '/bank?currency=GHS&type=' . $paystackType);
        if (!$data || !($data['status'] ?? false)) continue;
        foreach ($data['data'] ?? [] as $bank) {
            if (empty($bank['code']) || empty($bank['name'])) continue;
            $pdo->prepare('INSERT INTO mp_banks (code, name, type, currency) VALUES (?,?,?,\'GHS\')
                ON DUPLICATE KEY UPDATE name = VALUES(name)')
                ->execute([$bank['code'], $bank['name'], $localType]);
            $synced++;
        }
    }
    return $synced > 0
        ? ['success' => true, 'count' => $synced]
        : ['success' => false, 'error' => 'Could not reach Paystack, or no banks were returned.'];
}

// Confirms the account name on a MoMo/bank account before saving it, so a
// seller mistyping their own account number is caught before payouts start.
function paystack_resolve_account(string $accountNumber, string $bankCode): array {
    $data = paystack_request('GET', '/bank/resolve?account_number=' . urlencode($accountNumber) . '&bank_code=' . urlencode($bankCode));
    if (!$data || !($data['status'] ?? false)) {
        return ['success' => false, 'error' => $data['message'] ?? 'Could not verify this account.'];
    }
    return ['success' => true, 'account_name' => $data['data']['account_name'] ?? null];
}

// Creates (and caches) a Paystack transfer recipient for a saved payout account.
function paystack_get_or_create_recipient(array $account): array {
    global $pdo;
    if (!empty($account['paystack_recipient_code'])) {
        return ['success' => true, 'recipient_code' => $account['paystack_recipient_code']];
    }
    $data = paystack_request('POST', '/transferrecipient', [
        'type'           => $account['method'] === 'bank' ? 'ghipss' : 'mobile_money',
        'name'           => $account['account_name'],
        'account_number' => $account['account_number'],
        'bank_code'      => $account['bank_code'],
        'currency'       => 'GHS',
    ]);
    if (!$data || !($data['status'] ?? false)) {
        return ['success' => false, 'error' => $data['message'] ?? 'Could not create transfer recipient.'];
    }
    $recipientCode = $data['data']['recipient_code'] ?? null;
    if ($recipientCode) {
        $pdo->prepare('UPDATE mp_payout_accounts SET paystack_recipient_code=? WHERE id=?')
            ->execute([$recipientCode, $account['id']]);
    }
    return ['success' => true, 'recipient_code' => $recipientCode];
}

// Fires the actual transfer. The platform absorbs Paystack's transfer fee —
// it's deducted from the platform's own Paystack balance, not this $amount.
function paystack_initiate_transfer(int $payoutRequestId, string $recipientCode, float $amount, string $reason): array {
    $ref  = 'AH-PAYOUT-' . $payoutRequestId . '-' . time() . '-' . strtoupper(bin2hex(random_bytes(3)));
    $data = paystack_request('POST', '/transfer', [
        'source'    => 'balance',
        'amount'    => (int)round($amount * 100),
        'recipient' => $recipientCode,
        'reason'    => $reason,
        'reference' => $ref,
        'currency'  => 'GHS',
    ]);
    if (!$data || !($data['status'] ?? false)) {
        return ['success' => false, 'error' => $data['message'] ?? 'Transfer request failed.', 'reference' => $ref];
    }
    $status = $data['data']['status'] ?? 'pending';
    if ($status === 'otp') {
        // Paystack account has OTP-finalization enabled for transfers — this
        // can't be automated from here, it's a Paystack dashboard/API step
        // requiring a human to enter the OTP sent to the business owner.
        return ['success' => false, 'otp_required' => true,
            'error' => 'Paystack requires OTP finalization for transfers on this account — disable "Finalize Transfers with OTP" in the Paystack dashboard for full automation, or finalize this transfer manually there.',
            'reference' => $ref, 'transfer_code' => $data['data']['transfer_code'] ?? null];
    }
    return ['success' => true, 'transfer_code' => $data['data']['transfer_code'] ?? null, 'reference' => $ref, 'status' => $status];
}

// Manual fallback for admins in case a transfer.success/failed webhook was missed.
function paystack_check_transfer_status(string $transferCode): array {
    $data = paystack_request('GET', '/transfer/' . urlencode($transferCode));
    if (!$data || !($data['status'] ?? false)) {
        return ['success' => false, 'error' => $data['message'] ?? 'Could not check transfer status.'];
    }
    return ['success' => true, 'status' => $data['data']['status'] ?? null];
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
        $channel = $tx['authorization']['channel'] ?? ($tx['channel'] ?? null);

        // Atomic UPDATE — only succeeds once; prevents double-activation on concurrent
        // webhook + callback calls for the same reference.
        $upd = $pdo->prepare("UPDATE platform_payments SET status = 'paid', paystack_transaction_id = ?, payment_method = COALESCE(?, payment_method), paid_at = NOW() WHERE id = ? AND status = 'pending'");
        $upd->execute([$txId, $channel, $payment['id']]);

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

        case 'worker_premium':
            $pkg = $pdo->prepare("SELECT duration_days FROM worker_premium_packages WHERE id = ?");
            $pkg->execute([$payment['package_id']]);
            $pkg = $pkg->fetch();
            $days = $pkg ? (int)$pkg['duration_days'] : 30;
            $pdo->prepare("UPDATE worker_profiles
                SET subscription_status = 'premium', premium_expiry = DATE_ADD(CURDATE(), INTERVAL ? DAY), premium_renewal_notice_sent = 0
                WHERE id = ?")
                ->execute([$days, $payment['reference_id']]);
            notify_user($payment['user_id'], 'Premium activated', "Your worker subscription is now Premium for {$days} days — you'll rank higher in search results.", 'success');
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

        case 'news_post':
            $newsRow = $pdo->prepare("SELECT title FROM news WHERE id = ?");
            $newsRow->execute([$payment['reference_id']]);
            $newsRow = $newsRow->fetch();
            $newsTitle = $newsRow ? $newsRow['title'] : "Article #{$payment['reference_id']}";
            $pdo->prepare("UPDATE news SET status = 'draft', updated_at = NOW() WHERE id = ? AND status = 'pending_payment'")
                ->execute([$payment['reference_id']]);
            notify_user($payment['user_id'], '✅ Publishing fee confirmed',
                "Payment for \"{$newsTitle}\" was received. Your article is now queued for admin review.", 'success');
            notify_admins_and_managers(
                'News article awaiting review (fee paid)',
                "\"{$newsTitle}\" — publishing fee paid by user {$payment['user_id']}. Review in Admin → News.",
                'info'
            );
            break;

        case 'event_post':
            $evRow = $pdo->prepare("SELECT title FROM events WHERE id = ?");
            $evRow->execute([$payment['reference_id']]);
            $evRow = $evRow->fetch();
            $evTitle = $evRow ? $evRow['title'] : "Event #{$payment['reference_id']}";
            $pdo->prepare("UPDATE events SET status = 'draft', updated_at = NOW() WHERE id = ? AND status = 'pending_payment'")
                ->execute([$payment['reference_id']]);
            notify_user($payment['user_id'], '✅ Publishing fee confirmed',
                "Payment for \"{$evTitle}\" was received. Your event is now queued for admin review.", 'success');
            notify_admins_and_managers(
                'Event awaiting review (fee paid)',
                "\"{$evTitle}\" — publishing fee paid by user {$payment['user_id']}. Review in Admin → Events.",
                'info'
            );
            break;

        case 'funeral_post':
            $faRow = $pdo->prepare("SELECT deceased_name FROM funeral_announcements WHERE id = ?");
            $faRow->execute([$payment['reference_id']]);
            $faRow = $faRow->fetch();
            $faName = $faRow ? $faRow['deceased_name'] : "Announcement #{$payment['reference_id']}";
            $pdo->prepare("UPDATE funeral_announcements SET status = 'pending', updated_at = NOW() WHERE id = ? AND status = 'pending_payment'")
                ->execute([$payment['reference_id']]);
            notify_user($payment['user_id'], '✅ Publishing fee confirmed',
                "Payment for the announcement of \"{$faName}\" was received. It is now under admin review.", 'success');
            notify_admins_and_managers(
                'Funeral announcement awaiting review (fee paid)',
                "\"{$faName}\" — publishing fee paid by user {$payment['user_id']}. Review in Admin → Funerals.",
                'info'
            );
            break;

        case 'mp_order':
            // reference_id is just the first order created at checkout; the full
            // set (usually one per shop in the cart) is looked up by payment id.
            $mpOrders = $pdo->prepare("SELECT * FROM mp_orders WHERE platform_payment_id=? AND payment_status='unpaid'");
            $mpOrders->execute([$payment['id']]);
            $mpOrders = $mpOrders->fetchAll();

            $commissionPct = (float)get_platform_setting('mp_commission_percent', '10');
            $custEmailSent = false;

            foreach ($mpOrders as $mo) {
                $commissionAmt = round((float)$mo['total_amount'] * $commissionPct / 100, 2);
                $netAmt = round((float)$mo['total_amount'] - $commissionAmt, 2);

                $pdo->prepare("UPDATE mp_orders SET payment_status='paid', commission_percent=?, commission_amount=?, net_amount=?, updated_at=NOW() WHERE id=?")
                    ->execute([$commissionPct, $commissionAmt, $netAmt, $mo['id']]);

                $shopRow = $pdo->prepare('SELECT id, user_id, shop_name, phone, region FROM mp_shops WHERE id=?');
                $shopRow->execute([$mo['shop_id']]);
                $shop = $shopRow->fetch();
                if (!$shop) continue;

                $pdo->prepare('UPDATE mp_shops SET pending_balance = pending_balance + ? WHERE id=?')
                    ->execute([$netAmt, $shop['id']]);
                $pdo->prepare('INSERT INTO mp_wallet_transactions (shop_id, order_id, type, amount) VALUES (?,?,\'sale_pending\',?)')
                    ->execute([$shop['id'], $mo['id'], $netAmt]);

                notify_user((int)$shop['user_id'], 'Payment Received 💳',
                    "Order #{$mo['id']} was paid (GH₵ " . number_format((float)$mo['total_amount'], 2) . "). GH₵ " . number_format($netAmt, 2) . " has been added to your pending balance — it becomes withdrawable after the order is delivered and the confirmation period passes.",
                    'success');

                if (!$custEmailSent) {
                    notify_user($payment['user_id'], 'Order Payment Confirmed ✅',
                        'Your payment for order #' . $mo['id'] . (count($mpOrders) > 1 ? ' (and related orders)' : '') . ' was successful. Sellers have been notified and will confirm shortly.',
                        'success');
                    $custEmailSent = true;
                }

                if (class_exists('EmailService') || file_exists(__DIR__ . '/services/EmailService.php')) {
                    if (!class_exists('EmailService', false)) require_once __DIR__ . '/services/EmailService.php';
                    $custRow = $pdo->prepare('SELECT name, email FROM users WHERE id=?');
                    $custRow->execute([$payment['user_id']]);
                    $cust = $custRow->fetch();
                    if ($cust) {
                        $orderItemsStmt = $pdo->prepare('SELECT product_name, quantity, price, subtotal FROM mp_order_items WHERE order_id=?');
                        $orderItemsStmt->execute([$mo['id']]);
                        $receiptItems = array_map(fn($it) => [
                            'name'       => $it['product_name'],
                            'qty'        => (int)$it['quantity'],
                            'unit_price' => (float)$it['price'],
                            'amount'     => (float)$it['subtotal'],
                        ], $orderItemsStmt->fetchAll());

                        EmailService::sendReceipt(
                            $cust['email'], $cust['name'],
                            'MKT-' . str_pad($mo['id'], 6, '0', STR_PAD_LEFT),
                            'Marketplace Order — ' . $shop['shop_name'],
                            (float)$mo['total_amount'],
                            date('d M Y, g:i A'),
                            (int)$payment['user_id'],
                            $receiptItems,
                            ['name' => $shop['shop_name'], 'phone' => $shop['phone'], 'region' => $shop['region']]
                        );
                    }
                }

                $pdo->prepare("UPDATE mp_quote_requests SET status='paid', updated_at=NOW() WHERE order_id=? AND status='quoted'")
                    ->execute([$mo['id']]);
            }
            break;

        case 'mp_subscription':
            require_once __DIR__ . '/marketplace_functions.php';
            mp_activate_subscription((int)$payment['reference_id'], (int)$payment['id']);
            break;

        case 'featured_news':
            $pkgN = $pdo->prepare("SELECT duration_days FROM featured_news_packages WHERE id=?");
            $pkgN->execute([$payment['package_id']]);
            $pkgNRow = $pkgN->fetch();
            $days = (int)($pkgNRow['duration_days'] ?? 30);
            $artR = $pdo->prepare("SELECT title FROM news WHERE id=?");
            $artR->execute([$payment['reference_id']]);
            $artRow = $artR->fetch();
            $artTitle = $artRow ? $artRow['title'] : "Article #{$payment['reference_id']}";
            $pdo->prepare("UPDATE news SET featured=1, featured_end_date=DATE_ADD(CURDATE(),INTERVAL ? DAY) WHERE id=?")
                ->execute([$days, $payment['reference_id']]);
            notify_user($payment['user_id'], '⭐ Article is now featured!',
                "\"{$artTitle}\" is featured for {$days} days. It will appear at the top of the news feed.", 'success');
            break;

        case 'featured_event':
            $pkgE = $pdo->prepare("SELECT duration_days FROM featured_event_packages WHERE id=?");
            $pkgE->execute([$payment['package_id']]);
            $pkgERow = $pkgE->fetch();
            $days = (int)($pkgERow['duration_days'] ?? 30);
            $evR = $pdo->prepare("SELECT title FROM events WHERE id=?");
            $evR->execute([$payment['reference_id']]);
            $evRow = $evR->fetch();
            $evTitle = $evRow ? $evRow['title'] : "Event #{$payment['reference_id']}";
            $pdo->prepare("UPDATE events SET featured=1, featured_end_date=DATE_ADD(CURDATE(),INTERVAL ? DAY) WHERE id=?")
                ->execute([$days, $payment['reference_id']]);
            notify_user($payment['user_id'], '⭐ Event is now featured!',
                "\"{$evTitle}\" is featured for {$days} days. It will appear at the top of the events listing.", 'success');
            break;

        case 'featured_funeral':
            $pkgF = $pdo->prepare("SELECT duration_days FROM featured_funeral_packages WHERE id=?");
            $pkgF->execute([$payment['package_id']]);
            $pkgFRow = $pkgF->fetch();
            $days = (int)($pkgFRow['duration_days'] ?? 30);
            $faR = $pdo->prepare("SELECT deceased_name FROM funeral_announcements WHERE id=?");
            $faR->execute([$payment['reference_id']]);
            $faRow2 = $faR->fetch();
            $faName2 = $faRow2 ? $faRow2['deceased_name'] : "Announcement #{$payment['reference_id']}";
            $pdo->prepare("UPDATE funeral_announcements SET featured=1, featured_end_date=DATE_ADD(CURDATE(),INTERVAL ? DAY) WHERE id=?")
                ->execute([$days, $payment['reference_id']]);
            notify_user($payment['user_id'], '⭐ Announcement is now featured!',
                "The announcement for \"{$faName2}\" is featured for {$days} days.", 'success');
            break;

        case 'escrow_with_posting':
            $jobId = (int)$payment['reference_id'];

            // Mark escrow as held
            $pdo->prepare("UPDATE escrow_payments
                SET status = 'held', platform_payment_id = ?, paystack_reference = ?, paid_at = NOW()
                WHERE job_id = ? AND status = 'awaiting_payment'")
                ->execute([$payment['id'], $payment['paystack_reference'], $jobId]);

            // Move job to pending; mark both payment modes satisfied in one shot
            $pdo->prepare("UPDATE service_requests
                SET status = 'pending', payment_status = 'escrowed', posting_fee_status = 'paid', updated_at = NOW()
                WHERE id = ? AND status = 'pending_payment'")
                ->execute([$jobId]);

            // Credit multi-post packages (same logic as job_post)
            if (!empty($payment['package_id'])) {
                $pkg = $pdo->prepare("SELECT post_count FROM job_posting_packages WHERE id = ?");
                $pkg->execute([$payment['package_id']]);
                $pkg = $pkg->fetch();
                $postCount = $pkg ? (int)$pkg['post_count'] : 1;
                if ($postCount > 1) {
                    $pdo->prepare('INSERT INTO job_post_credits (user_id, payment_id, posts_total, posts_remaining, created_at) VALUES (?,?,?,?,NOW())')
                        ->execute([$payment['user_id'], $payment['id'], $postCount, $postCount - 1]);
                }
            }

            $jobRow = $pdo->prepare("SELECT title FROM service_requests WHERE id = ?");
            $jobRow->execute([$jobId]);
            $jobRow = $jobRow->fetch();
            $jobTitle = $jobRow ? $jobRow['title'] : "Job #{$jobId}";

            notify_user($payment['user_id'], '💳 Payment confirmed — job submitted',
                "Your escrow and posting fee payment for \"{$jobTitle}\" has been received. The job is pending admin review — you'll be notified once it goes live.",
                'success');
            notify_admins_and_managers(
                'New escrow job awaiting approval',
                "Job #{$jobId} \"{$jobTitle}\" was posted with escrow (GH₵ " . number_format($payment['amount'], 2) . ") held and posting fee paid. Review and approve it in the admin panel.",
                'info'
            );
            break;

        case 'sponsor':
            $spPkg = $pdo->prepare("SELECT duration_days FROM sponsor_packages WHERE id=?");
            $spPkg->execute([$payment['package_id']]);
            $spDays = (int)($spPkg->fetch()['duration_days'] ?? 30);
            $spRow = $pdo->prepare("SELECT name FROM sponsors WHERE id=?");
            $spRow->execute([$payment['reference_id']]);
            $spRow = $spRow->fetch();
            $spName = $spRow ? $spRow['name'] : "Sponsor #{$payment['reference_id']}";
            $pdo->prepare("UPDATE sponsors
                SET status='pending_approval', start_date=CURDATE(), end_date=DATE_ADD(CURDATE(), INTERVAL ? DAY), updated_at=NOW()
                WHERE id=? AND status='pending_payment'")
                ->execute([$spDays, $payment['reference_id']]);
            notify_user($payment['user_id'], '✅ Sponsorship payment confirmed',
                "Payment for \"{$spName}\" was received. Your sponsor listing is now queued for admin review.", 'success');
            notify_admins_and_managers(
                'Sponsor submission awaiting review (paid)',
                "\"{$spName}\" — sponsorship fee paid by user {$payment['user_id']}. Review in Admin → Sponsors.",
                'info'
            );
            break;
    }

    // Referral milestone: first payment made by a referred user
    require_once __DIR__ . '/modules/referrals/service.php';
    handle_referral_milestone((int)$payment['user_id'], 'first_payment');

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

    if (in_array($event['event'], ['transfer.success', 'transfer.failed', 'transfer.reversed'], true)) {
        $ref = $event['data']['reference'] ?? '';
        if ($ref) {
            require_once __DIR__ . '/marketplace_functions.php';
            finalize_marketplace_payout_transfer($ref, $event['event']); // idempotent — handles duplicate webhooks
        }
    }

    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'ok']);
    exit;
}
