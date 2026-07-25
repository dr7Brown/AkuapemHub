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

$navItems = [
    'home'      => ['href' => 'jobs.php',            'icon' => '💼', 'label' => 'Jobs'],
    'myapps'    => ['href' => 'my_applications.php', 'icon' => '📋', 'label' => 'My Apps'],
    'community' => ['href' => 'index.php',           'icon' => '🌍', 'label' => 'Community'],
    'messages'  => ['href' => 'chat.php',            'icon' => '💬', 'label' => 'Messages', 'count' => $navUnreadMessages],
    'settings'  => ['href' => 'settings.php',        'icon' => '⚙️', 'label' => 'Settings'],
];

if (!isset($activeNav)) {
    $currentScript = basename($_SERVER['SCRIPT_NAME']);
    $activeNav = 'home';
    foreach ($navItems as $key => $item) {
        if (basename(parse_url($item['href'], PHP_URL_PATH)) === $currentScript) {
            $activeNav = $key;
        }
    }
}
?>
<nav class="bottom-nav">
    <?php foreach ($navItems as $key => $item): ?>
        <a href="<?php echo sanitize($item['href']); ?>" class="bottom-nav-item <?php echo $activeNav === $key ? 'active' : ''; ?>">
            <span class="nav-icon<?php echo !empty($item['count']) ? ' nav-badge' : ''; ?>" <?php echo !empty($item['count']) ? 'data-count="' . (int)$item['count'] . '"' : ''; ?>><?php echo $item['icon']; ?></span>
            <span><?php echo sanitize($item['label']); ?></span>
        </a>
    <?php endforeach; ?>
</nav>
<!-- Fixed notification bell — top-right corner, every page -->
<a href="notifications.php" id="notif-bell"
   aria-label="Notifications" aria-haspopup="true" aria-expanded="false"
   style="position:fixed;top:10px;right:14px;z-index:1100;
          width:40px;height:40px;border-radius:50%;
          background:var(--surface,#fff);
          box-shadow:0 2px 10px rgba(0,0,0,.15);
          border:1px solid var(--border,#e5e7eb);
          display:flex;align-items:center;justify-content:center;
          font-size:1.1rem;text-decoration:none;
          transition:box-shadow .15s;">
    <span class="nav-icon<?php echo $navUnreadNotifs ? ' nav-badge' : ''; ?>"
          <?php echo $navUnreadNotifs ? 'data-count="' . min($navUnreadNotifs, 99) . '"' : ''; ?>>🔔</span>
</a>

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
/* Shrink badge text inside fixed bell */
#notif-bell .nav-badge::after { font-size:.58rem; min-width:16px; height:16px; }

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
