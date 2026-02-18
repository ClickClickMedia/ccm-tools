/**
 * CCM API Hub — Main JavaScript
 * 
 * Toast notifications, modal helpers, AJAX utilities, and common UI interactions.
 * Pure vanilla JS (ES6+) — no libraries.
 * 
 * @package CCM_API_Hub
 * @version 1.0.0
 */

'use strict';

// ─────────────────────────────────────────────
// Toast Notifications
// ─────────────────────────────────────────────

/**
 * Show a toast notification.
 * 
 * @param {string} message   - Text to display
 * @param {'success'|'error'|'warning'|'info'} type - Toast type
 * @param {number} duration  - Auto-dismiss in ms (0 = manual close)
 */
function showToast(message, type = 'info', duration = 4000) {
    const container = document.getElementById('toast-container');
    if (!container) return;

    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.setAttribute('role', 'alert');

    const icons = { success: '✓', error: '✗', warning: '⚠', info: 'ℹ' };

    toast.innerHTML = `
        <span class="toast-icon">${icons[type] || icons.info}</span>
        <span class="toast-message">${escapeHtml(message)}</span>
        <button class="toast-close" aria-label="Close">&times;</button>
    `;

    container.appendChild(toast);

    // Trigger entrance animation
    requestAnimationFrame(() => toast.classList.add('toast-visible'));

    // Close button
    toast.querySelector('.toast-close').addEventListener('click', () => dismissToast(toast));

    // Auto-dismiss
    if (duration > 0) {
        setTimeout(() => dismissToast(toast), duration);
    }

    return toast;
}

function dismissToast(toast) {
    if (!toast || toast.classList.contains('toast-removing')) return;
    toast.classList.add('toast-removing');
    toast.addEventListener('animationend', () => toast.remove());
    // Fallback removal
    setTimeout(() => toast.remove(), 400);
}

// ─────────────────────────────────────────────
// Modal Helpers
// ─────────────────────────────────────────────

/** Open a modal by ID */
function openModal(id) {
    const modal = document.getElementById(id);
    if (!modal) return;
    modal.classList.add('active');
    document.body.style.overflow = 'hidden';

    // Focus first input
    const input = modal.querySelector('input:not([type="hidden"]), select, textarea');
    if (input) setTimeout(() => input.focus(), 100);
}

/** Close a modal by ID */
function closeModal(id) {
    const modal = document.getElementById(id);
    if (!modal) return;
    modal.classList.remove('active');
    document.body.style.overflow = '';
}

/** Close any open modal */
function closeAllModals() {
    document.querySelectorAll('.modal.active').forEach(m => {
        m.classList.remove('active');
    });
    document.body.style.overflow = '';
}

// Close modal on backdrop click or Escape key
document.addEventListener('click', (e) => {
    if (e.target.classList.contains('modal') && e.target.classList.contains('active')) {
        closeAllModals();
    }
    if (e.target.closest('[data-close-modal]')) {
        const target = e.target.closest('[data-close-modal]').dataset.closeModal;
        closeModal(target);
    }
    if (e.target.closest('[data-open-modal]')) {
        e.preventDefault();
        const target = e.target.closest('[data-open-modal]').dataset.openModal;
        openModal(target);
    }
});

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeAllModals();
});

// ─────────────────────────────────────────────
// AJAX Helper
// ─────────────────────────────────────────────

/**
 * Make an AJAX request with JSON body.
 * 
 * @param {string} url     - Endpoint URL
 * @param {object} options - Fetch options override
 * @returns {Promise<object>}
 */
async function apiRequest(url, options = {}) {
    const defaults = {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        credentials: 'same-origin',
    };

    const config = { ...defaults, ...options };

    if (config.body && typeof config.body === 'object') {
        config.body = JSON.stringify(config.body);
    }

    const response = await fetch(url, config);
    const data = await response.json();

    if (!response.ok) {
        throw new Error(data.error || `HTTP ${response.status}`);
    }

    return data;
}

// ─────────────────────────────────────────────
// Copy to Clipboard
// ─────────────────────────────────────────────

async function copyToClipboard(text, successMessage = 'Copied!') {
    try {
        await navigator.clipboard.writeText(text);
        showToast(successMessage, 'success', 2000);
    } catch {
        // Fallback for older browsers
        const el = document.createElement('textarea');
        el.value = text;
        el.style.position = 'fixed';
        el.style.left = '-9999px';
        document.body.appendChild(el);
        el.select();
        document.execCommand('copy');
        document.body.removeChild(el);
        showToast(successMessage, 'success', 2000);
    }
}

// Delegated click handler for [data-copy]
document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-copy]');
    if (btn) {
        e.preventDefault();
        copyToClipboard(btn.dataset.copy, btn.dataset.copyMessage || 'Copied!');
    }
});

// ─────────────────────────────────────────────
// Confirm Delete / Dangerous Actions
// ─────────────────────────────────────────────

document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-confirm]');
    if (btn) {
        const message = btn.dataset.confirm || 'Are you sure?';
        if (!confirm(message)) {
            e.preventDefault();
            e.stopImmediatePropagation();
        }
    }
});

// ─────────────────────────────────────────────
// Auto-dismiss alerts
// ─────────────────────────────────────────────

document.querySelectorAll('.alert-success').forEach(alert => {
    setTimeout(() => {
        alert.style.transition = 'opacity 0.3s';
        alert.style.opacity = '0';
        setTimeout(() => alert.remove(), 300);
    }, 5000);
});

// ─────────────────────────────────────────────
// Utility Functions
// ─────────────────────────────────────────────

function escapeHtml(str) {
    const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
    return String(str).replace(/[&<>"']/g, c => map[c]);
}

/**
 * Format a number with commas.
 */
function formatNumber(n) {
    return Number(n).toLocaleString();
}

/**
 * Debounce a function
 */
function debounce(fn, delay = 300) {
    let timer;
    return (...args) => {
        clearTimeout(timer);
        timer = setTimeout(() => fn(...args), delay);
    };
}

// ─────────────────────────────────────────────
// Table Sort (data-sort attribute on <th>)
// ─────────────────────────────────────────────

document.addEventListener('click', (e) => {
    const th = e.target.closest('th[data-sort]');
    if (!th) return;

    const table = th.closest('table');
    const tbody = table.querySelector('tbody');
    const colIndex = Array.from(th.parentElement.children).indexOf(th);
    const type = th.dataset.sort; // 'string', 'number', 'date'
    const currentDir = th.dataset.sortDir || 'asc';
    const newDir = currentDir === 'asc' ? 'desc' : 'asc';

    // Reset sort indicators
    th.parentElement.querySelectorAll('th[data-sort]').forEach(h => {
        h.dataset.sortDir = '';
        h.classList.remove('sort-asc', 'sort-desc');
    });

    th.dataset.sortDir = newDir;
    th.classList.add(`sort-${newDir}`);

    const rows = Array.from(tbody.querySelectorAll('tr'));
    rows.sort((a, b) => {
        let va = a.children[colIndex]?.textContent.trim() || '';
        let vb = b.children[colIndex]?.textContent.trim() || '';

        if (type === 'number') {
            va = parseFloat(va.replace(/[^0-9.-]/g, '')) || 0;
            vb = parseFloat(vb.replace(/[^0-9.-]/g, '')) || 0;
        }

        let cmp = type === 'number' ? va - vb : va.localeCompare(vb);
        return newDir === 'desc' ? -cmp : cmp;
    });

    rows.forEach(r => tbody.appendChild(r));
});

// ─────────────────────────────────────────────
// Init
// ─────────────────────────────────────────────

console.log('%c⚡ CCM API Hub', 'color: #94c83e; font-weight: bold; font-size: 14px;');
