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
    static $cached = null;
    global $ccm_tools_perf_settings_dirty;
    
    if ($cached !== null && empty($ccm_tools_perf_settings_dirty)) {
        return $cached;
    }
    $ccm_tools_perf_settings_dirty = false;
    
    $defaults = array(
        'enabled' => false,
        'defer_js' => false,
        'defer_js_excludes' => array('jquery', 'jquery-core', 'jquery-migrate'),
        'delay_js' => false,
        'delay_js_timeout' => 0, // 0 = wait for interaction, otherwise milliseconds
        'delay_js_excludes' => array(),
        'preload_css' => false,
        'preload_css_excludes' => array(),
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
        // New optimizations for v7.9.0
        'font_display_swap' => false,
        'speculation_rules' => false,
        'speculation_eagerness' => 'moderate', // conservative, moderate, eager
        'critical_css' => false,
        'critical_css_code' => '',
        'disable_jquery_migrate' => false,
        'disable_block_css' => false,
        'disable_woocommerce_cart_fragments' => false,
        'reduce_heartbeat' => false,
        'heartbeat_interval' => 60,
        'disable_xmlrpc' => false,
        'disable_rsd_wlw' => false,
        'disable_shortlink' => false,
        'disable_rest_api_links' => false,
        'disable_oembed' => false,
    );
    
    $settings = get_option('ccm_tools_perf_settings', array());
    $cached = wp_parse_args($settings, $defaults);
    return $cached;
}
/**
 * Save performance optimizer settings
 * 
 * @param array $settings Settings to save
 * @return bool Success
 */
function ccm_tools_perf_save_settings($settings) {
    // Clear static cache so next get_settings() call returns fresh data
    ccm_tools_perf_clear_settings_cache();
    return update_option('ccm_tools_perf_settings', $settings);
}

/**
 * Clear the static settings cache
 * Called after saving settings to ensure fresh data
 */
function ccm_tools_perf_clear_settings_cache() {
    // We need to reset the static variable in ccm_tools_perf_get_settings()
    // Since PHP doesn't allow direct access to another function's static vars,
    // we use a global flag that get_settings checks
    global $ccm_tools_perf_settings_dirty;
    $ccm_tools_perf_settings_dirty = true;
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
    // Only run on frontend (except heartbeat which also helps in admin)
    if (is_admin()) {
        // In admin, only apply heartbeat reduction
        $settings = ccm_tools_perf_get_settings();
        if (!empty($settings['enabled']) && !empty($settings['reduce_heartbeat'])) {
            add_filter('heartbeat_settings', 'ccm_tools_perf_reduce_heartbeat');
        }
        return;
    }
    
    // Safety: Skip for logged-in administrators so they can always fix issues
    // Unless ?ccm_test_perf=1 is in the URL (allows admin testing)
    if (current_user_can('manage_options') && empty($_GET['ccm_test_perf'])) {
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
        add_filter('wp_get_attachment_image_attributes', 'ccm_tools_perf_lcp_fetchpriority_attributes', 5, 3);
    }
    
    // LCP preload
    if (!empty($settings['lcp_preload']) && !empty($settings['lcp_preload_url'])) {
        add_action('wp_head', 'ccm_tools_perf_lcp_preload', 1);
    }
    
    // Font display: swap
    if (!empty($settings['font_display_swap'])) {
        add_filter('style_loader_tag', 'ccm_tools_perf_font_display_swap', 10, 4);
        add_action('wp_head', 'ccm_tools_perf_font_display_preload', 2);
        // Use output buffering to inject font-display: swap into @font-face rules (self-hosted fonts)
        add_action('template_redirect', 'ccm_tools_perf_font_display_start_buffer', 1);
    }
    
    // Speculation Rules API (instant page navigation)
    if (!empty($settings['speculation_rules'])) {
        add_action('wp_footer', 'ccm_tools_perf_speculation_rules', 99);
    }
    
    // Critical CSS
    if (!empty($settings['critical_css']) && !empty($settings['critical_css_code'])) {
        add_action('wp_head', 'ccm_tools_perf_inline_critical_css', 1);
    }
    
    // Disable jQuery Migrate
    if (!empty($settings['disable_jquery_migrate'])) {
        add_action('wp_default_scripts', 'ccm_tools_perf_disable_jquery_migrate');
    }
    
    // Disable WordPress Block Library CSS
    if (!empty($settings['disable_block_css'])) {
        add_action('wp_enqueue_scripts', 'ccm_tools_perf_disable_block_css', 100);
    }
    
    // Disable WooCommerce cart fragments
    if (!empty($settings['disable_woocommerce_cart_fragments'])) {
        add_action('wp_enqueue_scripts', 'ccm_tools_perf_disable_cart_fragments', 99);
    }
    
    // Reduce Heartbeat API frequency
    if (!empty($settings['reduce_heartbeat'])) {
        add_filter('heartbeat_settings', 'ccm_tools_perf_reduce_heartbeat');
    }
    
    // Disable XML-RPC
    if (!empty($settings['disable_xmlrpc'])) {
        add_filter('xmlrpc_enabled', '__return_false');
        add_filter('wp_headers', 'ccm_tools_perf_remove_x_pingback');
    }
    
    // Disable RSD and WLW Manifest links
    if (!empty($settings['disable_rsd_wlw'])) {
        remove_action('wp_head', 'rsd_link');
        remove_action('wp_head', 'wlwmanifest_link');
    }
    
    // Disable shortlink
    if (!empty($settings['disable_shortlink'])) {
        remove_action('wp_head', 'wp_shortlink_wp_head');
        remove_action('template_redirect', 'wp_shortlink_header', 11);
    }
    
    // Disable REST API link in head
    if (!empty($settings['disable_rest_api_links'])) {
        remove_action('wp_head', 'rest_output_link_wp_head', 10);
        remove_action('template_redirect', 'rest_output_link_header', 11);
    }
    
    // Disable oEmbed discovery
    if (!empty($settings['disable_oembed'])) {
        remove_action('wp_head', 'wp_oembed_add_discovery_links');
        remove_action('wp_head', 'wp_oembed_add_host_js');
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
 * Make CSS non-render-blocking using the print media trick
 * Changes media="all" to media="print" with onload handler to swap back
 * This eliminates render-blocking CSS while still loading stylesheets
 * 
 * @param string $tag Stylesheet HTML tag
 * @param string $handle Stylesheet handle
 * @param string $href Stylesheet URL
 * @param string $media Media attribute
 * @return string Modified stylesheet tag
 */
function ccm_tools_perf_preload_css($tag, $handle, $href, $media) {
    // Skip admin styles
    if (strpos($handle, 'admin') !== false) {
        return $tag;
    }
    
    // Skip if media is anything other than 'all' (the default)
    // Themes/plugins that already set media to 'print' or a media query are already optimized
    if (!empty($media) && $media !== 'all') {
        return $tag;
    }
    
    // Skip if already has preload or is already non-blocking
    // Check both single and double quote variants (WordPress uses single quotes, our tags use double)
    if (strpos($tag, 'rel="preload"') !== false || strpos($tag, "rel='preload'") !== false ||
        strpos($tag, 'media="print"') !== false || strpos($tag, "media='print'") !== false ||
        strpos($tag, 'onload=') !== false) {
        return $tag;
    }
    
    // Skip if this is an inline style (no href)
    if (empty($href)) {
        return $tag;
    }
    
    $settings = ccm_tools_perf_get_settings();
    $excludes = isset($settings['preload_css_excludes']) ? (array) $settings['preload_css_excludes'] : array();
    
    // Check if handle is excluded
    foreach ($excludes as $exclude) {
        $exclude = trim($exclude);
        if (!empty($exclude) && strpos($handle, $exclude) !== false) {
            return $tag;
        }
    }
    
    // Use the print media trick:
    // 1. Set media="print" so browser downloads but doesn't block render
    // 2. onload switches media to "all" so styles apply once loaded
    // 3. noscript fallback for users without JavaScript
    $async_tag = sprintf(
        '<link rel="stylesheet" id="%s-css" href="%s" media="print" onload="this.media=\'all\'">' . "\n" .
        '<noscript><link rel="stylesheet" href="%s"></noscript>',
        esc_attr($handle),
        esc_url($href),
        esc_url($href)
    );
    
    return $async_tag . "\n";
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
    if (strpos($src, 'ver=') !== false) {
        $src = remove_query_arg('ver', $src);
    }
    return $src;
}

/**
 * Disable WordPress emoji scripts and styles
 */
function ccm_tools_perf_disable_emojis() {
    remove_action('wp_head', 'print_emoji_detection_script', 7);
    remove_action('wp_print_styles', 'print_emoji_styles');
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
 * Add fetchpriority="high" to images rendered via wp_get_attachment_image()
 * This catches images in page builders and theme templates that bypass the_content
 *
 * @param array $attr Image attributes array
 * @param WP_Post $attachment Attachment post object
 * @param string|int[] $size Image size
 * @return array Modified attributes
 */
function ccm_tools_perf_lcp_fetchpriority_attributes($attr, $attachment, $size) {
    // Only run once per page load (use same global as content/thumbnail filters)
    global $ccm_lcp_priority_added;
    if (!empty($ccm_lcp_priority_added)) {
        return $attr;
    }

    // Only on singular frontend pages
    if (is_admin() || !function_exists('is_singular') || !is_singular()) {
        return $attr;
    }

    // Skip if already has fetchpriority
    if (isset($attr['fetchpriority'])) {
        $ccm_lcp_priority_added = true;
        return $attr;
    }

    // Add fetchpriority high to first image
    $attr['fetchpriority'] = 'high';

    // Remove lazy loading from LCP candidate
    if (isset($attr['loading']) && $attr['loading'] === 'lazy') {
        unset($attr['loading']);
    }

    $ccm_lcp_priority_added = true;
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
 * Add font-display: swap to Google Fonts and other font stylesheets
 * Fixes "Ensure text remains visible during webfont load" warning
 * 
 * @param string $tag Stylesheet HTML tag
 * @param string $handle Stylesheet handle
 * @param string $href Stylesheet URL
 * @param string $media Media attribute
 * @return string Modified stylesheet tag
 */
function ccm_tools_perf_font_display_swap($tag, $handle, $href, $media) {
    // Only modify Google Fonts URLs
    if (strpos($href, 'fonts.googleapis.com') !== false) {
        // Add display=swap parameter if not already present
        if (strpos($href, 'display=') === false) {
            $href_with_swap = add_query_arg('display', 'swap', $href);
            $tag = str_replace($href, $href_with_swap, $tag);
        }
    }
    
    return $tag;
}

/**
 * Add preload hints for Google Fonts
 */
function ccm_tools_perf_font_display_preload() {
    // Add preconnect for fonts.gstatic.com (actual font files)
    echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
}

/**
 * Start output buffering to inject font-display: swap into @font-face rules
 * This catches self-hosted fonts in theme CSS that don't have font-display set
 */
function ccm_tools_perf_font_display_start_buffer() {
    // Don't buffer AJAX requests, admin, or REST API
    if (wp_doing_ajax() || is_admin() || (defined('REST_REQUEST') && REST_REQUEST)) {
        return;
    }
    
    ob_start('ccm_tools_perf_font_display_process_buffer');
}

/**
 * Process output buffer to inject font-display: swap into @font-face rules
 * 
 * @param string $html The complete HTML output
 * @return string Modified HTML with font-display: swap injected
 */
function ccm_tools_perf_font_display_process_buffer($html) {
    // Only process HTML responses
    if (empty($html)) {
        return $html;
    }
    
    // Find all @font-face rules and inject font-display: swap if not present
    // This regex matches @font-face rules that don't already have font-display
    $html = preg_replace_callback(
        '/@font-face\s*\{([^}]+)\}/is',
        function($matches) {
            $rule = $matches[0];
            $content = $matches[1];
            
            // Check if font-display is already set (not commented out)
            // Look for font-display that's not in a comment
            if (preg_match('/(?<!\/\*\s*)font-display\s*:/i', $content)) {
                return $rule; // Already has font-display, don't modify
            }
            
            // Inject font-display: swap before the closing brace
            // Add it after the last property
            $content = rtrim($content);
            
            // Check if content ends with semicolon
            if (substr($content, -1) !== ';') {
                $content .= ';';
            }
            
            $content .= "\n            font-display: swap;";
            
            return '@font-face {' . $content . "\n        }";
        },
        $html
    );
    
    return $html;
}

/**
 * Output Speculation Rules for instant page navigation
 * Uses the Speculation Rules API for prerendering pages on hover
 * @see https://developer.chrome.com/docs/web-platform/prerender-pages
 */
function ccm_tools_perf_speculation_rules() {
    $settings = ccm_tools_perf_get_settings();
    $eagerness = isset($settings['speculation_eagerness']) ? $settings['speculation_eagerness'] : 'moderate';
    
    // Validate eagerness value
    $valid_eagerness = array('conservative', 'moderate', 'eager');
    if (!in_array($eagerness, $valid_eagerness)) {
        $eagerness = 'moderate';
    }
    
    // Build speculation rules
    $rules = array(
        'prerender' => array(
            array(
                'source' => 'document',
                'where' => array(
                    'and' => array(
                        // Only same-origin links
                        array('href_matches' => '/*'),
                        // Exclude common non-navigational patterns
                        array('not' => array('href_matches' => '/*\\?*')), // URLs with query strings
                        array('not' => array('href_matches' => '/*#*')), // Anchor links  
                        array('not' => array('href_matches' => '/wp-admin/*')),
                        array('not' => array('href_matches' => '/wp-login.php')),
                        array('not' => array('href_matches' => '/cart/*')),
                        array('not' => array('href_matches' => '/checkout/*')),
                        array('not' => array('href_matches' => '/my-account/*')),
                        array('not' => array('selector_matches' => '[target="_blank"]')),
                        array('not' => array('selector_matches' => '[download]')),
                        array('not' => array('selector_matches' => '.no-prerender')),
                    ),
                ),
                'eagerness' => $eagerness,
            ),
        ),
    );
    
    // Output the speculation rules
    echo '<script type="speculationrules">' . "\n";
    echo wp_json_encode($rules, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    echo "\n</script>\n";
}

/**
 * Inline critical CSS in the head
 */
function ccm_tools_perf_inline_critical_css() {
    $settings = ccm_tools_perf_get_settings();
    
    if (empty($settings['critical_css_code'])) {
        return;
    }
    
    // Sanitize and output the critical CSS
    $css = wp_strip_all_tags($settings['critical_css_code']);
    
    echo '<style id="ccm-critical-css">' . $css . '</style>' . "\n";
}

/**
 * Disable jQuery Migrate
 * jQuery Migrate is often unnecessary for modern themes/plugins
 */
function ccm_tools_perf_disable_jquery_migrate($scripts) {
    if (!is_admin() && isset($scripts->registered['jquery'])) {
        $jquery = $scripts->registered['jquery'];
        
        if ($jquery->deps) {
            $jquery->deps = array_diff($jquery->deps, array('jquery-migrate'));
        }
    }
}

/**
 * Disable WordPress Block Library CSS (Gutenberg)
 * Useful if not using the block editor on frontend
 */
function ccm_tools_perf_disable_block_css() {
    wp_dequeue_style('wp-block-library');
    wp_dequeue_style('wp-block-library-theme');
    wp_dequeue_style('wc-blocks-style'); // WooCommerce Blocks
    wp_dequeue_style('global-styles'); // Global styles
}

/**
 * Disable WooCommerce cart fragments AJAX
 * Cart fragments can slow down pages significantly
 */
function ccm_tools_perf_disable_cart_fragments() {
    if (class_exists('WooCommerce')) {
        // Only disable on non-cart/checkout pages
        if (function_exists('is_cart') && !is_cart() && !is_checkout()) {
            wp_dequeue_script('wc-cart-fragments');
        }
    }
}

/**
 * Reduce Heartbeat API frequency
 * 
 * @param array $settings Heartbeat settings
 * @return array Modified settings
 */
function ccm_tools_perf_reduce_heartbeat($settings) {
    $perf_settings = ccm_tools_perf_get_settings();
    $interval = isset($perf_settings['heartbeat_interval']) ? intval($perf_settings['heartbeat_interval']) : 60;
    
    // Ensure minimum of 15 seconds, maximum of 120 seconds
    $interval = max(15, min(120, $interval));
    
    $settings['interval'] = $interval;
    return $settings;
}

/**
 * Remove X-Pingback header
 * 
 * @param array $headers HTTP headers
 * @return array Modified headers
 */
function ccm_tools_perf_remove_x_pingback($headers) {
    unset($headers['X-Pingback']);
    return $headers;
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
        <?php 
        if (function_exists('ccm_tools_render_header_nav')) {
            ccm_tools_render_header_nav('ccm-tools-perf');
        }
        ?>
        
        <div class="ccm-content">
            <?php
            // AI Optimizer section (one-click AI flow) — renders at top of page
            if (function_exists('ccm_tools_render_ai_section')) {
                ccm_tools_render_ai_section();
            }
            ?>

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
                <?php if ($settings['enabled']) : ?>
                <div style="margin-top: var(--ccm-space-md); padding: var(--ccm-space-sm) var(--ccm-space-md); background: var(--ccm-bg-secondary); border-radius: var(--ccm-radius); border-left: 3px solid var(--ccm-info);">
                    <p class="ccm-text-muted" style="margin: 0; font-size: var(--ccm-text-sm);">
                        <strong><?php _e('Testing Tip:', 'ccm-tools'); ?></strong>
                        <?php _e('Optimizations are bypassed for logged-in administrators for safety. To test as admin, add', 'ccm-tools'); ?>
                        <code>?ccm_test_perf=1</code>
                        <?php _e('to any frontend URL.', 'ccm-tools'); ?>
                    </p>
                </div>
                <?php endif; ?>
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
                        
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-top: var(--ccm-space-md); margin-bottom: var(--ccm-space-sm);">
                            <label><strong><?php _e('Exclude scripts containing:', 'ccm-tools'); ?></strong></label>
                            <button type="button" id="detect-delay-scripts-btn" class="ccm-button ccm-button-small ccm-button-secondary">
                                <?php _e('🔍 Detect Scripts', 'ccm-tools'); ?>
                            </button>
                        </div>
                        <input type="text" id="perf-delay-js-excludes" class="ccm-input" value="<?php echo esc_attr(implode(', ', $settings['delay_js_excludes'])); ?>" placeholder="critical-script, analytics">
                        <p class="ccm-text-muted" style="font-size: var(--ccm-text-sm);"><?php _e('Comma-separated patterns. jQuery and WordPress core are always excluded automatically.', 'ccm-tools'); ?></p>
                        <div id="detected-delay-scripts-result" style="display: none; margin-top: var(--ccm-space-md); padding: var(--ccm-space-md); background: var(--ccm-bg-secondary); border-radius: var(--ccm-radius);"></div>
                    </div>
                </div>
            </div>
            
            <!-- CSS Optimizations -->
            <div class="ccm-card" id="css-optimizations">
                <h2><?php _e('CSS Optimizations', 'ccm-tools'); ?></h2>
                <p class="ccm-text-muted"><?php _e('Optimize CSS delivery to improve First Contentful Paint.', 'ccm-tools'); ?></p>
                
                <!-- Async CSS Loading -->
                <div class="ccm-setting-row" style="border-bottom: 1px solid var(--ccm-border); padding: var(--ccm-space-md) 0;">
                    <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: var(--ccm-space-md);">
                        <div style="flex: 1;">
                            <strong><?php _e('Async CSS Loading', 'ccm-tools'); ?></strong>
                            <p class="ccm-text-muted"><?php _e('Makes stylesheets non-render-blocking using the print media technique. Browser downloads CSS without blocking page render, then applies styles once loaded.', 'ccm-tools'); ?></p>
                            <p class="ccm-text-muted" style="color: var(--ccm-warning);"><span class="ccm-icon">⚠</span> <?php _e('May cause FOUC (Flash of Unstyled Content). Best paired with Critical CSS below to avoid visible style flash.', 'ccm-tools'); ?></p>
                        </div>
                        <label class="ccm-toggle">
                            <input type="checkbox" id="perf-preload-css" <?php checked(!empty($settings['preload_css'])); ?>>
                            <span class="ccm-toggle-slider"></span>
                        </label>
                    </div>
                    <div class="ccm-setting-detail" style="margin-top: var(--ccm-space-sm); <?php echo !empty($settings['preload_css']) ? '' : 'display:none;'; ?>">
                        <label><strong><?php _e('Exclude from Async:', 'ccm-tools'); ?></strong></label>
                        <input type="text" id="perf-preload-css-excludes" class="ccm-input" 
                               value="<?php echo esc_attr(implode(', ', isset($settings['preload_css_excludes']) ? (array) $settings['preload_css_excludes'] : array())); ?>"
                               placeholder="e.g. theme-style, elementor-frontend">
                        <p class="ccm-text-muted" style="font-size: var(--ccm-text-sm);"><?php _e('Comma-separated list of stylesheet handles to keep render-blocking. Use Inspect Element to find handle names.', 'ccm-tools'); ?></p>
                    </div>
                </div>
                
                <!-- Critical CSS -->
                <div class="ccm-setting-row" style="border-bottom: 1px solid var(--ccm-border); padding: var(--ccm-space-md) 0;">
                    <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: var(--ccm-space-md);">
                        <div style="flex: 1;">
                            <strong><?php _e('Inline Critical CSS', 'ccm-tools'); ?></strong>
                            <p class="ccm-text-muted"><?php _e('Inlines critical above-the-fold CSS directly in the HTML head. Eliminates render-blocking for initial content.', 'ccm-tools'); ?></p>
                            <p class="ccm-text-muted" style="color: var(--ccm-info);"><span class="ccm-icon">ℹ</span> <?php _e('Use tools like critical.js or Penthouse to generate critical CSS.', 'ccm-tools'); ?></p>
                        </div>
                        <label class="ccm-toggle">
                            <input type="checkbox" id="perf-critical-css" <?php checked(!empty($settings['critical_css'])); ?>>
                            <span class="ccm-toggle-slider"></span>
                        </label>
                    </div>
                    <div class="ccm-setting-detail" style="margin-top: var(--ccm-space-md); <?php echo !empty($settings['critical_css']) ? '' : 'display:none;'; ?>">
                        <label><strong><?php _e('Critical CSS Code:', 'ccm-tools'); ?></strong></label>
                        <textarea id="perf-critical-css-code" class="ccm-textarea" rows="8" placeholder="/* Paste your critical CSS here */
body { margin: 0; }
.header { ... }
.hero { ... }"><?php echo esc_textarea(isset($settings['critical_css_code']) ? $settings['critical_css_code'] : ''); ?></textarea>
                        <p class="ccm-text-muted" style="font-size: var(--ccm-text-sm);"><?php _e('CSS that renders above-the-fold content. Keep it minimal (<14KB ideally).', 'ccm-tools'); ?></p>
                    </div>
                </div>
                
                <!-- Disable Block Library CSS -->
                <div class="ccm-setting-row" style="padding: var(--ccm-space-md) 0;">
                    <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: var(--ccm-space-md);">
                        <div style="flex: 1;">
                            <strong><?php _e('Disable Block Library CSS', 'ccm-tools'); ?></strong>
                            <p class="ccm-text-muted"><?php _e('Removes WordPress Gutenberg block styles if you\'re not using the block editor. Saves ~36KB.', 'ccm-tools'); ?></p>
                            <p class="ccm-text-muted" style="color: var(--ccm-warning);"><span class="ccm-icon">⚠</span> <?php _e('Only enable if your theme doesn\'t use Gutenberg blocks.', 'ccm-tools'); ?></p>
                        </div>
                        <label class="ccm-toggle">
                            <input type="checkbox" id="perf-disable-block-css" <?php checked(!empty($settings['disable_block_css'])); ?>>
                            <span class="ccm-toggle-slider"></span>
                        </label>
                    </div>
                </div>
            </div>
            
            <!-- Font Optimization -->
            <div class="ccm-card" id="font-optimization">
                <h2><?php _e('Font Optimization', 'ccm-tools'); ?></h2>
                <p class="ccm-text-muted"><?php _e('Optimize web font loading to reduce render-blocking and improve text visibility.', 'ccm-tools'); ?></p>
                
                <!-- Font Display Swap -->
                <div class="ccm-setting-row" style="padding: var(--ccm-space-md) 0;">
                    <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: var(--ccm-space-md);">
                        <div style="flex: 1;">
                            <strong><?php _e('Font Display: Swap', 'ccm-tools'); ?></strong>
                            <p class="ccm-text-muted"><?php _e('Adds font-display: swap to all fonts including Google Fonts, self-hosted fonts, and icon fonts (FontAwesome, etc.). Shows fallback text immediately while custom fonts load. Fixes "Ensure text remains visible during webfont load" warning.', 'ccm-tools'); ?></p>
                            <p class="ccm-text-muted" style="color: var(--ccm-success);"><span class="ccm-icon">✓</span> <?php _e('Safe optimization. Est. savings of 150-500ms+', 'ccm-tools'); ?></p>
                        </div>
                        <label class="ccm-toggle">
                            <input type="checkbox" id="perf-font-display-swap" <?php checked(!empty($settings['font_display_swap'])); ?>>
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
                
                <!-- Disable jQuery Migrate -->
                <div class="ccm-setting-row" style="border-top: 1px solid var(--ccm-border); padding: var(--ccm-space-md) 0;">
                    <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: var(--ccm-space-md);">
                        <div style="flex: 1;">
                            <strong><?php _e('Disable jQuery Migrate', 'ccm-tools'); ?></strong>
                            <p class="ccm-text-muted"><?php _e('Removes jQuery Migrate script (~10KB). Only needed for legacy plugins using deprecated jQuery functions.', 'ccm-tools'); ?></p>
                            <p class="ccm-text-muted" style="color: var(--ccm-warning);"><span class="ccm-icon">⚠</span> <?php _e('May break older plugins. Test thoroughly.', 'ccm-tools'); ?></p>
                        </div>
                        <label class="ccm-toggle">
                            <input type="checkbox" id="perf-disable-jquery-migrate" <?php checked(!empty($settings['disable_jquery_migrate'])); ?>>
                            <span class="ccm-toggle-slider"></span>
                        </label>
                    </div>
                </div>
                
                <!-- Disable WooCommerce Cart Fragments -->
                <?php if (class_exists('WooCommerce')) : ?>
                <div class="ccm-setting-row" style="border-top: 1px solid var(--ccm-border); padding: var(--ccm-space-md) 0;">
                    <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: var(--ccm-space-md);">
                        <div style="flex: 1;">
                            <strong><?php _e('Disable WooCommerce Cart Fragments', 'ccm-tools'); ?></strong>
                            <p class="ccm-text-muted"><?php _e('Disables the AJAX cart fragments script on non-cart pages. Can reduce page load time significantly on WooCommerce sites.', 'ccm-tools'); ?></p>
                            <p class="ccm-text-muted" style="color: var(--ccm-info);"><span class="ccm-icon">ℹ</span> <?php _e('Cart/Checkout pages are not affected.', 'ccm-tools'); ?></p>
                        </div>
                        <label class="ccm-toggle">
                            <input type="checkbox" id="perf-disable-woocommerce-cart-fragments" <?php checked(!empty($settings['disable_woocommerce_cart_fragments'])); ?>>
                            <span class="ccm-toggle-slider"></span>
                        </label>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Instant Page Navigation (Speculation Rules) -->
            <div class="ccm-card" id="speculation-rules">
                <h2><?php _e('Instant Page Navigation', 'ccm-tools'); ?></h2>
                <p class="ccm-text-muted"><?php _e('Uses the modern Speculation Rules API to prerender pages, making navigation feel instant.', 'ccm-tools'); ?></p>
                
                <div class="ccm-setting-row" style="padding: var(--ccm-space-md) 0;">
                    <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: var(--ccm-space-md);">
                        <div style="flex: 1;">
                            <strong><?php _e('Enable Speculation Rules', 'ccm-tools'); ?></strong>
                            <p class="ccm-text-muted"><?php _e('Prerenders same-origin links in the background. When users click a link, the page loads instantly. Supported in Chrome 109+, Edge 109+.', 'ccm-tools'); ?></p>
                            <p class="ccm-text-muted" style="color: var(--ccm-success);"><span class="ccm-icon">✓</span> <?php _e('Safe - browsers without support simply ignore it.', 'ccm-tools'); ?></p>
                        </div>
                        <label class="ccm-toggle">
                            <input type="checkbox" id="perf-speculation-rules" <?php checked(!empty($settings['speculation_rules'])); ?>>
                            <span class="ccm-toggle-slider"></span>
                        </label>
                    </div>
                    <div class="ccm-setting-detail" style="margin-top: var(--ccm-space-md); <?php echo !empty($settings['speculation_rules']) ? '' : 'display:none;'; ?>">
                        <label><strong><?php _e('Eagerness Level:', 'ccm-tools'); ?></strong></label>
                        <select id="perf-speculation-eagerness" class="ccm-select" style="margin-top: var(--ccm-space-xs);">
                            <option value="conservative" <?php selected($settings['speculation_eagerness'], 'conservative'); ?>><?php _e('Conservative - Only on strong intent (e.g., pointer down)', 'ccm-tools'); ?></option>
                            <option value="moderate" <?php selected($settings['speculation_eagerness'], 'moderate'); ?>><?php _e('Moderate - On hover for 200ms (Recommended)', 'ccm-tools'); ?></option>
                            <option value="eager" <?php selected($settings['speculation_eagerness'], 'eager'); ?>><?php _e('Eager - Immediately on link visibility', 'ccm-tools'); ?></option>
                        </select>
                        <p class="ccm-text-muted" style="font-size: var(--ccm-text-sm); margin-top: var(--ccm-space-xs);"><?php _e('Higher eagerness = faster navigation but more bandwidth. Moderate is a good balance.', 'ccm-tools'); ?></p>
                    </div>
                </div>
            </div>
            
            <!-- Head Cleanup & Bloat Removal -->
            <div class="ccm-card" id="head-cleanup">
                <h2><?php _e('Head Cleanup & Bloat Removal', 'ccm-tools'); ?></h2>
                <p class="ccm-text-muted"><?php _e('Remove unnecessary elements from wp_head that add to page weight without providing value.', 'ccm-tools'); ?></p>
                
                <!-- Reduce Heartbeat -->
                <div class="ccm-setting-row" style="border-bottom: 1px solid var(--ccm-border); padding: var(--ccm-space-md) 0;">
                    <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: var(--ccm-space-md);">
                        <div style="flex: 1;">
                            <strong><?php _e('Reduce Heartbeat Frequency', 'ccm-tools'); ?></strong>
                            <p class="ccm-text-muted"><?php _e('WordPress Heartbeat API sends AJAX requests every 15-60 seconds. Reducing frequency saves server resources.', 'ccm-tools'); ?></p>
                        </div>
                        <label class="ccm-toggle">
                            <input type="checkbox" id="perf-reduce-heartbeat" <?php checked(!empty($settings['reduce_heartbeat'])); ?>>
                            <span class="ccm-toggle-slider"></span>
                        </label>
                    </div>
                    <div class="ccm-setting-detail" style="margin-top: var(--ccm-space-md); <?php echo !empty($settings['reduce_heartbeat']) ? '' : 'display:none;'; ?>">
                        <label><strong><?php _e('Heartbeat Interval (seconds):', 'ccm-tools'); ?></strong></label>
                        <input type="number" id="perf-heartbeat-interval" class="ccm-input" style="width: 100px;" value="<?php echo esc_attr($settings['heartbeat_interval']); ?>" min="15" max="120" step="5">
                        <p class="ccm-text-muted" style="font-size: var(--ccm-text-sm);"><?php _e('Default is 15-60s. Recommended: 60s for frontend, affects auto-save frequency.', 'ccm-tools'); ?></p>
                    </div>
                </div>
                
                <!-- Disable XML-RPC -->
                <div class="ccm-setting-row" style="border-bottom: 1px solid var(--ccm-border); padding: var(--ccm-space-md) 0;">
                    <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: var(--ccm-space-md);">
                        <div style="flex: 1;">
                            <strong><?php _e('Disable XML-RPC', 'ccm-tools'); ?></strong>
                            <p class="ccm-text-muted"><?php _e('Disables XML-RPC functionality. Removes X-Pingback header and blocks xmlrpc.php requests.', 'ccm-tools'); ?></p>
                            <p class="ccm-text-muted" style="color: var(--ccm-warning);"><span class="ccm-icon">⚠</span> <?php _e('Required for Jetpack, WordPress mobile app, and some third-party services.', 'ccm-tools'); ?></p>
                        </div>
                        <label class="ccm-toggle">
                            <input type="checkbox" id="perf-disable-xmlrpc" <?php checked(!empty($settings['disable_xmlrpc'])); ?>>
                            <span class="ccm-toggle-slider"></span>
                        </label>
                    </div>
                </div>
                
                <!-- Disable RSD/WLW Links -->
                <div class="ccm-setting-row" style="border-bottom: 1px solid var(--ccm-border); padding: var(--ccm-space-md) 0;">
                    <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: var(--ccm-space-md);">
                        <div style="flex: 1;">
                            <strong><?php _e('Remove RSD & WLW Links', 'ccm-tools'); ?></strong>
                            <p class="ccm-text-muted"><?php _e('Removes Really Simple Discovery and Windows Live Writer manifest links from head. Rarely needed.', 'ccm-tools'); ?></p>
                        </div>
                        <label class="ccm-toggle">
                            <input type="checkbox" id="perf-disable-rsd-wlw" <?php checked(!empty($settings['disable_rsd_wlw'])); ?>>
                            <span class="ccm-toggle-slider"></span>
                        </label>
                    </div>
                </div>
                
                <!-- Disable Shortlink -->
                <div class="ccm-setting-row" style="border-bottom: 1px solid var(--ccm-border); padding: var(--ccm-space-md) 0;">
                    <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: var(--ccm-space-md);">
                        <div style="flex: 1;">
                            <strong><?php _e('Remove Shortlink', 'ccm-tools'); ?></strong>
                            <p class="ccm-text-muted"><?php _e('Removes the shortlink tag from head and HTTP headers. WordPress shortlinks (?p=123) are rarely used.', 'ccm-tools'); ?></p>
                        </div>
                        <label class="ccm-toggle">
                            <input type="checkbox" id="perf-disable-shortlink" <?php checked(!empty($settings['disable_shortlink'])); ?>>
                            <span class="ccm-toggle-slider"></span>
                        </label>
                    </div>
                </div>
                
                <!-- Disable REST API Links -->
                <div class="ccm-setting-row" style="border-bottom: 1px solid var(--ccm-border); padding: var(--ccm-space-md) 0;">
                    <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: var(--ccm-space-md);">
                        <div style="flex: 1;">
                            <strong><?php _e('Remove REST API Link', 'ccm-tools'); ?></strong>
                            <p class="ccm-text-muted"><?php _e('Removes the REST API discovery link from head. API still works, just not advertised.', 'ccm-tools'); ?></p>
                        </div>
                        <label class="ccm-toggle">
                            <input type="checkbox" id="perf-disable-rest-api-links" <?php checked(!empty($settings['disable_rest_api_links'])); ?>>
                            <span class="ccm-toggle-slider"></span>
                        </label>
                    </div>
                </div>
                
                <!-- Disable oEmbed -->
                <div class="ccm-setting-row" style="padding: var(--ccm-space-md) 0;">
                    <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: var(--ccm-space-md);">
                        <div style="flex: 1;">
                            <strong><?php _e('Disable oEmbed Discovery', 'ccm-tools'); ?></strong>
                            <p class="ccm-text-muted"><?php _e('Removes oEmbed discovery links and JavaScript. Others won\'t be able to embed your posts.', 'ccm-tools'); ?></p>
                        </div>
                        <label class="ccm-toggle">
                            <input type="checkbox" id="perf-disable-oembed" <?php checked(!empty($settings['disable_oembed'])); ?>>
                            <span class="ccm-toggle-slider"></span>
                        </label>
                    </div>
                </div>
            </div>
            
            <!-- Save Button -->
            <div class="ccm-card">
                <div style="display: flex; gap: var(--ccm-space-md); align-items: center; flex-wrap: wrap;">
                    <button type="button" id="save-perf-settings" class="ccm-button ccm-button-primary">
                        <?php _e('Save Settings', 'ccm-tools'); ?>
                    </button>
                    <span id="perf-save-status"></span>
                </div>
                <div id="perf-result" class="ccm-result-box" style="margin-top: var(--ccm-space-md);"></div>
            </div>
            
            <!-- Import/Export Settings -->
            <div class="ccm-card" id="import-export">
                <h2><?php _e('Import / Export Settings', 'ccm-tools'); ?></h2>
                <p class="ccm-text-muted"><?php _e('Export your settings to a JSON file for backup or to import on another site.', 'ccm-tools'); ?></p>
                
                <div style="display: flex; gap: var(--ccm-space-lg); flex-wrap: wrap; margin-top: var(--ccm-space-md);">
                    <!-- Export -->
                    <div style="flex: 1; min-width: 280px;">
                        <h3 style="margin-bottom: var(--ccm-space-sm);"><?php _e('Export Settings', 'ccm-tools'); ?></h3>
                        <p class="ccm-text-muted" style="font-size: var(--ccm-text-sm);"><?php _e('Download current settings as a JSON file.', 'ccm-tools'); ?></p>
                        <button type="button" id="export-perf-settings" class="ccm-button ccm-button-secondary" style="margin-top: var(--ccm-space-sm);">
                            📥 <?php _e('Export Settings', 'ccm-tools'); ?>
                        </button>
                    </div>
                    
                    <!-- Import -->
                    <div style="flex: 1; min-width: 280px;">
                        <h3 style="margin-bottom: var(--ccm-space-sm);"><?php _e('Import Settings', 'ccm-tools'); ?></h3>
                        <p class="ccm-text-muted" style="font-size: var(--ccm-text-sm);"><?php _e('Upload a previously exported JSON file.', 'ccm-tools'); ?></p>
                        <div style="display: flex; gap: var(--ccm-space-sm); align-items: center; margin-top: var(--ccm-space-sm);">
                            <input type="file" id="import-perf-file" accept=".json" style="display: none;">
                            <button type="button" id="import-perf-settings-btn" class="ccm-button ccm-button-secondary">
                                📤 <?php _e('Choose File', 'ccm-tools'); ?>
                            </button>
                            <span id="import-file-name" class="ccm-text-muted"></span>
                        </div>
                        <button type="button" id="import-perf-settings" class="ccm-button ccm-button-primary" style="margin-top: var(--ccm-space-sm); display: none;">
                            <?php _e('Import Settings', 'ccm-tools'); ?>
                        </button>
                    </div>
                </div>
                
                <!-- Current Settings Preview -->
                <div style="margin-top: var(--ccm-space-lg);">
                    <h3 style="margin-bottom: var(--ccm-space-sm);">
                        <?php _e('Current Settings Preview', 'ccm-tools'); ?>
                        <button type="button" id="toggle-settings-preview" class="ccm-button ccm-button-small ccm-button-secondary" style="margin-left: var(--ccm-space-sm);">
                            <?php _e('Show/Hide', 'ccm-tools'); ?>
                        </button>
                    </h3>
                    <pre id="settings-preview" style="display: none; background: var(--ccm-bg-secondary); padding: var(--ccm-space-md); border-radius: var(--ccm-radius); overflow-x: auto; font-size: var(--ccm-text-sm); max-height: 400px; overflow-y: auto;"><?php echo esc_html(wp_json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre>
                </div>
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
