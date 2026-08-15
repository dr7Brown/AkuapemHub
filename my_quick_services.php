<?php
/**
 * "My Services" — a user's own Quick Service requests: status, the
 * manager's response, and a link to any result file they attached.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_module_enabled('quick_services', 'Quick Services');
require_login();
$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel') {
    csrf_check();
    $reqId = (int)($_POST['request_id'] ?? 0);
    $pdo->prepare("UPDATE quick_service_requests SET status='cancelled', updated_at=NOW() WHERE id=? AND user_id=? AND status='pending_payment'")
        ->execute([$reqId, $user['id']]);
    flash('Request cancelled.', 'success');
    header('Location: my_quick_services.php'); exit;
}

$stmt = $pdo->prepare("SELECT qsr.*, qs.name AS service_name, qs.icon, qs.image_path, qs.form_fields
    FROM quick_service_requests qsr
    JOIN quick_services qs ON qs.id = qsr.service_id
    WHERE qsr.user_id = ?
    ORDER BY qsr.created_at DESC LIMIT 100");
$stmt->execute([$user['id']]);
$requests = $stmt->fetchAll();

function qsBadge(string $status): array {
    $map = [
        'pending_payment'   => ['⏳', 'PENDING PAYMENT', 'pending'],
        'paid'              => ['🟡', 'RECEIVED', 'processing'],
        'processing'        => ['🟡', 'PROCESSING', 'processing'],
        'completed'         => ['🟢', 'COMPLETED', 'completed'],
        'unable_to_process' => ['🔴', 'UNABLE TO PROCESS', 'failed'],
        'cancelled'         => ['⚪', 'CANCELLED', 'cancelled'],
    ];
    return $map[$status] ?? ['⚪', strtoupper($status), 'pending'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Services — <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .myqs-row { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; padding:14px 0; border-bottom:1px solid var(--border,#e5e7eb); flex-wrap:wrap; }
        .myqs-row:last-child { border-bottom:none; }
        .myqs-info { flex:1; min-width:220px; }
        .myqs-info h3 { margin:0 0 3px; font-size:.92rem; }
        .myqs-info .meta { margin:2px 0 0; font-size:.78rem; color:var(--muted,#6b7280); }
        .myqs-actions { display:flex; flex-direction:column; align-items:flex-end; gap:6px; flex-shrink:0; }
        .myqs-badge { display:inline-block; padding:2px 8px; border-radius:12px; font-size:.72rem; font-weight:700; white-space:nowrap; }
        .myqs-badge.pending    { background:#fef9c3; color:#a16207; }
        .myqs-badge.processing { background:#fef3c7; color:#92400e; }
        .myqs-badge.completed  { background:#d1fae5; color:#065f46; }
        .myqs-badge.failed     { background:#fee2e2; color:#991b1b; }
        .myqs-badge.cancelled  { background:#f3f4f6; color:#6b7280; }
        .myqs-response { background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:8px 11px; margin-top:8px; font-size:.8rem; line-height:1.55; width:100%; }
        .myqs-response strong { color:#1d4ed8; }
        .myqs-details { width:100%; margin-top:6px; font-size:.8rem; }
        .myqs-details summary { cursor:pointer; color:var(--primary,#0f766e); font-weight:600; font-size:.78rem; }
        .myqs-details-body { background:var(--surface-muted,#f8fafc); border-radius:8px; padding:8px 10px; margin-top:6px; }
        .myqs-details-row { display:flex; justify-content:space-between; gap:10px; padding:2px 0; color:var(--muted,#6b7280); }
        .myqs-details-row strong { color:var(--text,#1f2937); font-weight:600; text-align:right; }
    </style>
</head>
<body class="has-bottom-nav">

<header class="app-topbar">
    <span class="brand">⚡ My Services</span>
    <a href="quick_services.php" class="button button-secondary button-small">+ New Request</a>
</header>

<main class="page-shell">
    <?php foreach (get_flashes() as $f): ?>
    <div class="alert alert-<?php echo sanitize($f['type']); ?>"><?php echo sanitize($f['message']); ?></div>
    <?php endforeach; ?>

    <?php if (!$requests): ?>
    <div class="empty-state">
        <p>No service requests yet.</p>
        <div style="margin-top:10px;"><a href="quick_services.php" class="button button-primary">Browse Services</a></div>
    </div>
    <?php else: ?>
    <div class="panel">
        <?php foreach ($requests as $r): [$icon, $label, $cls] = qsBadge($r['status']); ?>
        <div class="myqs-row">
            <div class="myqs-info">
                <h3>
                    <?php if (!empty($r['image_path'])): ?>
                    <img src="<?php echo sanitize($r['image_path']); ?>" alt="" style="width:22px;height:22px;border-radius:50%;object-fit:cover;vertical-align:middle;margin-right:3px;">
                    <?php else: ?>
                    <?php echo sanitize($r['icon']) ?: '⚡'; ?>
                    <?php endif; ?>
                    <?php echo sanitize($r['service_name']); ?>
                </h3>
                <p class="meta">Ref: <?php echo qs_reference((int)$r['id']); ?> · <?php echo date('d M Y', strtotime($r['created_at'])); ?></p>
                <p class="meta">GH₵ <?php echo number_format((float)$r['total_amount'], 2); ?></p>
                <?php $detailRows = qs_request_data_rows($r, json_decode($r['request_data'], true) ?: []); ?>
                <?php if ($detailRows): ?>
                <details class="myqs-details">
                    <summary>View what you submitted</summary>
                    <div class="myqs-details-body">
                        <?php foreach ($detailRows as $row): ?>
                        <div class="myqs-details-row"><span><?php echo sanitize($row['label']); ?></span><strong><?php echo sanitize($row['value']); ?></strong></div>
                        <?php endforeach; ?>
                    </div>
                </details>
                <?php endif; ?>
                <?php if ($r['manager_response']): ?>
                <div class="myqs-response">
                    <strong><?php echo $r['status'] === 'unable_to_process' ? 'Note from our team:' : 'Response:'; ?></strong>
                    <?php echo nl2br(sanitize($r['manager_response'])); ?>
                    <?php if ($r['response_file_path']): ?>
                    <br><a href="<?php echo sanitize($r['response_file_path']); ?>" target="_blank" rel="noopener" style="color:#1d4ed8;font-weight:700;">📎 Download attachment</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <div class="myqs-actions">
                <span class="myqs-badge <?php echo $cls; ?>"><?php echo $icon . ' ' . $label; ?></span>
                <?php if ($r['status'] === 'pending_payment'): ?>
                <a href="pay_quick_service.php?id=<?php echo (int)$r['id']; ?>" class="button button-small button-primary">Pay Now</a>
                <form method="post" onsubmit="return confirm('Cancel this request?');">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="cancel">
                    <input type="hidden" name="request_id" value="<?php echo (int)$r['id']; ?>">
                    <button type="submit" class="button button-small button-secondary">Cancel</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</main>

<?php $activeNav = 'myapps'; require __DIR__ . '/partials/bottom_nav.php'; ?>
</body>
</html>
