<?php
header('Content-Type: text/css; charset=utf-8');
header('Cache-Control: public, max-age=300');

try {
    require_once __DIR__ . '/../../db.php';
    $rows = $pdo->query("SELECT setting_key, setting_value FROM platform_settings WHERE setting_key LIKE 'theme_%'")->fetchAll();
    $s = array_column($rows, 'setting_value', 'setting_key');
} catch (Exception $e) {
    $s = [];
}

$hex = fn($v, $d) => preg_match('/^#[0-9a-f]{3,6}$/i', $v ?? '') ? $v : $d;

$vars = [
    '--primary'       => $hex($s['theme_primary']        ?? null, '#2f8f5b'),
    '--primary-dark'  => $hex($s['theme_primary_dark']   ?? null, '#246b45'),
    '--primary-soft'  => $hex($s['theme_primary_soft']   ?? null, '#e4f4ea'),
    '--secondary'     => $hex($s['theme_secondary']      ?? null, '#f97316'),
    '--secondary-soft'=> $hex($s['theme_secondary_soft'] ?? null, '#ffedd9'),
    '--bg'            => $hex($s['theme_bg']             ?? null, '#f5f8f6'),
    '--surface'       => $hex($s['theme_surface']        ?? null, '#ffffff'),
    '--surface-muted' => $hex($s['theme_surface_muted']  ?? null, '#f1f7f3'),
    '--border'        => $hex($s['theme_border']         ?? null, '#e2e6ed'),
    '--text'          => $hex($s['theme_text']           ?? null, '#1a2230'),
];

// Only output if at least one custom value was found
$hasCustom = !empty($s);
if ($hasCustom) {
    echo ":root {\n";
    foreach ($vars as $prop => $val) {
        echo "  {$prop}: {$val};\n";
    }
    echo "}\n";
}
