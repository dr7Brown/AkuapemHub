<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_login();
$user = current_user();

$requestId = intval($_GET['request_id'] ?? 0);
if ($requestId <= 0) {
    header('Location: dashboard.php');
    exit;
}

$stmt = $pdo->prepare('SELECT sr.*, u.name AS assigned_worker_name FROM service_requests sr LEFT JOIN users u ON sr.assigned_worker_id = u.id WHERE sr.id = ? AND sr.customer_id = ?');
$stmt->execute([$requestId, $user['id']]);
$request = $stmt->fetch();
if (!$request || $request['status'] !== 'completed' || !$request['assigned_worker_id']) {
    header('Location: dashboard.php');
    exit;
}

$ratingExists = $pdo->prepare('SELECT id FROM ratings WHERE request_id = ? AND customer_id = ?');
$ratingExists->execute([$requestId, $user['id']]);
if ($ratingExists->fetch()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $score = intval($_POST['score'] ?? 0);
    $comment = trim($_POST['comment'] ?? '');

    if ($score < 1 || $score > 5) {
        $error = 'Please select a rating from 1 to 5.';
    } else {
        $stmt = $pdo->prepare('INSERT INTO ratings (request_id, worker_id, customer_id, score, comment, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
        $stmt->execute([$requestId, $request['assigned_worker_id'], $user['id'], $score, $comment]);
        flash('Thank you for rating the worker.');
        header('Location: dashboard.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Rate Worker — AkuapemHub</title>
    <link rel="stylesheet" href="assets/css/style.css" />
</head>
<body>
    <header class="topbar">
        <a href="dashboard.php" class="button button-secondary button-small">Back</a>
        <h1>Rate worker</h1>
        <a href="logout.php" class="button button-secondary button-small">Logout</a>
    </header>
    <main class="page-shell small-shell">
        <form class="card form-card" method="post" action="rate_job.php?request_id=<?php echo $requestId; ?>">
            <h2><?php echo sanitize($request['title']); ?></h2>
            <p class="meta">Worker: <?php echo sanitize($request['assigned_worker_name']); ?></p>
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo sanitize($error); ?></div>
            <?php endif; ?>
            <label>Score</label>
            <select name="score" required>
                <option value="">Choose rating</option>
                <?php for ($i = 1; $i <= 5; $i++): ?>
                    <option value="<?php echo $i; ?>"><?php echo $i; ?> star<?php echo $i > 1 ? 's' : ''; ?></option>
                <?php endfor; ?>
            </select>
            <label>Comment</label>
            <textarea name="comment" rows="4" placeholder="Optional feedback"></textarea>
            <button type="submit" class="button button-primary">Send rating</button>
        </form>
    </main>
</body>
</html>
