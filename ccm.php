<?php
/**
 * Plugin Name: CCM Tools
 * Plugin URI: https://clickclickmedia.com.au/
 * Description: CCM Tools is a WordPress utility plugin that helps administrators monitor and optimize their WordPress installation. It provides system information, database tools, and .htaccess optimization features.
 * Version: 8.2.0
 * Requires at least: 6.0
 * Tested up to: 6.8.2
 * Requires PHP: 7.4
 * Author: Click Click Media
 * Author URI: https://clickclickmedia.com.au/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ccm-tools
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Bail if this file is being included a second time within the same request.
// WP-Cron auto-updates silently deactivate the plugin via the upgrader's
// `active_before` filter, extract new files, then call `activate_plugin()` to
// re-activate. That triggers `plugin_sandbox_scrape()` which does a raw
// `include` of this file — but the OLD copy was already loaded at the start of
// the request, so the unguarded global function/class definitions below would
// fatal with "Cannot redeclare …". WP's fatal handler then catches it, pauses
// the plugin, and shows a generic error with no entry in debug.log unless
// WP_DEBUG_LOG is on. The sentinel below cleanly short-circuits the second
// include so the activation completes without the fatal.
if (defined('CCM_TOOLS_FILE_LOADED')) {
    return;
}
define('CCM_TOOLS_FILE_LOADED', true);

// Define plugin constants only if they don't already exist
if (!defined('CCM_HELPER_VERSION')) {
    define('CCM_HELPER_VERSION', '8.2.0');
}

// Better duplicate detection mechanism that only checks active plugins
$ccm_is_duplicate = false;

/**
 * Convert a PHP ini size string to bytes.
 *
 * Declared at file scope. It used to be nested inside create_dashboard_page(),
 * which meant it only existed once execution had passed its declaration part
 * way down that method: anything earlier on the page that called it died with
 * "call to undefined function".
 *
 * Returns -1 unchanged for an unlimited memory_limit, since -1 is numeric.
 *
 * @param string|int $size e.g. "512M", "1G", "-1".
 * @return int Bytes.
 */
if (!function_exists('ccm_tools_convert_php_size_to_bytes')) {
    function ccm_tools_convert_php_size_to_bytes($size) {
        if (is_numeric($size)) {
            return (int) $size;
        }

        $size = trim((string) $size);
        if ($size === '') {
            return 0;
        }

        $last = strtolower($size[strlen($size) - 1]);
        $size = (int) $size;

        // Deliberate fall-through: G multiplies three times, M twice, K once.
        switch ($last) {
            case 'g':
                $size *= 1024;
                // no break
            case 'm':
                $size *= 1024;
                // no break
            case 'k':
                $size *= 1024;
        }

        return $size;
    }
}

if (!function_exists('ccm_check_for_duplicates')) {
    function ccm_check_for_duplicates() {
        // Use get_option() directly — do NOT apply_filters('active_plugins')
        // here because this runs at file-include time (before plugins_loaded).
        // Firing that filter can trigger theme resolution in other plugins,
        // causing a fatal "tried to allocate 4 GB" in theme.php.
        $active_plugins = (array) get_option('active_plugins', array());
        $current_plugin = plugin_basename(__FILE__);
        
        foreach ($active_plugins as $plugin) {
            if (strpos($plugin, 'ccm.php') !== false && $plugin !== $current_plugin) {
                return true;
            }
        }
        return false;
    }
}

// Check if there's another active instance of CCM Tools
$ccm_is_duplicate = ccm_check_for_duplicates();

// Suppress all admin notices on CCM Tools pages
add_action('admin_notices', 'ccm_tools_hide_all_notices', 0);

/**
 * Remove all admin notices on CCM Tools pages
 * This ensures a clean interface without WordPress notifications
 */
function ccm_tools_hide_all_notices() {
    global $plugin_page;
    
    // Only remove notices on CCM Tools pages
    if ($plugin_page && strpos($plugin_page, 'ccm-tools') === 0) {
        remove_all_actions('admin_notices');
        remove_all_actions('all_admin_notices');
        add_action('admin_notices', 'ccm_tools_custom_admin_notices');
        add_action('all_admin_notices', 'ccm_tools_custom_admin_notices');
    }
}

/**
 * Add back only CCM Tools specific notices
 * Allows our plugin to still show its own notifications
 */
function ccm_tools_custom_admin_notices() {
    // Duplicate installs are now resolved automatically (the canonical
    // /ccm-tools/ install is kept and version-suffixed copies are removed), so
    // the old "please deactivate the other instance" warning is no longer shown.
    // This hook remains as the single allowed admin_notices callback on CCM
    // Tools pages after remove_all_actions(); intentionally left as a no-op.
}

// ── Resolve duplicate installs ───────────────────────────────────────────
// A manual upload of a version-suffixed release zip (e.g. ccm-tools-7.42.0.zip)
// makes WordPress create wp-content/plugins/ccm-tools-7.42.0/ ALONGSIDE the
// canonical ccm-tools/. Two active copies used to make this plugin deactivate
// ITSELF (frequently the good one — whichever loaded first), which is the
// "plugin deactivated itself after update" symptom. Instead: always keep the
// canonical /ccm-tools/ install and clean up the version-suffixed duplicate(s).
if ($ccm_is_duplicate) {
    $ccm_current_dir = dirname(plugin_basename(__FILE__));

    if ($ccm_current_dir === 'ccm-tools') {
        // We are the canonical install — stay active and remove the duplicates.
        add_action('admin_init', 'ccm_tools_cleanup_duplicate_installs');
    } elseif (file_exists(WP_PLUGIN_DIR . '/ccm-tools/ccm.php')) {
        // We are a version-suffixed copy and the canonical exists (checking
        // file_exists() on ccm.php itself, not just is_dir() on the folder —
        // a leftover EMPTY ccm-tools/ directory from a failed upgrade used to
        // satisfy is_dir() and fall into this branch with no working
        // canonical to hand off to). Make sure the canonical is active, stand
        // down quietly (silent = NO teardown hooks), and stop loading. The
        // canonical instance deletes our folder on its next admin load.
        add_action('admin_init', function () {
            if (!function_exists('activate_plugin') || !function_exists('is_plugin_active')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $canonical_active = is_plugin_active('ccm-tools/ccm.php');
            if (!$canonical_active) {
                $activate_result = activate_plugin('ccm-tools/ccm.php');
                $canonical_active = !is_wp_error($activate_result) && is_plugin_active('ccm-tools/ccm.php');
            }
            // Only self-deactivate once the canonical copy is confirmed
            // active. Previously activate_plugin()'s WP_Error return was
            // silenced with @ and ignored, so a broken canonical install
            // (e.g. that empty leftover folder) plus a failed activation
            // still deactivated this — the only working — copy, making the
            // plugin disappear entirely.
            if ($canonical_active) {
                deactivate_plugins(plugin_basename(__FILE__), true); // silent: no deactivation hooks
            }
        });
        return; // stop loading this duplicate copy
    } else {
        // Version-suffixed copy with NO canonical present — it is the only
        // install, so keep running (never self-delete) and just warn the admin
        // to reinstall into /ccm-tools/.
        add_action('admin_notices', 'ccm_tools_nonstandard_folder_notice');
    }
}

/**
 * Remove version-suffixed duplicate CCM Tools folders (e.g. ccm-tools-7.42.0)
 * left behind by a manual zip upload. Runs from the canonical /ccm-tools/
 * install only. Deactivates duplicates silently (no teardown) and deletes the
 * folder via the filesystem API (no uninstall hooks), so shared options, the
 * wp-config Redis block and the object-cache drop-in are never touched.
 */
function ccm_tools_cleanup_duplicate_installs() {
    if (!current_user_can('activate_plugins')) {
        return;
    }
    require_once ABSPATH . 'wp-admin/includes/plugin.php';

    $candidates = glob(WP_PLUGIN_DIR . '/ccm-tools-*', GLOB_ONLYDIR);
    if (empty($candidates)) {
        return;
    }

    $active  = (array) get_option('active_plugins', array());
    $removed = array();

    foreach ($candidates as $dir) {
        $base = basename($dir);
        if ($base === 'ccm-tools') {
            continue; // never the canonical install
        }
        $main = $dir . '/ccm.php';
        if (!file_exists($main)) {
            continue;
        }
        // Safety: only remove folders that are genuinely a CCM Tools copy.
        $data = get_plugin_data($main, false, false);
        if (empty($data['Name']) || stripos($data['Name'], 'CCM Tools') === false) {
            continue;
        }

        $entry = $base . '/ccm.php';
        if (in_array($entry, $active, true)) {
            deactivate_plugins($entry, true); // silent — no teardown
        }

        if (!function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        WP_Filesystem();
        global $wp_filesystem;
        if ($wp_filesystem && $wp_filesystem->is_dir($dir)) {
            $wp_filesystem->delete($dir, true);
            $removed[] = $base;
        }
    }

    if (!empty($removed)) {
        set_transient('ccm_tools_removed_duplicates', $removed, 600);
    }
}

/**
 * Notice shown after duplicate folders are cleaned up.
 */
add_action('admin_notices', 'ccm_tools_duplicate_cleanup_notice');
function ccm_tools_duplicate_cleanup_notice() {
    if (!current_user_can('activate_plugins')) {
        return;
    }
    $removed = get_transient('ccm_tools_removed_duplicates');
    if (empty($removed)) {
        return;
    }
    delete_transient('ccm_tools_removed_duplicates');
    echo '<div class="notice notice-success is-dismissible"><p><strong>CCM Tools:</strong> ';
    printf(
        /* translators: %s: list of removed plugin folder names */
        esc_html__('Removed duplicate plugin folder(s) left by a manual install: %s. The canonical install at /wp-content/plugins/ccm-tools/ is active.', 'ccm-tools'),
        esc_html(implode(', ', (array) $removed))
    );
    echo '</p></div>';
}

/**
 * Notice shown when CCM Tools is running from a non-standard (version-suffixed)
 * folder with no canonical /ccm-tools/ present.
 */
function ccm_tools_nonstandard_folder_notice() {
    if (!current_user_can('activate_plugins')) {
        return;
    }
    echo '<div class="notice notice-warning is-dismissible"><p><strong>CCM Tools:</strong> ';
    printf(
        /* translators: %s: current plugin folder name */
        esc_html__('CCM Tools is installed in a non-standard folder (%s) instead of "ccm-tools". This can cause update and activation problems. Please reinstall CCM Tools so it lives in /wp-content/plugins/ccm-tools/.', 'ccm-tools'),
        esc_html(dirname(plugin_basename(__FILE__)))
    );
    echo '</p></div>';
}

// Define plugin constants only if they don't already exist
if (!defined('CCM_HELPER_ROOT_DIR')) {
    define('CCM_HELPER_ROOT_DIR', plugin_dir_path(__FILE__));
}

if (!defined('CCM_HELPER_ROOT_PATH')) {
    define('CCM_HELPER_ROOT_PATH', plugin_dir_path(__FILE__));
}

if (!defined('CCM_HELPER_ROOT_URL')) {
    define('CCM_HELPER_ROOT_URL', plugin_dir_url(__FILE__));
}

/* ────────────────────────────────────────────────────────────────
 *  One-time cleanup: dead data left behind by the Premium and AI
 *  Performance Hub modules (inc/premium.php, inc/ai-hub.php), both
 *  removed entirely — every formerly-premium feature is now standard.
 *  Guarded by the 'ccm_tools_cleanup_version' option so it only does
 *  its work once per site no matter how many times admin_init fires.
 * ──────────────────────────────────────────────────────────────── */
add_action('admin_init', 'ccm_tools_cleanup_removed_modules');

function ccm_tools_cleanup_removed_modules() {
    // Identifier for this cleanup pass — not tied to the plugin version, just
    // bumped if a future cleanup pass needs to run again.
    $cleanup_marker = 'premium-ai-hub-removal-1';

    if (!current_user_can('manage_options')) {
        return;
    }
    if (get_option('ccm_tools_cleanup_version') === $cleanup_marker) {
        return;
    }

    // Options the removed AI Performance Hub / Premium modules used to own.
    delete_option('ccm_tools_ai_hub_settings');
    delete_option('ccm_tools_ai_optimization_runs');
    delete_option('ccm_tools_perf_snapshot');
    delete_option('ccm_tools_ai_known_bad');

    // Transients the same modules used to own.
    delete_transient('ccm_tools_premium_status');
    delete_transient('ccm_tools_premium_details');
    delete_transient('ccm_tools_premium_pricing');
    delete_transient('ccm_tools_perf_snapshot');

    // ccm_tools_perf_settings survives (it's not premium/AI-specific), but it
    // had a 'warn_dom_size' key that belonged to the removed AI Hub — drop
    // just that key and keep the rest of the option intact.
    $perf_settings = get_option('ccm_tools_perf_settings');
    if (is_array($perf_settings) && array_key_exists('warn_dom_size', $perf_settings)) {
        unset($perf_settings['warn_dom_size']);
        update_option('ccm_tools_perf_settings', $perf_settings);
    }

    update_option('ccm_tools_cleanup_version', $cleanup_marker);
}

/* ────────────────────────────────────────────────────────────────
 *  Redis drop-in lifecycle (activation / deactivation)
 *
 *  Registered against the MAIN plugin file so they fire on genuine
 *  user-initiated (de)activation. WordPress deactivates SILENTLY during
 *  plugin updates ($silent = true), so these do NOT run mid-update — the
 *  drop-in is instead refreshed by upgrader_process_complete / admin_init
 *  in inc/redis-object-cache.php.
 * ──────────────────────────────────────────────────────────────── */
register_activation_hook(__FILE__, 'ccm_tools_on_activate');
register_deactivation_hook(__FILE__, 'ccm_tools_on_deactivate');

/**
 * On (re)activation: if Redis was previously enabled, make sure the deployed
 * drop-in is present and current. Covers the update deactivate→reactivate
 * dance leaving a stale or missing drop-in.
 */
function ccm_tools_on_activate() {
    require_once CCM_HELPER_ROOT_DIR . 'inc/redis-object-cache.php';
    if (!function_exists('ccm_tools_redis_get_settings') || !function_exists('ccm_tools_redis_refresh_dropin')) {
        return;
    }
    $settings = ccm_tools_redis_get_settings();
    if (!empty($settings['enabled'])) {
        ccm_tools_redis_refresh_dropin(true); // install if missing, refresh if outdated
    }
}

/**
 * On deactivation: remove our object-cache.php drop-in and strip the managed
 * Redis block from wp-config.php (clean teardown). Only touches files we own.
 */
function ccm_tools_on_deactivate() {
    require_once CCM_HELPER_ROOT_DIR . 'inc/redis-object-cache.php';
    if (function_exists('ccm_tools_redis_uninstall_dropin')) {
        ccm_tools_redis_uninstall_dropin(); // removes drop-in (ours only) + strips wp-config
    }
    // Belt-and-braces: ensure the wp-config block is gone even if the drop-in
    // was already absent (uninstall_dropin early-returns in that case).
    if (function_exists('ccm_tools_redis_remove_config')) {
        ccm_tools_redis_remove_config();
    }
}

// IMPORTANT: Load text domain only on init hook to avoid "too early" warnings
add_action('init', 'ccmtools_load_textdomain');

/**
 * Load plugin text domain for translations
 */
function ccmtools_load_textdomain() {
    load_plugin_textdomain('ccm-tools', false, dirname(plugin_basename(__FILE__)) . '/languages');
}


// Main plugin initialization - AFTER PLUGINS_LOADED HOOK
add_action('plugins_loaded', 'ccm_initialize_plugin', 10);

/**
 * Initialize the plugin after all plugins are loaded
 * This prevents early initialization issues
 *
 * NOTE: This used to skip loading entirely on REST API requests as a minor
 * performance optimisation. That short-circuit was removed: it matched the
 * REST prefix against the raw REQUEST_URI (including the query string), so a
 * request like /checkout/?x=/wp-json/ was misdetected as a REST request and
 * skipped ALL 12 includes — including inc/woocommerce-tools.php, which is
 * what enforces the admin-only COD/BACS restriction. That let an unpaid order
 * be placed on any request crafted to look like a REST hit, and the real
 * WooCommerce Store API endpoints defeated it with no trick at all. Loading
 * the includes unconditionally (they're cheap under OPcache) closes that gap.
 */
function ccm_initialize_plugin() {
    define('CCM_TOOLS_INITIALIZING', true);
    
    // Load core files
    require_once CCM_HELPER_ROOT_DIR . 'inc/system-info.php';
    require_once CCM_HELPER_ROOT_DIR . 'inc/htaccess.php';
    require_once CCM_HELPER_ROOT_DIR . 'inc/optimize.php';
    require_once CCM_HELPER_ROOT_DIR . 'inc/tableconverter.php';
    require_once CCM_HELPER_ROOT_DIR . 'inc/ajax-handlers.php';
    require_once CCM_HELPER_ROOT_DIR . 'inc/error-log.php'; // Add the new error log file
    require_once CCM_HELPER_ROOT_DIR . 'inc/update.php';  // Add GitHub update functionality
    require_once CCM_HELPER_ROOT_DIR . 'inc/woocommerce-tools.php'; // Add WooCommerce tools
    require_once CCM_HELPER_ROOT_DIR . 'inc/webp-converter.php'; // Add WebP image converter
    require_once CCM_HELPER_ROOT_DIR . 'inc/performance-catalogue.php'; // setting definitions
    require_once CCM_HELPER_ROOT_DIR . 'inc/performance-optimizer.php';
    require_once CCM_HELPER_ROOT_DIR . 'inc/redis-object-cache.php'; // Add Redis Object Cache
    require_once CCM_HELPER_ROOT_DIR . 'inc/cloudflare.php'; // Cloudflare integration
    require_once CCM_HELPER_ROOT_DIR . 'inc/site-health.php'; // PageSpeed Insights reporting
    
    // Initialize plugin settings
    global $ccm_tools;
    $ccm_tools = new CCMSettings();
}

/**
 * Render the CCM Tools header navigation menu
 * 
 * @param string $active_page The current active page slug (e.g., 'ccm-tools', 'ccm-tools-database')
 * @return void
 */
function ccm_tools_render_header_nav($active_page = '') {
    $webp_available = function_exists('ccm_tools_webp_is_available') && ccm_tools_webp_is_available();
    $redis_available = function_exists('ccm_tools_redis_extension_available') && ccm_tools_redis_extension_available();
    $woocommerce_active = class_exists('WooCommerce');
    $cf_available = function_exists('ccm_tools_render_cloudflare_page');
    ?>
    <div class="ccm-header">
        <div class="ccm-header-logo">
            <a href="<?php echo esc_url(admin_url('admin.php?page=ccm-tools')); ?>">
                <img src="<?php echo esc_url(CCM_HELPER_ROOT_URL); ?>img/logo.svg" alt="CCM Tools">
            </a>
        </div>
        <nav class="ccm-header-menu">
            <div class="ccm-tabs">
                <a href="<?php echo esc_url(admin_url('admin.php?page=ccm-tools')); ?>" class="ccm-tab <?php echo $active_page === 'ccm-tools' ? 'active' : ''; ?>"><?php _e('System Info', 'ccm-tools'); ?></a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=ccm-tools-database')); ?>" class="ccm-tab <?php echo $active_page === 'ccm-tools-database' ? 'active' : ''; ?>"><?php _e('Database', 'ccm-tools'); ?></a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=ccm-tools-htaccess')); ?>" class="ccm-tab <?php echo $active_page === 'ccm-tools-htaccess' ? 'active' : ''; ?>"><?php _e('.htaccess', 'ccm-tools'); ?></a>
                <?php if ($redis_available): ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=ccm-tools-redis')); ?>" class="ccm-tab <?php echo $active_page === 'ccm-tools-redis' ? 'active' : ''; ?>"><?php _e('Redis', 'ccm-tools'); ?></a>
                <?php endif; ?>
                <?php if ($webp_available): ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=ccm-tools-webp')); ?>" class="ccm-tab <?php echo $active_page === 'ccm-tools-webp' ? 'active' : ''; ?>"><?php _e('WebP', 'ccm-tools'); ?></a>
                <?php endif; ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=ccm-tools-perf')); ?>" class="ccm-tab <?php echo $active_page === 'ccm-tools-perf' ? 'active' : ''; ?>"><?php _e('Performance', 'ccm-tools'); ?></a>
                <?php if ($cf_available): ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=ccm-tools-cloudflare')); ?>" class="ccm-tab <?php echo $active_page === 'ccm-tools-cloudflare' ? 'active' : ''; ?>"><?php _e('Cloudflare', 'ccm-tools'); ?></a>
                <?php endif; ?>
                <?php if ($woocommerce_active): ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=ccm-tools-woocommerce')); ?>" class="ccm-tab <?php echo $active_page === 'ccm-tools-woocommerce' ? 'active' : ''; ?>"><?php _e('WooCommerce', 'ccm-tools'); ?></a>
                <?php endif; ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=ccm-tools-site-health')); ?>" class="ccm-tab <?php echo $active_page === 'ccm-tools-site-health' ? 'active' : ''; ?>"><?php _e('Site Health', 'ccm-tools'); ?></a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=ccm-tools-error-log')); ?>" class="ccm-tab <?php echo $active_page === 'ccm-tools-error-log' ? 'active' : ''; ?>"><?php _e('Error Log', 'ccm-tools'); ?></a>
            </div>
        </nav>
        <div class="ccm-header-title">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <button type="button" class="ccm-theme-toggle" aria-label="<?php esc_attr_e('Switch between light and dark', 'ccm-tools'); ?>" title="<?php esc_attr_e('Switch between light and dark', 'ccm-tools'); ?>">
                <svg class="ccm-icon-moon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8Z"/></svg>
                <svg class="ccm-icon-sun" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
            </button>
        </div>
    </div>
    <?php
}

/**
 * Main plugin settings class
 */
class CCMSettings {
    public function __construct() {
        // Add admin hooks - admin_menu is called after init, so it's safe
        add_action('admin_menu', array($this, 'add_plugin_page'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('admin_head', array($this, 'print_theme_stamp'), 1);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_action_links'));
        
        // Add Front Page prioritization hooks
        add_action('admin_init', array($this, 'init_front_page_prioritization'));
        add_filter('post_row_actions', array($this, 'add_front_page_indicator'), 10, 2);
        add_action('admin_head', array($this, 'add_front_page_admin_styles'));
    }
    
    public function add_plugin_page(): void {
        // Main menu
        add_menu_page(
            'CCM Tools',
            'CCM Tools',
            'manage_options',
            'ccm-tools',
            array($this, 'create_dashboard_page'),
            'dashicons-admin-tools',
            100
        );
        
        // Submenu pages
        add_submenu_page(
            'ccm-tools',
            'Dashboard',
            'Dashboard',
            'manage_options',
            'ccm-tools',
            array($this, 'create_dashboard_page')
        );
        
        add_submenu_page(
            'ccm-tools',
            'Database Tools',
            'Database Tools',
            'manage_options',
            'ccm-tools-database',
            array($this, 'create_database_page')
        );
        
        add_submenu_page(
            'ccm-tools',
            '.htaccess Tools',
            '.htaccess Tools',
            'manage_options',
            'ccm-tools-htaccess',
            array($this, 'create_htaccess_page')
        );
        
        // Add Redis Object Cache submenu (only if Redis extension is available)
        if (function_exists('ccm_tools_redis_extension_available') && ccm_tools_redis_extension_available()) {
            add_submenu_page(
                'ccm-tools',
                'Redis Cache',
                'Redis Cache',
                'manage_options',
                'ccm-tools-redis',
                'ccm_tools_render_redis_page'
            );
        }
        
        // Add WebP Converter submenu (only if image extension is available)
        if (function_exists('ccm_tools_webp_is_available') && ccm_tools_webp_is_available()) {
            add_submenu_page(
                'ccm-tools',
                'WebP Converter',
                'WebP Converter',
                'manage_options',
                'ccm-tools-webp',
                'ccm_tools_render_webp_page'
            );
        }
        
        // Add Performance Optimizer submenu
        add_submenu_page(
            'ccm-tools',
            'Performance',
            'Performance',
            'manage_options',
            'ccm-tools-perf',
            'ccm_tools_render_perf_page'
        );
        
        // Add WooCommerce Tools submenu (only if WooCommerce is active)
        if (class_exists('WooCommerce')) {
            add_submenu_page(
                'ccm-tools',
                'WooCommerce Tools',
                'WooCommerce Tools',
                'manage_options',
                'ccm-tools-woocommerce',
                array($this, 'create_woocommerce_page')
            );
        }
        
        // Add Cloudflare submenu (always registered — page handles detection)
        if (function_exists('ccm_tools_render_cloudflare_page')) {
            add_submenu_page(
                'ccm-tools',
                'Cloudflare',
                'Cloudflare',
                'manage_options',
                'ccm-tools-cloudflare',
                'ccm_tools_render_cloudflare_page'
            );
        }

        // Add Site Health submenu (PageSpeed Insights)
        add_submenu_page(
            'ccm-tools',
            'Site Health',
            'Site Health',
            'manage_options',
            'ccm-tools-site-health',
            'ccm_tools_render_site_health_page'
        );

        // Add Error Log submenu
        add_submenu_page(
            'ccm-tools',
            'Error Log',
            'Error Log',
            'manage_options',
            'ccm-tools-error-log',
            'ccm_tools_render_error_log_page'
        );
        
        // Add debug submenu if debug mode is enabled
        if (defined('CCM_DEBUG_FRONT_PAGE')) {
            add_submenu_page(
                'ccm-tools',
                'Front Page Debug',
                'Front Page Debug',
                'manage_options',
                'ccm-tools-debug',
                array($this, 'create_debug_page')
            );
        }
    }
    
    /**
     * Stamp the chosen theme on <html> before anything paints.
     *
     * Has to be inline and in the head: a deferred script would let the page
     * render in the wrong palette first and then snap, which is worse than
     * either theme on its own. Falls back to the operating system preference
     * when the viewer has never chosen, and survives blocked storage.
     *
     * @return void
     */
    public function print_theme_stamp(): void {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || strpos((string) $screen->id, 'ccm-tools') === false) {
            return;
        }
        ?>
        <script>
        (function () {
            var t = null;
            try { t = window.localStorage.getItem('ccm-tools-theme'); } catch (e) {}
            if (t !== 'dark' && t !== 'light') {
                t = (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
            }
            document.documentElement.setAttribute('data-ccm-theme', t);
        })();
        </script>
        <?php
    }

    public function enqueue_admin_scripts($hook): void {
        // Only load on our plugin pages
        if (strpos($hook, 'ccm-tools') !== false) {
            // Modern pure CSS - no external dependencies
            wp_enqueue_style('ccm-tools-style', CCM_HELPER_ROOT_URL . 'css/style.css', array(), CCM_HELPER_VERSION);
            
            // UI layer: theme toggle + the shared CCM spinner. Loaded before
            // main.js so window.ccmSpinner exists for anything that wants it.
            wp_enqueue_script('ccm-tools-ui', CCM_HELPER_ROOT_URL . 'js/ui.js', array(), CCM_HELPER_VERSION, true);

            // Modern vanilla JS - no jQuery required
            wp_enqueue_script('ccm-tools-script', CCM_HELPER_ROOT_URL . 'js/main.js', array('ccm-tools-ui'), CCM_HELPER_VERSION, true);

            // Site Health is a self-contained module; only load it on its own page.
            if (strpos($hook, 'ccm-tools-site-health') !== false) {
                wp_enqueue_script('ccm-tools-site-health', CCM_HELPER_ROOT_URL . 'js/site-health.js', array('ccm-tools-script'), CCM_HELPER_VERSION, true);
            }

            // Performance page interactions (search, filters, field reveal).
            // Saving still lives in main.js; this only touches the interface.
            if (strpos($hook, 'ccm-tools-perf') !== false) {
                wp_enqueue_script('ccm-tools-perf-ui', CCM_HELPER_ROOT_URL . 'js/perf-ui.js', array('ccm-tools-script'), CCM_HELPER_VERSION, true);
            }
            wp_localize_script('ccm-tools-script', 'ccmToolsData', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('ccm-tools-nonce'),
                'i18n' => array(
                    'confirmClearLog' => __('Are you sure you want to clear this log file? This operation cannot be undone.', 'ccm-tools'),
                    'downloadFailed' => __('Failed to download log file. Please try again.', 'ccm-tools'),
                    'confirmEnableDebug' => __('WARNING: Enabling WP_DEBUG will display PHP errors, notices, and warnings on your website. This should only be used on development or staging sites. Are you sure you want to enable debug mode?', 'ccm-tools'),
                    'confirmEnableDebugDisplay' => __('WARNING: Enabling WP_DEBUG_DISPLAY will show PHP errors directly on your website frontend. This is only recommended for development sites. Are you sure you want to enable debug display?', 'ccm-tools'),
                    // TTFB related messages
                    'measuring' => __('Measuring...', 'ccm-tools'),
                    'measurementFailed' => __('Measurement failed', 'ccm-tools'),
                    // Redis related messages
                    'confirmRedisConfig' => __('This will add Redis configuration to your wp-config.php file. Continue?', 'ccm-tools'),
                    'installRedis' => __('Install Redis Cache Plugin', 'ccm-tools'),
                    'installing' => __('Installing...', 'ccm-tools'),
                    'installFailed' => __('Installation failed.', 'ccm-tools'),
                    'enableRedis' => __('Enable', 'ccm-tools'),
                    'enabling' => __('Enabling...', 'ccm-tools'),
                    'enableFailed' => __('Failed to enable Redis.', 'ccm-tools'),
                    'disableRedis' => __('Disable', 'ccm-tools'),
                    'disabling' => __('Disabling...', 'ccm-tools'),
                    'disableFailed' => __('Failed to disable Redis.', 'ccm-tools'),
                    'showConfig' => __('Show Config', 'ccm-tools'),
                    'hideConfig' => __('Hide Config', 'ccm-tools'),
                    // WooCommerce related messages
                    'wooToggleFailed' => __('Failed to toggle setting.', 'ccm-tools'),
                    // Button labels swapped in while an action is in flight.
                    'saving' => __('Saving...', 'ccm-tools'),
                    'saveSettings' => __('Save Settings', 'ccm-tools'),
                    'testing' => __('Testing...', 'ccm-tools'),
                    'testConversion' => __('Test Conversion', 'ccm-tools'),
                    'stopping' => __('Stopping...', 'ccm-tools'),
                    'stopConversion' => __('Stop Conversion', 'ccm-tools')
                )
            ));
        }
    }
    
    public function add_action_links($links): array {
        $settings_link = '<a href="' . esc_url(admin_url('admin.php?page=ccm-tools')) . '">' . __('Settings', 'ccm-tools') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }
    
    /**
     * Initialize front page prioritization hooks
     */
    public function init_front_page_prioritization(): void {
        // Add hooks for front page prioritization
        add_action('pre_get_posts', array($this, 'prioritize_front_page_in_admin'));
        add_filter('posts_orderby', array($this, 'modify_posts_orderby_for_front_page'), 10, 2);
    }
    
    /**
     * Prioritize Front Page in admin post/page lists
     * 
     * @param WP_Query $query The WordPress query object
     */
    public function prioritize_front_page_in_admin($query): void {
        // Only modify admin queries
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }
        
        // Get current screen info
        $screen = get_current_screen();
        
        // Only apply to edit screens (post/page lists)
        if (!$screen || $screen->base !== 'edit') {
            return;
        }
        
        // Get the front page ID
        $front_page_id = get_option('page_on_front');
        
        // If no front page is set, do nothing
        if (!$front_page_id) {
            return;
        }
        
        // Check if we're viewing the post type that contains the front page
        $current_post_type = $screen->post_type;
        $front_page_post_type = get_post_type($front_page_id);
        
        if ($current_post_type !== $front_page_post_type) {
            return;
        }
        
        // Check if user has applied custom sorting via GET parameters
        $orderby_param = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : '';
        if (!empty($orderby_param)) {
            return; // User has custom sorting, don't interfere
        }
        
        // Mark this query for custom ordering
        $query->set('ccm_prioritize_front_page', true);
        $query->set('ccm_front_page_id', $front_page_id);
    }
    
    /**
     * Debug function to check front page settings
     */
    public function debug_front_page_settings(): array {
        return array(
            'page_on_front' => get_option('page_on_front'),
            'show_on_front' => get_option('show_on_front'),
            'page_for_posts' => get_option('page_for_posts'),
            'current_screen' => is_admin() ? get_current_screen() : null,
            'current_pagenow' => $GLOBALS['pagenow'] ?? null,
            'current_post_type' => isset($_GET['post_type']) ? $_GET['post_type'] : 'post'
        );
    }
    
    /**
     * Modify the ORDER BY clause to prioritize the front page
     * 
     * @param string $orderby The ORDER BY clause
     * @param WP_Query $query The WordPress query object
     * @return string Modified ORDER BY clause
     */
    public function modify_posts_orderby_for_front_page($orderby, $query): string {
        global $wpdb;

        // Coerce to string up front: an earlier posts_orderby filter can hand
        // us null, and this method's `: string` return type would otherwise
        // fatal with a TypeError when that null is returned as-is below.
        $orderby = (string) $orderby;

        // Only apply our custom ordering if it's marked for front page prioritization
        if (!$query->get('ccm_prioritize_front_page')) {
            return $orderby;
        }

        $front_page_id = $query->get('ccm_front_page_id');
        if (!$front_page_id) {
            return $orderby;
        }

        // Put the front page first, then fall back to WordPress's own
        // ordering instead of discarding it. For hierarchical post types
        // (e.g. Pages) WordPress passes "menu_order title" here — overwriting
        // it outright silently turned the Pages list into date-DESC order.
        $front_page_case = "CASE WHEN {$wpdb->posts}.ID = " . intval($front_page_id) . " THEN 0 ELSE 1 END ASC";

        return $orderby !== '' ? $front_page_case . ', ' . $orderby : $front_page_case;
    }
    
    /**
     * Add a visual indicator for the front page in admin lists
     * 
     * @param array $actions Row actions for the post
     * @param WP_Post $post The post object
     * @return array Modified actions array
     */
    public function add_front_page_indicator($actions, $post): array {
        // Check if this is the front page
        $front_page_id = get_option('page_on_front');
        
        if ($post->ID == $front_page_id) {
            // Add front page indicator at the beginning of actions
            $front_page_actions = array(
                'ccm_front_page' => '<span class="ccm-front-page-indicator" title="' . esc_attr__('This is your Front Page (Home Page)', 'ccm-tools') . '">🏠 ' . __('Front Page', 'ccm-tools') . '</span>'
            );
            $actions = $front_page_actions + $actions;
        }
        
        return $actions;
    }
    
    /**
     * Add CSS styles for front page indicator in admin
     */
    public function add_front_page_admin_styles(): void {
        global $pagenow;
        
        // Only add styles on edit.php (post/page list)
        if ($pagenow !== 'edit.php') {
            return;
        }
        
        ?>
        <style type="text/css">
            .ccm-front-page-indicator {
                color: #2271b1;
                font-weight: 600;
                text-decoration: none !important;
                cursor: help;
            }
            
            .ccm-front-page-indicator:hover {
                color: #135e96;
            }
            
            /* Style the entire row for front page */
            tr.ccm-front-page-row {
                background-color: #f0f8ff !important;
                border-left: 4px solid #2271b1 !important;
            }
            
            tr.ccm-front-page-row:hover {
                background-color: #e6f3ff !important;
            }
            
            /* Make the front page title more prominent */
            tr.ccm-front-page-row .row-title {
                font-weight: 600;
                color: #2271b1;
            }
        </style>
        <script type="text/javascript">
            document.addEventListener('DOMContentLoaded', function() {
                // Add class to front page row for styling
                document.querySelectorAll('.ccm-front-page-indicator').forEach(function(el) {
                    var row = el.closest('tr');
                    if (row) row.classList.add('ccm-front-page-row');
                });
            });
        </script>
        <?php
    }
    
    /**
     * Dashboard page callback
     */
    public function create_dashboard_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'ccm-tools'));
        }
        
        // Initialize Redis variables before using them
        $redis_status = array('server_available' => false, 'version' => '');
        $redis_config = array('configured' => false, 'constants' => array());
        $server_status_class = 'ccm-error';
        $server_status_text = __('Not Available', 'ccm-tools');
        $config_status_class = 'ccm-warning';
        $config_status_text = __('Not Configured', 'ccm-tools');
        $plugin_status_class = 'ccm-warning';
        $plugin_status_text = __('Not Installed', 'ccm-tools');
        $cache_status_class = 'ccm-warning';
        $cache_status_text = __('Not available', 'ccm-tools');
        $cache_status_note = '';
        
        // Get debug status variables early - verify current state from system
        // Use direct file check to bypass any opcode caching
        $wp_config_path = ABSPATH . 'wp-config.php';
        $wp_config_content = '';
        if (file_exists($wp_config_path) && is_readable($wp_config_path)) {
            $wp_config_content = file_get_contents($wp_config_path);
        }
        
        // Parse wp-config.php directly to verify the current state
        $debug_mode_enabled = defined('WP_DEBUG') && WP_DEBUG;
        $debug_log_enabled = defined('WP_DEBUG_LOG') && WP_DEBUG_LOG;
        $debug_display_enabled = defined('WP_DEBUG_DISPLAY') && WP_DEBUG_DISPLAY;
        
        // Double-check against the file content. Anchored to a line start
        // that is not itself indented under a comment marker, so a
        // define('WP_DEBUG', ...) sitting inside a // or /* */ comment can no
        // longer be picked up as the live value (previously the FIRST match
        // anywhere in the file won, including commented-out ones).
        if (!empty($wp_config_content)) {
            if (preg_match('/^[ \t]*define\s*\(\s*[\'"]WP_DEBUG[\'"]\s*,\s*(true|false)\s*\)/mi', $wp_config_content, $matches)) {
                $debug_mode_enabled = strtolower($matches[1]) === 'true';
            }
            if (preg_match('/^[ \t]*define\s*\(\s*[\'"]WP_DEBUG_LOG[\'"]\s*,\s*(true|false)\s*\)/mi', $wp_config_content, $matches)) {
                $debug_log_enabled = strtolower($matches[1]) === 'true';
            }
            if (preg_match('/^[ \t]*define\s*\(\s*[\'"]WP_DEBUG_DISPLAY[\'"]\s*,\s*(true|false)\s*\)/mi', $wp_config_content, $matches)) {
                $debug_display_enabled = strtolower($matches[1]) === 'true';
            }
        }
        
        $debug_log_status = $debug_log_enabled ? 'Enabled' : 'Disabled';
        $debug_log_class = $debug_log_status === 'Enabled' ? 'ccm-info' : '';
        $debug_display_status = $debug_display_enabled ? 'Enabled' : 'Disabled';
        $debug_display_class = $debug_display_status === 'Enabled' ? 'ccm-warning' : '';
        
        // Only attempt to get Redis status if the function exists
        if (function_exists('ccm_tools_check_redis_status')) {
            $redis_status = ccm_tools_check_redis_status();
            $redis_config = ccm_tools_check_redis_configuration();
            
            // Redis Server Status
            $server_status_class = $redis_status['server_available'] ? 'ccm-success' : 'ccm-error';
            $server_status_text = $redis_status['server_available'] ? __('Available', 'ccm-tools') : __('Not Available', 'ccm-tools');
            
            // Redis Configuration Status
            $config_status_class = $redis_config['configured'] ? 'ccm-success' : 'ccm-warning';
            $config_status_text = 'Not Configured';
            
            if ($redis_config['configured']) {
                $config_status_text = 'Configured';
            } elseif (isset($redis_config['partially_configured']) && $redis_config['partially_configured']) {
                $config_status_text = 'Partially Configured';
                $config_status_class = 'ccm-info';
            }
            
            // Object cache status.
            //
            // Read this from the drop-in actually installed at
            // wp-content/object-cache.php, NOT from whether the third-party
            // "Redis Object Cache" plugin happens to be present. CCM Tools
            // ships its own drop-in and replaces that plugin, so checking for
            // it reported "Not Available" on every site where our own cache
            // was connected and serving.
            $dropin = function_exists('ccm_tools_redis_dropin_status')
                ? ccm_tools_redis_dropin_status()
                : array('exists' => false, 'is_ccm' => false, 'is_other' => false, 'other_plugin' => '', 'version' => '');

            $cache_status_text  = __('Not available', 'ccm-tools');
            $cache_status_class = 'ccm-warning';
            $cache_status_note  = '';

            if (!empty($dropin['is_ccm'])) {
                $cache_status_text  = __('Enabled', 'ccm-tools');
                $cache_status_class = 'ccm-success';
                $cache_status_note  = !empty($dropin['version'])
                    ? sprintf(__('CCM drop-in v%s', 'ccm-tools'), $dropin['version'])
                    : __('CCM drop-in active', 'ccm-tools');
            } elseif (!empty($dropin['is_other'])) {
                $cache_status_text  = __('Other plugin', 'ccm-tools');
                $cache_status_class = 'ccm-info';
                $cache_status_note  = !empty($dropin['other_plugin'])
                    ? sprintf(__('Managed by %s', 'ccm-tools'), $dropin['other_plugin'])
                    : __('A drop-in from another plugin is installed', 'ccm-tools');
            } elseif (!empty($dropin['exists'])) {
                $cache_status_text  = __('Unrecognised', 'ccm-tools');
                $cache_status_note  = __('An object-cache.php we did not write is installed', 'ccm-tools');
            } elseif (!empty($redis_status['server_available'])) {
                $cache_status_text  = __('Not enabled', 'ccm-tools');
                $cache_status_note  = __('Redis is running but no drop-in is installed', 'ccm-tools');
            } else {
                $cache_status_note  = __('No Redis server detected', 'ccm-tools');
            }
        }
        
        ?>
        <div class="wrap ccm-tools">
            <?php ccm_tools_render_header_nav('ccm-tools'); ?>
            
            <div class="ccm-content">

                <div class="ccm-hero">
                    <div class="ccm-hero__text">
                        <h1><?php _e('System Information', 'ccm-tools'); ?></h1>
                        <div class="ccm-hero__meta">
                            <span><?php echo esc_html(wp_parse_url(home_url(), PHP_URL_HOST)); ?></span>
                            <span><?php printf(esc_html__('WordPress %s', 'ccm-tools'), esc_html(get_bloginfo('version'))); ?></span>
                            <span><?php printf(esc_html__('PHP %s', 'ccm-tools'), esc_html(phpversion())); ?></span>
                        </div>
                    </div>
                </div>

                <?php
                // At-a-glance row. Everything here is already computed above or
                // is a cheap read; nothing new is fetched to render it.
                $perf_now = get_option('ccm_tools_perf_settings', array());
                $perf_now = is_array($perf_now) ? $perf_now : array();
                $perf_on = 0;
                foreach ($perf_now as $perf_key => $perf_val) {
                    if ($perf_key === 'enabled') { continue; }
                    if ($perf_val === true) { $perf_on++; }
                }
                $perf_master = !empty($perf_now['enabled']);

                // Read these here rather than borrowing the PHP card's variables:
                // that card renders further down the page, so at this point they
                // do not exist yet.
                $tile_memory_limit = ini_get('memory_limit');
                $tile_memory_bytes = ccm_tools_convert_php_size_to_bytes($tile_memory_limit);
                $tile_memory_unlimited = ($tile_memory_bytes < 0);
                $tile_memory_low = (!$tile_memory_unlimited && $tile_memory_bytes < 256 * 1024 * 1024);
                ?>
                <div class="ccm-stat-grid">

                    <div class="ccm-stat-tile">
                        <div class="ccm-stat-tile__value ccm-stat-tile__value--brand" id="ttfb-result">
                            <span class="ccm-text-muted" style="font-size:0.95rem;font-weight:500;"><?php _e('Not measured', 'ccm-tools'); ?></span>
                        </div>
                        <div class="ccm-stat-tile__label"><?php _e('Time to first byte', 'ccm-tools'); ?></div>
                        <div class="ccm-stat-tile__sub">
                            <button type="button" id="refresh-ttfb" class="ccm-button ccm-button-secondary ccm-button-small" title="<?php esc_attr_e('Runs several timed requests against this site', 'ccm-tools'); ?>"><?php _e('Measure', 'ccm-tools'); ?></button>
                        </div>
                    </div>

                    <div class="ccm-stat-tile">
                        <div class="ccm-stat-tile__value"><?php echo $tile_memory_unlimited ? esc_html__('Unlimited', 'ccm-tools') : esc_html($tile_memory_limit); ?></div>
                        <div class="ccm-stat-tile__label"><?php _e('PHP memory limit', 'ccm-tools'); ?></div>
                        <div class="ccm-stat-tile__sub">
                            <span class="ccm-dot <?php echo $tile_memory_low ? 'ccm-dot-warn' : 'ccm-dot-ok'; ?>"></span>
                            <?php echo $tile_memory_low
                                ? esc_html__('Below the 256M floor', 'ccm-tools')
                                : esc_html__('Above the 256M floor', 'ccm-tools'); ?>
                        </div>
                    </div>

                    <div class="ccm-stat-tile">
                        <div class="ccm-stat-tile__value"><?php echo esc_html($cache_status_text); ?></div>
                        <div class="ccm-stat-tile__label"><?php _e('Object cache', 'ccm-tools'); ?></div>
                        <div class="ccm-stat-tile__sub">
                            <span class="ccm-dot <?php echo $cache_status_class === 'ccm-success' ? 'ccm-dot-ok' : 'ccm-dot-warn'; ?>"></span>
                            <?php echo esc_html($cache_status_note); ?>
                        </div>
                    </div>

                    <div class="ccm-stat-tile">
                        <div class="ccm-stat-tile__value">
                            <?php echo esc_html($perf_on); ?><small> <?php _e('on', 'ccm-tools'); ?></small>
                        </div>
                        <div class="ccm-stat-tile__label"><?php _e('Performance options', 'ccm-tools'); ?></div>
                        <div class="ccm-stat-tile__sub">
                            <span class="ccm-dot <?php echo $perf_master ? 'ccm-dot-ok' : 'ccm-dot-warn'; ?>"></span>
                            <?php echo $perf_master
                                ? esc_html__('Optimiser active', 'ccm-tools')
                                : esc_html__('Optimiser switched off', 'ccm-tools'); ?>
                        </div>
                    </div>

                </div>

                <div class="ccm-section">
                    <div>
                        <span class="ccm-section__eyebrow"><?php _e('Environment', 'ccm-tools'); ?></span>
                        <h2><?php _e('What this site is running on', 'ccm-tools'); ?></h2>
                        <p><?php _e('Read live from the running interpreter and the database, not from wp-config.', 'ccm-tools'); ?></p>
                    </div>
                </div>

                <div class="ccm-grid-2">
                <!-- Database Information Card -->
                <div class="ccm-card">
                    <h2><?php _e('Database Information', 'ccm-tools'); ?></h2>
                    <?php
                    $db_info = ccm_tools_get_database_size();
                    ?>
                    <table class="ccm-table">
                        <tr>
                            <th><?php _e('Database Size', 'ccm-tools'); ?></th>
                            <td><?php echo esc_html($db_info['size']); ?></td>
                        </tr>
                        <tr>
                            <th><?php _e('Number of Tables', 'ccm-tools'); ?></th>
                            <td><?php echo esc_html($db_info['tables']); ?></td>
                        </tr>
                        <tr>
                            <th><?php _e('Database Host', 'ccm-tools'); ?></th>
                            <td><?php echo esc_html(DB_HOST); ?></td>
                        </tr>
                        <tr>
                            <th><?php _e('Database Name', 'ccm-tools'); ?></th>
                            <td><?php echo esc_html(DB_NAME); ?></td>
                        </tr>
                        <tr>
                            <th><?php _e('Database User', 'ccm-tools'); ?></th>
                            <td><?php echo esc_html(DB_USER); ?></td>
                        </tr>
                        <tr>
                            <th><?php _e('Database Charset', 'ccm-tools'); ?></th>
                            <td><?php echo esc_html(defined('DB_CHARSET') ? DB_CHARSET : 'utf8'); ?></td>
                        </tr>
                        <tr>
                            <th><?php _e('Database Collation', 'ccm-tools'); ?></th>
                            <td><?php echo esc_html(defined('DB_COLLATE') && DB_COLLATE ? DB_COLLATE : 'Default'); ?></td>
                        </tr>
                        <tr>
                            <th><?php _e('MySQL Version', 'ccm-tools'); ?></th>
                            <td><?php echo esc_html($GLOBALS['wpdb']->db_version()); ?></td>
                        </tr>
                    </table>
                </div>
                
                <!-- PHP Information Card -->
                <div class="ccm-card">
                    <h2><?php _e('PHP Information', 'ccm-tools'); ?></h2>
                    <?php
                    // Get PHP settings
                    $memory_limit = ini_get('memory_limit');
                    $max_execution_time = (int) ini_get('max_execution_time');
                    $post_max_size = ini_get('post_max_size');
                    $upload_max_filesize = ini_get('upload_max_filesize');
                    $max_input_vars = (int) ini_get('max_input_vars');
                    
                    // Convert sizes to bytes for comparison
                    $memory_limit_bytes = ccm_tools_convert_php_size_to_bytes($memory_limit);
                    $post_max_size_bytes = ccm_tools_convert_php_size_to_bytes($post_max_size);
                    $upload_max_filesize_bytes = ccm_tools_convert_php_size_to_bytes($upload_max_filesize);
                    
                    // Define thresholds
                    $memory_limit_threshold = 256 * 1024 * 1024; // 256MB
                    $execution_time_threshold = 30; // 30 seconds
                    $post_size_threshold = 62 * 1024 * 1024; // 62MB
                    $upload_size_threshold = 62 * 1024 * 1024; // 62MB
                    $input_vars_threshold = 10000;
                    
                    // Determine status classes and suggestions.
                    // -1 means "unlimited" for memory_limit — it's numeric, so
                    // without this check it reads as less than the 256M
                    // threshold and renders a red "Recommend: 512M or higher"
                    // for the best possible setting.
                    $memory_limit_unlimited = ($memory_limit_bytes < 0);
                    $memory_class = ($memory_limit_unlimited || $memory_limit_bytes >= $memory_limit_threshold) ? 'ccm-success' : 'ccm-error';
                    $memory_suggestion = ($memory_limit_unlimited || $memory_limit_bytes >= $memory_limit_threshold) ? '' : __('Recommend: 512M or higher', 'ccm-tools');
                    
                    $execution_class = $max_execution_time <= $execution_time_threshold ? 'ccm-error' : 'ccm-success';
                    $execution_suggestion = $max_execution_time <= $execution_time_threshold ? __('Recommend: 180 seconds or higher', 'ccm-tools') : '';
                    
                    $post_class = $post_max_size_bytes <= $post_size_threshold ? 'ccm-error' : 'ccm-success';
                    $post_suggestion = $post_max_size_bytes <= $post_size_threshold ? __('Recommend: 256M or higher', 'ccm-tools') : '';
                    
                    $upload_class = $upload_max_filesize_bytes <= $upload_size_threshold ? 'ccm-error' : 'ccm-success';
                    $upload_suggestion = $upload_max_filesize_bytes <= $upload_size_threshold ? __('Recommend: 256M or higher', 'ccm-tools') : '';
                    
                    $vars_class = $max_input_vars < $input_vars_threshold ? 'ccm-error' : 'ccm-success';
                    $vars_suggestion = $max_input_vars < $input_vars_threshold ? __('Recommend: 10000 or higher', 'ccm-tools') : '';
                    
                    // Display Errors check
                    $display_errors = ini_get('display_errors');
                    $display_errors_class = $display_errors ? 'ccm-warning' : 'ccm-success';
                    $display_errors_suggestion = $display_errors ? __('Recommend: Disable for production sites', 'ccm-tools') : '';
                    ?>
                    <table class="ccm-table">
                        <tr>
                            <th><?php _e('PHP Version', 'ccm-tools'); ?></th>
                            <td><?php echo esc_html(phpversion()); ?></td>
                        </tr>
                        <tr>
                            <th><?php _e('Memory Limit', 'ccm-tools'); ?></th>
                            <td>
                                <span class="<?php echo esc_attr($memory_class); ?>"><?php echo esc_html($memory_limit); ?></span>
                                <?php if ($memory_suggestion): ?>
                                    <br><small class="ccm-note"><?php echo esc_html($memory_suggestion); ?></small>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th><?php _e('Max Execution Time', 'ccm-tools'); ?></th>
                            <td>
                                <span class="<?php echo esc_attr($execution_class); ?>"><?php echo esc_html($max_execution_time); ?> <?php _e('seconds', 'ccm-tools'); ?></span>
                                <?php if ($execution_suggestion): ?>
                                    <br><small class="ccm-note"><?php echo esc_html($execution_suggestion); ?></small>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th><?php _e('Post Max Size', 'ccm-tools'); ?></th>
                            <td>
                                <span class="<?php echo esc_attr($post_class); ?>"><?php echo esc_html($post_max_size); ?></span>
                                <?php if ($post_suggestion): ?>
                                    <br><small class="ccm-note"><?php echo esc_html($post_suggestion); ?></small>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th><?php _e('Upload Max Filesize', 'ccm-tools'); ?></th>
                            <td>
                                <span class="<?php echo esc_attr($upload_class); ?>"><?php echo esc_html($upload_max_filesize); ?></span>
                                <?php if ($upload_suggestion): ?>
                                    <br><small class="ccm-note"><?php echo esc_html($upload_suggestion); ?></small>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th><?php _e('Max Input Vars', 'ccm-tools'); ?></th>
                            <td>
                                <span class="<?php echo esc_attr($vars_class); ?>"><?php echo esc_html($max_input_vars); ?></span>
                                <?php if ($vars_suggestion): ?>
                                    <br><small class="ccm-note"><?php echo esc_html($vars_suggestion); ?></small>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th><?php _e('Display Errors', 'ccm-tools'); ?></th>
                            <td>
                                <span class="<?php echo esc_attr($display_errors_class); ?>">
                                    <?php echo $display_errors ? __('Enabled', 'ccm-tools') : __('Disabled', 'ccm-tools'); ?>
                                </span>
                                <?php if ($display_errors_suggestion): ?>
                                    <br><small class="ccm-note"><?php echo esc_html($display_errors_suggestion); ?></small>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th><?php _e('Allow URL fopen', 'ccm-tools'); ?></th>
                            <td><?php echo ini_get('allow_url_fopen') ? __('Enabled', 'ccm-tools') : __('Disabled', 'ccm-tools'); ?></td>
                        </tr>
                        <tr>
                            <th><?php _e('Default Timezone', 'ccm-tools'); ?></th>
                            <td><?php echo esc_html(date_default_timezone_get()); ?></td>
                        </tr>
                    </table>
                </div>
                
                <!-- PHP Extensions Card -->
                <div class="ccm-card">
                    <h2><?php _e('PHP Extensions', 'ccm-tools'); ?></h2>
                    <div class="ccm-extensions-grid">
                        <?php
                        $required_extensions = array(
                            'mysqli' => __('Required for WordPress database', 'ccm-tools'),
                            'curl' => __('Required for remote requests', 'ccm-tools'),
                            'gd' => __('Required for image manipulation', 'ccm-tools'),
                            'mbstring' => __('Required for multibyte string handling', 'ccm-tools'),
                            'xml' => __('Required for XML processing', 'ccm-tools'),
                            'zip' => __('Required for plugin/theme installation', 'ccm-tools'),
                            'openssl' => __('Required for secure connections', 'ccm-tools'),
                            'json' => __('Required for JSON handling', 'ccm-tools'),
                            'fileinfo' => __('Required for file type detection', 'ccm-tools'),
                            'exif' => __('Recommended for image metadata', 'ccm-tools'),
                            'imagick' => __('Recommended for advanced image processing', 'ccm-tools')
                        );
                        
                        foreach ($required_extensions as $ext => $desc) {
                            $loaded = extension_loaded($ext);
                            echo '<div class="ccm-extension-item ' . ($loaded ? 'ccm-success' : 'ccm-error') . '">';
                            echo '<span class="ccm-icon">' . ($loaded ? '✓' : '✗') . '</span>';
                            echo '<strong>' . esc_html($ext) . '</strong>: ' . esc_html($desc);
                            echo '</div>';
                        }
                        ?>
                    </div>
                </div>
                
                <!-- Server Information Card -->
                <div class="ccm-card">
                    <h2><?php _e('Server Information', 'ccm-tools'); ?></h2>
                    <table class="ccm-table">
                        <tr>
                            <th><?php _e('Server Software', 'ccm-tools'); ?></th>
                            <td><?php echo isset($_SERVER['SERVER_SOFTWARE']) ? esc_html($_SERVER['SERVER_SOFTWARE']) : ''; ?></td>
                        </tr>
                        <tr>
                            <th><?php _e('Operating System', 'ccm-tools'); ?></th>
                            <td><?php echo esc_html(PHP_OS); ?></td>
                        </tr>
                        <tr>
                            <th><?php _e('Architecture', 'ccm-tools'); ?></th>
                            <td><?php echo esc_html(PHP_INT_SIZE * 8); ?> <?php _e('Bit', 'ccm-tools'); ?></td>
                        </tr>
                        <tr>
                            <th><?php _e('Server Protocol', 'ccm-tools'); ?></th>
                            <td><?php echo isset($_SERVER['SERVER_PROTOCOL']) ? esc_html($_SERVER['SERVER_PROTOCOL']) : ''; ?></td>
                        </tr>
                        <tr>
                            <th><?php _e('HTTPS Enabled', 'ccm-tools'); ?></th>
                            <td><?php echo isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? __('Yes', 'ccm-tools') : __('No', 'ccm-tools'); ?></td>
                        </tr>
                        <tr>
                            <th><?php _e('Server IP', 'ccm-tools'); ?></th>
                            <td>
                                <?php 
                                if (isset($_SERVER['SERVER_ADDR'])) {
                                    echo esc_html($_SERVER['SERVER_ADDR']);
                                } elseif (isset($_SERVER['LOCAL_ADDR'])) {
                                    echo esc_html($_SERVER['LOCAL_ADDR']);
                                } else {
                                    _e('Not available', 'ccm-tools');
                                }
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <th><?php _e('Server Port', 'ccm-tools'); ?></th>
                            <td><?php echo isset($_SERVER['SERVER_PORT']) ? esc_html($_SERVER['SERVER_PORT']) : ''; ?></td>
                        </tr>
                        <tr>
                            <th><?php _e('Cloudflare', 'ccm-tools'); ?></th>
                            <td>
                                <?php
                                $cf_info = function_exists('ccm_tools_cf_detect') ? ccm_tools_cf_detect() : array('detected' => false);
                                if (!empty($cf_info['detected'])) {
                                    echo '<span class="ccm-success">✓ ' . esc_html__('Detected', 'ccm-tools') . '</span>';
                                    if (!empty($cf_info['ray_id'])) {
                                        echo ' <small style="color: var(--ccm-text-muted);">(Ray: ' . esc_html($cf_info['ray_id']) . ')</small>';
                                    }
                                    $cf_s = function_exists('ccm_tools_cf_get_settings') ? ccm_tools_cf_get_settings() : array();
                                    if (empty($cf_s['connected'])) {
                                        echo ' — <a href="' . esc_url(admin_url('admin.php?page=ccm-tools-cloudflare')) . '">' . esc_html__('Connect API', 'ccm-tools') . '</a>';
                                    }
                                } else {
                                    echo '<span class="ccm-text-muted">' . esc_html__('Not detected', 'ccm-tools') . '</span>';
                                }
                                ?>
                            </td>
                        </tr>
                    </table>
                </div>
                
                </div><!-- /.ccm-grid-2 -->

                <div class="ccm-section">
                    <div>
                        <span class="ccm-section__eyebrow"><?php _e('Controls', 'ccm-tools'); ?></span>
                        <h2><?php _e('WordPress and debugging', 'ccm-tools'); ?></h2>
                        <p><?php _e('These write to wp-config.php. A backup is taken before every change.', 'ccm-tools'); ?></p>
                    </div>
                </div>

                <!-- WordPress Environment Card -->
                <div class="ccm-card">
                    <h2><?php _e('WordPress Environment', 'ccm-tools'); ?></h2>
                    <?php 
                    global $wp_version;
                    
                    // Get update information
                    $wp_update_info = ccm_tools_check_wordpress_updates();
                    $needs_update = $wp_update_info['needs_update'] ?? false;
                    ?>
                    <table class="ccm-table">
                        <tr>
                            <th><?php _e('WordPress Version', 'ccm-tools'); ?></th>
                            <td>
                                <?php if ($needs_update): ?>
                                    <span class="ccm-error">
                                        <?php echo esc_html($wp_version); ?>
                                    </span>
                                    <a href="<?php echo esc_url($wp_update_info['update_url']); ?>" class="ccm-update-link">
                                        <?php echo sprintf(__('Update to %s', 'ccm-tools'), esc_html($wp_update_info['latest_version'])); ?>
                                    </a>
                                <?php else: ?>
                                    <span class="ccm-success">
                                        <?php echo esc_html($wp_version); ?> 
                                        <small><?php _e('(up to date)', 'ccm-tools'); ?></small>
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th><?php _e('CCM Tools Version', 'ccm-tools'); ?></th>
                            <td><?php echo esc_html(CCM_HELPER_VERSION); ?></td>
                        </tr>
                        

                        <?php if ($redis_status['server_available']): ?>
                        <tr>
                            <th><?php _e('Redis Cache', 'ccm-tools'); ?></th>
                            <td>
                                <div class="ccm-config-control">
                                    <div>
                                        <strong><?php _e('Server:', 'ccm-tools'); ?></strong> 
                                        <span class="ccm-success"><?php _e('Available', 'ccm-tools'); ?></span>
                                        <?php if (!empty($redis_status['version'])): ?>
                                            (<?php echo esc_html($redis_status['version']); ?>)
                                        <?php endif; ?>
                                    </div>
                                    <a href="<?php echo esc_url(admin_url('admin.php?page=ccm-tools-redis')); ?>" class="ccm-button ccm-button-small">
                                        <?php _e('Configure', 'ccm-tools'); ?>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endif; ?>
                        
                        <tr>
                            <th><?php _e('Site URL', 'ccm-tools'); ?></th>
                            <td><?php echo esc_url(site_url()); ?></td>
                        </tr>
                        <tr>
                            <th><?php _e('Home URL', 'ccm-tools'); ?></th>
                            <td><?php echo esc_url(home_url()); ?></td>
                        </tr>
                        <tr>
                            <th><?php _e('WP Debug Mode', 'ccm-tools'); ?></th>
                            <td>
                                <div class="ccm-config-control">
                                    <?php 
                                    $debug_status = $debug_mode_enabled ? 'Enabled' : 'Disabled';
                                    $debug_class = $debug_mode_enabled ? 'ccm-warning' : 'ccm-success';
                                    ?>
                                    <span class="<?php echo esc_attr($debug_class); ?>">
                                        <?php echo esc_html($debug_status); ?>
                                    </span>
                                    <button id="toggle-debug" class="ccm-button" data-enabled="<?php echo $debug_mode_enabled ? 'true' : 'false'; ?>">
                                        <?php echo $debug_mode_enabled ? esc_html__('Disable', 'ccm-tools') : esc_html__('Enable', 'ccm-tools'); ?>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php if ($debug_mode_enabled): ?>
                        <tr>
                            <th><?php _e('WP Debug Log', 'ccm-tools'); ?></th>
                            <td>
                                <div class="ccm-config-control debug-dependent-controls">
                                    <span class="<?php echo esc_attr($debug_log_class); ?>">
                                        <?php echo esc_html($debug_log_status); ?>
                                    </span>
                                    <button id="toggle-debug-log" class="ccm-button" data-enabled="<?php echo $debug_log_status === 'Enabled' ? 'true' : 'false'; ?>">
                                        <?php echo $debug_log_status === 'Enabled' ? esc_html__('Disable', 'ccm-tools') : esc_html__('Enable', 'ccm-tools'); ?>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th><?php _e('WP Debug Display', 'ccm-tools'); ?></th>
                            <td>
                                <div class="ccm-config-control debug-dependent-controls">
                                    <span class="<?php echo esc_attr($debug_display_class); ?>">
                                        <?php echo esc_html($debug_display_status); ?>
                                    </span>
                                    <button id="toggle-debug-display" class="ccm-button" data-enabled="<?php echo $debug_display_status === 'Enabled' ? 'true' : 'false'; ?>">
                                        <?php echo $debug_display_status === 'Enabled' ? esc_html__('Disable', 'ccm-tools') : esc_html__('Enable', 'ccm-tools'); ?>
                                    </button>
                                </div>
                                <?php if ($debug_display_status === 'Enabled'): ?>
                                <small class="ccm-warning debug-display-warning"><?php _e('Warning: Errors will be displayed on the frontend', 'ccm-tools'); ?></small>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <th><?php _e('WP Memory Limit', 'ccm-tools'); ?></th>
                            <td>
                                <div class="ccm-config-control">
                                    <span><?php echo esc_html(WP_MEMORY_LIMIT); ?></span>
                                    <div class="ccm-config-select">
                                        <select id="memory-limit">
                                            <option value="40M" <?php selected(WP_MEMORY_LIMIT, '40M'); ?>><?php _e('Default (40M)', 'ccm-tools'); ?></option>
                                            <option value="64M" <?php selected(WP_MEMORY_LIMIT, '64M'); ?>><?php _e('64M', 'ccm-tools'); ?></option>
                                            <option value="128M" <?php selected(WP_MEMORY_LIMIT, '128M'); ?>><?php _e('128M', 'ccm-tools'); ?></option>
                                            <option value="256M" <?php selected(WP_MEMORY_LIMIT, '256M'); ?>><?php _e('256M', 'ccm-tools'); ?></option>
                                            <option value="512M" <?php selected(WP_MEMORY_LIMIT, '512M'); ?>><?php _e('512M', 'ccm-tools'); ?></option>
                                            <option value="1024M" <?php selected(WP_MEMORY_LIMIT, '1024M'); ?>><?php _e('1024M', 'ccm-tools'); ?></option>
                                        </select>
                                        <button id="update-memory-limit" class="ccm-button">
                                            <?php _e('Update', 'ccm-tools'); ?>
                                        </button>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th><?php _e('Active Theme', 'ccm-tools'); ?></th>
                            <td><?php echo esc_html(wp_get_theme()->get('Name') . ' (' . wp_get_theme()->get('Version') . ')'); ?></td>
                        </tr>
                        <tr>
                            <th><?php _e('Active Plugins', 'ccm-tools'); ?></th>
                            <td><?php echo esc_html(count(get_option('active_plugins'))); ?></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Database tools page callback
     */
    public function create_database_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'ccm-tools'));
        }

        $db = function_exists('ccm_tools_get_database_size') ? ccm_tools_get_database_size() : array();
        ?>
        <div class="wrap ccm-tools">
            <?php ccm_tools_render_header_nav('ccm-tools-database'); ?>

            <div class="ccm-content">

                <div class="ccm-hero">
                    <div class="ccm-hero__text">
                        <h1><?php _e('Database', 'ccm-tools'); ?></h1>
                        <div class="ccm-hero__meta">
                            <?php if (!empty($db['size'])) : ?>
                                <span><?php echo esc_html($db['size']); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($db['tables'])) : ?>
                                <span><?php printf(
                                    esc_html(_n('%d table', '%d tables', (int) $db['tables'], 'ccm-tools')),
                                    (int) $db['tables']
                                ); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($db['overhead'])) : ?>
                                <span><?php printf(
                                    /* translators: %s: reclaimable size */
                                    esc_html__('%s reclaimable', 'ccm-tools'), esc_html($db['overhead'])
                                ); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="ccm-hero__actions">
                        <button id="run-optimizations" class="ccm-button ccm-button-primary" disabled>
                            <?php _e('Run selected', 'ccm-tools'); ?>
                        </button>
                    </div>
                </div>

                <div class="ccm-alert" style="margin-bottom: var(--ccm-space-lg);">
                    <span class="ccm-dot ccm-dot-ok"></span>
                    <div><strong><?php _e('Take a backup first.', 'ccm-tools'); ?></strong>
                    <?php _e('Most of these are routine housekeeping, but anything marked as permanent deletes rows that cannot be recovered from here.', 'ccm-tools'); ?></div>
                </div>

                <div class="ccm-toolbar" style="margin-bottom: var(--ccm-space-lg);">
                    <span class="ccm-text-muted" style="font-size: var(--ccm-text-sm); font-weight: 600;">
                        <?php _e('Quick select', 'ccm-tools'); ?>
                    </span>
                    <button id="select-all-safe" class="ccm-button ccm-button-secondary ccm-button-small">
                        <?php _e('Everything safe', 'ccm-tools'); ?>
                    </button>
                    <button id="deselect-all" class="ccm-button ccm-button-secondary ccm-button-small">
                        <?php _e('Nothing', 'ccm-tools'); ?>
                    </button>
                </div>

                <div class="ccm-section">
                    <div>
                        <span class="ccm-section__eyebrow"><?php _e('Housekeeping', 'ccm-tools'); ?></span>
                        <h2><?php _e('Optimisation tasks', 'ccm-tools'); ?></h2>
                        <p><?php _e('Safe tasks are ticked for you. Each one shows how many rows it would actually touch, so you can see whether it is worth running.', 'ccm-tools'); ?></p>
                    </div>
                </div>

                <div id="optimization-options" class="ccm-optimization-options">
                    <div class="ccm-empty">
                        <div class="ccm-spinner ccm-spinner-block"></div>
                        <p><?php _e('Counting what can be cleaned up…', 'ccm-tools'); ?></p>
                    </div>
                </div>

                <div id="optimization-results" class="ccm-result-box" style="display: none;"></div>

            </div>
        </div>
        <?php
    }

    /**
     * .htaccess tools page callback
     */
    public function create_htaccess_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'ccm-tools'));
        }
        ?>
        <div class="wrap ccm-tools">
            <?php ccm_tools_render_header_nav('ccm-tools-htaccess'); ?>
            <div class="ccm-content">
                <?php
                /*
                 * ccm_tools_display_htaccess() returns the whole page body now,
                 * built from the component kit, rather than a blob to drop in a
                 * card. The two boxes below are kept because js/main.js writes
                 * its status messages into them by id.
                 */
                ?>
                <div id="infoBox" class="ccm-info-box"></div>
                <div id="resultBox"><?php echo ccm_tools_display_htaccess(); ?></div>
            </div>
        </div>
        <?php
    }

    /**
     * Error Log viewer page callback
     */
    /**
     * WooCommerce tools page callback
     */
    public function create_woocommerce_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'ccm-tools'));
        }

        $woocommerce_active    = ccm_tools_is_woocommerce_active();
        $woocommerce_info      = $woocommerce_active ? ccm_tools_get_woocommerce_info() : false;
        $payment_gateways_info = $woocommerce_active ? ccm_tools_check_payment_gateways() : false;
        $admin_payment_enabled = get_option('ccm_woo_admin_payment_enabled', 'no') === 'yes';
        ?>
        <div class="wrap ccm-tools">
            <?php ccm_tools_render_header_nav('ccm-tools-woocommerce'); ?>

            <div class="ccm-content">

            <?php if (!$woocommerce_active) : ?>

                <div class="ccm-hero">
                    <div class="ccm-hero__text">
                        <h1><?php _e('WooCommerce', 'ccm-tools'); ?></h1>
                        <div class="ccm-hero__meta"><span><?php _e('not active on this site', 'ccm-tools'); ?></span></div>
                    </div>
                </div>

                <div class="ccm-empty">
                    <span class="ccm-empty__icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><circle cx="9" cy="20" r="1"/><circle cx="18" cy="20" r="1"/><path d="M1 1h4l2.7 12.4a2 2 0 002 1.6h9.7a2 2 0 002-1.6L23 6H6"/></svg>
                    </span>
                    <h3><?php _e('Nothing to configure yet', 'ccm-tools'); ?></h3>
                    <p><?php _e('These tools only do anything on a site running WooCommerce. Install and activate it first.', 'ccm-tools'); ?></p>
                    <a href="<?php echo esc_url(admin_url('plugin-install.php?s=woocommerce&tab=search&type=term')); ?>" class="ccm-button ccm-button-primary">
                        <?php _e('Install WooCommerce', 'ccm-tools'); ?>
                    </a>
                </div>

            <?php else : ?>

                <div class="ccm-hero">
                    <div class="ccm-hero__text">
                        <h1><?php _e('WooCommerce', 'ccm-tools'); ?></h1>
                        <div class="ccm-hero__meta">
                            <?php if (!empty($woocommerce_info['version'])) : ?>
                                <span><?php echo esc_html('v' . $woocommerce_info['version']); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($woocommerce_info['currency'])) : ?>
                                <span><?php echo esc_html($woocommerce_info['currency']); ?></span>
                            <?php endif; ?>
                            <?php if ($admin_payment_enabled) : ?>
                                <span><?php _e('admin-only payments on', 'ccm-tools'); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <?php if ($admin_payment_enabled) : ?>
                    <div class="ccm-alert ccm-alert--warn" style="margin-bottom: var(--ccm-space-lg);">
                        <span class="ccm-dot ccm-dot-warn"></span>
                        <div><strong><?php _e('Customers cannot see Cash on Delivery or Bank Transfer.', 'ccm-tools'); ?></strong>
                        <?php _e('That is deliberate while you are testing. Remember to switch it off before handover.', 'ccm-tools'); ?></div>
                    </div>
                <?php endif; ?>

                <div class="ccm-stat-grid">
                    <div class="ccm-stat-tile">
                        <div class="ccm-stat-tile__value"><?php echo esc_html(number_format_i18n((int) ($woocommerce_info['orders'] ?? 0))); ?></div>
                        <div class="ccm-stat-tile__label"><?php _e('Orders', 'ccm-tools'); ?></div>
                    </div>
                    <div class="ccm-stat-tile">
                        <div class="ccm-stat-tile__value"><?php echo esc_html(number_format_i18n((int) ($woocommerce_info['products'] ?? 0))); ?></div>
                        <div class="ccm-stat-tile__label"><?php _e('Products', 'ccm-tools'); ?></div>
                    </div>
                    <div class="ccm-stat-tile">
                        <div class="ccm-stat-tile__value"><?php echo esc_html(number_format_i18n((int) ($woocommerce_info['customers'] ?? 0))); ?></div>
                        <div class="ccm-stat-tile__label"><?php _e('Customers', 'ccm-tools'); ?></div>
                    </div>
                    <div class="ccm-stat-tile">
                        <div class="ccm-stat-tile__value"><?php echo esc_html(count((array) ($woocommerce_info['gateways'] ?? array()))); ?></div>
                        <div class="ccm-stat-tile__label"><?php _e('Payment gateways', 'ccm-tools'); ?></div>
                        <div class="ccm-stat-tile__sub">
                            <span class="ccm-dot <?php echo $admin_payment_enabled ? 'ccm-dot-warn' : 'ccm-dot-ok'; ?>"></span>
                            <?php echo $admin_payment_enabled
                                ? esc_html__('2 restricted to admins', 'ccm-tools')
                                : esc_html__('all visible to customers', 'ccm-tools'); ?>
                        </div>
                    </div>
                </div>

                <div class="ccm-section">
                    <div>
                        <span class="ccm-section__eyebrow"><?php _e('Testing', 'ccm-tools'); ?></span>
                        <h2><?php _e('Admin-only payment methods', 'ccm-tools'); ?></h2>
                        <p><?php _e('Hides Cash on Delivery and Bank Transfer from everyone except administrators, so you can place a real test order without leaving an unpaid route open to customers.', 'ccm-tools'); ?></p>
                    </div>
                </div>

                <div class="ccm-opts">
                    <div class="ccm-opt<?php echo $admin_payment_enabled ? ' is-on' : ''; ?>">
                        <div class="ccm-opt__main">
                            <div class="ccm-opt__text">
                                <span class="ccm-opt__label"><?php _e('Restrict to administrators', 'ccm-tools'); ?></span>
                                <?php if ($admin_payment_enabled) : ?>
                                    <span class="ccm-chip ccm-chip--warn"><?php _e('Active', 'ccm-tools'); ?></span>
                                <?php endif; ?>
                                <p class="ccm-opt__desc"><?php _e('Applies at the classic checkout and in the block cart. Stripe and PayPal are unaffected.', 'ccm-tools'); ?></p>
                            </div>
                            <button type="button" id="toggle-admin-payment"
                                    class="ccm-button <?php echo $admin_payment_enabled ? 'ccm-button-danger' : 'ccm-button-primary'; ?> ccm-button-small">
                                <?php echo $admin_payment_enabled
                                    ? esc_html__('Turn off', 'ccm-tools')
                                    : esc_html__('Turn on', 'ccm-tools'); ?>
                            </button>
                        </div>
                    </div>
                </div>

                <?php if (is_array($payment_gateways_info)) : ?>
                    <div class="ccm-panel" style="margin-top: var(--ccm-space-md);">
                        <div class="ccm-panel__head"><span><?php _e('Gateway status', 'ccm-tools'); ?></span></div>
                        <div class="ccm-kv">
                            <div>
                                <span class="ccm-kv__k"><?php _e('Cash on Delivery', 'ccm-tools'); ?></span>
                                <span class="ccm-kv__v">
                                    <?php if (empty($payment_gateways_info['cod_available'])) : ?>
                                        <span class="ccm-chip"><?php _e('Not installed', 'ccm-tools'); ?></span>
                                    <?php elseif (empty($payment_gateways_info['cod_enabled'])) : ?>
                                        <span class="ccm-chip ccm-chip--warn"><?php _e('Installed but disabled in WooCommerce', 'ccm-tools'); ?></span>
                                    <?php else : ?>
                                        <span class="ccm-chip ccm-chip--good"><?php _e('Enabled', 'ccm-tools'); ?></span>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div>
                                <span class="ccm-kv__k"><?php _e('Bank Transfer', 'ccm-tools'); ?></span>
                                <span class="ccm-kv__v">
                                    <?php if (empty($payment_gateways_info['bacs_available'])) : ?>
                                        <span class="ccm-chip"><?php _e('Not installed', 'ccm-tools'); ?></span>
                                    <?php elseif (empty($payment_gateways_info['bacs_enabled'])) : ?>
                                        <span class="ccm-chip ccm-chip--warn"><?php _e('Installed but disabled in WooCommerce', 'ccm-tools'); ?></span>
                                    <?php else : ?>
                                        <span class="ccm-chip ccm-chip--good"><?php _e('Enabled', 'ccm-tools'); ?></span>
                                    <?php endif; ?>
                                </span>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <details class="ccm-disclose" style="margin-top: var(--ccm-space-xl);">
                    <summary><?php _e('Store details', 'ccm-tools'); ?></summary>
                    <div class="ccm-disclose__body ccm-panel__body--flush">
                        <div class="ccm-kv">
                            <div><span class="ccm-kv__k"><?php _e('WooCommerce version', 'ccm-tools'); ?></span>
                                 <span class="ccm-kv__v"><?php echo esc_html($woocommerce_info['version'] ?? '-'); ?></span></div>
                            <div><span class="ccm-kv__k"><?php _e('Currency', 'ccm-tools'); ?></span>
                                 <span class="ccm-kv__v"><?php echo esc_html($woocommerce_info['currency'] ?? '-'); ?></span></div>
                            <div><span class="ccm-kv__k"><?php _e('Base country', 'ccm-tools'); ?></span>
                                 <span class="ccm-kv__v"><?php echo esc_html($woocommerce_info['country'] ?? '-'); ?></span></div>
                            <div><span class="ccm-kv__k"><?php _e('Available gateways', 'ccm-tools'); ?></span>
                                 <span class="ccm-kv__v"><?php
                                    $gws = (array) ($woocommerce_info['gateways'] ?? array());
                                    echo $gws ? esc_html(implode(', ', $gws)) : esc_html__('none', 'ccm-tools');
                                 ?></span></div>
                        </div>
                    </div>
                </details>

                <div id="woocommerce-result" class="ccm-result-box" style="display:none;"></div>

            <?php endif; ?>

            </div>
        </div>
        <?php
    }
    
    /**
     * Debug page callback
     */
    public function create_debug_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'ccm-tools'));
        }
        
        $debug_info = $this->debug_front_page_settings();
        ?>
        <div class="wrap ccm-tools">
            <h1>Front Page Debug Information</h1>
            
            <div class="ccm-card">
                <h2>WordPress Front Page Settings</h2>
                <table class="ccm-table">
                    <tr>
                        <th>Show on Front</th>
                        <td><?php echo esc_html($debug_info['show_on_front']); ?></td>
                    </tr>
                    <tr>
                        <th>Page on Front (Front Page ID)</th>
                        <td><?php echo esc_html($debug_info['page_on_front']); ?></td>
                    </tr>
                    <tr>
                        <th>Page for Posts</th>
                        <td><?php echo esc_html($debug_info['page_for_posts']); ?></td>
                    </tr>
                    <tr>
                        <th>Current Screen</th>
                        <td><?php echo esc_html($debug_info['current_screen'] ? $debug_info['current_screen']->base . ' (' . $debug_info['current_screen']->post_type . ')' : 'N/A'); ?></td>
                    </tr>
                    <tr>
                        <th>Current Page Now</th>
                        <td><?php echo esc_html($debug_info['current_pagenow']); ?></td>
                    </tr>
                    <tr>
                        <th>Current Post Type</th>
                        <td><?php echo esc_html($debug_info['current_post_type']); ?></td>
                    </tr>
                </table>
            </div>
            
            <?php if ($debug_info['page_on_front']): ?>
            <div class="ccm-card">
                <h2>Front Page Details</h2>
                <?php 
                $front_page = get_post($debug_info['page_on_front']);
                if ($front_page): ?>
                <table class="ccm-table">
                    <tr>
                        <th>Title</th>
                        <td><?php echo esc_html($front_page->post_title); ?></td>
                    </tr>
                    <tr>
                        <th>Post Type</th>
                        <td><?php echo esc_html($front_page->post_type); ?></td>
                    </tr>
                    <tr>
                        <th>Status</th>
                        <td><?php echo esc_html($front_page->post_status); ?></td>
                    </tr>
                    <tr>
                        <th>Date</th>
                        <td><?php echo esc_html($front_page->post_date); ?></td>
                    </tr>
                </table>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <div class="ccm-card">
                <h2>Instructions</h2>
                <p>If the front page is not appearing at the top of your page list:</p>
                <ol>
                    <li>Make sure "Page on Front" has a valid ID (not 0)</li>
                    <li>Go to Pages → All Pages</li>
                    <li>Check if you see the debug notice at the top</li>
                    <li>Look for the 🏠 Front Page indicator</li>
                </ol>
            </div>
        </div>
        <?php
    }
}
?>