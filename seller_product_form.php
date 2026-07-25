<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/marketplace_functions.php';

require_login();
$user = current_user();
$shop = get_shop_by_user((int)$user['id']);

if (!$shop) {
    flash('Create your shop first.', 'warning');
    header('Location: seller_dashboard.php?tab=setup');
    exit;
}

$editId  = (int)($_GET['id'] ?? 0);
$product = null;
$images  = [];
if ($editId) {
    $product = get_product($editId);
    if (!$product || (int)$product['shop_id'] !== (int)$shop['id']) {
        flash('Product not found.', 'error');
        header('Location: seller_dashboard.php?tab=products');
        exit;
    }
    $images = get_product_images($editId);
}

$categories = $pdo->query('SELECT * FROM mp_categories ORDER BY sort_order, name')->fetchAll();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $name          = trim($_POST['name']          ?? '');
    $description   = trim($_POST['description']   ?? '');
    $categoryId    = (int)($_POST['category_id']  ?? 0) ?: null;
    $price         = (float)($_POST['price']      ?? 0);
    $discountPrice = $_POST['discount_price'] !== '' ? (float)$_POST['discount_price'] : null;
    $stockQty      = (int)($_POST['stock_quantity'] ?? 0);
    $sku           = trim($_POST['sku']            ?? '');
    $condition     = $_POST['condition_type']      ?? 'new';
    $delivAvail    = isset($_POST['delivery_available']) ? 1 : 0;
    $submitType    = $_POST['submit_type']         ?? 'draft';

    if (!in_array($condition, ['new','used','refurbished'], true)) $condition = 'new';
    $status = $submitType === 'publish' ? 'pending_approval' : 'draft';

    if ($name === '') $error = 'Product name is required.';
    elseif ($price <= 0) $error = 'Price must be greater than 0.';
    elseif ($stockQty < 0) $error = 'Stock quantity cannot be negative.';
    elseif ($discountPrice !== null && $discountPrice >= $price) $error = 'Discount price must be less than the regular price.';
    elseif (!$editId && requires_verified_email('product_post') && !is_email_verified()) {
        $error = 'Please verify your email address before listing a product.';
    }

    if (!$error) {
        if ($editId) {
            $wasRejected = ($product['status'] ?? '') === 'rejected';
            if ($product['status'] === 'approved') {
                $newStatus = 'approved';
            } elseif ($product['status'] === 'out_of_stock') {
                // Restocking (or just editing while still out) shouldn't require
                // re-approval — it was already an approved listing.
                $newStatus = $stockQty > 0 ? 'approved' : 'out_of_stock';
            } else {
                $newStatus = $status;
            }
            // If previously rejected and user submits for approval, clear rejection reason
            $clearRejection = $wasRejected && $status === 'pending_approval' ? ', rejection_reason=NULL' : '';
            $pdo->prepare(
                "UPDATE mp_products SET name=?,description=?,category_id=?,price=?,discount_price=?,stock_quantity=?,sku=?,condition_type=?,delivery_available=?,status=?,updated_at=NOW()$clearRejection WHERE id=?"
            )->execute([$name, $description ?: null, $categoryId, $price, $discountPrice, $stockQty, $sku ?: null, $condition, $delivAvail, $newStatus, $editId]);
        } else {
            $slug = mp_unique_slug($name, 'mp_products', 'slug', $pdo);
            $pdo->prepare(
                'INSERT INTO mp_products (shop_id, category_id, name, slug, description, price, discount_price, stock_quantity, sku, condition_type, delivery_available, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([$shop['id'], $categoryId, $name, $slug, $description ?: null, $price, $discountPrice, $stockQty, $sku ?: null, $condition, $delivAvail, $status]);
            $editId = (int)$pdo->lastInsertId();
        }

        // Handle image uploads (up to 5)
        if (!empty($_FILES['product_images']['name'][0])) {
            $existingCount = (int)$pdo->prepare('SELECT COUNT(*) FROM mp_product_images WHERE product_id=?')->execute([$editId]) ? 0 : 0;
            $existCheck = $pdo->prepare('SELECT COUNT(*) FROM mp_product_images WHERE product_id=?');
            $existCheck->execute([$editId]);
            $existingCount = (int)$existCheck->fetchColumn();
            $maxNew = max(0, 5 - $existingCount);

            $files = $_FILES['product_images'];
            for ($i = 0; $i < min($maxNew, count($files['name'])); $i++) {
                if ($files['error'][$i] !== UPLOAD_ERR_OK || !$files['name'][$i]) continue;
                $file = ['name'=>$files['name'][$i],'type'=>$files['type'][$i],'tmp_name'=>$files['tmp_name'][$i],'error'=>$files['error'][$i],'size'=>$files['size'][$i]];
                if (!is_valid_image_upload($file)) continue;
                $path = save_uploaded_image($file, 'uploads/marketplace/products/' . $editId);
                if ($path) {
                    $isPrimary = ($existingCount === 0 && $i === 0) ? 1 : 0;
                    $pdo->prepare('INSERT INTO mp_product_images (product_id, image_path, is_primary, sort_order) VALUES (?,?,?,?)')
                        ->execute([$editId, $path, $isPrimary, $existingCount + $i]);
                }
            }
        }

        // Notify admins + moderators on new submission or resubmission after rejection
        if ($status === 'pending_approval') {
            $notifTitle = isset($wasRejected) && $wasRejected
                ? 'Product Resubmitted After Rejection'
                : 'New Product Pending Approval';
            $notifBody  = $shop['shop_name'] . ' submitted "' . $name . '" for review. Check Admin → Marketplace.';
            notify_moderators('approve_products', $notifTitle, $notifBody);
        }

        $successMsg = $status === 'pending_approval'
            ? (isset($wasRejected) && $wasRejected ? 'Product resubmitted for review. Admin has been notified.' : 'Product submitted for admin approval.')
            : 'Product saved.';
        flash($successMsg, 'success');
        header('Location: seller_dashboard.php?tab=products');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $product ? 'Edit Product' : 'Add Product'; ?> — AkuapemConnect</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .pf-shell { max-width:680px; margin:0 auto; padding:20px 16px 80px; }
        .pf-section { background:var(--surface); border:1px solid var(--border); border-radius:14px; padding:18px; margin-bottom:14px; }
        .pf-section-title { font-size:.74rem; font-weight:800; text-transform:uppercase; letter-spacing:.07em; color:var(--text-muted,#6b7280); margin:0 0 14px; }
        label { font-weight:600; font-size:.86rem; display:block; margin-bottom:4px; }
        .form-group { margin-bottom:14px; }
        .form-hint { font-size:.74rem; color:var(--text-muted,#6b7280); margin-top:3px; }
        .pf-grid2 { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
        @media(max-width:480px){ .pf-grid2 { grid-template-columns:1fr; } }
        .pf-cond input { display:none; }
        .pf-cond label { display:block; border:2px solid var(--border); border-radius:10px; padding:8px 12px; cursor:pointer; text-align:center; font-size:.82rem; font-weight:700; transition:all .12s; }
        .pf-cond input:checked + label { border-color:var(--primary,#0f766e); background:var(--primary-soft,#d1fae5); color:var(--primary,#0f766e); }
        .pf-existing-imgs { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:10px; }
        .pf-existing-img  { position:relative; }
        .pf-existing-img img { width:64px; height:64px; border-radius:8px; object-fit:cover; border:2px solid var(--border); }
        .pf-del-img { position:absolute; top:-6px; right:-6px; background:#ef4444; color:#fff; border:none; border-radius:50%; width:18px; height:18px; font-size:.7rem; cursor:pointer; display:flex; align-items:center; justify-content:center; }
    </style>
</head>
<body class="has-bottom-nav">

<header class="app-topbar">
    <a href="seller_dashboard.php?tab=products" class="button button-secondary button-small">← Products</a>
    <span class="brand"><?php echo $product ? 'Edit Product' : 'Add Product'; ?></span>
</header>

<main class="pf-shell">

    <?php if ($error): ?><div class="alert alert-error"><?php echo sanitize($error); ?></div><?php endif; ?>

    <form method="post" action="seller_product_form.php<?php echo $editId ? '?id='.$editId : ''; ?>" enctype="multipart/form-data">
        <?php echo csrf_field(); ?>

        <!-- Basic Info -->
        <div class="pf-section">
            <p class="pf-section-title">Product Information</p>
            <div class="form-group">
                <label for="name">Product Name *</label>
                <input type="text" id="name" name="name" required value="<?php echo sanitize($_POST['name'] ?? ($product['name']??'')); ?>" placeholder="e.g. Samsung Galaxy A14">
            </div>
            <div class="form-group">
                <label for="description">Description</label>
                <textarea id="description" name="description" class="rich-editor" rows="4" placeholder="Describe the product — condition, features, what's included…"><?php echo sanitize($_POST['description'] ?? ($product['description']??'')); ?></textarea>
            </div>
            <div class="form-group">
                <label for="category_id">Category</label>
                <select id="category_id" name="category_id">
                    <option value="">— Select category —</option>
                    <?php $selCat = $_POST['category_id'] ?? ($product['category_id']??''); ?>
                    <?php foreach ($categories as $c): ?>
                    <option value="<?php echo $c['id']; ?>" <?php echo $selCat==$c['id']?'selected':''; ?>><?php echo $c['icon'].' '; ?><?php echo sanitize($c['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <!-- Pricing & Stock -->
        <div class="pf-section">
            <p class="pf-section-title">Pricing &amp; Stock</p>
            <div class="pf-grid2">
                <div class="form-group">
                    <label for="price">Price (GHS) *</label>
                    <input type="number" id="price" name="price" required min="0.01" step="0.01" value="<?php echo sanitize($_POST['price'] ?? ($product['price']??'')); ?>">
                </div>
                <div class="form-group">
                    <label for="discount_price">Discount Price (GHS)</label>
                    <input type="number" id="discount_price" name="discount_price" min="0" step="0.01" value="<?php echo sanitize($_POST['discount_price'] ?? ($product['discount_price']??'')); ?>" placeholder="Optional — for sale">
                </div>
                <div class="form-group">
                    <label for="stock_quantity">Stock Quantity *</label>
                    <input type="number" id="stock_quantity" name="stock_quantity" required min="0" value="<?php echo sanitize($_POST['stock_quantity'] ?? ($product['stock_quantity']??'1')); ?>">
                </div>
                <div class="form-group">
                    <label for="sku">SKU / Reference</label>
                    <input type="text" id="sku" name="sku" placeholder="Optional" value="<?php echo sanitize($_POST['sku'] ?? ($product['sku']??'')); ?>">
                </div>
            </div>
        </div>

        <!-- Condition -->
        <div class="pf-section">
            <p class="pf-section-title">Condition</p>
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;">
                <?php $selCond = $_POST['condition_type'] ?? ($product['condition_type']??'new'); ?>
                <?php foreach (['new'=>'New','used'=>'Used','refurbished'=>'Refurbished'] as $v=>$l): ?>
                <div class="pf-cond">
                    <input type="radio" name="condition_type" id="cond_<?php echo $v; ?>" value="<?php echo $v; ?>" <?php echo $selCond===$v?'checked':''; ?>>
                    <label for="cond_<?php echo $v; ?>"><?php echo $l; ?></label>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Images -->
        <div class="pf-section">
            <p class="pf-section-title">Product Images (up to 5)</p>
            <?php if ($images): ?>
            <div class="pf-existing-imgs">
                <?php foreach ($images as $img): ?>
                <div class="pf-existing-img">
                    <img src="<?php echo sanitize($img['image_path']); ?>" alt="">
                    <form method="post" action="marketplace_ajax.php" style="display:inline;">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="delete_product_image">
                        <input type="hidden" name="image_id" value="<?php echo $img['id']; ?>">
                        <input type="hidden" name="product_id" value="<?php echo $editId; ?>">
                        <button type="submit" class="pf-del-img" onclick="return confirm('Remove this image?');">×</button>
                    </form>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <input type="file" name="product_images[]" multiple accept="image/jpeg,image/png,image/webp">
            <p class="form-hint">Select up to <?php echo max(1,5-count($images)); ?> more images. JPEG/PNG/WEBP, max 5MB each. First image becomes the primary.</p>
        </div>

        <!-- Delivery -->
        <div class="pf-section">
            <p class="pf-section-title">Delivery Options</p>
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:600;font-size:.88rem;">
                <input type="checkbox" name="delivery_available" value="1" <?php echo ($_POST['delivery_available'] ?? ($product['delivery_available']??'1')) ? 'checked' : ''; ?>>
                Available for delivery via AkuapemConnect riders
            </label>
        </div>

        <!-- Action buttons -->
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <?php if (!$product || $product['status'] === 'draft'): ?>
            <button type="submit" name="submit_type" value="draft" class="button button-secondary" style="flex:1;">Save as Draft</button>
            <button type="submit" name="submit_type" value="publish" class="button button-primary" style="flex:2;">Submit for Approval →</button>
            <?php else: ?>
            <button type="submit" name="submit_type" value="draft" class="button button-primary" style="flex:1;">Save Changes</button>
            <?php endif; ?>
        </div>
    </form>

</main>

<?php require_once __DIR__ . '/partials/bottom_nav.php'; ?>
</body>
</html>
