<?php
/**
 * Only a real boolean may revoke a site's entitlement.
 *
 * Every site running this plugin asks the same endpoint. That makes the parse
 * of its answer the one piece of code here whose mistakes arrive everywhere at
 * once, and the rule the registry is built around is that entitlement is taken
 * away only by an explicit answer saying so. A timeout, a 500, HTML from a
 * captive portal and truncated JSON all leave a site exactly as it was.
 *
 * The parse did not honour that. It checked that the `entitled` key existed
 * and then cast it:
 *
 *     'entitled' => (bool) $parsed['entitled']
 *
 * which asks "is this falsy", not "did somebody decide". null, 0, "", "0" and
 * [] are all falsy, and all five are what a nullable column, an ORM default or
 * a half-finished deploy returns. One response of that shape would have told
 * the entire fleet it was no longer entitled, silently, with nobody having
 * made a decision.
 *
 * The existing registry suite could not express this: its ok_response() helper
 * takes a PHP bool, so the only values it could ever send were the two that
 * were always handled correctly.
 *
 * Run:  php tests/entitlement_shapes_test.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);

// ── A small WordPress ───────────────────────────────────────────
define('ABSPATH', $root . '/');
define('HOUR_IN_SECONDS', 3600);
define('MINUTE_IN_SECONDS', 60);
define('DAY_IN_SECONDS', 86400);

$GLOBALS['__options']    = array();
$GLOBALS['__transients'] = array();
$GLOBALS['__next_http']  = null;

function get_option($k, $d = false) { return $GLOBALS['__options'][$k] ?? $d; }
function update_option($k, $v, $a = null) { $GLOBALS['__options'][$k] = $v; return true; }
function delete_option($k) { unset($GLOBALS['__options'][$k]); return true; }
function get_transient($k) { return $GLOBALS['__transients'][$k] ?? false; }
function set_transient($k, $v, $t = 0) { $GLOBALS['__transients'][$k] = $v; return true; }
function delete_transient($k) { unset($GLOBALS['__transients'][$k]); return true; }
function home_url($p = '') { return 'https://example-client.com.au' . $p; }
function get_bloginfo($w = '') { return $w === 'version' ? '6.8.1' : 'Example Client'; }
function is_multisite() { return false; }
function wp_json_encode($d) { return json_encode($d); }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_html__($s, $d = null) { return $s; }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_attr_e($s, $d = null) { echo $s; }
function esc_url($s) { return $s; }
function __($s, $d = null) { return $s; }
function _e($s, $d = null) { echo $s; }
function add_action($h, $c, $p = 10, $a = 1) { return true; }
function add_filter($h, $c, $p = 10, $a = 1) { return true; }
function wp_parse_url($u, $c = -1) { return parse_url($u, $c); }
function human_time_diff($f, $t = 0) { return (string) max(1, (int) round(($t - $f) / 60)) . ' mins'; }
function get_current_screen() { return null; }
function ccm_tools_user_is_admin(): bool { return true; }
function check_ajax_referer($a = '', $q = false, $die = true) { return true; }
function wp_send_json_error($d = null, $s = null) { throw new RuntimeException('json_error'); }
function wp_send_json_success($d = null, $s = null) { throw new RuntimeException('json_success'); }
function wp_remote_post($url, $args = array()) { return $GLOBALS['__next_http']; }
function wp_remote_retrieve_response_code($r) { return is_array($r) ? ($r['response']['code'] ?? 0) : 0; }
function wp_remote_retrieve_body($r) { return is_array($r) ? ($r['body'] ?? '') : ''; }
function is_wp_error($t) { return $t instanceof WP_Error; }

class WP_Error
{
    private $msg;
    public function __construct($c = '', $m = '') { $this->msg = $m; }
    public function get_error_message() { return $this->msg; }
}

require_once $root . '/inc/registry.php';

$failures = 0;

function check(string $what, bool $ok, string $why = ''): void
{
    global $failures;
    if ($ok) {
        printf("  ok   %s\n", $what);
        return;
    }
    $failures++;
    printf("  FAIL %-58s %s\n", $what, $why);
}

/** A 200 whose JSON body carries exactly this `entitled` value. */
function reply_with($json_fragment): array
{
    return array(
        'response' => array('code' => 200),
        'body'     => '{"entitled":' . $json_fragment . '}',
    );
}

/** Put the site in a known entitled state, then deliver one answer. */
function entitlement_after($json_fragment): bool
{
    $GLOBALS['__options']    = array();
    $GLOBALS['__transients'] = array();

    // Start entitled, by an explicit answer.
    $GLOBALS['__next_http'] = reply_with('true');
    ccm_tools_registry_check(true);
    if (ccm_tools_registry_is_entitled() !== true) {
        throw new RuntimeException('could not establish the entitled baseline');
    }

    $GLOBALS['__next_http'] = reply_with($json_fragment);
    ccm_tools_registry_check(true);

    return ccm_tools_registry_is_entitled();
}

printf("Entitlement may only be revoked by a real boolean\n\n");

// The only two answers that are decisions.
check('true  keeps the site entitled', entitlement_after('true') === true);
check('false revokes, because somebody decided it', entitlement_after('false') === false);

printf("\n  everything below is a degenerate value, not a decision:\n");

$degenerate = array(
    'null'    => 'null',
    '0'       => '0',
    '"0"'     => '"0"',
    '""'      => '""',
    '[]'      => '[]',
    '{}'      => '{}',
    '"false"' => '"false"',
    '"true"'  => '"true"',
    '1'       => '1',
);

foreach ($degenerate as $label => $fragment) {
    check(
        sprintf('%-8s leaves entitlement alone', $label),
        entitlement_after($fragment) === true,
        'a site was told it is not entitled by a value nobody chose'
    );
}

printf("\n");

// The shapes that were already handled, confirmed still handled.
foreach (array(
    'no entitled key' => '{"notice":"hello"}',
    'truncated JSON'  => '{"entitled":tr',
    'HTML'            => '<html>captive portal</html>',
    'empty body'      => '',
) as $label => $body) {
    $GLOBALS['__options']    = array();
    $GLOBALS['__transients'] = array();
    $GLOBALS['__next_http']  = reply_with('true');
    ccm_tools_registry_check(true);

    $GLOBALS['__next_http'] = array('response' => array('code' => 200), 'body' => $body);
    ccm_tools_registry_check(true);

    check(
        sprintf('%-16s still leaves entitlement alone', $label),
        ccm_tools_registry_is_entitled() === true
    );
}

printf("\n");
if ($failures) {
    printf("%d check(s) failed\n", $failures);
    exit(1);
}
printf("only an explicit true or false moves entitlement; nothing else does\n");
