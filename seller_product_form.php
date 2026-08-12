<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/marketplace_functions.php';

require_module_enabled('mp', 'Marketplace');
require_login();
$user = current_user();
$myShops = get_shops_by_user((int)$user['id']);

if (!$myShops) {
    flash('Create your shop first.', 'warning');
    header('Location: seller_dashboard.php?tab=setup');
    exit;
}

$editId  = (int)($_GET['id'] ?? 0);
$product = null;
$images  = [];
if ($editId) {
    // Editing an existing product: shop context follows the product, not
    // whichever shop happens to be "active" — the seller may be editing a
    // listing under their other shop.
    $product = get_product($editId);
    $shop = null;
    if ($product) {
        foreach ($myShops as $s) { if ((int)$s['id'] === (int)$product['shop_id']) { $shop = $s; break; } }
    }
    if (!$shop) {
        flash('Product not found.', 'error');
        header('Location: seller_dashboard.php?tab=products');
        exit;
    }
    $images = get_product_images($editId);
} else {
    $shop = get_active_seller_shop((int)$user['id']);
}

$categories = $pdo->query('SELECT * FROM mp_categories ORDER BY sort_order, name')->fetchAll();
$maxImagesForShop = mp_shop_max_images((int)$shop['id']);
$error = '';

// Pre-fill from the Master Product Catalog, if the seller arrived via "Add from Catalog"
$catalogId      = (int)($_GET['catalog_id'] ?? 0);
$catalogProduct = null;
if ($catalogId && !$editId) {
    $cStmt = $pdo->prepare("SELECT * FROM master_products WHERE id=? AND status='active'");
    $cStmt->execute([$catalogId]);
    $catalogProduct = $cStmt->fetch();
}

// Best-effort match of the catalog item's own category text (e.g. "Rice")
// against this shop's broader mp_categories list (e.g. "Food") — only used
// to pre-select the dropdown; the seller can still change it freely.
$catalogSuggestedCategoryId = null;
if ($catalogProduct && $catalogProduct['category']) {
    foreach ($categories as $c) {
        if (strcasecmp($c['name'], $catalogProduct['category']) === 0) {
            $catalogSuggestedCategoryId = $c['id'];
            break;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $name          = trim($_POST['name']          ?? '');
    $description   = trim($_POST['description']   ?? '');
    $categoryId    = (int)($_POST['category_id']  ?? 0) ?: null;
    $price         = (float)($_POST['price']      ?? 0);
    $priceUnit     = trim($_POST['price_unit']    ?? '');
    if (mb_strlen($priceUnit) > 40) $priceUnit = mb_substr($priceUnit, 0, 40);
    $discountPrice = $_POST['discount_price'] !== '' ? (float)$_POST['discount_price'] : null;
    $stockQty      = (int)($_POST['stock_quantity'] ?? 0);
    $sku           = trim($_POST['sku']            ?? '');
    $condition     = $_POST['condition_type']      ?? 'new';
    $delivAvail    = isset($_POST['delivery_available']) ? 1 : 0;
    $submitType    = $_POST['submit_type']         ?? 'draft';
    $masterProductId = (int)($_POST['master_product_id'] ?? 0) ?: null;
    $wasNewProduct    = !$editId;

    if (!in_array($condition, ['new','used','refurbished'], true)) $condition = 'new';
    $status = $submitType === 'publish' ? 'pending_approval' : 'draft';

    if ($name === '') $error = 'Product name is required.';
    elseif ($price <= 0) $error = 'Price must be greater than 0.';
    elseif ($stockQty < 0) $error = 'Stock quantity cannot be negative.';
    elseif ($discountPrice !== null && $discountPrice >= $price) $error = 'Discount price must be less than the regular price.';
    elseif (!$editId && requires_verified_email('product_post') && !is_email_verified()) {
        $error = 'Please verify your email address before listing a product.';
    } elseif (!$editId && is_banned_from_feature((int)$user['id'], 'mp')) {
        $error = 'You have been restricted from the Marketplace. Contact support if you believe this is an error.';
    } elseif ($status === 'pending_approval' && (!$editId || $product['status'] === 'rejected')) {
        // A brand-new listing, or a rejected one being resubmitted, is about to
        // newly occupy a slot — re-check the limit. An already-approved/pending
        // product being edited in place never needs a new slot.
        $listCheck = mp_shop_can_list_product((int)$shop['id']);
        if (!$listCheck['allowed']) {
            if ($listCheck['no_subscription']) {
                // Send them straight to checkout with the reason, rather than
                // just showing the message on a form they can't submit anyway.
                flash('You need an active marketplace subscription before you can list products. Subscribe to a package to continue.', 'error');
                header('Location: pay_mp_subscription.php');
                exit;
            }
            $error = 'You have reached your monthly product limit. Upgrade your subscription or remove existing listings.';
        }
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
                "UPDATE mp_products SET name=?,description=?,category_id=?,price=?,price_unit=?,discount_price=?,stock_quantity=?,sku=?,condition_type=?,delivery_available=?,status=?,updated_at=NOW()$clearRejection WHERE id=?"
            )->execute([$name, $description ?: null, $categoryId, $price, $priceUnit ?: null, $discountPrice, $stockQty, $sku ?: null, $condition, $delivAvail, $newStatus, $editId]);
        } else {
            $slug = mp_unique_slug($name, 'mp_products', 'slug', $pdo);
            $pdo->prepare(
                'INSERT INTO mp_products (shop_id, category_id, master_product_id, name, slug, description, price, price_unit, discount_price, stock_quantity, sku, condition_type, delivery_available, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([$shop['id'], $categoryId, $masterProductId, $name, $slug, $description ?: null, $price, $priceUnit ?: null, $discountPrice, $stockQty, $sku ?: null, $condition, $delivAvail, $status]);
            $editId = (int)$pdo->lastInsertId();
        }

        // Handle image uploads (up to the shop's plan-based max, or unlimited)
        if (!empty($_FILES['product_images']['name'][0])) {
            $existCheck = $pdo->prepare('SELECT COUNT(*) FROM mp_product_images WHERE product_id=?');
            $existCheck->execute([$editId]);
            $existingCount = (int)$existCheck->fetchColumn();
            $maxImages = mp_shop_max_images((int)$shop['id']);
            $maxNew = $maxImages === -1 ? count($_FILES['product_images']['name']) : max(0, $maxImages - $existingCount);

            $files = $_FILES['product_images'];
            for ($i = 0; $i < min($maxNew, count($files['name'])); $i++) {
                if ($files['error'][$i] !== UPLOAD_ERR_OK || !$files['name'][$i]) continue;
                $file = ['name'=>$files['name'][$i],'type'=>$files['type'][$i],'tmp_name'=>$files['tmp_name'][$i],'error'=>$files['error'][$i],'size'=>$files['size'][$i]];
                if (!is_valid_image_upload($file)) continue;
                $path = save_uploaded_image($file, 'uploads/marketplace/products/' . $editId,
                    (int)get_platform_setting('img_mp_product_maxwidth', '1200'),
                    (int)get_platform_setting('img_mp_product_quality', '85'));
                if ($path) {
                    $isPrimary = ($existingCount === 0 && $i === 0) ? 1 : 0;
                    $pdo->prepare('INSERT INTO mp_product_images (product_id, image_path, is_primary, sort_order) VALUES (?,?,?,?)')
                        ->execute([$editId, $path, $isPrimary, $existingCount + $i]);
                }
            }
        } elseif ($wasNewProduct && $masterProductId) {
            // No photo of their own uploaded — reuse the catalog's default image as-is (same path, no file copy)
            $mStmt = $pdo->prepare('SELECT default_image FROM master_products WHERE id=?');
            $mStmt->execute([$masterProductId]);
            $masterDefaultImage = $mStmt->fetchColumn();
            if ($masterDefaultImage) {
                $pdo->prepare('INSERT INTO mp_product_images (product_id, image_path, is_primary, sort_order) VALUES (?,?,1,0)')
                    ->execute([$editId, $masterDefaultImage]);
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

    <?php if ($catalogProduct): ?>
    <div class="alert alert-success" style="display:flex;align-items:center;gap:10px;">
        <?php if ($catalogProduct['default_image']): ?><img src="<?php echo sanitize($catalogProduct['default_image']); ?>" alt="" style="width:40px;height:40px;object-fit:cover;border-radius:8px;flex-shrink:0;"><?php endif; ?>
        <span>📚 From the catalog: <strong><?php echo sanitize($catalogProduct['name']); ?></strong> — just set your price, stock, and condition below. Its catalog photo will be used automatically unless you upload your own.</span>
    </div>
    <?php endif; ?>

    <form method="post" action="seller_product_form.php<?php echo $editId ? '?id='.$editId : ($catalogId ? '?catalog_id='.$catalogId : ''); ?>" enctype="multipart/form-data">
        <?php echo csrf_field(); ?>
        <?php if ($catalogProduct): ?><input type="hidden" name="master_product_id" value="<?php echo (int)$catalogProduct['id']; ?>"><?php endif; ?>

        <!-- Basic Info -->
        <div class="pf-section">
            <p class="pf-section-title">Product Information</p>
            <div class="form-group">
                <label for="name">Product Name *</label>
                <input type="text" id="name" name="name" required value="<?php echo sanitize($_POST['name'] ?? ($product['name'] ?? ($catalogProduct['name'] ?? ''))); ?>" placeholder="e.g. Samsung Galaxy A14">
            </div>
            <div class="form-group">
                <label for="description">Description</label>
                <textarea id="description" name="description" class="rich-editor" rows="4" placeholder="Describe the product — condition, features, what's included…"><?php echo $_POST['description'] ?? ($product['description'] ?? ($catalogProduct['description'] ?? '')); ?></textarea>
            </div>
            <div class="form-group">
                <label for="category_id">Category</label>
                <select id="category_id" name="category_id">
                    <option value="">— Select category —</option>
                    <?php $selCat = $_POST['category_id'] ?? ($product['category_id'] ?? ($catalogSuggestedCategoryId ?? '')); ?>
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
                    <label for="price_unit">Unit (optional)</label>
                    <input type="text" id="price_unit" name="price_unit" maxlength="40" placeholder="e.g. litre, kg, acre, dozen, piece" value="<?php echo sanitize($_POST['price_unit'] ?? ($product['price_unit']??'')); ?>">
                    <p class="form-hint">Shown as "GH₵ X / unit" — leave blank for a flat price.</p>
                </div>
                <div class="form-group">
                    <label for="discount_price">Discount Price (GHS)</label>
                    <input type="number" id="discount_price" name="discount_price" min="0" step="0.01" value="<?php echo sanitize($_POST['discount_price'] ?? ($product['discount_price']??'')); ?>" placeholder="Optional — for sale">
                </div>
                <div class="form-group">
                    <label for="stock_quantity">Stock Quantity *</label>
                    <input type="number" id="stock_quantity" name="stock_quantity" required min="0" value="<?php echo sanitize($_POST['stock_quantity'] ?? ($product['stock_quantity']??'1000')); ?>">
                </div>
                <div class="form-group">
                    <label for="sku">SKU / Reference</label>
                    <input type="text" id="sku" name="sku" placeholder="Optional" value="<?php echo sanitize($_POST['sku'] ?? ($product['sku'] ?? ($catalogProduct['sku'] ?? ''))); ?>">
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
                    <button type="button" class="pf-del-img" onclick="deleteProductImage(<?php echo (int)$img['id']; ?>, <?php echo (int)$editId; ?>, this)">×</button>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <input type="file" name="product_images[]" multiple accept="image/jpeg,image/png,image/webp">
            <p class="form-hint">
                <?php if ($maxImagesForShop === -1): ?>
                    Select as many images as you like. JPEG/PNG/WEBP, max 5MB each. First image becomes the primary.
                <?php else: ?>
                    Select up to <?php echo max(0, $maxImagesForShop - count($images)); ?> more image<?php echo (max(0,$maxImagesForShop-count($images))===1)?'':'s'; ?> (plan limit: <?php echo $maxImagesForShop; ?>). JPEG/PNG/WEBP, max 5MB each. First image becomes the primary.
                <?php endif; ?>
            </p>
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

<script>
var CSRF = <?php echo json_encode(csrf_token()); ?>;
function deleteProductImage(imageId, productId, btn) {
    if (!confirm('Remove this image?')) return;
    btn.disabled = true;
    fetch('marketplace_ajax.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=delete_product_image&image_id=' + imageId + '&product_id=' + productId + '&csrf_token=' + encodeURIComponent(CSRF)
    }).then(function () {
        location.reload();
    }).catch(function () {
        btn.disabled = false;
        alert('Could not remove image. Please try again.');
    });
}
</script>

<?php require_once __DIR__ . '/partials/bottom_nav.php'; ?>
</body>
</html>
