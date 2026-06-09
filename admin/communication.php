<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../chat_functions.php';

require_login();
$user = current_user();
if (!in_array($user['role'], ['admin','manager'], true)) {
    header('Location: ../dashboard.php'); exit;
}

$tab = $_GET['tab'] ?? 'dashboard';
$msg = '';

// ── POST actions ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';

    if ($act === 'save_chat_settings') {
        $keys = ['chat_disabled','chat_allow_applicant_chat','chat_allow_hired_chat','chat_allow_worker_worker','chat_allow_direct_all'];
        foreach ($keys as $k) {
            set_platform_setting($k, isset($_POST[$k]) ? '1' : '0');
        }
        log_chat_audit($user['id'], 'update_chat_settings', null, null, 'Admin updated chat platform settings');
        $msg = 'Settings saved.';
        $tab = 'settings';
    }

    if ($act === 'restrict_user' && $user['role'] === 'admin') {
        $tid      = intval($_POST['target_user_id'] ?? 0);
        $canSend  = isset($_POST['can_send']) ? 1 : 0;
        $canRecv  = isset($_POST['can_receive']) ? 1 : 0;
        $banUntil = !empty($_POST['ban_until']) ? $_POST['ban_until'] : null;
        if ($banUntil && !preg_match('/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2})?$/', $banUntil)) $banUntil = null;
        $pdo->prepare("UPDATE users SET can_send_messages=?, can_receive_messages=?, chat_ban_until=? WHERE id=?")
            ->execute([$canSend, $canRecv, $banUntil, $tid]);
        log_chat_audit($user['id'], 'restrict_user', $tid, null, "can_send=$canSend, can_receive=$canRecv, ban_until=" . ($banUntil ?? 'null'));
        $msg = 'User restrictions updated.';
        $tab = 'users';
    }

    if ($act === 'close_conversation') {
        $cid = intval($_POST['conversation_id'] ?? 0);
        $pdo->prepare("UPDATE conversations SET status='closed' WHERE id=?")->execute([$cid]);
        log_chat_audit($user['id'], 'close_conversation', null, $cid, 'Admin closed conversation');
        $msg = 'Conversation closed.';
        $tab = 'conversations';
    }

    if ($act === 'block_conversation') {
        $cid = intval($_POST['conversation_id'] ?? 0);
        $pdo->prepare("UPDATE conversations SET status='blocked' WHERE id=?")->execute([$cid]);
        log_chat_audit($user['id'], 'block_conversation', null, $cid, 'Admin blocked conversation');
        $msg = 'Conversation blocked.';
        $tab = 'conversations';
    }

    if ($act === 'delete_message') {
        $mid = intval($_POST['message_id'] ?? 0);
        $pdo->prepare("UPDATE chat_messages SET deleted_by_sender=1, deleted_by_receiver=1 WHERE id=?")->execute([$mid]);
        log_chat_audit($user['id'], 'delete_message', null, null, "Deleted message id=$mid");
        $msg = 'Message deleted.';
        $tab = 'reports';
    }

    if ($act === 'review_report') {
        $rid    = intval($_POST['report_id'] ?? 0);
        $status = $_POST['report_status'] ?? 'dismissed';
        if (!in_array($status, ['reviewed','dismissed'])) $status = 'dismissed';
        $pdo->prepare("UPDATE message_reports SET status=? WHERE id=?")->execute([$status, $rid]);
        log_chat_audit($user['id'], 'review_report', null, null, "Report $rid marked $status");
        $msg = 'Report updated.';
        $tab = 'reports';
    }

    if ($act === 'grant_chat') {
        $uid1 = intval($_POST['user1_id'] ?? 0);
        $uid2 = intval($_POST['user2_id'] ?? 0);
        if ($uid1 && $uid2 && $uid1 !== $uid2) {
            $convId = get_or_create_conversation($user['id'], $uid2, 'admin_granted', null);
            // Ensure both target users are participants
            foreach ([$uid1, $uid2] as $uid) {
                $check = $pdo->prepare("SELECT id FROM conversation_participants WHERE conversation_id=? AND user_id=?");
                $check->execute([$convId, $uid]);
                if (!$check->fetch()) {
                    $pdo->prepare("INSERT INTO conversation_participants (conversation_id, user_id, joined_at) VALUES (?,?,NOW())")->execute([$convId, $uid]);
                }
            }
            // Update type if needed
            $pdo->prepare("UPDATE conversations SET conversation_type='admin_granted', status='active', created_by=? WHERE id=?")->execute([$user['id'], $convId]);
            log_chat_audit($user['id'], 'grant_chat', $uid1, $convId, "Admin granted chat between user $uid1 and $uid2");
            $msg = 'Chat access granted.';
        }
        $tab = 'users';
    }

    header('Location: communication.php?tab=' . $tab . '&msg=' . urlencode($msg));
    exit;
}

if (!$msg && isset($_GET['msg'])) $msg = $_GET['msg'];

// ── Data for tabs ─────────────────────────────────────────────────────────────

// Dashboard stats
$statMsgs  = (int)$pdo->query("SELECT COUNT(*) FROM chat_messages WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn();
$statConvs = (int)$pdo->query("SELECT COUNT(*) FROM conversations WHERE status='active'")->fetchColumn();
$statReports = (int)$pdo->query("SELECT COUNT(*) FROM message_reports WHERE status='pending'")->fetchColumn();
$statFlagged = (int)$pdo->query("SELECT COUNT(*) FROM chat_messages WHERE is_flagged=1 AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();

// Conversations list
$conversations = [];
if ($tab === 'conversations' || $tab === 'dashboard') {
    $cStmt = $pdo->query("
        SELECT c.*,
               GROUP_CONCAT(u.name ORDER BY u.name SEPARATOR ', ') AS participant_names,
               (SELECT COUNT(*) FROM chat_messages cm WHERE cm.conversation_id = c.id) AS msg_count,
               (SELECT COUNT(*) FROM chat_messages cm WHERE cm.conversation_id = c.id AND cm.is_flagged=1) AS flag_count,
               (SELECT cm2.created_at FROM chat_messages cm2 WHERE cm2.conversation_id=c.id ORDER BY cm2.id DESC LIMIT 1) AS last_at
        FROM conversations c
        JOIN conversation_participants cp ON c.id = cp.conversation_id
        JOIN users u ON cp.user_id = u.id
        GROUP BY c.id
        ORDER BY last_at DESC, c.created_at DESC
        LIMIT 100
    ");
    $conversations = $cStmt->fetchAll();
}

// View single conversation messages
$viewConvId   = intval($_GET['view'] ?? 0);
$viewMessages = [];
$viewConv     = null;
if ($viewConvId && $tab === 'conversations') {
    $vcStmt = $pdo->prepare("SELECT * FROM conversations WHERE id=?");
    $vcStmt->execute([$viewConvId]);
    $viewConv = $vcStmt->fetch();
    if ($viewConv) {
        $vmStmt = $pdo->prepare("
            SELECT cm.*, u.name AS sender_name FROM chat_messages cm JOIN users u ON cm.sender_id=u.id
            WHERE cm.conversation_id=? ORDER BY cm.created_at ASC LIMIT 200
        ");
        $vmStmt->execute([$viewConvId]);
        $viewMessages = $vmStmt->fetchAll();
    }
}

// Users list with chat status
$chatUsers = [];
if ($tab === 'users') {
    $uStmt = $pdo->query("
        SELECT id, name, email, role, can_send_messages, can_receive_messages, chat_ban_until
        FROM users WHERE role NOT IN ('admin') ORDER BY name ASC LIMIT 200
    ");
    $chatUsers = $uStmt->fetchAll();
}

// Reports
$reports = [];
if ($tab === 'reports') {
    $rStmt = $pdo->query("
        SELECT mr.*, cm.message, cm.sender_id, cm.conversation_id,
               u_rep.name AS reporter_name, u_sender.name AS sender_name
        FROM message_reports mr
        JOIN chat_messages cm ON mr.message_id = cm.id
        JOIN users u_rep ON mr.reported_by = u_rep.id
        JOIN users u_sender ON cm.sender_id = u_sender.id
        ORDER BY mr.status ASC, mr.created_at DESC
        LIMIT 100
    ");
    $reports = $rStmt->fetchAll();
}

// Audit log
$auditLogs = [];
if ($tab === 'audit') {
    $aStmt = $pdo->query("
        SELECT cal.*, u.name AS admin_name FROM chat_audit_logs cal
        LEFT JOIN users u ON cal.admin_id = u.id
        ORDER BY cal.created_at DESC LIMIT 200
    ");
    $auditLogs = $aStmt->fetchAll();
}

// Platform users for grant chat
$allUsers = $pdo->query("SELECT id, name, role FROM users WHERE role NOT IN ('admin') ORDER BY name ASC")->fetchAll();

$chatSettings = [
    'chat_disabled'            => get_platform_setting('chat_disabled','0'),
    'chat_allow_applicant_chat'=> get_platform_setting('chat_allow_applicant_chat','1'),
    'chat_allow_hired_chat'    => get_platform_setting('chat_allow_hired_chat','1'),
    'chat_allow_worker_worker' => get_platform_setting('chat_allow_worker_worker','0'),
    'chat_allow_direct_all'    => get_platform_setting('chat_allow_direct_all','0'),
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"/>
    <meta name="viewport" content="width=device-width,initial-scale=1.0"/>
    <title>Communication Centre — AkuapemHub Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css"/>
    <style>
        .tab-bar { display:flex; gap:0; border-bottom:2px solid var(--border); margin-bottom:20px; flex-wrap:wrap; }
        .tab-btn { padding:9px 18px; border:none; background:none; cursor:pointer; font-size:0.9rem; font-weight:500; color:var(--text-muted); border-bottom:3px solid transparent; margin-bottom:-2px; }
        .tab-btn.active { color:var(--primary); border-bottom-color:var(--primary); }
        .stat-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:12px; margin-bottom:20px; }
        .stat-card { background:var(--surface); border:1px solid var(--border); border-radius:10px; padding:14px 16px; text-align:center; }
        .stat-val { font-size:1.7rem; font-weight:700; }
        .stat-label { font-size:0.78rem; color:var(--text-muted); margin-top:4px; }
        .badge-red { background:#fde8e8;color:#b91c1c;padding:2px 8px;border-radius:20px;font-size:0.72rem;font-weight:700; }
        .badge-green { background:#d1fae5;color:#065f46;padding:2px 8px;border-radius:20px;font-size:0.72rem;font-weight:700; }
        .badge-amber { background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:20px;font-size:0.72rem;font-weight:700; }
        .msg-log { background:var(--surface); border:1px solid var(--border); border-radius:8px; padding:14px; max-height:400px; overflow-y:auto; }
        .msg-log-item { padding:6px 0; border-bottom:1px solid var(--border); font-size:0.85rem; }
        .msg-log-item:last-child { border-bottom:none; }
        .conv-row td { vertical-align:middle; }
        .toggle-label { display:flex; align-items:center; gap:10px; padding:8px 0; }
        .toggle-label input[type=checkbox] { width:18px; height:18px; }
        .form-row { display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; margin-bottom:12px; }
        .form-row label { font-size:0.85rem; font-weight:600; }
        .form-row select, .form-row input { padding:6px 10px; border:1px solid var(--border); border-radius:6px; font-size:0.85rem; }
    </style>
</head>
<body>
<header class="app-topbar">
    <a href="index.php" class="button button-secondary button-small">← Admin</a>
    <span class="brand">💬 Communication Centre</span>
</header>
<main class="page-shell">
    <?php if ($msg): ?>
        <div class="alert alert-success"><?php echo sanitize($msg); ?></div>
    <?php endif; ?>

    <div class="tab-bar">
        <?php foreach (['dashboard'=>'Dashboard','conversations'=>'Conversations','users'=>'Users','reports'=>'Reports','audit'=>'Audit Log','settings'=>'Settings'] as $k=>$label): ?>
            <a href="communication.php?tab=<?php echo $k; ?>" class="tab-btn <?php echo $tab===$k?'active':''; ?>"><?php echo $label; ?></a>
        <?php endforeach; ?>
    </div>

    <!-- DASHBOARD ──────────────────────────────────────────────────────────── -->
    <?php if ($tab === 'dashboard'): ?>
        <div class="stat-grid">
            <div class="stat-card"><div class="stat-val"><?php echo $statMsgs; ?></div><div class="stat-label">Messages (24h)</div></div>
            <div class="stat-card"><div class="stat-val"><?php echo $statConvs; ?></div><div class="stat-label">Active Conversations</div></div>
            <div class="stat-card"><div class="stat-val" style="color:<?php echo $statReports>0?'var(--danger)':'inherit'; ?>"><?php echo $statReports; ?></div><div class="stat-label">Pending Reports</div></div>
            <div class="stat-card"><div class="stat-val" style="color:<?php echo $statFlagged>0?'#92400e':'inherit'; ?>"><?php echo $statFlagged; ?></div><div class="stat-label">Auto-Flagged (7d)</div></div>
        </div>

        <?php if ($statReports > 0): ?>
            <div class="alert alert-error" style="margin-bottom:16px;">⚠ <?php echo $statReports; ?> message report<?php echo $statReports>1?'s':''; ?> awaiting review. <a href="communication.php?tab=reports">Review →</a></div>
        <?php endif; ?>

        <div class="panel">
            <h3 style="margin-top:0;">Recent conversations</h3>
            <?php if (empty($conversations)): ?>
                <div class="empty-state">No conversations yet.</div>
            <?php else: ?>
                <div class="table-wrapper">
                <table>
                    <thead><tr><th>Participants</th><th>Type</th><th>Messages</th><th>Flagged</th><th>Status</th><th>Last Active</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($conversations,0,10) as $c): ?>
                        <tr class="conv-row">
                            <td><?php echo sanitize($c['participant_names']); ?></td>
                            <td><span class="badge-amber"><?php echo sanitize(str_replace('_',' ',$c['conversation_type'])); ?></span></td>
                            <td><?php echo (int)$c['msg_count']; ?></td>
                            <td><?php echo $c['flag_count']>0 ? '<span class="badge-red">'.(int)$c['flag_count'].'</span>' : '—'; ?></td>
                            <td><?php echo $c['status']==='active'?'<span class="badge-green">Active</span>':('<span class="badge-red">'.ucfirst($c['status']).'</span>'); ?></td>
                            <td><?php echo $c['last_at'] ? date('M j, g:i a', strtotime($c['last_at'])) : '—'; ?></td>
                            <td><a href="communication.php?tab=conversations&view=<?php echo $c['id']; ?>" class="button button-secondary button-small">View</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php if (count($conversations)>10): ?><p><a href="communication.php?tab=conversations">View all →</a></p><?php endif; ?>
            <?php endif; ?>
        </div>

    <!-- CONVERSATIONS ──────────────────────────────────────────────────────── -->
    <?php elseif ($tab === 'conversations'): ?>
        <?php if ($viewConvId && $viewConv): ?>
            <div class="panel">
                <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;">
                    <a href="communication.php?tab=conversations" class="button button-secondary button-small">← Back</a>
                    <h3 style="margin:0;">Conversation #<?php echo $viewConvId; ?> — <?php echo sanitize(str_replace('_',' ',$viewConv['conversation_type'])); ?></h3>
                    <span class="badge-<?php echo $viewConv['status']==='active'?'green':($viewConv['status']==='blocked'?'red':'amber'); ?>"><?php echo ucfirst($viewConv['status']); ?></span>
                </div>
                <div style="display:flex;gap:8px;margin-bottom:14px;">
                    <?php if ($viewConv['status'] !== 'blocked'): ?>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="action" value="block_conversation">
                            <input type="hidden" name="conversation_id" value="<?php echo $viewConvId; ?>">
                            <button class="button button-danger button-small" onclick="return confirm('Block this conversation?')">Block</button>
                        </form>
                    <?php endif; ?>
                    <?php if ($viewConv['status'] !== 'closed'): ?>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="action" value="close_conversation">
                            <input type="hidden" name="conversation_id" value="<?php echo $viewConvId; ?>">
                            <button class="button button-secondary button-small" onclick="return confirm('Close this conversation?')">Close</button>
                        </form>
                    <?php endif; ?>
                </div>
                <div class="msg-log">
                    <?php if (empty($viewMessages)): ?>
                        <div class="empty-state">No messages yet.</div>
                    <?php else: ?>
                        <?php foreach ($viewMessages as $m): ?>
                            <div class="msg-log-item <?php echo $m['is_flagged']?'':''; ?>">
                                <strong><?php echo sanitize($m['sender_name']); ?></strong>
                                <?php if ($m['is_flagged']): ?><span class="badge-red">⚑ <?php echo sanitize($m['flag_reason']); ?></span><?php endif; ?>
                                <span style="color:var(--text-muted);font-size:0.75rem;margin-left:6px;"><?php echo date('M j, g:i a', strtotime($m['created_at'])); ?></span>
                                <div style="margin-top:3px;"><?php echo nl2br(sanitize($m['message'])); ?></div>
                                <form method="post" style="margin-top:4px;display:inline;">
                                    <input type="hidden" name="action" value="delete_message">
                                    <input type="hidden" name="message_id" value="<?php echo $m['id']; ?>">
                                    <button class="button button-danger button-small" style="font-size:0.7rem;padding:2px 8px;" onclick="return confirm('Delete this message?')">Delete</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <div class="panel">
                <h3 style="margin-top:0;">All Conversations</h3>
                <?php if (empty($conversations)): ?>
                    <div class="empty-state">No conversations yet.</div>
                <?php else: ?>
                    <div class="table-wrapper">
                    <table>
                        <thead><tr><th>Participants</th><th>Type</th><th>Msgs</th><th>Flagged</th><th>Status</th><th>Last Active</th><th>Actions</th></tr></thead>
                        <tbody>
                        <?php foreach ($conversations as $c): ?>
                            <tr class="conv-row">
                                <td><?php echo sanitize($c['participant_names']); ?></td>
                                <td><span class="badge-amber" style="font-size:0.72rem;"><?php echo sanitize(str_replace('_',' ',$c['conversation_type'])); ?></span></td>
                                <td><?php echo (int)$c['msg_count']; ?></td>
                                <td><?php echo $c['flag_count']>0?'<span class="badge-red">'.(int)$c['flag_count'].'</span>':'—'; ?></td>
                                <td><?php echo $c['status']==='active'?'<span class="badge-green">Active</span>':('<span class="badge-red">'.ucfirst($c['status']).'</span>'); ?></td>
                                <td><?php echo $c['last_at'] ? date('M j g:i a', strtotime($c['last_at'])) : '—'; ?></td>
                                <td style="white-space:nowrap;">
                                    <a href="communication.php?tab=conversations&view=<?php echo $c['id']; ?>" class="button button-secondary button-small">View</a>
                                    <?php if ($c['status']==='active'): ?>
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="action" value="block_conversation">
                                            <input type="hidden" name="conversation_id" value="<?php echo $c['id']; ?>">
                                            <button class="button button-danger button-small" onclick="return confirm('Block?')">Block</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    <!-- USERS ──────────────────────────────────────────────────────────────── -->
    <?php elseif ($tab === 'users'): ?>
        <?php if ($user['role'] === 'admin'): ?>
        <div class="panel" style="margin-bottom:20px;">
            <h3 style="margin-top:0;">Grant Direct Chat Access</h3>
            <p style="font-size:0.85rem;color:var(--text-muted);">Allow two users to message each other regardless of job relationships.</p>
            <form method="post">
                <input type="hidden" name="action" value="grant_chat">
                <div class="form-row">
                    <div><label>User 1</label><br><select name="user1_id" required>
                        <option value="">Select…</option>
                        <?php foreach ($allUsers as $u): ?><option value="<?php echo $u['id']; ?>"><?php echo sanitize($u['name']); ?> (<?php echo $u['role']; ?>)</option><?php endforeach; ?>
                    </select></div>
                    <div><label>User 2</label><br><select name="user2_id" required>
                        <option value="">Select…</option>
                        <?php foreach ($allUsers as $u): ?><option value="<?php echo $u['id']; ?>"><?php echo sanitize($u['name']); ?> (<?php echo $u['role']; ?>)</option><?php endforeach; ?>
                    </select></div>
                    <button type="submit" class="button button-primary button-small">Grant Access</button>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <div class="panel">
            <h3 style="margin-top:0;">User Messaging Restrictions</h3>
            <div class="table-wrapper">
            <table>
                <thead><tr><th>User</th><th>Role</th><th>Can Send</th><th>Can Receive</th><th>Ban Until</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($chatUsers as $cu): ?>
                    <?php $banned = !empty($cu['chat_ban_until']) && strtotime($cu['chat_ban_until']) > time(); ?>
                    <tr>
                        <td><?php echo sanitize($cu['name']); ?><br><span class="meta"><?php echo sanitize($cu['email']); ?></span></td>
                        <td><?php echo ucfirst($cu['role']); ?></td>
                        <td><?php echo $cu['can_send_messages']?'<span class="badge-green">Yes</span>':'<span class="badge-red">No</span>'; ?></td>
                        <td><?php echo $cu['can_receive_messages']?'<span class="badge-green">Yes</span>':'<span class="badge-red">No</span>'; ?></td>
                        <td><?php echo $banned ? '<span class="badge-red">'.date('M j, Y', strtotime($cu['chat_ban_until'])).'</span>' : '—'; ?></td>
                        <td>
                            <?php if ($user['role'] === 'admin'): ?>
                            <button class="button button-secondary button-small" onclick="openRestrict(<?php echo $cu['id']; ?>, '<?php echo sanitize($cu['name']); ?>', <?php echo $cu['can_send_messages']; ?>, <?php echo $cu['can_receive_messages']; ?>, '<?php echo $cu['chat_ban_until'] ?? ''; ?>')">Edit</button>
                            <?php else: ?><span style="color:var(--text-muted);font-size:0.8rem;">Admin only</span><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>

        <!-- Restrict modal -->
        <div id="restrictModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:999;align-items:center;justify-content:center;">
            <div style="background:var(--bg);border-radius:12px;padding:24px;width:360px;max-width:94vw;">
                <h3 style="margin:0 0 16px;" id="restrictTitle">Restrict User</h3>
                <form method="post">
                    <input type="hidden" name="action" value="restrict_user">
                    <input type="hidden" name="target_user_id" id="restrictUserId">
                    <label class="toggle-label"><input type="checkbox" name="can_send" id="rCanSend"> Can send messages</label>
                    <label class="toggle-label"><input type="checkbox" name="can_receive" id="rCanRecv"> Can receive messages</label>
                    <div style="margin:10px 0;">
                        <label style="font-size:0.85rem;font-weight:600;">Temporary ban until (optional)</label><br>
                        <input type="date" name="ban_until" id="rBanUntil" style="margin-top:4px;padding:6px 10px;border:1px solid var(--border);border-radius:6px;font-size:0.85rem;">
                    </div>
                    <div style="display:flex;gap:8px;margin-top:16px;">
                        <button type="submit" class="button button-primary">Save</button>
                        <button type="button" class="button button-secondary" onclick="closeRestrict()">Cancel</button>
                    </div>
                </form>
            </div>
        </div>

    <!-- REPORTS ────────────────────────────────────────────────────────────── -->
    <?php elseif ($tab === 'reports'): ?>
        <div class="panel">
            <h3 style="margin-top:0;">Message Reports</h3>
            <?php if (empty($reports)): ?>
                <div class="empty-state">No reports yet.</div>
            <?php else: ?>
                <?php foreach ($reports as $r): ?>
                    <div style="border:1px solid var(--border);border-radius:8px;padding:14px;margin-bottom:12px;<?php echo $r['status']==='pending'?'border-left:3px solid var(--danger);':'opacity:0.7;'; ?>">
                        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
                            <div>
                                <strong>Report #<?php echo $r['id']; ?></strong>
                                <span class="badge-<?php echo $r['status']==='pending'?'red':($r['status']==='reviewed'?'green':'amber'); ?>" style="margin-left:8px;"><?php echo ucfirst($r['status']); ?></span>
                            </div>
                            <span style="font-size:0.78rem;color:var(--text-muted);"><?php echo date('M j, Y g:i a', strtotime($r['created_at'])); ?></span>
                        </div>
                        <p style="margin:8px 0 4px;font-size:0.85rem;"><strong>Reported by:</strong> <?php echo sanitize($r['reporter_name']); ?> &nbsp;|&nbsp; <strong>Sender:</strong> <?php echo sanitize($r['sender_name']); ?></p>
                        <p style="margin:4px 0;font-size:0.85rem;"><strong>Reason:</strong> <?php echo sanitize($r['reason']); ?></p>
                        <blockquote style="margin:8px 0;padding:8px 12px;background:var(--surface);border-left:3px solid var(--border);border-radius:4px;font-size:0.85rem;"><?php echo nl2br(sanitize($r['message'])); ?></blockquote>
                        <?php if ($r['status']==='pending'): ?>
                            <div style="display:flex;gap:8px;margin-top:8px;">
                                <form method="post" style="display:inline;">
                                    <input type="hidden" name="action" value="delete_message">
                                    <input type="hidden" name="message_id" value="<?php echo $r['message_id']; ?>">
                                    <button class="button button-danger button-small" onclick="return confirm('Delete this message from the platform?')">Delete Message</button>
                                </form>
                                <form method="post" style="display:inline;">
                                    <input type="hidden" name="action" value="review_report">
                                    <input type="hidden" name="report_id" value="<?php echo $r['id']; ?>">
                                    <input type="hidden" name="report_status" value="reviewed">
                                    <button class="button button-primary button-small">Mark Reviewed</button>
                                </form>
                                <form method="post" style="display:inline;">
                                    <input type="hidden" name="action" value="review_report">
                                    <input type="hidden" name="report_id" value="<?php echo $r['id']; ?>">
                                    <input type="hidden" name="report_status" value="dismissed">
                                    <button class="button button-secondary button-small">Dismiss</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

    <!-- AUDIT LOG ──────────────────────────────────────────────────────────── -->
    <?php elseif ($tab === 'audit'): ?>
        <div class="panel">
            <h3 style="margin-top:0;">Chat Audit Log</h3>
            <?php if (empty($auditLogs)): ?>
                <div class="empty-state">No audit events yet.</div>
            <?php else: ?>
                <div class="table-wrapper">
                <table>
                    <thead><tr><th>Time</th><th>Admin</th><th>Action</th><th>Target User</th><th>Conversation</th><th>Details</th></tr></thead>
                    <tbody>
                    <?php foreach ($auditLogs as $al): ?>
                        <tr>
                            <td style="font-size:0.8rem;white-space:nowrap;"><?php echo date('M j, g:i a', strtotime($al['created_at'])); ?></td>
                            <td><?php echo $al['admin_name'] ? sanitize($al['admin_name']) : '<em>System</em>'; ?></td>
                            <td><code style="font-size:0.78rem;"><?php echo sanitize($al['action']); ?></code></td>
                            <td><?php echo $al['target_user_id'] ? '#'.(int)$al['target_user_id'] : '—'; ?></td>
                            <td><?php echo $al['conversation_id'] ? '#'.(int)$al['conversation_id'] : '—'; ?></td>
                            <td style="font-size:0.8rem;"><?php echo sanitize($al['details'] ?? ''); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>
        </div>

    <!-- SETTINGS ───────────────────────────────────────────────────────────── -->
    <?php elseif ($tab === 'settings'): ?>
        <div class="panel">
            <h3 style="margin-top:0;">Chat Platform Settings</h3>
            <form method="post">
                <input type="hidden" name="action" value="save_chat_settings">
                <label class="toggle-label">
                    <input type="checkbox" name="chat_disabled" <?php echo $chatSettings['chat_disabled']==='1'?'checked':''; ?>>
                    <span><strong>Disable all messaging</strong> — turns off chat for all regular users</span>
                </label>
                <hr style="margin:12px 0;border-color:var(--border);">
                <p style="font-size:0.85rem;font-weight:600;margin-bottom:8px;">Who can start conversations:</p>
                <label class="toggle-label">
                    <input type="checkbox" name="chat_allow_applicant_chat" <?php echo $chatSettings['chat_allow_applicant_chat']==='1'?'checked':''; ?>>
                    <span>Customer ↔ Worker who has <strong>applied</strong> to their job</span>
                </label>
                <label class="toggle-label">
                    <input type="checkbox" name="chat_allow_hired_chat" <?php echo $chatSettings['chat_allow_hired_chat']==='1'?'checked':''; ?>>
                    <span>Customer ↔ Worker they have <strong>hired</strong></span>
                </label>
                <label class="toggle-label">
                    <input type="checkbox" name="chat_allow_worker_worker" <?php echo $chatSettings['chat_allow_worker_worker']==='1'?'checked':''; ?>>
                    <span>Worker ↔ Worker (any two workers)</span>
                </label>
                <label class="toggle-label">
                    <input type="checkbox" name="chat_allow_direct_all" <?php echo $chatSettings['chat_allow_direct_all']==='1'?'checked':''; ?>>
                    <span>Open direct messaging — <strong>any user</strong> can message any other</span>
                </label>
                <button type="submit" class="button button-primary" style="margin-top:14px;">Save Settings</button>
            </form>
        </div>
    <?php endif; ?>
</main>

<script>
function openRestrict(id, name, canSend, canRecv, banUntil) {
    document.getElementById('restrictUserId').value = id;
    document.getElementById('restrictTitle').textContent = 'Restrict: ' + name;
    document.getElementById('rCanSend').checked = canSend == 1;
    document.getElementById('rCanRecv').checked = canRecv == 1;
    document.getElementById('rBanUntil').value = banUntil ? banUntil.slice(0,10) : '';
    document.getElementById('restrictModal').style.display = 'flex';
}
function closeRestrict() { document.getElementById('restrictModal').style.display = 'none'; }
document.getElementById('restrictModal')?.addEventListener('click', function(e) { if (e.target===this) closeRestrict(); });
</script>
</body>
</html>
