/**
 * Makes "Sign in with Google" work inside the Android/iOS app shell.
 *
 * Google actively detects and refuses to complete OAuth sign-in inside an
 * embedded WebView (Capacitor's own WebView counts as one) — it lets you
 * pick an account and enter a password, then tries to hand off to a real
 * browser and fails, since the WebView doesn't know what to do with that.
 * This is documented, not a bug — see Google's "disallowed_useragent"
 * policy for embedded user agents.
 *
 * Fix: when running inside the app, open google_auth.php in the *system*
 * browser instead (a Chrome Custom Tab / SFSafariViewController, which
 * Google's checks accept) via @capacitor/browser. That browser finishes
 * the OAuth flow fine, but it has its own cookie jar — separate from the
 * app's WebView — so completing login there doesn't log the app itself in.
 * google_callback.php knows this (google_auth.php marked the session with
 * ?mobile=1) and, instead of a normal redirect, hands off to the app via a
 * `com.akuapemconnect.app://oauth-callback` deep link carrying a one-time
 * token. This script catches that link (Capacitor's `appUrlOpen` event),
 * closes the browser, and loads mobile_login_exchange.php?token=... in the
 * app's own WebView — the one request that actually sets a session cookie
 * the app can see.
 *
 * No-ops entirely outside the app (window.Capacitor doesn't exist there),
 * so this is safe to load on every page.
 */
(function () {
    'use strict';

    if (!window.Capacitor || typeof window.Capacitor.isNativePlatform !== 'function' || !window.Capacitor.isNativePlatform()) {
        return;
    }

    var Browser = window.Capacitor.Plugins && window.Capacitor.Plugins.Browser;
    var App = window.Capacitor.Plugins && window.Capacitor.Plugins.App;
    if (!Browser || !App) return;

    document.addEventListener('click', function (e) {
        var link = e.target && e.target.closest ? e.target.closest('a[href*="google_auth.php"]') : null;
        if (!link) return;
        e.preventDefault();
        var url = new URL(link.href, window.location.href);
        url.searchParams.set('mobile', '1');
        Browser.open({ url: url.toString() }).catch(function () {});
    });

    App.addListener('appUrlOpen', function (data) {
        var incoming = data && data.url;
        if (!incoming || incoming.indexOf('oauth-callback') === -1) return;

        Browser.close().catch(function () {});

        var url;
        try { url = new URL(incoming); } catch (e) { return; }
        var token = url.searchParams.get('token');
        var dest = url.searchParams.get('dest') || 'jobs.php';
        if (!token) return;

        window.location.href = 'mobile_login_exchange.php?token=' + encodeURIComponent(token) + '&dest=' + encodeURIComponent(dest);
    });
})();
