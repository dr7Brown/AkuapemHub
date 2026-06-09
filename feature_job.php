<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_login();
$user = current_user();

$requestId = intval($_GET['id'] ?? $_POST['request_id'] ?? 0);
if (!$requestId) {
    header('Location: dashboard.php');
    exit;
}

$stmt = $pdo->prepare('SELECT sr.*, c.name AS category_name FROM service_requests sr JOIN service_categories c ON sr.category_id = c.id WHERE sr.id = ?');
$stmt->execute([$requestId]);
$request = $stmt->fetch();

if (!$request || $request['customer_id'] != $user['id']) {
    flash('You can only feature your own jobs.', 'error');
    header('Location: dashboard.php');
    exit;
}

$isPaid = is_feature_paid('enable_paid_featured_jobs');

// Check for an existing pending featuring payment for this job
$existingFeatStmt = $pdo->prepare("SELECT id, reference_code FROM platform_payments WHERE user_id = ? AND payment_type = 'featured_job' AND reference_id = ? AND status = 'pending'");
$existingFeatStmt->execute([$user['id'], $requestId]);
$existingFeatPayment = $existingFeatStmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $packageId = intval($_POST['package_id'] ?? 0);

    if ($existingFeatPayment) {
        flash('You already have a pending featuring payment (ref ' . $existingFeatPayment['reference_code'] . '). Wait for admin confirmation.', 'info');
        header('Location: my_payments.php');
        exit;
    }

    if (!$isPaid) {
        // Free featuring — activate immediately
        $pdo->prepare('UPDATE service_requests SET featured = 1, featured_start_date = CURDATE(), featured_end_date = DATE_ADD(CURDATE(), INTERVAL 30 DAY) WHERE id = ?')
            ->execute([$requestId]);
        log_audit_action($user['id'], 'feature_job_requested', "Feature job requested for job ID {$requestId} '{$request['title']}' — setting: free — decision: featured immediately (free mode)");
        flash('Your job is now featured for 30 days.', 'success');
        header('Location: request_detail.php?id=' . $requestId);
        exit;
    }

    // Paid featuring — validate package and create pending payment
    $pkgStmt = $pdo->prepare("SELECT * FROM featured_job_packages WHERE id = ? AND status = 'active'");
    $pkgStmt->execute([$packageId]);
    $package = $pkgStmt->fetch();

    if (!$package) {
        flash('Please select a valid package.', 'error');
        header('Location: feature_job.php?id=' . $requestId);
        exit;
    }

    $refCode = strtoupper(bin2hex(random_bytes(5)));
    $pdo->prepare('INSERT INTO platform_payments (user_id, payment_type, reference_id, package_id, amount, status, reference_code, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())')
        ->execute([$user['id'], 'featured_job', $requestId, $packageId, $package['price'], 'pending', $refCode]);

    notify_admins_and_managers(
        'Featured job payment pending',
        display_name($user) . ' submitted payment ref ' . $refCode . ' for "' . $request['title'] . '" (' . $package['name'] . ', GH₵' . number_format($package['price'], 2) . '). Confirm in Monetization → Pending Payments.',
        'info'
    );
    log_audit_action($user['id'], 'feature_job_requested', "Feature job requested for job ID {$requestId} '{$request['title']}' — setting: paid — package: {$package['name']} GH₵{$package['price']} — decision: pending payment ref {$refCode}");

    flash("Payment request submitted. Reference: {$refCode}. Once our team confirms your payment of GH₵" . number_format($package['price'], 2) . ", your job will be featured.", 'success');
    header('Location: request_detail.php?id=' . $requestId);
    exit;
}

$packages = get_active_packages('featured_job_packages');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Feature Job — AkuapemHub</title>
    <link rel="stylesheet" href="assets/css/style.css" />
</head>
<body class="has-bottom-nav">
    <header class="app-topbar">
        <a href="request_detail.php?id=<?php echo $requestId; ?>" class="button button-secondary button-small">Back</a>
        <span class="brand">Feature this job</span>
    </header>
    <main class="page-shell small-shell">
        <div class="card">
            <h2 style="margin-top: 0;"><?php echo sanitize($request['title']); ?></h2>
            <p class="meta"><?php echo sanitize($request['category_name']); ?> • <?php echo sanitize($request['location']); ?></p>
            <?php if ($request['featured']): ?>
                <div class="alert alert-success">This job is already featured<?php echo $request['featured_end_date'] ? ' until ' . sanitize($request['featured_end_date']) : ''; ?>.</div>
            <?php elseif ($existingFeatPayment): ?>
                <div class="alert alert-info">
                    You have a pending featuring payment (ref <strong><?php echo sanitize($existingFeatPayment['reference_code']); ?></strong>). Your job will be featured once we confirm your payment.
                    <br><a href="my_payments.php" style="color:var(--primary);">Track payment →</a>
                </div>
            <?php else: ?>
                <?php if (!$isPaid): ?>
                    <p>Feature this job for <strong>free</strong> for 30 days. Featured jobs appear at the top of all listings and get more applications.</p>
                    <form method="post" action="feature_job.php">
                        <input type="hidden" name="request_id" value="<?php echo $requestId; ?>" />
                        <button type="submit" class="button button-primary">Feature for free</button>
                    </form>
                <?php else: ?>
                    <p>Choose a package to feature this job. Featured jobs appear at the top of all listings and get more applications.</p>
                    <?php if (empty($packages)): ?>
                        <div class="alert alert-error">No featuring packages are currently available. Check back later.</div>
                    <?php else: ?>
                        <form method="post" action="feature_job.php">
                            <input type="hidden" name="request_id" value="<?php echo $requestId; ?>" />
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
                            <p class="meta" style="margin-top: 8px;">After submitting, contact us to confirm your payment. We'll activate your feature immediately once confirmed.</p>
                            <button type="submit" class="button button-primary">Submit payment request</button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </main>
    <?php $activeNav = 'home'; require __DIR__ . '/partials/bottom_nav.php'; ?>
</body>
</html>
