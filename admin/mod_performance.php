<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';

require_login();
if (!is_admin()) { header('Location: index.php'); exit; }

$adminUser = current_user();
$tab       = $_GET['tab'] ?? 'leaderboard';
$period    = $_GET['period'] ?? 'month';
$flash     = get_flash();

// ── POST: reward payout actions ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $postAction = $_POST['action'] ?? '';

    // Approve reward request
    if ($postAction === 'approve_reward' && !empty($_POST['reward_id'])) {
        $rid = (int)$_POST['reward_id'];
        $pdo->prepare("UPDATE mod_rewards SET status='approved', approved_by=? WHERE id=? AND status='pending'")
            ->execute([$adminUser['id'], $rid]);
        log_audit_action($adminUser['id'], 'mod_reward_approve', "Approved reward request #$rid");
        flash('Reward approved.', 'success');
    }

    // Mark paid
    if ($postAction === 'mark_paid_reward' && !empty($_POST['reward_id'])) {
        $rid = (int)$_POST['reward_id'];
        $pdo->prepare("UPDATE mod_rewards SET status='paid', paid_at=NOW() WHERE id=? AND status='approved'")
            ->execute([$rid]);
        log_audit_action($adminUser['id'], 'mod_reward_paid', "Marked reward #$rid as paid");
        flash('Reward marked as paid.', 'success');
    }

    // Reject reward request
    if ($postAction === 'reject_reward' && !empty($_POST['reward_id'])) {
        $rid = (int)$_POST['reward_id'];
        $pdo->prepare("UPDATE mod_rewards SET status='rejected' WHERE id=? AND status='pending'")
            ->execute([$rid]);
        log_audit_action($adminUser['id'], 'mod_reward_reject', "Rejected reward request #$rid");
        flash('Reward rejected.', 'info');
    }

    // Save point config
    if ($postAction === 'save_point_config') {
        foreach ($_POST['points'] ?? [] as $key => $value) {
            $pts = max(0, (float)$value);
            $pdo->prepare('UPDATE mod_point_config SET points=? WHERE action_key=?')->execute([$pts, $key]);
        }
        log_audit_action($adminUser['id'], 'mod_points_config_save', 'Updated moderator point configuration');
        flash('Point configuration saved.', 'success');
    }

    // Save settings
    if ($postAction === 'save_settings') {
        foreach (['mod_reward_100pts','mod_reward_500pts','mod_reward_1000pts','mod_flag_low_accuracy','mod_flag_inactivity_days','mod_flag_high_approval'] as $k) {
            if (isset($_POST[$k])) set_platform_setting($k, trim($_POST[$k]));
        }
        set_platform_setting('mod_perf_enabled', isset($_POST['mod_perf_enabled']) ? '1' : '0');
        flash('Settings saved.', 'success');
    }

    header('Location: mod_performance.php?tab=' . $tab . '&period=' . $period);
    exit;
}

// ── Load all managers with performance data ────────────────────────────────────
$managers = $pdo->query(
    "SELECT u.id, u.name, u.username, u.email, u.profile_photo,
            COUNT(mp.permission) AS perm_count
     FROM users u
     LEFT JOIN moderator_permissions mp ON mp.user_id = u.id
     WHERE u.role='manager'
     GROUP BY u.id ORDER BY u.name ASC"
)->fetchAll();

// Pre-load performance for each manager
$managerPerf = [];
foreach ($managers as $m) {
    $managerPerf[$m['id']] = get_mod_performance((int)$m['id'], $period);
}

// Sort by points for leaderboard
usort($managers, fn($a,$b) => ($managerPerf[$b['id']]['points'] ?? 0) <=> ($managerPerf[$a['id']]['points'] ?? 0));

// Platform-wide stats
$totalActions = 0; $totalPoints = 0;
foreach ($managerPerf as $p) { $totalActions += $p['total_actions']; $totalPoints += $p['points']; }

// Pending reward requests
$pendingRewards = $pdo->query(
    "SELECT mr.*, u.name AS mod_name, u.email AS mod_email
     FROM mod_rewards mr JOIN users u ON mr.mod_id=u.id
     WHERE mr.status IN('pending','approved')
     ORDER BY mr.created_at DESC LIMIT 40"
)->fetchAll();
$pendingRewardCount = count(array_filter($pendingRewards, fn($r) => $r['status']==='pending'));

// Activity breakdown
$activitySummary = [];
try {
    $dateFilter = match($period) {
        'today' => "AND DATE(created_at)=CURDATE()",
        'week'  => "AND created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)",
        'month' => "AND MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())",
        'year'  => "AND YEAR(created_at)=YEAR(NOW())",
        default => '',
    };
    $activitySummary = $pdo->query(
        "SELECT module, action_key, COUNT(*) AS cnt, SUM(points) AS total_pts
         FROM mod_activity_log WHERE 1=1 $dateFilter
         GROUP BY module, action_key ORDER BY cnt DESC LIMIT 20"
    )->fetchAll();
} catch (Exception $e) {}

// Point config
$pointConfig = [];
try {
    $pointConfig = $pdo->query("SELECT * FROM mod_point_config ORDER BY module, label")->fetchAll();
} catch (Exception $e) {}

// Settings
$cfg = [];
foreach (['mod_perf_enabled','mod_reward_100pts','mod_reward_500pts','mod_reward_1000pts','mod_flag_low_accuracy','mod_flag_inactivity_days','mod_flag_high_approval'] as $k) {
    $cfg[$k] = get_platform_setting($k, '');
}

$periodLabels = ['today'=>'Today','week'=>'This Week','month'=>'This Month','year'=>'This Year'];

// Rank badge
function rankBadge(int $rank): string {
    return match($rank) {
        1 => '🥇',  2 => '🥈',  3 => '🥉', default => "#$rank",
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Moderator Performance — Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .mp-shell { max-width:1100px; margin:0 auto; padding:18px 16px 60px; }
        /* Stats */
        .mp-stats { display:grid; grid-template-columns:repeat(auto-fill,minmax(130px,1fr)); gap:10px; margin-bottom:20px; }
        .mp-stat  { background:var(--surface); border:1px solid var(--border); border-radius:12px; padding:12px; text-align:center; }
        .mp-stat strong { display:block; font-size:1.5rem; font-weight:900; color:var(--primary,#0f766e); line-height:1.1; }
        .mp-stat span   { font-size:.7rem; color:var(--text-muted,#6b7280); }
        /* Tabs */
        .mp-tabs { display:flex; gap:5px; flex-wrap:wrap; border-bottom:1px solid var(--border); padding-bottom:12px; margin-bottom:18px; }
        .mp-tab  { padding:7px 16px; border-radius:8px; font-size:.82rem; font-weight:700; text-decoration:none; background:var(--surface); border:1px solid var(--border); color:var(--text-muted,#6b7280); }
        .mp-tab.active { background:var(--primary-soft,#d1fae5); border-color:var(--primary,#0f766e); color:var(--primary,#0f766e); }
        /* Period pills */
        .mp-periods { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:16px; }
        .mp-period  { padding:5px 12px; border-radius:20px; font-size:.78rem; font-weight:700; text-decoration:none; background:var(--surface); border:1px solid var(--border); color:var(--text-muted,#6b7280); }
        .mp-period.active { background:var(--primary,#0f766e); color:#fff; border-color:var(--primary,#0f766e); }
        /* Leaderboard card */
        .mp-lb-card { background:var(--surface); border:1px solid var(--border); border-radius:14px; padding:14px 16px; margin-bottom:10px; display:flex; align-items:center; gap:14px; transition:box-shadow .1s; }
        .mp-lb-card:hover { box-shadow:0 4px 16px rgba(0,0,0,.08); }
        .mp-lb-rank { font-size:1.4rem; font-weight:900; width:36px; text-align:center; flex-shrink:0; }
        .mp-lb-av   { width:42px; height:42px; border-radius:50%; background:var(--primary-soft,#d1fae5); display:flex; align-items:center; justify-content:center; font-weight:800; color:var(--primary,#0f766e); flex-shrink:0; overflow:hidden; border:2px solid var(--border); }
        .mp-lb-av img { width:100%; height:100%; object-fit:cover; }
        .mp-lb-info { flex:1; min-width:0; }
        .mp-lb-name { font-weight:800; font-size:.9rem; }
        .mp-lb-meta { font-size:.74rem; color:var(--text-muted,#6b7280); }
        .mp-lb-stats { display:flex; gap:14px; flex-shrink:0; flex-wrap:wrap; text-align:right; }
        .mp-lb-stat  { }
        .mp-lb-stat strong { display:block; font-size:1.1rem; font-weight:900; color:var(--primary,#0f766e); }
        .mp-lb-stat span   { font-size:.68rem; color:var(--text-muted,#6b7280); }
        /* Progress bar */
        .mp-bar-wrap { background:#f1f5f9; border-radius:6px; height:8px; overflow:hidden; }
        .mp-bar      { background:var(--primary,#0f766e); height:100%; border-radius:6px; }
        /* Flag badge */
        .mp-flag { background:#fef3c7; color:#b45309; font-size:.68rem; font-weight:800; padding:2px 7px; border-radius:10px; }
        /* Table */
        .mp-table { width:100%; border-collapse:collapse; font-size:.84rem; }
        .mp-table th { padding:9px 12px; text-align:left; font-size:.72rem; font-weight:800; text-transform:uppercase; letter-spacing:.05em; color:var(--text-muted,#6b7280); border-bottom:1px solid var(--border); background:var(--surface-muted,#f9fafb); }
        .mp-table td { padding:10px 12px; border-bottom:1px solid var(--border,#f1f5f9); vertical-align:middle; }
        .mp-table tr:last-child td { border-bottom:none; }
        /* Forms */
        label { font-weight:600; font-size:.86rem; display:block; margin-bottom:4px; }
        .form-group { margin-bottom:12px; }
        .mp-set-section { background:var(--surface); border:1px solid var(--border); border-radius:14px; padding:18px; margin-bottom:16px; }
        .mp-set-title { font-size:.74rem; font-weight:800; text-transform:uppercase; letter-spacing:.07em; color:var(--text-muted,#6b7280); margin:0 0 14px; }
        .mp-grid2 { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
        @media(max-width:520px){ .mp-stats { grid-template-columns:repeat(3,1fr); } .mp-grid2 { grid-template-columns:1fr; } }
    </style>
</head>
<body>

<header class="topbar">
    <a href="index.php" class="button button-secondary button-small">← Dashboard</a>
    <h1 style="margin:0;font-size:1rem;font-weight:800;">🏆 Moderator Performance</h1>
    <a href="moderators.php" class="button button-secondary button-small">Manage Roles</a>
</header>

<main class="mp-shell">

    <div style="display:flex;justify-content:flex-end;margin-bottom:14px;">
        <a href="moderators.php" class="button button-secondary button-small">Manage Roles</a>
    </div>

    <?php if ($flash): ?>
    <div class="alert alert-<?php echo sanitize($flash['type']); ?>" style="margin-bottom:14px;"><?php echo sanitize($flash['message']); ?></div>
    <?php endif; ?>

    <!-- Platform stats -->
    <div class="mp-stats">
        <div class="mp-stat"><strong><?php echo count($managers); ?></strong><span>Moderators</span></div>
        <div class="mp-stat"><strong><?php echo number_format($totalActions); ?></strong><span>Actions (<?php echo $periodLabels[$period]??$period; ?>)</span></div>
        <div class="mp-stat"><strong><?php echo number_format($totalPoints, 1); ?></strong><span>Points Earned</span></div>
        <div class="mp-stat"><strong style="color:#f59e0b;"><?php echo $pendingRewardCount; ?></strong><span>Pending Rewards</span></div>
    </div>

    <!-- Period selector -->
    <div class="mp-periods">
        <?php foreach ($periodLabels as $k=>$l): ?>
        <a href="?tab=<?php echo $tab; ?>&period=<?php echo $k; ?>" class="mp-period <?php echo $period===$k?'active':''; ?>"><?php echo $l; ?></a>
        <?php endforeach; ?>
    </div>

    <!-- Tabs -->
    <div class="mp-tabs">
        <a href="?tab=leaderboard&period=<?php echo $period; ?>" class="mp-tab <?php echo $tab==='leaderboard'?'active':''; ?>">🏆 Leaderboard</a>
        <a href="?tab=activity&period=<?php echo $period; ?>"    class="mp-tab <?php echo $tab==='activity'?'active':''; ?>">📋 Activity</a>
        <a href="?tab=rewards&period=<?php echo $period; ?>"     class="mp-tab <?php echo $tab==='rewards'?'active':''; ?>">
            💰 Rewards <?php if ($pendingRewardCount): ?><span style="background:#f59e0b;color:#fff;border-radius:10px;padding:0 6px;font-size:.65rem;margin-left:3px;"><?php echo $pendingRewardCount; ?></span><?php endif; ?>
        </a>
        <a href="?tab=points&period=<?php echo $period; ?>"      class="mp-tab <?php echo $tab==='points'?'active':''; ?>">⚡ Point Config</a>
        <a href="?tab=settings&period=<?php echo $period; ?>"    class="mp-tab <?php echo $tab==='settings'?'active':''; ?>">⚙️ Settings</a>
    </div>

    <!-- ═══ LEADERBOARD ═══ -->
    <?php if ($tab === 'leaderboard'): ?>
    <?php if ($managers): ?>
    <?php $maxPoints = max(1, max(array_map(fn($m)=>$managerPerf[$m['id']]['points']??0, $managers))); ?>
    <?php foreach ($managers as $rank => $m):
        $perf = $managerPerf[$m['id']];
        $flag = get_mod_flag($perf, $m['id']);
        $pts  = $perf['points'];
    ?>
    <div class="mp-lb-card">
        <div class="mp-lb-rank"><?php echo rankBadge($rank+1); ?></div>
        <div class="mp-lb-av">
            <?php if (!empty($m['profile_photo'])): ?><img src="<?php echo sanitize('../'.ltrim($m['profile_photo'],'/')); ?>" alt=""><?php else: ?><?php echo strtoupper(substr(display_name(['name'=>$m['name'],'username'=>$m['username']]),0,1)); ?><?php endif; ?>
        </div>
        <div class="mp-lb-info">
            <div class="mp-lb-name"><?php echo sanitize(display_name(['name'=>$m['name'],'username'=>$m['username']])); ?>
                <?php if ($flag): ?><span class="mp-flag" title="<?php echo sanitize($flag); ?>">⚠ Flagged</span><?php endif; ?>
            </div>
            <div class="mp-lb-meta"><?php echo sanitize($m['email']); ?> &nbsp;·&nbsp; <?php echo $m['perm_count']; ?> permissions</div>
            <?php if ($perf['total_actions'] > 0): ?>
            <div style="margin-top:6px;max-width:240px;">
                <div class="mp-bar-wrap"><div class="mp-bar" style="width:<?php echo min(100,round($pts/$maxPoints*100)); ?>%;"></div></div>
            </div>
            <?php endif; ?>
        </div>
        <div class="mp-lb-stats">
            <div class="mp-lb-stat"><strong><?php echo number_format($pts,1); ?></strong><span>Points</span></div>
            <div class="mp-lb-stat"><strong><?php echo number_format($perf['total_actions']); ?></strong><span>Actions</span></div>
            <div class="mp-lb-stat"><strong><?php echo $perf['approval_rate']; ?>%</strong><span>Approval rate</span></div>
            <div class="mp-lb-stat"><strong>GHS <?php echo number_format(mod_points_to_ghs((int)$perf['points_all']),2); ?></strong><span>Earned</span></div>
        </div>
        <div>
            <a href="?tab=leaderboard&period=<?php echo $period; ?>&mod=<?php echo $m['id']; ?>" class="button button-secondary button-small">Scorecard</a>
        </div>
    </div>

    <?php /* Individual scorecard */ ?>
    <?php if (isset($_GET['mod']) && (int)$_GET['mod'] === $m['id']): ?>
    <div style="background:var(--surface-muted,#f8fafc);border:1px solid var(--border);border-radius:12px;padding:16px;margin:-4px 0 10px 50px;">
        <p style="font-size:.76rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted,#6b7280);margin:0 0 12px;">Scorecard — <?php echo sanitize($m['name']); ?></p>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(100px,1fr));gap:10px;margin-bottom:14px;">
            <?php $ap=['total_actions'=>'Total Actions','approvals'=>'Approvals','rejections'=>'Rejections','approval_rate'=>'Approval %','point_balance'=>'Point Balance']; ?>
            <?php foreach ($ap as $k=>$lbl): ?>
            <div style="text-align:center;background:var(--surface);border-radius:8px;padding:8px;">
                <strong style="display:block;font-size:1.1rem;font-weight:900;color:var(--primary,#0f766e);"><?php echo is_float($perf[$k]) ? number_format($perf[$k],1) : number_format((int)$perf[$k]); ?><?php echo $k==='approval_rate'?'%':''; ?></strong>
                <span style="font-size:.68rem;color:var(--text-muted,#6b7280);"><?php echo $lbl; ?></span>
            </div>
            <?php endforeach; ?>
            <div style="text-align:center;background:var(--surface);border-radius:8px;padding:8px;">
                <strong style="display:block;font-size:1.1rem;font-weight:900;color:#8b5cf6;">GHS <?php echo number_format(mod_points_to_ghs((int)$perf['points_all']),2); ?></strong>
                <span style="font-size:.68rem;color:var(--text-muted,#6b7280);">Total Earned</span>
            </div>
        </div>
        <?php if ($flag): ?>
        <div style="background:#fef3c7;border-radius:8px;padding:8px 12px;font-size:.82rem;"><strong>⚠️ Flag:</strong> <?php echo sanitize($flag); ?></div>
        <?php endif; ?>
        <!-- Recent actions -->
        <?php try {
            $recent = $pdo->prepare("SELECT action_key, module, record_id, points, created_at FROM mod_activity_log WHERE mod_id=? ORDER BY created_at DESC LIMIT 8");
            $recent->execute([$m['id']]);
            $recentActs = $recent->fetchAll();
        } catch(Exception $e){ $recentActs=[]; } ?>
        <?php if ($recentActs): ?>
        <p style="font-size:.74rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted,#6b7280);margin:12px 0 6px;">Recent Actions</p>
        <?php foreach ($recentActs as $ra): ?>
        <div style="display:flex;justify-content:space-between;padding:4px 0;border-bottom:1px solid var(--border,#f1f5f9);font-size:.8rem;">
            <span><span style="background:var(--primary-soft,#d1fae5);color:var(--primary,#0f766e);font-size:.65rem;font-weight:800;padding:1px 5px;border-radius:6px;margin-right:4px;"><?php echo sanitize($ra['module']); ?></span><?php echo sanitize(ucwords(str_replace('_',' ',$ra['action_key']))); ?></span>
            <span style="color:var(--text-muted,#6b7280);"><?php echo number_format($ra['points'],1); ?> pts &nbsp;·&nbsp; <?php echo time_ago($ra['created_at']); ?></span>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php endforeach; ?>
    <?php else: ?><div class="empty-state">No moderators yet. <a href="moderators.php">Add moderators →</a></div><?php endif; ?>
    <?php endif; ?>

    <!-- ═══ ACTIVITY BREAKDOWN ═══ -->
    <?php if ($tab === 'activity'): ?>
    <?php if ($activitySummary): ?>
    <div style="overflow-x:auto;background:var(--surface);border:1px solid var(--border);border-radius:14px;">
    <table class="mp-table">
        <thead><tr><th>Module</th><th>Action</th><th>Count</th><th>Points Awarded</th></tr></thead>
        <tbody>
        <?php foreach ($activitySummary as $a): ?>
        <tr>
            <td><span style="background:var(--primary-soft,#d1fae5);color:var(--primary,#0f766e);font-size:.7rem;font-weight:800;padding:2px 7px;border-radius:8px;"><?php echo sanitize($a['module']); ?></span></td>
            <td><?php echo sanitize(ucwords(str_replace('_',' ',$a['action_key']))); ?></td>
            <td><strong><?php echo number_format((int)$a['cnt']); ?></strong></td>
            <td><?php echo number_format((float)$a['total_pts'],1); ?> pts</td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php else: ?><div class="empty-state">No activity recorded yet for this period.</div><?php endif; ?>
    <?php endif; ?>

    <!-- ═══ REWARDS ═══ -->
    <?php if ($tab === 'rewards'): ?>
    <?php if ($pendingRewards): ?>
    <div style="overflow-x:auto;background:var(--surface);border:1px solid var(--border);border-radius:14px;">
    <table class="mp-table">
        <thead><tr><th>Moderator</th><th>Type</th><th>Points</th><th>GHS Value</th><th>MoMo</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($pendingRewards as $r): ?>
        <tr>
            <td><strong><?php echo sanitize($r['mod_name']); ?></strong><br><span style="font-size:.74rem;color:var(--text-muted,#6b7280);"><?php echo sanitize($r['mod_email']); ?></span></td>
            <td><?php echo ucfirst($r['reward_type']); ?></td>
            <td><?php echo number_format((int)$r['points_used']); ?></td>
            <td><strong>GHS <?php echo number_format((float)$r['amount_ghs'],2); ?></strong></td>
            <td style="font-size:.8rem;"><?php echo sanitize($r['mobi_number']??'—'); ?></td>
            <td>
                <?php $sc=['pending'=>['#fef3c7','#b45309'],'approved'=>['#dbeafe','#1d4ed8'],'paid'=>['#d1fae5','#065f46'],'rejected'=>['#fee2e2','#c0392b']]; [$bg,$col]=$sc[$r['status']]??['#f3f4f6','#6b7280']; ?>
                <span style="background:<?php echo $bg; ?>;color:<?php echo $col; ?>;font-size:.7rem;font-weight:800;padding:2px 8px;border-radius:10px;"><?php echo ucfirst($r['status']); ?></span>
            </td>
            <td style="font-size:.78rem;color:var(--text-muted,#6b7280);"><?php echo date('d M Y', strtotime($r['created_at'])); ?></td>
            <td>
                <div style="display:flex;gap:5px;flex-wrap:wrap;">
                <?php if ($r['status']==='pending'): ?>
                <form method="post" style="margin:0;"><?php echo csrf_field(); ?><input type="hidden" name="action" value="approve_reward"><input type="hidden" name="reward_id" value="<?php echo $r['id']; ?>"><button type="submit" class="button button-primary button-small">Approve</button></form>
                <form method="post" style="margin:0;"><?php echo csrf_field(); ?><input type="hidden" name="action" value="reject_reward"><input type="hidden" name="reward_id" value="<?php echo $r['id']; ?>"><button type="submit" class="button button-small" style="background:#ef4444;color:#fff;border-color:transparent;" onclick="return confirm('Reject?');">Reject</button></form>
                <?php elseif ($r['status']==='approved'): ?>
                <form method="post" style="margin:0;"><?php echo csrf_field(); ?><input type="hidden" name="action" value="mark_paid_reward"><input type="hidden" name="reward_id" value="<?php echo $r['id']; ?>"><button type="submit" class="button button-primary button-small">Mark Paid</button></form>
                <?php endif; ?>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php else: ?><div class="empty-state">No reward requests yet.</div><?php endif; ?>
    <?php endif; ?>

    <!-- ═══ POINT CONFIG ═══ -->
    <?php if ($tab === 'points'): ?>
    <form method="post" action="mod_performance.php?tab=points&period=<?php echo $period; ?>">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="save_point_config">
        <?php
        $groups = [];
        foreach ($pointConfig as $pc) $groups[$pc['module']][] = $pc;
        foreach ($groups as $groupName => $items):
        ?>
        <div class="mp-set-section">
            <p class="mp-set-title">📦 <?php echo ucfirst($groupName); ?></p>
            <div class="mp-grid2">
            <?php foreach ($items as $pc): ?>
            <div class="form-group">
                <label><?php echo sanitize($pc['label']); ?></label>
                <div style="display:flex;align-items:center;gap:6px;">
                    <input type="number" name="points[<?php echo sanitize($pc['action_key']); ?>]"
                           value="<?php echo number_format((float)$pc['points'],2,'.',''); ?>"
                           min="0" step="0.5" style="width:90px;">
                    <span style="font-size:.8rem;color:var(--text-muted,#6b7280);">pts</span>
                </div>
            </div>
            <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if (!$pointConfig): ?><div class="empty-state">No point config yet. Run the v017 migration first.</div><?php endif; ?>
        <?php if ($pointConfig): ?><button type="submit" class="button button-primary">Save Point Configuration</button><?php endif; ?>
    </form>
    <?php endif; ?>

    <!-- ═══ SETTINGS ═══ -->
    <?php if ($tab === 'settings'): ?>
    <form method="post" action="mod_performance.php?tab=settings&period=<?php echo $period; ?>">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="save_settings">

        <div class="mp-set-section">
            <p class="mp-set-title">Module</p>
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                <input type="checkbox" name="mod_perf_enabled" value="1" <?php echo ($cfg['mod_perf_enabled']??'1')==='1'?'checked':''; ?>>
                Enable moderator performance & points tracking
            </label>
        </div>

        <div class="mp-set-section">
            <p class="mp-set-title">Reward Tiers (GHS per point tier)</p>
            <div class="mp-grid2">
                <?php foreach (['mod_reward_100pts'=>'GHS for 100 points','mod_reward_500pts'=>'GHS for 500 points','mod_reward_1000pts'=>'GHS for 1000 points'] as $k=>$l): ?>
                <div class="form-group">
                    <label><?php echo $l; ?></label>
                    <input type="number" name="<?php echo $k; ?>" min="0" step="0.01"
                           value="<?php echo sanitize($cfg[$k]??'0'); ?>">
                </div>
                <?php endforeach; ?>
            </div>
            <p style="font-size:.78rem;color:var(--text-muted,#6b7280);margin:0;">Example: if 100 pts = GHS 10, a mod with 350 points earns GHS 30 (3×100).</p>
        </div>

        <div class="mp-set-section">
            <p class="mp-set-title">Auto-Flagging Thresholds</p>
            <div class="mp-grid2">
                <div class="form-group">
                    <label>Flag if accuracy below (%)</label>
                    <input type="number" name="mod_flag_low_accuracy" min="0" max="100"
                           value="<?php echo sanitize($cfg['mod_flag_low_accuracy']??'65'); ?>">
                    <p class="form-hint" style="font-size:.74rem;color:var(--text-muted,#6b7280);margin-top:3px;">0 = disabled</p>
                </div>
                <div class="form-group">
                    <label>Flag if approval rate above (%)</label>
                    <input type="number" name="mod_flag_high_approval" min="0" max="100"
                           value="<?php echo sanitize($cfg['mod_flag_high_approval']??'95'); ?>">
                    <p class="form-hint" style="font-size:.74rem;color:var(--text-muted,#6b7280);margin-top:3px;">Catches rubber-stamping. 0 = disabled</p>
                </div>
                <div class="form-group">
                    <label>Flag if inactive for (days)</label>
                    <input type="number" name="mod_flag_inactivity_days" min="0"
                           value="<?php echo sanitize($cfg['mod_flag_inactivity_days']??'7'); ?>">
                    <p class="form-hint" style="font-size:.74rem;color:var(--text-muted,#6b7280);margin-top:3px;">0 = disabled</p>
                </div>
            </div>
        </div>

        <button type="submit" class="button button-primary">Save Settings</button>
    </form>
    <?php endif; ?>

</main>
</body>
</html>
