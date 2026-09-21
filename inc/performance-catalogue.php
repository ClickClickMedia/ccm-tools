<?php
/**
 * Performance Optimizer — the setting catalogue.
 *
 * Every toggle on the Performance page is described here as data, and one
 * renderer draws them all. Before this, the page was eleven hundred lines of
 * hand-written markup: each group styled slightly differently, each risky
 * option warned about in its own words or not at all, and a new setting meant
 * copying forty lines of HTML and hoping you matched the last one.
 *
 * The element id for a setting is always `perf-` plus its key with underscores
 * turned into hyphens. That is not cosmetic: js/main.js reads every value by
 * that exact id, so the convention is the contract between the two files.
 *
 * Risk levels:
 *   safe   No effect on layout or behaviour. Fine anywhere without testing.
 *   test   Usually fine, but check the site afterwards.
 *   risky  Known to break real sites in specific, named ways.
 *
 * @package CCM_Tools
 * @since 8.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The element id for a setting key.
 *
 * @param string $key Setting key.
 * @return string
 */
function ccm_tools_perf_field_id(string $key): string {
    return 'perf-' . str_replace('_', '-', $key);
}

/**
 * Every performance setting, grouped.
 *
 * @return array
 */
function ccm_tools_perf_catalogue(): array {
    $woo = class_exists('WooCommerce');

    $groups = array(

        'head' => array(
            'label' => __('Head and request cleanup', 'ccm-tools'),
            'blurb' => __('Removes markup and requests WordPress adds by default. Nothing here changes how the page looks.', 'ccm-tools'),
            'items' => array(
                array('key' => 'remove_query_strings', 'risk' => 'safe',
                    'label' => __('Remove version query strings', 'ccm-tools'),
                    'desc'  => __('Drops ?ver= from static assets so a CDN can cache them properly.', 'ccm-tools')),
                array('key' => 'disable_emoji', 'risk' => 'safe',
                    'label' => __('Disable the emoji script', 'ccm-tools'),
                    'desc'  => __('About 10 KB. Native browser emoji still work exactly as before.', 'ccm-tools')),
                array('key' => 'disable_dashicons', 'risk' => 'safe',
                    'label' => __('Disable Dashicons on the frontend', 'ccm-tools'),
                    'desc'  => __('About 35 KB, for logged-out visitors only. Kept for anyone signed in.', 'ccm-tools')),
                array('key' => 'disable_rsd_wlw', 'risk' => 'safe',
                    'label' => __('Remove RSD and Windows Live Writer links', 'ccm-tools'),
                    'desc'  => __('Discovery links for software nobody has used in a decade.', 'ccm-tools')),
                array('key' => 'disable_shortlink', 'risk' => 'safe',
                    'label' => __('Remove the shortlink tag', 'ccm-tools'),
                    'desc'  => __('The ?p=123 link in the head. Shortlinks still resolve.', 'ccm-tools')),
                array('key' => 'disable_rest_api_links', 'risk' => 'safe',
                    'label' => __('Remove the REST API discovery link', 'ccm-tools'),
                    'desc'  => __('Only the link tag. The REST API itself is untouched.', 'ccm-tools')),
                array('key' => 'remove_generator_tag', 'risk' => 'safe',
                    'label' => __('Remove the generator tag', 'ccm-tools'),
                    'desc'  => __('Hides the WordPress version. Marginal as security, free to do.', 'ccm-tools')),
                array('key' => 'remove_adjacent_post_links', 'risk' => 'safe',
                    'label' => __('Remove previous and next post links', 'ccm-tools'),
                    'desc'  => __('Head-only rel links, not the ones in your template.', 'ccm-tools')),
                array('key' => 'disable_wp_embed', 'risk' => 'safe',
                    'label' => __('Remove wp-embed.js', 'ccm-tools'),
                    'desc'  => __('About 3 KB on every page. Only needed for embedding one WordPress post in another.', 'ccm-tools')),
                array('key' => 'disable_oembed', 'risk' => 'test',
                    'label' => __('Disable oEmbed discovery', 'ccm-tools'),
                    'desc'  => __('Other sites can no longer auto-embed your posts. Your own embeds keep working.', 'ccm-tools')),
                array('key' => 'disable_admin_bar', 'risk' => 'safe',
                    'label' => __('Hide the admin bar on the frontend', 'ccm-tools'),
                    'desc'  => __('For logged-in users browsing the site. Saves its CSS and JS.', 'ccm-tools')),
                array('key' => 'disable_jquery_migrate', 'risk' => 'test',
                    'label' => __('Remove jQuery Migrate', 'ccm-tools'),
                    'desc'  => __('Safe on a modern stack. An old theme or plugin using deprecated jQuery will break, usually visibly.', 'ccm-tools')),
                array('key' => 'disable_xmlrpc', 'risk' => 'risky',
                    'label' => __('Disable XML-RPC', 'ccm-tools'),
                    'desc'  => __('Stops pingback abuse, and also breaks the WordPress mobile app and Jetpack.', 'ccm-tools')),
                array('key' => 'disable_author_archives', 'risk' => 'test',
                    'label' => __('Disable author archive pages', 'ccm-tools'),
                    'desc'  => __('Redirects /author/name/ to the homepage. Stops user enumeration by slug and thin-content pages.', 'ccm-tools')),
            ),
        ),

        'js' => array(
            'label' => __('JavaScript', 'ccm-tools'),
            'blurb' => __('Render-blocking scripts are usually the single biggest cost on a WordPress page. They are also the easiest thing to break, so work down this list rather than switching it all on.', 'ccm-tools'),
            'items' => array(
                array('key' => 'defer_js', 'risk' => 'test',
                    'label' => __('Defer JavaScript', 'ccm-tools'),
                    'desc'  => __('Lets the page render while scripts load. jQuery, wp-i18n, wp-a11y, wp-hooks and wp-polyfill are always excluded, as is any script with an inline companion.', 'ccm-tools'),
                    'fields' => array(
                        array('key' => 'defer_js_excludes', 'type' => 'list',
                            'label' => __('Also never defer scripts matching', 'ccm-tools'),
                            'hint'  => __('Comma separated. Matched against the script URL.', 'ccm-tools'),
                            'placeholder' => 'slider, elementor-frontend'),
                    )),
                array('key' => 'delay_js', 'risk' => 'risky',
                    'label' => __('Delay all JavaScript until interaction', 'ccm-tools'),
                    'desc'  => __('Holds every script until the first scroll, click or touch. The biggest score win available and the most likely thing on this page to break a slider, a carousel or a sticky header.', 'ccm-tools'),
                    'fields' => array(
                        array('key' => 'delay_js_timeout', 'type' => 'number',
                            'label' => __('Give up and load anyway after', 'ccm-tools'),
                            'hint'  => __('Seconds. 0 waits for a real interaction.', 'ccm-tools'),
                            'min' => 0, 'max' => 30, 'suffix' => __('sec', 'ccm-tools')),
                        array('key' => 'delay_js_excludes', 'type' => 'list',
                            'label' => __('Never delay scripts matching', 'ccm-tools'),
                            'hint'  => __('Start here when something breaks.', 'ccm-tools')),
                    )),
                array('key' => 'delay_third_party', 'risk' => 'test',
                    'label' => __('Delay third-party scripts only', 'ccm-tools'),
                    'desc'  => __('The safer half of the option above: tag managers, analytics, chat widgets and pixels wait for interaction, your own scripts do not.', 'ccm-tools'),
                    'fields' => array(
                        array('key' => 'delay_third_party_domains', 'type' => 'list',
                            'label' => __('Domains to delay', 'ccm-tools'),
                            'hint'  => __('One per line. Leave empty for the built-in list of common tag and analytics hosts.', 'ccm-tools')),
                    )),
                array('key' => 'inline_small_scripts', 'risk' => 'test',
                    'label' => __('Inline small scripts', 'ccm-tools'),
                    'desc'  => __('Saves a request per file. Scripts carrying inline configuration or translations are left alone, because inlining them would throw that away.', 'ccm-tools'),
                    'fields' => array(
                        array('key' => 'inline_threshold_kb', 'type' => 'number',
                            'label' => __('Only files smaller than', 'ccm-tools'),
                            'min' => 1, 'max' => 50, 'suffix' => 'KB'),
                    )),
            ),
        ),

        'css' => array(
            'label' => __('CSS', 'ccm-tools'),
            'blurb' => __('Stylesheets block the first paint. Critical CSS is the real fix and the one that needs actual work; the rest are cheap wins.', 'ccm-tools'),
            'items' => array(
                array('key' => 'critical_css', 'risk' => 'test',
                    'label' => __('Critical CSS', 'ccm-tools'),
                    'desc'  => __('Inlines the above-the-fold CSS you paste in and defers the rest. Without real CSS below this does nothing useful.', 'ccm-tools'),
                    'fields' => array(
                        array('key' => 'critical_css_code', 'type' => 'textarea',
                            'label' => __('Above-the-fold CSS', 'ccm-tools'),
                            'hint'  => __('Generate it with a tool against your actual homepage. Paste the CSS only, no style tags.', 'ccm-tools'),
                            'rows' => 8, 'mono' => true),
                    )),
                array('key' => 'preload_css', 'risk' => 'risky', 'requires' => 'critical_css_code',
                    'label' => __('Defer non-critical CSS', 'ccm-tools'),
                    'desc'  => __('Loads stylesheets as print then swaps them in. Only possible once Critical CSS above holds real content, otherwise the page renders unstyled for a moment.', 'ccm-tools'),
                    'fields' => array(
                        array('key' => 'preload_css_excludes', 'type' => 'list',
                            'label' => __('Never defer stylesheets matching', 'ccm-tools')),
                    )),
                array('key' => 'inline_small_styles', 'risk' => 'test',
                    'label' => __('Inline small stylesheets', 'ccm-tools'),
                    'desc'  => __('Same threshold as small scripts above.', 'ccm-tools')),
                array('key' => 'disable_block_css', 'risk' => 'test',
                    'label' => __('Remove block editor CSS', 'ccm-tools'),
                    'desc'  => __('Skipped automatically on block themes and on pages that actually contain blocks, because it would strip their styling.', 'ccm-tools')),
                array('key' => 'disable_gutenberg_frontend', 'risk' => 'test',
                    'label' => __('Remove all Gutenberg frontend assets', 'ccm-tools'),
                    'desc'  => __('Wider than the option above. Also skipped on block themes.', 'ccm-tools')),
            ),
        ),

        'images' => array(
            'label' => __('Images and media', 'ccm-tools'),
            'blurb' => __('Largest Contentful Paint is an image on most sites, and layout shift is almost always an image without dimensions.', 'ccm-tools'),
            'items' => array(
                array('key' => 'lazy_load_images', 'risk' => 'safe',
                    'label' => __('Lazy load images', 'ccm-tools'),
                    'desc'  => __('Offscreen images wait until they are needed. The LCP candidate is excluded so this cannot slow your hero down.', 'ccm-tools')),
                array('key' => 'image_decoding_async', 'risk' => 'safe',
                    'label' => __('Decode images off the main thread', 'ccm-tools'),
                    'desc'  => __('Adds decoding="async". No visual change.', 'ccm-tools')),
                array('key' => 'inject_image_dimensions', 'risk' => 'safe',
                    'label' => __('Add missing width and height', 'ccm-tools'),
                    'desc'  => __('The usual fix for layout shift. Reserves the space before the image arrives.', 'ccm-tools')),
                array('key' => 'inject_srcset', 'risk' => 'test',
                    'label' => __('Add missing responsive srcset', 'ccm-tools'),
                    'desc'  => __('Lets the browser pick a smaller file on a phone, where WordPress did not already offer one.', 'ccm-tools')),
                array('key' => 'lcp_fetchpriority', 'risk' => 'safe',
                    'label' => __('Prioritise the first in-content image', 'ccm-tools'),
                    'desc'  => __('Marks it fetchpriority="high". The site logo is deliberately skipped, since it is almost never the LCP element.', 'ccm-tools')),
                array('key' => 'lcp_preload', 'risk' => 'test',
                    'label' => __('Preload a specific LCP image', 'ccm-tools'),
                    'desc'  => __('When you know exactly which image it is. Preloading the wrong one makes things worse.', 'ccm-tools'),
                    'fields' => array(
                        array('key' => 'lcp_preload_url', 'type' => 'text',
                            'label' => __('Image URL', 'ccm-tools'), 'placeholder' => 'https://…/hero.jpg'),
                    )),
                array('key' => 'preload_css_bg_image', 'risk' => 'test',
                    'label' => __('Preload a CSS background image', 'ccm-tools'),
                    'desc'  => __('For a hero set in CSS rather than an img tag, which the browser discovers late.', 'ccm-tools'),
                    'fields' => array(
                        array('key' => 'preload_css_bg_url', 'type' => 'text',
                            'label' => __('Image URL', 'ccm-tools'), 'placeholder' => 'https://…/hero-bg.jpg'),
                    )),
                array('key' => 'priority_hints_above_fold', 'risk' => 'test',
                    'label' => __('Priority hints for above-the-fold images', 'ccm-tools'),
                    'desc'  => __('Raises priority and removes lazy loading for images matching your selectors.', 'ccm-tools'),
                    'fields' => array(
                        array('key' => 'priority_hints_selectors', 'type' => 'text',
                            'label' => __('CSS selectors', 'ccm-tools'), 'placeholder' => '.hero img, .banner img'),
                    )),
                array('key' => 'lazy_load_iframes', 'risk' => 'safe',
                    'label' => __('Lazy load iframes', 'ccm-tools'),
                    'desc'  => __('Maps, embeds and anything else in an iframe.', 'ccm-tools')),
                array('key' => 'youtube_facade', 'risk' => 'test',
                    'label' => __('Replace YouTube embeds with a thumbnail', 'ccm-tools'),
                    'desc'  => __('The player loads on click. A YouTube embed is well over a megabyte before anyone presses play.', 'ccm-tools')),
                array('key' => 'video_lazy_load', 'risk' => 'safe',
                    'label' => __('Lazy load video elements', 'ccm-tools'),
                    'desc'  => __('Self-hosted video waits until it is scrolled to.', 'ccm-tools')),
                array('key' => 'video_preload_none', 'risk' => 'safe',
                    'label' => __('Do not preload video', 'ccm-tools'),
                    'desc'  => __('Nothing downloads until the visitor presses play.', 'ccm-tools')),
            ),
        ),

        'fonts' => array(
            'label' => __('Fonts', 'ccm-tools'),
            'blurb' => __('Webfonts either block text from painting or make it jump when they arrive. Pick your poison deliberately.', 'ccm-tools'),
            'items' => array(
                array('key' => 'font_display_swap', 'risk' => 'safe',
                    'label' => __('Show fallback text while fonts load', 'ccm-tools'),
                    'desc'  => __('Adds font-display: swap. Text appears immediately in a system font and reflows once. Fixes the PageSpeed webfont audit.', 'ccm-tools')),
                array('key' => 'self_host_google_fonts', 'risk' => 'test',
                    'label' => __('Self-host Google Fonts', 'ccm-tools'),
                    'desc'  => __('Downloads the CSS and font files to this server. Removes a third-party connection and helps with GDPR. Check your headings after enabling.', 'ccm-tools')),
            ),
        ),

        'hints' => array(
            'label' => __('Network hints', 'ccm-tools'),
            'blurb' => __('Tells the browser about work it will need to do, slightly before it works that out for itself.', 'ccm-tools'),
            'items' => array(
                array('key' => 'preconnect', 'risk' => 'safe',
                    'label' => __('Preconnect to known third parties', 'ccm-tools'),
                    'desc'  => __('Opens the connection to fonts, analytics and CDN hosts early.', 'ccm-tools'),
                    'fields' => array(
                        array('key' => 'preconnect_urls', 'type' => 'list',
                            'label' => __('Extra origins', 'ccm-tools'), 'hint' => __('One per line.', 'ccm-tools')),
                    )),
                array('key' => 'dns_prefetch', 'risk' => 'safe',
                    'label' => __('DNS prefetch', 'ccm-tools'),
                    'desc'  => __('Cheaper than preconnect and useful for hosts you might not end up using.', 'ccm-tools'),
                    'fields' => array(
                        array('key' => 'dns_prefetch_urls', 'type' => 'list',
                            'label' => __('Hosts', 'ccm-tools'), 'hint' => __('One per line.', 'ccm-tools')),
                    )),
                array('key' => 'preload_key_requests', 'risk' => 'test',
                    'label' => __('Preload specific files', 'ccm-tools'),
                    'desc'  => __('For a font or stylesheet the browser finds late. Preloading something that is not needed immediately wastes bandwidth.', 'ccm-tools'),
                    'fields' => array(
                        array('key' => 'preload_key_urls', 'type' => 'list',
                            'label' => __('File URLs', 'ccm-tools'), 'hint' => __('One per line. The type is worked out from the extension.', 'ccm-tools')),
                    )),
                array('key' => 'prefetch_on_hover', 'risk' => 'test',
                    'label' => __('Prefetch links on hover', 'ccm-tools'),
                    'desc'  => __('Starts fetching the next page the moment the pointer lands on a link. Costs bandwidth on links nobody clicks.', 'ccm-tools')),
                array('key' => 'speculation_rules', 'risk' => 'test',
                    'label' => __('Speculation Rules prerendering', 'ccm-tools'),
                    'desc'  => __('Chrome prerenders likely next pages. Cart, checkout, account and anything with a query string are excluded.', 'ccm-tools'),
                    'fields' => array(
                        array('key' => 'speculation_eagerness', 'type' => 'select',
                            'label' => __('How eager', 'ccm-tools'),
                            'options' => array(
                                'conservative' => __('Conservative, on click', 'ccm-tools'),
                                'moderate'     => __('Moderate, on hover', 'ccm-tools'),
                                'eager'        => __('Eager, as soon as it can', 'ccm-tools'),
                            )),
                    )),
            ),
        ),

        'output' => array(
            'label' => __('Output and caching', 'ccm-tools'),
            'blurb' => __('What leaves the server and how long anything downstream may keep it.', 'ccm-tools'),
            'items' => array(
                array('key' => 'minify_html', 'risk' => 'test',
                    'label' => __('Minify HTML', 'ccm-tools'),
                    'desc'  => __('Strips comments and whitespace between tags. pre, textarea, script and style blocks are preserved.', 'ccm-tools')),
                array('key' => 'cache_control_meta', 'risk' => 'test',
                    'label' => __('Send Cache-Control headers', 'ccm-tools'),
                    'desc'  => __('For logged-out visitors only. Skipped whenever a cookie is being set, so a cart page is never cached.', 'ccm-tools')),
                array('key' => 'stale_while_revalidate', 'risk' => 'test',
                    'label' => __('Allow stale-while-revalidate', 'ccm-tools'),
                    'desc'  => __('Serves the cached copy instantly while fetching a fresh one behind it. Needs the option above.', 'ccm-tools')),
            ),
        ),

        'wordpress' => array(
            'label' => __('WordPress internals', 'ccm-tools'),
            'blurb' => __('Background work WordPress does on every request whether you need it or not.', 'ccm-tools'),
            'items' => array(
                array('key' => 'reduce_heartbeat', 'risk' => 'safe',
                    'label' => __('Slow the Heartbeat API down', 'ccm-tools'),
                    'desc'  => __('The admin polls the server every fifteen seconds by default. On a busy editor that is a lot of PHP for very little.', 'ccm-tools'),
                    'fields' => array(
                        array('key' => 'heartbeat_interval', 'type' => 'number',
                            'label' => __('Poll every', 'ccm-tools'), 'min' => 15, 'max' => 300, 'suffix' => __('sec', 'ccm-tools')),
                    )),
                array('key' => 'disable_wp_cron', 'risk' => 'risky',
                    'label' => __('Throttle WP-Cron', 'ccm-tools'),
                    'desc'  => __('Stops cron running on every page load. Only do this if a real server cron is calling wp-cron.php, otherwise scheduled jobs simply stop.', 'ccm-tools'),
                    'fields' => array(
                        array('key' => 'cron_interval', 'type' => 'number',
                            'label' => __('At most once every', 'ccm-tools'), 'min' => 1, 'max' => 1440, 'suffix' => __('min', 'ccm-tools')),
                    )),
            ),
        ),
    );

    if ($woo) {
        $groups['woocommerce'] = array(
            'label' => __('WooCommerce', 'ccm-tools'),
            'blurb' => __('WooCommerce loads its whole frontend on every page by default, including pages that sell nothing.', 'ccm-tools'),
            'items' => array(
                array('key' => 'woo_scripts_shop_only', 'risk' => 'test',
                    'label' => __('Load WooCommerce assets on shop pages only', 'ccm-tools'),
                    'desc'  => __('Pages containing product blocks, WooCommerce shortcodes or a cart widget are detected and left alone.', 'ccm-tools')),
                array('key' => 'disable_woocommerce_cart_fragments', 'risk' => 'risky',
                    'label' => __('Disable cart fragments', 'ccm-tools'),
                    'desc'  => __('Removes an AJAX request from every page load. A mini-cart that updates without a refresh will stop doing so.', 'ccm-tools')),
            ),
        );
    }

    return $groups;
}

/**
 * Flat list of every setting key that is a plain on/off toggle.
 *
 * @return array
 */
function ccm_tools_perf_toggle_keys(): array {
    $keys = array();
    foreach (ccm_tools_perf_catalogue() as $group) {
        foreach ($group['items'] as $item) {
            $keys[] = $item['key'];
        }
    }
    return $keys;
}

/**
 * Count how many toggles are on.
 *
 * @param array $settings Current settings.
 * @return array{on:int,total:int,risky:int}
 */
function ccm_tools_perf_tally(array $settings): array {
    $on = 0; $total = 0; $risky = 0;
    foreach (ccm_tools_perf_catalogue() as $group) {
        foreach ($group['items'] as $item) {
            $total++;
            if (!empty($settings[$item['key']])) {
                $on++;
                if (($item['risk'] ?? '') === 'risky') { $risky++; }
            }
        }
    }
    return array('on' => $on, 'total' => $total, 'risky' => $risky);
}
