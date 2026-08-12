<?php
/**
 * Buyer submits a custom order (shopping list) for a periodic market — the
 * market's assigned agent(s) price it, same lifecycle as the Marketplace's
 * existing "Request a Custom Order" quote-request feature
 * (see request_quote.php), just re-pointed at the market's hidden system
 * shop instead of a real seller's shop, and priced by an agent instead of
 * a shop owner.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/marketplace_functions.php';

require_module_enabled('markets', 'Periodic Markets');
require_login();

$user     = current_user();
$marketId = (int)($_GET['market_id'] ?? 0);
$market   = null;
if ($marketId) {
    $mStmt = $pdo->prepare('SELECT * FROM markets WHERE id = ?');
    $mStmt->execute([$marketId]);
    $market = $mStmt->fetch();
}
if (!$market) { header('Location: markets.php'); exit; }

$sched = get_market_schedule($market);
if (!$sched['is_preorder_open']) {
    if ($sched['is_market_day']) {
        $msg = $market['name'] . ' has stopped taking orders for today. Check back next market day.';
    } elseif ($sched['is_scheduled'] && $sched['preorder_starts_at']) {
        $msg = $market['name'] . ' isn\'t taking custom orders yet — orders open ' . $sched['preorder_starts_at']->format('j F') . '.';
    } else {
        $msg = $market['name'] . ' isn\'t taking custom orders right now.';
    }
    flash($msg, 'info');
    header('Location: market_view.php?id=' . $marketId);
    exit;
}

$shopStmt = $pdo->prepare('SELECT id FROM mp_shops WHERE market_id = ? LIMIT 1');
$shopStmt->execute([$marketId]);
$shopId = (int)$shopStmt->fetchColumn();

// Self-heal: markets created before the system-shop-per-market pivot (or one
// whose shop was somehow removed) would otherwise silently strand every
// buyer who tries to order from them — create the missing shop here rather
// than bouncing them away with no explanation.
if (!$shopId) {
    $ownerStmt = $pdo->query("SELECT id FROM users WHERE role='admin' ORDER BY id LIMIT 1");
    $ownerId = (int)$ownerStmt->fetchColumn();
    if (!$ownerId) {
        flash('This market can\'t accept custom orders right now — please contact support.', 'error');
        header('Location: markets.php');
        exit;
    }
    $shopSlug = mp_unique_slug($market['name'] . ' custom orders', 'mp_shops', 'slug', $pdo);
    $pdo->prepare('INSERT INTO mp_shops (user_id, shop_name, slug, market_id, status) VALUES (?,?,?,?,?)')
        ->execute([$ownerId, $market['name'] . ' — Custom Orders', $shopSlug, $marketId, 'active']);
    $shopId = (int)$pdo->lastInsertId();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $itemNames  = $_POST['item_name'] ?? [];
    $itemQtys   = $_POST['quantity_note'] ?? [];
    $itemNotes  = $_POST['buyer_note'] ?? [];
    $itemPrices = $_POST['item_price'] ?? [];
    $buyerNotes = trim($_POST['buyer_notes'] ?? '');

    $rows = [];
    foreach ($itemNames as $i => $name) {
        $name = trim($name);
        if ($name === '') continue;
        $price = isset($itemPrices[$i]) ? (float)$itemPrices[$i] : 0;
        if ($price <= 0) {
            $error = 'Please enter a price for every item — "' . mb_substr($name, 0, 40) . '" is missing one.';
            break;
        }
        $rows[] = [
            'item_name'     => mb_substr($name, 0, 255),
            'quantity_note' => mb_substr(trim($itemQtys[$i] ?? ''), 0, 100) ?: null,
            'buyer_note'    => mb_substr(trim($itemNotes[$i] ?? ''), 0, 255) ?: null,
            'price'         => round($price, 2),
        ];
    }

    if (!$error && !$rows) {
        $error = 'Please add at least one item to your list.';
    }

    if (!$error) {
        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO mp_quote_requests (customer_id, shop_id, buyer_notes, status) VALUES (?,?,?,\'pending\')')
                ->execute([$user['id'], $shopId, $buyerNotes ?: null]);
            $requestId = (int)$pdo->lastInsertId();

            $itemStmt = $pdo->prepare(
                'INSERT INTO mp_quote_request_items (quote_request_id, item_name, quantity_note, buyer_note, price, sort_order) VALUES (?,?,?,?,?,?)'
            );
            foreach ($rows as $sort => $row) {
                $itemStmt->execute([$requestId, $row['item_name'], $row['quantity_note'], $row['buyer_note'], $row['price'], $sort]);
            }

            $pdo->commit();

            // Notify every agent assigned to this market (there's no single
            // "shop owner" here — could be zero, one, or several agents).
            $agentStmt = $pdo->prepare('SELECT user_id FROM market_managers WHERE market_id = ?');
            $agentStmt->execute([$marketId]);
            foreach ($agentStmt->fetchAll(PDO::FETCH_COLUMN) as $agentId) {
                notify_user(
                    (int)$agentId,
                    'New Custom Order 📝',
                    $user['name'] . ' sent a shopping list for "' . $market['name'] . '" — ' . count($rows) . ' item(s) with suggested prices. Confirm or adjust them.',
                    'info',
                    'admin/market_orders.php'
                );
            }
            notify_admins('New Market Custom Order', $user['name'] . ' sent a shopping list for "' . $market['name'] . '".', 'info');

            flash('Your shopping list was sent for ' . $market['name'] . '. You\'ll be notified once it\'s priced.', 'success');
            header('Location: orders.php?view=quotes');
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Could not send your request. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Send a Custom Order — <?php echo sanitize($market['name']); ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .rq-shell { max-width:700px; margin:0 auto; padding:16px 16px 80px; }
        .rq-card  { background:var(--surface); border:1px solid var(--border); border-radius:14px; padding:18px; margin-bottom:14px; }
        .rq-section-title { font-size:.75rem; font-weight:800; text-transform:uppercase; letter-spacing:.07em; color:var(--text-muted,#6b7280); margin:0 0 14px; }
        label { font-weight:600; font-size:.86rem; display:block; margin-bottom:4px; }
        .form-group { margin-bottom:14px; }
        .rq-item-row { display:grid; grid-template-columns:2fr 1fr 1fr 1fr auto; gap:8px; align-items:end; margin-bottom:10px; padding-bottom:10px; border-bottom:1px solid var(--border); }
        .rq-item-row:last-child { border-bottom:none; }
        .rq-item-row input { width:100%; padding:8px 10px; border:1px solid var(--border); border-radius:8px; font-size:.86rem; }
        .rq-remove { background:none; border:none; color:#b91c1c; font-size:1.1rem; cursor:pointer; padding:6px; }
        .rq-add { margin-top:4px; }
        @media (max-width:600px) { .rq-item-row { grid-template-columns:1fr 1fr; } .rq-remove { grid-column:span 2; text-align:right; } }
    </style>
</head>
<body class="has-bottom-nav">

<header class="app-topbar">
    <a href="market_view.php?id=<?php echo $marketId; ?>" class="button button-secondary button-small">← <?php echo sanitize($market['name']); ?></a>
    <span class="brand">Custom Order</span>
</header>

<main class="rq-shell">
    <?php if ($error): ?><div class="alert alert-error"><?php echo sanitize($error); ?></div><?php endif; ?>

    <div class="rq-card">
        <p class="rq-section-title">📝 How it works</p>
        <p class="meta" style="margin:0;">List what you'd like from <strong><?php echo sanitize($market['name']); ?></strong> and suggest a price for each item — a market agent will confirm or adjust your prices and let you know the final total. You'll only pay once you accept it.</p>
    </div>

    <form method="post" action="request_market_order.php?market_id=<?php echo $marketId; ?>">
        <?php echo csrf_field(); ?>

        <div class="rq-card">
            <p class="rq-section-title">🛍️ Your Shopping List</p>
            <div id="rq-items">
                <div class="rq-item-row">
                    <div><label>Item *</label><input type="text" name="item_name[]" placeholder="e.g. Tomatoes"></div>
                    <div><label>Quantity</label><input type="text" name="quantity_note[]" placeholder="e.g. 2kg"></div>
                    <div><label>Your Price (GH₵) *</label><input type="number" name="item_price[]" min="0.01" step="0.01" placeholder="e.g. 15.00"></div>
                    <div><label>Note</label><input type="text" name="buyer_note[]" placeholder="e.g. ripe ones"></div>
                    <button type="button" class="rq-remove" onclick="rqRemoveRow(this)" title="Remove">✕</button>
                </div>
            </div>
            <button type="button" class="button button-secondary button-small rq-add" onclick="rqAddRow()">+ Add another item</button>
        </div>

        <div class="rq-card">
            <div class="form-group">
                <label for="buyer_notes">General Note (optional)</label>
                <textarea id="buyer_notes" name="buyer_notes" rows="2" placeholder="Anything else the agent should know?"></textarea>
            </div>
        </div>

        <button type="submit" class="button button-primary" style="width:100%;padding:14px;font-size:1rem;">Send Request →</button>
    </form>
</main>

<script>
function rqAddRow() {
    const container = document.getElementById('rq-items');
    const row = container.firstElementChild.cloneNode(true);
    row.querySelectorAll('input').forEach(i => i.value = '');
    container.appendChild(row);
}
function rqRemoveRow(btn) {
    const container = document.getElementById('rq-items');
    if (container.children.length > 1) btn.closest('.rq-item-row').remove();
}
</script>

<?php require_once __DIR__ . '/partials/bottom_nav.php'; ?>
</body>
</html>
