<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/delivery_functions.php';

require_module_enabled('delivery', 'Delivery Services');
require_login();
$user         = current_user();
$agentProfile = get_delivery_agent_for_user((int)$user['id']);

if (!$agentProfile || $agentProfile['verification_status'] !== 'approved') {
    flash('You must be an approved delivery agent to apply for verification.', 'warning');
    header('Location: delivery_agent_jobs.php');
    exit;
}

$flash = get_flash();
$error = '';

// Existing verification record
$existingStmt = $pdo->prepare('SELECT * FROM delivery_verifications WHERE agent_id = ?');
$existingStmt->execute([$agentProfile['id']]);
$existing = $existingStmt->fetch() ?: null;

$verificationFee = (float)get_platform_setting('delivery_verification_fee', '0.00');
$feeEnabled      = get_platform_setting('delivery_enable_verification_fee', '0') === '1' && !user_has_complimentary_access();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $method = $_POST['payment_method'] ?? 'free';
    $mobi   = trim($_POST['mobi_number'] ?? '');

    // Validate uploads
    $selfieFile   = $_FILES['selfie']   ?? null;
    $ghanaFile    = $_FILES['ghana_card']   ?? null;
    $licenseFile  = $_FILES['license']  ?? null;
    $vehicleFile  = $_FILES['vehicle_reg']  ?? null;

    if (empty($selfieFile['name'])) $error = 'Selfie photo is required.';
    elseif (!is_valid_image_upload($selfieFile)) $error = 'Selfie must be a JPEG, PNG, or WEBP image under 5MB.';
    elseif ($feeEnabled && $verificationFee > 0 && $method !== 'wallet' && $mobi === '') $error = 'Enter your mobile money number.';

    if (!$error && $ghanaFile && $ghanaFile['name'] && !is_valid_image_upload($ghanaFile)) {
        $error = 'Ghana Card photo must be a valid image file.';
    }

    if (!$error) {
        $selfPath  = save_uploaded_image($selfieFile, 'uploads/delivery_verify/' . $agentProfile['id'] . '/selfie');
        $ghPath    = ($ghanaFile && $ghanaFile['name']) ? save_uploaded_image($ghanaFile, 'uploads/delivery_verify/' . $agentProfile['id'] . '/ghana_card') : null;
        $licPath   = ($licenseFile && $licenseFile['name'] && is_valid_image_upload($licenseFile)) ? save_uploaded_image($licenseFile, 'uploads/delivery_verify/' . $agentProfile['id'] . '/license') : null;
        $vehPath   = ($vehicleFile && $vehicleFile['name'] && is_valid_image_upload($vehicleFile)) ? save_uploaded_image($vehicleFile, 'uploads/delivery_verify/' . $agentProfile['id'] . '/vehicle') : null;

        if ($existing) {
            $pdo->prepare(
                'UPDATE delivery_verifications SET ghana_card_path=?, license_path=?, vehicle_reg_path=?, selfie_path=?, fee_paid=?, status="pending", rejection_reason=NULL, submitted_at=NOW(), reviewed_at=NULL WHERE id=?'
            )->execute([$ghPath, $licPath, $vehPath, $selfPath, ($feeEnabled ? $verificationFee : 0.00), $existing['id']]);
        } else {
            $pdo->prepare(
                'INSERT INTO delivery_verifications (agent_id, ghana_card_path, license_path, vehicle_reg_path, selfie_path, fee_paid, status) VALUES (?,?,?,?,?,?,?)'
            )->execute([$agentProfile['id'], $ghPath, $licPath, $vehPath, $selfPath, ($feeEnabled ? $verificationFee : 0.00), 'pending']);
        }

        // Transaction record if fee applies
        if ($feeEnabled && $verificationFee > 0) {
            $pdo->prepare(
                'INSERT INTO delivery_transactions (agent_id, transaction_type, amount, payment_method, mobi_number, status) VALUES (?,?,?,?,?,?)'
            )->execute([$agentProfile['id'], 'verification', $verificationFee, $method, $mobi ?: null, 'pending']);
        }

        notify_moderators('approve_verifications', 'New Rider Verification Request',
            display_name($user) . ' submitted a Verified Rider Badge application. Review in Admin → Delivery.');

        notify_user((int)$user['id'], 'Verification Submitted', 'Your verification documents have been submitted. An admin will review them and notify you.', 'info');

        flash('Verification documents submitted! You\'ll be notified once reviewed.', 'success');
        header('Location: delivery_agent_jobs.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verified Rider Badge — AkuapemConnect</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .vr-shell { max-width:600px; margin:0 auto; padding:20px 16px 80px; }
        .vr-card { background:var(--surface); border:1px solid var(--border); border-radius:14px; padding:18px; margin-bottom:16px; }
        .vr-title { font-size:.75rem; font-weight:800; text-transform:uppercase; letter-spacing:.07em; color:var(--text-muted,#6b7280); margin:0 0 14px; }
        label { font-weight:600; font-size:.86rem; display:block; margin-bottom:4px; }
        .form-hint { font-size:.74rem; color:var(--text-muted,#6b7280); margin-top:3px; }
        .form-group { margin-bottom:14px; }
    </style>
</head>
<body class="has-bottom-nav">

<header class="app-topbar">
    <a href="delivery_agent_jobs.php" class="button button-secondary button-small">← Back</a>
    <span class="brand">✓ Verified Rider Badge</span>
</header>

<?php if ($flash): ?><div class="alert alert-<?php echo sanitize($flash['type']); ?>" style="margin:10px 16px 0;"><?php echo sanitize($flash['message']); ?></div><?php endif; ?>

<main class="vr-shell">

    <!-- Current status -->
    <?php if ($agentProfile['is_verified']): ?>
    <div style="background:#d1fae5;border:1px solid #6ee7b7;border-radius:12px;padding:14px 16px;margin-bottom:16px;">
        ✅ <strong>You are a Verified Rider!</strong> Your badge is active on your profile.
    </div>
    <?php elseif ($existing): ?>
    <div style="background:<?php echo $existing['status']==='pending'?'#fef3c7':'#fee2e2'; ?>;border:1px solid <?php echo $existing['status']==='pending'?'#f59e0b':'#fca5a5'; ?>;border-radius:12px;padding:14px 16px;margin-bottom:16px;">
        <?php if ($existing['status'] === 'pending'): ?>
        ⏳ <strong>Verification under review.</strong> Admin will notify you once processed.
        <?php elseif ($existing['status'] === 'rejected'): ?>
        ❌ <strong>Verification rejected.</strong>
        <?php if ($existing['rejection_reason']): ?> Reason: <?php echo sanitize($existing['rejection_reason']); ?><?php endif; ?>
        <br><small>You can resubmit corrected documents below.</small>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- What you get -->
    <div class="vr-card">
        <p class="vr-title">Verified Rider Benefits</p>
        <?php foreach (['✓ Verified badge visible on your profile','Trusted status in rider search results','Higher ranking in customer selection','Increased customer confidence','Access to premium delivery categories'] as $b): ?>
        <div style="font-size:.86rem;padding:4px 0;"><?php echo sanitize($b); ?></div>
        <?php endforeach; ?>
        <?php if ($feeEnabled && $verificationFee > 0): ?>
        <div style="margin-top:10px;font-weight:700;color:var(--primary,#0f766e);">One-time fee: GHS <?php echo number_format($verificationFee,2); ?></div>
        <?php else: ?>
        <div style="margin-top:10px;font-size:.82rem;color:var(--text-muted,#6b7280);">Verification is free — admin review only.</div>
        <?php endif; ?>
    </div>

    <?php if ($existing && $existing['status'] === 'pending'): ?>
        <p style="text-align:center;color:var(--text-muted,#6b7280);font-size:.86rem;">Your application is being reviewed. No action needed.</p>
    <?php else: ?>

    <?php if ($error): ?><div class="alert alert-error"><?php echo sanitize($error); ?></div><?php endif; ?>

    <form method="post" action="delivery_verify.php" enctype="multipart/form-data">
        <?php echo csrf_field(); ?>

        <div class="vr-card">
            <p class="vr-title">Upload Documents</p>

            <div class="form-group">
                <label for="selfie">Selfie Photo * <span style="font-weight:400;color:var(--text-muted,#6b7280);">(holding your ID)</span></label>
                <input type="file" id="selfie" name="selfie" accept="image/jpeg,image/png,image/webp" required>
                <p class="form-hint">Clear photo of your face clearly visible. JPEG/PNG/WEBP, max 5MB.</p>
            </div>

            <div class="form-group">
                <label for="ghana_card">Ghana Card</label>
                <input type="file" id="ghana_card" name="ghana_card" accept="image/jpeg,image/png,image/webp">
                <p class="form-hint">Optional but strongly recommended.</p>
            </div>

            <div class="form-group">
                <label for="license">Driver's / Rider's License</label>
                <input type="file" id="license" name="license" accept="image/jpeg,image/png,image/webp">
                <p class="form-hint">Optional — improves trust score.</p>
            </div>

            <div class="form-group">
                <label for="vehicle_reg">Vehicle Registration</label>
                <input type="file" id="vehicle_reg" name="vehicle_reg" accept="image/jpeg,image/png,image/webp">
                <p class="form-hint">Optional.</p>
            </div>
        </div>

        <?php if ($feeEnabled && $verificationFee > 0): ?>
        <div class="vr-card">
            <p class="vr-title">Pay Verification Fee — GHS <?php echo number_format($verificationFee,2); ?></p>
            <?php foreach (['mtn_momo'=>'MTN Mobile Money','telecel'=>'Telecel Cash','airtel'=>'AirtelTigo Money','wallet'=>'Wallet Balance'] as $v=>$l): ?>
            <label style="display:flex;align-items:center;gap:8px;padding:5px 0;cursor:pointer;font-size:.88rem;">
                <input type="radio" name="payment_method" value="<?php echo $v; ?>" <?php echo $v==='mtn_momo'?'checked':''; ?>>
                <?php echo sanitize($l); ?>
            </label>
            <?php endforeach; ?>
            <div style="margin-top:10px;" id="mobi-row">
                <label>Mobile Money Number</label>
                <input type="tel" name="mobi_number" placeholder="e.g. 0244000000" style="margin-top:4px;">
            </div>
        </div>
        <?php else: ?>
        <input type="hidden" name="payment_method" value="free">
        <?php endif; ?>

        <button type="submit" class="button button-primary" style="width:100%;font-size:1rem;padding:14px;">
            Submit Verification Request
        </button>
    </form>
    <?php endif; ?>

</main>

<?php require_once __DIR__ . '/partials/bottom_nav.php'; ?>
</body>
</html>
