<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/paystack.php';

require_module_enabled('quick_services', 'Quick Services');
require_login();
$user = current_user();

$reqId = (int)($_GET['id'] ?? 0);
if (!$reqId) { header('Location: my_quick_services.php'); exit; }

$stmt = $pdo->prepare("SELECT qsr.*, qs.name AS service_name, qs.icon, qs.image_path
    FROM quick_service_requests qsr
    JOIN quick_services qs ON qs.id = qsr.service_id
    WHERE qsr.id = ? AND qsr.user_id = ? LIMIT 1");
$stmt->execute([$reqId, $user['id']]);
$request = $stmt->fetch();

if (!$request) {
    flash('Request not found.', 'error');
    header('Location: my_quick_services.php'); exit;
}

if ($request['status'] !== 'pending_payment') {
    flash('This request is not awaiting payment.', 'info');
    header('Location: my_quick_services.php'); exit;
}

$existing = $pdo->prepare("SELECT id, reference_code FROM platform_payments WHERE user_id = ? AND payment_type = 'quick_service' AND reference_id = ? AND status = 'pending' LIMIT 1");
$existing->execute([$user['id'], $reqId]);
$existingPay = $existing->fetch();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if ($existingPay) {
        flash('A payment is already in progress for this request. Complete it or wait for it to expire.', 'info');
        header('Location: my_payments.php'); exit;
    }
    $result = initializePayment(
        $user['id'], $user['email'], 'quick_service', $reqId, 0, (float)$request['total_amount'],
        ['service_name' => $request['service_name']]
    );
    if (isset($result['error'])) {
        $error = $result['error'];
    } else {
        header('Location: ' . $result['checkout_url']); exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pay — <?php echo sanitize($request['service_name']); ?> — <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-bottom-nav">
    <header class="app-topbar">
        <a href="my_quick_services.php" class="button button-secondary button-small">← My Services</a>
        <span class="brand">Payment</span>
    </header>
    <main class="page-shell small-shell">
        <?php foreach (get_flashes() as $f): ?>
        <div class="alert alert-<?php echo sanitize($f['type']); ?>"><?php echo sanitize($f['message']); ?></div>
        <?php endforeach; ?>
        <?php if ($error): ?>
        <div class="alert alert-error"><?php echo sanitize($error); ?></div>
        <?php endif; ?>

        <div class="card">
            <h2 style="margin-top:0;">
                <?php if (!empty($request['image_path'])): ?>
                <img src="<?php echo sanitize($request['image_path']); ?>" alt="" style="width:28px;height:28px;border-radius:50%;object-fit:cover;vertical-align:middle;margin-right:4px;">
                <?php else: ?>
                <?php echo sanitize($request['icon']) ?: '⚡'; ?>
                <?php endif; ?>
                <?php echo sanitize($request['service_name']); ?>
            </h2>
            <p style="color:var(--muted);font-size:.9rem;">Ref: <?php echo qs_reference((int)$request['id']); ?></p>

            <div style="padding:12px;background:var(--surface);border-radius:8px;margin-bottom:20px;border:1px solid var(--border);font-size:.86rem;">
                <div style="display:flex;justify-content:space-between;padding:3px 0;"><span>Service Cost</span><span>GH₵ <?php echo number_format((float)$request['service_amount'], 2); ?></span></div>
                <div style="display:flex;justify-content:space-between;padding:3px 0;"><span>AkuapemConnect Service Fee</span><span>GH₵ <?php echo number_format((float)$request['service_fee'], 2); ?></span></div>
            </div>

            <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:10px;padding:16px 18px;margin-bottom:20px;text-align:center;">
                <p style="margin:0;font-size:.85rem;color:#166534;">Total to pay</p>
                <p style="margin:4px 0 0;font-size:1.6rem;font-weight:900;color:#15803d;">GH₵ <?php echo number_format((float)$request['total_amount'], 2); ?></p>
            </div>

            <?php if ($existingPay): ?>
            <div class="alert alert-info">
                A Paystack payment is already in progress for this request (ref: <?php echo sanitize($existingPay['reference_code']); ?>).
                <br><a href="my_payments.php" style="color:var(--primary);">Track your payment →</a>
            </div>
            <?php else: ?>
            <form method="post">
                <?php echo csrf_field(); ?>
                <button type="submit" class="button button-primary" style="width:100%;padding:14px;font-size:1rem;">
                    🔒 Pay GH₵ <?php echo number_format((float)$request['total_amount'], 2); ?> with Paystack
                </button>
            </form>
            <p style="font-size:.76rem;color:var(--muted);text-align:center;margin-top:10px;">Card · Mobile Money · Secure checkout</p>
            <?php endif; ?>
        </div>
    </main>
    <?php $activeNav = 'community'; require __DIR__ . '/partials/bottom_nav.php'; ?>
</body>
</html>
