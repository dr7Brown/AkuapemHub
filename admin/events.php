<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';

require_login();
if (!is_admin_or_manager()) { header('Location: index.php'); exit; }
$user = current_user();
require_mod_permission('approve_events');

// Event fee settings update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_fee'])) {
    csrf_check();
    set_platform_setting('event_fee_enabled', (int)isset($_POST['fee_enabled']));
    set_platform_setting('event_fee_amount', max(0, (float)($_POST['fee_amount'] ?? 0)));
    log_audit_action($user['id'], 'event_fee_update', 'Updated event submission fee settings');
    header('Location: events.php?saved=1'); exit;
}

// Featured settings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_featured'])) {
    csrf_check();
    set_platform_setting('enable_paid_featured_events', isset($_POST['feat_paid']) ? '1' : '0');
    log_audit_action($user['id'], 'event_feat_update', 'Updated event featuring settings');
    header('Location: events.php?saved=1'); exit;
}

// Package CRUD
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pkg_action'])) {
    csrf_check();
    $pa = $_POST['pkg_action'];
    if ($pa === 'add_pkg') {
        $pName = trim($_POST['pkg_name'] ?? '');
        $pDays = max(1, (int)($_POST['pkg_days'] ?? 30));
        $pPrice= max(0, (float)($_POST['pkg_price'] ?? 0));
        if ($pName) {
            $pdo->prepare("INSERT INTO featured_event_packages (name,duration_days,price,status) VALUES (?,?,?,'active')")
                ->execute([$pName, $pDays, $pPrice]);
            flash('Package added.', 'success');
        }
    } elseif ($pa === 'toggle_pkg' && !empty($_POST['pkg_id'])) {
        $pid = (int)$_POST['pkg_id'];
        $pdo->prepare("UPDATE featured_event_packages SET status=IF(status='active','inactive','active') WHERE id=?")->execute([$pid]);
    } elseif ($pa === 'delete_pkg' && !empty($_POST['pkg_id'])) {
        $pdo->prepare("DELETE FROM featured_event_packages WHERE id=?")->execute([(int)$_POST['pkg_id']]);
        flash('Package deleted.', 'info');
    } elseif ($pa === 'edit_pkg' && !empty($_POST['pkg_id'])) {
        $pdo->prepare("UPDATE featured_event_packages SET name=?,duration_days=?,price=? WHERE id=?")
            ->execute([trim($_POST['pkg_name']??''), max(1,(int)($_POST['pkg_days']??30)), max(0,(float)($_POST['pkg_price']??0)), (int)$_POST['pkg_id']]);
        flash('Package updated.', 'success');
    }
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
                if (check_mod_coi('event', $tid, $user['id'])) {
                    log_coi_violation($user['id'], 'event', $tid, 'publish');
                    header('Location: events.php'); exit;
                }
                $pdo->prepare("UPDATE events SET status='published', updated_at=NOW() WHERE id=?")->execute([$tid]);
                log_audit_action($user['id'], 'event_publish', "Published event #{$tid}: {$ev['title']}");
                log_mod_activity($user['id'], 'events', 'approve_event', $tid);
                if ($ev['user_id']) {
                    require_once __DIR__ . '/../modules/referrals/service.php';
                    award_points((int)$ev['user_id'], 'event_approved', $tid);
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
            case 'reject':
                $reason = trim($_POST['rejection_reason'] ?? '');
                $pdo->prepare("UPDATE events SET status='rejected', rejection_reason=?, updated_at=NOW() WHERE id=?")
                    ->execute([$reason ?: null, $tid]);
                log_audit_action($user['id'], 'event_reject', "Rejected event #{$tid}: {$ev['title']}");
                log_mod_activity($user['id'], 'events', 'reject_event', $tid);
                if ($ev['user_id']) {
                    $body = '"' . $ev['title'] . '" was not approved and needs your attention.';
                    if ($reason) $body .= "\n\nReason: {$reason}";
                    $body .= "\n\nClick to edit and resubmit.";
                    notify_user($ev['user_id'], '❌ Event Rejected — Action Required',
                        $body, 'error', 'my_events.php?edit=' . $tid);
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

$statusFilter = in_array($_GET['status'] ?? '', ['pending_payment','draft','published','cancelled','rejected']) ? $_GET['status'] : '';
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

// Feature settings
$featPaid    = get_platform_setting('enable_paid_featured_events', '0') === '1';
$featPkgs    = $pdo->query("SELECT * FROM featured_event_packages ORDER BY duration_days ASC")->fetchAll();
$featRevenue = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM platform_payments WHERE payment_type='featured_event' AND status='paid'")->fetchColumn();
$featActive  = (int)$pdo->query("SELECT COUNT(*) FROM events WHERE featured=1 AND (featured_end_date IS NULL OR featured_end_date>=CURDATE())")->fetchColumn();

// Monetization stats
$feeRevenue     = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM platform_payments WHERE payment_type='event_post' AND status='paid'")->fetchColumn();
$feeThisMonth   = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM platform_payments WHERE payment_type='event_post' AND status='paid' AND MONTH(paid_at)=MONTH(NOW()) AND YEAR(paid_at)=YEAR(NOW())")->fetchColumn();
$feePendingCount= (int)$pdo->query("SELECT COUNT(*) FROM platform_payments WHERE payment_type='event_post' AND status='pending'")->fetchColumn();
$feePayingUsers = (int)$pdo->query("SELECT COUNT(DISTINCT user_id) FROM platform_payments WHERE payment_type='event_post' AND status='paid'")->fetchColumn();
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
        .ae-badge-rejected  { background:#fee2e2; color:#991b1b; }
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

        <!-- Monetization panel -->
        <div style="background:var(--surface,#fff);border:1px solid var(--border);border-radius:14px;padding:18px;margin-bottom:20px;">
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:16px;">
                <div>
                    <h3 style="font-size:.9rem;font-weight:800;margin:0 0 2px;">💰 Event Submission Fee</h3>
                    <p style="font-size:.76rem;color:var(--muted,#6b7280);margin:0;">Charge users to submit events for review.</p>
                </div>
                <span style="background:<?php echo $feeEnabled?'#d1fae5':'#f3f4f6'; ?>;color:<?php echo $feeEnabled?'#065f46':'#6b7280'; ?>;font-size:.72rem;font-weight:800;padding:3px 10px;border-radius:20px;">
                    <?php echo $feeEnabled ? '● ENABLED' : '○ DISABLED'; ?>
                </span>
            </div>
            <!-- Revenue stats row -->
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:10px;margin-bottom:16px;">
                <div style="background:var(--surface-muted,#f8fafc);border-radius:10px;padding:10px;text-align:center;">
                    <strong style="display:block;font-size:1.1rem;font-weight:900;color:var(--primary,#0f766e);">GH₵ <?php echo number_format($feeRevenue,2); ?></strong>
                    <span style="font-size:.68rem;color:var(--muted,#6b7280);">Total Revenue</span>
                </div>
                <div style="background:var(--surface-muted,#f8fafc);border-radius:10px;padding:10px;text-align:center;">
                    <strong style="display:block;font-size:1.1rem;font-weight:900;color:var(--primary,#0f766e);">GH₵ <?php echo number_format($feeThisMonth,2); ?></strong>
                    <span style="font-size:.68rem;color:var(--muted,#6b7280);">This Month</span>
                </div>
                <div style="background:var(--surface-muted,#f8fafc);border-radius:10px;padding:10px;text-align:center;">
                    <strong style="display:block;font-size:1.1rem;font-weight:900;color:<?php echo $feePendingCount?'#f59e0b':'#6b7280'; ?>;"><?php echo $feePendingCount; ?></strong>
                    <span style="font-size:.68rem;color:var(--muted,#6b7280);">Pending Payment</span>
                </div>
                <div style="background:var(--surface-muted,#f8fafc);border-radius:10px;padding:10px;text-align:center;">
                    <strong style="display:block;font-size:1.1rem;font-weight:900;"><?php echo $feePayingUsers; ?></strong>
                    <span style="font-size:.68rem;color:var(--muted,#6b7280);">Paying Users</span>
                </div>
            </div>
            <?php if ($feePendingCount > 0): ?>
            <div style="background:#fef3c7;border:1px solid #f59e0b;border-radius:8px;padding:8px 12px;font-size:.8rem;margin-bottom:12px;">
                ⏳ <strong><?php echo $feePendingCount; ?> event<?php echo $feePendingCount!==1?'s':''; ?></strong> awaiting payment — users have been redirected to pay.
                <a href="events.php?status=pending_payment" style="color:#92400e;font-weight:700;margin-left:6px;">View →</a>
            </div>
            <?php endif; ?>
            <!-- Settings form -->
            <form method="post" action="events.php" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding-top:14px;border-top:1px solid var(--border);">
                <?php echo csrf_field(); ?>
                <label style="display:flex;align-items:center;gap:6px;font-size:.86rem;font-weight:600;cursor:pointer;">
                    <input type="checkbox" name="fee_enabled" value="1" <?php echo $feeEnabled ? 'checked' : ''; ?>>
                    Require fee to submit events
                </label>
                <label style="display:flex;align-items:center;gap:6px;font-size:.86rem;font-weight:600;">
                    GH₵ <input type="number" name="fee_amount" value="<?php echo number_format($feeAmount,2,'.',''); ?>"
                           min="0" step="0.01" style="width:90px;padding:6px 10px;border:1px solid var(--border);border-radius:8px;">
                </label>
                <button type="submit" name="save_fee" class="button button-primary button-small">Save Settings</button>
            </form>
        </div>

        <!-- Stats -->
        <div class="ae-stats">
            <?php if ((int)($counts['pending_payment'] ?? 0) > 0): ?>
            <a href="events.php?status=pending_payment" class="ae-stat" style="border-color:#f59e0b;">
                <strong style="color:#f59e0b;"><?php echo (int)($counts['pending_payment'] ?? 0); ?></strong><span>⏳ Awaiting Payment</span>
            </a>
            <?php endif; ?>
            <a href="events.php?status=published" class="ae-stat"><strong><?php echo (int)($counts['published'] ?? 0); ?></strong><span>Published</span></a>
            <a href="events.php?status=draft"     class="ae-stat"><strong><?php echo (int)($counts['draft']     ?? 0); ?></strong><span>Pending Review</span></a>
            <a href="events.php?status=rejected"  class="ae-stat"><strong style="color:#dc2626;"><?php echo (int)($counts['rejected']  ?? 0); ?></strong><span>Rejected</span></a>
            <a href="events.php?status=cancelled" class="ae-stat"><strong><?php echo (int)($counts['cancelled'] ?? 0); ?></strong><span>Cancelled</span></a>
            <div class="ae-stat"><strong><?php echo array_sum($counts); ?></strong><span>Total</span></div>
        </div>

        <!-- Featuring panel -->
        <div style="background:var(--surface,#fff);border:1px solid var(--border);border-radius:14px;padding:18px;margin-bottom:20px;">
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:14px;">
                <div>
                    <h3 style="font-size:.9rem;font-weight:800;margin:0 0 2px;">⭐ Event Featuring</h3>
                    <p style="font-size:.76rem;color:var(--muted,#6b7280);margin:0;">Allow users to pay to pin their events at the top with a featured badge.</p>
                </div>
                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    <span style="background:var(--primary-soft,#d1fae5);color:var(--primary,#0f766e);font-size:.76rem;font-weight:800;padding:4px 10px;border-radius:10px;">GH₵ <?php echo number_format($featRevenue,2); ?> earned</span>
                    <span style="background:var(--surface-muted,#f3f4f6);color:var(--text,#1a2230);font-size:.76rem;font-weight:800;padding:4px 10px;border-radius:10px;"><?php echo $featActive; ?> active now</span>
                </div>
            </div>
            <!-- Toggle paid/free -->
            <form method="post" action="events.php" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding-bottom:14px;border-bottom:1px solid var(--border);margin-bottom:14px;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="save_featured" value="1">
                <label style="display:flex;align-items:center;gap:6px;font-size:.86rem;font-weight:600;cursor:pointer;">
                    <input type="checkbox" name="feat_paid" value="1" <?php echo $featPaid ? 'checked' : ''; ?>>
                    Charge users to feature events
                </label>
                <span style="font-size:.76rem;color:var(--muted,#6b7280);">
                    <?php echo $featPaid ? 'Users must choose a package and pay.' : 'Currently free — events featured instantly.'; ?>
                </span>
                <button type="submit" class="button button-primary button-small">Save</button>
            </form>
            <!-- Packages -->
            <p style="font-size:.74rem;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:var(--muted,#6b7280);margin:0 0 10px;">Packages</p>
            <?php if ($featPkgs): ?>
            <table style="width:100%;border-collapse:collapse;font-size:.83rem;margin-bottom:12px;">
                <thead><tr>
                    <th style="text-align:left;padding:6px 8px;border-bottom:1px solid var(--border);font-size:.7rem;font-weight:800;text-transform:uppercase;color:var(--muted);">Name</th>
                    <th style="padding:6px 8px;border-bottom:1px solid var(--border);font-size:.7rem;font-weight:800;text-transform:uppercase;color:var(--muted);">Days</th>
                    <th style="padding:6px 8px;border-bottom:1px solid var(--border);font-size:.7rem;font-weight:800;text-transform:uppercase;color:var(--muted);">Price</th>
                    <th style="padding:6px 8px;border-bottom:1px solid var(--border);font-size:.7rem;font-weight:800;text-transform:uppercase;color:var(--muted);">Status</th>
                    <th style="padding:6px 8px;border-bottom:1px solid var(--border);"></th>
                </tr></thead>
                <tbody>
                <?php foreach ($featPkgs as $pkg): ?>
                <tr style="<?php echo $pkg['status']==='inactive'?'opacity:.5':''; ?>">
                    <td style="padding:7px 8px;border-bottom:1px solid var(--border);"><?php echo sanitize($pkg['name']); ?></td>
                    <td style="padding:7px 8px;border-bottom:1px solid var(--border);text-align:center;"><?php echo (int)$pkg['duration_days']; ?></td>
                    <td style="padding:7px 8px;border-bottom:1px solid var(--border);font-weight:700;color:var(--primary);">GH₵ <?php echo number_format((float)$pkg['price'],2); ?></td>
                    <td style="padding:7px 8px;border-bottom:1px solid var(--border);">
                        <span style="font-size:.68rem;font-weight:800;padding:2px 8px;border-radius:20px;background:<?php echo $pkg['status']==='active'?'#d1fae5':'#f3f4f6'; ?>;color:<?php echo $pkg['status']==='active'?'#065f46':'#6b7280'; ?>;">
                            <?php echo ucfirst($pkg['status']); ?>
                        </span>
                    </td>
                    <td style="padding:7px 8px;border-bottom:1px solid var(--border);">
                        <form method="post" action="events.php" style="display:inline;">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="pkg_action" value="toggle_pkg">
                            <input type="hidden" name="pkg_id" value="<?php echo $pkg['id']; ?>">
                            <button type="submit" class="button button-small button-secondary" style="font-size:.7rem;padding:2px 8px;"><?php echo $pkg['status']==='active'?'Disable':'Enable'; ?></button>
                        </form>
                        <form method="post" action="events.php" style="display:inline;" onsubmit="return confirm('Delete this package?')">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="pkg_action" value="delete_pkg">
                            <input type="hidden" name="pkg_id" value="<?php echo $pkg['id']; ?>">
                            <button type="submit" class="button button-small" style="font-size:.7rem;padding:2px 8px;background:#fee2e2;color:#991b1b;border-color:transparent;">Del</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
            <!-- Add package -->
            <form method="post" action="events.php" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="pkg_action" value="add_pkg">
                <div><label style="font-size:.72rem;font-weight:700;display:block;margin-bottom:3px;">Name</label>
                    <input type="text" name="pkg_name" placeholder="e.g. 30 Days" style="padding:6px 10px;border:1px solid var(--border);border-radius:8px;width:110px;"></div>
                <div><label style="font-size:.72rem;font-weight:700;display:block;margin-bottom:3px;">Days</label>
                    <input type="number" name="pkg_days" min="1" value="30" style="padding:6px 10px;border:1px solid var(--border);border-radius:8px;width:70px;"></div>
                <div><label style="font-size:.72rem;font-weight:700;display:block;margin-bottom:3px;">Price (GH₵)</label>
                    <input type="number" name="pkg_price" min="0" step="0.01" value="0" style="padding:6px 10px;border:1px solid var(--border);border-radius:8px;width:90px;"></div>
                <button type="submit" class="button button-primary button-small">+ Add Package</button>
            </form>
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
                <option value="pending_payment" <?php echo $statusFilter==='pending_payment' ? 'selected':'' ?>>⏳ Awaiting Payment</option>
                <option value="published"       <?php echo $statusFilter==='published'       ? 'selected':'' ?>>Published</option>
                <option value="draft"           <?php echo $statusFilter==='draft'           ? 'selected':'' ?>>Draft / Pending Review</option>
                <option value="rejected"        <?php echo $statusFilter==='rejected'        ? 'selected':'' ?>>Rejected</option>
                <option value="cancelled"       <?php echo $statusFilter==='cancelled'       ? 'selected':'' ?>>Cancelled</option>
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
                            <?php $evCoi = !is_admin() && (int)($ev['user_id'] ?? 0) === (int)$user['id']; ?>
                            <div class="ae-actions">
                                <a href="event_edit.php?id=<?php echo (int)$ev['id']; ?>" class="button button-small button-primary">View</a>
                                <a href="event_edit.php?id=<?php echo (int)$ev['id']; ?>" class="button button-small">Edit</a>
                                <?php if ($evCoi && in_array($ev['status'],['draft','pending_payment'],true)): ?>
                                <span style="background:#fef3c7;border:1px solid #f59e0b;color:#92400e;font-size:.72rem;font-weight:700;padding:3px 8px;border-radius:8px;">⚠️ Yours</span>
                                <?php else: ?>
                                <?php if (!in_array($ev['status'], ['published','rejected'], true)): ?>
                                <form method="post" action="events.php"><input type="hidden" name="id" value="<?php echo (int)$ev['id']; ?>"><input type="hidden" name="action" value="publish"><?php echo csrf_field(); ?><button class="button button-small" style="background:#ecfdf5;color:#065f46;border-color:#6ee7b7;">Publish</button></form>
                                <?php endif; ?>
                                <?php if ($ev['status'] !== 'rejected'): ?>
                                <button type="button" class="button button-small" style="background:#fee2e2;color:#991b1b;border-color:#fca5a5;" onclick="openRejectModal(<?php echo (int)$ev['id']; ?>)">Reject</button>
                                <?php endif; ?>
                                <?php endif; ?>
                                <?php if ($ev['status'] === 'published'): ?>
                                <form method="post" action="events.php"><input type="hidden" name="id" value="<?php echo (int)$ev['id']; ?>"><input type="hidden" name="action" value="cancel"><?php echo csrf_field(); ?><button class="button button-small" style="background:#fef3c7;color:#92400e;border-color:#fcd34d;">Cancel</button></form>
                                <?php endif; ?>
                                <?php if (in_array($ev['status'], ['cancelled','rejected'], true)): ?>
                                <form method="post" action="events.php"><input type="hidden" name="id" value="<?php echo (int)$ev['id']; ?>"><input type="hidden" name="action" value="draft"><?php echo csrf_field(); ?><button class="button button-small">↩ Draft</button></form>
                                <?php endif; ?>
                                <form method="post" action="events.php"><input type="hidden" name="id" value="<?php echo (int)$ev['id']; ?>"><input type="hidden" name="action" value="feature"><?php echo csrf_field(); ?><button class="button button-small"><?php echo $ev['featured'] ? 'Unfeature' : '⭐ Feature'; ?></button></form>
                                <form method="post" action="events.php" onsubmit="return confirm('Delete this event?')"><input type="hidden" name="id" value="<?php echo (int)$ev['id']; ?>"><input type="hidden" name="action" value="delete"><?php echo csrf_field(); ?><button class="button button-small" style="background:#fee2e2;color:#991b1b;border-color:#fca5a5;">Delete</button></form>
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

<!-- Reject modal -->
<div id="reject-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9900;align-items:center;justify-content:center;padding:16px;" onclick="if(event.target===this)closeRejectModal()">
    <div style="background:#fff;border-radius:14px;padding:24px;max-width:460px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,.3);">
        <h3 style="margin:0 0 6px;font-size:1rem;">Reject Event</h3>
        <p style="font-size:.85rem;color:#6b7280;margin:0 0 14px;">Optionally explain why. The organiser will see this and can edit and resubmit.</p>
        <form method="post" action="events.php">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="id" id="reject-target-id" value="">
            <textarea name="rejection_reason" rows="4" placeholder="e.g. The event description needs more details about the programme and venue."
                style="width:100%;box-sizing:border-box;padding:10px;border:1px solid #d1d5db;border-radius:8px;font-size:.9rem;resize:vertical;margin-bottom:12px;"></textarea>
            <div style="display:flex;gap:8px;">
                <button type="submit" class="button" style="background:#fee2e2;color:#991b1b;border-color:#fca5a5;">Reject event</button>
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
