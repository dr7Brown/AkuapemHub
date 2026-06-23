<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';

require_login();
if (!is_admin()) { header('Location: index.php'); exit; }

$adminUser = current_user();
$saved = false;
$testResult = null;
$errors = [];

$keys = ['smtp_enabled','smtp_host','smtp_port','smtp_encryption',
         'smtp_username','smtp_password','smtp_from_name','smtp_from_email'];

// ── Save settings ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'save') {
    csrf_check();
    foreach ($keys as $k) {
        $val = trim($_POST[$k] ?? '');
        if ($k === 'smtp_enabled') $val = isset($_POST['smtp_enabled']) ? '1' : '0';
        if ($k === 'smtp_port') $val = max(1, min(65535, (int)$val));
        if ($k === 'smtp_encryption' && !in_array($val, ['tls','ssl','none'])) $val = 'tls';
        set_platform_setting($k, (string)$val);
    }
    log_audit_action($adminUser['id'], 'smtp_settings_save', 'Updated SMTP email settings');
    flash('Email settings saved.', 'success');
    header('Location: email_settings.php?saved=1');
    exit;
}

// ── Send test email ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'test') {
    csrf_check();
    $testTo = trim($_POST['test_email'] ?? '');
    if (!filter_var($testTo, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email address to send the test to.';
    } else {
        require_once __DIR__ . '/../services/EmailService.php';
        EmailService::resetConfig();
        $ok = EmailService::send(
            $testTo,
            'Test Email — ' . APP_NAME,
            '<p>This is a test email from <strong>' . APP_NAME . '</strong>.</p><p>If you received this, your SMTP configuration is working correctly.</p>'
        );
        $testResult = $ok ? 'success' : 'error';
    }
}

$flash = get_flash();

// Load current settings
$current = [];
foreach ($keys as $k) $current[$k] = get_platform_setting($k, '');
$current['smtp_port'] = $current['smtp_port'] ?: '587';
$current['smtp_encryption'] = $current['smtp_encryption'] ?: 'tls';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Settings — Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .es-shell { max-width:660px; margin:0 auto; padding:20px 16px 60px; }
        .es-card  { background:var(--surface); border:1px solid var(--border); border-radius:14px; padding:20px; margin-bottom:16px; }
        .es-title { font-size:.75rem; font-weight:800; text-transform:uppercase; letter-spacing:.07em; color:var(--text-muted,#6b7280); margin:0 0 16px; }
        label     { font-weight:600; font-size:.86rem; display:block; margin-bottom:4px; }
        .form-group { margin-bottom:14px; }
        .form-hint  { font-size:.74rem; color:var(--text-muted,#6b7280); margin-top:3px; }
        .es-grid2   { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
        .es-provider-tips { display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:10px; }
        .es-tip { background:var(--surface-muted,#f8fafc); border:1px solid var(--border); border-radius:8px; padding:10px 12px; font-size:.8rem; cursor:pointer; transition:border-color .1s; }
        .es-tip:hover { border-color:var(--primary,#0f766e); }
        .es-tip strong { display:block; margin-bottom:3px; }
        @media(max-width:480px){ .es-grid2 { grid-template-columns:1fr; } }
    </style>
</head>
<body>

<header class="topbar">
    <a href="index.php" class="button button-secondary button-small">← Dashboard</a>
    <h1 style="margin:0;font-size:1rem;font-weight:800;">📧 Email Settings</h1>
</header>

<main class="es-shell">

    <?php if ($flash): ?>
    <div class="alert alert-<?php echo sanitize($flash['type']); ?>"><?php echo sanitize($flash['message']); ?></div>
    <?php endif; ?>

    <?php if ($testResult === 'success'): ?>
    <div class="alert alert-success">✅ Test email sent successfully! Check your inbox.</div>
    <?php elseif ($testResult === 'error'): ?>
    <div class="alert alert-error">❌ Test email failed. Check the settings below and your server error log.</div>
    <?php endif; ?>

    <?php foreach ($errors as $e): ?>
    <div class="alert alert-error"><?php echo sanitize($e); ?></div>
    <?php endforeach; ?>

    <!-- Provider quick-fill tips -->
    <div class="es-card">
        <p class="es-title">Quick Fill — Common Providers</p>
        <div class="es-provider-tips">
            <div class="es-tip" onclick="fillSmtp('smtp.gmail.com','587','tls')">
                <strong>Gmail</strong>host: smtp.gmail.com · port: 587 · TLS<br>
                <span style="color:var(--text-muted,#6b7280);font-size:.72rem;">Use an App Password (not your regular password)</span>
            </div>
            <div class="es-tip" onclick="fillSmtp('smtp.office365.com','587','tls')">
                <strong>Outlook / Office 365</strong>host: smtp.office365.com · 587 · TLS
            </div>
            <div class="es-tip" onclick="fillSmtp('smtp.sendgrid.net','587','tls')">
                <strong>SendGrid</strong>host: smtp.sendgrid.net · 587 · TLS<br>
                <span style="color:var(--text-muted,#6b7280);font-size:.72rem;">Username: apikey · Password: your API key</span>
            </div>
            <div class="es-tip" onclick="fillSmtp('mail.privateemail.com','465','ssl')">
                <strong>Namecheap / cPanel</strong>host: your cPanel mail host · 465 · SSL
            </div>
        </div>
    </div>

    <!-- Main settings form -->
    <form method="post" action="email_settings.php">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="form" value="save">

        <div class="es-card">
            <p class="es-title">Email Delivery Mode</p>
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:.9rem;">
                <input type="checkbox" name="smtp_enabled" value="1"
                       id="smtp_toggle"
                       <?php echo $current['smtp_enabled']==='1' ? 'checked' : ''; ?>>
                Enable SMTP (uncheck to use server's default <code>mail()</code>)
            </label>
            <p class="form-hint" style="margin-top:8px;">
                <strong>SMTP is recommended for production.</strong>
                <code>mail()</code> often fails on shared hosting without extra configuration.
            </p>
        </div>

        <div class="es-card" id="smtp-fields">
            <p class="es-title">SMTP Server</p>
            <div class="es-grid2">
                <div class="form-group" style="grid-column:1/-1;">
                    <label for="smtp_host">Host *</label>
                    <input type="text" id="smtp_host" name="smtp_host"
                           value="<?php echo sanitize($current['smtp_host']); ?>"
                           placeholder="smtp.gmail.com">
                </div>
                <div class="form-group">
                    <label for="smtp_port">Port</label>
                    <input type="number" id="smtp_port" name="smtp_port" min="1" max="65535"
                           value="<?php echo sanitize($current['smtp_port']); ?>">
                    <p class="form-hint">587 = STARTTLS &nbsp;·&nbsp; 465 = SSL &nbsp;·&nbsp; 25 = plain</p>
                </div>
                <div class="form-group">
                    <label for="smtp_encryption">Encryption</label>
                    <select id="smtp_encryption" name="smtp_encryption">
                        <?php foreach (['tls'=>'STARTTLS (recommended)','ssl'=>'SSL','none'=>'None (insecure)'] as $v=>$l): ?>
                        <option value="<?php echo $v; ?>" <?php echo $current['smtp_encryption']===$v?'selected':''; ?>><?php echo $l; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <p class="es-title" style="margin-top:8px;">Authentication</p>
            <div class="es-grid2">
                <div class="form-group">
                    <label for="smtp_username">Username</label>
                    <input type="text" id="smtp_username" name="smtp_username"
                           value="<?php echo sanitize($current['smtp_username']); ?>"
                           placeholder="your@email.com" autocomplete="off">
                </div>
                <div class="form-group">
                    <label for="smtp_password">Password / App Password</label>
                    <input type="password" id="smtp_password" name="smtp_password"
                           value="<?php echo sanitize($current['smtp_password']); ?>"
                           placeholder="••••••••" autocomplete="new-password">
                    <p class="form-hint">For Gmail: generate an App Password in Google Account → Security → 2-Step → App passwords.</p>
                </div>
            </div>

            <p class="es-title" style="margin-top:8px;">Sender Identity</p>
            <div class="es-grid2">
                <div class="form-group">
                    <label for="smtp_from_name">From Name</label>
                    <input type="text" id="smtp_from_name" name="smtp_from_name"
                           value="<?php echo sanitize($current['smtp_from_name']); ?>"
                           placeholder="<?php echo sanitize(APP_NAME); ?>">
                    <p class="form-hint">Blank = uses APP_NAME from config.php</p>
                </div>
                <div class="form-group">
                    <label for="smtp_from_email">From Email</label>
                    <input type="email" id="smtp_from_email" name="smtp_from_email"
                           value="<?php echo sanitize($current['smtp_from_email']); ?>"
                           placeholder="<?php echo sanitize(MAIL_FROM); ?>">
                    <p class="form-hint">Blank = uses MAIL_FROM from config.php</p>
                </div>
            </div>
        </div>

        <button type="submit" class="button button-primary" style="width:100%;padding:13px;">Save Email Settings</button>
    </form>

    <!-- Test email -->
    <div class="es-card" style="margin-top:16px;">
        <p class="es-title">Send Test Email</p>
        <form method="post" action="email_settings.php" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="form" value="test">
            <div class="form-group" style="flex:1;margin:0;">
                <label for="test_email">Send test to</label>
                <input type="email" id="test_email" name="test_email"
                       value="<?php echo sanitize($adminUser['email']); ?>" placeholder="you@example.com">
            </div>
            <button type="submit" class="button button-secondary" style="flex-shrink:0;">Send Test →</button>
        </form>
        <p class="form-hint" style="margin-top:6px;">Saves nothing — just fires one email using the <em>currently saved</em> settings.</p>
    </div>

</main>

<script>
function fillSmtp(host, port, enc) {
    document.getElementById('smtp_host').value       = host;
    document.getElementById('smtp_port').value       = port;
    document.getElementById('smtp_encryption').value = enc;
    document.getElementById('smtp_toggle').checked   = true;
    document.getElementById('smtp-fields').style.display = '';
}
document.getElementById('smtp_toggle').addEventListener('change', function() {
    document.getElementById('smtp-fields').style.opacity = this.checked ? '1' : '.5';
});
</script>
</body>
</html>
