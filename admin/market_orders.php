<?php
/**
 * Agents price the custom orders (shopping lists) buyers send in for a
 * periodic market — same pricing lifecycle as a seller's Quote Requests tab
 * in seller_dashboard.php, just scoped to the market(s) this admin/manager
 * is assigned to instead of a shop they own (see request_market_order.php
 * and the "system shop" trick it relies on).
 */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';

require_login();
if (!is_admin_or_manager()) { header('Location: index.php'); exit; }

$adminUser = current_user();
$isAdmin   = is_admin();

if (!$isAdmin) require_mod_permission('manage_market_deliveries');

$managedMarketIds = $isAdmin
    ? array_column($pdo->query('SELECT id FROM markets')->fetchAll(), 'id')
    : get_managed_market_ids((int)$adminUser['id']);

$flash = get_flash();
$noMarketsAssigned = !$managedMarketIds;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action  = $_POST['action'] ?? '';
    $quoteId = (int)($_POST['quote_id'] ?? 0);

    $qrSt = $pdo->prepare(
        "SELECT mqr.*, s.market_id, m.name AS market_name
         FROM mp_quote_requests mqr
         JOIN mp_shops s ON mqr.shop_id = s.id
         LEFT JOIN markets m ON s.market_id = m.id
         WHERE mqr.id = ? AND mqr.status = 'pending'"
    );
    $qrSt->execute([$quoteId]);
    $qr = $qrSt->fetch();

    if (!$qr || !$qr['market_id'] || !user_can_manage_market((int)$adminUser['id'], (int)$qr['market_id'])) {
        flash('You are not assigned to manage this market.', 'error');
    } elseif ($action === 'price') {
        $itemsStmt = $pdo->prepare('SELECT * FROM mp_quote_request_items WHERE quote_request_id=?');
        $itemsStmt->execute([$quoteId]);
        $qrItems = $itemsStmt->fetchAll();

        $prices      = $_POST['price'] ?? [];
        $unavailable = array_map('intval', $_POST['unavailable'] ?? []);
        $total       = 0;
        $anyPriced   = false;

        $updStmt = $pdo->prepare('UPDATE mp_quote_request_items SET price=?, is_available=? WHERE id=? AND quote_request_id=?');
        foreach ($qrItems as $item) {
            $isAvailable = !in_array((int)$item['id'], $unavailable, true);
            $price = isset($prices[$item['id']]) ? (float)$prices[$item['id']] : null;
            if ($isAvailable && $price > 0) {
                $anyPriced = true;
                $total += $price;
            } elseif (!$isAvailable) {
                $price = null;
            }
            $updStmt->execute([$price, $isAvailable ? 1 : 0, $item['id'], $quoteId]);
        }

        if (!$anyPriced) {
            flash('Enter a price for at least one available item.', 'error');
        } else {
            $pdo->prepare("UPDATE mp_quote_requests SET status='quoted', total_amount=?, quoted_at=NOW() WHERE id=?")
                ->execute([$total, $quoteId]);
            notify_user((int)$qr['customer_id'], 'Your Quote is Ready 💰',
                $qr['market_name'] . ' priced your shopping list — total GH₵ ' . number_format($total, 2) . '. Review and pay to confirm your order.',
                'success', 'orders.php?view=quotes');
            log_audit_action($adminUser['id'], 'market_order_quoted', "Quote #{$quoteId} ({$qr['market_name']}) priced at GH₵" . number_format($total, 2));
            flash('Quote sent to the buyer.', 'success');
        }
    } elseif ($action === 'decline') {
        $reason = trim($_POST['decline_reason'] ?? '');
        $pdo->prepare("UPDATE mp_quote_requests SET status='declined', decline_reason=? WHERE id=?")
            ->execute([$reason ?: null, $quoteId]);
        notify_user((int)$qr['customer_id'], 'Custom Order Declined',
            $qr['market_name'] . ' could not fulfil your shopping list.' . ($reason ? ' Reason: ' . $reason : ''),
            'info', 'orders.php?view=quotes');
        log_audit_action($adminUser['id'], 'market_order_declined', "Quote #{$quoteId} ({$qr['market_name']}) declined");
        flash('Request declined.', 'info');
    } else {
        flash('That request is not in a state this action applies to.', 'error');
    }
    header('Location: market_orders.php' . (!empty($_GET['market']) ? '?market=' . (int)$_GET['market'] : ''));
    exit;
}

$filterMarketId = (int)($_GET['market'] ?? 0);
if ($filterMarketId && !in_array($filterMarketId, $managedMarketIds, true)) $filterMarketId = 0;

$markets = [];
$quoteRequests = [];
if (!$noMarketsAssigned) {
    $markets = $pdo->query(
        'SELECT id, name FROM markets WHERE id IN (' . implode(',', array_map('intval', $managedMarketIds)) . ') ORDER BY name'
    )->fetchAll();

    $where  = ["s.market_id IN (" . implode(',', array_map('intval', $managedMarketIds)) . ")"];
    $params = [];
    if ($filterMarketId) { $where[] = 's.market_id = ?'; $params[] = $filterMarketId; }

    $quoteRequests = $pdo->prepare(
        "SELECT mqr.*, u.name AS customer_name, u.phone AS customer_phone, m.name AS market_name
         FROM mp_quote_requests mqr
         JOIN mp_shops s ON mqr.shop_id = s.id
         JOIN users u ON mqr.customer_id = u.id
         LEFT JOIN markets m ON s.market_id = m.id
         WHERE " . implode(' AND ', $where) . "
         ORDER BY FIELD(mqr.status,'pending','quoted','declined','cancelled','expired','paid'), mqr.created_at DESC
         LIMIT 60"
    );
    $quoteRequests->execute($params);
    $quoteRequests = $quoteRequests->fetchAll();
}

$quoteItems = [];
if ($quoteRequests) {
    $qrIds = implode(',', array_column($quoteRequests, 'id'));
    $qriStmt = $pdo->query("SELECT * FROM mp_quote_request_items WHERE quote_request_id IN ($qrIds) ORDER BY sort_order ASC");
    foreach ($qriStmt->fetchAll() as $qi) { $quoteItems[$qi['quote_request_id']][] = $qi; }
}

$pendingCount = 0;
foreach ($quoteRequests as $qr) { if ($qr['status'] === 'pending') $pendingCount++; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Custom Orders — Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .mo-shell { max-width: 900px; margin: 0 auto; padding: 18px 16px 60px; }
        .mo-card  { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; padding: 16px 18px; margin-bottom: 12px; border-left: 4px solid #6b7280; }
        .mo-badge { display:inline-block; padding:2px 9px; border-radius:20px; font-size:.68rem; font-weight:800; }
        .mo-item-row { display:grid; grid-template-columns:2fr 1fr auto; gap:8px; align-items:center; padding:6px 0; border-bottom:1px solid var(--border); }
        .mo-item-row input[type="number"] { padding:6px 9px; border:1px solid var(--border); border-radius:8px; font-size:.82rem; }
        .mo-empty { text-align:center; padding:50px 20px; color:var(--text-muted,#6b7280); }
    </style>
</head>
<body>

<header class="topbar">
    <a href="markets.php" class="button button-secondary button-small">← Markets</a>
    <h1 style="margin:0;font-size:1rem;font-weight:800;">📝 Custom Orders<?php if ($pendingCount): ?> <span class="mo-badge" style="background:#fef3c7;color:#92400e;"><?php echo $pendingCount; ?> pending</span><?php endif; ?></h1>
</header>

<main class="mo-shell">

    <?php if ($flash): ?>
    <div class="alert alert-<?php echo sanitize($flash['type']); ?>" style="margin-bottom:14px;"><?php echo sanitize($flash['message']); ?></div>
    <?php endif; ?>

    <p style="font-size:.84rem;color:var(--text-muted,#6b7280);margin-bottom:14px;">
        Shopping lists buyers sent in for a market you manage, with the price they suggested for each
        item pre-filled — confirm it as-is or adjust it, mark anything unavailable, then send the quote.
        Once the buyer pays, the order lands in <a href="market_deliveries.php">Storehouse Deliveries</a>.
    </p>

    <?php if ($noMarketsAssigned): ?>
    <div class="mo-empty">
        <div style="font-size:2.5rem;opacity:.35;margin-bottom:10px;">📝</div>
        <p style="margin:0;font-weight:700;">You're not assigned to manage any market yet</p>
        <p style="margin:6px 0 0;font-size:.85rem;">Ask an admin to assign you via <a href="markets.php">Periodic Markets</a>.</p>
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

    <?php if (!$quoteRequests): ?>
    <div class="mo-empty">
        <div style="font-size:2.5rem;opacity:.35;margin-bottom:10px;">📭</div>
        <p style="margin:0;font-weight:700;">No custom orders yet</p>
    </div>
    <?php else: ?>
    <?php foreach ($quoteRequests as $qr):
        $qrItemsList = $quoteItems[$qr['id']] ?? [];
        $qrStatusColors = ['pending'=>'#f59e0b','quoted'=>'#3b82f6','declined'=>'#ef4444','cancelled'=>'#6b7280','expired'=>'#6b7280','paid'=>'#10b981'];
        $qrColor = $qrStatusColors[$qr['status']] ?? '#6b7280';
    ?>
    <div class="mo-card" style="border-left-color:<?php echo $qrColor; ?>;">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:8px;flex-wrap:wrap;gap:6px;">
            <div>
                <div style="font-weight:800;">Request #<?php echo $qr['id']; ?><?php if (count($markets) !== 1): ?><span style="color:var(--text-muted,#6b7280);font-weight:400;font-size:.78rem;"> · <?php echo sanitize($qr['market_name']); ?></span><?php endif; ?></div>
                <div style="font-size:.76rem;color:var(--text-muted,#6b7280);">
                    <?php echo sanitize($qr['customer_name']); ?><?php echo $qr['customer_phone'] ? ' · ' . sanitize($qr['customer_phone']) : ''; ?> &nbsp;·&nbsp; <?php echo time_ago($qr['created_at']); ?>
                </div>
            </div>
            <span class="mo-badge" style="background:<?php echo $qrColor; ?>1a;color:<?php echo $qrColor; ?>;"><?php echo ucfirst($qr['status']); ?></span>
        </div>
        <?php if ($qr['buyer_notes']): ?><div style="font-size:.8rem;background:var(--surface-muted,#f8fafc);border-radius:8px;padding:6px 9px;margin-bottom:8px;">📝 <?php echo sanitize($qr['buyer_notes']); ?></div><?php endif; ?>

        <?php if ($qr['status'] === 'pending'): ?>
        <form method="post">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="price">
            <input type="hidden" name="quote_id" value="<?php echo $qr['id']; ?>">
            <?php foreach ($qrItemsList as $qi): ?>
            <div class="mo-item-row">
                <div>
                    <strong style="font-size:.85rem;"><?php echo sanitize($qi['item_name']); ?></strong>
                    <?php if ($qi['quantity_note']): ?><span style="color:var(--text-muted,#6b7280);font-size:.78rem;"> — <?php echo sanitize($qi['quantity_note']); ?></span><?php endif; ?>
                    <?php if ($qi['buyer_note']): ?><div style="font-size:.74rem;color:var(--text-muted,#6b7280);">Note: <?php echo sanitize($qi['buyer_note']); ?></div><?php endif; ?>
                    <?php if ($qi['price'] !== null): ?><div style="font-size:.72rem;color:#0f766e;">💡 Buyer suggested GH&#8373; <?php echo number_format((float)$qi['price'], 2); ?> — confirm or adjust below</div><?php endif; ?>
                </div>
                <input type="number" step="0.01" min="0" name="price[<?php echo $qi['id']; ?>]" placeholder="GH₵ price" value="<?php echo $qi['price'] !== null ? sanitize($qi['price']) : ''; ?>">
                <label style="font-size:.74rem;display:flex;align-items:center;gap:4px;white-space:nowrap;"><input type="checkbox" name="unavailable[]" value="<?php echo $qi['id']; ?>"> Unavailable</label>
            </div>
            <?php endforeach; ?>
            <div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap;">
                <button type="submit" class="button button-primary button-small">Send Quote</button>
            </div>
        </form>
        <form method="post" style="margin-top:6px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="decline">
            <input type="hidden" name="quote_id" value="<?php echo $qr['id']; ?>">
            <input type="text" name="decline_reason" placeholder="Reason (optional)" style="flex:1;min-width:160px;padding:6px 9px;border:1px solid var(--border);border-radius:8px;font-size:.82rem;">
            <button type="submit" class="button button-secondary button-small" style="color:#b91c1c;">Decline Request</button>
        </form>
        <?php else: ?>
        <?php foreach ($qrItemsList as $qi): ?>
        <div style="font-size:.82rem;padding:2px 0;<?php echo !$qi['is_available'] ? 'opacity:.5;text-decoration:line-through;' : ''; ?>">
            • <?php echo sanitize($qi['item_name']); ?><?php if ($qi['quantity_note']): ?> — <?php echo sanitize($qi['quantity_note']); ?><?php endif; ?>
            <?php if ($qi['is_available'] && $qi['price'] !== null): ?> — GH&#8373; <?php echo number_format((float)$qi['price'],2); ?><?php elseif (!$qi['is_available']): ?> (unavailable)<?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php if ($qr['decline_reason']): ?><div style="font-size:.78rem;padding:6px 8px;margin-top:6px;background:#fee2e2;border-radius:6px;color:#c0392b;">Reason: <?php echo sanitize($qr['decline_reason']); ?></div><?php endif; ?>
        <?php if ($qr['total_amount'] !== null): ?><div style="font-weight:900;color:var(--primary,#0f766e);margin-top:8px;">Total: GH&#8373; <?php echo number_format((float)$qr['total_amount'],2); ?></div><?php endif; ?>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <?php endif; ?>

</main>
</body>
</html>
