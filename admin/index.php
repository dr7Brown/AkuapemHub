<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';

require_login();
if (!is_admin_or_manager()) {
    header('Location: ../dashboard.php');
    exit;
}

$totalUsers         = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$totalRequests      = (int)$pdo->query('SELECT COUNT(*) FROM service_requests')->fetchColumn();
$openRequests       = (int)$pdo->query('SELECT COUNT(*) FROM service_requests WHERE status="open"')->fetchColumn();
$pendingRequests    = (int)$pdo->query('SELECT COUNT(*) FROM service_requests WHERE status="pending"')->fetchColumn();
$completedRequests  = (int)$pdo->query('SELECT COUNT(*) FROM service_requests WHERE status="completed"')->fetchColumn();
$premiumWorkers     = get_premium_worker_count();
$pendingPayments    = (int)$pdo->query("SELECT COUNT(*) FROM platform_payments WHERE status='pending'")->fetchColumn();
$pendingPostingFees = (int)$pdo->query("SELECT COUNT(*) FROM service_requests WHERE posting_fee_status='pending'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard — AkuapemHub</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        html, body { height: 100%; margin: 0; }
        body { display: flex; flex-direction: column; min-height: 100vh; background: var(--bg, #f9fafb); }

        /* ── Topbar ─────────────────────────────────────────────── */
        .adm-bar {
            position: sticky; top: 0; z-index: 300;
            background: var(--surface, #fff);
            border-bottom: 1px solid var(--border, #e5e7eb);
            display: flex; align-items: stretch; min-height: 52px;
            box-shadow: 0 1px 3px rgba(0,0,0,.06);
        }
        .adm-brand {
            display: flex; align-items: center; gap: 8px;
            padding: 0 18px; font-weight: 800; font-size: .95rem;
            color: var(--primary, #0f766e); white-space: nowrap;
            border-right: 1px solid var(--border, #e5e7eb);
            text-decoration: none; flex-shrink: 0;
            transition: background .12s;
        }
        .adm-brand:hover { background: var(--surface-muted, #f8fafc); }
        .adm-brand-badge {
            font-size: .6rem; font-weight: 800; letter-spacing: .06em;
            background: var(--primary, #0f766e); color: #fff;
            padding: 2px 7px; border-radius: 20px; text-transform: uppercase;
        }
        .adm-nav {
            display: flex; align-items: center; gap: 2px;
            overflow-x: auto; padding: 0 6px; flex: 1;
            scrollbar-width: none; -webkit-overflow-scrolling: touch;
        }
        .adm-nav::-webkit-scrollbar { display: none; }
        .adm-nav-sep { width: 1px; height: 22px; background: var(--border, #e5e7eb); margin: 0 4px; flex-shrink: 0; }
        .adm-nav a {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 6px 11px; border-radius: 8px; white-space: nowrap;
            font-size: .82rem; font-weight: 600; text-decoration: none;
            color: var(--text-muted, #6b7280);
            transition: background .12s, color .12s;
        }
        .adm-nav a:hover  { background: var(--surface-muted, #f3f4f6); color: var(--text, #111); }
        .adm-nav a.active { background: var(--primary-soft, #d1faf4); color: var(--primary, #0f766e); }
        .adm-bar-end {
            display: flex; align-items: center; gap: 7px;
            padding: 0 12px; border-left: 1px solid var(--border, #e5e7eb); flex-shrink: 0;
        }

        /* ── Page body ──────────────────────────────────────────── */
        .adm-body { flex: 1; position: relative; }

        /* ── Home panel ─────────────────────────────────────────── */
        .adm-home { max-width: 1060px; margin: 0 auto; padding: 24px 16px 56px; }
        .adm-section-label {
            font-size: .75rem; font-weight: 800; text-transform: uppercase;
            letter-spacing: .07em; color: var(--text-muted, #6b7280); margin: 0 0 12px;
        }
        .adm-stats {
            display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            gap: 12px; margin-bottom: 28px;
        }
        .adm-stat {
            background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb);
            border-radius: 12px; padding: 16px 12px; text-align: center;
        }
        .adm-stat strong {
            display: block; font-size: 1.9rem; font-weight: 900; line-height: 1.1;
            color: var(--primary, #0f766e);
        }
        .adm-stat span { font-size: .74rem; color: var(--text-muted, #6b7280); margin-top: 2px; display: block; }
        .adm-cards {
            display: grid; grid-template-columns: repeat(auto-fill, minmax(158px, 1fr)); gap: 12px;
        }
        .adm-card {
            background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb);
            border-radius: 12px; padding: 16px 14px;
            display: flex; flex-direction: column; gap: 5px;
            text-decoration: none; color: inherit; cursor: pointer;
            transition: box-shadow .15s, transform .15s, border-color .15s;
        }
        .adm-card:hover {
            box-shadow: 0 4px 20px rgba(0,0,0,.09);
            transform: translateY(-2px);
            border-color: var(--primary, #0f766e);
        }
        .adm-card-icon  { font-size: 1.4rem; }
        .adm-card-title { font-weight: 700; font-size: .9rem; }
        .adm-card-desc  { font-size: .73rem; color: var(--text-muted, #6b7280); }

        /* ── Alert ──────────────────────────────────────────────── */
        .adm-pay-alert {
            display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
            background: #fffbeb; border: 1px solid #f59e0b;
            border-radius: 10px; padding: 12px 16px; margin-bottom: 22px; font-size: .9rem;
        }

        /* ── AJAX content area ──────────────────────────────────── */
        #adm-ajax { display: none; }

        /* ── Loading indicator ──────────────────────────────────── */
        .adm-loading {
            display: flex; align-items: center; justify-content: center;
            gap: 12px; padding: 80px 20px;
            color: var(--text-muted, #6b7280); font-size: .9rem;
        }
        .adm-spinner {
            width: 26px; height: 26px; flex-shrink: 0;
            border: 3px solid var(--border, #e5e7eb);
            border-top-color: var(--primary, #0f766e);
            border-radius: 50%; animation: adm-spin .65s linear infinite;
        }
        @keyframes adm-spin { to { transform: rotate(360deg); } }

        @media (max-width: 520px) {
            .adm-stats { grid-template-columns: repeat(3, 1fr); }
            .adm-cards { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>

<!-- ── Top navigation bar ──────────────────────────────────────── -->
<header class="adm-bar">
    <a class="adm-brand" href="index.php" id="adm-home-btn">
        🔧 <?php echo is_admin() ? 'Admin' : 'Manager'; ?>
        <span class="adm-brand-badge"><?php echo is_admin() ? 'admin' : 'mgr'; ?></span>
    </a>

    <nav class="adm-nav" id="adm-nav" aria-label="Admin sections">
        <?php if (is_admin()): ?>
        <a href="users.php"         data-page="users.php">👥 Users</a>
        <?php endif; ?>
        <a href="requests.php"      data-page="requests.php">📋 Requests</a>
        <a href="applications.php"  data-page="applications.php">📝 Applications</a>
        <?php if (is_admin()): ?>
        <div class="adm-nav-sep" aria-hidden="true"></div>
        <a href="disputes.php"          data-page="disputes.php">⚖️ Disputes</a>
        <a href="payments.php"          data-page="payments.php">💳 Payments</a>
        <a href="referrals.php"         data-page="referrals.php">🔗 Referrals</a>
        <a href="analytics.php"         data-page="analytics.php">📊 Analytics</a>
        <div class="adm-nav-sep" aria-hidden="true"></div>
        <a href="business_messages.php" data-page="business_messages.php">💬 Messages</a>
        <a href="monetization.php"      data-page="monetization.php">💰 Monetize</a>
        <a href="communication.php"     data-page="communication.php">📣 Broadcast</a>
        <div class="adm-nav-sep" aria-hidden="true"></div>
        <a href="news.php"              data-page="news.php">📰 News</a>
        <a href="ads.php"               data-page="ads.php">📣 Ads</a>
        <div class="adm-nav-sep" aria-hidden="true"></div>
        <a href="contact_settings.php"  data-page="contact_settings.php">📞 Contact</a>
        <a href="theme.php"             data-page="theme.php">🎨 Theme</a>
        <a href="audit_logs.php"        data-page="audit_logs.php">📜 Audit</a>
        <?php endif; ?>
    </nav>

    <div class="adm-bar-end">
        <a href="../settings.php" class="button button-secondary button-small">Settings</a>
        <a href="../logout.php"   class="button button-secondary button-small">Logout</a>
    </div>
</header>

<!-- ── Body ────────────────────────────────────────────────────── -->
<div class="adm-body">

    <!-- Home panel: stats + quick-access cards -->
    <div id="adm-home" class="adm-home">

        <?php if ($pendingPayments > 0): ?>
        <div class="adm-pay-alert">
            💳 <strong><?php echo $pendingPayments; ?> pending payment<?php echo $pendingPayments !== 1 ? 's' : ''; ?></strong>
            awaiting confirmation.
            <button class="button button-primary button-small" onclick="admLoad('payments.php')">View payments →</button>
            <?php if ($pendingPostingFees > 0): ?>
            &nbsp;·&nbsp;
            <?php echo $pendingPostingFees; ?> job<?php echo $pendingPostingFees !== 1 ? 's' : ''; ?> blocked by unpaid posting fee.
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <p class="adm-section-label">Platform overview</p>
        <div class="adm-stats">
            <div class="adm-stat"><strong><?php echo $totalUsers; ?></strong><span>Users</span></div>
            <div class="adm-stat"><strong><?php echo $totalRequests; ?></strong><span>Requests</span></div>
            <div class="adm-stat"><strong><?php echo $openRequests; ?></strong><span>Open</span></div>
            <div class="adm-stat"><strong><?php echo $pendingRequests; ?></strong><span>Pending</span></div>
            <div class="adm-stat"><strong><?php echo $completedRequests; ?></strong><span>Completed</span></div>
            <div class="adm-stat"><strong><?php echo $premiumWorkers; ?></strong><span>Premium</span></div>
        </div>

        <p class="adm-section-label">Quick access</p>
        <div class="adm-cards">
        <?php
        $cards = [
            ['users.php',           '👥', 'Users',        'Manage accounts & roles',   true ],
            ['requests.php',        '📋', 'Requests',     'Service request queue',      false],
            ['applications.php',    '📝', 'Applications', 'Job applications',           false],
            ['disputes.php',        '⚖️', 'Disputes',     'Resolve user conflicts',     true ],
            ['payments.php',        '💳', 'Payments',     'Platform payment records',   true ],
            ['referrals.php',       '🔗', 'Referrals',    'Referral programme',         true ],
            ['analytics.php',       '📊', 'Analytics',    'Stats & growth trends',      true ],
            ['business_messages.php','💬','Messages',     'Business enquiries',         true ],
            ['monetization.php',    '💰', 'Monetize',     'Pricing, plans & fees',      true ],
            ['communication.php',   '📣', 'Broadcast',    'Push notifications',         true ],
            ['news.php',            '📰', 'News',         'Articles & blog posts',      true ],
            ['ads.php',             '📣', 'Ads',          'Manage advertisements',      true ],
            ['contact_settings.php','📞', 'Contact',      'Contact page information',   true ],
            ['theme.php',           '🎨', 'Theme',        'Colours & branding',         true ],
            ['audit_logs.php',      '📜', 'Audit Logs',   'Admin action history',       true ],
        ];
        foreach ($cards as [$href, $icon, $title, $desc, $adminOnly]):
            if ($adminOnly && !is_admin()) continue;
        ?>
            <a class="adm-card" href="<?php echo $href; ?>" data-page="<?php echo $href; ?>">
                <div class="adm-card-icon"><?php echo $icon; ?></div>
                <div class="adm-card-title"><?php echo $title; ?></div>
                <div class="adm-card-desc"><?php echo $desc; ?></div>
            </a>
        <?php endforeach; ?>
        </div>
    </div>

    <!-- AJAX content injected here -->
    <div id="adm-ajax"></div>

</div><!-- .adm-body -->

<script>
(function () {
    'use strict';

    var homeEl      = document.getElementById('adm-home');
    var ajaxEl      = document.getElementById('adm-ajax');
    var pageStyleEl = null;   // reused <style> tag for loaded-page CSS

    /* ── Helpers ──────────────────────────────────────────────── */

    function setActive(url) {
        document.querySelectorAll('.adm-nav a[data-page]').forEach(function (a) {
            var match = url && (url === a.dataset.page || url.indexOf('/' + a.dataset.page) !== -1);
            a.classList.toggle('active', !!match);
        });
    }

    function isInternalAdminPage(href) {
        try {
            var u = new URL(href, window.location.href);
            return u.hostname === window.location.hostname
                && /\/admin\/[a-z0-9_-]+\.php/.test(u.pathname)
                && !/logout|login/i.test(u.pathname);
        } catch (e) { return false; }
    }

    function ensurePageStyle() {
        if (!pageStyleEl) {
            pageStyleEl = document.createElement('style');
            pageStyleEl.id = 'adm-page-style';
            document.head.appendChild(pageStyleEl);
        }
        return pageStyleEl;
    }

    /* ── Show home dashboard ──────────────────────────────────── */

    function showHome(push) {
        homeEl.style.display = '';
        ajaxEl.style.display = 'none';
        ajaxEl.innerHTML = '';
        setActive(null);
        document.title = 'Admin Dashboard — AkuapemHub';
        if (push !== false) {
            history.pushState({ adm: 'home' }, '', 'index.php');
        }
    }

    /* ── Load a page into the AJAX panel ─────────────────────── */

    function admLoad(href, push) {
        var absUrl = new URL(href, window.location.href).href;

        homeEl.style.display = 'none';
        ajaxEl.style.display = 'block';
        ajaxEl.innerHTML = '<div class="adm-loading"><div class="adm-spinner"></div>Loading…</div>';
        setActive(absUrl);

        if (push !== false) {
            history.pushState({ adm: 'page', url: absUrl }, '', absUrl);
        }

        fetch(absUrl, { credentials: 'same-origin' })
            .then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.text();
            })
            .then(function (html) {
                var parser = new DOMParser();
                var doc    = parser.parseFromString(html, 'text/html');

                /* Page title */
                var t = doc.querySelector('title');
                if (t) document.title = t.textContent;

                /* Page-specific <style> blocks from <head> */
                var css = '';
                doc.querySelectorAll('head style').forEach(function (s) { css += s.textContent; });
                ensurePageStyle().textContent = css;

                /* Inject <main> (outerHTML preserves the shell class + its CSS) */
                var main = doc.querySelector('main');
                ajaxEl.innerHTML = main ? main.outerHTML : doc.body.innerHTML;

                /* Re-execute inline <script> tags */
                ajaxEl.querySelectorAll('script').forEach(function (old) {
                    var s = document.createElement('script');
                    if (old.src) {
                        s.src = old.src; s.async = false;
                    } else {
                        s.textContent = old.textContent;
                    }
                    document.head.appendChild(s);
                    if (!old.src) document.head.removeChild(s);
                });

                /* Scroll to top of the page without hiding content under the sticky bar */
                window.scrollTo({ top: 0 });
            })
            .catch(function () {
                ajaxEl.innerHTML =
                    '<div style="padding:30px 16px;">' +
                    '<div class="alert alert-error">Failed to load the page. ' +
                    '<a href="' + absUrl + '">Open directly →</a></div></div>';
            });
    }

    /* ── Wire topbar nav links ────────────────────────────────── */
    document.querySelectorAll('.adm-nav a[data-page]').forEach(function (a) {
        a.addEventListener('click', function (e) {
            e.preventDefault();
            admLoad(a.getAttribute('href'));
        });
    });

    /* ── Wire quick-access cards ──────────────────────────────── */
    document.querySelectorAll('#adm-home .adm-card[data-page]').forEach(function (a) {
        a.addEventListener('click', function (e) {
            e.preventDefault();
            admLoad(a.getAttribute('href'));
        });
    });

    /* ── Brand / home button ──────────────────────────────────── */
    document.getElementById('adm-home-btn').addEventListener('click', function (e) {
        e.preventDefault();
        showHome();
    });

    /* ── Delegate: intercept links inside AJAX content ──────── */
    ajaxEl.addEventListener('click', function (e) {
        var a = e.target.closest('a[href]');
        if (!a) return;
        if (!isInternalAdminPage(a.href)) return;
        e.preventDefault();
        admLoad(a.href);
    });

    /* ── Browser back / forward ───────────────────────────────── */
    window.addEventListener('popstate', function (e) {
        if (!e.state || e.state.adm === 'home') {
            showHome(false);
        } else if (e.state.adm === 'page' && e.state.url) {
            admLoad(e.state.url, false);
        }
    });

    /* Seed the initial history entry so back-button works */
    history.replaceState({ adm: 'home' }, '', window.location.href);

    /* Expose for inline onclick usage (e.g. the payments alert button) */
    window.admLoad = admLoad;
}());
</script>
</body>
</html>
