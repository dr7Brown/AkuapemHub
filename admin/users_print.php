<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';

require_login();
if (!is_admin_or_manager()) { header('Location: ../jobs.php'); exit; }
require_mod_permission('manage_users');

// Same filters as users.php, so this always prints exactly what's on screen.
$q      = trim($_GET['q']      ?? '');
$role   = $_GET['role']        ?? '';
$status = $_GET['status']      ?? '';

$where  = ['1=1'];
$params = [];
if ($q !== '') {
    $where[] = '(u.name LIKE ? OR u.username LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)';
    $like = '%' . $q . '%';
    $params = array_merge($params, [$like,$like,$like,$like]);
}
if ($role && in_array($role,['customer','worker','manager','admin'],true)) {
    $where[] = 'u.role = ?'; $params[] = $role;
}
if ($status === 'banned')     { $where[] = 'u.banned = 1'; }
if ($status === 'active')     { $where[] = 'u.banned = 0'; }
if ($status === 'verified')   { $where[] = 'u.email_verified = 1'; }
if ($status === 'unverified') { $where[] = 'u.email_verified = 0'; }

$whereClause = implode(' AND ', $where);
$usersSt = $pdo->prepare(
    "SELECT u.id, u.name, u.username, u.email, u.phone, u.role, u.banned, u.email_verified, u.created_at
     FROM users u WHERE $whereClause ORDER BY u.created_at DESC"
);
$usersSt->execute($params);
$users = $usersSt->fetchAll();

$filterSummary = [];
if ($q !== '')      $filterSummary[] = 'Search: "' . $q . '"';
if ($role !== '')   $filterSummary[] = 'Role: ' . ucfirst($role);
if ($status !== '') $filterSummary[] = 'Status: ' . ucfirst($status);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registered Users — <?php echo sanitize(APP_NAME); ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .up-shell { max-width:1000px; margin:0 auto; padding:20px 16px 60px; }
        .up-table { width:100%; border-collapse:collapse; font-size:.86rem; }
        .up-table th { text-align:left; padding:8px; border-bottom:2px solid #e2e8f0; font-size:.72rem; text-transform:uppercase; letter-spacing:.05em; color:#6b7280; }
        .up-table td { padding:8px; border-bottom:1px solid #f1f5f9; }
        .up-meta { color:#6b7280; font-size:.82rem; margin-bottom:16px; }
        @media print {
            .no-print { display:none !important; }
            body { background:#fff; }
        }
    </style>
</head>
<body>

<header class="app-topbar no-print">
    <a href="users.php" class="button button-secondary button-small">← Back</a>
    <span class="brand">Registered Users</span>
    <button onclick="window.print()" class="button button-primary button-small">🖨 Print / Save as PDF</button>
</header>

<main class="up-shell">
    <h1 style="margin:0 0 4px;font-size:1.2rem;"><?php echo sanitize(APP_NAME); ?> — Registered Users</h1>
    <p class="up-meta">
        Printed <?php echo date('d M Y, g:i A'); ?> · <?php echo count($users); ?> user<?php echo count($users) === 1 ? '' : 's'; ?>
        <?php if ($filterSummary): ?> · <?php echo sanitize(implode(' · ', $filterSummary)); ?><?php endif; ?>
    </p>

    <table class="up-table">
        <thead>
            <tr>
                <th>ID</th><th>Name</th><th>Username</th><th>Email</th><th>Phone</th>
                <th>Role</th><th>Status</th><th>Verified</th><th>Registered</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $u): ?>
            <tr>
                <td><?php echo $u['id']; ?></td>
                <td><?php echo sanitize($u['name']); ?></td>
                <td><?php echo sanitize($u['username']); ?></td>
                <td><?php echo sanitize($u['email']); ?></td>
                <td><?php echo sanitize($u['phone']); ?></td>
                <td><?php echo ucfirst($u['role']); ?></td>
                <td><?php echo $u['banned'] ? 'Banned' : 'Active'; ?></td>
                <td><?php echo $u['email_verified'] ? 'Yes' : 'No'; ?></td>
                <td><?php echo date('d M Y', strtotime($u['created_at'])); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</main>

</body>
</html>
