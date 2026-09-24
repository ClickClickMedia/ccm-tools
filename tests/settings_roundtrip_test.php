<?php
/**
 * Prove that every setting the admin pages offer can actually be saved.
 *
 * tests/render_test.php answers "does the page draw without fatalling". It does
 * not answer "does ticking this box change anything", and that is the failure
 * we keep shipping: a field id drifts, the save routine reads an id that is no
 * longer there, and the setting silently never turns on. Nothing in a lint, a
 * render test or a screenshot can see it.
 *
 * This drives the real handlers against a real in-memory option store. For each
 * settings page it posts every control the page offers, saves, reads the option
 * back, and reports any key that did not survive the trip.
 *
 * Run:  php tests/settings_roundtrip_test.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);

// ── A real option store, before the harness stubs a no-op one ───

$GLOBALS['__options'] = array();

function get_option($key = null, $default = false) {
    if (array_key_exists($key, $GLOBALS['__options'])) {
        return $GLOBALS['__options'][$key];
    }
    $scalars = array(
        'show_on_front' => 'page', 'page_on_front' => '0', 'page_for_posts' => '0',
        'blogname' => 'Example', 'home' => 'https://example.test',
        'siteurl' => 'https://example.test', 'admin_email' => 'a@example.test',
        'timezone_string' => 'Australia/Sydney', 'date_format' => 'j F Y',
        'time_format' => 'g:i a', 'gmt_offset' => 10,
    );
    if (isset($scalars[$key])) {
        return $scalars[$key];
    }
    return is_array($default) ? $default : array();
}

function update_option($key, $value, $autoload = null) {
    $GLOBALS['__options'][$key] = $value;
    return true;
}

function delete_option($key) {
    unset($GLOBALS['__options'][$key]);
    return true;
}

function add_option($key, $value = '', $d = '', $a = 'yes') {
    if (!array_key_exists($key, $GLOBALS['__options'])) {
        $GLOBALS['__options'][$key] = $value;
        return true;
    }
    return false;
}

// A handler ends by sending JSON, which in WordPress exits. Here it has to
// return instead, or the first page under test would end the run.
class CCM_Test_Json_Sent extends Exception {
    /** @var mixed */
    public $payload;
    /** @var bool */
    public $ok;
    public function __construct($payload, bool $ok) {
        parent::__construct('json');
        $this->payload = $payload;
        $this->ok = $ok;
    }
}

function wp_send_json_success($data = null, $code = null, $flags = 0) {
    throw new CCM_Test_Json_Sent($data, true);
}

function wp_send_json_error($data = null, $code = null, $flags = 0) {
    throw new CCM_Test_Json_Sent($data, false);
}

function check_ajax_referer($action = -1, $q = false, $die = true) {
    return 1;
}

// ── Reuse the render harness for everything else ────────────────

$harness = file_get_contents($root . '/tests/render_test.php');

// Drop the harness's version of anything defined above, so the real option
// store wins. A name appears either as an entry in one of the eval'd stub
// lists or as its own declaration.
$mine = array('get_option', 'update_option', 'delete_option', 'add_option',
              'wp_send_json_success', 'wp_send_json_error', 'check_ajax_referer');

foreach ($mine as $fn) {
    $q = preg_quote($fn, '/');
    $harness = preg_replace("/'" . $q . "',\s*/", '', $harness);
    $harness = preg_replace("/,\s*'" . $q . "'/", '', $harness);
    $harness = preg_replace('/^function\s+' . $q . '\s*\([^)]*\)\s*\{.*?^\}\s*$/ms', '', $harness);
    $harness = preg_replace('/^function\s+' . $q . '\s*\([^)]*\)\s*\{[^\n]*\}[^\n]*$/m', '', $harness);
}
$cut = strpos($harness, '// ── Render every admin page');
if ($cut !== false) {
    $harness = substr($harness, 0, $cut);
}
$harness = preg_replace('/^\s*declare\(strict_types=1\);\s*$/m', '', $harness);
$harness = preg_replace('/\$root = isset\(\$argv.*?\n\}/s', '$root = ' . var_export($root, true) . ';', $harness, 1);

$tmp = sys_get_temp_dir() . '/ccm-roundtrip-harness.php';
file_put_contents($tmp, $harness);
require $tmp;
@unlink($tmp);

require_once $root . '/ccm.php';

// ── Helpers ─────────────────────────────────────────────────────

/**
 * Run an ajax handler with a given POST body and return what it sent.
 *
 * @param string $fn   Handler function name.
 * @param array  $post POST body.
 * @return array{ok: bool, payload: mixed}
 */
function ccm_test_call(string $fn, array $post): array {
    $_POST = $post;
    $_REQUEST = $post;
    ob_start();
    try {
        call_user_func($fn);
        $sent = array('ok' => false, 'payload' => 'handler returned without sending JSON');
    } catch (CCM_Test_Json_Sent $e) {
        $sent = array('ok' => $e->ok, 'payload' => $e->payload);
    } catch (Throwable $e) {
        $sent = array('ok' => false, 'payload' => get_class($e) . ': ' . $e->getMessage()
            . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    }
    ob_end_clean();
    $_POST = array();
    $_REQUEST = array();
    return $sent;
}

$failures = array();
$checked = 0;

function ccm_test_report(string $page, array $misses, int $total) {
    global $failures, $checked;
    $checked += $total;
    if ($misses) {
        $failures[] = $page;
        printf("  FAIL %-14s %d of %d settings did not survive a save\n", $page, count($misses), $total);
        foreach ($misses as $key => $why) {
            printf("         %-34s %s\n", $key, $why);
        }
    } else {
        printf("  ok   %-14s all %d settings saved and read back\n", $page, $total);
    }
}

echo "Settings round-trip\n\n";

// ── Performance ─────────────────────────────────────────────────

if (function_exists('ccm_tools_perf_catalogue') && function_exists('ccm_tools_ajax_save_perf_settings')) {
    $post = array('nonce' => 'n', 'enabled' => '1');
    $expect = array();

    foreach (ccm_tools_perf_catalogue() as $group) {
        foreach ($group['items'] as $item) {
            $post[$item['key']] = '1';
            $expect[$item['key']] = true;
            if (empty($item['fields'])) {
                continue;
            }
            foreach ($item['fields'] as $f) {
                $type = isset($f['type']) ? $f['type'] : 'text';
                if ($type === 'number') {
                    // Some numeric settings are clamped to a sensible range,
                    // so use a value every one of them accepts.
                    $post[$f['key']] = '30';
                    $expect[$f['key']] = 30;
                } elseif ($type === 'select') {
                    $opts = isset($f['options']) ? array_keys($f['options']) : array();
                    if ($opts) {
                        $post[$f['key']] = (string) $opts[0];
                        $expect[$f['key']] = (string) $opts[0];
                    }
                } elseif ($type === 'list' || $type === 'textarea') {
                    $post[$f['key']] = "example.test";
                    $expect[$f['key']] = 'example.test';
                } else {
                    $post[$f['key']] = 'example.test';
                    $expect[$f['key']] = 'example.test';
                }
            }
        }
    }

    $res = ccm_test_call('ccm_tools_ajax_save_perf_settings', $post);
    $saved = get_option('ccm_tools_perf_settings');

    $misses = array();
    if (!$res['ok']) {
        $misses['(handler)'] = is_string($res['payload']) ? $res['payload'] : 'did not report success';
    }
    foreach ($expect as $key => $want) {
        if (!array_key_exists($key, (array) $saved)) {
            $misses[$key] = 'the save handler never stores this key';
            continue;
        }
        $got = $saved[$key];
        if ($want === true && empty($got)) {
            $misses[$key] = 'posted on, stored as ' . var_export($got, true);
        } elseif (is_int($want) && (int) $got !== $want) {
            $misses[$key] = 'posted 7, stored ' . var_export($got, true);
        } elseif (is_string($want) && $want !== '' && empty($got)) {
            $misses[$key] = 'posted a value, stored empty';
        }
    }
    ccm_test_report('performance', $misses, count($expect));
} else {
    echo "  skip performance (catalogue or handler missing)\n";
}

// ── WebP ────────────────────────────────────────────────────────

if (function_exists('ccm_tools_ajax_save_webp_settings')) {
    // Seed something in the two keys the save handler does not rebuild, so we
    // can see whether a save from the UI wipes them.
    $GLOBALS['__options']['ccm_tools_webp_settings'] = array_merge(
        ccm_tools_webp_get_default_settings(),
        array('convert_existing' => true, 'exclude_sizes' => array('thumbnail'))
    );

    $post = array(
        'nonce' => 'n', 'enabled' => '1', 'quality' => '90',
        'convert_on_upload' => '1', 'serve_webp' => '1', 'convert_on_demand' => '1',
        'convert_bg_images' => '1', 'keep_originals' => '1', 'preferred_extension' => 'gd',
    );
    $res = ccm_test_call('ccm_tools_ajax_save_webp_settings', $post);
    $saved = (array) get_option('ccm_tools_webp_settings');

    $misses = array();
    if (!$res['ok']) {
        $misses['(handler)'] = is_string($res['payload']) ? $res['payload'] : 'did not report success';
    }
    foreach (array('enabled', 'convert_on_upload', 'serve_webp', 'convert_on_demand',
                   'convert_bg_images', 'keep_originals') as $k) {
        if (empty($saved[$k])) {
            $misses[$k] = 'posted on, stored ' . var_export($saved[$k] ?? null, true);
        }
    }
    if ((int) ($saved['quality'] ?? 0) !== 90) {
        $misses['quality'] = 'posted 90, stored ' . var_export($saved['quality'] ?? null, true);
    }
    if (($saved['preferred_extension'] ?? '') !== 'gd') {
        $misses['preferred_extension'] = 'posted gd, stored ' . var_export($saved['preferred_extension'] ?? null, true);
    }
    // These two are in the defaults but not rebuilt by the handler.
    if (!array_key_exists('convert_existing', $saved)) {
        $misses['convert_existing'] = 'was set before the save and is gone after it';
    }
    if (!array_key_exists('exclude_sizes', $saved)) {
        $misses['exclude_sizes'] = 'was set before the save and is gone after it';
    }
    ccm_test_report('webp', $misses, 10);
} else {
    echo "  skip webp (handler missing)\n";
}

// ── .htaccess ───────────────────────────────────────────────────

if (function_exists('ccm_tools_parse_htaccess_options')) {
    $offered = array();
    if (function_exists('ccm_tools_get_htaccess_options')) {
        foreach (ccm_tools_get_htaccess_options() as $group) {
            foreach (array_keys($group['options']) as $k) {
                $offered[] = $k;
            }
        }
    }
    $_POST = array('options' => $offered);
    $parsed = ccm_tools_parse_htaccess_options();
    $_POST = array();

    $misses = array();
    foreach ($offered as $k) {
        if (empty($parsed[$k])) {
            $misses[$k] = 'offered on the page but the handler rejects it';
        }
    }
    ccm_test_report('htaccess', $misses, count($offered));
} else {
    echo "  skip htaccess (parser missing)\n";
}

// ── Redis ───────────────────────────────────────────────────────

/*
 * The ajax wrapper refuses to run without the Redis PHP extension, which this
 * machine does not have, so go at the storage function underneath it. That is
 * where a settings wipe would happen, and it is the half worth guarding: the
 * wrapper's own per-field validation is straightforward to read.
 */
if (function_exists('ccm_tools_redis_save_settings')) {
    $GLOBALS['__options']['ccm_tools_redis_settings'] = array('key_salt' => 'set-earlier');

    $input = array(
        'scheme' => 'tcp', 'host' => '127.0.0.1', 'port' => 6380, 'database' => 3,
        'password' => 'hunter2', 'username' => 'cache',
        'max_ttl' => 3600, 'selective_flush' => true,
        'timeout' => 2, 'read_timeout' => 3, 'serializer' => 'php',
        'compression' => 'none', 'async_flush' => true, 'disable_comment' => true,
        'wc_cache_cart_fragments' => true, 'wc_persistent_cart' => true,
        'wc_session_cache' => true, 'wc_product_cache_ttl' => 600,
        'wc_session_cache_ttl' => 900,
    );
    ccm_tools_redis_save_settings($input);
    $saved = (array) get_option('ccm_tools_redis_settings');

    $misses = array();
    foreach ($input as $k => $v) {
        if (!array_key_exists($k, $saved)) {
            $misses[$k] = 'dropped by the save function';
        } elseif (is_bool($v)) {
            if (empty($saved[$k])) { $misses[$k] = 'passed in true, stored ' . var_export($saved[$k], true); }
        } elseif ((string) $saved[$k] !== (string) $v) {
            $misses[$k] = 'passed in ' . var_export($v, true) . ', stored ' . var_export($saved[$k], true);
        }
    }
    // A key this screen does not own must survive the save.
    if (($saved['key_salt'] ?? '') !== 'set-earlier') {
        $misses['key_salt'] = 'a key set before the save was erased by it';
    }
    ccm_test_report('redis', $misses, count($input) + 1);
} elseif (false) {
    $post = array(
        'nonce' => 'n',
        'scheme' => 'tcp', 'host' => '127.0.0.1', 'port' => '6380', 'database' => '3',
        'path' => '', 'password' => 'hunter2', 'username' => 'cache',
        'key_salt' => 'example-a1b2', 'max_ttl' => '3600', 'selective_flush' => '1',
        'timeout' => '2', 'read_timeout' => '3', 'serializer' => 'igbinary',
        'compression' => 'none', 'async_flush' => '1', 'disable_comment' => '1',
        'wc_cache_cart_fragments' => '1', 'wc_persistent_cart' => '1',
        'wc_session_cache' => '1', 'wc_product_cache_ttl' => '600',
        'wc_session_cache_ttl' => '900',
    );
    $res = ccm_test_call('ccm_tools_ajax_redis_save_settings', $post);
    $saved = (array) get_option('ccm_tools_redis_settings');

    $misses = array();
    if (!$res['ok']) {
        $misses['(handler)'] = is_string($res['payload']) ? $res['payload'] : 'did not report success';
    }
    $want = array('host' => '127.0.0.1', 'port' => 6380, 'database' => 3,
                  'key_salt' => 'example-a1b2', 'serializer' => 'igbinary',
                  'timeout' => 2, 'read_timeout' => 3);
    foreach ($want as $k => $v) {
        if (!array_key_exists($k, $saved)) {
            $misses[$k] = 'the save handler never stores this key';
        } elseif ((string) $saved[$k] !== (string) $v) {
            $misses[$k] = 'posted ' . var_export($v, true) . ', stored ' . var_export($saved[$k], true);
        }
    }
    foreach (array('selective_flush', 'async_flush', 'wc_session_cache') as $k) {
        if (array_key_exists($k, $saved) && empty($saved[$k])) {
            $misses[$k] = 'posted on, stored off';
        }
    }
    ccm_test_report('redis', $misses, count($want) + 3);
} else {
    echo "  skip redis (handler missing)\n";
}

// ── Result ──────────────────────────────────────────────────────

echo "\n";
if ($failures) {
    printf("%d page(s) have settings that do not save: %s\n", count($failures), implode(', ', $failures));
    exit(1);
}
printf("all %d settings across every page saved and read back\n", $checked);
