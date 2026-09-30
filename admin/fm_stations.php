<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../fm_functions.php';

require_login();
if (!is_admin_or_manager()) { header('Location: index.php'); exit; }
// Viewing the list is open to either permission (a programmes-only manager
// still needs to find their station and reach its schedule); every action
// below is station-level and re-checked strictly against manage_fm_stations.
if (!is_admin() && !has_mod_permission('manage_fm_stations') && !has_mod_permission('manage_fm_programmes')) {
    require_mod_permission('manage_fm_stations');
}
$canManageStations = is_admin() || has_mod_permission('manage_fm_stations');
$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    require_mod_permission('manage_fm_stations');

    $sid = (int)($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';
    if ($sid && $action) {
        $row = $pdo->prepare("SELECT * FROM fm_stations WHERE id=? LIMIT 1");
        $row->execute([$sid]);
        $row = $row->fetch();

        if ($row) {
            if ($action === 'toggle_status') {
                $new = $row['status'] === 'active' ? 'inactive' : 'active';
                $pdo->prepare("UPDATE fm_stations SET status=? WHERE id=?")->execute([$new, $sid]);
                log_audit_action($user['id'], 'fm_station_status', "Set station #{$sid} ({$row['name']}) to {$new}");
            } elseif ($action === 'toggle_featured') {
                $new = $row['featured'] ? 0 : 1;
                $pdo->prepare("UPDATE fm_stations SET featured=? WHERE id=?")->execute([$new, $sid]);
                log_audit_action($user['id'], 'fm_station_feature', "Toggled feature for station #{$sid} ({$row['name']})");
            } elseif ($action === 'set_live_mode') {
                $mode = in_array($_POST['live_mode'] ?? '', ['auto','live','offline'], true) ? $_POST['live_mode'] : 'auto';
                $pdo->prepare("UPDATE fm_stations SET live_mode=? WHERE id=?")->execute([$mode, $sid]);
                log_audit_action($user['id'], 'fm_station_live_mode', "Set station #{$sid} ({$row['name']}) live_mode to {$mode}");
            } elseif ($action === 'move_up' || $action === 'move_down') {
                $dir = $action === 'move_up' ? '<' : '>';
                $ord = $action === 'move_up' ? 'DESC' : 'ASC';
                $neighborStmt = $pdo->prepare("SELECT id, display_order FROM fm_stations WHERE display_order {$dir} ? ORDER BY display_order {$ord} LIMIT 1");
                $neighborStmt->execute([$row['display_order']]);
                $neighbor = $neighborStmt->fetch();
                if ($neighbor) {
                    $pdo->prepare("UPDATE fm_stations SET display_order=? WHERE id=?")->execute([$neighbor['display_order'], $sid]);
                    $pdo->prepare("UPDATE fm_stations SET display_order=? WHERE id=?")->execute([$row['display_order'], $neighbor['id']]);
                }
            } elseif ($action === 'delete') {
                $pdo->prepare("DELETE FROM fm_stations WHERE id=?")->execute([$sid]);
                log_audit_action($user['id'], 'fm_station_delete', "Deleted station #{$sid}: {$row['name']}");
            }
        }
    }
    header('Location: fm_stations.php'); exit;
}

$search = trim($_GET['q'] ?? '');
$where  = '1';
$params = [];
if ($search) { $where .= " AND (fs.name LIKE ? OR fs.frequency LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }

$stmt = $pdo->prepare(
    "SELECT fs.*, t.name AS town_name FROM fm_stations fs
     LEFT JOIN towns t ON t.id = fs.town_id
     WHERE $where
     ORDER BY fs.featured DESC, fs.display_order ASC, fs.name ASC"
);
$stmt->execute($params);
$stations = $stmt->fetchAll();

$streamTypeLabels = fm_stream_type_labels();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FM Stations — Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .fma-shell   { max-width:1100px; margin:0 auto; padding:20px 16px 60px; }
        .fma-toolbar { display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-bottom:16px; }
        .fma-toolbar form { display:flex; gap:6px; flex:1; min-width:200px; }
        .fma-toolbar input { flex:1; padding:8px 12px; border-radius:8px; border:1px solid var(--border); font-size:.85rem; }
        .fma-table   { width:100%; border-collapse:collapse; font-size:.85rem; }
        .fma-table th { background:var(--surface-muted,#f9fafb); padding:9px 12px; text-align:left; font-size:.75rem; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:var(--text-muted); border-bottom:1px solid var(--border); }
        .fma-table td { padding:10px 12px; border-bottom:1px solid var(--border,#e5e7eb); vertical-align:middle; }
        .fma-table tr:last-child td { border-bottom:none; }
        .fma-table tr:hover td { background:var(--surface-muted,#f9fafb); }
        .fma-logo   { width:40px; height:40px; border-radius:8px; object-fit:cover; }
        .fma-logo-init { width:40px; height:40px; border-radius:8px; background:#f3f4f6; display:flex; align-items:center; justify-content:center; font-weight:900; font-size:1.1rem; color:#9ca3af; }
        .fma-badge  { display:inline-block; font-size:.65rem; font-weight:800; padding:2px 8px; border-radius:20px; }
        .fma-badge-active   { background:#ecfdf5; color:#065f46; }
        .fma-badge-inactive { background:#f3f4f6; color:#6b7280; }
        .fma-badge-live     { background:#fee2e2; color:#991b1b; }
        .fma-badge-offline  { background:#f3f4f6; color:#6b7280; }
        .fma-actions { display:flex; gap:5px; flex-wrap:wrap; align-items:center; }
        .fma-select  { padding:4px 6px; border-radius:6px; border:1px solid var(--border); font-size:.75rem; }
    </style>
</head>
<body>
    <header class="topbar">
        <a href="index.php" class="button button-secondary button-small">← Admin</a>
        <h1>FM Stations</h1>
        <?php if ($canManageStations): ?><a href="fm_station_edit.php" class="button button-primary button-small">+ New Station</a><?php endif; ?>
    </header>

    <div class="fma-shell">
        <div class="fma-toolbar">
            <form method="get" action="fm_stations.php">
                <input type="search" name="q" placeholder="Search by name or frequency…" value="<?php echo sanitize($search); ?>">
                <button class="button button-small">Search</button>
            </form>
        </div>

        <div style="overflow-x:auto;background:var(--surface,#fff);border:1px solid var(--border);border-radius:12px;">
            <table class="fma-table">
                <thead>
                    <tr>
                        <th>Logo</th>
                        <th>Station</th>
                        <th>Town</th>
                        <th>Stream</th>
                        <th>Status</th>
                        <th>Live</th>
                        <th>Featured</th>
                        <th>Order</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stations as $s): $isLive = fm_station_is_live($s); ?>
                    <tr>
                        <td>
                            <?php if ($s['logo_path']): ?>
                            <img src="../<?php echo sanitize($s['logo_path']); ?>" class="fma-logo" alt="">
                            <?php else: ?>
                            <div class="fma-logo-init">📻</div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:<?php echo sanitize($s['theme_color'] ?: '#2f8f5b'); ?>;margin-right:6px;vertical-align:middle;"></span>
                            <strong><?php echo sanitize($s['name']); ?></strong>
                            <?php if ($s['frequency']): ?><br><small><?php echo sanitize($s['frequency']); ?></small><?php endif; ?>
                        </td>
                        <td style="font-size:.8rem;"><?php echo $s['town_name'] ? sanitize($s['town_name']) : '—'; ?></td>
                        <td style="font-size:.8rem;"><?php echo $s['stream_url'] ? sanitize($streamTypeLabels[$s['stream_type']] ?? $s['stream_type']) : '<em>None</em>'; ?></td>
                        <td><span class="fma-badge fma-badge-<?php echo $s['status']; ?>"><?php echo ucfirst($s['status']); ?></span></td>
                        <td>
                            <span class="fma-badge fma-badge-<?php echo $isLive ? 'live' : 'offline'; ?>"><?php echo $isLive ? '🔴 Live' : '⚪ Offline'; ?></span>
                            <?php if ($canManageStations): ?>
                            <form method="post" action="fm_stations.php" style="margin-top:4px;">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="id" value="<?php echo (int)$s['id']; ?>">
                                <input type="hidden" name="action" value="set_live_mode">
                                <select name="live_mode" class="fma-select" onchange="this.form.submit()">
                                    <option value="auto"    <?php echo $s['live_mode']==='auto'    ? 'selected':''; ?>>Auto-detect</option>
                                    <option value="live"    <?php echo $s['live_mode']==='live'    ? 'selected':''; ?>>Force Live</option>
                                    <option value="offline" <?php echo $s['live_mode']==='offline' ? 'selected':''; ?>>Force Offline</option>
                                </select>
                            </form>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:center;"><?php echo $s['featured'] ? '⭐' : '—'; ?></td>
                        <td style="text-align:center;"><?php echo (int)$s['display_order']; ?></td>
                        <td>
                            <div class="fma-actions">
                                <?php if ($canManageStations): ?>
                                <a href="fm_station_edit.php?id=<?php echo (int)$s['id']; ?>" class="button button-small button-primary">Edit</a>
                                <form method="post" action="fm_stations.php"><input type="hidden" name="id" value="<?php echo (int)$s['id']; ?>"><input type="hidden" name="action" value="move_up"><?php echo csrf_field(); ?><button class="button button-small">↑</button></form>
                                <form method="post" action="fm_stations.php"><input type="hidden" name="id" value="<?php echo (int)$s['id']; ?>"><input type="hidden" name="action" value="move_down"><?php echo csrf_field(); ?><button class="button button-small">↓</button></form>
                                <form method="post" action="fm_stations.php"><input type="hidden" name="id" value="<?php echo (int)$s['id']; ?>"><input type="hidden" name="action" value="toggle_featured"><?php echo csrf_field(); ?><button class="button button-small"><?php echo $s['featured'] ? 'Unfeature' : 'Feature'; ?></button></form>
                                <form method="post" action="fm_stations.php"><input type="hidden" name="id" value="<?php echo (int)$s['id']; ?>"><input type="hidden" name="action" value="toggle_status"><?php echo csrf_field(); ?><button class="button button-small" style="<?php echo $s['status']==='active' ? 'background:#fef3c7;color:#92400e;border-color:#f59e0b;' : 'background:#ecfdf5;color:#065f46;border-color:#6ee7b7;'; ?>"><?php echo $s['status']==='active' ? 'Deactivate' : 'Activate'; ?></button></form>
                                <form method="post" action="fm_stations.php" onsubmit="return confirm('Delete this station and all its programmes?')"><input type="hidden" name="id" value="<?php echo (int)$s['id']; ?>"><input type="hidden" name="action" value="delete"><?php echo csrf_field(); ?><button class="button button-small" style="background:#fee2e2;color:#991b1b;border-color:#fca5a5;">Delete</button></form>
                                <?php else: ?>
                                <a href="fm_station_edit.php?id=<?php echo (int)$s['id']; ?>#schedule" class="button button-small button-primary">🗓️ Manage Programmes</a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$stations): ?>
                    <tr><td colspan="9" style="text-align:center;padding:32px;color:var(--text-muted);">No FM stations yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
