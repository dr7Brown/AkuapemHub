<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';

require_login();
if (!is_admin()) {
    header('Location: ../jobs.php');
    exit;
}

$user = current_user();
$success = '';
$error   = '';

// POST: delete entries
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'delete_entry' && !empty($_POST['log_id'])) {
        $logId = intval($_POST['log_id']);
        $pdo->prepare('DELETE FROM audit_logs WHERE id = ?')->execute([$logId]);
        log_audit_action($user['id'], 'audit_log_deleted', "Deleted audit log entry #{$logId}");
        $success = 'Log entry deleted.';

    } elseif ($action === 'delete_bulk' && !empty($_POST['selected_logs']) && is_array($_POST['selected_logs'])) {
        $ids = array_filter(array_map('intval', $_POST['selected_logs']));
        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("DELETE FROM audit_logs WHERE id IN ($placeholders)")->execute($ids);
            log_audit_action($user['id'], 'audit_log_bulk_deleted', 'Deleted ' . count($ids) . ' audit log entries');
            $success = count($ids) . ' log ' . (count($ids) === 1 ? 'entry' : 'entries') . ' deleted.';
        }

    } elseif ($action === 'delete_all') {
        $pdo->exec('DELETE FROM audit_logs');
        log_audit_action($user['id'], 'audit_log_cleared', 'All audit log entries cleared');
        $success = 'All audit logs cleared.';
    }
}

// Filters
$search    = trim($_GET['q'] ?? '');
$dateFrom  = trim($_GET['from'] ?? '');
$dateTo    = trim($_GET['to'] ?? '');
$page      = max(1, intval($_GET['page'] ?? 1));
$perPage   = 50;
$offset    = ($page - 1) * $perPage;

$where  = [];
$params = [];

if ($search !== '') {
    $where[]  = '(al.action LIKE ? OR al.description LIKE ? OR u.name LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}
if ($dateFrom !== '') {
    $where[]  = 'al.created_at >= ?';
    $params[] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
    $where[]  = 'al.created_at <= ?';
    $params[] = $dateTo . ' 23:59:59';
}

$whereClause = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs al LEFT JOIN users u ON al.admin_id = u.id $whereClause");
$countStmt->execute($params);
$totalRows = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $perPage));

$logStmt = $pdo->prepare(
    "SELECT al.id, al.action, al.description, al.created_at, u.name AS admin_name
     FROM audit_logs al
     LEFT JOIN users u ON al.admin_id = u.id
     $whereClause
     ORDER BY al.created_at DESC
     LIMIT $perPage OFFSET $offset"
);
$logStmt->execute($params);
$logs = $logStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Audit Logs — AkuapemHub Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css" />
</head>
<body>
    <header class="topbar">
        <a href="index.php" class="button button-secondary button-small">← Admin</a>
        <h1 style="flex:1;margin:0 16px;font-size:1.1rem;">Audit Logs</h1>
        <div class="topbar-actions">
            <a href="../logout.php" class="button button-secondary button-small">Logout</a>
        </div>
    </header>
    <main class="page-shell">

        <?php if ($success): ?>
            <div class="alert alert-success" style="margin-bottom:14px;"><?php echo sanitize($success); ?></div>
        <?php endif; ?>

        <!-- Filters -->
        <div class="panel" style="margin-bottom:14px;">
            <form method="get" class="filter-form">
                <input type="text" name="q" value="<?php echo sanitize($search); ?>" placeholder="Search action or description" />
                <input type="date" name="from" value="<?php echo sanitize($dateFrom); ?>" />
                <input type="date" name="to" value="<?php echo sanitize($dateTo); ?>" />
                <button type="submit" class="button button-primary">Filter</button>
                <?php if ($search || $dateFrom || $dateTo): ?>
                    <a href="audit_logs.php" class="button button-secondary">Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Bulk actions form -->
        <form method="post" id="bulk-form">
            <?php echo csrf_field(); ?>
            <div class="panel">
                <div class="panel-header">
                    <h2 style="margin:0;font-size:1rem;"><?php echo number_format($totalRows); ?> log <?php echo $totalRows === 1 ? 'entry' : 'entries'; ?></h2>
                    <div style="display:flex;gap:8px;align-items:center;">
                        <button type="submit" name="action" value="delete_bulk" class="button button-secondary button-small"
                                onclick="return confirmBulk()">Delete selected</button>
                        <button type="submit" name="action" value="delete_all" class="button button-secondary button-small"
                                onclick="return confirm('Delete ALL audit logs? This cannot be undone.')">Clear all</button>
                    </div>
                </div>

                <?php if (!$logs): ?>
                    <div class="empty-state">No audit log entries found.</div>
                <?php else: ?>
                    <div style="overflow-x:auto;">
                        <table style="width:100%;border-collapse:collapse;font-size:0.88rem;">
                            <thead>
                                <tr style="border-bottom:2px solid var(--border);text-align:left;">
                                    <th style="padding:8px 10px;width:32px;">
                                        <input type="checkbox" id="select-all" title="Select all" />
                                    </th>
                                    <th style="padding:8px 10px;">Date</th>
                                    <th style="padding:8px 10px;">Admin</th>
                                    <th style="padding:8px 10px;">Action</th>
                                    <th style="padding:8px 10px;">Description</th>
                                    <th style="padding:8px 10px;width:60px;"></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($logs as $log): ?>
                                <tr style="border-bottom:1px solid var(--border);">
                                    <td style="padding:8px 10px;">
                                        <input type="checkbox" name="selected_logs[]" value="<?php echo $log['id']; ?>" />
                                    </td>
                                    <td style="padding:8px 10px;white-space:nowrap;color:var(--text-muted);">
                                        <?php echo sanitize(date('d M Y H:i', strtotime($log['created_at']))); ?>
                                    </td>
                                    <td style="padding:8px 10px;">
                                        <?php echo $log['admin_name'] ? sanitize($log['admin_name']) : '<em style="color:var(--text-muted);">System</em>'; ?>
                                    </td>
                                    <td style="padding:8px 10px;">
                                        <code style="font-size:0.82rem;background:var(--surface);padding:2px 6px;border-radius:4px;"><?php echo sanitize($log['action']); ?></code>
                                    </td>
                                    <td style="padding:8px 10px;color:var(--text);">
                                        <?php echo sanitize($log['description']); ?>
                                    </td>
                                    <td style="padding:8px 10px;">
                                        <button type="submit" name="action" value="delete_entry"
                                                onclick="document.getElementById('del-id').value=<?php echo $log['id']; ?>;return confirm('Delete this log entry?');"
                                                class="button button-secondary button-small">Del</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <input type="hidden" name="log_id" id="del-id" value="" />
            </div>
        </form>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div style="display:flex;gap:8px;justify-content:center;margin-top:14px;flex-wrap:wrap;">
            <?php
            $qs = http_build_query(array_filter(['q' => $search, 'from' => $dateFrom, 'to' => $dateTo]));
            $qs = $qs ? "&$qs" : '';
            for ($p = 1; $p <= $totalPages; $p++):
            ?>
                <a href="?page=<?php echo $p; ?><?php echo $qs; ?>"
                   class="button <?php echo $p === $page ? 'button-primary' : 'button-secondary'; ?> button-small"><?php echo $p; ?></a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>

    </main>

    <script>
        document.getElementById('select-all').addEventListener('change', function () {
            document.querySelectorAll('input[name="selected_logs[]"]').forEach(function (cb) {
                cb.checked = this.checked;
            }, this);
        });
        function confirmBulk() {
            var checked = document.querySelectorAll('input[name="selected_logs[]"]:checked').length;
            if (!checked) { alert('No entries selected.'); return false; }
            return confirm('Delete ' + checked + ' selected log ' + (checked === 1 ? 'entry' : 'entries') + '?');
        }
    </script>
</body>
</html>
