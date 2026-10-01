<?php
/* 572fe790e3d9414b */

if (!defined('ABSPATH')) {
    exit;
}

/* dab498c3fe06b54a */

/** Marker prefix on stored api_token values that are AES-256-CBC encrypted. */
if (!defined('CCM_TOOLS_CF_TOKEN_ENC_PREFIX')) {
    define('CCM_TOOLS_CF_TOKEN_ENC_PREFIX', 'ccmcf1:');
}

/* f1dd39386ff7aa12 */
function ccm_tools_cf_token_keys(): array {
    $auth_key        = defined('AUTH_KEY') && AUTH_KEY ? AUTH_KEY : 'ccm-tools-fallback-auth-key';
    $secure_auth_key = defined('SECURE_AUTH_KEY') && SECURE_AUTH_KEY ? SECURE_AUTH_KEY : 'ccm-tools-fallback-secure-auth-key';

    $enc_key  = hash('sha256', $auth_key . '|' . $secure_auth_key . '|ccm-tools-cf-enc', true);
    $hmac_key = hash('sha256', $secure_auth_key . '|' . $auth_key . '|ccm-tools-cf-hmac', true);

    return array($enc_key, $hmac_key);
}

/* c263b5573b9969df */
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

/* daf9c2fa7d0a0a9b */
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

/* eb4406ff16c45169 */
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

/* 333fcd2e1c910bba */
function ccm_tools_cf_save_settings(array $settings): void {
    $stored = get_option('ccm_tools_cf_settings', array());

    /* 13c1c96a3c3634d6 */
    if (empty($settings['api_token']) && !empty($stored['api_token'])) {
        $settings['api_token'] = $stored['api_token'];
    }

    if (!empty($settings['api_token']) && strpos($settings['api_token'], CCM_TOOLS_CF_TOKEN_ENC_PREFIX) !== 0) {
        $encrypted = ccm_tools_cf_encrypt_token($settings['api_token']);

        if ($encrypted !== false) {
            $settings['api_token'] = $encrypted;
        } elseif (!empty($stored['api_token'])) {
            /* 4e818d36b63d6513 */
            $settings['api_token'] = $stored['api_token'];
        }
    }

    update_option('ccm_tools_cf_settings', $settings);
}

/* d13d79aabea9521d */

/* 170c460ab1102774 */
function ccm_tools_cf_detect(): array {
    // API connection check must run BEFORE the transient cache so a connected
    // API always trumps a stale "not detected" cache entry.
    $cf_settings   = ccm_tools_cf_get_settings();
    $api_connected = !empty($cf_settings['connected']) && !empty($cf_settings['zone_id']);

    $cached = get_transient('ccm_tools_cf_detected');
    /* f6dc9b88ad0b77a9 */
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

    /* 12df0265657f0162 */
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

/* 0aba87ba8334ce7b */

/* 59c531597b3e2a4f */
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

/* 032aff874cfb8e23 */
function ccm_tools_cf_verify_token(string $token, string $zone_id = '') {
    // If zone_id supplied, verify token by fetching the zone directly
    if (!empty($zone_id)) {
        $zone_id = sanitize_text_field($zone_id);
        $data = ccm_tools_cf_api('zones/' . $zone_id, 'GET', array(), $token);
        if (is_wp_error($data)) {
            return new WP_Error('token_invalid', __('Could not access zone: ', 'ccm-tools') . $data->get_error_message());
        }
        $zone_result = $data['result'] ?? $data;

        /* 7ad0257acf5ca131 */
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

/* 07eddfc61f769f19 */
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

/* 63c075a9e953f7ef */
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

/* d76bb27a352ace96 */
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

/* 94a4c4c10cdf8ecf */
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

/* 96c17f9c92de16f0 */
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

/* db87f282b4ad6400 */

/* 6dc3b2805dbde9ae */
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

/* b1177846b53fa0b1 */

/* 338a7c22aa4f0357 */
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

/* 1769f1a4c4384896 */

/* a08aafb03f539991 */
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

/* c8afd40170bd588c */

/* aedd6ff9719fb51c */
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

/* bdfe657a1e694803 */
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

/* ec95b7272dd159d8 */
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

/* 9fa4f80136411723 */
function ccm_tools_cf_auto_purge_all_action(): void {
    ccm_tools_cf_purge_all();
}


/* fa7c07fc542e1a54 */

/* 391deb64cca5fd57 */
function ccm_tools_render_cloudflare_page(): void {
    if (!ccm_tools_user_is_admin()) {
        wp_die(__('You do not have sufficient permissions to access this page.', 'ccm-tools'));
    }

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

/* 51232ad847445661 */
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

/* 72336abfa85cfc47 */
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

/* 1d2029b8245105fb */
function ccm_tools_cf_render_analytics(): void {
    ?>
    <div id="cf-analytics">
        <div style="text-align:center; padding: var(--ccm-space-lg) 0;"><div class="ccm-spinner"></div><p class="ccm-text-muted" style="margin-top: var(--ccm-space-sm);"><?php _e('Loading analytics...', 'ccm-tools'); ?></p></div>
    </div>
    <?php
}

/* 3dce2d5ee6b557c1 */
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

/* 7e7d6903951677cd */
function ccm_tools_cf_render_cache_section(): void {
    $settings = ccm_tools_cf_get_settings();
    ?>
    <section class="ccm-optgroup">
        <header class="ccm-optgroup__head">
            <div>
                <h2 class="ccm-optgroup__title"><?php _e('Cache', 'ccm-tools'); ?></h2>
                <p class="ccm-optgroup__note"><?php _e('Purge everything from the button at the top of this page. The options below cover specific URLs, purging automatically when content changes, and bypassing the cache entirely.', 'ccm-tools'); ?></p>
            </div>
        </header>

        <div class="ccm-optgroup__body">
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
    </section>
    <?php
}

/* ab68918d94c47ea8 */
function ccm_tools_cf_render_security_section(): void {
    ?>
    <section class="ccm-optgroup">
        <header class="ccm-optgroup__head">
            <div>
                <h2 class="ccm-optgroup__title"><?php _e('Security', 'ccm-tools'); ?></h2>
                <p class="ccm-optgroup__note"><?php _e('Challenge and bot controls for this zone. Changing a switch needs the API token to carry the Zone Settings: Edit permission.', 'ccm-tools'); ?></p>
            </div>
        </header>
        <div class="ccm-optgroup__body">
            <div id="cf-security-settings">
                <div style="text-align:center; padding: var(--ccm-space-lg) 0;"><div class="ccm-spinner"></div><p class="ccm-text-muted" style="margin-top: var(--ccm-space-sm);"><?php _e('Loading security settings...', 'ccm-tools'); ?></p></div>
            </div>
        </div>
    </section>
    <?php
}

/* 6be7b55b488678c7 */
function ccm_tools_cf_render_network_section(): void {
    ?>
    <section class="ccm-optgroup">
        <header class="ccm-optgroup__head">
            <div>
                <h2 class="ccm-optgroup__title"><?php _e('SSL/TLS and network', 'ccm-tools'); ?></h2>
                <p class="ccm-optgroup__note"><?php _e('Encryption and protocol settings for this zone. 0-RTT (below) lets returning visitors skip a round trip, but it carries a replay risk for non-idempotent requests such as form submissions or checkouts — leave it off unless the app is known to guard against replayed requests.', 'ccm-tools'); ?></p>
            </div>
        </header>
        <div class="ccm-optgroup__body">
            <div id="cf-network-settings">
                <div style="text-align:center; padding: var(--ccm-space-lg) 0;"><div class="ccm-spinner"></div><p class="ccm-text-muted" style="margin-top: var(--ccm-space-sm);"><?php _e('Loading network settings...', 'ccm-tools'); ?></p></div>
            </div>
        </div>
    </section>
    <?php
}

/* 93fcfa1cde834ce3 */
function ccm_tools_cf_render_dns_section(array $settings): void {
    ?>
    <section class="ccm-optgroup">
        <header class="ccm-optgroup__head">
            <div>
                <h2 class="ccm-optgroup__title"><?php _e('DNS records', 'ccm-tools'); ?></h2>
                <p class="ccm-optgroup__note"><?php _e('A read-only view of this zone\'s DNS records. Manage records in the Cloudflare dashboard.', 'ccm-tools'); ?></p>
            </div>
        </header>
        <div class="ccm-optgroup__body">
            <div id="cf-dns-records">
                <div style="text-align:center; padding: var(--ccm-space-lg) 0;"><div class="ccm-spinner"></div><p class="ccm-text-muted" style="margin-top: var(--ccm-space-sm);"><?php _e('Loading DNS records...', 'ccm-tools'); ?></p></div>
            </div>
        </div>
    </section>
    <?php
}

/* 254072dc76b0abcc */
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

/* df735822a4f76113 */
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
