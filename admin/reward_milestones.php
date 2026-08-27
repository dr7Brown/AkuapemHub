<?php
/**
 * Admin → Rewards → Milestones. CRUD for reward_milestones — mirrors
 * admin/referrals.php's config-style layout (same page shell/classes) since
 * this feature sits directly on top of the points module referrals.php owns.
 */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../modules/referrals/service.php';
require_once __DIR__ . '/../modules/rewards/service.php';

require_login();
if (!is_admin_or_manager()) { header('Location: index.php'); exit; }
require_mod_permission('manage_rewards');
$adminUser = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_milestone') {
        $id          = (int)($_POST['id'] ?? 0);
        $title       = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $reqPoints   = max(1, (int)($_POST['required_points'] ?? 0));
        $rewardType  = $_POST['reward_type'] ?? 'other';
        $rewardValue = ($_POST['reward_value'] ?? '') !== '' ? (float)$_POST['reward_value'] : null;
        $rewardDesc  = trim($_POST['reward_description'] ?? '');
        $frequency   = ($_POST['claim_frequency'] ?? 'one_time') === 'repeatable' ? 'repeatable' : 'one_time';
        $maxClaims   = trim($_POST['max_claims'] ?? '') !== '' ? max(1, (int)$_POST['max_claims']) : null;
        $startDate   = trim($_POST['start_date'] ?? '') ?: null;
        $endDate     = trim($_POST['end_date'] ?? '') ?: null;
        $active      = isset($_POST['active']) ? 1 : 0;

        if (!isset(reward_type_labels()[$rewardType])) $rewardType = 'other';

        if ($title === '') {
            flash('Title is required.', 'error');
        } elseif ($rewardDesc === '') {
            flash('Reward description is required.', 'error');
        } elseif ($endDate && $startDate && $endDate < $startDate) {
            flash('End date cannot be before the start date.', 'error');
        } else {
            if ($id > 0) {
                $pdo->prepare(
                    'UPDATE reward_milestones SET title=?, description=?, required_points=?, reward_type=?, reward_value=?,
                     reward_description=?, claim_frequency=?, max_claims=?, start_date=?, end_date=?, active=?, updated_at=NOW() WHERE id=?'
                )->execute([$title, $description ?: null, $reqPoints, $rewardType, $rewardValue, $rewardDesc, $frequency, $maxClaims, $startDate, $endDate, $active, $id]);
                log_audit_action($adminUser['id'], 'milestone_updated', "Updated milestone #{$id}: {$title}");
                flash('Milestone updated.', 'success');
            } else {
                $pdo->prepare(
                    'INSERT INTO reward_milestones (title, description, required_points, reward_type, reward_value, reward_description, claim_frequency, max_claims, start_date, end_date, active, created_by)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
                )->execute([$title, $description ?: null, $reqPoints, $rewardType, $rewardValue, $rewardDesc, $frequency, $maxClaims, $startDate, $endDate, $active, $adminUser['id']]);
                $newId = (int)$pdo->lastInsertId();
                log_audit_action($adminUser['id'], 'milestone_created', "Created milestone #{$newId}: {$title}");
                flash('Milestone added.', 'success');
            }
        }
        header('Location: reward_milestones.php'); exit;
    }

    if ($action === 'toggle_active') {
        $id = (int)($_POST['id'] ?? 0);
        $m  = get_milestone($id);
        if ($m) {
            $newActive = $m['active'] ? 0 : 1;
            $pdo->prepare('UPDATE reward_milestones SET active=?, updated_at=NOW() WHERE id=?')->execute([$newActive, $id]);
            log_audit_action($adminUser['id'], $newActive ? 'milestone_activated' : 'milestone_deactivated', "Milestone #{$id}: {$m['title']}");
            flash($newActive ? 'Milestone activated.' : 'Milestone deactivated.', 'success');
        }
        header('Location: reward_milestones.php'); exit;
    }
}

$milestones = get_all_milestones();
$typeLabels = reward_type_labels();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Reward Milestones — Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css" />
    <style>
        .rm-shell { max-width:900px; margin:0 auto; padding:18px 16px 60px; }
        .rm-table-wrap { overflow-x:auto; background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-sm); margin-bottom:20px; }
        table.data-table { width:100%; border-collapse:collapse; font-size:0.85rem; }
        table.data-table th { text-align:left; padding:8px; border-bottom:2px solid var(--border); font-size:0.72rem; color:var(--muted); text-transform:uppercase; }
        table.data-table td { padding:8px; border-bottom:1px solid var(--border); vertical-align:middle; }
        .rm-badge { display:inline-block; padding:2px 9px; border-radius:20px; font-size:0.7rem; font-weight:700; color:#fff; }
        .rm-form-section { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-sm); padding:18px; }
        .rm-grid2 { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
        @media(max-width:520px){ .rm-grid2 { grid-template-columns:1fr; } }
        label { font-weight:600; font-size:0.85rem; display:block; margin-bottom:4px; }
        .form-group { margin-bottom:12px; }
        .form-group input, .form-group select, .form-group textarea { width:100%; padding:7px 10px; border:1px solid var(--border); border-radius:6px; background:var(--surface); color:var(--text); box-sizing:border-box; }
        .rwd-module-nav { display:flex; gap:6px; margin-bottom:16px; flex-wrap:wrap; }
        .rwd-module-nav a { padding:6px 14px; border-radius:20px; background:var(--surface-muted,#f3f4f6); border:1px solid var(--border); font-size:.82rem; font-weight:700; text-decoration:none; color:var(--text-muted,#6b7280); }
        .rwd-module-nav a.active { background:var(--primary,#0f766e); color:#fff; border-color:var(--primary,#0f766e); }
    </style>
</head>
<body>
    <header class="topbar">
        <a href="index.php" style="text-decoration:none;color:inherit;font-weight:700;">‹ Admin</a>
        <span style="font-weight:700;margin-left:12px;">🏁 Reward Milestones</span>
    </header>
    <main class="rm-shell">
        <?php foreach (get_flashes() as $msg): ?>
            <div class="alert alert-<?php echo sanitize($msg['type']); ?>"><?php echo sanitize($msg['message']); ?></div>
        <?php endforeach; ?>

        <div class="rwd-module-nav">
            <a href="referrals.php">🔗 Referrals &amp; Points</a>
            <a href="reward_milestones.php" class="active">🏁 Reward Milestones</a>
            <a href="reward_claims.php">🎁 Reward Claims</a>
        </div>

        <div class="rm-table-wrap">
            <table class="data-table">
                <thead><tr>
                    <th>Title</th><th>Points</th><th>Reward</th><th>Status</th>
                    <th style="text-align:center;">Eligible</th>
                    <th style="text-align:center;">Claims</th>
                    <th>Actions</th>
                </tr></thead>
                <tbody>
                <?php if (!$milestones): ?>
                <tr><td colspan="7" style="text-align:center;padding:24px;color:var(--muted);">No milestones yet — add one below.</td></tr>
                <?php endif; ?>
                <?php foreach ($milestones as $m):
                    $mStatus = reward_milestone_status($m);
                    $eligible = get_milestone_eligible_user_count($m);
                    $remaining = $m['max_claims'] !== null ? max(0, (int)$m['max_claims'] - (int)$m['claims_count']) : null;
                    $mJson = htmlspecialchars(json_encode($m), ENT_QUOTES, 'UTF-8');
                    $statusColors = ['active'=>'#16a34a','upcoming'=>'#3b82f6','expired'=>'#6b7280','completed'=>'#8b5cf6','disabled'=>'#dc2626'];
                ?>
                <tr>
                    <td><strong><?php echo sanitize($m['title']); ?></strong><br><span class="meta"><?php echo (int)$m['required_points']; ?> pts required</span></td>
                    <td><?php echo number_format((int)$m['required_points']); ?></td>
                    <td><?php echo sanitize($typeLabels[$m['reward_type']] ?? $m['reward_type']); ?><br><span class="meta"><?php echo sanitize($m['reward_description']); ?></span></td>
                    <td><span class="rm-badge" style="background:<?php echo $statusColors[$mStatus]; ?>;"><?php echo reward_milestone_status_label($mStatus); ?></span></td>
                    <td style="text-align:center;"><?php echo number_format($eligible); ?></td>
                    <td style="text-align:center;"><?php echo (int)$m['claims_count']; ?><?php echo $m['max_claims'] !== null ? ' / ' . (int)$m['max_claims'] : ''; ?><?php if ($remaining !== null): ?><br><span class="meta"><?php echo $remaining; ?> left</span><?php endif; ?></td>
                    <td>
                        <button type="button" class="button button-small button-secondary" onclick='rmEdit(<?php echo $mJson; ?>)'>Edit</button>
                        <form method="post" class="inline-form" style="display:inline;">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="toggle_active">
                            <input type="hidden" name="id" value="<?php echo $m['id']; ?>">
                            <button type="submit" class="button button-small" style="background:<?php echo $m['active'] ? '#fee2e2' : '#d1fae5'; ?>;color:<?php echo $m['active'] ? '#991b1b' : '#065f46'; ?>;border-color:transparent;"><?php echo $m['active'] ? 'Deactivate' : 'Activate'; ?></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="rm-form-section">
            <h3 id="rm-form-title" style="margin:0 0 14px;">Add Milestone</h3>
            <form method="post" action="reward_milestones.php">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="save_milestone">
                <input type="hidden" name="id" id="rm_id" value="0">
                <div class="form-group">
                    <label>Title *</label>
                    <input type="text" name="title" id="rm_title" required placeholder="e.g. Active Contributor">
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" id="rm_description" rows="2" placeholder="Shown to users — what this milestone recognises"></textarea>
                </div>
                <div class="rm-grid2">
                    <div class="form-group">
                        <label>Required Points *</label>
                        <input type="number" name="required_points" id="rm_required_points" min="1" required>
                    </div>
                    <div class="form-group">
                        <label>Reward Type *</label>
                        <select name="reward_type" id="rm_reward_type">
                            <?php foreach ($typeLabels as $tv => $tl): ?>
                            <option value="<?php echo $tv; ?>"><?php echo sanitize($tl); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Reward Value (GHS, optional)</label>
                        <input type="number" name="reward_value" id="rm_reward_value" min="0" step="0.01" placeholder="e.g. 100">
                    </div>
                    <div class="form-group">
                        <label>Claim Frequency</label>
                        <select name="claim_frequency" id="rm_claim_frequency">
                            <option value="one_time">One-time (per user)</option>
                            <option value="repeatable">Repeatable</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label>Reward Description *</label>
                    <input type="text" name="reward_description" id="rm_reward_description" required placeholder="e.g. GHS 100 cash reward">
                </div>
                <div class="rm-grid2">
                    <div class="form-group">
                        <label>Maximum Claims (optional)</label>
                        <input type="number" name="max_claims" id="rm_max_claims" min="1" placeholder="Leave blank for unlimited">
                    </div>
                    <div class="form-group">
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-top:22px;">
                            <input type="checkbox" name="active" id="rm_active" value="1" checked style="width:18px;height:18px;">
                            Active
                        </label>
                    </div>
                    <div class="form-group">
                        <label>Start Date (optional)</label>
                        <input type="date" name="start_date" id="rm_start_date">
                    </div>
                    <div class="form-group">
                        <label>End Date (optional)</label>
                        <input type="date" name="end_date" id="rm_end_date">
                    </div>
                </div>
                <button type="submit" class="button button-primary">Save Milestone</button>
                <button type="button" class="button button-secondary" onclick="rmReset()">Clear</button>
            </form>
        </div>
    </main>
    <script>
    function rmEdit(m) {
        document.getElementById('rm_id').value = m.id;
        document.getElementById('rm_title').value = m.title;
        document.getElementById('rm_description').value = m.description || '';
        document.getElementById('rm_required_points').value = m.required_points;
        document.getElementById('rm_reward_type').value = m.reward_type;
        document.getElementById('rm_reward_value').value = m.reward_value || '';
        document.getElementById('rm_claim_frequency').value = m.claim_frequency;
        document.getElementById('rm_reward_description').value = m.reward_description;
        document.getElementById('rm_max_claims').value = m.max_claims || '';
        document.getElementById('rm_active').checked = !!parseInt(m.active, 10);
        document.getElementById('rm_start_date').value = m.start_date || '';
        document.getElementById('rm_end_date').value = m.end_date || '';
        document.getElementById('rm-form-title').textContent = 'Edit Milestone — ' + m.title;
        document.getElementById('rm-form-title').scrollIntoView({ behavior: 'smooth' });
    }
    function rmReset() {
        document.getElementById('rm_id').value = 0;
        document.querySelector('.rm-form-section form').reset();
        document.getElementById('rm-form-title').textContent = 'Add Milestone';
    }
    </script>
</body>
</html>
