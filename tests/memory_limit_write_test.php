<?php
/**
 * Setting WP_MEMORY_LIMIT must leave exactly one define in wp-config.php.
 *
 * Two defines of the same constant is not cosmetic: PHP raises "Constant
 * WP_MEMORY_LIMIT already defined" on every request, that output arrives
 * before WordPress sends headers, and every redirect, cookie and admin page
 * after it breaks with "headers already sent".
 *
 * How it happened (pro_court_2026, 2026-11-25): the dropdown opens on the
 * current value, so clicking Update without changing it asks the handler to
 * set the value the file already holds. The replace produced identical text,
 * the handler read "content unchanged" as "nothing matched", and fell through
 * to the insert, adding a second copy above "That's all, stop editing!".
 * Every later save then updated both copies, so the duplicate never went away.
 *
 * This drives the real ajax handler against wp-config fixtures and counts the
 * live defines it leaves behind, then lints the file.
 *
 * Run:  php tests/memory_limit_write_test.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);

$fixture_dir = sys_get_temp_dir() . '/ccm-memlimit-' . bin2hex(random_bytes(4));
mkdir($fixture_dir, 0777, true);
define('ABSPATH', $fixture_dir . '/');

// A handler ends by sending JSON, which in WordPress exits. Here it returns.
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

$mine = array('wp_send_json_success', 'wp_send_json_error', 'check_ajax_referer');
foreach ($mine as $fn) {
    $q = preg_quote($fn, '/');
    $harness = preg_replace("/'" . $q . "',\s*/", '', $harness);
    $harness = preg_replace("/,\s*'" . $q . "'/", '', $harness);
    $harness = preg_replace('/^function\s+' . $q . '\s*\([^)]*\)\s*\{[^\n]*\}[^\n]*$/m', '', $harness, -1, $one_line);
    if (!$one_line) {
        $harness = preg_replace('/^function\s+' . $q . '\s*\([^)]*\)\s*\{.*?^\}\s*$/ms', '', $harness);
    }
}
$cut = strpos($harness, '// ── Render every admin page');
if ($cut !== false) {
    $harness = substr($harness, 0, $cut);
}
$harness = preg_replace('/^\s*declare\(strict_types=1\);\s*$/m', '', $harness);
$harness = preg_replace('/\$root = isset\(\$argv.*?\n\}/s', '$root = ' . var_export($root, true) . ';', $harness, 1);
// ABSPATH is already defined above, pointing at the fixture.
$harness = preg_replace("/define\('ABSPATH',[^\n]*\n/", '', $harness);

$tmp = $fixture_dir . '/harness.php';
file_put_contents($tmp, $harness);
ob_start();
require $tmp;
ob_end_clean();
@unlink($tmp);

ob_start();
require_once $root . '/ccm.php';
if (!function_exists('ccm_tools_ajax_update_memory_limit')) {
    require_once $root . '/inc/ajax-handlers.php';
}
ob_end_clean();

if (!function_exists('ccm_tools_ajax_update_memory_limit')) {
    fwrite(STDERR, "ccm_tools_ajax_update_memory_limit() not found\n");
    exit(2);
}

// ── Fixtures ────────────────────────────────────────────────────

/*
 * Shaped like the real file it broke on: custom defines packed straight
 * after one another in the "custom values" block, no blank line between.
 */
function ccm_test_config(string $custom): string {
    return "<?php\n"
        . "define( 'DB_NAME', 'example_db' );\n"
        . "define( 'DB_USER', 'example_user' );\n"
        . "\$table_prefix = 'wp_';\n"
        . "define( 'WP_DEBUG', false );\n"
        . "\n"
        . "/* Add any custom values between this line and the \"stop editing\" line. */\n"
        . "\n"
        . "define( 'WPLANG', 'en_AU' );\n"
        . "\n"
        . "define( 'DUPLICATOR_AUTH_KEY', 'abc' );\n"
        . $custom
        . "\n"
        . "/* That's all, stop editing! Happy publishing. */\n"
        . "\n"
        . "/** Absolute path to the WordPress directory. */\n"
        . "if ( ! defined( 'ABSPATH' ) ) {\n"
        . "\tdefine( 'ABSPATH', __DIR__ . '/' );\n"
        . "}\n"
        . "\n"
        . "require_once ABSPATH . 'wp-settings.php';\n";
}

$ours   = "define('WP_MEMORY_LIMIT', '%s');\n";
$spaced = "define( 'WP_MEMORY_LIMIT', '%s' );\n";
$commented = "// define('WP_MEMORY_LIMIT', '%s');\n";

/*
 * name => [existing custom block, value to set, expected live define value
 *          (null = none), expected commented lines left]
 */
$cases = array(
    'same value again (the reported bug)' => array(sprintf($ours, '1024M'), '1024M', '1024M', 0),
    'same value, core spacing'            => array(sprintf($spaced, '1024M'), '1024M', '1024M', 0),
    'different value'                     => array(sprintf($ours, '256M'), '1024M', '1024M', 0),
    'none yet'                            => array('', '512M', '512M', 0),
    'already duplicated, new value'       => array(sprintf($ours, '256M') . "\n" . sprintf($ours, '256M'), '1024M', '1024M', 0),
    'already duplicated, same value'      => array(sprintf($ours, '1024M') . "\n" . sprintf($ours, '1024M'), '1024M', '1024M', 0),
    'commented only'                      => array(sprintf($commented, '128M'), '256M', '256M', 0),
    'two commented only'                  => array(sprintf($commented, '128M') . sprintf($commented, '64M'), '256M', '256M', 1),
    'live plus commented'                 => array(sprintf($commented, '128M') . sprintf($ours, '64M'), '256M', '256M', 1),
    'back to default removes it'          => array(sprintf($ours, '256M'), '40M', null, 0),
    'default removes duplicates too'      => array(sprintf($ours, '256M') . sprintf($ours, '256M'), '40M', null, 0),
);

$live_re = '/^[\t ]*define\s*\(\s*[\'"]WP_MEMORY_LIMIT[\'"]\s*,\s*[\'"]([^\'"]*)[\'"]\s*\)/m';
$comment_re = '/^[\t ]*(?:\/\/|#)[\t ]*define\s*\(\s*[\'"]WP_MEMORY_LIMIT[\'"]/m';

$php = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php';

echo "WP_MEMORY_LIMIT writes\n\n";

$failures = 0;
foreach ($cases as $label => [$existing, $set, $expect, $expect_commented]) {
    file_put_contents($fixture_dir . '/wp-config.php', ccm_test_config($existing));

    $_POST = array('nonce' => 'n', 'limit' => $set);
    $_REQUEST = $_POST;
    ob_start();
    try {
        ccm_tools_ajax_update_memory_limit();
        $sent = array('ok' => false, 'payload' => 'handler returned without sending JSON');
    } catch (CCM_Test_Json_Sent $e) {
        $sent = array('ok' => $e->ok, 'payload' => $e->payload);
    } catch (Throwable $e) {
        $sent = array('ok' => false, 'payload' => get_class($e) . ': ' . $e->getMessage());
    }
    ob_end_clean();

    $written = (string) file_get_contents($fixture_dir . '/wp-config.php');
    preg_match_all($live_re, $written, $m);
    $live = $m[1];
    $commented_left = preg_match_all($comment_re, $written);

    $check = $fixture_dir . '/lint.php';
    file_put_contents($check, $written);
    $out = array();
    $code = 0;
    exec(escapeshellarg($php) . ' -l ' . escapeshellarg($check) . ' 2>&1', $out, $code);
    @unlink($check);

    $problems = array();
    if (!$sent['ok']) {
        $problems[] = 'handler reported failure: ' . (is_string($sent['payload']) ? $sent['payload'] : json_encode($sent['payload']));
    }
    if ($expect === null) {
        if (count($live) !== 0) {
            $problems[] = 'expected no live define, found ' . count($live);
        }
    } else {
        if (count($live) !== 1) {
            $problems[] = 'expected exactly 1 live define, found ' . count($live) . ' (' . implode(', ', $live) . ')';
        } elseif ($live[0] !== $expect) {
            $problems[] = "live define holds {$live[0]}, expected {$expect}";
        }
    }
    if ($commented_left !== $expect_commented) {
        $problems[] = "expected {$expect_commented} commented line(s) left, found {$commented_left}";
    }
    if ($code !== 0) {
        $problems[] = 'wp-config.php no longer parses: ' . trim((string) ($out[0] ?? 'unknown'));
    }
    if (strpos($written, "That's all, stop editing") === false) {
        $problems[] = 'the anchor comment was consumed';
    }
    if (strpos($written, "define( 'DUPLICATOR_AUTH_KEY', 'abc' );\n") === false) {
        $problems[] = 'the neighbouring define was damaged';
    }

    if ($problems) {
        $failures++;
        printf("  FAIL %s\n", $label);
        foreach ($problems as $p) {
            printf("         %s\n", $p);
        }
    } else {
        printf("  ok   %s\n", $label);
    }
}

$_POST = array();
$_REQUEST = array();

$rmtree = function ($dir) use (&$rmtree) {
    foreach (glob($dir . '/{,.}[!.]*', GLOB_BRACE) ?: array() as $f) {
        is_dir($f) ? $rmtree($f) : @unlink($f);
    }
    @rmdir($dir);
};
$rmtree($fixture_dir);

echo "\n";
if ($failures) {
    printf("%d of %d cases left wp-config.php wrong\n", $failures, count($cases));
    exit(1);
}
printf("all %d cases left exactly the expected WP_MEMORY_LIMIT\n", count($cases));
