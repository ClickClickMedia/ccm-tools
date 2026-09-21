<?php
// Prevent direct file access
if (!defined('ABSPATH')) {
    exit;
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
                        $result = $redis->connect($attempt['host']);
                    } else {
                        $result = $redis->connect($attempt['host'], $attempt['port'], $attempt['timeout']);
                    }
                    
                    if ($result) {
                        $status['connected'] = true;
                        $status['server_available'] = true;
                        $info = $redis->info();
                        $status['version'] = isset($info['redis_version']) ? $info['redis_version'] : 'Unknown';
                        $status['connection'] = $attempt;
                        break;
                    }
                } catch (\Throwable $e) {
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
            global $wp_filesystem;
            if (empty($wp_filesystem)) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
                WP_Filesystem();
            }
            $config_content = $wp_filesystem ? $wp_filesystem->get_contents($wp_config_path) : false;
            if ($config_content === false) {
                return $config_status;
            }

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
 * Check if WordPress core needs an update
 * 
 * @return array Update status information
 */
function ccm_tools_check_wordpress_updates(): array {
    global $wp_version;
    
    // Check for WP core updates. Not forced: wp_version_check() only hits
    // api.wordpress.org when its own cache is stale, so this no longer POSTs
    // (and, on failure, GET-retries) to WordPress.org on every dashboard load.
    wp_version_check();
    
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