<?php
/**
 * "Get the app" banner for Android web visitors — an interim way to
 * distribute the app by direct APK download while it isn't on the Play
 * Store yet (see download_app.php). Self-contained; safe to require
 * anywhere in <body>.
 *
 * Detection: shown only to a real Android browser. Capacitor's own WebView
 * is *also* an Android WebView, but it always carries a "wv" token in its
 * user agent (Chrome's standard marker for "this is an embedded WebView,
 * not the Chrome app") — checking for that excludes visitors who are
 * already using the app itself.
 */
$__adbUa = $_SERVER['HTTP_USER_AGENT'] ?? '';
$__adbShow = (stripos($__adbUa, 'Android') !== false) && (stripos($__adbUa, 'wv') === false);
?>
<?php if ($__adbShow): ?>
<div id="app-download-banner" class="adb-banner" hidden>
    <img src="assets/images/ac%20logo.png" alt="" class="adb-icon">
    <div class="adb-text">
        <strong>Get the AkuapemConnect app</strong>
        <span>Faster access, push alerts for messages &amp; orders.</span>
    </div>
    <a href="download_app.php" class="adb-btn">⬇ Download</a>
    <button type="button" class="adb-close" onclick="adbDismiss()" aria-label="Dismiss">×</button>
</div>
<style>
    .adb-banner {
        display:flex; align-items:center; gap:10px; padding:10px 12px;
        background:var(--primary,#2f8f5b); color:#fff; position:sticky; top:0; z-index:500;
        box-shadow:0 2px 8px rgba(0,0,0,.12);
    }
    .adb-icon { width:34px; height:34px; border-radius:8px; object-fit:contain; background:#fff; padding:3px; flex-shrink:0; }
    .adb-text { flex:1; min-width:0; display:flex; flex-direction:column; line-height:1.3; }
    .adb-text strong { font-size:.85rem; }
    .adb-text span { font-size:.72rem; opacity:.9; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .adb-btn { flex-shrink:0; background:#fff; color:var(--primary-dark,#246b45); font-weight:700; font-size:.8rem; padding:7px 14px; border-radius:20px; text-decoration:none; white-space:nowrap; }
    .adb-close { flex-shrink:0; background:none; border:none; color:#fff; font-size:1.3rem; line-height:1; padding:0 4px; cursor:pointer; opacity:.85; }
</style>
<script>
(function () {
    var KEY = 'adb_dismissed_until';
    var banner = document.getElementById('app-download-banner');
    if (!banner) return;
    try {
        var until = localStorage.getItem(KEY);
        if (until && Date.now() < parseInt(until, 10)) return; // still within a dismissal window
    } catch (e) {}
    banner.hidden = false;
})();
function adbDismiss() {
    var banner = document.getElementById('app-download-banner');
    if (banner) banner.hidden = true;
    try {
        // Don't show again for 7 days after a dismiss.
        localStorage.setItem('adb_dismissed_until', String(Date.now() + 7 * 24 * 60 * 60 * 1000));
    } catch (e) {}
}
</script>
<?php endif; ?>
