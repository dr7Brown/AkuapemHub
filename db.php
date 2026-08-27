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

    // Light values always apply via :root. Dark values (theme_dark_*) only
    // ship at all when an admin has switched dark_mode_enabled on (Admin →
    // Theme) — otherwise the site stays exactly as it always has, even for
    // visitors whose device is set to dark. When on, dark colours apply
    // automatically via prefers-color-scheme, with [data-theme] as an
    // explicit per-visitor override (set by the toggle button below, stored
    // in a cookie so it's known here before the page even renders).
    $__darkModeEnabled = false;
    try {
        $__themeRows = $pdo->query(
            "SELECT setting_key, setting_value FROM platform_settings WHERE setting_key LIKE 'theme_%' OR setting_key = 'dark_mode_enabled'"
        )->fetchAll(PDO::FETCH_ASSOC);

        $__propMap = [
            'primary' => '--primary', 'primary_dark' => '--primary-dark', 'primary_soft' => '--primary-soft',
            'secondary' => '--secondary', 'secondary_soft' => '--secondary-soft',
            'bg' => '--bg', 'surface' => '--surface', 'surface_muted' => '--surface-muted',
            'border' => '--border', 'border_strong' => '--border-strong',
            'text' => '--text', 'muted' => '--muted',
        ];

        $__lightVals = [];
        $__darkVals  = [];
        foreach ($__themeRows as $__row) {
            if ($__row['setting_key'] === 'dark_mode_enabled') {
                $__darkModeEnabled = $__row['setting_value'] === '1';
                continue;
            }
            if (!preg_match('/^#[0-9a-f]{3,6}$/i', $__row['setting_value'])) continue;
            if (strpos($__row['setting_key'], 'theme_dark_') === 0) {
                $__suffix = substr($__row['setting_key'], 11);
                if (isset($__propMap[$__suffix])) $__darkVals[$__propMap[$__suffix]] = $__row['setting_value'];
            } else {
                $__suffix = substr($__row['setting_key'], 6);
                if (isset($__propMap[$__suffix])) $__lightVals[$__propMap[$__suffix]] = $__row['setting_value'];
            }
        }
        unset($__themeRows, $__propMap, $__row, $__suffix);

        $__cssBlocks = '';
        if ($__lightVals) {
            $__lines = [];
            foreach ($__lightVals as $__p => $__v) $__lines[] = "{$__p}:{$__v}";
            $__cssBlocks .= ':root{' . implode(';', $__lines) . '}';
            unset($__lines);
        }
        if ($__darkModeEnabled && $__darkVals) {
            $__lines = [];
            foreach ($__darkVals as $__p => $__v) $__lines[] = "{$__p}:{$__v}";
            $__darkCss = implode(';', $__lines);
            $__cssBlocks .= '@media(prefers-color-scheme:dark){:root:not([data-theme="light"]){' . $__darkCss . '}}';
            $__cssBlocks .= '[data-theme="dark"]{' . $__darkCss . '}';
            unset($__lines, $__darkCss);
        }
        if ($__cssBlocks) {
            $__headExtra .= '<style id="akc-theme">' . $__cssBlocks . '</style>';
        }
        unset($__cssBlocks, $__lightVals, $__darkVals);
    } catch (Exception $__e) {
        // No theme rows or DB not ready — favicon links still get injected
        $__darkModeEnabled = false;
    }

    // Explicit per-visitor override (cookie set by the toggle button below).
    // Empty means "follow the device" — no [data-theme] attribute at all, so
    // the prefers-color-scheme media query alone decides.
    $__themeModeCookie = $__darkModeEnabled ? ($_COOKIE['theme_mode'] ?? '') : '';
    $__htmlDataTheme   = in_array($__themeModeCookie, ['light', 'dark'], true) ? $__themeModeCookie : '';
    unset($__themeModeCookie);

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

    // Site-wide light/dark/system toggle — only rendered once an admin has
    // actually turned dark mode on, so nothing changes for existing sites.
    if ($__darkModeEnabled) {
        $__toggleIcon = $__htmlDataTheme === 'dark' ? '🌙' : ($__htmlDataTheme === 'light' ? '☀️' : '🖥️');
        $__bodyExtra .=
            '<button id="akc-theme-toggle" type="button" aria-label="Toggle colour theme" onclick="akcCycleTheme()" ' .
            'style="position:fixed;bottom:16px;right:16px;z-index:99998;width:42px;height:42px;border-radius:50%;' .
            'border:1px solid var(--border,#e2e6ed);background:var(--surface,#fff);color:var(--text,#1a2230);' .
            'font-size:1.1rem;display:flex;align-items:center;justify-content:center;cursor:pointer;' .
            'box-shadow:0 4px 14px rgba(0,0,0,.15);">' . $__toggleIcon . '</button>' .
            '<script>(function(){' .
            'function akcApply(mode){var b=document.getElementById("akc-theme-toggle");' .
            'if(mode==="system"){document.documentElement.removeAttribute("data-theme");}' .
            'else{document.documentElement.setAttribute("data-theme",mode);}' .
            'if(b)b.textContent=mode==="dark"?"🌙":(mode==="light"?"☀️":"🖥️");}' .
            'window.akcCycleTheme=function(){' .
            'var m=(document.cookie.match(/(?:^|; )theme_mode=([^;]*)/)||[])[1]||"system";' .
            'var n=m==="system"?"dark":(m==="dark"?"light":"system");' .
            'document.cookie="theme_mode="+n+";path=/;max-age=31536000";akcApply(n);};' .
            '})();</script>';
        unset($__toggleIcon);
    }

    ob_start(function (string $buf) use ($__headExtra, $__bodyExtra, $__htmlDataTheme): string {
        if ($__htmlDataTheme !== '') {
            $buf = preg_replace('/<html\b/i', '<html data-theme="' . $__htmlDataTheme . '"', $buf, 1);
        }

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
    unset($__headExtra, $__bodyExtra, $__htmlDataTheme, $__darkModeEnabled);
}
