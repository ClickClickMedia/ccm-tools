<?php
// Prevent direct file access
if (!defined('ABSPATH')) {
    exit;
}

// Convert Tables
add_action('wp_ajax_ccm_tools_convert_tables', 'ccm_tools_ajax_convert_tables');
function ccm_tools_ajax_convert_tables(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('<p class="ccm-error">' . esc_html__('You do not have permission to perform this action.', 'ccm-tools') . '</p>');
    }
    $result = ccm_tools_convert_tables();
    wp_send_json_success($result);
}

// Get tables to convert (AJAX)
add_action('wp_ajax_ccm_tools_get_tables_to_convert', 'ccm_tools_ajax_get_tables_to_convert');
function ccm_tools_ajax_get_tables_to_convert(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('<p class="ccm-error">' . esc_html__('You do not have permission to perform this action.', 'ccm-tools') . '</p>');
    }
    $tables_info = ccm_tools_get_tables_to_convert();
    wp_send_json_success($tables_info);
}

// Convert single table (AJAX)
add_action('wp_ajax_ccm_tools_convert_single_table', 'ccm_tools_ajax_convert_single_table');
function ccm_tools_ajax_convert_single_table(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('<p class="ccm-error">' . esc_html__('You do not have permission to perform this action.', 'ccm-tools') . '</p>');
    }
    
    $table_name = isset($_POST['table_name']) ? sanitize_text_field($_POST['table_name']) : '';
    if (empty($table_name)) {
        wp_send_json_error('<p class="ccm-error">' . esc_html__('No table name provided.', 'ccm-tools') . '</p>');
    }
    
    $result = ccm_tools_convert_single_table($table_name);
    wp_send_json_success($result);
}

// Optimize Database
add_action('wp_ajax_ccm_tools_optimize_database', 'ccm_tools_ajax_optimize_database');
function ccm_tools_ajax_optimize_database(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('<p class="ccm-error">' . esc_html__('You do not have permission to perform this action.', 'ccm-tools') . '</p>');
    }
    $result = ccm_tools_optimize_database();
    wp_send_json_success($result);
}

// Get tables to optimize (AJAX)
add_action('wp_ajax_ccm_tools_get_tables_to_optimize', 'ccm_tools_ajax_get_tables_to_optimize');
function ccm_tools_ajax_get_tables_to_optimize(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('<p class="ccm-error">' . esc_html__('You do not have permission to perform this action.', 'ccm-tools') . '</p>');
    }
    
    try {
        $tables_info = ccm_tools_get_tables_to_optimize();
        
        if (isset($tables_info['error'])) {
            wp_send_json_error('Error getting tables: ' . $tables_info['error']);
        } else {
            wp_send_json_success($tables_info);
        }
    } catch (Exception $e) {
        wp_send_json_error('Exception: ' . $e->getMessage());
    }
}

// Optimize initial setup (AJAX)
add_action('wp_ajax_ccm_tools_optimize_initial_setup', 'ccm_tools_ajax_optimize_initial_setup');
function ccm_tools_ajax_optimize_initial_setup(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('<p class="ccm-error">' . esc_html__('You do not have permission to perform this action.', 'ccm-tools') . '</p>');
    }
    $result = ccm_tools_optimize_initial_setup();
    wp_send_json_success($result);
}

// Get optimization options and stats (AJAX)
add_action('wp_ajax_ccm_tools_get_optimization_options', 'ccm_tools_ajax_get_optimization_options');
function ccm_tools_ajax_get_optimization_options(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('You do not have permission to perform this action.', 'ccm-tools'));
    }
    
    $options = ccm_tools_get_optimization_options();
    $stats = ccm_tools_get_optimization_stats();
    
    wp_send_json_success(array(
        'options' => $options,
        'stats' => $stats
    ));
}

// Run selected optimizations (AJAX)
add_action('wp_ajax_ccm_tools_run_optimizations', 'ccm_tools_ajax_run_optimizations');
function ccm_tools_ajax_run_optimizations(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('You do not have permission to perform this action.', 'ccm-tools'));
    }
    
    // Handle both selected and selected[] (array notation from FormData)
    $selected = array();
    if (isset($_POST['selected']) && is_array($_POST['selected'])) {
        $selected = array_map('sanitize_text_field', $_POST['selected']);
    } elseif (isset($_POST['selected'])) {
        // Single value or comma-separated fallback
        $selected = array_map('sanitize_text_field', explode(',', $_POST['selected']));
    }
    
    if (empty($selected)) {
        wp_send_json_error(__('No optimization options selected.', 'ccm-tools'));
    }
    
    // Validate all selected options exist
    $available = ccm_tools_get_optimization_options();
    foreach ($selected as $option) {
        if (!isset($available[$option])) {
            wp_send_json_error(sprintf(__('Invalid optimization option: %s', 'ccm-tools'), $option));
        }
    }
    
    $results = ccm_tools_run_selected_optimizations($selected);
    
    // Calculate totals
    $total_count = 0;
    $success_count = 0;
    $messages = array();
    
    foreach ($results as $key => $result) {
        if (isset($result['count'])) {
            $total_count += $result['count'];
        }
        if (!empty($result['success'])) {
            $success_count++;
        }
        if (!empty($result['message'])) {
            $messages[] = $result['message'];
        }
    }
    
    wp_send_json_success(array(
        'results' => $results,
        'total_count' => $total_count,
        'success_count' => $success_count,
        'total_tasks' => count($selected),
        'summary' => implode("\n", $messages)
    ));
}

/**
 * AJAX handler to run a single optimization task
 * This allows progressive execution with live feedback
 */
add_action('wp_ajax_ccm_tools_run_single_optimization', 'ccm_tools_ajax_run_single_optimization');
function ccm_tools_ajax_run_single_optimization(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('You do not have permission to perform this action.', 'ccm-tools'));
    }
    
    $task = isset($_POST['task']) ? sanitize_text_field($_POST['task']) : '';
    
    if (empty($task)) {
        wp_send_json_error(__('No optimization task specified.', 'ccm-tools'));
    }
    
    // Validate the task exists
    $available = ccm_tools_get_optimization_options();
    if (!isset($available[$task])) {
        wp_send_json_error(sprintf(__('Invalid optimization task: %s', 'ccm-tools'), $task));
    }
    
    // Run the single task
    $results = ccm_tools_run_selected_optimizations(array($task));
    $result = isset($results[$task]) ? $results[$task] : array('success' => false, 'message' => 'Task not executed');
    
    wp_send_json_success(array(
        'task' => $task,
        'label' => $available[$task]['label'],
        'success' => !empty($result['success']),
        'message' => isset($result['message']) ? $result['message'] : '',
        'count' => isset($result['count']) ? $result['count'] : 0
    ));
}

// Optimize single table (AJAX)
add_action('wp_ajax_ccm_tools_optimize_single_table', 'ccm_tools_ajax_optimize_single_table');
function ccm_tools_ajax_optimize_single_table(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('<p class="ccm-error">' . esc_html__('You do not have permission to perform this action.', 'ccm-tools') . '</p>');
    }
    
    $table_name = isset($_POST['table_name']) ? sanitize_text_field($_POST['table_name']) : '';
    if (empty($table_name)) {
        wp_send_json_error('<p class="ccm-error">' . esc_html__('No table name provided.', 'ccm-tools') . '</p>');
    }
    
    $result = ccm_tools_optimize_single_table($table_name);
    wp_send_json_success($result);
}

// Display .htaccess
add_action('wp_ajax_ccm_tools_display_htaccess', 'ccm_tools_ajax_display_htaccess');
function ccm_tools_ajax_display_htaccess(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('<p class="ccm-error">' . esc_html__('You do not have permission to perform this action.', 'ccm-tools') . '</p>');
    }
    $result = ccm_tools_display_htaccess();
    wp_send_json_success($result);
}

/**
 * Helper function to parse htaccess options from POST
 */
function ccm_tools_parse_htaccess_options(): array {
    $options = array();
    $valid_options = array(
        // Safe options
        'caching', 'compression', 'security_headers', 'hsts_basic', 'https_redirect', 'file_protection', 'disable_indexes', 'etag_removal',
        // Moderate options
        'x_frame_options', 'x_xss_protection', 'hsts_subdomains', 'coop', 'corp', 'block_author_scan',
        // High risk options
        'block_xmlrpc', 'block_rest_api'
    );
    
    // Parse options from POST - handle both array format and individual params
    if (isset($_POST['options']) && is_array($_POST['options'])) {
        foreach ($_POST['options'] as $opt) {
            $opt = sanitize_text_field($opt);
            if (in_array($opt, $valid_options)) {
                $options[$opt] = true;
            }
        }
    }
    
    // Fill in missing options as false
    foreach ($valid_options as $opt) {
        if (!isset($options[$opt])) {
            $options[$opt] = false;
        }
    }
    
    return $options;
}

// Add .htaccess optimizations
add_action('wp_ajax_ccm_tools_add_htaccess', 'ccm_tools_ajax_add_htaccess');
function ccm_tools_ajax_add_htaccess(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('<p class="ccm-error">' . esc_html__('You do not have permission to perform this action.', 'ccm-tools') . '</p>');
    }
    // Prevent double execution
    if (defined('CCM_HTACCESS_ADD_RUNNING')) {
        wp_send_json_error('<p class="ccm-error"><span class="ccm-icon">✗</span>' . esc_html__('Request already processing.', 'ccm-tools') . '</p>');
    }
    define('CCM_HTACCESS_ADD_RUNNING', true);

    // Get options from POST data
    $options = ccm_tools_parse_htaccess_options();
    
    $result = ccm_tools_update_htaccess('add', $options);
    if ($result['success']) {
        wp_send_json_success('<p class="ccm-success"><span class="ccm-icon">✓</span>' . esc_html($result['message']) . '</p>' . ccm_tools_display_htaccess());
    } else {
        wp_send_json_error('<p class="ccm-error"><span class="ccm-icon">✗</span>' . esc_html($result['message']) . '</p>');
    }
}

// Update .htaccess optimizations (with new options)
add_action('wp_ajax_ccm_tools_update_htaccess', 'ccm_tools_ajax_update_htaccess');
function ccm_tools_ajax_update_htaccess(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('<p class="ccm-error">' . esc_html__('You do not have permission to perform this action.', 'ccm-tools') . '</p>');
    }
    
    // Get options from POST data
    $options = ccm_tools_parse_htaccess_options();
    
    $result = ccm_tools_update_htaccess('update', $options);
    if ($result['success']) {
        wp_send_json_success('<p class="ccm-success"><span class="ccm-icon">✓</span>' . esc_html($result['message']) . '</p>' . ccm_tools_display_htaccess());
    } else {
        wp_send_json_error('<p class="ccm-error"><span class="ccm-icon">✗</span>' . esc_html($result['message']) . '</p>');
    }
}

// Remove .htaccess optimizations
add_action('wp_ajax_ccm_tools_remove_htaccess', 'ccm_tools_ajax_remove_htaccess');
function ccm_tools_ajax_remove_htaccess(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('<p class="ccm-error">' . esc_html__('You do not have permission to perform this action.', 'ccm-tools') . '</p>');
    }
    // Prevent double execution
    if (defined('CCM_HTACCESS_REMOVE_RUNNING')) {
        wp_send_json_error('<p class="ccm-error"><span class="ccm-icon">✗</span>' . esc_html__('Request already processing.', 'ccm-tools') . '</p>');
    }
    define('CCM_HTACCESS_REMOVE_RUNNING', true);

    $result = ccm_tools_update_htaccess('remove');
    if ($result['success']) {
        wp_send_json_success('<p class="ccm-success"><span class="ccm-icon">✓</span>' . esc_html($result['message']) . '</p>' . ccm_tools_display_htaccess());
    } else {
        wp_send_json_error('<p class="ccm-error"><span class="ccm-icon">✗</span>' . esc_html($result['message']) . '</p>');
    }
}

/**
 * Update WordPress debug mode setting
 */
add_action('wp_ajax_ccm_tools_update_debug_mode', 'ccm_tools_ajax_update_debug_mode');
function ccm_tools_ajax_update_debug_mode(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('You do not have permission to perform this action.', 'ccm-tools'));
    }

    $enable = isset($_POST['enable']) ? filter_var($_POST['enable'], FILTER_VALIDATE_BOOLEAN) : false;
    
    // Get wp-config.php file path
    $wp_config_path = ABSPATH . 'wp-config.php';
    $wp_config_sample_path = ABSPATH . 'wp-config-sample.php';
    
    if (!file_exists($wp_config_path) && file_exists($wp_config_sample_path)) {
        $wp_config_path = $wp_config_sample_path;
    }
    
    if (!file_exists($wp_config_path) || !is_writable($wp_config_path)) {
        wp_send_json_error(__('wp-config.php file not found or not writable.', 'ccm-tools'));
        return;
    }
    
    // Read the config file
    $config_content = file_get_contents($wp_config_path);
    
    // Update WP_DEBUG value
    $debug_value = $enable ? 'true' : 'false';
    
    // If disabling debug mode, also disable debug display AND debug log
    if (!$enable) {
        // Update or add WP_DEBUG_DISPLAY to false
        if (preg_match('/define\(\s*[\'"]WP_DEBUG_DISPLAY[\'"]\s*,\s*(?:true|false)\s*\)/i', $config_content)) {
            $config_content = preg_replace(
                '/define\(\s*[\'"]WP_DEBUG_DISPLAY[\'"]\s*,\s*(?:true|false)\s*\)/i',
                "define('WP_DEBUG_DISPLAY', false)",
                $config_content
            );
        } else {
            // Add WP_DEBUG_DISPLAY line after WP_DEBUG if it doesn't exist
            $config_content = preg_replace(
                '/(define\(\s*[\'"]WP_DEBUG[\'"]\s*,\s*(?:true|false)\s*\);)/i',
                "$1\ndefine('WP_DEBUG_DISPLAY', false);",
                $config_content
            );
        }
        
        // Update or add WP_DEBUG_LOG to false
        if (preg_match('/define\(\s*[\'"]WP_DEBUG_LOG[\'"]\s*,\s*(?:true|false)\s*\)/i', $config_content)) {
            $config_content = preg_replace(
                '/define\(\s*[\'"]WP_DEBUG_LOG[\'"]\s*,\s*(?:true|false)\s*\)/i',
                "define('WP_DEBUG_LOG', false)",
                $config_content
            );
        } else {
            // Add WP_DEBUG_LOG line after WP_DEBUG if it doesn't exist
            $config_content = preg_replace(
                '/(define\(\s*[\'"]WP_DEBUG[\'"]\s*,\s*(?:true|false)\s*\);)/i',
                "$1\ndefine('WP_DEBUG_LOG', false);",
                $config_content
            );
        }
    }
    
    // Update WP_DEBUG
    if (preg_match('/define\(\s*[\'"]WP_DEBUG[\'"]\s*,\s*(?:true|false)\s*\)/i', $config_content)) {
        // Replace existing WP_DEBUG line
        $config_content = preg_replace(
            '/define\(\s*[\'"]WP_DEBUG[\'"]\s*,\s*(?:true|false)\s*\)/i',
            "define('WP_DEBUG', $debug_value)",
            $config_content
        );
    } else {
        // Add WP_DEBUG line before DB_CHARSET
        $config_content = preg_replace(
            '/(define\(\s*[\'"]DB_CHARSET[\'"]\s*,)/',
            "define('WP_DEBUG', $debug_value);\n$1",
            $config_content
        );
    }
    
    // Write changes back to the file
    if (file_put_contents($wp_config_path, $config_content)) {
        // Clear any opcode cache
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($wp_config_path, true);
        }
        
        // Re-read the file to ensure changes were applied
        $updated_config = file_get_contents($wp_config_path);
        $debug_log_enabled = false;
        $debug_display_enabled = false;
        
        // Check if WP_DEBUG_LOG is true in the file
        if (preg_match('/define\s*\(\s*[\'"]WP_DEBUG_LOG[\'"]\s*,\s*true\s*\)/i', $updated_config)) {
            $debug_log_enabled = true;
        }
        
        // Check if WP_DEBUG_DISPLAY is true in the file
        if (preg_match('/define\s*\(\s*[\'"]WP_DEBUG_DISPLAY[\'"]\s*,\s*true\s*\)/i', $updated_config)) {
            $debug_display_enabled = true;
        }
        
        wp_send_json_success(array(
            'message' => $enable ? 
                __('WP_DEBUG enabled successfully. Please note errors may be visible on the frontend depending on your display settings.', 'ccm-tools') : 
                __('WP_DEBUG disabled successfully. Debug display and debug log have also been disabled.', 'ccm-tools'),
            'status' => $enable ? 'Enabled' : 'Disabled',
            'debug_log_status' => $debug_log_enabled ? 'Enabled' : 'Disabled',
            'debug_display_status' => $debug_display_enabled ? 'Enabled' : 'Disabled'
        ));
    } else {
        wp_send_json_error(__('Failed to update wp-config.php file.', 'ccm-tools'));
    }
}

/**
 * Update WordPress debug display setting
 */
add_action('wp_ajax_ccm_tools_update_debug_display', 'ccm_tools_ajax_update_debug_display');
function ccm_tools_ajax_update_debug_display(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('You do not have permission to perform this action.', 'ccm-tools'));
    }

    $enable = isset($_POST['enable']) ? filter_var($_POST['enable'], FILTER_VALIDATE_BOOLEAN) : false;
    
    // If we're enabling debug display but WP_DEBUG is not enabled, we can't proceed
    if ($enable && (!defined('WP_DEBUG') || !WP_DEBUG)) {
        wp_send_json_error(__('WP_DEBUG must be enabled first to use debug display.', 'ccm-tools'));
        return;
    }
    
    // Get wp-config.php file path
    $wp_config_path = ABSPATH . 'wp-config.php';
    $wp_config_sample_path = ABSPATH . 'wp-config-sample.php';
    
    if (!file_exists($wp_config_path) && file_exists($wp_config_sample_path)) {
        $wp_config_path = $wp_config_sample_path;
    }
    
    if (!file_exists($wp_config_path) || !is_writable($wp_config_path)) {
        wp_send_json_error(__('wp-config.php file not found or not writable.', 'ccm-tools'));
        return;
    }
    
    // Read the config file
    $config_content = file_get_contents($wp_config_path);
    
    // Update WP_DEBUG_DISPLAY value
    $debug_display_value = $enable ? 'true' : 'false';
    
    if (preg_match('/define\(\s*[\'"]WP_DEBUG_DISPLAY[\'"]\s*,\s*(?:true|false)\s*\)/i', $config_content)) {
        // Replace existing WP_DEBUG_DISPLAY line
        $config_content = preg_replace(
            '/define\(\s*[\'"]WP_DEBUG_DISPLAY[\'"]\s*,\s*(?:true|false)\s*\)/i',
            "define('WP_DEBUG_DISPLAY', $debug_display_value)",
            $config_content
        );
    } else {
        // Add WP_DEBUG_DISPLAY line after WP_DEBUG
        if (preg_match('/define\(\s*[\'"]WP_DEBUG[\'"]\s*,\s*(?:true|false)\s*\);/i', $config_content)) {
            $config_content = preg_replace(
                '/(define\(\s*[\'"]WP_DEBUG[\'"]\s*,\s*(?:true|false)\s*\);)/i',
                "$1\ndefine('WP_DEBUG_DISPLAY', $debug_display_value);",
                $config_content
            );
        } else {
            // If WP_DEBUG is not defined (shouldn't happen), add it before DB_CHARSET
            $config_content = preg_replace(
                '/(define\(\s*[\'"]DB_CHARSET[\'"]\s*,)/',
                "define('WP_DEBUG_DISPLAY', $debug_display_value);\n$1",
                $config_content
            );
        }
    }
    
    // Write changes back to the file
    if (file_put_contents($wp_config_path, $config_content)) {
        // Clear any opcode cache
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($wp_config_path, true);
        }
        
        wp_send_json_success(array(
            'message' => $enable ? 
                __('WP_DEBUG_DISPLAY enabled successfully. PHP errors will now be visible on the frontend.', 'ccm-tools') : 
                __('WP_DEBUG_DISPLAY disabled successfully.', 'ccm-tools'),
            'status' => $enable ? 'Enabled' : 'Disabled',
            'debug_log_status' => defined('WP_DEBUG_LOG') && WP_DEBUG_LOG ? 'Enabled' : 'Disabled'
        ));
    } else {
        wp_send_json_error(__('Failed to update wp-config.php file.', 'ccm-tools'));
    }
}

/**
 * Update WordPress debug log setting
 */
add_action('wp_ajax_ccm_tools_update_debug_log', 'ccm_tools_ajax_update_debug_log');
function ccm_tools_ajax_update_debug_log(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('You do not have permission to perform this action.', 'ccm-tools'));
    }

    $enable = isset($_POST['enable']) ? filter_var($_POST['enable'], FILTER_VALIDATE_BOOLEAN) : false;
    
    // If we're enabling debug log but WP_DEBUG is not enabled, we can't proceed
    if ($enable && (!defined('WP_DEBUG') || !WP_DEBUG)) {
        wp_send_json_error(__('WP_DEBUG must be enabled to use debug logging.', 'ccm-tools'));
        return;
    }
    
    // Get wp-config.php file path
    $wp_config_path = ABSPATH . 'wp-config.php';
    $wp_config_sample_path = ABSPATH . 'wp-config-sample.php';
    
    if (!file_exists($wp_config_path) && file_exists($wp_config_sample_path)) {
        $wp_config_path = $wp_config_sample_path;
    }
    
    if (!file_exists($wp_config_path) || !is_writable($wp_config_path)) {
        wp_send_json_error(__('wp-config.php file not found or not writable.', 'ccm-tools'));
        return;
    }
    
    // Read the config file
    $config_content = file_get_contents($wp_config_path);
    
    // Update WP_DEBUG_LOG value
    $debug_log_value = $enable ? 'true' : 'false';
    
    if (preg_match('/define\(\s*[\'"]WP_DEBUG_LOG[\'"]\s*,\s*(?:true|false)\s*\)/i', $config_content)) {
        // Replace existing WP_DEBUG_LOG line
        $config_content = preg_replace(
            '/define\(\s*[\'"]WP_DEBUG_LOG[\'"]\s*,\s*(?:true|false)\s*\)/i',
            "define('WP_DEBUG_LOG', $debug_log_value)",
            $config_content
        );
    } else {
        // Add WP_DEBUG_LOG line after WP_DEBUG
        if (preg_match('/define\(\s*[\'"]WP_DEBUG[\'"]\s*,\s*(?:true|false)\s*\);/i', $config_content)) {
            $config_content = preg_replace(
                '/(define\(\s*[\'"]WP_DEBUG[\'"]\s*,\s*(?:true|false)\s*\);)/i',
                "$1\ndefine('WP_DEBUG_LOG', $debug_log_value);",
                $config_content
            );
        } else {
            // If WP_DEBUG is not defined (shouldn't happen), add it before DB_CHARSET
            $config_content = preg_replace(
                '/(define\(\s*[\'"]DB_CHARSET[\'"]\s*,)/',
                "define('WP_DEBUG_LOG', $debug_log_value);\n$1",
                $config_content
            );
        }
    }
    
    // Write changes back to the file
    if (file_put_contents($wp_config_path, $config_content)) {
        // Clear any opcode cache
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($wp_config_path, true);
        }
        
        wp_send_json_success(array(
            'message' => $enable ? 
                __('WP_DEBUG_LOG enabled successfully. Debug logs will be saved to wp-content/debug.log', 'ccm-tools') : 
                __('WP_DEBUG_LOG disabled successfully.', 'ccm-tools'),
            'status' => $enable ? 'Enabled' : 'Disabled'
        ));
    } else {
        wp_send_json_error(__('Failed to update wp-config.php file.', 'ccm-tools'));
    }
}

/**
 * Update WordPress memory limit
 */
add_action('wp_ajax_ccm_tools_update_memory_limit', 'ccm_tools_ajax_update_memory_limit');
function ccm_tools_ajax_update_memory_limit(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('You do not have permission to perform this action.', 'ccm-tools'));
    }

    $memory_limit = isset($_POST['limit']) ? sanitize_text_field($_POST['limit']) : '40M';
    $default_limit = '40M';
    
    // Validate memory limit
    $valid_limits = array('40M', '64M', '128M', '256M', '512M', '1024M');
    if (!in_array($memory_limit, $valid_limits)) {
        wp_send_json_error(__('Invalid memory limit value.', 'ccm-tools'));
    }
    
    // Get wp-config.php file path
    $wp_config_path = ABSPATH . 'wp-config.php';
    $wp_config_sample_path = ABSPATH . 'wp-config-sample.php';
    
    if (!file_exists($wp_config_path) && file_exists($wp_config_sample_path)) {
        $wp_config_path = $wp_config_sample_path;
    }
    
    if (!file_exists($wp_config_path)) {
        wp_send_json_error(__('wp-config.php file not found.', 'ccm-tools'));
    }
    
    if (!is_writable($wp_config_path)) {
        wp_send_json_error(__('wp-config.php file is not writable. Please check file permissions.', 'ccm-tools'));
    }
    
    // Read the config file
    $config_content = file_get_contents($wp_config_path);
    if ($config_content === false) {
        wp_send_json_error(__('Failed to read wp-config.php file.', 'ccm-tools'));
    }
    
    $original_content = $config_content;
    
    // Patterns for detecting existing memory limit (both commented and uncommented)
    // Pattern 1: Uncommented active define
    $active_memory_limit_pattern = '/^[\t ]*define\s*\(\s*[\'"]WP_MEMORY_LIMIT[\'"]\s*,\s*[\'"][^\'\"]*[\'"]\s*\)\s*;?[\t ]*\r?\n?/im';
    // Pattern 2: Commented out define (// or #)
    $commented_memory_limit_pattern = '/^[\t ]*(?:\/\/|#)[\t ]*define\s*\(\s*[\'"]WP_MEMORY_LIMIT[\'"]\s*,\s*[\'"][^\'\"]*[\'"]\s*\)\s*;?[\t ]*\r?\n?/im';
    
    // Check what exists
    $active_found = preg_match($active_memory_limit_pattern, $config_content);
    $commented_found = preg_match($commented_memory_limit_pattern, $config_content);
    $existing_memory_limit_found = $active_found || $commented_found;
    
    // Check if the memory limit is being set to default
    if ($memory_limit === $default_limit) {
        if ($existing_memory_limit_found) {
            // Remove existing WP_MEMORY_LIMIT line (both commented and uncommented)
            if ($active_found) {
                $config_content = preg_replace($active_memory_limit_pattern, '', $config_content);
            }
            if ($commented_found) {
                $config_content = preg_replace($commented_memory_limit_pattern, '', $config_content);
            }
        } else {
            wp_send_json_success(array(
                'message' => __('WordPress memory limit is already set to default.', 'ccm-tools'),
                'limit' => $memory_limit,
                'reload' => false
            ));
            return;
        }
    } else {
        // Non-default value - add or update the setting
        $new_define_line = "define('WP_MEMORY_LIMIT', '{$memory_limit}');";
        
        if ($existing_memory_limit_found) {
            // Replace existing WP_MEMORY_LIMIT line (handles both commented and uncommented)
            $replaced = false;
            
            // First try to replace active (uncommented) line
            if ($active_found) {
                $new_content = preg_replace($active_memory_limit_pattern, $new_define_line . "\n", $config_content);
                if ($new_content !== $config_content) {
                    $config_content = $new_content;
                    $replaced = true;
                }
            }
            
            // If no active line, replace commented line (uncomment it)
            if (!$replaced && $commented_found) {
                $new_content = preg_replace($commented_memory_limit_pattern, $new_define_line . "\n", $config_content);
                if ($new_content !== $config_content) {
                    $config_content = $new_content;
                    $replaced = true;
                }
            }
            
            if (!$replaced) {
                $existing_memory_limit_found = false; // Fall through to add logic
            }
        }
        
        if (!$existing_memory_limit_found) {
            // Multiple insertion strategies with better pattern matching
            $insertion_patterns = array(
                // Try to insert before "That's all" comment (multiple variations)
                array(
                    'pattern' => '/(\/\*\s*That\'s\s+all,\s+stop\s+editing!\s+Happy\s+(?:blogging|publishing)\.?\s*\*\/)/i',
                    'replacement' => $new_define_line . "\n\n$1",
                    'description' => 'before "That\'s all" comment'
                ),
                // Alternative "That's all" patterns
                array(
                    'pattern' => '/(\/\*\s*That\'s\s+all,\s+stop\s+editing!\s*\*\/)/i',
                    'replacement' => $new_define_line . "\n\n$1",
                    'description' => 'before simplified "That\'s all" comment'
                ),
                // Try to insert before ABSPATH definition
                array(
                    'pattern' => '/(\/\*\*\s*Absolute\s+path\s+to\s+the\s+WordPress\s+directory\.\s*\*\/)/i',
                    'replacement' => $new_define_line . "\n\n$1",
                    'description' => 'before ABSPATH comment'
                ),
                // Try to insert before any ABSPATH define
                array(
                    'pattern' => '/(define\s*\(\s*[\'"]ABSPATH[\'"])/i',
                    'replacement' => $new_define_line . "\n\n$1",
                    'description' => 'before ABSPATH define'
                ),
                // Try to insert before wp-settings.php require
                array(
                    'pattern' => '/(require_once\s*\(\s*ABSPATH\s*\.\s*[\'"]wp-settings\.php[\'"]\s*\))/i',
                    'replacement' => $new_define_line . "\n\n$1",
                    'description' => 'before wp-settings.php require'
                ),
                // Try to insert before closing PHP tag
                array(
                    'pattern' => '/(\s*\?>\s*)$/i',
                    'replacement' => "\n" . $new_define_line . "\n$1",
                    'description' => 'before closing PHP tag'
                ),
                // Try to insert before any ending whitespace/newlines
                array(
                    'pattern' => '/(\s*)$/i',
                    'replacement' => "\n" . $new_define_line . "\n$1",
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
                $config_content .= "\n" . $new_define_line . "\n";
            }
        }
    }
    
    // Check if content actually changed
    if ($original_content === $config_content) {
        wp_send_json_success(array(
            'message' => sprintf(__('WordPress memory limit is already set to %s.', 'ccm-tools'), $memory_limit),
            'limit' => $memory_limit,
            'reload' => false
        ));
        return;
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
        
        // Verify the change was written correctly
        $verification_content = file_get_contents($wp_config_path);
        $verification_successful = false;
        
        if ($memory_limit === $default_limit) {
            // Verify removal - check neither active nor commented exists
            $still_active = preg_match($active_memory_limit_pattern, $verification_content);
            $still_commented = preg_match($commented_memory_limit_pattern, $verification_content);
            $verification_successful = !$still_active && !$still_commented;
        } else {
            // Verify addition/update - must be active (uncommented) with correct value
            $verification_successful = preg_match('/^[\t ]*define\s*\(\s*[\'"]WP_MEMORY_LIMIT[\'"]\s*,\s*[\'"]\s*' . preg_quote($memory_limit, '/') . '\s*[\'"]\s*\)/im', $verification_content);
        }
        
        if ($verification_successful) {
            wp_send_json_success(array(
                'message' => $memory_limit === $default_limit ? 
                    __('WordPress memory limit set to default. The setting has been removed from wp-config.php.', 'ccm-tools') :
                    sprintf(__('WordPress memory limit updated to %s successfully.', 'ccm-tools'), $memory_limit),
                'limit' => $memory_limit,
                'reload' => true
            ));
        } else {
            // Restore backup
            file_put_contents($wp_config_path, $backup_content);
            wp_send_json_error(__('Configuration update failed verification. Changes have been reverted. Please check wp-config.php manually.', 'ccm-tools'));
        }
    } else {
        wp_send_json_error(__('Failed to update wp-config.php file. Please check file permissions.', 'ccm-tools'));
    }
}

/**
 * AJAX handler for Redis configuration
 */
add_action('wp_ajax_ccm_tools_configure_redis', 'ccm_tools_ajax_configure_redis');
function ccm_tools_ajax_configure_redis(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('You do not have permission to perform this action.', 'ccm-tools'));
    }
    
    $result = ccm_tools_add_redis_configuration();
    
    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
    } else {
        wp_send_json_success(array(
            'message' => __('Redis configuration added successfully to wp-config.php.', 'ccm-tools'),
        ));
    }
}

/**
 * AJAX handler to install Redis Cache plugin
 */
add_action('wp_ajax_ccm_tools_install_redis_plugin', 'ccm_tools_ajax_install_redis_plugin');
function ccm_tools_ajax_install_redis_plugin(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('You do not have permission to perform this action.', 'ccm-tools'));
    }
    
    // Check if plugin is already installed
    $plugin_file = 'redis-cache/redis-cache.php';
    if (file_exists(WP_PLUGIN_DIR . '/' . $plugin_file)) {
        // Plugin exists, try to activate it
        $activated = activate_plugin($plugin_file);
        if (is_wp_error($activated)) {
            wp_send_json_error($activated->get_error_message());
        } else {
            wp_send_json_success(array(
                'message' => __('Redis Cache plugin activated successfully.', 'ccm-tools'),
                'reload' => true
            ));
        }
        return;
    }
    
    // Include necessary files for plugin installation
    require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    require_once ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php';
    
    // Use automatic installation API
    $api = plugins_api('plugin_information', array(
        'slug' => 'redis-cache',
        'fields' => array(
            'short_description' => false,
            'sections' => false,
            'requires' => false,
            'rating' => false,
            'ratings' => false,
            'downloaded' => false,
            'last_updated' => false,
            'added' => false,
            'tags' => false,
            'compatibility' => false,
            'homepage' => false,
            'donate_link' => false,
        ),
    ));
    
    if (is_wp_error($api)) {
        wp_send_json_error($api->get_error_message());
        return;
    }
    
    // Use Ajax Skin for silent install
    $skin = new WP_Ajax_Upgrader_Skin();
    $upgrader = new Plugin_Upgrader($skin);
    $result = $upgrader->install($api->download_link);
    
    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
        return;
    }
    
    if (is_wp_error($skin->result)) {
        wp_send_json_error($skin->result->get_error_message());
        return;
    }
    
    if ($skin->get_errors()->has_errors()) {
        wp_send_json_error($skin->get_error_messages());
        return;
    }
    
    if (is_null($result)) {
        global $wp_filesystem;
        wp_send_json_error(__('Unable to connect to the filesystem. Please confirm your credentials.', 'ccm-tools'));
        return;
    }
    
    // Activate plugin after installation
    $activate = activate_plugin($plugin_file);
    if (is_wp_error($activate)) {
        wp_send_json_error($activate->get_error_message());
        return;
    }
    
    wp_send_json_success(array(
        'message' => __('Redis Cache plugin installed and activated successfully.', 'ccm-tools'),
        'reload' => true
    ));
}

/**
 * AJAX handler to enable Redis object cache
 */
add_action('wp_ajax_ccm_tools_enable_redis_cache', 'ccm_tools_ajax_enable_redis_cache');
function ccm_tools_ajax_enable_redis_cache(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('You do not have permission to perform this action.', 'ccm-tools'));
    }
    
    // Check if Redis plugin is active by verifying plugin file exists and is active
    $plugin_file = 'redis-cache/redis-cache.php';
    if (!file_exists(WP_PLUGIN_DIR . '/' . $plugin_file) || !is_plugin_active($plugin_file)) {
        // Instead of error, redirect to Redis plugin settings page if it exists
        if (file_exists(WP_PLUGIN_DIR . '/' . $plugin_file)) {
            wp_send_json_success(array(
                'message' => __('Please enable the Redis Cache plugin first.', 'ccm-tools'),
                'redirect' => admin_url('options-general.php?page=redis-cache')
            ));
            return;
        }
        
        wp_send_json_error(__('Redis Cache plugin is not installed or active.', 'ccm-tools'));
        return;
    }
    
    // Try to enable Redis object cache
    if (!function_exists('wp_cache_flush')) {
        wp_send_json_error(__('Object cache functions not available.', 'ccm-tools'));
        return;
    }
    
    // Try direct access to the Redis plugin class if possible
    if (class_exists('Redis_Object_Cache_Plugin') && method_exists('Redis_Object_Cache_Plugin', 'instance')) {
        // Use the singleton instance method if available
        $plugin_instance = call_user_func(array('Redis_Object_Cache_Plugin', 'instance'));
        if (method_exists($plugin_instance, 'enable')) {
            $result = $plugin_instance->enable();
            if (is_wp_error($result)) {
                wp_send_json_error($result->get_error_message());
            } else {
                wp_send_json_success(array(
                    'message' => __('Redis object cache enabled successfully.', 'ccm-tools'),
                    'reload' => true
                ));
            }
            return;
        }
    }
    
    // Fall back to the plugin's function if it exists
    if (function_exists('wp_redis_enable_cache')) {
        $result = wp_redis_enable_cache();
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        } else {
            wp_send_json_success(array(
                'message' => __('Redis object cache enabled successfully.', 'ccm-tools'),
                'reload' => true
            ));
        }
    } else {
        // Fallback for older versions - try manual file copy
        global $wp_filesystem;
        
        if (!function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        
        // Initialize WP Filesystem
        if (!WP_Filesystem()) {
            wp_send_json_error(__('Unable to access the filesystem. Please check file permissions.', 'ccm-tools'));
            return;
        }
        
        $dropin_path = WP_CONTENT_DIR . '/object-cache.php';
        $redis_dropin = WP_PLUGIN_DIR . '/redis-cache/includes/object-cache.php';
        
        // Try alternate locations if the first one doesn't exist
        if (!file_exists($redis_dropin)) {
            $alternate_paths = array(
                WP_PLUGIN_DIR . '/redis-cache/object-cache.php',
                WP_PLUGIN_DIR . '/redis-cache/assets/object-cache.php',
            );
            
            foreach ($alternate_paths as $path) {
                if (file_exists($path)) {
                    $redis_dropin = $path;
                    break;
                }
            }
        }
        
        if (!file_exists($redis_dropin)) {
            wp_send_json_success(array(
                'message' => __('Redis Cache plugin found but unable to locate object cache file. Please use the plugin settings page.', 'ccm-tools'),
                'redirect' => admin_url('options-general.php?page=redis-cache')
            ));
            return;
        }
        
        // Copy the object cache dropin file
        if (!$wp_filesystem->copy($redis_dropin, $dropin_path, true)) {
            wp_send_json_error(__('Could not copy object cache file. Please check file permissions.', 'ccm-tools'));
            return;
        }
        
        wp_cache_flush();
        
        wp_send_json_success(array(
            'message' => __('Redis object cache enabled successfully.', 'ccm-tools'),
            'reload' => true
        ));
    }
}

/**
 * AJAX handler to disable Redis object cache
 */
add_action('wp_ajax_ccm_tools_disable_redis_cache', 'ccm_tools_ajax_disable_redis_cache');
function ccm_tools_ajax_disable_redis_cache(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('You do not have permission to perform this action.', 'ccm-tools'));
    }
    
    // Initialize wp-admin includes for plugin functions
    if (!function_exists('is_plugin_active')) {
        include_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    
    // Check if Redis plugin is active
    $plugin_file = 'redis-cache/redis-cache.php';
    if (!file_exists(WP_PLUGIN_DIR . '/' . $plugin_file) || !is_plugin_active($plugin_file)) {
        wp_send_json_error(__('Redis Cache plugin is not active.', 'ccm-tools'));
        return;
    }
    
    // Try to disable Redis object cache using different available methods
    $success = false;
    
    // Method 1: Using the plugin's function
    if (function_exists('wp_redis_disable_cache')) {
        $result = wp_redis_disable_cache();
        if (!is_wp_error($result)) {
            $success = true;
        }
    }
    
    // Method 2: Using the plugin's class if available
    if (!$success && class_exists('Redis_Object_Cache_Plugin') && method_exists('Redis_Object_Cache_Plugin', 'instance')) {
        $plugin_instance = call_user_func(array('Redis_Object_Cache_Plugin', 'instance'));
        if (method_exists($plugin_instance, 'disable')) {
            $result = $plugin_instance->disable();
            if (!is_wp_error($result)) {
                $success = true;
            }
        }
    }
    
    // Method 3: Manual file deletion as a last resort
    if (!$success) {
        global $wp_filesystem;
        
        if (!function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        
        if (!function_exists('request_filesystem_credentials')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        
        // Initialize WP Filesystem
        $credentials = request_filesystem_credentials('');
        if (WP_Filesystem($credentials)) {
            $dropin_path = WP_CONTENT_DIR . '/object-cache.php';
            
            if (file_exists($dropin_path)) {
                if ($wp_filesystem->delete($dropin_path)) {
                    $success = true;
                }
            } else {
                // If the file doesn't exist, consider it a success
                $success = true;
            }
        }
    }
    
    // Final result
    if ($success) {
        // Clear the cache after disabling
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
        
        wp_send_json_success(array(
            'message' => __('Redis object cache disabled successfully.', 'ccm-tools'),
            'reload' => true
        ));
    } else {
        wp_send_json_error(__('Failed to disable Redis object cache. Please try to delete the object-cache.php file manually from your wp-content directory.', 'ccm-tools'));
    }
}

/**
 * AJAX handler to measure TTFB with enhanced accuracy
 */
add_action('wp_ajax_ccm_tools_measure_ttfb', 'ccm_tools_ajax_measure_ttfb');
function ccm_tools_ajax_measure_ttfb(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('You do not have permission to perform this action.', 'ccm-tools'));
    }
    
    // Clear WordPress internal cache first
    wp_cache_flush();
    
    // Always use enhanced measurement for best accuracy
    $ttfb = ccm_tools_measure_ttfb_enhanced();
    
    // Format the result HTML
    $result_html = '';
    if ($ttfb['success']) {
        // Determine performance class based on TTFB value
        $ttfb_class = 'ccm-success';
        $ttfb_label = __('Fast', 'ccm-tools');
        
        if ($ttfb['time'] > 1800) {
            $ttfb_class = 'ccm-error';
            $ttfb_label = __('Slow', 'ccm-tools');
        } elseif ($ttfb['time'] > 800) {
            $ttfb_class = 'ccm-warning';
            $ttfb_label = __('Average', 'ccm-tools');
        }
        
        $result_html = sprintf(
            '<span class="%s">%s %s</span> <span class="ccm-note">(%s)</span>',
            esc_attr($ttfb_class),
            esc_html($ttfb['time']),
            esc_html($ttfb['unit']),
            esc_html($ttfb_label)
        );
        
        // Add measurement details if available
        if (isset($ttfb['measurement_note'])) {
            $result_html .= '<br><small class="ccm-note">' . esc_html($ttfb['measurement_note']) . '</small>';
        }
        
        // Add individual times for enhanced measurements
        if (isset($ttfb['individual_times']) && !empty($ttfb['individual_times'])) {
            $times_text = implode('ms, ', $ttfb['individual_times']) . 'ms';
            $result_html .= '<br><small class="ccm-note">Individual: ' . esc_html($times_text) . '</small>';
        }
        
        // Send the response with TTFB data
        wp_send_json_success(array(
            'html' => $result_html,
            'time' => $ttfb['time'],
            'unit' => $ttfb['unit'],
            'performance' => $ttfb_label,
            'performance_class' => $ttfb_class,
            'measurement_type' => 'enhanced',
            'individual_times' => isset($ttfb['individual_times']) ? $ttfb['individual_times'] : array(),
            'measurement_note' => isset($ttfb['measurement_note']) ? $ttfb['measurement_note'] : '',
            'timestamp' => time()
        ));
    } else {
        $result_html = '<span class="ccm-error">' . __('Measurement failed', 'ccm-tools') . '</span>';
        if (!empty($ttfb['error'])) {
            $result_html .= ' <small class="ccm-note">' . esc_html($ttfb['error']) . '</small>';
        }
        
        // Send the response with error information
        wp_send_json_success(array(
            'html' => $result_html,
            'error' => $ttfb['error'],
            'measurement_type' => 'enhanced',
            'timestamp' => time()
        ));
    }
}

// WooCommerce Tools - Toggle Admin Payment Methods
add_action('wp_ajax_ccm_toggle_admin_payment', 'ccm_tools_ajax_toggle_admin_payment');
function ccm_tools_ajax_toggle_admin_payment(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'ccm-tools')));
    }
    
    // Check if WooCommerce is active
    if (!ccm_tools_is_woocommerce_active()) {
        wp_send_json_error(array('message' => __('WooCommerce is not active.', 'ccm-tools')));
    }
    
    $enabled = isset($_POST['enabled']) && $_POST['enabled'] === 'true';
    $new_value = $enabled ? 'yes' : 'no';
    
    update_option('ccm_woo_admin_payment_enabled', $new_value);
    
    $message = $enabled 
        ? __('Admin-only payment methods enabled successfully. COD and Bank Transfer are now restricted to administrators only.', 'ccm-tools')
        : __('Admin-only payment methods disabled successfully. COD and Bank Transfer are now available to all customers.', 'ccm-tools');
    
    wp_send_json_success(array(
        'message' => $message,
        'enabled' => $enabled
    ));
}

// ==========================================
// WebP Converter AJAX Handlers
// ==========================================

// Save WebP Settings
add_action('wp_ajax_ccm_tools_save_webp_settings', 'ccm_tools_ajax_save_webp_settings');
function ccm_tools_ajax_save_webp_settings(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'ccm-tools')));
    }
    
    $settings = array(
        'enabled' => isset($_POST['enabled']) && $_POST['enabled'] === '1',
        'quality' => isset($_POST['quality']) ? max(1, min(100, intval($_POST['quality']))) : 82,
        'convert_on_upload' => isset($_POST['convert_on_upload']) && $_POST['convert_on_upload'] === '1',
        'serve_webp' => isset($_POST['serve_webp']) && $_POST['serve_webp'] === '1',
        'convert_on_demand' => isset($_POST['convert_on_demand']) && $_POST['convert_on_demand'] === '1',
        'use_picture_tags' => isset($_POST['use_picture_tags']) && $_POST['use_picture_tags'] === '1',
        'convert_bg_images' => isset($_POST['convert_bg_images']) && $_POST['convert_bg_images'] === '1',
        'keep_originals' => isset($_POST['keep_originals']) && $_POST['keep_originals'] === '1',
        'preferred_extension' => isset($_POST['preferred_extension']) ? sanitize_text_field($_POST['preferred_extension']) : 'auto'
    );
    
    if (ccm_tools_webp_save_settings($settings)) {
        wp_send_json_success(array(
            'message' => __('WebP settings saved successfully.', 'ccm-tools'),
            'settings' => $settings
        ));
    } else {
        wp_send_json_error(array('message' => __('Failed to save WebP settings.', 'ccm-tools')));
    }
}

// Get WebP Statistics
add_action('wp_ajax_ccm_tools_get_webp_stats', 'ccm_tools_ajax_get_webp_stats');
function ccm_tools_ajax_get_webp_stats(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'ccm-tools')));
    }
    
    $stats = ccm_tools_webp_get_statistics();
    wp_send_json_success($stats);
}

// Get unconverted images for bulk conversion
add_action('wp_ajax_ccm_tools_get_unconverted_images', 'ccm_tools_ajax_get_unconverted_images');
function ccm_tools_ajax_get_unconverted_images(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'ccm-tools')));
    }
    
    global $wpdb;
    
    $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
    $limit = isset($_POST['limit']) ? min(50, max(1, intval($_POST['limit']))) : 10;
    
    // Get images that haven't been converted yet
    $images = $wpdb->get_results($wpdb->prepare(
        "SELECT p.ID, p.post_title, p.guid 
         FROM {$wpdb->posts} p
         LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_ccm_webp_converted'
         WHERE p.post_type = 'attachment' 
         AND p.post_mime_type IN ('image/jpeg', 'image/png', 'image/gif')
         AND pm.meta_id IS NULL
         ORDER BY p.ID ASC
         LIMIT %d OFFSET %d",
        $limit,
        $offset
    ));
    
    // Get total count
    $total = (int) $wpdb->get_var(
        "SELECT COUNT(*) 
         FROM {$wpdb->posts} p
         LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_ccm_webp_converted'
         WHERE p.post_type = 'attachment' 
         AND p.post_mime_type IN ('image/jpeg', 'image/png', 'image/gif')
         AND pm.meta_id IS NULL"
    );
    
    $image_list = array();
    foreach ($images as $image) {
        $image_list[] = array(
            'id' => $image->ID,
            'title' => $image->post_title,
            'url' => wp_get_attachment_url($image->ID)
        );
    }
    
    wp_send_json_success(array(
        'images' => $image_list,
        'total' => $total,
        'offset' => $offset,
        'limit' => $limit
    ));
}

// Convert single image to WebP (for bulk conversion)
add_action('wp_ajax_ccm_tools_convert_single_image', 'ccm_tools_ajax_convert_single_image');
function ccm_tools_ajax_convert_single_image(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'ccm-tools')));
    }
    
    $attachment_id = isset($_POST['attachment_id']) ? intval($_POST['attachment_id']) : 0;
    
    if (!$attachment_id) {
        wp_send_json_error(array('message' => __('No attachment ID provided.', 'ccm-tools')));
    }
    
    // Get attachment file path
    $file_path = get_attached_file($attachment_id);
    
    if (!$file_path || !file_exists($file_path)) {
        wp_send_json_error(array('message' => __('Attachment file not found.', 'ccm-tools')));
    }
    
    // Check if it's an image type we can convert
    $mime_type = get_post_mime_type($attachment_id);
    $allowed_mimes = array('image/jpeg', 'image/png', 'image/gif');
    
    if (!in_array($mime_type, $allowed_mimes)) {
        wp_send_json_error(array('message' => __('Invalid image type for conversion.', 'ccm-tools')));
    }
    
    // Get settings
    $settings = ccm_tools_webp_get_settings();
    $quality = intval($settings['quality']);
    $extension = $settings['preferred_extension'];
    
    $converted_files = array();
    $total_source_size = 0;
    $total_dest_size = 0;
    
    // Convert the main file
    $main_result = ccm_tools_webp_convert_image($file_path, '', $quality, $extension);
    if ($main_result['success']) {
        $converted_files['full'] = $main_result;
        $total_source_size += $main_result['source_size'];
        $total_dest_size += $main_result['dest_size'];
    }
    
    // Convert all generated sizes
    $metadata = wp_get_attachment_metadata($attachment_id);
    if (!empty($metadata['sizes'])) {
        $file_dir = dirname($file_path);
        
        foreach ($metadata['sizes'] as $size_name => $size_data) {
            $size_file_path = $file_dir . '/' . $size_data['file'];
            
            if (file_exists($size_file_path)) {
                $size_result = ccm_tools_webp_convert_image($size_file_path, '', $quality, $extension);
                if ($size_result['success']) {
                    $converted_files[$size_name] = $size_result;
                    $total_source_size += $size_result['source_size'];
                    $total_dest_size += $size_result['dest_size'];
                }
            }
        }
    }
    
    // Store conversion info as post meta
    if (!empty($converted_files)) {
        update_post_meta($attachment_id, '_ccm_webp_converted', $converted_files);
        
        $savings_percent = 0;
        if ($total_source_size > 0) {
            $savings_percent = round((($total_source_size - $total_dest_size) / $total_source_size) * 100, 1);
        }
        
        wp_send_json_success(array(
            'attachment_id' => $attachment_id,
            'converted_count' => count($converted_files),
            'source_size' => $total_source_size,
            'dest_size' => $total_dest_size,
            'savings_percent' => $savings_percent,
            'extension_used' => $main_result['extension_used'] ?? 'unknown',
            'message' => sprintf(
                __('Converted %d file(s), saved %s (%s%% reduction)', 'ccm-tools'),
                count($converted_files),
                size_format($total_source_size - $total_dest_size),
                $savings_percent
            )
        ));
    } else {
        wp_send_json_error(array(
            'message' => $main_result['message'] ?? __('Conversion failed.', 'ccm-tools')
        ));
    }
}

// Test WebP conversion with uploaded file
add_action('wp_ajax_ccm_tools_test_webp_conversion', 'ccm_tools_ajax_test_webp_conversion');
function ccm_tools_ajax_test_webp_conversion(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'ccm-tools')));
    }
    
    if (empty($_FILES['test_image'])) {
        wp_send_json_error(array('message' => __('No test image uploaded.', 'ccm-tools')));
    }
    
    $file = $_FILES['test_image'];
    
    // Validate file type
    $allowed_types = array('image/jpeg', 'image/png', 'image/gif');
    if (!in_array($file['type'], $allowed_types)) {
        wp_send_json_error(array('message' => __('Invalid file type. Please upload a JPG, PNG, or GIF image.', 'ccm-tools')));
    }
    
    // Get upload directory for temp storage
    $upload_dir = wp_upload_dir();
    $temp_dir = $upload_dir['basedir'] . '/ccm-webp-test/';
    
    // Create temp directory if it doesn't exist
    if (!file_exists($temp_dir)) {
        wp_mkdir_p($temp_dir);
    }
    
    // Generate unique filename
    $filename = 'test-' . time() . '-' . sanitize_file_name($file['name']);
    $source_path = $temp_dir . $filename;
    
    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $source_path)) {
        wp_send_json_error(array('message' => __('Failed to save uploaded file.', 'ccm-tools')));
    }
    
    // Get settings
    $settings = ccm_tools_webp_get_settings();
    $quality = intval($settings['quality']);
    $extension = $settings['preferred_extension'];
    
    // Perform conversion
    $result = ccm_tools_webp_convert_image($source_path, '', $quality, $extension);
    
    // Get image dimensions
    $image_info = getimagesize($source_path);
    $width = $image_info[0] ?? 0;
    $height = $image_info[1] ?? 0;
    
    // Clean up temp files
    if (file_exists($source_path)) {
        unlink($source_path);
    }
    if ($result['success'] && file_exists($result['dest_path'])) {
        unlink($result['dest_path']);
    }
    
    if ($result['success']) {
        wp_send_json_success(array(
            'message' => $result['message'],
            'source_size' => size_format($result['source_size']),
            'dest_size' => size_format($result['dest_size']),
            'savings_percent' => $result['savings_percent'],
            'extension_used' => $result['extension_used'],
            'quality' => $quality,
            'dimensions' => $width . 'x' . $height
        ));
    } else {
        wp_send_json_error(array('message' => $result['message']));
    }
}

// Reset WebP conversions for regeneration
add_action('wp_ajax_ccm_tools_reset_webp_conversions', 'ccm_tools_ajax_reset_webp_conversions');
function ccm_tools_ajax_reset_webp_conversions(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'ccm-tools')));
    }
    
    global $wpdb;
    
    $delete_files = !empty($_POST['delete_files']);
    
    // Get all attachments with WebP conversions
    $attachments = $wpdb->get_results(
        "SELECT p.ID, pm.meta_value 
         FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_ccm_webp_converted'
         WHERE p.post_type = 'attachment'"
    );
    
    $deleted_files = 0;
    $reset_count = 0;
    
    foreach ($attachments as $attachment) {
        // Optionally delete WebP files
        if ($delete_files) {
            $conversion_data = maybe_unserialize($attachment->meta_value);
            if (is_array($conversion_data)) {
                foreach ($conversion_data as $size => $data) {
                    if (!empty($data['dest_path']) && file_exists($data['dest_path'])) {
                        if (unlink($data['dest_path'])) {
                            $deleted_files++;
                        }
                    }
                }
            }
        }
        
        // Remove the conversion meta
        delete_post_meta($attachment->ID, '_ccm_webp_converted');
        $reset_count++;
    }
    
    // Also clear the failed conversion cache
    $wpdb->query(
        "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_ccm_webp_conversion_failed'"
    );
    
    wp_send_json_success(array(
        'message' => sprintf(
            __('Reset %d images for re-conversion. %d WebP files deleted.', 'ccm-tools'),
            $reset_count,
            $deleted_files
        ),
        'reset_count' => $reset_count,
        'deleted_files' => $deleted_files
    ));
}

/**
 * Process background WebP conversion queue
 * This is called via AJAX from the frontend to convert queued images
 */
add_action('wp_ajax_ccm_tools_process_webp_queue', 'ccm_tools_ajax_process_webp_queue');
add_action('wp_ajax_nopriv_ccm_tools_process_webp_queue', 'ccm_tools_ajax_process_webp_queue');
function ccm_tools_ajax_process_webp_queue(): void {
    // No nonce check - this is a background process that should work for all visitors
    // Rate limiting prevents abuse
    
    $queue = get_transient('ccm_webp_conversion_queue');
    
    if (empty($queue) || !is_array($queue)) {
        wp_send_json_success(array('processed' => 0, 'remaining' => 0));
        return;
    }
    
    $settings = ccm_tools_webp_get_settings();
    
    if (empty($settings['enabled']) || empty($settings['convert_on_demand'])) {
        wp_send_json_success(array('processed' => 0, 'remaining' => count($queue), 'disabled' => true));
        return;
    }
    
    $quality = intval($settings['quality']);
    $extension = $settings['preferred_extension'];
    
    // Process up to 5 images per request (faster with optimized settings)
    $batch_size = 5;
    $processed = 0;
    $processed_items = array();
    
    foreach ($queue as $key => $item) {
        if ($processed >= $batch_size) {
            break;
        }
        
        // Skip if already converted
        if (file_exists($item['webp_path'])) {
            $processed_items[] = $key;
            continue;
        }
        
        // Skip if source doesn't exist
        if (!file_exists($item['source_path'])) {
            $processed_items[] = $key;
            continue;
        }
        
        // Perform conversion
        $result = ccm_tools_webp_convert_image($item['source_path'], $item['webp_path'], $quality, $extension);
        
        if ($result['success']) {
            // Try to update attachment meta
            $upload_dir = wp_upload_dir();
            $relative_path = str_replace($upload_dir['basedir'] . '/', '', $item['source_path']);
            
            global $wpdb;
            $attachment_id = $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s",
                $relative_path
            ));
            
            if ($attachment_id) {
                $converted = get_post_meta($attachment_id, '_ccm_webp_converted', true);
                if (!is_array($converted)) {
                    $converted = array();
                }
                $converted['background'] = array(
                    'success' => true,
                    'source_path' => $item['source_path'],
                    'dest_path' => $item['webp_path'],
                    'source_size' => $result['source_size'],
                    'dest_size' => $result['dest_size'],
                    'converted_at' => current_time('mysql')
                );
                update_post_meta($attachment_id, '_ccm_webp_converted', $converted);
            }
        } else {
            // Mark as failed
            $failed_key = 'ccm_webp_failed_' . md5($item['source_path']);
            set_transient($failed_key, true, HOUR_IN_SECONDS);
        }
        
        $processed_items[] = $key;
        $processed++;
    }
    
    // Remove processed items from queue
    foreach ($processed_items as $key) {
        unset($queue[$key]);
    }
    
    // Update or delete queue
    if (empty($queue)) {
        delete_transient('ccm_webp_conversion_queue');
    } else {
        set_transient('ccm_webp_conversion_queue', $queue, 3600);
    }
    
    wp_send_json_success(array(
        'processed' => $processed,
        'remaining' => count($queue)
    ));
}

// ===================================
// Performance Optimizer AJAX Handlers
// ===================================

/**
 * Save performance optimizer settings
 */
add_action('wp_ajax_ccm_tools_save_perf_settings', 'ccm_tools_ajax_save_perf_settings');
function ccm_tools_ajax_save_perf_settings(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'ccm-tools')));
    }
    
    // Sanitize and validate settings
    $settings = array(
        'enabled' => !empty($_POST['enabled']),
        'defer_js' => !empty($_POST['defer_js']),
        'defer_js_excludes' => ccm_tools_perf_sanitize_list($_POST['defer_js_excludes'] ?? ''),
        'delay_js' => !empty($_POST['delay_js']),
        'delay_js_timeout' => absint($_POST['delay_js_timeout'] ?? 0),
        'delay_js_excludes' => ccm_tools_perf_sanitize_list($_POST['delay_js_excludes'] ?? ''),
        'preload_css' => !empty($_POST['preload_css']),
        'preconnect' => !empty($_POST['preconnect']),
        'preconnect_urls' => ccm_tools_perf_sanitize_urls($_POST['preconnect_urls'] ?? ''),
        'dns_prefetch' => !empty($_POST['dns_prefetch']),
        'dns_prefetch_urls' => ccm_tools_perf_sanitize_urls($_POST['dns_prefetch_urls'] ?? ''),
        'lcp_fetchpriority' => !empty($_POST['lcp_fetchpriority']),
        'lcp_preload' => !empty($_POST['lcp_preload']),
        'lcp_preload_url' => esc_url_raw($_POST['lcp_preload_url'] ?? ''),
        'remove_query_strings' => !empty($_POST['remove_query_strings']),
        'disable_emoji' => !empty($_POST['disable_emoji']),
        'disable_dashicons' => !empty($_POST['disable_dashicons']),
        'lazy_load_iframes' => !empty($_POST['lazy_load_iframes']),
        'youtube_facade' => !empty($_POST['youtube_facade']),
    );
    
    // Save settings - update_option returns false if value unchanged, so we check if option exists
    ccm_tools_perf_save_settings($settings);
    
    // Always return success since we processed the request
    wp_send_json_success(array(
        'message' => __('Performance settings saved successfully.', 'ccm-tools'),
        'settings' => $settings
    ));
}

/**
 * Get performance optimizer settings
 */
add_action('wp_ajax_ccm_tools_get_perf_settings', 'ccm_tools_ajax_get_perf_settings');
function ccm_tools_ajax_get_perf_settings(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'ccm-tools')));
    }
    
    $settings = ccm_tools_perf_get_settings();
    wp_send_json_success($settings);
}

/**
 * Sanitize comma-separated list of handles
 * 
 * @param string $input Comma-separated list
 * @return array Array of sanitized handles
 */
function ccm_tools_perf_sanitize_list($input) {
    if (empty($input)) {
        return array();
    }
    
    $items = explode(',', $input);
    $sanitized = array();
    
    foreach ($items as $item) {
        $item = sanitize_key(trim($item));
        if (!empty($item)) {
            $sanitized[] = $item;
        }
    }
    
    return array_unique($sanitized);
}

/**
 * Sanitize newline-separated list of URLs
 * 
 * @param string $input Newline-separated URLs
 * @return array Array of sanitized URLs
 */
function ccm_tools_perf_sanitize_urls($input) {
    if (empty($input)) {
        return array();
    }
    
    $lines = explode("\n", $input);
    $sanitized = array();
    
    foreach ($lines as $line) {
        $url = esc_url_raw(trim($line));
        if (!empty($url)) {
            $sanitized[] = $url;
        }
    }
    
    return array_unique($sanitized);
}

/**
 * Detect scripts on the homepage and categorize them for defer/exclude recommendations
 */
add_action('wp_ajax_ccm_tools_detect_scripts', 'ccm_tools_ajax_detect_scripts');
function ccm_tools_ajax_detect_scripts(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'ccm-tools')));
    }
    
    // Get the site URL to fetch
    $site_url = home_url('/');
    $site_host = wp_parse_url($site_url, PHP_URL_HOST);
    
    // Fetch the homepage
    $response = wp_remote_get($site_url, array(
        'timeout' => 30,
        'sslverify' => false,
        'user-agent' => 'CCM-Tools Script Detector',
    ));
    
    if (is_wp_error($response)) {
        wp_send_json_error(array(
            'message' => __('Failed to fetch homepage: ', 'ccm-tools') . $response->get_error_message()
        ));
    }
    
    $html = wp_remote_retrieve_body($response);
    
    if (empty($html)) {
        wp_send_json_error(array('message' => __('Empty response from homepage.', 'ccm-tools')));
    }
    
    $scripts = array();
    
    // Find all script tags with src attribute
    if (preg_match_all('/<script[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $html, $matches)) {
        foreach ($matches[1] as $index => $src) {
            $full_tag = $matches[0][$index];
            
            // Skip if already has defer or async
            $has_defer = stripos($full_tag, 'defer') !== false;
            $has_async = stripos($full_tag, 'async') !== false;
            
            // Extract script handle/identifier from URL
            $handle = ccm_tools_extract_script_handle($src);
            
            $scripts[] = array(
                'src' => $src,
                'handle' => $handle,
                'has_defer' => $has_defer,
                'has_async' => $has_async,
            );
        }
    }
    
    // Categorize scripts
    $categorized = array(
        'wp_core' => array(),      // WordPress core - DO NOT defer
        'jquery' => array(),       // jQuery family - DO NOT defer
        'theme' => array(),        // Theme scripts - usually safe
        'plugins' => array(),      // Plugin scripts - usually safe
        'third_party' => array(),  // External CDN - usually safe
        'other' => array(),        // Unknown - needs testing
    );
    
    // Patterns for categorization
    $wp_core_patterns = array('wp-includes', 'wp-admin', 'wp-embed', 'wp-polyfill', 'wp-hooks', 'wp-i18n', 'wp-a11y', 'wp-dom-ready');
    $jquery_patterns = array('jquery', 'jquery-core', 'jquery-migrate', 'jquery-ui');
    
    foreach ($scripts as &$script) {
        $src = $script['src'];
        $handle = $script['handle'];
        $src_lower = strtolower($src);
        $handle_lower = strtolower($handle);
        
        // Determine category
        $category = 'other';
        $safe_to_defer = true;
        $reason = '';
        
        // Check jQuery first (highest priority to exclude)
        foreach ($jquery_patterns as $pattern) {
            if (strpos($src_lower, $pattern) !== false || strpos($handle_lower, $pattern) !== false) {
                $category = 'jquery';
                $safe_to_defer = false;
                $reason = 'jQuery must load synchronously - many scripts depend on it';
                break;
            }
        }
        
        // Check WordPress core
        if ($category === 'other') {
            foreach ($wp_core_patterns as $pattern) {
                if (strpos($src_lower, $pattern) !== false) {
                    $category = 'wp_core';
                    $safe_to_defer = false;
                    $reason = 'WordPress core scripts have inline dependencies';
                    break;
                }
            }
        }
        
        // Check if third-party (different host)
        if ($category === 'other') {
            $script_host = wp_parse_url($src, PHP_URL_HOST);
            if ($script_host && $script_host !== $site_host) {
                $category = 'third_party';
                $safe_to_defer = true;
                $reason = 'External script - usually safe to defer';
            }
        }
        
        // Check if theme
        if ($category === 'other') {
            if (strpos($src_lower, '/themes/') !== false) {
                $category = 'theme';
                $safe_to_defer = true;
                $reason = 'Theme script - test after deferring';
            }
        }
        
        // Check if plugin
        if ($category === 'other') {
            if (strpos($src_lower, '/plugins/') !== false) {
                $category = 'plugins';
                $safe_to_defer = true;
                $reason = 'Plugin script - test after deferring';
            }
        }
        
        // Default unknown
        if ($category === 'other') {
            $safe_to_defer = true;
            $reason = 'Unknown origin - test after deferring';
        }
        
        $script['category'] = $category;
        $script['safe_to_defer'] = $safe_to_defer;
        $script['reason'] = $reason;
        
        $categorized[$category][] = $script;
    }
    
    // Count stats
    $total = count($scripts);
    $already_deferred = count(array_filter($scripts, function($s) { return $s['has_defer'] || $s['has_async']; }));
    $safe_count = count(array_filter($scripts, function($s) { return $s['safe_to_defer']; }));
    $exclude_count = count(array_filter($scripts, function($s) { return !$s['safe_to_defer']; }));
    
    wp_send_json_success(array(
        'scripts' => $scripts,
        'categorized' => $categorized,
        'stats' => array(
            'total' => $total,
            'already_deferred' => $already_deferred,
            'safe_to_defer' => $safe_count,
            'should_exclude' => $exclude_count,
        ),
        'site_host' => $site_host,
    ));
}

/**
 * Extract a readable handle/identifier from a script URL
 */
function ccm_tools_extract_script_handle($src) {
    // Remove query strings
    $src = strtok($src, '?');
    
    // Get filename without extension
    $filename = basename($src);
    $handle = pathinfo($filename, PATHINFO_FILENAME);
    
    // Remove common suffixes
    $handle = preg_replace('/[._-]?(min|bundle|packed|dist)$/i', '', $handle);
    
    // If the handle is very generic, try to get more context from path
    $generic_names = array('index', 'main', 'app', 'script', 'scripts', 'frontend', 'public');
    if (in_array(strtolower($handle), $generic_names)) {
        // Try to get parent folder name
        $path_parts = explode('/', trim(wp_parse_url($src, PHP_URL_PATH), '/'));
        if (count($path_parts) >= 2) {
            $parent = $path_parts[count($path_parts) - 2];
            if (!in_array(strtolower($parent), array('js', 'scripts', 'assets', 'dist', 'build'))) {
                $handle = $parent . '-' . $handle;
            }
        }
    }
    
    return $handle;
}

/**
 * Detect external origins by fetching the site's homepage
 * Uses wp_remote_get to fetch the page and parses for external resources
 */
add_action('wp_ajax_ccm_tools_detect_external_origins', 'ccm_tools_ajax_detect_external_origins');
function ccm_tools_ajax_detect_external_origins(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'ccm-tools')));
    }
    
    // Get the site URL to fetch
    $site_url = home_url('/');
    $site_host = wp_parse_url($site_url, PHP_URL_HOST);
    
    // Fetch the homepage
    $response = wp_remote_get($site_url, array(
        'timeout' => 30,
        'sslverify' => false,
        'user-agent' => 'CCM-Tools External Origin Detector',
    ));
    
    if (is_wp_error($response)) {
        wp_send_json_error(array(
            'message' => __('Failed to fetch homepage: ', 'ccm-tools') . $response->get_error_message()
        ));
    }
    
    $html = wp_remote_retrieve_body($response);
    
    if (empty($html)) {
        wp_send_json_error(array('message' => __('Empty response from homepage.', 'ccm-tools')));
    }
    
    $external_origins = array();
    
    // Patterns to find external URLs
    // Match src="...", href="...", url(...), data-src="...", srcset="..."
    $patterns = array(
        '/\s(?:src|href|data-src)=["\']?(https?:\/\/[^"\'>\s]+)["\']?/i',
        '/url\s*\(\s*["\']?(https?:\/\/[^"\')\s]+)["\']?\s*\)/i',
        '/srcset=["\']([^"\']+)["\']/i',
    );
    
    foreach ($patterns as $pattern) {
        if (preg_match_all($pattern, $html, $matches)) {
            foreach ($matches[1] as $url) {
                // For srcset, we might have multiple URLs
                if (strpos($url, ',') !== false || strpos($url, ' ') !== false) {
                    // Parse srcset format: "url1 1x, url2 2x"
                    $srcset_parts = preg_split('/,\s*/', $url);
                    foreach ($srcset_parts as $part) {
                        $parts = preg_split('/\s+/', trim($part));
                        if (!empty($parts[0]) && filter_var($parts[0], FILTER_VALIDATE_URL)) {
                            $parsed = wp_parse_url($parts[0]);
                            if (!empty($parsed['host']) && $parsed['host'] !== $site_host) {
                                $origin = $parsed['scheme'] . '://' . $parsed['host'];
                                $external_origins[$origin] = true;
                            }
                        }
                    }
                } else {
                    // Regular URL
                    $parsed = wp_parse_url($url);
                    if (!empty($parsed['host']) && $parsed['host'] !== $site_host) {
                        $origin = $parsed['scheme'] . '://' . $parsed['host'];
                        $external_origins[$origin] = true;
                    }
                }
            }
        }
    }
    
    // Also check for preconnect/dns-prefetch that might already be in the page
    if (preg_match_all('/<link[^>]+rel=["\'](?:preconnect|dns-prefetch)["\'][^>]+href=["\']([^"\']+)["\']/', $html, $link_matches)) {
        foreach ($link_matches[1] as $url) {
            $parsed = wp_parse_url($url);
            if (!empty($parsed['host']) && $parsed['host'] !== $site_host) {
                $origin = (isset($parsed['scheme']) ? $parsed['scheme'] : 'https') . '://' . $parsed['host'];
                $external_origins[$origin] = true;
            }
        }
    }
    
    // Sort origins alphabetically
    $origins = array_keys($external_origins);
    sort($origins);
    
    // Categorize origins for better UX
    $categorized = array(
        'fonts' => array(),
        'analytics' => array(),
        'cdn' => array(),
        'social' => array(),
        'other' => array(),
    );
    
    foreach ($origins as $origin) {
        $host = wp_parse_url($origin, PHP_URL_HOST);
        
        // Fonts
        if (strpos($host, 'fonts.') !== false || strpos($host, 'font') !== false || strpos($host, 'typekit') !== false) {
            $categorized['fonts'][] = $origin;
        }
        // Analytics
        elseif (strpos($host, 'google-analytics') !== false || strpos($host, 'googletagmanager') !== false || 
                strpos($host, 'analytics') !== false || strpos($host, 'gtm') !== false ||
                strpos($host, 'hotjar') !== false || strpos($host, 'clarity') !== false) {
            $categorized['analytics'][] = $origin;
        }
        // CDN
        elseif (strpos($host, 'cdn') !== false || strpos($host, 'cloudflare') !== false || 
                strpos($host, 'jsdelivr') !== false || strpos($host, 'unpkg') !== false ||
                strpos($host, 'cdnjs') !== false || strpos($host, 'bootstrapcdn') !== false) {
            $categorized['cdn'][] = $origin;
        }
        // Social
        elseif (strpos($host, 'facebook') !== false || strpos($host, 'twitter') !== false ||
                strpos($host, 'instagram') !== false || strpos($host, 'linkedin') !== false ||
                strpos($host, 'pinterest') !== false || strpos($host, 'youtube') !== false) {
            $categorized['social'][] = $origin;
        }
        // Other
        else {
            $categorized['other'][] = $origin;
        }
    }
    
    wp_send_json_success(array(
        'origins' => $origins,
        'categorized' => $categorized,
        'count' => count($origins),
        'site_host' => $site_host,
    ));
}

// ===================================
// Uploads Backup AJAX Handlers
// ===================================

/**
 * Check if ZipArchive is available
 */
add_action('wp_ajax_ccm_tools_check_zip_available', 'ccm_tools_ajax_check_zip_available');
function ccm_tools_ajax_check_zip_available(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'ccm-tools')));
    }
    
    // Check for zip extension - try both methods
    $available = class_exists('ZipArchive') || extension_loaded('zip');
    
    // Get uploads info
    $upload_dir = wp_upload_dir();
    $uploads_path = $upload_dir['basedir'];
    $uploads_size = 0;
    $file_count = 0;
    
    if (is_dir($uploads_path)) {
        // Get size and count in one pass for efficiency
        $stats = ccm_tools_get_directory_stats($uploads_path);
        $uploads_size = $stats['size'];
        $file_count = $stats['count'];
    }
    
    wp_send_json_success(array(
        'zip_available' => $available,
        'uploads_path' => $uploads_path,
        'uploads_size' => size_format($uploads_size),
        'uploads_size_bytes' => $uploads_size,
        'file_count' => $file_count
    ));
}

/**
 * Get directory size and file count in one pass (more efficient)
 * Has a time limit to prevent timeouts on massive folders
 */
function ccm_tools_get_directory_stats($path, $max_time = 10) {
    $size = 0;
    $count = 0;
    $start_time = time();
    
    if (!is_dir($path)) {
        return array('size' => $size, 'count' => $count, 'complete' => true);
    }
    
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $file) {
            // Check time limit
            if ((time() - $start_time) > $max_time) {
                return array('size' => $size, 'count' => $count, 'complete' => false);
            }
            
            // Skip backup directory
            $filepath = $file->getPathname();
            if (strpos($filepath, 'ccm-backups') !== false) {
                continue;
            }
            
            if ($file->isFile()) {
                $size += $file->getSize();
                $count++;
            }
        }
    } catch (Exception $e) {
        // Handle permission errors gracefully
    }
    
    return array('size' => $size, 'count' => $count, 'complete' => true);
}

/**
 * Get directory size recursively (legacy - kept for compatibility)
 */
function ccm_tools_get_directory_size($path) {
    $stats = ccm_tools_get_directory_stats($path);
    return $stats['size'];
}

/**
 * Count files in directory recursively (legacy - kept for compatibility)
 */
function ccm_tools_count_files($path) {
    $stats = ccm_tools_get_directory_stats($path);
    return $stats['count'];
}

/**
 * Start uploads backup process
 */
add_action('wp_ajax_ccm_tools_start_uploads_backup', 'ccm_tools_ajax_start_uploads_backup');
function ccm_tools_ajax_start_uploads_backup(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'ccm-tools')));
    }
    
    if (!class_exists('ZipArchive')) {
        wp_send_json_error(array('message' => __('ZipArchive is not available on this server.', 'ccm-tools')));
    }
    
    $upload_dir = wp_upload_dir();
    $uploads_path = $upload_dir['basedir'];
    
    if (!is_dir($uploads_path)) {
        wp_send_json_error(array('message' => __('Uploads directory not found.', 'ccm-tools')));
    }
    
    // Create backup directory
    $backup_dir = $uploads_path . '/ccm-backups';
    if (!file_exists($backup_dir)) {
        wp_mkdir_p($backup_dir);
        // Add index.php for security
        file_put_contents($backup_dir . '/index.php', '<?php // Silence is golden');
        // Add .htaccess to prevent direct access
        file_put_contents($backup_dir . '/.htaccess', 'deny from all');
    }
    
    // Clean up old backups (older than 24 hours)
    ccm_tools_cleanup_old_backups($backup_dir);
    
    // Generate unique backup filename
    $backup_filename = 'uploads-backup-' . date('Y-m-d-His') . '-' . wp_generate_password(8, false) . '.zip';
    $backup_path = $backup_dir . '/' . $backup_filename;
    
    // Get all files to process
    $files = array();
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($uploads_path, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    
    foreach ($iterator as $file) {
        $path = $file->getPathname();
        
        // Skip backup directory itself
        if (strpos($path, '/ccm-backups') !== false || strpos($path, '\\ccm-backups') !== false) {
            continue;
        }
        
        if ($file->isFile()) {
            $files[] = $path;
        }
    }
    
    // Store backup state
    $backup_state = array(
        'status' => 'in_progress',
        'backup_path' => $backup_path,
        'backup_filename' => $backup_filename,
        'uploads_path' => $uploads_path,
        'files' => $files,
        'total_files' => count($files),
        'processed_files' => 0,
        'current_batch' => 0,
        'started_at' => time(),
        'error' => null
    );
    
    update_option('ccm_tools_backup_state', $backup_state, false);
    
    wp_send_json_success(array(
        'message' => __('Backup started', 'ccm-tools'),
        'total_files' => count($files),
        'backup_filename' => $backup_filename
    ));
}

/**
 * Process a batch of files for backup
 */
add_action('wp_ajax_ccm_tools_process_backup_batch', 'ccm_tools_ajax_process_backup_batch');
function ccm_tools_ajax_process_backup_batch(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'ccm-tools')));
    }
    
    $state = get_option('ccm_tools_backup_state');
    
    if (empty($state) || $state['status'] !== 'in_progress') {
        wp_send_json_error(array('message' => __('No backup in progress.', 'ccm-tools')));
    }
    
    $batch_size = 50; // Process 50 files at a time
    $start_index = $state['processed_files'];
    $end_index = min($start_index + $batch_size, $state['total_files']);
    
    try {
        $zip = new ZipArchive();
        $mode = ($start_index === 0) ? ZipArchive::CREATE | ZipArchive::OVERWRITE : ZipArchive::CREATE;
        
        if ($zip->open($state['backup_path'], $mode) !== true) {
            throw new Exception(__('Failed to open zip file for writing.', 'ccm-tools'));
        }
        
        for ($i = $start_index; $i < $end_index; $i++) {
            $file_path = $state['files'][$i];
            
            if (file_exists($file_path)) {
                // Get relative path from uploads directory
                $relative_path = str_replace($state['uploads_path'] . '/', '', $file_path);
                $relative_path = str_replace($state['uploads_path'] . '\\', '', $relative_path);
                
                $zip->addFile($file_path, $relative_path);
            }
        }
        
        $zip->close();
        
        // Update state
        $state['processed_files'] = $end_index;
        $state['current_batch']++;
        
        // Check if complete
        if ($state['processed_files'] >= $state['total_files']) {
            $state['status'] = 'complete';
            $state['completed_at'] = time();
            
            // Get final file size
            if (file_exists($state['backup_path'])) {
                $state['backup_size'] = filesize($state['backup_path']);
            }
        }
        
        update_option('ccm_tools_backup_state', $state, false);
        
        wp_send_json_success(array(
            'status' => $state['status'],
            'processed_files' => $state['processed_files'],
            'total_files' => $state['total_files'],
            'percent' => round(($state['processed_files'] / $state['total_files']) * 100, 1),
            'backup_size' => isset($state['backup_size']) ? size_format($state['backup_size']) : null
        ));
        
    } catch (Exception $e) {
        $state['status'] = 'error';
        $state['error'] = $e->getMessage();
        update_option('ccm_tools_backup_state', $state, false);
        
        wp_send_json_error(array('message' => $e->getMessage()));
    }
}

/**
 * Get backup status
 */
add_action('wp_ajax_ccm_tools_get_backup_status', 'ccm_tools_ajax_get_backup_status');
function ccm_tools_ajax_get_backup_status(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'ccm-tools')));
    }
    
    $state = get_option('ccm_tools_backup_state');
    
    if (empty($state)) {
        wp_send_json_success(array('status' => 'none'));
        return;
    }
    
    $response = array(
        'status' => $state['status'],
        'processed_files' => $state['processed_files'] ?? 0,
        'total_files' => $state['total_files'] ?? 0,
        'backup_filename' => $state['backup_filename'] ?? ''
    );
    
    if ($state['status'] === 'complete' && !empty($state['backup_path']) && file_exists($state['backup_path'])) {
        $response['backup_size'] = size_format(filesize($state['backup_path']));
        $response['download_ready'] = true;
    }
    
    if ($state['status'] === 'error') {
        $response['error'] = $state['error'];
    }
    
    wp_send_json_success($response);
}

/**
 * Download backup file
 */
add_action('wp_ajax_ccm_tools_download_backup', 'ccm_tools_ajax_download_backup');
function ccm_tools_ajax_download_backup(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have permission to perform this action.', 'ccm-tools'));
    }
    
    $state = get_option('ccm_tools_backup_state');
    
    if (empty($state) || $state['status'] !== 'complete' || !file_exists($state['backup_path'])) {
        wp_die(__('Backup file not found.', 'ccm-tools'));
    }
    
    $file_path = $state['backup_path'];
    $file_name = $state['backup_filename'];
    
    // Set headers for download
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $file_name . '"');
    header('Content-Length: ' . filesize($file_path));
    header('Pragma: public');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    
    // Clear output buffer
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    // Read file in chunks to handle large files
    $handle = fopen($file_path, 'rb');
    while (!feof($handle)) {
        echo fread($handle, 8192);
        flush();
    }
    fclose($handle);
    
    exit;
}

/**
 * Cancel/reset backup
 */
add_action('wp_ajax_ccm_tools_cancel_backup', 'ccm_tools_ajax_cancel_backup');
function ccm_tools_ajax_cancel_backup(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'ccm-tools')));
    }
    
    $state = get_option('ccm_tools_backup_state');
    
    // Delete partial backup file if exists
    if (!empty($state['backup_path']) && file_exists($state['backup_path'])) {
        unlink($state['backup_path']);
    }
    
    delete_option('ccm_tools_backup_state');
    
    wp_send_json_success(array('message' => __('Backup cancelled.', 'ccm-tools')));
}

/**
 * Clean up old backup files
 */
function ccm_tools_cleanup_old_backups($backup_dir, $max_age_hours = 24) {
    if (!is_dir($backup_dir)) {
        return;
    }
    
    $files = glob($backup_dir . '/*.zip');
    $max_age_seconds = $max_age_hours * 3600;
    $now = time();
    
    foreach ($files as $file) {
        if (is_file($file) && ($now - filemtime($file)) > $max_age_seconds) {
            unlink($file);
        }
    }
}