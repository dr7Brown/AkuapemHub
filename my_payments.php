<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_login();
$user = current_user();

$stmt = $pdo->prepare("
    SELECT pp.*,
        CASE pp.payment_type
            WHEN 'featured_job'    THEN sr.title
            WHEN 'job_post'        THEN sr2.title
            ELSE NULL
        END AS job_title,
        CASE pp.payment_type
            WHEN 'featured_job'    THEN COALESCE(fp.name, '—')
            WHEN 'featured_worker' THEN COALESCE(wp2.name, '—')
            WHEN 'verification'    THEN COALESCE(vp.name, '—')
            WHEN 'job_post'        THEN COALESCE(jp.name, '—')
            WHEN 'worker_service'  THEN COALESCE(ws.name, '—')
        END AS package_name
    FROM platform_payments pp
    LEFT JOIN service_requests sr  ON pp.payment_type = 'featured_job' AND sr.id  = pp.reference_id
    LEFT JOIN service_requests sr2 ON pp.payment_type = 'job_post'     AND sr2.id = pp.reference_id
    LEFT JOIN featured_job_packages fp      ON pp.payment_type = 'featured_job'    AND fp.id  = pp.package_id
    LEFT JOIN worker_promotion_packages wp2 ON pp.payment_type = 'featured_worker' AND wp2.id = pp.package_id
    LEFT JOIN verification_packages vp      ON pp.payment_type = 'verification'    AND vp.id  = pp.package_id
    LEFT JOIN job_posting_packages jp       ON pp.payment_type = 'job_post'        AND jp.id  = pp.package_id
    LEFT JOIN worker_service_packages ws    ON pp.payment_type = 'worker_service'  AND ws.id  = pp.package_id
    WHERE pp.user_id = ?
    ORDER BY pp.created_at DESC
");
$stmt->execute([$user['id']]);
$payments = $stmt->fetchAll();

$pending = array_filter($payments, fn($p) => $p['status'] === 'pending');
$paid    = array_filter($payments, fn($p) => $p['status'] === 'paid');
$failed  = array_filter($payments, fn($p) => $p['status'] === 'failed');

$typeLabel = [
    'featured_job'    => 'Featured Job',
    'featured_worker' => 'Featured Profile',
    'verification'    => 'Verification Badge',
    'job_post'        => 'Job Posting Fee',
    'worker_service'  => 'Service Listing',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>My Payments — AkuapemHub</title>
    <link rel="stylesheet" href="assets/css/style.css" />
</head>
<body class="has-bottom-nav">
    <header class="app-topbar">
        <a href="dashboard.php" class="button button-secondary button-small">← Back</a>
        <span class="brand">My Payments</span>
    </header>
    <main class="page-shell small-shell">

        <?php if (empty($payments)): ?>
            <div class="empty-state">You have no platform payment history yet.</div>

        <?php else: ?>

            <?php if (!empty($pending)): ?>
                <section class="panel">
                    <h2 style="margin-top:0;color:#f59e0b;">⏳ Awaiting Confirmation</h2>
                    <p class="meta">Share your reference code with our team to confirm payment. Features activate once confirmed.</p>
                    <?php foreach ($pending as $p): ?>
                        <div style="padding:14px;border:2px solid #f59e0b;border-radius:10px;margin-bottom:12px;">
                            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;flex-wrap:wrap;">
                                <div>
                                    <strong><?php echo sanitize($typeLabel[$p['payment_type']] ?? $p['payment_type']); ?></strong>
                                    — <?php echo sanitize($p['package_name']); ?>
                                    <?php if ($p['job_title']): ?>
                                        <br><span class="meta">📋 <?php echo sanitize(substr($p['job_title'], 0, 50)); ?></span>
                                    <?php endif; ?>
                                </div>
                                <strong style="color:var(--primary);white-space:nowrap;">GH₵ <?php echo number_format($p['amount'], 2); ?></strong>
                            </div>
                            <div style="margin-top:10px;padding:10px;background:var(--surface);border-radius:8px;display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;">
                                <div>
                                    <p class="meta" style="margin:0 0 2px;">Reference code</p>
                                    <strong style="font-size:1.1rem;letter-spacing:0.05em;"><?php echo sanitize($p['reference_code']); ?></strong>
                                </div>
                                <span class="meta" style="font-size:0.8rem;"><?php echo sanitize(date('d M Y, H:i', strtotime($p['created_at']))); ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </section>
            <?php endif; ?>

            <?php if (!empty($paid)): ?>
                <section class="panel">
                    <h2 style="margin-top:0;">✓ Confirmed Payments</h2>
                    <div style="display:flex;flex-direction:column;gap:8px;">
                        <?php foreach ($paid as $p): ?>
                            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;padding:12px;background:var(--surface);border-radius:8px;border:1px solid var(--border);">
                                <div>
                                    <strong style="font-size:0.93rem;"><?php echo sanitize($typeLabel[$p['payment_type']] ?? $p['payment_type']); ?></strong>
                                    — <?php echo sanitize($p['package_name']); ?>
                                    <?php if ($p['job_title']): ?><br><span class="meta">📋 <?php echo sanitize(substr($p['job_title'], 0, 40)); ?></span><?php endif; ?>
                                    <br><span class="meta" style="font-size:0.8rem;">Ref: <?php echo sanitize($p['reference_code']); ?> · <?php echo sanitize(date('d M Y', strtotime($p['paid_at'] ?: $p['created_at']))); ?></span>
                                </div>
                                <div style="text-align:right;flex-shrink:0;">
                                    <strong style="color:var(--primary);">GH₵ <?php echo number_format($p['amount'], 2); ?></strong>
                                    <br><span class="status status-open" style="font-size:0.72rem;">PAID</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <?php if (!empty($failed)): ?>
                <section class="panel">
                    <h2 style="margin-top:0;color:#c0392b;">✗ Unconfirmed / Rejected</h2>
                    <p class="meta">These payments were not confirmed. Contact support if you believe this is an error.</p>
                    <div style="display:flex;flex-direction:column;gap:8px;">
                        <?php foreach ($failed as $p): ?>
                            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;padding:12px;background:var(--surface);border-radius:8px;border:1px solid var(--border);opacity:0.75;">
                                <div>
                                    <strong style="font-size:0.93rem;"><?php echo sanitize($typeLabel[$p['payment_type']] ?? $p['payment_type']); ?></strong>
                                    — <?php echo sanitize($p['package_name']); ?>
                                    <br><span class="meta" style="font-size:0.8rem;">Ref: <?php echo sanitize($p['reference_code']); ?> · <?php echo sanitize(date('d M Y', strtotime($p['created_at']))); ?></span>
                                </div>
                                <div style="text-align:right;flex-shrink:0;">
                                    <strong>GH₵ <?php echo number_format($p['amount'], 2); ?></strong>
                                    <br><span class="status status-cancelled" style="font-size:0.72rem;">NOT CONFIRMED</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

        <?php endif; ?>
    </main>
    <?php $activeNav = 'settings'; require __DIR__ . '/partials/bottom_nav.php'; ?>
</body>
</html>
