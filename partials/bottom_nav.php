<?php
/**
 * Shared bottom tab bar. Include after $user = current_user(); is set.
 * Optional: set $activeNav to one of 'home','jobs','workers','messages','settings' to force the active tab.
 * Also injects admin-defined theme colour overrides as a <style> block.
 */
if (!isset($user) || !$user) {
    return;
}

// Theme colours are now injected globally via db.php ob_start() — no need to echo here.

// When the admin does NOT require a verified email to log in, unverified
// users can reach every page — nudge them to verify instead of silently
// letting it slide. If login itself requires verification, this state can't
// occur (login.php already blocks it), so behaviour there is unchanged.
$needsEmailVerifyNudge = !is_email_verified() && !requires_verified_email('login');

global $pdo;

// Unread chat count
if (function_exists('get_total_unread_chat_count')) {
    $navUnreadMessages = get_total_unread_chat_count($user['id']);
} else {
    try {
        $navChatStmt = $pdo->prepare("
            SELECT COUNT(*) FROM chat_messages cm
            JOIN conversation_participants cp ON cm.conversation_id = cp.conversation_id AND cp.user_id = ?
            WHERE cm.sender_id != ? AND cm.is_read = 0 AND cm.deleted_by_receiver = 0
        ");
        $navChatStmt->execute([$user['id'], $user['id']]);
        $navUnreadMessages = (int)$navChatStmt->fetchColumn();
    } catch (Exception $e) {
        $navUnreadMessages = 0;
    }
}

// Unread notification count
try {
    $navNotifStmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $navNotifStmt->execute([$user['id']]);
    $navUnreadNotifs = (int)$navNotifStmt->fetchColumn();
} catch (Exception $e) {
    $navUnreadNotifs = 0;
}

// The bell's badge combines unread notifications + unread chat messages, so
// it reads as one "you have unread things" count; the Messages tab below
// still shows its own dedicated chat-only badge.
$navBellCount = $navUnreadNotifs + $navUnreadMessages;

$navItems = [];
if (module_enabled('jobs')) {
    $navItems['home']   = ['href' => 'jobs.php',            'icon' => '💼', 'label' => 'Jobs'];
    $navItems['myapps'] = ['href' => 'my_applications.php', 'icon' => '📋', 'label' => 'My Apps'];
}
$navItems['community'] = ['href' => 'index.php',    'icon' => '🌍', 'label' => 'Community'];
$navItems['messages']  = ['href' => 'chat.php',     'icon' => '💬', 'label' => 'Messages', 'count' => $navUnreadMessages];
$navItems['settings']  = ['href' => 'settings.php', 'icon' => '⚙️', 'label' => 'Settings'];

if (!isset($activeNav)) {
    $currentScript = basename($_SERVER['SCRIPT_NAME']);
    $activeNav = module_enabled('jobs') ? 'home' : 'community';
    foreach ($navItems as $key => $item) {
        if (basename(parse_url($item['href'], PHP_URL_PATH)) === $currentScript) {
            $activeNav = $key;
        }
    }
}
?>
<?php if ($needsEmailVerifyNudge): ?>
<div id="email-verify-modal" class="evm-overlay" role="dialog" aria-modal="true" aria-label="Verify your email">
    <div class="evm-card">
        <div class="evm-icon">📧</div>
        <h2>Verify your email</h2>
        <p>We sent a verification link to <strong><?php echo sanitize($user['email']); ?></strong>. Open your inbox and click the link to unlock full access to AkuapemConnect.</p>
        <form method="post" action="resend_verification.php" style="margin:0;">
            <?php echo csrf_field(); ?>
            <button type="submit" class="button button-primary" style="width:100%;">Resend verification email</button>
        </form>
        <button type="button" id="evm-dismiss" class="button button-secondary" style="width:100%;margin-top:8px;">Continue without verifying</button>
    </div>
</div>
<style>
.evm-overlay { display:none; position:fixed; inset:0; z-index:1200; background:rgba(0,0,0,.45); align-items:center; justify-content:center; padding:16px; }
.evm-overlay.open { display:flex; }
.evm-card { background:var(--surface,#fff); border-radius:16px; max-width:380px; width:100%; padding:24px 22px; text-align:center; box-shadow:0 20px 50px rgba(0,0,0,.25); }
.evm-icon { font-size:2.2rem; margin-bottom:6px; }
.evm-card h2 { margin:0 0 8px; font-size:1.15rem; }
.evm-card p { margin:0 0 16px; font-size:.88rem; color:var(--text-muted,#6b7280); line-height:1.5; }
</style>
<script>
(function () {
    var KEY     = 'evm_dismissed_<?php echo (int)$user['id']; ?>';
    var overlay = document.getElementById('email-verify-modal');
    if (!overlay) return;
    if (!sessionStorage.getItem(KEY)) {
        overlay.classList.add('open');
    }
    document.getElementById('evm-dismiss').addEventListener('click', function () {
        sessionStorage.setItem(KEY, '1');
        overlay.classList.remove('open');
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && overlay.classList.contains('open')) {
            sessionStorage.setItem(KEY, '1');
            overlay.classList.remove('open');
        }
    });
})();
</script>
<?php endif; ?>
<?php require __DIR__ . '/social_tiles.php'; ?>
<nav class="bottom-nav">
    <?php foreach ($navItems as $key => $item): ?>
        <a href="<?php echo sanitize($item['href']); ?>" class="bottom-nav-item <?php echo $activeNav === $key ? 'active' : ''; ?>">
            <span class="nav-icon<?php echo !empty($item['count']) ? ' nav-badge' : ''; ?>" <?php echo !empty($item['count']) ? 'data-count="' . (int)$item['count'] . '"' : ''; ?>><?php echo $item['icon']; ?></span>
            <span><?php echo sanitize($item['label']); ?></span>
        </a>
    <?php endforeach; ?>
</nav>
<!-- Fixed notification bell — top-right corner, every page. -->
<a href="notifications.php" id="notif-bell"
   aria-label="Notifications" aria-haspopup="true" aria-expanded="false"
   style="position:fixed;top:10px;right:68px;z-index:1100;
          width:40px;height:40px;border-radius:50%;
          background:var(--surface,#fff);
          box-shadow:0 2px 10px rgba(0,0,0,.15);
          border:1px solid var(--border,#e5e7eb);
          display:flex;align-items:center;justify-content:center;
          font-size:1.1rem;text-decoration:none;
          transition:box-shadow .15s;">
    <span class="nav-icon<?php echo $navBellCount ? ' nav-badge' : ''; ?>"
          <?php echo $navBellCount ? 'data-count="' . min($navBellCount, 99) . '"' : ''; ?>>🔔</span>
</a>

<!-- Fixed profile avatar with dropdown — top-right corner, every page. -->
<div id="avatar-wrap" style="position:fixed;top:10px;right:14px;z-index:1100;">
    <button id="avatar-btn" aria-expanded="false" aria-haspopup="true"
            style="background:none;border:none;padding:0;cursor:pointer;display:flex;align-items:center;border-radius:50%;
                   box-shadow:0 2px 10px rgba(0,0,0,.15);">
        <?php if (!empty($user['profile_photo'])): ?>
            <img src="<?php echo sanitize($user['profile_photo']); ?>" alt="Profile" class="avatar" style="pointer-events:none;" />
        <?php else: ?>
            <span class="avatar" style="pointer-events:none;"><?php echo sanitize(strtoupper(substr(display_name($user), 0, 1))); ?></span>
        <?php endif; ?>
    </button>
    <div id="avatar-menu" role="menu"
         style="display:none;position:absolute;right:0;top:calc(100% + 8px);min-width:180px;background:#fff;border:1px solid #e2e8f0;border-radius:10px;box-shadow:0 6px 24px rgba(0,0,0,.12);z-index:999;overflow:hidden;">
        <div style="padding:12px 14px 10px;border-bottom:1px solid #f1f5f9;">
            <strong style="display:block;font-size:.88rem;"><?php echo sanitize(display_name($user)); ?></strong>
            <span style="font-size:.75rem;color:#6b7280;"><?php echo sanitize($user['email']); ?></span>
        </div>
        <?php if ($user['role'] === 'worker'): ?>
        <a href="worker_profile.php" role="menuitem" style="display:flex;align-items:center;gap:10px;padding:10px 14px;color:var(--text);text-decoration:none;font-size:.88rem;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
            <span>👤</span> My Worker Profile
        </a>
        <?php else: ?>
        <a href="jobs.php" role="menuitem" style="display:flex;align-items:center;gap:10px;padding:10px 14px;color:var(--text);text-decoration:none;font-size:.88rem;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
            <span>🏠</span> Dashboard
        </a>
        <?php endif; ?>
        <a href="settings.php" role="menuitem" style="display:flex;align-items:center;gap:10px;padding:10px 14px;color:var(--text);text-decoration:none;font-size:.88rem;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
            <span>⚙️</span> Settings
        </a>
        <div style="border-top:1px solid #f1f5f9;"></div>
        <a href="marketplace.php" role="menuitem" style="display:flex;align-items:center;gap:10px;padding:10px 14px;color:var(--text);text-decoration:none;font-size:.88rem;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
            <span>🛍️</span> Marketplace
        </a>
        <?php if (module_enabled('markets')): ?>
        <a href="markets.php" role="menuitem" style="display:flex;align-items:center;gap:10px;padding:10px 14px;color:var(--text);text-decoration:none;font-size:.88rem;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
            <span>🏬</span> Periodic Markets
        </a>
        <?php endif; ?>
        <a href="orders.php" role="menuitem" style="display:flex;align-items:center;gap:10px;padding:10px 14px;color:var(--text);text-decoration:none;font-size:.88rem;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
            <span>📋</span> My Orders
        </a>
        <a href="seller_dashboard.php" role="menuitem" style="display:flex;align-items:center;gap:10px;padding:10px 14px;color:var(--text);text-decoration:none;font-size:.88rem;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
            <span>🏪</span> My Shop
        </a>
        <a href="my_saved.php" role="menuitem" style="display:flex;align-items:center;gap:10px;padding:10px 14px;color:var(--text);text-decoration:none;font-size:.88rem;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
            <span>❤️</span> Saved Products
        </a>
        <div style="border-top:1px solid #f1f5f9;"></div>
        <a href="logout.php" role="menuitem" style="display:flex;align-items:center;gap:10px;padding:10px 14px;color:#c0392b;text-decoration:none;font-size:.88rem;" onmouseover="this.style.background='#fff5f5'" onmouseout="this.style.background=''">
            <span>🚪</span> Logout
        </a>
    </div>
</div>
<script>
(function() {
    var btn  = document.getElementById('avatar-btn');
    var menu = document.getElementById('avatar-menu');
    if (!btn || !menu) return;
    btn.addEventListener('click', function(e) {
        e.stopPropagation();
        var open = menu.style.display === 'block';
        menu.style.display = open ? 'none' : 'block';
        btn.setAttribute('aria-expanded', String(!open));
    });
    document.addEventListener('click', function() {
        menu.style.display = 'none';
        btn.setAttribute('aria-expanded', 'false');
    });
    menu.addEventListener('click', function(e) { e.stopPropagation(); });
})();
</script>

<!-- Notification popup — opened from the bell instead of navigating to notifications.php -->
<div id="notif-popup" role="dialog" aria-label="Notifications">
    <div class="np-header">
        <strong>Notifications</strong>
        <a href="notifications.php" class="np-view-all">View all →</a>
    </div>
    <div id="notif-popup-body" class="np-body">
        <div class="np-loading">Loading…</div>
    </div>
</div>

<style>
#notif-bell:hover { box-shadow:0 4px 16px rgba(0,0,0,.2); }
/* Unread-count bubble on the fixed bell. Not inside .bottom-nav-item, so it
   needs its own full rule (not just the size tweak this used to be) —
   positioned to the TOP-LEFT of the bell (not top-right) since the profile
   avatar sits immediately to the bell's right and a right-side bubble would
   render underneath/behind it. */
#notif-bell .nav-badge { position:relative; }
#notif-bell .nav-badge::after {
    content:attr(data-count);
    position:absolute;
    top:-4px; left:-8px;
    min-width:16px; height:16px;
    padding:0 4px;
    border-radius:999px;
    background:var(--secondary);
    color:#fff;
    font-size:.58rem;
    font-weight:700;
    display:flex;
    align-items:center;
    justify-content:center;
}

#notif-popup {
    display:none;
    position:fixed; top:56px; right:14px; z-index:1101;
    width:min(360px, calc(100vw - 28px));
    max-height:min(480px, calc(100vh - 76px));
    background:var(--surface,#fff);
    border:1px solid var(--border,#e5e7eb);
    border-radius:14px;
    box-shadow:0 12px 32px rgba(0,0,0,.18);
    overflow:hidden;
    flex-direction:column;
}
#notif-popup.open { display:flex; }
.np-header { display:flex; align-items:center; justify-content:space-between; padding:12px 14px; border-bottom:1px solid var(--border,#e5e7eb); font-size:.88rem; }
.np-view-all { font-size:.78rem; color:var(--primary,#0f766e); font-weight:700; text-decoration:none; }
.np-body { overflow-y:auto; }
.np-loading, .np-empty { padding:28px 16px; text-align:center; font-size:.84rem; color:var(--text-muted,#6b7280); }
.np-item { display:flex; gap:9px; padding:10px 14px; border-bottom:1px solid var(--border,#f1f5f9); text-decoration:none; color:inherit; cursor:pointer; }
.np-item:last-child { border-bottom:none; }
.np-item:hover { background:rgba(0,0,0,.03); }
.np-item.unread { background:var(--primary-soft,#f0fdf4); }
.np-item-icon { font-size:1.1rem; flex-shrink:0; margin-top:1px; }
.np-item-body { flex:1; min-width:0; }
.np-item-title { font-size:.83rem; margin:0 0 2px; font-weight:600; }
.np-item.unread .np-item-title { font-weight:800; }
.np-item-preview { font-size:.76rem; color:var(--text-muted,#6b7280); margin:0; overflow:hidden; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; }
.np-item-time { font-size:.68rem; color:var(--text-muted,#9ca3af); white-space:nowrap; flex-shrink:0; margin-top:1px; }
@media(max-width:480px) { #notif-popup { top:56px; right:8px; left:8px; width:auto; } }
</style>

<script src="assets/js/rich-editor.js" defer></script>
<script>
var CSRF = <?php echo json_encode(csrf_token()); ?>;
var NOTIF_TYPE_ICON = { info: 'ℹ️', success: '✅', warning: '⚠️', error: '❌' };

(function () {
    var bell   = document.getElementById('notif-bell');
    var popup  = document.getElementById('notif-popup');
    var body   = document.getElementById('notif-popup-body');
    if (!bell || !popup) return;
    var loaded = false;

    function closePopup() {
        popup.classList.remove('open');
        bell.setAttribute('aria-expanded', 'false');
    }

    function openPopup() {
        popup.classList.add('open');
        bell.setAttribute('aria-expanded', 'true');

        // Clear the bell badge optimistically once opened
        var badge = bell.querySelector('.nav-badge');
        if (badge) { badge.classList.remove('nav-badge'); badge.removeAttribute('data-count'); }

        if (loaded) return;
        loaded = true;
        fetch('ajax.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=get_recent_notifications&csrf_token=' + encodeURIComponent(CSRF)
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data.ok || !data.notifications.length) {
                body.innerHTML = '<div class="np-empty">🔔 No notifications yet</div>';
                return;
            }
            body.innerHTML = data.notifications.map(function (n) {
                var icon = NOTIF_TYPE_ICON[n.type] || 'ℹ️';
                return '<a class="np-item' + (n.is_read ? '' : ' unread') + '" data-id="' + n.id + '" data-link="' + (n.link || '') + '" href="' + (n.link || 'notifications.php') + '">' +
                    '<span class="np-item-icon">' + icon + '</span>' +
                    '<span class="np-item-body">' +
                        '<p class="np-item-title">' + escapeHtml(n.title) + '</p>' +
                        '<p class="np-item-preview">' + escapeHtml(n.preview) + '</p>' +
                    '</span>' +
                    '<span class="np-item-time">' + escapeHtml(n.time_ago) + '</span>' +
                '</a>';
            }).join('');

            body.querySelectorAll('.np-item.unread').forEach(function (el) {
                el.addEventListener('click', function () {
                    fetch('ajax.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'action=mark_notification_read&notification_id=' + encodeURIComponent(el.dataset.id) + '&csrf_token=' + encodeURIComponent(CSRF)
                    }).catch(function () {});
                }, { once: true });
            });
        })
        .catch(function () {
            body.innerHTML = '<div class="np-empty">Couldn\'t load notifications. <a href="notifications.php">Open notifications page →</a></div>';
        });
    }

    function escapeHtml(s) {
        var d = document.createElement('div');
        d.textContent = s || '';
        return d.innerHTML;
    }

    bell.addEventListener('click', function (e) {
        e.preventDefault();
        if (popup.classList.contains('open')) closePopup();
        else openPopup();
    });

    document.addEventListener('click', function (e) {
        if (popup.classList.contains('open') && !popup.contains(e.target) && e.target !== bell && !bell.contains(e.target)) {
            closePopup();
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closePopup();
    });
})();
</script>
