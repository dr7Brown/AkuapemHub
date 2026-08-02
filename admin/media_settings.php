<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';

require_login();
if (!is_admin_or_manager()) {
    header('Location: index.php');
    exit;
}
require_mod_permission('manage_media_settings');

$fields = [
    'img_mp_product_maxwidth' => ['label' => 'Product photos — max width (px)',  'default' => '1200', 'desc' => 'Product images are resized to no wider than this before saving.'],
    'img_mp_product_quality'  => ['label' => 'Product photos — quality (1-100)', 'default' => '85',   'desc' => 'JPEG compression quality. 85 is visually near-lossless; lower = smaller files, more visible artifacts.'],
    'img_mp_logo_maxwidth'    => ['label' => 'Shop logo — max width (px)',       'default' => '500',  'desc' => 'Shop logos are always shown small, so a lower width keeps files tiny.'],
    'img_mp_logo_quality'     => ['label' => 'Shop logo — quality (1-100)',      'default' => '85',   'desc' => ''],
    'img_mp_banner_maxwidth'  => ['label' => 'Shop banner — max width (px)',     'default' => '1200', 'desc' => 'Banners span the full width of a shop page, so they need more width than the logo.'],
    'img_mp_banner_quality'   => ['label' => 'Shop banner — quality (1-100)',    'default' => '85',   'desc' => ''],
    'img_completion_maxwidth' => ['label' => 'Job completion photos — max width (px)',  'default' => '1200', 'desc' => 'Kept large enough to be usable as dispute evidence.'],
    'img_completion_quality'  => ['label' => 'Job completion photos — quality (1-100)', 'default' => '85',   'desc' => ''],
];

$saved = false;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    foreach ($fields as $key => $meta) {
        $val = trim($_POST[$key] ?? '');
        $isQuality = str_ends_with($key, '_quality');
        $min = $isQuality ? 10 : 100; // guard against typos producing unusable images (e.g. quality=1 or a 1px-wide photo)
        if ($val === '' || !ctype_digit($val) || (int)$val < $min) {
            $errors[] = $meta['label'] . ' must be a whole number of at least ' . $min . '.';
            continue;
        }
        if ($isQuality && (int)$val > 100) {
            $errors[] = $meta['label'] . ' cannot be more than 100.';
            continue;
        }
        set_platform_setting($key, $val);
    }
    if (!$errors) {
        $saved = true;
        header('Location: media_settings.php?saved=1');
        exit;
    }
}

$savedFlash = isset($_GET['saved']);

$current = [];
foreach ($fields as $key => $meta) {
    $current[$key] = get_platform_setting($key) ?: $meta['default'];
}

$groups = [
    'Marketplace product photos' => ['img_mp_product_maxwidth', 'img_mp_product_quality'],
    'Shop logo'                  => ['img_mp_logo_maxwidth', 'img_mp_logo_quality'],
    'Shop banner'                => ['img_mp_banner_maxwidth', 'img_mp_banner_quality'],
    'Job completion photos'      => ['img_completion_maxwidth', 'img_completion_quality'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Image Optimization Settings — AkuapemConnect Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css" />
    <style>
        .cs-shell { max-width: 640px; margin: 0 auto; padding: 20px 16px 60px; }
        .cs-group { margin-bottom: 22px; padding: 16px 18px; background: var(--surface); border: 1px solid var(--border); border-radius: 10px; }
        .cs-group h3 { margin: 0 0 12px; font-size: .95rem; }
        .cs-field  { margin-bottom: 14px; }
        .cs-field:last-child { margin-bottom: 0; }
        .cs-field label { display: block; font-weight: 600; font-size: .85rem; margin-bottom: 4px; }
        .cs-field .cs-desc { font-size: .78rem; color: var(--text-muted); margin: 3px 0 6px; }
        .cs-field input { width: 140px; box-sizing: border-box; }
    </style>
</head>
<body>
    <header class="topbar">
        <a href="index.php" class="button button-secondary button-small">← Back</a>
        <h1>Image Optimization Settings</h1>
        <a href="../logout.php" class="button button-secondary button-small">Logout</a>
    </header>

    <main class="cs-shell">

        <?php if ($savedFlash): ?>
            <div class="alert alert-success">Image settings saved successfully.</div>
        <?php endif; ?>
        <?php foreach ($errors as $e): ?>
            <div class="alert alert-error"><?php echo sanitize($e); ?></div>
        <?php endforeach; ?>

        <p class="meta" style="margin:0 0 20px;">
            These control how uploaded images are resized and compressed before saving. Changes only apply to
            <strong>new uploads</strong> — existing images already on disk are not reprocessed.
        </p>

        <form method="post" action="media_settings.php">
            <?php echo csrf_field(); ?>

            <?php foreach ($groups as $groupLabel => $keys): ?>
                <div class="cs-group">
                    <h3><?php echo sanitize($groupLabel); ?></h3>
                    <?php foreach ($keys as $key): $meta = $fields[$key]; ?>
                        <div class="cs-field">
                            <label for="ms-<?php echo $key; ?>"><?php echo sanitize($meta['label']); ?></label>
                            <?php if ($meta['desc']): ?><p class="cs-desc"><?php echo sanitize($meta['desc']); ?></p><?php endif; ?>
                            <input type="number" min="<?php echo str_ends_with($key, '_quality') ? '10' : '100'; ?>" <?php echo str_ends_with($key, '_quality') ? 'max="100"' : ''; ?>
                                   id="ms-<?php echo $key; ?>" name="<?php echo $key; ?>" class="form-control"
                                   value="<?php echo sanitize($current[$key]); ?>" required />
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>

            <div style="display:flex;gap:10px;align-items:center;margin-top:24px;">
                <button type="submit" class="button button-primary">Save image settings</button>
            </div>
        </form>

        <div style="margin-top:28px;padding:14px 16px;background:var(--surface);border:1px solid var(--border);border-radius:8px;font-size:.85rem;color:var(--text-muted);">
            <strong>Notes:</strong>
            <ul style="margin:6px 0 0;padding-left:18px;line-height:1.9;">
                <li>Defaults (1200px/85 for photos, 500px/85 for the logo) are a good balance of file size vs. quality — only change these if you have a specific reason to.</li>
                <li>ID/verification documents (worker IDs, delivery agent IDs, Ghana Cards, etc.) are handled separately and always compressed down to under 1MB — they are not affected by this page.</li>
            </ul>
        </div>
    </main>
</body>
</html>
