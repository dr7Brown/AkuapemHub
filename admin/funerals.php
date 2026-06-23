<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';

require_login();
if (!is_admin_or_manager()) { header('Location: index.php'); exit; }
$user = current_user();
require_mod_permission('approve_funerals');

$feeEnabled = (bool)(int)get_platform_setting('funeral_fee_enabled', '0');
$feeAmount  = (float)get_platform_setting('funeral_fee_amount', '20');

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // Fee settings update
    if (isset($_POST['save_fee'])) {
        set_platform_setting('funeral_fee_enabled', (int)isset($_POST['fee_enabled']));
        set_platform_setting('funeral_fee_amount', max(0, (float)($_POST['fee_amount'] ?? 0)));
        log_audit_action($user['id'], 'funeral_fee_update', 'Updated funeral announcement fee settings');
        header('Location: funerals.php?saved=1'); exit;
    }

    $tid = (int)($_POST['id'] ?? 0);
    if ($tid) {
        if (isset($_POST['action'])) {
            $action = $_POST['action'];
            $row = $pdo->prepare("SELECT * FROM funeral_announcements WHERE id=? LIMIT 1");
            $row->execute([$tid]);
            $row = $row->fetch();

            if ($row) {
                if ($action === 'approve') {
                    $pdo->prepare("UPDATE funeral_announcements SET status='approved', updated_at=NOW() WHERE id=?")->execute([$tid]);
                    log_audit_action($user['id'], 'funeral_approve', "Approved funeral #{$tid}: {$row['deceased_name']}");
                    if ($row['user_id']) {
                        notify_user($row['user_id'], 'Funeral announcement published',
                            'Your announcement for "' . $row['deceased_name'] . '" has been approved and is now live.', 'success');
                    }
                } elseif ($action === 'reject') {
                    $reason = trim($_POST['rejection_reason'] ?? '');
                    $pdo->prepare("UPDATE funeral_announcements SET status='rejected', rejection_reason=?, updated_at=NOW() WHERE id=?")
                        ->execute([$reason ?: null, $tid]);
                    log_audit_action($user['id'], 'funeral_reject', "Rejected funeral #{$tid}: {$row['deceased_name']}");
                    if ($row['user_id']) {
                        $reasonNote = $reason ? " Reason: {$reason}" : ' Contact admin for details.';
                        notify_user($row['user_id'], 'Funeral announcement not approved',
                            'Your announcement for "' . $row['deceased_name'] . '" was not approved.' . $reasonNote . ' You can edit and resubmit it.', 'warning');
                    }
                } elseif ($action === 'mark_paid') {
                    $pdo->prepare("UPDATE funeral_announcements SET status='pending', updated_at=NOW() WHERE id=?")->execute([$tid]);
                    log_audit_action($user['id'], 'funeral_paid', "Marked payment received for funeral #{$tid}");
                    if ($row['user_id']) {
                        notify_user($row['user_id'], 'Payment received — announcement under review',
                            'Payment for "' . $row['deceased_name'] . '" has been recorded. Your announcement is now under review.', 'info');
                    }
                } elseif ($action === 'feature') {
                    $newFeat = $row['featured'] ? 0 : 1;
                    $pdo->prepare("UPDATE funeral_announcements SET featured=? WHERE id=?")->execute([$newFeat, $tid]);
                    log_audit_action($user['id'], 'funeral_feature', "Toggled feature for funeral #{$tid}");
                } elseif ($action === 'delete') {
                    $pdo->prepare("DELETE FROM funeral_announcements WHERE id=?")->execute([$tid]);
                    log_audit_action($user['id'], 'funeral_delete', "Deleted funeral #{$tid}: {$row['deceased_name']}");
                }
            }
        }
        header('Location: funerals.php'); exit;
    }
}

$statusFilter = in_array($_GET['status'] ?? '', ['pending_payment','pending','approved','rejected']) ? $_GET['status'] : '';
$search       = trim($_GET['q'] ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 20;
$offset       = ($page - 1) * $perPage;

$where  = '1';
$params = [];
if ($statusFilter) { $where .= " AND fa.status=?"; $params[] = $statusFilter; }
if ($search)       { $where .= " AND fa.deceased_name LIKE ?"; $params[] = "%$search%"; }

$total = (int)$pdo->prepare("SELECT COUNT(*) FROM funeral_announcements fa WHERE $where")->execute($params) ?
         $pdo->prepare("SELECT COUNT(*) FROM funeral_announcements fa WHERE $where")->execute($params) ? null : null : null;
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM funeral_announcements fa WHERE $where");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$stmt = $pdo->prepare("SELECT fa.*, u.name AS submitter FROM funeral_announcements fa
    LEFT JOIN users u ON u.id=fa.user_id
    WHERE $where ORDER BY
    FIELD(fa.status,'pending_payment','pending','approved','rejected'), fa.created_at DESC
    LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$counts = $pdo->query("SELECT status, COUNT(*) as n FROM funeral_announcements GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$statusLabels = ['pending_payment'=>'Awaiting Payment','pending'=>'Under Review','approved'=>'Published','rejected'=>'Rejected'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Funeral Announcements — Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .af-shell  { max-width:1060px; margin:0 auto; padding:20px 16px 60px; }
        .af-stats  { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:20px; }
        .af-stat   { background:var(--surface,#fff); border:1px solid var(--border); border-radius:10px; padding:10px 16px; text-align:center; min-width:90px; }
        .af-stat strong { display:block; font-size:1.3rem; }
        .af-stat span   { font-size:.74rem; color:var(--text-muted); }
        .af-toolbar { display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-bottom:16px; }
        .af-toolbar form { display:flex; gap:6px; flex:1; min-width:200px; }
        .af-toolbar input  { flex:1; padding:8px 12px; border-radius:8px; border:1px solid var(--border); font-size:.85rem; }
        .af-toolbar select { padding:8px 10px; border-radius:8px; border:1px solid var(--border); font-size:.85rem; }
        .af-table  { width:100%; border-collapse:collapse; font-size:.85rem; }
        .af-table th { background:var(--surface-muted,#f9fafb); padding:9px 12px; text-align:left; font-size:.75rem; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:var(--text-muted); border-bottom:1px solid var(--border); }
        .af-table td { padding:10px 12px; border-bottom:1px solid var(--border,#e5e7eb); vertical-align:middle; }
        .af-table tr:last-child td { border-bottom:none; }
        .af-table tr:hover td { background:var(--surface-muted,#f9fafb); }
        .af-photo  { width:40px; height:40px; border-radius:8px; object-fit:cover; }
        .af-photo-init { width:40px; height:40px; border-radius:8px; background:#f3f4f6; display:flex; align-items:center; justify-content:center; font-weight:900; font-size:.9rem; color:#9ca3af; }
        .af-badge  { display:inline-block; font-size:.65rem; font-weight:800; padding:2px 8px; border-radius:20px; }
        .af-badge-pending_payment { background:#fffbeb; color:#92400e; }
        .af-badge-pending         { background:#eff6ff; color:#1d4ed8; }
        .af-badge-approved        { background:#ecfdf5; color:#065f46; }
        .af-badge-rejected        { background:#fee2e2; color:#991b1b; }
        .af-actions { display:flex; gap:5px; flex-wrap:wrap; }
        .af-fee-box { background:var(--surface,#fff); border:1px solid var(--border); border-radius:12px; padding:16px 18px; margin-bottom:20px; }
        .af-fee-box h2 { font-size:.9rem; font-weight:800; margin:0 0 12px; }
        .af-fee-row { display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
    </style>
</head>
<body>
    <header class="topbar">
        <a href="index.php" class="button button-secondary button-small">← Admin</a>
        <h1>Funeral Announcements</h1>
        <a href="funeral_edit.php" class="button button-primary button-small">+ New</a>
    </header>

    <div class="af-shell">
        <?php if (isset($_GET['saved'])): ?><div class="alert alert-success" style="margin-bottom:14px;">Settings saved.</div><?php endif; ?>

        <!-- Fee settings -->
        <div class="af-fee-box">
            <h2>Fee Settings</h2>
            <form method="post" action="funerals.php" class="af-fee-row">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="save_fee" value="1">
                <label style="display:flex;align-items:center;gap:6px;font-size:.88rem;font-weight:600;cursor:pointer;">
                    <input type="checkbox" name="fee_enabled" value="1" <?php echo $feeEnabled ? 'checked' : ''; ?>>
                    Require payment to publish
                </label>
                <label style="display:flex;align-items:center;gap:6px;font-size:.88rem;font-weight:600;">
                    Amount: GH₵
                    <input type="number" name="fee_amount" value="<?php echo (int)$feeAmount; ?>" min="0" step="0.01" style="width:90px;padding:6px 10px;border-radius:8px;border:1px solid var(--border);">
                </label>
                <button class="button button-small button-primary">Save</button>
            </form>
        </div>

        <!-- Stats -->
        <div class="af-stats">
            <?php foreach ($statusLabels as $k => $l): ?>
            <a href="funerals.php?status=<?php echo $k; ?>" class="af-stat" style="text-decoration:none;color:inherit;">
                <strong><?php echo (int)($counts[$k] ?? 0); ?></strong><span><?php echo $l; ?></span>
            </a>
            <?php endforeach; ?>
            <div class="af-stat"><strong><?php echo array_sum($counts); ?></strong><span>Total</span></div>
        </div>

        <!-- Toolbar -->
        <div class="af-toolbar">
            <form method="get" action="funerals.php">
                <?php if ($statusFilter): ?><input type="hidden" name="status" value="<?php echo sanitize($statusFilter); ?>"><?php endif; ?>
                <input type="search" name="q" placeholder="Search by name…" value="<?php echo sanitize($search); ?>">
                <button class="button button-small">Search</button>
            </form>
            <select onchange="window.location='funerals.php?status='+this.value+'<?php echo $search ? '&q='.urlencode($search) : ''; ?>'">
                <option value="">All statuses</option>
                <?php foreach ($statusLabels as $k => $l): ?>
                <option value="<?php echo $k; ?>" <?php echo $statusFilter===$k ? 'selected' : ''; ?>><?php echo $l; ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Table -->
        <div style="overflow-x:auto;background:var(--surface,#fff);border:1px solid var(--border);border-radius:12px;">
            <table class="af-table">
                <thead>
                    <tr>
                        <th>Photo</th>
                        <th>Deceased</th>
                        <th>Submitted by</th>
                        <th>Burial Date</th>
                        <th>Status</th>
                        <th>Views</th>
                        <th>Featured</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $fa): ?>
                    <tr>
                        <td>
                            <?php if ($fa['photograph']): ?>
                            <img src="../<?php echo sanitize($fa['photograph']); ?>" class="af-photo" alt="">
                            <?php else: ?>
                            <div class="af-photo-init"><?php echo mb_strtoupper(mb_substr($fa['deceased_name'],0,2)); ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <strong><?php echo sanitize($fa['deceased_name']); ?></strong>
                            <?php if ($fa['age']): ?><br><small><?php echo (int)$fa['age']; ?> yrs</small><?php endif; ?>
                        </td>
                        <td style="color:var(--text-muted);font-size:.8rem;"><?php echo $fa['submitter'] ? sanitize($fa['submitter']) : '<em>Admin</em>'; ?></td>
                        <td style="font-size:.82rem;"><?php echo $fa['burial_date'] ? date('d M Y', strtotime($fa['burial_date'])) : '—'; ?></td>
                        <td><span class="af-badge af-badge-<?php echo $fa['status']; ?>"><?php echo $statusLabels[$fa['status']] ?? $fa['status']; ?></span></td>
                        <td style="text-align:center;"><?php echo number_format((int)$fa['view_count']); ?></td>
                        <td style="text-align:center;"><?php echo $fa['featured'] ? '⭐' : '—'; ?></td>
                        <td>
                            <div class="af-actions">
                                <a href="funeral_edit.php?id=<?php echo (int)$fa['id']; ?>" class="button button-small button-primary">View</a>
                                <a href="funeral_edit.php?id=<?php echo (int)$fa['id']; ?>" class="button button-small">Edit</a>
                                <?php if ($fa['status'] === 'pending_payment'): ?>
                                <form method="post" action="funerals.php"><input type="hidden" name="id" value="<?php echo (int)$fa['id']; ?>"><input type="hidden" name="action" value="mark_paid"><?php echo csrf_field(); ?><button class="button button-small" style="background:#fffbeb;color:#92400e;border-color:#f59e0b;">Mark Paid</button></form>
                                <?php endif; ?>
                                <?php if (in_array($fa['status'],['pending_payment','pending','rejected'])): ?>
                                <form method="post" action="funerals.php"><input type="hidden" name="id" value="<?php echo (int)$fa['id']; ?>"><input type="hidden" name="action" value="approve"><?php echo csrf_field(); ?><button class="button button-small" style="background:#ecfdf5;color:#065f46;border-color:#6ee7b7;">Approve</button></form>
                                <?php endif; ?>
                                <?php if (in_array($fa['status'], ['pending','rejected'], true)): ?>
                                <button type="button" class="button button-small" style="background:#fee2e2;color:#991b1b;border-color:#fca5a5;" onclick="openRejectModal(<?php echo (int)$fa['id']; ?>)">Reject</button>
                                <?php endif; ?>
                                <form method="post" action="funerals.php"><input type="hidden" name="id" value="<?php echo (int)$fa['id']; ?>"><input type="hidden" name="action" value="feature"><?php echo csrf_field(); ?><button class="button button-small"><?php echo $fa['featured'] ? 'Unfeature' : 'Feature'; ?></button></form>
                                <form method="post" action="funerals.php" onsubmit="return confirm('Delete this announcement?')"><input type="hidden" name="id" value="<?php echo (int)$fa['id']; ?>"><input type="hidden" name="action" value="delete"><?php echo csrf_field(); ?><button class="button button-small" style="background:#fee2e2;color:#991b1b;border-color:#fca5a5;">Delete</button></form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows): ?>
                    <tr><td colspan="8" style="text-align:center;padding:32px;color:var(--text-muted);">No announcements found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($total > $perPage): ?>
        <div style="display:flex;gap:8px;justify-content:center;margin-top:20px;flex-wrap:wrap;">
            <?php for ($p = 1; $p <= ceil($total / $perPage); $p++): ?>
            <a href="funerals.php?page=<?php echo $p; ?><?php echo $statusFilter ? '&status='.$statusFilter : ''; ?><?php echo $search ? '&q='.urlencode($search) : ''; ?>"
               class="button button-small <?php echo $p === $page ? 'button-primary' : 'button-secondary'; ?>"><?php echo $p; ?></a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
    </div>
</body>

<!-- Reject modal -->
<div id="reject-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9900;align-items:center;justify-content:center;padding:16px;" onclick="if(event.target===this)closeRejectModal()">
    <div style="background:#fff;border-radius:14px;padding:24px;max-width:460px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,.3);">
        <h3 style="margin:0 0 6px;font-size:1rem;">Reject Announcement</h3>
        <p style="font-size:.85rem;color:#6b7280;margin:0 0 14px;">Optionally explain why. The family will see this and can edit and resubmit.</p>
        <form method="post" action="funerals.php">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="id" id="reject-target-id" value="">
            <textarea name="rejection_reason" rows="4" placeholder="e.g. Some required information is missing. Please add the burial date and venue."
                style="width:100%;box-sizing:border-box;padding:10px;border:1px solid #d1d5db;border-radius:8px;font-size:.9rem;resize:vertical;margin-bottom:12px;"></textarea>
            <div style="display:flex;gap:8px;">
                <button type="submit" class="button" style="background:#fee2e2;color:#991b1b;border-color:#fca5a5;">Reject announcement</button>
                <button type="button" class="button button-secondary" onclick="closeRejectModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>
<script>
function openRejectModal(id) {
    document.getElementById('reject-target-id').value = id;
    var m = document.getElementById('reject-modal');
    m.style.display = 'flex';
    m.querySelector('textarea').value = '';
    m.querySelector('textarea').focus();
}
function closeRejectModal() {
    document.getElementById('reject-modal').style.display = 'none';
}
</script>
</html>
