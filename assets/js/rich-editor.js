/*!
 * RichEditor — floating-toolbar rich text editor
 * Auto-attaches to <textarea class="rich-editor">.
 * MutationObserver handles dynamically-injected textareas automatically.
 */
(function () {
    'use strict';

    try { document.execCommand('defaultParagraphSeparator', false, 'p'); } catch (_) {}

    /* ── Toolbar definitions ──────────────────────────────────────────────────── */
    var TOOLS = [
        { cmd: 'bold',               icon: '<b>B</b>',  title: 'Bold (Ctrl+B)' },
        { cmd: 'italic',             icon: '<i>I</i>',  title: 'Italic (Ctrl+I)' },
        { cmd: 'underline',          icon: '<u>U</u>',  title: 'Underline (Ctrl+U)' },
        { cmd: 'strikeThrough',      icon: '<s>S</s>',  title: 'Strikethrough' },
        { sep: true },
        { cmd: 'formatBlock', val: 'h2', icon: 'H2',   title: 'Heading 2' },
        { cmd: 'formatBlock', val: 'h3', icon: 'H3',   title: 'Heading 3' },
        { cmd: 'formatBlock', val: 'p',  icon: '¶',    title: 'Paragraph' },
        { sep: true },
        { cmd: 'insertUnorderedList', icon: '&#8226;&#8801;', title: 'Bullet list' },
        { cmd: 'insertOrderedList',   icon: '1&#8801;',       title: 'Numbered list' },
        { sep: true },
        { cmd: 'createLink',   link: true, icon: '&#128279;', title: 'Insert link' },
        { cmd: 'removeFormat',          icon: '&times;',      title: 'Clear formatting' }
    ];

    /* ── Build the one shared floating toolbar ────────────────────────────────── */
    var _bar = null;

    function getBar() {
        if (_bar) return _bar;
        _bar = document.createElement('div');
        _bar.className = 'rte-bar';
        _bar.setAttribute('role', 'toolbar');
        _bar.setAttribute('aria-label', 'Text formatting');
        TOOLS.forEach(function (t) {
            if (t.sep) {
                var s = document.createElement('span');
                s.className = 'rte-sep';
                _bar.appendChild(s);
                return;
            }
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'rte-btn';
            btn.innerHTML = t.icon;
            btn.title = t.title;
            btn.dataset.cmd = t.cmd;
            if (t.val)  btn.dataset.val  = t.val;
            if (t.link) btn.dataset.link = '1';
            _bar.appendChild(btn);
        });
        document.body.appendChild(_bar);
        return _bar;
    }

    function exec(cmd, val) {
        document.execCommand(cmd, false, val !== undefined ? val : null);
    }

    var _activeEd = null;

    /* ── RichEditor ───────────────────────────────────────────────────────────── */
    function RichEditor(ta) {
        this.ta       = ta;
        this.wasReq   = ta.hasAttribute('required');
        this._build();
    }

    RichEditor.prototype._build = function () {
        var ta  = this.ta;
        var self = this;

        /* Wrap */
        var wrap = document.createElement('div');
        wrap.className = 'rte-wrap';
        ta.parentNode.insertBefore(wrap, ta);
        wrap.appendChild(ta);

        /* Hidden textarea adjustments */
        ta.style.display = 'none';
        ta.removeAttribute('required');      // prevent native validation on hidden field
        ta._rteRequired = this.wasReq;

        /* Editor div */
        var ed = document.createElement('div');
        ed.className = 'rte-editor';
        ed.contentEditable = 'true';
        ed.setAttribute('spellcheck', 'true');
        var ph = ta.getAttribute('placeholder');
        if (ph) ed.dataset.placeholder = ph;
        var rows = parseInt(ta.getAttribute('rows'), 10) || 5;
        ed.style.minHeight = (rows * 1.65) + 'em';

        /* Seed initial content */
        var raw = ta.value.trim();
        if (raw) {
            /* Plain text (no HTML tags) → convert line-blocks to <p> */
            if (!/<[a-z][^>]*>/i.test(raw)) {
                ed.innerHTML = raw.split(/\n\n+/).map(function (p) {
                    return '<p>' + p.replace(/\n/g, '<br>') + '</p>';
                }).join('') || '<p><br></p>';
            } else {
                ed.innerHTML = raw;
            }
        } else {
            ed.innerHTML = '<p><br></p>';
        }

        wrap.insertBefore(ed, ta);
        this.ed   = ed;
        this.wrap = wrap;

        this._bindEvents();
        this._sync();
    };

    RichEditor.prototype._sync = function () {
        var v = this.ed.innerHTML;
        this.ta.value = (v === '<p><br></p>' || v === '<br>' || v === '') ? '' : v;
    };

    RichEditor.prototype._isEmpty = function () {
        return !this.ed.innerText.trim();
    };

    RichEditor.prototype._bindEvents = function () {
        var self = this;
        var ed   = this.ed;

        /* Sync on every keystroke / paste / cut */
        ed.addEventListener('input', function () { self._sync(); });

        /* Enter creates a new <p>, not <div> */
        ed.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                var sel   = window.getSelection();
                var node  = sel && sel.anchorNode;
                var block = node ? (node.nodeType === 3 ? node.parentElement : node) : null;
                if (block && block.closest('li')) return; /* lists: let browser handle */
                e.preventDefault();
                document.execCommand('insertParagraph');
                self._sync();
            }
        });

        /* Keyboard shortcuts */
        ed.addEventListener('keydown', function (e) {
            if (!e.ctrlKey && !e.metaKey) return;
            var k = e.key.toLowerCase();
            if (k === 'b') { e.preventDefault(); exec('bold');      self._sync(); }
            if (k === 'i') { e.preventDefault(); exec('italic');    self._sync(); }
            if (k === 'u') { e.preventDefault(); exec('underline'); self._sync(); }
        });

        /* Show toolbar when text is selected */
        ed.addEventListener('mouseup',  function () { setTimeout(function () { self._checkSel(); }, 20); });
        ed.addEventListener('keyup', function (e) {
            self._sync();
            if (e.shiftKey || /^Arrow/.test(e.key)) setTimeout(function () { self._checkSel(); }, 20);
        });

        /* Focus / blur */
        ed.addEventListener('focus', function () { _activeEd = self; });
        ed.addEventListener('blur',  function () {
            setTimeout(function () {
                var b = getBar();
                if (!b.matches(':hover') && _activeEd === self) hideBar();
            }, 200);
        });

        /* Form submit: sync all editors in this form + validate required */
        var form = ed.closest('form') || this.ta.closest('form');
        if (form && !form._rteHooked) {
            form._rteHooked = true;
            form.addEventListener('submit', function (e) {
                var hasError = false;
                form.querySelectorAll('.rte-editor').forEach(function (edEl) {
                    var taEl = edEl.parentElement && edEl.parentElement.querySelector('textarea');
                    if (!taEl) return;
                    var v = edEl.innerHTML;
                    taEl.value = (v === '<p><br></p>' || v === '<br>' || v === '') ? '' : v;
                    if (taEl._rteRequired && !taEl.value) {
                        edEl.classList.add('rte-error');
                        edEl.focus();
                        hasError = true;
                    } else {
                        edEl.classList.remove('rte-error');
                    }
                });
                if (hasError) e.preventDefault();
            });
        }
    };

    RichEditor.prototype._checkSel = function () {
        var sel = window.getSelection();
        if (!sel || sel.isCollapsed || !sel.toString().trim()) {
            if (_activeEd === this) hideBar();
            return;
        }
        if (!this.ed.contains(sel.anchorNode)) return;
        showBar(this, sel);
    };

    /* ── Bar positioning ──────────────────────────────────────────────────────── */
    function showBar(editor, sel) {
        var b    = getBar();
        b._ed    = editor;
        b.style.visibility = 'hidden';
        b.style.display    = 'flex';

        var range = sel.getRangeAt(0);
        var rect  = range.getBoundingClientRect();
        var bw    = b.offsetWidth  || 320;
        var bh    = b.offsetHeight || 42;

        var top  = rect.top  - bh - 10;
        var left = rect.left + rect.width / 2 - bw / 2;

        if (top < 4) top = rect.bottom + 10;           /* flip below viewport top */
        left = Math.max(8, Math.min(left, window.innerWidth - bw - 8));

        b.style.top        = top  + 'px';
        b.style.left       = left + 'px';
        b.style.visibility = 'visible';

        updateState(b);
    }

    function hideBar() {
        var b = getBar();
        b.style.display = 'none';
        b._ed = null;
    }

    function updateState(b) {
        b.querySelectorAll('.rte-btn').forEach(function (btn) {
            var cmd = btn.dataset.cmd;
            try {
                if (['bold', 'italic', 'underline', 'strikeThrough'].indexOf(cmd) !== -1) {
                    btn.classList.toggle('rte-active', document.queryCommandState(cmd));
                }
                if (cmd === 'formatBlock') {
                    var cur = document.queryCommandValue('formatBlock').toLowerCase();
                    btn.classList.toggle('rte-active', cur === (btn.dataset.val || '').toLowerCase());
                }
            } catch (_) {}
        });
    }

    /* ── Global bar event delegation ──────────────────────────────────────────── */
    document.addEventListener('mousedown', function (e) {
        var btn = e.target.closest && e.target.closest('.rte-btn');
        if (!btn || !getBar().contains(btn)) return;
        e.preventDefault();                  /* keep focus inside editor */

        var ed = getBar()._ed;
        if (!ed) return;

        var cmd = btn.dataset.cmd;
        if (btn.dataset.link) {
            var sel   = window.getSelection();
            var range = sel && sel.rangeCount ? sel.getRangeAt(0).cloneRange() : null;
            var url   = prompt('Enter URL (e.g. https://example.com):');
            if (url && range) {
                sel.removeAllRanges();
                sel.addRange(range);
                exec('createLink', url);
            }
        } else {
            exec(cmd, btn.dataset.val || null);
        }
        ed._sync();
        updateState(getBar());
    });

    /* Hide bar on outside click */
    document.addEventListener('mousedown', function (e) {
        if (!e.target.closest('.rte-bar') && !e.target.closest('.rte-editor')) hideBar();
    });

    /* Hide bar when selection collapses */
    document.addEventListener('selectionchange', function () {
        var sel = window.getSelection();
        if (sel && sel.isCollapsed) {
            setTimeout(function () {
                if (!document.activeElement || !document.activeElement.closest('.rte-editor')) hideBar();
            }, 60);
        }
    });

    /* ── Auto-init ────────────────────────────────────────────────────────────── */
    function initOne(ta) {
        if (ta._rte) return;
        ta._rte = new RichEditor(ta);
    }

    function initAll() {
        document.querySelectorAll('textarea.rich-editor').forEach(initOne);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }

    /* MutationObserver: handles AJAX-injected and future textareas automatically */
    new MutationObserver(function (mutations) {
        mutations.forEach(function (m) {
            m.addedNodes.forEach(function (node) {
                if (node.nodeType !== 1) return;
                if (node.matches && node.matches('textarea.rich-editor')) initOne(node);
                if (node.querySelectorAll) node.querySelectorAll('textarea.rich-editor').forEach(initOne);
            });
        });
    }).observe(document.documentElement, { childList: true, subtree: true });

    /* Public API */
    window.RichEditor      = RichEditor;
    window.initRichEditors = initAll;
}());
