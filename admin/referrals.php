<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../modules/referrals/service.php';

require_login();
if (!is_admin_or_manager()) {
    header('Location: index.php');
    exit;
}

require_mod_permission('manage_referrals');
$tab = $_GET['tab'] ?? 'config';

// ── POST: save config ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_config') {
        set_platform_setting('referrals_enabled', isset($_POST['referrals_enabled']) ? 1 : 0);

        $events = array_keys(_ref_event_meta());
        foreach ($events as $event) {
            [$defPts, $oneTime, $defCap] = _ref_event_meta()[$event];
            $pts = max(0, (int)($_POST['pts_' . $event] ?? $defPts));
            set_platform_setting('points_' . $event, $pts);
            if ($defCap > 0) {
                $cap = max(0, (int)($_POST['cap_' . $event] ?? $defCap));
                set_platform_setting('points_' . $event . '_cap', $cap);
            }
        }
        log_audit_action($user['id'], 'referral_config_saved', 'Points configuration updated');
        flash('Points configuration saved.', 'success');
        header('Location: referrals.php?tab=config');
        exit;
    }
}

// ── Stats ─────────────────────────────────────────────────────────────────────
$stats = $pdo->query("SELECT
    (SELECT COUNT(*) FROM referral_codes)                                   AS total_codes,
    (SELECT COUNT(*) FROM referrals)                                        AS total_referrals,
    (SELECT COUNT(*) FROM referrals WHERE email_verified_at IS NOT NULL)    AS verified_referrals,
    (SELECT COUNT(*) FROM referrals WHERE first_payment_at IS NOT NULL)     AS paid_referrals,
    (SELECT COALESCE(SUM(clicks),0) FROM referral_codes)                    AS total_clicks,
    (SELECT COUNT(*) FROM points_wallets)                                   AS wallets,
    (SELECT COALESCE(SUM(total_earned),0) FROM points_wallets)              AS total_points_issued,
    (SELECT COALESCE(SUM(balance),0) FROM points_wallets)                   AS total_balance
")->fetch(PDO::FETCH_ASSOC);

// ── Recent transactions (for Transactions tab) ─────────────────────────────────
if ($tab === 'transactions') {
    $txSearch = trim($_GET['q'] ?? '');
    $txWhere  = '';
    $txParams = [];
    if ($txSearch !== '') {
        // Three plain "?" placeholders, each bound separately — this app runs
        // with PDO::ATTR_EMULATE_PREPARES=false (real server-side prepared
        // statements), which can't bind one named parameter to more than one
        // occurrence in the same query.
        $txWhere = "WHERE u.name LIKE ? OR u.email LIKE ? OR pt.event LIKE ?";
        $like = '%' . $txSearch . '%';
        $txParams = [$like, $like, $like];
    }

    $txPage    = max(1, (int)($_GET['page'] ?? 1));
    $txPerPage = 30;
    $txOffset  = ($txPage - 1) * $txPerPage;

    $txCountStmt = $pdo->prepare("SELECT COUNT(*) FROM points_transactions pt JOIN users u ON u.id=pt.user_id {$txWhere}");
    $txCountStmt->execute($txParams);
    $txTotal      = (int)$txCountStmt->fetchColumn();
    $txTotalPages = max(1, (int)ceil($txTotal / $txPerPage));

    $txSQL = "SELECT pt.*, u.name AS user_name, u.email AS user_email
              FROM points_transactions pt JOIN users u ON u.id=pt.user_id
              {$txWhere}
              ORDER BY pt.created_at DESC LIMIT {$txPerPage} OFFSET {$txOffset}";
    $txStmt = $pdo->prepare($txSQL);
    $txStmt->execute($txParams);
    $transactions = $txStmt->fetchAll(PDO::FETCH_ASSOC);
}

// ── Referral relationships (for Referrals tab) ────────────────────────────────
if ($tab === 'referrals') {
    $refPage    = max(1, (int)($_GET['page'] ?? 1));
    $refPerPage = 30;
    $refOffset  = ($refPage - 1) * $refPerPage;

    $refTotal      = (int)$pdo->query('SELECT COUNT(*) FROM referrals')->fetchColumn();
    $refTotalPages = max(1, (int)ceil($refTotal / $refPerPage));

    $refRows = $pdo->query("SELECT r.*, rc.clicks,
        ref.name AS referrer_name, ref.email AS referrer_email,
        u.name AS referred_name, u.email AS referred_email
        FROM referrals r
        JOIN referral_codes rc ON rc.user_id = r.referrer_id
        JOIN users ref ON ref.id = r.referrer_id
        JOIN users u   ON u.id  = r.referred_id
        ORDER BY r.created_at DESC LIMIT {$refPerPage} OFFSET {$refOffset}")->fetchAll(PDO::FETCH_ASSOC);
}

function refadm_qstr(array $overrides = []): string {
    $base = [];
    foreach (['tab', 'q', 'page'] as $k) {
        if (isset($_GET[$k]) && $_GET[$k] !== '') $base[$k] = $_GET[$k];
    }
    $merged = array_filter(array_merge($base, $overrides), fn($v) => $v !== null);
    return 'referrals.php?' . http_build_query($merged);
}

// ── Top earners ───────────────────────────────────────────────────────────────
$topEarners = $pdo->query("SELECT u.id, u.name, u.email, pw.balance, pw.total_earned
    FROM points_wallets pw JOIN users u ON u.id=pw.user_id
    ORDER BY pw.total_earned DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);

// ── Config display ────────────────────────────────────────────────────────────
$cfg = get_points_config();
$meta = _ref_event_meta();

$eventLabels = [
    'registration'            => 'Registration',
    'email_verification'      => 'Email Verification',
    'phone_verification'      => 'Phone Verification',
    'profile_photo'           => 'Upload Profile Photo',
    'referral_registers'      => 'Referral Registers',
    'referral_email_verified' => 'Referral Verifies Email',
    'referral_first_payment'  => 'Referral Makes First Payment',
    'hire_worker'             => 'Hire Worker',
    'mark_job_completed'      => 'Mark Job Completed',
    'leave_review'            => 'Leave Review',
    'complete_job'            => 'Complete Job',
    'five_star_rating'        => 'Receive 5-Star Rating',
    'news_approved'           => 'News Article Approved',
    'event_approved'          => 'Event Approved',
];

$groups = [
    'Account Activities'  => ['registration','email_verification','phone_verification','profile_photo'],
    'Referral Activities' => ['referral_registers','referral_email_verified','referral_first_payment'],
    'Job Activities (Client)' => ['hire_worker','mark_job_completed','leave_review'],
    'Worker Activities'   => ['complete_job','five_star_rating'],
    'Content Activities'  => ['news_approved','event_approved'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Referrals &amp; Points — Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css" />
    <style>
        .ref-nav { display:flex; gap:4px; padding:0 0 16px; flex-wrap:wrap; }
        .ref-nav a { padding:6px 14px; border-radius:var(--radius-sm); background:var(--surface-muted); color:var(--text); text-decoration:none; font-size:0.88rem; border:1px solid var(--border); }
        .ref-nav a.active { background:var(--primary); color:#fff; border-color:var(--primary); }
        .stat-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:10px; margin-bottom:20px; }
        .stat-box { background:var(--surface-muted); border:1px solid var(--border); border-radius:var(--radius-sm); padding:12px 14px; }
        .stat-box .val { font-size:1.4rem; font-weight:700; color:var(--primary); margin:0 0 2px; }
        .stat-box .lbl { font-size:0.78rem; color:var(--muted); margin:0; }
        .config-group { margin-bottom:24px; }
        .config-group h3 { font-size:0.82rem; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:.05em; margin:0 0 10px; }
        .config-row { display:grid; grid-template-columns:1fr 90px 90px; gap:10px; align-items:center; padding:8px 0; border-bottom:1px solid var(--border); font-size:0.9rem; }
        .config-row:last-child { border-bottom:none; }
        .config-row input[type=number] { width:100%; padding:6px 8px; border:1px solid var(--border); border-radius:4px; background:var(--surface); color:var(--text); text-align:center; font-size:0.9rem; }
        .config-row input[type=number][disabled] { opacity:0.4; cursor:not-allowed; }
        .once-tag { display:inline-block; font-size:0.72rem; padding:1px 5px; border-radius:3px; background:var(--surface-muted); border:1px solid var(--border); color:var(--muted); margin-left:4px; }
        .col-header { font-size:0.75rem; font-weight:600; color:var(--muted); text-align:center; }
        table.data-table { width:100%; border-collapse:collapse; font-size:0.85rem; }
        table.data-table th { text-align:left; padding:8px 8px; border-bottom:2px solid var(--border); font-size:0.78rem; color:var(--muted); text-transform:uppercase; }
        table.data-table td { padding:8px 8px; border-bottom:1px solid var(--border); }
        .pts-chip { background:var(--primary); color:#fff; border-radius:12px; padding:2px 8px; font-size:0.78rem; font-weight:600; white-space:nowrap; }
        .pagination { display:flex; gap:4px; flex-wrap:wrap; align-items:center; margin-top:14px; }
        .pagination a, .pagination span { padding:5px 10px; border-radius:6px; border:1px solid var(--border); text-decoration:none; font-size:.82rem; color:var(--text); }
        .pagination a:hover { background:var(--surface-muted,#f9fafb); }
        .pagination .current { background:var(--primary,#0f766e); color:#fff; border-color:var(--primary,#0f766e); }
        .rwd-module-nav { display:flex; gap:6px; margin-bottom:16px; flex-wrap:wrap; }
        .rwd-module-nav a { padding:6px 14px; border-radius:20px; background:var(--surface-muted,#f3f4f6); border:1px solid var(--border); font-size:.82rem; font-weight:700; text-decoration:none; color:var(--text-muted,#6b7280); }
        .rwd-module-nav a.active { background:var(--primary,#0f766e); color:#fff; border-color:var(--primary,#0f766e); }
    </style>
</head>
<body>
    <header class="topbar">
        <a href="index.php" style="text-decoration:none;color:inherit;font-weight:700;">‹ Admin</a>
        <span style="font-weight:700;margin-left:12px;">Referrals &amp; Points</span>
        <div class="topbar-actions">
            <a href="../logout.php" class="button button-secondary button-small">Logout</a>
        </div>
    </header>
    <main class="page-shell" style="padding-bottom:40px;">
        <?php foreach (get_flashes() as $msg): ?>
            <div class="alert alert-<?php echo sanitize($msg['type']); ?>"><?php echo $msg['message']; ?></div>
        <?php endforeach; ?>

        <div class="rwd-module-nav">
            <a href="referrals.php" class="active">🔗 Referrals &amp; Points</a>
            <a href="reward_milestones.php">🏁 Reward Milestones</a>
            <a href="reward_claims.php">🎁 Reward Claims</a>
        </div>

        <nav class="ref-nav">
            <a href="referrals.php?tab=config"       class="<?php echo $tab==='config'       ? 'active':'' ?>">Config</a>
            <a href="referrals.php?tab=transactions"  class="<?php echo $tab==='transactions'  ? 'active':'' ?>">Transactions</a>
            <a href="referrals.php?tab=referrals"     class="<?php echo $tab==='referrals'     ? 'active':'' ?>">Referrals</a>
            <a href="referrals.php?tab=leaderboard"   class="<?php echo $tab==='leaderboard'   ? 'active':'' ?>">Leaderboard</a>
        </nav>

        <!-- Stats (always visible) -->
        <div class="stat-grid">
            <div class="stat-box"><p class="val"><?php echo number_format($stats['total_points_issued']); ?></p><p class="lbl">Total Points Issued</p></div>
            <div class="stat-box"><p class="val"><?php echo number_format($stats['total_referrals']); ?></p><p class="lbl">Total Referrals</p></div>
            <div class="stat-box"><p class="val"><?php echo number_format($stats['paid_referrals']); ?></p><p class="lbl">Paid Referrals</p></div>
            <div class="stat-box"><p class="val"><?php echo number_format($stats['total_clicks']); ?></p><p class="lbl">Link Clicks</p></div>
            <div class="stat-box"><p class="val"><?php echo number_format($stats['wallets']); ?></p><p class="lbl">Users with Points</p></div>
            <div class="stat-box"><p class="val"><?php echo (int)get_platform_setting('referrals_enabled',1) ? '<span style="color:#16a34a;">On</span>' : '<span style="color:#dc2626;">Off</span>'; ?></p><p class="lbl">Module Status</p></div>
        </div>

        <?php if ($tab === 'config'): ?>
        <!-- ── Config tab ───────────────────────────────────────────────────── -->
        <form method="post" action="referrals.php?tab=config" style="max-width:680px;">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="save_config" />

            <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;padding:14px;background:var(--surface-muted);border:1px solid var(--border);border-radius:var(--radius-sm);">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:600;">
                    <input type="checkbox" name="referrals_enabled" value="1"
                           <?php echo get_platform_setting('referrals_enabled',1) ? 'checked' : ''; ?>
                           style="width:18px;height:18px;" />
                    Referrals &amp; Points module enabled
                </label>
                <span class="meta">Uncheck to disable all point-awarding without deleting data.</span>
            </div>

            <?php foreach ($groups as $groupLabel => $events): ?>
            <div class="config-group">
                <h3><?php echo $groupLabel; ?></h3>
                <div class="config-row" style="border-bottom:1px solid var(--border);">
                    <span></span>
                    <span class="col-header">Points</span>
                    <span class="col-header">Daily cap</span>
                </div>
                <?php foreach ($events as $event):
                    [$defPts, $oneTime, $defCap] = $meta[$event];
                    $curPts = $cfg[$event]['points'];
                    $curCap = $cfg[$event]['cap'];
                ?>
                <div class="config-row">
                    <span>
                        <?php echo sanitize($eventLabels[$event] ?? $event); ?>
                        <?php if ($oneTime): ?><span class="once-tag">one-time</span><?php endif; ?>
                    </span>
                    <input type="number" name="pts_<?php echo $event; ?>" value="<?php echo $curPts; ?>" min="0" max="9999" />
                    <input type="number" name="cap_<?php echo $event; ?>"
                           value="<?php echo $defCap > 0 ? $curCap : ''; ?>"
                           min="0" max="9999"
                           <?php echo $defCap === 0 ? 'disabled placeholder="—"' : ''; ?> />
                </div>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>

            <button type="submit" class="button button-primary">Save Configuration</button>
        </form>

        <?php elseif ($tab === 'transactions'): ?>
        <!-- ── Transactions tab ─────────────────────────────────────────────── -->
        <form method="get" action="referrals.php" style="display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap;">
            <input type="hidden" name="tab" value="transactions" />
            <input type="text" name="q" value="<?php echo sanitize($_GET['q'] ?? ''); ?>"
                   placeholder="Search by name, email, or event…" style="flex:1;min-width:200px;padding:7px 10px;border:1px solid var(--border);border-radius:4px;background:var(--surface);color:var(--text);" />
            <button type="submit" class="button button-primary">Search</button>
        </form>

        <?php if (empty($transactions)): ?>
            <p class="meta" style="text-align:center;padding:30px 0;">No transactions found.</p>
        <?php else: ?>
        <div style="overflow-x:auto;">
            <table class="data-table">
                <thead><tr>
                    <th>User</th><th>Event</th><th style="text-align:right;">Points</th><th>Date</th>
                </tr></thead>
                <tbody>
                    <?php foreach ($transactions as $tx): ?>
                    <tr>
                        <td>
                            <?php echo sanitize($tx['user_name']); ?><br>
                            <span class="meta"><?php echo sanitize($tx['user_email']); ?></span>
                        </td>
                        <td><?php echo sanitize(ucwords(str_replace('_',' ',$tx['event']))); ?></td>
                        <td style="text-align:right;"><span class="pts-chip">+<?php echo $tx['points']; ?></span></td>
                        <td style="white-space:nowrap;font-size:0.82rem;"><?php echo date('d M Y, g:i a', strtotime($tx['created_at'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($txTotalPages > 1): ?>
        <div class="pagination">
            <?php if ($txPage > 1): ?><a href="<?php echo refadm_qstr(['page' => $txPage - 1]); ?>">‹ Prev</a><?php endif; ?>
            <?php
            $tpStart = max(1, $txPage - 3);
            $tpEnd   = min($txTotalPages, $txPage + 3);
            if ($tpStart > 1) echo '<span>…</span>';
            for ($tp = $tpStart; $tp <= $tpEnd; $tp++): ?>
                <?php if ($tp === $txPage): ?><span class="current"><?php echo $tp; ?></span>
                <?php else: ?><a href="<?php echo refadm_qstr(['page' => $tp]); ?>"><?php echo $tp; ?></a><?php endif; ?>
            <?php endfor;
            if ($tpEnd < $txTotalPages) echo '<span>…</span>';
            ?>
            <?php if ($txPage < $txTotalPages): ?><a href="<?php echo refadm_qstr(['page' => $txPage + 1]); ?>">Next ›</a><?php endif; ?>
            <span style="color:var(--muted);border:none;padding-left:4px;">Page <?php echo $txPage; ?> of <?php echo $txTotalPages; ?> (<?php echo $txTotal; ?> total)</span>
        </div>
        <?php endif; ?>
        <?php endif; ?>

        <?php elseif ($tab === 'referrals'): ?>
        <!-- ── Referrals tab ─────────────────────────────────────────────────── -->
        <?php if (empty($refRows)): ?>
            <p class="meta" style="text-align:center;padding:30px 0;">No referrals yet.</p>
        <?php else: ?>
        <div style="overflow-x:auto;">
            <table class="data-table">
                <thead><tr>
                    <th>Referrer</th><th>Referred</th><th>Code</th>
                    <th style="text-align:center;">Email ✓</th>
                    <th style="text-align:center;">Paid ✓</th>
                    <th>Joined</th>
                </tr></thead>
                <tbody>
                    <?php foreach ($refRows as $r): ?>
                    <tr>
                        <td><?php echo sanitize($r['referrer_name']); ?><br><span class="meta"><?php echo sanitize($r['referrer_email']); ?></span></td>
                        <td><?php echo sanitize($r['referred_name']); ?><br><span class="meta"><?php echo sanitize($r['referred_email']); ?></span></td>
                        <td style="font-family:monospace;font-size:0.85rem;"><?php echo sanitize($r['code']); ?></td>
                        <td style="text-align:center;"><?php echo $r['email_verified_at'] ? '✅' : '—'; ?></td>
                        <td style="text-align:center;"><?php echo $r['first_payment_at'] ? '✅' : '—'; ?></td>
                        <td style="white-space:nowrap;font-size:0.82rem;"><?php echo date('d M Y', strtotime($r['created_at'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($refTotalPages > 1): ?>
        <div class="pagination">
            <?php if ($refPage > 1): ?><a href="<?php echo refadm_qstr(['page' => $refPage - 1]); ?>">‹ Prev</a><?php endif; ?>
            <?php
            $rpStart = max(1, $refPage - 3);
            $rpEnd   = min($refTotalPages, $refPage + 3);
            if ($rpStart > 1) echo '<span>…</span>';
            for ($rp = $rpStart; $rp <= $rpEnd; $rp++): ?>
                <?php if ($rp === $refPage): ?><span class="current"><?php echo $rp; ?></span>
                <?php else: ?><a href="<?php echo refadm_qstr(['page' => $rp]); ?>"><?php echo $rp; ?></a><?php endif; ?>
            <?php endfor;
            if ($rpEnd < $refTotalPages) echo '<span>…</span>';
            ?>
            <?php if ($refPage < $refTotalPages): ?><a href="<?php echo refadm_qstr(['page' => $refPage + 1]); ?>">Next ›</a><?php endif; ?>
            <span style="color:var(--muted);border:none;padding-left:4px;">Page <?php echo $refPage; ?> of <?php echo $refTotalPages; ?> (<?php echo $refTotal; ?> total)</span>
        </div>
        <?php endif; ?>
        <?php endif; ?>

        <?php elseif ($tab === 'leaderboard'): ?>
        <!-- ── Leaderboard tab ───────────────────────────────────────────────── -->
        <p style="font-weight:600;margin:0 0 12px;">Top 10 Point Earners</p>
        <?php if (empty($topEarners)): ?>
            <p class="meta" style="text-align:center;padding:30px 0;">No wallets yet.</p>
        <?php else: ?>
        <div style="overflow-x:auto;">
            <table class="data-table">
                <thead><tr>
                    <th>#</th><th>User</th><th style="text-align:right;">Balance</th><th style="text-align:right;">Total Earned</th>
                </tr></thead>
                <tbody>
                    <?php foreach ($topEarners as $i => $u): ?>
                    <tr>
                        <td style="color:var(--muted);"><?php echo $i+1; ?></td>
                        <td><?php echo sanitize($u['name']); ?><br><span class="meta"><?php echo sanitize($u['email']); ?></span></td>
                        <td style="text-align:right;font-weight:700;color:var(--primary);"><?php echo number_format($u['balance']); ?></td>
                        <td style="text-align:right;"><?php echo number_format($u['total_earned']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        <?php endif; ?>

    </main>
</body>
</html>
