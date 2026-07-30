<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_login();
$user = current_user();

$stmt = $pdo->prepare("
    SELECT pp.*,
        CASE pp.payment_type
            WHEN 'featured_job'        THEN sr.title
            WHEN 'job_post'            THEN sr2.title
            WHEN 'escrow_with_posting' THEN sr3.title
            ELSE NULL
        END AS job_title,
        CASE pp.payment_type
            WHEN 'featured_job'        THEN COALESCE(fp.name, '—')
            WHEN 'featured_worker'     THEN COALESCE(wp2.name, '—')
            WHEN 'verification'        THEN COALESCE(vp.name, '—')
            WHEN 'job_post'            THEN COALESCE(jp.name, '—')
            WHEN 'escrow_with_posting' THEN COALESCE(jp2.name, '—')
            WHEN 'worker_service'      THEN COALESCE(ws.name, '—')
            WHEN 'featured_event'      THEN COALESCE(fep.name, '—')
            WHEN 'featured_funeral'    THEN COALESCE(ffp.name, '—')
            WHEN 'featured_news'       THEN COALESCE(fnp.name, '—')
            WHEN 'mp_subscription'     THEN COALESCE(msp.name, '—')
            WHEN 'mp_boost'            THEN COALESCE(mbp.name, '—')
        END AS package_name
    FROM platform_payments pp
    LEFT JOIN service_requests sr   ON pp.payment_type = 'featured_job'        AND sr.id   = pp.reference_id
    LEFT JOIN service_requests sr2  ON pp.payment_type = 'job_post'            AND sr2.id  = pp.reference_id
    LEFT JOIN service_requests sr3  ON pp.payment_type = 'escrow_with_posting' AND sr3.id  = pp.reference_id
    LEFT JOIN featured_job_packages fp       ON pp.payment_type = 'featured_job'        AND fp.id   = pp.package_id
    LEFT JOIN worker_promotion_packages wp2  ON pp.payment_type = 'featured_worker'     AND wp2.id  = pp.package_id
    LEFT JOIN verification_packages vp       ON pp.payment_type = 'verification'        AND vp.id   = pp.package_id
    LEFT JOIN job_posting_packages jp        ON pp.payment_type = 'job_post'            AND jp.id   = pp.package_id
    LEFT JOIN job_posting_packages jp2       ON pp.payment_type = 'escrow_with_posting' AND jp2.id  = pp.package_id
    LEFT JOIN worker_service_packages ws     ON pp.payment_type = 'worker_service'      AND ws.id   = pp.package_id
    LEFT JOIN featured_event_packages fep    ON pp.payment_type = 'featured_event'      AND fep.id  = pp.package_id
    LEFT JOIN featured_funeral_packages ffp  ON pp.payment_type = 'featured_funeral'    AND ffp.id  = pp.package_id
    LEFT JOIN featured_news_packages fnp     ON pp.payment_type = 'featured_news'       AND fnp.id  = pp.package_id
    LEFT JOIN mp_seller_subscription_plans msp ON pp.payment_type = 'mp_subscription'  AND msp.id  = pp.package_id
    LEFT JOIN mp_boost_packages mbp          ON pp.payment_type = 'mp_boost'            AND mbp.id  = pp.package_id
    WHERE pp.user_id = ?
    ORDER BY pp.created_at DESC
");
$stmt->execute([$user['id']]);
$payments = $stmt->fetchAll();

$pending   = array_filter($payments, fn($p) => $p['status'] === 'pending');
$paid      = array_filter($payments, fn($p) => $p['status'] === 'paid');
$failed    = array_filter($payments, fn($p) => in_array($p['status'], ['failed', 'abandoned', 'refunded'], true));

$typeLabel = [
    'featured_job'       => 'Featured Job',
    'featured_worker'    => 'Featured Profile',
    'verification'       => 'Verification Badge',
    'job_post'           => 'Job Posting Fee',
    'worker_service'     => 'Service Listing',
    'escrow_payment'     => 'Escrow Payment',
    'escrow_with_posting'=> 'Escrow + Posting Fee',
    'news_post'          => 'News Publishing Fee',
    'event_post'         => 'Event Publishing Fee',
    'funeral_post'       => 'Funeral Announcement Fee',
    'featured_news'      => 'Featured Article',
    'featured_event'     => 'Featured Event',
    'featured_funeral'   => 'Featured Announcement',
    'mp_boost'           => 'Marketplace Boost',
    'mp_subscription'    => 'Seller Subscription',
    'mp_order'           => 'Marketplace Order',
    'delivery_subscription'  => 'Rider Premium Subscription',
    'delivery_sponsored'     => 'Rider Sponsored Listing',
    'delivery_verification'  => 'Rider Verification Badge',
    'delivery_commission'    => 'Delivery Commission',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>My Payments — AkuapemConnect</title>
    <link rel="stylesheet" href="assets/css/style.css" />
</head>
<body class="has-bottom-nav">
    <header class="app-topbar">
        <a href="jobs.php" class="button button-secondary button-small">← Back</a>
        <span class="brand">My Payments</span>
    </header>
    <main class="page-shell small-shell">

        <?php if (empty($payments)): ?>
            <div class="empty-state">You have no platform payment history yet.</div>

        <?php else: ?>

            <?php if (!empty($pending)): ?>
                <section class="panel">
                    <h2 style="margin-top:0;color:#f59e0b;">⏳ Pending Payments</h2>
                    <p class="meta" style="margin:0 0 14px;">These payments were started but not completed. You can continue or cancel them.</p>
                    <?php foreach ($pending as $p): ?>
                        <?php
                        $isPs       = ($p['gateway'] ?? 'manual') === 'paystack';
                        $ageMin     = (int)round((time() - strtotime($p['created_at'])) / 60);
                        $urlFresh   = !empty($p['authorization_url']) && $ageMin < 45;
                        ?>
                        <div style="border:2px solid #f59e0b;border-radius:12px;margin-bottom:14px;overflow:hidden;">
                            <!-- Header row -->
                            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;padding:14px;flex-wrap:wrap;">
                                <div>
                                    <div style="font-weight:800;font-size:.92rem;"><?php echo sanitize($typeLabel[$p['payment_type']] ?? $p['payment_type']); ?><?php if ($p['package_name'] && $p['package_name']!=='—'): ?> — <?php echo sanitize($p['package_name']); ?><?php endif; ?></div>
                                    <?php if ($p['job_title']): ?><div class="meta" style="margin-top:2px;">📋 <?php echo sanitize(mb_substr($p['job_title'],0,50)); ?></div><?php endif; ?>
                                    <div class="meta" style="font-size:.76rem;margin-top:4px;font-family:monospace;"><?php echo sanitize(strtoupper($p['reference_code'])); ?></div>
                                    <div class="meta" style="font-size:.72rem;"><?php echo time_ago($p['created_at']); ?></div>
                                </div>
                                <strong style="color:var(--primary);white-space:nowrap;font-size:1.05rem;">GH₵ <?php echo number_format($p['amount'], 2); ?></strong>
                            </div>
                            <!-- Action bar -->
                            <div style="background:#fef3c7;padding:10px 14px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                                <?php if ($isPs): ?>
                                <a href="resume_payment.php?id=<?php echo (int)$p['id']; ?>" class="button button-primary button-small" style="font-size:.82rem;">
                                    💳 <?php echo $urlFresh ? 'Continue Checkout →' : 'Restart Payment →'; ?>
                                </a>
                                <span style="font-size:.74rem;color:#92400e;flex:1;">
                                    <?php echo $urlFresh ? 'Your checkout session is still active.' : 'Session expired — a fresh link will be created.'; ?>
                                </span>
                                <?php else: ?>
                                <a href="resume_payment.php?id=<?php echo (int)$p['id']; ?>" class="button button-secondary button-small" style="font-size:.82rem;">
                                    📋 View Instructions
                                </a>
                                <span style="font-size:.74rem;color:#92400e;flex:1;">Transfer via MoMo/bank using the reference code above.</span>
                                <?php endif; ?>
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
                                    <?php if ($p['package_name']): ?>— <?php echo sanitize($p['package_name']); ?><?php endif; ?>
                                    <?php if ($p['job_title']): ?><br><span class="meta">📋 <?php echo sanitize(substr($p['job_title'], 0, 40)); ?></span><?php endif; ?>
                                    <br><span class="meta" style="font-size:0.8rem;">Ref: <?php echo sanitize($p['reference_code']); ?> · <?php echo sanitize(date('d M Y', strtotime($p['paid_at'] ?: $p['created_at']))); ?></span>
                                </div>
                                <div style="text-align:right;flex-shrink:0;">
                                    <strong style="color:var(--primary);">GH₵ <?php echo number_format($p['amount'], 2); ?></strong>
                                    <br><span class="status status-open" style="font-size:0.72rem;">PAID</span>
                                    <?php if (!empty($p['paystack_reference'])): ?>
                                        <br><a href="platform_receipt.php?ref=<?php echo urlencode($p['paystack_reference']); ?>" style="font-size:0.75rem;color:var(--primary);">View receipt</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <?php if (!empty($failed)): ?>
                <section class="panel">
                    <h2 style="margin-top:0;color:#c0392b;">✗ Failed / Abandoned</h2>
                    <p class="meta">These payments did not complete. Contact support if you believe this is an error.</p>
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
                                    <br><span class="status status-cancelled" style="font-size:0.72rem;"><?php echo strtoupper($p['status']); ?></span>
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
