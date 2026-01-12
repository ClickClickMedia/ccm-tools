<?php
// Prevent direct file access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get detailed PHP information
 * 
 * @return array PHP configuration details
 */
function ccm_tools_get_php_info(): array {
    return array(
        'version' => phpversion(),
        'memory_limit' => ini_get('memory_limit'),
        'max_execution_time' => ini_get('max_execution_time'),
        'post_max_size' => ini_get('post_max_size'),
        'upload_max_filesize' => ini_get('upload_max_filesize'),
        'display_errors' => ini_get('display_errors'),
        'max_input_vars' => ini_get('max_input_vars'),
        'allow_url_fopen' => ini_get('allow_url_fopen'),
        'date_timezone' => date_default_timezone_get(),
    );
}

/**
 * Check if Redis is available on the server
 * 
 * @return array Redis status information
 */
function ccm_tools_check_redis_status(): array {
    $status = array(
        'server_available' => false,
        'extension_loaded' => false,
        'connected' => false,
        'version' => '',
    );
    
    // Check if Redis PHP extension is loaded
    if (extension_loaded('redis')) {
        $status['extension_loaded'] = true;
        
        // Try to connect to Redis server
        try {
            $redis = new Redis();
            
            // Default Redis port is 6379, but some hosts use different ports
            // Try common configurations
            $connection_attempts = array(
                array('host' => '127.0.0.1', 'port' => 6379, 'timeout' => 1),
                array('host' => 'localhost', 'port' => 6379, 'timeout' => 1),
                array('host' => '/var/run/redis/redis.sock', 'port' => 0, 'timeout' => 1), // Unix socket
            );
            
            // Additional check for defined constants in WordPress
            if (defined('WP_REDIS_HOST') && defined('WP_REDIS_PORT')) {
                array_unshift($connection_attempts, array(
                    'host' => WP_REDIS_HOST,
                    'port' => WP_REDIS_PORT,
                    'timeout' => 1
                ));
            }
            
            foreach ($connection_attempts as $attempt) {
                try {
                    if ($attempt['port'] === 0) {
                        // Try unix socket connection
                        $result = @$redis->connect($attempt['host']);
                    } else {
                        $result = @$redis->connect($attempt['host'], $attempt['port'], $attempt['timeout']);
                    }
                    
                    if ($result) {
                        $status['connected'] = true;
                        $status['server_available'] = true;
                        $status['version'] = $redis->info()['redis_version'] ?? 'Unknown';
                        $status['connection'] = $attempt;
                        break;
                    }
                } catch (Exception $e) {
                    // Connection failed, try next configuration
                    continue;
                }
            }
        } catch (Exception $e) {
            // Redis extension loaded but server connection failed
            $status['error'] = $e->getMessage();
        }
    }
    
    return $status;
}

/**
 * Check if Redis configuration exists in wp-config.php
 * 
 * @return array Redis configuration status
 */
function ccm_tools_check_redis_configuration(): array {
    $config_status = array(
        'configured' => false,
        'constants' => array(),
        'salt_configured' => false,
        'partially_configured' => false,
        'missing_constants' => array(),
    );
    
    // List of Redis constants to check
    $redis_constants = array(
        'WP_REDIS_HOST',
        'WP_REDIS_PORT',
        'WP_REDIS_PATH',
        'WP_REDIS_SCHEME',
        'WP_REDIS_DATABASE',
        'WP_REDIS_PASSWORD',
        'WP_REDIS_CLIENT',
        'WP_REDIS_TIMEOUT',
        'WP_REDIS_READ_TIMEOUT',
        'WP_REDIS_RETRY_INTERVAL',
        'WP_REDIS_MAXTTL',
        'WP_REDIS_DISABLE_METRICS',
        'WP_REDIS_DISABLE_COMMENT',
        'WP_CACHE_KEY_SALT',
    );
    
    // Required constants for basic Redis functionality - at least one of these must be defined
    $primary_constants = array(
        'WP_REDIS_HOST',
        'WP_REDIS_PATH',
    );
    
    // Additional important Redis constants
    $secondary_constants = array(
        'WP_REDIS_MAXTTL',
        'WP_REDIS_DISABLE_METRICS',
        'WP_REDIS_DISABLE_COMMENT',
        'WP_CACHE_KEY_SALT',
    );
    
    // Check if each constant is defined
    $defined_constants = 0;
    $defined_primary = false;
    $defined_secondary = 0;
    
    foreach ($redis_constants as $constant) {
        if (defined($constant)) {
            $value = constant($constant);
            // Mask password
            if ($constant === 'WP_REDIS_PASSWORD') {
                $value = '******';
            }
            $config_status['constants'][$constant] = $value;
            $defined_constants++;
            
            if (in_array($constant, $primary_constants)) {
                $defined_primary = true;
            }
            
            if (in_array($constant, $secondary_constants)) {
                $defined_secondary++;
            }
            
            if ($constant === 'WP_CACHE_KEY_SALT') {
                $config_status['salt_configured'] = true;
            }
        } else {
            // Track missing constants
            if (in_array($constant, $secondary_constants)) {
                $config_status['missing_constants'][] = $constant;
            }
        }
    }
    
    // Mark as configured if primary constants are defined OR at least 2 secondary constants are defined
    if ($defined_primary || $defined_secondary >= 2) {
        $config_status['configured'] = true;
    } 
    // Mark as partially configured if at least one secondary constant is defined
    else if ($defined_secondary > 0) {
        $config_status['partially_configured'] = true;
    }
    
    // If we didn't find enough constants as defined in PHP, check the wp-config.php file directly
    if (!$config_status['configured']) {
        // Get wp-config.php file path
        $wp_config_path = ABSPATH . 'wp-config.php';
        if (file_exists($wp_config_path) && is_readable($wp_config_path)) {
            $config_content = file_get_contents($wp_config_path);
            
            // Check for Redis configuration in the file
            $has_host = preg_match('/define\s*\(\s*[\'"]WP_REDIS_HOST[\'"]/i', $config_content);
            $has_port = preg_match('/define\s*\(\s*[\'"]WP_REDIS_PORT[\'"]/i', $config_content);
            $has_path = preg_match('/define\s*\(\s*[\'"]WP_REDIS_PATH[\'"]/i', $config_content);
            $has_maxttl = preg_match('/define\s*\(\s*[\'"]WP_REDIS_MAXTTL[\'"]/i', $config_content);
            $has_metrics = preg_match('/define\s*\(\s*[\'"]WP_REDIS_DISABLE_METRICS[\'"]/i', $config_content);
            $has_comment = preg_match('/define\s*\(\s*[\'"]WP_REDIS_DISABLE_COMMENT[\'"]/i', $config_content);
            $has_salt = preg_match('/define\s*\(\s*[\'"]WP_CACHE_KEY_SALT[\'"]/i', $config_content);
            
            $secondary_count = $has_maxttl + $has_metrics + $has_comment + $has_salt;
            
            // Mark as configured if primary constants are found OR multiple secondary constants
            if (($has_host && $has_port) || $has_path || $secondary_count >= 2) {
                $config_status['configured'] = true;
                $config_status['file_configured'] = true;
            }
            // Mark as partially configured if at least one secondary constant is found
            else if ($secondary_count > 0) {
                $config_status['partially_configured'] = true;
                $config_status['file_configured'] = true;
            }
            
            // If salt is configured in the file
            if ($has_salt) {
                $config_status['salt_configured'] = true;
            }
            
            // Extract the values of each constant from the file if they exist
            foreach ($redis_constants as $constant) {
                // If not already defined in PHP context, try to extract from file
                if (!isset($config_status['constants'][$constant]) && 
                    preg_match('/define\s*\(\s*[\'"]\b' . preg_quote($constant, '/') . '\b[\'"]\s*,\s*[\'"](.*?)[\'"]/i', $config_content, $matches)) {
                    $value = $matches[1];
                    // Mask password
                    if ($constant === 'WP_REDIS_PASSWORD') {
                        $value = '******';
                    }
                    $config_status['constants'][$constant] = $value;
                }
                else if (!isset($config_status['constants'][$constant]) && 
                    preg_match('/define\s*\(\s*[\'"]\b' . preg_quote($constant, '/') . '\b[\'"]\s*,\s*(true|false)\s*\)/i', $config_content, $matches)) {
                    $value = $matches[1];
                    $config_status['constants'][$constant] = $value;
                }
                else if (!isset($config_status['constants'][$constant]) && 
                    preg_match('/define\s*\(\s*[\'"]\b' . preg_quote($constant, '/') . '\b[\'"]\s*,\s*([0-9]+)\s*\)/i', $config_content, $matches)) {
                    $value = $matches[1];
                    $config_status['constants'][$constant] = $value;
                }
            }
        }
    }
    
    return $config_status;
}

/**
 * Add Redis configuration to wp-config.php
 * 
 * @return bool|WP_Error True on success or WP_Error on failure
 */
function ccm_tools_add_redis_configuration() {
    // First check if Redis configuration already exists
    $redis_config = ccm_tools_check_redis_configuration();
    
    // If fully configured, don't add anything
    if ($redis_config['configured'] && !$redis_config['partially_configured']) {
        return new WP_Error('already_configured', __('Redis configuration already exists in wp-config.php.', 'ccm-tools'));
    }
    
    // Get wp-config.php file path
    $wp_config_path = ABSPATH . 'wp-config.php';
    if (!file_exists($wp_config_path)) {
        return new WP_Error('config_not_found', __('wp-config.php file not found.', 'ccm-tools'));
    }
    
    if (!is_writable($wp_config_path)) {
        return new WP_Error('config_not_writable', __('wp-config.php file not found or not writable.', 'ccm-tools'));
    }
    
    // Read the config file
    $config_content = file_get_contents($wp_config_path);
    if ($config_content === false) {
        return new WP_Error('read_failed', __('Failed to read wp-config.php file.', 'ccm-tools'));
    }
    
    $original_content = $config_content;
    
    // Prepare Redis configuration
    $redis_config_lines = array();
    
    // Primary constants - add if missing
    if (!isset($redis_config['constants']['WP_REDIS_HOST']) && !isset($redis_config['constants']['WP_REDIS_PATH'])) {
        $redis_config_lines[] = "define('WP_REDIS_HOST', '127.0.0.1');";
        $redis_config_lines[] = "define('WP_REDIS_PORT', 6379);";
    }
    
    // Secondary constants - add if missing
    if (!isset($redis_config['constants']['WP_REDIS_MAXTTL'])) {
        $redis_config_lines[] = "define('WP_REDIS_MAXTTL', 3600);";
    }
    
    if (!isset($redis_config['constants']['WP_REDIS_DISABLE_METRICS'])) {
        $redis_config_lines[] = "define('WP_REDIS_DISABLE_METRICS', true);";
    }
    
    if (!isset($redis_config['constants']['WP_REDIS_DISABLE_COMMENT'])) {
        $redis_config_lines[] = "define('WP_REDIS_DISABLE_COMMENT', true);";
    }
    
    // Add WP_CACHE_KEY_SALT if needed
    if (!$redis_config['salt_configured']) {
        // Get site URL for the salt
        $site_url = parse_url(site_url(), PHP_URL_HOST);
        if (empty($site_url)) {
            $site_url = 'wp_' . time(); // Fallback if site_url is not available
        }
        $redis_config_lines[] = "define('WP_CACHE_KEY_SALT', '{$site_url}');";
    }
    
    // If no configuration to add, we're done
    if (empty($redis_config_lines)) {
        return true;
    }
    
    // Format the configuration block
    $redis_config_text = "\n/* Redis configuration */\n";
    $redis_config_text .= implode("\n", $redis_config_lines);
    $redis_config_text .= "\n";
    
    // Multiple insertion strategies with comprehensive pattern matching
    $insertion_patterns = array(
        // Try to insert before "That's all" comment (multiple variations)
        array(
            'pattern' => '/(\/\*\s*That\'s\s+all,\s+stop\s+editing!\s+Happy\s+(?:blogging|publishing)\.?\s*\*\/)/i',
            'replacement' => $redis_config_text . "\n$1",
            'description' => 'before "That\'s all" comment'
        ),
        // Alternative "That's all" patterns
        array(
            'pattern' => '/(\/\*\s*That\'s\s+all,\s+stop\s+editing!\s*\*\/)/i',
            'replacement' => $redis_config_text . "\n$1",
            'description' => 'before simplified "That\'s all" comment'
        ),
        // Try to insert before ABSPATH definition
        array(
            'pattern' => '/(\/\*\*\s*Absolute\s+path\s+to\s+the\s+WordPress\s+directory\.\s*\*\/)/i',
            'replacement' => $redis_config_text . "\n$1",
            'description' => 'before ABSPATH comment'
        ),
        // Try to insert before any ABSPATH define
        array(
            'pattern' => '/(define\s*\(\s*[\'"]ABSPATH[\'"])/i',
            'replacement' => $redis_config_text . "\n$1",
            'description' => 'before ABSPATH define'
        ),
        // Try to insert before wp-settings.php require
        array(
            'pattern' => '/(require_once\s*\(\s*ABSPATH\s*\.\s*[\'"]wp-settings\.php[\'"]\s*\))/i',
            'replacement' => $redis_config_text . "\n$1",
            'description' => 'before wp-settings.php require'
        ),
        // Try to insert before closing PHP tag
        array(
            'pattern' => '/(\s*\?>\s*)$/i',
            'replacement' => $redis_config_text . "$1",
            'description' => 'before closing PHP tag'
        ),
        // Try to insert before any ending whitespace/newlines
        array(
            'pattern' => '/(\s*)$/i',
            'replacement' => $redis_config_text . "$1",
            'description' => 'at end of file'
        )
    );
    
    $inserted = false;
    foreach ($insertion_patterns as $insertion) {
        $new_content = preg_replace($insertion['pattern'], $insertion['replacement'], $config_content, 1, $count);
        if ($count > 0 && $new_content !== $config_content) {
            $config_content = $new_content;
            $inserted = true;
            break;
        }
    }
    
    if (!$inserted) {
        // Last resort: append to end
        $config_content .= $redis_config_text;
    }
    
    // Check if content actually changed
    if ($original_content === $config_content) {
        return true;
    }
    
    // Create backup before writing
    $backup_content = $original_content;
    
    // Write changes back to the file
    $bytes_written = file_put_contents($wp_config_path, $config_content);
    if ($bytes_written !== false) {
        // Clear any opcode cache
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($wp_config_path, true);
        }
        
        // Verify the changes were written correctly
        $verification_content = file_get_contents($wp_config_path);
        $verification_successful = true;
        
        foreach ($redis_config_lines as $line) {
            // Extract the constant name from the line
            if (preg_match('/define\s*\(\s*[\'"]([^\'\"]+)[\'"]/i', $line, $matches)) {
                $constant_name = $matches[1];
                if (!preg_match('/define\s*\(\s*[\'"]\s*' . preg_quote($constant_name, '/') . '\s*[\'"]/i', $verification_content)) {
                    $verification_successful = false;
                    break;
                }
            }
        }
        
        if ($verification_successful) {
            return true;
        } else {
            // Restore backup
            file_put_contents($wp_config_path, $backup_content);
            return new WP_Error('verification_failed', __('Configuration was written but verification failed. Changes have been reverted. Please check wp-config.php manually.', 'ccm-tools'));
        }
    } else {
        return new WP_Error('write_failed', __('Failed to write configuration to wp-config.php.', 'ccm-tools'));
    }
}

/**
 * Check Redis Cache plugin status
 * 
 * @return array Plugin status information
 */
function ccm_tools_check_redis_plugin(): array {
    $plugin_file = 'redis-cache/redis-cache.php';
    $plugin_slug = 'redis-cache';
    
    $status = array(
        'installed' => false,
        'active' => false,
        'object_cache_enabled' => false,
        'version' => '',
        'install_url' => wp_nonce_url(
            self_admin_url('update.php?action=install-plugin&plugin=' . $plugin_slug),
            'install-plugin_' . $plugin_slug
        ),
        'activate_url' => wp_nonce_url(
            self_admin_url('plugins.php?action=activate&plugin=' . urlencode($plugin_file)),
            'activate-plugin_' . $plugin_file
        ),
        'settings_url' => admin_url('options-general.php?page=redis-cache'),
    );
    
    // Check if the plugin file exists
    if (file_exists(WP_PLUGIN_DIR . '/' . $plugin_file)) {
        $status['installed'] = true;
        
        // Make sure necessary plugin functions are available
        if (!function_exists('is_plugin_active')) {
            include_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        
        // Get plugin data for version
        $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_file);
        $status['version'] = $plugin_data['Version'];
        
        // Check if plugin is active
        if (is_plugin_active($plugin_file)) {
            $status['active'] = true;
            
            // Check if Redis object cache is enabled
            // First check: Using the drop-in file
            $dropin_exists = file_exists(WP_CONTENT_DIR . '/object-cache.php');
            $is_redis_dropin = false;
            
            if ($dropin_exists) {
                $dropin_content = file_get_contents(WP_CONTENT_DIR . '/object-cache.php');
                $is_redis_dropin = strpos($dropin_content, 'Redis') !== false;
                
                if ($is_redis_dropin) {
                    $status['object_cache_enabled'] = true;
                }
            }
            
            // Second check: Using wp_redis_get_info if available
            if (function_exists('wp_redis_get_info')) {
                $redis_info = wp_redis_get_info();
                $status['object_cache_enabled'] = isset($redis_info['status']) && $redis_info['status'] === 'connected';
                $status['redis_info'] = $redis_info;
            } 
            // Third check: Using native Redis class methods if available
            else if (class_exists('Redis_Object_Cache_Plugin') && method_exists('Redis_Object_Cache_Plugin', 'instance')) {
                $plugin_instance = call_user_func(array('Redis_Object_Cache_Plugin', 'instance'));
                if (method_exists($plugin_instance, 'get_redis_status')) {
                    $status['object_cache_enabled'] = $plugin_instance->get_redis_status() === 'connected';
                }
            } 
            // Final check: Using wp_using_ext_object_cache
            else if (function_exists('wp_using_ext_object_cache')) {
                $status['object_cache_enabled'] = wp_using_ext_object_cache() && $is_redis_dropin;
            }
        }
    }
    
    return $status;
}

/**
 * Get list of loaded PHP extensions
 * 
 * @return array List of loaded PHP extensions
 */
function ccm_tools_get_php_extensions(): array {
    return get_loaded_extensions();
}

/**
 * Get server information
 * 
 * @return array Server details
 */
function ccm_tools_get_server_info(): array {
    $server_info = array(
        'software' => isset($_SERVER['SERVER_SOFTWARE']) ? sanitize_text_field($_SERVER['SERVER_SOFTWARE']) : '',
        'os' => PHP_OS,
        'architecture' => PHP_INT_SIZE * 8 . ' Bit',
        'protocol' => isset($_SERVER['SERVER_PROTOCOL']) ? sanitize_text_field($_SERVER['SERVER_PROTOCOL']) : '',
        'https' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'Yes' : 'No',
    );
    
    // Add server IP safely
    if (isset($_SERVER['SERVER_ADDR'])) {
        $server_info['server_ip'] = sanitize_text_field($_SERVER['SERVER_ADDR']);
    } elseif (isset($_SERVER['LOCAL_ADDR'])) {
        $server_info['server_ip'] = sanitize_text_field($_SERVER['LOCAL_ADDR']);
    } else {
        $server_info['server_ip'] = __('Not available', 'ccm-tools');
    }
    
    // Add server port
    $server_info['server_port'] = isset($_SERVER['SERVER_PORT']) ? intval($_SERVER['SERVER_PORT']) : '';
    
    // Check if Apache is running
    if (function_exists('apache_get_modules')) {
        $server_info['apache_modules'] = apache_get_modules();
    }
    
    return $server_info;
}

/**
 * Get WordPress environment details
 * 
 * @return array WordPress environment details
 */
function ccm_tools_get_wordpress_info(): array {
    global $wp_version, $wpdb;
    
    return array(
        'version' => $wp_version,
        'site_url' => site_url(),
        'home_url' => home_url(),
        'debug_mode' => defined('WP_DEBUG') && WP_DEBUG ? 'Enabled' : 'Disabled',
        'debug_log' => defined('WP_DEBUG_LOG') && WP_DEBUG_LOG ? 'Enabled' : 'Disabled',
        'debug_display' => defined('WP_DEBUG_DISPLAY') && WP_DEBUG_DISPLAY ? 'Enabled' : 'Disabled',
        'memory_limit' => WP_MEMORY_LIMIT,
        'db_version' => $wpdb->db_version(),
        'active_theme' => wp_get_theme()->get('Name') . ' (' . wp_get_theme()->get('Version') . ')',
        'active_plugins' => count(get_option('active_plugins')),
    );
}

/**
 * Check if WordPress core needs an update
 * 
 * @return array Update status information
 */
function ccm_tools_check_wordpress_updates(): array {
    global $wp_version;
    
    // Force WP to check updates, ignoring the cache
    wp_version_check([], true);
    
    // Get the update data
    $core = get_site_transient('update_core');
    
    // Default response structure
    $response = [
        'status' => 'unknown',
        'current_version' => $wp_version,
        'latest_version' => '',
        'needs_update' => false,
        'update_url' => admin_url('update-core.php'),
    ];
    
    // No update data
    if (!isset($core->updates) || empty($core->updates)) {
        // Try alternative method
        $api_response = wp_remote_get('https://api.wordpress.org/core/version-check/1.7/');
        if (is_wp_error($api_response) || wp_remote_retrieve_response_code($api_response) !== 200) {
            return $response;
        }
        
        $api_data = json_decode(wp_remote_retrieve_body($api_response), true);
        if (empty($api_data['offers'][0]['version'])) {
            return $response;
        }
        
        $latest_version = $api_data['offers'][0]['version'];
        $needs_update = version_compare($wp_version, $latest_version, '<');
        
        return [
            'status' => 'success',
            'current_version' => $wp_version,
            'latest_version' => $latest_version,
            'needs_update' => $needs_update,
            'update_url' => admin_url('update-core.php'),
        ];
    }
    
    // Process update data from WP core
    foreach ($core->updates as $update) {
        if (isset($update->response) && $update->response == 'upgrade') {
            return [
                'status' => 'success',
                'current_version' => $wp_version,
                'latest_version' => $update->version ?? '',
                'needs_update' => true,
                'update_url' => admin_url('update-core.php'),
            ];
        }
    }
    
    // If we got here, WP is up to date
    return [
        'status' => 'success',
        'current_version' => $wp_version,
        'latest_version' => $wp_version, // Current version is the latest
        'needs_update' => false,
        'update_url' => admin_url('update-core.php'),
    ];
}

/**
 * Get disk space information
 * 
 * @return array Disk space information
 */
function ccm_tools_get_disk_info(): array {
    $disk_info = array();
    
    // Get the disk space information for the WordPress installation directory
    if (function_exists('disk_total_space') && function_exists('disk_free_space')) {
        $disk_total = disk_total_space(ABSPATH);
        $disk_free = disk_free_space(ABSPATH);
        
        if ($disk_total && $disk_free) {
            $disk_used = $disk_total - $disk_free;
            $disk_used_percent = ($disk_used / $disk_total) * 100;
            
            $disk_info = array(
                'total' => ccm_tools_format_file_size($disk_total),
                'used' => ccm_tools_format_file_size($disk_used),
                'free' => ccm_tools_format_file_size($disk_free),
                'used_percent' => round($disk_used_percent, 2) . '%',
                'free_percent' => round(100 - $disk_used_percent, 2) . '%',
            );
        }
    }
    
    return $disk_info;
}

/**
 * Format file size to human-readable format
 * 
 * @param int $bytes File size in bytes
 * @param int $precision Decimal precision
 * @return string Formatted file size
 */
function ccm_tools_format_file_size($bytes, $precision = 2): string {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    
    $bytes /= pow(1024, $pow);
    
    return round($bytes, $precision) . ' ' . $units[$pow];
}

/**
 * Get WordPress database size
 * 
 * @return array Database size information
 */
function ccm_tools_get_database_size(): array {
    global $wpdb;
    
    $db_size = 0;
    $db_tables = 0;
    
    $tables = $wpdb->get_results("SHOW TABLE STATUS", ARRAY_A);
    if ($tables) {
        foreach ($tables as $table) {
            $db_size += $table['Data_length'] + $table['Index_length'];
            $db_tables++;
        }
    }
    
    return array(
        'size' => ccm_tools_format_file_size($db_size),
        'tables' => $db_tables
    );
}

/**
 * Measure Time To First Byte (TTFB) of the home page
 * 
 * @return array TTFB measurement results
 */
function ccm_tools_measure_ttfb(): array {
    $result = array(
        'success' => false,
        'time' => 0,
        'unit' => 'ms',
        'error' => '',
    );
    
    // Use home_url() to get the site's home URL
    $url = home_url('/');
    
    // Add cache-busting parameter to avoid caching
    $url = add_query_arg('nocache', microtime(true), $url);
    
    // Check if cURL is available
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_USERAGENT, 'CCM-Tools/1.0 TTFB-Checker');
        curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
        curl_setopt($ch, CURLOPT_FORBID_REUSE, true);
        
        // Add cache-control headers to prevent caching at all levels
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Cache-Control: no-cache, no-store, must-revalidate',
            'Pragma: no-cache',
            'Expires: 0',
            'Connection: close'
        ));
        
        // Start timing
        $start_time = microtime(true);
        
        // Execute the request
        $response = curl_exec($ch);
        
        // Calculate TTFB immediately after response
        $ttfb = microtime(true) - $start_time;
        
        // Check for errors
        if ($response === false) {
            $result['error'] = curl_error($ch);
        } else {
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            
            // Check if we got a valid HTTP response
            if ($http_code >= 200 && $http_code < 400) {
                // Convert to milliseconds and round to 2 decimal places
                $result['time'] = round($ttfb * 1000, 2);
                $result['success'] = true;
            } else {
                $result['error'] = "HTTP Error: {$http_code}";
            }
        }
        
        curl_close($ch);
    } else {
        // Fallback to file_get_contents if curl is not available
        $context = stream_context_create(array(
            'http' => array(
                'method' => 'GET',
                'timeout' => 10,
                'ignore_errors' => true,
                'user_agent' => 'CCM-Tools/1.0 TTFB-Checker',
                'header' => "Cache-Control: no-cache, no-store, must-revalidate\r\n" .
                           "Pragma: no-cache\r\n" .
                           "Expires: 0\r\n" .
                           "Connection: close\r\n"
            ),
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
            )
        ));
        
        // Start timing
        $start_time = microtime(true);
        
        // Suppress warnings with @
        $response = @file_get_contents($url, false, $context);
        
        // Calculate TTFB immediately after response
        $ttfb = microtime(true) - $start_time;
        
        if ($response === false) {
            $last_error = error_get_last();
            $result['error'] = $last_error ? $last_error['message'] : 'Failed to connect to the home page';
        } else {
            // Convert to milliseconds and round to 2 decimal places
            $result['time'] = round($ttfb * 1000, 2);
            $result['success'] = true;
        }
    }
    
    return $result;
}

/**
 * Get system information for AJAX request
 */
add_action('wp_ajax_ccm_tools_get_system_info', 'ccm_tools_ajax_get_system_info');
function ccm_tools_ajax_get_system_info(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('<p class="ccm-error">' . esc_html__('You do not have permission to perform this action.', 'ccm-tools') . '</p>');
    }
    
    $php_info = ccm_tools_get_php_info();
    $server_info = ccm_tools_get_server_info();
    $wordpress_info = ccm_tools_get_wordpress_info();
    $wordpress_updates = ccm_tools_check_wordpress_updates();
    $disk_info = ccm_tools_get_disk_info();
    $db_info = ccm_tools_get_database_size();
    
    $output = '<h3>' . __('Disk Information', 'ccm-tools') . '</h3>';
    if (!empty($disk_info)) {
        // Calculate a color based on disk usage (green to red)
        $used_percent_value = floatval($disk_info['used_percent']);
        $color_class = 'ccm-success';
        if ($used_percent_value > 85) {
            $color_class = 'ccm-error';
        } elseif ($used_percent_value > 70) {
            $color_class = 'ccm-warning';
        }
        
        $output .= '<div class="ccm-disk-usage">';
        $output .= '<div class="ccm-disk-bar">';
        $output .= '<div class="ccm-disk-used ' . esc_attr($color_class) . '" style="width: ' . esc_attr($disk_info['used_percent']) . ';">';
        $output .= esc_html($disk_info['used_percent']);
        $output .= '</div></div>';
        
        $output .= '<div class="ccm-disk-info">';
        $output .= '<p>' . __('Used Space:', 'ccm-tools') . ' <strong>' . esc_html($disk_info['used']) . '</strong> (' . esc_html($disk_info['used_percent']) . ')</p>';
        $output .= '<p>' . __('Free Space:', 'ccm-tools') . ' <strong>' . esc_html($disk_info['free']) . '</strong> (' . esc_html($disk_info['free_percent']) . ')</p>';
        $output .= '<p>' . __('Total Space:', 'ccm-tools') . ' <strong>' . esc_html($disk_info['total']) . '</strong></p>';
        $output .= '</div></div>';
    } else {
        $output .= '<p class="ccm-warning"><span class="ccm-icon">⚠</span> ' . __('Disk information is not available.', 'ccm-tools') . '</p>';
    }
    
    $output .= '<h3>' . __('Database Information', 'ccm-tools') . '</h3>';
    $output .= '<table class="ccm-table">';
    $output .= '<tr><th>' . __('Database Size', 'ccm-tools') . '</th><td>' . esc_html($db_info['size']) . '</td></tr>';
    $output .= '<tr><th>' . __('Number of Tables', 'ccm-tools') . '</th><td>' . esc_html($db_info['tables']) . '</td></tr>';
    $output .= '<tr><th>' . __('Database Host', 'ccm-tools') . '</th><td>' . esc_html(DB_HOST) . '</td></tr>';
    $output .= '<tr><th>' . __('Database Name', 'ccm-tools') . '</th><td>' . esc_html(DB_NAME) . '</td></tr>';
    $output .= '<tr><th>' . __('Database User', 'ccm-tools') . '</th><td>' . esc_html(DB_USER) . '</td></tr>';
    $output .= '<tr><th>' . __('Database Charset', 'ccm-tools') . '</th><td>' . esc_html(defined('DB_CHARSET') ? DB_CHARSET : 'utf8') . '</td></tr>';
    $output .= '<tr><th>' . __('Database Collation', 'ccm-tools') . '</th><td>' . esc_html(defined('DB_COLLATE') && DB_COLLATE ? DB_COLLATE : 'Default') . '</td></tr>';
    $output .= '<tr><th>' . __('MySQL Version', 'ccm-tools') . '</th><td>' . esc_html($GLOBALS['wpdb']->db_version()) . '</td></tr>';
    $output .= '</table>';
    
    $output .= '<h3>' . __('PHP Information', 'ccm-tools') . '</h3>';
    $output .= '<table class="ccm-table">';
    foreach ($php_info as $key => $value) {
        $output .= '<tr>';
        $output .= '<th>' . esc_html(ucwords(str_replace('_', ' ', $key))) . '</th>';
        $output .= '<td>' . esc_html($value) . '</td>';
        $output .= '</tr>';
    }
    $output .= '</table>';
    
    $output .= '<h3>' . __('Server Information', 'ccm-tools') . '</h3>';
    $output .= '<table class="ccm-table">';
    foreach ($server_info as $key => $value) {
        if ($key === 'apache_modules') {
            continue; // Handle Apache modules separately
        }
        $output .= '<tr>';
        $output .= '<th>' . esc_html(ucwords(str_replace('_', ' ', $key))) . '</th>';
        $output .= '<td>' . esc_html($value) . '</td>';
        $output .= '</tr>';
    }
    $output .= '</table>';
    
    if (isset($server_info['apache_modules'])) {
        $output .= '<h3>' . __('Apache Modules', 'ccm-tools') . '</h3>';
        $output .= '<div class="ccm-extensions-grid">';
        foreach ($server_info['apache_modules'] as $module) {
            $output .= '<div class="ccm-extension-item ccm-success">';
            $output .= '<span class="ccm-icon">✓</span> ' . esc_html($module);
            $output .= '</div>';
        }
        $output .= '</div>';
    }
    
    $output .= '<h3>' . __('WordPress Information', 'ccm-tools') . '</h3>';
    $output .= '<table class="ccm-table">';
    foreach ($wordpress_info as $key => $value) {
        $output .= '<tr>';
        $output .= '<th>' . esc_html(ucwords(str_replace('_', ' ', $key))) . '</th>';
        $output .= '<td>' . esc_html($value) . '</td>';
        $output .= '</tr>';
    }
    $output .= '</table>';
    
    if ($wordpress_updates['status'] === 'success') {
        $output .= '<h3>' . __('WordPress Updates', 'ccm-tools') . '</h3>';
        $output .= '<p>';
        if ($wordpress_updates['needs_update']) {
            $output .= '<span class="ccm-warning">' . __('WordPress update available!', 'ccm-tools') . '</span> ';
            $output .= sprintf(__('Current version: %s, Latest version: %s', 'ccm-tools'), 
                esc_html($wordpress_updates['current_version']), 
                esc_html($wordpress_updates['latest_version'])
            );
            $output .= ' <a href="' . esc_url($wordpress_updates['update_url']) . '" class="ccm-update-link">' . __('Update Now', 'ccm-tools') . '</a>';
        } else {
            $output .= '<span class="ccm-success">' . __('WordPress is up to date!', 'ccm-tools') . '</span> ';
            $output .= sprintf(__('Current version: %s', 'ccm-tools'), esc_html($wordpress_updates['current_version']));
        }
        $output .= '</p>';
    }
    
    wp_send_json_success($output);
}

/**
 * Enhanced TTFB measurement function with improved baseline strategy
 * This is the main function that should be called for TTFB measurement
 * @return array Enhanced TTFB measurement results
 */
function ccm_tools_measure_ttfb_enhanced(): array {
    // Strategy: Measure fresh first to "warm up" the server, then baseline
    // This eliminates the cold start advantage that fresh measurements were getting
    
    // First, do a single warm-up request (not counted in results)
    $warmup_result = ccm_tools_single_ttfb_measurement(home_url('/'), true, 0);
    
    // Small delay after warmup
    usleep(100000); // 0.1 second
    
    // Now get baseline measurement with warmed-up server
    $baseline_result = ccm_tools_measure_ttfb_improved(true, 3);
    
    // Then get fresh measurement with cache-busting
    $fresh_result = ccm_tools_measure_ttfb_improved(false, 3);
    
    // Determine which result to use as primary
    $primary_result = $baseline_result;
    $is_using_baseline = true;
    
    // If baseline measurement failed but fresh succeeded, use fresh
    if (!$baseline_result['success'] && $fresh_result['success']) {
        $primary_result = $fresh_result;
        $is_using_baseline = false;
    }
    
    // If both succeeded, use the baseline result but include comparison data
    if ($baseline_result['success'] && $fresh_result['success']) {
        $primary_result['fresh_time'] = $fresh_result['time'];
        $primary_result['baseline_time'] = $baseline_result['time'];
        
        // Determine which is actually faster and provide context
        $baseline_avg = $baseline_result['time'];
        $fresh_avg = $fresh_result['time'];
        
        // Add information about the warmup
        $primary_result['warmup_used'] = $warmup_result['success'];
        
        if (abs($baseline_avg - $fresh_avg) < 15) {
            // Very similar results (within 15ms)
            $primary_result['measurement_note'] = sprintf(
                'Baseline: %sms, Fresh: %sms (similar performance)',
                $baseline_avg,
                $fresh_avg
            );
        } elseif ($baseline_avg <= $fresh_avg) {
            // Baseline faster or equal - expected behavior
            $cache_benefit = $fresh_avg - $baseline_avg;
            if ($cache_benefit < 5) {
                $primary_result['measurement_note'] = sprintf(
                    'Baseline: %sms, Fresh: %sms (minimal difference)',
                    $baseline_avg,
                    $fresh_avg
                );
            } else {
                $primary_result['measurement_note'] = sprintf(
                    'Baseline: %sms, Fresh: %sms (cache benefit: %sms)',
                    $baseline_avg,
                    $fresh_avg,
                    round($cache_benefit, 2)
                );
            }
        } else {
            // Fresh is faster - still unusual but note it
            $primary_result['measurement_note'] = sprintf(
                'Baseline: %sms, Fresh: %sms (fresh faster by %sms - may indicate caching overhead)',
                $baseline_avg,
                $fresh_avg,
                round($baseline_avg - $fresh_avg, 2)
            );
        }
    } else {
        $primary_result['measurement_note'] = $is_using_baseline ? 'Baseline measurement' : 'Fresh measurement (baseline failed)';
    }
    
    return $primary_result;
}

/**
 * Improved TTFB measurement with multiple attempts and better accuracy
 * @param bool $use_cache Whether to allow cached responses for more realistic measurement
 * @param int $attempts Number of attempts to average for more accurate results
 * @return array TTFB measurement results
 */
function ccm_tools_measure_ttfb_improved($use_cache = true, $attempts = 3): array {
    $result = array(
        'success' => false,
        'time' => 0,
        'unit' => 'ms',
        'error' => '',
        'attempts' => $attempts,
        'individual_times' => array(),
        'measurement_type' => $use_cache ? 'cached' : 'fresh',
    );
    
    // Use home_url() to get the site's home URL
    $url = home_url('/');
    
    $measurements = array();
    $errors = array();
    
    // Perform multiple measurements for better accuracy
    for ($i = 0; $i < $attempts; $i++) {
        $single_result = ccm_tools_single_ttfb_measurement($url, $use_cache, $i);
        
        if ($single_result['success']) {
            $measurements[] = $single_result['time'];
            $result['individual_times'][] = $single_result['time'];
        } else {
            $errors[] = $single_result['error'];
        }
        
        // Small delay between measurements to avoid overwhelming the server
        if ($i < $attempts - 1) {
            usleep(200000); // 0.2 second delay
        }
    }
    
    if (!empty($measurements)) {
        // Calculate average, removing outliers if we have enough measurements
        if (count($measurements) >= 3) {
            // Remove highest and lowest values to reduce impact of outliers
            sort($measurements);
            $original_count = count($measurements);
            if ($original_count > 3) {
                array_shift($measurements); // Remove lowest
                array_pop($measurements);   // Remove highest
            }
        }
        
        $result['time'] = round(array_sum($measurements) / count($measurements), 2);
        $result['success'] = true;
    } else {
        $result['error'] = !empty($errors) ? implode('; ', array_unique($errors)) : 'All measurement attempts failed';
    }
    
    return $result;
}

/**
 * Perform a single TTFB measurement with improved handling for baseline vs fresh
 * @param string $url URL to measure
 * @param bool $use_cache Whether to allow cached responses
 * @param int $attempt_number Current attempt number for cache busting
 * @return array Single measurement result
 */
function ccm_tools_single_ttfb_measurement($url, $use_cache, $attempt_number): array {
    $result = array(
        'success' => false,
        'time' => 0,
        'error' => '',
    );
    
    // For baseline measurements, use clean URL or minimal parameters
    // For fresh measurements, use aggressive cache-busting
    if (!$use_cache) {
        // Fresh: Aggressive cache-busting
        $url = add_query_arg('ttfb_fresh', microtime(true) . '_' . $attempt_number, $url);
    } else {
        // Baseline: Only add parameter if we need unique requests for multiple attempts
        if ($attempt_number > 0) {
            $url = add_query_arg('ttfb_baseline', $attempt_number, $url);
        }
        // First baseline attempt uses completely clean URL
    }
    
    // Check if cURL is available
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        
        // Common cURL options for both baseline and fresh requests
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERAGENT, 'CCM-Tools/6.4.6 TTFB-Checker');
        
        // Always use fresh connections for consistent measurements
        curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
        curl_setopt($ch, CURLOPT_FORBID_REUSE, true);
        
        // Set headers based on whether we want to use cache
        if ($use_cache) {
            // Baseline: Allow server-side caching, normal browser-like headers
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.5',
                'Accept-Encoding: gzip, deflate',
                'Connection: close'
            ));
        } else {
            // Fresh: Force fresh response from server with aggressive cache-busting
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Cache-Control: no-cache, no-store, must-revalidate',
                'Pragma: no-cache',
                'Expires: 0',
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.5',
                'Accept-Encoding: gzip, deflate',
                'Connection: close',
                'If-Modified-Since: Thu, 01 Jan 1970 00:00:00 GMT',
                'If-None-Match: "invalid-etag"'
            ));
        }
        
        // Start timing
        $start_time = microtime(true);
        
        // Execute the request
        $response = curl_exec($ch);
        
        // Calculate TTFB immediately after response
        $ttfb = microtime(true) - $start_time;
        
        // Check for errors
        if ($response === false) {
            $result['error'] = curl_error($ch);
        } else {
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            
            // Check if we got a valid HTTP response
            if ($http_code >= 200 && $http_code < 400) {
                // Convert to milliseconds and round to 2 decimal places
                $result['time'] = round($ttfb * 1000, 2);
                $result['success'] = true;
            } else {
                $result['error'] = "HTTP Error: {$http_code}";
            }
        }
        
        curl_close($ch);
    } else {
        // Fallback to file_get_contents if curl is not available
        $headers = $use_cache ? 
            "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8\r\n" .
            "Accept-Language: en-US,en;q=0.5\r\n" .
            "Accept-Encoding: gzip, deflate\r\n" .
            "Connection: close\r\n" :
            "Cache-Control: no-cache, no-store, must-revalidate\r\n" .
            "Pragma: no-cache\r\n" .
            "Expires: 0\r\n" .
            "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8\r\n" .
            "Accept-Language: en-US,en;q=0.5\r\n" .
            "Accept-Encoding: gzip, deflate\r\n" .
            "Connection: close\r\n" .
            "If-Modified-Since: Thu, 01 Jan 1970 00:00:00 GMT\r\n" .
            "If-None-Match: \"invalid-etag\"\r\n";
            
        $context = stream_context_create(array(
            'http' => array(
                'method' => 'GET',
                'timeout' => 15,
                'ignore_errors' => true,
                'user_agent' => 'CCM-Tools/6.4.6 TTFB-Checker',
                'header' => $headers
            ),
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
            )
        ));
        
        // Start timing
        $start_time = microtime(true);
        
        // Suppress warnings with @
        $response = @file_get_contents($url, false, $context);
        
        // Calculate TTFB immediately after response
        $ttfb = microtime(true) - $start_time;
        
        if ($response === false) {
            $last_error = error_get_last();
            $result['error'] = $last_error ? $last_error['message'] : 'Failed to connect to the home page';
        } else {
            // Convert to milliseconds and round to 2 decimal places
            $result['time'] = round($ttfb * 1000, 2);
            $result['success'] = true;
        }
    }
    
    return $result;
}

// Remove direct HTML output from this file. Only output HTML when rendering the admin page, not after AJAX or function code.
// The following block should only be included in the actual admin page rendering, not after wp_send_json_success or any AJAX handler.
// If you need to render the admin page, do it in a dedicated function or template, not at the end of this file.
// (Removed stray HTML navigation markup)