<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/delivery_functions.php';

require_module_enabled('delivery', 'Delivery Services');
require_login();
$user  = current_user();
$flash = get_flash();

// Load customer's approved delivery requests that have applications
$reqStmt = $pdo->prepare(
    "SELECT dr.id, dr.item_description, dr.item_category, dr.pickup_location,
            dr.dropoff_location, dr.delivery_fee, dr.status, dr.created_at,
            COUNT(da.id) AS app_count
     FROM delivery_requests dr
     LEFT JOIN delivery_applications da ON da.delivery_request_id = dr.id
                                         AND da.status NOT IN ('withdrawn','rejected')
     WHERE dr.customer_id = ? AND dr.status IN ('approved','assigned','accepted')
     GROUP BY dr.id
     ORDER BY dr.created_at DESC"
);
$reqStmt->execute([$user['id']]);
$myRequests = $reqStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rider Applications — AkuapemConnect</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .dap-shell { max-width:760px; margin:0 auto; padding:16px 16px 80px; }
        .dap-req   { background:var(--surface); border:1px solid var(--border); border-radius:14px; margin-bottom:16px; overflow:hidden; }
        .dap-req-head { padding:14px 16px; border-bottom:1px solid var(--border); display:flex; align-items:flex-start; justify-content:space-between; gap:10px; flex-wrap:wrap; cursor:pointer; }
        .dap-req-title { font-weight:800; font-size:.92rem; }
        .dap-req-meta  { font-size:.75rem; color:var(--text-muted,#6b7280); margin-top:2px; }
        .dap-badge { display:inline-block; padding:3px 9px; border-radius:20px; font-size:.7rem; font-weight:800; }
        .dap-apps  { display:none; padding:12px; }
        .dap-apps.open { display:block; }
        .dap-app-card { border:1px solid var(--border); border-radius:10px; padding:12px 14px; margin-bottom:8px; }
        .dap-app-head { display:flex; align-items:center; gap:10px; margin-bottom:8px; }
        .dap-av { width:36px; height:36px; border-radius:50%; background:var(--primary-soft,#d1fae5); display:flex; align-items:center; justify-content:center; font-weight:800; color:var(--primary,#0f766e); font-size:.88rem; flex-shrink:0; overflow:hidden; }
        .dap-av img { width:100%; height:100%; object-fit:cover; }
    </style>
</head>
<body class="has-bottom-nav">

<header class="app-topbar">
    <a href="delivery.php" class="button button-secondary button-small">← Back</a>
    <span class="brand">Rider Applications</span>
</header>

<?php if ($flash): ?>
<div class="alert alert-<?php echo sanitize($flash['type']); ?>" style="margin:10px 16px 0;">
    <?php echo sanitize($flash['message']); ?>
</div>
<?php endif; ?>

<main class="dap-shell">

    <?php if ($myRequests): ?>
    <?php foreach ($myRequests as $req): ?>
    <?php
    // Load applications for this request
    $appStmt = $pdo->prepare(
        'SELECT da.*, u.name AS agent_name, u.username AS agent_username,
                u.profile_photo AS agent_photo,
                dag.vehicle_type, dag.rating, dag.completed_deliveries,
                dag.is_verified, dag.is_premium, dag.premium_end,
                dag.is_sponsored, dag.sponsored_end
         FROM delivery_applications da
         JOIN delivery_agents dag ON da.agent_id = dag.id
         JOIN users u ON dag.user_id = u.id
         WHERE da.delivery_request_id = ?
         ORDER BY ' . agent_priority_sql('dag') . ' DESC, da.created_at ASC'
    );
    $appStmt->execute([$req['id']]);
    $apps = $appStmt->fetchAll();
    $isOpen = $req['app_count'] > 0;
    ?>
    <div class="dap-req">
        <div class="dap-req-head" onclick="toggleReq(<?php echo $req['id']; ?>)">
            <div>
                <div class="dap-req-title">
                    <?php echo item_category_icon($req['item_category']); ?>
                    <?php echo sanitize(mb_substr($req['item_description'],0,65)).(mb_strlen($req['item_description'])>65?'…':''); ?>
                </div>
                <div class="dap-req-meta">
                    #<?php echo $req['id']; ?> · <?php echo time_ago($req['created_at']); ?>
                    · 📍 <?php echo sanitize(mb_substr($req['pickup_location'],0,40)); ?>
                </div>
            </div>
            <div style="display:flex;align-items:center;gap:8px;">
                <?php if ($req['app_count'] > 0): ?>
                <span style="background:var(--primary,#0f766e);color:#fff;border-radius:12px;padding:3px 10px;font-size:.74rem;font-weight:800;">
                    <?php echo $req['app_count']; ?> applicant<?php echo $req['app_count']>1?'s':''; ?>
                </span>
                <?php else: ?>
                <span style="font-size:.78rem;color:var(--text-muted,#6b7280);">No applications yet</span>
                <?php endif; ?>
                <span class="dap-badge" style="background:<?php echo delivery_status_bg($req['status']); ?>;color:<?php echo delivery_status_color($req['status']); ?>;">
                    <?php echo delivery_status_label($req['status']); ?>
                </span>
                <span style="font-size:.9rem;color:var(--text-muted,#6b7280);" id="caret-<?php echo $req['id']; ?>">▾</span>
            </div>
        </div>

        <div class="dap-apps <?php echo $isOpen ? 'open' : ''; ?>" id="apps-<?php echo $req['id']; ?>">
            <?php if ($apps): ?>
            <?php foreach ($apps as $app): ?>
            <div class="dap-app-card">
                <div class="dap-app-head">
                    <div class="dap-av">
                        <?php if (!empty($app['agent_photo'])): ?>
                            <img src="<?php echo sanitize($app['agent_photo']); ?>" alt="">
                        <?php else: ?>
                            <?php echo strtoupper(substr(display_name(['name'=>$app['agent_name'],'username'=>$app['agent_username']]),0,1)); ?>
                        <?php endif; ?>
                    </div>
                    <div style="flex:1;">
                        <div style="font-weight:800;font-size:.9rem;"><?php echo sanitize(display_name(['name'=>$app['agent_name'],'username'=>$app['agent_username']])); ?></div>
                        <div style="font-size:.74rem;color:var(--text-muted,#6b7280);">
                            <?php echo vehicle_type_icon($app['vehicle_type']); ?> <?php echo vehicle_type_label($app['vehicle_type']); ?>
                            <?php if ($app['rating'] > 0): ?> · ⭐ <?php echo number_format((float)$app['rating'],1); ?><?php endif; ?>
                            <?php if ($app['completed_deliveries'] > 0): ?> · <?php echo $app['completed_deliveries']; ?> done<?php endif; ?>
                        </div>
                        <div style="margin-top:3px;"><?php echo agent_badges_html($app); ?></div>
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

                <?php if ($req['status'] === 'approved' && $app['status'] === 'applied'): ?>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <form method="post" action="delivery_ajax.php">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action"      value="select_rider">
                        <input type="hidden" name="delivery_id" value="<?php echo $req['id']; ?>">
                        <input type="hidden" name="app_id"      value="<?php echo $app['id']; ?>">
                        <button type="submit" class="button button-primary button-small">Select This Rider</button>
                    </form>
                    <a href="delivery_detail.php?id=<?php echo $req['id']; ?>" class="button button-secondary button-small">Full Details</a>
                </div>
                <?php elseif ($app['status'] === 'assigned'): ?>
                <span class="dap-badge" style="background:#d1fae5;color:#065f46;">✓ Selected</span>
                <?php else: ?>
                <span class="dap-badge" style="background:<?php echo delivery_status_bg($app['status']); ?>;color:<?php echo delivery_status_color($app['status']); ?>;">
                    <?php echo ucfirst($app['status']); ?>
                </span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <?php else: ?>
            <div style="text-align:center;padding:20px;color:var(--text-muted,#6b7280);font-size:.85rem;">
                No riders have applied yet. They'll be notified automatically.
            </div>
            <?php endif; ?>

            <div style="padding:8px 0 0;text-align:right;">
                <a href="delivery_detail.php?id=<?php echo $req['id']; ?>" style="font-size:.8rem;color:var(--primary,#0f766e);text-decoration:none;font-weight:700;">View Full Request →</a>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <?php else: ?>
    <div style="text-align:center;padding:48px 20px;color:var(--text-muted,#6b7280);">
        <div style="font-size:2.5rem;opacity:.4;margin-bottom:12px;">📋</div>
        <p style="margin:0 0 14px;">You don't have any approved delivery requests yet.</p>
        <a href="delivery_request.php" class="button button-primary">Create Delivery Request</a>
    </div>
    <?php endif; ?>

</main>

<?php require_once __DIR__ . '/partials/bottom_nav.php'; ?>

<script>
function toggleReq(id) {
    var el   = document.getElementById('apps-' + id);
    var cr   = document.getElementById('caret-' + id);
    var open = el.classList.contains('open');
    el.classList.toggle('open', !open);
    cr.textContent = open ? '▾' : '▴';
}
</script>
</body>
</html>
