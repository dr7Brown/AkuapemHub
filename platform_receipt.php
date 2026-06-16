<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_login();
$user = current_user();

$ref = trim($_GET['ref'] ?? '');
if (!$ref) {
    flash('Receipt not found.', 'error');
    header('Location: my_payments.php');
    exit;
}

// ── 1. Core payment record ───────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT pp.*, u.name AS payer_name, u.email AS payer_email
    FROM platform_payments pp
    JOIN users u ON pp.user_id = u.id
    WHERE pp.paystack_reference = ? AND pp.user_id = ? AND pp.status = 'paid'
    LIMIT 1
");
$stmt->execute([$ref, $user['id']]);
$p = $stmt->fetch();

if (!$p) {
    flash('Receipt not found or this payment has not been confirmed yet.', 'error');
    header('Location: my_payments.php');
    exit;
}

$type = $p['payment_type'];

// ── 2. Escrow breakdown (only for escrow types) ──────────────────────────────
$escrow = null;
if (in_array($type, ['escrow_payment', 'escrow_with_posting'], true)) {
    $eStmt = $pdo->prepare("SELECT * FROM escrow_payments WHERE job_id = ? LIMIT 1");
    $eStmt->execute([$p['reference_id']]);
    $escrow = $eStmt->fetch();
}

// ── 3. Job / service-request details (where reference_id = job) ─────────────
$job = null;
if (in_array($type, ['featured_job', 'job_post', 'escrow_payment', 'escrow_with_posting'], true)) {
    $jStmt = $pdo->prepare("SELECT id, title, location FROM service_requests WHERE id = ? LIMIT 1");
    $jStmt->execute([$p['reference_id']]);
    $job = $jStmt->fetch();
}

// ── 4. Package info (looked up per payment type) ─────────────────────────────
$pkg = null;
if ($p['package_id']) {
    $pkgTableMap = [
        'featured_job'       => 'featured_job_packages',
        'featured_worker'    => 'worker_promotion_packages',
        'verification'       => 'verification_packages',
        'job_post'           => 'job_posting_packages',
        'escrow_with_posting'=> 'job_posting_packages',
        'worker_service'     => 'worker_service_packages',
    ];
    if (isset($pkgTableMap[$type])) {
        $pkgStmt = $pdo->prepare("SELECT * FROM {$pkgTableMap[$type]} WHERE id = ? LIMIT 1");
        $pkgStmt->execute([$p['package_id']]);
        $pkg = $pkgStmt->fetch();
    }
}

// ── Derived display values ───────────────────────────────────────────────────
$receiptNumber = 'AKH-' . str_pad($p['id'], 6, '0', STR_PAD_LEFT);
$paidAt        = $p['paid_at'] ?: $p['created_at'];

$typeLabels = [
    'featured_job'       => 'Featured Job Listing',
    'featured_worker'    => 'Featured Worker Profile',
    'verification'       => 'Identity Verification',
    'job_post'           => 'Job Posting Fee',
    'worker_service'     => 'Worker Service Listing',
    'escrow_payment'     => 'Escrow Payment',
    'escrow_with_posting'=> 'Escrow + Job Posting Fee',
    'news_post'          => 'News Article Publishing Fee',
    'event_post'         => 'Event Publishing Fee',
    'funeral_post'       => 'Funeral Announcement Fee',
];
$typeLabel = $typeLabels[$type] ?? ucwords(str_replace('_', ' ', $type));

$continueUrls = [
    'featured_job'       => 'request_detail.php?id=' . $p['reference_id'],
    'featured_worker'    => 'worker_profile.php',
    'verification'       => 'worker_profile.php',
    'job_post'           => 'jobs.php',
    'worker_service'     => 'worker_profile.php',
    'escrow_payment'     => 'request_detail.php?id=' . $p['reference_id'],
    'escrow_with_posting'=> 'request_detail.php?id=' . $p['reference_id'],
    'news_post'          => 'my_news.php',
    'event_post'         => 'my_events.php',
    'funeral_post'       => 'my_funerals.php',
];
$continueUrl = $continueUrls[$type] ?? 'jobs.php';

// For escrow_with_posting, posting fee = total - escrow gross
$postingFeeAmount = null;
if ($type === 'escrow_with_posting' && $escrow) {
    $postingFeeAmount = (float)$p['amount'] - (float)$escrow['gross_amount'];
    // Fallback: use package price if the subtraction yields something odd
    if ($postingFeeAmount < 0 && $pkg) {
        $postingFeeAmount = (float)$pkg['price'];
    }
} elseif ($type === 'escrow_with_posting' && $pkg) {
    $postingFeeAmount = (float)$pkg['price'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Receipt <?php echo sanitize($receiptNumber); ?> — <?php echo sanitize(APP_NAME); ?></title>
    <link rel="stylesheet" href="assets/css/style.css" />
    <style>
        .rcpt-shell { max-width: 680px; margin: 0 auto; padding: 20px 16px 40px; }
        .rcpt-card  { background:#fff; border:1px solid #e2e8f0; border-radius:12px; overflow:hidden; box-shadow:0 2px 12px rgba(0,0,0,.07); }

        .rcpt-header { background:#0f766e; color:#fff; padding:24px 28px; display:flex; justify-content:space-between; align-items:flex-start; gap:16px; flex-wrap:wrap; }
        .rcpt-header h2   { margin:0 0 4px; font-size:1.3rem; }
        .rcpt-header .sub { margin:0; color:rgba(255,255,255,.75); font-size:.85rem; }
        .rcpt-header .num { text-align:right; }
        .rcpt-header .num strong { display:block; font-size:1rem; }
        .rcpt-header .num .sub   { font-size:.8rem; }

        .rcpt-body  { padding:24px 28px; }

        .rcpt-stamp { display:inline-flex; align-items:center; gap:6px; background:#f0fdf4; border:1.5px solid #16a34a; color:#16a34a; border-radius:6px; padding:5px 12px; font-size:.83rem; font-weight:700; margin-bottom:20px; letter-spacing:.04em; }

        .rcpt-meta  { display:grid; grid-template-columns:1fr 1fr; gap:4px 24px; margin-bottom:22px; }
        @media (max-width:480px) { .rcpt-meta { grid-template-columns:1fr; } }
        .rcpt-meta-item       { font-size:.88rem; color:#374151; }
        .rcpt-meta-item span  { color:#9ca3af; display:block; font-size:.76rem; text-transform:uppercase; letter-spacing:.05em; margin-bottom:1px; }
        .rcpt-meta-item.wide  { grid-column:1/-1; }

        /* Breakdown table */
        .rcpt-table { width:100%; border-collapse:collapse; font-size:.93rem; }
        .rcpt-table thead th {
            text-align:left; padding:7px 0 7px; border-bottom:2px solid #e2e8f0;
            color:#9ca3af; font-size:.75rem; text-transform:uppercase; letter-spacing:.06em;
        }
        .rcpt-table thead th:last-child { text-align:right; }
        .rcpt-table tbody td { padding:11px 0; border-bottom:1px solid #f1f5f9; vertical-align:top; }
        .rcpt-table tbody td:last-child { text-align:right; font-weight:600; white-space:nowrap; }
        .rcpt-table tbody td .sub { font-size:.8rem; color:#6b7280; display:block; margin-top:2px; }
        .rcpt-table tbody .sep-row td { padding:0; border-bottom:2px solid #e2e8f0; }
        .rcpt-table tbody .total-row td { border-bottom:none; padding-top:13px; font-weight:700; font-size:1.05rem; }
        .rcpt-table tbody .total-row td:last-child { color:#0f766e; font-size:1.2rem; }

        .rcpt-footer { padding:16px 28px 20px; border-top:1px solid #f1f5f9; background:#f8fafc; font-size:.8rem; color:#6b7280; line-height:1.7; }

        @media print {
            .no-print { display:none !important; }
            body { background:#fff !important; }
            .rcpt-shell { padding:0; }
            .rcpt-card  { border:none; box-shadow:none; }
        }
    </style>
</head>
<body class="has-bottom-nav">
    <header class="app-topbar no-print">
        <a href="my_payments.php" class="button button-secondary button-small">← Payments</a>
        <span class="brand">Receipt</span>
    </header>

    <div class="rcpt-shell">
        <div class="no-print" style="display:flex;gap:10px;justify-content:flex-end;margin-bottom:14px;">
            <button onclick="window.print()" class="button button-secondary button-small">🖨 Print / Save PDF</button>
            <a href="<?php echo sanitize($continueUrl); ?>" class="button button-primary button-small">Continue →</a>
        </div>

        <div class="rcpt-card">
            <!-- ── Header band ──────────────────────────────────── -->
            <div class="rcpt-header">
                <div>
                    <h2><?php echo sanitize(APP_NAME); ?></h2>
                    <p class="sub">Official payment receipt</p>
                </div>
                <div class="num">
                    <strong>Receipt #<?php echo sanitize($receiptNumber); ?></strong>
                    <p class="sub"><?php echo date('d M Y, H:i', strtotime($paidAt)); ?> GMT</p>
                </div>
            </div>

            <!-- ── Body ────────────────────────────────────────── -->
            <div class="rcpt-body">
                <div class="rcpt-stamp">✓ PAYMENT CONFIRMED</div>

                <!-- Payer + transaction meta -->
                <div class="rcpt-meta">
                    <div class="rcpt-meta-item">
                        <span>Paid by</span>
                        <?php echo sanitize($p['payer_name']); ?>
                    </div>
                    <div class="rcpt-meta-item">
                        <span>Email</span>
                        <?php echo sanitize($p['payer_email']); ?>
                    </div>
                    <div class="rcpt-meta-item">
                        <span>Payment type</span>
                        <?php echo sanitize($typeLabel); ?>
                    </div>
                    <div class="rcpt-meta-item">
                        <span>Payment method</span>
                        Paystack (online)
                    </div>
                    <?php if ($job): ?>
                    <div class="rcpt-meta-item wide">
                        <span>Job</span>
                        <?php echo sanitize($job['title']); ?>
                        <?php if ($job['location']): ?> &mdash; <?php echo sanitize($job['location']); ?><?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <div class="rcpt-meta-item">
                        <span>Paystack reference</span>
                        <?php echo sanitize($p['paystack_reference']); ?>
                    </div>
                    <div class="rcpt-meta-item">
                        <span>Internal reference</span>
                        <?php echo sanitize($p['reference_code']); ?>
                    </div>
                </div>

                <!-- ── Charge breakdown table ──────────────────── -->
                <table class="rcpt-table">
                    <thead>
                        <tr>
                            <th>Description</th>
                            <th style="text-align:right;">Amount (GH₵)</th>
                        </tr>
                    </thead>
                    <tbody>

                    <?php if (in_array($type, ['escrow_payment', 'escrow_with_posting'], true) && $escrow): ?>
                        <!-- Escrow breakdown from escrow_payments record -->
                        <tr>
                            <td>
                                Worker receives
                                <span class="sub">Held in escrow — released to worker after job completion</span>
                            </td>
                            <td><?php echo number_format((float)$escrow['net_amount'], 2); ?></td>
                        </tr>
                        <tr>
                            <td>
                                Platform commission
                                <span class="sub"><?php echo number_format((float)$escrow['commission_rate'], 1); ?>% service fee</span>
                            </td>
                            <td><?php echo number_format((float)$escrow['commission_amount'], 2); ?></td>
                        </tr>
                        <?php if ($type === 'escrow_with_posting' && $postingFeeAmount !== null): ?>
                        <tr>
                            <td>
                                Job posting fee
                                <?php if ($pkg): ?>
                                    <span class="sub">
                                        Package: <?php echo sanitize($pkg['name']); ?>
                                        <?php
                                            $pc = (int)($pkg['post_count'] ?? 1);
                                            if ($pc == -1)     echo ' — unlimited posts';
                                            elseif ($pc > 1)   echo " — {$pc} posts included";
                                        ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo number_format($postingFeeAmount, 2); ?></td>
                        </tr>
                        <?php endif; ?>

                    <?php elseif (in_array($type, ['escrow_payment', 'escrow_with_posting'], true)): ?>
                        <!-- Fallback if escrow record not found: show flat amount -->
                        <tr>
                            <td>
                                Escrow payment
                                <span class="sub">Worker payment + platform fee</span>
                            </td>
                            <td><?php echo number_format((float)$p['amount'], 2); ?></td>
                        </tr>

                    <?php elseif ($type === 'job_post'): ?>
                        <tr>
                            <td>
                                Job posting package
                                <?php if ($pkg): ?>
                                    <span class="sub">
                                        <?php echo sanitize($pkg['name']); ?>
                                        <?php
                                            $pc = (int)($pkg['post_count'] ?? 1);
                                            if ($pc == -1)     echo ' — unlimited posts';
                                            elseif ($pc > 1)   echo " — {$pc} posts included";
                                            else               echo ' — 1 post';
                                        ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo number_format((float)$p['amount'], 2); ?></td>
                        </tr>

                    <?php elseif ($type === 'featured_job'): ?>
                        <tr>
                            <td>
                                Featured job listing
                                <?php if ($pkg): ?>
                                    <span class="sub">
                                        <?php echo sanitize($pkg['name']); ?>
                                        <?php if (!empty($pkg['duration_days'])): ?> — <?php echo (int)$pkg['duration_days']; ?> days priority visibility<?php endif; ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo number_format((float)$p['amount'], 2); ?></td>
                        </tr>

                    <?php elseif ($type === 'featured_worker'): ?>
                        <tr>
                            <td>
                                Featured worker profile
                                <?php if ($pkg): ?>
                                    <span class="sub">
                                        <?php echo sanitize($pkg['name']); ?>
                                        <?php if (!empty($pkg['duration_days'])): ?> — <?php echo (int)$pkg['duration_days']; ?> days priority visibility<?php endif; ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo number_format((float)$p['amount'], 2); ?></td>
                        </tr>

                    <?php elseif ($type === 'worker_service'): ?>
                        <tr>
                            <td>
                                Worker service listing
                                <?php if ($pkg): ?>
                                    <span class="sub">
                                        <?php echo sanitize($pkg['name']); ?>
                                        <?php if (!empty($pkg['duration_days'])): ?> — active for <?php echo (int)$pkg['duration_days']; ?> days<?php endif; ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo number_format((float)$p['amount'], 2); ?></td>
                        </tr>

                    <?php elseif ($type === 'verification'): ?>
                        <tr>
                            <td>
                                Identity verification fee
                                <span class="sub">Unlocks admin review of your verification documents</span>
                            </td>
                            <td><?php echo number_format((float)$p['amount'], 2); ?></td>
                        </tr>

                    <?php else: ?>
                        <tr>
                            <td><?php echo sanitize($typeLabel); ?></td>
                            <td><?php echo number_format((float)$p['amount'], 2); ?></td>
                        </tr>
                    <?php endif; ?>

                        <!-- Separator + total -->
                        <tr class="sep-row"><td colspan="2"></td></tr>
                        <tr class="total-row">
                            <td>Total paid</td>
                            <td>GH₵ <?php echo number_format((float)$p['amount'], 2); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- ── Footer ──────────────────────────────────────── -->
            <div class="rcpt-footer">
                This receipt was generated automatically by <?php echo sanitize(APP_NAME); ?> and confirms payment received via Paystack.
                For queries, quote receipt <strong><?php echo sanitize($receiptNumber); ?></strong> or
                Paystack ref <strong><?php echo sanitize($p['paystack_reference']); ?></strong> when contacting
                <a href="mailto:<?php echo sanitize(MAIL_FROM); ?>"><?php echo sanitize(MAIL_FROM); ?></a>.
                <br><br>
                <a href="terms.php">Terms of Service</a> &nbsp;·&nbsp;
                <a href="privacy.php">Privacy Policy</a> &nbsp;·&nbsp;
                <a href="contact.php">Contact</a>
            </div>
        </div>

        <div class="no-print" style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px;">
            <button onclick="window.print()" class="button button-secondary">🖨 Print / Save as PDF</button>
            <a href="<?php echo sanitize($continueUrl); ?>" class="button button-primary">Continue →</a>
        </div>
    </div>

    <div class="no-print">
        <?php $activeNav = 'settings'; require __DIR__ . '/partials/bottom_nav.php'; ?>
    </div>
</body>
</html>
