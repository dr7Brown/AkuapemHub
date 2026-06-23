<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/delivery_functions.php';

require_login();
$user = current_user();

$agentProfile = get_delivery_agent_for_user((int)$user['id']);
if (!$agentProfile) {
    flash('Register as a delivery agent first.', 'warning');
    header('Location: become_delivery_agent.php');
    exit;
}

$flash = get_flash();
$agentId = (int)$agentProfile['id'];
$tab     = $_GET['tab'] ?? 'available';

// ── Available jobs: approved, no agent assigned, not already full ─────────────
$availableJobs = [];
if ($agentProfile['verification_status'] === 'approved') {
    $avStmt = $pdo->prepare(
        "SELECT dr.*,
                cu.name AS customer_name, cu.username AS customer_username,
                cu.profile_photo AS customer_photo,
                da_app.status AS my_app_status, da_app.offered_fee AS my_offer
         FROM delivery_requests dr
         JOIN users cu ON dr.customer_id = cu.id
         LEFT JOIN delivery_applications da_app
                ON da_app.delivery_request_id = dr.id AND da_app.agent_id = ?
         WHERE dr.status = 'approved' AND dr.agent_id IS NULL
         ORDER BY
             (da_app.id IS NOT NULL) DESC,
             dr.preferred_date ASC, dr.created_at ASC
         LIMIT 40"
    );
    $avStmt->execute([$agentId]);
    $availableJobs = $avStmt->fetchAll();
}

// ── My Applications ────────────────────────────────────────────────────────────
$myAppsStmt = $pdo->prepare(
    "SELECT da.*, dr.pickup_location, dr.dropoff_location, dr.item_description,
            dr.item_category, dr.delivery_fee, dr.status AS req_status,
            dr.agent_id AS req_agent_id, dr.preferred_date,
            cu.name AS customer_name, cu.profile_photo AS customer_photo
     FROM delivery_applications da
     JOIN delivery_requests dr ON da.delivery_request_id = dr.id
     JOIN users cu ON dr.customer_id = cu.id
     WHERE da.agent_id = ?
     ORDER BY da.updated_at DESC LIMIT 50"
);
$myAppsStmt->execute([$agentId]);
$myApplications = $myAppsStmt->fetchAll();

// ── Active assigned deliveries ─────────────────────────────────────────────────
$activeStmt = $pdo->prepare(
    "SELECT dr.*, cu.name AS customer_name, cu.username AS customer_username,
            cu.phone AS customer_phone, cu.profile_photo AS customer_photo
     FROM delivery_requests dr
     JOIN users cu ON dr.customer_id = cu.id
     WHERE dr.agent_id = ? AND dr.status IN ('assigned','accepted','picked_up','in_progress','in_transit')
     ORDER BY dr.updated_at DESC"
);
$activeStmt->execute([$agentId]);
$activeDeliveries = $activeStmt->fetchAll();

// ── History ────────────────────────────────────────────────────────────────────
$historyStmt = $pdo->prepare(
    "SELECT dr.*, cu.name AS customer_name, cu.username AS customer_username,
            drr.customer_rating
     FROM delivery_requests dr
     JOIN users cu ON dr.customer_id = cu.id
     LEFT JOIN delivery_ratings drr ON drr.delivery_request_id = dr.id
     WHERE dr.agent_id = ? AND dr.status IN ('delivered','cancelled','failed','rejected')
     ORDER BY dr.updated_at DESC LIMIT 40"
);
$historyStmt->execute([$agentId]);
$history = $historyStmt->fetchAll();

// ── Earnings ────────────────────────────────────────────────────────────────────
$earningsStmt = $pdo->prepare(
    "SELECT COALESCE(SUM(delivery_fee),0) AS total_earned,
            COUNT(*) AS total_done
     FROM delivery_requests WHERE agent_id = ? AND status = 'delivered'"
);
$earningsStmt->execute([$agentId]);
$earnings = $earningsStmt->fetch();

$pendingAppCount = count(array_filter($myApplications, fn($a) => $a['da_status'] ?? $a['status'] === 'applied'));
$activeCount     = count($activeDeliveries);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agent Dashboard — AkuapemConnect</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .ag-stats { display:flex; gap:12px; padding:14px 16px; background:var(--surface); border-bottom:1px solid var(--border); flex-wrap:wrap; }
        .ag-stat  { flex:1; min-width:80px; text-align:center; }
        .ag-stat strong { display:block; font-size:1.45rem; font-weight:900; color:var(--primary,#0f766e); line-height:1.1; }
        .ag-stat span   { font-size:.72rem; color:var(--text-muted,#6b7280); }

        .ag-tabs { display:flex; background:var(--surface); border-bottom:1px solid var(--border); overflow-x:auto; scrollbar-width:none; }
        .ag-tabs::-webkit-scrollbar { display:none; }
        .ag-tab  { flex-shrink:0; padding:12px 14px; text-align:center; font-size:.8rem; font-weight:700; text-decoration:none; color:var(--text-muted,#6b7280); border-bottom:3px solid transparent; white-space:nowrap; }
        .ag-tab.active { color:var(--primary,#0f766e); border-bottom-color:var(--primary,#0f766e); }

        .ag-section { padding:14px 16px 80px; max-width:760px; margin:0 auto; }

        /* Job card */
        .ag-card { background:var(--surface); border:2px solid var(--border); border-radius:14px; padding:14px 16px; margin-bottom:10px; transition:border-color .12s; }
        .ag-card.applied-card { border-color:var(--primary-soft,#a7f3d0); }
        .ag-card-head { display:flex; align-items:flex-start; justify-content:space-between; gap:10px; margin-bottom:10px; }
        .ag-card-desc { font-weight:800; font-size:.9rem; line-height:1.4; }
        .ag-badge { display:inline-block; padding:3px 9px; border-radius:20px; font-size:.7rem; font-weight:800; }
        .ag-card-route { font-size:.8rem; color:var(--text-muted,#6b7280); display:flex; flex-direction:column; gap:3px; margin-bottom:10px; }
        .ag-card-foot { display:flex; gap:8px; align-items:center; flex-wrap:wrap; border-top:1px solid var(--border); padding-top:10px; }
        .ag-av { width:28px; height:28px; border-radius:50%; border:1px solid var(--border); background:var(--primary-soft,#d1fae5); display:flex; align-items:center; justify-content:center; font-size:.72rem; font-weight:800; color:var(--primary,#0f766e); flex-shrink:0; overflow:hidden; }
        .ag-av img { width:100%; height:100%; object-fit:cover; }

        /* Apply form inline */
        .ag-apply-panel { display:none; border-top:1px solid var(--border); margin-top:10px; padding-top:10px; }
        .ag-apply-panel.open { display:block; }
        .ag-apply-panel label { font-weight:600; font-size:.83rem; }
        .ag-apply-panel textarea, .ag-apply-panel input[type=number] { width:100%; box-sizing:border-box; margin-top:4px; margin-bottom:10px; }

        /* Availability bar */
        .ag-avail-bar { display:flex; align-items:center; gap:8px; padding:10px 16px; background:var(--surface); border-bottom:1px solid var(--border); flex-wrap:wrap; }

        /* Status update buttons */
        .ag-status-btns { display:flex; gap:8px; flex-wrap:wrap; }

        /* Tier badges */
        .tier-badge-row { display:flex; gap:6px; flex-wrap:wrap; padding:10px 16px; background:#fffbeb; border-bottom:1px solid #fde68a; }
    </style>
</head>
<body class="has-bottom-nav">

<header class="app-topbar">
    <span class="brand"><span class="brand-icon">🛵</span> Agent Dashboard</span>
    <a href="delivery.php" class="button button-secondary button-small">Customer View</a>
</header>

<?php if ($flash): ?>
<div class="alert alert-<?php echo sanitize($flash['type']); ?>" style="margin:10px 16px 0;">
    <?php echo sanitize($flash['message']); ?>
</div>
<?php endif; ?>

<!-- Tier / upgrade nudge -->
<?php $hasUpgrade = !agent_is_premium($agentProfile) || !agent_is_verified($agentProfile) || !agent_is_sponsored($agentProfile); ?>
<?php if ($agentProfile['verification_status'] === 'approved' && $hasUpgrade): ?>
<div class="tier-badge-row">
    <?php echo agent_badges_html($agentProfile) ?: '<span style="font-size:.78rem;color:var(--text-muted,#6b7280);">Basic Rider</span>'; ?>
    <div style="margin-left:auto;display:flex;gap:8px;align-items:center;">
        <?php if (!agent_is_premium($agentProfile)): ?>
            <a href="delivery_subscribe.php" class="button button-small" style="background:#8b5cf6;color:#fff;border-color:transparent;font-size:.75rem;">&#9733; Go Premium</a>
        <?php endif; ?>
        <?php if (!agent_is_verified($agentProfile)): ?>
            <a href="delivery_verify.php" class="button button-small" style="background:#10b981;color:#fff;border-color:transparent;font-size:.75rem;">&#10003; Get Verified</a>
        <?php endif; ?>
        <?php if (!agent_is_sponsored($agentProfile)): ?>
            <a href="delivery_sponsor.php" class="button button-small" style="background:#f59e0b;color:#fff;border-color:transparent;font-size:.75rem;">&#9650; Sponsor</a>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Verification pending notice -->
<?php if ($agentProfile['verification_status'] === 'pending'): ?>
<div style="background:#fef3c7;border:1px solid #f59e0b;border-radius:10px;padding:14px 16px;margin:12px 16px;">
    ⏳ <strong>Application under review.</strong> You'll be notified once approved. You cannot apply to jobs yet.
</div>
<?php elseif ($agentProfile['verification_status'] === 'rejected'): ?>
<div style="background:#fee2e2;border:1px solid #ef4444;border-radius:10px;padding:14px 16px;margin:12px 16px;">
    ❌ <strong>Application rejected.</strong>
    <?php if ($agentProfile['rejection_reason']): ?> <?php echo sanitize($agentProfile['rejection_reason']); ?><?php endif; ?>
    <a href="become_delivery_agent.php" style="color:#c0392b;font-weight:700;margin-left:8px;">Re-apply →</a>
</div>
<?php endif; ?>

<!-- Stats -->
<div class="ag-stats">
    <div class="ag-stat"><strong><?php echo count($availableJobs); ?></strong><span>Open Jobs</span></div>
    <div class="ag-stat"><strong><?php echo $activeCount; ?></strong><span>Active</span></div>
    <div class="ag-stat"><strong><?php echo $agentProfile['completed_deliveries']; ?></strong><span>Done</span></div>
    <div class="ag-stat"><strong><?php echo $agentProfile['rating'] > 0 ? number_format((float)$agentProfile['rating'],1).'★' : '—'; ?></strong><span>Rating</span></div>
    <div class="ag-stat"><strong>GH&#8373; <?php echo number_format((float)$earnings['total_earned'],2); ?></strong><span>Earned</span></div>
</div>

<!-- Availability toggle -->
<?php if ($agentProfile['verification_status'] === 'approved'): ?>
<form method="post" action="delivery_ajax.php" class="ag-avail-bar">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="action" value="toggle_availability">
    <span style="font-size:.83rem;font-weight:700;">Status:</span>
    <?php $avColors=['available'=>'#10b981','busy'=>'#f59e0b','offline'=>'#9ca3af']; $cur=$agentProfile['availability_status']; ?>
    <?php foreach (['available'=>'Available','busy'=>'Busy','offline'=>'Offline'] as $v=>$l): ?>
        <button type="submit" name="availability" value="<?php echo $v; ?>" class="button button-small"
                style="<?php echo $cur===$v ? 'background:'.$avColors[$v].';color:#fff;border-color:transparent;' : ''; ?>">
            <?php echo $l; ?>
        </button>
    <?php endforeach; ?>
</form>
<?php endif; ?>

<!-- Tabs -->
<div class="ag-tabs">
    <a href="?tab=available" class="ag-tab <?php echo $tab==='available'?'active':''; ?>">
        Available
        <?php if ($availableJobs): ?><span style="background:var(--primary,#0f766e);color:#fff;border-radius:10px;padding:0 6px;font-size:.65rem;margin-left:3px;"><?php echo count($availableJobs); ?></span><?php endif; ?>
    </a>
    <a href="?tab=applications" class="ag-tab <?php echo $tab==='applications'?'active':''; ?>">
        My Applications
        <?php if ($myApplications): ?><span style="background:#8b5cf6;color:#fff;border-radius:10px;padding:0 6px;font-size:.65rem;margin-left:3px;"><?php echo count($myApplications); ?></span><?php endif; ?>
    </a>
    <a href="?tab=active" class="ag-tab <?php echo $tab==='active'?'active':''; ?>">
        Active
        <?php if ($activeDeliveries): ?><span style="background:#f59e0b;color:#fff;border-radius:10px;padding:0 6px;font-size:.65rem;margin-left:3px;"><?php echo $activeCount; ?></span><?php endif; ?>
    </a>
    <a href="?tab=history" class="ag-tab <?php echo $tab==='history'?'active':''; ?>">History</a>
</div>

<div class="ag-section">

<?php if ($tab === 'available'): ?>
    <?php if ($agentProfile['verification_status'] !== 'approved'): ?>
    <div style="text-align:center;padding:32px;color:var(--text-muted,#6b7280);">Your profile must be approved to see and apply for jobs.</div>
    <?php elseif ($availableJobs): ?>
        <?php foreach ($availableJobs as $j): ?>
        <?php
        $myStatus  = $j['my_app_status'];    // null if no application yet
        $myOffer   = $j['my_offer'];
        $applied   = $myStatus !== null;
        ?>
        <div class="ag-card <?php echo $applied ? 'applied-card' : ''; ?>">
            <div class="ag-card-head">
                <div>
                    <div class="ag-card-desc">
                        <?php echo item_category_icon($j['item_category']); ?>
                        <?php echo sanitize(mb_substr($j['item_description'],0,70)).(mb_strlen($j['item_description'])>70?'…':''); ?>
                    </div>
                    <div style="font-size:.72rem;color:var(--text-muted,#6b7280);margin-top:2px;">
                        <?php echo item_category_label($j['item_category']); ?> · #<?php echo $j['id']; ?>
                        <?php if ($j['package_weight']): ?> · <?php echo number_format((float)$j['package_weight'],1); ?> kg<?php endif; ?>
                    </div>
                </div>
                <?php if ($applied): ?>
                <span class="ag-badge" style="background:<?php echo delivery_status_bg($myStatus??'pending'); ?>;color:<?php echo delivery_status_color($myStatus??'pending'); ?>;">
                    <?php echo ucfirst($myStatus ?? 'Applied'); ?>
                </span>
                <?php elseif ($j['delivery_fee']): ?>
                <div style="text-align:right;">
                    <div style="font-weight:900;color:var(--primary,#0f766e);">GH&#8373; <?php echo number_format((float)$j['delivery_fee'],2); ?></div>
                    <div style="font-size:.7rem;color:var(--text-muted,#6b7280);">Customer fee</div>
                </div>
                <?php endif; ?>
            </div>
            <div class="ag-card-route">
                <span>📍 From: <?php echo sanitize(mb_substr($j['pickup_location'],0,70)); ?></span>
                <span>🏁 To: <?php echo sanitize(mb_substr($j['dropoff_location'],0,70)); ?></span>
                <?php if ($j['preferred_date']): ?><span>📅 <?php echo date('d M Y', strtotime($j['preferred_date'])); ?><?php echo $j['preferred_time'] ? ' at '.date('g:i A', strtotime($j['preferred_time'])) : ''; ?></span><?php endif; ?>
            </div>
            <div class="ag-card-foot">
                <div class="ag-av"><?php if (!empty($j['customer_photo'])): ?><img src="<?php echo sanitize($j['customer_photo']); ?>" alt=""><?php else: ?><?php echo strtoupper(substr(display_name(['name'=>$j['customer_name'],'username'=>$j['customer_username']]),0,1)); ?><?php endif; ?></div>
                <span style="font-size:.78rem;color:var(--text-muted,#6b7280);"><?php echo sanitize(display_name(['name'=>$j['customer_name'],'username'=>$j['customer_username']])); ?></span>
                <span style="font-size:.74rem;color:var(--text-muted,#6b7280);">🕐 <?php echo time_ago($j['created_at']); ?></span>
                <div style="margin-left:auto;display:flex;gap:8px;">
                    <a href="delivery_detail.php?id=<?php echo $j['id']; ?>" class="button button-secondary button-small">Details</a>
                    <?php if (!$applied): ?>
                    <button type="button" class="button button-primary button-small"
                            onclick="toggleApply(<?php echo $j['id']; ?>)">Apply →</button>
                    <?php elseif (in_array($myStatus, ['applied','shortlisted'])): ?>
                    <form method="post" action="delivery_ajax.php" style="margin:0;">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action"      value="withdraw_application">
                        <input type="hidden" name="delivery_id" value="<?php echo $j['id']; ?>">
                        <button type="submit" class="button button-small" style="background:#ef4444;color:#fff;border-color:transparent;"
                                onclick="return confirm('Withdraw your application?');">Withdraw</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Inline apply form -->
            <?php if (!$applied): ?>
            <div class="ag-apply-panel" id="apply-<?php echo $j['id']; ?>">
                <form method="post" action="delivery_ajax.php">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action"      value="apply_delivery">
                    <input type="hidden" name="delivery_id" value="<?php echo $j['id']; ?>">
                    <label>Your fee offer (GH&#8373;)
                        <span style="font-weight:400;color:var(--text-muted,#6b7280);font-size:.78rem;">
                            — leave blank to accept customer's stated fee
                        </span>
                    </label>
                    <input type="number" name="offered_fee" min="0" step="0.01"
                           placeholder="<?php echo $j['delivery_fee'] ? number_format((float)$j['delivery_fee'],2) : 'Negotiate'; ?>">
                    <label>Message to customer (optional)</label>
                    <textarea name="offer_note" rows="2" placeholder="Why should the customer pick you? Any relevant experience…"></textarea>
                    <div style="display:flex;gap:8px;">
                        <button type="submit" class="button button-primary button-small">Submit Application</button>
                        <button type="button" class="button button-secondary button-small" onclick="toggleApply(<?php echo $j['id']; ?>)">Cancel</button>
                    </div>
                </form>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div style="text-align:center;padding:40px 20px;color:var(--text-muted,#6b7280);">
            <div style="font-size:2.5rem;opacity:.4;margin-bottom:10px;">📦</div>
            <p>No approved delivery requests right now. Check back soon.</p>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php if ($tab === 'applications'): ?>
    <?php if ($myApplications): ?>
        <?php foreach ($myApplications as $a): ?>
        <a href="delivery_detail.php?id=<?php echo $a['delivery_request_id']; ?>" class="ag-card" style="display:block;text-decoration:none;color:inherit;">
            <div class="ag-card-head">
                <div>
                    <div class="ag-card-desc" style="font-size:.87rem;">
                        <?php echo item_category_icon($a['item_category']); ?>
                        <?php echo sanitize(mb_substr($a['item_description'],0,60)).(mb_strlen($a['item_description'])>60?'…':''); ?>
                    </div>
                    <div style="font-size:.72rem;color:var(--text-muted,#6b7280);margin-top:2px;">
                        Request #<?php echo $a['delivery_request_id']; ?> · Applied <?php echo time_ago($a['created_at']); ?>
                    </div>
                </div>
                <span class="ag-badge" style="background:<?php echo delivery_status_bg($a['status']); ?>;color:<?php echo delivery_status_color($a['status']); ?>;">
                    <?php echo ucfirst($a['status']); ?>
                </span>
            </div>
            <div style="font-size:.8rem;color:var(--text-muted,#6b7280);line-height:1.5;">
                📍 <?php echo sanitize(mb_substr($a['pickup_location'],0,50)); ?> → <?php echo sanitize(mb_substr($a['dropoff_location'],0,50)); ?>
            </div>
            <?php if ($a['offered_fee']): ?>
            <div style="font-size:.82rem;color:var(--primary,#0f766e);font-weight:700;margin-top:6px;">Your offer: GH&#8373; <?php echo number_format((float)$a['offered_fee'],2); ?></div>
            <?php endif; ?>
            <?php if ($a['offer_note']): ?>
            <div style="font-size:.78rem;color:var(--text-muted,#6b7280);margin-top:4px;font-style:italic;">"<?php echo sanitize(mb_substr($a['offer_note'],0,100)); ?>"</div>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>
    <?php else: ?>
        <div style="text-align:center;padding:40px 20px;color:var(--text-muted,#6b7280);">
            <p>You haven't applied to any deliveries yet. <a href="?tab=available" style="color:var(--primary,#0f766e);">Browse available jobs →</a></p>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php if ($tab === 'active'): ?>
    <?php if ($activeDeliveries): ?>
        <?php foreach ($activeDeliveries as $d): ?>
        <div class="ag-card">
            <div class="ag-card-head">
                <div>
                    <div class="ag-card-desc">
                        <?php echo item_category_icon($d['item_category']); ?>
                        <?php echo sanitize(mb_substr($d['item_description'],0,60)).(mb_strlen($d['item_description'])>60?'…':''); ?>
                    </div>
                </div>
                <span class="ag-badge" style="background:<?php echo delivery_status_bg($d['status']); ?>;color:<?php echo delivery_status_color($d['status']); ?>;">
                    <?php echo delivery_status_label($d['status']); ?>
                </span>
            </div>
            <div class="ag-card-route">
                <span>📍 From: <?php echo sanitize(mb_substr($d['pickup_location'],0,70)); ?>
                    <?php if (!empty($d['pickup_maps_link'])): ?>&nbsp;<a href="<?php echo sanitize($d['pickup_maps_link']); ?>" target="_blank" rel="noopener" style="font-size:.72rem;color:var(--primary,#0f766e);font-weight:700;">🗺 Map</a><?php endif; ?>
                </span>
                <span>🏁 To: <?php echo sanitize(mb_substr($d['dropoff_location'],0,70)); ?>
                    <?php if (!empty($d['dropoff_maps_link'])): ?>&nbsp;<a href="<?php echo sanitize($d['dropoff_maps_link']); ?>" target="_blank" rel="noopener" style="font-size:.72rem;color:var(--primary,#0f766e);font-weight:700;">🗺 Map</a><?php endif; ?>
                </span>
                <span>👤 Receiver: <?php echo sanitize($d['receiver_name']); ?> · <?php echo sanitize($d['receiver_phone']); ?></span>
            </div>
            <div class="ag-card-foot">
                <?php $nextSts = delivery_agent_next_statuses($d['status']); ?>
                <?php $sColors = ['picked_up'=>'#8b5cf6','in_transit'=>'#f97316','in_progress'=>'#f97316','delivered'=>'#10b981','failed'=>'#ef4444']; ?>
                <div class="ag-status-btns">
                <?php foreach ($nextSts as $ns): ?>
                <form method="post" action="delivery_ajax.php" style="margin:0;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action"      value="update_status">
                    <input type="hidden" name="delivery_id" value="<?php echo $d['id']; ?>">
                    <input type="hidden" name="new_status"  value="<?php echo $ns; ?>">
                    <?php $nsL=['picked_up'=>'Picked Up','in_transit'=>'In Transit','in_progress'=>'In Progress','delivered'=>'Delivered','failed'=>'Failed']; ?>
                    <button type="submit" class="button button-small"
                            style="background:<?php echo $sColors[$ns]??'var(--primary)'; ?>;color:#fff;border-color:transparent;">
                        <?php echo $nsL[$ns]??ucfirst($ns); ?>
                    </button>
                </form>
                <?php endforeach; ?>
                </div>
                <a href="delivery_detail.php?id=<?php echo $d['id']; ?>" class="button button-secondary button-small" style="margin-left:auto;">Details</a>
            </div>
        </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div style="text-align:center;padding:40px 20px;color:var(--text-muted,#6b7280);">
            <div style="font-size:2.5rem;opacity:.4;margin-bottom:10px;">🛵</div>
            <p>No active deliveries. <a href="?tab=available" style="color:var(--primary,#0f766e);">Browse available jobs →</a></p>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php if ($tab === 'history'): ?>
    <?php if ($history): ?>
        <?php foreach ($history as $d): ?>
        <a href="delivery_detail.php?id=<?php echo $d['id']; ?>" class="ag-card" style="display:block;text-decoration:none;color:inherit;">
            <div class="ag-card-head">
                <div>
                    <div class="ag-card-desc" style="font-size:.85rem;">
                        <?php echo item_category_icon($d['item_category']); ?>
                        <?php echo sanitize(mb_substr($d['item_description'],0,55)).(mb_strlen($d['item_description'])>55?'…':''); ?>
                    </div>
                    <div style="font-size:.72rem;color:var(--text-muted,#6b7280);margin-top:2px;">
                        <?php echo date('d M Y', strtotime($d['updated_at'])); ?> · #<?php echo $d['id']; ?>
                    </div>
                </div>
                <div style="text-align:right;">
                    <span class="ag-badge" style="background:<?php echo delivery_status_bg($d['status']); ?>;color:<?php echo delivery_status_color($d['status']); ?>;">
                        <?php echo delivery_status_label($d['status']); ?>
                    </span>
                    <?php if ($d['customer_rating']): ?>
                    <div style="font-size:.78rem;color:#f59e0b;margin-top:4px;"><?php echo str_repeat('★',(int)$d['customer_rating']); ?> <?php echo $d['customer_rating']; ?>/5</div>
                    <?php endif; ?>
                </div>
            </div>
            <div style="font-size:.78rem;color:var(--text-muted,#6b7280);">
                📍 <?php echo sanitize(mb_substr($d['pickup_location'],0,50)); ?> → <?php echo sanitize(mb_substr($d['dropoff_location'],0,50)); ?>
            </div>
            <?php if ($d['delivery_fee'] > 0): ?>
            <div style="font-size:.82rem;color:var(--primary,#0f766e);font-weight:700;margin-top:6px;">GH&#8373; <?php echo number_format((float)$d['delivery_fee'],2); ?></div>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>
    <?php else: ?>
        <div style="text-align:center;padding:40px 20px;color:var(--text-muted,#6b7280);">
            <p>No delivery history yet.</p>
        </div>
    <?php endif; ?>
<?php endif; ?>

</div><!-- /ag-section -->

<?php require_once __DIR__ . '/partials/bottom_nav.php'; ?>

<script>
function toggleApply(id) {
    var el = document.getElementById('apply-' + id);
    el.classList.toggle('open');
}
</script>
</body>
</html>
