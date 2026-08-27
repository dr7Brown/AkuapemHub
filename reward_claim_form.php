<?php
/**
 * Claim form for a single milestone — collects reward-type-specific details,
 * shows a confirmation summary, then submits to create_reward_claim() which
 * re-validates everything server-side and locks points atomically. Never
 * trusts milestone_id/points/reward_value from the client beyond looking up
 * the real row.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/modules/referrals/service.php';
require_once __DIR__ . '/modules/rewards/service.php';

require_login();
$user = current_user();

$milestoneId = (int)($_GET['id'] ?? $_POST['milestone_id'] ?? 0);
if (!$milestoneId) { header('Location: my_rewards.php'); exit; }

$check = evaluate_claim_eligibility((int)$user['id'], $milestoneId);
if (!$check['ok']) {
    flash($check['error'], 'error');
    header('Location: my_rewards.php');
    exit;
}
$milestone = $check['milestone'];
$balance   = $check['balance'];

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'submit') {
    csrf_check();

    $rawDetails = $_POST['details'] ?? [];
    $details = [];
    foreach ($rawDetails as $k => $v) {
        $k = preg_replace('/[^a-z0-9_]/i', '', (string)$k);
        $v = trim((string)$v);
        if ($k !== '' && $v !== '') $details[$k] = mb_substr($v, 0, 255);
    }

    // Minimal required-field validation per reward type — kept intentionally
    // light (this is claim metadata, not a payment gateway); real validation
    // of payout details happens when an admin reviews the claim.
    $requiredByType = [
        'cash'          => ['mobile_network', 'mobile_number', 'account_name'],
        'airtime'       => ['network', 'phone_number'],
        'data'          => ['network', 'phone_number', 'data_package'],
        'physical_item' => ['phone_number', 'delivery_method'],
    ];
    $required = $requiredByType[$milestone['reward_type']] ?? [];
    $missing = array_diff($required, array_keys($details));

    if ($missing) {
        $error = 'Please fill in all required fields.';
    } else {
        $result = create_reward_claim((int)$user['id'], $milestoneId, $details);
        if ($result['ok']) {
            flash('Reward claim ' . $result['reference'] . ' submitted! ' . number_format((int)$milestone['required_points']) . ' points have been reserved pending review.', 'success');
            header('Location: my_reward_claims.php?ref=' . $result['reference']);
            exit;
        }
        $error = $result['error'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Claim Reward — AkuapemConnect</title>
    <link rel="stylesheet" href="assets/css/style.css" />
    <style>
        .cf-summary { background:var(--surface-muted); border:1px solid var(--border); border-radius:var(--radius-sm); padding:16px; margin-bottom:18px; }
        .cf-summary dt { font-size:0.72rem; color:var(--muted); text-transform:uppercase; margin-top:8px; }
        .cf-summary dt:first-child { margin-top:0; }
        .cf-summary dd { margin:2px 0 0; font-weight:600; }
        .cf-warn { background:#fef3c7; border:1px solid #f59e0b; color:#92400e; border-radius:var(--radius-sm); padding:12px 14px; font-size:0.86rem; margin-bottom:16px; }
        label { font-weight:600; font-size:0.86rem; display:block; margin:12px 0 4px; }
        .form-group input, .form-group select { width:100%; padding:9px 12px; border:1px solid var(--border); border-radius:6px; background:var(--surface); color:var(--text); box-sizing:border-box; }
    </style>
</head>
<body class="has-bottom-nav">
    <header class="app-topbar">
        <a href="my_rewards.php" class="brand" style="text-decoration:none;">‹ My Rewards</a>
        <span style="font-weight:600;">Claim Reward</span>
    </header>
    <main class="page-shell small-shell" style="padding-bottom:80px;">
        <?php if ($error): ?><div class="alert alert-error"><?php echo sanitize($error); ?></div><?php endif; ?>

        <div class="cf-summary">
            <dt>Reward</dt><dd><?php echo sanitize($milestone['reward_description']); ?></dd>
            <dt>Points Required</dt><dd><?php echo number_format((int)$milestone['required_points']); ?> points</dd>
            <dt>Your Available Points</dt><dd><?php echo number_format($balance); ?> points</dd>
            <dt>After Claim</dt><dd><?php echo number_format($balance - (int)$milestone['required_points']); ?> points</dd>
        </div>

        <div class="cf-warn">⚠️ <?php echo number_format((int)$milestone['required_points']); ?> points will be reserved when you submit this claim. They are returned automatically if the claim is rejected, and permanently used once it's fulfilled.</div>

        <form method="post" class="card form-card">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="step" value="submit">
            <input type="hidden" name="milestone_id" value="<?php echo $milestoneId; ?>">

            <?php
            // Retain whatever the user typed if the form is being re-shown after a
            // validation error, instead of making them re-type everything.
            $old = fn($k, $default = '') => sanitize($_POST['details'][$k] ?? $default);
            $oldSel = fn($k, $v) => $old($k) === $v ? 'selected' : '';
            ?>
            <?php switch ($milestone['reward_type']):
                case 'cash': ?>
                <div class="form-group"><label>Mobile Network *</label>
                    <select name="details[mobile_network]" required>
                        <option value="">Select network…</option>
                        <option <?php echo $oldSel('mobile_network','MTN'); ?>>MTN</option>
                        <option <?php echo $oldSel('mobile_network','Telecel'); ?>>Telecel</option>
                        <option <?php echo $oldSel('mobile_network','AirtelTigo'); ?>>AirtelTigo</option>
                    </select>
                </div>
                <div class="form-group"><label>Mobile Money Number *</label><input type="tel" name="details[mobile_number]" required placeholder="024XXXXXXX" value="<?php echo $old('mobile_number'); ?>"></div>
                <div class="form-group"><label>Account Name *</label><input type="text" name="details[account_name]" required value="<?php echo $old('account_name', $user['name']); ?>"></div>
                <?php break;
                case 'airtime': ?>
                <div class="form-group"><label>Network *</label>
                    <select name="details[network]" required>
                        <option value="">Select network…</option>
                        <option <?php echo $oldSel('network','MTN'); ?>>MTN</option>
                        <option <?php echo $oldSel('network','Telecel'); ?>>Telecel</option>
                        <option <?php echo $oldSel('network','AirtelTigo'); ?>>AirtelTigo</option>
                    </select>
                </div>
                <div class="form-group"><label>Phone Number *</label><input type="tel" name="details[phone_number]" required placeholder="024XXXXXXX" value="<?php echo $old('phone_number'); ?>"></div>
                <?php break;
                case 'data': ?>
                <div class="form-group"><label>Network *</label>
                    <select name="details[network]" required>
                        <option value="">Select network…</option>
                        <option <?php echo $oldSel('network','MTN'); ?>>MTN</option>
                        <option <?php echo $oldSel('network','Telecel'); ?>>Telecel</option>
                        <option <?php echo $oldSel('network','AirtelTigo'); ?>>AirtelTigo</option>
                    </select>
                </div>
                <div class="form-group"><label>Phone Number *</label><input type="tel" name="details[phone_number]" required placeholder="024XXXXXXX" value="<?php echo $old('phone_number'); ?>"></div>
                <div class="form-group"><label>Preferred Data Package *</label><input type="text" name="details[data_package]" required placeholder="e.g. 5GB" value="<?php echo $old('data_package'); ?>"></div>
                <?php break;
                case 'physical_item': ?>
                <div class="form-group"><label>Size / Variant (if applicable)</label><input type="text" name="details[variant]" placeholder="Optional" value="<?php echo $old('variant'); ?>"></div>
                <div class="form-group"><label>Pickup or Delivery *</label>
                    <select name="details[delivery_method]" required>
                        <option value="">Select…</option>
                        <option value="pickup" <?php echo $oldSel('delivery_method','pickup'); ?>>Pickup</option>
                        <option value="delivery" <?php echo $oldSel('delivery_method','delivery'); ?>>Delivery</option>
                    </select>
                </div>
                <div class="form-group"><label>Phone Number *</label><input type="tel" name="details[phone_number]" required value="<?php echo $old('phone_number', $user['phone'] ?? ''); ?>"></div>
                <div class="form-group"><label>Delivery Address (if delivery)</label><input type="text" name="details[delivery_address]" placeholder="Optional if picking up" value="<?php echo $old('delivery_address'); ?>"></div>
                <?php break;
                default: ?>
                <div class="form-group"><label>Additional Notes (optional)</label><input type="text" name="details[notes]" placeholder="Anything the admin should know" value="<?php echo $old('notes'); ?>"></div>
                <?php endswitch; ?>

            <button type="submit" class="button button-primary" style="width:100%;margin-top:18px;">CONFIRM CLAIM</button>
            <a href="my_rewards.php" class="button button-secondary" style="width:100%;margin-top:8px;text-align:center;display:block;">CANCEL</a>
        </form>
    </main>
    <?php require __DIR__ . '/partials/bottom_nav.php'; ?>
</body>
</html>
