<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/marketplace_functions.php';
require_once __DIR__ . '/paystack.php';

require_module_enabled('mp', 'Marketplace');
require_login();
$user = current_user();
$shop = get_shop_by_user((int)$user['id']);

if (!$shop) {
    flash('Create your shop first.', 'warning');
    header('Location: seller_dashboard.php?tab=setup');
    exit;
}

$formError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $method        = $_POST['method'] ?? '';
    $accountNumber = trim($_POST['account_number'] ?? '');
    $bankCode      = trim($_POST['bank_code'] ?? '');
    $makeDefault   = isset($_POST['is_default']);

    if (!in_array($method, ['momo', 'bank'], true)) {
        $formError = 'Invalid payout method.';
    } else {
        $bankStmt = $pdo->prepare('SELECT * FROM mp_banks WHERE code=? AND type=?');
        $bankStmt->execute([$bankCode, $method === 'bank' ? 'bank' : 'mobile_money']);
        $bankRow = $bankStmt->fetch();

        if ($accountNumber === '' || !$bankRow) {
            $formError = 'Select a ' . ($method === 'bank' ? 'bank' : 'network') . ' and enter a valid account number.';
        } else {
            $resolved    = paystack_resolve_account($accountNumber, $bankCode);
            $accountName = $resolved['success'] ? $resolved['account_name'] : trim($_POST['account_name'] ?? '');

            if (!$accountName) {
                $formError = 'Could not verify this account automatically (' . ($resolved['error'] ?? 'unknown error') . '). Enter the account holder name to save it anyway.';
            } else {
                $existing = $pdo->prepare('SELECT * FROM mp_payout_accounts WHERE shop_id=? AND method=?');
                $existing->execute([$shop['id'], $method]);
                $existing = $existing->fetch();

                // Keep the cached Paystack recipient only if the account details
                // are unchanged — otherwise it must be recreated for the new one.
                $recipientCode = null;
                if ($existing && $existing['account_number'] === $accountNumber && $existing['bank_code'] === $bankCode) {
                    $recipientCode = $existing['paystack_recipient_code'];
                }

                $pdo->prepare('INSERT INTO mp_payout_accounts (shop_id, method, account_name, account_number, bank_code, bank_name, paystack_recipient_code, is_default)
                    VALUES (?,?,?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE account_name=VALUES(account_name), account_number=VALUES(account_number),
                        bank_code=VALUES(bank_code), bank_name=VALUES(bank_name),
                        paystack_recipient_code=VALUES(paystack_recipient_code), is_default=VALUES(is_default), updated_at=NOW()')
                    ->execute([$shop['id'], $method, $accountName, $accountNumber, $bankCode, $bankRow['name'], $recipientCode, $makeDefault ? 1 : 0]);

                if ($makeDefault) {
                    $pdo->prepare('UPDATE mp_payout_accounts SET is_default=0 WHERE shop_id=? AND method<>?')
                        ->execute([$shop['id'], $method]);
                }

                log_audit_action((int)$user['id'], 'mp_payout_account_saved', "Shop #{$shop['id']} saved a {$method} payout account");
                flash('Payout account saved' . ($resolved['success'] ? " — verified as \"{$accountName}\"." : '.'), 'success');
                header('Location: seller_payout_accounts.php');
                exit;
            }
        }
    }
}

$momoNetworks = $pdo->query("SELECT * FROM mp_banks WHERE type='mobile_money' ORDER BY name")->fetchAll();
$banks        = $pdo->query("SELECT * FROM mp_banks WHERE type='bank' ORDER BY name")->fetchAll();

$accountsStmt = $pdo->prepare('SELECT * FROM mp_payout_accounts WHERE shop_id=?');
$accountsStmt->execute([$shop['id']]);
$momoAccount = null;
$bankAccount = null;
foreach ($accountsStmt->fetchAll() as $a) {
    if ($a['method'] === 'momo') $momoAccount = $a; else $bankAccount = $a;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payout Accounts — AkuapemConnect</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .pf-shell { max-width:680px; margin:0 auto; padding:20px 16px 80px; }
        .pf-section { background:var(--surface); border:1px solid var(--border); border-radius:14px; padding:18px; margin-bottom:14px; }
        .pf-section-title { font-size:.74rem; font-weight:800; text-transform:uppercase; letter-spacing:.07em; color:var(--text-muted,#6b7280); margin:0 0 14px; }
        label { font-weight:600; font-size:.86rem; display:block; margin-bottom:4px; }
        .form-group { margin-bottom:14px; }
        .form-hint { font-size:.74rem; color:var(--text-muted,#6b7280); margin-top:3px; }
        .pa-current { background:var(--primary-soft,#d1fae5); border-radius:10px; padding:10px 12px; margin-bottom:14px; font-size:.84rem; }
        .pa-current strong { display:block; margin-bottom:2px; }
        .pa-default-label { display:flex; align-items:center; gap:8px; font-weight:600; font-size:.86rem; cursor:pointer; margin-bottom:14px; }
        .pa-default-label input { width:auto; }
    </style>
</head>
<body class="has-bottom-nav">

<header class="app-topbar">
    <a href="seller_dashboard.php?tab=wallet" class="button button-secondary button-small">← Wallet</a>
    <span class="brand">Payout Accounts</span>
</header>

<main class="pf-shell">

    <?php if ($formError): ?><div class="alert alert-error"><?php echo sanitize($formError); ?></div><?php endif; ?>

    <!-- Mobile Money -->
    <div class="pf-section">
        <p class="pf-section-title">📱 Mobile Money</p>
        <?php if ($momoAccount): ?>
        <div class="pa-current">
            <strong><?php echo sanitize($momoAccount['account_name']); ?></strong>
            <?php echo sanitize($momoAccount['bank_name']); ?> — •••• <?php echo sanitize(substr($momoAccount['account_number'], -4)); ?>
            <?php echo $momoAccount['is_default'] ? ' · Default payout method' : ''; ?>
        </div>
        <?php endif; ?>
        <?php if (!$momoNetworks): ?>
        <p class="form-hint">No mobile money networks available yet — ask an admin to sync the bank list.</p>
        <?php else: ?>
        <form method="post" action="seller_payout_accounts.php">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="method" value="momo">
            <div class="form-group">
                <label for="momo_bank_code">Network</label>
                <select id="momo_bank_code" name="bank_code" required>
                    <option value="">— Select network —</option>
                    <?php foreach ($momoNetworks as $n): ?>
                    <option value="<?php echo sanitize($n['code']); ?>" <?php echo ($momoAccount && $momoAccount['bank_code'] === $n['code']) ? 'selected' : ''; ?>><?php echo sanitize($n['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="momo_account_number">Mobile Money Number</label>
                <input type="tel" id="momo_account_number" name="account_number" placeholder="e.g. 024xxxxxxx" value="<?php echo sanitize($momoAccount['account_number'] ?? ''); ?>" required>
            </div>
            <div class="form-group">
                <label for="momo_account_name">Account Holder Name <span class="form-hint">(only needed if we can't auto-verify)</span></label>
                <input type="text" id="momo_account_name" name="account_name" value="<?php echo sanitize($momoAccount['account_name'] ?? ''); ?>">
            </div>
            <label class="pa-default-label"><input type="checkbox" name="is_default" value="1" <?php echo ($momoAccount && $momoAccount['is_default']) ? 'checked' : ''; ?>> Use as default payout method</label>
            <button type="submit" class="button button-primary"><?php echo $momoAccount ? 'Update' : 'Save'; ?> Mobile Money</button>
        </form>
        <?php endif; ?>
    </div>

    <!-- Bank Account -->
    <div class="pf-section">
        <p class="pf-section-title">🏦 Bank Account</p>
        <?php if ($bankAccount): ?>
        <div class="pa-current">
            <strong><?php echo sanitize($bankAccount['account_name']); ?></strong>
            <?php echo sanitize($bankAccount['bank_name']); ?> — •••• <?php echo sanitize(substr($bankAccount['account_number'], -4)); ?>
            <?php echo $bankAccount['is_default'] ? ' · Default payout method' : ''; ?>
        </div>
        <?php endif; ?>
        <?php if (!$banks): ?>
        <p class="form-hint">No banks synced yet — ask an admin to sync the bank list from Paystack, or use Mobile Money for now.</p>
        <?php else: ?>
        <form method="post" action="seller_payout_accounts.php">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="method" value="bank">
            <div class="form-group">
                <label for="bank_code">Bank</label>
                <select id="bank_code" name="bank_code" required>
                    <option value="">— Select bank —</option>
                    <?php foreach ($banks as $b): ?>
                    <option value="<?php echo sanitize($b['code']); ?>" <?php echo ($bankAccount && $bankAccount['bank_code'] === $b['code']) ? 'selected' : ''; ?>><?php echo sanitize($b['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="bank_account_number">Account Number</label>
                <input type="text" id="bank_account_number" name="account_number" value="<?php echo sanitize($bankAccount['account_number'] ?? ''); ?>" required>
            </div>
            <div class="form-group">
                <label for="bank_account_name">Account Holder Name <span class="form-hint">(only needed if we can't auto-verify)</span></label>
                <input type="text" id="bank_account_name" name="account_name" value="<?php echo sanitize($bankAccount['account_name'] ?? ''); ?>">
            </div>
            <label class="pa-default-label"><input type="checkbox" name="is_default" value="1" <?php echo ($bankAccount && $bankAccount['is_default']) ? 'checked' : ''; ?>> Use as default payout method</label>
            <button type="submit" class="button button-primary"><?php echo $bankAccount ? 'Update' : 'Save'; ?> Bank Account</button>
        </form>
        <?php endif; ?>
    </div>

</main>

<?php require_once __DIR__ . '/partials/bottom_nav.php'; ?>
</body>
</html>
