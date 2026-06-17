<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_login();
$user = current_user();
require_role('worker');

$stmt = $pdo->prepare('SELECT * FROM worker_profiles WHERE user_id = ?');
$stmt->execute([$user['id']]);
$profile = $stmt->fetch();

if (!$profile) {
    flash('Worker profile not found.', 'error');
    header('Location: jobs.php');
    exit;
}

$vStatus  = $profile['verification_status'] ?? ($profile['is_verified'] ? 'approved' : 'none');
$vReason  = $profile['verification_rejection_reason'] ?? null;

if ($vStatus === 'approved') {
    flash('Your profile is already verified.', 'info');
    header('Location: worker_profile.php');
    exit;
}

if ($vStatus === 'pending') {
    flash('Your verification request is already pending admin review.', 'info');
    header('Location: worker_profile.php');
    exit;
}

$isPaid = is_feature_paid('enable_paid_verification_badges');

// Check if there's an existing paid verification payment (used for free resubmission)
$paidVerifStmt = $pdo->prepare("SELECT id FROM platform_payments WHERE user_id = ? AND payment_type = 'verification' AND status = 'paid' LIMIT 1");
$paidVerifStmt->execute([$user['id']]);
$hasPaidVerif = (bool)$paidVerifStmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Resubmission when admin requested updated docs — free if already paid once
    if ($vStatus === 'resubmission_requested' && $hasPaidVerif) {
        $idType       = $_POST['id_type'] ?? '';
        $idNumber     = trim($_POST['id_number'] ?? '');
        $idTypeCustom = trim($_POST['id_type_custom'] ?? '');
        if (!in_array($idType, ['ghana_card', 'passport', 'other'], true) || $idNumber === '') {
            flash('Select an ID type and enter your ID number.', 'error');
            header('Location: request_verification.php');
            exit;
        }
        if ($idType === 'other' && $idTypeCustom === '') {
            flash('Please specify the type of ID document you are using.', 'error');
            header('Location: request_verification.php');
            exit;
        }
        if ($idType === 'ghana_card' && !validate_ghana_card($idNumber)) {
            flash('Ghana Card number must be in the format GHA-123456789-0.', 'error');
            header('Location: request_verification.php');
            exit;
        }
        if ($idType === 'ghana_card' && is_ghana_card_duplicate($idNumber, $user['id'])) {
            flash('This Ghana Card number is already registered to another account.', 'error');
            header('Location: request_verification.php');
            exit;
        }
        $newDocPath = null;
        if (!empty($_FILES['id_document']['name'])) {
            if (!is_valid_image_upload($_FILES['id_document'])) {
                flash('ID photo must be a JPEG, PNG, or WEBP image under 5MB.', 'error');
                header('Location: request_verification.php');
                exit;
            }
            $newDocPath = save_uploaded_image($_FILES['id_document'], 'uploads/worker_ids/' . $user['id']);
        }
        $customVal = ($idType === 'other') ? $idTypeCustom : null;
        if ($newDocPath) {
            $pdo->prepare("UPDATE worker_profiles SET id_type = ?, id_type_custom = ?, id_number = ?, id_document_path = ?, verification_status = 'pending', verification_rejection_reason = NULL WHERE user_id = ?")
                ->execute([$idType, $customVal, $idNumber, $newDocPath, $user['id']]);
        } else {
            $pdo->prepare("UPDATE worker_profiles SET id_type = ?, id_type_custom = ?, id_number = ?, verification_status = 'pending', verification_rejection_reason = NULL WHERE user_id = ?")
                ->execute([$idType, $customVal, $idNumber, $user['id']]);
        }
        log_audit_action($user['id'], 'verification_resubmitted', "Worker user ID {$user['id']} resubmitted verification documents");
        flash('Your updated verification request has been submitted. An admin will review your documents shortly.', 'success');
        header('Location: worker_profile.php');
        exit;
    }

    if (!$isPaid) {
        flash('Verification is granted by an admin. Please contact support.', 'info');
        header('Location: worker_profile.php');
        exit;
    }

    $packageId = intval($_POST['package_id'] ?? 0);
    $pkgStmt = $pdo->prepare("SELECT * FROM verification_packages WHERE id = ? AND status = 'active'");
    $pkgStmt->execute([$packageId]);
    $package = $pkgStmt->fetch();

    if (!$package) {
        flash('Please select a valid verification package.', 'error');
        header('Location: request_verification.php');
        exit;
    }

    // Block if already pending (any gateway)
    $existingStmt = $pdo->prepare("SELECT id, gateway FROM platform_payments WHERE user_id = ? AND payment_type = 'verification' AND status = 'pending'");
    $existingStmt->execute([$user['id']]);
    $existingPay = $existingStmt->fetch();
    if ($existingPay) {
        $isPs = !empty($existingPay['gateway']) && $existingPay['gateway'] === 'paystack';
        flash($isPs
            ? 'You have an incomplete Paystack verification payment in progress.'
            : 'You already have a pending verification payment. Please wait for admin confirmation.',
            'info'
        );
        header('Location: worker_profile.php');
        exit;
    }

    require_once __DIR__ . '/paystack.php';
    $result = initializePayment(
        $user['id'], $user['email'],
        'verification', (int)$profile['id'], $packageId,
        (float)$package['price']
    );

    if (isset($result['error'])) {
        flash($result['error'], 'error');
        header('Location: request_verification.php');
        exit;
    }

    log_audit_action($user['id'], 'verification_requested', "Paystack checkout initiated for verification — user ID {$user['id']} — ref {$result['reference']} — {$package['name']} GH₵{$package['price']}");
    header('Location: ' . $result['checkout_url']);
    exit;
}

$packages = get_active_packages('verification_packages');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Request Verification — AkuapemConnect</title>
    <link rel="stylesheet" href="assets/css/style.css" />
</head>
<body class="has-bottom-nav">
    <header class="app-topbar">
        <a href="worker_profile.php" class="button button-secondary button-small">Back</a>
        <span class="brand">Request Verification</span>
    </header>
    <main class="page-shell small-shell">
        <?php foreach (get_flashes() as $f): ?>
            <div class="alert alert-<?php echo sanitize($f['type']); ?>"><?php echo sanitize($f['message']); ?></div>
        <?php endforeach; ?>
        <div class="card">
            <h2 style="margin-top: 0;">Get your ✓erified Worker Badge</h2>
            <p>Verified workers earn more trust from customers. Your profile will display a verification checkmark on search results and your public profile.</p>
            <?php if ($vStatus === 'rejected'): ?>
                <div class="alert alert-error" style="margin-bottom:14px;">
                    <strong>Your previous verification request was rejected.</strong>
                    <?php if ($vReason): ?><br>Reason: <?php echo sanitize($vReason); ?><?php endif; ?>
                    <br><span class="meta">You can submit a new request below.</span>
                </div>
            <?php elseif ($vStatus === 'resubmission_requested'): ?>
                <div class="alert alert-warning" style="margin-bottom:14px;">
                    <strong>Admin has requested updated documents.</strong>
                    <?php if ($vReason): ?><br>Notes: <?php echo sanitize($vReason); ?><?php endif; ?>
                    <br><span class="meta">Please resubmit your verification request below.</span>
                </div>
            <?php elseif ($vStatus === 'expired'): ?>
                <div class="alert alert-warning" style="margin-bottom:14px;">
                    Your verification badge has expired. Renew it below to restore your ✓erified status.
                </div>
            <?php endif; ?>
            <?php if ($vStatus === 'resubmission_requested' && $hasPaidVerif): ?>
                <form method="post" action="request_verification.php" enctype="multipart/form-data" id="resubmit-form">
                    <?php echo csrf_field(); ?>
                    <p style="margin-top:0;">Update your ID details below, then resubmit. No additional payment required.</p>
                    <label>ID type</label>
                    <select name="id_type" id="rv-id-type" required style="margin-bottom:10px;">
                        <option value="">Select ID type</option>
                        <option value="ghana_card" <?php echo ($profile['id_type'] === 'ghana_card') ? 'selected' : ''; ?>>Ghana Card</option>
                        <option value="passport" <?php echo ($profile['id_type'] === 'passport') ? 'selected' : ''; ?>>Passport</option>
                        <option value="other" <?php echo ($profile['id_type'] === 'other') ? 'selected' : ''; ?>>Other (specify)</option>
                    </select>
                    <div id="rv-custom-wrap" style="display:<?php echo ($profile['id_type'] === 'other') ? 'block' : 'none'; ?>;margin-bottom:10px;">
                        <label>Specify ID type</label>
                        <input type="text" name="id_type_custom" id="rv-id-type-custom" placeholder="e.g. National ID, Driver's Licence, NHIS" maxlength="100" value="<?php echo sanitize($profile['id_type_custom'] ?? ''); ?>" />
                    </div>
                    <label>ID card number</label>
                    <input type="text" name="id_number" id="rv-id-number" value="<?php echo sanitize($profile['id_number'] ?? ''); ?>" required placeholder="ID card number" autocomplete="off" style="margin-bottom:4px;" />
                    <small id="rv-id-number_hint" style="display:block;margin-bottom:10px;font-size:0.82rem;"></small>
                    <label>New ID photo <span class="meta">(leave blank to keep current)</span></label>
                    <?php if (!empty($profile['id_document_path'])): ?>
                        <div style="margin-bottom:8px;">
                            <img src="<?php echo sanitize($profile['id_document_path']); ?>" alt="Current ID" style="height:72px;width:auto;border-radius:6px;border:1px solid var(--border);object-fit:cover;" />
                            <span class="meta" style="margin-left:8px;">Current document</span>
                        </div>
                    <?php endif; ?>
                    <input type="file" name="id_document" accept="image/jpeg,image/png,image/webp" style="margin-bottom:14px;" />
                    <p class="small-note" style="margin-top:-8px;">JPEG/PNG/WEBP, max 5 MB.</p>
                    <button type="submit" class="button button-primary">Resubmit for review</button>
                </form>
            <?php elseif (!$isPaid): ?>
                <div class="alert alert-info">Verification is granted by an admin. There is no payment required at this time. If you believe you qualify, please contact our team.</div>
            <?php elseif (empty($packages)): ?>
                <div class="alert alert-error">No verification packages are available right now. Check back later.</div>
            <?php else: ?>
                <form method="post" action="request_verification.php">
                    <?php foreach ($packages as $pkg): ?>
                        <label class="list-row" style="display:flex;align-items:center;gap:12px;padding:14px;border:2px solid var(--border);border-radius:10px;margin-bottom:10px;cursor:pointer;">
                            <input type="radio" name="package_id" value="<?php echo $pkg['id']; ?>" required />
                            <span>
                                <strong><?php echo sanitize($pkg['name']); ?></strong><br>
                                <strong style="color:var(--primary);">GH₵ <?php echo number_format($pkg['price'], 2); ?></strong>
                            </span>
                        </label>
                    <?php endforeach; ?>
                    <button type="submit" class="button button-primary" style="margin-top:8px;">
                        🔒 Pay with Paystack
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </main>
    <?php $activeNav = 'settings'; require __DIR__ . '/partials/bottom_nav.php'; ?>
    <script src="assets/js/ghana-card-input.js"></script>
    <script>
        (function () {
            var typeEl   = document.getElementById('rv-id-type');
            var numEl    = document.getElementById('rv-id-number');
            var custWrap = document.getElementById('rv-custom-wrap');
            var custEl   = document.getElementById('rv-id-type-custom');
            if (!typeEl || !numEl) return;
            var ctrl = initGhanaCardInput(numEl);
            function sync() {
                var v = typeEl.value;
                custWrap.style.display = v === 'other' ? 'block' : 'none';
                if (custEl) custEl.required = (v === 'other');
                if (v === 'ghana_card') {
                    ctrl.activate();
                } else {
                    ctrl.deactivate();
                    numEl.placeholder = v === 'passport' ? 'e.g. G0000000' : 'ID card number';
                }
            }
            typeEl.addEventListener('change', sync);
            sync();
        })();
    </script>
</body>
</html>
