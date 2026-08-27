<?php
/**
 * Create/edit an accommodation listing. Image upload block mirrors
 * seller_product_form.php exactly (save_uploaded_image()/is_valid_image_upload()
 * from functions.php — no new upload system).
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/marketplace_functions.php'; // mp_unique_slug()
require_once __DIR__ . '/accommodation_functions.php';

require_module_enabled('accommodation', 'Accommodation');
require_login();

$user = current_user();

$editId  = (int)($_GET['id'] ?? 0);
$listing = null;
$images  = [];
if ($editId) {
    $listing = get_accommodation_listing($editId);
    if (!$listing || ((int)$listing['user_id'] !== (int)$user['id'] && !is_admin_or_manager())) {
        flash('Listing not found.', 'error');
        header('Location: my_accommodation.php');
        exit;
    }
    $images = get_accommodation_images($editId);
}

$types      = get_accommodation_types();
$facilities = get_accommodation_facilities();
$townsGrouped = get_towns_grouped_by_district();
$error = '';

$selectedTypeId = (int)($_POST['accommodation_type_id'] ?? $listing['accommodation_type_id'] ?? 0);
$selectedTypeRow = null;
foreach ($types as $t) { if ((int)$t['id'] === $selectedTypeId) { $selectedTypeRow = $t; break; } }
$initialMaxImages = (int)($selectedTypeRow['max_images'] ?? ($types[0]['max_images'] ?? 10));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $title          = trim($_POST['title'] ?? '');
    $accTypeId      = (int)($_POST['accommodation_type_id'] ?? 0);
    $description    = trim($_POST['description'] ?? '');
    $townId         = (int)($_POST['town_id'] ?? 0) ?: null;
    $area           = trim($_POST['area'] ?? '');
    $price          = $_POST['price'] !== '' ? (float)$_POST['price'] : null;
    $pricePeriod    = in_array($_POST['price_period'] ?? '', ['night','week','month','year','negotiable','on_request'], true) ? $_POST['price_period'] : 'month';
    $bedrooms       = $_POST['bedrooms']  !== '' ? max(0, (int)$_POST['bedrooms'])  : null;
    $bathrooms      = $_POST['bathrooms'] !== '' ? max(0, (int)$_POST['bathrooms']) : null;
    $furnished      = in_array($_POST['furnished_status'] ?? '', ['furnished','unfurnished','partly_furnished'], true) ? $_POST['furnished_status'] : null;
    $guests         = $_POST['guests_capacity'] !== '' ? max(0, (int)$_POST['guests_capacity']) : null;
    $checkin        = trim($_POST['checkin_info']  ?? '');
    $checkout       = trim($_POST['checkout_info'] ?? '');
    $roomClass      = trim($_POST['room_class'] ?? '');
    $contactPhone   = trim($_POST['contact_phone'] ?? '');
    $contactWhatsapp = trim($_POST['contact_whatsapp'] ?? '');
    $submitType     = $_POST['submit_type'] ?? 'draft';
    $selectedFacilities = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['facilities'] ?? [])))));

    $status = $submitType === 'publish' ? 'pending_approval' : 'draft';

    $typeRow = null;
    foreach ($types as $t) { if ((int)$t['id'] === $accTypeId) { $typeRow = $t; break; } }

    if ($title === '') $error = 'Title is required.';
    elseif (!$typeRow) $error = 'Please choose a valid accommodation type.';
    elseif ($contactPhone === '') $error = 'Contact phone is required.';
    elseif (!$editId && requires_verified_email('accommodation_post') && !is_email_verified()) {
        $error = 'Please verify your email address before listing accommodation.';
    } elseif (!$editId && is_banned_from_feature((int)$user['id'], 'accommodation')) {
        $error = 'You have been restricted from listing accommodation. Contact support if you believe this is an error.';
    }

    // A brand-new listing, or a rejected one being resubmitted, is about to
    // newly occupy a slot — re-check the limit. Mirrors seller_product_form.php's
    // fix: never discard the seller's work just because they lack a
    // subscription — save it as a draft and remember it so it auto-publishes
    // the moment their package activates (accommodation_publish_pending_draft()).
    $needsSubscriptionFirst = false;
    if (!$error && $status === 'pending_approval' && (!$editId || $listing['status'] === 'rejected')) {
        $listCheck = accommodation_can_list((int)$user['id']);
        if (!$listCheck['allowed']) {
            if ($listCheck['no_subscription']) {
                $status = 'draft';
                $needsSubscriptionFirst = true;
            } else {
                $error = 'You have reached your listing package limit. Upgrade your package or remove existing listings.';
            }
        }
    }

    if (!$error) {
        $facilitiesJson = json_encode($selectedFacilities);

        if ($editId) {
            $wasRejected = ($listing['status'] ?? '') === 'rejected';
            $newStatus = $listing['status'] === 'approved' ? 'approved' : $status;
            $clearRejection = $wasRejected && $status === 'pending_approval' ? ', rejection_reason=NULL' : '';
            $pdo->prepare(
                "UPDATE accommodation_listings SET title=?, accommodation_type_id=?, description=?, town_id=?, area=?, price=?, price_period=?,
                    facilities=?, bedrooms=?, bathrooms=?, furnished_status=?, guests_capacity=?, checkin_info=?, checkout_info=?,
                    room_class=?, contact_phone=?, contact_whatsapp=?, status=?, updated_at=NOW()$clearRejection WHERE id=?"
            )->execute([$title, $accTypeId, $description ?: null, $townId, $area ?: null, $price, $pricePeriod,
                $facilitiesJson, $bedrooms, $bathrooms, $furnished, $guests, $checkin ?: null, $checkout ?: null,
                $roomClass ?: null, $contactPhone, $contactWhatsapp ?: null, $newStatus, $editId]);
        } else {
            $slug = mp_unique_slug($title, 'accommodation_listings', 'slug', $pdo);
            $pdo->prepare(
                'INSERT INTO accommodation_listings (user_id, accommodation_type_id, title, slug, description, town_id, area, price, price_period, facilities, bedrooms, bathrooms, furnished_status, guests_capacity, checkin_info, checkout_info, room_class, contact_phone, contact_whatsapp, status)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([$user['id'], $accTypeId, $title, $slug, $description ?: null, $townId, $area ?: null, $price, $pricePeriod,
                $facilitiesJson, $bedrooms, $bathrooms, $furnished, $guests, $checkin ?: null, $checkout ?: null,
                $roomClass ?: null, $contactPhone, $contactWhatsapp ?: null, $status]);
            $editId = (int)$pdo->lastInsertId();
        }

        // Image uploads — same pattern as seller_product_form.php
        if (!empty($_FILES['listing_images']['name'][0])) {
            $existCheck = $pdo->prepare('SELECT COUNT(*) FROM accommodation_images WHERE listing_id=?');
            $existCheck->execute([$editId]);
            $existingCount = (int)$existCheck->fetchColumn();
            $maxImages = (int)($typeRow['max_images'] ?? 10);
            $maxNew = max(0, $maxImages - $existingCount);

            $files = $_FILES['listing_images'];
            for ($i = 0; $i < min($maxNew, count($files['name'])); $i++) {
                if ($files['error'][$i] !== UPLOAD_ERR_OK || !$files['name'][$i]) continue;
                $file = ['name'=>$files['name'][$i],'type'=>$files['type'][$i],'tmp_name'=>$files['tmp_name'][$i],'error'=>$files['error'][$i],'size'=>$files['size'][$i]];
                if (!is_valid_image_upload($file)) continue;
                $path = save_uploaded_image($file, 'uploads/accommodation/' . $editId, 1200, 85);
                if ($path) {
                    $isPrimary = ($existingCount === 0 && $i === 0) ? 1 : 0;
                    $pdo->prepare('INSERT INTO accommodation_images (listing_id, image_path, is_primary, sort_order) VALUES (?,?,?,?)')
                        ->execute([$editId, $path, $isPrimary, $existingCount + $i]);
                }
            }
        }

        if ($status === 'pending_approval') {
            notify_moderators('manage_accommodation',
                isset($wasRejected) && $wasRejected ? 'Accommodation Listing Resubmitted' : 'New Accommodation Listing Pending Approval',
                $user['name'] . ' submitted "' . $title . '" for review. Check Admin → Accommodation.');
        }

        if ($needsSubscriptionFirst) {
            $_SESSION['accommodation_pending_publish_id'] = $editId;
            flash('Your listing was saved — choose a listing package below to publish it.', 'info');
            header('Location: pay_accommodation_subscription.php');
            exit;
        }

        $successMsg = $status === 'pending_approval'
            ? (isset($wasRejected) && $wasRejected ? 'Listing resubmitted for review.' : 'Listing submitted for admin approval.')
            : 'Listing saved.';
        flash($successMsg, 'success');
        header('Location: my_accommodation.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $listing ? 'Edit Listing' : 'List Your Accommodation'; ?> — <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .af-shell { max-width:680px; margin:0 auto; padding:20px 16px 80px; }
        .af-section { background:var(--surface); border:1px solid var(--border); border-radius:14px; padding:18px; margin-bottom:14px; }
        .af-section-title { font-size:.74rem; font-weight:800; text-transform:uppercase; letter-spacing:.07em; color:var(--text-muted,#6b7280); margin:0 0 14px; }
        label { font-weight:600; font-size:.86rem; display:block; margin-bottom:4px; }
        .form-group { margin-bottom:14px; }
        .af-grid2 { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
        @media(max-width:480px){ .af-grid2 { grid-template-columns:1fr; } }
        .af-facility-check { display:flex; align-items:center; gap:6px; font-weight:400; font-size:.84rem; padding:5px 0; }
        .af-existing-imgs { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:10px; }
        .af-existing-img  { position:relative; }
        .af-existing-img img { width:64px; height:64px; border-radius:8px; object-fit:cover; border:2px solid var(--border); }
    </style>
</head>
<body class="has-bottom-nav">

<header class="app-topbar">
    <a href="my_accommodation.php" class="button button-secondary button-small">← My Listings</a>
    <span class="brand"><?php echo $listing ? 'Edit Listing' : 'List Accommodation'; ?></span>
</header>

<main class="af-shell">
    <?php if ($error): ?><div class="alert alert-error"><?php echo sanitize($error); ?></div><?php endif; ?>

    <form method="post" action="accommodation_form.php<?php echo $editId ? '?id='.$editId : ''; ?>" enctype="multipart/form-data">
        <?php echo csrf_field(); ?>

        <div class="af-section">
            <p class="af-section-title">Basic Info</p>
            <div class="form-group">
                <label for="title">Title *</label>
                <input type="text" id="title" name="title" required value="<?php echo sanitize($_POST['title'] ?? ($listing['title'] ?? '')); ?>" placeholder="e.g. Self-Contained near Aburi Junction">
            </div>
            <div class="form-group">
                <label for="accommodation_type_id">Accommodation Type *</label>
                <select id="accommodation_type_id" name="accommodation_type_id" required onchange="afToggleTypeFields()">
                    <optgroup label="Rooms & Houses">
                        <?php foreach ($types as $t): if ($t['category']!=='room_house') continue; ?>
                        <option value="<?php echo $t['id']; ?>" data-category="room_house" data-max-images="<?php echo (int)$t['max_images']; ?>" <?php echo (int)($_POST['accommodation_type_id'] ?? $listing['accommodation_type_id'] ?? 0)===(int)$t['id']?'selected':''; ?>><?php echo $t['icon'].' '; ?><?php echo sanitize($t['name']); ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                    <optgroup label="Hotels & Guest Houses">
                        <?php foreach ($types as $t): if ($t['category']!=='hotel') continue; ?>
                        <option value="<?php echo $t['id']; ?>" data-category="hotel" data-max-images="<?php echo (int)$t['max_images']; ?>" <?php echo (int)($_POST['accommodation_type_id'] ?? $listing['accommodation_type_id'] ?? 0)===(int)$t['id']?'selected':''; ?>><?php echo $t['icon'].' '; ?><?php echo sanitize($t['name']); ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                </select>
            </div>
            <div class="form-group">
                <label for="room_class">Room Class / Category (optional)</label>
                <input type="text" id="room_class" name="room_class" value="<?php echo sanitize($_POST['room_class'] ?? ($listing['room_class'] ?? '')); ?>" placeholder="e.g. Standard Room, Deluxe Room, Executive Suite">
            </div>
            <div class="form-group">
                <label for="description">Description</label>
                <textarea id="description" name="description" class="rich-editor" rows="4"><?php echo $_POST['description'] ?? ($listing['description'] ?? ''); ?></textarea>
            </div>
        </div>

        <div class="af-section">
            <p class="af-section-title">Contact</p>
            <div class="af-grid2">
                <div class="form-group">
                    <label for="contact_phone">Phone *</label>
                    <input type="tel" id="contact_phone" name="contact_phone" required value="<?php echo sanitize($_POST['contact_phone'] ?? ($listing['contact_phone'] ?? $user['phone'] ?? '')); ?>" placeholder="e.g. 0244000000">
                </div>
                <div class="form-group">
                    <label for="contact_whatsapp">WhatsApp (optional)</label>
                    <input type="tel" id="contact_whatsapp" name="contact_whatsapp" value="<?php echo sanitize($_POST['contact_whatsapp'] ?? ($listing['contact_whatsapp'] ?? '')); ?>" placeholder="e.g. 0244000000">
                </div>
            </div>
            <p class="form-hint" style="font-size:.74rem;color:var(--text-muted,#6b7280);margin-top:-8px;">Shown to interested renters/guests instead of your account phone.</p>
        </div>

        <div class="af-section">
            <p class="af-section-title">Location</p>
            <div class="af-grid2">
                <div class="form-group">
                    <label for="town_id">Town</label>
                    <select id="town_id" name="town_id">
                        <option value="">— Select town —</option>
                        <?php $selTown = $_POST['town_id'] ?? ($listing['town_id'] ?? ''); ?>
                        <?php foreach ($townsGrouped as $district => $ts): ?>
                        <optgroup label="<?php echo sanitize($district); ?>">
                            <?php foreach ($ts as $t): ?>
                            <option value="<?php echo $t['id']; ?>" <?php echo $selTown==$t['id']?'selected':''; ?>><?php echo sanitize($t['name']); ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="area">Area / Landmark</label>
                    <input type="text" id="area" name="area" value="<?php echo sanitize($_POST['area'] ?? ($listing['area'] ?? '')); ?>" placeholder="e.g. Near the market">
                </div>
            </div>
        </div>

        <div class="af-section">
            <p class="af-section-title">Pricing</p>
            <div class="af-grid2">
                <div class="form-group">
                    <label for="price">Price (GHS)</label>
                    <input type="number" id="price" name="price" min="0" step="0.01" value="<?php echo sanitize($_POST['price'] ?? ($listing['price']??'')); ?>" placeholder="Leave blank if negotiable">
                </div>
                <div class="form-group">
                    <label for="price_period">Price Period</label>
                    <?php $selPeriod = $_POST['price_period'] ?? ($listing['price_period'] ?? 'month'); ?>
                    <select id="price_period" name="price_period">
                        <?php foreach (['night'=>'Per night','week'=>'Per week','month'=>'Per month','year'=>'Per year','negotiable'=>'Negotiable','on_request'=>'Price on request'] as $v=>$l): ?>
                        <option value="<?php echo $v; ?>" <?php echo $selPeriod===$v?'selected':''; ?>><?php echo $l; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <div class="af-section" id="af-room-fields">
            <p class="af-section-title">Room / House Details</p>
            <div class="af-grid2">
                <div class="form-group">
                    <label for="bedrooms">Bedrooms</label>
                    <input type="number" id="bedrooms" name="bedrooms" min="0" value="<?php echo sanitize($_POST['bedrooms'] ?? ($listing['bedrooms']??'')); ?>">
                </div>
                <div class="form-group">
                    <label for="bathrooms">Bathrooms</label>
                    <input type="number" id="bathrooms" name="bathrooms" min="0" value="<?php echo sanitize($_POST['bathrooms'] ?? ($listing['bathrooms']??'')); ?>">
                </div>
                <div class="form-group">
                    <label for="furnished_status">Furnished</label>
                    <?php $selFurnished = $_POST['furnished_status'] ?? ($listing['furnished_status'] ?? ''); ?>
                    <select id="furnished_status" name="furnished_status">
                        <option value="">— Not specified —</option>
                        <?php foreach (['furnished'=>'Furnished','unfurnished'=>'Unfurnished','partly_furnished'=>'Partly Furnished'] as $v=>$l): ?>
                        <option value="<?php echo $v; ?>" <?php echo $selFurnished===$v?'selected':''; ?>><?php echo $l; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <div class="af-section" id="af-hotel-fields" style="display:none;">
            <p class="af-section-title">Hotel / Guest House Details</p>
            <div class="af-grid2">
                <div class="form-group">
                    <label for="guests_capacity">Guests Capacity</label>
                    <input type="number" id="guests_capacity" name="guests_capacity" min="0" value="<?php echo sanitize($_POST['guests_capacity'] ?? ($listing['guests_capacity']??'')); ?>">
                </div>
                <div class="form-group">
                    <label for="checkin_info">Check-in</label>
                    <input type="text" id="checkin_info" name="checkin_info" value="<?php echo sanitize($_POST['checkin_info'] ?? ($listing['checkin_info']??'')); ?>" placeholder="e.g. From 2:00 PM">
                </div>
                <div class="form-group">
                    <label for="checkout_info">Check-out</label>
                    <input type="text" id="checkout_info" name="checkout_info" value="<?php echo sanitize($_POST['checkout_info'] ?? ($listing['checkout_info']??'')); ?>" placeholder="e.g. Before 11:00 AM">
                </div>
            </div>
        </div>

        <div class="af-section">
            <p class="af-section-title">Facilities</p>
            <?php
            $selFacilities = $_POST['facilities'] ?? ($listing ? (json_decode($listing['facilities'] ?? '[]', true) ?: []) : []);
            $selFacilities = array_map('intval', $selFacilities);
            ?>
            <div class="af-grid2">
                <?php foreach ($facilities as $f): ?>
                <label class="af-facility-check">
                    <input type="checkbox" name="facilities[]" value="<?php echo $f['id']; ?>" <?php echo in_array((int)$f['id'],$selFacilities,true)?'checked':''; ?>>
                    <?php echo $f['icon'] ? $f['icon'].' ' : ''; ?><?php echo sanitize($f['name']); ?>
                </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="af-section">
            <p class="af-section-title" id="af-photos-title">Photos (up to <?php echo $initialMaxImages; ?>)</p>
            <?php if ($images): ?>
            <div class="af-existing-imgs">
                <?php foreach ($images as $img): ?>
                <div class="af-existing-img"><img src="<?php echo sanitize($img['image_path']); ?>" alt=""></div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <input type="file" name="listing_images[]" multiple accept="image/jpeg,image/png,image/webp">
            <p class="form-hint" style="font-size:.74rem;color:var(--text-muted,#6b7280);margin-top:4px;">JPEG/PNG/WEBP, max 5MB each. First image becomes the primary photo.</p>
        </div>

        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <?php if (!$listing || $listing['status'] === 'draft'): ?>
            <button type="submit" name="submit_type" value="draft" class="button button-secondary" style="flex:1;">Save as Draft</button>
            <button type="submit" name="submit_type" value="publish" class="button button-primary" style="flex:2;">Submit for Approval →</button>
            <?php else: ?>
            <button type="submit" name="submit_type" value="draft" class="button button-primary" style="flex:1;">Save Changes</button>
            <?php endif; ?>
        </div>
    </form>
</main>

<script src="assets/js/rich-editor.js"></script>
<script>
var AF_TYPES = {};
<?php foreach ($types as $t): ?>
AF_TYPES[<?php echo (int)$t['id']; ?>] = { category: '<?php echo $t['category']; ?>', maxImages: <?php echo (int)$t['max_images']; ?> };
<?php endforeach; ?>
function afToggleTypeFields() {
    var sel = document.getElementById('accommodation_type_id');
    var info = AF_TYPES[sel.value] || { category: 'room_house', maxImages: 10 };
    document.getElementById('af-room-fields').style.display  = info.category === 'hotel' ? 'none' : '';
    document.getElementById('af-hotel-fields').style.display = info.category === 'hotel' ? '' : 'none';
    document.getElementById('af-photos-title').textContent = 'Photos (up to ' + info.maxImages + ')';
}
afToggleTypeFields();
</script>

<?php require_once __DIR__ . '/partials/bottom_nav.php'; ?>
</body>
</html>
