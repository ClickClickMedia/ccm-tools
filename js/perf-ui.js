/**
 * CCM Tools — Performance page interactions.
 *
 * Search, filtering, sub-field reveal and the live counters. Kept apart from
 * main.js, which still owns saving: this file never builds the payload or
 * talks to admin-ajax, so the two cannot disagree about what a setting is.
 *
 * @since 8.1.0
 */
(function () {
    'use strict';

    var page = document.querySelector('.ccm-tools-perf');
    if (!page) { return; }

    var $  = function (s, c) { return (c || document).querySelector(s); };
    var $$ = function (s, c) { return Array.prototype.slice.call((c || document).querySelectorAll(s)); };

    var opts = $$('.ccm-opt');

    // ── Counters ────────────────────────────────────────────────

    function recount() {
        var on = opts.filter(function (o) { return o.dataset.state === 'on'; }).length;
        var countEl = $('#perf-count');
        if (countEl) { countEl.textContent = on + ' of ' + opts.length + ' on'; }

        $$('.ccm-optgroup').forEach(function (group) {
            var rows = $$('.ccm-opt', group);
            var n = rows.filter(function (o) { return o.dataset.state === 'on'; }).length;
            var eyebrow = $('.ccm-section__eyebrow', group);
            if (eyebrow) { eyebrow.textContent = n + ' of ' + rows.length + ' on'; }
        });
    }

    // ── Toggling ────────────────────────────────────────────────

    opts.forEach(function (opt) {
        var input = $('input[data-perf-toggle]', opt);
        if (!input) { return; }

        input.addEventListener('change', function () {
            var on = input.checked;
            opt.classList.toggle('is-on', on);
            opt.dataset.state = on ? 'on' : 'off';

            var fields = $('.ccm-opt__fields', opt);
            if (fields) { fields.hidden = !on; }

            recount();
            applyFilter();
        });
    });

    // ── Prerequisites ───────────────────────────────────────────
    //
    // Deferring stylesheets without critical CSS pasted in guarantees a flash
    // of unstyled content, so the toggle stays disabled until there is some.
    // Enforced server side too; this only keeps the interface honest.

    var criticalBox = $('#perf-critical-css-code');
    var preloadCss  = $('#perf-preload-css');

    function syncPrereq() {
        if (!criticalBox || !preloadCss) { return; }
        var has = criticalBox.value.trim().length > 0;
        preloadCss.disabled = !has;
        if (!has && preloadCss.checked) {
            preloadCss.checked = false;
            preloadCss.dispatchEvent(new Event('change'));
        }
        var row = preloadCss.closest('.ccm-opt');
        if (!row) { return; }
        var chip = $('.ccm-chip--warn', row);
        if (has && chip) { chip.remove(); }
        if (!has && !chip) {
            var span = document.createElement('span');
            span.className = 'ccm-chip ccm-chip--warn';
            span.textContent = 'Needs critical CSS first';
            var label = $('.ccm-opt__label', row);
            if (label && label.parentNode) { label.parentNode.insertBefore(span, label.nextSibling); }
        }
    }

    if (criticalBox) {
        criticalBox.addEventListener('input', syncPrereq);
        syncPrereq();
    }

    // ── Search and filter ───────────────────────────────────────

    function currentFilter() {
        var f = $('input[name="perf-filter"]:checked');
        return f ? f.value : 'all';
    }

    function applyFilter() {
        var term = ($('#perf-search') || {}).value || '';
        term = term.trim().toLowerCase();
        var mode = currentFilter();
        var shown = 0;

        opts.forEach(function (opt) {
            var matchesTerm = !term || (opt.dataset.search || '').indexOf(term) !== -1;
            var matchesMode =
                mode === 'all' ? true :
                mode === 'on' ? opt.dataset.state === 'on' :
                mode === 'safe' ? opt.dataset.risk === 'safe' :
                mode === 'risky' ? opt.dataset.risk === 'risky' : true;

            var show = matchesTerm && matchesMode;
            opt.classList.toggle('ccm-hide', !show);
            if (show) { shown++; }
        });

        // Hide a group whose every row is filtered out, so the page does not
        // become a run of empty headings.
        $$('.ccm-optgroup').forEach(function (group) {
            var any = $$('.ccm-opt', group).some(function (o) { return !o.classList.contains('ccm-hide'); });
            group.classList.toggle('ccm-hide', !any);
        });

        var none = $('#perf-noresults');
        if (none) { none.classList.toggle('ccm-hide', shown > 0); }
    }

    var search = $('#perf-search');
    if (search) { search.addEventListener('input', applyFilter); }
    $$('input[name="perf-filter"]').forEach(function (r) { r.addEventListener('change', applyFilter); });

    // ── Bulk action ─────────────────────────────────────────────

    var safeBtn = $('#perf-enable-safe');
    if (safeBtn) {
        safeBtn.addEventListener('click', function () {
            var changed = 0;
            opts.forEach(function (opt) {
                if (opt.dataset.risk !== 'safe') { return; }
                var input = $('input[data-perf-toggle]', opt);
                if (input && !input.checked && !input.disabled) {
                    input.checked = true;
                    input.dispatchEvent(new Event('change'));
                    changed++;
                }
            });
            var status = $('#perf-save-status');
            if (status) {
                status.textContent = changed
                    ? changed + ' turned on. Remember to save.'
                    : 'Everything safe was already on.';
            }
        });
    }

    // Bulk off is the counterpart to bulk safe-on. Without it the only way
    // back from a page of enabled toggles is sixty individual clicks.
    var offBtn = $('#perf-disable-all');
    if (offBtn) {
        offBtn.addEventListener('click', function () {
            var changed = 0;
            opts.forEach(function (opt) {
                var input = $('input[data-perf-toggle]', opt);
                if (input && input.checked && !input.disabled) {
                    input.checked = false;
                    input.dispatchEvent(new Event('change'));
                    changed++;
                }
            });
            var status = $('#perf-save-status');
            if (status) {
                status.textContent = changed
                    ? changed + ' turned off. Remember to save.'
                    : 'Nothing was on.';
            }
        });
    }

    // ── Master switch ───────────────────────────────────────────

    var master = $('#perf-master-enable');
    var masterWrap = $('#perf-master-wrap');
    if (master && masterWrap) {
        master.addEventListener('change', function () {
            masterWrap.classList.toggle('is-on', master.checked);
            var label = $('.ccm-masterswitch__label', masterWrap);
            if (label) { label.textContent = master.checked ? 'Optimiser active' : 'Optimiser off'; }
        });
    }

    recount();
})();
