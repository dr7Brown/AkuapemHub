<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';

require_login();
if (!is_admin_or_manager()) { header('Location: index.php'); exit; }

$adminUser = current_user();
$isAdmin   = is_admin();

// Managers need the permission at all; admins always pass (require_mod_permission no-ops for them).
if (!$isAdmin) require_mod_permission('manage_market_deliveries');


// Which markets this user may act on — admins manage all, managers only their assignments.
$managedMarketIds = $isAdmin
    ? array_column($pdo->query('SELECT id FROM markets')->fetchAll(), 'id')
    : get_managed_market_ids((int)$adminUser['id']);

$flash = get_flash();
$noMarketsAssigned = !$managedMarketIds;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action  = $_POST['action'] ?? '';
    $orderId = (int)($_POST['order_id'] ?? 0);

    // Verify the order belongs to a market this user is allowed to manage.
    // Uses the order's own snapshotted market_id (set at checkout), not the
    // shop's current one — a seller changing/clearing their shop's market
    // later must not orphan or reassign orders already placed under it.
    $ordSt = $pdo->prepare(
        "SELECT o.id, o.status, o.shop_id, o.market_id, s.shop_name, m.name AS market_name
         FROM mp_orders o JOIN mp_shops s ON o.shop_id = s.id LEFT JOIN markets m ON o.market_id = m.id
         WHERE o.id = ?"
    );
    $ordSt->execute([$orderId]);
    $order = $ordSt->fetch();

    if (!$order || !$order['market_id'] || !user_can_manage_market((int)$adminUser['id'], (int)$order['market_id'])) {
        flash('You are not assigned to manage this market.', 'error');
    } elseif ($action === 'accept' && $order['status'] === 'pending') {
        // Market orders have no seller to click "Accept Order" the way a
        // regular shop's owner would — the assigned agent does it here instead.
        $pdo->prepare("UPDATE mp_orders SET status='processing', updated_at=NOW() WHERE id=?")->execute([$orderId]);
        log_audit_action($adminUser['id'], 'market_order_accepted', "Order #{$orderId} ({$order['shop_name']}, {$order['market_name']}) accepted");
        flash('Order accepted.', 'success');
    } elseif ($action === 'mark_at_storehouse' && $order['status'] === 'processing') {
        $pdo->prepare("UPDATE mp_orders SET status='at_storehouse', updated_at=NOW() WHERE id=?")->execute([$orderId]);
        log_audit_action($adminUser['id'], 'market_order_at_storehouse', "Order #{$orderId} ({$order['shop_name']}, {$order['market_name']}) marked received at storehouse");
        flash('Order marked as received at the storehouse.', 'success');
    } elseif ($action === 'mark_delivered' && $order['status'] === 'at_storehouse') {
        $confirmDays = (int)get_platform_setting('market_payout_confirmation_days', get_platform_setting('mp_payout_confirmation_days', 3));
        $pdo->prepare("UPDATE mp_orders SET status='delivered', payout_release_at=NOW() + INTERVAL ? DAY, updated_at=NOW() WHERE id=?")
            ->execute([$confirmDays, $orderId]);
        log_audit_action($adminUser['id'], 'market_order_delivered', "Order #{$orderId} ({$order['shop_name']}, {$order['market_name']}) marked handed to buyer");
        flash('Order marked as handed to the buyer.', 'success');
    } else {
        flash('That order is not in a state this action applies to.', 'error');
    }
    header('Location: market_deliveries.php' . (!empty($_GET['market']) ? '?market=' . (int)$_GET['market'] : ''));
    exit;
}

// Market filter (only among markets this user manages)
$filterMarketId = (int)($_GET['market'] ?? 0);
if ($filterMarketId && !in_array($filterMarketId, $managedMarketIds, true)) $filterMarketId = 0;

$markets = [];
$orders  = [];
if (!$noMarketsAssigned) {
    $markets = $pdo->query(
        'SELECT id, name FROM markets WHERE id IN (' . implode(',', array_map('intval', $managedMarketIds)) . ') ORDER BY name'
    )->fetchAll();

    // 'pending' is included so agents can accept a just-paid order (no seller
    // exists to do it for them) — gated on payment_status='paid' so an
    // abandoned/unpaid checkout never shows up here.
    $where  = ["o.market_id IN (" . implode(',', array_map('intval', $managedMarketIds)) . ")", "o.status IN ('pending','processing','at_storehouse')", "o.payment_status='paid'"];
    $params = [];
    if ($filterMarketId) { $where[] = 'o.market_id = ?'; $params[] = $filterMarketId; }

    $orders = $pdo->prepare(
        "SELECT o.*, s.shop_name, m.name AS market_name, m.storehouse_location, m.storehouse_maps_link, u.name AS buyer_name, u.phone AS buyer_phone,
                t.name AS delivery_town_name, t.district AS delivery_town_district
         FROM mp_orders o
         JOIN mp_shops s ON o.shop_id = s.id
         LEFT JOIN markets m ON o.market_id = m.id
         JOIN users u ON o.customer_id = u.id
         LEFT JOIN towns t ON o.delivery_town_id = t.id
         WHERE " . implode(' AND ', $where) . "
         ORDER BY FIELD(o.status,'pending','at_storehouse','processing'), o.created_at ASC"
    );
    $orders->execute($params);
    $orders = $orders->fetchAll();
}

$itemsByOrder = [];
if ($orders) {
    $orderIds = array_column($orders, 'id');
    $itSt = $pdo->prepare('SELECT * FROM mp_order_items WHERE order_id IN (' . implode(',', array_fill(0, count($orderIds), '?')) . ')');
    $itSt->execute($orderIds);
    foreach ($itSt->fetchAll() as $it) { $itemsByOrder[$it['order_id']][] = $it; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Storehouse Deliveries — Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .md-shell { max-width: 900px; margin: 0 auto; padding: 18px 16px 60px; }
        .md-card  { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; padding: 16px 18px; margin-bottom: 12px; }
        .md-head  { display:flex; justify-content:space-between; align-items:flex-start; gap:10px; flex-wrap:wrap; }
        .md-badge { display:inline-block; padding:2px 9px; border-radius:20px; font-size:.68rem; font-weight:800; }
        .md-badge.pending       { background:#fee2e2; color:#c0392b; }
        .md-badge.processing    { background:#fef3c7; color:#92400e; }
        .md-badge.at_storehouse { background:#dbeafe; color:#1e40af; }
        .md-items { font-size:.82rem; color:var(--text-muted,#6b7280); margin:8px 0; }
        .md-item-row { display:flex; justify-content:space-between; padding:2px 0; }
        .md-empty { text-align:center; padding:50px 20px; color:var(--text-muted,#6b7280); }
    </style>
</head>
<body>

<header class="topbar">
    <a href="markets.php" class="button button-secondary button-small">← Markets</a>
    <h1 style="margin:0;font-size:1rem;font-weight:800;">📦 Storehouse Deliveries</h1>
</header>

<main class="md-shell">

    <?php if ($flash): ?>
    <div class="alert alert-<?php echo sanitize($flash['type']); ?>" style="margin-bottom:14px;"><?php echo sanitize($flash['message']); ?></div>
    <?php endif; ?>

    <p style="font-size:.84rem;color:var(--text-muted,#6b7280);margin-bottom:14px;">
        Orders paid for at a market you manage. <strong>Accept</strong> a newly-paid order, mark
        <strong>Received at Storehouse</strong> once it's dropped off, then mark it
        <strong>handed over</strong> once the buyer has it — pickup or delivery, whichever they chose —
        which starts the payout confirmation window.
    </p>

    <?php if ($noMarketsAssigned): ?>
    <div class="md-empty">
        <div style="font-size:2.5rem;opacity:.35;margin-bottom:10px;">🏬</div>
        <p style="margin:0;font-weight:700;">You're not assigned to manage any market yet</p>
        <p style="margin:6px 0 0;font-size:.85rem;">Ask an admin to assign you via <a href="markets.php">Nearby Markets</a>.</p>
    </div>
    <?php else: ?>

    <?php if (count($markets) > 1): ?>
    <form method="get" style="margin-bottom:14px;display:flex;gap:8px;align-items:center;">
        <label style="font-size:.82rem;font-weight:600;">Market:</label>
        <select name="market" onchange="this.form.submit()" style="padding:6px 10px;border:1px solid var(--border);border-radius:8px;font-size:.84rem;">
            <option value="">All assigned markets</option>
            <?php foreach ($markets as $m): ?>
            <option value="<?php echo $m['id']; ?>" <?php echo $filterMarketId===(int)$m['id']?'selected':''; ?>><?php echo sanitize($m['name']); ?></option>
            <?php endforeach; ?>
        </select>
    </form>
    <?php endif; ?>

    <?php if (!$orders): ?>
    <div class="md-empty">
        <div style="font-size:2.5rem;opacity:.35;margin-bottom:10px;">📦</div>
        <p style="margin:0;font-weight:700;">No orders need action right now</p>
    </div>
    <?php else: ?>
    <?php foreach ($orders as $o): ?>
    <div class="md-card">
        <div class="md-head">
            <div>
                <strong>Order #<?php echo (int)$o['id']; ?></strong> — <?php echo sanitize($o['shop_name']); ?>
                <?php if (count($markets) !== 1): ?><span style="color:var(--text-muted,#6b7280);font-size:.78rem;"> · <?php echo sanitize($o['market_name']); ?></span><?php endif; ?>
                <div style="font-size:.82rem;color:var(--text-muted,#6b7280);margin-top:2px;">
                    Buyer: <?php echo sanitize($o['buyer_name']); ?><?php echo $o['buyer_phone'] ? ' · ' . sanitize($o['buyer_phone']) : ''; ?>
                </div>
            </div>
            <span class="md-badge <?php echo $o['status']; ?>"><?php echo ['pending'=>'New — Needs Acceptance','processing'=>'Awaiting Drop-off','at_storehouse'=>'At Storehouse'][$o['status']]; ?></span>
        </div>
        <?php if ($o['fulfillment_method'] === 'delivery'): ?>
        <div style="font-size:.82rem;padding:6px 9px;margin-top:6px;background:#dbeafe;border-radius:6px;color:#1e40af;">
            🚚 <strong>Deliver to <?php echo sanitize($o['delivery_town_name']); ?>, <?php echo sanitize($o['delivery_town_district']); ?></strong>
            <?php if ($o['delivery_address']): ?><br><?php echo sanitize($o['delivery_address']); ?><?php endif; ?>
            <?php if ($o['delivery_maps_link']): ?> — <a href="<?php echo sanitize($o['delivery_maps_link']); ?>" target="_blank" rel="noopener">Map</a><?php endif; ?>
        </div>
        <?php else: ?>
        <div style="font-size:.82rem;padding:6px 9px;margin-top:6px;background:var(--surface-muted,#f8fafc);border-radius:6px;color:var(--text-muted,#6b7280);">
            🏬 Storehouse pickup — buyer collects in person.
        </div>
        <?php endif; ?>
        <div class="md-items">
            <?php foreach ($itemsByOrder[$o['id']] ?? [] as $it): ?>
            <div class="md-item-row"><span><?php echo sanitize($it['product_name']); ?> × <?php echo (int)$it['quantity']; ?></span><span>GH₵ <?php echo number_format((float)$it['subtotal'], 2); ?></span></div>
            <?php endforeach; ?>
            <?php if ((float)$o['delivery_fee'] > 0): ?>
            <div class="md-item-row"><span><?php echo $o['fulfillment_method']==='delivery'?'Delivery Fee':'Pickup Fee'; ?></span><span>GH₵ <?php echo number_format((float)$o['delivery_fee'], 2); ?></span></div>
            <?php endif; ?>
            <?php if ((float)$o['system_charge'] > 0): ?>
            <div class="md-item-row"><span>System Charge</span><span>GH₵ <?php echo number_format((float)$o['system_charge'], 2); ?></span></div>
            <?php endif; ?>
        </div>
        <div style="display:flex;gap:8px;margin-top:10px;">
            <?php if ($o['status'] === 'pending'): ?>
            <form method="post" style="display:inline;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="accept">
                <input type="hidden" name="order_id" value="<?php echo (int)$o['id']; ?>">
                <button type="submit" class="button button-primary button-small">✅ Accept Order</button>
            </form>
            <?php elseif ($o['status'] === 'processing'): ?>
            <form method="post" style="display:inline;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="mark_at_storehouse">
                <input type="hidden" name="order_id" value="<?php echo (int)$o['id']; ?>">
                <button type="submit" class="button button-primary button-small">✅ Received at Storehouse</button>
            </form>
            <?php elseif ($o['status'] === 'at_storehouse'): ?>
            <form method="post" style="display:inline;" onsubmit="return confirm('<?php echo $o['fulfillment_method']==='delivery' ? 'Confirm this order has been delivered to the buyer?' : 'Confirm this order has been handed to the buyer?'; ?>');">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="mark_delivered">
                <input type="hidden" name="order_id" value="<?php echo (int)$o['id']; ?>">
                <button type="submit" class="button button-primary button-small" style="background:#10b981;border-color:transparent;"><?php echo $o['fulfillment_method']==='delivery' ? '🚚 Delivered to Buyer' : '📬 Handed to Buyer'; ?></button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <?php endif; ?>

</main>
</body>
</html>
