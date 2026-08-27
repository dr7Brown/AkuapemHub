<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/marketplace_functions.php';
require_once __DIR__ . '/paystack.php';

require_module_enabled('mp', 'Marketplace');
require_login();
$user = current_user();
$shop = get_active_seller_shop((int)$user['id']);

if (!$shop) {
    flash('Create your shop first.', 'warning');
    header('Location: seller_dashboard.php?tab=setup');
    exit;
}

$formError = '';

// Master switch + per-shop allowlist, both admin-controlled from
// admin/mp_payouts.php → Fast Payout tab. Section is hidden entirely — not
// just disabled — unless both are true.
$fastPayoutModuleEnabled    = get_platform_setting('mp_fast_payout_module_enabled', '0') === '1';
$fastPayoutRequiresApproval = get_platform_setting('mp_fast_payout_requires_approval', '1') === '1';
$fastPayoutVisible          = $fastPayoutModuleEnabled && (bool)$shop['fast_payout_eligible'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fast_payout_action'])) {
    csrf_check();

    if (!$fastPayoutVisible) {
        flash('Fast Payout is not available for this shop.', 'error');
        header('Location: seller_payout_accounts.php');
        exit;
    }

    if ($_POST['fast_payout_action'] === 'enable') {
        if ($shop['fast_payout_enabled']) {
            flash('Fast Payout is already enabled.', 'info');
        } elseif ($shop['fast_payout_requested_at']) {
            flash('Your Fast Payout request is already pending admin approval.', 'info');
        } else {
            $acctStmt = $pdo->prepare('SELECT * FROM mp_payout_accounts WHERE shop_id=? AND is_default=1');
            $acctStmt->execute([$shop['id']]);
            $account = $acctStmt->fetch();
            if (!$account) {
                $allStmt = $pdo->prepare('SELECT * FROM mp_payout_accounts WHERE shop_id=?');
                $allStmt->execute([$shop['id']]);
                $all = $allStmt->fetchAll();
                if (count($all) === 1) $account = $all[0];
            }

            if (!$account) {
                flash('Save a payout account above and mark it as default before enabling Fast Payout.', 'error');
            } elseif ($fastPayoutRequiresApproval) {
                mp_request_fast_payout((int)$shop['id']);
                flash('Fast Payout request submitted — an admin will review it shortly.', 'success');
            } else {
                $result = mp_enable_fast_payout((int)$shop['id'], $account);
                if ($result['success']) {
                    flash('⚡ Fast Payout enabled! Orders for this shop (when your cart is the only shop involved) will now split your cut straight to your Paystack subaccount.', 'success');
                } else {
                    flash('Could not enable Fast Payout: ' . $result['error'], 'error');
                }
            }
        }
    } elseif ($_POST['fast_payout_action'] === 'cancel_request') {
        $pdo->prepare('UPDATE mp_shops SET fast_payout_requested_at=NULL WHERE id=?')->execute([$shop['id']]);
        flash('Fast Payout request cancelled.', 'info');
    } elseif ($_POST['fast_payout_action'] === 'disable') {
        mp_disable_fast_payout((int)$shop['id']);
        flash('Fast Payout disabled. New orders will use the standard payout flow. Any orders already routed through it will still clear normally.', 'info');
    }

    header('Location: seller_payout_accounts.php');
    exit;
}

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

                // If this shop has a Fast Payout subaccount — enabled, or
                // disabled but still winding down a held balance — and this
                // save just (re)confirmed the default payout account, keep
                // the subaccount pointed at it. Checked on subaccount
                // presence rather than the enabled flag, since a disabled
                // shop can still have an un-settled balance sitting on the
                // OLD account details until it clears.
                if ($shop['paystack_subaccount_code']) {
                    $curDefaultStmt = $pdo->prepare('SELECT * FROM mp_payout_accounts WHERE shop_id=? AND is_default=1');
                    $curDefaultStmt->execute([$shop['id']]);
                    $curDefault = $curDefaultStmt->fetch();
                    if ($curDefault && $curDefault['method'] === $method) {
                        mp_sync_fast_payout_bank_account((int)$shop['id'], $curDefault);
                    }
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

    <!-- Fast Payout — hidden entirely unless the admin has both switched the
         module on and granted this specific shop eligibility. -->
    <?php if ($fastPayoutVisible): ?>
    <div class="pf-section">
        <p class="pf-section-title">⚡ Fast Payout <span style="text-transform:none;font-weight:600;">(Beta, opt-in)</span></p>

        <?php if ($shop['fast_payout_enabled']): ?>
        <div class="pa-current">
            <strong>Fast Payout is ON</strong>
            Once an order clears its confirmation window, your cut settles straight to your default payout account above — no withdrawal step, no transfer fee.
        </div>
        <form method="post" action="seller_payout_accounts.php">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="fast_payout_action" value="disable">
            <button type="submit" class="button button-secondary">Disable Fast Payout</button>
        </form>

        <?php elseif ($shop['fast_payout_requested_at']): ?>
        <div class="pa-current" style="background:#fef3c7;">
            <strong>⏳ Pending admin approval</strong>
            You requested Fast Payout on <?php echo date('d M Y', strtotime($shop['fast_payout_requested_at'])); ?>. An admin will review it shortly.
        </div>
        <form method="post" action="seller_payout_accounts.php">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="fast_payout_action" value="cancel_request">
            <button type="submit" class="button button-secondary">Cancel Request</button>
        </form>

        <?php else: ?>
        <?php if (!empty($shop['fast_payout_rejected_reason'])): ?>
        <div class="pa-current" style="background:#fee2e2;">
            <strong>Previous request not approved</strong>
            <?php echo sanitize($shop['fast_payout_rejected_reason']); ?>
        </div>
        <?php endif; ?>
        <p class="form-hint" style="margin-bottom:12px;">
            Skip the manual withdrawal step. When you opt in, orders where your shop is the <em>only</em> shop in the buyer's cart route your cut directly to your default payout account (above) via a Paystack subaccount. It's still held until the same confirmation window used today has passed — same buyer protection, just no manual transfer once it clears.
            Carts that mix your shop with others still use the standard wallet flow.
            <?php if ($fastPayoutRequiresApproval): ?>Enabling here files a request — an admin approves it before it goes live.<?php endif; ?>
        </p>
        <form method="post" action="seller_payout_accounts.php">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="fast_payout_action" value="enable">
            <button type="submit" class="button button-primary" <?php echo (!$momoAccount && !$bankAccount) ? 'disabled' : ''; ?>><?php echo $fastPayoutRequiresApproval ? 'Request Fast Payout' : 'Enable Fast Payout'; ?></button>
        </form>
        <?php if (!$momoAccount && !$bankAccount): ?>
        <p class="form-hint" style="margin-top:8px;">Save a Mobile Money or Bank account above first, and mark it default.</p>
        <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>

</main>

<?php require_once __DIR__ . '/partials/bottom_nav.php'; ?>
</body>
</html>
