<?php
/**
 * Plugin Name: CCM Tools
 * Plugin URI: https://clickclickmedia.com.au/
 * Description: CCM Tools is a WordPress utility plugin that helps administrators monitor and optimize their WordPress installation. It provides system information, database tools, and .htaccess optimization features.
 * Version: 7.9.8
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

// Define plugin constants only if they don't already exist
if (!defined('CCM_HELPER_VERSION')) {
    define('CCM_HELPER_VERSION', '7.9.8');
}

// Better duplicate detection mechanism that only checks active plugins
$ccm_is_duplicate = false;

if (!function_exists('ccm_check_for_duplicates')) {
    function ccm_check_for_duplicates() {
        $active_plugins = apply_filters('active_plugins', get_option('active_plugins'));
        $current_plugin = plugin_basename(__FILE__);
        $ccm_instances = 0;
        
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
    global $ccm_is_duplicate;
    
    // Only show our duplicate plugin warning if needed
    if ($ccm_is_duplicate) {
        echo '<div class="notice notice-error is-dismissible"><p>';
        echo 'Another instance of CCM Tools is already active. Please deactivate it before using this version.';
        echo '</p></div>';
    }
}

// Only run duplicate checking if we're actually finding a duplicate
if ($ccm_is_duplicate) {
    // Deactivate this instance if another one is already running
    add_action('admin_notices', function() {
        echo '<div class="notice notice-error is-dismissible"><p>';
        echo 'Another instance of CCM Tools is already active. Please deactivate it before using this version.';
        echo '</p></div>';
    });
    
    // Deactivate the newer plugin
    add_action('admin_init', function() {
        deactivate_plugins(plugin_basename(__FILE__));
    });
    
    // Stop loading this plugin
    return;
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
 */
function ccm_initialize_plugin() {
    // Remove the problematic class check that's preventing initialization
    // and use our improved duplicate detection instead
    if (isset($GLOBALS['ccm_is_duplicate']) && $GLOBALS['ccm_is_duplicate'] === true) {
        return;
    }
    
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
    require_once CCM_HELPER_ROOT_DIR . 'inc/performance-optimizer.php';
    require_once CCM_HELPER_ROOT_DIR . 'inc/redis-object-cache.php'; // Add Redis Object Cache
    
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
                <?php if ($woocommerce_active): ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=ccm-tools-woocommerce')); ?>" class="ccm-tab <?php echo $active_page === 'ccm-tools-woocommerce' ? 'active' : ''; ?>"><?php _e('WooCommerce', 'ccm-tools'); ?></a>
                <?php endif; ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=ccm-tools-error-log')); ?>" class="ccm-tab <?php echo $active_page === 'ccm-tools-error-log' ? 'active' : ''; ?>"><?php _e('Error Log', 'ccm-tools'); ?></a>
            </div>
        </nav>
        <div class="ccm-header-title">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
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
    
    public function enqueue_admin_scripts($hook): void {
        // Only load on our plugin pages
        if (strpos($hook, 'ccm-tools') !== false) {
            // Modern pure CSS - no external dependencies
            wp_enqueue_style('ccm-tools-style', CCM_HELPER_ROOT_URL . 'css/style.css', array(), CCM_HELPER_VERSION);
            
            // Modern vanilla JS - no jQuery required
            wp_enqueue_script('ccm-tools-script', CCM_HELPER_ROOT_URL . 'js/main.js', array(), CCM_HELPER_VERSION, true);
            wp_localize_script('ccm-tools-script', 'ccmToolsData', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('ccm-tools-nonce'),
                'i18n' => array(
                    'confirmConvert' => __('Are you sure you want to convert all tables? This operation cannot be undone.', 'ccm-tools'),
                    'confirmOptimize' => __('Are you sure you want to optimize the database? This operation cannot be undone.', 'ccm-tools'),
                    'confirmAddHtaccess' => __('Are you sure you want to add optimizations to .htaccess? This will modify your .htaccess file.', 'ccm-tools'),
                    'confirmRemoveHtaccess' => __('Are you sure you want to remove optimizations from .htaccess? This will modify your .htaccess file.', 'ccm-tools'),
                    'confirmClearLog' => __('Are you sure you want to clear this log file? This operation cannot be undone.', 'ccm-tools'),
                    'downloadFailed' => __('Failed to download log file. Please try again.', 'ccm-tools'),
                    'confirmEnableDebug' => __('WARNING: Enabling WP_DEBUG will display PHP errors, notices, and warnings on your website. This should only be used on development or staging sites. Are you sure you want to enable debug mode?', 'ccm-tools'),
                    'confirmEnableDebugDisplay' => __('WARNING: Enabling WP_DEBUG_DISPLAY will show PHP errors directly on your website frontend. This is only recommended for development sites. Are you sure you want to enable debug display?', 'ccm-tools'),
                    'debugModeRequired' => __('Note: WP Debug Mode must be enabled first', 'ccm-tools'),
                    'warningFrontendErrors' => __('Warning: Errors will be displayed on the frontend', 'ccm-tools'),
                    // TTFB related messages
                    'measuring' => __('Measuring...', 'ccm-tools'),
                    'measurementFailed' => __('Measurement failed', 'ccm-tools'),
                    'refresh' => __('Refresh', 'ccm-tools'),
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
                    'enabling' => __('Enabling...', 'ccm-tools'),
                    'disabling' => __('Disabling...', 'ccm-tools'),
                    'wooToggleFailed' => __('Failed to toggle setting.', 'ccm-tools')
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
        
        // Only apply our custom ordering if it's marked for front page prioritization
        if (!$query->get('ccm_prioritize_front_page')) {
            return $orderby;
        }
        
        $front_page_id = $query->get('ccm_front_page_id');
        if (!$front_page_id) {
            return $orderby;
        }
        
        // Create custom ORDER BY clause that puts front page first, then orders by date descending
        $custom_orderby = "CASE WHEN {$wpdb->posts}.ID = " . intval($front_page_id) . " THEN 0 ELSE 1 END ASC, {$wpdb->posts}.post_date DESC";
        
        return $custom_orderby;
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
        
        // Clear the opcache if available to ensure fresh constants
        if (function_exists('opcache_reset')) {
            opcache_reset();
        }
        
        // Re-include wp-config.php to refresh constants
        if (file_exists(ABSPATH . 'wp-config.php')) {
            // Use include_once to avoid errors
            @include_once ABSPATH . 'wp-config.php';
        }
        
        // Clear opcache to ensure the latest constants are loaded
        if (function_exists('opcache_reset')) {
            opcache_reset();
        }
        
        // Attempt to clear any PHP opcode cache
        if (function_exists('opcache_invalidate') && file_exists(ABSPATH . 'wp-config.php')) {
            opcache_invalidate(ABSPATH . 'wp-config.php', true);
        }
        
        // Clear the WordPress file cache if available
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
        
        // Initialize Redis variables before using them
        $redis_status = array('server_available' => false, 'version' => '');
        $redis_config = array('configured' => false, 'constants' => array());
        $redis_plugin = array('installed' => false, 'active' => false, 'object_cache_enabled' => false, 'version' => '');
        $server_status_class = 'ccm-error';
        $server_status_text = __('Not Available', 'ccm-tools');
        $config_status_class = 'ccm-warning';
        $config_status_text = __('Not Configured', 'ccm-tools');
        $plugin_status_class = 'ccm-warning';
        $plugin_status_text = __('Not Installed', 'ccm-tools');
        $cache_status_class = 'ccm-warning';
        $cache_status_text = __('Not Available', 'ccm-tools');
        
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
        
        // Double-check against the file content
        if (!empty($wp_config_content)) {
            if (preg_match('/define\s*\(\s*[\'"]WP_DEBUG[\'"]\s*,\s*(true|false)\s*\)/i', $wp_config_content, $matches)) {
                $debug_mode_enabled = strtolower($matches[1]) === 'true';
            }
            if (preg_match('/define\s*\(\s*[\'"]WP_DEBUG_LOG[\'"]\s*,\s*(true|false)\s*\)/i', $wp_config_content, $matches)) {
                $debug_log_enabled = strtolower($matches[1]) === 'true';
            }
            if (preg_match('/define\s*\(\s*[\'"]WP_DEBUG_DISPLAY[\'"]\s*,\s*(true|false)\s*\)/i', $wp_config_content, $matches)) {
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
            $redis_plugin = ccm_tools_check_redis_plugin();
            
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
            
            // Redis Plugin Status
            $plugin_status_text = '';
            $plugin_status_class = 'ccm-warning';
            
            if (!$redis_plugin['installed']) {
                $plugin_status_text = __('Not Installed', 'ccm-tools');
            } else if (!$redis_plugin['active']) {
                $plugin_status_text = __('Inactive', 'ccm-tools');
            } else {
                $plugin_status_text = __('Active', 'ccm-tools');
                $plugin_status_class = 'ccm-success';
            }
            
            // Redis Object Cache Status (separate from plugin status)
            $cache_status_text = '';
            $cache_status_class = 'ccm-warning';
            
            if (!$redis_plugin['active']) {
                $cache_status_text = __('Not Available', 'ccm-tools');
            } else if (!$redis_plugin['object_cache_enabled']) {
                $cache_status_text = __('Disabled', 'ccm-tools');
            } else {
                $cache_status_text = __('Enabled', 'ccm-tools');
                $cache_status_class = 'ccm-success';
            }
        }
        
        ?>
        <div class="wrap ccm-tools">
            <?php ccm_tools_render_header_nav('ccm-tools'); ?>
            
            <div class="ccm-content">
                <!-- Disk Information Card -->
                <div class="ccm-card">
                    <h2><?php _e('Disk Information', 'ccm-tools'); ?></h2>
                    <?php
                    $disk_info = ccm_tools_get_disk_info();
                    if (!empty($disk_info)) {
                        // Calculate a color based on disk usage (green to red)
                        $used_percent_value = floatval($disk_info['used_percent']);
                        $color_class = 'ccm-success';
                        if ($used_percent_value > 85) {
                            $color_class = 'ccm-error';
                        } elseif ($used_percent_value > 70) {
                            $color_class = 'ccm-warning';
                        }
                    ?>
                        <div class="ccm-disk-usage">
                            <div class="ccm-disk-bar">
                                <div class="ccm-disk-used <?php echo esc_attr($color_class); ?>" style="width: <?php echo esc_attr($disk_info['used_percent']); ?>;">
                                    <?php echo esc_html($disk_info['used_percent']); ?>
                                </div>
                            </div>
                            <div class="ccm-disk-info">
                                <p><?php _e('Used Space:', 'ccm-tools'); ?> <strong><?php echo esc_html($disk_info['used']); ?></strong> (<?php echo esc_html($disk_info['used_percent']); ?>)</p>
                                <p><?php _e('Free Space:', 'ccm-tools'); ?> <strong><?php echo esc_html($disk_info['free']); ?></strong> (<?php echo esc_html($disk_info['free_percent']); ?>)</p>
                                <p><?php _e('Total Space:', 'ccm-tools'); ?> <strong><?php echo esc_html($disk_info['total']); ?></strong></p>
                            </div>
                        </div>
                    <?php } else { ?>
                        <p class="ccm-warning"><span class="ccm-icon">⚠</span> <?php _e('Disk information is not available.', 'ccm-tools'); ?></p>
                    <?php } ?>
                </div>
                
                <!-- Uploads Backup Card -->
                <div class="ccm-card">
                    <h2><?php _e('Uploads Backup', 'ccm-tools'); ?></h2>
                    <?php if (class_exists('ZipArchive') || extension_loaded('zip')) : ?>
                        <p class="ccm-text-muted"><?php _e('Create a downloadable ZIP backup of your uploads folder.', 'ccm-tools'); ?></p>
                        
                        <div id="backup-info" style="margin: var(--ccm-space-md) 0;">
                            <p><span class="ccm-icon">📁</span> <?php _e('Loading uploads information...', 'ccm-tools'); ?></p>
                        </div>
                        
                        <div id="backup-actions" style="display: flex; gap: var(--ccm-space-sm); flex-wrap: wrap; align-items: center;">
                            <button type="button" id="start-uploads-backup" class="ccm-button ccm-button-primary">
                                <?php _e('Create Backup', 'ccm-tools'); ?>
                            </button>
                            <button type="button" id="cancel-uploads-backup" class="ccm-button ccm-button-danger" style="display: none;">
                                <?php _e('Cancel', 'ccm-tools'); ?>
                            </button>
                        </div>
                        
                        <div id="backup-progress" style="display: none; margin-top: var(--ccm-space-md);">
                            <div class="ccm-progress-info">
                                <p><?php _e('Processing:', 'ccm-tools'); ?> <span id="backup-current">0</span>/<span id="backup-total">0</span> <?php _e('files', 'ccm-tools'); ?> (<span id="backup-percent">0</span>%)</p>
                                <div class="ccm-progress-bar">
                                    <div class="ccm-progress-fill" id="backup-progress-bar" style="width: 0%"></div>
                                </div>
                            </div>
                        </div>
                        
                        <div id="backup-complete" style="display: none; margin-top: var(--ccm-space-md);">
                            <div class="ccm-alert ccm-alert-success">
                                <span class="ccm-icon">✓</span>
                                <div>
                                    <strong><?php _e('Backup Complete!', 'ccm-tools'); ?></strong>
                                    <p><?php _e('File size:', 'ccm-tools'); ?> <span id="backup-size"></span></p>
                                    <p style="margin-top: var(--ccm-space-sm); display: flex; gap: var(--ccm-space-sm); flex-wrap: wrap;">
                                        <a href="#" id="download-backup" class="ccm-button ccm-button-primary"><?php _e('Download Backup', 'ccm-tools'); ?></a>
                                        <button type="button" id="delete-backup" class="ccm-button ccm-button-danger"><?php _e('Delete Backup', 'ccm-tools'); ?></button>
                                    </p>
                                    <p class="ccm-text-muted" style="font-size: var(--ccm-text-sm); margin-top: var(--ccm-space-sm);">
                                        <?php _e('Backup files are automatically deleted after 24 hours.', 'ccm-tools'); ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    <?php else : ?>
                        <div class="ccm-alert ccm-alert-warning">
                            <span class="ccm-icon">⚠</span>
                            <div>
                                <strong><?php _e('ZipArchive Not Available', 'ccm-tools'); ?></strong>
                                <p><?php _e('The PHP ZipArchive extension is not installed on this server. Contact your hosting provider to enable it.', 'ccm-tools'); ?></p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                    
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
                    // Helper function to convert PHP size values to bytes
                    function ccm_tools_convert_php_size_to_bytes($size) {
                        if (is_numeric($size)) {
                            return (int) $size;
                        }
                        
                        $size = trim($size);
                        $last = strtolower($size[strlen($size) - 1]);
                        $size = (int) $size;
                        
                        switch ($last) {
                            case 'g':
                                $size *= 1024;
                            case 'm':
                                $size *= 1024;
                            case 'k':
                                $size *= 1024;
                        }
                        
                        return $size;
                    }
                    
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
                    
                    // Determine status classes and suggestions
                    $memory_class = $memory_limit_bytes < $memory_limit_threshold ? 'ccm-error' : 'ccm-success';
                    $memory_suggestion = $memory_limit_bytes < $memory_limit_threshold ? __('Recommend: 512M or higher', 'ccm-tools') : '';
                    
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
                    </table>
                </div>
                
                <!-- WordPress Environment Card -->
                <div class="ccm-card">
                    <h2><?php _e('WordPress Environment', 'ccm-tools'); ?></h2>
                    <?php 
                    global $wp_version;
                    
                    // Delete the update cache to force a fresh check
                    delete_site_transient('update_core');
                    
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
                        
                        <!-- TTFB Measurement - New Row (Deferred load for faster page rendering) -->
                        <tr>
                            <th><?php _e('Time To First Byte (TTFB)', 'ccm-tools'); ?></th>
                            <td>
                                <div class="ccm-config-control" style="display: flex; align-items: center; justify-content: space-between;">
                                    <div style="display: flex; align-items: center; flex: 1;">
                                        <div id="ttfb-result" style="margin-right: 10px;" data-auto-load="true">
                                            <div class="ccm-spinner ccm-spinner-small"></div>
                                            <span class="ccm-text-muted"><?php _e('Measuring...', 'ccm-tools'); ?></span>
                                        </div>
                                        <span class="ccm-info-icon" 
                                              title="<?php esc_attr_e('TTFB Measurement Info: Enhanced measurement uses multiple attempts with server warmup for accuracy. Baseline measurement shows realistic cached performance, Fresh measurement shows worst-case uncached performance. Results are averaged and outliers removed for consistency.', 'ccm-tools'); ?>">
                                            ℹ
                                        </span>
                                    </div>
                                    <button id="refresh-ttfb" class="ccm-button ccm-button-small" title="<?php esc_attr_e('Refresh TTFB measurement', 'ccm-tools'); ?>">
                                        ↻ <?php _e('Refresh', 'ccm-tools'); ?>
                                    </button>
                                </div>
                            </td>
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
        ?>
        <div class="wrap ccm-tools">
            <?php ccm_tools_render_header_nav('ccm-tools-database'); ?>
            
            <div class="ccm-content">
                <div class="ccm-card">
                    <h2><?php _e('Database Tools', 'ccm-tools'); ?></h2>
                    <p><?php _e('Use these tools to optimize your WordPress database.', 'ccm-tools'); ?></p>
                    <div class="ccm-buttons">
                        <button id="ct" class="ccm-button"><?php _e('Convert Tables to InnoDB', 'ccm-tools'); ?></button>
                    </div>
                    <div id="infoBox" class="ccm-info-box"></div>
                    <div id="resultBox" class="ccm-result-box"></div>
                </div>
                
                <div class="ccm-card">
                    <h2><?php _e('Database Optimization', 'ccm-tools'); ?></h2>
                    <p><?php _e('Select the optimization tasks you want to run. Safe options are checked by default.', 'ccm-tools'); ?></p>
                    
                    <div id="optimization-options" class="ccm-optimization-options">
                        <div class="ccm-loading">
                            <div class="ccm-spinner"></div>
                            <span><?php _e('Loading optimization options...', 'ccm-tools'); ?></span>
                        </div>
                    </div>
                    
                    <div class="ccm-buttons" style="margin-top: 1rem;">
                        <button id="run-optimizations" class="ccm-button ccm-button-primary" disabled><?php _e('Run Selected Optimizations', 'ccm-tools'); ?></button>
                        <button id="select-all-safe" class="ccm-button ccm-button-secondary"><?php _e('Select Safe Options', 'ccm-tools'); ?></button>
                        <button id="deselect-all" class="ccm-button ccm-button-secondary"><?php _e('Deselect All', 'ccm-tools'); ?></button>
                    </div>
                    
                    <div id="optimization-results" class="ccm-result-box" style="display: none;"></div>
                </div>
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
                <div class="ccm-card">
                    <h2><?php _e('.htaccess Optimization', 'ccm-tools'); ?></h2>
                    <p><?php _e('Manage .htaccess optimizations for better performance.', 'ccm-tools'); ?></p>
                    <div id="infoBox" class="ccm-info-box"></div>
                    <div id="resultBox" class="ccm-result-box">
                        <?php 
                        // Directly load the .htaccess content
                        echo ccm_tools_display_htaccess(); 
                        ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Error Log viewer page callback
     */
    function ccm_tools_render_error_log_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'ccm-tools'));
        }
        ?>
        <div class="wrap ccm-tools">
            <?php ccm_tools_render_header_nav('ccm-tools-error-log'); ?>
            <!-- ...rest of error log page... -->
        </div>
        <?php
    }

    /**
     * WooCommerce tools page callback
     */
    public function create_woocommerce_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'ccm-tools'));
        }
        
        // Check if WooCommerce is active
        $woocommerce_active = ccm_tools_is_woocommerce_active();
        $woocommerce_info = $woocommerce_active ? ccm_tools_get_woocommerce_info() : false;
        $payment_gateways_info = $woocommerce_active ? ccm_tools_check_payment_gateways() : false;
        $admin_payment_enabled = get_option('ccm_woo_admin_payment_enabled', 'no') === 'yes';
        
        ?>
        <div class="wrap ccm-tools">
            <?php ccm_tools_render_header_nav('ccm-tools-woocommerce'); ?>
            
            <div class="ccm-content">
                <?php if (!$woocommerce_active): ?>
                    <!-- WooCommerce Not Active Warning -->
                    <div class="ccm-card">
                        <h2><?php _e('WooCommerce Not Active', 'ccm-tools'); ?></h2>
                        <p class="ccm-error">
                            <span class="ccm-icon">⚠</span>
                            <?php _e('WooCommerce plugin is not active. Please install and activate WooCommerce to use these tools.', 'ccm-tools'); ?>
                        </p>
                        <div class="ccm-buttons">
                            <a href="<?php echo esc_url(admin_url('plugin-install.php?s=woocommerce&tab=search&type=term')); ?>" class="ccm-button">
                                <?php _e('Install WooCommerce', 'ccm-tools'); ?>
                            </a>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- WooCommerce Information Card -->
                    <div class="ccm-card">
                        <h2><?php _e('WooCommerce Information', 'ccm-tools'); ?></h2>
                        <table class="ccm-table">
                            <tr>
                                <th><?php _e('WooCommerce Version', 'ccm-tools'); ?></th>
                                <td><?php echo esc_html($woocommerce_info['version']); ?></td>
                            </tr>
                            <tr>
                                <th><?php _e('Total Orders', 'ccm-tools'); ?></th>
                                <td><?php echo esc_html(number_format($woocommerce_info['orders_count'])); ?></td>
                            </tr>
                            <tr>
                                <th><?php _e('Total Products', 'ccm-tools'); ?></th>
                                <td><?php echo esc_html(number_format($woocommerce_info['products_count'])); ?></td>
                            </tr>
                            <tr>
                                <th><?php _e('Total Customers', 'ccm-tools'); ?></th>
                                <td><?php echo esc_html(number_format($woocommerce_info['customers_count'])); ?></td>
                            </tr>
                            <tr>
                                <th><?php _e('Store Currency', 'ccm-tools'); ?></th>
                                <td><?php echo esc_html($woocommerce_info['currency'] . ' (' . $woocommerce_info['currency_symbol'] . ')'); ?></td>
                            </tr>
                            <tr>
                                <th><?php _e('Base Country', 'ccm-tools'); ?></th>
                                <td><?php echo esc_html($woocommerce_info['base_country']); ?></td>
                            </tr>
                            <tr>
                                <th><?php _e('Available Payment Gateways', 'ccm-tools'); ?></th>
                                <td><?php echo esc_html(implode(', ', $woocommerce_info['payment_gateways'])); ?></td>
                            </tr>
                        </table>
                    </div>
                    
                    <!-- Admin-Only Payment Methods Tool -->
                    <div class="ccm-card">
                        <h2><?php _e('Admin-Only Payment Methods', 'ccm-tools'); ?></h2>
                        <p><?php _e('This feature restricts Cash on Delivery (COD) and Bank Transfer (BACS) payment methods to administrators only. This is useful for testing checkout processes without exposing these payment methods to regular customers.', 'ccm-tools'); ?></p>
                        
                        <!-- Payment Gateway Status -->
                        <div class="ccm-gateway-status" style="margin: 15px 0;">
                            <h4><?php _e('Payment Gateway Status:', 'ccm-tools'); ?></h4>
                            <div style="margin: 10px 0;">
                                <strong><?php _e('Cash on Delivery (COD):', 'ccm-tools'); ?></strong>
                                <?php if ($payment_gateways_info['cod_available']): ?>
                                    <span class="<?php echo $payment_gateways_info['cod_enabled'] ? 'ccm-success' : 'ccm-warning'; ?>">
                                        <?php echo $payment_gateways_info['cod_enabled'] ? __('Available & Enabled', 'ccm-tools') : __('Available but Disabled', 'ccm-tools'); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="ccm-error"><?php _e('Not Available', 'ccm-tools'); ?></span>
                                <?php endif; ?>
                            </div>
                            <div style="margin: 10px 0;">
                                <strong><?php _e('Bank Transfer (BACS):', 'ccm-tools'); ?></strong>
                                <?php if ($payment_gateways_info['bacs_available']): ?>
                                    <span class="<?php echo $payment_gateways_info['bacs_enabled'] ? 'ccm-success' : 'ccm-warning'; ?>">
                                        <?php echo $payment_gateways_info['bacs_enabled'] ? __('Available & Enabled', 'ccm-tools') : __('Available but Disabled', 'ccm-tools'); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="ccm-error"><?php _e('Not Available', 'ccm-tools'); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <?php if (!$payment_gateways_info['cod_available'] && !$payment_gateways_info['bacs_available']): ?>
                            <div class="ccm-warning" style="margin: 15px 0; padding: 10px;">
                                <span class="ccm-icon">⚠</span>
                                <?php _e('Neither Cash on Delivery nor Bank Transfer payment methods are available. Please enable them in WooCommerce Settings > Payments to use this feature.', 'ccm-tools'); ?>
                            </div>
                        <?php elseif (!$payment_gateways_info['cod_enabled'] && !$payment_gateways_info['bacs_enabled']): ?>
                            <div class="ccm-info" style="margin: 15px 0; padding: 10px;">
                                <span class="ccm-icon">ℹ</span>
                                <?php _e('Both payment methods are available but currently disabled in WooCommerce. Enable them in WooCommerce Settings > Payments before using this feature.', 'ccm-tools'); ?>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Admin Payment Toggle -->
                        <div class="ccm-config-control" style="margin: 20px 0;">
                            <div>
                                <strong><?php _e('Admin-Only Payment Methods:', 'ccm-tools'); ?></strong>
                                <span class="<?php echo $admin_payment_enabled ? 'ccm-success' : 'ccm-warning'; ?>">
                                    <?php echo $admin_payment_enabled ? __('Enabled', 'ccm-tools') : __('Disabled', 'ccm-tools'); ?>
                                </span>
                            </div>
                            <button id="toggle-admin-payment" 
                                    class="ccm-button" 
                                    data-enabled="<?php echo $admin_payment_enabled ? 'true' : 'false'; ?>"
                                    <?php echo (!$payment_gateways_info['cod_available'] && !$payment_gateways_info['bacs_available']) ? 'disabled' : ''; ?>>
                                <?php echo $admin_payment_enabled ? __('Disable', 'ccm-tools') : __('Enable', 'ccm-tools'); ?>
                            </button>
                        </div>
                        
                        <?php if ($admin_payment_enabled): ?>
                            <div class="ccm-info" style="margin: 15px 0; padding: 10px;">
                                <span class="ccm-icon">ℹ</span>
                                <strong><?php _e('Feature Active:', 'ccm-tools'); ?></strong>
                                <?php _e('Cash on Delivery and Bank Transfer payment methods are now restricted to administrators only. Regular customers will not see these payment options during checkout.', 'ccm-tools'); ?>
                            </div>
                        <?php endif; ?>
                        
                        <div id="woocommerce-result" class="ccm-result-box"></div>
                    </div>
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