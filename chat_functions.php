<?php
/**
 * Chat system helper functions.
 * Requires db.php + functions.php to be included first.
 */

// ── Platform-level permission helpers ────────────────────────────────────────

function chat_is_disabled(): bool {
    return get_platform_setting('chat_disabled', '0') === '1';
}

function chat_allow_applicant(): bool {
    return get_platform_setting('chat_allow_applicant_chat', '1') === '1';
}

function chat_allow_hired(): bool {
    return get_platform_setting('chat_allow_hired_chat', '1') === '1';
}

function chat_allow_worker_worker(): bool {
    return get_platform_setting('chat_allow_worker_worker', '0') === '1';
}

function chat_allow_direct_all(): bool {
    return get_platform_setting('chat_allow_direct_all', '0') === '1';
}

// ── Per-user chat status ──────────────────────────────────────────────────────

function get_user_chat_status(int $userId): array {
    global $pdo;
    $stmt = $pdo->prepare("SELECT can_send_messages, can_receive_messages, chat_ban_until FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $u = $stmt->fetch();
    if (!$u) return ['can_send' => false, 'can_receive' => false, 'banned' => true, 'ban_until' => null];
    $banned = !empty($u['chat_ban_until']) && strtotime($u['chat_ban_until']) > time();
    return [
        'can_send'    => (bool)$u['can_send_messages'] && !$banned,
        'can_receive' => (bool)$u['can_receive_messages'] && !$banned,
        'banned'      => $banned,
        'ban_until'   => $u['chat_ban_until'],
    ];
}

// ── Permission check ──────────────────────────────────────────────────────────
// Returns ['allowed'=>bool, 'type'=>string, 'job_id'=>int|null, 'reason'=>string]

function can_chat_with(int $myId, int $theirId, ?int $jobId = null): array {
    global $pdo;

    if ($myId === $theirId) {
        return ['allowed' => false, 'reason' => 'Cannot message yourself.'];
    }

    if (chat_is_disabled()) {
        return ['allowed' => false, 'reason' => 'Messaging is currently disabled on this platform.'];
    }

    $myStatus = get_user_chat_status($myId);
    if (!$myStatus['can_send']) {
        $msg = $myStatus['banned']
            ? 'Your messaging is suspended until ' . date('M j, Y', strtotime($myStatus['ban_until'])) . '.'
            : 'Your messaging privileges have been disabled.';
        return ['allowed' => false, 'reason' => $msg];
    }

    $theirStatus = get_user_chat_status($theirId);
    if (!$theirStatus['can_receive']) {
        return ['allowed' => false, 'reason' => 'This user cannot receive messages at this time.'];
    }

    // Get both roles
    $rolesStmt = $pdo->prepare("SELECT id, role FROM users WHERE id IN (?, ?)");
    $rolesStmt->execute([$myId, $theirId]);
    $roles = [];
    foreach ($rolesStmt->fetchAll() as $r) $roles[$r['id']] = $r['role'];
    $myRole    = $roles[$myId] ?? 'customer';
    $theirRole = $roles[$theirId] ?? 'customer';

    // Admins and managers can always initiate chat
    if (in_array($myRole, ['admin', 'manager'], true)) {
        return ['allowed' => true, 'type' => 'admin_granted', 'job_id' => null, 'reason' => ''];
    }

    // Check existing admin-granted conversation
    $stmt = $pdo->prepare("
        SELECT c.id FROM conversations c
        JOIN conversation_participants cp1 ON c.id = cp1.conversation_id AND cp1.user_id = ?
        JOIN conversation_participants cp2 ON c.id = cp2.conversation_id AND cp2.user_id = ?
        WHERE c.conversation_type = 'admin_granted' AND c.status = 'active' LIMIT 1
    ");
    $stmt->execute([$myId, $theirId]);
    if ($stmt->fetch()) {
        return ['allowed' => true, 'type' => 'admin_granted', 'job_id' => null, 'reason' => ''];
    }

    // Direct all
    if (chat_allow_direct_all()) {
        return ['allowed' => true, 'type' => 'direct', 'job_id' => null, 'reason' => ''];
    }

    // Worker ↔ worker
    if (chat_allow_worker_worker() && $myRole === 'worker' && $theirRole === 'worker') {
        return ['allowed' => true, 'type' => 'worker_direct', 'job_id' => null, 'reason' => ''];
    }

    // Job-based permissions
    if ($jobId) {
        $result = _check_job_chat_permission($myId, $theirId, $jobId);
        if ($result['allowed']) return $result;
    }

    // No specific job: scan all jobs connecting these two users
    if (chat_allow_hired()) {
        $stmt = $pdo->prepare("
            SELECT id FROM service_requests
            WHERE (customer_id = ? AND assigned_worker_id = ?)
               OR (customer_id = ? AND assigned_worker_id = ?) LIMIT 1
        ");
        $stmt->execute([$myId, $theirId, $theirId, $myId]);
        if ($row = $stmt->fetch()) {
            return ['allowed' => true, 'type' => 'job_hired', 'job_id' => (int)$row['id'], 'reason' => ''];
        }
    }

    if (chat_allow_applicant()) {
        $stmt = $pdo->prepare("
            SELECT sr.id FROM service_requests sr
            JOIN applications a ON sr.id = a.request_id
            WHERE (sr.customer_id = ? AND a.worker_id = ?)
               OR (sr.customer_id = ? AND a.worker_id = ?) LIMIT 1
        ");
        $stmt->execute([$myId, $theirId, $theirId, $myId]);
        if ($row = $stmt->fetch()) {
            return ['allowed' => true, 'type' => 'job_application', 'job_id' => (int)$row['id'], 'reason' => ''];
        }
    }

    return [
        'allowed' => false,
        'reason'  => 'You can only message users you\'re connected with through a job application or hire.',
    ];
}

function _check_job_chat_permission(int $myId, int $theirId, int $jobId): array {
    global $pdo;
    $stmt = $pdo->prepare("SELECT id, customer_id, assigned_worker_id FROM service_requests WHERE id = ?");
    $stmt->execute([$jobId]);
    $job = $stmt->fetch();
    if (!$job) return ['allowed' => false, 'reason' => 'Job not found.'];

    if (chat_allow_hired() && $job['assigned_worker_id']) {
        $hired = [(int)$job['customer_id'], (int)$job['assigned_worker_id']];
        if (in_array($myId, $hired) && in_array($theirId, $hired)) {
            return ['allowed' => true, 'type' => 'job_hired', 'job_id' => $jobId, 'reason' => ''];
        }
    }

    if (chat_allow_applicant() && ($job['customer_id'] == $myId || $job['customer_id'] == $theirId)) {
        $workerId = ($job['customer_id'] == $myId) ? $theirId : $myId;
        $appStmt = $pdo->prepare("SELECT id FROM applications WHERE request_id = ? AND worker_id = ? LIMIT 1");
        $appStmt->execute([$jobId, $workerId]);
        if ($appStmt->fetch()) {
            return ['allowed' => true, 'type' => 'job_application', 'job_id' => $jobId, 'reason' => ''];
        }
    }

    return ['allowed' => false, 'reason' => 'No qualifying job relationship for this conversation.'];
}

// ── Conversation management ───────────────────────────────────────────────────

function get_or_create_conversation(int $myId, int $theirId, string $type, ?int $jobId): int {
    global $pdo;
    $sql = "
        SELECT c.id FROM conversations c
        JOIN conversation_participants cp1 ON c.id = cp1.conversation_id AND cp1.user_id = ?
        JOIN conversation_participants cp2 ON c.id = cp2.conversation_id AND cp2.user_id = ?
        WHERE c.status != 'closed'
    ";
    $params = [$myId, $theirId];
    if ($jobId) { $sql .= " AND c.job_id = ?"; $params[] = $jobId; }
    $sql .= " LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    if ($row = $stmt->fetch()) return (int)$row['id'];

    $pdo->prepare("INSERT INTO conversations (conversation_type, job_id, created_by, status, created_at) VALUES (?,?,?,'active',NOW())")
        ->execute([$type, $jobId, $myId]);
    $convId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO conversation_participants (conversation_id, user_id, joined_at) VALUES (?,?,NOW()),(?,?,NOW())")
        ->execute([$convId, $myId, $convId, $theirId]);
    return $convId;
}

function can_access_conversation(int $convId, int $userId): bool {
    global $pdo;
    $stmt = $pdo->prepare("SELECT id FROM conversation_participants WHERE conversation_id = ? AND user_id = ?");
    $stmt->execute([$convId, $userId]);
    return (bool)$stmt->fetch();
}

function get_other_participant(int $convId, int $myId): ?array {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT u.id, u.name, u.username, u.role, u.profile_photo
        FROM conversation_participants cp JOIN users u ON cp.user_id = u.id
        WHERE cp.conversation_id = ? AND cp.user_id != ? LIMIT 1
    ");
    $stmt->execute([$convId, $myId]);
    return $stmt->fetch() ?: null;
}

function get_user_conversations(int $userId, int $limit = 40): array {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT c.id, c.conversation_type, c.job_id, c.status, c.created_at,
               u2.id AS other_id, u2.name AS other_name, u2.username AS other_username,
               u2.role AS other_role, u2.profile_photo AS other_photo,
               (SELECT cm2.message FROM chat_messages cm2
                WHERE cm2.conversation_id = c.id ORDER BY cm2.id DESC LIMIT 1) AS last_message,
               (SELECT cm2.message_type FROM chat_messages cm2
                WHERE cm2.conversation_id = c.id ORDER BY cm2.id DESC LIMIT 1) AS last_message_type,
               (SELECT cm2.created_at FROM chat_messages cm2
                WHERE cm2.conversation_id = c.id ORDER BY cm2.id DESC LIMIT 1) AS last_at,
               (SELECT COUNT(*) FROM chat_messages cm3
                WHERE cm3.conversation_id = c.id AND cm3.sender_id != ? AND cm3.is_read = 0
                  AND cm3.deleted_by_receiver = 0) AS unread_count
        FROM conversations c
        JOIN conversation_participants cp  ON c.id = cp.conversation_id  AND cp.user_id = ?
        JOIN conversation_participants cp2 ON c.id = cp2.conversation_id AND cp2.user_id != ?
        JOIN users u2 ON cp2.user_id = u2.id
        ORDER BY last_at DESC, c.created_at DESC
        LIMIT ?
    ");
    $stmt->bindValue(1, $userId, PDO::PARAM_INT);
    $stmt->bindValue(2, $userId, PDO::PARAM_INT);
    $stmt->bindValue(3, $userId, PDO::PARAM_INT);
    $stmt->bindValue(4, $limit,  PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function get_conversation_messages(int $convId, int $userId, int $limit = 50, int $afterId = 0): array {
    global $pdo;
    $sql  = "SELECT cm.*, u.name AS sender_name, u.username AS sender_username
             FROM chat_messages cm JOIN users u ON cm.sender_id = u.id
             WHERE cm.conversation_id = ?
               AND NOT (cm.deleted_by_sender = 1 AND cm.sender_id = ?)
               AND NOT (cm.deleted_by_receiver = 1 AND cm.sender_id != ?)";
    $params = [$convId, $userId, $userId];
    if ($afterId) { $sql .= " AND cm.id > ?"; $params[] = $afterId; }
    $sql .= " ORDER BY cm.created_at ASC, cm.id ASC LIMIT ?";
    $params[] = $limit;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function get_total_unread_chat_count(int $userId): int {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM chat_messages cm
        JOIN conversation_participants cp ON cm.conversation_id = cp.conversation_id AND cp.user_id = ?
        WHERE cm.sender_id != ? AND cm.is_read = 0 AND cm.deleted_by_receiver = 0
    ");
    $stmt->execute([$userId, $userId]);
    return (int)$stmt->fetchColumn();
}

// ── Content moderation ────────────────────────────────────────────────────────

function detect_suspicious_content(string $text): array {
    $flags = [];
    // Phone numbers (Ghanaian 0XX / +233XX or generic 9+ digits)
    if (preg_match_all('/(\+?233|0)[2357]\d{7,8}/', $text) >= 1
        || preg_match_all('/\b\d[\d\s\-\.]{7,14}\d\b/', $text) >= 2) {
        $flags[] = 'phone_number';
    }
    // URLs
    if (preg_match_all('~(https?://|www\.)\S+~i', $text) >= 1) {
        $flags[] = 'url';
    }
    // Repeated-word spam
    if (preg_match('/(\b\w{3,}\b)(?:\s+\1){3,}/i', $text)) {
        $flags[] = 'spam';
    }
    return $flags;
}

// ── Audit logging ─────────────────────────────────────────────────────────────

function log_chat_audit(int $adminId, string $action, ?int $targetUserId, ?int $conversationId, string $details): void {
    global $pdo;
    $pdo->prepare("INSERT INTO chat_audit_logs (admin_id, action, target_user_id, conversation_id, details, created_at) VALUES (?,?,?,?,?,NOW())")
        ->execute([$adminId ?: null, $action, $targetUserId, $conversationId, $details]);
}
