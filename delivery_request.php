<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/delivery_functions.php';

require_module_enabled('delivery', 'Delivery Services');
require_login();
$user  = current_user();
$error = '';

$categories = ['documents','food','electronics','clothing','medical_supplies','groceries','parcels','other'];
$payments   = ['cash'=>'Cash on Delivery','mobile_money'=>'Mobile Money','card'=>'Card','wallet'=>'Wallet Balance'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $pickupLocation     = trim($_POST['pickup_location']     ?? '');
    $pickupMapsLink     = trim($_POST['pickup_maps_link']    ?? '') ?: null;
    $pickupContactName  = trim($_POST['pickup_contact_name'] ?? '');
    $pickupContactPhone = trim($_POST['pickup_contact_phone']?? '');
    $dropoffLocation    = trim($_POST['dropoff_location']    ?? '');
    $dropoffMapsLink    = trim($_POST['dropoff_maps_link']   ?? '') ?: null;
    $receiverName       = trim($_POST['receiver_name']       ?? '');
    $receiverPhone      = trim($_POST['receiver_phone']      ?? '');
    $itemDescription    = trim($_POST['item_description']    ?? '');
    $itemCategory       = $_POST['item_category'] ?? 'parcels';
    $packageWeight      = $_POST['package_weight'] !== '' ? (float)($_POST['package_weight'] ?? 0) : null;
    $deliveryNotes      = trim($_POST['delivery_notes']      ?? '');
    $deliveryFee        = $_POST['delivery_fee'] !== '' ? (float)($_POST['delivery_fee'] ?? 0) : null;
    $paymentMethod      = isset($payments[$_POST['payment_method'] ?? '']) ? $_POST['payment_method'] : 'cash';
    $preferredDate      = $_POST['preferred_date'] ?? '';
    $preferredTime      = $_POST['preferred_time'] ?? '';

    if (!in_array($itemCategory, $categories, true)) $itemCategory = 'parcels';

    // Validate
    if ($pickupLocation === '')    $error = 'Pickup location is required.';
    elseif ($pickupContactName === '') $error = 'Pickup contact name is required.';
    elseif ($pickupContactPhone === '') $error = 'Pickup contact phone is required.';
    elseif ($dropoffLocation === '') $error = 'Drop-off location is required.';
    elseif ($receiverName === '')   $error = 'Receiver name is required.';
    elseif ($receiverPhone === '')  $error = 'Receiver phone number is required.';
    elseif ($itemDescription === '') $error = 'Describe the item(s) to be delivered.';
    elseif (requires_verified_email('delivery_request') && !is_email_verified()) {
        $error = 'Please verify your email address before creating a delivery request.';
    }

    if (!$error) {
        // Fraud check
        $fraudReason  = check_delivery_fraud($_POST, (int)$user['id']);
        $isFlagged    = $fraudReason !== null ? 1 : 0;

        // Auto-approval: trusted customers skip the queue
        $autoApproved = !$isFlagged && is_auto_approve_eligible((int)$user['id']) ? 1 : 0;
        $initStatus   = ($isFlagged || !$autoApproved) ? 'pending_approval' : 'approved';

        $stmt = $pdo->prepare(
            'INSERT INTO delivery_requests
             (customer_id, pickup_location, pickup_maps_link, pickup_contact_name, pickup_contact_phone,
              dropoff_location, dropoff_maps_link, receiver_name, receiver_phone,
              item_description, item_category, package_weight,
              delivery_notes, delivery_fee, payment_method,
              preferred_date, preferred_time, status,
              is_flagged, flag_reason, auto_approved,
              created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())'
        );
        $stmt->execute([
            $user['id'],
            $pickupLocation, $pickupMapsLink, $pickupContactName, $pickupContactPhone,
            $dropoffLocation, $dropoffMapsLink, $receiverName, $receiverPhone,
            $itemDescription, $itemCategory, $packageWeight,
            $deliveryNotes ?: null, $deliveryFee, $paymentMethod,
            $preferredDate ?: null, $preferredTime ?: null,
            $initStatus,
            $isFlagged, $fraudReason, $autoApproved,
        ]);
        $newId = (int)$pdo->lastInsertId();

        if ($initStatus === 'approved') {
            // Notify all active approved agents
            $agentsForNotify = $pdo->query(
                "SELECT da.user_id FROM delivery_agents da
                 WHERE da.verification_status = 'approved'
                   AND da.availability_status IN ('available','busy')"
            )->fetchAll();
            foreach ($agentsForNotify as $ag) {
                notify_user((int)$ag['user_id'], 'New Delivery Job Available',
                    "A new approved delivery request (#$newId) is ready. Open your agent dashboard to apply.",
                    'info');
            }
            $msg = 'Delivery request approved and posted! Riders have been notified.';
        } else {
            $msg = 'Delivery request submitted and is awaiting admin review. We\'ll notify you once it\'s approved.';
        }

        // Notify admins + moderators of flagged/pending requests
        if ($isFlagged) {
            notify_moderators('approve_delivery_requests',
                'Flagged Delivery Request',
                "Delivery request #$newId was flagged: $fraudReason");
        } elseif ($initStatus === 'pending_approval') {
            notify_moderators('approve_delivery_requests',
                'New Delivery Request Pending',
                "Delivery request #$newId needs admin approval.");
        }

        flash($msg, 'success');
        header('Location: delivery_detail.php?id=' . $newId);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request Delivery — AkuapemConnect</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .dr-shell { max-width:640px; margin:0 auto; padding:20px 16px 80px; }
        .dr-section { background:var(--surface); border:1px solid var(--border); border-radius:14px; padding:18px; margin-bottom:16px; }
        .dr-section-title { font-size:.78rem; font-weight:800; text-transform:uppercase; letter-spacing:.07em; color:var(--text-muted,#6b7280); margin:0 0 14px; display:flex; align-items:center; gap:7px; }
        .dr-row { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
        @media(max-width:480px){ .dr-row { grid-template-columns:1fr; } }
        label { font-weight:600; font-size:.87rem; display:block; margin-bottom:4px; }
        .form-group { margin-bottom:14px; }
        .form-hint { font-size:.75rem; color:var(--text-muted,#6b7280); margin-top:3px; }
        .dr-cat-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(110px,1fr)); gap:8px; }
        .dr-cat-item input { display:none; }
        .dr-cat-item label { cursor:pointer; border:2px solid var(--border); border-radius:10px; padding:10px 8px; text-align:center; font-size:.8rem; font-weight:700; transition:all .12s; display:block; margin:0; }
        .dr-cat-item input:checked + label { border-color:var(--primary,#0f766e); background:var(--primary-soft,#d1fae5); color:var(--primary,#0f766e); }
        .dr-cat-icon { font-size:1.4rem; display:block; margin-bottom:4px; }
    </style>
</head>
<body class="has-bottom-nav">

<header class="app-topbar">
    <a href="delivery.php" class="button button-secondary button-small">← Back</a>
    <span class="brand">Request Delivery</span>
</header>

<main class="dr-shell">
    <?php if ($error): ?>
        <div class="alert alert-error"><?php echo sanitize($error); ?></div>
    <?php endif; ?>

    <form method="post" action="delivery_request.php">
        <?php echo csrf_field(); ?>

        <!-- 1 · Pickup Details -->
        <div class="dr-section">
            <p class="dr-section-title">📍 Pickup Details</p>
            <div class="form-group">
                <label for="pickup_location">Pickup Location *</label>
                <input type="text" id="pickup_location" name="pickup_location" required
                       placeholder="e.g. Akropong Market, near Total Station"
                       value="<?php echo sanitize($_POST['pickup_location'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label for="pickup_maps_link">Pickup — Google Maps Link <span style="font-weight:400;color:var(--text-muted,#6b7280);">(optional)</span></label>
                <input type="url" id="pickup_maps_link" name="pickup_maps_link"
                       placeholder="https://maps.google.com/…"
                       value="<?php echo sanitize($_POST['pickup_maps_link'] ?? ''); ?>">
                <p style="font-size:.74rem;color:var(--text-muted,#6b7280);margin-top:3px;">Tip: open Google Maps, find the spot, tap Share → Copy link.</p>
            </div>
            <div class="dr-row">
                <div class="form-group">
                    <label for="pickup_contact_name">Contact Name *</label>
                    <input type="text" id="pickup_contact_name" name="pickup_contact_name" required
                           placeholder="Who to meet at pickup"
                           value="<?php echo sanitize($_POST['pickup_contact_name'] ?? $user['name']); ?>">
                </div>
                <div class="form-group">
                    <label for="pickup_contact_phone">Contact Phone *</label>
                    <input type="tel" id="pickup_contact_phone" name="pickup_contact_phone" required
                           placeholder="+233 XX XXX XXXX"
                           value="<?php echo sanitize($_POST['pickup_contact_phone'] ?? $user['phone']); ?>">
                </div>
            </div>
        </div>

        <!-- 2 · Drop-off Details -->
        <div class="dr-section">
            <p class="dr-section-title">🏁 Drop-off Details</p>
            <div class="form-group">
                <label for="dropoff_location">Drop-off Location *</label>
                <input type="text" id="dropoff_location" name="dropoff_location" required
                       placeholder="e.g. Abiriw Junction, Blue Gate"
                       value="<?php echo sanitize($_POST['dropoff_location'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label for="dropoff_maps_link">Drop-off — Google Maps Link <span style="font-weight:400;color:var(--text-muted,#6b7280);">(optional)</span></label>
                <input type="url" id="dropoff_maps_link" name="dropoff_maps_link"
                       placeholder="https://maps.google.com/…"
                       value="<?php echo sanitize($_POST['dropoff_maps_link'] ?? ''); ?>">
                <p style="font-size:.74rem;color:var(--text-muted,#6b7280);margin-top:3px;">Helps the rider find the exact drop-off point faster.</p>
            </div>
            <div class="dr-row">
                <div class="form-group">
                    <label for="receiver_name">Receiver Name *</label>
                    <input type="text" id="receiver_name" name="receiver_name" required
                           placeholder="Full name of recipient"
                           value="<?php echo sanitize($_POST['receiver_name'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label for="receiver_phone">Receiver Phone *</label>
                    <input type="tel" id="receiver_phone" name="receiver_phone" required
                           placeholder="+233 XX XXX XXXX"
                           value="<?php echo sanitize($_POST['receiver_phone'] ?? ''); ?>">
                </div>
            </div>
        </div>

        <!-- 3 · Item Details -->
        <div class="dr-section">
            <p class="dr-section-title">📦 Item Details</p>
            <div class="form-group">
                <label>Item Category *</label>
                <div class="dr-cat-grid">
                    <?php
                    $catIcons = ['documents'=>'📄','food'=>'🍔','electronics'=>'📱','clothing'=>'👕','medical_supplies'=>'💊','groceries'=>'🛒','parcels'=>'📦','other'=>'📦'];
                    $catLabels = ['documents'=>'Documents','food'=>'Food','electronics'=>'Electronics','clothing'=>'Clothing','medical_supplies'=>'Medical','groceries'=>'Groceries','parcels'=>'Parcels','other'=>'Other'];
                    $selCat = $_POST['item_category'] ?? 'parcels';
                    foreach ($categories as $cat): ?>
                    <div class="dr-cat-item">
                        <input type="radio" name="item_category" id="cat_<?php echo $cat; ?>" value="<?php echo $cat; ?>"
                               <?php echo $selCat === $cat ? 'checked' : ''; ?>>
                        <label for="cat_<?php echo $cat; ?>">
                            <span class="dr-cat-icon"><?php echo $catIcons[$cat]; ?></span>
                            <?php echo $catLabels[$cat]; ?>
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="form-group">
                <label for="item_description">Item Description *</label>
                <textarea id="item_description" name="item_description" rows="3" required
                          placeholder="Briefly describe what needs to be delivered…"><?php echo sanitize($_POST['item_description'] ?? ''); ?></textarea>
            </div>
            <div class="dr-row">
                <div class="form-group">
                    <label for="package_weight">Package Weight (kg)</label>
                    <input type="number" id="package_weight" name="package_weight" min="0" step="0.1"
                           placeholder="Optional"
                           value="<?php echo sanitize($_POST['package_weight'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label for="delivery_fee">Agreed Delivery Fee (GH₵)</label>
                    <input type="number" id="delivery_fee" name="delivery_fee" min="0" step="0.01"
                           placeholder="Optional — negotiate with agent"
                           value="<?php echo sanitize($_POST['delivery_fee'] ?? ''); ?>">
                </div>
            </div>
            <div class="form-group">
                <label for="delivery_notes">Delivery Notes</label>
                <textarea id="delivery_notes" name="delivery_notes" rows="2"
                          placeholder="Any special instructions, fragile items, etc."><?php echo sanitize($_POST['delivery_notes'] ?? ''); ?></textarea>
            </div>
        </div>

        <!-- 4 · Preferences -->
        <div class="dr-section">
            <p class="dr-section-title">⏰ Preferences</p>
            <div class="dr-row">
                <div class="form-group">
                    <label for="preferred_date">Preferred Pickup Date</label>
                    <input type="date" id="preferred_date" name="preferred_date"
                           value="<?php echo sanitize($_POST['preferred_date'] ?? ''); ?>">
                    <script>
                    (function(){
                        var el = document.getElementById('preferred_date');
                        var d  = new Date();
                        var today = d.getFullYear() + '-'
                            + String(d.getMonth()+1).padStart(2,'0') + '-'
                            + String(d.getDate()).padStart(2,'0');
                        el.min = today;
                        if (!el.value) el.value = today;
                    })();
                    </script>
                </div>
                <div class="form-group">
                    <label for="preferred_time">Preferred Pickup Time</label>
                    <input type="time" id="preferred_time" name="preferred_time"
                           value="<?php echo sanitize($_POST['preferred_time'] ?? ''); ?>">
                </div>
            </div>
            <div class="form-group">
                <label for="payment_method">Payment Method *</label>
                <select id="payment_method" name="payment_method">
                    <?php $selPm = $_POST['payment_method'] ?? 'cash'; ?>
                    <?php foreach ($payments as $val => $lbl): ?>
                        <option value="<?php echo $val; ?>" <?php echo $selPm === $val ? 'selected' : ''; ?>><?php echo sanitize($lbl); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <button type="submit" class="button button-primary" style="width:100%;font-size:1rem;padding:14px;">
            Submit Delivery Request
        </button>
    </form>
</main>

<?php require_once __DIR__ . '/partials/bottom_nav.php'; ?>
</body>
</html>
