# CCM Tools — Changelog

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
