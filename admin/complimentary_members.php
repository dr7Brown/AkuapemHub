<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';

require_login();
if (!is_admin()) { header('Location: index.php'); exit; }

$adminUser = current_user();
$flash     = get_flash();
$allFeatures = all_complimentary_features();

// ── POST: grant / revoke ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $userId = (int)($_POST['id'] ?? 0);

    if ($action === 'grant_complimentary' && $userId) {
        $row = $pdo->prepare('SELECT name, role FROM users WHERE id=?');
        $row->execute([$userId]); $row = $row->fetch();
        $selected = array_values(array_intersect((array)($_POST['features'] ?? []), array_merge(array_keys($allFeatures), ['full'])));
        if (!$row) {
            flash('User not found.', 'error');
        } elseif ($row['role'] === 'admin') {
            flash('Cannot grant a complimentary membership to an admin.', 'error');
        } elseif (!$selected) {
            flash('Select at least one feature to grant.', 'error');
        } else {
            grant_complimentary_features($userId, $selected, $adminUser['id']);
            $grantLabel = in_array('full', $selected, true)
                ? 'full access to every paid feature'
                : implode(', ', array_map(fn($k) => $allFeatures[$k] ?? $k, $selected));
            log_audit_action($adminUser['id'], 'complimentary_granted', "Granted complimentary access ({$grantLabel}) to {$row['name']} (#{$userId})");
            notify_user($userId, '⭐ Complimentary Access Granted', "You now have free access to: {$grantLabel}.", 'success');
            flash('Complimentary access granted: ' . $grantLabel, 'success');
        }
    } elseif ($action === 'revoke_complimentary' && $userId) {
        $row = $pdo->prepare('SELECT name FROM users WHERE id=?');
        $row->execute([$userId]); $row = $row->fetch();
        revoke_complimentary_access($userId);
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

// Features granted per member, for the "Features" column below.
$grantsByUser = [];
if ($members) {
    $ids = array_column($members, 'id');
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $gStmt = $pdo->prepare("SELECT user_id, feature_key FROM complimentary_grants WHERE user_id IN ($in)");
    $gStmt->execute($ids);
    foreach ($gStmt->fetchAll() as $g) {
        $grantsByUser[$g['user_id']][] = $g['feature_key'];
    }
}

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="complimentary_members_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Name', 'Email', 'Role', 'Granted At', 'Granted By', 'Features']);
    foreach ($members as $m) {
        $keys = $grantsByUser[$m['id']] ?? [];
        $featuresLabel = in_array('full', $keys, true) ? 'Full access' : implode('; ', array_map(fn($k) => $allFeatures[$k] ?? $k, $keys));
        fputcsv($out, [csv_safe($m['name']), csv_safe($m['email']), $m['role'], $m['complimentary_granted_at'], csv_safe($m['granted_by_name'] ?? ''), csv_safe($featuresLabel)]);
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
        .cm-badge { display:inline-block; padding:2px 9px; border-radius:20px; font-size:.68rem; font-weight:700; background:#fef3c7; color:#92400e; margin:1px 2px; }
        .cm-badge.full { background:#d1fae5; color:#065f46; }
        .cm-grant-item { display:flex; align-items:center; gap:8px; padding:6px 0; font-size:.85rem; }
        .cm-grant-item label { cursor:pointer; }
        .cm-grant-item.full-item { border-bottom:1px solid var(--border); padding-bottom:10px; margin-bottom:6px; font-weight:700; }
    </style>
</head>
<body>

<header class="topbar">
    <a href="index.php" class="button button-secondary button-small">← Dashboard</a>
    <h1 style="margin:0;font-size:1rem;font-weight:800;">⭐ Complimentary Memberships</h1>
    <a href="monetization.php?tab=settings" class="button button-secondary button-small">Monetize Settings</a>
</header>

<main class="cm-shell">

    <div style="display:flex;justify-content:flex-end;margin-bottom:14px;">
        <a href="monetization.php?tab=settings" class="button button-secondary button-small">Monetize Settings</a>
    </div>

    <?php if ($flash): ?>
    <div class="alert alert-<?php echo sanitize($flash['type']); ?>" style="margin-bottom:14px;"><?php echo sanitize($flash['message']); ?></div>
    <?php endif; ?>

    <p style="font-size:.84rem;color:var(--text-muted,#6b7280);margin-bottom:16px;">
        A complimentary member gets free access to whichever paid features you grant them below — pick "Full Access" for everything (job posting, featured listings, verification, marketplace subscriptions/boosts, delivery premium/sponsored/verification), or pick specific ones. Escrow commission on jobs is not affected.
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
                    <button type="button" class="button button-secondary button-small"
                            onclick="openGrantModal(<?php echo (int)$r['id']; ?>, '<?php echo sanitize(addslashes($r['name'])); ?>')">Edit</button>
                    <?php else: ?>
                    <button type="button" class="button button-primary button-small"
                            onclick="openGrantModal(<?php echo (int)$r['id']; ?>, '<?php echo sanitize(addslashes($r['name'])); ?>')">Grant</button>
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
            <thead><tr><th>Name</th><th>Email</th><th>Features</th><th>Granted</th><th>By</th><th style="text-align:right;">Action</th></tr></thead>
            <tbody>
            <?php if (!$members): ?>
            <tr><td colspan="6" style="text-align:center;color:var(--text-muted,#6b7280);padding:24px;">No complimentary members yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($members as $m): ?>
            <?php $keys = $grantsByUser[$m['id']] ?? []; $isFull = in_array('full', $keys, true); ?>
            <tr>
                <td><strong><?php echo sanitize($m['name']); ?></strong></td>
                <td><?php echo sanitize($m['email']); ?></td>
                <td>
                    <?php if ($isFull): ?>
                        <span class="cm-badge full">⭐ Full</span>
                    <?php elseif ($keys): ?>
                        <?php foreach ($keys as $k): ?><span class="cm-badge"><?php echo sanitize($allFeatures[$k] ?? $k); ?></span><?php endforeach; ?>
                    <?php else: ?>
                        <span style="color:var(--text-muted,#6b7280);font-size:.78rem;">—</span>
                    <?php endif; ?>
                </td>
                <td><?php echo $m['complimentary_granted_at'] ? date('d M Y', strtotime($m['complimentary_granted_at'])) : '—'; ?></td>
                <td><?php echo sanitize($m['granted_by_name'] ?? '—'); ?></td>
                <td style="text-align:right;white-space:nowrap;">
                    <button type="button" class="button button-secondary button-small"
                            onclick="openGrantModal(<?php echo (int)$m['id']; ?>, '<?php echo sanitize(addslashes($m['name'])); ?>')">Edit</button>
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

<!-- Grant complimentary access modal -->
<div id="grantModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:999;align-items:center;justify-content:center;">
    <div style="background:var(--surface);border-radius:12px;padding:24px;width:420px;max-width:94vw;max-height:88vh;overflow-y:auto;">
        <h3 style="margin:0 0 16px;" id="grantModalTitle">Grant Complimentary Access</h3>
        <form method="post" action="complimentary_members.php">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="grant_complimentary">
            <input type="hidden" name="id" id="grantUserId">

            <div class="cm-grant-item full-item">
                <input type="checkbox" name="features[]" value="full" id="grantFull">
                <label for="grantFull">⭐ Full Access — every current and future paid feature</label>
            </div>

            <?php foreach ($allFeatures as $fKey => $fLabel): ?>
            <div class="cm-grant-item">
                <input type="checkbox" name="features[]" value="<?php echo sanitize($fKey); ?>" class="grant-feature-cb" id="grantFeature_<?php echo sanitize($fKey); ?>">
                <label for="grantFeature_<?php echo sanitize($fKey); ?>"><?php echo sanitize($fLabel); ?></label>
            </div>
            <?php endforeach; ?>

            <div style="display:flex;gap:8px;margin-top:14px;">
                <button type="submit" class="button button-primary">Grant Selected</button>
                <button type="button" class="button button-secondary" onclick="closeGrantModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
var GRANTS_BY_USER = <?php echo json_encode($grantsByUser, JSON_FORCE_OBJECT); ?>;

function openGrantModal(userId, name) {
    document.getElementById('grantUserId').value = userId;
    document.getElementById('grantModalTitle').textContent = 'Grant Complimentary Access: ' + name;
    var active = GRANTS_BY_USER[userId] || [];
    document.getElementById('grantFull').checked = active.indexOf('full') !== -1;
    document.querySelectorAll('.grant-feature-cb').forEach(function (cb) {
        cb.checked = active.indexOf(cb.value) !== -1;
    });
    toggleFeatureCheckboxes();
    document.getElementById('grantModal').style.display = 'flex';
}
function closeGrantModal() { document.getElementById('grantModal').style.display = 'none'; }
document.getElementById('grantModal').addEventListener('click', function (e) { if (e.target === this) closeGrantModal(); });

function toggleFeatureCheckboxes() {
    var full = document.getElementById('grantFull').checked;
    document.querySelectorAll('.grant-feature-cb').forEach(function (cb) {
        cb.disabled = full;
    });
}
document.getElementById('grantFull').addEventListener('change', toggleFeatureCheckboxes);
</script>

</body>
</html>
