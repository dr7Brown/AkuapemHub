<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';

require_login();
if (!is_admin_or_manager()) {
    header('Location: ../jobs.php');
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

        /* ── Scroll buttons ──────────────────────────────────────── */
        .adm-sb {
            display: none; align-items: center; justify-content: center;
            width: 30px; flex-shrink: 0; border: none; cursor: pointer;
            background: none; font-size: 1.2rem; font-weight: 700;
            color: var(--text-muted, #9ca3af);
            transition: background .12s, color .12s;
        }
        .adm-sb:hover { background: var(--surface-muted, #f3f4f6); color: var(--text, #111); }
        .adm-sb.vis { display: flex; }
        #adm-sl { border-right: 1px solid var(--border, #e5e7eb); }
        #adm-sr { border-left:  1px solid var(--border, #e5e7eb); }

        /* ── Nav rail ───────────────────────────────────────────── */
        .adm-nav {
            display: flex; align-items: center; gap: 2px;
            overflow-x: auto; padding: 0 6px; flex: 1;
            scrollbar-width: none; -webkit-overflow-scrolling: touch;
        }
        .adm-nav::-webkit-scrollbar { display: none; }

        /* ── Category buttons ───────────────────────────────────── */
        .adm-cat-btn {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 6px 11px; border-radius: 8px; white-space: nowrap;
            font-size: .82rem; font-weight: 700;
            background: none; border: none; cursor: pointer;
            color: var(--text-muted, #6b7280);
            transition: background .12s, color .12s;
            height: 36px;
        }
        .adm-cat-btn:hover  { background: var(--surface-muted, #f3f4f6); color: var(--text, #111); }
        .adm-cat-btn.open   { background: var(--surface-muted, #f3f4f6); color: var(--text, #111); }
        .adm-cat-btn.active { background: var(--primary-soft, #d1faf4); color: var(--primary, #0f766e); }
        .adm-caret {
            font-size: .65rem; transition: transform .18s; display: inline-block; opacity: .6;
        }
        .adm-cat-btn.open .adm-caret { transform: rotate(180deg); }

        /* ── Dropdown panels — fixed to viewport below topbar ──── */
        /* Panels live in #adm-drops (direct body child), positioned fixed */
        #adm-drops { position: fixed; top: 52px; left: 0; z-index: 500; pointer-events: none; }
        .adm-drop {
            position: absolute; top: 4px;
            background: var(--surface, #fff);
            border: 1px solid var(--border, #e5e7eb);
            border-radius: 10px; padding: 6px;
            box-shadow: 0 8px 28px rgba(0,0,0,.13);
            min-width: 168px;
            pointer-events: none; opacity: 0;
            transform: translateY(-6px);
            transition: opacity .14s, transform .14s;
        }
        .adm-drop.open {
            pointer-events: auto; opacity: 1; transform: translateY(0);
        }
        .adm-drop a {
            display: flex; align-items: center; gap: 8px;
            padding: 8px 10px; border-radius: 6px;
            font-size: .83rem; font-weight: 600;
            text-decoration: none; color: var(--text, #111);
            transition: background .12s;
        }
        .adm-drop a:hover  { background: var(--surface-muted, #f3f4f6); }
        .adm-drop a.active {
            background: var(--primary-soft, #d1faf4);
            color: var(--primary, #0f766e); font-weight: 700;
        }

        /* ── Bar end ─────────────────────────────────────────────── */
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
<header class="adm-bar" id="adm-bar">
    <a class="adm-brand" href="index.php" id="adm-home-btn">
        🔧 <?php echo is_admin() ? 'Admin' : 'Manager'; ?>
        <span class="adm-brand-badge"><?php echo is_admin() ? 'admin' : 'mgr'; ?></span>
    </a>

    <button class="adm-sb" id="adm-sl" aria-label="Scroll nav left">‹</button>

    <nav class="adm-nav" id="adm-nav" aria-label="Admin sections">
        <button class="adm-cat-btn" data-cat="jobs">
            📋 Jobs <span class="adm-caret">▾</span>
        </button>
        <?php if (is_admin()): ?>
        <button class="adm-cat-btn" data-cat="users">
            👥 Users <span class="adm-caret">▾</span>
        </button>
        <button class="adm-cat-btn" data-cat="finance">
            💳 Finance <span class="adm-caret">▾</span>
        </button>
        <button class="adm-cat-btn" data-cat="community">
            🌍 Community <span class="adm-caret">▾</span>
        </button>
        <button class="adm-cat-btn" data-cat="platform">
            ⚙️ Platform <span class="adm-caret">▾</span>
        </button>
        <?php endif; ?>
    </nav>

    <button class="adm-sb" id="adm-sr" aria-label="Scroll nav right">›</button>

    <div class="adm-bar-end">
        <a href="../settings.php" class="button button-secondary button-small">Settings</a>
        <a href="../logout.php"   class="button button-secondary button-small">Logout</a>
    </div>
</header>

<!-- ── Dropdown panels (outside nav to avoid overflow clipping) ── -->
<div id="adm-drops">
    <div class="adm-drop" data-cat="jobs">
        <a href="requests.php"     data-page="requests.php">📋 Requests</a>
        <a href="applications.php" data-page="applications.php">📝 Applications</a>
    </div>
    <?php if (is_admin()): ?>
    <div class="adm-drop" data-cat="users">
        <a href="users.php"     data-page="users.php">👥 Users</a>
        <a href="disputes.php"  data-page="disputes.php">⚖️ Disputes</a>
        <a href="referrals.php" data-page="referrals.php">🔗 Referrals</a>
    </div>
    <div class="adm-drop" data-cat="finance">
        <a href="payments.php"       data-page="payments.php">💳 Payments</a>
        <a href="monetization.php"   data-page="monetization.php">💰 Monetize</a>
    </div>
    <div class="adm-drop" data-cat="community">
        <a href="news.php"     data-page="news.php">📰 News</a>
        <a href="ads.php"      data-page="ads.php">📣 Ads</a>
        <a href="funerals.php" data-page="funerals.php">🕊️ Funerals</a>
        <a href="events.php"   data-page="events.php">📅 Events</a>
    </div>
    <div class="adm-drop" data-cat="platform">
        <a href="analytics.php"         data-page="analytics.php">📊 Analytics</a>
        <a href="business_messages.php" data-page="business_messages.php">💬 Messages</a>
        <a href="communication.php"     data-page="communication.php">📣 Broadcast</a>
        <a href="contact_settings.php"  data-page="contact_settings.php">📞 Contact</a>
        <a href="theme.php"             data-page="theme.php">🎨 Theme</a>
        <a href="audit_logs.php"        data-page="audit_logs.php">📜 Audit</a>
    </div>
    <?php endif; ?>
</div>

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
            ['requests.php',        '📋', 'Requests',     'Service request queue',      false],
            ['applications.php',    '📝', 'Applications', 'Job applications',           false],
            ['users.php',           '👥', 'Users',        'Manage accounts & roles',   true ],
            ['disputes.php',        '⚖️', 'Disputes',     'Resolve user conflicts',     true ],
            ['referrals.php',       '🔗', 'Referrals',    'Referral programme',         true ],
            ['payments.php',        '💳', 'Payments',     'Platform payment records',   true ],
            ['monetization.php',    '💰', 'Monetize',     'Pricing, plans & fees',      true ],
            ['news.php',            '📰', 'News',         'Articles & blog posts',      true ],
            ['ads.php',             '📣', 'Ads',          'Manage advertisements',      true ],
            ['funerals.php',        '🕊️', 'Funerals',     'Funeral announcements',      true ],
            ['events.php',          '📅', 'Events',       'Community events',           true ],
            ['analytics.php',       '📊', 'Analytics',    'Stats & growth trends',      true ],
            ['business_messages.php','💬','Messages',     'Business enquiries',         true ],
            ['communication.php',   '📣', 'Broadcast',    'Push notifications',         true ],
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

    var homeEl         = document.getElementById('adm-home');
    var ajaxEl         = document.getElementById('adm-ajax');
    var navEl          = document.getElementById('adm-nav');
    var slBtn          = document.getElementById('adm-sl');
    var srBtn          = document.getElementById('adm-sr');
    var pageStyleEl    = null;
    var currentLoadUrl = null;
    var currentCat     = null;

    /* ── Scroll buttons ───────────────────────────────────────── */

    function updateScrollBtns() {
        var sl = navEl.scrollLeft;
        var max = navEl.scrollWidth - navEl.clientWidth;
        slBtn.classList.toggle('vis', sl > 4);
        srBtn.classList.toggle('vis', max > 4 && sl < max - 4);
    }

    slBtn.addEventListener('click', function () {
        navEl.scrollBy({ left: -200, behavior: 'smooth' });
    });
    srBtn.addEventListener('click', function () {
        navEl.scrollBy({ left: 200, behavior: 'smooth' });
    });
    navEl.addEventListener('scroll', updateScrollBtns, { passive: true });
    window.addEventListener('resize', updateScrollBtns);
    updateScrollBtns();

    /* ── Dropdown open / close ────────────────────────────────── */

    function openDrop(cat) {
        closeDrop(true);
        var btn  = document.querySelector('.adm-cat-btn[data-cat="' + cat + '"]');
        var drop = document.querySelector('.adm-drop[data-cat="' + cat + '"]');
        if (!btn || !drop) return;
        var rect = btn.getBoundingClientRect();
        drop.style.left = rect.left + 'px';
        btn.classList.add('open');
        drop.classList.add('open');
        currentCat = cat;
    }

    function closeDrop(silent) {
        if (!currentCat) return;
        document.querySelectorAll('.adm-cat-btn.open').forEach(function (b) { b.classList.remove('open'); });
        document.querySelectorAll('.adm-drop.open').forEach(function (d)   { d.classList.remove('open'); });
        currentCat = null;
    }

    document.querySelectorAll('.adm-cat-btn').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var cat = btn.dataset.cat;
            if (currentCat === cat) { closeDrop(); } else { openDrop(cat); }
        });
    });

    document.addEventListener('click', function (e) {
        if (!e.target.closest('#adm-drops') && !e.target.closest('.adm-cat-btn')) {
            closeDrop();
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeDrop();
    });

    /* ── Active state ─────────────────────────────────────────── */

    function setActive(absUrl) {
        document.querySelectorAll('.adm-drop a[data-page]').forEach(function (a) {
            var match = absUrl && absUrl.indexOf('/' + a.dataset.page) !== -1;
            a.classList.toggle('active', !!match);
        });
        document.querySelectorAll('.adm-cat-btn').forEach(function (btn) {
            var drop = document.querySelector('.adm-drop[data-cat="' + btn.dataset.cat + '"]');
            btn.classList.toggle('active', !!(drop && drop.querySelector('a.active')));
        });
    }

    /* ── Helpers ──────────────────────────────────────────────── */

    function isInternalAdminPage(absUrl) {
        try {
            var u = new URL(absUrl);
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

    function toHashSegment(absUrl) {
        try {
            var u = new URL(absUrl);
            return u.pathname.split('/').pop() + u.search;
        } catch (e) { return ''; }
    }

    /* ── Show home dashboard ──────────────────────────────────── */

    function showHome(push) {
        homeEl.style.display = '';
        ajaxEl.style.display = 'none';
        ajaxEl.innerHTML = '';
        currentLoadUrl = null;
        setActive(null);
        document.title = 'Admin Dashboard — AkuapemHub';
        if (push !== false) history.pushState({ adm: 'home' }, '', 'index.php');
    }

    /* ── Load a page into the AJAX panel ─────────────────────── */

    function admLoad(href, push) {
        closeDrop();
        var base   = currentLoadUrl || window.location.href;
        var absUrl = new URL(href, base).href;
        currentLoadUrl = absUrl;

        homeEl.style.display = 'none';
        ajaxEl.style.display = 'block';
        ajaxEl.innerHTML = '<div class="adm-loading"><div class="adm-spinner"></div>Loading…</div>';
        setActive(absUrl);

        if (push !== false) {
            history.pushState({ adm: 'page', url: absUrl }, '', 'index.php#' + toHashSegment(absUrl));
        }

        fetch(absUrl, { credentials: 'same-origin' })
            .then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.text();
            })
            .then(function (html) {
                var parser = new DOMParser();
                var doc    = parser.parseFromString(html, 'text/html');

                var t = doc.querySelector('title');
                if (t) document.title = t.textContent;

                // Extract ALL <style> blocks from the fetched page (not just head, to
                // guard against browsers that move styles around during DOMParser parsing)
                var css = '';
                doc.querySelectorAll('style').forEach(function (s) { css += s.textContent; });
                ensurePageStyle().textContent = css;

                // Load any external <script src> from the fetched page's <head>
                // (e.g. chart.js on payments.php) — only if not already loaded
                doc.querySelectorAll('head script[src]').forEach(function (old) {
                    var src = old.getAttribute('src');
                    if (src && !document.querySelector('script[src="' + src + '"]')) {
                        var s = document.createElement('script');
                        s.src = src;
                        s.async = false;
                        document.head.appendChild(s);
                    }
                });

                var main = doc.querySelector('main');
                ajaxEl.innerHTML = main ? main.outerHTML : doc.body.innerHTML;

                // Scan the full body (not just main) so scripts placed after </main>
                // but before </body> are also executed — this fixes toggleDetail,
                // tab switching, and any other page-level JS in admin pages.
                doc.querySelectorAll('body script').forEach(function (old) {
                    var s = document.createElement('script');
                    if (old.src) { s.src = old.src; s.async = false; }
                    else         { s.textContent = old.textContent; }
                    document.head.appendChild(s);
                    if (!old.src) document.head.removeChild(s);
                });

                window.scrollTo({ top: 0 });
            })
            .catch(function () {
                ajaxEl.innerHTML =
                    '<div style="padding:30px 16px;">' +
                    '<div class="alert alert-error">Failed to load the page. ' +
                    '<a href="' + absUrl + '">Open directly →</a></div></div>';
            });
    }

    /* ── Wire dropdown links ──────────────────────────────────── */
    document.querySelectorAll('.adm-drop a[data-page]').forEach(function (a) {
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
        var resolved;
        try { resolved = new URL(a.getAttribute('href'), currentLoadUrl || window.location.href).href; }
        catch (ex) { return; }
        if (!isInternalAdminPage(resolved)) return;
        e.preventDefault();
        admLoad(resolved);
    });

    /* ── Delegate: intercept POST forms inside AJAX content ──── */
    // Resolves the action against currentLoadUrl so forms without an explicit
    // action attribute (which would otherwise POST to index.php) hit the
    // correct admin page (funerals.php, events.php, news.php, etc.)
    ajaxEl.addEventListener('submit', function (e) {
        var form = e.target.closest('form');
        if (!form || form.method.toLowerCase() === 'get') return;
        var actionRaw  = form.getAttribute('action') || '';
        var actionBase = currentLoadUrl || window.location.href;
        var resolved;
        try { resolved = new URL(actionRaw || actionBase, actionBase).href; }
        catch (ex) { return; }
        if (!isInternalAdminPage(resolved)) return;
        e.preventDefault();
        var fd  = new FormData(form);
        // Include the clicked submit button's name/value (e.g. "save_fee", bulk actions)
        var sub = e.submitter;
        if (sub && sub.name) fd.append(sub.name, sub.value);
        fetch(resolved, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { admLoad(r.url || resolved); })
            .catch(function ()  { admLoad(currentLoadUrl);   });
    });

    /* ── Browser back / forward ───────────────────────────────── */
    window.addEventListener('popstate', function (e) {
        if (!e.state || e.state.adm === 'home') {
            showHome(false);
        } else if (e.state.adm === 'page' && e.state.url) {
            currentLoadUrl = e.state.url;
            admLoad(e.state.url, false);
        }
    });

    /* ── On refresh: restore from hash ───────────────────────── */
    var initHash = window.location.hash.slice(1);
    if (initHash && /^[a-z0-9_-]+\.php/i.test(initHash)) {
        admLoad(initHash, false);
        history.replaceState({ adm: 'page', url: new URL(initHash, window.location.href).href }, '', window.location.href);
    } else {
        history.replaceState({ adm: 'home' }, '', window.location.href);
    }

    /* Expose for inline onclick (payments alert button) */
    window.admLoad = admLoad;
}());
</script>
<script src="../assets/js/rich-editor.js" defer></script>
</body>
</html>
