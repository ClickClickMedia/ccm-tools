/**
 * CCM Tools — Site Health
 *
 * Renders PageSpeed Insights results as gauges, threshold bars and ranked
 * findings. Read-only by design: there is no code path in this file that
 * writes a performance setting, which is the whole difference between it and
 * the AI optimiser it replaced.
 *
 * @since 8.0.0
 */
(function () {
    'use strict';

    var $ = function (sel, ctx) { return (ctx || document).querySelector(sel); };
    var $$ = function (sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); };

    var page = $('.ccm-tools-site-health');
    if (!page) { return; }

    // ── State ───────────────────────────────────────────────────

    var latest = { mobile: null, desktop: null };
    var history = [];
    var busy = false;

    try {
        var boot = JSON.parse($('#sh-bootstrap').textContent || '{}');
        history = Array.isArray(boot.history) ? boot.history : [];
    } catch (e) { history = []; }

    var CATEGORIES = [
        ['performance', 'Performance'],
        ['accessibility', 'Accessibility'],
        ['best-practices', 'Best practices'],
        ['seo', 'SEO']
    ];

    var FIELD_LABELS = {
        LARGEST_CONTENTFUL_PAINT_MS: ['Largest Contentful Paint', 'ms'],
        CUMULATIVE_LAYOUT_SHIFT_SCORE: ['Cumulative Layout Shift', ''],
        INTERACTION_TO_NEXT_PAINT: ['Interaction to Next Paint', 'ms'],
        FIRST_CONTENTFUL_PAINT_MS: ['First Contentful Paint', 'ms'],
        EXPERIMENTAL_TIME_TO_FIRST_BYTE: ['Time to First Byte', 'ms']
    };

    // ── Helpers ─────────────────────────────────────────────────

    function esc(s) {
        return String(s === null || s === undefined ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function band(score) {
        if (score === null || score === undefined) { return 'none'; }
        if (score >= 90) { return 'good'; }
        if (score >= 50) { return 'ok'; }
        return 'bad';
    }

    function device() {
        var checked = $('input[name="sh-device"]:checked');
        return checked ? checked.value : 'mobile';
    }

    function histDevice() {
        var checked = $('input[name="sh-hist-device"]:checked');
        return checked ? checked.value : 'mobile';
    }

    function show(sel, on) {
        var el = $(sel);
        if (el) { el.classList.toggle('ccm-hide', !on); }
    }

    function ajax(action, data) {
        var body = new FormData();
        body.append('action', action);
        body.append('nonce', window.ccmToolsData.nonce);
        Object.keys(data || {}).forEach(function (k) { body.append(k, data[k]); });

        return fetch(window.ccmToolsData.ajax_url, {
            method: 'POST', credentials: 'same-origin', body: body
        }).then(function (res) {
            if (!res.ok) { throw new Error('HTTP ' + res.status); }
            return res.json();
        }).then(function (json) {
            if (!json || !json.success) {
                throw new Error((json && json.data && json.data.message) || 'Request failed.');
            }
            return json.data;
        });
    }

    function status(html, kind) {
        var el = $('#sh-status');
        if (!el) { return; }
        if (!html) { el.innerHTML = ''; return; }
        var accent = kind === 'error' ? 'var(--ccm-error)'
            : kind === 'success' ? 'var(--ccm-success)' : 'var(--ccm-info)';
        el.innerHTML = '<div class="ccm-toolbar" style="margin-bottom: var(--ccm-space-lg);' +
            'border-left: 3px solid ' + accent + ';">' + html + '</div>';
    }

    function spinner(size) {
        return window.ccmSpinner ? window.ccmSpinner(size || 16) : '';
    }

    // ── Gauges ──────────────────────────────────────────────────

    var R = 46, CIRC = 2 * Math.PI * R;

    function gaugeHtml(label, score) {
        var b = band(score);
        var pct = (score === null || score === undefined) ? 0 : Math.max(0, Math.min(100, score)) / 100;
        var off = CIRC * (1 - pct);
        return '<div class="ccm-gauge ccm-gauge--' + b + '">' +
            '<div class="ccm-gauge__ring">' +
                '<svg viewBox="0 0 108 108" aria-hidden="true" focusable="false">' +
                    '<circle class="ccm-gauge__track" cx="54" cy="54" r="' + R + '"/>' +
                    '<circle class="ccm-gauge__value" cx="54" cy="54" r="' + R + '"' +
                        ' stroke-dasharray="' + CIRC.toFixed(2) + '"' +
                        ' stroke-dashoffset="' + off.toFixed(2) + '"/>' +
                '</svg>' +
                '<div class="ccm-gauge__num">' +
                    ((score === null || score === undefined) ? '<small>n/a</small>' : esc(score)) +
                '</div>' +
            '</div>' +
            '<div><div class="ccm-gauge__label">' + esc(label) + '</div></div>' +
        '</div>';
    }

    function renderScores() {
        var r = latest[device()];
        if (!r) { show('#sh-scores-wrap', false); return; }

        $('#sh-gauges').innerHTML = CATEGORIES.map(function (c) {
            var v = (r.scores && r.scores[c[0]] !== undefined) ? r.scores[c[0]] : null;
            return gaugeHtml(c[1], v);
        }).join('');

        var meta = [];
        if (r.url) { meta.push(r.url); }
        if (r.lh_version) { meta.push('Lighthouse ' + r.lh_version); }
        meta.push(device() === 'mobile' ? 'Mobile, simulated slow 4G' : 'Desktop');
        $('#sh-scores-meta').textContent = meta.join(' · ');
        $('#sh-scores-eyebrow').textContent = 'Lighthouse';

        show('#sh-scores-wrap', true);
    }

    // ── Core Web Vitals ─────────────────────────────────────────

    function metricHtml(m) {
        // Bands are drawn to a scale that runs to 1.4x the poor boundary, so
        // "good" is not a sliver and a very bad value still lands on the bar.
        var max = m.poor * 1.4;
        var goodPct = Math.min(100, (m.good / max) * 100);
        var okPct = Math.min(100 - goodPct, ((m.poor - m.good) / max) * 100);
        var badPct = Math.max(0, 100 - goodPct - okPct);

        var markerPct = null;
        if (m.numeric !== null && m.numeric !== undefined) {
            markerPct = Math.max(0, Math.min(100, (m.numeric / max) * 100));
        }

        var unit = m.abbr === 'CLS' ? '' : 's';
        var lo = m.abbr === 'CLS' ? m.good.toFixed(2) : (m.good / 1000) + unit;
        var hi = m.abbr === 'CLS' ? m.poor.toFixed(2) : (m.poor / 1000) + unit;

        return '<div class="ccm-metric ccm-metric--' + esc(m.band || 'none') + '">' +
            '<div class="ccm-metric__head">' +
                '<span class="ccm-metric__name">' + esc(m.label) + '</span>' +
                '<span class="ccm-metric__abbr">' + esc(m.abbr || '') + '</span>' +
            '</div>' +
            '<div class="ccm-metric__value">' + esc(m.display || '-') + '</div>' +
            '<div class="ccm-metric__bands">' +
                '<span class="ccm-metric__band ccm-metric__band--good" style="width:' + goodPct.toFixed(1) + '%"></span>' +
                '<span class="ccm-metric__band ccm-metric__band--ok" style="width:' + okPct.toFixed(1) + '%"></span>' +
                '<span class="ccm-metric__band ccm-metric__band--bad" style="width:' + badPct.toFixed(1) + '%"></span>' +
            '</div>' +
            '<div class="ccm-metric__scale">' +
                (markerPct === null ? '' :
                    '<span class="ccm-metric__marker" style="left:' + markerPct.toFixed(1) + '%"></span>') +
            '</div>' +
            '<div class="ccm-metric__legend"><span>' + esc(lo) + '</span><span>' + esc(hi) + '</span></div>' +
        '</div>';
    }

    function renderVitals() {
        var r = latest[device()];
        if (!r || !r.metrics || !Object.keys(r.metrics).length) {
            show('#sh-vitals-wrap', false);
            return;
        }

        $('#sh-vitals').innerHTML = Object.keys(r.metrics)
            .map(function (id) { return metricHtml(r.metrics[id]); }).join('');

        // Field data, when Google has enough real traffic for this origin.
        var field = r.field_data || {};
        var ids = Object.keys(field);
        if (ids.length) {
            $('#sh-field').innerHTML = ids.map(function (id) {
                var f = field[id];
                var meta = FIELD_LABELS[id] || [id, ''];
                var cls = f.category === 'FAST' ? 'good' : (f.category === 'AVERAGE' ? 'warn' : 'bad');
                var value = (id === 'CUMULATIVE_LAYOUT_SHIFT_SCORE')
                    ? (f.percentile / 100).toFixed(2)
                    : f.percentile + ' ' + meta[1];
                return '<div><span class="ccm-kv__k">' + esc(meta[0]) + '</span>' +
                    '<span class="ccm-kv__v"><span class="ccm-chip ccm-chip--' + cls + '">' +
                    esc(value) + '</span></span></div>';
            }).join('');
            show('#sh-field-wrap', true);
        } else {
            show('#sh-field-wrap', false);
        }

        show('#sh-vitals-wrap', true);
    }

    // ── Findings ────────────────────────────────────────────────

    function renderFindings() {
        // Merge across whichever devices have been tested, keeping the worst
        // saving per audit so one list covers both.
        var merged = {};
        ['mobile', 'desktop'].forEach(function (s) {
            if (!latest[s] || !latest[s].findings) { return; }
            latest[s].findings.forEach(function (f) {
                var seen = merged[f.id];
                if (!seen || f.savings_ms > seen.savings_ms) {
                    merged[f.id] = { f: f, where: (seen ? seen.where : {}) };
                }
                merged[f.id].where[s] = true;
            });
        });

        var list = Object.keys(merged).map(function (k) {
            return { f: merged[k].f, where: merged[k].where };
        });

        if (!list.length) {
            if (!latest.mobile && !latest.desktop) { show('#sh-findings-wrap', false); return; }
            $('#sh-findings').innerHTML =
                '<div class="ccm-finding ccm-finding--low"><span class="ccm-finding__stripe"></span>' +
                '<div class="ccm-finding__body"><p class="ccm-finding__title">Nothing significant to fix</p>' +
                '<p class="ccm-finding__detail">Every audit with a measurable cost came back clean. Good as gold.</p>' +
                '</div></div>';
            $('#sh-findings-count').textContent = '0 findings';
            show('#sh-findings-wrap', true);
            return;
        }

        list.sort(function (a, b) { return b.f.savings_ms - a.f.savings_ms; });
        var worst = list[0].f.savings_ms || 1;

        $('#sh-findings').innerHTML = list.slice(0, 30).map(function (item) {
            var f = item.f;
            var sev = f.savings_ms >= 1000 ? 'high' : (f.savings_ms >= 250 ? 'medium' : 'low');

            var cost = '-';
            if (f.savings_ms > 0) {
                cost = f.savings_ms >= 1000 ? (f.savings_ms / 1000).toFixed(1) + ' s' : f.savings_ms + ' ms';
            } else if (f.savings_bytes > 0) {
                cost = Math.round(f.savings_bytes / 1024) + ' KB';
            }

            var barPct = f.savings_ms > 0 ? Math.max(4, (f.savings_ms / worst) * 100) : 0;

            var devices = [];
            if (item.where.mobile) { devices.push('Mobile'); }
            if (item.where.desktop) { devices.push('Desktop'); }

            var fix = '<span class="ccm-text-muted">No CCM Tools setting covers this one</span>';
            if (f.suggestion) {
                fix = '<a href="' + esc('admin.php?page=' + f.suggestion.page) + '">' +
                    esc(f.suggestion.label) + ' &rarr;</a>';
                if (f.suggestion.note) {
                    fix += '<span class="ccm-text-muted">' + esc(f.suggestion.note) + '</span>';
                }
            }

            return '<div class="ccm-finding ccm-finding--' + sev + '">' +
                '<span class="ccm-finding__stripe"></span>' +
                '<div class="ccm-finding__body">' +
                    '<p class="ccm-finding__title">' + esc(f.title) + '</p>' +
                    (f.display ? '<p class="ccm-finding__detail">' + esc(f.display) + '</p>' : '') +
                    '<div class="ccm-finding__fix">' + fix +
                        (devices.length === 1 ? '<span class="ccm-chip">' + esc(devices[0]) + ' only</span>' : '') +
                    '</div>' +
                '</div>' +
                '<div class="ccm-finding__impact">' +
                    '<div class="ccm-finding__cost">' + esc(cost) + '</div>' +
                    '<div class="ccm-finding__bar"><i style="width:' + barPct.toFixed(1) + '%"></i></div>' +
                '</div>' +
            '</div>';
        }).join('');

        $('#sh-findings-count').textContent = list.length + (list.length === 1 ? ' finding' : ' findings');
        show('#sh-findings-wrap', true);
    }

    // ── History ─────────────────────────────────────────────────

    function trendHtml(label, points) {
        var now = points.length ? points[points.length - 1] : null;
        var b = band(now);
        var colourVar = b === 'good' ? 'success' : (b === 'ok' ? 'warning' : (b === 'bad' ? 'error' : 'text-light'));

        var head = '<div class="ccm-trend__head"><span class="ccm-trend__name">' + esc(label) + '</span>';
        if (points.length >= 2) {
            var delta = now - points[points.length - 2];
            var dir = delta > 0 ? 'up' : (delta < 0 ? 'down' : 'flat');
            head += '<span class="ccm-trend__delta ccm-trend__delta--' + dir + '">' +
                (delta > 0 ? '+' : '') + delta + '</span>';
        }
        head += '</div>';

        var spark = '';
        if (points.length >= 2) {
            // Fixed 0-100 domain. Auto-scaling to the data's own range makes a
            // wobble between 97 and 99 look like a collapse.
            var w = 100, h = 28, step = w / (points.length - 1);
            var coords = points.map(function (v, i) {
                return (i * step).toFixed(2) + ',' +
                    (h - (Math.max(0, Math.min(100, v)) / 100) * h).toFixed(2);
            });
            var lastXY = coords[coords.length - 1].split(',');
            spark = '<svg class="ccm-spark" viewBox="-1 -2 ' + (w + 2) + ' ' + (h + 4) + '" preserveAspectRatio="none" aria-hidden="true">' +
                '<polygon class="ccm-spark__area" points="0,' + h + ' ' + coords.join(' ') + ' ' + w + ',' + h + '"/>' +
                '<polyline class="ccm-spark__line" points="' + coords.join(' ') + '" vector-effect="non-scaling-stroke"/>' +
                '<circle class="ccm-spark__dot" cx="' + lastXY[0] + '" cy="' + lastXY[1] + '" r="2.5" vector-effect="non-scaling-stroke"/>' +
                '</svg>' +
                '<span class="sr-only">' + points.length + ' recorded runs</span>';
        }

        return '<div class="ccm-trend" style="color: var(--ccm-' + colourVar + ');">' + head +
            '<div class="ccm-trend__now" style="color: var(--ccm-text);">' +
            (now === null ? '&ndash;' : esc(now)) + '</div>' + spark + '</div>';
    }

    function renderHistory() {
        if (!history.length) { show('#sh-history-wrap', false); return; }

        var dev = histDevice();
        var rows = history.filter(function (r) { return (r.strategy || 'mobile') === dev; });

        $('#sh-trends').innerHTML = CATEGORIES.map(function (c) {
            var pts = rows.map(function (r) {
                return (r.scores && r.scores[c[0]] !== undefined) ? Number(r.scores[c[0]]) : null;
            }).filter(function (v) { return v !== null && !isNaN(v); });
            return trendHtml(c[1], pts);
        }).join('');

        // The full log is every run on record, newest first, both devices.
        var all = history.slice().reverse();
        $('#sh-log').querySelector('tbody').innerHTML = all.map(function (r) {
            var sc = r.scores || {};
            var cell = function (k) {
                if (sc[k] === undefined) { return '<span class="ccm-text-muted">-</span>'; }
                var v = Number(sc[k]);
                var b = band(v);
                return '<span class="ccm-chip ccm-chip--' +
                    (b === 'good' ? 'good' : (b === 'ok' ? 'warn' : 'bad')) + '">' + v + '</span>';
            };
            var when = new Date(Number(r.at) * 1000);
            var path = '/';
            try { path = new URL(r.url).pathname || '/'; } catch (e) {}
            return '<tr>' +
                '<td>' + esc(when.toLocaleString()) + '</td>' +
                '<td>' + esc((r.strategy === 'desktop') ? 'Desktop' : 'Mobile') + '</td>' +
                '<td>' + cell('performance') + '</td>' +
                '<td>' + cell('accessibility') + '</td>' +
                '<td>' + cell('best-practices') + '</td>' +
                '<td>' + cell('seo') + '</td>' +
                '<td class="ccm-text-muted">' + esc(path) + '</td>' +
            '</tr>';
        }).join('');

        $('#sh-log-count').textContent = all.length + (all.length === 1 ? ' run' : ' runs');
        show('#sh-history-wrap', true);
    }

    // ── Running a test ──────────────────────────────────────────

    function setBusy(on) {
        busy = on;
        ['#sh-run', '#sh-run-both'].forEach(function (sel) {
            var b = $(sel);
            if (b) { b.disabled = on; }
        });
    }

    function runOne(strategy) {
        status(spinner(16) + ' <span>Running the ' + esc(strategy) +
            ' test. Google can take up to a minute.</span>', 'info');

        return ajax('ccm_tools_sh_run_test', {
            url: ($('#sh-url') || {}).value || '',
            strategy: strategy
        }).then(function (report) {
            latest[strategy] = report;

            // Keep the local history in step so the trend updates without a reload.
            history.push({
                at: report.fetched_at,
                strategy: report.strategy,
                url: report.url,
                scores: report.scores || {}
            });

            var radio = $('#sh-dev-' + strategy);
            if (radio) { radio.checked = true; }

            renderAll();
            return report;
        });
    }

    function run(strategies) {
        if (busy) { return; }
        setBusy(true);
        var chain = Promise.resolve();
        strategies.forEach(function (s) { chain = chain.then(function () { return runOne(s); }); });
        chain.then(function () {
            status('<span class="ccm-chip ccm-chip--good">Done</span> <span>Results updated.</span>', 'success');
            window.setTimeout(function () { status(''); }, 4000);
        }).catch(function (err) {
            status('<span class="ccm-chip ccm-chip--bad">Failed</span> <span>' +
                esc(err.message || 'The test failed.') + '</span>', 'error');
        }).then(function () { setBusy(false); });
    }

    function renderAll() {
        // Both prompts are answered the moment there is a result on screen.
        show('#sh-never', false);
        show('#sh-nokey', false);
        renderScores();
        renderVitals();
        renderFindings();
        renderHistory();
    }

    // ── Wiring ──────────────────────────────────────────────────

    var runBtn = $('#sh-run');
    if (runBtn) { runBtn.addEventListener('click', function () { run([device()]); }); }

    var bothBtn = $('#sh-run-both');
    if (bothBtn) { bothBtn.addEventListener('click', function () { run(['mobile', 'desktop']); }); }

    $$('input[name="sh-device"]').forEach(function (r) {
        r.addEventListener('change', function () { renderScores(); renderVitals(); });
    });

    $$('input[name="sh-hist-device"]').forEach(function (r) {
        r.addEventListener('change', renderHistory);
    });

    var jump = $('#sh-jump-setup');
    if (jump) {
        jump.addEventListener('click', function () {
            var setup = $('#sh-setup');
            if (!setup) { return; }
            setup.open = true;
            setup.scrollIntoView({ behavior: 'smooth', block: 'center' });
            var field = $('#sh-api-key');
            if (field) { window.setTimeout(function () { field.focus(); }, 400); }
        });
    }

    var saveBtn = $('#sh-save-key');
    if (saveBtn) {
        saveBtn.addEventListener('click', function () {
            var field = $('#sh-api-key');
            var msg = $('#sh-key-msg');
            if (!field) { return; }
            saveBtn.disabled = true;
            ajax('ccm_tools_sh_save_key', { api_key: field.value })
                .then(function (data) {
                    if (msg) { msg.textContent = 'Saved.'; }
                    ['#sh-run', '#sh-run-both'].forEach(function (sel) {
                        var b = $(sel);
                        if (b) { b.disabled = !data.has_key; }
                    });
                    if (data.has_key) {
                        field.value = '••••••••••••••••';
                        field.dataset.hasKey = '1';
                        show('#sh-nokey', false);
                        if (!history.length) { show('#sh-never', true); }
                    }
                })
                .catch(function (err) { if (msg) { msg.textContent = err.message; } })
                .then(function () { saveBtn.disabled = false; });
        });
    }

    var keyField = $('#sh-api-key');
    if (keyField) {
        keyField.addEventListener('focus', function () {
            if (keyField.dataset.hasKey === '1' && keyField.value.indexOf('•') !== -1) {
                keyField.value = '';
            }
        });
    }

    var clearBtn = $('#sh-clear-history');
    if (clearBtn) {
        clearBtn.addEventListener('click', function () {
            if (!window.confirm('Clear every recorded run? This cannot be undone.')) { return; }
            clearBtn.disabled = true;
            ajax('ccm_tools_sh_clear_history', {})
                .then(function () { history = []; show('#sh-history-wrap', false); })
                .catch(function (err) { status(esc(err.message), 'error'); })
                .then(function () { clearBtn.disabled = false; });
        });
    }

    // First paint from whatever is already on record.
    renderHistory();
})();
