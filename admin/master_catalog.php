<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../marketplace_functions.php';

require_login();
if (!is_admin_or_manager()) { header('Location: index.php'); exit; }
require_mod_permission('manage_master_catalog');

$adminUser = current_user();
$flash     = get_flash();
$error     = '';

$catalogTypes = $pdo->query('SELECT slug, name FROM catalog_types ORDER BY sort_order, name')->fetchAll(PDO::FETCH_KEY_PAIR);

$tab = $_GET['tab'] ?? 'products';
if (!in_array($tab, ['products', 'catalogs', 'import'], true)) $tab = 'products';

// ── CSV template download ───────────────────────────────────────────────────
if ($tab === 'import' && ($_GET['template'] ?? '') === 'csv') {
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="master_catalog_template.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['name', 'brand', 'category', 'sku', 'description', 'package_size', 'search_keywords']);
    fputcsv($out, ['Milo 400g', 'Nestle', 'Chocolate Drinks', 'MILO-400', 'Chocolate malt drink', '400g Tin', 'chocolate, malt, drink, breakfast']);
    fclose($out);
    exit;
}

// ── CSV export (full catalog) ───────────────────────────────────────────────
if ($tab === 'import' && ($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="master_catalog_export_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['name', 'brand', 'category', 'sku', 'description', 'package_size', 'search_keywords', 'status', 'catalog_type']);
    $rows = $pdo->query('SELECT * FROM master_products ORDER BY catalog_type, name')->fetchAll();
    foreach ($rows as $r) {
        fputcsv($out, [
            csv_safe($r['name']), csv_safe($r['brand']), csv_safe($r['category']), csv_safe($r['sku']),
            csv_safe($r['description']), csv_safe($r['package_size']), csv_safe($r['search_keywords']),
            $r['status'], $r['catalog_type'],
        ]);
    }
    fclose($out);
    exit;
}

// ── POST handlers ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_product') {
        $id             = (int)($_POST['id'] ?? 0);
        $catalogType    = array_key_exists($_POST['catalog_type'] ?? '', $catalogTypes) ? $_POST['catalog_type'] : 'provision';
        $category       = trim($_POST['category'] ?? '') ?: null;
        $name           = trim($_POST['name'] ?? '');
        $brand          = trim($_POST['brand'] ?? '') ?: null;
        $sku            = trim($_POST['sku'] ?? '') ?: null;
        $description    = trim($_POST['description'] ?? '') ?: null;
        $packageSize    = trim($_POST['package_size'] ?? '') ?: null;
        $searchKeywords = trim($_POST['search_keywords'] ?? '') ?: null;
        $status         = ($_POST['status'] ?? '') === 'inactive' ? 'inactive' : 'active';
        $confirmDup     = !empty($_POST['confirm_duplicate']);
        $removeDefault  = !empty($_POST['remove_default_image']);

        if ($name === '') {
            $error = 'Product name is required.';
        } else {
            $dupStmt = $pdo->prepare('SELECT id, name FROM master_products WHERE catalog_type = ? AND LOWER(name) = LOWER(?) AND id != ? LIMIT 1');
            $dupStmt->execute([$catalogType, $name, $id]);
            $dup = $dupStmt->fetch();

            if ($dup && !$confirmDup) {
                $error = 'DUPLICATE:' . $dup['name'] . ':' . $dup['id'];
            } else {
                $defaultImagePath = null;
                if (!empty($_FILES['default_image']['name']) && is_valid_image_upload($_FILES['default_image'])) {
                    $defaultImagePath = save_uploaded_image(
                        $_FILES['default_image'], 'uploads/master_catalog',
                        (int)get_platform_setting('img_mp_product_maxwidth', '1200'),
                        (int)get_platform_setting('img_mp_product_quality', '85')
                    );
                }

                if ($id > 0) {
                    $sets   = 'catalog_type=?, category=?, name=?, brand=?, sku=?, description=?, package_size=?, search_keywords=?, status=?';
                    $params = [$catalogType, $category, $name, $brand, $sku, $description, $packageSize, $searchKeywords, $status];
                    if ($defaultImagePath) { $sets .= ', default_image=?'; $params[] = $defaultImagePath; }
                    elseif ($removeDefault) { $sets .= ', default_image=NULL'; }
                    $params[] = $id;
                    $pdo->prepare("UPDATE master_products SET $sets WHERE id=?")->execute($params);
                    log_audit_action($adminUser['id'], 'catalog_product_updated', "Updated catalog product #{$id}: '{$name}'");
                    flash('Product updated.', 'success');
                } else {
                    $pdo->prepare(
                        'INSERT INTO master_products (catalog_type, category, name, brand, sku, description, package_size, search_keywords, default_image, status)
                         VALUES (?,?,?,?,?,?,?,?,?,?)'
                    )->execute([$catalogType, $category, $name, $brand, $sku, $description, $packageSize, $searchKeywords, $defaultImagePath, $status]);
                    $id = (int)$pdo->lastInsertId();
                    log_audit_action($adminUser['id'], 'catalog_product_created', "Created catalog product #{$id}: '{$name}'");
                    flash('Product added to catalog.', 'success');
                }

                header('Location: master_catalog.php?tab=products');
                exit;
            }
        }
    } elseif ($action === 'toggle_product_status' && !empty($_POST['id'])) {
        $pid = (int)$_POST['id'];
        $row = $pdo->prepare('SELECT name, status FROM master_products WHERE id=?'); $row->execute([$pid]); $row = $row->fetch();
        if ($row) {
            $newStatus = $row['status'] === 'active' ? 'inactive' : 'active';
            $pdo->prepare('UPDATE master_products SET status=? WHERE id=?')->execute([$newStatus, $pid]);
            log_audit_action($adminUser['id'], 'catalog_product_status_toggled', "Set catalog product '{$row['name']}' (#{$pid}) to {$newStatus}");
            flash('Product ' . ($newStatus === 'active' ? 'activated' : 'deactivated') . '.', 'success');
        }
        header('Location: master_catalog.php?tab=products'); exit;
    } elseif ($action === 'delete_product' && !empty($_POST['id'])) {
        $pid = (int)$_POST['id'];
        $row = $pdo->prepare('SELECT name FROM master_products WHERE id=?'); $row->execute([$pid]); $row = $row->fetch();
        $inUse = $pdo->prepare('SELECT COUNT(*) FROM mp_products WHERE master_product_id=?'); $inUse->execute([$pid]);
        if ((int)$inUse->fetchColumn() > 0) {
            flash('Cannot delete — one or more shops are already using this product. Deactivate it instead.', 'error');
        } elseif ($row) {
            $pdo->prepare('DELETE FROM master_products WHERE id=?')->execute([$pid]);
            log_audit_action($adminUser['id'], 'catalog_product_deleted', "Deleted catalog product '{$row['name']}' (#{$pid})");
            flash('Product deleted.', 'success');
        }
        header('Location: master_catalog.php?tab=products'); exit;

    } elseif ($action === 'save_catalog_type') {
        $ctId   = (int)($_POST['ct_id'] ?? 0);
        $ctName = trim($_POST['ct_name'] ?? '');
        if ($ctName === '') {
            flash('Catalog name is required.', 'error');
        } elseif ($ctId > 0) {
            // Editing only ever renames the label — the slug is what's stored on
            // every existing master_products row, so it must never change underneath them.
            $pdo->prepare('UPDATE catalog_types SET name=? WHERE id=?')->execute([$ctName, $ctId]);
            log_audit_action($adminUser['id'], 'catalog_type_updated', "Renamed catalog #{$ctId} to '{$ctName}'");
            flash('Catalog updated.', 'success');
        } else {
            $slug = mp_slugify($ctName);
            try {
                $maxOrder = (int)$pdo->query('SELECT COALESCE(MAX(sort_order),0) FROM catalog_types')->fetchColumn();
                $pdo->prepare('INSERT INTO catalog_types (slug, name, sort_order) VALUES (?,?,?)')->execute([$slug, $ctName, $maxOrder + 1]);
                log_audit_action($adminUser['id'], 'catalog_type_created', "Created catalog '{$ctName}' ({$slug})");
                flash('Catalog added.', 'success');
            } catch (PDOException $e) {
                flash('A catalog with that name already exists.', 'error');
            }
        }
        header('Location: master_catalog.php?tab=catalogs'); exit;
    } elseif ($action === 'delete_catalog_type' && !empty($_POST['ct_id'])) {
        $ctId = (int)$_POST['ct_id'];
        $row  = $pdo->prepare('SELECT slug, name FROM catalog_types WHERE id=?'); $row->execute([$ctId]); $row = $row->fetch();
        if ($row) {
            $inUse = $pdo->prepare('SELECT COUNT(*) FROM master_products WHERE catalog_type=?'); $inUse->execute([$row['slug']]);
            if ((int)$inUse->fetchColumn() > 0) {
                flash('Cannot delete — products already exist in this catalog.', 'error');
            } else {
                $pdo->prepare('DELETE FROM catalog_types WHERE id=?')->execute([$ctId]);
                log_audit_action($adminUser['id'], 'catalog_type_deleted', "Deleted catalog '{$row['name']}' ({$row['slug']})");
                flash('Catalog deleted.', 'success');
            }
        }
        header('Location: master_catalog.php?tab=catalogs'); exit;

    } elseif ($action === 'import_csv') {
        $catalogType = array_key_exists($_POST['catalog_type'] ?? '', $catalogTypes) ? $_POST['catalog_type'] : 'provision';
        if (empty($_FILES['csv_file']['tmp_name']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            flash('Please choose a CSV file to upload.', 'error');
        } else {
            $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
            $header = $handle ? fgetcsv($handle) : false;
            if (!$handle || !$header) {
                flash('Could not read that file — make sure it is a valid CSV.', 'error');
            } else {
                $colIndex = [];
                foreach ($header as $i => $h) { $colIndex[strtolower(trim($h))] = $i; }
                $get = function (array $row, string $col) use ($colIndex) {
                    return isset($colIndex[$col], $row[$colIndex[$col]]) ? trim((string)$row[$colIndex[$col]]) : '';
                };

                $total = 0; $success = 0; $skipped = 0; $errCount = 0; $errors = [];
                $rowNum = 1;
                while (($row = fgetcsv($handle)) !== false) {
                    $rowNum++;
                    if (!array_filter($row, fn($v) => trim((string)$v) !== '')) continue; // blank line
                    $total++;

                    $name = $get($row, 'name');
                    if ($name === '') { $errCount++; $errors[] = "row {$rowNum}: name is required"; continue; }

                    $dupStmt = $pdo->prepare('SELECT id FROM master_products WHERE catalog_type=? AND LOWER(name)=LOWER(?) LIMIT 1');
                    $dupStmt->execute([$catalogType, $name]);
                    if ($dupStmt->fetch()) { $skipped++; continue; }

                    try {
                        $pdo->prepare(
                            'INSERT INTO master_products (catalog_type, category, name, brand, sku, description, package_size, search_keywords)
                             VALUES (?,?,?,?,?,?,?,?)'
                        )->execute([
                            $catalogType, $get($row, 'category') ?: null, $name,
                            $get($row, 'brand') ?: null, $get($row, 'sku') ?: null, $get($row, 'description') ?: null,
                            $get($row, 'package_size') ?: null, $get($row, 'search_keywords') ?: null,
                        ]);
                        $success++;
                    } catch (PDOException $e) {
                        $errCount++; $errors[] = "row {$rowNum}: could not save";
                    }
                }
                fclose($handle);

                $summary = "{$success} created, {$skipped} duplicates skipped, {$errCount} errors";
                log_audit_action(
                    $adminUser['id'], 'catalog_import',
                    "Imported '{$_FILES['csv_file']['name']}' ({$total} rows): {$summary}" . ($errors ? ' — ' . implode('; ', array_slice($errors, 0, 10)) : '')
                );
                flash("Import complete — {$summary}.", $errCount > 0 ? 'error' : 'success');
            }
        }
        header('Location: master_catalog.php?tab=import'); exit;
    }
}

// ── Data for the active tab ─────────────────────────────────────────────────
$editProduct = null;
if ($tab === 'products') {
    $editId = (int)($_GET['edit'] ?? 0);
    if ($editId) {
        $stmt = $pdo->prepare('SELECT * FROM master_products WHERE id=?'); $stmt->execute([$editId]);
        $editProduct = $stmt->fetch();
    }

    $q          = trim($_GET['q'] ?? '');
    $catFilter  = trim($_GET['category'] ?? '');
    $statusFilter = in_array($_GET['status'] ?? '', ['active', 'inactive'], true) ? $_GET['status'] : '';
    $where = []; $params = [];
    if ($q !== '') {
        $where[] = '(name LIKE ? OR brand LIKE ? OR sku LIKE ? OR search_keywords LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like, $like);
    }
    if ($catFilter !== '') { $where[] = 'category = ?'; $params[] = $catFilter; }
    if ($statusFilter) { $where[] = 'status = ?'; $params[] = $statusFilter; }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $page    = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 30;
    $offset  = ($page - 1) * $perPage;

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM master_products $whereSql");
    $countStmt->execute($params);
    $totalProducts = (int)$countStmt->fetchColumn();
    $totalPages    = max(1, (int)ceil($totalProducts / $perPage));

    $stmt = $pdo->prepare("SELECT mp.*,
            (SELECT COUNT(*) FROM mp_products WHERE master_product_id = mp.id) AS shop_usage_count
        FROM master_products mp
        $whereSql ORDER BY mp.name LIMIT $perPage OFFSET $offset");
    $stmt->execute($params);
    $products = $stmt->fetchAll();

    $existingCategories = $pdo->query("SELECT DISTINCT category FROM master_products WHERE category IS NOT NULL AND category != '' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
} elseif ($tab === 'catalogs') {
    $catalogRows = $pdo->query(
        "SELECT ct.*, (SELECT COUNT(*) FROM master_products WHERE catalog_type = ct.slug) AS product_count
         FROM catalog_types ct ORDER BY ct.sort_order, ct.name"
    )->fetchAll();
} elseif ($tab === 'import') {
    $recentImports = $pdo->query("SELECT * FROM audit_logs WHERE action = 'catalog_import' ORDER BY created_at DESC LIMIT 10")->fetchAll();
}

function qstr(array $overrides = []): string {
    $base = [];
    foreach (['tab', 'q', 'category', 'status', 'page'] as $k) {
        if (isset($_GET[$k]) && $_GET[$k] !== '') $base[$k] = $_GET[$k];
    }
    return '?' . http_build_query(array_merge($base, $overrides));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Master Product Catalog — Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .mc-shell { max-width:1100px; margin:0 auto; padding:18px 16px 60px; }
        .mc-table { width:100%; border-collapse:collapse; font-size:.84rem; }
        .mc-table th { padding:9px 12px; text-align:left; font-size:.72rem; font-weight:800; text-transform:uppercase; letter-spacing:.05em; color:var(--text-muted,#6b7280); border-bottom:1px solid var(--border); background:var(--surface-muted,#f9fafb); }
        .mc-table td { padding:10px 12px; border-bottom:1px solid var(--border,#f1f5f9); vertical-align:middle; }
        .mc-table tr:last-child td { border-bottom:none; }
        .mc-badge { display:inline-block; padding:2px 9px; border-radius:20px; font-size:.68rem; font-weight:700; }
        .mc-card { background:var(--surface); border:1px solid var(--border); border-radius:14px; padding:18px; margin-bottom:16px; overflow-x:auto; }
        .mc-form-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:12px; }
        .mc-form-grid label { font-weight:600; font-size:.82rem; display:block; margin-bottom:4px; }
        .mc-form-grid input[type=text], .mc-form-grid select, .mc-form-grid textarea {
            width:100%; padding:7px 9px; border:1px solid var(--border); border-radius:8px; font-size:.84rem; box-sizing:border-box;
        }
        .mc-section-title { font-size:.74rem; font-weight:800; text-transform:uppercase; letter-spacing:.07em; color:var(--text-muted,#6b7280); margin:18px 0 10px; }
        .mc-section-title:first-child { margin-top:0; }
        .mc-thumb { width:56px; height:56px; object-fit:cover; border-radius:8px; border:1px solid var(--border); }
        .mc-tabs { display:flex; gap:5px; flex-wrap:wrap; border-bottom:1px solid var(--border); padding-bottom:12px; margin-bottom:18px; }
        .mc-tab { padding:7px 16px; border-radius:8px; font-size:.82rem; font-weight:700; text-decoration:none; border:1px solid var(--border); }
        .mc-tab.active { background:var(--primary-soft,#d1fae5); color:var(--primary,#0f766e); }
        .mc-tab:not(.active) { background:var(--surface); color:var(--text-muted,#6b7280); }
        .mc-dup-warning { background:#fffbeb; border:1px solid #fde68a; border-radius:10px; padding:14px 16px; margin-bottom:14px; font-size:.86rem; color:#92400e; }
        .pagination { display:flex; gap:4px; flex-wrap:wrap; align-items:center; margin-top:14px; }
        .pagination a, .pagination span { padding:5px 10px; border-radius:6px; border:1px solid var(--border); text-decoration:none; font-size:.82rem; color:var(--text); }
        .pagination a:hover { background:var(--surface-muted,#f9fafb); }
        .pagination .current { background:var(--primary,#0f766e); color:#fff; border-color:var(--primary,#0f766e); }
    </style>
</head>
<body>

<header class="topbar">
    <a href="index.php" class="button button-secondary button-small">← Dashboard</a>
    <h1 style="margin:0;font-size:1rem;font-weight:800;">🗂️ Master Product Catalog</h1>
</header>

<main class="mc-shell">

    <?php if ($flash): ?>
    <div class="alert alert-<?php echo sanitize($flash['type']); ?>" style="margin-bottom:14px;"><?php echo sanitize($flash['message']); ?></div>
    <?php endif; ?>

    <?php if ($error && str_starts_with($error, 'DUPLICATE:')): ?>
        <?php [, $dupName, $dupId] = explode(':', $error, 3); ?>
        <div class="mc-dup-warning">
            ⚠️ A similar product already exists: <strong><?php echo sanitize($dupName); ?></strong> (#<?php echo (int)$dupId; ?>).
            Review it, or re-submit below to save this one anyway.
        </div>
    <?php elseif ($error): ?>
        <div class="alert alert-error" style="margin-bottom:14px;"><?php echo sanitize($error); ?></div>
    <?php endif; ?>

    <p style="font-size:.84rem;color:var(--text-muted,#6b7280);margin-bottom:16px;">
        One shared catalog of common products — shops pick from it instead of building every product from scratch.
        Only admins manage this list; shops manage their own price, stock, and images once they add a product.
    </p>

    <div class="mc-tabs">
        <a href="?tab=products" class="mc-tab <?php echo $tab === 'products' ? 'active' : ''; ?>">📦 Products</a>
        <a href="?tab=catalogs" class="mc-tab <?php echo $tab === 'catalogs' ? 'active' : ''; ?>">🗃️ Catalogs</a>
        <a href="?tab=import"   class="mc-tab <?php echo $tab === 'import' ? 'active' : ''; ?>">⬆️⬇️ Import / Export</a>
    </div>

    <?php if ($tab === 'products'): ?>

    <form method="get" action="master_catalog.php" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px;">
        <input type="hidden" name="tab" value="products">
        <input type="text" name="q" value="<?php echo sanitize($q); ?>" placeholder="Search name, brand, SKU, keywords…" style="flex:1;min-width:200px;padding:7px 10px;border:1px solid var(--border);border-radius:8px;font-size:.82rem;">
        <select name="category" style="padding:7px 10px;border:1px solid var(--border);border-radius:8px;font-size:.82rem;">
            <option value="">All categories</option>
            <?php foreach ($existingCategories as $c): ?>
            <option value="<?php echo sanitize($c); ?>" <?php echo $catFilter === $c ? 'selected' : ''; ?>><?php echo sanitize($c); ?></option>
            <?php endforeach; ?>
        </select>
        <select name="status" style="padding:7px 10px;border:1px solid var(--border);border-radius:8px;font-size:.82rem;">
            <option value="">All statuses</option>
            <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
            <option value="inactive" <?php echo $statusFilter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
        </select>
        <button type="submit" class="button button-secondary button-small">Filter</button>
        <?php if ($q || $catFilter || $statusFilter): ?><a href="?tab=products" class="button button-secondary button-small">Clear</a><?php endif; ?>
    </form>

    <div class="mc-card">
        <table class="mc-table">
            <thead><tr><th></th><th>Name</th><th>Brand</th><th>Category</th><th>SKU</th><th>Shops using</th><th>Status</th><th style="text-align:right;">Actions</th></tr></thead>
            <tbody>
            <?php if (!$products): ?>
            <tr><td colspan="8" style="text-align:center;color:var(--text-muted,#6b7280);padding:24px;">No products match this filter.</td></tr>
            <?php endif; ?>
            <?php foreach ($products as $p): ?>
            <tr>
                <td><?php if ($p['default_image']): ?><img src="../<?php echo sanitize($p['default_image']); ?>" class="mc-thumb"><?php else: ?><span style="font-size:1.4rem;opacity:.3;">📦</span><?php endif; ?></td>
                <td><strong><?php echo sanitize($p['name']); ?></strong><?php if ($p['package_size']): ?><br><span style="font-size:.75rem;color:var(--text-muted);"><?php echo sanitize($p['package_size']); ?></span><?php endif; ?></td>
                <td><?php echo sanitize($p['brand'] ?: '—'); ?></td>
                <td><?php echo sanitize($p['category'] ?: '—'); ?></td>
                <td><?php echo sanitize($p['sku'] ?: '—'); ?></td>
                <td><?php echo (int)$p['shop_usage_count']; ?></td>
                <td><span class="mc-badge" style="background:<?php echo $p['status'] === 'active' ? '#d1fae5' : '#fee2e2'; ?>;color:<?php echo $p['status'] === 'active' ? '#065f46' : '#991b1b'; ?>;"><?php echo ucfirst($p['status']); ?></span></td>
                <td style="text-align:right;white-space:nowrap;">
                    <a href="?tab=products&edit=<?php echo $p['id']; ?>" class="button button-secondary button-small">Edit</a>
                    <form method="post" style="display:inline;" onsubmit="return confirm('<?php echo $p['status'] === 'active' ? 'Deactivate' : 'Activate'; ?> this product?');">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="toggle_product_status">
                        <input type="hidden" name="id" value="<?php echo $p['id']; ?>">
                        <button type="submit" class="button button-secondary button-small"><?php echo $p['status'] === 'active' ? 'Deactivate' : 'Activate'; ?></button>
                    </form>
                    <form method="post" style="display:inline;" onsubmit="return confirm('Delete this product permanently? This cannot be undone.');">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="delete_product">
                        <input type="hidden" name="id" value="<?php echo $p['id']; ?>">
                        <button type="submit" class="button button-secondary button-small" style="color:#c0392b;">Delete</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?><a href="<?php echo qstr(['page' => $page - 1]); ?>">‹ Prev</a><?php endif; ?>
            <?php
            $pStart = max(1, $page - 3);
            $pEnd   = min($totalPages, $page + 3);
            if ($pStart > 1) echo '<span>…</span>';
            for ($p = $pStart; $p <= $pEnd; $p++): ?>
                <?php if ($p === $page): ?><span class="current"><?php echo $p; ?></span>
                <?php else: ?><a href="<?php echo qstr(['page' => $p]); ?>"><?php echo $p; ?></a><?php endif; ?>
            <?php endfor;
            if ($pEnd < $totalPages) echo '<span>…</span>';
            ?>
            <?php if ($page < $totalPages): ?><a href="<?php echo qstr(['page' => $page + 1]); ?>">Next ›</a><?php endif; ?>
            <span style="color:var(--text-muted,#6b7280);border:none;padding-left:4px;">Page <?php echo $page; ?> of <?php echo $totalPages; ?> (<?php echo $totalProducts; ?> total)</span>
        </div>
        <?php endif; ?>
    </div>

    <div class="mc-card">
        <h2 style="margin-top:0;font-size:1rem;"><?php echo $editProduct ? 'Edit Product — ' . sanitize($editProduct['name']) : 'Add Product'; ?></h2>
        <form method="post" enctype="multipart/form-data">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="save_product">
            <input type="hidden" name="id" value="<?php echo $editProduct['id'] ?? 0; ?>">
            <?php if (str_starts_with($error, 'DUPLICATE:')): ?><input type="hidden" name="confirm_duplicate" value="1"><?php endif; ?>

            <p class="mc-section-title">Basics</p>
            <div class="mc-form-grid">
                <div><label>Product Name *</label><input type="text" name="name" value="<?php echo sanitize($_POST['name'] ?? ($editProduct['name'] ?? '')); ?>" required></div>
                <div><label>Brand</label><input type="text" name="brand" value="<?php echo sanitize($_POST['brand'] ?? ($editProduct['brand'] ?? '')); ?>"></div>
                <div><label>SKU</label><input type="text" name="sku" value="<?php echo sanitize($_POST['sku'] ?? ($editProduct['sku'] ?? '')); ?>"></div>
                <div><label>Catalog</label>
                    <select name="catalog_type">
                        <?php foreach ($catalogTypes as $ct => $ctLabel): ?>
                        <option value="<?php echo $ct; ?>" <?php echo ($_POST['catalog_type'] ?? ($editProduct['catalog_type'] ?? 'provision')) === $ct ? 'selected' : ''; ?>><?php echo sanitize($ctLabel); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Category</label>
                    <input type="text" name="category" list="mc-category-list" value="<?php echo sanitize($_POST['category'] ?? ($editProduct['category'] ?? '')); ?>" placeholder="e.g. Chocolate Drinks">
                    <datalist id="mc-category-list">
                        <?php foreach ($existingCategories as $c): ?><option value="<?php echo sanitize($c); ?>"><?php endforeach; ?>
                    </datalist>
                </div>
                <div><label>Package Size</label><input type="text" name="package_size" value="<?php echo sanitize($_POST['package_size'] ?? ($editProduct['package_size'] ?? '')); ?>" placeholder="e.g. 400g, 1.5L, 6-pack"></div>
                <div><label>Status</label>
                    <select name="status">
                        <option value="active" <?php echo ($_POST['status'] ?? ($editProduct['status'] ?? 'active')) === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo ($_POST['status'] ?? ($editProduct['status'] ?? '')) === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
            </div>
            <div style="margin-top:12px;"><label style="font-weight:600;font-size:.82rem;">Description</label><textarea name="description" rows="3" style="width:100%;padding:7px 9px;border:1px solid var(--border);border-radius:8px;font-size:.84rem;box-sizing:border-box;"><?php echo sanitize($_POST['description'] ?? ($editProduct['description'] ?? '')); ?></textarea></div>
            <div style="margin-top:12px;"><label style="font-weight:600;font-size:.82rem;">Search Keywords (comma-separated)</label><input type="text" name="search_keywords" value="<?php echo sanitize($_POST['search_keywords'] ?? ($editProduct['search_keywords'] ?? '')); ?>" style="width:100%;padding:7px 9px;border:1px solid var(--border);border-radius:8px;font-size:.84rem;box-sizing:border-box;" placeholder="chocolate, malt, breakfast drink"></div>

            <p class="mc-section-title">Image</p>
            <?php if ($editProduct && $editProduct['default_image']): ?>
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">
                <img src="../<?php echo sanitize($editProduct['default_image']); ?>" class="mc-thumb" style="width:70px;height:70px;">
                <label style="font-size:.8rem;font-weight:600;"><input type="checkbox" name="remove_default_image" value="1"> Remove current image</label>
            </div>
            <?php endif; ?>
            <input type="file" name="default_image" accept="image/*">

            <div style="margin-top:16px;display:flex;gap:8px;">
                <button type="submit" class="button button-primary"><?php echo str_starts_with($error, 'DUPLICATE:') ? 'Save Anyway' : 'Save Product'; ?></button>
                <?php if ($editProduct): ?><a href="?tab=products" class="button button-secondary">Cancel Edit</a><?php endif; ?>
            </div>
        </form>
    </div>

    <?php elseif ($tab === 'catalogs'): ?>

    <div class="mc-card">
        <table class="mc-table">
            <thead><tr><th>Name</th><th>Products</th><th style="text-align:right;">Actions</th></tr></thead>
            <tbody>
            <?php if (!$catalogRows): ?>
            <tr><td colspan="3" style="text-align:center;color:var(--text-muted,#6b7280);padding:24px;">No catalogs yet — add one below.</td></tr>
            <?php endif; ?>
            <?php foreach ($catalogRows as $ct): ?>
            <tr>
                <td><strong><?php echo sanitize($ct['name']); ?></strong></td>
                <td><?php echo (int)$ct['product_count']; ?></td>
                <td style="text-align:right;white-space:nowrap;">
                    <button type="button" class="button button-secondary button-small" onclick='editCatalogType(<?php echo json_encode($ct); ?>)'>Edit</button>
                    <form method="post" style="display:inline;" onsubmit="return confirm('Delete this catalog permanently?');">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="delete_catalog_type">
                        <input type="hidden" name="ct_id" value="<?php echo $ct['id']; ?>">
                        <button type="submit" class="button button-secondary button-small" style="color:#c0392b;">Delete</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="mc-card">
        <h2 id="mc-ct-heading" style="margin-top:0;font-size:1rem;">Add Catalog</h2>
        <form method="post" id="mc-ct-form">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="save_catalog_type">
            <input type="hidden" name="ct_id" id="f_ct_id" value="0">
            <div class="mc-form-grid">
                <div><label>Catalog Name *</label><input type="text" name="ct_name" id="f_ct_name" placeholder="e.g. Electrical Shop" required></div>
            </div>
            <div style="margin-top:16px;display:flex;gap:8px;">
                <button type="submit" class="button button-primary">Save Catalog</button>
                <button type="button" class="button button-secondary" onclick="resetCatalogTypeForm()">Cancel Edit</button>
            </div>
        </form>
    </div>

    <script>
    function editCatalogType(c) {
        document.getElementById('mc-ct-heading').textContent = 'Edit Catalog — ' + c.name;
        document.getElementById('f_ct_id').value = c.id;
        document.getElementById('f_ct_name').value = c.name;
        document.getElementById('mc-ct-form').scrollIntoView({ behavior: 'smooth' });
    }
    function resetCatalogTypeForm() {
        document.getElementById('mc-ct-form').reset();
        document.getElementById('f_ct_id').value = 0;
        document.getElementById('mc-ct-heading').textContent = 'Add Catalog';
    }
    </script>

    <?php else /* import */: ?>

    <div class="mc-card">
        <h2 style="margin-top:0;font-size:1rem;">Bulk Import (CSV)</h2>
        <p style="font-size:.84rem;color:var(--text-muted,#6b7280);">
            Columns: <code>name*, brand, category, sku, description, package_size, search_keywords</code>.
            Rows matching an existing product name (same catalog, case-insensitive) are skipped, not overwritten.
            Images aren't included in the CSV — add those per-product afterwards from the Products tab.
        </p>
        <p><a href="?tab=import&template=csv" class="button button-secondary button-small">⬇ Download empty template</a></p>
        <form method="post" enctype="multipart/form-data" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:10px;">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="import_csv">
            <select name="catalog_type" style="padding:7px 10px;border:1px solid var(--border);border-radius:8px;font-size:.82rem;">
                <?php foreach ($catalogTypes as $ct => $ctLabel): ?>
                <option value="<?php echo $ct; ?>"><?php echo sanitize($ctLabel); ?></option>
                <?php endforeach; ?>
            </select>
            <input type="file" name="csv_file" accept=".csv,text/csv" required>
            <button type="submit" class="button button-primary button-small">Upload &amp; Import</button>
        </form>
    </div>

    <div class="mc-card">
        <div style="display:flex;justify-content:space-between;align-items:center;">
            <h2 style="margin:0;font-size:1rem;">Export Full Catalog</h2>
            <a href="?tab=import&export=csv" class="button button-secondary button-small">⬇ Download CSV</a>
        </div>
    </div>

    <div class="mc-card">
        <h2 style="margin-top:0;font-size:1rem;">Recent Imports</h2>
        <?php if (!$recentImports): ?>
        <p style="text-align:center;color:var(--text-muted,#6b7280);padding:20px;">No imports yet.</p>
        <?php else: ?>
        <ul style="margin:0;padding-left:18px;font-size:.84rem;line-height:1.9;">
            <?php foreach ($recentImports as $imp): ?>
            <li><?php echo date('d M Y, g:i A', strtotime($imp['created_at'])); ?> — <?php echo sanitize($imp['description']); ?></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>

    <?php endif; ?>

</main>
</body>
</html>
