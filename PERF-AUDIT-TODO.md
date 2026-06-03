# Performance Audit TODO — AI Reference Doc
> Auto-updated after each version. Restart-safe: agent can read this file to resume work.
> Last updated: v7.42.1 released ✅ (duplicate-folder self-deactivation fix + wrapper-folder zips)

---

## Status Summary

| Version | Items | Status |
|---------|-------|--------|
| v7.23.0 | #5, #10, #15 | ✅ Released |
| v7.24.0 | #12, #24, #26 + JS bugfix | ✅ Released |
| v7.25.0 | #1, #4, #6 | ✅ Released |
| v7.26.0 | #7, #8, #9, #11 | ✅ Released |
| v7.27.0 | #13, #16, #17 | ✅ Released |
| v7.28.0 | #18, #19, #20, #21 | ✅ Released |
| v7.29.0 | #22, #25 (#23 skipped — duplicate of remove_query_strings) | ✅ Released |
| v7.30.0 | #27, #28 | ✅ Released |
| v7.41.4 | Redis `alloptions` 4 GB OOM hardening (P1–P4) | ✅ Released |
| v7.42.0 | Redis drop-in lifecycle automation + one-step Save | ✅ Released |
| v7.42.1 | Duplicate-folder self-deactivation fix + wrapper-folder release zips | ✅ Released |

**Permanently skipped:** #2 (ES module type="module"), #3 (modulepreload) — unsafe for arbitrary WP scripts.

---

## Completed Items

### ✅ Redis drop-in lifecycle automation + one-step Save (v7.42.0)
Files: `ccm.php` (activation/deactivation hooks), `inc/redis-object-cache.php`, `inc/ajax-handlers.php`
- **Auto-replace drop-in on update:** `upgrader_process_complete` + `admin_init` self-heal copy the bundled drop-in over the deployed one whenever it's newer (connection-less, won't clobber a foreign drop-in). This is what makes future fixes like v7.41.4 actually reach sites without a manual reinstall.
- **Activation:** reinstalls/refreshes the drop-in when `enabled` (covers the update deactivate→reactivate dance).
- **Disable/deactivation:** removes the drop-in AND strips the wp-config block (clean teardown; silent during updates so caching survives them).
- **One-step Save:** Save rewrites wp-config + refreshes the drop-in when Redis is enabled; Enable writes wp-config too. wp-config write skipped when unchanged; backups pruned to last 5.
- New helpers: `build_config_array()`, `managed_constants()`, `refresh_dropin()`, `remove_config()`, `prune_backups()`.

### ✅ Redis `alloptions` 4 GB OOM hardening (v7.41.4)
Files: `assets/object-cache.php` (P1–P3), `inc/redis-object-cache.php` (P4)
- **Root cause:** LZ4+igbinary failed to round-trip the `alloptions` blob; the corrupt length prefix made `unserialize()` try to allocate 4,295,229,440 bytes → sitewide fatals (TSB, 2026-05-28 + 2026-06-03). v7.39.10 fixed only one trigger.
- **P1:** drop-in no longer persists `options`/`site-options` to Redis (`$skip_persistent_groups`); override via `WP_REDIS_PERSIST_OPTIONS`. This alone closes the issue.
- **P2:** `get()` rejects non-array `options:alloptions`/`options:notoptions` as a cache miss.
- **P3:** `ensure_config_consistency()` auto-flushes on serializer/compression drift (sentinel key via `rawCommand`).
- **P4:** admin UI warns on LZ4+igbinary and documents the skipped groups.
- Drop-in `@version` 7.19.0 → 7.41.4 (triggers the outdated-drop-in reinstall notice).

### ✅ #1 — Inline Small Scripts & Styles (v7.25.0)
Settings: `inline_small_scripts`, `inline_small_styles`, `inline_threshold_kb`
- Replaces `<script src>` / `<link rel=stylesheet>` with inline blocks when file ≤ threshold KB
- Eliminates HTTP round-trips for small assets

### ✅ #4 — Inject Image Dimensions (v7.25.0)
Setting: `inject_image_dimensions`
- Adds missing `width`/`height` to local `<img>` tags in post content
- Eliminates CLS (Cumulative Layout Shift)

### ✅ #5 — Lazy Load Images (v7.23.0)
Setting: `lazy_load_images`
- Adds `loading="lazy"` to all non-LCP images

### ✅ #6 — Inject Responsive srcset (v7.25.0)
Setting: `inject_srcset`
- Adds missing `srcset`/`sizes` to local `<img>` tags using WP attachment metadata

### ✅ #10 — Async Image Decoding (v7.23.0)
Setting: `image_decoding_async`
- Adds `decoding="async"` to non-LCP images

### ✅ #12 — Remove Generator Tag (v7.24.0)
Setting: `remove_generator_tag`
- Strips `<meta name="generator">` from `<head>`

### ✅ #15 — Prefetch on Hover (v7.23.0)
Setting: `prefetch_on_hover`
- Fires `<link rel=prefetch>` 100ms after hover on same-origin links

### ✅ #24 — Disable Admin Bar (Frontend) (v7.24.0)
Setting: `disable_admin_bar`
- Hides WP admin toolbar on public-facing pages

### ✅ #26 — Remove Adjacent Post Links (v7.24.0)
Setting: `remove_adjacent_post_links`
- Removes prev/next `<link>` tags and feed discovery links from `<head>`

### ✅ #7 — Minify HTML Output (v7.26.0)
Setting: `minify_html`
- Output buffer strips HTML comments and redundant whitespace; preserves pre/textarea/script/style

### ✅ #8 — Preload Key Requests (v7.26.0)
Settings: `preload_key_requests` (toggle), `preload_key_urls` (array)
- Adds `<link rel="preload">` in `wp_head` for configured URLs; auto-detects `as` type from extension

### ✅ #9 — Remove wp-embed.min.js (v7.26.0)
Setting: `disable_wp_embed`
- Deregisters `wp-embed` script and removes `wp_oembed_add_host_js` from `wp_head`

### ✅ #11 — Self-host Google Fonts (v7.26.0)
Setting: `self_host_google_fonts`
- Downloads Google Fonts CSS + WOFF2 files to `uploads/ccm-fonts/`; rewrites URLs locally; 30-day cache

---

## ✅ v7.27.0 — Resource Hints & Third-party (Released)

### ✅ #13 — Preload LCP Background Image
**Setting key:** `preload_css_bg_image` (boolean), `preload_css_bg_url` (string URL)
**Type:** toggle + URL input
**Implementation:** Output `<link rel="preload" as="image" href="..." fetchpriority="high">` in `wp_head` priority 1. Targets background-image LCP elements that `lcp_preload` misses (which only handles `<img>` tags).
**Impact:** Fixes "Largest Contentful Paint image was not preloaded" for hero sections using CSS background-image instead of `<img>`. Can improve LCP by 200–1000ms.
**Safety:** Low. Only affects pages that use this. No side effects if URL is wrong (browser just ignores it).

### ✅ #16 — Priority Hints on Multiple Above-Fold Images
**Setting key:** `priority_hints_above_fold` (boolean), `priority_hints_selectors` (string, CSS selectors)
**Type:** toggle + textarea for CSS selectors
**Implementation:** Output buffer — scan HTML for `<img>` tags matching configured selectors OR first N images in hero/banner containers; add `fetchpriority="high"` and remove `loading="lazy"`. Different from `lcp_fetchpriority` which only handles the very first image.
**Impact:** Above-fold images in carousels, grid thumbnails, product images — each gets priority hints. Improves FID/LCP for image-heavy above-fold layouts.
**Safety:** Low. Only adds an attribute; doesn't change loading behaviour for unsupported browsers.

### ✅ #17 — Delay Third-party Scripts Until User Interaction
**Setting key:** `delay_third_party` (boolean), `delay_third_party_domains` (array)
**Type:** toggle + textarea (domains to delay; defaults: analytics, advertising networks)
**Implementation:** Output buffer — wrap matching `<script src="...">` external tags in the same "delay until interaction" logic used by `delay_js`, but scoped by domain instead of handle. Default domains: `googletagmanager.com`, `google-analytics.com`, `facebook.net`, `hotjar.com`, `intercom.io`, `crisp.chat`, `tawk.to`.
**Impact:** Removes all analytics/chat/tracking scripts from TBT/TTI calculations. Can reduce TBT by 200–2000ms depending on third-party scripts active.
**Safety:** Medium. Chat widgets may not appear until first scroll/click. Add note in UI.

---

## ✅ v7.28.0 — More Third-party & Caching

### ✅ #18 — Disable Gutenberg Block Editor Assets on Frontend
**Setting key:** `disable_gutenberg_frontend`
**Type:** boolean toggle
**Implementation:** `add_action('wp_enqueue_scripts', function() { wp_dequeue_style('wp-block-library'); wp_dequeue_style('wp-block-library-theme'); wp_dequeue_style('global-styles'); wp_dequeue_style('classic-theme-styles'); }, 100);`
**Note:** Different from `disable_block_css` which already exists — need to verify what `disable_block_css` currently does and whether this is a duplicate. If `disable_block_css` already covers this, skip and replace #18 with another item.
**Impact:** ~36 KB stylesheet removed. Strong PageSpeed "Eliminate render-blocking resources" win.
**Safety:** Medium. Sites using Gutenberg blocks need this OFF. Add clear warning in UI.

### ✅ #19 — WooCommerce Scripts Only on Shop Pages
**Setting key:** `woo_scripts_shop_only`
**Type:** boolean toggle  
**Implementation:** `add_action('wp_enqueue_scripts', function() { if (is_woocommerce() || is_cart() || is_checkout() || is_account_page()) return; wp_dequeue_script('wc-cart-fragments'); wp_dequeue_script('woocommerce'); wp_dequeue_script('wc-add-to-cart'); wp_dequeue_style('woocommerce-general'); ... }, 99);` — only shows when WooCommerce is active.
**Impact:** Removes ~100–200 KB of scripts/styles from all non-shop pages. Major win for content/blog heavy WooCommerce sites.
**Safety:** Medium. Test carefully with page builders that embed WC shortcodes on non-shop pages.

### ✅ #20 — Browser Cache Policy via Meta (No-htaccess Alternative)
**Setting key:** `cache_control_meta`
**Type:** boolean toggle (only relevant when .htaccess is not used/writable)
**Implementation:** `add_action('send_headers', function() { header('Cache-Control: public, max-age=31536000, immutable'); });` for static assets OR via `wp_headers` filter. Primarily useful on Nginx/LiteSpeed where .htaccess cache rules don't apply.
**Note:** If .htaccess caching is already active, show notice and skip. This is a fallback.
**Impact:** Fixes "Serve static assets with an efficient cache policy" warning on non-Apache hosts.
**Safety:** Low when scoped to static files only.

### ✅ #21 — Stale-While-Revalidate Cache Header
**Setting key:** `stale_while_revalidate`
**Type:** boolean toggle
**Implementation:** Add `stale-while-revalidate=86400` to the `Cache-Control` header for HTML pages via `send_headers` action. Allows browsers to serve stale cached pages while fetching fresh in background.
**Impact:** Perceived navigation speed improvement; reduces TTFB for repeat visitors.
**Safety:** Low. Standard HTTP caching directive; ignored by unsupported browsers.

---

## ✅ v7.29.0 — WP Cleanup & Miscellaneous (Released)

### ✅ #22 — Optimize WordPress Cron
**Setting key:** `disable_wp_cron` (boolean), `cron_interval` (select: 5/10/30/60 min)
**Type:** toggle + select
**Implementation:** When enabled, defines `DISABLE_WP_CRON` to `true` in a way that can be set from the plugin (by writing to wp-config.php similar to Redis constants). Also shows instructions for setting up a real server cron. Alternatively: `add_filter('cron_schedules', ...)` to reduce check frequency.
**Note:** Can't define constants after bootstrap — write to wp-config.php or show instructions.
**Impact:** Eliminates cron overhead (~50ms) on every page request. Real cron is more reliable.
**Safety:** Medium. Cron tasks stop running if server cron isn't configured. Clear warning required.

### ~~#23 — Remove WordPress Version from Scripts & Styles~~ — SKIPPED (duplicate)
**Reason:** Confirmed duplicate of existing `remove_query_strings` setting which already removes `?ver=` via `remove_query_arg('ver', $src)` on `style_loader_src` / `script_loader_src`. No separate setting needed. AI hub prompts corrected to remove the erroneous `remove_asset_versions` reference.

### ✅ #25 — Disable Author Archive Pages
**Setting key:** `disable_author_archives`
**Type:** boolean toggle
**Implementation:** `add_action('template_redirect', function() { if (is_author()) { wp_redirect(home_url(), 301); exit; } });`
**Impact:** Eliminates duplicate content pages (author pages duplicate post content). PageSpeed/SEO benefit. Reduces crawl budget waste.
**Safety:** Low-medium. Sites with genuine author profiles (news sites, multi-author blogs) should keep OFF. Add warning.

---

## ✅ v7.30.0 — INP / Interaction Optimizations (Released)

### ✅ #27 — Passive Event Listeners
**Setting key:** `passive_event_listeners`
**Type:** boolean toggle
**Implementation:** Inject a small inline script in `wp_head` that overrides `addEventListener` globally to add `{ passive: true }` for scroll, wheel, touchstart, touchmove events — unless the handler calls `preventDefault()`. Uses the standard overriding pattern.
**Impact:** Fixes "Does not use passive listeners to improve scrolling performance" PageSpeed audit. Improves scroll smoothness and INP score.
**Safety:** Medium. Some plugins rely on non-passive scroll listeners for parallax/sticky effects. These may break. Add option to exclude specific scripts.

### ✅ #28 — Reduce DOM Size
**Setting key:** `warn_dom_size` (info-only, no toggle)
**Type:** Informational panel showing current DOM node count (measured via async AJAX)
**Implementation:** New card in the Performance Optimizer page showing DOM statistics: total nodes, deepest nesting level, largest subtree. Fetches stats via a PHP function that parses the frontend HTML. Not a toggle — provides actionable guidance only (PageSpeed Insights recommendation: keep DOM < 1500 nodes).
**Impact:** Informational. Helps site owners understand if theme/page builder is bloating DOM.
**Note:** DOM size can't be reduced by a plugin setting — can only report it and link to relevant docs.

---

## Implementation Rules (for Agent Reference)

### Every version requires these file changes:
1. `inc/performance-optimizer.php` — new `$defaults` entries, new hooks in `ccm_tools_perf_init()`, new PHP functions, new UI rows/cards
2. `inc/ajax-handlers.php` — save handler entries + `$boolean_keys` array (boolean settings only; numeric/array settings use explicit sanitization)
3. `js/main.js` — `savePerfSettings()` entries, `PERF_SETTING_KEYS` entries, `aiSettingLabel()` entries
4. Version bump in: `ccm.php` (header + constant), `js/main.js` (header comment), `css/style.css` (header comment), `.github/copilot-instructions.md` (Current Version + Change Log)

### Release process (from ccm-tools root in PowerShell):
```powershell
git add -A; git commit -m "vX.Y.Z: description"; git push
Compress-Archive -Path "ccm.php","index.php","css","inc","js","img","assets" -DestinationPath "archive\ccm-tools-X.Y.Z.zip" -Force
Compress-Archive -Path "ccm.php","index.php","css","inc","js","img","assets" -DestinationPath "ccm-tools.zip" -Force
& "C:\Program Files\GitHub CLI\gh.exe" release create vX.Y.Z "archive\ccm-tools-X.Y.Z.zip" "ccm-tools.zip" --title "vX.Y.Z" --notes "## Changes in vX.Y.Z`n`n- ..."
```

### Numeric/array settings (NOT in `$boolean_keys`):
- `inline_threshold_kb` — `absint()` + `max(1, min(50, ...))`
- `preload_key_urls` — `array_map('esc_url_raw', ...)`
- `delay_third_party_domains` — `array_map('sanitize_text_field', ...)`
- `priority_hints_selectors` — `sanitize_textarea_field()`
- `heartbeat_interval`, `cron_interval` — `absint()` with range clamp

### WooCommerce-conditional settings:
- `woo_scripts_shop_only` — only render UI row when `class_exists('WooCommerce')`
- Same pattern as existing WooCommerce Redis section in redis-object-cache.php
