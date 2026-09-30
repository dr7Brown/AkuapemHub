# AkuapemConnect — Android & iOS app shell

A [Capacitor](https://capacitorjs.com) hybrid app: a thin native shell that
loads `https://akuapemconnect.com` directly, with real native plugins
(push notifications, native splash/status bar, browser tabs) so it clears
Apple's "not just a repackaged website" review bar. Everything the site
already does (auth, marketplace, chat, payments…) works unchanged inside it.

App ID: `com.akuapemconnect.app` · Display name: `AkuapemConnect`

## Google Sign-In

Google actively detects and blocks OAuth sign-in inside an embedded WebView
(this app's own WebView counts) — it lets you pick an account and enter a
password, then tries to hand off to a real browser and fails there. This is
policy, not a bug in this project, and it's already handled:

- `assets/js/google-auth-bridge.js` (loaded on `login.php`/`register.php`)
  opens `google_auth.php` in the *system* browser instead
  (`@capacitor/browser`), which Google's checks accept.
- `google_callback.php` can't redirect straight back into the app from
  there — that browser has its own cookie jar, separate from the app's
  WebView — so it instead hands off via a `com.akuapemconnect.app://`
  deep link carrying a single-use, 5-minute token (`mobile_login_tokens`
  table).
- The same bridge script catches that deep link and loads
  `mobile_login_exchange.php?token=...` in the app's own WebView — the one
  request that actually sets a session cookie the app can see.

The deep link only works because `AndroidManifest.xml` and `Info.plist`
both register `com.akuapemconnect.app` as a URL scheme — if you ever
change the app ID, update both (and the hardcoded scheme in
`google_callback.php`'s redirect and `google-auth-bridge.js`'s no other
reference needed, it reads the scheme from the URL Capacitor delivers).

## ⚠️ Read this before submitting to Apple

**Apple's App Store Review Guideline 3.1.1** requires *digital* content or
services consumed inside the app to go through Apple's In-App Purchase
system — a third-party processor like Paystack is not allowed for those.
Physical goods/services (marketplace products, delivery, most Quick
Services, event tickets tied to a real-world event) are exempt and Paystack
is fine for them. But a few flows here are genuinely digital/platform-only
and **will likely get the app rejected or pulled** if paid via Paystack
inside the iOS app specifically:

- Featured/boosted listing upgrades (events, funerals, news, marketplace, jobs)
- Seller "Pro" subscription plans
- Any other "unlock a platform feature" purchase with no physical component

Before submitting to Apple, either (a) implement Apple In-App Purchase for
those specific flows on iOS only (Android/Play + the website keep Paystack —
Google's policy is far more permissive here), or (b) hide/disable those
specific purchase entry points inside the iOS build only. This is a policy
compliance decision, not a code limitation — happy to help implement
whichever route you pick once you've decided.

## What's already done

- Native Android project (`android/`) and Xcode project (`ios/`) — both
  buildable once you have the right toolchain (below).
- App icon + splash screen generated for both platforms from the real
  AkuapemConnect logo (`assets/icon-source-1024.png` /
  `splash-source-2732.png`). **The source logo file is fairly low-resolution
  (457×408px)**, so the generated icons are a little soft up close — fine to
  ship, but worth swapping for a proper high-res/vector logo before your
  final release if you have one.
- Android manifest permissions (`INTERNET`, `POST_NOTIFICATIONS`,
  `ACCESS_NETWORK_STATE`) and adaptive icon layers.
- iOS `Info.plist` usage-description strings for camera, photo library, and
  location (the site's file-upload and "use my current location" controls
  need these declared or iOS kills the WebView call outright), plus a
  `PrivacyInfo.xcprivacy` privacy manifest (Apple has required this since
  Spring 2024).
- `allowNavigation` in `capacitor.config.json` covers Paystack checkout and
  Google Sign-In redirect domains — without this, tapping "Pay" or "Sign in
  with Google" would silently fail to navigate inside the WebView.
- Push notifications wired end-to-end: `@capacitor/push-notifications` on
  the client (`assets/js/push-bridge.js` in the main site, loaded on every
  logged-in page — see `partials/bottom_nav.php`), a `push_tokens` DB table
  and `register_push_token.php` endpoint, and real send-side code
  (`push_notifications.php`) for both Firebase Cloud Messaging (Android)
  and APNs (iOS) — see `config/README.md` for the credentials it needs.
  Verified the JWT-signing crypto for both (RS256 for FCM, ES256 for APNs)
  against test keypairs; it just has nothing to actually send to yet.

## What you still need to do

1. **Apple Developer Program** ($99/yr) and **Google Play Console** ($25
   one-time) accounts — you said you have neither yet. Nothing below can be
   submitted without them.
2. **A Mac with Xcode** to build/sign/upload the iOS app — cannot be done
   from this Windows machine. Everything in `ios/` is ready to open there.
3. Firebase project + APNs key for push (see `config/README.md`).
4. Store listing assets only you can provide: screenshots (exact sizes
   below), a short + full description, support email/URL, and answers to
   each store's content-rating / data-safety questionnaire.
5. Decide on the Apple IAP question above.

## Local setup

```bash
cd mobile-app
npm install
npx cap sync          # re-copies config + web assets into both native projects
```

Re-run `npx cap sync` any time you change `capacitor.config.json` or add a
plugin. It does **not** need to run when you change the PHP site itself —
the app loads that live over the network, nothing is bundled.

## Android — build & test

Needs **Android Studio** (bundles the right JDK + SDK).

```bash
npm run open:android      # opens android/ in Android Studio
```

From Android Studio: Run ▶ on an emulator/device for testing. For a real
Play Store upload:

1. Build → Generate Signed Bundle/APK → Android App Bundle (`.aab`).
2. Create a new keystore the first time (Build → Generate Signed Bundle →
   "Create new…") — **back this up somewhere safe.** Losing it means you
   can never update this app listing again under the same identity.
3. Play Console → your app → Production → Create release → upload the
   `.aab`.

Play Console also requires, before it'll let a release go out: a privacy
policy URL (`https://akuapemconnect.com/privacy.php` — already exists),
a completed Data Safety form, a content rating questionnaire, and a
feature graphic (1024×500) plus at least 2 phone screenshots
(min 320px, max 3840px, 16:9 or 9:16).

## iOS — build & test

Needs a **Mac with Xcode 15+** and CocoaPods (`sudo gem install cocoapods`).

```bash
cd ios/App
pod install
open App.xcworkspace      # always the .xcworkspace, not .xcodeproj, once Pods exist
```

In Xcode: set your Team under Signing & Capabilities, then add the "Push
Notifications" capability (see `config/README.md`). Run ▶ on the Simulator
or a device to test.

For an App Store upload: Product → Archive → Distribute App → App Store
Connect. App Store Connect also requires, before submission: a privacy
policy URL, the App Privacy questionnaire (the `PrivacyInfo.xcprivacy` file
in this project documents what the app itself accesses, but you still fill
this in yourself on the portal), an age rating, and screenshots for at
least one device size per family (6.9" iPhone: 1320×2868 or 2868×1320, and
12.9"/13" iPad if you support iPad — this project doesn't restrict device
family, so consider whether you want to require iPhone-only in the App
Store Connect listing to skip iPad screenshots).

## File map

```
mobile-app/
  capacitor.config.json   — app id, name, the live URL it loads, allowed navigation domains
  assets/                 — icon & splash source images used to generate every platform size
  www/                    — a one-page fallback shown only if there's no network at launch
  android/                — native Android Studio project
  ios/                    — native Xcode project
```
