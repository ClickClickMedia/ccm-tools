<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get the optimized .htaccess content
 * 
 * @return string Optimized .htaccess content
 */
function ccm_tools_htaccess_content($hardening = false): string {
    $base = "";
    $base .= "# BEGIN CCM Optimise - DO NOT CHANGE!\n";
    $base .= "# Default 2025 .htaccess baseline (HTTPS-only sites)\n\n";
    $base .= "# Caching policies\n";
    $base .= "<IfModule mod_expires.c>\n";
    $base .= "ExpiresActive On\n";
    $base .= "ExpiresByType image/jpg \"access plus 1 year\"\n";
    $base .= "ExpiresByType image/jpeg \"access plus 1 year\"\n";
    $base .= "ExpiresByType image/gif \"access plus 1 year\"\n";
    $base .= "ExpiresByType image/png \"access plus 1 year\"\n";
    $base .= "ExpiresByType image/webp \"access plus 1 year\"\n";
    $base .= "ExpiresByType image/avif \"access plus 1 year\"\n";
    $base .= "ExpiresByType image/svg+xml \"access plus 1 year\"\n";
    $base .= "ExpiresByType font/woff2 \"access plus 1 year\"\n";
    $base .= "ExpiresByType font/woff \"access plus 1 year\"\n";
    $base .= "ExpiresByType font/ttf \"access plus 1 year\"\n";
    $base .= "ExpiresByType font/otf \"access plus 1 year\"\n";
    $base .= "ExpiresByType text/css \"access plus 1 month\"\n";
    $base .= "ExpiresByType application/javascript \"access plus 1 month\"\n";
    $base .= "ExpiresByType text/javascript \"access plus 1 month\"\n";
    $base .= "ExpiresByType application/pdf \"access plus 1 month\"\n";
    $base .= "ExpiresByType application/xml \"access plus 1 hour\"\n";
    $base .= "ExpiresByType text/xml \"access plus 1 hour\"\n";
    $base .= "ExpiresByType application/rss+xml \"access plus 1 hour\"\n";
    $base .= "ExpiresByType application/atom+xml \"access plus 1 hour\"\n";
    $base .= "ExpiresByType text/html \"access plus 0 seconds\"\n";
    $base .= "ExpiresDefault \"access plus 1 hour\"\n";
    $base .= "</IfModule>\n\n";
    $base .= "# Compression (Brotli + gzip fallback)\n";
    $base .= "<IfModule mod_brotli.c>\n";
    $base .= "AddOutputFilterByType BROTLI_COMPRESS text/html text/plain text/css application/javascript application/json application/xml image/svg+xml\n";
    $base .= "</IfModule>\n";
    $base .= "<IfModule mod_deflate.c>\n";
    $base .= "AddOutputFilterByType DEFLATE text/html text/plain text/css application/javascript application/json application/xml image/svg+xml\n";
    $base .= "</IfModule>\n\n";
    $base .= "# HTTP response headers\n";
    $base .= "<IfModule mod_headers.c>\n";
    $base .= "# Long-lived static assets\n";
    $base .= "<FilesMatch \"\\.(ico|pdf|jpg|jpeg|png|webp|gif|svg|avif|woff2|woff|ttf|otf)$\">\n";
    $base .= "Header always set Cache-Control \"public, max-age=31536000\"\n";
    $base .= "# If your filenames are versioned (hashed), you can switch to:\n";
    $base .= "# Header always set Cache-Control \"public, max-age=31536000, immutable\"\n";
    $base .= "</FilesMatch>\n";
    $base .= "# CSS/JS (moderate cache)\n";
    $base .= "<FilesMatch \"\\.(css|js)$\">\n";
    $base .= "Header always set Cache-Control \"public, max-age=2592000\"\n";
    $base .= "# If versioned filenames, you can switch to:\n";
    $base .= "# Header always set Cache-Control \"public, max-age=2592000, immutable\"\n";
    $base .= "</FilesMatch>\n\n";
    $base .= "# Security and privacy\n";
    $base .= "Header always set X-Content-Type-Options \"nosniff\"\n";
    $base .= "Header always set Referrer-Policy \"strict-origin-when-cross-origin\"\n";
    $base .= "Header always set Permissions-Policy \"geolocation=(), microphone=(), camera=()\"\n";
    if ($hardening) {
        $base .= "\n# Optional hardening\n";
        $base .= "Header always set X-Frame-Options \"SAMEORIGIN\"\n";
    } else {
        $base .= "\n# Optional hardening (uncomment if desired)\n";
        $base .= "# Header always set X-Frame-Options \"SAMEORIGIN\"\n";
    }
    $base .= "\n# HSTS (sent only over HTTPS; safe baseline)\n";
    $base .= "SetEnvIf X-Forwarded-Proto https HTTPS=on\n";
    $base .= "Header always set Strict-Transport-Security \"max-age=31536000\" env=HTTPS\n\n";
    $base .= "# Auto-upgrade any http:// subresources\n";
    $base .= "Header always set Content-Security-Policy \"upgrade-insecure-requests\"\n";
    $base .= "</IfModule>\n\n";
    $base .= "# Disable directory browsing\n";
    $base .= "Options -Indexes\n\n";
    $base .= "# Enforce HTTPS (considers proxies to avoid loops)\n";
    $base .= "<IfModule mod_rewrite.c>\n";
    $base .= "RewriteEngine On\n";
    $base .= "RewriteCond %{HTTPS} !=on\n";
    $base .= "RewriteCond %{HTTP:X-Forwarded-Proto} !https\n";
    $base .= "RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]\n\n";
    $base .= "# Block WordPress username enumeration\n";
    $base .= "RewriteCond %{QUERY_STRING} author=\\d\n";
    $base .= "RewriteRule ^(.*)$ /? [R=301,L]\n";
    $base .= "</IfModule>\n\n";
    $base .= "# Protect sensitive files (Apache 2.4 syntax)\n";
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
    $base .= "</FilesMatch>\n\n";
    $base .= "# WordPress-specific security (safe rules only)\n";
    $base .= "<FilesMatch \"\\.(log|sql|bak|backup|old|tmp|temp|swp|swo|~)$\">\n";
    $base .= "Require all denied\n";
    $base .= "</FilesMatch>\n";
    $base .= "<Files \"debug.log\">\n";
    $base .= "Require all denied\n";
    $base .= "</Files>\n";
    $base .= "<Files \"wp-config-sample.php\">\n";
    $base .= "Require all denied\n";
    $base .= "</Files>\n\n";
    $base .= "# Hide common VCS directories\n";
    $base .= "<IfModule mod_alias.c>\n";
    $base .= "RedirectMatch 404 /(\\.git|\\.svn|\\.hg)(/|$)\n";
    $base .= "</IfModule>\n";
    $base .= "# END CCM Optimise - DO NOT CHANGE!\n";
    return $base;
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
    // Check if hardening is enabled (not commented out)
    $has_hardening = strpos($current_content, 'Header always set X-Frame-Options "SAMEORIGIN"') !== false;

    $output = '<h3>' . __('Current .htaccess Status', 'ccm-tools') . '</h3>';
    
    if ($has_optimizations) {
        $output .= '<p class="ccm-success"><span class="ccm-icon">✓</span>' . __('CCM Optimizations are currently applied.', 'ccm-tools') . '</p>';
        $output .= '<button id="htremove" class="ccm-button">' . __('Remove Optimizations', 'ccm-tools') . '</button>';
        $output .= '<br><br><label><input type="checkbox" id="ht_hardening"' . ($has_hardening ? ' checked' : '') . '> ' . __('Enable Optional Hardening (X-Frame-Options: SAMEORIGIN)', 'ccm-tools') . '</label>';
        $output .= '<p class="description">' . __('Optional hardening adds X-Frame-Options header to prevent your site from being embedded in iframes, protecting against clickjacking attacks.', 'ccm-tools') . '</p>';
    } else {
        $output .= '<p class="ccm-info"><span class="ccm-icon">i</span>' . __('CCM Optimizations are not currently applied.', 'ccm-tools') . '</p>';
        $output .= '<button id="htadd" class="ccm-button">' . __('Add Optimizations', 'ccm-tools') . '</button>';
        $output .= '<br><br><label><input type="checkbox" id="ht_hardening"> ' . __('Enable Optional Hardening (X-Frame-Options: SAMEORIGIN)', 'ccm-tools') . '</label>';
        $output .= '<p class="description">' . __('Optional hardening adds X-Frame-Options header to prevent your site from being embedded in iframes, protecting against clickjacking attacks.', 'ccm-tools') . '</p>';
    }
    
    $output .= '<h3>' . __('Current .htaccess Content', 'ccm-tools') . '</h3>';
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
 * @param string $action 'add' or 'remove'
 * @return array Result with success status and message
 */
function ccm_tools_update_htaccess(string $action, $hardening = false): array {
    // Check user capabilities
    if (!current_user_can('manage_options')) {
        return array(
            'success' => false, 
            'message' => __('You do not have permission to perform this action.', 'ccm-tools')
        );
    }
    
    $htaccess_file = ABSPATH . '.htaccess';
    
    if (!file_exists($htaccess_file)) {
        if ($action === 'add') {
            // Create new .htaccess file with optimizations
            $new_content = ccm_tools_htaccess_content($hardening);
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
    
    $ccm_content = ccm_tools_htaccess_content($hardening);
    
    if ($action === 'add') {
        // Check if optimizations are already applied
        if (strpos($current_content, '# BEGIN CCM Optimise') !== false) {
            return array(
                'success' => false, 
                'message' => __('Optimizations are already applied.', 'ccm-tools')
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
