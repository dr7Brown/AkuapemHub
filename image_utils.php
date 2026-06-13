<?php
/**
 * image_utils.php — Server-side image processing (GD + WebP).
 * Requires extension=gd in php.ini with WebP support.
 *
 * Public API:
 *   process_profile_image(array $file, string $relativeDir): ?string
 *   process_chat_image(array $file): ?array{medium:string, thumb:string}
 */

// ── Internal GD helpers ───────────────────────────────────────────────────────

function _img_load(string $path): GdImage|false {
    $mime = mime_content_type($path);
    return match ($mime) {
        'image/jpeg' => imagecreatefromjpeg($path),
        'image/png'  => imagecreatefrompng($path),
        'image/webp' => imagecreatefromwebp($path),
        'image/gif'  => imagecreatefromgif($path),
        default      => false,
    };
}

/**
 * Resize + save as WebP.
 * $crop = true  → center-crop to exact $maxW × $maxH (use for profile photos).
 * $crop = false → fit within $maxW × $maxH preserving aspect ratio.
 */
function _img_resize_webp(string $srcPath, string $dstPath, int $maxW, int $maxH, bool $crop, int $quality = 75): bool {
    $src = _img_load($srcPath);
    if (!$src) return false;

    $sw = imagesx($src);
    $sh = imagesy($src);

    if ($crop) {
        // Scale so both dimensions fill, then crop center
        $scale = max($maxW / $sw, $maxH / $sh);
        $iW    = (int)ceil($sw * $scale);
        $iH    = (int)ceil($sh * $scale);
        $tmp   = imagecreatetruecolor($iW, $iH);
        imagecopyresampled($tmp, $src, 0, 0, 0, 0, $iW, $iH, $sw, $sh);
        imagedestroy($src);
        $dst = imagecreatetruecolor($maxW, $maxH);
        $ox  = (int)(($iW - $maxW) / 2);
        $oy  = (int)(($iH - $maxH) / 2);
        imagecopy($dst, $tmp, 0, 0, $ox, $oy, $maxW, $maxH);
        imagedestroy($tmp);
    } else {
        // Fit: downscale only, preserve aspect ratio
        $scale = ($sw > $maxW || $sh > $maxH) ? min($maxW / $sw, $maxH / $sh) : 1.0;
        $nW    = max(1, (int)round($sw * $scale));
        $nH    = max(1, (int)round($sh * $scale));
        $dst   = imagecreatetruecolor($nW, $nH);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $trans = imagecolorallocatealpha($dst, 255, 255, 255, 127);
        imagefill($dst, 0, 0, $trans);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nW, $nH, $sw, $sh);
        imagedestroy($src);
    }

    $ok = imagewebp($dst, $dstPath, $quality);
    imagedestroy($dst);
    return $ok;
}

// ── Public API ────────────────────────────────────────────────────────────────

/**
 * Process a profile picture upload.
 * → 300×300 center-crop, WebP at quality 75, max 5 MB.
 * Returns relative path (e.g. "uploads/profiles/3/abc123.webp") or null.
 */
function process_profile_image(array $file, string $relativeDir): ?string {
    if (empty($file['name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;
    if ($file['size'] > 5 * 1024 * 1024) return null;

    $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    $mime    = mime_content_type($file['tmp_name']);
    if (!in_array($mime, $allowed, true)) return null;

    $dir = __DIR__ . '/' . $relativeDir;
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $fileName = bin2hex(random_bytes(8)) . '.webp';
    $dstPath  = $dir . '/' . $fileName;

    if (!_img_resize_webp($file['tmp_name'], $dstPath, 300, 300, true, 75)) return null;
    return $relativeDir . '/' . $fileName;
}

/**
 * Process a chat image upload.
 * → medium: max 1280px wide, WebP 82%; thumb: max 300px wide, WebP 75%.
 * Max 10 MB. Returns ['medium'=>..., 'thumb'=>...] or null.
 */
function process_chat_image(array $file): ?array {
    if (empty($file['name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;
    if ($file['size'] > 10 * 1024 * 1024) return null;

    $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    $mime    = mime_content_type($file['tmp_name']);
    if (!in_array($mime, $allowed, true)) return null;

    $dir = __DIR__ . '/uploads/chat/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $base      = bin2hex(random_bytes(8));
    $mediumRel = 'uploads/chat/img_'   . $base . '.webp';
    $thumbRel  = 'uploads/chat/thumb_' . $base . '.webp';
    $mediumAbs = __DIR__ . '/' . $mediumRel;
    $thumbAbs  = __DIR__ . '/' . $thumbRel;

    if (!_img_resize_webp($file['tmp_name'], $mediumAbs, 1280, 4000, false, 82)) return null;
    if (!_img_resize_webp($file['tmp_name'], $thumbAbs,   300, 1000, false, 75)) {
        @unlink($mediumAbs);
        return null;
    }

    return ['medium' => $mediumRel, 'thumb' => $thumbRel];
}
