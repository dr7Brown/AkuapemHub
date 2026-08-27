<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';

require_login();
if (!is_admin()) {
    header('Location: index.php');
    exit;
}

$themeKeys = [
    // Core brand
    'theme_primary'        => ['label' => 'Primary',              'default' => '#2f8f5b', 'dark_default' => '#4ade80', 'desc' => 'Buttons, links, active nav'],
    'theme_primary_dark'   => ['label' => 'Primary dark',         'default' => '#246b45', 'dark_default' => '#22c55e', 'desc' => 'Hover states, headings'],
    'theme_primary_soft'   => ['label' => 'Primary tint',         'default' => '#e4f4ea', 'dark_default' => '#123120', 'desc' => 'Badge backgrounds, selected rows'],
    'theme_secondary'      => ['label' => 'Secondary / Accent',   'default' => '#f97316', 'dark_default' => '#fb923c', 'desc' => 'Featured badges, highlights'],
    'theme_secondary_soft' => ['label' => 'Secondary tint',       'default' => '#ffedd9', 'dark_default' => '#3a2410', 'desc' => 'Secondary badge backgrounds'],
    // Surfaces & background
    'theme_bg'             => ['label' => 'Page background',      'default' => '#f5f8f6', 'dark_default' => '#0f1512', 'desc' => 'Overall page background'],
    'theme_surface'        => ['label' => 'Card surface',         'default' => '#ffffff', 'dark_default' => '#1a211d', 'desc' => 'Cards, modals, panels'],
    'theme_surface_muted'  => ['label' => 'Muted surface',        'default' => '#f1f7f3', 'dark_default' => '#232b26', 'desc' => 'Table rows, muted sections'],
    // Text
    'theme_text'           => ['label' => 'Body text',            'default' => '#1a2230', 'dark_default' => '#e7ebe8', 'desc' => 'Primary text colour'],
    'theme_muted'          => ['label' => 'Muted text',           'default' => '#5b6472', 'dark_default' => '#9aa5a0', 'desc' => 'Labels, placeholders, subtitles'],
    // Borders
    'theme_border'         => ['label' => 'Border',               'default' => '#e2e6ed', 'dark_default' => '#2c352f', 'desc' => 'Panel and card borders'],
    'theme_border_strong'  => ['label' => 'Border strong',        'default' => '#cbd3df', 'dark_default' => '#3d4941', 'desc' => 'Input focus outlines, dividers'],
];

$saved = false;
$reset = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!empty($_POST['reset_theme'])) {
        foreach (array_keys($themeKeys) as $key) {
            $pdo->prepare("DELETE FROM platform_settings WHERE setting_key = ?")->execute([$key]);
            $pdo->prepare("DELETE FROM platform_settings WHERE setting_key = ?")->execute(['theme_dark_' . substr($key, 6)]);
        }
        $reset = true;
    } else {
        foreach ($themeKeys as $key => $meta) {
            $val = trim($_POST[$key] ?? '');
            if (preg_match('/^#[0-9a-f]{3,6}$/i', $val)) {
                set_platform_setting($key, strtolower($val));
            }
            $darkKey = 'theme_dark_' . substr($key, 6);
            $darkVal = trim($_POST[$darkKey] ?? '');
            if (preg_match('/^#[0-9a-f]{3,6}$/i', $darkVal)) {
                set_platform_setting($darkKey, strtolower($darkVal));
            }
        }
        set_platform_setting('dark_mode_enabled', isset($_POST['dark_mode_enabled']) ? '1' : '0');
        $saved = true;
    }
    header('Location: theme.php' . ($reset ? '?reset=1' : '?saved=1'));
    exit;
}

$current = [];
$currentDark = [];
foreach (array_keys($themeKeys) as $key) {
    $current[$key]     = get_platform_setting($key) ?: $themeKeys[$key]['default'];
    $darkKey            = 'theme_dark_' . substr($key, 6);
    $currentDark[$key] = get_platform_setting($darkKey) ?: $themeKeys[$key]['dark_default'];
}
$darkModeEnabled = get_platform_setting('dark_mode_enabled', '0') === '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"/>
    <meta name="viewport" content="width=device-width,initial-scale=1.0"/>
    <title>Theme Colours — AkuapemConnect Admin</title>
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
<main class="page-shell" style="max-width:900px;">
    <?php if (!empty($_GET['saved'])): ?>
        <div class="alert alert-success">Theme colours saved — live for all users now.</div>
    <?php elseif (!empty($_GET['reset'])): ?>
        <div class="alert alert-success">Theme reset to defaults.</div>
    <?php endif; ?>

    <!-- Live mini-preview panel -->
    <div class="card" style="margin-bottom:20px;" id="live-preview">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:8px;">
            <h2 style="margin:0;font-size:1rem;">Live preview</h2>
            <span style="font-size:.78rem;color:var(--muted);">Updates instantly as you pick colours — save to apply to all users.</span>
        </div>
        <div style="display:flex;gap:6px;margin-bottom:14px;">
            <button type="button" id="preview-mode-light" class="button button-small button-primary" onclick="setPreviewMode('light')">☀️ Light</button>
            <button type="button" id="preview-mode-dark" class="button button-small button-secondary" onclick="setPreviewMode('dark')">🌙 Dark</button>
        </div>
        <!-- Swatch row -->
        <div class="preview-box" id="preview-swatches" style="margin-bottom:16px;">
            <?php foreach ($themeKeys as $key => $meta): ?>
                <div class="preview-swatch" id="swatch-<?php echo $key; ?>" style="background:<?php echo htmlspecialchars($current[$key]); ?>;">
                    <?php echo htmlspecialchars($meta['label']); ?>
                </div>
            <?php endforeach; ?>
        </div>
        <!-- Mini UI mock -->
        <div id="ui-preview" style="background:var(--bg,<?php echo htmlspecialchars($current['theme_bg']); ?>);border-radius:12px;padding:16px;border:1px solid var(--border,<?php echo htmlspecialchars($current['theme_border']); ?>);">
            <div style="background:var(--primary,<?php echo htmlspecialchars($current['theme_primary']); ?>);border-radius:8px;padding:10px 14px;margin-bottom:10px;display:flex;align-items:center;justify-content:space-between;">
                <span style="color:#fff;font-weight:700;font-size:.9rem;">AkuapemConnect</span>
                <span style="background:rgba(255,255,255,.2);color:#fff;font-size:.72rem;padding:3px 9px;border-radius:20px;font-weight:700;">Nav</span>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-bottom:10px;">
                <div style="background:var(--surface,<?php echo htmlspecialchars($current['theme_surface']); ?>);border:1px solid var(--border,<?php echo htmlspecialchars($current['theme_border']); ?>);border-radius:8px;padding:10px;text-align:center;">
                    <div style="font-size:.8rem;font-weight:800;color:var(--primary,<?php echo htmlspecialchars($current['theme_primary']); ?>);">Jobs</div>
                    <div style="font-size:.68rem;color:var(--muted,<?php echo htmlspecialchars($current['theme_muted'] ?? '#5b6472'); ?>);">Card</div>
                </div>
                <div style="background:var(--primary-soft,<?php echo htmlspecialchars($current['theme_primary_soft']); ?>);border:1px solid var(--border,<?php echo htmlspecialchars($current['theme_border']); ?>);border-radius:8px;padding:10px;text-align:center;">
                    <div style="font-size:.8rem;font-weight:800;color:var(--primary-dark,<?php echo htmlspecialchars($current['theme_primary_dark']); ?>);">Selected</div>
                    <div style="font-size:.68rem;color:var(--muted,<?php echo htmlspecialchars($current['theme_muted'] ?? '#5b6472'); ?>);">Card</div>
                </div>
                <div style="background:var(--secondary-soft,<?php echo htmlspecialchars($current['theme_secondary_soft']); ?>);border:1px solid var(--border,<?php echo htmlspecialchars($current['theme_border']); ?>);border-radius:8px;padding:10px;text-align:center;">
                    <div style="font-size:.8rem;font-weight:800;color:var(--secondary,<?php echo htmlspecialchars($current['theme_secondary']); ?>);">Featured</div>
                    <div style="font-size:.68rem;color:var(--muted,<?php echo htmlspecialchars($current['theme_muted'] ?? '#5b6472'); ?>);">Badge</div>
                </div>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <button type="button" style="background:var(--primary,<?php echo htmlspecialchars($current['theme_primary']); ?>);color:#fff;border:none;border-radius:8px;padding:8px 16px;font-weight:700;font-size:.84rem;cursor:default;">Primary button</button>
                <button type="button" style="background:var(--surface,<?php echo htmlspecialchars($current['theme_surface']); ?>);color:var(--text,<?php echo htmlspecialchars($current['theme_text']); ?>);border:1.5px solid var(--border-strong,<?php echo htmlspecialchars($current['theme_border_strong'] ?? '#cbd3df'); ?>);border-radius:8px;padding:8px 16px;font-weight:700;font-size:.84rem;cursor:default;">Secondary</button>
                <span style="background:var(--secondary,<?php echo htmlspecialchars($current['theme_secondary']); ?>);color:#fff;font-size:.72rem;font-weight:800;padding:4px 10px;border-radius:20px;">⭐ Featured</span>
            </div>
        </div>
    </div>

    <div class="card" style="margin-bottom:20px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
        <div>
            <strong style="font-size:.95rem;">🌙 Dark Mode</strong>
            <p class="meta" style="margin:2px 0 0;">When on, the site follows each visitor's device preference automatically, with the dark colours below — no separate toggle needed on their end. Applies to the shared design system (buttons, nav, cards, forms); pages with their own custom colours may not fully adapt.</p>
        </div>
        <label style="display:flex;align-items:center;gap:8px;font-weight:700;white-space:nowrap;">
            <input type="checkbox" form="theme-form" name="dark_mode_enabled" value="1" style="width:auto;" <?php echo $darkModeEnabled ? 'checked' : ''; ?>>
            Enabled
        </label>
    </div>

    <form method="post" class="panel" id="theme-form">
        <?php echo csrf_field(); ?>
        <h2 style="margin-top:0;">Colour settings</h2>
        <p class="meta">Pick a colour or type a hex value for both light and dark. Changes are live for all users once saved.</p>

        <?php
        $groups = [
            'Brand'      => ['theme_primary','theme_primary_dark','theme_primary_soft','theme_secondary','theme_secondary_soft'],
            'Surfaces'   => ['theme_bg','theme_surface','theme_surface_muted'],
            'Typography' => ['theme_text','theme_muted'],
            'Borders'    => ['theme_border','theme_border_strong'],
        ];
        foreach ($groups as $groupLabel => $keys):
        ?>
        <p style="font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:var(--muted);margin:16px 0 6px;"><?php echo $groupLabel; ?></p>
        <?php foreach ($keys as $key):
            if (!isset($themeKeys[$key])) continue;
            $meta = $themeKeys[$key];
            $darkKey = 'theme_dark_' . substr($key, 6);
        ?>
            <div class="colour-row">
                <div class="colour-label" style="min-width:150px;"><?php echo htmlspecialchars($meta['label']); ?></div>
                <span style="font-size:.7rem;font-weight:800;color:var(--muted);">☀️</span>
                <input type="color" id="cp-<?php echo $key; ?>" value="<?php echo htmlspecialchars($current[$key]); ?>"
                       oninput="syncHex('<?php echo $key; ?>', this.value)">
                <input type="text" name="<?php echo $key; ?>" id="hex-<?php echo $key; ?>"
                       value="<?php echo htmlspecialchars($current[$key]); ?>"
                       maxlength="7" placeholder="#000000"
                       oninput="syncPicker('<?php echo $key; ?>', this.value)">
                <span style="font-size:.7rem;font-weight:800;color:var(--muted);margin-left:8px;">🌙</span>
                <input type="color" id="cp-<?php echo $darkKey; ?>" value="<?php echo htmlspecialchars($currentDark[$key]); ?>"
                       oninput="syncHex('<?php echo $darkKey; ?>', this.value)">
                <input type="text" name="<?php echo $darkKey; ?>" id="hex-<?php echo $darkKey; ?>"
                       value="<?php echo htmlspecialchars($currentDark[$key]); ?>"
                       maxlength="7" placeholder="#000000"
                       oninput="syncPicker('<?php echo $darkKey; ?>', this.value)">
                <span class="colour-desc"><?php echo htmlspecialchars($meta['desc']); ?></span>
            </div>
        <?php endforeach; endforeach; ?>

        <div style="display:flex;gap:12px;margin-top:20px;flex-wrap:wrap;">
            <button type="submit" class="button button-primary">Save & apply to all users</button>
            <button type="submit" name="reset_theme" value="1" class="button button-secondary"
                    onclick="return confirm('Reset all colours to defaults?')">Reset to defaults</button>
        </div>
    </form>
</main>
<script>
// Suffix (shared between theme_X and theme_dark_X) → CSS variable name
// (mirrors db.php's propMap).
var propMap = {
    primary:        '--primary',
    primary_dark:   '--primary-dark',
    primary_soft:   '--primary-soft',
    secondary:      '--secondary',
    secondary_soft: '--secondary-soft',
    bg:             '--bg',
    surface:        '--surface',
    surface_muted:  '--surface-muted',
    border:         '--border',
    border_strong:  '--border-strong',
    text:           '--text',
    muted:          '--muted',
};

var previewMode = 'light';

// 'theme_primary' -> {mode:'light', suffix:'primary'}; 'theme_dark_primary' -> {mode:'dark', suffix:'primary'}
function keyParts(fullKey) {
    if (fullKey.indexOf('theme_dark_') === 0) return { mode: 'dark', suffix: fullKey.slice(11) };
    return { mode: 'light', suffix: fullKey.slice(6) };
}

function syncHex(fullKey, val) {
    var hex = document.getElementById('hex-' + fullKey);
    if (hex) hex.value = val;
    applyLive(fullKey, val);
}
function syncPicker(fullKey, val) {
    if (/^#[0-9a-f]{3,6}$/i.test(val)) {
        var cp = document.getElementById('cp-' + fullKey);
        if (cp) cp.value = val;
        applyLive(fullKey, val);
    }
}
function applyLive(fullKey, val) {
    if (!/^#[0-9a-f]{3,6}$/i.test(val)) return;
    var parts = keyParts(fullKey);
    if (parts.mode !== previewMode) return; // editing the mode that isn't currently previewed
    var sw = document.getElementById('swatch-theme_' + parts.suffix);
    if (sw) sw.style.background = val;
    var prop = propMap[parts.suffix];
    if (prop) document.documentElement.style.setProperty(prop, val);
}

// Switches the live preview (swatches + mock UI) between the light and dark
// colour sets — doesn't change which mode actually ships to visitors, that's
// governed by the "Dark Mode" checkbox + each visitor's own device setting.
function setPreviewMode(mode) {
    previewMode = mode;
    document.getElementById('preview-mode-light').className = 'button button-small ' + (mode === 'light' ? 'button-primary' : 'button-secondary');
    document.getElementById('preview-mode-dark').className  = 'button button-small ' + (mode === 'dark'  ? 'button-primary' : 'button-secondary');
    Object.keys(propMap).forEach(function (suffix) {
        var fullKey = (mode === 'dark' ? 'theme_dark_' : 'theme_') + suffix;
        var hex = document.getElementById('hex-' + fullKey);
        var val = hex ? hex.value : null;
        if (val && /^#[0-9a-f]{3,6}$/i.test(val)) {
            var sw = document.getElementById('swatch-theme_' + suffix);
            if (sw) sw.style.background = val;
            document.documentElement.style.setProperty(propMap[suffix], val);
        }
    });
}
</script>
</body>
</html>
