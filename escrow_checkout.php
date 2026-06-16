<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/paystack.php';

require_login();
$user = current_user();

$jobId = intval($_GET['id'] ?? 0);
if ($jobId <= 0) {
    header('Location: jobs.php');
    exit;
}

$stmt = $pdo->prepare('SELECT sr.*, sc.name AS category_name FROM service_requests sr JOIN service_categories sc ON sr.category_id = sc.id WHERE sr.id = ? AND sr.customer_id = ? AND sr.payment_mode = ?');
$stmt->execute([$jobId, $user['id'], 'escrow']);
$job = $stmt->fetch();

if (!$job) {
    flash('Job not found or not eligible for escrow checkout.', 'error');
    header('Location: jobs.php');
    exit;
}

// Already paid — job moved to pending/open
if ($job['status'] !== 'pending_payment') {
    flash('This job has already been submitted.', 'info');
    header('Location: request_detail.php?id=' . $jobId);
    exit;
}

$escrow = $pdo->prepare('SELECT * FROM escrow_payments WHERE job_id = ? AND client_id = ?');
$escrow->execute([$jobId, $user['id']]);
$escrow = $escrow->fetch();

if (!$escrow) {
    flash('Escrow record not found. Please contact support.', 'error');
    header('Location: jobs.php');
    exit;
}

if ($escrow['status'] === 'held') {
    // Payment confirmed via webhook before user hit callback
    flash('Payment already confirmed. Job is pending admin review.', 'info');
    header('Location: request_detail.php?id=' . $jobId);
    exit;
}

// Determine whether posting fee should be bundled into this payment
$needsPostingFee  = is_feature_paid('enable_paid_job_posting') && ($job['posting_fee_status'] ?? 'pending') === 'pending';
$postingPackages  = $needsPostingFee ? get_active_packages('job_posting_packages') : [];
$bundlePostingFee = $needsPostingFee && !empty($postingPackages);

$paystackError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if (!paystack_configured()) {
        $paystackError = 'Payment gateway is not configured. Please contact support.';
    } else {
        $paymentType = 'escrow_payment';
        $packageId   = 0;
        $totalAmount = (float)$escrow['gross_amount'];

        if ($bundlePostingFee) {
            $packageId = intval($_POST['posting_package_id'] ?? 0);
            $pkgStmt   = $pdo->prepare("SELECT * FROM job_posting_packages WHERE id = ? AND status = 'active'");
            $pkgStmt->execute([$packageId]);
            $postingPackage = $pkgStmt->fetch();
            if (!$postingPackage) {
                $paystackError = 'Please select a valid job posting package.';
            } else {
                $paymentType = 'escrow_with_posting';
                $totalAmount = (float)$escrow['gross_amount'] + (float)$postingPackage['price'];
            }
        }

        if (!$paystackError) {
            $result = initializePayment(
                $user['id'], $user['email'],
                $paymentType, $jobId, $packageId, $totalAmount,
                ['job_title' => $job['title'], 'escrow_id' => $escrow['id']]
            );

            if (isset($result['error'])) {
                $paystackError = $result['error'];
            } else {
                $pdo->prepare('UPDATE escrow_payments SET paystack_reference = ?, platform_payment_id = ? WHERE id = ?')
                    ->execute([$result['reference'], $result['payment_id'], $escrow['id']]);
                header('Location: ' . $result['checkout_url']);
                exit;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Escrow Payment — AkuapemHub</title>
    <link rel="stylesheet" href="assets/css/style.css" />
</head>
<body class="has-bottom-nav">
    <header class="app-topbar">
        <a href="request_detail.php?id=<?php echo $jobId; ?>" class="brand" style="text-decoration:none;">
            <span class="brand-icon">‹</span> Escrow Payment
        </a>
    </header>
    <main class="page-shell small-shell">
        <?php if ($paystackError): ?>
            <div class="alert alert-error"><?php echo sanitize($paystackError); ?></div>
        <?php endif; ?>

        <section class="card" style="margin-bottom:16px;">
            <h1 style="font-size:1.1rem;margin:0 0 4px;"><?php echo sanitize($job['title']); ?></h1>
            <p class="meta" style="margin:0;"><?php echo sanitize($job['category_name']); ?> · <?php echo sanitize($job['location']); ?></p>
        </section>

        <section class="card" style="margin-bottom:16px;">
            <p style="margin:0 0 12px;font-weight:600;">Payment summary</p>
            <table style="width:100%;border-collapse:collapse;font-size:0.95rem;">
                <tr style="border-bottom:1px solid var(--border);">
                    <td style="padding:8px 0;">Worker receives</td>
                    <td style="text-align:right;font-weight:600;">GH₵ <?php echo number_format($escrow['net_amount'], 2); ?></td>
                </tr>
                <tr style="border-bottom:1px solid var(--border);">
                    <td style="padding:8px 0;">Platform fee (<?php echo number_format($escrow['commission_rate'], 0); ?>%)</td>
                    <td style="text-align:right;">GH₵ <?php echo number_format($escrow['commission_amount'], 2); ?></td>
                </tr>
                <?php if ($bundlePostingFee): ?>
                <tr style="border-bottom:1px solid var(--border);">
                    <td style="padding:8px 0;">Job posting fee</td>
                    <td style="text-align:right;" id="posting-fee-cell"><em style="color:var(--text-muted);font-size:0.85rem;">select package</em></td>
                </tr>
                <?php endif; ?>
                <tr>
                    <td style="padding:10px 0 4px;font-weight:700;font-size:1.05rem;">Total due today</td>
                    <td style="text-align:right;font-weight:700;font-size:1.15rem;color:var(--primary);" id="total-amount-cell">GH₵ <?php echo number_format($escrow['gross_amount'], 2); ?><?php if ($bundlePostingFee): ?> + fee<?php endif; ?></td>
                </tr>
            </table>
        </section>

        <?php if ($bundlePostingFee): ?>
        <section class="card" style="margin-bottom:16px;">
            <p style="margin:0 0 4px;font-weight:600;">Job posting package</p>
            <p class="meta" style="margin:0 0 14px;">A posting fee is required to submit your job for review. It is included in the single payment below.</p>
            <?php foreach ($postingPackages as $pkg): ?>
                <label class="list-row" style="display:flex;align-items:center;gap:12px;padding:14px;border:2px solid var(--border);border-radius:10px;margin-bottom:10px;cursor:pointer;">
                    <input type="radio" name="posting_package_id" value="<?php echo $pkg['id']; ?>"
                           data-price="<?php echo number_format((float)$pkg['price'], 2, '.', ''); ?>"
                           form="checkout-form" required />
                    <span>
                        <strong><?php echo sanitize($pkg['name']); ?></strong>
                        <?php if ($pkg['post_count'] == -1): ?>
                            <span class="meta"> — unlimited posts</span>
                        <?php elseif ($pkg['post_count'] == 1): ?>
                            <span class="meta"> — 1 post</span>
                        <?php else: ?>
                            <span class="meta"> — <?php echo (int)$pkg['post_count']; ?> posts</span>
                        <?php endif; ?>
                        <?php if (!empty($pkg['description'])): ?>
                            <br><span class="meta" style="font-size:0.83rem;"><?php echo sanitize($pkg['description']); ?></span>
                        <?php endif; ?>
                        <br><strong style="color:var(--primary);">GH₵ <?php echo number_format((float)$pkg['price'], 2); ?></strong>
                    </span>
                </label>
            <?php endforeach; ?>
        </section>
        <?php elseif ($needsPostingFee): ?>
        <section class="card" style="margin-bottom:16px;background:var(--surface-muted);">
            <p class="meta" style="margin:0;">ⓘ After this payment a job posting fee will also be required before your job goes live for admin review.</p>
        </section>
        <?php endif; ?>

        <section class="card" style="margin-bottom:16px;background:var(--surface-muted);">
            <p style="margin:0 0 6px;font-weight:600;">How escrow works</p>
            <ol style="margin:0;padding-left:18px;line-height:1.8;font-size:0.9rem;">
                <li>You pay <strong id="how-amount">GH₵ <?php echo number_format($escrow['gross_amount'], 2); ?><?php if ($bundlePostingFee): ?> + posting fee<?php endif; ?></strong> now via Paystack.</li>
                <li>AkuapemHub holds the escrow funds securely after admin approves your job.</li>
                <li>A worker accepts and completes the job.</li>
                <li>You confirm satisfactory completion and release the payment to the worker.</li>
                <li>If you don't release within 7 days of job completion, the payment is auto-released to the worker.</li>
            </ol>
        </section>

        <?php if (paystack_configured()): ?>
            <form method="post" action="escrow_checkout.php?id=<?php echo $jobId; ?>" id="checkout-form">
                <?php echo csrf_field(); ?>
                <button type="submit" class="button button-primary" style="width:100%;font-size:1.05rem;" id="pay-btn">
                    Pay GH₵ <?php echo number_format($escrow['gross_amount'], 2); ?><?php if ($bundlePostingFee): ?> + posting fee<?php endif; ?> with Paystack
                </button>
            </form>
        <?php else: ?>
            <div class="alert alert-warning">Payment gateway is not yet configured. Please contact an admin to complete setup.</div>
        <?php endif; ?>

        <p class="meta" style="text-align:center;margin-top:12px;">
            <a href="jobs.php">Save for later</a> — your job draft is saved and you can return any time.
        </p>
    </main>
    <?php $activeNav = 'home'; require __DIR__ . '/partials/bottom_nav.php'; ?>
    <?php if ($bundlePostingFee): ?>
    <script>
    (function () {
        var escrowBase = <?php echo json_encode((float)$escrow['gross_amount']); ?>;
        var totalCell  = document.getElementById('total-amount-cell');
        var feeCell    = document.getElementById('posting-fee-cell');
        var payBtn     = document.getElementById('pay-btn');
        var howAmt     = document.getElementById('how-amount');

        function fmt(n) {
            return 'GH₵ ' + n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        }

        document.querySelectorAll('input[name="posting_package_id"]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                var fee   = parseFloat(this.dataset.price) || 0;
                var total = escrowBase + fee;
                feeCell.textContent  = fmt(fee);
                totalCell.textContent = fmt(total);
                payBtn.textContent   = 'Pay ' + fmt(total) + ' with Paystack';
                if (howAmt) howAmt.textContent = fmt(total);
            });
        });
    })();
    </script>
    <?php endif; ?>
</body>
</html>
