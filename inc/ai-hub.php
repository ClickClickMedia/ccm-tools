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
add_action('wp_ajax_ccm_tools_ai_preflight', 'ccm_tools_ajax_ai_preflight');
add_action('wp_ajax_ccm_tools_ai_enable_tool', 'ccm_tools_ajax_ai_enable_tool');

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
 * Get cached PageSpeed results from hub (fetches both strategies)
 */
function ccm_tools_ajax_ai_hub_get_results(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Unauthorized']);
    }

    $limit = min(max((int)($_POST['limit'] ?? 10), 1), 50);

    // Fetch both strategies
    $mobile = ccm_tools_ai_hub_request('pagespeed/results', [
        'strategy' => 'mobile',
        'limit'    => $limit,
    ]);

    $desktop = ccm_tools_ai_hub_request('pagespeed/results', [
        'strategy' => 'desktop',
        'limit'    => $limit,
    ]);

    $mobile_results = [];
    $desktop_results = [];

    if (!is_wp_error($mobile)) {
        $mobile_results = $mobile['results'] ?? $mobile;
        if (!is_array($mobile_results)) $mobile_results = [];
    }

    if (!is_wp_error($desktop)) {
        $desktop_results = $desktop['results'] ?? $desktop;
        if (!is_array($desktop_results)) $desktop_results = [];
    }

    // Get stored optimization run log
    $runs = get_option('ccm_tools_ai_optimization_runs', []);

    wp_send_json_success([
        'mobile'  => $mobile_results,
        'desktop' => $desktop_results,
        'runs'    => array_slice($runs, 0, $limit),
    ]);
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
    $url = esc_url_raw(sanitize_text_field($_POST['url'] ?? site_url()));
    $context = '';

    if (class_exists('WooCommerce')) {
        $context .= 'WooCommerce site. ';
    }

    // Add learnings from previous optimization runs
    $learnings = ccm_tools_build_learnings_context($url);
    if (!empty($learnings)) {
        $context .= "\n" . $learnings;
    }

    // Add server-side tool status for AI context
    $toolStatus = ccm_tools_get_optimization_status();
    if (!$toolStatus['htaccess']['applied']) {
        $context .= 'WARNING: .htaccess optimizations NOT applied (no browser caching, no gzip/brotli compression). ';
    }
    if (!empty($toolStatus['webp']['available']) && empty($toolStatus['webp']['enabled'])) {
        $context .= 'WARNING: WebP image conversion NOT enabled (images served as JPG/PNG). ';
    }
    if (!empty($toolStatus['redis']['extension']) && empty($toolStatus['redis']['dropin'])) {
        $context .= 'Redis PHP extension available but object cache NOT installed. ';
    }
    if (!empty($toolStatus['database']['needs_optimization'])) {
        $context .= 'Database has ' . $toolStatus['database']['tables_needing_optimization'] . ' tables needing optimization. ';
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

        // Add learnings from previous optimization runs
        $learnings = ccm_tools_build_learnings_context($url);
        if (!empty($learnings)) {
            $context .= "\n" . $learnings;
        }

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

// ─── Optimization Run History ────────────────────────────────────

add_action('wp_ajax_ccm_tools_ai_save_run', 'ccm_tools_ajax_ai_save_run');

/**
 * Save an optimization run summary to the local log.
 * Stored as a capped array in wp_options.
 */
function ccm_tools_ajax_ai_save_run(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Unauthorized']);
    }

    $run_data = json_decode(wp_unslash($_POST['run_data'] ?? ''), true);
    if (!is_array($run_data) || empty($run_data)) {
        wp_send_json_error(['message' => 'No run data provided']);
    }

    // Sanitize
    $run = [
        'date'            => current_time('mysql'),
        'url'             => esc_url_raw($run_data['url'] ?? ''),
        'before_mobile'   => intval($run_data['before_mobile'] ?? 0),
        'before_desktop'  => intval($run_data['before_desktop'] ?? 0),
        'after_mobile'    => intval($run_data['after_mobile'] ?? 0),
        'after_desktop'   => intval($run_data['after_desktop'] ?? 0),
        'changes_count'   => intval($run_data['changes_count'] ?? 0),
        'changes'         => [],
        'iterations'      => intval($run_data['iterations'] ?? 1),
        'rolled_back'     => !empty($run_data['rolled_back']),
        'outcome'         => sanitize_text_field($run_data['outcome'] ?? 'completed'),
    ];

    // Store changes with from/to values for learning
    if (!empty($run_data['changes']) && is_array($run_data['changes'])) {
        foreach (array_slice($run_data['changes'], 0, 30) as $change) {
            if (is_array($change) && !empty($change['key'])) {
                $entry = ['key' => sanitize_text_field($change['key'])];
                if (isset($change['from'])) $entry['from'] = $change['from'];
                if (isset($change['to']))   $entry['to']   = $change['to'];
                $run['changes'][] = $entry;
            } elseif (is_string($change)) {
                $run['changes'][] = ['key' => sanitize_text_field($change)];
            }
        }
    }

    $runs = get_option('ccm_tools_ai_optimization_runs', []);
    if (!is_array($runs)) $runs = [];

    array_unshift($runs, $run);
    $runs = array_slice($runs, 0, 20); // Keep last 20 runs

    update_option('ccm_tools_ai_optimization_runs', $runs, false);

    wp_send_json_success(['message' => 'Run saved', 'run' => $run]);
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

// ─── Snapshot & Rollback ─────────────────────────────────────────

add_action('wp_ajax_ccm_tools_ai_snapshot_settings', 'ccm_tools_ajax_ai_snapshot_settings');

/**
 * Save a snapshot of current performance settings (for rollback)
 */
function ccm_tools_ajax_ai_snapshot_settings(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Unauthorized']);
    }

    $settings = ccm_tools_perf_get_settings();
    set_transient('ccm_tools_perf_snapshot', $settings, HOUR_IN_SECONDS);

    wp_send_json_success([
        'message'  => 'Settings snapshot saved',
        'settings' => $settings,
    ]);
}

add_action('wp_ajax_ccm_tools_ai_rollback_settings', 'ccm_tools_ajax_ai_rollback_settings');

/**
 * Rollback performance settings to the saved snapshot
 */
function ccm_tools_ajax_ai_rollback_settings(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Unauthorized']);
    }

    $snapshot = get_transient('ccm_tools_perf_snapshot');

    if (!is_array($snapshot) || empty($snapshot)) {
        wp_send_json_error(['message' => 'No snapshot found to rollback to']);
    }

    ccm_tools_perf_save_settings($snapshot);

    wp_send_json_success([
        'message'  => 'Settings rolled back to snapshot',
        'settings' => $snapshot,
    ]);
}

// ─── Preflight: Tool Status & Auto-Enable ────────────────────────

/**
 * Build learnings context from previous optimization runs for the same URL.
 *
 * Creates a structured summary the AI can use to avoid repeating mistakes and
 * build on successful strategies. Includes score deltas, which settings helped
 * or hurt, and overall patterns.
 *
 * @param string $url The URL being optimized (for matching past runs)
 * @return string Formatted context string, or empty if no relevant history
 */
function ccm_tools_build_learnings_context(string $url = ''): string {
    $runs = get_option('ccm_tools_ai_optimization_runs', []);
    if (!is_array($runs) || empty($runs)) {
        return '';
    }

    // Filter to runs for this URL (or all runs if URL is empty/different)
    $relevant = [];
    $normalized_url = rtrim(strtolower($url), '/');
    foreach ($runs as $run) {
        $run_url = rtrim(strtolower($run['url'] ?? ''), '/');
        // Match exact URL or same domain (allow path variation)
        if (empty($normalized_url) || $run_url === $normalized_url || parse_url($run_url, PHP_URL_HOST) === parse_url($normalized_url, PHP_URL_HOST)) {
            $relevant[] = $run;
        }
    }

    if (empty($relevant)) {
        return '';
    }

    // Limit to last 10 runs
    $relevant = array_slice($relevant, 0, 10);

    // Build wins and losses
    $wins = [];   // settings that improved scores
    $losses = []; // settings that caused rollbacks
    $patterns = ['improved' => 0, 'rolled_back' => 0, 'no_changes' => 0];

    foreach ($relevant as $run) {
        $outcome = $run['outcome'] ?? 'completed';
        if (isset($patterns[$outcome])) {
            $patterns[$outcome]++;
        }

        $m_delta = ($run['after_mobile'] ?? 0) - ($run['before_mobile'] ?? 0);
        $d_delta = ($run['after_desktop'] ?? 0) - ($run['before_desktop'] ?? 0);
        $changes = $run['changes'] ?? [];

        if ($outcome === 'improved' && !empty($changes)) {
            foreach ($changes as $change) {
                $key = is_array($change) ? ($change['key'] ?? '') : $change;
                if (empty($key)) continue;
                if (!isset($wins[$key])) {
                    $wins[$key] = ['count' => 0, 'total_m_delta' => 0, 'total_d_delta' => 0, 'values' => []];
                }
                $wins[$key]['count']++;
                $wins[$key]['total_m_delta'] += $m_delta;
                $wins[$key]['total_d_delta'] += $d_delta;
                if (is_array($change) && isset($change['to'])) {
                    $wins[$key]['values'][] = $change['to'];
                }
            }
        } elseif ($outcome === 'rolled_back' && !empty($changes)) {
            foreach ($changes as $change) {
                $key = is_array($change) ? ($change['key'] ?? '') : $change;
                if (empty($key)) continue;
                if (!isset($losses[$key])) {
                    $losses[$key] = ['count' => 0, 'total_m_delta' => 0, 'total_d_delta' => 0, 'values' => []];
                }
                $losses[$key]['count']++;
                $losses[$key]['total_m_delta'] += $m_delta;
                $losses[$key]['total_d_delta'] += $d_delta;
                if (is_array($change) && isset($change['to'])) {
                    $losses[$key]['values'][] = $change['to'];
                }
            }
        }
    }

    // Build the context string
    $out = "## Previous Optimization History (AI Memory)\n";
    $out .= "The following is data from previous optimization runs on this site. Use it to make better decisions.\n\n";

    // Overview
    $total = count($relevant);
    $out .= "**Runs:** {$total} total — {$patterns['improved']} improved, {$patterns['rolled_back']} rolled back, {$patterns['no_changes']} no changes\n";

    // Best scores achieved
    $best_m = 0;
    $best_d = 0;
    foreach ($relevant as $r) {
        $best_m = max($best_m, $r['after_mobile'] ?? 0, $r['before_mobile'] ?? 0);
        $best_d = max($best_d, $r['after_desktop'] ?? 0, $r['before_desktop'] ?? 0);
    }
    if ($best_m > 0 || $best_d > 0) {
        $out .= "**Best scores achieved:** Mobile {$best_m}, Desktop {$best_d}\n\n";
    }

    // Settings that helped
    if (!empty($wins)) {
        $out .= "### Settings That IMPROVED Scores (REPEAT these)\n";
        // Sort by count desc
        uasort($wins, function ($a, $b) { return $b['count'] - $a['count']; });
        foreach ($wins as $key => $data) {
            $avg_m = $data['count'] > 0 ? round($data['total_m_delta'] / $data['count']) : 0;
            $avg_d = $data['count'] > 0 ? round($data['total_d_delta'] / $data['count']) : 0;
            $m_fmt = sprintf('%+d', $avg_m);
            $d_fmt = sprintf('%+d', $avg_d);
            $out .= "- `{$key}`: helped {$data['count']}x (avg Mobile {$m_fmt}, Desktop {$d_fmt})";
            // Show most recent value used
            if (!empty($data['values'])) {
                $last_val = end($data['values']);
                if (is_bool($last_val)) {
                    $out .= " → " . ($last_val ? 'true' : 'false');
                } elseif (is_scalar($last_val) && strlen((string)$last_val) < 60) {
                    $out .= " → " . (string)$last_val;
                }
            }
            $out .= "\n";
        }
        $out .= "\n";
    }

    // Settings that hurt
    if (!empty($losses)) {
        $out .= "### Settings That CAUSED ROLLBACKS (AVOID these)\n";
        uasort($losses, function ($a, $b) { return $b['count'] - $a['count']; });
        foreach ($losses as $key => $data) {
            $avg_m = $data['count'] > 0 ? round($data['total_m_delta'] / $data['count']) : 0;
            $avg_d = $data['count'] > 0 ? round($data['total_d_delta'] / $data['count']) : 0;
            $m_fmt = sprintf('%+d', $avg_m);
            $d_fmt = sprintf('%+d', $avg_d);
            $out .= "- `{$key}`: caused rollback {$data['count']}x (avg Mobile {$m_fmt}, Desktop {$d_fmt})";
            if (!empty($data['values'])) {
                $last_val = end($data['values']);
                if (is_bool($last_val)) {
                    $out .= " → " . ($last_val ? 'true' : 'false');
                } elseif (is_scalar($last_val) && strlen((string)$last_val) < 60) {
                    $out .= " → " . (string)$last_val;
                }
            }
            $out .= "\n";
        }
        $out .= "\n";
    }

    // Recent run details (last 3)
    $out .= "### Recent Runs (newest first)\n";
    foreach (array_slice($relevant, 0, 3) as $run) {
        $date = $run['date'] ?? '?';
        $m_before = $run['before_mobile'] ?? 0;
        $d_before = $run['before_desktop'] ?? 0;
        $m_after = $run['after_mobile'] ?? 0;
        $d_after = $run['after_desktop'] ?? 0;
        $outcome = $run['outcome'] ?? '?';
        $iters = $run['iterations'] ?? 1;
        $m_delta = $m_after - $m_before;
        $d_delta = $d_after - $d_before;

        $m_delta_fmt = sprintf('%+d', $m_delta);
        $d_delta_fmt = sprintf('%+d', $d_delta);
        $out .= "- [{$date}] {$outcome} in {$iters} iter(s): Mobile {$m_before}→{$m_after} ({$m_delta_fmt}), Desktop {$d_before}→{$d_after} ({$d_delta_fmt})";
        $change_keys = [];
        foreach (($run['changes'] ?? []) as $c) {
            $change_keys[] = is_array($c) ? ($c['key'] ?? '?') : $c;
        }
        if (!empty($change_keys)) {
            $out .= " | Changed: " . implode(', ', array_slice($change_keys, 0, 10));
        }
        $out .= "\n";
    }

    return $out;
}

/**
 * Get optimization status of all server-side tools
 */
function ccm_tools_get_optimization_status(): array {
    $status = [];

    // .htaccess
    $htaccess_file = ABSPATH . '.htaccess';
    $htaccess_content = file_exists($htaccess_file) ? file_get_contents($htaccess_file) : '';
    $status['htaccess'] = [
        'applied' => strpos($htaccess_content, '# BEGIN CCM Optimise') !== false,
        'writable' => file_exists($htaccess_file) ? is_writable($htaccess_file) : is_writable(ABSPATH),
    ];
    if ($status['htaccess']['applied'] && function_exists('ccm_tools_detect_htaccess_options')) {
        $status['htaccess']['options'] = ccm_tools_detect_htaccess_options($htaccess_content);
    }

    // WebP
    if (function_exists('ccm_tools_webp_get_settings')) {
        $webp = ccm_tools_webp_get_settings();
        $extensions = function_exists('ccm_tools_webp_get_available_extensions')
            ? ccm_tools_webp_get_available_extensions() : [];
        $status['webp'] = [
            'available'      => !empty($extensions),
            'enabled'        => !empty($webp['enabled']),
            'serve_webp'     => !empty($webp['serve_webp']),
            'picture_tags'   => !empty($webp['use_picture_tags']),
            'on_demand'      => !empty($webp['convert_on_demand']),
            'bg_images'      => !empty($webp['convert_bg_images']),
        ];
    } else {
        $status['webp'] = ['available' => false, 'enabled' => false];
    }

    // Redis
    $status['redis'] = [
        'extension' => extension_loaded('redis'),
        'dropin'    => false,
        'connected' => false,
    ];
    if (function_exists('ccm_tools_redis_dropin_status')) {
        $redis_status = ccm_tools_redis_dropin_status();
        $status['redis']['dropin'] = !empty($redis_status['is_ccm']);
    }
    if ($status['redis']['extension'] && function_exists('ccm_tools_redis_check_connection')) {
        $conn = ccm_tools_redis_check_connection();
        $status['redis']['connected'] = !empty($conn['connected']);
    }

    // Database
    global $wpdb;
    $tables_needing = 0;
    try {
        $tables = $wpdb->get_results("SHOW TABLE STATUS WHERE Engine != 'InnoDB' OR Collation != 'utf8mb4_unicode_ci'");
        $tables_needing = $tables ? count($tables) : 0;
    } catch (\Exception $e) {
        // ignore
    }
    $status['database'] = [
        'needs_optimization' => $tables_needing > 0,
        'tables_needing_optimization' => $tables_needing,
    ];

    // Performance Optimizer
    if (function_exists('ccm_tools_perf_get_settings')) {
        $perf = ccm_tools_perf_get_settings();
        $status['performance'] = [
            'enabled' => !empty($perf['enabled']),
        ];
    }

    return $status;
}

/**
 * AJAX: Get tool status for preflight check
 */
function ccm_tools_ajax_ai_preflight(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Unauthorized']);
    }

    wp_send_json_success(ccm_tools_get_optimization_status());
}

/**
 * AJAX: Enable a specific server-side tool
 */
function ccm_tools_ajax_ai_enable_tool(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Unauthorized']);
    }

    $tool = sanitize_text_field($_POST['tool'] ?? '');
    $result = ['tool' => $tool, 'success' => false, 'message' => 'Unknown tool'];

    switch ($tool) {
        case 'htaccess':
            if (function_exists('ccm_tools_update_htaccess')) {
                $options = [
                    'caching'          => true,
                    'compression'      => true,
                    'security_headers' => true,
                    'etag_removal'     => true,
                    'disable_indexes'  => true,
                    'hsts_basic'       => true,
                ];
                $r = ccm_tools_update_htaccess('add', $options);
                $result['success'] = !empty($r['success']);
                $result['message'] = $r['message'] ?? 'Unknown error';
            }
            break;

        case 'webp':
            if (function_exists('ccm_tools_webp_get_settings') && function_exists('ccm_tools_webp_save_settings')) {
                $settings = ccm_tools_webp_get_settings();
                $settings['enabled']           = true;
                $settings['serve_webp']        = true;
                $settings['convert_on_demand'] = true;
                $settings['use_picture_tags']  = true;
                $settings['convert_bg_images'] = true;
                ccm_tools_webp_save_settings($settings);
                $result['success'] = true;
                $result['message'] = 'WebP enabled (on-demand, picture tags, BG images)';
            } else {
                $result['message'] = 'WebP converter not available (no image extension)';
            }
            break;

        case 'redis':
            if (!extension_loaded('redis')) {
                $result['message'] = 'PHP Redis extension not installed';
            } elseif (function_exists('ccm_tools_redis_dropin_status') && function_exists('ccm_tools_redis_install_dropin')) {
                $status = ccm_tools_redis_dropin_status();
                if (!empty($status['is_ccm'])) {
                    $result['success'] = true;
                    $result['message'] = 'Redis drop-in already installed';
                } else {
                    // Test connection first
                    if (function_exists('ccm_tools_redis_check_connection')) {
                        $conn = ccm_tools_redis_check_connection();
                        if (empty($conn['connected'])) {
                            $result['message'] = 'Redis server not reachable';
                            break;
                        }
                    }
                    $r = ccm_tools_redis_install_dropin();
                    $result['success'] = !is_wp_error($r) && $r !== false;
                    $result['message'] = $result['success'] ? 'Redis drop-in installed' : 'Failed to install Redis drop-in';
                }
            }
            break;

        case 'performance':
            if (function_exists('ccm_tools_perf_get_settings') && function_exists('ccm_tools_perf_save_settings')) {
                $settings = ccm_tools_perf_get_settings();
                $settings['enabled'] = true;
                ccm_tools_perf_save_settings($settings);
                $result['success'] = true;
                $result['message'] = 'Performance optimizer enabled';
            }
            break;
    }

    wp_send_json_success($result);
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
                    <div class="ccm-ai-connection-row">
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
                <div class="ccm-form-field">
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

            <!-- Activity Log (terminal-style) -->
            <div id="ai-activity-log-wrapper" style="display: none; margin-top: 1rem;">
                <div class="ccm-ai-log-header">
                    <span><?php _e('Activity Log', 'ccm-tools'); ?></span>
                    <button type="button" id="ai-log-clear-btn" class="ccm-ai-log-btn" title="<?php esc_attr_e('Clear log', 'ccm-tools'); ?>">Clear</button>
                </div>
                <div id="ai-activity-log" class="ccm-ai-log"></div>
            </div>

            <!-- Results Area — dual strategy tabs -->
            <div id="ai-results-area" style="display: none; margin-top: 1.5rem;">
                <div class="ccm-ai-strategy-tabs">
                    <button type="button" class="ccm-ai-tab active" data-strategy="mobile"><?php _e('Mobile', 'ccm-tools'); ?></button>
                    <button type="button" class="ccm-ai-tab" data-strategy="desktop"><?php _e('Desktop', 'ccm-tools'); ?></button>
                </div>
                <div id="ai-results-mobile" class="ccm-ai-strategy-panel active">
                    <div id="ai-ps-scores-mobile" class="ccm-ai-scores-grid"></div>
                    <details class="ccm-ai-accordion">
                        <summary class="ccm-ai-accordion-summary"><?php _e('Metrics', 'ccm-tools'); ?></summary>
                        <div id="ai-ps-metrics-mobile" class="ccm-ai-accordion-body"></div>
                    </details>
                    <details class="ccm-ai-accordion">
                        <summary class="ccm-ai-accordion-summary"><?php _e('Opportunities', 'ccm-tools'); ?></summary>
                        <div id="ai-ps-opportunities-mobile" class="ccm-ai-accordion-body"></div>
                    </details>
                </div>
                <div id="ai-results-desktop" class="ccm-ai-strategy-panel" style="display: none;">
                    <div id="ai-ps-scores-desktop" class="ccm-ai-scores-grid"></div>
                    <details class="ccm-ai-accordion">
                        <summary class="ccm-ai-accordion-summary"><?php _e('Metrics', 'ccm-tools'); ?></summary>
                        <div id="ai-ps-metrics-desktop" class="ccm-ai-accordion-body"></div>
                    </details>
                    <details class="ccm-ai-accordion">
                        <summary class="ccm-ai-accordion-summary"><?php _e('Opportunities', 'ccm-tools'); ?></summary>
                        <div id="ai-ps-opportunities-desktop" class="ccm-ai-accordion-body"></div>
                    </details>
                </div>
            </div>

            <!-- Fix Summary (auto vs manual — populated by one-click flow) -->
            <div id="ai-fix-summary" style="display: none; margin-top: 1.5rem;"></div>

            <!-- Before/After Comparison -->
            <div id="ai-before-after" style="display: none; margin-top: 1.5rem;"></div>

            <!-- Remaining Recommendations (when score < 90) -->
            <div id="ai-remaining-recommendations" style="display: none; margin-top: 1.5rem;"></div>

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
