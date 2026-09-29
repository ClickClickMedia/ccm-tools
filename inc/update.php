<?php
/**
 * Plugin updates, from the CCM update service
 * 
 * Based on the well-tested pattern used by many WordPress plugins
 * that update from the CCM update service at updates.clickclick.media.
 *
 * @package CCM Tools
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * CCM Tools Updater
 * 
 * A streamlined updater class that follows WordPress conventions
 * and handles releases served by the update service.
 */
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
        
        // Releases come from the CCM update service, which authorises by
        // domain. Nothing secret is stored on the site.
        $this->authorize_token = '';
        
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
     * @param string $release_version
     * @return stdClass
     */
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

    /**
     * Load the release this site is currently being offered, if any.
     *
     * The answer comes from the update service, which is also what decides
     * whether this site is entitled to one at all. Caching lives there (twelve
     * hours on a good answer), so there is deliberately no second cache here:
     * the previous arrangement had this method's one-hour transient and
     * WordPress's twelve-hour update transient disagreeing about the package
     * URL, which the integrity gate then had to make excuses for.
     *
     * @return bool True when a release is on offer.
     */
     /**
     * Load the release this site is being offered, from whichever source is
     * authoritative right now.
     *
     * Two sources exist on purpose, and only while the fleet is crossing over.
     * A site still on v7.44.1 has an updater that only knows GitHub, so it
     * reaches this version through GitHub and nothing else is possible. From
     * here on the update service is the authority — but until enough sites have
     * arrived and the GitHub releases stop, losing the service must not strand
     * anyone. So:
     *
     *   service answered, not entitled  -> no update, and NO fallback. A
     *                                      fallback here would let a blocked
     *                                      site help itself from GitHub, which
     *                                      makes blocking meaningless.
     *   service answered, has one       -> use it.
     *   service answered, up to date    -> no update. It is the authority.
     *   service never answered          -> GitHub, so nobody is stranded.
     *
     * Note the second rule holds even while the service is unreachable: a
     * refusal we were given stays given. Only never having had an answer, or
     * having lost it, opens the fallback.
     *
     * @return bool True when a release is on offer.
     */
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

    /**
     * The GitHub release path, kept only for the crossover.
     *
     * Delete this, `api_request()` and the `github_*` helpers once the register
     * shows the fleet has arrived; that is also the moment the repository can
     * go private, and the two have to happen together because making it private
     * is exactly what stops this working.
     *
     * @return bool
     */
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

    /**
     * Whether the GitHub fallback may still be used.
     *
     * Defined as a constant so a single site can be taken off it for testing,
     * and so switching the whole fleet off later is one release rather than a
     * hunt through this class.
     *
     * @return bool
     */
    private function github_fallback_enabled() {
        if (defined('CCM_TOOLS_GITHUB_FALLBACK')) {
            return (bool) CCM_TOOLS_GITHUB_FALLBACK;
        }
        return true;
    }

    /**
     * Which source answered last. 'service', 'github' or '' if neither did.
     *
     * @return string
     */
    public function last_source() {
        return $this->source;
    }

    /**
     * Shape one answer from the service into the record the rest of this class
     * reads.
     *
     * The shape is GitHub's release payload, because that is what it used to
     * be and the details modal, the changelog and the icons all still read it.
     * The single asset is the package URL issued for this site: short lived,
     * and bound to this domain and this version.
     *
     * @param array $update
     * @return stdClass
     */
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

    /**
     * Host the update service answers on. Anything downloaded from anywhere
     * else is not ours and this class leaves it alone.
     *
     * @return string
     */
    private function service_host() {
        $base = function_exists('ccm_tools_registry_endpoint')
            ? ccm_tools_registry_endpoint()
            : 'https://updates.clickclick.media';
        return strtolower((string) wp_parse_url($base, PHP_URL_HOST));
    }
    
    /**
     * Version number of the release on offer
     * 
     * @return string Version number
     */
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
    
    /**
     * Get the download URL for the release
     * 
     * @return string Download URL
     */
    private function get_download_url() {
        if (empty($this->release_response)) {
            return '';
        }

        // First check for assets (preferred way)
        if (!empty($this->release_response->assets) && is_array($this->release_response->assets)) {
            /*
             * Prefer the canonical name. A release carries two zips, and
             * get_checksum_url() pairs the checksum by filename, so picking
             * "whichever .zip GitHub happens to list first" would make the
             * pairing depend on upload order — and since the gate now fails
             * closed, a release uploaded in the other order would block every
             * site's update rather than quietly skip the check.
             */
            foreach ($this->release_response->assets as $asset) {
                if (isset($asset->browser_download_url, $asset->name) && $asset->name === 'ccm-tools.zip') {
                    return $asset->browser_download_url;
                }
            }

            // Match the .zip suffix specifically rather than "contains .zip
            // anywhere". The service issues one asset, but this stays honest
            // if that ever changes.
            foreach ($this->release_response->assets as $asset) {
                if (isset($asset->browser_download_url, $asset->name) && substr($asset->name, -4) === '.zip') {
                    return $asset->browser_download_url;
                }
            }
        }

        return '';
    }

    /**
     * The SHA-256 the service published for this release.
     *
     * It arrives with the release record rather than being fetched from a
     * second URL. One request fewer is one failure mode fewer, and the gate
     * below fails closed, so a checksum that could not be fetched used to mean
     * a refused update for the whole fleet.
     *
     * @return string Lowercase 64-char hex digest, or '' if there isn't one.
     */
     /**
     * The SHA-256 for the release on offer.
     *
     * From the service it arrives with the release, which is one request fewer
     * and therefore one failure fewer. From GitHub it comes from an asset named
     * `ccm-tools-sha256.txt`.
     *
     * That asset is deliberately not called `ccm-tools.zip.sha256`: the v7.44.1
     * updater still running on the fleet picks its download with
     * `strpos($asset->name, '.zip') !== false`, and that name contains the
     * substring, so a release carrying it could hand a site a 64-byte text file
     * instead of the plugin.
     *
     * @return string Lowercase 64-char hex digest, or '' if there isn't one.
     */
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

    /**
     * Read a digest out of a checksum asset.
     *
     * Accepts a bare hex digest or the usual `sha256sum` output shape
     * ("<hex>  <filename>").
     *
     * @param string $url
     * @return string
     */
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

    /**
     * Verify a detached Ed25519 signature over the downloaded package.
     *
     * Optional, and only as good as the key: a release without a signature is
     * still gated on its SHA-256. It exists because the digest and the zip
     * travel the same channel, so anyone who could serve a bad zip could serve
     * a matching digest with it. The signing key never touches the service.
     *
     * @param string $file      Path to the downloaded package.
     * @param string $signature Base64 detached signature.
     * @return bool|null true verified, false rejected, null could not check.
     */
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

    /**
     * Download our own package and verify it before WordPress installs it.
     *
     * This gate fails CLOSED. It used to return $reply whenever it could not
     * verify, so WordPress installed the package unverified with nothing in
     * the admin to say the check had been skipped: anyone who could make one
     * HTTPS GET fail turned integrity checking off for the entire fleet, and
     * cutting a release without the checksum asset did the same by accident.
     * Refusing an update is recoverable, because the site stays on the version
     * it is running and the administrator is told why. Installing an
     * unverified package is not.
     *
     * The package URL is re-issued here rather than taken from $package. The
     * URL WordPress holds comes out of a transient that lives for twelve hours
     * while the URLs this service issues are short lived and bound to one
     * domain, so by the time somebody clicks Update the cached one is usually
     * expired. Asking again costs one request and removes the whole class of
     * "the update link went stale" failures.
     *
     * @param bool|WP_Error|string $reply
     * @param string               $package
     * @param object               $upgrader
     * @return bool|WP_Error|string
     */
    public function verify_package_checksum($reply, $package, $upgrader) {
        // Something upstream already decided, or there is nothing to check.
        if (false !== $reply || empty($package)) {
            return $reply;
        }

        /*
         * Only ever interfere with our own package, from either source. While
         * the fleet is crossing over a package may legitimately come from
         * GitHub, and letting that one through unverified would leave the gate
         * open on exactly the path that has no service behind it.
         */
        $host = strtolower((string) wp_parse_url($package, PHP_URL_HOST));
        $from_service = ($host !== '' && $host === $this->service_host());
        $from_github  = ($host === 'github.com' || substr($host, -20) === 'githubusercontent.com');

        if (!$from_service && !$from_github) {
            return $reply;
        }

        // Ask again so the URL and the digest are both current and belong to
        // each other. Only meaningful on the service path; the GitHub asset
        // URLs are stable.
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

        /*
         * On the service path the URL is re-issued, because the one WordPress
         * cached for twelve hours has almost certainly expired. On the GitHub
         * path the URL WordPress already has is the right one.
         */
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

        // A signature, where one is published and this host can check it, is
        // the stronger statement: the digest travels the same channel as the
        // zip, a signature does not.
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
    
    /**
     * Override the plugin info popup with our own release details
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
     * Format the changelog from the release notes
     * 
     * @return string Formatted changelog
     */
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
     * One GitHub API request, for the fallback path only.
     *
     * Unauthenticated: the repository is public for exactly as long as this
     * crossover lasts, and a token sitting on a client site is the thing this
     * whole move is getting rid of. Goes with the rest of the fallback.
     *
     * @param string $url
     * @return object|false
     */
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

    /**
     * Identify ourselves to the update service.
     *
     * Deliberately carries no credential. The previous version supported a
     * GitHub personal access token via a CCM_GITHUB_TOKEN constant in
     * wp-config.php, which would have meant a token with access to the
     * organisation's repositories sitting readable on every client server.
     * The service authorises by domain instead, so a site holds nothing worth
     * stealing.
     *
     * @param array  $args
     * @param string $url
     * @return array
     */
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

        // Clear ALL caches to force fresh data. The legacy ccm_github_*
        // transient is still deleted so an upgraded site sheds the row.
        delete_transient('ccm_github_' . md5($this->basename));
        delete_transient('ccm_last_force_check');

        // Clear our internal cache
        $this->release_response = null;

        /*
         * Re-ask the update service. Without this a force check only cleared
         * WordPress's transient and then read our own twelve-hour answer
         * straight back, so "Check again" reported the same version it had a
         * moment ago and looked broken.
         */
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

// Also force check on admin_init when force-check param is present.
//
// SECURITY: this fires on admin_init, i.e. on EVERY wp-admin screen — not
// just plugins.php/update-core.php — so without a capability + nonce check
// any logged-in user who can load any wp-admin page at all (a Subscriber, a
// WooCommerce customer on their account screen, etc.) could hit
// wp-admin/profile.php?force-check=1 in a loop, bypass the hourly update
// cache every time. That used to burn the shared 60-requests/hour
// unauthenticated GitHub budget for every CCM site on that egress IP.
// Gated the same way
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

    // And ask the update service again, or the rebuilt transient is filled
    // from the answer we already had.
    if (function_exists('ccm_tools_registry_check')) {
        ccm_tools_registry_check(true);
    }
}, 1);

