<?php
/**
 * AI Performance Hub Integration
 * 
 * Client-side integration for the CCM API Hub.
 * Handles API key storage, hub communication, and the AI optimization UI.
 * 
 * @package CCM_Tools
 * @since 7.11.0
 */

// Prevent direct file access
if (!defined('ABSPATH')) {
    exit;
}

// ─── Settings & Constants ────────────────────────────────────────

/**
 * Get AI Hub settings
 * 
 * @return array Current settings
 */
function ccm_tools_ai_hub_get_settings(): array {
    static $cached = null;
    if ($cached !== null) return $cached;

    $defaults = [
        'enabled'  => false,
        'hub_url'  => 'https://api.tools.clickclick.media',
        'api_key'  => '', // ccm_xxxx... stored encrypted in WP options
        'site_url' => site_url(),
    ];

    $settings = get_option('ccm_tools_ai_hub_settings', []);
    $cached = wp_parse_args($settings, $defaults);
    return $cached;
}

/**
 * Save AI Hub settings
 */
function ccm_tools_ai_hub_save_settings(array $settings): bool {
    return update_option('ccm_tools_ai_hub_settings', $settings);
}

// ─── Hub Communication ──────────────────────────────────────────

/**
 * Make an authenticated request to the API Hub
 * 
 * @param string $endpoint  API endpoint path (e.g. 'health', 'pagespeed/test')
 * @param array  $body      Request body (JSON encoded)
 * @param string $method    HTTP method (default: POST)
 * @param int    $timeout   Request timeout in seconds
 * @return array|WP_Error   Response data or error
 */
function ccm_tools_ai_hub_request(string $endpoint, array $body = [], string $method = 'POST', int $timeout = 120) {
    $settings = ccm_tools_ai_hub_get_settings();

    if (empty($settings['hub_url']) || empty($settings['api_key'])) {
        return new WP_Error('hub_not_configured', __('AI Hub is not configured. Please enter your API key.', 'ccm-tools'));
    }

    $url = rtrim($settings['hub_url'], '/') . '/api/v1/' . ltrim($endpoint, '/');

    $args = [
        'method'  => $method,
        'timeout' => $timeout,
        'headers' => [
            'Content-Type'   => 'application/json',
            'Accept'         => 'application/json',
            'X-CCM-Api-Key'  => $settings['api_key'],
            'X-CCM-Site-Url' => $settings['site_url'],
        ],
    ];

    if (!empty($body) && $method !== 'GET') {
        $args['body'] = wp_json_encode($body);
    }

    $response = wp_remote_request($url, $args);

    if (is_wp_error($response)) {
        return $response;
    }

    $code = wp_remote_retrieve_response_code($response);
    $responseBody = wp_remote_retrieve_body($response);
    $data = json_decode($responseBody, true);

    if ($code >= 400) {
        $message = $data['error'] ?? "HTTP {$code}";
        return new WP_Error('hub_error', $message, ['status' => $code]);
    }

    if (!is_array($data)) {
        $snippet = substr(trim($responseBody), 0, 200);
        return new WP_Error('hub_invalid_response', "Invalid response from hub (HTTP {$code}): {$snippet}");
    }

    return $data;
}

// ─── AJAX Handlers ──────────────────────────────────────────────

add_action('wp_ajax_ccm_tools_ai_hub_save_settings', 'ccm_tools_ajax_ai_hub_save_settings');
add_action('wp_ajax_ccm_tools_ai_hub_test_connection', 'ccm_tools_ajax_ai_hub_test_connection');
add_action('wp_ajax_ccm_tools_ai_hub_run_pagespeed', 'ccm_tools_ajax_ai_hub_run_pagespeed');
add_action('wp_ajax_ccm_tools_ai_hub_get_results', 'ccm_tools_ajax_ai_hub_get_results');
add_action('wp_ajax_ccm_tools_ai_hub_ai_analyze', 'ccm_tools_ajax_ai_hub_ai_analyze');
add_action('wp_ajax_ccm_tools_ai_hub_ai_optimize', 'ccm_tools_ajax_ai_hub_ai_optimize');

/**
 * Save AI Hub connection settings
 */
function ccm_tools_ajax_ai_hub_save_settings(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Unauthorized']);
    }

    $settings = ccm_tools_ai_hub_get_settings();
    $settings['enabled'] = !empty($_POST['enabled']);
    $settings['hub_url'] = esc_url_raw(rtrim(sanitize_text_field($_POST['hub_url'] ?? ''), '/'));
    $settings['site_url'] = site_url();

    // Only update API key if provided (don't blank it)
    $newKey = sanitize_text_field($_POST['api_key'] ?? '');
    if (!empty($newKey)) {
        $settings['api_key'] = $newKey;
    }

    ccm_tools_ai_hub_save_settings($settings);

    wp_send_json_success(['message' => 'Settings saved. Test the connection to verify.']);
}

/**
 * Test connection to the AI Hub
 */
function ccm_tools_ajax_ai_hub_test_connection(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Unauthorized']);
    }

    $result = ccm_tools_ai_hub_request('health');

    if (is_wp_error($result)) {
        wp_send_json_error(['message' => $result->get_error_message()]);
    }

    wp_send_json_success([
        'message'  => 'Connected to ' . ($result['site_name'] ?? 'AI Hub'),
        'features' => $result['features'] ?? [],
        'limits'   => $result['limits'] ?? [],
        'version'  => $result['hub_version'] ?? 'unknown',
    ]);
}

/**
 * Run a PageSpeed test via the hub
 */
function ccm_tools_ajax_ai_hub_run_pagespeed(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Unauthorized']);
    }

    $url = esc_url_raw(sanitize_text_field($_POST['url'] ?? site_url()));
    $strategy = in_array($_POST['strategy'] ?? '', ['mobile', 'desktop']) ? $_POST['strategy'] : 'mobile';
    $force = !empty($_POST['force']);

    $result = ccm_tools_ai_hub_request('pagespeed/test', [
        'url'      => $url,
        'strategy' => $strategy,
        'force'    => $force,
    ]);

    if (is_wp_error($result)) {
        wp_send_json_error(['message' => $result->get_error_message()]);
    }

    wp_send_json_success($result);
}

/**
 * Get cached PageSpeed results from hub
 */
function ccm_tools_ajax_ai_hub_get_results(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Unauthorized']);
    }

    $strategy = in_array($_POST['strategy'] ?? '', ['mobile', 'desktop']) ? $_POST['strategy'] : 'mobile';
    $limit = min(max((int)($_POST['limit'] ?? 10), 1), 50);

    $result = ccm_tools_ai_hub_request('pagespeed/results', [
        'strategy' => $strategy,
        'limit'    => $limit,
    ]);

    if (is_wp_error($result)) {
        wp_send_json_error(['message' => $result->get_error_message()]);
    }

    wp_send_json_success($result);
}

/**
 * Request AI analysis of a PageSpeed result
 */
function ccm_tools_ajax_ai_hub_ai_analyze(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Unauthorized']);
    }

    $resultId = (int)($_POST['result_id'] ?? 0);
    if ($resultId <= 0) {
        wp_send_json_error(['message' => 'result_id is required']);
    }

    // Send current Performance Optimizer settings for context
    $currentSettings = ccm_tools_perf_get_settings();
    $context = '';

    if (class_exists('WooCommerce')) {
        $context .= 'WooCommerce site. ';
    }

    $result = ccm_tools_ai_hub_request('ai/analyze', [
        'result_id'        => $resultId,
        'current_settings' => $currentSettings,
        'context'          => $context,
    ]);

    if (is_wp_error($result)) {
        wp_send_json_error(['message' => $result->get_error_message()]);
    }

    wp_send_json_success($result);
}

/**
 * Run full AI optimization session (start/retest/complete)
 */
function ccm_tools_ajax_ai_hub_ai_optimize(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Unauthorized']);
    }

    $action = sanitize_text_field($_POST['optimize_action'] ?? 'start');
    $sessionId = (int)($_POST['session_id'] ?? 0);
    $url = esc_url_raw(sanitize_text_field($_POST['url'] ?? site_url()));
    $strategy = in_array($_POST['strategy'] ?? '', ['mobile', 'desktop']) ? $_POST['strategy'] : 'mobile';

    $body = [
        'action'   => $action,
        'url'      => $url,
        'strategy' => $strategy,
    ];

    if ($action === 'start') {
        $body['current_settings'] = ccm_tools_perf_get_settings();
        $body['session_type'] = sanitize_text_field($_POST['session_type'] ?? 'full_audit');

        $context = '';
        if (class_exists('WooCommerce')) $context .= 'WooCommerce site. ';
        $body['context'] = $context;
    }

    if ($action === 'retest' || $action === 'complete') {
        $body['session_id'] = $sessionId;
        $body['applied_settings'] = ccm_tools_perf_get_settings();
    }

    $result = ccm_tools_ai_hub_request('ai/optimize', $body, 'POST', 180);

    if (is_wp_error($result)) {
        wp_send_json_error(['message' => $result->get_error_message()]);
    }

    // If hub returned recommended settings, apply them automatically if auto_apply enabled
    $data = $result;

    if ($action === 'start' && !empty($data['analysis']['recommendations'])) {
        $data['auto_applied'] = false;

        // Check if auto-apply was requested
        if (!empty($_POST['auto_apply'])) {
            $applied = ccm_tools_ai_hub_apply_recommendations($data['analysis']['recommendations']);
            $data['auto_applied'] = $applied;
        }
    }

    wp_send_json_success($data);
}

/**
 * Apply AI recommendations to Performance Optimizer settings
 * 
 * @param array $recommendations Array of recommendations from AI analysis
 * @return bool Whether settings were successfully applied
 */
function ccm_tools_ai_hub_apply_recommendations(array $recommendations): bool {
    $settings = ccm_tools_perf_get_settings();
    $changed = false;

    foreach ($recommendations as $rec) {
        $key = $rec['setting_key'] ?? '';
        $value = $rec['recommended_value'] ?? null;

        if (empty($key) || $value === null) continue;

        // Only apply known settings
        if (!array_key_exists($key, $settings)) continue;

        // Type-match the value
        if (is_bool($settings[$key])) {
            $settings[$key] = (bool)$value;
        } elseif (is_int($settings[$key])) {
            $settings[$key] = (int)$value;
        } elseif (is_array($settings[$key])) {
            if (is_array($value)) {
                $settings[$key] = array_map('sanitize_text_field', $value);
            }
        } else {
            $settings[$key] = sanitize_text_field((string)$value);
        }

        $changed = true;
    }

    if ($changed) {
        // Ensure the optimizer is enabled
        $settings['enabled'] = true;
        return ccm_tools_perf_save_settings($settings);
    }

    return false;
}

// ─── Admin Page Rendering ───────────────────────────────────────

/**
 * Render the AI Performance page
 */
function ccm_tools_render_ai_hub_page(): void {
    $settings = ccm_tools_ai_hub_get_settings();
    ?>
    <div class="wrap ccm-tools">
        <?php ccm_tools_render_header_nav('ccm-tools-ai'); ?>
        
        <div class="ccm-content">
            <div class="ccm-page-header">
                <div class="ccm-page-title">
                    <h2>AI Performance</h2>
                    <p class="ccm-subtitle">AI-powered PageSpeed analysis and automatic optimization via the CCM API Hub</p>
                </div>
            </div>

            <!-- Connection Settings Card -->
            <div class="ccm-card">
                <div class="ccm-card-header">
                    <h3>Hub Connection</h3>
                    <span id="ai-hub-status" class="ccm-badge">—</span>
                </div>
                <div class="ccm-card-body">
                    <input type="hidden" id="ai-hub-url" value="<?php echo esc_attr($settings['hub_url']); ?>">
                    <div class="ccm-form-field">
                        <label for="ai-hub-key">API Key</label>
                        <input type="password" id="ai-hub-key" value="<?php echo esc_attr($settings['api_key']); ?>" placeholder="ccm_xxxx...">
                    </div>
                    <div style="margin-top: 1rem; display: flex; gap: 0.5rem;">
                        <button type="button" id="ai-hub-save-btn" class="ccm-button ccm-button-primary">Save Settings</button>
                        <button type="button" id="ai-hub-test-btn" class="ccm-button ccm-button-secondary">Test Connection</button>
                    </div>
                    <div id="ai-hub-test-result" style="margin-top: 0.75rem;"></div>
                </div>
            </div>

            <!-- PageSpeed Test Card -->
            <div class="ccm-card" style="margin-top: 1.5rem;">
                <div class="ccm-card-header">
                    <h3>PageSpeed Test</h3>
                </div>
                <div class="ccm-card-body">
                    <div class="ccm-form-grid" style="display: grid; grid-template-columns: 2fr 1fr auto; gap: 1rem; align-items: end;">
                        <div class="ccm-form-field">
                            <label for="ai-ps-url">URL to Test</label>
                            <input type="url" id="ai-ps-url" value="<?php echo esc_attr(site_url()); ?>" placeholder="<?php echo esc_attr(site_url()); ?>">
                        </div>
                        <div class="ccm-form-field">
                            <label for="ai-ps-strategy">Strategy</label>
                            <select id="ai-ps-strategy">
                                <option value="mobile">Mobile</option>
                                <option value="desktop">Desktop</option>
                            </select>
                        </div>
                        <div class="ccm-form-field">
                            <button type="button" id="ai-ps-run-btn" class="ccm-button ccm-button-primary">Run Test</button>
                        </div>
                    </div>
                    <div id="ai-ps-results" style="margin-top: 1rem; display: none;">
                        <div id="ai-ps-scores" class="ccm-stats-row" style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 1rem;"></div>
                        <div id="ai-ps-metrics" style="margin-bottom: 1rem;"></div>
                        <div id="ai-ps-opportunities"></div>
                    </div>
                    <div id="ai-ps-loading" style="display: none; text-align: center; padding: 2rem;">
                        <div class="ccm-spinner"></div>
                        <p style="margin-top: 0.5rem;">Running PageSpeed test... This may take up to 60 seconds.</p>
                    </div>
                </div>
            </div>

            <!-- AI Analysis Card -->
            <div class="ccm-card" style="margin-top: 1.5rem;">
                <div class="ccm-card-header">
                    <h3>AI Analysis & Optimization</h3>
                </div>
                <div class="ccm-card-body">
                    <p class="ccm-subtitle">Run a PageSpeed test first, then use AI to analyze the results and get optimization recommendations.</p>
                    <div style="display: flex; gap: 0.5rem; margin-top: 1rem;">
                        <button type="button" id="ai-analyze-btn" class="ccm-button ccm-button-primary" disabled>Analyze with AI</button>
                        <button type="button" id="ai-optimize-btn" class="ccm-button ccm-button-success" disabled>⚡ Auto-Optimize</button>
                    </div>
                    <div id="ai-analysis-loading" style="display: none; text-align: center; padding: 2rem;">
                        <div class="ccm-spinner"></div>
                        <p style="margin-top: 0.5rem;">AI is analyzing your PageSpeed results...</p>
                    </div>
                    <div id="ai-analysis-results" style="margin-top: 1rem; display: none;"></div>
                </div>
            </div>

            <!-- Optimization Session Card -->
            <div id="ai-session-card" class="ccm-card" style="margin-top: 1.5rem; display: none;">
                <div class="ccm-card-header">
                    <h3>Optimization Session</h3>
                    <span id="ai-session-status" class="ccm-badge">—</span>
                </div>
                <div class="ccm-card-body">
                    <div id="ai-session-progress"></div>
                    <div id="ai-session-log" style="margin-top: 1rem; max-height: 300px; overflow-y: auto; font-family: monospace; font-size: 0.85rem; background: #f5f5f5; padding: 1rem; border-radius: 4px;"></div>
                </div>
            </div>

            <!-- Results History Card -->
            <div class="ccm-card" style="margin-top: 1.5rem;">
                <div class="ccm-card-header">
                    <h3>Recent Results</h3>
                    <button type="button" id="ai-history-refresh-btn" class="ccm-button ccm-button-sm ccm-button-secondary">Refresh</button>
                </div>
                <div class="ccm-card-body">
                    <div id="ai-history-table"></div>
                </div>
            </div>
        </div>
    </div>
    <?php
}
