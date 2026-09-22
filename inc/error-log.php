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
    
    // Try to get error log path from PHP configuration.
    // Only trust it when it resolves inside THIS site's own directory tree.
    // On a shared PHP pool, ini_get('error_log') can point at a server-wide
    // file that other tenants also log to and that the PHP user can write
    // to; whitelisting it unconditionally would let this site's admin read
    // (and, via the "clear log" action, truncate) every other tenant's log.
    $php_error_log = ini_get('error_log');
    if (!empty($php_error_log) && $php_error_log !== 'syslog' && ccm_tools_path_is_within_site($php_error_log)) {
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

/**
 * Check whether a (possibly not-yet-existing) path resolves to somewhere
 * inside this site's own directory tree (ABSPATH or its parent directory).
 *
 * Used to keep host-wide configuration (like PHP's ini "error_log" setting)
 * from being whitelisted when it points outside the site, which on a shared
 * pool can be a file other tenants also use.
 *
 * @param string $path Path to check.
 * @return bool
 */
function ccm_tools_path_is_within_site($path) {
    if (empty($path)) {
        return false;
    }

    $real_path = realpath($path);
    if (!$real_path) {
        return false;
    }

    $allowed_roots = array_filter(array(
        realpath(ABSPATH),
        realpath(dirname(ABSPATH)),
    ));

    foreach ($allowed_roots as $root) {
        if ($real_path === $root || strpos($real_path, rtrim($root, '/\\') . DIRECTORY_SEPARATOR) === 0) {
            return true;
        }
    }

    return false;
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
            'formatted_content' => ccm_tools_format_error_log(''),
            'error' => __('Invalid log file selection.', 'ccm-tools'),
            'file_size' => 0,
            'last_modified' => 0
        );
    }

    if (!file_exists($log_file) || !is_readable($log_file)) {
        return array(
            'content' => '',
            'formatted_content' => ccm_tools_format_error_log(''),
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
        } else {
            $content = __('Failed to open log file for reading.', 'ccm-tools');
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
    
    // Convert UTC timestamps to the site's configured timezone (via wp_timezone())
    $content = ccm_tools_convert_error_log_timestamps($content);

    return array(
        'content' => $content,
        // Always build an escaped, highlighted rendering alongside the raw content.
        // The raw log can contain attacker-controlled text (a plugin logging request
        // data, a PHP warning quoting user input); the JS viewer renders
        // formatted_content into innerHTML on every auto-refresh, so this must never
        // be skipped or left to fall back to unescaped raw content.
        'formatted_content' => ccm_tools_format_error_log($content),
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
    $lines = isset($_POST['lines']) ? max(1, min(10000, intval($_POST['lines']))) : 100;
    $offset = isset($_POST['offset']) ? max(0, min(100000, intval($_POST['offset']))) : 0;
    
    // Check for errors_only parameter - handle various boolean formats
    $errors_only = false;
    if (isset($_POST['errors_only'])) {
        $errors_only = filter_var($_POST['errors_only'], FILTER_VALIDATE_BOOLEAN);
    }
    
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

    // Get file metadata first
    $filesize = filesize($log_file);
    $last_modified = filemtime($log_file);

    // If errors_only is enabled, filter the content to show only PHP Fatal/Parse errors
    if ($errors_only) {
        if (!file_exists($log_file) || !is_readable($log_file)) {
            wp_send_json_error(array('message' => __('Log file not found or not readable.', 'ccm-tools')));
        }
        
        // Guard against reading excessively large files
        $filesize = @filesize($log_file);
        if ($filesize > 5 * 1024 * 1024) {
            wp_send_json_error(array('message' => __('Log file too large for error filtering. Please clear the log first.', 'ccm-tools')));
        }
        
        $full_content = @file_get_contents($log_file);
        if ($full_content === false) {
            wp_send_json_error(array('message' => __('Failed to read log file.', 'ccm-tools')));
        }
        
        // Convert timestamps first
        $full_content = ccm_tools_convert_error_log_timestamps($full_content);
        
        // Filter to show only fatal errors and their stack traces
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
        
        // Format the filtered content for display with syntax highlighting
        $formatted_content = !empty($filtered_content) ? ccm_tools_format_error_log($filtered_content) : '';
        
        wp_send_json_success(array(
            'content' => $filtered_content,
            'formatted_content' => $formatted_content,
            'file_size' => size_format($filesize, 2),
            'last_modified' => human_time_diff($last_modified) . ' ' . __('ago', 'ccm-tools'),
            'raw_last_modified' => $last_modified,
            'filtered' => true
        ));
    }

    // Normal unfiltered log reading
    $log_data = ccm_tools_read_error_log($log_file, $lines, $offset);
    $log_data['filtered'] = false;
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
    
    // Escape HTML first to prevent stored XSS from log content, then layer the
    // highlighting markup on top of the already-escaped text.
    $content = htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

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

    // preg_replace() returns null on a regex engine failure (e.g. hitting the
    // PCRE backtrack limit on a very large stack trace). Fall back to the
    // already-escaped, unhighlighted content rather than blanking the viewer.
    if ($formatted === null) {
        return $content;
    }

    return $formatted;
}

/**
 * Convert UTC timestamps in error log content to the site's configured
 * timezone (via wp_timezone() / WordPress's Timezone setting), not a
 * hardcoded zone.
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
            
            // Convert to WordPress-configured timezone
            $dt->setTimezone(wp_timezone());
            
            // Format as the original format but with timezone abbreviation
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
 * Summarise the log text already loaded for the viewer: counts for the
 * at-a-glance stat tiles, the severity of the most recent entry, and a
 * ranked list of recurring problems for the findings summary.
 *
 * Deliberately works from the same (already line-limited) text the page
 * already reads for the raw viewer below it — no extra file read and no
 * extra AJAX call. This only ever reads $content; it never echoes it back.
 * Callers must still esc_html() every fragment this returns before output,
 * the same as any other value pulled out of log text.
 *
 * @param string $content Raw (unescaped) log text, already limited to the visible window.
 * @return array {
 *     @type int        fatal         Fatal / parse error lines.
 *     @type int        warning       Warning + deprecated lines.
 *     @type string     last_severity Severity of the last recognised line: fatal|warning|deprecated|notice|''.
 *     @type array[]    findings      Ranked array of array('severity','message','file','count'), highest count first.
 *     @type array|null top_plugin    array('slug','count','total') when one plugin accounts for at least half
 *                                    of the fatals found, otherwise null.
 * }
 */
function ccm_tools_error_log_analyze($content) {
    $result = array(
        'fatal'         => 0,
        'warning'       => 0,
        'last_severity' => '',
        'findings'      => array(),
        'top_plugin'    => null,
    );

    if (empty($content)) {
        return $result;
    }

    $groups        = array();
    $plugin_fatals = array();

    foreach (explode("\n", $content) as $line) {
        if (trim($line) === '') {
            continue;
        }

        $severity = '';
        $message  = '';

        if (preg_match('/PHP (?:Fatal error|Parse error|Catchable fatal error):\s*(.*)$/i', $line, $m)) {
            $severity = 'fatal';
            $message  = $m[1];
            $result['fatal']++;
        } elseif (preg_match('/PHP Warning:\s*(.*)$/i', $line, $m)) {
            $severity = 'warning';
            $message  = $m[1];
            $result['warning']++;
        } elseif (preg_match('/PHP Deprecated:\s*(.*)$/i', $line, $m)) {
            $severity = 'deprecated';
            $message  = $m[1];
            $result['warning']++;
        } elseif (preg_match('/PHP Notice:/i', $line)) {
            // Notices are routine and excluded from the counts above; still
            // worth knowing about for "since last entry", nothing more.
            $result['last_severity'] = 'notice';
            continue;
        } else {
            continue;
        }

        $result['last_severity'] = $severity;
        $message = trim($message);

        // Pull the file out of "... in /path/file.php:23" or
        // "... in /path/file.php on line 23" so the same bug from the same
        // file groups together even when the reported line number moves.
        $file = '';
        if (preg_match('#\sin\s(/\S+?\.php)(?::\d+)?#i', $message, $fm)) {
            $file    = $fm[1];
            $message = str_replace($fm[0], '', $message);
        }
        $no_line = preg_replace('/\bon line \d+\b/i', '', $message);
        $message = $no_line !== null ? $no_line : $message;
        $tidy    = preg_replace('/\s+/', ' ', trim($message));
        $message = ($tidy !== null && $tidy !== '') ? $tidy : trim($line);

        $short_file = $file !== '' ? ccm_tools_error_log_relative_path($file) : '';

        $key = $severity . '|' . strtolower($message) . '|' . strtolower($short_file);
        if (!isset($groups[$key])) {
            $groups[$key] = array(
                'severity' => $severity,
                'message'  => $message,
                'file'     => $short_file,
                'count'    => 0,
            );
        }
        $groups[$key]['count']++;

        if ($severity === 'fatal' && $file !== '' && preg_match('#wp-content/plugins/([^/]+)/#i', $file, $pm)) {
            $slug = $pm[1];
            $plugin_fatals[$slug] = isset($plugin_fatals[$slug]) ? $plugin_fatals[$slug] + 1 : 1;
        }
    }

    usort($groups, function ($a, $b) {
        return $b['count'] <=> $a['count'];
    });

    $result['findings'] = array_slice($groups, 0, 5);

    // When one plugin is behind most of the fatals, that is the single most
    // useful thing this page can say — surface it as its own alert.
    if ($result['fatal'] > 1 && !empty($plugin_fatals)) {
        arsort($plugin_fatals);
        $top_slug  = array_key_first($plugin_fatals);
        $top_count = $plugin_fatals[$top_slug];
        if ($top_count >= ceil($result['fatal'] * 0.5)) {
            $result['top_plugin'] = array(
                'slug'  => $top_slug,
                'count' => $top_count,
                'total' => $result['fatal'],
            );
        }
    }

    return $result;
}

/**
 * Shorten an absolute file path for display: relative to ABSPATH when it
 * lives inside this site (the common case), otherwise just its last few
 * segments, so a finding row stays on one line without spelling out the
 * full server path.
 *
 * @param string $path Absolute path pulled out of a log line.
 * @return string
 */
function ccm_tools_error_log_relative_path($path) {
    $normalized = wp_normalize_path($path);
    $abspath    = wp_normalize_path(ABSPATH);

    if ($abspath !== '' && strpos($normalized, $abspath) === 0) {
        return substr($normalized, strlen($abspath));
    }

    $segments = explode('/', trim($normalized, '/'));
    return implode('/', array_slice($segments, -3));
}

/**
 * Render the error log viewer page
 */
function ccm_tools_render_error_log_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.', 'ccm-tools'));
    }

    $locations   = ccm_tools_get_error_log_locations();
    $default_log = !empty($locations) ? $locations[0] : '';
    $log_data    = !empty($default_log) ? ccm_tools_read_error_log($default_log) : array('content' => '', 'error' => __('No error logs found.', 'ccm-tools'));
    // ccm_tools_read_error_log() already returns an escaped, highlighted
    // formatted_content on every branch (including the empty-log message).
    // Re-running that through ccm_tools_format_error_log() would
    // htmlspecialchars() already-built HTML a second time, turning its tags
    // into literal text, so only build formatted_content when it's missing
    // (e.g. when no default log was found at all, above).
    if (isset($log_data['content']) && !isset($log_data['formatted_content'])) {
        // Convert UTC timestamps to the site's configured timezone (via wp_timezone()) before formatting
        $log_data['content'] = ccm_tools_convert_error_log_timestamps($log_data['content']);
        $log_data['formatted_content'] = ccm_tools_format_error_log($log_data['content']);
    }

    $has_locations  = !empty($locations);
    $has_read_error = isset($log_data['error']);

    // Independent of $log_data — a direct filesystem check, so it stays
    // correct even where the read helper's own numbers are placeholders
    // (e.g. its "invalid selection" branch returns file_size => 0, not a
    // real size).
    $raw_size     = ($has_locations && file_exists($default_log)) ? @filesize($default_log) : false;
    $is_log_empty = $has_locations && $raw_size === 0;

    // Everything below is derived from the text already loaded for the
    // viewer above (the visible window) — no extra file read, no extra
    // AJAX call. ccm_tools_error_log_analyze() only ever reads $content;
    // the raw, unescaped text is never echoed, only short fragments pulled
    // out of it, and every one of those is still esc_html()'d below.
    $analysis = ($has_locations && !$has_read_error && isset($log_data['content']))
        ? ccm_tools_error_log_analyze($log_data['content'])
        : array('fatal' => 0, 'warning' => 0, 'last_severity' => '', 'findings' => array(), 'top_plugin' => null);

    // empty() also excludes the read-error branches' placeholder 0/'' values
    // (a real empty file reports file_size as the string "0 B", not 0), so
    // this only ever shows a genuine reading.
    $file_size_label     = !empty($log_data['file_size']) ? $log_data['file_size'] : '';
    $last_modified_label = !empty($log_data['last_modified']) ? (string) $log_data['last_modified'] : '';

    $size_dot  = 'ccm-dot-ok';
    $size_note = __('healthy', 'ccm-tools');
    if ($raw_size !== false && $raw_size > 4 * 1024 * 1024) {
        $size_dot  = 'ccm-dot-bad';
        $size_note = __('near the 5 MB display limit', 'ccm-tools');
    } elseif ($raw_size !== false && $raw_size > 1024 * 1024) {
        $size_dot  = 'ccm-dot-warn';
        $size_note = __('getting large', 'ccm-tools');
    }

    $entry_dot  = 'ccm-dot-ok';
    $entry_note = __('quiet', 'ccm-tools');
    if ($analysis['last_severity'] === 'fatal') {
        $entry_dot  = 'ccm-dot-bad';
        $entry_note = __('most recent entry was fatal', 'ccm-tools');
    } elseif ($analysis['last_severity'] === 'warning') {
        $entry_dot  = 'ccm-dot-warn';
        $entry_note = __('most recent entry was a warning', 'ccm-tools');
    } elseif ($analysis['last_severity'] === 'deprecated') {
        $entry_dot  = 'ccm-dot-warn';
        $entry_note = __('most recent entry was a deprecation notice', 'ccm-tools');
    } elseif ($analysis['last_severity'] === 'notice') {
        $entry_note = __('most recent entry was a notice', 'ccm-tools');
    }

    $stripe_class = array(
        'fatal'      => 'ccm-finding--high',
        'warning'    => 'ccm-finding--medium',
        'deprecated' => 'ccm-finding--low',
    );
    $max_finding_count = !empty($analysis['findings']) ? $analysis['findings'][0]['count'] : 0;
    ?>
    <div class="wrap ccm-tools ccm-tools-error-log">
        <?php
        if (function_exists('ccm_tools_render_header_nav')) {
            ccm_tools_render_header_nav('ccm-tools-error-log');
        }
        ?>
        <div class="ccm-content">

            <div class="ccm-hero">
                <div class="ccm-hero__text">
                    <h1><?php _e('Error Log', 'ccm-tools'); ?></h1>
                    <div class="ccm-hero__meta">
                        <?php if ($has_locations) : ?>
                            <span title="<?php echo esc_attr($default_log); ?>"><?php echo esc_html(basename($default_log)); ?></span>
                            <?php if ($file_size_label !== '') : ?>
                                <span><?php echo esc_html($file_size_label); ?></span>
                            <?php endif; ?>
                            <?php if ($last_modified_label !== '') : ?>
                                <span><?php printf(
                                    /* translators: %s: e.g. "5 minutes ago" — already includes the word "ago" */
                                    esc_html__('last written %s', 'ccm-tools'),
                                    esc_html($last_modified_label)
                                ); ?></span>
                            <?php endif; ?>
                        <?php else : ?>
                            <span><?php _e('no readable log file found', 'ccm-tools'); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($has_locations) : ?>
                    <div class="ccm-hero__actions">
                        <button type="button" id="refresh-log" class="ccm-button ccm-button-secondary"><?php _e('Refresh', 'ccm-tools'); ?></button>
                        <button type="button" id="download-log" class="ccm-button ccm-button-primary"><?php _e('Download', 'ccm-tools'); ?></button>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (!$has_locations) : ?>

                <div class="ccm-empty">
                    <span class="ccm-empty__icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><path d="M6 2h9l5 5v15H6z"/><path d="M15 2v5h5"/><path d="M9 13h6M9 17h6"/></svg>
                    </span>
                    <h3><?php _e('No error log file found', 'ccm-tools'); ?></h3>
                    <p><?php _e('CCM Tools looked in the usual WordPress and server locations and could not find a log file it can read. Check the error_log setting in your PHP configuration.', 'ccm-tools'); ?></p>
                </div>

            <?php else : ?>

                <?php if ($has_read_error && !empty($log_data['error'])) : ?>
                    <div class="ccm-alert ccm-alert--bad" style="margin-bottom: var(--ccm-space-lg);">
                        <span class="ccm-dot ccm-dot-bad" aria-hidden="true"></span>
                        <div><?php echo esc_html($log_data['error']); ?></div>
                    </div>
                <?php endif; ?>

                <div class="ccm-stat-grid">
                    <div class="ccm-stat-tile">
                        <div class="ccm-stat-tile__value"><span id="log-size"><?php echo esc_html($file_size_label); ?></span></div>
                        <div class="ccm-stat-tile__label"><?php _e('Log size', 'ccm-tools'); ?></div>
                        <div class="ccm-stat-tile__sub"><span class="ccm-dot <?php echo esc_attr($size_dot); ?>" aria-hidden="true"></span><?php echo esc_html($size_note); ?></div>
                    </div>
                    <div class="ccm-stat-tile">
                        <div class="ccm-stat-tile__value<?php echo $analysis['fatal'] > 0 ? ' ccm-stat-tile__value--brand' : ''; ?>"><?php echo (int) $analysis['fatal']; ?></div>
                        <div class="ccm-stat-tile__label"><?php _e('Fatal errors', 'ccm-tools'); ?></div>
                        <div class="ccm-stat-tile__sub"><span class="ccm-dot <?php echo $analysis['fatal'] > 0 ? 'ccm-dot-bad' : 'ccm-dot-ok'; ?>" aria-hidden="true"></span><?php _e('in the visible window', 'ccm-tools'); ?></div>
                    </div>
                    <div class="ccm-stat-tile">
                        <div class="ccm-stat-tile__value"><?php echo (int) $analysis['warning']; ?></div>
                        <div class="ccm-stat-tile__label"><?php _e('Warnings & deprecations', 'ccm-tools'); ?></div>
                        <div class="ccm-stat-tile__sub"><span class="ccm-dot <?php echo $analysis['warning'] > 0 ? 'ccm-dot-warn' : 'ccm-dot-ok'; ?>" aria-hidden="true"></span><?php _e('in the visible window', 'ccm-tools'); ?></div>
                    </div>
                    <div class="ccm-stat-tile">
                        <div class="ccm-stat-tile__value"><span id="log-modified"><?php echo esc_html($last_modified_label); ?></span></div>
                        <div class="ccm-stat-tile__label"><?php _e('Since last entry', 'ccm-tools'); ?></div>
                        <div class="ccm-stat-tile__sub"><span class="ccm-dot <?php echo esc_attr($entry_dot); ?>" aria-hidden="true"></span><?php echo esc_html($entry_note); ?></div>
                    </div>
                </div>

                <div class="ccm-toolbar ccm-toolbar--sticky">
                    <div>
                        <label for="log-file-select" style="font-size: var(--ccm-text-sm); font-weight: 600; color: var(--ccm-text-muted);"><?php _e('File', 'ccm-tools'); ?></label>
                        <select id="log-file-select" class="ccm-input" style="width: auto; min-width: 14rem;">
                            <?php foreach ($locations as $location) : ?>
                                <option value="<?php echo esc_attr($location); ?>" title="<?php echo esc_attr($location); ?>">
                                    <?php echo esc_html(ccm_tools_error_log_relative_path($location)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="log-lines" style="font-size: var(--ccm-text-sm); font-weight: 600; color: var(--ccm-text-muted);"><?php _e('Lines', 'ccm-tools'); ?></label>
                        <select id="log-lines" class="ccm-input" style="width: auto;">
                            <option value="50">50</option>
                            <option value="100" selected>100</option>
                            <option value="250">250</option>
                            <option value="500">500</option>
                            <option value="1000">1000</option>
                        </select>
                    </div>
                    <div>
                        <label for="log-filter" class="ccm-visually-hidden"><?php _e('Filter visible lines', 'ccm-tools'); ?></label>
                        <input type="search" id="log-filter" class="ccm-input" style="width: auto;" placeholder="<?php echo esc_attr__('Filter visible lines…', 'ccm-tools'); ?>">
                    </div>
                    <div class="ccm-row" style="gap: 0.5rem;">
                        <span style="font-size: var(--ccm-text-sm); font-weight: 600; color: var(--ccm-text-muted);"><?php _e('Errors only', 'ccm-tools'); ?></span>
                        <label class="ccm-toggle">
                            <input type="checkbox" id="show-errors-only">
                            <span class="ccm-toggle-slider"></span>
                        </label>
                    </div>
                    <div class="ccm-row" style="gap: 0.5rem;">
                        <span style="font-size: var(--ccm-text-sm); font-weight: 600; color: var(--ccm-text-muted);"><?php _e('Highlight', 'ccm-tools'); ?></span>
                        <label class="ccm-toggle">
                            <input type="checkbox" id="highlight-errors" checked>
                            <span class="ccm-toggle-slider"></span>
                        </label>
                    </div>
                    <span class="ccm-toolbar__spacer"></span>
                    <div class="ccm-refresh-timer" style="flex: 1 1 100%;">
                        <div class="ccm-refresh-progress"></div>
                        <p><?php _e('Auto-refreshes in', 'ccm-tools'); ?> <span id="refresh-countdown">30</span> <?php _e('seconds', 'ccm-tools'); ?></p>
                    </div>
                </div>

                <div class="ccm-section">
                    <div>
                        <span class="ccm-section__eyebrow"><?php _e('Ranked by frequency', 'ccm-tools'); ?></span>
                        <h2><?php _e("What's actually wrong", 'ccm-tools'); ?></h2>
                        <p><?php _e('Identical messages in the visible window are grouped into one row, so a bug that fired fifty times shows once, not fifty times.', 'ccm-tools'); ?></p>
                    </div>
                    <div class="ccm-row">
                        <span class="ccm-chip"><?php echo (int) count($analysis['findings']); ?> <?php _e('shown', 'ccm-tools'); ?></span>
                    </div>
                </div>

                <?php if ($analysis['top_plugin']) : ?>
                    <div class="ccm-alert ccm-alert--bad" style="margin-bottom: var(--ccm-space-md);">
                        <span class="ccm-dot ccm-dot-bad" aria-hidden="true"></span>
                        <div><?php printf(
                            /* translators: 1: plugin folder name (already wrapped in <strong> here), 2: fatals attributed to it, 3: total fatals in the visible window */
                            esc_html__('%1$s is responsible for %2$d of the %3$d fatal errors shown here.', 'ccm-tools'),
                            '<strong>' . esc_html($analysis['top_plugin']['slug']) . '</strong>',
                            (int) $analysis['top_plugin']['count'],
                            (int) $analysis['top_plugin']['total']
                        ); ?></div>
                    </div>
                <?php endif; ?>

                <?php if (empty($analysis['findings'])) : ?>
                    <div class="ccm-empty">
                        <span class="ccm-empty__icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><path d="M20 6L9 17l-5-5"/></svg>
                        </span>
                        <h3><?php _e('Nothing recurring in the visible window', 'ccm-tools'); ?></h3>
                        <p><?php _e('No fatal errors or warnings repeat in the lines currently loaded. Widen the line count above to look further back.', 'ccm-tools'); ?></p>
                    </div>
                <?php else : ?>
                    <div class="ccm-findings">
                        <?php foreach ($analysis['findings'] as $finding) :
                            $stripe = isset($stripe_class[$finding['severity']]) ? $stripe_class[$finding['severity']] : 'ccm-finding--low';
                            $pct    = $max_finding_count > 0 ? (int) round(($finding['count'] / $max_finding_count) * 100) : 0;
                        ?>
                            <div class="ccm-finding <?php echo esc_attr($stripe); ?>">
                                <div class="ccm-finding__stripe"></div>
                                <div class="ccm-finding__body">
                                    <p class="ccm-finding__title"><?php echo esc_html($finding['message']); ?></p>
                                    <?php if ($finding['file'] !== '') : ?>
                                        <p class="ccm-finding__detail"><?php echo esc_html($finding['file']); ?></p>
                                    <?php endif; ?>
                                </div>
                                <div class="ccm-finding__impact">
                                    <div class="ccm-finding__cost"><?php echo (int) $finding['count']; ?></div>
                                    <div class="ccm-finding__bar"><i style="width: <?php echo esc_attr($pct); ?>%;"></i></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="ccm-section">
                    <div>
                        <span class="ccm-section__eyebrow"><?php _e('Full detail', 'ccm-tools'); ?></span>
                        <h2><?php _e('Log output', 'ccm-tools'); ?></h2>
                        <p>
                            <?php echo $is_log_empty
                                ? esc_html__('This log is currently empty.', 'ccm-tools')
                                : esc_html__('Exactly what the controls above select, unfiltered by the summary.', 'ccm-tools'); ?>
                        </p>
                    </div>
                </div>

                <div class="ccm-panel">
                    <div class="ccm-panel__body--flush ccm-error-log-viewer">
                        <pre id="error-log-content" class="highlight-enabled"><?php echo isset($log_data['formatted_content']) ? $log_data['formatted_content'] : esc_html($log_data['content']); ?></pre>
                    </div>
                </div>

                <details class="ccm-disclose" style="margin-top: var(--ccm-space-lg);">
                    <summary>
                        <?php _e('Clear this log file', 'ccm-tools'); ?>
                        <span class="ccm-disclose__note"><?php _e('Destructive', 'ccm-tools'); ?></span>
                    </summary>
                    <div class="ccm-disclose__body">
                        <p class="ccm-text-muted" style="font-size: var(--ccm-text-sm); margin: 0 0 var(--ccm-space-md);">
                            <?php _e('Permanently empties the selected log file. This cannot be undone — anything in it that has not already been read here is gone for good.', 'ccm-tools'); ?>
                        </p>
                        <button type="button" id="clear-log" class="ccm-button ccm-button-danger">
                            <?php _e('Clear log file', 'ccm-tools'); ?>
                        </button>
                    </div>
                </details>

            <?php endif; ?>

        </div>
    </div>

    <?php if ($has_locations) : ?>
    <script>
    (function () {
        'use strict';

        // Lightweight, page-local text filter for the raw log view below the
        // findings summary. Deliberately self-contained: main.js owns the
        // AJAX refresh / clear / download logic and is not touched here. This
        // only ever reorganises DOM nodes that formatted_content already put
        // on the page — it never parses a new string into innerHTML, so it
        // cannot introduce anything the server-escaped content didn't
        // already contain.
        try {
            var input = document.getElementById('log-filter');
            var container = document.querySelector('.ccm-error-log-viewer');
            if (!input || !container) { return; }

            var wrapLines = function (pre) {
                if (!pre || pre.getAttribute('data-ccm-wrapped') === '1') { return; }
                var frag = document.createDocumentFragment();
                var current = document.createElement('span');
                current.className = 'ccm-log-line';

                function flush() {
                    frag.appendChild(current);
                    current = document.createElement('span');
                    current.className = 'ccm-log-line';
                }

                Array.prototype.slice.call(pre.childNodes).forEach(function (node) {
                    if (node.nodeType === 3) {
                        var parts = node.textContent.split('\n');
                        parts.forEach(function (part, i) {
                            if (i > 0) { flush(); }
                            if (part !== '') { current.appendChild(document.createTextNode(part)); }
                        });
                    } else {
                        current.appendChild(node);
                    }
                });
                flush();

                pre.innerHTML = '';
                pre.appendChild(frag);
                pre.setAttribute('data-ccm-wrapped', '1');
            };

            var applyFilter = function () {
                var pre = document.getElementById('error-log-content');
                if (!pre) { return; }
                try {
                    wrapLines(pre);
                    var q = input.value.toLowerCase();
                    var lines = pre.querySelectorAll('.ccm-log-line');
                    for (var i = 0; i < lines.length; i++) {
                        var show = !q || lines[i].textContent.toLowerCase().indexOf(q) !== -1;
                        lines[i].style.display = show ? '' : 'none';
                    }
                } catch (e) { /* cosmetic only — never block the real viewer */ }
            };

            input.addEventListener('input', applyFilter);

            if (window.MutationObserver) {
                new MutationObserver(applyFilter).observe(container, { childList: true });
            }

            applyFilter();
        } catch (e) { /* cosmetic only */ }
    })();
    </script>
    <?php endif; ?>
    <?php
}
