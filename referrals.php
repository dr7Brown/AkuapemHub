<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/modules/referrals/service.php';
require_once __DIR__ . '/modules/rewards/service.php';

require_login();
$user = current_user();

if (!referrals_enabled()) {
    flash('The referral programme is not currently active.', 'info');
    header('Location: jobs.php');
    exit;
}

$rewardsOn = rewards_enabled();
$dash = $rewardsOn ? get_user_reward_dashboard((int)$user['id']) : null;

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
    'news_approved'           => 'News article approved',
    'event_approved'          => 'Event approved',
    'funeral_approved'        => 'Funeral announcement approved',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Points, Referrals &amp; Rewards — AkuapemConnect</title>
    <link rel="stylesheet" href="assets/css/style.css" />
    <style>
        @keyframes pgRise { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:translateY(0); } }
        @media (prefers-reduced-motion: no-preference) {
            .pg-anim { animation:pgRise .45s ease both; }
        }

        /* ── Hero ─────────────────────────────────────────────────────── */
        .pg-hero {
            position:relative; overflow:hidden; isolation:isolate;
            background:linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            border-radius:var(--radius-lg); padding:32px 24px 44px; margin-bottom:0;
            color:#fff; text-align:center;
        }
        .pg-hero::before, .pg-hero::after {
            content:''; position:absolute; border-radius:50%; z-index:-1;
            background:rgba(255,255,255,.08);
        }
        .pg-hero::before { width:220px; height:220px; top:-90px; right:-60px; }
        .pg-hero::after { width:160px; height:160px; bottom:-70px; left:-40px; background:rgba(255,255,255,.06); }
        .pg-hero-eyebrow { font-size:.78rem; font-weight:700; text-transform:uppercase; letter-spacing:.1em; opacity:.8; margin:0; }
        .pg-hero .pts { font-size:3.4rem; font-weight:800; letter-spacing:-1.5px; line-height:1; margin:6px 0 0; }
        .pg-hero .lbl { font-size:0.95rem; opacity:0.88; margin:4px 0 0; }
        .pg-hero-cta {
            display:inline-flex; align-items:center; gap:6px; margin-top:16px; padding:10px 22px;
            background:#fff; color:var(--primary-dark); font-weight:700; border-radius:999px;
            text-decoration:none; box-shadow:0 6px 16px rgba(0,0,0,.15); transition:transform .15s, box-shadow .15s;
        }
        .pg-hero-cta:hover { transform:translateY(-2px); box-shadow:0 10px 22px rgba(0,0,0,.2); text-decoration:none; }

        /* Glass stat chips, overlapping the bottom edge of the hero */
        .pg-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(110px,1fr)); gap:10px; margin:-28px 6px 26px; position:relative; z-index:1; }
        .pg-stat {
            background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-md);
            padding:14px 10px; text-align:center; box-shadow:0 8px 20px rgba(20,60,40,.08);
        }
        .pg-stat .num { font-size:1.5rem; font-weight:800; color:var(--primary); margin:0; }
        .pg-stat .lbl { font-size:0.74rem; color:var(--muted); margin:2px 0 0; }

        /* ── Section header ───────────────────────────────────────────── */
        .pg-section-head { display:flex; align-items:center; gap:10px; margin:30px 0 14px; }
        .pg-section-icon {
            width:34px; height:34px; border-radius:10px; display:flex; align-items:center; justify-content:center;
            font-size:1.05rem; flex-shrink:0; background:var(--primary-soft); color:var(--primary-dark);
        }
        .pg-section-icon.orange { background:var(--secondary-soft); color:#c2410c; }
        .pg-section-title { font-weight:800; font-size:1.05rem; margin:0; }
        .pg-section-sub { font-size:0.8rem; color:var(--muted); margin:1px 0 0; }

        /* ── Referral link card ───────────────────────────────────────── */
        .pg-link-card {
            background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-lg);
            padding:22px; box-shadow:0 2px 10px rgba(20,60,40,.04);
        }
        .ref-link-row { display:flex; gap:8px; align-items:center; margin-top:12px; flex-wrap:wrap; }
        .ref-link-input {
            flex:1; min-width:0; font-size:0.85rem; padding:11px 14px; border:1.5px dashed var(--border-strong);
            border-radius:var(--radius-sm); background:var(--surface-muted); color:var(--text); font-family:monospace;
        }
        .btn-copy,.btn-share { display:inline-flex; align-items:center; gap:6px; white-space:nowrap; }
        .copy-ok { color:var(--primary); font-weight:700; font-size:0.82rem; display:none; margin-left:4px; }
        .pg-code-chip {
            display:inline-flex; align-items:center; gap:6px; margin-top:12px; padding:6px 14px;
            background:var(--primary-soft); color:var(--primary-dark); border-radius:999px; font-weight:700;
            font-family:monospace; font-size:0.85rem; letter-spacing:.03em;
        }

        /* ── Rewards ──────────────────────────────────────────────────── */
        .mr-summary {
            display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;
            background:linear-gradient(120deg, var(--primary-soft) 0%, #fff 100%);
            border:1px solid var(--border); border-radius:var(--radius-lg); padding:18px 22px; margin-bottom:18px;
        }
        .mr-summary .pts { font-size:1.9rem; font-weight:800; color:var(--primary-dark); line-height:1; }
        .mr-summary .lbl { font-size:0.82rem; color:var(--muted); margin:2px 0 0; }
        .mr-summary .locked { font-size:0.78rem; color:#b45309; background:var(--secondary-soft); padding:5px 12px; border-radius:999px; font-weight:600; }

        .mr-card {
            position:relative; background:var(--surface); border:2px solid #d4af37; border-radius:var(--radius-md);
            padding:16px 18px; margin-bottom:12px; overflow:hidden; transition:box-shadow .15s, transform .15s, border-color .15s;
        }
        .mr-card::before { content:''; position:absolute; left:0; top:0; bottom:0; width:4px; background:#d4af37; }
        .mr-card.available { border-color:var(--primary); box-shadow:0 4px 16px rgba(47,143,91,.15); }
        .mr-card.available::before { background:linear-gradient(180deg, var(--primary) 0%, var(--primary-dark) 100%); }
        .mr-card.available:hover { transform:translateY(-2px); box-shadow:0 8px 22px rgba(47,143,91,.2); }
        .mr-card.locked-card { opacity:.72; border-color:var(--border-strong); }
        .mr-card.locked-card::before { background:var(--border-strong); }
        .mr-ribbon {
            position:absolute; top:10px; right:-30px; transform:rotate(35deg); background:var(--primary);
            color:#fff; font-size:0.62rem; font-weight:800; letter-spacing:.06em; padding:3px 34px;
        }
        .mr-card-title { font-weight:700; font-size:1rem; margin:0 0 4px; }
        .mr-progress-track { background:var(--surface-muted); border-radius:20px; height:9px; overflow:hidden; margin:12px 0 6px; }
        .mr-progress-fill { background:linear-gradient(90deg, var(--primary) 0%, var(--secondary) 100%); height:100%; border-radius:20px; transition:width .4s ease; }
        .mr-progress-text { font-size:0.8rem; color:var(--muted); }
        .mr-lock-reason { font-size:0.82rem; color:var(--muted); margin-top:6px; }
        .mr-lock-reason::before { content:'🔒 '; }
        .mr-empty { text-align:center; padding:26px 16px; color:var(--muted); background:var(--surface-muted); border-radius:var(--radius-md); }
        .mr-card-head { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; }
        .mr-card-head > div:first-child { min-width:0; flex:1; }
        .mr-claim-btn { flex:0 0 auto; white-space:nowrap; border-radius:999px !important; }
        .mr-claim-btn[disabled] { background:var(--surface-muted); color:var(--muted); border-color:var(--border); cursor:not-allowed; opacity:0.8; }

        /* ── Earn-points guide ────────────────────────────────────────── */
        .earn-grid { display:grid; grid-template-columns:1fr; gap:14px; }
        .earn-card { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-lg); padding:18px 20px; }
        .earn-card-head { display:flex; align-items:center; gap:8px; margin-bottom:10px; }
        .earn-card-head .ic { font-size:1.15rem; }
        .earn-card-head h4 { margin:0; font-size:0.9rem; font-weight:800; }
        .earn-item { display:flex; justify-content:space-between; align-items:center; padding:8px 0; border-bottom:1px solid var(--border); font-size:0.87rem; }
        .earn-item:last-child { border-bottom:none; }
        .pts-badge { display:inline-block; background:var(--primary); color:#fff; border-radius:20px; padding:3px 10px; font-size:0.78rem; font-weight:700; white-space:nowrap; }
        .once-badge { display:inline-block; background:var(--surface-muted); border:1px solid var(--border); color:var(--muted); border-radius:3px; padding:1px 6px; font-size:0.68rem; margin-left:5px; vertical-align:middle; }

        /* ── Activity timeline ────────────────────────────────────────── */
        .timeline { position:relative; padding-left:20px; }
        .timeline::before { content:''; position:absolute; left:5px; top:6px; bottom:6px; width:2px; background:var(--border); }
        .timeline-row { position:relative; padding:9px 0; }
        .timeline-row::before { content:''; position:absolute; left:-19px; top:15px; width:9px; height:9px; border-radius:50%; background:var(--primary); box-shadow:0 0 0 3px var(--primary-soft); }
        .timeline-row .trow-inner { display:flex; justify-content:space-between; align-items:center; gap:10px; }

        /* ── Referrals table ──────────────────────────────────────────── */
        .pg-table-wrap { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-lg); overflow:hidden; }
        .pg-table { width:100%; border-collapse:collapse; font-size:0.85rem; }
        .pg-table th { text-align:left; padding:10px 12px; background:var(--surface-muted); color:var(--muted); font-size:0.72rem; text-transform:uppercase; letter-spacing:.04em; }
        .pg-table td { padding:10px 12px; border-top:1px solid var(--border); }
        .pg-name { display:flex; align-items:center; gap:8px; font-weight:600; }
        .pg-avatar { width:26px; height:26px; border-radius:50%; background:var(--primary-soft); color:var(--primary-dark); display:flex; align-items:center; justify-content:center; font-size:0.72rem; font-weight:800; flex-shrink:0; }
        .pg-pill { display:inline-flex; align-items:center; padding:2px 10px; border-radius:999px; font-size:0.74rem; font-weight:700; }
        .pg-pill.yes { background:var(--primary-soft); color:var(--primary-dark); }
        .pg-pill.no { background:var(--surface-muted); color:var(--muted); }

        @media (min-width:760px) {
            .earn-grid { grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); }
        }
    </style>
</head>
<body class="has-bottom-nav">
    <header class="app-topbar">
        <a href="jobs.php" class="brand" style="text-decoration:none;">‹ Dashboard</a>
    </header>

    <main class="page-shell" style="padding-bottom:80px;">

        <?php foreach (get_flashes() as $msg): ?>
            <div class="alert alert-<?php echo sanitize($msg['type']); ?>"><?php echo $msg['message']; ?></div>
        <?php endforeach; ?>

        <!-- Points balance hero -->
        <div class="pg-hero pg-anim">
            <p class="pg-hero-eyebrow">Your balance</p>
            <p class="pts">⭐ <?php echo number_format($balance); ?></p>
            <p class="lbl">points earned so far</p>
            <?php if ($rewardsOn): ?>
            <a href="my_reward_claims.php" class="pg-hero-cta">🎁 Claim History</a>
            <?php endif; ?>
        </div>

        <!-- Stats -->
        <div class="pg-stats pg-anim">
            <div class="pg-stat">
                <p class="num"><?php echo $totalClicks; ?></p>
                <p class="lbl">🔗 Link clicks</p>
            </div>
            <div class="pg-stat">
                <p class="num"><?php echo $refTotal; ?></p>
                <p class="lbl">🤝 Friends joined</p>
            </div>
            <div class="pg-stat">
                <p class="num"><?php echo $refVerified; ?></p>
                <p class="lbl">✅ Verified</p>
            </div>
            <div class="pg-stat">
                <p class="num"><?php echo $refPaid; ?></p>
                <p class="lbl">💳 Paid</p>
            </div>
        </div>

        <!-- Referral link card -->
        <div class="pg-section-head pg-anim">
            <span class="pg-section-icon">🔗</span>
            <div>
                <p class="pg-section-title">Invite friends, earn points</p>
                <p class="pg-section-sub">Share your link — earn when friends join, verify, and pay.</p>
            </div>
        </div>
        <div class="pg-link-card pg-anim">
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

            <div><span class="pg-code-chip">🏷️ <?php echo htmlspecialchars($refCode); ?></span></div>
        </div>

        <?php if ($rewardsOn): ?>
        <div id="rewards">
            <div class="pg-section-head pg-anim">
                <span class="pg-section-icon orange">🎁</span>
                <div>
                    <p class="pg-section-title">Milestone Rewards</p>
                    <p class="pg-section-sub">Turn your points into real rewards.</p>
                </div>
            </div>

            <!-- Available -->
            <?php if (!$dash['available']): ?>
            <div class="mr-empty">Keep earning points. Your next reward is on the way! <a href="#earn-points">See how to earn more →</a></div>
            <?php else: foreach ($dash['available'] as $m): ?>
            <div class="mr-card available">
                <span class="mr-ribbon">READY</span>
                <div class="mr-card-head">
                    <div>
                        <p class="mr-card-title">🎁 <?php echo sanitize($m['reward_description']); ?></p>
                        <p class="meta"><?php echo sanitize($m['title']); ?> · Requires <?php echo number_format((int)$m['required_points']); ?> points</p>
                    </div>
                    <a href="reward_claim_form.php?id=<?php echo $m['id']; ?>" class="button button-primary button-small mr-claim-btn">CLAIM REWARD</a>
                </div>
            </div>
            <?php endforeach; endif; ?>

            <!-- Almost there -->
            <?php if ($dash['almost_there']): ?>
            <p class="pg-section-sub" style="margin:18px 0 8px;font-weight:700;color:var(--text);">🔥 Almost there</p>
            <?php foreach ($dash['almost_there'] as $m):
                $pct = min(100, (int)round(($dash['balance'] / max(1,$m['required_points'])) * 100));
                $toGo = max(0, (int)$m['required_points'] - $dash['balance']);
            ?>
            <div class="mr-card">
                <div class="mr-card-head">
                    <div>
                        <p class="mr-card-title"><?php echo sanitize($m['reward_description']); ?></p>
                        <p class="meta"><?php echo sanitize($m['title']); ?></p>
                    </div>
                    <button type="button" class="button button-primary button-small mr-claim-btn" disabled title="Reach <?php echo number_format((int)$m['required_points']); ?> points to unlock">CLAIM REWARD</button>
                </div>
                <div class="mr-progress-track"><div class="mr-progress-fill" style="width:<?php echo $pct; ?>%;"></div></div>
                <p class="mr-progress-text"><strong><?php echo $pct; ?>%</strong> · <?php echo number_format($dash['balance']); ?> / <?php echo number_format((int)$m['required_points']); ?> points — <?php echo number_format($toGo); ?> to go</p>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>

            <!-- Locked -->
            <?php if ($dash['locked']): ?>
            <p class="pg-section-sub" style="margin:18px 0 8px;font-weight:700;color:var(--text);">🔒 Locked</p>
            <?php foreach ($dash['locked'] as $m):
                $pct = min(100, (int)round(($dash['balance'] / max(1,$m['required_points'])) * 100));
            ?>
            <div class="mr-card locked-card">
                <div class="mr-card-head">
                    <div>
                        <p class="mr-card-title"><?php echo sanitize($m['reward_description']); ?></p>
                        <p class="meta"><?php echo sanitize($m['title']); ?></p>
                    </div>
                    <button type="button" class="button button-primary button-small mr-claim-btn" disabled title="<?php echo sanitize($m['_reason'] ?? ('Reach ' . number_format((int)$m['required_points']) . ' points to unlock')); ?>">CLAIM REWARD</button>
                </div>
                <?php if (!empty($m['_reason'])): ?>
                <p class="mr-lock-reason"><?php echo sanitize($m['_reason']); ?></p>
                <?php else: ?>
                <div class="mr-progress-track"><div class="mr-progress-fill" style="width:<?php echo $pct; ?>%;"></div></div>
                <p class="mr-progress-text"><?php echo number_format($dash['balance']); ?> / <?php echo number_format((int)$m['required_points']); ?> points</p>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>

            <?php if (!$dash['available'] && !$dash['almost_there'] && !$dash['locked']): ?>
            <div class="mr-empty">No milestone rewards are currently available.</div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Earn points guide -->
        <div class="pg-section-head pg-anim">
            <span class="pg-section-icon">📈</span>
            <div>
                <p class="pg-section-title" id="earn-points">How to earn points</p>
                <p class="pg-section-sub">Every one of these actions adds to your balance.</p>
            </div>
        </div>

        <div class="earn-grid pg-anim">
            <div class="earn-card">
                <div class="earn-card-head"><span class="ic">🤝</span><h4>Referrals</h4></div>
                <?php
                $referralEvents = ['referral_registers','referral_email_verified','referral_first_payment'];
                foreach ($referralEvents as $ev):
                    $pts = $pointsCfg[$ev]['points'] ?? 0;
                    if ($pts <= 0) continue;
                ?>
                <div class="earn-item">
                    <span><?php echo sanitize($eventLabels[$ev] ?? $ev); ?></span>
                    <span class="pts-badge">+<?php echo $pts; ?> pts</span>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="earn-card">
                <div class="earn-card-head"><span class="ic">🧾</span><h4>Account Setup <span class="once-badge">one-time</span></h4></div>
                <?php
                $accountEvents = ['registration','email_verification','phone_verification','profile_photo'];
                foreach ($accountEvents as $ev):
                    $pts = $pointsCfg[$ev]['points'] ?? 0;
                    if ($pts <= 0) continue;
                ?>
                <div class="earn-item">
                    <span><?php echo sanitize($eventLabels[$ev] ?? $ev); ?></span>
                    <span class="pts-badge">+<?php echo $pts; ?> pts</span>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="earn-card">
                <div class="earn-card-head"><span class="ic">🧰</span><h4>Job Activity</h4></div>
                <?php
                $jobEvents = ['hire_worker','mark_job_completed','leave_review','complete_job','five_star_rating'];
                foreach ($jobEvents as $ev):
                    $pts = $pointsCfg[$ev]['points'] ?? 0;
                    $cap = $pointsCfg[$ev]['cap'] ?? 0;
                    if ($pts <= 0) continue;
                ?>
                <div class="earn-item">
                    <span><?php echo sanitize($eventLabels[$ev] ?? $ev); ?><?php if ($cap > 0): ?><span class="once-badge">cap <?php echo $cap; ?>/day</span><?php endif; ?></span>
                    <span class="pts-badge">+<?php echo $pts; ?> pts</span>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="earn-card">
                <div class="earn-card-head"><span class="ic">📰</span><h4>Community Content</h4></div>
                <?php
                $contentEvents = ['news_approved','event_approved','funeral_approved'];
                foreach ($contentEvents as $ev):
                    $pts = $pointsCfg[$ev]['points'] ?? 0;
                    $cap = $pointsCfg[$ev]['cap'] ?? 0;
                    if ($pts <= 0) continue;
                ?>
                <div class="earn-item">
                    <span><?php echo sanitize($eventLabels[$ev] ?? $ev); ?><?php if ($cap > 0): ?><span class="once-badge">cap <?php echo $cap; ?>/day</span><?php endif; ?></span>
                    <span class="pts-badge">+<?php echo $pts; ?> pts</span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Points history -->
        <?php if ($history): ?>
        <div class="pg-section-head pg-anim">
            <span class="pg-section-icon">🕒</span>
            <div><p class="pg-section-title">Recent activity</p></div>
        </div>
        <div class="pg-link-card pg-anim">
            <div class="timeline">
                <?php foreach ($history as $tx): ?>
                    <div class="timeline-row">
                        <div class="trow-inner">
                            <div>
                                <span><?php echo sanitize($eventLabels[$tx['event']] ?? ucwords(str_replace('_',' ',$tx['event']))); ?></span>
                                <br><span class="meta" style="font-size:0.78rem;"><?php echo date('d M Y, g:i a', strtotime($tx['created_at'])); ?></span>
                            </div>
                            <span style="font-weight:700;color:var(--primary);white-space:nowrap;">+<?php echo $tx['points']; ?> pts</span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Referrals list -->
        <?php if ($referrals): ?>
        <div class="pg-section-head pg-anim">
            <span class="pg-section-icon">👥</span>
            <div><p class="pg-section-title">Your referrals</p></div>
        </div>
        <div class="pg-table-wrap pg-anim" style="overflow-x:auto;margin-bottom:16px;">
            <table class="pg-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Joined</th>
                        <th>Email</th>
                        <th>Paid</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($referrals as $r): ?>
                    <tr>
                        <td>
                            <span class="pg-name">
                                <span class="pg-avatar"><?php echo sanitize(strtoupper(mb_substr($r['referred_name'] ?? '?', 0, 1))); ?></span>
                                <?php echo sanitize($r['referred_name']); ?>
                            </span>
                        </td>
                        <td><span class="pg-pill yes">✅ Joined</span></td>
                        <td><?php echo $r['email_verified_at'] ? '<span class="pg-pill yes">✅ Verified</span>' : '<span class="pg-pill no">— Pending</span>'; ?></td>
                        <td><?php echo $r['first_payment_at'] ? '<span class="pg-pill yes">✅ Paid</span>' : '<span class="pg-pill no">— Not yet</span>'; ?></td>
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
                title: 'Join AkuapemConnect',
                text: 'I use AkuapemConnect to find and hire skilled workers. Join with my link:',
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
