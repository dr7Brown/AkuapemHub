<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';

require_login();
if (!is_admin()) { header('Location: index.php'); exit; }

$adminUser = current_user();
$flash     = get_flash();

// ── POST: grant / revoke ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $userId = (int)($_POST['id'] ?? 0);

    if ($action === 'grant_complimentary' && $userId) {
        $row = $pdo->prepare('SELECT name, role FROM users WHERE id=?');
        $row->execute([$userId]); $row = $row->fetch();
        if (!$row) {
            flash('User not found.', 'error');
        } elseif ($row['role'] === 'admin') {
            flash('Cannot grant a complimentary membership to an admin.', 'error');
        } else {
            $pdo->prepare('UPDATE users SET is_complimentary=1, complimentary_granted_at=NOW(), complimentary_granted_by=? WHERE id=?')
                ->execute([$adminUser['id'], $userId]);
            log_audit_action($adminUser['id'], 'complimentary_granted', "Granted complimentary membership to {$row['name']} (#{$userId})");
            notify_user($userId, '⭐ Complimentary Membership Granted', 'You now have free access to every paid feature on the platform.', 'success');
            flash('Complimentary membership granted.', 'success');
        }
    } elseif ($action === 'revoke_complimentary' && $userId) {
        $row = $pdo->prepare('SELECT name FROM users WHERE id=?');
        $row->execute([$userId]); $row = $row->fetch();
        $pdo->prepare('UPDATE users SET is_complimentary=0, complimentary_granted_at=NULL, complimentary_granted_by=NULL WHERE id=?')
            ->execute([$userId]);
        log_audit_action($adminUser['id'], 'complimentary_revoked', "Revoked complimentary membership from " . ($row['name'] ?? "#{$userId}") . " (#{$userId})");
        if ($row) notify_user($userId, 'Complimentary Membership Ended', 'Your complimentary membership has ended. Paid features now require payment as normal.', 'info');
        flash('Complimentary membership revoked.', 'success');
    }
    header('Location: complimentary_members.php');
    exit;
}

// ── Current complimentary members ────────────────────────────────────────────
$members = $pdo->query(
    "SELECT u.id, u.name, u.email, u.role, u.complimentary_granted_at, g.name AS granted_by_name
     FROM users u
     LEFT JOIN users g ON u.complimentary_granted_by = g.id
     WHERE u.is_complimentary = 1
     ORDER BY u.complimentary_granted_at DESC"
)->fetchAll();

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="complimentary_members_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Name', 'Email', 'Role', 'Granted At', 'Granted By']);
    foreach ($members as $m) {
        fputcsv($out, [csv_safe($m['name']), csv_safe($m['email']), $m['role'], $m['complimentary_granted_at'], csv_safe($m['granted_by_name'] ?? '')]);
    }
    fclose($out);
    exit;
}

// ── Search users to grant to ─────────────────────────────────────────────────
$q = trim($_GET['q'] ?? '');
$searchResults = [];
if ($q !== '') {
    $searchStmt = $pdo->prepare(
        "SELECT id, name, email, role, is_complimentary FROM users
         WHERE (name LIKE ? OR email LIKE ? OR username LIKE ?) AND role != 'admin'
         ORDER BY name LIMIT 20"
    );
    $like = '%' . $q . '%';
    $searchStmt->execute([$like, $like, $like]);
    $searchResults = $searchStmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Complimentary Memberships — Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .cm-shell { max-width:900px; margin:0 auto; padding:18px 16px 60px; }
        .cm-table { width:100%; border-collapse:collapse; font-size:.84rem; }
        .cm-table th { padding:9px 12px; text-align:left; font-size:.72rem; font-weight:800; text-transform:uppercase; letter-spacing:.05em; color:var(--text-muted,#6b7280); border-bottom:1px solid var(--border); background:var(--surface-muted,#f9fafb); }
        .cm-table td { padding:10px 12px; border-bottom:1px solid var(--border,#f1f5f9); vertical-align:middle; }
        .cm-table tr:last-child td { border-bottom:none; }
        .cm-card { background:var(--surface); border:1px solid var(--border); border-radius:14px; padding:18px; margin-bottom:16px; overflow-x:auto; }
        .cm-badge { display:inline-block; padding:2px 9px; border-radius:20px; font-size:.68rem; font-weight:700; background:#fef3c7; color:#92400e; }
    </style>
</head>
<body>

<header class="topbar">
    <a href="index.php" class="button button-secondary button-small">← Dashboard</a>
    <h1 style="margin:0;font-size:1rem;font-weight:800;">⭐ Complimentary Memberships</h1>
    <a href="monetization.php?tab=settings" class="button button-secondary button-small">Monetize Settings</a>
</header>

<main class="cm-shell">

    <?php if ($flash): ?>
    <div class="alert alert-<?php echo sanitize($flash['type']); ?>" style="margin-bottom:14px;"><?php echo sanitize($flash['message']); ?></div>
    <?php endif; ?>

    <p style="font-size:.84rem;color:var(--text-muted,#6b7280);margin-bottom:16px;">
        A complimentary member gets free access to every currently-paid feature on the platform (job posting, featured listings, verification, marketplace subscriptions/boosts, delivery premium/sponsored/verification) until revoked. Escrow commission on jobs is not affected.
    </p>

    <div class="cm-card">
        <h2 style="margin-top:0;font-size:1rem;">Grant to a User</h2>
        <form method="get" action="complimentary_members.php" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px;">
            <input type="text" name="q" value="<?php echo sanitize($q); ?>" placeholder="Search name, email, or username…" style="flex:1;min-width:200px;padding:7px 10px;border:1px solid var(--border);border-radius:8px;font-size:.82rem;">
            <button type="submit" class="button button-secondary button-small">Search</button>
        </form>
        <?php if ($q !== ''): ?>
        <table class="cm-table">
            <thead><tr><th>Name</th><th>Email</th><th>Role</th><th style="text-align:right;">Action</th></tr></thead>
            <tbody>
            <?php if (!$searchResults): ?>
            <tr><td colspan="4" style="text-align:center;color:var(--text-muted,#6b7280);padding:16px;">No matching users.</td></tr>
            <?php endif; ?>
            <?php foreach ($searchResults as $r): ?>
            <tr>
                <td><?php echo sanitize($r['name']); ?></td>
                <td><?php echo sanitize($r['email']); ?></td>
                <td><?php echo ucfirst($r['role']); ?></td>
                <td style="text-align:right;">
                    <?php if ($r['is_complimentary']): ?>
                    <span class="cm-badge">Already complimentary</span>
                    <?php else: ?>
                    <form method="post" style="display:inline;" onsubmit="return confirm('Grant complimentary membership to <?php echo sanitize(addslashes($r['name'])); ?>?');">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="grant_complimentary">
                        <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                        <button type="submit" class="button button-primary button-small">Grant</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <div class="cm-card">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
            <h2 style="margin:0;font-size:1rem;">Current Complimentary Members (<?php echo count($members); ?>)</h2>
            <a href="?export=csv" class="button button-secondary button-small">⬇ CSV</a>
        </div>
        <table class="cm-table">
            <thead><tr><th>Name</th><th>Email</th><th>Granted</th><th>By</th><th style="text-align:right;">Action</th></tr></thead>
            <tbody>
            <?php if (!$members): ?>
            <tr><td colspan="5" style="text-align:center;color:var(--text-muted,#6b7280);padding:24px;">No complimentary members yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($members as $m): ?>
            <tr>
                <td><strong><?php echo sanitize($m['name']); ?></strong></td>
                <td><?php echo sanitize($m['email']); ?></td>
                <td><?php echo $m['complimentary_granted_at'] ? date('d M Y', strtotime($m['complimentary_granted_at'])) : '—'; ?></td>
                <td><?php echo sanitize($m['granted_by_name'] ?? '—'); ?></td>
                <td style="text-align:right;">
                    <form method="post" style="display:inline;" onsubmit="return confirm('Revoke complimentary membership for <?php echo sanitize(addslashes($m['name'])); ?>?');">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="revoke_complimentary">
                        <input type="hidden" name="id" value="<?php echo $m['id']; ?>">
                        <button type="submit" class="button button-secondary button-small" style="color:#c0392b;">Revoke</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

</main>
</body>
</html>
