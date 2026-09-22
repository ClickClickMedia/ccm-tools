/**
 * CCM Tools — UI layer
 *
 * Two jobs, both deliberately kept out of main.js so they apply to every
 * admin page regardless of what else is loaded:
 *
 *   1. The light/dark theme toggle.
 *   2. Upgrading spinners to the shared CCM brand mark.
 *
 * The spinner upgrade is done with an observer rather than by rewriting the
 * forty-odd call sites that build markup as template strings. Any element
 * with class ccm-spinner that is not already the SVG gets swapped for the
 * real mark, whether it was rendered by PHP or injected by JavaScript.
 *
 * @since 8.0.0
 */
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

    /**
     * Build the CCM spinner as an HTML string.
     *
     * Below 32px the regular spokes fall under two pixels and the mark
     * shimmers rather than spins, so anything smaller gets the --sm drawing.
     *
     * @param {number} size Pixel size. Default 20.
     * @param {Object} opts cls, style, id, label.
     * @return {string}
     */
    function ccmSpinner(size, opts) {
        opts = opts || {};
        size = Math.max(8, parseInt(size, 10) || 20);

        var classes = ['ccm-spinner', 'ccm-spinner--bare'];
        if (size < 32) { classes.push('ccm-spinner--sm'); }
        if (opts.cls) { classes.push(opts.cls); }

        // A label is only correct where the spinner is the sole announcement
        // of the wait. Beside visible text it must stay hidden, or a screen
        // reader reads the wait out twice.
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

    /**
     * The same mark as a real DOM node.
     *
     * document.createElement cannot parse SVG, so this goes through a
     * template element rather than createElementNS by hand.
     */
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

    /**
     * Replace one legacy spinner element with the brand mark.
     *
     * @param {Element} el A .ccm-spinner that is not already an SVG.
     */
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

    // ── Save bar ────────────────────────────────────────────────
    //
    // A page opts in by rendering one element:
    //
    //   <div class="ccm-savebar" data-ccm-savebar data-savebar-target="#save-perf-settings">
    //
    // This watches every form control on the page, tracks whether anything
    // differs from what was loaded, and proxies its Save button to the page's
    // real one. It deliberately does NOT know how to save: that logic stays
    // wherever it already lives, so the two can never disagree about what a
    // setting is.

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
            }).join(' ');
        }

        var clean = snapshot();
        var dirty = false;

        function label(n) {
            if (n === 0) { return 'No unsaved changes'; }
            return n === 1 ? '1 unsaved change' : n + ' unsaved changes';
        }

        function countChanges() {
            var now = snapshot().split(' ');
            var was = clean.split(' ');
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
                el.type === 'number' || el.type === 'password') {
                el.addEventListener('input', refresh);
            }
        });

        if (saveBtn) {
            saveBtn.addEventListener('click', function () {
                if (!dirty) { return; }
                bar.classList.add('is-saving');
                if (msg) { msg.textContent = 'Saving…'; }
                target.click();

                // The page's own handler disables its button while it works.
                // Watching that is how this stays out of the saving logic.
                var settled = false;
                var done = function () {
                    if (settled) { return; }
                    settled = true;
                    bar.classList.remove('is-saving');
                    clean = snapshot();
                    refresh();
                    bar.classList.add('is-saved');
                    if (msg) { msg.textContent = 'Saved'; }
                    window.setTimeout(function () {
                        bar.classList.remove('is-saved');
                        refresh();
                    }, 2500);
                };

                if (window.MutationObserver) {
                    var seenDisabled = target.disabled;
                    var obs = new MutationObserver(function () {
                        if (target.disabled) { seenDisabled = true; return; }
                        if (seenDisabled) { obs.disconnect(); done(); }
                    });
                    obs.observe(target, { attributes: true, attributeFilter: ['disabled'] });
                    window.setTimeout(function () { obs.disconnect(); done(); }, 20000);
                } else {
                    window.setTimeout(done, 1500);
                }
            });
        }

        if (discardBtn) {
            discardBtn.addEventListener('click', function () {
                if (!window.confirm('Discard your unsaved changes and reload?')) { return; }
                window.location.reload();
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

    // ── Boot ────────────────────────────────────────────────────

    function init() {
        initThemeToggle();
        initSaveBar();
        upgradeAll(document);
        watchForSpinners();
    }

    window.ccmSpinner = ccmSpinner;
    window.ccmSpinnerEl = ccmSpinnerEl;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
