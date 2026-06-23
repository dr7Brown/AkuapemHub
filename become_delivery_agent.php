<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/delivery_functions.php';

require_login();
$user = current_user();

// If already registered, redirect to agent dashboard
$existing = get_delivery_agent_for_user((int)$user['id']);
if ($existing) {
    flash('You already have a delivery agent profile.', 'info');
    header('Location: delivery_agent_jobs.php');
    exit;
}

$error = '';
$vehicleTypes = ['motorbike','bicycle','car','van','truck','public_transport','other'];
$idTypes      = ['ghana_card'=>'Ghana Card','passport'=>'Passport','other'=>'Other'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $vehicleType         = $_POST['vehicle_type'] ?? '';
    $vehicleRegistration = trim($_POST['vehicle_registration'] ?? '');
    $licenseNumber       = trim($_POST['license_number'] ?? '');
    $serviceArea         = trim($_POST['service_area'] ?? '');
    $bio                 = trim($_POST['bio'] ?? '');
    $idType              = $_POST['id_type'] ?? '';
    $idTypeCustom        = trim($_POST['id_type_custom'] ?? '');
    $idNumber            = trim($_POST['id_number'] ?? '');

    if (!in_array($vehicleType, $vehicleTypes, true)) $error = 'Select a valid vehicle type.';
    elseif ($serviceArea === '') $error = 'Describe your service area.';
    elseif (!array_key_exists($idType, $idTypes)) $error = 'Select an ID type.';
    elseif ($idType === 'other' && $idTypeCustom === '') $error = 'Specify the type of ID document.';
    elseif ($idNumber === '') $error = 'Enter your ID number.';
    elseif (empty($_FILES['id_document']['name'])) $error = 'Upload a photo of your ID document.';
    elseif (!is_valid_image_upload($_FILES['id_document'])) $error = 'ID document must be a JPEG, PNG, or WEBP image under 5MB.';

    if (!$error) {
        $idDocPath = save_uploaded_image($_FILES['id_document'], 'uploads/delivery_agents/' . $user['id']);

        $pdo->prepare(
            'INSERT INTO delivery_agents
             (user_id, vehicle_type, vehicle_registration, license_number,
              service_area, bio, id_type, id_type_custom, id_number,
              id_document_path, availability_status, verification_status, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,\'offline\',\'pending\',NOW(),NOW())'
        )->execute([
            $user['id'],
            $vehicleType, $vehicleRegistration ?: null, $licenseNumber ?: null,
            $serviceArea, $bio ?: null,
            $idType, ($idType === 'other' ? $idTypeCustom : null), $idNumber,
            $idDocPath,
        ]);

        // Notify admin
        notify_moderators('approve_delivery_agents',
            'New Delivery Agent Application',
            display_name($user) . ' has applied to become a delivery agent. Review in Admin → Delivery.');

        notify_user(
            (int)$user['id'],
            'Application Received',
            'Your delivery agent application is under review. We\'ll notify you once approved.',
            'info'
        );

        flash('Application submitted! You\'ll be notified once an admin approves your profile.', 'success');
        header('Location: delivery.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Become a Delivery Agent — AkuapemConnect</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .ba-shell { max-width:640px; margin:0 auto; padding:20px 16px 80px; }
        .ba-section { background:var(--surface); border:1px solid var(--border); border-radius:14px; padding:18px; margin-bottom:16px; }
        .ba-section-title { font-size:.75rem; font-weight:800; text-transform:uppercase; letter-spacing:.07em; color:var(--text-muted,#6b7280); margin:0 0 14px; }
        .ba-row { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
        @media(max-width:480px){ .ba-row { grid-template-columns:1fr; } }
        label { font-weight:600; font-size:.87rem; display:block; margin-bottom:4px; }
        .form-group { margin-bottom:14px; }
        .form-hint { font-size:.75rem; color:var(--text-muted,#6b7280); margin-top:3px; }
        .ba-vehicle-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(100px,1fr)); gap:8px; margin-bottom:4px; }
        .ba-vtype input { display:none; }
        .ba-vtype label { cursor:pointer; border:2px solid var(--border); border-radius:10px; padding:10px 6px; text-align:center; font-size:.78rem; font-weight:700; transition:all .12s; display:block; margin:0; }
        .ba-vtype input:checked + label { border-color:var(--primary,#0f766e); background:var(--primary-soft,#d1fae5); color:var(--primary,#0f766e); }
        .ba-vtype-icon { font-size:1.4rem; display:block; margin-bottom:4px; }
        .ba-info { background:#f0fdf4; border:1px solid #a7f3d0; border-radius:10px; padding:12px 14px; margin-bottom:16px; font-size:.85rem; line-height:1.6; }
    </style>
</head>
<body class="has-bottom-nav">

<header class="app-topbar">
    <a href="delivery.php" class="button button-secondary button-small">← Back</a>
    <span class="brand">Become a Delivery Agent</span>
</header>

<main class="ba-shell">

    <div class="ba-info">
        🛵 <strong>How it works:</strong> Register your vehicle and ID, and an admin will verify your profile (usually within 24 hours). Once approved, you can start accepting delivery jobs and earning money.
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error"><?php echo sanitize($error); ?></div>
    <?php endif; ?>

    <form method="post" action="become_delivery_agent.php" enctype="multipart/form-data">
        <?php echo csrf_field(); ?>

        <!-- Vehicle Details -->
        <div class="ba-section">
            <p class="ba-section-title">🚗 Vehicle Details</p>
            <div class="form-group">
                <label>Vehicle Type *</label>
                <div class="ba-vehicle-grid">
                    <?php
                    $vtIcons  = ['motorbike'=>'🛵','bicycle'=>'🚲','car'=>'🚗','van'=>'🚐','truck'=>'🚛','public_transport'=>'🚌','other'=>'🚚'];
                    $vtLabels = ['motorbike'=>'Motorbike','bicycle'=>'Bicycle','car'=>'Car','van'=>'Van','truck'=>'Truck','public_transport'=>'Public','other'=>'Other'];
                    $selVt = $_POST['vehicle_type'] ?? '';
                    foreach ($vehicleTypes as $vt): ?>
                    <div class="ba-vtype">
                        <input type="radio" name="vehicle_type" id="vt_<?php echo $vt; ?>" value="<?php echo $vt; ?>"
                               <?php echo $selVt === $vt ? 'checked' : ''; ?> required>
                        <label for="vt_<?php echo $vt; ?>">
                            <span class="ba-vtype-icon"><?php echo $vtIcons[$vt]; ?></span>
                            <?php echo $vtLabels[$vt]; ?>
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="ba-row">
                <div class="form-group">
                    <label for="vehicle_registration">Vehicle Registration</label>
                    <input type="text" id="vehicle_registration" name="vehicle_registration"
                           placeholder="e.g. GR-1234-23"
                           value="<?php echo sanitize($_POST['vehicle_registration'] ?? ''); ?>">
                    <span class="form-hint">Optional but recommended</span>
                </div>
                <div class="form-group">
                    <label for="license_number">Driving / Rider's License No.</label>
                    <input type="text" id="license_number" name="license_number"
                           placeholder="Optional"
                           value="<?php echo sanitize($_POST['license_number'] ?? ''); ?>">
                </div>
            </div>
        </div>

        <!-- Service Area & Bio -->
        <div class="ba-section">
            <p class="ba-section-title">📍 Service Area</p>
            <div class="form-group">
                <label for="service_area">Service Area *</label>
                <input type="text" id="service_area" name="service_area" required
                       placeholder="e.g. Akropong, Aburi, Adukrom, Mampong"
                       value="<?php echo sanitize($_POST['service_area'] ?? ''); ?>">
                <span class="form-hint">List the towns/areas you cover</span>
            </div>
            <div class="form-group">
                <label for="bio">About You</label>
                <textarea id="bio" name="bio" rows="3"
                          placeholder="Experience, reliability, additional services…"><?php echo sanitize($_POST['bio'] ?? ''); ?></textarea>
            </div>
        </div>

        <!-- ID Verification -->
        <div class="ba-section">
            <p class="ba-section-title">🪪 Identity Verification</p>
            <div class="form-group">
                <label for="id_type">ID Type *</label>
                <select id="id_type" name="id_type" onchange="document.getElementById('id_type_custom_row').style.display=this.value==='other'?'block':'none'">
                    <option value="">— Select ID type —</option>
                    <?php foreach ($idTypes as $val => $lbl): ?>
                        <option value="<?php echo $val; ?>" <?php echo ($_POST['id_type'] ?? '') === $val ? 'selected' : ''; ?>><?php echo sanitize($lbl); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" id="id_type_custom_row" style="display:<?php echo ($_POST['id_type'] ?? '') === 'other' ? 'block' : 'none'; ?>;">
                <label for="id_type_custom">Specify ID Type *</label>
                <input type="text" id="id_type_custom" name="id_type_custom"
                       placeholder="e.g. Voter ID, NHIS Card"
                       value="<?php echo sanitize($_POST['id_type_custom'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label for="id_number">ID Number *</label>
                <input type="text" id="id_number" name="id_number" required
                       placeholder="Enter your ID number"
                       value="<?php echo sanitize($_POST['id_number'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label for="id_document">Upload ID Document Photo *</label>
                <input type="file" id="id_document" name="id_document" accept="image/jpeg,image/png,image/webp">
                <span class="form-hint">Clear photo of the front of your ID. JPEG/PNG/WEBP, max 5MB.</span>
            </div>
        </div>

        <button type="submit" class="button button-primary" style="width:100%;font-size:1rem;padding:14px;">
            Submit Application
        </button>
        <p style="text-align:center;font-size:.8rem;color:var(--text-muted,#6b7280);margin-top:10px;">
            Your information is kept private and only used for verification.
        </p>
    </form>
</main>

<?php require_once __DIR__ . '/partials/bottom_nav.php'; ?>
</body>
</html>
