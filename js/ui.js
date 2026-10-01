/* ea3522e22bb8f06d */
(function () {
    'use strict';

    // ── Theme ───────────────────────────────────────────────────

    var STORAGE_KEY = 'ccm-tools-theme';
    var root = document.documentElement;

    function currentTheme() {
        var explicit = root.getAttribute('data-ccm-theme');
        if (explicit === 'dark' || explicit === 'light') {
            return explicit;
        }
        return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches
            ? 'dark'
            : 'light';
    }

    function applyTheme(theme) {
        root.setAttribute('data-ccm-theme', theme);
        try { window.localStorage.setItem(STORAGE_KEY, theme); } catch (e) { /* private window */ }
    }

    function initThemeToggle() {
        var btn = document.querySelector('.ccm-theme-toggle');
        if (!btn) { return; }
        btn.addEventListener('click', function () {
            applyTheme(currentTheme() === 'dark' ? 'light' : 'dark');
        });
    }

    // ── Spinner ─────────────────────────────────────────────────

    var SPOKES = [
        [0, 's1', 'w'], [60, 's2', 'w'], [120, 's3', 'g'],
        [180, 's4', 'g'], [240, 's5', 'g'], [300, 's6', 'w']
    ];

    /* e6f7e1c1a4669940 */
    function ccmSpinner(size, opts) {
        opts = opts || {};
        size = Math.max(8, parseInt(size, 10) || 20);

        var classes = ['ccm-spinner', 'ccm-spinner--bare'];
        if (size < 32) { classes.push('ccm-spinner--sm'); }
        if (opts.cls) { classes.push(opts.cls); }

        /* 67bd4e9cbb4c9988 */
        var a11y = opts.label
            ? 'role="img" aria-label="' + esc(opts.label) + '"'
            : 'aria-hidden="true"';

        var body = '';
        SPOKES.forEach(function (spoke) {
            body += '<g transform="rotate(' + spoke[0] + ' 48 48)">' +
                '<rect class="ccm-sp ccm-' + spoke[1] + ' ccm-' + spoke[2] + '" ' +
                'x="57" y="45.05" width="21" height="5.9" rx="2.95"/></g>';
        });

        return '<svg class="' + esc(classes.join(' ')) + '"' +
            (opts.id ? ' id="' + esc(opts.id) + '"' : '') +
            ' style="--ccm-size:' + size + 'px' + (opts.style ? ';' + esc(opts.style) : '') + '" ' +
            a11y + ' viewBox="0 0 96 96" focusable="false">' +
            '<rect class="ccm-tile" width="96" height="96" rx="18"/>' +
            '<g class="ccm-rotor">' + body + '</g></svg>';
    }

    /* 0aa66724597ce7f6 */
    function ccmSpinnerEl(size, opts) {
        var tpl = document.createElement('template');
        tpl.innerHTML = ccmSpinner(size, opts).trim();
        return tpl.content.firstChild;
    }

    function esc(str) {
        return String(str === null || str === undefined ? '' : str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    /* 469b3fc42fa9f5dd */
    function upgrade(el) {
        if (el.tagName.toLowerCase() === 'svg') { return; }
        if (el.getAttribute('data-ccm-upgraded') === '1') { return; }

        var small = el.classList.contains('ccm-spinner-small');
        var size = small ? 16 : 32;

        // Carry across any inline sizing the call site asked for.
        var inline = el.getAttribute('style') || '';
        var extra = [];
        if (inline) {
            // Keep margins and display tweaks, drop width/height, which the
            // SVG sets from --ccm-size.
            inline.split(';').forEach(function (rule) {
                var name = rule.split(':')[0];
                if (!name) { return; }
                name = name.trim().toLowerCase();
                if (name === 'width' || name === 'height') { return; }
                extra.push(rule.trim());
            });
        }

        var keep = [];
        el.classList.forEach(function (c) {
            if (c !== 'ccm-spinner' && c !== 'ccm-spinner-small') { keep.push(c); }
        });

        var svg = ccmSpinnerEl(size, {
            cls: keep.join(' '),
            style: extra.join('; ')
        });
        svg.setAttribute('data-ccm-upgraded', '1');
        el.replaceWith(svg);
    }

    function upgradeAll(scope) {
        var nodes = (scope || document).querySelectorAll('.ccm-spinner:not(svg)');
        Array.prototype.forEach.call(nodes, upgrade);
    }

    function watchForSpinners() {
        if (!window.MutationObserver) { return; }
        var observer = new MutationObserver(function (records) {
            for (var i = 0; i < records.length; i++) {
                var added = records[i].addedNodes;
                for (var j = 0; j < added.length; j++) {
                    var node = added[j];
                    if (node.nodeType !== 1) { continue; }
                    if (node.classList && node.classList.contains('ccm-spinner')) {
                        upgrade(node);
                    }
                    if (node.querySelectorAll) {
                        upgradeAll(node);
                    }
                }
            }
        });
        observer.observe(document.body, { childList: true, subtree: true });
    }

    /* 0eb56af7c329e2ce */

    function initSaveBar() {
        var bar = document.querySelector('[data-ccm-savebar]');
        if (!bar) { return; }

        var targetSel = bar.getAttribute('data-savebar-target');
        var target = targetSel ? document.querySelector(targetSel) : null;
        if (!target) { return; }

        var wrap = document.querySelector('.ccm-tools');
        if (wrap) { wrap.classList.add('has-savebar'); }

        var msg = bar.querySelector('.ccm-savebar__msg');
        var saveBtn = bar.querySelector('[data-savebar-save]');
        var discardBtn = bar.querySelector('[data-savebar-discard]');

        var controls = Array.prototype.slice.call(
            document.querySelectorAll('.ccm-content input, .ccm-content select, .ccm-content textarea')
        ).filter(function (el) {
            return el.type !== 'search' && !el.closest('[data-ccm-savebar]') && !el.hasAttribute('data-savebar-ignore');
        });

        function snapshot() {
            return controls.map(function (el) {
                return (el.type === 'checkbox' || el.type === 'radio') ? (el.checked ? '1' : '0') : el.value;
            });
        }

        var clean = snapshot();
        var dirty = false;

        function label(n) {
            if (n === 0) { return 'No unsaved changes'; }
            return n === 1 ? '1 unsaved change' : n + ' unsaved changes';
        }

        function countChanges() {
            var now = snapshot();
            var was = clean;
            var n = 0;
            for (var i = 0; i < now.length; i++) { if (now[i] !== was[i]) { n++; } }
            return n;
        }

        function refresh() {
            var n = countChanges();
            dirty = n > 0;
            bar.classList.toggle('is-dirty', dirty);
            bar.classList.remove('is-saved');
            if (msg) { msg.textContent = label(n); }
        }

        controls.forEach(function (el) {
            el.addEventListener('change', refresh);
            if (el.tagName === 'TEXTAREA' || el.type === 'text' || el.type === 'url' ||
                el.type === 'number' || el.type === 'password' || el.type === 'range') {
                el.addEventListener('input', refresh);
            }
        });

        if (saveBtn) {
            saveBtn.addEventListener('click', function () {
                if (!dirty) { return; }
                bar.classList.add('is-saving');
                if (msg) { msg.textContent = 'Saving…'; }
                target.click();

                /* f287b3eeee9a2e01 */
                var settled = false;
                var done = function (ok) {
                    if (settled) { return; }
                    settled = true;
                    bar.classList.remove('is-saving');

                    if (ok) {
                        clean = snapshot();
                        refresh();
                        bar.classList.add('is-saved');
                        if (msg) { msg.textContent = 'Saved'; }
                        window.setTimeout(function () {
                            bar.classList.remove('is-saved');
                            refresh();
                        }, 2500);
                        return;
                    }

                    // Leave the changes marked dirty; they are still unsaved.
                    bar.classList.add('is-failed');
                    if (msg) { msg.textContent = 'Not saved'; }
                    window.setTimeout(function () {
                        bar.classList.remove('is-failed');
                        refresh();
                    }, 5000);
                };

                var onResult = function (e) {
                    target.removeEventListener('ccm:save', onResult);
                    done(!!(e && e.detail && e.detail.ok));
                };
                target.addEventListener('ccm:save', onResult);

                /* 094f97c6ec49ee57 */
                window.setTimeout(function () {
                    target.removeEventListener('ccm:save', onResult);
                    done(false);
                }, 20000);
            });
        }

        if (discardBtn) {
            discardBtn.addEventListener('click', function () {
                /* 5b803ea26b959bda */
                var asked = window.ccmConfirm
                    ? window.ccmConfirm('Discard your unsaved changes and reload?', 'Discard changes')
                    : Promise.resolve(window.confirm('Discard your unsaved changes and reload?'));

                asked.then(function (ok) {
                    if (ok) { window.location.reload(); }
                });
            });
        }

        // Leaving with unsaved changes is almost always a mistake.
        window.addEventListener('beforeunload', function (e) {
            if (!dirty) { return; }
            e.preventDefault();
            e.returnValue = '';
        });

        refresh();
    }

    /* 6326bc77c1d3812c */

    var ccmRunPanel = (function () {

        function el(tag, className, text) {
            var node = document.createElement(tag);
            if (className) { node.className = className; }
            if (text !== undefined && text !== null) { node.textContent = String(text); }
            return node;
        }

        function open(options) {
            options = options || {};
            var tasks = options.tasks || [];
            var total = tasks.length;

            var overlay = el('div', 'ccm-modal-overlay');
            var panel = el('div', 'ccm-runpanel');
            panel.setAttribute('role', 'dialog');
            panel.setAttribute('aria-modal', 'true');

            var head = el('div', 'ccm-runpanel__head');
            var titleId = 'ccm-runpanel-title-' + Date.now();
            var title = el('h2', 'ccm-runpanel__title', options.title || 'Running');
            title.id = titleId;
            panel.setAttribute('aria-labelledby', titleId);
            var sub = el('p', 'ccm-runpanel__sub', '0 of ' + total + ' completed');
            sub.setAttribute('aria-live', 'polite');
            sub.setAttribute('aria-atomic', 'true');
            head.appendChild(title);
            head.appendChild(sub);

            var meter = el('div', 'ccm-runpanel__meter');
            var fill = el('i');
            meter.appendChild(fill);

            var body = el('div', 'ccm-runpanel__body');
            var list = el('ol', 'ccm-runlist');
            var rows = {};

            tasks.forEach(function (task) {
                var row = el('li', 'ccm-runrow is-pending');
                var icon = el('span', 'ccm-runrow__icon');
                var label = el('span', 'ccm-runrow__label', task.label || task.key);
                var meta = el('span', 'ccm-runrow__meta', 'Waiting');
                row.appendChild(icon);
                row.appendChild(label);
                row.appendChild(meta);
                list.appendChild(row);
                rows[task.key] = { row: row, icon: icon, meta: meta };
            });

            body.appendChild(list);

            var foot = el('div', 'ccm-runpanel__foot');
            var summary = el('span', 'ccm-runpanel__summary', 'Working…');
            summary.setAttribute('aria-live', 'polite');
            summary.setAttribute('aria-atomic', 'true');
            var closeBtn = el('button', 'ccm-button ccm-button-primary', 'Close');
            closeBtn.setAttribute('type', 'button');
            closeBtn.setAttribute('data-runpanel-close', '');
            foot.appendChild(summary);
            foot.appendChild(closeBtn);

            panel.appendChild(head);
            panel.appendChild(meter);
            panel.appendChild(body);
            panel.appendChild(foot);
            overlay.appendChild(panel);
            document.body.appendChild(overlay);

            window.requestAnimationFrame(function () {
                overlay.classList.add('ccm-modal-show');
            });

            var finished = 0;
            var failed = 0;
            var items = 0;
            var isDone = false;
            var closeHandlers = [];

            function progress() {
                sub.textContent = finished + ' of ' + total + ' completed';
                fill.style.width = total ? Math.round((finished / total) * 100) + '%' : '100%';
            }

            function close() {
                if (!isDone) { return; }
                overlay.classList.remove('ccm-modal-show');
                document.removeEventListener('keydown', onKey);
                window.setTimeout(function () {
                    if (overlay.parentNode) { overlay.parentNode.removeChild(overlay); }
                }, 200);
                closeHandlers.forEach(function (fn) {
                    try { fn(); } catch (e) { /* a bad handler must not trap the panel */ }
                });
                closeHandlers = [];
            }

            /* dff96d6dac49dec6 */
            function onKey(e) {
                if (e.key === 'Escape' && isDone) { close(); }
            }
            document.addEventListener('keydown', onKey);
            overlay.addEventListener('click', function (e) {
                if (e.target === overlay && isDone) { close(); }
            });
            closeBtn.addEventListener('click', close);

            function scrollRowIntoView(row) {
                if (!row) { return; }
                var top = row.offsetTop;
                var h = body.clientHeight;
                if (top < body.scrollTop || top + row.offsetHeight > body.scrollTop + h) {
                    body.scrollTop = Math.max(0, top - (h / 2) + (row.offsetHeight / 2));
                }
            }

            return {
                /** Mark a task as in flight. */
                start: function (key, note) {
                    var entry = rows[key];
                    if (!entry) { return; }
                    entry.row.className = 'ccm-runrow is-running';
                    entry.icon.textContent = '';
                    entry.icon.appendChild(el('div', 'ccm-spinner ccm-spinner-small'));
                    entry.meta.textContent = note || 'Running…';
                    scrollRowIntoView(entry.row);
                },

                /** Update the note on a task that is still running. */
                note: function (key, text) {
                    var entry = rows[key];
                    if (entry) { entry.meta.textContent = text; }
                },

                /* bff44de873044dec */
                finish: function (key, result) {
                    var entry = rows[key];
                    result = result || {};
                    finished++;
                    if (!result.ok && !result.skipped) { failed++; }
                    if (typeof result.count === 'number') { items += result.count; }

                    if (entry) {
                        var state = result.skipped ? 'is-skip' : (result.ok ? 'is-ok' : 'is-fail');
                        entry.row.className = 'ccm-runrow ' + state;
                        entry.icon.textContent = result.skipped ? '–' : (result.ok ? '✓' : '✗');
                        var text = result.message || (result.ok ? 'Done' : 'Failed');
                        if (typeof result.count === 'number' && result.count > 0) {
                            text += ' · ' + result.count.toLocaleString();
                        }
                        entry.meta.textContent = text;
                    }
                    progress();
                },

                /** Close out the run and let the panel be dismissed. */
                done: function (text) {
                    isDone = true;
                    panel.classList.add('is-done');
                    if (failed > 0) { panel.classList.add('has-failures'); }
                    fill.style.width = '100%';
                    sub.textContent = finished + ' of ' + total + ' completed';

                    if (text) {
                        summary.textContent = text;
                    } else {
                        var parts = [];
                        parts.push(failed > 0
                            ? (total - failed) + ' of ' + total + ' succeeded'
                            : 'All ' + total + ' completed');
                        if (items > 0) {
                            parts.push(items.toLocaleString() + ' ' + (options.unit || 'items') + ' affected');
                        }
                        summary.textContent = parts.join(' · ');
                    }
                    closeBtn.focus();
                },

                /** Run a callback once the panel is dismissed. */
                onClose: function (fn) {
                    if (typeof fn === 'function') { closeHandlers.push(fn); }
                },

                close: close,
                failures: function () { return failed; },
                affected: function () { return items; }
            };
        }

        return { open: open };
    })();

    /* 8d666336258361e5 */

    function initActionBar() {
        var bar = document.querySelector('[data-ccm-actionbar]');
        if (!bar) { return; }

        var target = document.querySelector(bar.getAttribute('data-actionbar-target') || '');
        var watch = document.querySelector(bar.getAttribute('data-actionbar-watch') || '');
        if (!target || !watch) { return; }

        var wrap = document.querySelector('.ccm-tools');
        if (wrap) { wrap.classList.add('has-savebar'); }

        var msg = bar.querySelector('.ccm-savebar__msg');
        var runBtn = bar.querySelector('[data-actionbar-run]');
        var noneBtn = bar.querySelector('[data-actionbar-none]');
        var noun = bar.getAttribute('data-actionbar-noun') || 'task';

        function selectedCount() {
            return watch.querySelectorAll('input[type="checkbox"]:checked').length;
        }

        function refresh() {
            var n = selectedCount();
            bar.classList.toggle('is-armed', n > 0);
            if (!msg) { return; }
            if (n === 0) {
                msg.textContent = 'No ' + noun + 's selected';
                return;
            }
            msg.textContent = n === 1 ? '1 ' + noun + ' selected' : n + ' ' + noun + 's selected';
        }

        /* f40c834040ac497a */
        watch.addEventListener('change', function (e) {
            if (e.target && e.target.type === 'checkbox') { refresh(); }
        });

        if (window.MutationObserver) {
            new window.MutationObserver(refresh).observe(watch, { childList: true, subtree: true });
        }

        if (runBtn) {
            runBtn.addEventListener('click', function () {
                if (!bar.classList.contains('is-armed')) { return; }
                target.click();
            });
        }

        if (noneBtn) {
            noneBtn.addEventListener('click', function () {
                Array.prototype.forEach.call(
                    watch.querySelectorAll('input[type="checkbox"]'),
                    function (cb) { cb.checked = false; }
                );
                refresh();
            });
        }

        /* 9d6913c7ae10000a */
        document.addEventListener('ccm:run-start', function () { bar.classList.add('is-running'); });
        document.addEventListener('ccm:run-end', function () {
            bar.classList.remove('is-running');
            refresh();
        });

        refresh();
    }

    /* 0e783a666c06a7f8 */

    function initSliders(scope) {
        var ranges = (scope || document).querySelectorAll('.ccm-slider__range');

        Array.prototype.forEach.call(ranges, function (range) {
            var sel = range.getAttribute('data-slider-output');
            var out = sel ? document.querySelector(sel) : null;
            if (!out && range.parentNode) {
                out = range.parentNode.querySelector('.ccm-slider__value');
            }

            function paint() {
                var min = parseFloat(range.min);
                var max = parseFloat(range.max);
                if (!isFinite(min)) { min = 0; }
                if (!isFinite(max)) { max = 100; }

                var span = max - min;
                var pos = span > 0 ? ((parseFloat(range.value) - min) / span) * 100 : 0;
                if (!isFinite(pos)) { pos = 0; }

                range.style.setProperty('--ccm-slider-pos', pos.toFixed(2) + '%');
                if (out) { out.textContent = range.value; }
            }

            range.addEventListener('input', paint);
            paint();
        });
    }

    /* 71757c2f9da403e4 */

    var FOCUS_PREFIX = '#ccm-focus-';

    function focusTarget(id, attempt) {
        var el = document.getElementById(id);

        if (!el) {
            /* 3415f95fe2baa65d */
            if ((attempt || 0) < 20) {
                window.setTimeout(function () { focusTarget(id, (attempt || 0) + 1); }, 150);
            }
            return;
        }

        // Open anything it is folded inside, or we scroll to a closed box.
        var node = el;
        while (node && node !== document.body) {
            if (node.tagName === 'DETAILS') { node.open = true; }
            node = node.parentElement;
        }

        // Mark the row rather than the input itself where there is one: a
        // highlighted checkbox is nearly invisible, a highlighted row is not.
        var mark = el.closest('.ccm-opt, .ccm-optfield, .ccm-stat-tile, tr, .ccm-optgroup') || el;

        var reduce = window.matchMedia
            && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        mark.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'center' });

        mark.classList.add('ccm-focus');
        window.setTimeout(function () { mark.classList.remove('ccm-focus'); }, 2600);

        // Put keyboard focus on the control too, so the next keystroke acts on
        // the thing the person was sent here to change.
        if (typeof el.focus === 'function') {
            try { el.focus({ preventScroll: true }); } catch (e) { el.focus(); }
        }
    }

    function initFocusTarget() {
        if (window.location.hash.indexOf(FOCUS_PREFIX) !== 0) {
            return;
        }
        var id = window.location.hash.slice(FOCUS_PREFIX.length);
        if (id) {
            focusTarget(id, 0);
        }
    }

    // ── Boot ────────────────────────────────────────────────────

    function init() {
        initThemeToggle();
        initSliders(document);
        initFocusTarget();
        initSaveBar();
        initActionBar();
        upgradeAll(document);
        watchForSpinners();
    }

    window.ccmRunPanel = ccmRunPanel;
    window.ccmSpinner = ccmSpinner;
    window.ccmSpinnerEl = ccmSpinnerEl;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
