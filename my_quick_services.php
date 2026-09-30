<?php
/**
 * Quick Services — customer's own transaction history. Reuses the same
 * payment/processing badge helpers the admin queue uses.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/quick_service_functions.php';

require_module_enabled('quick_services', 'Quick Services');
require_login();
$user = current_user();

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM quick_transactions WHERE user_id=?');
$countStmt->execute([$user['id']]);
$total = (int)$countStmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT qt.*, qs.name AS service_name, qs.icon AS service_icon FROM quick_transactions qt
     JOIN quick_services qs ON qs.id = qt.service_id
     WHERE qt.user_id = ? ORDER BY qt.created_at DESC LIMIT ? OFFSET ?"
);
$stmt->bindValue(1, $user['id'], PDO::PARAM_INT);
$stmt->bindValue(2, $perPage, PDO::PARAM_INT);
$stmt->bindValue(3, ($page - 1) * $perPage, PDO::PARAM_INT);
$stmt->execute();
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
$totalPages = max(1, (int)ceil($total / $perPage));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Quick Services — <?php echo sanitize(APP_NAME); ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .myqs-shell { max-width:640px; margin:0 auto; padding:16px 16px 80px; }
        .myqs-row { display:flex; align-items:center; gap:12px; background:var(--surface,#fff); border:1px solid var(--border,#e5e7eb); border-radius:14px; padding:14px; margin-bottom:10px; text-decoration:none; color:inherit; }
        .myqs-icon { font-size:1.6rem; flex-shrink:0; }
        .myqs-body { flex:1; min-width:0; }
        .myqs-ref { font-size:.7rem; color:var(--muted,#6b7280); font-weight:700; }
        .myqs-name { font-weight:800; font-size:.92rem; }
        .myqs-date { font-size:.76rem; color:var(--muted,#6b7280); }
        .myqs-badges { display:flex; flex-direction:column; align-items:flex-end; gap:4px; }
        .myqs-badge { font-size:.66rem; font-weight:800; padding:2px 9px; border-radius:20px; white-space:nowrap; }
        .myqs-badge.pending { background:#fffbeb; color:#92400e; }
        .myqs-badge.completed { background:#ecfdf5; color:#065f46; }
        .myqs-badge.processing { background:#eff6ff; color:#1d4ed8; }
        .myqs-badge.failed, .myqs-badge.cancelled { background:#fee2e2; color:#991b1b; }
        .myqs-amount { font-weight:800; font-size:.86rem; }
    </style>
</head>
<body class="has-bottom-nav">

<header class="app-topbar">
    <a href="quick_services.php" class="button button-secondary button-small">‹ Quick Services</a>
    <span class="brand">My Quick Services</span>
</header>

<main class="myqs-shell">
    <?php if (!$transactions): ?>
    <div class="empty-state"><p>You haven't made any Quick Services requests yet.</p><a href="quick_services.php" class="button button-primary" style="margin-top:10px;">Browse Services</a></div>
    <?php else: foreach ($transactions as $t):
        $payBadge  = qs_payment_badge($t['payment_status']);
        $procBadge = qs_processing_badge($t['processing_status']);
    ?>
    <a href="quick_service_view.php?ref=<?php echo urlencode($t['reference']); ?>" class="myqs-row">
        <span class="myqs-icon"><?php echo sanitize($t['service_icon']) ?: '⚡'; ?></span>
        <div class="myqs-body">
            <div class="myqs-ref"><?php echo sanitize($t['reference']); ?></div>
            <div class="myqs-name"><?php echo sanitize($t['service_name']); ?></div>
            <div class="myqs-date"><?php echo date('d M Y, g:i A', strtotime($t['created_at'])); ?></div>
        </div>
        <div class="myqs-badges">
            <span class="myqs-amount">GH₵<?php echo number_format((float)$t['amount'], 2); ?></span>
            <span class="myqs-badge <?php echo $payBadge[2]; ?>"><?php echo $payBadge[0]; ?> <?php echo $payBadge[1]; ?></span>
            <?php if ($t['payment_status'] === 'paid'): ?><span class="myqs-badge <?php echo $procBadge[2]; ?>"><?php echo $procBadge[0]; ?> <?php echo $procBadge[1]; ?></span><?php endif; ?>
        </div>
    </a>
    <?php endforeach; endif; ?>

    <?php if ($totalPages > 1): ?>
    <div style="display:flex;gap:8px;justify-content:center;margin-top:20px;flex-wrap:wrap;">
        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
        <a href="my_quick_services.php?page=<?php echo $p; ?>" class="button button-small <?php echo $p === $page ? 'button-primary' : 'button-secondary'; ?>"><?php echo $p; ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</main>

<?php require __DIR__ . '/partials/bottom_nav.php'; ?>
</body>
</html>
