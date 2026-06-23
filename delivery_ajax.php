<?php
/**
 * Delivery Services — POST/AJAX action handler.
 * Actions:
 *   apply_delivery        — rider applies for a request
 *   withdraw_application  — rider withdraws their application
 *   select_rider          — customer selects a rider from applications
 *   approve_request       — admin approves a pending_approval request
 *   reject_request        — admin rejects a pending_approval request
 *   update_status         — assigned agent advances delivery status
 *   cancel_delivery       — customer cancels
 *   rate_delivery         — customer or agent submits rating
 *   toggle_availability   — agent changes availability status
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/delivery_functions.php';

require_login();
csrf_check();

$user   = current_user();
$action = $_POST['action'] ?? '';

function delivery_error(string $msg, string $back = 'delivery.php'): never {
    flash($msg, 'error');
    header('Location: ' . $back);
    exit;
}

// ── apply_delivery ──────────────────────────────────────────────────────────
if ($action === 'apply_delivery') {
    $deliveryId = (int)($_POST['delivery_id'] ?? 0);
    $offeredFee = $_POST['offered_fee'] !== '' ? (float)$_POST['offered_fee'] : null;
    $offerNote  = trim($_POST['offer_note'] ?? '');

    $agentProfile = get_delivery_agent_for_user((int)$user['id']);
    if (!$agentProfile || $agentProfile['verification_status'] !== 'approved') {
        delivery_error('You must be an approved delivery agent to apply.', 'delivery_agent_jobs.php');
    }

    $delivery = get_delivery_request($deliveryId);
    if (!$delivery || $delivery['status'] !== 'approved') {
        delivery_error('This request is no longer open for applications.', 'delivery_agent_jobs.php');
    }

    $existing = get_delivery_application($deliveryId, (int)$agentProfile['id']);
    if ($existing) delivery_error('You have already applied to this delivery.', 'delivery_agent_jobs.php');

    $pdo->prepare(
        'INSERT INTO delivery_applications (delivery_request_id, agent_id, offer_note, offered_fee) VALUES (?,?,?,?)'
    )->execute([$deliveryId, $agentProfile['id'], $offerNote ?: null, $offeredFee]);

    // Notify customer
    notify_user(
        (int)$delivery['customer_id'],
        'New Application for Your Delivery',
        display_name($user) . ' has applied to handle your delivery request #' . $deliveryId . '. View applications →',
        'info'
    );

    flash('Application submitted! The customer will review and select a rider.', 'success');
    header('Location: delivery_agent_jobs.php?tab=applications');
    exit;
}

// ── withdraw_application ────────────────────────────────────────────────────
if ($action === 'withdraw_application') {
    $deliveryId   = (int)($_POST['delivery_id'] ?? 0);
    $agentProfile = get_delivery_agent_for_user((int)$user['id']);
    if (!$agentProfile) delivery_error('Agent profile not found.', 'delivery_agent_jobs.php');

    $app = get_delivery_application($deliveryId, (int)$agentProfile['id']);
    if (!$app || !in_array($app['status'], ['applied','shortlisted'], true)) {
        delivery_error('Application cannot be withdrawn at this stage.', 'delivery_agent_jobs.php');
    }

    $pdo->prepare("UPDATE delivery_applications SET status='withdrawn', updated_at=NOW() WHERE id=?")
        ->execute([$app['id']]);

    flash('Application withdrawn.', 'info');
    header('Location: delivery_agent_jobs.php?tab=applications');
    exit;
}

// ── select_rider ─────────────────────────────────────────────────────────────
if ($action === 'select_rider') {
    $deliveryId = (int)($_POST['delivery_id'] ?? 0);
    $appId      = (int)($_POST['app_id'] ?? 0);

    $delivery = get_delivery_request($deliveryId);
    if (!$delivery || (int)$delivery['customer_id'] !== (int)$user['id']) {
        delivery_error('Delivery not found or not yours.', 'delivery.php');
    }
    if ($delivery['status'] !== 'approved') {
        delivery_error('Riders can only be selected on approved requests.', 'delivery_detail.php?id=' . $deliveryId);
    }

    $appStmt = $pdo->prepare('SELECT da.*, da.id AS app_id, da.agent_id FROM delivery_applications da WHERE da.id = ? AND da.delivery_request_id = ?');
    $appStmt->execute([$appId, $deliveryId]);
    $app = $appStmt->fetch();
    if (!$app || $app['status'] === 'withdrawn') {
        delivery_error('Application not found.', 'delivery_detail.php?id=' . $deliveryId);
    }

    $pdo->beginTransaction();
    try {
        // Assign agent to request
        $agentRow = $pdo->prepare('SELECT user_id FROM delivery_agents WHERE id = ?');
        $agentRow->execute([$app['agent_id']]);
        $agentUserId = $agentRow->fetchColumn();

        $usedFee = $app['offered_fee'] ?? $delivery['delivery_fee'];
        $pdo->prepare("UPDATE delivery_requests SET agent_id=?, status='assigned', delivery_fee=?, updated_at=NOW() WHERE id=?")
            ->execute([$app['agent_id'], $usedFee, $deliveryId]);

        // Mark selected application
        $pdo->prepare("UPDATE delivery_applications SET status='assigned', updated_at=NOW() WHERE id=?")
            ->execute([$appId]);

        // Reject all other pending/shortlisted applications
        $pdo->prepare("UPDATE delivery_applications SET status='rejected', updated_at=NOW()
                        WHERE delivery_request_id=? AND id!=? AND status IN('applied','shortlisted')")
            ->execute([$deliveryId, $appId]);

        // Notify selected agent
        if ($agentUserId) {
            notify_user((int)$agentUserId, 'You Got the Job! 🎉',
                "You have been selected for delivery request #$deliveryId. Check your active deliveries.",
                'success');
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        delivery_error('Could not assign rider. Please try again.', 'delivery_detail.php?id=' . $deliveryId);
    }

    flash('Rider assigned! They have been notified.', 'success');
    header('Location: delivery_detail.php?id=' . $deliveryId);
    exit;
}

// ── approve_request (admin) ─────────────────────────────────────────────────
if ($action === 'approve_request') {
    if (!is_admin_or_manager()) delivery_error('Not authorised.', 'admin/delivery.php');
    $deliveryId = (int)($_POST['delivery_id'] ?? 0);

    $delivery = get_delivery_request($deliveryId);
    if (!$delivery || $delivery['status'] !== 'pending_approval') {
        delivery_error('Request is not awaiting approval.', 'admin/delivery.php?tab=pending');
    }

    $pdo->prepare("UPDATE delivery_requests SET status='approved', updated_at=NOW() WHERE id=?")
        ->execute([$deliveryId]);

    notify_user((int)$delivery['customer_id'], 'Delivery Request Approved ✅',
        "Your delivery request #$deliveryId has been approved and is now visible to riders.",
        'success');

    // Notify all active approved agents
    $agents = $pdo->query("SELECT da.user_id FROM delivery_agents da WHERE da.verification_status='approved' AND da.availability_status IN('available','busy')")->fetchAll();
    foreach ($agents as $ag) {
        notify_user((int)$ag['user_id'], 'New Delivery Job Available',
            "A new approved delivery request (#$deliveryId) is open. Open your agent dashboard to apply.", 'info');
    }

    log_audit_action($user['id'], 'delivery_request_approve', "Approved delivery request #$deliveryId");
    flash("Request #$deliveryId approved and posted.", 'success');
    header('Location: admin/delivery.php?tab=pending');
    exit;
}

// ── reject_request (admin) ──────────────────────────────────────────────────
if ($action === 'reject_request') {
    if (!is_admin_or_manager()) delivery_error('Not authorised.', 'admin/delivery.php');
    $deliveryId = (int)($_POST['delivery_id'] ?? 0);
    $reason     = trim($_POST['rejection_reason'] ?? '');

    $delivery = get_delivery_request($deliveryId);
    if (!$delivery || $delivery['status'] !== 'pending_approval') {
        delivery_error('Request is not awaiting approval.', 'admin/delivery.php?tab=pending');
    }

    $pdo->prepare("UPDATE delivery_requests SET status='rejected', rejection_reason=?, updated_at=NOW() WHERE id=?")
        ->execute([$reason ?: null, $deliveryId]);

    notify_user((int)$delivery['customer_id'], 'Delivery Request Rejected',
        'Your delivery request #' . $deliveryId . ' could not be approved.' .
        ($reason ? " Reason: $reason" : '') .
        ' Please review and resubmit.',
        'error');

    log_audit_action($user['id'], 'delivery_request_reject', "Rejected delivery request #$deliveryId. Reason: " . ($reason ?: 'none'));
    flash("Request #$deliveryId rejected.", 'info');
    header('Location: admin/delivery.php?tab=pending');
    exit;
}

// ── update_status ───────────────────────────────────────────────────────────
if ($action === 'update_status') {
    $deliveryId   = (int)($_POST['delivery_id'] ?? 0);
    $newStatus    = $_POST['new_status'] ?? '';
    $agentProfile = get_delivery_agent_for_user((int)$user['id']);

    if (!$agentProfile) delivery_error('Agent profile not found.', 'delivery_agent_jobs.php');

    $delivery = get_delivery_request($deliveryId);
    if (!$delivery || (int)$delivery['agent_id'] !== (int)$agentProfile['id']) {
        delivery_error('You are not the assigned agent for this delivery.', 'delivery_agent_jobs.php');
    }

    $allowed = delivery_agent_next_statuses($delivery['status']);
    if (!in_array($newStatus, $allowed, true)) {
        delivery_error('Invalid status transition.', 'delivery_detail.php?id=' . $deliveryId);
    }

    $pdo->prepare("UPDATE delivery_requests SET status=?, updated_at=NOW() WHERE id=?")->execute([$newStatus, $deliveryId]);

    $notifMap = [
        'picked_up'   => ['Parcel Picked Up 📦',      'Your delivery agent has picked up the item.',                          'info'],
        'in_progress' => ['Delivery In Progress 🚚',   'Your item is on the way.',                                            'info'],
        'in_transit'  => ['Delivery In Transit 🚚',    'Your item is on the way to the drop-off point.',                      'info'],
        'delivered'   => ['Delivered! ✅',              'Your item has been delivered. Please rate your agent.',               'success'],
        'failed'      => ['Delivery Failed ❌',          "Delivery #$deliveryId failed. Please contact support if needed.",    'error'],
    ];

    if (isset($notifMap[$newStatus])) {
        [$title, $body, $type] = $notifMap[$newStatus];
        notify_user((int)$delivery['customer_id'], $title, $body, $type);
        $custEmail = $pdo->prepare('SELECT email FROM users WHERE id=?');
        $custEmail->execute([$delivery['customer_id']]);
        $emailAddr = $custEmail->fetchColumn();
        if ($emailAddr) send_email_notification($emailAddr, "$title — Delivery #$deliveryId", "$body\n\nView: " . BASE_URL . "delivery_detail.php?id=$deliveryId", (int)$delivery['customer_id']);
    }

    if ($newStatus === 'delivered') {
        $pdo->prepare("UPDATE delivery_requests SET payment_status='paid', updated_at=NOW() WHERE id=?")->execute([$deliveryId]);
        refresh_agent_stats((int)$agentProfile['id']);
        $pdo->prepare("UPDATE delivery_agents SET availability_status='available', updated_at=NOW() WHERE id=? AND availability_status='busy'")->execute([$agentProfile['id']]);

        // Mark application as completed
        $pdo->prepare("UPDATE delivery_applications SET status='completed', updated_at=NOW() WHERE delivery_request_id=? AND status='assigned'")->execute([$deliveryId]);

        notify_user((int)$user['id'], 'Delivery Complete — Rate Your Customer',
            "Delivery #$deliveryId marked as delivered. You can now rate the customer.", 'info');
    }

    if ($newStatus === 'failed') {
        $pdo->prepare("UPDATE delivery_agents SET availability_status='available', updated_at=NOW() WHERE id=?")->execute([$agentProfile['id']]);
    }

    flash('Status updated to: ' . delivery_status_label($newStatus), 'success');
    header('Location: delivery_detail.php?id=' . $deliveryId);
    exit;
}

// ── cancel_delivery ─────────────────────────────────────────────────────────
if ($action === 'cancel_delivery') {
    $deliveryId      = (int)($_POST['delivery_id'] ?? 0);
    $cancelledReason = trim($_POST['cancelled_reason'] ?? '');

    $delivery = get_delivery_request($deliveryId);
    if (!$delivery || (int)$delivery['customer_id'] !== (int)$user['id']) {
        delivery_error('Delivery not found or not yours.', 'delivery.php');
    }
    if (!in_array($delivery['status'], ['pending_approval','approved','assigned','accepted'], true)) {
        delivery_error('This delivery cannot be cancelled at this stage.', 'delivery_detail.php?id=' . $deliveryId);
    }

    $pdo->prepare("UPDATE delivery_requests SET status='cancelled', cancelled_reason=?, updated_at=NOW() WHERE id=?")
        ->execute([$cancelledReason ?: null, $deliveryId]);

    // Notify assigned agent
    if ($delivery['agent_id']) {
        $agUid = $pdo->prepare('SELECT user_id FROM delivery_agents WHERE id=?');
        $agUid->execute([$delivery['agent_id']]);
        if ($uid = $agUid->fetchColumn()) {
            notify_user((int)$uid, 'Delivery Cancelled', "Customer cancelled delivery #$deliveryId." . ($cancelledReason ? " Reason: $cancelledReason" : ''), 'warning');
        }
        $pdo->prepare("UPDATE delivery_agents SET availability_status='available', updated_at=NOW() WHERE id=? AND availability_status='busy'")->execute([$delivery['agent_id']]);
    }

    // Withdraw all pending applications
    $pdo->prepare("UPDATE delivery_applications SET status='withdrawn', updated_at=NOW() WHERE delivery_request_id=? AND status IN('applied','shortlisted')")->execute([$deliveryId]);

    flash('Delivery request cancelled.', 'info');
    header('Location: delivery.php');
    exit;
}

// ── rate_delivery ────────────────────────────────────────────────────────────
if ($action === 'rate_delivery') {
    $deliveryId = (int)($_POST['delivery_id'] ?? 0);
    $rater      = $_POST['rater'] ?? '';
    $ratingVal  = (int)($_POST['rating'] ?? 0);
    $comment    = trim($_POST['comment'] ?? '');

    if ($ratingVal < 1 || $ratingVal > 5) delivery_error('Please select a star rating (1–5).', 'delivery_detail.php?id=' . $deliveryId);

    $delivery = get_delivery_request($deliveryId);
    if (!$delivery || $delivery['status'] !== 'delivered') delivery_error('Ratings are only for completed deliveries.', 'delivery_detail.php?id=' . $deliveryId);

    $isCustomer   = (int)$delivery['customer_id'] === (int)$user['id'];
    $agentProfile = get_delivery_agent_for_user((int)$user['id']);
    $isAgent      = $agentProfile && (int)$delivery['agent_id'] === (int)$agentProfile['id'];

    if ($rater === 'customer' && !$isCustomer) delivery_error('Not authorised.', 'delivery.php');
    if ($rater === 'agent'    && !$isAgent)    delivery_error('Not authorised.', 'delivery_agent_jobs.php');

    $exists = $pdo->prepare('SELECT id FROM delivery_ratings WHERE delivery_request_id=?');
    $exists->execute([$deliveryId]);
    $row = $exists->fetch();

    if ($row) {
        $col = $rater === 'customer' ? 'customer_rating' : 'agent_rating';
        $cmt = $rater === 'customer' ? 'customer_comment' : 'agent_comment';
        $pdo->prepare("UPDATE delivery_ratings SET $col=?, $cmt=? WHERE delivery_request_id=?")
            ->execute([$ratingVal, $comment ?: null, $deliveryId]);
    } else {
        if ($rater === 'customer') {
            $pdo->prepare('INSERT INTO delivery_ratings (delivery_request_id, customer_rating, customer_comment) VALUES (?,?,?)')->execute([$deliveryId, $ratingVal, $comment ?: null]);
        } else {
            $pdo->prepare('INSERT INTO delivery_ratings (delivery_request_id, agent_rating, agent_comment) VALUES (?,?,?)')->execute([$deliveryId, $ratingVal, $comment ?: null]);
        }
    }

    if ($delivery['agent_id']) refresh_agent_stats((int)$delivery['agent_id']);

    flash('Thank you for your rating!', 'success');
    header('Location: ' . ($rater === 'agent' ? 'delivery_agent_jobs.php?tab=history' : 'delivery_detail.php?id=' . $deliveryId));
    exit;
}

// ── toggle_availability ──────────────────────────────────────────────────────
if ($action === 'toggle_availability') {
    $av = $_POST['availability'] ?? '';
    if (!in_array($av, ['available','busy','offline'], true)) delivery_error('Invalid status.', 'delivery_agent_jobs.php');

    $agentProfile = get_delivery_agent_for_user((int)$user['id']);
    if (!$agentProfile) delivery_error('Agent profile not found.', 'delivery_agent_jobs.php');

    $pdo->prepare("UPDATE delivery_agents SET availability_status=?, updated_at=NOW() WHERE id=?")->execute([$av, $agentProfile['id']]);
    flash('Availability set to ' . ucfirst($av) . '.', 'success');
    header('Location: delivery_agent_jobs.php');
    exit;
}

delivery_error('Unknown action.', 'delivery.php');
