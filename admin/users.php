<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';

require_login();
if (!is_admin_or_manager()) { header('Location: ../jobs.php'); exit; }
require_mod_permission('manage_users');

$adminUser = current_user();
$flash     = get_flash();

// ── POST: quick actions from list ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action  = $_POST['action'] ?? '';
    $userId  = (int)($_POST['user_id'] ?? 0);
    $bulkIds = array_filter(array_map('intval', $_POST['selected_users'] ?? []));

    // Manage Restrictions modal — whole-system ban and/or specific feature bans
    if ($action === 'set_ban' && $userId) {
        $targetRow = $pdo->prepare('SELECT name, role FROM users WHERE id=?');
        $targetRow->execute([$userId]);
        $target = $targetRow->fetch();

        if ($target && ($target['role'] !== 'admin' || is_admin())) {
            $wholeSystem = isset($_POST['whole_system']);
            $features    = (array)($_POST['features'] ?? []);
            $reason      = trim($_POST['reason'] ?? '');
            $result = apply_feature_ban_update($userId, $adminUser['id'], $wholeSystem, $features, $reason ?: null);

            if ($result['whole_changed']) log_mod_activity($adminUser['id'], 'users', $wholeSystem ? 'manage_users_ban' : 'manage_users_unban', $userId, $target['name']);

            $summary = [];
            if ($result['whole_changed']) $summary[] = $wholeSystem ? 'whole-system ban applied' : 'whole-system ban lifted';
            if ($result['granted']) $summary[] = count($result['granted']) . ' restriction(s) added';
            if ($result['revoked']) $summary[] = count($result['revoked']) . ' restriction(s) lifted';
            flash(sanitize($target['name']) . ': ' . ($summary ? implode(', ', $summary) : 'no changes') . '.', 'info');
        }
    }

    // Bulk actions
    if ($bulkIds && in_array($action, ['bulk_ban','bulk_unban','bulk_delete'])) {
        $ph = implode(',', array_fill(0, count($bulkIds), '?'));
        $targets = $pdo->prepare("SELECT id, name, role FROM users WHERE id IN ($ph) AND role != 'admin'")->execute($bulkIds) ? null : null;
        // Safer: process one by one
        foreach ($bulkIds as $bid) {
            $r = $pdo->prepare('SELECT name, role FROM users WHERE id=?');
            $r->execute([$bid]);
            $t = $r->fetch();
            if (!$t || $t['role'] === 'admin') continue;
            if ($action === 'bulk_ban')   $pdo->prepare('UPDATE users SET banned=1 WHERE id=?')->execute([$bid]);
            if ($action === 'bulk_unban') $pdo->prepare('UPDATE users SET banned=0 WHERE id=?')->execute([$bid]);
            if ($action === 'bulk_delete' && is_admin()) $pdo->prepare('DELETE FROM users WHERE id=? AND role!=?')->execute([$bid,'admin']);
        }
        log_audit_action($adminUser['id'], $action, 'Bulk action on ' . count($bulkIds) . ' user(s)');
        flash('Bulk action applied to ' . count($bulkIds) . ' user(s).', 'info');
    }

    header('Location: users.php?' . http_build_query(array_filter([
        'q'    => $_GET['q']    ?? '',
        'role' => $_GET['role'] ?? '',
        'status'=> $_GET['status']?? '',
    ])));
    exit;
}

// ── Filters ────────────────────────────────────────────────────────────────────
$q      = trim($_GET['q']      ?? '');
$role   = $_GET['role']        ?? '';
$status = $_GET['status']      ?? '';
$sort   = $_GET['sort']        ?? 'newest';
$page   = max(1, (int)($_GET['page'] ?? 1));
$perPage = 30;

$where  = ['1=1'];
$params = [];
if ($q !== '') {
    $where[] = '(u.name LIKE ? OR u.username LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)';
    $like = '%' . $q . '%';
    $params = array_merge($params, [$like,$like,$like,$like]);
}
if ($role && in_array($role,['customer','worker','manager','admin'],true)) {
    $where[] = 'u.role = ?'; $params[] = $role;
}
if ($status === 'banned')   { $where[] = 'u.banned = 1'; }
if ($status === 'active')   { $where[] = 'u.banned = 0'; }
if ($status === 'verified') { $where[] = 'u.email_verified = 1'; }
if ($status === 'unverified'){$where[] = 'u.email_verified = 0'; }

$orderBy = match($sort) {
    'name'   => 'u.name ASC',
    'oldest' => 'u.created_at ASC',
    default  => 'u.created_at DESC',
};

$whereClause = implode(' AND ', $where);

// ── CSV export — honors the current search/role/status filters ─────────────
if (isset($_GET['export']) && is_admin()) {
    csrf_check();
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="users_' . date('Y-m-d') . '.csv"');
    $exportSt = $pdo->prepare(
        "SELECT u.id, u.name, u.username, u.email, u.phone, u.role, u.banned,
                u.email_verified, u.created_at
         FROM users u WHERE $whereClause ORDER BY u.created_at DESC"
    );
    $exportSt->execute($params);
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID','Name','Username','Email','Phone','Role','Status','Email Verified','Registered']);
    foreach ($exportSt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        fputcsv($out, [
            $r['id'], csv_safe($r['name']), csv_safe($r['username']), $r['email'], $r['phone'],
            $r['role'], $r['banned'] ? 'Banned' : 'Active', $r['email_verified'] ? 'Yes' : 'No',
            $r['created_at'],
        ]);
    }
    fclose($out);
    log_audit_action($adminUser['id'], 'users_csv_export', 'Exported user list as CSV' . ($whereClause !== '1=1' ? ' (filtered)' : ''));
    exit;
}

$total = (int)$pdo->prepare("SELECT COUNT(*) FROM users u WHERE $whereClause")->execute($params) ?
    (function() use ($pdo, $whereClause, $params) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM users u WHERE $whereClause");
        $st->execute($params); return (int)$st->fetchColumn();
    })() : 0;

// re-run cleanly
$countSt = $pdo->prepare("SELECT COUNT(*) FROM users u WHERE $whereClause");
$countSt->execute($params);
$total = (int)$countSt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));
$offset = ($page - 1) * $perPage;

$usersSt = $pdo->prepare(
    "SELECT u.id, u.name, u.username, u.email, u.phone, u.role, u.banned,
            u.email_verified, u.profile_photo, u.created_at
     FROM users u WHERE $whereClause
     ORDER BY $orderBy LIMIT $perPage OFFSET $offset"
);
$usersSt->execute($params);
$users = $usersSt->fetchAll();

// Batched feature-ban lookup for the current page (avoids N+1 queries)
$userFeatureBans = [];
if ($users) {
    $ids = implode(',', array_column($users, 'id'));
    $fbStmt = $pdo->query("SELECT user_id, feature_key FROM user_feature_bans WHERE user_id IN ($ids)");
    foreach ($fbStmt->fetchAll() as $row) {
        $userFeatureBans[$row['user_id']][] = $row['feature_key'];
    }
}

// Stats
$stats = [
    'total'     => (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(),
    'active'    => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE banned=0")->fetchColumn(),
    'banned'    => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE banned=1")->fetchColumn(),
    'workers'   => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='worker'")->fetchColumn(),
    'managers'  => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='manager'")->fetchColumn(),
    'unverified'=> (int)$pdo->query("SELECT COUNT(*) FROM users WHERE email_verified=0 AND banned=0")->fetchColumn(),
];

$roleColors = ['admin'=>['#7c3aed','#ede9fe'],'manager'=>['#0891b2','#cffafe'],'worker'=>['#059669','#d1fae5'],'customer'=>['#6b7280','#f3f4f6']];
function roleChip(string $role, array $map): string {
    [$col,$bg] = $map[$role] ?? ['#6b7280','#f3f4f6'];
    return "<span style='background:$bg;color:$col;font-size:.68rem;font-weight:800;padding:2px 8px;border-radius:10px;'>".ucfirst($role)."</span>";
}

function pageUrl(array $extra = []): string {
    return 'users.php?' . http_build_query(array_merge(array_filter([
        'q'      => $_GET['q']      ?? '',
        'role'   => $_GET['role']   ?? '',
        'status' => $_GET['status'] ?? '',
        'sort'   => $_GET['sort']   ?? '',
    ]), $extra));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management — Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .um-shell { max-width:1200px; margin:0 auto; padding:18px 16px 60px; }

        /* Stats */
        .um-stats { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:18px; }
        .um-stat  { background:var(--surface); border:1px solid var(--border); border-radius:10px; padding:10px 16px; display:flex; align-items:center; gap:10px; cursor:pointer; text-decoration:none; color:inherit; flex:1; min-width:120px; transition:border-color .1s; }
        .um-stat:hover { border-color:var(--primary,#0f766e); }
        .um-stat.active { border-color:var(--primary,#0f766e); background:var(--primary-soft,#d1fae5); }
        .um-stat strong { display:block; font-size:1.4rem; font-weight:900; color:var(--primary,#0f766e); line-height:1.1; }
        .um-stat span   { font-size:.72rem; color:var(--text-muted,#6b7280); }

        /* Filters */
        .um-filters { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:14px; align-items:center; }
        .um-filters input, .um-filters select { padding:6px 10px; border-radius:8px; border:1px solid var(--border); font-size:.83rem; background:var(--surface); }
        .um-filters input { flex:1; min-width:200px; }

        /* Table */
        .um-table-wrap { background:var(--surface); border:1px solid var(--border); border-radius:14px; overflow:hidden; }
        .um-table { width:100%; border-collapse:collapse; }
        .um-table th { padding:10px 12px; font-size:.72rem; font-weight:800; text-transform:uppercase; letter-spacing:.06em; color:var(--text-muted,#6b7280); border-bottom:1px solid var(--border); text-align:left; background:var(--surface-muted,#f8fafc); white-space:nowrap; }
        .um-table td { padding:10px 12px; border-bottom:1px solid var(--border); font-size:.84rem; vertical-align:middle; }
        .um-table tr:last-child td { border-bottom:none; }
        .um-table tr:hover td { background:var(--surface-muted,#f8fafc); }
        .um-av { width:32px; height:32px; border-radius:50%; background:var(--primary-soft,#d1fae5); display:flex; align-items:center; justify-content:center; font-size:.78rem; font-weight:800; color:var(--primary,#0f766e); flex-shrink:0; overflow:hidden; border:1px solid var(--border); }
        .um-av img { width:100%; height:100%; object-fit:cover; }
        .um-badge-banned { background:#fee2e2; color:#c0392b; font-size:.65rem; font-weight:800; padding:1px 6px; border-radius:10px; }
        .um-badge-active { background:#d1fae5; color:#065f46; font-size:.65rem; font-weight:800; padding:1px 6px; border-radius:10px; }
        .um-badge-restricted { display:inline-block; margin-top:3px; background:#fef3c7; color:#92400e; font-size:.62rem; font-weight:800; padding:1px 6px; border-radius:10px; }

        /* Manage Restrictions modal */
        .um-ban-item { display:flex; align-items:center; gap:8px; padding:6px 0; font-size:.85rem; }
        .um-ban-item label { cursor:pointer; }

        /* Pagination */
        .um-pages { display:flex; gap:5px; justify-content:center; margin-top:16px; flex-wrap:wrap; }
        .um-page  { padding:5px 11px; border-radius:7px; border:1px solid var(--border); font-size:.8rem; font-weight:700; text-decoration:none; color:var(--text-muted,#6b7280); }
        .um-page.active { background:var(--primary,#0f766e); color:#fff; border-color:var(--primary,#0f766e); }
        .um-page:hover  { border-color:var(--primary,#0f766e); color:var(--primary,#0f766e); }

        /* Bulk bar */
        .um-bulk { display:flex; gap:8px; align-items:center; margin-bottom:10px; flex-wrap:wrap; }
        .um-select-all { cursor:pointer; }

        @media(max-width:700px){
            .um-table th:nth-child(4), .um-table td:nth-child(4),
            .um-table th:nth-child(5), .um-table td:nth-child(5) { display:none; }
        }
    </style>
</head>
<body>

<header class="topbar">
    <a href="index.php" class="button button-secondary button-small">← Dashboard</a>
    <h1 style="margin:0;font-size:1rem;font-weight:800;">👥 User Management</h1>
    <span style="font-size:.82rem;color:var(--text-muted,#6b7280);"><?php echo number_format($stats['total']); ?> total users</span>
</header>

<main class="um-shell">

    <?php if ($flash): ?>
    <div class="alert alert-<?php echo sanitize($flash['type']); ?>" style="margin-bottom:14px;"><?php echo sanitize($flash['message']); ?></div>
    <?php endif; ?>

    <!-- Stats chips -->
    <div class="um-stats">
        <a href="users.php" class="um-stat <?php echo !$status&&!$role?'active':''; ?>"><strong><?php echo number_format($stats['total']); ?></strong><span>All Users</span></a>
        <a href="users.php?status=active" class="um-stat <?php echo $status==='active'?'active':''; ?>"><strong><?php echo number_format($stats['active']); ?></strong><span>Active</span></a>
        <a href="users.php?status=banned" class="um-stat <?php echo $status==='banned'?'active':''; ?>"><strong style="color:#ef4444;"><?php echo number_format($stats['banned']); ?></strong><span>Banned</span></a>
        <a href="users.php?role=worker" class="um-stat <?php echo $role==='worker'?'active':''; ?>"><strong><?php echo number_format($stats['workers']); ?></strong><span>Workers</span></a>
        <a href="users.php?role=manager" class="um-stat <?php echo $role==='manager'?'active':''; ?>"><strong style="color:#0891b2;"><?php echo number_format($stats['managers']); ?></strong><span>Managers</span></a>
        <a href="users.php?status=unverified" class="um-stat <?php echo $status==='unverified'?'active':''; ?>"><strong style="color:#f59e0b;"><?php echo number_format($stats['unverified']); ?></strong><span>Unverified</span></a>
    </div>

    <!-- Filters -->
    <form method="get" action="users.php" class="um-filters">
        <input type="text" name="q" value="<?php echo sanitize($q); ?>" placeholder="Search name, username, email, phone…">
        <select name="role" onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()">
            <option value="">All Roles</option>
            <?php foreach (['customer'=>'Customer','worker'=>'Worker','manager'=>'Manager','admin'=>'Admin'] as $v=>$l): ?>
            <option value="<?php echo $v; ?>" <?php echo $role===$v?'selected':''; ?>><?php echo $l; ?></option>
            <?php endforeach; ?>
        </select>
        <select name="status" onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()">
            <option value="">All Statuses</option>
            <option value="active"    <?php echo $status==='active'?'selected':''; ?>>Active</option>
            <option value="banned"    <?php echo $status==='banned'?'selected':''; ?>>Banned</option>
            <option value="verified"  <?php echo $status==='verified'?'selected':''; ?>>Email Verified</option>
            <option value="unverified"<?php echo $status==='unverified'?'selected':''; ?>>Email Unverified</option>
        </select>
        <select name="sort" onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()">
            <option value="newest" <?php echo $sort==='newest'?'selected':''; ?>>Newest First</option>
            <option value="oldest" <?php echo $sort==='oldest'?'selected':''; ?>>Oldest First</option>
            <option value="name"   <?php echo $sort==='name'?'selected':''; ?>>A–Z Name</option>
        </select>
        <button type="submit" class="button button-primary button-small">Search</button>
        <?php if ($q||$role||$status): ?><a href="users.php" class="button button-secondary button-small">Clear</a><?php endif; ?>
        <?php if (is_admin()): ?><a href="?<?php echo http_build_query(array_filter(['q'=>$q,'role'=>$role,'status'=>$status])); ?>&export=1&csrf_token=<?php echo urlencode(csrf_token()); ?>" class="button button-secondary button-small">&#8595; Export CSV</a><?php endif; ?>
        <a href="users_print.php?<?php echo http_build_query(array_filter(['q'=>$q,'role'=>$role,'status'=>$status])); ?>" target="_blank" class="button button-secondary button-small">🖨 Print / PDF</a>
    </form>

    <form method="post" action="users.php" id="bulk-form">
        <?php echo csrf_field(); ?>

        <!-- Bulk action bar -->
        <div class="um-bulk">
            <label style="display:flex;align-items:center;gap:6px;font-size:.83rem;cursor:pointer;">
                <input type="checkbox" class="um-select-all" id="select-all"> Select all on page
            </label>
            <select name="action" id="bulk-action-sel" style="font-size:.82rem;padding:5px 8px;border:1px solid var(--border);border-radius:8px;">
                <option value="bulk_ban">Ban selected</option>
                <option value="bulk_unban">Unban selected</option>
                <?php if (is_admin()): ?><option value="bulk_delete" style="color:#ef4444;">Delete selected</option><?php endif; ?>
            </select>
            <button type="submit" class="button button-small" style="background:#ef4444;color:#fff;border-color:transparent;"
                    onclick="return confirmBulk()">Apply to Selected</button>
            <span style="font-size:.78rem;color:var(--text-muted,#6b7280);">
                Showing <?php echo number_format(count($users)); ?> of <?php echo number_format($total); ?> users
            </span>
        </div>

        <!-- Table -->
        <div class="um-table-wrap">
            <table class="um-table">
                <thead>
                    <tr>
                        <th style="width:36px;"></th>
                        <th>User</th>
                        <th>Email / Phone</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Joined</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($users): foreach ($users as $u): ?>
                    <tr>
                        <td>
                            <?php if ($u['role'] !== 'admin' || is_admin()): ?>
                            <input type="checkbox" name="selected_users[]" value="<?php echo $u['id']; ?>" class="row-cb">
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="display:flex;align-items:center;gap:8px;">
                                <div class="um-av">
                                    <?php if (!empty($u['profile_photo'])): ?>
                                        <img src="<?php echo sanitize('../'.ltrim($u['profile_photo'],'/')); ?>" alt="">
                                    <?php else: ?>
                                        <?php echo strtoupper(substr(display_name(['name'=>$u['name'],'username'=>$u['username']]),0,1)); ?>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <div style="font-weight:700;font-size:.85rem;"><?php echo sanitize($u['name']); ?></div>
                                    <?php if ($u['username']): ?><div style="font-size:.72rem;color:var(--text-muted,#6b7280);">@<?php echo sanitize($u['username']); ?></div><?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div style="font-size:.83rem;"><?php echo sanitize($u['email']); ?>
                                <?php if (!$u['email_verified']): ?><span style="font-size:.62rem;color:#f59e0b;font-weight:800;margin-left:4px;">unverified</span><?php endif; ?>
                            </div>
                            <?php if ($u['phone']): ?><div style="font-size:.75rem;color:var(--text-muted,#6b7280);"><?php echo sanitize($u['phone']); ?></div><?php endif; ?>
                        </td>
                        <td><?php echo roleChip($u['role'], $roleColors); ?></td>
                        <td>
                            <?php if ($u['banned']): ?>
                            <span class="um-badge-banned">Banned</span>
                            <?php else: ?>
                            <span class="um-badge-active">Active</span>
                            <?php endif; ?>
                            <?php $ufb = $userFeatureBans[$u['id']] ?? []; if ($ufb): ?>
                            <span class="um-badge-restricted"><?php echo count($ufb); ?> restricted</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:.78rem;color:var(--text-muted,#6b7280);white-space:nowrap;"><?php echo date('d M Y', strtotime($u['created_at'])); ?></td>
                        <td>
                            <div style="display:flex;gap:5px;flex-wrap:wrap;">
                                <a href="user_edit.php?id=<?php echo $u['id']; ?>" class="button button-primary button-small">Manage</a>
                                <?php if (is_admin() || has_mod_permission('view_reports')): ?>
                                <a href="user_statement.php?id=<?php echo $u['id']; ?>" class="button button-secondary button-small" title="Statement">📄</a>
                                <?php endif; ?>
                                <?php if ($u['role'] !== 'admin' || is_admin()): ?>
                                <button type="button" class="button button-small" style="<?php echo ($u['banned']||$ufb)?'background:#10b981;color:#fff;border-color:transparent;':'background:#ef4444;color:#fff;border-color:transparent;'; ?>"
                                        onclick="openBanModal(<?php echo $u['id']; ?>, '<?php echo sanitize(addslashes($u['name'])); ?>', <?php echo $u['banned']?1:0; ?>, '<?php echo implode(',', $ufb); ?>')">
                                    <?php echo ($u['banned']||$ufb)?'Manage Restrictions':'Ban'; ?>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; else: ?>
                    <tr><td colspan="7" style="text-align:center;padding:32px;color:var(--text-muted,#6b7280);">No users found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </form>

    <!-- Manage Restrictions modal -->
    <div id="banModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:999;align-items:center;justify-content:center;">
        <div style="background:var(--surface);border-radius:12px;padding:24px;width:400px;max-width:94vw;max-height:88vh;overflow-y:auto;">
            <h3 style="margin:0 0 16px;" id="banModalTitle">Manage Restrictions</h3>
            <form method="post" action="users.php">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="set_ban">
                <input type="hidden" name="user_id" id="banUserId">

                <div class="um-ban-item" style="border-bottom:1px solid var(--border);padding-bottom:8px;margin-bottom:6px;">
                    <input type="checkbox" name="whole_system" id="banWholeSystem">
                    <label for="banWholeSystem"><strong>Whole System</strong> — blocks login entirely</label>
                </div>

                <?php foreach (all_ban_features() as $fKey => $fLabel): ?>
                <div class="um-ban-item">
                    <input type="checkbox" name="features[]" value="<?php echo $fKey; ?>" class="ban-feature-cb" id="banFeature_<?php echo $fKey; ?>">
                    <label for="banFeature_<?php echo $fKey; ?>"><?php echo sanitize($fLabel); ?></label>
                </div>
                <?php endforeach; ?>

                <div style="margin:12px 0;">
                    <label style="font-size:.82rem;font-weight:700;">Reason (optional, shown to user)</label><br>
                    <textarea name="reason" id="banReason" rows="2" style="width:100%;margin-top:4px;padding:7px 10px;border:1px solid var(--border);border-radius:8px;font-size:.84rem;"></textarea>
                </div>

                <div style="display:flex;gap:8px;margin-top:14px;">
                    <button type="submit" class="button button-primary">Save</button>
                    <button type="button" class="button button-secondary" onclick="closeBanModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="um-pages">
        <?php if ($page > 1): ?><a href="<?php echo pageUrl(['page'=>$page-1]); ?>" class="um-page">← Prev</a><?php endif; ?>
        <?php for ($i=max(1,$page-2);$i<=min($totalPages,$page+2);$i++): ?>
        <a href="<?php echo pageUrl(['page'=>$i]); ?>" class="um-page <?php echo $i===$page?'active':''; ?>"><?php echo $i; ?></a>
        <?php endfor; ?>
        <?php if ($page<$totalPages): ?><a href="<?php echo pageUrl(['page'=>$page+1]); ?>" class="um-page">Next →</a><?php endif; ?>
    </div>
    <?php endif; ?>

</main>

<script>
// Select all checkboxes
document.getElementById('select-all').addEventListener('change', function() {
    document.querySelectorAll('.row-cb').forEach(cb => cb.checked = this.checked);
});

// Manage Restrictions modal
function openBanModal(userId, name, isBanned, featureCsv) {
    document.getElementById('banUserId').value = userId;
    document.getElementById('banModalTitle').textContent = 'Manage Restrictions: ' + name;
    document.getElementById('banWholeSystem').checked = isBanned == 1;
    document.getElementById('banReason').value = '';
    var active = featureCsv ? featureCsv.split(',') : [];
    document.querySelectorAll('.ban-feature-cb').forEach(function(cb) {
        cb.checked = active.indexOf(cb.value) !== -1;
    });
    document.getElementById('banModal').style.display = 'flex';
}
function closeBanModal() { document.getElementById('banModal').style.display = 'none'; }
document.getElementById('banModal').addEventListener('click', function(e) { if (e.target === this) closeBanModal(); });

function confirmBulk() {
    var sel = document.getElementById('bulk-action-sel').value;
    var checked = document.querySelectorAll('.row-cb:checked').length;
    if (!checked) { alert('Select at least one user.'); return false; }
    if (sel === 'bulk_delete') return confirm('Permanently delete ' + checked + ' user(s)? This cannot be undone.');
    return confirm('Apply "' + sel + '" to ' + checked + ' user(s)?');
}
</script>
</body>
</html>
