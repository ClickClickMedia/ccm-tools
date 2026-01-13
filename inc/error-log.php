<?php
/**
 * Error Log Viewer functionality
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get possible error log locations
 *
 * @return array List of possible log file paths
 */
function ccm_tools_get_error_log_locations() {
    $locations = array();
    $unique_paths = array(); // Track unique paths
    
    // Common WordPress error log locations
    $possible_locations = array(
        ABSPATH . 'error_log',
        ABSPATH . 'wp-content/error_log',
        ABSPATH . 'wp-admin/error_log',
        dirname(ABSPATH) . '/logs/error_log',
        dirname(ABSPATH) . '/logs/error.log',
        dirname(ABSPATH) . '/logs/php_error.log',
        dirname(ABSPATH) . '/logs/php_errors.log',
        dirname(ABSPATH) . '/logs/php-errors.log',
        dirname(ABSPATH) . '/logs/debug.log',
        ABSPATH . 'wp-content/debug.log'
    );
    
    // Try to get error log path from PHP configuration
    $php_error_log = ini_get('error_log');
    if (!empty($php_error_log) && $php_error_log !== 'syslog') {
        array_unshift($possible_locations, $php_error_log);
    }
    
    // Check existence of each location and avoid duplicates
    foreach ($possible_locations as $location) {
        // Normalize path to prevent duplicates with different formatting
        $real_path = realpath($location);
        
        // Skip if we've already processed this path or it doesn't exist
        if (!$real_path || isset($unique_paths[$real_path])) {
            continue;
        }
        
        if (file_exists($real_path) && is_readable($real_path)) {
            $locations[] = $real_path;
            $unique_paths[$real_path] = true;
        }
    }
    
    return $locations;
}

if (!defined('CCM_TOOLS_LOG_DOWNLOAD_TTL')) {
    define('CCM_TOOLS_LOG_DOWNLOAD_TTL', 5 * MINUTE_IN_SECONDS);
}

/**
 * Validate a requested log file path against the known whitelist.
 *
 * @param string $path Raw path provided by the requester.
 * @return string Validated absolute path or empty string when invalid.
 */
function ccm_tools_validate_log_file_path($path) {
    if (empty($path)) {
        return '';
    }

    $normalized = wp_normalize_path($path);
    $real_path = realpath($normalized);
    if (!$real_path) {
        return '';
    }

    $allowed_paths = ccm_tools_get_error_log_locations();
    return in_array($real_path, $allowed_paths, true) ? $real_path : '';
}

/**
 * Helper to fetch the first available log file path from the whitelist.
 *
 * @return string
 */
function ccm_tools_get_default_log_file_path() {
    $locations = ccm_tools_get_error_log_locations();
    return $locations[0] ?? '';
}

/**
 * Read error log file content with pagination
 *
 * @param string $log_file Path to the log file
 * @param int $lines Number of lines to read (default: 100)
 * @param int $offset Number of lines to skip from the end (default: 0)
 * @return array Log content and metadata
 */
function ccm_tools_read_error_log($log_file, $lines = 100, $offset = 0) {
    $log_file = ccm_tools_validate_log_file_path($log_file);
    if (empty($log_file)) {
        return array(
            'content' => '',
            'error' => __('Invalid log file selection.', 'ccm-tools'),
            'file_size' => 0,
            'last_modified' => 0
        );
    }

    if (!file_exists($log_file) || !is_readable($log_file)) {
        return array(
            'content' => '',
            'error' => __('Log file not found or not readable', 'ccm-tools'),
            'file_size' => 0,
            'last_modified' => 0
        );
    }
    
    $filesize = filesize($log_file);
    $last_modified = filemtime($log_file);
    
    // Check if the file is empty
    if ($filesize === 0) {
        // Remove slashes from translated strings
        $empty_title = __("Crikey! She's Empty as a Roo's Pocket!", 'ccm-tools');
        $empty_desc = __("Fair dinkum, mate, this error log's drier than a dead dingo's donger. Not a single drama to report - she's running smoother than a cold stubby sliding down ya throat on a scorcher.", 'ccm-tools');
        $empty_message = '<div class="empty-log-message">' .
                          esc_html(stripslashes($empty_title)) .
                          '<p class="empty-log-description">' .
                          esc_html(stripslashes($empty_desc)) .
                          '</p></div>';
        
        return array(
            'content' => $empty_message,
            'formatted_content' => $empty_message,
            'file_size' => size_format(0, 2),
            'last_modified' => human_time_diff($last_modified) . ' ' . __('ago', 'ccm-tools'),
            'raw_last_modified' => $last_modified
        );
    }
    
    // For very large files, we'll only read the last portion
    if ($filesize > 5 * 1024 * 1024) { // 5MB limit
        $content = __('Log file is too large to display completely. Showing last entries.', 'ccm-tools') . "\n\n";
        $handle = @fopen($log_file, 'r');
        
        if ($handle) {
            // Move to the end minus 1MB
            $read_size = min($filesize, 1024 * 1024); // 1MB maximum
            fseek($handle, -$read_size, SEEK_END);
            
            // Discard first line (may be incomplete)
            fgets($handle);
            
            // Read the rest
            $content .= fread($handle, $read_size);
            fclose($handle);
            
            // Split into lines and take last X lines
            $lines_array = explode("\n", $content);
            $total_lines = count($lines_array);
            
            if ($total_lines > $lines) {
                $start = max(0, $total_lines - $lines - $offset);
                $length = $lines;
                $lines_array = array_slice($lines_array, $start, $length);
                $content = implode("\n", $lines_array);
            }
        }
    } else {
        // For smaller files, we can read the whole file
        $content = file_get_contents($log_file);
        
        // Split into lines and take last X lines
        $lines_array = explode("\n", $content);
        $total_lines = count($lines_array);
        
        if ($total_lines > $lines) {
            $start = max(0, $total_lines - $lines - $offset);
            $length = $lines;
            $lines_array = array_slice($lines_array, $start, $length);
            $content = implode("\n", $lines_array);
        }    }
    
    // Convert UTC timestamps to Australia/Sydney timezone
    $content = ccm_tools_convert_error_log_timestamps($content);
    
    return array(
        'content' => $content,
        'file_size' => size_format($filesize, 2),
        'last_modified' => human_time_diff($last_modified) . ' ' . __('ago', 'ccm-tools'),
        'raw_last_modified' => $last_modified
    );
}

/**
 * AJAX handler for fetching error log content
 */
function ccm_tools_ajax_get_error_log() {
    // Check permissions and nonce
    if (!current_user_can('manage_options') || !check_ajax_referer('ccm-tools-nonce', 'nonce', false)) {
        wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'ccm-tools')));
    }
    
    $log_file_input = isset($_POST['log_file']) ? sanitize_text_field(wp_unslash($_POST['log_file'])) : '';
    $lines = isset($_POST['lines']) ? intval($_POST['lines']) : 100;
    $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
    $errors_only = isset($_POST['errors_only']) && filter_var($_POST['errors_only'], FILTER_VALIDATE_BOOLEAN);
    
    if ($log_file_input !== '') {
        $log_file = ccm_tools_validate_log_file_path($log_file_input);
        if (empty($log_file)) {
            wp_send_json_error(array('message' => __('Invalid log file selection.', 'ccm-tools')));
        }
    } else {
        $log_file = ccm_tools_get_default_log_file_path();
    }

    if (empty($log_file)) {
        wp_send_json_error(array('message' => __('No error log file found.', 'ccm-tools')));
    }

    // If errors_only is enabled, filter the content
    if ($errors_only) {
        if (!file_exists($log_file) || !is_readable($log_file)) {
            wp_send_json_error(array('message' => __('Log file not found or not readable.', 'ccm-tools')));
        }
        
        $full_content = file_get_contents($log_file);
        if ($full_content === false) {
            wp_send_json_error(array('message' => __('Failed to read log file.', 'ccm-tools')));
        }
        
        // Convert timestamps and filter for errors only
        $full_content = ccm_tools_convert_error_log_timestamps($full_content);
        $filtered_content = ccm_tools_filter_errors_only($full_content);
        
        // Limit to requested number of lines from the end
        if (!empty($filtered_content)) {
            $lines_array = explode("\n", $filtered_content);
            $total_lines = count($lines_array);
            
            if ($total_lines > $lines) {
                $start = max(0, $total_lines - $lines);
                $lines_array = array_slice($lines_array, $start);
                $filtered_content = implode("\n", $lines_array);
            }
        }
        
        // Format the filtered content for display
        $formatted_content = ccm_tools_format_error_log($filtered_content);
        
        $filesize = filesize($log_file);
        $last_modified = filemtime($log_file);
        
        wp_send_json_success(array(
            'content' => $filtered_content,
            'formatted_content' => $formatted_content,
            'file_size' => size_format($filesize, 2),
            'last_modified' => human_time_diff($last_modified) . ' ' . __('ago', 'ccm-tools'),
            'raw_last_modified' => $last_modified
        ));
    }

    $log_data = ccm_tools_read_error_log($log_file, $lines, $offset);
    wp_send_json_success($log_data);
}
add_action('wp_ajax_ccm_tools_get_error_log', 'ccm_tools_ajax_get_error_log');

/**
 * AJAX handler for clearing error log
 */
function ccm_tools_ajax_clear_error_log() {
    // Check permissions and nonce
    if (!current_user_can('manage_options') || !check_ajax_referer('ccm-tools-nonce', 'nonce', false)) {
        wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'ccm-tools')));
    }
    
    $log_file_input = isset($_POST['log_file']) ? sanitize_text_field(wp_unslash($_POST['log_file'])) : '';
    
    if (empty($log_file_input)) {
        wp_send_json_error(array('message' => __('No log file specified.', 'ccm-tools')));
    }

    $log_file = ccm_tools_validate_log_file_path($log_file_input);
    if (empty($log_file)) {
        wp_send_json_error(array('message' => __('Invalid log file selection.', 'ccm-tools')));
    }
    
    // Check if file exists and is writable
    if (!file_exists($log_file) || !is_writable($log_file)) {
        wp_send_json_error(array('message' => __('Log file not found or not writable.', 'ccm-tools')));
    }
    
    // Clear the file by opening it in write mode
    $result = file_put_contents($log_file, '');
    if ($result !== false) {
        wp_send_json_success(array(
            'message' => __('Log file cleared successfully.', 'ccm-tools'),
            'content' => '',
            'file_size' => size_format(0, 2),
            'last_modified' => human_time_diff(time()) . ' ' . __('ago', 'ccm-tools'),
            'raw_last_modified' => time()
        ));
    } else {
        wp_send_json_error(array('message' => __('Failed to clear log file.', 'ccm-tools')));
    }
}
add_action('wp_ajax_ccm_tools_clear_error_log', 'ccm_tools_ajax_clear_error_log');

/**
 * AJAX handler for downloading error log
 */
function ccm_tools_ajax_download_error_log() {
    // Check permissions and nonce
    if (!current_user_can('manage_options') || !check_ajax_referer('ccm-tools-nonce', 'nonce', false)) {
        wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'ccm-tools')));
    }
    
    $log_file_input = isset($_POST['log_file']) ? sanitize_text_field(wp_unslash($_POST['log_file'])) : '';

    if (empty($log_file_input)) {
        wp_send_json_error(array('message' => __('No log file specified.', 'ccm-tools')));
    }

    $log_file = ccm_tools_validate_log_file_path($log_file_input);
    if (empty($log_file) || !file_exists($log_file) || !is_readable($log_file)) {
        wp_send_json_error(array('message' => __('Invalid or unreadable log file selection.', 'ccm-tools')));
    }

    if (!class_exists('ZipArchive')) {
        wp_send_json_error(array('message' => __('ZipArchive PHP extension is required to download logs.', 'ccm-tools')));
    }

    $token = wp_generate_password(20, false);
    $transient_key = 'ccm_tools_log_download_' . $token;

    if (!set_transient($transient_key, $log_file, CCM_TOOLS_LOG_DOWNLOAD_TTL)) {
        wp_send_json_error(array('message' => __('Unable to initialize secure download. Please try again.', 'ccm-tools')));
    }

    $download_url = wp_nonce_url(
        add_query_arg(
            array(
                'action' => 'ccm_tools_download_error_log_file',
                'token' => $token,
            ),
            admin_url('admin-ajax.php')
        ),
        'ccm-tools-download-log'
    );

    wp_send_json_success(array(
        'download_url' => $download_url,
        'filename' => sprintf('error-log-%s.zip', gmdate('Y-m-d-H-i-s'))
    ));
}
add_action('wp_ajax_ccm_tools_download_error_log', 'ccm_tools_ajax_download_error_log');

/**
 * Securely streams a zipped error log to the browser.
 */
function ccm_tools_ajax_download_error_log_file() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have permission to perform this action.', 'ccm-tools'), __('Permission denied', 'ccm-tools'), array('response' => 403));
    }

    check_admin_referer('ccm-tools-download-log');

    $token = isset($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : '';
    if (empty($token)) {
        wp_die(__('Invalid download token.', 'ccm-tools'), __('Download error', 'ccm-tools'), array('response' => 400));
    }

    $transient_key = 'ccm_tools_log_download_' . $token;
    $log_file = get_transient($transient_key);
    delete_transient($transient_key);

    $log_file = ccm_tools_validate_log_file_path($log_file);
    if (empty($log_file) || !file_exists($log_file) || !is_readable($log_file)) {
        wp_die(__('The requested log file is no longer available.', 'ccm-tools'), __('Download error', 'ccm-tools'), array('response' => 410));
    }

    if (!class_exists('ZipArchive')) {
        wp_die(__('ZipArchive PHP extension is required to download logs.', 'ccm-tools'), __('Server error', 'ccm-tools'), array('response' => 500));
    }

    $temp_zip = wp_tempnam('ccm-tools-log');
    if (!$temp_zip) {
        wp_die(__('Unable to create temporary archive.', 'ccm-tools'), __('Server error', 'ccm-tools'), array('response' => 500));
    }

    $zip = new ZipArchive();
    if (true !== $zip->open($temp_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
        @unlink($temp_zip);
        wp_die(__('Could not initialize archive for download.', 'ccm-tools'), __('Server error', 'ccm-tools'), array('response' => 500));
    }

    if (!$zip->addFile($log_file, basename($log_file))) {
        $zip->close();
        @unlink($temp_zip);
        wp_die(__('Failed to add log file to archive.', 'ccm-tools'), __('Server error', 'ccm-tools'), array('response' => 500));
    }

    $zip->close();

    $download_name = sprintf('error-log-%s.zip', gmdate('Y-m-d-H-i-s'));

    nocache_headers();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $download_name . '"');
    header('Content-Length: ' . filesize($temp_zip));

    readfile($temp_zip);
    @unlink($temp_zip);
    wp_die();
}
add_action('wp_ajax_ccm_tools_download_error_log_file', 'ccm_tools_ajax_download_error_log_file');

/**
 * AJAX handler for formatting error log content
 */
function ccm_tools_ajax_format_error_log() {
    // Check permissions and nonce
    if (!current_user_can('manage_options') || !check_ajax_referer('ccm-tools-nonce', 'nonce', false)) {
        wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'ccm-tools')));
    }
    $content = isset($_POST['content']) ? wp_kses_post(wp_unslash($_POST['content'])) : '';
    
    if (empty($content)) {
        wp_send_json_error(array('message' => __('No content to format.', 'ccm-tools')));
    }
    
    // Convert UTC timestamps to Australia/Sydney timezone before formatting
    $content = ccm_tools_convert_error_log_timestamps($content);
    
    $formatted_content = ccm_tools_format_error_log($content);
    
    wp_send_json_success(array(
        'formatted_content' => $formatted_content
    ));
}
add_action('wp_ajax_ccm_tools_format_error_log_ajax', 'ccm_tools_ajax_format_error_log');

/**
 * AJAX handler for filtering error log content to show only errors
 */
function ccm_tools_ajax_filter_errors_only() {
    // Check permissions and nonce
    if (!current_user_can('manage_options') || !check_ajax_referer('ccm-tools-nonce', 'nonce', false)) {
        wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'ccm-tools')));
    }
    
    $log_file_input = isset($_POST['log_file']) ? sanitize_text_field(wp_unslash($_POST['log_file'])) : '';
    $lines = isset($_POST['lines']) ? intval($_POST['lines']) : 100;
    
    if ($log_file_input !== '') {
        $log_file = ccm_tools_validate_log_file_path($log_file_input);
        if (empty($log_file)) {
            wp_send_json_error(array('message' => __('Invalid log file selection.', 'ccm-tools')));
        }
    } else {
        $log_file = ccm_tools_get_default_log_file_path();
    }

    if (empty($log_file)) {
        wp_send_json_error(array('message' => __('No error log file found.', 'ccm-tools')));
    }
    
    // For filtering, we need to read the entire log file first
    if (!file_exists($log_file) || !is_readable($log_file)) {
        wp_send_json_error(array('message' => __('Log file not found or not readable.', 'ccm-tools')));
    }
    
    // Read the entire log file content
    $full_content = file_get_contents($log_file);
    if ($full_content === false) {
        wp_send_json_error(array('message' => __('Failed to read log file.', 'ccm-tools')));
    }
      // Convert UTC timestamps to Australia/Sydney timezone before filtering
    $full_content = ccm_tools_convert_error_log_timestamps($full_content);
    
    // Apply the error filtering to the full content
    $filtered_content = ccm_tools_filter_errors_only($full_content);
    
    // Now limit the filtered content to the requested number of lines from the end
    if (!empty($filtered_content)) {
        $lines_array = explode("\n", $filtered_content);
        $total_lines = count($lines_array);
        
        if ($total_lines > $lines) {
            $start = max(0, $total_lines - $lines);
            $lines_array = array_slice($lines_array, $start);
            $filtered_content = implode("\n", $lines_array);
        }
    }
    
    wp_send_json_success(array(
        'filtered_content' => $filtered_content
    ));
}
add_action('wp_ajax_ccm_tools_filter_errors_only', 'ccm_tools_ajax_filter_errors_only');

/**
 * Filter error log content to show only errors and stack traces
 * 
 * @param string $content Raw log content
 * @return string Filtered log content containing only errors and stack traces
 */
function ccm_tools_filter_errors_only($content) {
    if (empty($content)) {
        return $content;
    }
    
    $lines = explode("\n", $content);
    $filtered_lines = array();
    $in_stack_trace = false;
    $current_error_is_actual_error = false;
    
    foreach ($lines as $line) {
        // Skip empty lines
        if (trim($line) === '') {
            continue;
        }
        
        // Check if this line is a FATAL ERROR or PARSE ERROR only (true errors)
        // These are the ONLY log entries we want to show
        if (preg_match('/PHP (Fatal error|Parse error|Catchable fatal error):/i', $line)) {
            $filtered_lines[] = $line;
            $in_stack_trace = false;
            $current_error_is_actual_error = true;
            continue;
        }
        
        // Check if this line starts a stack trace (only include if preceded by an actual error)
        if ($current_error_is_actual_error && preg_match('/Stack trace:\s*$/i', $line)) {
            $filtered_lines[] = $line;
            $in_stack_trace = true;
            continue;
        }
        
        // Include stack trace lines (they start with # followed by a number)
        if ($in_stack_trace && $current_error_is_actual_error && preg_match('/^\s*#\d+\s+/', $line)) {
            $filtered_lines[] = $line;
            continue;
        }
        
        // Include "thrown in" lines that are part of error context
        if ($current_error_is_actual_error && preg_match('/(thrown in|in \/.*\.php on line \d+)/i', $line)) {
            $filtered_lines[] = $line;
            $in_stack_trace = false;
            $current_error_is_actual_error = false;
            continue;
        }
        
        // Any other line - check if it's a new log entry (starts with timestamp)
        // If so, reset our state since it's not an error we care about
        if (preg_match('/^\[[\d]{2}-[A-Za-z]{3}-[\d]{4}/', $line)) {
            $in_stack_trace = false;
            $current_error_is_actual_error = false;
        }
        
        // All other lines are excluded (notices, warnings, deprecated, doing_it_wrong, etc.)
    }
    
    return implode("\n", $filtered_lines);
}

/**
 * Format the error log content to highlight fatal errors and stack traces
 * 
 * @param string $content Raw log content
 * @return string Formatted log content with highlighted errors
 */
function ccm_tools_format_error_log($content) {
    if (empty($content)) {
        return $content;
    }
    
    // Add HTML highlighting for fatal errors and stack traces
    $formatted = preg_replace(
        array(
            // Fatal errors
            '/(PHP Fatal error:.*?)(\n|\r\n)/',
            // Stack traces
            '/(Stack trace:\s*\n)((#[0-9]+\s+.*?\n)+)/',
            // PHP parse errors
            '/(PHP Parse error:.*?)(\n|\r\n)/',
            // PHP warnings
            '/(PHP Warning:.*?)(\n|\r\n)/',
            // PHP notices
            '/(PHP Notice:.*?)(\n|\r\n)/',
            // PHP deprecated
            '/(PHP Deprecated:.*?)(\n|\r\n)/'
        ),
        array(
            '<span class="error-fatal">$1</span>$2',
            '$1<span class="error-stack">$2</span>',
            '<span class="error-parse">$1</span>$2',
            '<span class="error-warning">$1</span>$2',
            '<span class="error-notice">$1</span>$2',
            '<span class="error-deprecated">$1</span>$2'
        ),
        $content
    );
    
    return $formatted;
}

/**
 * Convert UTC timestamps in error log content to Australia/Sydney timezone
 * 
 * @param string $content Raw log content
 * @return string Log content with converted timestamps
 */
function ccm_tools_convert_error_log_timestamps($content) {
    if (empty($content)) {
        return $content;
    }
    
    // PHP error log timestamp pattern: [dd-MMM-yyyy HH:mm:ss UTC]
    $pattern = '/\[(\d{2}-[A-Za-z]{3}-\d{4} \d{2}:\d{2}:\d{2}) UTC\]/';
    
    $converted = preg_replace_callback($pattern, function($matches) {
        $utc_time = $matches[1];
        
        try {
            // Parse the UTC timestamp
            $dt = DateTime::createFromFormat('d-M-Y H:i:s', $utc_time, new DateTimeZone('UTC'));
            
            if ($dt === false) {
                // If parsing fails, return original timestamp
                return $matches[0];
            }
            
            // Convert to Australia/Sydney timezone
            $dt->setTimezone(new DateTimeZone('Australia/Sydney'));
            
            // Format as the original format but with AEDT/AEST instead of UTC
            $tz_abbr = $dt->format('T');
            return '[' . $dt->format('d-M-Y H:i:s') . ' ' . $tz_abbr . ']';
            
        } catch (Exception $e) {
            // If any error occurs, return original timestamp
            return $matches[0];
        }
    }, $content);
    
    return $converted;
}

/**
 * Render the error log viewer page
 */
function ccm_tools_render_error_log_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.', 'ccm-tools'));
    }

    // Start output buffering to prevent "headers already sent" issues
    ob_start();

    $locations = ccm_tools_get_error_log_locations();
    $default_log = !empty($locations) ? $locations[0] : '';
    $log_data = !empty($default_log) ? ccm_tools_read_error_log($default_log) : array('content' => '', 'error' => __('No error logs found.', 'ccm-tools'));    // Format the log content to highlight errors
    if (isset($log_data['content'])) {
        // Convert UTC timestamps to Australia/Sydney timezone before formatting
        $log_data['content'] = ccm_tools_convert_error_log_timestamps($log_data['content']);
        $log_data['formatted_content'] = ccm_tools_format_error_log($log_data['content']);
    }
    ?>
    <div class="wrap ccm-tools">
        <div class="ccm-header">
            <div class="ccm-header-logo">
                <a href="<?php echo esc_url(admin_url('admin.php?page=ccm-tools')); ?>">
                    <img src="<?php echo esc_url(CCM_HELPER_ROOT_URL); ?>img/logo.svg" alt="CCM Tools">
                </a>
            </div>
            <nav class="ccm-header-menu">
                <div class="ccm-tabs">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=ccm-tools')); ?>" class="ccm-tab"><?php _e('System Info', 'ccm-tools'); ?></a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=ccm-tools-database')); ?>" class="ccm-tab"><?php _e('Database', 'ccm-tools'); ?></a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=ccm-tools-htaccess')); ?>" class="ccm-tab"><?php _e('.htaccess', 'ccm-tools'); ?></a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=ccm-tools-woocommerce')); ?>" class="ccm-tab"><?php _e('WooCommerce', 'ccm-tools'); ?></a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=ccm-tools-error-log')); ?>" class="ccm-tab active"><?php _e('Error Log', 'ccm-tools'); ?></a>
                </div>
            </nav>
            <div class="ccm-header-title">
                <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            </div>
        </div>
        <div class="ccm-content">
            <div class="ccm-card">
                <h2><?php _e('Error Log Viewer', 'ccm-tools'); ?></h2>

                <?php if (empty($locations)): ?>
                    <div class="ccm-notice ccm-warning">
                        <p><?php _e('No error log files found. Please check your PHP configuration.', 'ccm-tools'); ?></p>
                    </div>
                <?php else: ?>
                    <div class="ccm-error-log-controls">
                        <div class="ccm-error-log-header">
                            <div>
                                <label for="log-file-select"><?php _e('Select log file:', 'ccm-tools'); ?></label>
                                <select id="log-file-select">
                                    <?php foreach ($locations as $location): ?>
                                        <option value="<?php echo esc_attr($location); ?>"><?php echo esc_html($location); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>                            <div>
                                <label for="log-lines"><?php _e('Lines to display:', 'ccm-tools'); ?></label>
                                <select id="log-lines">
                                    <option value="50">50</option>
                                    <option value="100" selected>100</option>
                                    <option value="250">250</option>
                                    <option value="500">500</option>
                                    <option value="1000">1000</option>
                                </select>
                                <label class="show-stack-trace">
                                    <input type="checkbox" id="highlight-errors" checked>
                                    <?php _e('Highlight Errors', 'ccm-tools'); ?>
                                </label>
                                <label class="show-errors-only">
                                    <input type="checkbox" id="show-errors-only">
                                    <?php _e('Show Errors Only', 'ccm-tools'); ?>
                                </label>
                            </div>
                        </div>

                        <div class="ccm-error-log-info">
                            <div class="ccm-log-meta">
                                <p><strong><?php _e('Size:', 'ccm-tools'); ?></strong> <span id="log-size"><?php echo isset($log_data['file_size']) ? esc_html($log_data['file_size']) : ''; ?></span></p>
                                <p><strong><?php _e('Last modified:', 'ccm-tools'); ?></strong> <span id="log-modified"><?php echo isset($log_data['last_modified']) ? esc_html($log_data['last_modified']) : ''; ?></span></p>
                            </div>
                            <div class="ccm-error-log-buttons">
                                <button id="refresh-log" class="ccm-button"><?php _e('Refresh Now', 'ccm-tools'); ?></button>
                                <button id="download-log" class="ccm-button ccm-button-success"><?php _e('Download Log', 'ccm-tools'); ?></button>
                                <button id="clear-log" class="ccm-button ccm-button-danger"><?php _e('Clear Log', 'ccm-tools'); ?></button>
                            </div>
                        </div>

                        <div class="ccm-refresh-timer">
                            <div class="ccm-refresh-progress"></div>
                            <p><?php _e('Auto-refreshes in', 'ccm-tools'); ?> <span id="refresh-countdown">30</span> <?php _e('seconds', 'ccm-tools'); ?></p>
                        </div>
                    </div>

                    <div class="ccm-error-log-viewer">
                        <pre id="error-log-content" class="highlight-enabled"><?php echo isset($log_data['formatted_content']) ? $log_data['formatted_content'] : esc_html($log_data['content']); ?></pre>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
    // End output buffering and output the content
    echo ob_get_clean();
}
