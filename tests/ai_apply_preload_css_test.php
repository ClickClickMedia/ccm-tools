<?php
/**
 * Regression test for ccm_tools_ai_hub_apply_recommendations()'s preload_css
 * site-safety guard.
 *
 * Bug: the preload_css precondition checked critical_css_code for non-empty
 * BEFORE sanitization. wp_strip_all_tags() (run during sanitization) deletes
 * the entire content of a "<style>...</style>" payload, so an AI
 * recommendation like critical_css_code: "<style>.hero{color:red}</style>"
 * would pass the upfront check, satisfy preload_css's precondition, then
 * sanitize down to '' when stored — leaving preload_css=true with EMPTY
 * critical CSS on a live site (invisible above-the-fold content until the
 * full stylesheet loads).
 *
 * Fix: after all sanitization has run, an explicit guard forces
 * preload_css=false whenever the FINAL (sanitized) critical_css_code is
 * empty — mirroring how critical_css is already forced off in the existing
 * self-heal loop, which never covered preload_css.
 *
 * This harness loads and exercises the REAL ccm_tools_ai_hub_apply_recommendations()
 * from inc/ai-hub.php by stubbing the WordPress functions it calls, rather
 * than copying the function's logic (which would drift from the source of
 * truth). It does not use a WordPress installation.
 */

// ─── Minimal WP stubs, defined BEFORE the guarded file is loaded ──────────

define('ABSPATH', __DIR__ . '/');

// In-memory options store, standing in for wp_options.
$GLOBALS['__test_options'] = [];

function update_option($name, $value, $autoload = null) {
    $GLOBALS['__test_options'][$name] = $value;
    return true;
}

function get_option($name, $default = false) {
    return $GLOBALS['__test_options'][$name] ?? $default;
}

// In-memory Performance Optimizer settings store (mirrors
// inc/performance-optimizer.php's ccm_tools_perf_get_settings/save_settings,
// stubbed per the task instructions rather than loading that whole file and
// its own WP dependencies).
$GLOBALS['__test_perf_settings'] = null;

function ccm_tools_perf_get_settings() {
    if ($GLOBALS['__test_perf_settings'] === null) {
        $GLOBALS['__test_perf_settings'] = [
            'enabled'             => false,
            'critical_css'        => false,
            'critical_css_code'   => '',
            'preload_css'         => false,
            'preload_css_excludes' => [],
            'preconnect'          => false,
            'preconnect_urls'     => [],
            'dns_prefetch'        => false,
            'dns_prefetch_urls'   => [],
            'lcp_preload'         => false,
            'lcp_preload_url'     => '',
            'preload_key_requests'      => false,
            'preload_key_urls'          => [],
            'delay_third_party'         => false,
            'delay_third_party_domains' => [],
            'priority_hints_above_fold' => false,
            'priority_hints_selectors'  => '',
            'lazy_load_images'   => false,
            'defer_js_excludes'  => [],
            'delay_js_excludes'  => [],
        ];
    }
    return $GLOBALS['__test_perf_settings'];
}

function ccm_tools_perf_save_settings($settings) {
    $GLOBALS['__test_perf_settings'] = $settings;
    return update_option('ccm_tools_perf_settings', $settings);
}

// error_log() is a real PHP built-in, not a WP shim — it cannot be
// redeclared. Redirect its destination to a scratch file instead, so the
// refusal messages the target function logs don't clutter test stdout/stderr,
// while remaining readable afterward for an optional content check.
$__test_error_log_file = tempnam(sys_get_temp_dir(), 'ccm_tools_test_error_log_');
ini_set('error_log', $__test_error_log_file);

function read_test_error_log(): string {
    global $__test_error_log_file;
    return (string) @file_get_contents($__test_error_log_file);
}

// WordPress hook registration used at module-load time in ai-hub.php
// (add_action(...) calls at file scope). No-op is sufficient: this test
// never fires WP's ajax action hooks, only calls the target function
// directly.
function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
    return true;
}

// wp_strip_all_tags: realistic stand-in for WordPress core's function.
// Deletes the CONTENT of <style>...</style> and <script>...</script> blocks
// entirely (not just the tags), then strips any remaining tags — this is
// exactly the behavior that turns "<style>.hero{}</style>" into "".
function wp_strip_all_tags($string, $remove_breaks = false) {
    $string = (string) $string;
    $string = preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', $string);
    $string = strip_tags($string);
    if ($remove_breaks) {
        $string = preg_replace('/[\r\n\t ]+/', ' ', $string);
    }
    return trim($string);
}

function sanitize_text_field($str) {
    $str = (string) $str;
    $str = strip_tags($str);
    $str = preg_replace('/[\r\n\t]+/', ' ', $str);
    return trim($str);
}

function esc_url_raw($url) {
    return filter_var($url, FILTER_SANITIZE_URL) ?: '';
}

function absint($n) {
    return abs((int) $n);
}

// Only referenced via function_exists()/class_exists() guards in the target
// function (wp_is_block_theme, WooCommerce) — neither key is exercised by
// this test, so no stub is required for them to remain undefined.

// ─── Load the REAL function from the real file ────────────────────────────

require __DIR__ . '/../inc/ai-hub.php';

// ─── Test harness ──────────────────────────────────────────────────────────

$failures = 0;

function reset_perf_settings() {
    $GLOBALS['__test_perf_settings'] = null;
    $GLOBALS['__test_options'] = [];
    ccm_tools_perf_get_settings(); // seed defaults
}

function check(bool $cond, string $label): void {
    global $failures;
    echo ($cond ? "PASS" : "FAIL") . " — {$label}\n";
    if (!$cond) { $failures++; }
}

// (a) Malicious/plausible AI payload: a <style> block that survives the
// upfront non-empty precondition check but sanitizes to '' via
// wp_strip_all_tags(). Expect preload_css forced OFF and critical_css_code
// stored as ''.
reset_perf_settings();
$result_a = ccm_tools_ai_hub_apply_recommendations([
    ['setting_key' => 'critical_css_code', 'recommended_value' => '<style>.hero{color:red}</style>'],
    ['setting_key' => 'preload_css',       'recommended_value' => true],
]);
$saved_a = get_option('ccm_tools_perf_settings');
check($result_a === true, '(a) apply_recommendations() returns true (something changed)');
check($saved_a['preload_css'] === false, '(a) preload_css saved as false after critical CSS sanitized to empty');
check($saved_a['critical_css_code'] === '', "(a) critical_css_code saved as '' (got: " . var_export($saved_a['critical_css_code'] ?? null, true) . ')');
check($saved_a['critical_css'] === false, '(a) critical_css also forced off (existing self-heal still intact)');

// (b) Plain CSS that survives sanitization unchanged. Expect preload_css
// saved true and critical_css_code non-empty (no regression from the fix).
reset_perf_settings();
$result_b = ccm_tools_ai_hub_apply_recommendations([
    ['setting_key' => 'critical_css_code', 'recommended_value' => '.hero{color:red}'],
    ['setting_key' => 'preload_css',       'recommended_value' => true],
]);
$saved_b = get_option('ccm_tools_perf_settings');
check($result_b === true, '(b) apply_recommendations() returns true');
check($saved_b['preload_css'] === true, '(b) preload_css saved true when critical_css_code survives sanitization');
check(is_string($saved_b['critical_css_code']) && $saved_b['critical_css_code'] !== '', "(b) critical_css_code saved non-empty (got: " . var_export($saved_b['critical_css_code'] ?? null, true) . ')');

// (c) No-regression check: a plain boolean toggle unrelated to
// critical_css_code/preload_css still applies normally.
reset_perf_settings();
$result_c = ccm_tools_ai_hub_apply_recommendations([
    ['setting_key' => 'lazy_load_images', 'recommended_value' => true],
]);
$saved_c = get_option('ccm_tools_perf_settings');
check($result_c === true, '(c) apply_recommendations() returns true');
check($saved_c['lazy_load_images'] === true, '(c) lazy_load_images saved true (plain toggle unaffected by the fix)');

echo "\n" . ($failures === 0 ? "ALL PASS" : "{$failures} FAILURE(S)") . "\n";
exit($failures === 0 ? 0 : 1);
