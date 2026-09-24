<?php
// Prevent direct file access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Atomically write content to a config file (used for wp-config.php).
 *
 * Takes a timestamped backup of the existing file (if any), writes the new
 * content to a temp file in the SAME directory, verifies the full byte count
 * was written, then renames the temp file into place. A plain
 * file_put_contents() can leave the file truncated if the worker is killed
 * mid-write or a second admin writes concurrently - for wp-config.php that
 * is a sitewide white screen with no way into wp-admin to fix it.
 *
 * Note file_put_contents() returns the byte count on a partial write (not
 * false), so a naive `=== false` check does not catch a full disk - this
 * helper checks the byte count against strlen($content) instead.
 *
 * @param string $path    Absolute path to the file to write.
 * @param string $content New full file content.
 * @return bool True on success. On failure the original file is left
 *              untouched and the temp file is cleaned up.
 */
function ccm_tools_write_wp_config($path, $content) {
    // Timestamped backup of the current file, if it exists.
    if (file_exists($path)) {
        $backup_path = $path . '.ccm-backup-' . gmdate('YmdHis') . '-' . wp_generate_password(6, false, false);
        @copy($path, $backup_path);
    }

    $dir = dirname($path);
    $tmp_path = $dir . '/' . basename($path) . '.tmp-' . wp_generate_password(12, false, false);

    $bytes_written = @file_put_contents($tmp_path, $content);
    if ($bytes_written === false || $bytes_written !== strlen($content)) {
        @unlink($tmp_path);
        return false;
    }

    // Preserve the original file's permissions where possible.
    if (file_exists($path)) {
        $perms = @fileperms($path);
        if ($perms !== false) {
            @chmod($tmp_path, $perms & 0777);
        }
    }

    if (!@rename($tmp_path, $path)) {
        @unlink($tmp_path);
        return false;
    }

    if (function_exists('opcache_invalidate')) {
        opcache_invalidate($path, true);
    }

    return true;
}

/**
 * Inspect a wp-config.php constant definition and report whether it is a
 * literal true/false this plugin can safely toggle.
 *
 * A host using define('WP_DEBUG', getenv('WP_DEBUG')) or a ternary is a
 * real, supported wp-config pattern. Matching only a bare true|false meant
 * that pattern was invisible to this plugin, which then inserted a SECOND
 * define() for the same constant - a hard "Constant already defined" PHP
 * notice/fatal on every later request.
 *
 * @param string $constant       Constant name, e.g. WP_DEBUG.
 * @param string $config_content Current wp-config.php contents.
 * @return array{defined: bool, is_bool_literal: bool, is_true: bool}
 */
function ccm_tools_wp_config_constant_state($constant, $config_content) {
    $state = array('defined' => false, 'is_bool_literal' => false, 'is_true' => false);

    if (preg_match('/define\(\s*[\'"]' . preg_quote($constant, '/') . '[\'"]\s*,\s*([^)]*)\)/i', $config_content, $matches)) {
        $state['defined'] = true;
        $value = trim($matches[1]);
        if (preg_match('/^(true|false)$/i', $value)) {
            $state['is_bool_literal'] = true;
            $state['is_true'] = (strtolower($value) === 'true');
        }
    }

    return $state;
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
    
    if (!ccm_tools_validate_table_name($table_name)) {
        wp_send_json_error('<p class="ccm-error">' . esc_html__('Invalid table name.', 'ccm-tools') . '</p>');
    }
    
    $result = ccm_tools_convert_single_table($table_name);
    wp_send_json_success($result);
}

// Get tables to optimize (AJAX)
add_action('wp_ajax_ccm_tools_get_tables_to_optimize', 'ccm_tools_ajax_get_tables_to_optimize');
function ccm_tools_ajax_get_tables_to_optimize(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('<p class="ccm-error">' . esc_html__('You do not have permission to perform this action.', 'ccm-tools') . '</p>');
    }
    
    $do_optimize = !empty($_POST['optimize']);
    $do_collation = !empty($_POST['collation']);
    $do_engine = !empty($_POST['engine']);
    
    // Default to optimize if no flags specified (backwards compatibility)
    if (!$do_optimize && !$do_collation && !$do_engine) {
        $do_optimize = true;
    }
    
    try {
        $tables_info = ccm_tools_get_tables_to_optimize($do_optimize, $do_collation, $do_engine);
        
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
    
    if (!ccm_tools_validate_table_name_optimize($table_name)) {
        wp_send_json_error('<p class="ccm-error">' . esc_html__('Invalid table name.', 'ccm-tools') . '</p>');
    }
    
    $result = ccm_tools_optimize_single_table($table_name);
    wp_send_json_success($result);
}

/**
 * AJAX handler to optimize a single table (lightweight — OPTIMIZE TABLE only)
 * Used by the progressive optimization flow to avoid timeouts on large databases
 */
add_action('wp_ajax_ccm_tools_optimize_table_task', 'ccm_tools_ajax_optimize_table_task');
function ccm_tools_ajax_optimize_table_task(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('You do not have permission to perform this action.', 'ccm-tools'));
    }

    $table_name = isset($_POST['table_name']) ? sanitize_text_field($_POST['table_name']) : '';
    $do_optimize = !empty($_POST['optimize']);
    $do_collation = !empty($_POST['collation']);
    $do_engine = !empty($_POST['engine']);

    if (empty($table_name)) {
        wp_send_json_error(__('No table name provided.', 'ccm-tools'));
    }

    if (!ccm_tools_validate_table_name_optimize($table_name)) {
        wp_send_json_error(__('Invalid table name.', 'ccm-tools'));
    }

    global $wpdb;
    $messages = array();
    $success = true;

    // OPTIMIZE TABLE
    if ($do_optimize) {
        $result = $wpdb->query("OPTIMIZE TABLE `{$table_name}`");
        if ($result === false) {
            $messages[] = 'Optimize failed';
            $success = false;
        } else {
            $messages[] = 'Optimized';
        }
    }

    // CONVERT ENGINE TO InnoDB
    if ($do_engine) {
        $database_name = $wpdb->dbname;
        if (empty($database_name)) {
            $database_name = defined('DB_NAME') ? DB_NAME : '';
        }

        $table_engine = null;
        if (!empty($database_name)) {
            $table_engine = $wpdb->get_var(
                $wpdb->prepare("SELECT ENGINE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s", $database_name, $table_name)
            );
        }

        if ($table_engine && $table_engine !== 'InnoDB') {
            $result = $wpdb->query("ALTER TABLE `{$table_name}` ENGINE = InnoDB");
            if ($result !== false) {
                $messages[] = $table_engine . ' → InnoDB';
            } else {
                $messages[] = 'Engine conversion failed';
                $success = false;
            }
        } else {
            $messages[] = 'Engine OK';
        }
    }

    // UPDATE COLLATION
    if ($do_collation) {
        $mysql_version = $wpdb->get_var("SELECT VERSION()");
        $collation = ccm_tools_get_appropriate_collation_optimize($mysql_version);
        $database_name = $wpdb->dbname;
        if (empty($database_name)) {
            $database_name = defined('DB_NAME') ? DB_NAME : '';
        }

        $table_status = null;
        if (!empty($database_name)) {
            $table_status = $wpdb->get_row(
                $wpdb->prepare("SELECT TABLE_COLLATION as Collation FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s", $database_name, $table_name),
                'OBJECT'
            );
        }

        if ($table_status && $table_status->Collation !== $collation) {
            $result = $wpdb->query("ALTER TABLE `{$table_name}` CONVERT TO CHARACTER SET utf8mb4 COLLATE {$collation}");
            if ($result !== false) {
                $messages[] = $table_status->Collation . ' → ' . $collation;
            } else {
                $messages[] = 'Collation update failed';
                $success = false;
            }
        } else {
            $messages[] = 'Collation OK';
        }
    }

    wp_send_json_success(array(
        'table' => $table_name,
        'success' => $success,
        'message' => implode('; ', $messages),
    ));
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
        'block_xmlrpc', 'block_rest_api', 'block_rss_feeds'
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
    
    if (!file_exists($wp_config_path) || !is_writable($wp_config_path)) {
        wp_send_json_error(__('wp-config.php file not found or not writable.', 'ccm-tools'));
        return;
    }
    
    // Read the config file
    $config_content = file_get_contents($wp_config_path);

    // A wp-config using define('WP_DEBUG', getenv('WP_DEBUG')) or a ternary
    // is a real, supported pattern on managed hosts. Refuse rather than
    // insert a second define() for the same constant.
    $wp_debug_state = ccm_tools_wp_config_constant_state('WP_DEBUG', $config_content);
    if ($wp_debug_state['defined'] && !$wp_debug_state['is_bool_literal']) {
        wp_send_json_error(__('WP_DEBUG is defined in wp-config.php using a non-boolean expression (e.g. getenv() or a ternary). This plugin cannot safely toggle it without risking a duplicate constant definition. Please edit wp-config.php manually.', 'ccm-tools'));
        return;
    }

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
    if ($wp_debug_state['defined']) {
        // Replace existing WP_DEBUG line (already confirmed a literal true/false above)
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

    // Write changes back to the file - atomically, with a backup
    if (ccm_tools_write_wp_config($wp_config_path, $config_content)) {
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
    
    if (!file_exists($wp_config_path) || !is_writable($wp_config_path)) {
        wp_send_json_error(__('wp-config.php file not found or not writable.', 'ccm-tools'));
        return;
    }
    
    // Read the config file
    $config_content = file_get_contents($wp_config_path);

    // A wp-config using a non-literal value (getenv(), a ternary, etc.) for
    // WP_DEBUG_DISPLAY is a real, supported pattern. Refuse rather than
    // insert a second define() for the same constant.
    $wp_debug_display_state = ccm_tools_wp_config_constant_state('WP_DEBUG_DISPLAY', $config_content);
    if ($wp_debug_display_state['defined'] && !$wp_debug_display_state['is_bool_literal']) {
        wp_send_json_error(__('WP_DEBUG_DISPLAY is defined in wp-config.php using a non-boolean expression. This plugin cannot safely toggle it without risking a duplicate constant definition. Please edit wp-config.php manually.', 'ccm-tools'));
        return;
    }

    // Update WP_DEBUG_DISPLAY value
    $debug_display_value = $enable ? 'true' : 'false';

    if ($wp_debug_display_state['defined']) {
        // Replace existing WP_DEBUG_DISPLAY line (already confirmed a literal true/false above)
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
    
    // Write changes back to the file - atomically, with a backup
    if (ccm_tools_write_wp_config($wp_config_path, $config_content)) {
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
    
    if (!file_exists($wp_config_path) || !is_writable($wp_config_path)) {
        wp_send_json_error(__('wp-config.php file not found or not writable.', 'ccm-tools'));
        return;
    }
    
    // Read the config file
    $config_content = file_get_contents($wp_config_path);

    // A wp-config using a non-literal value for WP_DEBUG_LOG (getenv(), a
    // ternary, or a file path string per WP's own docs) is a real,
    // supported pattern. Refuse rather than insert a second define().
    $wp_debug_log_state = ccm_tools_wp_config_constant_state('WP_DEBUG_LOG', $config_content);
    if ($wp_debug_log_state['defined'] && !$wp_debug_log_state['is_bool_literal']) {
        wp_send_json_error(__('WP_DEBUG_LOG is defined in wp-config.php using a non-boolean expression (e.g. a custom log file path, getenv(), or a ternary). This plugin cannot safely toggle it without risking a duplicate constant definition. Please edit wp-config.php manually.', 'ccm-tools'));
        return;
    }

    // Update WP_DEBUG_LOG value
    $debug_log_value = $enable ? 'true' : 'false';

    if ($wp_debug_log_state['defined']) {
        // Replace existing WP_DEBUG_LOG line (already confirmed a literal true/false above)
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
    
    // Write changes back to the file - atomically, with a backup
    if (ccm_tools_write_wp_config($wp_config_path, $config_content)) {
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
    
    // Backup used for the verify-and-restore step below (in addition to the
    // timestamped on-disk backup the atomic writer itself takes).
    $backup_content = $original_content;

    // Write changes back to the file - atomically, with a backup
    if (ccm_tools_write_wp_config($wp_config_path, $config_content)) {
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
            // Restore backup - also written atomically
            ccm_tools_write_wp_config($wp_config_path, $backup_content);
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

    // ccm_tools_add_redis_configuration() lived in system-info.php, which has
    // been removed - this legacy "Add to wp-config.php" button is repointed
    // at the current redis-object-cache.php implementation, using the same
    // settings -> config-array builder the one-step Save flow uses.
    if (!function_exists('ccm_tools_redis_add_config') || !function_exists('ccm_tools_redis_build_config_array') || !function_exists('ccm_tools_redis_get_settings')) {
        wp_send_json_error(__('Redis module not loaded.', 'ccm-tools'));
        return;
    }

    $settings = ccm_tools_redis_get_settings();
    $config   = ccm_tools_redis_build_config_array($settings);
    $result   = ccm_tools_redis_add_config($config);

    if (!empty($result['success'])) {
        wp_send_json_success(array(
            'message' => !empty($result['message']) ? $result['message'] : __('Redis configuration added successfully to wp-config.php.', 'ccm-tools'),
        ));
    } else {
        wp_send_json_error(!empty($result['message']) ? $result['message'] : __('Failed to add Redis configuration.', 'ccm-tools'));
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
add_action('wp_ajax_ccm_tools_toggle_admin_payment', 'ccm_tools_ajax_toggle_admin_payment');
function ccm_tools_ajax_toggle_admin_payment(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'ccm-tools')));
    }

    // Check if WooCommerce is active
    if (!ccm_tools_is_woocommerce_active()) {
        wp_send_json_error(array('message' => __('WooCommerce is not active.', 'ccm-tools')));
    }

    // js/main.js posts action ccm_tools_toggle_admin_payment with { enable: <bool> }
    // via FormData, which stringifies the boolean to "true"/"false".
    $enabled = isset($_POST['enable']) ? filter_var($_POST['enable'], FILTER_VALIDATE_BOOLEAN) : false;
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
    
    /*
     * Merge onto what is already stored rather than rebuilding the array from
     * the eight fields this screen owns. exclude_sizes is not on this screen —
     * it arrives through a settings import and is read when converting, at
     * inc/webp-converter.php — so rebuilding from scratch silently erased it
     * the next time anyone pressed Save, and the excluded sizes started being
     * converted again with no sign anything had changed.
     */
    $defaults = function_exists('ccm_tools_webp_get_default_settings')
        ? ccm_tools_webp_get_default_settings()
        : array();
    $existing = array_merge($defaults, (array) get_option('ccm_tools_webp_settings', array()));

    $settings = array_merge($existing, array(
        'enabled' => isset($_POST['enabled']) && $_POST['enabled'] === '1',
        'quality' => isset($_POST['quality'])
            ? max(1, min(100, intval($_POST['quality'])))
            : (isset($defaults['quality']) ? $defaults['quality'] : 85),
        'convert_on_upload' => isset($_POST['convert_on_upload']) && $_POST['convert_on_upload'] === '1',
        'serve_webp' => isset($_POST['serve_webp']) && $_POST['serve_webp'] === '1',
        'convert_on_demand' => isset($_POST['convert_on_demand']) && $_POST['convert_on_demand'] === '1',
        'convert_bg_images' => isset($_POST['convert_bg_images']) && $_POST['convert_bg_images'] === '1',
        'keep_originals' => isset($_POST['keep_originals']) && $_POST['keep_originals'] === '1',
        'preferred_extension' => in_array(($_POST['preferred_extension'] ?? 'auto'), array('auto', 'gd', 'imagick'), true)
            ? $_POST['preferred_extension']
            : 'auto',
    ));
    
    // update_option returns false if value unchanged, so we check if save succeeded OR value is same
    $saved = update_option('ccm_tools_webp_settings', $settings);
    $current = get_option('ccm_tools_webp_settings');
    
    if ($saved || $current == $settings) {
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

/**
 * Export WebP converter settings as JSON
 */
add_action('wp_ajax_ccm_tools_export_webp_settings', 'ccm_tools_ajax_export_webp_settings');
function ccm_tools_ajax_export_webp_settings(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'ccm-tools')));
    }
    
    $settings = ccm_tools_webp_get_settings();
    
    // Add export metadata
    $export_data = array(
        'plugin' => 'ccm-tools',
        'type' => 'webp-settings',
        'version' => defined('CCM_HELPER_VERSION') ? CCM_HELPER_VERSION : '7.10.0',
        'exported_at' => current_time('mysql'),
        'site_url' => get_site_url(),
        'settings' => $settings,
    );
    
    wp_send_json_success($export_data);
}

/**
 * Import WebP converter settings from JSON
 */
add_action('wp_ajax_ccm_tools_import_webp_settings', 'ccm_tools_ajax_import_webp_settings');
function ccm_tools_ajax_import_webp_settings(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'ccm-tools')));
    }
    
    // Get the JSON data from POST
    $json_data = isset($_POST['settings_json']) ? wp_unslash($_POST['settings_json']) : '';
    
    if (empty($json_data)) {
        wp_send_json_error(array('message' => __('No settings data provided.', 'ccm-tools')));
    }
    
    // Decode JSON
    $import_data = json_decode($json_data, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        wp_send_json_error(array('message' => __('Invalid JSON format: ', 'ccm-tools') . json_last_error_msg()));
    }
    
    // Validate it's from CCM Tools WebP settings
    if (!isset($import_data['plugin']) || $import_data['plugin'] !== 'ccm-tools') {
        wp_send_json_error(array('message' => __('Invalid settings file. This does not appear to be a CCM Tools export.', 'ccm-tools')));
    }
    
    if (!isset($import_data['type']) || $import_data['type'] !== 'webp-settings') {
        wp_send_json_error(array('message' => __('Invalid settings file. This is not a WebP settings export.', 'ccm-tools')));
    }
    
    // Check if settings exist
    if (!isset($import_data['settings']) || !is_array($import_data['settings'])) {
        wp_send_json_error(array('message' => __('No settings found in the import file.', 'ccm-tools')));
    }
    
    $imported_settings = $import_data['settings'];
    
    // Get current defaults to merge with imported settings
    $defaults = ccm_tools_webp_get_settings();
    
    // Sanitize each setting based on its type
    $sanitized_settings = array();
    
    // Boolean settings
    $boolean_keys = array(
        'enabled', 'convert_on_upload', 'serve_webp', 'convert_on_demand',
        'convert_bg_images', 'keep_originals'
    );
    
    foreach ($boolean_keys as $key) {
        $sanitized_settings[$key] = isset($imported_settings[$key]) ? (bool) $imported_settings[$key] : $defaults[$key];
    }
    
    // Integer settings
    $sanitized_settings['quality'] = isset($imported_settings['quality']) 
        ? max(1, min(100, intval($imported_settings['quality']))) 
        : $defaults['quality'];
    
    // String settings
    $sanitized_settings['preferred_extension'] = isset($imported_settings['preferred_extension']) 
        ? sanitize_text_field($imported_settings['preferred_extension']) 
        : $defaults['preferred_extension'];
    
    // Array settings
    $sanitized_settings['exclude_sizes'] = isset($imported_settings['exclude_sizes']) && is_array($imported_settings['exclude_sizes'])
        ? array_map('sanitize_text_field', $imported_settings['exclude_sizes'])
        : $defaults['exclude_sizes'];
    
    // Save the imported settings
    update_option('ccm_tools_webp_settings', $sanitized_settings);
    
    wp_send_json_success(array(
        'message' => sprintf(
            __('Settings imported successfully from %s (exported on %s).', 'ccm-tools'),
            isset($import_data['site_url']) ? esc_html($import_data['site_url']) : 'unknown site',
            isset($import_data['exported_at']) ? esc_html($import_data['exported_at']) : 'unknown date'
        ),
        'settings' => $sanitized_settings
    ));
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
    
    // Validate file type using server-side detection (not client-supplied Content-Type)
    $allowed_types = array('image/jpeg', 'image/png', 'image/gif');
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $real_mime = $finfo ? finfo_file($finfo, $file['tmp_name']) : $file['type'];
    if ($finfo) { finfo_close($finfo); }
    if (!in_array($real_mime, $allowed_types, true)) {
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
    
    $deleted_files = 0;
    $reset_count = 0;

    // Read the recorded conversions BEFORE the metadata is cleared below -
    // this is the only source of truth for which WebP files this plugin
    // actually created.
    $converted_rows = $wpdb->get_results(
        "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_ccm_webp_converted'"
    );

    // Delete only the paths this plugin recorded creating - never by
    // filename inference. Walking the uploads tree and unlinking any
    // "same basename, .webp extension" file also deletes WebP images a
    // designer hand-uploaded alongside the original (e.g. hero.png +
    // hero.webp both uploaded deliberately), permanently and by accident.
    if ($delete_files) {
        foreach ($converted_rows as $row) {
            $converted = maybe_unserialize($row->meta_value);
            if (!is_array($converted)) {
                continue;
            }
            foreach ($converted as $conversion) {
                if (!empty($conversion['success']) && !empty($conversion['dest_path']) && file_exists($conversion['dest_path'])) {
                    if (@unlink($conversion['dest_path'])) {
                        $deleted_files++;
                    }
                }
            }
        }

        // The background conversion queue also records webp_path for items
        // it has already converted - pick those up too.
        $queue = get_transient('ccm_webp_conversion_queue');
        if (is_array($queue)) {
            foreach ($queue as $item) {
                if (!empty($item['webp_path']) && file_exists($item['webp_path'])) {
                    if (@unlink($item['webp_path'])) {
                        $deleted_files++;
                    }
                }
            }
        }
    }

    // Clear WebP conversion metadata
    $attachment_ids = array_unique(wp_list_pluck($converted_rows, 'post_id'));
    foreach ($attachment_ids as $attachment_id) {
        delete_post_meta($attachment_id, '_ccm_webp_converted');
        $reset_count++;
    }
    
    // Also clear the failed conversion cache (legacy meta key)
    $wpdb->query(
        "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_ccm_webp_conversion_failed'"
    );
    
    // Clear all failed conversion transients
    $wpdb->query(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_ccm_webp_failed_%' OR option_name LIKE '_transient_timeout_ccm_webp_failed_%'"
    );
    
    // Also clear the conversion queue transient
    delete_transient('ccm_webp_conversion_queue');
    
    wp_send_json_success(array(
        'message' => sprintf(
            __('Reset %d images. %d WebP files deleted from disk.', 'ccm-tools'),
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
function ccm_tools_ajax_process_webp_queue(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('Permission denied.', 'ccm-tools')));
    }
    
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
        'preload_css_excludes' => ccm_tools_perf_sanitize_list($_POST['preload_css_excludes'] ?? ''),
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
        // New v7.9.0 settings
        'font_display_swap' => !empty($_POST['font_display_swap']),
        'speculation_rules' => !empty($_POST['speculation_rules']),
        'speculation_eagerness' => in_array($_POST['speculation_eagerness'] ?? 'moderate', array('conservative', 'moderate', 'eager')) 
            ? sanitize_text_field($_POST['speculation_eagerness']) 
            : 'moderate',
        'critical_css' => !empty($_POST['critical_css']),
        'critical_css_code' => wp_strip_all_tags($_POST['critical_css_code'] ?? ''),
        'disable_jquery_migrate' => !empty($_POST['disable_jquery_migrate']),
        'disable_block_css' => !empty($_POST['disable_block_css']),
        'disable_woocommerce_cart_fragments' => !empty($_POST['disable_woocommerce_cart_fragments']),
        'reduce_heartbeat' => !empty($_POST['reduce_heartbeat']),
        'heartbeat_interval' => max(15, min(120, absint($_POST['heartbeat_interval'] ?? 60))),
        'disable_xmlrpc' => !empty($_POST['disable_xmlrpc']),
        'disable_rsd_wlw' => !empty($_POST['disable_rsd_wlw']),
        'disable_shortlink' => !empty($_POST['disable_shortlink']),
        'disable_rest_api_links' => !empty($_POST['disable_rest_api_links']),
        'disable_oembed' => !empty($_POST['disable_oembed']),
        // Video optimizations
        'video_lazy_load' => !empty($_POST['video_lazy_load']),
        'video_preload_none' => !empty($_POST['video_preload_none']),
        // Image optimizations
        'lazy_load_images'     => !empty($_POST['lazy_load_images']),
        'image_decoding_async' => !empty($_POST['image_decoding_async']),
        'prefetch_on_hover'    => !empty($_POST['prefetch_on_hover']),
        // Head bloat removal
        'remove_generator_tag'       => !empty($_POST['remove_generator_tag']),
        'remove_adjacent_post_links' => !empty($_POST['remove_adjacent_post_links']),
        'disable_admin_bar'          => !empty($_POST['disable_admin_bar']),
        // Script/style inlining (v7.25.0)
        'inline_small_scripts'    => !empty($_POST['inline_small_scripts']),
        'inline_small_styles'     => !empty($_POST['inline_small_styles']),
        'inline_threshold_kb'     => max(1, min(50, absint($_POST['inline_threshold_kb'] ?? 2))),
        // Image attribute injection (v7.25.0)
        'inject_image_dimensions' => !empty($_POST['inject_image_dimensions']),
        'inject_srcset'           => !empty($_POST['inject_srcset']),
        // HTML & font optimizations (v7.26.0)
        'minify_html'            => !empty($_POST['minify_html']),
        'preload_key_requests'   => !empty($_POST['preload_key_requests']),
        'preload_key_urls'       => ccm_tools_perf_sanitize_urls($_POST['preload_key_urls'] ?? ''),
        'disable_wp_embed'       => !empty($_POST['disable_wp_embed']),
        'self_host_google_fonts' => !empty($_POST['self_host_google_fonts']),
        // Resource hints & third-party delay (v7.27.0)
        'preload_css_bg_image'      => !empty($_POST['preload_css_bg_image']),
        'preload_css_bg_url'        => esc_url_raw($_POST['preload_css_bg_url'] ?? ''),
        'priority_hints_above_fold' => !empty($_POST['priority_hints_above_fold']),
        'priority_hints_selectors'  => sanitize_textarea_field($_POST['priority_hints_selectors'] ?? ''),
        'delay_third_party'         => !empty($_POST['delay_third_party']),
        'delay_third_party_domains' => array_values(array_filter(array_map('sanitize_text_field', preg_split('/[\r\n,]+/', $_POST['delay_third_party_domains'] ?? '')))),
        // Gutenberg / WooCommerce / Cache headers (v7.28.0)
        'disable_gutenberg_frontend' => !empty($_POST['disable_gutenberg_frontend']),
        'woo_scripts_shop_only'      => !empty($_POST['woo_scripts_shop_only']),
        'cache_control_meta'         => !empty($_POST['cache_control_meta']),
        'stale_while_revalidate'     => !empty($_POST['stale_while_revalidate']),
        // WordPress Cron / Author Archives (v7.29.0)
        'disable_wp_cron'         => !empty($_POST['disable_wp_cron']),
        'cron_interval'           => absint($_POST['cron_interval'] ?? 60) ?: 60,
        'disable_author_archives' => !empty($_POST['disable_author_archives']),
        // INP / Interaction Optimizations (v7.30.0)
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
 * Export performance optimizer settings as JSON
 */
add_action('wp_ajax_ccm_tools_export_perf_settings', 'ccm_tools_ajax_export_perf_settings');
function ccm_tools_ajax_export_perf_settings(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'ccm-tools')));
    }
    
    $settings = ccm_tools_perf_get_settings();
    
    // Add export metadata
    $export_data = array(
        'plugin' => 'ccm-tools',
        'version' => defined('CCM_HELPER_VERSION') ? CCM_HELPER_VERSION : '7.9.0',
        'exported_at' => current_time('mysql'),
        'site_url' => get_site_url(),
        'settings' => $settings,
    );
    
    wp_send_json_success($export_data);
}

/**
 * Import performance optimizer settings from JSON
 */
add_action('wp_ajax_ccm_tools_import_perf_settings', 'ccm_tools_ajax_import_perf_settings');
function ccm_tools_ajax_import_perf_settings(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'ccm-tools')));
    }
    
    // Get the JSON data from POST
    $json_data = isset($_POST['settings_json']) ? wp_unslash($_POST['settings_json']) : '';
    
    if (empty($json_data)) {
        wp_send_json_error(array('message' => __('No settings data provided.', 'ccm-tools')));
    }
    
    // Decode JSON
    $import_data = json_decode($json_data, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        wp_send_json_error(array('message' => __('Invalid JSON format: ', 'ccm-tools') . json_last_error_msg()));
    }
    
    // Validate it's from CCM Tools
    if (!isset($import_data['plugin']) || $import_data['plugin'] !== 'ccm-tools') {
        wp_send_json_error(array('message' => __('Invalid settings file. This does not appear to be a CCM Tools export.', 'ccm-tools')));
    }
    
    // Check if settings exist
    if (!isset($import_data['settings']) || !is_array($import_data['settings'])) {
        wp_send_json_error(array('message' => __('No settings found in the import file.', 'ccm-tools')));
    }
    
    $imported_settings = $import_data['settings'];
    
    // Get current defaults to merge with imported settings (in case import is from older version)
    $defaults = ccm_tools_perf_get_settings();
    
    // Sanitize each setting based on its type
    $sanitized_settings = array();
    
    // Boolean settings
    $boolean_keys = array(
        'enabled', 'defer_js', 'delay_js', 'preload_css', 'preconnect', 'dns_prefetch',
        'lcp_fetchpriority', 'lcp_preload', 'remove_query_strings', 'disable_emoji',
        'disable_dashicons', 'lazy_load_iframes', 'youtube_facade', 'font_display_swap',
        'speculation_rules', 'critical_css', 'disable_jquery_migrate', 'disable_block_css',
        'disable_woocommerce_cart_fragments', 'reduce_heartbeat', 'disable_xmlrpc',
        'disable_rsd_wlw', 'disable_shortlink', 'disable_rest_api_links', 'disable_oembed',
        'video_lazy_load', 'video_preload_none',
        'lazy_load_images', 'image_decoding_async', 'prefetch_on_hover',
        'remove_generator_tag', 'remove_adjacent_post_links', 'disable_admin_bar',
        'inline_small_scripts', 'inline_small_styles', 'inject_image_dimensions', 'inject_srcset',
        'minify_html', 'preload_key_requests', 'disable_wp_embed', 'self_host_google_fonts',
        'preload_css_bg_image', 'priority_hints_above_fold', 'delay_third_party',
        'disable_gutenberg_frontend', 'woo_scripts_shop_only', 'cache_control_meta', 'stale_while_revalidate',
        'disable_wp_cron', 'disable_author_archives',
    );
    
    foreach ($boolean_keys as $key) {
        $sanitized_settings[$key] = isset($imported_settings[$key]) ? (bool) $imported_settings[$key] : $defaults[$key];
    }
    
    // Array settings (comma-separated lists of script/style handles).
    // sanitize_text_field is used here rather than sanitize_key - handles can
    // legitimately contain dots and mixed case (e.g. 'jquery.validate'),
    // and sanitize_key would lowercase and strip those characters, silently
    // breaking every future match against the real handle.
    $array_keys = array('defer_js_excludes', 'delay_js_excludes', 'preload_css_excludes');
    foreach ($array_keys as $key) {
        if (isset($imported_settings[$key]) && is_array($imported_settings[$key])) {
            $sanitized_settings[$key] = array_map('sanitize_text_field', $imported_settings[$key]);
        } else {
            $sanitized_settings[$key] = $defaults[$key];
        }
    }

    // URL array settings. preload_key_urls belongs here, not in the handle
    // list above - sanitize_key would mangle a URL (lowercase it and strip
    // everything but [a-z0-9_-]), turning "https://x.com/f.woff2" into
    // "httpsxcomfwoff2" and breaking every preload link it generates.
    $url_array_keys = array('preconnect_urls', 'dns_prefetch_urls', 'preload_key_urls');
    foreach ($url_array_keys as $key) {
        if (isset($imported_settings[$key]) && is_array($imported_settings[$key])) {
            $sanitized_settings[$key] = array_map('esc_url_raw', $imported_settings[$key]);
        } else {
            $sanitized_settings[$key] = $defaults[$key];
        }
    }
    
    // String settings
    $sanitized_settings['lcp_preload_url'] = isset($imported_settings['lcp_preload_url']) 
        ? esc_url_raw($imported_settings['lcp_preload_url']) 
        : $defaults['lcp_preload_url'];

    // Resource hints & third-party delay (v7.27.0)
    $sanitized_settings['preload_css_bg_url'] = isset($imported_settings['preload_css_bg_url'])
        ? esc_url_raw($imported_settings['preload_css_bg_url'])
        : $defaults['preload_css_bg_url'];

    $sanitized_settings['priority_hints_selectors'] = isset($imported_settings['priority_hints_selectors'])
        ? sanitize_textarea_field($imported_settings['priority_hints_selectors'])
        : $defaults['priority_hints_selectors'];

    $sanitized_settings['delay_third_party_domains'] = isset($imported_settings['delay_third_party_domains']) && is_array($imported_settings['delay_third_party_domains'])
        ? array_values(array_filter(array_map('sanitize_text_field', $imported_settings['delay_third_party_domains'])))
        : $defaults['delay_third_party_domains'];

    $sanitized_settings['speculation_eagerness'] = isset($imported_settings['speculation_eagerness']) && 
        in_array($imported_settings['speculation_eagerness'], array('conservative', 'moderate', 'eager'))
        ? $imported_settings['speculation_eagerness']
        : $defaults['speculation_eagerness'];
    
    $sanitized_settings['critical_css_code'] = isset($imported_settings['critical_css_code'])
        ? wp_strip_all_tags($imported_settings['critical_css_code'])
        : $defaults['critical_css_code'];
    
    // Integer settings
    $sanitized_settings['delay_js_timeout'] = isset($imported_settings['delay_js_timeout'])
        ? absint($imported_settings['delay_js_timeout'])
        : $defaults['delay_js_timeout'];
    
    $sanitized_settings['heartbeat_interval'] = isset($imported_settings['heartbeat_interval'])
        ? max(15, min(120, absint($imported_settings['heartbeat_interval'])))
        : $defaults['heartbeat_interval'];
    
    // Save the sanitized settings
    ccm_tools_perf_save_settings($sanitized_settings);
    
    wp_send_json_success(array(
        'message' => __('Settings imported successfully!', 'ccm-tools'),
        'imported_from' => isset($import_data['site_url']) ? $import_data['site_url'] : 'Unknown',
        'exported_at' => isset($import_data['exported_at']) ? $import_data['exported_at'] : 'Unknown',
        'settings' => $sanitized_settings,
    ));
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
    
    /*
     * Split on newlines as well as commas, and do not use sanitize_key().
     *
     * The renderer writes these lists into a textarea joined by newlines, so
     * a comma-only split saw the whole box as one item. sanitize_key() then
     * stripped the newlines and fused it: the shipped default of jquery,
     * jquery-core and jquery-migrate became the single handle
     * "jqueryjquery-corejquery-migrate" the first time anyone pressed Save,
     * and it can never match a real handle again. sanitize_key() also
     * lowercases and drops dots, so a hand-added "jquery.validate" became
     * "jqueryvalidate" and stopped excluding anything. The matcher is a
     * case-sensitive substring test, so both are silent failures.
     *
     * The import path already does it this way and says why in its own
     * comment; this is the same rule.
     */
    $items = preg_split('/[
,]+/', (string) $input);
    $sanitized = array();

    foreach ((array) $items as $item) {
        $item = sanitize_text_field(trim($item));
        if ($item !== '') {
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
    
    // Get the detection target: 'defer' or 'delay'
    $target = isset($_POST['target']) ? sanitize_text_field($_POST['target']) : 'defer';
    $is_delay = ($target === 'delay');
    
    // Get the site URL to fetch
    $site_url = home_url('/');
    $site_host = wp_parse_url($site_url, PHP_URL_HOST);
    
    // Fetch the homepage
    $response = wp_remote_get($site_url, array(
        'timeout' => 30,
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
        'wp_core' => array(),      // WordPress core
        'jquery' => array(),       // jQuery family
        'theme' => array(),        // Theme scripts
        'plugins' => array(),      // Plugin scripts
        'third_party' => array(),  // External CDN
        'other' => array(),        // Unknown
    );
    
    // Patterns for categorization
    $wp_core_patterns = array('wp-includes', 'wp-admin', 'wp-embed', 'wp-polyfill', 'wp-hooks', 'wp-i18n', 'wp-a11y', 'wp-dom-ready');
    $jquery_patterns = array('jquery', 'jquery-core', 'jquery-migrate', 'jquery-ui');
    
    // Additional patterns for delay mode - scripts that handle above-the-fold interactivity
    $interaction_patterns = array('navigation', 'menu', 'header', 'slider', 'carousel', 'swiper', 'lightbox', 'modal', 'accordion', 'tabs', 'dropdown', 'offcanvas', 'sticky', 'fixed', 'scroll', 'lazyload', 'lazy-load', 'lazysizes', 'aos', 'wow', 'animate');
    
    foreach ($scripts as &$script) {
        $src = $script['src'];
        $handle = $script['handle'];
        $src_lower = strtolower($src);
        $handle_lower = strtolower($handle);
        
        // Determine category
        $category = 'other';
        $safe = true;
        $reason = '';
        
        // Check jQuery first (highest priority to exclude for both modes)
        foreach ($jquery_patterns as $pattern) {
            if (strpos($src_lower, $pattern) !== false || strpos($handle_lower, $pattern) !== false) {
                $category = 'jquery';
                $safe = false;
                $reason = $is_delay
                    ? 'jQuery must not be delayed - many scripts depend on it being available immediately'
                    : 'jQuery must load synchronously - many scripts depend on it';
                break;
            }
        }
        
        // Check WordPress core (exclude for both modes)
        if ($category === 'other') {
            foreach ($wp_core_patterns as $pattern) {
                if (strpos($src_lower, $pattern) !== false) {
                    $category = 'wp_core';
                    $safe = false;
                    $reason = $is_delay
                        ? 'WordPress core scripts must not be delayed - they have inline dependencies'
                        : 'WordPress core scripts have inline dependencies';
                    break;
                }
            }
        }
        
        // Check if third-party (different host)
        if ($category === 'other') {
            $script_host = wp_parse_url($src, PHP_URL_HOST);
            if ($script_host && $script_host !== $site_host) {
                $category = 'third_party';
                $safe = true;
                $reason = $is_delay
                    ? 'External script - ideal candidate for delaying until user interaction'
                    : 'External script - usually safe to defer';
            }
        }
        
        // Check if theme
        if ($category === 'other') {
            if (strpos($src_lower, '/themes/') !== false) {
                $category = 'theme';
                
                if ($is_delay) {
                    // For delay mode, theme scripts often handle navigation/interactivity
                    // Check if this looks like an interaction script
                    $is_interaction = false;
                    foreach ($interaction_patterns as $pattern) {
                        if (strpos($src_lower, $pattern) !== false || strpos($handle_lower, $pattern) !== false) {
                            $is_interaction = true;
                            break;
                        }
                    }
                    if ($is_interaction) {
                        $safe = false;
                        $reason = 'Theme interaction script - delaying may break navigation/UI elements';
                    } else {
                        $safe = true;
                        $reason = 'Theme script - test carefully after delaying, may affect above-the-fold content';
                    }
                } else {
                    $safe = true;
                    $reason = 'Theme script - test after deferring';
                }
            }
        }
        
        // Check if plugin
        if ($category === 'other') {
            if (strpos($src_lower, '/plugins/') !== false) {
                $category = 'plugins';
                
                if ($is_delay) {
                    // For delay mode, check if plugin handles above-the-fold interactivity
                    $is_interaction = false;
                    foreach ($interaction_patterns as $pattern) {
                        if (strpos($src_lower, $pattern) !== false || strpos($handle_lower, $pattern) !== false) {
                            $is_interaction = true;
                            break;
                        }
                    }
                    if ($is_interaction) {
                        $safe = false;
                        $reason = 'Plugin interaction script - delaying may break visible UI elements';
                    } else {
                        $safe = true;
                        $reason = 'Plugin script - good candidate for delaying until user interaction';
                    }
                } else {
                    $safe = true;
                    $reason = 'Plugin script - test after deferring';
                }
            }
        }
        
        // Default unknown
        if ($category === 'other') {
            $safe = true;
            $reason = $is_delay
                ? 'Unknown origin - test carefully after delaying'
                : 'Unknown origin - test after deferring';
        }
        
        $script['category'] = $category;
        $script['safe_to_defer'] = $safe;
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
        'target' => $target,
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
    $file_list_path = $backup_dir . '/' . $backup_filename . '.filelist.json';

    // Get all files to process. Time-capped so a very large uploads folder
    // can't stall the request indefinitely - an incomplete walk still starts
    // a backup covering whatever was found within the time budget.
    $files = array();
    $walk_start = time();
    $max_walk_seconds = 20;
    $walk_complete = true;

    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($uploads_path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ((time() - $walk_start) > $max_walk_seconds) {
                $walk_complete = false;
                break;
            }

            $path = $file->getPathname();

            // Skip backup directory itself
            if (strpos($path, '/ccm-backups') !== false || strpos($path, '\\ccm-backups') !== false) {
                continue;
            }

            if ($file->isFile()) {
                $files[] = $path;
            }
        }
    } catch (Throwable $e) {
        // Permission errors etc. - proceed with whatever was found so far
        $walk_complete = false;
    }

    // Persist the (potentially very large) file list to a file inside the
    // backup directory rather than a wp_options row. A large media library
    // can run to tens of thousands of paths - storing that in an option
    // either exceeds max_allowed_packet on the first write or turns every
    // 50-file batch update into a multi-megabyte DB write. The backup
    // directory already has a deny-all .htaccess protecting it.
    if (file_put_contents($file_list_path, wp_json_encode($files)) === false) {
        wp_send_json_error(array('message' => __('Failed to write the backup file list.', 'ccm-tools')));
    }

    // Store backup state - counters and paths only, not the file list itself
    $backup_state = array(
        'status' => 'in_progress',
        'backup_path' => $backup_path,
        'backup_filename' => $backup_filename,
        'file_list_path' => $file_list_path,
        'uploads_path' => $uploads_path,
        'total_files' => count($files),
        'processed_files' => 0,
        'current_batch' => 0,
        'started_at' => time(),
        'walk_complete' => $walk_complete,
        'error' => null
    );

    update_option('ccm_tools_backup_state', $backup_state, false);

    wp_send_json_success(array(
        'message' => $walk_complete
            ? __('Backup started', 'ccm-tools')
            : __('Backup started. The uploads folder is large, so the file list was capped by a time limit and may be incomplete.', 'ccm-tools'),
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

    if (empty($state['file_list_path']) || !file_exists($state['file_list_path'])) {
        $state['status'] = 'error';
        $state['error'] = __('Backup file list is missing.', 'ccm-tools');
        update_option('ccm_tools_backup_state', $state, false);
        wp_send_json_error(array('message' => $state['error']));
    }

    $files = json_decode((string) file_get_contents($state['file_list_path']), true);
    if (!is_array($files)) {
        $state['status'] = 'error';
        $state['error'] = __('Backup file list is corrupt.', 'ccm-tools');
        update_option('ccm_tools_backup_state', $state, false);
        wp_send_json_error(array('message' => $state['error']));
    }

    $batch_size = 50; // Process 50 files at a time
    $start_index = $state['processed_files'];
    $end_index = min($start_index + $batch_size, $state['total_files']);

    try {
        $zip = new ZipArchive();
        // ZipArchive::RDWR does not exist (only RDONLY does - verified on
        // PHP 8.4 with ext/zip). CREATE re-opens an existing archive for
        // appending without truncating it, so it is correct for every batch
        // after the first too.
        $open_flag = ($start_index === 0) ? (ZipArchive::CREATE | ZipArchive::OVERWRITE) : ZipArchive::CREATE;

        if ($zip->open($state['backup_path'], $open_flag) !== true) {
            throw new Exception(__('Failed to open zip file for writing.', 'ccm-tools'));
        }

        for ($i = $start_index; $i < $end_index; $i++) {
            $file_path = isset($files[$i]) ? $files[$i] : '';

            if ($file_path !== '' && file_exists($file_path)) {
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

            // The manifest has done its job - remove it so it doesn't linger
            // in the backup directory.
            if (!empty($state['file_list_path']) && file_exists($state['file_list_path'])) {
                @unlink($state['file_list_path']);
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

    } catch (Throwable $e) {
        // Widened from Exception: ZipArchive::RDWR used to throw a fatal
        // Error (not an Exception) on batches after the first, which this
        // catch could not see - admin-ajax then 500'd and the job stuck in
        // "in_progress" forever. Catching Throwable means any future Error
        // is reported through the normal error response instead.
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
    
    // Validate backup path is within uploads directory
    $upload_dir = wp_upload_dir();
    $real_file = realpath($file_path);
    $real_upload = realpath($upload_dir['basedir']);
    if ($real_file === false || $real_upload === false || strpos($real_file, $real_upload) !== 0) {
        wp_die(__('Invalid backup file path.', 'ccm-tools'));
    }
    
    // Set headers for download
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . sanitize_file_name($file_name) . '"');
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

    // Delete the file-list manifest if exists
    if (!empty($state['file_list_path']) && file_exists($state['file_list_path'])) {
        unlink($state['file_list_path']);
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
    
    $files = array_merge(
        glob($backup_dir . '/*.zip') ?: array(),
        glob($backup_dir . '/*.filelist.json') ?: array()
    );
    $max_age_seconds = $max_age_hours * 3600;
    $now = time();

    foreach ($files as $file) {
        if (is_file($file) && ($now - filemtime($file)) > $max_age_seconds) {
            unlink($file);
        }
    }
}

/**
 * =================================================================
 * Redis Object Cache AJAX Handlers
 * =================================================================
 */

/**
 * AJAX handler to enable Redis object cache
 */
add_action('wp_ajax_ccm_tools_redis_enable', 'ccm_tools_ajax_redis_enable');
function ccm_tools_ajax_redis_enable(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('You do not have permission to perform this action.', 'ccm-tools'));
    }
    
    // Verify Redis extension is available
    if (!function_exists('ccm_tools_redis_extension_available') || !ccm_tools_redis_extension_available()) {
        wp_send_json_error(__('Redis PHP extension is not available.', 'ccm-tools'));
        return;
    }
    
    $force = isset($_POST['force']) && ($_POST['force'] === 'true' || $_POST['force'] === '1');
    
    if (!function_exists('ccm_tools_redis_install_dropin')) {
        wp_send_json_error(__('Redis module not loaded.', 'ccm-tools'));
        return;
    }
    
    $result = ccm_tools_redis_install_dropin($force);
    
    if ($result['success']) {
        wp_send_json_success(array(
            'message' => $result['message'],
            'reload' => true
        ));
    } else {
        wp_send_json_error($result['message']);
    }
}

/**
 * AJAX handler to disable Redis object cache
 */
add_action('wp_ajax_ccm_tools_redis_disable', 'ccm_tools_ajax_redis_disable');
function ccm_tools_ajax_redis_disable(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('You do not have permission to perform this action.', 'ccm-tools'));
    }
    
    // Verify Redis extension is available
    if (!function_exists('ccm_tools_redis_extension_available') || !ccm_tools_redis_extension_available()) {
        wp_send_json_error(__('Redis PHP extension is not available.', 'ccm-tools'));
        return;
    }
    
    if (!function_exists('ccm_tools_redis_uninstall_dropin')) {
        wp_send_json_error(__('Redis module not loaded.', 'ccm-tools'));
        return;
    }
    
    $result = ccm_tools_redis_uninstall_dropin();
    
    if ($result['success']) {
        wp_send_json_success(array(
            'message' => $result['message'],
            'reload' => true
        ));
    } else {
        wp_send_json_error($result['message']);
    }
}

/**
 * AJAX handler to flush Redis cache
 */
add_action('wp_ajax_ccm_tools_redis_flush', 'ccm_tools_ajax_redis_flush');
function ccm_tools_ajax_redis_flush(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('You do not have permission to perform this action.', 'ccm-tools'));
    }
    
    // Verify Redis extension is available
    if (!function_exists('ccm_tools_redis_extension_available') || !ccm_tools_redis_extension_available()) {
        wp_send_json_error(__('Redis PHP extension is not available.', 'ccm-tools'));
        return;
    }
    
    if (!function_exists('ccm_tools_redis_flush_cache')) {
        wp_send_json_error(__('Redis module not loaded.', 'ccm-tools'));
        return;
    }
    
    $settings = ccm_tools_redis_get_settings();
    $result = ccm_tools_redis_flush_cache($settings['selective_flush']);
    
    if ($result['success']) {
        wp_send_json_success(array(
            'message' => $result['message'],
            'keys_deleted' => $result['keys_deleted']
        ));
    } else {
        wp_send_json_error($result['message']);
    }
}

/**
 * AJAX handler to test Redis connection
 */
add_action('wp_ajax_ccm_tools_redis_test', 'ccm_tools_ajax_redis_test');
function ccm_tools_ajax_redis_test(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('You do not have permission to perform this action.', 'ccm-tools'));
    }
    
    // Verify Redis extension is available
    if (!function_exists('ccm_tools_redis_extension_available') || !ccm_tools_redis_extension_available()) {
        wp_send_json_error(__('Redis PHP extension is not available.', 'ccm-tools'));
        return;
    }
    
    if (!function_exists('ccm_tools_redis_check_connection')) {
        wp_send_json_error(__('Redis module not loaded.', 'ccm-tools'));
        return;
    }
    
    $connection = ccm_tools_redis_check_connection();
    
    if ($connection['connected']) {
        wp_send_json_success(array(
            'message' => sprintf(
                __('Successfully connected to Redis %s at %s', 'ccm-tools'),
                esc_html($connection['version']),
                esc_html($connection['host']) . ($connection['port'] ? ':' . intval($connection['port']) : '')
            ),
            'connection' => $connection
        ));
    } else {
        wp_send_json_error(
            __('Connection failed: ', 'ccm-tools') . esc_html($connection['error'] ?: __('Unknown error', 'ccm-tools'))
        );
    }
}

/**
 * AJAX handler to save Redis settings
 */
add_action('wp_ajax_ccm_tools_redis_save_settings', 'ccm_tools_ajax_redis_save_settings');
function ccm_tools_ajax_redis_save_settings(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('You do not have permission to perform this action.', 'ccm-tools'));
    }
    
    // Verify Redis extension is available
    if (!function_exists('ccm_tools_redis_extension_available') || !ccm_tools_redis_extension_available()) {
        wp_send_json_error(__('Redis PHP extension is not available.', 'ccm-tools'));
        return;
    }
    
    if (!function_exists('ccm_tools_redis_save_settings')) {
        wp_send_json_error(__('Redis module not loaded.', 'ccm-tools'));
        return;
    }
    
    $settings = array();
    
    // Collect and validate settings from POST data
    if (isset($_POST['host'])) {
        $host = sanitize_text_field($_POST['host']);
        // Validate host is a valid hostname or IP address
        if (!empty($host) && (filter_var($host, FILTER_VALIDATE_IP) || preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-\.]*[a-zA-Z0-9])?$/', $host))) {
            $settings['host'] = $host;
        } elseif ($host === 'localhost' || $host === '127.0.0.1') {
            $settings['host'] = $host;
        }
    }
    if (isset($_POST['port'])) {
        $port = absint($_POST['port']);
        // Validate port is in valid range
        if ($port > 0 && $port <= 65535) {
            $settings['port'] = $port;
        }
    }
    if (isset($_POST['path'])) {
        $path = sanitize_text_field($_POST['path']);
        // Validate path looks like a Unix socket path
        if (!empty($path) && preg_match('/^\/[a-zA-Z0-9\/\.\-_]+\.sock$/', $path)) {
            $settings['path'] = $path;
        }
    }
    if (isset($_POST['scheme'])) {
        $scheme = sanitize_text_field($_POST['scheme']);
        // Only allow valid schemes
        if (in_array($scheme, array('tcp', 'unix', 'tls'), true)) {
            $settings['scheme'] = $scheme;
        }
    }
    if (isset($_POST['database'])) {
        $database = absint($_POST['database']);
        // Redis databases are 0-15 by default
        if ($database >= 0 && $database <= 15) {
            $settings['database'] = $database;
        }
    }
    if (isset($_POST['password'])) {
        // Reject non-string password values (e.g. arrays)
        if (!is_string($_POST['password'])) {
            wp_send_json_error(array('message' => __('Invalid value.', 'ccm-tools')));
            return;
        }
        // Use wp_unslash to handle escaped characters properly
        $raw_password = (string) wp_unslash($_POST['password']);
        // Reject quotes/backslash/control chars: this value is later written
        // into wp-config.php as a define() literal, so bad input must never
        // be allowed to reach that code path (defense in depth alongside
        // ccm_tools_redis_config_line()'s var_export()-based escaping).
        if ($raw_password !== '' && preg_match('/[\'"\\\\\x00-\x1F]/', $raw_password)) {
            wp_send_json_error(array('message' => __('Password contains disallowed characters (quotes, backslash, or control characters).', 'ccm-tools')));
            return;
        }
        $settings['password'] = $raw_password;
    }
    if (isset($_POST['timeout'])) {
        $timeout = floatval($_POST['timeout']);
        // Reasonable timeout range: 0.1 to 30 seconds
        if ($timeout >= 0 && $timeout <= 30) {
            $settings['timeout'] = $timeout;
        }
    }
    if (isset($_POST['read_timeout'])) {
        $read_timeout = floatval($_POST['read_timeout']);
        // Reasonable timeout range: 0.1 to 30 seconds
        if ($read_timeout >= 0 && $read_timeout <= 30) {
            $settings['read_timeout'] = $read_timeout;
        }
    }
    if (isset($_POST['max_ttl'])) {
        $max_ttl = absint($_POST['max_ttl']);
        // Max TTL up to 1 year in seconds
        if ($max_ttl >= 0 && $max_ttl <= 31536000) {
            $settings['max_ttl'] = $max_ttl;
        }
    }
    if (isset($_POST['key_salt'])) {
        $key_salt = sanitize_text_field($_POST['key_salt']);
        // Key salt should be alphanumeric with underscores/hyphens
        if (empty($key_salt) || preg_match('/^[a-zA-Z0-9_\-\.]+$/', $key_salt)) {
            $settings['key_salt'] = $key_salt;
        }
    }
    if (isset($_POST['selective_flush'])) {
        $settings['selective_flush'] = $_POST['selective_flush'] === 'true' || $_POST['selective_flush'] === '1';
    }
    
    // Username (ACL auth, Redis 6.0+)
    if (isset($_POST['username'])) {
        // Reject non-string username values (e.g. arrays)
        if (!is_string($_POST['username'])) {
            wp_send_json_error(array('message' => __('Invalid value.', 'ccm-tools')));
            return;
        }
        $raw_username = (string) wp_unslash($_POST['username']);
        // Same rejection as password — this also ends up in a wp-config.php
        // define() literal.
        if ($raw_username !== '' && preg_match('/[\'"\\\\\x00-\x1F]/', $raw_username)) {
            wp_send_json_error(array('message' => __('Username contains disallowed characters (quotes, backslash, or control characters).', 'ccm-tools')));
            return;
        }
        $settings['username'] = sanitize_text_field($raw_username);
    }
    
    // Serializer — validate the extension is actually available on the server
    if (isset($_POST['serializer'])) {
        $ser = sanitize_text_field($_POST['serializer']);
        if ($ser === 'igbinary' && !extension_loaded('igbinary')) {
            $ser = 'php'; // igbinary extension not installed
        }
        if ($ser === 'msgpack' && !extension_loaded('msgpack')) {
            $ser = 'php'; // msgpack extension not installed
        }
        if (in_array($ser, array('php', 'igbinary', 'msgpack'), true)) {
            $settings['serializer'] = $ser;
        }
    }
    
    // Compression — validate phpredis was compiled with the requested algorithm
    if (isset($_POST['compression'])) {
        $comp = sanitize_text_field($_POST['compression']);
        if ($comp === 'lzf' && !defined('Redis::COMPRESSION_LZF')) {
            $comp = 'none';
        }
        if ($comp === 'lz4' && !defined('Redis::COMPRESSION_LZ4')) {
            $comp = 'none';
        }
        if ($comp === 'zstd' && !defined('Redis::COMPRESSION_ZSTD')) {
            $comp = 'none';
        }
        if (in_array($comp, array('none', 'lzf', 'lz4', 'zstd'), true)) {
            $settings['compression'] = $comp;
        }
    }
    
    // Async flush
    if (isset($_POST['async_flush'])) {
        $settings['async_flush'] = $_POST['async_flush'] === 'true' || $_POST['async_flush'] === '1';
    }
    
    // Disable comment (HTML footnote) — inverted checkbox
    if (isset($_POST['disable_comment'])) {
        $settings['disable_comment'] = $_POST['disable_comment'] === 'true' || $_POST['disable_comment'] === '1';
    }
    
    // WooCommerce specific settings
    if (isset($_POST['wc_cache_cart_fragments'])) {
        $settings['wc_cache_cart_fragments'] = $_POST['wc_cache_cart_fragments'] === 'true' || $_POST['wc_cache_cart_fragments'] === '1';
    }
    if (isset($_POST['wc_persistent_cart'])) {
        $settings['wc_persistent_cart'] = $_POST['wc_persistent_cart'] === 'true' || $_POST['wc_persistent_cart'] === '1';
    }
    if (isset($_POST['wc_session_cache'])) {
        $settings['wc_session_cache'] = $_POST['wc_session_cache'] === 'true' || $_POST['wc_session_cache'] === '1';
    }
    if (isset($_POST['wc_product_cache_ttl'])) {
        $ttl = absint($_POST['wc_product_cache_ttl']);
        if ($ttl >= 0 && $ttl <= 86400) { // Max 24 hours
            $settings['wc_product_cache_ttl'] = $ttl;
        }
    }
    if (isset($_POST['wc_session_cache_ttl'])) {
        $ttl = absint($_POST['wc_session_cache_ttl']);
        if ($ttl >= 0 && $ttl <= 604800) { // Max 7 days
            $settings['wc_session_cache_ttl'] = $ttl;
        }
    }
    
    // Detect serializer or compression changes — requires a cache flush to avoid deserialization crashes
    $old_settings = ccm_tools_redis_get_settings();
    $serializer_changed  = isset($settings['serializer'])  && ($settings['serializer']  !== ($old_settings['serializer']  ?? 'php'));
    $compression_changed = isset($settings['compression']) && ($settings['compression'] !== ($old_settings['compression'] ?? 'none'));
    
    $saved = ccm_tools_redis_save_settings($settings);
    
    // Auto-flush Redis when serializer or compression changed to prevent deserialization errors
    $flushed = false;
    if ($saved && ($serializer_changed || $compression_changed)) {
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
            $flushed = true;
        }
    }

    // One-step Save: when Redis is actually live (our drop-in is installed),
    // push the same settings to wp-config.php and make sure the deployed
    // drop-in is current — so saving is no longer a two-step process that
    // leaves wp-config out of sync. Skipped when Redis isn't enabled.
    $config_written = false;
    $dropin_synced  = false;
    $dropin_status  = function_exists('ccm_tools_redis_dropin_status') ? ccm_tools_redis_dropin_status() : array('is_ccm' => false);
    if (!empty($dropin_status['is_ccm'])
        && function_exists('ccm_tools_redis_build_config_array')
        && function_exists('ccm_tools_redis_add_config')
    ) {
        $fresh_settings = ccm_tools_redis_get_settings();
        $cfg = ccm_tools_redis_add_config(ccm_tools_redis_build_config_array($fresh_settings));
        // success with no 'unchanged' flag means a write actually happened
        $config_written = !empty($cfg['success']) && empty($cfg['unchanged']);

        if (function_exists('ccm_tools_redis_refresh_dropin')) {
            $sync = ccm_tools_redis_refresh_dropin(false);
            $dropin_synced = !empty($sync['changed']);
        }
    }

    // Build response message
    if (!$saved) {
        $message = __('Settings unchanged.', 'ccm-tools');
    } elseif ($flushed) {
        $message = __('Redis settings saved. Cache flushed automatically because serializer or compression changed.', 'ccm-tools');
    } else {
        $message = __('Redis settings saved successfully.', 'ccm-tools');
    }
    if ($config_written) {
        $message .= ' ' . __('wp-config.php updated.', 'ccm-tools');
    }
    if ($dropin_synced) {
        $message .= ' ' . __('Drop-in refreshed.', 'ccm-tools');
    }
    
    // Re-read settings after save so the response contains the freshest merged values
    $fresh = ccm_tools_redis_get_settings();
    $active_config = array(
        'WP_REDIS_HOST'            => array('value' => $fresh['host'],                                          'defined' => defined('WP_REDIS_HOST')),
        'WP_REDIS_PORT'            => array('value' => $fresh['port'],                                          'defined' => defined('WP_REDIS_PORT')),
        'WP_REDIS_PATH'            => array('value' => $fresh['path'],                                          'defined' => defined('WP_REDIS_PATH')),
        'WP_REDIS_SCHEME'          => array('value' => $fresh['scheme'],                                        'defined' => defined('WP_REDIS_SCHEME')),
        'WP_REDIS_DATABASE'        => array('value' => $fresh['database'],                                      'defined' => defined('WP_REDIS_DATABASE')),
        'WP_REDIS_USERNAME'        => array('value' => !empty($fresh['username']) ? $fresh['username'] : '',    'defined' => defined('WP_REDIS_USERNAME')),
        'WP_REDIS_PASSWORD'        => array('value' => !empty($fresh['password']) ? '******' : '',              'defined' => defined('WP_REDIS_PASSWORD')),
        'WP_REDIS_TIMEOUT'         => array('value' => $fresh['timeout'],                                       'defined' => defined('WP_REDIS_TIMEOUT')),
        'WP_REDIS_MAXTTL'          => array('value' => $fresh['max_ttl'],                                       'defined' => defined('WP_REDIS_MAXTTL')),
        'WP_CACHE_KEY_SALT'        => array('value' => $fresh['key_salt'],                                      'defined' => defined('WP_CACHE_KEY_SALT')),
        'WP_REDIS_SELECTIVE_FLUSH' => array('value' => $fresh['selective_flush'] ? 'true' : 'false',            'defined' => defined('WP_REDIS_SELECTIVE_FLUSH')),
        'WP_REDIS_SERIALIZER'      => array('value' => $fresh['serializer'],                                    'defined' => defined('WP_REDIS_SERIALIZER')),
        'WP_REDIS_COMPRESSION'     => array('value' => $fresh['compression'],                                   'defined' => defined('WP_REDIS_COMPRESSION')),
        'WP_REDIS_ASYNC_FLUSH'     => array('value' => !empty($fresh['async_flush']) ? 'true' : 'false',       'defined' => defined('WP_REDIS_ASYNC_FLUSH')),
    );

    // update_option returns false when the value is unchanged, so treat that as success
    wp_send_json_success(array(
        'message'        => $message,
        'cache_flushed'  => $flushed,
        'config_written' => $config_written,
        'dropin_synced'  => $dropin_synced,
        'active_config'  => $active_config,
    ));
}

/**
 * AJAX handler to add Redis config to wp-config.php
 */
add_action('wp_ajax_ccm_tools_redis_add_config', 'ccm_tools_ajax_redis_add_config');
function ccm_tools_ajax_redis_add_config(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('You do not have permission to perform this action.', 'ccm-tools'));
    }
    
    // Verify Redis extension is available
    if (!function_exists('ccm_tools_redis_extension_available') || !ccm_tools_redis_extension_available()) {
        wp_send_json_error(__('Redis PHP extension is not available.', 'ccm-tools'));
        return;
    }
    
    if (!function_exists('ccm_tools_redis_add_config')) {
        wp_send_json_error(__('Redis module not loaded.', 'ccm-tools'));
        return;
    }
    
    // Get current settings and build the managed constant set. The builder is
    // shared with the one-step Save flow so both paths emit an identical block.
    $settings = ccm_tools_redis_get_settings();
    $config   = ccm_tools_redis_build_config_array($settings);

    // Detect serializer/compression changes — must flush BEFORE writing wp-config.php
    // because the current drop-in still uses the OLD serializer and can talk to Redis.
    // After wp-config changes, the next PHP process would use the NEW serializer but
    // find data encoded with the OLD one → deserialization failures → 500 errors.
    $flush_reason = '';
    $old_serializer  = defined('WP_REDIS_SERIALIZER')  ? strtolower(WP_REDIS_SERIALIZER)  : 'php';
    $old_compression = defined('WP_REDIS_COMPRESSION') ? strtolower(WP_REDIS_COMPRESSION) : 'none';
    $new_serializer  = isset($config['WP_REDIS_SERIALIZER'])  ? strtolower($config['WP_REDIS_SERIALIZER'])  : 'php';
    $new_compression = isset($config['WP_REDIS_COMPRESSION']) ? strtolower($config['WP_REDIS_COMPRESSION']) : 'none';
    
    if ($new_serializer !== $old_serializer) {
        $flush_reason = sprintf('serializer changed from %s to %s', $old_serializer, $new_serializer);
    } elseif ($new_compression !== $old_compression) {
        $flush_reason = sprintf('compression changed from %s to %s', $old_compression, $new_compression);
    }
    
    $cache_flushed = false;
    if ($flush_reason) {
        // Flush with the CURRENT (old) serializer still active so Redis can process the command
        try {
            if (class_exists('Redis')) {
                $redis = new Redis();
                $redis_host = defined('WP_REDIS_HOST') ? WP_REDIS_HOST : '127.0.0.1';
                $redis_port = defined('WP_REDIS_PORT') ? (int) WP_REDIS_PORT : 6379;
                $redis_db   = defined('WP_REDIS_DATABASE') ? (int) WP_REDIS_DATABASE : 0;
                $redis_timeout = defined('WP_REDIS_TIMEOUT') ? (float) WP_REDIS_TIMEOUT : 1;
                
                if (@$redis->connect($redis_host, $redis_port, $redis_timeout)) {
                    if (defined('WP_REDIS_PASSWORD') && WP_REDIS_PASSWORD) {
                        $auth = defined('WP_REDIS_USERNAME') && WP_REDIS_USERNAME
                            ? [WP_REDIS_USERNAME, WP_REDIS_PASSWORD]
                            : WP_REDIS_PASSWORD;
                        @$redis->auth($auth);
                    }
                    if ($redis_db > 0) {
                        $redis->select($redis_db);
                    }
                    $redis->flushDb();
                    $cache_flushed = true;
                }
                $redis->close();
            }
        } catch (Exception $e) {
            // Flush failed — proceed anyway, the drop-in has fallback handling
        }
    }
    
    $result = ccm_tools_redis_add_config($config);
    
    if ($result['success']) {
        $message = $result['message'];
        if ($cache_flushed) {
            $message .= ' ' . __('Redis cache was flushed automatically because ' . $flush_reason . '.', 'ccm-tools');
        }
        wp_send_json_success(array(
            'message' => $message,
            'backup_path' => isset($result['backup_path']) ? basename($result['backup_path']) : '',
            'cache_flushed' => $cache_flushed,
        ));
    } else {
        wp_send_json_error($result['message']);
    }
}

/**
 * AJAX handler to get Redis stats
 */
add_action('wp_ajax_ccm_tools_redis_get_stats', 'ccm_tools_ajax_redis_get_stats');
function ccm_tools_ajax_redis_get_stats(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('You do not have permission to perform this action.', 'ccm-tools'));
    }
    
    // Verify Redis extension is available
    if (!function_exists('ccm_tools_redis_extension_available') || !ccm_tools_redis_extension_available()) {
        wp_send_json_error(__('Redis PHP extension is not available.', 'ccm-tools'));
        return;
    }
    
    if (!function_exists('ccm_tools_redis_get_stats')) {
        wp_send_json_error(__('Redis module not loaded.', 'ccm-tools'));
        return;
    }
    
    $stats = ccm_tools_redis_get_stats();
    
    wp_send_json_success(array(
        'stats' => $stats
    ));
}

// Search pages/posts/CPTs for AI URL picker

// ===================================
// Cloudflare AJAX Handlers
// ===================================

add_action('wp_ajax_ccm_tools_cf_connect', 'ccm_tools_ajax_cf_connect');
function ccm_tools_ajax_cf_connect(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('Permission denied.', 'ccm-tools')));
    }

    $token   = isset($_POST['api_token']) ? trim(wp_unslash($_POST['api_token'])) : '';
    $zone_id = sanitize_text_field($_POST['zone_id'] ?? '');

    if (empty($token)) {
        wp_send_json_error(array('message' => __('API Token is required.', 'ccm-tools')));
    }

    // Validate token contains only printable ASCII (no control chars, no whitespace)
    if (!preg_match('/^[\x21-\x7E]+$/', $token)) {
        wp_send_json_error(array('message' => __('Invalid API Token format. Tokens should contain only alphanumeric characters, dashes, and underscores.', 'ccm-tools')));
    }

    // Cloudflare zone IDs are a 32-character hex string - reject anything else before it reaches the API path
    if (!empty($zone_id) && !preg_match('/^[a-f0-9]{32}$/i', $zone_id)) {
        wp_send_json_error(array('message' => __('Invalid Zone ID format.', 'ccm-tools')));
    }

    $result = ccm_tools_cf_verify_token($token, $zone_id);
    if (is_wp_error($result)) {
        wp_send_json_error(array('message' => $result->get_error_message()));
    }

    // Save verified settings (merge with existing to preserve auto_purge etc.)
    ccm_tools_cf_save_settings(array_merge(ccm_tools_cf_get_settings(), array(
        'api_token' => $token,
        'zone_id'   => $result['id'] ?? $zone_id,
        'connected' => true,
    )));

    // Clear detection transient so tab shows immediately
    delete_transient('ccm_tools_cf_detected');

    wp_send_json_success(array(
        'message' => __('Connected to Cloudflare successfully!', 'ccm-tools'),
        'zone'    => array(
            'id'   => $result['id'] ?? '',
            'name' => $result['name'] ?? '',
            'plan' => $result['plan']['name'] ?? 'Unknown',
        ),
    ));
}

add_action('wp_ajax_ccm_tools_cf_disconnect', 'ccm_tools_ajax_cf_disconnect');
function ccm_tools_ajax_cf_disconnect(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('Permission denied.', 'ccm-tools')));
    }

    delete_option('ccm_tools_cf_settings');
    delete_transient('ccm_tools_cf_detected');

    wp_send_json_success(array('message' => __('Cloudflare disconnected.', 'ccm-tools')));
}

add_action('wp_ajax_ccm_tools_cf_get_status', 'ccm_tools_ajax_cf_get_status');
function ccm_tools_ajax_cf_get_status(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('Permission denied.', 'ccm-tools')));
    }

    $status = ccm_tools_cf_get_zone_status();
    if (is_wp_error($status)) {
        wp_send_json_error(array('message' => $status->get_error_message()));
    }

    wp_send_json_success($status);
}

add_action('wp_ajax_ccm_tools_cf_purge_all', 'ccm_tools_ajax_cf_purge_all');
function ccm_tools_ajax_cf_purge_all(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('Permission denied.', 'ccm-tools')));
    }

    $result = ccm_tools_cf_purge_all();
    if (is_wp_error($result)) {
        wp_send_json_error(array('message' => $result->get_error_message()));
    }

    wp_send_json_success(array('message' => __('Cache purged successfully!', 'ccm-tools')));
}

add_action('wp_ajax_ccm_tools_cf_purge_urls', 'ccm_tools_ajax_cf_purge_urls');
function ccm_tools_ajax_cf_purge_urls(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('Permission denied.', 'ccm-tools')));
    }

    $raw_urls = sanitize_textarea_field($_POST['urls'] ?? '');
    $urls = array_filter(array_map('trim', explode("\n", $raw_urls)));

    if (empty($urls)) {
        wp_send_json_error(array('message' => __('No URLs provided.', 'ccm-tools')));
    }

    // Validate each URL
    foreach ($urls as $url) {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            wp_send_json_error(array('message' => sprintf(__('Invalid URL: %s', 'ccm-tools'), $url)));
        }
    }

    $result = ccm_tools_cf_purge_urls($urls);
    if (is_wp_error($result)) {
        wp_send_json_error(array('message' => $result->get_error_message()));
    }

    wp_send_json_success(array(
        'message' => sprintf(__('%d URL(s) purged successfully!', 'ccm-tools'), count($urls)),
    ));
}

add_action('wp_ajax_ccm_tools_cf_dev_mode', 'ccm_tools_ajax_cf_dev_mode');
function ccm_tools_ajax_cf_dev_mode(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('Permission denied.', 'ccm-tools')));
    }

    $enable = !empty($_POST['enable']);
    $result = ccm_tools_cf_toggle_dev_mode($enable);
    if (is_wp_error($result)) {
        wp_send_json_error(array('message' => $result->get_error_message()));
    }

    wp_send_json_success(array(
        'message' => $enable
            ? __('Development Mode enabled (auto-disables in 3 hours).', 'ccm-tools')
            : __('Development Mode disabled.', 'ccm-tools'),
        'enabled' => $enable,
    ));
}

// ──────────────────────────────────────────────
// Cloudflare: Update Zone Setting
// ──────────────────────────────────────────────
add_action('wp_ajax_ccm_tools_cf_update_setting', 'ccm_tools_ajax_cf_update_setting');
function ccm_tools_ajax_cf_update_setting(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('Permission denied.', 'ccm-tools')));
    }

    $setting = isset($_POST['setting']) ? sanitize_text_field($_POST['setting']) : '';
    if (empty($setting)) {
        wp_send_json_error(array('message' => __('No setting specified.', 'ccm-tools')));
    }

    // Allowlist of settings that can be modified via this handler
    $allowed_settings = array(
        'minify', 'browser_cache_ttl', 'polish', 'security_level', 'ssl',
        'automatic_platform_optimization', 'rocket_loader', 'always_online', 'webp', 'mirage',
        'bot_fight_mode', 'browser_check', 'privacy_pass', 'server_side_exclude',
        'challenge_ttl', 'ip_geolocation', 'opportunistic_onion', 'pseudo_ipv4',
        'email_obfuscation', 'hotlink_protection', 'opportunistic_encryption',
        'early_hints', 'http2', 'http3', '0rtt', 'brotli',
        'always_use_https', 'automatic_https_rewrites',
    );
    if (!in_array($setting, $allowed_settings, true)) {
        wp_send_json_error(array('message' => __('Unknown Cloudflare setting.', 'ccm-tools')));
    }

    // Parse value based on setting type
    if ($setting === 'minify') {
        $value = array(
            'js'   => (!empty($_POST['js']) && $_POST['js'] === 'on') ? 'on' : 'off',
            'css'  => (!empty($_POST['css']) && $_POST['css'] === 'on') ? 'on' : 'off',
            'html' => (!empty($_POST['html']) && $_POST['html'] === 'on') ? 'on' : 'off',
        );
    } elseif ($setting === 'browser_cache_ttl' || $setting === 'challenge_ttl') {
        $value = isset($_POST['value']) ? absint($_POST['value']) : 0;
    } elseif ($setting === 'polish') {
        $allowed_polish = array('off', 'lossless', 'lossy');
        $value = isset($_POST['value']) ? sanitize_text_field($_POST['value']) : 'off';
        if (!in_array($value, $allowed_polish, true)) {
            $value = 'off';
        }
    } elseif ($setting === 'security_level') {
        $allowed_levels = array('essentially_off', 'low', 'medium', 'high', 'under_attack');
        $value = isset($_POST['value']) ? sanitize_text_field($_POST['value']) : 'medium';
        if (!in_array($value, $allowed_levels, true)) {
            $value = 'medium';
        }
    } elseif ($setting === 'ssl') {
        $allowed_ssl = array('off', 'flexible', 'full', 'strict');
        $value = isset($_POST['value']) ? sanitize_text_field($_POST['value']) : 'full';
        if (!in_array($value, $allowed_ssl, true)) {
            $value = 'full';
        }
    } elseif ($setting === 'automatic_platform_optimization') {
        $enabled = (!empty($_POST['value']) && $_POST['value'] === 'on');
        $value = array(
            'enabled'       => $enabled,
            'cf'            => true,
            'wordpress'     => true,
            'wp_plugin'     => false,
            'hostnames'     => array(),
            'cache_by_device_type' => false,
        );
    } elseif ($setting === 'pseudo_ipv4') {
        $allowed_pv4 = array('off', 'add_header', 'overwrite_header');
        $value = isset($_POST['value']) ? sanitize_text_field($_POST['value']) : 'off';
        if (!in_array($value, $allowed_pv4, true)) {
            $value = 'off';
        }
    } else {
        // on/off toggle settings
        $value = (!empty($_POST['value']) && $_POST['value'] === 'on') ? 'on' : 'off';
    }

    $result = ccm_tools_cf_update_setting($setting, $value);
    if (is_wp_error($result)) {
        wp_send_json_error(array('message' => $result->get_error_message()));
    }

    wp_send_json_success(array(
        'message' => __('Setting updated successfully.', 'ccm-tools'),
        'setting' => $setting,
        'value'   => $value,
    ));
}

// ──────────────────────────────────────────────
// Cloudflare: Apply Recommended Settings
// ──────────────────────────────────────────────
add_action('wp_ajax_ccm_tools_cf_apply_recommended', 'ccm_tools_ajax_cf_apply_recommended');
function ccm_tools_ajax_cf_apply_recommended(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('Permission denied.', 'ccm-tools')));
    }

    $result = ccm_tools_cf_apply_recommended();
    $applied_count = count($result['applied']);
    $failed_count  = count($result['failed']);

    if ($failed_count === 0) {
        wp_send_json_success(array(
            'message' => sprintf(__('%d recommended settings applied successfully.', 'ccm-tools'), $applied_count),
        ));
    } else {
        wp_send_json_success(array(
            'message' => sprintf(__('%d settings applied, %d failed.', 'ccm-tools'), $applied_count, $failed_count),
            'failed'  => $result['failed'],
        ));
    }
}

// ──────────────────────────────────────────────
// Cloudflare: Auto-Purge Toggle
// ──────────────────────────────────────────────
add_action('wp_ajax_ccm_tools_cf_auto_purge', 'ccm_tools_ajax_cf_auto_purge');
function ccm_tools_ajax_cf_auto_purge(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('Permission denied.', 'ccm-tools')));
    }

    $enable   = !empty($_POST['enable']);
    $settings = ccm_tools_cf_get_settings();
    $settings['auto_purge'] = $enable;
    ccm_tools_cf_save_settings($settings);

    wp_send_json_success(array(
        'message' => $enable
            ? __('Automatic cache purge enabled.', 'ccm-tools')
            : __('Automatic cache purge disabled.', 'ccm-tools'),
        'enabled' => $enable,
    ));
}

// ──────────────────────────────────────────────
// Cloudflare: Zone Analytics
// ──────────────────────────────────────────────
add_action('wp_ajax_ccm_tools_cf_analytics', 'ccm_tools_ajax_cf_analytics');
function ccm_tools_ajax_cf_analytics(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('Permission denied.', 'ccm-tools')));
    }

    $analytics = ccm_tools_cf_get_analytics();
    if (is_wp_error($analytics)) {
        $msg = $analytics->get_error_message();
        $err_data = $analytics->get_error_data();
        if (!empty($err_data['status']) && $err_data['status'] === 403) {
            $msg = __('Your API token does not have Analytics:Read permission. Edit your token in the Cloudflare dashboard to add Zone → Analytics → Read.', 'ccm-tools');
        }
        wp_send_json_error(array('message' => $msg));
    }

    wp_send_json_success($analytics);
}

// ──────────────────────────────────────────────
// Cloudflare: DNS Records
// ──────────────────────────────────────────────
add_action('wp_ajax_ccm_tools_cf_dns_records', 'ccm_tools_ajax_cf_dns_records');
function ccm_tools_ajax_cf_dns_records(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('Permission denied.', 'ccm-tools')));
    }

    $records = ccm_tools_cf_get_dns_records();
    if (is_wp_error($records)) {
        $msg = $records->get_error_message();
        $err_data = $records->get_error_data();
        if (!empty($err_data['status']) && $err_data['status'] === 403) {
            $msg = __('Your API token does not have DNS:Read permission. Edit your token in the Cloudflare dashboard to add Zone → DNS → Read.', 'ccm-tools');
        }
        wp_send_json_error(array('message' => $msg));
    }

    wp_send_json_success(array('records' => $records));
}