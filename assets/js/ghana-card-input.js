/**
 * Ghana Card smart input controller.
 * Format: GHA-XXXXXXXXX-X  (prefix GHA-, 9 digits, hyphen, 1 digit)
 *
 * Usage:
 *   var ctrl = initGhanaCardInput(el);
 *   ctrl.activate();    // enables GHA formatting
 *   ctrl.deactivate();  // disables; clears GHA value
 */
(function () {
    var PREFIX = 'GHA-';
    var FULL_RE = /^GHA-\d{9}-\d$/;

    function digits(str) {
        return str.replace(/\D/g, '');
    }

    function format(raw) {
        var d = digits(raw).slice(0, 10);
        if (d.length <= 9) return PREFIX + d;
        return PREFIX + d.slice(0, 9) + '-' + d.slice(9);
    }

    function initGhanaCardInput(el) {
        if (!el) return null;

        var hint = document.getElementById(el.id + '_hint');
        var active = false;

        function setVal(v) { el.value = v; }

        function setHint(msg, color) {
            if (!hint) return;
            hint.textContent = msg;
            hint.style.color = color || '';
        }

        function validate() {
            if (!active) { setHint(''); return; }
            var v = el.value;
            if (v === PREFIX || v === '') {
                setHint('');
            } else if (FULL_RE.test(v)) {
                setHint('Valid Ghana Card format', 'var(--success, #16a34a)');
            } else {
                setHint('Format: GHA-123456789-0', 'var(--danger, #dc2626)');
            }
        }

        el.addEventListener('focus', function () {
            if (!active) return;
            if (!el.value.startsWith(PREFIX)) setVal(PREFIX);
        });

        el.addEventListener('keydown', function (e) {
            if (!active) return;
            var pos = el.selectionStart;
            if ((e.key === 'Backspace' || e.key === 'Delete') && pos <= PREFIX.length && el.selectionStart === el.selectionEnd) {
                e.preventDefault();
                return;
            }
            if (e.ctrlKey || e.metaKey || ['ArrowLeft','ArrowRight','ArrowUp','ArrowDown','Tab','Enter'].includes(e.key)) return;
            if (e.key.length === 1 && !/\d/.test(e.key)) e.preventDefault();
        });

        el.addEventListener('input', function () {
            if (!active) return;
            var v = el.value;
            if (!v.startsWith(PREFIX)) {
                var d = digits(v);
                setVal(format(d));
                el.setSelectionRange(el.value.length, el.value.length);
                validate();
                return;
            }
            var d = digits(v.slice(PREFIX.length));
            var newVal = format(d);
            var cursorAt = el.selectionStart;
            setVal(newVal);
            var newPos = Math.max(PREFIX.length, Math.min(cursorAt, newVal.length));
            el.setSelectionRange(newPos, newPos);
            validate();
        });

        el.addEventListener('paste', function (e) {
            if (!active) return;
            e.preventDefault();
            var pasted = (e.clipboardData || window.clipboardData).getData('text');
            var cleaned = pasted.trim().toUpperCase();
            var d;
            if (/^GHA/.test(cleaned)) {
                d = digits(cleaned.slice(cleaned.indexOf('-') >= 0 ? cleaned.indexOf('-') + 1 : 3));
            } else {
                d = digits(cleaned);
            }
            var newVal = format(d);
            setVal(newVal);
            el.setSelectionRange(newVal.length, newVal.length);
            validate();
        });

        el.addEventListener('blur', validate);

        return {
            activate: function () {
                active = true;
                el.placeholder = 'GHA-_________-_';
                if (el.value && !el.value.startsWith(PREFIX)) {
                    setVal(format(digits(el.value)));
                }
                validate();
            },
            deactivate: function () {
                active = false;
                if (el.value.startsWith(PREFIX)) el.value = '';
                setHint('');
            }
        };
    }

    window.initGhanaCardInput = initGhanaCardInput;
})();
