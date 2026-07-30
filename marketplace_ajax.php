<?php
/**
 * Marketplace AJAX/POST handler.
 * Actions: add_to_cart, update_quantity, remove_from_cart,
 *          toggle_save, delete_product_image, submit_shop_verification,
 *          approve_product, reject_product
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/marketplace_functions.php';

require_login();
csrf_check();

$user   = current_user();
$action = $_POST['action'] ?? '';

// Admin/manager actions in this file (approve_product, reject_product, etc.)
// must keep working even while the module is switched off — only block the
// customer-facing actions (add_to_cart and friends).
if (!is_admin_or_manager()) {
    require_module_enabled('mp', 'Marketplace');
}

function mp_error(string $msg, string $back = 'marketplace.php'): never {
    flash($msg, 'error');
    header('Location: ' . $back);
    exit;
}

// ── add_to_cart ──────────────────────────────────────────────────────────────
if ($action === 'add_to_cart') {
    $productId = (int)($_POST['product_id'] ?? 0);
    $quantity  = max(1, (int)($_POST['quantity'] ?? 1));

    $product = get_product($productId);
    if (!$product || $product['status'] !== 'approved') {
        mp_error('Product not available.', 'marketplace.php');
    }
    if ($product['stock_quantity'] < 1) {
        mp_error('This product is out of stock.', 'product.php?id=' . $productId);
    }
    $quantity = min($quantity, $product['stock_quantity']);

    $cartId = mp_get_or_create_cart((int)$user['id']);

    // Upsert cart item
    $existing = $pdo->prepare('SELECT id, quantity FROM mp_cart_items WHERE cart_id=? AND product_id=?');
    $existing->execute([$cartId, $productId]);
    $row = $existing->fetch();

    if ($row) {
        $newQty = min($row['quantity'] + $quantity, $product['stock_quantity']);
        $pdo->prepare('UPDATE mp_cart_items SET quantity=? WHERE id=?')->execute([$newQty, $row['id']]);
    } else {
        $pdo->prepare('INSERT INTO mp_cart_items (cart_id, product_id, quantity) VALUES (?,?,?)')->execute([$cartId, $productId, $quantity]);
    }

    flash('Added to cart!', 'success');
    $back = $_SERVER['HTTP_REFERER'] ?? 'product.php?id=' . $productId;
    header('Location: ' . $back);
    exit;
}

// ── update_quantity ───────────────────────────────────────────────────────────
if ($action === 'update_quantity') {
    $productId = (int)($_POST['product_id'] ?? 0);
    $quantity  = (int)($_POST['quantity'] ?? 0);

    $cartId = mp_get_or_create_cart((int)$user['id']);

    if ($quantity <= 0) {
        $pdo->prepare('DELETE FROM mp_cart_items WHERE cart_id=? AND product_id=?')->execute([$cartId, $productId]);
    } else {
        $product = get_product($productId);
        $qty = $product ? min($quantity, $product['stock_quantity']) : $quantity;
        $pdo->prepare('UPDATE mp_cart_items SET quantity=? WHERE cart_id=? AND product_id=?')->execute([$qty, $cartId, $productId]);
    }

    header('Location: cart.php');
    exit;
}

// ── remove_from_cart ──────────────────────────────────────────────────────────
if ($action === 'remove_from_cart') {
    $productId = (int)($_POST['product_id'] ?? 0);
    $cartId    = mp_get_or_create_cart((int)$user['id']);
    $pdo->prepare('DELETE FROM mp_cart_items WHERE cart_id=? AND product_id=?')->execute([$cartId, $productId]);
    flash('Item removed from cart.', 'info');
    header('Location: cart.php');
    exit;
}

// ── toggle_save ───────────────────────────────────────────────────────────────
if ($action === 'toggle_save') {
    $productId = (int)($_POST['product_id'] ?? 0);

    $existing = $pdo->prepare('SELECT id FROM mp_saved_products WHERE user_id=? AND product_id=?');
    $existing->execute([$user['id'], $productId]);
    $row = $existing->fetch();

    if ($row) {
        $pdo->prepare('DELETE FROM mp_saved_products WHERE id=?')->execute([$row['id']]);
        flash('Removed from saved products.', 'info');
    } else {
        $pdo->prepare('INSERT IGNORE INTO mp_saved_products (user_id, product_id) VALUES (?,?)')->execute([$user['id'], $productId]);
        flash('Product saved! ❤️', 'success');
    }

    header('Location: product.php?id=' . $productId);
    exit;
}

// ── delete_product_image ──────────────────────────────────────────────────────
if ($action === 'delete_product_image') {
    $imageId   = (int)($_POST['image_id']   ?? 0);
    $productId = (int)($_POST['product_id'] ?? 0);

    // Verify image belongs to seller's product
    $check = $pdo->prepare(
        'SELECT mpi.id, mpi.image_path, mpi.is_primary FROM mp_product_images mpi
         JOIN mp_products mp ON mpi.product_id = mp.id
         JOIN mp_shops ms ON mp.shop_id = ms.id
         WHERE mpi.id=? AND mpi.product_id=? AND ms.user_id=?'
    );
    $check->execute([$imageId, $productId, $user['id']]);
    $img = $check->fetch();

    if ($img) {
        $pdo->prepare('DELETE FROM mp_product_images WHERE id=?')->execute([$imageId]);
        // If it was primary, make the next one primary
        if ($img['is_primary']) {
            $pdo->prepare('UPDATE mp_product_images SET is_primary=1 WHERE product_id=? LIMIT 1')->execute([$productId]);
        }
        flash('Image removed.', 'info');
    }

    header('Location: seller_product_form.php?id=' . $productId);
    exit;
}

// ── submit_shop_verification ──────────────────────────────────────────────────
if ($action === 'submit_shop_verification') {
    $shop = get_shop_by_user((int)$user['id']);
    if (!$shop) mp_error('Create your shop first.', 'seller_dashboard.php');

    $gcFile  = $_FILES['ghana_card']   ?? null;
    $bizFile = $_FILES['business_reg'] ?? null;

    if (empty($gcFile['name'])) mp_error('Ghana Card photo is required.', 'seller_dashboard.php?tab=verify');
    if (!is_valid_image_upload($gcFile)) mp_error('Ghana Card must be a valid image file.', 'seller_dashboard.php?tab=verify');

    $gcPath  = save_uploaded_image($gcFile, 'uploads/marketplace/verify/' . $shop['id'] . '/ghana_card');
    $bizPath = ($bizFile && $bizFile['name'] && is_valid_image_upload($bizFile))
        ? save_uploaded_image($bizFile, 'uploads/marketplace/verify/' . $shop['id'] . '/business_reg')
        : null;

    $existing = $pdo->prepare('SELECT id FROM mp_shop_verifications WHERE shop_id=?');
    $existing->execute([$shop['id']]);
    $existRow = $existing->fetch();

    if ($existRow) {
        $pdo->prepare('UPDATE mp_shop_verifications SET ghana_card_path=?,business_reg_path=?,status="pending",rejection_reason=NULL,submitted_at=NOW(),reviewed_at=NULL WHERE id=?')
            ->execute([$gcPath, $bizPath, $existRow['id']]);
    } else {
        $pdo->prepare('INSERT INTO mp_shop_verifications (shop_id, ghana_card_path, business_reg_path) VALUES (?,?,?)')
            ->execute([$shop['id'], $gcPath, $bizPath]);
    }

    $pdo->prepare("UPDATE mp_shops SET verification_status='pending' WHERE id=?")->execute([$shop['id']]);

    notify_moderators('approve_shops', 'Shop Verification Request',
        $shop['shop_name'] . ' submitted verification documents. Review in Admin → Marketplace.');

    flash('Verification documents submitted! Await admin review.', 'success');
    header('Location: seller_dashboard.php?tab=verify');
    exit;
}

// ── approve_product (admin/manager only) ──────────────────────────────────────
if ($action === 'approve_product') {
    if (!is_admin_or_manager()) mp_error('Not authorised.', 'marketplace.php');
    $pid     = (int)($_POST['product_id'] ?? 0);
    $prodRow = $pdo->prepare('SELECT mp.*, ms.user_id AS owner_id, ms.shop_name FROM mp_products mp JOIN mp_shops ms ON mp.shop_id=ms.id WHERE mp.id=?');
    $prodRow->execute([$pid]);
    $prod = $prodRow->fetch();
    if ($prod) {
        $pdo->prepare("UPDATE mp_products SET status='approved', updated_at=NOW() WHERE id=?")->execute([$pid]);
        notify_user((int)$prod['owner_id'], 'Product Approved ✅', '"' . $prod['name'] . '" is now live on the marketplace!', 'success');
        log_audit_action($user['id'], 'mp_product_approve', 'Approved product #' . $pid . ': ' . $prod['name']);
        flash('Product approved and now live.', 'success');
    }
    $back = $_SERVER['HTTP_REFERER'] ?? 'admin/marketplace.php?tab=products';
    header('Location: ' . $back);
    exit;
}

// ── reject_product (admin/manager only) ───────────────────────────────────────
if ($action === 'reject_product') {
    if (!is_admin_or_manager()) mp_error('Not authorised.', 'marketplace.php');
    $pid    = (int)($_POST['product_id'] ?? 0);
    $reason = trim($_POST['rejection_reason'] ?? '');
    if (!$reason) mp_error('A rejection reason is required.', 'product.php?id=' . $pid);
    $prodRow = $pdo->prepare('SELECT mp.*, ms.user_id AS owner_id FROM mp_products mp JOIN mp_shops ms ON mp.shop_id=ms.id WHERE mp.id=?');
    $prodRow->execute([$pid]);
    $prod = $prodRow->fetch();
    if ($prod) {
        $pdo->prepare("UPDATE mp_products SET status='rejected', rejection_reason=?, updated_at=NOW() WHERE id=?")->execute([$reason, $pid]);
        notify_user((int)$prod['owner_id'], 'Product Rejected', '"' . $prod['name'] . '" was not approved. Reason: ' . $reason, 'error');
        log_audit_action($user['id'], 'mp_product_reject', 'Rejected product #' . $pid . ': ' . $prod['name'] . '. Reason: ' . $reason);
        flash('Product rejected.', 'info');
    }
    $back = $_SERVER['HTTP_REFERER'] ?? 'admin/marketplace.php?tab=products';
    header('Location: ' . $back);
    exit;
}

mp_error('Unknown action.', 'marketplace.php');
