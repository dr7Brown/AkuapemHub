<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';

require_login();
if (!is_admin()) {
    header('Location: ../dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action']) && !empty($_POST['user_id'])) {
    $userId = intval($_POST['user_id']);
    if ($_POST['action'] === 'ban') {
        $pdo->prepare('UPDATE users SET banned = 1 WHERE id = ?')->execute([$userId]);
    } elseif ($_POST['action'] === 'unban') {
        $pdo->prepare('UPDATE users SET banned = 0 WHERE id = ?')->execute([$userId]);
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
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
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
                                <td><?php echo sanitize($userItem['name']); ?></td>
                                <td><?php echo sanitize($userItem['email']); ?></td>
                                <td><?php echo sanitize($userItem['role']); ?></td>
                                <td><?php echo $userItem['banned'] ? 'Banned' : 'Active'; ?></td>
                                <td><?php echo sanitize($userItem['created_at']); ?></td>
                                <td>
                                    <?php if ($userItem['role'] !== ADMIN_ROLE): ?>
                                        <form method="post" class="inline-form" action="users.php">
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
