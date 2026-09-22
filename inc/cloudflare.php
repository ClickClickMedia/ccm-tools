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

/** Marker prefix on stored api_token values that are AES-256-CBC encrypted. */
if (!defined('CCM_TOOLS_CF_TOKEN_ENC_PREFIX')) {
    define('CCM_TOOLS_CF_TOKEN_ENC_PREFIX', 'ccmcf1:');
}

/**
 * Derive the encryption + HMAC keys used to protect the stored Cloudflare
 * API token, from WordPress's own AUTH_KEY / SECURE_AUTH_KEY salts.
 *
 * These salts are already secret, per-site, and never stored in the
 * database (they live in wp-config.php), so deriving from them means the
 * token ciphertext is useless without also having filesystem access to
 * wp-config.php — a straight DB dump (or another plugin with option-read
 * access) is no longer enough to recover a live Cloudflare token.
 *
 * @return array{0:string,1:string}  [encryption key (32 raw bytes), hmac key (32 raw bytes)]
 */
function ccm_tools_cf_token_keys(): array {
    $auth_key        = defined('AUTH_KEY') && AUTH_KEY ? AUTH_KEY : 'ccm-tools-fallback-auth-key';
    $secure_auth_key = defined('SECURE_AUTH_KEY') && SECURE_AUTH_KEY ? SECURE_AUTH_KEY : 'ccm-tools-fallback-secure-auth-key';

    $enc_key  = hash('sha256', $auth_key . '|' . $secure_auth_key . '|ccm-tools-cf-enc', true);
    $hmac_key = hash('sha256', $secure_auth_key . '|' . $auth_key . '|ccm-tools-cf-hmac', true);

    return array($enc_key, $hmac_key);
}

/**
 * Encrypt a Cloudflare API token for storage.
 *
 * Format: PREFIX + base64( iv[16] . hmac[32] . ciphertext ), AES-256-CBC with
 * an HMAC-SHA256 (encrypt-then-MAC) over the IV + ciphertext to detect
 * tampering/corruption before we ever hand a garbled value to the CF API.
 *
 * @param string $plain
 * @return string|false Encrypted value, or false if encryption isn't available.
 */
function ccm_tools_cf_encrypt_token(string $plain) {
    if ($plain === '' || !function_exists('openssl_encrypt')) {
        return false;
    }

    list($enc_key, $hmac_key) = ccm_tools_cf_token_keys();

    $iv = openssl_random_pseudo_bytes(16);
    if ($iv === false) {
        return false;
    }

    $ciphertext = openssl_encrypt($plain, 'aes-256-cbc', $enc_key, OPENSSL_RAW_DATA, $iv);
    if ($ciphertext === false) {
        return false;
    }

    $hmac = hash_hmac('sha256', $iv . $ciphertext, $hmac_key, true);

    return CCM_TOOLS_CF_TOKEN_ENC_PREFIX . base64_encode($iv . $hmac . $ciphertext);
}

/**
 * Decrypt a Cloudflare API token previously encrypted with
 * ccm_tools_cf_encrypt_token().
 *
 * @param string $stored
 * @return string|false Decrypted plaintext, or false on failure (bad key, tampered value, etc).
 */
function ccm_tools_cf_decrypt_token(string $stored) {
    if (strpos($stored, CCM_TOOLS_CF_TOKEN_ENC_PREFIX) !== 0 || !function_exists('openssl_decrypt')) {
        return false;
    }

    $raw = base64_decode(substr($stored, strlen(CCM_TOOLS_CF_TOKEN_ENC_PREFIX)), true);
    if ($raw === false || strlen($raw) <= 48) {
        return false;
    }

    $iv         = substr($raw, 0, 16);
    $hmac       = substr($raw, 16, 32);
    $ciphertext = substr($raw, 48);

    list($enc_key, $hmac_key) = ccm_tools_cf_token_keys();

    $expected_hmac = hash_hmac('sha256', $iv . $ciphertext, $hmac_key, true);
    if (!hash_equals($expected_hmac, $hmac)) {
        // Tampered, corrupted, or encrypted under different salts (e.g. salts
        // rotated) — refuse to trust it rather than risk using a mangled token.
        return false;
    }

    $plain = openssl_decrypt($ciphertext, 'aes-256-cbc', $enc_key, OPENSSL_RAW_DATA, $iv);

    return $plain === false ? false : $plain;
}

/**
 * Get Cloudflare settings from the database.
 *
 * The API token is stored encrypted at rest (see ccm_tools_cf_save_settings())
 * and is transparently decrypted here for use. A pre-existing plaintext token
 * (from before this encryption was introduced) is decrypted-as-is and quietly
 * migrated to the encrypted format on this first read.
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
    $stored   = get_option('ccm_tools_cf_settings', array());
    $settings = wp_parse_args($stored, $defaults);

    if (!empty($settings['api_token'])) {
        $raw_token = $settings['api_token'];

        if (strpos($raw_token, CCM_TOOLS_CF_TOKEN_ENC_PREFIX) === 0) {
            $decrypted = ccm_tools_cf_decrypt_token($raw_token);
            $settings['api_token'] = $decrypted !== false ? $decrypted : '';
        } else {
            // Legacy plaintext value. Use it as-is for this request, then
            // transparently migrate the stored option to the encrypted form.
            $encrypted = ccm_tools_cf_encrypt_token($raw_token);
            if ($encrypted !== false) {
                $stored['api_token'] = $encrypted;
                update_option('ccm_tools_cf_settings', $stored);
            }
        }
    }

    return $settings;
}

/**
 * Save Cloudflare settings.
 *
 * The api_token is encrypted at rest before being written — see
 * ccm_tools_cf_encrypt_token(). Callers always pass the plaintext token (as
 * returned by ccm_tools_cf_get_settings()); it is never stored unencrypted.
 *
 * @param array $settings
 * @return void
 */
function ccm_tools_cf_save_settings(array $settings): void {
    if (!empty($settings['api_token']) && strpos($settings['api_token'], CCM_TOOLS_CF_TOKEN_ENC_PREFIX) !== 0) {
        $encrypted = ccm_tools_cf_encrypt_token($settings['api_token']);
        if ($encrypted !== false) {
            $settings['api_token'] = $encrypted;
        }
    }
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
    // API connection check must run BEFORE the transient cache so a connected
    // API always trumps a stale "not detected" cache entry.
    $cf_settings   = ccm_tools_cf_get_settings();
    $api_connected = !empty($cf_settings['connected']) && !empty($cf_settings['zone_id']);

    $cached = get_transient('ccm_tools_cf_detected');
    // array_key_exists, not is_array alone: a cache entry written by an older
    // version (or any malformed value) would otherwise be returned as-is and
    // every caller reading ['detected'] would warn on an undefined index.
    if (is_array($cached) && array_key_exists('detected', $cached)) {
        // Trust cache only when API is disconnected, or cache already says detected
        if (!$api_connected || !empty($cached['detected'])) {
            return $cached;
        }
        // API is connected but cache says not detected — stale, re-run detection
        delete_transient('ccm_tools_cf_detected');
    }

    $result = array('detected' => false);

    // Quick win: connected API proves the site is on Cloudflare
    if ($api_connected) {
        $result['detected'] = true;
        $result['source']   = 'api';
    }

    // Primary: check $_SERVER for CF headers (set on every proxied request).
    //
    // SECURITY NOTE: HTTP_CF_RAY and HTTP_CF_CONNECTING_IP are ordinary
    // request headers — visitor-controlled, trivially spoofable by anyone
    // sending a direct request to the origin (they are only meaningful when
    // the origin is locked down to accept traffic solely from Cloudflare's
    // IP ranges, which this plugin does not verify). They are used here
    // purely for an informational "is Cloudflare probably in front of this
    // site" label; NEVER use them to gate a security decision (e.g. trusting
    // a "real" visitor IP, or skipping auth/rate-limiting) without that
    // origin-side IP allowlist in place.
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
            'timeout' => 5,
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
 * Make a request to the Cloudflare API v4 — REST or GraphQL.
 *
 * Uses PHP cURL directly (not wp_remote_request) to prevent other WordPress
 * plugins from injecting headers via the http_request_args filter, which
 * causes Cloudflare error 6003 "Invalid request headers".
 *
 * Also serves the GraphQL Analytics endpoint (pass $endpoint = 'graphql',
 * $graphql = true) so callers don't need to duplicate the cURL request /
 * response / error-parsing plumbing: GraphQL responses have a different
 * shape ({data, errors} rather than REST's {success, result, errors}), so
 * $graphql switches which shape is used to decide success vs failure.
 *
 * @param string $endpoint  Path after /client/v4/ (e.g. "zones/{id}/purge_cache", or "graphql").
 * @param string $method    HTTP method.
 * @param array  $body      Request body (will be JSON-encoded for POST/PUT/PATCH/DELETE). For GraphQL this is the raw
 *                           {"query": "..."} payload.
 * @param string $token     API token (uses saved setting if empty).
 * @param bool   $graphql   True to parse the response as a GraphQL {data, errors} payload instead of REST's {success, result, errors}.
 * @return array|WP_Error   Decoded JSON body, or WP_Error.
 */
function ccm_tools_cf_api(string $endpoint, string $method = 'GET', array $body = array(), string $token = '', bool $graphql = false) {
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

    if ($graphql) {
        if ($httpCode < 200 || $httpCode >= 300) {
            $msg = 'Cloudflare GraphQL error (HTTP ' . $httpCode . ')';
            if (!empty($data['errors'][0]['message'])) {
                $msg = $data['errors'][0]['message'] . ' (HTTP ' . $httpCode . ')';
            }
            return new WP_Error('cf_api_error', $msg, array('status' => $httpCode, 'response' => $data));
        }
        if (!empty($data['errors'])) {
            return new WP_Error('cf_graphql_error', $data['errors'][0]['message'] ?? 'GraphQL query failed.', array('status' => $httpCode));
        }
        return $data;
    }

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
        $zone_result = $data['result'] ?? $data;

        // SECURITY: a token — especially an agency-wide all-zones token —
        // can see zones belonging to other sites/customers. A manually
        // entered (stale or mistyped) Zone ID must never be trusted just
        // because the token happens to have access to it: verify the zone
        // Cloudflare handed back actually belongs to THIS site's domain (or
        // is a parent domain of it, e.g. this site is shop.example.com and
        // the zone is example.com) before accepting it.
        $zone_name = isset($zone_result['name']) ? strtolower((string) $zone_result['name']) : '';

        $site_host = wp_parse_url(home_url(), PHP_URL_HOST);
        $site_host = strtolower(preg_replace('/^www\./i', '', (string) $site_host));

        $zone_matches = ($zone_name !== '' && $site_host !== '') && (
            $zone_name === $site_host
            || substr($site_host, -(strlen($zone_name) + 1)) === '.' . $zone_name
        );

        if (!$zone_matches) {
            return new WP_Error(
                'zone_mismatch',
                sprintf(
                    /* translators: 1: this site's domain, 2: the domain the supplied Zone ID actually resolved to */
                    __('The supplied Zone ID belongs to a different domain. This site is "%1$s" but the Zone ID resolved to "%2$s". Double-check the Zone ID, or leave it blank to auto-detect the correct zone.', 'ccm-tools'),
                    $site_host !== '' ? $site_host : __('(unknown)', 'ccm-tools'),
                    $zone_name !== '' ? $zone_name : __('(unknown)', 'ccm-tools')
                )
            );
        }

        return $zone_result;
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

    // Fetch ALL zone settings in a single API call instead of one-by-one
    $all_settings = ccm_tools_cf_api('zones/' . $zone_id . '/settings');
    $settings_map = array();
    if (!is_wp_error($all_settings) && !empty($all_settings['result'])) {
        foreach ($all_settings['result'] as $item) {
            if (!empty($item['id'])) {
                $settings_map[$item['id']] = $item['value'];
            }
        }
    }

    // Pick the settings we care about
    $feature_keys = array(
        'polish', 'rocket_loader', 'always_online',
        'browser_cache_ttl', 'development_mode', 'webp', 'mirage',
        'security_level', 'ssl', 'always_use_https', 'automatic_https_rewrites',
        'email_obfuscation', 'hotlink_protection', 'opportunistic_encryption',
        'early_hints', 'http2', 'http3', '0rtt', 'brotli',
        'bot_fight_mode', 'browser_check', 'privacy_pass',
        'ip_geolocation', 'server_side_exclude', 'opportunistic_onion',
        'pseudo_ipv4', 'challenge_ttl',
    );

    $features = array();
    foreach ($feature_keys as $key) {
        if (isset($settings_map[$key])) {
            $features[$key] = $settings_map[$key];
        }
    }

    // APO is included in the bulk settings response
    if (isset($settings_map['automatic_platform_optimization'])) {
        $features['apo'] = $settings_map['automatic_platform_optimization'];
    }

    return array(
        'zone'     => array(
            'id'      => $zone_result['id'] ?? '',
            'name'    => $zone_result['name'] ?? '',
            'status'  => $zone_result['status'] ?? '',
            'plan'    => $zone_result['plan']['name'] ?? 'Unknown',
            'plan_id' => $zone_result['plan']['legacy_id'] ?? 'free',
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
 * @param string $setting Setting key (e.g. 'rocket_loader', 'always_online').
 * @param mixed  $value   Setting value ('on'/'off', integer for browser_cache_ttl).
 * @return true|WP_Error
 */
function ccm_tools_cf_update_setting(string $setting, $value) {
    $settings = ccm_tools_cf_get_settings();
    if (empty($settings['zone_id'])) {
        return new WP_Error('no_zone', __('Cloudflare is not connected.', 'ccm-tools'));
    }

    // Whitelist of allowed settings
    $allowed = array(
        'rocket_loader', 'always_online', 'browser_cache_ttl', 'polish', 'webp', 'mirage',
        'security_level', 'ssl', 'always_use_https', 'automatic_https_rewrites',
        'email_obfuscation', 'hotlink_protection', 'opportunistic_encryption',
        'early_hints', 'http2', 'http3', '0rtt', 'brotli',
        'automatic_platform_optimization',
        'bot_fight_mode', 'browser_check', 'privacy_pass',
        'ip_geolocation', 'server_side_exclude', 'opportunistic_onion',
        'pseudo_ipv4', 'challenge_ttl',
    );
    if (!in_array($setting, $allowed, true)) {
        return new WP_Error('invalid_setting', __('Invalid Cloudflare setting.', 'ccm-tools'));
    }

    // Pro+ features cannot be changed on the Free plan
    $pro_only = array('polish', 'webp', 'mirage');
    if (in_array($setting, $pro_only, true)) {
        $zone = ccm_tools_cf_api('zones/' . $settings['zone_id']);
        $plan_id = $zone['result']['plan']['legacy_id'] ?? 'free';
        if ($plan_id === 'free') {
            return new WP_Error('plan_required', __('This feature requires a Cloudflare Pro or higher plan.', 'ccm-tools'));
        }
    }

    $data = ccm_tools_cf_api(
        'zones/' . $settings['zone_id'] . '/settings/' . $setting,
        'PATCH',
        array('value' => $value)
    );

    return is_wp_error($data) ? $data : true;
}

// ──────────────────────────────────────────────
// Apply Recommended WordPress Settings
// ──────────────────────────────────────────────

/**
 * Apply Cloudflare's recommended settings for WordPress.
 *
 * @return array Results with successes and failures.
 */
function ccm_tools_cf_apply_recommended(): array {
    $recommended = array(
        'security_level'           => 'medium',
        'ssl'                      => 'full',
        'always_use_https'         => 'on',
        'automatic_https_rewrites' => 'on',
        'browser_cache_ttl'        => 14400,
        'rocket_loader'            => 'off',
        'email_obfuscation'        => 'on',
        'brotli'                   => 'on',
        'http3'                    => 'on',
        '0rtt'                     => 'on',
        'early_hints'              => 'on',
        'always_online'            => 'on',
        'hotlink_protection'       => 'off',
        'browser_check'            => 'on',
        'ip_geolocation'           => 'on',
    );

    $successes = array();
    $failures  = array();

    foreach ($recommended as $setting => $value) {
        $result = ccm_tools_cf_update_setting($setting, $value);
        if (is_wp_error($result)) {
            $failures[] = $setting . ': ' . $result->get_error_message();
        } else {
            $successes[] = $setting;
        }
    }

    return array(
        'applied' => $successes,
        'failed'  => $failures,
    );
}

// ──────────────────────────────────────────────
// Zone Analytics
// ──────────────────────────────────────────────

/**
 * Fetch zone analytics for the last 24 hours via Cloudflare GraphQL Analytics API.
 *
 * @param string $since  ISO 8601 date (default: -24h).
 * @param string $until  ISO 8601 date (default: now).
 * @return array|WP_Error
 */
function ccm_tools_cf_get_analytics(string $since = '', string $until = '') {
    $settings = ccm_tools_cf_get_settings();
    if (empty($settings['zone_id'])) {
        return new WP_Error('no_zone', __('Cloudflare is not connected.', 'ccm-tools'));
    }

    $token = $settings['api_token'];
    if (empty($token)) {
        return new WP_Error('no_token', __('Cloudflare API token is not configured.', 'ccm-tools'));
    }

    if (empty($since)) {
        $since = gmdate('Y-m-d', strtotime('-1 day'));
    }
    if (empty($until)) {
        $until = gmdate('Y-m-d');
    }

    // Use date-only values for the 1d dataset
    $date_since = substr($since, 0, 10);
    $date_until = substr($until, 0, 10);

    // Validate zone_id format to prevent GraphQL injection
    if (!preg_match('/^[a-f0-9]{32}$/i', $settings['zone_id'])) {
        return array('success' => false, 'message' => __('Invalid zone ID format.', 'ccm-tools'));
    }

    $query = '
        query {
            viewer {
                zones(filter: {zoneTag: "' . $settings['zone_id'] . '"}) {
                    httpRequests1dGroups(
                        filter: {date_geq: "' . $date_since . '", date_leq: "' . $date_until . '"}
                        limit: 10
                    ) {
                        sum {
                            requests
                            cachedRequests
                            encryptedRequests
                            bytes
                            cachedBytes
                            threats
                            pageViews
                        }
                        uniq {
                            uniques
                        }
                    }
                }
            }
        }
    ';

    // Reuses the shared REST/GraphQL request helper instead of a second copy
    // of the cURL setup + response/error parsing (see ccm_tools_cf_api()).
    $data = ccm_tools_cf_api('graphql', 'POST', array('query' => $query), $token, true);
    if (is_wp_error($data)) {
        return $data;
    }

    $groups = $data['data']['viewer']['zones'][0]['httpRequests1dGroups'] ?? array();

    // Aggregate across all returned day groups
    $totals = array(
        'requests' => 0, 'cachedRequests' => 0, 'encryptedRequests' => 0,
        'bytes' => 0, 'cachedBytes' => 0, 'threats' => 0, 'pageViews' => 0,
        'uniques' => 0,
    );
    foreach ($groups as $g) {
        $s = $g['sum'] ?? array();
        $totals['requests']          += $s['requests'] ?? 0;
        $totals['cachedRequests']    += $s['cachedRequests'] ?? 0;
        $totals['encryptedRequests'] += $s['encryptedRequests'] ?? 0;
        $totals['bytes']             += $s['bytes'] ?? 0;
        $totals['cachedBytes']       += $s['cachedBytes'] ?? 0;
        $totals['threats']           += $s['threats'] ?? 0;
        $totals['pageViews']         += $s['pageViews'] ?? 0;
        $totals['uniques']           += ($g['uniq']['uniques'] ?? 0);
    }

    return array(
        'requests'  => array(
            'all'      => $totals['requests'],
            'cached'   => $totals['cachedRequests'],
            'uncached' => $totals['requests'] - $totals['cachedRequests'],
            'ssl'      => $totals['encryptedRequests'],
        ),
        'bandwidth' => array(
            'all'      => $totals['bytes'],
            'cached'   => $totals['cachedBytes'],
            'uncached' => $totals['bytes'] - $totals['cachedBytes'],
        ),
        'threats'   => $totals['threats'],
        'pageviews' => $totals['pageViews'],
        'uniques'   => $totals['uniques'],
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
                $cat_link = get_category_link($cat->term_id);
                if (!is_wp_error($cat_link)) {
                    $urls[] = $cat_link;
                }
            }
        }

        // Tag archives
        $tags = get_the_tags($post_id);
        if ($tags) {
            foreach ($tags as $tag) {
                $tag_link = get_tag_link($tag->term_id);
                if (!is_wp_error($tag_link)) {
                    $urls[] = $tag_link;
                }
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
    $term_link = get_term_link($term_id, $taxonomy);
    if (!is_wp_error($term_link)) {
        $urls[] = $term_link;
    }
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
 *
 * Hero + stat grid + grouped sections, per docs/UI-BRIEF.md, replacing the
 * former flat stack of ten equally-weighted `.ccm-card` tables. Every
 * container id that js/main.js queries (`#cf-connection-form`,
 * `#cf-zone-status`, `#cf-analytics`, `#cf-security-settings`,
 * `#cf-network-settings`, `#cf-dns-records`, and every button/input id it
 * binds to) is preserved exactly — see the per-section render helpers below
 * for the full accounting. main.js itself is untouched.
 */
function ccm_tools_render_cloudflare_page(): void {
    $settings    = ccm_tools_cf_get_settings();
    $connected   = !empty($settings['connected']) && !empty($settings['zone_id']);
    $cf_detected = ccm_tools_cf_detect();
    $is_cf       = !empty($cf_detected['detected']);
    ?>
    <div class="wrap ccm-tools">
        <?php ccm_tools_render_header_nav('ccm-tools-cloudflare'); ?>

        <div class="ccm-content">
            <?php
            ccm_tools_cf_render_hero($connected, $is_cf);

            if ($connected) {
                ccm_tools_cf_render_dev_mode_alert();
                ccm_tools_cf_render_analytics();
                ccm_tools_cf_render_zone_panel();
                ccm_tools_cf_render_cache_section();
                ccm_tools_cf_render_security_section();
                ccm_tools_cf_render_network_section();
                ccm_tools_cf_render_dns_section($settings);
            } else {
                ccm_tools_cf_render_empty_state();
            }

            ccm_tools_cf_render_connection_disclosure($settings, $connected);
            ?>
        </div>
    </div>
    <?php
}

/**
 * Hero: title, zone/detection context, and the primary action.
 *
 * The zone name and plan are only known once js/main.js has fetched
 * `ccm_tools_cf_get_status` (they are not cached anywhere in $settings),
 * so showing them here synchronously would mean either a second, blocking,
 * render-time call to the Cloudflare API (a real behaviour change) or new
 * JS wiring outside this file. Neither is in scope, so the meta line shows
 * what IS known at render time — the site's own domain (the zone is this
 * domain or a parent of it, exactly as ccm_tools_cf_verify_token() already
 * assumes) and whether Cloudflare is actually in front of requests. The
 * live zone name + plan continue to appear exactly where they always have,
 * in the panel js/main.js fills in directly below (see
 * ccm_tools_cf_render_zone_panel()).
 *
 * @param bool $connected
 * @param bool $is_cf
 */
function ccm_tools_cf_render_hero(bool $connected, bool $is_cf): void {
    $domain = wp_parse_url(home_url(), PHP_URL_HOST);
    ?>
    <div class="ccm-hero">
        <div class="ccm-hero__text">
            <h1><?php _e('Cloudflare', 'ccm-tools'); ?></h1>
            <div class="ccm-hero__meta">
                <span><?php echo esc_html($domain); ?></span>
                <span><?php echo $connected ? __('Connected', 'ccm-tools') : __('Not connected', 'ccm-tools'); ?></span>
                <span><?php echo $is_cf
                    ? __('Requests are arriving through Cloudflare', 'ccm-tools')
                    : __('No Cloudflare traffic detected', 'ccm-tools'); ?></span>
            </div>
        </div>
        <div class="ccm-hero__actions">
            <?php if ($connected): ?>
                <!-- Text kept as "Purge Everything" (not sentence case) because
                     js/main.js hardcodes that exact string when it resets this
                     button after a purge finishes; any other casing here would
                     flash back to this one the first time it's used. -->
                <button type="button" id="cf-purge-all" class="ccm-button ccm-button-primary">
                    <?php _e('Purge Everything', 'ccm-tools'); ?>
                </button>
            <?php else: ?>
                <a href="#cf-connection-disclose" class="ccm-button ccm-button-primary">
                    <?php _e('Connect', 'ccm-tools'); ?>
                </a>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

/**
 * Development Mode alert. Caller only invokes this when $connected is true.
 *
 * #cf-dev-mode-status is the exact element js/main.js already writes to
 * (via updateDevModeStatus(), called both on load and on toggle) — it sets
 * that element's innerHTML to a message when Development Mode is on, or to
 * an empty string when it's off. main.js never touches this element's
 * classes, so a small inline observer (scoped to this page, not touching
 * js/main.js) mirrors that content into visibility on the wrapping
 * `.ccm-alert`. This is the same "inline script owned by the render file"
 * pattern already used in inc/performance-optimizer.php and
 * inc/webp-converter.php.
 */
function ccm_tools_cf_render_dev_mode_alert(): void {
    ?>
    <div class="ccm-alert ccm-alert--warn ccm-hide" id="cf-dev-mode-alert">
        <span class="ccm-dot ccm-dot-warn" aria-hidden="true"></span>
        <p id="cf-dev-mode-status" style="margin: 0;"></p>
    </div>
    <script>
    (function () {
        var box = document.getElementById('cf-dev-mode-alert');
        var msg = document.getElementById('cf-dev-mode-status');
        if (!box || !msg) { return; }
        var sync = function () {
            box.classList.toggle('ccm-hide', msg.textContent.trim() === '');
        };
        if (window.MutationObserver) {
            new MutationObserver(sync).observe(msg, { childList: true, characterData: true, subtree: true });
        }
        sync();
    })();
    </script>
    <?php
}

/**
 * Zone Analytics. Caller only invokes this when $connected is true.
 *
 * js/main.js replaces #cf-analytics's entire innerHTML with its own
 * `.ccm-cf-analytics-grid` of `.ccm-cf-stat-card` tiles (cache ratio, total
 * requests, threats blocked, bandwidth, etc. — see loadCfAnalytics() in
 * js/main.js). That grid already IS this page's stat-grid equivalent, with
 * its own matching CSS, so it is kept as the container js/main.js expects
 * rather than wrapped in an unrelated `.ccm-stat-grid` that would have no
 * children to lay out (main.js's own wrapper div is the only child). It
 * sits directly under the hero, where the brief wants the at-a-glance
 * numbers.
 */
function ccm_tools_cf_render_analytics(): void {
    ?>
    <div id="cf-analytics">
        <div style="text-align:center; padding: var(--ccm-space-lg) 0;"><div class="ccm-spinner"></div><p class="ccm-text-muted" style="margin-top: var(--ccm-space-sm);"><?php _e('Loading analytics...', 'ccm-tools'); ?></p></div>
    </div>
    <?php
}

/**
 * Zone snapshot panel: the live Zone/Plan/Status/Features table js/main.js
 * writes into #cf-zone-status, plus the "Apply Recommended" action.
 * Caller only invokes this when $connected is true.
 *
 * #cf-zone-status is where js/main.js (loadCloudflareStatus()) writes a
 * `<table class="ccm-table">` — per the brief, that container is kept
 * as-is and wrapped in a `.ccm-panel` rather than fought. The
 * `data-premium="1"` attribute that used to sit on this container is
 * dead: nothing in js/main.js or any other JS file reads it (confirmed by
 * grep), so it has been dropped rather than carried forward as clutter —
 * the actual gate it once referred to (paid-tier feature editability) was
 * already removed from the PHP side.
 */
function ccm_tools_cf_render_zone_panel(): void {
    ?>
    <div class="ccm-panel">
        <div class="ccm-panel__head">
            <span><?php _e('Zone', 'ccm-tools'); ?></span>
            <button type="button" id="cf-apply-recommended" class="ccm-button ccm-button-secondary ccm-button-small">
                <?php _e('Apply Recommended', 'ccm-tools'); ?>
            </button>
        </div>
        <div class="ccm-panel__body ccm-panel__body--flush">
            <div id="cf-zone-status">
                <div style="text-align:center; padding: var(--ccm-space-lg) 0;"><div class="ccm-spinner"></div><p class="ccm-text-muted" style="margin-top: var(--ccm-space-sm);"><?php _e('Loading zone information...', 'ccm-tools'); ?></p></div>
            </div>
        </div>
    </div>
    <p class="ccm-text-muted" style="font-size: var(--ccm-text-xs); margin: var(--ccm-space-xs) 0 0;">
        <?php _e('Applies Cloudflare\'s recommended base configuration for WordPress: security, caching and performance settings, set to sensible defaults in one go.', 'ccm-tools'); ?>
    </p>
    <?php
}

/**
 * Cache section: purge URLs, auto-purge on save, development mode.
 * Caller only invokes this when $connected is true.
 *
 * "Purge everything" itself is not repeated here as a second control — it
 * is the hero's primary action (#cf-purge-all can only exist once in the
 * document), so this section points to it instead of duplicating it.
 */
function ccm_tools_cf_render_cache_section(): void {
    $settings = ccm_tools_cf_get_settings();
    ?>
    <div class="ccm-section">
        <div>
            <span class="ccm-section__eyebrow"><?php _e('Edge cache', 'ccm-tools'); ?></span>
            <h2><?php _e('Cache', 'ccm-tools'); ?></h2>
            <p><?php _e('Purge everything from the button at the top of this page. The options below cover specific URLs, purging automatically when content changes, and bypassing the cache entirely.', 'ccm-tools'); ?></p>
        </div>
    </div>

    <div class="ccm-opts">
        <div class="ccm-opt">
            <div class="ccm-opt__main">
                <div class="ccm-opt__text">
                    <span class="ccm-opt__label"><?php _e('Purge URLs', 'ccm-tools'); ?></span>
                    <p class="ccm-opt__desc"><?php _e('Clear specific pages from Cloudflare\'s cache without purging everything. One URL per line, up to 30 at a time.', 'ccm-tools'); ?></p>
                </div>
                <!-- Text kept as "Purge URLs" — js/main.js hardcodes this exact
                     string when it resets the button after a purge finishes. -->
                <button type="button" id="cf-purge-urls-btn" class="ccm-button ccm-button-secondary ccm-button-small">
                    <?php _e('Purge URLs', 'ccm-tools'); ?>
                </button>
            </div>
            <div class="ccm-opt__fields">
                <div class="ccm-optfield">
                    <label for="cf-purge-urls"><?php _e('URLs to purge', 'ccm-tools'); ?></label>
                    <textarea id="cf-purge-urls" class="ccm-input" rows="4"
                              placeholder="<?php echo esc_attr(home_url('/example-page/')); ?>"></textarea>
                </div>
            </div>
        </div>

        <div class="ccm-opt<?php echo !empty($settings['auto_purge']) ? ' is-on' : ''; ?>">
            <div class="ccm-opt__main">
                <div class="ccm-opt__text">
                    <span class="ccm-opt__label"><?php _e('Auto-purge on save', 'ccm-tools'); ?></span>
                    <p class="ccm-opt__desc"><?php _e('Automatically clears the affected page, its archives and the homepage from Cloudflare whenever a post, page, menu, widget or the theme is saved.', 'ccm-tools'); ?></p>
                </div>
                <label class="ccm-toggle">
                    <input type="checkbox" id="cf-auto-purge-toggle" <?php checked(!empty($settings['auto_purge'])); ?>>
                    <span class="ccm-toggle-slider"></span>
                </label>
            </div>
        </div>

        <div class="ccm-opt">
            <div class="ccm-opt__main">
                <div class="ccm-opt__text">
                    <span class="ccm-opt__label"><?php _e('Development mode', 'ccm-tools'); ?></span>
                    <p class="ccm-opt__desc"><?php _e('Bypasses the edge cache completely so changes show up immediately — every request hits your origin server for as long as it\'s on. Turns itself off after 3 hours if you forget.', 'ccm-tools'); ?></p>
                </div>
                <label class="ccm-toggle">
                    <input type="checkbox" id="cf-dev-mode-toggle">
                    <span class="ccm-toggle-slider"></span>
                </label>
            </div>
        </div>
    </div>
    <?php
}

/**
 * Security section: the live Security Settings panel js/main.js writes
 * (Under Attack mode, security level, email obfuscation, etc.). Caller
 * only invokes this when $connected is true.
 *
 * #cf-security-settings is where js/main.js (renderCfSecurityPanel()) writes
 * a `<div class="ccm-cf-under-attack">` block plus a `<table>` — kept as-is
 * and wrapped in a `.ccm-panel`. That panel's own copy already explains
 * Under Attack mode ("Visitors see a challenge page for ~5 seconds while
 * Cloudflare verifies the request") — that text is authored in js/main.js,
 * not here, and already satisfies the brief's "say what Under Attack does"
 * requirement, so it isn't duplicated in this section's intro.
 *
 * `data-confirm-settings="under_attack"` used to sit on this container as a
 * marker for a confirm() step main.js was meant to read; grep confirms
 * nothing reads it (js/main.js's own confirm() for Under Attack is wired
 * directly to its #cf-under-attack-toggle, not to this attribute), so it
 * has been dropped rather than carried forward as a dead marker.
 */
function ccm_tools_cf_render_security_section(): void {
    ?>
    <div class="ccm-section">
        <div>
            <span class="ccm-section__eyebrow"><?php _e('Zone', 'ccm-tools'); ?></span>
            <h2><?php _e('Security', 'ccm-tools'); ?></h2>
            <p><?php _e('Challenge and bot controls for this zone. Toggle switches require the API Token to have Zone Settings: Edit permission.', 'ccm-tools'); ?></p>
        </div>
    </div>
    <div class="ccm-panel">
        <div class="ccm-panel__body ccm-panel__body--flush">
            <div id="cf-security-settings">
                <div style="text-align:center; padding: var(--ccm-space-lg) 0;"><div class="ccm-spinner"></div><p class="ccm-text-muted" style="margin-top: var(--ccm-space-sm);"><?php _e('Loading security settings...', 'ccm-tools'); ?></p></div>
            </div>
        </div>
    </div>
    <?php
}

/**
 * SSL/TLS & network section: the live Network Settings panel js/main.js
 * writes. Caller only invokes this when $connected is true.
 *
 * #cf-network-settings is where js/main.js (renderCfNetworkPanel()) writes
 * a `<table>` — kept as-is, wrapped in a `.ccm-panel`. Its per-row 0-RTT
 * description ("Improve performance for repeat visitors with zero round-trip
 * time") is authored in js/main.js and does not mention the replay risk on
 * non-idempotent requests the brief wants called out; that copy can't be
 * changed here without editing js/main.js, so the caveat is added at the
 * section level instead, below.
 *
 * `data-confirm-settings="ssl"` was another dead marker (see the note on
 * ccm_tools_cf_render_security_section()) — same situation, dropped.
 */
function ccm_tools_cf_render_network_section(): void {
    ?>
    <div class="ccm-section">
        <div>
            <span class="ccm-section__eyebrow"><?php _e('Zone', 'ccm-tools'); ?></span>
            <h2><?php _e('SSL/TLS and network', 'ccm-tools'); ?></h2>
            <p><?php _e('Encryption and protocol settings for this zone. 0-RTT (below) lets returning visitors skip a round trip, but it carries a replay risk for non-idempotent requests such as form submissions or checkouts — leave it off unless the app is known to guard against replayed requests.', 'ccm-tools'); ?></p>
        </div>
    </div>
    <div class="ccm-panel">
        <div class="ccm-panel__body ccm-panel__body--flush">
            <div id="cf-network-settings">
                <div style="text-align:center; padding: var(--ccm-space-lg) 0;"><div class="ccm-spinner"></div><p class="ccm-text-muted" style="margin-top: var(--ccm-space-sm);"><?php _e('Loading network settings...', 'ccm-tools'); ?></p></div>
            </div>
        </div>
    </div>
    <?php
}

/**
 * DNS Records section (read-only, populated client-side). Caller only
 * invokes this when $connected is true. Last in the page, per the brief.
 *
 * #cf-dns-records is where js/main.js (loadCfDnsRecords()) writes a
 * `<div class="ccm-cf-dns-table-wrap"><table>…</table></div>` — kept as-is,
 * wrapped in a `.ccm-panel`.
 *
 * @param array $settings  Unused directly; kept for parity with the other
 *                          section helpers and in case a future caller needs it.
 */
function ccm_tools_cf_render_dns_section(array $settings): void {
    ?>
    <div class="ccm-section">
        <div>
            <span class="ccm-section__eyebrow"><?php _e('Read-only', 'ccm-tools'); ?></span>
            <h2><?php _e('DNS records', 'ccm-tools'); ?></h2>
            <p><?php _e('A read-only view of this zone\'s DNS records. Manage records in the Cloudflare dashboard.', 'ccm-tools'); ?></p>
        </div>
    </div>
    <div class="ccm-panel">
        <div class="ccm-panel__body ccm-panel__body--flush">
            <div id="cf-dns-records">
                <div style="text-align:center; padding: var(--ccm-space-lg) 0;"><div class="ccm-spinner"></div><p class="ccm-text-muted" style="margin-top: var(--ccm-space-sm);"><?php _e('Loading DNS records...', 'ccm-tools'); ?></p></div>
            </div>
        </div>
    </div>
    <?php
}

/**
 * Empty state shown instead of every connected-only section when there is
 * no API connection yet.
 */
function ccm_tools_cf_render_empty_state(): void {
    ?>
    <div class="ccm-empty">
        <span class="ccm-empty__icon" aria-hidden="true">
            <svg viewBox="0 0 24 24"><path d="M6.5 19a4.5 4.5 0 01-.4-8.98 5.5 5.5 0 0110.6-2A4.5 4.5 0 0117.5 19h-11z"/></svg>
        </span>
        <h3><?php _e('Connect Cloudflare to unlock this page', 'ccm-tools'); ?></h3>
        <p><?php _e('Cache purging, analytics, security and DNS all come from Cloudflare\'s API once this site is connected with a token scoped to Zone:Read, Zone Settings:Edit, Cache Purge, Analytics:Read and DNS:Read.', 'ccm-tools'); ?></p>
        <a href="#cf-connection-disclose" class="ccm-button ccm-button-primary"><?php _e('Connect', 'ccm-tools'); ?></a>
    </div>
    <?php
}

/**
 * Connection settings: API Token + Zone ID form, and the token setup
 * guide. Always rendered (open by default while disconnected) — this is
 * also where #cf-connection-form lives, and js/main.js only initialises
 * any of its Cloudflare handlers at all when that element exists
 * (`if ($('#cf-connection-form')) { initCloudflareHandlers(); }`), so it
 * must never be left out of the page regardless of connection state.
 *
 * @param array $settings
 * @param bool  $connected
 */
function ccm_tools_cf_render_connection_disclosure(array $settings, bool $connected): void {
    ?>
    <details class="ccm-disclose" id="cf-connection-disclose"<?php echo !$connected ? ' open' : ''; ?>>
        <summary>
            <?php _e('Connection settings', 'ccm-tools'); ?>
            <span class="ccm-disclose__note">
                <?php if ($connected): ?>
                    <span class="ccm-chip ccm-chip--good"><?php _e('Connected', 'ccm-tools'); ?></span>
                <?php else: ?>
                    <span class="ccm-chip ccm-chip--warn"><?php _e('Not connected', 'ccm-tools'); ?></span>
                <?php endif; ?>
            </span>
        </summary>
        <div class="ccm-disclose__body">
            <p class="ccm-text-muted" style="margin: 0 0 var(--ccm-space-sm);">
                <?php _e('Connect using an API Token with Zone:Read, Zone Settings:Edit, Cache Purge, Analytics:Read and DNS:Read permissions for this zone.', 'ccm-tools'); ?>
            </p>
            <p class="ccm-text-muted" style="margin: 0 0 var(--ccm-space-md);">
                <strong><?php _e('Important:', 'ccm-tools'); ?></strong>
                <?php _e('This needs an API Token, not a Global API Key. Global API Keys use a different authentication method and will not work here.', 'ccm-tools'); ?>
            </p>

            <div id="cf-connection-form" autocomplete="off">
                <div class="ccm-grid-2">
                    <div class="ccm-form-field">
                        <label for="cf-api-token"><?php _e('API Token', 'ccm-tools'); ?></label>
                        <div class="ccm-row">
                            <input type="password" id="cf-api-token"
                                   name="cf_api_token"
                                   class="ccm-input"
                                   autocomplete="new-password"
                                   value="<?php echo esc_attr(!empty($settings['api_token']) ? str_repeat("\xe2\x80\xa2", 12) : ''); ?>"
                                   data-has-token="<?php echo !empty($settings['api_token']) ? '1' : '0'; ?>"
                                   placeholder="<?php esc_attr_e('Enter your Cloudflare API Token', 'ccm-tools'); ?>"
                                   style="flex: 1;">
                            <button type="button" id="cf-toggle-token" class="ccm-button ccm-button-secondary ccm-button-small" title="<?php esc_attr_e('Show/hide token', 'ccm-tools'); ?>">
                                <span aria-hidden="true">👁</span>
                            </button>
                        </div>
                    </div>

                    <div class="ccm-form-field">
                        <label for="cf-zone-id">
                            <?php _e('Zone ID', 'ccm-tools'); ?>
                            <span class="ccm-text-muted" style="font-weight: normal;"> — <?php _e('leave blank to auto-detect from your domain', 'ccm-tools'); ?></span>
                        </label>
                        <input type="text" id="cf-zone-id"
                               name="cf_zone_id"
                               class="ccm-input ccm-mono"
                               autocomplete="off"
                               value="<?php echo esc_attr($settings['zone_id']); ?>"
                               placeholder="<?php esc_attr_e('e.g. a1b2c3d4e5f6...', 'ccm-tools'); ?>">
                    </div>
                </div>

                <div class="ccm-row" style="margin-top: var(--ccm-space-md);">
                    <!-- Reconnect/Connect text kept exactly as before: js/main.js
                         hardcodes "Connect" when it resets this button after an
                         attempt, regardless of connection state — a pre-existing
                         quirk this rebuild leaves untouched. -->
                    <button type="button" id="cf-connect-btn" class="ccm-button ccm-button-primary">
                        <?php echo $connected ? __('Reconnect', 'ccm-tools') : __('Connect', 'ccm-tools'); ?>
                    </button>
                    <?php if ($connected): ?>
                    <button type="button" id="cf-disconnect-btn" class="ccm-button ccm-button-secondary">
                        <?php _e('Disconnect', 'ccm-tools'); ?>
                    </button>
                    <?php endif; ?>
                    <span id="cf-connection-status"></span>
                </div>
            </div>

            <details class="ccm-disclose" style="margin-top: var(--ccm-space-md);">
                <summary><?php _e('How to create a Cloudflare API Token', 'ccm-tools'); ?></summary>
                <div class="ccm-disclose__body">
                    <ol style="margin: 0; padding-left: var(--ccm-space-lg); line-height: 1.8;">
                        <li><?php _e('Log in to the <a href="https://dash.cloudflare.com/profile/api-tokens" target="_blank" rel="noopener">Cloudflare Dashboard → My Profile → API Tokens</a>.', 'ccm-tools'); ?></li>
                        <li><?php _e('Click <strong>Create Token</strong>.', 'ccm-tools'); ?></li>
                        <li><?php _e('Under <strong>Custom token</strong>, click <strong>Get started</strong>.', 'ccm-tools'); ?></li>
                        <li>
                            <?php _e('Give it a name (e.g. <em>CCM Tools</em>) and add these Permissions:', 'ccm-tools'); ?>
                            <div class="ccm-table-wrap">
                                <table class="ccm-table" style="margin: var(--ccm-space-sm) 0;">
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
                            </div>
                            <p class="ccm-text-muted" style="font-size: var(--ccm-text-sm); margin: var(--ccm-space-xs) 0;"><?php _e('Click "+ Add more" to add each permission row.', 'ccm-tools'); ?></p>
                        </li>
                        <li><?php _e('Under <strong>Zone Resources</strong>, select <strong>Include → Specific zone</strong> and choose the domain, or use <strong>All zones</strong>.', 'ccm-tools'); ?></li>
                        <li><?php _e('Click <strong>Continue to summary</strong>, then <strong>Create Token</strong>.', 'ccm-tools'); ?></li>
                        <li><?php _e('Copy the token and paste it into the API Token field above. The token is only shown once — save it somewhere safe.', 'ccm-tools'); ?></li>
                    </ol>
                    <p class="ccm-text-muted" style="font-size: var(--ccm-text-sm); margin-top: var(--ccm-space-md);">
                        <strong><?php _e('Finding the Zone ID:', 'ccm-tools'); ?></strong>
                        <?php _e('Open the domain in the Cloudflare dashboard — the Zone ID is in the right sidebar under API. Leave it blank above and CCM Tools will auto-detect it.', 'ccm-tools'); ?>
                    </p>
                </div>
            </details>
        </div>
    </details>
    <?php
}
