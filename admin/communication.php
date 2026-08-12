<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../chat_functions.php';

require_login();
$user = current_user();
if (!in_array($user['role'], ['admin','manager'], true)) {
    header('Location: ../jobs.php'); exit;
}
require_mod_permission('manage_communication');

$tab = $_GET['tab'] ?? 'dashboard';
$msg = '';

// ── POST actions ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
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

    if ($act === 'send_notification_admin') {
        $tid      = intval($_POST['target_user_id'] ?? 0);
        $catKey   = trim($_POST['category'] ?? '');
        $title    = trim($_POST['notif_title'] ?? '');
        $body     = trim($_POST['notif_body']  ?? '');
        $ntype    = $_POST['notif_type'] ?? 'info';
        $alsoMail = isset($_POST['notif_email']);
        if (!in_array($ntype, ['info','success','warning','error'], true)) $ntype = 'info';

        if (!$title || !$body) {
            $msg = 'Title and message are required.';
        } elseif ($catKey && array_key_exists($catKey, broadcast_categories())) {
            $result = send_bulk_notification($catKey, $title, $body, $ntype, $user['id'], $alsoMail);
            $catLabel = broadcast_categories()[$catKey];
            log_audit_action($user['id'], 'notification_broadcast',
                "Sent notification to category \"$catLabel\" ({$result['sent']} user(s)" . ($alsoMail ? ", {$result['emailed']} emailed" : '') . "): \"$title\"");
            $msg = "Notification sent to {$result['sent']} user(s)" . ($alsoMail ? " ({$result['emailed']} emailed)." : '.');
        } elseif ($tid) {
            notify_user($tid, $title, $body, $ntype, null, $user['id']);
            if ($alsoMail) {
                $emStmt = $pdo->prepare("SELECT email FROM users WHERE id=?");
                $emStmt->execute([$tid]);
                if ($em = $emStmt->fetchColumn()) send_email_notification($em, $title, render_rich($body), $tid);
            }
            log_audit_action($user['id'], 'notification_sent', "Sent notification to user #$tid: \"$title\"");
            $msg = 'Notification sent.';
        } else {
            $msg = 'Choose a recipient or category first.';
        }
        $tab = 'notifications';
    }

    if ($act === 'update_notification') {
        $nid   = intval($_POST['notification_id'] ?? 0);
        $title = trim($_POST['notif_title'] ?? '');
        $body  = trim($_POST['notif_body']  ?? '');
        $ntype = $_POST['notif_type'] ?? 'info';
        if (!in_array($ntype, ['info','success','warning','error'], true)) $ntype = 'info';
        if ($nid && $title && $body) {
            $pdo->prepare("UPDATE notifications SET title=?, body=?, type=? WHERE id=? AND sent_by_admin_id IS NOT NULL")
                ->execute([$title, $body, $ntype, $nid]);
            log_audit_action($user['id'], 'notification_updated', "Edited notification #$nid");
            $msg = 'Notification updated.';
        } else {
            $msg = 'Title and message are required.';
        }
        $tab = 'notifications';
    }

    if ($act === 'delete_notification') {
        $nid = intval($_POST['notification_id'] ?? 0);
        $pdo->prepare("DELETE FROM notifications WHERE id=? AND sent_by_admin_id IS NOT NULL")->execute([$nid]);
        log_audit_action($user['id'], 'notification_deleted', "Deleted notification #$nid");
        $msg = 'Notification deleted.';
        $tab = 'notifications';
    }

    if ($act === 'grant_chat') {
        $uid1 = intval($_POST['user1_id'] ?? 0);
        $uid2 = intval($_POST['user2_id'] ?? 0);
        if ($uid1 && $uid2 && $uid1 !== $uid2) {
            // Find existing conversation between uid1 and uid2 (not admin)
            $existing = $pdo->prepare("
                SELECT c.id FROM conversations c
                JOIN conversation_participants cp1 ON c.id = cp1.conversation_id AND cp1.user_id = ?
                JOIN conversation_participants cp2 ON c.id = cp2.conversation_id AND cp2.user_id = ?
                WHERE c.status != 'closed' LIMIT 1
            ");
            $existing->execute([$uid1, $uid2]);
            if ($row = $existing->fetch()) {
                $convId = (int)$row['id'];
                $pdo->prepare("UPDATE conversations SET conversation_type='admin_granted', status='active' WHERE id=?")->execute([$convId]);
            } else {
                $pdo->prepare("INSERT INTO conversations (conversation_type, job_id, created_by, status, created_at) VALUES ('admin_granted', NULL, ?, 'active', NOW())")
                    ->execute([$user['id']]);
                $convId = (int)$pdo->lastInsertId();
                $pdo->prepare("INSERT INTO conversation_participants (conversation_id, user_id, joined_at) VALUES (?,?,NOW()),(?,?,NOW())")
                    ->execute([$convId, $uid1, $convId, $uid2]);
            }
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

// ── Shared pagination (page param means different things per tab, but only one tab renders at a time) ──
$commPage    = max(1, (int)($_GET['page'] ?? 1));
$commPerPage = 30;
$commOffset  = ($commPage - 1) * $commPerPage;
$commTotal      = 0;
$commTotalPages = 1;

function comm_qstr(array $overrides = []): string {
    $base = [];
    foreach (['tab', 'page'] as $k) {
        if (isset($_GET[$k]) && $_GET[$k] !== '') $base[$k] = $_GET[$k];
    }
    $merged = array_filter(array_merge($base, $overrides), fn($v) => $v !== null);
    return 'communication.php?' . http_build_query($merged);
}

function comm_render_pagination(int $page, int $totalPages, int $total): void {
    if ($totalPages <= 1) return;
    echo '<div class="pagination">';
    if ($page > 1) echo '<a href="' . sanitize(comm_qstr(['page' => $page - 1])) . '">‹ Prev</a>';
    $pStart = max(1, $page - 3);
    $pEnd   = min($totalPages, $page + 3);
    if ($pStart > 1) echo '<span>…</span>';
    for ($p = $pStart; $p <= $pEnd; $p++) {
        echo $p === $page
            ? '<span class="current">' . $p . '</span>'
            : '<a href="' . sanitize(comm_qstr(['page' => $p])) . '">' . $p . '</a>';
    }
    if ($pEnd < $totalPages) echo '<span>…</span>';
    if ($page < $totalPages) echo '<a href="' . sanitize(comm_qstr(['page' => $page + 1])) . '">Next ›</a>';
    echo '<span style="color:var(--text-muted,#6b7280);border:none;padding-left:4px;">Page ' . $page . ' of ' . $totalPages . ' (' . $total . ' total)</span>';
    echo '</div>';
}

// Conversations list
$conversations = [];
if ($tab === 'conversations' || $tab === 'dashboard') {
    $commTotal      = (int)$pdo->query("SELECT COUNT(*) FROM conversations")->fetchColumn();
    $commTotalPages = max(1, (int)ceil($commTotal / $commPerPage));
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
        LIMIT $commPerPage OFFSET $commOffset
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
    $commTotal      = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role NOT IN ('admin')")->fetchColumn();
    $commTotalPages = max(1, (int)ceil($commTotal / $commPerPage));
    $uStmt = $pdo->query("
        SELECT id, name, email, role, can_send_messages, can_receive_messages, chat_ban_until
        FROM users WHERE role NOT IN ('admin') ORDER BY name ASC LIMIT $commPerPage OFFSET $commOffset
    ");
    $chatUsers = $uStmt->fetchAll();
}

// Reports
$reports = [];
if ($tab === 'reports') {
    $commTotal      = (int)$pdo->query("SELECT COUNT(*) FROM message_reports")->fetchColumn();
    $commTotalPages = max(1, (int)ceil($commTotal / $commPerPage));
    $rStmt = $pdo->query("
        SELECT mr.*, cm.message, cm.sender_id, cm.conversation_id,
               u_rep.name AS reporter_name, u_sender.name AS sender_name
        FROM message_reports mr
        JOIN chat_messages cm ON mr.message_id = cm.id
        JOIN users u_rep ON mr.reported_by = u_rep.id
        JOIN users u_sender ON cm.sender_id = u_sender.id
        ORDER BY mr.status ASC, mr.created_at DESC
        LIMIT $commPerPage OFFSET $commOffset
    ");
    $reports = $rStmt->fetchAll();
}

// Audit log
$auditLogs = [];
if ($tab === 'audit') {
    $commTotal      = (int)$pdo->query("SELECT COUNT(*) FROM chat_audit_logs")->fetchColumn();
    $commTotalPages = max(1, (int)ceil($commTotal / $commPerPage));
    $aStmt = $pdo->query("
        SELECT cal.*, u.name AS admin_name FROM chat_audit_logs cal
        LEFT JOIN users u ON cal.admin_id = u.id
        ORDER BY cal.created_at DESC LIMIT $commPerPage OFFSET $commOffset
    ");
    $auditLogs = $aStmt->fetchAll();
}

// Admin-sent notifications (CRUD list) — system-triggered notifications
// (payments, approvals, etc.) are intentionally excluded; this tab only
// manages messages an admin composed and sent themselves.
$sentNotifications = [];
if ($tab === 'notifications') {
    $commTotal      = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE sent_by_admin_id IS NOT NULL")->fetchColumn();
    $commTotalPages = max(1, (int)ceil($commTotal / $commPerPage));
    $nStmt = $pdo->query("
        SELECT n.*, ur.name AS recipient_name, ur.email AS recipient_email, ua.name AS admin_name
        FROM notifications n
        JOIN users ur ON n.user_id = ur.id
        LEFT JOIN users ua ON n.sent_by_admin_id = ua.id
        WHERE n.sent_by_admin_id IS NOT NULL
        ORDER BY n.created_at DESC
        LIMIT $commPerPage OFFSET $commOffset
    ");
    $sentNotifications = $nStmt->fetchAll();
}

// Notification being edited (server-rendered pre-fill — avoids needing JS
// to inject HTML into the hidden textarea behind the rich editor)
$editNotif = null;
if ($tab === 'notifications' && !empty($_GET['edit'])) {
    $eStmt = $pdo->prepare("
        SELECT n.*, ur.name AS recipient_name FROM notifications n
        JOIN users ur ON n.user_id = ur.id
        WHERE n.id=? AND n.sent_by_admin_id IS NOT NULL
    ");
    $eStmt->execute([(int)$_GET['edit']]);
    $editNotif = $eStmt->fetch() ?: null;
}

// Platform users for grant chat
$allUsers = $pdo->query("SELECT id, name, role FROM users WHERE role NOT IN ('admin') ORDER BY name ASC")->fetchAll();

// Recipient search for the notifications compose form — a plain <select>
// doesn't scale to a userbase in the thousands, so recipients are found by
// name/email/username search instead, same pattern as complimentary_members.php.
$notifQ = '';
$notifSearchResults = [];
$toUser = null;
if ($tab === 'notifications' && !$editNotif) {
    $notifQ = trim($_GET['q'] ?? '');
    if ($notifQ !== '') {
        $nsStmt = $pdo->prepare(
            "SELECT id, name, email, role FROM users
             WHERE (name LIKE ? OR email LIKE ? OR username LIKE ?) AND role != 'admin'
             ORDER BY name LIMIT 20"
        );
        $like = '%' . $notifQ . '%';
        $nsStmt->execute([$like, $like, $like]);
        $notifSearchResults = $nsStmt->fetchAll();
    }
    $toId = (int)($_GET['to'] ?? 0);
    if ($toId) {
        $tuStmt = $pdo->prepare("SELECT id, name, email, role FROM users WHERE id=? AND role != 'admin'");
        $tuStmt->execute([$toId]);
        $toUser = $tuStmt->fetch() ?: null;
    }
}

// Broadcast category — the other way to pick a "recipient" (a whole segment
// instead of one user). Counts are computed for the picker dropdown too.
$category      = null;
$categoryLabel = null;
$categoryCount = 0;
if ($tab === 'notifications' && !$editNotif && !$toUser) {
    $catKey = trim($_GET['category'] ?? '');
    if ($catKey && array_key_exists($catKey, broadcast_categories())) {
        $category      = $catKey;
        $categoryLabel = broadcast_categories()[$catKey];
        $categoryCount = count(broadcast_recipient_ids($catKey));
    }
}
$categoryCounts = [];
if ($tab === 'notifications' && !$editNotif && !$toUser && !$category) {
    foreach (array_keys(broadcast_categories()) as $ck) {
        $categoryCounts[$ck] = count(broadcast_recipient_ids($ck));
    }
}

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
    <title>Communication Centre — AkuapemConnect Admin</title>
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
        .pagination { display:flex; gap:4px; flex-wrap:wrap; align-items:center; margin-top:14px; }
        .pagination a, .pagination span { padding:5px 10px; border-radius:6px; border:1px solid var(--border); text-decoration:none; font-size:.82rem; color:var(--text); }
        .pagination a:hover { background:var(--surface-muted,#f9fafb); }
        .pagination .current { background:var(--primary,#0f766e); color:#fff; border-color:var(--primary,#0f766e); }
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
        <?php foreach (['dashboard'=>'Dashboard','conversations'=>'Conversations','users'=>'Users','notifications'=>'Notifications','reports'=>'Reports','audit'=>'Audit Log','settings'=>'Settings'] as $k=>$label): ?>
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
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="block_conversation">
                            <input type="hidden" name="conversation_id" value="<?php echo $viewConvId; ?>">
                            <button class="button button-danger button-small" onclick="return confirm('Block this conversation?')">Block</button>
                        </form>
                    <?php endif; ?>
                    <?php if ($viewConv['status'] !== 'closed'): ?>
                        <form method="post" style="display:inline;">
                            <?php echo csrf_field(); ?>
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
                                    <?php echo csrf_field(); ?>
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
                                            <?php echo csrf_field(); ?>
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
                    <?php comm_render_pagination($commPage, $commTotalPages, $commTotal); ?>
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
                <?php echo csrf_field(); ?>
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
            <?php comm_render_pagination($commPage, $commTotalPages, $commTotal); ?>
            </div>
        </div>

        <!-- Restrict modal -->
        <div id="restrictModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:999;align-items:center;justify-content:center;">
            <div style="background:var(--bg);border-radius:12px;padding:24px;width:360px;max-width:94vw;">
                <h3 style="margin:0 0 16px;" id="restrictTitle">Restrict User</h3>
                <form method="post">
                    <?php echo csrf_field(); ?>
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

    <!-- NOTIFICATIONS ──────────────────────────────────────────────────────── -->
    <?php elseif ($tab === 'notifications'): ?>

        <?php if (!$editNotif && !$toUser && !$category): ?>
        <!-- Step 1: find a recipient — a plain dropdown doesn't scale to thousands of users -->
        <div class="panel" style="margin-bottom:20px;">
            <h3 style="margin-top:0;">Send Notification</h3>
            <form method="get" action="communication.php" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px;">
                <input type="hidden" name="tab" value="notifications">
                <input type="text" name="q" value="<?php echo sanitize($notifQ); ?>" placeholder="Search recipient by name, email, or username…" style="flex:1;min-width:220px;padding:7px 10px;border:1px solid var(--border);border-radius:8px;font-size:.82rem;" autofocus>
                <button type="submit" class="button button-secondary button-small">Search</button>
            </form>
            <?php if ($notifQ !== ''): ?>
                <?php if (!$notifSearchResults): ?>
                <div class="empty-state">No matching users.</div>
                <?php else: ?>
                <div class="table-wrapper">
                <table>
                    <thead><tr><th>Name</th><th>Email</th><th>Role</th><th style="text-align:right;">Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($notifSearchResults as $r): ?>
                    <tr>
                        <td><?php echo sanitize($r['name']); ?></td>
                        <td><?php echo sanitize($r['email']); ?></td>
                        <td><?php echo ucfirst($r['role']); ?></td>
                        <td style="text-align:right;">
                            <a href="communication.php?tab=notifications&to=<?php echo (int)$r['id']; ?>" class="button button-primary button-small">Compose →</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>
            <?php endif; ?>

            <hr style="margin:16px 0;border-color:var(--border);">
            <p style="font-size:.85rem;font-weight:600;margin-bottom:8px;">Or broadcast to a category:</p>
            <form method="get" action="communication.php" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
                <input type="hidden" name="tab" value="notifications">
                <div>
                    <label style="font-size:.82rem;font-weight:600;">Category</label><br>
                    <select name="category" style="padding:7px 10px;border:1px solid var(--border);border-radius:8px;font-size:.82rem;">
                        <?php foreach (broadcast_categories() as $ck => $cl): ?>
                        <option value="<?php echo $ck; ?>"><?php echo sanitize($cl); ?> (<?php echo $categoryCounts[$ck] ?? 0; ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="button button-primary button-small">Compose →</button>
            </form>
        </div>

        <?php else: ?>
        <!-- Step 2: compose/edit, recipient already resolved -->
        <div class="panel" style="margin-bottom:20px;">
            <h3 style="margin-top:0;"><?php echo $editNotif ? 'Edit Notification' : 'Send Notification'; ?></h3>
            <p style="font-size:.85rem;color:var(--text-muted);margin-top:-8px;">
                <?php if ($editNotif): ?>
                To: <strong><?php echo sanitize($editNotif['recipient_name']); ?></strong> — recipient can't be changed; <a href="communication.php?tab=notifications">cancel edit</a>
                <?php elseif ($category): ?>
                To: <strong><?php echo sanitize($categoryLabel); ?></strong> (<?php echo $categoryCount; ?> user<?php echo $categoryCount===1?'':'s'; ?>) — <a href="communication.php?tab=notifications">change recipient</a>
                <?php else: ?>
                To: <strong><?php echo sanitize($toUser['name']); ?></strong> — <a href="communication.php?tab=notifications&q=<?php echo urlencode($notifQ); ?>">change recipient</a>
                <?php endif; ?>
            </p>
            <form method="post">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="<?php echo $editNotif ? 'update_notification' : 'send_notification_admin'; ?>">
                <?php if ($editNotif): ?>
                <input type="hidden" name="notification_id" value="<?php echo (int)$editNotif['id']; ?>">
                <?php elseif ($category): ?>
                <input type="hidden" name="category" value="<?php echo sanitize($category); ?>">
                <?php else: ?>
                <input type="hidden" name="target_user_id" value="<?php echo (int)$toUser['id']; ?>">
                <?php endif; ?>
                <div class="form-row">
                    <div>
                        <label>Title</label><br>
                        <input type="text" name="notif_title" required maxlength="150" value="<?php echo sanitize($editNotif['title'] ?? ''); ?>" placeholder="e.g. Account update">
                    </div>
                    <div>
                        <label>Type</label><br>
                        <select name="notif_type">
                            <?php foreach (['info'=>'Info','success'=>'Success','warning'=>'Warning','error'=>'Error'] as $tk=>$tl): ?>
                            <option value="<?php echo $tk; ?>" <?php echo ($editNotif['type'] ?? 'info')===$tk?'selected':''; ?>><?php echo $tl; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group" style="margin-bottom:12px;">
                    <label>Message</label>
                    <textarea name="notif_body" class="rich-editor" rows="4" required placeholder="Message shown in the recipient's notifications"><?php echo $editNotif['body'] ?? ''; ?></textarea>
                </div>
                <?php if (!$editNotif): ?>
                <div class="form-group" style="margin-bottom:12px;">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:600;font-size:.85rem;">
                        <input type="checkbox" name="notif_email" value="1">
                        Also send by email
                        <?php if ($toUser): ?> (<?php echo sanitize($toUser['email']); ?>)
                        <?php elseif ($category): ?> to all <?php echo $categoryCount; ?> matched user(s) — may take a moment for large groups
                        <?php endif; ?>
                    </label>
                </div>
                <?php endif; ?>
                <button type="submit" class="button button-primary button-small">📨 <?php echo $editNotif ? 'Save Changes' : 'Send Notification'; ?></button>
                <?php if ($editNotif): ?>
                <a href="communication.php?tab=notifications" class="button button-secondary button-small">Cancel</a>
                <?php endif; ?>
            </form>
        </div>
        <?php endif; ?>

        <div class="panel">
            <h3 style="margin-top:0;">Sent Notifications (<?php echo $commTotal; ?>)</h3>
            <?php if (empty($sentNotifications)): ?>
                <div class="empty-state">No notifications sent by admins yet.</div>
            <?php else: ?>
                <div class="table-wrapper">
                <table>
                    <thead><tr><th>Sent</th><th>Recipient</th><th>Title</th><th>Type</th><th>Read?</th><th>Sent By</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($sentNotifications as $n): ?>
                        <tr>
                            <td style="font-size:0.8rem;white-space:nowrap;"><?php echo date('M j, g:i a', strtotime($n['created_at'])); ?></td>
                            <td><?php echo sanitize($n['recipient_name']); ?><br><span class="meta"><?php echo sanitize($n['recipient_email']); ?></span></td>
                            <td><?php echo sanitize($n['title']); ?></td>
                            <td><span class="badge-<?php echo $n['type']==='error'?'red':($n['type']==='success'?'green':'amber'); ?>"><?php echo ucfirst($n['type']); ?></span></td>
                            <td><?php echo $n['is_read'] ? '<span class="badge-green">Read</span>' : '<span class="badge-amber">Unread</span>'; ?></td>
                            <td><?php echo $n['admin_name'] ? sanitize($n['admin_name']) : '<em>—</em>'; ?></td>
                            <td style="white-space:nowrap;">
                                <a href="communication.php?tab=notifications&edit=<?php echo (int)$n['id']; ?>" class="button button-secondary button-small">Edit</a>
                                <form method="post" style="display:inline;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="delete_notification">
                                    <input type="hidden" name="notification_id" value="<?php echo (int)$n['id']; ?>">
                                    <button class="button button-danger button-small" onclick="return confirm('Delete this notification? The recipient will no longer see it.')">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php comm_render_pagination($commPage, $commTotalPages, $commTotal); ?>
                </div>
            <?php endif; ?>
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
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="delete_message">
                                    <input type="hidden" name="message_id" value="<?php echo $r['message_id']; ?>">
                                    <button class="button button-danger button-small" onclick="return confirm('Delete this message from the platform?')">Delete Message</button>
                                </form>
                                <form method="post" style="display:inline;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="review_report">
                                    <input type="hidden" name="report_id" value="<?php echo $r['id']; ?>">
                                    <input type="hidden" name="report_status" value="reviewed">
                                    <button class="button button-primary button-small">Mark Reviewed</button>
                                </form>
                                <form method="post" style="display:inline;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="review_report">
                                    <input type="hidden" name="report_id" value="<?php echo $r['id']; ?>">
                                    <input type="hidden" name="report_status" value="dismissed">
                                    <button class="button button-secondary button-small">Dismiss</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <?php comm_render_pagination($commPage, $commTotalPages, $commTotal); ?>
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
                <?php comm_render_pagination($commPage, $commTotalPages, $commTotal); ?>
                </div>
            <?php endif; ?>
        </div>

    <!-- SETTINGS ───────────────────────────────────────────────────────────── -->
    <?php elseif ($tab === 'settings'): ?>
        <div class="panel">
            <h3 style="margin-top:0;">Chat Platform Settings</h3>
            <form method="post">
                <?php echo csrf_field(); ?>
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
<script src="../assets/js/rich-editor.js" defer></script>
</body>
</html>
