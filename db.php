<?php
require_once __DIR__ . '/config.php';

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_NAME),
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    // Force this connection's NOW()/CURRENT_TIMESTAMP to UTC, matching PHP's
    // date_default_timezone_set('UTC') in config.php — regardless of whatever
    // timezone the underlying MySQL server itself is configured to.
    $pdo->exec("SET time_zone = '+00:00'");
} catch (PDOException $e) {
    die('Database connection failed: ' . htmlspecialchars($e->getMessage()));
}

// ── Auto-inject favicon links + admin theme colours into every HTML page ────
// Build the extra <head> markup once (at request start) and close over it
// in an ob_start callback that splices it before </head>.
// Works for ALL pages — public, admin, auth — without changing individual files.
if (!defined('AKC_THEME_INJECTED')) {
    define('AKC_THEME_INJECTED', true);

    $__faviconBase = rtrim(BASE_URL, '/') . '/uploads/favicon';
    $__headExtra   =
        '<link rel="icon" type="image/png" sizes="32x32" href="' . $__faviconBase . '/favicon-32x32.png">' .
        '<link rel="icon" type="image/png" sizes="16x16" href="' . $__faviconBase . '/favicon-16x16.png">' .
        '<link rel="shortcut icon" href="' . $__faviconBase . '/favicon.ico">' .
        '<link rel="apple-touch-icon" sizes="180x180" href="' . $__faviconBase . '/apple-touch-icon.png">' .
        '<link rel="manifest" href="' . $__faviconBase . '/site.webmanifest">';
    unset($__faviconBase);

    try {
        $__themeRows = $pdo->query(
            "SELECT setting_key, setting_value FROM platform_settings WHERE setting_key LIKE 'theme_%'"
        )->fetchAll(PDO::FETCH_ASSOC);

        $__propMap = [
            'theme_primary'        => '--primary',
            'theme_primary_dark'   => '--primary-dark',
            'theme_primary_soft'   => '--primary-soft',
            'theme_secondary'      => '--secondary',
            'theme_secondary_soft' => '--secondary-soft',
            'theme_bg'             => '--bg',
            'theme_surface'        => '--surface',
            'theme_surface_muted'  => '--surface-muted',
            'theme_border'         => '--border',
            'theme_border_strong'  => '--border-strong',
            'theme_text'           => '--text',
            'theme_muted'          => '--muted',
        ];

        $__cssLines = [];
        foreach ($__themeRows as $__row) {
            $__prop = $__propMap[$__row['setting_key']] ?? null;
            $__val  = $__row['setting_value'];
            if ($__prop && preg_match('/^#[0-9a-f]{3,6}$/i', $__val)) {
                $__cssLines[] = "{$__prop}:{$__val}";
            }
        }
        unset($__themeRows, $__propMap, $__row, $__prop, $__val);

        if ($__cssLines) {
            $__headExtra .= '<style id="akc-theme">:root{' . implode(';', $__cssLines) . '}</style>';
        }
        unset($__cssLines);
    } catch (Exception $__e) {
        // No theme rows or DB not ready — favicon links still get injected
    }

    // Admin impersonation banner — injected the same way, into <body> instead
    // of <head>, so no per-page changes are needed for "Log In As This User"
    // (admin/user_edit.php) to show a persistent, unmissable "Exit" link.
    $__bodyExtra = '';
    if (!empty($_SESSION['impersonator_admin_id'])) {
        $__viewingAs = htmlspecialchars($_SESSION['user']['name'] ?? 'user', ENT_QUOTES, 'UTF-8');
        $__bodyExtra =
            '<div style="position:fixed;top:0;left:0;right:0;z-index:99999;background:#f59e0b;color:#1a1a1a;' .
            'padding:8px 14px;font-size:.82rem;font-weight:700;text-align:center;">' .
            '👁️ Viewing as ' . $__viewingAs . ' &mdash; ' .
            '<a href="' . rtrim(BASE_URL, '/') . '/exit_impersonation.php" style="color:#1a1a1a;text-decoration:underline;">Exit to Admin</a>' .
            '</div><div style="height:38px;"></div>';
    }

    ob_start(function (string $buf) use ($__headExtra, $__bodyExtra): string {
        $pos = stripos($buf, '</head>');
        if ($pos !== false) $buf = substr($buf, 0, $pos) . $__headExtra . substr($buf, $pos);

        if ($__bodyExtra !== '') {
            $bodyPos = stripos($buf, '<body');
            if ($bodyPos !== false) {
                $tagEnd = strpos($buf, '>', $bodyPos);
                if ($tagEnd !== false) {
                    $buf = substr($buf, 0, $tagEnd + 1) . $__bodyExtra . substr($buf, $tagEnd + 1);
                }
            }
        }
        return $buf;
    });
    unset($__headExtra, $__bodyExtra);
}
