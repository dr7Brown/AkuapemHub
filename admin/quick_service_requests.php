<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';

require_login();
if (!is_admin_or_manager()) { header('Location: index.php'); exit; }

$adminUser = current_user();
$isAdmin   = is_admin();

if (!$isAdmin) require_mod_permission('manage_quick_service_requests');

$managedServiceIds = $isAdmin
    ? array_column($pdo->query('SELECT id FROM quick_services')->fetchAll(), 'id')
    : get_managed_quick_service_ids((int)$adminUser['id']);

$flash = get_flash();
$noServicesAssigned = !$managedServiceIds;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $reqId  = (int)($_POST['request_id'] ?? 0);

    $reqSt = $pdo->prepare(
        "SELECT qsr.*, qs.name AS service_name FROM quick_service_requests qsr
         JOIN quick_services qs ON qs.id = qsr.service_id WHERE qsr.id = ?"
    );
    $reqSt->execute([$reqId]);
    $request = $reqSt->fetch();

    if (!$request || !user_can_manage_quick_service((int)$adminUser['id'], (int)$request['service_id'])) {
        flash('You are not assigned to manage this service.', 'error');
    } elseif ($action === 'accept' && $request['status'] === 'paid') {
        $pdo->prepare("UPDATE quick_service_requests SET status='processing', processed_by=?, updated_at=NOW() WHERE id=?")
            ->execute([$adminUser['id'], $reqId]);
        log_audit_action($adminUser['id'], 'quick_service_request_accepted', "Accepted Quick Service request #{$reqId} ({$request['service_name']})");
        notify_user((int)$request['user_id'], '🔧 Request in progress',
            "Your \"{$request['service_name']}\" request (Ref " . qs_reference($reqId) . ") is now being processed.", 'info');
        flash('Request accepted — now processing.', 'success');
    } elseif ($action === 'complete' && in_array($request['status'], ['paid', 'processing'], true)) {
        $response = trim($_POST['manager_response'] ?? '');
        if ($response === '') {
            flash('A response message is required to mark this request completed.', 'error');
        } else {
            $filePath = $request['response_file_path'];
            if (!empty($_FILES['response_file']['name'])) {
                $saved = save_uploaded_document($_FILES['response_file'], 'uploads/quick_services');
                if ($saved) {
                    $filePath = $saved;
                } else {
                    flash('File upload failed — only PDF/JPEG/PNG up to 10 MB are allowed. The response was not saved; please try again.', 'error');
                    header('Location: quick_service_requests.php'); exit;
                }
            }
            $pdo->prepare("UPDATE quick_service_requests SET status='completed', manager_response=?, response_file_path=?, processed_by=?, processed_at=NOW(), updated_at=NOW() WHERE id=?")
                ->execute([$response, $filePath, $adminUser['id'], $reqId]);
            log_audit_action($adminUser['id'], 'quick_service_request_completed', "Completed Quick Service request #{$reqId} ({$request['service_name']})");
            notify_user((int)$request['user_id'], '✅ Request completed',
                "Your \"{$request['service_name']}\" request (Ref " . qs_reference($reqId) . ") is complete. Check My Services for details.", 'success');
            flash('Request marked completed.', 'success');
        }
    } elseif ($action === 'unable' && in_array($request['status'], ['paid', 'processing'], true)) {
        $reason = trim($_POST['manager_response'] ?? '');
        if ($reason === '') {
            flash('A reason is required.', 'error');
        } else {
            $pdo->prepare("UPDATE quick_service_requests SET status='unable_to_process', manager_response=?, processed_by=?, processed_at=NOW(), updated_at=NOW() WHERE id=?")
                ->execute([$reason, $adminUser['id'], $reqId]);
            log_audit_action($adminUser['id'], 'quick_service_request_unable', "Marked Quick Service request #{$reqId} ({$request['service_name']}) unable to process");
            notify_user((int)$request['user_id'], '⚠️ Unable to process your request',
                "Your \"{$request['service_name']}\" request (Ref " . qs_reference($reqId) . ") could not be processed: {$reason}", 'error');
            flash('Request marked unable to process.', 'success');
        }
    } else {
        flash('That request is not in a state this action applies to.', 'error');
    }
    $backParams = [];
    if (!empty($_GET['service'])) $backParams[] = 'service=' . (int)$_GET['service'];
    if (!empty($_GET['view']))    $backParams[] = 'view=' . urlencode($_GET['view']);
    if (!empty($_GET['page']))    $backParams[] = 'page=' . (int)$_GET['page'];
    header('Location: quick_service_requests.php' . ($backParams ? '?' . implode('&', $backParams) : ''));
    exit;
}

/** Builds a quick_service_requests.php URL preserving the current view/service filters. */
function qr_page_url(int $page, string $view, int $serviceId): string {
    $params = ['view' => $view, 'page' => $page];
    if ($serviceId) $params['service'] = $serviceId;
    return 'quick_service_requests.php?' . http_build_query($params);
}

$filterServiceId = (int)($_GET['service'] ?? 0);
if ($filterServiceId && !in_array($filterServiceId, $managedServiceIds, true)) $filterServiceId = 0;

$viewTabs = [
    'active'    => "qsr.status IN ('paid','processing')",
    'completed' => "qsr.status = 'completed'",
    'unable'    => "qsr.status = 'unable_to_process'",
    'all'       => null,
];
$view = $_GET['view'] ?? 'active';
if (!isset($viewTabs[$view])) $view = 'active';

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 30;

$services   = [];
$requests   = [];
$total      = 0;
$totalPages = 1;
$revenue    = ['count' => 0, 'service_amount' => 0, 'service_fee' => 0, 'total' => 0];
if (!$noServicesAssigned) {
    $services = $pdo->query(
        'SELECT id, name FROM quick_services WHERE id IN (' . implode(',', array_map('intval', $managedServiceIds)) . ') ORDER BY name'
    )->fetchAll();

    $where  = ["qsr.service_id IN (" . implode(',', array_map('intval', $managedServiceIds)) . ")"];
    if ($viewTabs[$view]) $where[] = $viewTabs[$view];
    $params = [];
    if ($filterServiceId) { $where[] = 'qsr.service_id = ?'; $params[] = $filterServiceId; }
    $whereSql = implode(' AND ', $where);

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM quick_service_requests qsr WHERE {$whereSql}");
    $countStmt->execute($params);
    $total      = (int)$countStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($total / $perPage));
    $offset     = ($page - 1) * $perPage;

    $requests = $pdo->prepare(
        "SELECT qsr.*, qs.name AS service_name, qs.icon, qs.image_path, qs.form_fields, u.name AS buyer_name, u.phone AS buyer_phone
         FROM quick_service_requests qsr
         JOIN quick_services qs ON qsr.service_id = qs.id
         JOIN users u ON qsr.user_id = u.id
         WHERE {$whereSql}
         ORDER BY FIELD(qsr.status,'paid','processing'), qsr.created_at DESC, qsr.id DESC
         LIMIT {$perPage} OFFSET {$offset}"
    );
    $requests->execute($params);
    $requests = $requests->fetchAll();

    // Revenue summary — completed requests only, scoped to the same managed
    // services (and the service filter, if one is active), regardless of tab.
    $revWhere = ["qsr.service_id IN (" . implode(',', array_map('intval', $managedServiceIds)) . ")", "qsr.status = 'completed'"];
    $revParams = [];
    if ($filterServiceId) { $revWhere[] = 'qsr.service_id = ?'; $revParams[] = $filterServiceId; }
    $revStmt = $pdo->prepare(
        "SELECT COUNT(*) AS count, COALESCE(SUM(service_amount),0) AS service_amount,
                COALESCE(SUM(service_fee),0) AS service_fee, COALESCE(SUM(total_amount),0) AS total
         FROM quick_service_requests qsr WHERE " . implode(' AND ', $revWhere)
    );
    $revStmt->execute($revParams);
    $revenue = $revStmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quick Service Requests — Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .qr-shell { max-width: 900px; margin: 0 auto; padding: 18px 16px 60px; }
        .qr-card  { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; padding: 16px 18px; margin-bottom: 12px; }
        .qr-head  { display:flex; justify-content:space-between; align-items:flex-start; gap:10px; flex-wrap:wrap; }
        .qr-badge { display:inline-block; padding:2px 9px; border-radius:20px; font-size:.68rem; font-weight:800; }
        .qr-badge.pending_payment { background:#f3f4f6; color:#6b7280; }
        .qr-badge.paid       { background:#fef3c7; color:#92400e; }
        .qr-badge.processing { background:#dbeafe; color:#1e40af; }
        .qr-badge.completed  { background:#d1fae5; color:#065f46; }
        .qr-badge.unable_to_process { background:#fee2e2; color:#991b1b; }
        .qr-badge.cancelled  { background:#f3f4f6; color:#6b7280; }
        .qr-data  { font-size:.82rem; color:var(--text-muted,#6b7280); margin:8px 0; background:var(--surface-muted,#f8fafc); border-radius:8px; padding:8px 10px; }
        .qr-data-row { display:flex; justify-content:space-between; padding:2px 0; gap:10px; }
        .qr-empty { text-align:center; padding:50px 20px; color:var(--text-muted,#6b7280); }
        .qr-response-form { margin-top:10px; display:flex; flex-direction:column; gap:6px; }
        .qr-response-form textarea { width:100%; box-sizing:border-box; padding:8px 10px; border:1px solid var(--border); border-radius:8px; font-size:.84rem; resize:vertical; min-height:56px; }
        .qr-response-view { background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:8px 11px; margin-top:8px; font-size:.82rem; line-height:1.55; }
        .qr-response-view strong { color:#1d4ed8; }
        .qr-revenue { display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:10px; margin-bottom:14px; }
        .qr-revenue > div { background:var(--surface); border:1px solid var(--border); border-radius:10px; padding:10px 12px; text-align:center; }
        .qr-revenue .n { display:block; font-size:1.05rem; font-weight:800; color:var(--primary,#0f766e); }
        .qr-revenue .l { font-size:.7rem; color:var(--text-muted,#6b7280); }
        .qr-tabs { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:14px; }
        .qr-tab { padding:6px 12px; border-radius:20px; font-size:.8rem; font-weight:700; text-decoration:none; color:var(--text-muted,#6b7280); background:var(--surface-muted,#f3f4f6); }
        .qr-tab.active { background:var(--primary,#0f766e); color:#fff; }
        .qr-pagination { display:flex; gap:4px; flex-wrap:wrap; align-items:center; margin-top:14px; }
        .qr-pagination a, .qr-pagination .current { padding:5px 10px; border-radius:6px; border:1px solid var(--border); text-decoration:none; font-size:.82rem; color:var(--text); }
        .qr-pagination a:hover { background:var(--surface-muted,#f9fafb); }
        .qr-pagination .current { background:var(--primary,#0f766e); color:#fff; border-color:var(--primary,#0f766e); }
        .qr-page-total { font-size:.78rem; color:var(--text-muted,#6b7280); margin-left:4px; }
        .hidden-form { display:none; }
    </style>
</head>
<body>

<header class="topbar">
    <a href="quick_services.php" class="button button-secondary button-small">← Quick Services</a>
    <h1 style="margin:0;font-size:1rem;font-weight:800;">📥 Quick Service Requests</h1>
</header>

<main class="qr-shell">

    <?php if ($flash): ?>
    <div class="alert alert-<?php echo sanitize($flash['type']); ?>" style="margin-bottom:14px;"><?php echo sanitize($flash['message']); ?></div>
    <?php endif; ?>

    <p style="font-size:.84rem;color:var(--text-muted,#6b7280);margin-bottom:14px;">
        Requests paid for on a service you manage. <strong>Accept</strong> a newly-paid request to start
        processing it, then <strong>Mark Completed</strong> with a response (and an optional result file)
        once done — or <strong>Unable to Process</strong> with a reason if it can't be fulfilled.
    </p>

    <?php if ($noServicesAssigned): ?>
    <div class="qr-empty">
        <div style="font-size:2.5rem;opacity:.35;margin-bottom:10px;">⚡</div>
        <p style="margin:0;font-weight:700;">You're not assigned to manage any service yet</p>
        <p style="margin:6px 0 0;font-size:.85rem;">Ask an admin to assign you via <a href="quick_services.php">Quick Services</a>.</p>
    </div>
    <?php else: ?>

    <div class="qr-revenue">
        <div><span class="n"><?php echo (int)$revenue['count']; ?></span><span class="l">Completed<?php echo $filterServiceId ? '' : ' (all assigned)'; ?></span></div>
        <div><span class="n">GH₵ <?php echo number_format((float)$revenue['service_amount'], 2); ?></span><span class="l">Service Cost</span></div>
        <div><span class="n">GH₵ <?php echo number_format((float)$revenue['service_fee'], 2); ?></span><span class="l">Service Fee (revenue)</span></div>
        <div><span class="n">GH₵ <?php echo number_format((float)$revenue['total'], 2); ?></span><span class="l">Total Collected</span></div>
    </div>

    <div class="qr-tabs">
        <?php
        $tabLabels = ['active' => '🟡 Needs Action', 'completed' => '🟢 Completed', 'unable' => '🔴 Unable to Process', 'all' => 'All'];
        foreach ($tabLabels as $tKey => $tLabel):
            $tUrl = 'quick_service_requests.php?view=' . $tKey . ($filterServiceId ? '&service=' . $filterServiceId : '');
        ?>
        <a href="<?php echo $tUrl; ?>" class="qr-tab <?php echo $view === $tKey ? 'active' : ''; ?>"><?php echo $tLabel; ?></a>
        <?php endforeach; ?>
    </div>

    <?php if (count($services) > 1): ?>
    <form method="get" style="margin-bottom:14px;display:flex;gap:8px;align-items:center;">
        <input type="hidden" name="view" value="<?php echo sanitize($view); ?>">
        <label style="font-size:.82rem;font-weight:600;">Service:</label>
        <select name="service" onchange="this.form.submit()" style="padding:6px 10px;border:1px solid var(--border);border-radius:8px;font-size:.84rem;">
            <option value="">All assigned services</option>
            <?php foreach ($services as $s): ?>
            <option value="<?php echo $s['id']; ?>" <?php echo $filterServiceId===(int)$s['id']?'selected':''; ?>><?php echo sanitize($s['name']); ?></option>
            <?php endforeach; ?>
        </select>
    </form>
    <?php endif; ?>

    <?php if (!$requests): ?>
    <div class="qr-empty">
        <div style="font-size:2.5rem;opacity:.35;margin-bottom:10px;">📥</div>
        <p style="margin:0;font-weight:700;">No requests in this view</p>
    </div>
    <?php else: ?>
    <?php foreach ($requests as $r): $dataRows = qs_request_data_rows($r, json_decode($r['request_data'], true) ?: []); $isActive = in_array($r['status'], ['paid', 'processing'], true); ?>
    <div class="qr-card">
        <div class="qr-head">
            <div>
                <strong>
                    <?php if (!empty($r['image_path'])): ?>
                    <img src="../<?php echo sanitize($r['image_path']); ?>" alt="" style="width:22px;height:22px;border-radius:50%;object-fit:cover;vertical-align:middle;margin-right:3px;">
                    <?php else: ?>
                    <?php echo sanitize($r['icon']) ?: '⚡'; ?>
                    <?php endif; ?>
                    <?php echo sanitize($r['service_name']); ?>
                </strong>
                <span style="color:var(--text-muted,#6b7280);font-size:.78rem;"> · Ref <?php echo qs_reference((int)$r['id']); ?></span>
                <div style="font-size:.82rem;color:var(--text-muted,#6b7280);margin-top:2px;">
                    Customer: <?php echo sanitize($r['buyer_name']); ?><?php echo $r['buyer_phone'] ? ' · ' . sanitize($r['buyer_phone']) : ''; ?>
                </div>
            </div>
            <span class="qr-badge <?php echo $r['status']; ?>">
                <?php echo ['pending_payment' => 'Pending Payment', 'paid' => 'New — Needs Acceptance', 'processing' => 'Processing', 'completed' => 'Completed', 'unable_to_process' => 'Unable to Process', 'cancelled' => 'Cancelled'][$r['status']] ?? ucwords(str_replace('_', ' ', $r['status'])); ?>
            </span>
        </div>

        <div class="qr-data">
            <?php foreach ($dataRows as $row): ?>
            <div class="qr-data-row"><span><?php echo sanitize($row['label']); ?></span><span><strong><?php echo sanitize($row['value']); ?></strong></span></div>
            <?php endforeach; ?>
            <div class="qr-data-row"><span>Service Cost</span><span>GH₵ <?php echo number_format((float)$r['service_amount'], 2); ?></span></div>
            <div class="qr-data-row"><span>Service Fee</span><span>GH₵ <?php echo number_format((float)$r['service_fee'], 2); ?></span></div>
            <div class="qr-data-row"><span><strong>Total Paid</strong></span><span><strong>GH₵ <?php echo number_format((float)$r['total_amount'], 2); ?></strong></span></div>
        </div>

        <?php if (!$isActive): ?>
        <?php if ($r['manager_response']): ?>
        <div class="qr-response-view">
            <strong><?php echo $r['status'] === 'unable_to_process' ? 'Reason:' : 'Response:'; ?></strong>
            <?php echo nl2br(sanitize($r['manager_response'])); ?>
            <?php if ($r['response_file_path']): ?>
            <br><a href="../<?php echo sanitize($r['response_file_path']); ?>" target="_blank" rel="noopener">📎 View attachment</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if ($r['processed_at']): ?>
        <p style="font-size:.76rem;color:var(--text-muted,#6b7280);margin:8px 0 0;">Processed <?php echo date('d M Y, g:i A', strtotime($r['processed_at'])); ?></p>
        <?php endif; ?>
        <?php else: ?>

        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <?php if ($r['status'] === 'paid'): ?>
            <form method="post" style="display:inline;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="accept">
                <input type="hidden" name="request_id" value="<?php echo (int)$r['id']; ?>">
                <button type="submit" class="button button-primary button-small">✅ Accept &amp; Start Processing</button>
            </form>
            <?php endif; ?>
            <button type="button" class="button button-secondary button-small" onclick="document.getElementById('unable-<?php echo $r['id']; ?>').classList.toggle('hidden-form')">🚫 Unable to Process</button>
        </div>

        <form method="post" enctype="multipart/form-data" class="qr-response-form">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="complete">
            <input type="hidden" name="request_id" value="<?php echo (int)$r['id']; ?>">
            <textarea name="manager_response" placeholder="Response to the customer (e.g. 'Your ECG token is 1234-5678-9012')" required></textarea>
            <input type="file" name="response_file" accept=".pdf,image/jpeg,image/png">
            <button type="submit" class="button button-primary button-small" style="align-self:flex-start;background:#10b981;border-color:transparent;">✅ Mark Completed</button>
        </form>

        <form method="post" id="unable-<?php echo $r['id']; ?>" class="qr-response-form hidden-form">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="unable">
            <input type="hidden" name="request_id" value="<?php echo (int)$r['id']; ?>">
            <textarea name="manager_response" placeholder="Reason this request can't be fulfilled" required></textarea>
            <button type="submit" class="button button-secondary button-small" style="align-self:flex-start;color:#c0392b;">🚫 Confirm Unable to Process</button>
        </form>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php if ($totalPages > 1): ?>
    <div class="qr-pagination">
        <?php if ($page > 1): ?><a href="<?php echo sanitize(qr_page_url($page - 1, $view, $filterServiceId)); ?>">‹ Prev</a><?php endif; ?>
        <?php for ($p = max(1, $page - 3); $p <= min($totalPages, $page + 3); $p++): ?>
            <?php if ($p === $page): ?><span class="current"><?php echo $p; ?></span>
            <?php else: ?><a href="<?php echo sanitize(qr_page_url($p, $view, $filterServiceId)); ?>"><?php echo $p; ?></a>
            <?php endif; ?>
        <?php endfor; ?>
        <?php if ($page < $totalPages): ?><a href="<?php echo sanitize(qr_page_url($page + 1, $view, $filterServiceId)); ?>">Next ›</a><?php endif; ?>
        <span class="qr-page-total">Page <?php echo $page; ?> of <?php echo $totalPages; ?> (<?php echo $total; ?> total)</span>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <?php endif; ?>

</main>
</body>
</html>
