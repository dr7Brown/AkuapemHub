<?php
/**
 * Universal payment receipt.
 *
 * Usage:
 *   payment_receipt.php?type=marketplace_order&id=42
 *   payment_receipt.php?type=platform_payment&id=7
 *   payment_receipt.php?type=delivery_transaction&id=3
 *   payment_receipt.php?type=boost_order&id=5
 *   payment_receipt.php?type=escrow&id=11
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_login();
$user = current_user();

$type = $_GET['type'] ?? '';
$id   = (int)($_GET['id'] ?? 0);

if (!$id || !$type) { header('Location: my_payments.php'); exit; }

// ── Load payment data by type ──────────────────────────────────────────────
$receipt = null;  // normalised receipt array
$lineItems = [];  // [{description, amount}]

switch ($type) {

    // ── Marketplace order ──────────────────────────────────────────────────
    case 'marketplace_order': {
        $stmt = $pdo->prepare(
            'SELECT mo.*, ms.shop_name, ms.phone AS shop_phone, ms.email AS shop_email, ms.region AS shop_region,
                    u.name AS customer_name, u.email AS customer_email
             FROM mp_orders mo
             JOIN mp_shops ms ON mo.shop_id = ms.id
             JOIN users u ON mo.customer_id = u.id
             WHERE mo.id = ? AND mo.customer_id = ?'
        );
        $stmt->execute([$id, $user['id']]);
        $row = $stmt->fetch();
        if (!$row) break;

        $itemsStmt = $pdo->prepare('SELECT product_name, quantity, price, subtotal FROM mp_order_items WHERE order_id = ?');
        $itemsStmt->execute([$id]);
        $items = $itemsStmt->fetchAll();
        foreach ($items as $i) {
            $lineItems[] = [
                'name'       => $i['product_name'],
                'qty'        => (int)$i['quantity'],
                'unit_price' => (float)$i['price'],
                'amount'     => (float)$i['subtotal'],
            ];
        }
        $receipt = [
            'ref'     => 'MKT-' . str_pad($id, 6, '0', STR_PAD_LEFT),
            'date'    => $row['created_at'],
            'title'   => 'Marketplace Order — ' . sanitize($row['shop_name']),
            'to'      => $row['customer_name'],
            'email'   => $row['customer_email'],
            'method'  => ucwords(str_replace('_',' ',$row['payment_method'])),
            'status'  => $row['payment_status'] === 'paid' ? 'Paid' : 'Unpaid',
            'total'   => (float)$row['total_amount'],
            'itemized' => true,
            'shop'    => [
                'name'   => $row['shop_name'],
                'phone'  => $row['shop_phone'],
                'email'  => $row['shop_email'],
                'region' => $row['shop_region'],
            ],
        ];
        break;
    }

    // ── Platform payment ───────────────────────────────────────────────────
    case 'platform_payment': {
        $stmt = $pdo->prepare(
            'SELECT pp.*, u.name AS customer_name, u.email AS customer_email
             FROM platform_payments pp
             JOIN users u ON pp.user_id = u.id
             WHERE pp.id = ? AND pp.user_id = ?'
        );
        $stmt->execute([$id, $user['id']]);
        $row = $stmt->fetch();
        if (!$row || $row['status'] !== 'paid') break;

        $typeLabel = [
            'featured_job'         => 'Featured Job Post',
            'featured_worker'      => 'Featured Worker Profile',
            'verification'         => 'Worker Verification Badge',
            'job_post'             => 'Job Posting Credit',
            'worker_service'       => 'Worker Service Listing',
            'escrow_payment'       => 'Escrow Payment',
            'escrow_with_posting'  => 'Escrow + Job Posting',
            'news_post'            => 'News Article Submission',
            'event_post'           => 'Event Submission',
            'funeral_post'         => 'Funeral Announcement',
        ][$row['payment_type']] ?? ucwords(str_replace('_',' ',$row['payment_type']));

        $lineItems[] = ['desc' => $typeLabel, 'amount' => (float)$row['amount']];
        $receipt = [
            'ref'    => 'PLT-' . str_pad($id, 6, '0', STR_PAD_LEFT),
            'date'   => $row['paid_at'] ?? $row['created_at'],
            'title'  => $typeLabel,
            'to'     => $row['customer_name'],
            'email'  => $row['customer_email'],
            'method' => ucwords($row['gateway'] ?? 'Manual'),
            'status' => 'Paid',
            'total'  => (float)$row['amount'],
            'ref_code' => $row['reference_code'] ?? ($row['paystack_reference'] ?? null),
        ];
        break;
    }

    // ── Delivery transaction ───────────────────────────────────────────────
    case 'delivery_transaction': {
        $stmt = $pdo->prepare(
            'SELECT dt.*, da.user_id AS agent_user_id, u.name AS agent_name, u.email AS agent_email
             FROM delivery_transactions dt
             JOIN delivery_agents da ON dt.agent_id = da.id
             JOIN users u ON da.user_id = u.id
             WHERE dt.id = ? AND da.user_id = ? AND dt.status = ?'
        );
        $stmt->execute([$id, $user['id'], 'completed']);
        $row = $stmt->fetch();
        if (!$row) break;

        $typeLabel = ['subscription'=>'Premium Rider Subscription','sponsored'=>'Sponsored Rider Listing','verification'=>'Rider Verification Badge'][$row['transaction_type']] ?? ucfirst($row['transaction_type']);
        $lineItems[] = ['desc' => $typeLabel, 'amount' => (float)$row['amount']];
        $receipt = [
            'ref'    => 'DLV-' . str_pad($id, 6, '0', STR_PAD_LEFT),
            'date'   => $row['created_at'],
            'title'  => $typeLabel . ' — Delivery Services',
            'to'     => $row['agent_name'],
            'email'  => $row['agent_email'],
            'method' => ucwords(str_replace('_',' ',$row['payment_method'])),
            'status' => 'Paid',
            'total'  => (float)$row['amount'],
        ];
        break;
    }

    // ── Marketplace boost order ────────────────────────────────────────────
    case 'boost_order': {
        $stmt = $pdo->prepare(
            'SELECT mb.*, ms.shop_name, u.id AS user_id, u.name AS owner_name, u.email AS owner_email
             FROM mp_boost_orders mb
             JOIN mp_shops ms ON mb.shop_id = ms.id
             JOIN users u ON ms.user_id = u.id
             WHERE mb.id = ? AND ms.user_id = ? AND mb.status = ?'
        );
        $stmt->execute([$id, $user['id'], 'active']);
        $row = $stmt->fetch();
        if (!$row) break;

        $boostLabel = ucwords(str_replace('_',' ',$row['boost_type']));
        $lineItems[] = ['desc' => $boostLabel . ' — ' . $row['package_days'] . ' days', 'amount' => (float)$row['price_paid']];
        $receipt = [
            'ref'    => 'BST-' . str_pad($id, 6, '0', STR_PAD_LEFT),
            'date'   => $row['activated_at'] ?? $row['created_at'],
            'title'  => 'Marketplace Boost — ' . $boostLabel,
            'to'     => $row['owner_name'],
            'email'  => $row['owner_email'],
            'method' => ucwords(str_replace('_',' ',$row['payment_method'])),
            'status' => 'Paid',
            'total'  => (float)$row['price_paid'],
        ];
        break;
    }

    // ── Escrow payment ─────────────────────────────────────────────────────
    case 'escrow': {
        $stmt = $pdo->prepare(
            'SELECT ep.*, sr.title, u.name AS customer_name, u.email AS customer_email
             FROM escrow_payments ep
             JOIN service_requests sr ON ep.job_id = sr.id
             JOIN users u ON ep.client_id = u.id
             WHERE ep.id = ? AND ep.client_id = ?'
        );
        $stmt->execute([$id, $user['id']]);
        $row = $stmt->fetch();
        if (!$row) break;

        $lineItems = [
            ['desc' => 'Job: ' . sanitize($row['title']),     'amount' => (float)$row['net_amount']],
            ['desc' => 'Platform commission',                  'amount' => (float)$row['commission_amount']],
        ];
        $receipt = [
            'ref'    => 'ESC-' . str_pad($id, 6, '0', STR_PAD_LEFT),
            'date'   => $row['paid_at'] ?? $row['created_at'],
            'title'  => 'Escrow — ' . sanitize($row['title']),
            'to'     => $row['customer_name'],
            'email'  => $row['customer_email'],
            'method' => 'Escrow',
            'status' => ucfirst($row['status']),
            'total'  => (float)$row['gross_amount'],
        ];
        break;
    }
}

if (!$receipt) {
    flash('Receipt not available.', 'error');
    header('Location: my_payments.php');
    exit;
}

$receiptDate = date('d M Y, g:i A', strtotime($receipt['date']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt <?php echo sanitize($receipt['ref']); ?> — AkuapemConnect</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .rc-shell { max-width:640px; margin:0 auto; padding:20px 16px 60px; }
        .rc-card  { background:#fff; border:1px solid #e5e7eb; border-radius:14px; padding:32px; }
        .rc-head  { display:flex; justify-content:space-between; align-items:flex-start; border-bottom:3px solid var(--primary,#0f766e); padding-bottom:18px; margin-bottom:22px; flex-wrap:wrap; gap:12px; }
        .rc-logo  { font-size:1.1rem; font-weight:900; color:var(--primary,#0f766e); }
        .rc-table { width:100%; border-collapse:collapse; margin:14px 0; }
        .rc-table td { padding:8px 0; border-bottom:1px solid #f1f5f9; font-size:.88rem; }
        .rc-table td:last-child { text-align:right; font-weight:600; }
        .rc-total-row td { padding-top:14px; font-size:1rem; font-weight:800; color:var(--primary,#0f766e); border-bottom:none; border-top:2px solid #e5e7eb; }
        .rc-badge { display:inline-block; padding:3px 10px; border-radius:20px; font-size:.72rem; font-weight:800; background:#d1fae5; color:#065f46; }
        .rc-meta  { font-size:.8rem; color:#6b7280; line-height:1.6; }
        .rc-divider { border:none; border-top:1px solid #f1f5f9; margin:20px 0; }
        @media print {
            .no-print { display:none !important; }
            body { background:#fff; }
            .rc-card  { border:none; padding:0; }
        }
    </style>
</head>
<body class="has-bottom-nav">

<header class="app-topbar no-print">
    <a href="my_payments.php" class="button button-secondary button-small">← Back</a>
    <span class="brand">🧾 Receipt</span>
    <button onclick="window.print()" class="button button-primary button-small">Print / PDF</button>
</header>

<main class="rc-shell">
    <div class="rc-card">

        <!-- Header -->
        <div class="rc-head">
            <div>
                <div class="rc-logo">
                    <img src="assets/images/ac%20logo.png" alt="<?php echo APP_NAME; ?>" style="height:36px;width:auto;vertical-align:middle;">
                </div>
                <p class="rc-meta" style="margin:6px 0 0;">Payment Receipt</p>
            </div>
            <div style="text-align:right;">
                <div style="font-weight:800;font-size:.9rem;"><?php echo sanitize($receipt['ref']); ?></div>
                <div class="rc-meta"><?php echo sanitize($receiptDate); ?></div>
                <div style="margin-top:6px;"><span class="rc-badge"><?php echo sanitize($receipt['status']); ?></span></div>
            </div>
        </div>

        <!-- Billed to -->
        <div style="display:grid;grid-template-columns:repeat(<?php echo !empty($receipt['shop']) ? 3 : 2; ?>,1fr);gap:20px;margin-bottom:20px;flex-wrap:wrap;">
            <div>
                <div style="font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:#6b7280;margin-bottom:4px;">Billed To</div>
                <div style="font-weight:700;"><?php echo sanitize($receipt['to']); ?></div>
                <div class="rc-meta"><?php echo sanitize($receipt['email']); ?></div>
            </div>
            <?php if (!empty($receipt['shop'])): ?>
            <div>
                <div style="font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:#6b7280;margin-bottom:4px;">Sold By</div>
                <div style="font-weight:700;"><?php echo sanitize($receipt['shop']['name']); ?></div>
                <?php if ($receipt['shop']['phone']): ?><div class="rc-meta"><?php echo sanitize($receipt['shop']['phone']); ?></div><?php endif; ?>
                <?php if ($receipt['shop']['region']): ?><div class="rc-meta"><?php echo sanitize($receipt['shop']['region']); ?></div><?php endif; ?>
            </div>
            <?php endif; ?>
            <div>
                <div style="font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:#6b7280;margin-bottom:4px;">Payment Details</div>
                <div class="rc-meta">Method: <strong><?php echo sanitize($receipt['method']); ?></strong></div>
                <?php if (!empty($receipt['ref_code'])): ?>
                <div class="rc-meta">Reference: <?php echo sanitize($receipt['ref_code']); ?></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Line items -->
        <div style="font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:#6b7280;margin-bottom:6px;"><?php echo sanitize($receipt['title']); ?></div>
        <table class="rc-table">
            <?php if (!empty($receipt['itemized'])): ?>
            <thead>
                <tr style="font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;">
                    <td>Item</td><td style="text-align:center;">Qty</td><td style="text-align:right;">Unit Price</td><td style="text-align:right;">Subtotal</td>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($lineItems as $item): ?>
                <tr>
                    <td><?php echo sanitize($item['name']); ?></td>
                    <td style="text-align:center;"><?php echo $item['qty']; ?></td>
                    <td style="text-align:right;">GHS <?php echo number_format($item['unit_price'], 2); ?></td>
                    <td style="text-align:right;font-weight:600;">GHS <?php echo number_format($item['amount'], 2); ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="rc-total-row">
                    <td colspan="3">Total Paid</td>
                    <td style="text-align:right;">GHS <?php echo number_format($receipt['total'], 2); ?></td>
                </tr>
            </tbody>
            <?php else: ?>
            <tbody>
                <?php foreach ($lineItems as $item): ?>
                <tr>
                    <td><?php echo $item['desc']; ?></td>
                    <td>GHS <?php echo number_format($item['amount'], 2); ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="rc-total-row">
                    <td>Total Paid</td>
                    <td>GHS <?php echo number_format($receipt['total'], 2); ?></td>
                </tr>
            </tbody>
            <?php endif; ?>
        </table>

        <hr class="rc-divider">
        <p class="rc-meta" style="text-align:center;">
            Thank you for using <?php echo sanitize(APP_NAME); ?>.<br>
            This receipt was automatically generated on <?php echo date('d M Y \a\t g:i A'); ?>.
            For support, visit <strong><?php echo defined('BASE_URL') ? sanitize(BASE_URL) : 'akuapemconnect.com'; ?></strong>.
        </p>
    </div>
</main>

<div class="no-print">
<?php require_once __DIR__ . '/partials/bottom_nav.php'; ?>
</div>
</body>
</html>
