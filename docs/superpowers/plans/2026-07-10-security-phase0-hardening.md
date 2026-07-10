# Security + Phase 0 Hardening — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the live security exposures in the CCM hub + plugin, and make AI performance recommendations track the real PageSpeed report again on a current model with a validated output contract — without rebuilding the optimize loop (that is unit C).

**Architecture:** Two repos, both on branch `harden/security-phase0`. Hub (`ccm-api-hub`, PHP, deployed on Ignea via `git pull` webhook) gets a shared SSRF guard, secret/`.git`/CSRF fixes, LH13 insight ingestion, Opus 4.8 + Structured Outputs, and server-side recommendation validation. Plugin (`ccm-tools`, WordPress) gets the Redis-config RCE fix, a mirrored allow-list, and two interim JS guardrails. Ship security first, then AI correctness + safety.

**Tech Stack:** PHP 8.4 (local CLI available for pure-function tests), WordPress, raw cURL to Anthropic Messages API, Google PageSpeed Insights v5 (Lighthouse 13), MySQL/PDO.

## Global Constraints

- Branch: `harden/security-phase0` in BOTH repos. No commits to `main`; no unattended prod deploys or credential rotation.
- Model: `claude-opus-4-8`. Do NOT send `temperature`/`top_p`/`top_k` (Opus 4.8 rejects them).
- Anthropic API version header stays `anthropic-version: 2023-06-01`; add `output_config` for Structured Outputs.
- No new runtime dependencies (no Composer packages) — hub has none and must stay installable via `git pull`.
- Pure-function tests live in `<repo>/tests/*.php`, run with local `php`, guarded by `tests/.htaccess` deny. WP/DB/prod-coupled changes are verified by curl/CLI, not unit tests.
- Every setting key the AI can emit MUST exist in the plugin `$defaults` (verify against `inc/performance-optimizer.php`).
- Preserve existing code style (procedural PHP, `appLog()`, `jsonError()`, `Settings::get()`, `dbFetchOne()`).

---

# PHASE 1 — Security (ship first)

## Task 1: SSRF guard helper (hub)

**Files:**
- Create: `ccm-api-hub/includes/url-guard.php`
- Create: `ccm-api-hub/tests/url_guard_test.php`

**Interfaces:**
- Produces: `assertSafeExternalUrl(string $url, ?string $mustMatchSite = null): array` returning `['ok'=>bool, 'url'=>string, 'error'=>?string]`. Consumed by Tasks 2, 6, 7.
- Consumes: existing `urlMatchesSite(string $url, string $siteUrl): bool` (already in the hub — used by `pagespeed-test.php`).

- [ ] **Step 1: Write the failing test**

```php
// ccm-api-hub/tests/url_guard_test.php
<?php
require __DIR__ . '/../includes/url-guard.php';

function check($label, $cond) {
    echo ($cond ? "PASS" : "FAIL") . " — $label\n";
    if (!$cond) { $GLOBALS['failed'] = true; }
}

// Accept public https
check('public https ok', assertSafeExternalUrl('https://example.com/page')['ok'] === true);
// Reject file://
check('file:// rejected', assertSafeExternalUrl('file:///etc/passwd')['ok'] === false);
// Reject loopback
check('127.0.0.1 rejected', assertSafeExternalUrl('http://127.0.0.1/')['ok'] === false);
// Reject cloud metadata
check('169.254.169.254 rejected', assertSafeExternalUrl('http://169.254.169.254/latest/meta-data/')['ok'] === false);
// Reject private ranges
check('10.x rejected', assertSafeExternalUrl('http://10.0.0.5/')['ok'] === false);
check('192.168 rejected', assertSafeExternalUrl('http://192.168.1.1/')['ok'] === false);
check('localhost name rejected', assertSafeExternalUrl('http://localhost/')['ok'] === false);
// Reject non-http scheme
check('gopher rejected', assertSafeExternalUrl('gopher://x/')['ok'] === false);
// Site-binding
check('site match ok', assertSafeExternalUrl('https://client.com/x', 'https://client.com')['ok'] === true);
check('site mismatch rejected', assertSafeExternalUrl('https://evil.com/x', 'https://client.com')['ok'] === false);

echo empty($GLOBALS['failed']) ? "\nALL PASS\n" : "\nFAILURES\n";
exit(empty($GLOBALS['failed']) ? 0 : 1);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php "g:/My Drive/Github/ccm-api-hub/tests/url_guard_test.php"`
Expected: FAIL — fatal "Call to undefined function assertSafeExternalUrl".

- [ ] **Step 3: Write the implementation**

```php
// ccm-api-hub/includes/url-guard.php
<?php
/**
 * SSRF guard for all server-side URL fetches.
 * @package CCM_API_Hub
 */

function ccm_ip_is_blocked(string $ip): bool {
    // Reject anything not a normal public unicast address.
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
        return false; // public — allowed
    }
    return true; // private, reserved, loopback, link-local, etc. — blocked
}

/**
 * Validate a caller-supplied URL is safe to fetch server-side.
 * Blocks non-http(s) schemes, private/reserved/link-local IPs, and
 * (optionally) URLs whose host does not belong to the authenticated site.
 * DNS-rebinding safe: every resolved A/AAAA record is checked.
 */
function assertSafeExternalUrl(string $url, ?string $mustMatchSite = null): array {
    $fail = fn(string $m) => ['ok' => false, 'url' => $url, 'error' => $m];

    $parts = parse_url($url);
    if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
        return $fail('Malformed URL');
    }
    $scheme = strtolower($parts['scheme']);
    if ($scheme !== 'http' && $scheme !== 'https') {
        return $fail('Only http/https allowed');
    }

    $host = $parts['host'];

    // If host is a literal IP, check it directly.
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        if (ccm_ip_is_blocked($host)) return $fail('Blocked IP range');
    } else {
        // Reject obvious internal names outright.
        if (strcasecmp($host, 'localhost') === 0 || substr($host, -6) === '.local') {
            return $fail('Blocked host');
        }
        // Resolve and check EVERY address (v4 + v6).
        $ips = [];
        $a  = @dns_get_record($host, DNS_A);
        $aaaa = @dns_get_record($host, DNS_AAAA);
        foreach ((array)$a as $r)    { if (!empty($r['ip']))   $ips[] = $r['ip']; }
        foreach ((array)$aaaa as $r) { if (!empty($r['ipv6'])) $ips[] = $r['ipv6']; }
        if (empty($ips)) {
            $h = @gethostbynamel($host);
            if ($h) $ips = $h;
        }
        if (empty($ips)) return $fail('Host does not resolve');
        foreach ($ips as $ip) {
            if (ccm_ip_is_blocked($ip)) return $fail('Host resolves to a blocked IP');
        }
    }

    if ($mustMatchSite !== null) {
        if (!function_exists('urlMatchesSite') || !urlMatchesSite($url, $mustMatchSite)) {
            return $fail('URL does not belong to the authenticated site');
        }
    }

    return ['ok' => true, 'url' => $url, 'error' => null];
}

/**
 * cURL options that keep a fetch on http/https only (no file://, no gopher).
 * Merge into curl_setopt_array for every guarded fetch.
 */
function ccm_safe_curl_opts(): array {
    return [
        CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
    ];
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php "g:/My Drive/Github/ccm-api-hub/tests/url_guard_test.php"`
Expected: `ALL PASS` (exit 0). Note: the DNS-based cases need network; if offline, the literal-IP and scheme cases still pass.

- [ ] **Step 5: Commit**

```bash
cd "g:/My Drive/Github/ccm-api-hub"
git add includes/url-guard.php tests/url_guard_test.php tests/.htaccess
git commit -m "Add SSRF guard helper (assertSafeExternalUrl) + tests"
```

---

## Task 2: Apply SSRF guard to page-analyzer fetches (hub)

**Files:**
- Modify: `ccm-api-hub/includes/page-analyzer.php:19-53` (page fetch) and the `fetchMainCssContent` cURL (~`:302-311`).

**Interfaces:**
- Consumes: `assertSafeExternalUrl()`, `ccm_safe_curl_opts()` (Task 1).

- [ ] **Step 1: Require the guard at the top of the file**

After the opening docblock (top of `page-analyzer.php`), add:
```php
require_once __DIR__ . '/url-guard.php';
```

- [ ] **Step 2: Guard the page fetch**

In `fetchPageResources()`, replace the `$ch = curl_init($url);` block (currently `:33-42`) so it validates first and adds safe protocols:
```php
    $safe = assertSafeExternalUrl($url);
    if (!$safe['ok']) {
        $result['error'] = 'Blocked: ' . $safe['error'];
        appLog("Page fetch blocked for {$url}: " . $safe['error'], 'warning');
        return $result;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, ccm_safe_curl_opts() + [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_USERAGENT      => 'CCM-Tools-Analyzer/1.0 (Performance Audit)',
        CURLOPT_HTTPHEADER     => ['Accept: text/html'],
        CURLOPT_ENCODING       => '',
    ]);
```

- [ ] **Step 3: Guard the CSS fetch**

In `fetchMainCssContent()`, before each stylesheet `curl_init(...)`/`curl_setopt_array`, add the same `assertSafeExternalUrl($cssUrl)` check (skip that stylesheet on failure) and merge `ccm_safe_curl_opts()` into its options.

- [ ] **Step 4: Verify (lint + local smoke)**

Run: `php -l "g:/My Drive/Github/ccm-api-hub/includes/page-analyzer.php"`
Expected: `No syntax errors detected`.
Then a local smoke test:
```bash
php -r "require 'g:/My Drive/Github/ccm-api-hub/includes/url-guard.php'; var_dump(assertSafeExternalUrl('file:///etc/passwd')['ok']);"
```
Expected: `bool(false)`.

- [ ] **Step 5: Commit**

```bash
git add includes/page-analyzer.php
git commit -m "Guard page-analyzer fetches with SSRF check + protocol allow-list"
```

---

## Task 3: Bind ai-chat / screenshot / console / visual-compare to the guard (hub)

**Files:**
- Modify: `ccm-api-hub/api/v1/ai-chat.php:32,47-48`
- Modify: `ccm-api-hub/api/v1/screenshot-capture.php:38-44`
- Modify: `ccm-api-hub/api/v1/console-check.php:32-39`
- Modify: `ccm-api-hub/api/v1/ai-visual-compare.php:41-49,109-118`

**Interfaces:**
- Consumes: `assertSafeExternalUrl()` (Task 1).

- [ ] **Step 1: ai-chat.php — bind site_url to the authenticated site**

After `$siteUrl = $input['site_url'] ?? ($site['site_url'] ?? '');` (`:32`), before the fetch at `:47`, add:
```php
if (!empty($siteUrl)) {
    $chk = assertSafeExternalUrl($siteUrl, $site['site_url'] ?? null);
    if (!$chk['ok']) { jsonError('Invalid site_url: ' . $chk['error'], 400); }
}
```
Add `require_once __DIR__ . '/../../includes/url-guard.php';` near the top if the endpoint doesn't already load includes globally (check the bootstrap in `api/v1/index.php` — if includes are auto-loaded there, add the require there once instead).

- [ ] **Step 2: screenshot-capture.php — validate before Chromium, no file://**

Replace the `filter_var($url, FILTER_VALIDATE_URL)`-only check (`:38-44`) with:
```php
$chk = assertSafeExternalUrl($url, $site['site_url'] ?? null);
if (!$chk['ok']) { jsonError('Invalid url: ' . $chk['error'], 400); }
```

- [ ] **Step 3: console-check.php — same guard (`:32-39`)**

```php
$chk = assertSafeExternalUrl($url, $site['site_url'] ?? null);
if (!$chk['ok']) { jsonError('Invalid url: ' . $chk['error'], 400); }
```

- [ ] **Step 4: ai-visual-compare.php — guard each before/after URL**

Before each `fetchImageAsBase64($someUrl)` call (`:109-118`), guard it (these are hub-hosted screenshot URLs; no site binding, but block internal/file):
```php
foreach (['before_desktop_url','after_desktop_url','before_mobile_url','after_mobile_url'] as $k) {
    if (!empty($input[$k])) {
        $c = assertSafeExternalUrl($input[$k]);
        if (!$c['ok']) { jsonError("Invalid {$k}: " . $c['error'], 400); }
    }
}
```
Also merge `ccm_safe_curl_opts()` into `fetchImageAsBase64`'s cURL options and set `CURLOPT_SSL_VERIFYPEER => true`.

- [ ] **Step 5: Lint all four + commit**

Run: `php -l` on each of the four files. Expected: `No syntax errors detected` for each.
```bash
git add api/v1/ai-chat.php api/v1/screenshot-capture.php api/v1/console-check.php api/v1/ai-visual-compare.php
git commit -m "SSRF-guard ai-chat/screenshot/console/visual-compare URL inputs"
```

---

## Task 4: Deploy secret to .env + block .git (hub)

**Files:**
- Modify: `ccm-api-hub/deploy.php:24`
- Modify: `ccm-api-hub/.htaccess`
- Modify: `ccm-api-hub/.env.example`

- [ ] **Step 1: Read the secret from env**

Replace `deploy.php:24`:
```php
define('DEPLOY_SECRET', Env::string('DEPLOY_SECRET', ''));
```
Immediately after the `define`s, fail closed if unset:
```php
if (DEPLOY_SECRET === '') { http_response_code(500); deployLog('DEPLOY_SECRET not configured'); exit('not configured'); }
```
Confirm `Env` is loaded in `deploy.php` (it uses `config`/bootstrap). If not, add `require_once __DIR__ . '/config/config.php';` (which defines `Env`) near the top — verify the path against how other entry files bootstrap.

- [ ] **Step 2: Add key to .env.example**

Append to `.env.example`:
```
# GitHub webhook deploy secret (rotate; must match the GitHub webhook Secret)
DEPLOY_SECRET=
```

- [ ] **Step 3: Block .git in .htaccess**

In `.htaccess`, inside the `<IfModule mod_rewrite.c>` block (after the `logs/` rule), add:
```apache
    RewriteRule ^\.git - [F,L]
```

- [ ] **Step 4: Verify**

Run: `php -l "g:/My Drive/Github/ccm-api-hub/deploy.php"`
Expected: `No syntax errors detected`.
(Live `.git` 404 check happens post-deploy — in the manual steps.)

- [ ] **Step 5: Commit**

```bash
git add deploy.php .htaccess .env.example
git commit -m "Move deploy secret to .env (fail-closed) and block .git/ over HTTP"
```

---

## Task 5: Admin CSRF/authz + test-screenshot + lower-severity batch (hub)

**Files:**
- Modify: `ccm-api-hub/admin/subscriptions.php:13,22-61`
- Modify: `ccm-api-hub/test-screenshot.php` (or delete)
- Modify: `ccm-api-hub/includes/api-auth.php` consumers (`screenshot-capture.php:31`, `screenshot-history.php:23`)
- Modify: `ccm-api-hub/api/v1/premium-register-site.php:29`
- Modify: `ccm-api-hub/admin/sites.php:263`

- [ ] **Step 1: subscriptions.php — add CSRF + role gate**

At the top of the POST handler (after `requireLogin()`, `:13`), add `requireManager();`. Inside the `grant_premium`/`revoke_premium` branch (`:22-61`), add `requireCsrf();` as the first line. Add `<input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">` to each form (match the field name/help used by `sites.php`).

- [ ] **Step 2: test-screenshot.php — remove from prod**

Delete the file:
```bash
git rm "g:/My Drive/Github/ccm-api-hub/test-screenshot.php"
```
(If you prefer to keep it, instead wrap the whole body in `requireAdmin();`, delete the `error_reporting(E_ALL)`/`ini_set('display_errors','1')` lines, and guard `$_GET['url']` with `assertSafeExternalUrl`. Deletion is recommended and simpler.)

- [ ] **Step 3: Fix the no-op feature check**

In `screenshot-capture.php:31` and `screenshot-history.php:23`, change `checkFeatureAccess($site, 'ai_enabled')` → `checkFeatureAccess($site, 'ai')`.

- [ ] **Step 4: Constant-time admin-key compare**

`premium-register-site.php:29` — replace `$adminKey !== APP_SECRET_KEY` with `!hash_equals(APP_SECRET_KEY, (string)$adminKey)`.

- [ ] **Step 5: Stop serializing api_key_hash into the DOM**

`admin/sites.php:263` — build a whitelisted array for `editSite()` (e.g. `id, site_url, site_name, plan, status`) and `json_encode` that instead of the whole `$site` row.

- [ ] **Step 6: Verify + commit**

Run `php -l` on each modified file. Expected `No syntax errors detected`.
```bash
git add admin/subscriptions.php includes/api-auth.php api/v1/screenshot-capture.php api/v1/screenshot-history.php api/v1/premium-register-site.php admin/sites.php
git rm test-screenshot.php 2>/dev/null; true
git commit -m "Harden admin: CSRF+authz on subscriptions, remove test-screenshot, fix feature check, hash_equals, drop api_key_hash from DOM"
```

---

## Task 6: Plugin Redis-config RCE fix + safe backups (plugin)

**Files:**
- Modify: `ccm-tools/inc/redis-object-cache.php:1300` (config line builder), `:1348-1351,836-839` (backup paths)
- Modify: `ccm-tools/inc/ajax-handlers.php:3267,3303` (password/username input validation)
- Create: `ccm-tools/tests/redis_config_line_test.php`

**Interfaces:**
- Produces: config lines that are injection-proof regardless of value content.

- [ ] **Step 1: Write the failing test (pure string builder)**

Extract the line-building into a testable pure helper. First the test:
```php
// ccm-tools/tests/redis_config_line_test.php
<?php
require __DIR__ . '/redis_config_line_helper.php'; // temporary shim; see Step 3

$evil = "x'); eval(\$_POST['c']); //";
$line = ccm_tools_redis_config_line('WP_REDIS_PASSWORD', $evil);

// The value must be a single safe PHP string literal — no statement break.
$ok = (substr_count($line, ';') === 1) && (strpos($line, 'eval') !== false ? strpos($line, "'") !== false : true);
echo ($ok ? "PASS" : "FAIL") . " — no statement injection\n";

// Round-trip: eval the define in an isolated scope and read it back.
$code = str_replace('define(', 'define(', $line);
eval($code);
echo (WP_REDIS_PASSWORD === $evil ? "PASS" : "FAIL") . " — value preserved exactly\n";
exit(0);
```

- [ ] **Step 2: Run to verify it fails**

Run: `php "g:/My Drive/Github/ccm-tools/tests/redis_config_line_test.php"`
Expected: FAIL — undefined function `ccm_tools_redis_config_line`.

- [ ] **Step 3: Add the pure helper and use `var_export`**

In `inc/redis-object-cache.php`, add near the other config helpers:
```php
/**
 * Build a single wp-config define() line with an injection-proof value literal.
 */
function ccm_tools_redis_config_line(string $constant, $value): string {
    return "define('" . $constant . "', " . var_export($value, true) . ");";
}
```
Then at `:1300`, replace:
```php
$config_lines[] = "define('{$constant}', '{$value}');";
```
with:
```php
$config_lines[] = ccm_tools_redis_config_line($constant, $value);
```
(Also update the boolean/numeric branch nearby to route through the same helper so all values are `var_export`-safe.)
For the test shim, create `tests/redis_config_line_helper.php` containing a **verbatim copy** of the `ccm_tools_redis_config_line()` function body only (no `ABSPATH` guard, no other plugin code) so `php` can run the test without booting WordPress:
```php
// ccm-tools/tests/redis_config_line_helper.php
<?php
function ccm_tools_redis_config_line(string $constant, $value): string {
    return "define('" . $constant . "', " . var_export($value, true) . ");";
}
```

- [ ] **Step 4: Reject quotes/control chars at input**

In `inc/ajax-handlers.php`, where `password` (`:3267`) and `username` (`:3303`) are read, add validation mirroring the existing `host` check:
```php
$raw_pw = (string) wp_unslash($_POST['password'] ?? '');
if ($raw_pw !== '' && preg_match('/[\'"\\\\\x00-\x1F]/', $raw_pw)) {
    wp_send_json_error(['message' => 'Password contains disallowed characters (quotes, backslash, control chars).']);
}
$settings['password'] = $raw_pw;
```
Apply the same pattern to `username` (using `sanitize_text_field` after the quote/control-char rejection).

- [ ] **Step 5: Move backups out of the web root**

In `inc/redis-object-cache.php:1348-1351` and `:836-839`, change the backup target from `ABSPATH`/web root to `wp_upload_dir()['basedir'] . '/ccm-private/'`; create the dir if missing and drop an `.htaccess` (`deny from all`) + empty `index.php` into it on first use.

- [ ] **Step 6: Verify + commit**

Run: `php "g:/My Drive/Github/ccm-tools/tests/redis_config_line_test.php"` → both PASS.
Run: `php -l "g:/My Drive/Github/ccm-tools/inc/redis-object-cache.php"` and `php -l inc/ajax-handlers.php` → no syntax errors.
```bash
cd "g:/My Drive/Github/ccm-tools"
git add inc/redis-object-cache.php inc/ajax-handlers.php tests/redis_config_line_test.php tests/redis_config_line_helper.php
git commit -m "Fix Redis-config RCE via var_export + reject quotes in password/username; move backups out of web root"
```

---

# PHASE 2 — AI correctness + safety (ship second)

## Task 7: LH13 insight-audit ingestion (hub)

**Files:**
- Create: `ccm-api-hub/includes/psi-insights.php`
- Create: `ccm-api-hub/tests/psi_insights_test.php`
- Modify: `ccm-api-hub/api/v1/ai-optimize.php:440-498` (category param + opportunity extraction), `:501-517` (store diagnostics)
- Modify: `ccm-api-hub/api/v1/pagespeed-test.php:158-174`
- Modify: `ccm-api-hub/api/v1/ai-analyze.php:58-132`

**Interfaces:**
- Produces: `extractPsiOpportunities(array $audits): array` (list of `['id','title','savings_ms','settings'=>[...]]`), and `psiSchemaSeen(array $audits): string` returning `'insight'|'legacy'|'none'`. Consumed by the analyze prompt builders.

- [ ] **Step 1: Write the failing test**

```php
// ccm-api-hub/tests/psi_insights_test.php
<?php
require __DIR__ . '/../includes/psi-insights.php';
$fail = false;
$c = function($l,$b) use (&$fail){ echo ($b?"PASS":"FAIL")." — $l\n"; if(!$b)$fail=true; };

// LH13 insight audit with metricSavings
$audits = [
  'render-blocking-insight' => ['title'=>'Render blocking requests','metricSavings'=>['LCP'=>320,'FCP'=>210],'details'=>['items'=>[]]],
  'uses-long-cache-ttl'     => ['title'=>'x'], // not an insight, ignored
];
$opps = extractPsiOpportunities($audits);
$c('picks up render-blocking-insight', count($opps) === 1 && $opps[0]['id'] === 'render-blocking-insight');
$c('savings from metricSavings max', $opps[0]['savings_ms'] === 320);
$c('maps to CCM settings', in_array('preload_css', $opps[0]['settings'], true));
$c('schema detected as insight', psiSchemaSeen($audits) === 'insight');

// Legacy fallback
$legacy = ['render-blocking-resources'=>['title'=>'x','details'=>['overallSavingsMs'=>150]]];
$lo = extractPsiOpportunities($legacy);
$c('legacy still parsed', count($lo) === 1 && $lo[0]['savings_ms'] === 150);
$c('schema detected as legacy', psiSchemaSeen($legacy) === 'legacy');

echo $fail ? "\nFAILURES\n" : "\nALL PASS\n"; exit($fail?1:0);
```

- [ ] **Step 2: Run to verify it fails**

Run: `php "g:/My Drive/Github/ccm-api-hub/tests/psi_insights_test.php"`
Expected: FAIL — undefined function.

- [ ] **Step 3: Implement the parser + mapping**

```php
// ccm-api-hub/includes/psi-insights.php
<?php
/** PageSpeed Insights (Lighthouse 13) insight-audit extraction. @package CCM_API_Hub */

/** insight audit id => CCM Tools setting keys that address it. */
function ccm_insight_setting_map(): array {
    return [
        'render-blocking-insight'        => ['preload_css','critical_css','defer_js','inline_small_styles'],
        'lcp-discovery-insight'          => ['lcp_preload','preload_css_bg_image','lcp_fetchpriority'],
        'lcp-phases-insight'             => ['lcp_fetchpriority'],
        'image-delivery-insight'         => ['inject_srcset'],
        'third-parties-insight'          => ['delay_third_party'],
        'font-display-insight'           => ['font_display_swap','self_host_google_fonts'],
        'network-dependency-tree-insight'=> ['preconnect','dns_prefetch','preload_key_requests'],
        'modern-http-insight'            => [],
        'use-cache-insight'              => ['cache_control_meta'],
        'dom-size-insight'               => ['warn_dom_size'],
        'duplicated-javascript-insight'  => [],
        'legacy-javascript-insight'      => [],
        'cls-culprits-insight'           => ['inject_image_dimensions'],
        'viewport-insight'               => [],
        'interaction-to-next-paint-insight' => ['passive_event_listeners'],
        'document-latency-insight'       => [],
    ];
}

function ccm_insight_savings_ms(array $audit): int {
    if (isset($audit['metricSavings']) && is_array($audit['metricSavings'])) {
        $vals = array_map('intval', $audit['metricSavings']);
        return $vals ? max($vals) : 0;
    }
    if (isset($audit['details']['overallSavingsMs'])) {
        return (int) $audit['details']['overallSavingsMs'];
    }
    return 0;
}

function psiSchemaSeen(array $audits): string {
    foreach (array_keys(ccm_insight_setting_map()) as $id) {
        if (isset($audits[$id])) return 'insight';
    }
    foreach (['render-blocking-resources','unused-javascript','unused-css-rules'] as $id) {
        if (isset($audits[$id])) return 'legacy';
    }
    return 'none';
}

/** Return a savings-sorted opportunity list with mapped CCM setting hints. */
function extractPsiOpportunities(array $audits): array {
    $map = ccm_insight_setting_map();
    $out = [];

    // Prefer LH13 insight audits.
    foreach ($map as $id => $settings) {
        if (!isset($audits[$id])) continue;
        $ms = ccm_insight_savings_ms($audits[$id]);
        $out[] = [
            'id'         => $id,
            'title'      => $audits[$id]['title'] ?? $id,
            'savings_ms' => $ms,
            'settings'   => $settings,
        ];
    }

    // Legacy fallback (pre-LH13) if no insight audits present.
    if (empty($out)) {
        foreach ($audits as $auditId => $audit) {
            $ms = isset($audit['details']['overallSavingsMs']) ? (int)$audit['details']['overallSavingsMs'] : 0;
            if ($ms > 0) {
                $out[] = ['id'=>$auditId, 'title'=>$audit['title'] ?? $auditId, 'savings_ms'=>$ms, 'settings'=>[]];
            }
        }
    }

    usort($out, fn($a,$b) => $b['savings_ms'] <=> $a['savings_ms']);
    return $out;
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php "g:/My Drive/Github/ccm-api-hub/tests/psi_insights_test.php"`
Expected: `ALL PASS`.

- [ ] **Step 5: Wire into the endpoints**

- `ai-optimize.php`: `require_once __DIR__ . '/../../includes/psi-insights.php';`. Fix the `category` param at `:440-445` — replace the `'category' => [...]` array with a base query then append repeated params:
```php
$psiUrl = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed?' . http_build_query([
    'url' => $url, 'strategy' => $strategy, 'key' => $apiKey,
]) . '&category=performance&category=accessibility&category=best-practices&category=seo';
```
Replace the opportunity loop (`:489-498`) with `$opportunities = extractPsiOpportunities($audits);`. Store `diagnostics` and `opportunities` in the `dbInsert('pagespeed_results', …)` call (`:501-517`) — add `'diagnostics' => json_encode($diagnostics)` and `'opportunities' => json_encode($opportunities)` so the analyze path (which reads them) is populated. Log the schema: `appLog('PSI schema: ' . psiSchemaSeen($audits) . " for {$url}", 'info');`.
- `pagespeed-test.php:158-174`: replace its opportunity loop with `extractPsiOpportunities($audits)` too (it already fixes the category param at `:93-97`).
- `ai-analyze.php:58-132`: where it reads `render-blocking-resources`/`unused-*`/`largest-contentful-paint-element`, add insight-audit equivalents (`render-blocking-insight`, `lcp-phases-insight`) with the legacy read as fallback; if the insight `details.items` shape differs, guard with `?? []`.

- [ ] **Step 6: Lint + commit**

`php -l` each modified file. Expected no syntax errors.
```bash
cd "g:/My Drive/Github/ccm-api-hub"
git add includes/psi-insights.php tests/psi_insights_test.php api/v1/ai-optimize.php api/v1/pagespeed-test.php api/v1/ai-analyze.php
git commit -m "Ingest Lighthouse 13 insight audits (metricSavings) + map to CCM settings; fix category param; store diagnostics"
```

---

## Task 8: Opus 4.8 + Structured Outputs + stop_reason + caching (hub)

**Files:**
- Modify: `ccm-api-hub/api/v1/ai-optimize.php:554-896` (model, request body, parse)
- Modify: `ccm-api-hub/api/v1/ai-analyze.php:353-446`
- Modify: `ccm-api-hub/api/v1/ai-chat.php:235`
- Modify: `ccm-api-hub/config/config.php:96`
- Modify: `ccm-api-hub/includes/page-analyzer.php:437-450` (cost table)
- Create: `ccm-api-hub/includes/ai-recommendation-schema.php`

**Interfaces:**
- Produces: `ccm_recommendation_output_schema(): array` — the `output_config.format` JSON schema with `setting_key` `enum`. Consumed by ai-optimize + ai-analyze.
- Consumes: `ccm_perf_setting_catalog()` (Task 9) for the enum.

> **Ordering dependency:** the schema's `setting_key` enum is `array_keys(ccm_perf_setting_catalog())`, defined in Task 9's `ai-allowlist.php`. **Implement Task 9 before Task 8** (or at minimum create `ai-allowlist.php` first).

- [ ] **Step 1: Model default + cost table**

`config/config.php:96` → default `'claude-opus-4-8'`. `page-analyzer.php:437-450` → add cost entries for `claude-opus-4-8` and `claude-sonnet-5` (use current per-MTok input/output rates; if unknown at build time, set to the Opus 4.x row and add a TODO-free comment noting the source). In `ai-optimize.php:561` and `ai-analyze.php:353`, change the fallback default to `'claude-opus-4-8'`.

- [ ] **Step 2: Build the output schema (depends on Task 9's key list)**

```php
// ccm-api-hub/includes/ai-recommendation-schema.php
<?php
require_once __DIR__ . '/ai-allowlist.php'; // Task 9

function ccm_recommendation_output_schema(): array {
    return [
        'type' => 'json_schema',
        'name' => 'ccm_recommendations',
        'schema' => [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['summary','recommendations'],
            'properties' => [
                'summary' => ['type' => 'string'],
                'priority' => ['type' => 'string'],
                'recommendations' => [
                    'type' => 'array',
                    'maxItems' => 5,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['setting_key','recommended_value','reason','risk'],
                        'properties' => [
                            'setting_key' => ['type' => 'string', 'enum' => array_keys(ccm_perf_setting_catalog())],
                            'recommended_value' => ['type' => ['boolean','integer','string','array']],
                            'reason' => ['type' => 'string'],
                            'risk' => ['type' => 'string', 'enum' => ['low','medium','high']],
                            'related_opportunity' => ['type' => ['string','null']],
                        ],
                    ],
                ],
                'estimated_score_improvement' => ['type' => ['object','null']],
            ],
        ],
    ];
}
```

- [ ] **Step 3: Send the model the schema; drop temperature; handle stop_reason**

In `runAiAnalysis()` (`ai-optimize.php`) and the analyze equivalent, change the request body assembled before the curl POST:
```php
$requestBody = [
    'model'      => $claudeModel,
    'max_tokens' => $maxTokens,
    'system'     => [[
        'type' => 'text',
        'text' => $systemPrompt,
        'cache_control' => ['type' => 'ephemeral'],
    ]],
    'messages'      => [['role' => 'user', 'content' => $userMessage]],
    'output_config' => ['format' => ccm_recommendation_output_schema()],
];
// NOTE: no 'temperature' — Opus 4.8 rejects it.
```
After decoding the API response, before parsing content:
```php
if (($data['stop_reason'] ?? '') === 'max_tokens') {
    appLog('AI response truncated (max_tokens) for site ' . $site['id'], 'error');
    return ['success' => false, 'error' => 'AI response was truncated; try again.'];
}
```
With Structured Outputs the content is guaranteed-valid JSON — replace the regex-fallback block (`:875-896`) with a direct `json_decode($aiText, true)` and treat a decode failure as a hard error (no silent `recommendations: []`).

- [ ] **Step 4: ai-chat.php max_tokens**

`ai-chat.php:235` → `max(Settings::int('claude_max_tokens', 16384), 16384)`.

- [ ] **Step 5: Verify**

`php -l` each modified file → no syntax errors.
`php -r "require 'includes/ai-recommendation-schema.php'; \$s=ccm_recommendation_output_schema(); echo count(\$s['schema']['properties']['recommendations']['items']['properties']['setting_key']['enum']) > 20 ? 'ENUM OK' : 'ENUM EMPTY';"`
Expected: `ENUM OK`.

- [ ] **Step 6: Commit**

```bash
git add config/config.php includes/ai-recommendation-schema.php includes/page-analyzer.php api/v1/ai-optimize.php api/v1/ai-analyze.php api/v1/ai-chat.php
git commit -m "Opus 4.8 + Structured Outputs + stop_reason handling + prompt caching; drop temperature"
```

---

## Task 9: Server-side allow-list catalog + validation (hub + plugin)

**Files:**
- Create: `ccm-api-hub/includes/ai-allowlist.php`
- Create: `ccm-api-hub/tests/ai_allowlist_test.php`
- Modify: `ccm-api-hub/api/v1/ai-optimize.php`, `ai-analyze.php` (validate before returning)
- Modify: `ccm-tools/inc/ai-hub.php:524-635` (mirror preconditions in `ccm_tools_ai_hub_apply_recommendations`)

**Interfaces:**
- Produces: `ccm_perf_setting_catalog(): array` (key => `['type','tier','requires'=>[],'incompatible'=>[]]`); `ccm_validate_recommendations(array $recs, array $siteFlags): array` returning `['valid'=>[], 'rejected'=>[]]`. Consumed by Task 8 (enum) and the endpoints.

- [ ] **Step 1: Write the failing test**

```php
// ccm-api-hub/tests/ai_allowlist_test.php
<?php
require __DIR__ . '/../includes/ai-allowlist.php';
$fail=false; $c=function($l,$b)use(&$fail){echo($b?"PASS":"FAIL")." — $l\n"; if(!$b)$fail=true;};

$c('catalog has real keys', array_key_exists('lazy_load_images', ccm_perf_setting_catalog()));
$c('catalog excludes hallucinated key', !array_key_exists('remove_wp_embed', ccm_perf_setting_catalog()));

// preload_css requires critical_css_code
$recs = [['setting_key'=>'preload_css','recommended_value'=>true]];
$r = ccm_validate_recommendations($recs, ['is_block_theme'=>false,'woo'=>false]);
$c('preload_css without critical CSS rejected', count($r['valid'])===0 && count($r['rejected'])===1);

$recs2 = [
  ['setting_key'=>'critical_css_code','recommended_value'=>'.h{color:red}'],
  ['setting_key'=>'preload_css','recommended_value'=>true],
];
$r2 = ccm_validate_recommendations($recs2, ['is_block_theme'=>false,'woo'=>false]);
$c('preload_css WITH critical CSS allowed', count($r2['valid'])===2);

// block theme blocks disable_block_css
$r3 = ccm_validate_recommendations([['setting_key'=>'disable_block_css','recommended_value'=>true]], ['is_block_theme'=>true,'woo'=>false]);
$c('disable_block_css blocked on block theme', count($r3['valid'])===0);

// unknown key rejected
$r4 = ccm_validate_recommendations([['setting_key'=>'made_up','recommended_value'=>1]], ['is_block_theme'=>false,'woo'=>false]);
$c('unknown key rejected', count($r4['rejected'])===1);

// type mismatch rejected (bool setting given string "false")
$r5 = ccm_validate_recommendations([['setting_key'=>'lazy_load_images','recommended_value'=>'false']], ['is_block_theme'=>false,'woo'=>false]);
$c('string-bool mismatch rejected', count($r5['valid'])===0);

echo $fail?"\nFAILURES\n":"\nALL PASS\n"; exit($fail?1:0);
```

- [ ] **Step 2: Run to verify it fails**

Run: `php "g:/My Drive/Github/ccm-api-hub/tests/ai_allowlist_test.php"` → FAIL (undefined function).

- [ ] **Step 3a: Enumerate the real setting keys**

Run `grep -nE "^\s*'[a-z_]+'\s*=>" "g:/My Drive/Github/ccm-tools/inc/performance-optimizer.php"` within the `$defaults` array to list every key and its default's PHP type. Every one of these keys MUST appear in the catalog below (the enum in Task 8 is derived from it — a missing key means the AI can never recommend it). This is a mechanical transcription, not a judgement call.

- [ ] **Step 3b: Implement the catalog + validator**

Build `ccm_perf_setting_catalog()` from the keys enumerated in 3a (bool/int/string/array per key; tier `safe`/`assisted`; `requires`/`incompatible`). The block below shows the structure and the risky-key entries that the tests and preconditions depend on; **the final catalog must contain every key from 3a**, not just these:
```php
// ccm-api-hub/includes/ai-allowlist.php
<?php
/** Typed allow-list of CCM Tools performance settings. @package CCM_API_Hub */

function ccm_perf_setting_catalog(): array {
    $b = fn($tier, $req=[], $inc=[]) => ['type'=>'bool','tier'=>$tier,'requires'=>$req,'incompatible'=>$inc];
    return [
        // safe tier (examples — fill from plugin $defaults)
        'lazy_load_images'        => $b('safe'),
        'image_decoding_async'    => $b('safe'),
        'inject_image_dimensions' => $b('safe'),
        'inject_srcset'           => $b('safe'),
        'preconnect'              => $b('safe'),
        'dns_prefetch'            => $b('safe'),
        'font_display_swap'       => $b('safe'),
        'disable_emoji'           => $b('safe'),
        // assisted tier (risky — gated)
        'preload_css'             => $b('assisted', ['critical_css_code']),
        'critical_css'            => $b('assisted', ['critical_css_code']),
        'critical_css_code'       => ['type'=>'string','tier'=>'assisted','requires'=>[],'incompatible'=>[]],
        'delay_js'                => $b('assisted'),
        'passive_event_listeners' => $b('assisted'),
        'disable_block_css'          => ['type'=>'bool','tier'=>'assisted','requires'=>[],'incompatible'=>['is_block_theme']],
        'disable_gutenberg_frontend' => ['type'=>'bool','tier'=>'assisted','requires'=>[],'incompatible'=>['is_block_theme']],
        'woo_scripts_shop_only'   => ['type'=>'bool','tier'=>'assisted','requires'=>['woo'],'incompatible'=>[]],
        'disable_wp_cron'         => $b('assisted'),
        'preload_key_requests'    => $b('safe'),
        'preload_key_urls'        => ['type'=>'array','tier'=>'safe','requires'=>[],'incompatible'=>[]],
        'delay_third_party'       => $b('assisted'),
        'delay_third_party_domains'=>['type'=>'array','tier'=>'assisted','requires'=>[],'incompatible'=>[]],
        // ... complete from inc/performance-optimizer.php $defaults
    ];
}

function ccm_value_matches_type($value, string $type): bool {
    switch ($type) {
        case 'bool':   return is_bool($value);
        case 'int':    return is_int($value);
        case 'string': return is_string($value);
        case 'array':  return is_array($value);
    }
    return false;
}

/**
 * Validate model recommendations against the catalog + site flags.
 * $siteFlags: ['is_block_theme'=>bool, 'woo'=>bool].
 */
function ccm_validate_recommendations(array $recs, array $siteFlags): array {
    $cat = ccm_perf_setting_catalog();
    $valid = []; $rejected = [];
    // Index proposed values so a requires-key present in THIS batch counts.
    $proposed = [];
    foreach ($recs as $r) { if (!empty($r['setting_key'])) $proposed[$r['setting_key']] = $r['recommended_value'] ?? null; }

    foreach ($recs as $r) {
        $k = $r['setting_key'] ?? '';
        $v = $r['recommended_value'] ?? null;
        $why = null;
        if (!isset($cat[$k]))                         $why = 'unknown key';
        elseif (!ccm_value_matches_type($v, $cat[$k]['type'])) $why = 'type mismatch';
        else {
            foreach ($cat[$k]['requires'] as $req) {
                $present = !empty($proposed[$req]) || !empty($siteFlags[$req]);
                if (!$present) { $why = "requires {$req}"; break; }
            }
            if (!$why) foreach ($cat[$k]['incompatible'] as $bad) {
                if (!empty($siteFlags[$bad])) { $why = "incompatible with {$bad}"; break; }
            }
        }
        if ($why) $rejected[] = ['setting_key'=>$k,'reason'=>$why];
        else      $valid[]    = $r;
    }
    return ['valid'=>$valid, 'rejected'=>$rejected];
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php "g:/My Drive/Github/ccm-api-hub/tests/ai_allowlist_test.php"` → `ALL PASS`.

- [ ] **Step 5: Enforce in the hub endpoints**

In `ai-optimize.php`/`ai-analyze.php`, after parsing the model output and before returning, compute `$siteFlags` (pass `is_block_theme`/`woo` from the plugin `context`/`current_settings`; default false), run `ccm_validate_recommendations`, return only `valid`, and include `rejected` in the response for logging.

- [ ] **Step 6: Mirror preconditions in the plugin apply path**

In `ccm-tools/inc/ai-hub.php` `ccm_tools_ai_hub_apply_recommendations()` (`:524-635`), before applying each rec, enforce: `preload_css`/`critical_css` require non-empty `critical_css_code` (present in the batch or already in settings); skip `disable_block_css`/`disable_gutenberg_frontend` when `wp_is_block_theme()`; skip `woo_scripts_shop_only` unless `class_exists('WooCommerce')`; reject values whose PHP type doesn't match the existing setting type instead of coercing (removes the `(bool)"false"` trap at `:562-563`). Log each skip with `error_log`.

- [ ] **Step 7: Verify + commit (both repos)**

`php -l` all modified files.
```bash
cd "g:/My Drive/Github/ccm-api-hub"
git add includes/ai-allowlist.php tests/ai_allowlist_test.php api/v1/ai-optimize.php api/v1/ai-analyze.php
git commit -m "Add typed AI recommendation allow-list + preconditions; enforce server-side"
cd "g:/My Drive/Github/ccm-tools"
git add inc/ai-hub.php
git commit -m "Mirror AI allow-list preconditions in plugin apply path; reject type mismatches"
```

---

## Task 10: Prompt catalog fixes (hub)

**Files:**
- Modify: `ccm-api-hub/includes/prompts.php`

- [ ] **Step 1: Fix wrong setting-key names**

Global replace within `prompts.php`: `remove_wp_embed`→`disable_wp_embed`, `inject_responsive_srcset`→`inject_srcset`, `disable_admin_bar_frontend`→`disable_admin_bar`, `remove_adjacent_posts`→`remove_adjacent_post_links`.

- [ ] **Step 2: Fix value-type docs**

Where the prompt describes `preload_key_urls` and `delay_third_party_domains` as "string, newline-separated", change to "array of strings".

- [ ] **Step 3: Verify + commit**

Run: `grep -n "remove_wp_embed\|inject_responsive_srcset\|disable_admin_bar_frontend\|remove_adjacent_posts" includes/prompts.php` → no matches.
```bash
git add includes/prompts.php
git commit -m "Fix prompt catalog: correct 4 setting-key names + 2 array-type descriptions"
```

---

## Task 11: Interim JS guardrails (plugin)

**Files:**
- Modify: `ccm-tools/js/main.js:6330-6438` (disable infra auto-apply), `:7068-7253` (visual gate fail-closed), `:7504` (unconditional error rollback)

- [ ] **Step 1: Stop auto-applying infrastructure in pre-flight**

In the pre-flight step (`:6330-6438`), remove the automatic `ccm_tools_ai_enable_tool` calls for `htaccess`, `webp`, `redis`, `cloudflare`. Replace with a one-time notice listing the available infra tools and a manual "Enable" affordance (or simply log "Infrastructure changes are manual in this version"). Leave the `performance` master-enable in place (harmless).

- [ ] **Step 2: Visual gate fail-closed**

In the visual-compare block (`:7068-7253`):
- read `iterScreenshots?.desktop?.url || iterScreenshots?.desktop?.data_uri` (and mobile);
- require BOTH desktop and mobile comparisons to run;
- if screenshots are missing, the page is too tall, or the vision call errors → set `hasLayoutRegression = true` (fail closed) instead of continuing;
- delete the `DYNAMIC_CONTENT_PATTERNS` reclassification that discards structural issues; treat any `visualData.severity` of `high`/`critical` as `hasLayoutRegression = true`.

- [ ] **Step 3: Unconditional error rollback**

At `:7504`, change `if (allChanges.length > 0 && !wasRolledBack)` to `if (allChanges.length > 0)` so an uncaught error always attempts a rollback to the snapshot.

- [ ] **Step 4: Verify (syntax) + commit**

Run: `node --check "g:/My Drive/Github/ccm-tools/js/main.js"`
Expected: no output (valid). If `node` unavailable, load the plugin admin page and confirm no console parse error.
```bash
git add js/main.js
git commit -m "Interim AI guardrails: no infra auto-apply, fail-closed visual gate, always-rollback on error"
```

---

## Task 12: Version bumps + changelog (plugin)

**Files:**
- Modify: `ccm-tools/ccm.php` (header + version constant), `js/main.js` (header comment), `css/style.css` (header comment), `.github/copilot-instructions.md` (version + changelog), `CHANGELOG.md`

- [ ] **Step 1: Bump the plugin version**

Choose the next version (from current `7.44.1` → `7.45.0` for this feature set). Update all five locations. Add a CHANGELOG entry summarizing the security + AI hardening.

- [ ] **Step 2: Commit**

```bash
git add ccm.php js/main.js css/style.css .github/copilot-instructions.md CHANGELOG.md
git commit -m "v7.45.0: security + Phase 0 AI hardening"
```

---

## Self-review notes (coverage vs spec)

- Spec §4.1 SSRF → Tasks 1-3. §4.2 deploy secret, §4.3 `.git` → Task 4. §4.4/§4.5/§4.6 → Task 5. §5.1/§5.2 plugin → Task 6.
- Spec §6.1 LH13 → Task 7. §6.2 model/IO → Task 8. §6.3 prompt → Task 10.
- Spec §7.1 allow-list → Task 9. §7.2 infra auto-apply, §7.3 visual gate → Task 11.
- Verification (§9) folded into each task's verify step + the manual post-deploy checks in spec §8.
- Deferred to unit C (not in this plan): server-authoritative loop, full-state infra snapshot/rollback, single-change median-of-N verification, safe/assisted catalog UI.

## Post-implementation: manual steps (from spec §8)

After both branches are reviewed and merged, execute spec §8: rotate the deploy secret + GitHub webhook, set the live `claude_model` to `claude-opus-4-8`, cut the plugin release, and run the live verification checks (`.git` 404, `file://` rejection, PSI insight-schema log line).
