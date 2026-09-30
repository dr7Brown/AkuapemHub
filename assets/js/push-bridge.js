/**
 * Push-notification bridge for the AkuapemConnect mobile app shell.
 *
 * This file is loaded on every logged-in page (see partials/bottom_nav.php)
 * on the live website too, but it's a silent no-op there — it only does
 * anything when the page is actually running inside the Capacitor native
 * app (Android/iOS), detected via `window.Capacitor.isNativePlatform()`.
 * Capacitor's JS bridge is auto-injected into remote content loaded via its
 * `server.url` config, so `window.Capacitor` exists there but never in a
 * normal desktop/mobile browser tab.
 *
 * Registers the device for push notifications, then POSTs the resulting
 * token to register_push_token.php so the server can target this device.
 * Tapping a delivered notification deep-links into whatever page the
 * original notify_user() call set as its `link`.
 */
(function () {
    'use strict';

    if (!window.Capacitor || typeof window.Capacitor.isNativePlatform !== 'function' || !window.Capacitor.isNativePlatform()) {
        return; // Running in a normal browser — nothing to do.
    }

    var PushNotifications = window.Capacitor.Plugins && window.Capacitor.Plugins.PushNotifications;
    if (!PushNotifications) return;

    function sendTokenToServer(token) {
        var body = new URLSearchParams();
        body.set('token', token);
        body.set('platform', window.Capacitor.getPlatform());
        body.set('csrf_token', window.__CSRF_TOKEN__ || '');

        fetch('register_push_token.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
            credentials: 'same-origin'
        }).catch(function () {
            // Silent failure — push is a nice-to-have, never blocks the app.
        });
    }

    PushNotifications.addListener('registration', function (token) {
        if (token && token.value) sendTokenToServer(token.value);
    });

    PushNotifications.addListener('registrationError', function (err) {
        console.warn('[push] registration error', err);
    });

    // Tapping a delivered notification: jump to whatever page notify_user()
    // attached as this notification's link, same as tapping it in the
    // in-app notification bell.
    PushNotifications.addListener('pushNotificationActionPerformed', function (action) {
        var link = action && action.notification && action.notification.data && action.notification.data.link;
        if (link) window.location.href = link;
    });

    PushNotifications.checkPermissions().then(function (result) {
        if (result.receive === 'granted') {
            PushNotifications.register();
            return;
        }
        if (result.receive === 'prompt' || result.receive === 'prompt-with-rationale') {
            PushNotifications.requestPermissions().then(function (r) {
                if (r.receive === 'granted') PushNotifications.register();
            });
        }
        // 'denied' — respect the user's choice, don't nag every page load.
    });
})();
