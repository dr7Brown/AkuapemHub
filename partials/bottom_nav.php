<?php
/**
 * Shared bottom tab bar. Include after $user = current_user(); is set.
 * Optional: set $activeNav to one of 'home','jobs','workers','messages','settings' to force the active tab.
 */
if (!isset($user) || !$user) {
    return;
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
    'home' => ['href' => 'dashboard.php', 'icon' => '🏠', 'label' => 'Home'],
    'jobs' => ['href' => $jobsHref, 'icon' => '🧰', 'label' => 'Jobs'],
    'workers' => ['href' => 'find_workers.php', 'icon' => '🔍', 'label' => 'Workers'],
    'messages' => ['href' => 'chat.php', 'icon' => '💬', 'label' => 'Messages', 'count' => $navUnreadMessages],
    'settings' => ['href' => 'settings.php', 'icon' => '⚙️', 'label' => 'Settings'],
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
