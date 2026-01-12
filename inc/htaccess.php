<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get available .htaccess options with descriptions
 * 
 * @return array Options grouped by risk level
 */
function ccm_tools_get_htaccess_options(): array {
    return array(
        'safe' => array(
            'label' => '✓ Safe Options (Always Included)',
            'options' => array(
                'caching' => array(
                    'label' => 'Browser Caching',
                    'description' => 'Set optimal cache durations for images, CSS, JS, and fonts',
                    'default' => true,
                    'locked' => true, // Always included
                ),
                'compression' => array(
                    'label' => 'Brotli + Gzip Compression',
                    'description' => 'Compress text, CSS, JS, JSON, XML, SVG, fonts, and WebAssembly',
                    'default' => true,
                    'locked' => true,
                ),
                'security_headers' => array(
                    'label' => 'Basic Security Headers',
                    'description' => 'X-Content-Type-Options, Referrer-Policy, Permissions-Policy',
                    'default' => true,
                    'locked' => true,
                ),
                'hsts_basic' => array(
                    'label' => 'HSTS (1 Year)',
                    'description' => 'Strict-Transport-Security header for HTTPS enforcement',
                    'default' => true,
                    'locked' => true,
                ),
                'https_redirect' => array(
                    'label' => 'HTTPS Redirect',
                    'description' => 'Redirect HTTP to HTTPS (with proxy support)',
                    'default' => true,
                    'locked' => true,
                ),
                'file_protection' => array(
                    'label' => 'Sensitive File Protection',
                    'description' => 'Block access to wp-config.php, .env, logs, backups, etc.',
                    'default' => true,
                    'locked' => true,
                ),
                'disable_indexes' => array(
                    'label' => 'Disable Directory Browsing',
                    'description' => 'Prevent directory listing with Options -Indexes',
                    'default' => true,
                    'locked' => true,
                ),
                'etag_removal' => array(
                    'label' => 'Remove ETags',
                    'description' => 'Disable ETags to reduce server overhead and improve caching',
                    'default' => true,
                    'locked' => true,
                ),
            ),
        ),
        'moderate' => array(
            'label' => '⚡ Moderate Options (Select as Needed)',
            'options' => array(
                'x_frame_options' => array(
                    'label' => 'X-Frame-Options: SAMEORIGIN',
                    'description' => 'Prevent clickjacking by blocking iframe embedding from other sites',
                    'default' => false,
                ),
                'x_xss_protection' => array(
                    'label' => 'X-XSS-Protection: 0',
                    'description' => 'Modern recommendation - disable legacy XSS filter (CSP is preferred)',
                    'default' => false,
                ),
                'hsts_subdomains' => array(
                    'label' => 'HSTS with includeSubDomains',
                    'description' => 'Extend HSTS to all subdomains (ensure all subdomains support HTTPS)',
                    'default' => false,
                ),
                'coop' => array(
                    'label' => 'Cross-Origin-Opener-Policy',
                    'description' => 'Isolate browsing context (same-origin). May affect popups/OAuth flows.',
                    'default' => false,
                ),
                'corp' => array(
                    'label' => 'Cross-Origin-Resource-Policy',
                    'description' => 'Restrict resource loading to same-origin. May break external embeds.',
                    'default' => false,
                ),
                'block_author_scan' => array(
                    'label' => 'Block Author Enumeration',
                    'description' => 'Block ?author=N queries to prevent username discovery',
                    'default' => true, // This one is relatively safe
                ),
            ),
        ),
    );
}

/**
 * Get the optimized .htaccess content
 * 
 * @param array $options Selected options
 * @return string Optimized .htaccess content
 */
function ccm_tools_htaccess_content($options = array()): string {
    // Default options if none provided (backward compatibility)
    if (empty($options) || !is_array($options)) {
        $options = array(
            'x_frame_options' => false,
            'x_xss_protection' => false,
            'hsts_subdomains' => false,
            'coop' => false,
            'corp' => false,
            'block_author_scan' => true,
        );
    }
    
    // Handle legacy $hardening parameter (backward compatibility)
    if (is_bool($options)) {
        $hardening = $options;
        $options = array(
            'x_frame_options' => $hardening,
            'x_xss_protection' => false,
            'hsts_subdomains' => false,
            'coop' => false,
            'corp' => false,
            'block_author_scan' => true,
        );
    }
    
    $base = "";
    $base .= "# BEGIN CCM Optimise - DO NOT CHANGE!\n";
    $base .= "# CCM Tools .htaccess optimization (2026 baseline)\n\n";
    
    // ===== CACHING =====
    $base .= "# Browser Caching\n";
    $base .= "<IfModule mod_expires.c>\n";
    $base .= "ExpiresActive On\n";
    $base .= "# Images (1 year)\n";
    $base .= "ExpiresByType image/jpg \"access plus 1 year\"\n";
    $base .= "ExpiresByType image/jpeg \"access plus 1 year\"\n";
    $base .= "ExpiresByType image/gif \"access plus 1 year\"\n";
    $base .= "ExpiresByType image/png \"access plus 1 year\"\n";
    $base .= "ExpiresByType image/webp \"access plus 1 year\"\n";
    $base .= "ExpiresByType image/avif \"access plus 1 year\"\n";
    $base .= "ExpiresByType image/heic \"access plus 1 year\"\n";
    $base .= "ExpiresByType image/heif \"access plus 1 year\"\n";
    $base .= "ExpiresByType image/svg+xml \"access plus 1 year\"\n";
    $base .= "ExpiresByType image/x-icon \"access plus 1 year\"\n";
    $base .= "# Fonts (1 year)\n";
    $base .= "ExpiresByType font/woff2 \"access plus 1 year\"\n";
    $base .= "ExpiresByType font/woff \"access plus 1 year\"\n";
    $base .= "ExpiresByType font/ttf \"access plus 1 year\"\n";
    $base .= "ExpiresByType font/otf \"access plus 1 year\"\n";
    $base .= "ExpiresByType application/font-woff \"access plus 1 year\"\n";
    $base .= "ExpiresByType application/font-woff2 \"access plus 1 year\"\n";
    $base .= "# CSS/JS (1 month)\n";
    $base .= "ExpiresByType text/css \"access plus 1 month\"\n";
    $base .= "ExpiresByType application/javascript \"access plus 1 month\"\n";
    $base .= "ExpiresByType text/javascript \"access plus 1 month\"\n";
    $base .= "# Other\n";
    $base .= "ExpiresByType application/pdf \"access plus 1 month\"\n";
    $base .= "ExpiresByType application/wasm \"access plus 1 year\"\n";
    $base .= "ExpiresByType application/xml \"access plus 1 hour\"\n";
    $base .= "ExpiresByType text/xml \"access plus 1 hour\"\n";
    $base .= "ExpiresByType application/rss+xml \"access plus 1 hour\"\n";
    $base .= "ExpiresByType application/atom+xml \"access plus 1 hour\"\n";
    $base .= "ExpiresByType text/html \"access plus 0 seconds\"\n";
    $base .= "ExpiresDefault \"access plus 1 hour\"\n";
    $base .= "</IfModule>\n\n";
    
    // ===== COMPRESSION =====
    $base .= "# Compression (Brotli with gzip fallback)\n";
    $base .= "<IfModule mod_brotli.c>\n";
    $base .= "AddOutputFilterByType BROTLI_COMPRESS text/html text/plain text/css text/xml\n";
    $base .= "AddOutputFilterByType BROTLI_COMPRESS application/javascript application/json application/xml\n";
    $base .= "AddOutputFilterByType BROTLI_COMPRESS image/svg+xml image/x-icon\n";
    $base .= "AddOutputFilterByType BROTLI_COMPRESS font/ttf font/otf font/woff font/woff2\n";
    $base .= "AddOutputFilterByType BROTLI_COMPRESS application/wasm\n";
    $base .= "</IfModule>\n";
    $base .= "<IfModule mod_deflate.c>\n";
    $base .= "AddOutputFilterByType DEFLATE text/html text/plain text/css text/xml\n";
    $base .= "AddOutputFilterByType DEFLATE application/javascript application/json application/xml\n";
    $base .= "AddOutputFilterByType DEFLATE image/svg+xml image/x-icon\n";
    $base .= "AddOutputFilterByType DEFLATE font/ttf font/otf font/woff font/woff2\n";
    $base .= "AddOutputFilterByType DEFLATE application/wasm\n";
    $base .= "</IfModule>\n\n";
    
    // ===== ETAG REMOVAL =====
    $base .= "# Remove ETags (reduces server overhead)\n";
    $base .= "<IfModule mod_headers.c>\n";
    $base .= "Header unset ETag\n";
    $base .= "</IfModule>\n";
    $base .= "FileETag None\n\n";
    
    // ===== CACHE-CONTROL HEADERS =====
    $base .= "# Cache-Control Headers\n";
    $base .= "<IfModule mod_headers.c>\n";
    $base .= "# Long-lived static assets (1 year)\n";
    $base .= "<FilesMatch \"\\.(ico|pdf|jpg|jpeg|png|webp|avif|heic|heif|gif|svg|woff2|woff|ttf|otf|wasm)$\">\n";
    $base .= "Header set Cache-Control \"public, max-age=31536000, immutable\"\n";
    $base .= "</FilesMatch>\n";
    $base .= "# CSS/JS (1 month)\n";
    $base .= "<FilesMatch \"\\.(css|js)$\">\n";
    $base .= "Header set Cache-Control \"public, max-age=2592000\"\n";
    $base .= "</FilesMatch>\n";
    $base .= "</IfModule>\n\n";
    
    // ===== SECURITY HEADERS =====
    $base .= "# Security Headers\n";
    $base .= "<IfModule mod_headers.c>\n";
    $base .= "# Prevent MIME-type sniffing\n";
    $base .= "Header always set X-Content-Type-Options \"nosniff\"\n";
    $base .= "# Control referrer information\n";
    $base .= "Header always set Referrer-Policy \"strict-origin-when-cross-origin\"\n";
    $base .= "# Restrict browser features\n";
    $base .= "Header always set Permissions-Policy \"geolocation=(), microphone=(), camera=(), payment=(), usb=()\"\n";
    
    // Optional: X-Frame-Options
    if (!empty($options['x_frame_options'])) {
        $base .= "# Prevent clickjacking\n";
        $base .= "Header always set X-Frame-Options \"SAMEORIGIN\"\n";
    }
    
    // Optional: X-XSS-Protection
    if (!empty($options['x_xss_protection'])) {
        $base .= "# Disable legacy XSS filter (modern CSP is preferred)\n";
        $base .= "Header always set X-XSS-Protection \"0\"\n";
    }
    
    // Optional: Cross-Origin headers
    if (!empty($options['coop'])) {
        $base .= "# Isolate browsing context\n";
        $base .= "Header always set Cross-Origin-Opener-Policy \"same-origin\"\n";
    }
    
    if (!empty($options['corp'])) {
        $base .= "# Restrict resource loading\n";
        $base .= "Header always set Cross-Origin-Resource-Policy \"same-origin\"\n";
    }
    
    $base .= "</IfModule>\n\n";
    
    // ===== HSTS =====
    $base .= "# HSTS (HTTP Strict Transport Security)\n";
    $base .= "<IfModule mod_headers.c>\n";
    $base .= "SetEnvIf X-Forwarded-Proto https HTTPS=on\n";
    if (!empty($options['hsts_subdomains'])) {
        $base .= "Header always set Strict-Transport-Security \"max-age=31536000; includeSubDomains\" env=HTTPS\n";
    } else {
        $base .= "Header always set Strict-Transport-Security \"max-age=31536000\" env=HTTPS\n";
    }
    $base .= "# Auto-upgrade insecure requests\n";
    $base .= "Header always set Content-Security-Policy \"upgrade-insecure-requests\"\n";
    $base .= "</IfModule>\n\n";
    
    // ===== DIRECTORY SECURITY =====
    $base .= "# Disable directory browsing\n";
    $base .= "Options -Indexes\n\n";
    
    // ===== HTTPS REDIRECT =====
    $base .= "# HTTPS Redirect (with proxy support)\n";
    $base .= "<IfModule mod_rewrite.c>\n";
    $base .= "RewriteEngine On\n";
    $base .= "RewriteCond %{HTTPS} !=on\n";
    $base .= "RewriteCond %{HTTP:X-Forwarded-Proto} !https\n";
    $base .= "RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]\n";
    
    // Optional: Block author enumeration
    if (!empty($options['block_author_scan'])) {
        $base .= "\n# Block username enumeration\n";
        $base .= "RewriteCond %{QUERY_STRING} author=\\d\n";
        $base .= "RewriteRule ^(.*)$ /? [R=301,L]\n";
    }
    
    $base .= "</IfModule>\n\n";
    
    // ===== FILE PROTECTION =====
    $base .= "# Protect sensitive files\n";
    $base .= "<Files wp-config.php>\n";
    $base .= "Require all denied\n";
    $base .= "</Files>\n";
    $base .= "<Files .htaccess>\n";
    $base .= "Require all denied\n";
    $base .= "</Files>\n";
    $base .= "<FilesMatch \"^(wp-config\\.php|php\\.ini|\\.[hH][tT][aApP]|readme\\.html|license\\.txt)$\">\n";
    $base .= "Require all denied\n";
    $base .= "</FilesMatch>\n";
    $base .= "<FilesMatch \"(\\.env|\\.env\\..*|composer\\.(json|lock)|package(-lock)?\\.json|yarn\\.lock|pnpm-lock\\.yaml)$\">\n";
    $base .= "Require all denied\n";
    $base .= "</FilesMatch>\n";
    $base .= "<FilesMatch \"\\.(log|sql|bak|backup|old|tmp|temp|swp|swo|~)$\">\n";
    $base .= "Require all denied\n";
    $base .= "</FilesMatch>\n";
    $base .= "<Files \"debug.log\">\n";
    $base .= "Require all denied\n";
    $base .= "</Files>\n";
    $base .= "<Files \"wp-config-sample.php\">\n";
    $base .= "Require all denied\n";
    $base .= "</Files>\n\n";
    
    // ===== VCS PROTECTION =====
    $base .= "# Hide version control directories\n";
    $base .= "<IfModule mod_alias.c>\n";
    $base .= "RedirectMatch 404 /(\\.git|\\.svn|\\.hg)(/|$)\n";
    $base .= "</IfModule>\n";
    
    $base .= "# END CCM Optimise - DO NOT CHANGE!\n";
    return $base;
}

/**
 * Parse current .htaccess to detect which options are enabled
 * 
 * @param string $content Current .htaccess content
 * @return array Detected options
 */
function ccm_tools_detect_htaccess_options(string $content): array {
    $options = array(
        'x_frame_options' => strpos($content, 'X-Frame-Options') !== false && strpos($content, '# Header always set X-Frame-Options') === false,
        'x_xss_protection' => strpos($content, 'X-XSS-Protection') !== false,
        'hsts_subdomains' => strpos($content, 'includeSubDomains') !== false,
        'coop' => strpos($content, 'Cross-Origin-Opener-Policy') !== false,
        'corp' => strpos($content, 'Cross-Origin-Resource-Policy') !== false,
        'block_author_scan' => strpos($content, 'author=') !== false,
    );
    return $options;
}

/**
 * Display current .htaccess content
 * 
 * @return string HTML output
 */
function ccm_tools_display_htaccess(): string {
    // Check user capabilities
    if (!current_user_can('manage_options')) {
        return '';
    }

    $htaccess_file = ABSPATH . '.htaccess';
    
    if (!file_exists($htaccess_file)) {
        return '<p class="ccm-warning"><span class="ccm-icon">⚠</span>' . __('.htaccess file not found.', 'ccm-tools') . '</p>';
    }
    
    if (!is_readable($htaccess_file)) {
        return '<p class="ccm-error"><span class="ccm-icon">✗</span>' . __('.htaccess file exists but is not readable.', 'ccm-tools') . '</p>';
    }
    
    $current_content = file_get_contents($htaccess_file);
    if ($current_content === false) {
        return '<p class="ccm-error"><span class="ccm-icon">✗</span>' . __('Failed to read .htaccess file.', 'ccm-tools') . '</p>';
    }
    
    $has_optimizations = strpos($current_content, '# BEGIN CCM Optimise') !== false;
    $current_options = ccm_tools_detect_htaccess_options($current_content);
    $available_options = ccm_tools_get_htaccess_options();

    $output = '<h3>' . __('Current .htaccess Status', 'ccm-tools') . '</h3>';
    
    if ($has_optimizations) {
        $output .= '<p class="ccm-success"><span class="ccm-icon">✓</span>' . __('CCM Optimizations are currently applied.', 'ccm-tools') . '</p>';
    } else {
        $output .= '<p class="ccm-info"><span class="ccm-icon">ℹ</span>' . __('CCM Optimizations are not currently applied.', 'ccm-tools') . '</p>';
    }
    
    // Options container
    $output .= '<div id="htaccess-options" class="ccm-optimization-options">';
    
    // Safe options (always included, shown as locked)
    $output .= '<div class="ccm-opt-group safe">';
    $output .= '<div class="ccm-opt-group-header">' . esc_html($available_options['safe']['label']) . '</div>';
    $output .= '<div class="ccm-opt-group-items">';
    foreach ($available_options['safe']['options'] as $key => $opt) {
        $is_applied = $has_optimizations;
        $status_class = $is_applied ? 'ccm-status-applied' : 'ccm-status-pending';
        $status_icon = $is_applied ? '✓' : '○';
        $status_text = $is_applied ? __('Applied', 'ccm-tools') : __('Will be applied', 'ccm-tools');
        
        $output .= '<div class="ccm-opt-item ' . $status_class . '">';
        $output .= '<input type="checkbox" id="ht-' . esc_attr($key) . '" checked disabled>';
        $output .= '<div class="ccm-opt-item-content">';
        $output .= '<label class="ccm-opt-item-label" for="ht-' . esc_attr($key) . '">' . esc_html($opt['label']) . '</label>';
        $output .= '<span class="ccm-opt-item-desc">' . esc_html($opt['description']) . '</span>';
        $output .= '</div>';
        $output .= '<span class="ccm-opt-item-status ' . $status_class . '" title="' . esc_attr($status_text) . '">' . $status_icon . ' <small>' . esc_html($status_text) . '</small></span>';
        $output .= '</div>';
    }
    $output .= '</div></div>';
    
    // Moderate options (selectable)
    $output .= '<div class="ccm-opt-group moderate">';
    $output .= '<div class="ccm-opt-group-header">' . esc_html($available_options['moderate']['label']) . '</div>';
    $output .= '<div class="ccm-opt-group-items">';
    foreach ($available_options['moderate']['options'] as $key => $opt) {
        $is_applied = $has_optimizations && !empty($current_options[$key]);
        $checked = $is_applied ? 'checked' : (!$has_optimizations && !empty($opt['default']) ? 'checked' : '');
        
        // Determine status
        if ($has_optimizations) {
            if ($is_applied) {
                $status_class = 'ccm-status-applied';
                $status_icon = '✓';
                $status_text = __('Applied', 'ccm-tools');
            } else {
                $status_class = 'ccm-status-not-applied';
                $status_icon = '○';
                $status_text = __('Not applied', 'ccm-tools');
            }
        } else {
            $status_class = 'ccm-status-pending';
            $status_icon = '○';
            $status_text = $checked ? __('Will be applied', 'ccm-tools') : __('Optional', 'ccm-tools');
        }
        
        $output .= '<div class="ccm-opt-item ' . $status_class . '">';
        $output .= '<input type="checkbox" id="ht-' . esc_attr($key) . '" name="htaccess_options[]" value="' . esc_attr($key) . '" ' . $checked . '>';
        $output .= '<div class="ccm-opt-item-content">';
        $output .= '<label class="ccm-opt-item-label" for="ht-' . esc_attr($key) . '">' . esc_html($opt['label']) . '</label>';
        $output .= '<span class="ccm-opt-item-desc">' . esc_html($opt['description']) . '</span>';
        $output .= '</div>';
        $output .= '<span class="ccm-opt-item-status ' . $status_class . '" title="' . esc_attr($status_text) . '">' . $status_icon . ' <small>' . esc_html($status_text) . '</small></span>';
        $output .= '</div>';
    }
    $output .= '</div></div>';
    
    $output .= '</div>'; // End options container
    
    // Buttons
    $output .= '<div class="ccm-button-group" style="margin-top: 1rem;">';
    if ($has_optimizations) {
        $output .= '<button id="htupdate" class="ccm-button ccm-button-primary">' . __('Update Optimizations', 'ccm-tools') . '</button> ';
        $output .= '<button id="htremove" class="ccm-button">' . __('Remove Optimizations', 'ccm-tools') . '</button>';
    } else {
        $output .= '<button id="htadd" class="ccm-button ccm-button-primary">' . __('Add Optimizations', 'ccm-tools') . '</button>';
    }
    $output .= '</div>';
    
    // Result box
    $output .= '<div id="htaccess-result" class="ccm-result-box" style="display:none; margin-top: 1rem;"></div>';
    
    $output .= '<h3 style="margin-top: 2rem;">' . __('Current .htaccess Content', 'ccm-tools') . '</h3>';
    $output .= '<div class="ccm-htaccess-viewer"><pre>' . esc_html($current_content) . '</pre></div>';

    return $output;
}

/**
 * Clean up excessive blank lines in .htaccess content
 * 
 * @param string $content The .htaccess content to clean
 * @return string Cleaned content
 */
function ccm_tools_cleanup_htaccess_content(string $content): string {
    // Remove multiple consecutive blank lines and replace with single blank line
    $content = preg_replace('/\n\s*\n\s*\n+/', "\n\n", $content);
    
    // Remove blank lines at the beginning of the file
    $content = ltrim($content, "\n\r\t ");
    
    // Ensure exactly one blank line after "# END CCM Optimise - DO NOT CHANGE!" if there's content after it
    $content = preg_replace('/# END CCM Optimise - DO NOT CHANGE!\n+/', "# END CCM Optimise - DO NOT CHANGE!\n\n", $content);
    
    // Remove excessive blank lines at the end of CCM block when followed by other content
    $content = preg_replace('/# END CCM Optimise - DO NOT CHANGE!\n\n+(\S)/', "# END CCM Optimise - DO NOT CHANGE!\n\n$1", $content);
    
    // Ensure single trailing newline at end of file
    $content = rtrim($content) . "\n";
    
    return $content;
}

/**
 * Update .htaccess file
 * 
 * @param string $action 'add', 'update', or 'remove'
 * @param array $options Selected options
 * @return array Result with success status and message
 */
function ccm_tools_update_htaccess(string $action, $options = array()): array {
    // Check user capabilities
    if (!current_user_can('manage_options')) {
        return array(
            'success' => false, 
            'message' => __('You do not have permission to perform this action.', 'ccm-tools')
        );
    }
    
    // Handle legacy boolean $hardening parameter
    if (is_bool($options)) {
        $options = array('x_frame_options' => $options, 'block_author_scan' => true);
    }
    
    $htaccess_file = ABSPATH . '.htaccess';
    
    if (!file_exists($htaccess_file)) {
        if ($action === 'add' || $action === 'update') {
            // Create new .htaccess file with optimizations
            $new_content = ccm_tools_htaccess_content($options);
            $new_content = ccm_tools_cleanup_htaccess_content($new_content);
            $result = file_put_contents($htaccess_file, $new_content);
            if ($result !== false) {
                return array(
                    'success' => true, 
                    'message' => __('.htaccess file created with optimizations.', 'ccm-tools')
                );
            } else {
                return array(
                    'success' => false, 
                    'message' => __('Failed to create .htaccess file.', 'ccm-tools')
                );
            }
        } else {
            return array(
                'success' => false, 
                'message' => __('.htaccess file does not exist.', 'ccm-tools')
            );
        }
    }
    
    if (!is_writable($htaccess_file)) {
        return array(
            'success' => false, 
            'message' => __('.htaccess file is not writable.', 'ccm-tools')
        );
    }
    
    $current_content = file_get_contents($htaccess_file);
    if ($current_content === false) {
        return array(
            'success' => false, 
            'message' => __('Failed to read .htaccess file.', 'ccm-tools')
        );
    }
    
    $ccm_content = ccm_tools_htaccess_content($options);
    
    if ($action === 'add') {
        // Check if optimizations are already applied
        if (strpos($current_content, '# BEGIN CCM Optimise') !== false) {
            return array(
                'success' => false, 
                'message' => __('Optimizations are already applied. Use Update instead.', 'ccm-tools')
            );
        }
        
        // Add optimizations to the beginning of the file
        $new_content = $ccm_content . "\n" . $current_content;
        
        // Clean up excessive blank lines
        $new_content = ccm_tools_cleanup_htaccess_content($new_content);
        
        if (file_put_contents($htaccess_file, $new_content) !== false) {
            return array(
                'success' => true, 
                'message' => __('Optimizations successfully added to .htaccess.', 'ccm-tools')
            );
        } else {
            return array(
                'success' => false, 
                'message' => __('Failed to update .htaccess file.', 'ccm-tools')
            );
        }
    } else if ($action === 'update') {
        // Check if optimizations exist
        if (strpos($current_content, '# BEGIN CCM Optimise') === false) {
            // No existing optimizations, add them
            $new_content = $ccm_content . "\n" . $current_content;
        } else {
            // Replace existing optimizations
            $pattern = '/# BEGIN CCM Optimise - DO NOT CHANGE!.*?# END CCM Optimise - DO NOT CHANGE!/s';
            $new_content = preg_replace($pattern, trim($ccm_content), $current_content);
        }
        
        // Clean up excessive blank lines
        $new_content = ccm_tools_cleanup_htaccess_content($new_content);
        
        if (file_put_contents($htaccess_file, $new_content) !== false) {
            return array(
                'success' => true, 
                'message' => __('Optimizations successfully updated.', 'ccm-tools')
            );
        } else {
            return array(
                'success' => false, 
                'message' => __('Failed to update .htaccess file.', 'ccm-tools')
            );
        }
    } else if ($action === 'remove') {
        // Check if optimizations are applied
        if (strpos($current_content, '# BEGIN CCM Optimise') === false) {
            return array(
                'success' => false, 
                'message' => __('No optimizations found to remove.', 'ccm-tools')
            );
        }
        
        // Remove optimizations
        $pattern = '/# BEGIN CCM Optimise - DO NOT CHANGE!.*?# END CCM Optimise - DO NOT CHANGE!/s';
        $new_content = preg_replace($pattern, '', $current_content);
        
        // Clean up excessive blank lines after removal
        $new_content = ccm_tools_cleanup_htaccess_content($new_content);
        
        if (file_put_contents($htaccess_file, $new_content) !== false) {
            return array(
                'success' => true, 
                'message' => __('Optimizations successfully removed from .htaccess.', 'ccm-tools')
            );
        } else {
            return array(
                'success' => false, 
                'message' => __('Failed to update .htaccess file.', 'ccm-tools')
            );
        }
    }
    
    return array(
        'success' => false, 
        'message' => __('Invalid action.', 'ccm-tools')
    );
}
