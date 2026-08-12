<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_module_enabled('jobs', 'Jobs & Services');
require_login();
$user = current_user();

// ── Job Applications (workers) ────────────────────────────────────────────────
$myJobApps = [];
if (is_worker()) {
    $appStmt = $pdo->prepare("
        SELECT sr.id AS job_id, sr.title, sr.location, sr.budget, sr.budget_amount,
               a.id AS app_id, a.status AS app_status, a.applied_at,
               c.name AS category_name, u.name AS customer_name
        FROM applications a
        JOIN service_requests sr ON a.request_id = sr.id
        LEFT JOIN service_categories c ON sr.category_id = c.id
        LEFT JOIN users u ON sr.customer_id = u.id
        WHERE a.worker_id = ?
        ORDER BY a.applied_at DESC LIMIT 100
    ");
    $appStmt->execute([$user['id']]);
    $myJobApps = $appStmt->fetchAll();
}

// ── Jobs I've posted (as customer) — shown alongside worker applications,
// same as the Delivery/Marketplace tabs already show both sides of activity
// regardless of the user's current role. ─────────────────────────────────
$postStmt = $pdo->prepare("
    SELECT sr.id, sr.title, sr.location, sr.budget, sr.budget_amount, sr.status,
           sr.rejection_reason, sr.created_at, c.name AS category_name
    FROM service_requests sr
    LEFT JOIN service_categories c ON sr.category_id = c.id
    WHERE sr.customer_id = ?
    ORDER BY sr.created_at DESC LIMIT 100
");
$postStmt->execute([$user['id']]);
$myPostedJobs = $postStmt->fetchAll();

// ── Funeral Announcements ─────────────────────────────────────────────────────
$funeralStmt = $pdo->prepare("SELECT id, deceased_name, burial_date, status, rejection_reason, created_at FROM funeral_announcements WHERE user_id = ? ORDER BY created_at DESC LIMIT 100");
$funeralStmt->execute([$user['id']]);
$myFunerals = $funeralStmt->fetchAll();

// ── Events ────────────────────────────────────────────────────────────────────
$eventStmt = $pdo->prepare("SELECT id, title, venue, start_date, status, rejection_reason, created_at FROM events WHERE user_id = ? ORDER BY created_at DESC LIMIT 100");
$eventStmt->execute([$user['id']]);
$myEvents = $eventStmt->fetchAll();

// ── News Articles ─────────────────────────────────────────────────────────────
$newsStmt = $pdo->prepare("SELECT id, title, status, rejection_reason, created_at FROM news WHERE user_id = ? ORDER BY created_at DESC LIMIT 100");
$newsStmt->execute([$user['id']]);
$myNews = $newsStmt->fetchAll();

// ── Delivery Requests (as customer) ──────────────────────────────────────────
$myDeliveryRequests = [];
try {
    $dlStmt = $pdo->prepare("
        SELECT dr.id, dr.item_description, dr.item_category, dr.pickup_location, dr.dropoff_location,
               dr.status, dr.rejection_reason, dr.delivery_fee, dr.preferred_date, dr.created_at,
               au.name AS agent_name
        FROM delivery_requests dr
        LEFT JOIN delivery_agents da ON dr.agent_id = da.id
        LEFT JOIN users au ON da.user_id = au.id
        WHERE dr.customer_id = ?
        ORDER BY dr.created_at DESC LIMIT 100
    ");
    $dlStmt->execute([$user['id']]);
    $myDeliveryRequests = $dlStmt->fetchAll();
} catch (Exception $e) {}

// ── Delivery Applications (as rider/agent) ────────────────────────────────────
$myDeliveryApplications = [];
try {
    $agentProfile = $pdo->prepare('SELECT id FROM delivery_agents WHERE user_id=?');
    $agentProfile->execute([$user['id']]);
    $agentRow = $agentProfile->fetch();
    if ($agentRow) {
        $daStmt = $pdo->prepare("
            SELECT dapp.id, dapp.status, dapp.offered_fee, dapp.offer_note, dapp.created_at,
                   dr.id AS request_id, dr.item_description, dr.item_category,
                   dr.pickup_location, dr.dropoff_location, dr.delivery_fee, dr.status AS request_status
            FROM delivery_applications dapp
            JOIN delivery_requests dr ON dapp.delivery_request_id = dr.id
            WHERE dapp.agent_id = ?
            ORDER BY dapp.created_at DESC LIMIT 100
        ");
        $daStmt->execute([$agentRow['id']]);
        $myDeliveryApplications = $daStmt->fetchAll();
    }
} catch (Exception $e) {}

// ── Marketplace Orders (as buyer) ─────────────────────────────────────────────
$myMarketplaceOrders = [];
try {
    $ordStmt = $pdo->prepare("
        SELECT mo.id, mo.status, mo.total_amount, mo.payment_method, mo.created_at,
               ms.shop_name, ms.id AS shop_id
        FROM mp_orders mo
        JOIN mp_shops ms ON mo.shop_id = ms.id
        WHERE mo.customer_id = ?
        ORDER BY mo.created_at DESC LIMIT 100
    ");
    $ordStmt->execute([$user['id']]);
    $myMarketplaceOrders = $ordStmt->fetchAll();
} catch (Exception $e) {}

// ── Marketplace Products (as seller) ─────────────────────────────────────────
$myProducts = [];
try {
    $prodStmt = $pdo->prepare("
        SELECT mp.id, mp.name, mp.price, mp.discount_price, mp.status, mp.rejection_reason,
               mp.stock_quantity, mp.created_at,
               (SELECT image_path FROM mp_product_images WHERE product_id=mp.id AND is_primary=1 LIMIT 1) AS primary_image
        FROM mp_products mp
        JOIN mp_shops ms ON mp.shop_id = ms.id
        WHERE ms.user_id = ?
        ORDER BY mp.created_at DESC LIMIT 100
    ");
    $prodStmt->execute([$user['id']]);
    $myProducts = $prodStmt->fetchAll();
} catch (Exception $e) {}

$notificationCount = get_unread_notifications_count((int)$user['id']);

// ── Stats ─────────────────────────────────────────────────────────────────────
$jobAppStats = ['total'=>0,'under_review'=>0,'shortlisted'=>0,'offered'=>0,'approved'=>0,'hired'=>0,'rejected'=>0,'expired'=>0];
if (is_worker() && $myJobApps) {
    foreach ($myJobApps as $a) {
        $jobAppStats['total']++;
        $s = $a['app_status'];
        if (isset($jobAppStats[$s])) $jobAppStats[$s]++;
    }
}

$activeTab = $_GET['tab'] ?? 'jobs';

// Helper: status badge CSS class
function appBadgeClass(string $s): string {
    $map = [
        'pending'          => 'pending',
        'pending_approval' => 'pending',
        'approved'         => 'approved',
        'published'        => 'published',
        'active'           => 'active',
        'open'             => 'published',
        'accepted'         => 'approved',
        'assigned'         => 'approved',
        'applied'          => 'under_review',
        'under_review'     => 'under_review',
        'interview_scheduled' => 'under_review',
        'offered'          => 'shortlisted',
        'hired'            => 'approved',
        'position_filled'  => 'expired',
        'shortlisted'      => 'shortlisted',
        'rejected'         => 'rejected',
        'withdrawn'        => 'withdrawn',
        'completed'        => 'published',
        'delivered'        => 'published',
        'cancelled'        => 'cancelled',
        'expired'          => 'expired',
        'failed'           => 'rejected',
        'in_transit'       => 'under_review',
        'picked_up'        => 'under_review',
        'in_progress'      => 'under_review',
        'draft'            => 'draft',
        'out_of_stock'     => 'expired',
        'archived'         => 'expired',
    ];
    return $map[$s] ?? 'draft';
}
function appBadgeLabel(string $s): string {
    return strtoupper(str_replace('_', ' ', $s));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Applications — AkuapemConnect</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .tabs { display:flex; gap:0; border-bottom:2px solid var(--border,#e5e7eb); margin-bottom:20px; overflow-x:auto; scrollbar-width:none; }
        .tabs::-webkit-scrollbar { display:none; }
        .tab-btn { flex-shrink:0; padding:10px 14px; font-weight:600; font-size:.84rem; color:var(--text-muted,#6b7280); border:none; background:none; cursor:pointer; border-bottom:3px solid transparent; margin-bottom:-2px; white-space:nowrap; }
        .tab-btn.active { color:var(--primary,#0f766e); border-bottom-color:var(--primary,#0f766e); }
        .tab-panel { display:none; }
        .tab-panel.active { display:block; }

        /* Badges */
        .app-badge { display:inline-block; padding:2px 8px; border-radius:12px; font-size:.72rem; font-weight:700; }
        .app-badge.pending,.app-badge.under_review { background:#fef9c3; color:#a16207; }
        .app-badge.shortlisted { background:#ede9fe; color:#6d28d9; }
        .app-badge.approved,.app-badge.published,.app-badge.active { background:#d1fae5; color:#065f46; }
        .app-badge.rejected,.app-badge.failed { background:#fee2e2; color:#991b1b; }
        .app-badge.expired,.app-badge.withdrawn,.app-badge.cancelled,.app-badge.draft,.app-badge.archived { background:#f3f4f6; color:#6b7280; }

        /* Rejection alert */
        .rejection-box { background:#fee2e2; border-radius:8px; padding:8px 11px; margin-top:6px; font-size:.78rem; line-height:1.55; }
        .rejection-box strong { color:#c0392b; }

        /* Stat strip */
        .stat-strip { display:grid; grid-template-columns:repeat(auto-fit,minmax(80px,1fr)); gap:8px; margin-bottom:16px; }
        .stat-chip { background:var(--surface,#fff); border:1px solid var(--border,#e5e7eb); border-radius:10px; padding:10px 8px; text-align:center; }
        .stat-chip .n { font-size:1.35rem; font-weight:700; color:var(--primary,#0f766e); display:block; line-height:1.1; }
        .stat-chip .l { font-size:.68rem; color:var(--muted,#6b7280); margin-top:2px; }
        .stat-chip.blue   .n { color:#1d4ed8; }
        .stat-chip.purple .n { color:#6d28d9; }
        .stat-chip.green  .n { color:#16a34a; }
        .stat-chip.red    .n { color:#dc2626; }
        .stat-chip.orange .n { color:#ea580c; }
        .stat-chip.grey   .n { color:#6b7280; }

        /* App rows */
        .app-row { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; padding:14px 0; border-bottom:1px solid var(--border,#e5e7eb); flex-wrap:wrap; }
        .app-row:last-child { border-bottom:none; }
        .app-info { flex:1; min-width:0; }
        .app-info h3 { margin:0 0 3px; font-size:.92rem; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .app-info .meta { margin:0; font-size:.78rem; color:var(--muted,#6b7280); }
        .app-actions { display:flex; flex-direction:column; align-items:flex-end; gap:6px; flex-shrink:0; }

        /* Product thumbnail */
        .prod-thumb { width:44px; height:44px; border-radius:8px; object-fit:cover; background:#f8fafc; flex-shrink:0; border:1px solid var(--border); }

        /* Section label */
        .section-label { font-size:.76rem; font-weight:800; text-transform:uppercase; letter-spacing:.07em; color:var(--text-muted,#6b7280); margin:16px 0 8px; padding-bottom:4px; border-bottom:1px solid var(--border); }
    </style>
</head>
<body class="has-bottom-nav">

<header class="app-topbar">
    <span class="brand"><span class="brand-icon">📋</span> My Applications</span>
</header>

<main class="page-shell">

    <!-- ── Tabs ── -->
    <div class="tabs">
        <?php
        $dlCount  = count($myDeliveryRequests) + count($myDeliveryApplications);
        $mpCount  = count($myMarketplaceOrders) + count($myProducts);
        $tabs = [
            'jobs'      => ['label'=>'💼 Jobs',      'count'=>count($myJobApps) + count($myPostedJobs)],
            'delivery'  => ['label'=>'🚚 Delivery',   'count'=>$dlCount],
            'market'    => ['label'=>'🛍️ Marketplace','count'=>$mpCount],
            'events'    => ['label'=>'📅 Events',     'count'=>count($myEvents)],
            'funeral'   => ['label'=>'🕊️ Funerals',   'count'=>count($myFunerals)],
            'news'      => ['label'=>'📰 News',        'count'=>count($myNews)],
        ];
        foreach ($tabs as $key => $tab):
        ?>
        <button class="tab-btn <?php echo $activeTab===$key?'active':''; ?>" data-tab="<?php echo $key; ?>">
            <?php echo $tab['label']; ?>
            <?php if ($tab['count'] > 0): ?><span style="background:var(--primary-soft,#ccfbf1);color:var(--primary,#0f766e);border-radius:10px;padding:1px 6px;font-size:.72rem;margin-left:3px;"><?php echo $tab['count']; ?></span><?php endif; ?>
        </button>
        <?php endforeach; ?>
    </div>

    <!-- ══════════════════ JOB APPLICATIONS ══════════════════ -->
    <div class="tab-panel <?php echo $activeTab==='jobs'?'active':''; ?>" id="tab-jobs">

        <!-- Jobs I've posted (as customer) -->
        <?php if ($myPostedJobs): ?>
        <div class="section-label">My Posted Jobs</div>
        <div class="panel" style="margin-bottom:16px;">
            <?php foreach ($myPostedJobs as $pj): ?>
            <div class="app-row">
                <div class="app-info">
                    <h3><?php echo sanitize($pj['title']); ?></h3>
                    <p class="meta"><?php echo sanitize($pj['category_name'] ?? ''); ?><?php if ($pj['location']): ?> · <?php echo sanitize($pj['location']); ?><?php endif; ?> · <?php echo date('d M Y', strtotime($pj['created_at'])); ?></p>
                    <?php if ($pj['budget'] || $pj['budget_amount']): ?><p class="meta">GH₵ <?php echo sanitize($pj['budget'] ?: number_format((float)$pj['budget_amount'],2)); ?></p><?php endif; ?>
                    <?php if ($pj['status'] === 'rejected' && !empty($pj['rejection_reason'])): ?>
                    <div class="rejection-box"><strong>Rejected:</strong> <?php echo sanitize($pj['rejection_reason']); ?></div>
                    <?php endif; ?>
                </div>
                <div class="app-actions">
                    <span class="app-badge <?php echo appBadgeClass($pj['status']); ?>"><?php echo appBadgeLabel($pj['status']); ?></span>
                    <?php if (in_array($pj['status'], ['draft', 'rejected'], true)): ?>
                        <a href="request.php?edit=<?php echo (int)$pj['id']; ?>" class="button button-small">Edit</a>
                    <?php else: ?>
                        <a href="manage_applicants.php?id=<?php echo (int)$pj['id']; ?>" class="button button-small">👥 Applicants</a>
                        <a href="request_detail.php?id=<?php echo (int)$pj['id']; ?>" class="button button-small">View</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Job applications (as worker) -->
        <?php if (is_worker() && $myJobApps): ?>
            <div class="section-label">My Job Applications</div>
            <?php if ($jobAppStats['total'] > 0): ?>
            <div class="stat-strip">
                <div class="stat-chip"><span class="n"><?php echo $jobAppStats['total']; ?></span><div class="l">Total</div></div>
                <?php if ($jobAppStats['under_review']): ?><div class="stat-chip blue"><span class="n"><?php echo $jobAppStats['under_review']; ?></span><div class="l">In Review</div></div><?php endif; ?>
                <?php if ($jobAppStats['shortlisted']): ?><div class="stat-chip purple"><span class="n"><?php echo $jobAppStats['shortlisted']; ?></span><div class="l">Shortlisted</div></div><?php endif; ?>
                <?php $hired = $jobAppStats['offered']+$jobAppStats['approved']+$jobAppStats['hired']; if ($hired): ?><div class="stat-chip green"><span class="n"><?php echo $hired; ?></span><div class="l">Hired</div></div><?php endif; ?>
                <?php if ($jobAppStats['rejected']): ?><div class="stat-chip red"><span class="n"><?php echo $jobAppStats['rejected']; ?></span><div class="l">Rejected</div></div><?php endif; ?>
                <?php if ($jobAppStats['expired']): ?><div class="stat-chip grey"><span class="n"><?php echo $jobAppStats['expired']; ?></span><div class="l">Expired</div></div><?php endif; ?>
            </div>
            <?php endif; ?>
            <div class="panel">
            <?php
            $groupOrder  = ['offered','shortlisted','interview_scheduled','under_review','approved','hired','pending','rejected','position_filled','expired','withdrawn'];
            $groupLabels = ['offered'=>'🎉 Offered','shortlisted'=>'⭐ Shortlisted','interview_scheduled'=>'📅 Interview Scheduled','under_review'=>'🔍 Under Review','approved'=>'✅ Approved','hired'=>'🏆 Hired','pending'=>'⏳ Pending','rejected'=>'❌ Rejected','position_filled'=>'🔒 Position Filled','expired'=>'⌛ Expired','withdrawn'=>'↩ Withdrawn'];
            $groups = [];
            foreach ($myJobApps as $a) $groups[$a['app_status']][] = $a;
            foreach ($groupOrder as $s):
                if (empty($groups[$s])) continue;
            ?>
                <div class="section-label"><?php echo $groupLabels[$s] ?? ucfirst(str_replace('_',' ',$s)); ?></div>
                <?php foreach ($groups[$s] as $app): ?>
                <div class="app-row">
                    <div class="app-info">
                        <h3><?php echo sanitize($app['title']); ?></h3>
                        <p class="meta"><?php echo sanitize($app['category_name']); ?><?php if ($app['location']): ?> · <?php echo sanitize($app['location']); ?><?php endif; ?> · <?php echo date('d M Y', strtotime($app['applied_at'])); ?></p>
                        <?php if ($app['budget'] || $app['budget_amount']): ?><p class="meta">GH₵ <?php echo sanitize($app['budget'] ?: number_format((float)$app['budget_amount'],2)); ?></p><?php endif; ?>
                    </div>
                    <div class="app-actions">
                        <span class="app-badge <?php echo appBadgeClass($app['app_status']); ?>"><?php echo appBadgeLabel($app['app_status']); ?></span>
                        <a href="request_detail.php?id=<?php echo (int)$app['job_id']; ?>" class="button button-small">View Job</a>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!$myPostedJobs && !(is_worker() && $myJobApps)): ?>
            <div class="empty-state">
                <p>No job activity yet.</p>
                <div style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap;margin-top:10px;">
                    <a href="request.php" class="button button-primary">Post a Job</a>
                    <a href="jobs.php" class="button button-secondary">Browse Open Jobs</a>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- ══════════════════ DELIVERY ══════════════════ -->
    <div class="tab-panel <?php echo $activeTab==='delivery'?'active':''; ?>" id="tab-delivery">

        <!-- Delivery Requests (as customer) -->
        <?php if ($myDeliveryRequests): ?>
        <div class="section-label">My Delivery Requests</div>
        <?php
        $dlStatusOrder = ['pending_approval','approved','assigned','in_progress','picked_up','in_transit','delivered','cancelled','rejected','failed'];
        $dlGroups = [];
        foreach ($myDeliveryRequests as $d) $dlGroups[$d['status']][] = $d;
        foreach ($dlStatusOrder as $s):
            if (empty($dlGroups[$s])) continue;
        ?>
        <div class="panel" style="margin-bottom:10px;">
        <?php foreach ($dlGroups[$s] as $d): ?>
        <div class="app-row">
            <div class="app-info">
                <h3><?php echo sanitize(mb_substr($d['item_description'],0,60)).(mb_strlen($d['item_description'])>60?'…':''); ?></h3>
                <p class="meta">📍 <?php echo sanitize(mb_substr($d['pickup_location'],0,40)); ?> → <?php echo sanitize(mb_substr($d['dropoff_location'],0,40)); ?></p>
                <?php if ($d['preferred_date']): ?><p class="meta">📅 <?php echo date('d M Y', strtotime($d['preferred_date'])); ?></p><?php endif; ?>
                <?php if ($d['agent_name']): ?><p class="meta">🛵 Rider: <?php echo sanitize($d['agent_name']); ?></p><?php endif; ?>
                <?php if ($d['status'] === 'rejected' && !empty($d['rejection_reason'])): ?>
                <div class="rejection-box"><strong>Rejected:</strong> <?php echo sanitize($d['rejection_reason']); ?></div>
                <?php endif; ?>
            </div>
            <div class="app-actions">
                <span class="app-badge <?php echo appBadgeClass($d['status']); ?>"><?php echo appBadgeLabel($d['status']); ?></span>
                <?php if ($d['delivery_fee']): ?><span style="font-size:.76rem;color:var(--text-muted,#6b7280);">GH₵ <?php echo number_format((float)$d['delivery_fee'],2); ?></span><?php endif; ?>
                <a href="delivery_detail.php?id=<?php echo $d['id']; ?>" class="button button-small">View</a>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>

        <!-- Delivery Job Applications (as rider) -->
        <?php if ($myDeliveryApplications): ?>
        <div class="section-label">My Delivery Job Applications (Rider)</div>
        <?php
        $daOrder = ['assigned','applied','shortlisted','accepted','completed','rejected','withdrawn'];
        $daGroups = [];
        foreach ($myDeliveryApplications as $da) $daGroups[$da['status']][] = $da;
        foreach ($daOrder as $s):
            if (empty($daGroups[$s])) continue;
        ?>
        <div class="panel" style="margin-bottom:10px;">
        <?php foreach ($daGroups[$s] as $da): ?>
        <div class="app-row">
            <div class="app-info">
                <h3><?php echo sanitize(mb_substr($da['item_description'],0,60)).(mb_strlen($da['item_description'])>60?'…':''); ?></h3>
                <p class="meta">📍 <?php echo sanitize(mb_substr($da['pickup_location'],0,40)); ?> → <?php echo sanitize(mb_substr($da['dropoff_location'],0,40)); ?></p>
                <?php if ($da['offered_fee']): ?><p class="meta">Your offer: GH₵ <?php echo number_format((float)$da['offered_fee'],2); ?></p><?php endif; ?>
                <p class="meta">Applied <?php echo date('d M Y', strtotime($da['created_at'])); ?></p>
            </div>
            <div class="app-actions">
                <span class="app-badge <?php echo appBadgeClass($da['status']); ?>"><?php echo appBadgeLabel($da['status']); ?></span>
                <a href="delivery_detail.php?id=<?php echo $da['request_id']; ?>" class="button button-small">View</a>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>

        <?php if (!$myDeliveryRequests && !$myDeliveryApplications): ?>
        <div class="empty-state">
            <p>No delivery activity yet.</p>
            <div style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap;margin-top:10px;">
                <a href="delivery_request.php" class="button button-primary">Request a Delivery</a>
                <a href="become_delivery_agent.php" class="button button-secondary">Become a Rider</a>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- ══════════════════ MARKETPLACE ══════════════════ -->
    <div class="tab-panel <?php echo $activeTab==='market'?'active':''; ?>" id="tab-market">

        <!-- Orders (as buyer) -->
        <?php if ($myMarketplaceOrders): ?>
        <div class="section-label">My Orders</div>
        <?php
        $ordStatusOrder = ['pending','confirmed','processing','ready_for_delivery','in_transit','delivered','cancelled','refunded'];
        $ordGroups = [];
        foreach ($myMarketplaceOrders as $o) $ordGroups[$o['status']][] = $o;
        foreach ($ordStatusOrder as $s):
            if (empty($ordGroups[$s])) continue;
        ?>
        <div class="panel" style="margin-bottom:10px;">
        <?php foreach ($ordGroups[$s] as $o): ?>
        <div class="app-row">
            <div class="app-info">
                <h3>Order #<?php echo $o['id']; ?> — <?php echo sanitize($o['shop_name']); ?></h3>
                <p class="meta">GH₵ <?php echo number_format((float)$o['total_amount'],2); ?> · <?php echo ucwords(str_replace('_',' ',$o['payment_method'])); ?></p>
                <p class="meta"><?php echo date('d M Y', strtotime($o['created_at'])); ?></p>
            </div>
            <div class="app-actions">
                <span class="app-badge <?php echo appBadgeClass($o['status']); ?>"><?php echo appBadgeLabel($o['status']); ?></span>
                <a href="orders.php" class="button button-small">Orders</a>
                <a href="payment_receipt.php?type=marketplace_order&id=<?php echo $o['id']; ?>" class="button button-small">🧾 Receipt</a>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>

        <!-- Products (as seller) -->
        <?php if ($myProducts): ?>
        <div class="section-label">My Shop Products</div>
        <?php
        $prodStatusOrder = ['pending_approval','approved','rejected','out_of_stock','archived','draft'];
        $prodGroups = [];
        foreach ($myProducts as $p) $prodGroups[$p['status']][] = $p;
        $prodStatusLabel = ['pending_approval'=>'⏳ Pending Review','approved'=>'✅ Live','rejected'=>'❌ Rejected','out_of_stock'=>'📦 Out of Stock','archived'=>'🗄 Archived','draft'=>'📝 Draft'];
        foreach ($prodStatusOrder as $s):
            if (empty($prodGroups[$s])) continue;
        ?>
        <div class="section-label" style="font-size:.72rem;"><?php echo $prodStatusLabel[$s] ?? ucfirst($s); ?></div>
        <div class="panel" style="margin-bottom:10px;">
        <?php foreach ($prodGroups[$s] as $p): ?>
        <div class="app-row">
            <?php if ($p['primary_image']): ?>
            <img src="<?php echo sanitize($p['primary_image']); ?>" class="prod-thumb" alt="">
            <?php endif; ?>
            <div class="app-info">
                <h3><?php echo sanitize($p['name']); ?></h3>
                <p class="meta">GH₵ <?php echo number_format((float)$p['price'],2); ?> · Stock: <?php echo $p['stock_quantity']; ?></p>
                <?php if ($p['status'] === 'rejected' && !empty($p['rejection_reason'])): ?>
                <div class="rejection-box"><strong>Rejected:</strong> <?php echo sanitize($p['rejection_reason']); ?> — <a href="seller_product_form.php?id=<?php echo $p['id']; ?>" style="color:var(--primary,#0f766e);font-weight:700;">Edit &amp; Resubmit →</a></div>
                <?php endif; ?>
            </div>
            <div class="app-actions">
                <span class="app-badge <?php echo appBadgeClass($p['status']); ?>"><?php echo appBadgeLabel($p['status']); ?></span>
                <a href="product.php?id=<?php echo $p['id']; ?>" class="button button-small">View</a>
                <a href="seller_product_form.php?id=<?php echo $p['id']; ?>" class="button button-small">Edit</a>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>

        <?php if (!$myMarketplaceOrders && !$myProducts): ?>
        <div class="empty-state">
            <p>No marketplace activity yet.</p>
            <div style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap;margin-top:10px;">
                <a href="marketplace.php" class="button button-secondary">Browse Products</a>
                <a href="seller_dashboard.php" class="button button-primary">Open My Shop</a>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- ══════════════════ EVENTS ══════════════════ -->
    <div class="tab-panel <?php echo $activeTab==='events'?'active':''; ?>" id="tab-events">
        <div style="display:flex;justify-content:flex-end;margin-bottom:12px;">
            <a href="my_events.php" class="button button-primary button-small">+ New Event</a>
        </div>
        <?php if (!$myEvents): ?>
            <div class="empty-state">No event submissions yet.</div>
        <?php else: ?>
        <div class="panel">
        <?php foreach ($myEvents as $ev): ?>
        <div class="app-row">
            <div class="app-info">
                <h3><?php echo sanitize($ev['title']); ?></h3>
                <p class="meta"><?php echo sanitize($ev['venue']??''); ?><?php if ($ev['start_date']): ?><?php if ($ev['venue']): ?> · <?php endif; ?><?php echo date('d M Y', strtotime($ev['start_date'])); ?><?php endif; ?></p>
                <p class="meta">Submitted <?php echo date('d M Y', strtotime($ev['created_at'])); ?></p>
                <?php if ($ev['status'] === 'rejected' && !empty($ev['rejection_reason'])): ?>
                <div class="rejection-box"><strong>Rejected:</strong> <?php echo sanitize($ev['rejection_reason']); ?></div>
                <?php endif; ?>
            </div>
            <div class="app-actions">
                <span class="app-badge <?php echo appBadgeClass($ev['status']); ?>"><?php echo appBadgeLabel($ev['status']); ?></span>
                <a href="my_events.php" class="button button-small">Manage</a>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- ══════════════════ FUNERALS ══════════════════ -->
    <div class="tab-panel <?php echo $activeTab==='funeral'?'active':''; ?>" id="tab-funeral">
        <div style="display:flex;justify-content:flex-end;margin-bottom:12px;">
            <a href="my_funerals.php" class="button button-primary button-small">+ New Announcement</a>
        </div>
        <?php if (!$myFunerals): ?>
            <div class="empty-state">No funeral announcements yet.</div>
        <?php else: ?>
        <div class="panel">
        <?php foreach ($myFunerals as $fa): ?>
        <div class="app-row">
            <div class="app-info">
                <h3><?php echo sanitize($fa['deceased_name']); ?></h3>
                <p class="meta">Submitted <?php echo date('d M Y', strtotime($fa['created_at'])); ?><?php if ($fa['burial_date']): ?> · Burial: <?php echo date('d M Y', strtotime($fa['burial_date'])); ?><?php endif; ?></p>
                <?php if ($fa['status'] === 'rejected' && !empty($fa['rejection_reason'])): ?>
                <div class="rejection-box"><strong>Rejected:</strong> <?php echo sanitize($fa['rejection_reason']); ?></div>
                <?php endif; ?>
            </div>
            <div class="app-actions">
                <span class="app-badge <?php echo appBadgeClass($fa['status']); ?>"><?php echo appBadgeLabel($fa['status']); ?></span>
                <a href="my_funerals.php" class="button button-small">Manage</a>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- ══════════════════ NEWS ══════════════════ -->
    <div class="tab-panel <?php echo $activeTab==='news'?'active':''; ?>" id="tab-news">
        <div style="display:flex;justify-content:flex-end;margin-bottom:12px;">
            <a href="my_news.php" class="button button-primary button-small">+ New Article</a>
        </div>
        <?php if (!$myNews): ?>
            <div class="empty-state">No article submissions yet.</div>
        <?php else: ?>
        <div class="panel">
        <?php foreach ($myNews as $art): ?>
        <div class="app-row">
            <div class="app-info">
                <h3><?php echo sanitize($art['title']); ?></h3>
                <p class="meta">Submitted <?php echo date('d M Y', strtotime($art['created_at'])); ?></p>
                <?php if ($art['status'] === 'rejected' && !empty($art['rejection_reason'])): ?>
                <div class="rejection-box"><strong>Rejected:</strong> <?php echo sanitize($art['rejection_reason']); ?></div>
                <?php endif; ?>
            </div>
            <div class="app-actions">
                <span class="app-badge <?php echo appBadgeClass($art['status']); ?>"><?php echo appBadgeLabel($art['status']); ?></span>
                <a href="my_news.php" class="button button-small">Manage</a>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

</main>

<?php $activeNav = 'myapps'; require __DIR__ . '/partials/bottom_nav.php'; ?>

<script>
document.querySelectorAll('.tab-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var key = btn.getAttribute('data-tab');
        document.querySelectorAll('.tab-btn').forEach(function (b) { b.classList.remove('active'); });
        document.querySelectorAll('.tab-panel').forEach(function (p) { p.classList.remove('active'); });
        btn.classList.add('active');
        document.getElementById('tab-' + key).classList.add('active');
        history.replaceState(null, '', '?tab=' + key);
    });
});
</script>
</body>
</html>
