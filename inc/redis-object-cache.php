<?php
/**
 * Redis Object Cache Manager
 * 
 * Provides a custom Redis object cache solution for WordPress.
 * Replaces the need for external plugins like Redis Object Cache by Till Krüss.
 * 
 * @package CCM_Tools
 * @since 7.8.0
 */

// Prevent direct file access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Check if Redis PHP extension is available
 * 
 * @return bool True if Redis extension is loaded
 */
function ccm_tools_redis_extension_available() {
    return extension_loaded('redis');
}

/**
 * Check if Redis server is available and can be connected to
 * 
 * @return array Connection status with details
 */
function ccm_tools_redis_check_connection() {
    $status = array(
        'available' => false,
        'connected' => false,
        'version' => '',
        'host' => '',
        'port' => 0,
        'error' => '',
        'memory_used' => '',
        'memory_peak' => '',
        'uptime' => 0,
        'connected_clients' => 0,
        'total_connections' => 0,
        'total_commands' => 0,
        'hits' => 0,
        'misses' => 0,
        'hit_ratio' => 0,
    );
    
    if (!ccm_tools_redis_extension_available()) {
        $status['error'] = __('Redis PHP extension is not installed.', 'ccm-tools');
        return $status;
    }
    
    $status['available'] = true;
    
    // Get connection settings
    $settings = ccm_tools_redis_get_settings();
    $host = $settings['host'];
    $port = $settings['port'];
    $password = $settings['password'];
    $database = $settings['database'];
    $timeout = $settings['timeout'];
    
    try {
        $redis = new Redis();
        
        // Connect based on scheme (tcp vs unix socket)
        if ($settings['scheme'] === 'unix' && !empty($settings['path'])) {
            $connected = @$redis->connect($settings['path']);
            $status['host'] = $settings['path'];
        } elseif ($settings['scheme'] === 'tls') {
            $connected = @$redis->connect('tls://' . $host, $port, $timeout);
            $status['host'] = $host;
            $status['port'] = $port;
        } else {
            $connected = @$redis->connect($host, $port, $timeout);
            $status['host'] = $host;
            $status['port'] = $port;
        }
        
        if (!$connected) {
            $status['error'] = __('Could not connect to Redis server.', 'ccm-tools');
            return $status;
        }
        
        // ACL auth (Redis 6.0+ username) or legacy auth
        if (!empty($password)) {
            $username = isset($settings['username']) ? $settings['username'] : '';
            if (defined('WP_REDIS_USERNAME')) {
                $username = WP_REDIS_USERNAME;
            }
            $auth = $username ? [$username, $password] : $password;
            if (!@$redis->auth($auth)) {
                $status['error'] = __('Redis authentication failed.', 'ccm-tools');
                return $status;
            }
        }
        
        // Select database
        if ($database > 0) {
            $redis->select($database);
        }
        
        // Get server info
        $info = $redis->info();
        
        $status['connected'] = true;
        $status['version'] = $info['redis_version'] ?? '';
        $status['memory_used'] = $info['used_memory_human'] ?? '';
        $status['memory_peak'] = $info['used_memory_peak_human'] ?? '';
        $status['uptime'] = intval($info['uptime_in_seconds'] ?? 0);
        $status['connected_clients'] = intval($info['connected_clients'] ?? 0);
        $status['total_connections'] = intval($info['total_connections_received'] ?? 0);
        $status['total_commands'] = intval($info['total_commands_processed'] ?? 0);
        
        // Get keyspace hits/misses
        $status['hits'] = intval($info['keyspace_hits'] ?? 0);
        $status['misses'] = intval($info['keyspace_misses'] ?? 0);
        
        if ($status['hits'] + $status['misses'] > 0) {
            $status['hit_ratio'] = round(($status['hits'] / ($status['hits'] + $status['misses'])) * 100, 2);
        }
        
        $redis->close();
        
    } catch (Exception $e) {
        $status['error'] = $e->getMessage();
    }
    
    return $status;
}

/**
 * Get Redis settings from options or wp-config constants
 * 
 * @return array Redis settings
 */
function ccm_tools_redis_get_settings() {
    $defaults = array(
        'host' => '127.0.0.1',
        'port' => 6379,
        'path' => '',
        'scheme' => 'tcp',
        'database' => 0,
        'username' => '',
        'password' => '',
        'timeout' => 1.0,
        'read_timeout' => 1.0,
        'retry_interval' => 0,
        'max_ttl' => 0,
        'key_salt' => '',
        'disable_metrics' => true,
        'disable_comment' => false,
        'enabled' => false,
        'selective_flush' => true,
        'compression' => 'none',
        // Prefer igbinary when the extension is present: smaller payloads,
        // faster encode/decode. Falls back to php where igbinary is absent.
        // Only written to wp-config when non-php AND loaded (see
        // ccm_tools_redis_build_config_array); the drop-in auto-flushes once
        // on serializer drift, so the switch is safe on existing installs.
        'serializer' => extension_loaded('igbinary') ? 'igbinary' : 'php',
        'async_flush' => false,
        'ignored_groups' => array('counts', 'plugins', 'themes'),
        'global_groups' => array(
            'blog-details', 'blog-id-cache', 'blog-lookup', 'global-posts',
            'networks', 'rss', 'sites', 'site-details', 'site-lookup',
            'site-options', 'site-transient', 'users', 'useremail', 'userlogins',
            'usermeta', 'user_meta', 'userslugs'
        ),
        'non_persistent_groups' => array('counts', 'plugins'),
        // WooCommerce specific settings
        'wc_cache_cart_fragments' => false,
        'wc_persistent_cart' => false,
        'wc_session_cache' => true,
        'wc_product_cache_ttl' => 3600,
        'wc_session_cache_ttl' => 172800,
    );
    
    // Check for wp-config.php constants first
    $config_settings = array();
    
    if (defined('WP_REDIS_HOST')) {
        $config_settings['host'] = WP_REDIS_HOST;
    }
    if (defined('WP_REDIS_PORT')) {
        $config_settings['port'] = intval(WP_REDIS_PORT);
    }
    if (defined('WP_REDIS_PATH')) {
        $config_settings['path'] = WP_REDIS_PATH;
        $config_settings['scheme'] = 'unix';
    }
    if (defined('WP_REDIS_SCHEME')) {
        $config_settings['scheme'] = WP_REDIS_SCHEME;
    }
    if (defined('WP_REDIS_DATABASE')) {
        $config_settings['database'] = intval(WP_REDIS_DATABASE);
    }
    if (defined('WP_REDIS_PASSWORD')) {
        $config_settings['password'] = WP_REDIS_PASSWORD;
    }
    if (defined('WP_REDIS_TIMEOUT')) {
        $config_settings['timeout'] = floatval(WP_REDIS_TIMEOUT);
    }
    if (defined('WP_REDIS_READ_TIMEOUT')) {
        $config_settings['read_timeout'] = floatval(WP_REDIS_READ_TIMEOUT);
    }
    if (defined('WP_REDIS_RETRY_INTERVAL')) {
        $config_settings['retry_interval'] = intval(WP_REDIS_RETRY_INTERVAL);
    }
    if (defined('WP_REDIS_MAXTTL')) {
        $config_settings['max_ttl'] = intval(WP_REDIS_MAXTTL);
    }
    if (defined('WP_CACHE_KEY_SALT')) {
        $config_settings['key_salt'] = WP_CACHE_KEY_SALT;
    }
    if (defined('WP_REDIS_DISABLE_METRICS')) {
        $config_settings['disable_metrics'] = WP_REDIS_DISABLE_METRICS;
    }
    if (defined('WP_REDIS_DISABLE_COMMENT')) {
        $config_settings['disable_comment'] = WP_REDIS_DISABLE_COMMENT;
    }
    if (defined('WP_REDIS_SELECTIVE_FLUSH')) {
        $config_settings['selective_flush'] = WP_REDIS_SELECTIVE_FLUSH;
    }
    if (defined('WP_REDIS_IGNORED_GROUPS')) {
        $config_settings['ignored_groups'] = WP_REDIS_IGNORED_GROUPS;
    }
    if (defined('WP_REDIS_GLOBAL_GROUPS')) {
        $config_settings['global_groups'] = WP_REDIS_GLOBAL_GROUPS;
    }
    if (defined('WP_REDIS_USERNAME')) {
        $config_settings['username'] = WP_REDIS_USERNAME;
    }
    if (defined('WP_REDIS_SERIALIZER')) {
        $config_settings['serializer'] = strtolower(WP_REDIS_SERIALIZER);
    }
    if (defined('WP_REDIS_COMPRESSION')) {
        $config_settings['compression'] = strtolower(WP_REDIS_COMPRESSION);
    }
    if (defined('WP_REDIS_ASYNC_FLUSH')) {
        $config_settings['async_flush'] = (bool) WP_REDIS_ASYNC_FLUSH;
    }
    
    // Get saved settings from database
    $saved_settings = get_option('ccm_tools_redis_settings', array());
    
    // Merge: defaults < config constants < saved settings
    return array_merge($defaults, $config_settings, $saved_settings);
}

/**
 * Generate a per-site Redis cache key salt.
 *
 * The stored default is empty, and the settings-page field only shows the
 * hostname as a greyed-out placeholder — placeholders are never submitted,
 * so WP_CACHE_KEY_SALT was typically never written. Combined with the
 * default host/port/database, two WordPress installs sharing one Redis
 * daemon then produce identical keys and silently read/write each other's
 * options, sessions and WooCommerce cart data. Host-only would still
 * collide for two installs sharing one hostname (e.g. subdirectory
 * multisite on shared hosting), so this always adds a random suffix too.
 *
 * @return string A non-empty salt suitable for WP_CACHE_KEY_SALT.
 */
function ccm_tools_redis_generate_key_salt() {
    $host = parse_url(site_url(), PHP_URL_HOST);
    $host = $host ? sanitize_text_field($host) : 'wp';
    return $host . '_' . wp_generate_password(8, false, false) . '_';
}

/**
 * Save Redis settings to database
 *
 * @param array $settings Settings to save
 * @return bool Success
 */
function ccm_tools_redis_save_settings($settings) {
    // Get existing settings to merge with
    $existing = get_option('ccm_tools_redis_settings', array());
    
    // Sanitize settings
    $sanitized = array();
    
    if (isset($settings['host'])) {
        $sanitized['host'] = sanitize_text_field($settings['host']);
    }
    if (isset($settings['port'])) {
        $sanitized['port'] = absint($settings['port']);
    }
    if (isset($settings['path'])) {
        $sanitized['path'] = sanitize_text_field($settings['path']);
    }
    if (isset($settings['scheme'])) {
        $sanitized['scheme'] = in_array($settings['scheme'], array('tcp', 'unix', 'tls')) ? $settings['scheme'] : 'tcp';
    }
    if (isset($settings['database'])) {
        $sanitized['database'] = absint($settings['database']);
    }
    if (isset($settings['username'])) {
        $sanitized['username'] = sanitize_text_field($settings['username']);
    }
    if (isset($settings['password'])) {
        // Use wp_unslash to handle escaped characters, don't sanitize password content
        $sanitized['password'] = wp_unslash($settings['password']);
    }
    if (isset($settings['timeout'])) {
        $sanitized['timeout'] = floatval($settings['timeout']);
    }
    if (isset($settings['read_timeout'])) {
        $sanitized['read_timeout'] = floatval($settings['read_timeout']);
    }
    if (isset($settings['max_ttl'])) {
        $sanitized['max_ttl'] = absint($settings['max_ttl']);
    }
    if (isset($settings['key_salt'])) {
        $sanitized['key_salt'] = sanitize_text_field($settings['key_salt']);
    }
    if (isset($settings['disable_metrics'])) {
        $sanitized['disable_metrics'] = (bool) $settings['disable_metrics'];
    }
    if (isset($settings['disable_comment'])) {
        $sanitized['disable_comment'] = (bool) $settings['disable_comment'];
    }
    if (isset($settings['enabled'])) {
        $sanitized['enabled'] = (bool) $settings['enabled'];
    }
    if (isset($settings['selective_flush'])) {
        $sanitized['selective_flush'] = (bool) $settings['selective_flush'];
    }
    if (isset($settings['compression'])) {
        $comp = $settings['compression'];
        // Validate the compression algorithm is actually available in phpredis
        if ($comp === 'lzf' && !defined('Redis::COMPRESSION_LZF')) { $comp = 'none'; }
        if ($comp === 'lz4' && !defined('Redis::COMPRESSION_LZ4')) { $comp = 'none'; }
        if ($comp === 'zstd' && !defined('Redis::COMPRESSION_ZSTD')) { $comp = 'none'; }
        $sanitized['compression'] = in_array($comp, array('none', 'lzf', 'zstd', 'lz4')) ? $comp : 'none';
    }
    if (isset($settings['serializer'])) {
        $ser = $settings['serializer'];
        // Validate the serializer extension is actually installed
        if ($ser === 'igbinary' && !extension_loaded('igbinary')) { $ser = 'php'; }
        if ($ser === 'msgpack' && !extension_loaded('msgpack')) { $ser = 'php'; }
        $sanitized['serializer'] = in_array($ser, array('php', 'igbinary', 'msgpack')) ? $ser : 'php';
    }
    if (isset($settings['async_flush'])) {
        $sanitized['async_flush'] = (bool) $settings['async_flush'];
    }
    
    // WooCommerce specific settings
    if (isset($settings['wc_cache_cart_fragments'])) {
        $sanitized['wc_cache_cart_fragments'] = (bool) $settings['wc_cache_cart_fragments'];
    }
    if (isset($settings['wc_persistent_cart'])) {
        $sanitized['wc_persistent_cart'] = (bool) $settings['wc_persistent_cart'];
    }
    if (isset($settings['wc_session_cache'])) {
        $sanitized['wc_session_cache'] = (bool) $settings['wc_session_cache'];
    }
    if (isset($settings['wc_product_cache_ttl'])) {
        $sanitized['wc_product_cache_ttl'] = absint($settings['wc_product_cache_ttl']);
    }
    if (isset($settings['wc_session_cache_ttl'])) {
        $sanitized['wc_session_cache_ttl'] = absint($settings['wc_session_cache_ttl']);
    }
    
    // Merge with existing settings (new values override existing)
    $merged = array_merge($existing, $sanitized);

    // A shared Redis daemon with an empty (or never-explicitly-set) salt
    // means two WordPress installs can silently read/write each other's
    // options, sessions and WooCommerce cart data. Preserve any salt an
    // admin already set; only generate + persist a new one when there still
    // isn't one, so the field value round-trips on the next page load.
    if (empty($merged['key_salt'])) {
        $merged['key_salt'] = ccm_tools_redis_generate_key_salt();
    }

    return update_option('ccm_tools_redis_settings', $merged);
}

/**
 * Check if the CCM Tools object-cache.php drop-in is installed
 * 
 * @return array Status information
 */
function ccm_tools_redis_dropin_status() {
    $dropin_path = WP_CONTENT_DIR . '/object-cache.php';
    $our_dropin = CCM_HELPER_ROOT_PATH . 'assets/object-cache.php';
    
    $status = array(
        'exists' => false,
        'is_ccm' => false,
        'is_other' => false,
        'other_plugin' => '',
        'version' => '',
        'writable' => is_writable(WP_CONTENT_DIR),
    );
    
    if (file_exists($dropin_path)) {
        $status['exists'] = true;
        
        // Read the drop-in content to identify it
        $content = file_get_contents($dropin_path);
        
        // Check if it's our drop-in
        if (strpos($content, 'CCM Tools Redis Object Cache') !== false) {
            $status['is_ccm'] = true;
            
            // Extract version — the drop-in header uses "@version X.Y.Z" (no
            // colon after "Version"), matching ccm_tools_redis_dropin_version_check().
            if (preg_match('/@version\s+([0-9.]+)/i', $content, $matches)) {
                $status['version'] = $matches[1];
            }
        } 
        // Check for other known object cache plugins
        elseif (strpos($content, 'Redis Object Cache') !== false || strpos($content, 'Till Krüss') !== false) {
            $status['is_other'] = true;
            $status['other_plugin'] = 'Redis Object Cache by Till Krüss';
        }
        elseif (strpos($content, 'W3 Total Cache') !== false) {
            $status['is_other'] = true;
            $status['other_plugin'] = 'W3 Total Cache';
        }
        elseif (strpos($content, 'LiteSpeed') !== false) {
            $status['is_other'] = true;
            $status['other_plugin'] = 'LiteSpeed Cache';
        }
        elseif (strpos($content, 'WP Super Cache') !== false) {
            $status['is_other'] = true;
            $status['other_plugin'] = 'WP Super Cache';
        }
        else {
            $status['is_other'] = true;
            $status['other_plugin'] = __('Unknown plugin', 'ccm-tools');
        }
    }
    
    return $status;
}

/**
 * Install the CCM Tools object-cache.php drop-in
 * 
 * @param bool $force Force overwrite existing drop-in
 * @return array Result with success status and message
 */
function ccm_tools_redis_install_dropin($force = false) {
    $result = array(
        'success' => false,
        'message' => '',
    );
    
    // Check prerequisites
    if (!ccm_tools_redis_extension_available()) {
        $result['message'] = __('Redis PHP extension is not installed.', 'ccm-tools');
        return $result;
    }
    
    $connection = ccm_tools_redis_check_connection();
    if (!$connection['connected']) {
        $result['message'] = __('Cannot connect to Redis server: ', 'ccm-tools') . $connection['error'];
        return $result;
    }

    // A shared Redis daemon needs a non-empty per-site key salt, or two
    // installs on the same daemon can silently read/write each other's
    // cache. Ensure one exists and is persisted before touching any files.
    // ccm_tools_redis_save_settings() auto-generates one when empty, so this
    // should always succeed — the second check is a hard refusal in case it
    // somehow doesn't.
    $salt_settings = ccm_tools_redis_get_settings();
    if (empty($salt_settings['key_salt'])) {
        ccm_tools_redis_save_settings(array_merge($salt_settings, array(
            'key_salt' => ccm_tools_redis_generate_key_salt(),
        )));
        $salt_settings = ccm_tools_redis_get_settings();
    }
    if (empty($salt_settings['key_salt'])) {
        $result['message'] = __('Could not establish a Redis cache key salt; refusing to enable Redis object caching.', 'ccm-tools');
        return $result;
    }

    $dropin_status = ccm_tools_redis_dropin_status();
    
    // Check if another plugin's drop-in exists
    if ($dropin_status['exists'] && $dropin_status['is_other'] && !$force) {
        $result['message'] = sprintf(
            __('An object-cache.php from %s already exists. Use force option to replace it.', 'ccm-tools'),
            $dropin_status['other_plugin']
        );
        return $result;
    }
    
    // Check if wp-content is writable
    if (!$dropin_status['writable']) {
        $result['message'] = __('wp-content directory is not writable.', 'ccm-tools');
        return $result;
    }
    
    $dropin_path = WP_CONTENT_DIR . '/object-cache.php';
    $source_path = CCM_HELPER_ROOT_PATH . 'assets/object-cache.php';
    
    // Check if our source file exists
    if (!file_exists($source_path)) {
        $result['message'] = __('Source object-cache.php file not found.', 'ccm-tools');
        return $result;
    }
    
    // Backup existing drop-in if it exists
    if ($dropin_status['exists']) {
        $backup_path = WP_CONTENT_DIR . '/object-cache-backup-' . date('Y-m-d-His') . '.php';
        if (!@copy($dropin_path, $backup_path)) {
            $result['message'] = __('Could not create backup of existing object-cache.php.', 'ccm-tools');
            return $result;
        }
    }
    
    // Copy the drop-in file
    if (!@copy($source_path, $dropin_path)) {
        $result['message'] = __('Could not install object-cache.php. Please check file permissions.', 'ccm-tools');
        return $result;
    }
    
    // Verify the installation
    if (!file_exists($dropin_path)) {
        $result['message'] = __('Installation verification failed.', 'ccm-tools');
        return $result;
    }
    
    // Keep only the most recent drop-in backups.
    ccm_tools_redis_prune_backups(WP_CONTENT_DIR, 'object-cache-backup-*.php', 5);

    // Update settings to mark as enabled
    $settings = ccm_tools_redis_get_settings();
    $settings['enabled'] = true;
    ccm_tools_redis_save_settings($settings);

    // Write the matching constants to wp-config.php so enabling is one-click
    // (previously a separate "Add to wp-config" step that was easily missed).
    $config_written = false;
    if (function_exists('ccm_tools_redis_build_config_array')) {
        $cfg = ccm_tools_redis_add_config(ccm_tools_redis_build_config_array($settings));
        $config_written = !empty($cfg['success']);
    }

    // Flush the cache
    if (function_exists('wp_cache_flush')) {
        wp_cache_flush();
    }

    // Cleanup database-stored transients (Redis handles them now)
    ccm_tools_redis_cleanup_transients();

    $result['success'] = true;
    $result['message'] = $config_written
        ? __('Redis Object Cache installed, configured in wp-config.php, and enabled successfully!', 'ccm-tools')
        : __('Redis Object Cache installed and enabled. Review wp-config.php — automatic configuration could not be written.', 'ccm-tools');

    return $result;
}

/**
 * Uninstall the CCM Tools object-cache.php drop-in
 * 
 * @return array Result with success status and message
 */
function ccm_tools_redis_uninstall_dropin() {
    $result = array(
        'success' => false,
        'message' => '',
    );
    
    $dropin_path = WP_CONTENT_DIR . '/object-cache.php';
    $dropin_status = ccm_tools_redis_dropin_status();
    
    if (!$dropin_status['exists']) {
        $result['success'] = true;
        $result['message'] = __('Object cache drop-in is not installed.', 'ccm-tools');
        return $result;
    }
    
    // Only remove our drop-in
    if (!$dropin_status['is_ccm']) {
        $result['message'] = sprintf(
            __('The installed object-cache.php is from %s, not CCM Tools. Please remove it manually.', 'ccm-tools'),
            $dropin_status['other_plugin']
        );
        return $result;
    }
    
    // Flush cache before removing
    if (function_exists('wp_cache_flush')) {
        wp_cache_flush();
    }
    
    // Delete the drop-in
    if (!@unlink($dropin_path)) {
        $result['message'] = __('Could not remove object-cache.php. Please check file permissions.', 'ccm-tools');
        return $result;
    }
    
    // Update settings
    $settings = ccm_tools_redis_get_settings();
    $settings['enabled'] = false;
    ccm_tools_redis_save_settings($settings);

    // Strip the managed Redis block from wp-config.php so disabling is a clean,
    // one-step teardown (mirrors the one-step enable). Best-effort.
    $config_removed = false;
    if (function_exists('ccm_tools_redis_remove_config')) {
        $cfg = ccm_tools_redis_remove_config();
        $config_removed = !empty($cfg['success']);
    }

    $result['success'] = true;
    $result['message'] = $config_removed
        ? __('Redis Object Cache disabled, drop-in removed, and wp-config.php cleaned up.', 'ccm-tools')
        : __('Redis Object Cache disabled and drop-in removed.', 'ccm-tools');

    return $result;
}

/**
 * Canonical list of wp-config constants this plugin manages.
 *
 * Used both when writing the managed block and when stripping it, so the two
 * paths can never drift apart.
 *
 * @return string[]
 */
function ccm_tools_redis_managed_constants() {
    return array(
        'WP_REDIS_HOST', 'WP_REDIS_PORT', 'WP_REDIS_PATH', 'WP_REDIS_SCHEME',
        'WP_REDIS_DATABASE', 'WP_REDIS_PASSWORD', 'WP_REDIS_USERNAME',
        'WP_REDIS_TIMEOUT', 'WP_REDIS_READ_TIMEOUT', 'WP_REDIS_RETRY_INTERVAL',
        'WP_REDIS_MAXTTL', 'WP_REDIS_DISABLE_METRICS', 'WP_REDIS_DISABLE_COMMENT',
        'WP_REDIS_SELECTIVE_FLUSH', 'WP_REDIS_SERIALIZER', 'WP_REDIS_COMPRESSION',
        'WP_REDIS_ASYNC_FLUSH', 'WP_REDIS_CLIENT', 'WP_REDIS_IGNORED_GROUPS',
        'WP_REDIS_GLOBAL_GROUPS', 'WP_CACHE_KEY_SALT',
    );
}

/**
 * Build the wp-config constant array from saved Redis settings.
 *
 * Shared by the "Add to wp-config" handler and the one-step Save flow so they
 * always emit an identical block. Only writes extensions/algorithms that are
 * actually available on this server.
 *
 * @param array $settings Result of ccm_tools_redis_get_settings()
 * @return array Constant => value map
 */
function ccm_tools_redis_build_config_array($settings) {
    $config = array(
        'WP_REDIS_HOST'            => sanitize_text_field($settings['host']),
        'WP_REDIS_PORT'            => absint($settings['port']),
        'WP_REDIS_MAXTTL'          => absint($settings['max_ttl']) ?: 604800,
        'WP_REDIS_DISABLE_METRICS' => true,
    );

    if (!empty($settings['key_salt'])) {
        $config['WP_CACHE_KEY_SALT'] = sanitize_text_field($settings['key_salt']);
    }
    if (!empty($settings['password'])) {
        $config['WP_REDIS_PASSWORD'] = $settings['password'];
    }
    if (!empty($settings['username'])) {
        $config['WP_REDIS_USERNAME'] = sanitize_text_field($settings['username']);
    }
    if (!empty($settings['database']) && $settings['database'] > 0) {
        $config['WP_REDIS_DATABASE'] = absint($settings['database']);
    }

    $config['WP_REDIS_SCHEME'] = sanitize_text_field($settings['scheme']);

    if (!empty($settings['path'])) {
        $config['WP_REDIS_PATH'] = sanitize_text_field($settings['path']);
    }
    if (!empty($settings['selective_flush'])) {
        $config['WP_REDIS_SELECTIVE_FLUSH'] = true;
    }

    // Serializer — only if non-default and the extension is loaded.
    if (!empty($settings['serializer']) && $settings['serializer'] !== 'php') {
        $ser = sanitize_text_field($settings['serializer']);
        $ser_available = ($ser === 'igbinary' && extension_loaded('igbinary'))
                      || ($ser === 'msgpack' && extension_loaded('msgpack'));
        if ($ser_available) {
            $config['WP_REDIS_SERIALIZER'] = $ser;
        }
    }

    // Compression — only if non-default and phpredis supports it.
    if (!empty($settings['compression']) && $settings['compression'] !== 'none') {
        $comp = sanitize_text_field($settings['compression']);
        $comp_available = ($comp === 'lzf' && defined('Redis::COMPRESSION_LZF'))
                       || ($comp === 'lz4' && defined('Redis::COMPRESSION_LZ4'))
                       || ($comp === 'zstd' && defined('Redis::COMPRESSION_ZSTD'));
        if ($comp_available) {
            $config['WP_REDIS_COMPRESSION'] = $comp;
        }
    }

    if (!empty($settings['async_flush'])) {
        $config['WP_REDIS_ASYNC_FLUSH'] = true;
    }

    $config['WP_REDIS_TIMEOUT']      = (float) ($settings['timeout'] ?? 1);
    $config['WP_REDIS_READ_TIMEOUT'] = (float) ($settings['read_timeout'] ?? 1);
    $config['WP_REDIS_DISABLE_COMMENT'] = empty($settings['disable_comment']) ? false : true;

    return $config;
}

/**
 * Keep at most $keep backup files matching a glob in $dir; delete the rest.
 *
 * Prevents wp-config-backup-*.php / object-cache-backup-*.php from piling up
 * now that Save rewrites wp-config and the drop-in on every change.
 *
 * @param string $dir         Directory to scan
 * @param string $glob_suffix Glob pattern (e.g. 'wp-config-backup-*.php')
 * @param int    $keep        Number of most-recent backups to retain
 */
function ccm_tools_redis_prune_backups($dir, $glob_suffix, $keep = 5) {
    $files = glob(rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $glob_suffix);
    if (!is_array($files) || count($files) <= $keep) {
        return;
    }
    // Newest first by mtime, then drop everything past $keep.
    usort($files, function ($a, $b) {
        return filemtime($b) <=> filemtime($a);
    });
    foreach (array_slice($files, $keep) as $old) {
        @unlink($old);
    }
}

/**
 * Atomically replace the contents of $path with $content.
 *
 * A direct @file_put_contents($path, $content) is NOT safe for a file like
 * wp-config.php that PHP parses on every request: a worker killed mid-write,
 * a full disk, or an execution timeout can leave a truncated file, which is
 * a hard parse error on the very next request with no way into wp-admin to
 * recover. Note file_put_contents() returns the BYTE COUNT on a partial
 * write, not false, so a naive `=== false` check does not catch a full disk
 * — the byte count must be compared against strlen($content).
 *
 * We instead write to "<path>.tmp-<random>" in the same directory, verify
 * every byte landed, then rename() into place. rename() on the same
 * filesystem is atomic, so a concurrent reader always sees either the old
 * file or the new one whole, never a half-written one. Mirrors the pattern
 * ccm_tools_redis_refresh_dropin() already used for the drop-in file; this
 * is the shared helper so wp-config writes get the same guarantee.
 *
 * @param string $path    Absolute path of the file to replace.
 * @param string $content New file content.
 * @return bool True on a verified, complete, atomic write.
 */
function ccm_tools_redis_atomic_write_file($path, $content) {
    $tmp = $path . '.tmp-' . wp_generate_password(6, false, false);

    $written = @file_put_contents($tmp, $content);
    if ($written === false || $written !== strlen($content)) {
        @unlink($tmp);
        return false;
    }

    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }

    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate($path, true);
    }

    return true;
}

/**
 * Directory used to store wp-config.php backups.
 *
 * wp-config.php backups embed the site's Redis credentials (and everything
 * else in wp-config.php) in plaintext, so they must never live under the web
 * root where a misconfigured server could serve them as a static download.
 * This stores them under wp-content/uploads instead. The primary protection
 * is now encryption of the backup body itself (see
 * ccm_tools_redis_encrypt_backup()) — the .htaccess deny-all and empty
 * index.php below are defence in depth only, because nginx never reads
 * .htaccess, so on an nginx-fronted site they do nothing at all and a full
 * plaintext wp-config.php would otherwise sit at a predictable path
 * protected only by an unguessable filename.
 *
 * @return string Absolute path to the private backup directory, with a trailing slash.
 */
function ccm_tools_redis_private_backup_dir() {
    $dir = trailingslashit(wp_upload_dir()['basedir']) . 'ccm-private/';

    if (!file_exists($dir)) {
        wp_mkdir_p($dir);
    }

    $htaccess = $dir . '.htaccess';
    if (!file_exists($htaccess)) {
        $htaccess_content = "<IfModule mod_authz_core.c>\n"
            . "Require all denied\n"
            . "</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n"
            . "Order allow,deny\n"
            . "Deny from all\n"
            . "</IfModule>\n";
        @file_put_contents($htaccess, $htaccess_content);
    }

    $index = $dir . 'index.php';
    if (!file_exists($index)) {
        @file_put_contents($index, "<?php\n// Silence is golden.\n");
    }

    return $dir;
}

/**
 * Derive the encryption and HMAC keys used for wp-config.php backups from
 * the site's own AUTH_KEY/SECURE_AUTH_KEY. These are already secret,
 * already unique per site, and already rotate with the site's salts, so
 * there is no new key material to generate or store anywhere.
 *
 * @return array{enc:string,mac:string}|false Two 32-byte binary keys, or
 *                                             false if no usable secret
 *                                             material is defined.
 */
function ccm_tools_redis_backup_key() {
    $secret = (defined('AUTH_KEY') ? AUTH_KEY : '') . (defined('SECURE_AUTH_KEY') ? SECURE_AUTH_KEY : '');
    if ($secret === '' || strlen($secret) < 16) {
        return false;
    }
    return array(
        'enc' => hash_hmac('sha256', 'ccm-tools-redis-wpconfig-backup-enc', $secret, true),
        'mac' => hash_hmac('sha256', 'ccm-tools-redis-wpconfig-backup-mac', $secret, true),
    );
}

/**
 * Encrypt a wp-config.php backup body: AES-256-CBC then HMAC-SHA256 over the
 * ciphertext (encrypt-then-MAC), with the IV and MAC stored alongside the
 * ciphertext so ccm_tools_redis_decrypt_backup() is self-contained.
 *
 * This is defence in depth against a *static file* leak (nginx not honouring
 * .htaccess, a misconfigured backup tool, a misdirected symlink) — not
 * against an attacker who already has PHP execution or DB access on the
 * site, since the key is derived from that same site's own secrets.
 *
 * @param string $plaintext Raw wp-config.php content to protect.
 * @return string|false Encrypted blob, or false if OpenSSL (or usable key
 *                       material) is unavailable — callers must treat that
 *                       as "refuse to write an unencrypted backup", never
 *                       fall back to writing $plaintext as-is.
 */
function ccm_tools_redis_encrypt_backup($plaintext) {
    if (!function_exists('openssl_encrypt') || !in_array('aes-256-cbc', array_map('strtolower', openssl_get_cipher_methods()), true)) {
        return false;
    }
    $keys = ccm_tools_redis_backup_key();
    if ($keys === false) {
        return false;
    }

    $iv = random_bytes(16);
    $ciphertext = openssl_encrypt($plaintext, 'aes-256-cbc', $keys['enc'], OPENSSL_RAW_DATA, $iv);
    if ($ciphertext === false) {
        return false;
    }

    $mac = hash_hmac('sha256', $iv . $ciphertext, $keys['mac'], true);

    // Magic header so decrypt() can recognise our format (and so a legacy
    // plaintext backup from before this change is never mistaken for one).
    return 'CCMENCB1' . $iv . $mac . $ciphertext;
}

/**
 * Decrypt a blob produced by ccm_tools_redis_encrypt_backup(), verifying the
 * HMAC before attempting to decrypt (encrypt-then-MAC: verify first).
 *
 * No restore UI wires this up yet — wp-config.php backups here are written
 * for a human with shell/SFTP access to recover from, and this is the
 * supported way to get the plaintext back out instead of reading raw
 * ciphertext off disk. A future "Restore backup" action should call this.
 *
 * @param string $blob Encrypted backup file content.
 * @return string|false Decrypted wp-config.php content, or false if the
 *                       blob is not one of ours, is truncated, or the HMAC
 *                       does not verify (tampered, or wrong/rotated key).
 */
function ccm_tools_redis_decrypt_backup($blob) {
    if (!function_exists('openssl_decrypt')) {
        return false;
    }
    $magic = 'CCMENCB1';
    if (strncmp((string) $blob, $magic, strlen($magic)) !== 0) {
        return false;
    }
    $keys = ccm_tools_redis_backup_key();
    if ($keys === false) {
        return false;
    }

    $offset = strlen($magic);
    $iv = substr($blob, $offset, 16);
    $offset += 16;
    $mac = substr($blob, $offset, 32);
    $offset += 32;
    $ciphertext = substr($blob, $offset);

    if (strlen($iv) !== 16 || strlen($mac) !== 32 || $ciphertext === '') {
        return false;
    }

    $expected_mac = hash_hmac('sha256', $iv . $ciphertext, $keys['mac'], true);
    if (!hash_equals($expected_mac, $mac)) {
        return false;
    }

    $plaintext = openssl_decrypt($ciphertext, 'aes-256-cbc', $keys['enc'], OPENSSL_RAW_DATA, $iv);
    return $plaintext === false ? false : $plaintext;
}

/**
 * Encrypt $plaintext and write it to $backup_path as a wp-config.php backup.
 * Refuses to write anything if encryption isn't possible, rather than ever
 * falling back to a plaintext credentials dump on disk.
 *
 * @param string $backup_path Absolute destination path.
 * @param string $plaintext   Raw wp-config.php content to back up.
 * @return bool True if an encrypted backup was written and verified.
 */
function ccm_tools_redis_write_encrypted_backup($backup_path, $plaintext) {
    $encrypted = ccm_tools_redis_encrypt_backup($plaintext);
    if ($encrypted === false) {
        return false;
    }
    $written = @file_put_contents($backup_path, $encrypted);
    return $written !== false && $written === strlen($encrypted);
}

/**
 * Read and decrypt a wp-config.php backup written by
 * ccm_tools_redis_write_encrypted_backup(). See ccm_tools_redis_decrypt_backup()
 * for the format and failure cases.
 *
 * @param string $backup_path Absolute path to a backup file.
 * @return string|false Decrypted wp-config.php content, or false.
 */
function ccm_tools_redis_read_backup($backup_path) {
    if (!file_exists($backup_path)) {
        return false;
    }
    $blob = file_get_contents($backup_path);
    if ($blob === false) {
        return false;
    }
    return ccm_tools_redis_decrypt_backup($blob);
}

/**
 * Bring the deployed wp-content/object-cache.php into line with the bundled
 * drop-in, WITHOUT requiring a live Redis connection.
 *
 * This is the auto-replace primitive used by plugin updates, (re)activation
 * and the admin_init self-heal. It is deliberately conservative:
 *   - never overwrites another plugin's drop-in;
 *   - only copies when the bundled @version is newer than the deployed one
 *     (or when the drop-in is missing and $install_if_missing is set);
 *   - backs up the existing drop-in before replacing it.
 *
 * @param bool $install_if_missing Copy the drop-in even if none is present.
 * @return array { changed: bool, message: string, reason: string }
 */
function ccm_tools_redis_refresh_dropin($install_if_missing = false) {
    $result = array('changed' => false, 'message' => '', 'reason' => '');

    $source = CCM_HELPER_ROOT_PATH . 'assets/object-cache.php';
    $dest   = WP_CONTENT_DIR . '/object-cache.php';

    if (!file_exists($source)) {
        $result['message'] = 'bundled drop-in missing';
        return $result;
    }

    $status = ccm_tools_redis_dropin_status();

    // Never clobber a drop-in that belongs to another plugin.
    if ($status['exists'] && $status['is_other']) {
        $result['message'] = 'foreign drop-in present; left untouched';
        return $result;
    }

    if ($status['exists']) {
        // Ours — only refresh when the bundled copy is actually newer.
        $vc = ccm_tools_redis_dropin_version_check();
        if (empty($vc['needs_update'])) {
            $result['message'] = 'drop-in already current';
            return $result;
        }
        $result['reason'] = sprintf('drop-in v%s -> v%s', $vc['installed'] ?: '?', $vc['bundled'] ?: '?');
    } else {
        if (!$install_if_missing) {
            $result['message'] = 'no drop-in present';
            return $result;
        }
        $result['reason'] = 'drop-in missing; installing';
    }

    if (!is_writable(WP_CONTENT_DIR)) {
        $result['message'] = 'wp-content not writable';
        return $result;
    }

    // Back up the outgoing CCM drop-in before overwriting.
    if ($status['exists']) {
        @copy($dest, WP_CONTENT_DIR . '/object-cache-backup-' . date('Y-m-d-His') . '.php');
    }

    // Write atomically via the shared helper (tmp file in the same dir,
    // byte-count verified, then rename() into place) — see
    // ccm_tools_redis_atomic_write_file() for why a plain copy()/
    // file_put_contents() isn't safe here: a concurrent request could read a
    // half-written object-cache.php and fatal.
    $source_content = @file_get_contents($source);
    if ($source_content === false) {
        $result['message'] = 'could not read bundled drop-in';
        return $result;
    }
    if (!ccm_tools_redis_atomic_write_file($dest, $source_content)) {
        $result['message'] = 'atomic replace failed (check permissions or disk space)';
        return $result;
    }

    ccm_tools_redis_prune_backups(WP_CONTENT_DIR, 'object-cache-backup-*.php', 5);

    $result['changed'] = true;
    $result['message'] = 'drop-in refreshed';

    // Keep wp-config.php in lockstep whenever the drop-in itself changes —
    // this runs on plugin update, (re)activation, and the admin_init
    // self-heal, any of which can happen well after the original Enable, so
    // wp-config could otherwise drift from what a newer bundled version
    // expects. Routed through the single shared builder (same as Enable /
    // Save / "Add to wp-config") so the constant list can never diverge
    // between paths. Only when Redis is actually flagged enabled, mirroring
    // the "ours but not enabled" branch in ccm_tools_redis_maybe_autosync_dropin()
    // — we shouldn't start writing new wp-config constants for a site that
    // isn't opted in.
    $sync_settings = function_exists('ccm_tools_redis_get_settings') ? ccm_tools_redis_get_settings() : array();
    if (!empty($sync_settings['enabled'])
        && function_exists('ccm_tools_redis_build_config_array')
        && function_exists('ccm_tools_redis_add_config')
    ) {
        ccm_tools_redis_add_config(ccm_tools_redis_build_config_array($sync_settings));
    }

    return $result;
}

/**
 * Remove the managed Redis configuration from wp-config.php.
 *
 * Strips the "CCM Tools Redis Configuration" block, any stray managed
 * defines (WP_REDIS_* and WP_CACHE_KEY_SALT) left outside it, and the
 * unterminated "/* Redis configuration *\/" header the old (now-removed)
 * ccm_tools_add_redis_configuration() writer in system-info.php used to
 * leave behind (it had no matching end marker, so the block-strip above
 * never matched it). ccm_tools_redis_add_config() is now the only writer of
 * Redis constants into wp-config.php. Backs the file up (encrypted) first
 * and verifies the write. Used on disable / plugin deactivation.
 *
 * @return array { success: bool, message: string, backup_path?: string }
 */
function ccm_tools_redis_remove_config() {
    $result = array('success' => false, 'message' => '');

    $wp_config_path   = ABSPATH . 'wp-config.php';
    $real_config_path = realpath($wp_config_path);
    $real_abspath     = realpath(ABSPATH);

    if ($real_config_path === false || $real_abspath === false
        || strpos($real_config_path, $real_abspath) !== 0
        || basename($real_config_path) !== 'wp-config.php'
    ) {
        $result['message'] = __('wp-config.php file not found.', 'ccm-tools');
        return $result;
    }
    if (!is_writable($real_config_path)) {
        $result['message'] = __('wp-config.php file is not writable.', 'ccm-tools');
        return $result;
    }

    $config_content = file_get_contents($real_config_path);
    if ($config_content === false) {
        $result['message'] = __('Could not read wp-config.php file.', 'ccm-tools');
        return $result;
    }
    $original = $config_content;

    // Strip our managed block.
    $config_content = preg_replace(
        '/\n?\/\*\s*CCM Tools Redis Configuration\s*\*\/.*?\/\*\s*End CCM Tools Redis Configuration\s*\*\/\n?/is',
        "\n",
        $config_content
    );
    // Strip any stray managed constants left elsewhere in the file — this
    // also cleans up defines the old system-info.php writer left loose in
    // the file (it shared the same WP_REDIS_* constant names).
    foreach (ccm_tools_redis_managed_constants() as $cname) {
        $config_content = preg_replace(
            '/^[ \t]*define\s*\(\s*[\'"]' . preg_quote($cname, '/') . '[\'"].*?\);\s*\n?/mi',
            '',
            $config_content
        );
    }
    // Remove the old writer's unterminated "/* Redis configuration */"
    // header comment (no matching end marker, so it never matched the
    // block-strip above and was left behind on every prior "Disable").
    $config_content = preg_replace('/^[ \t]*\/\*\s*Redis\s+configuration\s*\*\/\s*\n?/mi', '', $config_content);
    $config_content = preg_replace('/\n{4,}/', "\n\n\n", $config_content);

    if ($config_content === $original) {
        $result['success'] = true;
        $result['message'] = __('No Redis configuration found in wp-config.php.', 'ccm-tools');
        return $result;
    }

    // Back up outside the web root, encrypted — wp-config.php holds the
    // Redis credentials (and everything else) in plaintext, and .htaccess
    // alone does not protect this directory on an nginx-fronted site. Refuse
    // to proceed rather than fall back to an unencrypted backup.
    $backup_dir      = ccm_tools_redis_private_backup_dir();
    $backup_filename = 'wp-config-backup-' . wp_generate_password(8, false, false) . '-' . date('Y-m-d-His') . '.php';
    $backup_path     = $backup_dir . $backup_filename;

    if (!ccm_tools_redis_write_encrypted_backup($backup_path, $original)) {
        $result['message'] = __('Could not create an encrypted backup of wp-config.php.', 'ccm-tools');
        return $result;
    }
    if (!ccm_tools_redis_atomic_write_file($real_config_path, $config_content)) {
        $result['message'] = __('Could not write to wp-config.php file.', 'ccm-tools');
        return $result;
    }

    ccm_tools_redis_prune_backups($backup_dir, 'wp-config-backup-*.php', 5);

    $result['success']     = true;
    $result['message']     = __('Redis configuration removed from wp-config.php.', 'ccm-tools');
    $result['backup_path'] = $backup_path;
    return $result;
}

/* ──────────────────────────────────────────────────────────────────
 *  Drop-in lifecycle automation
 *
 *  Keeps the deployed object-cache.php in sync with intent:
 *   - plugin update  → refresh the drop-in to the bundled version
 *   - admin_init     → self-heal (reinstall if missing while enabled,
 *                      refresh if ours but outdated)
 *  (Activation/deactivation hooks live in ccm.php because they must be
 *   registered against the main plugin file.)
 * ────────────────────────────────────────────────────────────────── */

/**
 * After any plugin update/install completes, bring our drop-in current.
 * Self-gates on version, so it's a no-op unless OUR bundled drop-in changed.
 */
function ccm_tools_redis_on_upgrade($upgrader, $hook_extra) {
    if (empty($hook_extra['type']) || $hook_extra['type'] !== 'plugin') {
        return;
    }
    $action = $hook_extra['action'] ?? '';
    if ($action !== 'update' && $action !== 'install') {
        return;
    }
    // refresh_dropin only copies when the deployed CCM drop-in is older than
    // the bundled one — which can only happen when this plugin was updated.
    ccm_tools_redis_refresh_dropin(false);
}
add_action('upgrader_process_complete', 'ccm_tools_redis_on_upgrade', 10, 2);

/**
 * Self-heal on admin page loads: reinstall a missing drop-in when Redis is
 * enabled, or refresh an outdated CCM drop-in. Skipped during AJAX/cron to
 * avoid doing filesystem work on every background request.
 */
function ccm_tools_redis_maybe_autosync_dropin() {
    if ((defined('DOING_AJAX') && DOING_AJAX) || (defined('DOING_CRON') && DOING_CRON)) {
        return;
    }
    if (!function_exists('ccm_tools_redis_extension_available') || !ccm_tools_redis_extension_available()) {
        return;
    }

    $settings = ccm_tools_redis_get_settings();
    $status   = ccm_tools_redis_dropin_status();

    if (!empty($settings['enabled'])) {
        // Sites enabled before the auto-salt fix shipped may still be
        // running with an empty WP_CACHE_KEY_SALT and won't necessarily
        // ever hit Enable or Save again. Heal them here too, since this
        // path already runs for every already-enabled site. The drop-in
        // only ever reads the wp-config.php constant (never the DB option),
        // so persisting the salt alone isn't enough — push it into
        // wp-config.php too, through the same shared builder as every other
        // path, not just the DB option.
        if (empty($settings['key_salt']) && function_exists('ccm_tools_redis_generate_key_salt')) {
            $settings['key_salt'] = ccm_tools_redis_generate_key_salt();
            ccm_tools_redis_save_settings($settings);
            if (function_exists('ccm_tools_redis_build_config_array') && function_exists('ccm_tools_redis_add_config')) {
                ccm_tools_redis_add_config(ccm_tools_redis_build_config_array($settings));
            }
        }
        // Enabled: ensure a current CCM drop-in is in place (install if missing).
        ccm_tools_redis_refresh_dropin(true);
    } elseif ($status['is_ccm']) {
        // Not flagged enabled but our drop-in is live — keep it current anyway.
        ccm_tools_redis_refresh_dropin(false);
    }
}
add_action('admin_init', 'ccm_tools_redis_maybe_autosync_dropin');

/**
 * Flush the Redis cache
 * 
 * @param bool $selective Only flush keys for this site
 * @return array Result with success status and message
 */
function ccm_tools_redis_flush_cache($selective = true) {
    $result = array(
        'success' => false,
        'message' => '',
        'keys_deleted' => 0,
    );
    
    if (!ccm_tools_redis_extension_available()) {
        $result['message'] = __('Redis PHP extension is not installed.', 'ccm-tools');
        return $result;
    }
    
    $settings = ccm_tools_redis_get_settings();
    
    try {
        $redis = new Redis();
        
        if ($settings['scheme'] === 'unix' && !empty($settings['path'])) {
            $connected = @$redis->connect($settings['path']);
        } elseif ($settings['scheme'] === 'tls') {
            $connected = @$redis->connect('tls://' . $settings['host'], $settings['port'], $settings['timeout']);
        } else {
            $connected = @$redis->connect($settings['host'], $settings['port'], $settings['timeout']);
        }
        
        if (!$connected) {
            $result['message'] = __('Could not connect to Redis server.', 'ccm-tools');
            return $result;
        }
        
        if (!empty($settings['password'])) {
            $auth = (!empty($settings['username'])) ? array($settings['username'], $settings['password']) : $settings['password'];
            if (!@$redis->auth($auth)) {
                $result['message'] = __('Redis authentication failed.', 'ccm-tools');
                return $result;
            }
        }
        
        if ($settings['database'] > 0) {
            $redis->select($settings['database']);
        }
        
        if ($selective && !empty($settings['key_salt'])) {
            // Selective flush - only keys with our prefix (using SCAN to avoid blocking)
            $pattern = $settings['key_salt'] . '*';
            $deleted = 0;
            $iterator = null;
            
            while (true) {
                $keys_batch = $redis->scan($iterator, $pattern, 200);
                if ($keys_batch === false) {
                    break;
                }
                if (!empty($keys_batch)) {
                    $deleted += $redis->del($keys_batch);
                }
                if ($iterator === 0) {
                    break;
                }
            }
            
            $result['keys_deleted'] = $deleted;
            
            $result['success'] = true;
            $result['message'] = sprintf(
                __('Selectively flushed %d cache keys.', 'ccm-tools'),
                $result['keys_deleted']
            );
        } else {
            // Full database flush
            $redis->flushDb();
            $result['success'] = true;
            $result['message'] = __('Redis cache flushed successfully.', 'ccm-tools');
        }
        
        $redis->close();
        
    } catch (Exception $e) {
        $result['message'] = $e->getMessage();
    }
    
    return $result;
}

/**
 * Get Redis cache statistics (site-specific only)
 * 
 * @return array Cache statistics
 */
function ccm_tools_redis_get_stats() {
    $stats = array(
        'status' => 'disconnected',
        'keys' => 0,
        'memory_used' => 'N/A',
        'memory_bytes' => 0,
        'groups' => 0,
        'avg_ttl' => 'N/A',
        'largest_key' => 'N/A',
        'uptime' => 0,
        'version' => '',
        'key_prefix' => '',
    );
    
    $connection = ccm_tools_redis_check_connection();
    
    if ($connection['connected']) {
        $stats['status'] = 'connected';
        $stats['uptime'] = $connection['uptime'];
        $stats['version'] = $connection['version'];
        
        // Get site-specific stats
        $settings = ccm_tools_redis_get_settings();
        $stats['key_prefix'] = $settings['key_salt'];
        
        try {
            $redis = new Redis();
            
            if ($settings['scheme'] === 'unix' && !empty($settings['path'])) {
                $redis->connect($settings['path']);
            } elseif ($settings['scheme'] === 'tls') {
                $redis->connect('tls://' . $settings['host'], $settings['port'], $settings['timeout']);
            } else {
                $redis->connect($settings['host'], $settings['port'], $settings['timeout']);
            }
            
            if (!empty($settings['password'])) {
                $auth = (!empty($settings['username'])) ? array($settings['username'], $settings['password']) : $settings['password'];
                $redis->auth($auth);
            }
            
            if ($settings['database'] > 0) {
                $redis->select($settings['database']);
            }
            
            // Count keys for this site only using SCAN (non-blocking, safe for large Redis instances)
            if (!empty($settings['key_salt'])) {
                $pattern = $settings['key_salt'] . '*';
                $key_count = 0;
                $sample_keys = array();
                $max_sample = 100;
                $iterator = null;
                
                // Use SCAN to iterate keys without blocking Redis
                while (true) {
                    $keys_batch = $redis->scan($iterator, $pattern, 200);
                    if ($keys_batch === false) {
                        break;
                    }
                    $key_count += count($keys_batch);
                    // Collect sample keys from early batches
                    if (count($sample_keys) < $max_sample) {
                        $remaining = $max_sample - count($sample_keys);
                        $sample_keys = array_merge($sample_keys, array_slice($keys_batch, 0, $remaining));
                    }
                    if ($iterator === 0) {
                        break;
                    }
                }
                
                $stats['keys'] = $key_count;
                
                if ($key_count > 0 && count($sample_keys) > 0) {
                    // Calculate memory usage and other stats from sample
                    $sample_size = count($sample_keys);
                    
                    $total_memory = 0;
                    $total_ttl = 0;
                    $ttl_count = 0;
                    $largest_size = 0;
                    $largest_key_name = '';
                    $groups = array();
                    
                    foreach ($sample_keys as $key) {
                        // Try MEMORY USAGE first (Redis 4.0+), then fall back to STRLEN
                        $mem = $redis->rawCommand('MEMORY', 'USAGE', $key);
                        if ($mem === false || $mem === null) {
                            // Fallback: get string length
                            $value = $redis->get($key);
                            $mem = $value ? strlen($value) : 0;
                        }
                        
                        $total_memory += $mem;
                        
                        // Track largest key
                        if ($mem > $largest_size) {
                            $largest_size = $mem;
                            $largest_key_name = $key;
                        }
                        
                        // Get TTL
                        $ttl = $redis->ttl($key);
                        if ($ttl > 0) {
                            $total_ttl += $ttl;
                            $ttl_count++;
                        }
                        
                        // Extract cache group from key (format: prefix:group:hash)
                        $key_without_prefix = substr($key, strlen($settings['key_salt']));
                        $parts = explode(':', $key_without_prefix);
                        if (!empty($parts[0])) {
                            $groups[$parts[0]] = true;
                        }
                    }
                    
                    // Extrapolate memory for all keys
                    if ($sample_size > 0 && $total_memory > 0) {
                        $avg_size = $total_memory / $sample_size;
                        $estimated_total = $avg_size * $key_count;
                        $stats['memory_bytes'] = $estimated_total;
                        $stats['memory_used'] = ccm_tools_redis_format_bytes($estimated_total);
                    }
                    
                    // Cache groups count (extrapolate if sampled)
                    $stats['groups'] = count($groups);
                    
                    // Average TTL
                    if ($ttl_count > 0) {
                        $avg_ttl_seconds = $total_ttl / $ttl_count;
                        $stats['avg_ttl'] = ccm_tools_redis_format_duration($avg_ttl_seconds);
                    } else {
                        $stats['avg_ttl'] = __('No expiry', 'ccm-tools');
                    }
                    
                    // Largest key (show group name, not full key for security)
                    if ($largest_key_name) {
                        $key_without_prefix = substr($largest_key_name, strlen($settings['key_salt']));
                        $parts = explode(':', $key_without_prefix);
                        $stats['largest_key'] = $parts[0] . ' (' . ccm_tools_redis_format_bytes($largest_size) . ')';
                    }
                }
            } else {
                // No key salt - show database size
                $stats['keys'] = $redis->dbSize();
                $stats['memory_used'] = $connection['memory_used'];
            }
            
            $redis->close();
            
        } catch (Exception $e) {
            // Silently fail
            error_log('CCM Tools Redis Stats Error: ' . $e->getMessage());
        }
    }
    
    return $stats;
}

/**
 * Format seconds to human readable duration
 * 
 * @param int $seconds Seconds to format
 * @return string Formatted duration
 */
function ccm_tools_redis_format_duration($seconds) {
    if ($seconds < 60) {
        return round($seconds) . 's';
    } elseif ($seconds < 3600) {
        return round($seconds / 60) . 'm';
    } elseif ($seconds < 86400) {
        return round($seconds / 3600, 1) . 'h';
    } else {
        return round($seconds / 86400, 1) . 'd';
    }
}

/**
 * Format bytes to human readable string
 * 
 * @param int $bytes Bytes to format
 * @return string Formatted string
 */
function ccm_tools_redis_format_bytes($bytes) {
    if ($bytes == 0) {
        return '0 B';
    }
    
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    $i = floor(log($bytes, 1024));
    $i = min($i, count($units) - 1);
    
    return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
}

/**
 * Build a single wp-config define() line with an injection-proof value
 * literal. Using var_export() (rather than string interpolation) means the
 * value can contain quotes, backslashes, or anything else and it will always
 * be emitted as one safe PHP literal — no way to break out of the string and
 * inject additional statements into wp-config.php.
 *
 * @param string $constant Constant name (already validated/whitelisted by caller).
 * @param mixed  $value    Constant value (bool, int, float, or string).
 * @return string A complete "define('CONST', <literal>);" line.
 */
function ccm_tools_redis_config_line(string $constant, $value): string {
    return "define('" . $constant . "', " . var_export($value, true) . ");";
}

/**
 * Add Redis configuration to wp-config.php
 *
 * @param array $config Configuration values to add
 * @return array Result with success status and message
 */
function ccm_tools_redis_add_config($config = array()) {
    $result = array(
        'success' => false,
        'message' => '',
    );
    
    // Security: Build and validate the wp-config.php path
    $wp_config_path = ABSPATH . 'wp-config.php';
    
    // Resolve to real path and verify it's within ABSPATH
    $real_config_path = realpath($wp_config_path);
    $real_abspath = realpath(ABSPATH);
    
    if ($real_config_path === false || $real_abspath === false) {
        $result['message'] = __('wp-config.php file not found.', 'ccm-tools');
        return $result;
    }
    
    // Ensure the file is actually within ABSPATH (prevent path traversal)
    if (strpos($real_config_path, $real_abspath) !== 0) {
        $result['message'] = __('Invalid wp-config.php path.', 'ccm-tools');
        return $result;
    }
    
    // Verify the file is named wp-config.php
    if (basename($real_config_path) !== 'wp-config.php') {
        $result['message'] = __('Invalid configuration file.', 'ccm-tools');
        return $result;
    }
    
    if (!is_writable($real_config_path)) {
        $result['message'] = __('wp-config.php file is not writable.', 'ccm-tools');
        return $result;
    }
    
    $config_content = file_get_contents($real_config_path);
    if ($config_content === false) {
        $result['message'] = __('Could not read wp-config.php file.', 'ccm-tools');
        return $result;
    }
    $original_content = $config_content;

    // ── Remove any existing CCM Tools Redis Configuration block ──
    // This makes the operation idempotent — always fresh, no duplicates.
    $config_content = preg_replace(
        '/\n?\/\*\s*CCM Tools Redis Configuration\s*\*\/.*?\/\*\s*End CCM Tools Redis Configuration\s*\*\/\n?/is',
        "\n",
        $config_content
    );

    // ── Remove ANY pre-existing Redis constants (from other plugins, manual edits, etc.) ──
    // This prevents stale values from blocking our managed block.
    foreach (ccm_tools_redis_managed_constants() as $cname) {
        $config_content = preg_replace(
            '/^[ \t]*define\s*\(\s*[\'"]' . preg_quote($cname, '/') . '[\'"].*?\);\s*\n?/mi',
            '',
            $config_content
        );
    }
    // Remove common Redis comment headers left behind (but not our block markers)
    $config_content = preg_replace('/^[ \t]*\/\*\s*Redis\s+configuration\s*\*\/\s*\n?/mi', '', $config_content);
    // Collapse excessive blank lines left by removals (3+ → 2)
    $config_content = preg_replace('/\n{4,}/', "\n\n\n", $config_content);
    
    // Default configuration
    $defaults = array(
        'WP_REDIS_HOST' => '127.0.0.1',
        'WP_REDIS_PORT' => 6379,
        'WP_REDIS_MAXTTL' => 604800,
        'WP_REDIS_DISABLE_METRICS' => true,
        'WP_REDIS_DISABLE_COMMENT' => true,
    );
    
    // Fallback salt if the caller's $config didn't already supply one (e.g.
    // ccm_tools_redis_build_config_array() only adds WP_CACHE_KEY_SALT when
    // the stored setting is non-empty). Host-only would still collide for
    // two installs sharing one hostname (e.g. subdirectory multisite on
    // shared hosting), so this always includes a random suffix too — same
    // generator ccm_tools_redis_save_settings() uses to persist a salt.
    if (function_exists('ccm_tools_redis_generate_key_salt')) {
        $defaults['WP_CACHE_KEY_SALT'] = ccm_tools_redis_generate_key_salt();
    }

    $config = array_merge($defaults, $config);
    
    // Build configuration lines
    $config_lines = array("\n/* CCM Tools Redis Configuration */");
    
    foreach ($config as $constant => $value) {
        // Route every value type through var_export() so nothing — booleans,
        // numbers, or attacker-influenced strings (e.g. a Redis password) —
        // can ever break out of the define() literal.
        $config_lines[] = ccm_tools_redis_config_line($constant, $value);
    }
    
    // Only proceed if we have new constants to add
    if (count($config_lines) <= 1) {
        $result['success'] = true;
        $result['message'] = __('Redis configuration already exists in wp-config.php.', 'ccm-tools');
        return $result;
    }
    
    $config_lines[] = "/* End CCM Tools Redis Configuration */\n";
    $config_text = implode("\n", $config_lines);
    
    // Find the best place to insert - before "That's all, stop editing"
    $patterns = array(
        '/(\/\*\s*That\'s\s+all,\s+stop\s+editing!.*?\*\/)/is',
        '/(\/\*\*\s*Absolute\s+path\s+to\s+the\s+WordPress\s+directory\..*?\*\/)/is',
        '/(if\s*\(\s*!\s*defined\s*\(\s*[\'"]ABSPATH[\'"]\s*\))/i',
    );
    
    $inserted = false;
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $config_content)) {
            $new_content = preg_replace($pattern, $config_text . "\n$1", $config_content, 1, $count);
            if ($count > 0) {
                $config_content = $new_content;
                $inserted = true;
                break;
            }
        }
    }
    
    if (!$inserted) {
        // Append before the end
        $config_content .= $config_text;
    }

    // Nothing actually changed — skip the write so we don't spawn a needless
    // backup on every Save (this path now runs automatically on each save).
    if ($config_content === $original_content) {
        $result['success'] = true;
        $result['message'] = __('Redis configuration already up to date in wp-config.php.', 'ccm-tools');
        $result['unchanged'] = true;
        return $result;
    }

    // Create backup with secure filename, stored outside the web root and
    // encrypted — wp-config.php holds the Redis credentials (and everything
    // else) in plaintext, and .htaccess alone does not protect this
    // directory on an nginx-fronted site. Refuse to proceed rather than
    // fall back to an unencrypted backup.
    $backup_dir      = ccm_tools_redis_private_backup_dir();
    $backup_filename = 'wp-config-backup-' . wp_generate_password(8, false, false) . '-' . date('Y-m-d-His') . '.php';
    $backup_path     = $backup_dir . $backup_filename;

    if (!ccm_tools_redis_write_encrypted_backup($backup_path, $original_content)) {
        $result['message'] = __('Could not create an encrypted backup of wp-config.php.', 'ccm-tools');
        return $result;
    }

    // Write the new content atomically (tmp file + rename, byte-count
    // verified) so a worker kill, full disk, or timeout mid-write can never
    // leave a truncated wp-config.php.
    if (!ccm_tools_redis_atomic_write_file($real_config_path, $config_content)) {
        $result['message'] = __('Could not write to wp-config.php file.', 'ccm-tools');
        return $result;
    }

    ccm_tools_redis_prune_backups($backup_dir, 'wp-config-backup-*.php', 5);

    $result['success'] = true;
    $result['message'] = __('Redis configuration saved to wp-config.php successfully.', 'ccm-tools');
    $result['backup_path'] = $backup_path;

    return $result;
}

/**
 * Format uptime seconds to human-readable string
 * 
 * @param int $seconds Uptime in seconds
 * @return string Formatted uptime
 */
function ccm_tools_redis_format_uptime($seconds) {
    if ($seconds < 60) {
        return sprintf(_n('%d second', '%d seconds', $seconds, 'ccm-tools'), $seconds);
    }
    
    $days = floor($seconds / 86400);
    $hours = floor(($seconds % 86400) / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    
    $parts = array();
    
    if ($days > 0) {
        $parts[] = sprintf(_n('%d day', '%d days', $days, 'ccm-tools'), $days);
    }
    if ($hours > 0) {
        $parts[] = sprintf(_n('%d hour', '%d hours', $hours, 'ccm-tools'), $hours);
    }
    if ($minutes > 0 && $days === 0) {
        $parts[] = sprintf(_n('%d minute', '%d minutes', $minutes, 'ccm-tools'), $minutes);
    }
    
    return implode(', ', $parts);
}

/**
 * Render the Redis admin page
 */
function ccm_tools_render_redis_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.', 'ccm-tools'));
    }

    $extension_available = ccm_tools_redis_extension_available();

    // Round-trip time: timed around the connect + AUTH + SELECT + INFO
    // handshake ccm_tools_redis_check_connection() already performs, rather
    // than opening a second connection just to measure latency.
    $rtt_start  = microtime(true);
    $connection = ccm_tools_redis_check_connection();
    $rtt_ms     = $connection['connected'] ? round((microtime(true) - $rtt_start) * 1000, 1) : null;

    $settings        = ccm_tools_redis_get_settings();
    $dropin_status   = ccm_tools_redis_dropin_status();
    $version_check   = ccm_tools_redis_dropin_version_check();
    $has_woocommerce = class_exists('WooCommerce');
    $is_unix         = $settings['scheme'] === 'unix';

    $stats = ($extension_available && $connection['connected']) ? ccm_tools_redis_get_stats() : null;

    // Live values from the running drop-in — only meaningful when it is ours,
    // connected, and WordPress has actually booted an object cache.
    $runtime = null;
    if ($connection['connected'] && $dropin_status['is_ccm'] && function_exists('wp_cache_get')) {
        global $wp_object_cache;
        if (is_object($wp_object_cache) && method_exists($wp_object_cache, 'info')) {
            $runtime = $wp_object_cache->info();
        }
    }

    // Which wp-config constants belong to which configuration group, so the
    // group eyebrows and the Status panel can say how many are locked there.
    $constants_connection  = array('WP_REDIS_HOST', 'WP_REDIS_PORT', 'WP_REDIS_PATH', 'WP_REDIS_SCHEME', 'WP_REDIS_DATABASE', 'WP_REDIS_USERNAME', 'WP_REDIS_PASSWORD');
    $constants_cache       = array('WP_REDIS_MAXTTL', 'WP_CACHE_KEY_SALT', 'WP_REDIS_SELECTIVE_FLUSH');
    $constants_advanced    = array('WP_REDIS_TIMEOUT', 'WP_REDIS_READ_TIMEOUT', 'WP_REDIS_RETRY_INTERVAL', 'WP_REDIS_SERIALIZER', 'WP_REDIS_COMPRESSION', 'WP_REDIS_ASYNC_FLUSH', 'WP_REDIS_DISABLE_COMMENT');
    $all_managed_constants = ccm_tools_redis_managed_constants();
    $locked_constants       = array_filter($all_managed_constants, 'defined');
    $count_locked           = function (array $list) {
        return count(array_filter($list, 'defined'));
    };

    $has_lzf  = defined('Redis::COMPRESSION_LZF');
    $has_lz4  = defined('Redis::COMPRESSION_LZ4');
    $has_zstd = defined('Redis::COMPRESSION_ZSTD');
    ?>
    <div class="wrap ccm-tools">
        <?php
        if (function_exists('ccm_tools_render_header_nav')) {
            ccm_tools_render_header_nav('ccm-tools-redis');
        }
        ?>

        <div class="ccm-content">

            <!-- Hero -->
            <div class="ccm-hero">
                <div class="ccm-hero__text">
                    <h1><?php _e('Redis Object Cache', 'ccm-tools'); ?></h1>
                    <div class="ccm-hero__meta">
                        <?php if (!$extension_available) : ?>
                            <span><?php _e('PHP extension not installed', 'ccm-tools'); ?></span>
                        <?php elseif (!$connection['connected']) : ?>
                            <span><?php _e('Not connected', 'ccm-tools'); ?></span>
                        <?php else : ?>
                            <span><?php _e('Connected', 'ccm-tools'); ?></span>
                            <span><?php printf(esc_html__('Redis %s', 'ccm-tools'), esc_html($connection['version'])); ?></span>
                        <?php endif; ?>
                        <?php if ($dropin_status['is_ccm']) : ?>
                            <span><?php printf(
                                esc_html__('Drop-in v%s', 'ccm-tools'),
                                esc_html($dropin_status['version'] !== '' ? $dropin_status['version'] : '?')
                            ); ?></span>
                        <?php elseif ($dropin_status['is_other']) : ?>
                            <span><?php printf(esc_html__('Drop-in: %s', 'ccm-tools'), esc_html($dropin_status['other_plugin'])); ?></span>
                        <?php else : ?>
                            <span><?php _e('Drop-in not installed', 'ccm-tools'); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="ccm-hero__actions">
                    <?php if ($extension_available && $connection['connected']) : ?>
                        <button type="button" id="redis-flush" class="ccm-button ccm-button-secondary">
                            <?php _e('Flush Cache', 'ccm-tools'); ?>
                        </button>
                        <?php if ($dropin_status['is_ccm']) : ?>
                            <button type="button" id="redis-disable" class="ccm-button ccm-button-danger">
                                <?php _e('Disable Object Cache', 'ccm-tools'); ?>
                            </button>
                        <?php else : ?>
                            <button type="button" id="redis-enable" class="ccm-button ccm-button-primary" <?php echo $dropin_status['is_other'] ? 'data-force="true"' : ''; ?>>
                                <?php echo $dropin_status['is_other'] ? esc_html__('Replace & Enable', 'ccm-tools') : esc_html__('Enable Object Cache', 'ccm-tools'); ?>
                            </button>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- At a glance -->
            <?php if ($extension_available && $connection['connected']) :
                $hits        = intval($connection['hits']);
                $misses      = intval($connection['misses']);
                $has_traffic = ($hits + $misses) > 0;
                $hit_ratio   = $connection['hit_ratio'];
                // Bands below are a judgement call, not a Redis-published
                // standard: north of 80% the cache is clearly earning its
                // keep, under 50% it is barely being used yet.
                $hit_dot = !$has_traffic ? '' : ($hit_ratio >= 80 ? 'ccm-dot-ok' : ($hit_ratio >= 50 ? 'ccm-dot-warn' : 'ccm-dot-bad'));
                $mem_dot = $stats['memory_bytes'] > 536870912 ? 'ccm-dot-warn' : 'ccm-dot-ok'; // >512MB for one site is worth a look
                $keys_dot = $stats['keys'] > 0 ? 'ccm-dot-ok' : 'ccm-dot-warn';
                $rtt_dot = $rtt_ms === null ? '' : ($rtt_ms <= 15 ? 'ccm-dot-ok' : ($rtt_ms <= 50 ? 'ccm-dot-warn' : 'ccm-dot-bad'));
                ?>
                <div class="ccm-stat-grid">
                    <div class="ccm-stat-tile">
                        <div class="ccm-stat-tile__value">
                            <?php echo $has_traffic ? esc_html(number_format_i18n($hit_ratio, 1)) . '<small>%</small>' : '—'; ?>
                        </div>
                        <div class="ccm-stat-tile__label"><?php _e('Hit rate', 'ccm-tools'); ?></div>
                        <div class="ccm-stat-tile__sub">
                            <span class="ccm-dot <?php echo esc_attr($hit_dot); ?>"></span>
                            <?php if ($has_traffic) : ?>
                                <?php printf(esc_html__('%s hits, whole server since restart', 'ccm-tools'), esc_html(number_format_i18n($hits))); ?>
                            <?php else : ?>
                                <?php _e('No traffic recorded yet', 'ccm-tools'); ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="ccm-stat-tile">
                        <div class="ccm-stat-tile__value" id="redis-stat-memory"><?php echo esc_html($stats['memory_used']); ?></div>
                        <div class="ccm-stat-tile__label"><?php _e('Memory used', 'ccm-tools'); ?></div>
                        <div class="ccm-stat-tile__sub">
                            <span class="ccm-dot <?php echo esc_attr($mem_dot); ?>"></span>
                            <?php _e('This site only', 'ccm-tools'); ?>
                        </div>
                    </div>

                    <div class="ccm-stat-tile">
                        <div class="ccm-stat-tile__value" id="redis-stat-keys"><?php echo esc_html(number_format_i18n($stats['keys'])); ?></div>
                        <div class="ccm-stat-tile__label"><?php _e('Keys for this site', 'ccm-tools'); ?></div>
                        <div class="ccm-stat-tile__sub">
                            <span class="ccm-dot <?php echo esc_attr($keys_dot); ?>"></span>
                            <?php echo $stats['keys'] > 0 ? esc_html__('Prefix is scoped and populated', 'ccm-tools') : esc_html__('Nothing cached yet', 'ccm-tools'); ?>
                        </div>
                    </div>

                    <div class="ccm-stat-tile">
                        <div class="ccm-stat-tile__value">
                            <?php echo $rtt_ms !== null ? esc_html(number_format_i18n($rtt_ms, 1)) . '<small>ms</small>' : '—'; ?>
                        </div>
                        <div class="ccm-stat-tile__label"><?php _e('Round-trip time', 'ccm-tools'); ?></div>
                        <div class="ccm-stat-tile__sub">
                            <span class="ccm-dot <?php echo esc_attr($rtt_dot); ?>"></span>
                            <?php _e('Connect and INFO, this page load', 'ccm-tools'); ?>
                        </div>
                    </div>
                </div>

                <p class="ccm-text-muted" style="font-size: var(--ccm-text-xs); margin-top: calc(-1 * var(--ccm-space-md)); margin-bottom: var(--ccm-space-lg);">
                    <?php printf(
                        /* translators: 1: key prefix, 2: cache group count, 3: average TTL */
                        esc_html__('Scoped to keys prefixed %1$s — %2$s cache groups, average TTL %3$s', 'ccm-tools'),
                        '<code>' . esc_html($stats['key_prefix']) . '</code>',
                        '<span id="redis-stat-groups">' . esc_html(number_format_i18n($stats['groups'])) . '</span>',
                        '<span id="redis-stat-ttl">' . esc_html($stats['avg_ttl']) . '</span>'
                    ); ?>
                </p>
            <?php elseif ($extension_available) : ?>
                <div class="ccm-empty">
                    <span class="ccm-empty__icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><path d="M21 2l-2 2m-7.6 7.6a5 5 0 11-7 7 5 5 0 017-7zm0 0L15 8m0 0l3 3 3-3-3-3"/></svg>
                    </span>
                    <h3><?php _e('Redis is not reachable', 'ccm-tools'); ?></h3>
                    <p>
                        <?php if (!empty($connection['error'])) : ?>
                            <?php echo esc_html($connection['error']); ?>
                        <?php else : ?>
                            <?php _e('The PHP extension is installed but the server did not answer.', 'ccm-tools'); ?>
                        <?php endif; ?>
                        <?php _e('Check the host, port and password in Connection below, or confirm the Redis server itself is running.', 'ccm-tools'); ?>
                    </p>
                </div>
            <?php else : ?>
                <div class="ccm-empty">
                    <span class="ccm-empty__icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><path d="M21 2l-2 2m-7.6 7.6a5 5 0 11-7 7 5 5 0 017-7zm0 0L15 8m0 0l3 3 3-3-3-3"/></svg>
                    </span>
                    <h3><?php _e('The Redis PHP extension is not installed', 'ccm-tools'); ?></h3>
                    <p><?php _e('Object caching needs the PHP redis extension on the server. Ask your host to enable it, then come back to this page.', 'ccm-tools'); ?></p>
                </div>
            <?php endif; ?>

            <!-- Status -->
            <div class="ccm-panel">
                <div class="ccm-panel__head">
                    <span><?php _e('Status', 'ccm-tools'); ?></span>
                    <?php if ($extension_available) : ?>
                        <button type="button" id="redis-test" class="ccm-button ccm-button-secondary ccm-button-small">
                            <?php _e('Test Connection', 'ccm-tools'); ?>
                        </button>
                    <?php endif; ?>
                </div>
                <div class="ccm-kv">
                    <div>
                        <span class="ccm-kv__k"><?php _e('PHP extension', 'ccm-tools'); ?></span>
                        <span class="ccm-kv__v">
                            <?php if ($extension_available) : ?>
                                <span class="ccm-chip ccm-chip--good"><?php _e('Installed', 'ccm-tools'); ?></span>
                            <?php else : ?>
                                <span class="ccm-chip ccm-chip--bad"><?php _e('Not installed', 'ccm-tools'); ?></span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <?php if ($extension_available) : ?>
                    <div>
                        <span class="ccm-kv__k"><?php _e('Server connection', 'ccm-tools'); ?></span>
                        <span class="ccm-kv__v">
                            <?php if ($connection['connected']) : ?>
                                <span class="ccm-chip ccm-chip--good"><?php _e('Connected', 'ccm-tools'); ?></span>
                                <?php echo esc_html($connection['host']); ?><?php echo $connection['port'] ? ':' . esc_html($connection['port']) : ''; ?>
                            <?php else : ?>
                                <span class="ccm-chip ccm-chip--bad"><?php _e('Not connected', 'ccm-tools'); ?></span>
                                <?php if (!empty($connection['error'])) : ?>
                                    <small><?php echo esc_html($connection['error']); ?></small>
                                <?php endif; ?>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div>
                        <span class="ccm-kv__k"><?php _e('wp-config constants', 'ccm-tools'); ?></span>
                        <span class="ccm-kv__v">
                            <?php if ($locked_constants) : ?>
                                <?php printf(
                                    /* translators: 1: number locked, 2: number of settings that can be locked */
                                    esc_html__('%1$d of %2$d settings locked there', 'ccm-tools'),
                                    count($locked_constants),
                                    count($all_managed_constants)
                                ); ?>
                                <small><?php echo esc_html(implode(', ', $locked_constants)); ?></small>
                            <?php else : ?>
                                <?php _e('None — everything below is stored in the database', 'ccm-tools'); ?>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div>
                        <span class="ccm-kv__k"><?php _e('Drop-in', 'ccm-tools'); ?></span>
                        <span class="ccm-kv__v">
                            <?php if ($dropin_status['is_ccm']) : ?>
                                <span class="ccm-chip ccm-chip--good"><?php _e('Enabled', 'ccm-tools'); ?></span>
                                <?php if ($dropin_status['version'] !== '') : ?>
                                    v<?php echo esc_html($dropin_status['version']); ?>
                                <?php endif; ?>
                                <?php if ($version_check['needs_update']) : ?>
                                    <span class="ccm-chip ccm-chip--warn"><?php printf(esc_html__('Update to v%s available', 'ccm-tools'), esc_html($version_check['bundled'])); ?></span>
                                    <button type="button" id="redis-update-dropin" class="ccm-button ccm-button-secondary ccm-button-small">
                                        <?php _e('Update Drop-In', 'ccm-tools'); ?>
                                    </button>
                                <?php endif; ?>
                            <?php elseif ($dropin_status['is_other']) : ?>
                                <span class="ccm-chip ccm-chip--warn"><?php _e('Other plugin active', 'ccm-tools'); ?></span>
                                <?php echo esc_html($dropin_status['other_plugin']); ?>
                            <?php else : ?>
                                <span class="ccm-chip"><?php _e('Not installed', 'ccm-tools'); ?></span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div>
                        <span class="ccm-kv__k"><?php _e('Options group', 'ccm-tools'); ?></span>
                        <span class="ccm-kv__v">
                            <?php if (defined('WP_REDIS_PERSIST_OPTIONS') && WP_REDIS_PERSIST_OPTIONS) : ?>
                                <?php _e('Persisted to Redis — WP_REDIS_PERSIST_OPTIONS overrides the default skip.', 'ccm-tools'); ?>
                            <?php else : ?>
                                <?php _e('Skipped, deliberately: alloptions is read on almost every request, so caching it in Redis risks an out-of-memory crash across the whole site if that one copy is ever corrupt.', 'ccm-tools'); ?>
                            <?php endif; ?>
                        </span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($runtime) : ?>
            <!-- Drop-in runtime -->
            <div class="ccm-panel" style="margin-top: var(--ccm-space-md);">
                <div class="ccm-panel__head">
                    <span><?php _e('Drop-in runtime', 'ccm-tools'); ?></span>
                    <span class="ccm-chip ccm-chip--info"><?php _e('Live values from the running drop-in', 'ccm-tools'); ?></span>
                </div>
                <div class="ccm-kv">
                    <div><span class="ccm-kv__k"><?php _e('Serializer', 'ccm-tools'); ?></span><span class="ccm-kv__v"><code><?php echo esc_html($runtime['serializer'] ?? 'php'); ?></code></span></div>
                    <div><span class="ccm-kv__k"><?php _e('Compression', 'ccm-tools'); ?></span><span class="ccm-kv__v"><code><?php echo esc_html($runtime['compression'] ?? 'none'); ?></code></span></div>
                    <div><span class="ccm-kv__k"><?php _e('Async flush', 'ccm-tools'); ?></span><span class="ccm-kv__v"><?php echo !empty($runtime['async_flush']) ? esc_html__('On — UNLINK', 'ccm-tools') : esc_html__('Off', 'ccm-tools'); ?></span></div>
                    <div><span class="ccm-kv__k"><?php _e('Selective flush', 'ccm-tools'); ?></span><span class="ccm-kv__v"><?php echo !empty($runtime['selective_flush']) ? esc_html__('On', 'ccm-tools') : esc_html__('Off', 'ccm-tools'); ?></span></div>
                    <div><span class="ccm-kv__k"><?php _e('Max TTL', 'ccm-tools'); ?></span><span class="ccm-kv__v"><?php echo intval($runtime['max_ttl'] ?? 0); ?>s<?php echo intval($runtime['max_ttl'] ?? 0) === 0 ? ' (' . esc_html__('no limit', 'ccm-tools') . ')' : ''; ?></span></div>
                    <div><span class="ccm-kv__k"><?php _e('KEEPTTL support', 'ccm-tools'); ?></span><span class="ccm-kv__v"><?php echo !empty($runtime['supports_keepttl']) ? esc_html__('Yes', 'ccm-tools') : esc_html__('No', 'ccm-tools'); ?></span></div>
                    <div><span class="ccm-kv__k"><?php _e('Key prefix', 'ccm-tools'); ?></span><span class="ccm-kv__v"><code><?php echo esc_html($runtime['key_salt'] ?? ''); ?></code></span></div>
                    <div><span class="ccm-kv__k"><?php _e('Global groups', 'ccm-tools'); ?></span><span class="ccm-kv__v"><?php echo esc_html(implode(', ', array_keys($runtime['global_groups'] ?? array()))); ?></span></div>
                    <div>
                        <span class="ccm-kv__k"><?php _e('Hits / misses', 'ccm-tools'); ?></span>
                        <span class="ccm-kv__v">
                            <?php
                            $rt_hits   = intval($runtime['hits'] ?? 0);
                            $rt_misses = intval($runtime['misses'] ?? 0);
                            $rt_total  = $rt_hits + $rt_misses;
                            echo esc_html(number_format_i18n($rt_hits)) . ' / ' . esc_html(number_format_i18n($rt_misses));
                            if ($rt_total > 0) :
                            ?>
                                <small><?php printf(esc_html__('%s%% this page load', 'ccm-tools'), round(($rt_hits / $rt_total) * 100, 1)); ?></small>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div><span class="ccm-kv__k"><?php _e('Redis calls', 'ccm-tools'); ?></span><span class="ccm-kv__v"><?php echo intval($runtime['redis_calls'] ?? 0); ?></span></div>
                    <div><span class="ccm-kv__k"><?php _e('Redis time', 'ccm-tools'); ?></span><span class="ccm-kv__v"><?php echo esc_html(round(floatval($runtime['redis_time'] ?? 0) * 1000, 2)); ?>ms</span></div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($extension_available) : ?>

            <form id="redis-settings-form">

                <div class="ccm-section">
                    <div>
                        <span class="ccm-section__eyebrow"><?php printf(esc_html__('%1$d of %2$d locked in wp-config.php', 'ccm-tools'), $count_locked($constants_connection), count($constants_connection)); ?></span>
                        <h2><?php _e('Connection', 'ccm-tools'); ?></h2>
                        <p><?php _e('Where Redis is and how to reach it. Anything defined as a constant in wp-config.php always wins over what is saved here.', 'ccm-tools'); ?></p>
                    </div>
                </div>

                <div class="ccm-grid-2">
                    <div class="ccm-optfield">
                        <label for="redis-scheme"><?php _e('Connection type', 'ccm-tools'); ?></label>
                        <select id="redis-scheme" name="scheme" class="ccm-input">
                            <option value="tcp" <?php selected($settings['scheme'], 'tcp'); ?>><?php _e('TCP/IP', 'ccm-tools'); ?></option>
                            <option value="unix" <?php selected($settings['scheme'], 'unix'); ?>><?php _e('Unix socket', 'ccm-tools'); ?></option>
                            <option value="tls" <?php selected($settings['scheme'], 'tls'); ?>><?php _e('TLS/SSL', 'ccm-tools'); ?></option>
                        </select>
                    </div>
                    <div class="ccm-optfield" id="redis-database-field">
                        <label for="redis-database"><?php _e('Database index', 'ccm-tools'); ?></label>
                        <input type="number" id="redis-database" name="database" class="ccm-input" value="<?php echo esc_attr($settings['database']); ?>" min="0" max="15">
                        <span class="ccm-optfield__hint"><?php _e('0–15', 'ccm-tools'); ?></span>
                    </div>
                </div>

                <div class="ccm-row" id="tcp-settings" style="align-items: flex-start; margin-top: var(--ccm-space-md);<?php echo $is_unix ? ' display:none;' : ''; ?>">
                    <div class="ccm-optfield" style="flex: 2 1 16rem;">
                        <label for="redis-host"><?php _e('Host', 'ccm-tools'); ?></label>
                        <input type="text" id="redis-host" name="host" class="ccm-input" value="<?php echo esc_attr($settings['host']); ?>" placeholder="127.0.0.1">
                    </div>
                    <div class="ccm-optfield" style="flex: 1 1 8rem;">
                        <label for="redis-port"><?php _e('Port', 'ccm-tools'); ?></label>
                        <input type="number" id="redis-port" name="port" class="ccm-input" value="<?php echo esc_attr($settings['port']); ?>" placeholder="6379" min="1" max="65535">
                    </div>
                </div>

                <div id="unix-settings" style="margin-top: var(--ccm-space-md);<?php echo $is_unix ? '' : ' display:none;'; ?>">
                    <div class="ccm-optfield" style="max-width: none;">
                        <label for="redis-path"><?php _e('Socket path', 'ccm-tools'); ?></label>
                        <input type="text" id="redis-path" name="path" class="ccm-input" value="<?php echo esc_attr($settings['path']); ?>" placeholder="/var/run/redis/redis.sock">
                    </div>
                </div>

                <div class="ccm-optfield" style="max-width: none; margin-top: var(--ccm-space-md);">
                    <label for="redis-password"><?php _e('Password', 'ccm-tools'); ?></label>
                    <input type="password" id="redis-password" name="password" class="ccm-input" value="<?php echo esc_attr($settings['password']); ?>" placeholder="<?php esc_attr_e('Leave empty if not required', 'ccm-tools'); ?>" autocomplete="new-password">
                </div>

                <div class="ccm-optfield" style="max-width: none; margin-top: var(--ccm-space-md);">
                    <label for="redis-username"><?php _e('Username (Redis 6.0+ ACL)', 'ccm-tools'); ?></label>
                    <input type="text" id="redis-username" name="username" class="ccm-input" value="<?php echo esc_attr($settings['username'] ?? ''); ?>" placeholder="<?php esc_attr_e('Leave empty for the default user', 'ccm-tools'); ?>">
                    <span class="ccm-optfield__hint"><?php _e('Only needed if the Redis server uses ACL authentication.', 'ccm-tools'); ?></span>
                </div>

                <div class="ccm-section">
                    <div>
                        <span class="ccm-section__eyebrow"><?php printf(esc_html__('%1$d of %2$d locked in wp-config.php', 'ccm-tools'), $count_locked($constants_cache), count($constants_cache)); ?></span>
                        <h2><?php _e('Cache behaviour', 'ccm-tools'); ?></h2>
                        <p><?php _e('How long entries live, and how this site keeps its cache separate from anyone else on the same Redis server.', 'ccm-tools'); ?></p>
                    </div>
                </div>

                <div class="ccm-optfield" style="max-width: none;">
                    <label for="redis-key-salt"><?php _e('Key prefix', 'ccm-tools'); ?></label>
                    <span class="ccm-row" style="flex-wrap: nowrap;">
                        <input type="text" id="redis-key-salt" name="key_salt" class="ccm-input ccm-mono" style="flex: 1;" value="<?php echo esc_attr($settings['key_salt']); ?>" placeholder="<?php echo esc_attr(parse_url(site_url(), PHP_URL_HOST) . '_'); ?>">
                        <button type="button" id="redis-generate-salt" class="ccm-button ccm-button-secondary ccm-button-small"><?php _e('Generate', 'ccm-tools'); ?></button>
                    </span>
                    <span class="ccm-optfield__hint"><?php _e('Generated automatically so this site never reads or writes another site\'s cache when several installs share one Redis server — an empty prefix is exactly how that collision happens. Only change it to deliberately share cache with another install.', 'ccm-tools'); ?></span>
                </div>

                <div class="ccm-optfield" style="margin-top: var(--ccm-space-md);">
                    <label for="redis-max-ttl"><?php _e('Max TTL', 'ccm-tools'); ?></label>
                    <span class="ccm-optfield__inline">
                        <input type="number" id="redis-max-ttl" name="max_ttl" class="ccm-input" value="<?php echo esc_attr($settings['max_ttl']); ?>" min="0" placeholder="604800">
                        <span class="ccm-optfield__suffix"><?php _e('sec (0 = no limit)', 'ccm-tools'); ?></span>
                    </span>
                </div>

                <div class="ccm-opts" style="margin-top: var(--ccm-space-md);">
                    <div class="ccm-opt<?php echo $settings['selective_flush'] ? ' is-on' : ''; ?>">
                        <div class="ccm-opt__main">
                            <div class="ccm-opt__text">
                                <span class="ccm-opt__label"><?php _e('Selective flush', 'ccm-tools'); ?></span>
                                <p class="ccm-opt__desc"><?php _e('Flush Cache clears only this site\'s own keys, never the whole Redis database. Turn this off only if you deliberately want a flush here to clear every site sharing this server.', 'ccm-tools'); ?></p>
                            </div>
                            <label class="ccm-toggle">
                                <input type="checkbox" name="selective_flush" <?php checked($settings['selective_flush']); ?>>
                                <span class="ccm-toggle-slider"></span>
                            </label>
                        </div>
                    </div>
                </div>

                <?php if ($has_woocommerce) :
                    $wc_on = (int) !empty($settings['wc_cache_cart_fragments'])
                           + (int) !empty($settings['wc_persistent_cart'])
                           + (int) (empty($settings['wc_session_cache']) || $settings['wc_session_cache']);
                    ?>
                <div class="ccm-section">
                    <div>
                        <span class="ccm-section__eyebrow"><?php printf(esc_html__('%1$d of %2$d on', 'ccm-tools'), $wc_on, 3); ?></span>
                        <h2><?php _e('WooCommerce', 'ccm-tools'); ?></h2>
                        <p><?php _e('Cart, session and product data are the highest-traffic reads on a store, so caching them in Redis is what cuts database load at checkout.', 'ccm-tools'); ?></p>
                    </div>
                </div>

                <div class="ccm-grid-2">
                    <div class="ccm-optfield">
                        <label for="wc-product-cache-ttl"><?php _e('Product cache TTL', 'ccm-tools'); ?></label>
                        <span class="ccm-optfield__inline">
                            <input type="number" id="wc-product-cache-ttl" name="wc_product_cache_ttl" class="ccm-input" value="<?php echo esc_attr(!empty($settings['wc_product_cache_ttl']) ? $settings['wc_product_cache_ttl'] : 3600); ?>" min="0">
                            <span class="ccm-optfield__suffix"><?php _e('sec', 'ccm-tools'); ?></span>
                        </span>
                        <span class="ccm-optfield__hint"><?php _e('3600 = one hour.', 'ccm-tools'); ?></span>
                    </div>
                    <div class="ccm-optfield">
                        <label for="wc-session-cache-ttl"><?php _e('Session cache TTL', 'ccm-tools'); ?></label>
                        <span class="ccm-optfield__inline">
                            <input type="number" id="wc-session-cache-ttl" name="wc_session_cache_ttl" class="ccm-input" value="<?php echo esc_attr(!empty($settings['wc_session_cache_ttl']) ? $settings['wc_session_cache_ttl'] : 172800); ?>" min="0">
                            <span class="ccm-optfield__suffix"><?php _e('sec', 'ccm-tools'); ?></span>
                        </span>
                        <span class="ccm-optfield__hint"><?php _e('172800 = 48 hours, matching WooCommerce\'s own default.', 'ccm-tools'); ?></span>
                    </div>
                </div>

                <div class="ccm-opts" style="margin-top: var(--ccm-space-md);">
                    <div class="ccm-opt<?php echo !empty($settings['wc_cache_cart_fragments']) ? ' is-on' : ''; ?>">
                        <div class="ccm-opt__main">
                            <div class="ccm-opt__text">
                                <span class="ccm-opt__label"><?php _e('Cache cart fragments', 'ccm-tools'); ?></span>
                                <p class="ccm-opt__desc"><?php _e('Speeds up the AJAX cart update fired on every add-to-cart click.', 'ccm-tools'); ?></p>
                            </div>
                            <label class="ccm-toggle">
                                <input type="checkbox" name="wc_cache_cart_fragments" <?php checked(!empty($settings['wc_cache_cart_fragments'])); ?>>
                                <span class="ccm-toggle-slider"></span>
                            </label>
                        </div>
                    </div>
                    <div class="ccm-opt<?php echo !empty($settings['wc_persistent_cart']) ? ' is-on' : ''; ?>">
                        <div class="ccm-opt__main">
                            <div class="ccm-opt__text">
                                <span class="ccm-opt__label"><?php _e('Persistent cart in Redis', 'ccm-tools'); ?></span>
                                <p class="ccm-opt__desc"><?php _e('Stores a logged-in shopper\'s cart in Redis instead of user meta, for a faster checkout.', 'ccm-tools'); ?></p>
                            </div>
                            <label class="ccm-toggle">
                                <input type="checkbox" name="wc_persistent_cart" <?php checked(!empty($settings['wc_persistent_cart'])); ?>>
                                <span class="ccm-toggle-slider"></span>
                            </label>
                        </div>
                    </div>
                    <div class="ccm-opt<?php echo (empty($settings['wc_session_cache']) || $settings['wc_session_cache']) ? ' is-on' : ''; ?>">
                        <div class="ccm-opt__main">
                            <div class="ccm-opt__text">
                                <span class="ccm-opt__label"><?php _e('Session data caching', 'ccm-tools'); ?></span>
                                <p class="ccm-opt__desc"><?php _e('On by default. Caches WooCommerce session data in Redis rather than the database.', 'ccm-tools'); ?></p>
                            </div>
                            <label class="ccm-toggle">
                                <input type="checkbox" name="wc_session_cache" <?php checked(empty($settings['wc_session_cache']) || $settings['wc_session_cache']); ?>>
                                <span class="ccm-toggle-slider"></span>
                            </label>
                        </div>
                    </div>
                </div>
                <?php endif; // WooCommerce ?>

                <div class="ccm-section">
                    <div>
                        <span class="ccm-section__eyebrow"><?php printf(esc_html__('%1$d of %2$d locked in wp-config.php', 'ccm-tools'), $count_locked($constants_advanced), count($constants_advanced)); ?></span>
                        <h2><?php _e('Advanced', 'ccm-tools'); ?></h2>
                        <p><?php _e('Timeouts and encoding — including the one combination that has taken down production sites before.', 'ccm-tools'); ?></p>
                    </div>
                </div>

                <div class="ccm-grid-2">
                    <div class="ccm-optfield">
                        <label for="redis-timeout"><?php _e('Connection timeout', 'ccm-tools'); ?></label>
                        <span class="ccm-optfield__inline">
                            <input type="number" id="redis-timeout" name="timeout" class="ccm-input" value="<?php echo esc_attr($settings['timeout']); ?>" min="0" step="0.1">
                            <span class="ccm-optfield__suffix"><?php _e('sec', 'ccm-tools'); ?></span>
                        </span>
                    </div>
                    <div class="ccm-optfield">
                        <label for="redis-read-timeout"><?php _e('Read timeout', 'ccm-tools'); ?></label>
                        <span class="ccm-optfield__inline">
                            <input type="number" id="redis-read-timeout" name="read_timeout" class="ccm-input" value="<?php echo esc_attr($settings['read_timeout']); ?>" min="0" step="0.1">
                            <span class="ccm-optfield__suffix"><?php _e('sec', 'ccm-tools'); ?></span>
                        </span>
                    </div>
                </div>

                <div class="ccm-grid-2" style="margin-top: var(--ccm-space-md);">
                    <div class="ccm-optfield">
                        <label for="redis-serializer"><?php _e('Serializer', 'ccm-tools'); ?></label>
                        <select id="redis-serializer" name="serializer" class="ccm-input">
                            <option value="php" <?php selected($settings['serializer'], 'php'); ?>><?php _e('PHP', 'ccm-tools'); ?><?php echo extension_loaded('igbinary') ? ' (' . __('fallback', 'ccm-tools') . ')' : ' (' . __('default', 'ccm-tools') . ')'; ?></option>
                            <option value="igbinary" <?php selected($settings['serializer'], 'igbinary'); ?> <?php disabled(!extension_loaded('igbinary')); ?>><?php _e('igbinary', 'ccm-tools'); ?><?php echo !extension_loaded('igbinary') ? ' (' . __('not installed', 'ccm-tools') . ')' : ' (' . __('default — faster, smaller', 'ccm-tools') . ')'; ?></option>
                            <option value="msgpack" <?php selected($settings['serializer'], 'msgpack'); ?> <?php disabled(!extension_loaded('msgpack')); ?>><?php _e('msgpack', 'ccm-tools'); ?><?php echo !extension_loaded('msgpack') ? ' (' . __('not installed', 'ccm-tools') . ')' : ' (' . __('compact binary', 'ccm-tools') . ')'; ?></option>
                        </select>
                        <span class="ccm-optfield__hint"><?php _e('Changing this flushes the cache once, automatically, so nothing tries to decode a value with the wrong serializer.', 'ccm-tools'); ?></span>
                    </div>
                    <div class="ccm-optfield">
                        <label for="redis-compression"><?php _e('Compression', 'ccm-tools'); ?></label>
                        <select id="redis-compression" name="compression" class="ccm-input">
                            <option value="none" <?php selected($settings['compression'], 'none'); ?>><?php _e('None (default)', 'ccm-tools'); ?></option>
                            <option value="lzf" <?php selected($settings['compression'], 'lzf'); ?> <?php disabled(!$has_lzf); ?>><?php _e('LZF', 'ccm-tools'); ?><?php echo !$has_lzf ? ' (' . __('not available', 'ccm-tools') . ')' : ' (' . __('fast', 'ccm-tools') . ')'; ?></option>
                            <option value="lz4" <?php selected($settings['compression'], 'lz4'); ?> <?php disabled(!$has_lz4); ?>><?php _e('LZ4', 'ccm-tools'); ?><?php echo !$has_lz4 ? ' (' . __('not available', 'ccm-tools') . ')' : ' (' . __('very fast — see warning', 'ccm-tools') . ')'; ?></option>
                            <option value="zstd" <?php selected($settings['compression'], 'zstd'); ?> <?php disabled(!$has_zstd); ?>><?php _e('Zstandard', 'ccm-tools'); ?><?php echo !$has_zstd ? ' (' . __('not available', 'ccm-tools') . ')' : ' (' . __('best ratio', 'ccm-tools') . ')'; ?></option>
                        </select>
                        <span class="ccm-optfield__hint"><?php _e('LZ4 with the igbinary serializer has caused production sites to hit "Allowed memory size exhausted" fatals when a compressed value failed to round-trip. Leave this on None with igbinary unless LZ4 or Zstandard has been tested on staging first.', 'ccm-tools'); ?></span>
                    </div>
                </div>

                <div class="ccm-opts" style="margin-top: var(--ccm-space-md);">
                    <div class="ccm-opt<?php echo !empty($settings['async_flush']) ? ' is-on' : ''; ?>">
                        <div class="ccm-opt__main">
                            <div class="ccm-opt__text">
                                <span class="ccm-opt__label"><?php _e('Async flush', 'ccm-tools'); ?></span>
                                <p class="ccm-opt__desc"><?php _e('Uses non-blocking UNLINK and FLUSHDB ASYNC (Redis 4.0+) so a flush does not stall other requests while it runs.', 'ccm-tools'); ?></p>
                            </div>
                            <label class="ccm-toggle">
                                <input type="checkbox" name="async_flush" <?php checked(!empty($settings['async_flush'])); ?>>
                                <span class="ccm-toggle-slider"></span>
                            </label>
                        </div>
                    </div>
                    <div class="ccm-opt<?php echo empty($settings['disable_comment']) ? ' is-on' : ''; ?>">
                        <div class="ccm-opt__main">
                            <div class="ccm-opt__text">
                                <span class="ccm-opt__label"><?php _e('HTML footnote', 'ccm-tools'); ?></span>
                                <p class="ccm-opt__desc"><?php _e('Appends an HTML comment with cache hit/miss counts and Redis timing to page output, for debugging.', 'ccm-tools'); ?></p>
                            </div>
                            <label class="ccm-toggle">
                                <input type="checkbox" name="disable_comment" <?php checked(empty($settings['disable_comment'])); ?>>
                                <span class="ccm-toggle-slider"></span>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="ccm-row" style="margin-top: var(--ccm-space-xl);">
                    <button type="submit" id="redis-save-settings" class="ccm-button ccm-button-primary"><?php _e('Save Settings', 'ccm-tools'); ?></button>
                    <button type="button" id="add-to-wp-config" class="ccm-button ccm-button-secondary"><?php _e('Add to wp-config.php', 'ccm-tools'); ?></button>
                </div>

            </form>

            <?php
            $config_items = array(
                'WP_REDIS_HOST' => array('value' => $settings['host'], 'defined' => defined('WP_REDIS_HOST')),
                'WP_REDIS_PORT' => array('value' => $settings['port'], 'defined' => defined('WP_REDIS_PORT')),
                'WP_REDIS_PATH' => array('value' => $settings['path'], 'defined' => defined('WP_REDIS_PATH')),
                'WP_REDIS_SCHEME' => array('value' => $settings['scheme'], 'defined' => defined('WP_REDIS_SCHEME')),
                'WP_REDIS_DATABASE' => array('value' => $settings['database'], 'defined' => defined('WP_REDIS_DATABASE')),
                'WP_REDIS_USERNAME' => array('value' => !empty($settings['username']) ? $settings['username'] : '', 'defined' => defined('WP_REDIS_USERNAME')),
                'WP_REDIS_PASSWORD' => array('value' => !empty($settings['password']) ? '******' : '', 'defined' => defined('WP_REDIS_PASSWORD')),
                'WP_REDIS_TIMEOUT' => array('value' => $settings['timeout'], 'defined' => defined('WP_REDIS_TIMEOUT')),
                'WP_REDIS_MAXTTL' => array('value' => $settings['max_ttl'], 'defined' => defined('WP_REDIS_MAXTTL')),
                'WP_CACHE_KEY_SALT' => array('value' => $settings['key_salt'], 'defined' => defined('WP_CACHE_KEY_SALT')),
                'WP_REDIS_SELECTIVE_FLUSH' => array('value' => $settings['selective_flush'] ? 'true' : 'false', 'defined' => defined('WP_REDIS_SELECTIVE_FLUSH')),
            );

            // Standard for everyone now.
            $config_items['WP_REDIS_SERIALIZER'] = array('value' => $settings['serializer'], 'defined' => defined('WP_REDIS_SERIALIZER'));
            $config_items['WP_REDIS_COMPRESSION'] = array('value' => $settings['compression'], 'defined' => defined('WP_REDIS_COMPRESSION'));
            $config_items['WP_REDIS_ASYNC_FLUSH'] = array('value' => !empty($settings['async_flush']) ? 'true' : 'false', 'defined' => defined('WP_REDIS_ASYNC_FLUSH'));
            ?>

            <details class="ccm-disclose" style="margin-top: var(--ccm-space-lg);">
                <summary><?php _e('Active configuration', 'ccm-tools'); ?></summary>
                <div class="ccm-disclose__body ccm-panel__body--flush">
                    <div class="ccm-table-wrap">
                        <table class="ccm-table" id="redis-active-config-table">
                            <thead>
                                <tr>
                                    <th><?php _e('Setting', 'ccm-tools'); ?></th>
                                    <th><?php _e('Value', 'ccm-tools'); ?></th>
                                    <th><?php _e('Source', 'ccm-tools'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($config_items as $constant => $item) :
                                    if (empty($item['value']) && !$item['defined']) { continue; }
                                ?>
                                <tr>
                                    <td><code><?php echo esc_html($constant); ?></code></td>
                                    <td><?php echo esc_html($item['value']); ?></td>
                                    <td>
                                        <?php if ($item['defined']) : ?>
                                            <span class="ccm-badge ccm-badge-info"><?php _e('wp-config.php', 'ccm-tools'); ?></span>
                                        <?php else : ?>
                                            <span class="ccm-badge"><?php _e('Plugin settings', 'ccm-tools'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </details>

            <div class="ccm-savebar" data-ccm-savebar data-savebar-target="#redis-save-settings">
                <span class="ccm-savebar__dot" aria-hidden="true"></span>
                <span class="ccm-savebar__msg"><?php _e('No unsaved changes', 'ccm-tools'); ?></span>
                <button type="button" class="ccm-button ccm-button-secondary ccm-button-small" data-savebar-discard>
                    <?php _e('Discard', 'ccm-tools'); ?>
                </button>
                <button type="button" class="ccm-button ccm-button-primary" data-savebar-save>
                    <?php _e('Save Settings', 'ccm-tools'); ?>
                </button>
            </div>

            <?php endif; // $extension_available (Configuration) ?>

            <?php if (!$extension_available || !$connection['connected']) : ?>
            <!-- Installing Redis -->
            <details class="ccm-disclose" style="margin-top: var(--ccm-space-lg);">
                <summary><?php _e('Installing Redis', 'ccm-tools'); ?></summary>
                <div class="ccm-disclose__body">
                    <?php if (!$extension_available) : ?>
                        <p class="ccm-text-muted" style="font-size: var(--ccm-text-sm); margin: 0 0 var(--ccm-space-sm);">
                            <?php _e('The Redis PHP extension has to be installed on the server before object caching can be used here.', 'ccm-tools'); ?>
                        </p>
                        <div class="ccm-stack--sm">
                            <div>
                                <strong style="font-size: var(--ccm-text-sm);"><?php _e('Ubuntu/Debian', 'ccm-tools'); ?></strong>
                                <pre class="ccm-mono" style="margin: 0.3rem 0 0; padding: var(--ccm-space-sm); background: var(--ccm-bg-secondary); border-radius: var(--ccm-radius); overflow-x: auto;">sudo apt-get install php-redis
sudo systemctl restart php-fpm</pre>
                            </div>
                            <div>
                                <strong style="font-size: var(--ccm-text-sm);"><?php _e('CentOS/RHEL', 'ccm-tools'); ?></strong>
                                <pre class="ccm-mono" style="margin: 0.3rem 0 0; padding: var(--ccm-space-sm); background: var(--ccm-bg-secondary); border-radius: var(--ccm-radius); overflow-x: auto;">sudo yum install php-pecl-redis
sudo systemctl restart php-fpm</pre>
                            </div>
                            <p class="ccm-text-muted" style="font-size: var(--ccm-text-sm); margin: 0;">
                                <?php _e('cPanel/WHM: WHM → Software → Module Installers → PHP PECL → install "redis". On managed hosting, ask the host to enable the Redis PHP extension.', 'ccm-tools'); ?>
                            </p>
                        </div>
                    <?php else : ?>
                        <p class="ccm-text-muted" style="font-size: var(--ccm-text-sm); margin: 0;">
                            <?php echo !empty($connection['error']) ? esc_html($connection['error']) . ' ' : ''; ?>
                            <?php _e('The extension is installed, but no Redis server answered at the address configured above. Check the host and port are correct and that the Redis service is running.', 'ccm-tools'); ?>
                        </p>
                    <?php endif; ?>
                </div>
            </details>
            <?php endif; ?>

        </div>
    </div>
    <?php
}

/**
 * Initialize WooCommerce Redis optimizations
 * 
 * @since 7.8.6
 */
function ccm_tools_redis_woocommerce_init() {
    // Only run if WooCommerce is active
    if (!class_exists('WooCommerce')) {
        return;
    }
    
    // Only run if Redis object cache is enabled
    if (!wp_using_ext_object_cache()) {
        return;
    }
    
    $settings = ccm_tools_redis_get_settings();
    
    // Add WooCommerce cache groups
    wp_cache_add_non_persistent_groups(array('wc_session_id'));
    
    // Cart Fragment Caching
    if (!empty($settings['wc_cache_cart_fragments'])) {
        add_filter('woocommerce_add_to_cart_fragments', 'ccm_tools_redis_cache_cart_fragments', 100);
        add_action('woocommerce_cart_updated', 'ccm_tools_redis_invalidate_cart_cache');
    }
    
    // Persistent Cart in Redis
    if (!empty($settings['wc_persistent_cart'])) {
        add_filter('woocommerce_persistent_cart_enabled', '__return_true');
    }
    
    // Session Cache optimization
    if (!empty($settings['wc_session_cache']) || !isset($settings['wc_session_cache'])) {
        // WooCommerce sessions are already handled by object cache
        // Just ensure the group is properly set
        if (defined('WC_SESSION_CACHE_GROUP')) {
            wp_cache_add_non_persistent_groups(array(WC_SESSION_CACHE_GROUP));
        }
    }
}
add_action('plugins_loaded', 'ccm_tools_redis_woocommerce_init', 20);

/**
 * Cache cart fragments in Redis
 * 
 * @param array $fragments Cart fragments
 * @return array Cart fragments
 */
function ccm_tools_redis_cache_cart_fragments($fragments) {
    if (!is_user_logged_in()) {
        return $fragments;
    }
    
    $settings = ccm_tools_redis_get_settings();
    $ttl = !empty($settings['wc_product_cache_ttl']) ? intval($settings['wc_product_cache_ttl']) : 3600;
    
    $user_id = get_current_user_id();
    $cache_key = 'wc_cart_fragments_' . $user_id;
    
    // Cache the fragments
    wp_cache_set($cache_key, $fragments, 'ccm_wc_cart', $ttl);
    
    return $fragments;
}

/**
 * Invalidate cart cache when cart is updated
 */
function ccm_tools_redis_invalidate_cart_cache() {
    if (!is_user_logged_in()) {
        return;
    }
    
    $user_id = get_current_user_id();
    $cache_key = 'wc_cart_fragments_' . $user_id;
    
    wp_cache_delete($cache_key, 'ccm_wc_cart');
}

/**
 * Get cached product data with TTL from settings
 * 
 * @param int $product_id Product ID
 * @return mixed Product data or false
 */
function ccm_tools_redis_get_product_cache($product_id) {
    return wp_cache_get('wc_product_' . $product_id, 'ccm_wc_products');
}

/**
 * Set product cache with TTL from settings
 * 
 * @param int $product_id Product ID
 * @param mixed $data Product data
 */
function ccm_tools_redis_set_product_cache($product_id, $data) {
    $settings = ccm_tools_redis_get_settings();
    $ttl = !empty($settings['wc_product_cache_ttl']) ? intval($settings['wc_product_cache_ttl']) : 3600;
    
    wp_cache_set('wc_product_' . $product_id, $data, 'ccm_wc_products', $ttl);
}

/* ────────────────────────────────────────────────────────────────
 *  Transient Cleanup (on drop-in enable)
 * ──────────────────────────────────────────────────────────────── */

/**
 * Remove database-stored transients since Redis will handle them.
 * Called when the drop-in is first installed.
 */
function ccm_tools_redis_cleanup_transients() {
    global $wpdb;

    // Only delete expired transients (where timeout has passed)
    $now = time();
    $deleted = $wpdb->query(
        $wpdb->prepare(
            "DELETE a, b FROM {$wpdb->options} a
             INNER JOIN {$wpdb->options} b ON b.option_name = CONCAT('_transient_timeout_', SUBSTRING(a.option_name, 12))
             WHERE a.option_name LIKE %s
             AND b.option_value < %d",
            $wpdb->esc_like('_transient_') . '%',
            $now
        )
    );

    // Also clean expired site transients
    $deleted_site = $wpdb->query(
        $wpdb->prepare(
            "DELETE a, b FROM {$wpdb->options} a
             INNER JOIN {$wpdb->options} b ON b.option_name = CONCAT('_site_transient_timeout_', SUBSTRING(a.option_name, 17))
             WHERE a.option_name LIKE %s
             AND b.option_value < %d",
            $wpdb->esc_like('_site_transient_') . '%',
            $now
        )
    );

    $total = ($deleted ?: 0) + ($deleted_site ?: 0);

    if ($total > 0) {
        error_log('CCM Redis: Cleaned up ' . $total . ' expired database transients.');
    }

    return $total;
}

/* ────────────────────────────────────────────────────────────────
 *  Drop-in Version Check
 * ──────────────────────────────────────────────────────────────── */

/**
 * Compare installed drop-in version with bundled version.
 *
 * @return array  'needs_update' bool, 'installed' string, 'bundled' string
 */
function ccm_tools_redis_dropin_version_check() {
    $result = array(
        'needs_update' => false,
        'installed'    => '',
        'bundled'      => '',
    );

    $dropin_path  = WP_CONTENT_DIR . '/object-cache.php';
    $bundled_path = CCM_HELPER_ROOT_PATH . 'assets/object-cache.php';

    // Read installed drop-in version
    if (file_exists($dropin_path)) {
        $content = @file_get_contents($dropin_path);
        if ($content && preg_match('/@version\s+([0-9.]+)/i', $content, $m)) {
            $result['installed'] = $m[1];
        }
    }

    // Read bundled version
    if (file_exists($bundled_path)) {
        $content = @file_get_contents($bundled_path);
        if ($content && preg_match('/@version\s+([0-9.]+)/i', $content, $m)) {
            $result['bundled'] = $m[1];
        }
    }

    if ($result['installed'] && $result['bundled']
        && version_compare($result['installed'], $result['bundled'], '<')
    ) {
        $result['needs_update'] = true;
    }

    return $result;
}

/* ────────────────────────────────────────────────────────────────
 *  WordPress Site Health Integration
 * ──────────────────────────────────────────────────────────────── */

/**
 * Register Site Health tests.
 */
function ccm_tools_redis_site_health_tests($tests) {
    if (!ccm_tools_redis_extension_available()) {
        return $tests;
    }

    $tests['direct']['ccm_redis_connection'] = array(
        'label' => __('Redis Connection', 'ccm-tools'),
        'test'  => 'ccm_tools_redis_site_health_connection',
    );

    $tests['direct']['ccm_redis_dropin'] = array(
        'label' => __('Redis Drop-In', 'ccm-tools'),
        'test'  => 'ccm_tools_redis_site_health_dropin',
    );

    $tests['direct']['ccm_redis_eviction'] = array(
        'label' => __('Redis Eviction Policy', 'ccm-tools'),
        'test'  => 'ccm_tools_redis_site_health_eviction',
    );

    return $tests;
}
add_filter('site_status_tests', 'ccm_tools_redis_site_health_tests');

/**
 * Site Health: Redis connection test.
 */
function ccm_tools_redis_site_health_connection() {
    $connection = ccm_tools_redis_check_connection();

    if ($connection['connected']) {
        return array(
            'label'       => __('Redis is reachable', 'ccm-tools'),
            'status'      => 'good',
            'badge'       => array('label' => __('Performance', 'ccm-tools'), 'color' => 'blue'),
            'description' => sprintf(
                '<p>%s</p>',
                sprintf(
                    __('Connected to Redis %s on %s. Uptime: %s.', 'ccm-tools'),
                    esc_html($connection['version']),
                    esc_html($connection['host'] . ($connection['port'] ? ':' . $connection['port'] : '')),
                    esc_html(ccm_tools_redis_format_uptime($connection['uptime']))
                )
            ),
            'actions'     => '',
            'test'        => 'ccm_redis_connection',
        );
    }

    return array(
        'label'       => __('Redis is not reachable', 'ccm-tools'),
        'status'      => 'critical',
        'badge'       => array('label' => __('Performance', 'ccm-tools'), 'color' => 'red'),
        'description' => sprintf('<p>%s</p>', esc_html($connection['error'])),
        'actions'     => '',
        'test'        => 'ccm_redis_connection',
    );
}

/**
 * Site Health: Drop-in status test.
 */
function ccm_tools_redis_site_health_dropin() {
    $dropin  = ccm_tools_redis_dropin_status();
    $version = ccm_tools_redis_dropin_version_check();

    if ($dropin['is_ccm'] && !$version['needs_update']) {
        return array(
            'label'       => __('Redis object cache drop-in is installed and up to date', 'ccm-tools'),
            'status'      => 'good',
            'badge'       => array('label' => __('Performance', 'ccm-tools'), 'color' => 'blue'),
            'description' => sprintf('<p>%s</p>', sprintf(__('Drop-in version: %s', 'ccm-tools'), esc_html($version['installed']))),
            'actions'     => '',
            'test'        => 'ccm_redis_dropin',
        );
    }

    if ($dropin['is_ccm'] && $version['needs_update']) {
        return array(
            'label'       => __('Redis object cache drop-in needs an update', 'ccm-tools'),
            'status'      => 'recommended',
            'badge'       => array('label' => __('Performance', 'ccm-tools'), 'color' => 'orange'),
            'description' => sprintf(
                '<p>%s</p>',
                sprintf(
                    __('Installed: %1$s — Available: %2$s. Visit the Redis page to update.', 'ccm-tools'),
                    esc_html($version['installed']),
                    esc_html($version['bundled'])
                )
            ),
            'actions'     => '',
            'test'        => 'ccm_redis_dropin',
        );
    }

    return array(
        'label'       => __('Redis object cache drop-in is not installed', 'ccm-tools'),
        'status'      => 'recommended',
        'badge'       => array('label' => __('Performance', 'ccm-tools'), 'color' => 'orange'),
        'description' => sprintf('<p>%s</p>', __('Enable the CCM Tools Redis object cache for better performance.', 'ccm-tools')),
        'actions'     => '',
        'test'        => 'ccm_redis_dropin',
    );
}

/**
 * Site Health: Redis eviction policy check.
 */
function ccm_tools_redis_site_health_eviction() {
    $settings = ccm_tools_redis_get_settings();
    $policy   = '';

    try {
        $redis = new Redis();
        if ($settings['scheme'] === 'unix' && !empty($settings['path'])) {
            @$redis->connect($settings['path']);
        } elseif ($settings['scheme'] === 'tls') {
            @$redis->connect('tls://' . $settings['host'], $settings['port'], $settings['timeout']);
        } else {
            @$redis->connect($settings['host'], $settings['port'], $settings['timeout']);
        }
        if (!empty($settings['password'])) {
            $username = $settings['username'] ?? '';
            if (defined('WP_REDIS_USERNAME')) $username = WP_REDIS_USERNAME;
            @$redis->auth($username ? [$username, $settings['password']] : $settings['password']);
        }
        if ($settings['database'] > 0) $redis->select($settings['database']);

        $info = $redis->info('server');
        // CONFIG GET may be restricted; try it, fall back to INFO
        try {
            $cfg = $redis->rawCommand('CONFIG', 'GET', 'maxmemory-policy');
            if (is_array($cfg) && isset($cfg[1])) {
                $policy = $cfg[1];
            }
        } catch (Exception $e) {
            // Restricted — parse from INFO memory instead
            $mem_info = $redis->info('memory');
            $policy = $mem_info['maxmemory_policy'] ?? '';
        }
        $redis->close();
    } catch (Exception $e) {
        return array(
            'label'       => __('Could not check Redis eviction policy', 'ccm-tools'),
            'status'      => 'recommended',
            'badge'       => array('label' => __('Performance', 'ccm-tools'), 'color' => 'orange'),
            'description' => sprintf('<p>%s</p>', esc_html($e->getMessage())),
            'actions'     => '',
            'test'        => 'ccm_redis_eviction',
        );
    }

    $safe_policies = array('noeviction', 'volatile-lru', 'volatile-lfu', 'volatile-ttl', 'volatile-random');

    if (in_array($policy, $safe_policies, true)) {
        return array(
            'label'       => sprintf(__('Redis eviction policy is safe (%s)', 'ccm-tools'), $policy),
            'status'      => 'good',
            'badge'       => array('label' => __('Performance', 'ccm-tools'), 'color' => 'blue'),
            'description' => sprintf('<p>%s</p>', __('The current eviction policy protects persistent cache keys.', 'ccm-tools')),
            'actions'     => '',
            'test'        => 'ccm_redis_eviction',
        );
    }

    return array(
        'label'       => sprintf(__('Redis eviction policy may cause data loss (%s)', 'ccm-tools'), $policy ?: 'unknown'),
        'status'      => 'recommended',
        'badge'       => array('label' => __('Performance', 'ccm-tools'), 'color' => 'orange'),
        'description' => sprintf(
            '<p>%s</p>',
            __('allkeys-* eviction policies can evict important cache keys. Consider using "volatile-lru" or "noeviction".', 'ccm-tools')
        ),
        'actions'     => '',
        'test'        => 'ccm_redis_eviction',
    );
}

/**
 * Show admin notice when the Redis drop-in needs an update.
 */
function ccm_tools_redis_dropin_update_notice() {
    if (!current_user_can('manage_options')) return;

    $dropin = ccm_tools_redis_dropin_status();
    if (!$dropin['is_ccm']) return;

    $version = ccm_tools_redis_dropin_version_check();
    if (!$version['needs_update']) return;

    $redis_url = admin_url('admin.php?page=ccm-tools-redis');
    printf(
        '<div class="notice notice-warning"><p><strong>CCM Tools Redis:</strong> %s <a href="%s">%s</a></p></div>',
        sprintf(
            __('The object-cache.php drop-in (v%1$s) is outdated. A new version (v%2$s) is available.', 'ccm-tools'),
            esc_html($version['installed']),
            esc_html($version['bundled'])
        ),
        esc_url($redis_url),
        __('Update now →', 'ccm-tools')
    );
}
add_action('admin_notices', 'ccm_tools_redis_dropin_update_notice');
