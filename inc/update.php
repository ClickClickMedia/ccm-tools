<?php
/**
 * GitHub-based plugin updates
 * 
 * Based on the well-tested pattern used by many WordPress plugins
 * that update from GitHub repositories.
 *
 * @package CCM Tools
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * CCM GitHub Updater
 * 
 * A streamlined updater class that follows WordPress conventions
 * and properly handles GitHub releases.
 */
class CCM_GitHub_Updater {
    private $file;             // Plugin file path
    private $plugin;           // Plugin basename
    private $basename;         // Plugin directory name
    private $active;           // Whether the plugin is active
    private $username;         // GitHub username
    private $repository;       // GitHub repository name
    private $authorize_token;  // GitHub API token
    private $github_response;  // Cached GitHub API response
    
    /**
     * Class constructor
     * 
     * @param string $file The path to the main plugin file
     */
    public function __construct($file) {
        // Set class properties
        $this->file = $file;
        $this->plugin = plugin_basename($file);
        $this->basename = dirname($this->plugin);
        $this->active = is_plugin_active($this->plugin);
        
        // Set GitHub information
        $this->username = 'ClickClickMedia';
        $this->repository = 'ccm-tools';
        // Token is OPTIONAL for public repositories
        // For private repos, define CCM_GITHUB_TOKEN in wp-config.php: define('CCM_GITHUB_TOKEN', 'your_token');
        // Authenticated requests get higher API rate limits (5000/hr vs 60/hr)
        $this->authorize_token = defined('CCM_GITHUB_TOKEN') ? CCM_GITHUB_TOKEN : '';
        
        // Add required hooks with higher priority to ensure they run early
        add_filter('pre_set_site_transient_update_plugins', array($this, 'modify_transient'), 5, 1);
        add_filter('plugins_api', array($this, 'plugin_popup'), 10, 3);
        add_filter('upgrader_post_install', array($this, 'after_install'), 10, 3);
        add_filter('http_request_args', array($this, 'add_auth_to_request'), 10, 2);
        
        // Add filter to ensure icons are available in plugin update data
        add_filter('all_plugins', array($this, 'add_plugin_icons_to_data'), 10, 1);
        add_filter('get_plugin_data', array($this, 'add_plugin_icons_to_update_data'), 10, 2);
        
        // Critical: Add hook to inject icons directly when WordPress processes plugin updates
        add_filter('site_transient_update_plugins', array($this, 'inject_plugin_icons'), 99);
        add_filter('all_plugins', array($this, 'inject_plugin_icons_to_all_plugins'), 99);
        
        add_action('admin_head-update-core.php', array($this, 'force_plugin_icons_css'));
        
        // Force update check on plugins page load
        add_action('load-plugins.php', array($this, 'force_update_check_on_plugins_page'));
        
        // Cleanup maintenance file if update fails
        add_action('activated_plugin', array($this, 'check_maintenance_file'));
        add_action('deactivated_plugin', array($this, 'check_maintenance_file'));
        add_action('admin_init', array($this, 'check_maintenance_file'));
    }
    
    /**
     * Add repository information to update transient
     * 
     * @param object $transient Update transient
     * @return object Modified transient
     */
    public function modify_transient($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        // Get release information from GitHub
        $this->get_repository_info();
        
        // Check if we have a valid response
        if (empty($this->github_response) || !is_object($this->github_response)) {
            return $transient;
        }
        
        // IMPORTANT: Ensure plugin version is being compared correctly
        // Get latest plugin version from GitHub
        $github_version = $this->get_github_version();
        
        // Get current plugin version - try multiple approaches to ensure we get it
        $plugin_data = get_plugin_data($this->file);
        $current_version = $plugin_data['Version'];
        
        // Compare versions and add update information if newer
        if (version_compare($github_version, $current_version, '>')) {
            // Create plugin object with required fields
            $obj = new stdClass();
            $obj->slug = $this->basename;
            $obj->plugin = $this->plugin; 
            $obj->new_version = $github_version;
            $obj->url = $this->github_response->html_url;
            $obj->package = $this->get_download_url();
            $obj->tested = $this->get_current_wp_version();
            
            // Add plugin icons
            $plugin_url = plugin_dir_url($this->file);
            $obj->icons = array(
                'svg' => $plugin_url . 'assets/icon.svg',
                '1x' => $plugin_url . 'assets/icon.png',
                '2x' => $plugin_url . 'assets/icon.png',
                'default' => $plugin_url . 'assets/icon.svg'
            );
            
            // Force plugin into the response section for immediate update
            $transient->response[$this->plugin] = $obj;
        } else {
            // No update needed, but provide info for the 'View details' screen
            
            // Ensure plugin shows in no_update section for correct information display
            $obj = new stdClass();
            $obj->slug = $this->basename;
            $obj->plugin = $this->plugin;
            $obj->new_version = $github_version;
            $obj->url = $this->github_response->html_url;
            $obj->package = $this->get_download_url();
            $obj->tested = $this->get_current_wp_version();
            
            // Add plugin icons
            $plugin_url = plugin_dir_url($this->file);
            $obj->icons = array(
                'svg' => $plugin_url . 'assets/icon.svg',
                '1x' => $plugin_url . 'assets/icon.png',
                '2x' => $plugin_url . 'assets/icon.png',
                'default' => $plugin_url . 'assets/icon.svg'
            );
            
            $transient->no_update[$this->plugin] = $obj;
        }
        
        return $transient;
    }

    /**
     * Get the current WordPress version for compatibility reporting
     * 
     * @return string Current WordPress version
     */
    private function get_current_wp_version() {
        global $wp_version;
        return $wp_version;
    }

    /**
     * Get release information from GitHub
     * 
     * @return bool True if successful, false otherwise
     */
    private function get_repository_info() {
        // Check for cached response
        if (!empty($this->github_response)) {
            return true;
        }
        
        // Check if we have a cached response that's still valid
        $transient_key = 'ccm_github_' . md5($this->basename);
        $cached_response = get_transient($transient_key);
        
        if ($cached_response && is_object($cached_response)) {
            $this->github_response = $cached_response;
            return true;
        }
        
        // Make API request to GitHub
        $url = "https://api.github.com/repos/{$this->username}/{$this->repository}/releases/latest";
        
        $response = $this->api_request($url);
        
        // Check for valid response
        if (empty($response)) {
            return false;
        }
        
        // Cache response with reasonable duration
        $this->github_response = $response;
        set_transient($transient_key, $response, 30 * MINUTE_IN_SECONDS); // 30 minutes cache
        
        return true;
    }
    
    /**
     * Get the version number from GitHub
     * 
     * @return string Version number
     */
    private function get_github_version() {
        if (empty($this->github_response)) {
            return '0.0.0'; // Return a default version
        }
        
        // Remove 'v' prefix if present and ensure it's a clean version number
        $version = ltrim($this->github_response->tag_name, 'v');
        
        // Ensure it's a valid version format 
        if (strpos($version, '.') === false) {
            $version .= '.0'; // Convert single number to x.0 format
        }
        
        return $version;
    }
    
    /**
     * Get the download URL for the release
     * 
     * @return string Download URL
     */
    private function get_download_url() {
        if (empty($this->github_response)) {
            return '';
        }
        
        // First check for assets (preferred way)
        if (!empty($this->github_response->assets) && is_array($this->github_response->assets)) {
            foreach ($this->github_response->assets as $asset) {
                if (isset($asset->browser_download_url) && strpos($asset->name, '.zip') !== false) {
                    return $asset->browser_download_url;
                }
            }
        }
        
        // Fallback to zipball URL (auto-generated GitHub archive)
        if (isset($this->github_response->zipball_url)) {
            return $this->github_response->zipball_url;
        }
        
        return '';
    }
    
    /**
     * Override the plugin info popup with GitHub details
     * 
     * @param object $result The result object
     * @param string $action The API action being performed
     * @param object $args Plugin arguments
     * @return object Plugin info
     */
    public function plugin_popup($result, $action, $args) {
        // Only handle plugin information requests for this plugin
        if ($action !== 'plugin_information' || 
            !isset($args->slug) || 
            $args->slug !== $this->basename) {
            return $result;
        }
        
        // Get release information
        $this->get_repository_info();
        
        // Return early if we don't have information
        if (empty($this->github_response)) {
            return $result;
        }
        
        // Get plugin data
        $plugin_data = get_plugin_data($this->file);
        
        // Create response object
        $plugin_info = new stdClass();
        $plugin_info->name = $plugin_data['Name'];
        $plugin_info->slug = $this->basename;
        $plugin_info->version = $this->get_github_version();
        $plugin_info->author = $plugin_data['Author'];
        $plugin_info->author_profile = $plugin_data['AuthorURI'];
        $plugin_info->homepage = $plugin_data['PluginURI'] ?: $this->github_response->html_url;
        $plugin_info->requires = $plugin_data['RequiresWP'] ?: '5.0';
        $plugin_info->requires_php = $plugin_data['RequiresPHP'] ?: '7.0';
        $plugin_info->tested = $this->get_current_wp_version();  // Use current WordPress version
        
        // Format timestamps
        $plugin_info->last_updated = isset($this->github_response->published_at) 
                                   ? date('Y-m-d', strtotime($this->github_response->published_at)) 
                                   : date('Y-m-d');
        
        // Set sections
        $plugin_info->sections = array(
            'description' => $plugin_data['Description'],
            'changelog' => $this->get_changelog()
        );
        
        // Set download link
        $plugin_info->download_link = $this->get_download_url();
        
        // Add banners if they exist
        if (!empty($plugin_data['PluginBannerLow'])) {
            $plugin_info->banners = array(
                'low' => $plugin_data['PluginBannerLow'],
                'high' => $plugin_data['PluginBannerHigh'] ?: $plugin_data['PluginBannerLow']
            );
        }
        
        // Add plugin icons
        $plugin_url = plugin_dir_url($this->file);
        $plugin_info->icons = array(
            'svg' => $plugin_url . 'assets/icon.svg',
            '1x' => $plugin_url . 'assets/icon.png',
            '2x' => $plugin_url . 'assets/icon.png', // Could be a higher resolution version
            'default' => $plugin_url . 'assets/icon.svg'
        );
        
        return $plugin_info;
    }
    
    /**
     * Add plugin icons to plugin data for update-core.php
     * 
     * @param array $plugins List of all plugins
     * @return array Modified plugins array
     */
    public function add_plugin_icons_to_data($plugins) {
        if (isset($plugins[$this->plugin])) {
            // Ensure plugin icons are available in the plugin data
            $plugin_url = plugin_dir_url($this->file);
            $icons = array(
                'svg' => $plugin_url . 'assets/icon.svg',
                '1x' => $plugin_url . 'assets/icon.png',
                '2x' => $plugin_url . 'assets/icon.png',
                'default' => $plugin_url . 'assets/icon.svg'
            );
            
            // Add icons to plugin data if not already present
            if (!isset($plugins[$this->plugin]['icons'])) {
                $plugins[$this->plugin]['icons'] = $icons;
            }
        }
        
        return $plugins;
    }
    
    /**
     * Add plugin icons to plugin update data
     * 
     * @param array $plugin_data Plugin data
     * @param string $plugin_file Plugin file path
     * @return array Modified plugin data
     */
    public function add_plugin_icons_to_update_data($plugin_data, $plugin_file) {
        if ($plugin_file === $this->file) {
            $plugin_url = plugin_dir_url($this->file);
            $plugin_data['icons'] = array(
                'svg' => $plugin_url . 'assets/icon.svg',
                '1x' => $plugin_url . 'assets/icon.png',
                '2x' => $plugin_url . 'assets/icon.png',
                'default' => $plugin_url . 'assets/icon.svg'
            );
        }
        
        return $plugin_data;
    }
    
    /**
     * Format the changelog from GitHub release body
     * 
     * @return string Formatted changelog
     */
    private function get_changelog() {
        if (empty($this->github_response) || empty($this->github_response->body)) {
            return 'No changelog provided';
        }
        
        // Simple markdown to HTML conversion
        $changelog = $this->github_response->body;
        $changelog = preg_replace('/\r\n|\r/', "\n", $changelog);
        $changelog = preg_replace('/###(.*?)\n/', '<h3>$1</h3>', $changelog);
        $changelog = preg_replace('/##(.*?)\n/', '<h2>$1</h2>', $changelog);
        $changelog = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $changelog);
        $changelog = preg_replace('/\*(.*?)\*/', '<em>$1</em>', $changelog);
        $changelog = preg_replace('/- (.*?)(\n|$)/', '<li>$1</li>', $changelog);
        $changelog = preg_replace('/((?:<li>.*<\/li>\n?)+)/', '<ul>$1</ul>', $changelog);
        
        return $changelog;
    }
    
    /**
     * Perform actions after plugin update
     * 
     * @param bool $response Installation response
     * @param array $hook_extra Extra arguments
     * @param array $result Installation result
     * @return array Result
     */
    public function after_install($response, $hook_extra, $result) {
        global $wp_filesystem;
        
        // Check if we're updating this plugin
        if (!isset($hook_extra['plugin']) || $hook_extra['plugin'] != $this->plugin) {
            return $response;
        }
        
        // Ensure we have WP_Filesystem available
        if (!$wp_filesystem) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
            global $wp_filesystem;
        }
        
        // Get the install directory
        $install_directory = plugin_dir_path($this->file);
        $wp_filesystem->move($result['destination'], $install_directory);
        $result['destination'] = $install_directory;
        
        // Re-activate plugin if it was active
        if ($this->active) {
            activate_plugin($this->plugin);
        }
        
        // Clean up maintenance file
        $this->check_maintenance_file();
        
        return $result;
    }
    
    /**
     * Add authentication to GitHub API requests
     * 
     * @param array $args Request arguments
     * @param string $url URL being requested
     * @return array Modified request arguments
     */
    public function add_auth_to_request($args, $url) {
        // Only add token to GitHub URLs
        if (strpos($url, 'github.com') === false && strpos($url, 'api.github.com') === false) {
            return $args;
        }
        
        // Add token if available
        if (!empty($this->authorize_token)) {
            if (!isset($args['headers'])) {
                $args['headers'] = array();
            }
            
            $args['headers']['Authorization'] = 'Bearer ' . $this->authorize_token;
            $args['headers']['Accept'] = 'application/vnd.github+json';
            $args['headers']['X-GitHub-Api-Version'] = '2022-11-28';
            
            // Add user agent if not set
            if (!isset($args['headers']['User-Agent'])) {
                $args['headers']['User-Agent'] = 'WordPress/' . get_bloginfo('version');
            }
        }
        
        return $args;
    }
    
    /**
     * Make an API request to GitHub
     * 
     * Works with both public repos (no token needed) and private repos (token required).
     * For public repositories, GitHub's API allows unauthenticated requests with lower rate limits.
     * 
     * @param string $url API URL
     * @return object|bool Response object or false on failure
     */
    private function api_request($url) {
        // Build headers - token is optional for public repos
        $headers = array(
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
            'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url()
        );
        
        // Only add Authorization header if token is available (for private repos or higher rate limits)
        if (!empty($this->authorize_token)) {
            $headers['Authorization'] = 'Bearer ' . $this->authorize_token;
        }
        
        $response = wp_remote_get($url, array(
            'headers' => $headers,
            'timeout' => 20,
            'sslverify' => true, // Ensure SSL verification is enabled for security
            'redirection' => 5 // Follow up to 5 redirects (important for repository renames)
        ));
        
        // Check for errors
        if (is_wp_error($response)) {
            return false;
        }
        
        // Check response code
        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($response_code !== 200) {
            return false;
        }
        
        // Parse JSON response
        $data = json_decode($body);
        
        if (empty($data)) {
            return false;
        }
        
        return $data;
    }
    
    /**
     * Force plugin icons with CSS if they're not displaying properly
     */
    public function force_plugin_icons_css() {
        $plugin_url = plugin_dir_url($this->file);
        $plugin_slug = dirname($this->plugin);
        
        echo '<style type="text/css">
        /* Force CCM Tools plugin icon display */
        tr[data-slug="' . esc_attr($plugin_slug) . '"] .dashicons-admin-plugins {
            display: none !important;
        }
        tr[data-slug="' . esc_attr($plugin_slug) . '"] .dashicons-admin-plugins::before {
            content: "" !important;
            background-image: url("' . esc_url($plugin_url . 'assets/icon.svg') . '") !important;
            background-size: contain !important;
            background-repeat: no-repeat !important;
            width: 20px !important;
            height: 20px !important;
            display: inline-block !important;
        }
        </style>';
        
        echo '<script type="text/javascript">
        document.addEventListener("DOMContentLoaded", function() {
            // Find CCM Tools plugin row and force icon display
            document.querySelectorAll("tr").forEach(function(row) {
                if (row.textContent.indexOf("CCM Tools") > -1) {
                    var iconCell = row.querySelector("td.plugin-title");
                    if (iconCell) {
                        // Remove default dashicon
                        var dashicon = iconCell.querySelector(".dashicons-admin-plugins");
                        if (dashicon) dashicon.remove();
                        
                        // Add our custom icon
                        var customIcon = document.createElement("img");
                        customIcon.src = "' . esc_url($plugin_url . 'assets/icon.svg') . '";
                        customIcon.alt = "CCM Tools";
                        customIcon.style.cssText = "width: 20px; height: 20px; margin-right: 5px; vertical-align: middle;";
                        var strong = iconCell.querySelector("strong");
                        if (strong) strong.parentNode.insertBefore(customIcon, strong);
                    }
                }
            });
        });
        </script>';
    }
    
    /**
     * Inject plugin icons directly into update transient for update-core.php display
     * This is the critical method that ensures icons show up on the update page
     */
    public function inject_plugin_icons($transient) {
        if (!$transient || !is_object($transient)) {
            return $transient;
        }
        
        $plugin_url = plugin_dir_url($this->file);
        $icons = array(
            'svg' => $plugin_url . 'assets/icon.svg',
            '2x' => $plugin_url . 'assets/icon.png',
            '1x' => $plugin_url . 'assets/icon.png',
            'default' => $plugin_url . 'assets/icon.svg'
        );
        
        // Add icons to response section if plugin exists there
        if (isset($transient->response[$this->plugin])) {
            $transient->response[$this->plugin]->icons = $icons;
        }
        
        // Add icons to no_update section if plugin exists there
        if (isset($transient->no_update[$this->plugin])) {
            $transient->no_update[$this->plugin]->icons = $icons;
        }
        
        return $transient;
    }
    
    /**
     * Inject icons into all_plugins filter - another approach for icon display
     */
    public function inject_plugin_icons_to_all_plugins($plugins) {
        if (isset($plugins[$this->plugin])) {
            $plugin_url = plugin_dir_url($this->file);
            $plugins[$this->plugin]['icons'] = array(
                'svg' => $plugin_url . 'assets/icon.svg',
                '2x' => $plugin_url . 'assets/icon.png',
                '1x' => $plugin_url . 'assets/icon.png',
                'default' => $plugin_url . 'assets/icon.svg'
            );
        }
        
        return $plugins;
    }
    
    /**
     * Check and remove stale maintenance file
     */
    public function check_maintenance_file() {
        $maintenance_file = ABSPATH . '.maintenance';
        
        if (file_exists($maintenance_file)) {
            $file_age = time() - filemtime($maintenance_file);
            
            // Remove file if it's older than 5 minutes
            if ($file_age > 300) {
                @unlink($maintenance_file);
            }
        }
    }
    
    /**
     * Force update check when visiting plugins page for immediate availability
     */
    public function force_update_check_on_plugins_page() {
        if (!current_user_can('update_plugins')) {
            return;
        }

        // Respect the native "Check for updates" button (?force-check=1)
        $force_requested = isset($_GET['force-check']) && '1' === sanitize_text_field(wp_unslash($_GET['force-check']));
        if (!$force_requested) {
            return;
        }

        // Only force refresh if we haven't checked recently (within last 30 minutes)
        $last_force_check = get_transient('ccm_last_force_check');
        
        if ($last_force_check) {
            return;
        }

        // Clear the GitHub cache to force fresh data
        $transient_key = 'ccm_github_' . md5($this->basename);
        delete_transient($transient_key);
        
        // Clear the plugin updates transient to force WordPress to re-check
        delete_site_transient('update_plugins');
        
        // Set a transient to prevent excessive API calls
        set_transient('ccm_last_force_check', true, 30 * MINUTE_IN_SECONDS);
    }
}

// Initialize the updater
function ccm_initialize_updater() {
    static $ccm_updater_bootstrapped = false;

    if ($ccm_updater_bootstrapped) {
        return;
    }

    if (!class_exists('CCM_GitHub_Updater')) {
        return;
    }

    $doing_cron = function_exists('wp_doing_cron') && wp_doing_cron();
    $doing_ajax = function_exists('wp_doing_ajax') && wp_doing_ajax();

    // Only load updater in admin (for capable users) or during Cron for background checks
    if (!$doing_cron && !is_admin()) {
        return;
    }

    if (!$doing_cron && is_admin()) {
        if (!current_user_can('update_plugins')) {
            return;
        }
    }

    // Prevent instantiating during unrelated AJAX calls without update permissions
    if ($doing_ajax && !current_user_can('update_plugins')) {
        return;
    }

    // Get main plugin file path - make sure this is correct
    $plugin_file = CCM_HELPER_ROOT_DIR . 'ccm.php';
    
    if (!file_exists($plugin_file)) {
        return;
    }
    
    $ccm_updater_bootstrapped = true;
    new CCM_GitHub_Updater($plugin_file);
}

// Initialize early to ensure updates are available immediately
add_action('plugins_loaded', 'ccm_initialize_updater');

