<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';

require_login();
if (!is_admin_or_manager()) { header('Location: index.php'); exit; }
$user = current_user();

// Event fee settings update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_fee'])) {
    csrf_check();
    set_platform_setting('event_fee_enabled', (int)isset($_POST['fee_enabled']));
    set_platform_setting('event_fee_amount', max(0, (float)($_POST['fee_amount'] ?? 0)));
    log_audit_action($user['id'], 'event_fee_update', 'Updated event submission fee settings');
    header('Location: events.php?saved=1'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['id'])) {
    csrf_check();
    $tid = (int)$_POST['id'];
    $row = $pdo->prepare("SELECT * FROM events WHERE id=? LIMIT 1");
    $row->execute([$tid]);
    $ev = $row->fetch();

    if ($ev) {
        switch ($_POST['action']) {
            case 'publish':
                $pdo->prepare("UPDATE events SET status='published', updated_at=NOW() WHERE id=?")->execute([$tid]);
                log_audit_action($user['id'], 'event_publish', "Published event #{$tid}: {$ev['title']}");
                if ($ev['user_id']) {
                    notify_user($ev['user_id'], 'Your event is now live!',
                        '"' . $ev['title'] . '" has been approved and published on the events page.', 'success');
                }
                break;
            case 'cancel':
                $pdo->prepare("UPDATE events SET status='cancelled', updated_at=NOW() WHERE id=?")->execute([$tid]);
                log_audit_action($user['id'], 'event_cancel', "Cancelled event #{$tid}: {$ev['title']}");
                if ($ev['user_id']) {
                    notify_user($ev['user_id'], 'Event cancelled',
                        '"' . $ev['title'] . '" has been marked as cancelled. Contact admin for details.', 'warning');
                }
                break;
            case 'draft':
                $pdo->prepare("UPDATE events SET status='draft', updated_at=NOW() WHERE id=?")->execute([$tid]);
                log_audit_action($user['id'], 'event_draft', "Set event #{$tid} back to draft");
                if ($ev['user_id']) {
                    notify_user($ev['user_id'], 'Event returned for revision',
                        '"' . $ev['title'] . '" has been returned to draft. Please review and resubmit.', 'info');
                }
                break;
            case 'feature':
                $pdo->prepare("UPDATE events SET featured=1-featured WHERE id=?")->execute([$tid]);
                log_audit_action($user['id'], 'event_feature', "Toggled feature for event #{$tid}");
                break;
            case 'delete':
                $pdo->prepare("DELETE FROM events WHERE id=?")->execute([$tid]);
                log_audit_action($user['id'], 'event_delete', "Deleted event #{$tid}: {$ev['title']}");
                break;
        }
    }
    header('Location: events.php'); exit;
}

$statusFilter = in_array($_GET['status'] ?? '', ['draft','published','cancelled']) ? $_GET['status'] : '';
$search       = trim($_GET['q'] ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 20;
$offset       = ($page - 1) * $perPage;
$today        = date('Y-m-d');

$where  = '1';
$params = [];
if ($statusFilter) { $where .= " AND e.status=?"; $params[] = $statusFilter; }
if ($search)       { $where .= " AND e.title LIKE ?"; $params[] = "%$search%"; }

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM events e WHERE $where");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT e.* FROM events e WHERE $where
     ORDER BY FIELD(e.status,'published','draft','cancelled'), e.start_date DESC
     LIMIT $perPage OFFSET $offset"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$counts     = $pdo->query("SELECT status, COUNT(*) FROM events GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$feeEnabled = (bool)(int)get_platform_setting('event_fee_enabled', '0');
$feeAmount  = (float)get_platform_setting('event_fee_amount', '15');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Events — Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .ae-shell  { max-width:1060px; margin:0 auto; padding:20px 16px 60px; }
        .ae-stats  { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:20px; }
        .ae-stat   { background:var(--surface,#fff); border:1px solid var(--border); border-radius:10px; padding:10px 16px; text-align:center; min-width:90px; text-decoration:none; color:inherit; }
        .ae-stat strong { display:block; font-size:1.3rem; }
        .ae-stat span   { font-size:.74rem; color:var(--text-muted); }
        .ae-toolbar { display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-bottom:16px; }
        .ae-toolbar form { display:flex; gap:6px; flex:1; min-width:200px; }
        .ae-toolbar input  { flex:1; padding:8px 12px; border-radius:8px; border:1px solid var(--border); font-size:.85rem; }
        .ae-toolbar select { padding:8px 10px; border-radius:8px; border:1px solid var(--border); font-size:.85rem; }
        .ae-table  { width:100%; border-collapse:collapse; font-size:.85rem; }
        .ae-table th { background:var(--surface-muted,#f9fafb); padding:9px 12px; text-align:left; font-size:.75rem; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:var(--text-muted); border-bottom:1px solid var(--border); }
        .ae-table td { padding:10px 12px; border-bottom:1px solid var(--border,#e5e7eb); vertical-align:middle; }
        .ae-table tr:last-child td { border-bottom:none; }
        .ae-table tr:hover td { background:var(--surface-muted,#f9fafb); }
        .ae-thumb  { width:60px; height:36px; border-radius:6px; object-fit:cover; }
        .ae-badge  { display:inline-block; font-size:.65rem; font-weight:800; padding:2px 8px; border-radius:20px; }
        .ae-badge-published { background:#ecfdf5; color:#065f46; }
        .ae-badge-draft     { background:#f3f4f6; color:#6b7280; }
        .ae-badge-cancelled { background:#fee2e2; color:#991b1b; }
        .ae-badge-past      { background:#f3f4f6; color:#9ca3af; font-size:.6rem; }
        .ae-actions { display:flex; gap:5px; flex-wrap:wrap; }
    </style>
</head>
<body>
    <header class="topbar">
        <a href="index.php" class="button button-secondary button-small">← Admin</a>
        <h1>Events</h1>
        <a href="event_edit.php" class="button button-primary button-small">+ New Event</a>
    </header>

    <div class="ae-shell">
        <?php if (isset($_GET['saved'])): ?><div class="alert alert-success" style="margin-bottom:12px;">Saved.</div><?php endif; ?>

        <!-- Fee settings panel -->
        <div style="background:var(--surface,#fff);border:1px solid var(--border);border-radius:12px;padding:16px 18px;margin-bottom:20px;">
            <h3 style="font-size:.88rem;font-weight:800;margin:0 0 12px;">⚙️ Event Submission Fee</h3>
            <form method="post" action="events.php" style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
                <?php echo csrf_field(); ?>
                <label style="display:flex;align-items:center;gap:6px;font-size:.88rem;cursor:pointer;">
                    <input type="checkbox" name="fee_enabled" value="1" <?php echo $feeEnabled ? 'checked' : ''; ?>>
                    Charge a fee for user-submitted events
                </label>
                <label style="display:flex;align-items:center;gap:6px;font-size:.88rem;">
                    Amount: <strong>GH₵</strong>
                    <input type="number" name="fee_amount" value="<?php echo number_format($feeAmount,2,'.',''); ?>"
                           min="0" step="0.01" style="width:90px;padding:5px 8px;border:1px solid var(--border);border-radius:6px;">
                </label>
                <button type="submit" name="save_fee" class="button button-primary button-small">Save</button>
                <span style="font-size:.78rem;color:var(--text-muted);">
                    <?php echo $feeEnabled ? '⚠️ Users must pay GH₵ ' . number_format($feeAmount,2) . ' per submission.' : 'Currently free for all users.'; ?>
                </span>
            </form>
        </div>

        <!-- Stats -->
        <div class="ae-stats">
            <a href="events.php?status=published" class="ae-stat"><strong><?php echo (int)($counts['published'] ?? 0); ?></strong><span>Published</span></a>
            <a href="events.php?status=draft"     class="ae-stat"><strong><?php echo (int)($counts['draft']     ?? 0); ?></strong><span>Draft</span></a>
            <a href="events.php?status=cancelled" class="ae-stat"><strong><?php echo (int)($counts['cancelled'] ?? 0); ?></strong><span>Cancelled</span></a>
            <div class="ae-stat"><strong><?php echo array_sum($counts); ?></strong><span>Total</span></div>
        </div>

        <!-- Toolbar -->
        <div class="ae-toolbar">
            <form method="get" action="events.php">
                <?php if ($statusFilter): ?><input type="hidden" name="status" value="<?php echo sanitize($statusFilter); ?>"><?php endif; ?>
                <input type="search" name="q" placeholder="Search events…" value="<?php echo sanitize($search); ?>">
                <button class="button button-small">Search</button>
            </form>
            <select onchange="window.location='events.php?status='+this.value+'<?php echo $search ? '&q='.urlencode($search) : ''; ?>'">
                <option value="">All statuses</option>
                <option value="published" <?php echo $statusFilter==='published' ? 'selected':'' ?>>Published</option>
                <option value="draft"     <?php echo $statusFilter==='draft'     ? 'selected':'' ?>>Draft</option>
                <option value="cancelled" <?php echo $statusFilter==='cancelled' ? 'selected':'' ?>>Cancelled</option>
            </select>
        </div>

        <!-- Table -->
        <div style="overflow-x:auto;background:var(--surface,#fff);border:1px solid var(--border);border-radius:12px;">
            <table class="ae-table">
                <thead>
                    <tr>
                        <th>Image</th>
                        <th>Title</th>
                        <th>Date</th>
                        <th>Venue</th>
                        <th>Tickets</th>
                        <th>Status</th>
                        <th>Views</th>
                        <th>Featured</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $ev): ?>
                    <?php $isPast = $ev['start_date'] < $today; ?>
                    <tr>
                        <td>
                            <?php if ($ev['featured_image']): ?>
                            <img src="../<?php echo sanitize($ev['featured_image']); ?>" class="ae-thumb" alt="">
                            <?php else: ?>
                            <div style="width:60px;height:36px;background:#f3f4f6;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;">📅</div>
                            <?php endif; ?>
                        </td>
                        <td><strong><?php echo sanitize($ev['title']); ?></strong></td>
                        <td style="font-size:.8rem;">
                            <?php echo date('d M Y', strtotime($ev['start_date'])); ?>
                            <?php if ($isPast): ?><br><span class="ae-badge ae-badge-past">Past</span><?php endif; ?>
                        </td>
                        <td style="font-size:.8rem;color:var(--text-muted);"><?php echo sanitize(mb_substr($ev['venue'] ?? '', 0, 40)); ?></td>
                        <td>
                            <?php if ($ev['ticket_type'] === 'paid'): ?>
                            <small>GH₵ <?php echo number_format((float)$ev['ticket_price'],2); ?></small>
                            <?php else: ?>
                            <small><?php echo ucfirst($ev['ticket_type']); ?></small>
                            <?php endif; ?>
                        </td>
                        <td><span class="ae-badge ae-badge-<?php echo $ev['status']; ?>"><?php echo ucfirst($ev['status']); ?></span></td>
                        <td style="text-align:center;"><?php echo number_format((int)$ev['view_count']); ?></td>
                        <td style="text-align:center;"><?php echo $ev['featured'] ? '⭐' : '—'; ?></td>
                        <td>
                            <div class="ae-actions">
                                <a href="event_edit.php?id=<?php echo (int)$ev['id']; ?>" class="button button-small">Edit</a>
                                <?php if ($ev['status'] !== 'published'): ?>
                                <form method="post"><input type="hidden" name="id" value="<?php echo (int)$ev['id']; ?>"><input type="hidden" name="action" value="publish"><?php echo csrf_field(); ?><button class="button button-small" style="background:#ecfdf5;color:#065f46;border-color:#6ee7b7;">Publish</button></form>
                                <?php endif; ?>
                                <?php if ($ev['status'] === 'published'): ?>
                                <form method="post"><input type="hidden" name="id" value="<?php echo (int)$ev['id']; ?>"><input type="hidden" name="action" value="cancel"><?php echo csrf_field(); ?><button class="button button-small" style="background:#fef3c7;color:#92400e;border-color:#fcd34d;">Cancel</button></form>
                                <?php endif; ?>
                                <?php if ($ev['status'] === 'cancelled'): ?>
                                <form method="post"><input type="hidden" name="id" value="<?php echo (int)$ev['id']; ?>"><input type="hidden" name="action" value="draft"><?php echo csrf_field(); ?><button class="button button-small">↩ Draft</button></form>
                                <?php endif; ?>
                                <form method="post"><input type="hidden" name="id" value="<?php echo (int)$ev['id']; ?>"><input type="hidden" name="action" value="feature"><?php echo csrf_field(); ?><button class="button button-small"><?php echo $ev['featured'] ? 'Unfeature' : '⭐ Feature'; ?></button></form>
                                <form method="post" onsubmit="return confirm('Delete this event?')"><input type="hidden" name="id" value="<?php echo (int)$ev['id']; ?>"><input type="hidden" name="action" value="delete"><?php echo csrf_field(); ?><button class="button button-small" style="background:#fee2e2;color:#991b1b;border-color:#fca5a5;">Delete</button></form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows): ?>
                    <tr><td colspan="9" style="text-align:center;padding:32px;color:var(--text-muted);">No events found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($total > $perPage): ?>
        <div style="display:flex;gap:8px;justify-content:center;margin-top:20px;flex-wrap:wrap;">
            <?php for ($p = 1; $p <= ceil($total / $perPage); $p++): ?>
            <a href="events.php?page=<?php echo $p; ?><?php echo $statusFilter ? '&status='.$statusFilter : ''; ?><?php echo $search ? '&q='.urlencode($search) : ''; ?>"
               class="button button-small <?php echo $p === $page ? 'button-primary' : 'button-secondary'; ?>"><?php echo $p; ?></a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
