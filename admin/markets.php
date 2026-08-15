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
        $pickupFee          = max(0, (float)($_POST['pickup_fee'] ?? 0));
        $colorRaw           = trim($_POST['color'] ?? '');
        $color              = preg_match('/^#[0-9a-f]{6}$/i', $colorRaw) ? $colorRaw : null;

        $recurrenceType = in_array($_POST['recurrence_type'] ?? '', ['manual','weekly','monthly'], true) ? $_POST['recurrence_type'] : 'manual';
        $recurrenceWeekdays = null;
        $recurrenceWeekOfMonth = null;
        $preorderDays = max(0, (int)($_POST['preorder_days'] ?? 0));
        $orderCloseTimeRaw = trim($_POST['order_close_time'] ?? '');
        $orderCloseTime = ($orderCloseTimeRaw !== '' && preg_match('/^\d{2}:\d{2}$/', $orderCloseTimeRaw)) ? $orderCloseTimeRaw . ':00' : null;
        if ($recurrenceType === 'weekly') {
            $days = array_values(array_intersect(array_map('intval', $_POST['weekly_days'] ?? []), range(1, 7)));
            $recurrenceWeekdays = $days ? implode(',', $days) : null;
            if (!$recurrenceWeekdays) { $error = 'Pick at least one day of the week.'; }
        } elseif ($recurrenceType === 'monthly') {
            $monthlyDay = (int)($_POST['monthly_weekday'] ?? 0);
            $monthlyWeek = (int)($_POST['monthly_week_of_month'] ?? 0);
            if ($monthlyDay < 1 || $monthlyDay > 7 || !in_array($monthlyWeek, [1,2,3,4,-1], true)) {
                $error = 'Pick which weekday and which week of the month.';
            } else {
                $recurrenceWeekdays = (string)$monthlyDay;
                $recurrenceWeekOfMonth = $monthlyWeek;
            }
        }

        if (!$error && $name === '') {
            $error = 'Market name is required.';
        }
        if ($error) {
            // fall through to the generic error display below
        } else {
            if ($id > 0) {
                // Switching a market from manual into a weekly/monthly schedule
                // while it's sitting at 'closed' (the manual default) would
                // otherwise force-shut it under the new override semantics
                // before the admin ever touches the toggle — flip it to 'open'
                // (= "let the schedule decide") in that one case only.
                $existing = $pdo->prepare('SELECT recurrence_type, status FROM markets WHERE id=?');
                $existing->execute([$id]);
                $existing = $existing->fetch();
                $statusUpdate = '';
                $statusParams = [];
                if ($existing && $existing['recurrence_type'] === 'manual' && $recurrenceType !== 'manual' && $existing['status'] === 'closed') {
                    $statusUpdate = ', status=?';
                    $statusParams = ['open'];
                }
                $pdo->prepare("UPDATE markets SET name=?, description=?, schedule_note=?, recurrence_type=?, recurrence_weekdays=?, recurrence_week_of_month=?, preorder_days=?, order_close_time=?, pickup_fee=?, color=?, storehouse_location=?, storehouse_maps_link=?{$statusUpdate}, updated_at=NOW() WHERE id=?")
                    ->execute(array_merge([$name, $description ?: null, $scheduleNote ?: null, $recurrenceType, $recurrenceWeekdays, $recurrenceWeekOfMonth, $preorderDays, $orderCloseTime, $pickupFee, $color, $storehouseLocation ?: null, $storehouseMapsLink ?: null], $statusParams, [$id]));
                log_audit_action($adminUser['id'], 'market_edited', "Edited market #{$id}: '{$name}'");
                flash('Market updated.', 'success');
            } else {
                // Every market gets one hidden "system shop" — not a real
                // storefront (never browsable, never listed, zero products),
                // it exists purely so custom orders for this market can
                // reuse the existing mp_quote_requests → pay_quote.php →
                // mp_orders pipeline, which requires a shop_id. Market women
                // never see or manage it; buyers submit custom orders and an
                // assigned agent prices/fulfills them instead.
                $pdo->beginTransaction();
                try {
                    // For a scheduled market, status='open' just means "let the
                    // computed schedule decide" (see get_market_schedule()) —
                    // it's the correct default so a newly-created weekly/monthly
                    // market isn't force-shut before the admin ever touches the
                    // toggle. Manual markets keep the table's 'closed' default,
                    // requiring the admin to flip it open by hand as before.
                    $initialStatus = $recurrenceType === 'manual' ? 'closed' : 'open';
                    $slug = mp_unique_slug($name, 'markets', 'slug', $pdo);
                    $pdo->prepare('INSERT INTO markets (name, slug, description, schedule_note, recurrence_type, recurrence_weekdays, recurrence_week_of_month, preorder_days, order_close_time, pickup_fee, color, storehouse_location, storehouse_maps_link, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                        ->execute([$name, $slug, $description ?: null, $scheduleNote ?: null, $recurrenceType, $recurrenceWeekdays, $recurrenceWeekOfMonth, $preorderDays, $orderCloseTime, $pickupFee, $color, $storehouseLocation ?: null, $storehouseMapsLink ?: null, $initialStatus]);
                    $marketId = (int)$pdo->lastInsertId();

                    $shopSlug = mp_unique_slug($name . ' custom orders', 'mp_shops', 'slug', $pdo);
                    $pdo->prepare('INSERT INTO mp_shops (user_id, shop_name, slug, market_id, status) VALUES (?,?,?,?,?)')
                        ->execute([$adminUser['id'], $name . ' — Custom Orders', $shopSlug, $marketId, 'active']);

                    $pdo->commit();
                    log_audit_action($adminUser['id'], 'market_created', "Created market: '{$name}'");
                    flash('Market added.', 'success');
                } catch (Exception $e) {
                    $pdo->rollBack();
                    flash('Could not create market — please try again.', 'error');
                }
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
            $activeQuoteCount = $pdo->prepare("SELECT COUNT(*) FROM mp_quote_requests mqr JOIN mp_shops ms ON mqr.shop_id=ms.id WHERE ms.market_id=? AND mqr.status IN ('pending','quoted')");
            $activeQuoteCount->execute([$id]);
            $activeOrderCount = $pdo->prepare("SELECT COUNT(*) FROM mp_orders WHERE market_id=? AND status IN ('pending','confirmed','processing','at_storehouse')");
            $activeOrderCount->execute([$id]);
            if ((int)$activeQuoteCount->fetchColumn() > 0) {
                flash('Cannot delete — this market has custom orders still awaiting pricing or payment.', 'error');
            } elseif ((int)$activeOrderCount->fetchColumn() > 0) {
                flash('Cannot delete — this market has orders still in progress (awaiting storehouse handoff). Wait until they complete first.', 'error');
            } else {
                // The market's system shop (used only as the FK anchor for
                // custom orders) is deleted along with it — it was never a
                // real storefront.
                $pdo->prepare('DELETE FROM mp_shops WHERE market_id=?')->execute([$id]);
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
    } elseif ($action === 'add_delivery_town') {
        $marketId = (int)($_POST['market_id'] ?? 0);
        $townId   = (int)($_POST['town_id'] ?? 0);
        $fee      = max(0, (float)($_POST['delivery_fee'] ?? 0));
        if ($marketId && $townId) {
            try {
                $pdo->prepare('INSERT INTO market_delivery_towns (market_id, town_id, delivery_fee) VALUES (?,?,?)')
                    ->execute([$marketId, $townId, $fee]);
                log_audit_action($adminUser['id'], 'market_delivery_town_added', "Added delivery town #{$townId} to market #{$marketId} at GH₵{$fee}");
                flash('Delivery town added.', 'success');
            } catch (PDOException $e) {
                flash((int)$e->errorInfo[1] === 1062 ? 'That town is already priced for this market — edit its fee below instead.' : 'Could not add that town.', 'error');
            }
        }
        header('Location: markets.php?manage=' . $marketId . '#towns-panel'); exit;
    } elseif ($action === 'update_delivery_town') {
        $rowId    = (int)($_POST['row_id'] ?? 0);
        $marketId = (int)($_POST['market_id'] ?? 0);
        $fee      = max(0, (float)($_POST['delivery_fee'] ?? 0));
        $pdo->prepare('UPDATE market_delivery_towns SET delivery_fee=?, updated_at=NOW() WHERE id=? AND market_id=?')->execute([$fee, $rowId, $marketId]);
        log_audit_action($adminUser['id'], 'market_delivery_town_updated', "Updated delivery town #{$rowId} on market #{$marketId} to GH₵{$fee}");
        flash('Delivery fee updated.', 'success');
        header('Location: markets.php?manage=' . $marketId . '#towns-panel'); exit;
    } elseif ($action === 'toggle_delivery_town') {
        $rowId    = (int)($_POST['row_id'] ?? 0);
        $marketId = (int)($_POST['market_id'] ?? 0);
        $row = $pdo->prepare('SELECT status FROM market_delivery_towns WHERE id=? AND market_id=?');
        $row->execute([$rowId, $marketId]); $row = $row->fetch();
        if ($row) {
            $newStatus = $row['status'] === 'active' ? 'inactive' : 'active';
            $pdo->prepare('UPDATE market_delivery_towns SET status=?, updated_at=NOW() WHERE id=?')->execute([$newStatus, $rowId]);
            log_audit_action($adminUser['id'], 'market_delivery_town_toggled', "Set delivery town #{$rowId} on market #{$marketId} to {$newStatus}");
            flash('Delivery town ' . ($newStatus === 'active' ? 'enabled' : 'disabled') . '.', 'success');
        }
        header('Location: markets.php?manage=' . $marketId . '#towns-panel'); exit;
    } elseif ($action === 'remove_delivery_town') {
        $rowId    = (int)($_POST['row_id'] ?? 0);
        $marketId = (int)($_POST['market_id'] ?? 0);
        $pdo->prepare('DELETE FROM market_delivery_towns WHERE id=? AND market_id=?')->execute([$rowId, $marketId]);
        log_audit_action($adminUser['id'], 'market_delivery_town_removed', "Removed delivery town #{$rowId} from market #{$marketId}");
        flash('Delivery town removed.', 'success');
        header('Location: markets.php?manage=' . $marketId . '#towns-panel'); exit;
    }
}

$markets = $pdo->query(
    "SELECT m.*,
        (SELECT COUNT(*) FROM mp_quote_requests mqr JOIN mp_shops s ON mqr.shop_id=s.id WHERE s.market_id = m.id AND mqr.status='pending') AS pending_orders_count
     FROM markets m ORDER BY m.name"
)->fetchAll();

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

// Delivery-towns panel — this market's own priced towns, plus every town
// not yet priced here (for the "add" dropdown).
$deliveryTowns = [];
$availableTowns = [];
if ($manageMarket) {
    $dtStmt = $pdo->prepare(
        "SELECT mdt.id, mdt.town_id, mdt.delivery_fee, mdt.status, t.name, t.district
         FROM market_delivery_towns mdt JOIN towns t ON mdt.town_id = t.id
         WHERE mdt.market_id = ? ORDER BY t.district, t.name"
    );
    $dtStmt->execute([$manageMarket['id']]);
    $deliveryTowns = $dtStmt->fetchAll();

    $usedTownIds = array_column($deliveryTowns, 'town_id');
    $availStmt = $pdo->prepare(
        "SELECT id, name, district FROM towns
         WHERE NOT (name='Other' AND district='Other')" .
        ($usedTownIds ? ' AND id NOT IN (' . implode(',', array_fill(0, count($usedTownIds), '?')) . ')' : '') .
        " ORDER BY district, name"
    );
    $availStmt->execute($usedTownIds);
    $availableTowns = $availStmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nearby Markets — Admin</title>
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
    <h1 style="margin:0;font-size:1rem;font-weight:800;">🏬 Nearby Markets</h1>
    <a href="market_orders.php" class="button button-primary button-small">📝 Custom Orders</a>
    <a href="market_deliveries.php" class="button button-primary button-small">📦 Storehouse Deliveries</a>
    <a href="market_settings.php" class="button button-secondary button-small">⚙️ Settings</a>
</header>

<main class="mk-shell">

    <?php if ($flash): ?>
    <div class="alert alert-<?php echo sanitize($flash['type']); ?>" style="margin-bottom:14px;"><?php echo sanitize($flash['message']); ?></div>
    <?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error" style="margin-bottom:14px;"><?php echo sanitize($error); ?></div><?php endif; ?>

    <p style="font-size:.84rem;color:var(--text-muted,#6b7280);margin-bottom:16px;">
        Each market is a recurring scheduled market day (Ofie Market, Nkurakan Market, etc.).
        Buyers send a <strong>custom order</strong> (a shopping list) for what they want from the
        market; an assigned manager prices it. Set a <strong>weekly or monthly recurrence</strong> and
        an order window below and everything — when buyers can submit, when they can pay — is
        computed automatically per market; markets left on "Manual" keep the old plain Open/Closed
        toggle instead. Assign a manager per market to price custom orders and handle the storehouse
        handoff after purchases.
    </p>

    <div class="mk-card">
        <?php if (!$markets): ?>
            <p style="text-align:center;color:var(--text-muted,#6b7280);padding:20px;">No markets yet — add one below.</p>
        <?php else: ?>
        <table class="mk-table">
            <thead><tr><th>Market</th><th>Schedule</th><th>Pending Orders</th><th>Managers</th><th>Status</th><th style="text-align:right;">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($markets as $m): $sched = get_market_schedule($m); ?>
            <tr>
                <td>
                    <span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:<?php echo sanitize($m['color'] ?: '#2f8f5b'); ?>;margin-right:6px;vertical-align:middle;"></span>
                    <strong><?php echo sanitize($m['name']); ?></strong>
                    <?php if ($m['storehouse_location']): ?><br><span style="font-size:.72rem;color:var(--text-muted,#6b7280);margin-left:18px;">📍 <?php echo sanitize($m['storehouse_location']); ?></span><?php endif; ?>
                </td>
                <td>
                    <?php if ($sched['is_scheduled']): ?>
                        <?php echo sanitize(market_schedule_label($m)); ?>
                        <?php if ($sched['next_market_date']): ?><br><span style="font-size:.72rem;color:var(--text-muted,#6b7280);">Next: <?php echo $sched['next_market_date']->format('D, j M'); ?> · orders from <?php echo $sched['preorder_starts_at']->format('j M'); ?><?php if ($m['order_close_time']): ?> · orders close <?php echo date('g:ia', strtotime($m['order_close_time'])); ?><?php endif; ?></span><?php endif; ?>
                    <?php else: ?>
                        <?php echo $m['schedule_note'] ? sanitize($m['schedule_note']) : '<span style="color:var(--text-muted,#6b7280);">—</span>'; ?>
                    <?php endif; ?>
                </td>
                <td><?php echo (int)$m['pending_orders_count']; ?></td>
                <td><?php echo isset($managersByMarket[$m['id']]) ? count($managersByMarket[$m['id']]) . ' assigned' : '<span style="color:var(--text-muted,#6b7280);">None</span>'; ?></td>
                <td>
                    <?php if (!$sched['is_scheduled']): ?>
                    <span class="mk-badge <?php echo $m['status']; ?>"><?php echo ucfirst($m['status']); ?></span>
                    <?php elseif ($m['status'] === 'closed'): ?>
                    <span class="mk-badge closed">Force-Closed</span>
                    <?php elseif ($sched['is_payment_open']): ?>
                    <span class="mk-badge open">Payment Open</span>
                    <?php elseif ($sched['is_market_day']): ?>
                    <span class="mk-badge closed" style="background:#f3f4f6;color:#6b7280;">Closed for Today</span>
                    <?php else: ?>
                    <span class="mk-badge closed" style="background:#f3f4f6;color:#6b7280;">Upcoming</span>
                    <?php endif; ?>
                </td>
                <td style="text-align:right;white-space:nowrap;">
                    <a href="markets.php?manage=<?php echo (int)$m['id']; ?>#mgr-panel" class="button button-secondary button-small">Manage</a>
                    <button type="button" class="button button-secondary button-small" onclick='editMarket(<?php echo json_encode($m); ?>)'>Edit</button>
                    <form method="post" style="display:inline;">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="toggle_status">
                        <input type="hidden" name="id" value="<?php echo (int)$m['id']; ?>">
                        <button type="submit" class="button button-small" style="background:<?php echo $m['status']==='open' ? '#ef4444' : '#10b981'; ?>;color:#fff;border-color:transparent;">
                            <?php if ($sched['is_scheduled']): ?>
                                <?php echo $m['status'] === 'open' ? 'Force Close' : 'Resume Schedule'; ?>
                            <?php else: ?>
                                <?php echo $m['status'] === 'open' ? 'Close' : 'Open'; ?>
                            <?php endif; ?>
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
                    <label>Storehouse Location</label>
                    <input type="text" name="storehouse_location" id="f_location" placeholder="e.g. Ofie Market Storehouse, near the lorry station">
                </div>
                <div>
                    <label>Storehouse Google Maps Link</label>
                    <input type="url" name="storehouse_maps_link" id="f_maps" placeholder="https://maps.google.com/…">
                </div>
                <div>
                    <label>Storehouse Pickup Fee (GH₵)</label>
                    <input type="number" name="pickup_fee" id="f_pickup_fee" min="0" step="0.01" value="0">
                </div>
                <div>
                    <label>Card Colour</label>
                    <input type="color" name="color" id="f_color" value="#2f8f5b" style="width:100%;height:38px;padding:2px;cursor:pointer;">
                </div>
            </div>
            <p class="form-hint" style="margin-top:6px;">Home delivery pricing per town is set separately, once the market is saved — use "Manage" on the list below.</p>

            <div class="form-group" style="margin-top:12px;">
                <label>Recurrence</label>
                <select name="recurrence_type" id="f_recurrence_type" onchange="updateRecurrenceUI()" style="width:100%;max-width:320px;padding:7px 9px;border:1px solid var(--border);border-radius:8px;font-size:.84rem;">
                    <option value="manual">Manual (I'll flip Open/Closed myself)</option>
                    <option value="weekly">Weekly — recurs on specific day(s) every week</option>
                    <option value="monthly">Monthly — recurs on one weekday once a month</option>
                </select>
            </div>

            <div id="f_manual_panel" class="form-group">
                <label>Schedule note (shown to buyers)</label>
                <input type="text" name="schedule_note" id="f_schedule" placeholder="e.g. Every first Saturday of the month">
            </div>

            <div id="f_weekly_panel" class="form-group" style="display:none;">
                <label>Which day(s) of the week?</label>
                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    <?php $wdNames = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun']; foreach ($wdNames as $i => $wn): ?>
                    <label style="display:flex;align-items:center;gap:4px;font-weight:400;font-size:.82rem;">
                        <input type="checkbox" name="weekly_days[]" value="<?php echo $i + 1; ?>" class="f-weekly-day"> <?php echo $wn; ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div id="f_monthly_panel" class="form-group" style="display:none;">
                <label>Which week &amp; weekday of the month?</label>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <select name="monthly_week_of_month" id="f_monthly_week" style="padding:7px 9px;border:1px solid var(--border);border-radius:8px;font-size:.84rem;">
                        <option value="1">First</option>
                        <option value="2">Second</option>
                        <option value="3">Third</option>
                        <option value="4">Fourth</option>
                        <option value="-1">Last</option>
                    </select>
                    <select name="monthly_weekday" id="f_monthly_weekday" style="padding:7px 9px;border:1px solid var(--border);border-radius:8px;font-size:.84rem;">
                        <?php foreach (['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'] as $i => $wn): ?>
                        <option value="<?php echo $i + 1; ?>"><?php echo $wn; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span style="align-self:center;font-size:.82rem;color:var(--text-muted,#6b7280);">of the month</span>
                </div>
            </div>

            <div id="f_preorder_panel" class="form-group" style="display:none;max-width:320px;">
                <label>Order window (days before market day)</label>
                <input type="number" name="preorder_days" id="f_preorder_days" min="0" max="60" value="0">
                <p class="form-hint">Buyers can start sending custom orders this many days before the computed market day, and can pay any time from then through market day itself.</p>
            </div>

            <div id="f_close_panel" class="form-group" style="display:none;max-width:320px;">
                <label>Order cutoff time on market day (optional)</label>
                <input type="time" name="order_close_time" id="f_order_close_time">
                <p class="form-hint">Once market day starts, orders stop taking new payments at this time. Leave blank to keep accepting payment all day.</p>
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

    <div class="mk-card" id="towns-panel">
        <h2 style="margin-top:0;font-size:1rem;">Delivery Towns — <?php echo sanitize($manageMarket['name']); ?></h2>
        <p style="font-size:.84rem;color:var(--text-muted,#6b7280);">
            Buyers can choose home delivery to any town priced here instead of storehouse pickup — the
            assigned manager delivers it personally, same as they already handle the storehouse handoff.
            Untick "Active" to hide a town from checkout without losing its price.
        </p>

        <?php if (!$deliveryTowns): ?>
        <p style="font-size:.84rem;color:var(--text-muted,#6b7280);">No delivery towns priced yet — home delivery isn't offered for this market until you add one below.</p>
        <?php else: ?>
        <table class="mk-table">
            <thead><tr><th>Town</th><th>District</th><th>Fee (GH₵)</th><th>Active</th><th style="text-align:right;">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($deliveryTowns as $dt): ?>
            <tr>
                <td><?php echo sanitize($dt['name']); ?></td>
                <td><?php echo sanitize($dt['district']); ?></td>
                <td>
                    <form method="post" style="display:flex;gap:6px;align-items:center;">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="update_delivery_town">
                        <input type="hidden" name="row_id" value="<?php echo (int)$dt['id']; ?>">
                        <input type="hidden" name="market_id" value="<?php echo (int)$manageMarket['id']; ?>">
                        <input type="number" name="delivery_fee" min="0" step="0.01" value="<?php echo sanitize($dt['delivery_fee']); ?>" style="width:90px;padding:5px 7px;border:1px solid var(--border);border-radius:6px;font-size:.82rem;">
                        <button type="submit" class="button button-secondary button-small">Save</button>
                    </form>
                </td>
                <td>
                    <form method="post" style="display:inline;">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="toggle_delivery_town">
                        <input type="hidden" name="row_id" value="<?php echo (int)$dt['id']; ?>">
                        <input type="hidden" name="market_id" value="<?php echo (int)$manageMarket['id']; ?>">
                        <button type="submit" class="button button-small" style="background:<?php echo $dt['status']==='active'?'#10b981':'#6b7280'; ?>;color:#fff;border-color:transparent;"><?php echo $dt['status']==='active'?'Active':'Inactive'; ?></button>
                    </form>
                </td>
                <td style="text-align:right;">
                    <form method="post" style="display:inline;" onsubmit="return confirm('Remove <?php echo sanitize(addslashes($dt['name'])); ?> as a delivery town?');">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="remove_delivery_town">
                        <input type="hidden" name="row_id" value="<?php echo (int)$dt['id']; ?>">
                        <input type="hidden" name="market_id" value="<?php echo (int)$manageMarket['id']; ?>">
                        <button type="submit" class="button button-secondary button-small" style="color:#c0392b;">Remove</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <?php if ($availableTowns): ?>
        <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:12px;">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="add_delivery_town">
            <input type="hidden" name="market_id" value="<?php echo (int)$manageMarket['id']; ?>">
            <select name="town_id" required style="padding:7px 9px;border:1px solid var(--border);border-radius:8px;font-size:.84rem;">
                <option value="">Select a town…</option>
                <?php foreach ($availableTowns as $t): ?>
                <option value="<?php echo $t['id']; ?>"><?php echo sanitize($t['name']); ?>, <?php echo sanitize($t['district']); ?></option>
                <?php endforeach; ?>
            </select>
            <input type="number" name="delivery_fee" min="0" step="0.01" placeholder="Fee (GH₵)" required style="width:120px;padding:7px 9px;border:1px solid var(--border);border-radius:8px;font-size:.84rem;">
            <button type="submit" class="button button-primary button-small">+ Add Town</button>
        </form>
        <?php else: ?>
        <p style="font-size:.8rem;color:var(--text-muted,#6b7280);margin-top:10px;">Every town is already priced for this market. Need one that's not in the list? Add it via <a href="towns.php">Towns</a> first.</p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

</main>

<script>
function updateRecurrenceUI() {
    var type = document.getElementById('f_recurrence_type').value;
    document.getElementById('f_manual_panel').style.display   = type === 'manual'  ? '' : 'none';
    document.getElementById('f_weekly_panel').style.display   = type === 'weekly'  ? '' : 'none';
    document.getElementById('f_monthly_panel').style.display  = type === 'monthly' ? '' : 'none';
    document.getElementById('f_preorder_panel').style.display = (type === 'weekly' || type === 'monthly') ? '' : 'none';
    document.getElementById('f_close_panel').style.display    = (type === 'weekly' || type === 'monthly') ? '' : 'none';
}
function editMarket(m) {
    document.getElementById('mk-form-heading').textContent = 'Edit Market — ' + m.name;
    document.getElementById('f_id').value = m.id;
    document.getElementById('f_name').value = m.name;
    document.getElementById('f_schedule').value = m.schedule_note || '';
    document.getElementById('f_location').value = m.storehouse_location || '';
    document.getElementById('f_maps').value = m.storehouse_maps_link || '';
    document.getElementById('f_pickup_fee').value = m.pickup_fee || 0;
    document.getElementById('f_color').value = m.color || '#2f8f5b';
    document.getElementById('f_description').value = m.description || '';
    document.getElementById('f_recurrence_type').value = m.recurrence_type || 'manual';
    document.getElementById('f_preorder_days').value = m.preorder_days || 0;
    document.getElementById('f_order_close_time').value = m.order_close_time ? m.order_close_time.substring(0, 5) : '';

    document.querySelectorAll('.f-weekly-day').forEach(function (cb) { cb.checked = false; });
    document.getElementById('f_monthly_week').value = '1';
    document.getElementById('f_monthly_weekday').value = '1';
    var days = (m.recurrence_weekdays || '').split(',').filter(Boolean);
    if (m.recurrence_type === 'weekly') {
        days.forEach(function (d) {
            var cb = document.querySelector('.f-weekly-day[value="' + d + '"]');
            if (cb) cb.checked = true;
        });
    } else if (m.recurrence_type === 'monthly') {
        if (days[0]) document.getElementById('f_monthly_weekday').value = days[0];
        if (m.recurrence_week_of_month) document.getElementById('f_monthly_week').value = m.recurrence_week_of_month;
    }

    updateRecurrenceUI();
    document.getElementById('mk-form').scrollIntoView({ behavior: 'smooth' });
}
function resetForm() {
    document.getElementById('mk-form').reset();
    document.getElementById('f_id').value = 0;
    document.getElementById('mk-form-heading').textContent = 'Add Market';
    updateRecurrenceUI();
}
updateRecurrenceUI();
</script>

</body>
</html>
