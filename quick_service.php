<?php
/**
 * Quick Services — a single service's intro page + dynamic request form.
 * The form itself is generated from quick_services.form_fields (admin-
 * configured JSON), so a brand-new service needs no code changes here.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_module_enabled('quick_services', 'Quick Services');
require_login();

$user = current_user();
$slug = trim($_GET['slug'] ?? '');

$stmt = $pdo->prepare("SELECT * FROM quick_services WHERE slug = ? AND status = 'active' LIMIT 1");
$stmt->execute([$slug]);
$service = $stmt->fetch();

if (!$service) {
    flash('That service is not available right now.', 'error');
    header('Location: quick_services.php');
    exit;
}

$formFields = json_decode($service['form_fields'], true) ?: [];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $submitted = [];
    foreach ($formFields as $f) {
        $key = $f['key'];
        $val = trim($_POST['field'][$key] ?? '');
        if (!empty($f['required']) && $val === '') {
            $error = ($f['label'] ?? $key) . ' is required.';
            break;
        }
        if (($f['type'] ?? 'text') === 'select' && $val !== '' && !empty($f['options']) && !in_array($val, $f['options'], true)) {
            $error = 'Invalid value for ' . ($f['label'] ?? $key) . '.';
            break;
        }
        $submitted[$key] = mb_substr($val, 0, 500);
    }

    if (!$error) {
        $pricing = qs_compute_pricing($service, $submitted);
        if ($pricing['total'] <= 0) {
            $error = 'The total amount must be greater than zero.';
        }
    }

    if (!$error) {
        $pdo->prepare('INSERT INTO quick_service_requests
            (user_id, service_id, request_data, service_amount, service_fee, total_amount, status)
            VALUES (?,?,?,?,?,?,\'pending_payment\')')
            ->execute([
                $user['id'], $service['id'], json_encode($submitted, JSON_UNESCAPED_UNICODE),
                $pricing['service_amount'], $pricing['service_fee'], $pricing['total'],
            ]);
        $requestId = (int)$pdo->lastInsertId();
        header('Location: pay_quick_service.php?id=' . $requestId);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo sanitize($service['name']); ?> — <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .qs-shell { max-width: 640px; margin: 0 auto; padding: 16px 16px 80px; }
        .qs-hero  { background:var(--surface); border:1px solid var(--border); border-radius:14px; padding:20px; margin-bottom:14px; text-align:center; }
        .qs-hero-icon { font-size:2.4rem; margin-bottom:6px; }
        .qs-hero-icon img { width:56px; height:56px; border-radius:50%; object-fit:cover; }
        .qs-card  { background:var(--surface); border:1px solid var(--border); border-radius:14px; padding:18px; margin-bottom:14px; }
        .qs-section-title { font-size:.75rem; font-weight:800; text-transform:uppercase; letter-spacing:.07em; color:var(--text-muted,#6b7280); margin:0 0 14px; }
        .qs-field { margin-bottom:14px; }
        .qs-field label { font-weight:600; font-size:.86rem; display:block; margin-bottom:4px; }
        .qs-field input, .qs-field select, .qs-field textarea { width:100%; box-sizing:border-box; padding:9px 11px; border:1px solid var(--border); border-radius:8px; font-size:.9rem; }
        .qs-field textarea { resize:vertical; min-height:70px; }
        .qs-pricing { background:#f0fdf4; border:1px solid #86efac; border-radius:10px; padding:14px 16px; margin-bottom:16px; }
        .qs-pricing-row { display:flex; justify-content:space-between; font-size:.86rem; color:#166534; padding:3px 0; }
        .qs-pricing-row.total { font-weight:800; font-size:1.05rem; color:#15803d; border-top:1px dashed #86efac; margin-top:6px; padding-top:8px; }
    </style>
</head>
<body class="has-bottom-nav">

<header class="app-topbar">
    <a href="quick_services.php" class="button button-secondary button-small">← Services</a>
    <span class="brand"><?php echo sanitize($service['name']); ?></span>
</header>

<main class="qs-shell">
    <?php if ($error): ?><div class="alert alert-error"><?php echo sanitize($error); ?></div><?php endif; ?>

    <div class="qs-hero">
        <div class="qs-hero-icon"><?php if (!empty($service['image_path'])): ?><img src="<?php echo sanitize($service['image_path']); ?>" alt=""><?php else: ?><?php echo sanitize($service['icon']) ?: '⚡'; ?><?php endif; ?></div>
        <h2 style="margin:0 0 6px;"><?php echo sanitize($service['name']); ?></h2>
        <?php if ($service['instructions']): ?>
        <p style="margin:0;color:var(--text-muted,#6b7280);font-size:.88rem;"><?php echo sanitize($service['instructions']); ?></p>
        <?php endif; ?>
    </div>

    <form method="post" id="qs-form"
          data-pricing-mode="<?php echo sanitize($service['pricing_mode']); ?>"
          data-base-cost="<?php echo (float)$service['base_cost']; ?>"
          data-fee-type="<?php echo sanitize($service['service_fee_type']); ?>"
          data-fee-value="<?php echo (float)$service['service_fee_value']; ?>"
          data-amount-field="<?php echo sanitize($service['amount_field_key'] ?? ''); ?>">
        <?php echo csrf_field(); ?>

        <div class="qs-card">
            <p class="qs-section-title">📝 Request Details</p>
            <?php foreach ($formFields as $f):
                $key      = sanitize($f['key']);
                $type     = $f['type'] ?? 'text';
                $label    = sanitize($f['label'] ?? $f['key']);
                $required = !empty($f['required']);
                $isAmount = ($f['key'] ?? '') === ($service['amount_field_key'] ?? '');
                $inputId  = $isAmount ? 'qs-amount-field' : 'qs-' . $key;
            ?>
            <div class="qs-field">
                <label for="<?php echo $inputId; ?>"><?php echo $label; ?><?php echo $required ? ' *' : ''; ?></label>
                <?php if ($type === 'select'): ?>
                <select id="qs-<?php echo $key; ?>" name="field[<?php echo $key; ?>]" <?php echo $required ? 'required' : ''; ?>>
                    <option value="">Select…</option>
                    <?php foreach (($f['options'] ?? []) as $opt): ?>
                    <option value="<?php echo sanitize($opt); ?>"><?php echo sanitize($opt); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php elseif ($type === 'textarea'): ?>
                <textarea id="qs-<?php echo $key; ?>" name="field[<?php echo $key; ?>]" <?php echo $required ? 'required' : ''; ?> placeholder="<?php echo sanitize($f['placeholder'] ?? ''); ?>"></textarea>
                <?php else: ?>
                <input type="<?php echo in_array($type, ['text','number','tel','password'], true) ? $type : 'text'; ?>"
                       id="<?php echo $isAmount ? 'qs-amount-field' : 'qs-' . $key; ?>" name="field[<?php echo $key; ?>]"
                       <?php echo $type === 'number' ? 'min="0" step="0.01"' : ''; ?>
                       <?php echo $isAmount ? 'oninput="qsRecalc()"' : ''; ?>
                       <?php echo $required ? 'required' : ''; ?>
                       placeholder="<?php echo sanitize($f['placeholder'] ?? ''); ?>">
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="qs-pricing">
            <div class="qs-pricing-row"><span>Service Cost</span><span id="qs-amount-out">GH₵ <?php echo number_format((float)$service['base_cost'], 2); ?></span></div>
            <div class="qs-pricing-row"><span>AkuapemConnect Service Fee</span><span id="qs-fee-out">GH₵ 0.00</span></div>
            <div class="qs-pricing-row total"><span>Total</span><span id="qs-total-out">GH₵ 0.00</span></div>
        </div>

        <button type="submit" class="button button-primary" style="width:100%;padding:14px;font-size:1rem;">Continue to Payment →</button>
    </form>
</main>

<script>
function qsRecalc() {
    var form = document.getElementById('qs-form');
    var mode = form.dataset.pricingMode;
    var baseCost = parseFloat(form.dataset.baseCost) || 0;
    var feeType = form.dataset.feeType;
    var feeValue = parseFloat(form.dataset.feeValue) || 0;
    var amountField = document.getElementById('qs-amount-field');

    var amount = (mode === 'user_entered' && amountField) ? (parseFloat(amountField.value) || 0) : baseCost;
    if (amount < 0) amount = 0;
    var fee = feeType === 'percent' ? (amount * feeValue / 100) : feeValue;
    var total = amount + fee;

    document.getElementById('qs-amount-out').textContent = 'GH₵ ' + amount.toFixed(2);
    document.getElementById('qs-fee-out').textContent = 'GH₵ ' + fee.toFixed(2);
    document.getElementById('qs-total-out').textContent = 'GH₵ ' + total.toFixed(2);
}
qsRecalc();
</script>

<?php require_once __DIR__ . '/partials/bottom_nav.php'; ?>
</body>
</html>
