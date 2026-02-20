/**
 * CCM Tools - Modern Vanilla JavaScript
 * Pure JS without jQuery or other dependencies
 * Version: 7.16.2
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

        // Initialize AI Hub handlers (now embedded in perf page)
        try { initAiHubHandlers(); } catch (e) { console.error('CCM: AI Hub init error', e); }
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

        // Load PageSpeed scores on dashboard
        try {
            if ($('#dashboard-pagespeed-scores')) {
                loadDashboardPageSpeedScores();
            }
        } catch (e) { console.error('CCM: Dashboard PageSpeed init error', e); }
    });
    
    // ===================================
    // Dashboard PageSpeed Scores
    // ===================================

    /**
     * Load latest PageSpeed scores for the dashboard card
     */
    async function loadDashboardPageSpeedScores() {
        const container = $('#dashboard-pagespeed-scores');
        if (!container) return;

        function scoreColor(score) {
            const n = parseInt(score, 10);
            if (isNaN(n)) return '';
            if (n >= 90) return 'green';
            if (n >= 50) return 'orange';
            return 'red';
        }

        function timeAgo(dateStr) {
            if (!dateStr) return '';
            const d = new Date(dateStr);
            const now = new Date();
            const diff = Math.floor((now - d) / 1000);
            if (diff < 60) return 'just now';
            if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
            if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
            return `${Math.floor(diff / 86400)}d ago`;
        }

        function renderColumn(data, label, icon) {
            if (!data) return `<div class="ccm-dashboard-ps-col"><div class="ccm-dashboard-ps-col-label">${icon} ${escapeHtml(label)}</div><p class="ccm-text-muted" style="margin:0;">No results</p></div>`;

            const perf = parseInt(data.performance, 10);
            const perfColor = scoreColor(perf);
            const perfDisplay = isNaN(perf) ? '—' : perf;

            const secondaryScores = [
                { label: 'Accessibility', value: data.accessibility },
                { label: 'Best Practices', value: data.best_practices },
                { label: 'SEO', value: data.seo },
            ];

            let secondaryHtml = secondaryScores.map(s => {
                const v = parseInt(s.value, 10);
                const c = scoreColor(v);
                return `<div class="ccm-dashboard-ps-secondary-item"><span class="ccm-dashboard-ps-secondary-dot ccm-dot-${c || ''}"></span>${escapeHtml(s.label)} <span class="ccm-dashboard-ps-secondary-val">${isNaN(v) ? '—' : v}</span></div>`;
            }).join('');

            return `<div class="ccm-dashboard-ps-col">
                <div class="ccm-dashboard-ps-col-label">${icon} ${escapeHtml(label)}</div>
                <div class="ccm-dashboard-ps-hero">
                    <div class="ccm-dashboard-ps-hero-circle ccm-score-${perfColor}">${perfDisplay}</div>
                    <div class="ccm-dashboard-ps-hero-text"><strong>Performance</strong>Core Web Vitals score</div>
                </div>
                <div class="ccm-dashboard-ps-secondary">${secondaryHtml}</div>
            </div>`;
        }

        try {
            const res = await ajax('ccm_tools_ai_hub_get_latest_scores', {}, { timeout: 30000 });
            const data = res.data || {};

            if (!data.mobile && !data.desktop) {
                const adminUrl = ccmToolsData.ajax_url.replace('admin-ajax.php', '');
                container.innerHTML = '<p class="ccm-text-muted">No PageSpeed results yet. <a href="' + adminUrl + 'admin.php?page=ccm-tools-performance">Run a test</a></p>';
                return;
            }

            let html = '<div class="ccm-dashboard-ps-grid">';
            html += renderColumn(data.mobile, 'Mobile', '📱');
            html += renderColumn(data.desktop, 'Desktop', '🖥️');
            html += '</div>';

            // Meta footer with URL and time
            const testedUrl = data.mobile_url || data.desktop_url || '';
            const testedDate = data.mobile_date || data.desktop_date || '';
            if (testedUrl || testedDate) {
                html += '<div class="ccm-dashboard-ps-meta">';
                if (testedUrl) html += `<span>${escapeHtml(testedUrl)}</span>`;
                if (testedDate) html += `<span>${timeAgo(testedDate)}</span>`;
                html += '</div>';
            }

            container.innerHTML = html;
        } catch (err) {
            container.innerHTML = '<p class="ccm-text-muted">Could not load PageSpeed scores.</p>';
            console.error('CCM: Dashboard PageSpeed error', err);
        }
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
                        'wc_cache_cart_fragments',
                        'wc_persistent_cart',
                        'wc_session_cache'
                    ];
                    
                    formData.forEach((value, key) => {
                        if (checkboxFields.includes(key)) {
                            // Checkbox - convert to boolean string
                            data[key] = 'true';
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
                    
                    const response = await ajax('ccm_tools_redis_save_settings', data);
                    showNotification(response.data.message, 'success');
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
    
    // ===================================
    // AI Hub Functions — One-Click Optimize + Dual Strategy
    // ===================================

    /** State for the current AI session */
    let aiHubState = {
        lastResultId: null,     // most recent result_id (mobile — used for AI analysis)
        resultIds: {},          // { mobile: id, desktop: id }
        sessionActive: false,
        confirmedFixes: null,   // Promise resolver for user confirmation
        beforeScores: null,     // { mobile: {}, desktop: {} }
        afterScores: null,
    };

    // ─── Activity Log (terminal-style) ────────────

    /**
     * Append a timestamped entry to the activity log.
     * @param {string} message - Text to log
     * @param {'info'|'success'|'warn'|'error'|'step'|'ai'} type - Log entry type
     */
    function aiLog(message, type = 'info') {
        const wrapper = $('#ai-activity-log-wrapper');
        const log = $('#ai-activity-log');
        if (!log) return;
        if (wrapper) wrapper.style.display = 'block';

        const now = new Date();
        const ts = now.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        const prefixes = {
            info: '<span class="ccm-log-prefix ccm-log-info">INFO</span>',
            success: '<span class="ccm-log-prefix ccm-log-success">DONE</span>',
            warn: '<span class="ccm-log-prefix ccm-log-warn">WARN</span>',
            error: '<span class="ccm-log-prefix ccm-log-error">FAIL</span>',
            step: '<span class="ccm-log-prefix ccm-log-step">STEP</span>',
            ai: '<span class="ccm-log-prefix ccm-log-ai"> AI </span>',
        };
        const prefix = prefixes[type] || prefixes.info;
        const entry = document.createElement('div');
        entry.className = 'ccm-log-entry';
        entry.innerHTML = `<span class="ccm-log-ts">${ts}</span> ${prefix} ${message}`;
        log.appendChild(entry);
        log.scrollTop = log.scrollHeight;
    }

    function aiLogClear() {
        const log = $('#ai-activity-log');
        if (log) log.innerHTML = '';
    }

    /**
     * Known perf-optimizer setting keys (for auto-fix detection)
     */
    const PERF_SETTING_KEYS = new Set([
        'enabled', 'defer_js', 'delay_js', 'preload_css', 'preconnect', 'dns_prefetch',
        'remove_query_strings', 'disable_emoji', 'disable_dashicons', 'lazy_load_iframes',
        'youtube_facade', 'lcp_fetchpriority', 'lcp_preload', 'font_display_swap',
        'speculation_rules', 'critical_css', 'disable_jquery_migrate', 'disable_block_css',
        'disable_woocommerce_cart_fragments', 'reduce_heartbeat', 'disable_xmlrpc',
        'disable_rsd_wlw', 'disable_shortlink', 'disable_rest_api_links', 'disable_oembed',
        'video_lazy_load', 'video_preload_none',
        // Deep analysis data keys (auto-applied by apply_recommendations)
        'critical_css_code', 'preconnect_urls', 'dns_prefetch_urls',
        'lcp_preload_url', 'defer_js_excludes', 'delay_js_excludes', 'preload_css_excludes',
        'delay_js_timeout', 'speculation_eagerness', 'heartbeat_interval',
    ]);

    /**
     * Initialize AI Hub event handlers
     */
    function initAiHubHandlers() {
        const saveBtn = $('#ai-hub-save-btn');
        const testBtn = $('#ai-hub-test-btn');
        const runBtn = $('#ai-ps-run-btn');
        const oneClickBtn = $('#ai-one-click-btn');

        if (saveBtn) saveBtn.addEventListener('click', aiHubSaveSettings);
        if (testBtn) testBtn.addEventListener('click', aiHubTestConnection);
        if (runBtn) runBtn.addEventListener('click', aiTestOnly);
        if (oneClickBtn) oneClickBtn.addEventListener('click', aiOneClickOptimize);

        const logClearBtn = $('#ai-log-clear-btn');
        if (logClearBtn) logClearBtn.addEventListener('click', aiLogClear);

        // Strategy tab switching
        document.addEventListener('click', (e) => {
            if (e.target.matches('.ccm-ai-tab')) {
                const strategy = e.target.dataset.strategy;
                $$('.ccm-ai-tab').forEach(t => t.classList.toggle('active', t.dataset.strategy === strategy));
                $$('.ccm-ai-strategy-panel').forEach(p => {
                    p.style.display = p.id === `ai-results-${strategy}` ? 'block' : 'none';
                    p.classList.toggle('active', p.id === `ai-results-${strategy}`);
                });
            }
        });

        // Load history on init
        aiHubLoadHistory();

        // AI Chat widget
        initAiChat();
    }

    // ─── Hub Connection ────────────

    async function aiHubSaveSettings() {
        const btn = $('#ai-hub-save-btn');
        if (btn) { btn.disabled = true; btn.textContent = 'Saving…'; }

        try {
            const hubUrl = ($('#ai-hub-url') || {}).value || '';
            const apiKey = ($('#ai-hub-key') || {}).value || '';

            await ajax('ccm_tools_ai_hub_save_settings', {
                enabled: 1,
                hub_url: hubUrl,
                api_key: apiKey,
            });

            showNotification('Settings saved.', 'success');
            const oneClickBtn = $('#ai-one-click-btn');
            if (oneClickBtn && apiKey) oneClickBtn.disabled = false;
        } catch (err) {
            showNotification(err.message || 'Failed to save settings.', 'error');
        } finally {
            if (btn) { btn.disabled = false; btn.textContent = 'Save'; }
        }
    }

    async function aiHubTestConnection() {
        const btn = $('#ai-hub-test-btn');
        const resultEl = $('#ai-hub-test-result');
        const statusBadge = $('#ai-hub-status');

        if (btn) { btn.disabled = true; btn.textContent = 'Testing…'; }
        if (resultEl) resultEl.innerHTML = '<div class="ccm-spinner ccm-spinner-small"></div>';

        try {
            const res = await ajax('ccm_tools_ai_hub_test_connection', {}, { timeout: 15000 });
            const d = res.data;

            if (statusBadge) { statusBadge.textContent = 'Connected'; statusBadge.className = 'ccm-badge ccm-badge-success'; }

            let html = `<div class="ccm-success" style="padding: 0.5rem 0;">✅ ${d.message || 'Connected'}`;
            if (d.version) html += ` — Hub v${d.version}`;
            html += '</div>';

            if (d.features) {
                const feats = Object.entries(d.features).filter(([, v]) => v).map(([k]) => k).join(', ');
                if (feats) html += `<small>Features: ${feats}</small>`;
            }

            if (resultEl) resultEl.innerHTML = html;
            const oneClickBtn = $('#ai-one-click-btn');
            if (oneClickBtn) oneClickBtn.disabled = false;
        } catch (err) {
            if (statusBadge) { statusBadge.textContent = 'Disconnected'; statusBadge.className = 'ccm-badge ccm-badge-error'; }
            if (resultEl) resultEl.innerHTML = `<div class="ccm-error">❌ ${err.message || 'Connection failed'}</div>`;
        } finally {
            if (btn) { btn.disabled = false; btn.textContent = 'Test'; }
        }
    }

    // ─── PageSpeed render helpers ────────────

    /**
     * Return the Google-standard score color class:
     * 90-100 = green, 50-89 = orange, 0-49 = red
     */
    function aiScoreColorClass(score) {
        const num = parseInt(score, 10);
        if (isNaN(num)) return '';
        if (num >= 90) return 'ccm-score-green';
        if (num >= 50) return 'ccm-score-orange';
        return 'ccm-score-red';
    }

    function aiRenderScores(scores, suffix) {
        const container = $(`#ai-ps-scores-${suffix}`);
        if (!container) return;

        const categories = [
            { key: 'performance', label: 'Performance' },
            { key: 'accessibility', label: 'Accessibility' },
            { key: 'best_practices', label: 'Best Practices' },
            { key: 'seo', label: 'SEO' },
        ];

        container.innerHTML = categories.map(cat => {
            const score = scores[cat.key] ?? '—';
            const colorClass = aiScoreColorClass(score);
            return `<div class="ccm-ai-score-circle-wrap">
                <div class="ccm-ai-score-circle ${colorClass}"><span>${score}</span></div>
                <div class="ccm-ai-score-label">${cat.label}</div>
            </div>`;
        }).join('');
    }

    function aiRenderMetrics(metrics, suffix) {
        const container = $(`#ai-ps-metrics-${suffix}`);
        if (!container) return;

        const defs = [
            { key: 'fcp_ms', label: 'First Contentful Paint', unit: 'ms', good: 1800, poor: 3000 },
            { key: 'lcp_ms', label: 'Largest Contentful Paint', unit: 'ms', good: 2500, poor: 4000 },
            { key: 'cls', label: 'Cumulative Layout Shift', unit: '', good: 0.1, poor: 0.25 },
            { key: 'tbt_ms', label: 'Total Blocking Time', unit: 'ms', good: 200, poor: 600 },
            { key: 'si_ms', label: 'Speed Index', unit: 'ms', good: 3400, poor: 5800 },
            { key: 'tti_ms', label: 'Time To Interactive', unit: 'ms', good: 3800, poor: 7300 },
        ];

        let html = '<table class="ccm-table"><thead><tr><th>Metric</th><th>Value</th></tr></thead><tbody>';
        defs.forEach(m => {
            const val = metrics[m.key];
            if (val !== undefined && val !== null) {
                const numVal = parseFloat(val);
                const display = m.unit === 'ms' ? `${Number(val).toLocaleString()} ms` : val;
                let colorClass = '';
                if (!isNaN(numVal)) {
                    if (numVal <= m.good) colorClass = 'ccm-score-green';
                    else if (numVal <= m.poor) colorClass = 'ccm-score-orange';
                    else colorClass = 'ccm-score-red';
                }
                html += `<tr><td>${m.label}</td><td class="${colorClass}" style="font-weight:600;">${display}</td></tr>`;
            }
        });
        html += '</tbody></table>';
        container.innerHTML = html;
    }

    function aiRenderOpportunities(opportunities, suffix) {
        const container = $(`#ai-ps-opportunities-${suffix}`);
        if (!container) return;

        if (!opportunities.length) {
            container.innerHTML = '<p class="ccm-success" style="padding: 0.5rem 0;">No significant opportunities found — great job!</p>';
            return;
        }

        let html = '<table class="ccm-table"><thead><tr><th>Issue</th><th>Savings</th></tr></thead><tbody>';
        opportunities.forEach(opp => {
            const savings = opp.savings_ms ? `${Number(opp.savings_ms).toLocaleString()} ms`
                          : (opp.savings_bytes ? `${(opp.savings_bytes / 1024).toFixed(1)} KB` : '—');
            html += `<tr><td>${opp.title || opp.id || 'Unknown'}</td><td>${savings}</td></tr>`;
        });
        html += '</tbody></table>';
        container.innerHTML = html;

        // Auto-open the accordion if there are opportunities
        const accordion = container.closest('.ccm-ai-accordion');
        if (accordion && opportunities.length) accordion.open = true;
    }

    /**
     * Render results for a single strategy into the tabbed panels
     */
    function aiShowResultsForStrategy(data, strategy) {
        aiRenderScores(data.scores || {}, strategy);
        aiRenderMetrics(data.metrics || {}, strategy);
        aiRenderOpportunities(data.opportunities || [], strategy);
        const area = $('#ai-results-area');
        if (area) area.style.display = 'block';
    }

    // ─── Run a single PageSpeed test ────────────

    async function aiRunPageSpeed(url, strategy) {
        const res = await ajax('ccm_tools_ai_hub_run_pagespeed', {
            url: url,
            strategy: strategy,
            force: 1,
        }, { timeout: 120000 });

        const data = res.data || {};
        aiHubState.resultIds[strategy] = data.result_id || data.id || null;
        return data;
    }

    // ─── Test Only (runs both strategies, no AI) ────────────

    async function aiTestOnly() {
        const btn = $('#ai-ps-run-btn');
        if (btn) { btn.disabled = true; btn.textContent = 'Testing…'; }

        aiLogClear();
        aiLog('Starting PageSpeed test (both strategies)…', 'step');

        try {
            const url = ($('#ai-ps-url') || {}).value || '';
            aiLog(`Target URL: <strong>${url}</strong>`, 'info');

            // Run mobile
            aiLog('Testing Mobile…', 'step');
            const mobileData = await aiRunPageSpeed(url, 'mobile');
            aiShowResultsForStrategy(mobileData, 'mobile');
            aiLog(`Mobile Performance: <strong>${mobileData.scores?.performance ?? '—'}</strong>`, 'info');

            // Run desktop
            aiLog('Testing Desktop…', 'step');
            const desktopData = await aiRunPageSpeed(url, 'desktop');
            aiShowResultsForStrategy(desktopData, 'desktop');
            aiLog(`Desktop Performance: <strong>${desktopData.scores?.performance ?? '—'}</strong>`, 'info');

            // Default to mobile tab
            $$('.ccm-ai-tab').forEach(t => t.classList.toggle('active', t.dataset.strategy === 'mobile'));
            $$('.ccm-ai-strategy-panel').forEach(p => {
                p.style.display = p.id === 'ai-results-mobile' ? 'block' : 'none';
            });

            aiLog('PageSpeed tests complete!', 'success');
            showNotification('PageSpeed tests complete (Mobile + Desktop)!', 'success');
        } catch (err) {
            aiLog(`Error: ${err.message || 'PageSpeed test failed.'}`, 'error');
            showNotification(err.message || 'PageSpeed test failed.', 'error');
        } finally {
            if (btn) { btn.disabled = false; btn.textContent = 'Test Only'; }
        }
    }

    // ─── Step Progress UI ────────────

    const AI_STEPS = [
        { id: 'preflight',      label: 'Pre-flight Check' },
        { id: 'snapshot',       label: 'Save Snapshot' },
        { id: 'test-mobile',    label: 'Test Mobile' },
        { id: 'test-desktop',   label: 'Test Desktop' },
        { id: 'analyze',        label: 'AI Analysis' },
        { id: 'apply',          label: 'Apply Changes' },
        { id: 'retest-mobile',  label: 'Re-test Mobile' },
        { id: 'retest-desktop', label: 'Re-test Desktop' },
        { id: 'compare',        label: 'Compare Results' },
    ];

    function aiRenderSteps() {
        const container = $('#ai-steps');
        if (!container) return;
        container.innerHTML = AI_STEPS.map(s =>
            `<div class="ccm-ai-step" id="ai-step-${s.id}" data-status="pending">
                <span class="ccm-ai-step-indicator"></span>
                <span class="ccm-ai-step-label">${s.label}</span>
                <span class="ccm-ai-step-status"></span>
            </div>`
        ).join('');
        const progressEl = $('#ai-progress');
        if (progressEl) progressEl.style.display = 'block';
    }

    function aiUpdateStep(stepId, status, detail) {
        const el = $(`#ai-step-${stepId}`);
        if (!el) return;
        el.dataset.status = status; // pending | active | done | error | skipped
        const statusEl = el.querySelector('.ccm-ai-step-status');
        if (statusEl && detail) statusEl.textContent = detail;
    }

    // ─── Render AI analysis ────────────

    function aiRenderAnalysis(data) {
        const container = $('#ai-analysis-results');
        if (!container) return;

        const analysis = data.analysis || data;
        let html = '';

        if (analysis.summary) {
            html += `<div style="background:var(--ccm-bg-light,#f9fafb);border-radius:6px;padding:1rem;margin-bottom:1rem;">
                <strong>Summary</strong><p style="margin:0.5rem 0 0;">${analysis.summary}</p></div>`;
        }

        if (analysis.warnings && analysis.warnings.length) {
            html += '<div style="margin-bottom:1rem;"><h4 style="margin:0 0 0.5rem;">⚠️ Warnings</h4><ul>';
            analysis.warnings.forEach(w => { html += `<li>${w}</li>`; });
            html += '</ul></div>';
        }

        if (data.tokens_used) {
            html += `<p style="margin-top:1rem;font-size:0.8rem;opacity:0.6;">Tokens: ${Number(data.tokens_used).toLocaleString()} | Model: ${data.model || '?'} | Cost: ~$${data.estimated_cost || '?'}</p>`;
        }

        container.innerHTML = html;
        container.style.display = html ? 'block' : 'none';
    }

    // ─── Fix Summary (auto vs manual) ────────────

    function aiRenderFixSummary(recommendations, manualActions) {
        const container = $('#ai-fix-summary');
        if (!container) return [];

        const autoFixes = (recommendations || []).filter(r => PERF_SETTING_KEYS.has(r.setting_key));
        const manualFixes = (recommendations || []).filter(r => !PERF_SETTING_KEYS.has(r.setting_key));
        const manual = [...manualFixes, ...(manualActions || []).map(a => ({ reason: a, setting_key: null, impact: 'info' }))];

        let html = '';

        // Auto-fixable (informational — no checkboxes, auto-applied)
        if (autoFixes.length) {
            html += `<div class="ccm-ai-fix-section ccm-ai-fix-auto">
                <h4>⚡ Auto-Applying (${autoFixes.length})</h4>
                <p class="ccm-text-muted" style="margin:0 0 0.75rem;">These changes are being applied automatically.</p>`;
            autoFixes.forEach(fix => {
                const displayValue = aiFormatValue(fix.recommended_value);
                const impact = fix.impact || fix.estimated_impact || 'medium';
                const risk = fix.risk || 'low';
                html += `<div class="ccm-ai-fix-item">
                    <div class="ccm-ai-fix-details">
                        <strong>${aiSettingLabel(fix.setting_key)}</strong>
                        <span class="ccm-badge ccm-badge-${impact === 'high' ? 'error' : (impact === 'medium' ? 'warning' : 'info')}">${impact} impact</span>
                        <span class="ccm-badge ccm-badge-${risk === 'high' ? 'error' : (risk === 'medium' ? 'warning' : 'info')}">${risk} risk</span>
                        <p class="ccm-text-muted" style="margin:0.25rem 0 0;">${fix.reason || ''}</p>
                        <code style="word-break:break-all;">${fix.setting_key} → ${displayValue}</code>
                    </div>
                </div>`;
            });
            html += '</div>';
        }

        // Manual items
        if (manual.length) {
            html += `<div class="ccm-ai-fix-section ccm-ai-fix-manual" style="margin-top:1rem;">
                <h4>🔧 Manual Fixes (${manual.length})</h4>
                <p class="ccm-text-muted" style="margin:0 0 0.75rem;">These require manual action and cannot be applied automatically.</p>`;
            manual.forEach(fix => {
                html += `<div class="ccm-ai-fix-item ccm-ai-fix-item-manual">
                    <div class="ccm-ai-fix-details">
                        ${fix.setting_key ? `<strong>${fix.setting_key}</strong>` : ''}
                        <p style="margin:0.25rem 0 0;">${fix.reason || fix}</p>
                    </div>
                </div>`;
            });
            html += '</div>';
        }

        if (!autoFixes.length && !manual.length) {
            html = '<p class="ccm-success" style="padding:1rem;">No additional optimizations recommended — your site looks great!</p>';
        }

        container.innerHTML = html;
        container.style.display = 'block';

        // Return auto fixes for automatic application
        return autoFixes;
    }

    /** Human-friendly label for perf setting keys */
    function aiSettingLabel(key) {
        const labels = {
            defer_js: 'Defer JavaScript', delay_js: 'Delay JavaScript', preload_css: 'Async CSS Loading',
            preconnect: 'Preconnect Hints', dns_prefetch: 'DNS Prefetch', remove_query_strings: 'Remove Query Strings',
            disable_emoji: 'Disable Emoji Scripts', disable_dashicons: 'Disable Dashicons',
            lazy_load_iframes: 'Lazy Load Iframes', youtube_facade: 'YouTube Lite Embeds',
            lcp_fetchpriority: 'LCP Fetchpriority', lcp_preload: 'LCP Image Preload',
            font_display_swap: 'Font Display: Swap',
            speculation_rules: 'Speculation Rules', critical_css: 'Critical CSS',
            critical_css_code: 'Critical CSS Code (generated)', preconnect_urls: 'Preconnect URLs',
            dns_prefetch_urls: 'DNS Prefetch URLs', lcp_preload_url: 'LCP Image URL',
            defer_js_excludes: 'Defer JS Exclude List', delay_js_excludes: 'Delay JS Exclude List',
            preload_css_excludes: 'Async CSS Exclude List', delay_js_timeout: 'Delay JS Timeout',
            speculation_eagerness: 'Speculation Eagerness', heartbeat_interval: 'Heartbeat Interval',
            disable_jquery_migrate: 'Disable jQuery Migrate', disable_block_css: 'Disable Block CSS',
            disable_woocommerce_cart_fragments: 'Disable Cart Fragments', reduce_heartbeat: 'Reduce Heartbeat',
            disable_xmlrpc: 'Disable XML-RPC', disable_rsd_wlw: 'Remove RSD/WLW Links',
            disable_shortlink: 'Remove Shortlink', disable_rest_api_links: 'Remove REST API Link',
            disable_oembed: 'Disable oEmbed', enabled: 'Performance Optimizer',
            video_lazy_load: 'Video Lazy Load', video_preload_none: 'Video Preload: None',
        };
        return labels[key] || key.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
    }

    /** Format a recommended_value for display in the fix summary */
    function aiFormatValue(value) {
        if (Array.isArray(value)) {
            if (value.length === 0) return '[]';
            if (value.length <= 3) return JSON.stringify(value);
            return `[${value.slice(0, 3).map(v => `"${v}"`).join(', ')}, …+${value.length - 3}]`;
        }
        if (typeof value === 'string' && value.length > 80) {
            return `"${value.substring(0, 77)}…" (${value.length} chars)`;
        }
        return String(value);
    }



    // ─── Update page toggles in real time ────────────

    function aiUpdatePageToggles(settings) {
        if (!settings) return;

        // Special ID mappings where key → DOM id doesn't follow the standard pattern
        const idOverrides = {
            enabled: 'perf-master-enable',
        };

        Object.entries(settings).forEach(([key, value]) => {
            const domId = idOverrides[key] || `perf-${key.replace(/_/g, '-')}`;
            const el = $(`#${domId}`);
            if (!el) return;

            if (el.type === 'checkbox') {
                const newChecked = !!value;
                if (el.checked !== newChecked) {
                    el.checked = newChecked;
                    el.dispatchEvent(new Event('change', { bubbles: true }));
                }
            } else if (el.tagName === 'TEXTAREA') {
                // Critical CSS code, URL lists (newline-separated)
                el.value = Array.isArray(value) ? value.join('\n') : String(value || '');
            } else if (el.tagName === 'SELECT') {
                el.value = String(value || '');
            } else if (el.type === 'text' || el.type === 'url' || el.type === 'number') {
                // Text inputs for URLs, comma-separated lists, numbers
                if (Array.isArray(value)) {
                    el.value = value.join(', ');
                } else {
                    el.value = String(value || '');
                }
            }
        });
    }

    // ─── Before / After Comparison ────────────

    function aiRenderBeforeAfter(before, after) {
        const container = $('#ai-before-after');
        if (!container) return;

        const strategies = ['mobile', 'desktop'];
        const categories = ['performance', 'accessibility', 'best_practices', 'seo'];

        let html = '<h3 style="margin-bottom:1rem;">Before / After Comparison</h3>';
        html += '<div class="ccm-ai-comparison">';

        strategies.forEach(strategy => {
            const b = (before || {})[strategy] || {};
            const a = (after || {})[strategy] || {};
            html += `<div class="ccm-ai-comparison-col">
                <h4 style="text-transform:capitalize;margin-bottom:0.5rem;">${strategy}</h4>
                <table class="ccm-table"><thead><tr><th>Category</th><th>Before</th><th>After</th><th>Change</th></tr></thead><tbody>`;

            categories.forEach(cat => {
                const bv = b[cat] ?? '—';
                const av = a[cat] ?? '—';
                const bNum = parseInt(bv, 10);
                const aNum = parseInt(av, 10);
                let change = '—';
                let changeClass = '';
                if (!isNaN(bNum) && !isNaN(aNum)) {
                    const diff = aNum - bNum;
                    change = (diff > 0 ? '+' : '') + diff;
                    changeClass = diff > 0 ? 'ccm-score-green' : (diff < 0 ? 'ccm-score-red' : '');
                }
                const label = cat === 'best_practices' ? 'Best Practices' : cat.charAt(0).toUpperCase() + cat.slice(1);
                const beforeColor = aiScoreColorClass(bv);
                const afterColor = aiScoreColorClass(av);
                html += `<tr><td>${label}</td><td class="${beforeColor}">${bv}</td><td class="${afterColor}"><strong>${av}</strong></td><td class="${changeClass}"><strong>${change}</strong></td></tr>`;
            });

            html += '</tbody></table></div>';
        });

        html += '</div>';
        container.innerHTML = html;
        container.style.display = 'block';
    }

    // ─── One-Click Optimize (iterative improvement loop with rollback) ────────────

    const AI_MAX_ITERATIONS = 10;
    const PSI_NOISE = 3; // PageSpeed variance tolerance (±3 points is normal)

    async function aiOneClickOptimize() {
        const btn = $('#ai-one-click-btn');
        if (btn) { btn.disabled = true; btn.textContent = 'Optimizing…'; }

        // Reset state
        aiHubState.beforeScores = {};
        aiHubState.afterScores = {};
        aiHubState.resultIds = {};
        const fixSummary = $('#ai-fix-summary');
        const beforeAfter = $('#ai-before-after');
        const analysisResults = $('#ai-analysis-results');
        const remainingRecs = $('#ai-remaining-recommendations');
        if (fixSummary) { fixSummary.style.display = 'none'; fixSummary.innerHTML = ''; }
        if (beforeAfter) { beforeAfter.style.display = 'none'; beforeAfter.innerHTML = ''; }
        if (analysisResults) { analysisResults.style.display = 'none'; analysisResults.innerHTML = ''; }
        if (remainingRecs) { remainingRecs.style.display = 'none'; remainingRecs.innerHTML = ''; }

        // Render step indicators & clear log
        aiRenderSteps();
        aiLogClear();
        aiLog('Starting One-Click Optimize…', 'step');

        const url = ($('#ai-ps-url') || {}).value || '';
        aiLog(`Target URL: <strong>${url}</strong>`, 'info');

        // Track final opportunities for remaining recommendations
        let lastMobileOpportunities = [];
        let lastDesktopOpportunities = [];
        let lastManualActions = [];
        let allChanges = []; // accumulate across iterations
        let wasRolledBack = false;

        try {
            // ── Step 0: Pre-flight — Check & enable server-side tools ──
            aiUpdateStep('preflight', 'active', 'Checking tools…');
            aiLog('Running pre-flight tool checks…', 'step');
            try {
                const preflightRes = await ajax('ccm_tools_ai_preflight', {}, { timeout: 15000 });
                const tools = preflightRes.data || {};
                let toolsEnabled = 0;

                // .htaccess
                if (tools.htaccess && !tools.htaccess.applied && tools.htaccess.writable) {
                    aiLog('.htaccess optimizations not applied — enabling caching, compression & security…', 'warn');
                    try {
                        const htRes = await ajax('ccm_tools_ai_enable_tool', { tool: 'htaccess' }, { timeout: 15000 });
                        if (htRes.data?.success) {
                            aiLog(`.htaccess: ${htRes.data.message}`, 'success');
                            toolsEnabled++;
                        } else {
                            aiLog(`.htaccess: ${htRes.data?.message || 'Failed'}`, 'warn');
                        }
                    } catch (e) { aiLog(`.htaccess enable failed: ${e.message}`, 'warn'); }
                } else if (tools.htaccess?.applied) {
                    aiLog('.htaccess optimizations already applied ✓', 'info');
                }

                // WebP
                if (tools.webp?.available && !tools.webp?.enabled) {
                    aiLog('WebP converter not enabled — enabling with on-demand conversion & picture tags…', 'warn');
                    try {
                        const wpRes = await ajax('ccm_tools_ai_enable_tool', { tool: 'webp' }, { timeout: 15000 });
                        if (wpRes.data?.success) {
                            aiLog(`WebP: ${wpRes.data.message}`, 'success');
                            toolsEnabled++;
                        } else {
                            aiLog(`WebP: ${wpRes.data?.message || 'Failed'}`, 'warn');
                        }
                    } catch (e) { aiLog(`WebP enable failed: ${e.message}`, 'warn'); }
                } else if (tools.webp?.enabled) {
                    aiLog('WebP conversion already enabled ✓', 'info');
                } else if (!tools.webp?.available) {
                    aiLog('WebP not available (no GD/ImageMagick with WebP support)', 'info');
                }

                // Redis
                if (tools.redis?.extension && !tools.redis?.dropin) {
                    aiLog('Redis extension available but drop-in not installed — enabling…', 'warn');
                    try {
                        const rdRes = await ajax('ccm_tools_ai_enable_tool', { tool: 'redis' }, { timeout: 15000 });
                        if (rdRes.data?.success) {
                            aiLog(`Redis: ${rdRes.data.message}`, 'success');
                            toolsEnabled++;
                        } else {
                            aiLog(`Redis: ${rdRes.data?.message || 'Failed'}`, 'warn');
                        }
                    } catch (e) { aiLog(`Redis enable failed: ${e.message}`, 'warn'); }
                } else if (tools.redis?.dropin) {
                    aiLog('Redis object cache active ✓', 'info');
                } else {
                    aiLog('Redis not available on this server', 'info');
                }

                // Performance optimizer master toggle
                if (tools.performance && !tools.performance.enabled) {
                    aiLog('Performance Optimizer disabled — enabling…', 'warn');
                    try {
                        await ajax('ccm_tools_ai_enable_tool', { tool: 'performance' }, { timeout: 10000 });
                        aiLog('Performance Optimizer enabled.', 'success');
                        toolsEnabled++;
                    } catch (e) { aiLog(`Perf enable failed: ${e.message}`, 'warn'); }
                }

                // Database status note
                if (tools.database?.needs_optimization) {
                    aiLog(`Database: ${tools.database.tables_needing_optimization} table(s) need optimization (InnoDB/utf8mb4) — consider running Database tools.`, 'warn');
                }

                const msg = toolsEnabled > 0
                    ? `Enabled ${toolsEnabled} tool(s) for better baseline`
                    : 'All tools OK';
                aiUpdateStep('preflight', 'done', msg);
                if (toolsEnabled > 0) {
                    aiLog(`Pre-flight: enabled ${toolsEnabled} server-side tool(s). Waiting 3s for changes to take effect…`, 'info');
                    await aiSleep(3000);
                } else {
                    aiLog('Pre-flight: all server-side tools already configured ✓', 'success');
                }
            } catch (e) {
                aiLog(`Pre-flight check failed: ${e.message} — continuing anyway`, 'warn');
                aiUpdateStep('preflight', 'done', 'Skipped');
            }

            // ── Step 1: Save Settings Snapshot (for rollback) ──
            aiUpdateStep('snapshot', 'active', 'Saving…');
            aiLog('Saving settings snapshot for rollback safety…', 'step');
            await ajax('ccm_tools_ai_snapshot_settings', {}, { timeout: 10000 });
            aiUpdateStep('snapshot', 'done', 'Saved');
            aiLog('Snapshot saved.', 'success');

            // ── Step 2: Test Mobile (baseline) ──
            aiUpdateStep('test-mobile', 'active', 'Running…');
            aiLog('Running Mobile PageSpeed test…', 'step');
            const mobileData = await aiRunPageSpeed(url, 'mobile');
            aiShowResultsForStrategy(mobileData, 'mobile');
            aiHubState.beforeScores.mobile = mobileData.scores || {};
            aiHubState.lastResultId = aiHubState.resultIds.mobile;
            lastMobileOpportunities = mobileData.opportunities || [];
            const mobilePerf = mobileData.scores?.performance ?? '—';
            aiUpdateStep('test-mobile', 'done', `Perf: ${mobilePerf}`);
            aiLog(`Mobile scores — Performance: <strong>${mobilePerf}</strong>, LCP: ${mobileData.metrics?.lcp_ms ?? '—'}ms, CLS: ${mobileData.metrics?.cls ?? '—'}`, 'info');

            // ── Step 3: Test Desktop (baseline) ──
            aiUpdateStep('test-desktop', 'active', 'Running…');
            aiLog('Running Desktop PageSpeed test…', 'step');
            const desktopData = await aiRunPageSpeed(url, 'desktop');
            aiShowResultsForStrategy(desktopData, 'desktop');
            aiHubState.beforeScores.desktop = desktopData.scores || {};
            lastDesktopOpportunities = desktopData.opportunities || [];
            const desktopPerf = desktopData.scores?.performance ?? '—';
            aiUpdateStep('test-desktop', 'done', `Perf: ${desktopPerf}`);
            aiLog(`Desktop scores — Performance: <strong>${desktopPerf}</strong>, LCP: ${desktopData.metrics?.lcp_ms ?? '—'}ms, CLS: ${desktopData.metrics?.cls ?? '—'}`, 'info');

            // Show results (default mobile tab)
            $$('.ccm-ai-tab').forEach(t => t.classList.toggle('active', t.dataset.strategy === 'mobile'));
            $$('.ccm-ai-strategy-panel').forEach(p => {
                p.style.display = p.id === 'ai-results-mobile' ? 'block' : 'none';
            });

            // Track snapshot scores (updated after each successful keep)
            let snapshotMobilePerf = mobileData.scores?.performance ?? 0;
            let snapshotDesktopPerf = desktopData.scores?.performance ?? 0;
            let iteration = 0;
            let hasApplied = false;

            // ── Iterative improvement loop ──
            while (iteration < AI_MAX_ITERATIONS) {
                iteration++;
                const iterLabel = iteration > 1 ? ` (Iter ${iteration})` : '';
                if (iteration > 1) {
                    aiLog(`── Iteration ${iteration}/${AI_MAX_ITERATIONS} ──`, 'step');
                }

                // ── AI Analysis ──
                aiUpdateStep('analyze', 'active', `Analyzing${iterLabel}…`);
                aiLog(`Sending results to AI for analysis${iterLabel}…`, 'ai');
                const analysisLoading = $('#ai-analysis-loading');
                if (analysisLoading) analysisLoading.style.display = 'block';

                const analysisRes = await ajax('ccm_tools_ai_hub_ai_analyze', {
                    result_id: aiHubState.lastResultId,
                    url: url,
                }, { timeout: 120000 });

                if (analysisLoading) analysisLoading.style.display = 'none';

                const analysisData = analysisRes.data || {};
                const analysis = analysisData.analysis || analysisData;
                aiRenderAnalysis(analysisData);
                const recCount = (analysis.recommendations || []).length;
                const manualCount = (analysis.manual_actions || []).length;
                lastManualActions = analysis.manual_actions || [];
                aiUpdateStep('analyze', 'done', `${recCount} recommendations${iterLabel}`);
                aiLog(`AI returned <strong>${recCount}</strong> auto-fixable + <strong>${manualCount}</strong> manual recommendations.`, 'ai');

                if (analysis.summary) {
                    aiLog(`Summary: ${analysis.summary}`, 'ai');
                }

                if (analysis.tokens_used) {
                    aiLog(`Tokens: ${Number(analysis.tokens_used).toLocaleString()} | Model: ${analysis.model || analysisData.model || '?'} | Cost: ~$${analysis.estimated_cost || analysisData.estimated_cost || '?'}`, 'info');
                }

                if (recCount === 0) {
                    aiLog('AI found no further optimizations to apply.', 'info');
                    showNotification('AI found no further optimizations to try.', 'info');
                    break;
                }

                // ── Auto-select all applicable fixes (no user interaction) ──
                const autoFixes = aiRenderFixSummary(analysis.recommendations || [], analysis.manual_actions || []);
                const selectedFixes = autoFixes || [];

                if (!selectedFixes.length) {
                    aiLog('No auto-fixable recommendations found — only manual fixes.', 'warn');
                    showNotification('No auto-fixable recommendations found.', 'info');
                    break;
                }

                // Log each fix being applied
                selectedFixes.forEach(fix => {
                    aiLog(`Queuing: <code>${fix.setting_key}</code> → <code>${aiFormatValue(fix.recommended_value)}</code>`, 'info');
                });

                // ── Apply Changes ──
                aiUpdateStep('apply', 'active', `Applying${iterLabel}…`);
                aiLog(`Applying <strong>${selectedFixes.length}</strong> changes${iterLabel}…`, 'step');
                const applyRes = await ajax('ccm_tools_ai_apply_changes', {
                    recommendations: JSON.stringify(selectedFixes),
                }, { timeout: 30000 });

                const applyData = applyRes.data || {};
                const changes = applyData.changes || [];
                allChanges = allChanges.concat(changes);
                aiUpdateStep('apply', 'done', `${changes.length} changed${iterLabel}`);
                hasApplied = true;
                aiLog(`<strong>${changes.length}</strong> settings changed successfully.`, 'success');

                // Update on-page toggles in real time
                if (applyData.settings) {
                    aiUpdatePageToggles(applyData.settings);
                }

                showNotification(`Applied ${changes.length} change(s).${iterLabel}`, 'success');

                // Wait for caches to clear
                aiLog('Waiting 5s for caches to clear…', 'info');
                await aiSleep(5000);

                // ── Re-test Mobile ──
                aiUpdateStep('retest-mobile', 'active', `Re-testing${iterLabel}…`);
                aiLog(`Re-testing Mobile PageSpeed${iterLabel}…`, 'step');
                const mobileRetest = await aiRunPageSpeed(url, 'mobile');
                const retestMobilePerf = mobileRetest.scores?.performance ?? 0;
                aiHubState.afterScores.mobile = mobileRetest.scores || {};
                lastMobileOpportunities = mobileRetest.opportunities || [];
                aiUpdateStep('retest-mobile', 'done', `Perf: ${retestMobilePerf}${iterLabel}`);
                aiLog(`Mobile re-test — Performance: <strong>${retestMobilePerf}</strong>, LCP: ${mobileRetest.metrics?.lcp_ms ?? '—'}ms`, 'info');

                // ── Re-test Desktop ──
                aiUpdateStep('retest-desktop', 'active', `Re-testing${iterLabel}…`);
                aiLog(`Re-testing Desktop PageSpeed${iterLabel}…`, 'step');
                const desktopRetest = await aiRunPageSpeed(url, 'desktop');
                const retestDesktopPerf = desktopRetest.scores?.performance ?? 0;
                aiHubState.afterScores.desktop = desktopRetest.scores || {};
                lastDesktopOpportunities = desktopRetest.opportunities || [];
                aiUpdateStep('retest-desktop', 'done', `Perf: ${retestDesktopPerf}${iterLabel}`);
                aiLog(`Desktop re-test — Performance: <strong>${retestDesktopPerf}</strong>, LCP: ${desktopRetest.metrics?.lcp_ms ?? '—'}ms`, 'info');

                // ── Evaluate results (smart rollback with net-gain logic) ──
                const mobileChange = retestMobilePerf - snapshotMobilePerf;
                const desktopChange = retestDesktopPerf - snapshotDesktopPerf;
                const netChange = mobileChange + desktopChange;

                // KEEP changes if:
                // 1. Both scores within noise tolerance (neither dropped meaningfully)
                // 2. Net positive AND neither dropped catastrophically (>15 points)
                const bothStable = mobileChange >= -PSI_NOISE && desktopChange >= -PSI_NOISE;
                const netPositive = netChange > 0 && mobileChange > -15 && desktopChange > -15;
                const keepChanges = bothStable || netPositive;

                if (!keepChanges) {
                    // ── ROLLBACK — net negative or catastrophic drop ──
                    aiLog(`Scores regressed — Mobile: ${mobileChange >= 0 ? '+' : ''}${mobileChange}, Desktop: ${desktopChange >= 0 ? '+' : ''}${desktopChange} (net: ${netChange >= 0 ? '+' : ''}${netChange}). Rolling back…`, 'error');
                    showNotification(
                        `Net negative (${netChange >= 0 ? '+' : ''}${netChange}). Rolling back…`,
                        'warning'
                    );
                    aiUpdateStep('compare', 'active', 'Rolling back…');

                    await ajax('ccm_tools_ai_rollback_settings', {}, { timeout: 10000 });
                    aiLog('Settings rolled back to snapshot.', 'warn');
                    showNotification('Settings rolled back to pre-optimization snapshot.', 'info');
                    wasRolledBack = true;

                    // Update page toggles to rolled-back state
                    try {
                        const snapRes = await ajax('ccm_tools_get_perf_settings', {}, { timeout: 10000 });
                        if (snapRes.data) aiUpdatePageToggles(snapRes.data);
                    } catch (_) { /* best effort */ }

                    // Update result_id for next analysis (use the new retest result)
                    aiHubState.lastResultId = aiHubState.resultIds.mobile;

                    if (iteration < AI_MAX_ITERATIONS) {
                        // Save new snapshot and try again with conservative approach
                        await ajax('ccm_tools_ai_snapshot_settings', {}, { timeout: 10000 });
                        aiUpdateStep('compare', 'done', `Rolled back — retrying (${iteration + 1}/${AI_MAX_ITERATIONS})`);
                        aiLog(`Retrying with conservative approach (iteration ${iteration + 1})…`, 'step');
                        showNotification(`Attempting conservative approach (iteration ${iteration + 1})…`, 'info');
                        // Clear fix summary and reset remaining steps for next iteration
                        if (fixSummary) { fixSummary.style.display = 'none'; fixSummary.innerHTML = ''; }
                        aiUpdateStep('analyze', 'pending', '');
                        aiUpdateStep('apply', 'pending', '');
                        aiUpdateStep('retest-mobile', 'pending', '');
                        aiUpdateStep('retest-desktop', 'pending', '');
                        aiUpdateStep('compare', 'pending', '');
                        continue; // next iteration
                    } else {
                        aiUpdateStep('compare', 'done', 'Rolled back — max iterations');
                        aiLog('Max iterations reached after rollback.', 'warn');
                        break;
                    }
                }

                // ── KEEP — scores improved or stable ──
                aiLog(`Scores OK — Mobile: ${mobileChange >= 0 ? '+' : ''}${mobileChange}, Desktop: ${desktopChange >= 0 ? '+' : ''}${desktopChange} (net: ${netChange >= 0 ? '+' : ''}${netChange}). Keeping changes!`, 'success');
                showNotification(`Changes kept (net ${netChange >= 0 ? '+' : ''}${netChange}).`, 'success');
                wasRolledBack = false;

                // Show before/after comparison
                aiUpdateStep('compare', 'active', 'Comparing…');
                aiRenderBeforeAfter(aiHubState.beforeScores, aiHubState.afterScores);
                aiShowResultsForStrategy(mobileRetest, 'mobile');
                aiShowResultsForStrategy(desktopRetest, 'desktop');

                // Check if we should keep iterating
                const bothAbove90 = retestMobilePerf >= 90 && retestDesktopPerf >= 90;
                if (bothAbove90 || iteration >= AI_MAX_ITERATIONS) {
                    const resultMsg = `M:${retestMobilePerf} D:${retestDesktopPerf}`;
                    aiUpdateStep('compare', 'done', resultMsg);
                    if (bothAbove90) {
                        aiLog(`Both scores above 90! Mobile: ${retestMobilePerf}, Desktop: ${retestDesktopPerf} 🎉`, 'success');
                    } else {
                        aiLog(`Max iterations reached. Mobile: ${retestMobilePerf}, Desktop: ${retestDesktopPerf}`, 'info');
                    }
                    break;
                }

                // Scores improved but below 90 — update snapshot and iterate for more gains
                aiLog(`Improved but still below 90 (M:${retestMobilePerf}, D:${retestDesktopPerf}). Saving checkpoint and iterating…`, 'info');
                showNotification('Improved — trying for more gains…', 'info');
                aiUpdateStep('compare', 'done', `Net +${netChange}pts — iterating…`);

                // Save snapshot of current (improved) state and update baseline
                await ajax('ccm_tools_ai_snapshot_settings', {}, { timeout: 10000 });
                snapshotMobilePerf = retestMobilePerf;
                snapshotDesktopPerf = retestDesktopPerf;

                // Clear fix summary for next iteration
                if (fixSummary) { fixSummary.style.display = 'none'; fixSummary.innerHTML = ''; }

                // Update steps UI for next iteration
                aiUpdateStep('analyze', 'pending', '');
                aiUpdateStep('apply', 'pending', '');
                aiUpdateStep('retest-mobile', 'pending', '');
                aiUpdateStep('retest-desktop', 'pending', '');
                aiUpdateStep('compare', 'pending', '');
            }

            // Final state if no apply happened
            if (!hasApplied) {
                aiUpdateStep('apply', 'skipped', 'Skipped');
                aiUpdateStep('retest-mobile', 'skipped', 'Skipped');
                aiUpdateStep('retest-desktop', 'skipped', 'Skipped');
                aiUpdateStep('compare', 'skipped', 'Skipped');
            }

            // ── Remaining Recommendations (when not at 90+) ──
            const finalMobilePerf = aiHubState.afterScores?.mobile?.performance ?? aiHubState.beforeScores?.mobile?.performance ?? 0;
            const finalDesktopPerf = aiHubState.afterScores?.desktop?.performance ?? aiHubState.beforeScores?.desktop?.performance ?? 0;
            aiRenderRemainingRecommendations(
                finalMobilePerf, finalDesktopPerf,
                lastMobileOpportunities, lastDesktopOpportunities,
                lastManualActions
            );

            aiLog('One-Click Optimize complete!', 'success');
            showNotification('One-Click Optimize complete!', 'success');

            // Save optimization run summary
            try {
                await ajax('ccm_tools_ai_save_run', {
                    run_data: JSON.stringify({
                        url: url,
                        before_mobile: aiHubState.beforeScores?.mobile?.performance ?? 0,
                        before_desktop: aiHubState.beforeScores?.desktop?.performance ?? 0,
                        after_mobile: aiHubState.afterScores?.mobile?.performance ?? aiHubState.beforeScores?.mobile?.performance ?? 0,
                        after_desktop: aiHubState.afterScores?.desktop?.performance ?? aiHubState.beforeScores?.desktop?.performance ?? 0,
                        changes_count: allChanges.length,
                        changes: allChanges,
                        iterations: iteration,
                        rolled_back: wasRolledBack,
                        outcome: !hasApplied ? 'no_changes' : wasRolledBack ? 'rolled_back' : 'improved',
                    }),
                }, { timeout: 10000 });
            } catch (_) { /* best effort */ }

            aiHubLoadHistory();
        } catch (err) {
            console.error('[CCM AI] One-click error:', err);
            const errMsg = err.message || 'Optimization failed.';
            aiLog(`Error: ${errMsg}`, 'error');
            showNotification(errMsg, 'error');
            // Mark current active step as error with details, remaining as skipped
            AI_STEPS.forEach(s => {
                const el = $(`#ai-step-${s.id}`);
                if (!el) return;
                if (el.dataset.status === 'active') {
                    aiUpdateStep(s.id, 'error', errMsg.length > 40 ? errMsg.slice(0, 40) + '…' : errMsg);
                } else if (el.dataset.status === 'pending') {
                    aiUpdateStep(s.id, 'skipped', '');
                }
            });
        } finally {
            if (btn) { btn.disabled = false; btn.textContent = '🚀 One-Click Optimize'; }
        }
    }

    // ─── Remaining Recommendations Panel (when score < 90) ────────────

    /**
     * Render actionable remaining recommendations when scores are still below 90.
     * Combines PSI opportunities with AI manual actions.
     */
    function aiRenderRemainingRecommendations(mobilePerf, desktopPerf, mobileOpps, desktopOpps, manualActions) {
        const container = $('#ai-remaining-recommendations');
        if (!container) return;

        const below90 = mobilePerf < 90 || desktopPerf < 90;
        if (!below90) {
            container.style.display = 'none';
            return;
        }

        const hasOpps = (mobileOpps && mobileOpps.length) || (desktopOpps && desktopOpps.length);
        const hasManual = manualActions && manualActions.length;
        if (!hasOpps && !hasManual) {
            container.style.display = 'none';
            return;
        }

        let html = `<div class="ccm-ai-remaining-panel">
            <h3>🎯 Remaining Recommendations to Reach 90+</h3>
            <p class="ccm-text-muted" style="margin:0.25rem 0 1rem;">These issues require changes outside the Performance Optimizer — server configuration, theme/plugin modifications, or content optimization.</p>`;

        // Merge and deduplicate opportunities from both strategies
        const allOpps = new Map();
        const mergeOpps = (opps, strategy) => {
            (opps || []).forEach(opp => {
                const key = opp.title || opp.id || 'Unknown';
                if (allOpps.has(key)) {
                    const existing = allOpps.get(key);
                    existing.strategies.push(strategy);
                    if (opp.savings_ms && (!existing.savings_ms || opp.savings_ms > existing.savings_ms)) {
                        existing.savings_ms = opp.savings_ms;
                    }
                    if (opp.savings_bytes && (!existing.savings_bytes || opp.savings_bytes > existing.savings_bytes)) {
                        existing.savings_bytes = opp.savings_bytes;
                    }
                } else {
                    allOpps.set(key, { ...opp, title: key, strategies: [strategy] });
                }
            });
        };
        mergeOpps(mobileOpps, 'Mobile');
        mergeOpps(desktopOpps, 'Desktop');

        // Show PageSpeed opportunities
        if (allOpps.size) {
            html += `<h4 style="margin:0 0 0.5rem;">PageSpeed Opportunities</h4>`;
            html += '<div class="ccm-ai-remaining-list">';
            allOpps.forEach(opp => {
                const savings = opp.savings_ms ? `${Number(opp.savings_ms).toLocaleString()} ms`
                              : (opp.savings_bytes ? `${(opp.savings_bytes / 1024).toFixed(1)} KB` : '');
                const strategyBadges = opp.strategies.map(s =>
                    `<span class="ccm-badge ccm-badge-info">${s}</span>`
                ).join(' ');
                const guidance = aiGetOpportunityGuidance(opp.id || opp.title);
                html += `<div class="ccm-ai-remaining-item">
                    <div class="ccm-ai-remaining-header">
                        <strong>${opp.title}</strong>
                        ${savings ? `<span class="ccm-badge ccm-badge-warning">${savings}</span>` : ''}
                        ${strategyBadges}
                    </div>
                    ${guidance ? `<p class="ccm-ai-remaining-guidance">${guidance}</p>` : ''}
                </div>`;
            });
            html += '</div>';
        }

        // Show manual actions from AI
        if (hasManual) {
            html += `<h4 style="margin:1rem 0 0.5rem;">AI-Recommended Manual Actions</h4>`;
            html += '<div class="ccm-ai-remaining-list">';
            manualActions.forEach(action => {
                const text = typeof action === 'string' ? action : (action.reason || action.description || JSON.stringify(action));
                html += `<div class="ccm-ai-remaining-item ccm-ai-remaining-item-manual">
                    <p>${text}</p>
                </div>`;
            });
            html += '</div>';
        }

        html += '</div>';
        container.innerHTML = html;
        container.style.display = 'block';

        aiLog(`Showing ${allOpps.size + (manualActions || []).length} remaining recommendations for reaching 90+.`, 'info');
    }

    /**
     * Return actionable guidance for common PageSpeed opportunities.
     */
    function aiGetOpportunityGuidance(idOrTitle) {
        const key = (idOrTitle || '').toLowerCase();
        const guidance = {
            'render-blocking-resources': 'Defer non-critical CSS/JS. Check theme for inline critical CSS support, or use the Critical CSS + Async CSS features in Performance Optimizer.',
            'unused-css-rules': 'Remove unused CSS from your theme or page builder. Consider a CSS clean-up tool or loading CSS conditionally per page.',
            'unused-javascript': 'Remove or defer unused JS. Check if plugins load scripts on pages where they aren\'t needed. Use asset clean-up plugins.',
            'uses-responsive-images': 'Ensure images use srcset/sizes for responsive loading. Regenerate thumbnails if using an older theme.',
            'offscreen-images': 'Enable lazy loading for below-the-fold images. WordPress native lazy loading should handle most cases.',
            'unminified-css': 'Minify CSS files. This typically requires a server-level build step or a minification plugin.',
            'unminified-javascript': 'Minify JS files. Use a build tool or minification plugin. Some hosts provide this automatically.',
            'uses-text-compression': 'Enable Gzip/Brotli compression on your server. Check .htaccess or contact your hosting provider.',
            'uses-long-cache-ttl': 'Set longer Cache-Control headers for static assets. The .htaccess Optimizer in CCM Tools can help with this.',
            'server-response-time': 'Improve TTFB — consider server-side caching (Redis, page cache), upgrading hosting, or optimizing slow database queries.',
            'total-byte-weight': 'Reduce total page weight — compress images, remove unused plugins/scripts, and audit third-party resources.',
            'dom-size': 'Simplify your HTML structure. Page builders often create deeply nested DOM trees — consider simplifying layouts.',
            'uses-optimized-images': 'Compress images further. Use WebP/AVIF formats via the WebP Converter in CCM Tools.',
            'modern-image-formats': 'Convert images to WebP or AVIF. Use the WebP Converter tool in CCM Tools.',
            'third-party-summary': 'Audit third-party scripts (analytics, ads, widgets). Consider delaying non-essential third-party scripts.',
            'largest-contentful-paint-element': 'Optimize the LCP element — preload hero images, use fetchpriority="high", and ensure the LCP image isn\'t lazy-loaded.',
            'layout-shift-elements': 'Add explicit width/height to images and embeds. Avoid inserting content above the fold after page load.',
            'font-display': 'Use font-display: swap for all fonts. Enable this in Performance Optimizer settings.',
            'efficient-animated-content': 'Replace animated GIFs with video (MP4/WebM). Videos are dramatically smaller and faster.',
            'duplicated-javascript': 'Check if multiple plugins load the same library (e.g., multiple jQuery versions). Dequeue duplicates.',
            'legacy-javascript': 'Some plugins serve ES5 bundles unnecessarily. Check for plugin updates or modern replacements.',
            'mainthread-work-breakdown': 'Reduce main thread work — minimize JS execution, CSS parsing, and layout recalculations. Simplify page complexity.',
            'bootup-time': 'Reduce JS execution time — delay non-critical scripts and remove unused JavaScript.',
            'uses-rel-preconnect': 'Add preconnect hints for critical third-party origins. Enable Preconnect in Performance Optimizer.',
            'redirects': 'Minimize redirects. Each redirect adds a round-trip. Check for unnecessary http→https or www→non-www redirects.',
            'critical-request-chains': 'Break long request chains by inlining critical resources and preloading key assets.',
        };

        for (const [k, v] of Object.entries(guidance)) {
            if (key.includes(k)) return v;
        }
        return '';
    }

    // ─── Helpers ────────────

    // ─── AI Chat — Troubleshooting Assistant ────────────

    const aiChatState = {
        conversation: [],
        isOpen: false,
        isSending: false,
        pendingImages: [], // Array of { dataUri, mediaType, base64 }
    };

    function initAiChat() {
        const toggle = $('#ai-chat-toggle');
        const close = $('#ai-chat-close');
        const clear = $('#ai-chat-clear');
        const send = $('#ai-chat-send');
        const input = $('#ai-chat-input');

        if (!toggle) return;

        toggle.addEventListener('click', () => {
            aiChatState.isOpen = !aiChatState.isOpen;
            const panel = $('#ai-chat-panel');
            if (panel) {
                panel.style.display = aiChatState.isOpen ? 'flex' : 'none';
                if (aiChatState.isOpen && input) {
                    setTimeout(() => input.focus(), 100);
                }
            }
            toggle.classList.toggle('active', aiChatState.isOpen);
        });

        if (close) close.addEventListener('click', () => {
            aiChatState.isOpen = false;
            const panel = $('#ai-chat-panel');
            if (panel) panel.style.display = 'none';
            const toggleBtn = $('#ai-chat-toggle');
            if (toggleBtn) toggleBtn.classList.remove('active');
        });

        if (clear) clear.addEventListener('click', aiChatClear);

        if (send) send.addEventListener('click', aiChatSend);

        // Image attach handlers
        const fileInput = $('#ai-chat-file-input');
        const attachBtn = $('#ai-chat-attach');

        if (attachBtn && fileInput) {
            attachBtn.addEventListener('click', () => fileInput.click());
            fileInput.addEventListener('change', aiChatHandleImages);
        }

        // Delegate click for individual image remove buttons in preview
        const previewContainer = $('#ai-chat-image-preview');
        if (previewContainer) {
            previewContainer.addEventListener('click', (e) => {
                const removeBtn = e.target.closest('.ccm-ai-chat-image-preview-remove');
                if (removeBtn) {
                    const idx = parseInt(removeBtn.dataset.idx, 10);
                    aiChatRemoveImage(idx);
                }
            });
        }

        if (input) {
            input.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    aiChatSend();
                }
            });
            // Auto-resize textarea
            input.addEventListener('input', () => {
                input.style.height = 'auto';
                input.style.height = Math.min(input.scrollHeight, 120) + 'px';
            });
        }
    }

    function aiChatClear() {
        aiChatState.conversation = [];
        aiChatClearImages();
        const messages = $('#ai-chat-messages');
        if (messages) {
            messages.innerHTML = `<div class="ccm-ai-chat-msg ccm-ai-chat-msg-assistant">
                <div class="ccm-ai-chat-msg-content">Hi! I'm the AI Troubleshooter. If your site has issues after optimization (broken animations, missing elements, non-working features), describe the problem and I'll help identify which setting to adjust.</div>
            </div>`;
        }
    }

    function aiChatAppendMessage(role, content, imageDataUris = []) {
        const messages = $('#ai-chat-messages');
        if (!messages) return;

        const msg = document.createElement('div');
        msg.className = `ccm-ai-chat-msg ccm-ai-chat-msg-${role}`;

        const contentEl = document.createElement('div');
        contentEl.className = 'ccm-ai-chat-msg-content';

        if (role === 'assistant') {
            // Render markdown-like formatting for AI responses
            contentEl.innerHTML = aiChatFormatMarkdown(content);
        } else {
            // User message — may include images
            if (imageDataUris.length > 0) {
                const imgWrap = document.createElement('div');
                imgWrap.className = 'ccm-ai-chat-msg-images';
                imageDataUris.forEach(uri => {
                    const img = document.createElement('img');
                    img.src = uri;
                    img.alt = 'Screenshot';
                    img.className = 'ccm-ai-chat-msg-image';
                    img.addEventListener('click', () => window.open(uri, '_blank'));
                    imgWrap.appendChild(img);
                });
                contentEl.appendChild(imgWrap);
            }
            if (content) {
                const textSpan = document.createElement('span');
                textSpan.textContent = content;
                contentEl.appendChild(textSpan);
            }
        }

        msg.appendChild(contentEl);
        messages.appendChild(msg);
        messages.scrollTop = messages.scrollHeight;

        return msg;
    }

    function aiChatFormatMarkdown(text) {
        // Simple markdown → HTML for chat responses
        let html = text
            // Code blocks
            .replace(/```(\w*)\n([\s\S]*?)```/g, '<pre><code>$2</code></pre>')
            // Inline code
            .replace(/`([^`]+)`/g, '<code>$1</code>')
            // Bold
            .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
            // Italic
            .replace(/\*(.+?)\*/g, '<em>$1</em>')
            // Headers
            .replace(/^### (.+)$/gm, '<strong style="display:block;margin-top:0.5em;">$1</strong>')
            .replace(/^## (.+)$/gm, '<strong style="display:block;font-size:1.05em;margin-top:0.5em;">$1</strong>')
            // Lists
            .replace(/^- (.+)$/gm, '<li>$1</li>')
            .replace(/^(\d+)\. (.+)$/gm, '<li>$2</li>');

        // Wrap consecutive <li> items in <ul>
        html = html.replace(/(<li>[\s\S]*?<\/li>)/g, (match) => {
            if (!match.startsWith('<ul>')) {
                return '<ul>' + match + '</ul>';
            }
            return match;
        });
        // Clean up nested ul tags from consecutive items
        html = html.replace(/<\/ul>\s*<ul>/g, '');

        // Paragraphs — split on double newlines
        html = html.split(/\n\n+/).map(p => {
            p = p.trim();
            if (!p) return '';
            if (p.startsWith('<pre>') || p.startsWith('<ul>') || p.startsWith('<strong style=')) return p;
            return `<p>${p.replace(/\n/g, '<br>')}</p>`;
        }).join('');

        return html;
    }

    /** Handle image file selection for chat (supports multiple files) */
    function aiChatHandleImages(e) {
        const files = Array.from(e.target.files);
        if (!files.length) return;

        const maxSize = 5 * 1024 * 1024; // 5MB per file
        const maxImages = 5;
        const remaining = maxImages - aiChatState.pendingImages.length;

        if (remaining <= 0) {
            showNotification('Maximum 5 images per message', 'warning');
            e.target.value = '';
            return;
        }

        const toProcess = files.slice(0, remaining);
        let processed = 0;

        toProcess.forEach(file => {
            if (!file.type.startsWith('image/')) {
                showNotification(`${file.name}: Not an image file`, 'error');
                return;
            }
            if (file.size > maxSize) {
                showNotification(`${file.name}: Must be under 5 MB`, 'error');
                return;
            }

            const reader = new FileReader();
            reader.onload = (ev) => {
                const dataUri = ev.target.result;
                const headerMatch = dataUri.match(/^data:(image\/[a-z+]+);base64,/);
                if (!headerMatch) return;

                aiChatState.pendingImages.push({ dataUri, mediaType: headerMatch[1] });
                aiChatRenderImagePreviews();

                processed++;
            };
            reader.readAsDataURL(file);
        });

        // Reset so the same files can be re-selected
        e.target.value = '';
    }

    /** Render all pending image previews */
    function aiChatRenderImagePreviews() {
        const preview = $('#ai-chat-image-preview');
        if (!preview) return;

        if (aiChatState.pendingImages.length === 0) {
            preview.style.display = 'none';
            preview.innerHTML = '';
            return;
        }

        preview.style.display = 'flex';
        preview.innerHTML = aiChatState.pendingImages.map((img, idx) =>
            `<div class="ccm-ai-chat-image-preview-item">
                <img src="${img.dataUri}" alt="Preview">
                <button class="ccm-ai-chat-image-preview-remove" type="button" data-idx="${idx}" title="Remove">&times;</button>
            </div>`
        ).join('');
    }

    /** Remove a single pending image by index */
    function aiChatRemoveImage(idx) {
        aiChatState.pendingImages.splice(idx, 1);
        aiChatRenderImagePreviews();
    }

    /** Clear all pending images from chat */
    function aiChatClearImages() {
        aiChatState.pendingImages = [];
        const preview = $('#ai-chat-image-preview');
        if (preview) {
            preview.style.display = 'none';
            preview.innerHTML = '';
        }
    }

    async function aiChatSend() {
        if (aiChatState.isSending) return;

        const input = $('#ai-chat-input');
        const sendBtn = $('#ai-chat-send');
        if (!input) return;

        const message = input.value.trim();
        const hasImages = aiChatState.pendingImages.length > 0;

        // Need at least a message or images
        if (!message && !hasImages) return;

        aiChatState.isSending = true;
        input.value = '';
        input.style.height = 'auto';
        if (sendBtn) sendBtn.disabled = true;

        // Capture image data before clearing
        const imageDataUris = hasImages ? aiChatState.pendingImages.map(i => i.dataUri) : [];
        aiChatClearImages();

        // Add user message (with optional image thumbnails)
        aiChatAppendMessage('user', message, imageDataUris);

        // Show typing indicator
        const messages = $('#ai-chat-messages');
        const typing = document.createElement('div');
        typing.className = 'ccm-ai-chat-msg ccm-ai-chat-msg-assistant ccm-ai-chat-typing';
        typing.innerHTML = '<div class="ccm-ai-chat-msg-content"><span class="ccm-ai-chat-dots"><span></span><span></span><span></span></span></div>';
        if (messages) {
            messages.appendChild(typing);
            messages.scrollTop = messages.scrollHeight;
        }

        try {
            const siteUrl = ($('#ai-ps-url') || {}).value || '';

            const imgCount = imageDataUris.length;
            const ajaxData = {
                message: message || `(${imgCount} screenshot${imgCount > 1 ? 's' : ''} attached — please analyze)`,
                conversation: JSON.stringify(aiChatState.conversation),
                site_url: siteUrl,
            };

            // Include images as JSON array of data URIs
            if (imgCount > 0) {
                ajaxData.images = JSON.stringify(imageDataUris);
            }

            const res = await ajax('ccm_tools_ai_chat', ajaxData, { timeout: 90000 });

            // Remove typing indicator
            if (typing.parentElement) typing.remove();

            const reply = res.data?.reply || 'Sorry, I could not generate a response.';

            // Update conversation history (don't store full base64 — just note images were sent)
            const imgNote = hasImages ? ` [${imgCount} screenshot${imgCount > 1 ? 's' : ''} attached]` : '';
            aiChatState.conversation.push({ role: 'user', content: (message || '') + imgNote });
            aiChatState.conversation.push({ role: 'assistant', content: reply });

            // Keep conversation manageable (last 20 messages)
            if (aiChatState.conversation.length > 20) {
                aiChatState.conversation = aiChatState.conversation.slice(-20);
            }

            aiChatAppendMessage('assistant', reply);

        } catch (err) {
            if (typing.parentElement) typing.remove();
            aiChatAppendMessage('assistant', `Sorry, there was an error: ${err.message}. Please try again.`);
        } finally {
            aiChatState.isSending = false;
            if (sendBtn) sendBtn.disabled = false;
            if (input) input.focus();
        }
    }

    function aiSleep(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }

    async function aiHubLoadHistory() {
        const container = $('#ai-history-table');
        if (!container) return;

        container.innerHTML = '<div class="ccm-spinner ccm-spinner-small"></div>';

        try {
            const res = await ajax('ccm_tools_ai_hub_get_results', {
                limit: 10,
            }, { timeout: 15000 });

            const data = res.data || {};
            const mobileResults = data.mobile || [];
            const desktopResults = data.desktop || [];
            const runs = data.runs || [];

            let html = '';

            // ── Optimization Runs ──
            if (runs.length) {
                html += '<h4 style="margin:0 0 0.75rem;">Optimization Runs</h4>';
                html += '<div class="ccm-ai-history-runs">';
                runs.forEach(run => {
                    const date = run.date ? new Date(run.date).toLocaleString() : '—';
                    const mobileChange = run.after_mobile - run.before_mobile;
                    const desktopChange = run.after_desktop - run.before_desktop;
                    const mobileChangeStr = mobileChange !== 0 ? `(${mobileChange > 0 ? '+' : ''}${mobileChange})` : '';
                    const desktopChangeStr = desktopChange !== 0 ? `(${desktopChange > 0 ? '+' : ''}${desktopChange})` : '';
                    const mobileChangeClass = mobileChange > 0 ? 'ccm-score-green' : (mobileChange < 0 ? 'ccm-score-red' : '');
                    const desktopChangeClass = desktopChange > 0 ? 'ccm-score-green' : (desktopChange < 0 ? 'ccm-score-red' : '');

                    let outcomeLabel = '';
                    if (run.rolled_back) outcomeLabel = '<span class="ccm-badge ccm-badge-warning">Rolled Back</span>';
                    else if (run.outcome === 'no_changes') outcomeLabel = '<span class="ccm-badge ccm-badge-info">No Changes</span>';
                    else if (mobileChange > 0 || desktopChange > 0) outcomeLabel = '<span class="ccm-badge ccm-badge-success">Improved</span>';
                    else outcomeLabel = '<span class="ccm-badge ccm-badge-info">Complete</span>';

                    // Build changes summary
                    let changesList = '';
                    if (run.changes && run.changes.length) {
                        const labels = run.changes.map(c => {
                            const k = typeof c === 'object' && c.key ? c.key : String(c);
                            return aiSettingLabel(k);
                        });
                        changesList = `<div class="ccm-ai-run-changes">${labels.map(l => `<span class="ccm-ai-run-change-tag">${l}</span>`).join('')}</div>`;
                    }

                    html += `<div class="ccm-ai-run-card">
                        <div class="ccm-ai-run-header">
                            <span class="ccm-ai-run-date">${date}</span>
                            ${outcomeLabel}
                            ${run.iterations > 1 ? `<span class="ccm-badge ccm-badge-info">${run.iterations} iterations</span>` : ''}
                        </div>
                        <div class="ccm-ai-run-scores">
                            <div class="ccm-ai-run-strategy">
                                <span class="ccm-ai-run-strategy-label">Mobile</span>
                                <span class="${aiScoreColorClass(run.before_mobile)}">${run.before_mobile}</span>
                                <span class="ccm-ai-run-arrow">→</span>
                                <strong class="${aiScoreColorClass(run.after_mobile)}">${run.after_mobile}</strong>
                                <span class="${mobileChangeClass}" style="font-size:0.8rem;">${mobileChangeStr}</span>
                            </div>
                            <div class="ccm-ai-run-strategy">
                                <span class="ccm-ai-run-strategy-label">Desktop</span>
                                <span class="${aiScoreColorClass(run.before_desktop)}">${run.before_desktop}</span>
                                <span class="ccm-ai-run-arrow">→</span>
                                <strong class="${aiScoreColorClass(run.after_desktop)}">${run.after_desktop}</strong>
                                <span class="${desktopChangeClass}" style="font-size:0.8rem;">${desktopChangeStr}</span>
                            </div>
                        </div>
                        ${run.changes_count ? `<div class="ccm-ai-run-meta">${run.changes_count} setting${run.changes_count !== 1 ? 's' : ''} changed</div>` : ''}
                        ${changesList}
                    </div>`;
                });
                html += '</div>';
            }

            // ── PageSpeed Test Results (paired mobile + desktop) ──
            if (mobileResults.length || desktopResults.length) {
                html += '<h4 style="margin:1.25rem 0 0.75rem;">PageSpeed Test Results</h4>';

                // Pair mobile and desktop by matching timestamps (within 5 min)
                const paired = aiPairResults(mobileResults, desktopResults);

                html += '<div class="ccm-ai-history-results">';
                paired.forEach(pair => {
                    const date = pair.date ? new Date(pair.date).toLocaleString() : '—';
                    const m = pair.mobile;
                    const d = pair.desktop;

                    html += `<div class="ccm-ai-result-card">
                        <div class="ccm-ai-result-date">${date}</div>
                        <div class="ccm-ai-result-scores">`;

                    if (m) {
                        html += `<div class="ccm-ai-result-strategy">
                            <span class="ccm-ai-result-strategy-label">Mobile</span>
                            <div class="ccm-ai-result-score-row">
                                <span class="ccm-ai-result-score ${aiScoreColorClass(m.scores?.performance)}">${m.scores?.performance ?? '—'}</span>
                                <span class="ccm-ai-result-metrics">A:${m.scores?.accessibility ?? '—'} BP:${m.scores?.best_practices ?? '—'} SEO:${m.scores?.seo ?? '—'}</span>
                            </div>
                            <span class="ccm-ai-result-lcp">${m.metrics?.lcp_ms ? `LCP ${Number(m.metrics.lcp_ms).toLocaleString()}ms` : ''}</span>
                        </div>`;
                    }

                    if (d) {
                        html += `<div class="ccm-ai-result-strategy">
                            <span class="ccm-ai-result-strategy-label">Desktop</span>
                            <div class="ccm-ai-result-score-row">
                                <span class="ccm-ai-result-score ${aiScoreColorClass(d.scores?.performance)}">${d.scores?.performance ?? '—'}</span>
                                <span class="ccm-ai-result-metrics">A:${d.scores?.accessibility ?? '—'} BP:${d.scores?.best_practices ?? '—'} SEO:${d.scores?.seo ?? '—'}</span>
                            </div>
                            <span class="ccm-ai-result-lcp">${d.metrics?.lcp_ms ? `LCP ${Number(d.metrics.lcp_ms).toLocaleString()}ms` : ''}</span>
                        </div>`;
                    }

                    html += '</div></div>';
                });
                html += '</div>';
            }

            if (!html) {
                html = '<p style="opacity:0.6;">No results yet. Run a test to get started.</p>';
            }

            container.innerHTML = html;
        } catch (err) {
            container.innerHTML = `<p class="ccm-error">${err.message || 'Failed to load history.'}</p>`;
        }
    }

    /**
     * Pair mobile and desktop results by timestamp proximity (within 5 minutes).
     * Returns an array of { date, mobile, desktop } objects.
     */
    function aiPairResults(mobileResults, desktopResults) {
        const paired = [];
        const usedDesktop = new Set();
        const THRESHOLD = 5 * 60 * 1000; // 5 minutes

        mobileResults.forEach(m => {
            const mTime = m.tested_at ? new Date(m.tested_at).getTime() : 0;
            let bestMatch = null;
            let bestDiff = Infinity;

            desktopResults.forEach((d, idx) => {
                if (usedDesktop.has(idx)) return;
                const dTime = d.tested_at ? new Date(d.tested_at).getTime() : 0;
                const diff = Math.abs(mTime - dTime);
                if (diff < THRESHOLD && diff < bestDiff) {
                    bestMatch = idx;
                    bestDiff = diff;
                }
            });

            const entry = { date: m.tested_at, mobile: m, desktop: null };
            if (bestMatch !== null) {
                entry.desktop = desktopResults[bestMatch];
                usedDesktop.add(bestMatch);
            }
            paired.push(entry);
        });

        // Add unmatched desktop results
        desktopResults.forEach((d, idx) => {
            if (!usedDesktop.has(idx)) {
                paired.push({ date: d.tested_at, mobile: null, desktop: d });
            }
        });

        // Sort by date descending
        paired.sort((a, b) => new Date(b.date || 0) - new Date(a.date || 0));
        return paired;
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
