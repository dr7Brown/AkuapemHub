<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';

require_login();
if (!is_admin()) { header('Location: index.php'); exit; }

$adminUser = current_user();
$testResult = null;
$errors = [];

$keys = ['arkesel_enabled', 'arkesel_api_key', 'arkesel_sender_id'];

// ── Save settings ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'save') {
    csrf_check();
    foreach ($keys as $k) {
        $val = trim($_POST[$k] ?? '');
        if ($k === 'arkesel_enabled') $val = isset($_POST['arkesel_enabled']) ? '1' : '0';
        if ($k === 'arkesel_sender_id' && $val !== '') $val = substr($val, 0, 11); // Arkesel sender ID max length
        set_platform_setting($k, (string)$val);
    }
    log_audit_action($adminUser['id'], 'sms_settings_save', 'Updated Arkesel SMS settings');
    require_once __DIR__ . '/../services/SmsService.php';
    SmsService::resetConfig();
    flash('SMS settings saved.', 'success');
    header('Location: sms_settings.php?saved=1');
    exit;
}

// ── Send test SMS ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'test') {
    csrf_check();
    $testPhone = trim($_POST['test_phone'] ?? '');
    if ($testPhone === '') {
        $errors[] = 'Enter a phone number to send the test to.';
    } else {
        require_once __DIR__ . '/../services/WhatsAppService.php';
        require_once __DIR__ . '/../services/SmsService.php';
        $testMessage = 'Test SMS from ' . APP_NAME . ' — your Arkesel configuration is working.';
        $ok = SmsService::send($testPhone, $testMessage);
        $testResult = $ok ? 'success' : 'error';
        $pdo->prepare(
            'INSERT INTO business_messages (user_id, phone, channel, message, status, response_excerpt, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())'
        )->execute([
            $adminUser['id'], WhatsAppService::normalizePhone($testPhone), 'sms', $testMessage,
            $ok ? 'sent' : 'failed', $ok ? 'Test send — delivered via Arkesel' : 'Test send — see server error log',
        ]);
    }
}

$flash = get_flash();

$current = [];
foreach ($keys as $k) $current[$k] = get_platform_setting($k, '');
if ($current['arkesel_enabled'] === '') $current['arkesel_enabled'] = '1';
if ($current['arkesel_sender_id'] === '') $current['arkesel_sender_id'] = defined('ARKESEL_SENDER_ID') ? ARKESEL_SENDER_ID : 'AkuapemCn';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMS Settings — Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .ss-shell { max-width:660px; margin:0 auto; padding:20px 16px 60px; }
        .ss-card  { background:var(--surface); border:1px solid var(--border); border-radius:14px; padding:20px; margin-bottom:16px; }
        .ss-title { font-size:.75rem; font-weight:800; text-transform:uppercase; letter-spacing:.07em; color:var(--text-muted,#6b7280); margin:0 0 16px; }
        label     { font-weight:600; font-size:.86rem; display:block; margin-bottom:4px; }
        .form-group { margin-bottom:14px; }
        .form-hint  { font-size:.74rem; color:var(--text-muted,#6b7280); margin-top:3px; }
    </style>
</head>
<body>

<header class="topbar">
    <a href="index.php" class="button button-secondary button-small">← Dashboard</a>
    <h1 style="margin:0;font-size:1rem;font-weight:800;">📱 SMS Settings</h1>
</header>

<main class="ss-shell">

    <?php if ($flash): ?>
    <div class="alert alert-<?php echo sanitize($flash['type']); ?>"><?php echo sanitize($flash['message']); ?></div>
    <?php endif; ?>

    <?php if ($testResult === 'success'): ?>
    <div class="alert alert-success">✅ Test SMS sent successfully! Check the phone you sent it to.</div>
    <?php elseif ($testResult === 'error'): ?>
    <div class="alert alert-error">❌ Test SMS failed. Check the settings below and your server error log.</div>
    <?php endif; ?>

    <?php foreach ($errors as $e): ?>
    <div class="alert alert-error"><?php echo sanitize($e); ?></div>
    <?php endforeach; ?>

    <div class="ss-card">
        <p class="ss-title">About</p>
        <p style="font-size:.86rem;line-height:1.6;margin:0;">
            Set up your <a href="https://sms.arkesel.com" target="_blank" rel="noopener">Arkesel</a> account here so SMS can actually send — the individual "major action" triggers (worker hired, payment confirmed, etc.) are switched on separately from <a href="monetization.php?tab=settings">Monetize → Settings</a>.
        </p>
    </div>

    <form method="post" action="sms_settings.php">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="form" value="save">

        <div class="ss-card">
            <p class="ss-title">SMS Delivery</p>
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:.9rem;">
                <input type="checkbox" name="arkesel_enabled" value="1" <?php echo $current['arkesel_enabled']==='1' ? 'checked' : ''; ?>>
                Enable SMS sending (uncheck to pause all SMS without touching individual triggers)
            </label>
        </div>

        <div class="ss-card">
            <p class="ss-title">Arkesel Credentials</p>
            <div class="form-group">
                <label for="arkesel_api_key">API Key</label>
                <input type="password" id="arkesel_api_key" name="arkesel_api_key"
                       value="<?php echo sanitize($current['arkesel_api_key']); ?>"
                       placeholder="••••••••" autocomplete="new-password">
                <p class="form-hint">From your Arkesel dashboard → API Keys.</p>
            </div>
            <div class="form-group">
                <label for="arkesel_sender_id">Sender ID</label>
                <input type="text" id="arkesel_sender_id" name="arkesel_sender_id" maxlength="11"
                       value="<?php echo sanitize($current['arkesel_sender_id']); ?>"
                       placeholder="AkuapemCn">
                <p class="form-hint">Max 11 characters — must be pre-approved in your Arkesel dashboard before it will deliver.</p>
            </div>
        </div>

        <button type="submit" class="button button-primary" style="width:100%;padding:13px;">Save SMS Settings</button>
    </form>

    <div class="ss-card" style="margin-top:16px;">
        <p class="ss-title">Send Test SMS</p>
        <form method="post" action="sms_settings.php" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="form" value="test">
            <div class="form-group" style="flex:1;margin:0;">
                <label for="test_phone">Send test to</label>
                <input type="tel" id="test_phone" name="test_phone" placeholder="0244000000">
            </div>
            <button type="submit" class="button button-secondary" style="flex-shrink:0;">Send Test →</button>
        </form>
        <p class="form-hint" style="margin-top:6px;">Saves nothing — just fires one SMS using the <em>currently saved</em> settings.</p>
    </div>

</main>
</body>
</html>
