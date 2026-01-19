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
        } else {
            $connected = @$redis->connect($host, $port, $timeout);
            $status['host'] = $host;
            $status['port'] = $port;
        }
        
        if (!$connected) {
            $status['error'] = __('Could not connect to Redis server.', 'ccm-tools');
            return $status;
        }
        
        // Authenticate if password is set
        if (!empty($password)) {
            if (!@$redis->auth($password)) {
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
        'password' => '',
        'timeout' => 1.0,
        'read_timeout' => 1.0,
        'retry_interval' => 0,
        'max_ttl' => 0,
        'key_salt' => '',
        'disable_metrics' => true,
        'disable_comment' => true,
        'enabled' => false,
        'selective_flush' => true,
        'compression' => 'none',
        'serializer' => 'php',
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
    
    // Get saved settings from database
    $saved_settings = get_option('ccm_tools_redis_settings', array());
    
    // Merge: defaults < config constants < saved settings
    return array_merge($defaults, $config_settings, $saved_settings);
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
        $sanitized['compression'] = in_array($settings['compression'], array('none', 'lzf', 'zstd', 'lz4')) ? $settings['compression'] : 'none';
    }
    if (isset($settings['serializer'])) {
        $sanitized['serializer'] = in_array($settings['serializer'], array('php', 'igbinary', 'msgpack')) ? $settings['serializer'] : 'php';
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
            
            // Extract version
            if (preg_match('/Version:\s*([0-9.]+)/i', $content, $matches)) {
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
    
    // Update settings to mark as enabled
    $settings = ccm_tools_redis_get_settings();
    $settings['enabled'] = true;
    ccm_tools_redis_save_settings($settings);
    
    // Flush the cache
    if (function_exists('wp_cache_flush')) {
        wp_cache_flush();
    }
    
    $result['success'] = true;
    $result['message'] = __('Redis Object Cache installed and enabled successfully!', 'ccm-tools');
    
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
    
    $result['success'] = true;
    $result['message'] = __('Redis Object Cache disabled and drop-in removed.', 'ccm-tools');
    
    return $result;
}

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
        } else {
            $connected = @$redis->connect($settings['host'], $settings['port'], $settings['timeout']);
        }
        
        if (!$connected) {
            $result['message'] = __('Could not connect to Redis server.', 'ccm-tools');
            return $result;
        }
        
        if (!empty($settings['password'])) {
            if (!@$redis->auth($settings['password'])) {
                $result['message'] = __('Redis authentication failed.', 'ccm-tools');
                return $result;
            }
        }
        
        if ($settings['database'] > 0) {
            $redis->select($settings['database']);
        }
        
        if ($selective && !empty($settings['key_salt'])) {
            // Selective flush - only keys with our prefix
            $pattern = $settings['key_salt'] . '*';
            $keys = $redis->keys($pattern);
            
            if (!empty($keys)) {
                $result['keys_deleted'] = $redis->del($keys);
            }
            
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
            } else {
                $redis->connect($settings['host'], $settings['port'], $settings['timeout']);
            }
            
            if (!empty($settings['password'])) {
                $redis->auth($settings['password']);
            }
            
            if ($settings['database'] > 0) {
                $redis->select($settings['database']);
            }
            
            // Count keys for this site only
            if (!empty($settings['key_salt'])) {
                $pattern = $settings['key_salt'] . '*';
                $keys = $redis->keys($pattern);
                $stats['keys'] = count($keys);
                
                if (count($keys) > 0) {
                    // Calculate memory usage and other stats
                    // Sample up to 100 keys to estimate
                    $sample_size = min(100, count($keys));
                    $sample_keys = array_slice($keys, 0, $sample_size);
                    
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
                        $estimated_total = $avg_size * count($keys);
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
    
    // Default configuration
    $defaults = array(
        'WP_REDIS_HOST' => '127.0.0.1',
        'WP_REDIS_PORT' => 6379,
        'WP_REDIS_MAXTTL' => 3600,
        'WP_REDIS_DISABLE_METRICS' => true,
        'WP_REDIS_DISABLE_COMMENT' => true,
    );
    
    // Add site-specific salt
    $site_url = parse_url(site_url(), PHP_URL_HOST);
    if (!empty($site_url)) {
        $defaults['WP_CACHE_KEY_SALT'] = $site_url . '_';
    }
    
    $config = array_merge($defaults, $config);
    
    // Build configuration lines
    $config_lines = array("\n/* CCM Tools Redis Configuration */");
    
    foreach ($config as $constant => $value) {
        // Skip if already defined
        if (preg_match('/define\s*\(\s*[\'"]' . preg_quote($constant, '/') . '[\'"]/i', $config_content)) {
            continue;
        }
        
        if (is_bool($value)) {
            $value_str = $value ? 'true' : 'false';
            $config_lines[] = "define('{$constant}', {$value_str});";
        } elseif (is_int($value)) {
            $config_lines[] = "define('{$constant}', {$value});";
        } else {
            $config_lines[] = "define('{$constant}', '{$value}');";
        }
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
    
    // Create backup with secure filename
    $backup_filename = 'wp-config-backup-' . wp_generate_password(8, false, false) . '-' . date('Y-m-d-His') . '.php';
    $backup_path = dirname($real_config_path) . DIRECTORY_SEPARATOR . $backup_filename;
    
    if (!@copy($real_config_path, $backup_path)) {
        $result['message'] = __('Could not create backup of wp-config.php.', 'ccm-tools');
        return $result;
    }
    
    // Write the new content
    if (@file_put_contents($real_config_path, $config_content) === false) {
        $result['message'] = __('Could not write to wp-config.php file.', 'ccm-tools');
        return $result;
    }
    
    // Clear opcode cache
    if (function_exists('opcache_invalidate')) {
        opcache_invalidate($real_config_path, true);
    }
    
    $result['success'] = true;
    $result['message'] = __('Redis configuration added to wp-config.php successfully.', 'ccm-tools');
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
    $connection = ccm_tools_redis_check_connection();
    $settings = ccm_tools_redis_get_settings();
    $dropin_status = ccm_tools_redis_dropin_status();
    $stats = ccm_tools_redis_get_stats();
    
    ?>
    <div class="wrap ccm-tools">
        <?php 
        if (function_exists('ccm_tools_render_header_nav')) {
            ccm_tools_render_header_nav('ccm-tools-redis');
        }
        ?>
        
        <div class="ccm-content">
            <!-- Status Overview Card -->
            <div class="ccm-card">
                <h2><?php _e('Redis Status', 'ccm-tools'); ?></h2>
                
                <table class="ccm-table">
                    <tr>
                        <th><?php _e('PHP Extension', 'ccm-tools'); ?></th>
                        <td>
                            <?php if ($extension_available): ?>
                                <span class="ccm-success"><?php _e('Installed', 'ccm-tools'); ?></span>
                            <?php else: ?>
                                <span class="ccm-error"><?php _e('Not Installed', 'ccm-tools'); ?></span>
                                <p class="ccm-note"><?php _e('The Redis PHP extension is required. Contact your hosting provider to install it.', 'ccm-tools'); ?></p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    
                    <?php if ($extension_available): ?>
                    <tr>
                        <th><?php _e('Server Connection', 'ccm-tools'); ?></th>
                        <td>
                            <?php if ($connection['connected']): ?>
                                <span class="ccm-success"><?php _e('Connected', 'ccm-tools'); ?></span>
                                <span class="ccm-note">
                                    (<?php echo esc_html($connection['host']); ?><?php echo $connection['port'] ? ':' . esc_html($connection['port']) : ''; ?>)
                                </span>
                            <?php else: ?>
                                <span class="ccm-error"><?php _e('Not Connected', 'ccm-tools'); ?></span>
                                <?php if (!empty($connection['error'])): ?>
                                    <p class="ccm-note ccm-error"><?php echo esc_html($connection['error']); ?></p>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    
                    <?php if ($connection['connected']): ?>
                    <tr>
                        <th><?php _e('Redis Version', 'ccm-tools'); ?></th>
                        <td><?php echo esc_html($connection['version']); ?></td>
                    </tr>
                    <tr>
                        <th><?php _e('Uptime', 'ccm-tools'); ?></th>
                        <td><?php echo esc_html(ccm_tools_redis_format_uptime($connection['uptime'])); ?></td>
                    </tr>
                    <tr>
                        <th><?php _e('Memory Used', 'ccm-tools'); ?></th>
                        <td><?php echo esc_html($connection['memory_used']); ?></td>
                    </tr>
                    <tr>
                        <th><?php _e('Object Cache', 'ccm-tools'); ?></th>
                        <td>
                            <?php if ($dropin_status['is_ccm']): ?>
                                <span class="ccm-success"><?php _e('Enabled', 'ccm-tools'); ?></span>
                                <?php if (!empty($dropin_status['version'])): ?>
                                    <span class="ccm-note">(v<?php echo esc_html($dropin_status['version']); ?>)</span>
                                <?php endif; ?>
                            <?php elseif ($dropin_status['is_other']): ?>
                                <span class="ccm-warning"><?php _e('Other Plugin Active', 'ccm-tools'); ?></span>
                                <span class="ccm-note">(<?php echo esc_html($dropin_status['other_plugin']); ?>)</span>
                            <?php else: ?>
                                <span class="ccm-warning"><?php _e('Disabled', 'ccm-tools'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php endif; ?>
                </table>
                
                <?php if ($extension_available && $connection['connected']): ?>
                <div class="ccm-button-group" style="margin-top: var(--ccm-space-md);">
                    <?php if (!$dropin_status['is_ccm']): ?>
                        <button type="button" id="redis-enable" class="ccm-button ccm-button-primary" <?php echo $dropin_status['is_other'] ? 'data-force="true"' : ''; ?>>
                            <?php echo $dropin_status['is_other'] ? __('Replace & Enable', 'ccm-tools') : __('Enable Object Cache', 'ccm-tools'); ?>
                        </button>
                    <?php else: ?>
                        <button type="button" id="redis-disable" class="ccm-button ccm-button-danger">
                            <?php _e('Disable Object Cache', 'ccm-tools'); ?>
                        </button>
                    <?php endif; ?>
                    
                    <button type="button" id="redis-flush" class="ccm-button">
                        <?php _e('Flush Cache', 'ccm-tools'); ?>
                    </button>
                    
                    <button type="button" id="redis-test" class="ccm-button">
                        <?php _e('Test Connection', 'ccm-tools'); ?>
                    </button>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if ($extension_available && $connection['connected']): ?>
            <!-- Statistics Card -->
            <div class="ccm-card">
                <h2><?php _e('Site Cache Statistics', 'ccm-tools'); ?></h2>
                <p class="ccm-note"><?php printf(__('Showing statistics for keys prefixed with: %s', 'ccm-tools'), '<code>' . esc_html($stats['key_prefix']) . '</code>'); ?></p>
                
                <div class="ccm-stats-grid">
                    <div class="ccm-stat-box">
                        <div class="ccm-stat-value" id="redis-stat-keys"><?php echo number_format_i18n($stats['keys']); ?></div>
                        <div class="ccm-stat-label"><?php _e('Cached Keys', 'ccm-tools'); ?></div>
                    </div>
                    <div class="ccm-stat-box">
                        <div class="ccm-stat-value" id="redis-stat-memory"><?php echo esc_html($stats['memory_used']); ?></div>
                        <div class="ccm-stat-label"><?php _e('Estimated Memory', 'ccm-tools'); ?></div>
                    </div>
                    <div class="ccm-stat-box">
                        <div class="ccm-stat-value" id="redis-stat-groups"><?php echo number_format_i18n($stats['groups']); ?></div>
                        <div class="ccm-stat-label"><?php _e('Cache Groups', 'ccm-tools'); ?></div>
                    </div>
                    <div class="ccm-stat-box">
                        <div class="ccm-stat-value" id="redis-stat-ttl"><?php echo esc_html($stats['avg_ttl']); ?></div>
                        <div class="ccm-stat-label"><?php _e('Avg. TTL', 'ccm-tools'); ?></div>
                    </div>
                </div>
            </div>
            
            <!-- Configuration Card -->
            <div class="ccm-card">
                <h2><?php _e('Configuration', 'ccm-tools'); ?></h2>
                
                <form id="redis-settings-form" class="ccm-form">
                    <div class="ccm-form-section">
                        <h3><?php _e('Connection Settings', 'ccm-tools'); ?></h3>
                        <p class="ccm-note"><?php _e('These settings can also be defined as constants in wp-config.php. Constants take precedence over these settings.', 'ccm-tools'); ?></p>
                        
                        <div class="ccm-form-grid">
                            <div class="ccm-form-field">
                                <label for="redis-scheme"><?php _e('Connection Type', 'ccm-tools'); ?></label>
                                <select id="redis-scheme" name="scheme">
                                    <option value="tcp" <?php selected($settings['scheme'], 'tcp'); ?>><?php _e('TCP/IP', 'ccm-tools'); ?></option>
                                    <option value="unix" <?php selected($settings['scheme'], 'unix'); ?>><?php _e('Unix Socket', 'ccm-tools'); ?></option>
                                    <option value="tls" <?php selected($settings['scheme'], 'tls'); ?>><?php _e('TLS/SSL', 'ccm-tools'); ?></option>
                                </select>
                            </div>
                            
                            <div class="ccm-form-field" id="redis-database-field">
                                <label for="redis-database"><?php _e('Database Index', 'ccm-tools'); ?></label>
                                <input type="number" id="redis-database" name="database" value="<?php echo esc_attr($settings['database']); ?>" min="0" max="15">
                                <span class="ccm-field-hint"><?php _e('0-15', 'ccm-tools'); ?></span>
                            </div>
                        </div>
                        
                        <div class="ccm-form-grid" id="tcp-settings">
                            <div class="ccm-form-field ccm-form-field-wide">
                                <label for="redis-host"><?php _e('Host', 'ccm-tools'); ?></label>
                                <input type="text" id="redis-host" name="host" value="<?php echo esc_attr($settings['host']); ?>" placeholder="127.0.0.1">
                            </div>
                            <div class="ccm-form-field">
                                <label for="redis-port"><?php _e('Port', 'ccm-tools'); ?></label>
                                <input type="number" id="redis-port" name="port" value="<?php echo esc_attr($settings['port']); ?>" placeholder="6379" min="1" max="65535">
                            </div>
                        </div>
                        
                        <div class="ccm-form-grid" id="unix-settings" style="display: none;">
                            <div class="ccm-form-field ccm-form-field-full">
                                <label for="redis-path"><?php _e('Socket Path', 'ccm-tools'); ?></label>
                                <input type="text" id="redis-path" name="path" value="<?php echo esc_attr($settings['path']); ?>" placeholder="/var/run/redis/redis.sock">
                            </div>
                        </div>
                        
                        <div class="ccm-form-grid">
                            <div class="ccm-form-field ccm-form-field-full">
                                <label for="redis-password"><?php _e('Password', 'ccm-tools'); ?></label>
                                <input type="password" id="redis-password" name="password" value="<?php echo esc_attr($settings['password']); ?>" placeholder="<?php esc_attr_e('Leave empty if not required', 'ccm-tools'); ?>">
                            </div>
                        </div>
                    </div>
                    
                    <div class="ccm-form-section">
                        <h3><?php _e('Cache Settings', 'ccm-tools'); ?></h3>
                        
                        <div class="ccm-form-grid">
                            <div class="ccm-form-field ccm-form-field-wide">
                                <label for="redis-key-salt"><?php _e('Key Prefix/Salt', 'ccm-tools'); ?></label>
                                <input type="text" id="redis-key-salt" name="key_salt" value="<?php echo esc_attr($settings['key_salt']); ?>" placeholder="<?php echo esc_attr(parse_url(site_url(), PHP_URL_HOST)); ?>_">
                                <span class="ccm-field-hint"><?php _e('Unique prefix for cache keys (essential for multisite)', 'ccm-tools'); ?></span>
                            </div>
                            <div class="ccm-form-field">
                                <label for="redis-max-ttl"><?php _e('Max TTL (seconds)', 'ccm-tools'); ?></label>
                                <input type="number" id="redis-max-ttl" name="max_ttl" value="<?php echo esc_attr($settings['max_ttl']); ?>" min="0" placeholder="3600">
                                <span class="ccm-field-hint"><?php _e('0 = no limit', 'ccm-tools'); ?></span>
                            </div>
                        </div>
                        
                        <div class="ccm-form-grid">
                            <div class="ccm-form-field ccm-form-field-full">
                                <label class="ccm-checkbox-label">
                                    <input type="checkbox" name="selective_flush" <?php checked($settings['selective_flush']); ?>>
                                    <span class="ccm-checkbox-text">
                                        <strong><?php _e('Selective Flush', 'ccm-tools'); ?></strong>
                                        <span class="ccm-field-hint"><?php _e('Only flush keys for this site when clearing cache (recommended for shared Redis)', 'ccm-tools'); ?></span>
                                    </span>
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <?php if (class_exists('WooCommerce')): ?>
                    <div class="ccm-form-section ccm-form-section-woocommerce">
                        <h3><span class="dashicons dashicons-cart" style="margin-right: 8px;"></span><?php _e('WooCommerce Optimization', 'ccm-tools'); ?></h3>
                        <p class="ccm-note"><?php _e('WooCommerce detected! These settings optimize Redis caching for e-commerce performance.', 'ccm-tools'); ?></p>
                        
                        <div class="ccm-form-grid">
                            <div class="ccm-form-field ccm-form-field-full">
                                <label class="ccm-checkbox-label">
                                    <input type="checkbox" name="wc_cache_cart_fragments" <?php checked(!empty($settings['wc_cache_cart_fragments'])); ?>>
                                    <span class="ccm-checkbox-text">
                                        <strong><?php _e('Cache Cart Fragments', 'ccm-tools'); ?></strong>
                                        <span class="ccm-field-hint"><?php _e('Cache cart fragments for faster AJAX cart updates (reduces database queries)', 'ccm-tools'); ?></span>
                                    </span>
                                </label>
                            </div>
                            <div class="ccm-form-field ccm-form-field-full">
                                <label class="ccm-checkbox-label">
                                    <input type="checkbox" name="wc_persistent_cart" <?php checked(!empty($settings['wc_persistent_cart'])); ?>>
                                    <span class="ccm-checkbox-text">
                                        <strong><?php _e('Persistent Cart in Redis', 'ccm-tools'); ?></strong>
                                        <span class="ccm-field-hint"><?php _e('Store persistent cart data in Redis instead of user meta (faster checkout for logged-in users)', 'ccm-tools'); ?></span>
                                    </span>
                                </label>
                            </div>
                            <div class="ccm-form-field ccm-form-field-full">
                                <label class="ccm-checkbox-label">
                                    <input type="checkbox" name="wc_session_cache" <?php checked(empty($settings['wc_session_cache']) || $settings['wc_session_cache']); ?>>
                                    <span class="ccm-checkbox-text">
                                        <strong><?php _e('Session Data Caching', 'ccm-tools'); ?></strong>
                                        <span class="ccm-field-hint"><?php _e('Cache WooCommerce session data for faster page loads (enabled by default)', 'ccm-tools'); ?></span>
                                    </span>
                                </label>
                            </div>
                        </div>
                        
                        <div class="ccm-form-grid">
                            <div class="ccm-form-field">
                                <label for="wc-product-cache-ttl"><?php _e('Product Cache TTL', 'ccm-tools'); ?></label>
                                <div class="ccm-input-with-suffix">
                                    <input type="number" id="wc-product-cache-ttl" name="wc_product_cache_ttl" value="<?php echo esc_attr(!empty($settings['wc_product_cache_ttl']) ? $settings['wc_product_cache_ttl'] : 3600); ?>" min="0">
                                    <span class="ccm-input-suffix"><?php _e('sec', 'ccm-tools'); ?></span>
                                </div>
                                <span class="ccm-field-hint"><?php _e('Cache duration for product data (3600 = 1 hour)', 'ccm-tools'); ?></span>
                            </div>
                            <div class="ccm-form-field">
                                <label for="wc-session-cache-ttl"><?php _e('Session Cache TTL', 'ccm-tools'); ?></label>
                                <div class="ccm-input-with-suffix">
                                    <input type="number" id="wc-session-cache-ttl" name="wc_session_cache_ttl" value="<?php echo esc_attr(!empty($settings['wc_session_cache_ttl']) ? $settings['wc_session_cache_ttl'] : 172800); ?>" min="0">
                                    <span class="ccm-input-suffix"><?php _e('sec', 'ccm-tools'); ?></span>
                                </div>
                                <span class="ccm-field-hint"><?php _e('Session data TTL (172800 = 48 hours, matches WC default)', 'ccm-tools'); ?></span>
                            </div>
                        </div>
                        
                        <div class="ccm-wc-info">
                            <p><strong><?php _e('How Redis improves WooCommerce:', 'ccm-tools'); ?></strong></p>
                            <ul>
                                <li><?php _e('Product data caching reduces database queries by 50-80%', 'ccm-tools'); ?></li>
                                <li><?php _e('Session caching speeds up cart/checkout by avoiding database reads', 'ccm-tools'); ?></li>
                                <li><?php _e('Cart fragment caching reduces AJAX response times', 'ccm-tools'); ?></li>
                                <li><?php _e('Persistent cart in Redis provides faster access than user meta', 'ccm-tools'); ?></li>
                            </ul>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="ccm-form-section">
                        <h3><?php _e('Advanced Settings', 'ccm-tools'); ?></h3>
                        
                        <div class="ccm-form-grid">
                            <div class="ccm-form-field">
                                <label for="redis-timeout"><?php _e('Connection Timeout', 'ccm-tools'); ?></label>
                                <div class="ccm-input-with-suffix">
                                    <input type="number" id="redis-timeout" name="timeout" value="<?php echo esc_attr($settings['timeout']); ?>" min="0" step="0.1">
                                    <span class="ccm-input-suffix"><?php _e('sec', 'ccm-tools'); ?></span>
                                </div>
                            </div>
                            <div class="ccm-form-field">
                                <label for="redis-read-timeout"><?php _e('Read Timeout', 'ccm-tools'); ?></label>
                                <div class="ccm-input-with-suffix">
                                    <input type="number" id="redis-read-timeout" name="read_timeout" value="<?php echo esc_attr($settings['read_timeout']); ?>" min="0" step="0.1">
                                    <span class="ccm-input-suffix"><?php _e('sec', 'ccm-tools'); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="ccm-form-actions">
                        <button type="submit" class="ccm-button ccm-button-primary"><?php _e('Save Settings', 'ccm-tools'); ?></button>
                        <button type="button" id="add-to-wp-config" class="ccm-button"><?php _e('Add to wp-config.php', 'ccm-tools'); ?></button>
                    </div>
                </form>
            </div>
            
            <!-- Current Configuration Card -->
            <div class="ccm-card">
                <h2><?php _e('Active Configuration', 'ccm-tools'); ?></h2>
                <p class="ccm-note"><?php _e('These are the currently active settings, including any constants defined in wp-config.php.', 'ccm-tools'); ?></p>
                
                <table class="ccm-table ccm-table-striped">
                    <thead>
                        <tr>
                            <th><?php _e('Setting', 'ccm-tools'); ?></th>
                            <th><?php _e('Value', 'ccm-tools'); ?></th>
                            <th><?php _e('Source', 'ccm-tools'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $config_items = array(
                            'WP_REDIS_HOST' => array('value' => $settings['host'], 'defined' => defined('WP_REDIS_HOST')),
                            'WP_REDIS_PORT' => array('value' => $settings['port'], 'defined' => defined('WP_REDIS_PORT')),
                            'WP_REDIS_PATH' => array('value' => $settings['path'], 'defined' => defined('WP_REDIS_PATH')),
                            'WP_REDIS_SCHEME' => array('value' => $settings['scheme'], 'defined' => defined('WP_REDIS_SCHEME')),
                            'WP_REDIS_DATABASE' => array('value' => $settings['database'], 'defined' => defined('WP_REDIS_DATABASE')),
                            'WP_REDIS_PASSWORD' => array('value' => !empty($settings['password']) ? '******' : '', 'defined' => defined('WP_REDIS_PASSWORD')),
                            'WP_REDIS_TIMEOUT' => array('value' => $settings['timeout'], 'defined' => defined('WP_REDIS_TIMEOUT')),
                            'WP_REDIS_MAXTTL' => array('value' => $settings['max_ttl'], 'defined' => defined('WP_REDIS_MAXTTL')),
                            'WP_CACHE_KEY_SALT' => array('value' => $settings['key_salt'], 'defined' => defined('WP_CACHE_KEY_SALT')),
                            'WP_REDIS_SELECTIVE_FLUSH' => array('value' => $settings['selective_flush'] ? 'true' : 'false', 'defined' => defined('WP_REDIS_SELECTIVE_FLUSH')),
                        );
                        
                        foreach ($config_items as $constant => $item):
                            if (empty($item['value']) && !$item['defined']) continue;
                        ?>
                        <tr>
                            <td><code><?php echo esc_html($constant); ?></code></td>
                            <td><?php echo esc_html($item['value']); ?></td>
                            <td>
                                <?php if ($item['defined']): ?>
                                    <span class="ccm-badge ccm-badge-info"><?php _e('wp-config.php', 'ccm-tools'); ?></span>
                                <?php else: ?>
                                    <span class="ccm-badge"><?php _e('Plugin Settings', 'ccm-tools'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            
            <?php if (!$extension_available): ?>
            <!-- Installation Help Card -->
            <div class="ccm-card">
                <h2><?php _e('Installing Redis', 'ccm-tools'); ?></h2>
                
                <div class="ccm-alert ccm-alert-info">
                    <span class="ccm-icon">ℹ</span>
                    <div>
                        <strong><?php _e('Redis PHP Extension Required', 'ccm-tools'); ?></strong>
                        <p><?php _e('To use Redis object caching, the Redis PHP extension must be installed on your server.', 'ccm-tools'); ?></p>
                    </div>
                </div>
                
                <h3><?php _e('Installation Methods', 'ccm-tools'); ?></h3>
                
                <div class="ccm-tabs-content">
                    <h4><?php _e('Ubuntu/Debian', 'ccm-tools'); ?></h4>
                    <pre class="ccm-code-block">sudo apt-get install php-redis
sudo systemctl restart php-fpm</pre>
                    
                    <h4><?php _e('CentOS/RHEL', 'ccm-tools'); ?></h4>
                    <pre class="ccm-code-block">sudo yum install php-pecl-redis
sudo systemctl restart php-fpm</pre>
                    
                    <h4><?php _e('cPanel/WHM', 'ccm-tools'); ?></h4>
                    <p><?php _e('Go to WHM → Software → Module Installers → PHP PECL → Install "redis"', 'ccm-tools'); ?></p>
                    
                    <h4><?php _e('Managed Hosting', 'ccm-tools'); ?></h4>
                    <p><?php _e('Contact your hosting provider to enable the Redis PHP extension.', 'ccm-tools'); ?></p>
                </div>
            </div>
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
