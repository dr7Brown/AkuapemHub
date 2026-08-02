<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';

require_login();
if (!is_admin_or_manager()) { header('Location: ../jobs.php'); exit; }
require_mod_permission('manage_users');

$adminUser = current_user();
$flash = get_flash();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action  = $_POST['action'] ?? '';
    $reqId   = (int)($_POST['request_id'] ?? 0);
    $notes   = trim($_POST['admin_notes'] ?? '');

    $row = $pdo->prepare("SELECT * FROM account_deletion_requests WHERE id=? AND status='pending'");
    $row->execute([$reqId]);
    $dr = $row->fetch();

    if ($dr) {
        if ($action === 'approve') {
            $pdo->prepare("UPDATE account_deletion_requests SET status='approved', admin_notes=?, reviewed_by=?, reviewed_at=NOW() WHERE id=?")
                ->execute([$notes ?: null, $adminUser['id'], $reqId]);
            $pdo->prepare("UPDATE users SET banned=1 WHERE id=?")->execute([$dr['user_id']]);
            notify_user((int)$dr['user_id'], 'Account Closure Approved',
                'Your request to close your account has been approved. Contact support if you\'d like it reactivated.' . ($notes ? "\n\nNote: {$notes}" : ''),
                'warning');
            log_audit_action($adminUser['id'], 'account_deletion_approved', "Approved account closure request #{$reqId} for user #{$dr['user_id']}");
            flash('Account closure approved — the account has been deactivated.', 'success');
        } elseif ($action === 'reject') {
            $pdo->prepare("UPDATE account_deletion_requests SET status='rejected', admin_notes=?, reviewed_by=?, reviewed_at=NOW() WHERE id=?")
                ->execute([$notes ?: null, $adminUser['id'], $reqId]);
            notify_user((int)$dr['user_id'], 'Account Closure Request Declined',
                'Your request to close your account was not approved.' . ($notes ? "\n\nReason: {$notes}" : ''),
                'info');
            log_audit_action($adminUser['id'], 'account_deletion_rejected', "Rejected account closure request #{$reqId} for user #{$dr['user_id']}");
            flash('Account closure request rejected.', 'info');
        }
    }
    header('Location: account_deletions.php'); exit;
}

$pending = $pdo->query("
    SELECT adr.*, u.name, u.username, u.email, u.role
    FROM account_deletion_requests adr JOIN users u ON u.id = adr.user_id
    WHERE adr.status='pending' ORDER BY adr.created_at ASC
")->fetchAll();

$histPage    = max(1, (int)($_GET['page'] ?? 1));
$histPerPage = 30;
$histOffset  = ($histPage - 1) * $histPerPage;
$histTotal      = (int)$pdo->query("SELECT COUNT(*) FROM account_deletion_requests WHERE status != 'pending'")->fetchColumn();
$histTotalPages = max(1, (int)ceil($histTotal / $histPerPage));

$history = $pdo->query("
    SELECT adr.*, u.name, u.username, ru.name AS reviewer_name
    FROM account_deletion_requests adr
    JOIN users u ON u.id = adr.user_id
    LEFT JOIN users ru ON ru.id = adr.reviewed_by
    WHERE adr.status != 'pending' ORDER BY adr.reviewed_at DESC LIMIT $histPerPage OFFSET $histOffset
")->fetchAll();

function acd_qstr(array $overrides = []): string {
    $base = [];
    if (isset($_GET['page']) && $_GET['page'] !== '') $base['page'] = $_GET['page'];
    $merged = array_filter(array_merge($base, $overrides), fn($v) => $v !== null);
    return 'account_deletions.php?' . http_build_query($merged);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Account Closure Requests — Admin</title>
<link rel="stylesheet" href="../assets/css/style.css">
<style>
.acd-shell { max-width:820px; margin:0 auto; padding:20px 16px 60px; }
.acd-card  { background:var(--surface); border:1px solid var(--border); border-radius:14px; padding:18px; margin-bottom:14px; }
.acd-title { font-size:.75rem; font-weight:800; text-transform:uppercase; letter-spacing:.07em; color:var(--text-muted,#6b7280); margin:0 0 14px; }
.acd-row   { border-bottom:1px solid var(--border); padding:14px 0; }
.acd-row:last-child { border-bottom:none; }
.acd-reason { background:var(--surface-muted,#f8fafc); border-radius:8px; padding:10px 12px; font-size:.86rem; margin:8px 0; white-space:pre-wrap; }
.acd-actions { display:flex; gap:8px; flex-wrap:wrap; align-items:flex-end; margin-top:8px; }
.acd-hist-row { display:flex; justify-content:space-between; align-items:flex-start; gap:8px; padding:10px 0; border-bottom:1px solid var(--border); font-size:.84rem; flex-wrap:wrap; }
.acd-hist-row:last-child { border-bottom:none; }
.pagination { display:flex; gap:4px; flex-wrap:wrap; align-items:center; margin-top:14px; }
.pagination a, .pagination span { padding:5px 10px; border-radius:6px; border:1px solid var(--border); text-decoration:none; font-size:.82rem; color:var(--text); }
.pagination a:hover { background:var(--surface-muted,#f9fafb); }
.pagination .current { background:var(--primary,#0f766e); color:#fff; border-color:var(--primary,#0f766e); }
</style>
</head>
<body>

<header class="topbar">
    <a href="index.php" class="button button-secondary button-small">← Dashboard</a>
    <h1 style="margin:0;font-size:1rem;font-weight:800;">🚪 Account Closure Requests</h1>
</header>

<main class="acd-shell">
    <?php if ($flash): ?>
    <div class="alert alert-<?php echo sanitize($flash['type']); ?>" style="margin-bottom:14px;"><?php echo sanitize($flash['message']); ?></div>
    <?php endif; ?>

    <div class="acd-card">
        <p class="acd-title">Pending Requests (<?php echo count($pending); ?>)</p>
        <?php if (!$pending): ?>
        <p class="meta">No pending account closure requests.</p>
        <?php endif; ?>
        <?php foreach ($pending as $r): ?>
        <div class="acd-row">
            <strong><?php echo sanitize($r['name']); ?></strong>
            <span class="meta">@<?php echo sanitize($r['username']); ?> · <?php echo sanitize($r['email']); ?> · <?php echo ucfirst($r['role']); ?></span>
            <span class="meta" style="display:block;">Requested <?php echo sanitize(time_ago($r['created_at'])); ?></span>
            <div class="acd-reason"><?php echo sanitize($r['reason']); ?></div>
            <form method="post" class="acd-actions">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="request_id" value="<?php echo (int)$r['id']; ?>">
                <div style="flex:1;min-width:180px;">
                    <label style="font-size:.72rem;font-weight:700;display:block;margin-bottom:3px;">Note (optional)</label>
                    <input type="text" name="admin_notes" placeholder="Visible to the user" style="width:100%;padding:6px 10px;border:1px solid var(--border);border-radius:8px;">
                </div>
                <button type="submit" name="action" value="approve" class="button button-primary button-small">✓ Approve &amp; Deactivate</button>
                <button type="submit" name="action" value="reject" class="button button-secondary button-small">✗ Reject</button>
            </form>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if ($history): ?>
    <div class="acd-card">
        <p class="acd-title">Recent Decisions</p>
        <?php foreach ($history as $h): ?>
        <div class="acd-hist-row">
            <div>
                <strong><?php echo sanitize($h['name']); ?></strong> <span class="meta">@<?php echo sanitize($h['username']); ?></span>
                <div class="meta"><?php echo sanitize(mb_substr($h['reason'],0,100)); ?></div>
                <?php if ($h['admin_notes']): ?><div class="meta">Note: <?php echo sanitize($h['admin_notes']); ?></div><?php endif; ?>
                <div class="meta">By <?php echo sanitize($h['reviewer_name'] ?? '—'); ?> · <?php echo date('d M Y', strtotime($h['reviewed_at'])); ?></div>
            </div>
            <span style="background:<?php echo $h['status']==='approved'?'#fee2e2':'#d1fae5'; ?>;color:<?php echo $h['status']==='approved'?'#991b1b':'#065f46'; ?>;font-size:.7rem;font-weight:800;padding:2px 8px;border-radius:10px;white-space:nowrap;"><?php echo ucfirst($h['status']); ?></span>
        </div>
        <?php endforeach; ?>
        <?php if ($histTotalPages > 1): ?>
        <div class="pagination">
            <?php if ($histPage > 1): ?><a href="<?php echo acd_qstr(['page' => $histPage - 1]); ?>">‹ Prev</a><?php endif; ?>
            <?php
            $hpStart = max(1, $histPage - 3);
            $hpEnd   = min($histTotalPages, $histPage + 3);
            if ($hpStart > 1) echo '<span>…</span>';
            for ($hp = $hpStart; $hp <= $hpEnd; $hp++): ?>
                <?php if ($hp === $histPage): ?><span class="current"><?php echo $hp; ?></span>
                <?php else: ?><a href="<?php echo acd_qstr(['page' => $hp]); ?>"><?php echo $hp; ?></a><?php endif; ?>
            <?php endfor;
            if ($hpEnd < $histTotalPages) echo '<span>…</span>';
            ?>
            <?php if ($histPage < $histTotalPages): ?><a href="<?php echo acd_qstr(['page' => $histPage + 1]); ?>">Next ›</a><?php endif; ?>
            <span style="color:var(--text-muted,#6b7280);border:none;padding-left:4px;">Page <?php echo $histPage; ?> of <?php echo $histTotalPages; ?> (<?php echo $histTotal; ?> total)</span>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</main>
</body>
</html>
