<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../services/OtpService.php';

require_login();
if (!is_admin()) {
    header('Location: ../jobs.php');
    exit;
}

// ── Stats ────────────────────────────────────────────────────────────────────

$todayStart = date('Y-m-d') . ' 00:00:00';

$resetRequestsToday = (int) $pdo->query(
    "SELECT COUNT(*) FROM password_reset_otps WHERE created_at >= '{$todayStart}'"
)->fetchColumn();

$failedOtpToday = (int) $pdo->query(
    "SELECT COUNT(*) FROM security_logs WHERE action = 'otp_failed' AND created_at >= '{$todayStart}'"
)->fetchColumn();

$lockedOtps = (int) $pdo->query(
    'SELECT COUNT(*) FROM password_reset_otps WHERE status = \'expired\' AND attempts >= ' . OtpService::MAX_ATTEMPTS
)->fetchColumn();

// Suspicious IPs: >= 3 failures in last hour
$suspiciousHour = date('Y-m-d H:i:s', time() - 3600);
$suspStmt = $pdo->prepare(
    "SELECT ip_address, COUNT(*) AS cnt
     FROM security_logs
     WHERE action IN ('otp_failed', 'otp_rate_limited')
       AND created_at >= ?
     GROUP BY ip_address
     HAVING cnt >= 3
     ORDER BY cnt DESC
     LIMIT 20"
);
$suspStmt->execute([$suspiciousHour]);
$suspiciousIps = $suspStmt->fetchAll(PDO::FETCH_ASSOC);

// Recent security events
$evPage    = max(1, (int)($_GET['epage'] ?? 1));
$evPerPage = 30;
$evOffset  = ($evPage - 1) * $evPerPage;
$evTotal      = (int)$pdo->query('SELECT COUNT(*) FROM security_logs')->fetchColumn();
$evTotalPages = max(1, (int)ceil($evTotal / $evPerPage));

$recentStmt = $pdo->prepare(
    "SELECT sl.id, sl.action, sl.ip_address, sl.user_agent, sl.created_at,
            u.name AS user_name, u.email AS user_email
     FROM security_logs sl
     LEFT JOIN users u ON sl.user_id = u.id
     ORDER BY sl.created_at DESC
     LIMIT $evPerPage OFFSET $evOffset"
);
$recentStmt->execute();
$recentEvents = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

// Locked OTP detail
$lkPage    = max(1, (int)($_GET['lpage'] ?? 1));
$lkPerPage = 30;
$lkOffset  = ($lkPage - 1) * $lkPerPage;
$lkCountStmt = $pdo->prepare("SELECT COUNT(*) FROM password_reset_otps WHERE status = 'expired' AND attempts >= ?");
$lkCountStmt->execute([OtpService::MAX_ATTEMPTS]);
$lkTotal      = (int)$lkCountStmt->fetchColumn();
$lkTotalPages = max(1, (int)ceil($lkTotal / $lkPerPage));

$lockedStmt = $pdo->prepare(
    "SELECT pro.id, pro.created_at, pro.attempts, pro.expires_at,
            u.name AS user_name, u.email AS user_email
     FROM password_reset_otps pro
     LEFT JOIN users u ON pro.user_id = u.id
     WHERE pro.status = 'expired' AND pro.attempts >= ?
     ORDER BY pro.created_at DESC
     LIMIT $lkPerPage OFFSET $lkOffset"
);
$lockedStmt->execute([OtpService::MAX_ATTEMPTS]);
$lockedDetails = $lockedStmt->fetchAll(PDO::FETCH_ASSOC);

function sec_qstr(array $overrides = []): string {
    $base = [];
    foreach (['epage', 'lpage'] as $k) {
        if (isset($_GET[$k]) && $_GET[$k] !== '') $base[$k] = $_GET[$k];
    }
    $merged = array_filter(array_merge($base, $overrides), fn($v) => $v !== null);
    return 'security_center.php?' . http_build_query($merged);
}

function sec_render_pagination(int $page, int $totalPages, int $total, string $param): void {
    if ($totalPages <= 1) return;
    echo '<div class="pagination">';
    if ($page > 1) echo '<a href="' . sanitize(sec_qstr([$param => $page - 1])) . '">‹ Prev</a>';
    $pStart = max(1, $page - 3);
    $pEnd   = min($totalPages, $page + 3);
    if ($pStart > 1) echo '<span>…</span>';
    for ($p = $pStart; $p <= $pEnd; $p++) {
        echo $p === $page
            ? '<span class="current">' . $p . '</span>'
            : '<a href="' . sanitize(sec_qstr([$param => $p])) . '">' . $p . '</a>';
    }
    if ($pEnd < $totalPages) echo '<span>…</span>';
    if ($page < $totalPages) echo '<a href="' . sanitize(sec_qstr([$param => $page + 1])) . '">Next ›</a>';
    echo '<span style="color:var(--text-muted,#6b7280);border:none;padding-left:4px;">Page ' . $page . ' of ' . $totalPages . ' (' . $total . ' total)</span>';
    echo '</div>';
}

$actionLabels = [
    'otp_requested'          => 'OTP Requested',
    'otp_requested_unknown'  => 'OTP Request (unknown account)',
    'otp_verified'           => 'OTP Verified',
    'otp_failed'             => 'OTP Failed',
    'otp_rate_limited'       => 'Rate Limit Hit',
    'password_reset_completed' => 'Password Reset',
];
$actionColors = [
    'otp_requested'            => '#3b82f6',
    'otp_requested_unknown'    => '#f59e0b',
    'otp_verified'             => '#10b981',
    'otp_failed'               => '#ef4444',
    'otp_rate_limited'         => '#f97316',
    'password_reset_completed' => '#8b5cf6',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Security Center — AkuapemConnect Admin</title>
  <link rel="stylesheet" href="../assets/css/style.css"/>
  <style>
    .pagination { display:flex; gap:4px; flex-wrap:wrap; align-items:center; margin-top:14px; }
    .pagination a, .pagination span { padding:5px 10px; border-radius:6px; border:1px solid var(--border); text-decoration:none; font-size:.82rem; color:var(--text); }
    .pagination a:hover { background:var(--surface-muted,#f9fafb); }
    .pagination .current { background:var(--primary,#0f766e); color:#fff; border-color:var(--primary,#0f766e); }
  </style>
</head>
<body>
  <header class="topbar">
    <a href="index.php" class="button button-secondary button-small">← Admin</a>
    <h1 style="flex:1;margin:0 16px;font-size:1.1rem;">Security Center</h1>
    <a href="../logout.php" class="button button-secondary button-small">Logout</a>
  </header>
  <main class="page-shell">

    <!-- Stats row -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:24px;">
      <div class="card" style="padding:20px;text-align:center;">
        <p style="margin:0 0 4px;font-size:2rem;font-weight:700;color:#3b82f6;">
          <?php echo number_format($resetRequestsToday); ?>
        </p>
        <p class="meta" style="margin:0;font-size:0.82rem;">Reset requests today</p>
      </div>
      <div class="card" style="padding:20px;text-align:center;">
        <p style="margin:0 0 4px;font-size:2rem;font-weight:700;color:#ef4444;">
          <?php echo number_format($failedOtpToday); ?>
        </p>
        <p class="meta" style="margin:0;font-size:0.82rem;">Failed OTP attempts today</p>
      </div>
      <div class="card" style="padding:20px;text-align:center;">
        <p style="margin:0 0 4px;font-size:2rem;font-weight:700;color:#f97316;">
          <?php echo number_format($lockedOtps); ?>
        </p>
        <p class="meta" style="margin:0;font-size:0.82rem;">Locked OTP sessions</p>
      </div>
      <div class="card" style="padding:20px;text-align:center;">
        <p style="margin:0 0 4px;font-size:2rem;font-weight:700;color:#8b5cf6;">
          <?php echo count($suspiciousIps); ?>
        </p>
        <p class="meta" style="margin:0;font-size:0.82rem;">Suspicious IPs (last hour)</p>
      </div>
    </div>

    <!-- Suspicious IPs -->
    <?php if ($suspiciousIps): ?>
      <div class="panel" style="margin-bottom:20px;border-left:4px solid #ef4444;">
        <div class="panel-header">
          <h2 style="margin:0;font-size:1rem;color:#ef4444;">Suspicious Activity — Last Hour</h2>
        </div>
        <div style="overflow-x:auto;">
          <table style="width:100%;border-collapse:collapse;font-size:0.88rem;">
            <thead>
              <tr style="border-bottom:2px solid var(--border);text-align:left;">
                <th style="padding:8px 12px;">IP Address</th>
                <th style="padding:8px 12px;">Failures</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($suspiciousIps as $row): ?>
                <tr style="border-bottom:1px solid var(--border);">
                  <td style="padding:8px 12px;font-family:monospace;"><?php echo sanitize($row['ip_address']); ?></td>
                  <td style="padding:8px 12px;font-weight:600;color:#ef4444;"><?php echo (int)$row['cnt']; ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>

    <!-- Locked OTP sessions -->
    <?php if ($lockedDetails): ?>
      <div class="panel" style="margin-bottom:20px;">
        <div class="panel-header">
          <h2 style="margin:0;font-size:1rem;">Locked Reset Sessions</h2>
        </div>
        <div style="overflow-x:auto;">
          <table style="width:100%;border-collapse:collapse;font-size:0.88rem;">
            <thead>
              <tr style="border-bottom:2px solid var(--border);text-align:left;">
                <th style="padding:8px 12px;">User</th>
                <th style="padding:8px 12px;">Attempts</th>
                <th style="padding:8px 12px;">Requested</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($lockedDetails as $row): ?>
                <tr style="border-bottom:1px solid var(--border);">
                  <td style="padding:8px 12px;">
                    <?php if ($row['user_name']): ?>
                      <?php echo sanitize($row['user_name']); ?>
                      <span class="meta"> — <?php echo sanitize($row['user_email']); ?></span>
                    <?php else: ?>
                      <em class="meta">Unknown</em>
                    <?php endif; ?>
                  </td>
                  <td style="padding:8px 12px;font-weight:600;color:#f97316;">
                    <?php echo (int)$row['attempts']; ?> / <?php echo OtpService::MAX_ATTEMPTS; ?>
                  </td>
                  <td style="padding:8px 12px;color:var(--text-muted);">
                    <?php echo sanitize(date('d M Y H:i', strtotime($row['created_at']))); ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php sec_render_pagination($lkPage, $lkTotalPages, $lkTotal, 'lpage'); ?>
      </div>
    <?php endif; ?>

    <!-- Recent events log -->
    <div class="panel">
      <div class="panel-header">
        <h2 style="margin:0;font-size:1rem;">Recent Security Events</h2>
        <span class="meta" style="font-size:0.8rem;"><?php echo $evTotal; ?> total</span>
      </div>
      <?php if (!$recentEvents): ?>
        <div class="empty-state">No security events recorded yet.</div>
      <?php else: ?>
        <div style="overflow-x:auto;">
          <table style="width:100%;border-collapse:collapse;font-size:0.85rem;">
            <thead>
              <tr style="border-bottom:2px solid var(--border);text-align:left;">
                <th style="padding:8px 10px;">Date</th>
                <th style="padding:8px 10px;">User</th>
                <th style="padding:8px 10px;">Event</th>
                <th style="padding:8px 10px;">IP</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($recentEvents as $ev): ?>
                <?php
                  $color = $actionColors[$ev['action']] ?? 'var(--text-muted)';
                  $label = $actionLabels[$ev['action']] ?? $ev['action'];
                ?>
                <tr style="border-bottom:1px solid var(--border);">
                  <td style="padding:7px 10px;color:var(--text-muted);white-space:nowrap;">
                    <?php echo sanitize(date('d M H:i', strtotime($ev['created_at']))); ?>
                  </td>
                  <td style="padding:7px 10px;">
                    <?php if ($ev['user_name']): ?>
                      <?php echo sanitize($ev['user_name']); ?>
                    <?php else: ?>
                      <em style="color:var(--text-muted);">Anonymous</em>
                    <?php endif; ?>
                  </td>
                  <td style="padding:7px 10px;">
                    <span style="background:<?php echo $color; ?>1a;color:<?php echo $color; ?>;
                                 padding:2px 8px;border-radius:20px;font-size:0.78rem;font-weight:600;">
                      <?php echo sanitize($label); ?>
                    </span>
                  </td>
                  <td style="padding:7px 10px;font-family:monospace;font-size:0.8rem;color:var(--text-muted);">
                    <?php echo sanitize($ev['ip_address']); ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php sec_render_pagination($evPage, $evTotalPages, $evTotal, 'epage'); ?>
      <?php endif; ?>
    </div>

  </main>
</body>
</html>
