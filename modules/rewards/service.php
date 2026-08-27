<?php
/**
 * modules/rewards/service.php
 * Milestone Reward Claim System — service layer.
 *
 * Sits ON TOP of the existing Referrals & Points module
 * (modules/referrals/service.php) — it never touches award_points()'s
 * earning config/one-time/cap logic, and it never introduces a second points
 * balance. A user's spendable balance is, and remains, exactly
 * points_wallets.balance (see get_points_balance()).
 *
 * "Locking" points for a pending claim is a real, immediate debit recorded
 * in points_transactions (event='reward_claim_lock') and reflected in
 * points_wallets.balance — so the existing balance already IS the
 * "available" balance everywhere else in the app reads it. "Locked points"
 * for display purposes is derived on demand from reward_claims (never
 * stored redundantly) — see get_user_locked_points().
 *
 * Include AFTER auth.php + functions.php + modules/referrals/service.php.
 */
if (defined('REWARDS_MODULE_LOADED')) return;
define('REWARDS_MODULE_LOADED', true);

// ── Enums / labels ───────────────────────────────────────────────────────────

function rewards_enabled(): bool {
    return (int)get_platform_setting('rewards_enabled', 1) === 1;
}

function reward_type_labels(): array {
    return [
        'cash'          => 'Cash',
        'airtime'       => 'Airtime',
        'data'          => 'Data Bundle',
        'physical_item' => 'Physical Item',
        'discount'      => 'Discount',
        'voucher'       => 'Voucher',
        'badge'         => 'Badge',
        'other'         => 'Other',
    ];
}

function reward_claim_status_labels(): array {
    return [
        'pending'      => 'Pending',
        'under_review' => 'Under Review',
        'approved'     => 'Approved',
        'processing'   => 'Processing',
        'fulfilled'    => 'Fulfilled',
        'rejected'     => 'Rejected',
        'cancelled'    => 'Cancelled',
    ];
}

function reward_claim_status_color(string $status): string {
    return [
        'pending'      => '#f59e0b',
        'under_review' => '#3b82f6',
        'approved'     => '#0ea5e9',
        'processing'   => '#8b5cf6',
        'fulfilled'    => '#16a34a',
        'rejected'     => '#dc2626',
        'cancelled'    => '#6b7280',
    ][$status] ?? '#6b7280';
}

/** Claim statuses that still have points locked against them (non-terminal, or fulfilled which permanently consumes them). */
function reward_active_claim_statuses(): array {
    return ['pending', 'under_review', 'approved', 'processing'];
}

/** Reasons an admin can pick when rejecting a claim (section 19 of the spec). */
function reward_rejection_reasons(): array {
    return [
        'invalid_payment_info' => 'Invalid payment information',
        'invalid_claim_info'   => 'Invalid claim information',
        'duplicate_claim'      => 'Duplicate claim',
        'not_eligible'         => 'User not eligible',
        'suspicious_activity'  => 'Suspicious activity',
        'reward_unavailable'   => 'Reward unavailable',
        'other'                => 'Other',
    ];
}

/** Computed display status for a milestone row — never stored, always derived. */
function reward_milestone_status(array $m): string {
    if (!$m['active']) return 'disabled';
    $today = date('Y-m-d');
    if (!empty($m['start_date']) && $m['start_date'] > $today) return 'upcoming';
    if (!empty($m['end_date']) && $m['end_date'] < $today) return 'expired';
    if ($m['max_claims'] !== null && (int)$m['claims_count'] >= (int)$m['max_claims']) return 'completed';
    return 'active';
}

function reward_milestone_status_label(string $status): string {
    return [
        'disabled'  => 'Disabled',
        'upcoming'  => 'Upcoming',
        'expired'   => 'Expired',
        'completed' => 'Fully Claimed',
        'active'    => 'Active',
    ][$status] ?? ucfirst($status);
}

// ── Reads ─────────────────────────────────────────────────────────────────────

function get_all_milestones(bool $activeOnly = false): array {
    global $pdo;
    $sql = 'SELECT * FROM reward_milestones';
    if ($activeOnly) $sql .= " WHERE active=1 AND (start_date IS NULL OR start_date<=CURDATE()) AND (end_date IS NULL OR end_date>=CURDATE())";
    $sql .= ' ORDER BY required_points ASC';
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function get_milestone(int $id): ?array {
    global $pdo;
    $st = $pdo->prepare('SELECT * FROM reward_milestones WHERE id=?');
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Points currently reserved by this user's non-terminal claims — derived, never stored. */
function get_user_locked_points(int $userId): int {
    global $pdo;
    $statuses = reward_active_claim_statuses();
    $placeholders = implode(',', array_fill(0, count($statuses), '?'));
    $st = $pdo->prepare("SELECT COALESCE(SUM(points_locked),0) FROM reward_claims WHERE user_id=? AND status IN ($placeholders)");
    $st->execute(array_merge([$userId], $statuses));
    return (int)$st->fetchColumn();
}

/**
 * Whether $userId already has a claim for $milestoneId that blocks a new one.
 * one_time milestones: blocked by ANY non-terminal claim OR a fulfilled one (their one shot is used).
 * repeatable milestones: blocked only while a non-terminal claim is in flight; a fulfilled/rejected/
 * cancelled claim frees them to claim again once the milestone is reached again.
 */
function user_has_blocking_claim(int $userId, array $milestone): bool {
    global $pdo;
    $blockingStatuses = reward_active_claim_statuses();
    if ($milestone['claim_frequency'] === 'one_time') $blockingStatuses[] = 'fulfilled';
    $placeholders = implode(',', array_fill(0, count($blockingStatuses), '?'));
    $st = $pdo->prepare("SELECT 1 FROM reward_claims WHERE user_id=? AND milestone_id=? AND status IN ($placeholders) LIMIT 1");
    $st->execute(array_merge([$userId, $milestone['id']], $blockingStatuses));
    return (bool)$st->fetchColumn();
}

/**
 * Builds the full "My Rewards" view model for a user: available balance,
 * locked points, and every milestone bucketed into available / almost_there
 * / locked / unavailable (already claimed, expired, disabled, or full).
 * A handful of cheap, indexed queries — never loads claim history in bulk.
 */
function get_user_reward_dashboard(int $userId): array {
    $balance = get_points_balance($userId);
    $milestones = get_all_milestones();

    $available = $almostThere = $locked = [];
    foreach ($milestones as $m) {
        $mStatus = reward_milestone_status($m);
        if (in_array($mStatus, ['disabled', 'expired'], true)) continue; // never shown to users

        $blocked = user_has_blocking_claim($userId, $m);
        $canAfford = $balance >= (int)$m['required_points'];

        if ($mStatus === 'completed') {
            if (!$blocked) continue; // fully claimed by others and this user never engaged — not worth showing
            $locked[] = $m + ['_blocked' => true, '_reason' => 'Reward fully claimed'];
        } elseif ($blocked) {
            $locked[] = $m + ['_blocked' => true, '_reason' => 'Already claimed'];
        } elseif ($canAfford && $mStatus === 'active') {
            $available[] = $m;
        } elseif ($mStatus === 'upcoming') {
            $locked[] = $m + ['_blocked' => false, '_reason' => 'Starts ' . date('d M Y', strtotime($m['start_date']))];
        } else {
            // Not yet enough points — "almost there" if within 40% of the requirement, else locked.
            $pct = $m['required_points'] > 0 ? $balance / $m['required_points'] : 0;
            if ($pct >= 0.6) $almostThere[] = $m; else $locked[] = $m + ['_blocked' => false, '_reason' => null];
        }
    }

    return [
        'balance'       => $balance,
        'locked_points' => get_user_locked_points($userId),
        'available'     => $available,
        'almost_there'  => $almostThere,
        'locked'        => $locked,
    ];
}

function get_reward_claim(int $claimId): ?array {
    global $pdo;
    $st = $pdo->prepare(
        'SELECT rc.*, rm.title AS milestone_title, rm.reward_description, u.name AS user_name, u.email AS user_email,
                au.name AS approved_by_name
         FROM reward_claims rc
         JOIN reward_milestones rm ON rc.milestone_id = rm.id
         JOIN users u ON rc.user_id = u.id
         LEFT JOIN users au ON rc.approved_by = au.id
         WHERE rc.id=?'
    );
    $st->execute([$claimId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function get_reward_claim_by_reference(string $ref): ?array {
    global $pdo;
    $id = $pdo->prepare('SELECT id FROM reward_claims WHERE reference_code=?');
    $id->execute([$ref]);
    $id = $id->fetchColumn();
    return $id ? get_reward_claim((int)$id) : null;
}

function get_user_reward_claims(int $userId, int $limit = 30, int $offset = 0): array {
    global $pdo;
    $st = $pdo->prepare(
        'SELECT rc.*, rm.title AS milestone_title
         FROM reward_claims rc JOIN reward_milestones rm ON rc.milestone_id = rm.id
         WHERE rc.user_id=? ORDER BY rc.created_at DESC LIMIT ? OFFSET ?'
    );
    $st->bindValue(1, $userId, PDO::PARAM_INT);
    $st->bindValue(2, $limit, PDO::PARAM_INT);
    $st->bindValue(3, $offset, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

// ── Eligibility (section 11) ─────────────────────────────────────────────────

/**
 * Full server-side eligibility check — never trust the client. Returns
 * ['ok'=>bool, 'error'=>?string, 'milestone'=>?array, 'balance'=>int].
 */
function evaluate_claim_eligibility(int $userId, int $milestoneId): array {
    if (!rewards_enabled()) return ['ok' => false, 'error' => 'The rewards programme is not currently active.'];

    $m = get_milestone($milestoneId);
    if (!$m) return ['ok' => false, 'error' => 'This reward could not be found.'];

    $status = reward_milestone_status($m);
    if ($status === 'disabled') return ['ok' => false, 'error' => 'This reward is no longer available.'];
    if ($status === 'upcoming') return ['ok' => false, 'error' => 'This reward is not open for claims yet.'];
    if ($status === 'expired') return ['ok' => false, 'error' => 'This milestone has expired.'];
    if ($status === 'completed') return ['ok' => false, 'error' => 'This reward has reached its maximum number of claims.'];

    if (user_has_blocking_claim($userId, $m)) {
        return ['ok' => false, 'error' => 'You have already claimed this reward.'];
    }

    $balance = get_points_balance($userId);
    if ($balance < (int)$m['required_points']) {
        return ['ok' => false, 'error' => "You don't have enough available points for this reward."];
    }

    return ['ok' => true, 'error' => null, 'milestone' => $m, 'balance' => $balance];
}

// ── Claim creation (sections 6, 14 — atomic point lock) ──────────────────────

function generate_reward_claim_reference(int $claimId): string {
    return 'RW-' . date('Y') . '-' . str_pad((string)$claimId, 6, '0', STR_PAD_LEFT);
}

/**
 * Creates a claim and locks the required points, atomically. Re-validates
 * everything inside the transaction (never trusts the pre-check alone) and
 * row-locks the wallet with SELECT ... FOR UPDATE — the same pattern
 * functions.php already uses for job_post_credits — so two simultaneous
 * submissions can never both succeed against the same points.
 */
function create_reward_claim(int $userId, int $milestoneId, array $claimDetails): array {
    global $pdo;

    $pdo->beginTransaction();
    try {
        // Re-fetch + re-check everything inside the transaction.
        $m = $pdo->prepare('SELECT * FROM reward_milestones WHERE id=? FOR UPDATE');
        $m->execute([$milestoneId]);
        $m = $m->fetch(PDO::FETCH_ASSOC);
        if (!$m) { $pdo->rollBack(); return ['ok' => false, 'error' => 'This reward could not be found.']; }

        $status = reward_milestone_status($m);
        if ($status === 'disabled') { $pdo->rollBack(); return ['ok' => false, 'error' => 'This reward is no longer available.']; }
        if ($status === 'upcoming') { $pdo->rollBack(); return ['ok' => false, 'error' => 'This reward is not open for claims yet.']; }
        if ($status === 'expired')  { $pdo->rollBack(); return ['ok' => false, 'error' => 'This milestone has expired.']; }
        if ($status === 'completed') { $pdo->rollBack(); return ['ok' => false, 'error' => 'This reward has reached its maximum number of claims.']; }

        if (user_has_blocking_claim($userId, $m)) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'You have already claimed this reward.'];
        }

        // Row-lock the wallet so a concurrent claim can't read the same stale balance.
        $walletRow = $pdo->prepare('SELECT balance FROM points_wallets WHERE user_id=? FOR UPDATE');
        $walletRow->execute([$userId]);
        $balance = (int)($walletRow->fetchColumn() ?: 0);

        $required = (int)$m['required_points'];
        if ($balance < $required) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => "You don't have enough available points for this reward."];
        }

        // Insert the claim first (reference_code needs the new id), then lock points.
        $ins = $pdo->prepare(
            'INSERT INTO reward_claims (reference_code, user_id, milestone_id, points_locked, reward_type, reward_value, claim_details, status)
             VALUES (?,?,?,?,?,?,?,?)'
        );
        $ins->execute(['', $userId, $milestoneId, $required, $m['reward_type'], $m['reward_value'], json_encode($claimDetails, JSON_UNESCAPED_UNICODE), 'pending']);
        $claimId = (int)$pdo->lastInsertId();
        $reference = generate_reward_claim_reference($claimId);
        $pdo->prepare('UPDATE reward_claims SET reference_code=? WHERE id=?')->execute([$reference, $claimId]);

        // Lock the points — a real ledger debit, reusing the existing points_transactions/points_wallets tables.
        $pdo->prepare('INSERT INTO points_transactions (user_id, event, points, related_id, note, created_at) VALUES (?,?,?,?,?,NOW())')
            ->execute([$userId, 'reward_claim_lock', -$required, $claimId, 'Locked for claim ' . $reference]);
        $pdo->prepare('UPDATE points_wallets SET balance = balance - ?, updated_at = NOW() WHERE user_id = ?')
            ->execute([$required, $userId]);

        $pdo->prepare('UPDATE reward_milestones SET claims_count = claims_count + 1 WHERE id=?')->execute([$milestoneId]);

        $pdo->commit();

        log_audit_action($userId, 'reward_claim_created', "Claimed \"{$m['title']}\" ({$reference}) — {$required} points locked");
        notify_user($userId, '🎁 Reward claim received',
            'Your reward claim ' . $reference . ' for "' . $m['title'] . '" has been received and is awaiting review.',
            'info', 'my_reward_claims.php?ref=' . $reference);

        return ['ok' => true, 'claim_id' => $claimId, 'reference' => $reference];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'error' => "We couldn't process your claim. Please try again."];
    }
}

/** User-initiated cancellation — only while still pending (before an admin starts reviewing). */
function user_cancel_reward_claim(int $userId, int $claimId): array {
    global $pdo;
    $pdo->beginTransaction();
    try {
        $c = $pdo->prepare('SELECT * FROM reward_claims WHERE id=? AND user_id=? FOR UPDATE');
        $c->execute([$claimId, $userId]);
        $c = $c->fetch(PDO::FETCH_ASSOC);
        if (!$c) { $pdo->rollBack(); return ['ok' => false, 'error' => 'Claim not found.']; }
        if ($c['status'] !== 'pending') { $pdo->rollBack(); return ['ok' => false, 'error' => 'This claim can no longer be cancelled.']; }

        _reward_release_points($c, 'reward_claim_cancel', 'Cancelled by user');
        $pdo->prepare("UPDATE reward_claims SET status='cancelled', cancelled_at=NOW() WHERE id=?")->execute([$claimId]);
        $pdo->prepare('UPDATE reward_milestones SET claims_count = GREATEST(0, claims_count - 1) WHERE id=?')->execute([$c['milestone_id']]);

        $pdo->commit();
        log_audit_action($userId, 'reward_claim_cancelled', "Cancelled claim {$c['reference_code']}");
        return ['ok' => true];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'error' => 'Could not cancel this claim. Please try again.'];
    }
}

/** Internal — credits locked points back to the wallet. Caller must already be inside a transaction with $claim row-locked. */
function _reward_release_points(array $claim, string $event, string $note): void {
    global $pdo;
    $pdo->prepare('INSERT INTO points_transactions (user_id, event, points, related_id, note, created_at) VALUES (?,?,?,?,?,NOW())')
        ->execute([$claim['user_id'], $event, (int)$claim['points_locked'], $claim['id'], $note]);
    $pdo->prepare('UPDATE points_wallets SET balance = balance + ?, updated_at = NOW() WHERE user_id = ?')
        ->execute([(int)$claim['points_locked'], $claim['user_id']]);
}

// ── Admin review (sections 18–21) ────────────────────────────────────────────

/** Only these transitions are ever allowed — never an arbitrary status write. */
function reward_claim_valid_transitions(): array {
    return [
        'pending'      => ['under_review', 'approved', 'rejected', 'cancelled'],
        'under_review' => ['approved', 'rejected', 'cancelled'],
        'approved'     => ['processing', 'fulfilled', 'rejected'],
        'processing'   => ['fulfilled', 'rejected'],
        'fulfilled'    => [],
        'rejected'     => [],
        'cancelled'    => [],
    ];
}

function admin_mark_claim_under_review(int $claimId, int $adminId): array {
    return _reward_admin_transition($claimId, $adminId, 'under_review', function ($pdo, $c) {
        $pdo->prepare("UPDATE reward_claims SET status='under_review', updated_at=NOW() WHERE id=?")->execute([$c['id']]);
    });
}

function admin_approve_reward_claim(int $claimId, int $adminId): array {
    return _reward_admin_transition($claimId, $adminId, 'approved', function ($pdo, $c) use ($adminId) {
        $pdo->prepare("UPDATE reward_claims SET status='approved', approved_by=?, approved_at=NOW(), updated_at=NOW() WHERE id=?")
            ->execute([$adminId, $c['id']]);
    }, function ($c) {
        notify_user((int)$c['user_id'], '🎉 Reward claim approved',
            'Your ' . $c['reward_description'] . ' claim (' . $c['reference_code'] . ') has been approved.',
            'success', 'my_reward_claims.php?ref=' . $c['reference_code']);
    });
}

function admin_mark_claim_processing(int $claimId, int $adminId, ?string $note = null): array {
    return _reward_admin_transition($claimId, $adminId, 'processing', function ($pdo, $c) use ($note) {
        $pdo->prepare("UPDATE reward_claims SET status='processing', processing_at=NOW(), admin_note=COALESCE(?,admin_note), updated_at=NOW() WHERE id=?")
            ->execute([$note, $c['id']]);
    }, function ($c) {
        notify_user((int)$c['user_id'], 'Reward being processed',
            'Your reward claim ' . $c['reference_code'] . ' is currently being processed.',
            'info', 'my_reward_claims.php?ref=' . $c['reference_code']);
    });
}

function admin_mark_claim_fulfilled(int $claimId, int $adminId, ?string $note, ?string $reference): array {
    return _reward_admin_transition($claimId, $adminId, 'fulfilled', function ($pdo, $c) use ($note, $reference) {
        $pdo->prepare("UPDATE reward_claims SET status='fulfilled', fulfilled_at=NOW(), fulfillment_note=?, fulfillment_reference=?, updated_at=NOW() WHERE id=?")
            ->execute([$note, $reference, $c['id']]);
    }, function ($c) {
        notify_user((int)$c['user_id'], '🎁 Reward fulfilled',
            'Your ' . $c['reward_description'] . ' reward (' . $c['reference_code'] . ') has been fulfilled. Thank you for being part of ' . APP_NAME . '!',
            'success', 'my_reward_claims.php?ref=' . $c['reference_code']);
    });
}

/** Rejection releases the locked points back to the user — from any non-terminal state. */
function admin_reject_reward_claim(int $claimId, int $adminId, string $reason, ?string $note): array {
    return _reward_admin_transition($claimId, $adminId, 'rejected', function ($pdo, $c) use ($reason, $note) {
        _reward_release_points($c, 'reward_claim_release', 'Claim ' . $c['reference_code'] . ' rejected: ' . $reason);
        $pdo->prepare("UPDATE reward_claims SET status='rejected', rejection_reason=?, admin_note=?, rejected_at=NOW(), updated_at=NOW() WHERE id=?")
            ->execute([$reason, $note, $c['id']]);
        $pdo->prepare('UPDATE reward_milestones SET claims_count = GREATEST(0, claims_count - 1) WHERE id=?')->execute([$c['milestone_id']]);
    }, function ($c) use ($reason) {
        notify_user((int)$c['user_id'], 'Reward claim rejected',
            'Your reward claim ' . $c['reference_code'] . ' has been rejected (' . $reason . '). Your points have been returned to your balance. Check your Rewards page for details.',
            'error', 'my_reward_claims.php?ref=' . $c['reference_code']);
    });
}

/**
 * Shared guarded transition runner: row-locks the claim, checks the move is
 * legal per reward_claim_valid_transitions(), runs $apply inside the same
 * transaction, then fires $notify (if given) after commit.
 */
function _reward_admin_transition(int $claimId, int $adminId, string $toStatus, callable $apply, ?callable $notify = null): array {
    global $pdo;
    $pdo->beginTransaction();
    try {
        $c = $pdo->prepare(
            'SELECT rc.*, rm.title AS milestone_title, rm.reward_description FROM reward_claims rc
             JOIN reward_milestones rm ON rc.milestone_id = rm.id WHERE rc.id=? FOR UPDATE'
        );
        $c->execute([$claimId]);
        $c = $c->fetch(PDO::FETCH_ASSOC);
        if (!$c) { $pdo->rollBack(); return ['ok' => false, 'error' => 'Claim not found.']; }

        $allowed = reward_claim_valid_transitions()[$c['status']] ?? [];
        if (!in_array($toStatus, $allowed, true)) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => "Can't move a claim from \"{$c['status']}\" to \"{$toStatus}\"."];
        }

        $prevStatus = $c['status'];
        $apply($pdo, $c);
        $pdo->commit();

        log_audit_action($adminId, 'reward_claim_' . $toStatus, "Claim {$c['reference_code']}: {$prevStatus} → {$toStatus}");
        if ($notify) $notify($c);

        return ['ok' => true];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'error' => 'Could not update this claim. Please try again.'];
    }
}

// ── Admin analytics (section 37) ─────────────────────────────────────────────

function get_reward_claim_stats(): array {
    global $pdo;
    $row = $pdo->query(
        "SELECT
            SUM(status='pending')      AS pending,
            SUM(status='under_review') AS under_review,
            SUM(status='approved')     AS approved,
            SUM(status='processing')   AS processing,
            SUM(status='fulfilled')    AS fulfilled,
            SUM(status='rejected')     AS rejected,
            SUM(status='cancelled')    AS cancelled,
            COUNT(*)                   AS total,
            COALESCE(SUM(CASE WHEN status='fulfilled' THEN points_locked ELSE 0 END),0) AS points_redeemed
         FROM reward_claims"
    )->fetch(PDO::FETCH_ASSOC);
    foreach ($row as $k => $v) if ($k !== 'points_redeemed') $row[$k] = (int)$v;
    return $row;
}

// ── Proactive "milestone reached" notification (section 22) ─────────────────

/**
 * Called from award_points() (modules/referrals/service.php) right after any
 * successful award — checks whether $userId's new balance just crossed one
 * or more active milestones they haven't been told about yet, and notifies
 * them once each via reward_milestone_notifications' unique key (INSERT
 * IGNORE is the atomic, concurrency-safe dedup guard — never double-sends
 * even if two award_points() calls race for the same user).
 *
 * Cheap by design: one indexed query, only runs at the moment points change,
 * never scans anything on ordinary page loads.
 */
function rewards_check_new_milestones(int $userId): void {
    if (!rewards_enabled()) return;
    global $pdo;

    $balance = get_points_balance($userId);
    if ($balance <= 0) return;

    $st = $pdo->prepare(
        "SELECT rm.* FROM reward_milestones rm
         LEFT JOIN reward_milestone_notifications n ON n.milestone_id = rm.id AND n.user_id = ?
         WHERE rm.required_points <= ? AND rm.active = 1
           AND (rm.start_date IS NULL OR rm.start_date <= CURDATE())
           AND (rm.end_date IS NULL OR rm.end_date >= CURDATE())
           AND n.user_id IS NULL"
    );
    $st->execute([$userId, $balance]);
    $candidates = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$candidates) return;

    $dedupe = $pdo->prepare('INSERT IGNORE INTO reward_milestone_notifications (user_id, milestone_id, notified_at) VALUES (?,?,NOW())');

    foreach ($candidates as $m) {
        // Skip (without recording the dedupe row) anything not genuinely
        // claimable by this user right now — fully claimed by others, or
        // already used up by this same user. Leaving it un-dedupe'd means
        // we naturally re-check next time they earn points, so if a slot
        // frees up later (a rejected claim, say) they still get notified
        // once it's real. Never send a notification promising a reward
        // that isn't actually available.
        if (reward_milestone_status($m) !== 'active') continue;
        if (user_has_blocking_claim($userId, $m)) continue;

        $dedupe->execute([$userId, $m['id']]);
        if ($dedupe->rowCount() === 0) continue; // another concurrent call already claimed this one

        notify_user($userId, '🎉 Milestone Reached!',
            'Congratulations! You have reached ' . number_format((int)$m['required_points']) . ' points and unlocked "' . $m['reward_description'] . '".',
            'success', 'my_rewards.php');
    }
}

function get_milestone_eligible_user_count(array $milestone): int {
    global $pdo;
    $st = $pdo->prepare('SELECT COUNT(*) FROM points_wallets WHERE balance >= ?');
    $st->execute([(int)$milestone['required_points']]);
    return (int)$st->fetchColumn();
}
