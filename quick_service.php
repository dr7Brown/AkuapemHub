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

    // Pass 1: collect every raw submitted value first, so "Depends on" and
    // "Show only if" parent lookups work regardless of field order.
    $rawSubmitted = [];
    foreach ($formFields as $f) {
        $rawSubmitted[$f['key']] = trim($_POST['field'][$f['key']] ?? '');
    }

    // Pass 2: validate + build the final stored data. A field currently
    // hidden by a "Show only if" condition is skipped entirely — not
    // required, not validated, not stored — matching how a disabled form
    // control never gets submitted by the browser in the first place.
    $submitted = [];
    foreach ($formFields as $f) {
        $key = $f['key'];
        if (!empty($f['show_if']['field'])) {
            $parentVal = $rawSubmitted[$f['show_if']['field']] ?? '';
            if (!in_array($parentVal, $f['show_if']['values'] ?? [], true)) {
                continue;
            }
        }
        $val = $rawSubmitted[$key];
        if (!empty($f['required']) && $val === '') {
            $error = ($f['label'] ?? $key) . ' is required.';
            break;
        }
        if (($f['type'] ?? 'text') === 'select' && $val !== '') {
            if (!empty($f['depends_on']) && is_array($f['options'] ?? null)) {
                // Dependent select: valid options depend on which parent
                // value was submitted.
                $parentVal   = $rawSubmitted[$f['depends_on']] ?? '';
                $bucket      = $f['options'][$parentVal] ?? [];
                $validLabels = array_column($bucket, 'label');
                if (!in_array($val, $validLabels, true)) {
                    $error = 'Invalid value for ' . ($f['label'] ?? $key) . '.';
                    break;
                }
            } elseif (qs_field_is_priced_select($f)) {
                // Standalone priced select: valid options are this field's
                // own flat priced list.
                if (!in_array($val, array_column($f['options'], 'label'), true)) {
                    $error = 'Invalid value for ' . ($f['label'] ?? $key) . '.';
                    break;
                }
            } elseif (!empty($f['options']) && !in_array($val, $f['options'], true)) {
                $error = 'Invalid value for ' . ($f['label'] ?? $key) . '.';
                break;
            }
        }
        $submitted[$key] = mb_substr($val, 0, 500);
    }

    if (!$error && requires_verified_email('quick_service') && !is_email_verified()) {
        $error = 'Please verify your email address before requesting a service.';
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

$otherServicesStmt = $pdo->prepare("SELECT * FROM quick_services WHERE status='active' AND id != ? ORDER BY RAND() LIMIT 5");
$otherServicesStmt->execute([$service['id']]);
$otherServices = $otherServicesStmt->fetchAll();
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
        .qs-other-grid  { display:grid; grid-template-columns:repeat(auto-fill,minmax(140px,1fr)); gap:12px; margin-bottom:14px; }
        .qs-other-card  { background:var(--bg,#f9fafb); border:1px solid var(--border,#e5e7eb); border-radius:14px; padding:16px 12px; text-align:center; text-decoration:none; color:inherit; transition:box-shadow .15s,transform .15s; }
        .qs-other-card:hover { box-shadow:0 6px 24px rgba(0,0,0,.1); transform:translateY(-3px); }
        .qs-other-icon  { font-size:1.7rem; margin-bottom:6px; }
        .qs-other-icon img { width:34px; height:34px; border-radius:50%; object-fit:cover; }
        .qs-other-title { font-weight:700; font-size:.82rem; }
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
        <div class="rich-content" style="text-align:left;color:var(--text-muted,#6b7280);font-size:.88rem;"><?php echo render_rich($service['instructions']); ?></div>
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
            <?php $fieldLabelsByKey = array_column($formFields, 'label', 'key'); ?>
            <?php foreach ($formFields as $f):
                $key      = sanitize($f['key']);
                $type     = $f['type'] ?? 'text';
                $label    = sanitize($f['label'] ?? $f['key']);
                $required = !empty($f['required']);
                $isAmount = ($f['key'] ?? '') === ($service['amount_field_key'] ?? '');
                $inputId  = $isAmount ? 'qs-amount-field' : 'qs-' . $key;
            ?>
            <div class="qs-field"
                 <?php if (!empty($f['show_if']['field'])): ?>
                 data-show-if-field="<?php echo sanitize($f['show_if']['field']); ?>"
                 data-show-if-values='<?php echo htmlspecialchars(json_encode($f['show_if']['values'], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>'
                 <?php endif; ?>>
                <label for="<?php echo $inputId; ?>"><?php echo $label; ?><?php echo $required ? ' *' : ''; ?></label>
                <?php if ($type === 'select' && !empty($f['depends_on']) && is_array($f['options'] ?? null)): ?>
                <select id="<?php echo $inputId; ?>" name="field[<?php echo $key; ?>]"
                        <?php echo $required ? 'required' : ''; ?>
                        <?php echo $isAmount ? 'onchange="qsRecalc()"' : ''; ?>
                        data-depends-on="<?php echo sanitize($f['depends_on']); ?>"
                        data-options-map='<?php echo htmlspecialchars(json_encode($f['options'], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>'>
                    <option value="">Select <?php echo sanitize($fieldLabelsByKey[$f['depends_on']] ?? 'an option'); ?> first…</option>
                </select>
                <?php elseif ($type === 'select' && qs_field_is_priced_select($f)): ?>
                <select id="<?php echo $inputId; ?>" name="field[<?php echo $key; ?>]"
                        <?php echo $required ? 'required' : ''; ?>
                        <?php echo $isAmount ? 'onchange="qsRecalc()"' : ''; ?>>
                    <option value="">Select…</option>
                    <?php foreach ($f['options'] as $opt): ?>
                    <option value="<?php echo sanitize($opt['label']); ?>" data-price="<?php echo (float)($opt['price'] ?? 0); ?>"><?php echo sanitize($opt['label']); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php elseif ($type === 'select'): ?>
                <select id="<?php echo $inputId; ?>" name="field[<?php echo $key; ?>]" <?php echo $required ? 'required' : ''; ?>>
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

    <?php if ($otherServices): ?>
    <div class="qs-card" style="margin-top:28px;">
        <p class="qs-section-title">⚡ Other Quick Services</p>
        <div class="qs-other-grid">
            <?php foreach ($otherServices as $os): ?>
            <a href="quick_service.php?slug=<?php echo urlencode($os['slug']); ?>" class="qs-other-card">
                <div class="qs-other-icon"><?php if (!empty($os['image_path'])): ?><img src="<?php echo sanitize($os['image_path']); ?>" alt=""><?php else: ?><?php echo sanitize($os['icon']) ?: '⚡'; ?><?php endif; ?></div>
                <div class="qs-other-title"><?php echo sanitize($os['name']); ?></div>
            </a>
            <?php endforeach; ?>
        </div>
        <a href="quick_services.php" class="button button-secondary" style="width:100%;text-align:center;display:block;">View All Services →</a>
    </div>
    <?php endif; ?>
</main>

<script>
function qsAmountFromSelect(selectEl) {
    if (selectEl.dataset.dependsOn) {
        // Dependent (cascading): resolve via the embedded parent-keyed map.
        var map = {};
        try { map = JSON.parse(selectEl.dataset.optionsMap || '{}'); } catch (e) {}
        var parentEl = document.getElementById('qs-' + selectEl.dataset.dependsOn);
        var bucket = (parentEl && map[parentEl.value]) ? map[parentEl.value] : [];
        var match = bucket.filter(function (o) { return o.label === selectEl.value; })[0];
        return match ? (parseFloat(match.price) || 0) : 0;
    }
    // Standalone priced: the price lives directly on the selected <option>.
    var opt = selectEl.selectedOptions && selectEl.selectedOptions[0];
    return opt ? (parseFloat(opt.dataset.price) || 0) : 0;
}
function qsRecalc() {
    var form = document.getElementById('qs-form');
    var mode = form.dataset.pricingMode;
    var baseCost = parseFloat(form.dataset.baseCost) || 0;
    var feeType = form.dataset.feeType;
    var feeValue = parseFloat(form.dataset.feeValue) || 0;
    var amountField = document.getElementById('qs-amount-field');

    var amount = baseCost;
    if (mode === 'user_entered' && amountField) {
        amount = (amountField.tagName === 'SELECT')
            ? qsAmountFromSelect(amountField)
            : (parseFloat(amountField.value) || 0);
    }
    if (amount < 0) amount = 0;
    var fee = feeType === 'percent' ? (amount * feeValue / 100) : feeValue;
    var total = amount + fee;

    document.getElementById('qs-amount-out').textContent = 'GH₵ ' + amount.toFixed(2);
    document.getElementById('qs-fee-out').textContent = 'GH₵ ' + fee.toFixed(2);
    document.getElementById('qs-total-out').textContent = 'GH₵ ' + total.toFixed(2);
}
function qsEsc(s) {
    var d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
}
/** Wires every "depends on" select (e.g. Data Package, depending on
 *  Network) to repopulate its own options whenever its parent changes. */
function qsWireDependentSelects() {
    document.querySelectorAll('#qs-form select[data-depends-on]').forEach(function (childSel) {
        var parentSel = document.getElementById('qs-' + childSel.dataset.dependsOn);
        if (!parentSel) return;
        var map = {};
        try { map = JSON.parse(childSel.dataset.optionsMap || '{}'); } catch (e) {}

        function repopulate() {
            var bucket = map[parentSel.value] || [];
            var html = '<option value="">Select…</option>';
            bucket.forEach(function (o) {
                html += '<option value="' + qsEsc(o.label) + '">' + qsEsc(o.label) + '</option>';
            });
            childSel.innerHTML = html;
            childSel.disabled = bucket.length === 0;
            if (childSel.id === 'qs-amount-field') qsRecalc();
        }
        parentSel.addEventListener('change', repopulate);
        repopulate();
    });
}
/** Shows/hides every field with a "Show only if" condition, based on
 *  whichever select field it's gated on — independent of the "Depends on"
 *  cascading-options mechanism, and works for any field type (text,
 *  number, select, textarea…). A hidden field's control is disabled so
 *  the browser excludes it from submission entirely (matching the
 *  server-side skip in the POST handler) and so it's exempt from the
 *  "required" constraint while hidden. */
function qsEvaluateShowIf() {
    document.querySelectorAll('#qs-form .qs-field[data-show-if-field]').forEach(function (wrapper) {
        var parentEl = document.getElementById('qs-' + wrapper.dataset.showIfField);
        var values = [];
        try { values = JSON.parse(wrapper.dataset.showIfValues || '[]'); } catch (e) {}
        var show = !!parentEl && values.indexOf(parentEl.value) !== -1;
        wrapper.style.display = show ? '' : 'none';
        var control = wrapper.querySelector('input, select, textarea');
        if (control) {
            control.disabled = !show;
            if (control.id === 'qs-amount-field') qsRecalc();
        }
    });
}
qsWireDependentSelects();
document.querySelectorAll('#qs-form select').forEach(function (sel) {
    sel.addEventListener('change', qsEvaluateShowIf);
});
qsEvaluateShowIf();
qsRecalc();
</script>

<?php require __DIR__ . '/partials/site_footer.php'; ?>
<?php require_once __DIR__ . '/partials/bottom_nav.php'; ?>
</body>
</html>
