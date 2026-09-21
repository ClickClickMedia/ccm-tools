<?php
/**
 * Site Health — Google PageSpeed Insights
 *
 * Replaces the removed AI Performance Hub. This module measures and reports;
 * it NEVER changes a setting on its own. Every recommendation is a link to the
 * relevant toggle so a human decides. That is the whole point: the old AI
 * optimiser applied its own guesses to live sites and broke them.
 *
 * Talks straight to Google's PageSpeed Insights API v5 with the site's own API
 * key. There is no CCM hub in the middle any more.
 *
 * @package CCM_Tools
 * @since 8.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/** PSI endpoint. */
if (!defined('CCM_TOOLS_PSI_ENDPOINT')) {
    define('CCM_TOOLS_PSI_ENDPOINT', 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed');
}

/** How many past runs to keep per strategy. */
if (!defined('CCM_TOOLS_SH_HISTORY_MAX')) {
    define('CCM_TOOLS_SH_HISTORY_MAX', 20);
}

// ─── Settings ───────────────────────────────────────────────────

/**
 * Site Health settings.
 *
 * @return array
 */
function ccm_tools_sh_get_settings(): array {
    $defaults = array(
        'api_key'    => '',
        'last_url'   => '',
    );
    $stored = get_option('ccm_tools_site_health', array());
    if (!is_array($stored)) {
        $stored = array();
    }
    return wp_parse_args($stored, $defaults);
}

/**
 * Persist Site Health settings, whitelisted.
 *
 * @param array $settings Raw settings.
 * @return bool
 */
function ccm_tools_sh_save_settings(array $settings): bool {
    $current = ccm_tools_sh_get_settings();
    $clean   = array(
        'api_key'  => isset($settings['api_key']) ? trim(sanitize_text_field($settings['api_key'])) : $current['api_key'],
        'last_url' => isset($settings['last_url']) ? esc_url_raw($settings['last_url']) : $current['last_url'],
    );
    return update_option('ccm_tools_site_health', $clean);
}

/**
 * Whether an API key is configured.
 *
 * A constant in wp-config.php wins, so a key can be kept out of the database.
 *
 * @return string
 */
function ccm_tools_sh_api_key(): string {
    if (defined('CCM_TOOLS_PSI_KEY') && CCM_TOOLS_PSI_KEY) {
        return (string) CCM_TOOLS_PSI_KEY;
    }
    $settings = ccm_tools_sh_get_settings();
    return (string) $settings['api_key'];
}

/**
 * Whether the key came from wp-config.php rather than the database.
 *
 * @return bool
 */
function ccm_tools_sh_key_is_constant(): bool {
    return defined('CCM_TOOLS_PSI_KEY') && CCM_TOOLS_PSI_KEY;
}

// ─── URL handling ───────────────────────────────────────────────

/**
 * Confine a URL to this site.
 *
 * PSI will happily test any public URL. Restricting it to this install stops
 * the plugin being used to run scans against third parties on Google's dime,
 * and stops a tampered request pointing the report somewhere misleading.
 *
 * @param string $url Candidate URL.
 * @return string|WP_Error Normalised URL, or an error.
 */
function ccm_tools_sh_validate_url(string $url) {
    $url = trim($url);
    if ($url === '') {
        $url = home_url('/');
    }

    $parts = wp_parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) {
        return new WP_Error('ccm_sh_bad_url', __('That does not look like a valid URL.', 'ccm-tools'));
    }
    if (!isset($parts['scheme']) || !in_array(strtolower($parts['scheme']), array('http', 'https'), true)) {
        return new WP_Error('ccm_sh_bad_scheme', __('Only http and https URLs can be tested.', 'ccm-tools'));
    }

    $home = wp_parse_url(home_url('/'));
    $site_host = isset($home['host']) ? strtolower($home['host']) : '';
    $url_host  = strtolower($parts['host']);

    // Treat www and bare host as the same site.
    $strip = function ($h) {
        return preg_replace('/^www\./i', '', $h);
    };
    if ($strip($url_host) !== $strip($site_host)) {
        return new WP_Error(
            'ccm_sh_offsite',
            sprintf(
                /* translators: 1: requested host, 2: this site's host */
                __('Only pages on this site can be tested. You asked for %1$s but this site is %2$s.', 'ccm-tools'),
                $url_host,
                $site_host
            )
        );
    }

    return esc_url_raw($url);
}

// ─── The API call ───────────────────────────────────────────────

/**
 * Run PageSpeed Insights against one URL and strategy.
 *
 * @param string $url      URL on this site.
 * @param string $strategy 'mobile' or 'desktop'.
 * @return array|WP_Error Extracted report, or an error.
 */
function ccm_tools_sh_run(string $url, string $strategy) {
    $strategy = ($strategy === 'desktop') ? 'desktop' : 'mobile';

    $url = ccm_tools_sh_validate_url($url);
    if (is_wp_error($url)) {
        return $url;
    }

    $key = ccm_tools_sh_api_key();
    if ($key === '') {
        return new WP_Error(
            'ccm_sh_no_key',
            __('No PageSpeed Insights API key is configured. Add one on this page first.', 'ccm-tools')
        );
    }

    // Categories must be repeated as separate query parameters. Building them
    // with http_build_query on an array yields category[0]=..., which Google
    // silently ignores, and only the performance category comes back.
    $categories = array('PERFORMANCE', 'ACCESSIBILITY', 'BEST_PRACTICES', 'SEO');
    $query = 'url=' . rawurlencode($url)
        . '&strategy=' . rawurlencode($strategy)
        . '&key=' . rawurlencode($key);
    foreach ($categories as $category) {
        $query .= '&category=' . rawurlencode($category);
    }

    $response = wp_remote_get(
        CCM_TOOLS_PSI_ENDPOINT . '?' . $query,
        array(
            'timeout' => 60,
            'headers' => array('Accept' => 'application/json'),
        )
    );

    if (is_wp_error($response)) {
        return new WP_Error(
            'ccm_sh_request_failed',
            sprintf(
                /* translators: %s: error text */
                __('Could not reach the PageSpeed Insights API: %s', 'ccm-tools'),
                $response->get_error_message()
            )
        );
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);

    if ($code !== 200 || !is_array($body)) {
        $detail = '';
        if (is_array($body) && isset($body['error']['message'])) {
            $detail = (string) $body['error']['message'];
        }
        if ($code === 400 && stripos($detail, 'API key not valid') !== false) {
            return new WP_Error('ccm_sh_bad_key', __('Google rejected the API key. Check it is correct and that the PageSpeed Insights API is enabled for it.', 'ccm-tools'));
        }
        if ($code === 429) {
            return new WP_Error('ccm_sh_rate_limited', __('Google is rate limiting this key. Wait a minute and try again.', 'ccm-tools'));
        }
        return new WP_Error(
            'ccm_sh_api_error',
            sprintf(
                /* translators: 1: HTTP status, 2: error detail */
                __('PageSpeed Insights returned HTTP %1$d. %2$s', 'ccm-tools'),
                $code,
                $detail
            )
        );
    }

    return ccm_tools_sh_extract($body, $url, $strategy);
}

/**
 * Pull the parts we display out of a PSI response.
 *
 * Deliberately schema-agnostic about audit ids. Lighthouse 13 removed the
 * legacy opportunity audits (render-blocking-resources, unused-javascript and
 * friends) in favour of "*-insight" audits carrying metricSavings. Keying off
 * fixed ids is exactly why the old integration stopped returning anything, so
 * this walks every audit and keeps whatever is failing and quantified.
 *
 * @param array  $body     Decoded PSI response.
 * @param string $url      URL tested.
 * @param string $strategy Strategy used.
 * @return array
 */
function ccm_tools_sh_extract(array $body, string $url, string $strategy): array {
    $lh = isset($body['lighthouseResult']) && is_array($body['lighthouseResult'])
        ? $body['lighthouseResult']
        : array();

    $out = array(
        'url'        => $url,
        'strategy'   => $strategy,
        'fetched_at' => time(),
        'lh_version' => isset($lh['lighthouseVersion']) ? (string) $lh['lighthouseVersion'] : '',
        'scores'     => array(),
        'metrics'    => array(),
        'findings'   => array(),
        'field_data' => array(),
    );

    // Category scores, 0-100.
    if (!empty($lh['categories']) && is_array($lh['categories'])) {
        foreach ($lh['categories'] as $id => $cat) {
            if (isset($cat['score']) && $cat['score'] !== null) {
                $out['scores'][$id] = (int) round(((float) $cat['score']) * 100);
            }
        }
    }

    $audits = (!empty($lh['audits']) && is_array($lh['audits'])) ? $lh['audits'] : array();

    // Lab metrics. displayValue is already localised by Google.
    $metric_ids = array(
        'first-contentful-paint'   => __('First Contentful Paint', 'ccm-tools'),
        'largest-contentful-paint' => __('Largest Contentful Paint', 'ccm-tools'),
        'total-blocking-time'      => __('Total Blocking Time', 'ccm-tools'),
        'cumulative-layout-shift'  => __('Cumulative Layout Shift', 'ccm-tools'),
        'speed-index'              => __('Speed Index', 'ccm-tools'),
        'server-response-time'     => __('Server Response Time', 'ccm-tools'),
    );
    foreach ($metric_ids as $id => $label) {
        if (!isset($audits[$id])) {
            continue;
        }
        $out['metrics'][$id] = array(
            'label'   => $label,
            'display' => isset($audits[$id]['displayValue']) ? (string) $audits[$id]['displayValue'] : '',
            'score'   => isset($audits[$id]['score']) && $audits[$id]['score'] !== null
                ? (float) $audits[$id]['score']
                : null,
        );
    }

    // Real-user data from the Chrome UX Report, when Google has enough traffic
    // for this origin. Far more trustworthy than a single lab run.
    if (!empty($body['loadingExperience']['metrics']) && is_array($body['loadingExperience']['metrics'])) {
        foreach ($body['loadingExperience']['metrics'] as $id => $metric) {
            if (!isset($metric['percentile'])) {
                continue;
            }
            $out['field_data'][$id] = array(
                'percentile' => (int) $metric['percentile'],
                'category'   => isset($metric['category']) ? (string) $metric['category'] : '',
            );
        }
    }

    // Failing audits worth acting on, ranked by how much they cost.
    foreach ($audits as $id => $audit) {
        if (!is_array($audit)) {
            continue;
        }
        $score = isset($audit['score']) ? $audit['score'] : null;
        if ($score === null || (float) $score >= 0.9) {
            continue; // Passing, or informational with no score.
        }
        if (isset($audit['scoreDisplayMode']) && in_array($audit['scoreDisplayMode'], array('notApplicable', 'manual', 'informative'), true)) {
            // Informative audits carry no pass/fail, so only keep them when
            // they quantify a saving.
            if (empty($audit['metricSavings']) && empty($audit['details']['overallSavingsMs'])) {
                continue;
            }
        }

        $savings_ms = 0;
        if (!empty($audit['details']['overallSavingsMs'])) {
            $savings_ms = (int) $audit['details']['overallSavingsMs'];
        }
        if (!empty($audit['metricSavings']) && is_array($audit['metricSavings'])) {
            foreach ($audit['metricSavings'] as $saving) {
                if (is_numeric($saving)) {
                    $savings_ms = max($savings_ms, (int) $saving);
                }
            }
        }
        $savings_bytes = !empty($audit['details']['overallSavingsBytes'])
            ? (int) $audit['details']['overallSavingsBytes']
            : 0;

        $out['findings'][] = array(
            'id'            => (string) $id,
            'title'         => isset($audit['title']) ? (string) $audit['title'] : (string) $id,
            'display'       => isset($audit['displayValue']) ? (string) $audit['displayValue'] : '',
            'savings_ms'    => $savings_ms,
            'savings_bytes' => $savings_bytes,
            'score'         => (float) $score,
            'suggestion'    => ccm_tools_sh_suggest_for_audit((string) $id),
        );
    }

    // Biggest wins first, then anything unquantified by how badly it scored.
    usort($out['findings'], function ($a, $b) {
        if ($a['savings_ms'] !== $b['savings_ms']) {
            return $b['savings_ms'] <=> $a['savings_ms'];
        }
        if ($a['savings_bytes'] !== $b['savings_bytes']) {
            return $b['savings_bytes'] <=> $a['savings_bytes'];
        }
        return $a['score'] <=> $b['score'];
    });

    return $out;
}

// ─── Mapping audits to this plugin's own controls ───────────────

/**
 * Which CCM Tools control, if any, addresses a given Lighthouse audit.
 *
 * Both the legacy audit ids and the Lighthouse 13 "-insight" ids are listed,
 * because a given site can be served either depending on Google's rollout.
 * Nothing here is ever applied automatically; it only produces a link.
 *
 * @param string $audit_id Lighthouse audit id.
 * @return array{page:string,label:string,note:string}|null
 */
function ccm_tools_sh_suggest_for_audit(string $audit_id) {
    $map = array(
        // Render blocking.
        'render-blocking-resources' => array('perf', __('Defer JavaScript', 'ccm-tools'), __('Also consider Critical CSS, which needs the above-the-fold CSS pasted in.', 'ccm-tools')),
        'render-blocking-insight'   => array('perf', __('Defer JavaScript', 'ccm-tools'), __('Also consider Critical CSS, which needs the above-the-fold CSS pasted in.', 'ccm-tools')),

        // JavaScript weight.
        'unused-javascript'      => array('perf', __('Delay third-party scripts', 'ccm-tools'), __('Delaying all JavaScript is the heavier hammer and breaks sliders. Try third-party only first.', 'ccm-tools')),
        'legacy-javascript'      => array('perf', __('Delay third-party scripts', 'ccm-tools'), __('Usually comes from a plugin or a tag manager container.', 'ccm-tools')),
        'duplicated-javascript'  => array('perf', __('Delay third-party scripts', 'ccm-tools'), __('Two plugins bundling the same library. Worth finding the culprit.', 'ccm-tools')),
        'third-party-summary'    => array('perf', __('Delay third-party scripts', 'ccm-tools'), ''),
        'third-parties-insight'  => array('perf', __('Delay third-party scripts', 'ccm-tools'), ''),

        // CSS.
        'unused-css-rules'  => array('perf', __('Critical CSS', 'ccm-tools'), __('Only with real above-the-fold CSS pasted in, or the page flashes unstyled.', 'ccm-tools')),
        'unminified-css'    => array('perf', __('Minify HTML output', 'ccm-tools'), __('CSS minification belongs to the theme or its build step.', 'ccm-tools')),

        // Images.
        'modern-image-formats'   => array('webp', __('WebP Converter', 'ccm-tools'), __('Bulk convert, then turn on serving WebP.', 'ccm-tools')),
        'uses-webp-images'       => array('webp', __('WebP Converter', 'ccm-tools'), __('Bulk convert, then turn on serving WebP.', 'ccm-tools')),
        'uses-optimized-images'  => array('webp', __('WebP Converter', 'ccm-tools'), __('Lower the quality setting if the saving is large.', 'ccm-tools')),
        'offscreen-images'       => array('perf', __('Lazy load images', 'ccm-tools'), ''),
        'unsized-images'         => array('perf', __('Inject width and height', 'ccm-tools'), __('This is the usual cause of layout shift.', 'ccm-tools')),
        'uses-responsive-images' => array('perf', __('Inject responsive srcset', 'ccm-tools'), ''),
        'image-delivery-insight' => array('webp', __('WebP Converter', 'ccm-tools'), ''),
        'cls-culprits-insight'   => array('perf', __('Inject width and height', 'ccm-tools'), __('This is the usual cause of layout shift.', 'ccm-tools')),

        // LCP.
        'prioritize-lcp-image'  => array('perf', __('Auto fetchpriority on the LCP image', 'ccm-tools'), ''),
        'lcp-lazy-loaded'       => array('perf', __('Auto fetchpriority on the LCP image', 'ccm-tools'), __('The hero image is being lazy loaded, which delays it.', 'ccm-tools')),
        'lcp-discovery-insight' => array('perf', __('Auto fetchpriority on the LCP image', 'ccm-tools'), ''),

        // Fonts.
        'font-display'         => array('perf', __('Font display: swap', 'ccm-tools'), ''),
        'font-display-insight' => array('perf', __('Font display: swap', 'ccm-tools'), ''),

        // Server and caching.
        'server-response-time'      => array('redis', __('Redis Object Cache', 'ccm-tools'), __('A slow first byte is usually the database, not the front end.', 'ccm-tools')),
        'document-latency-insight'  => array('redis', __('Redis Object Cache', 'ccm-tools'), __('A slow first byte is usually the database, not the front end.', 'ccm-tools')),
        'uses-long-cache-ttl'       => array('htaccess', __('Browser caching', 'ccm-tools'), ''),
        'cache-insight'             => array('htaccess', __('Browser caching', 'ccm-tools'), ''),
        'uses-text-compression'     => array('htaccess', __('Brotli and Gzip compression', 'ccm-tools'), ''),

        // Head bloat.
        'dom-size'         => array('perf', __('Head cleanup', 'ccm-tools'), __('A big DOM is usually the page builder. No toggle fixes it; the template needs simplifying.', 'ccm-tools')),
        'dom-size-insight' => array('perf', __('Head cleanup', 'ccm-tools'), __('A big DOM is usually the page builder. No toggle fixes it; the template needs simplifying.', 'ccm-tools')),
        'redirects'        => array('htaccess', __('HTTPS redirect', 'ccm-tools'), __('Check you are not chaining more than one redirect.', 'ccm-tools')),
    );

    if (!isset($map[$audit_id])) {
        return null;
    }

    $pages = array(
        'perf'     => 'ccm-tools-perf',
        'webp'     => 'ccm-tools-webp',
        'redis'    => 'ccm-tools-redis',
        'htaccess' => 'ccm-tools-htaccess',
    );

    list($page_key, $label, $note) = $map[$audit_id];

    return array(
        'page'  => isset($pages[$page_key]) ? $pages[$page_key] : 'ccm-tools-perf',
        'label' => $label,
        'note'  => $note,
    );
}

// ─── History ────────────────────────────────────────────────────

/**
 * Append a run to the stored history and return the trimmed list.
 *
 * Only the scores and a timestamp are kept, never the whole payload, so the
 * option cannot grow without bound.
 *
 * @param array $report Extracted report.
 * @return void
 */
function ccm_tools_sh_record(array $report): void {
    $history = get_option('ccm_tools_site_health_history', array());
    if (!is_array($history)) {
        $history = array();
    }

    $history[] = array(
        'at'       => (int) $report['fetched_at'],
        'strategy' => (string) $report['strategy'],
        'url'      => (string) $report['url'],
        'scores'   => isset($report['scores']) ? array_map('intval', $report['scores']) : array(),
    );

    // Keep the newest N per strategy.
    $by_strategy = array('mobile' => array(), 'desktop' => array());
    foreach ($history as $row) {
        $key = (isset($row['strategy']) && $row['strategy'] === 'desktop') ? 'desktop' : 'mobile';
        $by_strategy[$key][] = $row;
    }
    $trimmed = array();
    foreach ($by_strategy as $rows) {
        $rows = array_slice($rows, -CCM_TOOLS_SH_HISTORY_MAX);
        $trimmed = array_merge($trimmed, $rows);
    }
    usort($trimmed, function ($a, $b) {
        return ((int) $a['at']) <=> ((int) $b['at']);
    });

    update_option('ccm_tools_site_health_history', $trimmed, false);
}

/**
 * Stored history, newest last.
 *
 * @param string $strategy 'mobile', 'desktop' or '' for both.
 * @return array
 */
function ccm_tools_sh_history(string $strategy = ''): array {
    $history = get_option('ccm_tools_site_health_history', array());
    if (!is_array($history)) {
        return array();
    }
    if ($strategy === '') {
        return $history;
    }
    $out = array();
    foreach ($history as $row) {
        if (isset($row['strategy']) && $row['strategy'] === $strategy) {
            $out[] = $row;
        }
    }
    return $out;
}

// ─── AJAX ───────────────────────────────────────────────────────

add_action('wp_ajax_ccm_tools_sh_save_key', 'ccm_tools_ajax_sh_save_key');
/**
 * Save the PageSpeed Insights API key.
 *
 * @return void
 */
function ccm_tools_ajax_sh_save_key(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'ccm-tools')));
    }

    if (ccm_tools_sh_key_is_constant()) {
        wp_send_json_error(array('message' => __('The API key is set in wp-config.php, so it cannot be changed here.', 'ccm-tools')));
    }

    $key = isset($_POST['api_key']) ? trim(sanitize_text_field(wp_unslash($_POST['api_key']))) : '';

    // A masked value means "leave it alone".
    if ($key !== '' && strpos($key, '•') === false) {
        if (!preg_match('/^[A-Za-z0-9_\-]{20,120}$/', $key)) {
            wp_send_json_error(array('message' => __('That does not look like a Google API key.', 'ccm-tools')));
        }
        ccm_tools_sh_save_settings(array('api_key' => $key));
    } elseif ($key === '') {
        ccm_tools_sh_save_settings(array('api_key' => ''));
    }

    wp_send_json_success(array(
        'has_key' => ccm_tools_sh_api_key() !== '',
        'message' => __('API key saved.', 'ccm-tools'),
    ));
}

add_action('wp_ajax_ccm_tools_sh_run_test', 'ccm_tools_ajax_sh_run_test');
/**
 * Run one PageSpeed Insights test.
 *
 * The browser calls this once per strategy so neither request runs long enough
 * to hit an execution timeout.
 *
 * @return void
 */
function ccm_tools_ajax_sh_run_test(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'ccm-tools')));
    }

    $url      = isset($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '';
    $strategy = isset($_POST['strategy']) && $_POST['strategy'] === 'desktop' ? 'desktop' : 'mobile';

    $report = ccm_tools_sh_run($url, $strategy);
    if (is_wp_error($report)) {
        wp_send_json_error(array('message' => $report->get_error_message()));
    }

    ccm_tools_sh_record($report);
    ccm_tools_sh_save_settings(array('last_url' => $report['url']));

    wp_send_json_success($report);
}

add_action('wp_ajax_ccm_tools_sh_clear_history', 'ccm_tools_ajax_sh_clear_history');
/**
 * Wipe the stored score history.
 *
 * @return void
 */
function ccm_tools_ajax_sh_clear_history(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'ccm-tools')));
    }
    delete_option('ccm_tools_site_health_history');
    wp_send_json_success(array('message' => __('History cleared.', 'ccm-tools')));
}

// ─── Page ───────────────────────────────────────────────────────

/**
 * Render the Site Health admin page.
 *
 * @return void
 */
function ccm_tools_render_site_health_page(): void {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.', 'ccm-tools'));
    }

    $settings   = ccm_tools_sh_get_settings();
    $has_key    = ccm_tools_sh_api_key() !== '';
    $key_locked = ccm_tools_sh_key_is_constant();
    $test_url   = $settings['last_url'] !== '' ? $settings['last_url'] : home_url('/');
    $history    = ccm_tools_sh_history();
    ?>
    <div class="wrap ccm-tools ccm-tools-site-health">
        <?php
        if (function_exists('ccm_tools_render_header_nav')) {
            ccm_tools_render_header_nav('ccm-tools-site-health');
        }
        ?>
        <div class="ccm-content">

            <?php if (!$has_key) : ?>
            <div class="ccm-card">
                <h2><?php _e('Connect PageSpeed Insights', 'ccm-tools'); ?></h2>
                <p class="ccm-text-muted">
                    <?php _e('Site Health runs Google PageSpeed Insights against this site and tells you which CCM Tools settings would address what it finds. It never changes a setting on its own.', 'ccm-tools'); ?>
                </p>
                <ol class="ccm-text-muted" style="margin-left:1.2em;">
                    <li><?php
                    printf(
                        /* translators: %s: link to the Google Cloud console */
                        esc_html__('Create an API key in the %s.', 'ccm-tools'),
                        '<a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener noreferrer">' . esc_html__('Google Cloud console', 'ccm-tools') . '</a>'
                    ); ?></li>
                    <li><?php _e('Enable the PageSpeed Insights API for that key.', 'ccm-tools'); ?></li>
                    <li><?php _e('Restrict the key to the PageSpeed Insights API so it cannot be used for anything else.', 'ccm-tools'); ?></li>
                </ol>
                <p class="ccm-text-muted">
                    <?php _e('The free quota is 25,000 requests a day, which is far more than this page will ever use.', 'ccm-tools'); ?>
                </p>
            </div>
            <?php endif; ?>

            <!-- API key -->
            <div class="ccm-card">
                <h2>
                    <?php _e('API key', 'ccm-tools'); ?>
                    <span id="sh-key-badge" class="ccm-badge <?php echo $has_key ? 'ccm-badge-success' : 'ccm-badge-warning'; ?>">
                        <?php echo $has_key ? esc_html__('Configured', 'ccm-tools') : esc_html__('Not set', 'ccm-tools'); ?>
                    </span>
                </h2>
                <?php if ($key_locked) : ?>
                    <p class="ccm-text-muted">
                        <?php _e('The key is defined as CCM_TOOLS_PSI_KEY in wp-config.php, which is the safer place for it. Remove that constant if you would rather manage it here.', 'ccm-tools'); ?>
                    </p>
                <?php else : ?>
                    <div class="ccm-form-field">
                        <label for="sh-api-key"><?php _e('PageSpeed Insights API key', 'ccm-tools'); ?></label>
                        <input type="password" id="sh-api-key" class="ccm-input" autocomplete="off"
                               value="<?php echo $has_key ? esc_attr(str_repeat('•', 16)) : ''; ?>"
                               data-has-key="<?php echo $has_key ? '1' : '0'; ?>"
                               placeholder="AIza...">
                    </div>
                    <div class="ccm-buttons" style="margin-top:0.75rem;">
                        <button type="button" id="sh-save-key" class="ccm-button ccm-button-primary"><?php _e('Save key', 'ccm-tools'); ?></button>
                    </div>
                    <p class="ccm-text-muted" style="font-size:0.85em;margin-top:0.5rem;">
                        <?php _e('Stored in the database for this site only. To keep it out of the database entirely, define CCM_TOOLS_PSI_KEY in wp-config.php instead.', 'ccm-tools'); ?>
                    </p>
                <?php endif; ?>
            </div>

            <!-- Run a test -->
            <div class="ccm-card">
                <h2><?php _e('Run a test', 'ccm-tools'); ?></h2>
                <p class="ccm-text-muted"><?php _e('Mobile and desktop are measured separately. A run takes up to a minute each.', 'ccm-tools'); ?></p>
                <div class="ccm-form-field">
                    <label for="sh-url"><?php _e('Page to test', 'ccm-tools'); ?></label>
                    <input type="url" id="sh-url" class="ccm-input" value="<?php echo esc_attr($test_url); ?>">
                    <p class="ccm-text-muted" style="font-size:0.85em;"><?php _e('Must be a page on this site.', 'ccm-tools'); ?></p>
                </div>
                <div class="ccm-buttons" style="margin-top:0.75rem;">
                    <button type="button" id="sh-run-mobile" class="ccm-button ccm-button-primary" <?php disabled(!$has_key); ?>><?php _e('Test mobile', 'ccm-tools'); ?></button>
                    <button type="button" id="sh-run-desktop" class="ccm-button ccm-button-secondary" <?php disabled(!$has_key); ?>><?php _e('Test desktop', 'ccm-tools'); ?></button>
                    <button type="button" id="sh-run-both" class="ccm-button ccm-button-secondary" <?php disabled(!$has_key); ?>><?php _e('Test both', 'ccm-tools'); ?></button>
                </div>
                <div id="sh-run-status" style="margin-top:0.75rem;"></div>
            </div>

            <!-- Results -->
            <div class="ccm-card" id="sh-results-card" style="display:none;">
                <h2><?php _e('Results', 'ccm-tools'); ?> <span id="sh-results-meta" class="ccm-text-muted" style="font-weight:400;font-size:0.8em;"></span></h2>
                <div id="sh-results"></div>
            </div>

            <!-- Recommendations -->
            <div class="ccm-card" id="sh-findings-card" style="display:none;">
                <h2><?php _e('What to do about it', 'ccm-tools'); ?></h2>
                <p class="ccm-text-muted">
                    <?php _e('Ranked by how much time each one costs. Nothing here is applied for you. Where CCM Tools has a setting that addresses a finding, there is a link to it.', 'ccm-tools'); ?>
                </p>
                <div id="sh-findings"></div>
            </div>

            <!-- History -->
            <div class="ccm-card" id="sh-history-card"<?php echo empty($history) ? ' style="display:none;"' : ''; ?>>
                <h2>
                    <?php _e('Score history', 'ccm-tools'); ?>
                    <button type="button" id="sh-clear-history" class="ccm-button ccm-button-small ccm-button-secondary" style="float:right;"><?php _e('Clear', 'ccm-tools'); ?></button>
                </h2>
                <div id="sh-history">
                    <?php ccm_tools_sh_render_history_table($history); ?>
                </div>
            </div>

        </div>
    </div>
    <?php
}

/**
 * Render the stored history as a table.
 *
 * @param array $history History rows.
 * @return void
 */
function ccm_tools_sh_render_history_table(array $history): void {
    if (empty($history)) {
        echo '<p class="ccm-text-muted">' . esc_html__('No tests recorded yet.', 'ccm-tools') . '</p>';
        return;
    }

    $rows = array_reverse($history);
    ?>
    <table class="ccm-table">
        <thead>
            <tr>
                <th><?php _e('When', 'ccm-tools'); ?></th>
                <th><?php _e('Device', 'ccm-tools'); ?></th>
                <th><?php _e('Performance', 'ccm-tools'); ?></th>
                <th><?php _e('Accessibility', 'ccm-tools'); ?></th>
                <th><?php _e('Best practices', 'ccm-tools'); ?></th>
                <th><?php _e('SEO', 'ccm-tools'); ?></th>
                <th><?php _e('Page', 'ccm-tools'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row) :
            $scores = isset($row['scores']) && is_array($row['scores']) ? $row['scores'] : array();
            $cell = function ($key) use ($scores) {
                if (!isset($scores[$key])) {
                    return '<span class="ccm-text-muted">-</span>';
                }
                $v = (int) $scores[$key];
                $class = $v >= 90 ? 'ccm-success' : ($v >= 50 ? 'ccm-warning' : 'ccm-error');
                return '<span class="' . esc_attr($class) . '">' . esc_html($v) . '</span>';
            };
            ?>
            <tr>
                <td><?php echo esc_html(wp_date('j M Y, g:ia', (int) $row['at'])); ?></td>
                <td><?php echo esc_html(isset($row['strategy']) && $row['strategy'] === 'desktop' ? __('Desktop', 'ccm-tools') : __('Mobile', 'ccm-tools')); ?></td>
                <td><?php echo wp_kses_post($cell('performance')); ?></td>
                <td><?php echo wp_kses_post($cell('accessibility')); ?></td>
                <td><?php echo wp_kses_post($cell('best-practices')); ?></td>
                <td><?php echo wp_kses_post($cell('seo')); ?></td>
                <td class="ccm-text-muted"><?php echo esc_html(wp_parse_url((string) $row['url'], PHP_URL_PATH) ?: '/'); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php
}
