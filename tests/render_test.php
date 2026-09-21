<?php
/**
 * Smoke test for CCM Tools: does it load, and does every admin page render?
 *
 *     php tests/render_test.php
 *
 * Stubs the WordPress functions the plugin touches while its files are being
 * included, then includes every module. Catches the class of failure that a
 * plain `php -l` cannot: a redeclared symbol, a fatal at file scope, or a call
 * to a function that lived in one of the deleted modules.
 *
 * It does NOT prove any handler is correct, that any AJAX round trip works, or
 * that a page LOOKS right. It proves the plugin loads and that every admin page
 * callback runs to completion without throwing.
 *
 * That second half exists because of a real fatal shipped in v8.0.0: the
 * dashboard called ccm_tools_convert_php_size_to_bytes() about a hundred lines
 * above the point where that function was declared, nested inside the very
 * method doing the calling. `php -l` is happy with it, importing the file is
 * happy with it, and the page dies the moment anyone opens it. Only executing
 * the callback finds that class of bug.
 *
 * The WordPress stubs below are deliberately dumb. If a page starts needing a
 * behaviour a stub does not have, teach the stub rather than skipping the page.
 */

declare(strict_types=1);

$root = isset($argv[1]) && $argv[1] !== '' ? $argv[1] : dirname(__DIR__);
if (!is_file($root . '/ccm.php')) {
    fwrite(STDERR, "cannot find ccm.php under: $root
");
    exit(2);
}

define('ABSPATH', $root . '/fake-wp/');
define('WP_CONTENT_DIR', $root . '/fake-wp/wp-content');
define('WP_PLUGIN_DIR', $root . '/fake-wp/wp-content/plugins');
define('WPINC', 'wp-includes');
define('HOUR_IN_SECONDS', 3600);
define('MINUTE_IN_SECONDS', 60);
define('DAY_IN_SECONDS', 86400);
define('WEEK_IN_SECONDS', 604800);
define('MONTH_IN_SECONDS', 2592000);
define('AUTH_KEY', str_repeat('a', 64));
define('SECURE_AUTH_KEY', str_repeat('b', 64));
define('DB_NAME', 'test');
define('DB_USER', 'test');
define('DB_HOST', 'localhost');
define('DB_CHARSET', 'utf8mb4');
define('ARRAY_A', 'ARRAY_A');
define('WP_MEMORY_LIMIT', '256M');
define('ARRAY_N', 'ARRAY_N');
define('DB_PASSWORD', 'x');
define('DB_COLLATE', '');
define('EMPTY_TRASH_DAYS', 30);

$GLOBALS['__hooks'] = array();
$GLOBALS['__ajax_actions'] = array();

/** Record every hook so we can report on them. */
function add_action($hook, $cb = null, $p = 10, $a = 1) {
    $GLOBALS['__hooks'][] = $hook;
    if (strpos((string) $hook, 'wp_ajax_') === 0) {
        $GLOBALS['__ajax_actions'][] = $hook;
    }
    return true;
}
function add_filter($hook, $cb = null, $p = 10, $a = 1) { $GLOBALS['__hooks'][] = $hook; return true; }

// Everything else is a no-op stub. Grouped only for readability.
$void = array(
    'register_activation_hook', 'register_deactivation_hook', 'register_uninstall_hook',
    'load_plugin_textdomain', 'wp_enqueue_script', 'wp_enqueue_style', 'wp_localize_script',
    'wp_register_script', 'wp_register_style', 'wp_die', 'wp_send_json_error',
    'wp_send_json_success', 'do_action', 'wp_clear_scheduled_hook', 'wp_schedule_event',
    'delete_option', 'delete_transient', 'delete_site_transient', 'wp_cache_flush',
    'opcache_invalidate_stub', 'check_ajax_referer', 'wp_enqueue_media', 'add_thickbox',
);
foreach ($void as $fn) {
    if (!function_exists($fn)) {
        eval("function {$fn}() { return null; }");
    }
}

$truthy = array('is_admin', 'is_plugin_active', 'current_user_can', 'wp_doing_cron');
foreach ($truthy as $fn) {
    if (!function_exists($fn)) {
        eval("function {$fn}() { return true; }");
    }
}

$falsy = array(
    'is_multisite', 'wp_is_block_theme', 'is_feed', 'wp_is_json_request', 'is_user_logged_in',
    'wp_doing_ajax', 'has_blocks', 'is_search', 'is_admin_bar_showing', 'wp_next_scheduled',
    'is_woocommerce', 'is_cart', 'is_checkout', 'is_account_page', 'is_singular', 'is_front_page',
);
foreach ($falsy as $fn) {
    if (!function_exists($fn)) {
        eval("function {$fn}() { return false; }");
    }
}

$strings = array(
    'home_url' => 'https://example.test/',
    'site_url' => 'https://example.test/',
    'admin_url' => 'https://example.test/wp-admin/',
    'plugin_dir_path' => '',
    'plugin_dir_url' => 'https://example.test/wp-content/plugins/ccm-tools/',
    'plugin_basename' => 'ccm-tools/ccm.php',
    'get_bloginfo' => '6.8.2',
    'rest_get_url_prefix' => 'wp-json',
    'wp_create_nonce' => 'nonce',
    'get_admin_page_title' => 'CCM Tools',
    'wp_timezone_string' => 'Australia/Sydney',
);
foreach ($strings as $fn => $val) {
    if (!function_exists($fn)) {
        eval("function {$fn}() { return " . var_export($val, true) . "; }");
    }
}

$arrays = array('get_option', 'get_transient', 'get_site_transient', 'wp_parse_args', 'get_plugins', 'headers_list');
foreach ($arrays as $fn) {
    if (!function_exists($fn)) {
        eval("function {$fn}(\$a = null, \$b = null) { return is_array(\$b) ? \$b : array(); }");
    }
}

// Pass-through / identity helpers.
function __($t, $d = null) { return $t; }
function _e($t, $d = null) { echo $t; }
function esc_html($t) { return (string) $t; }
function esc_attr($t) { return (string) $t; }
function esc_url($t) { return (string) $t; }
function esc_url_raw($t) { return (string) $t; }
function esc_textarea($t) { return (string) $t; }
function esc_html__($t, $d = null) { return $t; }
function esc_attr__($t, $d = null) { return $t; }
function esc_html_e($t, $d = null) { echo $t; }
function esc_attr_e($t, $d = null) { echo $t; }
function sanitize_text_field($t) { return (string) $t; }
function sanitize_key($t) { return strtolower((string) $t); }
function sanitize_file_name($t) { return (string) $t; }
function wp_unslash($t) { return $t; }
function wp_kses_post($t) { return (string) $t; }
function wp_strip_all_tags($t) { return strip_tags((string) $t); }
function absint($n) { return abs((int) $n); }
function checked($a, $b = true, $e = true) { return ''; }
function disabled($a, $b = true, $e = true) { return ''; }
function selected($a, $b = true, $e = true) { return ''; }
function wp_parse_url($u, $c = -1) { return parse_url($u, $c); }
function wp_json_encode($d, $f = 0) { return json_encode($d, $f); }
function wp_date($f, $t = null) { return date($f, $t ?: time()); }
function wp_timezone() { return new DateTimeZone('UTC'); }
function wp_upload_dir() {
    return array(
        'basedir' => ABSPATH . 'wp-content/uploads',
        'baseurl' => 'https://example.test/wp-content/uploads',
        'path'    => ABSPATH . 'wp-content/uploads',
        'url'     => 'https://example.test/wp-content/uploads',
        'error'   => false,
    );
}
function update_option($k, $v, $a = null) { return true; }
function add_option($k, $v = '', $d = '', $a = 'yes') { return true; }
function set_transient($k, $v, $e = 0) { return true; }
function get_current_screen() { return null; }
function add_menu_page() { return 'ccm-tools'; }
function add_submenu_page() { return 'ccm-tools-sub'; }
function wp_remote_get($u, $a = array()) { return array('response' => array('code' => 200), 'body' => '{}'); }
function wp_remote_post($u, $a = array()) { return array('response' => array('code' => 200), 'body' => '{}'); }
function wp_remote_request($u, $a = array()) { return array('response' => array('code' => 200), 'body' => '{}'); }
function wp_remote_retrieve_body($r) { return is_array($r) ? ($r['body'] ?? '') : ''; }
function wp_remote_head($u, $a = array()) { return array('response' => array('code' => 200), 'headers' => array(), 'body' => ''); }
function wp_remote_retrieve_header($r, $h) { return ''; }
function wp_remote_retrieve_headers($r) { return array(); }
function wp_remote_retrieve_response_code($r) { return 200; }
function is_wp_error($t) { return $t instanceof WP_Error; }
function wp_safe_remote_get($u, $a = array()) { return wp_remote_get($u, $a); }
function wp_generate_password($l = 12, $s = true, $e = false) { return str_repeat('x', $l); }
function apply_filters($tag, $value) { return $value; }
function has_action($h, $c = false) { return false; }
function did_action($h) { return 0; }
function wp_version_check() { return null; }
function class_exists_wc() { return false; }
function trailingslashit($s) { return rtrim((string) $s, '/\\') . '/'; }
function untrailingslashit($s) { return rtrim((string) $s, '/\\'); }
function wp_mkdir_p($d) { return true; }
function size_format($b, $d = 0) { return $b . ' B'; }
function number_format_i18n($n, $d = 0) { return number_format($n, $d); }
function human_time_diff($f, $t = 0) { return '1 min'; }
function get_locale() { return 'en_AU'; }
function determine_locale() { return 'en_AU'; }
function wp_normalize_path($p) { return str_replace('\\', '/', (string) $p); }

function wp_nonce_url($u, $a = -1, $n = '_wpnonce') { return $u . '&' . $n . '=nonce'; }
function wp_nonce_field($a = -1, $n = '_wpnonce', $r = true, $e = true) { return ''; }
function get_num_queries() { return 0; }
function timer_stop($d = 0, $p = 3) { return '0.1'; }
function get_plugin_data($f, $m = true, $t = true) { return array('Name' => 'X', 'Version' => '1.0'); }
function is_ssl() { return true; }
function count_users() { return array('total_users' => 1); }
function wp_count_posts($t = 'post') { return (object) array('publish' => 0); }
function get_template() { return 'astra'; }
function wp_get_environment_type() { return 'production'; }
function wp_max_upload_size() { return 268435456; }
function current_time($t = 'timestamp', $g = 0) { return time(); }
function mysql2date($f, $d, $t = true) { return $d; }
function get_site_url($b = null, $p = '', $s = null) { return 'https://example.test' . $p; }
function get_home_path() { return ABSPATH; }
function submit_button($t = null, $ty = 'primary', $n = 'submit', $w = true, $o = null) { echo ''; }
function settings_fields($g) { echo ''; }
function do_settings_sections($pg) { echo ''; }
function wp_get_theme() { return new CCM_Fake_Theme(); }

class CCM_Fake_Theme {
    public function get($k) { return 'Astra'; }
    public function get_stylesheet() { return 'astra'; }
    public function __toString() { return 'Astra'; }
}

function self_admin_url() { return ''; }

class WP_Error {
    private $code; private $message; private $data;
    public function __construct($code = '', $message = '', $data = '') {
        $this->code = $code; $this->message = $message; $this->data = $data;
    }
    public function get_error_message() { return $this->message; }
    public function get_error_code() { return $this->code; }
}

class wpdb {
    public $prefix = 'wp_';
    public $options = 'wp_options';
    public $postmeta = 'wp_postmeta';
    public $posts = 'wp_posts';
    public $usermeta = 'wp_usermeta';
    public $commentmeta = 'wp_commentmeta';
    public $termmeta = 'wp_termmeta';
    public $comments = 'wp_comments';
    public $term_relationships = 'wp_term_relationships';
    public $last_error = '';
    public function get_results($q, $o = OBJECT) { return array(); }
    public function get_var($q) { return null; }
    public function get_row($q, $o = OBJECT) { return null; }
    public function get_col($q) { return array(); }
    public function query($q) { return 0; }
    public function prepare($q) { return $q; }
    public function esc_like($t) { return $t; }
    public function db_version() { return '10.11.6'; }
    public function db_server_info() { return '10.11.6-MariaDB'; }
    public function get_charset_collate() { return 'DEFAULT CHARACTER SET utf8mb4'; }
    public function has_cap($c) { return true; }
    public function tables($scope = 'all', $prefix = true, $blog_id = 0) { return array(); }
    public function get_blog_prefix($b = null) { return 'wp_'; }
    public function flush() { return null; }
    public function check_connection($e = true) { return true; }
}
if (!defined('OBJECT')) { define('OBJECT', 'OBJECT'); }
$GLOBALS['wpdb'] = new wpdb();
$GLOBALS['wp_version'] = '6.8.2';

// ── Load every module ───────────────────────────────────────────
$modules = glob($root . '/inc/*.php');
sort($modules);

$loaded = array();
$failed = array();

foreach ($modules as $file) {
    $name = basename($file);
    if ($name === 'index.php') { continue; }
    try {
        require_once $file;
        $loaded[] = $name;
    } catch (Throwable $e) {
        $failed[] = $name . ': ' . get_class($e) . ' ' . $e->getMessage();
    }
}

echo "Loaded " . count($loaded) . " modules:\n";
foreach ($loaded as $m) { echo "  ok  $m\n"; }
if ($failed) {
    echo "\nFAILED:\n";
    foreach ($failed as $f) { echo "  XX  $f\n"; }
}

echo "\nwp_ajax_ actions registered: " . count($GLOBALS['__ajax_actions']) . "\n";
$nopriv = array_filter($GLOBALS['__ajax_actions'], function ($a) {
    return strpos($a, 'wp_ajax_nopriv_') === 0;
});
echo "wp_ajax_nopriv_ actions:      " . count($nopriv) . ($nopriv ? ' <-- ' . implode(', ', $nopriv) : ' (good)') . "\n";
echo "total hooks registered:       " . count($GLOBALS['__hooks']) . "\n";

// ── Render every admin page ─────────────────────────────────────
//
// Importing a file only proves it parses. It does not prove a page renders:
// a call to a function that is declared LATER inside the same method is a
// fatal at run time and completely invisible to both `php -l` and a plain
// require. That is exactly the bug this section exists to catch.

require_once $root . '/ccm.php';

$pages = array();

if (class_exists('CCMSettings')) {
    $settings_obj = new CCMSettings();
    foreach (array('create_dashboard_page', 'create_database_page', 'create_htaccess_page',
                   'create_woocommerce_page', 'create_debug_page') as $m) {
        if (method_exists($settings_obj, $m)) {
            $pages[$m] = array($settings_obj, $m);
        }
    }
}

foreach (array('ccm_tools_render_redis_page', 'ccm_tools_render_webp_page',
               'ccm_tools_render_perf_page', 'ccm_tools_render_cloudflare_page',
               'ccm_tools_render_error_log_page', 'ccm_tools_render_site_health_page') as $fn) {
    if (function_exists($fn)) {
        $pages[$fn] = $fn;
    }
}

echo "\nRendering " . count($pages) . " admin pages:\n";
$render_failed = array();

foreach ($pages as $label => $callable) {
    ob_start();
    try {
        call_user_func($callable);
        $html = ob_get_clean();
        $len = strlen($html);
        if ($len < 50) {
            echo "  ??  $label rendered only $len bytes\n";
        } else {
            echo "  ok  $label (" . number_format($len) . " bytes)\n";
        }
    } catch (Throwable $e) {
        ob_end_clean();
        $render_failed[] = $label . ': ' . get_class($e) . ' ' . $e->getMessage()
            . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
        echo "  XX  $label\n";
    }
}

if ($render_failed) {
    echo "\nRENDER FAILURES:\n";
    foreach ($render_failed as $f) { echo "  $f\n"; }
}

exit(($failed || $render_failed) ? 1 : 0);
