<?php
/**
 * modules/referrals/service.php
 * Referral & Points module — service layer.
 *
 * Include AFTER auth.php so $pdo and get_platform_setting() are available.
 * All public functions are guarded by referrals_enabled() internally.
 */
if (defined('REFERRAL_MODULE_LOADED')) return;
define('REFERRAL_MODULE_LOADED', true);

// ── Internal event registry ────────────────────────────────────────────────────
// [default_points, one_time, default_daily_cap (0 = none)]
function _ref_event_meta(): array {
    return [
        'registration'            => [10, true,  0],
        'email_verification'      => [5,  true,  0],
        'phone_verification'      => [5,  true,  0],
        'profile_photo'           => [5,  true,  0],
        'referral_registers'      => [5,  false, 0],
        'referral_email_verified' => [5,  false, 0],
        'referral_first_payment'  => [10, false, 0],
        'hire_worker'             => [10, false, 20],
        'mark_job_completed'      => [5,  false, 10],
        'leave_review'            => [5,  false, 10],
        'complete_job'            => [10, false, 20],
        'five_star_rating'        => [5,  false, 10],
        'news_approved'           => [10, false, 30],
        'event_approved'          => [10, false, 30],
        'funeral_approved'        => [10, false, 20],
    ];
}

// ── Public API ────────────────────────────────────────────────────────────────

function referrals_enabled(): bool {
    return (int)get_platform_setting('referrals_enabled', 1) === 1;
}

/**
 * Returns the live point config (values from DB, falling back to defaults).
 * Cached per-request via static variable.
 */
function get_points_config(): array {
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $cfg = [];
    foreach (_ref_event_meta() as $event => [$defPts, $oneTime, $defCap]) {
        $cfg[$event] = [
            'points'   => (int)get_platform_setting('points_' . $event, $defPts),
            'one_time' => $oneTime,
            'cap'      => $defCap > 0 ? (int)get_platform_setting('points_' . $event . '_cap', $defCap) : 0,
        ];
    }
    return $cfg;
}

/**
 * Award points to a user for a named event.
 * Respects one-time deduplication and daily caps.
 * Returns true if points were actually awarded.
 */
function award_points(int $userId, string $event, ?int $relatedId = null): bool {
    if (!referrals_enabled()) return false;
    global $pdo;

    $cfg = get_points_config();
    if (!isset($cfg[$event])) return false;

    $pts = $cfg[$event]['points'];
    if ($pts <= 0) return false;

    // One-time lifetime deduplication
    if ($cfg[$event]['one_time']) {
        $chk = $pdo->prepare("SELECT 1 FROM points_transactions WHERE user_id=? AND event=? LIMIT 1");
        $chk->execute([$userId, $event]);
        if ($chk->fetch()) return false;
    }

    // Per-item deduplication — when a specific related record is given, never
    // award the same event twice for that exact record (e.g. an admin
    // toggling a news article published → draft → published again shouldn't
    // pay out a second time for the same article).
    if ($relatedId !== null) {
        $chk = $pdo->prepare("SELECT 1 FROM points_transactions WHERE user_id=? AND event=? AND related_id=? LIMIT 1");
        $chk->execute([$userId, $event, $relatedId]);
        if ($chk->fetch()) return false;
    }

    // Daily cap check
    $cap = $cfg[$event]['cap'];
    if ($cap > 0) {
        $dayStmt = $pdo->prepare(
            "SELECT COALESCE(SUM(points),0) FROM points_transactions WHERE user_id=? AND event=? AND DATE(created_at)=CURDATE()"
        );
        $dayStmt->execute([$userId, $event]);
        $todayTotal = (int)$dayStmt->fetchColumn();
        if ($todayTotal >= $cap) return false;
        $pts = min($pts, $cap - $todayTotal);
        if ($pts <= 0) return false;
    }

    // Record transaction
    $pdo->prepare("INSERT INTO points_transactions (user_id,event,points,related_id,created_at) VALUES (?,?,?,?,NOW())")
        ->execute([$userId, $event, $pts, $relatedId]);

    // Upsert wallet
    $pdo->prepare("INSERT INTO points_wallets (user_id,balance,total_earned,updated_at)
        VALUES (?,?,?,NOW())
        ON DUPLICATE KEY UPDATE balance=balance+VALUES(balance), total_earned=total_earned+VALUES(total_earned), updated_at=NOW()")
        ->execute([$userId, $pts, $pts]);

    // Optional hook: if the Milestone Reward module (modules/rewards) is
    // installed, let it check whether this award just pushed the user over
    // a new milestone threshold, so it can push a "🎉 Milestone Reached!"
    // notification. Guarded by file_exists() so award_points() itself has
    // zero dependency on that module and behaves identically whether or not
    // it's present — none of the earning logic above is affected either way.
    // Wrapped in try/catch so a problem in that optional add-on (e.g. its
    // tables not yet migrated) can never fail a real points award — the
    // award above has already committed by this point regardless.
    try {
        $rewardsService = __DIR__ . '/../rewards/service.php';
        if (file_exists($rewardsService)) {
            require_once $rewardsService;
            if (function_exists('rewards_check_new_milestones')) rewards_check_new_milestones($userId);
        }
    } catch (Exception $e) {
        // Swallow — the points award itself already succeeded and must not be undone.
    }

    return true;
}

/**
 * Returns a user's current points balance (0 if no wallet exists).
 */
function get_points_balance(int $userId): int {
    global $pdo;
    $stmt = $pdo->prepare("SELECT balance FROM points_wallets WHERE user_id=?");
    $stmt->execute([$userId]);
    return (int)($stmt->fetchColumn() ?: 0);
}

/**
 * Admin manual points adjustment — add (positive) or remove (negative) an
 * arbitrary number of points from a user's wallet, with a required
 * description recorded on the transaction (points_transactions.note) for
 * accountability. Unlike award_points(), this bypasses the event registry
 * entirely (event is always logged as 'admin_adjustment') and is not gated
 * by referrals_enabled(), since it's a corrective admin tool, not part of
 * the automatic earning engine.
 *
 * Row-locks the wallet (mirrors the reward-claim locking pattern in
 * modules/rewards/service.php) so a concurrent adjustment or claim can't
 * race past a stale balance. Removing points never takes the balance below
 * zero — the whole adjustment is rejected instead of partially applied.
 *
 * total_earned is only incremented on a positive adjustment; a deduction
 * reduces the spendable balance without erasing the user's lifetime-earned
 * stat (same convention as reward-claim locking/release).
 *
 * @return array{ok:bool, error:?string, new_balance:?int}
 */
function admin_adjust_points(int $userId, int $points, string $note, int $adminId): array {
    global $pdo;

    if ($points === 0) return ['ok' => false, 'error' => 'Enter a non-zero number of points.', 'new_balance' => null];
    $note = trim($note);
    if ($note === '') return ['ok' => false, 'error' => 'A description is required.', 'new_balance' => null];

    $chkUser = $pdo->prepare("SELECT 1 FROM users WHERE id=?");
    $chkUser->execute([$userId]);
    if (!$chkUser->fetch()) return ['ok' => false, 'error' => 'User not found.', 'new_balance' => null];

    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT IGNORE INTO points_wallets (user_id,balance,total_earned,updated_at) VALUES (?,0,0,NOW())")
            ->execute([$userId]);

        $row = $pdo->prepare("SELECT balance FROM points_wallets WHERE user_id=? FOR UPDATE");
        $row->execute([$userId]);
        $balance = (int)$row->fetchColumn();

        if ($points < 0 && $balance + $points < 0) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => "This user only has {$balance} points — cannot remove " . abs($points) . '.', 'new_balance' => null];
        }

        $pdo->prepare("INSERT INTO points_transactions (user_id,event,points,note,created_at) VALUES (?,?,?,?,NOW())")
            ->execute([$userId, 'admin_adjustment', $points, $note]);

        if ($points > 0) {
            $pdo->prepare("UPDATE points_wallets SET balance=balance+?, total_earned=total_earned+?, updated_at=NOW() WHERE user_id=?")
                ->execute([$points, $points, $userId]);
        } else {
            $pdo->prepare("UPDATE points_wallets SET balance=balance+?, updated_at=NOW() WHERE user_id=?")
                ->execute([$points, $userId]);
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        return ['ok' => false, 'error' => 'Something went wrong applying the adjustment. Please try again.', 'new_balance' => null];
    }

    $newBalance = get_points_balance($userId);

    notify_user(
        $userId,
        $points > 0 ? '🎁 Points Added' : '⚠️ Points Adjusted',
        ($points > 0 ? 'You received +' . number_format($points) . ' points' : 'You had ' . number_format(abs($points)) . ' points removed')
            . ' by an admin. Reason: ' . $note . '. New balance: ' . number_format($newBalance) . ' points.',
        $points > 0 ? 'success' : 'warning',
        'referrals.php',
        $adminId
    );

    // Optional milestone hook — same as award_points(); guarded and
    // exception-safe so this optional module can never fail a real adjustment.
    if ($points > 0) {
        try {
            $rewardsService = __DIR__ . '/../rewards/service.php';
            if (file_exists($rewardsService)) {
                require_once $rewardsService;
                if (function_exists('rewards_check_new_milestones')) rewards_check_new_milestones($userId);
            }
        } catch (Exception $e) {
            // Swallow — the adjustment itself already succeeded and must not be undone.
        }
    }

    return ['ok' => true, 'error' => null, 'new_balance' => $newBalance];
}

/**
 * Returns (or lazily creates) a unique referral code for a user.
 */
function get_or_create_referral_code(int $userId): string {
    global $pdo;
    $stmt = $pdo->prepare("SELECT code FROM referral_codes WHERE user_id=?");
    $stmt->execute([$userId]);
    if ($code = $stmt->fetchColumn()) return $code;

    // Generate unique 8-char code (retry on collision, though rare)
    do {
        $code = strtoupper(substr(bin2hex(random_bytes(5)), 0, 8));
        $chk  = $pdo->prepare("SELECT 1 FROM referral_codes WHERE code=?");
        $chk->execute([$code]);
    } while ($chk->fetch());

    $pdo->prepare("INSERT INTO referral_codes (user_id,code) VALUES (?,?)")->execute([$userId, $code]);
    return $code;
}

/**
 * Returns the user_id who owns a referral code, or null if not found.
 */
function referral_code_owner(string $code): ?int {
    global $pdo;
    $stmt = $pdo->prepare("SELECT user_id FROM referral_codes WHERE code=?");
    $stmt->execute([strtoupper($code)]);
    $row = $stmt->fetchColumn();
    return $row !== false ? (int)$row : null;
}

/**
 * Tracks a referral link visit (one unique visit per IP per code per day).
 * Also increments the click counter on the referral_codes row.
 */
function record_referral_visit(string $code, string $ip): void {
    global $pdo;
    $code = strtoupper($code);
    $pdo->prepare("UPDATE referral_codes SET clicks=clicks+1 WHERE code=?")->execute([$code]);

    $dup = $pdo->prepare(
        "SELECT 1 FROM referral_visits WHERE code=? AND ip_address=? AND DATE(visited_at)=CURDATE() LIMIT 1"
    );
    $dup->execute([$code, $ip]);
    if (!$dup->fetch()) {
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512);
        $pdo->prepare("INSERT INTO referral_visits (code,ip_address,user_agent,visited_at) VALUES (?,?,?,NOW())")
            ->execute([$code, $ip, $ua]);
    }
}

/**
 * Records a new referral relationship and awards the referrer registration points.
 * Idempotent — UNIQUE KEY on referred_id prevents duplicates.
 */
function record_referral(int $referrerId, int $referredId, string $code): void {
    global $pdo;
    $ins = $pdo->prepare(
        "INSERT IGNORE INTO referrals (referrer_id,referred_id,code,registered_at,created_at) VALUES (?,?,?,NOW(),NOW())"
    );
    $ins->execute([$referrerId, $referredId, strtoupper($code)]);

    if ($ins->rowCount() > 0) {
        // Mark the most recent unconverted visit as converted
        $pdo->prepare(
            "UPDATE referral_visits SET converted=1 WHERE code=? AND converted=0 ORDER BY visited_at DESC LIMIT 1"
        )->execute([strtoupper($code)]);

        award_points($referrerId, 'referral_registers', $referredId);
    }
}

/**
 * Called when a referred user hits a milestone.
 * $milestone: 'email_verified' | 'first_payment'
 * Deduplicates via timestamps on the referrals row.
 */
function handle_referral_milestone(int $referredId, string $milestone): void {
    global $pdo;
    $stmt = $pdo->prepare(
        "SELECT referrer_id, email_verified_at, first_payment_at FROM referrals WHERE referred_id=?"
    );
    $stmt->execute([$referredId]);
    $row = $stmt->fetch();
    if (!$row) return;

    $referrerId = (int)$row['referrer_id'];

    if ($milestone === 'email_verified' && empty($row['email_verified_at'])) {
        $pdo->prepare("UPDATE referrals SET email_verified_at=NOW() WHERE referred_id=?")->execute([$referredId]);
        award_points($referrerId, 'referral_email_verified', $referredId);

    } elseif ($milestone === 'first_payment' && empty($row['first_payment_at'])) {
        $pdo->prepare("UPDATE referrals SET first_payment_at=NOW() WHERE referred_id=?")->execute([$referredId]);
        award_points($referrerId, 'referral_first_payment', $referredId);
    }
}

/**
 * Returns a user's recent points transactions (newest first).
 */
function get_user_points_history(int $userId, int $limit = 30): array {
    global $pdo;
    $stmt = $pdo->prepare(
        "SELECT * FROM points_transactions WHERE user_id=? ORDER BY created_at DESC LIMIT ?"
    );
    $stmt->execute([$userId, $limit]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Returns all referrals made by a user (newest first).
 */
function get_user_referrals(int $userId): array {
    global $pdo;
    $stmt = $pdo->prepare(
        "SELECT r.*, u.name AS referred_name, u.email AS referred_email
         FROM referrals r JOIN users u ON u.id=r.referred_id
         WHERE r.referrer_id=? ORDER BY r.created_at DESC"
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
