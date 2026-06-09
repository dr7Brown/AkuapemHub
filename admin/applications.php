<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';

require_login();
if (!is_admin_or_manager()) {
    header('Location: ../dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action']) && !empty($_POST['application_id'])) {
    $applicationId = intval($_POST['application_id']);
    $stmt = $pdo->prepare('SELECT a.*, sr.title AS request_title, sr.status AS request_status, sr.customer_id, w.name AS worker_name, w.email AS worker_email, c.name AS customer_name, c.email AS customer_email
        FROM applications a
        JOIN service_requests sr ON a.request_id = sr.id
        JOIN users w ON a.worker_id = w.id
        JOIN users c ON sr.customer_id = c.id
        WHERE a.id = ?');
    $stmt->execute([$applicationId]);
    $application = $stmt->fetch();

    if ($application && $application['status'] === 'pending') {
        if ($_POST['action'] === 'approve' && $application['request_status'] === 'open') {
            $pdo->beginTransaction();
            try {
                $pdo->prepare("UPDATE service_requests SET assigned_worker_id = ?, status = 'in_progress', updated_at = NOW() WHERE id = ? AND status = 'open'")->execute([$application['worker_id'], $application['request_id']]);
                $pdo->prepare("UPDATE applications SET status = 'accepted' WHERE id = ?")->execute([$applicationId]);
                $pdo->prepare("UPDATE applications SET status = 'declined' WHERE request_id = ? AND id != ? AND status = 'pending'")->execute([$application['request_id'], $applicationId]);
                $pdo->commit();

                notify_user($application['worker_id'], 'Application approved', "Your application for '{$application['request_title']}' was approved. The job is now assigned to you.", 'success');
                send_email_notification($application['worker_email'], 'Your job application was approved', "Hello {$application['worker_name']},\n\nYour application for '{$application['request_title']}' has been approved. The job is now in progress.\n\nThank you.", $application['worker_id']);
                notify_user($application['customer_id'], 'Worker assigned', "'{$application['worker_name']}' was assigned to your request '{$application['request_title']}'.", 'success');
            } catch (Exception $e) {
                $pdo->rollBack();
                flash('Unable to approve this application. Please try again.', 'error');
                header('Location: applications.php');
                exit;
            }
            flash('Application approved and worker assigned.');
        } elseif ($_POST['action'] === 'decline') {
            $pdo->prepare("UPDATE applications SET status = 'declined' WHERE id = ?")->execute([$applicationId]);
            notify_user($application['worker_id'], 'Application declined', "Your application for '{$application['request_title']}' was not approved this time.", 'warning');
            flash('Application declined.');
        }
    }
    header('Location: applications.php');
    exit;
}

$stmt = $pdo->query("SELECT a.*, sr.title AS request_title, sr.status AS request_status, sr.location, sr.budget,
    w.name AS worker_name, w.email AS worker_email,
    wp.is_verified AS worker_is_verified, wp.is_featured AS worker_is_featured
    FROM applications a
    JOIN service_requests sr ON a.request_id = sr.id
    JOIN users w ON a.worker_id = w.id
    LEFT JOIN worker_profiles wp ON w.id = wp.user_id
    ORDER BY (a.status = 'pending') DESC, a.applied_at DESC
    LIMIT 200");
$applications = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Job Applications — AkuapemHub</title>
    <link rel="stylesheet" href="../assets/css/style.css" />
</head>
<body>
    <header class="topbar">
        <a href="index.php" class="button button-secondary button-small">Back</a>
        <h1>Job applications</h1>
        <a href="../logout.php" class="button button-secondary button-small">Logout</a>
    </header>
    <main class="page-shell">
        <section class="panel">
            <?php if (!$applications): ?>
                <div class="empty-state">No job applications yet.</div>
            <?php else: ?>
                <?php foreach ($applications as $application): ?>
                    <article class="request-card">
                        <div class="request-head">
                            <h2><?php echo sanitize($application['request_title']); ?></h2>
                            <span class="status status-<?php echo sanitize($application['status']); ?>"><?php echo strtoupper($application['status']); ?></span>
                        </div>
                        <p class="meta"><?php echo sanitize($application['location']); ?> • GH₵ <?php echo sanitize($application['budget']); ?> • Job status: <?php echo strtoupper(str_replace('_', ' ', $application['request_status'])); ?></p>
                        <p>Applicant: <strong><?php echo sanitize($application['worker_name']); ?></strong>
                            <?php if ($application['worker_is_verified']): ?>
                                <span style="display:inline-flex;align-items:center;background:#22a06b;color:#fff;border-radius:4px;padding:0 5px;font-size:0.78rem;margin-left:4px;vertical-align:middle;"><strong>✓</strong>erified</span>
                            <?php endif; ?>
                            <?php if ($application['worker_is_featured']): ?>
                                <span class="badge" style="background:var(--primary);color:#fff;font-size:0.75rem;margin-left:4px;vertical-align:middle;">Featured</span>
                            <?php endif; ?>
                            (<?php echo sanitize($application['worker_email']); ?>)</p>
                        <p class="meta">Applied <?php echo sanitize(time_ago($application['applied_at'])); ?></p>
                        <div class="request-footer">
                            <a href="../worker_profile_public.php?id=<?php echo $application['worker_id']; ?>" class="button button-secondary button-small">View worker profile</a>
                            <?php if ($application['status'] === 'pending'): ?>
                                <form method="post" class="inline-form" action="applications.php">
                                    <input type="hidden" name="application_id" value="<?php echo $application['id']; ?>" />
                                    <?php if ($application['request_status'] === 'open'): ?>
                                        <button type="submit" name="action" value="approve" class="button button-primary">Approve</button>
                                    <?php endif; ?>
                                    <button type="submit" name="action" value="decline" class="button button-secondary">Decline</button>
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
