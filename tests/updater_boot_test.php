<?php
/**
 * The updater must start in cron and WP-CLI, not only when an admin opens
 * wp-admin.
 *
 * Found 2026-11-12 deploying 8.15.1 to chantalleaesthetics.com.au: update.php
 * is require'd from inside plugins_loaded (priority 10) and registered
 * ccm_initialize_updater on plugins_loaded at priority 10, mid-run. WordPress
 * walks a priority's callbacks as a copy, so the new one never ran, and the
 * updater only ever started on admin_init. Cron never saw an update, WP auto-
 * updates never fired, and `wp plugin update` said "already updated".
 *
 * The hook runner below copies that one WP_Hook behaviour: priorities added
 * later in the run are reached, callbacks added to the priority that is
 * running are not. The same fault was reproduced on real WordPress 7.1.2
 * (riktest) before this fix.
 *
 * Run:  php tests/updater_boot_test.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
define('ABSPATH', __DIR__ . '/');
define('CCM_HELPER_ROOT_DIR', $root . '/');
define('HOUR_IN_SECONDS', 3600);

// ── Hooks, with WP_Hook's mid-run semantics ──

$GLOBALS['__hooks'] = array();
$GLOBALS['__running'] = array();
function add_action($tag, $cb, $prio = 10, $args = 1) { $GLOBALS['__hooks'][$tag][$prio][] = $cb; return true; }
function add_filter($tag, $cb, $prio = 10, $args = 1) { return add_action($tag, $cb, $prio, $args); }
function do_action($tag) {
    $done = array();
    while (true) {
        $prios = array_keys($GLOBALS['__hooks'][$tag] ?? array());
        sort($prios);
        $next = null;
        foreach ($prios as $p) { if (!in_array($p, $done, true)) { $next = $p; break; } }
        if ($next === null) { return; }
        $done[] = $next;
        foreach ($GLOBALS['__hooks'][$tag][$next] as $cb) { $cb(); }   // array copy: additions to $next are skipped
    }
}
function has_updater_filter() {
    foreach ($GLOBALS['__hooks']['pre_set_site_transient_update_plugins'] ?? array() as $cbs) {
        foreach ($cbs as $cb) { if (is_array($cb) && $cb[0] instanceof CCM_Tools_Updater) { return true; } }
    }
    return false;
}

// ── Context ──

$GLOBALS['__ctx'] = array('cron' => false, 'admin' => false, 'ajax' => false, 'can' => false);
function wp_doing_cron() { return $GLOBALS['__ctx']['cron']; }
function wp_doing_ajax() { return $GLOBALS['__ctx']['ajax']; }
function is_admin() { return $GLOBALS['__ctx']['admin']; }
function current_user_can($c) { return $GLOBALS['__ctx']['can']; }
function plugin_basename($f) { return 'ccm-tools/' . basename($f); }
function is_plugin_active($p) { return true; }
function get_option($k, $d = false) { return $d; }
function get_site_transient($k) { return false; }
function wp_parse_url($u, $c = -1) { return parse_url($u, $c); }
function __($s, $d = null) { return $s; }

// Each context needs a fresh static guard, so each runs in its own process.
if (isset($argv[1])) {
    $ctx = json_decode((string) base64_decode($argv[1]), true);
    $GLOBALS['__ctx'] = array_merge($GLOBALS['__ctx'], $ctx);
    if (!empty($ctx['cli'])) { define('WP_CLI', true); }

    // ccm.php: add_action('plugins_loaded', 'ccm_initialize_plugin', 10), which requires update.php.
    add_action('plugins_loaded', function () use ($root) { require_once $root . '/inc/update.php'; }, 10);
    do_action('plugins_loaded');
    do_action('init');
    if ($GLOBALS['__ctx']['admin']) { do_action('admin_init'); }
    echo has_updater_filter() ? 'started' : 'not started';
    exit(0);
}

$cases = array(
    'cron'                     => array(array('cron' => true), 'started'),
    'WP-CLI'                   => array(array('cli' => true), 'started'),
    'wp-admin, can update'     => array(array('admin' => true, 'can' => true), 'started'),
    'wp-admin, cannot update'  => array(array('admin' => true, 'can' => false), 'not started'),
    'front end'                => array(array(), 'not started'),
    'admin-ajax, cannot update'=> array(array('admin' => true, 'ajax' => true, 'can' => false), 'not started'),
);

$fail = 0;
foreach ($cases as $name => list($ctx, $want)) {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg(base64_encode(json_encode($ctx ?: new stdClass()))) . ' 2>&1';
    $got = trim((string) shell_exec($cmd));
    $ok = ($got === $want);
    if (!$ok) { $fail++; }
    printf("  %s  %-26s want %-12s got %s\n", $ok ? 'ok  ' : 'FAIL', $name, $want, $got);
}

echo $fail ? "\n$fail context(s) wrong\n" : "\nthe updater starts in cron, WP-CLI and capable wp-admin, and nowhere else\n";
exit($fail ? 1 : 0);
