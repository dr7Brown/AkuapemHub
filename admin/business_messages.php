<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';

require_login();
if (!is_admin()) {
    header('Location: ../jobs.php');
    exit;
}

$messages = get_business_message_log(100);
$providerConfigured = WHATSAPP_PROVIDER_URL !== '' || SMS_PROVIDER_URL !== '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Business Messages — AkuapemHub</title>
    <link rel="stylesheet" href="../assets/css/style.css" />
</head>
<body>
    <header class="topbar">
        <a href="index.php" class="button button-secondary button-small">Back</a>
        <h1>SMS / WhatsApp messages</h1>
        <a href="../logout.php" class="button button-secondary button-small">Logout</a>
    </header>
    <main class="page-shell">
        <section class="panel">
            <?php if ($providerConfigured): ?>
                <div class="alert alert-success">A messaging provider is configured — outgoing messages are sent live.</div>
            <?php else: ?>
                <div class="alert alert-error">No SMS/WhatsApp provider is configured yet (see WHATSAPP_PROVIDER_URL / SMS_PROVIDER_URL in config.php). Messages are logged below but not actually delivered until a provider is set up.</div>
            <?php endif; ?>

            <?php if (empty($messages)): ?>
                <div class="empty-state">No business messages have been triggered yet.</div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>When</th>
                            <th>To</th>
                            <th>Channel</th>
                            <th>Message</th>
                            <th>Status</th>
                            <th>Provider response</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($messages as $message): ?>
                            <tr>
                                <td><?php echo sanitize(date('M j, Y H:i', strtotime($message['created_at']))); ?></td>
                                <td><?php echo sanitize($message['user_name'] ?: 'Unknown'); ?><br /><span class="meta"><?php echo sanitize($message['phone']); ?></span></td>
                                <td><?php echo sanitize(strtoupper($message['channel'])); ?></td>
                                <td><?php echo sanitize($message['message']); ?></td>
                                <td><span class="status status-<?php echo $message['status'] === 'sent' ? 'success' : ($message['status'] === 'failed' ? 'error' : 'warning'); ?>"><?php echo strtoupper($message['status']); ?></span></td>
                                <td><?php echo sanitize($message['response_excerpt'] ?: '—'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
