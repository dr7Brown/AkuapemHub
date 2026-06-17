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

$isPaid = is_feature_paid('enable_paid_featured_workers');

// Check for an existing pending featuring payment for this worker
$existingFeatStmt = $pdo->prepare("SELECT id, reference_code, COALESCE(gateway,'manual') AS gateway FROM platform_payments WHERE user_id = ? AND payment_type = 'featured_worker' AND status = 'pending'");
$existingFeatStmt->execute([$user['id']]);
$existingFeatPayment = $existingFeatStmt->fetch();

$wFeatEndDate  = $profile['featured_end_date'] ?? null;
$wFeatActive   = !empty($profile['is_featured']) && (empty($wFeatEndDate) || $wFeatEndDate >= date('Y-m-d'));
$wFeatRenewSoon = !empty($profile['is_featured']) && !empty($wFeatEndDate) && $wFeatEndDate < date('Y-m-d', strtotime('+7 days'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $packageId = intval($_POST['package_id'] ?? 0);

    if ($existingFeatPayment) {
        $isPaystack = !empty($existingFeatPayment['gateway']) && $existingFeatPayment['gateway'] === 'paystack';
        flash($isPaystack
            ? 'You have an incomplete Paystack payment in progress. Please complete or abandon it first.'
            : 'You already have a pending featuring payment (ref ' . $existingFeatPayment['reference_code'] . '). Wait for admin confirmation.',
            'info'
        );
        header('Location: my_payments.php');
        exit;
    }

    if (!$isPaid) {
        $pdo->prepare('UPDATE worker_profiles SET is_featured = 1, featured_start_date = CURDATE(), featured_end_date = DATE_ADD(CURDATE(), INTERVAL 30 DAY) WHERE user_id = ?')
            ->execute([$user['id']]);
        log_audit_action($user['id'], 'feature_worker_requested', "Feature worker requested for user ID {$user['id']} — free mode, featured immediately");
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

    require_once __DIR__ . '/paystack.php';
    $result = initializePayment(
        $user['id'], $user['email'],
        'featured_worker', (int)$profile['id'], $packageId,
        (float)$package['price']
    );

    if (isset($result['error'])) {
        flash($result['error'], 'error');
        header('Location: feature_worker.php');
        exit;
    }

    log_audit_action($user['id'], 'feature_worker_requested', "Paystack checkout initiated for featuring worker user ID {$user['id']} — ref {$result['reference']} — {$package['name']} GH₵{$package['price']}");
    header('Location: ' . $result['checkout_url']);
    exit;
}

$packages = get_active_packages('worker_promotion_packages');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Feature My Profile — AkuapemConnect</title>
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
                        <?php echo csrf_field(); ?>
                        <button type="submit" class="button button-primary"><?php echo $wFeatRenewSoon ? 'Renew for free' : 'Feature my profile'; ?></button>
                    </form>
                <?php else: ?>
                    <h2 style="margin-top:0;"><?php echo $wFeatRenewSoon ? 'Renew your promotion' : 'Choose a promotion package'; ?></h2>
                    <p>Featured profiles appear at the top of search results and the Find Workers page, getting more job requests.</p>
                    <?php if (empty($packages)): ?>
                        <div class="alert alert-error">No promotion packages are available right now. Check back later.</div>
                    <?php else: ?>
                        <form method="post" action="feature_worker.php">
                            <?php echo csrf_field(); ?>
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
                            <button type="submit" class="button button-primary" style="margin-top:8px;">
                                🔒 Pay with Paystack — <?php echo $wFeatRenewSoon ? 'Renew' : 'Feature'; ?> my profile
                            </button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </main>
    <?php $activeNav = 'settings'; require __DIR__ . '/partials/bottom_nav.php'; ?>
</body>
</html>
