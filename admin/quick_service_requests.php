<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../quick_service_functions.php';

require_login();
if (!is_admin_or_manager()) { header('Location: index.php'); exit; }
require_mod_permission('manage_quick_service_requests');
$user = current_user();

$isAdmin = is_admin();
$managedIds = $isAdmin ? [] : get_managed_quick_service_ids($user['id']);
if (!$isAdmin && !$managedIds) {
    // A manager with the permission but no service assigned yet has nothing to see.
    $managedIds = [-1];
}

$statusFilter = in_array($_GET['status'] ?? '', ['awaiting_assignment','assigned','processing','completed','failed','cancelled']) ? $_GET['status'] : '';
$search = trim($_GET['q'] ?? '');

$where = ['1=1'];
$params = [];
if (!$isAdmin) { $where[] = 'qt.service_id IN (' . implode(',', array_map('intval', $managedIds)) . ')'; }
if ($statusFilter) { $where[] = 'qt.processing_status = ?'; $params[] = $statusFilter; }
if ($search) { $where[] = '(qt.reference LIKE ? OR qt.customer_name LIKE ? OR qt.customer_phone LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; }
$whereSql = implode(' AND ', $where);

$rows = $pdo->prepare(
    "SELECT qt.*, qs.name AS service_name, qs.icon AS service_icon, u.name AS customer_full_name, m.name AS manager_name
     FROM quick_transactions qt
     JOIN quick_services qs ON qs.id = qt.service_id
     JOIN users u ON u.id = qt.user_id
     LEFT JOIN users m ON m.id = qt.assigned_manager_id
     WHERE {$whereSql} AND qt.payment_status = 'paid'
     ORDER BY FIELD(qt.processing_status,'awaiting_assignment','assigned','processing','completed','failed','cancelled'), qt.created_at DESC
     LIMIT 100"
);
$rows->execute($params);
$rows = $rows->fetchAll(PDO::FETCH_ASSOC);

$statsWhere = $isAdmin ? '1=1' : ('service_id IN (' . implode(',', array_map('intval', $managedIds)) . ')');
$stats = $pdo->query("SELECT processing_status, COUNT(*) AS n FROM quick_transactions WHERE payment_status='paid' AND {$statsWhere} GROUP BY processing_status")->fetchAll(PDO::FETCH_KEY_PAIR);
$statusLabels = ['awaiting_assignment' => 'Awaiting', 'assigned' => 'Assigned', 'processing' => 'Processing', 'completed' => 'Completed', 'failed' => 'Failed', 'cancelled' => 'Cancelled'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quick Services Requests — Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .qsr-shell  { max-width:960px; margin:0 auto; padding:20px 16px 60px; }
        .qsr-stats  { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:18px; }
        .qsr-stat   { background:var(--surface,#fff); border:1px solid var(--border); border-radius:10px; padding:10px 16px; text-align:center; min-width:90px; text-decoration:none; color:inherit; }
        .qsr-stat strong { display:block; font-size:1.2rem; }
        .qsr-stat span { font-size:.72rem; color:var(--text-muted); }
        .qsr-toolbar { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:16px; }
        .qsr-toolbar input, .qsr-toolbar select { padding:8px 12px; border-radius:8px; border:1px solid var(--border); font-size:.85rem; }
        .qsr-table  { width:100%; border-collapse:collapse; font-size:.85rem; }
        .qsr-table th { background:var(--surface-muted,#f9fafb); padding:9px 12px; text-align:left; font-size:.72rem; font-weight:800; text-transform:uppercase; color:var(--text-muted); border-bottom:1px solid var(--border); }
        .qsr-table td { padding:9px 12px; border-bottom:1px solid var(--border,#e5e7eb); }
        .qsr-badge  { font-size:.65rem; font-weight:800; padding:2px 8px; border-radius:20px; }
        .qsr-badge.pending { background:#fffbeb; color:#92400e; }
        .qsr-badge.completed { background:#ecfdf5; color:#065f46; }
        .qsr-badge.processing { background:#eff6ff; color:#1d4ed8; }
        .qsr-badge.failed, .qsr-badge.cancelled { background:#fee2e2; color:#991b1b; }
    </style>
</head>
<body>
    <header class="topbar">
        <a href="index.php" class="button button-secondary button-small">← Admin</a>
        <h1>Quick Services Requests</h1>
    </header>

    <div class="qsr-shell">
        <div class="qsr-stats">
            <?php foreach ($statusLabels as $k => $l): ?>
            <a href="quick_service_requests.php?status=<?php echo $k; ?>" class="qsr-stat"><strong><?php echo (int)($stats[$k] ?? 0); ?></strong><span><?php echo $l; ?></span></a>
            <?php endforeach; ?>
        </div>

        <div class="qsr-toolbar">
            <form method="get" style="display:flex;gap:8px;flex:1;">
                <?php if ($statusFilter): ?><input type="hidden" name="status" value="<?php echo sanitize($statusFilter); ?>"><?php endif; ?>
                <input type="search" name="q" placeholder="Search reference or customer…" value="<?php echo sanitize($search); ?>" style="flex:1;">
                <button class="button button-small">Search</button>
            </form>
            <select onchange="window.location='quick_service_requests.php?status='+this.value+'<?php echo $search ? '&q='.urlencode($search) : ''; ?>'">
                <option value="">All statuses</option>
                <?php foreach ($statusLabels as $k => $l): ?><option value="<?php echo $k; ?>" <?php echo $statusFilter===$k?'selected':''; ?>><?php echo $l; ?></option><?php endforeach; ?>
            </select>
        </div>

        <table class="qsr-table">
            <thead><tr><th>Reference</th><th>Service</th><th>Customer</th><th>Amount</th><th>Status</th><th>Manager</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($rows as $r): $badge = qs_processing_badge($r['processing_status']); ?>
                <tr>
                    <td><?php echo sanitize($r['reference']); ?></td>
                    <td><?php echo sanitize($r['service_icon']); ?> <?php echo sanitize($r['service_name']); ?></td>
                    <td><?php echo sanitize($r['customer_full_name']); ?></td>
                    <td>GH₵<?php echo number_format((float)$r['amount'], 2); ?></td>
                    <td><span class="qsr-badge <?php echo $badge[2]; ?>"><?php echo $badge[0]; ?> <?php echo $badge[1]; ?></span></td>
                    <td style="font-size:.78rem;color:var(--text-muted);"><?php echo $r['manager_name'] ? sanitize($r['manager_name']) : '—'; ?></td>
                    <td><a href="quick_service_process.php?id=<?php echo (int)$r['id']; ?>" class="button button-small button-primary">Manage</a></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?><tr><td colspan="7" style="text-align:center;padding:32px;color:var(--text-muted);">No paid requests found.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</body>
</html>
