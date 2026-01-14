<?php
/**
 * Performance Optimizer
 * 
 * Eliminates render-blocking resources and improves Lighthouse scores.
 * Features: Defer JS, Delay JS, Async CSS, Preconnect hints, Remove query strings.
 * 
 * @package CCM_Tools
 * @since 7.4.0
 */

// Prevent direct file access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get performance optimizer settings
 * 
 * @return array Current settings
 */
function ccm_tools_perf_get_settings() {
    $defaults = array(
        'enabled' => false,
        'defer_js' => false,
        'defer_js_excludes' => array('jquery', 'jquery-core', 'jquery-migrate'),
        'delay_js' => false,
        'delay_js_timeout' => 0, // 0 = wait for interaction, otherwise milliseconds
        'delay_js_excludes' => array(),
        'preload_css' => false,
        'preconnect' => false,
        'preconnect_urls' => array(
            'https://fonts.googleapis.com',
            'https://fonts.gstatic.com',
        ),
        'dns_prefetch' => false,
        'dns_prefetch_urls' => array(),
        'remove_query_strings' => false,
        'disable_emoji' => false,
        'disable_dashicons' => false,
        'lazy_load_iframes' => false,
        'youtube_facade' => false,
        'lcp_fetchpriority' => false,
        'lcp_preload' => false,
        'lcp_preload_url' => '',
    );
    
    $settings = get_option('ccm_tools_perf_settings', array());
    return wp_parse_args($settings, $defaults);
}
/**
 * Save performance optimizer settings
 * 
 * @param array $settings Settings to save
 * @return bool Success
 */
function ccm_tools_perf_save_settings($settings) {
    return update_option('ccm_tools_perf_settings', $settings);
}

/**
 * Check if performance optimizer is enabled
 * 
 * @return bool
 */
function ccm_tools_perf_is_enabled() {
    $settings = ccm_tools_perf_get_settings();
    return !empty($settings['enabled']);
}

/**
 * Initialize performance optimizer hooks
 */
function ccm_tools_perf_init() {
    // Only run on frontend
    if (is_admin()) {
        return;
    }
    
    // Safety: Skip for logged-in administrators so they can always fix issues
    if (current_user_can('manage_options')) {
        return;
    }
    
    $settings = ccm_tools_perf_get_settings();
    
    if (empty($settings['enabled'])) {
        return;
    }
    
    // Defer JavaScript
    if (!empty($settings['defer_js'])) {
        add_filter('script_loader_tag', 'ccm_tools_perf_defer_js', 10, 3);
    }
    
    // Delay JavaScript
    if (!empty($settings['delay_js'])) {
        add_filter('script_loader_tag', 'ccm_tools_perf_delay_js', 20, 3);
        add_action('wp_footer', 'ccm_tools_perf_delay_js_script', 99);
    }
    
    // Preload CSS
    if (!empty($settings['preload_css'])) {
        add_filter('style_loader_tag', 'ccm_tools_perf_preload_css', 10, 4);
    }
    
    // Preconnect hints
    if (!empty($settings['preconnect'])) {
        add_action('wp_head', 'ccm_tools_perf_preconnect_hints', 1);
    }
    
    // DNS Prefetch
    if (!empty($settings['dns_prefetch'])) {
        add_action('wp_head', 'ccm_tools_perf_dns_prefetch', 1);
    }
    
    // Remove query strings from static resources
    if (!empty($settings['remove_query_strings'])) {
        add_filter('script_loader_src', 'ccm_tools_perf_remove_query_strings', 15);
        add_filter('style_loader_src', 'ccm_tools_perf_remove_query_strings', 15);
    }
    
    // Disable emoji scripts
    if (!empty($settings['disable_emoji'])) {
        ccm_tools_perf_disable_emojis();
    }
    
    // Disable dashicons for non-logged-in users
    if (!empty($settings['disable_dashicons'])) {
        add_action('wp_enqueue_scripts', 'ccm_tools_perf_disable_dashicons');
    }
    
    // Lazy load iframes
    if (!empty($settings['lazy_load_iframes'])) {
        add_filter('the_content', 'ccm_tools_perf_lazy_load_iframes', 99);
    }
    
    // YouTube facade (lite embeds)
    if (!empty($settings['youtube_facade'])) {
        add_filter('the_content', 'ccm_tools_perf_youtube_facade', 99);
        add_action('wp_footer', 'ccm_tools_perf_youtube_facade_script', 99);
    }
    
    // LCP fetchpriority optimization
    if (!empty($settings['lcp_fetchpriority'])) {
        add_filter('the_content', 'ccm_tools_perf_lcp_fetchpriority', 5);
        add_filter('post_thumbnail_html', 'ccm_tools_perf_lcp_fetchpriority_thumbnail', 5, 5);
    }
    
    // LCP preload
    if (!empty($settings['lcp_preload']) && !empty($settings['lcp_preload_url'])) {
        add_action('wp_head', 'ccm_tools_perf_lcp_preload', 1);
    }
}
add_action('init', 'ccm_tools_perf_init');

/**
 * Defer JavaScript files
 * Adds defer attribute to script tags
 * 
 * @param string $tag Script HTML tag
 * @param string $handle Script handle
 * @param string $src Script source URL
 * @return string Modified script tag
 */
function ccm_tools_perf_defer_js($tag, $handle, $src) {
    // Skip if already has defer or async
    if (strpos($tag, 'defer') !== false || strpos($tag, 'async') !== false) {
        return $tag;
    }
    
    // Skip inline scripts
    if (empty($src)) {
        return $tag;
    }
    
    $settings = ccm_tools_perf_get_settings();
    $excludes = isset($settings['defer_js_excludes']) ? (array) $settings['defer_js_excludes'] : array();
    
    // Check if handle is excluded
    foreach ($excludes as $exclude) {
        if (strpos($handle, $exclude) !== false) {
            return $tag;
        }
    }
    
    // Add defer attribute
    return str_replace(' src=', ' defer src=', $tag);
}

/**
 * Delay JavaScript execution until user interaction
 * Changes script type to prevent immediate execution
 * 
 * @param string $tag Script HTML tag
 * @param string $handle Script handle
 * @param string $src Script source URL
 * @return string Modified script tag
 */
function ccm_tools_perf_delay_js($tag, $handle, $src) {
    // Skip inline scripts
    if (empty($src)) {
        return $tag;
    }
    
    $settings = ccm_tools_perf_get_settings();
    $excludes = isset($settings['delay_js_excludes']) ? (array) $settings['delay_js_excludes'] : array();
    
    // Always exclude jQuery and critical scripts
    $always_exclude = array('jquery', 'jquery-core', 'jquery-migrate', 'wp-i18n', 'wp-hooks');
    $excludes = array_merge($excludes, $always_exclude);
    
    // Check if handle is excluded
    foreach ($excludes as $exclude) {
        if (strpos($handle, $exclude) !== false) {
            return $tag;
        }
    }
    
    // Skip if it's a module
    if (strpos($tag, 'type="module"') !== false) {
        return $tag;
    }
    
    // Change script type to delay loading
    $tag = str_replace('type="text/javascript"', 'type="ccmdelay/javascript"', $tag);
    
    // If no type attribute, add our delayed type
    if (strpos($tag, 'type=') === false) {
        $tag = str_replace('<script ', '<script type="ccmdelay/javascript" ', $tag);
    }
    
    // Store the original src for later execution
    $tag = str_replace(' src=', ' data-ccm-src=', $tag);
    
    return $tag;
}

/**
 * Output the delay JS execution script
 * Listens for user interaction then loads delayed scripts
 */
function ccm_tools_perf_delay_js_script() {
    $settings = ccm_tools_perf_get_settings();
    $timeout = isset($settings['delay_js_timeout']) ? intval($settings['delay_js_timeout']) : 0;
    ?>
    <script>
    (function() {
        var ccmDelayLoaded = false;
        var ccmDelayTimeout = <?php echo $timeout; ?>;
        
        function ccmLoadDelayedScripts() {
            if (ccmDelayLoaded) return;
            ccmDelayLoaded = true;
            
            var scripts = document.querySelectorAll('script[type="ccmdelay/javascript"]');
            
            scripts.forEach(function(oldScript, index) {
                var newScript = document.createElement('script');
                
                // Copy attributes
                Array.from(oldScript.attributes).forEach(function(attr) {
                    if (attr.name === 'type') {
                        newScript.type = 'text/javascript';
                    } else if (attr.name === 'data-ccm-src') {
                        newScript.src = attr.value;
                    } else {
                        newScript.setAttribute(attr.name, attr.value);
                    }
                });
                
                // Preserve inline content if any
                if (oldScript.innerHTML) {
                    newScript.innerHTML = oldScript.innerHTML;
                }
                
                // Add defer to prevent blocking
                newScript.defer = true;
                
                // Replace old script with new
                oldScript.parentNode.replaceChild(newScript, oldScript);
            });
            
            // Dispatch event for other scripts that may need to know
            document.dispatchEvent(new CustomEvent('ccm:delayedScriptsLoaded'));
        }
        
        // User interaction events
        var events = ['mousemove', 'touchstart', 'scroll', 'keydown', 'click'];
        
        events.forEach(function(event) {
            document.addEventListener(event, ccmLoadDelayedScripts, { once: true, passive: true });
        });
        
        // Fallback timeout if specified
        if (ccmDelayTimeout > 0) {
            setTimeout(ccmLoadDelayedScripts, ccmDelayTimeout);
        }
        
        // Also load after page fully loaded (fallback)
        window.addEventListener('load', function() {
            setTimeout(ccmLoadDelayedScripts, 5000);
        });
    })();
    </script>
    <?php
}

/**
 * Preload CSS files
 * Adds preload link before the stylesheet for parallel downloading
 * 
 * @param string $tag Stylesheet HTML tag
 * @param string $handle Stylesheet handle
 * @param string $href Stylesheet URL
 * @param string $media Media attribute
 * @return string Modified stylesheet tag with preload
 */
function ccm_tools_perf_preload_css($tag, $handle, $href, $media) {
    // Skip admin styles
    if (strpos($handle, 'admin') !== false) {
        return $tag;
    }
    
    // Skip if already has preload
    if (strpos($tag, 'rel="preload"') !== false) {
        return $tag;
    }
    
    // Add preload hint before the stylesheet
    $preload = sprintf(
        '<link rel="preload" href="%s" as="style">',
        esc_url($href)
    );
    
    return $preload . "\n" . $tag;
}

/**
 * Add preconnect hints for external resources
 */
function ccm_tools_perf_preconnect_hints() {
    $settings = ccm_tools_perf_get_settings();
    
    if (empty($settings['preconnect_urls'])) {
        return;
    }
    
    foreach ($settings['preconnect_urls'] as $url) {
        $url = esc_url(trim($url));
        if (!empty($url)) {
            echo '<link rel="preconnect" href="' . $url . '" crossorigin>' . "\n";
        }
    }
}

/**
 * Add DNS prefetch hints
 */
function ccm_tools_perf_dns_prefetch() {
    $settings = ccm_tools_perf_get_settings();
    
    if (empty($settings['dns_prefetch_urls'])) {
        return;
    }
    
    foreach ($settings['dns_prefetch_urls'] as $url) {
        $url = esc_url(trim($url));
        if (!empty($url)) {
            // Extract just the host
            $host = parse_url($url, PHP_URL_HOST);
            if (!empty($host)) {
                echo '<link rel="dns-prefetch" href="//' . esc_attr($host) . '">' . "\n";
            }
        }
    }
}

/**
 * Remove query strings from static resources
 * Helps with caching and reduces URL length
 * 
 * @param string $src Resource URL
 * @return string Modified URL
 */
function ccm_tools_perf_remove_query_strings($src) {
    if (strpos($src, '?ver=') !== false) {
        $src = remove_query_arg('ver', $src);
    }
    return $src;
}

/**
 * Disable WordPress emoji scripts and styles
 */
function ccm_tools_perf_disable_emojis() {
    remove_action('wp_head', 'print_emoji_detection_script', 7);
    remove_action('admin_print_scripts', 'print_emoji_detection_script');
    remove_action('wp_print_styles', 'print_emoji_styles');
    remove_action('admin_print_styles', 'print_emoji_styles');
    remove_filter('the_content_feed', 'wp_staticize_emoji');
    remove_filter('comment_text_rss', 'wp_staticize_emoji');
    remove_filter('wp_mail', 'wp_staticize_emoji_for_email');
    
    // Remove emoji DNS prefetch
    add_filter('emoji_svg_url', '__return_false');
    
    // Remove TinyMCE emoji
    add_filter('tiny_mce_plugins', function($plugins) {
        if (is_array($plugins)) {
            return array_diff($plugins, array('wpemoji'));
        }
        return $plugins;
    });
}

/**
 * Disable dashicons for non-logged-in users
 */
function ccm_tools_perf_disable_dashicons() {
    if (!is_user_logged_in()) {
        wp_dequeue_style('dashicons');
        wp_deregister_style('dashicons');
    }
}

/**
 * Add lazy loading to iframes
 * 
 * @param string $content Post content
 * @return string Modified content
 */
function ccm_tools_perf_lazy_load_iframes($content) {
    if (empty($content)) {
        return $content;
    }
    
    // Add loading="lazy" to iframes that don't have it
    $content = preg_replace_callback(
        '/<iframe([^>]*)>/i',
        function($matches) {
            $attributes = $matches[1];
            
            // Skip if already has loading attribute
            if (strpos($attributes, 'loading=') !== false) {
                return $matches[0];
            }
            
            return '<iframe loading="lazy"' . $attributes . '>';
        },
        $content
    );
    
    return $content;
}

/**
 * Replace YouTube embeds with lightweight facade
 * 
 * @param string $content Post content
 * @return string Modified content
 */
function ccm_tools_perf_youtube_facade($content) {
    if (empty($content)) {
        return $content;
    }
    
    // Match YouTube iframes
    $pattern = '/<iframe[^>]*src=["\'](?:https?:)?\/\/(?:www\.)?(?:youtube\.com\/embed\/|youtube-nocookie\.com\/embed\/)([a-zA-Z0-9_-]+)[^"\']*["\'][^>]*><\/iframe>/i';
    
    $content = preg_replace_callback($pattern, function($matches) {
        $video_id = $matches[1];
        $thumbnail = 'https://i.ytimg.com/vi/' . esc_attr($video_id) . '/hqdefault.jpg';
        
        // Return lightweight facade
        return sprintf(
            '<div class="ccm-yt-facade" data-video-id="%s" style="position:relative;padding-bottom:56.25%%;height:0;overflow:hidden;background:#000;cursor:pointer;">
                <img src="%s" alt="YouTube Video" style="position:absolute;top:0;left:0;width:100%%;height:100%%;object-fit:cover;" loading="lazy">
                <div style="position:absolute;top:50%%;left:50%%;transform:translate(-50%%,-50%%);width:68px;height:48px;background:red;border-radius:14px;display:flex;align-items:center;justify-content:center;">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="white"><path d="M8 5v14l11-7z"/></svg>
                </div>
            </div>',
            esc_attr($video_id),
            esc_url($thumbnail)
        );
    }, $content);
    
    return $content;
}

/**
 * Output YouTube facade click handler script
 */
function ccm_tools_perf_youtube_facade_script() {
    if (!is_singular()) {
        return;
    }
    ?>
    <script>
    document.addEventListener('click', function(e) {
        var facade = e.target.closest('.ccm-yt-facade');
        if (!facade) return;
        
        var videoId = facade.getAttribute('data-video-id');
        if (!videoId) return;
        
        var iframe = document.createElement('iframe');
        iframe.src = 'https://www.youtube-nocookie.com/embed/' + videoId + '?autoplay=1';
        iframe.width = '100%';
        iframe.height = '100%';
        iframe.style.position = 'absolute';
        iframe.style.top = '0';
        iframe.style.left = '0';
        iframe.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture';
        iframe.allowFullscreen = true;
        
        facade.innerHTML = '';
        facade.appendChild(iframe);
    });
    </script>
    <?php
}

/**
 * Add fetchpriority="high" to the first image in content
 * This helps browsers prioritize the LCP (Largest Contentful Paint) image
 * 
 * @param string $content Post content
 * @return string Modified content
 */
function ccm_tools_perf_lcp_fetchpriority($content) {
    // Safety checks
    if (!is_string($content) || empty($content)) {
        return $content;
    }
    
    // Only run once per page load
    global $ccm_lcp_priority_added;
    if (!empty($ccm_lcp_priority_added)) {
        return $content;
    }
    
    // Only on singular frontend pages
    if (is_admin() || !function_exists('is_singular') || !is_singular()) {
        return $content;
    }
    
    // Simple: find first img tag and add fetchpriority if not present
    if (strpos($content, '<img') !== false && strpos($content, 'fetchpriority') === false) {
        $content = preg_replace(
            '/<img(\s)/i',
            '<img fetchpriority="high"$1',
            $content,
            1  // Only replace first occurrence
        );
        $ccm_lcp_priority_added = true;
    }
    
    return $content;
}

/**
 * Add fetchpriority="high" to featured images
 *
 * @param string $html Post thumbnail HTML
 * @param int $post_id Post ID
 * @param int $thumbnail_id Thumbnail attachment ID
 * @param string|int[] $size Image size
 * @param string|array $attr Query string or array of attributes
 * @return string Modified HTML
 */
function ccm_tools_perf_lcp_fetchpriority_thumbnail($html, $post_id, $thumbnail_id, $size, $attr) {
    // Safety checks
    if (!is_string($html) || empty($html)) {
        return $html;
    }
    
    // Only run once per page load (use same global as content filter)
    global $ccm_lcp_priority_added;
    if (!empty($ccm_lcp_priority_added)) {
        return $html;
    }
    
    // Only on singular frontend pages
    if (is_admin() || !function_exists('is_singular') || !is_singular()) {
        return $html;
    }
    
    // Skip if already has fetchpriority
    if (strpos($html, 'fetchpriority') !== false) {
        $ccm_lcp_priority_added = true;
        return $html;
    }
    
    // Add fetchpriority="high" to first img tag
    $html = preg_replace(
        '/<img(\s)/i',
        '<img fetchpriority="high"$1',
        $html,
        1
    );
    $ccm_lcp_priority_added = true;
    
    return $html;
}

/**
 * Preload the LCP image if URL is specified
    // Track if we've already added fetchpriority via attributes
    static $attr_priority_added = false;
    if ($attr_priority_added) {
        return $attr;
    }
    
    // Skip if already has fetchpriority
    if (isset($attr['fetchpriority'])) {
        $attr_priority_added = true;
        return $attr;
    }
    
    // Add fetchpriority high to first image
    $attr['fetchpriority'] = 'high';
    
    // Remove lazy loading from LCP candidate
    if (isset($attr['loading']) && $attr['loading'] === 'lazy') {
        unset($attr['loading']);
    }
    
    $attr_priority_added = true;
    return $attr;
}

/**
 * Preload the LCP image if URL is specified
 */
function ccm_tools_perf_lcp_preload() {
    $settings = ccm_tools_perf_get_settings();
    
    if (empty($settings['lcp_preload_url'])) {
        return;
    }
    
    $url = esc_url($settings['lcp_preload_url']);
    
    // Determine image type for "as" attribute
    $type = '';
    $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
    
    $mime_types = array(
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'svg' => 'image/svg+xml',
    );
    
    if (isset($mime_types[$ext])) {
        $type = ' type="' . $mime_types[$ext] . '"';
    }
    
    echo '<link rel="preload" as="image" href="' . $url . '"' . $type . ' fetchpriority="high">' . "\n";
}

/**
 * Get list of registered scripts for the exclude list UI
 * 
 * @return array Script handles and info
 */
function ccm_tools_perf_get_registered_scripts() {
    global $wp_scripts;
    
    $scripts = array();
    
    if (isset($wp_scripts->registered)) {
        foreach ($wp_scripts->registered as $handle => $script) {
            $scripts[$handle] = array(
                'handle' => $handle,
                'src' => isset($script->src) ? $script->src : '',
                'deps' => isset($script->deps) ? $script->deps : array(),
            );
        }
    }
    
    return $scripts;
}

/**
 * Get list of registered styles for the exclude list UI
 * 
 * @return array Style handles and info
 */
function ccm_tools_perf_get_registered_styles() {
    global $wp_styles;
    
    $styles = array();
    
    if (isset($wp_styles->registered)) {
        foreach ($wp_styles->registered as $handle => $style) {
            $styles[$handle] = array(
                'handle' => $handle,
                'src' => isset($style->src) ? $style->src : '',
                'deps' => isset($style->deps) ? $style->deps : array(),
            );
        }
    }
    
    return $styles;
}

/**
 * Render the Performance Optimizer admin page
 */
function ccm_tools_render_perf_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.', 'ccm-tools'));
    }
    
    $settings = ccm_tools_perf_get_settings();
    ?>
    <div class="wrap ccm-tools ccm-tools-perf">
        <div class="ccm-header">
            <div class="ccm-header-logo">
                <a href="<?php echo esc_url(admin_url('admin.php?page=ccm-tools')); ?>">
                    <img src="<?php echo esc_url(CCM_HELPER_ROOT_URL); ?>img/logo.svg" alt="CCM Tools">
                </a>
            </div>
            <div class="ccm-header-title">
                <h1><?php _e('Performance Optimizer', 'ccm-tools'); ?></h1>
            </div>
            <nav class="ccm-tabs">
                <a href="<?php echo esc_url(admin_url('admin.php?page=ccm-tools')); ?>" class="ccm-tab"><?php _e('Dashboard', 'ccm-tools'); ?></a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=ccm-tools-optimizer')); ?>" class="ccm-tab"><?php _e('Database', 'ccm-tools'); ?></a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=ccm-tools-htaccess')); ?>" class="ccm-tab"><?php _e('.htaccess', 'ccm-tools'); ?></a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=ccm-tools-error-log')); ?>" class="ccm-tab"><?php _e('Error Log', 'ccm-tools'); ?></a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=ccm-tools-perf')); ?>" class="ccm-tab active"><?php _e('Performance', 'ccm-tools'); ?></a>
            </nav>
        </div>
        
        <div class="ccm-content">
            <!-- Master Enable Toggle -->
            <div class="ccm-card">
                <h2><?php _e('Performance Optimizer Status', 'ccm-tools'); ?></h2>
                <div class="ccm-setting-row" style="display: flex; align-items: center; justify-content: space-between; padding: var(--ccm-space-md) 0;">
                    <div>
                        <strong><?php _e('Enable Performance Optimizer', 'ccm-tools'); ?></strong>
                        <p class="ccm-text-muted"><?php _e('Master switch to enable/disable all performance optimizations', 'ccm-tools'); ?></p>
                    </div>
                    <label class="ccm-toggle">
                        <input type="checkbox" id="perf-master-enable" <?php checked($settings['enabled']); ?>>
                        <span class="ccm-toggle-slider"></span>
                    </label>
                </div>
                <div id="perf-status" class="<?php echo $settings['enabled'] ? 'ccm-success' : 'ccm-warning'; ?>">
                    <?php echo $settings['enabled'] ? __('Performance optimizations are ACTIVE', 'ccm-tools') : __('Performance optimizations are INACTIVE', 'ccm-tools'); ?>
                </div>
            </div>
            
            <!-- JavaScript Optimizations -->
            <div class="ccm-card" id="js-optimizations">
                <h2><?php _e('JavaScript Optimizations', 'ccm-tools'); ?></h2>
                <p class="ccm-text-muted"><?php _e('Reduce render-blocking JavaScript to improve First Contentful Paint (FCP) and Largest Contentful Paint (LCP).', 'ccm-tools'); ?></p>
                
                <!-- Defer JS -->
                <div class="ccm-setting-row" style="border-bottom: 1px solid var(--ccm-border); padding: var(--ccm-space-md) 0;">
                    <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: var(--ccm-space-md);">
                        <div style="flex: 1;">
                            <strong><?php _e('Defer JavaScript', 'ccm-tools'); ?></strong>
                            <p class="ccm-text-muted"><?php _e('Adds defer attribute to scripts, allowing the page to render while scripts load in the background. Scripts execute after HTML parsing.', 'ccm-tools'); ?></p>
                        </div>
                        <label class="ccm-toggle">
                            <input type="checkbox" id="perf-defer-js" <?php checked($settings['defer_js']); ?>>
                            <span class="ccm-toggle-slider"></span>
                        </label>
                    </div>
                    <div class="ccm-setting-detail" style="margin-top: var(--ccm-space-md); <?php echo $settings['defer_js'] ? '' : 'display:none;'; ?>">
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: var(--ccm-space-sm);">
                            <label><strong><?php _e('Exclude scripts containing:', 'ccm-tools'); ?></strong></label>
                            <button type="button" id="detect-scripts-btn" class="ccm-button ccm-button-small ccm-button-secondary">
                                <?php _e('🔍 Detect Scripts', 'ccm-tools'); ?>
                            </button>
                        </div>
                        <input type="text" id="perf-defer-js-excludes" class="ccm-input" value="<?php echo esc_attr(implode(', ', $settings['defer_js_excludes'])); ?>" placeholder="jquery, wp-">
                        <p class="ccm-text-muted" style="font-size: var(--ccm-text-sm);"><?php _e('Comma-separated patterns. Scripts with URLs containing these strings won\'t be deferred.', 'ccm-tools'); ?></p>
                        <div id="detected-scripts-result" style="display: none; margin-top: var(--ccm-space-md); padding: var(--ccm-space-md); background: var(--ccm-bg-secondary); border-radius: var(--ccm-radius);"></div>
                    </div>
                </div>
                
                <!-- Delay JS -->
                <div class="ccm-setting-row" style="border-bottom: 1px solid var(--ccm-border); padding: var(--ccm-space-md) 0;">
                    <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: var(--ccm-space-md);">
                        <div style="flex: 1;">
                            <strong><?php _e('Delay JavaScript Execution', 'ccm-tools'); ?></strong>
                            <p class="ccm-text-muted"><?php _e('Delays non-critical JavaScript until user interaction (scroll, click, touch). Dramatically improves initial page load but may delay interactive features.', 'ccm-tools'); ?></p>
                            <p class="ccm-text-muted" style="color: var(--ccm-warning);"><span class="ccm-icon">⚠</span> <?php _e('More aggressive than defer. May break some functionality.', 'ccm-tools'); ?></p>
                        </div>
                        <label class="ccm-toggle">
                            <input type="checkbox" id="perf-delay-js" <?php checked($settings['delay_js']); ?>>
                            <span class="ccm-toggle-slider"></span>
                        </label>
                    </div>
                    <div class="ccm-setting-detail" style="margin-top: var(--ccm-space-md); <?php echo $settings['delay_js'] ? '' : 'display:none;'; ?>">
                        <label><strong><?php _e('Fallback timeout (milliseconds):', 'ccm-tools'); ?></strong></label>
                        <input type="number" id="perf-delay-js-timeout" class="ccm-input" value="<?php echo esc_attr($settings['delay_js_timeout']); ?>" min="0" step="500" placeholder="0">
                        <p class="ccm-text-muted" style="font-size: var(--ccm-text-sm);"><?php _e('Set to 0 to wait for user interaction only, or enter milliseconds (e.g., 3000 for 3 seconds) for a timeout fallback.', 'ccm-tools'); ?></p>
                        
                        <label style="margin-top: var(--ccm-space-md);"><strong><?php _e('Exclude scripts (comma-separated handles):', 'ccm-tools'); ?></strong></label>
                        <input type="text" id="perf-delay-js-excludes" class="ccm-input" value="<?php echo esc_attr(implode(', ', $settings['delay_js_excludes'])); ?>" placeholder="critical-script, analytics">
                        <p class="ccm-text-muted" style="font-size: var(--ccm-text-sm);"><?php _e('jQuery and core WordPress scripts are always excluded automatically.', 'ccm-tools'); ?></p>
                    </div>
                </div>
            </div>
            
            <!-- CSS Optimizations -->
            <div class="ccm-card" id="css-optimizations">
                <h2><?php _e('CSS Optimizations', 'ccm-tools'); ?></h2>
                <p class="ccm-text-muted"><?php _e('Optimize CSS delivery to improve First Contentful Paint.', 'ccm-tools'); ?></p>
                
                <!-- Preload CSS -->
                <div class="ccm-setting-row" style="padding: var(--ccm-space-md) 0;">
                    <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: var(--ccm-space-md);">
                        <div style="flex: 1;">
                            <strong><?php _e('Preload CSS', 'ccm-tools'); ?></strong>
                            <p class="ccm-text-muted"><?php _e('Preloads stylesheets in parallel with HTML parsing. Browser starts downloading CSS earlier without blocking render.', 'ccm-tools'); ?></p>
                            <p class="ccm-text-muted" style="color: var(--ccm-success);"><span class="ccm-icon">✓</span> <?php _e('Safe optimization. No FOUC risk.', 'ccm-tools'); ?></p>
                        </div>
                        <label class="ccm-toggle">
                            <input type="checkbox" id="perf-preload-css" <?php checked(!empty($settings['preload_css'])); ?>>
                            <span class="ccm-toggle-slider"></span>
                        </label>
                    </div>
                </div>
            </div>
            
            <!-- Resource Hints -->
            <div class="ccm-card" id="resource-hints">
                <h2><?php _e('Resource Hints', 'ccm-tools'); ?></h2>
                <p class="ccm-text-muted"><?php _e('Help the browser prepare for resources it will need soon.', 'ccm-tools'); ?></p>
                
                <!-- Preconnect -->
                <div class="ccm-setting-row" style="border-bottom: 1px solid var(--ccm-border); padding: var(--ccm-space-md) 0;">
                    <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: var(--ccm-space-md);">
                        <div style="flex: 1;">
                            <strong><?php _e('Preconnect', 'ccm-tools'); ?></strong>
                            <p class="ccm-text-muted"><?php _e('Establishes early connections to important third-party origins. Saves time on DNS lookup, TCP handshake, and TLS negotiation.', 'ccm-tools'); ?></p>
                        </div>
                        <label class="ccm-toggle">
                            <input type="checkbox" id="perf-preconnect" <?php checked($settings['preconnect']); ?>>
                            <span class="ccm-toggle-slider"></span>
                        </label>
                    </div>
                    <div class="ccm-setting-detail" style="margin-top: var(--ccm-space-md); <?php echo $settings['preconnect'] ? '' : 'display:none;'; ?>">
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: var(--ccm-space-sm);">
                            <label><strong><?php _e('Preconnect URLs (one per line):', 'ccm-tools'); ?></strong></label>
                            <button type="button" id="detect-external-origins" class="ccm-button ccm-button-small ccm-button-secondary">
                                <?php _e('🔍 Detect Origins', 'ccm-tools'); ?>
                            </button>
                        </div>
                        <textarea id="perf-preconnect-urls" class="ccm-textarea" rows="4" placeholder="https://fonts.googleapis.com&#10;https://fonts.gstatic.com"><?php echo esc_textarea(implode("\n", $settings['preconnect_urls'])); ?></textarea>
                        <div id="detected-origins-result" style="display: none; margin-top: var(--ccm-space-md); padding: var(--ccm-space-md); background: var(--ccm-bg-secondary); border-radius: var(--ccm-radius);"></div>
                    </div>
                </div>
                
                <!-- DNS Prefetch -->
                <div class="ccm-setting-row" style="padding: var(--ccm-space-md) 0;">
                    <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: var(--ccm-space-md);">
                        <div style="flex: 1;">
                            <strong><?php _e('DNS Prefetch', 'ccm-tools'); ?></strong>
                            <p class="ccm-text-muted"><?php _e('Performs DNS lookups for external domains in advance. Lighter than preconnect, good for resources that might be needed.', 'ccm-tools'); ?></p>
                        </div>
                        <label class="ccm-toggle">
                            <input type="checkbox" id="perf-dns-prefetch" <?php checked($settings['dns_prefetch']); ?>>
                            <span class="ccm-toggle-slider"></span>
                        </label>
                    </div>
                    <div class="ccm-setting-detail" style="margin-top: var(--ccm-space-md); <?php echo $settings['dns_prefetch'] ? '' : 'display:none;'; ?>">
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: var(--ccm-space-sm);">
                            <label><strong><?php _e('DNS Prefetch URLs (one per line):', 'ccm-tools'); ?></strong></label>
                            <button type="button" id="detect-dns-prefetch-origins" class="ccm-button ccm-button-small ccm-button-secondary">
                                <?php _e('🔍 Detect Origins', 'ccm-tools'); ?>
                            </button>
                        </div>
                        <textarea id="perf-dns-prefetch-urls" class="ccm-textarea" rows="4" placeholder="https://example.com&#10;https://analytics.example.com"><?php echo esc_textarea(implode("\n", $settings['dns_prefetch_urls'])); ?></textarea>
                        <div id="detected-dns-origins-result" style="display: none; margin-top: var(--ccm-space-md); padding: var(--ccm-space-md); background: var(--ccm-bg-secondary); border-radius: var(--ccm-radius);"></div>
                    </div>
                </div>
            </div>
            
            <!-- LCP Optimization -->
            <div class="ccm-card" id="lcp-optimization">
                <h2><?php _e('LCP Optimization', 'ccm-tools'); ?></h2>
                <p class="ccm-text-muted"><?php _e('Largest Contentful Paint (LCP) measures how quickly the main content loads. Typically your hero image or banner.', 'ccm-tools'); ?></p>
                
                <!-- Fetchpriority High -->
                <div class="ccm-setting-row" style="border-bottom: 1px solid var(--ccm-border); padding: var(--ccm-space-md) 0;">
                    <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: var(--ccm-space-md);">
                        <div style="flex: 1;">
                            <strong><?php _e('Auto fetchpriority="high"', 'ccm-tools'); ?></strong>
                            <p class="ccm-text-muted"><?php _e('Automatically adds fetchpriority="high" to the first image on each page. Tells the browser to prioritize downloading the LCP image.', 'ccm-tools'); ?></p>
                            <p class="ccm-text-muted" style="color: var(--ccm-info);"><span class="ccm-icon">ℹ</span> <?php _e('Also removes loading="lazy" from the first image (lazy LCP = bad).', 'ccm-tools'); ?></p>
                        </div>
                        <label class="ccm-toggle">
                            <input type="checkbox" id="perf-lcp-fetchpriority" <?php checked($settings['lcp_fetchpriority']); ?>>
                            <span class="ccm-toggle-slider"></span>
                        </label>
                    </div>
                </div>
                
                <!-- LCP Preload -->
                <div class="ccm-setting-row" style="padding: var(--ccm-space-md) 0;">
                    <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: var(--ccm-space-md);">
                        <div style="flex: 1;">
                            <strong><?php _e('Preload LCP Image', 'ccm-tools'); ?></strong>
                            <p class="ccm-text-muted"><?php _e('Adds a preload hint for a specific LCP image URL. The browser starts downloading it immediately, before CSS/JS.', 'ccm-tools'); ?></p>
                            <p class="ccm-text-muted" style="color: var(--ccm-warning);"><span class="ccm-icon">⚠</span> <?php _e('Best for sites with the same hero image across all pages. Use Lighthouse to identify your LCP image URL.', 'ccm-tools'); ?></p>
                        </div>
                        <label class="ccm-toggle">
                            <input type="checkbox" id="perf-lcp-preload" <?php checked($settings['lcp_preload']); ?>>
                            <span class="ccm-toggle-slider"></span>
                        </label>
                    </div>
                    <div class="ccm-setting-detail" style="margin-top: var(--ccm-space-md); <?php echo $settings['lcp_preload'] ? '' : 'display:none;'; ?>">
                        <label><strong><?php _e('LCP Image URL:', 'ccm-tools'); ?></strong></label>
                        <input type="text" id="perf-lcp-preload-url" class="ccm-input" style="width: 100%; margin-top: var(--ccm-space-xs);" 
                               placeholder="https://example.com/wp-content/uploads/hero-image.webp" 
                               value="<?php echo esc_attr($settings['lcp_preload_url']); ?>">
                        <p class="ccm-text-muted" style="margin-top: var(--ccm-space-xs); font-size: 0.85em;">
                            <?php _e('💡 Tip: Run Lighthouse, expand "LCP request discovery", and copy the image URL shown there.', 'ccm-tools'); ?>
                        </p>
                    </div>
                </div>
            </div>
            
            <!-- Additional Optimizations -->
            <div class="ccm-card" id="additional-optimizations">
                <h2><?php _e('Additional Optimizations', 'ccm-tools'); ?></h2>
                
                <!-- Remove Query Strings -->
                <div class="ccm-setting-row" style="border-bottom: 1px solid var(--ccm-border); padding: var(--ccm-space-md) 0;">
                    <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: var(--ccm-space-md);">
                        <div style="flex: 1;">
                            <strong><?php _e('Remove Query Strings', 'ccm-tools'); ?></strong>
                            <p class="ccm-text-muted"><?php _e('Removes version query strings (?ver=x.x.x) from static resources. Can improve caching with some CDNs and proxies.', 'ccm-tools'); ?></p>
                        </div>
                        <label class="ccm-toggle">
                            <input type="checkbox" id="perf-remove-query-strings" <?php checked($settings['remove_query_strings']); ?>>
                            <span class="ccm-toggle-slider"></span>
                        </label>
                    </div>
                </div>
                
                <!-- Disable Emojis -->
                <div class="ccm-setting-row" style="border-bottom: 1px solid var(--ccm-border); padding: var(--ccm-space-md) 0;">
                    <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: var(--ccm-space-md);">
                        <div style="flex: 1;">
                            <strong><?php _e('Disable WordPress Emojis', 'ccm-tools'); ?></strong>
                            <p class="ccm-text-muted"><?php _e('Removes the emoji detection script and DNS prefetch. Saves ~10KB and 1 HTTP request. Native browser emojis will still work.', 'ccm-tools'); ?></p>
                        </div>
                        <label class="ccm-toggle">
                            <input type="checkbox" id="perf-disable-emoji" <?php checked($settings['disable_emoji']); ?>>
                            <span class="ccm-toggle-slider"></span>
                        </label>
                    </div>
                </div>
                
                <!-- Disable Dashicons -->
                <div class="ccm-setting-row" style="border-bottom: 1px solid var(--ccm-border); padding: var(--ccm-space-md) 0;">
                    <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: var(--ccm-space-md);">
                        <div style="flex: 1;">
                            <strong><?php _e('Disable Dashicons (Frontend)', 'ccm-tools'); ?></strong>
                            <p class="ccm-text-muted"><?php _e('Removes the Dashicons stylesheet for logged-out visitors. Saves ~35KB. Admin bar icons will still work for logged-in users.', 'ccm-tools'); ?></p>
                        </div>
                        <label class="ccm-toggle">
                            <input type="checkbox" id="perf-disable-dashicons" <?php checked($settings['disable_dashicons']); ?>>
                            <span class="ccm-toggle-slider"></span>
                        </label>
                    </div>
                </div>
                
                <!-- Lazy Load Iframes -->
                <div class="ccm-setting-row" style="border-bottom: 1px solid var(--ccm-border); padding: var(--ccm-space-md) 0;">
                    <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: var(--ccm-space-md);">
                        <div style="flex: 1;">
                            <strong><?php _e('Lazy Load Iframes', 'ccm-tools'); ?></strong>
                            <p class="ccm-text-muted"><?php _e('Adds native loading="lazy" attribute to iframes. Delays loading of offscreen iframes until needed.', 'ccm-tools'); ?></p>
                        </div>
                        <label class="ccm-toggle">
                            <input type="checkbox" id="perf-lazy-load-iframes" <?php checked($settings['lazy_load_iframes']); ?>>
                            <span class="ccm-toggle-slider"></span>
                        </label>
                    </div>
                </div>
                
                <!-- YouTube Facade -->
                <div class="ccm-setting-row" style="padding: var(--ccm-space-md) 0;">
                    <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: var(--ccm-space-md);">
                        <div style="flex: 1;">
                            <strong><?php _e('YouTube Lite Embeds', 'ccm-tools'); ?></strong>
                            <p class="ccm-text-muted"><?php _e('Replaces YouTube iframe embeds with a lightweight facade (thumbnail + play button). Actual video only loads on click. Saves significant bandwidth and improves LCP.', 'ccm-tools'); ?></p>
                        </div>
                        <label class="ccm-toggle">
                            <input type="checkbox" id="perf-youtube-facade" <?php checked($settings['youtube_facade']); ?>>
                            <span class="ccm-toggle-slider"></span>
                        </label>
                    </div>
                </div>
            </div>
            
            <!-- Save Button -->
            <div class="ccm-card">
                <div style="display: flex; gap: var(--ccm-space-md); align-items: center;">
                    <button type="button" id="save-perf-settings" class="ccm-button ccm-button-primary">
                        <?php _e('Save Settings', 'ccm-tools'); ?>
                    </button>
                    <span id="perf-save-status"></span>
                </div>
                <div id="perf-result" class="ccm-result-box" style="margin-top: var(--ccm-space-md);"></div>
            </div>
            
            <!-- Testing Tips -->
            <div class="ccm-card">
                <h2><?php _e('Testing & Debugging', 'ccm-tools'); ?></h2>
                <p><?php _e('After enabling optimizations, use these tools to verify everything works:', 'ccm-tools'); ?></p>
                <ul style="list-style: disc; margin-left: var(--ccm-space-xl);">
                    <li><a href="https://pagespeed.web.dev/" target="_blank" rel="noopener"><?php _e('Google PageSpeed Insights', 'ccm-tools'); ?></a> - <?php _e('Official Lighthouse testing', 'ccm-tools'); ?></li>
                    <li><a href="https://gtmetrix.com/" target="_blank" rel="noopener"><?php _e('GTmetrix', 'ccm-tools'); ?></a> - <?php _e('Detailed performance analysis', 'ccm-tools'); ?></li>
                    <li><a href="https://www.webpagetest.org/" target="_blank" rel="noopener"><?php _e('WebPageTest', 'ccm-tools'); ?></a> - <?php _e('Waterfall analysis and filmstrip', 'ccm-tools'); ?></li>
                </ul>
                <p style="margin-top: var(--ccm-space-md);"><strong><?php _e('Chrome DevTools tips:', 'ccm-tools'); ?></strong></p>
                <ul style="list-style: disc; margin-left: var(--ccm-space-xl);">
                    <li><?php _e('Network tab → Check script loading order and timing', 'ccm-tools'); ?></li>
                    <li><?php _e('Performance tab → Run Lighthouse directly in DevTools', 'ccm-tools'); ?></li>
                    <li><?php _e('Console tab → Watch for JavaScript errors', 'ccm-tools'); ?></li>
                    <li><?php _e('Coverage tab → Find unused CSS/JS', 'ccm-tools'); ?></li>
                </ul>
            </div>
        </div>
    </div>
    <?php
}
