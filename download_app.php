<?php
/**
 * Direct APK download — interim distribution while the app isn't on the
 * Play Store yet. Serves downloads/AkuapemConnect.apk with the headers
 * needed for a browser to treat it as a file to install rather than
 * something to render, and for Android to recognize it as an app package.
 *
 * To ship a new build: rebuild the APK (mobile-app/android →
 * ./gradlew assembleRelease using the real release keystore) and overwrite
 * downloads/AkuapemConnect.apk — this endpoint doesn't need to change.
 */
require_once __DIR__ . '/functions.php';

$apkPath = __DIR__ . '/downloads/AkuapemConnect.apk';

if (!file_exists($apkPath)) {
    http_response_code(404);
    echo 'The app download is not available right now. Please check back soon.';
    exit;
}

log_audit_action(null, 'app_apk_downloaded', 'Direct APK download from ' . (client_ip() ?: 'unknown IP'));

header('Content-Type: application/vnd.android.package-archive');
header('Content-Disposition: attachment; filename="AkuapemConnect.apk"');
header('Content-Length: ' . filesize($apkPath));
header('Cache-Control: no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

readfile($apkPath);
exit;
