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