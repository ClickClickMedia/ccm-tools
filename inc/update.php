<?php
/* 8712fb79032b7589 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/* c0064e71344d6d3a */
class CCM_Tools_Updater {
    private $file;             // Plugin file path
    private $plugin;           // Plugin basename
    private $basename;         // Plugin directory name
    private $active;           // Whether the plugin is active
    private $authorize_token;  // always empty; see add_auth_to_request()
    private $username = 'ClickClickMedia';   // GitHub fallback only
    private $repository = 'ccm-tools';       // GitHub fallback only
    private $source = '';                    // 'service' | 'github' | ''
    private $release_response; // Cached release record from the update service
    
    /* cfc1b5b9f43b3125 */
    public function __construct($file) {
        /* 139c4c970933286f */
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        // Set class properties
        $this->file = $file;
        $this->plugin = plugin_basename($file);
        $this->basename = dirname($this->plugin);
        $this->active = is_plugin_active($this->plugin);
        
        // Releases come from the CCM update service, which authorises by
        // domain. Nothing secret is stored on the site.
        $this->authorize_token = '';
        
        // Add required hooks with higher priority to ensure they run early
        add_filter('pre_set_site_transient_update_plugins', array($this, 'modify_transient'), 5, 1);
        add_filter('plugins_api', array($this, 'plugin_popup'), 10, 3);
        add_filter('upgrader_post_install', array($this, 'after_install'), 10, 3);
        add_filter('upgrader_source_selection', array($this, 'fix_source_dir'), 10, 4);
        add_filter('upgrader_pre_download', array($this, 'verify_package_checksum'), 10, 4);
        add_filter('http_request_args', array($this, 'add_auth_to_request'), 10, 2);

        /* 3120d95da3c75040 */
        add_filter('site_transient_update_plugins', array($this, 'check_for_update'), 99, 1);

        /* 2e6a8a86f884cbfe */
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
    
    /* f38706dc798e0095 */
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

        // Load whatever release this site is being offered
        $this->get_repository_info();

        // Check if we have a valid response
        if (empty($this->release_response) || !is_object($this->release_response)) {
            return $transient;
        }

        // IMPORTANT: Ensure plugin version is being compared correctly
        // Version on offer
        $release_version = $this->get_release_version();

        // Get current plugin version - try multiple approaches to ensure we get it
        $plugin_data = get_plugin_data($this->file);
        $current_version = $plugin_data['Version'];

        // Compare versions and add update information if newer
        if (version_compare($release_version, $current_version, '>')) {
            // Force plugin into the response section for immediate update
            $transient->response[$this->plugin] = $this->build_update_object($release_version);
        } else {
            // No update needed, but provide info for the 'View details' screen
            $transient->no_update[$this->plugin] = $this->build_update_object($release_version);
        }

        return $transient;
    }

    /* 79c66dff17c18689 */
    public function check_for_update($transient) {
        if (!is_object($transient)) {
            $transient = new stdClass();
        }

        // Don't recompute if we've already added our plugin to the response
        if (!isset($transient->response[$this->plugin])) {
            // Load whatever release this site is being offered
            $this->get_repository_info();

            if (!empty($this->release_response) && is_object($this->release_response)) {
                $release_version   = $this->get_release_version();
                $plugin_data      = get_plugin_data($this->file);
                $current_version  = $plugin_data['Version'];

                // Compare versions
                if (version_compare($release_version, $current_version, '>')) {
                    if (!isset($transient->response)) {
                        $transient->response = array();
                    }

                    $transient->response[$this->plugin] = $this->build_update_object($release_version);
                }
            }
        }

        /* cd7101911a402268 */
        $icons = $this->get_icons();
        if (isset($transient->response[$this->plugin])) {
            $transient->response[$this->plugin]->icons = $icons;
        }
        if (isset($transient->no_update[$this->plugin])) {
            $transient->no_update[$this->plugin]->icons = $icons;
        }

        return $transient;
    }

    /* 21bd25eb4976f782 */
    private function get_current_wp_version() {
        global $wp_version;
        return $wp_version;
    }

    /* 125649b4bb96dda6 */
    private function get_icons() {
        $plugin_url = plugin_dir_url($this->file);
        return array(
            'svg'     => $plugin_url . 'assets/icon.svg',
            '1x'      => $plugin_url . 'assets/icon.png',
            '2x'      => $plugin_url . 'assets/icon.png',
            'default' => $plugin_url . 'assets/icon.svg',
        );
    }

    /* 71caa572917f0bbe */
    private function build_update_object($release_version) {
        $obj              = new stdClass();
        $obj->slug        = $this->basename;
        $obj->plugin      = $this->plugin;
        $obj->new_version = $release_version;
        $obj->url         = $this->release_response->html_url;
        $obj->package     = $this->get_download_url();
        $obj->tested      = $this->get_current_wp_version();
        $obj->icons       = $this->get_icons();
        return $obj;
    }

    /* ad348063073edc18 */
     /* edf840ae76d72339 */
    private function get_repository_info() {
        if (!empty($this->release_response)) {
            return true;
        }

        if (!function_exists('ccm_tools_registry_check')) {
            return $this->get_repository_info_github();
        }

        $state = ccm_tools_registry_check();

        if (is_array($state) && isset($state['entitled'])) {
            if (empty($state['entitled'])) {
                $this->source = 'service';
                return false;   // refused, and deliberately no fallback
            }

            $update = isset($state['update']) && is_array($state['update']) ? $state['update'] : null;
            if ($update && !empty($update['version']) && !empty($update['package'])) {
                $this->release_response = $this->build_release_record($update);
                $this->source = 'service';
                return true;
            }

            // Entitled, nothing newer. Believe it, unless the service is not
            // actually talking to us and this is just the last thing it said.
            if (!ccm_tools_registry_is_degraded()) {
                $this->source = 'service';
                return false;
            }
        }

        return $this->get_repository_info_github();
    }

    /* 7e696eeac2a5cb65 */
    private function get_repository_info_github() {
        if (!$this->github_fallback_enabled()) {
            return false;
        }

        $transient_key = 'ccm_github_' . md5($this->basename);
        $cached = get_transient($transient_key);

        if ($cached && is_object($cached)) {
            $this->release_response = $cached;
            $this->source = 'github';
            return true;
        }

        $response = $this->api_request(
            "https://api.github.com/repos/{$this->username}/{$this->repository}/releases/latest"
        );

        if (empty($response) || !is_object($response) || empty($response->tag_name)) {
            return false;
        }

        set_transient($transient_key, $response, HOUR_IN_SECONDS);
        $this->release_response = $response;
        $this->source = 'github';
        return true;
    }

    /* 77af7b1756be7ed2 */
    private function github_fallback_enabled() {
        if (defined('CCM_TOOLS_GITHUB_FALLBACK')) {
            return (bool) CCM_TOOLS_GITHUB_FALLBACK;
        }
        return true;
    }

    /* 46831dffd7d1a66f */
    public function last_source() {
        return $this->source;
    }

    /* bf37b6515493d2c0 */
    private function build_release_record(array $update) {
        $record = new stdClass();
        $record->tag_name     = (string) $update['version'];
        $record->html_url     = 'https://clickclickmedia.com.au/';
        $record->body         = isset($update['notes']) ? (string) $update['notes'] : '';
        $record->published_at = gmdate('c');
        $record->sha256       = isset($update['sha256']) ? strtolower((string) $update['sha256']) : '';
        $record->signature    = isset($update['signature']) ? (string) $update['signature'] : '';
        $record->is_security  = !empty($update['security']);
        $record->tested_wp    = isset($update['tested']) ? (string) $update['tested'] : '';

        $asset = new stdClass();
        $asset->name = 'ccm-tools.zip';
        $asset->browser_download_url = (string) $update['package'];
        $record->assets = array($asset);

        return $record;
    }

    /* 8afb66a359870214 */
    private function service_host() {
        $base = function_exists('ccm_tools_registry_endpoint')
            ? ccm_tools_registry_endpoint()
            : 'https://updates.clickclick.media';
        return strtolower((string) wp_parse_url($base, PHP_URL_HOST));
    }
    
    /* 649f668ae03e15f3 */
    private function get_release_version() {
        if (empty($this->release_response)) {
            return '0.0.0'; // Return a default version
        }
        
        // Remove 'v' prefix if present and ensure it's a clean version number
        $version = ltrim($this->release_response->tag_name, 'v');
        
        // Ensure it's a valid version format 
        if (strpos($version, '.') === false) {
            $version .= '.0'; // Convert single number to x.0 format
        }
        
        return $version;
    }
    
    /* 175eeacd138b0168 */
    private function get_download_url() {
        if (empty($this->release_response)) {
            return '';
        }

        // First check for assets (preferred way)
        if (!empty($this->release_response->assets) && is_array($this->release_response->assets)) {
            /* 3be0a7a74d9045c3 */
            foreach ($this->release_response->assets as $asset) {
                if (isset($asset->browser_download_url, $asset->name) && $asset->name === 'ccm-tools.zip') {
                    return $asset->browser_download_url;
                }
            }

            /* 99efecab10d77b26 */
            foreach ($this->release_response->assets as $asset) {
                if (isset($asset->browser_download_url, $asset->name) && substr($asset->name, -4) === '.zip') {
                    return $asset->browser_download_url;
                }
            }
        }

        return '';
    }

    /* fb9daa5b28cd567d */
     /* 37abb972ff8fc857 */
    private function get_expected_checksum() {
        if (empty($this->release_response)) {
            return '';
        }

        // Straight off the service's answer.
        if (!empty($this->release_response->sha256)) {
            $digest = strtolower(trim((string) $this->release_response->sha256));
            return preg_match('/^[a-f0-9]{64}$/', $digest) ? $digest : '';
        }

        // Or from the release asset, on the GitHub path.
        if (empty($this->release_response->assets) || !is_array($this->release_response->assets)) {
            return '';
        }

        foreach ($this->release_response->assets as $asset) {
            if (isset($asset->name, $asset->browser_download_url)
                && $asset->name === 'ccm-tools-sha256.txt') {
                return $this->fetch_remote_checksum($asset->browser_download_url);
            }
        }

        return '';
    }

    /* 14513be4ab513249 */
    private function fetch_remote_checksum($url) {
        $response = wp_remote_get($url, array('timeout' => 15, 'sslverify' => true));

        if (is_wp_error($response) || 200 !== (int) wp_remote_retrieve_response_code($response)) {
            return '';
        }

        $body = trim((string) wp_remote_retrieve_body($response));
        if (preg_match('/\b([a-f0-9]{64})\b/i', $body, $m)) {
            return strtolower($m[1]);
        }

        return '';
    }

    /* 755b3838f840727b */
    private function verify_signature($file, $signature) {
        if ($signature === '' || !defined('CCM_TOOLS_RELEASE_PUBKEY') || CCM_TOOLS_RELEASE_PUBKEY === '') {
            return null;
        }
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            return null; // host has no libsodium; the digest gate still applies
        }

        $key = base64_decode(CCM_TOOLS_RELEASE_PUBKEY, true);
        $sig = base64_decode($signature, true);
        if ($key === false || $sig === false
            || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return null;
        }

        $bytes = @file_get_contents($file);
        if ($bytes === false) {
            return null;
        }

        try {
            return sodium_crypto_sign_verify_detached($sig, $bytes, $key) ? true : false;
        } catch (Exception $e) {
            return null;
        } catch (Error $e) {
            return null;
        }
    }

    /* 6fc5f33a7efb0e13 */
    public function verify_package_checksum($reply, $package, $upgrader = null, $hook_extra = array()) {
        // Something upstream already decided, or there is nothing to check.
        if (false !== $reply || empty($package)) {
            return $reply;
        }

        /* 219a6cc7283d0d7f */
        $named = isset($hook_extra['plugin']) ? (string) $hook_extra['plugin'] : '';
        if ($named !== '' && $named !== $this->plugin) {
            return $reply;   // definitely not ours
        }

        $host = strtolower((string) wp_parse_url($package, PHP_URL_HOST));
        $from_service = ($host !== '' && $host === $this->service_host());
        $from_github  = ($host === 'github.com' || substr($host, -20) === 'githubusercontent.com');

        if (!$from_service && !$from_github) {
            /* 63d223e4440a9bd4 */
            if ($named === $this->plugin) {
                return new WP_Error(
                    'ccm_package_foreign_host',
                    __('The CCM Tools update came from an unexpected address, so it has not been installed.', 'ccm-tools')
                );
            }
            return $reply;
        }

        /* 5076b9d2ec510fb7 */
        $this->release_response = null;
        if ($from_service && function_exists('ccm_tools_registry_check')) {
            ccm_tools_registry_check(true);
        }

        if (!$this->get_repository_info()) {
            return new WP_Error(
                'ccm_release_unavailable',
                __('CCM Tools could not confirm which release this site should install, so nothing has been installed. Please try again shortly.', 'ccm-tools')
            );
        }

        $expected = $this->get_expected_checksum();
        if ($expected === '') {
            return new WP_Error(
                'ccm_checksum_missing',
                __('This CCM Tools release did not publish a checksum, so the download could not be verified and has not been installed.', 'ccm-tools')
            );
        }

        /* a25f25194ce932df */
        $fresh = $from_service ? $this->get_download_url() : $package;
        if ($fresh === '') {
            return new WP_Error(
                'ccm_package_unavailable',
                __('CCM Tools could not obtain a download link for this release, so nothing has been installed.', 'ccm-tools')
            );
        }

        $tmp_file = download_url($fresh);
        if (is_wp_error($tmp_file)) {
            return $tmp_file;
        }

        $actual = hash_file('sha256', $tmp_file);
        if (!is_string($actual) || !hash_equals($expected, strtolower($actual))) {
            @unlink($tmp_file);
            return new WP_Error(
                'ccm_checksum_mismatch',
                __('CCM Tools update package failed its integrity check (SHA-256 mismatch against the published checksum). The update has been blocked to protect this site.', 'ccm-tools')
            );
        }

        /* bd750710f91182c6 */
        $signed = $this->verify_signature($tmp_file, (string) $this->release_response->signature);
        if ($signed === false) {
            @unlink($tmp_file);
            return new WP_Error(
                'ccm_signature_invalid',
                __('CCM Tools update package failed its signature check. The update has been blocked to protect this site.', 'ccm-tools')
            );
        }

        // Hand WordPress the file we already downloaded and verified so it
        // does not fetch the package a second time.
        return $tmp_file;
    }
    
    /* f9e9bd77f1b99135 */
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
        if (empty($this->release_response)) {
            return $result;
        }
        
        // Get plugin data
        $plugin_data = get_plugin_data($this->file);
        
        // Create response object
        $plugin_info = new stdClass();
        $plugin_info->name = $plugin_data['Name'];
        $plugin_info->slug = $this->basename;
        $plugin_info->version = $this->get_release_version();
        $plugin_info->author = $plugin_data['Author'];
        $plugin_info->author_profile = $plugin_data['AuthorURI'];
        $plugin_info->homepage = $plugin_data['PluginURI'] ?: $this->release_response->html_url;
        $plugin_info->requires = $plugin_data['RequiresWP'] ?: '5.0';
        $plugin_info->requires_php = $plugin_data['RequiresPHP'] ?: '7.0';
        $plugin_info->tested = $this->get_current_wp_version();  // Use current WordPress version
        
        // Format timestamps
        $plugin_info->last_updated = isset($this->release_response->published_at) 
                                   ? date('Y-m-d', strtotime($this->release_response->published_at)) 
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

    /* f707a6ffc5c80d29 */
    public function add_plugin_icons_to_all_plugins($plugins) {
        if (isset($plugins[$this->plugin])) {
            $plugins[$this->plugin]['icons'] = $this->get_icons();
        }

        return $plugins;
    }

    /* c9a742e7df2d08fc */
    public function add_plugin_icons_to_update_data($plugin_data, $plugin_file) {
        if ($plugin_file === $this->file) {
            $plugin_data['icons'] = $this->get_icons();
        }
        
        return $plugin_data;
    }
    
    /* 5d635d0d237d9bd8 */
    private function get_changelog() {
        if (empty($this->release_response) || empty($this->release_response->body)) {
            return 'No changelog provided';
        }
        
        // Simple markdown to HTML conversion — escape raw HTML first
        $changelog = esc_html($this->release_response->body);
        $changelog = preg_replace('/\r\n|\r/', "\n", $changelog);
        $changelog = preg_replace('/###(.*?)\n/', '<h3>$1</h3>', $changelog);
        $changelog = preg_replace('/##(.*?)\n/', '<h2>$1</h2>', $changelog);
        $changelog = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $changelog);
        $changelog = preg_replace('/\*(.*?)\*/', '<em>$1</em>', $changelog);
        $changelog = preg_replace('/- (.*?)(\n|$)/', '<li>$1</li>', $changelog);
        $changelog = preg_replace('/((?:<li>.*<\/li>\n?)+)/', '<ul>$1</ul>', $changelog);
        
        return $changelog;
    }
    
    /* a6c527ff7e9cb05e */
    /* 796c031b6de4caed */
    public function fix_source_dir($source, $remote_source, $upgrader, $hook_extra) {
        global $wp_filesystem;

        /* ae2052ae4068fc9b */
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

        /* c6dd7fd4c83ad062 */
        if (function_exists('ccm_tools_registry_invalidate')) {
            ccm_tools_registry_invalidate();
        }

        return $response;
    }
    
    /* 54129551b14ac4b8 */
    private function api_request($url) {
        $response = wp_remote_get($url, array(
            'timeout'   => 15,
            'sslverify' => true,
            'headers'   => array(
                'Accept'     => 'application/vnd.github+json',
                'User-Agent' => 'CCM-Tools/' . (defined('CCM_HELPER_VERSION') ? CCM_HELPER_VERSION : '0'),
            ),
        ));

        if (is_wp_error($response) || 200 !== (int) wp_remote_retrieve_response_code($response)) {
            return false;
        }

        $decoded = json_decode(wp_remote_retrieve_body($response));
        return is_object($decoded) ? $decoded : false;
    }

    /* d0f90da445a6133a */
    public function add_auth_to_request($args, $url) {
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        if ($host === '' || $host !== $this->service_host()) {
            return $args;
        }

        if (!isset($args['headers']) || !is_array($args['headers'])) {
            $args['headers'] = array();
        }
        if (!isset($args['headers']['User-Agent'])) {
            $args['headers']['User-Agent'] = 'CCM-Tools/'
                . (defined('CCM_HELPER_VERSION') ? CCM_HELPER_VERSION : '0')
                . '; ' . home_url();
        }

        return $args;
    }
    
     
    /* 6cfb29ae129590ce */
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
    
    /* 0afbc7e6c4ff2588 */
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
    
    /* 4364a63540aa8095 */
    public function force_update_check_on_plugins_page() {
        if (!current_user_can('update_plugins')) {
            return;
        }

        // Force check requested via URL parameter
        $force_requested = isset($_GET['force-check']) && '1' === sanitize_text_field(wp_unslash($_GET['force-check']));
        
        if (!$force_requested) {
            return;
        }

        // Clear ALL caches to force fresh data. The legacy ccm_github_*
        // transient is still deleted so an upgraded site sheds the row.
        delete_transient('ccm_github_' . md5($this->basename));
        delete_transient('ccm_last_force_check');

        // Clear our internal cache
        $this->release_response = null;

        /* be3f5b28d823ffbe */
        if (function_exists('ccm_tools_registry_check')) {
            ccm_tools_registry_check(true);
        }
        
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

    if (!class_exists('CCM_Tools_Updater')) {
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
    new CCM_Tools_Updater($plugin_file);
}

// Initialize on multiple hooks to ensure we catch updates
add_action('plugins_loaded', 'ccm_initialize_updater');
add_action('admin_init', 'ccm_initialize_updater');

/* 7051bb0725d3668e */
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

    // And ask the update service again, or the rebuilt transient is filled
    // from the answer we already had.
    if (function_exists('ccm_tools_registry_check')) {
        ccm_tools_registry_check(true);
    }
}, 1);

