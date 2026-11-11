/* Search engine visibility nag: dismiss only with a reason. */
(function () {
    'use strict';

    var cfg = window.ccmSeoNag;
    var banner = document.getElementById('ccmsn-banner');
    var dialog = document.getElementById('ccmsn-dialog');
    if (!cfg || !banner || !dialog || typeof dialog.showModal !== 'function') {
        return;
    }

    var form = document.getElementById('ccmsn-form');
    var other = document.getElementById('ccmsn-other');
    var detail = document.getElementById('ccmsn-detail');
    var error = document.getElementById('ccmsn-error');
    var confirmBtn = document.getElementById('ccmsn-confirm');
    var busy = false;

    function reason() {
        var picked = form.querySelector('input[name="ccmsn_reason"]:checked');
        return picked ? picked.value : '';
    }

    function refresh() {
        var r = reason();
        other.hidden = r !== 'other';
        confirmBtn.disabled = busy || r === '' || (r === 'other' && detail.value.trim() === '');
    }

    function showError(msg) {
        error.textContent = msg;
        error.hidden = !msg;
    }

    document.getElementById('ccmsn-dismiss').addEventListener('click', function () {
        showError('');
        refresh();
        dialog.showModal();
    });

    document.getElementById('ccmsn-cancel').addEventListener('click', function () {
        dialog.close();
    });

    form.addEventListener('change', function (e) {
        refresh();
        if (e.target && e.target.value === 'other') {
            detail.focus();
        }
    });
    detail.addEventListener('input', refresh);

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var r = reason();
        if (busy || !r || (r === 'other' && detail.value.trim() === '')) {
            return;
        }

        busy = true;
        refresh();
        showError('');

        var body = new URLSearchParams();
        body.append('action', 'ccm_tools_seo_nag_dismiss');
        body.append('nonce', cfg.nonce);
        body.append('reason', r);
        if (r === 'other') {
            body.append('detail', detail.value.trim());
        }

        fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
            .then(function (res) { return res.json(); })
            .then(function (json) {
                if (json && json.success) {
                    dialog.close();
                    banner.remove();
                    dialog.remove();
                    return;
                }
                throw new Error(json && json.data && json.data.message ? json.data.message : cfg.failed);
            })
            .catch(function (err) {
                showError(err && err.message ? err.message : cfg.failed);
            })
            .then(function () {
                busy = false;
                if (document.body.contains(confirmBtn)) {
                    refresh();
                }
            });
    });
}());
