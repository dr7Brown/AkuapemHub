<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_module_enabled('jobs', 'Jobs & Services');
require_login();
$user = current_user();

$jobId = intval($_GET['id'] ?? $_POST['job_id'] ?? 0);
if (!$jobId) {
    header('Location: jobs.php');
    exit;
}

$stmt = $pdo->prepare('SELECT sr.*, c.name AS category_name FROM service_requests sr JOIN service_categories c ON sr.category_id = c.id WHERE sr.id = ? AND sr.customer_id = ?');
$stmt->execute([$jobId, $user['id']]);
$job = $stmt->fetch();

if (!$job) {
    flash('Job not found.', 'error');
    header('Location: jobs.php');
    exit;
}

// Job already has posting fee paid — nothing to do
if ($job['posting_fee_status'] === 'paid') {
    flash('Posting fee already confirmed for this job.', 'info');
    header('Location: request_detail.php?id=' . $jobId);
    exit;
}

// Backend check: is paid job posting actually required?
if (!is_feature_paid('enable_paid_job_posting')) {
    // Admin turned off paid posting — update the job and let it through
    $pdo->prepare("UPDATE service_requests SET posting_fee_status = 'free' WHERE id = ?")->execute([$jobId]);
    flash('Job submitted for admin review.', 'success');
    header('Location: jobs.php');
    exit;
}

// Check for existing pending payment to prevent duplicates
$existingStmt = $pdo->prepare("SELECT id, reference_code, COALESCE(gateway,'manual') AS gateway FROM platform_payments WHERE user_id = ? AND payment_type = 'job_post' AND reference_id = ? AND status = 'pending'");
$existingStmt->execute([$user['id'], $jobId]);
$existingPayment = $existingStmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    // Hard backend guard — re-check paid mode
    if (!is_feature_paid('enable_paid_job_posting')) {
        $pdo->prepare("UPDATE service_requests SET posting_fee_status = 'free' WHERE id = ?")->execute([$jobId]);
        flash('Job submitted for admin review.', 'success');
        header('Location: jobs.php');
        exit;
    }

    if ($existingPayment) {
        $isPaystack = !empty($existingPayment['gateway']) && $existingPayment['gateway'] === 'paystack';
        flash($isPaystack
            ? 'You have an incomplete Paystack payment for this job. Please complete or abandon it first.'
            : 'You already have a pending payment (ref ' . $existingPayment['reference_code'] . ') for this job. Wait for admin confirmation.',
            'info'
        );
        header('Location: my_payments.php');
        exit;
    }

    $packageId = intval($_POST['package_id'] ?? 0);
    $pkgStmt = $pdo->prepare("SELECT * FROM job_posting_packages WHERE id = ? AND status = 'active'");
    $pkgStmt->execute([$packageId]);
    $package = $pkgStmt->fetch();

    if (!$package) {
        flash('Please select a valid package.', 'error');
        header('Location: pay_job_post.php?id=' . $jobId);
        exit;
    }

    require_once __DIR__ . '/paystack.php';
    $result = initializePayment(
        $user['id'], $user['email'],
        'job_post', $jobId, $packageId,
        (float)$package['price'],
        ['job_title' => $job['title']]
    );

    if (isset($result['error'])) {
        flash($result['error'], 'error');
        header('Location: pay_job_post.php?id=' . $jobId);
        exit;
    }

    header('Location: ' . $result['checkout_url']);
    exit;
}

$packages = get_active_packages('job_posting_packages');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Pay Posting Fee — AkuapemConnect</title>
    <link rel="stylesheet" href="assets/css/style.css" />
</head>
<body class="has-bottom-nav">
    <header class="app-topbar">
        <a href="jobs.php" class="button button-secondary button-small">← Back</a>
        <span class="brand">Job Posting Fee</span>
    </header>
    <main class="page-shell small-shell">
        <?php foreach (get_flashes() as $f): ?>
            <div class="alert alert-<?php echo sanitize($f['type']); ?>"><?php echo sanitize($f['message']); ?></div>
        <?php endforeach; ?>

        <div class="card">
            <h2 style="margin-top:0;">Posting fee required</h2>
            <p>A posting fee is required to submit this job for review. Choose a package below.</p>

            <div style="padding:12px;background:var(--surface);border-radius:8px;margin-bottom:18px;border:1px solid var(--border);">
                <strong><?php echo sanitize($job['title']); ?></strong>
                <p class="meta" style="margin:4px 0 0;"><?php echo sanitize($job['category_name']); ?> · <?php echo sanitize($job['location']); ?></p>
            </div>

            <?php if ($existingPayment): ?>
                <div class="alert alert-info">
                    <?php if (!empty($existingPayment['gateway']) && $existingPayment['gateway'] === 'paystack'): ?>
                        🔒 A Paystack payment is in progress for this job's posting fee. Complete or wait for it to resolve.
                    <?php else: ?>
                        You already have a pending payment (ref <strong><?php echo sanitize($existingPayment['reference_code']); ?></strong>) for this job. Waiting for admin confirmation.
                    <?php endif; ?>
                    <br><a href="my_payments.php" style="color:var(--primary);">Track your payment →</a>
                </div>
            <?php elseif (empty($packages)): ?>
                <div class="alert alert-error">No posting packages available right now. Contact support.</div>
            <?php else: ?>
                <form method="post" action="pay_job_post.php">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="job_id" value="<?php echo $jobId; ?>" />
                    <?php foreach ($packages as $pkg): ?>
                        <label class="list-row" style="display:flex;align-items:center;gap:12px;padding:14px;border:2px solid var(--border);border-radius:10px;margin-bottom:10px;cursor:pointer;">
                            <input type="radio" name="package_id" value="<?php echo $pkg['id']; ?>" required />
                            <span>
                                <strong><?php echo sanitize($pkg['name']); ?></strong>
                                <?php if ($pkg['post_count'] == -1): ?>
                                    <span class="meta"> — unlimited posts</span>
                                <?php else: ?>
                                    <span class="meta"> — <?php echo $pkg['post_count']; ?> post<?php echo $pkg['post_count'] > 1 ? 's' : ''; ?></span>
                                <?php endif; ?>
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
    <?php $activeNav = 'home'; require __DIR__ . '/partials/bottom_nav.php'; ?>
</body>
</html>
