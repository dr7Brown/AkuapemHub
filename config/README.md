# Push notification credentials

This directory holds the two secret files `push_notifications.php` looks for.
**Neither is checked into git** (see `.gitignore` below) — they contain real
private keys.

## Android — `firebase-service-account.json`

1. Go to the [Firebase Console](https://console.firebase.google.com/) and
   create a project (or use an existing Google Cloud project).
2. Add an Android app to it with package name `com.akuapemconnect.app`.
3. Download the generated `google-services.json` and place it at
   `mobile-app/android/app/google-services.json` (this is what lets the app
   itself receive pushes at all — separate from the file below, which is
   what lets *your server* send them).
4. Project Settings → Service Accounts → "Generate new private key" →
   downloads a JSON file. Save it here as `firebase-service-account.json`.

## iOS — `apns-auth-key.p8`

Requires an active Apple Developer Program membership ($99/yr).

1. developer.apple.com → Certificates, Identifiers & Profiles → Keys → "+".
2. Enable "Apple Push Notifications service (APNs)", create the key.
3. Download it once (Apple only lets you download a .p8 key ONE time) and
   save it here as `apns-auth-key.p8`.
4. Note the **Key ID** shown on that page, and your **Team ID** (top-right
   of the Apple Developer account, or Membership Details).
5. Set both as environment variables for whatever runs this PHP app —
   `APNS_KEY_ID` and `APNS_TEAM_ID` (e.g. in Apache's `SetEnv` config, or
   your host's environment panel). Optionally set `APNS_ENV=sandbox` while
   testing a development/TestFlight build (defaults to `production`).
6. In Xcode, add the "Push Notifications" capability to the App target
   (Signing & Capabilities tab) — this is required for the app to actually
   register for a token, separate from server-side sending above.

## Sending a push

Once both files exist, call `push_notify_user($userId, $title, $body, $link)`
(from `push_notifications.php`) anywhere you'd otherwise only call
`notify_user()` — they're independent, so pushing is opt-in per call site.
