<?php
/**
 * modules/referrals/migrate.php
 * Run once (admin only) to create all referral/points tables
 * and seed platform_settings with default point values.
 */
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../functions.php';

if (!is_admin()) {
    http_response_code(403);
    exit('Admin only.');
}

$errors = [];
$done   = [];

// ── 1. referral_codes ─────────────────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS referral_codes (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        user_id    INT NOT NULL,
        code       VARCHAR(16) NOT NULL,
        clicks     INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_user (user_id),
        UNIQUE KEY uq_code (code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done[] = 'referral_codes';
} catch (Exception $e) { $errors[] = 'referral_codes: ' . $e->getMessage(); }

// ── 2. referral_visits ────────────────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS referral_visits (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        code        VARCHAR(16) NOT NULL,
        ip_address  VARCHAR(45),
        user_agent  VARCHAR(512),
        converted   TINYINT(1) NOT NULL DEFAULT 0,
        visited_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_code    (code),
        INDEX idx_visited (visited_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done[] = 'referral_visits';
} catch (Exception $e) { $errors[] = 'referral_visits: ' . $e->getMessage(); }

// ── 3. referrals ─────────────────────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS referrals (
        id                INT AUTO_INCREMENT PRIMARY KEY,
        referrer_id       INT NOT NULL,
        referred_id       INT NOT NULL,
        code              VARCHAR(16) NOT NULL,
        registered_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        email_verified_at DATETIME NULL,
        first_payment_at  DATETIME NULL,
        created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_referred (referred_id),
        INDEX idx_referrer (referrer_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done[] = 'referrals';
} catch (Exception $e) { $errors[] = 'referrals: ' . $e->getMessage(); }

// ── 4. points_wallets ─────────────────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS points_wallets (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        user_id      INT NOT NULL,
        balance      INT NOT NULL DEFAULT 0,
        total_earned INT NOT NULL DEFAULT 0,
        updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done[] = 'points_wallets';
} catch (Exception $e) { $errors[] = 'points_wallets: ' . $e->getMessage(); }

// ── 5. points_transactions ────────────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS points_transactions (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        user_id    INT NOT NULL,
        event      VARCHAR(64) NOT NULL,
        points     INT NOT NULL,
        related_id INT NULL,
        note       VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user_event (user_id, event),
        INDEX idx_user_date  (user_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done[] = 'points_transactions';
} catch (Exception $e) { $errors[] = 'points_transactions: ' . $e->getMessage(); }

// ── Seed platform_settings defaults (INSERT IGNORE — won't overwrite) ─────────
$seeds = [
    'referrals_enabled'                      => 1,
    // Account
    'points_registration'                    => 10,
    'points_email_verification'              => 5,
    'points_phone_verification'              => 5,
    'points_profile_photo'                   => 5,
    // Referral
    'points_referral_registers'              => 5,
    'points_referral_email_verified'         => 5,
    'points_referral_first_payment'          => 10,
    // Client job
    'points_hire_worker'                     => 10,
    'points_hire_worker_cap'                 => 20,
    'points_mark_job_completed'              => 5,
    'points_mark_job_completed_cap'          => 10,
    'points_leave_review'                    => 5,
    'points_leave_review_cap'                => 10,
    // Worker
    'points_complete_job'                    => 10,
    'points_complete_job_cap'                => 20,
    'points_five_star_rating'                => 5,
    'points_five_star_rating_cap'            => 10,
];

$seedStmt = $pdo->prepare(
    "INSERT IGNORE INTO platform_settings (setting_key, setting_value, updated_at) VALUES (?, ?, NOW())"
);
$seeded = 0;
foreach ($seeds as $key => $val) {
    try {
        $seedStmt->execute([$key, $val]);
        if ($seedStmt->rowCount() > 0) $seeded++;
    } catch (Exception $e) { $errors[] = "seed {$key}: " . $e->getMessage(); }
}

?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Referral Module Migration</title>
<style>body{font-family:sans-serif;max-width:600px;margin:40px auto;padding:20px}
.ok{color:#16a34a}.err{color:#dc2626}pre{background:#f1f5f9;padding:12px;border-radius:4px}</style>
</head><body>
<h2>Referral Module Migration</h2>
<?php if ($errors): ?>
    <?php foreach ($errors as $e): ?>
        <p class="err">❌ <?php echo htmlspecialchars($e); ?></p>
    <?php endforeach; ?>
<?php endif; ?>
<?php foreach ($done as $t): ?>
    <p class="ok">✅ Table <strong><?php echo $t; ?></strong> ready.</p>
<?php endforeach; ?>
<p class="ok">✅ Seeded <?php echo $seeded; ?> new platform_settings default(s).</p>
<hr>
<p><?php echo $errors ? '<span class="err">Completed with errors — see above.</span>' : '<span class="ok">All done. <a href="../../admin/referrals.php">Go to Referrals Admin →</a></span>'; ?></p>
</body></html>
