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

/**
 * How many past runs to keep per strategy. Only the scores and a timestamp are
 * stored per run, a few dozen bytes, so this can afford to be generous.
 */
if (!defined('CCM_TOOLS_SH_HISTORY_MAX')) {
    define('CCM_TOOLS_SH_HISTORY_MAX', 200);
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
/**
 * Format a lab metric for display.
 *
 * Lighthouse's own displayValue is not consistent between audits: most are a
 * bare figure like "1.7 s", but server-response-time returns the sentence
 * "Root document took 0 ms". Printing that as the tile's headline value reads
 * as a caption rather than a measurement, and it wraps onto a second line,
 * which makes that one tile taller than the five beside it.
 *
 * @param string     $id       Lighthouse audit id.
 * @param float|null $numeric  numericValue, in ms except for CLS.
 * @param string     $fallback displayValue, used only when there is no number.
 * @return string
 */
function ccm_tools_sh_format_metric(string $id, $numeric, string $fallback): string {
    if ($numeric === null) {
        return $fallback !== '' ? $fallback : '-';
    }

    // Cumulative Layout Shift is a ratio, not a duration.
    if ($id === 'cumulative-layout-shift') {
        return rtrim(rtrim(number_format_i18n($numeric, 3), '0'), '.') ?: '0';
    }

    // Under a second reads better in milliseconds; above it, in seconds.
    if ($numeric < 1000) {
        return number_format_i18n(round($numeric)) . ' ms';
    }

    return number_format_i18n($numeric / 1000, 1) . ' s';
}

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

    // Lab metrics. Each carries Google's published good / needs-improvement
    // boundaries so the interface can show WHERE a value falls, not just what
    // colour it is. A bare number cannot tell you whether 2.6s was a near miss
    // or a long way off.
    $metric_ids = array(
        'largest-contentful-paint' => array(__('Largest Contentful Paint', 'ccm-tools'), 'LCP',  2500, 4000),
        'cumulative-layout-shift'  => array(__('Cumulative Layout Shift', 'ccm-tools'),  'CLS',  0.1,  0.25),
        'total-blocking-time'      => array(__('Total Blocking Time', 'ccm-tools'),      'TBT',  200,  600),
        'first-contentful-paint'   => array(__('First Contentful Paint', 'ccm-tools'),   'FCP',  1800, 3000),
        'speed-index'              => array(__('Speed Index', 'ccm-tools'),              'SI',   3400, 5800),
        'server-response-time'     => array(__('Server Response Time', 'ccm-tools'),     'TTFB', 800,  1800),
    );
    foreach ($metric_ids as $id => $meta) {
        if (!isset($audits[$id])) {
            continue;
        }
        list($label, $abbr, $good, $poor) = $meta;

        $numeric = isset($audits[$id]['numericValue']) && is_numeric($audits[$id]['numericValue'])
            ? (float) $audits[$id]['numericValue']
            : null;

        $out['metrics'][$id] = array(
            'label'   => $label,
            'abbr'    => $abbr,
            'display' => ccm_tools_sh_format_metric(
                $id,
                $numeric,
                isset($audits[$id]['displayValue']) ? (string) $audits[$id]['displayValue'] : ''
            ),
            'numeric' => $numeric,
            'good'    => $good,
            'poor'    => $poor,
            'band'    => $numeric === null ? '' : ($numeric <= $good ? 'good' : ($numeric <= $poor ? 'ok' : 'bad')),
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
 * Score band for a 0-100 Lighthouse category score.
 *
 * @param int|null $score
 * @return string good|ok|bad|none
 */
function ccm_tools_sh_band($score): string {
    if ($score === null || $score === '') {
        return 'none';
    }
    $score = (int) $score;
    if ($score >= 90) { return 'good'; }
    if ($score >= 50) { return 'ok'; }
    return 'bad';
}

/**
 * Render one score ring.
 *
 * The arc is drawn with stroke-dasharray on a circle, so the value is baked
 * into the markup rather than animated into place by script. The page is
 * correct the instant it paints and stays correct with JavaScript off.
 *
 * @param string   $label Category name.
 * @param int|null $score 0-100, or null when not measured.
 * @param string   $sub   Small line beneath the label.
 * @return string
 */
function ccm_tools_sh_gauge(string $label, $score, string $sub = ''): string {
    $band = ccm_tools_sh_band($score);
    $r    = 46;
    $circ = 2 * M_PI * $r;
    $pct  = ($score === null) ? 0 : max(0, min(100, (int) $score)) / 100;
    $off  = $circ * (1 - $pct);

    $out  = '<div class="ccm-gauge ccm-gauge--' . esc_attr($band) . '">';
    $out .= '<div class="ccm-gauge__ring">';
    $out .= '<svg viewBox="0 0 108 108" aria-hidden="true" focusable="false">';
    $out .= '<circle class="ccm-gauge__track" cx="54" cy="54" r="' . $r . '"/>';
    $out .= '<circle class="ccm-gauge__value" cx="54" cy="54" r="' . $r . '"'
          . ' stroke-dasharray="' . round($circ, 2) . '"'
          . ' stroke-dashoffset="' . round($off, 2) . '"/>';
    $out .= '</svg>';
    $out .= '<div class="ccm-gauge__num">'
          . ($score === null ? '<small>' . esc_html__('n/a', 'ccm-tools') . '</small>' : esc_html((string) (int) $score))
          . '</div>';
    $out .= '</div>';
    $out .= '<div><div class="ccm-gauge__label">' . esc_html($label) . '</div>';
    if ($sub !== '') {
        $out .= '<div class="ccm-gauge__sub">' . esc_html($sub) . '</div>';
    }
    $out .= '</div></div>';

    return $out;
}

/**
 * Render a sparkline plus the latest value for one category's history.
 *
 * @param string $label  Category name.
 * @param array  $points Chronological list of ints.
 * @return string
 */
function ccm_tools_sh_trend(string $label, array $points): string {
    $points = array_values(array_filter($points, 'is_numeric'));
    $now    = $points ? (int) end($points) : null;
    $band   = ccm_tools_sh_band($now);

    $out = '<div class="ccm-trend" style="color: var(--ccm-' .
        ($band === 'good' ? 'success' : ($band === 'ok' ? 'warning' : ($band === 'bad' ? 'error' : 'text-light'))) . ');">';
    $out .= '<div class="ccm-trend__head">';
    $out .= '<span class="ccm-trend__name">' . esc_html($label) . '</span>';

    if (count($points) >= 2) {
        $delta = $now - (int) $points[count($points) - 2];
        $dir   = $delta > 0 ? 'up' : ($delta < 0 ? 'down' : 'flat');
        $sign  = $delta > 0 ? '+' : '';
        $out  .= '<span class="ccm-trend__delta ccm-trend__delta--' . $dir . '">'
               . esc_html($sign . $delta) . '</span>';
    }
    $out .= '</div>';
    $out .= '<div class="ccm-trend__now" style="color: var(--ccm-text);">'
          . ($now === null ? '&ndash;' : esc_html((string) $now)) . '</div>';

    if (count($points) >= 2) {
        // Fixed 0-100 domain: a sparkline auto-scaled to its own min and max
        // makes a wobble between 97 and 99 look like a cliff.
        $w = 100.0; $h = 28.0; $n = count($points);
        $step = $w / max(1, $n - 1);
        $coords = array();
        foreach ($points as $i => $v) {
            $x = round($i * $step, 2);
            $y = round($h - (max(0, min(100, (int) $v)) / 100) * $h, 2);
            $coords[] = $x . ',' . $y;
        }
        $line = implode(' ', $coords);
        $area = '0,' . $h . ' ' . $line . ' ' . round(($n - 1) * $step, 2) . ',' . $h;
        list($lx, $ly) = explode(',', end($coords));

        $out .= '<svg class="ccm-spark" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" aria-hidden="true" focusable="false">';
        $out .= '<polygon class="ccm-spark__area" points="' . esc_attr($area) . '"/>';
        $out .= '<polyline class="ccm-spark__line" points="' . esc_attr($line) . '" vector-effect="non-scaling-stroke"/>';
        $out .= '</svg>';
        $out .= '<span class="sr-only">' . esc_html(sprintf(
            /* translators: %d: number of recorded tests */
            __('%d recorded tests', 'ccm-tools'), $n)) . '</span>';
    }

    $out .= '</div>';
    return $out;
}

/**
 * Render the Site Health admin page.
 *
 * Reading order is deliberate: the thing you came for (scores) is first, the
 * thing you act on (findings) is second, the record (history) is third, and
 * the API key, which you set once and never touch again, is last.
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
    $last       = $history ? end($history) : null;
    ?>
    <div class="wrap ccm-tools ccm-tools-site-health">
        <?php
        if (function_exists('ccm_tools_render_header_nav')) {
            ccm_tools_render_header_nav('ccm-tools-site-health');
        }
        ?>
        <div class="ccm-content">

            <!-- Hero -->
            <div class="ccm-hero">
                <div class="ccm-hero__text">
                    <h1><?php _e('Site Health', 'ccm-tools'); ?></h1>
                    <div class="ccm-hero__meta">
                        <span><?php echo esc_html(wp_parse_url(home_url(), PHP_URL_HOST)); ?></span>
                        <?php if ($last) : ?>
                            <span><?php printf(
                                /* translators: %s: human readable time difference */
                                esc_html__('last tested %s ago', 'ccm-tools'),
                                esc_html(human_time_diff((int) $last['at']))
                            ); ?></span>
                            <span><?php printf(
                                /* translators: %d: number of stored runs */
                                esc_html(_n('%d run recorded', '%d runs recorded', count($history), 'ccm-tools')),
                                count($history)
                            ); ?></span>
                        <?php else : ?>
                            <span><?php _e('never tested', 'ccm-tools'); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="ccm-hero__actions">
                    <div class="ccm-seg" role="group" aria-label="<?php esc_attr_e('Device', 'ccm-tools'); ?>">
                        <input type="radio" name="sh-device" id="sh-dev-mobile" value="mobile" checked>
                        <label for="sh-dev-mobile"><?php _e('Mobile', 'ccm-tools'); ?></label>
                        <input type="radio" name="sh-device" id="sh-dev-desktop" value="desktop">
                        <label for="sh-dev-desktop"><?php _e('Desktop', 'ccm-tools'); ?></label>
                    </div>
                    <button type="button" id="sh-run" class="ccm-button ccm-button-primary" <?php disabled(!$has_key); ?>>
                        <?php _e('Run test', 'ccm-tools'); ?>
                    </button>
                </div>
            </div>

            <!-- What gets tested -->
            <div class="ccm-toolbar" style="margin-bottom: var(--ccm-space-lg);">
                <label for="sh-url" style="font-size: var(--ccm-text-sm); font-weight: 600; color: var(--ccm-text-muted);">
                    <?php _e('Page', 'ccm-tools'); ?>
                </label>
                <input type="url" id="sh-url" class="ccm-input" style="flex: 1 1 22rem; width: auto;"
                       value="<?php echo esc_attr($test_url); ?>">
                <span class="ccm-toolbar__spacer"></span>
                <button type="button" id="sh-run-both" class="ccm-button ccm-button-secondary ccm-button-small" <?php disabled(!$has_key); ?>>
                    <?php _e('Test both devices', 'ccm-tools'); ?>
                </button>
            </div>

            <div id="sh-status" role="status" aria-live="polite"></div>

            <?php if (!$has_key) : ?>
                <div class="ccm-empty" id="sh-nokey">
                    <span class="ccm-empty__icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><path d="M21 2l-2 2m-7.6 7.6a5 5 0 11-7 7 5 5 0 017-7zm0 0L15 8m0 0l3 3 3-3-3-3"/></svg>
                    </span>
                    <h3><?php _e('Add a PageSpeed Insights key to begin', 'ccm-tools'); ?></h3>
                    <p><?php _e('Site Health measures this site with Google PageSpeed Insights and tells you which CCM Tools settings address what it finds. It never changes a setting on its own.', 'ccm-tools'); ?></p>
                    <button type="button" class="ccm-button ccm-button-primary" id="sh-jump-setup"><?php _e('Set up the key', 'ccm-tools'); ?></button>
                </div>
            <?php elseif (!$last) : ?>
                <div class="ccm-empty" id="sh-never">
                    <span class="ccm-empty__icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><path d="M3 12h4l3 8 4-16 3 8h4"/></svg>
                    </span>
                    <h3><?php _e('No test run yet', 'ccm-tools'); ?></h3>
                    <p><?php _e('Pick a device and run a test. Mobile and desktop are measured separately and each takes up to a minute.', 'ccm-tools'); ?></p>
                </div>
            <?php endif; ?>

            <!-- Scores -->
            <div id="sh-scores-wrap" class="ccm-hide">
                <div class="ccm-section">
                    <div>
                        <span class="ccm-section__eyebrow" id="sh-scores-eyebrow"><?php _e('Lighthouse', 'ccm-tools'); ?></span>
                        <h2><?php _e('Scores', 'ccm-tools'); ?></h2>
                        <p id="sh-scores-meta"></p>
                    </div>
                </div>
                <div class="ccm-gauges" id="sh-gauges"></div>
            </div>

            <!-- Core Web Vitals -->
            <div id="sh-vitals-wrap" class="ccm-hide">
                <div class="ccm-section">
                    <div>
                        <span class="ccm-section__eyebrow"><?php _e('Measured', 'ccm-tools'); ?></span>
                        <h2><?php _e('Core Web Vitals', 'ccm-tools'); ?></h2>
                        <p><?php _e('The marker shows where this page falls against Google\'s own good and poor boundaries.', 'ccm-tools'); ?></p>
                    </div>
                </div>
                <div class="ccm-metrics" id="sh-vitals"></div>
                <div id="sh-field-wrap" class="ccm-hide" style="margin-top: var(--ccm-space-md);">
                    <div class="ccm-panel">
                        <div class="ccm-panel__head">
                            <span><?php _e('Real visitors, last 28 days', 'ccm-tools'); ?></span>
                            <span class="ccm-chip"><?php _e('Chrome UX Report', 'ccm-tools'); ?></span>
                        </div>
                        <div class="ccm-kv" id="sh-field"></div>
                    </div>
                </div>
            </div>

            <!-- Findings -->
            <div id="sh-findings-wrap" class="ccm-hide">
                <div class="ccm-section">
                    <div>
                        <span class="ccm-section__eyebrow"><?php _e('Ranked by cost', 'ccm-tools'); ?></span>
                        <h2><?php _e('What to fix', 'ccm-tools'); ?></h2>
                        <p><?php _e('Nothing here is applied for you. Where CCM Tools has a setting that addresses a finding, there is a link straight to it.', 'ccm-tools'); ?></p>
                    </div>
                    <div class="ccm-row">
                        <span class="ccm-chip" id="sh-findings-count"></span>
                    </div>
                </div>
                <div class="ccm-findings" id="sh-findings"></div>
            </div>

            <!-- History -->
            <div id="sh-history-wrap"<?php echo $history ? '' : ' class="ccm-hide"'; ?>>
                <div class="ccm-section">
                    <div>
                        <span class="ccm-section__eyebrow"><?php _e('Over time', 'ccm-tools'); ?></span>
                        <h2><?php _e('History', 'ccm-tools'); ?></h2>
                        <p><?php _e('Every run is kept, so you can tell whether a change actually helped.', 'ccm-tools'); ?></p>
                    </div>
                    <div class="ccm-row">
                        <div class="ccm-seg" role="group" aria-label="<?php esc_attr_e('History device', 'ccm-tools'); ?>">
                            <input type="radio" name="sh-hist-device" id="sh-hist-mobile" value="mobile" checked>
                            <label for="sh-hist-mobile"><?php _e('Mobile', 'ccm-tools'); ?></label>
                            <input type="radio" name="sh-hist-device" id="sh-hist-desktop" value="desktop">
                            <label for="sh-hist-desktop"><?php _e('Desktop', 'ccm-tools'); ?></label>
                        </div>
                        <button type="button" id="sh-clear-history" class="ccm-button ccm-button-secondary ccm-button-small">
                            <?php _e('Clear', 'ccm-tools'); ?>
                        </button>
                    </div>
                </div>

                <div class="ccm-trends" id="sh-trends"></div>

                <details class="ccm-disclose" style="margin-top: var(--ccm-space-md);">
                    <summary>
                        <?php _e('Every recorded run', 'ccm-tools'); ?>
                        <span class="ccm-disclose__note" id="sh-log-count"></span>
                    </summary>
                    <div class="ccm-disclose__body ccm-panel__body--flush">
                        <div class="ccm-table-wrap">
                            <table class="ccm-table" id="sh-log">
                                <thead>
                                    <tr>
                                        <th><?php _e('When', 'ccm-tools'); ?></th>
                                        <th><?php _e('Device', 'ccm-tools'); ?></th>
                                        <th><?php _e('Perf', 'ccm-tools'); ?></th>
                                        <th><?php _e('A11y', 'ccm-tools'); ?></th>
                                        <th><?php _e('Best pr.', 'ccm-tools'); ?></th>
                                        <th><?php _e('SEO', 'ccm-tools'); ?></th>
                                        <th><?php _e('Page', 'ccm-tools'); ?></th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                </details>
            </div>

            <!-- Setup, last, because you do this once -->
            <div class="ccm-section">
                <div>
                    <span class="ccm-section__eyebrow"><?php _e('One-off', 'ccm-tools'); ?></span>
                    <h2><?php _e('Setup', 'ccm-tools'); ?></h2>
                </div>
            </div>

            <details class="ccm-disclose" id="sh-setup"<?php echo $has_key ? '' : ' open'; ?>>
                <summary>
                    <?php _e('PageSpeed Insights API key', 'ccm-tools'); ?>
                    <span class="ccm-disclose__note">
                        <?php if ($key_locked) : ?>
                            <span class="ccm-chip ccm-chip--good"><?php _e('Set in wp-config.php', 'ccm-tools'); ?></span>
                        <?php elseif ($has_key) : ?>
                            <span class="ccm-chip ccm-chip--good"><?php _e('Configured', 'ccm-tools'); ?></span>
                        <?php else : ?>
                            <span class="ccm-chip ccm-chip--warn"><?php _e('Not set', 'ccm-tools'); ?></span>
                        <?php endif; ?>
                    </span>
                </summary>
                <div class="ccm-disclose__body">
                    <?php if ($key_locked) : ?>
                        <p class="ccm-text-muted" style="font-size: var(--ccm-text-sm); margin: 0;">
                            <?php _e('The key is defined as CCM_TOOLS_PSI_KEY in wp-config.php, which keeps it out of the database. Remove that constant if you would rather manage it here.', 'ccm-tools'); ?>
                        </p>
                    <?php else : ?>
                        <div class="ccm-grid-2">
                            <div>
                                <div class="ccm-form-field">
                                    <label for="sh-api-key"><?php _e('API key', 'ccm-tools'); ?></label>
                                    <input type="password" id="sh-api-key" class="ccm-input" autocomplete="off"
                                           value="<?php echo $has_key ? esc_attr(str_repeat('•', 16)) : ''; ?>"
                                           data-has-key="<?php echo $has_key ? '1' : '0'; ?>"
                                           placeholder="AIza...">
                                </div>
                                <div class="ccm-row" style="margin-top: var(--ccm-space-sm);">
                                    <button type="button" id="sh-save-key" class="ccm-button ccm-button-primary ccm-button-small">
                                        <?php _e('Save key', 'ccm-tools'); ?>
                                    </button>
                                    <span id="sh-key-msg" class="ccm-text-muted" style="font-size: var(--ccm-text-xs);"></span>
                                </div>
                            </div>
                            <div>
                                <ol class="ccm-text-muted" style="font-size: var(--ccm-text-sm); margin: 0; padding-left: 1.1em; line-height: 1.8;">
                                    <li><?php
                                    printf(
                                        /* translators: %s: link to the Google Cloud console */
                                        esc_html__('Create a key in the %s.', 'ccm-tools'),
                                        '<a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener noreferrer">' . esc_html__('Google Cloud console', 'ccm-tools') . '</a>'
                                    ); ?></li>
                                    <li><?php _e('Enable the PageSpeed Insights API for it.', 'ccm-tools'); ?></li>
                                    <li><?php _e('Restrict it to that API so it cannot be used for anything else.', 'ccm-tools'); ?></li>
                                </ol>
                                <p class="ccm-text-muted" style="font-size: var(--ccm-text-xs); margin-top: var(--ccm-space-sm);">
                                    <?php _e('The free quota is 25,000 requests a day. Define CCM_TOOLS_PSI_KEY in wp-config.php to keep the key out of the database entirely.', 'ccm-tools'); ?>
                                </p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </details>

        </div>
    </div>

    <script type="application/json" id="sh-bootstrap"><?php
        echo wp_json_encode(array(
            'history' => array_values($history),
            'hasKey'  => $has_key,
        ));
    ?></script>
    <?php
}
