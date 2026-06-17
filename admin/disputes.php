<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';

require_login();
if (!is_admin()) {
    header('Location: ../jobs.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action']) && !empty($_POST['dispute_id'])) {
    $disputeId = intval($_POST['dispute_id']);
    $action = $_POST['action'];
    $resolutionNotes = trim($_POST['resolution_notes'] ?? '');
    
    $stmt = $pdo->prepare('SELECT * FROM disputes WHERE id = ?');
    $stmt->execute([$disputeId]);
    $dispute = $stmt->fetch();
    
    if ($dispute) {
        if ($action === 'investigating') {
            $pdo->prepare('UPDATE disputes SET status = ? WHERE id = ?')->execute(['investigating', $disputeId]);
            notify_user($dispute['reported_user_id'], 'Dispute update', 'A dispute involving you is being investigated by admin.', 'info');
        } elseif ($action === 'resolved' && $resolutionNotes !== '') {
            $pdo->prepare('UPDATE disputes SET status = ?, resolution_notes = ?, updated_at = NOW() WHERE id = ?')->execute(['resolved', $resolutionNotes, $disputeId]);
            notify_user($dispute['reported_by'], 'Dispute resolved', 'Your dispute has been resolved by admin.', 'success');
            notify_user($dispute['reported_user_id'], 'Dispute resolved', 'A dispute involving you has been resolved.', 'info');
        } elseif ($action === 'closed') {
            $pdo->prepare('UPDATE disputes SET status = ? WHERE id = ?')->execute(['closed', $disputeId]);
            notify_user($dispute['reported_by'], 'Dispute closed', 'Your dispute has been closed.', 'info');
        }
    }
    header('Location: disputes.php');
    exit;
}

$statusFilter = $_GET['status'] ?? 'open';
$stmt = $pdo->prepare('SELECT d.*, u1.name AS reported_by_name, u2.name AS reported_user_name, sr.title AS request_title FROM disputes d JOIN users u1 ON d.reported_by = u1.id JOIN users u2 ON d.reported_user_id = u2.id JOIN service_requests sr ON d.request_id = sr.id WHERE d.status = ? ORDER BY d.created_at DESC');
$stmt->execute([$statusFilter]);
$disputes = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Admin Disputes — AkuapemConnect</title>
    <link rel="stylesheet" href="../assets/css/style.css" />
</head>
<body>
    <header class="topbar">
        <a href="index.php" class="button button-secondary button-small">Back</a>
        <h1>Disputes</h1>
        <a href="../logout.php" class="button button-secondary button-small">Logout</a>
    </header>
    <main class="page-shell">
        <section class="panel">
            <div class="filter-form" style="margin-bottom: 16px;">
                <a href="disputes.php?status=open" class="button <?php echo $statusFilter === 'open' ? 'button-primary' : 'button-secondary'; ?> button-small">Open</a>
                <a href="disputes.php?status=investigating" class="button <?php echo $statusFilter === 'investigating' ? 'button-primary' : 'button-secondary'; ?> button-small">Investigating</a>
                <a href="disputes.php?status=resolved" class="button <?php echo $statusFilter === 'resolved' ? 'button-primary' : 'button-secondary'; ?> button-small">Resolved</a>
            </div>

            <?php if (empty($disputes)): ?>
                <div class="empty-state">No disputes with status "<?php echo sanitize($statusFilter); ?>".</div>
            <?php else: ?>
                <?php foreach ($disputes as $dispute): ?>
                    <article class="request-card">
                        <div class="request-head">
                            <div>
                                <h2><?php echo sanitize($dispute['request_title']); ?></h2>
                                <p class="meta">Reported by <?php echo sanitize($dispute['reported_by_name']); ?> against <?php echo sanitize($dispute['reported_user_name']); ?></p>
                            </div>
                            <span class="status status-<?php echo sanitize($dispute['status']); ?>"><?php echo strtoupper($dispute['status']); ?></span>
                        </div>
                        <p><strong>Type:</strong> <?php echo strtoupper(str_replace('_', ' ', $dispute['dispute_type'])); ?></p>
                        <p><?php echo sanitize($dispute['description']); ?></p>
                        <?php if ($dispute['resolution_notes']): ?>
                            <p><strong>Resolution:</strong> <?php echo sanitize($dispute['resolution_notes']); ?></p>
                        <?php endif; ?>
                        <p class="meta"><?php echo sanitize(date('M j, Y H:i', strtotime($dispute['created_at']))); ?></p>

                        <div class="request-footer">
                            <?php if ($dispute['status'] === 'open'): ?>
                                <form method="post" class="inline-form">
                                    <input type="hidden" name="dispute_id" value="<?php echo $dispute['id']; ?>" />
                                    <input type="hidden" name="action" value="investigating" />
                                    <button type="submit" class="button button-primary button-small">Investigate</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($dispute['status'] !== 'closed'): ?>
                                <form method="post" style="display: flex; gap: 8px;">
                                    <input type="hidden" name="dispute_id" value="<?php echo $dispute['id']; ?>" />
                                    <textarea name="resolution_notes" rows="2" placeholder="Resolution notes..." style="flex: 1;"></textarea>
                                    <input type="hidden" name="action" value="resolved" />
                                    <button type="submit" class="button button-primary button-small">Resolve</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
