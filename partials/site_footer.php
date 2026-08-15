<?php
/**
 * Shared sponsors showcase + site footer + social tiles, matching the block
 * at the bottom of index.php. Self-contained (own <style>, scoped class
 * names, queries sponsors itself) so it's safe to require at the bottom of
 * any page's <body> — right before the bottom_nav include, or right before
 * </body> on pages with no bottom nav.
 */
global $pdo;
$__footerSponsors = [];
try {
    $__footerSponsors = $pdo->query(
        "SELECT s.id, s.name, s.logo_path, s.website_url, sp.name AS package_name, sp.price AS package_price
         FROM sponsors s
         LEFT JOIN sponsor_packages sp ON s.package_id = sp.id
         WHERE s.status='active' AND (s.end_date IS NULL OR s.end_date >= CURDATE())
         ORDER BY sp.price DESC, s.created_at DESC LIMIT 12"
    )->fetchAll();
} catch (Exception $e) {}
$__footerUser = function_exists('current_user') ? current_user() : null;
?>
<?php if ($__footerSponsors): ?>
<div class="sf-sponsors-section">
    <div class="sf-sponsors-head">
        <h2>With Thanks To Our Sponsors</h2>
        <p>The businesses and partners supporting the AkuapemConnect community</p>
    </div>
    <div class="sf-sponsor-row">
        <?php foreach ($__footerSponsors as $sp): ?>
        <a href="<?php echo $sp['website_url'] ? sanitize($sp['website_url']) : 'become_sponsor.php'; ?>" class="sf-sponsor-card" <?php echo $sp['website_url'] ? 'target="_blank" rel="noopener sponsored"' : ''; ?> title="<?php echo sanitize($sp['name']); ?>">
            <span class="sf-sponsor-logo-frame">
                <img src="<?php echo sanitize($sp['logo_path']); ?>" alt="<?php echo sanitize($sp['name']); ?>">
            </span>
            <span class="sf-sponsor-name"><?php echo sanitize($sp['name']); ?></span>
            <?php if ($sp['package_name']): ?>
            <span class="sf-sponsor-tier"><?php echo sanitize($sp['package_name']); ?></span>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>
    </div>
    <div class="sf-sponsors-cta">
        <a href="become_sponsor.php" class="button button-secondary">🤝 Become a Sponsor</a>
    </div>
</div>
<?php endif; ?>

<?php if (!$__footerUser) require __DIR__ . '/social_tiles.php'; ?>

<footer style="text-align:center;padding:20px 16px <?php echo $__footerUser ? '80px' : '32px'; ?>;font-size:.8rem;color:#6b7280;border-top:1px solid #e5e7eb;margin-top:8px;">
    &copy; <?php echo date('Y'); ?> <?php echo sanitize(APP_NAME); ?> &nbsp;·&nbsp;
    <a href="about.php"   style="color:#6b7280;">About</a> &nbsp;·&nbsp;
    <a href="support.php" style="color:#6b7280;">Support</a> &nbsp;·&nbsp;
    <a href="terms.php"   style="color:#6b7280;">Terms</a> &nbsp;·&nbsp;
    <a href="privacy.php" style="color:#6b7280;">Privacy</a> &nbsp;·&nbsp;
    <a href="contact.php" style="color:#6b7280;">Contact</a>
</footer>
<style>
.sf-sponsors-section { margin:44px 0 24px; padding:34px 16px; border-top:1px solid var(--border,#e5e7eb); border-bottom:1px solid var(--border,#e5e7eb); }
.sf-sponsors-head { text-align:center; margin-bottom:24px; }
.sf-sponsors-head h2 { font-size:.8rem; font-weight:800; text-transform:uppercase; letter-spacing:.12em; color:var(--muted,#6b7280); margin:0; }
.sf-sponsors-head p { font-size:.86rem; color:var(--muted,#6b7280); margin:6px 0 0; }
.sf-sponsor-row { display:flex; flex-wrap:wrap; gap:26px; justify-content:center; }
.sf-sponsor-card { display:flex; flex-direction:column; align-items:center; gap:9px; text-decoration:none; width:150px; }
.sf-sponsor-logo-frame { display:flex; align-items:center; justify-content:center; width:150px; height:104px; background:#fff; border:1px solid var(--border,#e5e7eb); border-radius:10px; padding:16px; box-shadow:0 1px 2px rgba(0,0,0,.04); transition:box-shadow .2s ease,transform .2s ease,border-color .2s ease; }
.sf-sponsor-card:hover .sf-sponsor-logo-frame { box-shadow:0 12px 28px rgba(0,0,0,.1); transform:translateY(-3px); border-color:var(--primary,#0f766e); }
.sf-sponsor-logo-frame img { max-width:100%; max-height:100%; object-fit:contain; }
.sf-sponsor-name { font-size:.82rem; font-weight:700; color:var(--text,#1f2937); text-align:center; line-height:1.25; }
.sf-sponsor-tier { font-size:.66rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:var(--muted,#6b7280); text-align:center; }
.sf-sponsors-cta { text-align:center; margin-top:26px; }
@media (min-width:768px) {
    .sf-sponsors-section { padding:48px 16px; }
    .sf-sponsor-row { gap:36px; }
    .sf-sponsor-card { width:210px; }
    .sf-sponsor-logo-frame { width:210px; height:150px; padding:20px; }
}
</style>
