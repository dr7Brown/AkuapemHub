<?php
/**
 * Partial: applicant card for manage_applicants.php
 * Required variables from parent scope:
 *   $app          — application row (worker_name, status, applied_at, worker_id, id, request_id, ...)
 *   $isFullyStaffed — bool, whether this job is fully staffed
 *   $user         — current logged-in user
 *   $statusLabels — array (defined in manage_applicants.php)
 */

$appStatus  = $app['status'];
$statusInfo = $statusLabels[$appStatus] ?? ['label' => ucfirst(str_replace('_', ' ', $appStatus)), 'class' => ''];
$isPending  = $appStatus === 'pending';
$isApproved = $appStatus === 'approved';
$returnId   = (int)$app['request_id'];

// Statuses that are still "actionable" (employer can change them)
$terminalStatuses = ['approved', 'hired', 'rejected', 'withdrawn', 'expired', 'position_filled', 'completed', 'declined'];
$isTerminal = in_array($appStatus, $terminalStatuses, true);

// Options available from current status (exclude current + terminal-only transitions)
$statusOptions = [];
if (!$isTerminal) {
    $all = [
        'under_review'        => 'Under Review',
        'shortlisted'         => 'Shortlisted',
        'interview_scheduled' => 'Interview Scheduled',
        'offered'             => 'Offered',
        'rejected'            => 'Reject',
    ];
    foreach ($all as $val => $lbl) {
        if ($val !== $appStatus) $statusOptions[$val] = $lbl;
    }
}
?>
<article class="applicant-card" <?php echo $isPending ? 'data-pending="1"' : ''; ?>>
    <div class="applicant-head">
        <div style="flex:1;min-width:0;">
            <strong><?php echo sanitize($app['worker_name']); ?></strong>
            <?php if (!empty($app['is_verified'])): ?>
                <span style="background:#22a06b;color:#fff;border-radius:4px;padding:0 5px;font-size:0.75rem;margin-left:4px;">✓ Verified</span>
            <?php endif; ?>
            <?php if (!empty($app['is_featured'])): ?>
                <span style="background:var(--primary);color:#fff;border-radius:4px;padding:0 5px;font-size:0.75rem;margin-left:4px;">Featured</span>
            <?php endif; ?>
            <div style="font-size:0.8rem;color:var(--muted,#6b7280);margin-top:2px;">
                Applied <?php echo sanitize(time_ago($app['applied_at'])); ?>
                <?php if (!empty($app['worker_email'])): ?>
                    · <?php echo sanitize($app['worker_email']); ?>
                <?php endif; ?>
                <?php if (!empty($app['worker_location'])): ?>
                    · <?php echo sanitize($app['worker_location']); ?>
                <?php endif; ?>
            </div>
            <?php if (!empty($app['bio'])): ?>
                <?php $appBioText = trim(strip_tags($app['bio'])); ?>
                <p style="font-size:0.83rem;color:var(--muted,#6b7280);margin:4px 0 0;"><?php echo sanitize(mb_substr($appBioText, 0, 120)); ?><?php echo mb_strlen($appBioText) > 120 ? '…' : ''; ?></p>
            <?php endif; ?>
        </div>
        <span class="status <?php echo sanitize($statusInfo['class']); ?>"><?php echo strtoupper($statusInfo['label']); ?></span>
    </div>

    <div class="card-actions" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;align-items:center;">
        <a href="worker_profile_public.php?id=<?php echo $app['worker_id']; ?>" class="button button-secondary button-small">View Profile</a>

        <?php if ($isApproved || $appStatus === 'hired'): ?>
            <a href="chat_start.php?user_id=<?php echo $app['worker_id']; ?>&job_id=<?php echo $returnId; ?>" class="button button-secondary button-small">💬 Message</a>
        <?php endif; ?>

        <?php if ($isPending && !$isFullyStaffed): ?>
            <form method="post" style="display:inline;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="application_id" value="<?php echo $app['id']; ?>">
                <input type="hidden" name="return_id" value="<?php echo $returnId; ?>">
                <button type="submit" name="action" value="approve" class="button button-primary button-small"
                        onclick="return confirm('Approve this worker?')">✓ Approve</button>
            </form>
        <?php elseif ($isPending && $isFullyStaffed): ?>
            <span style="font-size:0.8rem;color:#92400e;background:#fef3c7;padding:3px 10px;border-radius:20px;">Job fully staffed</span>
        <?php endif; ?>

        <?php if ($isPending): ?>
            <form method="post" style="display:inline;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="application_id" value="<?php echo $app['id']; ?>">
                <input type="hidden" name="return_id" value="<?php echo $returnId; ?>">
                <button type="submit" name="action" value="reject" class="button button-secondary button-small"
                        onclick="return confirm('Reject this application?')" style="color:#ef4444;border-color:#ef4444;">✗ Reject</button>
            </form>
        <?php endif; ?>

        <?php if (!$isTerminal && !$isPending && !empty($statusOptions)): ?>
            <form method="post" style="display:inline-flex;gap:6px;align-items:center;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="application_id" value="<?php echo $app['id']; ?>">
                <input type="hidden" name="return_id" value="<?php echo $returnId; ?>">
                <select name="new_status" class="status-select">
                    <option value="">Move to…</option>
                    <?php foreach ($statusOptions as $val => $lbl): ?>
                        <option value="<?php echo $val; ?>"><?php echo $lbl; ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="button button-secondary button-small">Update</button>
            </form>
        <?php endif; ?>

        <?php if (!$isTerminal && $isPending && !empty($statusOptions)): ?>
            <form method="post" style="display:inline-flex;gap:6px;align-items:center;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="application_id" value="<?php echo $app['id']; ?>">
                <input type="hidden" name="return_id" value="<?php echo $returnId; ?>">
                <select name="new_status" class="status-select">
                    <option value="">Skip to…</option>
                    <?php foreach ($statusOptions as $val => $lbl): ?>
                        <?php if ($val !== 'rejected'): // reject already has its own button ?>
                        <option value="<?php echo $val; ?>"><?php echo $lbl; ?></option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="button button-secondary button-small">Update</button>
            </form>
        <?php endif; ?>
    </div>
</article>
