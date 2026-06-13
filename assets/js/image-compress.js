/**
 * Client-side image compression via Canvas API.
 * Downsizes images and converts to WebP before upload,
 * reducing bandwidth and server processing time.
 */

/**
 * Compress an image File to a smaller Blob.
 * @param {File}   file      Source image file
 * @param {number} maxWidth  Maximum output width in px
 * @param {number} maxHeight Maximum output height in px
 * @param {number} quality   WebP quality 0.0–1.0 (e.g. 0.82)
 * @returns {Promise<Blob>}  Compressed blob (WebP if supported, else JPEG fallback)
 */
function compressImage(file, maxWidth, maxHeight, quality) {
    return new Promise(function (resolve) {
        if (!file || !file.type.startsWith('image/')) { resolve(file); return; }

        var img = new Image();
        var url = URL.createObjectURL(file);

        img.onload = function () {
            URL.revokeObjectURL(url);
            var w = img.naturalWidth;
            var h = img.naturalHeight;
            // Only downscale; never upscale
            var scale = Math.min(1, maxWidth / w, maxHeight / h);
            var nw = Math.max(1, Math.round(w * scale));
            var nh = Math.max(1, Math.round(h * scale));

            var canvas = document.createElement('canvas');
            canvas.width  = nw;
            canvas.height = nh;
            var ctx = canvas.getContext('2d');
            ctx.drawImage(img, 0, 0, nw, nh);

            // Try WebP first; fall back to JPEG if browser doesn't support WebP output
            canvas.toBlob(function (blob) {
                if (blob && blob.size < file.size) {
                    resolve(blob);
                } else {
                    // WebP not smaller or not supported — try JPEG
                    canvas.toBlob(function (jpegBlob) {
                        resolve((jpegBlob && jpegBlob.size < file.size) ? jpegBlob : file);
                    }, 'image/jpeg', quality);
                }
            }, 'image/webp', quality);
        };

        img.onerror = function () { URL.revokeObjectURL(url); resolve(file); };
        img.src = url;
    });
}

/**
 * Wire client-side compression to a file <input>.
 * Replaces the input's FileList with a compressed File via DataTransfer.
 * Falls back gracefully if DataTransfer isn't supported.
 *
 * @param {HTMLInputElement} inputEl   The file input element
 * @param {number}           maxWidth
 * @param {number}           maxHeight
 * @param {number}           quality   0.0–1.0
 * @param {Function}         [onReady] Optional callback(compressedFile) when done
 */
function setupImageInput(inputEl, maxWidth, maxHeight, quality, onReady) {
    if (!inputEl) return;
    inputEl.addEventListener('change', function () {
        var file = this.files && this.files[0];
        if (!file || !file.type.startsWith('image/')) return;

        var originalSize = file.size;
        compressImage(file, maxWidth, maxHeight, quality).then(function (blob) {
            var ext  = blob.type === 'image/webp' ? '.webp' : '.jpg';
            var name = file.name.replace(/\.[^.]+$/, '') + ext;
            var compressed = new File([blob], name, { type: blob.type });

            // Only use compressed version if it's actually smaller
            if (compressed.size >= originalSize) {
                if (onReady) onReady(file);
                return;
            }

            try {
                var dt = new DataTransfer();
                dt.items.add(compressed);
                inputEl.files = dt.files;
            } catch (e) {
                // DataTransfer not supported (old Safari) — store on element for manual FormData
                inputEl._compressedFile = compressed;
            }
            if (onReady) onReady(compressed);
        });
    });
}
