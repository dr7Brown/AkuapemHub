<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/delivery_functions.php';

require_login();
$user = current_user();

// Admins/managers need to keep reviewing deliveries even while the module is
// switched off for everyone else.
if (!is_admin_or_manager()) {
    require_module_enabled('delivery', 'Delivery Services');
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: delivery.php'); exit; }

$delivery = get_delivery_request($id);
if (!$delivery) { header('Location: delivery.php'); exit; }

// Determine viewer role
$agentProfile    = get_delivery_agent_for_user((int)$user['id']);
$isCustomer      = (int)$delivery['customer_id'] === (int)$user['id'];
$isAssignedAgent = $agentProfile && (int)$delivery['agent_id'] === (int)$agentProfile['id'];
$isAdminOrMgr    = is_admin_or_manager();

// Also allow any agent to view approved requests (so they can decide to apply)
$isApprovedAgent = $agentProfile && $agentProfile['verification_status'] === 'approved';
$requestVisible  = in_array($delivery['status'], ['approved','pending_approval'], true);

if (!$isCustomer && !$isAssignedAgent && !$isAdminOrMgr && !($isApprovedAgent && $requestVisible)) {
    header('Location: delivery.php');
    exit;
}

$flash = get_flash();

// ── Existing rating ───────────────────────────────────────────────────────────
$ratingStmt = $pdo->prepare('SELECT * FROM delivery_ratings WHERE delivery_request_id = ?');
$ratingStmt->execute([$id]);
$rating = $ratingStmt->fetch() ?: null;

// ── Existing complaint ────────────────────────────────────────────────────────
$disputeStmt = $pdo->prepare('SELECT * FROM delivery_disputes WHERE delivery_request_id = ? ORDER BY created_at DESC LIMIT 1');
$disputeStmt->execute([$id]);
$dispute = $disputeStmt->fetch() ?: null;

// Complaint window matches the marketplace payout confirmation period
$complaintWindowDays = (int)get_platform_setting('mp_payout_confirmation_days', 3);
$complaintWindowOpen = $delivery['status'] === 'delivered'
    && (time() - strtotime($delivery['updated_at'])) <= $complaintWindowDays * 86400;

// ── Applications (for approved requests) ─────────────────────────────────────
$applications = [];
if ($isCustomer && $delivery['status'] === 'approved') {
    $appStmt = $pdo->prepare(
        'SELECT da.*, u.name AS agent_name, u.username AS agent_username,
                u.profile_photo AS agent_photo, u.phone AS agent_phone,
                dag.vehicle_type, dag.rating, dag.completed_deliveries,
                dag.is_verified, dag.is_premium, dag.premium_end,
                dag.is_sponsored, dag.sponsored_end
         FROM delivery_applications da
         JOIN delivery_agents dag ON da.agent_id = dag.id
         JOIN users u ON dag.user_id = u.id
         WHERE da.delivery_request_id = ? AND da.status NOT IN ("withdrawn","rejected")
         ORDER BY ' . agent_priority_sql('dag') . ' DESC, da.created_at ASC'
    );
    $appStmt->execute([$id]);
    $applications = $appStmt->fetchAll();
}

// ── This agent's application (for approved-agent viewer) ──────────────────────
$myApplication = ($agentProfile && $delivery['status'] === 'approved')
    ? get_delivery_application($id, (int)$agentProfile['id'])
    : null;

// Status timeline steps (new flow uses approved→assigned→in_progress→delivered)
$steps = ['pending_approval','approved','assigned','in_progress','delivered'];
$legacySteps = ['pending','accepted','picked_up','in_transit','delivered'];
$currentSteps = str_contains('pending_approval approved assigned in_progress', $delivery['status'])
    ? $steps : $legacySteps;
$statusIndex = array_search($delivery['status'], $currentSteps);
$stepLabels  = ['pending_approval'=>'Pending Review','approved'=>'Open','assigned'=>'Assigned',
                'in_progress'=>'In Progress','delivered'=>'Delivered',
                'pending'=>'Pending','accepted'=>'Accepted','picked_up'=>'Picked Up',
                'in_transit'=>'In Transit'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delivery #<?php echo $id; ?> — AkuapemConnect</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .dd-shell { max-width:680px; margin:0 auto; padding:16px 16px 80px; }
        .dd-card  { background:var(--surface); border:1px solid var(--border); border-radius:14px; padding:18px; margin-bottom:14px; }
        .dd-label { font-size:.72rem; font-weight:800; text-transform:uppercase; letter-spacing:.06em; color:var(--text-muted,#6b7280); margin-bottom:12px; }
        .dd-row   { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:10px; }
        @media(max-width:480px){ .dd-row { grid-template-columns:1fr; } }
        .dd-field label { font-size:.73rem; color:var(--text-muted,#6b7280); font-weight:600; display:block; margin-bottom:2px; }
        .dd-field p     { margin:0; font-size:.88rem; font-weight:600; }

        /* Timeline */
        .dl-timeline { display:flex; align-items:flex-start; gap:0; margin-bottom:6px; }
        .dl-tstep    { flex:1; display:flex; flex-direction:column; align-items:center; gap:4px; }
        .dl-tstep-dot{ width:20px; height:20px; border-radius:50%; border:2px solid var(--border); background:var(--surface); display:flex; align-items:center; justify-content:center; font-size:.6rem; z-index:1; }
        .dl-tstep-dot.done   { background:var(--primary,#0f766e); border-color:var(--primary,#0f766e); color:#fff; }
        .dl-tstep-dot.active { background:#fff; border-color:var(--primary,#0f766e); box-shadow:0 0 0 3px var(--primary-soft,#d1fae5); }
        .dl-tstep-line { height:2px; background:var(--border); flex:1; margin-top:9px; }
        .dl-tstep-line.done { background:var(--primary,#0f766e); }
        .dl-tstep-label { font-size:.64rem; color:var(--text-muted,#6b7280); text-align:center; line-height:1.3; }
        .dl-tstep-label.active { color:var(--primary,#0f766e); font-weight:700; }
        .dl-timeline-row { display:flex; align-items:center; }

        /* Stars input */
        .dl-stars-input { display:flex; flex-direction:row-reverse; justify-content:flex-end; gap:2px; }
        .dl-stars-input input { display:none; }
        .dl-stars-input label { font-size:1.6rem; cursor:pointer; color:#e5e7eb; transition:color .1s; }
        .dl-stars-input input:checked ~ label,
        .dl-stars-input label:hover,
        .dl-stars-input label:hover ~ label { color:#f59e0b; }

        .dl-stars .dl-star-full  { color:#f59e0b; }
        .dl-stars .dl-star-empty { color:#e5e7eb; }
        .dl-star-val { font-size:.8rem; color:var(--text-muted,#6b7280); }

        .dd-badge { display:inline-block; padding:4px 12px; border-radius:20px; font-size:.78rem; font-weight:800; }
        .dd-agent-row { display:flex; align-items:center; gap:12px; }
        .dd-agent-av  { width:42px; height:42px; border-radius:50%; object-fit:cover; background:var(--primary-soft,#d1fae5); display:flex; align-items:center; justify-content:center; font-size:1rem; font-weight:800; color:var(--primary,#0f766e); flex-shrink:0; overflow:hidden; border:2px solid var(--border); }
        .dd-agent-av img { width:100%; height:100%; object-fit:cover; }
    </style>
</head>
<body class="has-bottom-nav">

<header class="app-topbar">
    <a href="<?php echo $isAssignedAgent && !$isCustomer ? 'delivery_agent_jobs.php' : 'delivery.php'; ?>"
       class="button button-secondary button-small">← Back</a>
    <span class="brand">Delivery #<?php echo $id; ?></span>
</header>

<?php if ($flash): ?>
<div class="alert alert-<?php echo sanitize($flash['type']); ?>" style="margin:10px 16px 0;">
    <?php echo sanitize($flash['message']); ?>
</div>
<?php endif; ?>

<main class="dd-shell">

    <!-- ── Status badge ── -->
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:8px;">
        <span class="dd-badge"
              style="background:<?php echo delivery_status_bg($delivery['status']); ?>;color:<?php echo delivery_status_color($delivery['status']); ?>;">
            <?php echo delivery_status_label($delivery['status']); ?>
        </span>
        <span style="font-size:.78rem;color:var(--text-muted,#6b7280);">
            Requested <?php echo time_ago($delivery['created_at']); ?>
            · Updated <?php echo time_ago($delivery['updated_at']); ?>
        </span>
    </div>

    <!-- ── Admin: pending approval notice + approve/reject ── -->
    <?php if ($delivery['status'] === 'pending_approval'): ?>
    <div class="dd-card" style="border-color:#f59e0b;background:#fffbeb;">
        <p class="dd-label" style="color:#b45309;">⏳ Awaiting Admin Review</p>
        <p style="margin:0 0 12px;font-size:.86rem;">This request is queued for admin approval before riders can see it.</p>
        <?php if ($isAdminOrMgr): ?>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <form method="post" action="delivery_ajax.php">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action"      value="approve_request">
                <input type="hidden" name="delivery_id" value="<?php echo $id; ?>">
                <button type="submit" class="button button-primary button-small">✅ Approve</button>
            </form>
            <form method="post" action="delivery_ajax.php" style="display:flex;gap:6px;align-items:center;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action"      value="reject_request">
                <input type="hidden" name="delivery_id" value="<?php echo $id; ?>">
                <input type="text" name="rejection_reason" placeholder="Rejection reason (required)" style="font-size:.8rem;padding:5px 10px;width:220px;" required>
                <button type="submit" class="button button-small" style="background:#ef4444;color:#fff;border-color:transparent;">❌ Reject</button>
            </form>
        </div>
        <?php endif; ?>
    </div>
    <?php elseif ($delivery['status'] === 'rejected'): ?>
    <div class="dd-card" style="border-color:#fca5a5;background:#fff5f5;">
        <p class="dd-label" style="color:#c0392b;">❌ Request Rejected</p>
        <?php if ($delivery['rejection_reason']): ?>
        <p style="margin:0 0 10px;font-size:.86rem;"><strong>Reason:</strong> <?php echo sanitize($delivery['rejection_reason']); ?></p>
        <?php endif; ?>
        <?php if ($isCustomer): ?>
        <a href="delivery_request.php" class="button button-secondary button-small">Submit a New Request →</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($delivery['is_flagged'] && $isAdminOrMgr): ?>
    <div class="dd-card" style="border-color:#f59e0b;background:#fffbeb;">
        <p class="dd-label" style="color:#b45309;">⚠️ Fraud Flag</p>
        <p style="margin:0;font-size:.85rem;"><?php echo sanitize($delivery['flag_reason'] ?? ''); ?></p>
    </div>
    <?php endif; ?>

    <!-- ── Timeline ── -->
    <?php if (!in_array($delivery['status'], ['cancelled','failed','rejected'])): ?>
    <div class="dd-card" style="padding:16px 18px 10px;">
        <p class="dd-label" style="margin-bottom:14px;">Progress</p>
        <div style="display:flex;align-items:center;">
            <?php foreach ($currentSteps as $i => $step):
                $done   = $statusIndex !== false && $i < $statusIndex;
                $active = $statusIndex !== false && $i === $statusIndex;
            ?>
            <?php if ($i > 0): ?>
                <div class="dl-tstep-line <?php echo $done || $active ? 'done' : ''; ?>" style="flex:1;"></div>
            <?php endif; ?>
            <div class="dl-tstep" style="flex-shrink:0;">
                <div class="dl-tstep-dot <?php echo $done ? 'done' : ($active ? 'active' : ''); ?>">
                    <?php echo $done ? '✓' : ''; ?>
                </div>
                <div class="dl-tstep-label <?php echo $active ? 'active' : ''; ?>" style="max-width:60px;text-align:center;">
                    <?php echo $stepLabels[$step] ?? ucfirst($step); ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── CUSTOMER: Applications list ── -->
    <?php if ($isCustomer && $delivery['status'] === 'approved'): ?>
    <div class="dd-card">
        <p class="dd-label">Rider Applications
            <?php if ($applications): ?>
            <span style="background:var(--primary,#0f766e);color:#fff;border-radius:10px;padding:1px 7px;font-size:.68rem;margin-left:6px;"><?php echo count($applications); ?></span>
            <?php endif; ?>
        </p>
        <?php if ($applications): ?>
        <?php foreach ($applications as $app): ?>
        <div style="border:1px solid var(--border);border-radius:10px;padding:12px 14px;margin-bottom:10px;">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
                <div style="width:38px;height:38px;border-radius:50%;background:var(--primary-soft,#d1fae5);display:flex;align-items:center;justify-content:center;font-weight:800;color:var(--primary,#0f766e);font-size:.9rem;overflow:hidden;flex-shrink:0;">
                    <?php if (!empty($app['agent_photo'])): ?><img src="<?php echo sanitize($app['agent_photo']); ?>" style="width:100%;height:100%;object-fit:cover;" alt=""><?php else: ?><?php echo strtoupper(substr(display_name(['name'=>$app['agent_name'],'username'=>$app['agent_username']]),0,1)); ?><?php endif; ?>
                </div>
                <div style="flex:1;">
                    <div style="font-weight:800;font-size:.9rem;"><?php echo sanitize(display_name(['name'=>$app['agent_name'],'username'=>$app['agent_username']])); ?></div>
                    <div style="font-size:.75rem;color:var(--text-muted,#6b7280);">
                        <?php echo vehicle_type_icon($app['vehicle_type']); ?> <?php echo vehicle_type_label($app['vehicle_type']); ?>
                        <?php if ($app['rating'] > 0): ?> · ⭐ <?php echo number_format((float)$app['rating'],1); ?><?php endif; ?>
                        <?php if ($app['completed_deliveries'] > 0): ?> · <?php echo $app['completed_deliveries']; ?> done<?php endif; ?>
                    </div>
                    <div style="margin-top:4px;"><?php echo agent_badges_html($app); ?></div>
                </div>
                <?php if ($app['offered_fee'] !== null): ?>
                <div style="text-align:right;flex-shrink:0;">
                    <div style="font-weight:900;color:var(--primary,#0f766e);">GH&#8373; <?php echo number_format((float)$app['offered_fee'],2); ?></div>
                    <div style="font-size:.68rem;color:var(--text-muted,#6b7280);">Offered fee</div>
                </div>
                <?php endif; ?>
            </div>
            <?php if ($app['offer_note']): ?>
            <div style="font-size:.82rem;font-style:italic;color:var(--text-muted,#6b7280);margin-bottom:8px;">"<?php echo sanitize($app['offer_note']); ?>"</div>
            <?php endif; ?>
            <form method="post" action="delivery_ajax.php">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action"      value="select_rider">
                <input type="hidden" name="delivery_id" value="<?php echo $id; ?>">
                <input type="hidden" name="app_id"      value="<?php echo $app['id']; ?>">
                <button type="submit" class="button button-primary button-small">Select This Rider →</button>
            </form>
        </div>
        <?php endforeach; ?>
        <?php else: ?>
        <div style="text-align:center;padding:20px;color:var(--text-muted,#6b7280);font-size:.86rem;">
            No applications yet. Riders will be notified and can apply soon.
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ── AGENT (not assigned): show application status or Apply button ── -->
    <?php if ($isApprovedAgent && !$isAssignedAgent && !$isCustomer && $delivery['status'] === 'approved'): ?>
    <div class="dd-card">
        <p class="dd-label">Your Application</p>
        <?php if ($myApplication): ?>
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
            <span>Status: <strong><?php echo ucfirst($myApplication['status']); ?></strong></span>
            <?php if (in_array($myApplication['status'], ['applied','shortlisted'])): ?>
            <form method="post" action="delivery_ajax.php">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action"      value="withdraw_application">
                <input type="hidden" name="delivery_id" value="<?php echo $id; ?>">
                <button type="submit" class="button button-small" style="background:#ef4444;color:#fff;border-color:transparent;"
                        onclick="return confirm('Withdraw your application?');">Withdraw</button>
            </form>
            <?php endif; ?>
        </div>
        <?php if ($myApplication['offered_fee'] !== null): ?>
        <div style="font-size:.82rem;margin-top:8px;">Your offered fee: GH&#8373; <?php echo number_format((float)$myApplication['offered_fee'],2); ?></div>
        <?php endif; ?>
        <?php else: ?>
        <form method="post" action="delivery_ajax.php">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action"      value="apply_delivery">
            <input type="hidden" name="delivery_id" value="<?php echo $id; ?>">
            <div class="form-group">
                <label style="font-weight:600;font-size:.85rem;">Your fee offer (GH&#8373;) <span style="font-weight:400;color:var(--text-muted,#6b7280);">— optional</span></label>
                <input type="number" name="offered_fee" min="0" step="0.01" style="max-width:160px;margin-top:4px;"
                       placeholder="<?php echo $delivery['delivery_fee'] ? number_format((float)$delivery['delivery_fee'],2) : 'Negotiate'; ?>">
            </div>
            <div class="form-group">
                <label style="font-weight:600;font-size:.85rem;">Message to customer <span style="font-weight:400;color:var(--text-muted,#6b7280);">— optional</span></label>
                <textarea name="offer_note" rows="2" style="margin-top:4px;" placeholder="Why should they pick you?"></textarea>
            </div>
            <button type="submit" class="button button-primary">Apply for This Delivery</button>
        </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ── Route ── -->
    <div class="dd-card">
        <p class="dd-label">Route</p>
        <div style="display:flex;flex-direction:column;gap:10px;">
            <div style="display:flex;gap:10px;align-items:flex-start;">
                <span style="font-size:1.2rem;line-height:1;">📍</span>
                <div style="flex:1;">
                    <div style="font-size:.72rem;color:var(--text-muted,#6b7280);font-weight:700;margin-bottom:2px;">PICKUP</div>
                    <div style="font-weight:700;"><?php echo sanitize($delivery['pickup_location']); ?></div>
                    <div style="font-size:.8rem;color:var(--text-muted,#6b7280);">
                        <?php echo sanitize($delivery['pickup_contact_name']); ?> · <?php echo sanitize($delivery['pickup_contact_phone']); ?>
                    </div>
                    <?php if (!empty($delivery['pickup_maps_link'])): ?>
                    <a href="<?php echo sanitize($delivery['pickup_maps_link']); ?>" target="_blank" rel="noopener"
                       style="display:inline-flex;align-items:center;gap:4px;margin-top:6px;font-size:.76rem;font-weight:700;color:var(--primary,#0f766e);text-decoration:none;">
                        🗺 View pickup on Google Maps ↗
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <div style="padding-left:17px;border-left:2px dashed var(--border);margin-left:9px;height:18px;"></div>
            <div style="display:flex;gap:10px;align-items:flex-start;">
                <span style="font-size:1.2rem;line-height:1;">🏁</span>
                <div style="flex:1;">
                    <div style="font-size:.72rem;color:var(--text-muted,#6b7280);font-weight:700;margin-bottom:2px;">DROP-OFF</div>
                    <div style="font-weight:700;"><?php echo sanitize($delivery['dropoff_location']); ?></div>
                    <div style="font-size:.8rem;color:var(--text-muted,#6b7280);">
                        <?php echo sanitize($delivery['receiver_name']); ?> · <?php echo sanitize($delivery['receiver_phone']); ?>
                    </div>
                    <?php if (!empty($delivery['dropoff_maps_link'])): ?>
                    <a href="<?php echo sanitize($delivery['dropoff_maps_link']); ?>" target="_blank" rel="noopener"
                       style="display:inline-flex;align-items:center;gap:4px;margin-top:6px;font-size:.76rem;font-weight:700;color:var(--primary,#0f766e);text-decoration:none;">
                        🗺 View drop-off on Google Maps ↗
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Item Details ── -->
    <div class="dd-card">
        <p class="dd-label">Item Details</p>
        <div class="dd-row">
            <div class="dd-field">
                <label>Category</label>
                <p><?php echo item_category_icon($delivery['item_category']); ?> <?php echo item_category_label($delivery['item_category']); ?></p>
            </div>
            <?php if ($delivery['package_weight']): ?>
            <div class="dd-field">
                <label>Weight</label>
                <p><?php echo number_format((float)$delivery['package_weight'], 1); ?> kg</p>
            </div>
            <?php endif; ?>
            <div class="dd-field">
                <label>Payment</label>
                <p><?php $pmLabels = ['cash'=>'Cash on Delivery','mobile_money'=>'Mobile Money','card'=>'Card','wallet'=>'Wallet']; echo $pmLabels[$delivery['payment_method']] ?? ucfirst($delivery['payment_method']); ?></p>
            </div>
            <?php if ($delivery['delivery_fee']): ?>
            <div class="dd-field">
                <label>Delivery Fee</label>
                <p style="color:var(--primary,#0f766e);">GH₵ <?php echo number_format((float)$delivery['delivery_fee'], 2); ?></p>
            </div>
            <?php endif; ?>
        </div>
        <div class="dd-field" style="margin-top:4px;">
            <label>Description</label>
            <p><?php echo sanitize($delivery['item_description']); ?></p>
        </div>
        <?php if ($delivery['delivery_notes']): ?>
        <div class="dd-field" style="margin-top:8px;">
            <label>Notes</label>
            <p><?php echo sanitize($delivery['delivery_notes']); ?></p>
        </div>
        <?php endif; ?>
        <?php if ($delivery['preferred_date']): ?>
        <div class="dd-field" style="margin-top:8px;">
            <label>Preferred Time</label>
            <p>
                <?php echo date('d M Y', strtotime($delivery['preferred_date'])); ?>
                <?php echo $delivery['preferred_time'] ? ' at ' . date('g:i A', strtotime($delivery['preferred_time'])) : ''; ?>
            </p>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── Assigned Agent ── -->
    <?php if ($delivery['agent_name']): ?>
    <div class="dd-card">
        <p class="dd-label">Delivery Agent</p>
        <div class="dd-agent-row">
            <div class="dd-agent-av">
                <?php if (!empty($delivery['agent_photo'])): ?>
                    <img src="<?php echo sanitize($delivery['agent_photo']); ?>" alt="">
                <?php else: ?>
                    <?php echo strtoupper(substr(display_name(['name'=>$delivery['agent_name'],'username'=>$delivery['agent_username']]), 0, 1)); ?>
                <?php endif; ?>
            </div>
            <div style="flex:1;">
                <div style="font-weight:800;"><?php echo sanitize(display_name(['name'=>$delivery['agent_name'],'username'=>$delivery['agent_username']])); ?></div>
                <div style="font-size:.8rem;color:var(--text-muted,#6b7280);">
                    <?php echo vehicle_type_icon($delivery['vehicle_type']); ?> <?php echo vehicle_type_label($delivery['vehicle_type']); ?>
                    <?php if ($delivery['agent_rating'] > 0): ?>
                     · ⭐ <?php echo number_format((float)$delivery['agent_rating'], 1); ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($isCustomer && $delivery['agent_phone']): ?>
            <a href="tel:<?php echo sanitize($delivery['agent_phone']); ?>" class="button button-secondary button-small">📞 Call</a>
            <?php endif; ?>
        </div>
    </div>
    <?php elseif ($delivery['status'] === 'pending'): ?>
    <div class="dd-card" style="text-align:center;color:var(--text-muted,#6b7280);">
        <div style="font-size:2rem;margin-bottom:8px;">⏳</div>
        <p style="margin:0;font-size:.88rem;">Waiting for a delivery agent to accept this request.</p>
    </div>
    <?php endif; ?>

    <!-- ── AGENT: Update Status ── -->
    <?php if ($isAssignedAgent): ?>
        <?php $nextStatuses = delivery_agent_next_statuses($delivery['status']); ?>
        <?php if ($nextStatuses): ?>
        <div class="dd-card">
            <p class="dd-label">Update Status</p>
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <?php
                $statusActions = [
                    'accepted'   => ['label'=>'Accept Job',          'color'=>'#3b82f6'],
                    'picked_up'  => ['label'=>'Mark as Picked Up',  'color'=>'#8b5cf6'],
                    'in_transit' => ['label'=>'Mark In Transit',     'color'=>'#f97316'],
                    'delivered'  => ['label'=>'Mark as Delivered',   'color'=>'#10b981'],
                    'failed'     => ['label'=>'Mark Failed Delivery','color'=>'#ef4444'],
                ];
                foreach ($nextStatuses as $ns): ?>
                <form method="post" action="delivery_ajax.php">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="delivery_id" value="<?php echo $id; ?>">
                    <input type="hidden" name="new_status" value="<?php echo $ns; ?>">
                    <button type="submit" class="button"
                            style="background:<?php echo $statusActions[$ns]['color'] ?? 'var(--primary)'; ?>;color:#fff;border-color:transparent;">
                        <?php echo $statusActions[$ns]['label'] ?? ucfirst($ns); ?>
                    </button>
                </form>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    <?php endif; ?>

    <!-- ── CUSTOMER: Cancel ── -->
    <?php if ($isCustomer && in_array($delivery['status'], ['pending','pending_approval','approved','assigned','accepted'])): ?>
    <div class="dd-card">
        <p class="dd-label">Cancel Request</p>
        <form method="post" action="delivery_ajax.php">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="cancel_delivery">
            <input type="hidden" name="delivery_id" value="<?php echo $id; ?>">
            <div class="form-group">
                <label for="cancelled_reason">Reason (optional)</label>
                <input type="text" id="cancelled_reason" name="cancelled_reason" placeholder="Why are you cancelling?">
            </div>
            <button type="submit" class="button"
                    style="background:#ef4444;color:#fff;border-color:transparent;"
                    onclick="return confirm('Cancel this delivery request?');">
                Cancel Request
            </button>
        </form>
    </div>
    <?php endif; ?>

    <!-- ── CUSTOMER: Rate Agent ── -->
    <?php if ($isCustomer && $delivery['status'] === 'delivered' && $delivery['agent_id'] && (!$rating || !$rating['customer_rating'])): ?>
    <div class="dd-card">
        <p class="dd-label">Rate Your Delivery Agent</p>
        <form method="post" action="delivery_ajax.php" onsubmit="return document.querySelector('input[name=rating]:checked') || (alert('Please select a star rating.'), false);">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="rate_delivery">
            <input type="hidden" name="delivery_id" value="<?php echo $id; ?>">
            <input type="hidden" name="rater" value="customer">
            <div class="form-group">
                <label>Rating *</label>
                <?php echo render_stars(0, true, 'rating'); ?>
            </div>
            <div class="form-group">
                <label for="cust_comment">Comment (optional)</label>
                <textarea id="cust_comment" name="comment" rows="2" placeholder="How was your experience?"></textarea>
            </div>
            <button type="submit" class="button button-primary">Submit Rating</button>
        </form>
    </div>
    <?php endif; ?>

    <!-- ── CUSTOMER: Report a Problem ── -->
    <?php if ($isCustomer && $delivery['status'] === 'delivered' && $delivery['agent_id']): ?>
    <div class="dd-card">
        <?php if ($dispute && in_array($dispute['status'], ['open','investigating'], true)): ?>
            <p class="dd-label">Complaint Filed</p>
            <p style="margin:0 0 4px;font-size:.86rem;">
                Status: <strong><?php echo ucfirst($dispute['status']); ?></strong> — an admin will review your report.
            </p>
            <p class="meta" style="margin:0;"><?php echo sanitize($dispute['description']); ?></p>
        <?php elseif ($dispute && in_array($dispute['status'], ['resolved','dismissed'], true)): ?>
            <p class="dd-label">Complaint <?php echo $dispute['status'] === 'resolved' ? 'Resolved' : 'Reviewed'; ?></p>
            <p style="margin:0 0 4px;font-size:.86rem;">
                <?php echo $dispute['status'] === 'resolved' ? 'Your complaint was upheld.' : 'Admin reviewed your complaint and found no issue.'; ?>
            </p>
            <?php if ($dispute['resolution_notes']): ?>
            <p class="meta" style="margin:0;">Admin note: <?php echo sanitize($dispute['resolution_notes']); ?></p>
            <?php endif; ?>
        <?php elseif (!$complaintWindowOpen): ?>
            <p class="dd-label">Not What You Expected?</p>
            <p class="meta" style="margin:0;">The window to report a problem (<?php echo $complaintWindowDays; ?> days after delivery) has passed. Contact support directly if you still need help.</p>
        <?php else: ?>
            <p class="dd-label">Not What You Expected?</p>
            <p class="meta" style="margin:0 0 10px;">If this item was not truly delivered, arrived damaged, or something else went wrong, let us know within <?php echo $complaintWindowDays; ?> days of delivery.</p>
            <form method="post" action="delivery_ajax.php">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="file_delivery_dispute">
                <input type="hidden" name="delivery_id" value="<?php echo $id; ?>">
                <div class="form-group">
                    <label for="dispute_type">What went wrong? *</label>
                    <select id="dispute_type" name="dispute_type" required>
                        <option value="">Select…</option>
                        <option value="not_delivered">Item was not actually delivered</option>
                        <option value="damaged">Item arrived damaged</option>
                        <option value="wrong_item">Wrong item received</option>
                        <option value="late">Delivered very late</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="dispute_description">Describe the problem *</label>
                    <textarea id="dispute_description" name="description" rows="3" placeholder="Tell us what happened…" required></textarea>
                </div>
                <button type="submit" class="button" style="background:#ef4444;color:#fff;border-color:transparent;"
                        onclick="return confirm('File a complaint about this delivery? An admin will review it.');">
                    🚩 Report a Problem
                </button>
            </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ── AGENT: Rate Customer ── -->
    <?php if ($isAssignedAgent && $delivery['status'] === 'delivered' && (!$rating || !$rating['agent_rating'])): ?>
    <div class="dd-card">
        <p class="dd-label">Rate This Customer</p>
        <form method="post" action="delivery_ajax.php" onsubmit="return document.querySelector('input[name=agent_rating_val]:checked') || (alert('Please select a star rating.'), false);">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="rate_delivery">
            <input type="hidden" name="delivery_id" value="<?php echo $id; ?>">
            <input type="hidden" name="rater" value="agent">
            <div class="form-group">
                <label>Rating *</label>
                <?php echo render_stars(0, true, 'agent_rating_val'); ?>
                <!-- proxy: copy value to hidden field named 'rating' on submit -->
                <input type="hidden" name="rating" id="agent_rating_hidden">
            </div>
            <div class="form-group">
                <label for="agent_comment">Comment (optional)</label>
                <textarea id="agent_comment" name="comment" rows="2" placeholder="How was the customer to work with?"></textarea>
            </div>
            <button type="submit" class="button button-primary">Submit Rating</button>
        </form>
        <script>
        (function(){
            var form = document.currentScript.closest('.dd-card').querySelector('form');
            form.addEventListener('change', function(){
                var checked = form.querySelector('input[name="agent_rating_val"]:checked');
                if (checked) document.getElementById('agent_rating_hidden').value = checked.value;
            });
        })();
        </script>
    </div>
    <?php endif; ?>

    <!-- ── Show existing ratings ── -->
    <?php if ($rating && ($rating['customer_rating'] || $rating['agent_rating'])): ?>
    <div class="dd-card">
        <p class="dd-label">Ratings</p>
        <?php if ($rating['customer_rating']): ?>
        <div style="margin-bottom:10px;">
            <div style="font-size:.8rem;font-weight:700;color:var(--text-muted,#6b7280);margin-bottom:4px;">Customer rated agent</div>
            <?php echo render_stars((float)$rating['customer_rating']); ?>
            <?php if ($rating['customer_comment']): ?><p style="margin:6px 0 0;font-size:.85rem;"><?php echo sanitize($rating['customer_comment']); ?></p><?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if ($rating['agent_rating']): ?>
        <div>
            <div style="font-size:.8rem;font-weight:700;color:var(--text-muted,#6b7280);margin-bottom:4px;">Agent rated customer</div>
            <?php echo render_stars((float)$rating['agent_rating']); ?>
            <?php if ($rating['agent_comment']): ?><p style="margin:6px 0 0;font-size:.85rem;"><?php echo sanitize($rating['agent_comment']); ?></p><?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ── Cancellation reason ── -->
    <?php if ($delivery['status'] === 'cancelled' && $delivery['cancelled_reason']): ?>
    <div class="dd-card" style="border-color:#fca5a5;">
        <p class="dd-label" style="color:#ef4444;">Cancellation Reason</p>
        <p style="margin:0;"><?php echo sanitize($delivery['cancelled_reason']); ?></p>
    </div>
    <?php endif; ?>

</main>

<?php require_once __DIR__ . '/partials/bottom_nav.php'; ?>
</body>
</html>
