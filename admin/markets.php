<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../marketplace_functions.php';

require_login();
if (!is_admin_or_manager()) { header('Location: index.php'); exit; }
require_mod_permission('manage_markets');

$adminUser = current_user();
$flash     = get_flash();
$error     = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_market') {
        $id                 = (int)($_POST['id'] ?? 0);
        $name               = trim($_POST['name'] ?? '');
        $description        = trim($_POST['description'] ?? '');
        $scheduleNote       = trim($_POST['schedule_note'] ?? '');
        $storehouseLocation = trim($_POST['storehouse_location'] ?? '');
        $storehouseMapsLink = trim($_POST['storehouse_maps_link'] ?? '');

        if ($name === '') {
            $error = 'Market name is required.';
        } else {
            if ($id > 0) {
                $pdo->prepare('UPDATE markets SET name=?, description=?, schedule_note=?, storehouse_location=?, storehouse_maps_link=?, updated_at=NOW() WHERE id=?')
                    ->execute([$name, $description ?: null, $scheduleNote ?: null, $storehouseLocation ?: null, $storehouseMapsLink ?: null, $id]);
                log_audit_action($adminUser['id'], 'market_edited', "Edited market #{$id}: '{$name}'");
                flash('Market updated.', 'success');
            } else {
                $slug = mp_unique_slug($name, 'markets', 'slug', $pdo);
                $pdo->prepare('INSERT INTO markets (name, slug, description, schedule_note, storehouse_location, storehouse_maps_link) VALUES (?,?,?,?,?,?)')
                    ->execute([$name, $slug, $description ?: null, $scheduleNote ?: null, $storehouseLocation ?: null, $storehouseMapsLink ?: null]);
                log_audit_action($adminUser['id'], 'market_created', "Created market: '{$name}'");
                flash('Market added.', 'success');
            }
            header('Location: markets.php'); exit;
        }
    } elseif ($action === 'toggle_status' && !empty($_POST['id'])) {
        $id  = (int)$_POST['id'];
        $row = $pdo->prepare('SELECT name, status FROM markets WHERE id=?');
        $row->execute([$id]); $row = $row->fetch();
        if ($row) {
            $newStatus = $row['status'] === 'open' ? 'closed' : 'open';
            $pdo->prepare('UPDATE markets SET status=?, updated_at=NOW() WHERE id=?')->execute([$newStatus, $id]);
            log_audit_action($adminUser['id'], 'market_status_toggled', "Market '{$row['name']}' (#{$id}) set to {$newStatus}");
            flash("\"{$row['name']}\" is now " . ucfirst($newStatus) . " for purchases.", 'success');
        }
        header('Location: markets.php'); exit;
    } elseif ($action === 'delete_market' && !empty($_POST['id'])) {
        $id  = (int)$_POST['id'];
        $row = $pdo->prepare('SELECT name FROM markets WHERE id=?');
        $row->execute([$id]); $row = $row->fetch();
        if ($row) {
            $shopCount = $pdo->prepare('SELECT COUNT(*) FROM mp_shops WHERE market_id=?');
            $shopCount->execute([$id]);
            $activeOrderCount = $pdo->prepare("SELECT COUNT(*) FROM mp_orders WHERE market_id=? AND status IN ('pending','confirmed','processing','at_storehouse')");
            $activeOrderCount->execute([$id]);
            if ((int)$shopCount->fetchColumn() > 0) {
                flash('Cannot delete — shops are still assigned to this market. Reassign or remove them first.', 'error');
            } elseif ((int)$activeOrderCount->fetchColumn() > 0) {
                flash('Cannot delete — this market has orders still in progress (awaiting storehouse handoff). Wait until they complete first.', 'error');
            } else {
                $pdo->prepare('DELETE FROM markets WHERE id=?')->execute([$id]);
                log_audit_action($adminUser['id'], 'market_deleted', "Deleted market '{$row['name']}' (#{$id})");
                flash('Market deleted.', 'success');
            }
        }
        header('Location: markets.php'); exit;
    } elseif ($action === 'assign_manager') {
        $marketId = (int)($_POST['market_id'] ?? 0);
        $userId   = (int)($_POST['user_id'] ?? 0);
        if ($marketId && $userId) {
            $pdo->prepare('INSERT IGNORE INTO market_managers (market_id, user_id, granted_by) VALUES (?,?,?)')
                ->execute([$marketId, $userId, $adminUser['id']]);
            log_audit_action($adminUser['id'], 'market_manager_assigned', "Assigned user #{$userId} as manager of market #{$marketId}");
            flash('Manager assigned.', 'success');
        }
        header('Location: markets.php?manage=' . $marketId . '&q=' . urlencode($_POST['q'] ?? '')); exit;
    } elseif ($action === 'remove_manager') {
        $marketId = (int)($_POST['market_id'] ?? 0);
        $userId   = (int)($_POST['user_id'] ?? 0);
        $pdo->prepare('DELETE FROM market_managers WHERE market_id=? AND user_id=?')->execute([$marketId, $userId]);
        log_audit_action($adminUser['id'], 'market_manager_removed', "Removed user #{$userId} as manager of market #{$marketId}");
        flash('Manager removed.', 'success');
        header('Location: markets.php?manage=' . $marketId); exit;
    } elseif ($action === 'save_settings' && is_admin()) {
        $days = max(0, (int)($_POST['market_payout_confirmation_days'] ?? 3));
        set_platform_setting('market_payout_confirmation_days', (string)$days);
        log_audit_action($adminUser['id'], 'market_settings_updated', "Set market payout confirmation window to {$days} day(s)");
        flash('Settings saved.', 'success');
        header('Location: markets.php'); exit;
    }
}

$markets = $pdo->query(
    "SELECT m.*,
        (SELECT COUNT(*) FROM mp_shops s WHERE s.market_id = m.id) AS shop_count
     FROM markets m ORDER BY m.name"
)->fetchAll();

$marketPayoutDays = (int)get_platform_setting('market_payout_confirmation_days', get_platform_setting('mp_payout_confirmation_days', 3));

// Managers per market, for the summary column
$managersByMarket = [];
$mgrRows = $pdo->query(
    "SELECT mm.market_id, u.id AS user_id, u.name FROM market_managers mm JOIN users u ON mm.user_id = u.id ORDER BY u.name"
)->fetchAll();
foreach ($mgrRows as $r) { $managersByMarket[$r['market_id']][] = $r; }

// Manager-assignment panel — which market is being managed, and any user search
$manageMarketId = (int)($_GET['manage'] ?? 0);
$manageMarket   = null;
$mgrQ           = trim($_GET['q'] ?? '');
$mgrSearchResults = [];
if ($manageMarketId) {
    foreach ($markets as $m) { if ((int)$m['id'] === $manageMarketId) { $manageMarket = $m; break; } }
    if ($manageMarket && $mgrQ !== '') {
        $searchStmt = $pdo->prepare(
            "SELECT id, name, email, role FROM users
             WHERE (name LIKE ? OR email LIKE ? OR username LIKE ?) AND role IN ('manager','admin')
             ORDER BY name LIMIT 20"
        );
        $like = '%' . $mgrQ . '%';
        $searchStmt->execute([$like, $like, $like]);
        $mgrSearchResults = $searchStmt->fetchAll();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Periodic Markets — Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .mk-shell { max-width: 980px; margin: 0 auto; padding: 18px 16px 60px; }
        .mk-table { width: 100%; border-collapse: collapse; font-size: .84rem; }
        .mk-table th { padding: 9px 12px; text-align: left; font-size: .72rem; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; color: var(--text-muted,#6b7280); border-bottom: 1px solid var(--border); background: var(--surface-muted,#f9fafb); }
        .mk-table td { padding: 10px 12px; border-bottom: 1px solid var(--border,#f1f5f9); vertical-align: middle; }
        .mk-table tr:last-child td { border-bottom: none; }
        .mk-card { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; padding: 18px; margin-bottom: 16px; overflow-x: auto; }
        .mk-form-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px,1fr)); gap: 12px; }
        .mk-form-grid label { font-weight: 600; font-size: .82rem; display: block; margin-bottom: 4px; }
        .mk-form-grid input[type=text], .mk-form-grid input[type=url], .mk-form-grid textarea { width: 100%; padding: 7px 9px; border: 1px solid var(--border); border-radius: 8px; font-size: .84rem; box-sizing: border-box; }
        .mk-badge { display:inline-block; padding:2px 9px; border-radius:20px; font-size:.68rem; font-weight:800; }
        .mk-badge.open { background:#d1fae5; color:#065f46; }
        .mk-badge.closed { background:#fee2e2; color:#c0392b; }
        .mk-mgr-chip { display:inline-flex; align-items:center; gap:5px; background:var(--surface-muted,#f3f4f6); border-radius:14px; padding:2px 8px 2px 10px; font-size:.76rem; margin:2px 3px 2px 0; }
        .mk-mgr-chip button { border:none; background:none; color:#c0392b; cursor:pointer; font-weight:800; padding:0 2px; }
    </style>
</head>
<body>

<header class="topbar">
    <a href="index.php" class="button button-secondary button-small">← Dashboard</a>
    <h1 style="margin:0;font-size:1rem;font-weight:800;">🏬 Periodic Markets</h1>
    <a href="market_deliveries.php" class="button button-primary button-small">📦 Storehouse Deliveries</a>
</header>

<main class="mk-shell">

    <?php if ($flash): ?>
    <div class="alert alert-<?php echo sanitize($flash['type']); ?>" style="margin-bottom:14px;"><?php echo sanitize($flash['message']); ?></div>
    <?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error" style="margin-bottom:14px;"><?php echo sanitize($error); ?></div><?php endif; ?>

    <p style="font-size:.84rem;color:var(--text-muted,#6b7280);margin-bottom:16px;">
        Each market is a recurring scheduled market day (Ofie Market, Nkurakan Market, etc.). Sellers
        list products anytime under a market; buyers can only <strong>check out</strong> while it's
        marked Open — flip it open on market day and closed once it wraps up. Assign a manager per
        market to handle the storehouse handoff after purchases.
    </p>

    <div class="mk-card">
        <?php if (!$markets): ?>
            <p style="text-align:center;color:var(--text-muted,#6b7280);padding:20px;">No markets yet — add one below.</p>
        <?php else: ?>
        <table class="mk-table">
            <thead><tr><th>Market</th><th>Schedule</th><th>Shops</th><th>Managers</th><th>Status</th><th style="text-align:right;">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($markets as $m): ?>
            <tr>
                <td><strong><?php echo sanitize($m['name']); ?></strong><?php if ($m['storehouse_location']): ?><br><span style="font-size:.72rem;color:var(--text-muted,#6b7280);">📍 <?php echo sanitize($m['storehouse_location']); ?></span><?php endif; ?></td>
                <td><?php echo $m['schedule_note'] ? sanitize($m['schedule_note']) : '<span style="color:var(--text-muted,#6b7280);">—</span>'; ?></td>
                <td><?php echo (int)$m['shop_count']; ?></td>
                <td><?php echo isset($managersByMarket[$m['id']]) ? count($managersByMarket[$m['id']]) . ' assigned' : '<span style="color:var(--text-muted,#6b7280);">None</span>'; ?></td>
                <td><span class="mk-badge <?php echo $m['status']; ?>"><?php echo ucfirst($m['status']); ?></span></td>
                <td style="text-align:right;white-space:nowrap;">
                    <a href="markets.php?manage=<?php echo (int)$m['id']; ?>#mgr-panel" class="button button-secondary button-small">Managers</a>
                    <button type="button" class="button button-secondary button-small" onclick='editMarket(<?php echo json_encode($m); ?>)'>Edit</button>
                    <form method="post" style="display:inline;">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="toggle_status">
                        <input type="hidden" name="id" value="<?php echo (int)$m['id']; ?>">
                        <button type="submit" class="button button-small" style="background:<?php echo $m['status']==='open' ? '#ef4444' : '#10b981'; ?>;color:#fff;border-color:transparent;">
                            <?php echo $m['status'] === 'open' ? 'Close' : 'Open'; ?>
                        </button>
                    </form>
                    <form method="post" style="display:inline;" onsubmit="return confirm('Delete this market permanently?');">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="delete_market">
                        <input type="hidden" name="id" value="<?php echo (int)$m['id']; ?>">
                        <button type="submit" class="button button-secondary button-small" style="color:#c0392b;">Delete</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <?php if (is_admin()): ?>
    <div class="mk-card">
        <h2 style="margin-top:0;font-size:1rem;">Settings</h2>
        <p style="font-size:.84rem;color:var(--text-muted,#6b7280);margin-top:-8px;">
            These apply to all periodic markets and are intentionally separate from the regular
            Marketplace's settings (<a href="marketplace.php?tab=settings">Marketplace → Settings</a>) —
            storehouse pickup is a different process from a shipped delivery, so the two can diverge.
        </p>
        <form method="post">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="save_settings">
            <div class="form-group" style="max-width:320px;">
                <label for="market_payout_confirmation_days">Seller payout confirmation window (days)</label>
                <input type="number" id="market_payout_confirmation_days" name="market_payout_confirmation_days" min="0" max="30" value="<?php echo $marketPayoutDays; ?>">
                <p class="form-hint">How long after a buyer picks up their order (marked "Handed to Buyer") before the seller's payout is released, if the buyer raises no dispute. Regular Marketplace deliveries use their own separate setting.</p>
            </div>
            <button type="submit" class="button button-primary button-small">Save Settings</button>
        </form>
    </div>
    <?php endif; ?>

    <div class="mk-card">
        <h2 id="mk-form-heading" style="margin-top:0;font-size:1rem;">Add Market</h2>
        <form method="post" id="mk-form">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="save_market">
            <input type="hidden" name="id" id="f_id" value="0">

            <div class="mk-form-grid">
                <div>
                    <label>Market Name *</label>
                    <input type="text" name="name" id="f_name" required placeholder="e.g. Ofie Market">
                </div>
                <div>
                    <label>Schedule</label>
                    <input type="text" name="schedule_note" id="f_schedule" placeholder="e.g. Every first Saturday of the month">
                </div>
                <div>
                    <label>Storehouse Location</label>
                    <input type="text" name="storehouse_location" id="f_location" placeholder="e.g. Ofie Market Storehouse, near the lorry station">
                </div>
                <div>
                    <label>Storehouse Google Maps Link</label>
                    <input type="url" name="storehouse_maps_link" id="f_maps" placeholder="https://maps.google.com/…">
                </div>
            </div>
            <div class="form-group" style="margin-top:12px;">
                <label>Description</label>
                <textarea name="description" id="f_description" rows="2" placeholder="Optional — shown on the public market page"></textarea>
            </div>

            <div style="margin-top:16px;display:flex;gap:8px;">
                <button type="submit" class="button button-primary">Save Market</button>
                <button type="button" class="button button-secondary" onclick="resetForm()">Cancel Edit</button>
            </div>
        </form>
    </div>

    <?php if ($manageMarket): ?>
    <div class="mk-card" id="mgr-panel">
        <h2 style="margin-top:0;font-size:1rem;">Managers — <?php echo sanitize($manageMarket['name']); ?></h2>
        <p style="font-size:.84rem;color:var(--text-muted,#6b7280);">
            Only assigned managers (and admins) can handle storehouse deliveries for this market.
        </p>

        <?php if (!empty($managersByMarket[$manageMarket['id']])): ?>
        <div style="margin-bottom:14px;">
            <?php foreach ($managersByMarket[$manageMarket['id']] as $mgr): ?>
            <span class="mk-mgr-chip">
                <?php echo sanitize($mgr['name']); ?>
                <form method="post" style="display:inline;margin:0;" onsubmit="return confirm('Remove <?php echo sanitize(addslashes($mgr['name'])); ?> as manager?');">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="remove_manager">
                    <input type="hidden" name="market_id" value="<?php echo (int)$manageMarket['id']; ?>">
                    <input type="hidden" name="user_id" value="<?php echo (int)$mgr['user_id']; ?>">
                    <button type="submit" title="Remove">×</button>
                </form>
            </span>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <p style="font-size:.84rem;color:var(--text-muted,#6b7280);">No managers assigned yet.</p>
        <?php endif; ?>

        <form method="get" action="markets.php" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px;">
            <input type="hidden" name="manage" value="<?php echo (int)$manageMarket['id']; ?>">
            <input type="text" name="q" value="<?php echo sanitize($mgrQ); ?>" placeholder="Search manager/admin by name, email, or username…" style="flex:1;min-width:220px;padding:7px 10px;border:1px solid var(--border);border-radius:8px;font-size:.82rem;">
            <button type="submit" class="button button-secondary button-small">Search</button>
        </form>
        <?php if ($mgrQ !== ''): ?>
            <?php if (!$mgrSearchResults): ?>
            <p style="font-size:.84rem;color:var(--text-muted,#6b7280);">No matching manager/admin accounts. Only users with the Manager or Admin role can be assigned — promote them first via <a href="user_edit.php">Users</a>.</p>
            <?php else: ?>
            <table class="mk-table">
                <thead><tr><th>Name</th><th>Email</th><th>Role</th><th style="text-align:right;">Action</th></tr></thead>
                <tbody>
                <?php foreach ($mgrSearchResults as $r): ?>
                <tr>
                    <td><?php echo sanitize($r['name']); ?></td>
                    <td><?php echo sanitize($r['email']); ?></td>
                    <td><?php echo ucfirst($r['role']); ?></td>
                    <td style="text-align:right;">
                        <form method="post" style="display:inline;">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="assign_manager">
                            <input type="hidden" name="market_id" value="<?php echo (int)$manageMarket['id']; ?>">
                            <input type="hidden" name="user_id" value="<?php echo (int)$r['id']; ?>">
                            <input type="hidden" name="q" value="<?php echo sanitize($mgrQ); ?>">
                            <button type="submit" class="button button-primary button-small">Assign</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        <?php endif; ?>
        <p style="font-size:.76rem;color:var(--text-muted,#6b7280);margin-top:10px;">
            Note: a manager also needs the "Manage storehouse handoffs for assigned markets" permission —
            grant it via <a href="moderators.php">Moderators</a> if not already enabled for them.
        </p>
    </div>
    <?php endif; ?>

</main>

<script>
function editMarket(m) {
    document.getElementById('mk-form-heading').textContent = 'Edit Market — ' + m.name;
    document.getElementById('f_id').value = m.id;
    document.getElementById('f_name').value = m.name;
    document.getElementById('f_schedule').value = m.schedule_note || '';
    document.getElementById('f_location').value = m.storehouse_location || '';
    document.getElementById('f_maps').value = m.storehouse_maps_link || '';
    document.getElementById('f_description').value = m.description || '';
    document.getElementById('mk-form').scrollIntoView({ behavior: 'smooth' });
}
function resetForm() {
    document.getElementById('mk-form').reset();
    document.getElementById('f_id').value = 0;
    document.getElementById('mk-form-heading').textContent = 'Add Market';
}
</script>

</body>
</html>
