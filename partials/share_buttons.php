<?php
/**
 * Reusable "Share" button row — WhatsApp / Facebook / X / native share sheet / copy link.
 * Include with $shareTitle and $shareUrl already set in scope, e.g.:
 *   $shareTitle = $product['name'];
 *   $shareUrl   = rtrim(BASE_URL,'/') . '/product.php?id=' . $product['id'];
 *   require __DIR__ . '/partials/share_buttons.php';
 * Optional: $shareText (defaults to "$shareTitle — " . APP_NAME).
 */
$shareText = $shareText ?? ($shareTitle . ' — ' . APP_NAME);
?>
<style>
    .shb-share { display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
    .shb-share span.shb-label { font-size:.82rem; font-weight:700; color:var(--text-muted,#6b7280); }
    .shb-share a, .shb-share button {
        display:inline-flex; align-items:center; justify-content:center; gap:6px; width:42px; height:42px; padding:0;
        border-radius:50%; font-size:.82rem; font-weight:700; text-decoration:none; color:#fff; border:none; cursor:pointer;
        transition:transform .15s ease, box-shadow .15s ease;
    }
    .shb-share a:hover, .shb-share button:hover { transform:translateY(-2px); box-shadow:0 6px 16px rgba(0,0,0,.2); }
    .shb-wa   { background:#25D366; }
    .shb-fb   { background:#1877F2; }
    .shb-tw   { background:#000; }
    .shb-native { background:var(--primary,#0f766e); display:none; }
    .shb-copy { background:var(--surface,#fff); color:var(--text,#111); border:1px solid var(--border,#e5e7eb) !important; width:auto !important; padding:0 16px !important; border-radius:21px !important; }
</style>
<div class="shb-share">
    <span class="shb-label">Share:</span>
    <button type="button" class="shb-native" title="Share" aria-label="Share" data-title="<?php echo sanitize($shareTitle); ?>" data-text="<?php echo sanitize($shareText); ?>" data-url="<?php echo sanitize($shareUrl); ?>" onclick="shbNativeShare(this)">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="#fff" xmlns="http://www.w3.org/2000/svg"><path d="M18 16.08c-.76 0-1.44.3-1.96.77L8.91 12.7c.05-.23.09-.46.09-.7s-.04-.47-.09-.7l7.05-4.11c.54.5 1.25.81 2.04.81 1.66 0 3-1.34 3-3s-1.34-3-3-3-3 1.34-3 3c0 .24.04.47.09.7L7.04 9.81C6.5 9.31 5.79 9 5 9c-1.66 0-3 1.34-3 3s1.34 3 3 3c.79 0 1.5-.31 2.04-.81l7.12 4.16c-.05.21-.08.43-.08.65 0 1.61 1.31 2.92 2.92 2.92 1.61 0 2.92-1.31 2.92-2.92s-1.31-2.92-2.92-2.92z"/></svg>
    </button>
    <a href="https://wa.me/?text=<?php echo urlencode($shareText . ' ' . $shareUrl); ?>" target="_blank" rel="noopener" class="shb-wa" title="Share on WhatsApp" aria-label="Share on WhatsApp">
        <svg viewBox="0 0 24 24" width="19" height="19" fill="#fff" xmlns="http://www.w3.org/2000/svg"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347M12.05 21.785h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884M20.463 3.488A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413"/></svg>
    </a>
    <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo urlencode($shareUrl); ?>" target="_blank" rel="noopener" class="shb-fb" title="Share on Facebook" aria-label="Share on Facebook">
        <svg viewBox="0 0 24 24" width="17" height="17" fill="#fff" xmlns="http://www.w3.org/2000/svg"><path d="M9.101 23.691v-7.98H6.627v-3.667h2.474v-1.58c0-4.085 1.848-5.978 5.858-5.978.401 0 .955.042 1.468.103a8.68 8.68 0 0 1 1.141.195v3.325a8.623 8.623 0 0 0-.653-.036 26.805 26.805 0 0 0-.732-.009c-.954 0-1.639.267-2.05.68-.412.415-.622 1.16-.622 2.269v1.03h3.884l-.505 3.667h-3.379v7.98H9.101z"/></svg>
    </a>
    <a href="https://twitter.com/intent/tweet?text=<?php echo urlencode($shareText); ?>&url=<?php echo urlencode($shareUrl); ?>" target="_blank" rel="noopener" class="shb-tw" title="Share on X (Twitter)" aria-label="Share on X (Twitter)">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="#fff" xmlns="http://www.w3.org/2000/svg"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
    </a>
    <button type="button" class="shb-copy" data-url="<?php echo sanitize($shareUrl); ?>" onclick="shbCopyLink(this)">🔗 Copy Link</button>
</div>
<script>
document.querySelectorAll('.shb-native').forEach(function (btn) { if (navigator.share) btn.style.display = 'inline-flex'; });
if (!window.shbNativeShare) {
    window.shbNativeShare = function (btn) {
        navigator.share({ title: btn.dataset.title, text: btn.dataset.text, url: btn.dataset.url }).catch(function () {});
    };
    window.shbCopyLink = function (btn) {
        var url = btn.dataset.url, done = function () { var t = btn.textContent; btn.textContent = 'Copied!'; setTimeout(function () { btn.textContent = t; }, 1600); };
        if (navigator.clipboard) { navigator.clipboard.writeText(url).then(done).catch(done); } else { done(); }
    };
}
</script>
