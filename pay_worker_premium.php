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

// Already premium and not expired — nothing to do
$today = date('Y-m-d');
if ($profile['subscription_status'] === 'premium'
    && (empty($profile['premium_expiry']) || $profile['premium_expiry'] >= $today)) {
    flash('Your Premium subscription is already active.', 'info');
    header('Location: worker_profile.php');
    exit;
}

// Backend check: is paid premium actually required right now? If not, there's
// nothing to buy — the free toggle on worker_profile.php handles it directly.
if (!is_feature_paid('enable_paid_worker_premium')) {
    flash('Premium is currently free — use the toggle on your profile.', 'info');
    header('Location: worker_profile.php');
    exit;
}

// Check for existing pending payment
$existingStmt = $pdo->prepare("SELECT id, reference_code, COALESCE(gateway,'manual') AS gateway FROM platform_payments WHERE user_id = ? AND payment_type = 'worker_premium' AND status = 'pending'");
$existingStmt->execute([$user['id']]);
$existingPayment = $existingStmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    // Hard backend guard
    if (!is_feature_paid('enable_paid_worker_premium')) {
        flash('Premium is currently free — use the toggle on your profile.', 'info');
        header('Location: worker_profile.php');
        exit;
    }

    if ($existingPayment) {
        $isPaystack = !empty($existingPayment['gateway']) && $existingPayment['gateway'] === 'paystack';
        flash($isPaystack
            ? 'You have an incomplete Paystack payment in progress. Please complete or abandon it first.'
            : 'You already have a pending payment (ref ' . $existingPayment['reference_code'] . '). Wait for admin confirmation.',
            'info'
        );
        header('Location: my_payments.php');
        exit;
    }

    $packageId = intval($_POST['package_id'] ?? 0);
    $pkgStmt = $pdo->prepare("SELECT * FROM worker_premium_packages WHERE id = ? AND status = 'active'");
    $pkgStmt->execute([$packageId]);
    $package = $pkgStmt->fetch();

    if (!$package) {
        flash('Please select a valid package.', 'error');
        header('Location: pay_worker_premium.php');
        exit;
    }

    require_once __DIR__ . '/paystack.php';
    $result = initializePayment(
        $user['id'], $user['email'],
        'worker_premium', (int)$profile['id'], $packageId,
        (float)$package['price']
    );

    if (isset($result['error'])) {
        flash($result['error'], 'error');
        header('Location: pay_worker_premium.php');
        exit;
    }

    header('Location: ' . $result['checkout_url']);
    exit;
}

$packages = get_active_packages('worker_premium_packages');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Upgrade to Premium — AkuapemConnect</title>
    <link rel="stylesheet" href="assets/css/style.css" />
</head>
<body class="has-bottom-nav">
    <header class="app-topbar">
        <a href="worker_profile.php" class="button button-secondary button-small">← Back</a>
        <span class="brand">Upgrade to Premium</span>
    </header>
    <main class="page-shell small-shell">
        <?php foreach (get_flashes() as $f): ?>
            <div class="alert alert-<?php echo sanitize($f['type']); ?>"><?php echo sanitize($f['message']); ?></div>
        <?php endforeach; ?>

        <div class="card">
            <h2 style="margin-top:0;">Worker Premium subscription</h2>
            <p>Premium workers rank higher in search results and job recommendations. Choose a subscription package below.</p>

            <?php if ($existingPayment): ?>
                <div class="alert alert-info">
                    <?php if (!empty($existingPayment['gateway']) && $existingPayment['gateway'] === 'paystack'): ?>
                        🔒 A Paystack payment is in progress for your Premium subscription. Complete or wait for it to resolve.
                    <?php else: ?>
                        You already have a pending payment (ref <strong><?php echo sanitize($existingPayment['reference_code']); ?></strong>). Waiting for admin confirmation.
                    <?php endif; ?>
                    <br><a href="my_payments.php" style="color:var(--primary);">Track your payment →</a>
                </div>
            <?php elseif (empty($packages)): ?>
                <div class="alert alert-error">No Premium packages are available right now. Contact support.</div>
            <?php else: ?>
                <form method="post" action="pay_worker_premium.php">
                    <?php echo csrf_field(); ?>
                    <?php foreach ($packages as $pkg): ?>
                        <label class="list-row" style="display:flex;align-items:center;gap:12px;padding:14px;border:2px solid var(--border);border-radius:10px;margin-bottom:10px;cursor:pointer;">
                            <input type="radio" name="package_id" value="<?php echo $pkg['id']; ?>" required />
                            <span>
                                <strong><?php echo sanitize($pkg['name']); ?></strong>
                                <span class="meta"> — <?php echo $pkg['duration_days']; ?> days Premium</span>
                                <?php if (!empty($pkg['description'])): ?>
                                    <br><span class="meta" style="font-size:0.83rem;"><?php echo sanitize($pkg['description']); ?></span>
                                <?php endif; ?>
                                <br><strong style="color:var(--primary);">GH₵ <?php echo number_format($pkg['price'], 2); ?></strong>
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
</body>
</html>
