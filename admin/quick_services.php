<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../marketplace_functions.php'; // mp_unique_slug()

require_login();
if (!is_admin_or_manager()) { header('Location: index.php'); exit; }
require_mod_permission('manage_quick_services');

$adminUser = current_user();
$flash     = get_flash();
$error     = '';

/** Validates + normalizes the JSON field-builder payload posted from the form. */
function qs_parse_form_fields(string $raw): ?array {
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) return null;
    $out  = [];
    $seen = [];
    foreach ($decoded as $f) {
        $label = trim((string)($f['label'] ?? ''));
        $key   = trim((string)($f['key'] ?? ''));
        if ($label === '' || $key === '') continue;
        $key = preg_replace('/[^a-z0-9_]/', '', strtolower(str_replace(['-', ' '], '_', $key)));
        if ($key === '' || isset($seen[$key])) continue;
        $seen[$key] = true;
        $type = in_array($f['type'] ?? '', ['text', 'number', 'tel', 'password', 'textarea', 'select'], true) ? $f['type'] : 'text';
        $field = [
            'key'      => $key,
            'label'    => mb_substr($label, 0, 120),
            'type'     => $type,
            'required' => !empty($f['required']),
        ];
        if (!empty($f['placeholder'])) $field['placeholder'] = mb_substr(trim($f['placeholder']), 0, 150);
        if ($type === 'select') {
            $opts = array_values(array_filter(array_map('trim', explode(',', (string)($f['options'] ?? '')))));
            if ($opts) $field['options'] = array_map(fn($o) => mb_substr($o, 0, 80), $opts);
        }
        $out[] = $field;
    }
    return $out;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_service') {
        $id               = (int)($_POST['id'] ?? 0);
        $name             = trim($_POST['name'] ?? '');
        $icon             = trim($_POST['icon'] ?? '');
        $description      = trim($_POST['description'] ?? '');
        $instructions     = trim($_POST['instructions'] ?? '');
        $pricingMode      = ($_POST['pricing_mode'] ?? '') === 'user_entered' ? 'user_entered' : 'fixed';
        $baseCost         = max(0, (float)($_POST['base_cost'] ?? 0));
        $amountFieldKey   = trim($_POST['amount_field_key'] ?? '');
        $feeType          = ($_POST['service_fee_type'] ?? '') === 'percent' ? 'percent' : 'flat';
        $feeValue         = max(0, (float)($_POST['service_fee_value'] ?? 0));
        $displayOrder     = (int)($_POST['display_order'] ?? 0);
        $formFields       = qs_parse_form_fields($_POST['form_fields'] ?? '[]');

        $fieldTypesByKey = $formFields ? array_column($formFields, 'type', 'key') : [];

        if ($name === '') $error = 'Service name is required.';
        elseif ($formFields === null || !$formFields) $error = 'Add at least one valid form field.';
        elseif ($pricingMode === 'user_entered' && $amountFieldKey === '') $error = 'Set which field key holds the amount when pricing is "User-entered amount".';
        elseif ($pricingMode === 'user_entered' && !isset($fieldTypesByKey[$amountFieldKey])) $error = 'Amount Field Key must exactly match one of the field keys above.';
        elseif ($pricingMode === 'user_entered' && $fieldTypesByKey[$amountFieldKey] !== 'number') $error = 'Amount Field Key must point to a field of type "number" — otherwise the amount the buyer types can\'t be reliably read as a price.';

        // Keep the existing image unless a new one is uploaded.
        $imagePath = null;
        if ($id > 0) {
            $imgStmt = $pdo->prepare('SELECT image_path FROM quick_services WHERE id=?');
            $imgStmt->execute([$id]);
            $imagePath = $imgStmt->fetchColumn() ?: null;
        }
        if (!$error && !empty($_FILES['image']['name'])) {
            $newImage = save_uploaded_image($_FILES['image'], 'uploads/quick_services', 600, 85);
            if ($newImage) {
                $imagePath = $newImage;
            } else {
                $error = 'Image upload failed. Only JPEG/PNG/WebP up to 5 MB are allowed.';
            }
        }

        if (!$error) {
            $formFieldsJson = json_encode($formFields, JSON_UNESCAPED_UNICODE);
            if ($id > 0) {
                $pdo->prepare("UPDATE quick_services SET name=?, icon=?, image_path=?, description=?, instructions=?, form_fields=?,
                        pricing_mode=?, base_cost=?, amount_field_key=?, service_fee_type=?, service_fee_value=?, display_order=?, updated_at=NOW()
                    WHERE id=?")
                    ->execute([$name, $icon ?: null, $imagePath, $description ?: null, $instructions ?: null, $formFieldsJson,
                        $pricingMode, $baseCost, $pricingMode === 'user_entered' ? $amountFieldKey : null, $feeType, $feeValue, $displayOrder, $id]);
                log_audit_action($adminUser['id'], 'quick_service_edited', "Edited Quick Service #{$id}: '{$name}'");
                flash('Service updated.', 'success');
            } else {
                $slug = mp_unique_slug($name, 'quick_services', 'slug', $pdo);
                $pdo->prepare("INSERT INTO quick_services
                        (name, slug, icon, image_path, description, instructions, form_fields, pricing_mode, base_cost, amount_field_key, service_fee_type, service_fee_value, display_order, status)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,'inactive')")
                    ->execute([$name, $slug, $icon ?: null, $imagePath, $description ?: null, $instructions ?: null, $formFieldsJson,
                        $pricingMode, $baseCost, $pricingMode === 'user_entered' ? $amountFieldKey : null, $feeType, $feeValue, $displayOrder]);
                log_audit_action($adminUser['id'], 'quick_service_created', "Created Quick Service: '{$name}'");
                flash('Service added — it starts Inactive; review and activate it below.', 'success');
            }
            header('Location: quick_services.php'); exit;
        }
    } elseif ($action === 'toggle_status' && !empty($_POST['id'])) {
        $id  = (int)$_POST['id'];
        $row = $pdo->prepare('SELECT name, status FROM quick_services WHERE id=?');
        $row->execute([$id]); $row = $row->fetch();
        if ($row) {
            $newStatus = $row['status'] === 'active' ? 'inactive' : 'active';
            $pdo->prepare('UPDATE quick_services SET status=?, updated_at=NOW() WHERE id=?')->execute([$newStatus, $id]);
            log_audit_action($adminUser['id'], 'quick_service_status_toggled', "Quick Service '{$row['name']}' (#{$id}) set to {$newStatus}");
            flash("\"{$row['name']}\" is now " . ucfirst($newStatus) . ".", 'success');
        }
        header('Location: quick_services.php'); exit;
    } elseif ($action === 'delete_service' && !empty($_POST['id'])) {
        $id  = (int)$_POST['id'];
        $row = $pdo->prepare('SELECT name FROM quick_services WHERE id=?');
        $row->execute([$id]); $row = $row->fetch();
        if ($row) {
            $activeCount = $pdo->prepare("SELECT COUNT(*) FROM quick_service_requests WHERE service_id=? AND status NOT IN ('completed','unable_to_process','cancelled')");
            $activeCount->execute([$id]);
            if ((int)$activeCount->fetchColumn() > 0) {
                flash('Cannot delete — this service has requests still in progress. Deactivate it instead.', 'error');
            } else {
                $pdo->prepare('DELETE FROM quick_services WHERE id=?')->execute([$id]);
                log_audit_action($adminUser['id'], 'quick_service_deleted', "Deleted Quick Service '{$row['name']}' (#{$id})");
                flash('Service deleted.', 'success');
            }
        }
        header('Location: quick_services.php'); exit;
    } elseif ($action === 'assign_manager') {
        $serviceId = (int)($_POST['service_id'] ?? 0);
        $userId    = (int)($_POST['user_id'] ?? 0);
        if ($serviceId && $userId) {
            $pdo->prepare('INSERT IGNORE INTO quick_service_managers (service_id, user_id, granted_by) VALUES (?,?,?)')
                ->execute([$serviceId, $userId, $adminUser['id']]);
            log_audit_action($adminUser['id'], 'quick_service_manager_assigned', "Assigned user #{$userId} as manager of Quick Service #{$serviceId}");
            flash('Manager assigned.', 'success');
        }
        header('Location: quick_services.php?manage=' . $serviceId . '&q=' . urlencode($_POST['q'] ?? '')); exit;
    } elseif ($action === 'remove_manager') {
        $serviceId = (int)($_POST['service_id'] ?? 0);
        $userId    = (int)($_POST['user_id'] ?? 0);
        $pdo->prepare('DELETE FROM quick_service_managers WHERE service_id=? AND user_id=?')->execute([$serviceId, $userId]);
        log_audit_action($adminUser['id'], 'quick_service_manager_removed', "Removed user #{$userId} as manager of Quick Service #{$serviceId}");
        flash('Manager removed.', 'success');
        header('Location: quick_services.php?manage=' . $serviceId); exit;
    }
}

$services = $pdo->query(
    "SELECT qs.*,
        (SELECT COUNT(*) FROM quick_service_requests qsr WHERE qsr.service_id = qs.id AND qsr.status IN ('paid','processing')) AS pending_count
     FROM quick_services qs ORDER BY qs.display_order, qs.name"
)->fetchAll();

$managersByService = [];
$mgrRows = $pdo->query(
    "SELECT qsm.service_id, u.id AS user_id, u.name FROM quick_service_managers qsm JOIN users u ON qsm.user_id = u.id ORDER BY u.name"
)->fetchAll();
foreach ($mgrRows as $r) { $managersByService[$r['service_id']][] = $r; }

$manageServiceId = (int)($_GET['manage'] ?? 0);
$manageService   = null;
$mgrQ            = trim($_GET['q'] ?? '');
$mgrSearchResults = [];
if ($manageServiceId) {
    foreach ($services as $s) { if ((int)$s['id'] === $manageServiceId) { $manageService = $s; break; } }
    if ($manageService && $mgrQ !== '') {
        $searchStmt = $pdo->prepare(
            "SELECT id, name, email, role FROM users
             WHERE (name LIKE ? OR email LIKE ? OR username LIKE ?) AND role IN ('manager','admin')
             ORDER BY name LIMIT 20"
        );
        $like = '%' . $mgrQ . '%';
        $searchStmt->execute([$like, $like, $like]);
        $mgrSearchResults = $searchStmt->fetchAll();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quick Services — Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .qsa-shell { max-width: 980px; margin: 0 auto; padding: 18px 16px 60px; }
        .qsa-table { width: 100%; border-collapse: collapse; font-size: .84rem; }
        .qsa-table th { padding: 9px 12px; text-align: left; font-size: .72rem; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; color: var(--text-muted,#6b7280); border-bottom: 1px solid var(--border); background: var(--surface-muted,#f9fafb); }
        .qsa-table td { padding: 10px 12px; border-bottom: 1px solid var(--border,#f1f5f9); vertical-align: middle; }
        .qsa-table tr:last-child td { border-bottom: none; }
        .qsa-card { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; padding: 18px; margin-bottom: 16px; overflow-x: auto; }
        .qsa-form-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px,1fr)); gap: 12px; }
        .qsa-form-grid label { font-weight: 600; font-size: .82rem; display: block; margin-bottom: 4px; }
        .qsa-form-grid input[type=text], .qsa-form-grid input[type=number], .qsa-form-grid select, .qsa-form-grid textarea { width: 100%; padding: 7px 9px; border: 1px solid var(--border); border-radius: 8px; font-size: .84rem; box-sizing: border-box; }
        .qsa-badge { display:inline-block; padding:2px 9px; border-radius:20px; font-size:.68rem; font-weight:800; }
        .qsa-badge.active { background:#d1fae5; color:#065f46; }
        .qsa-badge.inactive { background:#fee2e2; color:#c0392b; }
        .qsa-mgr-chip { display:inline-flex; align-items:center; gap:5px; background:var(--surface-muted,#f3f4f6); border-radius:14px; padding:2px 8px 2px 10px; font-size:.76rem; margin:2px 3px 2px 0; }
        .qsa-mgr-chip button { border:none; background:none; color:#c0392b; cursor:pointer; font-weight:800; padding:0 2px; }
        .qsa-field-row { display:grid; grid-template-columns: 1.2fr .9fr .8fr auto auto 1fr auto; gap:6px; align-items:center; margin-bottom:8px; padding-bottom:8px; border-bottom:1px solid var(--border,#f1f5f9); }
        .qsa-field-row input, .qsa-field-row select { width:100%; padding:6px 8px; border:1px solid var(--border); border-radius:6px; font-size:.8rem; box-sizing:border-box; }
        .qsa-field-row .fr-remove { background:none; border:none; color:#c0392b; font-size:1rem; cursor:pointer; }
        @media (max-width:760px) { .qsa-field-row { grid-template-columns:1fr 1fr; } }
    </style>
</head>
<body>

<header class="topbar">
    <a href="index.php" class="button button-secondary button-small">← Dashboard</a>
    <h1 style="margin:0;font-size:1rem;font-weight:800;">⚡ Quick Services</h1>
    <a href="quick_service_requests.php" class="button button-primary button-small">📥 Service Requests</a>
</header>

<main class="qsa-shell">

    <?php if ($flash): ?>
    <div class="alert alert-<?php echo sanitize($flash['type']); ?>" style="margin-bottom:14px;"><?php echo sanitize($flash['message']); ?></div>
    <?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error" style="margin-bottom:14px;"><?php echo sanitize($error); ?></div><?php endif; ?>

    <p style="font-size:.84rem;color:var(--text-muted,#6b7280);margin-bottom:16px;">
        One reusable engine for every "digital service desk" request — Airtime, ECG, exam results, and
        more. Each service's form is fully configurable below (no code needed to add a new one). New
        services start <strong>Inactive</strong> — review the fee and assign a manager before switching one on.
    </p>

    <div class="qsa-card">
        <?php if (!$services): ?>
            <p style="text-align:center;color:var(--text-muted,#6b7280);padding:20px;">No services yet — add one below.</p>
        <?php else: ?>
        <table class="qsa-table">
            <thead><tr><th>Service</th><th>Pricing</th><th>Fee</th><th>Pending</th><th>Managers</th><th>Status</th><th style="text-align:right;">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($services as $s): ?>
            <tr>
                <td>
                    <?php if (!empty($s['image_path'])): ?>
                    <img src="../<?php echo sanitize($s['image_path']); ?>" alt="" style="width:24px;height:24px;border-radius:50%;object-fit:cover;vertical-align:middle;margin-right:4px;">
                    <?php else: ?>
                    <?php echo sanitize($s['icon']) ?: '⚡'; ?>
                    <?php endif; ?>
                    <strong><?php echo sanitize($s['name']); ?></strong>
                </td>
                <td>
                    <?php if ($s['pricing_mode'] === 'fixed'): ?>
                        Fixed — GH₵ <?php echo number_format((float)$s['base_cost'], 2); ?>
                    <?php else: ?>
                        User-entered (<?php echo sanitize($s['amount_field_key'] ?: '—'); ?>)
                    <?php endif; ?>
                </td>
                <td><?php echo $s['service_fee_type'] === 'percent' ? number_format((float)$s['service_fee_value'], 2) . '%' : 'GH₵ ' . number_format((float)$s['service_fee_value'], 2); ?></td>
                <td><?php echo (int)$s['pending_count']; ?></td>
                <td><?php echo isset($managersByService[$s['id']]) ? count($managersByService[$s['id']]) . ' assigned' : '<span style="color:var(--text-muted,#6b7280);">None</span>'; ?></td>
                <td><span class="qsa-badge <?php echo $s['status']; ?>"><?php echo ucfirst($s['status']); ?></span></td>
                <td style="text-align:right;white-space:nowrap;">
                    <a href="quick_services.php?manage=<?php echo (int)$s['id']; ?>#mgr-panel" class="button button-secondary button-small">Manage</a>
                    <button type="button" class="button button-secondary button-small" onclick='editService(<?php echo json_encode($s); ?>)'>Edit</button>
                    <form method="post" style="display:inline;">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="toggle_status">
                        <input type="hidden" name="id" value="<?php echo (int)$s['id']; ?>">
                        <button type="submit" class="button button-small" style="background:<?php echo $s['status']==='active' ? '#ef4444' : '#10b981'; ?>;color:#fff;border-color:transparent;">
                            <?php echo $s['status'] === 'active' ? 'Deactivate' : 'Activate'; ?>
                        </button>
                    </form>
                    <form method="post" style="display:inline;" onsubmit="return confirm('Delete this service permanently?');">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="delete_service">
                        <input type="hidden" name="id" value="<?php echo (int)$s['id']; ?>">
                        <button type="submit" class="button button-secondary button-small" style="color:#c0392b;">Delete</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <div class="qsa-card">
        <h2 id="qsa-form-heading" style="margin-top:0;font-size:1rem;">Add Service</h2>
        <form method="post" id="qsa-form" enctype="multipart/form-data">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="save_service">
            <input type="hidden" name="id" id="f_id" value="0">
            <input type="hidden" name="form_fields" id="f_form_fields" value="[]">

            <div class="qsa-form-grid">
                <div>
                    <label>Service Name *</label>
                    <input type="text" id="f_name" name="name" required placeholder="e.g. ECG Prepaid">
                </div>
                <div>
                    <label>Icon (emoji, used if no image is uploaded)</label>
                    <input type="text" id="f_icon" name="icon" placeholder="⚡" maxlength="4">
                </div>
                <div>
                    <label>Custom Image (optional, overrides icon)</label>
                    <input type="file" id="f_image" name="image" accept="image/jpeg,image/png,image/webp" onchange="if(this.files[0]){var p=document.getElementById('f_image_preview');p.src=URL.createObjectURL(this.files[0]);p.style.display='';}">
                    <img id="f_image_preview" src="" alt="" style="display:none;width:44px;height:44px;border-radius:50%;object-fit:cover;margin-top:6px;">
                </div>
                <div>
                    <label>Display Order</label>
                    <input type="number" id="f_display_order" name="display_order" value="0">
                </div>
                <div>
                    <label>Short Description (for the card)</label>
                    <input type="text" id="f_description" name="description" placeholder="One line shown on the service card">
                </div>
            </div>

            <div class="form-group" style="margin-top:10px;">
                <label style="font-weight:600;font-size:.82rem;">Instructions (shown on the service's page)</label>
                <textarea id="f_instructions" name="instructions" rows="2" placeholder="What the customer should know before requesting"></textarea>
            </div>

            <div class="qsa-form-grid" style="margin-top:12px;">
                <div>
                    <label>Pricing Mode</label>
                    <select id="f_pricing_mode" name="pricing_mode" onchange="updatePricingUI()">
                        <option value="fixed">Fixed cost (I set it)</option>
                        <option value="user_entered">User-entered amount</option>
                    </select>
                </div>
                <div id="f_base_cost_panel">
                    <label>Service Cost (GH₵)</label>
                    <input type="number" id="f_base_cost" name="base_cost" min="0" step="0.01" value="0">
                </div>
                <div id="f_amount_key_panel" style="display:none;">
                    <label>Amount Field Key</label>
                    <input type="text" id="f_amount_field_key" name="amount_field_key" placeholder="must match a field key below, e.g. amount">
                </div>
                <div>
                    <label>AkuapemConnect Fee Type</label>
                    <select id="f_fee_type" name="service_fee_type">
                        <option value="flat">Flat (GH₵)</option>
                        <option value="percent">Percent (%)</option>
                    </select>
                </div>
                <div>
                    <label>Fee Value</label>
                    <input type="number" id="f_fee_value" name="service_fee_value" min="0" step="0.01" value="0">
                </div>
            </div>

            <div class="form-group" style="margin-top:16px;">
                <label style="font-weight:600;font-size:.85rem;">Request Form Fields</label>
                <p class="form-hint" style="margin:2px 0 8px;">These generate the form customers fill in. The Field Key is auto-slugged from the label — reference it in "Amount Field Key" above when using a user-entered amount.</p>
                <div id="qsa-fields"></div>
                <button type="button" class="button button-secondary button-small" onclick="qsaAddField()">+ Add Field</button>
            </div>

            <div style="margin-top:16px;display:flex;gap:8px;">
                <button type="submit" class="button button-primary" onclick="return qsaSerializeFields()">Save Service</button>
                <button type="button" class="button button-secondary" onclick="resetForm()">Cancel Edit</button>
            </div>
        </form>
    </div>

    <?php if ($manageService): ?>
    <div class="qsa-card" id="mgr-panel">
        <h2 style="margin-top:0;font-size:1rem;">Managers — <?php echo sanitize($manageService['name']); ?></h2>
        <p style="font-size:.84rem;color:var(--text-muted,#6b7280);">
            Only assigned managers (and admins) can process requests for this service.
        </p>

        <?php if (!empty($managersByService[$manageService['id']])): ?>
        <div style="margin-bottom:14px;">
            <?php foreach ($managersByService[$manageService['id']] as $mgr): ?>
            <span class="qsa-mgr-chip">
                <?php echo sanitize($mgr['name']); ?>
                <form method="post" style="display:inline;margin:0;" onsubmit="return confirm('Remove <?php echo sanitize(addslashes($mgr['name'])); ?> as manager?');">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="remove_manager">
                    <input type="hidden" name="service_id" value="<?php echo (int)$manageService['id']; ?>">
                    <input type="hidden" name="user_id" value="<?php echo (int)$mgr['user_id']; ?>">
                    <button type="submit" title="Remove">×</button>
                </form>
            </span>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <p style="font-size:.84rem;color:var(--text-muted,#6b7280);">No managers assigned yet.</p>
        <?php endif; ?>

        <form method="get" action="quick_services.php" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px;">
            <input type="hidden" name="manage" value="<?php echo (int)$manageService['id']; ?>">
            <input type="text" name="q" value="<?php echo sanitize($mgrQ); ?>" placeholder="Search manager/admin by name, email, or username…" style="flex:1;min-width:220px;padding:7px 10px;border:1px solid var(--border);border-radius:8px;font-size:.82rem;">
            <button type="submit" class="button button-secondary button-small">Search</button>
        </form>
        <?php if ($mgrQ !== ''): ?>
            <?php if (!$mgrSearchResults): ?>
            <p style="font-size:.84rem;color:var(--text-muted,#6b7280);">No matching manager/admin accounts. Only users with the Manager or Admin role can be assigned — promote them first via <a href="user_edit.php">Users</a>.</p>
            <?php else: ?>
            <table class="qsa-table">
                <thead><tr><th>Name</th><th>Email</th><th>Role</th><th style="text-align:right;">Action</th></tr></thead>
                <tbody>
                <?php foreach ($mgrSearchResults as $r): ?>
                <tr>
                    <td><?php echo sanitize($r['name']); ?></td>
                    <td><?php echo sanitize($r['email']); ?></td>
                    <td><?php echo ucfirst($r['role']); ?></td>
                    <td style="text-align:right;">
                        <form method="post" style="display:inline;">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="assign_manager">
                            <input type="hidden" name="service_id" value="<?php echo (int)$manageService['id']; ?>">
                            <input type="hidden" name="user_id" value="<?php echo (int)$r['id']; ?>">
                            <input type="hidden" name="q" value="<?php echo sanitize($mgrQ); ?>">
                            <button type="submit" class="button button-primary button-small">Assign</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        <?php endif; ?>
        <p style="font-size:.76rem;color:var(--text-muted,#6b7280);margin-top:10px;">
            Note: a manager also needs the "Process requests for assigned services" permission —
            grant it via <a href="moderators.php">Moderators</a> if not already enabled for them.
        </p>
    </div>
    <?php endif; ?>

</main>

<script>
function qsaFieldRow(f) {
    f = f || {};
    var div = document.createElement('div');
    div.className = 'qsa-field-row';
    div.innerHTML =
        '<input type="text" class="fr-label" placeholder="Label, e.g. Meter Number" value="' + (f.label || '').replace(/"/g,'&quot;') + '" oninput="qsaAutoKey(this)">' +
        '<input type="text" class="fr-key" placeholder="key" value="' + (f.key || '').replace(/"/g,'&quot;') + '">' +
        '<select class="fr-type">' +
            ['text','number','tel','password','textarea','select'].map(function(t) {
                return '<option value="' + t + '"' + (f.type === t ? ' selected' : '') + '>' + t + '</option>';
            }).join('') +
        '</select>' +
        '<label style="display:flex;align-items:center;gap:4px;font-weight:400;font-size:.78rem;"><input type="checkbox" class="fr-required"' + (f.required ? ' checked' : '') + '> Required</label>' +
        '<input type="text" class="fr-placeholder" placeholder="Placeholder" value="' + (f.placeholder || '').replace(/"/g,'&quot;') + '">' +
        '<input type="text" class="fr-options" placeholder="Options (comma-separated, for Select)" value="' + ((f.options || []).join(', ')).replace(/"/g,'&quot;') + '">' +
        '<button type="button" class="fr-remove" onclick="this.closest(\'.qsa-field-row\').remove()" title="Remove">✕</button>';
    return div;
}
function qsaAddField(f) {
    document.getElementById('qsa-fields').appendChild(qsaFieldRow(f));
}
function qsaAutoKey(labelInput) {
    var row = labelInput.closest('.qsa-field-row');
    var keyInput = row.querySelector('.fr-key');
    if (!keyInput.dataset.touched) {
        keyInput.value = labelInput.value.toLowerCase().trim().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '');
    }
}
document.addEventListener('input', function (e) {
    if (e.target.classList.contains('fr-key')) e.target.dataset.touched = '1';
});
function qsaSerializeFields() {
    var rows = document.querySelectorAll('#qsa-fields .qsa-field-row');
    var fields = [];
    rows.forEach(function (row) {
        var label = row.querySelector('.fr-label').value.trim();
        var key = row.querySelector('.fr-key').value.trim();
        if (!label || !key) return;
        fields.push({
            label: label,
            key: key,
            type: row.querySelector('.fr-type').value,
            required: row.querySelector('.fr-required').checked,
            placeholder: row.querySelector('.fr-placeholder').value.trim(),
            options: row.querySelector('.fr-options').value.trim()
        });
    });
    document.getElementById('f_form_fields').value = JSON.stringify(fields);
    return true;
}
function updatePricingUI() {
    var mode = document.getElementById('f_pricing_mode').value;
    document.getElementById('f_base_cost_panel').style.display = mode === 'fixed' ? '' : 'none';
    document.getElementById('f_amount_key_panel').style.display = mode === 'user_entered' ? '' : 'none';
}
function editService(s) {
    document.getElementById('qsa-form-heading').textContent = 'Edit Service — ' + s.name;
    document.getElementById('f_id').value = s.id;
    document.getElementById('f_name').value = s.name;
    document.getElementById('f_icon').value = s.icon || '';
    document.getElementById('f_display_order').value = s.display_order || 0;
    document.getElementById('f_description').value = s.description || '';
    document.getElementById('f_instructions').value = s.instructions || '';
    document.getElementById('f_pricing_mode').value = s.pricing_mode || 'fixed';
    document.getElementById('f_base_cost').value = s.base_cost || 0;
    document.getElementById('f_amount_field_key').value = s.amount_field_key || '';
    document.getElementById('f_fee_type').value = s.service_fee_type || 'flat';
    document.getElementById('f_fee_value').value = s.service_fee_value || 0;
    updatePricingUI();

    var preview = document.getElementById('f_image_preview');
    document.getElementById('f_image').value = '';
    if (s.image_path) {
        preview.src = '../' + s.image_path;
        preview.style.display = '';
    } else {
        preview.style.display = 'none';
    }

    document.getElementById('qsa-fields').innerHTML = '';
    var fields = [];
    try { fields = JSON.parse(s.form_fields || '[]'); } catch (e) {}
    fields.forEach(function (f) {
        var row = qsaFieldRow(f);
        row.querySelector('.fr-key').dataset.touched = '1';
        document.getElementById('qsa-fields').appendChild(row);
    });

    document.getElementById('qsa-form').scrollIntoView({ behavior: 'smooth' });
}
function resetForm() {
    document.getElementById('qsa-form').reset();
    document.getElementById('f_id').value = 0;
    document.getElementById('qsa-form-heading').textContent = 'Add Service';
    document.getElementById('qsa-fields').innerHTML = '';
    document.getElementById('f_image_preview').style.display = 'none';
    qsaAddField();
    updatePricingUI();
}
qsaAddField();
updatePricingUI();
</script>

</body>
</html>
