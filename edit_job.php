<?php
/**
 * Edit a job that's already published (pending/open/partially_staffed) —
 * separate from request.php's draft/rejected edit flow, which recomputes
 * escrow/posting-fee logic that a LIVE job must not re-trigger. Only
 * content fields are editable here; budget stays locked once escrow money
 * is committed (payment_mode='escrow'), since applicants and the funded
 * amount are already tied to the original figure.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_module_enabled('jobs', 'Jobs & Services');
require_login();
$user = current_user();

$editableStatuses = ['pending', 'open', 'partially_staffed'];

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM service_requests WHERE id = ? AND customer_id = ?');
$stmt->execute([$id, $user['id']]);
$job = $stmt->fetch();

if (!$job) {
    flash('Job not found.', 'error');
    header('Location: jobs.php');
    exit;
}
if (in_array($job['status'], ['draft', 'rejected'], true)) {
    // Those go through the fuller request.php form (budget/payment-mode included).
    header('Location: request.php?edit=' . $id);
    exit;
}
if (!in_array($job['status'], $editableStatuses, true)) {
    flash('This job can no longer be edited — it has moved past the open-hiring stage.', 'error');
    header('Location: request_detail.php?id=' . $id);
    exit;
}

$categories  = get_categories();
$budgetLocked = $job['payment_mode'] === 'escrow'; // funds already committed at the original amount
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $title        = trim($_POST['title'] ?? '');
    $description  = trim($_POST['description'] ?? '');
    $categoryId   = (int)($_POST['category_id'] ?? 0);
    $location     = trim($_POST['location'] ?? '');
    $mapsLink     = trim($_POST['google_maps_link'] ?? '') ?: null;
    $skillsNeeded = trim($_POST['skills_needed'] ?? '');
    $jobTypeRaw   = $_POST['job_type'] ?? $job['job_type'];
    $jobType      = in_array($jobTypeRaw, ['on_site', 'remote', 'hybrid'], true) ? $jobTypeRaw : $job['job_type'];

    if ($title === '') $error = 'Title is required.';
    elseif ($description === '') $error = 'Description is required.';
    elseif (!$categoryId) $error = 'Please choose a category.';
    elseif (!$budgetLocked && $location === '') $error = 'Location is required.';

    if (!$error) {
        $pdo->prepare(
            'UPDATE service_requests SET title=?, description=?, category_id=?, location=?, google_maps_link=?, skills_needed=?, job_type=?, updated_at=NOW() WHERE id=? AND customer_id=?'
        )->execute([$title, $description, $categoryId, $location ?: null, $mapsLink, $skillsNeeded ?: null, $jobType, $id, $user['id']]);

        if (!empty($job['assigned_worker_id'])) {
            notify_user((int)$job['assigned_worker_id'], 'Job Details Updated',
                'The customer updated details for "' . $title . '". Review the changes on the job page.',
                'info', 'request_detail.php?id=' . $id);
        }

        flash('Job updated.', 'success');
        header('Location: request_detail.php?id=' . $id);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Job — <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .ej-shell { max-width: 640px; margin: 0 auto; padding: 18px 16px 60px; }
        .ej-card  { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; padding: 20px; }
        label { font-weight: 600; font-size: .86rem; display: block; margin-bottom: 4px; }
        .form-group { margin-bottom: 14px; }
    </style>
</head>
<body>

<header class="app-topbar">
    <a href="request_detail.php?id=<?php echo $id; ?>" class="button button-secondary button-small">← Back to Job</a>
    <span class="brand">Edit Job</span>
</header>

<main class="ej-shell">
    <?php if ($error): ?><div class="alert alert-error"><?php echo sanitize($error); ?></div><?php endif; ?>

    <div class="ej-card">
        <p style="font-size:.84rem;color:var(--text-muted,#6b7280);margin-top:0;">
            This job is already <?php echo str_replace('_', ' ', $job['status']); ?><?php echo $job['workers_approved'] > 0 ? ' — some workers are already involved, so ' : ', so '; ?>budget and headcount can't be changed here.
            <?php if ($budgetLocked): ?> Budget is locked because payment is already held in escrow.<?php endif; ?>
        </p>
        <form method="post" action="edit_job.php?id=<?php echo $id; ?>">
            <?php echo csrf_field(); ?>
            <div class="form-group">
                <label for="title">Title *</label>
                <input type="text" id="title" name="title" required value="<?php echo sanitize($_POST['title'] ?? $job['title']); ?>">
            </div>
            <div class="form-group">
                <label for="description">Description *</label>
                <textarea id="description" name="description" class="rich-editor" rows="5" required><?php echo $_POST['description'] ?? $job['description']; ?></textarea>
            </div>
            <div class="form-group">
                <label for="category_id">Category *</label>
                <select id="category_id" name="category_id" required>
                    <?php $selCat = $_POST['category_id'] ?? $job['category_id']; ?>
                    <?php foreach ($categories as $c): ?>
                    <option value="<?php echo $c['id']; ?>" <?php echo (string)$selCat === (string)$c['id'] ? 'selected' : ''; ?>><?php echo sanitize($c['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="location">Location</label>
                <input type="text" id="location" name="location" value="<?php echo sanitize($_POST['location'] ?? $job['location']); ?>">
            </div>
            <div class="form-group">
                <label for="google_maps_link">Google Maps Link (optional)</label>
                <input type="url" id="google_maps_link" name="google_maps_link" value="<?php echo sanitize($_POST['google_maps_link'] ?? $job['google_maps_link'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label for="skills_needed">Skills Needed (optional)</label>
                <input type="text" id="skills_needed" name="skills_needed" value="<?php echo sanitize($_POST['skills_needed'] ?? $job['skills_needed'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label for="job_type">Job Type</label>
                <?php $selType = $_POST['job_type'] ?? $job['job_type']; ?>
                <select id="job_type" name="job_type">
                    <option value="on_site" <?php echo $selType==='on_site'?'selected':''; ?>>On-site</option>
                    <option value="remote" <?php echo $selType==='remote'?'selected':''; ?>>Remote</option>
                    <option value="hybrid" <?php echo $selType==='hybrid'?'selected':''; ?>>Hybrid</option>
                </select>
            </div>
            <div class="form-group">
                <label>Budget <span class="meta">(locked once posted — cancel and repost to change it)</span></label>
                <input type="text" value="GH₵ <?php echo sanitize($job['budget']); ?>" disabled style="background:var(--surface-muted,#f3f4f6);">
            </div>
            <button type="submit" class="button button-primary" style="width:100%;padding:12px;">Save Changes</button>
        </form>
    </div>
</main>
<script src="assets/js/rich-editor.js" defer></script>
</body>
</html>
