<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';

require_login();
if (!is_admin()) {
    header('Location: index.php');
    exit;
}

$fields = [
    'contact_email'    => ['label' => 'Support email',    'type' => 'email',    'placeholder' => 'support@akuapemhub.com', 'desc' => 'Shown on the Contact page and used for contact form replies'],
    'contact_phone'    => ['label' => 'Phone number',     'type' => 'text',     'placeholder' => '+233 XX XXX XXXX',       'desc' => 'Optional phone number displayed on the Contact page'],
    'contact_whatsapp' => ['label' => 'WhatsApp number',  'type' => 'text',     'placeholder' => '+233244000000',          'desc' => 'Full international format, used to generate a wa.me link'],
    'contact_address'  => ['label' => 'Physical address', 'type' => 'text',     'placeholder' => 'Akuapem Area, Eastern Region, Ghana', 'desc' => 'Address or area displayed on the Contact page'],
    'contact_hours'    => ['label' => 'Support hours',    'type' => 'text',     'placeholder' => 'Mon–Sat, 8:00 AM – 6:00 PM GMT', 'desc' => 'Business hours text shown on the Contact page'],
    'contact_tagline'  => ['label' => 'Contact page intro', 'type' => 'textarea', 'placeholder' => "We're here to help. Reach out through any of the channels below…", 'desc' => 'Intro line shown at the top of the Contact page'],
];

$saved = false;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    foreach ($fields as $key => $meta) {
        $val = trim($_POST[$key] ?? '');
        if ($key === 'contact_email' && $val !== '' && !filter_var($val, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Support email must be a valid email address.';
            continue;
        }
        // Sanitize WhatsApp: strip non-digits for link use, store as entered
        set_platform_setting($key, $val);
    }
    if (!$errors) {
        $saved = true;
        header('Location: contact_settings.php?saved=1');
        exit;
    }
}

$savedFlash = isset($_GET['saved']);

$current = [];
foreach (array_keys($fields) as $key) {
    $current[$key] = get_platform_setting($key) ?? '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Contact Settings — AkuapemHub Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css" />
    <style>
        .cs-shell { max-width: 640px; margin: 0 auto; padding: 20px 16px 60px; }
        .cs-field  { margin-bottom: 20px; }
        .cs-field label { display: block; font-weight: 600; font-size: .9rem; margin-bottom: 4px; }
        .cs-field .cs-desc { font-size: .8rem; color: var(--text-muted); margin: 3px 0 6px; }
        .cs-field input, .cs-field textarea { width: 100%; box-sizing: border-box; }
        .cs-field textarea { resize: vertical; min-height: 64px; }
        .cs-preview {
            background: var(--surface); border: 1px solid var(--border); border-radius: 10px;
            padding: 16px 18px; margin-bottom: 24px; font-size: .88rem; line-height: 1.7;
        }
        .cs-preview h3 { margin: 0 0 10px; font-size: .9rem; }
        .cs-preview-item { display: flex; gap: 10px; margin-bottom: 8px; }
        .cs-preview-icon { width: 26px; text-align: center; flex-shrink: 0; }
    </style>
</head>
<body>
    <header class="topbar">
        <a href="index.php" class="button button-secondary button-small">← Back</a>
        <h1>Contact Page Settings</h1>
        <a href="../logout.php" class="button button-secondary button-small">Logout</a>
    </header>

    <main class="cs-shell">

        <?php if ($savedFlash): ?>
            <div class="alert alert-success">Contact information saved successfully.</div>
        <?php endif; ?>
        <?php foreach ($errors as $e): ?>
            <div class="alert alert-error"><?php echo sanitize($e); ?></div>
        <?php endforeach; ?>

        <!-- Live preview -->
        <div class="cs-preview">
            <h3>Preview — what users will see on the Contact page</h3>
            <?php
            $pvEmail    = htmlspecialchars($current['contact_email']    ?: MAIL_FROM,    ENT_QUOTES);
            $pvPhone    = htmlspecialchars($current['contact_phone']    ?: '',            ENT_QUOTES);
            $pvWa       = htmlspecialchars($current['contact_whatsapp'] ?: '',            ENT_QUOTES);
            $pvAddr     = htmlspecialchars($current['contact_address']  ?: 'Akuapem Area, Eastern Region, Ghana', ENT_QUOTES);
            $pvHours    = htmlspecialchars($current['contact_hours']    ?: 'Mon–Sat, 8:00 AM – 6:00 PM GMT',     ENT_QUOTES);
            ?>
            <div class="cs-preview-item"><span class="cs-preview-icon">📧</span><span>Email: <strong><?php echo $pvEmail; ?></strong></span></div>
            <?php if ($pvPhone): ?><div class="cs-preview-item"><span class="cs-preview-icon">📞</span><span>Phone: <strong><?php echo $pvPhone; ?></strong></span></div><?php endif; ?>
            <?php if ($pvWa): ?><div class="cs-preview-item"><span class="cs-preview-icon">💬</span><span>WhatsApp: <strong><?php echo $pvWa; ?></strong></span></div><?php endif; ?>
            <div class="cs-preview-item"><span class="cs-preview-icon">📍</span><span>Address: <strong><?php echo $pvAddr; ?></strong></span></div>
            <div class="cs-preview-item"><span class="cs-preview-icon">🕐</span><span>Hours: <strong><?php echo $pvHours; ?></strong></span></div>
        </div>

        <form method="post" action="contact_settings.php">
            <?php echo csrf_field(); ?>

            <?php foreach ($fields as $key => $meta): ?>
                <div class="cs-field">
                    <label for="cs-<?php echo $key; ?>"><?php echo sanitize($meta['label']); ?></label>
                    <p class="cs-desc"><?php echo sanitize($meta['desc']); ?></p>
                    <?php if ($meta['type'] === 'textarea'): ?>
                        <textarea id="cs-<?php echo $key; ?>" name="<?php echo $key; ?>"
                                  class="rich-editor"
                                  placeholder="<?php echo sanitize($meta['placeholder']); ?>"><?php echo $current[$key]; ?></textarea>
                    <?php else: ?>
                        <input type="<?php echo $meta['type']; ?>" id="cs-<?php echo $key; ?>" name="<?php echo $key; ?>"
                               value="<?php echo sanitize($current[$key]); ?>"
                               placeholder="<?php echo sanitize($meta['placeholder']); ?>" />
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <div style="display:flex;gap:10px;align-items:center;margin-top:24px;">
                <button type="submit" class="button button-primary">Save contact info</button>
                <a href="../contact.php" target="_blank" class="button button-secondary">Preview contact page ↗</a>
            </div>
        </form>

        <div style="margin-top:28px;padding:14px 16px;background:var(--surface);border:1px solid var(--border);border-radius:8px;font-size:.85rem;color:var(--text-muted);">
            <strong>Notes:</strong>
            <ul style="margin:6px 0 0;padding-left:18px;line-height:1.9;">
                <li>Support email falls back to the <code>MAIL_FROM</code> constant in <code>config.php</code> if left blank.</li>
                <li>WhatsApp number should be in international format (e.g. <code>+233244000000</code>). A clickable <strong>wa.me</strong> link is auto-generated.</li>
                <li>Leave any field blank to use the default/fallback value.</li>
            </ul>
        </div>
    </main>
</body>
</html>
