/*!
 * Username input — blocks spaces instead of just rejecting on submit.
 * Auto-attaches to <input name="username">. The first space in a run
 * becomes an underscore; any space typed right after (while the field
 * already ends in an underscore) is simply swallowed, so you can never
 * get "kwame  builds" or "kwame__builds" from spacebar mashing.
 */
(function () {
    'use strict';

    function attach(el) {
        if (el.dataset.usernameInputAttached) return;
        el.dataset.usernameInputAttached = '1';

        el.addEventListener('keydown', function (e) {
            if (e.key !== ' ') return;
            var pos = el.selectionStart;
            var precedingChar = el.value.slice(0, pos).slice(-1);
            if (precedingChar === '_' || pos === 0) {
                e.preventDefault();
                return;
            }
            e.preventDefault();
            el.value = el.value.slice(0, pos) + '_' + el.value.slice(el.selectionEnd);
            el.setSelectionRange(pos + 1, pos + 1);
        });

        // Catches spaces from paste/autofill/IME, which keydown won't see.
        // Each run of spaces becomes a single underscore; a run at the very
        // start is dropped rather than turned into a leading underscore.
        el.addEventListener('input', function () {
            var pos = el.selectionStart;
            var cleaned = el.value.replace(/ +/g, function (run, offset) {
                return offset === 0 ? '' : '_';
            });
            if (cleaned !== el.value) {
                var removed = el.value.length - cleaned.length;
                el.value = cleaned;
                el.setSelectionRange(Math.max(0, pos - removed), Math.max(0, pos - removed));
            }
        });
    }

    function attachAll() {
        document.querySelectorAll('input[name="username"]').forEach(attach);
    }

    document.addEventListener('DOMContentLoaded', attachAll);

    new MutationObserver(attachAll).observe(document.documentElement, { childList: true, subtree: true });
})();
