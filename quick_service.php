<?php
/**
 * Quick Services — single service page: shows the right request form for
 * the service's service_type (data_bundle vs result_service) and, on
 * submit, creates a pending quick_transactions row and hands off to the
 * existing Paystack flow (initializePayment()) — no separate payment
 * implementation. Login required since a transaction must belong to a user.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/quick_service_functions.php';
require_once __DIR__ . '/paystack.php';

require_module_enabled('quick_services', 'Quick Services');
require_login();
$user = current_user();

$slug = trim($_GET['slug'] ?? '');
$service = $slug !== '' ? qs_get_service_by_slug($slug) : null;
if (!$service) {
    render_not_found('quick_services.php', 'Browse Quick Services', 'This service is no longer available.');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if ($service['service_type'] === 'data_bundle') {
        $bundleId  = (int)($_POST['bundle_id'] ?? 0);
        $recipient = trim($_POST['recipient'] ?? '');
        $bundle    = $bundleId ? qs_get_bundle($bundleId) : null;

        if (!$bundle) $errors[] = 'Please select a valid data bundle.';
        if (!class_exists('WhatsAppService', false)) require_once __DIR__ . '/services/WhatsAppService.php';
        $normalizedRecipient = WhatsAppService::normalizePhone($recipient);
        if (!$recipient || strlen($normalizedRecipient) < 12) $errors[] = 'Please enter a valid recipient phone number.';

        if (!$errors) {
            $amount = (float)$bundle['price'];
            $requestData = ['network' => $bundle['network_name'], 'bundle' => $bundle['label'], 'recipient' => $recipient];
            $summary = $bundle['network_name'] . ' ' . $bundle['label'] . ' for ' . $recipient;
        }
    } else { // result_service
        $optionId  = (int)($_POST['option_id'] ?? 0);
        $candidate = trim($_POST['candidate_name'] ?? '');
        $indexNo   = trim($_POST['index_number'] ?? '');
        $examYear  = trim($_POST['exam_year'] ?? '');
        $whatsapp  = trim($_POST['whatsapp_number'] ?? '');
        $option    = $optionId ? qs_get_option($optionId) : null;

        if (!$option) $errors[] = 'Please select a valid option.';
        if (!$candidate) $errors[] = 'Candidate name is required.';
        if (!$indexNo)   $errors[] = 'Index number is required.';
        if (!$examYear)  $errors[] = 'Examination year is required.';
        if (!$whatsapp)  $errors[] = 'WhatsApp number is required.';

        if (!$errors) {
            $amount = (float)$option['price'];
            $requestData = [
                'option' => $option['label'], 'candidate_name' => $candidate,
                'index_number' => $indexNo, 'exam_year' => $examYear, 'whatsapp_number' => $whatsapp,
            ];
            $summary = $option['label'] . ' — ' . $candidate . ' (' . $indexNo . ')';
        }
    }

    if (!$errors) {
        $serviceCharge = get_quick_service_charge($amount);
        $totalAmount = $amount + $serviceCharge;

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                "INSERT INTO quick_transactions (reference, user_id, service_id, amount, service_charge, customer_name, customer_phone, request_data)
                 VALUES ('', ?, ?, ?, ?, ?, ?, ?)"
            )->execute([
                $user['id'], $service['id'], $amount, $serviceCharge, $user['name'],
                $requestData['recipient'] ?? $requestData['whatsapp_number'] ?? null,
                json_encode($requestData),
            ]);
            $txId = (int)$pdo->lastInsertId();
            $reference = qs_reference($txId);
            $pdo->prepare('UPDATE quick_transactions SET reference=? WHERE id=?')->execute([$reference, $txId]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors[] = 'Something went wrong creating your request. Please try again.';
        }

        if (!$errors) {
            $result = initializePayment($user['id'], $user['email'], 'quick_service', $txId, 0, $totalAmount, ['summary' => $summary]);
            if (isset($result['error'])) {
                $pdo->prepare("UPDATE quick_transactions SET processing_status='cancelled' WHERE id=?")->execute([$txId]);
                $errors[] = $result['error'];
            } else {
                header('Location: ' . $result['checkout_url']);
                exit;
            }
        }
    }
}

$networks = $service['service_type'] === 'data_bundle' ? qs_get_networks_with_bundles() : [];
$options  = $service['service_type'] === 'result_service' ? qs_get_service_options((int)$service['id']) : [];

$qsChargeType  = get_platform_setting('qs_service_charge_type', 'flat');
$qsChargeValue = (float)get_platform_setting('qs_service_charge_value', '0');
$qsChargeCap   = (float)get_platform_setting('qs_service_charge_cap', '0');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php echo seo_meta([
        'title'       => $service['name'] . ' — ' . APP_NAME,
        'description' => $service['description'] ?: ($service['name'] . ' via ' . APP_NAME . '.'),
    ]); ?>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .qsd-shell { max-width:560px; margin:0 auto; padding:16px 16px 80px; }
        .qsd-hero { display:flex; align-items:center; gap:12px; background:var(--surface,#fff); border:1px solid var(--border,#e5e7eb); border-radius:var(--radius-lg,20px); padding:16px; margin-bottom:16px; }
        .qsd-hero .icon { font-size:2rem; }
        .qsd-hero h1 { font-size:1.1rem; font-weight:800; margin:0; }
        .qsd-hero p { font-size:.8rem; color:var(--muted,#6b7280); margin:2px 0 0; }
        .qsd-panel { background:var(--surface,#fff); border:1px solid var(--border,#e5e7eb); border-radius:var(--radius-lg,20px); padding:18px; margin-bottom:16px; }
        .qsd-section-title { font-size:.78rem; font-weight:800; text-transform:uppercase; letter-spacing:.06em; color:var(--muted,#6b7280); margin:0 0 12px; }
        .qsd-net-tabs { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:14px; }
        .qsd-net-tab { padding:8px 16px; border-radius:20px; border:1px solid var(--border,#e5e7eb); background:var(--surface-muted,#f1f7f3); font-size:.85rem; font-weight:700; cursor:pointer; }
        .qsd-net-tab.active { background:var(--primary,#2f8f5b); color:#fff; border-color:var(--primary,#2f8f5b); }
        .qsd-bundle-group { display:none; }
        .qsd-bundle-group.active { display:grid; grid-template-columns:repeat(auto-fill,minmax(120px,1fr)); gap:10px; }
        .qsd-option { position:relative; }
        .qsd-option input { position:absolute; opacity:0; }
        .qsd-option label { display:block; border:2px solid var(--border,#e5e7eb); border-radius:14px; padding:12px 10px; text-align:center; cursor:pointer; font-size:.86rem; font-weight:700; }
        .qsd-option input:checked + label { border-color:var(--primary,#2f8f5b); background:var(--primary-soft,#e4f4ea); color:var(--primary-dark,#246b45); }
        .qsd-option .price { display:block; font-size:.76rem; font-weight:900; margin-top:3px; }
        .qsd-result-options { display:flex; flex-direction:column; gap:10px; margin-bottom:4px; }
        .qsd-result-options label { display:flex; justify-content:space-between; align-items:center; border:2px solid var(--border,#e5e7eb); border-radius:14px; padding:12px 14px; cursor:pointer; font-size:.88rem; font-weight:600; }
        .qsd-result-options input { margin-right:10px; }
        .qsd-result-options input:checked ~ span.price,
        .qsd-result-options label:has(input:checked) { border-color:var(--primary,#2f8f5b); background:var(--primary-soft,#e4f4ea); }
        .qsd-field { margin-bottom:14px; }
        .qsd-field label { display:block; font-weight:600; font-size:.86rem; margin-bottom:4px; }
        .qsd-field input, .qsd-field select { width:100%; box-sizing:border-box; }
        .qsd-summary { background:var(--surface-muted,#f1f7f3); border-radius:14px; padding:14px; margin-bottom:16px; }
        .qsd-summary-row { display:flex; justify-content:space-between; font-size:.86rem; padding:4px 0; }
        .qsd-summary-row.total { font-weight:800; font-size:1rem; border-top:1px solid var(--border,#e5e7eb); margin-top:6px; padding-top:10px; }
    </style>
</head>
<body class="<?php echo $user ? 'has-bottom-nav' : ''; ?>">

<header class="app-topbar">
    <a href="quick_services.php" class="button button-secondary button-small">‹ Quick Services</a>
    <span class="brand"><?php echo sanitize($service['name']); ?></span>
</header>

<main class="qsd-shell">
    <div class="qsd-hero">
        <span class="icon"><?php echo sanitize($service['icon']) ?: '⚡'; ?></span>
        <div>
            <h1><?php echo sanitize($service['name']); ?></h1>
            <?php if ($service['description']): ?><p><?php echo sanitize($service['description']); ?></p><?php endif; ?>
        </div>
    </div>

    <?php foreach ($errors as $e): ?><div class="alert alert-error" style="margin-bottom:12px;"><?php echo sanitize($e); ?></div><?php endforeach; ?>

    <form method="post" id="qsd-form">
        <?php echo csrf_field(); ?>

        <?php if ($service['service_type'] === 'data_bundle'): ?>
        <div class="qsd-panel">
            <p class="qsd-section-title">📶 Network</p>
            <div class="qsd-net-tabs" id="qsd-net-tabs">
                <?php foreach ($networks as $i => $n): ?>
                <button type="button" class="qsd-net-tab<?php echo $i===0 ? ' active' : ''; ?>" data-network="<?php echo $n['id']; ?>" onclick="qsdShowNetwork(<?php echo $n['id']; ?>)"><?php echo sanitize($n['name']); ?></button>
                <?php endforeach; ?>
            </div>
            <p class="qsd-section-title">Select Bundle</p>
            <?php foreach ($networks as $i => $n): ?>
            <div class="qsd-bundle-group<?php echo $i===0 ? ' active' : ''; ?>" id="qsd-bundles-<?php echo $n['id']; ?>">
                <?php foreach ($n['bundles'] as $b): ?>
                <div class="qsd-option">
                    <input type="radio" name="bundle_id" value="<?php echo $b['id']; ?>" id="bundle-<?php echo $b['id']; ?>" data-price="<?php echo $b['price']; ?>" data-label="<?php echo sanitize($n['name'] . ' ' . $b['label']); ?>" onchange="qsdUpdateSummary()" required>
                    <label for="bundle-<?php echo $b['id']; ?>"><?php echo sanitize($b['label']); ?><span class="price">GH₵<?php echo number_format($b['price'],2); ?></span></label>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="qsd-panel">
            <div class="qsd-field">
                <label>Recipient Phone Number *</label>
                <input type="tel" name="recipient" placeholder="024XXXXXXX" required value="<?php echo sanitize($_POST['recipient'] ?? ''); ?>">
            </div>
        </div>

        <?php else: ?>
        <div class="qsd-panel">
            <p class="qsd-section-title">Choose an Option</p>
            <div class="qsd-result-options">
                <?php foreach ($options as $o): ?>
                <label>
                    <span><input type="radio" name="option_id" value="<?php echo $o['id']; ?>" data-price="<?php echo $o['price']; ?>" data-label="<?php echo sanitize($o['label']); ?>" onchange="qsdUpdateSummary()" required><?php echo sanitize($o['label']); ?></span>
                    <span class="price">GH₵<?php echo number_format($o['price'],2); ?></span>
                </label>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="qsd-panel">
            <p class="qsd-section-title">Candidate Information</p>
            <div class="qsd-field"><label>Candidate Name *</label><input type="text" name="candidate_name" required value="<?php echo sanitize($_POST['candidate_name'] ?? ''); ?>"></div>
            <div class="qsd-field"><label>Index Number *</label><input type="text" name="index_number" required value="<?php echo sanitize($_POST['index_number'] ?? ''); ?>"></div>
            <div class="qsd-field"><label>Examination Year *</label><input type="number" name="exam_year" min="2000" max="2100" required value="<?php echo sanitize($_POST['exam_year'] ?? date('Y')); ?>"></div>
            <div class="qsd-field"><label>WhatsApp Number *</label><input type="tel" name="whatsapp_number" placeholder="024XXXXXXX" required value="<?php echo sanitize($_POST['whatsapp_number'] ?? ''); ?>">
                <p class="meta" style="margin-top:4px;">Your result (or checker code) will be prepared for delivery to this number.</p>
            </div>
        </div>
        <?php endif; ?>

        <div class="qsd-summary" id="qsd-summary" style="display:none;">
            <div class="qsd-summary-row"><span id="qsd-summary-label"></span><span id="qsd-summary-price"></span></div>
            <div class="qsd-summary-row" id="qsd-summary-charge-row" style="display:none;"><span>Service Charge</span><span id="qsd-summary-charge"></span></div>
            <div class="qsd-summary-row total"><span>Total</span><span id="qsd-summary-total"></span></div>
        </div>

        <button type="submit" class="button button-primary" style="width:100%;padding:14px;">Pay Now</button>
    </form>
</main>

<script>
function qsdShowNetwork(id) {
    document.querySelectorAll('.qsd-bundle-group').forEach(function (el) { el.classList.remove('active'); });
    document.querySelectorAll('.qsd-net-tab').forEach(function (el) { el.classList.remove('active'); });
    document.getElementById('qsd-bundles-' + id).classList.add('active');
    document.querySelector('.qsd-net-tab[data-network="' + id + '"]').classList.add('active');
}
var qsdChargeType  = <?php echo json_encode($qsChargeType); ?>;
var qsdChargeValue = <?php echo json_encode($qsChargeValue); ?>;
var qsdChargeCap   = <?php echo json_encode($qsChargeCap); ?>;
function qsdComputeCharge(price) {
    var charge = qsdChargeType === 'percent' ? (price * qsdChargeValue / 100) : qsdChargeValue;
    if (qsdChargeCap > 0 && charge > qsdChargeCap) charge = qsdChargeCap;
    return Math.round(charge * 100) / 100;
}
function qsdUpdateSummary() {
    var checked = document.querySelector('input[name="bundle_id"]:checked, input[name="option_id"]:checked');
    if (!checked) return;
    var price = parseFloat(checked.dataset.price);
    var charge = qsdComputeCharge(price);
    document.getElementById('qsd-summary-label').textContent = checked.dataset.label;
    document.getElementById('qsd-summary-price').textContent = 'GH₵' + price.toFixed(2);
    var chargeRow = document.getElementById('qsd-summary-charge-row');
    if (charge > 0) {
        document.getElementById('qsd-summary-charge').textContent = 'GH₵' + charge.toFixed(2);
        chargeRow.style.display = 'flex';
    } else {
        chargeRow.style.display = 'none';
    }
    document.getElementById('qsd-summary-total').textContent = 'GH₵' + (price + charge).toFixed(2);
    document.getElementById('qsd-summary').style.display = 'block';
}
</script>

<?php require __DIR__ . '/partials/site_footer.php'; ?>
<?php if ($user): require_once __DIR__ . '/partials/bottom_nav.php'; endif; ?>
</body>
</html>
