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
        'auto_purge' => true,
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
 * Detect whether the site is behind Cloudflare.
 *
 * Checks $_SERVER for CF-specific headers (set on every proxied request),
 * then falls back to a self-request. Cached in a short transient.
 *
 * @return array  {detected: bool, ray_id?: string, server?: string}
 */
function ccm_tools_cf_detect(): array {
    $cached = get_transient('ccm_tools_cf_detected');
    if (is_array($cached)) {
        return $cached;
    }

    $result = array('detected' => false);

    // Primary: check $_SERVER for CF headers (most reliable)
    if (!empty($_SERVER['HTTP_CF_RAY'])) {
        $result['detected'] = true;
        $result['ray_id']   = sanitize_text_field($_SERVER['HTTP_CF_RAY']);
    }
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $result['detected'] = true;
    }

    // Fallback: self-request (may miss CF if request doesn't traverse the edge)
    if (!$result['detected']) {
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
 * Uses PHP cURL directly (not wp_remote_request) to prevent other WordPress
 * plugins from injecting headers via the http_request_args filter, which
 * causes Cloudflare error 6003 "Invalid request headers".
 *
 * @param string $endpoint  Path after /client/v4/ (e.g. "zones/{id}/purge_cache").
 * @param string $method    HTTP method.
 * @param array  $body      Request body (will be JSON-encoded for POST/PUT/PATCH).
 * @param string $token     API token (uses saved setting if empty).
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

    $url    = 'https://api.cloudflare.com/client/v4/' . ltrim($endpoint, '/');
    $method = strtoupper($method);

    // Headers matching the official Cloudflare WordPress plugin
    $headers = array(
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
        'User-Agent: wordpress/' . get_bloginfo('version') . '; ccm-tools/' . CCM_HELPER_VERSION,
    );

    $ch = curl_init();
    curl_setopt_array($ch, array(
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ));

    if (!empty($body) && in_array($method, array('POST', 'PUT', 'PATCH', 'DELETE'), true)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, wp_json_encode($body));
    }

    $raw      = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    $errno    = curl_errno($ch);
    curl_close($ch);

    if ($error) {
        return new WP_Error('cf_http_error', $error . ' (cURL/' . $errno . ')');
    }

    $data = json_decode($raw, true);

    if ($httpCode < 200 || $httpCode >= 300 || empty($data['success'])) {
        $msg = 'Cloudflare API error (HTTP ' . $httpCode . ')';
        if (!empty($data['errors'][0]['message'])) {
            $msg = $data['errors'][0]['message'] . ' (HTTP ' . $httpCode . ')';
        } elseif (empty($data)) {
            $msg = 'Unexpected response (HTTP ' . $httpCode . '): ' . mb_substr($raw, 0, 200);
        }
        return new WP_Error('cf_api_error', $msg, array('status' => $httpCode, 'response' => $data));
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
        'security_level', 'ssl', 'always_use_https', 'automatic_https_rewrites',
        'email_obfuscation', 'hotlink_protection', 'opportunistic_encryption',
        'early_hints', 'http2', 'http3', '0rtt', 'brotli',
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

/**
 * Update a Cloudflare zone setting.
 *
 * @param string $setting Setting key (e.g. 'rocket_loader', 'always_online', 'minify').
 * @param mixed  $value   Setting value ('on'/'off', object for minify, integer for browser_cache_ttl).
 * @return true|WP_Error
 */
function ccm_tools_cf_update_setting(string $setting, $value) {
    $settings = ccm_tools_cf_get_settings();
    if (empty($settings['zone_id'])) {
        return new WP_Error('no_zone', __('Cloudflare is not connected.', 'ccm-tools'));
    }

    // Whitelist of allowed settings
    $allowed = array(
        'rocket_loader', 'always_online', 'minify', 'browser_cache_ttl', 'polish', 'webp',
        'security_level', 'ssl', 'always_use_https', 'automatic_https_rewrites',
        'email_obfuscation', 'hotlink_protection', 'opportunistic_encryption',
        'early_hints', 'http2', 'http3', '0rtt', 'brotli',
    );
    if (!in_array($setting, $allowed, true)) {
        return new WP_Error('invalid_setting', __('Invalid Cloudflare setting.', 'ccm-tools'));
    }

    $data = ccm_tools_cf_api(
        'zones/' . $settings['zone_id'] . '/settings/' . $setting,
        'PATCH',
        array('value' => $value)
    );

    return is_wp_error($data) ? $data : true;
}

// ──────────────────────────────────────────────
// Zone Analytics
// ──────────────────────────────────────────────

/**
 * Fetch zone analytics for the last 24 hours.
 *
 * @param string $since  ISO 8601 start time (default: -24h).
 * @param string $until  ISO 8601 end time (default: now).
 * @return array|WP_Error
 */
function ccm_tools_cf_get_analytics(string $since = '', string $until = '') {
    $settings = ccm_tools_cf_get_settings();
    if (empty($settings['zone_id'])) {
        return new WP_Error('no_zone', __('Cloudflare is not connected.', 'ccm-tools'));
    }

    if (empty($since)) {
        $since = gmdate('Y-m-d\TH:i:s\Z', strtotime('-24 hours'));
    }
    if (empty($until)) {
        $until = gmdate('Y-m-d\TH:i:s\Z');
    }

    $data = ccm_tools_cf_api(
        'zones/' . $settings['zone_id'] . '/analytics/dashboard?since=' . urlencode($since) . '&until=' . urlencode($until)
    );

    if (is_wp_error($data)) {
        return $data;
    }

    $totals = $data['result']['totals'] ?? array();

    return array(
        'requests'  => array(
            'all'     => $totals['requests']['all'] ?? 0,
            'cached'  => $totals['requests']['cached'] ?? 0,
            'uncached' => $totals['requests']['uncached'] ?? 0,
            'ssl'     => $totals['requests']['ssl']['encrypted'] ?? 0,
        ),
        'bandwidth' => array(
            'all'     => $totals['bandwidth']['all'] ?? 0,
            'cached'  => $totals['bandwidth']['cached'] ?? 0,
            'uncached' => $totals['bandwidth']['uncached'] ?? 0,
        ),
        'threats'   => $totals['threats']['all'] ?? 0,
        'pageviews' => $totals['pageviews']['all'] ?? 0,
        'uniques'   => $totals['uniques']['all'] ?? 0,
    );
}

// ──────────────────────────────────────────────
// DNS Records
// ──────────────────────────────────────────────

/**
 * Fetch DNS records for the connected zone.
 *
 * @return array|WP_Error
 */
function ccm_tools_cf_get_dns_records() {
    $settings = ccm_tools_cf_get_settings();
    if (empty($settings['zone_id'])) {
        return new WP_Error('no_zone', __('Cloudflare is not connected.', 'ccm-tools'));
    }

    $data = ccm_tools_cf_api('zones/' . $settings['zone_id'] . '/dns_records?per_page=100&order=type');
    if (is_wp_error($data)) {
        return $data;
    }

    $records = array();
    foreach (($data['result'] ?? array()) as $r) {
        $records[] = array(
            'type'    => $r['type'] ?? '',
            'name'    => $r['name'] ?? '',
            'content' => $r['content'] ?? '',
            'ttl'     => $r['ttl'] ?? 0,
            'proxied' => $r['proxied'] ?? false,
        );
    }

    return $records;
}

// ──────────────────────────────────────────────
// Auto-Purge on Content Changes
// ──────────────────────────────────────────────

/**
 * Automatically purge Cloudflare cache when content is saved.
 *
 * Hooks into WordPress save/update actions to purge relevant URLs.
 */
function ccm_tools_cf_auto_purge_init(): void {
    $settings = ccm_tools_cf_get_settings();
    if (empty($settings['connected']) || empty($settings['zone_id']) || empty($settings['auto_purge'])) {
        return;
    }

    // Post/page save
    add_action('save_post', 'ccm_tools_cf_auto_purge_post', 20, 2);

    // Term (category/tag) changes
    add_action('edited_term', 'ccm_tools_cf_auto_purge_term', 20, 3);
    add_action('delete_term', 'ccm_tools_cf_auto_purge_term', 20, 3);

    // Menu save
    add_action('wp_update_nav_menu', 'ccm_tools_cf_auto_purge_all_action', 20);

    // Widget save
    add_action('update_option_sidebars_widgets', 'ccm_tools_cf_auto_purge_all_action', 20);

    // Theme switch
    add_action('switch_theme', 'ccm_tools_cf_auto_purge_all_action', 20);

    // Customizer save
    add_action('customize_save_after', 'ccm_tools_cf_auto_purge_all_action', 20);

    // Permalink structure change
    add_action('update_option_permalink_structure', 'ccm_tools_cf_auto_purge_all_action', 20);
}
add_action('init', 'ccm_tools_cf_auto_purge_init');

/**
 * Purge CF cache for a specific post and related pages.
 *
 * @param int     $post_id
 * @param WP_Post $post
 */
function ccm_tools_cf_auto_purge_post(int $post_id, $post): void {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
        return;
    }
    if (!in_array($post->post_status, array('publish', 'trash'), true)) {
        return;
    }

    $urls = array();
    $urls[] = get_permalink($post_id);
    $urls[] = home_url('/');

    // Purge archive pages
    if ($post->post_type === 'post') {
        $urls[] = get_post_type_archive_link('post');

        // Category archives
        $cats = get_the_category($post_id);
        if ($cats) {
            foreach ($cats as $cat) {
                $urls[] = get_category_link($cat->term_id);
            }
        }

        // Tag archives
        $tags = get_the_tags($post_id);
        if ($tags) {
            foreach ($tags as $tag) {
                $urls[] = get_tag_link($tag->term_id);
            }
        }

        // Author archive
        $urls[] = get_author_posts_url($post->post_author);
    }

    // Feed URLs
    $urls[] = get_bloginfo_rss('rss2_url');

    $urls = array_filter(array_unique($urls));
    if (!empty($urls)) {
        ccm_tools_cf_purge_urls($urls);
    }
}

/**
 * Purge CF cache for a term and related pages.
 *
 * @param int    $term_id
 * @param int    $tt_id
 * @param string $taxonomy
 */
function ccm_tools_cf_auto_purge_term(int $term_id, int $tt_id, string $taxonomy): void {
    $urls = array();
    $urls[] = get_term_link($term_id, $taxonomy);
    $urls[] = home_url('/');

    $urls = array_filter(array_unique($urls));
    if (!empty($urls)) {
        ccm_tools_cf_purge_urls($urls);
    }
}

/**
 * Purge entire CF cache (for menu/widget/theme changes).
 */
function ccm_tools_cf_auto_purge_all_action(): void {
    ccm_tools_cf_purge_all();
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
    $cf_detected = ccm_tools_cf_detect();
    $is_cf       = !empty($cf_detected['detected']);
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
                            <?php if ($is_cf): ?>
                                <span class="ccm-success"><?php _e('Yes', 'ccm-tools'); ?></span>
                                <?php if (!empty($cf_detected['ray_id'])): ?>
                                    <span class="ccm-text-muted" style="margin-left: var(--ccm-space-xs);">CF-Ray: <?php echo esc_html($cf_detected['ray_id']); ?></span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="ccm-warning"><?php _e('Not detected', 'ccm-tools'); ?></span>
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
            <details class="ccm-card ccm-cf-connection-details"<?php echo !$connected ? ' open' : ''; ?>>
                <summary class="ccm-cf-connection-summary"><h2 style="display:inline; cursor:pointer;"><?php _e('Connection Settings', 'ccm-tools'); ?></h2></summary>
                <p class="ccm-text-muted"><?php _e('Connect using an API Token with Zone:Read, Zone Settings:Edit, and Cache Purge permissions.', 'ccm-tools'); ?></p>
                <p class="ccm-text-muted" style="margin-top: var(--ccm-space-xs);"><strong><?php _e('Important:', 'ccm-tools'); ?></strong> <?php _e('You need an <strong>API Token</strong> (not a Global API Key). Global API Keys use a different authentication method and will not work here.', 'ccm-tools'); ?></p>

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

                <!-- API Token Setup Guide -->
                <details style="margin-top: var(--ccm-space-lg); border: 1px solid var(--ccm-border); border-radius: var(--ccm-radius); padding: 0;">
                    <summary style="padding: var(--ccm-space-md); cursor: pointer; font-weight: 600; user-select: none;">
                        <?php _e('How to create a Cloudflare API Token', 'ccm-tools'); ?>
                    </summary>
                    <div style="padding: 0 var(--ccm-space-md) var(--ccm-space-md); line-height: 1.7;">
                        <ol style="margin: 0; padding-left: var(--ccm-space-lg);">
                            <li><?php _e('Log in to the <a href="https://dash.cloudflare.com/profile/api-tokens" target="_blank" rel="noopener">Cloudflare Dashboard → My Profile → API Tokens</a>.', 'ccm-tools'); ?></li>
                            <li><?php _e('Click <strong>Create Token</strong>.', 'ccm-tools'); ?></li>
                            <li><?php _e('Under <strong>Custom token</strong>, click <strong>Get started</strong>.', 'ccm-tools'); ?></li>
                            <li>
                                <?php _e('Give it a name (e.g. <em>CCM Tools</em>) and add these <strong>Permissions</strong>:', 'ccm-tools'); ?>
                                <table class="ccm-table" style="margin: var(--ccm-space-sm) 0; font-size: 0.9em;">
                                    <thead>
                                        <tr><th><?php _e('Resource', 'ccm-tools'); ?></th><th><?php _e('Permission', 'ccm-tools'); ?></th><th><?php _e('Access', 'ccm-tools'); ?></th></tr>
                                    </thead>
                                    <tbody>
                                        <tr><td>Zone</td><td>Zone</td><td>Read</td></tr>
                                        <tr><td>Zone</td><td>Zone Settings</td><td>Edit</td></tr>
                                        <tr><td>Zone</td><td>Cache Purge</td><td>Purge</td></tr>
                                        <tr><td>Zone</td><td>Analytics</td><td>Read</td></tr>
                                        <tr><td>Zone</td><td>DNS</td><td>Read</td></tr>
                                    </tbody>
                                </table>
                                <p class="ccm-text-muted" style="margin: var(--ccm-space-xs) 0;"><?php _e('Click <strong>+ Add more</strong> to add each permission row.', 'ccm-tools'); ?></p>
                            </li>
                            <li><?php _e('Under <strong>Zone Resources</strong>, select <strong>Include → Specific zone</strong> and choose your domain, or use <strong>All zones</strong>.', 'ccm-tools'); ?></li>
                            <li><?php _e('Click <strong>Continue to summary</strong>, then <strong>Create Token</strong>.', 'ccm-tools'); ?></li>
                            <li><?php _e('Copy the token and paste it into the <strong>API Token</strong> field above. The token is only shown once — save it somewhere safe.', 'ccm-tools'); ?></li>
                        </ol>
                        <p style="margin: var(--ccm-space-md) 0 0; padding: var(--ccm-space-sm) var(--ccm-space-md); background: var(--ccm-bg-alt, #f0f6fc); border-radius: var(--ccm-radius); font-size: 0.9em;">
                            <strong><?php _e('Finding your Zone ID:', 'ccm-tools'); ?></strong>
                            <?php _e('Go to your domain in the Cloudflare dashboard. The Zone ID is shown in the right sidebar under <strong>API</strong>. You can leave it blank above and CCM Tools will auto-detect it.', 'ccm-tools'); ?>
                        </p>
                    </div>
                </details>
            </details>

            <?php if ($connected): ?>
            <!-- Zone Features -->
            <div class="ccm-card" id="cf-status-card">
                <h2><?php _e('Zone Features', 'ccm-tools'); ?></h2>
                <p class="ccm-text-muted"><?php _e('View and manage your Cloudflare zone settings. Toggle switches require your API Token to have <strong>Zone Settings: Edit</strong> permission.', 'ccm-tools'); ?></p>
                <div id="cf-zone-status">
                    <div style="text-align:center; padding: var(--ccm-space-lg) 0;"><div class="ccm-spinner"></div><p class="ccm-text-muted" style="margin-top: var(--ccm-space-sm);"><?php _e('Loading zone information...', 'ccm-tools'); ?></p></div>
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

            <!-- Auto-Purge Settings -->
            <div class="ccm-card">
                <h2><?php _e('Automatic Cache Purge', 'ccm-tools'); ?></h2>
                <div class="ccm-setting-row" style="display: flex; align-items: flex-start; justify-content: space-between; gap: var(--ccm-space-md); padding: var(--ccm-space-md) 0;">
                    <div style="flex: 1;">
                        <strong><?php _e('Auto-Purge on Content Changes', 'ccm-tools'); ?></strong>
                        <p class="ccm-text-muted"><?php _e('Automatically purge relevant Cloudflare cache when posts, pages, menus, widgets, or the theme are updated. Purges the changed URL plus related archives and the homepage.', 'ccm-tools'); ?></p>
                    </div>
                    <label class="ccm-toggle">
                        <input type="checkbox" id="cf-auto-purge-toggle" <?php checked(!empty($settings['auto_purge'])); ?>>
                        <span class="ccm-toggle-slider"></span>
                    </label>
                </div>
            </div>

            <!-- Security Settings -->
            <div class="ccm-card" id="cf-security-card">
                <h2><?php _e('Security', 'ccm-tools'); ?></h2>
                <p class="ccm-text-muted"><?php _e('Manage Cloudflare security features for your zone.', 'ccm-tools'); ?></p>
                <div id="cf-security-settings">
                    <div style="text-align:center; padding: var(--ccm-space-lg) 0;"><div class="ccm-spinner"></div><p class="ccm-text-muted" style="margin-top: var(--ccm-space-sm);"><?php _e('Loading security settings...', 'ccm-tools'); ?></p></div>
                </div>
            </div>

            <!-- SSL/TLS & Network -->
            <div class="ccm-card" id="cf-network-card">
                <h2><?php _e('SSL/TLS & Network', 'ccm-tools'); ?></h2>
                <p class="ccm-text-muted"><?php _e('Encryption and network protocol settings.', 'ccm-tools'); ?></p>
                <div id="cf-network-settings">
                    <div style="text-align:center; padding: var(--ccm-space-lg) 0;"><div class="ccm-spinner"></div><p class="ccm-text-muted" style="margin-top: var(--ccm-space-sm);"><?php _e('Loading network settings...', 'ccm-tools'); ?></p></div>
                </div>
            </div>

            <!-- Zone Analytics -->
            <div class="ccm-card" id="cf-analytics-card">
                <h2><?php _e('Zone Analytics (Last 24 Hours)', 'ccm-tools'); ?></h2>
                <p class="ccm-text-muted"><?php _e('Traffic, caching efficiency, and threat overview.', 'ccm-tools'); ?></p>
                <div id="cf-analytics">
                    <div style="text-align:center; padding: var(--ccm-space-lg) 0;"><div class="ccm-spinner"></div><p class="ccm-text-muted" style="margin-top: var(--ccm-space-sm);"><?php _e('Loading analytics...', 'ccm-tools'); ?></p></div>
                </div>
            </div>

            <!-- DNS Records -->
            <div class="ccm-card" id="cf-dns-card">
                <h2><?php _e('DNS Records', 'ccm-tools'); ?></h2>
                <p class="ccm-text-muted"><?php _e('Read-only view of your zone\'s DNS records. Manage records in the Cloudflare dashboard.', 'ccm-tools'); ?></p>
                <div id="cf-dns-records">
                    <div style="text-align:center; padding: var(--ccm-space-lg) 0;"><div class="ccm-spinner"></div><p class="ccm-text-muted" style="margin-top: var(--ccm-space-sm);"><?php _e('Loading DNS records...', 'ccm-tools'); ?></p></div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}
