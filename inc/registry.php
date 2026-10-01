<?php
/* 5fa835e72bb6f048 */

if (!defined('ABSPATH')) {
    exit;
}

/** How long a good answer is trusted before asking again. */
if (!defined('CCM_TOOLS_REGISTRY_TTL')) {
    define('CCM_TOOLS_REGISTRY_TTL', 12 * HOUR_IN_SECONDS);
}

/** How long to wait after a failed attempt before trying again. */
if (!defined('CCM_TOOLS_REGISTRY_RETRY')) {
    define('CCM_TOOLS_REGISTRY_RETRY', 2 * HOUR_IN_SECONDS);
}

const CCM_TOOLS_REGISTRY_OPTION   = 'ccm_tools_registry_state';
const CCM_TOOLS_REGISTRY_BACKOFF  = 'ccm_tools_registry_backoff';
const CCM_TOOLS_REGISTRY_LAST     = 'ccm_tools_registry_last_attempt';

/* bf07fa05f5935711 */
function ccm_tools_registry_note_attempt(bool $ok, string $detail = ''): void {
    update_option(CCM_TOOLS_REGISTRY_LAST, array(
        'at'     => time(),
        'ok'     => $ok,
        'detail' => $detail,
    ), false);
}

/* a3950b52e10e06e0 */
function ccm_tools_registry_last_attempt() {
    $last = get_option(CCM_TOOLS_REGISTRY_LAST);
    return is_array($last) ? $last : null;
}

/* bbe7ece414ad7104 */
function ccm_tools_registry_is_degraded(): bool {
    $state = ccm_tools_registry_state();
    if ($state === null) {
        return true;   // never had an answer at all
    }

    $last = ccm_tools_registry_last_attempt();
    if ($last !== null) {
        return empty($last['ok']);
    }

    /* afa852c1b31754cd */
    $checked_at = isset($state['checked_at']) ? (int) $state['checked_at'] : 0;
    if ($checked_at <= 0) {
        return true;
    }

    return (time() - $checked_at) > (2 * CCM_TOOLS_REGISTRY_TTL);
}

/* 56970e44a7d0385d */
function ccm_tools_registry_last_checked_at(): int {
    $last = ccm_tools_registry_last_attempt();
    if ($last !== null && !empty($last['at'])) {
        return (int) $last['at'];
    }
    $state = ccm_tools_registry_state();
    return is_array($state) && !empty($state['checked_at']) ? (int) $state['checked_at'] : 0;
}

/* aff9b1c288c5951a */
function ccm_tools_registry_endpoint(): string {
    if (defined('CCM_TOOLS_UPDATE_API') && CCM_TOOLS_UPDATE_API) {
        return rtrim(CCM_TOOLS_UPDATE_API, '/');
    }
    return 'https://updates.clickclick.media';
}

/* fcda3ef50b11790e */
function ccm_tools_registry_state() {
    $state = get_option(CCM_TOOLS_REGISTRY_OPTION);
    return is_array($state) ? $state : null;
}

/* d94b15270de18cf0 */
function ccm_tools_registry_is_entitled(): bool {
    $state = ccm_tools_registry_state();
    if ($state === null || !isset($state['entitled'])) {
        return true;
    }
    return (bool) $state['entitled'];
}

/* 68d0855eadf14af8 */
function ccm_tools_registry_check(bool $force = false) {
    $state = ccm_tools_registry_state();

    if (!$force) {
        // A good answer is still fresh.
        if ($state !== null && isset($state['checked_at'])
            && (time() - (int) $state['checked_at']) < CCM_TOOLS_REGISTRY_TTL) {
            return $state;
        }
        // A recent attempt failed; do not hammer a service that is down.
        if (get_transient(CCM_TOOLS_REGISTRY_BACKOFF)) {
            return $state;
        }
    }

    $body = array(
        'home_url'  => home_url(),
        'site_name' => get_bloginfo('name'),
        'version'   => defined('CCM_HELPER_VERSION') ? CCM_HELPER_VERSION : '0.0.0',
        'wp'        => get_bloginfo('version'),
        'php'       => PHP_VERSION,
        'multisite' => is_multisite(),
    );

    $response = wp_remote_post(ccm_tools_registry_endpoint() . '/v1/check', array(
        'timeout'     => 8,
        'redirection' => 2,
        'headers'     => array(
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        ),
        'body'        => wp_json_encode($body),
        // The site's own identity is in the payload; no cookies, no auth.
        'cookies'     => array(),
    ));

    if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
        set_transient(CCM_TOOLS_REGISTRY_BACKOFF, 1, CCM_TOOLS_REGISTRY_RETRY);
        ccm_tools_registry_note_attempt(false, is_wp_error($response)
            ? $response->get_error_message()
            : 'HTTP ' . (int) wp_remote_retrieve_response_code($response));
        return $state; // whatever we knew before, unchanged
    }

    $parsed = json_decode(wp_remote_retrieve_body($response), true);
    if (!is_array($parsed) || !array_key_exists('entitled', $parsed)) {
        set_transient(CCM_TOOLS_REGISTRY_BACKOFF, 1, CCM_TOOLS_REGISTRY_RETRY);
        ccm_tools_registry_note_attempt(false, 'the reply was not an answer we understood');
        return $state;
    }

    $new = array(
        'entitled'   => (bool) $parsed['entitled'],
        'notice'     => isset($parsed['notice']) && is_string($parsed['notice']) ? $parsed['notice'] : '',
        'update'     => isset($parsed['update']) && is_array($parsed['update']) ? $parsed['update'] : null,
        'checked_at' => time(),
    );

    delete_transient(CCM_TOOLS_REGISTRY_BACKOFF);
    update_option(CCM_TOOLS_REGISTRY_OPTION, $new, false);
    ccm_tools_registry_note_attempt(true, $new['entitled']
        ? ($new['update'] ? 'offered ' . $new['update']['version'] : 'up to date')
        : 'not entitled');

    return $new;
}

/* 9956f7cfdbade4d2 */
function ccm_tools_registry_update_info() {
    $state = ccm_tools_registry_check();
    if (!is_array($state) || empty($state['update']) || !is_array($state['update'])) {
        return null;
    }

    $update = $state['update'];
    if (empty($update['version']) || empty($update['package'])) {
        return null;
    }

    /* f0223027cfeb33e1 */
    if (!ccm_tools_registry_package_is_ours((string) $update['package'])) {
        return null;
    }

    return $update;
}

/* 66e9c2a9583235af */
function ccm_tools_registry_invalidate(): void {
    delete_transient(CCM_TOOLS_REGISTRY_BACKOFF);

    $state = ccm_tools_registry_state();
    if (is_array($state)) {
        $state['checked_at'] = 0;
        update_option(CCM_TOOLS_REGISTRY_OPTION, $state, false);
    }
}

/* 34d0bc95fb2b370d */
function ccm_tools_registry_package_is_ours(string $url): bool {
    if ($url === '') {
        return false;
    }

    $parts = wp_parse_url($url);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return false;
    }
    if (strtolower($parts['scheme']) !== 'https') {
        return false;
    }

    $ours = strtolower((string) wp_parse_url(ccm_tools_registry_endpoint(), PHP_URL_HOST));
    return $ours !== '' && strtolower($parts['host']) === $ours;
}

/* f33efb3ca0cf27c7 */
function ccm_tools_registry_on_activate(): void {
    delete_transient(CCM_TOOLS_REGISTRY_BACKOFF);
    ccm_tools_registry_check(true);
}

/* 5b54bb9a0a7bbdaa */
function ccm_tools_registry_admin_notice(): void {
    if (!function_exists('ccm_tools_user_is_admin') || !ccm_tools_user_is_admin()) {
        return;
    }

    $state = ccm_tools_registry_state();
    if ($state === null || !empty($state['entitled'])) {
        return;
    }

    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    $on_plugins = $screen && isset($screen->id) && ($screen->id === 'plugins' || $screen->id === 'plugins-network');
    $on_ours    = isset($_GET['page']) && strpos((string) $_GET['page'], 'ccm-tools') === 0;

    if (!$on_plugins && !$on_ours) {
        return;
    }

    $message = !empty($state['notice'])
        ? $state['notice']
        : __('Updates for CCM Tools are included with any active Click Click Media service. The plugin will keep working exactly as it is.', 'ccm-tools');

    printf(
        '<div class="notice notice-info"><p><strong>%s</strong> %s</p></div>',
        esc_html__('CCM Tools is not currently receiving updates.', 'ccm-tools'),
        esc_html($message)
    );
}

/* 037cf1bf63a693ab */
function ccm_tools_registry_plugin_row_meta(array $plugin_meta, string $plugin_file): array {
    if (!defined('CCM_HELPER_BASENAME') || $plugin_file !== CCM_HELPER_BASENAME) {
        return $plugin_meta;
    }
    if (ccm_tools_registry_is_entitled()) {
        return $plugin_meta;
    }

    $plugin_meta[] = '<span style="color:#996800;">'
        . esc_html__('Updates paused - included with any active CCM service', 'ccm-tools')
        . '</span>';

    return $plugin_meta;
}

/* 18fc9f734e5814ed */
function ccm_tools_registry_render_panel(): void {
    if (!function_exists('ccm_tools_user_is_admin') || !ccm_tools_user_is_admin()) {
        return;
    }

    $state    = ccm_tools_registry_state();
    $last     = ccm_tools_registry_last_attempt();
    $degraded = ccm_tools_registry_is_degraded();
    $entitled = ccm_tools_registry_is_entitled();

    $fallback_on = !defined('CCM_TOOLS_GITHUB_FALLBACK') || CCM_TOOLS_GITHUB_FALLBACK;

    // Which source a check right now would actually use.
    if (!$entitled) {
        $source      = __('Service (refused)', 'ccm-tools');
        $source_tone = 'warn';
    } elseif (!$degraded) {
        $source      = __('Update service', 'ccm-tools');
        /* 5fcf3ca388c129f2 */
        $source_tone = 'good';
    } elseif ($fallback_on) {
        $source      = __('GitHub (fallback)', 'ccm-tools');
        $source_tone = 'warn';
    } else {
        $source      = __('None reachable', 'ccm-tools');
        $source_tone = 'bad';
    }

    $checked_at = ccm_tools_registry_last_checked_at();
    $when = $checked_at > 0
        ? sprintf(
            /* translators: %s: human time difference, e.g. "3 mins" */
            __('%s ago', 'ccm-tools'),
            human_time_diff($checked_at, time())
        )
        : __('never', 'ccm-tools');

    $offered = ($state && !empty($state['update']['version']))
        ? (string) $state['update']['version']
        : __('nothing newer', 'ccm-tools');
    ?>
    <section class="ccm-optgroup" id="ccm-update-channel">
        <header class="ccm-optgroup__head">
            <div>
                <h2 class="ccm-optgroup__title"><?php _e('Update channel', 'ccm-tools'); ?></h2>
                <p class="ccm-optgroup__note">
                    <?php _e('Where this site gets its updates, and what the service last said about it.', 'ccm-tools'); ?>
                </p>
            </div>
            <span class="ccm-chip ccm-chip--<?php echo esc_attr($source_tone); ?>">
                <?php echo esc_html($source); ?>
            </span>
        </header>
        <div class="ccm-optgroup__body ccm-panel__body">
            <div class="ccm-fieldgrid">
                <div class="ccm-optfield">
                    <span class="ccm-opt__label"><?php _e('Entitled to updates', 'ccm-tools'); ?></span>
                    <p class="ccm-opt__desc"><?php echo $entitled
                        ? esc_html__('Yes', 'ccm-tools')
                        : esc_html__('No - the plugin keeps working, it just stops being offered new versions.', 'ccm-tools'); ?></p>
                </div>
                <div class="ccm-optfield">
                    <span class="ccm-opt__label"><?php _e('Service reachable', 'ccm-tools'); ?></span>
                    <p class="ccm-opt__desc"><?php echo $degraded
                        ? esc_html__('No - falling back to GitHub while this lasts.', 'ccm-tools')
                        : esc_html__('Yes', 'ccm-tools'); ?></p>
                </div>
                <div class="ccm-optfield">
                    <span class="ccm-opt__label"><?php _e('Last checked', 'ccm-tools'); ?></span>
                    <p class="ccm-opt__desc">
                        <?php echo esc_html($when); ?>
                        <?php if ($last && !empty($last['detail'])) : ?>
                            &mdash; <?php echo esc_html($last['detail']); ?>
                        <?php endif; ?>
                    </p>
                </div>
                <div class="ccm-optfield">
                    <span class="ccm-opt__label"><?php _e('Version on offer', 'ccm-tools'); ?></span>
                    <p class="ccm-opt__desc"><?php echo esc_html($offered); ?></p>
                </div>
                <div class="ccm-optfield ccm-fieldgrid__wide">
                    <span class="ccm-opt__label"><?php _e('Endpoint', 'ccm-tools'); ?></span>
                    <p class="ccm-opt__desc ccm-mono"><?php echo esc_html(ccm_tools_registry_endpoint()); ?></p>
                </div>
            </div>

            <div class="ccm-row" style="margin-top: var(--ccm-space-md);">
                <button type="button" id="ccm-registry-recheck" class="ccm-button ccm-button-secondary ccm-button-small">
                    <?php _e('Check now', 'ccm-tools'); ?>
                </button>
                <span class="ccm-text-muted" style="font-size: var(--ccm-text-sm);">
                    <?php _e('Asks the service again straight away instead of waiting for the twelve-hour cycle.', 'ccm-tools'); ?>
                </span>
            </div>
            <div id="ccm-registry-recheck-result" class="ccm-result-box" style="display: none;"></div>
        </div>
    </section>
    <?php
}

/* 3a0658fe48dafa8d */
function ccm_tools_ajax_registry_recheck(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    if (!ccm_tools_user_is_admin()) {
        wp_send_json_error(array('message' => __('You do not have permission to do that.', 'ccm-tools')));
    }

    delete_transient(CCM_TOOLS_REGISTRY_BACKOFF);
    ccm_tools_registry_check(true);

    $last     = ccm_tools_registry_last_attempt();
    $state    = ccm_tools_registry_state();
    $degraded = ccm_tools_registry_is_degraded();

    wp_send_json_success(array(
        'ok'       => $last ? (bool) $last['ok'] : false,
        'detail'   => $last ? (string) $last['detail'] : '',
        'entitled' => ccm_tools_registry_is_entitled(),
        'source'   => $degraded ? 'github' : 'service',
        'offered'  => ($state && !empty($state['update']['version'])) ? $state['update']['version'] : '',
        'message'  => $last && $last['ok']
            ? sprintf(__('Service answered: %s', 'ccm-tools'), (string) $last['detail'])
            : sprintf(__('Could not reach the service: %s', 'ccm-tools'), $last ? (string) $last['detail'] : __('unknown', 'ccm-tools')),
    ));
}
add_action('wp_ajax_ccm_tools_registry_recheck', 'ccm_tools_ajax_registry_recheck');
