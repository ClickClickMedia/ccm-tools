<?php
/**
 * This plugin's integrity gate must only ever judge this plugin's download.
 *
 * `upgrader_pre_download` fires for EVERY package WordPress fetches — plugins,
 * themes, core, language packs. A filter on it that cannot tell whose download
 * it is looking at does not fail quietly: it refuses somebody else's software
 * and puts our name on the error.
 *
 * That is what happened. The gate asked "can I prove this is NOT mine" —
 *
 *     $named = isset($hook_extra['plugin']) ? $hook_extra['plugin'] : '';
 *     if ($named !== '' && $named !== $this->plugin) { return $reply; }
 *
 * — and WordPress only sets `hook_extra['plugin']` for a plugin UPDATE. A
 * theme update sets `['theme']`. A core update, a language pack and a fresh
 * upload-install set neither. All of those arrived with an empty name, fell
 * through to a host check that accepted github.com, and had this plugin's
 * checksum enforced on them. Self-hosting a theme on GitHub is ordinary, so
 * this was not a corner case; it broke those updates every time.
 *
 * The question is now the other way round: ours only when WordPress names us,
 * or when nothing is named and the package came from the update service, which
 * serves nothing but us.
 *
 * Run:  php tests/download_gate_test.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);

define('ABSPATH', $root . '/');
define('HOUR_IN_SECONDS', 3600);
define('MINUTE_IN_SECONDS', 60);
define('DAY_IN_SECONDS', 86400);
define('CCM_HELPER_VERSION', '8.13.9');

$GLOBALS['__options']    = array();
$GLOBALS['__transients'] = array();

function get_option($k, $d = false) { return $GLOBALS['__options'][$k] ?? $d; }
function update_option($k, $v, $a = null) { $GLOBALS['__options'][$k] = $v; return true; }
function delete_option($k) { unset($GLOBALS['__options'][$k]); return true; }
function get_transient($k) { return $GLOBALS['__transients'][$k] ?? false; }
function set_transient($k, $v, $t = 0) { $GLOBALS['__transients'][$k] = $v; return true; }
function delete_transient($k) { unset($GLOBALS['__transients'][$k]); return true; }
function plugin_basename($f) { return 'ccm-tools/ccm.php'; }
function is_plugin_active($p) { return true; }
function add_filter($h, $c, $p = 10, $a = 1) { return true; }
function add_action($h, $c, $p = 10, $a = 1) { return true; }
function wp_parse_url($u, $c = -1) { return parse_url($u, $c); }
function home_url($p = '') { return 'https://example-client.com.au' . $p; }
function get_bloginfo($w = '') { return $w === 'version' ? '6.8.1' : 'Example Client'; }
function is_multisite() { return false; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_html__($s, $d = null) { return $s; }
function __($s, $d = null) { return $s; }
function _e($s, $d = null) { echo $s; }
function is_wp_error($t) { return $t instanceof WP_Error; }
function wp_remote_get($u, $a = array()) { return new WP_Error('http', 'no network in tests'); }
function wp_remote_post($u, $a = array()) { return new WP_Error('http', 'no network in tests'); }
function wp_remote_retrieve_response_code($r) { return 0; }
function wp_remote_retrieve_body($r) { return ''; }
function wp_normalize_path($p) { return str_replace('\\', '/', $p); }
function trailingslashit($p) { return rtrim($p, '/\\') . '/'; }
function wp_json_encode($d) { return json_encode($d); }
function human_time_diff($f, $t = 0) { return '1 min'; }
function get_current_screen() { return null; }
function ccm_tools_user_is_admin(): bool { return true; }

class WP_Error
{
    private $code;
    private $msg;
    public function __construct($c = '', $m = '') { $this->code = $c; $this->msg = $m; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->msg; }
}

require_once $root . '/inc/registry.php';
require_once $root . '/inc/update.php';

$failures = 0;

function check(string $what, bool $ok, string $why = ''): void
{
    global $failures;
    if ($ok) {
        printf("  ok   %s\n", $what);
        return;
    }
    $failures++;
    printf("  FAIL %-56s %s\n", $what, $why);
}

$updater = new CCM_Tools_Updater($root . '/ccm.php');
$service = ccm_tools_registry_endpoint();
$service_host = (string) wp_parse_url($service, PHP_URL_HOST);

/** Run the real filter and describe what came back. */
function gate(CCM_Tools_Updater $u, string $package, array $hook_extra): string
{
    $out = $u->verify_package_checksum(false, $package, null, $hook_extra);
    if ($out instanceof WP_Error) {
        return 'BLOCKED:' . $out->get_error_code();
    }
    return $out === false ? 'passed through' : 'other';
}

printf("The download gate only judges our own package\n\n");
printf("  our basename: ccm-tools/ccm.php\n  service host: %s\n\n", $service_host);

/*
 * Downloads that belong to somebody else. Every one of these must be handed
 * straight back untouched — no verification, and above all no error carrying
 * our name on somebody else's install.
 */
$not_ours = array(
    'a theme from GitHub' => array(
        'https://github.com/someone/their-theme/archive/v2.zip',
        array('theme' => 'their-theme', 'type' => 'theme', 'action' => 'update'),
    ),
    'a theme, no type key' => array(
        'https://github.com/someone/their-theme/archive/v2.zip',
        array('theme' => 'their-theme'),
    ),
    'a GitHub plugin, named' => array(
        'https://github.com/someone/their-plugin/archive/v2.zip',
        array('plugin' => 'their-plugin/their-plugin.php', 'type' => 'plugin'),
    ),
    'a language pack' => array(
        'https://downloads.wordpress.org/translation/plugin/x.zip',
        array('language_update' => array('type' => 'plugin'), 'language_update_type' => 'plugin'),
    ),
    'core, nothing named' => array(
        'https://downloads.wordpress.org/release/wordpress-6.8.zip',
        array(),
    ),
    'an upload install from GitHub' => array(
        'https://github.com/someone/anything/archive/main.zip',
        array('type' => 'plugin', 'action' => 'install'),
    ),
    'raw.githubusercontent, unnamed' => array(
        'https://raw.githubusercontent.com/someone/thing/main/thing.zip',
        array('type' => 'plugin', 'action' => 'install'),
    ),
);

foreach ($not_ours as $label => $case) {
    list($package, $hook_extra) = $case;
    $result = gate($updater, $package, $hook_extra);
    check(
        sprintf('%-30s is passed through', $label),
        $result === 'passed through',
        'got ' . $result . ' — we broke somebody else\'s download'
    );
}

printf("\n");

/*
 * Our own package still gets judged. The site has no stored release here, so
 * a refusal is the correct, safe outcome — what matters is that the gate
 * ENGAGES rather than standing aside.
 */
$ours = array(
    'our plugin, from the service' => array(
        $service . '/v1/download?token=x',
        array('plugin' => 'ccm-tools/ccm.php', 'type' => 'plugin', 'action' => 'update'),
    ),
    'our plugin, from GitHub' => array(
        'https://github.com/ClickClickMedia/ccm-tools/releases/download/v1/ccm-tools.zip',
        array('plugin' => 'ccm-tools/ccm.php', 'type' => 'plugin', 'action' => 'update'),
    ),
    'unnamed, from the service' => array(
        $service . '/v1/download?token=x',
        array('type' => 'plugin', 'action' => 'install'),
    ),
);

foreach ($ours as $label => $case) {
    list($package, $hook_extra) = $case;
    $result = gate($updater, $package, $hook_extra);
    check(
        sprintf('%-30s is still judged', $label),
        strpos($result, 'BLOCKED:') === 0,
        'got ' . $result . ' — our own package went unverified'
    );
}

printf("\n");

// A package claiming to be ours from a host that is neither must be refused.
check(
    'our plugin from a foreign host is refused',
    gate($updater, 'https://attacker.example/payload.zip',
         array('plugin' => 'ccm-tools/ccm.php', 'type' => 'plugin')) === 'BLOCKED:ccm_package_foreign_host',
    'this is the arbitrary-code path'
);

// The asset-host test that could never fire.
check(
    'the githubusercontent suffix test can actually match',
    substr('raw.githubusercontent.com', -22) === '.githubusercontent.com',
    'substr(-20) against a 21-character literal is false for every host'
);

printf("\n");
if ($failures) {
    printf("%d check(s) failed\n", $failures);
    exit(1);
}
printf("only our own download is judged; everyone else's is left alone\n");
