<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';

require_login();
if (!is_admin()) {
    header('Location: index.php');
    exit;
}

$themeKeys = [
    'theme_primary'        => ['label' => 'Primary colour',        'default' => '#2f8f5b', 'desc' => 'Buttons, links, accents'],
    'theme_primary_dark'   => ['label' => 'Primary dark',          'default' => '#246b45', 'desc' => 'Hover states, headings'],
    'theme_primary_soft'   => ['label' => 'Primary soft (tint)',   'default' => '#e4f4ea', 'desc' => 'Card header fills, badges'],
    'theme_secondary'      => ['label' => 'Secondary colour',      'default' => '#f97316', 'desc' => 'Highlights, featured badges'],
    'theme_secondary_soft' => ['label' => 'Secondary soft (tint)', 'default' => '#ffedd9', 'desc' => 'Secondary badge backgrounds'],
    'theme_bg'             => ['label' => 'Page background',       'default' => '#f5f8f6', 'desc' => 'Overall page background'],
    'theme_surface'        => ['label' => 'Card surface',          'default' => '#ffffff',  'desc' => 'Card and panel background'],
    'theme_surface_muted'  => ['label' => 'Muted surface',         'default' => '#f1f7f3', 'desc' => 'Table rows, muted sections'],
    'theme_border'         => ['label' => 'Border colour',         'default' => '#e2e6ed', 'desc' => 'Panel and input borders'],
    'theme_text'           => ['label' => 'Body text',             'default' => '#1a2230', 'desc' => 'Primary text colour'],
];

$saved = false;
$reset = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!empty($_POST['reset_theme'])) {
        foreach (array_keys($themeKeys) as $key) {
            $pdo->prepare("DELETE FROM platform_settings WHERE setting_key = ?")->execute([$key]);
        }
        $reset = true;
    } else {
        foreach ($themeKeys as $key => $meta) {
            $val = trim($_POST[$key] ?? '');
            if (preg_match('/^#[0-9a-f]{3,6}$/i', $val)) {
                set_platform_setting($key, strtolower($val));
            }
        }
        $saved = true;
    }
    header('Location: theme.php' . ($reset ? '?reset=1' : '?saved=1'));
    exit;
}

$current = [];
foreach (array_keys($themeKeys) as $key) {
    $current[$key] = get_platform_setting($key) ?: $themeKeys[$key]['default'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"/>
    <meta name="viewport" content="width=device-width,initial-scale=1.0"/>
    <title>Theme Colours — AkuapemHub Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css"/>
    <style>
        .colour-row {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 12px 0;
            border-bottom: 1px solid var(--border);
            flex-wrap: wrap;
        }
        .colour-row:last-child { border-bottom: none; }
        .colour-row input[type="color"] {
            width: 48px;
            height: 40px;
            padding: 2px;
            border: 1.5px solid var(--border);
            border-radius: 8px;
            cursor: pointer;
            flex-shrink: 0;
        }
        .colour-row input[type="text"] {
            width: 110px;
            font-family: monospace;
            font-size: 0.95rem;
        }
        .colour-desc { color: var(--muted); font-size: 0.84rem; flex: 1; min-width: 160px; }
        .colour-label { font-weight: 600; font-size: 0.95rem; min-width: 180px; }
        .preview-box {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(80px, 1fr));
            gap: 10px;
            margin: 20px 0;
        }
        .preview-swatch {
            height: 48px;
            border-radius: 10px;
            border: 1px solid var(--border);
            display: flex;
            align-items: flex-end;
            padding: 4px 6px;
            font-size: 0.7rem;
            font-weight: 700;
            color: #fff;
            text-shadow: 0 1px 3px rgba(0,0,0,0.4);
        }
    </style>
</head>
<body>
<header class="topbar">
    <a href="index.php" class="button button-secondary button-small">← Back</a>
    <h1>Theme Colours</h1>
    <a href="../logout.php" class="button button-secondary button-small">Logout</a>
</header>
<main class="page-shell">
    <?php if (!empty($_GET['saved'])): ?>
        <div class="alert alert-success">Theme colours saved. Changes are live for all users.</div>
    <?php elseif (!empty($_GET['reset'])): ?>
        <div class="alert alert-success">Theme reset to defaults.</div>
    <?php endif; ?>

    <div class="card" style="margin-bottom:20px;">
        <h2 style="margin-top:0;">Current palette preview</h2>
        <div class="preview-box" id="preview-swatches">
            <?php foreach ($themeKeys as $key => $meta): ?>
                <div class="preview-swatch" id="swatch-<?php echo $key; ?>" style="background:<?php echo htmlspecialchars($current[$key]); ?>;">
                    <?php echo htmlspecialchars($meta['label']); ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <form method="post" class="panel">
        <h2 style="margin-top:0;">Edit colours</h2>
        <p class="meta">Pick a colour using the colour picker or type a hex value (e.g. #2f8f5b). Changes take effect immediately for all users after saving.</p>

        <?php foreach ($themeKeys as $key => $meta): ?>
            <div class="colour-row">
                <input type="color" id="cp-<?php echo $key; ?>" value="<?php echo htmlspecialchars($current[$key]); ?>"
                       oninput="syncHex('<?php echo $key; ?>', this.value)">
                <div class="colour-label"><?php echo htmlspecialchars($meta['label']); ?></div>
                <input type="text" name="<?php echo $key; ?>" id="hex-<?php echo $key; ?>"
                       value="<?php echo htmlspecialchars($current[$key]); ?>"
                       maxlength="7" placeholder="#000000"
                       oninput="syncPicker('<?php echo $key; ?>', this.value)">
                <span class="colour-desc"><?php echo htmlspecialchars($meta['desc']); ?></span>
            </div>
        <?php endforeach; ?>

        <div style="display:flex;gap:12px;margin-top:20px;flex-wrap:wrap;">
            <button type="submit" class="button button-primary">Save theme</button>
            <button type="submit" name="reset_theme" value="1" class="button button-secondary"
                    onclick="return confirm('Reset all colours to defaults?')">Reset to defaults</button>
        </div>
    </form>
</main>
<script>
function syncHex(key, val) {
    var hex = document.getElementById('hex-' + key);
    if (hex) hex.value = val;
    updateSwatch(key, val);
}
function syncPicker(key, val) {
    if (/^#[0-9a-f]{3,6}$/i.test(val)) {
        var cp = document.getElementById('cp-' + key);
        if (cp) cp.value = val;
        updateSwatch(key, val);
    }
}
function updateSwatch(key, val) {
    var sw = document.getElementById('swatch-' + key);
    if (sw && /^#[0-9a-f]{3,6}$/i.test(val)) sw.style.background = val;
}
</script>
</body>
</html>
