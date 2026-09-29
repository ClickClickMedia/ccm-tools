<?php
/**
 * The update service must never be able to break a client site.
 *
 * Entitlement is the one thing in this plugin that a third party (our own
 * Worker, and whatever sits between it and the site) gets to influence. If it
 * fails in the wrong direction the blast radius is every site running the
 * plugin at once: a DNS wobble, an expired certificate or a bad deploy would
 * put "you are no longer receiving updates" in front of 186 paying customers
 * simultaneously.
 *
 * So the rule is: entitlement is only ever revoked by an explicit, cleanly
 * parsed answer that says so. Everything else - a timeout, a 500, a 403, HTML
 * from a captive portal, truncated JSON, the service deleted outright - leaves
 * the site exactly as it was.
 *
 * This drives the real client against each of those failures rather than
 * reading the function and agreeing with it.
 *
 * Run:  php tests/registry_test.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);

// ── A small WordPress ────────────────────────────────────────────

define('ABSPATH', $root . '/');
define('HOUR_IN_SECONDS', 3600);
define('CCM_HELPER_VERSION', '8.10.0');
define('CCM_HELPER_BASENAME', 'ccm-tools/ccm.php');

$GLOBALS['__options']    = array();
$GLOBALS['__transients'] = array();
$GLOBALS['__next_http']  = null;   // what the next wp_remote_post returns
$GLOBALS['__http_calls'] = 0;

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
function __($s, $d = null) { return $s; }
function get_current_screen() { return null; }
function ccm_tools_user_is_admin(): bool { return true; }

// registry.php registers its recheck handler at file scope and renders a panel;
// neither is under test here, but both have to exist for the file to load.
function add_action($h, $c, $p = 10, $a = 1) { return true; }
function check_ajax_referer($a = '', $q = false, $die = true) { return true; }
function wp_send_json_error($d = null, $s = null) { throw new RuntimeException('json_error'); }
function wp_send_json_success($d = null, $s = null) { throw new RuntimeException('json_success'); }
function human_time_diff($from, $to = 0) { return (string) max(1, (int) round(($to - $from) / 60)) . ' mins'; }
function esc_attr($t) { return htmlspecialchars((string) $t, ENT_QUOTES); }
function _e($t, $d = null) { echo $t; }

class WP_Error {
    public $code;
    public $msg;
    public function __construct($c = '', $m = '') { $this->code = $c; $this->msg = $m; }
    // Real WP_Error has these; the stub did not, and the client calls
    // get_error_message() when recording why an attempt failed. A stub that is
    // missing a method the real class has will invent a bug that is not there.
    public function get_error_message() { return $this->msg; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error($t) { return $t instanceof WP_Error; }

function wp_remote_post($url, $args = array()) {
    $GLOBALS['__http_calls']++;
    $next = $GLOBALS['__next_http'];
    return is_callable($next) ? $next($url, $args) : $next;
}
function wp_remote_retrieve_response_code($r) { return is_array($r) ? ($r['code'] ?? 0) : 0; }
function wp_remote_retrieve_body($r) { return is_array($r) ? ($r['body'] ?? '') : ''; }

require_once $root . '/inc/registry.php';

// ── Helpers ──────────────────────────────────────────────────────

function reset_state(): void {
    $GLOBALS['__options']    = array();
    $GLOBALS['__transients'] = array();
    $GLOBALS['__http_calls'] = 0;
}

function ok_response(bool $entitled, $update = null, string $notice = ''): array {
    return array(
        'code' => 200,
        'body' => json_encode(array(
            'entitled' => $entitled,
            'notice'   => $notice,
            'update'   => $update,
        )),
    );
}

$failures = 0;
function check(string $label, bool $pass, string $detail = ''): void {
    global $failures;
    if ($pass) {
        printf("  ok   %s\n", $label);
        return;
    }
    $failures++;
    printf("  FAIL %-58s %s\n", $label, $detail);
}

printf("Update entitlement\n\n");

$sample_update = array(
    'version' => '8.11.0',
    'package' => 'https://updates.clickclick.media/v1/download?token=abc',
    'sha256'  => str_repeat('a', 64),
);

// ── 1. A site nobody has ever asked about is entitled ────────────

reset_state();
check(
    'unknown site is entitled',
    ccm_tools_registry_is_entitled() === true,
    'an unasked site must not be treated as blocked'
);

// ── 2. Every failure shape leaves the site alone ─────────────────

$failure_modes = array(
    'connection error'      => new WP_Error('http_request_failed', 'cURL error 6'),
    'service 500'           => array('code' => 500, 'body' => 'upstream error'),
    'service 403'           => array('code' => 403, 'body' => 'forbidden'),
    'service 404'           => array('code' => 404, 'body' => 'not found'),
    'captive portal HTML'   => array('code' => 200, 'body' => '<html>Sign in to continue</html>'),
    'truncated JSON'        => array('code' => 200, 'body' => '{"entitled":fal'),
    'JSON without entitled' => array('code' => 200, 'body' => '{"update":null}'),
    'empty body'            => array('code' => 200, 'body' => ''),
    'null'                  => null,
);

foreach ($failure_modes as $label => $response) {
    reset_state();
    $GLOBALS['__next_http'] = $response;

    ccm_tools_registry_check(true);

    check(
        sprintf('%-22s leaves the site entitled', $label),
        ccm_tools_registry_is_entitled() === true,
        'a failed check must never block'
    );
}

// ── 3. A previously blocked site is not un-blocked by an outage ──

reset_state();
$GLOBALS['__next_http'] = ok_response(false, null, 'Services cancelled.');
ccm_tools_registry_check(true);
$blocked_after_answer = !ccm_tools_registry_is_entitled();

$GLOBALS['__next_http'] = new WP_Error('http_request_failed', 'timeout');
ccm_tools_registry_check(true);
$still_blocked = !ccm_tools_registry_is_entitled();

check('an explicit block is honoured', $blocked_after_answer, 'a clean "entitled: false" must block');
check('a later outage does not silently un-block', $still_blocked, 'the last good answer must survive');

// ── 4. And an outage does not block a good site ──────────────────

reset_state();
$GLOBALS['__next_http'] = ok_response(true, $sample_update);
ccm_tools_registry_check(true);
$entitled_after_answer = ccm_tools_registry_is_entitled();

$GLOBALS['__next_http'] = array('code' => 503, 'body' => 'down for maintenance');
ccm_tools_registry_check(true);
$still_entitled = ccm_tools_registry_is_entitled();

check('a good answer is honoured', $entitled_after_answer);
check('a later outage does not block a good site', $still_entitled, 'THE failure that matters');

// ── 5. Backoff: a dead service is not hammered ───────────────────

reset_state();
$GLOBALS['__next_http'] = new WP_Error('http_request_failed', 'refused');
ccm_tools_registry_check(true);
$after_first = $GLOBALS['__http_calls'];
ccm_tools_registry_check();   // not forced
ccm_tools_registry_check();
ccm_tools_registry_check();
check(
    'a failing service is not retried on every call',
    $GLOBALS['__http_calls'] === $after_first,
    sprintf('made %d calls, expected to stop at %d', $GLOBALS['__http_calls'], $after_first)
);

// ── 6. A fresh good answer is cached, not re-fetched ─────────────

reset_state();
$GLOBALS['__next_http'] = ok_response(true, $sample_update);
ccm_tools_registry_check(true);
$calls = $GLOBALS['__http_calls'];
ccm_tools_registry_check();
ccm_tools_registry_check();
check(
    'a fresh answer is served from cache',
    $GLOBALS['__http_calls'] === $calls,
    sprintf('made %d calls, expected %d', $GLOBALS['__http_calls'], $calls)
);

// ── 7. The update payload has to be usable ───────────────────────

reset_state();
$GLOBALS['__next_http'] = ok_response(true, $sample_update);
ccm_tools_registry_check(true);
$info = ccm_tools_registry_update_info();
check(
    'a usable update is passed through',
    is_array($info) && $info['version'] === '8.11.0' && !empty($info['package'])
);

// A half-formed update must not reach the upgrader.
foreach (array(
    'no version' => array('package' => 'https://x/y'),
    'no package' => array('version' => '8.11.0'),
    'not an array' => 'nope',
) as $label => $broken) {
    reset_state();
    $GLOBALS['__next_http'] = ok_response(true, $broken);
    ccm_tools_registry_check(true);
    check(
        sprintf('%-14s is refused as an update', $label),
        ccm_tools_registry_update_info() === null
    );
}

// ── 8. A blocked site is still told, and only once it is known ───

reset_state();
$GLOBALS['__next_http'] = ok_response(false, null, 'Services cancelled 2026-09-01.');
ccm_tools_registry_check(true);
$state = ccm_tools_registry_state();
check(
    'the reason given by the service is kept',
    is_array($state) && $state['notice'] === 'Services cancelled 2026-09-01.'
);

ob_start();
ccm_tools_registry_admin_notice();
$notice_off_screen = trim(ob_get_clean());
check(
    'no notice is printed away from our own screens',
    $notice_off_screen === '',
    'a notice on every admin page would be nagging'
);

$_GET['page'] = 'ccm-tools';
ob_start();
ccm_tools_registry_admin_notice();
$notice_on_screen = trim(ob_get_clean());
check(
    'the notice appears on our own screens',
    strpos($notice_on_screen, 'Services cancelled') !== false,
    'got: ' . substr($notice_on_screen, 0, 60)
);

reset_state();
$GLOBALS['__next_http'] = ok_response(true, null);
ccm_tools_registry_check(true);
ob_start();
ccm_tools_registry_admin_notice();
$notice_when_fine = trim(ob_get_clean());
check(
    'an entitled site is never nagged',
    $notice_when_fine === '',
    'got: ' . substr($notice_when_fine, 0, 60)
);

printf("\n");
// -- 9. The signal the updater's GitHub fallback hangs off --------
//
// A fallback is only legitimate while the service is not answering. If it is
// answering it is the authority, and falling back would let a blocked site
// help itself to updates from GitHub anyway.

reset_state();
check(
    'never asked            -> degraded, fallback allowed',
    ccm_tools_registry_is_degraded() === true
);

$GLOBALS['__next_http'] = ok_response(true, $sample_update);
ccm_tools_registry_check(true);
check(
    'a good answer          -> not degraded, service is the authority',
    ccm_tools_registry_is_degraded() === false
);

$GLOBALS['__next_http'] = new WP_Error('http_request_failed', 'cURL error 28');
ccm_tools_registry_check(true);
check(
    'service drops          -> degraded again',
    ccm_tools_registry_is_degraded() === true,
    'without this the updater would never fall back'
);

$last = ccm_tools_registry_last_attempt();
check(
    'the failure reason is recorded for the diagnostics panel',
    is_array($last) && $last['ok'] === false && strpos($last['detail'], 'cURL error 28') !== false,
    'got: ' . var_export($last, true)
);

reset_state();
$GLOBALS['__next_http'] = ok_response(false, null, 'Services cancelled.');
ccm_tools_registry_check(true);
$GLOBALS['__next_http'] = new WP_Error('http_request_failed', 'timeout');
ccm_tools_registry_check(true);
check(
    'a blocked site stays blocked when the service drops',
    ccm_tools_registry_is_entitled() === false,
    'otherwise an outage becomes a way around a block'
);

printf("
");
if ($failures) {
    printf("%d check(s) failed\n", $failures);
    exit(1);
}
printf("entitlement fails open on every failure shape, and only an explicit answer blocks\n");
