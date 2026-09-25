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
            'label' => 'Safe options',
            'blurb' => 'No visible effect on the site. Fine to turn all of these on without testing afterwards.',
            'options' => array(
                'caching' => array(
                    'label' => 'Browser caching',
                    'description' => 'Sets a long cache lifetime for images, CSS, JS and fonts, so a returning visitor re-downloads less.',
                    'default' => true,
                ),
                'compression' => array(
                    'label' => 'Brotli + gzip compression',
                    'description' => 'Compresses HTML, CSS, JS, JSON, XML, SVG, fonts and WebAssembly with Brotli, falling back to gzip. Smaller responses, no visible change.',
                    'default' => true,
                ),
                'security_headers' => array(
                    'label' => 'Basic security headers',
                    'description' => 'Adds X-Content-Type-Options, Referrer-Policy and a conservative Permissions-Policy. Standard hardening; nothing for a visitor to see.',
                    'default' => true,
                ),
                'https_redirect' => array(
                    'label' => 'HTTPS redirect',
                    'description' => '301-redirects plain HTTP requests to HTTPS, including behind a proxy. Only enable once the site has a working SSL certificate, or every request will redirect loop.',
                    'default' => true,
                ),
                'file_protection' => array(
                    'label' => 'Sensitive file protection',
                    'description' => 'Blocks direct access to wp-config.php, .env, readme/licence files, and common log, backup and lockfile extensions.',
                    'default' => true,
                ),
                'disable_indexes' => array(
                    'label' => 'Disable directory browsing',
                    'description' => 'Stops a folder with no index file from listing its contents to anyone who browses to it directly.',
                    'default' => true,
                ),
                'etag_removal' => array(
                    'label' => 'Remove ETags',
                    'description' => 'Removes the ETag response header. Mainly useful behind a CDN or more than one web server, where ETags otherwise mismatch between servers and defeat caching.',
                    'default' => true,
                ),
            ),
        ),
        'moderate' => array(
            'label' => 'Moderate options',
            'blurb' => 'Mostly safe, but a few change how the site talks to browsers and other origins. Read each one before enabling it.',
            'options' => array(
                'x_frame_options' => array(
                    'label' => 'X-Frame-Options: SAMEORIGIN',
                    'description' => 'Stops other sites from embedding this one in an iframe, to prevent clickjacking. Breaks any legitimate embed of this site elsewhere, such as a partner page framing content from here.',
                    'default' => false,
                ),
                'x_xss_protection' => array(
                    'label' => 'X-XSS-Protection: 0',
                    'description' => 'Explicitly turns off the old browser XSS auditor, which is current best practice now that a Content-Security-Policy is the preferred defence. No effect in a browser that already removed the feature.',
                    'default' => false,
                ),
                'hsts_basic' => array(
                    'label' => 'HSTS (1 year)',
                    'description' => 'Sends Strict-Transport-Security so browsers refuse to load this site over plain HTTP. This is a one-year commitment: once a browser has seen the header, it will not fall back to HTTP for a full year, even if this option is switched off again before then. Only enable once HTTPS is solid across the whole site.',
                    'default' => false,
                ),
                'hsts_subdomains' => array(
                    'label' => 'HSTS with includeSubDomains',
                    'description' => 'Extends the HSTS header above to every subdomain. Only takes effect when HSTS above is also on, and it applies the same one-year lock-in to each subdomain — enable only once all of them actually serve HTTPS.',
                    'default' => false,
                ),
                'coop' => array(
                    'label' => 'Cross-Origin-Opener-Policy',
                    'description' => 'Isolates this site\'s browsing context with same-origin. Breaks OAuth logins and payment redirects that rely on window.opener talking back to a different origin.',
                    'default' => false,
                ),
                'corp' => array(
                    'label' => 'Cross-Origin-Resource-Policy',
                    'description' => 'Restricts this site\'s images, fonts and scripts to same-origin requests. Breaks any embed on another domain that pulls files straight from this one.',
                    'default' => false,
                ),
                'block_author_scan' => array(
                    'label' => 'Block author enumeration',
                    'description' => 'Redirects ?author=N requests to the homepage, so a scanner cannot walk numeric IDs to discover usernames. Comparatively low risk, which is why this one defaults on.',
                    'default' => true, // This one is relatively safe
                ),
            ),
        ),
        'high' => array(
            'label' => 'High-risk options',
            'blurb' => 'Blocks a specific, real piece of WordPress functionality. Only turn one on once you are sure nothing on this site depends on it.',
            'options' => array(
                'block_xmlrpc' => array(
                    'label' => 'Block XML-RPC',
                    'description' => 'Blocks xmlrpc.php outright. This breaks the WordPress mobile app and Jetpack, both of which depend on it, along with pingbacks and any older remote-publishing tool that talks to WordPress this way.',
                    'default' => false,
                ),
                'block_rest_api' => array(
                    'label' => 'Block REST API for logged-out users',
                    'description' => 'Blocks REST API requests (/wp-json/ and ?rest_route=) for anyone not logged in. This breaks the block editor, Contact Form 7, and most modern plugins and themes, which all call the REST API from the browser to function at all.',
                    'default' => false,
                ),
                'block_rss_feeds' => array(
                    'label' => 'Block RSS feeds',
                    'description' => 'Returns 410 Gone for every /feed/ URL. Breaks RSS/Atom readers, any service polling the feed (Zapier, IFTTT, email digests) and podcast subscriptions if this site publishes one. Do not enable on a site with subscribers.',
                    'default' => false,
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
    // Handle legacy $hardening parameter (backward compatibility)
    if (is_bool($options)) {
        $options = array('x_frame_options' => $options, 'block_author_scan' => true);
    }
    
    // Default options if none provided (backward compatibility)
    // Default all options if none provided (backward compatibility - all safe options enabled)
    if (empty($options) || !is_array($options)) {
        $options = array(
            // Safe options - default to true
            'caching' => true,
            'compression' => true,
            'security_headers' => true,
            'https_redirect' => true,
            'file_protection' => true,
            'disable_indexes' => true,
            'etag_removal' => true,
            // Moderate options
            'x_frame_options' => false,
            'x_xss_protection' => false,
            'hsts_basic' => false, // One-year HSTS commitment — opt-in, not a safe default
            'hsts_subdomains' => false,
            'coop' => false,
            'corp' => false,
            'block_author_scan' => true,
            // High risk options
            'block_xmlrpc' => false,
            'block_rest_api' => false,
            'block_rss_feeds' => false,
        );
    }
    
    $base = "";
    $base .= "# BEGIN CCM Optimise - DO NOT CHANGE!\n";
    $base .= "# CCM Tools .htaccess optimization (2026 baseline)\n\n";
    
    // ===== CACHING =====
    if (!empty($options['caching'])) {
        $base .= "# MIME Types (ensure modern formats are recognized)\n";
        $base .= "<IfModule mod_mime.c>\n";
        $base .= "AddType image/avif .avif\n";
        $base .= "AddType image/avif-sequence .avifs\n";
        $base .= "AddType image/webp .webp\n";
        $base .= "AddType image/heic .heic\n";
        $base .= "AddType image/heif .heif\n";
        $base .= "AddType font/woff2 .woff2\n";
        $base .= "AddType application/wasm .wasm\n";
        $base .= "</IfModule>\n\n";
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
        $base .= "ExpiresByType image/avif-sequence \"access plus 1 year\"\n";
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
        $base .= "# Video (1 year)\n";
        $base .= "ExpiresByType video/mp4 \"access plus 1 year\"\n";
        $base .= "ExpiresByType video/webm \"access plus 1 year\"\n";
        $base .= "ExpiresByType video/ogg \"access plus 1 year\"\n";
        $base .= "ExpiresByType application/xml \"access plus 1 hour\"\n";
        $base .= "ExpiresByType text/xml \"access plus 1 hour\"\n";
        $base .= "ExpiresByType application/rss+xml \"access plus 1 hour\"\n";
        $base .= "ExpiresByType application/atom+xml \"access plus 1 hour\"\n";
        $base .= "ExpiresByType text/html \"access plus 0 seconds\"\n";
        $base .= "ExpiresDefault \"access plus 1 hour\"\n";
        $base .= "</IfModule>\n";
        $base .= "# Cache-Control Headers\n";
        $base .= "<IfModule mod_headers.c>\n";
        $base .= "<FilesMatch \"\\.(ico|pdf|jpg|jpeg|png|webp|avif|avifs|heic|heif|gif|svg|woff2|woff|ttf|otf|wasm)$\">\n";
        $base .= "Header set Cache-Control \"public, max-age=31536000, immutable\"\n";
        $base .= "</FilesMatch>\n";
        $base .= "<FilesMatch \"\\.(css|js)$\">\n";
        $base .= "Header set Cache-Control \"public, max-age=2592000\"\n";
        $base .= "</FilesMatch>\n";
        $base .= "</IfModule>\n\n";
    }
    
    // ===== COMPRESSION =====
    if (!empty($options['compression'])) {
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
    }
    
    // ===== ETAG REMOVAL =====
    if (!empty($options['etag_removal'])) {
        $base .= "# Remove ETags (reduces server overhead)\n";
        $base .= "<IfModule mod_headers.c>\n";
        $base .= "Header unset ETag\n";
        $base .= "</IfModule>\n";
        $base .= "FileETag None\n\n";
    }
    
    // ===== SECURITY HEADERS =====
    if (!empty($options['security_headers']) || !empty($options['x_frame_options']) || !empty($options['x_xss_protection']) || !empty($options['coop']) || !empty($options['corp'])) {
        $base .= "# Security Headers\n";
        $base .= "<IfModule mod_headers.c>\n";
        
        if (!empty($options['security_headers'])) {
            $base .= "# Prevent MIME-type sniffing\n";
            $base .= "Header always set X-Content-Type-Options \"nosniff\"\n";
            $base .= "# Control referrer information\n";
            $base .= "Header always set Referrer-Policy \"strict-origin-when-cross-origin\"\n";
            $base .= "# Restrict browser features\n";
            $base .= "Header always set Permissions-Policy \"geolocation=(), microphone=(), camera=(), payment=(), usb=()\"\n";
        }
        
        if (!empty($options['x_frame_options'])) {
            $base .= "# Prevent clickjacking\n";
            $base .= "Header always set X-Frame-Options \"SAMEORIGIN\"\n";
        }
        
        if (!empty($options['x_xss_protection'])) {
            $base .= "# Disable legacy XSS filter (modern CSP is preferred)\n";
            $base .= "Header always set X-XSS-Protection \"0\"\n";
        }
        
        if (!empty($options['coop'])) {
            $base .= "# Isolate browsing context\n";
            $base .= "Header always set Cross-Origin-Opener-Policy \"same-origin\"\n";
        }
        
        if (!empty($options['corp'])) {
            $base .= "# Restrict resource loading\n";
            $base .= "Header always set Cross-Origin-Resource-Policy \"same-origin\"\n";
        }
        
        $base .= "</IfModule>\n\n";
    }
    
    // ===== HSTS =====
    if (!empty($options['hsts_basic'])) {
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
    }
    
    // ===== DIRECTORY SECURITY =====
    if (!empty($options['disable_indexes'])) {
        $base .= "# Disable directory browsing\n";
        $base .= "Options -Indexes\n\n";
    }
    
    // ===== HTTPS REDIRECT =====
    if (!empty($options['https_redirect']) || !empty($options['block_author_scan']) || !empty($options['block_rss_feeds'])) {
        $base .= "# Rewrite Rules\n";
        $base .= "<IfModule mod_rewrite.c>\n";
        $base .= "RewriteEngine On\n";
        
        if (!empty($options['https_redirect'])) {
            $base .= "# HTTPS Redirect (with proxy support)\n";
            $base .= "RewriteCond %{HTTPS} !=on\n";
            $base .= "RewriteCond %{HTTP:X-Forwarded-Proto} !https\n";
            $base .= "RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]\n";
        }
        
        if (!empty($options['block_author_scan'])) {
            $base .= "# Block username enumeration\n";
            $base .= "RewriteCond %{QUERY_STRING} author=\\d\n";
            $base .= "RewriteRule ^(.*)$ /? [R=301,L]\n";
        }
        
        if (!empty($options['block_rss_feeds'])) {
            $base .= "# Block RSS feeds (410 Gone)\n";
            $base .= "RewriteRule ^(.*/)?feed/?$ - [G,L]\n";
        }
        
        $base .= "</IfModule>\n\n";
    }
    
    // ===== FILE PROTECTION =====
    if (!empty($options['file_protection'])) {
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
        $base .= "# .htaccess backups/temp files written by CCM Tools itself\n";
        $base .= "<FilesMatch \"^\\.htaccess\\.ccm-backup-|^\\.ccm-htaccess-tmp-\">\n";
        $base .= "Require all denied\n";
        $base .= "</FilesMatch>\n";
        $base .= "<Files \"debug.log\">\n";
        $base .= "Require all denied\n";
        $base .= "</Files>\n";
        $base .= "<Files \"wp-config-sample.php\">\n";
        $base .= "Require all denied\n";
        $base .= "</Files>\n";
        $base .= "# Hide version control directories\n";
        $base .= "<IfModule mod_alias.c>\n";
        $base .= "RedirectMatch 404 /(\\.git|\\.svn|\\.hg)(/|$)\n";
        $base .= "</IfModule>\n\n";
    }
    
    // ===== HIGH RISK OPTIONS =====
    // Block XML-RPC
    if (!empty($options['block_xmlrpc'])) {
        $base .= "# Block XML-RPC (WARNING: Breaks Jetpack, WP mobile app, pingbacks)\n";
        $base .= "<Files xmlrpc.php>\n";
        $base .= "Require all denied\n";
        $base .= "</Files>\n\n";
    }
    
    // Block REST API for non-logged users
    if (!empty($options['block_rest_api'])) {
        $base .= "# Block REST API for non-authenticated users (WARNING: May break plugins)\n";
        $base .= "<IfModule mod_rewrite.c>\n";
        $base .= "RewriteEngine On\n";
        $base .= "RewriteCond %{REQUEST_URI} ^/wp-json/ [OR]\n";
        $base .= "RewriteCond %{REQUEST_URI} ^/\\?rest_route=\n";
        $base .= "RewriteCond %{HTTP_COOKIE} !wordpress_logged_in\n";
        $base .= "RewriteRule .* - [F,L]\n";
        $base .= "</IfModule>\n\n";
    }
    
    $base .= "# END CCM Optimise - DO NOT CHANGE!\n";
    return $base;
}

/**
 * Pull just the block this plugin wrote out of a full .htaccess file.
 *
 * Detection below is a set of substring tests, and it must only ever see
 * directives CCM itself wrote. Handed the whole file it reads other plugins'
 * work as its own: a cache plugin writing a `wordpress_logged_in` cookie
 * condition and a security plugin writing a `/wp-json/` rule are both
 * completely ordinary, and together they used to make the High-risk "Block
 * REST API for logged-out users" option report as Applied and pre-tick. One
 * click on Update then wrote CCM's own version of that rule and took the REST
 * API away from logged-out visitors, which breaks the block editor, Contact
 * Form 7 and most front-end plugin AJAX. `hsts_basic` pre-ticked the same way,
 * adopting a one-year browser commitment nobody chose.
 *
 * Returns '' when there is no complete, well-formed block. That is the safe
 * direction: nothing pre-ticks, so nothing is adopted by accident.
 *
 * @param string $content Full .htaccess content.
 * @return string The CCM block, both markers included, or '' if there is none.
 */
function ccm_tools_htaccess_extract_block(string $content): string {
    // Match on the marker stems, so a block written with or without the
    // "- DO NOT CHANGE!" tail is found either way. This is the same test
    // ccm_tools_display_htaccess() and ccm_tools_update_htaccess() use.
    $begin_marker = '# BEGIN CCM Optimise';
    $end_marker   = '# END CCM Optimise';

    $start = strpos($content, $begin_marker);
    if ($start === false) {
        return '';
    }

    $end = strpos($content, $end_marker, $start);
    if ($end === false) {
        // A BEGIN with no END is a damaged block. Reading on to the end of the
        // file here would sweep in every other plugin's rules, which is the
        // exact fault this function exists to prevent.
        return '';
    }

    return substr($content, $start, ($end - $start) + strlen($end_marker));
}

/**
 * Parse the CCM .htaccess block to detect which options are enabled.
 *
 * Pass the CCM block alone, not the whole file — see
 * ccm_tools_htaccess_extract_block() for why. An empty string is the correct
 * input when the file has no CCM block in it, and yields all-false.
 *
 * @param string $content The CCM block only.
 * @return array Detected options
 */
function ccm_tools_detect_htaccess_options(string $content): array {
    $options = array(
        // Safe options
        'caching' => strpos($content, 'mod_expires.c') !== false || strpos($content, 'Browser Caching') !== false,
        'compression' => strpos($content, 'BROTLI_COMPRESS') !== false || strpos($content, 'mod_deflate.c') !== false,
        'security_headers' => strpos($content, 'X-Content-Type-Options') !== false,
        'hsts_basic' => strpos($content, 'Strict-Transport-Security') !== false,
        'https_redirect' => strpos($content, 'RewriteRule ^ https://') !== false,
        'file_protection' => strpos($content, '<Files wp-config.php>') !== false,
        'disable_indexes' => strpos($content, 'Options -Indexes') !== false,
        'etag_removal' => strpos($content, 'FileETag None') !== false,
        // Moderate options
        'x_frame_options' => strpos($content, 'X-Frame-Options') !== false,
        'x_xss_protection' => strpos($content, 'X-XSS-Protection') !== false,
        'hsts_subdomains' => strpos($content, 'includeSubDomains') !== false,
        'coop' => strpos($content, 'Cross-Origin-Opener-Policy') !== false,
        'corp' => strpos($content, 'Cross-Origin-Resource-Policy') !== false,
        'block_author_scan' => strpos($content, 'author=\\d') !== false,
        // High risk options
        'block_xmlrpc' => strpos($content, '<Files xmlrpc.php>') !== false,
        'block_rest_api' => strpos($content, 'wp-json') !== false && strpos($content, 'wordpress_logged_in') !== false,
        'block_rss_feeds' => strpos($content, 'feed/?$ - [G,L]') !== false,
    );
    return $options;
}

/**
 * Whether a single .htaccess option should render as checked, given the
 * current file state. Safe and moderate options fall back to their
 * catalogue 'default' when the CCM block does not exist yet; high-risk
 * options never do — they are only ever checked because they are already
 * applied. This mirrors the pre-rebuild behaviour exactly.
 *
 * @param string $risk_key          'safe' | 'moderate' | 'high'
 * @param string $key               Option key.
 * @param array  $opt               Option definition (needs 'default').
 * @param bool   $has_optimizations Whether the CCM block currently exists.
 * @param array  $current_options   Options detected in the live file.
 * @return bool
 */
function ccm_tools_htaccess_option_checked(string $risk_key, string $key, array $opt, bool $has_optimizations, array $current_options): bool {
    $is_applied = $has_optimizations && !empty($current_options[$key]);

    if ($risk_key === 'high') {
        return $is_applied;
    }

    if ($is_applied) {
        return true;
    }

    return !$has_optimizations && !empty($opt['default']);
}

/**
 * Render one .htaccess option as a `.ccm-opt` row, matching the pattern the
 * Performance page uses. The checkbox id and name are the contract
 * js/main.js reads by: id="ht-<key>" and name="htaccess_options[]".
 *
 * @param string $risk_key 'safe' | 'moderate' | 'high'
 * @param string $key      Option key.
 * @param array  $opt      Option definition ('label', 'description').
 * @param bool   $checked  Whether the checkbox should render checked.
 * @return void
 */
function ccm_tools_htaccess_render_option(string $risk_key, string $key, array $opt, bool $checked, bool $applied = false, bool $has_block = false): void {
    $id = 'ht-' . $key;
    ?>
    <div class="ccm-opt<?php echo $checked ? ' is-on' : ''; ?>"
         data-risk="<?php echo esc_attr($risk_key); ?>"
         data-state="<?php echo $checked ? 'on' : 'off'; ?>"
         data-applied="<?php echo $applied ? '1' : '0'; ?>"
         data-has-block="<?php echo $has_block ? '1' : '0'; ?>">
        <div class="ccm-opt__main">
            <div class="ccm-opt__text">
                <span class="ccm-opt__label"><?php echo esc_html($opt['label']); ?></span>
                <?php if ($risk_key === 'safe') : ?>
                    <span class="ccm-chip ccm-chip--good"><?php _e('Safe', 'ccm-tools'); ?></span>
                <?php elseif ($risk_key === 'high') : ?>
                    <span class="ccm-chip ccm-chip--bad"><?php _e('Can break things', 'ccm-tools'); ?></span>
                <?php endif; ?>
                <?php if ($key === 'hsts_basic') : ?>
                    <span class="ccm-chip ccm-chip--bad"><?php _e('One-year commitment', 'ccm-tools'); ?></span>
                <?php endif; ?>
                <span class="ccm-chip" data-opt-status><?php
                    if (!$has_block) {
                        echo $checked ? esc_html__('Will be applied', 'ccm-tools') : esc_html__('Not applied', 'ccm-tools');
                    } elseif ($applied && $checked) {
                        echo esc_html__('Applied', 'ccm-tools');
                    } elseif ($applied) {
                        echo esc_html__('Will be removed', 'ccm-tools');
                    } elseif ($checked) {
                        echo esc_html__('Will be applied', 'ccm-tools');
                    } else {
                        echo esc_html__('Not applied', 'ccm-tools');
                    }
                ?></span>
                <p class="ccm-opt__desc"><?php echo esc_html($opt['description']); ?></p>
                <?php if ($key === 'hsts_basic') : ?>
                    <div class="ccm-alert ccm-alert--warn" style="margin-top: var(--ccm-space-sm);">
                        <span class="ccm-dot ccm-dot-warn"></span>
                        <div>
                            <?php _e('A browser remembers this for a full year from the moment it first sees the header. Switching this checkbox back off later does not undo that promise for anyone who already visited during the year — their browser keeps refusing plain HTTP regardless.', 'ccm-tools'); ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            <label class="ccm-toggle">
                <input type="checkbox" id="<?php echo esc_attr($id); ?>" name="htaccess_options[]"
                       value="<?php echo esc_attr($key); ?>" <?php checked($checked); ?>>
                <span class="ccm-toggle-slider"></span>
            </label>
        </div>
    </div>
    <?php
}

/**
 * Build the whole .htaccess Tools page body — everything that goes inside
 * `.ccm-content`. ccm.php prints this straight into the standard page shell
 * and, on every add/update/remove AJAX round trip, re-renders it wholesale
 * into #resultBox, so the hero, stats and option states always reflect
 * what is actually on disk after the write.
 *
 * @return string HTML output
 */
function ccm_tools_display_htaccess(): string {
    // Check user capabilities
    if (!current_user_can('manage_options')) {
        return '';
    }

    $htaccess_file = ABSPATH . '.htaccess';
    $file_exists   = file_exists($htaccess_file);
    $readable      = $file_exists && is_readable($htaccess_file);
    $read_failed   = false;
    $current_content = '';

    if ($readable) {
        $raw = file_get_contents($htaccess_file);
        if ($raw === false) {
            $read_failed = true;
        } else {
            $current_content = $raw;
        }
    }

    $has_optimizations = $current_content !== '' && strpos($current_content, '# BEGIN CCM Optimise') !== false;

    // Detect against the CCM block alone, never the whole file. Another
    // plugin's cache or security rules are not ours to read back as settings,
    // and doing so pre-ticked options nobody chose. No block means detect
    // against an empty string, which reports everything as not applied.
    $ccm_block         = ccm_tools_htaccess_extract_block($current_content);
    $current_options   = ccm_tools_detect_htaccess_options($ccm_block);
    $available_options = ccm_tools_get_htaccess_options();

    // Tally directives across all three groups.
    $total_options = 0;
    $applied_count = 0;
    foreach ($available_options as $group) {
        foreach ($group['options'] as $key => $opt) {
            $total_options++;
            if ($has_optimizations && !empty($current_options[$key])) {
                $applied_count++;
            }
        }
    }

    $writable = $file_exists ? is_writable($htaccess_file) : is_writable(dirname($htaccess_file));

    $server_software = isset($_SERVER['SERVER_SOFTWARE']) ? (string) $_SERVER['SERVER_SOFTWARE'] : '';
    $is_apache_compatible = (stripos($server_software, 'apache') !== false) || (stripos($server_software, 'litespeed') !== false);
    $server_label = $server_software !== '' ? $server_software : __('not reported by PHP', 'ccm-tools');

    // Most recent backup, if any. This is also what the restore control below
    // offers to put back, so both read the same file through the same helper.
    $latest_backup     = ccm_tools_htaccess_latest_backup();
    $latest_backup_at  = ccm_tools_htaccess_backup_time_label($latest_backup);
    $last_backup_label = $latest_backup_at !== '' ? $latest_backup_at : __('None yet', 'ccm-tools');

    // Mark the CCM block in the raw preview by wrapping the already-escaped
    // marker text — never insert unescaped file content into the page.
    $escaped_content = esc_html($current_content);
    $marked_content = $escaped_content;
    if ($has_optimizations) {
        $begin_marker = esc_html('# BEGIN CCM Optimise - DO NOT CHANGE!');
        $end_marker = esc_html('# END CCM Optimise - DO NOT CHANGE!');
        $start_pos = strpos($escaped_content, $begin_marker);
        $end_pos = strpos($escaped_content, $end_marker);
        if ($start_pos !== false && $end_pos !== false) {
            $end_pos += strlen($end_marker);
            $block = substr($escaped_content, $start_pos, $end_pos - $start_pos);
            $marked_content = substr($escaped_content, 0, $start_pos) . '<mark>' . $block . '</mark>' . substr($escaped_content, $end_pos);
        }
    }

    ob_start();
    ?>
    <div class="ccm-hero">
        <div class="ccm-hero__text">
            <h1><?php _e('.htaccess', 'ccm-tools'); ?></h1>
            <div class="ccm-hero__meta">
                <span><?php echo $has_optimizations
                    ? esc_html__('CCM block applied', 'ccm-tools')
                    : esc_html__('CCM block not applied', 'ccm-tools'); ?></span>
                <span><?php echo esc_html(sprintf(
                    /* translators: 1: directives currently live, 2: directives available */
                    __('%1$d of %2$d directives live', 'ccm-tools'), $applied_count, $total_options
                )); ?></span>
                <span><?php echo esc_html($server_label); ?></span>
            </div>
        </div>
        <div class="ccm-hero__actions">
            <?php if ($has_optimizations) : ?>
                <button type="button" id="htremove" class="ccm-button ccm-button-danger ccm-button-small">
                    <?php _e('Remove CCM block', 'ccm-tools'); ?>
                </button>
                <button type="button" id="htupdate" class="ccm-button ccm-button-primary">
                    <?php _e('Update .htaccess', 'ccm-tools'); ?>
                </button>
            <?php else : ?>
                <button type="button" id="htadd" class="ccm-button ccm-button-primary">
                    <?php _e('Apply to .htaccess', 'ccm-tools'); ?>
                </button>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$is_apache_compatible) : ?>
        <div class="ccm-alert ccm-alert--warn" style="margin-bottom: var(--ccm-space-lg);">
            <span class="ccm-dot ccm-dot-warn"></span>
            <div>
                <strong><?php echo esc_html(sprintf(
                    /* translators: %s: the server software string PHP reports */
                    __('This server reports "%s", not Apache or LiteSpeed.', 'ccm-tools'), $server_label
                )); ?></strong>
                <?php _e('Every directive on this page is Apache .htaccess syntax. On nginx, or anything else that does not read .htaccess, none of it takes effect no matter what is ticked below. "CCM block applied" above only means the marker comments were found in the file this page wrote — it has no way to tell whether the web server is actually reading them.', 'ccm-tools'); ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="ccm-stat-grid">
        <div class="ccm-stat-tile">
            <div class="ccm-stat-tile__value ccm-stat-tile__value--brand"><?php echo esc_html((string) $applied_count); ?></div>
            <div class="ccm-stat-tile__label"><?php _e('Directives applied', 'ccm-tools'); ?></div>
            <div class="ccm-stat-tile__sub"><?php _e('Detected in the live file now', 'ccm-tools'); ?></div>
        </div>
        <div class="ccm-stat-tile">
            <div class="ccm-stat-tile__value"><?php echo esc_html((string) $total_options); ?></div>
            <div class="ccm-stat-tile__label"><?php _e('Options available', 'ccm-tools'); ?></div>
            <div class="ccm-stat-tile__sub"><?php _e('Across the three risk groups below', 'ccm-tools'); ?></div>
        </div>
        <div class="ccm-stat-tile">
            <div class="ccm-stat-tile__value"><?php echo esc_html($last_backup_label); ?></div>
            <div class="ccm-stat-tile__label"><?php _e('Last backup', 'ccm-tools'); ?></div>
            <div class="ccm-stat-tile__sub"><?php _e('Taken automatically before every write, 5 kept', 'ccm-tools'); ?></div>
            <?php if ($latest_backup !== '') : ?>
                <div class="ccm-stat-tile__sub" style="flex-wrap: wrap; gap: var(--ccm-space-sm);">
                    <button type="button" id="htrestore" class="ccm-button ccm-button-secondary ccm-button-small">
                        <?php _e('Restore the last backup', 'ccm-tools'); ?>
                    </button>
                    <span style="font-size: var(--ccm-text-xs);"><?php echo esc_html($latest_backup_at !== ''
                        ? $latest_backup_at
                        : __('timestamp unreadable', 'ccm-tools')); ?></span>
                </div>
                <div class="ccm-stat-tile__sub" style="font-size: var(--ccm-text-xs);">
                    <?php _e('Restoring writes that file back over .htaccess, backing up the current one first. Note that removing the CCM block also removes the rule that stops Apache serving these backups, so do not leave them sitting in the site root without it.', 'ccm-tools'); ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="ccm-stat-tile">
            <div class="ccm-stat-tile__value"><?php echo $writable ? esc_html__('Yes', 'ccm-tools') : esc_html__('No', 'ccm-tools'); ?></div>
            <div class="ccm-stat-tile__label"><?php _e('File writable', 'ccm-tools'); ?></div>
            <div class="ccm-stat-tile__sub">
                <?php echo $file_exists
                    ? esc_html__('By the web server user', 'ccm-tools')
                    : esc_html__('No .htaccess yet — this checks the folder', 'ccm-tools'); ?>
            </div>
        </div>
    </div>

    <?php
    $preview_options = array();
    ?>
    <div id="htaccess-options">
        <?php foreach ($available_options as $risk_key => $group) :
            $items = $group['options'];
            $group_on = 0;
            foreach ($items as $key => $opt) {
                if (ccm_tools_htaccess_option_checked($risk_key, $key, $opt, $has_optimizations, $current_options)) {
                    $group_on++;
                }
            }
            ?>
            <section class="ccm-optgroup" data-group="<?php echo esc_attr($risk_key); ?>">
                <header class="ccm-optgroup__head">
                    <div>
                        <h2 class="ccm-optgroup__title"><?php echo esc_html($group['label']); ?></h2>
                        <p class="ccm-optgroup__note"><?php echo esc_html($group['blurb']); ?></p>
                    </div>
                    <span class="ccm-optgroup__count" data-group-count><?php echo esc_html(sprintf(
                        /* translators: 1: enabled, 2: total */
                        __('%1$d of %2$d on', 'ccm-tools'), $group_on, count($items)
                    )); ?></span>
                </header>
                <div class="ccm-optgroup__body">
                    <?php foreach ($items as $key => $opt) :
                        $checked = ccm_tools_htaccess_option_checked($risk_key, $key, $opt, $has_optimizations, $current_options);
                        $preview_options[$key] = $checked;
                        ccm_tools_htaccess_render_option($risk_key, $key, $opt, $checked, (bool) ($has_optimizations && !empty($current_options[$key])), $has_optimizations);
                    endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>
    </div>

    <?php
    $preview_content = ccm_tools_cleanup_htaccess_content(ccm_tools_htaccess_content($preview_options));
    ?>
    <details class="ccm-disclose">
        <summary><?php _e('Preview the directives that will be written', 'ccm-tools'); ?></summary>
        <div class="ccm-disclose__body">
            <p class="ccm-text-muted" style="font-size: var(--ccm-text-sm); margin: 0 0 var(--ccm-space-sm);">
                <?php _e('Exactly what the button above will write inside the CCM block, based on the options ticked above right now. Nothing outside the BEGIN/END markers is ever touched.', 'ccm-tools'); ?>
            </p>
            <pre class="ccm-mono"><?php echo esc_html($preview_content); ?></pre>
        </div>
    </details>

    <details class="ccm-disclose">
        <summary>
            <?php _e('What is in .htaccess right now', 'ccm-tools'); ?>
            <?php if ($has_optimizations) : ?>
                <span class="ccm-disclose__note"><?php _e('CCM block marked below', 'ccm-tools'); ?></span>
            <?php endif; ?>
        </summary>
        <div class="ccm-disclose__body">
            <?php if (!$file_exists) : ?>
                <div class="ccm-empty">
                    <p><?php _e('No .htaccess file exists yet at the site root. Clicking Apply above will create one.', 'ccm-tools'); ?></p>
                </div>
            <?php elseif (!$readable) : ?>
                <div class="ccm-alert ccm-alert--bad">
                    <span class="ccm-dot ccm-dot-bad"></span>
                    <div><?php _e('The file exists but PHP cannot read it. Fix the file permissions before using the buttons above — writing blind, without being able to read the current content first, is not attempted.', 'ccm-tools'); ?></div>
                </div>
            <?php elseif ($read_failed) : ?>
                <div class="ccm-alert ccm-alert--bad">
                    <span class="ccm-dot ccm-dot-bad"></span>
                    <div><?php _e('Reading the file failed unexpectedly. Reload the page and try again before using the buttons above.', 'ccm-tools'); ?></div>
                </div>
            <?php else : ?>
                <pre class="ccm-mono"><?php echo $marked_content; ?></pre>
            <?php endif; ?>
        </div>
    </details>

    <div class="ccm-savebar" data-ccm-savebar data-savebar-target="<?php echo $has_optimizations ? '#htupdate' : '#htadd'; ?>">
        <span class="ccm-savebar__dot" aria-hidden="true"></span>
        <span class="ccm-savebar__msg"><?php _e('No unsaved changes', 'ccm-tools'); ?></span>
        <button type="button" class="ccm-button ccm-button-secondary ccm-button-small" data-savebar-discard>
            <?php _e('Discard', 'ccm-tools'); ?>
        </button>
        <button type="button" class="ccm-button ccm-button-primary" data-savebar-save>
            <?php _e('Save settings', 'ccm-tools'); ?>
        </button>
    </div>
    <?php
    return (string) ob_get_clean();
}

/**
 * Clean up excessive blank lines in .htaccess content
 * 
 * @param string $content The .htaccess content to clean
 * @return string Cleaned content
 */
function ccm_tools_cleanup_htaccess_content(string $content): string {
    // Remove multiple consecutive blank lines and replace with single blank line
    $result = preg_replace('/\n\s*\n\s*\n+/', "\n\n", $content);
    if ($result !== null) $content = $result;
    
    // Remove blank lines at the beginning of the file
    $content = ltrim($content, "\n\r\t ");
    
    // Ensure exactly one blank line after "# END CCM Optimise - DO NOT CHANGE!" if there's content after it
    $result = preg_replace('/# END CCM Optimise - DO NOT CHANGE!\n+/', "# END CCM Optimise - DO NOT CHANGE!\n\n", $content);
    if ($result !== null) $content = $result;
    
    // Remove excessive blank lines at the end of CCM block when followed by other content
    $result = preg_replace('/# END CCM Optimise - DO NOT CHANGE!\n\n+(\S)/', "# END CCM Optimise - DO NOT CHANGE!\n\n$1", $content);
    if ($result !== null) $content = $result;
    
    // Ensure single trailing newline at end of file
    $content = rtrim($content) . "\n";
    
    return $content;
}

/**
 * Back up .htaccess to a timestamped copy before writing to it, and prune
 * old backups so only the 5 most recent are kept.
 *
 * The path is handed back so the caller can roll the write back to it, so a
 * backup that did not land completely reports as no backup at all — copying a
 * half-written file over .htaccess is the very outage this guards against.
 *
 * @param string $htaccess_file Absolute path to .htaccess
 * @return string Absolute path of the backup just taken, or '' if none was.
 */
function ccm_tools_backup_htaccess(string $htaccess_file): string {
    $current_content = @file_get_contents($htaccess_file);
    if ($current_content === false) {
        return ''; // Nothing readable to back up — don't block the write over it.
    }

    $dir = dirname($htaccess_file);

    // gmdate() on its own is only good to the second, so two writes inside the
    // same second wrote the same filename and the second destroyed the only
    // copy of the first. Microseconds separate them and keep the name sorting
    // in the order the backups were actually taken, which is what "the newest
    // backup" below relies on; the random tail then makes a collision
    // impossible even between two processes writing at the same instant.
    $now = microtime(true);
    $micros = sprintf('%06d', (int) floor(($now - floor($now)) * 1000000));
    $unique = substr(md5(uniqid((string) mt_rand(), true)), 0, 8);
    $backup_file = $dir . '/.htaccess.ccm-backup-' . gmdate('Ymd-His', (int) $now) . '-' . $micros . '-' . $unique;

    $written = @file_put_contents($backup_file, $current_content, LOCK_EX);
    if ($written === false || $written !== strlen($current_content)) {
        if (file_exists($backup_file)) {
            @unlink($backup_file);
        }
        $backup_file = '';
    }

    // Prune to the 5 most recent backups. The timestamp is fixed width and
    // leads the name, so a plain sort() still gives oldest-first and the
    // backup just taken is always last.
    $backups = glob($dir . '/.htaccess.ccm-backup-*');
    if (is_array($backups) && count($backups) > 5) {
        sort($backups);
        $to_remove = array_slice($backups, 0, count($backups) - 5);
        foreach ($to_remove as $old_backup) {
            if ($old_backup !== $backup_file) {
                @unlink($old_backup);
            }
        }
    }

    return $backup_file;
}

/**
 * Absolute path of the newest .htaccess backup this plugin has taken.
 *
 * Backup names are '.htaccess.ccm-backup-<Ymd-His>-<random>'. The timestamp is
 * fixed width and leads the name, so a plain sort() gives oldest-first and the
 * last element is the newest.
 *
 * @return string Absolute path, or '' when there is no backup.
 */
function ccm_tools_htaccess_latest_backup(): string {
    if (!defined('ABSPATH')) {
        return '';
    }

    $backups = glob(ABSPATH . '.htaccess.ccm-backup-*');
    if (!is_array($backups) || !$backups) {
        return '';
    }

    sort($backups);
    $newest = end($backups);

    return (is_string($newest) && is_file($newest)) ? $newest : '';
}

/**
 * Readable timestamp for one backup filename.
 *
 * Tolerates both the old name (timestamp only) and the current one (timestamp
 * plus microseconds plus a random tail), so backups taken before that change
 * still read.
 *
 * @param string $path Backup path.
 * @return string e.g. "25 Sep 2026, 03:14 UTC", or '' when it cannot be read.
 */
function ccm_tools_htaccess_backup_time_label(string $path): string {
    if ($path === '' || !preg_match('/\.ccm-backup-(\d{8}-\d{6})(?:-[0-9A-Za-z-]+)?$/', $path, $m)) {
        return '';
    }

    $taken_at = DateTime::createFromFormat('Ymd-His', $m[1], new DateTimeZone('UTC'));
    if ($taken_at === false) {
        return '';
    }

    return $taken_at->format('j M Y, H:i') . ' UTC';
}

/**
 * Restore one of this plugin's own .htaccess backups over the live file.
 *
 * This is what the "Restore the last backup" button is for: the tool takes a
 * backup before every write, and until now nothing could put one back, so a
 * write that took the site down could not be undone from a wp-admin that was
 * itself down.
 *
 * The path is validated hard before anything is copied over .htaccess. It has
 * to be a real, readable file sitting directly in the site root, carrying a
 * name this plugin writes. Anything else is refused. The copy goes through
 * ccm_tools_write_htaccess_safely(), so the current file is itself backed up
 * first, the write is atomic, and the site is checked afterwards.
 *
 * @param string $path Absolute path to the backup to restore.
 * @return array{success: bool, message: string}
 */
function ccm_tools_htaccess_restore_backup($path): array {
    if (!current_user_can('manage_options')) {
        return array(
            'success' => false,
            'message' => __('You do not have permission to perform this action.', 'ccm-tools')
        );
    }

    if (!is_string($path) || $path === '') {
        return array(
            'success' => false,
            'message' => __('No backup was named, so nothing was restored.', 'ccm-tools')
        );
    }

    // realpath() resolves any ../ and symlinks before the checks below, so the
    // directory comparison cannot be walked around.
    $real = realpath($path);
    if ($real === false || !is_file($real) || !is_readable($real)) {
        return array(
            'success' => false,
            'message' => __('That backup no longer exists, or cannot be read. Nothing was changed.', 'ccm-tools')
        );
    }

    $root = realpath(ABSPATH);
    if ($root === false) {
        return array(
            'success' => false,
            'message' => __('Could not resolve the site root, so the backup was not restored.', 'ccm-tools')
        );
    }

    if (dirname($real) !== $root || strpos(basename($real), '.htaccess.ccm-backup-') !== 0) {
        return array(
            'success' => false,
            'message' => __('That file is not one of the .htaccess backups this plugin takes, so it was not restored.', 'ccm-tools')
        );
    }

    $backup_content = @file_get_contents($real);
    if ($backup_content === false) {
        return array(
            'success' => false,
            'message' => __('Could not read the backup, so nothing was changed.', 'ccm-tools')
        );
    }

    if (trim($backup_content) === '') {
        return array(
            'success' => false,
            'message' => __('That backup is empty. Writing it would strip the whole file, so it was not restored.', 'ccm-tools')
        );
    }

    $htaccess_file    = ABSPATH . '.htaccess';
    $original_content = '';

    if (file_exists($htaccess_file)) {
        if (!is_writable($htaccess_file)) {
            return array(
                'success' => false,
                'message' => __('.htaccess file is not writable.', 'ccm-tools')
            );
        }

        $raw = @file_get_contents($htaccess_file);
        if ($raw === false) {
            return array(
                'success' => false,
                'message' => __('Failed to read .htaccess file.', 'ccm-tools')
            );
        }
        $original_content = $raw;
    }

    // Written back byte for byte. A restore is not the place to tidy anything.
    $write_result = ccm_tools_write_htaccess_safely($htaccess_file, $backup_content, $original_content);
    if (!$write_result['success']) {
        return $write_result;
    }

    $label = ccm_tools_htaccess_backup_time_label($real);

    return array(
        'success' => true,
        'message' => $label !== ''
            ? sprintf(
                /* translators: %s: when the restored backup was taken */
                __('.htaccess restored from the backup taken %s.', 'ccm-tools'),
                $label
            )
            : __('.htaccess restored from the last backup.', 'ccm-tools')
    );
}

/**
 * Safely persist new .htaccess content: refuse suspiciously destructive
 * results, back up the current file, and write atomically (temp file +
 * rename) so a crash or partial write can never leave .htaccess truncated.
 *
 * A single bad preg_replace() (e.g. a PCRE backtrack-limit failure on a
 * large, plugin-accreted .htaccess) used to be able to turn the whole file
 * into an empty string — an instant sitewide 500 (permalinks, other
 * plugins' rules, and the wp-config protection all gone in one write).
 * This is the last line of defence against that, independent of whichever
 * caller produced $new_content.
 *
 * @param string $htaccess_file    Absolute path to .htaccess
 * @param string $new_content      The content to write
 * @param string $original_content The content previously on disk, for the
 *                                  safety comparison ('' for a brand new file)
 * @return array{success: bool, message: string}
 */
function ccm_tools_write_htaccess_safely(string $htaccess_file, string $new_content, string $original_content = ''): array {
    $original_length = strlen($original_content);

    if ($original_length > 0) {
        if (trim($new_content) === '') {
            return array(
                'success' => false,
                'message' => __('Refusing to write .htaccess: the generated content was empty. No changes were made.', 'ccm-tools')
            );
        }

        // A well-formed add/update/remove of the CCM block should never
        // shrink the file by much more than the block itself, even with
        // every option enabled. If it does, treat it as a failed pattern
        // match rather than an intended edit and refuse to write it.
        $max_block_length = 8192;
        if ($original_length > $max_block_length) {
            $expected_minimum = $original_length - $max_block_length;
            if (strlen($new_content) < $expected_minimum * 0.5) {
                return array(
                    'success' => false,
                    'message' => __('Refusing to write .htaccess: the result is drastically shorter than the original file, which suggests a failed pattern match rather than an intended change. No changes were made.', 'ccm-tools')
                );
            }
        }
    }

    // Back up the current file before we touch it, and hold on to where that
    // backup landed — it is what the post-write check below rolls back to.
    $had_file    = file_exists($htaccess_file);
    $backup_file = '';
    if ($had_file) {
        $backup_file = ccm_tools_backup_htaccess($htaccess_file);
    }

    // Atomic write: write to a temp file in the same directory, verify the
    // byte count landed on disk matches what we intended, then rename()
    // over the real file. rename() within the same filesystem is atomic,
    // so a crash mid-write can never leave .htaccess half-written.
    $dir = dirname($htaccess_file);
    $tmp_file = $dir . '/.ccm-htaccess-tmp-' . uniqid('', true);

    $written = file_put_contents($tmp_file, $new_content, LOCK_EX);
    if ($written === false || $written !== strlen($new_content)) {
        if (file_exists($tmp_file)) {
            @unlink($tmp_file);
        }
        return array(
            'success' => false,
            'message' => __('Failed to write .htaccess: temp file write was incomplete. No changes were made to the live file.', 'ccm-tools')
        );
    }

    // Preserve the original file's permissions on the replacement.
    if (file_exists($htaccess_file)) {
        $perms = @fileperms($htaccess_file);
        if ($perms !== false) {
            @chmod($tmp_file, $perms & 0777);
        }
    }

    if (!@rename($tmp_file, $htaccess_file)) {
        @unlink($tmp_file);
        return array(
            'success' => false,
            'message' => __('Failed to write .htaccess: could not replace the live file.', 'ccm-tools')
        );
    }

    // The new file is live. Ask the site whether it still answers.
    //
    // Two directives this page can emit sit outside any <IfModule>:
    // `Options -Indexes` and `FileETag None`, both on by default. On a host
    // whose AllowOverride leaves out Options or FileInfo, Apache answers 500
    // to every request — the front end and /wp-admin/ alike — so the tool
    // that caused it can no longer be reached to undo it. One loopback
    // request catches that while there is still a page to report it on.
    if (function_exists('wp_remote_get') && function_exists('home_url')) {
        $response = wp_remote_get(home_url('/'), array(
            'timeout'     => 10,
            'sslverify'   => false,
            'redirection' => 0,
        ));

        // Only a definite 5xx rolls anything back. A WP_Error means the
        // loopback request itself never completed, which plenty of hosts
        // arrange deliberately, and says nothing at all about what a real
        // visitor would get. Treating that as a fault would roll back good
        // writes on every such host, so it is left strictly alone.
        $status = 0;
        $failed_to_connect = function_exists('is_wp_error') && is_wp_error($response);
        if (!$failed_to_connect && function_exists('wp_remote_retrieve_response_code')) {
            $status = (int) wp_remote_retrieve_response_code($response);
        }

        if ($status >= 500) {
            $rolled_back = false;

            if ($backup_file !== '' && is_readable($backup_file)) {
                $rolled_back = @copy($backup_file, $htaccess_file);
            } elseif (!$had_file) {
                // There was no .htaccess before this write, so there is no
                // backup to put back. Removing the file we just created
                // returns the site to exactly the state it was in.
                $rolled_back = @unlink($htaccess_file);
            }

            if ($rolled_back) {
                return array(
                    'success' => false,
                    'message' => sprintf(
                        /* translators: %d: the HTTP status the site returned */
                        __('The site returned an error (HTTP %d) immediately after the write, so the change was rolled back and the previous .htaccess is live again. The usual cause is a directive this host does not allow in .htaccess.', 'ccm-tools'),
                        $status
                    )
                );
            }

            return array(
                'success' => false,
                'message' => sprintf(
                    /* translators: 1: the HTTP status the site returned, 2: path to the backup, or a description of where to look */
                    __('The site returned an error (HTTP %1$d) immediately after the write and the rollback failed, so the new .htaccess is still live. Restore it by hand from %2$s.', 'ccm-tools'),
                    $status,
                    $backup_file !== '' ? $backup_file : __('the most recent .htaccess.ccm-backup- file in the site root', 'ccm-tools')
                )
            );
        }
    }

    return array('success' => true, 'message' => '');
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
            $write_result = ccm_tools_write_htaccess_safely($htaccess_file, $new_content, '');
            if (!$write_result['success']) {
                return $write_result;
            }
            return array(
                'success' => true,
                'message' => __('.htaccess file created with optimizations.', 'ccm-tools')
            );
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

        $write_result = ccm_tools_write_htaccess_safely($htaccess_file, $new_content, $current_content);
        if (!$write_result['success']) {
            return $write_result;
        }

        return array(
            'success' => true,
            'message' => __('Optimizations successfully added to .htaccess.', 'ccm-tools')
        );
    } else if ($action === 'update') {
        // Check if optimizations exist
        if (strpos($current_content, '# BEGIN CCM Optimise') === false) {
            // No existing optimizations, add them
            $new_content = $ccm_content . "\n" . $current_content;
        } else {
            // Replace existing optimizations
            $pattern = '/# BEGIN CCM Optimise - DO NOT CHANGE!.*?# END CCM Optimise - DO NOT CHANGE!/s';
            $new_content = preg_replace($pattern, trim($ccm_content), $current_content);
            if ($new_content === null) {
                return array(
                    'success' => false,
                    'message' => __('Failed to update .htaccess: the pattern replacement failed (the file may be too large or contain unusual content). No changes were made.', 'ccm-tools')
                );
            }
        }

        // Clean up excessive blank lines
        $new_content = ccm_tools_cleanup_htaccess_content($new_content);

        $write_result = ccm_tools_write_htaccess_safely($htaccess_file, $new_content, $current_content);
        if (!$write_result['success']) {
            return $write_result;
        }

        return array(
            'success' => true,
            'message' => __('Optimizations successfully updated.', 'ccm-tools')
        );
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
        if ($new_content === null) {
            return array(
                'success' => false,
                'message' => __('Failed to remove optimizations: the pattern replacement failed (the file may be too large or contain unusual content). No changes were made.', 'ccm-tools')
            );
        }

        // Clean up excessive blank lines after removal
        $new_content = ccm_tools_cleanup_htaccess_content($new_content);

        $write_result = ccm_tools_write_htaccess_safely($htaccess_file, $new_content, $current_content);
        if (!$write_result['success']) {
            return $write_result;
        }

        return array(
            'success' => true,
            'message' => __('Optimizations successfully removed from .htaccess.', 'ccm-tools')
        );
    }

    return array(
        'success' => false,
        'message' => __('Invalid action.', 'ccm-tools')
    );
}
