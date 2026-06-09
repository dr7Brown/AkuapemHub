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

$isPaid = is_feature_paid('enable_paid_featured_workers');

// Check for an existing pending featuring payment for this worker
$existingFeatStmt = $pdo->prepare("SELECT id, reference_code FROM platform_payments WHERE user_id = ? AND payment_type = 'featured_worker' AND status = 'pending'");
$existingFeatStmt->execute([$user['id']]);
$existingFeatPayment = $existingFeatStmt->fetch();

$wFeatEndDate  = $profile['featured_end_date'] ?? null;
$wFeatActive   = !empty($profile['is_featured']) && (empty($wFeatEndDate) || $wFeatEndDate >= date('Y-m-d'));
$wFeatRenewSoon = !empty($profile['is_featured']) && !empty($wFeatEndDate) && $wFeatEndDate < date('Y-m-d', strtotime('+7 days'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $packageId = intval($_POST['package_id'] ?? 0);

    if ($existingFeatPayment) {
        flash('You already have a pending featuring payment (ref ' . $existingFeatPayment['reference_code'] . '). Wait for admin confirmation.', 'info');
        header('Location: my_payments.php');
        exit;
    }

    if (!$isPaid) {
        $pdo->prepare('UPDATE worker_profiles SET is_featured = 1, featured_start_date = CURDATE(), featured_end_date = DATE_ADD(CURDATE(), INTERVAL 30 DAY) WHERE user_id = ?')
            ->execute([$user['id']]);
        log_audit_action($user['id'], 'feature_worker_requested', "Feature worker requested for user ID {$user['id']} — setting: free — decision: featured immediately (free mode)");
        flash('Your profile is now featured for 30 days. You\'ll appear at the top of search results.', 'success');
        header('Location: worker_profile.php');
        exit;
    }

    $pkgStmt = $pdo->prepare("SELECT * FROM worker_promotion_packages WHERE id = ? AND status = 'active'");
    $pkgStmt->execute([$packageId]);
    $package = $pkgStmt->fetch();

    if (!$package) {
        flash('Please select a valid package.', 'error');
        header('Location: feature_worker.php');
        exit;
    }

    $refCode = strtoupper(bin2hex(random_bytes(5)));
    $pdo->prepare('INSERT INTO platform_payments (user_id, payment_type, reference_id, package_id, amount, status, reference_code, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())')
        ->execute([$user['id'], 'featured_worker', $profile['id'], $packageId, $package['price'], 'pending', $refCode]);

    notify_admins_and_managers(
        'Featured worker payment pending',
        display_name($user) . ' submitted payment ref ' . $refCode . ' for profile promotion (' . $package['name'] . ', GH₵' . number_format($package['price'], 2) . '). Confirm in Monetization → Pending Payments.',
        'info'
    );
    log_audit_action($user['id'], 'feature_worker_requested', "Feature worker requested for user ID {$user['id']} — setting: paid — package: {$package['name']} GH₵{$package['price']} — decision: pending payment ref {$refCode}");

    flash("Payment request submitted. Reference: {$refCode}. Once our team confirms your payment of GH₵" . number_format($package['price'], 2) . ", your profile will be featured.", 'success');
    header('Location: worker_profile.php');
    exit;
}

$packages = get_active_packages('worker_promotion_packages');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Feature My Profile — AkuapemHub</title>
    <link rel="stylesheet" href="assets/css/style.css" />
</head>
<body class="has-bottom-nav">
    <header class="app-topbar">
        <a href="worker_profile.php" class="button button-secondary button-small">Back</a>
        <span class="brand"><?php echo ($wFeatActive && !$wFeatRenewSoon) ? 'Featured profile' : 'Feature my profile'; ?></span>
    </header>
    <main class="page-shell small-shell">
        <div class="card">
            <?php if ($wFeatActive && !$wFeatRenewSoon): ?>
                <div class="alert alert-success">
                    ⭐ Your profile is featured<?php echo $wFeatEndDate ? ' until <strong>' . sanitize($wFeatEndDate) . '</strong>' : ' (no expiry)'; ?>.
                </div>
            <?php elseif ($existingFeatPayment): ?>
                <div class="alert alert-info">
                    You have a pending featuring payment (ref <strong><?php echo sanitize($existingFeatPayment['reference_code']); ?></strong>). Your profile will be featured once we confirm your payment.
                    <br><a href="my_payments.php" style="color:var(--primary);">Track payment →</a>
                </div>
            <?php else: ?>
                <?php if ($wFeatRenewSoon): ?>
                    <div class="alert alert-warning" style="margin-bottom:14px;">
                        ⚠️ Your featuring expires on <strong><?php echo sanitize($wFeatEndDate); ?></strong>. Renew now to keep appearing at the top of search results.
                    </div>
                <?php endif; ?>
                <?php if (!$isPaid): ?>
                    <h2 style="margin-top:0;"><?php echo $wFeatRenewSoon ? 'Renew your feature' : 'Feature your profile for free'; ?></h2>
                    <p>Get featured for <strong>30 days</strong> at no cost. Featured workers appear at the top of search results and the Find Workers page.</p>
                    <form method="post" action="feature_worker.php">
                        <button type="submit" class="button button-primary"><?php echo $wFeatRenewSoon ? 'Renew for free' : 'Feature my profile'; ?></button>
                    </form>
                <?php else: ?>
                    <h2 style="margin-top:0;"><?php echo $wFeatRenewSoon ? 'Renew your promotion' : 'Choose a promotion package'; ?></h2>
                    <p>Featured profiles appear at the top of search results and the Find Workers page, getting more job requests.</p>
                    <?php if (empty($packages)): ?>
                        <div class="alert alert-error">No promotion packages are available right now. Check back later.</div>
                    <?php else: ?>
                        <form method="post" action="feature_worker.php">
                            <?php foreach ($packages as $pkg): ?>
                                <label class="list-row" style="display:flex;align-items:center;gap:12px;padding:14px;border:2px solid var(--border);border-radius:10px;margin-bottom:10px;cursor:pointer;">
                                    <input type="radio" name="package_id" value="<?php echo $pkg['id']; ?>" required />
                                    <span>
                                        <strong><?php echo sanitize($pkg['name']); ?></strong>
                                        <span class="meta"> — <?php echo $pkg['duration_days']; ?> days featured</span><br>
                                        <strong style="color:var(--primary);">GH₵ <?php echo number_format($pkg['price'], 2); ?></strong>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                            <p class="meta" style="margin-top: 8px;">After submitting, contact us to confirm your payment. We'll activate your feature once confirmed.</p>
                            <button type="submit" class="button button-primary"><?php echo $wFeatRenewSoon ? 'Submit renewal request' : 'Submit payment request'; ?></button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </main>
    <?php $activeNav = 'settings'; require __DIR__ . '/partials/bottom_nav.php'; ?>
</body>
</html>
