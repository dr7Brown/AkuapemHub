<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';

require_login();
if (!is_admin()) {
    flash('Only administrators can manage moderators.', 'error');
    header('Location: index.php');
    exit;
}

$adminUser = current_user();
$flash     = get_flash();
$tab       = $_GET['tab'] ?? 'moderators';

// ── POST actions ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $postAction = $_POST['action'] ?? '';
    $targetId   = (int)($_POST['user_id'] ?? 0);

    // Promote user to manager role
    if ($postAction === 'promote' && $targetId) {
        $row = $pdo->prepare('SELECT name, username, role FROM users WHERE id=?');
        $row->execute([$targetId]);
        $target = $row->fetch();
        if ($target && !in_array($target['role'], ['admin','manager'])) {
            $pdo->prepare("UPDATE users SET role='manager' WHERE id=?")->execute([$targetId]);
            log_audit_action($adminUser['id'], 'mod_promote', 'Promoted ' . $target['name'] . ' (#' . $targetId . ') to moderator');
            flash(sanitize($target['name']) . ' is now a moderator.', 'success');
        }
        header('Location: moderators.php?tab=moderators'); exit;
    }

    // Demote manager back to customer
    if ($postAction === 'demote' && $targetId) {
        $row = $pdo->prepare('SELECT name FROM users WHERE id=? AND role=?');
        $row->execute([$targetId, 'manager']);
        $target = $row->fetch();
        if ($target) {
            $pdo->prepare("UPDATE users SET role='customer' WHERE id=?")->execute([$targetId]);
            revoke_all_mod_permissions($targetId);
            log_audit_action($adminUser['id'], 'mod_demote', 'Demoted ' . $target['name'] . ' (#' . $targetId . ') from moderator, all permissions revoked');
            flash(sanitize($target['name']) . ' has been demoted and all permissions removed.', 'info');
        }
        header('Location: moderators.php?tab=moderators'); exit;
    }

    // Save permissions for a specific moderator
    if ($postAction === 'save_permissions' && $targetId) {
        $row = $pdo->prepare('SELECT name, role FROM users WHERE id=?');
        $row->execute([$targetId]);
        $target = $row->fetch();
        if ($target && $target['role'] === 'manager') {
            $validKeys   = all_mod_permission_keys();
            $submitted   = array_intersect($_POST['permissions'] ?? [], $validKeys);
            $current     = get_user_mod_permissions($targetId);
            $toGrant     = array_diff($submitted, $current);
            $toRevoke    = array_diff($current, $submitted);

            foreach ($toGrant  as $p) grant_mod_permission($targetId, $p, $adminUser['id']);
            foreach ($toRevoke as $p) revoke_mod_permission($targetId, $p);

            log_audit_action($adminUser['id'], 'mod_permissions_update',
                'Updated permissions for ' . $target['name'] . ' (#' . $targetId . '): granted=[' . implode(',', $toGrant) . '] revoked=[' . implode(',', $toRevoke) . ']');
            flash('Permissions updated for ' . sanitize($target['name']) . '.', 'success');
        }
        // Scroll back to this moderator's card
        header('Location: moderators.php?tab=moderators#mod-' . $targetId); exit;
    }
}

// ── Load data ─────────────────────────────────────────────────────────────────

// Current moderators
$modsStmt = $pdo->query(
    "SELECT u.id, u.name, u.username, u.email, u.profile_photo, u.created_at,
            COUNT(mp.permission) AS perm_count
     FROM users u
     LEFT JOIN moderator_permissions mp ON mp.user_id = u.id
     WHERE u.role = 'manager'
     GROUP BY u.id
     ORDER BY u.name ASC"
);
$moderators = $modsStmt->fetchAll();

// Promote search
$searchQuery = trim($_GET['search_user'] ?? '');
$foundUsers  = [];
if ($searchQuery && $tab === 'promote') {
    $st = $pdo->prepare(
        "SELECT id, name, username, email, role, profile_photo
         FROM users WHERE (name LIKE ? OR username LIKE ? OR email LIKE ?)
           AND role NOT IN ('admin','manager') AND banned=0 LIMIT 20"
    );
    $like = '%' . $searchQuery . '%';
    $st->execute([$like, $like, $like]);
    $foundUsers = $st->fetchAll();
}

// Audit log for moderators
$modAudit = [];
if ($tab === 'activity') {
    $modAudit = $pdo->query(
        "SELECT al.*, u.name AS admin_name
         FROM audit_logs al
         LEFT JOIN users u ON al.admin_id = u.id
         WHERE al.action LIKE 'mod_%'
         ORDER BY al.created_at DESC LIMIT 60"
    )->fetchAll();
}

$allPerms = all_mod_permissions();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Moderators — Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .mo-shell { max-width:1060px; margin:0 auto; padding:18px 16px 60px; }

        /* Stats */
        .mo-stats { display:flex; gap:12px; margin-bottom:20px; flex-wrap:wrap; }
        .mo-stat  { background:var(--surface); border:1px solid var(--border); border-radius:12px; padding:14px 18px; display:flex; align-items:center; gap:12px; flex:1; min-width:160px; }
        .mo-stat-icon { font-size:1.6rem; }
        .mo-stat strong { display:block; font-size:1.4rem; font-weight:900; color:var(--primary,#0f766e); line-height:1.1; }
        .mo-stat span   { font-size:.74rem; color:var(--text-muted,#6b7280); }

        /* Tabs */
        .mo-tabs { display:flex; gap:6px; flex-wrap:wrap; border-bottom:1px solid var(--border); padding-bottom:12px; margin-bottom:18px; }
        .mo-tab  { padding:7px 16px; border-radius:8px; font-size:.82rem; font-weight:700; text-decoration:none; background:var(--surface); border:1px solid var(--border); color:var(--text-muted,#6b7280); }
        .mo-tab.active { background:var(--primary-soft,#d1fae5); border-color:var(--primary,#0f766e); color:var(--primary,#0f766e); }

        /* Moderator cards */
        .mo-card { background:var(--surface); border:1px solid var(--border); border-radius:14px; margin-bottom:14px; overflow:hidden; }
        .mo-card-head { padding:14px 16px; display:flex; align-items:center; gap:12px; border-bottom:1px solid var(--border); }
        .mo-av { width:42px; height:42px; border-radius:50%; background:var(--primary-soft,#d1fae5); display:flex; align-items:center; justify-content:center; font-weight:800; color:var(--primary,#0f766e); flex-shrink:0; overflow:hidden; border:2px solid var(--border); }
        .mo-av img { width:100%; height:100%; object-fit:cover; }
        .mo-perm-body { padding:16px; }
        .mo-perm-group { margin-bottom:14px; }
        .mo-perm-group-title { font-size:.72rem; font-weight:800; text-transform:uppercase; letter-spacing:.06em; color:var(--text-muted,#6b7280); margin-bottom:8px; }
        .mo-perm-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(260px,1fr)); gap:6px; }
        .mo-perm-item { display:flex; align-items:center; gap:8px; font-size:.84rem; padding:5px 0; }
        .mo-perm-item input[type=checkbox] { width:16px; height:16px; flex-shrink:0; }
        .mo-perm-item label { cursor:pointer; line-height:1.3; }
        .mo-perm-on  { color:var(--primary,#0f766e); font-weight:600; }
        .mo-perm-off { color:var(--text-muted,#9ca3af); }

        /* Promote tab */
        .mo-user-row { background:var(--surface); border:1px solid var(--border); border-radius:10px; padding:12px 14px; margin-bottom:8px; display:flex; align-items:center; gap:12px; }

        /* Activity */
        .mo-log-row { padding:10px 0; border-bottom:1px solid var(--border); font-size:.84rem; }

        @media(max-width:520px){ .mo-perm-grid { grid-template-columns:1fr; } }
    </style>
</head>
<body>

<header class="topbar">
    <a href="index.php" class="button button-secondary button-small">← Dashboard</a>
    <h1 style="margin:0;font-size:1rem;font-weight:800;">👥 Moderator Management</h1>
    <a href="mod_performance.php" class="button button-secondary button-small">🏆 Performance →</a>
</header>

<main class="mo-shell">

    <?php if ($flash): ?>
    <div class="alert alert-<?php echo sanitize($flash['type']); ?>"><?php echo sanitize($flash['message']); ?></div>
    <?php endif; ?>

    <!-- Quick guide -->
    <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:12px;padding:14px 16px;margin-bottom:18px;font-size:.84rem;line-height:1.7;">
        <strong style="display:block;margin-bottom:4px;">How moderator access works:</strong>
        1. <strong>Promote</strong> a user to Moderator (+ Add Moderator tab) — this gives them admin dashboard access.<br>
        2. <strong>Check the permission boxes</strong> below for each action you want them to perform, then click <em>Save Permissions</em>.<br>
        3. The moderator can immediately log in and use their dashboard. They only see sections they have permission for.<br>
        ⚠️ A moderator with <strong>no permissions</strong> will see the dashboard but cannot do anything — make sure to assign at least one permission.
    </div>

    <!-- Stats -->
    <div class="mo-stats">
        <div class="mo-stat">
            <span class="mo-stat-icon">👥</span>
            <div><strong><?php echo count($moderators); ?></strong><span>Active Moderators</span></div>
        </div>
        <div class="mo-stat">
            <span class="mo-stat-icon">🔑</span>
            <div><strong><?php echo count(all_mod_permission_keys()); ?></strong><span>Available Permissions</span></div>
        </div>
        <div class="mo-stat">
            <span class="mo-stat-icon">📋</span>
            <div><strong><?php echo (int)$pdo->query("SELECT COUNT(*) FROM moderator_permissions")->fetchColumn(); ?></strong><span>Permissions Granted</span></div>
        </div>
    </div>

    <!-- Tabs -->
    <div class="mo-tabs">
        <a href="?tab=moderators" class="mo-tab <?php echo $tab==='moderators'?'active':''; ?>">Moderators</a>
        <a href="?tab=promote"    class="mo-tab <?php echo $tab==='promote'?'active':''; ?>">+ Add Moderator</a>
        <a href="?tab=activity"   class="mo-tab <?php echo $tab==='activity'?'active':''; ?>">Activity Log</a>
    </div>

    <!-- ═══ MODERATORS LIST ═══ -->
    <?php if ($tab === 'moderators'): ?>
    <?php if ($moderators): foreach ($moderators as $mod):
        $modPerms = get_user_mod_permissions($mod['id']);
    ?>
    <div class="mo-card" id="mod-<?php echo $mod['id']; ?>">
        <!-- Head -->
        <div class="mo-card-head">
            <div class="mo-av">
                <?php if (!empty($mod['profile_photo'])): ?>
                    <img src="<?php echo sanitize('../'.ltrim($mod['profile_photo'],'/')); ?>" alt="">
                <?php else: ?>
                    <?php echo strtoupper(substr(display_name(['name'=>$mod['name'],'username'=>$mod['username']]),0,1)); ?>
                <?php endif; ?>
            </div>
            <div style="flex:1;">
                <div style="font-weight:800;"><?php echo sanitize(display_name(['name'=>$mod['name'],'username'=>$mod['username']])); ?></div>
                <div style="font-size:.76rem;color:var(--text-muted,#6b7280);">
                    <?php echo sanitize($mod['email']); ?> &nbsp;·&nbsp;
                    <?php $totalPerms = count(all_mod_permission_keys()); ?>
                    <span style="<?php echo $mod['perm_count']===0?'color:#f59e0b;font-weight:700;':''; ?>">
                        <?php echo $mod['perm_count']; ?>/<?php echo $totalPerms; ?> permission<?php echo $mod['perm_count']!=1?'s':''; ?> granted
                    </span>
                    <?php if ($mod['perm_count']===0): ?>
                    &nbsp;<span style="background:#fef3c7;color:#b45309;font-size:.65rem;font-weight:800;padding:1px 6px;border-radius:8px;">NO ACCESS YET</span>
                    <?php endif; ?>
                    &nbsp;·&nbsp; Joined <?php echo time_ago($mod['created_at']); ?>
                </div>
            </div>
            <div style="display:flex;gap:6px;align-items:center;">
                <a href="mod_performance.php?tab=leaderboard&mod=<?php echo $mod['id']; ?>" class="button button-secondary button-small">🏆 Scorecard</a>
                <form method="post" style="margin:0;" onsubmit="return confirm('Demote this moderator? All their permissions will be removed.');">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action"  value="demote">
                    <input type="hidden" name="user_id" value="<?php echo $mod['id']; ?>">
                    <button type="submit" class="button button-small" style="background:#ef4444;color:#fff;border-color:transparent;">Remove Moderator</button>
                </form>
            </div>
        </div>

        <!-- Permissions form -->
        <form method="post" action="moderators.php?tab=moderators" class="mo-perm-body">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action"  value="save_permissions">
            <input type="hidden" name="user_id" value="<?php echo $mod['id']; ?>">

            <?php foreach ($allPerms as $groupName => $perms): ?>
            <?php $groupGranted = count(array_intersect(array_keys($perms), $modPerms)); ?>
            <div class="mo-perm-group">
                <div class="mo-perm-group-title" style="display:flex;align-items:center;justify-content:space-between;">
                    <span><?php echo sanitize($groupName); ?></span>
                    <span style="font-size:.68rem;font-weight:700;color:var(--text-muted,#6b7280);">
                        <?php echo $groupGranted; ?>/<?php echo count($perms); ?> granted
                    </span>
                </div>
                <div class="mo-perm-grid">
                    <?php foreach ($perms as $key => $label):
                        $hasIt = in_array($key, $modPerms, true);
                        $uid   = 'p_' . $mod['id'] . '_' . $key;
                    ?>
                    <div class="mo-perm-item">
                        <input type="checkbox" id="<?php echo $uid; ?>" name="permissions[]"
                               value="<?php echo $key; ?>" <?php echo $hasIt ? 'checked' : ''; ?>>
                        <label for="<?php echo $uid; ?>" class="<?php echo $hasIt ? 'mo-perm-on' : 'mo-perm-off'; ?>">
                            <?php echo sanitize($label); ?>
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>

            <div style="display:flex;gap:8px;align-items:center;margin-top:8px;flex-wrap:wrap;">
                <button type="submit" class="button button-primary button-small">Save Permissions</button>
                <button type="button" class="button button-secondary button-small"
                        onclick="toggleAll(this.closest('form'), true)">Grant All</button>
                <button type="button" class="button button-secondary button-small"
                        onclick="toggleAll(this.closest('form'), false)">Revoke All</button>
            </div>
        </form>
    </div>
    <?php endforeach; else: ?>
    <div style="text-align:center;padding:48px 20px;color:var(--text-muted,#6b7280);">
        <div style="font-size:3rem;opacity:.4;margin-bottom:12px;">👥</div>
        <p style="margin:0 0 16px;">No moderators yet. Add one from the <strong>+ Add Moderator</strong> tab.</p>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <!-- ═══ PROMOTE ═══ -->
    <?php if ($tab === 'promote'): ?>
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:18px;margin-bottom:16px;">
        <p style="font-size:.75rem;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted,#6b7280);margin:0 0 12px;">Search for a User to Promote</p>
        <form method="get" action="moderators.php" style="display:flex;gap:8px;">
            <input type="hidden" name="tab" value="promote">
            <input type="text" name="search_user" value="<?php echo sanitize($searchQuery); ?>"
                   placeholder="Search by name, username, or email…" style="flex:1;max-width:380px;">
            <button type="submit" class="button button-primary button-small">Search</button>
        </form>
    </div>

    <?php if ($foundUsers): foreach ($foundUsers as $u): ?>
    <div class="mo-user-row">
        <div class="mo-av">
            <?php if (!empty($u['profile_photo'])): ?><img src="<?php echo sanitize('../'.ltrim($u['profile_photo'],'/')); ?>" alt=""><?php else: ?><?php echo strtoupper(substr(display_name(['name'=>$u['name'],'username'=>$u['username']]),0,1)); ?><?php endif; ?>
        </div>
        <div style="flex:1;">
            <div style="font-weight:700;"><?php echo sanitize(display_name(['name'=>$u['name'],'username'=>$u['username']])); ?></div>
            <div style="font-size:.76rem;color:var(--text-muted,#6b7280);"><?php echo sanitize($u['email']); ?> &nbsp;·&nbsp; <?php echo ucfirst($u['role']); ?></div>
        </div>
        <form method="post" style="margin:0;">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action"  value="promote">
            <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
            <button type="submit" class="button button-primary button-small">Promote to Moderator</button>
        </form>
    </div>
    <?php endforeach; elseif ($searchQuery): ?>
    <div class="empty-state">No users found matching "<?php echo sanitize($searchQuery); ?>".</div>
    <?php else: ?>
    <div style="text-align:center;padding:32px;color:var(--text-muted,#6b7280);font-size:.86rem;">Search for a user above to promote them to moderator. Only non-admin accounts are shown.</div>
    <?php endif; ?>
    <?php endif; ?>

    <!-- ═══ ACTIVITY LOG ═══ -->
    <?php if ($tab === 'activity'): ?>
    <p style="font-size:.75rem;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted,#6b7280);margin:0 0 12px;">Recent Moderator Actions</p>
    <?php if ($modAudit): ?>
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:0 16px;">
        <?php foreach ($modAudit as $log): ?>
        <div class="mo-log-row">
            <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:10px;flex-wrap:wrap;">
                <div>
                    <span style="background:var(--primary-soft,#d1fae5);color:var(--primary,#0f766e);font-size:.68rem;font-weight:800;padding:2px 7px;border-radius:10px;margin-right:6px;"><?php echo sanitize($log['action']); ?></span>
                    <?php echo sanitize($log['description']); ?>
                </div>
                <div style="font-size:.74rem;color:var(--text-muted,#6b7280);flex-shrink:0;">
                    by <?php echo sanitize($log['admin_name'] ?? 'System'); ?> · <?php echo time_ago($log['created_at']); ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="empty-state">No moderator activity recorded yet.</div>
    <?php endif; ?>
    <?php endif; ?>

</main>

<script>
function toggleAll(form, checked) {
    form.querySelectorAll('input[type=checkbox][name="permissions[]"]').forEach(function(cb) {
        cb.checked = checked;
        syncLabel(cb);
    });
}

function syncLabel(cb) {
    var label = document.querySelector('label[for="' + cb.id + '"]');
    if (label) label.className = cb.checked ? 'mo-perm-on' : 'mo-perm-off';
}

// Sync on change
document.addEventListener('change', function(e) {
    if (e.target.type === 'checkbox' && e.target.name === 'permissions[]') syncLabel(e.target);
});

// Scroll to anchor on load (after saving permissions)
if (window.location.hash) {
    var el = document.querySelector(window.location.hash);
    if (el) { setTimeout(function() { el.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 150); }
}

// Flash success highlight on the card that was just saved
if (window.location.hash && window.location.search.indexOf('saved') === -1) {
    var card = document.querySelector(window.location.hash + ' .mo-perm-body');
    if (card) {
        card.style.transition = 'background .4s';
        card.style.background = '#f0fdf4';
        setTimeout(function() { card.style.background = ''; }, 1500);
    }
}
</script>
</body>
</html>
