<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/delivery_functions.php';

require_module_enabled('delivery', 'Delivery Services');

$user  = current_user();
$flash = get_flash();

// Agent profile for the logged-in user (if any)
$agentProfile = $user ? get_delivery_agent_for_user((int)$user['id']) : null;

// ── Per-user stats & deliveries ───────────────────────────────────────────────
$myDeliveries = [];
$stats = ['pending' => 0, 'active' => 0, 'completed' => 0];
$statusFilter = $_GET['status'] ?? 'all';

if ($user) {
    // Stats
    $sStmt = $pdo->prepare(
        'SELECT status, COUNT(*) AS cnt FROM delivery_requests
         WHERE customer_id = ? GROUP BY status'
    );
    $sStmt->execute([$user['id']]);
    foreach ($sStmt->fetchAll() as $row) {
        if ($row['status'] === 'pending') $stats['pending'] += $row['cnt'];
        elseif (in_array($row['status'], ['accepted','picked_up','in_transit'])) $stats['active'] += $row['cnt'];
        elseif ($row['status'] === 'delivered') $stats['completed'] += $row['cnt'];
    }

    // My deliveries with optional filter
    $validStatuses = ['pending','accepted','picked_up','in_transit','delivered','cancelled','failed'];
    $whereStatus = ($statusFilter !== 'all' && in_array($statusFilter, $validStatuses))
        ? 'AND dr.status = ' . $pdo->quote($statusFilter)
        : '';

    $dStmt = $pdo->prepare(
        "SELECT dr.*, da.vehicle_type,
                au.name AS agent_name, au.username AS agent_username, au.profile_photo AS agent_photo
         FROM delivery_requests dr
         LEFT JOIN delivery_agents da ON dr.agent_id = da.id
         LEFT JOIN users au ON da.user_id = au.id
         WHERE dr.customer_id = ? $whereStatus
         ORDER BY dr.created_at DESC LIMIT 20"
    );
    $dStmt->execute([$user['id']]);
    $myDeliveries = $dStmt->fetchAll();
}

// ── Available approved agents — ranked by tier ────────────────────────────────
$agentsStmt = $pdo->query(
    "SELECT da.*, u.name, u.username, u.profile_photo
     FROM delivery_agents da
     JOIN users u ON da.user_id = u.id
     WHERE da.verification_status = 'approved'
     ORDER BY
         " . agent_priority_sql('da') . " DESC,
         (da.availability_status = 'available') DESC,
         da.rating DESC,
         da.completed_deliveries DESC,
         da.created_at DESC
     LIMIT 8"
);
$availableAgents = $agentsStmt->fetchAll();

$activeNav = 'community';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delivery Services — AkuapemConnect</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        /* ── Stats row ── */
        .dl-stats { display:flex; gap:12px; padding:14px 16px; background:var(--surface); border-bottom:1px solid var(--border); flex-wrap:wrap; }
        .dl-stat  { flex:1; min-width:80px; text-align:center; }
        .dl-stat strong { display:block; font-size:1.5rem; font-weight:900; color:var(--primary,#0f766e); line-height:1.1; }
        .dl-stat span   { font-size:.72rem; color:var(--text-muted,#6b7280); }

        /* ── CTA bar ── */
        .dl-cta { display:flex; gap:10px; padding:14px 16px; flex-wrap:wrap; border-bottom:1px solid var(--border); background:var(--surface); }

        /* ── Section ── */
        .dl-section { padding:20px 16px; }
        .dl-section-head { display:flex; align-items:center; justify-content:space-between; margin-bottom:14px; }
        .dl-section-title { font-size:.75rem; font-weight:800; text-transform:uppercase; letter-spacing:.07em; color:var(--text-muted,#6b7280); margin:0; }

        /* ── Filter tabs ── */
        .dl-tabs { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:14px; }
        .dl-tab { padding:5px 12px; border-radius:20px; font-size:.78rem; font-weight:700; text-decoration:none; background:var(--surface); border:1px solid var(--border); color:var(--text-muted); transition:all .12s; }
        .dl-tab.active, .dl-tab:hover { background:var(--primary-soft,#d1fae5); border-color:var(--primary,#0f766e); color:var(--primary,#0f766e); }

        /* ── Delivery list card ── */
        .dl-card { background:var(--surface); border:2px solid var(--border); border-radius:14px; padding:14px 16px; text-decoration:none; color:inherit; display:block; margin-bottom:10px; transition:box-shadow .15s,border-color .15s; }
        .dl-card:hover { box-shadow:0 4px 18px rgba(0,0,0,.09); border-color:var(--primary,#0f766e); }
        .dl-card-head { display:flex; align-items:flex-start; justify-content:space-between; gap:10px; margin-bottom:10px; }
        .dl-card-title { font-weight:800; font-size:.92rem; line-height:1.4; }
        .dl-badge { display:inline-block; padding:3px 10px; border-radius:20px; font-size:.7rem; font-weight:800; letter-spacing:.03em; white-space:nowrap; }
        .dl-card-route { display:flex; flex-direction:column; gap:4px; font-size:.8rem; color:var(--text-muted,#6b7280); margin-bottom:8px; }
        .dl-card-route span { display:flex; align-items:flex-start; gap:6px; }
        .dl-card-foot { display:flex; align-items:center; justify-content:space-between; font-size:.75rem; color:var(--text-muted,#6b7280); border-top:1px solid var(--border); padding-top:8px; flex-wrap:wrap; gap:6px; }

        /* ── Agent cards ── */
        .dl-agents-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:12px; }
        .dl-agent-card { background:var(--surface); border:1px solid var(--border); border-radius:14px; padding:16px; text-align:center; text-decoration:none; color:inherit; display:flex; flex-direction:column; align-items:center; gap:8px; transition:box-shadow .15s,transform .15s; }
        .dl-agent-card:hover { box-shadow:0 6px 20px rgba(0,0,0,.1); transform:translateY(-2px); }
        .dl-agent-av { width:52px; height:52px; border-radius:50%; object-fit:cover; border:2px solid var(--border); background:var(--primary-soft,#d1fae5); display:flex; align-items:center; justify-content:center; font-size:1.3rem; font-weight:800; color:var(--primary,#0f766e); overflow:hidden; flex-shrink:0; }
        .dl-agent-av img { width:100%; height:100%; object-fit:cover; }
        .dl-agent-name { font-weight:800; font-size:.88rem; }
        .dl-agent-vehicle { font-size:.78rem; color:var(--text-muted,#6b7280); }
        .dl-agent-meta { display:flex; align-items:center; gap:8px; font-size:.74rem; flex-wrap:wrap; justify-content:center; }
        .dl-agent-status-dot { width:8px; height:8px; border-radius:50%; display:inline-block; }

        /* ── Stars ── */
        .dl-stars .dl-star-full  { color:#f59e0b; }
        .dl-stars .dl-star-empty { color:#e5e7eb; }
        .dl-star-val { font-size:.8rem; color:var(--text-muted,#6b7280); }

        /* ── Empty state ── */
        .dl-empty { text-align:center; padding:32px 20px; color:var(--text-muted,#6b7280); font-size:.88rem; background:var(--surface); border:1px solid var(--border); border-radius:14px; }
        .dl-empty-icon { font-size:2.5rem; margin-bottom:10px; opacity:.5; }

        /* ── Hero ── */
        .dl-hero { background:linear-gradient(135deg,rgba(15,118,110,.85) 0%,rgba(6,95,70,.9) 100%); color:#fff; padding:28px 20px; }
        .dl-hero h1 { font-size:1.5rem; font-weight:900; margin:0 0 6px; }
        .dl-hero p  { font-size:.88rem; color:#a7f3d0; margin:0; }

        @media(max-width:480px){
            .dl-agents-grid { grid-template-columns:repeat(2,1fr); }
        }
    </style>
</head>
<body class="<?php echo $user ? 'has-bottom-nav' : ''; ?>">

<!-- ── Header ── -->
<?php if (!$user): ?>
<header style="background:var(--surface);border-bottom:1px solid var(--border);padding:12px 16px;display:flex;align-items:center;justify-content:space-between;">
    <a href="index.php" style="font-weight:900;color:var(--primary,#0f766e);text-decoration:none;">← Back Home</a>
    <a href="login.php?redirect=<?php echo urlencode(current_request_path()); ?>" class="button button-secondary button-small">Sign in</a>
</header>
<?php else: ?>
<header class="app-topbar">
    <span class="brand"><span class="brand-icon">🚚</span> Delivery</span>
    <?php if ($agentProfile && $agentProfile['verification_status'] === 'approved'): ?>
        <a href="delivery_agent_jobs.php" class="button button-secondary button-small">Agent View</a>
    <?php endif; ?>
</header>
<?php endif; ?>

<!-- ── Flash ── -->
<?php if ($flash): ?>
<div class="alert alert-<?php echo sanitize($flash['type']); ?>" style="margin:10px 16px 0;">
    <?php echo sanitize($flash['message']); ?>
</div>
<?php endif; ?>

<!-- ── Hero ── -->
<div class="dl-hero">
    <h1>🚚 Delivery Services</h1>
    <p>Fast, reliable parcel &amp; item delivery across Akuapem</p>
</div>

<!-- ── Stats (logged-in) ── -->
<?php if ($user): ?>
<div class="dl-stats">
    <div class="dl-stat"><strong><?php echo $stats['pending']; ?></strong><span>Pending</span></div>
    <div class="dl-stat"><strong><?php echo $stats['active']; ?></strong><span>Active</span></div>
    <div class="dl-stat"><strong><?php echo $stats['completed']; ?></strong><span>Delivered</span></div>
</div>
<?php endif; ?>

<!-- ── CTA bar ── -->
<div class="dl-cta">
    <?php if ($user): ?>
        <a href="delivery_request.php" class="button button-primary">+ Request Delivery</a>
        <?php if (!$agentProfile): ?>
            <a href="become_delivery_agent.php" class="button button-secondary">Become an Agent</a>
        <?php elseif ($agentProfile['verification_status'] === 'pending'): ?>
            <span class="button button-secondary" style="cursor:default;opacity:.7;">Agent Application Pending…</span>
        <?php elseif ($agentProfile['verification_status'] === 'approved'): ?>
            <a href="delivery_agent_jobs.php" class="button button-secondary">My Agent Dashboard →</a>
        <?php endif; ?>
    <?php else: ?>
        <a href="login.php?redirect=<?php echo urlencode(current_request_path()); ?>" class="button button-primary">Sign in to Request Delivery</a>
        <a href="register.php" class="button button-secondary">Create Account</a>
    <?php endif; ?>
</div>

<div style="max-width:1060px;margin:0 auto;padding:0 0 20px;">

<!-- ── My Deliveries ── -->
<?php if ($user): ?>
<div class="dl-section">
    <div class="dl-section-head">
        <h2 class="dl-section-title">My Delivery Requests</h2>
        <?php if ($myDeliveries): ?>
            <a href="delivery_request.php" style="font-size:.82rem;font-weight:700;color:var(--primary,#0f766e);text-decoration:none;">+ New</a>
        <?php endif; ?>
    </div>

    <!-- Filter tabs -->
    <?php
    $tabs = ['all'=>'All','pending'=>'Pending','accepted'=>'Accepted','in_transit'=>'In Transit','delivered'=>'Delivered','cancelled'=>'Cancelled'];
    ?>
    <div class="dl-tabs">
        <?php foreach ($tabs as $val => $lbl): ?>
            <a href="delivery.php?status=<?php echo $val; ?>"
               class="dl-tab<?php echo $statusFilter === $val ? ' active' : ''; ?>">
               <?php echo sanitize($lbl); ?>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if ($myDeliveries): ?>
        <?php foreach ($myDeliveries as $d): ?>
        <a href="delivery_detail.php?id=<?php echo (int)$d['id']; ?>" class="dl-card">
            <div class="dl-card-head">
                <div>
                    <div class="dl-card-title">
                        <?php echo item_category_icon($d['item_category']); ?>
                        <?php echo sanitize(mb_substr($d['item_description'], 0, 60)) . (mb_strlen($d['item_description']) > 60 ? '…' : ''); ?>
                    </div>
                    <div style="font-size:.73rem;color:var(--text-muted,#6b7280);margin-top:2px;">
                        <?php echo item_category_label($d['item_category']); ?> · #<?php echo $d['id']; ?>
                    </div>
                </div>
                <span class="dl-badge" style="background:<?php echo delivery_status_bg($d['status']); ?>;color:<?php echo delivery_status_color($d['status']); ?>;">
                    <?php echo delivery_status_label($d['status']); ?>
                </span>
            </div>
            <div class="dl-card-route">
                <span>📍 <strong>From:</strong> <?php echo sanitize(mb_substr($d['pickup_location'], 0, 60)); ?></span>
                <span>🏁 <strong>To:</strong> <?php echo sanitize(mb_substr($d['dropoff_location'], 0, 60)); ?></span>
            </div>
            <div class="dl-card-foot">
                <span>🕐 <?php echo time_ago($d['created_at']); ?></span>
                <?php if ($d['agent_name']): ?>
                    <span><?php echo vehicle_type_icon($d['vehicle_type'] ?? ''); ?> <?php echo sanitize(display_name(['name'=>$d['agent_name'],'username'=>$d['agent_username']])); ?></span>
                <?php else: ?>
                    <span style="color:#f59e0b;">Awaiting agent</span>
                <?php endif; ?>
                <?php if ($d['delivery_fee'] > 0): ?>
                    <span>GH₵ <?php echo number_format((float)$d['delivery_fee'], 2); ?></span>
                <?php endif; ?>
            </div>
        </a>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="dl-empty">
            <div class="dl-empty-icon">📦</div>
            <p>No deliveries yet<?php echo $statusFilter !== 'all' ? ' with this status' : ''; ?>.</p>
            <a href="delivery_request.php" class="button button-primary" style="margin-top:10px;">Request your first delivery</a>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ── Available Agents ── -->
<div class="dl-section">
    <div class="dl-section-head">
        <h2 class="dl-section-title">Available Delivery Agents</h2>
        <?php if (!$user): ?><a href="register.php" style="font-size:.82rem;font-weight:700;color:var(--primary,#0f766e);text-decoration:none;">Become an Agent →</a><?php endif; ?>
    </div>
    <?php if ($availableAgents): ?>
    <div class="dl-agents-grid">
        <?php foreach ($availableAgents as $ag): ?>
        <?php $isSponsored = agent_is_sponsored($ag); ?>
        <div class="dl-agent-card" style="<?php echo $isSponsored ? 'border:2px solid #f59e0b;' : ''; ?>">
            <?php if ($isSponsored): ?>
            <div style="text-align:center;margin-bottom:4px;"><span style="background:#f59e0b;color:#fff;font-size:.62rem;font-weight:800;padding:2px 8px;border-radius:20px;">SPONSORED</span></div>
            <?php endif; ?>
            <div class="dl-agent-av">
                <?php if (!empty($ag['profile_photo'])): ?>
                    <img src="<?php echo sanitize($ag['profile_photo']); ?>" alt="">
                <?php else: ?>
                    <?php echo strtoupper(substr(display_name(['name'=>$ag['name'],'username'=>$ag['username']]), 0, 1)); ?>
                <?php endif; ?>
            </div>
            <div class="dl-agent-name"><?php echo sanitize(display_name(['name'=>$ag['name'],'username'=>$ag['username']])); ?></div>
            <!-- Tier badges -->
            <?php $badges = agent_badges_html($ag); if ($badges): ?>
            <div style="display:flex;gap:4px;flex-wrap:wrap;justify-content:center;"><?php echo $badges; ?></div>
            <?php endif; ?>
            <div class="dl-agent-vehicle"><?php echo vehicle_type_icon($ag['vehicle_type']); ?> <?php echo vehicle_type_label($ag['vehicle_type']); ?></div>
            <div class="dl-agent-meta">
                <?php $dotColor = $ag['availability_status'] === 'available' ? '#10b981' : ($ag['availability_status'] === 'busy' ? '#f59e0b' : '#9ca3af'); ?>
                <span><span class="dl-agent-status-dot" style="background:<?php echo $dotColor; ?>;"></span> <?php echo ucfirst($ag['availability_status']); ?></span>
                <?php if ($ag['completed_deliveries'] > 0): ?>
                <span>✅ <?php echo $ag['completed_deliveries']; ?> done</span>
                <?php endif; ?>
            </div>
            <?php if ($ag['rating'] > 0): ?>
                <div><?php echo render_stars((float)$ag['rating']); ?></div>
            <?php endif; ?>
            <?php if ($ag['service_area']): ?>
                <div style="font-size:.73rem;color:var(--text-muted,#6b7280);">📍 <?php echo sanitize(mb_substr($ag['service_area'], 0, 40)); ?></div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
        <div class="dl-empty">
            <div class="dl-empty-icon">🛵</div>
            <p>No approved agents yet. <a href="become_delivery_agent.php" style="color:var(--primary,#0f766e);">Be the first to register!</a></p>
        </div>
    <?php endif; ?>
</div>

<!-- ── Become an Agent CTA ── -->
<?php if ($user && !$agentProfile): ?>
<div class="dl-section" style="padding-top:0;">
    <div style="background:linear-gradient(135deg,#1e293b,#0f172a);color:#fff;border-radius:16px;padding:22px 20px;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;">
        <div>
            <h3 style="margin:0 0 4px;font-size:1rem;">Earn as a Delivery Agent</h3>
            <p style="margin:0;font-size:.85rem;color:#94a3b8;">Register your vehicle, get verified, and start accepting delivery jobs.</p>
        </div>
        <a href="become_delivery_agent.php" class="button button-primary">Register as Agent →</a>
    </div>
</div>
<?php endif; ?>

</div><!-- /max-width shell -->

<?php if ($user): require_once __DIR__ . '/partials/bottom_nav.php'; endif; ?>
</body>
</html>
