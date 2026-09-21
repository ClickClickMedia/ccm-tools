/**
 * CCM Tools — Site Health (PageSpeed Insights)
 *
 * Deliberately separate from main.js: this module only ever reads and reports.
 * It has no code path that writes a setting, which is the whole difference
 * between it and the AI optimiser it replaces.
 */
(function () {
    'use strict';

    var $ = function (sel, ctx) { return (ctx || document).querySelector(sel); };

    if (!$('.ccm-tools-site-health')) {
        return;
    }

    var latest = { mobile: null, desktop: null };
    var busy = false;

    /** Escape text for safe insertion, quotes included. */
    function esc(str) {
        return String(str === null || str === undefined ? '' : str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    /** POST to admin-ajax with the plugin nonce. */
    function ajax(action, data) {
        var body = new FormData();
        body.append('action', action);
        body.append('nonce', window.ccmToolsData.nonce);
        Object.keys(data || {}).forEach(function (k) { body.append(k, data[k]); });

        return fetch(window.ccmToolsData.ajax_url, {
            method: 'POST',
            credentials: 'same-origin',
            body: body
        }).then(function (res) {
            if (!res.ok) { throw new Error('HTTP ' + res.status); }
            return res.json();
        }).then(function (json) {
            if (!json || !json.success) {
                var msg = (json && json.data && json.data.message) ? json.data.message : 'Request failed.';
                throw new Error(msg);
            }
            return json.data;
        });
    }

    function scoreClass(v) {
        if (v === null || v === undefined) { return ''; }
        if (v >= 90) { return 'ccm-success'; }
        if (v >= 50) { return 'ccm-warning'; }
        return 'ccm-error';
    }

    function status(html, kind) {
        var el = $('#sh-run-status');
        if (!el) { return; }
        el.innerHTML = html
            ? '<p class="' + esc(kind || 'ccm-info') + '">' + html + '</p>'
            : '';
    }

    function spinnerMarkup(label) {
        // The shared CCM spinner helper is injected by main.js; fall back to
        // plain text if this page loaded before it.
        if (window.ccmSpinner) {
            return window.ccmSpinner(16) + ' ' + esc(label);
        }
        return esc(label);
    }

    // ── Rendering ───────────────────────────────────────────────

    var SCORE_LABELS = {
        'performance': 'Performance',
        'accessibility': 'Accessibility',
        'best-practices': 'Best practices',
        'seo': 'SEO'
    };

    var CWV_LABELS = {
        'LARGEST_CONTENTFUL_PAINT_MS': 'Largest Contentful Paint',
        'CUMULATIVE_LAYOUT_SHIFT_SCORE': 'Cumulative Layout Shift',
        'INTERACTION_TO_NEXT_PAINT': 'Interaction to Next Paint',
        'FIRST_CONTENTFUL_PAINT_MS': 'First Contentful Paint',
        'EXPERIMENTAL_TIME_TO_FIRST_BYTE': 'Time to First Byte'
    };

    function renderScores() {
        var wrap = $('#sh-results');
        var card = $('#sh-results-card');
        if (!wrap || !card) { return; }

        var strategies = ['mobile', 'desktop'].filter(function (s) { return latest[s]; });
        if (!strategies.length) { return; }

        var html = '';

        strategies.forEach(function (s) {
            var r = latest[s];
            html += '<h3 style="margin-top:1rem;">' + esc(s === 'mobile' ? 'Mobile' : 'Desktop') + '</h3>';

            html += '<table class="ccm-table"><tbody>';
            Object.keys(SCORE_LABELS).forEach(function (key) {
                var v = (r.scores && r.scores[key] !== undefined) ? r.scores[key] : null;
                html += '<tr><th>' + esc(SCORE_LABELS[key]) + '</th><td><span class="' +
                    esc(scoreClass(v)) + '">' + (v === null ? '-' : esc(v)) + '</span></td></tr>';
            });
            html += '</tbody></table>';

            if (r.metrics && Object.keys(r.metrics).length) {
                html += '<table class="ccm-table"><tbody>';
                Object.keys(r.metrics).forEach(function (id) {
                    var m = r.metrics[id];
                    var cls = (m.score === null) ? '' : (m.score >= 0.9 ? 'ccm-success' : (m.score >= 0.5 ? 'ccm-warning' : 'ccm-error'));
                    html += '<tr><th>' + esc(m.label) + '</th><td><span class="' + esc(cls) + '">' +
                        esc(m.display || '-') + '</span></td></tr>';
                });
                html += '</tbody></table>';
            }

            if (r.field_data && Object.keys(r.field_data).length) {
                html += '<p class="ccm-text-muted" style="margin-top:0.75rem;font-size:0.85em;">' +
                    'Real visitors, last 28 days (Chrome UX Report)</p>';
                html += '<table class="ccm-table"><tbody>';
                Object.keys(r.field_data).forEach(function (id) {
                    var f = r.field_data[id];
                    var label = CWV_LABELS[id] || id;
                    var cls = f.category === 'FAST' ? 'ccm-success'
                        : (f.category === 'AVERAGE' ? 'ccm-warning' : 'ccm-error');
                    var value = (id === 'CUMULATIVE_LAYOUT_SHIFT_SCORE')
                        ? (f.percentile / 100).toFixed(2)
                        : f.percentile + ' ms';
                    html += '<tr><th>' + esc(label) + '</th><td><span class="' + esc(cls) + '">' +
                        esc(value) + '</span></td></tr>';
                });
                html += '</tbody></table>';
            }
        });

        wrap.innerHTML = html;
        card.style.display = '';

        var meta = $('#sh-results-meta');
        if (meta) {
            var any = latest[strategies[0]];
            meta.textContent = any.url + (any.lh_version ? ' · Lighthouse ' + any.lh_version : '');
        }
    }

    function renderFindings() {
        var wrap = $('#sh-findings');
        var card = $('#sh-findings-card');
        if (!wrap || !card) { return; }

        // Merge findings across strategies, keeping the worst saving per audit.
        var merged = {};
        ['mobile', 'desktop'].forEach(function (s) {
            if (!latest[s] || !latest[s].findings) { return; }
            latest[s].findings.forEach(function (f) {
                var seen = merged[f.id];
                if (!seen || f.savings_ms > seen.savings_ms) {
                    merged[f.id] = Object.assign({}, f, { where: {} });
                }
                merged[f.id].where[s] = true;
            });
        });

        var list = Object.keys(merged).map(function (k) { return merged[k]; });
        if (!list.length) {
            wrap.innerHTML = '<p class="ccm-success">Nothing significant to fix. Good as gold.</p>';
            card.style.display = '';
            return;
        }

        list.sort(function (a, b) { return b.savings_ms - a.savings_ms; });

        var html = '<table class="ccm-table"><thead><tr>' +
            '<th>Finding</th><th>Cost</th><th>Device</th><th>Setting that addresses it</th>' +
            '</tr></thead><tbody>';

        list.slice(0, 25).forEach(function (f) {
            var cost = '';
            if (f.savings_ms > 0) {
                cost = (f.savings_ms >= 1000)
                    ? (f.savings_ms / 1000).toFixed(1) + ' s'
                    : f.savings_ms + ' ms';
            } else if (f.savings_bytes > 0) {
                cost = Math.round(f.savings_bytes / 1024) + ' KB';
            } else {
                cost = '-';
            }

            var devices = [];
            if (f.where.mobile) { devices.push('Mobile'); }
            if (f.where.desktop) { devices.push('Desktop'); }

            var action = '<span class="ccm-text-muted">No CCM Tools setting for this one</span>';
            if (f.suggestion) {
                var href = 'admin.php?page=' + encodeURIComponent(f.suggestion.page);
                action = '<a href="' + esc(href) + '">' + esc(f.suggestion.label) + '</a>';
                if (f.suggestion.note) {
                    action += '<br><small class="ccm-text-muted">' + esc(f.suggestion.note) + '</small>';
                }
            }

            html += '<tr>' +
                '<td><strong>' + esc(f.title) + '</strong>' +
                (f.display ? '<br><small class="ccm-text-muted">' + esc(f.display) + '</small>' : '') +
                '</td>' +
                '<td>' + esc(cost) + '</td>' +
                '<td class="ccm-text-muted">' + esc(devices.join(', ')) + '</td>' +
                '<td>' + action + '</td>' +
                '</tr>';
        });

        html += '</tbody></table>';
        wrap.innerHTML = html;
        card.style.display = '';
    }

    // ── Actions ─────────────────────────────────────────────────

    function runTest(strategy) {
        var urlField = $('#sh-url');
        var url = urlField ? urlField.value.trim() : '';

        status(spinnerMarkup('Running the ' + strategy + ' test. This can take up to a minute.'), 'ccm-info');

        return ajax('ccm_tools_sh_run_test', { url: url, strategy: strategy })
            .then(function (report) {
                latest[strategy] = report;
                renderScores();
                renderFindings();
                status('', '');
                return report;
            });
    }

    function setBusy(state) {
        busy = state;
        ['#sh-run-mobile', '#sh-run-desktop', '#sh-run-both'].forEach(function (sel) {
            var b = $(sel);
            if (b) { b.disabled = state; }
        });
    }

    function handleRun(strategies) {
        if (busy) { return; }
        setBusy(true);

        var chain = Promise.resolve();
        strategies.forEach(function (s) {
            chain = chain.then(function () { return runTest(s); });
        });

        chain.catch(function (err) {
            status(esc(err.message || 'The test failed.'), 'ccm-error');
        }).then(function () {
            setBusy(false);
        });
    }

    // ── Wiring ──────────────────────────────────────────────────

    var saveBtn = $('#sh-save-key');
    if (saveBtn) {
        saveBtn.addEventListener('click', function () {
            var field = $('#sh-api-key');
            if (!field) { return; }
            saveBtn.disabled = true;
            ajax('ccm_tools_sh_save_key', { api_key: field.value })
                .then(function (data) {
                    var badge = $('#sh-key-badge');
                    if (badge) {
                        badge.textContent = data.has_key ? 'Configured' : 'Not set';
                        badge.className = 'ccm-badge ' + (data.has_key ? 'ccm-badge-success' : 'ccm-badge-warning');
                    }
                    ['#sh-run-mobile', '#sh-run-desktop', '#sh-run-both'].forEach(function (sel) {
                        var b = $(sel);
                        if (b) { b.disabled = !data.has_key; }
                    });
                    if (data.has_key) {
                        field.value = '••••••••••••••••';
                        field.dataset.hasKey = '1';
                    }
                    status('API key saved.', 'ccm-success');
                })
                .catch(function (err) {
                    status(esc(err.message), 'ccm-error');
                })
                .then(function () { saveBtn.disabled = false; });
        });
    }

    // Clear the masked value on focus so a new key can be typed.
    var keyField = $('#sh-api-key');
    if (keyField) {
        keyField.addEventListener('focus', function () {
            if (keyField.dataset.hasKey === '1' && keyField.value.indexOf('•') !== -1) {
                keyField.value = '';
            }
        });
    }

    var mobileBtn = $('#sh-run-mobile');
    if (mobileBtn) { mobileBtn.addEventListener('click', function () { handleRun(['mobile']); }); }

    var desktopBtn = $('#sh-run-desktop');
    if (desktopBtn) { desktopBtn.addEventListener('click', function () { handleRun(['desktop']); }); }

    var bothBtn = $('#sh-run-both');
    if (bothBtn) { bothBtn.addEventListener('click', function () { handleRun(['mobile', 'desktop']); }); }

    var clearBtn = $('#sh-clear-history');
    if (clearBtn) {
        clearBtn.addEventListener('click', function () {
            if (!window.confirm('Clear the recorded score history?')) { return; }
            clearBtn.disabled = true;
            ajax('ccm_tools_sh_clear_history', {})
                .then(function () {
                    var wrap = $('#sh-history');
                    if (wrap) { wrap.innerHTML = '<p class="ccm-text-muted">No tests recorded yet.</p>'; }
                })
                .catch(function (err) { status(esc(err.message), 'ccm-error'); })
                .then(function () { clearBtn.disabled = false; });
        });
    }
})();
