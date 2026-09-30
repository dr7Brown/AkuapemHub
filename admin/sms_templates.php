<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';

require_login();
if (!is_admin()) { header('Location: index.php'); exit; }
$user = current_user();

$types = sms_trigger_types();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (isset($_POST['reset']) && array_key_exists($_POST['reset'], $types)) {
        set_platform_setting('sms_template_' . $_POST['reset'], '');
        flash('Reset "' . $types[$_POST['reset']]['label'] . '" to its default message.', 'success');
    } else {
        foreach ($types as $key => $info) {
            $val = trim($_POST["tpl_{$key}"] ?? '');
            set_platform_setting("sms_template_{$key}", $val === $info['default'] ? '' : $val);
        }
        log_audit_action($user['id'], 'sms_templates_save', 'Updated SMS message templates');
        flash('Message templates saved.', 'success');
    }
    header('Location: sms_templates.php');
    exit;
}

$flash = get_flash();
foreach ($types as $key => &$info) {
    $saved = get_platform_setting("sms_template_{$key}", '');
    $info['current'] = $saved !== '' ? $saved : $info['default'];
}
unset($info);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMS Message Templates — Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .st-shell   { max-width:760px; margin:0 auto; padding:20px 16px 60px; }
        .st-card    { background:var(--surface,#fff); border:1px solid var(--border); border-radius:14px; padding:16px 18px; margin-bottom:14px; }
        .st-label   { font-weight:800; font-size:.9rem; margin:0 0 2px; }
        .st-desc    { font-size:.78rem; color:var(--text-muted,#6b7280); margin:0 0 10px; }
        .st-card textarea { width:100%; box-sizing:border-box; border-radius:10px; font-size:.87rem; resize:vertical; }
        .st-placeholders { display:flex; gap:6px; flex-wrap:wrap; margin-top:8px; }
        .st-ph      { font-family:monospace; font-size:.74rem; background:var(--surface-muted,#f1f5f9); border:1px solid var(--border); border-radius:6px; padding:2px 7px; cursor:pointer; }
        .st-ph:hover { border-color:var(--primary,#0f766e); }
        .st-row-actions { display:flex; justify-content:space-between; align-items:center; margin-top:8px; }
        .st-prefix-note { font-size:.72rem; color:var(--text-muted,#6b7280); }
    </style>
</head>
<body>
    <header class="topbar">
        <a href="monetization.php?tab=settings" class="button button-secondary button-small">← Monetize</a>
        <h1>✏️ SMS Message Templates</h1>
    </header>

    <main class="st-shell">
        <?php if ($flash): ?><div class="alert alert-<?php echo sanitize($flash['type']); ?>" style="margin-bottom:16px;"><?php echo sanitize($flash['message']); ?></div><?php endif; ?>

        <p class="meta" style="margin-bottom:16px;">
            Edit the wording of each SMS below. <code>{placeholder}</code> tokens are replaced with real values when the message is sent — click one to insert it at your cursor. Every message is automatically prefixed with "<?php echo sanitize(APP_NAME); ?>: ", so you don't need to include the app name yourself.
            Whether each of these actually sends is controlled separately in <a href="monetization.php?tab=settings">Monetize → Settings → SMS Notifications</a>.
        </p>

        <form method="post" action="sms_templates.php">
            <?php echo csrf_field(); ?>
            <?php foreach ($types as $key => $info): ?>
            <div class="st-card">
                <p class="st-label"><?php echo sanitize($info['label']); ?></p>
                <p class="st-desc"><?php echo sanitize($info['desc']); ?></p>
                <textarea name="tpl_<?php echo $key; ?>" id="tpl_<?php echo $key; ?>" rows="2" maxlength="480"><?php echo sanitize($info['current']); ?></textarea>
                <div class="st-row-actions">
                    <div class="st-placeholders">
                        <?php if ($info['placeholders']): foreach ($info['placeholders'] as $ph): ?>
                        <span class="st-ph" onclick="stInsert('tpl_<?php echo $key; ?>','{<?php echo $ph; ?>}')">{<?php echo $ph; ?>}</span>
                        <?php endforeach; else: ?>
                        <span class="st-prefix-note">No placeholders for this message.</span>
                        <?php endif; ?>
                    </div>
                    <button type="submit" form="reset-<?php echo $key; ?>" class="button button-small button-secondary" onclick="return confirm('Reset to the default message?')">Reset</button>
                </div>
            </div>
            <?php endforeach; ?>
            <button type="submit" class="button button-primary" style="width:100%;padding:13px;">Save All Templates</button>
        </form>

        <?php foreach ($types as $key => $info): ?>
        <form method="post" action="sms_templates.php" id="reset-<?php echo $key; ?>" style="display:none;">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="reset" value="<?php echo $key; ?>">
        </form>
        <?php endforeach; ?>
    </main>

    <script>
        function stInsert(fieldId, token) {
            var el = document.getElementById(fieldId);
            var start = el.selectionStart, end = el.selectionEnd;
            el.value = el.value.slice(0, start) + token + el.value.slice(end);
            el.focus();
            el.selectionStart = el.selectionEnd = start + token.length;
        }
    </script>
</body>
</html>
