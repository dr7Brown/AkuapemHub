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

// Already paid/free — nothing to do
if (in_array($profile['service_fee_status'], ['free', 'paid'], true)) {
    flash('Your service listing is already active.', 'info');
    header('Location: worker_profile.php');
    exit;
}

// Backend check: is paid worker service actually required?
if (!is_feature_paid('enable_paid_worker_service')) {
    $pdo->prepare("UPDATE worker_profiles SET service_fee_status = 'free' WHERE user_id = ?")->execute([$user['id']]);
    flash('Your profile is now active in listings.', 'success');
    header('Location: worker_profile.php');
    exit;
}

// Check for existing pending payment
$existingStmt = $pdo->prepare("SELECT id, reference_code FROM platform_payments WHERE user_id = ? AND payment_type = 'worker_service' AND status = 'pending'");
$existingStmt->execute([$user['id']]);
$existingPayment = $existingStmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Hard backend guard
    if (!is_feature_paid('enable_paid_worker_service')) {
        $pdo->prepare("UPDATE worker_profiles SET service_fee_status = 'free' WHERE user_id = ?")->execute([$user['id']]);
        flash('Your profile is now active in listings.', 'success');
        header('Location: worker_profile.php');
        exit;
    }

    if ($existingPayment) {
        flash('You already have a pending payment (ref ' . $existingPayment['reference_code'] . '). Wait for admin confirmation.', 'info');
        header('Location: my_payments.php');
        exit;
    }

    $packageId = intval($_POST['package_id'] ?? 0);
    $pkgStmt = $pdo->prepare("SELECT * FROM worker_service_packages WHERE id = ? AND status = 'active'");
    $pkgStmt->execute([$packageId]);
    $package = $pkgStmt->fetch();

    if (!$package) {
        flash('Please select a valid package.', 'error');
        header('Location: pay_worker_service.php');
        exit;
    }

    $refCode = strtoupper(bin2hex(random_bytes(5)));
    $pdo->prepare('INSERT INTO platform_payments (user_id, payment_type, reference_id, package_id, amount, status, reference_code, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())')
        ->execute([$user['id'], 'worker_service', $profile['id'], $packageId, $package['price'], 'pending', $refCode]);

    notify_admins_and_managers(
        'Worker service listing payment pending',
        display_name($user) . ' submitted payment ref ' . $refCode . ' for ' . $package['name'] . ' service listing (GH₵' . number_format($package['price'], 2) . '). Confirm in Monetization → Pending Payments.',
        'info'
    );

    flash("Payment request submitted (ref: {$refCode}). You'll appear in search results once we confirm your payment of GH₵" . number_format($package['price'], 2) . ".", 'success');
    header('Location: my_payments.php');
    exit;
}

$packages = get_active_packages('worker_service_packages');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Activate Service Listing — AkuapemHub</title>
    <link rel="stylesheet" href="assets/css/style.css" />
</head>
<body class="has-bottom-nav">
    <header class="app-topbar">
        <a href="worker_profile.php" class="button button-secondary button-small">← Back</a>
        <span class="brand">Activate Listing</span>
    </header>
    <main class="page-shell small-shell">
        <?php foreach (get_flashes() as $f): ?>
            <div class="alert alert-<?php echo sanitize($f['type']); ?>"><?php echo sanitize($f['message']); ?></div>
        <?php endforeach; ?>

        <div class="card">
            <h2 style="margin-top:0;">Service listing fee</h2>
            <p>A listing fee is required to appear in AkuapemHub's worker search. Choose a subscription package below.</p>

            <?php if ($existingPayment): ?>
                <div class="alert alert-info">
                    You already have a pending payment (ref <strong><?php echo sanitize($existingPayment['reference_code']); ?></strong>). Waiting for admin confirmation.
                    <br><a href="my_payments.php" style="color:var(--primary);">Track your payment →</a>
                </div>
            <?php elseif (empty($packages)): ?>
                <div class="alert alert-error">No service packages are available right now. Contact support.</div>
            <?php else: ?>
                <form method="post" action="pay_worker_service.php">
                    <?php foreach ($packages as $pkg): ?>
                        <label class="list-row" style="display:flex;align-items:center;gap:12px;padding:14px;border:2px solid var(--border);border-radius:10px;margin-bottom:10px;cursor:pointer;">
                            <input type="radio" name="package_id" value="<?php echo $pkg['id']; ?>" required />
                            <span>
                                <strong><?php echo sanitize($pkg['name']); ?></strong>
                                <span class="meta"> — <?php echo $pkg['duration_days']; ?> days</span><br>
                                <strong style="color:var(--primary);">GH₵ <?php echo number_format($pkg['price'], 2); ?></strong>
                            </span>
                        </label>
                    <?php endforeach; ?>
                    <p class="meta">After submitting, contact us with your reference code to confirm payment. Your profile will go live in search results once confirmed.</p>
                    <button type="submit" class="button button-primary">Submit payment request</button>
                </form>
            <?php endif; ?>
        </div>
    </main>
    <?php $activeNav = 'settings'; require __DIR__ . '/partials/bottom_nav.php'; ?>
</body>
</html>
