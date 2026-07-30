<?php
/**
 * Let a delivery agent pay off their commission balance via Paystack.
 * Full-balance settlement only — mirrors the existing admin "Mark Settled"
 * action, which always clears the whole balance in one go.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/delivery_functions.php';
require_once __DIR__ . '/paystack.php';

require_module_enabled('delivery', 'Delivery Services');
require_login();
$user         = current_user();
$agentProfile = get_delivery_agent_for_user((int)$user['id']);

if (!$agentProfile) {
    flash('You must be a delivery agent to pay commission.', 'warning');
    header('Location: delivery_agent_jobs.php');
    exit;
}

$commissionOwed = (float)($agentProfile['commission_owed'] ?? 0);
if ($commissionOwed <= 0) {
    flash('You have no commission owed.', 'info');
    header('Location: delivery_agent_jobs.php?tab=earnings');
    exit;
}

$flash = get_flash();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $result = initializePayment(
        (int)$user['id'], $user['email'],
        'delivery_commission', $agentProfile['id'], 0, $commissionOwed,
        ['agent_id' => $agentProfile['id']]
    );

    if (!empty($result['error'])) {
        $error = $result['error'];
    } else {
        header('Location: ' . $result['checkout_url']);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pay Commission — AkuapemConnect</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .pc-shell { max-width:480px; margin:0 auto; padding:20px 16px 80px; }
        .pc-card  { background:var(--surface); border:1px solid var(--border); border-radius:14px; padding:22px; margin-bottom:16px; text-align:center; }
        .pc-amount { font-size:2rem; font-weight:900; color:#c0392b; margin:8px 0; }
        .pc-label  { font-size:.8rem; color:var(--text-muted,#6b7280); font-weight:700; text-transform:uppercase; letter-spacing:.05em; }
    </style>
</head>
<body class="has-bottom-nav">

<header class="app-topbar">
    <a href="delivery_agent_jobs.php?tab=earnings" class="button button-secondary button-small">← Back</a>
    <span class="brand">💳 Pay Commission</span>
</header>

<?php if ($flash): ?><div class="alert alert-<?php echo sanitize($flash['type']); ?>" style="margin:10px 16px 0;"><?php echo sanitize($flash['message']); ?></div><?php endif; ?>

<main class="pc-shell">

    <div class="pc-card">
        <p class="pc-label">Commission Owed</p>
        <p class="pc-amount">GH&#8373; <?php echo number_format($commissionOwed, 2); ?></p>
        <p style="font-size:.84rem;color:var(--text-muted,#6b7280);margin:0;">
            This clears your full balance and restores your ability to accept new delivery jobs immediately.
        </p>
    </div>

    <?php if ($error): ?><div class="alert alert-error"><?php echo sanitize($error); ?></div><?php endif; ?>

    <form method="post" action="pay_delivery_commission.php">
        <?php echo csrf_field(); ?>
        <button type="submit" class="button button-primary" style="width:100%;font-size:1rem;padding:14px;">
            🔒 Pay GH&#8373; <?php echo number_format($commissionOwed, 2); ?> via Paystack
        </button>
        <p style="font-size:.74rem;color:var(--muted,#6b7280);text-align:center;margin-top:8px;">Secure checkout · Card &amp; Mobile Money</p>
    </form>

</main>

<?php require_once __DIR__ . '/partials/bottom_nav.php'; ?>
</body>
</html>
