/**
 * CCM Tools - Modern Vanilla JavaScript
 * Pure JS without jQuery or other dependencies
 * Version: 7.6.9
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
                document.removeEventListener('keydown', handleEscape);
            }
        };
        document.addEventListener('keydown', handleEscape);
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
     * Stop WebP stats auto-refresh
     */
    function stopWebPStatsRefresh() {
        if (webpStatsRefreshInterval) {
            clearInterval(webpStatsRefreshInterval);
            webpStatsRefreshInterval = null;
        }
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
    }

    /**
     * Format bytes to human readable
     */
    function formatBytes(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
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
                use_picture_tags: $('#webp-picture-tags')?.checked ? '1' : '0',
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
            { checkbox: '#perf-preconnect', detail: '#perf-preconnect' },
            { checkbox: '#perf-dns-prefetch', detail: '#perf-dns-prefetch' },
            { checkbox: '#perf-lcp-preload', detail: '#perf-lcp-preload' },
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
            const response = await ajax('ccm_tools_detect_scripts', {});
            
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
                    <strong>Found ${stats.total} script${stats.total !== 1 ? 's' : ''}:</strong>
                </p>
                <div style="display: flex; gap: var(--ccm-space-md); flex-wrap: wrap; margin-bottom: var(--ccm-space-md);">
                    <span class="ccm-error">❌ ${stats.should_exclude} to exclude</span>
                    <span class="ccm-success">✓ ${stats.safe_to_defer} safe to defer</span>
                    ${stats.already_deferred > 0 ? `<span class="ccm-info">↻ ${stats.already_deferred} already deferred</span>` : ''}
                </div>
            `;
            
            // Category labels and icons
            const categoryLabels = {
                jquery: '⚠️ jQuery (DO NOT defer)',
                wp_core: '⚠️ WordPress Core (DO NOT defer)',
                theme: '🎨 Theme Scripts',
                plugins: '🔌 Plugin Scripts',
                third_party: '🌐 Third-Party Scripts',
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
                        statusIcon = '<span class="ccm-error" title="Should exclude">❌</span>';
                    } else if (isExcluded) {
                        statusIcon = '<span class="ccm-warning" title="Currently excluded">⊘</span>';
                    } else {
                        statusIcon = '<span class="ccm-success" title="Safe to defer">✓</span>';
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
        initEventHandlers();
        
        // Initialize WebP converter handlers if on WebP page
        if ($('#webp-settings-form') || $('#start-bulk-conversion')) {
            initWebPConverterHandlers();
        }
        
        // Initialize Performance Optimizer handlers if on perf page
        if ($('#save-perf-settings')) {
            initPerfOptimizerHandlers();
        }
        
        // Initialize uploads backup handlers
        if ($('#start-uploads-backup')) {
            initUploadsBackupHandlers();
        }
        
        // Mark front page rows
        const frontPageIndicators = $$('.ccm-front-page-indicator');
        frontPageIndicators.forEach(indicator => {
            const row = indicator.closest('tr');
            if (row) row.classList.add('ccm-front-page-row');
        });
    });
    
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
