<?php
/* 2eabd4dba2537e80 */

if (!defined('ABSPATH')) {
    exit;
}

/* 796647d11787068f */
function ccm_tools_health_check($id, $label, $status, $value, $detail = '', $link = array(), $weight = 1) {
    return array(
        'id'     => $id,
        'label'  => $label,
        'status' => $status,
        'value'  => $value,
        'detail' => $detail,
        'link'   => $link,
        'weight' => (int) $weight,
    );
}

/* de4ccb92d2678a8c */
/* d61a2b17f7d116d2 */
function ccm_tools_health_link($page, $anchor = '', $label = '') {
    $slug = ($page === '') ? 'ccm-tools' : 'ccm-tools-' . $page;

    if (!ccm_tools_health_page_exists($slug)) {
        return array();
    }

    $url = admin_url('admin.php?page=' . $slug);
    if ($anchor !== '') {
        $url .= '#ccm-focus-' . $anchor;
    }
    return array('url' => $url, 'label' => $label !== '' ? $label : __('Open', 'ccm-tools'));
}

/* 17f93ca2038c9354 */
function ccm_tools_health_page_exists($slug) {
    global $submenu;

    if (empty($submenu['ccm-tools']) || !is_array($submenu['ccm-tools'])) {
        return true; // Menu not built — not an admin screen. Fail open.
    }

    foreach ($submenu['ccm-tools'] as $item) {
        if (isset($item[2]) && $item[2] === $slug) {
            return true;
        }
    }

    return false;
}

/* ───────────────────────── Caching ───────────────────────── */

function ccm_tools_health_caching(): array {
    $checks = array();

    // Object cache.
    $redis_settings = function_exists('ccm_tools_redis_get_settings') ? ccm_tools_redis_get_settings() : array();
    $redis_enabled  = !empty($redis_settings['enabled']);
    $dropin         = function_exists('ccm_tools_redis_dropin_status') ? ccm_tools_redis_dropin_status() : array();
    $dropin_ok      = !empty($dropin['exists']) && !empty($dropin['is_ccm']);

    if (!$redis_enabled) {
        $checks[] = ccm_tools_health_check(
            'object-cache', __('Object cache', 'ccm-tools'), 'off',
            __('Not in use', 'ccm-tools'),
            __('Every page build re-runs the same queries. On a site with any real traffic this is the single biggest win available here.', 'ccm-tools'),
            ccm_tools_health_link('redis', 'redis-enable', __('Set up Redis', 'ccm-tools')),
            3
        );
    } elseif (!$dropin_ok) {
        $checks[] = ccm_tools_health_check(
            'object-cache', __('Object cache', 'ccm-tools'), 'bad',
            __('Configured but not installed', 'ccm-tools'),
            __('Redis is switched on here but the object-cache.php drop-in is missing, so nothing is actually being cached.', 'ccm-tools'),
            ccm_tools_health_link('redis', 'redis-enable', __('Install the drop-in', 'ccm-tools')),
            3
        );
    } else {
        $status = function_exists('ccm_tools_check_redis_status') ? ccm_tools_check_redis_status() : array();
        $connected = !empty($status['connected']);
        $checks[] = ccm_tools_health_check(
            'object-cache', __('Object cache', 'ccm-tools'),
            $connected ? 'good' : 'bad',
            $connected
                ? sprintf(__('Redis %s', 'ccm-tools'), (string) ($status['version'] ?? ''))
                : __('Not reachable', 'ccm-tools'),
            $connected
                ? __('Queries are being served from memory rather than rebuilt per request.', 'ccm-tools')
                : __('The drop-in is installed but the server is not answering, so every request is falling through to the database.', 'ccm-tools'),
            ccm_tools_health_link('redis', 'redis-host', __('Check the connection', 'ccm-tools')),
            3
        );
    }

    // Browser caching and compression, from the .htaccess block.
    // htaccess.php resolves this the same way, as ABSPATH . '.htaccess'.
    $htaccess = '';
    $htaccess_file = ABSPATH . '.htaccess';
    if (is_readable($htaccess_file)) {
        $htaccess = (string) file_get_contents($htaccess_file);
    }
    $has_block = $htaccess !== '' && strpos($htaccess, '# BEGIN CCM Optimise') !== false;
    $has_gzip  = stripos($htaccess, 'mod_deflate') !== false || stripos($htaccess, 'mod_brotli') !== false;
    $has_exp   = stripos($htaccess, 'mod_expires') !== false || stripos($htaccess, 'Cache-Control') !== false;

    $checks[] = ccm_tools_health_check(
        'htaccess', __('Browser caching and compression', 'ccm-tools'),
        ($has_gzip && $has_exp) ? 'good' : ($has_block ? 'warn' : 'off'),
        $has_block
            ? sprintf(
                __('%1$s, %2$s', 'ccm-tools'),
                $has_gzip ? __('compression on', 'ccm-tools') : __('no compression', 'ccm-tools'),
                $has_exp ? __('caching on', 'ccm-tools') : __('no cache headers', 'ccm-tools')
            )
            : __('No rules written', 'ccm-tools'),
        __('These two cost nothing and apply to every asset the site serves. Without them every visit re-downloads everything uncompressed.', 'ccm-tools'),
        ccm_tools_health_link('htaccess', 'htaccess-options', __('Open .htaccess rules', 'ccm-tools')),
        2
    );

    // Cloudflare.
    $cf = function_exists('ccm_tools_cf_get_settings') ? ccm_tools_cf_get_settings() : array();
    $cf_connected = !empty($cf['zone_id']) && !empty($cf['api_token']);
    $checks[] = ccm_tools_health_check(
        'cloudflare', __('Cloudflare', 'ccm-tools'),
        $cf_connected ? 'good' : 'off',
        $cf_connected ? __('Connected', 'ccm-tools') : __('Not connected', 'ccm-tools'),
        $cf_connected
            ? __('Cache can be purged from here when you change something.', 'ccm-tools')
            : __('Optional. Connecting it lets this plugin purge the edge cache after a change, so you are not left wondering whether a fix went live.', 'ccm-tools'),
        ccm_tools_health_link('cloudflare', 'cf-connection-disclose', __('Connect Cloudflare', 'ccm-tools')),
        1
    );

    return $checks;
}

/* ───────────────────────── Front end ───────────────────────── */

function ccm_tools_health_frontend(): array {
    $checks = array();

    $perf = function_exists('ccm_tools_perf_get_settings') ? ccm_tools_perf_get_settings() : array();
    $perf_on = !empty($perf['enabled']);
    $count_on = 0;
    foreach ($perf as $k => $v) {
        if ($k !== 'enabled' && $v === true) { $count_on++; }
    }

    $checks[] = ccm_tools_health_check(
        'optimiser', __('Performance optimiser', 'ccm-tools'),
        $perf_on ? ($count_on > 0 ? 'good' : 'warn') : 'off',
        $perf_on
            ? sprintf(
                /* translators: %s: number of enabled settings */
                _n('%s setting on', '%s settings on', $count_on, 'ccm-tools'),
                number_format_i18n($count_on)
            )
            : __('Switched off', 'ccm-tools'),
        $perf_on
            ? __('Markup and request cleanup is being applied to the front end.', 'ccm-tools')
            : __('The master switch is off, so none of the settings on that page are doing anything.', 'ccm-tools'),
        ccm_tools_health_link('perf', 'perf-master-enable', __('Open the optimiser', 'ccm-tools')),
        2
    );

    // WebP coverage.
    $webp_settings = function_exists('ccm_tools_webp_get_settings') ? ccm_tools_webp_get_settings() : array();
    if (!empty($webp_settings['enabled']) && function_exists('ccm_tools_webp_get_statistics')) {
        $w = ccm_tools_webp_get_statistics();
        $total = (int) ($w['total_images'] ?? 0);
        $done  = (int) ($w['converted_images'] ?? 0);
        $pct   = $total > 0 ? (int) round(($done / $total) * 100) : 0;

        $checks[] = ccm_tools_health_check(
            'webp', __('WebP coverage', 'ccm-tools'),
            $total === 0 ? 'off' : ($pct >= 90 ? 'good' : ($pct >= 50 ? 'warn' : 'bad')),
            $total === 0
                ? __('No images', 'ccm-tools')
                : sprintf(__('%1$s%% of %2$s images', 'ccm-tools'), number_format_i18n($pct), number_format_i18n($total)),
            $pct >= 90
                ? __('Nearly everything in the library is being served in a modern format.', 'ccm-tools')
                : __('Images not yet converted are still served at full size to every visitor. A bulk run fixes the backlog in one go.', 'ccm-tools'),
            ccm_tools_health_link('webp', 'start-bulk-conversion', __('Convert the backlog', 'ccm-tools')),
            2
        );
    } else {
        $checks[] = ccm_tools_health_check(
            'webp', __('WebP conversion', 'ccm-tools'), 'off',
            __('Switched off', 'ccm-tools'),
            __('Images are being served as uploaded. WebP typically takes 25 to 35 per cent off each one with no visible difference.', 'ccm-tools'),
            ccm_tools_health_link('webp', 'webp-enabled', __('Turn on WebP', 'ccm-tools')),
            2
        );
    }

    return $checks;
}

/* ───────────────────────── Database ───────────────────────── */

function ccm_tools_health_database(): array {
    $checks = array();

    if (!function_exists('ccm_tools_db_overview')) {
        return $checks;
    }

    $db = ccm_tools_db_overview();
    $autoload_kb = $db['autoload_bytes'] / 1024;

    $checks[] = ccm_tools_health_check(
        'autoload', __('Autoloaded options', 'ccm-tools'),
        $autoload_kb > 1024 ? 'bad' : ($autoload_kb > 512 ? 'warn' : 'good'),
        size_format($db['autoload_bytes'], 1),
        $autoload_kb > 512
            ? __('Read from the database on every single request, before anything is rendered. Usually one plugin storing a large value with autoload on.', 'ccm-tools')
            : __('Comfortably within what WordPress reads happily on each request.', 'ccm-tools'),
        ccm_tools_health_link('database', '', __('Open the database page', 'ccm-tools')),
        2
    );

    $myisam = 0;
    foreach ($db['engines'] as $engine => $n) {
        if (strtoupper((string) $engine) !== 'INNODB') { $myisam += (int) $n; }
    }
    $checks[] = ccm_tools_health_check(
        'engine', __('Storage engine', 'ccm-tools'),
        $myisam > 0 ? 'warn' : 'good',
        $myisam > 0
            ? sprintf(_n('%s table on MyISAM', '%s tables on MyISAM', $myisam, 'ccm-tools'), number_format_i18n($myisam))
            : __('All InnoDB', 'ccm-tools'),
        $myisam > 0
            ? __('MyISAM locks the whole table on every write, so one slow query blocks everything else touching that table.', 'ccm-tools')
            : __('Row-level locking throughout, which is what you want.', 'ccm-tools'),
        ccm_tools_health_link('database', 'opt-convert_innodb', __('Convert to InnoDB', 'ccm-tools')),
        2
    );

    if (function_exists('ccm_tools_get_optimization_stats')) {
        $stats = ccm_tools_get_optimization_stats();
        $junk = (int) ($stats['spam_comments'] ?? 0)
              + (int) ($stats['trashed_posts'] ?? 0)
              + (int) ($stats['auto_drafts'] ?? 0)
              + (int) ($stats['orphaned_postmeta'] ?? 0);

        $checks[] = ccm_tools_health_check(
            'junk', __('Rows worth clearing', 'ccm-tools'),
            $junk > 20000 ? 'warn' : 'good',
            number_format_i18n($junk),
            $junk > 20000
                ? __('Spam, trash, auto-drafts and orphaned meta adding weight to every backup and every query that scans these tables.', 'ccm-tools')
                : __('Nothing has built up worth a special trip.', 'ccm-tools'),
            ccm_tools_health_link('database', '', __('Review the tasks', 'ccm-tools')),
            1
        );
    }

    return $checks;
}

/* ───────────────────────── Platform ───────────────────────── */

/**
 * When each PHP branch stops getting security fixes.
 *
 * Ordered oldest first; ccm_tools_php_branch_status() relies on that to work
 * out which side of the table an unlisted branch falls on.
 */
function ccm_tools_php_eol_dates(): array {
    return array(
        '7.4' => '2022-11-28', '8.0' => '2023-11-26', '8.1' => '2025-12-31',
        '8.2' => '2026-12-31', '8.3' => '2027-12-31', '8.4' => '2028-12-31',
        '8.5' => '2029-12-31',
    );
}

/**
 * How a PHP version should be reported, as status and explanation.
 *
 * A hard-coded table of end-of-life dates goes stale by doing nothing, and
 * there are two ways to fall off it. This used to treat both the same way --
 * anything not listed was a warning -- so a site on the NEWEST PHP available
 * was told it had something to look at, directly above a line reading "Still
 * receiving security fixes". Being ahead of the table is not a fault; the only
 * unlisted branch worth warning about is one older than everything in it.
 *
 * Separated from the check itself so it can be driven with versions this
 * machine is not running. See tests/php_eol_test.php.
 *
 * @param string   $version A full PHP version, e.g. "8.5.10".
 * @param int|null $now     Unix time to judge against; defaults to now.
 * @return array{status:string, detail:string}
 */
function ccm_tools_php_branch_status(string $version, $now = null): array {
    $eol_dates = ccm_tools_php_eol_dates();
    $now = $now === null ? time() : (int) $now;

    $branch   = implode('.', array_slice(explode('.', $version), 0, 2));
    $branches = array_keys($eol_dates);
    $oldest   = reset($branches);
    $newest   = end($branches);

    if (isset($eol_dates[$branch])) {
        $eol = $eol_dates[$branch];
        if (strtotime($eol) < $now) {
            return array(
                'status' => 'bad',
                'detail' => sprintf(
                    /* translators: %s: end-of-life date */
                    __('Security support for this branch ended on %s. It is no longer receiving fixes.', 'ccm-tools'),
                    $eol
                ),
            );
        }
        return array(
            'status' => 'good',
            'detail' => __('Still receiving security fixes.', 'ccm-tools'),
        );
    }

    if (version_compare($branch, $newest, '>')) {
        return array(
            'status' => 'good',
            'detail' => __('Newer than every branch this plugin has an end-of-life date for, so it is ahead of them rather than behind.', 'ccm-tools'),
        );
    }

    if (version_compare($branch, $oldest, '<')) {
        return array(
            'status' => 'bad',
            'detail' => __('Older than any branch still tracked here, so it stopped receiving security fixes years ago.', 'ccm-tools'),
        );
    }

    // Between two listed branches without being either: not a real PHP release.
    return array(
        'status' => 'warn',
        'detail' => __('This is not a PHP branch with a published support schedule.', 'ccm-tools'),
    );
}

function ccm_tools_health_platform(): array {
    $checks = array();

    /* 5a147deaac7316d3 */
    $php = ccm_tools_php_branch_status(PHP_VERSION);

    $checks[] = ccm_tools_health_check(
        'php', __('PHP version', 'ccm-tools'),
        $php['status'],
        PHP_VERSION,
        $php['detail'],
        array(),
        3
    );

    $checks[] = ccm_tools_health_check(
        'https', __('HTTPS', 'ccm-tools'),
        is_ssl() || strpos(home_url(), 'https://') === 0 ? 'good' : 'bad',
        strpos(home_url(), 'https://') === 0 ? __('In use', 'ccm-tools') : __('Not in use', 'ccm-tools'),
        strpos(home_url(), 'https://') === 0
            ? __('The site address is https, so browsers will not warn on it.', 'ccm-tools')
            : __('Browsers mark plain http as not secure, and several of the headers on the .htaccess page have no effect without it.', 'ccm-tools'),
        array(),
        3
    );

    $debug = defined('WP_DEBUG') && WP_DEBUG;
    $display = defined('WP_DEBUG_DISPLAY') && WP_DEBUG_DISPLAY;
    $checks[] = ccm_tools_health_check(
        'debug', __('Debug output', 'ccm-tools'),
        ($debug && $display) ? 'bad' : ($debug ? 'warn' : 'good'),
        $debug ? ($display ? __('On, and shown to visitors', 'ccm-tools') : __('Logging only', 'ccm-tools')) : __('Off', 'ccm-tools'),
        ($debug && $display)
            ? __('PHP notices are being printed into the page where anyone can read them, which leaks paths and sometimes more.', 'ccm-tools')
            : __('Nothing is being printed into the front end.', 'ccm-tools'),
        ccm_tools_health_link('', 'toggle-debug', __('Open debug settings', 'ccm-tools')),
        2
    );

    $cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
    $checks[] = ccm_tools_health_check(
        'cron', __('Scheduled tasks', 'ccm-tools'),
        'info',
        $cron_disabled ? __('Server cron', 'ccm-tools') : __('On page loads', 'ccm-tools'),
        $cron_disabled
            ? __('WP-Cron is disabled here, so something on the server must be calling wp-cron.php. If nothing is, scheduled jobs never run.', 'ccm-tools')
            : __('Scheduled jobs run on visits, so a quiet site runs them late. Fine for most sites.', 'ccm-tools'),
        array(),
        0
    );

    return $checks;
}

/* ───────────────────────── Assembly ───────────────────────── */

/* 2d7221cee0e1bb70 */
function ccm_tools_health_report(): array {
    $groups = array(
        'caching'  => array('name' => __('Caching', 'ccm-tools'),   'checks' => ccm_tools_health_caching()),
        'frontend' => array('name' => __('Front end', 'ccm-tools'), 'checks' => ccm_tools_health_frontend()),
        'database' => array('name' => __('Database', 'ccm-tools'),  'checks' => ccm_tools_health_database()),
        'platform' => array('name' => __('Platform', 'ccm-tools'),  'checks' => ccm_tools_health_platform()),
    );

    /* abf4b28bff0006d9 */
    $earned = 0.0;
    $possible = 0.0;
    $counts = array('good' => 0, 'warn' => 0, 'bad' => 0, 'off' => 0, 'info' => 0);

    foreach ($groups as $group) {
        foreach ($group['checks'] as $check) {
            $counts[$check['status']] = ($counts[$check['status']] ?? 0) + 1;
            $w = (int) $check['weight'];
            if ($w <= 0) {
                continue;
            }
            $possible += $w;
            if ($check['status'] === 'good') {
                $earned += $w;
            } elseif ($check['status'] === 'warn') {
                $earned += $w * 0.5;
            }
        }
    }

    $score = $possible > 0 ? (int) round(($earned / $possible) * 100) : 0;

    return array(
        'groups' => $groups,
        'score'  => $score,
        'counts' => $counts,
        'grade'  => $score >= 90 ? 'good' : ($score >= 65 ? 'warn' : 'bad'),
    );
}
