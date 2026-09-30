<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';

require_login();
if (!is_admin()) {
    header('Location: ../jobs.php');
    exit;
}
$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    if ($action === 'delete' && !empty($_POST['id'])) {
        $pdo->prepare('DELETE FROM business_messages WHERE id=?')->execute([(int)$_POST['id']]);
        log_audit_action($user['id'], 'business_message_delete', 'Deleted business message #' . (int)$_POST['id']);
    } elseif ($action === 'clear_all') {
        $channel = in_array($_POST['channel'] ?? '', ['sms', 'whatsapp'], true) ? $_POST['channel'] : null;
        if ($channel) {
            $pdo->prepare('DELETE FROM business_messages WHERE channel=?')->execute([$channel]);
            log_audit_action($user['id'], 'business_message_clear', "Cleared all {$channel} messages from the log");
        } else {
            $pdo->exec('DELETE FROM business_messages');
            log_audit_action($user['id'], 'business_message_clear', 'Cleared the entire business message log');
        }
    }
    $qs = $_GET ? '?' . http_build_query($_GET) : '';
    header('Location: business_messages.php' . $qs);
    exit;
}

$channelFilter = in_array($_GET['channel'] ?? '', ['sms', 'whatsapp'], true) ? $_GET['channel'] : '';
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;

if ($channelFilter) {
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM business_messages WHERE channel=?');
    $countStmt->execute([$channelFilter]);
    $total = (int)$countStmt->fetchColumn();
    $stmt = $pdo->prepare('SELECT bm.*, u.name AS user_name FROM business_messages bm LEFT JOIN users u ON bm.user_id = u.id WHERE bm.channel=? ORDER BY bm.created_at DESC LIMIT ? OFFSET ?');
    $stmt->bindValue(1, $channelFilter);
    $stmt->bindValue(2, $perPage, PDO::PARAM_INT);
    $stmt->bindValue(3, ($page - 1) * $perPage, PDO::PARAM_INT);
    $stmt->execute();
    $messages = $stmt->fetchAll();
} else {
    $total    = get_business_message_count();
    $messages = get_business_message_log($perPage, ($page - 1) * $perPage);
}
$totalPages = max(1, (int)ceil($total / $perPage));
$providerConfigured = WHATSAPP_PROVIDER_URL !== '' || SMS_PROVIDER_URL !== '' || get_platform_setting('arkesel_api_key', '') !== '' || (defined('ARKESEL_API_KEY') && trim(ARKESEL_API_KEY) !== '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Business Messages — Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css" />
    <style>
        .bm-toolbar { display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-bottom:14px; }
        .bm-toolbar select { padding:7px 10px; border-radius:8px; border:1px solid var(--border); font-size:.85rem; }
        table.bm-table { width:100%; border-collapse:collapse; font-size:.85rem; }
        table.bm-table th { background:var(--surface-muted,#f9fafb); padding:9px 12px; text-align:left; font-size:.72rem; font-weight:800; text-transform:uppercase; letter-spacing:.04em; color:var(--text-muted); border-bottom:1px solid var(--border); }
        table.bm-table td { padding:9px 12px; border-bottom:1px solid var(--border,#e5e7eb); vertical-align:top; }
        table.bm-table tr:last-child td { border-bottom:none; }
        .bm-msg-cell { max-width:320px; white-space:pre-wrap; word-break:break-word; }
    </style>
</head>
<body>
    <header class="topbar">
        <a href="index.php" class="button button-secondary button-small">← Dashboard</a>
        <h1>SMS / WhatsApp Messages</h1>
        <?php if ($messages): ?>
        <form method="post" onsubmit="return confirm('Delete ALL<?php echo $channelFilter ? ' ' . strtoupper($channelFilter) : ''; ?> messages from the log? This cannot be undone.');" style="margin:0;">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="clear_all">
            <?php if ($channelFilter): ?><input type="hidden" name="channel" value="<?php echo sanitize($channelFilter); ?>"><?php endif; ?>
            <button type="submit" class="button button-small" style="background:#fee2e2;color:#991b1b;border-color:#fca5a5;">Clear<?php echo $channelFilter ? ' ' . strtoupper($channelFilter) : ' All'; ?></button>
        </form>
        <?php endif; ?>
    </header>
    <main class="page-shell">
        <section class="panel">
            <?php if ($providerConfigured): ?>
                <div class="alert alert-success">A messaging provider is configured — outgoing messages are sent live.</div>
            <?php else: ?>
                <div class="alert alert-error">No SMS/WhatsApp provider is configured yet — see <a href="sms_settings.php">SMS Settings</a> or WHATSAPP_PROVIDER_URL/SMS_PROVIDER_URL in config.php. Messages are logged below but not actually delivered until a provider is set up.</div>
            <?php endif; ?>

            <div class="bm-toolbar">
                <form method="get">
                    <select name="channel" onchange="this.form.submit()">
                        <option value="">All channels</option>
                        <option value="sms" <?php echo $channelFilter==='sms' ? 'selected':''; ?>>SMS only</option>
                        <option value="whatsapp" <?php echo $channelFilter==='whatsapp' ? 'selected':''; ?>>WhatsApp only</option>
                    </select>
                </form>
                <span class="meta"><?php echo number_format($total); ?> message<?php echo $total===1?'':'s'; ?> total</span>
            </div>

            <?php if (empty($messages)): ?>
                <div class="empty-state">No business messages have been triggered yet.</div>
            <?php else: ?>
                <div style="overflow-x:auto;">
                <table class="bm-table">
                    <thead>
                        <tr>
                            <th>When</th>
                            <th>To</th>
                            <th>Channel</th>
                            <th>Message</th>
                            <th>Status</th>
                            <th>Provider response</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($messages as $message): ?>
                            <tr>
                                <td style="white-space:nowrap;"><?php echo sanitize(date('M j, Y H:i', strtotime($message['created_at']))); ?></td>
                                <td><?php echo sanitize($message['user_name'] ?: 'Unknown'); ?><br /><span class="meta"><?php echo sanitize($message['phone']); ?></span></td>
                                <td><?php echo sanitize(strtoupper($message['channel'])); ?></td>
                                <td class="bm-msg-cell"><?php echo sanitize($message['message']); ?></td>
                                <td><span class="status status-<?php echo $message['status'] === 'sent' ? 'success' : ($message['status'] === 'failed' ? 'error' : 'warning'); ?>"><?php echo strtoupper($message['status']); ?></span></td>
                                <td><?php echo sanitize($message['response_excerpt'] ?: '—'); ?></td>
                                <td>
                                    <form method="post" onsubmit="return confirm('Delete this message?');" style="margin:0;">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo (int)$message['id']; ?>">
                                        <button type="submit" class="button button-small" style="background:#fee2e2;color:#991b1b;border-color:#fca5a5;">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>

                <?php if ($totalPages > 1): ?>
                <div style="display:flex;gap:8px;justify-content:center;margin-top:20px;flex-wrap:wrap;">
                    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                    <a href="business_messages.php?page=<?php echo $p; ?><?php echo $channelFilter ? '&channel='.$channelFilter : ''; ?>"
                       class="button button-small <?php echo $p === $page ? 'button-primary' : 'button-secondary'; ?>"><?php echo $p; ?></a>
                    <?php endfor; ?>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
