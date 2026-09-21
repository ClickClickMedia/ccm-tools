/**
 * CCM Tools - Modern Vanilla JavaScript
 * Pure JS without jQuery or other dependencies
 * Version: 8.0.3
 */

(function() {
    'use strict';

    // ===================================
    // Utility Functions
    // ===================================
    
    /**
     * DOM Ready handler
     * @param {Function} fn - Callback function
     */
    function ready(fn) {
        if (document.readyState !== 'loading') {
            fn();
        } else {
            document.addEventListener('DOMContentLoaded', fn);
        }
    }

    /**
     * Simple DOM selector (shorthand for querySelector)
     * @param {string} selector - CSS selector
     * @param {Element} context - Context element
     * @returns {Element|null}
     */
    function $(selector, context = document) {
        return context.querySelector(selector);
    }

    /**
     * Select all elements
     * @param {string} selector - CSS selector
     * @param {Element} context - Context element
     * @returns {NodeList}
     */
    function $$(selector, context = document) {
        return context.querySelectorAll(selector);
    }

    /**
     * Create element with attributes and content
     * @param {string} tag - HTML tag name
     * @param {Object} attrs - Attributes object
     * @param {string|Element|Array} children - Child content
     * @returns {Element}
     */
    function createElement(tag, attrs = {}, children = null) {
        const el = document.createElement(tag);
        
        Object.entries(attrs).forEach(([key, value]) => {
            if (key === 'className') {
                el.className = value;
            } else if (key === 'dataset') {
                Object.entries(value).forEach(([dataKey, dataValue]) => {
                    el.dataset[dataKey] = dataValue;
                });
            } else if (key.startsWith('on') && typeof value === 'function') {
                el.addEventListener(key.slice(2).toLowerCase(), value);
            } else {
                el.setAttribute(key, value);
            }
        });
        
        if (children !== null) {
            if (typeof children === 'string') {
                el.innerHTML = children;
            } else if (children instanceof Element) {
                el.appendChild(children);
            } else if (Array.isArray(children)) {
                children.forEach(child => {
                    if (typeof child === 'string') {
                        el.insertAdjacentHTML('beforeend', child);
                    } else if (child instanceof Element) {
                        el.appendChild(child);
                    }
                });
            }
        }
        
        return el;
    }

    /**
     * Remove existing spinners from element
     * @param {Element|string} target - Target element or selector
     */
    function removeSpinner(target) {
        const el = typeof target === 'string' ? $(target) : target;
        if (el) {
            const spinners = $$('.ccm-spinner', el);
            spinners.forEach(spinner => spinner.remove());
        }
    }

    /**
     * Show inline notification (replaces alerts)
     * @param {string} message - Message to display
     * @param {string} type - Message type (success, error, info, warning)
     * @param {number} duration - Auto-hide duration in ms (0 = no auto-hide)
     */
    function showNotification(message, type = 'info', duration = 5000) {
        // Remove existing notifications
        const existingNotifications = $$('.ccm-notification');
        existingNotifications.forEach(n => n.remove());
        
        const icons = {
            success: '✓',
            error: '✗',
            warning: '⚠',
            info: 'ℹ'
        };
        
        const notification = createElement('div', {
            className: `ccm-notification ccm-notification-${type}`
        }, `<span class="ccm-icon">${icons[type]}</span><span class="ccm-notification-message">${escapeHtml(message)}</span><button class="ccm-notification-close">×</button>`);
        
        // Add to page
        const container = $('.ccm-tools') || document.body;
        container.insertAdjacentElement('afterbegin', notification);
        
        // Trigger animation
        requestAnimationFrame(() => {
            notification.classList.add('ccm-notification-show');
        });
        
        // Close button handler
        const closeBtn = $('.ccm-notification-close', notification);
        if (closeBtn) {
            closeBtn.addEventListener('click', () => {
                notification.classList.remove('ccm-notification-show');
                setTimeout(() => notification.remove(), 300);
            });
        }
        
        // Auto-hide
        if (duration > 0) {
            setTimeout(() => {
                if (notification.parentElement) {
                    notification.classList.remove('ccm-notification-show');
                    setTimeout(() => notification.remove(), 300);
                }
            }, duration);
        }
    }

    /**
     * Show confirmation modal (replaces confirm())
     * @param {string} message - Message to display
     * @param {Function} onConfirm - Callback when confirmed
     * @param {string} confirmText - Text for confirm button (default: 'Confirm')
     * @param {string} cancelText - Text for cancel button (default: 'Cancel')
     */
    function showConfirmModal(message, onConfirm, confirmText = 'Confirm', cancelText = 'Cancel') {
        // Remove any existing modal
        const existingModal = $('.ccm-modal-overlay');
        if (existingModal) existingModal.remove();
        
        const modal = createElement('div', {
            className: 'ccm-modal-overlay'
        }, `
            <div class="ccm-modal">
                <div class="ccm-modal-body">
                    <p>${escapeHtml(message)}</p>
                </div>
                <div class="ccm-modal-footer">
                    <button class="ccm-button ccm-modal-cancel">${escapeHtml(cancelText)}</button>
                    <button class="ccm-button ccm-button-primary ccm-modal-confirm">${escapeHtml(confirmText)}</button>
                </div>
            </div>
        `);
        
        document.body.appendChild(modal);
        
        // Trigger animation
        requestAnimationFrame(() => {
            modal.classList.add('ccm-modal-show');
        });
        
        const closeModal = () => {
            modal.classList.remove('ccm-modal-show');
            // Remove the Escape listener on every dismissal path, not just Escape
            // itself, otherwise a click-closed modal leaks a keydown listener.
            document.removeEventListener('keydown', handleEscape);
            setTimeout(() => modal.remove(), 200);
        };

        // Cancel button
        const cancelBtn = $('.ccm-modal-cancel', modal);
        cancelBtn.addEventListener('click', closeModal);

        // Confirm button
        const confirmBtn = $('.ccm-modal-confirm', modal);
        confirmBtn.addEventListener('click', () => {
            closeModal();
            if (typeof onConfirm === 'function') {
                onConfirm();
            }
        });

        // Close on overlay click
        modal.addEventListener('click', (e) => {
            if (e.target === modal) closeModal();
        });

        // Close on Escape key
        const handleEscape = (e) => {
            if (e.key === 'Escape') {
                closeModal();
            }
        };
        document.addEventListener('keydown', handleEscape);
    }

    /**
     * Escape HTML special characters, including quotes, so the result is
     * safe to insert into text content AND into HTML attribute values.
     * @param {string} str - String to escape
     * @returns {string}
     */
    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str).replace(/[&<>"']/g, (c) => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;'
        }[c]));
    }

    // Alias: escHtml was a duplicate implementation used by the Cloudflare
    // integration code; kept as an alias so no call site has to change.
    const escHtml = escapeHtml;

    // ===================================
    // AJAX Handler
    // ===================================

    /**
     * Make AJAX request to WordPress admin-ajax.php
     * @param {string} action - WordPress action name
     * @param {Object} data - Additional data to send
     * @param {Object} options - Request options
     * @returns {Promise}
     */
    async function ajax(action, data = {}, options = {}) {
        const url = typeof ajaxurl !== 'undefined' ? ajaxurl : ccmToolsData.ajax_url;
        
        const formData = new FormData();
        formData.append('action', action);
        formData.append('nonce', ccmToolsData.nonce);
        
        Object.entries(data).forEach(([key, value]) => {
            if (Array.isArray(value)) {
                // Send arrays with [] notation for PHP to parse as array
                value.forEach(item => formData.append(`${key}[]`, item));
            } else {
                formData.append(key, value);
            }
        });
        
        const controller = new AbortController();
        const timeout = options.timeout || 30000;
        const timeoutId = setTimeout(() => controller.abort(), timeout);
        
        try {
            const response = await fetch(url, {
                method: 'POST',
                body: formData,
                signal: controller.signal
            });
            
            clearTimeout(timeoutId);
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            const result = await response.json();
            
            if (result && result.success) {
                return result;
            } else {
                // result.data can be a string, an object with a message key, or
                // absent entirely. Never pass a raw object into Error() — that
                // renders as the useless string "[object Object]".
                const data = result?.data;
                const message = (data && typeof data === 'object' && data.message)
                    ? data.message
                    : (typeof data === 'string' ? data : 'Unknown error occurred');
                throw new Error(message);
            }
        } catch (error) {
            if (error.name === 'AbortError') {
                throw new Error('Request timeout');
            }
            throw error;
        }
    }

    /**
     * Make AJAX request with UI feedback
     * @param {string} action - WordPress action name
     * @param {Function} callback - Success callback
     * @param {Object} data - Additional data to send
     */
    async function makeAjaxRequest(action, callback = null, data = {}) {
        const resultBox = $('#resultBox');
        
        if (resultBox) {
            removeSpinner(resultBox);
            resultBox.insertAdjacentHTML('afterbegin', '<div class="ccm-spinner" style="margin: 10px 0;"></div>');
        }
        
        try {
            const response = await ajax(action, data);
            
            if (resultBox) removeSpinner(resultBox);
            
            if (callback) {
                try {
                    callback(response);
                } catch (e) {
                    if (resultBox) {
                        resultBox.innerHTML = `<p class="ccm-error">Callback error: ${escapeHtml(e.message)}</p>`;
                    }
                }
            } else if (resultBox) {
                resultBox.innerHTML = response.data || 'Success';
            }
        } catch (error) {
            if (resultBox) {
                removeSpinner(resultBox);
                resultBox.innerHTML = `<p class="ccm-error">Error: ${escapeHtml(error.message)}</p>`;
            }
        }
    }

    /** Cache of the most recently loaded optimization option metadata, used by the button handlers in initOptimizationOptions() */
    let currentOptimizationOptions = null;

    /**
     * Load optimization options and stats into the options panel.
     * Safe to call repeatedly (e.g. to refresh stats after a run) — unlike
     * initOptimizationOptions(), it never (re)binds any button handlers.
     */
    async function loadOptimizationOptions() {
        const optionsContainer = $('#optimization-options');
        const runButton = $('#run-optimizations');

        if (!optionsContainer) return;

        try {
            // Load options and stats
            const response = await ajax('ccm_tools_get_optimization_options');

            if (!response?.data?.options) {
                optionsContainer.innerHTML = '<p class="ccm-error">Failed to load optimization options</p>';
                return;
            }

            const { options, stats } = response.data;
            currentOptimizationOptions = options;

            // Group options by risk level
            const groups = {
                safe: { label: '✓ Safe Operations', items: [] },
                moderate: { label: '⚡ Moderate Risk', items: [] },
                high: { label: '⚠️ High Risk - Use With Caution', items: [] }
            };

            // Populate groups
            for (const [key, opt] of Object.entries(options)) {
                const risk = opt.risk || 'moderate';
                if (groups[risk]) {
                    groups[risk].items.push({ key, ...opt });
                }
            }

            // Build HTML
            let html = '';

            for (const [riskLevel, group] of Object.entries(groups)) {
                if (group.items.length === 0) continue;

                html += `<div class="ccm-opt-group ${riskLevel}">`;
                html += `<div class="ccm-opt-group-header">${group.label}</div>`;
                html += '<div class="ccm-opt-group-items">';

                for (const item of group.items) {
                    const stat = getStatForOption(item.key, stats);
                    const statClass = stat > 0 ? (riskLevel === 'high' ? 'warning' : 'has-items') : '';
                    const checked = item.default ? 'checked' : '';

                    html += `
                        <div class="ccm-opt-item">
                            <input type="checkbox" id="opt-${item.key}" name="optimization[]" value="${item.key}" ${checked}>
                            <div class="ccm-opt-item-content">
                                <label class="ccm-opt-item-label" for="opt-${item.key}">${escapeHtml(item.label)}</label>
                                <span class="ccm-opt-item-desc">${escapeHtml(item.description)}</span>
                            </div>
                            ${stat !== null ? `<span class="ccm-opt-item-stat ${statClass}">${stat}</span>` : ''}
                        </div>
                    `;
                }

                html += '</div></div>';
            }

            optionsContainer.innerHTML = html;

            // Disable and annotate options that are already applied or have nothing to do
            const nothingToDo = {
                'optimize_tables': stats.tables_needing_optimization === 0,
                'convert_innodb': stats.tables_needing_innodb === 0,
                'update_collation': stats.tables_needing_collation === 0,
                'clear_transients': stats.transients === 0,
                'clean_spam_comments': stats.spam_comments === 0,
                'clean_trashed_comments': stats.trashed_comments === 0,
                'clean_trashed_posts': stats.trashed_posts === 0,
                'clean_auto_drafts': stats.auto_drafts === 0,
                'clean_orphaned_postmeta': stats.orphaned_postmeta === 0,
                'clean_orphaned_commentmeta': stats.orphaned_commentmeta === 0,
                'clean_oembed_cache': stats.oembed_cache === 0,
                'limit_revisions': stats.excess_revisions === 0,
                'delete_all_revisions': stats.revisions === 0,
                'clean_orphaned_termmeta': stats.orphaned_termmeta === 0,
                'clean_orphaned_relationships': stats.orphaned_relationships === 0,
                'add_postmeta_index': !!stats.index_postmeta_exists,
                'add_usermeta_index': !!stats.index_usermeta_exists,
                'add_commentmeta_index': !!stats.index_commentmeta_exists,
                'add_termmeta_index': !!stats.index_termmeta_exists,
                'add_postmeta_composite_index': !!stats.index_postmeta_composite_exists,
            };
            for (const [optKey, isDone] of Object.entries(nothingToDo)) {
                if (!isDone) continue;
                const cb = optionsContainer.querySelector(`#opt-${optKey}`);
                if (!cb) continue;
                cb.checked = false;
                cb.disabled = true;
                const descEl = cb.closest('.ccm-opt-item')?.querySelector('.ccm-opt-item-desc');
                if (descEl) {
                    descEl.innerHTML = '<span style="color:var(--ccm-success)">✓ Already applied</span>';
                }
                const statEl = cb.closest('.ccm-opt-item')?.querySelector('.ccm-opt-item-stat');
                if (statEl) {
                    statEl.textContent = '✓';
                    statEl.className = 'ccm-opt-item-stat';
                    statEl.style.color = 'var(--ccm-success)';
                }
            }

            // Enable run button
            if (runButton) {
                runButton.disabled = false;
            }

        } catch (error) {
            optionsContainer.innerHTML = `<p class="ccm-error">Error loading options: ${escapeHtml(error.message)}</p>`;
        }
    }

    /**
     * Initialize the optimization options panel and bind its buttons.
     * Binds #run-optimizations, #select-all-safe and #deselect-all exactly
     * once. loadOptimizationOptions() only ever replaces optionsContainer's
     * own innerHTML — never these buttons — so it's called separately (and
     * repeatedly, e.g. to refresh stats after a run) without rebinding them.
     */
    function initOptimizationOptions() {
        const optionsContainer = $('#optimization-options');
        const runButton = $('#run-optimizations');
        const selectSafeButton = $('#select-all-safe');
        const deselectAllButton = $('#deselect-all');

        if (!optionsContainer) return;

        if (runButton) {
            runButton.addEventListener('click', async (e) => {
                e.preventDefault();
                await runSelectedOptimizations();
            });
        }

        if (selectSafeButton) {
            selectSafeButton.addEventListener('click', (e) => {
                e.preventDefault();
                // Check safe options, uncheck others
                optionsContainer.querySelectorAll('input[type="checkbox"]').forEach(cb => {
                    const optKey = cb.value;
                    const opt = currentOptimizationOptions && currentOptimizationOptions[optKey];
                    cb.checked = opt && opt.risk === 'safe';
                });
            });
        }

        if (deselectAllButton) {
            deselectAllButton.addEventListener('click', (e) => {
                e.preventDefault();
                optionsContainer.querySelectorAll('input[type="checkbox"]').forEach(cb => {
                    cb.checked = false;
                });
            });
        }

        loadOptimizationOptions();
    }
    
    /**
     * Get the statistic value for an optimization option
     */
    function getStatForOption(optKey, stats) {
        const mapping = {
            'clear_transients': stats.transients,
            'optimize_tables': stats.tables_needing_optimization,
            'convert_innodb': stats.tables_needing_innodb,
            'update_collation': stats.tables_needing_collation,
            'clean_spam_comments': stats.spam_comments,
            'clean_trashed_comments': stats.trashed_comments,
            'clean_trashed_posts': stats.trashed_posts,
            'clean_auto_drafts': stats.auto_drafts,
            'clean_orphaned_postmeta': stats.orphaned_postmeta,
            'clean_orphaned_commentmeta': stats.orphaned_commentmeta,
            'clean_oembed_cache': stats.oembed_cache,
            'limit_revisions': stats.excess_revisions,
            'delete_all_revisions': stats.revisions,
            'clean_orphaned_termmeta': stats.orphaned_termmeta,
            'clean_orphaned_relationships': stats.orphaned_relationships,
            'add_postmeta_index': null,
            'add_usermeta_index': null,
            'add_commentmeta_index': null,
            'add_termmeta_index': null,
            'add_postmeta_composite_index': null,
        };
        return mapping.hasOwnProperty(optKey) ? mapping[optKey] : null;
    }
    
    /**
     * Run selected optimization tasks progressively (one at a time with live updates)
     */
    async function runSelectedOptimizations() {
        const optionsContainer = $('#optimization-options');
        const resultsBox = $('#optimization-results');
        const runButton = $('#run-optimizations');
        
        if (!optionsContainer || !resultsBox) return;
        
        // Get selected options with their labels
        const selected = [];
        optionsContainer.querySelectorAll('input[type="checkbox"]:checked').forEach(cb => {
            const label = optionsContainer.querySelector(`label[for="${cb.id}"]`);
            selected.push({
                key: cb.value,
                label: label ? label.textContent : cb.value.replace(/_/g, ' ')
            });
        });
        
        if (selected.length === 0) {
            showNotification('Please select at least one optimization option', 'warning');
            return;
        }
        
        // Check for high-risk options and confirm
        const highRiskSelected = selected.filter(opt => {
            const checkbox = optionsContainer.querySelector(`#opt-${opt.key}`);
            return checkbox && checkbox.closest('.ccm-opt-group.high');
        });
        
        if (highRiskSelected.length > 0) {
            const highRiskNames = highRiskSelected.map(o => o.label).join('\n• ');
            if (!confirm('⚠️ You have selected high-risk operations that cannot be undone. Are you sure you want to continue?\n\nSelected high-risk options:\n• ' + highRiskNames)) {
                return;
            }
        }
        
        // Disable UI during processing
        if (runButton) runButton.disabled = true;
        optionsContainer.querySelectorAll('input[type="checkbox"]').forEach(cb => cb.disabled = true);
        
        // Identify table-intensive tasks that need per-table progressive processing
        const TABLE_TASKS = new Set(['optimize_tables', 'update_collation', 'convert_innodb']);
        const tableTasks = selected.filter(t => TABLE_TASKS.has(t.key));
        const regularTasks = selected.filter(t => !TABLE_TASKS.has(t.key));
        
        // Build initial results table
        resultsBox.style.display = 'block';
        let tableHtml = `
            <div class="ccm-optimization-progress">
                <p><span class="ccm-icon ccm-info">⏳</span> <strong>Running optimizations...</strong> <span id="opt-progress-text">0/${selected.length} completed</span></p>
                <div class="ccm-progress-bar"><div class="ccm-progress-fill" id="opt-progress-bar" style="width: 0%"></div></div>
            </div>
            <table class="ccm-table"><thead><tr><th>Task</th><th>Status</th><th>Result</th><th>Items</th></tr></thead><tbody id="opt-results-body">
        `;
        
        // Add pending rows for each task
        for (const task of selected) {
            tableHtml += `
                <tr id="opt-row-${task.key}">
                    <td>${escapeHtml(task.label)}</td>
                    <td><span class="ccm-status-pending">⏳ Pending</span></td>
                    <td>-</td>
                    <td>-</td>
                </tr>
            `;
        }
        
        tableHtml += '</tbody></table>';
        resultsBox.innerHTML = tableHtml;
        
        // Process tasks one by one
        let completed = 0;
        let successCount = 0;
        let totalItems = 0;
        
        // Helper: process a regular (non-table) task
        async function processRegularTask(task) {
            const row = $(`#opt-row-${task.key}`);
            if (row) {
                row.innerHTML = `
                    <td>${escapeHtml(task.label)}</td>
                    <td><span class="ccm-status-running"><div class="ccm-spinner ccm-spinner-small"></div> Running</span></td>
                    <td>-</td>
                    <td>-</td>
                `;
            }
            
            try {
                const response = await ajax('ccm_tools_run_single_optimization', { task: task.key }, { timeout: 120000 });
                
                completed++;
                const result = response?.data || {};
                const success = result.success;
                const message = result.message || (success ? 'Completed' : 'Failed');
                const count = result.count !== undefined ? result.count : '-';
                
                if (success) {
                    successCount++;
                    if (typeof count === 'number') {
                        totalItems += count;
                    }
                }
                
                if (row) {
                    const statusIcon = success ? '✓' : '✗';
                    const statusClass = success ? 'success' : 'error';
                    row.innerHTML = `
                        <td>${escapeHtml(task.label)}</td>
                        <td><span class="ccm-status-${statusClass}"><span class="ccm-icon ccm-${statusClass}">${statusIcon}</span> ${success ? 'Done' : 'Failed'}</span></td>
                        <td>${escapeHtml(message)}</td>
                        <td>${count}</td>
                    `;
                }
                
            } catch (error) {
                completed++;
                
                if (row) {
                    row.innerHTML = `
                        <td>${escapeHtml(task.label)}</td>
                        <td><span class="ccm-status-error"><span class="ccm-icon ccm-error">✗</span> Error</span></td>
                        <td>${escapeHtml(error.message)}</td>
                        <td>-</td>
                    `;
                }
            }
            
            // Update progress bar
            const progressPercent = Math.round((completed / selected.length) * 100);
            const progressBar = $('#opt-progress-bar');
            const progressText = $('#opt-progress-text');
            if (progressBar) progressBar.style.width = `${progressPercent}%`;
            if (progressText) progressText.textContent = `${completed}/${selected.length} completed`;
        }
        
        // Helper: process table-intensive tasks progressively (table by table)
        async function processTableTasks(tasks) {
            if (tasks.length === 0) return;
            
            const doOptimize = tasks.some(t => t.key === 'optimize_tables');
            const doCollation = tasks.some(t => t.key === 'update_collation');
            const doEngine = tasks.some(t => t.key === 'convert_innodb');
            
            // Mark all table tasks as running
            for (const task of tasks) {
                const row = $(`#opt-row-${task.key}`);
                if (row) {
                    row.innerHTML = `
                        <td>${escapeHtml(task.label)}</td>
                        <td><span class="ccm-status-running"><div class="ccm-spinner ccm-spinner-small"></div> Running</span></td>
                        <td>-</td>
                        <td>-</td>
                    `;
                }
            }
            
            // Get table list
            let tables = [];
            try {
                const tablesResponse = await ajax('ccm_tools_get_tables_to_optimize', {
                    optimize: doOptimize ? '1' : '',
                    collation: doCollation ? '1' : '',
                    engine: doEngine ? '1' : ''
                }, { timeout: 30000 });
                tables = tablesResponse?.data?.tables || [];
            } catch (e) {
                for (const task of tasks) {
                    completed++;
                    const row = $(`#opt-row-${task.key}`);
                    if (row) {
                        row.innerHTML = `
                            <td>${escapeHtml(task.label)}</td>
                            <td><span class="ccm-status-error"><span class="ccm-icon ccm-error">✗</span> Error</span></td>
                            <td>Could not retrieve tables list</td>
                            <td>-</td>
                        `;
                    }
                }
                return;
            }
            
            if (tables.length === 0) {
                for (const task of tasks) {
                    completed++;
                    successCount++;
                    const row = $(`#opt-row-${task.key}`);
                    if (row) {
                        row.innerHTML = `
                            <td>${escapeHtml(task.label)}</td>
                            <td><span class="ccm-status-success"><span class="ccm-icon ccm-success">✓</span> Done</span></td>
                            <td>No tables found</td>
                            <td>0</td>
                        `;
                    }
                }
                return;
            }
            
            // Insert a sub-progress row after the first table task row
            const firstRow = $(`#opt-row-${tasks[0].key}`);
            const subProgressId = 'opt-table-progress';
            if (firstRow) {
                firstRow.insertAdjacentHTML('afterend', `
                    <tr id="${subProgressId}">
                        <td colspan="4" class="ccm-subtask-progress">
                            <div class="ccm-subtask-info">
                                <span id="opt-table-current">Preparing...</span>
                                <span id="opt-table-counter">0/${tables.length}</span>
                            </div>
                            <div class="ccm-progress-bar ccm-progress-bar-sm"><div class="ccm-progress-fill" id="opt-table-bar" style="width: 0%"></div></div>
                        </td>
                    </tr>
                `);
            }
            
            // Process each table
            let tablesDone = 0;
            let tablesSuccess = 0;
            let tablesFailed = 0;
            
            for (const tableName of tables) {
                // Update current table display
                const currentEl = $(`#opt-table-current`);
                if (currentEl) currentEl.textContent = tableName;
                
                try {
                    const resp = await ajax('ccm_tools_optimize_table_task', {
                        table_name: tableName,
                        optimize: doOptimize ? '1' : '',
                        collation: doCollation ? '1' : '',
                        engine: doEngine ? '1' : ''
                    }, { timeout: 60000 });
                    
                    const r = resp?.data || {};
                    if (r.success) {
                        tablesSuccess++;
                    } else {
                        tablesFailed++;
                    }
                } catch (e) {
                    tablesFailed++;
                }
                
                tablesDone++;
                const pct = Math.round((tablesDone / tables.length) * 100);
                const counterEl = $(`#opt-table-counter`);
                const barEl = $(`#opt-table-bar`);
                if (counterEl) counterEl.textContent = `${tablesDone}/${tables.length}`;
                if (barEl) barEl.style.width = `${pct}%`;
            }
            
            // Remove sub-progress row
            const subRow = $(`#${subProgressId}`);
            if (subRow) subRow.remove();
            
            // Mark table tasks as complete
            for (const task of tasks) {
                completed++;
                const row = $(`#opt-row-${task.key}`);
                const allOk = tablesFailed === 0;
                
                if (allOk) successCount++;
                if (task.key === 'optimize_tables' && doOptimize) {
                    totalItems += tablesSuccess;
                }
                
                const statusIcon = allOk ? '✓' : '⚠';
                const statusClass = allOk ? 'success' : 'warning';
                let msg;
                if (task.key === 'optimize_tables') {
                    msg = `${tablesSuccess} tables optimized` + (tablesFailed > 0 ? `, ${tablesFailed} failed` : '');
                } else if (task.key === 'convert_innodb') {
                    msg = `${tablesSuccess} tables converted to InnoDB` + (tablesFailed > 0 ? `, ${tablesFailed} failed` : '');
                } else {
                    msg = `${tablesSuccess} tables processed` + (tablesFailed > 0 ? `, ${tablesFailed} failed` : '');
                }
                
                if (row) {
                    row.innerHTML = `
                        <td>${escapeHtml(task.label)}</td>
                        <td><span class="ccm-status-${statusClass}"><span class="ccm-icon ccm-${statusClass}">${statusIcon}</span> Done</span></td>
                        <td>${escapeHtml(msg)}</td>
                        <td>${tablesSuccess}</td>
                    `;
                }
                
                // Update main progress
                const progressPercent = Math.round((completed / selected.length) * 100);
                const progressBar = $('#opt-progress-bar');
                const progressText = $('#opt-progress-text');
                if (progressBar) progressBar.style.width = `${progressPercent}%`;
                if (progressText) progressText.textContent = `${completed}/${selected.length} completed`;
            }
        }
        
        // Run regular tasks first, then table tasks
        for (const task of regularTasks) {
            await processRegularTask(task);
        }
        
        // Run table-intensive tasks progressively
        if (tableTasks.length > 0) {
            await processTableTasks(tableTasks);
        }
        
        // Update header with final status
        const progressDiv = $('.ccm-optimization-progress');
        if (progressDiv) {
            const allSuccess = successCount === selected.length;
            const icon = allSuccess ? '✓' : '⚠';
            const iconClass = allSuccess ? 'success' : 'warning';
            progressDiv.innerHTML = `
                <p><span class="ccm-icon ccm-${iconClass}">${icon}</span> <strong>Optimization Complete:</strong> ${successCount}/${selected.length} tasks successful, ${totalItems} items processed</p>
                <div class="ccm-progress-bar"><div class="ccm-progress-fill ccm-progress-${iconClass}" style="width: 100%"></div></div>
            `;
        }
        
        showNotification(`Database optimization completed! ${successCount}/${selected.length} tasks successful.`, successCount === selected.length ? 'success' : 'warning');
        
        // Re-enable UI
        if (runButton) runButton.disabled = false;
        optionsContainer.querySelectorAll('input[type="checkbox"]').forEach(cb => cb.disabled = false);
        
        // Refresh stats after a short delay. Use loadOptimizationOptions(), not
        // initOptimizationOptions() — the latter would rebind the run/select
        // buttons on top of their existing listeners, so a second click would
        // start two concurrent runs, a third click three, and so on.
        setTimeout(() => loadOptimizationOptions(), 1000);
    }

    /**
     * Initialize .htaccess options and event handlers
     */
    function initHtaccessOptions() {
        // Add change listeners to htaccess checkboxes for dynamic status updates
        const optionsContainer = document.querySelector('#htaccess-options');
        if (optionsContainer) {
            optionsContainer.addEventListener('change', (e) => {
                if (e.target.matches('input[name="htaccess_options[]"]')) {
                    updateHtaccessOptionStatus(e.target);
                }
            });
        }
        
        // .htaccess Tools (using event delegation)
        document.addEventListener('click', async (e) => {
            // Add htaccess
            if (e.target.id === 'htadd' || e.target.closest('#htadd')) {
                e.preventDefault();
                showConfirmModal(
                    'Add selected optimizations to your .htaccess file?',
                    () => {
                        const options = getSelectedHtaccessOptions();
                        makeAjaxRequest('ccm_tools_add_htaccess', null, { options: options });
                    },
                    'Add Optimizations'
                );
            }
            
            // Update htaccess
            if (e.target.id === 'htupdate' || e.target.closest('#htupdate')) {
                e.preventDefault();
                showConfirmModal(
                    'Update .htaccess with your selected options?',
                    () => {
                        const options = getSelectedHtaccessOptions();
                        makeAjaxRequest('ccm_tools_update_htaccess', null, { options: options });
                    },
                    'Update'
                );
            }
            
            // Remove htaccess
            if (e.target.id === 'htremove' || e.target.closest('#htremove')) {
                e.preventDefault();
                showConfirmModal(
                    'Remove all CCM optimizations from .htaccess?',
                    () => {
                        makeAjaxRequest('ccm_tools_remove_htaccess');
                    },
                    'Remove'
                );
            }
        });
    }
    
    /**
     * Update the status indicator for a htaccess option based on checkbox state
     * @param {HTMLInputElement} checkbox - The checkbox element
     */
    function updateHtaccessOptionStatus(checkbox) {
        const optItem = checkbox.closest('.ccm-opt-item');
        if (!optItem) return;
        
        const statusEl = optItem.querySelector('.ccm-opt-item-status');
        if (!statusEl) return;
        
        const isApplied = optItem.dataset.applied === '1';
        const hasOptimizations = optItem.dataset.hasOptimizations === '1';
        const isChecked = checkbox.checked;
        
        let statusClass, statusIcon, statusText;
        
        if (hasOptimizations) {
            // Optimizations exist in .htaccess
            if (isApplied && isChecked) {
                // Currently applied and staying applied
                statusClass = 'ccm-status-applied';
                statusIcon = '✓';
                statusText = 'Applied';
            } else if (isApplied && !isChecked) {
                // Currently applied but will be removed
                statusClass = 'ccm-status-will-remove';
                statusIcon = '−';
                statusText = 'Will be removed';
            } else if (!isApplied && isChecked) {
                // Not applied but will be added
                statusClass = 'ccm-status-pending';
                statusIcon = '+';
                statusText = 'Will be applied';
            } else {
                // Not applied and staying not applied
                statusClass = 'ccm-status-not-applied';
                statusIcon = '○';
                statusText = 'Not applied';
            }
        } else {
            // No optimizations yet (fresh install)
            if (isChecked) {
                statusClass = 'ccm-status-pending';
                statusIcon = '○';
                statusText = 'Will be applied';
            } else {
                statusClass = 'ccm-status-not-applied';
                statusIcon = '○';
                statusText = 'Will not be applied';
            }
        }
        
        // Update classes
        optItem.classList.remove('ccm-status-applied', 'ccm-status-not-applied', 'ccm-status-pending', 'ccm-status-will-remove');
        optItem.classList.add(statusClass);
        statusEl.classList.remove('ccm-status-applied', 'ccm-status-not-applied', 'ccm-status-pending', 'ccm-status-will-remove');
        statusEl.classList.add(statusClass);
        
        // Update content
        statusEl.innerHTML = statusIcon + ' <small>' + statusText + '</small>';
    }
    
    /**
     * Get selected htaccess options from checkboxes
     */
    function getSelectedHtaccessOptions() {
        const options = [];
        const checkboxes = document.querySelectorAll('#htaccess-options input[name="htaccess_options[]"]:checked');
        checkboxes.forEach(cb => {
            options.push(cb.value);
        });
        return options;
    }

    // ===================================
    // Event Handlers Setup
    // ===================================

    /**
     * Initialize all event handlers
     */
    function initEventHandlers() {
        // Initialize optimization options if on database page
        initOptimizationOptions();
        
        // Initialize htaccess options
        initHtaccessOptions();
        
        // Debug mode toggles
        initDebugToggles();
        
        // Redis controls
        initRedisControls();
        
        // Memory limit
        initMemoryLimit();
        
        // TTFB refresh
        initTTFBRefresh();
        
        // WooCommerce controls
        initWooCommerceControls();
        
        // Error log controls
        initErrorLogControls();
    }

    /**
     * Initialize debug mode toggles
     */
    function initDebugToggles() {
        const debugToggle = $('#toggle-debug');
        const debugLogToggle = $('#toggle-debug-log');
        const debugDisplayToggle = $('#toggle-debug-display');
        
        if (debugToggle) {
            debugToggle.addEventListener('click', async () => {
                const isEnabled = debugToggle.dataset.enabled === 'true';
                
                if (!isEnabled && !confirm(ccmToolsData.i18n.confirmEnableDebug)) {
                    return;
                }
                
                debugToggle.disabled = true;
                const resultBox = $('#resultBox');
                if (resultBox) {
                    resultBox.innerHTML = '<div class="ccm-spinner"></div><p class="ccm-info">Updating debug settings in wp-config.php...</p>';
                }
                
                try {
                    const response = await ajax('ccm_tools_update_debug_mode', { enable: !isEnabled });
                    showNotification(response.data.message, 'success');
                    setTimeout(() => {
                        // Replace (not append) the nocache param — otherwise it
                        // accumulates on every toggle in this session.
                        const nocacheUrl = new URL(window.location.href);
                        nocacheUrl.hash = '';
                        nocacheUrl.searchParams.set('nocache', Date.now());
                        window.location.href = nocacheUrl.toString();
                    }, 1000);
                } catch (error) {
                    if (resultBox) {
                        resultBox.innerHTML = `<p class="ccm-error"><span class="ccm-icon">✗</span>${escapeHtml(error.message)}</p>`;
                    }
                    showNotification(error.message, 'error');
                    debugToggle.disabled = false;
                }
            });
        }
        
        if (debugLogToggle) {
            debugLogToggle.addEventListener('click', async () => {
                const isEnabled = debugLogToggle.dataset.enabled === 'true';
                debugLogToggle.disabled = true;
                
                const resultBox = $('#resultBox');
                if (resultBox) {
                    resultBox.innerHTML = '<div class="ccm-spinner"></div><p class="ccm-info">Updating debug log settings in wp-config.php...</p>';
                }
                
                try {
                    const response = await ajax('ccm_tools_update_debug_log', { enable: !isEnabled });
                    showNotification(response.data.message, 'success');
                    setTimeout(() => {
                        // Replace (not append) the nocache param — otherwise it
                        // accumulates on every toggle in this session.
                        const nocacheUrl = new URL(window.location.href);
                        nocacheUrl.hash = '';
                        nocacheUrl.searchParams.set('nocache', Date.now());
                        window.location.href = nocacheUrl.toString();
                    }, 1000);
                } catch (error) {
                    if (resultBox) {
                        resultBox.innerHTML = `<p class="ccm-error"><span class="ccm-icon">✗</span>${escapeHtml(error.message)}</p>`;
                    }
                    showNotification(error.message, 'error');
                    debugLogToggle.disabled = false;
                }
            });
        }
        
        if (debugDisplayToggle) {
            debugDisplayToggle.addEventListener('click', async () => {
                const isEnabled = debugDisplayToggle.dataset.enabled === 'true';
                
                if (!isEnabled && !confirm(ccmToolsData.i18n.confirmEnableDebugDisplay)) {
                    return;
                }
                
                debugDisplayToggle.disabled = true;
                
                const resultBox = $('#resultBox');
                if (resultBox) {
                    resultBox.innerHTML = '<div class="ccm-spinner"></div><p class="ccm-info">Updating debug display settings in wp-config.php...</p>';
                }
                
                try {
                    const response = await ajax('ccm_tools_update_debug_display', { enable: !isEnabled });
                    showNotification(response.data.message, 'success');
                    setTimeout(() => {
                        // Replace (not append) the nocache param — otherwise it
                        // accumulates on every toggle in this session.
                        const nocacheUrl = new URL(window.location.href);
                        nocacheUrl.hash = '';
                        nocacheUrl.searchParams.set('nocache', Date.now());
                        window.location.href = nocacheUrl.toString();
                    }, 1000);
                } catch (error) {
                    if (resultBox) {
                        resultBox.innerHTML = `<p class="ccm-error"><span class="ccm-icon">✗</span>${escapeHtml(error.message)}</p>`;
                    }
                    showNotification(error.message, 'error');
                    debugDisplayToggle.disabled = false;
                }
            });
        }
    }

    /**
     * Initialize Redis controls
     */
    function initRedisControls() {
        const configureRedis = $('#configure-redis');
        const installRedisPlugin = $('#install-redis-plugin');
        const enableRedisCache = $('#enable-redis-cache');
        const disableRedisCache = $('#disable-redis-cache');
        const showRedisConfig = $('#show-redis-config');
        
        if (configureRedis) {
            configureRedis.addEventListener('click', async () => {
                if (!confirm(ccmToolsData.i18n.confirmRedisConfig)) return;
                
                configureRedis.disabled = true;
                configureRedis.textContent = 'Configuring...';
                
                try {
                    const response = await ajax('ccm_tools_configure_redis');
                    showNotification(response.data.message, 'success');
                    setTimeout(() => location.reload(), 1500);
                } catch (error) {
                    showNotification(error.message, 'error');
                    configureRedis.disabled = false;
                    configureRedis.textContent = 'Add to wp-config.php';
                }
            });
        }
        
        if (installRedisPlugin) {
            installRedisPlugin.addEventListener('click', async () => {
                installRedisPlugin.disabled = true;
                installRedisPlugin.textContent = ccmToolsData.i18n.installing;
                
                try {
                    const response = await ajax('ccm_tools_install_redis_plugin');
                    showNotification(response.data.message, 'success');
                    setTimeout(() => location.reload(), 1500);
                } catch (error) {
                    showNotification(ccmToolsData.i18n.installFailed + ' ' + error.message, 'error');
                    installRedisPlugin.disabled = false;
                    installRedisPlugin.textContent = ccmToolsData.i18n.installRedis;
                }
            });
        }
        
        if (enableRedisCache) {
            enableRedisCache.addEventListener('click', async () => {
                enableRedisCache.disabled = true;
                enableRedisCache.textContent = ccmToolsData.i18n.enabling;
                
                try {
                    const response = await ajax('ccm_tools_enable_redis_cache');
                    showNotification(response.data.message, 'success');
                    setTimeout(() => location.reload(), 1500);
                } catch (error) {
                    showNotification(ccmToolsData.i18n.enableFailed + ' ' + error.message, 'error');
                    enableRedisCache.disabled = false;
                    enableRedisCache.textContent = ccmToolsData.i18n.enableRedis;
                }
            });
        }
        
        if (disableRedisCache) {
            disableRedisCache.addEventListener('click', async () => {
                disableRedisCache.disabled = true;
                disableRedisCache.textContent = ccmToolsData.i18n.disabling;
                
                try {
                    const response = await ajax('ccm_tools_disable_redis_cache');
                    showNotification(response.data.message, 'success');
                    setTimeout(() => location.reload(), 1500);
                } catch (error) {
                    showNotification(ccmToolsData.i18n.disableFailed + ' ' + error.message, 'error');
                    disableRedisCache.disabled = false;
                    disableRedisCache.textContent = ccmToolsData.i18n.disableRedis;
                }
            });
        }
        
        if (showRedisConfig) {
            showRedisConfig.addEventListener('click', () => {
                const configDetails = $('#redis-config-details');
                const isShown = showRedisConfig.dataset.shown === 'true';
                
                if (configDetails) {
                    configDetails.style.display = isShown ? 'none' : 'block';
                }
                
                showRedisConfig.dataset.shown = isShown ? 'false' : 'true';
                showRedisConfig.textContent = isShown ? ccmToolsData.i18n.showConfig : ccmToolsData.i18n.hideConfig;
            });
        }
    }

    /**
     * Initialize memory limit control
     */
    function initMemoryLimit() {
        const updateMemoryLimit = $('#update-memory-limit');
        
        if (updateMemoryLimit) {
            updateMemoryLimit.addEventListener('click', () => {
                const memorySelect = $('#memory-limit');
                if (!memorySelect) return;

                const newLimit = memorySelect.value;

                showConfirmModal(
                    'This will update the PHP memory limit in wp-config.php. Continue?',
                    async () => {
                        updateMemoryLimit.disabled = true;
                        updateMemoryLimit.textContent = 'Updating...';

                        try {
                            // Note: PHP expects 'limit' not 'memory_limit'
                            const response = await ajax('ccm_tools_update_memory_limit', { limit: newLimit });
                            showNotification(response.data.message, 'success');
                            if (response.data.reload !== false) {
                                setTimeout(() => location.reload(), 1500);
                            } else {
                                updateMemoryLimit.disabled = false;
                                updateMemoryLimit.textContent = 'Update';
                            }
                        } catch (error) {
                            showNotification(error.message, 'error');
                            updateMemoryLimit.disabled = false;
                            updateMemoryLimit.textContent = 'Update';
                        }
                    },
                    'Update Memory Limit'
                );
            });
        }
    }

    /**
     * Initialize TTFB refresh and auto-load
     */
    function initTTFBRefresh() {
        const refreshTTFB = $('#refresh-ttfb');
        const ttfbResult = $('#ttfb-result');
        
        /**
         * Load TTFB measurement via AJAX
         */
        async function loadTTFB() {
            if (!ttfbResult) return;
            
            if (refreshTTFB) refreshTTFB.disabled = true;
            ttfbResult.innerHTML = `<div class="ccm-spinner ccm-spinner-small"></div> <span class="ccm-text-muted">${ccmToolsData.i18n.measuring || 'Measuring...'}</span>`;
            
            try {
                const response = await ajax('ccm_tools_measure_ttfb');
                const data = response.data;
                
                if (data.time) {
                    let ttfbClass = 'ccm-success';
                    let ttfbLabel = 'Fast';
                    
                    if (data.time > 1800) {
                        ttfbClass = 'ccm-error';
                        ttfbLabel = 'Slow';
                    } else if (data.time > 800) {
                        ttfbClass = 'ccm-warning';
                        ttfbLabel = 'Average';
                    }
                    
                    ttfbResult.innerHTML = `
                        <span class="${ttfbClass}">${data.time} ${data.unit || 'ms'}</span>
                        <span class="ccm-note">(${ttfbLabel})</span>
                    `;
                    
                    if (data.measurement_note) {
                        ttfbResult.innerHTML += `<br><small class="ccm-note">${escapeHtml(data.measurement_note)}</small>`;
                    }
                } else {
                    ttfbResult.innerHTML = `<span class="ccm-error">${ccmToolsData.i18n.measurementFailed || 'Measurement failed'}</span>`;
                }
            } catch (error) {
                ttfbResult.innerHTML = `<span class="ccm-error">${ccmToolsData.i18n.measurementFailed || 'Measurement failed'}: ${escapeHtml(error.message)}</span>`;
            }
            
            if (refreshTTFB) refreshTTFB.disabled = false;
        }
        
        // Auto-load TTFB on page load if element has data-auto-load attribute
        if (ttfbResult && ttfbResult.dataset.autoLoad === 'true') {
            // Defer the measurement to allow page to render first
            setTimeout(loadTTFB, 100);
        }
        
        // Refresh button click handler
        if (refreshTTFB) {
            refreshTTFB.addEventListener('click', loadTTFB);
        }
    }

    /**
     * Initialize WooCommerce controls
     */
    function initWooCommerceControls() {
        const toggleAdminPayment = $('#toggle-admin-payment');
        
        if (toggleAdminPayment) {
            toggleAdminPayment.addEventListener('click', async () => {
                const isEnabled = toggleAdminPayment.dataset.enabled === 'true';
                toggleAdminPayment.disabled = true;
                toggleAdminPayment.textContent = isEnabled ? ccmToolsData.i18n.disabling : ccmToolsData.i18n.enabling;
                
                const resultBox = $('#woocommerce-result');
                
                try {
                    const response = await ajax('ccm_tools_toggle_admin_payment', { enable: !isEnabled });
                    
                    if (resultBox) {
                        resultBox.innerHTML = `<p class="ccm-success"><span class="ccm-icon">✓</span>${escapeHtml(response.data.message)}</p>`;
                    }
                    
                    showNotification(response.data.message, 'success');
                    setTimeout(() => location.reload(), 1500);
                } catch (error) {
                    if (resultBox) {
                        resultBox.innerHTML = `<p class="ccm-error"><span class="ccm-icon">✗</span>${ccmToolsData.i18n.wooToggleFailed || 'Failed to update setting'}</p>`;
                    }
                    showNotification(error.message, 'error');
                    toggleAdminPayment.disabled = false;
                    toggleAdminPayment.textContent = isEnabled ? 'Disable' : 'Enable';
                }
            });
        }
    }

    /**
     * Initialize Error Log controls
     */
    function initErrorLogControls() {
        // Log file selector
        const logFileSelect = $('#log-file-select');
        if (logFileSelect) {
            logFileSelect.addEventListener('change', (e) => {
                const logFile = e.target.value;
                if (logFile) {
                    loadErrorLog(logFile);
                    // Restart auto-refresh with new file
                    startAutoRefresh(30, () => loadErrorLog(logFile));
                }
            });
            
            // Start auto-refresh on page load
            const initialLogFile = logFileSelect.value;
            if (initialLogFile) {
                startAutoRefresh(30, () => loadErrorLog(initialLogFile));
            }
        }
        
        // Highlight errors toggle
        const highlightErrors = $('#highlight-errors');
        if (highlightErrors) {
            highlightErrors.addEventListener('change', () => {
                const logViewer = $('#error-log-content');
                if (logViewer) {
                    if (highlightErrors.checked) {
                        logViewer.classList.add('highlight-enabled');
                    } else {
                        logViewer.classList.remove('highlight-enabled');
                    }
                }
            });
        }
        
        // Errors only toggle
        const showErrorsOnly = $('#show-errors-only');
        if (showErrorsOnly) {
            showErrorsOnly.addEventListener('change', () => {
                const logFileSelect = $('#log-file-select');
                const logFile = logFileSelect?.value;
                if (logFile) {
                    loadErrorLog(logFile);
                }
            });
        }
        
        // Clear log button (using event delegation)
        document.addEventListener('click', async (e) => {
            if (e.target.id === 'clear-log' || e.target.closest('#clear-log')) {
                e.preventDefault();
                
                if (!confirm(ccmToolsData.i18n.confirmClearLog || 'Are you sure you want to clear the log file?')) return;
                
                const logFileSelect = $('#log-file-select');
                const logFile = logFileSelect?.value;
                
                if (!logFile) return;
                
                try {
                    const response = await ajax('ccm_tools_clear_error_log', { log_file: logFile });
                    showNotification(response.data.message, 'success');
                    loadErrorLog(logFile);
                } catch (error) {
                    showNotification(error.message, 'error');
                }
            }
            
            // Download log button
            if (e.target.id === 'download-log' || e.target.closest('#download-log')) {
                e.preventDefault();
                
                const logFileSelect = $('#log-file-select');
                const logFile = logFileSelect?.value;
                
                if (!logFile) return;
                
                try {
                    const response = await ajax('ccm_tools_download_error_log', { log_file: logFile });
                    
                    if (response.data.download_url) {
                        // Server returned a download URL
                        window.location.href = response.data.download_url;
                    } else if (response.data.content) {
                        // Server returned content directly
                        const blob = new Blob([response.data.content], { type: 'text/plain' });
                        const url = URL.createObjectURL(blob);
                        const a = document.createElement('a');
                        a.href = url;
                        a.download = response.data.filename || 'error.log';
                        document.body.appendChild(a);
                        a.click();
                        document.body.removeChild(a);
                        URL.revokeObjectURL(url);
                    }
                } catch (error) {
                    showNotification((ccmToolsData.i18n.downloadFailed || 'Download failed') + ': ' + error.message, 'error');
                }
            }
            
            // Refresh log button
            if (e.target.id === 'refresh-log' || e.target.closest('#refresh-log')) {
                e.preventDefault();
                
                const logFileSelect = $('#log-file-select');
                const logFile = logFileSelect?.value;
                
                if (logFile) {
                    loadErrorLog(logFile);
                    // Reset auto-refresh timer
                    startAutoRefresh(30, () => loadErrorLog(logFile));
                }
            }
        });
    }

    /**
     * Load error log content
     * @param {string} logFile - Log file path
     */
    async function loadErrorLog(logFile) {
        const logViewer = $('.ccm-error-log-viewer');
        if (!logViewer) return;
        
        const showErrorsOnly = $('#show-errors-only')?.checked || false;
        const highlightEnabled = $('#highlight-errors')?.checked !== false; // Default true
        const logLines = $('#log-lines')?.value || 100;
        
        logViewer.innerHTML = '<div class="ccm-spinner"></div>';
        
        try {
            const response = await ajax('ccm_tools_get_error_log', { 
                log_file: logFile,
                errors_only: showErrorsOnly,
                lines: logLines
            });
            
            const data = response.data;
            
            if (data.formatted_content) {
                // Only ever render the server-escaped version. Never fall back
                // to the raw data.content field — that field is unescaped log
                // text and rendering it here would be a stored XSS hole.
                const highlightClass = highlightEnabled ? 'highlight-enabled' : '';
                logViewer.innerHTML = `<pre id="error-log-content" class="${highlightClass}">${data.formatted_content}</pre>`;
            } else if (data.error) {
                logViewer.innerHTML = `<p class="ccm-error">${escapeHtml(data.error)}</p>`;
            } else if (data.content) {
                // The server returned raw content but no safely-formatted version.
                logViewer.innerHTML = `<p class="ccm-error">Unable to safely display log content.</p>`;
            } else {
                logViewer.innerHTML = `
                    <div class="empty-log-message">
                        <span>No Log Entries</span>
                        <p class="empty-log-description">The log file is empty or contains no matching entries.</p>
                    </div>
                `;
            }
            
            // Update log meta info
            const logSize = $('#log-size');
            const logModified = $('#log-modified');
            
            if (logSize && data.file_size) {
                logSize.textContent = data.file_size;
            }
            if (logModified && data.last_modified) {
                logModified.textContent = data.last_modified;
            }
        } catch (error) {
            logViewer.innerHTML = `<p class="ccm-error">Error loading log: ${escapeHtml(error.message)}</p>`;
        }
    }

    // ===================================
    // Auto-refresh Functionality
    // ===================================

    let refreshInterval = null;
    let refreshCountdown = 0;

    /**
     * Start auto-refresh timer
     * @param {number} seconds - Refresh interval in seconds
     * @param {Function} callback - Refresh callback
     */
    function startAutoRefresh(seconds, callback) {
        stopAutoRefresh();
        
        refreshCountdown = seconds;
        
        const updateTimer = () => {
            const countdownEl = $('#refresh-countdown');
            const progressEl = $('.ccm-refresh-progress');
            
            if (countdownEl) {
                countdownEl.textContent = refreshCountdown;
            }
            
            if (progressEl) {
                const percent = ((seconds - refreshCountdown) / seconds) * 100;
                progressEl.style.width = `${percent}%`;
            }
            
            if (refreshCountdown <= 0) {
                if (progressEl) progressEl.classList.add('loading');
                callback();
                refreshCountdown = seconds;
                if (progressEl) {
                    setTimeout(() => progressEl.classList.remove('loading'), 500);
                }
            } else {
                refreshCountdown--;
            }
        };
        
        updateTimer();
        refreshInterval = setInterval(updateTimer, 1000);
    }

    /**
     * Stop auto-refresh timer
     */
    function stopAutoRefresh() {
        if (refreshInterval) {
            clearInterval(refreshInterval);
            refreshInterval = null;
        }
    }

    // ===================================
    // WebP Converter Handlers
    // ===================================

    let webpConversionRunning = false;
    let webpConversionStopped = false;
    let webpStatsRefreshInterval = null;

    /**
     * Refresh WebP conversion statistics
     * Called periodically to keep stats current
     */
    async function refreshWebPStats() {
        try {
            const response = await ajax('ccm_tools_get_webp_stats', {});
            
            if (response && response.data) {
                const data = response.data;
                
                // Update stat values
                const totalEl = $('#stat-total-images');
                const convertedEl = $('#stat-converted-images');
                const pendingEl = $('#stat-pending-images');
                const savingsEl = $('#stat-average-savings');
                const origSizeEl = $('#stat-original-size');
                const webpSizeEl = $('#stat-webp-size');
                const savedSizeEl = $('#stat-saved-size');
                const sizeComparisonEl = $('#stat-size-comparison');
                
                if (totalEl) totalEl.textContent = data.total_images;
                if (convertedEl) convertedEl.textContent = data.converted_images;
                if (pendingEl) {
                    pendingEl.textContent = data.pending_conversion;
                    // Update warning class
                    if (data.pending_conversion > 0) {
                        pendingEl.classList.add('ccm-warning');
                    } else {
                        pendingEl.classList.remove('ccm-warning');
                    }
                }
                if (savingsEl) savingsEl.textContent = data.total_savings + '%';
                
                // Update size comparison section
                if (data.total_original_size > 0) {
                    if (origSizeEl) origSizeEl.textContent = formatBytes(data.total_original_size);
                    if (webpSizeEl) webpSizeEl.textContent = formatBytes(data.total_webp_size);
                    if (savedSizeEl) savedSizeEl.textContent = 'Saved ' + formatBytes(data.total_original_size - data.total_webp_size);
                    if (sizeComparisonEl) sizeComparisonEl.style.display = '';
                } else {
                    if (sizeComparisonEl) sizeComparisonEl.style.display = 'none';
                }
                
                // Update button states and text (only if not currently converting)
                if (!webpConversionRunning) {
                    const startBulkBtn = $('#start-bulk-conversion');
                    const regenerateBtn = $('#regenerate-all-webp');
                    
                    if (startBulkBtn) {
                        startBulkBtn.disabled = data.pending_conversion === 0;
                        startBulkBtn.textContent = `Convert ${data.pending_conversion} Images`;
                    }
                    
                    if (regenerateBtn && !regenerateBtn.disabled) {
                        regenerateBtn.textContent = `Regenerate ${data.converted_images} WebP Images`;
                        if (data.converted_images === 0) {
                            regenerateBtn.disabled = true;
                        }
                    }
                }
            }
        } catch (error) {
            // Silently fail - stats refresh is non-critical
            console.debug('WebP stats refresh failed:', error);
        }
    }

    /**
     * Start WebP stats auto-refresh (every 30 seconds)
     */
    function startWebPStatsRefresh() {
        // Only start if we're on the WebP page
        if (!$('#webp-stats-card')) return;
        
        // Clear any existing interval
        if (webpStatsRefreshInterval) {
            clearInterval(webpStatsRefreshInterval);
        }
        
        // Refresh every 30 seconds (non-invasive)
        webpStatsRefreshInterval = setInterval(refreshWebPStats, 30000);
    }

    /**
     * Initialize WebP converter event handlers
     */
    function initWebPConverterHandlers() {
        // Start stats auto-refresh
        startWebPStatsRefresh();
        
        // Quality range slider
        const qualitySlider = $('#webp-quality');
        const qualityValue = $('#webp-quality-value');
        
        if (qualitySlider && qualityValue) {
            qualitySlider.addEventListener('input', () => {
                qualityValue.textContent = qualitySlider.value;
            });
        }
        
        // Quality presets
        $$('.ccm-quality-preset').forEach(btn => {
            btn.addEventListener('click', () => {
                const quality = btn.dataset.quality;
                if (qualitySlider && qualityValue) {
                    qualitySlider.value = quality;
                    qualityValue.textContent = quality;
                }
            });
        });
        
        // Save settings form
        const settingsForm = $('#webp-settings-form');
        if (settingsForm) {
            settingsForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                await saveWebPSettings();
            });
        }
        
        // Test image upload
        const selectTestBtn = $('#select-test-image');
        const testImageInput = $('#test-image-upload');
        const testImageName = $('#test-image-name');
        const runTestBtn = $('#run-test-conversion');
        
        if (selectTestBtn && testImageInput) {
            selectTestBtn.addEventListener('click', () => {
                testImageInput.click();
            });
            
            testImageInput.addEventListener('change', () => {
                if (testImageInput.files.length > 0) {
                    const file = testImageInput.files[0];
                    if (testImageName) {
                        testImageName.textContent = file.name + ' (' + formatBytes(file.size) + ')';
                    }
                    if (runTestBtn) {
                        runTestBtn.disabled = false;
                    }
                }
            });
        }
        
        // Run test conversion
        if (runTestBtn) {
            runTestBtn.addEventListener('click', async () => {
                await runTestConversion();
            });
        }
        
        // Bulk conversion buttons
        const startBulkBtn = $('#start-bulk-conversion');
        const stopBulkBtn = $('#stop-bulk-conversion');
        const regenerateBtn = $('#regenerate-all-webp');
        
        if (startBulkBtn) {
            startBulkBtn.addEventListener('click', async () => {
                await startBulkConversion();
            });
        }
        
        if (stopBulkBtn) {
            stopBulkBtn.addEventListener('click', () => {
                webpConversionStopped = true;
                stopBulkBtn.disabled = true;
                stopBulkBtn.textContent = ccmToolsData.i18n?.stopping || 'Stopping...';
            });
        }
        
        if (regenerateBtn) {
            regenerateBtn.addEventListener('click', async () => {
                if (!confirm('This will delete all existing WebP images and mark them for re-conversion with the current quality settings.\n\nAre you sure you want to continue?')) {
                    return;
                }
                
                regenerateBtn.disabled = true;
                regenerateBtn.innerHTML = '<div class="ccm-spinner ccm-spinner-small"></div> Resetting...';
                
                try {
                    const response = await ajax('ccm_tools_reset_webp_conversions', { delete_files: '1' });
                    
                    showNotification(response.message || 'WebP images reset successfully. You can now re-convert them.', 'success');
                    
                    // Reload page to update stats
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);
                } catch (error) {
                    showNotification(error.message || 'Failed to reset WebP conversions.', 'error');
                    regenerateBtn.disabled = false;
                    regenerateBtn.textContent = 'Regenerate WebP Images';
                }
            });
        }
        
        // Export WebP settings
        const exportWebPBtn = $('#export-webp-settings');
        if (exportWebPBtn) {
            exportWebPBtn.addEventListener('click', async () => {
                exportWebPBtn.disabled = true;
                exportWebPBtn.innerHTML = '<div class="ccm-spinner ccm-spinner-small"></div> Exporting...';
                
                try {
                    const response = await ajax('ccm_tools_export_webp_settings', {});
                    
                    // Create blob and download
                    const blob = new Blob([JSON.stringify(response, null, 2)], { type: 'application/json' });
                    const url = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    const siteName = window.location.hostname.replace(/[^a-z0-9]/gi, '-');
                    const date = new Date().toISOString().split('T')[0];
                    a.href = url;
                    a.download = `ccm-tools-webp-settings-${siteName}-${date}.json`;
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    URL.revokeObjectURL(url);
                    
                    showNotification('WebP settings exported successfully!', 'success');
                } catch (error) {
                    showNotification('Export failed: ' + error.message, 'error');
                } finally {
                    exportWebPBtn.disabled = false;
                    exportWebPBtn.innerHTML = '📥 Export Settings';
                }
            });
        }
        
        // Import WebP settings
        const importWebPBtn = $('#import-webp-settings-btn');
        const importWebPFile = $('#import-webp-settings-file');
        const importWebPFileName = $('#import-webp-file-name');
        const importWebPAction = $('#import-webp-settings');
        
        if (importWebPBtn && importWebPFile) {
            importWebPBtn.addEventListener('click', () => {
                importWebPFile.click();
            });
            
            importWebPFile.addEventListener('change', () => {
                if (importWebPFile.files.length > 0) {
                    const file = importWebPFile.files[0];
                    if (importWebPFileName) {
                        importWebPFileName.textContent = file.name;
                    }
                    if (importWebPAction) {
                        importWebPAction.style.display = 'inline-block';
                    }
                }
            });
        }
        
        if (importWebPAction) {
            importWebPAction.addEventListener('click', async () => {
                if (!importWebPFile?.files?.length) {
                    showNotification('Please select a file first', 'warning');
                    return;
                }
                
                const file = importWebPFile.files[0];
                const reader = new FileReader();
                
                reader.onload = async (e) => {
                    try {
                        // Validate JSON first
                        const jsonData = JSON.parse(e.target.result);
                        
                        // Show confirmation
                        const sourceInfo = jsonData.site_url ? `from ${jsonData.site_url}` : '';
                        const dateInfo = jsonData.exported_at ? ` (exported: ${jsonData.exported_at})` : '';
                        
                        if (!confirm(`Import WebP settings ${sourceInfo}${dateInfo}?\n\nThis will replace your current settings.`)) {
                            return;
                        }
                        
                        importWebPAction.disabled = true;
                        importWebPAction.innerHTML = '<div class="ccm-spinner ccm-spinner-small"></div> Importing...';
                        
                        const response = await ajax('ccm_tools_import_webp_settings', {
                            settings_json: e.target.result
                        });
                        
                        showNotification(response.message || 'Settings imported successfully!', 'success');
                        
                        // Reload page to show new settings
                        setTimeout(() => {
                            window.location.reload();
                        }, 1500);
                        
                    } catch (error) {
                        showNotification('Import failed: ' + error.message, 'error');
                        importWebPAction.disabled = false;
                        importWebPAction.textContent = 'Import Settings';
                    }
                };
                
                reader.onerror = () => {
                    showNotification('Failed to read file', 'error');
                };
                
                reader.readAsText(file);
            });
        }
    }

    /**
     * Save WebP settings
     */
    async function saveWebPSettings() {
        const saveBtn = $('#save-webp-settings');
        if (saveBtn) {
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<div class="ccm-spinner ccm-spinner-small"></div> ' + (ccmToolsData.i18n?.saving || 'Saving...');
        }
        
        try {
            const formData = {
                enabled: $('#webp-enabled')?.checked ? '1' : '0',
                quality: $('#webp-quality')?.value || '82',
                convert_on_upload: $('#webp-convert-on-upload')?.checked ? '1' : '0',
                serve_webp: $('#webp-serve')?.checked ? '1' : '0',
                convert_on_demand: $('#webp-convert-on-demand')?.checked ? '1' : '0',
                convert_bg_images: $('#webp-bg-images')?.checked ? '1' : '0',
                keep_originals: $('#webp-keep-originals')?.checked ? '1' : '0',
                preferred_extension: $('#webp-preferred-extension')?.value || 'auto'
            };
            
            const response = await ajax('ccm_tools_save_webp_settings', formData);
            showNotification(response.data?.message || 'Settings saved successfully', 'success');
            
        } catch (error) {
            showNotification('Error: ' + error.message, 'error');
        } finally {
            if (saveBtn) {
                saveBtn.disabled = false;
                saveBtn.innerHTML = ccmToolsData.i18n?.saveSettings || 'Save Settings';
            }
        }
    }

    /**
     * Run test conversion
     */
    async function runTestConversion() {
        const testInput = $('#test-image-upload');
        const runTestBtn = $('#run-test-conversion');
        const resultDiv = $('#test-conversion-result');
        const resultContent = $('#test-result-content');
        
        if (!testInput?.files?.length) {
            showNotification('Please select an image first', 'warning');
            return;
        }
        
        if (runTestBtn) {
            runTestBtn.disabled = true;
            runTestBtn.innerHTML = '<div class="ccm-spinner ccm-spinner-small"></div> ' + (ccmToolsData.i18n?.testing || 'Testing...');
        }
        
        if (resultDiv) {
            resultDiv.style.display = 'block';
        }
        
        if (resultContent) {
            resultContent.innerHTML = '<div class="ccm-spinner"></div>';
        }
        
        try {
            const formData = new FormData();
            formData.append('action', 'ccm_tools_test_webp_conversion');
            formData.append('nonce', ccmToolsData.nonce);
            formData.append('test_image', testInput.files[0]);
            
            const response = await fetch(ccmToolsData.ajax_url, {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            
            if (result.success) {
                resultContent.innerHTML = `
                    <div class="ccm-test-result ccm-success">
                        <h4><span class="ccm-icon">✓</span> Conversion Successful</h4>
                        <table class="ccm-table">
                            <tr><th>Original Size</th><td>${escapeHtml(result.data.source_size)}</td></tr>
                            <tr><th>WebP Size</th><td>${escapeHtml(result.data.dest_size)}</td></tr>
                            <tr><th>Savings</th><td class="ccm-success">${escapeHtml(result.data.savings_percent)}%</td></tr>
                            <tr><th>Extension Used</th><td>${escapeHtml(result.data.extension_used)}</td></tr>
                            <tr><th>Quality Setting</th><td>${escapeHtml(result.data.quality)}</td></tr>
                            <tr><th>Dimensions</th><td>${escapeHtml(result.data.dimensions)}</td></tr>
                        </table>
                    </div>
                `;
            } else {
                resultContent.innerHTML = `
                    <div class="ccm-test-result ccm-error">
                        <h4><span class="ccm-icon">✗</span> Conversion Failed</h4>
                        <p>${escapeHtml(result.data?.message || 'Unknown error')}</p>
                    </div>
                `;
            }
            
        } catch (error) {
            if (resultContent) {
                resultContent.innerHTML = `
                    <div class="ccm-test-result ccm-error">
                        <h4><span class="ccm-icon">✗</span> Error</h4>
                        <p>${escapeHtml(error.message)}</p>
                    </div>
                `;
            }
        } finally {
            if (runTestBtn) {
                runTestBtn.disabled = false;
                runTestBtn.innerHTML = ccmToolsData.i18n?.testConversion || 'Test Conversion';
            }
        }
    }

    /**
     * Start bulk conversion
     */
    async function startBulkConversion() {
        const startBtn = $('#start-bulk-conversion');
        const stopBtn = $('#stop-bulk-conversion');
        const progressDiv = $('#bulk-conversion-progress');
        const progressBar = $('#bulk-progress-bar');
        const currentSpan = $('#bulk-current');
        const totalSpan = $('#bulk-total');
        const logBox = $('#bulk-conversion-log');
        
        webpConversionRunning = true;
        webpConversionStopped = false;
        
        if (startBtn) startBtn.style.display = 'none';
        if (stopBtn) {
            stopBtn.style.display = 'inline-flex';
            stopBtn.disabled = false;
            stopBtn.textContent = ccmToolsData.i18n?.stopConversion || 'Stop Conversion';
        }
        if (progressDiv) progressDiv.style.display = 'block';
        if (logBox) logBox.innerHTML = '';
        
        let offset = 0;
        const batchSize = 5;
        let totalConverted = 0;
        let totalErrors = 0;
        
        try {
            // Get first batch to determine total
            const firstBatch = await ajax('ccm_tools_get_unconverted_images', { offset: 0, limit: batchSize });
            const total = firstBatch.data?.total || 0;
            
            if (totalSpan) totalSpan.textContent = total;
            
            if (total === 0) {
                addLogEntry(logBox, 'No images found to convert.', 'info');
                return;
            }
            
            addLogEntry(logBox, `Starting conversion of ${total} images...`, 'info');
            
            let processedCount = 0;
            
            while (!webpConversionStopped) {
                const batchResponse = await ajax('ccm_tools_get_unconverted_images', { offset: 0, limit: batchSize });
                const images = batchResponse.data?.images || [];
                
                if (images.length === 0) {
                    break;
                }
                
                for (const image of images) {
                    if (webpConversionStopped) break;
                    
                    try {
                        const convertResponse = await ajax('ccm_tools_convert_single_image', { attachment_id: image.id });
                        totalConverted++;
                        addLogEntry(logBox, `✓ ${image.title || 'Image #' + image.id}: ${convertResponse.data?.message || 'Converted'}`, 'success');
                    } catch (error) {
                        totalErrors++;
                        addLogEntry(logBox, `✗ ${image.title || 'Image #' + image.id}: ${error.message}`, 'error');
                    }
                    
                    processedCount++;
                    
                    // Update progress
                    if (currentSpan) currentSpan.textContent = totalConverted + totalErrors;
                    if (progressBar) {
                        const percent = Math.round(((totalConverted + totalErrors) / total) * 100);
                        progressBar.style.width = `${percent}%`;
                    }
                    
                    // Refresh stats every 10 images
                    if (processedCount % 10 === 0) {
                        refreshWebPStats();
                    }
                    
                    // Minimal delay to allow UI updates
                    await new Promise(resolve => setTimeout(resolve, 50));
                }
            }
            
            // Summary
            const summaryType = webpConversionStopped ? 'warning' : 'success';
            const summaryMsg = webpConversionStopped 
                ? `Conversion stopped. Converted: ${totalConverted}, Errors: ${totalErrors}`
                : `Conversion complete! Converted: ${totalConverted}, Errors: ${totalErrors}`;
            addLogEntry(logBox, summaryMsg, summaryType);
            
        } catch (error) {
            addLogEntry(logBox, `Error: ${error.message}`, 'error');
        } finally {
            webpConversionRunning = false;
            if (startBtn) startBtn.style.display = 'inline-flex';
            if (stopBtn) stopBtn.style.display = 'none';
            
            // Refresh stats
            refreshWebPStats();
        }
    }

    /**
     * Add entry to log box
     */
    function addLogEntry(logBox, message, type = 'info') {
        if (!logBox) return;
        
        const entry = document.createElement('div');
        entry.className = `ccm-log-entry ccm-log-${type}`;
        entry.innerHTML = `<span class="ccm-log-time">${new Date().toLocaleTimeString()}</span> ${escapeHtml(message)}`;
        logBox.appendChild(entry);
        logBox.scrollTop = logBox.scrollHeight;
    }

    // ===================================
    // Performance Optimizer Handlers
    // ===================================
    
    /**
     * Initialize performance optimizer event handlers
     */
    function initPerfOptimizerHandlers() {
        // Toggle visibility of setting details when checkboxes change
        const toggleSettings = [
            { checkbox: '#perf-defer-js', detail: '#perf-defer-js' },
            { checkbox: '#perf-delay-js', detail: '#perf-delay-js' },
            { checkbox: '#perf-preload-css', detail: '#perf-preload-css' },
            { checkbox: '#perf-preconnect', detail: '#perf-preconnect' },
            { checkbox: '#perf-dns-prefetch', detail: '#perf-dns-prefetch' },
            { checkbox: '#perf-lcp-preload', detail: '#perf-lcp-preload' },
            // New v7.9.0 settings
            { checkbox: '#perf-critical-css', detail: '#perf-critical-css' },
            { checkbox: '#perf-speculation-rules', detail: '#perf-speculation-rules' },
            { checkbox: '#perf-reduce-heartbeat', detail: '#perf-reduce-heartbeat' },
        ];
        
        toggleSettings.forEach(({ checkbox }) => {
            const el = $(checkbox);
            if (el) {
                el.addEventListener('change', function() {
                    const settingRow = this.closest('.ccm-setting-row');
                    if (settingRow) {
                        const detail = settingRow.querySelector('.ccm-setting-detail');
                        if (detail) {
                            detail.style.display = this.checked ? 'block' : 'none';
                        }
                    }
                });
            }
        });
        
        // Master enable toggle
        const masterEnable = $('#perf-master-enable');
        if (masterEnable) {
            masterEnable.addEventListener('change', function() {
                const statusEl = $('#perf-status');
                if (statusEl) {
                    if (this.checked) {
                        statusEl.className = 'ccm-success';
                        statusEl.textContent = 'Performance optimizations are ACTIVE';
                    } else {
                        statusEl.className = 'ccm-warning';
                        statusEl.textContent = 'Performance optimizations are INACTIVE';
                    }
                }
            });
        }

        // Safe Optimisations: sync safe toggles ↔ advanced toggles
        document.querySelectorAll('.safe-opt-toggle').forEach(safeCb => {
            const targetId = safeCb.getAttribute('data-target');
            const advancedCb = targetId ? document.getElementById(targetId) : null;
            if (!advancedCb) return;

            // Safe → Advanced
            safeCb.addEventListener('change', () => {
                advancedCb.checked = safeCb.checked;
                advancedCb.dispatchEvent(new Event('change', { bubbles: true }));
            });
            // Advanced → Safe
            advancedCb.addEventListener('change', () => {
                safeCb.checked = advancedCb.checked;
            });
        });

        // Enable All Safe / Disable All Safe buttons
        const enableAllSafe = $('#safe-enable-all');
        const disableAllSafe = $('#safe-disable-all');
        if (enableAllSafe) {
            enableAllSafe.addEventListener('click', () => {
                document.querySelectorAll('.safe-opt-toggle').forEach(cb => {
                    if (!cb.checked) {
                        cb.checked = true;
                        cb.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                });
                showNotification('All safe optimisations enabled. Press Save Settings to apply.', 'success');
            });
        }
        if (disableAllSafe) {
            disableAllSafe.addEventListener('click', () => {
                document.querySelectorAll('.safe-opt-toggle').forEach(cb => {
                    if (cb.checked) {
                        cb.checked = false;
                        cb.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                });
                showNotification('All safe optimisations disabled. Press Save Settings to apply.', 'info');
            });
        }
        
        // Save button
        const saveBtn = $('#save-perf-settings');
        if (saveBtn) {
            saveBtn.addEventListener('click', savePerfSettings);
        }
        
        // Detect external origins button (Preconnect)
        const detectBtn = $('#detect-external-origins');
        if (detectBtn) {
            detectBtn.addEventListener('click', () => detectExternalOrigins('preconnect'));
        }
        
        // Detect external origins button (DNS Prefetch)
        const detectDnsBtn = $('#detect-dns-prefetch-origins');
        if (detectDnsBtn) {
            detectDnsBtn.addEventListener('click', () => detectExternalOrigins('dns-prefetch'));
        }
        
        // Detect scripts button (Defer JS)
        const detectScriptsBtn = $('#detect-scripts-btn');
        if (detectScriptsBtn) {
            detectScriptsBtn.addEventListener('click', () => detectScripts('defer'));
        }
        
        // Detect scripts button (Delay JS)
        const detectDelayScriptsBtn = $('#detect-delay-scripts-btn');
        if (detectDelayScriptsBtn) {
            detectDelayScriptsBtn.addEventListener('click', () => detectScripts('delay'));
        }
        
        // Export settings button
        const exportBtn = $('#export-perf-settings');
        if (exportBtn) {
            exportBtn.addEventListener('click', exportPerfSettings);
        }
        
        // Import file input trigger
        const importFileBtn = $('#import-perf-settings-btn');
        const importFileInput = $('#import-perf-file');
        if (importFileBtn && importFileInput) {
            importFileBtn.addEventListener('click', () => importFileInput.click());
            importFileInput.addEventListener('change', handleImportFileSelect);
        }
        
        // Import settings button
        const importBtn = $('#import-perf-settings');
        if (importBtn) {
            importBtn.addEventListener('click', importPerfSettings);
        }
        
        // Toggle settings preview
        const togglePreviewBtn = $('#toggle-settings-preview');
        const settingsPreview = $('#settings-preview');
        if (togglePreviewBtn && settingsPreview) {
            togglePreviewBtn.addEventListener('click', () => {
                settingsPreview.style.display = settingsPreview.style.display === 'none' ? 'block' : 'none';
            });
        }
    }
    
    /**
     * Detect scripts on the homepage and categorize them
     * @param {string} target - 'defer' or 'delay'
     */
    async function detectScripts(target = 'defer') {
        const isDefer = target === 'defer';
        const detectBtn = isDefer ? $('#detect-scripts-btn') : $('#detect-delay-scripts-btn');
        const resultDiv = isDefer ? $('#detected-scripts-result') : $('#detected-delay-scripts-result');
        const excludeInput = isDefer ? $('#perf-defer-js-excludes') : $('#perf-delay-js-excludes');
        const targetLabel = isDefer ? 'Defer' : 'Delay';
        const actionVerb = isDefer ? 'defer' : 'delay';
        
        if (!detectBtn || !resultDiv) return;
        
        // Get current excludes
        const currentExcludes = new Set(
            excludeInput.value.split(',').map(s => s.trim().toLowerCase()).filter(s => s)
        );
        
        // Store original button content
        const originalBtnContent = detectBtn.innerHTML;
        detectBtn.disabled = true;
        detectBtn.innerHTML = '<div class="ccm-spinner ccm-spinner-small"></div> Scanning...';
        
        try {
            const response = await ajax('ccm_tools_detect_scripts', { target: target });
            
            if (!response.data || !response.data.scripts) {
                throw new Error('Invalid response from server');
            }
            
            const { scripts, categorized, stats, site_host } = response.data;
            
            if (stats.total === 0) {
                resultDiv.innerHTML = `
                    <p class="ccm-text-muted">
                        <strong>No scripts detected.</strong><br>
                        Your site doesn't appear to have any external JavaScript files.
                    </p>
                `;
                resultDiv.style.display = 'block';
                showNotification('No scripts found', 'info');
                return;
            }
            
            // Build the results HTML
            let html = `
                <p style="margin-bottom: var(--ccm-space-md);">
                    <strong>Found ${stats.total} script${stats.total !== 1 ? 's' : ''} (${targetLabel} analysis):</strong>
                </p>
                <div style="display: flex; gap: var(--ccm-space-md); flex-wrap: wrap; margin-bottom: var(--ccm-space-md);">
                    <span class="ccm-error">❌ ${stats.should_exclude} to exclude</span>
                    <span class="ccm-success">✓ ${stats.safe_to_defer} safe to ${actionVerb}</span>
                    ${stats.already_deferred > 0 ? `<span class="ccm-info">↻ ${stats.already_deferred} already deferred/async</span>` : ''}
                </div>
            `;
            
            // Category labels and icons - context-aware for defer vs delay
            const categoryLabels = isDefer ? {
                jquery: '⚠️ jQuery (DO NOT defer)',
                wp_core: '⚠️ WordPress Core (DO NOT defer)',
                theme: '🎨 Theme Scripts',
                plugins: '🔌 Plugin Scripts',
                third_party: '🌐 Third-Party Scripts',
                other: '📦 Other Scripts'
            } : {
                jquery: '⚠️ jQuery (DO NOT delay)',
                wp_core: '⚠️ WordPress Core (DO NOT delay)',
                theme: '🎨 Theme Scripts (check for above-the-fold interaction)',
                plugins: '🔌 Plugin Scripts (check for visible UI elements)',
                third_party: '🌐 Third-Party Scripts (ideal delay candidates)',
                other: '📦 Other Scripts'
            };
            
            const categoryOrder = ['jquery', 'wp_core', 'theme', 'plugins', 'third_party', 'other'];
            
            html += '<div style="max-height: 400px; overflow-y: auto;">';
            
            for (const category of categoryOrder) {
                const catScripts = categorized[category];
                if (!catScripts || catScripts.length === 0) continue;
                
                const isSafeCategory = !['jquery', 'wp_core'].includes(category);
                
                html += `
                    <div style="margin-bottom: var(--ccm-space-md); padding: var(--ccm-space-sm); background: var(--ccm-bg); border-radius: var(--ccm-radius);">
                        <strong style="color: ${isSafeCategory ? 'var(--ccm-success)' : 'var(--ccm-error)'};">
                            ${categoryLabels[category]}
                        </strong>
                        <div style="margin-top: var(--ccm-space-xs); font-size: 0.85em;">
                `;
                
                for (const script of catScripts) {
                    const isExcluded = Array.from(currentExcludes).some(exc => 
                        script.src.toLowerCase().includes(exc) || script.handle.toLowerCase().includes(exc)
                    );
                    
                    let statusIcon = '';
                    let labelStyle = 'display: flex; align-items: center; gap: var(--ccm-space-xs); margin-bottom: var(--ccm-space-xs);';
                    
                    if (script.has_defer || script.has_async) {
                        statusIcon = '<span class="ccm-info" title="Already deferred/async">↻</span>';
                    } else if (!script.safe_to_defer) {
                        statusIcon = `<span class="ccm-error" title="Should exclude from ${actionVerb}">❌</span>`;
                    } else if (isExcluded) {
                        statusIcon = '<span class="ccm-warning" title="Currently excluded">⊘</span>';
                    } else {
                        statusIcon = `<span class="ccm-success" title="Safe to ${actionVerb}">✓</span>`;
                    }
                    
                    html += `
                        <div style="${labelStyle}">
                            ${statusIcon}
                            <code style="word-break: break-all;">${escapeHtml(script.handle)}</code>
                            <span class="ccm-text-muted" style="font-size: 0.8em;">${escapeHtml(script.reason)}</span>
                        </div>
                    `;
                }
                
                html += '</div></div>';
            }
            
            html += '</div>';
            
            // Recommended excludes
            const recommendedExcludes = [];
            for (const script of scripts) {
                if (!script.safe_to_defer && !currentExcludes.has(script.handle.toLowerCase())) {
                    recommendedExcludes.push(script.handle);
                }
            }
            
            if (recommendedExcludes.length > 0) {
                // Simplify to patterns
                const patterns = new Set();
                for (const handle of recommendedExcludes) {
                    if (handle.toLowerCase().includes('jquery')) {
                        patterns.add('jquery');
                    } else if (handle.toLowerCase().includes('wp-')) {
                        patterns.add('wp-');
                    } else {
                        patterns.add(handle);
                    }
                }
                
                const patternList = Array.from(patterns).join(', ');
                
                html += `
                    <div style="margin-top: var(--ccm-space-md); padding: var(--ccm-space-sm); background: var(--ccm-warning-bg, #fef3c7); border-radius: var(--ccm-radius); border-left: 3px solid var(--ccm-warning);">
                        <strong>Recommended Excludes:</strong>
                        <p style="margin: var(--ccm-space-xs) 0;">
                            <code>${escapeHtml(patternList)}</code>
                        </p>
                        <button type="button" class="apply-recommended-excludes-btn ccm-button ccm-button-small ccm-button-primary" 
                                data-excludes="${escapeHtml(patternList)}">
                            Apply Recommended
                        </button>
                    </div>
                `;
            } else {
                html += `
                    <div style="margin-top: var(--ccm-space-md); padding: var(--ccm-space-sm); background: var(--ccm-success-bg, #d1fae5); border-radius: var(--ccm-radius); border-left: 3px solid var(--ccm-success);">
                        <strong>✓ All critical scripts are already excluded!</strong>
                    </div>
                `;
            }
            
            resultDiv.innerHTML = html;
            resultDiv.style.display = 'block';
            
            // Bind apply button
            resultDiv.querySelector('.apply-recommended-excludes-btn')?.addEventListener('click', (e) => {
                const newExcludes = e.target.dataset.excludes;
                const currentValue = excludeInput.value.trim();
                
                if (currentValue) {
                    // Merge with existing, avoiding duplicates
                    const existing = currentValue.split(',').map(s => s.trim().toLowerCase());
                    const toAdd = newExcludes.split(',').map(s => s.trim());
                    const merged = [...new Set([...existing, ...toAdd.map(s => s.toLowerCase())])];
                    excludeInput.value = merged.join(', ');
                } else {
                    excludeInput.value = newExcludes;
                }
                
                showNotification('Recommended excludes applied. Remember to save settings!', 'success');
                resultDiv.style.display = 'none';
            });
            
            showNotification(`Detected ${stats.total} scripts`, 'success');
            
        } catch (error) {
            console.error('Script detection error:', error);
            showNotification('Failed to detect scripts: ' + error.message, 'error');
            resultDiv.innerHTML = `
                <p class="ccm-error">
                    <strong>Error:</strong> ${escapeHtml(error.message)}
                </p>
            `;
            resultDiv.style.display = 'block';
        } finally {
            detectBtn.disabled = false;
            detectBtn.innerHTML = originalBtnContent;
        }
    }

    /**
     * Save performance optimizer settings
     */
    async function savePerfSettings() {
        const saveBtn = $('#save-perf-settings');
        const statusEl = $('#perf-save-status');
        const resultBox = $('#perf-result');
        
        if (saveBtn) {
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<div class="ccm-spinner ccm-spinner-small"></div> Saving...';
        }
        
        if (statusEl) {
            statusEl.innerHTML = '';
        }
        
        try {
            // Gather all settings
            const data = {
                enabled: $('#perf-master-enable')?.checked ? '1' : '',
                defer_js: $('#perf-defer-js')?.checked ? '1' : '',
                defer_js_excludes: $('#perf-defer-js-excludes')?.value || '',
                delay_js: $('#perf-delay-js')?.checked ? '1' : '',
                delay_js_timeout: $('#perf-delay-js-timeout')?.value || '0',
                delay_js_excludes: $('#perf-delay-js-excludes')?.value || '',
                preload_css: $('#perf-preload-css')?.checked ? '1' : '',
                preload_css_excludes: $('#perf-preload-css-excludes')?.value || '',
                preconnect: $('#perf-preconnect')?.checked ? '1' : '',
                preconnect_urls: $('#perf-preconnect-urls')?.value || '',
                dns_prefetch: $('#perf-dns-prefetch')?.checked ? '1' : '',
                dns_prefetch_urls: $('#perf-dns-prefetch-urls')?.value || '',
                lcp_fetchpriority: $('#perf-lcp-fetchpriority')?.checked ? '1' : '',
                lcp_preload: $('#perf-lcp-preload')?.checked ? '1' : '',
                lcp_preload_url: $('#perf-lcp-preload-url')?.value || '',
                remove_query_strings: $('#perf-remove-query-strings')?.checked ? '1' : '',
                disable_emoji: $('#perf-disable-emoji')?.checked ? '1' : '',
                disable_dashicons: $('#perf-disable-dashicons')?.checked ? '1' : '',
                lazy_load_iframes: $('#perf-lazy-load-iframes')?.checked ? '1' : '',
                youtube_facade: $('#perf-youtube-facade')?.checked ? '1' : '',
                // New v7.9.0 settings
                font_display_swap: $('#perf-font-display-swap')?.checked ? '1' : '',
                speculation_rules: $('#perf-speculation-rules')?.checked ? '1' : '',
                speculation_eagerness: $('#perf-speculation-eagerness')?.value || 'moderate',
                critical_css: $('#perf-critical-css')?.checked ? '1' : '',
                critical_css_code: $('#perf-critical-css-code')?.value || '',
                disable_jquery_migrate: $('#perf-disable-jquery-migrate')?.checked ? '1' : '',
                disable_block_css: $('#perf-disable-block-css')?.checked ? '1' : '',
                disable_woocommerce_cart_fragments: $('#perf-disable-woocommerce-cart-fragments')?.checked ? '1' : '',
                reduce_heartbeat: $('#perf-reduce-heartbeat')?.checked ? '1' : '',
                heartbeat_interval: $('#perf-heartbeat-interval')?.value || '60',
                disable_xmlrpc: $('#perf-disable-xmlrpc')?.checked ? '1' : '',
                disable_rsd_wlw: $('#perf-disable-rsd-wlw')?.checked ? '1' : '',
                disable_shortlink: $('#perf-disable-shortlink')?.checked ? '1' : '',
                disable_rest_api_links: $('#perf-disable-rest-api-links')?.checked ? '1' : '',
                disable_oembed: $('#perf-disable-oembed')?.checked ? '1' : '',
                // Video optimizations
                video_lazy_load: $('#perf-video-lazy-load')?.checked ? '1' : '',
                video_preload_none: $('#perf-video-preload-none')?.checked ? '1' : '',
                // Image optimizations (v7.23.0)
                lazy_load_images: $('#perf-lazy-load-images')?.checked ? '1' : '',
                image_decoding_async: $('#perf-image-decoding-async')?.checked ? '1' : '',
                prefetch_on_hover: $('#perf-prefetch-on-hover')?.checked ? '1' : '',
                // Head bloat removal (v7.24.0)
                remove_generator_tag: $('#perf-remove-generator-tag')?.checked ? '1' : '',
                remove_adjacent_post_links: $('#perf-remove-adjacent-post-links')?.checked ? '1' : '',
                disable_admin_bar: $('#perf-disable-admin-bar')?.checked ? '1' : '',
                // Script/style inlining (v7.25.0)
                inline_small_scripts: $('#perf-inline-small-scripts')?.checked ? '1' : '',
                inline_small_styles: $('#perf-inline-small-styles')?.checked ? '1' : '',
                inline_threshold_kb: $('#perf-inline-threshold-kb')?.value || '2',
                // Image attribute injection (v7.25.0)
                inject_image_dimensions: $('#perf-inject-image-dimensions')?.checked ? '1' : '',
                inject_srcset: $('#perf-inject-srcset')?.checked ? '1' : '',
                // HTML & font optimizations (v7.26.0)
                minify_html: $('#perf-minify-html')?.checked ? '1' : '',
                preload_key_requests: $('#perf-preload-key-requests')?.checked ? '1' : '',
                preload_key_urls: $('#perf-preload-key-urls')?.value || '',
                disable_wp_embed: $('#perf-disable-wp-embed')?.checked ? '1' : '',
                self_host_google_fonts: $('#perf-self-host-google-fonts')?.checked ? '1' : '',
                // Resource hints & third-party delay (v7.27.0)
                preload_css_bg_image: $('#perf-preload-css-bg-image')?.checked ? '1' : '',
                preload_css_bg_url: $('#perf-preload-css-bg-url')?.value || '',
                priority_hints_above_fold: $('#perf-priority-hints-above-fold')?.checked ? '1' : '',
                priority_hints_selectors: $('#perf-priority-hints-selectors')?.value || '',
                delay_third_party: $('#perf-delay-third-party')?.checked ? '1' : '',
                delay_third_party_domains: $('#perf-delay-third-party-domains')?.value || '',
                // Gutenberg / WooCommerce / Cache headers (v7.28.0)
                disable_gutenberg_frontend: $('#perf-disable-gutenberg-frontend')?.checked ? '1' : '',
                woo_scripts_shop_only: $('#perf-woo-scripts-shop-only')?.checked ? '1' : '',
                cache_control_meta: $('#perf-cache-control-meta')?.checked ? '1' : '',
                stale_while_revalidate: $('#perf-stale-while-revalidate')?.checked ? '1' : '',
                // WordPress Cron / Author Archives (v7.29.0)
                disable_wp_cron: $('#perf-disable-wp-cron')?.checked ? '1' : '',
                cron_interval: parseInt($('#perf-cron-interval')?.value) || 60,
                disable_author_archives: $('#perf-disable-author-archives')?.checked ? '1' : '',
                // INP / Interaction Optimizations (v7.30.0)
                warn_dom_size: $('#perf-warn-dom-size')?.checked ? '1' : '',
            };
            
            const response = await ajax('ccm_tools_save_perf_settings', data);
            
            showNotification('Performance settings saved successfully!', 'success');
            
            if (statusEl) {
                statusEl.innerHTML = '<span class="ccm-success">✓ Saved</span>';
            }
            
        } catch (error) {
            showNotification('Failed to save settings: ' + error.message, 'error');
            
            if (statusEl) {
                statusEl.innerHTML = '<span class="ccm-error">✗ Error</span>';
            }
            
            if (resultBox) {
                resultBox.innerHTML = `<p class="ccm-error">${escapeHtml(error.message)}</p>`;
            }
        } finally {
            if (saveBtn) {
                saveBtn.disabled = false;
                saveBtn.innerHTML = 'Save Settings';
            }
        }
    }
    
    /**
     * Export performance settings to JSON file
     */
    async function exportPerfSettings() {
        const exportBtn = $('#export-perf-settings');
        
        if (exportBtn) {
            exportBtn.disabled = true;
            exportBtn.innerHTML = '<div class="ccm-spinner ccm-spinner-small"></div> Exporting...';
        }
        
        try {
            const response = await ajax('ccm_tools_export_perf_settings', {});
            
            if (!response.data) {
                throw new Error('No data received from server');
            }
            
            // Create and download the JSON file
            const jsonString = JSON.stringify(response.data, null, 2);
            const blob = new Blob([jsonString], { type: 'application/json' });
            const url = URL.createObjectURL(blob);
            
            // Generate filename with site name and date
            const siteName = window.location.hostname.replace(/[^a-z0-9]/gi, '-');
            const date = new Date().toISOString().split('T')[0];
            const filename = `ccm-tools-perf-settings-${siteName}-${date}.json`;
            
            // Create download link and trigger
            const a = document.createElement('a');
            a.href = url;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
            
            showNotification('Settings exported successfully!', 'success');
            
        } catch (error) {
            showNotification('Failed to export settings: ' + error.message, 'error');
        } finally {
            if (exportBtn) {
                exportBtn.disabled = false;
                exportBtn.innerHTML = '📥 Export Settings';
            }
        }
    }
    
    /**
     * Handle file selection for import
     */
    function handleImportFileSelect(e) {
        const file = e.target.files[0];
        const fileNameSpan = $('#import-file-name');
        const importBtn = $('#import-perf-settings');
        
        if (file) {
            if (fileNameSpan) {
                fileNameSpan.textContent = file.name;
            }
            if (importBtn) {
                importBtn.style.display = 'inline-block';
            }
        } else {
            if (fileNameSpan) {
                fileNameSpan.textContent = '';
            }
            if (importBtn) {
                importBtn.style.display = 'none';
            }
        }
    }
    
    /**
     * Import performance settings from JSON file
     */
    async function importPerfSettings() {
        const importBtn = $('#import-perf-settings');
        const fileInput = $('#import-perf-file');
        
        if (!fileInput || !fileInput.files || !fileInput.files[0]) {
            showNotification('Please select a file to import', 'warning');
            return;
        }
        
        const file = fileInput.files[0];
        
        if (importBtn) {
            importBtn.disabled = true;
            importBtn.innerHTML = '<div class="ccm-spinner ccm-spinner-small"></div> Importing...';
        }
        
        try {
            // Read the file
            const fileContent = await new Promise((resolve, reject) => {
                const reader = new FileReader();
                reader.onload = (e) => resolve(e.target.result);
                reader.onerror = () => reject(new Error('Failed to read file'));
                reader.readAsText(file);
            });
            
            // Parse and validate JSON
            let importData;
            try {
                importData = JSON.parse(fileContent);
            } catch (e) {
                throw new Error('Invalid JSON file');
            }
            
            // Validate it's a CCM Tools export
            if (!importData.plugin || importData.plugin !== 'ccm-tools') {
                throw new Error('This file does not appear to be a CCM Tools settings export');
            }
            
            // Confirm import
            const confirmed = confirm(
                `Import settings from:\n` +
                `Site: ${importData.site_url || 'Unknown'}\n` +
                `Exported: ${importData.exported_at || 'Unknown'}\n` +
                `Version: ${importData.version || 'Unknown'}\n\n` +
                `This will overwrite your current settings. Continue?`
            );
            
            if (!confirmed) {
                showNotification('Import cancelled', 'info');
                return;
            }
            
            // Send to server for import
            const response = await ajax('ccm_tools_import_perf_settings', {
                settings_json: fileContent
            });
            
            showNotification('Settings imported successfully! Refreshing page...', 'success');
            
            // Refresh to show new settings
            setTimeout(() => {
                window.location.reload();
            }, 1500);
            
        } catch (error) {
            showNotification('Failed to import settings: ' + error.message, 'error');
        } finally {
            if (importBtn) {
                importBtn.disabled = false;
                importBtn.innerHTML = 'Import Settings';
            }
        }
    }
    
    /**
     * Detect external origins from the site's homepage
     * @param {string} target - 'preconnect' or 'dns-prefetch'
     */
    async function detectExternalOrigins(target = 'preconnect') {
        const isPreconnect = target === 'preconnect';
        const detectBtn = isPreconnect ? $('#detect-external-origins') : $('#detect-dns-prefetch-origins');
        const resultDiv = isPreconnect ? $('#detected-origins-result') : $('#detected-dns-origins-result');
        const textarea = isPreconnect ? $('#perf-preconnect-urls') : $('#perf-dns-prefetch-urls');
        const otherTextarea = isPreconnect ? $('#perf-dns-prefetch-urls') : $('#perf-preconnect-urls');
        const targetLabel = isPreconnect ? 'Preconnect' : 'DNS Prefetch';
        const otherLabel = isPreconnect ? 'DNS Prefetch' : 'Preconnect';
        
        if (!detectBtn || !resultDiv) return;
        
        // Get current URLs in both lists
        const currentUrls = new Set(textarea.value.trim().split('\n').filter(url => url.trim()));
        const otherUrls = new Set(otherTextarea.value.trim().split('\n').filter(url => url.trim()));
        
        // Store original button content
        const originalBtnContent = detectBtn.innerHTML;
        detectBtn.disabled = true;
        detectBtn.innerHTML = '<div class="ccm-spinner ccm-spinner-small"></div> Scanning...';
        
        try {
            const response = await ajax('ccm_tools_detect_external_origins', {});
            
            if (!response.data || !response.data.origins) {
                throw new Error('Invalid response from server');
            }
            
            const { origins, categorized, count, site_host } = response.data;
            
            if (count === 0) {
                resultDiv.innerHTML = `
                    <p class="ccm-text-muted">
                        <strong>No external origins detected.</strong><br>
                        Your site doesn't appear to load any external resources.
                    </p>
                `;
                resultDiv.style.display = 'block';
                showNotification('No external origins found', 'info');
                return;
            }
            
            // Count origins in each state
            let inCurrentCount = 0;
            let inOtherCount = 0;
            let availableCount = 0;
            
            for (const origin of origins) {
                if (currentUrls.has(origin)) inCurrentCount++;
                else if (otherUrls.has(origin)) inOtherCount++;
                else availableCount++;
            }
            
            // Build categorized display
            let html = `
                <p style="margin-bottom: var(--ccm-space-md);">
                    <strong>Found ${count} external origin${count !== 1 ? 's' : ''}:</strong>
                    <span class="ccm-text-muted">(excluding ${escapeHtml(site_host)})</span>
                </p>
            `;
            
            // Show summary of states
            if (inCurrentCount > 0 || inOtherCount > 0) {
                html += `<div style="margin-bottom: var(--ccm-space-md); padding: var(--ccm-space-sm); background: var(--ccm-bg); border-radius: var(--ccm-radius); font-size: 0.85em;">`;
                if (inCurrentCount > 0) {
                    html += `<span class="ccm-success">✓ ${inCurrentCount} already in ${targetLabel}</span> `;
                }
                if (inOtherCount > 0) {
                    html += `<span class="ccm-warning">⚠ ${inOtherCount} in ${otherLabel}</span>`;
                }
                html += `</div>`;
            }
            
            // Category labels and icons
            const categoryLabels = {
                fonts: '🔤 Fonts',
                analytics: '📊 Analytics',
                cdn: '🌐 CDN',
                social: '📱 Social',
                other: '📦 Other'
            };
            
            // Recommended categories for each target type
            const recommendedFor = {
                'preconnect': ['fonts', 'cdn'],
                'dns-prefetch': ['analytics', 'social']
            };
            const recommended = recommendedFor[target] || [];
            
            // Build checkboxes for each origin
            html += '<div style="max-height: 300px; overflow-y: auto;">';
            
            for (const [category, catOrigins] of Object.entries(categorized)) {
                if (catOrigins.length === 0) continue;
                
                const isRecommended = recommended.includes(category);
                const categoryNote = isRecommended ? ' <span class="ccm-success" style="font-size: 0.8em;">(recommended)</span>' : '';
                
                html += `
                    <div style="margin-bottom: var(--ccm-space-md);">
                        <strong>${categoryLabels[category] || category}${categoryNote}</strong>
                        <div style="margin-top: var(--ccm-space-xs);">
                `;
                
                for (const origin of catOrigins) {
                    const inCurrent = currentUrls.has(origin);
                    const inOther = otherUrls.has(origin);
                    const isAvailable = !inCurrent && !inOther;
                    
                    // Default: check if recommended category AND available
                    const shouldCheck = isRecommended && isAvailable;
                    
                    let statusBadge = '';
                    let labelStyle = 'display: flex; align-items: center; gap: var(--ccm-space-xs); margin-bottom: var(--ccm-space-xs);';
                    let checkboxAttrs = `class="detected-origin-checkbox-${target}" value="${escapeHtml(origin)}"`;
                    
                    if (inCurrent) {
                        statusBadge = `<span class="ccm-success" style="font-size: 0.75em; margin-left: var(--ccm-space-xs);">✓ already added</span>`;
                        labelStyle += ' opacity: 0.5;';
                        checkboxAttrs += ' disabled checked';
                    } else if (inOther) {
                        statusBadge = `<span class="ccm-warning" style="font-size: 0.75em; margin-left: var(--ccm-space-xs);">in ${otherLabel}</span>`;
                        checkboxAttrs += ` data-in-other="true"`;
                        if (shouldCheck) checkboxAttrs += ' checked';
                    } else {
                        labelStyle += ' cursor: pointer;';
                        if (shouldCheck) checkboxAttrs += ' checked';
                    }
                    
                    html += `
                        <label style="${labelStyle}">
                            <input type="checkbox" ${checkboxAttrs}>
                            <code style="font-size: 0.85em;">${escapeHtml(origin)}</code>
                            ${statusBadge}
                        </label>
                    `;
                }
                
                html += '</div></div>';
            }
            
            html += '</div>';
            
            // Action buttons
            html += `
                <div style="margin-top: var(--ccm-space-md); display: flex; gap: var(--ccm-space-sm); flex-wrap: wrap;">
                    <button type="button" class="add-selected-origins-btn ccm-button ccm-button-small ccm-button-primary">
                        Add Selected
                    </button>
                    <button type="button" class="select-recommended-btn ccm-button ccm-button-small ccm-button-secondary">
                        Select Recommended
                    </button>
                    <button type="button" class="select-none-origins-btn ccm-button ccm-button-small ccm-button-secondary">
                        Select None
                    </button>
                </div>
            `;
            
            // Info about overlap handling
            html += `<p class="ccm-text-muted" style="margin-top: var(--ccm-space-sm); font-size: 0.85em;">
                💡 <strong>${targetLabel}</strong>: Best for ${isPreconnect ? 'fonts & CDNs (critical resources)' : 'analytics & social (resources that might load)'}.<br>
                ${inOtherCount > 0 ? `⚠️ Origins in ${otherLabel} will be <strong>moved</strong> here (no duplicates).` : 'Origins already in this list are greyed out.'}
            </p>`;
            
            resultDiv.innerHTML = html;
            resultDiv.style.display = 'block';
            
            // Bind action buttons (scoped to this result div)
            resultDiv.querySelector('.add-selected-origins-btn')?.addEventListener('click', () => {
                const checkboxes = resultDiv.querySelectorAll(`.detected-origin-checkbox-${target}:checked:not(:disabled)`);
                const selectedOrigins = Array.from(checkboxes).map(cb => cb.value);
                
                if (selectedOrigins.length === 0) {
                    showNotification('No origins selected', 'warning');
                    return;
                }
                
                // Separate origins that need to be moved from the other list
                const toMove = selectedOrigins.filter(url => otherUrls.has(url));
                const toAdd = selectedOrigins.filter(url => !otherUrls.has(url));
                
                // Remove moved origins from other list
                if (toMove.length > 0) {
                    const otherCurrentUrls = otherTextarea.value.trim().split('\n').filter(url => url.trim());
                    const otherUpdated = otherCurrentUrls.filter(url => !toMove.includes(url));
                    otherTextarea.value = otherUpdated.join('\n');
                }
                
                // Add to current list
                const existingUrls = textarea.value.trim().split('\n').filter(url => url.trim());
                const allUrls = [...new Set([...existingUrls, ...selectedOrigins])];
                textarea.value = allUrls.join('\n');
                
                // Show appropriate message
                let message = '';
                if (toMove.length > 0 && toAdd.length > 0) {
                    message = `Added ${toAdd.length} and moved ${toMove.length} origin${toMove.length !== 1 ? 's' : ''} to ${targetLabel}`;
                } else if (toMove.length > 0) {
                    message = `Moved ${toMove.length} origin${toMove.length !== 1 ? 's' : ''} from ${otherLabel} to ${targetLabel}`;
                } else {
                    message = `Added ${toAdd.length} origin${toAdd.length !== 1 ? 's' : ''} to ${targetLabel}`;
                }
                
                showNotification(message, 'success');
                resultDiv.style.display = 'none';
            });
            
            resultDiv.querySelector('.select-recommended-btn')?.addEventListener('click', () => {
                resultDiv.querySelectorAll(`.detected-origin-checkbox-${target}:not(:disabled)`).forEach(cb => {
                    // Check the category of this origin
                    const categoryDiv = cb.closest('[style*="margin-bottom: var(--ccm-space-md)"]');
                    const categoryLabel = categoryDiv?.querySelector('strong')?.textContent || '';
                    
                    // Check if it's a recommended category
                    const isRecommendedCategory = recommended.some(cat => 
                        categoryLabel.toLowerCase().includes(cat) || 
                        categoryLabels[cat]?.includes(categoryLabel.split(' ')[0])
                    );
                    
                    cb.checked = isRecommendedCategory;
                });
            });
            
            resultDiv.querySelector('.select-none-origins-btn')?.addEventListener('click', () => {
                resultDiv.querySelectorAll(`.detected-origin-checkbox-${target}:not(:disabled)`).forEach(cb => cb.checked = false);
            });
            
            showNotification(`Detected ${count} external origins (${availableCount} available)`, 'success');
            
        } catch (error) {
            showNotification('Failed to detect origins: ' + error.message, 'error');
            resultDiv.innerHTML = `<p class="ccm-error">${escapeHtml(error.message)}</p>`;
            resultDiv.style.display = 'block';
        } finally {
            detectBtn.disabled = false;
            detectBtn.innerHTML = originalBtnContent;
        }
    }

    // ===================================
    // Initialize
    // ===================================

    ready(() => {
        // Check if we're on CCM Tools page
        if (!document.querySelector('.ccm-tools')) return;
        
        // Initialize event handlers
        try {
            initEventHandlers();
        } catch (e) { console.error('CCM: Event handlers init error', e); }
        
        // Initialize WebP converter handlers if on WebP page
        try {
            if ($('#webp-settings-form') || $('#start-bulk-conversion')) {
                initWebPConverterHandlers();
            }
        } catch (e) { console.error('CCM: WebP init error', e); }
        
        // Initialize Performance Optimizer handlers if on perf page
        try {
            if ($('#save-perf-settings')) {
                initPerfOptimizerHandlers();
            }
        } catch (e) { console.error('CCM: Perf init error', e); }
        
        // Initialize uploads backup handlers
        try {
            if ($('#start-uploads-backup')) {
                initUploadsBackupHandlers();
            }
        } catch (e) { console.error('CCM: Backup init error', e); }
        
        // Initialize Redis Object Cache page handlers
        try {
            if ($('#redis-settings-form') || $('#redis-enable') || $('#redis-disable')) {
                initRedisObjectCacheHandlers();
            }
        } catch (e) { console.error('CCM: Redis init error', e); }
        
        // Mark front page rows
        const frontPageIndicators = $$('.ccm-front-page-indicator');
        frontPageIndicators.forEach(indicator => {
            const row = indicator.closest('tr');
            if (row) row.classList.add('ccm-front-page-row');
        });

        // Initialize Cloudflare handlers if on CF page
        try {
            if ($('#cf-connection-form')) {
                initCloudflareHandlers();
            }
        } catch (e) { console.error('CCM: Cloudflare init error', e); }
    });
    
    // ===================================
    // Cloudflare Integration Handlers
    // ===================================

    function initCloudflareHandlers() {
        const connectBtn    = $('#cf-connect-btn');
        const disconnectBtn = $('#cf-disconnect-btn');
        const tokenInput    = $('#cf-api-token');
        const zoneInput     = $('#cf-zone-id');
        const toggleToken   = $('#cf-toggle-token');
        const statusSpan    = $('#cf-connection-status');
        const purgeAllBtn   = $('#cf-purge-all');
        const purgeUrlsBtn  = $('#cf-purge-urls-btn');
        const devToggle     = $('#cf-dev-mode-toggle');

        // Toggle token visibility
        if (toggleToken && tokenInput) {
            toggleToken.addEventListener('click', () => {
                tokenInput.type = tokenInput.type === 'password' ? 'text' : 'password';
            });
        }

        // Connect
        if (connectBtn) {
            connectBtn.addEventListener('click', async () => {
                const token  = tokenInput ? tokenInput.value.trim() : '';
                const zoneId = zoneInput ? zoneInput.value.trim() : '';

                if (!token) {
                    showNotification('Please enter an API Token.', 'error');
                    return;
                }

                connectBtn.disabled = true;
                connectBtn.textContent = 'Connecting…';
                if (statusSpan) statusSpan.textContent = '';

                try {
                    const res = await ajax('ccm_tools_cf_connect', {
                        api_token: token,
                        zone_id: zoneId,
                    });

                    showNotification(res.data.message, 'success');
                    if (statusSpan) {
                        statusSpan.innerHTML = '<span class="ccm-success">✓ Connected — ' +
                            escHtml(res.data.zone.name) + ' (' + escHtml(res.data.zone.plan) + ')</span>';
                    }
                    // Reload to show full dashboard
                    setTimeout(() => location.reload(), 1500);
                } catch (err) {
                    showNotification('Connection failed: ' + err.message, 'error');
                } finally {
                    connectBtn.disabled = false;
                    connectBtn.textContent = 'Connect';
                }
            });
        }

        // Disconnect
        if (disconnectBtn) {
            disconnectBtn.addEventListener('click', async () => {
                if (!confirm('Disconnect from Cloudflare? This will remove your API token.')) return;

                disconnectBtn.disabled = true;
                try {
                    const res = await ajax('ccm_tools_cf_disconnect');
                    showNotification(res.data.message, 'success');
                    setTimeout(() => location.reload(), 1000);
                } catch (err) {
                    showNotification('Disconnect failed: ' + err.message, 'error');
                } finally {
                    disconnectBtn.disabled = false;
                }
            });
        }

        // Load zone status on page load
        const statusCard = $('#cf-zone-status');
        if (statusCard) {
            loadCloudflareStatus(statusCard, devToggle);
        }

        // Apply Recommended Settings
        const recommendedBtn = $('#cf-apply-recommended');
        if (recommendedBtn) {
            recommendedBtn.addEventListener('click', async () => {
                if (!confirm('Apply Cloudflare\u2019s recommended settings for WordPress? This will change security, caching, and performance settings to optimal values.')) return;

                recommendedBtn.disabled = true;
                recommendedBtn.textContent = 'Applying\u2026';

                try {
                    const res = await ajax('ccm_tools_cf_apply_recommended');
                    showNotification(res.data.message, 'success');
                    if (res.data.failed && res.data.failed.length) {
                        showNotification('Failed: ' + res.data.failed.join(', '), 'warning');
                    }
                    // Reload zone status to reflect changes
                    if (statusCard) loadCloudflareStatus(statusCard, devToggle);
                } catch (err) {
                    showNotification('Failed: ' + err.message, 'error');
                } finally {
                    recommendedBtn.disabled = false;
                    recommendedBtn.textContent = 'Apply Recommended';
                }
            });
        }

        // Purge All Cache
        if (purgeAllBtn) {
            purgeAllBtn.addEventListener('click', async () => {
                if (!confirm('Purge ALL Cloudflare cached files? This may temporarily slow your site.')) return;

                purgeAllBtn.disabled = true;
                purgeAllBtn.textContent = 'Purging…';

                try {
                    const res = await ajax('ccm_tools_cf_purge_all');
                    showNotification(res.data.message, 'success');
                } catch (err) {
                    showNotification('Purge failed: ' + err.message, 'error');
                } finally {
                    purgeAllBtn.disabled = false;
                    purgeAllBtn.textContent = 'Purge Everything';
                }
            });
        }

        // Purge Specific URLs
        if (purgeUrlsBtn) {
            purgeUrlsBtn.addEventListener('click', async () => {
                const textarea = $('#cf-purge-urls');
                const urls = textarea ? textarea.value.trim() : '';

                if (!urls) {
                    showNotification('Please enter at least one URL.', 'error');
                    return;
                }

                purgeUrlsBtn.disabled = true;
                purgeUrlsBtn.textContent = 'Purging…';

                try {
                    const res = await ajax('ccm_tools_cf_purge_urls', { urls });
                    showNotification(res.data.message, 'success');
                    if (textarea) textarea.value = '';
                } catch (err) {
                    showNotification('Purge failed: ' + err.message, 'error');
                } finally {
                    purgeUrlsBtn.disabled = false;
                    purgeUrlsBtn.textContent = 'Purge URLs';
                }
            });
        }

        // Development Mode toggle
        if (devToggle) {
            devToggle.addEventListener('change', async () => {
                const enable = devToggle.checked;
                devToggle.disabled = true;

                try {
                    const res = await ajax('ccm_tools_cf_dev_mode', {
                        enable: enable ? '1' : '0',
                    });
                    showNotification(res.data.message, 'success');
                    updateDevModeStatus(enable);
                } catch (err) {
                    showNotification('Failed: ' + err.message, 'error');
                    devToggle.checked = !enable;
                } finally {
                    devToggle.disabled = false;
                }
            });
        }

        // Auto-Purge toggle
        const autoPurgeToggle = $('#cf-auto-purge-toggle');
        if (autoPurgeToggle) {
            autoPurgeToggle.addEventListener('change', async () => {
                const enable = autoPurgeToggle.checked;
                autoPurgeToggle.disabled = true;

                try {
                    const res = await ajax('ccm_tools_cf_auto_purge', {
                        enable: enable ? '1' : '0',
                    });
                    showNotification(res.data.message, 'success');
                } catch (err) {
                    showNotification('Failed: ' + err.message, 'error');
                    autoPurgeToggle.checked = !enable;
                } finally {
                    autoPurgeToggle.disabled = false;
                }
            });
        }

        // Load Analytics panel
        const analyticsContainer = $('#cf-analytics');
        if (analyticsContainer) {
            loadCfAnalytics(analyticsContainer);
        }

        // Load DNS Records panel
        const dnsContainer = $('#cf-dns-records');
        if (dnsContainer) {
            loadCfDnsRecords(dnsContainer);
        }
    }

    /**
     * Load Cloudflare zone status and render the status panel with toggleable controls.
     */
    async function loadCloudflareStatus(container, devToggle) {
        try {
            const res = await ajax('ccm_tools_cf_get_status');
            if (!res.success) {
                container.innerHTML = '<p class="ccm-error">' + escHtml(res.data.message || 'Failed to load status.') + '</p>';
                return;
            }

            const zone = res.data.zone || {};
            const features = res.data.features || {};
            const isFreePlan = !zone.plan_id || zone.plan_id === 'free';

            let html = '<table class="ccm-table">';
            html += cfStatusRow('Zone', escHtml(zone.name || '—'));
            html += cfStatusRow('Zone ID', '<code style="font-size: 0.85em;">' + escHtml(zone.id) + '</code>');
            html += cfStatusRow('Status', zone.status === 'active'
                ? '<span class="ccm-success">✓ Active</span>'
                : '<span class="ccm-error">' + escHtml(zone.status) + '</span>');
            html += cfStatusRow('Plan', escHtml(zone.plan));

            html += '<tr><td colspan="2" style="padding-top: var(--ccm-space-md);"><strong>Features</strong></td></tr>';

            // --- Toggleable on/off settings ---
            const toggleSettings = [
                { key: 'bot_fight_mode', label: 'Bot Fight Mode', desc: 'Challenge requests matching patterns of known bots and automated traffic' },
                { key: 'browser_check', label: 'Browser Integrity Check', desc: 'Evaluate HTTP headers for threats and block known bad bots' },
                { key: 'rocket_loader', label: 'Rocket Loader', desc: 'Prioritise loading of your page content over scripts' },
                { key: 'always_online', label: 'Always Online', desc: 'Show a cached version of your site if your server goes offline' },
            ];
            for (const item of toggleSettings) {
                const val = features[item.key];
                if (val === undefined) continue;
                const checked = val === 'on' ? ' checked' : '';
                html += '<tr><th>' + escHtml(item.label) + '<br><span class="ccm-text-muted" style="font-weight: normal; font-size: 0.85em;">' + escHtml(item.desc) + '</span></th>';
                html += '<td style="text-align: right;"><label class="ccm-toggle"><input type="checkbox" data-cf-setting="' + item.key + '"' + checked + '>';
                html += '<span class="ccm-toggle-slider"></span></label></td></tr>';
            }

            // --- WebP (editable; Cloudflare only exposes this on Pro+ plans) ---
            if (features.webp !== undefined) {
                if (isFreePlan) {
                    html += '<tr><th>WebP Conversion<br><span class="ccm-text-muted" style="font-weight: normal; font-size: 0.85em;">Serve WebP images to supported browsers (Pro+ plan)</span></th>';
                    html += '<td style="text-align: right;"><label class="ccm-toggle"><input type="checkbox" disabled' + (features.webp === 'on' ? ' checked' : '') + '>';
                    html += '<span class="ccm-toggle-slider"></span></label> <span class="ccm-text-muted" style="font-size: 0.8em;">Requires Pro+</span></td></tr>';
                } else {
                    const wChecked = features.webp === 'on' ? ' checked' : '';
                    html += '<tr><th>WebP Conversion<br><span class="ccm-text-muted" style="font-weight: normal; font-size: 0.85em;">Serve WebP images to supported browsers (Pro+ plan)</span></th>';
                    html += '<td style="text-align: right;"><label class="ccm-toggle"><input type="checkbox" data-cf-setting="webp"' + wChecked + '>';
                    html += '<span class="ccm-toggle-slider"></span></label></td></tr>';
                }
            }

            // --- Polish (editable; Cloudflare only exposes this on Pro+ plans) ---
            if (features.polish !== undefined) {
                if (isFreePlan) {
                    html += '<tr><th>Polish (Image Optimization)<br><span class="ccm-text-muted" style="font-weight: normal; font-size: 0.85em;">Strip metadata and compress images at Cloudflare\'s edge (Pro+ plan)</span></th>';
                    html += '<td style="text-align: right;"><select disabled class="ccm-cf-select">';
                    const polishOptionsFree = [['off', 'Off'], ['lossless', 'Lossless'], ['lossy', 'Lossy']];
                    for (const [pval, plabel] of polishOptionsFree) {
                        const sel = features.polish === pval ? ' selected' : '';
                        html += '<option value="' + pval + '"' + sel + '>' + plabel + '</option>';
                    }
                    html += '</select> <span class="ccm-text-muted" style="font-size: 0.8em;">Requires Pro+</span></td></tr>';
                } else {
                    html += '<tr><th>Polish (Image Optimization)<br><span class="ccm-text-muted" style="font-weight: normal; font-size: 0.85em;">Strip metadata and compress images at Cloudflare\'s edge (Pro+ plan)</span></th>';
                    html += '<td style="text-align: right;"><select data-cf-setting="polish" class="ccm-cf-select">';
                    const polishOptions = [['off', 'Off'], ['lossless', 'Lossless'], ['lossy', 'Lossy']];
                    for (const [pval, plabel] of polishOptions) {
                        const sel = features.polish === pval ? ' selected' : '';
                        html += '<option value="' + pval + '"' + sel + '>' + plabel + '</option>';
                    }
                    html += '</select></td></tr>';
                }
            }

            // --- Mirage (editable; Cloudflare only exposes this on Pro+ plans) ---
            if (features.mirage !== undefined) {
                if (isFreePlan) {
                    html += '<tr><th>Mirage<br><span class="ccm-text-muted" style="font-weight: normal; font-size: 0.85em;">Lazy load images and optimize for mobile visitors (Pro+ plan)</span></th>';
                    html += '<td style="text-align: right;"><label class="ccm-toggle"><input type="checkbox" disabled' + (features.mirage === 'on' ? ' checked' : '') + '>';
                    html += '<span class="ccm-toggle-slider"></span></label> <span class="ccm-text-muted" style="font-size: 0.8em;">Requires Pro+</span></td></tr>';
                } else {
                    const mChecked = features.mirage === 'on' ? ' checked' : '';
                    html += '<tr><th>Mirage<br><span class="ccm-text-muted" style="font-weight: normal; font-size: 0.85em;">Lazy load images and optimize for mobile visitors (Pro+ plan)</span></th>';
                    html += '<td style="text-align: right;"><label class="ccm-toggle"><input type="checkbox" data-cf-setting="mirage"' + mChecked + '>';
                    html += '<span class="ccm-toggle-slider"></span></label></td></tr>';
                }
            }

            // --- Browser Cache TTL (dropdown) ---
            if (features.browser_cache_ttl !== undefined) {
                html += '<tr><th>Browser Cache TTL<br><span class="ccm-text-muted" style="font-weight: normal; font-size: 0.85em;">How long browsers should cache resources</span></th>';
                html += '<td style="text-align: right;"><select data-cf-setting="browser_cache_ttl" class="ccm-cf-select">';
                const ttlOptions = [
                    [0, 'Respect Existing Headers'],
                    [1800, '30 minutes'], [3600, '1 hour'], [7200, '2 hours'],
                    [14400, '4 hours'], [28800, '8 hours'], [43200, '12 hours'],
                    [86400, '1 day'], [172800, '2 days'], [259200, '3 days'],
                    [345600, '4 days'], [432000, '5 days'], [691200, '8 days'],
                    [1382400, '16 days'], [2592000, '1 month'], [5184000, '2 months'],
                    [15552000, '6 months'], [31536000, '1 year'],
                ];
                const curTtl = features.browser_cache_ttl;
                for (const [tval, tlabel] of ttlOptions) {
                    const sel = curTtl === tval ? ' selected' : '';
                    html += '<option value="' + tval + '"' + sel + '>' + tlabel + '</option>';
                }
                html += '</select></td></tr>';
            }

            // --- APO ---
            if (features.apo !== undefined) {
                const apoEnabled = features.apo && features.apo.enabled;
                const apoChecked = apoEnabled ? ' checked' : '';
                html += '<tr><th>Automatic Platform Optimization (APO)<br><span class="ccm-text-muted" style="font-weight: normal; font-size: 0.85em;">Cloudflare\'s WordPress-specific full-page caching at the edge</span></th>';
                html += '<td style="text-align: right;"><label class="ccm-toggle"><input type="checkbox" data-cf-setting="automatic_platform_optimization"' + apoChecked + '>';
                html += '<span class="ccm-toggle-slider"></span></label></td></tr>';
            }

            // Development mode (read-only here — separate toggle below)
            const devMode = features.development_mode;
            if (devMode !== undefined) {
                html += cfStatusRow('Development Mode', devMode === 'on'
                    ? '<span style="color: var(--ccm-warning);">⚡ Active (auto-disables in 3h)</span>'
                    : '<span class="ccm-text-muted">Off</span>');

                if (devToggle) {
                    devToggle.checked = devMode === 'on';
                    updateDevModeStatus(devMode === 'on');
                }
            }

            html += '</table>';

            // Overlap warnings
            if (features.polish !== 'off' && features.polish !== undefined || features.webp === 'on') {
                html += '<div class="ccm-notice" style="margin-top: var(--ccm-space-md); padding: var(--ccm-space-sm) var(--ccm-space-md); background: var(--ccm-warning-bg, #fff8e1); border-left: 3px solid var(--ccm-warning); border-radius: var(--ccm-radius);">';
                html += '<span class="ccm-icon">⚠</span> ';
                html += '<strong>Note:</strong> Cloudflare is handling image optimization (Polish/WebP). The CCM Tools WebP converter may not be needed for this site.';
                html += '</div>';
            }

            container.innerHTML = html;

            // Bind toggle/select events
            bindCfSettingControls(container);

            // Render Security Settings panel
            renderCfSecurityPanel(features);

            // Bind "Under Attack" toggle
            const uaToggle = $('#cf-under-attack-toggle');
            if (uaToggle) {
                uaToggle.addEventListener('change', async function () {
                    const enable = this.checked;
                    const box = $('#cf-under-attack-box');
                    this.disabled = true;

                    if (enable && !confirm('Enable "I\'m Under Attack" mode? All visitors will see a challenge page for ~5 seconds.')) {
                        this.checked = false;
                        this.disabled = false;
                        return;
                    }

                    try {
                        const newLevel = enable ? 'under_attack' : 'high';
                        const res = await ajax('ccm_tools_cf_update_setting', { setting: 'security_level', value: newLevel });
                        showNotification(enable ? 'Under Attack mode enabled.' : 'Under Attack mode disabled (Security Level set to High).', 'success');
                        if (box) box.classList.toggle('ccm-cf-under-attack-active', enable);
                        // Update Security Level dropdown if present
                        const secSelect = document.querySelector('[data-cf-setting="security_level"]');
                        if (secSelect) secSelect.value = newLevel;
                    } catch (err) {
                        showNotification('Failed: ' + err.message, 'error');
                        this.checked = !enable;
                    } finally {
                        this.disabled = false;
                    }
                });
            }

            // Render Network Settings panel
            renderCfNetworkPanel(features);

        } catch (err) {
            container.innerHTML = '<p class="ccm-error">Failed to load zone status: ' + escHtml(err.message) + '</p>';
        }
    }

    /**
     * Bind change events to all CF setting controls in the zone status panel.
     */
    function bindCfSettingControls(container) {
        // On/off toggle switches (rocket_loader, always_online, webp)
        container.querySelectorAll('[data-cf-setting]').forEach(el => {
            el.addEventListener('change', async function () {
                const setting = this.dataset.cfSetting;
                const isToggle = this.type === 'checkbox';
                const isSelect = this.tagName === 'SELECT';

                this.disabled = true;
                const params = { setting };

                if (isToggle) {
                    params.value = this.checked ? 'on' : 'off';
                } else if (isSelect) {
                    params.value = this.value;
                }

                try {
                    const res = await ajax('ccm_tools_cf_update_setting', params);
                    showNotification(res.data.message, 'success');
                    // Sync Under Attack toggle when security_level dropdown changes
                    if (setting === 'security_level') {
                        const uaToggle = $('#cf-under-attack-toggle');
                        const uaBox = $('#cf-under-attack-box');
                        if (uaToggle) uaToggle.checked = (this.value === 'under_attack');
                        if (uaBox) uaBox.classList.toggle('ccm-cf-under-attack-active', this.value === 'under_attack');
                    }
                } catch (err) {
                    showNotification('Failed: ' + err.message, 'error');
                    if (isToggle) this.checked = !this.checked;
                } finally {
                    this.disabled = false;
                }
            });
        });

    }

    function cfStatusRow(label, value) {
        return '<tr><th>' + escHtml(label) + '</th><td>' + value + '</td></tr>';
    }

    function updateDevModeStatus(enabled) {
        const el = $('#cf-dev-mode-status');
        if (!el) return;
        el.innerHTML = enabled
            ? '<span style="color: var(--ccm-warning);">⚡ Development Mode is active — caching bypassed for 3 hours.</span>'
            : '';
    }

    /**
     * Render Security Settings panel from zone features data.
     */
    function renderCfSecurityPanel(features) {
        const container = $('#cf-security-settings');
        if (!container) return;

        let html = '';

        // "I'm Under Attack" mode — prominent toggle at the top
        if (features.security_level !== undefined) {
            const isUnderAttack = features.security_level === 'under_attack';
            html += '<div class="ccm-cf-under-attack' + (isUnderAttack ? ' ccm-cf-under-attack-active' : '') + '" id="cf-under-attack-box">';
            html += '<div style="flex: 1;"><strong>\u{1F6E1}\uFE0F I\'m Under Attack Mode</strong>';
            html += '<p class="ccm-text-muted" style="margin: 4px 0 0;">Enables additional DDoS protection. Visitors see a challenge page for ~5 seconds while Cloudflare verifies the request.</p></div>';
            html += '<label class="ccm-toggle"><input type="checkbox" id="cf-under-attack-toggle"' + (isUnderAttack ? ' checked' : '') + '>';
            html += '<span class="ccm-toggle-slider"></span></label></div>';
        }

        html += '<table class="ccm-table">';

        // Security Level (dropdown)
        if (features.security_level !== undefined) {
            const levels = [
                ['essentially_off', 'Essentially Off'],
                ['low', 'Low'],
                ['medium', 'Medium'],
                ['high', 'High'],
                ['under_attack', 'I\'m Under Attack!'],
            ];
            html += '<tr><th>Security Level<br><span class="ccm-text-muted" style="font-weight: normal; font-size: 0.85em;">Controls Cloudflare\'s challenge page sensitivity</span></th>';
            html += '<td style="text-align: right;"><select data-cf-setting="security_level" class="ccm-cf-select">';
            for (const [val, label] of levels) {
                const sel = features.security_level === val ? ' selected' : '';
                html += '<option value="' + val + '"' + sel + '>' + label + '</option>';
            }
            html += '</select></td></tr>';
        }

        // Email Obfuscation (toggle)
        if (features.email_obfuscation !== undefined) {
            const checked = features.email_obfuscation === 'on' ? ' checked' : '';
            html += '<tr><th>Email Address Obfuscation<br><span class="ccm-text-muted" style="font-weight: normal; font-size: 0.85em;">Hide email addresses from bots and scrapers</span></th>';
            html += '<td style="text-align: right;"><label class="ccm-toggle"><input type="checkbox" data-cf-setting="email_obfuscation"' + checked + '>';
            html += '<span class="ccm-toggle-slider"></span></label></td></tr>';
        }

        // Hotlink Protection (toggle)
        if (features.hotlink_protection !== undefined) {
            const checked = features.hotlink_protection === 'on' ? ' checked' : '';
            html += '<tr><th>Hotlink Protection<br><span class="ccm-text-muted" style="font-weight: normal; font-size: 0.85em;">Prevent other sites from embedding your images</span></th>';
            html += '<td style="text-align: right;"><label class="ccm-toggle"><input type="checkbox" data-cf-setting="hotlink_protection"' + checked + '>';
            html += '<span class="ccm-toggle-slider"></span></label></td></tr>';
        }

        // Server-side Excludes (toggle)
        if (features.server_side_exclude !== undefined) {
            const checked = features.server_side_exclude === 'on' ? ' checked' : '';
            html += '<tr><th>Server-side Excludes<br><span class="ccm-text-muted" style="font-weight: normal; font-size: 0.85em;">Automatically hide specific content from suspicious visitors</span></th>';
            html += '<td style="text-align: right;"><label class="ccm-toggle"><input type="checkbox" data-cf-setting="server_side_exclude"' + checked + '>';
            html += '<span class="ccm-toggle-slider"></span></label></td></tr>';
        }

        // Privacy Pass (toggle)
        if (features.privacy_pass !== undefined) {
            const checked = features.privacy_pass === 'on' ? ' checked' : '';
            html += '<tr><th>Privacy Pass<br><span class="ccm-text-muted" style="font-weight: normal; font-size: 0.85em;">Reduce CAPTCHAs for visitors using Privacy Pass tokens</span></th>';
            html += '<td style="text-align: right;"><label class="ccm-toggle"><input type="checkbox" data-cf-setting="privacy_pass"' + checked + '>';
            html += '<span class="ccm-toggle-slider"></span></label></td></tr>';
        }

        // Challenge Passage TTL (dropdown)
        if (features.challenge_ttl !== undefined) {
            html += '<tr><th>Challenge Passage<br><span class="ccm-text-muted" style="font-weight: normal; font-size: 0.85em;">How long a visitor who passed a challenge can access your site</span></th>';
            html += '<td style="text-align: right;"><select data-cf-setting="challenge_ttl" class="ccm-cf-select">';
            const ttlOptions = [
                [300, '5 minutes'], [900, '15 minutes'], [1800, '30 minutes'],
                [2700, '45 minutes'], [3600, '1 hour'], [7200, '2 hours'],
                [10800, '3 hours'], [14400, '4 hours'], [28800, '8 hours'],
                [57600, '16 hours'], [86400, '1 day'], [604800, '1 week'],
                [2592000, '1 month'], [31536000, '1 year'],
            ];
            for (const [tval, tlabel] of ttlOptions) {
                const sel = features.challenge_ttl === tval ? ' selected' : '';
                html += '<option value="' + tval + '"' + sel + '>' + tlabel + '</option>';
            }
            html += '</select></td></tr>';
        }

        html += '</table>';
        container.innerHTML = html;

        // Bind controls
        bindCfSettingControls(container);
    }

    /**
     * Render SSL/TLS & Network Settings panel from zone features data.
     */
    function renderCfNetworkPanel(features) {
        const container = $('#cf-network-settings');
        if (!container) return;

        let html = '<table class="ccm-table">';

        // SSL Mode (dropdown)
        if (features.ssl !== undefined) {
            const modes = [
                ['off', 'Off'],
                ['flexible', 'Flexible'],
                ['full', 'Full'],
                ['strict', 'Full (Strict)'],
            ];
            html += '<tr><th>SSL/TLS Encryption Mode<br><span class="ccm-text-muted" style="font-weight: normal; font-size: 0.85em;">Encryption between visitors, Cloudflare, and your origin server</span></th>';
            html += '<td style="text-align: right;"><select data-cf-setting="ssl" class="ccm-cf-select">';
            for (const [val, label] of modes) {
                const sel = features.ssl === val ? ' selected' : '';
                html += '<option value="' + val + '"' + sel + '>' + label + '</option>';
            }
            html += '</select></td></tr>';
        }

        // Toggle settings for network panel
        const networkToggles = [
            { key: 'always_use_https', label: 'Always Use HTTPS', desc: 'Redirect all HTTP requests to HTTPS' },
            { key: 'automatic_https_rewrites', label: 'Automatic HTTPS Rewrites', desc: 'Fix mixed content by rewriting HTTP URLs to HTTPS' },
            { key: 'opportunistic_encryption', label: 'Opportunistic Encryption', desc: 'Allow browsers to use HTTP/2 over an unencrypted connection' },
            { key: 'ip_geolocation', label: 'IP Geolocation', desc: 'Include visitor country in CF-IPCountry header sent to your origin' },
            { key: 'opportunistic_onion', label: 'Onion Routing', desc: 'Allow routing traffic through the Tor network when available' },
            { key: 'early_hints', label: 'Early Hints (103)', desc: 'Speed up page loads by sending link headers before the full response' },
            { key: 'http2', label: 'HTTP/2', desc: 'Accelerate content delivery with HTTP/2 protocol' },
            { key: 'http3', label: 'HTTP/3 (QUIC)', desc: 'Enable next-generation protocol using QUIC for faster connections' },
            { key: '0rtt', label: '0-RTT Connection Resumption', desc: 'Improve performance for repeat visitors with zero round-trip time' },
            { key: 'brotli', label: 'Brotli Compression', desc: 'Compress content with Brotli for faster delivery' },
        ];

        for (const item of networkToggles) {
            const val = features[item.key];
            if (val === undefined) continue;
            const checked = val === 'on' ? ' checked' : '';
            html += '<tr><th>' + escHtml(item.label) + '<br><span class="ccm-text-muted" style="font-weight: normal; font-size: 0.85em;">' + escHtml(item.desc) + '</span></th>';
            html += '<td style="text-align: right;"><label class="ccm-toggle"><input type="checkbox" data-cf-setting="' + item.key + '"' + checked + '>';
            html += '<span class="ccm-toggle-slider"></span></label></td></tr>';
        }

        // Pseudo IPv4 (dropdown)
        if (features.pseudo_ipv4 !== undefined) {
            html += '<tr><th>Pseudo IPv4<br><span class="ccm-text-muted" style="font-weight: normal; font-size: 0.85em;">Add an IPv4 header for IPv6 visitors for compatibility with older systems</span></th>';
            html += '<td style="text-align: right;"><select data-cf-setting="pseudo_ipv4" class="ccm-cf-select">';
            const pv4Options = [
                ['off', 'Off'],
                ['add_header', 'Add Header'],
                ['overwrite_header', 'Overwrite Headers'],
            ];
            for (const [val, label] of pv4Options) {
                const sel = features.pseudo_ipv4 === val ? ' selected' : '';
                html += '<option value="' + val + '"' + sel + '>' + label + '</option>';
            }
            html += '</select></td></tr>';
        }

        html += '</table>';
        container.innerHTML = html;

        // Bind controls
        bindCfSettingControls(container);
    }

    /**
     * Load and render Cloudflare zone analytics.
     */
    async function loadCfAnalytics(container) {
        try {
            const res = await ajax('ccm_tools_cf_analytics');

            const d = res.data;
            const req = d.requests || {};
            const bw = d.bandwidth || {};
            const cacheRatio = req.all > 0 ? Math.round((req.cached / req.all) * 100) : 0;
            const bwCacheRatio = bw.all > 0 ? Math.round((bw.cached / bw.all) * 100) : 0;

            let html = '<div class="ccm-cf-analytics-grid">';

            // Requests card
            html += '<div class="ccm-cf-stat-card">';
            html += '<div class="ccm-cf-stat-number">' + formatNumber(req.all) + '</div>';
            html += '<div class="ccm-cf-stat-label">Total Requests</div>';
            html += '<div class="ccm-cf-stat-detail">';
            html += '<span class="ccm-success">' + formatNumber(req.cached) + ' cached</span> · ';
            html += '<span class="ccm-text-muted">' + formatNumber(req.uncached) + ' uncached</span>';
            html += '</div></div>';

            // Bandwidth card
            html += '<div class="ccm-cf-stat-card">';
            html += '<div class="ccm-cf-stat-number">' + formatBytes(bw.all) + '</div>';
            html += '<div class="ccm-cf-stat-label">Bandwidth</div>';
            html += '<div class="ccm-cf-stat-detail">';
            html += '<span class="ccm-success">' + formatBytes(bw.cached) + ' cached</span> · ';
            html += '<span class="ccm-text-muted">' + formatBytes(bw.uncached) + ' uncached</span>';
            html += '</div></div>';

            // Cache ratio card
            html += '<div class="ccm-cf-stat-card">';
            html += '<div class="ccm-cf-stat-number">' + cacheRatio + '%</div>';
            html += '<div class="ccm-cf-stat-label">Request Cache Ratio</div>';
            html += '<div class="ccm-cf-stat-bar"><div class="ccm-cf-stat-bar-fill" style="width: ' + cacheRatio + '%;"></div></div>';
            html += '<div class="ccm-cf-stat-detail">' + bwCacheRatio + '% bandwidth saved</div></div>';

            // Page views card
            html += '<div class="ccm-cf-stat-card">';
            html += '<div class="ccm-cf-stat-number">' + formatNumber(d.pageviews || 0) + '</div>';
            html += '<div class="ccm-cf-stat-label">Page Views</div>';
            html += '</div>';

            // Unique visitors card
            html += '<div class="ccm-cf-stat-card">';
            html += '<div class="ccm-cf-stat-number">' + formatNumber(d.uniques || 0) + '</div>';
            html += '<div class="ccm-cf-stat-label">Unique Visitors</div>';
            html += '</div>';

            // Threats card
            html += '<div class="ccm-cf-stat-card">';
            html += '<div class="ccm-cf-stat-number' + ((d.threats || 0) > 0 ? ' ccm-cf-stat-warning' : '') + '">' + formatNumber(d.threats || 0) + '</div>';
            html += '<div class="ccm-cf-stat-label">Threats Blocked</div>';
            html += '</div>';

            // Encrypted requests
            html += '<div class="ccm-cf-stat-card">';
            html += '<div class="ccm-cf-stat-number">' + formatNumber(req.ssl || 0) + '</div>';
            html += '<div class="ccm-cf-stat-label">Encrypted Requests</div>';
            html += '<div class="ccm-cf-stat-detail">' + (req.all > 0 ? Math.round((req.ssl / req.all) * 100) : 0) + '% of traffic over HTTPS</div>';
            html += '</div>';

            html += '</div>';
            container.innerHTML = html;
        } catch (err) {
            container.innerHTML = '<p class="ccm-error">Failed to load analytics: ' + escHtml(err.message) + '</p>';
        }
    }

    /**
     * Load and render Cloudflare DNS records table.
     */
    async function loadCfDnsRecords(container) {
        try {
            const res = await ajax('ccm_tools_cf_dns_records');

            const records = res.data.records || [];
            if (!records.length) {
                container.innerHTML = '<p class="ccm-text-muted">No DNS records found.</p>';
                return;
            }

            let html = '<div class="ccm-cf-dns-table-wrap"><table class="ccm-table ccm-cf-dns-table">';
            html += '<thead><tr><th>Type</th><th>Name</th><th>Content</th><th>TTL</th><th>Proxy</th></tr></thead>';
            html += '<tbody>';

            for (const r of records) {
                const ttlLabel = r.ttl === 1 ? 'Auto' : formatTtl(r.ttl);
                const proxyBadge = r.proxied
                    ? '<span class="ccm-cf-proxy-badge ccm-cf-proxy-on" title="Proxied through Cloudflare">☁ On</span>'
                    : '<span class="ccm-cf-proxy-badge ccm-cf-proxy-off" title="DNS only">☁ Off</span>';

                // Truncate long content (e.g. TXT/DKIM records)
                let content = r.content || '';
                const truncated = content.length > 50;
                const displayContent = truncated ? content.substring(0, 47) + '…' : content;

                html += '<tr>';
                html += '<td><span class="ccm-cf-dns-type ccm-cf-dns-type-' + escHtml(r.type).toLowerCase() + '">' + escHtml(r.type) + '</span></td>';
                html += '<td class="ccm-cf-dns-name">' + escHtml(r.name) + '</td>';
                html += '<td class="ccm-cf-dns-content"' + (truncated ? ' title="' + escHtml(content) + '"' : '') + '>' + escHtml(displayContent) + '</td>';
                html += '<td>' + escHtml(ttlLabel) + '</td>';
                html += '<td>' + proxyBadge + '</td>';
                html += '</tr>';
            }

            html += '</tbody></table></div>';
            container.innerHTML = html;
        } catch (err) {
            container.innerHTML = '<p class="ccm-error">Failed to load DNS records: ' + escHtml(err.message) + '</p>';
        }
    }

    /**
     * Format bytes into human-readable string.
     */
    function formatBytes(bytes) {
        if (bytes === 0) return '0 B';
        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        const i = Math.floor(Math.log(bytes) / Math.log(1024));
        return (bytes / Math.pow(1024, i)).toFixed(i > 0 ? 1 : 0) + ' ' + units[i];
    }

    /**
     * Format large numbers with commas.
     */
    function formatNumber(n) {
        return Number(n).toLocaleString();
    }

    /**
     * Format TTL seconds into human-readable string.
     */
    function formatTtl(seconds) {
        if (seconds < 60) return seconds + 's';
        if (seconds < 3600) return Math.round(seconds / 60) + 'm';
        if (seconds < 86400) return Math.round(seconds / 3600) + 'h';
        return Math.round(seconds / 86400) + 'd';
    }

    // ===================================
    // Redis Object Cache Functions
    // ===================================
    
    /**
     * Refresh Redis cache statistics display
     */
    async function refreshRedisStats() {
        const keysEl = $('#redis-stat-keys');
        const memoryEl = $('#redis-stat-memory');
        const groupsEl = $('#redis-stat-groups');
        const ttlEl = $('#redis-stat-ttl');
        
        // Only proceed if stats elements exist
        if (!keysEl && !memoryEl && !groupsEl && !ttlEl) {
            return;
        }
        
        try {
            const response = await ajax('ccm_tools_redis_get_stats');
            const stats = response.data.stats;
            
            // Update the stat values
            if (keysEl) {
                keysEl.textContent = Number(stats.keys).toLocaleString();
            }
            if (memoryEl) {
                memoryEl.textContent = stats.memory_used || 'N/A';
            }
            if (groupsEl) {
                groupsEl.textContent = Number(stats.groups).toLocaleString();
            }
            if (ttlEl) {
                ttlEl.textContent = stats.avg_ttl || 'N/A';
            }
        } catch (error) {
            console.error('Failed to refresh Redis stats:', error);
        }
    }
    
    /**
     * Live-update the Active Configuration table from AJAX response data.
     * @param {Object} config  Keys are constant names, values are {value, defined}
     */
    function updateRedisActiveConfigTable(config) {
        const table = document.getElementById('redis-active-config-table');
        if (!table) return;

        const tbody = table.querySelector('tbody');
        if (!tbody) return;

        // Rebuild rows from the response — matches PHP rendering logic exactly
        let html = '';
        for (const [constant, info] of Object.entries(config)) {
            const val = info.value !== '' && info.value !== null && info.value !== undefined ? String(info.value) : '';

            // PHP: if (empty($item['value']) && !$item['defined']) continue;
            if (!val && !info.defined) continue;

            const badge = info.defined
                ? '<span class="ccm-badge ccm-badge-info">wp-config.php</span>'
                : '<span class="ccm-badge">Plugin Settings</span>';

            html += '<tr>'
                  + '<td><code>' + escapeHtml(constant) + '</code></td>'
                  + '<td>' + escapeHtml(val || '') + '</td>'
                  + '<td>' + badge + '</td>'
                  + '</tr>';
        }
        tbody.innerHTML = html;
    }

    /**
     * Initialize Redis Object Cache page handlers
     */
    function initRedisObjectCacheHandlers() {
        const enableBtn = $('#redis-enable');
        const disableBtn = $('#redis-disable');
        const flushBtn = $('#redis-flush');
        const testBtn = $('#redis-test');
        const settingsForm = $('#redis-settings-form');
        const addConfigBtn = $('#add-to-wp-config');
        const schemeSelect = $('#redis-scheme');
        const updateDropinBtn = $('#redis-update-dropin');
        
        // Update Drop-In (when version mismatch detected)
        if (updateDropinBtn) {
            updateDropinBtn.addEventListener('click', () => {
                showConfirmModal(
                    'This will force-replace the existing object-cache.php drop-in. Continue?',
                    async () => {
                        updateDropinBtn.disabled = true;
                        updateDropinBtn.innerHTML = '<div class="ccm-spinner ccm-spinner-small"></div> Updating...';
                        try {
                            const response = await ajax('ccm_tools_redis_enable', { force: 'true' });
                            showNotification(response.data.message || 'Drop-in updated successfully!', 'success');
                            setTimeout(() => location.reload(), 1500);
                        } catch (error) {
                            showNotification(error.message, 'error');
                            updateDropinBtn.disabled = false;
                            updateDropinBtn.innerHTML = 'Update Drop-In';
                        }
                    },
                    'Update Drop-In'
                );
            });
        }
        
        // Generate Key Prefix/Salt
        const generateSaltBtn = $('#redis-generate-salt');
        if (generateSaltBtn) {
            generateSaltBtn.addEventListener('click', () => {
                const host = location.hostname.replace(/[^a-z0-9]/gi, '_').toLowerCase();
                const rand = Array.from(crypto.getRandomValues(new Uint8Array(4)))
                    .map(b => b.toString(16).padStart(2, '0')).join('');
                const saltInput = $('#redis-key-salt');
                if (saltInput) {
                    saltInput.value = host + '_' + rand + '_';
                    saltInput.focus();
                }
            });
        }
        
        // Enable Redis Object Cache
        if (enableBtn) {
            enableBtn.addEventListener('click', async () => {
                const force = enableBtn.dataset.force === 'true';
                
                if (force && !confirm('This will replace the existing object-cache.php from another plugin. Continue?')) {
                    return;
                }
                
                enableBtn.disabled = true;
                enableBtn.innerHTML = '<div class="ccm-spinner ccm-spinner-small"></div> Enabling...';
                
                try {
                    const response = await ajax('ccm_tools_redis_enable', { force: force ? 'true' : 'false' });
                    showNotification(response.data.message, 'success');
                    if (response.data.reload) {
                        setTimeout(() => location.reload(), 1500);
                    }
                } catch (error) {
                    showNotification(error.message, 'error');
                    enableBtn.disabled = false;
                    enableBtn.innerHTML = force ? 'Replace & Enable' : 'Enable Object Cache';
                }
            });
        }
        
        // Disable Redis Object Cache
        if (disableBtn) {
            disableBtn.addEventListener('click', async () => {
                if (!confirm('Are you sure you want to disable the Redis Object Cache?')) {
                    return;
                }
                
                disableBtn.disabled = true;
                disableBtn.innerHTML = '<div class="ccm-spinner ccm-spinner-small"></div> Disabling...';
                
                try {
                    const response = await ajax('ccm_tools_redis_disable');
                    showNotification(response.data.message, 'success');
                    if (response.data.reload) {
                        setTimeout(() => location.reload(), 1500);
                    }
                } catch (error) {
                    showNotification(error.message, 'error');
                    disableBtn.disabled = false;
                    disableBtn.innerHTML = 'Disable Object Cache';
                }
            });
        }
        
        // Flush Redis Cache
        if (flushBtn) {
            flushBtn.addEventListener('click', async () => {
                flushBtn.disabled = true;
                flushBtn.innerHTML = '<div class="ccm-spinner ccm-spinner-small"></div> Flushing...';
                
                try {
                    const response = await ajax('ccm_tools_redis_flush');
                    showNotification(response.data.message, 'success');
                    
                    // Refresh stats after flush
                    await refreshRedisStats();
                } catch (error) {
                    showNotification(error.message, 'error');
                } finally {
                    flushBtn.disabled = false;
                    flushBtn.innerHTML = 'Flush Cache';
                }
            });
        }
        
        // Test Redis Connection
        if (testBtn) {
            testBtn.addEventListener('click', async () => {
                testBtn.disabled = true;
                testBtn.innerHTML = '<div class="ccm-spinner ccm-spinner-small"></div> Testing...';
                
                try {
                    const response = await ajax('ccm_tools_redis_test');
                    showNotification(response.data.message, 'success');
                } catch (error) {
                    showNotification(error.message, 'error');
                } finally {
                    testBtn.disabled = false;
                    testBtn.innerHTML = 'Test Connection';
                }
            });
        }
        
        // Connection type toggle (show/hide TCP vs Unix socket fields)
        if (schemeSelect) {
            const tcpSettings = $('#tcp-settings');
            const unixSettings = $('#unix-settings');
            
            const updateSchemeVisibility = () => {
                const scheme = schemeSelect.value;
                if (tcpSettings) tcpSettings.style.display = scheme === 'unix' ? 'none' : 'flex';
                if (unixSettings) unixSettings.style.display = scheme === 'unix' ? 'block' : 'none';
            };
            
            schemeSelect.addEventListener('change', updateSchemeVisibility);
            updateSchemeVisibility(); // Initial state
        }
        
        // Save Settings Form
        if (settingsForm) {
            settingsForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                
                const submitBtn = settingsForm.querySelector('button[type="submit"]');
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<div class="ccm-spinner ccm-spinner-small"></div> Saving...';
                }
                
                try {
                    // Collect form data
                    const formData = new FormData(settingsForm);
                    const data = {};
                    
                    // List of checkbox fields
                    const checkboxFields = [
                        'selective_flush',
                        'async_flush',
                        'wc_cache_cart_fragments',
                        'wc_persistent_cart',
                        'wc_session_cache'
                    ];
                    
                    formData.forEach((value, key) => {
                        if (checkboxFields.includes(key)) {
                            // Checkbox - convert to boolean string
                            data[key] = 'true';
                        } else if (key === 'disable_comment') {
                            // Inverted: checkbox checked = footnote ON = disable_comment false
                            data['disable_comment'] = 'false';
                        } else {
                            data[key] = value;
                        }
                    });
                    
                    // Handle unchecked checkboxes
                    checkboxFields.forEach(field => {
                        if (!formData.has(field)) {
                            data[field] = 'false';
                        }
                    });
                    
                    // Handle disable_comment inversion when unchecked
                    if (!formData.has('disable_comment')) {
                        data['disable_comment'] = 'true';
                    }
                    
                    const response = await ajax('ccm_tools_redis_save_settings', data);
                    showNotification(response.data.message, 'success');

                    // Live-update the Active Configuration table from the AJAX response
                    if (response.data.active_config) {
                        updateRedisActiveConfigTable(response.data.active_config);
                    }
                } catch (error) {
                    showNotification(error.message, 'error');
                } finally {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = 'Save Settings';
                    }
                }
            });
        }
        
        // Add to wp-config.php
        if (addConfigBtn) {
            addConfigBtn.addEventListener('click', async () => {
                if (!confirm('This will add Redis configuration constants to your wp-config.php file. A backup will be created. Continue?')) {
                    return;
                }
                
                addConfigBtn.disabled = true;
                addConfigBtn.innerHTML = '<div class="ccm-spinner ccm-spinner-small"></div> Adding...';
                
                try {
                    const response = await ajax('ccm_tools_redis_add_config');
                    showNotification(response.data.message, 'success');
                    setTimeout(() => location.reload(), 1500);
                } catch (error) {
                    showNotification(error.message, 'error');
                    addConfigBtn.disabled = false;
                    addConfigBtn.innerHTML = 'Add to wp-config.php';
                }
            });
        }
    }
    
    // ===================================
    // Uploads Backup Functions
    // ===================================
    
    let backupStopped = false;
    
    /**
     * Initialize uploads backup event handlers
     */
    function initUploadsBackupHandlers() {
        const startBtn = $('#start-uploads-backup');
        const cancelBtn = $('#cancel-uploads-backup');
        const downloadBtn = $('#download-backup');
        const deleteBtn = $('#delete-backup');
        
        // Load initial info
        loadUploadsInfo();
        
        // Check for existing backup status
        checkBackupStatus();
        
        if (startBtn) {
            startBtn.addEventListener('click', startUploadsBackup);
        }
        
        if (cancelBtn) {
            cancelBtn.addEventListener('click', async () => {
                if (confirm('Are you sure you want to cancel the backup? The partial file will be deleted.')) {
                    await cancelBackup();
                }
            });
        }
        
        if (downloadBtn) {
            downloadBtn.addEventListener('click', (e) => {
                e.preventDefault();
                downloadBackup();
            });
        }
        
        if (deleteBtn) {
            deleteBtn.addEventListener('click', async () => {
                if (confirm('Are you sure you want to delete this backup? This cannot be undone.')) {
                    await deleteBackup();
                }
            });
        }
    }
    
    /**
     * Load uploads folder information
     */
    async function loadUploadsInfo() {
        const infoEl = $('#backup-info');
        if (!infoEl) return;
        
        try {
            const response = await ajax('ccm_tools_check_zip_available');
            
            if (response && response.data && response.data.zip_available) {
                infoEl.innerHTML = `
                    <p><span class="ccm-icon">📁</span> <strong>Uploads folder:</strong> ${response.data.file_count.toLocaleString()} files (${response.data.uploads_size})</p>
                `;
            } else if (response) {
                infoEl.innerHTML = `<p class="ccm-warning"><span class="ccm-icon">⚠</span> ZipArchive not available on this server.</p>`;
            }
        } catch (error) {
            console.error('Error loading uploads info:', error);
            infoEl.innerHTML = `<p class="ccm-error"><span class="ccm-icon">✕</span> Failed to load uploads information.</p>`;
        }
    }
    
    /**
     * Check for existing backup status
     */
    async function checkBackupStatus() {
        try {
            const response = await ajax('ccm_tools_get_backup_status');
            const data = response.data || {};
            
            if (data.status === 'complete' && data.download_ready) {
                showBackupComplete(data.backup_size);
            }
            // Note: We no longer auto-resume in_progress backups on page load
            // User must click "Create Backup" to start a new backup
            // This prevents unexpected backup resumption from stale state
        } catch (error) {
            console.error('Error checking backup status:', error);
        }
    }
    
    /**
     * Start uploads backup
     */
    async function startUploadsBackup() {
        const startBtn = $('#start-uploads-backup');
        const cancelBtn = $('#cancel-uploads-backup');
        
        if (startBtn) {
            startBtn.disabled = true;
            startBtn.innerHTML = '<div class="ccm-spinner ccm-spinner-small"></div> Starting...';
        }
        
        backupStopped = false;
        
        try {
            const response = await ajax('ccm_tools_start_uploads_backup');
            const data = response.data || {};
            
            showNotification('Backup started. Processing ' + data.total_files + ' files...', 'info');
            
            // Update UI
            const totalEl = $('#backup-total');
            if (totalEl) totalEl.textContent = data.total_files;
            
            showBackupProgress();
            
            if (cancelBtn) cancelBtn.style.display = 'inline-flex';
            if (startBtn) startBtn.style.display = 'none';
            
            // Start processing batches
            await processBackupBatch();
            
        } catch (error) {
            showNotification(error.message || 'Failed to start backup.', 'error');
            if (startBtn) {
                startBtn.disabled = false;
                startBtn.textContent = 'Create Backup';
            }
        }
    }
    
    /**
     * Process backup batch
     */
    async function processBackupBatch() {
        if (backupStopped) {
            return;
        }
        
        try {
            const response = await ajax('ccm_tools_process_backup_batch');
            const data = response.data || {};
            
            // Update progress UI
            const currentEl = $('#backup-current');
            const totalEl = $('#backup-total');
            const percentEl = $('#backup-percent');
            const progressBar = $('#backup-progress-bar');
            
            if (currentEl) currentEl.textContent = data.processed_files;
            if (totalEl) totalEl.textContent = data.total_files;
            if (percentEl) percentEl.textContent = data.percent;
            if (progressBar) progressBar.style.width = data.percent + '%';
            
            if (data.status === 'complete') {
                showBackupComplete(data.backup_size);
                showNotification('Backup completed successfully!', 'success');
            } else {
                // Process next batch
                await processBackupBatch();
            }
            
        } catch (error) {
            showNotification(error.message || 'Backup failed.', 'error');
            resetBackupUI();
        }
    }
    
    /**
     * Show backup progress UI
     */
    function showBackupProgress() {
        const progressEl = $('#backup-progress');
        const completeEl = $('#backup-complete');
        
        if (progressEl) progressEl.style.display = 'block';
        if (completeEl) completeEl.style.display = 'none';
    }
    
    /**
     * Show backup complete UI
     */
    function showBackupComplete(size) {
        const progressEl = $('#backup-progress');
        const completeEl = $('#backup-complete');
        const startBtn = $('#start-uploads-backup');
        const cancelBtn = $('#cancel-uploads-backup');
        const sizeEl = $('#backup-size');
        
        if (progressEl) progressEl.style.display = 'none';
        if (completeEl) completeEl.style.display = 'block';
        if (startBtn) startBtn.style.display = 'none';
        if (cancelBtn) cancelBtn.style.display = 'none';
        if (sizeEl) sizeEl.textContent = size;
    }
    
    /**
     * Reset backup UI to initial state
     */
    function resetBackupUI() {
        const progressEl = $('#backup-progress');
        const completeEl = $('#backup-complete');
        const startBtn = $('#start-uploads-backup');
        const cancelBtn = $('#cancel-uploads-backup');
        const currentEl = $('#backup-current');
        const totalEl = $('#backup-total');
        const percentEl = $('#backup-percent');
        const progressBar = $('#backup-progress-bar');
        
        if (progressEl) progressEl.style.display = 'none';
        if (completeEl) completeEl.style.display = 'none';
        if (startBtn) {
            startBtn.style.display = 'inline-flex';
            startBtn.disabled = false;
            startBtn.textContent = 'Create Backup';
        }
        if (cancelBtn) cancelBtn.style.display = 'none';
        
        // Reset progress
        if (currentEl) currentEl.textContent = '0';
        if (totalEl) totalEl.textContent = '0';
        if (percentEl) percentEl.textContent = '0';
        if (progressBar) progressBar.style.width = '0%';
    }
    
    /**
     * Cancel backup (during processing)
     */
    async function cancelBackup() {
        backupStopped = true;
        
        const cancelBtn = $('#cancel-uploads-backup');
        if (cancelBtn) {
            cancelBtn.disabled = true;
            cancelBtn.textContent = 'Cancelling...';
        }
        
        try {
            await ajax('ccm_tools_cancel_backup');
            showNotification('Backup cancelled and file deleted.', 'info');
        } catch (error) {
            console.error('Error cancelling backup:', error);
            showNotification('Error cancelling backup.', 'error');
        }
        
        resetBackupUI();
    }
    
    /**
     * Delete completed backup
     */
    async function deleteBackup() {
        const deleteBtn = $('#delete-backup');
        if (deleteBtn) {
            deleteBtn.disabled = true;
            deleteBtn.textContent = 'Deleting...';
        }
        
        try {
            await ajax('ccm_tools_cancel_backup'); // Same action - deletes file and clears state
            showNotification('Backup deleted successfully.', 'success');
            resetBackupUI();
        } catch (error) {
            console.error('Error deleting backup:', error);
            showNotification('Error deleting backup.', 'error');
            if (deleteBtn) {
                deleteBtn.disabled = false;
                deleteBtn.textContent = 'Delete Backup';
            }
        }
    }
    
    /**
     * Download backup file
     */
    function downloadBackup() {
        // Create a form to submit download request with nonce
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = ccmToolsData.ajax_url;
        form.style.display = 'none';
        
        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'ccm_tools_download_backup';
        form.appendChild(actionInput);
        
        const nonceInput = document.createElement('input');
        nonceInput.type = 'hidden';
        nonceInput.name = 'nonce';
        nonceInput.value = ccmToolsData.nonce;
        form.appendChild(nonceInput);
        
        document.body.appendChild(form);
        form.submit();
        document.body.removeChild(form);
    }

})();
