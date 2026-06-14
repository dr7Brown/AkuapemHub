<?php
/**
 * Shared bottom tab bar. Include after $user = current_user(); is set.
 * Optional: set $activeNav to one of 'home','jobs','workers','messages','settings' to force the active tab.
 * Also injects admin-defined theme colour overrides as a <style> block.
 */
if (!isset($user) || !$user) {
    return;
}

// Inject custom theme colours saved by admin
try {
    global $pdo;
    $themeRows = $pdo->query("SELECT setting_key, setting_value FROM platform_settings WHERE setting_key LIKE 'theme_%'")->fetchAll();
    if ($themeRows) {
        $propMap = [
            'theme_primary'       => '--primary',
            'theme_primary_dark'  => '--primary-dark',
            'theme_primary_soft'  => '--primary-soft',
            'theme_secondary'     => '--secondary',
            'theme_secondary_soft'=> '--secondary-soft',
            'theme_bg'            => '--bg',
            'theme_surface'       => '--surface',
            'theme_surface_muted' => '--surface-muted',
            'theme_border'        => '--border',
            'theme_text'          => '--text',
        ];
        $lines = [];
        foreach ($themeRows as $row) {
            $prop = $propMap[$row['setting_key']] ?? null;
            $val  = $row['setting_value'];
            if ($prop && preg_match('/^#[0-9a-f]{3,6}$/i', $val)) {
                $lines[] = "  {$prop}: {$val};";
            }
        }
        if ($lines) {
            echo '<style>:root{' . implode('', $lines) . '}</style>';
        }
    }
} catch (Exception $e) {
    // silently skip if DB not available
}

$jobsHref = $user['role'] === 'worker' ? 'worker_history.php' : 'dashboard.php';

if (function_exists('get_total_unread_chat_count')) {
    $navUnreadMessages = get_total_unread_chat_count($user['id']);
} else {
    try {
        global $pdo;
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

$navItems = [
    'home'     => ['href' => 'dashboard.php',  'icon' => '🏠', 'label' => 'Home'],
    'jobs'     => ['href' => $jobsHref,         'icon' => '🧰', 'label' => 'Jobs'],
    'news'     => ['href' => 'news.php',        'icon' => '📰', 'label' => 'News'],
    'messages' => ['href' => 'chat.php',        'icon' => '💬', 'label' => 'Messages', 'count' => $navUnreadMessages],
    'settings' => ['href' => 'settings.php',   'icon' => '⚙️', 'label' => 'Settings'],
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
<script>
var CSRF = <?php echo json_encode(csrf_token()); ?>;
// Bell icon: optimistically clear badge, mark notifications read in background
(function () {
    var bell = document.querySelector('a[href="notifications.php"]');
    if (!bell) return;
    bell.addEventListener('click', function () {
        var badge = bell.querySelector('.nav-badge');
        if (!badge) return;
        badge.classList.remove('nav-badge');
        badge.removeAttribute('data-count');
        fetch('ajax.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=mark_notifications_read&csrf_token=' + encodeURIComponent(CSRF)
        }).catch(function () {});
    });
})();
</script>
