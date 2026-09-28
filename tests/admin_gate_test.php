<?php
/**
 * Only an administrator may see or use CCM Tools.
 *
 * The gate used to be `current_user_can('manage_options')`, which sounds like
 * "is an administrator" and is not. Shop manager roles, client roles built by
 * membership plugins and several page builders' "site manager" roles are all
 * handed manage_options on real sites. This plugin rewrites wp-config.php and
 * .htaccess, installs an object cache drop-in and permanently deletes database
 * rows, so the capability alone is the wrong gate.
 *
 * This drives ccm_tools_user_is_admin() against each shape of user that
 * matters, rather than reading the function and agreeing with it.
 *
 * Run:  php tests/admin_gate_test.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);

// ── Controllable stubs, installed before the plugin is loaded ────

$GLOBALS['__caps']       = array('manage_options' => true);
$GLOBALS['__roles']      = array('administrator');
$GLOBALS['__uid']        = 1;
$GLOBALS['__multisite']  = false;
$GLOBALS['__superadmin'] = false;

function current_user_can($cap) {
    return !empty($GLOBALS['__caps'][$cap]);
}
function wp_get_current_user() {
    $u = new stdClass();
    $u->ID = $GLOBALS['__uid'];
    $u->roles = $GLOBALS['__roles'];
    return $u;
}
function is_multisite() {
    return (bool) $GLOBALS['__multisite'];
}
function is_super_admin($user_id = false) {
    return (bool) $GLOBALS['__superadmin'];
}

/*
 * ccm.php is a plugin bootstrap: loading the whole thing would register hooks
 * and pull in every module. Only the gate is under test, so lift that one
 * function out of the source and evaluate it on its own.
 */
$src = (string) file_get_contents($root . '/ccm.php');
$start = strpos($src, 'function ccm_tools_user_is_admin');
if ($start === false) {
    fwrite(STDERR, "ccm_tools_user_is_admin() not found in ccm.php\n");
    exit(2);
}
$open = strpos($src, '{', $start);
$depth = 0;
$end = null;
for ($i = $open, $len = strlen($src); $i < $len; $i++) {
    if ($src[$i] === '{') {
        $depth++;
    } elseif ($src[$i] === '}') {
        $depth--;
        if ($depth === 0) { $end = $i; break; }
    }
}
if ($end === null) {
    fwrite(STDERR, "could not brace-match ccm_tools_user_is_admin()\n");
    exit(2);
}
eval(substr($src, $start, $end - $start + 1));

// ── The users that matter ───────────────────────────────────────

$cases = array(
    // label, caps, roles, uid, multisite, superadmin, expected
    array('administrator',
          array('manage_options' => true), array('administrator'), 1, false, false, true),

    array('administrator with extra roles',
          array('manage_options' => true), array('shop_manager', 'administrator'), 4, false, false, true),

    array('shop manager granted manage_options',
          array('manage_options' => true), array('shop_manager'), 7, false, false, false),

    array('client role granted manage_options',
          array('manage_options' => true), array('client'), 9, false, false, false),

    array('editor',
          array('manage_options' => false), array('editor'), 3, false, false, false),

    array('subscriber',
          array('manage_options' => false), array('subscriber'), 5, false, false, false),

    array('logged out',
          array('manage_options' => false), array(), 0, false, false, false),

    // A user id of 0 is nobody, whatever a broken cap filter claims.
    array('no user, but manage_options somehow true',
          array('manage_options' => true), array('administrator'), 0, false, false, false),

    // Super admins routinely hold no role on a given subsite.
    array('multisite super admin with no role here',
          array('manage_options' => false), array(), 2, true, true, true),

    array('multisite site admin',
          array('manage_options' => true), array('administrator'), 6, true, false, true),

    array('multisite editor',
          array('manage_options' => false), array('editor'), 8, true, false, false),
);

printf("Admin gate\n\n");

$failures = 0;
foreach ($cases as $c) {
    list($label, $caps, $roles, $uid, $ms, $super, $expected) = $c;

    $GLOBALS['__caps']       = $caps;
    $GLOBALS['__roles']      = $roles;
    $GLOBALS['__uid']        = $uid;
    $GLOBALS['__multisite']  = $ms;
    $GLOBALS['__superadmin'] = $super;

    $got = ccm_tools_user_is_admin();

    if ($got === $expected) {
        printf("  ok   %-42s %s\n", $label, $expected ? 'allowed' : 'refused');
    } else {
        $failures++;
        printf("  FAIL %-42s expected %s, got %s\n",
            $label,
            $expected ? 'allowed' : 'refused',
            $got ? 'allowed' : 'refused'
        );
    }
}

// ── Nothing may still be gated on the capability alone ──────────

$files = array($root . '/ccm.php');
foreach (glob($root . '/inc/*.php') as $f) {
    $files[] = $f;
}

$stragglers = array();
foreach ($files as $f) {
    $s = (string) file_get_contents($f);
    // The gate's own call is the one legitimate use in the codebase.
    if (basename($f) === 'ccm.php') {
        $s = str_replace(substr($src, $start, $end - $start + 1), '', $s);
    }
    $n = substr_count($s, "current_user_can('manage_options')");
    if ($n > 0) {
        $stragglers[] = basename($f) . ' x' . $n;
    }
}

printf("\n");
if ($stragglers) {
    $failures++;
    printf("  FAIL still gated on manage_options alone: %s\n", implode(', ', $stragglers));
} else {
    printf("  ok   nothing is gated on manage_options alone\n");
}

printf("\n");
if ($failures) {
    printf("%d check(s) failed\n", $failures);
    exit(1);
}
printf("all %d users resolved correctly, and the gate is the only caller\n", count($cases));
