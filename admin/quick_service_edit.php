<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../quick_service_functions.php';

require_login();
if (!is_admin_or_manager()) { header('Location: index.php'); exit; }
require_mod_permission('manage_quick_services');
$user = current_user();

$id = (int)($_GET['id'] ?? 0);
$svc = null;
if ($id) {
    $svc = qs_get_service($id);
    if (!$svc) { header('Location: quick_services.php'); exit; }
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    require_mod_permission('manage_quick_services');

    if (isset($_POST['save_service'])) {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '') ?: null;
        $icon = trim($_POST['icon'] ?? '') ?: null;
        $serviceType = in_array($_POST['service_type'] ?? '', ['data_bundle', 'result_service'], true) ? $_POST['service_type'] : 'data_bundle';
        $requiresManager = isset($_POST['requires_manager']) ? 1 : 0;
        $status = in_array($_POST['status'] ?? '', ['active', 'inactive'], true) ? $_POST['status'] : 'active';
        $dispOrder = (int)($_POST['display_order'] ?? 0);

        if (!$name) $errors[] = 'Service name is required.';

        if (!$errors) {
            $baseSlug = preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($name)));
            $baseSlug = trim($baseSlug, '-') ?: 'service';
            $slug = $baseSlug; $i = 2;
            while (true) {
                $chk = $pdo->prepare('SELECT id FROM quick_services WHERE slug=? AND id!=?');
                $chk->execute([$slug, $id]);
                if (!$chk->fetch()) break;
                $slug = $baseSlug . '-' . $i++;
            }

            if ($id) {
                $pdo->prepare("UPDATE quick_services SET name=?, slug=?, description=?, icon=?, service_type=?, requires_manager=?, status=?, display_order=?, updated_at=NOW() WHERE id=?")
                    ->execute([$name, $slug, $description, $icon, $serviceType, $requiresManager, $status, $dispOrder, $id]);
                log_audit_action($user['id'], 'quick_service_edit', "Edited Quick Service #{$id}: {$name}");
            } else {
                $pdo->prepare("INSERT INTO quick_services (name, slug, description, icon, service_type, requires_manager, status, display_order) VALUES (?,?,?,?,?,?,?,?)")
                    ->execute([$name, $slug, $description, $icon, $serviceType, $requiresManager, $status, $dispOrder]);
                $id = (int)$pdo->lastInsertId();
                log_audit_action($user['id'], 'quick_service_create', "Created Quick Service #{$id}: {$name}");
            }
            header('Location: quick_service_edit.php?id=' . $id . '&saved=1'); exit;
        }
        $svc = array_merge($svc ?? [], [
            'name' => $name,
            'description' => $description,
            'icon' => $icon,
            'requires_manager' => $requiresManager,
            'status' => $status,
            'display_order' => $dispOrder,
            'service_type' => $serviceType,
        ]);

    } elseif (isset($_POST['manager_action']) && $id) {
        if ($_POST['manager_action'] === 'add' && !empty($_POST['manager_user_id'])) {
            $pdo->prepare('INSERT IGNORE INTO quick_service_managers (service_id, user_id, granted_by) VALUES (?,?,?)')
                ->execute([$id, (int)$_POST['manager_user_id'], $user['id']]);
            log_audit_action($user['id'], 'quick_service_manager_assigned', "Assigned user #{$_POST['manager_user_id']} as manager of Quick Service #{$id}");
        } elseif ($_POST['manager_action'] === 'remove' && !empty($_POST['manager_id'])) {
            $pdo->prepare('DELETE FROM quick_service_managers WHERE id=? AND service_id=?')->execute([(int)$_POST['manager_id'], $id]);
        }
        header('Location: quick_service_edit.php?id=' . $id . '#managers'); exit;

    } elseif (isset($_POST['option_action']) && $id) {
        if ($_POST['option_action'] === 'add') {
            $label = trim($_POST['opt_label'] ?? '');
            $price = max(0, (float)($_POST['opt_price'] ?? 0));
            if ($label) {
                $pdo->prepare('INSERT INTO quick_service_options (service_id, label, price) VALUES (?,?,?)')->execute([$id, $label, $price]);
                log_audit_action($user['id'], 'quick_service_option_create', "Added option '{$label}' to Quick Service #{$id}");
            }
        } elseif ($_POST['option_action'] === 'toggle' && !empty($_POST['opt_id'])) {
            $pdo->prepare("UPDATE quick_service_options SET status=IF(status='active','inactive','active') WHERE id=? AND service_id=?")->execute([(int)$_POST['opt_id'], $id]);
        } elseif ($_POST['option_action'] === 'delete' && !empty($_POST['opt_id'])) {
            $pdo->prepare('DELETE FROM quick_service_options WHERE id=? AND service_id=?')->execute([(int)$_POST['opt_id'], $id]);
        }
        header('Location: quick_service_edit.php?id=' . $id . '#options'); exit;
    }
}

$isNew = !$id;
$v = fn($k) => sanitize($svc[$k] ?? '');

$managers = [];
$availableUsers = [];
$options = [];
if ($id) {
    $managers = $pdo->prepare("SELECT qm.id, qm.user_id, u.name, u.email FROM quick_service_managers qm JOIN users u ON u.id=qm.user_id WHERE qm.service_id=? ORDER BY u.name");
    $managers->execute([$id]);
    $managers = $managers->fetchAll();

    $availableUsers = $pdo->query("SELECT id, name, email FROM users WHERE role IN ('manager','admin') ORDER BY name")->fetchAll();
    $optStmt = $pdo->prepare("SELECT * FROM quick_service_options WHERE service_id=? ORDER BY display_order, price");
    $optStmt->execute([$id]);
    $options = $optStmt->fetchAll();
}
$flashMsg = get_flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $isNew ? 'New Quick Service' : 'Edit Quick Service'; ?> — Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .qse-shell  { max-width:640px; margin:0 auto; padding:20px 16px 60px; }
        .qse-field  { margin-bottom:16px; }
        .qse-field label { display:block; font-weight:600; font-size:.86rem; margin-bottom:4px; }
        .qse-field input, .qse-field select, .qse-field textarea { width:100%; box-sizing:border-box; }
        .qse-row    { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
        @media(max-width:520px){ .qse-row { grid-template-columns:1fr; } }
        .qse-section { font-size:.74rem; font-weight:800; text-transform:uppercase; letter-spacing:.06em; color:var(--text-muted); margin:24px 0 12px; border-top:1px solid var(--border); padding-top:16px; }
        .qse-list-row { display:flex; justify-content:space-between; align-items:center; background:var(--surface,#fff); border:1px solid var(--border); border-radius:10px; padding:10px 12px; margin-bottom:8px; }
    </style>
</head>
<body>
    <header class="topbar">
        <a href="quick_services.php" class="button button-secondary button-small">← Quick Services</a>
        <h1><?php echo $isNew ? 'New Service' : 'Edit Service'; ?></h1>
    </header>

    <div class="qse-shell">
        <?php if (isset($_GET['saved'])): ?><div class="alert alert-success" style="margin-bottom:16px;">Saved successfully.</div><?php endif; ?>
        <?php if ($flashMsg): ?><div class="alert alert-<?php echo sanitize($flashMsg['type']); ?>" style="margin-bottom:16px;"><?php echo sanitize($flashMsg['message']); ?></div><?php endif; ?>
        <?php foreach ($errors as $e): ?><div class="alert alert-error" style="margin-bottom:10px;"><?php echo sanitize($e); ?></div><?php endforeach; ?>

        <form method="post">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="save_service" value="1">
            <div class="qse-field"><label>Service Name *</label><input type="text" name="name" required value="<?php echo $v('name'); ?>"></div>
            <div class="qse-row">
                <div class="qse-field">
                    <label>Service Type *</label>
                    <select name="service_type">
                        <option value="data_bundle" <?php echo ($svc['service_type'] ?? 'data_bundle')==='data_bundle' ? 'selected':''; ?>>Data Bundle</option>
                        <option value="result_service" <?php echo ($svc['service_type'] ?? '')==='result_service' ? 'selected':''; ?>>Result Service</option>
                    </select>
                </div>
                <div class="qse-field"><label>Icon (emoji)</label><input type="text" name="icon" maxlength="10" value="<?php echo $v('icon'); ?>" placeholder="⚡"></div>
            </div>
            <div class="qse-field"><label>Description</label><textarea name="description" rows="3"><?php echo $v('description'); ?></textarea></div>
            <div class="qse-row">
                <div class="qse-field">
                    <label>Status</label>
                    <select name="status">
                        <option value="active" <?php echo ($svc['status'] ?? 'active')==='active' ? 'selected':''; ?>>Active</option>
                        <option value="inactive" <?php echo ($svc['status'] ?? '')==='inactive' ? 'selected':''; ?>>Inactive</option>
                    </select>
                </div>
                <div class="qse-field"><label>Display Order</label><input type="number" name="display_order" value="<?php echo (int)($svc['display_order'] ?? 0); ?>"></div>
            </div>
            <div class="qse-field" style="display:flex;align-items:center;gap:8px;">
                <input type="checkbox" name="requires_manager" id="qse-req-mgr" <?php echo !empty($svc['requires_manager']) || $isNew ? 'checked' : ''; ?>>
                <label for="qse-req-mgr" style="margin:0;cursor:pointer;">Requires a manager to process requests</label>
            </div>
            <button type="submit" class="button button-primary"><?php echo $isNew ? 'Create Service' : 'Save Changes'; ?></button>
        </form>

        <?php if (!$isNew): ?>
        <div id="managers" class="qse-section">Assigned Managers</div>
        <?php if (!$managers): ?>
        <p style="color:var(--text-muted);font-size:.85rem;">No managers assigned yet — this service's requests will notify all admins/managers until one is assigned.</p>
        <?php else: foreach ($managers as $m): ?>
        <div class="qse-list-row">
            <span><?php echo sanitize($m['name']); ?> <span class="meta">(<?php echo sanitize($m['email']); ?>)</span></span>
            <form method="post" style="margin:0;"><input type="hidden" name="manager_action" value="remove"><input type="hidden" name="manager_id" value="<?php echo (int)$m['id']; ?>"><?php echo csrf_field(); ?><button class="button button-small" style="background:#fee2e2;color:#991b1b;border-color:#fca5a5;">Remove</button></form>
        </div>
        <?php endforeach; endif; ?>
        <form method="post" style="display:flex;gap:8px;margin-top:10px;">
            <input type="hidden" name="manager_action" value="add">
            <?php echo csrf_field(); ?>
            <select name="manager_user_id" required style="flex:1;padding:8px 10px;border:1px solid var(--border);border-radius:8px;">
                <option value="">— Select user —</option>
                <?php foreach ($availableUsers as $u): ?><option value="<?php echo (int)$u['id']; ?>"><?php echo sanitize($u['name']); ?> (<?php echo sanitize($u['email']); ?>)</option><?php endforeach; ?>
            </select>
            <button type="submit" class="button button-primary button-small">+ Assign</button>
        </form>

        <?php if ($svc['service_type'] === 'result_service'): ?>
        <div id="options" class="qse-section">Priced Options</div>
        <?php if (!$options): ?>
        <p style="color:var(--text-muted);font-size:.85rem;">No options yet — add at least one below so customers have something to choose.</p>
        <?php else: foreach ($options as $o): ?>
        <div class="qse-list-row" style="<?php echo $o['status']==='inactive' ? 'opacity:.5;' : ''; ?>">
            <span><?php echo sanitize($o['label']); ?> — GH₵<?php echo number_format((float)$o['price'],2); ?></span>
            <div style="display:flex;gap:6px;">
                <form method="post" style="margin:0;"><input type="hidden" name="option_action" value="toggle"><input type="hidden" name="opt_id" value="<?php echo (int)$o['id']; ?>"><?php echo csrf_field(); ?><button class="button button-small"><?php echo $o['status']==='active' ? 'Disable' : 'Enable'; ?></button></form>
                <form method="post" style="margin:0;" onsubmit="return confirm('Delete this option?')"><input type="hidden" name="option_action" value="delete"><input type="hidden" name="opt_id" value="<?php echo (int)$o['id']; ?>"><?php echo csrf_field(); ?><button class="button button-small" style="background:#fee2e2;color:#991b1b;border-color:#fca5a5;">Delete</button></form>
            </div>
        </div>
        <?php endforeach; endif; ?>
        <form method="post" style="display:flex;gap:8px;margin-top:10px;">
            <input type="hidden" name="option_action" value="add">
            <?php echo csrf_field(); ?>
            <input type="text" name="opt_label" placeholder="e.g. BECE Checker Only" required style="flex:1;padding:8px 10px;border:1px solid var(--border);border-radius:8px;">
            <input type="number" name="opt_price" step="0.01" min="0" placeholder="Price" required style="width:100px;padding:8px 10px;border:1px solid var(--border);border-radius:8px;">
            <button type="submit" class="button button-primary button-small">+ Add Option</button>
        </form>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>
