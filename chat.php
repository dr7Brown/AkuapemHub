<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/chat_functions.php';

require_login();
$user = current_user();

if (chat_is_disabled() && !in_array($user['role'], ['admin','manager'], true)) {
    flash('Messaging is currently disabled.', 'info');
    header('Location: dashboard.php');
    exit;
}

$convId       = intval($_GET['id'] ?? 0);
$conversations = get_user_conversations($user['id']);
$activeConv   = null;
$other        = null;
$initialMsgs  = [];
$lastMsgId    = 0;

if ($convId) {
    if (!can_access_conversation($convId, $user['id'])) {
        flash('Conversation not found.', 'error');
        header('Location: chat.php');
        exit;
    }
    $other       = get_other_participant($convId, $user['id']);
    $initialMsgs = get_conversation_messages($convId, $user['id'], 50);
    if ($initialMsgs) {
        $lastMsgId = (int)end($initialMsgs)['id'];
        // mark as read
        $ids = array_column($initialMsgs, 'id');
        $pl  = implode(',', array_fill(0, count($ids), '?'));
        $params = $ids; $params[] = $user['id'];
        $pdo->prepare("UPDATE chat_messages SET is_read=1 WHERE id IN ($pl) AND sender_id!=? AND is_read=0")->execute($params);
    }
    // find active conv meta
    foreach ($conversations as $c) {
        if ((int)$c['id'] === $convId) { $activeConv = $c; break; }
    }
}

$myStatus = get_user_chat_status($user['id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"/>
    <meta name="viewport" content="width=device-width,initial-scale=1.0"/>
    <title><?php echo $other ? 'Chat with ' . sanitize($other['name']) : 'Messages'; ?> — AkuapemHub</title>
    <link rel="stylesheet" href="assets/css/style.css"/>
    <style>
        .chat-layout { display:flex; height:calc(100vh - 56px - 60px); overflow:hidden; }
        .conv-list { width:300px; min-width:220px; border-right:1px solid var(--border); overflow-y:auto; flex-shrink:0; }
        .conv-item { display:flex; align-items:center; gap:10px; padding:12px 14px; border-bottom:1px solid var(--border); cursor:pointer; text-decoration:none; color:inherit; }
        .conv-item:hover, .conv-item.active { background:var(--surface); }
        .conv-avatar { width:40px; height:40px; border-radius:50%; background:var(--primary); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:1rem; flex-shrink:0; }
        .conv-avatar img { width:40px; height:40px; border-radius:50%; object-fit:cover; }
        .conv-meta { flex:1; min-width:0; }
        .conv-name { font-weight:600; font-size:0.95rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .conv-preview { font-size:0.8rem; color:var(--text-muted); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; margin-top:2px; }
        .conv-job-tag { font-size:0.72rem; color:var(--primary); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; margin-top:1px; font-weight:500; }
        .conv-badge { background:var(--primary); color:#fff; border-radius:50%; min-width:18px; height:18px; font-size:0.7rem; font-weight:700; display:flex; align-items:center; justify-content:center; padding:0 3px; }
        .chat-pane { flex:1; display:flex; flex-direction:column; min-width:0; }
        .chat-header { padding:10px 16px; border-bottom:1px solid var(--border); display:flex; align-items:center; gap:10px; flex-shrink:0; background:var(--bg); }
        .chat-messages { flex:1; overflow-y:auto; padding:16px; display:flex; flex-direction:column; gap:8px; }
        .msg-bubble { max-width:72%; padding:8px 12px; border-radius:14px; font-size:0.9rem; line-height:1.45; word-break:break-word; }
        .msg-mine { align-self:flex-end; background:var(--primary); color:#fff; border-bottom-right-radius:4px; }
        .msg-theirs { align-self:flex-start; background:var(--surface); border:1px solid var(--border); border-bottom-left-radius:4px; }
        .msg-time { font-size:0.68rem; opacity:0.65; margin-top:3px; display:block; }
        .msg-wrap { display:flex; flex-direction:column; }
        .msg-wrap.mine { align-items:flex-end; }
        .msg-wrap.theirs { align-items:flex-start; }
        .msg-actions { font-size:0.7rem; display:none; gap:6px; margin-top:2px; }
        .msg-wrap:hover .msg-actions { display:flex; }
        .msg-actions button { background:none; border:none; cursor:pointer; color:var(--text-muted); padding:0; font-size:0.7rem; }
        .chat-input-bar { display:flex; gap:8px; padding:10px 14px; border-top:1px solid var(--border); flex-shrink:0; background:var(--bg); }
        .chat-input-bar textarea { flex:1; resize:none; border:1px solid var(--border); border-radius:8px; padding:8px 10px; font-size:0.9rem; font-family:inherit; line-height:1.4; max-height:120px; overflow-y:auto; }
        .chat-input-bar button { padding:0 16px; border-radius:8px; }
        .empty-chat { flex:1; display:flex; align-items:center; justify-content:center; color:var(--text-muted); font-size:0.95rem; }
        .conv-list-header { padding:12px 14px; font-weight:700; border-bottom:1px solid var(--border); font-size:0.95rem; }
        @media (max-width:640px) {
            .conv-list { display: <?php echo $convId ? 'none' : 'block'; ?>; width:100%; }
            .chat-pane { display: <?php echo $convId ? 'flex' : 'none'; ?>; }
        }
        .file-btn { background:none; border:1px solid var(--border); border-radius:8px; padding:6px 10px; cursor:pointer; font-size:0.85rem; color:var(--text-muted); }
        .img-msg { max-width:200px; border-radius:8px; display:block; margin-top:4px; }
        .suspended-bar { background:#fef3c7; color:#92400e; padding:10px 14px; text-align:center; font-size:0.85rem; border-bottom:1px solid #fde68a; }
    </style>
</head>
<body class="has-bottom-nav">
<header class="app-topbar">
    <?php if ($convId): ?>
        <a href="chat.php" class="button button-secondary button-small">← Back</a>
    <?php else: ?>
        <a href="dashboard.php" class="button button-secondary button-small">← Back</a>
    <?php endif; ?>
    <span class="brand">💬 Messages</span>
</header>

<?php if ($myStatus['banned']): ?>
    <div class="suspended-bar">Your messaging is suspended until <?php echo date('M j, Y', strtotime($myStatus['ban_until'])); ?>.</div>
<?php elseif (!$myStatus['can_send']): ?>
    <div class="suspended-bar">Your messaging privileges have been disabled by an administrator.</div>
<?php endif; ?>

<div class="chat-layout">
    <!-- Conversation list -->
    <div class="conv-list">
        <div class="conv-list-header">Conversations</div>
        <?php if (empty($conversations)): ?>
            <div style="padding:20px;color:var(--text-muted);font-size:0.88rem;">No conversations yet.</div>
        <?php endif; ?>
        <?php foreach ($conversations as $c): ?>
            <?php
            $isActive = (int)$c['id'] === $convId;
            $avatar   = $c['other_photo'] ? '<img src="' . sanitize($c['other_photo']) . '" alt="">' : strtoupper(substr($c['other_name'], 0, 1));
            $preview  = $c['last_message'] ?? 'No messages yet';
            if (mb_strlen($preview) > 50) $preview = mb_substr($preview, 0, 50) . '…';
            ?>
            <a href="chat.php?id=<?php echo $c['id']; ?>" class="conv-item <?php echo $isActive ? 'active' : ''; ?>">
                <div class="conv-avatar"><?php echo $c['other_photo'] ? '<img src="' . sanitize($c['other_photo']) . '" alt="">' : htmlspecialchars(strtoupper(substr($c['other_name'],0,1))); ?></div>
                <div class="conv-meta">
                    <div class="conv-name"><?php echo sanitize($c['other_name']); ?></div>
                    <?php if ($c['job_id'] && $c['job_title']): ?>
                        <div class="conv-job-tag">Re: <?php echo sanitize($c['job_title']); ?></div>
                    <?php endif; ?>
                    <div class="conv-preview"><?php echo sanitize($preview); ?></div>
                </div>
                <?php if ($c['unread_count'] > 0): ?>
                    <div class="conv-badge"><?php echo (int)$c['unread_count']; ?></div>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- Message thread -->
    <div class="chat-pane">
        <?php if (!$convId): ?>
            <div class="empty-chat">Select a conversation to start messaging.</div>
        <?php elseif (!$other): ?>
            <div class="empty-chat">Conversation not found.</div>
        <?php else: ?>
            <div class="chat-header">
                <div class="conv-avatar" style="width:36px;height:36px;font-size:0.9rem;">
                    <?php echo $other['profile_photo'] ? '<img src="' . sanitize($other['profile_photo']) . '" style="width:36px;height:36px;border-radius:50%;object-fit:cover;" alt="">' : htmlspecialchars(strtoupper(substr($other['name'],0,1))); ?>
                </div>
                <div>
                    <a href="<?php echo $other['role']==='worker' ? 'worker_profile_public.php?id='.$other['id'] : 'profile.php?id='.$other['id']; ?>" style="font-weight:600;text-decoration:none;color:inherit;"><?php echo sanitize($other['name']); ?></a>
                    <div class="meta" style="font-size:0.75rem;"><?php echo sanitize(ucfirst($other['role'])); ?></div>
                    <?php if ($activeConv && $activeConv['job_id'] && $activeConv['job_title']): ?>
                        <div style="font-size:0.75rem;margin-top:2px;">
                            Re: <a href="request_detail.php?id=<?php echo (int)$activeConv['job_id']; ?>" style="color:var(--primary);text-decoration:none;font-weight:500;"><?php echo sanitize($activeConv['job_title']); ?></a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="chat-messages" id="msgContainer">
                <?php foreach ($initialMsgs as $m): ?>
                    <?php $mine = (int)$m['sender_id'] === $user['id']; ?>
                    <div class="msg-wrap <?php echo $mine ? 'mine' : 'theirs'; ?>" data-id="<?php echo $m['id']; ?>">
                        <div class="msg-bubble <?php echo $mine ? 'msg-mine' : 'msg-theirs'; ?>">
                            <?php if ($m['message_type'] === 'image' && $m['file_path']): ?>
                                <img src="<?php echo sanitize($m['file_path']); ?>" class="img-msg" alt="Image">
                            <?php elseif ($m['message_type'] === 'file' && $m['file_path']): ?>
                                <a href="<?php echo sanitize($m['file_path']); ?>" target="_blank" style="color:inherit;">📎 Download file</a>
                            <?php else: ?>
                                <?php echo nl2br(sanitize($m['message'])); ?>
                            <?php endif; ?>
                            <span class="msg-time"><?php echo date('M j, g:i a', strtotime($m['created_at'])); ?></span>
                        </div>
                        <div class="msg-actions">
                            <?php if (!$mine): ?>
                                <button onclick="reportMsg(<?php echo $m['id']; ?>)">⚑ Report</button>
                            <?php endif; ?>
                            <button onclick="deleteMsg(<?php echo $m['id']; ?>, this)">Delete for me</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ($myStatus['can_send']): ?>
            <div class="chat-input-bar">
                <input type="file" id="fileInput" style="display:none;" accept="image/*,.pdf">
                <button class="file-btn" onclick="document.getElementById('fileInput').click()">📎</button>
                <textarea id="msgText" placeholder="Type a message…" rows="1" onkeydown="handleKey(event)"></textarea>
                <button class="button button-primary" onclick="sendMessage()">Send</button>
            </div>
            <?php else: ?>
            <div style="padding:10px 14px;text-align:center;color:var(--text-muted);font-size:0.85rem;">You cannot send messages at this time.</div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php $activeNav = 'messages'; require __DIR__ . '/partials/bottom_nav.php'; ?>

<?php if ($convId): ?>
<script>
const CONV_ID    = <?php echo $convId; ?>;
const MY_ID      = <?php echo $user['id']; ?>;
const CSRF_TOKEN = '<?php echo csrf_token(); ?>';
let lastMsgId    = <?php echo $lastMsgId; ?>;
let polling    = true;
let pollTimer  = null;

function scrollBottom() {
    const c = document.getElementById('msgContainer');
    if (c) c.scrollTop = c.scrollHeight;
}
scrollBottom();

function buildBubble(m) {
    const mine = parseInt(m.sender_id) === MY_ID;
    let inner  = '';
    if (m.message_type === 'image' && m.file_path) {
        inner = `<img src="${escHtml(m.file_path)}" class="img-msg" alt="Image">`;
    } else if (m.message_type === 'file' && m.file_path) {
        inner = `<a href="${escHtml(m.file_path)}" target="_blank" style="color:inherit">📎 Download file</a>`;
    } else {
        inner = nl2br(escHtml(m.message));
    }
    const d = new Date(m.created_at.replace(' ','T'));
    const timeStr = d.toLocaleString('en-US',{month:'short',day:'numeric',hour:'numeric',minute:'2-digit'});
    const reportBtn = !mine ? `<button onclick="reportMsg(${m.id})">⚑ Report</button>` : '';
    return `<div class="msg-wrap ${mine?'mine':'theirs'}" data-id="${m.id}">
        <div class="msg-bubble ${mine?'msg-mine':'msg-theirs'}">${inner}<span class="msg-time">${timeStr}</span></div>
        <div class="msg-actions">${reportBtn}<button onclick="deleteMsg(${m.id},this)">Delete for me</button></div>
    </div>`;
}

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function nl2br(s) { return s.replace(/\n/g,'<br>'); }

async function poll() {
    if (!polling) return;
    try {
        const res = await fetch(`chat_api.php?action=poll&conversation_id=${CONV_ID}&after_id=${lastMsgId}`);
        const data = await res.json();
        if (data.messages && data.messages.length) {
            const container = document.getElementById('msgContainer');
            const wasAtBottom = container.scrollHeight - container.scrollTop - container.clientHeight < 60;
            data.messages.forEach(m => {
                if (!document.querySelector(`[data-id="${m.id}"]`)) {
                    container.insertAdjacentHTML('beforeend', buildBubble(m));
                    lastMsgId = Math.max(lastMsgId, parseInt(m.id));
                }
            });
            if (wasAtBottom) scrollBottom();
        }
    } catch(e) {}
    pollTimer = setTimeout(poll, 3000);
}
poll();

async function sendMessage() {
    const text = document.getElementById('msgText').value.trim();
    const fileInput = document.getElementById('fileInput');
    const file = fileInput.files[0];
    if (!text && !file) return;

    const fd = new FormData();
    fd.append('action','send');
    fd.append('conversation_id', CONV_ID);
    fd.append('message', text);
    fd.append('csrf_token', CSRF_TOKEN);
    if (file) fd.append('file', file);

    document.getElementById('msgText').value = '';
    fileInput.value = '';

    try {
        const res = await fetch('chat_api.php', {method:'POST', body:fd});
        const data = await res.json();
        if (data.error) { alert(data.error); return; }
        // Poll immediately for the new message
        clearTimeout(pollTimer);
        poll();
    } catch(e) { alert('Failed to send. Please try again.'); }
}

function handleKey(e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
}

async function reportMsg(msgId) {
    const reason = prompt('Why are you reporting this message?');
    if (!reason) return;
    const fd = new FormData();
    fd.append('action','report');
    fd.append('message_id', msgId);
    fd.append('reason', reason);
    const res = await fetch('chat_api.php', {method:'POST',body:fd});
    const data = await res.json();
    alert(data.ok ? 'Report submitted. Thank you.' : (data.error || 'Error'));
}

async function deleteMsg(msgId, btn) {
    if (!confirm('Delete this message for you?')) return;
    const fd = new FormData();
    fd.append('action','soft_delete');
    fd.append('message_id', msgId);
    const res = await fetch('chat_api.php', {method:'POST',body:fd});
    const data = await res.json();
    if (data.ok) { btn.closest('[data-id]').remove(); }
    else alert(data.error || 'Error');
}

// Auto-resize textarea
document.getElementById('msgText')?.addEventListener('input', function() {
    this.style.height = 'auto';
    this.style.height = Math.min(this.scrollHeight, 120) + 'px';
});

// Show selected file name
document.getElementById('fileInput')?.addEventListener('change', function() {
    if (this.files[0]) {
        document.getElementById('msgText').placeholder = '📎 ' + this.files[0].name + ' (press Send)';
    }
});

window.addEventListener('beforeunload', () => { polling = false; clearTimeout(pollTimer); });
</script>
<?php endif; ?>
</body>
</html>
