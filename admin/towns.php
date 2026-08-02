<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';

require_login();
if (!is_admin_or_manager()) { header('Location: index.php'); exit; }
require_mod_permission('manage_towns');

$adminUser = current_user();
$flash     = get_flash();
$error     = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_town') {
        $id       = (int)($_POST['id'] ?? 0);
        $name     = trim($_POST['name'] ?? '');
        $district = trim($_POST['district'] ?? '');

        if ($name === '' || $district === '') {
            $error = 'Both town name and district are required.';
        } elseif (strcasecmp($name, 'Other') === 0 && strcasecmp($district, 'Other') === 0) {
            $error = '"Other" / "Other" is a reserved system entry and cannot be created or edited here.';
        } else {
            try {
                if ($id > 0) {
                    $existing = $pdo->prepare("SELECT name, district FROM towns WHERE id=?");
                    $existing->execute([$id]);
                    $existing = $existing->fetch();
                    if ($existing && strcasecmp($existing['name'], 'Other') === 0 && strcasecmp($existing['district'], 'Other') === 0) {
                        $error = 'The reserved "Other" entry cannot be edited.';
                    } else {
                        $pdo->prepare('UPDATE towns SET name=?, district=? WHERE id=?')->execute([$name, $district, $id]);
                        log_audit_action($adminUser['id'], 'town_edited', "Edited town #{$id}: '{$name}, {$district}'");
                        flash('Town updated.', 'success');
                    }
                } else {
                    $pdo->prepare('INSERT INTO towns (name, district) VALUES (?,?)')->execute([$name, $district]);
                    log_audit_action($adminUser['id'], 'town_created', "Created town: '{$name}, {$district}'");
                    flash('Town added.', 'success');
                }
                if (!$error) { header('Location: towns.php'); exit; }
            } catch (PDOException $e) {
                $error = (int)$e->errorInfo[1] === 1062
                    ? 'That town already exists in that district.'
                    : 'Could not save the town — please try again.';
            }
        }
    } elseif ($action === 'delete_town' && !empty($_POST['id'])) {
        $id  = (int)$_POST['id'];
        $row = $pdo->prepare('SELECT name, district FROM towns WHERE id=?');
        $row->execute([$id]); $row = $row->fetch();

        if ($row && strcasecmp($row['name'], 'Other') === 0 && strcasecmp($row['district'], 'Other') === 0) {
            flash('The reserved "Other" entry cannot be deleted.', 'error');
        } elseif ($row) {
            $uc = $pdo->prepare('SELECT COUNT(*) FROM users WHERE town_id=?'); $uc->execute([$id]);
            $shopCount = $pdo->prepare('SELECT COUNT(*) FROM mp_shops WHERE town_id=?'); $shopCount->execute([$id]);
            $inUse = (int)$uc->fetchColumn() + (int)$shopCount->fetchColumn();
            if ($inUse > 0) {
                flash("Cannot delete — {$inUse} user(s)/shop(s) are set to this town. Edit them to a different town first.", 'error');
            } else {
                $pdo->prepare('DELETE FROM towns WHERE id=?')->execute([$id]);
                log_audit_action($adminUser['id'], 'town_deleted', "Deleted town '{$row['name']}, {$row['district']}' (#{$id})");
                flash('Town deleted.', 'success');
            }
        }
        header('Location: towns.php');
        exit;
    }
}

$towns = $pdo->query(
    "SELECT t.*,
        (SELECT COUNT(*) FROM users u WHERE u.town_id = t.id) AS user_count,
        (SELECT COUNT(*) FROM mp_shops s WHERE s.town_id = t.id) AS shop_count
     FROM towns t
     WHERE NOT (t.name='Other' AND t.district='Other')
     ORDER BY t.district, t.name"
)->fetchAll();

$districts = $pdo->query(
    "SELECT DISTINCT district FROM towns WHERE NOT (name='Other' AND district='Other') ORDER BY district"
)->fetchAll(PDO::FETCH_COLUMN);

$grouped = [];
foreach ($towns as $t) {
    $grouped[$t['district']][] = $t;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Towns — Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .tw-shell { max-width: 900px; margin: 0 auto; padding: 18px 16px 60px; }
        .tw-table { width: 100%; border-collapse: collapse; font-size: .84rem; }
        .tw-table th { padding: 9px 12px; text-align: left; font-size: .72rem; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; color: var(--text-muted,#6b7280); border-bottom: 1px solid var(--border); background: var(--surface-muted,#f9fafb); }
        .tw-table td { padding: 9px 12px; border-bottom: 1px solid var(--border,#f1f5f9); vertical-align: middle; }
        .tw-table tr:last-child td { border-bottom: none; }
        .tw-card { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; padding: 18px; margin-bottom: 16px; overflow-x: auto; }
        .tw-district-heading { font-size: .74rem; font-weight: 800; text-transform: uppercase; letter-spacing: .06em; color: var(--primary,#0f766e); margin: 16px 0 6px; }
        .tw-district-heading:first-child { margin-top: 0; }
        .tw-form-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px,1fr)); gap: 12px; }
        .tw-form-grid label { font-weight: 600; font-size: .82rem; display: block; margin-bottom: 4px; }
        .tw-form-grid input[type=text] { width: 100%; padding: 7px 9px; border: 1px solid var(--border); border-radius: 8px; font-size: .84rem; box-sizing: border-box; }
        .tw-usage { font-size: .74rem; color: var(--text-muted,#6b7280); }
    </style>
</head>
<body>

<header class="topbar">
    <a href="index.php" class="button button-secondary button-small">← Dashboard</a>
    <h1 style="margin:0;font-size:1rem;font-weight:800;">📍 Towns</h1>
</header>

<main class="tw-shell">

    <?php if ($flash): ?>
    <div class="alert alert-<?php echo sanitize($flash['type']); ?>" style="margin-bottom:14px;"><?php echo sanitize($flash['message']); ?></div>
    <?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error" style="margin-bottom:14px;"><?php echo sanitize($error); ?></div><?php endif; ?>

    <p style="font-size:.84rem;color:var(--text-muted,#6b7280);margin-bottom:16px;">
        These towns populate the location dropdown on registration and elsewhere across the app.
        Deleting a town that's already in use by a user or shop is blocked — move them to a different
        town first. The "Other" fallback entry is reserved by the system and isn't shown here.
    </p>

    <div class="tw-card">
        <?php if (!$towns): ?>
            <p style="text-align:center;color:var(--text-muted,#6b7280);padding:20px;">No towns yet — add one below.</p>
        <?php endif; ?>
        <?php foreach ($grouped as $district => $districtTowns): ?>
            <h3 class="tw-district-heading"><?php echo sanitize($district); ?></h3>
            <table class="tw-table">
                <thead><tr><th>Name</th><th>In use by</th><th style="text-align:right;">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($districtTowns as $t): ?>
                <tr>
                    <td><strong><?php echo sanitize($t['name']); ?></strong></td>
                    <td class="tw-usage">
                        <?php if ($t['user_count'] == 0 && $t['shop_count'] == 0): ?>
                            Not in use
                        <?php else: ?>
                            <?php echo (int)$t['user_count']; ?> user(s), <?php echo (int)$t['shop_count']; ?> shop(s)
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right;white-space:nowrap;">
                        <button type="button" class="button button-secondary button-small" onclick='editTown(<?php echo json_encode($t); ?>)'>Edit</button>
                        <form method="post" style="display:inline;" onsubmit="return confirm('Delete this town permanently?');">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="delete_town">
                            <input type="hidden" name="id" value="<?php echo (int)$t['id']; ?>">
                            <button type="submit" class="button button-secondary button-small" style="color:#c0392b;">Delete</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endforeach; ?>
    </div>

    <div class="tw-card">
        <h2 id="tw-form-heading" style="margin-top:0;font-size:1rem;">Add Town</h2>
        <form method="post" id="tw-form">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="save_town">
            <input type="hidden" name="id" id="f_id" value="0">

            <div class="tw-form-grid">
                <div>
                    <label>Town Name *</label>
                    <input type="text" name="name" id="f_name" required>
                </div>
                <div>
                    <label>District *</label>
                    <input type="text" name="district" id="f_district" list="tw-district-list" required>
                    <datalist id="tw-district-list">
                        <?php foreach ($districts as $d): ?>
                            <option value="<?php echo sanitize($d); ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>
            </div>

            <div style="margin-top:16px;display:flex;gap:8px;">
                <button type="submit" class="button button-primary">Save Town</button>
                <button type="button" class="button button-secondary" onclick="resetForm()">Cancel Edit</button>
            </div>
        </form>
    </div>

</main>

<script>
function editTown(t) {
    document.getElementById('tw-form-heading').textContent = 'Edit Town — ' + t.name;
    document.getElementById('f_id').value = t.id;
    document.getElementById('f_name').value = t.name;
    document.getElementById('f_district').value = t.district;
    document.getElementById('tw-form').scrollIntoView({ behavior: 'smooth' });
}
function resetForm() {
    document.getElementById('tw-form').reset();
    document.getElementById('f_id').value = 0;
    document.getElementById('tw-form-heading').textContent = 'Add Town';
}
</script>

</body>
</html>
