<?php
/* 1b5be285c77d4edb */

// Prevent direct file access
if (!defined('ABSPATH')) {
    exit;
}

/* d0d27cf1c6b7c6d4 */
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
        'delay_js_timeout' => 0, // Seconds. 0 = wait for a real interaction.
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
        // Video optimizations for v7.16.0
        'video_lazy_load' => false,
        'video_preload_none' => false,
        // Image optimizations for v7.23.0
        'lazy_load_images'     => false,
        'image_decoding_async' => false,
        'prefetch_on_hover'    => false,
        // Head bloat removal for v7.24.0
        'remove_generator_tag'       => false,
        'remove_adjacent_post_links' => false,
        'disable_admin_bar'          => false,
        // Script/style inlining for v7.25.0
        'inline_small_scripts'   => false,
        'inline_small_styles'    => false,
        'inline_threshold_kb'    => 2,
        // Image attribute injection for v7.25.0
        'inject_image_dimensions' => false,
        'inject_srcset'           => false,
        // HTML & font optimizations (v7.26.0)
        'minify_html'            => false,
        'preload_key_requests'   => false,
        'preload_key_urls'       => array(),
        'disable_wp_embed'       => false,
        'self_host_google_fonts' => false,
        // Resource hints & third-party delay (v7.27.0)
        'preload_css_bg_image'        => false,
        'preload_css_bg_url'          => '',
        'priority_hints_above_fold'   => false,
        'priority_hints_selectors'    => '',
        'delay_third_party'           => false,
        'delay_third_party_domains'   => array(),
        // Gutenberg / WooCommerce / Cache headers (v7.28.0)
        'disable_gutenberg_frontend' => false,
        'woo_scripts_shop_only'      => false,
        'cache_control_meta'         => false,
        'stale_while_revalidate'     => false,
        // WordPress Cron / Author Archives (v7.29.0)
        'disable_wp_cron'         => false,
        'cron_interval'           => 60,
        'disable_author_archives' => false,
        // INP / Interaction Optimizations (v7.30.0)
    );

    $settings = get_option('ccm_tools_perf_settings', array());
    $cached = wp_parse_args($settings, $defaults);
    return $cached;
}
/* f6f36a4072590e35 */
function ccm_tools_perf_save_settings($settings) {
    // Clear static cache so next get_settings() call returns fresh data
    ccm_tools_perf_clear_settings_cache();
    return update_option('ccm_tools_perf_settings', $settings);
}

/* f07ca47b7415d8a4 */
function ccm_tools_perf_clear_settings_cache() {
    /* 4c94a0bd124feb49 */
    global $ccm_tools_perf_settings_dirty;
    $ccm_tools_perf_settings_dirty = true;
}

/* 89bf8983b252c168 */
function ccm_tools_perf_is_enabled() {
    $settings = ccm_tools_perf_get_settings();
    return !empty($settings['enabled']);
}

/* 61398474c3a9ce2c */
function ccm_tools_perf_should_transform_content() {
    if (function_exists('is_feed') && is_feed()) {
        return false;
    }
    if (function_exists('wp_is_json_request') && wp_is_json_request()) {
        return false;
    }
    if (defined('REST_REQUEST') && REST_REQUEST) {
        return false;
    }
    return true;
}

/* d8523f684d0990b2 */
function ccm_tools_perf_init() {
    $settings = ccm_tools_perf_get_settings();

    // Disable frontend admin bar — must run before is_admin() check to affect all users on public pages
    if (!empty($settings['enabled']) && !empty($settings['disable_admin_bar'])) {
        add_filter('show_admin_bar', '__return_false');
    }

    // Only run on frontend (except heartbeat which also helps in admin)
    if (is_admin()) {
        // In admin, only apply heartbeat reduction
        if (!empty($settings['enabled']) && !empty($settings['reduce_heartbeat'])) {
            add_filter('heartbeat_settings', 'ccm_tools_perf_reduce_heartbeat');
        }
        return;
    }
    
    // Safety: Skip for logged-in administrators so they can always fix issues
    // Unless ?ccm_test_perf=1 is in the URL (allows admin testing)
    if (ccm_tools_user_is_admin() && empty($_GET['ccm_test_perf'])) {
        return;
    }
    
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
    
    /* 825fe4f43464aa03 */
    if (!empty($settings['preload_css']) && !empty($settings['critical_css']) && !empty($settings['critical_css_code'])) {
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
    
    // Video lazy load (replace below-fold videos with poster placeholder)
    if (!empty($settings['video_lazy_load'])) {
        add_filter('the_content', 'ccm_tools_perf_video_lazy_load', 98);
        add_action('wp_footer', 'ccm_tools_perf_video_lazy_load_script', 99);
    }
    
    // Video preload none (set preload="none" on non-autoplay videos)
    if (!empty($settings['video_preload_none'])) {
        add_filter('the_content', 'ccm_tools_perf_video_preload_none', 97);
    }

    // Image lazy loading and async decoding (priority 10 — runs after LCP handler at priority 5)
    if (!empty($settings['lazy_load_images']) || !empty($settings['image_decoding_async'])) {
        add_filter('wp_get_attachment_image_attributes', 'ccm_tools_perf_image_attributes', 10, 3);
        add_filter('the_content', 'ccm_tools_perf_image_lazydecode_content', 99);
    }

    // Prefetch on hover
    if (!empty($settings['prefetch_on_hover'])) {
        add_action('wp_footer', 'ccm_tools_perf_prefetch_on_hover', 98);
    }

    // Remove WordPress generator meta tag
    if (!empty($settings['remove_generator_tag'])) {
        remove_action('wp_head', 'wp_generator');
    }

    // Remove adjacent post links and extra feed links from <head>
    if (!empty($settings['remove_adjacent_post_links'])) {
        remove_action('wp_head', 'adjacent_posts_rel_link_wp_head', 10);
        remove_action('wp_head', 'feed_links_extra', 3);
    }

    // Inline small scripts below the configured threshold
    if (!empty($settings['inline_small_scripts'])) {
        add_filter('script_loader_tag', 'ccm_tools_perf_inline_small_scripts', 5, 3);
    }

    // Inline small stylesheets below the configured threshold
    if (!empty($settings['inline_small_styles'])) {
        add_filter('style_loader_tag', 'ccm_tools_perf_inline_small_styles', 5, 4);
    }

    // Inject missing width/height on local images (CLS fix)
    if (!empty($settings['inject_image_dimensions'])) {
        add_filter('the_content', 'ccm_tools_perf_inject_image_dimensions', 20);
    }

    // Inject missing srcset/sizes on local images
    if (!empty($settings['inject_srcset'])) {
        add_filter('the_content', 'ccm_tools_perf_inject_srcset', 21);
    }

    // Minify HTML output (v7.26.0)
    if (!empty($settings['minify_html'])) {
        add_action('template_redirect', 'ccm_tools_perf_minify_html_start', 0);
    }

    // Preload key requests (v7.26.0)
    if (!empty($settings['preload_key_requests']) && !empty($settings['preload_key_urls'])) {
        add_action('wp_head', 'ccm_tools_perf_preload_key_requests', 1);
    }

    // Disable wp-embed script (v7.26.0) — different from disable_oembed which only removes discovery links
    if (!empty($settings['disable_wp_embed'])) {
        add_action('wp_enqueue_scripts', 'ccm_tools_perf_disable_wp_embed', 100);
        remove_action('wp_head', 'wp_oembed_add_host_js');
    }

    // Self-host Google Fonts (v7.26.0)
    if (!empty($settings['self_host_google_fonts'])) {
        add_filter('style_loader_src', 'ccm_tools_perf_self_host_google_fonts_src', 10, 2);
    }

    // Preload LCP CSS background image (v7.27.0)
    if (!empty($settings['preload_css_bg_image']) && !empty($settings['preload_css_bg_url'])) {
        add_action('wp_head', 'ccm_tools_perf_preload_css_bg_image', 1);
    }

    // Priority hints for above-fold images (v7.27.0)
    if (!empty($settings['priority_hints_above_fold'])) {
        add_action('template_redirect', 'ccm_tools_perf_priority_hints_start_buffer');
    }

    // Delay third-party scripts (v7.27.0)
    if (!empty($settings['delay_third_party'])) {
        add_action('template_redirect', 'ccm_tools_perf_delay_third_party_start_buffer');
    }

    // Disable Gutenberg block editor assets on frontend (v7.28.0)
    if (!empty($settings['disable_gutenberg_frontend'])) {
        add_action('wp_enqueue_scripts', 'ccm_tools_perf_disable_gutenberg_frontend', 100);
    }

    // WooCommerce scripts/styles only on shop pages (v7.28.0)
    if (!empty($settings['woo_scripts_shop_only']) && class_exists('WooCommerce')) {
        add_action('wp_enqueue_scripts', 'ccm_tools_perf_woo_scripts_shop_only', 99);
    }

    /* 428463dfb04ecd72 */
    if (!empty($settings['cache_control_meta']) || !empty($settings['stale_while_revalidate'])) {
        add_action('wp', 'ccm_tools_perf_cache_headers');
    }

    // Disable author archive pages (v7.29.0)
    if (!empty($settings['disable_author_archives'])) {
        add_action('template_redirect', 'ccm_tools_perf_disable_author_archives');
    }

    /* f0c663b87ecab4d4 */
}
add_action('init', 'ccm_tools_perf_init');

/* 8bf8c77a6dd2db4a */
add_action('plugins_loaded', 'ccm_tools_perf_cron_early_init', 5);
function ccm_tools_perf_cron_early_init() {
    $settings = ccm_tools_perf_get_settings();
    if (empty($settings['disable_wp_cron'])) {
        return;
    }
    $interval = max(1, (int)($settings['cron_interval'] ?? 60)) * MINUTE_IN_SECONDS;
    add_filter('pre_get_ready_cron_jobs', function($pre) use ($interval) {
        if (false !== get_transient('ccm_cron_throttle')) {
            return array(); // Within throttle window — tell the runner nothing is ready.
        }
        set_transient('ccm_cron_throttle', 1, $interval);
        return $pre; // First request in window — $pre is still null, so wp_cron() reads the real, untouched cron array.
    });
}

/* 49f28d5489bdab3a */
function ccm_tools_perf_defer_js($tag, $handle, $src) {
    // Skip if already has defer or async
    if (strpos($tag, 'defer') !== false || strpos($tag, 'async') !== false) {
        return $tag;
    }

    // Skip inline scripts
    if (empty($src)) {
        return $tag;
    }

    /* f2646b415fd18aa9 */
    if (ccm_tools_perf_has_inline_companion($handle)) {
        return $tag;
    }

    $settings = ccm_tools_perf_get_settings();
    $excludes = isset($settings['defer_js_excludes']) ? (array) $settings['defer_js_excludes'] : array();

    // Built-in always-exclude — known WP scripts that ship inline `-after`
    // translations and break if deferred.
    $always_exclude = array('jquery', 'jquery-core', 'jquery-migrate', 'wp-i18n', 'wp-hooks', 'wp-a11y', 'wp-polyfill');
    $excludes = array_merge($excludes, $always_exclude);

    // Check if handle is excluded
    foreach ($excludes as $exclude) {
        if (strpos($handle, $exclude) !== false) {
            return $tag;
        }
    }

    // Add defer attribute
    return str_replace(' src=', ' defer src=', $tag);
}

/* 4ad0f5180a523de3 */
function ccm_tools_perf_has_inline_companion($handle, $type = 'script') {
    if ('style' === $type) {
        global $wp_styles;
        if (!($wp_styles instanceof \WP_Styles)) return false;
        if (empty($wp_styles->registered[$handle])) return false;
        $extra = $wp_styles->registered[$handle]->extra ?? array();
        return !empty($extra['after']);
    }
    global $wp_scripts;
    if (!($wp_scripts instanceof \WP_Scripts)) return false;
    if (empty($wp_scripts->registered[$handle])) return false;
    $extra = $wp_scripts->registered[$handle]->extra ?? array();
    return !empty($extra['after']) || !empty($extra['before']);
}

/* ffe2f90254d50e08 */
function ccm_tools_perf_delay_js($tag, $handle, $src) {
    // Skip inline scripts
    if (empty($src)) {
        return $tag;
    }

    /* 431557ac640bb919 */
    if (is_user_logged_in()) {
        return $tag;
    }

    // Skip scripts with inline before/after companions (see defer_js for why)
    if (ccm_tools_perf_has_inline_companion($handle)) {
        return $tag;
    }

    $settings = ccm_tools_perf_get_settings();
    $excludes = isset($settings['delay_js_excludes']) ? (array) $settings['delay_js_excludes'] : array();

    // Always exclude jQuery and critical scripts
    $always_exclude = array('jquery', 'jquery-core', 'jquery-migrate', 'wp-i18n', 'wp-hooks', 'wp-a11y', 'wp-polyfill');
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
    
    // Change script type to delay loading (handle both single and double quotes)
    $tag = preg_replace('/\btype=["\']text\/javascript["\']/', 'type="ccmdelay/javascript"', $tag);
    
    // If no type attribute, add our delayed type
    if (strpos($tag, 'type=') === false) {
        $tag = str_replace('<script ', '<script type="ccmdelay/javascript" ', $tag);
    }
    
    // Store the original src for later execution
    $tag = str_replace(' src=', ' data-ccm-src=', $tag);
    
    return $tag;
}

/* 07535bb11f47521d */
function ccm_tools_perf_delay_js_script() {
    $settings = ccm_tools_perf_get_settings();
    /* 1aa2a08b508e9677 */
    $seconds = isset($settings['delay_js_timeout']) ? intval($settings['delay_js_timeout']) : 0;
    $timeout = max(0, min(30, $seconds)) * 1000;
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

/* 23c061755a551c9a */
function ccm_tools_perf_preload_css($tag, $handle, $href, $media) {
    // Skip admin styles
    if (strpos($handle, 'admin') !== false) {
        return $tag;
    }

    /* b84e8c6ddb47f4f6 */
    $settings = ccm_tools_perf_get_settings();
    if (empty($settings['critical_css']) || empty($settings['critical_css_code'])) {
        return $tag;
    }

    /* 993f8dfd62a6bcf1 */
    if (stripos($tag, '<style') !== false) {
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

    $excludes = isset($settings['preload_css_excludes']) ? (array) $settings['preload_css_excludes'] : array();

    // Check if handle is excluded
    foreach ($excludes as $exclude) {
        $exclude = trim($exclude);
        if (!empty($exclude) && strpos($handle, $exclude) !== false) {
            return $tag;
        }
    }

    // Keep the untouched original tag as the noscript fallback before mutating it.
    $noscript = '<noscript>' . $tag . '</noscript>';

    // Mutate the EXISTING tag in place rather than rebuilding it from scratch, so we keep
    // integrity/crossorigin/data-* and any other attributes a theme or plugin added to it.
    $mutated = preg_replace('/\smedia=(["\'])all\1/i', ' media=$1print$1 onload="this.media=\'all\'"', $tag, 1, $count);
    if (0 === $count) {
        // No media attribute present (WordPress omits it for the 'all' default) — add one
        // right before the tag's closing bracket.
        $mutated = preg_replace('/(\/?>)(\s*)$/', ' media="print" onload="this.media=\'all\'"$1$2', $tag, 1);
    }

    return $mutated . "\n" . $noscript . "\n";
}

/* 58bf9eee867d9bfb */
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

/* 82112792334c9422 */
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

/* 8a8b712e105aa197 */
function ccm_tools_perf_remove_query_strings($src) {
    if (strpos($src, 'ver=') !== false) {
        $src = remove_query_arg('ver', $src);
    }
    return $src;
}

/* 2a6e1db49df8d420 */
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

/* 747208739da29d07 */
function ccm_tools_perf_disable_dashicons() {
    if (!is_user_logged_in()) {
        wp_dequeue_style('dashicons');
        wp_deregister_style('dashicons');
    }
}

/* 7fdc77028d557e76 */
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

/* 65d6f368b0b1a821 */
function ccm_tools_perf_youtube_facade($content) {
    if (empty($content)) {
        return $content;
    }
    if (!ccm_tools_perf_should_transform_content()) {
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

/* aba155ef957d212a */
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

/* bd3f41c5a5cb5b8a */
function ccm_tools_perf_video_lazy_load($content) {
    if (empty($content) || stripos($content, '<video') === false) {
        return $content;
    }
    if (!ccm_tools_perf_should_transform_content()) {
        return $content;
    }

    $count = 0;
    $content = preg_replace_callback(
        '/<video\b([^>]*)>(.*?)<\/video>/is',
        function ($m) use (&$count) {
            $count++;
            $attrs = $m[1];
            $inner = $m[2];
            
            // Skip the first video (likely above-fold / hero)
            if ($count === 1) {
                return $m[0];
            }
            
            // Skip autoplay+muted (background video that must play immediately)
            if (preg_match('/\bautoplay\b/i', $attrs) && preg_match('/\bmuted\b/i', $attrs)) {
                return $m[0];
            }
            
            // Extract poster for the placeholder image
            $poster = '';
            if (preg_match('/\bposter\s*=\s*["\']([^"\']+)["\']/i', $attrs, $pm)) {
                $poster = $pm[1];
            }
            
            // Extract width/height for sizing
            $style_parts = array('position:relative', 'cursor:pointer', 'background:#000');
            if (preg_match('/\bwidth\s*=\s*["\']?(\d+)/i', $attrs, $wm)) {
                $style_parts[] = 'width:' . $wm[1] . 'px';
            }
            if (preg_match('/\bheight\s*=\s*["\']?(\d+)/i', $attrs, $hm)) {
                $style_parts[] = 'height:' . $hm[1] . 'px';
            }
            // Default aspect-ratio if no explicit dimensions
            if (!preg_match('/\bwidth\s*=/i', $attrs) && !preg_match('/\bheight\s*=/i', $attrs)) {
                $style_parts[] = 'aspect-ratio:16/9';
                $style_parts[] = 'width:100%';
            }
            
            // Build placeholder
            $poster_img = $poster
                ? sprintf('<img src="%s" alt="" style="width:100%%;height:100%%;object-fit:cover;display:block;" loading="lazy">', esc_url($poster))
                : '';
            
            $play_btn = '<div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:68px;height:48px;background:rgba(0,0,0,0.65);border-radius:14px;display:flex;align-items:center;justify-content:center;"><svg width="24" height="24" viewBox="0 0 24 24" fill="white"><path d="M8 5v14l11-7z"/></svg></div>';
            
            // Store the original video HTML inside a data attribute (base64 to avoid quote issues)
            $original_html = base64_encode($m[0]);
            
            return sprintf(
                '<div class="ccm-video-facade" data-ccm-video="%s" style="%s">%s%s</div>',
                esc_attr($original_html),
                esc_attr(implode(';', $style_parts)),
                $poster_img,
                $play_btn
            );
        },
        $content
    );
    
    return $content;
}

/* 53d8d4174e7ec8e9 */
function ccm_tools_perf_video_lazy_load_script() {
    ?>
    <script>
    document.addEventListener('click', function(e) {
        var facade = e.target.closest('.ccm-video-facade');
        if (!facade) return;
        var encoded = facade.getAttribute('data-ccm-video');
        if (!encoded) return;
        try {
            var html = atob(encoded);
            var tmp = document.createElement('div');
            tmp.innerHTML = html;
            var video = tmp.querySelector('video');
            if (video) {
                video.setAttribute('preload', 'auto');
                facade.replaceWith(video);
                video.play().catch(function(){});
            }
        } catch(err) {}
    });
    </script>
    <?php
}

/* 1cb0908c63ad616c */
function ccm_tools_perf_video_preload_none($content) {
    if (empty($content) || stripos($content, '<video') === false) {
        return $content;
    }
    
    $content = preg_replace_callback(
        '/<video\b([^>]*)>/i',
        function ($m) {
            $attrs = $m[1];
            
            // Skip autoplay videos — they need preload to play immediately
            if (preg_match('/\bautoplay\b/i', $attrs)) {
                return $m[0];
            }
            
            // Replace existing preload attribute or add preload="none"
            if (preg_match('/\bpreload\s*=\s*["\']?[^"\'\s]*/i', $attrs)) {
                $attrs = preg_replace('/\bpreload\s*=\s*["\']?[^"\'\s]*["\']?/i', 'preload="none"', $attrs);
            } else {
                $attrs .= ' preload="none"';
            }
            
            return '<video' . $attrs . '>';
        },
        $content
    );
    
    return $content;
}

/* 0452ed97ccb64256 */
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

/* 61333e16b5e0120a */
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

/* 534805c2e5225512 */
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

    /* 9f38d034e7400c6c */
    if (!empty($attr['class']) && preg_match('/\bcustom-logo\b/', $attr['class'])) {
        return $attr;
    }
    if (!did_action('wp_body_open')) {
        return $attr;
    }

    // Skip if already has fetchpriority
    if (isset($attr['fetchpriority'])) {
        return $attr;
    }

    /* 9da25868a3d4334c */
    static $local_claimed = false;
    if ($local_claimed) {
        return $attr;
    }

    // Add fetchpriority high to first qualifying image
    $attr['fetchpriority'] = 'high';

    // Remove lazy loading from LCP candidate
    if (isset($attr['loading']) && $attr['loading'] === 'lazy') {
        unset($attr['loading']);
    }

    $local_claimed = true;
    return $attr;
}

/* 440b8fec1df54c56 */
function ccm_tools_perf_image_attributes( $attr, $attachment, $size ) {
    // Skip the LCP image — it must not be lazy-loaded.
    if ( isset( $attr['fetchpriority'] ) && $attr['fetchpriority'] === 'high' ) {
        return $attr;
    }

    $settings = ccm_tools_perf_get_settings();

    if ( ! empty( $settings['lazy_load_images'] ) && ! isset( $attr['loading'] ) ) {
        $attr['loading'] = 'lazy';
    }

    if ( ! empty( $settings['image_decoding_async'] ) && ! isset( $attr['decoding'] ) ) {
        $attr['decoding'] = 'async';
    }

    return $attr;
}

/* 1b320ff78b839c89 */
function ccm_tools_perf_image_lazydecode_content( $content ) {
    if ( empty( $content ) || stripos( $content, '<img' ) === false ) {
        return $content;
    }
    if ( ! ccm_tools_perf_should_transform_content() ) {
        return $content;
    }

    $settings   = ccm_tools_perf_get_settings();
    $add_lazy   = ! empty( $settings['lazy_load_images'] );
    $add_decode = ! empty( $settings['image_decoding_async'] );

    if ( ! $add_lazy && ! $add_decode ) {
        return $content;
    }

    $content = preg_replace_callback(
        '/<img\b([^>]*)>/i',
        function ( $m ) use ( $add_lazy, $add_decode ) {
            $attrs = $m[1];

            // Skip the LCP image which already has fetchpriority="high".
            if ( preg_match( '/\bfetchpriority\s*=\s*["\']?high/i', $attrs ) ) {
                return $m[0];
            }

            if ( $add_lazy && stripos( $attrs, 'loading=' ) === false ) {
                $attrs .= ' loading="lazy"';
            }

            if ( $add_decode && stripos( $attrs, 'decoding=' ) === false ) {
                $attrs .= ' decoding="async"';
            }

            return '<img' . $attrs . '>';
        },
        $content
    );

    return $content;
}

/* e43851ab5287186d */
function ccm_tools_perf_prefetch_on_hover() {
    ?>
    <script id="ccm-prefetch-on-hover">
    (function(){
        if ('connection' in navigator && navigator.connection.saveData) return;
        var prefetched = new Set(), timer = null;
        function prefetch(url) {
            if (prefetched.has(url)) return;
            prefetched.add(url);
            var link = document.createElement('link');
            link.rel  = 'prefetch';
            link.href = url;
            link.as   = 'document';
            document.head.appendChild(link);
        }
        function onIntent(e) {
            var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
            if (!a) return;
            var url = a.href;
            if (!url || a.origin !== location.origin) return;
            if (url === location.href) return;
            var h = a.getAttribute('href') || '';
            if (h.startsWith('#') || h.startsWith('javascript:')) return;
            clearTimeout(timer);
            timer = setTimeout(function(){ prefetch(url); }, 100);
        }
        document.addEventListener('mouseover',  onIntent);
        document.addEventListener('touchstart', onIntent, {passive: true});
    })();
    </script>
    <?php
}

/* e9a27e82f1b326ca */
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

/* e6d83b1be56d6748 */
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

/* b89ff15f371799eb */
function ccm_tools_perf_font_display_preload() {
    // Add preconnect for fonts.gstatic.com (actual font files)
    echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
}

/* 189ca5f324a7d247 */
function ccm_tools_perf_font_display_start_buffer() {
    // Don't buffer AJAX requests, admin, REST API, or feeds
    if (wp_doing_ajax() || is_admin() || (defined('REST_REQUEST') && REST_REQUEST) || is_feed()) {
        return;
    }
    
    ob_start('ccm_tools_perf_font_display_process_buffer');
}

/* 1636f189cb89627e */
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
            
            // Check if font-display is already set
            if (preg_match('/font-display\s*:/i', $content)) {
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

/* 642a7e1a910733aa */
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

/* 22681733e7f579a8 */
function ccm_tools_perf_inline_critical_css() {
    $settings = ccm_tools_perf_get_settings();
    
    if (empty($settings['critical_css_code'])) {
        return;
    }
    
    // Sanitize critical CSS — strip closing style tags to prevent breakout
    $css = str_ireplace('</style>', '', $settings['critical_css_code']);
    
    echo '<style id="ccm-critical-css">' . $css . '</style>' . "\n";
}

/* 892d12a4eec2dd5d */
function ccm_tools_perf_disable_jquery_migrate($scripts) {
    if (!is_admin() && isset($scripts->registered['jquery'])) {
        $jquery = $scripts->registered['jquery'];
        
        if ($jquery->deps) {
            $jquery->deps = array_diff($jquery->deps, array('jquery-migrate'));
        }
    }
}

/* 879369ed817aabfe */
function ccm_tools_perf_disable_block_css() {
    // A block theme's `global-styles` carries its entire theme.json presets and layout —
    // dequeuing it on a block theme renders the site unstyled.
    if (function_exists('wp_is_block_theme') && wp_is_block_theme()) {
        return;
    }
    wp_dequeue_style('wp-block-library');
    wp_dequeue_style('wp-block-library-theme');
    wp_dequeue_style('wc-blocks-style'); /* a8c9db826c70b867 */
    if (!(function_exists('has_blocks') && has_blocks())) {
        wp_dequeue_style('global-styles'); // Global styles
    }
}

/* 9f17ba8f1b97a09a */
function ccm_tools_perf_disable_cart_fragments() {
    if (class_exists('WooCommerce')) {
        // Only disable on non-cart/checkout pages
        if (function_exists('is_cart') && !is_cart() && !is_checkout()) {
            wp_dequeue_script('wc-cart-fragments');
        }
    }
}

/* aab1504a84767ffa */
function ccm_tools_perf_reduce_heartbeat($settings) {
    $perf_settings = ccm_tools_perf_get_settings();
    $interval = isset($perf_settings['heartbeat_interval']) ? intval($perf_settings['heartbeat_interval']) : 60;
    
    // Ensure minimum of 15 seconds, maximum of 120 seconds
    $interval = max(15, min(120, $interval));
    
    $settings['interval'] = $interval;
    return $settings;
}

/* d73bd3bdb260c6cf */
function ccm_tools_perf_remove_x_pingback($headers) {
    unset($headers['X-Pingback']);
    return $headers;
}

/* 9c2bf9a06b519c27 */
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

/* 16b3a8e2f463eb14 */
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

/* 47cee0c5e8c3bc3f */
function ccm_tools_perf_url_to_path( $url ) {
    $url        = strtok( $url, '?' );
    $site_url   = rtrim( site_url( '/' ), '/' );
    $abspath    = rtrim( ABSPATH, '/\\' );

    if ( strpos( $url, $site_url ) === 0 ) {
        return $abspath . '/' . ltrim( substr( $url, strlen( $site_url ) ), '/' );
    }
    // Handle protocol-relative URLs
    $url_no_scheme  = preg_replace( '#^https?:#', '', $url );
    $site_no_scheme = preg_replace( '#^https?:#', '', $site_url );
    if ( strpos( $url_no_scheme, $site_no_scheme ) === 0 ) {
        return $abspath . '/' . ltrim( substr( $url_no_scheme, strlen( $site_no_scheme ) ), '/' );
    }
    return false;
}

/* 1e0b28cbf73c3f31 */
function ccm_tools_perf_is_safe_inline_path( $path, $allowed_exts ) {
    if ( empty( $path ) || ! is_file( $path ) ) {
        return false;
    }
    $ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
    if ( ! in_array( $ext, $allowed_exts, true ) ) {
        return false;
    }
    $real = realpath( $path );
    if ( ! $real ) {
        return false;
    }
    $roots = array();
    $wp_content_real = realpath( WP_CONTENT_DIR );
    if ( $wp_content_real ) {
        $roots[] = $wp_content_real;
    }
    $wp_includes_real = realpath( ABSPATH . 'wp-includes' );
    if ( $wp_includes_real ) {
        $roots[] = $wp_includes_real;
    }
    foreach ( $roots as $root ) {
        if ( $real === $root || strpos( $real . DIRECTORY_SEPARATOR, $root . DIRECTORY_SEPARATOR ) === 0 ) {
            return true;
        }
    }
    return false;
}

/* 4a8a2a0892f121d3 */
function ccm_tools_perf_splice_into_tag( $tag, $pattern, $replacement ) {
    if ( ! preg_match( $pattern, $tag, $m, PREG_OFFSET_CAPTURE ) ) {
        return false;
    }
    $match  = $m[0][0];
    $offset = $m[0][1];
    return substr_replace( $tag, $replacement, $offset, strlen( $match ) );
}

/* 06636c7beeda9345 */
function ccm_tools_perf_inline_small_scripts( $tag, $handle, $src ) {
    if ( empty( $src ) || strpos( $src, 'wp-admin' ) !== false ) {
        return $tag;
    }

    /* 866cffc8d46d8f9e */
    if ( ccm_tools_perf_has_inline_companion( $handle ) || preg_match( '/\b(defer|async)\b/i', $tag ) ) {
        return $tag;
    }

    $settings  = ccm_tools_perf_get_settings();
    $threshold = max( 1, intval( $settings['inline_threshold_kb'] ) ) * 1024;

    // Skip external scripts
    $site_url       = site_url( '/' );
    $url_no_scheme  = preg_replace( '#^https?:#', '', $src );
    $site_no_scheme = preg_replace( '#^https?:#', '', $site_url );
    if ( strpos( $url_no_scheme, $site_no_scheme ) !== 0 ) {
        return $tag;
    }

    $path = ccm_tools_perf_url_to_path( $src );
    if ( ! ccm_tools_perf_is_safe_inline_path( $path, array( 'js' ) ) ) {
        return $tag;
    }
    $size = @filesize( $path );
    if ( $size === false || $size > $threshold ) {
        return $tag;
    }
    $content = @file_get_contents( $path );
    if ( $content === false || $content === '' ) {
        return $tag;
    }
    // Prevent </script> in JS file from prematurely closing the script block (case-insensitive)
    $content = preg_replace( '#</(script)#i', '<\\/$1', $content );

    $inline = '<script id="' . esc_attr( $handle ) . '-inline">' . "\n" . $content . "\n" . '</script>';

    // Replace ONLY the <script ... src=...></script> element inside $tag, leaving any
    // translations/before/after companions that WordPress already concatenated in intact.
    $spliced = ccm_tools_perf_splice_into_tag( $tag, '/<script\b[^>]*\bsrc=[^>]*><\/script>/i', $inline );
    if ( false === $spliced ) {
        // Couldn't find the expected <script src> shape — safer to leave $tag untouched
        // than to guess and risk dropping content.
        return $tag;
    }
    return $spliced . "\n";
}

/* 305f720211ce8d41 */
function ccm_tools_perf_inline_small_styles( $tag, $handle, $href, $media ) {
    if ( empty( $href ) || strpos( $href, 'wp-admin' ) !== false ) {
        return $tag;
    }

    // Same reasoning as inline_small_scripts: don't discard a registered inline
    // companion (wp_add_inline_style 'after') by rebuilding the tag out from under it.
    if ( ccm_tools_perf_has_inline_companion( $handle, 'style' ) ) {
        return $tag;
    }

    $settings  = ccm_tools_perf_get_settings();
    $threshold = max( 1, intval( $settings['inline_threshold_kb'] ) ) * 1024;

    // Skip external stylesheets
    $site_url       = site_url( '/' );
    $url_no_scheme  = preg_replace( '#^https?:#', '', $href );
    $site_no_scheme = preg_replace( '#^https?:#', '', $site_url );
    if ( strpos( $url_no_scheme, $site_no_scheme ) !== 0 ) {
        return $tag;
    }

    $path = ccm_tools_perf_url_to_path( $href );
    if ( ! ccm_tools_perf_is_safe_inline_path( $path, array( 'css' ) ) ) {
        return $tag;
    }
    $size = @filesize( $path );
    if ( $size === false || $size > $threshold ) {
        return $tag;
    }
    $content = @file_get_contents( $path );
    if ( $content === false || $content === '' ) {
        return $tag;
    }
    // Escape closing style tags to prevent HTML breakout (case-insensitive)
    $content = preg_replace( '#</(style)#i', '<\\/$1', $content );
    $media_attr = ( $media && $media !== 'all' ) ? ' media="' . esc_attr( $media ) . '"' : '';
    $inline = '<style id="' . esc_attr( $handle ) . '-inline"' . $media_attr . '>' . "\n" . $content . "\n" . '</style>';

    // Mutate the existing tag rather than always fully discarding it: replace only the
    // <link ... href=...> element so anything else WordPress put in $tag survives.
    $spliced = ccm_tools_perf_splice_into_tag( $tag, '/<link\b[^>]*\bhref=[^>]*>/i', $inline );
    return ( false !== $spliced ? $spliced : $inline ) . "\n";
}

/* e3efb1bc7d9ad4d9 */
function ccm_tools_perf_resolve_attachment_id( $src_clean ) {
    static $cache = array();
    if ( array_key_exists( $src_clean, $cache ) ) {
        return $cache[ $src_clean ];
    }
    $attachment_id = attachment_url_to_postid( $src_clean );
    if ( ! $attachment_id ) {
        $stripped = preg_replace( '/-\d+x\d+(?=\.[A-Za-z0-9]+$)/', '', $src_clean );
        if ( $stripped !== $src_clean ) {
            $attachment_id = attachment_url_to_postid( $stripped );
        }
    }
    $cache[ $src_clean ] = (int) $attachment_id;
    return $cache[ $src_clean ];
}

/* 24b8dce2ef315ba3 */
function ccm_tools_perf_inject_image_dimensions( $content ) {
    if ( empty( $content ) || ! is_string( $content ) ) {
        return $content;
    }
    if ( ! ccm_tools_perf_should_transform_content() ) {
        return $content;
    }
    return preg_replace_callback( '/<img\s[^>]+>/i', function ( $matches ) {
        $tag        = $matches[0];
        $has_width  = (bool) preg_match( '/\bwidth\s*=/i', $tag );
        $has_height = (bool) preg_match( '/\bheight\s*=/i', $tag );
        if ( $has_width && $has_height ) {
            return $tag;
        }
        if ( ! preg_match( '/\bsrc\s*=\s*["\']([^"\']+)["\']/', $tag, $src_m ) ) {
            return $tag;
        }
        $src         = $src_m[1];
        $upload_dir  = wp_upload_dir();
        $upload_base = $upload_dir['baseurl'];
        if ( strpos( $src, $upload_base ) === false && strpos( $src, '/wp-content/uploads/' ) === false ) {
            return $tag;
        }
        $src_clean     = strtok( $src, '?' );
        $attachment_id = ccm_tools_perf_resolve_attachment_id( $src_clean );
        if ( ! $attachment_id ) {
            return $tag;
        }
        $meta = wp_get_attachment_metadata( $attachment_id );
        if ( empty( $meta ) ) {
            return $tag;
        }
        $width = $height = 0;
        $dimensions = function_exists( 'wp_image_src_get_dimensions' )
            ? wp_image_src_get_dimensions( $src_clean, $meta, $attachment_id )
            : false;
        if ( $dimensions ) {
            list( $width, $height ) = $dimensions;
        } elseif ( ! empty( $meta['width'] ) && ! empty( $meta['height'] ) ) {
            $width  = (int) $meta['width'];
            $height = (int) $meta['height'];
        } else {
            return $tag;
        }
        if ( ! $has_width ) {
            $tag = preg_replace( '/(<img\s)/i', '$1width="' . $width . '" ', $tag, 1 );
        }
        if ( ! $has_height ) {
            $tag = preg_replace( '/(<img\s)/i', '$1height="' . $height . '" ', $tag, 1 );
        }
        return $tag;
    }, $content );
}

/* 882eeea756f01457 */
function ccm_tools_perf_inject_srcset( $content ) {
    if ( empty( $content ) || ! is_string( $content ) ) {
        return $content;
    }
    if ( ! ccm_tools_perf_should_transform_content() ) {
        return $content;
    }
    return preg_replace_callback( '/<img\s[^>]+>/i', function ( $matches ) {
        $tag = $matches[0];
        // Skip if already has srcset
        if ( preg_match( '/\bsrcset\s*=/i', $tag ) ) {
            return $tag;
        }
        if ( ! preg_match( '/\bsrc\s*=\s*["\']([^"\']+)["\']/', $tag, $src_m ) ) {
            return $tag;
        }
        $src         = $src_m[1];
        $upload_dir  = wp_upload_dir();
        $upload_base = $upload_dir['baseurl'];
        if ( strpos( $src, $upload_base ) === false && strpos( $src, '/wp-content/uploads/' ) === false ) {
            return $tag;
        }
        $src_clean     = strtok( $src, '?' );
        $attachment_id = ccm_tools_perf_resolve_attachment_id( $src_clean );
        if ( ! $attachment_id ) {
            return $tag;
        }
        $srcset = wp_get_attachment_image_srcset( $attachment_id );
        if ( ! $srcset ) {
            return $tag;
        }
        // Determine sizes from width attribute, otherwise use full-width fallback
        $sizes = '100vw';
        if ( preg_match( '/\bwidth\s*=\s*["\']([0-9]+)["\']/', $tag, $w_m ) ) {
            $w     = (int) $w_m[1];
            $sizes = '(max-width: ' . $w . 'px) 100vw, ' . $w . 'px';
        }
        // Inject before the closing >
        $tag = preg_replace( '/(\s*\/?>)$/', ' srcset="' . esc_attr( $srcset ) . '" sizes="' . esc_attr( $sizes ) . '"$1', $tag, 1 );
        return $tag;
    }, $content );
}

/* 2e7256aed6484a43 */
function ccm_tools_perf_minify_html_start() {
    if ( is_feed() ) return;
    ob_start( 'ccm_tools_perf_minify_html_callback' );
}

/* c7e3877cde31f86b */
function ccm_tools_perf_minify_html_callback( $html ) {
    /* ffdd23160208596e */
    $head = ltrim( substr( $html, 0, 200 ) );
    if ( stripos( $head, '<!DOCTYPE' ) !== 0 && stripos( $head, '<html' ) !== 0 ) {
        return $html;
    }

    /* 602a8bb824101729 */
    $original = $html;

    $preserve = array();
    $i        = 0;
    /* 1c7901944ff6473d */
    $html = preg_replace_callback(
        '/<(pre|textarea|script|style)\b[^>]*>.*?<\/\1>/si',
        function ( $matches ) use ( &$preserve, &$i ) {
            $key          = '%%CCM_PRESERVE_' . $i . '%%';
            $preserve[$i] = $matches[0];
            $i++;
            return $key;
        },
        $html
    );
    if ( null === $html ) { return $original; }

    // Remove HTML comments (keep IE conditionals <!--[if)
    $html = preg_replace( '/<!--(?!\[if\s)(?!<!)[^\[>].*?-->/si', '', $html );
    if ( null === $html ) { return $original; }

    // Collapse runs of whitespace between tags to a single space
    // Preserves the space that inline elements need (a, span, button, img, etc.)
    $html = preg_replace( '/>\s+</', '> <', $html );
    if ( null === $html ) { return $original; }

    // Strip leading/trailing whitespace per line
    $html = preg_replace( '/^\s+|\s+$/m', '', $html );
    if ( null === $html ) { return $original; }
    // Restore preserved blocks
    foreach ( $preserve as $idx => $content ) {
        $html = str_replace( '%%CCM_PRESERVE_' . $idx . '%%', $content, $html );
    }
    return $html;
}

/* 41cf1386e0d99903 */
function ccm_tools_perf_preload_key_requests() {
    $settings = ccm_tools_perf_get_settings();
    $urls     = isset( $settings['preload_key_urls'] ) ? (array) $settings['preload_key_urls'] : array();
    foreach ( $urls as $url ) {
        $url = esc_url( trim( $url ) );
        if ( empty( $url ) ) {
            continue;
        }
        $path        = wp_parse_url( $url, PHP_URL_PATH );
        $ext         = strtolower( pathinfo( $path ?? '', PATHINFO_EXTENSION ) );
        $as          = 'fetch';
        $crossorigin = '';
        if ( in_array( $ext, array( 'woff', 'woff2', 'ttf', 'otf', 'eot' ), true ) ) {
            $as          = 'font';
            $crossorigin = ' crossorigin="anonymous"';
        } elseif ( 'css' === $ext ) {
            $as = 'style';
        } elseif ( 'js' === $ext ) {
            $as = 'script';
        } elseif ( in_array( $ext, array( 'jpg', 'jpeg', 'png', 'webp', 'avif', 'gif', 'svg' ), true ) ) {
            $as = 'image';
        } elseif ( in_array( $ext, array( 'mp4', 'webm', 'ogg' ), true ) ) {
            $as = 'video';
        }
        echo '<link rel="preload" href="' . $url . '" as="' . esc_attr( $as ) . '"' . $crossorigin . ">\n";
    }
}

/* 00144383ff29ddd8 */
function ccm_tools_perf_disable_wp_embed() {
    wp_deregister_script( 'wp-embed' );
}

/* ee18b901edc69542 */
function ccm_tools_perf_self_host_google_fonts_src( $src, $handle ) {
    // Exact-host match rather than strpos() anywhere in the string — a URL like
    // https://evil.example/?x=fonts.googleapis.com would otherwise pass.
    if ( wp_parse_url( $src, PHP_URL_HOST ) !== 'fonts.googleapis.com' ) {
        return $src;
    }
    $local = ccm_tools_perf_fetch_local_google_font( $src );
    return $local ?: $src;
}

/* 1aa973dbe68c8489 */
function ccm_tools_perf_fetch_local_google_font( $fonts_url ) {
    /* b890ea14bc1e1b63 */
    $fonts_url = add_query_arg( 'display', 'swap', $fonts_url );

    $upload_dir     = wp_upload_dir();
    $fonts_dir      = $upload_dir['basedir'] . '/ccm-fonts';
    $fonts_url_base = $upload_dir['baseurl'] . '/ccm-fonts';
    if ( ! file_exists( $fonts_dir ) ) {
        if ( ! wp_mkdir_p( $fonts_dir ) ) {
            return false;
        }
        file_put_contents( $fonts_dir . '/.htaccess', 'Options -Indexes' );
        file_put_contents( $fonts_dir . '/index.php', '<?php // Silence is golden.' );
    }
    $cache_key = md5( $fonts_url );
    $css_file  = $fonts_dir . '/' . $cache_key . '.css';
    $css_url   = $fonts_url_base . '/' . $cache_key . '.css';
    // Return cached CSS if less than 30 days old
    if ( file_exists( $css_file ) && ( time() - filemtime( $css_file ) ) < 30 * DAY_IN_SECONDS ) {
        return $css_url;
    }
    // Fetch Google Fonts CSS with a modern Chrome UA to receive WOFF2 format.
    // wp_safe_remote_get() blocks requests that resolve to internal/private IPs.
    $response = wp_safe_remote_get( $fonts_url, array(
        'timeout' => 10,
        'headers' => array(
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        ),
    ) );
    if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
        return false;
    }
    $css_content = wp_remote_retrieve_body( $response );
    $allowed_font_exts = array( 'woff2', 'woff', 'ttf', 'otf' );
    // Download individual font files from fonts.gstatic.com and rewrite to local URLs
    $css_content = preg_replace_callback(
        '/url\((["\']?)(\bhttps?:\/\/fonts\.gstatic\.com\/[^)"\' \t]+)\1\)/i',
        function ( $matches ) use ( $fonts_dir, $fonts_url_base, $allowed_font_exts ) {
            $font_url = $matches[2];
            $font_ext = strtolower( pathinfo( wp_parse_url( $font_url, PHP_URL_PATH ) ?? '', PATHINFO_EXTENSION ) );
            if ( ! in_array( $font_ext, $allowed_font_exts, true ) ) {
                return $matches[0];
            }
            $font_file  = md5( $font_url ) . '.' . $font_ext;
            $local_path = $fonts_dir . '/' . $font_file;
            $local_url  = $fonts_url_base . '/' . $font_file;
            if ( ! file_exists( $local_path ) ) {
                $font_response = wp_safe_remote_get( $font_url, array( 'timeout' => 15 ) );
                if ( ! is_wp_error( $font_response ) && 200 === (int) wp_remote_retrieve_response_code( $font_response ) ) {
                    $written = file_put_contents( $local_path, wp_remote_retrieve_body( $font_response ) );
                    if ( $written === false ) {
                        return $matches[0];
                    }
                } else {
                    return $matches[0];
                }
            }
            return 'url(' . $local_url . ')';
        },
        $css_content
    );
    if ( false === file_put_contents( $css_file, $css_content ) ) {
        return false;
    }
    return $css_url;
}

/* 3e6450e91305b45c */
function ccm_tools_perf_preload_css_bg_image() {
    $settings = ccm_tools_perf_get_settings();
    $url = esc_url( $settings['preload_css_bg_url'] ?? '' );
    if ( empty( $url ) ) return;
    echo '<link rel="preload" as="image" href="' . $url . '" fetchpriority="high">' . "\n";
}

/* 3ee5a17ab16308e3 */
function ccm_tools_perf_priority_hints_start_buffer() {
    if ( is_admin() || is_feed() ) return;
    ob_start( 'ccm_tools_perf_priority_hints_process_buffer' );
}

/* f4703857c1df8765 */
function ccm_tools_perf_priority_hints_process_buffer( $html ) {
    $settings = ccm_tools_perf_get_settings();
    $selectors_raw = $settings['priority_hints_selectors'] ?? '';

    // Parse selectors — split by comma or newline
    $selectors = array();
    if ( ! empty( $selectors_raw ) ) {
        foreach ( preg_split( '/[\r\n,]+/', $selectors_raw ) as $s ) {
            $s = trim( $s );
            if ( $s !== '' ) $selectors[] = $s;
        }
    }

    $count = 0;
    $limit = empty( $selectors ) ? 3 : PHP_INT_MAX;

    $html = preg_replace_callback(
        '/<img\s[^>]+>/is',
        function ( $matches ) use ( &$count, $limit, $selectors ) {
            if ( $count >= $limit ) return $matches[0];
            $tag = $matches[0];

            // Class-based selector matching (e.g. .hero-image)
            if ( ! empty( $selectors ) ) {
                preg_match( '/\bclass=["\']([^"\']*)["\']/', $tag, $class_match );
                $classes = isset( $class_match[1] ) ? array_filter( array_map( 'trim', explode( ' ', $class_match[1] ) ) ) : array();
                $matched = false;
                foreach ( $selectors as $selector ) {
                    if ( strpos( $selector, '.' ) === 0 ) {
                        $cls = ltrim( $selector, '.' );
                        if ( in_array( $cls, $classes, true ) ) {
                            $matched = true;
                            break;
                        }
                    }
                }
                if ( ! $matched ) return $tag;
            }

            // Skip if already has fetchpriority
            if ( stripos( $tag, 'fetchpriority' ) !== false ) return $tag;

            // Remove loading="lazy"
            $tag = preg_replace( '/\s*loading=["\']lazy["\']/', '', $tag );

            // Add fetchpriority="high" before the closing >
            $tag = preg_replace( '/\s*\/?>\s*$/', ' fetchpriority="high">', $tag );

            $count++;
            return $tag;
        },
        $html
    );

    return $html;
}

/* 28b71b6fb5e83629 */
function ccm_tools_perf_delay_third_party_start_buffer() {
    if ( is_admin() || is_feed() ) return;
    ob_start( 'ccm_tools_perf_delay_third_party_process_buffer' );
}

/* f0d7f112dcad7b79 */
function ccm_tools_perf_delay_third_party_process_buffer( $html ) {
    $settings = ccm_tools_perf_get_settings();
    $domains = $settings['delay_third_party_domains'] ?? array();

    // Default domains if none configured
    if ( empty( $domains ) ) {
        $domains = array(
            'googletagmanager.com', 'google-analytics.com', 'facebook.net',
            'hotjar.com', 'intercom.io', 'crisp.chat', 'tawk.to',
        );
    }

    $modified = false;

    $html = preg_replace_callback(
        '/<script\b([^>]*)>/i',
        function ( $matches ) use ( $domains, &$modified ) {
            $attrs_str = $matches[1];

            // Must have a src attribute
            if ( ! preg_match( '/\bsrc=["\']([^"\']+)["\']/', $attrs_str, $src_match ) ) {
                return $matches[0];
            }
            $src = $src_match[1];

            // Only delay external scripts matching configured domains
            $is_match = false;
            $host = wp_parse_url( $src, PHP_URL_HOST );
            if ( $host ) {
                foreach ( $domains as $domain ) {
                    $domain = trim( (string) $domain );
                    if ( $domain !== '' && strpos( $host, $domain ) !== false ) {
                        $is_match = true;
                        break;
                    }
                }
            }

            if ( ! $is_match ) return $matches[0];

            $modified = true;

            // Replace src with data-ccm-delay-src and change type to text/plain
            $new_attrs = preg_replace( '/\btype=["\'][^"\']*["\']/', '', $attrs_str );
            $new_attrs = preg_replace( '/\bsrc=["\'][^"\']+["\']/', 'type="text/plain" data-ccm-delay-src="' . esc_attr( $src ) . '"', $new_attrs );
            $new_attrs = preg_replace( '/\s+/', ' ', trim( $new_attrs ) );

            return '<script ' . $new_attrs . '>';
        },
        $html
    );

    if ( $modified ) {
        $html .= '<script>(function(){var loaded=false;function loadDelayed(){if(loaded)return;loaded=true;document.querySelectorAll("script[data-ccm-delay-src]").forEach(function(el){var s=document.createElement("script");["async","defer","crossorigin","integrity","nonce","id"].forEach(function(a){if(el.hasAttribute(a))s.setAttribute(a,el.getAttribute(a));});s.src=el.getAttribute("data-ccm-delay-src");el.parentNode.replaceChild(s,el);});}["keydown","mousemove","touchstart","scroll","click"].forEach(function(e){document.addEventListener(e,loadDelayed,{once:true,passive:true});});setTimeout(loadDelayed,5000);})();</script>';
    }

    return $html;
}

/* 6d69814b4b64a13d */
function ccm_tools_perf_disable_gutenberg_frontend() {
    // Same block-theme guard as disable_block_css — global-styles is theme.json-driven.
    if (function_exists('wp_is_block_theme') && wp_is_block_theme()) {
        return;
    }
    wp_dequeue_style('wp-block-library');
    wp_dequeue_style('wp-block-library-theme');
    if (!(function_exists('has_blocks') && has_blocks())) {
        wp_dequeue_style('global-styles');
    }
    wp_dequeue_style('classic-theme-styles');
}

/* 11011e4910d26dd1 */
function ccm_tools_perf_page_needs_woo_scripts() {
    $post = get_post();
    if ($post instanceof WP_Post && is_string($post->post_content) && $post->post_content !== '') {
        // Catches every block in the woocommerce/ namespace (cart, checkout, mini-cart,
        // all-products, product grids, etc.) regardless of the exact block name.
        if (strpos($post->post_content, '<!-- wp:woocommerce/') !== false) {
            return true;
        }
        if (function_exists('has_shortcode')) {
            $woo_shortcodes = array(
                'products', 'product', 'product_page', 'add_to_cart', 'add_to_cart_url',
                'woocommerce_cart', 'woocommerce_checkout', 'woocommerce_my_account',
                'product_category', 'product_categories', 'sale_products',
                'best_selling_products', 'featured_products', 'recent_products', 'related_products',
            );
            foreach ($woo_shortcodes as $shortcode) {
                if (has_shortcode($post->post_content, $shortcode)) {
                    return true;
                }
            }
        }
    }

    // Cart / mini-cart widget active in any sidebar.
    if (function_exists('is_active_widget') && is_active_widget(false, false, 'woocommerce_widget_cart', true)) {
        return true;
    }

    return false;
}

/* 50d378b56130ea6b */
function ccm_tools_perf_woo_scripts_shop_only() {
    if (is_woocommerce() || is_cart() || is_checkout() || is_account_page()) {
        return;
    }
    if (ccm_tools_perf_page_needs_woo_scripts()) {
        return;
    }
    wp_dequeue_script('wc-cart-fragments');
    wp_dequeue_script('woocommerce');
    wp_dequeue_script('wc-add-to-cart');
    wp_dequeue_script('wc-add-to-cart-variation');
    wp_dequeue_script('wc-single-product');
    wp_dequeue_style('woocommerce-general');
    wp_dequeue_style('woocommerce-layout');
    wp_dequeue_style('woocommerce-smallscreen');
}

/* 45b0a84f8ce6e346 */
function ccm_tools_perf_cache_headers() {
    if (is_admin() || is_user_logged_in()) {
        return;
    }
    /* f0d5ba927716c320 */
    if (function_exists('is_search') && is_search()) {
        return;
    }
    $settings = ccm_tools_perf_get_settings();
    if (empty($settings['cache_control_meta']) && empty($settings['stale_while_revalidate'])) {
        return;
    }

    /* a787d1e66b5b3158 */
    foreach (headers_list() as $sent_header) {
        if (stripos($sent_header, 'Set-Cookie:') === 0) {
            return;
        }
    }

    // Bail if this request already carries a WooCommerce guest cart/session cookie — the
    // response was built for that specific cart even if no new Set-Cookie is being sent.
    foreach (array_keys($_COOKIE) as $cookie_name) {
        if (strpos($cookie_name, 'woocommerce_') === 0 || strpos($cookie_name, 'wp_woocommerce_session_') === 0) {
            return;
        }
    }

    $parts = array('public', 'max-age=3600');
    if (!empty($settings['stale_while_revalidate'])) {
        $parts[] = 'stale-while-revalidate=86400';
    }
    header('Cache-Control: ' . implode(', ', $parts));

    /* 92394ee3e1d485b9 */
    $vary_values = array();
    foreach (headers_list() as $sent_header) {
        if (stripos($sent_header, 'Vary:') === 0) {
            foreach (explode(',', substr($sent_header, strlen('Vary:'))) as $v) {
                $v = trim($v);
                if ($v !== '') {
                    $vary_values[] = $v;
                }
            }
        }
    }
    if (!in_array('Cookie', $vary_values, true)) {
        $vary_values[] = 'Cookie';
    }
    header('Vary: ' . implode(', ', $vary_values));
}

/* dbf86c33b66941d7 */
function ccm_tools_perf_disable_author_archives() {
    if (is_author()) {
        wp_redirect(home_url('/'), 301);
        exit;
    }
}

/* 672833a445021752 */
function ccm_tools_perf_render_field(array $field, array $settings): void {
    $key   = $field['key'];
    $id    = ccm_tools_perf_field_id($key);
    $type  = $field['type'] ?? 'text';
    $value = $settings[$key] ?? '';

    if (is_array($value)) {
        // List fields round-trip as one value per line.
        $value = implode("\n", $value);
    }
    ?>
    <div class="ccm-optfield">
        <label for="<?php echo esc_attr($id); ?>"><?php echo esc_html($field['label']); ?></label>
        <?php if ($type === 'textarea' || $type === 'list') : ?>
            <textarea id="<?php echo esc_attr($id); ?>"
                      class="ccm-input<?php echo !empty($field['mono']) ? ' ccm-mono' : ''; ?>"
                      rows="<?php echo (int) ($field['rows'] ?? ($type === 'list' ? 3 : 6)); ?>"
                      placeholder="<?php echo esc_attr($field['placeholder'] ?? ''); ?>"><?php
                echo esc_textarea((string) $value);
            ?></textarea>
        <?php elseif ($type === 'number') : ?>
            <span class="ccm-optfield__inline">
                <input type="number" id="<?php echo esc_attr($id); ?>" class="ccm-input"
                       value="<?php echo esc_attr((string) $value); ?>"
                       min="<?php echo (int) ($field['min'] ?? 0); ?>"
                       max="<?php echo (int) ($field['max'] ?? 9999); ?>">
                <?php if (!empty($field['suffix'])) : ?>
                    <span class="ccm-optfield__suffix"><?php echo esc_html($field['suffix']); ?></span>
                <?php endif; ?>
            </span>
        <?php elseif ($type === 'select') : ?>
            <select id="<?php echo esc_attr($id); ?>">
                <?php foreach (($field['options'] ?? array()) as $ov => $ol) : ?>
                    <option value="<?php echo esc_attr($ov); ?>" <?php selected((string) $value, (string) $ov); ?>>
                        <?php echo esc_html($ol); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        <?php else : ?>
            <input type="text" id="<?php echo esc_attr($id); ?>" class="ccm-input"
                   value="<?php echo esc_attr((string) $value); ?>"
                   placeholder="<?php echo esc_attr($field['placeholder'] ?? ''); ?>">
        <?php endif; ?>
        <?php if (!empty($field['hint'])) : ?>
            <span class="ccm-optfield__hint"><?php echo esc_html($field['hint']); ?></span>
        <?php endif; ?>
    </div>
    <?php
}

/* 060c61b29a8503f0 */
function ccm_tools_perf_render_option(array $item, array $settings): void {
    $key  = $item['key'];
    $id   = ccm_tools_perf_field_id($key);
    $on   = !empty($settings[$key]);
    $risk = $item['risk'] ?? 'test';

    // A setting can declare a prerequisite: preload_css is meaningless, and
    // actively harmful, without critical CSS actually pasted in.
    $blocked = false;
    if (!empty($item['requires']) && empty($settings[$item['requires']])) {
        $blocked = true;
    }

    $risk_label = array(
        'safe'  => __('Safe', 'ccm-tools'),
        'test'  => __('Test after', 'ccm-tools'),
        'risky' => __('Can break things', 'ccm-tools'),
    );
    $risk_class = array('safe' => 'good', 'test' => '', 'risky' => 'bad');
    ?>
    <div class="ccm-opt<?php echo $on ? ' is-on' : ''; ?>"
         data-risk="<?php echo esc_attr($risk); ?>"
         data-state="<?php echo $on ? 'on' : 'off'; ?>"
         data-search="<?php echo esc_attr(strtolower($item['label'] . ' ' . $item['desc'] . ' ' . $key)); ?>">
        <div class="ccm-opt__main">
            <div class="ccm-opt__text">
                <span class="ccm-opt__label"><?php echo esc_html($item['label']); ?></span>
                <?php if ($risk !== 'test') : ?>
                    <span class="ccm-chip<?php echo $risk_class[$risk] ? ' ccm-chip--' . $risk_class[$risk] : ''; ?>">
                        <?php echo esc_html($risk_label[$risk]); ?>
                    </span>
                <?php endif; ?>
                <?php if ($blocked) : ?>
                    <span class="ccm-chip ccm-chip--warn"><?php _e('Needs critical CSS first', 'ccm-tools'); ?></span>
                <?php endif; ?>
                <p class="ccm-opt__desc"><?php echo esc_html($item['desc']); ?></p>
            </div>
            <label class="ccm-toggle">
                <input type="checkbox" id="<?php echo esc_attr($id); ?>"
                       <?php checked($on); ?> <?php disabled($blocked); ?>
                       data-perf-toggle="<?php echo esc_attr($key); ?>">
                <span class="ccm-toggle-slider"></span>
            </label>
        </div>
        <?php if (!empty($item['fields'])) : ?>
            <div class="ccm-opt__fields"<?php echo $on ? '' : ' hidden'; ?>>
                <?php foreach ($item['fields'] as $field) { ccm_tools_perf_render_field($field, $settings); } ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

/* 63e2bbda53bfa043 */
function ccm_tools_render_perf_page() {
    if (!ccm_tools_user_is_admin()) {
        wp_die(__('You do not have sufficient permissions to access this page.', 'ccm-tools'));
    }

    $settings  = ccm_tools_perf_get_settings();
    $catalogue = ccm_tools_perf_catalogue();
    $tally     = ccm_tools_perf_tally($settings);
    $active    = !empty($settings['enabled']);
    ?>
    <div class="wrap ccm-tools ccm-tools-perf">
        <?php
        if (function_exists('ccm_tools_render_header_nav')) {
            ccm_tools_render_header_nav('ccm-tools-perf');
        }
        ?>
        <div class="ccm-content">

            <div class="ccm-hero">
                <div class="ccm-hero__text">
                    <h1><?php _e('Performance', 'ccm-tools'); ?></h1>
                    <div class="ccm-hero__meta">
                        <span id="perf-count"><?php printf(
                            /* translators: 1: enabled count, 2: total count */
                            esc_html__('%1$d of %2$d on', 'ccm-tools'), $tally['on'], $tally['total']
                        ); ?></span>
                        <?php if ($tally['risky']) : ?>
                            <span><?php printf(
                                esc_html(_n('%d that can break things', '%d that can break things', $tally['risky'], 'ccm-tools')),
                                $tally['risky']
                            ); ?></span>
                        <?php endif; ?>
                        <span><?php _e('bypassed for administrators', 'ccm-tools'); ?></span>
                    </div>
                </div>
                <div class="ccm-hero__actions">
                    <span class="ccm-masterswitch<?php echo $active ? ' is-on' : ''; ?>" id="perf-master-wrap">
                        <span class="ccm-masterswitch__label">
                            <?php echo $active
                                ? esc_html__('Optimiser active', 'ccm-tools')
                                : esc_html__('Optimiser off', 'ccm-tools'); ?>
                        </span>
                        <label class="ccm-toggle">
                            <input type="checkbox" id="perf-master-enable" <?php checked($active); ?>>
                            <span class="ccm-toggle-slider"></span>
                        </label>
                    </span>
                    <button type="button" id="save-perf-settings" class="ccm-button ccm-button-primary ccm-savebar__proxy">
                        <?php _e('Save settings', 'ccm-tools'); ?>
                    </button>
                </div>
            </div>

            <?php if (!$active) : ?>
                <div class="ccm-alert ccm-alert--warn" style="margin-bottom: var(--ccm-space-lg);">
                    <span class="ccm-dot ccm-dot-warn"></span>
                    <div><strong><?php _e('The optimiser is switched off.', 'ccm-tools'); ?></strong>
                    <?php _e('Nothing below is being applied to the site, whatever it says. Turn on the master switch above.', 'ccm-tools'); ?></div>
                </div>
            <?php endif; ?>

            <div class="ccm-toolbar ccm-toolbar--sticky" id="perf-toolbar">
                <input type="search" id="perf-search" class="ccm-input" style="flex: 1 1 16rem; width: auto;"
                       placeholder="<?php esc_attr_e('Search settings…', 'ccm-tools'); ?>"
                       aria-label="<?php esc_attr_e('Search settings', 'ccm-tools'); ?>">
                <div class="ccm-seg" role="group" aria-label="<?php esc_attr_e('Filter', 'ccm-tools'); ?>">
                    <input type="radio" name="perf-filter" id="perf-f-all" value="all" checked>
                    <label for="perf-f-all"><?php _e('All', 'ccm-tools'); ?></label>
                    <input type="radio" name="perf-filter" id="perf-f-on" value="on">
                    <label for="perf-f-on"><?php _e('On', 'ccm-tools'); ?></label>
                    <input type="radio" name="perf-filter" id="perf-f-safe" value="safe">
                    <label for="perf-f-safe"><?php _e('Safe only', 'ccm-tools'); ?></label>
                    <input type="radio" name="perf-filter" id="perf-f-risky" value="risky">
                    <label for="perf-f-risky"><?php _e('Risky', 'ccm-tools'); ?></label>
                </div>
                <span class="ccm-toolbar__spacer"></span>
                <button type="button" id="perf-enable-safe" class="ccm-button ccm-button-secondary ccm-button-small">
                    <?php _e('Turn on everything safe', 'ccm-tools'); ?>
                </button>
                <button type="button" id="perf-disable-all" class="ccm-button ccm-button-secondary ccm-button-small">
                    <?php _e('Turn everything off', 'ccm-tools'); ?>
                </button>
                <span id="perf-save-status" class="ccm-text-muted" style="font-size: var(--ccm-text-xs);"></span>
            </div>

            <div id="perf-result"></div>

            <div id="perf-groups">
            <?php foreach ($catalogue as $slug => $group) :
                $group_on = 0;
                foreach ($group['items'] as $it) { if (!empty($settings[$it['key']])) { $group_on++; } }
                ?>
                <section class="ccm-optgroup" data-group="<?php echo esc_attr($slug); ?>">
                    <header class="ccm-optgroup__head">
                        <div>
                            <h2 class="ccm-optgroup__title"><?php echo esc_html($group['label']); ?></h2>
                            <p class="ccm-optgroup__note"><?php echo esc_html($group['blurb']); ?></p>
                        </div>
                        <span class="ccm-optgroup__count" data-group-count><?php echo esc_html(sprintf(
                            /* translators: 1: enabled, 2: total */
                            __('%1$d of %2$d on', 'ccm-tools'), $group_on, count($group['items'])
                        )); ?></span>
                    </header>
                    <div class="ccm-optgroup__body">
                        <?php foreach ($group['items'] as $item) { ccm_tools_perf_render_option($item, $settings); } ?>
                    </div>
                </section>
            <?php endforeach; ?>
            </div>

            <p class="ccm-empty ccm-hide" id="perf-noresults">
                <?php _e('Nothing matches that search.', 'ccm-tools'); ?>
            </p>

            <div class="ccm-section">
                <div>
                    <span class="ccm-section__eyebrow"><?php _e('Housekeeping', 'ccm-tools'); ?></span>
                    <h2><?php _e('Move settings between sites', 'ccm-tools'); ?></h2>
                    <p><?php _e('Configure one site the way you want it, then carry the same set to the next.', 'ccm-tools'); ?></p>
                </div>
                <div class="ccm-row">
                    <button type="button" id="export-perf-settings" class="ccm-button ccm-button-secondary ccm-button-small"><?php _e('Export', 'ccm-tools'); ?></button>
                    <?php
                    /* 409d3258c70a4d50 */
                    ?>
                    <button type="button" id="import-perf-settings-btn" class="ccm-button ccm-button-secondary ccm-button-small"><?php _e('Choose a file', 'ccm-tools'); ?></button>
                    <input type="file" id="import-perf-file" accept="application/json" class="ccm-hide" aria-label="<?php esc_attr_e('Choose a performance settings file to import', 'ccm-tools'); ?>">
                    <span id="import-file-name" class="ccm-text-muted" style="font-size: var(--ccm-text-sm);"></span>
                    <button type="button" id="import-perf-settings" class="ccm-button ccm-button-primary ccm-button-small" style="display: none;"><?php _e('Import', 'ccm-tools'); ?></button>
                </div>
            </div>

            <details class="ccm-disclose">
                <summary>
                    <?php _e('Testing the frontend as a visitor', 'ccm-tools'); ?>
                </summary>
                <div class="ccm-disclose__body">
                    <p class="ccm-text-muted" style="font-size: var(--ccm-text-sm); margin: 0 0 var(--ccm-space-sm);">
                        <?php _e('Optimisations are skipped for logged-in administrators, so a broken toggle is always recoverable from this page. To see the site the way a visitor does, add this to any frontend URL:', 'ccm-tools'); ?>
                    </p>
                    <p><code>?ccm_test_perf=1</code></p>
                    <div class="ccm-row" style="margin-top: var(--ccm-space-sm);">
                        <a class="ccm-button ccm-button-secondary ccm-button-small" target="_blank" rel="noopener"
                           href="<?php echo esc_url(add_query_arg('ccm_test_perf', '1', home_url('/'))); ?>">
                            <?php _e('Open the homepage as a visitor', 'ccm-tools'); ?>
                        </a>
                        <button type="button" id="detect-scripts-btn" class="ccm-button ccm-button-secondary ccm-button-small">
                            <?php _e('Find scripts to defer', 'ccm-tools'); ?>
                        </button>
                        <button type="button" id="detect-delay-scripts-btn" class="ccm-button ccm-button-secondary ccm-button-small">
                            <?php _e('Find third-party scripts to delay', 'ccm-tools'); ?>
                        </button>
                        <button type="button" id="detect-external-origins" class="ccm-button ccm-button-secondary ccm-button-small">
                            <?php _e('Find origins to preconnect', 'ccm-tools'); ?>
                        </button>
                        <button type="button" id="detect-dns-prefetch-origins" class="ccm-button ccm-button-secondary ccm-button-small">
                            <?php _e('Find origins to DNS-prefetch', 'ccm-tools'); ?>
                        </button>
                    </div>
                    <?php
                    /* ad4baa0cf17df039 */
                    ?>
                    <div id="detected-scripts-result" style="display: none; margin-top: var(--ccm-space-md);"></div>
                    <div id="detected-delay-scripts-result" style="display: none; margin-top: var(--ccm-space-md);"></div>
                    <div id="detected-origins-result" style="display: none; margin-top: var(--ccm-space-md);"></div>
                    <div id="detected-dns-origins-result" style="display: none; margin-top: var(--ccm-space-md);"></div>
                </div>
            </details>

        </div>

        <?php
        /* 5a15529ac9875866 */
        ?>
        <div class="ccm-savebar" data-ccm-savebar data-savebar-target="#save-perf-settings">
            <span class="ccm-savebar__dot" aria-hidden="true"></span>
            <span class="ccm-savebar__msg"><?php _e('No unsaved changes', 'ccm-tools'); ?></span>
            <button type="button" class="ccm-button ccm-button-secondary ccm-button-small" data-savebar-discard>
                <?php _e('Discard', 'ccm-tools'); ?>
            </button>
            <button type="button" class="ccm-button ccm-button-primary" data-savebar-save>
                <?php _e('Save settings', 'ccm-tools'); ?>
            </button>
        </div>
    </div>
    <?php
}
