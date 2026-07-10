# CCM Tools + Hub — Review & AI Performance Redesign Plan

> Author: full review of plugin (`ccm-tools`) + hub (`ccm-api-hub`), July 2026.
> Evidence: deep code review of both codebases + live inspection of the hub deployment on **Ignea** (`/home/ccmtoolsapi/public_html`, `api.tools.clickclick.media` → 52.62.57.205).
> Verdict: **The AI Auto Performance feature is architecturally unsound and must be rebuilt. The plugin's optimization engine and the hub's billing/auth plumbing are mostly sound and are kept.**

---

## 0. TL;DR

Two independent failures compound into "shitty results + broken sites":

1. **The AI is flying blind.** Google migrated Lighthouse to *insight* audits; Lighthouse 13 (Oct 2025) **removed the legacy audit IDs** the hub extracts (`render-blocking-resources`, `unused-javascript`, `unused-css-rules`, `largest-contentful-paint-element`, `third-party-summary`). PSI has returned the new schema for ~9 months. The hub's extraction now returns **empty opportunity data**, so the model gets scores and nothing else — it cannot "focus on the elements returned in the report" because it never receives them. On top of that the configured model is stale (`claude-sonnet-4-*`), `temperature` is unset (=1.0), output is parsed with a buggy regex, and the prompt advertises setting keys that don't exist in the plugin.

2. **The safety net has holes you can drive through.** The whole optimize loop runs in the browser tab with no server watchdog; pre-flight infrastructure changes (`.htaccess`, Redis drop-in, WebP, Cloudflare) are applied **before** the snapshot and are **never** rolled back; the snapshot advances each iteration so "rollback" only undoes the last step; the AI's output is applied to live sites with **no server-side validation or risk allow-list**; and the visual-regression gate (the one check meant to catch "looks broken but scores fine") is trivially bypassed. Dangerous settings (`preload_css` without critical CSS, `delay_js`, `passive_event_listeners`, `disable_gutenberg_frontend`/`disable_block_css` on block themes, `disable_wp_cron`) are all fully AI-reachable.

Plus two **security issues to fix immediately** (independent of the redesign): an unauthenticated-content SSRF chain on the hub that can read any co-hosted customer's `.env` (the hub shares Ignea with ~186 customer sites), and an authenticated RCE primitive in the plugin's Redis config writer.

---

## 1. Why the AI does a poor job (correctness)

| # | Finding | File / evidence | Impact |
|---|---------|-----------------|--------|
| C1 | **PSI opportunity extraction keyed to removed Lighthouse audit IDs.** Opportunities are built only from audits with `details.overallSavingsMs > 0`; detail sections read `render-blocking-resources`, `largest-contentful-paint-element`, `unused-javascript`, `unused-css-rules`, `third-party-summary`. LH13 removed these from the JSON and moved to `*-insight` audits using `metricSavings`. | `ai-optimize.php:490-498,586-661`; `ai-analyze.php:58-132`; `pagespeed-test.php:158-174`. Verified vs [Chrome for Developers: What's new in Lighthouse 13](https://developer.chrome.com/blog/lighthouse-13-0) and [Lighthouse is moving to insight audits](https://developer.chrome.com/blog/moving-lighthouse-to-insights). | **Root cause of "doesn't follow the report."** AI receives scores + 6 metrics and essentially no failing-audit detail. |
| C2 | **Stale/retired model.** `schema.sql` seeds `claude_model = 'claude-sonnet-4-20250514'` (Sonnet 4.0, retirement 15 Jun 2026 — already past); code default `claude-sonnet-4-6`. Either is ≥1 generation old for a nuanced "will this break the site?" judgement. | `database/schema.sql:298`; `config.php:96`; `ai-optimize.php:561`, `ai-analyze.php:353`. | Weak judgement; possibly 404ing outright. |
| C3 | **No structured output / validation; brittle parsing.** Response is `json_decode` + one regex `/```(?:json)?\s*(\{.*?\})\s*```/s` whose lazy `\{.*?\}` truncates nested JSON. No `stop_reason` check; `max_tokens` floored at 16384 non-streaming while the model is asked to inline ≤15KB `critical_css_code`. Failures degrade to HTTP 200 with `recommendations: []`. | `ai-optimize.php:875-896`; `ai-analyze.php:426-446`. | Silent empty runs; wasted iterations; inconsistent output. |
| C4 | **`temperature` unset → API default 1.0.** Maximum sampling randomness for a task that needs consistency. | grep: no `temperature` in `api/v1`. | Same input → different recommendations run to run. |
| C5 | **Prompt advertises 4 non-existent setting keys + 2 wrong types.** `remove_wp_embed`→`disable_wp_embed`, `inject_responsive_srcset`→`inject_srcset`, `disable_admin_bar_frontend`→`disable_admin_bar`, `remove_adjacent_posts`→`remove_adjacent_post_links`; `preload_key_urls`/`delay_third_party_domains` described as newline-strings but plugin expects arrays. Unknown keys silently dropped; array keys only assigned `if (is_array($value))`. | `includes/prompts.php`; plugin `inc/ai-hub.php:559,575-584`. | Wasted recommendation slots (hard cap 5); two high-leverage TBT levers can never be configured. |
| C6 | **Warm-baseline vs cold-retest asymmetry.** Baseline PSI runs with no cache flush; every retest runs seconds after a full purge of Redis + page cache + entire Cloudflare zone, with no cache warming. | `js/main.js:6482,6513` vs `6804,6934-6948`. | Genuine improvements read as regressions → false rollbacks + false "known-bad" bans. |
| C7 | **Learnings record variance as causation, then feed it back as "HIGH CONFIDENCE."** Rollback fires on *any* negative delta from a single mobile PSI sample; outcome attributed to *every* setting changed in the run; those tables are injected into every other site's prompt as PREFER/AVOID. | `ai-optimize.php:231-236`; `learnings.php:157-181,216-269`. | Systematically wrong, increasingly conservative recommendations hub-wide; cross-tenant poisoning. |
| C8 | **Critical CSS generated from a lossy, arbitrary sample.** "Main CSS" = first 4 same-origin stylesheets in DOM order, 25KB/file, 40KB total, mid-rule truncation, keyframes/media stripped; above-fold = first 4KB of `<body>`. Custom bot UA `CCM-Tools-Analyzer/1.0` is WAF-bait; on fetch failure the model is never told. | `page-analyzer.php:39,270-372`. | Wrong/partial critical CSS → FOUC when the rest of the CSS is deferred. |
| C9 | **Optimize path bugs:** `category` sent as `category[0]=…` (Google ignores → only performance category returns, others `null`); diagnostics never stored though the prompt reads them; `tti_ms` always 0 (audit removed in LH10); regression re-analysis passes `current_settings=[]`. | `ai-optimize.php:440-445,501-517,258`. | Prompt sections silently empty. |
| C10 | **No prompt caching.** 18-27KB system prompt + up to 40KB page CSS re-billed at full input price every iteration (~20-28K tokens/call). | grep: no `cache_control`. | Cost/latency drag on the multi-iteration loop. |

---

## 2. Why the AI breaks sites (safety architecture)

| # | Finding | File / evidence | Impact |
|---|---------|-----------------|--------|
| S1 | **Pre-flight infra changes applied before the snapshot and never rolled back.** `.htaccess` rewrite, Redis drop-in install, WebP serving, Cloudflare zone settings are applied at run start; the snapshot only covers perf-optimizer settings; no rollback path touches infra; no health check after pre-flight. | `js/main.js:6330-6438`; snapshot at `inc/ai-hub.php:773-790`. | **Most likely cause of "site broken with no recovery."** A bad `.htaccess`/Redis drop-in 500s the site and nothing reverts it. |
| S2 | **Entire loop is client-side; no server watchdog.** Runs take 30-90+ min. Close the tab / sleep the laptop / network blip → whatever was applied stays live, including untested settings applied in the window before their smoke test. | `js/main.js:6574-7436`. | Untested `critical_css_code`/`delay_js` left live on production indefinitely. |
| S3 | **Snapshot advances every iteration.** After any "keep," a fresh snapshot including the kept changes is taken; single snapshot slot. Rollback only ever reverts the current iteration. | `js/main.js:7421,7365,6973`. | Cumulative breakage across iterations can never be undone; "rolled back to pre-optimization" message is false after iteration 1. |
| S4 | **No server-side validation / risk allow-list; master toggle force-enabled.** Hub returns arbitrary output; plugin applies any key that exists in `$settings` and then sets `enabled = true`. The UI's "Safe vs Advanced" split is cosmetic — not enforced anywhere. | `inc/ai-hub.php:524-635` (esp. 558-559, 628-631). | Any site-breaker the model emits is applied. |
| S5 | **Visual-regression gate bypassable.** Reads only screenshot `.url` (skips `data_uri`); desktop-only; missing screenshots → keep on score alone; pages >8000px → skip; vision flake → keep; issues matching `carousel/slider/banner` keywords reclassified as "dynamic content" and ignored even at `severity: critical`. | `js/main.js:7068-7253`. | The one "looks broken but fast" check silently doesn't run. |
| S6 | **Score gate is noisy and desktop-blind.** Per-group testing is mobile-only; `netPositive` tolerates a desktop drop to −14; `PSI_NOISE=3` is tighter than real ±5 variance; single-sample decisions. | `js/main.js:6846,7255-7266`. | Desktop regressions kept; good iterations rolled back on noise. |
| S7 | **Dangerous settings fully AI-reachable, no compatibility gating.** `preload_css` without `critical_css_code` (guaranteed FOUC — not in the orphan-toggle guard); `delay_js`; `passive_event_listeners` (global `addEventListener` override breaks parallax/sliders/scroll-lock); `disable_gutenberg_frontend`/`disable_block_css` dequeue `global-styles` with no `wp_is_block_theme()` guard; `disable_wp_cron` via `pre_option_cron` can corrupt the cron array; `woo_scripts_shop_only` kills add-to-cart in page-builder blocks. | `performance-optimizer.php` (see §5 table); guard gap at `inc/ai-hub.php:530-538`. | Each is a known site-breaker; several apply even to logged-in non-admin visitors (`performance-optimizer.php:176`). |
| S8 | **Outer-catch rollback is skipped if any earlier iteration rolled back** (`!wasRolledBack`); smoke-fail revert failures are only logged and the loop continues applying more settings onto a 500ing site; a run can print "complete!" while broken. | `js/main.js:7504,6817-6838,6913-6921`. | Half-applied broken state reported as success. |
| S9 | **Interrupted/timed-out applies bake in phantom changes.** Client `AbortController` timeout cancels the fetch but PHP still writes settings; the orphan change is invisible to `keptChanges`, then frozen into the next checkpoint snapshot. | `js/main.js:271-316,6779`. | Untested settings survive all rollbacks. |

---

## 3. Security findings (fix now — independent of redesign)

The hub runs on **Ignea, the primary customer server (~186 cPanel accounts)**. That makes the SSRF findings materially worse than "internal-only."

### Hub — P0
- **SSRF → local file / secret disclosure (Critical).** `ai-chat.php` fetches a fully caller-controlled `site_url` via `page-analyzer.php` cURL with `FOLLOWLOCATION` and **no scheme/host/IP allow-list**; the body is embedded in the model prompt and the caller controls the message. `file:///home/<acct>/…/.env` exfiltrates `ENCRYPTION_KEY`, `DB_*`, `STRIPE_*`. Same class in `screenshot-capture.php` (Chromium renders `file://`/internal URLs and returns them as a public JPEG), `console-check.php`, `ai-visual-compare.php`, `page-analyzer.php` CSS fetch. `pagespeed-test.php`/`ai-optimize.php` already do it right via `urlMatchesSite()`. **Fix:** scheme allow-list (`http`/`https`), `CURLOPT_PROTOCOLS`/`REDIR_PROTOCOLS`, block private/reserved/link-local ranges (resolve-then-connect, re-check after redirects), require `urlMatchesSite()` where a site identity exists, never allow `file://` in Chromium. Files: `ai-chat.php:32,47-48`; `screenshot-capture.php:38-44`; `console-check.php:32-39`; `ai-visual-compare.php:41-49,109-118`; `page-analyzer.php:33-42,302-311`.
- **Hardcoded deploy secret (High).** `deploy.php:24 define('DEPLOY_SECRET','ccm-hub-deploy-2026')` — committed + synced to Drive. Anyone who reads/guesses it can forge the GitHub HMAC and trigger `git pull` on prod. **Fix:** move to `.env`, rotate the value and the GitHub webhook secret (32-byte random), consider IP-allowlisting GitHub ranges.
- **`.git/` exposed under web root (High).** Repo root is the docroot; `.htaccess` blocks `config/`, `includes/`, etc. but not `.git/`. **Fix:** `RedirectMatch 404 /\.git` (verify `curl -I .../.git/HEAD`); ideally deploy a build artifact, not a live working tree.

### Hub — P1/P2
- `admin/subscriptions.php`: `grant_premium`/`revoke_premium` POST has **no CSRF token and no role gate** (only `requireLogin()`), while default SSO role is viewer — add `requireCsrf()` + `requireManager()`.
- `test-screenshot.php`: any logged-in user; forces `display_errors`; unvalidated `$_GET['url']` SSRF — remove from prod or lock behind `requireAdmin()` + SSRF guards.
- `encryption.php`: secrets use AES-256-CBC with no MAC — move to GCM or encrypt-then-HMAC (transit functions already do this correctly).
- Lower: `checkFeatureAccess($site,'ai_enabled')` is a no-op (should be `'ai'`) — extra SSRF surface; spoofable `getClientIP()`; `api_key_hash` serialized into admin DOM; verbose exception text; non-constant-time admin-key compare; Stripe webhook lacks event-ID idempotency.

### Plugin — P0
- **RCE via Redis password/username → wp-config.php (High).** `inc/redis-object-cache.php:1300` interpolates the value straight into a single-quoted `define()`; `password` is fully raw (`ajax-handlers.php:3267`), `username` via `sanitize_text_field` (doesn't strip quotes). Payload `x'); eval($_POST['c']); //` becomes persistent PHP execution. Nonce + `manage_options` required, so worst on multisite/hardened installs. **Fix:** `var_export($value, true)` (or `addslashes` + reject quotes/non-printables at input, matching how `host`/`path`/`key_salt` are already validated).
- Lower: wp-config backups written into the web root (`redis-object-cache.php:1348-1351,836-839`) — move under `uploads/ccm-private/`; plaintext CF token / hub key in options (standard WP, contained).

**Plugin auth posture is otherwise strong:** all ~95 `wp_ajax_` handlers enforce both nonce and `manage_options`; no `nopriv` handlers; no SQLi/command-exec/path-traversal/object-injection found (tablenames validated against live `SHOW TABLES`, WebP via GD/Imagick, imports via `json_decode`).

---

## 4. Recommendation: rebuild the AI Auto Performance flow

The optimization *engine* (the individual perf features) and the hub's *plumbing* (auth, Stripe, PSI runner, screenshots) are worth keeping. What must be **redone** is everything between "we have a PSI report" and "settings are live on the site": ingestion, the decision loop, the safety/rollback model, and output validation.

### Design principles

1. **Server-authoritative, resumable orchestration.** Move the optimize loop off the browser tab. Either (a) hub-driven with the plugin exposing a small authenticated apply/health/rollback API, or (b) plugin-driven via WP-Cron/Action Scheduler with persistent run state. Non-negotiable: a **watchdog** that auto-reverts to baseline if a run is abandoned, stalls, or fails a health check. Closing the tab must never leave a site changed.

2. **One full-state snapshot, atomic rollback.** Before *any* change (including pre-flight infra), capture a complete baseline: perf settings **+ `.htaccess` + object-cache drop-in state + Cloudflare zone settings + WebP state**. Rollback restores that exact baseline. Pre-flight infra changes live inside the snapshot scope or are not auto-applied at all.

3. **Server-side contract + risk allow-list.** The hub returns recommendations via **Structured Outputs** (`output_config.format` JSON schema with `enum` on `setting_key`) so an invalid key is impossible. Both hub and plugin validate every recommendation against a **typed, tiered allow-list**: `{key, type, tier: safe|assisted|blocked, requires: [...], incompatible_with: [...]}`. High-risk keys are rejected unless their preconditions are met (e.g. `preload_css` requires non-empty `critical_css_code`; `disable_block_css` blocked when `wp_is_block_theme()`).

4. **Fix PSI ingestion to LH13 insight audits.** Extract from the insight audits and read `metricSavings`, not `details.overallSavingsMs`. Map each insight to the CCM settings that address it, and **constrain** recommendations to target reported insights:
   `render-blocking-insight`, `lcp-discovery-insight`, `lcp-phases-insight`, `image-delivery-insight`, `third-parties-insight`, `render-blocking-insight`, `legacy-javascript-insight`, `duplicated-javascript-insight`, `dom-size-insight`, `font-display-insight`, `cls-culprits-insight`, `network-dependency-tree-insight`, `modern-http-insight`, `use-cache-insight`, `viewport-insight`, `interaction-to-next-paint-insight`, `document-latency-insight`. Handle both the legacy and insight schema for a transition period, and log which was seen.

5. **Current model + reasoning + caching.** Move to `claude-opus-4-8` (or `claude-sonnet-5`) with adaptive thinking for the risk judgement; drop `temperature` (newer models reject it anyway); add `stop_reason` handling; add `cache_control` on the system prompt + page-resource block. Add the new model IDs to the cost table (`page-analyzer.php:437-443`).

6. **Two-gate, single-change-at-a-time apply.** Never decide on one noisy sample. For each change (or minimal group): apply → warm cache → measure **median of N PSI samples on both mobile and desktop** with a variance-aware threshold → **AND** no NEW console errors → **AND** no visual regression (mandatory, **fail-closed**: no screenshots = no keep). Keep or revert atomically before touching the next change. Attribute the outcome to that single change (enables real causal learning).

7. **Curated safe-by-default catalog.** *Auto mode* only touches the safe tier (lazy-load non-LCP, async decode, image dimensions/srcset, head cleanup, preconnect/dns-prefetch, `font-display: swap`, HTTP caching headers, WebP). The *assisted tier* (`delay_js`, `preload_css`+`critical_css`, `disable_gutenberg_frontend`, `passive_event_listeners`, WooCommerce dequeue, `disable_wp_cron`) requires per-change preview + explicit operator confirmation, with block-theme/WooCommerce/page-builder detection gating incompatible items.

8. **Causal, variance-aware learning that actually filters.** Per-setting attribution (single-change apply makes this real), variance modelling instead of fixed ±3, and the known-bad list is **enforced** on output (filter before apply), not just mentioned in the prompt. Validate `changes[].key` against the allow-list on ingest to stop cross-tenant poisoning.

### Phasing

**Phase 0 — Stop the bleeding (days).** Ship without a rebuild:
- Update `claude_model` to a current model (and verify the live DB value).
- Fix PSI insight-audit extraction (C1) + the `category` param + store diagnostics (C9).
- Add the server-side allow-list to `ccm_tools_ai_hub_apply_recommendations()` and **gate `preload_css` on non-empty `critical_css_code`** (S4/S7, closes the #1 FOUC cause).
- Make visual-regression **fail-closed** and read `data_uri` (S5).
- Put pre-flight infra inside the snapshot/rollback scope, or disable auto-apply of infra in one-click (S1).
- Security P0: SSRF guards; rotate+move deploy secret; block `.git/`; fix `subscriptions.php`; lock `test-screenshot.php`; `var_export` the Redis config writer.

**Phase 1 — Server-authoritative core (1-2 wks).** Move orchestration server-side with persistent run state + watchdog (principle 1); full-state snapshot/atomic rollback (2); Structured Outputs + typed allow-list (3); model/caching/`stop_reason` (5).

**Phase 2 — Decision quality (1-2 wks).** Two-gate single-change apply with N-sample median + variance (6); insight-driven recommendation targeting (4); causal learning + enforced known-bad (8).

**Phase 3 — Catalog + compatibility (1 wk).** Safe/assisted tiers with preview + confirmation; block-theme/Woo/builder detection gating (7); fix the individual optimizer bugs in §5.

---

## 5. Perf-optimizer bugs to fix during the rebuild (from the module review)

- **#1** `preload_css` can be enabled without `critical_css_code` (not in the orphan-toggle guard) → guaranteed FOUC. `performance-optimizer.php:38`, guard gap `inc/ai-hub.php:530-538`.
- **#2** `preload_css` rebuilds `<link>` from scratch, dropping `integrity`/`crossorigin`/`data-*` and regenerating the id. `:689-695`.
- **#3** No feed/AMP/Content-Type guard on the `the_content` transforms (facades, dimensions, srcset inject markup into RSS/AMP). `:157-431`.
- **#4** `delay_js` loader runs delayed scripts out of dependency order (`newScript.defer=true` is a no-op on dynamic scripts). `:606-610`, third-party loader `:2067`.
- **#5** `passive_event_listeners` forces passive on the classic `addEventListener(type, fn, false)` signature → `preventDefault()` ignored → parallax/sliders/scroll-lock break. `:2142-2146`.
- **#6** `disable_block_css`/`disable_gutenberg_frontend` dequeue `global-styles` with no `wp_is_block_theme()` guard. `:1462-1467,2076-2081`.
- **#7** `disable_wp_cron` returns `array()` from `pre_option_cron` globally → cron-array corruption / mass-unscheduling. `:444-449`.
- **#8** `has_inline_companion()` only sees `wp_add_inline_script`, not raw template inline scripts. `:507-513`.
- **#9** `self_host_google_fonts` does blocking remote fetches on the front-end critical path (10s + 15s/font). `:1867-1920`.
- **#10** Stacked full-page output buffers; `minify_html` has no Content-Type guard (can corrupt XML sitemaps/JSON). `:372,1767-1770`.
- **#11** `youtube_facade` hardcodes 16:9 + autoplay, drops title/allow. `:822-864`.
- **#12** Exclude-list `sanitize_key()` mangles dot/slash URL fragments. `ajax-handlers.php:2246-2262`.
- **#13** JS/CSS transforms apply to logged-in non-admin users (only `manage_options` is bypassed). `:176`.

---

## 6. Verification checklist (run against production)

- [ ] Confirm live `app_settings.claude_model` and `claude_max_tokens` (DB read was blocked in this session — needs an approved query or admin UI check).
- [ ] Dump one recent `pagespeed_results.full_response`, grep `lighthouseVersion` and `render-blocking-requests` vs `render-blocking-resources` to confirm C1 on live data.
- [ ] `curl -I https://api.tools.clickclick.media/.git/HEAD` — confirm `.git/` exposure before/after fix.
- [ ] Verify `deploy.php` secret rotated in `.env` + GitHub webhook.
