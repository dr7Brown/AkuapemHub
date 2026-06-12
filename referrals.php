<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/modules/referrals/service.php';

require_login();
$user = current_user();

if (!referrals_enabled()) {
    flash('The referral programme is not currently active.', 'info');
    header('Location: dashboard.php');
    exit;
}

$userId      = (int)$user['id'];
$balance     = get_points_balance($userId);
$refCode     = get_or_create_referral_code($userId);
$refUrl      = rtrim(BASE_URL, '/') . '/register.php?ref=' . $refCode;
$history     = get_user_points_history($userId, 40);
$referrals   = get_user_referrals($userId);
$pointsCfg   = get_points_config();

// Referral stats
$refTotal    = count($referrals);
$refVerified = count(array_filter($referrals, fn($r) => !empty($r['email_verified_at'])));
$refPaid     = count(array_filter($referrals, fn($r) => !empty($r['first_payment_at'])));

// Click stats from referral_codes table
$clickStmt = $pdo->prepare("SELECT clicks FROM referral_codes WHERE user_id=?");
$clickStmt->execute([$userId]);
$totalClicks = (int)($clickStmt->fetchColumn() ?: 0);

// Human-readable event labels
$eventLabels = [
    'registration'            => 'Account created',
    'email_verification'      => 'Email verified',
    'phone_verification'      => 'Phone verified',
    'profile_photo'           => 'Profile photo uploaded',
    'referral_registers'      => 'Friend joined via your link',
    'referral_email_verified' => 'Friend verified email',
    'referral_first_payment'  => 'Friend made first payment',
    'hire_worker'             => 'Hired a worker',
    'mark_job_completed'      => 'Marked job completed',
    'leave_review'            => 'Left a review',
    'complete_job'            => 'Completed a job',
    'five_star_rating'        => 'Received 5-star rating',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Points &amp; Referrals — AkuapemHub</title>
    <link rel="stylesheet" href="assets/css/style.css" />
    <style>
        .ref-hero { background:var(--primary); color:#fff; border-radius:var(--radius); padding:28px 24px; margin-bottom:20px; text-align:center; }
        .ref-hero .pts { font-size:3.2rem; font-weight:800; letter-spacing:-1px; line-height:1; }
        .ref-hero .lbl { font-size:0.95rem; opacity:0.85; margin:4px 0 0; }
        .ref-card { background:var(--surface-muted); border:1px solid var(--border); border-radius:var(--radius-sm); padding:20px; margin-bottom:16px; }
        .ref-link-row { display:flex; gap:8px; align-items:center; margin-top:10px; flex-wrap:wrap; }
        .ref-link-input { flex:1; min-width:0; font-size:0.85rem; padding:9px 12px; border:1px solid var(--border); border-radius:var(--radius-sm); background:var(--surface); color:var(--text); font-family:monospace; }
        .stat-row { display:grid; grid-template-columns:repeat(auto-fit,minmax(110px,1fr)); gap:10px; margin-bottom:20px; }
        .stat-pill { background:var(--surface-muted); border:1px solid var(--border); border-radius:var(--radius-sm); padding:12px; text-align:center; }
        .stat-pill .num { font-size:1.6rem; font-weight:700; color:var(--primary); margin:0 0 2px; }
        .stat-pill .lbl { font-size:0.78rem; color:var(--muted); margin:0; }
        .earn-table { width:100%; border-collapse:collapse; font-size:0.87rem; }
        .earn-table td { padding:7px 6px; border-bottom:1px solid var(--border); }
        .earn-table tr:last-child td { border-bottom:none; }
        .earn-table .pts-badge { display:inline-block; background:var(--primary); color:#fff; border-radius:20px; padding:2px 9px; font-size:0.8rem; font-weight:600; white-space:nowrap; }
        .once-badge { display:inline-block; background:var(--surface-muted); border:1px solid var(--border); color:var(--muted); border-radius:3px; padding:1px 5px; font-size:0.72rem; margin-left:4px; }
        .history-row { display:flex; justify-content:space-between; align-items:center; padding:8px 0; border-bottom:1px solid var(--border); font-size:0.88rem; }
        .history-row:last-child { border-bottom:none; }
        .referral-row td { padding:7px 8px; border-bottom:1px solid var(--border); font-size:0.86rem; }
        .btn-copy,.btn-share { display:inline-flex; align-items:center; gap:5px; white-space:nowrap; }
        .copy-ok { color:#16a34a; font-size:0.82rem; display:none; margin-left:4px; }
    </style>
</head>
<body class="has-bottom-nav">
    <header class="app-topbar">
        <a href="dashboard.php" class="brand" style="text-decoration:none;">‹ Dashboard</a>
        <span style="font-weight:600;">Points &amp; Referrals</span>
    </header>
    <main class="page-shell" style="padding-bottom:80px;">

        <?php foreach (get_flashes() as $msg): ?>
            <div class="alert alert-<?php echo sanitize($msg['type']); ?>"><?php echo $msg['message']; ?></div>
        <?php endforeach; ?>

        <!-- Points balance hero -->
        <div class="ref-hero">
            <p class="pts"><?php echo number_format($balance); ?></p>
            <p class="lbl">Your points balance</p>
        </div>

        <!-- Stats row -->
        <div class="stat-row">
            <div class="stat-pill">
                <p class="num"><?php echo $totalClicks; ?></p>
                <p class="lbl">Link clicks</p>
            </div>
            <div class="stat-pill">
                <p class="num"><?php echo $refTotal; ?></p>
                <p class="lbl">Friends joined</p>
            </div>
            <div class="stat-pill">
                <p class="num"><?php echo $refVerified; ?></p>
                <p class="lbl">Verified</p>
            </div>
            <div class="stat-pill">
                <p class="num"><?php echo $refPaid; ?></p>
                <p class="lbl">Paid</p>
            </div>
        </div>

        <!-- Referral link card -->
        <div class="ref-card">
            <p style="font-weight:700;margin:0 0 4px;">Invite friends, earn points</p>
            <p class="meta" style="margin:0 0 10px;">Share your unique link. You earn points when friends join, verify their email, and make their first payment.</p>

            <div class="ref-link-row">
                <input type="text" class="ref-link-input" id="refUrl" value="<?php echo htmlspecialchars($refUrl); ?>" readonly onclick="this.select()" />
                <button class="button button-primary btn-copy" onclick="copyLink()">
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
                    Copy
                </button>
                <button class="button button-secondary btn-share" id="shareBtn" onclick="shareLink()" style="display:none;">
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>
                    Share
                </button>
            </div>
            <span class="copy-ok" id="copyOk">✓ Copied!</span>

            <p class="meta" style="margin:10px 0 0;">Your code: <strong><?php echo htmlspecialchars($refCode); ?></strong></p>
        </div>

        <!-- Earn points guide -->
        <p style="font-weight:700;margin:0 0 10px;">How to earn points</p>

        <div class="ref-card" style="padding:14px 16px;">
            <p style="font-size:0.8rem;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin:0 0 8px;">Referrals</p>
            <table class="earn-table">
                <?php
                $referralEvents = ['referral_registers','referral_email_verified','referral_first_payment'];
                foreach ($referralEvents as $ev):
                    $pts = $pointsCfg[$ev]['points'] ?? 0;
                    if ($pts <= 0) continue;
                ?>
                <tr>
                    <td><?php echo sanitize($eventLabels[$ev] ?? $ev); ?></td>
                    <td style="text-align:right;"><span class="pts-badge">+<?php echo $pts; ?> pts</span></td>
                </tr>
                <?php endforeach; ?>
            </table>

            <p style="font-size:0.8rem;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin:14px 0 8px;">Account setup <span class="once-badge">one-time</span></p>
            <table class="earn-table">
                <?php
                $accountEvents = ['registration','email_verification','phone_verification','profile_photo'];
                foreach ($accountEvents as $ev):
                    $pts = $pointsCfg[$ev]['points'] ?? 0;
                    if ($pts <= 0) continue;
                ?>
                <tr>
                    <td><?php echo sanitize($eventLabels[$ev] ?? $ev); ?></td>
                    <td style="text-align:right;"><span class="pts-badge">+<?php echo $pts; ?> pts</span></td>
                </tr>
                <?php endforeach; ?>
            </table>

            <p style="font-size:0.8rem;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin:14px 0 8px;">Job activity</p>
            <table class="earn-table">
                <?php
                $jobEvents = ['hire_worker','mark_job_completed','leave_review','complete_job','five_star_rating'];
                foreach ($jobEvents as $ev):
                    $pts = $pointsCfg[$ev]['points'] ?? 0;
                    $cap = $pointsCfg[$ev]['cap'] ?? 0;
                    if ($pts <= 0) continue;
                ?>
                <tr>
                    <td>
                        <?php echo sanitize($eventLabels[$ev] ?? $ev); ?>
                        <?php if ($cap > 0): ?><span class="once-badge">cap <?php echo $cap; ?>/day</span><?php endif; ?>
                    </td>
                    <td style="text-align:right;"><span class="pts-badge">+<?php echo $pts; ?> pts</span></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>

        <!-- Points history -->
        <?php if ($history): ?>
        <p style="font-weight:700;margin:16px 0 10px;">Recent activity</p>
        <div class="ref-card" style="padding:10px 16px;">
            <?php foreach ($history as $tx): ?>
                <div class="history-row">
                    <div>
                        <span><?php echo sanitize($eventLabels[$tx['event']] ?? ucwords(str_replace('_',' ',$tx['event']))); ?></span>
                        <br><span class="meta" style="font-size:0.78rem;"><?php echo date('d M Y, g:i a', strtotime($tx['created_at'])); ?></span>
                    </div>
                    <span style="font-weight:700;color:var(--primary);white-space:nowrap;">+<?php echo $tx['points']; ?> pts</span>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Referrals list -->
        <?php if ($referrals): ?>
        <p style="font-weight:700;margin:16px 0 10px;">Your referrals</p>
        <div style="overflow-x:auto;margin-bottom:16px;">
            <table style="width:100%;border-collapse:collapse;font-size:0.85rem;">
                <thead>
                    <tr style="border-bottom:2px solid var(--border);">
                        <th style="text-align:left;padding:7px 8px;">Name</th>
                        <th style="padding:7px 4px;">Joined</th>
                        <th style="padding:7px 4px;">Email</th>
                        <th style="padding:7px 4px;">Paid</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($referrals as $r): ?>
                    <tr class="referral-row">
                        <td><?php echo sanitize($r['referred_name']); ?></td>
                        <td style="text-align:center;">✅</td>
                        <td style="text-align:center;"><?php echo $r['email_verified_at'] ? '✅' : '—'; ?></td>
                        <td style="text-align:center;"><?php echo $r['first_payment_at'] ? '✅' : '—'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

    </main>

    <script>
    var referralUrl = <?php echo json_encode($refUrl); ?>;

    // Show share button if Web Share API is available
    if (navigator.share) {
        document.getElementById('shareBtn').style.display = 'inline-flex';
    }

    async function copyLink() {
        try {
            await navigator.clipboard.writeText(referralUrl);
        } catch(e) {
            // Fallback: select the input text
            var inp = document.getElementById('refUrl');
            inp.select();
            inp.setSelectionRange(0, 99999);
            document.execCommand('copy');
        }
        var ok = document.getElementById('copyOk');
        ok.style.display = 'inline';
        setTimeout(function(){ ok.style.display = 'none'; }, 2000);
    }

    async function shareLink() {
        try {
            await navigator.share({
                title: 'Join AkuapemHub',
                text: 'I use AkuapemHub to find and hire skilled workers. Join with my link:',
                url: referralUrl
            });
        } catch(e) {
            // User cancelled or API not available — fall back to copy
            copyLink();
        }
    }
    </script>
</body>
</html>
