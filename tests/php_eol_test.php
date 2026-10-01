<?php
/**
 * Being on a newer PHP than we have a date for is not a fault.
 *
 * The Site Health platform group grades PHP against a hard-coded table of
 * end-of-life dates. A table like that goes stale by doing nothing, and there
 * are two ways to fall off it: too old to be listed, or newer than anything
 * listed. The first version treated both the same way — anything unlisted was
 * a warning — so a site running PHP 8.5, the newest release available, was
 * told it had "1 to look at" directly above a line reading "Still receiving
 * security fixes". The check contradicted itself, and the better the site was
 * maintained the more likely it was to see it.
 *
 * This matters more than one wrong dot: Site Health's overall score counts a
 * warning as a miss, so every well-maintained site's score was being marked
 * down for being well maintained. And it comes back every time PHP ships a
 * branch, which is why this is a test and not just a line added to the table.
 *
 * Run:  php tests/php_eol_test.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);

// Enough WordPress to load the module.
if (!defined('ABSPATH')) {
    define('ABSPATH', $root . '/');
}
if (!function_exists('__')) {
    function __($text, $domain = null) { return $text; }
}
if (!function_exists('esc_html')) {
    function esc_html($t) { return htmlspecialchars((string) $t, ENT_QUOTES); }
}
if (!function_exists('add_action')) {
    function add_action() { return true; }
}

require_once $root . '/inc/health.php';

$failures = 0;

function check(string $what, bool $ok, string $why = ''): void
{
    global $failures;
    if ($ok) {
        printf("  ok   %s\n", $what);
        return;
    }
    $failures++;
    printf("  FAIL %s%s\n", $what, $why !== '' ? '   ' . $why : '');
}

$eol  = ccm_tools_php_eol_dates();
$keys = array_keys($eol);
$newest = end($keys);
$oldest = reset($keys);

// A fixed "now" so the expectations do not drift with the calendar.
$now = strtotime('2026-10-01');

printf("PHP branch grading\n\n  table runs %s to %s, judged as at 1 Oct 2026\n\n", $oldest, $newest);

$cases = array(
    // version,   expected status, why this case exists
    array('8.5.10', 'good', 'the newest release; this is the one that was being flagged'),
    array('9.0.0',  'good', 'a branch that does not exist yet must not become a warning'),
    array('8.4.1',  'good', 'newest branch actually in the table'),
    array('8.2.0',  'good', 'listed and still supported on this date'),
    array('8.1.31', 'bad',  'listed and past its end-of-life date'),
    array('8.0.30', 'bad',  'listed and long past it'),
    array('7.4.33', 'bad',  'listed and long past it'),
    array('7.3.33', 'bad',  'older than the table starts, so certainly unsupported'),
    array('5.6.40', 'bad',  'ancient'),
);

foreach ($cases as $case) {
    list($version, $expected, $why) = $case;
    $got = ccm_tools_php_branch_status($version, $now);
    check(
        sprintf('%-8s -> %-4s   %s', $version, $got['status'], $why),
        $got['status'] === $expected,
        sprintf('expected %s', $expected)
    );
}

printf("\n");

// The thing that actually went wrong, stated as its own rule.
$newest_real = ccm_tools_php_branch_status($newest . '.0', $now);
$beyond      = ccm_tools_php_branch_status('99.0.0', $now);
check(
    'nothing newer than the table is ever a warning',
    $beyond['status'] !== 'warn' && $newest_real['status'] !== 'warn',
    'a site is being marked down for running a current PHP'
);

check(
    'the detail never contradicts the status',
    stripos(ccm_tools_php_branch_status('8.5.10', $now)['detail'], 'no longer receiving') === false,
    'a good status must not carry an end-of-life sentence'
);

// The table is only useful while it is ahead of reality.
check(
    sprintf('the table reaches past today (%s ends %s)', $newest, $eol[$newest]),
    strtotime($eol[$newest]) > time(),
    'every branch listed has reached end of life; the table needs extending'
);

printf("\n");
if ($failures) {
    printf("%d check(s) failed\n", $failures);
    exit(1);
}
printf("a supported PHP reads as supported, and a newer one is not a fault\n");
