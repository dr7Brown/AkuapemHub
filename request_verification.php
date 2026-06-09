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
    header('Location: dashboard.php');
    exit;
}

if ($profile['is_verified']) {
    flash('Your profile is already verified.', 'info');
    header('Location: worker_profile.php');
    exit;
}

$isPaid = is_feature_paid('enable_paid_verification_badges');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isPaid) {
        // Free verification path — not normally reachable from UI but handle defensively
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

    // Check for existing pending payment
    $existingStmt = $pdo->prepare("SELECT id FROM platform_payments WHERE user_id = ? AND payment_type = 'verification' AND status = 'pending'");
    $existingStmt->execute([$user['id']]);
    if ($existingStmt->fetch()) {
        flash('You already have a pending verification payment. Please wait for admin confirmation.', 'info');
        header('Location: worker_profile.php');
        exit;
    }

    $refCode = strtoupper(bin2hex(random_bytes(5)));
    $pdo->prepare('INSERT INTO platform_payments (user_id, payment_type, reference_id, package_id, amount, status, reference_code, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())')
        ->execute([$user['id'], 'verification', $profile['id'], $packageId, $package['price'], 'pending', $refCode]);

    notify_admins_and_managers(
        'Verification badge payment pending',
        display_name($user) . ' submitted payment ref ' . $refCode . ' for ' . $package['name'] . ' (GH₵' . number_format($package['price'], 2) . '). Confirm in Monetization → Verification.',
        'info'
    );
    log_audit_action($user['id'], 'verification_requested', "Verification badge requested by user ID {$user['id']} — setting: paid — package: {$package['name']} GH₵{$package['price']} — decision: pending payment ref {$refCode}");

    flash("Verification request submitted. Reference: {$refCode}. Once our team confirms your payment of GH₵" . number_format($package['price'], 2) . ", your profile will be verified.", 'success');
    header('Location: worker_profile.php');
    exit;
}

$packages = get_active_packages('verification_packages');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Request Verification — AkuapemHub</title>
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
            <h2 style="margin-top: 0;">Get your Verified Worker Badge ✓</h2>
            <p>Verified workers earn more trust from customers. Your profile will display a verification checkmark on search results and your public profile.</p>
            <?php if (!$isPaid): ?>
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
                    <p class="meta" style="margin-top: 8px;">After submitting, contact us with your payment reference to confirm. We'll activate your badge once confirmed.</p>
                    <button type="submit" class="button button-primary">Submit verification request</button>
                </form>
            <?php endif; ?>
        </div>
    </main>
    <?php $activeNav = 'settings'; require __DIR__ . '/partials/bottom_nav.php'; ?>
</body>
</html>
