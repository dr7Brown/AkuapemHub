<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_login();
$user = current_user();

$requestId = intval($_GET['request_id'] ?? 0);
if ($requestId <= 0) {
    header('Location: dashboard.php');
    exit;
}

$stmt = $pdo->prepare('SELECT sr.*, c.name AS customer_name, w.name AS worker_name FROM service_requests sr JOIN users c ON sr.customer_id = c.id LEFT JOIN users w ON sr.assigned_worker_id = w.id WHERE sr.id = ?');
$stmt->execute([$requestId]);
$request = $stmt->fetch();

if (!$request) {
    header('Location: dashboard.php');
    exit;
}

$canMessage = is_admin() || $user['id'] === $request['customer_id'] || $user['id'] === $request['assigned_worker_id'];
if (!$canMessage) {
    header('Location: dashboard.php');
    exit;
}

$messages = get_message_thread($requestId, $user['id'], 100);
mark_messages_read($requestId, $user['id']);

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['content'])) {
    $content = trim($_POST['content']);
    $recipientId = intval($_POST['recipient_id'] ?? 0);
    
    if ($content === '' || $recipientId <= 0) {
        $error = 'Message cannot be empty.';
    } else {
        send_message($requestId, $user['id'], $recipientId, $content);
        header('Location: messages.php?request_id=' . $requestId);
        exit;
    }
}

$otherUserId = ($user['id'] === $request['customer_id']) ? $request['assigned_worker_id'] : $request['customer_id'];
$otherUserName = ($user['id'] === $request['customer_id']) ? $request['worker_name'] : $request['customer_name'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Messages — AkuapemHub</title>
    <link rel="stylesheet" href="assets/css/style.css" />
    <style>
        .message-thread { max-height: 400px; overflow-y: auto; border: 1px solid #d1d5db; border-radius: 16px; padding: 12px; margin-bottom: 16px; background: #fafafa; }
        .message-item { margin-bottom: 12px; padding: 12px; border-radius: 12px; }
        .message-item.sent { background: #d1fae5; text-align: right; }
        .message-item.received { background: #ffffff; border: 1px solid #e5e7eb; }
        .message-time { font-size: 0.8rem; color: #6b7280; margin-top: 4px; }
    </style>
</head>
<body>
    <header class="topbar">
        <a href="dashboard.php" class="button button-secondary button-small">Back</a>
        <h1>Messages — <?php echo sanitize($request['title']); ?></h1>
        <a href="logout.php" class="button button-secondary button-small">Logout</a>
    </header>
    <main class="page-shell small-shell">
        <section class="card">
            <p><strong>Request:</strong> <?php echo sanitize($request['title']); ?></p>
            <?php if ($otherUserName): ?>
                <p><strong>Chatting with:</strong> <?php echo sanitize($otherUserName); ?></p>
            <?php else: ?>
                <p class="meta">Waiting for worker assignment...</p>
            <?php endif; ?>
            
            <div class="message-thread">
                <?php if (empty($messages)): ?>
                    <div class="empty-state" style="padding: 24px 0;">No messages yet. Start the conversation!</div>
                <?php else: ?>
                    <?php foreach ($messages as $msg): ?>
                        <div class="message-item <?php echo ($msg['sender_id'] === $user['id']) ? 'sent' : 'received'; ?>">
                            <p style="margin: 0;"><?php echo sanitize($msg['content']); ?></p>
                            <div class="message-time"><?php echo sanitize(date('M j H:i', strtotime($msg['created_at']))); ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <?php if ($otherUserId): ?>
                <?php if ($error): ?>
                    <div class="alert alert-error"><?php echo sanitize($error); ?></div>
                <?php endif; ?>
                <form method="post" action="messages.php?request_id=<?php echo $requestId; ?>">
                    <input type="hidden" name="recipient_id" value="<?php echo $otherUserId; ?>" />
                    <textarea name="content" rows="3" placeholder="Type your message..." required></textarea>
                    <button type="submit" class="button button-primary" style="width: 100%; margin-top: 12px;">Send</button>
                </form>
            <?php else: ?>
                <p class="meta">You cannot send messages until a worker is assigned.</p>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
