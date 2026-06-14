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

$stmt = $pdo->prepare("
    SELECT pp.*,
        u.name  AS payer_name,
        u.email AS payer_email,
        sr.title    AS job_title,
        sr.location AS job_location,
        ep.net_amount        AS escrow_net,
        ep.commission_amount AS escrow_commission,
        ep.commission_rate   AS escrow_rate,
        ep.gross_amount      AS escrow_gross,
        CASE pp.payment_type
            WHEN 'featured_job'        THEN fjp.name
            WHEN 'featured_worker'     THEN wpp.name
            WHEN 'verification'        THEN vp.name
            WHEN 'job_post'            THEN jpp.name
            WHEN 'escrow_with_posting' THEN jpp.name
            WHEN 'worker_service'      THEN wsp.name
            ELSE NULL
        END AS package_name,
        COALESCE(fjp.duration_days, wpp.duration_days, wsp.duration_days) AS package_duration,
        CASE pp.payment_type
            WHEN 'job_post'            THEN jpp.post_count
            WHEN 'escrow_with_posting' THEN jpp.post_count
            ELSE NULL
        END AS post_count,
        CASE pp.payment_type
            WHEN 'job_post'            THEN jpp.price
            WHEN 'escrow_with_posting' THEN jpp.price
            ELSE NULL
        END AS posting_fee_price
    FROM platform_payments pp
    JOIN users u ON pp.user_id = u.id
    LEFT JOIN service_requests sr
        ON pp.payment_type IN ('featured_job','job_post','escrow_payment','escrow_with_posting')
        AND sr.id = pp.reference_id
    LEFT JOIN escrow_payments ep
        ON pp.payment_type IN ('escrow_payment','escrow_with_posting')
        AND ep.job_id = pp.reference_id AND ep.client_id = pp.user_id
    LEFT JOIN featured_job_packages fjp
        ON pp.payment_type = 'featured_job' AND fjp.id = pp.package_id
    LEFT JOIN worker_promotion_packages wpp
        ON pp.payment_type = 'featured_worker' AND wpp.id = pp.package_id
    LEFT JOIN verification_packages vp
        ON pp.payment_type = 'verification' AND vp.id = pp.package_id
    LEFT JOIN job_posting_packages jpp
        ON pp.payment_type IN ('job_post','escrow_with_posting') AND jpp.id = pp.package_id
    LEFT JOIN worker_service_packages wsp
        ON pp.payment_type = 'worker_service' AND wsp.id = pp.package_id
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

$receiptNumber = 'AKH-' . str_pad($p['id'], 6, '0', STR_PAD_LEFT);
$paidAt  = $p['paid_at'] ?: $p['created_at'];

$typeLabels = [
    'featured_job'       => 'Featured Job Listing',
    'featured_worker'    => 'Featured Worker Profile',
    'verification'       => 'Identity Verification',
    'job_post'           => 'Job Posting Fee',
    'worker_service'     => 'Worker Service Listing',
    'escrow_payment'     => 'Escrow Payment',
    'escrow_with_posting'=> 'Escrow + Job Posting Fee',
];
$typeLabel = $typeLabels[$p['payment_type']] ?? ucwords(str_replace('_', ' ', $p['payment_type']));

$continueUrls = [
    'featured_job'       => 'request_detail.php?id=' . $p['reference_id'],
    'featured_worker'    => 'worker_profile.php',
    'verification'       => 'worker_profile.php',
    'job_post'           => 'dashboard.php',
    'worker_service'     => 'worker_profile.php',
    'escrow_payment'     => 'request_detail.php?id=' . $p['reference_id'],
    'escrow_with_posting'=> 'request_detail.php?id=' . $p['reference_id'],
];
$continueUrl = $continueUrls[$p['payment_type']] ?? 'dashboard.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Receipt <?php echo sanitize($receiptNumber); ?> — <?php echo sanitize(APP_NAME); ?></title>
    <link rel="stylesheet" href="assets/css/style.css" />
    <style>
        /* ── Receipt layout ─────────────────────────────── */
        .rcpt-shell {
            max-width: 680px;
            margin: 0 auto;
            padding: 20px 16px 40px;
        }
        .rcpt-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 12px rgba(0,0,0,.07);
        }
        /* green header band */
        .rcpt-header {
            background: #0f766e;
            color: #fff;
            padding: 24px 28px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            flex-wrap: wrap;
        }
        .rcpt-header h2 { margin: 0 0 4px; font-size: 1.3rem; letter-spacing: .01em; }
        .rcpt-header .meta { margin: 0; color: rgba(255,255,255,.75); font-size: .85rem; }
        .rcpt-header .rcpt-num { text-align: right; }
        .rcpt-header .rcpt-num strong { display: block; font-size: 1rem; }
        .rcpt-header .rcpt-num .meta { font-size: .8rem; }
        /* body */
        .rcpt-body { padding: 24px 28px; }
        /* confirmed stamp */
        .rcpt-stamp {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #f0fdf4;
            border: 1.5px solid #16a34a;
            color: #16a34a;
            border-radius: 6px;
            padding: 5px 12px;
            font-size: .83rem;
            font-weight: 700;
            margin-bottom: 20px;
            letter-spacing: .04em;
        }
        /* meta rows */
        .rcpt-meta-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 4px 24px;
            margin-bottom: 20px;
        }
        .rcpt-meta-row { font-size: .88rem; color: #374151; }
        .rcpt-meta-row span { color: #6b7280; display: block; font-size: .78rem; }
        @media (max-width:480px) { .rcpt-meta-grid { grid-template-columns: 1fr; } }
        /* breakdown table */
        .rcpt-table {
            width: 100%;
            border-collapse: collapse;
            font-size: .93rem;
            margin-top: 16px;
        }
        .rcpt-table th {
            text-align: left;
            padding: 8px 0 8px;
            border-bottom: 2px solid #e2e8f0;
            color: #6b7280;
            font-size: .78rem;
            text-transform: uppercase;
            letter-spacing: .06em;
        }
        .rcpt-table th:last-child { text-align: right; }
        .rcpt-table td {
            padding: 11px 0;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: top;
        }
        .rcpt-table td:last-child { text-align: right; font-weight: 600; white-space: nowrap; }
        .rcpt-table td .sub { font-size: .8rem; color: #6b7280; display: block; margin-top: 2px; }
        .rcpt-table tr.total-row td {
            border-top: 2px solid #e2e8f0;
            border-bottom: none;
            padding-top: 14px;
            font-weight: 700;
            font-size: 1.05rem;
        }
        .rcpt-table tr.total-row td:last-child { color: #0f766e; font-size: 1.2rem; }
        /* footer */
        .rcpt-footer {
            padding: 16px 28px 20px;
            border-top: 1px solid #f1f5f9;
            background: #f8fafc;
            font-size: .8rem;
            color: #6b7280;
            line-height: 1.6;
        }
        /* print */
        @media print {
            .no-print { display: none !important; }
            body { background: #fff !important; }
            .rcpt-shell { padding: 0; }
            .rcpt-card { border: none; box-shadow: none; }
        }
    </style>
</head>
<body class="has-bottom-nav">
    <header class="app-topbar no-print">
        <a href="my_payments.php" class="button button-secondary button-small">← Payments</a>
        <span class="brand">Receipt</span>
    </header>

    <div class="rcpt-shell">
        <!-- action buttons -->
        <div class="no-print" style="display:flex;gap:10px;justify-content:flex-end;margin-bottom:14px;">
            <button onclick="window.print()" class="button button-secondary button-small">
                🖨 Print / Save PDF
            </button>
            <a href="<?php echo sanitize($continueUrl); ?>" class="button button-primary button-small">
                Continue →
            </a>
        </div>

        <div class="rcpt-card">
            <!-- header band -->
            <div class="rcpt-header">
                <div>
                    <h2><?php echo sanitize(APP_NAME); ?></h2>
                    <p class="meta">Official payment receipt</p>
                </div>
                <div class="rcpt-num">
                    <strong>Receipt #<?php echo sanitize($receiptNumber); ?></strong>
                    <p class="meta"><?php echo date('d M Y, H:i', strtotime($paidAt)); ?> GMT</p>
                </div>
            </div>

            <div class="rcpt-body">
                <!-- confirmed stamp -->
                <div class="rcpt-stamp">✓ PAYMENT CONFIRMED</div>

                <!-- payer & job info -->
                <div class="rcpt-meta-grid">
                    <div class="rcpt-meta-row">
                        <span>Paid by</span>
                        <?php echo sanitize($p['payer_name']); ?>
                    </div>
                    <div class="rcpt-meta-row">
                        <span>Email</span>
                        <?php echo sanitize($p['payer_email']); ?>
                    </div>
                    <div class="rcpt-meta-row">
                        <span>Payment type</span>
                        <?php echo sanitize($typeLabel); ?>
                    </div>
                    <div class="rcpt-meta-row">
                        <span>Payment method</span>
                        Paystack (online)
                    </div>
                    <?php if ($p['job_title']): ?>
                    <div class="rcpt-meta-row" style="grid-column:1/-1;">
                        <span>Job</span>
                        <?php echo sanitize($p['job_title']); ?>
                        <?php if ($p['job_location']): ?>
                            &mdash; <?php echo sanitize($p['job_location']); ?>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <div class="rcpt-meta-row">
                        <span>Paystack reference</span>
                        <?php echo sanitize($p['paystack_reference']); ?>
                    </div>
                    <div class="rcpt-meta-row">
                        <span>Internal reference</span>
                        <?php echo sanitize($p['reference_code']); ?>
                    </div>
                </div>

                <!-- charge breakdown -->
                <table class="rcpt-table">
                    <thead>
                        <tr>
                            <th>Description</th>
                            <th>Amount (GH₵)</th>
                        </tr>
                    </thead>
                    <tbody>

                        <?php if (in_array($p['payment_type'], ['escrow_payment', 'escrow_with_posting'], true)): ?>
                            <tr>
                                <td>
                                    Worker receives
                                    <span class="sub">Held in escrow until job completion</span>
                                </td>
                                <td><?php echo number_format((float)$p['escrow_net'], 2); ?></td>
                            </tr>
                            <tr>
                                <td>
                                    Platform commission
                                    <span class="sub"><?php echo number_format((float)$p['escrow_rate'], 0); ?>% of worker amount</span>
                                </td>
                                <td><?php echo number_format((float)$p['escrow_commission'], 2); ?></td>
                            </tr>
                            <?php if ($p['payment_type'] === 'escrow_with_posting'): ?>
                            <tr>
                                <td>
                                    Job posting fee
                                    <?php if ($p['package_name']): ?>
                                        <span class="sub">Package: <?php echo sanitize($p['package_name']); ?>
                                        <?php if ($p['post_count'] && $p['post_count'] > 1): ?>
                                            — <?php echo (int)$p['post_count']; ?> posts included
                                        <?php elseif ($p['post_count'] == -1): ?>
                                            — unlimited posts
                                        <?php endif; ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo number_format((float)$p['posting_fee_price'], 2); ?></td>
                            </tr>
                            <?php endif; ?>

                        <?php elseif ($p['payment_type'] === 'job_post'): ?>
                            <tr>
                                <td>
                                    Job posting package
                                    <?php if ($p['package_name']): ?>
                                        <span class="sub"><?php echo sanitize($p['package_name']); ?>
                                        <?php if ($p['post_count'] == -1): ?>
                                            — unlimited posts
                                        <?php elseif ($p['post_count'] > 1): ?>
                                            — <?php echo (int)$p['post_count']; ?> posts included
                                        <?php else: ?>
                                            — 1 post
                                        <?php endif; ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo number_format((float)$p['amount'], 2); ?></td>
                            </tr>

                        <?php elseif ($p['payment_type'] === 'featured_job'): ?>
                            <tr>
                                <td>
                                    Featured job listing
                                    <?php if ($p['package_name']): ?>
                                        <span class="sub"><?php echo sanitize($p['package_name']); ?>
                                        <?php if ($p['package_duration']): ?> — <?php echo (int)$p['package_duration']; ?> days visibility<?php endif; ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo number_format((float)$p['amount'], 2); ?></td>
                            </tr>

                        <?php elseif ($p['payment_type'] === 'featured_worker'): ?>
                            <tr>
                                <td>
                                    Featured worker profile
                                    <?php if ($p['package_name']): ?>
                                        <span class="sub"><?php echo sanitize($p['package_name']); ?>
                                        <?php if ($p['package_duration']): ?> — <?php echo (int)$p['package_duration']; ?> days visibility<?php endif; ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo number_format((float)$p['amount'], 2); ?></td>
                            </tr>

                        <?php elseif ($p['payment_type'] === 'worker_service'): ?>
                            <tr>
                                <td>
                                    Worker service listing
                                    <?php if ($p['package_name']): ?>
                                        <span class="sub"><?php echo sanitize($p['package_name']); ?>
                                        <?php if ($p['package_duration']): ?> — active for <?php echo (int)$p['package_duration']; ?> days<?php endif; ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo number_format((float)$p['amount'], 2); ?></td>
                            </tr>

                        <?php elseif ($p['payment_type'] === 'verification'): ?>
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

                        <!-- total row -->
                        <tr class="total-row">
                            <td>Total paid</td>
                            <td>GH₵ <?php echo number_format((float)$p['amount'], 2); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="rcpt-footer">
                This receipt was generated automatically by <?php echo sanitize(APP_NAME); ?> and confirms payment received via Paystack.
                For queries regarding this transaction, quote reference <strong><?php echo sanitize($receiptNumber); ?></strong> or
                Paystack ref <strong><?php echo sanitize($p['paystack_reference']); ?></strong> when contacting support at
                <a href="mailto:<?php echo sanitize(MAIL_FROM); ?>"><?php echo sanitize(MAIL_FROM); ?></a>.
                <br><br>
                <a href="terms.php">Terms of Service</a> &nbsp;·&nbsp;
                <a href="privacy.php">Privacy Policy</a> &nbsp;·&nbsp;
                <a href="contact.php">Contact</a>
            </div>
        </div>

        <!-- bottom actions (no-print) -->
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
