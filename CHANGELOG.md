# CCM Tools — Changelog

## v7.43.0
- **Raise the default Redis max-TTL from 1 hour to 7 days (`604800`)**
  - The managed `WP_REDIS_MAXTTL` default was `3600`, which capped *every* cache entry — including the many objects WordPress stores with no expiry (`expire = 0`) — at one hour. With `allkeys-lru` doing the real memory management (and 0 evictions / huge headroom on our boxes), a 1-hour cap just forced needless cache misses and DB churn. The new `604800` default keeps a sane safety ceiling while letting long-lived objects actually live. Applied consistently across the one-step Save flow, the legacy "Add to wp-config" path, the auto-generated config in System Info, and the settings-screen placeholder. Existing installs with an explicit Max TTL set are untouched.
- **Default the Redis serializer to igbinary when the extension is present**
  - igbinary produces smaller payloads and faster encode/decode than PHP's native serializer. The default serializer is now `igbinary` whenever `extension_loaded('igbinary')`, falling back to `php` otherwise. The constant is still only written to `wp-config.php` when non-php *and* the extension is loaded, and the drop-in's existing serializer-drift detection auto-flushes its own keys once on the switch, so existing installs migrate safely. (Compression is deliberately left at `none` — LZ4 + igbinary previously caused production OOMs; see v7.41.4.) The settings-screen labels now reflect which serializer is the active default.

## v7.42.1
- **Fix "plugin deactivated itself" after a manual zip install (duplicate folder)**
  - Release zips are flat (files at the archive root). When a developer downloads `ccm-tools-<ver>.zip` and installs it via **Plugins → Add New → Upload**, WordPress names the destination folder after the *zip filename* — creating `wp-content/plugins/ccm-tools-<ver>/` alongside the canonical `ccm-tools/`. With two active copies, the old duplicate-detection guard made the plugin deactivate **itself** (often the good copy, whichever loaded first) — the "plugin deactivated itself after update" symptom several sites hit. (The in-dashboard auto-updater was unaffected because its `fix_source_dir` renames the folder.)
  - **Prevent (root cause):** release zips now ship with a top-level `ccm-tools/` wrapper folder, so a manual upload always installs to `/wp-content/plugins/ccm-tools/` regardless of the zip filename. The 7.42.0 and 7.41.4 release assets were rebuilt with the wrapper too.
  - **Heal (existing sites):** the duplicate guard no longer deactivates itself. From the canonical `/ccm-tools/` install it now detects version-suffixed `ccm-tools-*` duplicate folders, silently deactivates them (no teardown hooks fire — wp-config and the drop-in are never touched), deletes the stale folders via the filesystem API (no uninstall hooks, so shared options survive), and shows a notice listing what was removed. A copy running from a version-suffixed folder while the canonical exists stands down quietly and hands back to `/ccm-tools/`; a lone version-suffixed install keeps running and just warns to reinstall.
- **Atomic object-cache drop-in replacement**
  - The v7.42.0 auto-refresh wrote the drop-in with `copy()` straight over the live `wp-content/object-cache.php`, which truncates-then-writes — a concurrent request could read a half-written file and fatal. It now writes to a temp file and `rename()`s it into place (atomic on the same filesystem), so readers always see the old or new file whole.

## v7.42.0
- **Redis drop-in lifecycle automation + one-step Save**
  - **Auto-replace the drop-in on plugin update.** Added `upgrader_process_complete` and an `admin_init` self-heal that bring the deployed `wp-content/object-cache.php` into line with the bundled version automatically. Previously a version bump (e.g. the v7.41.4 OOM fix) only raised an admin notice the user had to click — so fixes never reached sites until someone manually reinstalled. The refresh is connection-less, never overwrites another plugin's drop-in, only copies when the bundled `@version` is newer, and is guarded against AJAX/cron churn.
  - **Auto-(re)install on activation.** `register_activation_hook` reinstalls/refreshes the drop-in when Redis was previously enabled — covering the WP-Cron update deactivate→reactivate dance that could leave a stale or missing drop-in.
  - **Clean teardown on disable/deactivation.** `register_deactivation_hook` (and the Disable button) now remove the drop-in **and** strip the managed Redis block from `wp-config.php`. Genuine deactivations only — WordPress deactivates silently during updates, so caching is never torn down mid-update. Saved settings are retained, so re-enabling restores everything.
  - **One Save does the lot.** Saving the Redis settings now also rewrites `wp-config.php` and refreshes the drop-in **when Redis is enabled** — eliminating the easily-missed second "Add to wp-config.php" step. Enabling Redis writes `wp-config.php` in the same action too. The wp-config write is skipped when nothing changed (no needless backups), and both `wp-config-backup-*` and `object-cache-backup-*` files are pruned to the most recent 5.
  - Internal: extracted shared `ccm_tools_redis_build_config_array()` and `ccm_tools_redis_managed_constants()` so the Save and "Add to wp-config" paths can never drift; added `ccm_tools_redis_refresh_dropin()`, `ccm_tools_redis_remove_config()`, and `ccm_tools_redis_prune_backups()`.

## v7.41.4
- **Harden Redis object cache against `alloptions` corruption causing 4&nbsp;GB OOM crashes**
  - thesportingbase.com (Paladine headless front-end, WP backend at `/tsb/`) suffered two outages — **2026-05-28** and **2026-06-03** — where 3,000+ `Allowed memory size … exhausted (tried to allocate 4,295,229,440 bytes) in wp-includes/theme.php` fatals took `/tsb/wp-admin` and `/tsb/wp-json` fully down for ~1 hour each. The 4&nbsp;GB allocation is the classic signature of `unserialize()` reading a corrupted length prefix. With `WP_REDIS_SERIALIZER='igbinary'` + `WP_REDIS_COMPRESSION='lz4'`, php-redis occasionally fails to round-trip the `alloptions` blob (LZ4 decompress → igbinary deserialize), and because that blob is read on nearly every request via `wp_load_alloptions()`, every fresh FPM worker that hit the corrupt Redis key OOM'd identically until the cache was flushed and FPM restarted.
  - The **v7.39.10** fix (commit `67a4193`) addressed one *trigger* — `apply_filters('active_plugins', …)` firing theme resolution at file-include time — but not the underlying cache fragility. The 2026-06-03 incident fired from a different entry point (`theme.php:325`, `apply_filters('template', get_option('template'))`) with v7.39.10 confirmed in place, proving the cache itself needed hardening.
  - **Fix (P1 — the real fix):** the object-cache drop-in no longer persists the `options` / `site-options` groups to Redis. The `alloptions` blob is already memoised per request by WP core, so skipping Redis costs at most one indexed `wp_options` SELECT per worker while removing the sitewide failure surface entirely. Opt back in (not recommended) with `define('WP_REDIS_PERSIST_OPTIONS', true);`.
  - **Fix (P2 — belt-and-braces):** `get()` now type-guards `options:alloptions` and `options:notoptions` — any non-array return is treated as a cache miss and rebuilt from the database, protecting sites that re-enable options persistence.
  - **Fix (P3 — encoding-drift auto-flush):** on connect, the drop-in stamps the active serializer+compression in a sentinel key (read/written via `rawCommand` to bypass the encoders) and selectively flushes its own keys if the encoding changed out from under existing data — covering manual `wp-config.php` edits and server-level extension changes, which the v7.39.6 UI-only auto-flush never caught.
  - **Fix (P4 — guidance):** the Redis settings screen now warns that LZ4 + igbinary has caused production OOMs (recommending igbinary with no compression) and documents the skipped options/site-options groups plus the override constant.
  - Drop-in `@version` bumped 7.19.0 → 7.41.4; sites running the old drop-in will see the "drop-in outdated" admin notice prompting a reinstall.

## v7.41.3
- **Fix silent "an error occurred" deactivation after auto-update**
  - During a WP-Cron auto-update, WordPress's `Plugin_Upgrader` silently deactivates the plugin via `active_before`, replaces the files, then calls `activate_plugin()` to re-enable it. Both WP's `active_after` and our own `after_install` invoke `activate_plugin()` — and because the plugin is no longer in `active_plugins` at that point, WP runs the full activation path including `plugin_sandbox_scrape()`, which `include`s `ccm.php` a second time within the same request.
  - The OLD `ccm.php` was already loaded at request boot, so the second include re-executed the unguarded global function definitions (`ccm_tools_hide_all_notices`, `ccm_initialize_plugin`, class `CCMSettings`, …) and PHP fatal'd with "Cannot redeclare function". WordPress's fatal-error handler caught it, paused the plugin, and surfaced the generic "an error occurred" notice — but with no entry in `debug.log` unless `WP_DEBUG_LOG` was enabled.
  - Fix: added a `CCM_TOOLS_FILE_LOADED` sentinel at the very top of `ccm.php` that cleanly returns on the second include, so activation completes without re-declaring symbols.

## v7.41.2
- **Fix `wp` / `jQuery` is not defined console errors caused by defer/delay**
  - `defer_js` and `delay_js` now skip any script that has a registered `wp_add_inline_script(handle, ..., 'before'|'after')` companion. The inline `_after` runs at parse time and references symbols (`wp.i18n.setLocaleData`, `jQuery(...)`) that the parent hasn't defined yet — three of the four console errors on wendyshome.com.au were this exact pattern.
  - Added `wp-a11y` and `wp-polyfill` to the always-exclude list for both defer and delay (previously only `delay_js` had a list, and it was missing these two).
  - `defer_js` previously had **no** always-exclude list at all — it was happily deferring `wp-i18n`/`wp-a11y`/`wp-hooks`. Now mirrors `delay_js`.
- **Visual regression check no longer dismisses real carousel breakage**
  - Previous filter treated any AI report mentioning "carousel" or "slider" as expected dynamic content. The AI was correctly flagging "carousel JavaScript is not initializing properly, all testimonials stacked vertically" — that's a regression, not a slide change. New filter looks for breakage words (`not initializing`, `stacked vertically`, `broken`, `regression`, `unstyled`, `missing`, `falling back`, plus mentions of plugin setting names) and overrides the dynamic-content classification when present.

## v7.41.1
- **AI Optimiser — guard against orphan parent toggles**
  - The AI sometimes recommends a feature toggle (`critical_css: true`, `preconnect: true`, `dns_prefetch: true`, `lcp_preload: true`, `preload_key_requests: true`, `delay_third_party: true`) without the companion data key in the same response. The toggle would flip on but do nothing — confusing in the UI and PSI variance could blame it for unrelated score drops.
  - Server-side: `ccm_tools_ai_hub_apply_recommendations` now post-validates the result and forces the parent toggle back to false if the required data key is empty.
  - Client-side: the apply pre-filter detects orphan parent toggles before they reach a test cycle and logs `Blocked N orphan toggle(s) — feature enabled without required data`. Saves a 30-second mobile retest per orphan.

## v7.41.0
- **AI Performance Optimiser — reliability fixes**
  - **Visual check on tall pages no longer fails silently**: Hub now scales screenshots wider/taller than 7800px to fit Claude Vision's 8000px hard limit; plugin detects the legacy oversize error, logs a clear "skipped — page too tall" message, and stops uselessly retrying. New `image_clamped` flag surfaces when auto-scaling occurred.
  - **Stops re-suggesting already-applied settings**: Before the apply loop runs, the plugin now compares each AI recommendation against current settings and drops anything that would be a no-op. Skipped keys are sent back to the AI on the next iteration so it picks fresh levers instead of wasting a slot.
  - **Persistent per-URL learning**: Settings that cause a ≥10pt mobile drop on a URL are now remembered across runs (60-day TTL, 50-key cap, LRU evicted). On the next One-Click Optimise for the same URL, those settings are filtered out before apply and the AI is told they're proven incompatible. New AJAX handlers: `ccm_tools_ai_record_known_bad`, `ccm_tools_ai_get_known_bad`, `ccm_tools_ai_clear_known_bad`.

## v7.30.0
- **INP & Interaction Optimizations — Passive Event Listeners + DOM Size Warning**
  - **Passive Event Listeners** (#27): Inline `wp_head` script (priority 1) overrides `EventTarget.prototype.addEventListener` globally — forces `{passive: true}` for scroll/wheel/touchstart/touchmove; fixes PageSpeed "Does not use passive listeners" audit; estimated 50–150ms TBT/INP improvement
  - **DOM Size Warning** (#28): Informational toggle; instructs AI Performance Optimizer to flag pages with DOM node counts >1,500 and recommend structural simplifications
  - New "INP & Interaction Optimizations" UI card on Performance Optimizer page

## v7.29.0
- **WP Cleanup — Disable WP Cron, Disable Author Archives**
  - **Disable WP Cron** (#22): Unhooks `wp_cron` from `init`, eliminating the per-request cron HTTP sub-request (~50–200ms); requires server-side cron as replacement
  - **Disable Author Archive Pages** (#25): 301 redirects `/author/username/` to homepage — prevents thin-content penalty and user enumeration via author slugs

## v7.28.0
- **Block Editor & WooCommerce Asset Control + Browser Cache Headers**
  - **Disable Gutenberg Frontend Assets**: Dequeues `wp-block-library`, `wp-block-library-theme`, `global-styles`, `classic-theme-styles` (~35–50 KB saved)
  - **WooCommerce Assets on Shop Pages Only**: Dequeues WC scripts/styles on non-commerce pages (~100–200 KB saved); only shown when WooCommerce active
  - **Cache-Control Header**: Sends `Cache-Control: public, max-age=3600` for logged-out users; works on Nginx/LiteSpeed
  - **Stale-While-Revalidate**: Sub-toggle appends `stale-while-revalidate=86400` to Cache-Control header

## v7.27.0
- **Resource Hints & Third-party Delay**
  - **Preload LCP CSS Background Image**: Emits `<link rel="preload" as="image" fetchpriority="high">` for CSS background LCP element; 200–1000ms LCP improvement
  - **Priority Hints (Above-fold Images)**: Output buffer adds `fetchpriority="high"`, removes `loading="lazy"` on above-fold images
  - **Delay Third-party Scripts**: Rewrites matching `<script src>` to `type="text/plain" data-ccm-delay-src`; restores on first user interaction or 5s fallback; can reduce TBT 200–2000ms

## v7.26.0
- **HTML & Font Optimisations**
  - **Minify HTML Output**: Strips HTML comments and inter-tag whitespace; preserves `<pre>`, `<textarea>`, `<script>`, `<style>` blocks; saves 5–25 KB
  - **Preload Key Requests**: `<link rel="preload">` tags in `wp_head`; auto-detects `as` from file extension
  - **Remove wp-embed.min.js**: Deregisters wp-embed script and removes oembed host JS (~3.5 KB)
  - **Self-host Google Fonts**: Downloads CSS + WOFF2 to `uploads/ccm-fonts/`; MD5 cache key; 30-day freshness; eliminates external DNS lookup

## v7.25.0
- **Script & Style Inlining**: Inline scripts/styles under configurable threshold (default 2 KB, range 1–50 KB); eliminates per-asset HTTP requests
- **Inject Image Dimensions**: Adds `width`/`height` to `<img>` tags missing them; eliminates CLS
- **Inject Responsive srcset**: Adds `srcset`/`sizes` to local images missing them via WordPress srcset API

## v7.24.0
- **Head Cleanup additions**: Remove Generator Tag, Disable Admin Bar (Frontend), Remove Adjacent Post Links
- **Bug Fix**: `lazy_load_images`, `image_decoding_async`, `prefetch_on_hover` not saving (were missing from `savePerfSettings()` JS data object)

## v7.23.0
- **Image Optimizations**: Lazy Load Images, Async Image Decoding, Prefetch on Hover — all with LCP exclusion logic

## v7.22.4
- **Fix Duplicate Redis Constants**: `ccm_tools_redis_add_config()` now strips existing CCM block before writing fresh one — idempotent, prevents "Cannot redeclare constant" PHP fatal

## v7.22.3
- **Redis Active Config Table Live Update**: Save handler returns `active_config` array; JS rebuilds table without page reload
- Added `id="redis-active-config-table"` for DOM targeting; removed `location.reload()`

## v7.22.2
- **WWW/Non-WWW URL Normalization**: Both plugin and hub now strip `www.` before URL comparison; same API key works for both variants

## v7.22.1
- **AI Troubleshooter Site-Specific**: Chat endpoint fetches live page HTML/CSS on first message; AI can generate real Critical CSS and identify actual script/domain lists

## v7.22.0
- **Per-Setting Incremental Apply**: Each AI recommendation tested individually (apply → 3s wait → mobile PSI → keep or revert); `SINGLE_SETTING_TOLERANCE = 5` pts; related settings grouped (parent + data keys)
- **Net result**: If AI recommends 5 settings and 1 is bad, the other 4 now survive

## v7.21.0
- **Smart Iteration Strategy**: `sessionFailedBatches` tracks rolled-back settings within session; `buildSessionFailedContext()` sends "banned keys" to AI on retries
- **Hub prompts**: Max 5 recommendations per batch; sort by risk (no-risk → medium → high; max 1 high per batch)
- **Max iterations pulled from hub** (was hardcoded `AI_MAX_ITERATIONS = 10`)
- **Visual check timeout**: No longer forces rollback when scores improved; only rolls back when visual check failed AND scores dropped

## v7.20.8
- `WP_REDIS_SCHEME`, `WP_REDIS_TIMEOUT`, `WP_REDIS_READ_TIMEOUT` always written to wp-config.php (not skipped when at defaults)

## v7.20.7
- Redis Key Prefix/Salt "Generate" button using `crypto.getRandomValues()` for cryptographically secure random bytes

## v7.20.6
- **Dynamic Stripe Price on Premium Page**: `ccm_tools_premium_get_pricing()` fetches from hub; 12h transient cache
- **Fixed "Free" status after saving API key**: Save handler now calls `ccm_tools_premium_clear_cache()`
- **Hide comparison cards when Premium active**; refactored hub premium check to use shared `ccm_tools_ai_hub_request()`

## v7.20.5
- Fixed auto-update failing when plugin directory already named `ccm-tools` (flat zip edge case)

## v7.20.4
- Fixed API key not saving on Premium page (`initAiHubHandlers()` only called from Performance page)

## v7.20.3
- Fixed GitHub release zip installs to wrong directory (`upgrader_source_selection` filter renames extracted folder)

## v7.20.2
- Removed Premium tab from top nav; moved Premium submenu item to bottom of sidebar (after Error Log)

## v7.20.1
- Removed Premium dashboard card; updated Get Premium URL; added "Lost your API key?" login link

## v7.20.0
- **Dedicated Premium Admin Page** (`ccm-tools-premium`): Hub Connection + Subscription Status cards
- **Premium Subscription System**: 3-tier check (wp-config constant → 12h transient → hub API); render-level + AJAX-level gating; upsell cards; `ccm_tools_is_premium()`; `CCM_TOOLS_PREMIUM_URL` constant

## v7.19.7
- **Auto-Flush Redis on Serializer/Compression Change**: Prevents deserialization crash when switching between serializers

## v7.19.6
- **"Add to wp-config.php" writes all Redis settings** including serializer, compression, async_flush, username, scheme, path, timeouts, disable_comment

## v7.19.5
- **Drop-In Runtime Diagnostics Panel**: Shows live `$wp_object_cache->info()` values — actual serializer/compression in use, hit/miss stats, Redis call count and timing

## v7.19.4
- Fixed false error when saving unchanged Redis settings (`update_option()` returns false on no-change)

## v7.19.3
- Redis settings page reloads 800ms after save so Active Configuration table reflects new values

## v7.19.2
- Fixed Redis password field browser autofill (`autocomplete="new-password"`)

## v7.19.1
- Redis settings form visible when disconnected — don't lock users out after saving bad config

## v7.19.0
- **Redis Object Cache — Complete Rewrite** (`CCM_Redis_Object_Cache` class): SCAN-based flush, pipelined bulk ops, serializer support (php/igbinary/msgpack), compression (none/lzf/lz4/zstd), async flush (UNLINK), ACL auth, retry/reconnect, HTML footnote, `wp_cache_has()`, `wp_cache_remember()`, `wp_cache_supports()`, Site Health integration, drop-in version checking + update button

## v7.18.12
- Disk card explains quota fallback clearly when `quota` command unavailable

## v7.18.11
- Disk info prioritizes cPanel account quota over server filesystem totals

## v7.18.10
- Standardized collation to `utf8mb4_unicode_520_ci` (WordPress core default); "Update Table Collations" checkbox auto-unchecks when 0 tables need it

## v7.18.9
- Progressive per-table DB optimization (one AJAX call per table, prevents timeout on 100+ tables)
- Redis stats: replaced `KEYS` with `SCAN` to prevent blocking on large instances

## v7.18.8
- Visual regression: `layout_ok: false` now triggers rollback regardless of severity; failed check now fails-safe to rollback

## v7.18.7
- Robust screenshot capture: 3-attempt retry pipeline (Puppeteer × 2, Chromium CLI fallback); 120s global JS timeout; `diagnoseScreenshotCapability()` for debugging

## v7.18.6
- Step indicators fit on one line: `flex-wrap: nowrap`, removed `min-width`, reduced sizes

## v7.18.5
- Puppeteer screenshot capture: `waitUntil: networkidle0`, programmatic scroll, `fullPage: true`; falls back to Chromium CLI if Node.js unavailable

## v7.18.4
- Fixed `--virtual-time-budget` flag breaking screenshot capture (incompatible with `--screenshot` in headless=new mode)

## v7.18.3
- Visual Check retries on failure (was silently skipping); screenshot capture added virtual time budget for lazy-loaded content

## v7.18.2
- Parallelized baseline screenshots + console check with PageSpeed tests (saves 30–90s)

## v7.18.1
- AI token optimization ~40%: CSS minification before sending, reduced caps, URL truncation, page resource caching in optimize sessions, removed duplicate learnings from plugin side

## v7.18.0
- **Cross-Site AI Learning**: Optimization runs stored in hub `optimization_runs` table; `buildHubLearnings()` aggregates cross-site win/loss data; Hub admin Optimizations page

## v7.17.13
- Live UI update when pre-flight enables Performance Optimizer

## v7.17.12
- Fixed plugin update loop (header Version stuck at 7.17.9); fixed hub iteration screenshots timing window

## v7.17.11
- Searchable page picker for URL to test (type-ahead, keyboard nav, post type badges)

## v7.17.10
- Visual regression fail-safe: JSON parse fallback defaults to `layout_ok: false, severity: critical`

## v7.17.9
- Fixed side-by-side screenshot height mismatch (`max-height: 520px; object-fit: cover`)

## v7.17.8
- Auto-scroll to screenshots during optimization (`scrollIntoView` on baseline and after captures)

## v7.17.7
- **AI Visual Regression Detection**: Before/after screenshots sent to Claude Vision; automatic rollback on critical layout regression; three severity levels; new "Visual Check" step

## v7.17.6
- Per-iteration screenshot capture during One-Click Optimize; "After (Iter N)" labeling

## v7.17.5
- Screenshot capture timeout fix (`set_time_limit(120)`); backward compatible with both `url` and `data_uri` response formats

## v7.17.4
- **Screenshot storage rewrite**: File-based JPEGs saved on hub with UUIDs; hub returns URLs not base64; `screenshots` DB table; Hub admin Screenshots page

## v7.17.3
- Interactive screenshot lightbox: Before shows immediately after baseline; After slides in when ready; Esc/click-outside to close

## v7.17.2
- Screenshots moved into Before/After Comparison block (same results section as score tables)

## v7.17.1
- **Visual Screenshot Comparison**: Headless Chromium captures desktop (1920×1080) + mobile (375×812); PNG→JPEG via GD; hub endpoint `POST /api/v1/screenshot/capture`

## v7.17.0
- **Console Error Checking**: Headless Chromium captures JS errors before and after optimization; automatic rollback if new errors introduced; hub endpoint `POST /api/v1/console/check`

## v7.16.2
- Multiple screenshot uploads in AI Chat (up to 5); error log context injected into AI system prompt; `max_tokens` increased to 8,192

## v7.16.1
- AI Chat screenshot upload via Claude Vision API (PNG/JPEG/GIF/WebP, max 5 MB)

## v7.16.0
- **Video Optimization**: Video Lazy Load facade (click to play), Video Preload: None; Dashboard PageSpeed UI redesign with hero circle

## v7.15.1
- Fixed "key.replace is not a function" in Recent Results History (enriched change objects vs string keys)

## v7.15.0
- **PageSpeed Scores on Dashboard**: Async-loaded latest Mobile/Desktop scores; `ccm_tools_ai_hub_get_latest_scores` AJAX handler

## v7.14.2
- Reset step indicators to pending state on rollback iteration

## v7.14.1
- Fixed step indicator icon rendering artifacts (`font-size: 0` on element, explicit `14px` on `::after`)

## v7.14.0
- **AI Troubleshooter Chat**: Floating chat widget; conversational AI with all 30+ setting descriptions; markdown rendering; hub endpoint `api/v1/ai/chat`

## v7.13.1
- **AI Learning Memory**: `ccm_tools_build_learnings_context()` reads past runs; categorizes win/rollback settings with score deltas; enriched run data stores from/to values

## v7.13.0
- **Smart Rollback Algorithm**: Net-positive evaluation (keeps if both within PSI noise ±3pts OR net positive AND neither dropped >15pts); snapshot-based comparison
- **Pre-flight Tool Check**: Auto-enables .htaccess, WebP, Redis, Performance Optimizer before optimization

## v7.12.9
- Updated hub models: Sonnet 4.6, Opus 4.6, Haiku 4.5

## v7.12.8
- Max optimization iterations increased from 3 to 10

## v7.12.7
- Redesigned Recent Results History (before→after scores, color-coded, outcome badges, settings tags)
- Optimization Run Persistence (`ccm_tools_ai_save_run` AJAX handler; stores up to 20 runs in `wp_options`)

## v7.12.6
- Live Activity Log (terminal-style, Catppuccin Mocha theme); Accordion PageSpeed results; Google-standard color-coded scores; Remaining Recommendations panel

## v7.12.5
- Live settings update after AI apply; fixed `enabled` key → `#perf-master-enable` DOM mapping

## v7.12.4
- Fully automated One-Click Optimize (removed manual review step)

## v7.12.3
- **Iterative AI Optimization with Rollback**: Hub `ai-optimize.php` deep analysis rewrite; score-drop aware retest; plugin snapshot/rollback AJAX handlers; JS iterative loop with up to 3 retries

## v7.12.2
- Button uniformity fixes; `.ccm-ai-connection-row` flex layout; mobile responsive rules

## v7.12.1
- **Deep AI Analysis**: Hub fetches live page HTML; Critical CSS generation; JS defer/delay script analysis; preconnect/DNS prefetch domain identification; LCP image preload detection

## v7.12.0
- **Combined AI + Performance pages**; One-Click Optimize with dual strategy (Mobile + Desktop); 8-step progress indicator; Before/After comparison; Dual strategy result tabs

## v7.11.2
- Fixed "Hub vunknown" (flat hub response format); fixed `result_id` TypeError; defensive JS null checks

## v7.11.1
- Fixed AI page buttons not working (wrong wrapper class `ccm-wrap` vs `ccm-tools`)

## v7.11.0
- **AI Performance Hub**: Hub application + API v1 endpoints (health, pagespeed/test, pagespeed/results, ai/analyze, ai/optimize); plugin-side `inc/ai-hub.php`

## v7.10.15
- 6 Performance Optimizer bug fixes: CSS exclude list missing from import, YouTube facades on non-singular pages, Heartbeat only on frontend, admin test mode `?ccm_test_perf=1`, static settings cache, removed dead emoji code

## v7.10.14
- Added AVIF MIME type support to .htaccess rules

## v7.10.13
- Hardened Async CSS against double-processing (checks single + double quote variants); fixed ImageMagick `/tmp/` path error (sets `MAGICK_TMPDIR` to uploads)

## v7.10.12
- 4 Performance Optimizer bug fixes: removed dead font-display override CSS, fixed `?ver=` query string stripping, fixed LCP fetchpriority on `wp_get_attachment_image()` calls, cleaned up dead LCP code

## v7.10.11
- Cleaned up remaining libvips references

## v7.10.10
- **Fixed Async CSS Loading**: Reimplemented with print media trick (`media="print"` + `onload="this.media='all'"`); added exclude list; added noscript fallback

## v7.10.9
- Fixed Detect Scripts giving identical results for Defer and Delay (now accepts `target` parameter)

## v7.10.8
- Removed libvips support (causing 500 errors); WebP now uses ImageMagick or GD only

## v7.9.5
- Fixed WebP conversion after reset (failed transients not cleared; URL matching fallback)

## v7.9.4
- Fixed WebP picture tag URL matching with `/wp-content/uploads/` fallback

## v7.9.3
- Fixed WebP for page builder/theme images (output buffering replaces filter-only approach)

## v7.9.2
- Font Display: Swap for self-hosted fonts via output buffer injection into `@font-face` rules

## v7.9.1
- Import/Export Performance Settings (JSON with metadata, validation, auto-refresh on import)

## v7.9.0
- New performance settings: Font Display Swap, Speculation Rules API, Critical CSS, Disable Block Library CSS, Disable jQuery Migrate, Disable WooCommerce Cart Fragments, Reduce Heartbeat, Head Cleanup (XML-RPC, RSD, Shortlink, REST API, oEmbed)

## v7.8.6
- WooCommerce Redis Optimization (cache cart fragments, persistent cart, session caching, Product/Session TTL)

## v7.8.5
- Redis stats: fixed memory calc with `MEMORY USAGE` command; added Cache Groups and Avg. TTL stats

## v7.8.4
- Site-specific Redis statistics (filtered by key prefix, not server-wide)

## v7.8.3
- Redis cache statistics auto-refresh after flush

## v7.8.2
- Redesigned Redis configuration UI with CSS Grid; responsive breakpoints

## v7.8.1
- Security improvements: Redis extension checks on AJAX handlers, `realpath()` path validation, comprehensive input validation for all Redis settings

## v7.8.0
- **Redis Object Cache**: Custom drop-in replacing third-party plugins; tcp/tls/unix support; `wp-config.php` constants; one-click install/uninstall; selective + full flush; multisite support

## v7.7.0
- Reorganized menu order: System Info, Database, .htaccess, WebP, Performance, WooCommerce, Error Log; WooCommerce item conditional on plugin active

## v7.6.9 – v7.6.7
- Fixed picture tag layout breaking (3 iterations): final fix uses `display:block;width:100%;height:100%` on `<picture>` + `style="width:100%;height:100%"` on inner `<img>`

## v7.6.1
- Fixed WebP background image conversion for theme templates (switched to output buffering)

## v7.6.0
- WebP background image conversion (CSS inline styles and `<style>` blocks)

## v7.5.5 – v7.5.9
- Fixed srcset stripping with WebP; fixed blurry full-width images; consistent navigation menu; picture tag double-wrapping fix

## v7.3.0 – v7.4.0
- **WebP Image Converter** stable release (GD/ImageMagick, bulk convert, on-demand, picture tags, WooCommerce)
- **Performance Optimizer** initial release (defer/delay JS, async CSS, critical CSS, resource hints, query strings, emoji, dashicons, iframe lazy, YouTube facades)

## v7.2.13 – v7.2.19
- WebP improvements: on-demand conversion, WooCommerce hooks, picture tag conversion
- Error Log: fixed Show Errors Only filter

## v7.0.3 (Security Release)
- **CRITICAL**: Removed hardcoded GitHub API token; **HIGH**: Fixed SQL injection in table name handling; added `ccm_tools_validate_table_name()` whitelist validation

## v7.0.0 – v7.0.2
- Complete UI rewrite: pure CSS/vanilla JS, removed jQuery/Bootstrap/FontAwesome
- Deferred TTFB loading; toast notification system
