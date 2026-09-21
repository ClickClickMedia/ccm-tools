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
        // This constructor can be reached during a plain wp-cron.php request
        // (see ccm_initialize_updater()'s cron bypass below), and core does
        // NOT load wp-admin/includes/plugin.php during the plugins_loaded
        // phase of a pseudo-cron request. Without this, is_plugin_active()
        // below fatals with "Call to undefined function" and aborts the
        // entire cron pass — silently killing every other scheduled job in
        // it, not just this plugin's update check. Same pattern ccm.php
        // already uses for the same reason.
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

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
        add_filter('upgrader_source_selection', array($this, 'fix_source_dir'), 10, 4);
        add_filter('upgrader_pre_download', array($this, 'verify_package_checksum'), 10, 3);
        add_filter('http_request_args', array($this, 'add_auth_to_request'), 10, 2);

        // Backstop for the update transient: populates our plugin if a fresh
        // pre_set_site_transient_update_plugins pass hasn't run, and makes
        // sure icons are present either way. One callback on this hook
        // (previously two: check_for_update at priority 10 plus a separate
        // inject_plugin_icons at priority 99 doing overlapping work) —
        // registered at 99 so it has the last word, same as before.
        add_filter('site_transient_update_plugins', array($this, 'check_for_update'), 99, 1);

        // Icons for the plugin list / update screens. One callback per hook
        // (previously all_plugins was filtered twice, at priorities 10 and
        // 99, both building the same icons array).
        add_filter('all_plugins', array($this, 'add_plugin_icons_to_all_plugins'), 10, 1);
        add_filter('get_plugin_data', array($this, 'add_plugin_icons_to_update_data'), 10, 2);

        add_action('admin_head-update-core.php', array($this, 'force_plugin_icons_css'));
        
        // Force update check on plugins page and update-core page
        add_action('load-plugins.php', array($this, 'force_update_check_on_plugins_page'));
        add_action('load-update-core.php', array($this, 'force_update_check_on_plugins_page'));
        
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
        // If checked is empty, we still need to populate it for our plugin
        if (empty($transient)) {
            $transient = new stdClass();
        }

        if (!isset($transient->checked)) {
            $transient->checked = array();
        }

        // Ensure our plugin is in the checked list
        if (empty($transient->checked[$this->plugin])) {
            $plugin_data = get_plugin_data($this->file);
            $transient->checked[$this->plugin] = $plugin_data['Version'];
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
            // Force plugin into the response section for immediate update
            $transient->response[$this->plugin] = $this->build_update_object($github_version);
        } else {
            // No update needed, but provide info for the 'View details' screen
            $transient->no_update[$this->plugin] = $this->build_update_object($github_version);
        }

        return $transient;
    }

    /**
     * Check for updates when retrieving the transient (backup method), and
     * make sure icons are present regardless of how the entry got there.
     *
     * This is the sole callback on the site_transient_update_plugins filter
     * (previously two: a version-compare backstop plus a separate icon
     * injector doing overlapping work). It still does both jobs, just in one
     * place:
     *  1. Ensures updates are detected even if pre_set_site_transient_update_plugins
     *     didn't run for this transient.
     *  2. Force-applies icons to whatever ended up in response/no_update, in
     *     case it was populated elsewhere without them.
     *
     * @param object $transient Update transient
     * @return object Modified transient
     */
    public function check_for_update($transient) {
        if (!is_object($transient)) {
            $transient = new stdClass();
        }

        // Don't recompute if we've already added our plugin to the response
        if (!isset($transient->response[$this->plugin])) {
            // Get release information from GitHub
            $this->get_repository_info();

            if (!empty($this->github_response) && is_object($this->github_response)) {
                $github_version   = $this->get_github_version();
                $plugin_data      = get_plugin_data($this->file);
                $current_version  = $plugin_data['Version'];

                // Compare versions
                if (version_compare($github_version, $current_version, '>')) {
                    if (!isset($transient->response)) {
                        $transient->response = array();
                    }

                    $transient->response[$this->plugin] = $this->build_update_object($github_version);
                }
            }
        }

        // Belt-and-braces: make sure icons are present on whatever ended up
        // in response/no_update, even if it was populated elsewhere (or
        // came back from a cache) without them.
        $icons = $this->get_icons();
        if (isset($transient->response[$this->plugin])) {
            $transient->response[$this->plugin]->icons = $icons;
        }
        if (isset($transient->no_update[$this->plugin])) {
            $transient->no_update[$this->plugin]->icons = $icons;
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
     * The icon set advertised to WP admin screens (plugin list,
     * update-core.php, the plugin info popup). Built once here instead of
     * being duplicated inline at every touchpoint.
     *
     * @return array
     */
    private function get_icons() {
        $plugin_url = plugin_dir_url($this->file);
        return array(
            'svg'     => $plugin_url . 'assets/icon.svg',
            '1x'      => $plugin_url . 'assets/icon.png',
            '2x'      => $plugin_url . 'assets/icon.png',
            'default' => $plugin_url . 'assets/icon.svg',
        );
    }

    /**
     * Build the stdClass WordPress expects in the update transient's
     * response/no_update maps for this plugin.
     *
     * @param string $github_version
     * @return stdClass
     */
    private function build_update_object($github_version) {
        $obj              = new stdClass();
        $obj->slug        = $this->basename;
        $obj->plugin      = $this->plugin;
        $obj->new_version = $github_version;
        $obj->url         = $this->github_response->html_url;
        $obj->package     = $this->get_download_url();
        $obj->tested      = $this->get_current_wp_version();
        $obj->icons       = $this->get_icons();
        return $obj;
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
        
        // Cache response with shorter duration to catch updates faster
        $this->github_response = $response;
        set_transient($transient_key, $response, HOUR_IN_SECONDS); // 1 hour cache
        
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
                // Match the .zip suffix specifically (not just "contains .zip
                // anywhere"), so the "ccm-tools.zip.sha256" checksum asset
                // published alongside it (see get_checksum_url() below) is
                // never mistaken for the actual download package.
                if (isset($asset->browser_download_url, $asset->name) && substr($asset->name, -4) === '.zip') {
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
     * Find the "<zip-name>.sha256" checksum asset published alongside the
     * release zip, if any.
     *
     * @return string Asset download URL, or '' if no checksum asset was published.
     */
    private function get_checksum_url() {
        if (empty($this->github_response) || empty($this->github_response->assets) || !is_array($this->github_response->assets)) {
            return '';
        }

        foreach ($this->github_response->assets as $asset) {
            if (isset($asset->browser_download_url, $asset->name) && substr($asset->name, -7) === '.sha256') {
                return $asset->browser_download_url;
            }
        }

        return '';
    }

    /**
     * Fetch and parse the expected SHA-256 digest from a checksum asset URL.
     *
     * Accepts either a bare hex digest, or the common `sha256sum` output
     * format ("<hex>  <filename>").
     *
     * @param string $checksum_url
     * @return string Lowercase 64-char hex digest, or '' if it couldn't be read/parsed.
     */
    private function fetch_expected_checksum($checksum_url) {
        $response = wp_remote_get($checksum_url, array(
            'timeout'   => 15,
            'sslverify' => true,
        ));

        if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
            return '';
        }

        $body  = trim(wp_remote_retrieve_body($response));
        $parts = preg_split('/\s+/', $body);
        $hash  = isset($parts[0]) ? strtolower($parts[0]) : '';

        return preg_match('/^[a-f0-9]{64}$/', $hash) ? $hash : '';
    }

    /**
     * Verify the downloaded release package's integrity against a published
     * "ccm-tools.zip.sha256" checksum asset, before WordPress trusts it.
     *
     * Hooked to `upgrader_pre_download` rather than `upgrader_source_selection`
     * or `upgrader_post_install`: by the time either of those filters fires,
     * WP_Upgrader::unpack_package() has already extracted AND DELETED the
     * downloaded zip (see wp-admin/includes/class-wp-upgrader.php), so the
     * raw package bytes are no longer available to hash at that point.
     * Intercepting the download itself is the only reliable place to check
     * the actual downloaded file before it's ever extracted or installed.
     *
     * RELEASE PROCESS NOTE: publish a `ccm-tools.zip.sha256` asset alongside
     * the plugin zip on every GitHub release, containing just the lowercase
     * hex SHA-256 digest of that zip (a `sha256sum ccm-tools.zip` style line
     * is also accepted). If the asset is missing, this gate logs a warning
     * and lets the existing unverified download proceed, so this rolls out
     * without breaking updates from releases published before it existed.
     *
     * @param bool|WP_Error|string $reply    Short-circuit value; false means "let WP download normally".
     * @param string               $package  URL of the package being downloaded.
     * @param WP_Upgrader          $upgrader Unused; part of the filter signature.
     * @return bool|WP_Error|string
     */
    public function verify_package_checksum($reply, $package, $upgrader) {
        // Something upstream already made a decision, or this isn't a
        // package URL we recognise as belonging to us — don't interfere.
        if (false !== $reply || empty($package)) {
            return $reply;
        }

        $this->get_repository_info();

        if (empty($package) || $package !== $this->get_download_url()) {
            return $reply;
        }

        $checksum_url = $this->get_checksum_url();
        if (empty($checksum_url)) {
            error_log('CCM Tools: this release did not publish a ccm-tools.zip.sha256 asset; skipping the download integrity check.');
            return $reply;
        }

        $expected = $this->fetch_expected_checksum($checksum_url);
        if (empty($expected)) {
            error_log('CCM Tools: could not read/parse the published checksum asset; skipping the download integrity check.');
            return $reply;
        }

        $tmp_file = download_url($package);
        if (is_wp_error($tmp_file)) {
            return $tmp_file;
        }

        $actual = hash_file('sha256', $tmp_file);

        if (!is_string($actual) || !hash_equals($expected, strtolower($actual))) {
            @unlink($tmp_file);
            return new WP_Error(
                'ccm_checksum_mismatch',
                __('CCM Tools update package failed its integrity check (SHA-256 mismatch against the published checksum). The update has been blocked to protect this site. Please try again later, or download the release manually.', 'ccm-tools')
            );
        }

        // Hand WordPress the file we already downloaded and verified so it
        // doesn't fetch the package a second time.
        return $tmp_file;
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
        $plugin_info->icons = $this->get_icons();

        return $plugin_info;
    }

    /**
     * Add plugin icons to plugin data for update-core.php / the plugins list.
     *
     * Sole callback on the all_plugins filter (previously two: this one at
     * priority 10 only setting icons if absent, plus a near-identical
     * unconditional one at priority 99 that always won anyway).
     *
     * @param array $plugins List of all plugins
     * @return array Modified plugins array
     */
    public function add_plugin_icons_to_all_plugins($plugins) {
        if (isset($plugins[$this->plugin])) {
            $plugins[$this->plugin]['icons'] = $this->get_icons();
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
            $plugin_data['icons'] = $this->get_icons();
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
        
        // Simple markdown to HTML conversion — escape raw HTML first
        $changelog = esc_html($this->github_response->body);
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
    /**
     * Rename extracted source directory to match the installed plugin folder.
     *
     * GitHub release zips extract to folders like 'ccm-tools/', 'ccm-tools-7.20.2/',
     * or 'ClickClickMedia-ccm-tools-abc1234/'. This filter renames the extracted
     * folder to match $this->basename (the actual installed plugin directory name,
     * e.g. 'ccm-tools' or 'ccm-tools-main') so WordPress installs to the correct path.
     *
     * @param string $source        File source location (temp directory).
     * @param string $remote_source Remote file source location.
     * @param WP_Upgrader $upgrader WP_Upgrader instance.
     * @param array  $hook_extra    Extra arguments passed to hooked filters.
     * @return string|WP_Error Corrected source path or WP_Error on failure.
     */
    public function fix_source_dir($source, $remote_source, $upgrader, $hook_extra) {
        global $wp_filesystem;

        // SECURITY: only act on our own plugin's update, identified
        // authoritatively via hook_extra['plugin'] — never by guessing from
        // the extracted folder's name. A folder-name fallback (matching
        // anything starting with "ccm-tools" or "ClickClickMedia-ccm-tools-")
        // used to also fire for any manually uploaded zip whose top-level
        // folder merely happened to start with that string, silently
        // renaming it to this plugin's slug before WordPress's own
        // destination checks ever ran.
        if (!isset($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->plugin) {
            return $source;
        }

        $source_dirname = basename(untrailingslashit($source));

        // Already correct — folder matches the installed plugin directory
        if ($source_dirname === $this->basename) {
            return $source;
        }

        // Flat zip: source IS the working directory (no parent folder in zip).
        // This should not happen with properly built zips, but handle gracefully.
        if (untrailingslashit($source) === untrailingslashit($remote_source)) {
            return $source;
        }

        // Rename the folder to match the installed plugin directory
        // e.g. ccm-tools-7.32.5 → ccm-tools (or ccm-tools-main, etc.)
        $correct_dir = trailingslashit($remote_source) . trailingslashit($this->basename);
        if ($wp_filesystem->move($source, $correct_dir)) {
            return $correct_dir;
        }

        return new WP_Error('rename_failed', 'Could not rename plugin directory to ' . $this->basename . '.');
    }

    public function after_install($response, $hook_extra, $result) {
        // Check if we're updating this plugin
        if (!isset($hook_extra['plugin']) || $hook_extra['plugin'] != $this->plugin) {
            return $response;
        }
        
        // Re-activate plugin if it was active before the update
        if ($this->active) {
            activate_plugin($this->plugin);
        }
        
        // Clean up maintenance file
        $this->check_maintenance_file();
        
        return $response;
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
        $host = wp_parse_url($url, PHP_URL_HOST);
        if ($host !== 'github.com' && $host !== 'api.github.com') {
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

        // Force check requested via URL parameter
        $force_requested = isset($_GET['force-check']) && '1' === sanitize_text_field(wp_unslash($_GET['force-check']));
        
        if (!$force_requested) {
            return;
        }

        // Clear ALL caches to force fresh data
        $transient_key = 'ccm_github_' . md5($this->basename);
        delete_transient($transient_key);
        delete_transient('ccm_last_force_check');
        
        // Clear our internal cache
        $this->github_response = null;
        
        // Clear the plugin updates transient to force WordPress to re-check
        delete_site_transient('update_plugins');
        
        // Force WordPress to rebuild the update transient now
        wp_clean_plugins_cache(true);
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

// Initialize on multiple hooks to ensure we catch updates
add_action('plugins_loaded', 'ccm_initialize_updater');
add_action('admin_init', 'ccm_initialize_updater');

// Also force check on admin_init when force-check param is present.
//
// SECURITY: this fires on admin_init, i.e. on EVERY wp-admin screen — not
// just plugins.php/update-core.php — so without a capability + nonce check
// any logged-in user who can load any wp-admin page at all (a Subscriber, a
// WooCommerce customer on their account screen, etc.) could hit
// wp-admin/profile.php?force-check=1 in a loop, bypass the hourly GitHub
// API cache every time, and burn the shared 60-requests/hour unauthenticated
// rate limit for every CCM site sharing that egress IP. Gated the same way
// as the properly-scoped twin, force_update_check_on_plugins_page() above.
add_action('admin_init', function () {
    if (!isset($_GET['force-check']) || '1' !== $_GET['force-check']) {
        return;
    }

    if (!current_user_can('update_plugins')) {
        return;
    }

    $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
    if (!wp_verify_nonce($nonce, 'ccm_tools_force_check')) {
        return;
    }

    // Delete transients immediately on admin_init before anything else runs
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%ccm_github_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%_site_transient_update_plugins%'");
    delete_site_transient('update_plugins');
    wp_clean_plugins_cache(true);
}, 1);

