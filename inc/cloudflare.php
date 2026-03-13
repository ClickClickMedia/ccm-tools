<?php
/**
 * CCM Tools — Cloudflare Integration
 *
 * Provides Cloudflare detection, cache purging, development mode toggle,
 * and a read-only status dashboard for connected zones.
 *
 * @package CCMTools
 */

if (!defined('ABSPATH')) {
    exit;
}

// ──────────────────────────────────────────────
// Settings helpers
// ──────────────────────────────────────────────

/**
 * Get Cloudflare settings from the database.
 *
 * @return array
 */
function ccm_tools_cf_get_settings(): array {
    $defaults = array(
        'api_token'  => '',
        'zone_id'    => '',
        'connected'  => false,
    );
    return wp_parse_args(get_option('ccm_tools_cf_settings', array()), $defaults);
}

/**
 * Save Cloudflare settings.
 *
 * @param array $settings
 * @return void
 */
function ccm_tools_cf_save_settings(array $settings): void {
    update_option('ccm_tools_cf_settings', $settings);
}

// ──────────────────────────────────────────────
// Detection
// ──────────────────────────────────────────────

/**
 * Detect whether the site is behind Cloudflare by making a self-request
 * and checking for CF-specific headers. Cached in a short transient.
 *
 * @return array  {detected: bool, ray_id?: string, server?: string}
 */
function ccm_tools_cf_detect(): array {
    $cached = get_transient('ccm_tools_cf_detected');
    if (is_array($cached)) {
        return $cached;
    }

    $result = array('detected' => false);

    $response = wp_remote_head(home_url('/'), array(
        'timeout'   => 5,
        'sslverify' => false,
    ));

    if (!is_wp_error($response)) {
        $headers = wp_remote_retrieve_headers($response);
        if (!empty($headers['cf-ray'])) {
            $result['detected'] = true;
            $result['ray_id']   = sanitize_text_field($headers['cf-ray']);
        }
        if (!empty($headers['server']) && stripos($headers['server'], 'cloudflare') !== false) {
            $result['detected'] = true;
            $result['server']   = sanitize_text_field($headers['server']);
        }
    }

    set_transient('ccm_tools_cf_detected', $result, 5 * MINUTE_IN_SECONDS);
    return $result;
}

// ──────────────────────────────────────────────
// Cloudflare API helpers
// ──────────────────────────────────────────────

/**
 * Make a request to the Cloudflare API v4.
 *
 * @param string $endpoint  Path after /client/v4/ (e.g. "zones/{id}/purge_cache").
 * @param string $method    HTTP method.
 * @param array  $body      Request body (will be JSON-encoded for POST/PUT/PATCH).
 * @return array|WP_Error   Decoded JSON body, or WP_Error.
 */
function ccm_tools_cf_api(string $endpoint, string $method = 'GET', array $body = array(), string $token = '') {
    if (empty($token)) {
        $settings = ccm_tools_cf_get_settings();
        $token    = $settings['api_token'];
    }

    if (empty($token)) {
        return new WP_Error('no_token', __('Cloudflare API token is not configured.', 'ccm-tools'));
    }

    $url = 'https://api.cloudflare.com/client/v4/' . ltrim($endpoint, '/');

    $args = array(
        'method'  => strtoupper($method),
        'timeout' => 15,
        'headers' => array(
            'Authorization' => 'Bearer ' . $token,
        ),
    );

    if (!empty($body) && in_array($args['method'], array('POST', 'PUT', 'PATCH'), true)) {
        $args['headers']['Content-Type'] = 'application/json';
        $args['body'] = wp_json_encode($body);
    }

    $response = wp_remote_request($url, $args);
    if (is_wp_error($response)) {
        return new WP_Error('cf_http_error', $response->get_error_message() . ' (cURL/' . $response->get_error_code() . ')');
    }

    $code = wp_remote_retrieve_response_code($response);
    $raw  = wp_remote_retrieve_body($response);
    $data = json_decode($raw, true);

    if ($code < 200 || $code >= 300 || empty($data['success'])) {
        $msg = 'Cloudflare API error (HTTP ' . $code . ')';
        if (!empty($data['errors'][0]['message'])) {
            $msg = $data['errors'][0]['message'] . ' (HTTP ' . $code . ')';
        } elseif (empty($data)) {
            $msg = 'Unexpected response (HTTP ' . $code . '): ' . mb_substr($raw, 0, 200);
        }
        return new WP_Error('cf_api_error', $msg, array('status' => $code, 'response' => $data));
    }

    return $data;
}

/**
 * Verify the API Token works and determine the Zone ID.
 *
 * @param string $token  API token to test.
 * @param string $zone_id Manually supplied zone ID (optional — auto-detect if empty).
 * @return array|WP_Error  Zone details on success.
 */
function ccm_tools_cf_verify_token(string $token, string $zone_id = '') {
    // If zone_id supplied, verify token by fetching the zone directly
    if (!empty($zone_id)) {
        $zone_id = sanitize_text_field($zone_id);
        $data = ccm_tools_cf_api('zones/' . $zone_id, 'GET', array(), $token);
        if (is_wp_error($data)) {
            return new WP_Error('token_invalid', __('Could not access zone: ', 'ccm-tools') . $data->get_error_message());
        }
        return $data['result'] ?? $data;
    }

    // Auto-detect zone from site domain — this also validates the token
    $domain = wp_parse_url(home_url(), PHP_URL_HOST);
    $domain = preg_replace('/^www\./i', '', $domain);

    // Walk up subdomains to find the zone (e.g. sub.example.com → example.com)
    $parts = explode('.', $domain);
    $last_error = null;
    while (count($parts) >= 2) {
        $try = implode('.', $parts);
        $data = ccm_tools_cf_api('zones?name=' . urlencode($try) . '&status=active', 'GET', array(), $token);

        // If the API returned an auth/request error, the token itself is bad
        if (is_wp_error($data)) {
            $last_error = $data;
            $error_data = $data->get_error_data();
            $status = $error_data['status'] ?? 0;
            if (in_array($status, array(400, 401, 403), true)) {
                return new WP_Error('token_invalid', __('API Token verification failed: ', 'ccm-tools') . $data->get_error_message());
            }
            array_shift($parts);
            continue;
        }

        if (!empty($data['result'][0]['id'])) {
            return $data['result'][0];
        }
        array_shift($parts);
    }

    // If we had a transport/API error, surface it
    if ($last_error) {
        return new WP_Error('token_invalid', __('API Token verification failed: ', 'ccm-tools') . $last_error->get_error_message());
    }

    return new WP_Error('zone_not_found', __('Token is valid but no Cloudflare zone found for this domain. Please enter the Zone ID manually.', 'ccm-tools'));
}

/**
 * Fetch zone details + feature settings for the status panel.
 *
 * @return array|WP_Error
 */
function ccm_tools_cf_get_zone_status() {
    $settings = ccm_tools_cf_get_settings();
    if (empty($settings['zone_id'])) {
        return new WP_Error('no_zone', __('Cloudflare is not connected.', 'ccm-tools'));
    }

    $zone_id = $settings['zone_id'];
    $zone    = ccm_tools_cf_api('zones/' . $zone_id);
    if (is_wp_error($zone)) {
        return $zone;
    }

    $zone_result = $zone['result'] ?? array();

    // Fetch feature settings we care about
    $feature_keys = array(
        'polish', 'minify', 'rocket_loader', 'always_online',
        'browser_cache_ttl', 'development_mode', 'webp',
    );

    $features = array();
    foreach ($feature_keys as $key) {
        $resp = ccm_tools_cf_api('zones/' . $zone_id . '/settings/' . $key);
        if (!is_wp_error($resp) && isset($resp['result']['value'])) {
            $features[$key] = $resp['result']['value'];
        }
    }

    // Check APO
    $apo = ccm_tools_cf_api('zones/' . $zone_id . '/settings/automatic_platform_optimization');
    if (!is_wp_error($apo) && isset($apo['result']['value'])) {
        $features['apo'] = $apo['result']['value'];
    }

    return array(
        'zone'     => array(
            'id'     => $zone_result['id'] ?? '',
            'name'   => $zone_result['name'] ?? '',
            'status' => $zone_result['status'] ?? '',
            'plan'   => $zone_result['plan']['name'] ?? 'Unknown',
        ),
        'features' => $features,
    );
}

/**
 * Purge the entire Cloudflare cache for the configured zone.
 *
 * @return true|WP_Error
 */
function ccm_tools_cf_purge_all() {
    $settings = ccm_tools_cf_get_settings();
    if (empty($settings['zone_id'])) {
        return new WP_Error('no_zone', __('Cloudflare is not connected.', 'ccm-tools'));
    }

    $data = ccm_tools_cf_api(
        'zones/' . $settings['zone_id'] . '/purge_cache',
        'POST',
        array('purge_everything' => true)
    );

    return is_wp_error($data) ? $data : true;
}

/**
 * Purge specific URLs from Cloudflare cache.
 *
 * @param array $urls List of full URLs to purge.
 * @return true|WP_Error
 */
function ccm_tools_cf_purge_urls(array $urls) {
    $settings = ccm_tools_cf_get_settings();
    if (empty($settings['zone_id'])) {
        return new WP_Error('no_zone', __('Cloudflare is not connected.', 'ccm-tools'));
    }

    // CF allows max 30 URLs per purge request
    $chunks = array_chunk($urls, 30);
    foreach ($chunks as $chunk) {
        $data = ccm_tools_cf_api(
            'zones/' . $settings['zone_id'] . '/purge_cache',
            'POST',
            array('files' => array_values($chunk))
        );
        if (is_wp_error($data)) {
            return $data;
        }
    }

    return true;
}

/**
 * Toggle Cloudflare Development Mode.
 *
 * @param bool $enable True to enable, false to disable.
 * @return true|WP_Error
 */
function ccm_tools_cf_toggle_dev_mode(bool $enable) {
    $settings = ccm_tools_cf_get_settings();
    if (empty($settings['zone_id'])) {
        return new WP_Error('no_zone', __('Cloudflare is not connected.', 'ccm-tools'));
    }

    $data = ccm_tools_cf_api(
        'zones/' . $settings['zone_id'] . '/settings/development_mode',
        'PATCH',
        array('value' => $enable ? 'on' : 'off')
    );

    return is_wp_error($data) ? $data : true;
}


// ──────────────────────────────────────────────
// Admin page renderer
// ──────────────────────────────────────────────

/**
 * Render the Cloudflare Tools admin page.
 */
function ccm_tools_render_cloudflare_page(): void {
    $settings  = ccm_tools_cf_get_settings();
    $connected = !empty($settings['connected']) && !empty($settings['zone_id']);
    $cf_detected = get_transient('ccm_tools_cf_detected');
    ?>
    <div class="wrap ccm-tools">
        <?php ccm_tools_render_header_nav('ccm-tools-cloudflare'); ?>

        <div class="ccm-content">
            <!-- Status Overview -->
            <div class="ccm-card">
                <h2><?php _e('Cloudflare Status', 'ccm-tools'); ?></h2>
                <table class="ccm-table">
                    <tr>
                        <th><?php _e('Cloudflare Detected', 'ccm-tools'); ?></th>
                        <td>
                            <?php if ($cf_detected): ?>
                                <span class="ccm-success"><?php _e('Yes', 'ccm-tools'); ?></span>
                            <?php elseif ($cf_detected === false): ?>
                                <span class="ccm-warning"><?php _e('Not detected', 'ccm-tools'); ?></span>
                            <?php else: ?>
                                <span class="ccm-text-muted"><?php _e('Unknown (not checked yet)', 'ccm-tools'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php _e('API Connection', 'ccm-tools'); ?></th>
                        <td>
                            <?php if ($connected): ?>
                                <span class="ccm-success"><?php _e('Connected', 'ccm-tools'); ?></span>
                            <?php else: ?>
                                <span class="ccm-warning"><?php _e('Not connected', 'ccm-tools'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if ($connected): ?>
                    <tr>
                        <th><?php _e('Zone ID', 'ccm-tools'); ?></th>
                        <td><code><?php echo esc_html($settings['zone_id']); ?></code></td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>

            <!-- Connection Settings -->
            <div class="ccm-card">
                <h2><?php _e('Connection Settings', 'ccm-tools'); ?></h2>
                <p class="ccm-text-muted"><?php _e('Connect using an API Token with Zone:Read, Cache Purge, and Zone Settings:Read permissions.', 'ccm-tools'); ?></p>

                <div id="cf-connection-form" autocomplete="off">
                    <div class="ccm-setting-row" style="padding: var(--ccm-space-md) 0;">
                        <label for="cf-api-token" style="display: block; margin-bottom: var(--ccm-space-xs);">
                            <strong><?php _e('API Token', 'ccm-tools'); ?></strong>
                        </label>
                        <div style="display: flex; gap: var(--ccm-space-sm); align-items: center;">
                            <input type="password" id="cf-api-token"
                                   name="cf_api_token"
                                   autocomplete="new-password"
                                   value="<?php echo esc_attr($settings['api_token']); ?>"
                                   placeholder="<?php esc_attr_e('Enter your Cloudflare API Token', 'ccm-tools'); ?>"
                                   style="flex: 1; padding: var(--ccm-space-sm); border: 1px solid var(--ccm-border); border-radius: var(--ccm-radius); font-family: monospace;">
                            <button type="button" id="cf-toggle-token" class="ccm-button ccm-button-secondary" style="padding: var(--ccm-space-sm) var(--ccm-space-md);" title="<?php esc_attr_e('Show/hide token', 'ccm-tools'); ?>">
                                <span class="ccm-button-icon">👁</span>
                            </button>
                        </div>
                    </div>

                    <div class="ccm-setting-row" style="padding: var(--ccm-space-md) 0;">
                        <label for="cf-zone-id" style="display: block; margin-bottom: var(--ccm-space-xs);">
                            <strong><?php _e('Zone ID', 'ccm-tools'); ?></strong>
                            <span class="ccm-text-muted" style="font-weight: normal;"> — <?php _e('leave blank to auto-detect from your domain', 'ccm-tools'); ?></span>
                        </label>
                        <input type="text" id="cf-zone-id"
                               name="cf_zone_id"
                               autocomplete="off"
                               value="<?php echo esc_attr($settings['zone_id']); ?>"
                               placeholder="<?php esc_attr_e('e.g. a1b2c3d4e5f6...', 'ccm-tools'); ?>"
                               style="width: 100%; max-width: 500px; padding: var(--ccm-space-sm); border: 1px solid var(--ccm-border); border-radius: var(--ccm-radius); font-family: monospace;">
                    </div>

                    <div style="display: flex; gap: var(--ccm-space-sm); align-items: center; padding-top: var(--ccm-space-sm);">
                        <button type="button" id="cf-connect-btn" class="ccm-button">
                            <?php echo $connected ? __('Reconnect', 'ccm-tools') : __('Connect', 'ccm-tools'); ?>
                        </button>
                        <?php if ($connected): ?>
                        <button type="button" id="cf-disconnect-btn" class="ccm-button ccm-button-secondary" style="color: var(--ccm-danger);">
                            <?php _e('Disconnect', 'ccm-tools'); ?>
                        </button>
                        <?php endif; ?>
                        <span id="cf-connection-status"></span>
                    </div>
                </div>
            </div>

            <?php if ($connected): ?>
            <!-- Zone Features -->
            <div class="ccm-card" id="cf-status-card">
                <h2><?php _e('Zone Features', 'ccm-tools'); ?></h2>
                <div id="cf-zone-status">
                    <p class="ccm-text-muted"><?php _e('Loading zone information...', 'ccm-tools'); ?></p>
                </div>
            </div>

            <!-- Cache Management -->
            <div class="ccm-card">
                <h2><?php _e('Cache Management', 'ccm-tools'); ?></h2>

                <div class="ccm-setting-row" style="display: flex; align-items: flex-start; justify-content: space-between; gap: var(--ccm-space-md); padding: var(--ccm-space-md) 0; border-bottom: 1px solid var(--ccm-border);">
                    <div style="flex: 1;">
                        <strong><?php _e('Purge All Cache', 'ccm-tools'); ?></strong>
                        <p class="ccm-text-muted"><?php _e('Clears all cached files from Cloudflare\'s edge servers. Your origin server will be hit for all requests until the cache is rebuilt.', 'ccm-tools'); ?></p>
                    </div>
                    <button type="button" id="cf-purge-all" class="ccm-button">
                        <?php _e('Purge Everything', 'ccm-tools'); ?>
                    </button>
                </div>

                <div class="ccm-setting-row" style="display: flex; align-items: flex-start; justify-content: space-between; gap: var(--ccm-space-md); padding: var(--ccm-space-md) 0;">
                    <div style="flex: 1;">
                        <strong><?php _e('Purge URLs', 'ccm-tools'); ?></strong>
                        <p class="ccm-text-muted"><?php _e('Purge specific URLs from Cloudflare\'s cache. Enter one URL per line (max 30).', 'ccm-tools'); ?></p>
                        <textarea id="cf-purge-urls" rows="4"
                                  placeholder="<?php echo esc_attr(home_url('/example-page/')); ?>"
                                  style="width: 100%; max-width: 600px; padding: var(--ccm-space-sm); border: 1px solid var(--ccm-border); border-radius: var(--ccm-radius); font-family: monospace; margin-top: var(--ccm-space-sm);"></textarea>
                    </div>
                    <button type="button" id="cf-purge-urls-btn" class="ccm-button ccm-button-secondary" style="align-self: flex-start; margin-top: var(--ccm-space-md);">
                        <?php _e('Purge URLs', 'ccm-tools'); ?>
                    </button>
                </div>
            </div>

            <!-- Development Mode -->
            <div class="ccm-card">
                <h2><?php _e('Development Mode', 'ccm-tools'); ?></h2>
                <div class="ccm-setting-row" style="display: flex; align-items: flex-start; justify-content: space-between; gap: var(--ccm-space-md); padding: var(--ccm-space-md) 0;">
                    <div style="flex: 1;">
                        <strong><?php _e('Development Mode', 'ccm-tools'); ?></strong>
                        <p class="ccm-text-muted"><?php _e('Temporarily bypass Cloudflare\'s cache, allowing you to see changes immediately. Automatically turns off after 3 hours.', 'ccm-tools'); ?></p>
                        <p id="cf-dev-mode-status" class="ccm-text-muted"></p>
                    </div>
                    <label class="ccm-toggle">
                        <input type="checkbox" id="cf-dev-mode-toggle">
                        <span class="ccm-toggle-slider"></span>
                    </label>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}
