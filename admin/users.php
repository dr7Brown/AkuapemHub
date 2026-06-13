<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';

require_login();
if (!is_admin()) {
    header('Location: ../dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    csrf_check();
    if ($_POST['action'] === 'bulk' && !empty($_POST['selected_users']) && is_array($_POST['selected_users'])) {
        $userIds = array_filter(array_map('intval', $_POST['selected_users']));
        $bulkAction = $_POST['bulk_action'] ?? '';
        if ($userIds) {
            $placeholders = implode(',', array_fill(0, count($userIds), '?'));
            $stmt = $pdo->prepare("SELECT id, role FROM users WHERE id IN ($placeholders) AND role != ?");
            $stmt->execute([...$userIds, ADMIN_ROLE]);
            $selectedUsers = $stmt->fetchAll();
            foreach ($selectedUsers as $userItem) {
                if ($bulkAction === 'ban') {
                    $pdo->prepare('UPDATE users SET banned = 1 WHERE id = ?')->execute([$userItem['id']]);
                } elseif ($bulkAction === 'unban') {
                    $pdo->prepare('UPDATE users SET banned = 0 WHERE id = ?')->execute([$userItem['id']]);
                }
            }
        }
    } elseif (!empty($_POST['user_id'])) {
        $userId = intval($_POST['user_id']);
        if ($_POST['action'] === 'ban') {
            $pdo->prepare('UPDATE users SET banned = 1 WHERE id = ?')->execute([$userId]);
        } elseif ($_POST['action'] === 'unban') {
            $pdo->prepare('UPDATE users SET banned = 0 WHERE id = ?')->execute([$userId]);
        } elseif ($_POST['action'] === 'change_role') {
            $newRole = $_POST['role'] ?? '';
            if (in_array($newRole, ['customer', 'worker', 'manager'], true)) {
                $pdo->prepare('UPDATE users SET role = ? WHERE id = ? AND role != ?')->execute([$newRole, $userId, ADMIN_ROLE]);
            }
        }
    }
    header('Location: users.php');
    exit;
}

$stmt = $pdo->query('SELECT id, name, email, role, banned, created_at FROM users ORDER BY created_at DESC');
$users = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Admin Users — AkuapemHub</title>
    <link rel="stylesheet" href="../assets/css/style.css" />
</head>
<body>
    <header class="topbar">
        <a href="index.php" class="button button-secondary button-small">Back</a>
        <h1>User management</h1>
        <a href="../logout.php" class="button button-secondary button-small">Logout</a>
    </header>
    <main class="page-shell">
        <section class="panel">
            <form id="bulk-users" method="post" action="users.php" class="filter-form" style="margin-bottom: 16px; gap: 8px; flex-wrap: wrap;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="bulk" />
                <label style="display: flex; align-items: center; gap: 8px;">
                    <span>Bulk user action</span>
                    <select name="bulk_action">
                        <option value="ban">Ban selected</option>
                        <option value="unban">Unban selected</option>
                    </select>
                </label>
                <button type="submit" class="button button-primary button-small">Apply</button>
            </form>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th></th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Joined</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $userItem): ?>
                            <tr>
                                <td>
                                    <?php if ($userItem['role'] !== ADMIN_ROLE): ?>
                                        <input type="checkbox" name="selected_users[]" value="<?php echo $userItem['id']; ?>" form="bulk-users" />
                                    <?php endif; ?>
                                </td>
                                <td><?php echo sanitize($userItem['name']); ?></td>
                                <td><?php echo sanitize($userItem['email']); ?></td>
                                <td>
                                    <?php if ($userItem['role'] === ADMIN_ROLE): ?>
                                        <?php echo sanitize($userItem['role']); ?>
                                    <?php else: ?>
                                        <form method="post" class="inline-form" action="users.php" style="display: flex; gap: 6px; align-items: center;">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="user_id" value="<?php echo $userItem['id']; ?>" />
                                            <input type="hidden" name="action" value="change_role" />
                                            <select name="role">
                                                <option value="customer" <?php echo $userItem['role'] === 'customer' ? 'selected' : ''; ?>>Customer</option>
                                                <option value="worker" <?php echo $userItem['role'] === 'worker' ? 'selected' : ''; ?>>Worker</option>
                                                <option value="manager" <?php echo $userItem['role'] === 'manager' ? 'selected' : ''; ?>>Manager</option>
                                            </select>
                                            <button type="submit" class="button button-small button-secondary">Set</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $userItem['banned'] ? 'Banned' : 'Active'; ?></td>
                                <td><?php echo sanitize($userItem['created_at']); ?></td>
                                <td>
                                    <?php if ($userItem['role'] !== ADMIN_ROLE): ?>
                                        <form method="post" class="inline-form" action="users.php">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="user_id" value="<?php echo $userItem['id']; ?>" />
                                            <input type="hidden" name="action" value="<?php echo $userItem['banned'] ? 'unban' : 'ban'; ?>" />
                                            <button type="submit" class="button button-small <?php echo $userItem['banned'] ? 'button-primary' : 'button-secondary'; ?>">
                                                <?php echo $userItem['banned'] ? 'Unban' : 'Ban'; ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</body>
</html>
