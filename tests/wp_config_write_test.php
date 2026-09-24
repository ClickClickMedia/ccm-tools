<?php
/**
 * Writing Redis settings must never produce a wp-config.php that will not parse.
 *
 * A broken wp-config.php is the worst outage this plugin can cause: the front
 * end and wp-admin both white-screen, so the tool that caused it cannot be used
 * to undo it, and recovery needs SFTP or shell.
 *
 * There was already a test for the escaping of a single define() line, and it
 * passed, because it tested the function that had been named in a report rather
 * than the operation the report was about. The insert underneath it used
 * preg_replace with the generated block as the replacement string, so a `$1`
 * inside a password was read as a backreference and the capture group was
 * spliced into the credential. That group is the "That's all, stop editing!"
 * comment, apostrophe and all, so the file became a parse error.
 *
 * This drives the real writer against a real wp-config fixture and then runs
 * `php -l` over what it produced. Any value that can break the file fails here,
 * whatever the mechanism, which is the property actually worth holding.
 *
 * Run:  php tests/wp_config_write_test.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);

$fixture_dir = sys_get_temp_dir() . '/ccm-wpconfig-' . bin2hex(random_bytes(4));
mkdir($fixture_dir, 0777, true);
define('ABSPATH', $fixture_dir . '/');

/*
 * The writer refuses to touch wp-config.php unless it can first take an
 * encrypted backup, and that needs the salts and OpenSSL. That refusal is
 * correct behaviour, so give it what it needs rather than working around it.
 */
define('AUTH_KEY', 'test-auth-key-for-the-backup-cipher-0123456789');
define('SECURE_AUTH_KEY', 'test-secure-auth-key-for-the-hmac-0123456789');

if (!extension_loaded('openssl')) {
    fwrite(STDERR, "skip: this PHP has no openssl, so the writer cannot take its backup
");
    exit(0);
}

$WP_CONFIG = <<<'WPC'
<?php
/** The base configuration for WordPress */
define( 'DB_NAME', 'example_db' );
define( 'DB_USER', 'example_user' );
define( 'DB_PASSWORD', 'example_pass' );
define( 'DB_HOST', 'localhost' );

define( 'AUTH_KEY',         'put your unique phrase here' );
define( 'SECURE_AUTH_KEY',  'put your unique phrase here' );

$table_prefix = 'wp_';

define( 'WP_DEBUG', false );

/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once ABSPATH . 'wp-settings.php';
WPC;

// ── Load the plugin under stubbed WordPress ─────────────────────

/*
 * The render harness stubs wp_mkdir_p() as a no-op, which is right for a
 * render test and wrong here: the writer creates its backup directory through
 * it, and a directory that is never created makes the write refuse. Strip the
 * stub and use a real one.
 */
function wp_mkdir_p($dir) {
    return is_dir($dir) ? true : @mkdir($dir, 0777, true);
}

$harness = file_get_contents($root . '/tests/render_test.php');
$harness = preg_replace('/^function\s+wp_mkdir_p\s*\([^)]*\)\s*\{[^
]*\}[^
]*$/m', '', $harness);
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

/*
 * The writer stores its encrypted backup under the uploads directory, so the
 * fixture needs that tree to exist for the same reason a real site does.
 */
$uploads = ABSPATH . 'wp-content/uploads';
if (!is_dir($uploads)) {
    mkdir($uploads, 0777, true);
}

require_once $root . '/inc/redis-object-cache.php';

if (!function_exists('ccm_tools_redis_add_config')) {
    fwrite(STDERR, "ccm_tools_redis_add_config() not found\n");
    exit(2);
}

// ── The values worth throwing at it ─────────────────────────────

/*
 * Every one of these passes the handler's own validator, which rejects quotes,
 * backslashes and control characters and nothing else. They are ordinary
 * output from pwgen, openssl rand -base64 and the like.
 */
$passwords = array(
    'plain'                 => 'hunter2',
    'dollar then digit'     => 'Xk$1vQ9z',
    'dollar brace digit'    => 'Ab${1}cD',
    'several backrefs'      => '$1$2$3$0',
    'dollar at the end'     => 'trailing$',
    'bare dollar'           => 'mid$dle',
    'double dollar'         => 'a$$1b',
    'percent and ampersand' => 'p%40s&w=rd',
    'brackets and braces'   => 'a[1]{2}(3)',
    'unicode'               => 'påsswörd-日本',
    'long random'           => 'aB3$dE6%fG9^hJ2&kL5*mN8(pQ1)rS4',
);

$php = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php';

echo "wp-config write safety\n\n";

$failures = 0;
foreach ($passwords as $label => $password) {
    // Fresh fixture for each case.
    file_put_contents($fixture_dir . '/wp-config.php', $WP_CONFIG);

    ob_start();
    $result = ccm_tools_redis_add_config(array(
        'WP_REDIS_HOST'     => '127.0.0.1',
        'WP_REDIS_PORT'     => 6379,
        'WP_REDIS_PASSWORD' => $password,
        'WP_CACHE'          => true,
    ));
    ob_end_clean();

    $written = (string) @file_get_contents($fixture_dir . '/wp-config.php');

    if (!empty($argv[1]) && $argv[1] === '-v') {
        printf("       -> %s | %s
", var_export($result['success'] ?? null, true), (string) ($result['message'] ?? ''));
    }

    // 1. It must still parse.
    $check = $fixture_dir . '/lint.php';
    file_put_contents($check, $written);
    $out = array();
    $code = 0;
    exec(escapeshellarg($php) . ' -l ' . escapeshellarg($check) . ' 2>&1', $out, $code);
    @unlink($check);

    // 2. The password must come back out exactly as it went in.
    $roundtrip = null;
    if (preg_match("/define\(\s*'WP_REDIS_PASSWORD'\s*,\s*(.*?)\s*\);/", $written, $m)) {
        $roundtrip = @eval('return ' . $m[1] . ';');
    }

    $problems = array();
    if ($code !== 0) {
        $problems[] = 'wp-config.php no longer parses: ' . trim((string) ($out[0] ?? 'unknown'));
    }
    if ($roundtrip === null) {
        $problems[] = 'no WP_REDIS_PASSWORD define was written';
    } elseif ($roundtrip !== $password) {
        $problems[] = 'password changed in the file: wrote ' . var_export($roundtrip, true);
    }
    if (strpos($written, "That's all, stop editing") === false) {
        $problems[] = 'the anchor comment was consumed by the insert';
    }
    if (substr_count($written, 'WP_REDIS_PASSWORD') !== 1) {
        $problems[] = 'the define was written ' . substr_count($written, 'WP_REDIS_PASSWORD') . ' times';
    }

    if ($problems) {
        $failures++;
        printf("  FAIL %-22s %s\n", $label, var_export($password, true));
        foreach ($problems as $p) {
            printf("         %s\n", $p);
        }
    } else {
        printf("  ok   %-22s %s\n", $label, var_export($password, true));
    }
}

// Tidy up, including any backups the writer made.
$rmtree = function ($dir) use (&$rmtree) {
    foreach (glob($dir . '/*') as $f) {
        is_dir($f) ? $rmtree($f) : @unlink($f);
    }
    @rmdir($dir);
};
$rmtree($fixture_dir);

echo "\n";
if ($failures) {
    printf("%d of %d values produced a broken or altered wp-config.php\n", $failures, count($passwords));
    exit(1);
}
printf("all %d values wrote a wp-config.php that parses, with the value intact\n", count($passwords));
