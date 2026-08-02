<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/marketplace_functions.php';

require_module_enabled('mp', 'Marketplace');
require_login();

$user   = current_user();
$shopId = (int)($_GET['shop_id'] ?? 0);
$shop   = get_shop($shopId);
if (!$shop || $shop['status'] !== 'active') { header('Location: marketplace.php'); exit; }
if ((int)$user['id'] === (int)$shop['user_id']) { header('Location: shop.php?id=' . $shopId); exit; }
if (!mp_shop_can_receive_quotes($shop)) {
    flash('This shop isn\'t currently accepting custom order requests.', 'error');
    header('Location: shop.php?id=' . $shopId);
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $itemNames = $_POST['item_name'] ?? [];
    $itemQtys  = $_POST['quantity_note'] ?? [];
    $itemNotes = $_POST['buyer_note'] ?? [];
    $buyerNotes = trim($_POST['buyer_notes'] ?? '');

    $rows = [];
    foreach ($itemNames as $i => $name) {
        $name = trim($name);
        if ($name === '') continue;
        $rows[] = [
            'item_name'     => mb_substr($name, 0, 255),
            'quantity_note' => mb_substr(trim($itemQtys[$i] ?? ''), 0, 100) ?: null,
            'buyer_note'    => mb_substr(trim($itemNotes[$i] ?? ''), 0, 255) ?: null,
        ];
    }

    if (!$rows) {
        $error = 'Please add at least one item to your list.';
    }

    if (!$error) {
        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO mp_quote_requests (customer_id, shop_id, buyer_notes, status) VALUES (?,?,?,\'pending\')')
                ->execute([$user['id'], $shopId, $buyerNotes ?: null]);
            $requestId = (int)$pdo->lastInsertId();

            $itemStmt = $pdo->prepare(
                'INSERT INTO mp_quote_request_items (quote_request_id, item_name, quantity_note, buyer_note, sort_order) VALUES (?,?,?,?,?)'
            );
            foreach ($rows as $sort => $row) {
                $itemStmt->execute([$requestId, $row['item_name'], $row['quantity_note'], $row['buyer_note'], $sort]);
            }

            $pdo->commit();

            notify_user(
                (int)$shop['user_id'],
                'New Quote Request 📝',
                $user['name'] . ' sent a shopping list to your shop "' . $shop['shop_name'] . '" — ' . count($rows) . ' item(s). Review and price it.',
                'info',
                'seller_dashboard.php?tab=quotes'
            );

            flash('Your shopping list was sent to ' . $shop['shop_name'] . '. You\'ll be notified once they respond with prices.', 'success');
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
    <title>Request a Custom Order — <?php echo sanitize($shop['shop_name']); ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .rq-shell { max-width:700px; margin:0 auto; padding:16px 16px 80px; }
        .rq-card  { background:var(--surface); border:1px solid var(--border); border-radius:14px; padding:18px; margin-bottom:14px; }
        .rq-section-title { font-size:.75rem; font-weight:800; text-transform:uppercase; letter-spacing:.07em; color:var(--text-muted,#6b7280); margin:0 0 14px; }
        label { font-weight:600; font-size:.86rem; display:block; margin-bottom:4px; }
        .form-group { margin-bottom:14px; }
        .rq-item-row { display:grid; grid-template-columns:2fr 1fr 1fr auto; gap:8px; align-items:end; margin-bottom:10px; padding-bottom:10px; border-bottom:1px solid var(--border); }
        .rq-item-row:last-child { border-bottom:none; }
        .rq-item-row input { width:100%; padding:8px 10px; border:1px solid var(--border); border-radius:8px; font-size:.86rem; }
        .rq-remove { background:none; border:none; color:#b91c1c; font-size:1.1rem; cursor:pointer; padding:6px; }
        .rq-add { margin-top:4px; }
        @media (max-width:600px) { .rq-item-row { grid-template-columns:1fr 1fr; } .rq-remove { grid-column:span 2; text-align:right; } }
    </style>
</head>
<body class="has-bottom-nav">

<header class="app-topbar">
    <a href="shop.php?id=<?php echo $shopId; ?>" class="button button-secondary button-small">← <?php echo sanitize($shop['shop_name']); ?></a>
    <span class="brand">Request a Custom Order</span>
</header>

<main class="rq-shell">
    <?php if ($error): ?><div class="alert alert-error"><?php echo sanitize($error); ?></div><?php endif; ?>

    <div class="rq-card">
        <p class="rq-section-title">📝 How it works</p>
        <p class="meta" style="margin:0;">List what you'd like from <strong><?php echo sanitize($shop['shop_name']); ?></strong> — the shop will price each item and let you know the total. You'll only pay once you accept the quote.</p>
    </div>

    <form method="post" action="request_quote.php?shop_id=<?php echo $shopId; ?>">
        <?php echo csrf_field(); ?>

        <div class="rq-card">
            <p class="rq-section-title">🛍️ Your Shopping List</p>
            <div id="rq-items">
                <div class="rq-item-row">
                    <div><label>Item *</label><input type="text" name="item_name[]" placeholder="e.g. Rice"></div>
                    <div><label>Quantity</label><input type="text" name="quantity_note[]" placeholder="e.g. 2kg"></div>
                    <div><label>Note</label><input type="text" name="buyer_note[]" placeholder="e.g. any brand"></div>
                    <button type="button" class="rq-remove" onclick="rqRemoveRow(this)" title="Remove">✕</button>
                </div>
            </div>
            <button type="button" class="button button-secondary button-small rq-add" onclick="rqAddRow()">+ Add another item</button>
        </div>

        <div class="rq-card">
            <div class="form-group">
                <label for="buyer_notes">General Note (optional)</label>
                <textarea id="buyer_notes" name="buyer_notes" rows="2" placeholder="Anything else the shop should know?"></textarea>
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
