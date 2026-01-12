/**
 * CCM Tools - Modern Vanilla JavaScript
 * Pure JS without jQuery or other dependencies
 * Version: 7.2.8
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
     * Show loading spinner in element
     * @param {Element|string} target - Target element or selector
     */
    function showSpinner(target) {
        const el = typeof target === 'string' ? $(target) : target;
        if (el) {
            el.innerHTML = '<div class="ccm-spinner"></div>';
        }
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
     * Show result message
     * @param {Element|string} target - Target element or selector
     * @param {string} message - Message to display
     * @param {string} type - Message type (success, error, info, warning)
     */
    function showMessage(target, message, type = 'info') {
        const el = typeof target === 'string' ? $(target) : target;
        if (el) {
            const icons = {
                success: '✓',
                error: '✗',
                warning: '⚠',
                info: 'ℹ'
            };
            el.innerHTML = `<p class="ccm-${type}"><span class="ccm-icon">${icons[type]}</span>${escapeHtml(message)}</p>`;
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
     * Escape HTML special characters
     * @param {string} str - String to escape
     * @returns {string}
     */
    function escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

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
                throw new Error(result?.data?.message || result?.data || 'Unknown error occurred');
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

    // ===================================
    // Progressive Table Operations
    // ===================================

    /**
     * Progressive table conversion
     */
    async function convertTablesProgressively() {
        const resultBox = $('#resultBox');
        if (!resultBox) return;
        
        // Initialize progress display
        resultBox.innerHTML = `
            <div id="progress-info">
                <div class="ccm-spinner" style="margin: 10px 0;"></div>
                <p>Converting tables: <span id="progress-count">0</span>/<span id="total-count">0</span></p>
                <div class="ccm-progress-bar"><div class="ccm-progress-fill" style="width: 0%"></div></div>
            </div>
        `;
        
        try {
            // Get tables list
            const response = await ajax('ccm_tools_get_tables_to_convert');
            
            if (!response?.data) {
                resultBox.innerHTML = '<p class="ccm-error">Error: Could not get tables list</p>';
                return;
            }
            
            const tablesInfo = response.data;
            
            if (tablesInfo.total_count === 0) {
                resultBox.innerHTML = '<p><span class="ccm-icon ccm-info">ℹ</span>All tables up to date. Nothing to change</p>';
                return;
            }
            
            let currentIndex = 0;
            let tablesChanged = 0;
            
            // Update layout
            resultBox.innerHTML = `
                <div id="progress-info">
                    <div class="ccm-spinner" style="margin: 10px 0;"></div>
                    <p>Converting tables: <span id="progress-count">0</span>/<span id="total-count">${tablesInfo.total_count}</span></p>
                    <div class="ccm-progress-bar"><div class="ccm-progress-fill" style="width: 0%"></div></div>
                </div>
                <p><span class="ccm-icon ccm-info">ℹ</span>${tablesInfo.total_count} Tables Found</p>
                <table class="ccm-table">
                    <thead><tr><th>Table</th><th>Engine</th><th>Collation</th><th>Status</th></tr></thead>
                    <tbody></tbody>
                </table>
            `;
            
            const tbody = $('tbody', resultBox);
            
            // Process tables one by one
            for (const table of tablesInfo.tables) {
                try {
                    const tableResponse = await ajax('ccm_tools_convert_single_table', { table_name: table.TABLE_NAME });
                    const result = tableResponse.data;
                    
                    const rowClass = result.success ? 'success' : 'error';
                    const statusIcon = result.success ? '✓' : '✗';
                    
                    if (result.success && result.changes_made) {
                        tablesChanged++;
                    }
                    
                    const originalEngine = result.original_engine || 'Unknown';
                    const newEngine = result.new_engine || 'Unknown';
                    const originalCollation = result.original_collation || 'Unknown';
                    const newCollation = result.new_collation || 'Unknown';
                    
                    const engineIcon = originalEngine === newEngine ? 'info' : 'warning';
                    const collationIcon = originalCollation === newCollation ? 'info' : 'warning';
                    
                    tbody.insertAdjacentHTML('beforeend', `
                        <tr class="${rowClass}">
                            <td>${escapeHtml(result.table_name || 'Unknown')}</td>
                            <td>${escapeHtml(originalEngine)} <span class="ccm-icon ccm-${engineIcon}">→</span> ${escapeHtml(newEngine)}</td>
                            <td>${escapeHtml(originalCollation)} <span class="ccm-icon ccm-${collationIcon}">→</span> ${escapeHtml(newCollation)}</td>
                            <td><span class="ccm-icon ccm-${rowClass}">${statusIcon}</span></td>
                        </tr>
                    `);
                } catch (error) {
                    tbody.insertAdjacentHTML('beforeend', `
                        <tr class="error">
                            <td>${escapeHtml(table.TABLE_NAME)}</td>
                            <td>Error: ${escapeHtml(error.message)}</td>
                            <td>N/A</td>
                            <td><span class="ccm-icon ccm-error">✗</span></td>
                        </tr>
                    `);
                }
                
                currentIndex++;
                const progress = Math.round((currentIndex / tablesInfo.total_count) * 100);
                
                const progressCount = $('#progress-count');
                const progressFill = $('.ccm-progress-fill');
                
                if (progressCount) progressCount.textContent = currentIndex;
                if (progressFill) progressFill.style.width = `${progress}%`;
                
                // Small delay to prevent overwhelming server
                await new Promise(resolve => setTimeout(resolve, 100));
            }
            
            // Complete
            const progressInfo = $('#progress-info');
            if (progressInfo) {
                removeSpinner(progressInfo);
                progressInfo.querySelector('p').innerHTML = '<span class="ccm-icon ccm-success">✓</span>Conversion completed!';
            }
            
            resultBox.insertAdjacentHTML('afterbegin', `<p><span class="ccm-icon ccm-info">ℹ</span>${tablesChanged} Tables Changed</p>`);
            
        } catch (error) {
            resultBox.innerHTML = `<p class="ccm-error">Error getting tables list: ${escapeHtml(error.message)}</p>`;
        }
    }

    /**
     * Progressive database optimization
     */
    async function optimizeDatabaseProgressively() {
        const resultBox = $('#resultBox');
        if (!resultBox) return;
        
        // Initialize progress display
        resultBox.innerHTML = `
            <div id="progress-info">
                <div class="ccm-spinner" style="margin: 10px 0;"></div>
                <p>Optimizing database: <span id="progress-count">0</span>/<span id="total-count">0</span></p>
                <div class="ccm-progress-bar"><div class="ccm-progress-fill" style="width: 0%"></div></div>
            </div>
        `;
        
        try {
            // Step 1: Initial setup
            const setupResponse = await ajax('ccm_tools_optimize_initial_setup');
            
            let setupHtml = '<div id="setup-results">';
            
            if (setupResponse?.data?.success) {
                setupHtml += '<p><span class="ccm-icon ccm-success">✓</span>Initial optimization setup completed</p>';
                if (setupResponse.data.messages?.length > 0) {
                    setupResponse.data.messages.forEach(message => {
                        setupHtml += `<p><span class="ccm-icon ccm-info">ℹ</span>${escapeHtml(message)}</p>`;
                    });
                }
            } else {
                const errorMsg = setupResponse?.data?.message || 'Unknown error during initial setup';
                setupHtml += `<p><span class="ccm-icon ccm-error">✗</span>Initial setup failed: ${escapeHtml(errorMsg)}</p>`;
                resultBox.innerHTML = setupHtml + '</div>';
                return;
            }
            
            setupHtml += '</div>';
            
            // Step 2: Get tables to optimize
            const tablesResponse = await ajax('ccm_tools_get_tables_to_optimize');
            
            if (!tablesResponse?.data?.tables) {
                resultBox.innerHTML = setupHtml + '<p class="ccm-error">Error: Could not get tables list</p>';
                return;
            }
            
            const tablesInfo = tablesResponse.data;
            
            if (tablesInfo.total_count === 0) {
                resultBox.innerHTML = setupHtml + '<p><span class="ccm-icon ccm-info">ℹ</span>No tables found to optimize</p>';
                return;
            }
            
            let currentIndex = 0;
            let tablesOptimized = 0;
            
            // Update layout
            resultBox.innerHTML = `
                <div id="progress-info">
                    <div class="ccm-spinner" style="margin: 10px 0;"></div>
                    <p>Optimizing database: <span id="progress-count">0</span>/<span id="total-count">${tablesInfo.total_count}</span></p>
                    <div class="ccm-progress-bar"><div class="ccm-progress-fill" style="width: 0%"></div></div>
                </div>
                ${setupHtml}
                <p><span class="ccm-icon ccm-info">ℹ</span>${tablesInfo.total_count} Tables Found</p>
                <table class="ccm-table">
                    <thead><tr><th>Table</th><th>Optimization</th><th>Collation</th><th>Status</th></tr></thead>
                    <tbody></tbody>
                </table>
            `;
            
            const tbody = $('tbody', resultBox);
            
            // Process tables
            for (const tableName of tablesInfo.tables) {
                try {
                    const tableResponse = await ajax('ccm_tools_optimize_single_table', { table_name: tableName });
                    const result = tableResponse.data;
                    
                    const rowClass = result.success ? 'success' : 'error';
                    const statusIcon = result.success ? '✓' : '✗';
                    
                    if (result.success) tablesOptimized++;
                    
                    const originalCollation = result.original_collation || 'Unknown';
                    const newCollation = result.new_collation || 'Unknown';
                    const collationIcon = result.collation_updated ? 'warning' : 'info';
                    
                    let optimizationMsg = '';
                    if (result.success && result.messages?.length > 0) {
                        optimizationMsg = result.messages.join('; ');
                    } else if (result.success) {
                        optimizationMsg = 'Optimized successfully';
                    } else {
                        optimizationMsg = result.message || 'Failed';
                    }
                    
                    tbody.insertAdjacentHTML('beforeend', `
                        <tr class="${rowClass}">
                            <td>${escapeHtml(result.table_name || tableName)}</td>
                            <td>${escapeHtml(optimizationMsg)}</td>
                            <td>${escapeHtml(originalCollation)} <span class="ccm-icon ccm-${collationIcon}">→</span> ${escapeHtml(newCollation)}</td>
                            <td><span class="ccm-icon ccm-${rowClass}">${statusIcon}</span></td>
                        </tr>
                    `);
                } catch (error) {
                    tbody.insertAdjacentHTML('beforeend', `
                        <tr class="error">
                            <td>${escapeHtml(tableName)}</td>
                            <td>Error: ${escapeHtml(error.message)}</td>
                            <td>N/A</td>
                            <td><span class="ccm-icon ccm-error">✗</span></td>
                        </tr>
                    `);
                }
                
                currentIndex++;
                const progress = Math.round((currentIndex / tablesInfo.total_count) * 100);
                
                const progressCount = $('#progress-count');
                const progressFill = $('.ccm-progress-fill');
                
                if (progressCount) progressCount.textContent = currentIndex;
                if (progressFill) progressFill.style.width = `${progress}%`;
                
                await new Promise(resolve => setTimeout(resolve, 200));
            }
            
            // Complete
            const progressInfo = $('#progress-info');
            if (progressInfo) {
                removeSpinner(progressInfo);
                progressInfo.querySelector('p').innerHTML = '<span class="ccm-icon ccm-success">✓</span>Optimization completed!';
            }
            
            resultBox.insertAdjacentHTML('afterbegin', `<p><span class="ccm-icon ccm-info">ℹ</span>${tablesOptimized} Tables Optimized</p>`);
            
        } catch (error) {
            resultBox.innerHTML = `<p class="ccm-error">Error: ${escapeHtml(error.message)}</p>`;
        }
    }

    /**
     * Initialize optimization options on database page
     */
    async function initOptimizationOptions() {
        const optionsContainer = $('#optimization-options');
        const runButton = $('#run-optimizations');
        const selectSafeButton = $('#select-all-safe');
        const deselectAllButton = $('#deselect-all');
        const resultsBox = $('#optimization-results');
        
        if (!optionsContainer) return;
        
        try {
            // Load options and stats
            const response = await ajax('ccm_tools_get_optimization_options');
            
            if (!response?.data?.options) {
                optionsContainer.innerHTML = '<p class="ccm-error">Failed to load optimization options</p>';
                return;
            }
            
            const { options, stats } = response.data;
            
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
            
            // Enable run button
            if (runButton) {
                runButton.disabled = false;
            }
            
            // Event handlers
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
                        const opt = options[optKey];
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
            
        } catch (error) {
            optionsContainer.innerHTML = `<p class="ccm-error">Error loading options: ${escapeHtml(error.message)}</p>`;
        }
    }
    
    /**
     * Get the statistic value for an optimization option
     */
    function getStatForOption(optKey, stats) {
        const mapping = {
            'clear_transients': stats.transients,
            'optimize_tables': stats.table_count,
            'update_collation': stats.table_count,
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
        
        for (const task of selected) {
            const row = $(`#opt-row-${task.key}`);
            if (row) {
                // Update row to show running
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
                
                // Update row with result
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
                
                // Update row with error
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
        
        // Refresh stats after a short delay
        setTimeout(() => initOptimizationOptions(), 1000);
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
                if (confirm(ccmToolsData.i18n.confirmAddHtaccess)) {
                    const options = getSelectedHtaccessOptions();
                    makeAjaxRequest('ccm_tools_add_htaccess', null, { options: options });
                }
            }
            
            // Update htaccess
            if (e.target.id === 'htupdate' || e.target.closest('#htupdate')) {
                e.preventDefault();
                if (confirm('Update .htaccess optimizations with new settings?')) {
                    const options = getSelectedHtaccessOptions();
                    makeAjaxRequest('ccm_tools_update_htaccess', null, { options: options });
                }
            }
            
            // Remove htaccess
            if (e.target.id === 'htremove' || e.target.closest('#htremove')) {
                e.preventDefault();
                if (confirm(ccmToolsData.i18n.confirmRemoveHtaccess)) {
                    makeAjaxRequest('ccm_tools_remove_htaccess');
                }
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
        // Database Tools
        const ctButton = $('#ct');
        
        if (ctButton) {
            ctButton.addEventListener('click', (e) => {
                e.preventDefault();
                if (confirm(ccmToolsData.i18n.confirmConvert)) {
                    convertTablesProgressively();
                }
            });
        }
        
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
                        window.location.href = window.location.href.split('#')[0] + '&nocache=' + Date.now();
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
                        window.location.href = window.location.href.split('#')[0] + '&nocache=' + Date.now();
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
                        window.location.href = window.location.href.split('#')[0] + '&nocache=' + Date.now();
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
            updateMemoryLimit.addEventListener('click', async () => {
                const memorySelect = $('#memory-limit');
                if (!memorySelect) return;
                
                const newLimit = memorySelect.value;
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
                
                if (data.html) {
                    ttfbResult.innerHTML = data.html;
                } else if (data.time) {
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
            
            if (data.formatted_content || data.content) {
                const content = data.formatted_content || data.content;
                const highlightClass = highlightEnabled ? 'highlight-enabled' : '';
                logViewer.innerHTML = `<pre id="error-log-content" class="${highlightClass}">${content}</pre>`;
            } else if (data.error) {
                logViewer.innerHTML = `<p class="ccm-error">${escapeHtml(data.error)}</p>`;
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
    // Initialize
    // ===================================

    ready(() => {
        // Check if we're on CCM Tools page
        if (!document.querySelector('.ccm-tools')) return;
        
        // Initialize event handlers
        initEventHandlers();
        
        // Mark front page rows
        const frontPageIndicators = $$('.ccm-front-page-indicator');
        frontPageIndicators.forEach(indicator => {
            const row = indicator.closest('tr');
            if (row) row.classList.add('ccm-front-page-row');
        });
    });

})();
