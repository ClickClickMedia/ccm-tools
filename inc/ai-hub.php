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
        return new WP_Error('hub_invalid_response', 'Invalid response from hub (non-JSON or empty)');
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
 * Handles complex value types: booleans, integers, arrays (URL lists, exclude lists),
 * and large strings (critical CSS code). Auto-enables corresponding boolean toggles
 * when data values are set (e.g., setting preconnect_urls also enables preconnect).
 * 
 * @param array $recommendations Array of recommendations from AI analysis
 * @return bool Whether settings were successfully applied
 */
function ccm_tools_ai_hub_apply_recommendations(array $recommendations): bool {
    $settings = ccm_tools_perf_get_settings();
    $changed = false;

    // Map data keys to their parent boolean toggle
    // When the AI sets a data key, auto-enable the corresponding feature toggle
    $data_to_toggle = [
        'critical_css_code'  => 'critical_css',
        'preconnect_urls'    => 'preconnect',
        'dns_prefetch_urls'  => 'dns_prefetch',
        'lcp_preload_url'    => 'lcp_preload',
    ];

    // Keys that contain CSS code — must NOT use sanitize_text_field (it strips tags/newlines)
    $css_keys = ['critical_css_code'];

    // Keys that contain URLs — sanitize individually as URLs
    $url_array_keys = ['preconnect_urls', 'dns_prefetch_urls'];

    // Keys that contain string arrays (handles, URL fragments) — sanitize individually
    $string_array_keys = ['defer_js_excludes', 'delay_js_excludes', 'preload_css_excludes'];

    // Keys that contain a single URL
    $url_keys = ['lcp_preload_url'];

    foreach ($recommendations as $rec) {
        $key = $rec['setting_key'] ?? '';
        $value = $rec['recommended_value'] ?? null;

        if (empty($key) || $value === null) continue;

        // Only apply known settings
        if (!array_key_exists($key, $settings)) continue;

        // Type-match and sanitize the value
        if (is_bool($settings[$key])) {
            $settings[$key] = (bool)$value;
        } elseif (is_int($settings[$key])) {
            $settings[$key] = (int)$value;
        } elseif (in_array($key, $css_keys, true)) {
            // CSS code: strip PHP tags and null bytes, but preserve CSS content
            if (is_string($value)) {
                $value = str_replace(['<?php', '<?', '?>'], '', $value);
                $value = str_replace("\0", '', $value);
                $settings[$key] = $value;
            }
        } elseif (in_array($key, $url_array_keys, true)) {
            // Array of URLs
            if (is_array($value)) {
                $settings[$key] = array_values(array_filter(array_map('esc_url_raw', $value)));
            }
        } elseif (in_array($key, $string_array_keys, true)) {
            // Array of handles/fragments
            if (is_array($value)) {
                $settings[$key] = array_values(array_filter(array_map('sanitize_text_field', $value)));
            }
        } elseif (in_array($key, $url_keys, true)) {
            // Single URL
            if (is_string($value)) {
                $settings[$key] = esc_url_raw($value);
            }
        } elseif (is_array($settings[$key])) {
            // Generic array fallback
            if (is_array($value)) {
                $settings[$key] = array_map('sanitize_text_field', $value);
            }
        } else {
            // Generic string
            $settings[$key] = sanitize_text_field((string)$value);
        }

        $changed = true;

        // Auto-enable the parent boolean toggle if this is a data key
        if (isset($data_to_toggle[$key])) {
            $toggleKey = $data_to_toggle[$key];
            if (array_key_exists($toggleKey, $settings)) {
                $settings[$toggleKey] = true;
            }
        }
    }

    if ($changed) {
        // Ensure the optimizer is enabled
        $settings['enabled'] = true;
        return ccm_tools_perf_save_settings($settings);
    }

    return false;
}

// ─── Admin Page Section ─────────────────────────────────────────

add_action('wp_ajax_ccm_tools_ai_apply_changes', 'ccm_tools_ajax_ai_apply_changes');

/**
 * Apply AI-recommended changes to Performance Optimizer settings
 */
function ccm_tools_ajax_ai_apply_changes(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Unauthorized']);
    }

    $recommendations_raw = wp_unslash($_POST['recommendations'] ?? '');
    $recommendations = json_decode($recommendations_raw, true);

    if (!is_array($recommendations) || empty($recommendations)) {
        wp_send_json_error(['message' => 'No recommendations to apply']);
    }

    $before = ccm_tools_perf_get_settings();
    $applied = ccm_tools_ai_hub_apply_recommendations($recommendations);
    $after = ccm_tools_perf_get_settings();

    // Calculate what changed
    $changes = [];
    foreach ($after as $key => $value) {
        if (isset($before[$key]) && $before[$key] !== $value) {
            $changes[] = [
                'key'  => $key,
                'from' => $before[$key],
                'to'   => $value,
            ];
        }
    }

    wp_send_json_success([
        'applied'  => $applied,
        'changes'  => $changes,
        'settings' => $after,
    ]);
}

/**
 * Render the AI Optimizer section (embedded in the Performance page)
 */
function ccm_tools_render_ai_section(): void {
    $settings = ccm_tools_ai_hub_get_settings();
    $hasKey = !empty($settings['api_key']);
    ?>
    <div class="ccm-card ccm-ai-hero-card">
        <div class="ccm-card-header" style="display: flex; align-items: center; justify-content: space-between;">
            <div>
                <h2 style="margin: 0;">AI Performance Optimizer</h2>
                <p class="ccm-subtitle" style="margin: 0.25rem 0 0;">One-click AI-powered analysis and automatic optimization</p>
            </div>
            <span id="ai-hub-status" class="ccm-badge <?php echo $hasKey ? 'ccm-badge-info' : 'ccm-badge-warning'; ?>"><?php echo $hasKey ? 'Configured' : 'Not Connected'; ?></span>
        </div>
        <div class="ccm-card-body">
            <!-- Connection Settings (collapsible) -->
            <details id="ai-connection-details"<?php echo $hasKey ? '' : ' open'; ?>>
                <summary style="cursor: pointer; font-weight: 600; padding: 0.5rem 0;"><?php _e('Hub Connection', 'ccm-tools'); ?></summary>
                <div style="padding: 0.75rem 0;">
                    <input type="hidden" id="ai-hub-url" value="<?php echo esc_attr($settings['hub_url']); ?>">
                    <div style="display: grid; grid-template-columns: 1fr auto auto; gap: 0.5rem; align-items: end;">
                        <div class="ccm-form-field">
                            <label for="ai-hub-key"><?php _e('API Key', 'ccm-tools'); ?></label>
                            <input type="password" id="ai-hub-key" value="<?php echo esc_attr($settings['api_key']); ?>" placeholder="ccm_xxxx..." class="ccm-input">
                        </div>
                        <button type="button" id="ai-hub-save-btn" class="ccm-button ccm-button-secondary"><?php _e('Save', 'ccm-tools'); ?></button>
                        <button type="button" id="ai-hub-test-btn" class="ccm-button ccm-button-secondary"><?php _e('Test', 'ccm-tools'); ?></button>
                    </div>
                    <div id="ai-hub-test-result" style="margin-top: 0.5rem;"></div>
                </div>
            </details>

            <!-- URL & Action Controls -->
            <div class="ccm-ai-controls">
                <div class="ccm-form-field" style="flex: 1; min-width: 200px;">
                    <label for="ai-ps-url"><?php _e('URL to Test', 'ccm-tools'); ?></label>
                    <input type="url" id="ai-ps-url" value="<?php echo esc_url(site_url()); ?>" class="ccm-input">
                </div>
                <button type="button" id="ai-one-click-btn" class="ccm-button ccm-button-primary ccm-ai-cta" <?php echo $hasKey ? '' : 'disabled'; ?>>
                    🚀 <?php _e('One-Click Optimize', 'ccm-tools'); ?>
                </button>
                <button type="button" id="ai-ps-run-btn" class="ccm-button ccm-button-secondary">
                    <?php _e('Test Only', 'ccm-tools'); ?>
                </button>
            </div>
            <p class="ccm-text-muted" style="margin-top: 0.25rem; font-size: var(--ccm-text-sm);"><?php _e('One-Click Optimize tests both Mobile &amp; Desktop, analyses results with AI, and applies fixes automatically.', 'ccm-tools'); ?></p>

            <!-- Step Progress -->
            <div id="ai-progress" style="display: none; margin-top: 1.5rem;">
                <div id="ai-steps" class="ccm-ai-steps"></div>
            </div>

            <!-- Results Area — dual strategy tabs -->
            <div id="ai-results-area" style="display: none; margin-top: 1.5rem;">
                <div class="ccm-ai-strategy-tabs">
                    <button type="button" class="ccm-ai-tab active" data-strategy="mobile"><?php _e('Mobile', 'ccm-tools'); ?></button>
                    <button type="button" class="ccm-ai-tab" data-strategy="desktop"><?php _e('Desktop', 'ccm-tools'); ?></button>
                </div>
                <div id="ai-results-mobile" class="ccm-ai-strategy-panel active">
                    <div id="ai-ps-scores-mobile" class="ccm-ai-scores-grid"></div>
                    <div id="ai-ps-metrics-mobile" style="margin-bottom: 1rem;"></div>
                    <div id="ai-ps-opportunities-mobile"></div>
                </div>
                <div id="ai-results-desktop" class="ccm-ai-strategy-panel" style="display: none;">
                    <div id="ai-ps-scores-desktop" class="ccm-ai-scores-grid"></div>
                    <div id="ai-ps-metrics-desktop" style="margin-bottom: 1rem;"></div>
                    <div id="ai-ps-opportunities-desktop"></div>
                </div>
            </div>

            <!-- Fix Summary (auto vs manual — populated by one-click flow) -->
            <div id="ai-fix-summary" style="display: none; margin-top: 1.5rem;"></div>

            <!-- Before/After Comparison -->
            <div id="ai-before-after" style="display: none; margin-top: 1.5rem;"></div>

            <!-- AI Analysis (for standalone analyze) -->
            <div id="ai-analysis-loading" style="display: none; text-align: center; padding: 2rem;">
                <div class="ccm-spinner"></div>
                <p style="margin-top: 0.5rem;"><?php _e('AI is analyzing your PageSpeed results...', 'ccm-tools'); ?></p>
            </div>
            <div id="ai-analysis-results" style="margin-top: 1rem; display: none;"></div>

            <!-- History (collapsible) -->
            <details style="margin-top: 1.5rem;">
                <summary style="cursor: pointer; font-weight: 600; padding: 0.5rem 0;"><?php _e('Recent Results History', 'ccm-tools'); ?></summary>
                <div id="ai-history-table" style="padding-top: 0.75rem;"></div>
            </details>
        </div>
    </div>
    <?php
}
