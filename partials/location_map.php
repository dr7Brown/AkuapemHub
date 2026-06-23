<?php
/**
 * Location display partial — Google Maps link only (no third-party map libraries).
 *
 * Usage:
 *   require_once __DIR__ . '/partials/location_map.php';
 *   render_location_map($googleMapsLink, $venueName, $address);
 *
 * All parameters are optional. Pass at least one to get output.
 */
function render_location_map(string $googleMapsLink = '', string $venue = '', string $address = ''): void {
    if (!$googleMapsLink && !$venue && !$address) return;
    ?>
    <div style="background:var(--surface-muted,#f8fafc);border:1px solid var(--border,#e5e7eb);border-radius:10px;padding:12px 14px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
        <div>
            <?php if ($venue): ?><div style="font-weight:700;font-size:.88rem;"><?php echo htmlspecialchars($venue, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
            <?php if ($address): ?><div style="font-size:.78rem;color:var(--text-muted,#6b7280);">📍 <?php echo htmlspecialchars($address, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
        </div>
        <?php if ($googleMapsLink): ?>
        <a href="<?php echo htmlspecialchars($googleMapsLink, ENT_QUOTES, 'UTF-8'); ?>"
           target="_blank" rel="noopener noreferrer"
           class="button button-secondary button-small"
           style="white-space:nowrap;flex-shrink:0;">
            🗺 View on Google Maps ↗
        </a>
        <?php endif; ?>
    </div>
    <?php
}
