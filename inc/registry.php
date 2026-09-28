<?php
/**
 * Site registry and update entitlement.
 *
 * CCM Tools is free for sites belonging to an active Click Click Media
 * customer. This file is the site's half of that arrangement: it registers the
 * domain with the update service and asks, periodically, whether this site is
 * still entitled to updates.
 *
 * What a block does, and just as importantly what it does not do:
 *
 *   It stops the site being offered new versions, and it says so plainly in
 *   wp-admin. It does not disable a single feature, remove a single file or
 *   change one byte of the site's behaviour. The .htaccess rules stay, the
 *   Redis drop-in stays, the converted images stay, every optimiser filter
 *   keeps running. A site whose entitlement lapses keeps working exactly as it
 *   did the day before, it simply stops improving.
 *
 * The service is never allowed to break this site. Every failure path here -
 * no network, DNS gone, a 500, a timeout, unparseable JSON, the whole service
 * deleted - results in the plugin carrying on silently as though nothing had
 * been asked. Entitlement is only ever revoked by an explicit, successfully
 * parsed answer saying so, which is why the last good answer is kept in an
 * option rather than only in a transient: a service outage must not be able to
 * produce a nag on 186 client sites at once.
 *
 * @package CCM_Tools
 * @since 8.10.0
 */

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

/**
 * Base URL of the update service.
 *
 * Overridable with a constant so a staging site can be pointed somewhere else
 * without touching the plugin.
 *
 * @return string
 */
function ccm_tools_registry_endpoint(): string {
    if (defined('CCM_TOOLS_UPDATE_API') && CCM_TOOLS_UPDATE_API) {
        return rtrim(CCM_TOOLS_UPDATE_API, '/');
    }
    return 'https://updates.clickclick.media';
}

/**
 * The last answer we successfully got, whatever its age.
 *
 * @return array{entitled:bool,notice:string,update:array|null,checked_at:int}|null
 */
function ccm_tools_registry_state() {
    $state = get_option(CCM_TOOLS_REGISTRY_OPTION);
    return is_array($state) ? $state : null;
}

/**
 * Whether this site may be offered updates.
 *
 * Unknown means yes. A site we have never managed to ask about, or whose
 * answer we have lost, is treated as entitled: refusing on no information
 * would turn any outage into a fleet-wide block.
 *
 * @return bool
 */
function ccm_tools_registry_is_entitled(): bool {
    $state = ccm_tools_registry_state();
    if ($state === null || !isset($state['entitled'])) {
        return true;
    }
    return (bool) $state['entitled'];
}

/**
 * Ask the service about this site, honouring the cache.
 *
 * @param bool $force Ignore the cache and the failure backoff.
 * @return array|null The parsed answer, or null if we could not get one.
 */
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
        return $state; // whatever we knew before, unchanged
    }

    $parsed = json_decode(wp_remote_retrieve_body($response), true);
    if (!is_array($parsed) || !array_key_exists('entitled', $parsed)) {
        set_transient(CCM_TOOLS_REGISTRY_BACKOFF, 1, CCM_TOOLS_REGISTRY_RETRY);
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

    return $new;
}

/**
 * The update on offer, if any.
 *
 * @return array|null
 */
function ccm_tools_registry_update_info() {
    $state = ccm_tools_registry_check();
    if (!is_array($state) || empty($state['update']) || !is_array($state['update'])) {
        return null;
    }

    $update = $state['update'];
    if (empty($update['version']) || empty($update['package'])) {
        return null;
    }

    return $update;
}

/**
 * Register the site the moment the plugin is switched on, rather than waiting
 * for the first scheduled update check.
 */
function ccm_tools_registry_on_activate(): void {
    delete_transient(CCM_TOOLS_REGISTRY_BACKOFF);
    ccm_tools_registry_check(true);
}

/**
 * Tell an administrator, once they are somewhere it makes sense to read it,
 * that this site is no longer being offered updates and what to do about it.
 *
 * Only on the plugin's own screens and the Plugins screen: a notice on every
 * admin page of a site we no longer have a relationship with would be nagging,
 * and the point is to be informative rather than annoying.
 */
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

/**
 * Surface the same thing on the Plugins screen row, where someone looking at
 * versions will actually be looking.
 *
 * @param array  $plugin_meta
 * @param string $plugin_file
 * @return array
 */
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
