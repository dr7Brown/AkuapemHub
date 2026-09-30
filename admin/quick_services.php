<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../quick_service_functions.php';

require_login();
if (!is_admin_or_manager()) { header('Location: index.php'); exit; }
require_mod_permission('manage_quick_services');
$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    require_mod_permission('manage_quick_services');
    $action = $_POST['action'] ?? '';

    if ($action === 'toggle_status' && !empty($_POST['id'])) {
        $pdo->prepare("UPDATE quick_services SET status=IF(status='active','inactive','active') WHERE id=?")->execute([(int)$_POST['id']]);
        log_audit_action($user['id'], 'quick_service_status', 'Toggled status for Quick Service #' . (int)$_POST['id']);
    } elseif ($action === 'delete' && !empty($_POST['id'])) {
        try {
            $pdo->prepare('DELETE FROM quick_services WHERE id=?')->execute([(int)$_POST['id']]);
            log_audit_action($user['id'], 'quick_service_delete', 'Deleted Quick Service #' . (int)$_POST['id']);
        } catch (PDOException $e) {
            flash('This service has existing transactions and cannot be deleted — deactivate it instead.', 'error');
        }
    } elseif ($action === 'add_network') {
        $name = trim($_POST['network_name'] ?? '');
        if ($name) {
            $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($name));
            $pdo->prepare('INSERT IGNORE INTO quick_data_networks (name, slug) VALUES (?,?)')->execute([$name, $slug]);
            log_audit_action($user['id'], 'quick_network_create', "Added data network: {$name}");
        }
    } elseif ($action === 'toggle_network' && !empty($_POST['id'])) {
        $pdo->prepare("UPDATE quick_data_networks SET status=IF(status='active','inactive','active') WHERE id=?")->execute([(int)$_POST['id']]);
    } elseif ($action === 'add_bundle') {
        $networkId = (int)($_POST['network_id'] ?? 0);
        $label = trim($_POST['bundle_label'] ?? '');
        $price = max(0, (float)($_POST['bundle_price'] ?? 0));
        if ($networkId && $label) {
            $pdo->prepare('INSERT IGNORE INTO quick_data_bundles (network_id, label, price) VALUES (?,?,?)')->execute([$networkId, $label, $price]);
            log_audit_action($user['id'], 'quick_bundle_create', "Added bundle {$label} to network #{$networkId}");
        }
    } elseif ($action === 'toggle_bundle' && !empty($_POST['id'])) {
        $pdo->prepare("UPDATE quick_data_bundles SET status=IF(status='active','inactive','active') WHERE id=?")->execute([(int)$_POST['id']]);
    } elseif ($action === 'delete_bundle' && !empty($_POST['id'])) {
        $pdo->prepare('DELETE FROM quick_data_bundles WHERE id=?')->execute([(int)$_POST['id']]);
    } elseif ($action === 'save_bundle_sort') {
        $bundleSort = in_array($_POST['bundle_sort'] ?? '', ['label', 'price'], true) ? $_POST['bundle_sort'] : 'default';
        set_platform_setting('qs_bundle_sort', $bundleSort);
        log_audit_action($user['id'], 'quick_bundle_sort', "Set Quick Services bundle sort order to: {$bundleSort}");
    } elseif ($action === 'save_charge_settings') {
        $chargeType  = ($_POST['qs_service_charge_type'] ?? '') === 'percent' ? 'percent' : 'flat';
        $chargeValue = max(0, (float)($_POST['qs_service_charge_value'] ?? 0));
        $chargeCap   = max(0, (float)($_POST['qs_service_charge_cap'] ?? 0));
        set_platform_setting('qs_service_charge_type', $chargeType);
        set_platform_setting('qs_service_charge_value', (string)$chargeValue);
        set_platform_setting('qs_service_charge_cap', (string)$chargeCap);
        log_audit_action($user['id'], 'quick_service_charge_settings', "Updated Quick Services charge: {$chargeType} {$chargeValue}, cap {$chargeCap}");
        flash('Charge settings saved.', 'success');
    }
    header('Location: quick_services.php'); exit;
}

$flashMsg = get_flash();
$services = $pdo->query("SELECT * FROM quick_services ORDER BY display_order, name")->fetchAll(PDO::FETCH_ASSOC);
$networks = $pdo->query("SELECT * FROM quick_data_networks ORDER BY display_order, name")->fetchAll(PDO::FETCH_ASSOC);
$bundleSort = get_platform_setting('qs_bundle_sort', 'default');
$bundleOrderBy = 'n.display_order, ' . qs_bundle_order_by();
$bundles  = $pdo->query("SELECT b.*, n.name AS network_name FROM quick_data_bundles b JOIN quick_data_networks n ON n.id=b.network_id ORDER BY {$bundleOrderBy}")->fetchAll(PDO::FETCH_ASSOC);
$chargeType  = get_platform_setting('qs_service_charge_type', 'flat');
$chargeValue = (float)get_platform_setting('qs_service_charge_value', '0');
$chargeCap   = (float)get_platform_setting('qs_service_charge_cap', '0');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quick Services — Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .qsa-shell  { max-width:900px; margin:0 auto; padding:20px 16px 60px; }
        .qsa-table  { width:100%; border-collapse:collapse; font-size:.85rem; margin-bottom:24px; }
        .qsa-table th { background:var(--surface-muted,#f9fafb); padding:9px 12px; text-align:left; font-size:.72rem; font-weight:800; text-transform:uppercase; color:var(--text-muted); border-bottom:1px solid var(--border); }
        .qsa-table td { padding:9px 12px; border-bottom:1px solid var(--border,#e5e7eb); vertical-align:middle; }
        .qsa-actions { display:flex; gap:5px; flex-wrap:wrap; }
        .qsa-badge  { font-size:.65rem; font-weight:800; padding:2px 8px; border-radius:20px; }
        .qsa-badge.active { background:#ecfdf5; color:#065f46; }
        .qsa-badge.inactive { background:#f3f4f6; color:#6b7280; }
        .qsa-section-head { display:flex; justify-content:space-between; align-items:center; margin:24px 0 10px; }
    </style>
</head>
<body>
    <header class="topbar">
        <a href="index.php" class="button button-secondary button-small">← Admin</a>
        <h1>Quick Services</h1>
        <a href="quick_service_edit.php" class="button button-primary button-small">+ New Service</a>
    </header>

    <div class="qsa-shell">
        <?php if ($flashMsg): ?><div class="alert alert-<?php echo sanitize($flashMsg['type']); ?>" style="margin-bottom:16px;"><?php echo sanitize($flashMsg['message']); ?></div><?php endif; ?>

        <div class="qsa-section-head"><h2 style="font-size:.9rem;margin:0;">Service Charge</h2></div>
        <form method="post" style="background:var(--surface-muted,#f9fafb);border:1px solid var(--border,#e5e7eb);border-radius:10px;padding:14px;margin-bottom:24px;">
            <input type="hidden" name="action" value="save_charge_settings">
            <?php echo csrf_field(); ?>
            <p style="font-size:.74rem;color:var(--text-muted,#6b7280);margin:0 0 10px;">Added on top of the bundle/option price at checkout — separate from the base price. Leave value at 0 to charge nothing.</p>
            <div style="display:flex;gap:14px;margin-bottom:10px;flex-wrap:wrap;">
                <label style="font-weight:400;display:flex;align-items:center;gap:5px;">
                    <input type="radio" name="qs_service_charge_type" value="flat" <?php echo $chargeType !== 'percent' ? 'checked' : ''; ?>> Flat GH&#8373;
                </label>
                <label style="font-weight:400;display:flex;align-items:center;gap:5px;">
                    <input type="radio" name="qs_service_charge_type" value="percent" <?php echo $chargeType === 'percent' ? 'checked' : ''; ?>> % of price
                </label>
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <div>
                    <label style="display:block;font-size:.74rem;font-weight:700;margin-bottom:3px;">Value</label>
                    <input type="number" name="qs_service_charge_value" min="0" step="0.01" value="<?php echo sanitize($chargeValue); ?>" style="width:120px;padding:8px 10px;border:1px solid var(--border);border-radius:8px;">
                </div>
                <div>
                    <label style="display:block;font-size:.74rem;font-weight:700;margin-bottom:3px;">Cap (GH&#8373;, 0 = no cap)</label>
                    <input type="number" name="qs_service_charge_cap" min="0" step="0.01" value="<?php echo sanitize($chargeCap); ?>" style="width:160px;padding:8px 10px;border:1px solid var(--border);border-radius:8px;">
                </div>
                <button type="submit" class="button button-primary button-small" style="align-self:end;">Save</button>
            </div>
        </form>

        <div class="qsa-section-head"><h2 style="font-size:.9rem;margin:0;">Services</h2></div>
        <table class="qsa-table">
            <thead><tr><th>Service</th><th>Type</th><th>Status</th><th>Order</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($services as $s): ?>
                <tr>
                    <td><?php echo sanitize($s['icon']); ?> <strong><?php echo sanitize($s['name']); ?></strong></td>
                    <td style="font-size:.78rem;color:var(--text-muted);"><?php echo sanitize($s['service_type']); ?></td>
                    <td><span class="qsa-badge <?php echo $s['status']; ?>"><?php echo ucfirst($s['status']); ?></span></td>
                    <td><?php echo (int)$s['display_order']; ?></td>
                    <td>
                        <div class="qsa-actions">
                            <a href="quick_service_edit.php?id=<?php echo (int)$s['id']; ?>" class="button button-small button-primary">Edit</a>
                            <form method="post"><input type="hidden" name="action" value="toggle_status"><input type="hidden" name="id" value="<?php echo (int)$s['id']; ?>"><?php echo csrf_field(); ?><button class="button button-small"><?php echo $s['status']==='active' ? 'Deactivate' : 'Activate'; ?></button></form>
                            <form method="post" onsubmit="return confirm('Delete this service?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int)$s['id']; ?>"><?php echo csrf_field(); ?><button class="button button-small" style="background:#fee2e2;color:#991b1b;border-color:#fca5a5;">Delete</button></form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$services): ?><tr><td colspan="5" style="text-align:center;padding:24px;color:var(--text-muted);">No services yet.</td></tr><?php endif; ?>
            </tbody>
        </table>

        <div class="qsa-section-head"><h2 style="font-size:.9rem;margin:0;">Data Networks</h2></div>
        <table class="qsa-table">
            <thead><tr><th>Network</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($networks as $n): ?>
                <tr>
                    <td><?php echo sanitize($n['name']); ?></td>
                    <td><span class="qsa-badge <?php echo $n['status']; ?>"><?php echo ucfirst($n['status']); ?></span></td>
                    <td><form method="post"><input type="hidden" name="action" value="toggle_network"><input type="hidden" name="id" value="<?php echo (int)$n['id']; ?>"><?php echo csrf_field(); ?><button class="button button-small"><?php echo $n['status']==='active' ? 'Deactivate' : 'Activate'; ?></button></form></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <form method="post" style="display:flex;gap:8px;margin-bottom:24px;">
            <input type="hidden" name="action" value="add_network">
            <?php echo csrf_field(); ?>
            <input type="text" name="network_name" placeholder="e.g. Vodafone" required style="flex:1;padding:8px 12px;border:1px solid var(--border);border-radius:8px;">
            <button type="submit" class="button button-primary button-small">+ Add Network</button>
        </form>

        <div class="qsa-section-head">
            <h2 style="font-size:.9rem;margin:0;">Data Bundles</h2>
            <form method="post" style="display:flex;gap:6px;align-items:center;font-size:.76rem;">
                <input type="hidden" name="action" value="save_bundle_sort">
                <?php echo csrf_field(); ?>
                <span style="color:var(--text-muted,#6b7280);">Sort by:</span>
                <button type="submit" name="bundle_sort" value="default" class="button button-small<?php echo $bundleSort === 'default' ? ' button-primary' : ''; ?>">Default</button>
                <button type="submit" name="bundle_sort" value="label" class="button button-small<?php echo $bundleSort === 'label' ? ' button-primary' : ''; ?>">Bundle</button>
                <button type="submit" name="bundle_sort" value="price" class="button button-small<?php echo $bundleSort === 'price' ? ' button-primary' : ''; ?>">Price</button>
            </form>
        </div>
        <p class="meta" style="font-size:.74rem;margin:-4px 0 10px;">Grouped by network either way — this also sets the order customers see when choosing a bundle.</p>
        <table class="qsa-table">
            <thead><tr><th>Network</th><th>Bundle</th><th>Price</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($bundles as $b): ?>
                <tr>
                    <td><?php echo sanitize($b['network_name']); ?></td>
                    <td><?php echo sanitize($b['label']); ?></td>
                    <td>GH₵<?php echo number_format((float)$b['price'], 2); ?></td>
                    <td><span class="qsa-badge <?php echo $b['status']; ?>"><?php echo ucfirst($b['status']); ?></span></td>
                    <td>
                        <div class="qsa-actions">
                            <form method="post"><input type="hidden" name="action" value="toggle_bundle"><input type="hidden" name="id" value="<?php echo (int)$b['id']; ?>"><?php echo csrf_field(); ?><button class="button button-small"><?php echo $b['status']==='active' ? 'Disable' : 'Enable'; ?></button></form>
                            <form method="post" onsubmit="return confirm('Delete this bundle?')"><input type="hidden" name="action" value="delete_bundle"><input type="hidden" name="id" value="<?php echo (int)$b['id']; ?>"><?php echo csrf_field(); ?><button class="button button-small" style="background:#fee2e2;color:#991b1b;border-color:#fca5a5;">Delete</button></form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$bundles): ?><tr><td colspan="5" style="text-align:center;padding:24px;color:var(--text-muted);">No bundles yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
        <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;">
            <input type="hidden" name="action" value="add_bundle">
            <?php echo csrf_field(); ?>
            <select name="network_id" required style="padding:8px 10px;border:1px solid var(--border);border-radius:8px;">
                <option value="">— Network —</option>
                <?php foreach ($networks as $n): ?><option value="<?php echo (int)$n['id']; ?>"><?php echo sanitize($n['name']); ?></option><?php endforeach; ?>
            </select>
            <input type="text" name="bundle_label" placeholder="e.g. 1GB" required style="width:100px;padding:8px 10px;border:1px solid var(--border);border-radius:8px;">
            <input type="number" name="bundle_price" step="0.01" min="0" placeholder="Price" required style="width:100px;padding:8px 10px;border:1px solid var(--border);border-radius:8px;">
            <button type="submit" class="button button-primary button-small">+ Add Bundle</button>
        </form>
    </div>
</body>
</html>
