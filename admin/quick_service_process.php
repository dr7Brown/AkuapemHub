<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../quick_service_functions.php';

require_login();
if (!is_admin_or_manager()) { header('Location: index.php'); exit; }
require_mod_permission('manage_quick_service_requests');
$user = current_user();

$id = (int)($_GET['id'] ?? 0);
$tx = $id ? qs_get_transaction($id) : null;
if (!$tx) { header('Location: quick_service_requests.php'); exit; }
if (!user_can_manage_quick_service($user['id'], (int)$tx['service_id'])) {
    flash('You are not assigned to manage this service.', 'error');
    header('Location: quick_service_requests.php'); exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'assign') {
        $mgrId = (int)($_POST['manager_id'] ?? 0);
        if ($mgrId && user_can_manage_quick_service($mgrId, (int)$tx['service_id'])) {
            $pdo->prepare("UPDATE quick_transactions SET assigned_manager_id=?, processing_status=IF(processing_status='awaiting_assignment','assigned',processing_status), assigned_at=NOW(), updated_at=NOW() WHERE id=?")
                ->execute([$mgrId, $id]);
            log_audit_action($user['id'], 'quick_service_request_assigned', "Assigned {$tx['reference']} to user #{$mgrId}");
            notify_user($mgrId, 'Quick Services Request Assigned', "{$tx['reference']} ({$tx['service_name']}) has been assigned to you.", 'info', 'admin/quick_service_process.php?id=' . $id);
        } elseif ($mgrId) {
            flash('That user is not an authorized manager for this service.', 'error');
        }
        header('Location: quick_service_process.php?id=' . $id); exit;

    } elseif ($action === 'mark_processing') {
        $pdo->prepare("UPDATE quick_transactions SET processing_status='processing', updated_at=NOW() WHERE id=?")->execute([$id]);
        log_audit_action($user['id'], 'quick_service_request_processing', "Marked {$tx['reference']} as processing");
        notify_user((int)$tx['user_id'], 'Request In Progress', "Your Quick Services request {$tx['reference']} is now being processed.", 'info', 'quick_service_view.php?ref=' . $tx['reference']);
        header('Location: quick_service_process.php?id=' . $id); exit;

    } elseif ($action === 'mark_completed') {
        $notes = trim($_POST['notes'] ?? '') ?: null;
        $filePath = $tx['result_file_path'];
        if (!empty($_FILES['result_file']['name'])) {
            $saved = qs_save_result_file($_FILES['result_file']);
            if ($saved) { $filePath = $saved; } else { $errors[] = 'Result file upload failed — only PDF/JPG/PNG up to 10MB are allowed.'; }
        }
        if (!$errors) {
            $pdo->prepare("UPDATE quick_transactions SET processing_status='completed', manager_notes=?, result_file_path=?, completed_at=NOW(), updated_at=NOW() WHERE id=?")
                ->execute([$notes, $filePath, $id]);
            log_audit_action($user['id'], 'quick_service_request_completed', "Marked {$tx['reference']} completed");
            notify_user((int)$tx['user_id'], '✅ Request Completed', "Your Quick Services request {$tx['reference']} has been completed successfully." . ($filePath ? ' Your document is ready to download.' : ''), 'success', 'quick_service_view.php?ref=' . $tx['reference']);
            sms_user((int)$tx['user_id'], 'quick_service_completed', ['reference' => $tx['reference'], 'service_name' => $tx['service_name']]);
            header('Location: quick_service_process.php?id=' . $id); exit;
        }

    } elseif ($action === 'mark_failed') {
        $reason = trim($_POST['notes'] ?? '');
        $refundResult = qs_process_refund($tx);
        $pdo->prepare("UPDATE quick_transactions SET processing_status='failed', payment_status=IF(?, 'refunded', payment_status), manager_notes=?, updated_at=NOW() WHERE id=?")
            ->execute([$refundResult['ok'] ? 1 : 0, $reason ?: null, $id]);
        log_audit_action($user['id'], 'quick_service_request_failed', "Marked {$tx['reference']} unable to process" . ($refundResult['ok'] ? ' (refunded)' : ' (refund failed: ' . $refundResult['error'] . ')'));
        notify_user((int)$tx['user_id'], '❌ Unable to Process Request', "We were unable to process your request {$tx['reference']}." . ($reason ? " Reason: {$reason}" : '') . ($refundResult['ok'] ? ' Your payment has been refunded.' : ''), 'error', 'quick_service_view.php?ref=' . $tx['reference']);
        if (!$refundResult['ok']) $errors[] = 'Marked as unable to process, but the automatic refund failed: ' . $refundResult['error'] . '. Please refund manually.';
        if (!$errors) { header('Location: quick_service_process.php?id=' . $id); exit; }

    } elseif ($action === 'save_notes') {
        $pdo->prepare("UPDATE quick_transactions SET manager_notes=?, updated_at=NOW() WHERE id=?")->execute([trim($_POST['notes'] ?? '') ?: null, $id]);
        header('Location: quick_service_process.php?id=' . $id); exit;
    }

    $tx = qs_get_transaction($id); // refresh after any state change/error
}

$requestData = json_decode($tx['request_data'] ?? '[]', true) ?: [];
$payBadge  = qs_payment_badge($tx['payment_status']);
$procBadge = qs_processing_badge($tx['processing_status']);
$serviceManagers = $pdo->prepare("SELECT u.id, u.name FROM quick_service_managers qm JOIN users u ON u.id=qm.user_id WHERE qm.service_id=? ORDER BY u.name");
$serviceManagers->execute([$tx['service_id']]);
$serviceManagers = $serviceManagers->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo sanitize($tx['reference']); ?> — Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .qsp-shell  { max-width:600px; margin:0 auto; padding:20px 16px 60px; }
        .qsp-card   { background:var(--surface,#fff); border:1px solid var(--border); border-radius:14px; padding:18px; margin-bottom:16px; }
        .qsp-section { font-size:.74rem; font-weight:800; text-transform:uppercase; letter-spacing:.06em; color:var(--text-muted); margin:0 0 12px; }
        .qsp-row    { display:flex; justify-content:space-between; padding:6px 0; font-size:.86rem; border-bottom:1px solid var(--border); }
        .qsp-row:last-child { border-bottom:none; }
        .qsp-row strong { color:var(--text-muted); font-weight:600; }
        .qsp-badge  { font-size:.68rem; font-weight:800; padding:3px 10px; border-radius:20px; }
        .qsp-badge.pending { background:#fffbeb; color:#92400e; }
        .qsp-badge.completed { background:#ecfdf5; color:#065f46; }
        .qsp-badge.processing { background:#eff6ff; color:#1d4ed8; }
        .qsp-badge.failed, .qsp-badge.cancelled { background:#fee2e2; color:#991b1b; }
        .qsp-actions { display:flex; gap:8px; flex-wrap:wrap; }
    </style>
</head>
<body>
    <header class="topbar">
        <a href="quick_service_requests.php" class="button button-secondary button-small">← Requests</a>
        <h1><?php echo sanitize($tx['reference']); ?></h1>
    </header>

    <div class="qsp-shell">
        <?php foreach ($errors as $e): ?><div class="alert alert-error" style="margin-bottom:12px;"><?php echo sanitize($e); ?></div><?php endforeach; ?>

        <div class="qsp-card">
            <div style="display:flex;gap:8px;margin-bottom:14px;">
                <span class="qsp-badge <?php echo $payBadge[2]; ?>"><?php echo $payBadge[0]; ?> <?php echo $payBadge[1]; ?></span>
                <span class="qsp-badge <?php echo $procBadge[2]; ?>"><?php echo $procBadge[0]; ?> <?php echo $procBadge[1]; ?></span>
            </div>
            <p class="qsp-section">Transaction</p>
            <div class="qsp-row"><strong>Service</strong><span><?php echo sanitize($tx['service_name']); ?></span></div>
            <div class="qsp-row"><strong>Customer</strong><span><?php echo sanitize($tx['customer_name']); ?></span></div>
            <div class="qsp-row"><strong>Phone</strong><span><?php echo sanitize($tx['customer_phone'] ?: '—'); ?></span></div>
            <div class="qsp-row"><strong>Amount</strong><span>GH₵<?php echo number_format((float)$tx['amount'], 2); ?></span></div>
            <div class="qsp-row"><strong>Created</strong><span><?php echo date('d M Y, g:i A', strtotime($tx['created_at'])); ?></span></div>
        </div>

        <div class="qsp-card">
            <p class="qsp-section">Submitted Details</p>
            <?php foreach ($requestData as $k => $v): ?>
            <div class="qsp-row"><strong><?php echo sanitize(ucwords(str_replace('_',' ',$k))); ?></strong><span><?php echo sanitize((string)$v); ?></span></div>
            <?php endforeach; ?>
        </div>

        <?php if (!in_array($tx['processing_status'], ['completed','failed','cancelled'], true)): ?>
        <div class="qsp-card">
            <p class="qsp-section">Assign Manager</p>
            <form method="post" style="display:flex;gap:8px;">
                <input type="hidden" name="action" value="assign">
                <?php echo csrf_field(); ?>
                <select name="manager_id" required style="flex:1;padding:8px 10px;border:1px solid var(--border);border-radius:8px;">
                    <option value="">— Select manager —</option>
                    <?php foreach ($serviceManagers as $m): ?><option value="<?php echo (int)$m['id']; ?>" <?php echo (int)$tx['assigned_manager_id']===(int)$m['id']?'selected':''; ?>><?php echo sanitize($m['name']); ?></option><?php endforeach; ?>
                </select>
                <button type="submit" class="button button-primary button-small">Assign</button>
            </form>
        </div>

        <div class="qsp-card">
            <p class="qsp-section">Actions</p>
            <div class="qsp-actions">
                <?php if (in_array($tx['processing_status'], ['awaiting_assignment','assigned'], true)): ?>
                <form method="post"><input type="hidden" name="action" value="mark_processing"><?php echo csrf_field(); ?><button class="button button-small button-primary">Mark Processing</button></form>
                <?php endif; ?>
            </div>

            <form method="post" enctype="multipart/form-data" style="margin-top:14px;padding-top:14px;border-top:1px solid var(--border);">
                <input type="hidden" name="action" value="mark_completed">
                <?php echo csrf_field(); ?>
                <p style="font-size:.82rem;font-weight:700;margin:0 0 8px;">✅ Mark Completed</p>
                <?php if ($tx['service_type'] === 'result_service'): ?>
                <div style="margin-bottom:8px;"><label style="font-size:.8rem;font-weight:600;display:block;margin-bottom:4px;">Result document (optional — checker-only requests don't need one)</label>
                <input type="file" name="result_file" accept="application/pdf,image/jpeg,image/png"></div>
                <?php endif; ?>
                <textarea name="notes" rows="2" placeholder="Notes for the customer (e.g. checker code, or leave blank)" style="width:100%;box-sizing:border-box;margin-bottom:8px;"><?php echo sanitize($tx['manager_notes'] ?? ''); ?></textarea>
                <button type="submit" class="button button-primary">Mark Completed</button>
            </form>

            <form method="post" style="margin-top:14px;padding-top:14px;border-top:1px solid var(--border);">
                <input type="hidden" name="action" value="mark_failed">
                <?php echo csrf_field(); ?>
                <p style="font-size:.82rem;font-weight:700;margin:0 0 8px;">❌ Unable to Process (refunds the customer)</p>
                <textarea name="notes" rows="2" placeholder="Reason (shown to the customer)" style="width:100%;box-sizing:border-box;margin-bottom:8px;"></textarea>
                <button type="submit" class="button button-small" style="background:#fee2e2;color:#991b1b;border-color:#fca5a5;" onclick="return confirm('Mark unable to process and refund the customer?')">Mark Unable to Process &amp; Refund</button>
            </form>
        </div>
        <?php else: ?>
        <div class="qsp-card">
            <p class="qsp-section">Notes</p>
            <p style="margin:0;line-height:1.6;"><?php echo $tx['manager_notes'] ? nl2br(sanitize($tx['manager_notes'])) : '<span class="meta">No notes.</span>'; ?></p>
            <?php if ($tx['result_file_path']): ?><p style="margin-top:10px;"><a href="../qs_result.php?ref=<?php echo urlencode($tx['reference']); ?>" class="button button-small">📄 View Result Document</a></p><?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
