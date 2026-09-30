<?php
/**
 * Quick Services — a single transaction's detail page (customer view).
 * Only the owning customer, an assigned manager, or an admin may view it —
 * mirrors the same access rule qs_result.php enforces for the PDF download.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/quick_service_functions.php';

require_module_enabled('quick_services', 'Quick Services');
require_login();
$user = current_user();

$reference = trim($_GET['ref'] ?? '');
$tx = $reference !== '' ? qs_get_transaction_by_reference($reference) : null;

$isOwner = $tx && (int)$tx['user_id'] === (int)$user['id'];
$canManage = $tx && user_can_manage_quick_service($user['id'], (int)$tx['service_id']);
if (!$tx || (!$isOwner && !$canManage)) {
    render_not_found('my_quick_services.php', 'My Quick Services', 'This request could not be found.');
}

$requestData = json_decode($tx['request_data'] ?? '[]', true) ?: [];
$payBadge  = qs_payment_badge($tx['payment_status']);
$procBadge = qs_processing_badge($tx['processing_status']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo sanitize($tx['reference']); ?> — <?php echo sanitize(APP_NAME); ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .qsv-shell { max-width:560px; margin:0 auto; padding:16px 16px 80px; }
        .qsv-panel { background:var(--surface,#fff); border:1px solid var(--border,#e5e7eb); border-radius:var(--radius-lg,20px); padding:18px; margin-bottom:16px; }
        .qsv-section-title { font-size:.78rem; font-weight:800; text-transform:uppercase; letter-spacing:.06em; color:var(--muted,#6b7280); margin:0 0 12px; }
        .qsv-badges { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:4px; }
        .qsv-badge { font-size:.7rem; font-weight:800; padding:4px 11px; border-radius:20px; }
        .qsv-badge.pending { background:#fffbeb; color:#92400e; }
        .qsv-badge.completed { background:#ecfdf5; color:#065f46; }
        .qsv-badge.processing { background:#eff6ff; color:#1d4ed8; }
        .qsv-badge.failed, .qsv-badge.cancelled { background:#fee2e2; color:#991b1b; }
        .qsv-row { display:flex; justify-content:space-between; padding:7px 0; border-bottom:1px solid var(--border,#e5e7eb); font-size:.86rem; }
        .qsv-row:last-child { border-bottom:none; }
        .qsv-row strong { color:var(--muted,#6b7280); font-weight:600; }
    </style>
</head>
<body class="has-bottom-nav">

<header class="app-topbar">
    <a href="my_quick_services.php" class="button button-secondary button-small">‹ My Quick Services</a>
    <span class="brand"><?php echo sanitize($tx['reference']); ?></span>
</header>

<main class="qsv-shell">
    <div class="qsv-panel">
        <div class="qsv-badges">
            <span class="qsv-badge <?php echo $payBadge[2]; ?>"><?php echo $payBadge[0]; ?> <?php echo $payBadge[1]; ?></span>
            <span class="qsv-badge <?php echo $procBadge[2]; ?>"><?php echo $procBadge[0]; ?> <?php echo $procBadge[1]; ?></span>
        </div>
        <p class="qsv-section-title" style="margin-top:14px;">Transaction</p>
        <div class="qsv-row"><strong>Reference</strong><span><?php echo sanitize($tx['reference']); ?></span></div>
        <div class="qsv-row"><strong>Service</strong><span><?php echo sanitize($tx['service_name']); ?></span></div>
        <div class="qsv-row"><strong>Amount</strong><span>GH₵<?php echo number_format((float)$tx['amount'], 2); ?></span></div>
        <?php if ((float)$tx['service_charge'] > 0): ?>
        <div class="qsv-row"><strong>Service Charge</strong><span>GH₵<?php echo number_format((float)$tx['service_charge'], 2); ?></span></div>
        <div class="qsv-row"><strong>Total Paid</strong><span>GH₵<?php echo number_format((float)$tx['amount'] + (float)$tx['service_charge'], 2); ?></span></div>
        <?php endif; ?>
        <div class="qsv-row"><strong>Date</strong><span><?php echo date('d M Y, g:i A', strtotime($tx['created_at'])); ?></span></div>
        <?php if ($tx['completed_at']): ?><div class="qsv-row"><strong>Completed</strong><span><?php echo date('d M Y, g:i A', strtotime($tx['completed_at'])); ?></span></div><?php endif; ?>
    </div>

    <div class="qsv-panel">
        <p class="qsv-section-title">Details Submitted</p>
        <?php foreach ($requestData as $key => $val): ?>
        <div class="qsv-row"><strong><?php echo sanitize(ucwords(str_replace('_', ' ', $key))); ?></strong><span><?php echo sanitize((string)$val); ?></span></div>
        <?php endforeach; ?>
    </div>

    <?php if ($tx['manager_notes']): ?>
    <div class="qsv-panel">
        <p class="qsv-section-title">Note From Our Team</p>
        <p style="margin:0;line-height:1.6;"><?php echo nl2br(sanitize($tx['manager_notes'])); ?></p>
    </div>
    <?php endif; ?>

    <?php if ($tx['result_file_path'] && $tx['processing_status'] === 'completed'): ?>
    <div class="qsv-panel" style="text-align:center;">
        <p class="qsv-section-title">Your Document</p>
        <a href="qs_result.php?ref=<?php echo urlencode($tx['reference']); ?>" class="button button-primary">📄 Download Result Document</a>
    </div>
    <?php endif; ?>
</main>

<?php require __DIR__ . '/partials/bottom_nav.php'; ?>
</body>
</html>
