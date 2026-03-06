# CCM Tools - GitHub Copilot Instructions

## Project Overview

**CCM Tools** is a WordPress utility plugin designed for site administrators to monitor and optimize their WordPress installations. It provides comprehensive system information, database management tools, and .htaccess optimization features.

- **Current Version:** 7.20.8
- **Requires WordPress:** 6.0+
- **Requires PHP:** 7.4+
- **Tested up to:** WordPress 6.8.2

## Architecture

### Technology Stack

- **Frontend:** Pure vanilla JavaScript (ES6+) - no jQuery or external libraries
- **Styling:** Pure CSS with CSS Custom Properties (variables) - no Bootstrap or frameworks
- **Backend:** PHP 7.4+ with WordPress coding standards
- **AJAX:** Native Fetch API with WordPress admin-ajax.php

### File Structure

```
ccm-tools/
├── ccm.php                 # Main plugin file, admin page rendering
├── css/
│   └── style.css          # Pure CSS stylesheet with custom properties
├── js/
│   └── main.js            # Vanilla JavaScript, event handlers, AJAX
├── inc/
│   ├── ai-hub.php         # AI Performance Hub plugin-side integration
│   ├── ajax-handlers.php  # All WordPress AJAX action handlers
│   ├── error-log.php      # Error log viewer and management
│   ├── htaccess.php       # .htaccess optimization functions
│   ├── optimize.php       # Database optimization tools
│   ├── performance-optimizer.php # Performance optimizer (experimental)
│   ├── premium.php        # Premium subscription management & feature gating
│   ├── redis-object-cache.php # Redis object cache management
│   ├── system-info.php    # System information gathering (TTFB, disk, etc.)
│   ├── tableconverter.php # Database table conversion (InnoDB/utf8mb4)
│   ├── update.php         # Plugin update checker
│   ├── webp-converter.php # WebP image converter
│   └── woocommerce-tools.php # WooCommerce-specific utilities
├── img/                   # Image assets
└── assets/
    └── object-cache.php   # WordPress object cache drop-in for Redis
```

## Key Features

### 1. System Information Dashboard
- Server environment details (PHP, MySQL, WordPress versions)
- Disk usage visualization with progress bar
- Database size and table count
- Memory limits and configuration
- TTFB (Time To First Byte) measurement
- Redis cache status and management

### 2. Database Tools
- **Table Converter:** Convert tables to InnoDB engine and utf8mb4_unicode_ci collation
- **Database Optimizer:** Optimize tables with collation updates
- Progressive processing with real-time progress UI

### 3. .htaccess Management
- Add/remove optimized .htaccess rules
- Optional security hardening rules
- Backup and restore functionality

### 4. Error Log Viewer
- Multiple log file support
- Real-time auto-refresh (30-second intervals)
- Error highlighting with color coding
- Filter by error type (errors only mode)
- Download and clear log functionality

### 5. Debug Mode Controls
- Toggle WP_DEBUG on/off
- Toggle WP_DEBUG_LOG
- Toggle WP_DEBUG_DISPLAY
- Direct wp-config.php modifications

### 6. WooCommerce Tools (when WooCommerce active)
- Admin payment method toggle
- Store configuration overview

### 7. Redis Object Cache
- Custom WordPress object cache implementation (replaces third-party plugins)
- WordPress object cache drop-in (`object-cache.php`) with full API support
- Redis connection management with multiple connection schemes (tcp, tls, unix)
- Configuration via wp-config.php constants or database settings
- One-click drop-in installation/uninstallation
- Cache flushing (selective site flush or full Redis flush)
- Real-time statistics (memory usage, hit/miss ratio, keys count)
- Multisite support with separate prefixes per blog
- Global cache groups for network-wide data
- Automatic fallback to in-memory cache if Redis unavailable

### 8. Premium Subscription System
- **Free vs Premium feature gating** with clear upgrade paths
- **Free tier:** System Info, Database Tools, .htaccess, Error Log, Debug, WebP, Performance Optimizer, Basic Redis (host/port/password/db/key_salt/max_ttl/selective_flush/enable/disable/flush)
- **Premium tier:** AI Performance Hub (all features), Advanced Redis (serializer, compression, async_flush, ACL, TLS, WooCommerce Redis, timeouts, runtime diagnostics, HTML footnote)
- **Premium status check flow:** wp-config.php constant override → transient cache (12h TTL) → hub API call
- **Hub API:** `GET /api/v1/premium/status` with `X-Api-Key` header for subscription verification
- **Stripe integration:** Recurring monthly payments, webhook-driven status updates on the hub
- **Developer override:** `define('CCM_TOOLS_PREMIUM', true)` in wp-config.php for testing
- **Defense-in-depth gating:** Both render-level (don't show UI) and AJAX-level (reject requests)
- Premium badge in header (green "Premium" or amber "Free" link)
- Dashboard comparison card showing Free vs Premium features
- Upsell cards on locked sections with feature lists and upgrade CTA
- Premium website: `CCM_TOOLS_PREMIUM_URL` constant (default: `https://premium.clickclickmedia.com.au`)

## Coding Conventions

### Version Numbering
- **Format:** `x.y.z` (Major.Minor.Patch)
- **Patch version (z) can go up to 999** before incrementing minor version
  - Example progression: 7.9.99 → 7.9.100 → 7.9.101 → ... → 7.9.999 → 7.10.0
- **Increment after each edit:**
  - Patch (z): Bug fixes, small changes (can go 0-999)
  - Minor (y): New features, significant improvements
  - Major (x): Breaking changes, complete rewrites
- **Files to update:** `ccm.php` (header + constant), `js/main.js`, `css/style.css`

### PHP Standards
```php
// Function naming: ccm_tools_action_name()
function ccm_tools_get_database_size() { }

// AJAX handlers: ccm_tools_ajax_action_name()
function ccm_tools_ajax_measure_ttfb() { }

// Always verify nonces
check_ajax_referer('ccm-tools-nonce', 'nonce');

// Sanitize input
$value = sanitize_text_field($_POST['param']);

// Escape output
echo esc_html($variable);
```

### JavaScript Standards
```javascript
// Use vanilla JS only - no jQuery
const element = document.querySelector('#id');
const elements = document.querySelectorAll('.class');

// AJAX with Fetch API
const response = await ajax('action_name', { data: value });

// Event delegation for dynamic elements
document.addEventListener('click', (e) => {
    if (e.target.matches('#button-id')) {
        // handle click
    }
});
```

### CSS Standards
```css
/* Use CSS custom properties for theming */
:root {
    --ccm-primary: #3b82f6;
    --ccm-success: #22c55e;
}

/* BEM-like naming with ccm- prefix */
.ccm-card { }
.ccm-card-header { }
.ccm-button { }
.ccm-button-primary { }
```

## AJAX Actions Reference

| Action | Handler Function | Description |
|--------|------------------|-------------|
| `ccm_tools_measure_ttfb` | `ccm_tools_ajax_measure_ttfb()` | Measure TTFB |
| `ccm_tools_update_memory_limit` | `ccm_tools_ajax_update_memory_limit()` | Update WP memory limit (param: `limit`) |
| `ccm_tools_get_error_log` | `ccm_tools_ajax_get_error_log()` | Get error log content |
| `ccm_tools_clear_error_log` | `ccm_tools_ajax_clear_error_log()` | Clear error log |
| `ccm_tools_add_htaccess` | `ccm_tools_ajax_add_htaccess()` | Add .htaccess optimizations |
| `ccm_tools_remove_htaccess` | `ccm_tools_ajax_remove_htaccess()` | Remove .htaccess optimizations |
| `ccm_tools_convert_single_table` | `ccm_tools_ajax_convert_single_table()` | Convert single DB table |
| `ccm_tools_optimize_single_table` | `ccm_tools_ajax_optimize_single_table()` | Optimize single DB table |
| `ccm_tools_update_debug_mode` | `ccm_tools_ajax_update_debug_mode()` | Toggle WP_DEBUG |
| `ccm_tools_redis_enable` | `ccm_tools_ajax_redis_enable()` | Install Redis object-cache.php drop-in |
| `ccm_tools_redis_disable` | `ccm_tools_ajax_redis_disable()` | Uninstall Redis object-cache.php drop-in |
| `ccm_tools_redis_flush` | `ccm_tools_ajax_redis_flush()` | Flush Redis cache (param: `flush_type`) |
| `ccm_tools_optimize_table_task` | `ccm_tools_ajax_optimize_table_task()` | Optimize/collate a single table (progressive) |
| `ccm_tools_redis_test` | `ccm_tools_ajax_redis_test()` | Test Redis connection |
| `ccm_tools_redis_save_settings` | `ccm_tools_ajax_redis_save_settings()` | Save Redis configuration settings |
| `ccm_tools_redis_add_config` | `ccm_tools_ajax_redis_add_config()` | Add Redis constants to wp-config.php |
| `ccm_tools_redis_get_stats` | `ccm_tools_ajax_redis_get_stats()` | Get Redis server statistics |
| `ccm_tools_configure_redis` | `ccm_tools_ajax_configure_redis()` | Configure Redis settings (legacy) |
| `ccm_tools_save_webp_settings` | `ccm_tools_ajax_save_webp_settings()` | Save WebP converter settings |
| `ccm_tools_get_webp_stats` | `ccm_tools_ajax_get_webp_stats()` | Get WebP conversion statistics |
| `ccm_tools_get_unconverted_images` | `ccm_tools_ajax_get_unconverted_images()` | Get images pending conversion |
| `ccm_tools_convert_single_image` | `ccm_tools_ajax_convert_single_image()` | Convert single image to WebP |
| `ccm_tools_test_webp_conversion` | `ccm_tools_ajax_test_webp_conversion()` | Test WebP conversion with upload |
| `ccm_tools_save_perf_settings` | `ccm_tools_ajax_save_perf_settings()` | Save performance optimizer settings |
| `ccm_tools_get_perf_settings` | `ccm_tools_ajax_get_perf_settings()` | Get performance optimizer settings |
| `ccm_tools_ai_hub_save_settings` | `ccm_tools_ajax_ai_hub_save_settings()` | Save AI Hub connection settings |
| `ccm_tools_ai_hub_test_connection` | `ccm_tools_ajax_ai_hub_test_connection()` | Test connection to AI Hub |
| `ccm_tools_ai_hub_run_pagespeed` | `ccm_tools_ajax_ai_hub_run_pagespeed()` | Run PageSpeed test via hub |
| `ccm_tools_ai_hub_get_results` | `ccm_tools_ajax_ai_hub_get_results()` | Get cached PageSpeed results |
| `ccm_tools_ai_hub_ai_analyze` | `ccm_tools_ajax_ai_hub_ai_analyze()` | AI analysis of PageSpeed result |
| `ccm_tools_ai_hub_ai_optimize` | `ccm_tools_ajax_ai_hub_ai_optimize()` | Full AI optimization session |
| `ccm_tools_ai_apply_changes` | `ccm_tools_ajax_ai_apply_changes()` | Apply selected AI recommendations to perf settings |
| `ccm_tools_ai_save_run` | `ccm_tools_ajax_ai_save_run()` | Save optimization run summary to wp_options + hub |
| `ccm_tools_ai_preflight` | `ccm_tools_ajax_ai_preflight()` | Pre-flight check of server-side tool status |
| `ccm_tools_ai_enable_tool` | `ccm_tools_ajax_ai_enable_tool()` | Enable a server-side tool (htaccess, webp, redis, performance) |
| `ccm_tools_ai_chat` | `ccm_tools_ajax_ai_chat()` | Send message to AI troubleshooting assistant |
| `ccm_tools_ai_hub_visual_compare` | `ccm_tools_ajax_ai_hub_visual_compare()` | AI visual regression detection (before/after screenshots) |
| `ccm_tools_ai_hub_console_check` | `ccm_tools_ajax_ai_hub_console_check()` | Check URL for JS console errors via headless Chromium |
| `ccm_tools_ai_hub_get_latest_scores` | `ccm_tools_ajax_ai_hub_get_latest_scores()` | Get latest PageSpeed scores for dashboard widget |
| `ccm_tools_premium_refresh` | `ccm_tools_ajax_premium_refresh()` | Clear cache and re-check premium subscription status |

## Performance Considerations

### Deferred Loading
- TTFB measurement is loaded via AJAX after page render (not blocking)
- Use `data-auto-load="true"` attribute for elements that should load asynchronously

### Progressive Operations
- Database operations process tables one-by-one with progress feedback
- Prevents timeouts on large databases
- Shows real-time progress with percentage

### Caching
- System info gathered on demand
- No persistent caching to ensure fresh data

## UI Components

### Toast Notifications
```javascript
showNotification('Message text', 'success'); // success, error, warning, info
```

### Spinners
```html
<div class="ccm-spinner"></div>
<div class="ccm-spinner ccm-spinner-small"></div>
```

### Status Classes
```css
.ccm-success { color: var(--ccm-success); }
.ccm-warning { color: var(--ccm-warning); }
.ccm-error { color: var(--ccm-error); }
.ccm-info { color: var(--ccm-info); }
```

## Testing Checklist

When making changes, verify:
- [ ] Dashboard loads quickly (TTFB deferred)
- [ ] Auto-refresh works on error log page
- [ ] Highlight Errors toggle functions
- [ ] Show Errors Only filter works
- [ ] Memory limit updates correctly
- [ ] TTFB refresh button works
- [ ] Database operations show progress
- [ ] Notifications appear for all actions
- [ ] No console errors
- [ ] No PHP notices/warnings

## Branch Strategy

- `main` - Production-ready code
- `feature/*` - Feature development branches
- Current feature branch: `feature/modern-pure-ui` (v7.x pure CSS/JS rewrite)

## Release Process

After completing changes:
1. **Update version numbers** in `ccm.php` (header + constant), `js/main.js`, `css/style.css`
2. **Update this file** (`copilot-instructions.md`) with:
   - Current version number in Project Overview
   - Change log entry for the new version
3. **Commit and push** to GitHub:
   ```bash
   git add -A
   git commit -m "Description of changes (vX.Y.Z)"
   git push
   ```
4. **Build release zips**:
   ```powershell
   # Versioned zip in archive folder
   Compress-Archive -Path "ccm.php", "index.php", "css", "inc", "js", "img", "assets" -DestinationPath "archive\ccm-tools-X.Y.Z.zip" -Force
   # Master zip (always latest) in root
   Compress-Archive -Path "ccm.php", "index.php", "css", "inc", "js", "img", "assets" -DestinationPath "ccm-tools.zip" -Force
   ```
5. **Create GitHub release** (required for WordPress auto-updates):
   ```powershell
   & "C:\Program Files\GitHub CLI\gh.exe" release create vX.Y.Z "archive\ccm-tools-X.Y.Z.zip" "ccm-tools.zip" --title "vX.Y.Z" --notes "## Changes in vX.Y.Z

   - Change 1
   - Change 2"
   ```
   > **Important:** Always include `ccm-tools.zip` (non-versioned) as an asset so the stable download URL works:
   > `https://github.com/ClickClickMedia/ccm-tools/releases/latest/download/ccm-tools.zip`

### Release File Structure
- `ccm-tools.zip` - Master zip in root (always latest version, git ignored)
- `archive/` - Contains all versioned zips (git ignored folder)
  - `ccm-tools-X.Y.Z.zip` - Versioned releases for GitHub

## Change Log (Recent)

### v7.20.8
- **"Add to wp-config.php" Now Writes Scheme & Timeout Constants Always**
  - `WP_REDIS_SCHEME`, `WP_REDIS_TIMEOUT`, and `WP_REDIS_READ_TIMEOUT` are now always written to wp-config.php regardless of whether they match defaults
  - Previously these were skipped when at default values (`tcp`, `1`), causing the Active Configuration table to show "PLUGIN SETTINGS" instead of "WP-CONFIG.PHP"
  - Ensures consistent source display and guarantees the drop-in reads explicit values from wp-config.php
  - Existing constants are still skipped (safe to click again)

### v7.20.7
- **Redis Key Prefix/Salt Generate Button**
  - Added "Generate" button next to the Key Prefix/Salt input field in Redis Cache Settings
  - Generates a unique salt from the site hostname + 8 random hex characters (e.g. `example_com_a3f1b2c4_`)
  - Uses `crypto.getRandomValues()` for cryptographically secure random bytes
  - Eliminates guesswork for users who don't know what value to enter

### v7.20.6
- **Dynamic Stripe Price on Premium Page**
  - Comparison cards now display the actual Stripe subscription price instead of hardcoded "Contact Us"
  - New `ccm_tools_premium_get_pricing()` function fetches pricing from hub API (`GET /api/v1/premium/pricing`) with transient caching (12h TTL)
  - Pricing also extracted from the premium status response when available (piggybacks on existing hub call)
  - Falls back to `$49` if hub pricing data is unavailable
  - Hub expected response: `{ formatted: "$49", amount: 4900, currency: "aud", interval: "month" }`
  - Handles sales/promotions automatically — price updates whenever Stripe price changes
- **Fixed Subscription Status Showing "Free" When Premium is Active**
  - Root cause: saving the API key via `ccm_tools_ajax_ai_hub_save_settings` did NOT clear the premium status transient cache
  - Flow: user visits Premium page (no key → cached as `inactive`) → saves API key → page reloads → stale transient returns `inactive` → shows "Free"
  - Save settings handler now calls `ccm_tools_premium_clear_cache()` after saving, forcing a fresh hub check on next page load
  - Also clears the new `ccm_tools_premium_pricing` transient alongside the existing status/details transients
- **Hide Comparison Cards When Premium is Active**
  - `ccm_tools_render_premium_comparison()` now returns early (renders nothing) when `ccm_tools_is_premium()` is true
  - Removed stale `$is_premium` variable references from comparison rendering (dead code after early return)
  - Premium subscribers see only the Subscription Status card with active features, manage subscription, and refresh button
- **Refactored Hub Premium Check to Use Shared Request Function**
  - `ccm_tools_premium_check_with_hub()` now uses `ccm_tools_ai_hub_request()` instead of duplicating HTTP call logic
  - Ensures consistent auth headers (`X-CCM-Api-Key`, `X-CCM-Site-Url`) and error handling
  - Eliminates redundant `wp_remote_get` code with separate header construction

### v7.20.5
- **Fix Auto-Update Failing When Plugin Directory Already Named `ccm-tools`**
  - `fix_source_dir()` filter tried to rename the source directory to `ccm-tools/` even when it was already correct or when the zip had a flat structure (no parent folder)
  - Flat zips (like `ccm-tools.zip` built by `Compress-Archive`) extract files directly into the working directory — the filter then tried to move the working dir into a subdirectory of itself, which always fails
  - Added early return when `basename($source)` is already `ccm-tools` — no rename needed
  - Added early return when `$source === $remote_source` (flat zip) — WordPress copies contents directly to the destination plugin directory
  - Rename now only fires when the extracted folder genuinely has a wrong name (e.g. `ccm-tools-7.20.5/` or `ClickClickMedia-ccm-tools-abc1234/`)

### v7.20.4
- **Fix API Key Not Saving on Premium Page**
  - `initAiHubHandlers()` was only called from `initPerfOptimizerHandlers()`, which is gated by `$('#save-perf-settings')` — an element that only exists on the Performance page
  - On the dedicated Premium page (`ccm-tools-premium`), the Save and Test Connection buttons had no click handlers, so clicking them did nothing
  - Added standalone `initAiHubHandlers()` call in the `ready()` block when on the Premium page (detected by `#ai-hub-save-btn` present without `#save-perf-settings`)
  - Save, Test Connection buttons now work on both the Performance and Premium pages

### v7.20.3
- **Fix GitHub Release Install Directory Name**
  - GitHub release zips (`ccm-tools-7.20.2.zip`) extract to `ccm-tools-7.20.2/` instead of `ccm-tools/`
  - WordPress then installs to `/wp-content/plugins/ccm-tools-7.20.2/` breaking the plugin path
  - Added `upgrader_source_selection` filter in `CCM_GitHub_Updater` to rename extracted folder to `ccm-tools/` before WordPress moves it
  - Handles both release asset zips (`ccm-tools-X.Y.Z/`) and GitHub zipball archives (`ClickClickMedia-ccm-tools-abc1234/`)
  - Works for both manual upload installs and auto-updates
  - Only fires when the extracted folder matches `ccm-tools-*` or `ClickClickMedia-ccm-tools-*` patterns — won't interfere with other plugins

### v7.20.2
- **Premium Menu Reorganization**
  - Removed `⭐ Premium` tab from the horizontal top navigation bar — Premium page is now accessed only via the WordPress admin sidebar
  - Moved `⭐ Premium` sidebar submenu item to the bottom of the CCM Tools menu (after Error Log)
  - Previous sidebar order: ...Performance, Premium, WooCommerce, Error Log → New order: ...Performance, WooCommerce, Error Log, Premium

### v7.20.1
- Removed Premium dashboard card from System Info page
- Updated Get Premium button URL to root `https://premium.clickclickmedia.com.au`
- Added "Lost your API key?" login link on Premium settings page

### v7.20.0
- **Dedicated Premium Admin Page — Hub API Key Connection Moved**
  - New `⭐ Premium` submenu page (`ccm-tools-premium`) with its own nav tab between Performance and WooCommerce
  - Hub Connection card with API key input, Save, Test Connection buttons, and connection status badge
  - Subscription Status card showing active subscription details (plan, renewal date, features) or Free vs Premium comparison table with upgrade CTA
  - New `ccm_tools_render_premium_page()` function in `inc/premium.php` — renders the full admin page
  - Hub Connection section removed from AI Performance section on the Performance page
  - Performance page now shows a CTA box linking to Premium Settings when no API key is configured
  - Hidden `#ai-hub-url` input kept on Performance page for JS compatibility
  - Same HTML element IDs used on Premium page (`#ai-hub-key`, `#ai-hub-save-btn`, `#ai-hub-test-btn`, etc.) — existing JS handlers work without modification
  - No JS changes needed — `initAiHubHandlers()` uses null-safe element binding that works on both pages
- **Premium Subscription System — Free vs Premium Feature Gating**
  - New `inc/premium.php` module: subscription status checking, feature access control, upsell UI rendering, AJAX handlers
  - **Three-tier premium status check:** wp-config.php `CCM_TOOLS_PREMIUM` constant (dev override) → transient cache (12h TTL) → hub API call `GET /api/v1/premium/status`
  - **Free tier includes:** System Info, Database Tools, .htaccess, Error Log, Debug Mode, WebP Converter, Performance Optimizer, Basic Redis (host, port, password, database, key_salt, max_ttl, selective_flush, enable/disable, flush, stats)
  - **Premium tier adds:** All AI Performance Hub features (PageSpeed, AI analysis, one-click optimize, visual regression, console check, AI chat), Advanced Redis (serializer, compression, async_flush, ACL username, TLS, WooCommerce optimization, timeouts, runtime diagnostics, HTML footnote)
  - **Render-level gating:** AI section shows full upsell card, Redis advanced/WooCommerce sections show compact upsell with Premium badge, Runtime diagnostics hidden entirely
  - **AJAX-level gating (defense-in-depth):** 7 AI hub AJAX handlers reject requests with JSON error if not premium — `run_pagespeed`, `ai_analyze`, `ai_optimize`, `console_check`, `screenshot`, `visual_compare`, `ai_chat`
  - **Premium badge in header:** Green "Premium" badge when active, amber "Free" link to upgrade when inactive — rendered by `ccm_tools_render_premium_badge()`
  - **Dashboard comparison card:** Shows active premium features or full Free vs Premium comparison grid with upgrade CTA
  - **Upsell cards:** `ccm_tools_render_premium_upsell($feature_key, $compact)` — full card (AI section) or compact inline (Redis sections) with feature lists and upgrade button
  - **JS premium refresh:** `#premium-refresh-btn` handler clears transient cache and re-checks hub, reloads page on status change
  - Premium website URL configurable via `CCM_TOOLS_PREMIUM_URL` constant (default: `https://premium.clickclickmedia.com.au`)
  - New AJAX handler: `ccm_tools_premium_refresh` — clears both `ccm_tools_premium_status` and `ccm_tools_premium_details` transients
  - Hub API contract: `GET /api/v1/premium/status` with `X-Api-Key` header, returns `{ success: true, premium: bool, plan: string, expires: string, features: string[] }`

### v7.19.7
- **Auto-Flush Redis Cache on Serializer or Compression Change**
  - Changing the serializer (e.g., PHP → igbinary) or compression (e.g., none → LZ4) makes all existing cached data unreadable by the new deserializer
  - This caused `PHP Fatal error: get_object_vars(): Argument #1 ($object) must be of type object, string given` and thousands of `foreach() argument must be of type array|object, string given` warnings
  - WordPress received raw strings instead of deserialized objects/arrays for posts, textdomain registry, ACF field groups, etc.
  - Save handler now compares old vs new serializer/compression values and automatically flushes the entire Redis cache when either changes
  - Response message tells the user the cache was flushed and why
  - Prevents the site-breaking deserialization crash that occurs when old cached data is read with a different serializer

### v7.19.6
- **"Add to wp-config.php" Now Writes All Redis Settings**
  - The drop-in (`object-cache.php`) loads before WordPress's options API is available, so it can ONLY read wp-config.php constants
  - Previously "Add to wp-config.php" only wrote host, port, maxttl, key_salt, password, database, selective_flush
  - Now also writes: `WP_REDIS_SERIALIZER`, `WP_REDIS_COMPRESSION`, `WP_REDIS_ASYNC_FLUSH`, `WP_REDIS_USERNAME`, `WP_REDIS_SCHEME`, `WP_REDIS_PATH`, `WP_REDIS_TIMEOUT`, `WP_REDIS_READ_TIMEOUT`, `WP_REDIS_DISABLE_COMMENT`
  - Non-default values only — e.g. serializer only written when not `php`, compression only when not `none`
  - Existing constants are skipped (safe to click again after changing settings)
  - Float values (timeouts) now written as unquoted numbers instead of quoted strings
  - Fixes the mismatch where settings form showed igbinary/LZ4/async_flush but the drop-in runtime showed php/none/false

### v7.19.5
- **Drop-In Runtime Diagnostics Panel**
  - New "Drop-In Runtime" section in Redis Status card shows live values from the active `$wp_object_cache` instance
  - Queries `$wp_object_cache->info()` to display what the drop-in is **actually using** (not just what's configured)
  - Shows: status, serializer, compression, async flush, selective flush, max TTL, KEEPTTL support, key prefix, global groups
  - Page-load cache stats: hits/misses with hit ratio, Redis calls count, total Redis time in ms
  - Only renders when the CCM Tools drop-in is installed and Redis is connected
  - Gives administrators concrete proof that igbinary/LZ4/etc. are active in the running cache

### v7.19.4
- **Fixed False Error When Saving Unchanged Redis Settings**
  - `update_option()` returns `false` when the value hasn't changed, which was incorrectly treated as a save failure
  - Now returns success with "Settings unchanged" message instead of showing a red error toast

### v7.19.3
- **Redis Settings Page Reload After Save**
  - Page now reloads 800ms after saving Redis settings so the Active Configuration table reflects the new values immediately
  - Previously the dropdown showed the new selection but the server-rendered Active Configuration table still displayed stale values from the initial page load
  - Users had to manually reload the page to see updated compression, serializer, async flush, etc. in the Active Configuration table
  - Also fixed plugin header `Version:` which was stuck at 7.19.0 since the v7.19.1 release

### v7.19.2
- **Fixed Redis Password Field Browser Autofill**
  - Added `autocomplete="new-password"` to the Redis password input to prevent browsers from injecting saved credentials
  - Browser autofill was silently populating the password field, causing `ERR AUTH <password> called without any password configured` on default Redis setups that don't require authentication

### v7.19.1
- **Redis Settings Form Visible When Disconnected**
  - Configuration form, Active Configuration table, and Test Connection button now render even when Redis connection fails
  - Previously the entire settings form was hidden behind `$connection['connected']` check, locking users out when they saved a bad config (e.g., wrong password)
  - Enable/Disable Object Cache and Flush Cache buttons still require active connection
  - Statistics card still requires active connection (no data to show without one)
  - Test Connection button always visible so users can verify config changes without page reload

### v7.19.0
- **Redis Object Cache — Complete Rewrite (Object Cache Pro Feature Parity)**
  - **Complete drop-in rewrite** (`assets/object-cache.php`): new `CCM_Redis_Object_Cache` class with modern architecture
  - **SCAN-based flush**: `flush()` and `flush_group()` now use SCAN + batch DEL/UNLINK instead of dangerous `KEYS` command (production-safe, non-blocking)
  - **Pipelined bulk operations**: `add_multiple()`, `set_multiple()`, `delete_multiple()` use Redis pipelines instead of looping one-by-one
  - **Serializer support**: Configurable via `WP_REDIS_SERIALIZER` — supports `php` (default), `igbinary` (faster, smaller), and `msgpack` (compact binary)
  - **Compression support**: Configurable via `WP_REDIS_COMPRESSION` — supports `none` (default), `lzf` (fast), `lz4` (very fast), and `zstd` (best ratio)
  - **`wp_cache_has()` function**: WordPress 6.4+ support for checking key existence without fetching the value
  - **Async flush (UNLINK)**: When `WP_REDIS_ASYNC_FLUSH` is true, uses non-blocking `UNLINK` and `FLUSHDB ASYNC` commands (Redis 4.0+)
  - **ACL authentication**: Redis 6.0+ username support via `WP_REDIS_USERNAME` constant for ACL-based auth
  - **Retry/reconnection logic**: Automatic retry with decorrelated jitter backoff on connection failures (configurable retries and interval)
  - **Auto-reconnect**: `redis_call()` wrapper detects connection-level failures and attempts one transparent reconnect before returning error
  - **Error tracking**: Runtime errors logged to `$wp_object_cache_errors` global and PHP error log; accessible via `get_errors()` method
  - **HTML footnote**: Appends `<!-- CCM Redis Object Cache | hits: X, misses: Y, ratio: Z%, redis calls: N, redis time: Xms -->` comment to page output (configurable)
  - **Flush logging**: Every cache flush records type, timestamp, and PHP backtrace for debugging (accessible via `get_flush_log()`)
  - **`wp_cache_remember()` / `wp_cache_sear()`**: Get-or-set cache helper functions — if key doesn't exist, calls callback and stores result
  - **Per-request timing**: Tracks total Redis time in milliseconds via `redis_time` stat for performance profiling
  - **Wildcard non-persistent groups**: Supports fnmatch()-style patterns (e.g., `wc_cache_*`) in non-persistent and ignored group lists
  - **TLS context options**: `WP_REDIS_TLS_OPTIONS` constant for custom TLS/SSL stream context (cert verification, CA bundle, etc.)
  - **`wp_suspend_cache_addition()` check**: `add()` now respects WordPress's cache addition suspension flag
  - **`wp_cache_supports()` function**: Reports supported features (add_multiple, set_multiple, get_multiple, delete_multiple, flush_runtime, flush_group)
  - **KEEPTTL detection**: Detects Redis 6.0+ for future KEEPTTL support on incr/decr operations
  - **Diagnostics**: `info()` method returns comprehensive diagnostic array (status, config, groups, errors, flush log, timing stats)
  - **Clone-on-read consistency**: Objects cloned when stored and when retrieved to prevent reference mutations
  - **Max TTL enforcement**: Caps all expiry times to `WP_REDIS_MAXTTL` when configured
  - **Admin UI — Serializer/Compression/Async Flush controls**: New dropdowns and toggles in Redis settings page
  - **Admin UI — ACL Username field**: New field in Advanced Settings for Redis 6.0+ ACL authentication
  - **Admin UI — HTML Footnote toggle**: Enable/disable the HTML comment cache stats via checkbox
  - **WordPress Site Health integration**: Three new health checks — Redis Connection (connected + version), Drop-In Status (installed + version), Eviction Policy (safe vs dangerous)
  - **Transient cleanup on enable**: When the drop-in is installed, all database-stored transients are purged (Redis handles them now)
  - **Drop-in version checking**: Compares installed drop-in `@version` tag with bundled version; shows update notice in admin bar and Redis status page
  - **Drop-in update button**: One-click "Update Drop-In" button appears when installed version is outdated
  - **Admin notice for outdated drop-in**: WordPress admin notice when drop-in version is behind plugin version
  - **Active Configuration table**: Now shows serializer, compression, async_flush, and username constants alongside existing settings

### v7.18.12
- **Disk Card Now Explains Quota Fallback Clearly**
  - Added a user-facing note when quota data cannot be read so users know server disk is being shown intentionally
  - Message distinguishes between temporary quota lookup failure and hosts where quota data is not available
  - Applied to both Dashboard card and AJAX System Information output for consistent UX

### v7.18.11
- **Disk Information Now Prioritizes cPanel Account Quota (Actual Hosting Limit)**
  - `ccm_tools_get_disk_info()` now tries to read per-account quota first (Linux `quota` command) instead of only filesystem totals
  - Resolves cPanel mismatch where server disk can show lots of free space while account quota is already full
  - Dashboard Disk card now shows `Account Quota` as the primary source when available
  - Keeps server-level disk usage as secondary context (`Server Disk (secondary)`) so both perspectives remain visible
  - Includes automatic fallback to server disk totals when quota data is unavailable (non-cPanel hosts or restricted shell functions)

### v7.18.10
- **Standardized Collation to `utf8mb4_unicode_520_ci` (WordPress Core Match)**
  - Both `ccm_tools_get_appropriate_collation()` and `ccm_tools_get_appropriate_collation_optimize()` now always return `utf8mb4_unicode_520_ci`
  - Previously used `utf8mb4_0900_ai_ci` on MySQL 8.0+ — which caused "Illegal mix of collations" errors when JOINing with tables created by WordPress/plugins using the default `utf8mb4_unicode_520_ci`
  - `utf8mb4_unicode_520_ci` (Unicode 5.2) is WordPress core's default since WP 4.6 — all core tables, plugin tables, and WooCommerce tables use it
  - `utf8mb4_0900_ai_ci` (Unicode 9.0) is faster but MySQL 8.0-only, unavailable on MariaDB, and mixing collations in JOINs causes SQL errors
  - Version string parameter kept for backward compatibility but is now ignored
- **Smart "Update Table Collations" Checkbox — Auto-Uncheck When Already Correct**
  - New `tables_needing_collation` stat counts tables where `TABLE_COLLATION != 'utf8mb4_unicode_520_ci'`
  - "Update table collations" checkbox now shows the actual count of tables needing updates (was showing total table count)
  - Checkbox automatically unchecked when 0 tables need collation update (e.g., after "Convert to InnoDB" has already converted them)
  - Prevents redundant collation runs that do nothing

### v7.18.9
- **Progressive Per-Table Database Optimization (Timeout Prevention)**
  - `optimize_tables` and `update_collation` tasks now process tables one-by-one via AJAX instead of in a single batch PHP call
  - Previously ALL tables were optimized/collated in one `ccm_tools_optimization_optimize_tables()` call — caused timeouts on databases with 100+ tables
  - New per-table sub-progress bar appears inline in the results table showing: current table name (monospace), counter (e.g. 47/156), and a compact progress bar
  - Each table gets its own lightweight AJAX call to new `ccm_tools_optimize_table_task` handler (OPTIMIZE TABLE + optional ALTER TABLE COLLATE)
  - Sub-progress row removed after completion; parent task row shows final tally
  - Regular optimization tasks (transients, spam, meta cleanup) still run as single AJAX calls — only table-intensive tasks are split
  - 60-second per-table timeout prevents any single large table from blocking the entire flow
- **Fixed Redis Cache Statistics Not Showing on Large Instances**
  - Root cause: `KEYS` command (O(N), blocks Redis server) was timing out or returning empty on Redis instances with large key spaces (e.g. 255MB+)
  - Redis 6.0+ with millions of keys: `KEYS prefix*` can take seconds and block all other Redis operations
  - Replaced `KEYS` with `SCAN` iterator throughout: `ccm_tools_redis_get_stats()` and `ccm_tools_redis_flush_cache()`
  - `SCAN` is non-blocking, processes in batches of 200 keys, and is safe for production Redis servers
  - Stats now correctly show Cached Keys, Estimated Memory, Cache Groups, and Avg. TTL even on large Redis instances
  - Selective cache flush also uses `SCAN` + batch `DEL` instead of `KEYS` + bulk `DEL`

### v7.18.8
- **Visual Regression Check — Layout Integrity is #1 Priority**
  - **`layout_ok: false` now triggers rollback regardless of severity**: Previously required BOTH `layout_ok === false` AND `severity === 'critical'` — if Claude returned `layout_ok: false, severity: 'minor'`, changes were KEPT despite broken layout
  - **Failed visual check now fails-safe to ROLLBACK**: Previously `hasLayoutRegression = false` when the visual compare API failed/timed out, letting broken layouts through. Now `hasLayoutRegression = true` — if we can't verify layout integrity, we assume regression
  - **Fixed `severity: 'minor'` catch-all bypassing `layout_ok: false`**: The `else if (severity === 'minor')` branch was catching `layout_ok: false, severity: 'minor'` responses and treating them as acceptable. Now only `layout_ok: true` responses can be classified as minor
  - **Hub severity fallback respects `layout_ok`**: When Claude omits `severity` from JSON, the fallback now defaults to `'critical'` when `layout_ok` is false (was defaulting to `'none'` unconditionally)
  - **Philosophy change**: Layout integrity is now explicitly more important than PageSpeed scores — the system will sacrifice score improvements to protect visual integrity

### v7.18.7
- **Robust Screenshot Capture — Retry Pipeline + Diagnostic Logging**
  - **screenshot.js rewritten for reliability:**
    - Global 120s process timeout — script self-terminates if hung, prevents zombie Chromium processes
    - Step-by-step diagnostic logging to stderr at every stage (launch, navigate, scroll, image wait, capture)
    - Smaller scroll steps (50% viewport) at 250ms intervals for better IntersectionObserver trigger coverage
    - `page.waitForNetworkIdle({ idleTime: 1500 })` after scroll catches all post-scroll lazy image fetches
    - Explicit `waitForImages()` waits for every `<img>` element's load event with per-image 10s timeout
    - Reports detailed image load stats (total, already loaded, loaded after wait, errors, timeouts)
    - Skips zero-size/hidden images to avoid waiting for tracking pixels
    - Dismisses common cookie consent overlays before scrolling
    - Output file verification: checks file exists AND is non-zero before reporting success
    - `headless: 'new'` mode for Chrome 112+ with proper args including `--disable-software-rasterizer`
  - **PHP retry pipeline (3 attempts before failure):**
    - Attempt 1: Puppeteer (best quality — networkidle0, scroll, image wait)
    - Attempt 2: Puppeteer retry with 2s cooldown (handles transient Chromium crashes)
    - Attempt 3: Chromium CLI fallback with `--headless=new` → `--headless` retry
    - Each attempt gets a fresh temp file to avoid stale state
  - **New `diagnoseScreenshotCapability()` function:**
    - Reports: Chromium path + version, Node.js path + version, puppeteer-core installed, proc_open available, /tmp writable + free space, GD library available
    - Diagnostic data included in error responses when all attempts fail — no more "no output file produced" without context
  - **Timeout increases across the stack:**
    - Puppeteer PHP wrapper: 130s (was 50s) to accommodate the 120s JS global timeout
    - `captureScreenshots()`: `set_time_limit(600)` (was 120s) for retry pipeline
    - API endpoint: `set_time_limit(600)` (was 120s)
    - Plugin AJAX handler: `set_time_limit(600)` (was 120s), HTTP timeout 300s (was 120s)
    - JS AJAX timeout: 300s (was 120s)
  - **Stricter AI Visual Comparison Prompt:**
    - Added "GOLDEN RULE": Before screenshot is ground truth — anything visible in Before but absent in After is always a regression
    - Removed "ignore lazy-loaded images/blank areas" instruction that was causing Claude to miss real regressions
    - Explicit rule: hero image present in Before → blank in After = AUTOMATIC CRITICAL
    - "NEVER IGNORE" section: blank areas in After that were filled in Before must always be reported
    - Updated user message to stop telling AI to ignore lazy-loading placeholders

### v7.18.6
- **Step Indicators All Fit on One Line**
  - Removed `flex-wrap: wrap` → `flex-wrap: nowrap` on `.ccm-ai-steps` so all 12 steps stay on a single row
  - Removed `min-width: 120px` → `min-width: 0` so steps can shrink freely with flex
  - Reduced step padding from `0.75rem 0.5rem` → `0.6rem 0.25rem`
  - Reduced step gap from `0.25rem` → `0.2rem`
  - Reduced indicator circle from 28px → 24px
  - Reduced label font-size from `0.75rem` → `0.68rem` with `word-break: break-word`
  - Reduced status font-size from `0.7rem` → `0.62rem`
  - Reduced icon `::after` font-size from `14px` → `12px` to fit smaller indicators

### v7.18.5
- **Puppeteer Screenshot Capture — Full Page Load Before Capture**
  - Replaced Chromium CLI `--screenshot` with a Puppeteer (Node.js) script for screenshot capture
  - `waitUntil: 'networkidle0'` waits until no network requests for 500ms — ensures deferred fonts, async scripts and XHR calls complete before capture
  - Programmatic scroll through the entire page triggers `IntersectionObserver` callbacks, firing lazy-loaded images, video facades, and below-fold content
  - 2-second settle wait after scrolling for JS-populated content (animated elements, carousel items, etc.) to finish rendering
  - Scrolls back to top before capture so above-fold content is the first thing visible
  - `fullPage: true` — Puppeteer captures the complete page regardless of viewport height, replacing the tall `8192px / 12288px` viewport hack
  - Uses `puppeteer-core` (no bundled Chromium download — reuses the existing Chromium binary found by `findChromiumBinary()`)
  - Automatic fallback to Chromium CLI `--screenshot` if Node.js or `puppeteer-core` is not installed
  - New files: `scripts/screenshot.js`, `scripts/package.json`
  - New PHP helpers: `findNodeBinary()`, `canUsePuppeteer()`, `runPuppeteerCapture()`, `runChromiumCliCapture()` in `includes/screenshot.php`
  - **Hub Installation (one-time, run on server):**
    ```bash
    # 1. Install Node.js (if not already installed)
    sudo apt-get install -y nodejs npm
    # or: curl -fsSL https://deb.nodesource.com/setup_20.x | sudo bash - && sudo apt-get install -y nodejs

    # 2. Install puppeteer-core
    cd /path/to/hub/scripts
    npm install
    ```
  - After install, `canUsePuppeteer()` auto-detects the setup and switches to Puppeteer automatically — no config changes needed

### v7.18.4
- **Fixed Screenshot Capture Broken by --virtual-time-budget Flag**
  - `--virtual-time-budget=10000` added in v7.18.3 is incompatible with Chromium's `--screenshot` flag in headless=new mode
  - The virtual time simulation prevents Chromium from producing an output PNG file, causing "no output file produced" errors
  - Removed `--virtual-time-budget` flag entirely
  - Added `--disable-background-timer-throttling`, `--disable-renderer-backgrounding`, and `--disable-backgrounding-occluded-windows` instead
  - These flags keep renderer and timers active during capture without conflicting with the screenshot mechanism

### v7.18.3
- **Fixed Visual Check Always Skipping + Screenshot Missing Lazy-Loaded Content**
  - **Visual Check now retries on failure**: Previously if the visual compare API call failed or timed out, the step silently showed "Skipped" and continued. Now retries once automatically before marking as failed
  - **Visual Check no longer silently skips**: When the check fails after retries, it shows "Check failed" (error state) instead of "Skipped" (done state), and passes a caution context to the AI for the next iteration
  - **When no screenshots exist**: Step now shows error state ("No screenshots") instead of quietly showing done, and passes context warning to AI
  - **Screenshot capture now waits for lazy-loaded content**: Added `--virtual-time-budget=10000` (10 seconds) to headless Chromium flags
  - Chromium's `--screenshot` flag previously captured immediately after DOMContentLoaded — missing lazy-loaded images, deferred JS content, facade elements, and below-fold resources
  - Virtual time budget advances Chromium's internal clock, triggering `setTimeout`, `requestAnimationFrame`, `IntersectionObserver` callbacks, and network fetches before capturing
  - Screenshots now show the full rendered page including lazy-loaded hero images, video facades, animated elements, and JS-populated content
  - Fixes missing laptop/phone mockup images and other below-fold content in baseline screenshots

### v7.18.2
- **Parallelized Baseline Screenshots + Console Check with PageSpeed Tests**
  - Screenshots and console error check now fire in the background immediately after saving the settings snapshot
  - Previously these ran sequentially AFTER both PageSpeed tests completed, adding ~60-120s of wait time
  - Screenshots appear as soon as they resolve — often during or right after the Mobile PSI test finishes
  - Users see visual baseline much earlier, providing instant feedback that the process is working
  - Console check results collected after both PSI tests complete (they run faster than PSI anyway)
  - New "Screenshots" step indicator added to the progress bar between Save Snapshot and Test Mobile
  - Uses `Promise.race` to check if screenshots finished during Mobile test, awaits remainder after Desktop test
  - No functional changes to the optimization pipeline — same data, same safety checks, just faster perceived performance
  - Estimated time savings: 30-90 seconds depending on hub server screenshot capture speed

### v7.18.1
- **AI API Token Optimization — Reduced Claude Input Token Usage by ~40%**
  - **CSS Minification Before Sending to AI**: New `minifyCssForAi()` function strips CSS comments, `@keyframes` blocks, `@media print` rules, external `@font-face` declarations, and collapses whitespace before including CSS in AI prompts
  - **Reduced CSS Cap**: Total CSS sent to AI reduced from 60KB → 40KB, per-file cap from 30KB → 25KB
  - **Reduced Inline Styles Cap**: Inline `<style>` content cap reduced from 20KB → 10KB, now also minified
  - **Reduced Image Lists**: Images sent to AI reduced from 20/15 → 10 in both analyze and optimize endpoints
  - **URL Truncation**: New `truncateUrlForAi()` function strips query strings and shortens URLs >120 chars to domain + last 2 path segments, reducing token waste on long CDN/cache-busted URLs
  - **Page Resource Caching in Optimize Sessions**: Page HTML/CSS fetched ONCE during the `start` action and stored in `ai_sessions.metadata` JSON column; retest iterations load cached resources instead of re-fetching (saves ~15-20K input tokens per iteration)
  - New `ensureAiSessionsMetadataColumn()` auto-migration function adds `metadata` JSON column to `ai_sessions` table
  - **Removed Plugin-Side Duplicate Learnings**: Plugin no longer sends `ccm_tools_build_learnings_context()` data in API calls for analyze, optimize, or chat — learnings are now handled centrally by the hub's `buildHubLearnings()` which includes both cross-site intelligence AND site-specific history
  - **Merged AI Prompt Sections**: Combined duplicate "Previous Optimization History" and "Cross-Site Optimization Intelligence" prompt sections into a single unified section in both `ai-analyze.php` and `ai-optimize.php`
  - `ccm_tools_build_learnings_context()` function preserved in plugin but no longer called (kept for potential local-only future use)
  - Estimated savings: ~25,000-40,000 fewer input tokens per optimize session, ~8,000-15,000 fewer per single analyze call

### v7.18.0
- **Cross-Site AI Learning — Hub-Centralized Optimization Intelligence**
  - Optimization run outcomes are now stored on the hub in a new `optimization_runs` database table
  - Every One-Click Optimize session sends results (URL, before/after scores, settings changed, outcome) to the hub after saving locally
  - Hub aggregates data from ALL sites to identify patterns: which settings consistently improve scores vs which cause rollbacks
  - New `buildHubLearnings()` function analyses all runs and builds a structured intelligence report for AI prompts
  - Cross-site intelligence injected into all 3 AI endpoints: Analyze, Optimize, and Chat
  - AI prompt updated with new "Cross-Site Optimization Intelligence" section explaining how to use hub data
  - AI told to: prioritise HIGH CONFIDENCE settings (many wins across sites), avoid HIGH RISK settings (many rollbacks), prefer site-specific history when it disagrees with cross-site data
  - Settings with mixed results include win/loss ratios for nuanced AI decision-making
  - **Hub Admin Page**: New "Optimizations" page (🧠 icon) in hub navigation
    - Stats grid: total runs, improved count, rolled back count, avg score gain, best scores achieved
    - Setting Intelligence table: win/loss analysis per setting with success rate bar, verdict badges (Safe, Avoid, Mixed, Risky)
    - Full runs table with site, URL, before→after scores, deltas, iterations, outcome, settings changed
    - Filter by site and outcome (improved/rolled back/no changes)
  - **New Hub API Endpoints**:
    - `POST /api/v1/optimization/save-run` — stores run data from any site
    - `GET /api/v1/optimization/learnings` — returns aggregate intelligence and stats
  - **Auto-migration**: `ensureOptimizationRunsTable()` creates table on first use (same pattern as screenshots)
  - Plugin save handler now POSTs to hub (best-effort, 15s timeout) alongside local wp_options save
  - Hub learnings supplement (not replace) site-specific learning history — both are included in AI context
  - New file: `includes/learnings.php` (hub), `admin/optimizations.php` (hub), `api/v1/optimization-save-run.php`, `api/v1/optimization-learnings.php`

### v7.17.13
- **Live UI Update When Pre-Flight Enables Performance Optimizer**
  - When the One-Click Optimize pre-flight check enables the Performance Optimizer, the master toggle and status text now update immediately on the page
  - Previously the log showed "Performance Optimizer enabled" but the toggle stayed OFF and status showed "INACTIVE"
  - Calls `aiUpdatePageToggles({ enabled: true })` after successful enable, which flips the checkbox and dispatches a change event
  - The existing change handler updates the status text and CSS class in real-time

### v7.17.12
- **Fixed Plugin Update Loop — Header Version Was Stuck at 7.17.9**
  - Plugin header `Version:` in ccm.php line 6 was never updated from 7.17.9 during v7.17.10 and v7.17.11 releases
  - WordPress reads the plugin header (not the `CCM_HELPER_VERSION` constant) for version comparison
  - `version_compare('7.17.11', '7.17.9', '>')` was always true → perpetual "Update to 7.17.11" notification
  - Both the header and constant now updated to 7.17.12
- **Fixed Hub Iteration Screenshots Not Showing (Timing Window Too Narrow)**
  - After screenshots for iterations were not appearing on the hub site detail page
  - Root cause: 120-second lookback window (`$pairTs - 120`) was far too tight
  - After screenshots are captured 3-5 minutes before the retest (flow: apply → screenshot → console check → visual check → retest)
  - Changed to use `$prevPairTs` (previous pair's timestamp) as the lower bound instead of `$pairTs - 120`
  - Now correctly matches any after-screenshot between the previous test and the next test
  - Iteration 1 (and all subsequent iterations) now show Before (Baseline) vs After comparison correctly

### v7.17.11
- **Searchable Page Picker for URL to Test**
  - Replaced manual URL text input with a searchable dropdown that lists all published pages, posts, and custom post types
  - Type-ahead search with 250ms debounce queries WordPress via new `ccm_tools_search_pages` AJAX handler
  - Results show post type badge (Page, Post, Product, etc.), title, and URL
  - Homepage always listed first when search is empty or matches "home/homepage"
  - Keyboard navigation: Arrow Up/Down to browse, Enter to select, Escape to close
  - Selected page shown as a pill with type badge, URL, and clear (×) button
  - Clearing the selection re-shows the search input for a new search
  - All public post types supported including WooCommerce Products, custom CPTs
  - New CSS: `.ccm-url-picker`, `.ccm-url-picker-selected`, `.ccm-url-picker-dropdown`, `.ccm-url-picker-item`, etc.

### v7.17.10
- **Visual Regression Detection — Fail-Safe Default + Stronger Prompt**
  - **CRITICAL FIX**: JSON parse fallback in `ai-visual-compare.php` now defaults to `layout_ok: false, severity: 'critical'` instead of `layout_ok: true`
  - Previously, if Claude returned unparseable JSON, the visual check silently passed — allowing broken layouts to remain deployed
  - Now unparseable responses trigger automatic rollback for safety
  - Added explicit detection rules for completely unstyled pages (missing CSS, raw HTML rendering)
  - Prompt now lists "AUTOMATIC CRITICAL" conditions: unstyled pages, navigation running together, hero sections collapsed to text, content spilling beyond viewport
  - These patterns are always treated as critical regardless of other factors
- **Hub Site Detail — Baseline-Only Before Screenshots + Per-Iteration Comparison**
  - Baseline now shows only the FIRST set of Before screenshots (was showing all Before screenshots from all runs)
  - Each iteration now shows a side-by-side comparison grid: Before (Baseline) alongside After (Iteration N)
  - Before and After columns have colored phase labels (green for Before, blue for After)
  - Comparison grid uses CSS Grid 2-column layout with responsive single-column on mobile
  - `$baselineSS` variable extracted once per session and reused across all iterations

### v7.17.9
- **Fixed Side-by-Side Screenshot Comparison Layout**
  - Before and After screenshots had wildly different heights due to full-page captures (8192px/12288px) with auto-crop producing different content lengths
  - Added `max-height: 520px; object-fit: cover; object-position: top;` to `.ccm-screenshot-img`
  - Both Before and After now show the same "above the fold" viewport at the same scale, making visual comparison meaningful
  - Heading updated to note "above the fold · click to view full page" — lightbox still shows full images
  - New `.ccm-screenshot-crop-note` CSS class for future use

### v7.17.8
- **Auto-Scroll to Screenshots During Optimization**
  - After screenshots were not visible during per-iteration captures because the `#ai-screenshots` container is at the bottom of the results area, far below the activity log the user watches
  - Added `scrollIntoView({ behavior: 'smooth', block: 'center' })` to `aiShowBaselineScreenshots()` — page scrolls to Before screenshots when baseline capture completes
  - Added `scrollIntoView({ behavior: 'smooth', block: 'center' })` to `aiShowAfterScreenshots()` — page scrolls to show updated After screenshots after each iteration's capture
  - Added activity log entry "After screenshots updated — scroll to see comparison ↓" when per-iteration After screenshots are rendered
  - Users now see both Before and After screenshots update in real-time during the optimization loop without manual scrolling

### v7.17.7
- **AI Visual Regression Detection — Automatic Layout Integrity Check**
  - New safety check in the One-Click Optimize pipeline that detects when performance changes break page layout
  - After each iteration's screenshot capture, before/after images are sent to Claude Vision API for comparison
  - AI analyzes desktop and mobile screenshots for structural layout regressions: shifted elements, missing content, broken grids, overlapping text, collapsed navigation
  - Smart differentiation: ignores expected differences (video playback states, lazy-loading placeholders, cookie banners, chat widgets, carousel slide changes, dynamic ads, minor compression artifacts)
  - **Automatic rollback**: if critical layout regression detected, settings are rolled back regardless of score improvement
  - Visual regression context passed to AI for next iteration so it avoids problematic CSS/JS settings
  - Three severity levels: `none` (identical), `minor` (acceptable), `critical` (triggers rollback)
  - New "Visual Check" step added to progress indicator between Console Check and Compare
  - Activity log shows detailed issue descriptions with affected area, likely cause, and suggested fix per issue
  - **Hub endpoint**: New `POST /api/v1/ai/visual-compare` — fetches screenshot URLs, encodes as base64, sends to Claude Vision with structured comparison prompt
  - Hub returns structured JSON: `layout_ok`, `severity`, `issues[]` with `description`, `area`, `likely_cause`, `suggested_fix`
  - New plugin AJAX handler: `ccm_tools_ai_hub_visual_compare` proxying to hub endpoint
  - Rollback decision logic updated: `keepChanges = (bothStable || netPositive) && !hasNewConsoleErrors && !hasLayoutRegression`
  - Tokens and cost logged per visual check call

### v7.17.6
- **Per-Iteration Screenshot Capture During One-Click Optimize**
  - Screenshots now captured after every optimization iteration, not just at the beginning and end
  - After each iteration's retests and console check, a screenshot is taken to show the visual state of the page
  - "After" column in the screenshot comparison updates live with each iteration's capture
  - After label shows iteration number (e.g., "After (Iter 2)") so users can track which iteration produced the current visual
  - If the final iteration was rolled back, a post-rollback screenshot is captured to show the actual final state
  - Eliminates redundant final screenshot capture when the last iteration was kept (per-iteration capture is already current)
  - `aiShowAfterScreenshots()` now accepts optional `iteration` parameter for labeling
  - Activity log shows screenshot file sizes per iteration for monitoring

### v7.17.5
- **Screenshot Capture Timeout Fix — Execution Time Limit + Backward Compatibility**
  - Root cause: PHP default `max_execution_time` (30s) was killing the hub PHP process mid-capture — two Chromium screenshots need ~50s total
  - Added `@set_time_limit(120)` to hub `api/v1/screenshot-capture.php` endpoint
  - Added `@set_time_limit(120)` to hub `captureScreenshots()` in `includes/screenshot.php`
  - Added `@set_time_limit(120)` to plugin AJAX handler `ccm_tools_ajax_ai_hub_screenshot()`
  - Comprehensive `appLog()` logging throughout screenshot capture pipeline for debugging:
    - Chromium binary check, proc_open start, process finish time, PNG file check, JPEG save result
    - Per-capture and total timing in `captureScreenshots()`
  - Increased plugin-to-hub HTTP request timeout from 60s to 120s
  - Increased JS AJAX timeout from 60s to 120s for both baseline and final captures
  - **Backward compatibility**: JS now handles both `url` (v7.17.4+ file-based) and `data_uri` (v7.17.1-7.17.3 base64) response formats
  - `aiShowBaselineScreenshots()` and `aiShowAfterScreenshots()` use `data.desktop.url || data.desktop.data_uri` pattern
  - Prevents silent failure if hub has not yet deployed the file-based storage update

### v7.17.4
- **Screenshot Storage Rewrite — File-Based with Persistent History**
  - Root cause fix: previous versions (7.17.1-7.17.3) returned base64 data URIs (1-3MB each) which overwhelmed the JSON → WordPress AJAX → browser chain, causing screenshots to silently fail
  - Screenshots now saved as JPEG files on the hub server under `screenshots/` directory with UUID filenames
  - Hub returns lightweight URLs instead of data URIs — response drops from ~6MB to ~500 bytes
  - New `screenshots` database table stores metadata: site_id, url, viewport, phase, run_id, filename, dimensions, size, format
  - Auto-migration: `ensureScreenshotTable()` creates the table on first use if it doesn't exist
  - `run_id` UUID groups before/after capture pairs for history tracking
  - Plugin AJAX handler now passes `phase` (before/after) and `run_id` to hub endpoint
  - JS updated: checks `data.desktop.url` instead of `data.desktop.data_uri` for success detection
  - Baseline capture sends `phase: 'before'`, final capture sends `phase: 'after'` with same `run_id`
  - `screenshotUrl()` helper builds full public URL from filename
  - `cleanupOldScreenshots()` utility removes files + DB records older than configurable retention period
- **Hub Screenshot History API**
  - New `GET /api/v1/screenshot/history` endpoint returns grouped before/after captures per site
  - Supports `url` filter and configurable `limit` (default 20, max 100)
  - `getScreenshotHistory()` returns structured array with before/after desktop/mobile per run
- **Hub Admin Screenshots Page**
  - New "Screenshots" page in hub admin navigation (📸 icon)
  - Browse all screenshot captures grouped by optimization run
  - Filter by site and URL
  - Stats header shows total runs, files, and disk usage
  - Before/After columns with desktop + mobile thumbnails
  - Thumbnails link to full-size images in new tab
  - Badges indicate "Before & After" vs "Before Only" captures
  - Responsive grid layout adapts mobile screenshots to narrower column
- **Hub Infrastructure**
  - New `screenshots/` directory with `.htaccess` (Options -Indexes) for direct file serving
  - `screenshots` table added to `database/schema.sql`
  - `screenshot/history` route added to API v1 router
  - Navigation updated in `layout-header.php`

### v7.17.3
- **Interactive Screenshot Comparison with Lightbox**
  - "Before" screenshots now display immediately after baseline capture (no waiting for optimization to finish)
  - "After" column shows a spinner placeholder until final screenshots are captured
  - After screenshots slide into place beside the baseline when ready
  - Clicking any screenshot opens a fullscreen lightbox overlay with side-by-side Before/After comparison
  - Lightbox supports keyboard close (Esc), click-outside-to-close, and close button
  - Desktop and mobile viewports shown separately — lightbox adapts width for mobile screenshots
  - Screenshots moved to dedicated `#ai-screenshots` container (independent from score comparison)
  - Dark overlay (92% opacity) with fade-in animation for professional comparison experience
  - Hover effect on thumbnail images indicates clickability
  - New CSS: `.ccm-lightbox-overlay`, `.ccm-lightbox-header`, `.ccm-lightbox-body`, `.ccm-lightbox-panel`, `.ccm-lightbox-label`, `.ccm-screenshot-waiting`, `.ccm-screenshot-placeholder`
  - New JS: `aiShowBaselineScreenshots()`, `aiShowAfterScreenshots()`, `aiOpenScreenshotLightbox()` replace old `aiRenderScreenshotComparison()`
  - Responsive: lightbox collapses to single-column on mobile
  - Body scroll locked while lightbox is open

### v7.17.2
- **Screenshot Comparison Moved into Results Section**
  - Before/after screenshots now render inside the Before/After Comparison block alongside score tables
  - Removed separate `#ai-screenshot-compare` container — screenshots append to `#ai-before-after` via `insertAdjacentHTML`
  - Unified results view: score comparison + visual comparison in one cohesive section
  - CSS updated to scope screenshot heading styles within `#ai-before-after`

### v7.17.1
- **Visual Screenshot Comparison — Before/After Layout Regression Detection**
  - Captures full-viewport screenshots (desktop 1920×1080 + mobile 375×812) before and after optimization
  - Detects content shifts, missing sections, images out of place, and layout breakage caused by performance changes
  - Baseline screenshots captured after PageSpeed tests but before any AI optimization changes
  - Final screenshots captured after the optimization loop completes (only when changes were applied)
  - Side-by-side comparison UI: Before/After columns for both desktop and mobile viewports
  - Images clickable to open full-size in new tab for detailed inspection
  - **Hub endpoint**: New `POST /api/v1/screenshot/capture` — launches headless Chromium with `--screenshot` flag
  - **Hub utility**: New `includes/screenshot.php` with `captureScreenshot()` and `captureScreenshots()`
  - Chromium flags: `--headless=new --no-sandbox --disable-gpu --hide-scrollbars --force-device-scale-factor=1 --run-all-compositor-stages-before-draw --ignore-certificate-errors`
  - PNG output converted to JPEG via GD library (82% quality) for ~5-10× smaller transfer size
  - Mobile emulation via iPhone 17 user-agent string for accurate mobile rendering
  - Returned as base64 data URIs for direct use in `<img>` elements (no temp file cleanup needed client-side)
  - New plugin AJAX handler: `ccm_tools_ai_hub_screenshot` proxying to hub endpoint
  - New JS function: `aiRenderScreenshotComparison(before, after)` renders comparison grid
  - New CSS: `.ccm-screenshot-row`, `.ccm-screenshot-col`, `.ccm-screenshot-label`, `.ccm-screenshot-col-mobile`
  - Responsive: single-column layout on mobile (≤768px)
  - Activity log shows capture progress with file sizes and format info
  - Graceful degradation: screenshot failures logged as warnings, optimization continues normally

### v7.17.0
- **Console Error Checking — Automated JS Functionality Protection**
  - New safety check in the One-Click Optimize pipeline that detects when performance changes break JavaScript functionality
  - Uses headless Chromium on the hub server to load the optimized page and capture console errors, uncaught exceptions, and unhandled promise rejections
  - **Baseline comparison**: captures pre-existing console errors before optimization, then only reacts to NEW errors introduced by changes
  - **Automatic rollback**: if new console errors are detected after applying changes, the optimizer rolls back immediately — regardless of score improvement
  - Prevents JS Delay, Defer JS, and other script optimizations from silently breaking interactive features (navigation, modals, sliders, etc.)
  - Console error context passed to AI for next iteration so it avoids problematic settings
  - **New step in progress UI**: "Console Check" step added between Re-test and Compare
  - Activity log shows detailed error messages with source file and line number
  - **Hub endpoint**: New `POST /api/v1/console/check` — loads URL in headless Chromium, parses `--enable-logging=stderr` output for CONSOLE entries
  - **Hub utility**: New `includes/console-checker.php` with `findChromiumBinary()`, `checkConsoleErrors()`, `deduplicateConsoleEntries()`, `diffConsoleErrors()`
  - Zero external dependencies — communicates with Chromium directly via `proc_open` and stderr parsing
  - Chromium flags: `--headless=new --no-sandbox --disable-gpu --disable-dev-shm-usage --enable-logging=stderr`
  - Configurable wait time (5-30 seconds) to capture delayed script errors (JS Delay fallback timeout)
  - New plugin AJAX handler: `ccm_tools_ai_hub_console_check` proxying to hub endpoint
  - Rollback decision logic updated: `keepChanges = (bothStable || netPositive) && !hasNewConsoleErrors`

### v7.16.2
- **Multiple Screenshot Uploads in AI Chat**
  - File input now accepts multiple images (up to 5 per message)
  - Preview area shows all attached images as a grid of thumbnails with individual remove (×) buttons on each
  - Redesigned preview: per-image red circular remove button (positioned top-right), wrapping flex layout
  - Message bubbles display all attached images in a flex row before the text
  - Plugin AJAX handler accepts `images` as JSON array of data URIs, validates each independently
  - Hub receives `images` array and builds Claude multimodal message with multiple image content blocks
- **AI Chat Now Has Error Log Context**
  - Last 50 lines of the site's PHP error log are automatically included in the AI system prompt
  - AI can now identify PHP fatal errors, plugin conflicts, deprecated function warnings etc.
  - Uses existing `ccm_tools_read_error_log()` — capped at 8 KB to avoid oversized requests
  - Hub system prompt appends error log under "Recent Error Log" section in a code block
- **Fixed Truncated AI Chat Responses**
  - Increased `max_tokens` cap from 4,096 to 8,192 for chat responses
  - Prevents AI responses from being cut off mid-sentence during detailed troubleshooting
- **CSS Changes**
  - `.ccm-ai-chat-image-preview` now uses `flex-wrap: wrap` for multi-image grid
  - New `.ccm-ai-chat-image-preview-item` with relative positioning for remove button overlay
  - Remove button changed from text to circular red badge with white ×
  - New `.ccm-ai-chat-msg-images` flex wrapper for multiple images in message bubbles
  - Slightly smaller thumbnails (56px preview, 160×120px in messages) to accommodate multiple images

### v7.16.1
- **AI Chat Screenshot Upload via Claude Vision API**
  - Users can now attach screenshots (PNG, JPEG, GIF, WebP) to AI Troubleshooter chat messages
  - Image button (landscape icon) added to chat input area next to the send button
  - Click to select an image file (max 5 MB) — shown as a thumbnail preview above the input
  - Preview has a remove (×) button to cancel before sending
  - Images displayed as thumbnails in user message bubbles (clickable to open full-size)
  - AI receives the image via Claude's Vision API (base64 content blocks) for visual analysis
  - Enables diagnosing visual glitches, broken layouts, FOUC, missing elements directly from screenshots
  - Screenshot Analysis section added to hub system prompt — instructs AI how to analyze visual issues
  - Conversation history stores `[screenshot attached]` text (not full base64) to keep token usage manageable
  - Plugin AJAX handler validates image data URI format and extracts media type + base64 data
  - Hub ai-chat.php builds multimodal Claude message with `image` + `text` content blocks
  - Timeout increased to 90 seconds for image-containing requests (vision processing takes longer)
  - New CSS: `.ccm-ai-chat-image-preview`, `.ccm-ai-chat-attach`, `.ccm-ai-chat-msg-image`, `.ccm-ai-chat-input-row`

### v7.16.0
- **Video Optimization Settings for PageSpeed Performance**
  - New **Video Lazy Load** setting: replaces below-fold `<video>` elements with lightweight poster placeholder + play button facade
    - On click, restores the original `<video>` element and auto-plays
    - First video on page excluded automatically (likely above-fold/LCP)
    - Autoplay+muted background videos excluded (must play immediately)
    - Uses base64-encoded data attribute to store original HTML safely
  - New **Video Preload: None** setting: sets `preload="none"` on non-autoplay `<video>` elements
    - Prevents browser from downloading video data until user clicks play
    - Reduces initial page weight significantly on video-heavy pages
    - Autoplay videos untouched (they need preload to play)
  - UI toggles added to Performance Optimizer page (after YouTube Lite Embeds section)
  - Settings saved/loaded via existing save handler with proper sanitization
  - Import/export support for both new boolean keys
  - JS: Added to `PERF_SETTING_KEYS` set and `aiSettingLabel()` labels
- **AI Video Awareness (Hub-Side)**
  - Page analyzer now extracts `<video>` tags: src, poster, autoplay, muted, loop, preload, class, id, dimensions, nested `<source>` elements
  - Third-party video domains tracked alongside existing resource domains
  - AI Analyze prompt: comprehensive "Video LCP & Optimisation Rules" (7 rules) covering video as LCP problem, poster image importance, preload attributes, lazy loading strategy, poster preloading, compression advice
  - AI Optimize prompt: same video rules (6 condensed) + video settings in available settings list
  - Both prompts include video elements in user message with `poster=MISSING` detection and `⚠ VIDEO LCP WARNING`
  - AI Chat prompt: video settings descriptions added for troubleshooting context
  - `video_lazy_load` and `video_preload_none` registered as recommendable settings in both analyze and optimize endpoints
- **Dashboard PageSpeed UI Redesign**
  - Prominent Performance hero circle (72px, 4px colored border) as focal point
  - Clean 50/50 Mobile/Desktop split with vertical separator
  - Secondary scores (Accessibility, Best Practices, SEO) as compact colored dot + label rows
  - Meta footer with background color showing tested URL and relative time
  - Google-standard color coding (green 90+, orange 50-89, red 0-49)
  - Responsive: switches from side-by-side to stacked on mobile
  - New CSS classes: `.ccm-card-ps`, `.ccm-dashboard-ps-hero-circle`, `.ccm-dashboard-ps-secondary-dot`

### v7.15.1
- **Fixed "key.replace is not a function" Error in Recent Results History**
  - Optimization run history stored `changes` as objects `{key, from, to}` (enriched in v7.13.1 for AI Learning Memory)
  - `aiHubLoadHistory()` passed these objects directly to `aiSettingLabel(key)` which expects a string
  - The fallback `key.replace(/_/g, ' ')` threw TypeError on object input
  - Fixed: extracts `.key` property from object entries, falls back to `String(c)` for plain strings
  - Recent Results History section now renders correctly with enriched change data

### v7.15.0
- **PageSpeed Scores on Dashboard**
  - Latest Mobile and Desktop PageSpeed scores displayed on the System Info dashboard
  - Card only renders when AI Hub API key is configured
  - Shows all 4 scores (Performance, Accessibility, Best Practices, SEO) with Google-standard color coding
  - Scores loaded asynchronously via new `ccm_tools_ai_hub_get_latest_scores` AJAX handler
  - Shows tested URL and relative time ("2h ago", "1d ago")
  - "Performance →" button links directly to the Performance Optimizer page
  - Fallback message with "Run a test" link when no results exist
  - Responsive grid: side-by-side on desktop, stacked on mobile
  - Smaller score circles (56px) to fit dashboard card layout
  - New CSS: `.ccm-dashboard-ps-grid`, `.ccm-dashboard-ps-strategy`, `.ccm-dashboard-ps-scores-row`, `.ccm-dashboard-ps-meta`
  - New JS: `loadDashboardPageSpeedScores()` with standalone scoring utilities

### v7.14.2
- **Reset Step Indicators on Rollback Iteration**
  - When the optimizer rolls back and retries, steps from AI Analysis onward now reset to "pending" (grey) instead of keeping stale "done" (green) state from the previous iteration
  - Matches the existing behavior on the "keep iterating" path which already reset steps
  - Gives clear visual feedback that a new iteration cycle is starting

### v7.14.1
- **Fixed Step Indicator Icon Rendering Artifacts**
  - Step progress circles (checkmark, X, dash) had visual artifacts due to inherited `font-size: 0.7rem` being too small for Unicode characters
  - Set `font-size: 0` on the indicator element itself to prevent any text content leakage
  - Added explicit `font-size: 14px` and `line-height: 1` on `::after` pseudo-elements for done/error/skipped states
  - Added explicit property resets (`width: auto; height: auto; background: none; border-radius: 0`) on text-based states to prevent active state (white dot) properties from bleeding through
  - Changed `transition: all 0.3s` to `transition: border-color 0.3s, background-color 0.3s` to avoid unexpected property transitions
  - Added `overflow: hidden` and `flex-shrink: 0` for clipping and layout stability
  - Increased indicator size from 24px to 28px for better visibility
- **Improved One-Click Optimize Error Handling**
  - Failed step now shows the actual error message (truncated to 40 chars) instead of generic "Failed"
  - Remaining pending steps are marked as "skipped" (dimmed) when the flow aborts early
  - Gives users clear visibility into what went wrong and which steps were not attempted

### v7.14.0
- **AI Troubleshooter Chat — Diagnose Optimization Issues in Real-Time**
  - New floating chat widget on the Performance Optimizer page
  - Conversational AI assistant specialized in diagnosing issues caused by performance optimizations
  - Click the "AI Help" button to open a slide-up chat panel
  - Describe broken functionality (e.g., "progress bar animations stopped working") and AI identifies the likely culprit setting
  - Full conversation history maintained within the session (up to 20 messages)
  - AI receives current performance settings, optimization history, and site context automatically
  - Markdown rendering in AI responses (code blocks, bold, lists, headers)
  - Typing indicator with animated dots while AI processes
  - Clear chat and close buttons in header
  - Auto-resizing textarea input with Enter-to-send (Shift+Enter for newline)
  - Responsive design — full-width on mobile, 420px panel on desktop
  - **Hub endpoint**: New `api/v1/ai/chat` endpoint with dedicated troubleshooting system prompt
  - System prompt includes all 30+ setting descriptions, common breakage patterns, and diagnostic methodology
  - Uses conversation history for multi-turn context (asks follow-up questions when needed)
  - New AJAX handler: `ccm_tools_ai_chat` with conversation history and settings context
  - New CSS: `.ccm-ai-chat-widget`, `.ccm-ai-chat-panel`, `.ccm-ai-chat-messages`, typing animation, markdown styles
  - New JS: `initAiChat()`, `aiChatSend()`, `aiChatFormatMarkdown()`, `aiChatAppendMessage()`, `aiChatClear()`

### v7.13.1
- **AI Learning Memory — Persistent Optimization History for Smarter AI Decisions**
  - New `ccm_tools_build_learnings_context()` function reads past optimization runs from `wp_options` and builds a structured summary
  - Categorizes settings into "IMPROVED scores" (repeat) and "CAUSED ROLLBACKS" (avoid) with per-setting score deltas
  - Includes best scores achieved, run count stats (improved/rolled back/no changes), and last 3 run details
  - Learnings context automatically injected into both AI analyze and optimize handlers
  - URL-based filtering: matches runs by exact URL or same domain for cross-page learning
  - **Enriched run data**: `save_run` handler now stores `from`/`to` values per changed setting (not just keys)
  - JS now passes target URL to the analyze AJAX call for accurate learnings lookup
  - **Hub AI prompt updates**: Both `ai-analyze.php` and `ai-optimize.php` system prompts include new "Previous Optimization History (AI Memory)" section
  - AI instructed to: repeat proven winners, avoid repeated rollback causes, use score deltas for impact assessment, avoid redundant recommendations

### v7.13.0
- **Smart Rollback Algorithm — Net-Positive Score Evaluation**
  - Replaced aggressive rollback logic that reverted any iteration where EITHER Mobile or Desktop dropped
  - Old logic: `scoreDropped = mobileChange < 0 || desktopChange < 0` — rolled back even when net gain was +18 (e.g., Mobile +29, Desktop -11)
  - New logic uses **net-positive evaluation**: keeps changes if both within PSI noise tolerance (±3pts) OR net positive AND neither dropped >15pts
  - `PSI_NOISE` constant (3 points) accounts for normal PageSpeed measurement variance
  - **Snapshot-based comparison**: compares against snapshot scores (updated after each successful keep), not the original baseline
  - After KEEP: saves new snapshot, updates snapshot scores, and continues iterating toward 90+ on both strategies
  - After ROLLBACK: snapshot scores unchanged, retries with conservative approach
  - Fixes core issue where excellent results (Mobile 94 from baseline 65) were thrown away due to minor Desktop fluctuation
- **Pre-flight Tool Check — Auto-Enable Server-Side Optimizations**
  - New "Pre-flight Check" step runs before the optimization loop
  - Automatically detects and enables missing server-side tools:
    - **.htaccess**: Applies caching headers, Gzip/Brotli compression, security headers, ETag removal, directory index protection, HSTS
    - **WebP**: Enables with on-demand conversion, picture tags, and background image conversion
    - **Redis**: Tests connection and installs object-cache.php drop-in (if PHP Redis extension available)
    - **Performance Optimizer**: Enables master toggle if disabled
    - **Database**: Reports tables needing InnoDB/utf8mb4 optimization (informational)
  - New AJAX handler: `ccm_tools_ai_preflight` — returns status of all tools
  - New AJAX handler: `ccm_tools_ai_enable_tool` — enables individual tools with appropriate defaults
  - New PHP function: `ccm_tools_get_optimization_status()` — checks htaccess (applied, writable, detected options), WebP (available, enabled, settings), Redis (extension, drop-in, connected), database (tables needing optimization), performance (enabled)
  - 3-second settling delay after enabling tools for server-side changes to take effect before baseline test
- **AI Context Enhancement — Tool Status Awareness**
  - AI analysis handler now includes tool status warnings in the context sent to hub
  - WARNING messages appended for: .htaccess not applied, WebP not enabled, Redis available but inactive, database tables needing optimization
  - Helps AI provide contextual recommendations about server-side improvements
- **Hub AI Prompt Updates — Server-Side Tools & Strategy Awareness**
  - Both `ai-analyze.php` and `ai-optimize.php` system prompts updated with:
    - "Server-Side Optimisation Tools" section explaining .htaccess, WebP, Redis, Database impact
    - "Strategy Awareness" section explaining Mobile vs Desktop scoring differences
    - Guidance that mobile is harder (throttled 4G), CSS/JS optimisations affect mobile more, server-side improvements benefit both equally
    - Instruction to note missing tools in `additional_notes`
    - Instruction to prioritise safe changes that help BOTH strategies
- **Improved Run Outcome Tracking**
  - Save run `outcome` now distinguishes between `improved`, `rolled_back`, and `no_changes` (was just `completed` or `no_changes`)

### v7.12.9
- **Updated Hub Claude AI Models to Latest Versions**
  - Sonnet 4 → Sonnet 4.6 (`claude-sonnet-4-6`), $3/$15 per MTok
  - Opus 4 → Opus 4.6 (`claude-opus-4-6`), $5/$25 per MTok (was $15/$75)
  - Haiku 3.5 → Haiku 4.5 (`claude-haiku-4-5-20251001`), $1/$5 per MTok (was $0.80/$4)
  - Legacy model IDs preserved in pricing table for historical cost calculations
  - Updated model descriptions and recommendation text in hub admin settings

### v7.12.8
- **Increased AI Optimization Max Iterations from 3 to 10**
  - Allows the optimizer to keep retrying after rollbacks until the hub API rate limit is reached
  - Prevents premature "Max iterations reached after rollback" when there are still API calls available

### v7.12.7
- **Redesigned Recent Results History**
  - Optimization Runs section shows before→after scores for both Mobile and Desktop on the same line
  - Color-coded score changes with delta indicators (+5, -3, etc.)
  - Outcome badges: Improved, Rolled Back, No Changes, Complete
  - Shows iteration count when multiple iterations were used
  - Lists all changed settings as compact tags (e.g., "Defer JavaScript", "Async CSS Loading")
  - Card-based layout with hover effects
- **PageSpeed Test Results — Paired Mobile + Desktop**
  - Mobile and Desktop results from the same test run are paired by timestamp proximity (within 5 minutes)
  - Each card shows both strategies side-by-side with performance score, secondary scores, and LCP
  - `aiPairResults()` pairs and deduplicates from separate strategy API responses
- **Optimization Run Persistence**
  - New AJAX handler `ccm_tools_ai_save_run` stores optimization run summaries in `wp_options`
  - Tracks: URL, before/after scores (both strategies), changes made, iteration count, rollback status, outcome
  - Stores up to 20 runs, shown alongside PageSpeed test history
  - Run saved automatically at the end of each One-Click Optimize session
- **PHP Changes**
  - `ccm_tools_ajax_ai_hub_get_results` now fetches both mobile and desktop results from hub in parallel
  - Returns `{ mobile: [...], desktop: [...], runs: [...] }` instead of single-strategy results
  - New `ccm_tools_ajax_ai_save_run` handler with sanitized run data storage
- **New CSS Components**
  - `.ccm-ai-run-card`, `.ccm-ai-run-scores`, `.ccm-ai-run-strategy`, `.ccm-ai-run-change-tag` — optimization run cards
  - `.ccm-ai-result-card`, `.ccm-ai-result-scores`, `.ccm-ai-result-score` — paired PageSpeed result cards
  - Responsive rules for mobile layout at 768px breakpoint

### v7.12.6
- **Live Activity Log (Terminal-Style Output)**
  - New dark terminal-style scrollable log panel shows real-time updates during optimization
  - `aiLog(message, type)` function with timestamped entries and color-coded prefixes (INFO, DONE, WARN, FAIL, STEP, AI)
  - Logs every step: snapshot, PageSpeed tests, AI analysis details, each setting change, rollback events, retries
  - Styled with Catppuccin Mocha theme — dark background, monospace font, custom scrollbar
  - Clear button to reset the log
  - Also enabled during "Test Only" flow
- **Accordion PageSpeed Results**
  - Scores remain always visible in the results area
  - Metrics and Opportunities sections wrapped in collapsible `<details>` accordions
  - Custom accordion styling with animated triangle marker
  - Opportunities accordion auto-opens when issues are found
- **Google-Standard Color-Coded Scores**
  - New `aiScoreColorClass()` utility following Google's PSI thresholds: green (90-100), orange (50-89), red (0-49)
  - Score circles rendered as bordered circular elements with matching color
  - Metrics table values color-coded using per-metric good/poor thresholds (e.g., LCP: green ≤2500ms, orange ≤4000ms, red >4000ms)
  - Before/After comparison table scores color-coded individually
  - History table uses same color scheme
  - New CSS classes: `.ccm-score-green`, `.ccm-score-orange`, `.ccm-score-red`
  - New CSS: `.ccm-ai-score-circle`, `.ccm-ai-score-circle-wrap`, `.ccm-ai-score-label`
- **Remaining Recommendations Panel (Push for 90+)**
  - When final scores are below 90, shows a "Remaining Recommendations to Reach 90+" panel
  - Merges and deduplicates PageSpeed opportunities from both Mobile and Desktop strategies
  - Shows strategy badges per opportunity (Mobile, Desktop, or both)
  - `aiGetOpportunityGuidance()` provides actionable how-to-fix text for 25+ common PSI audits
  - AI manual actions displayed separately with distinct styling
  - Panel styling: orange left border, collated list with savings badges
- **New CSS Components**
  - `.ccm-ai-log`, `.ccm-ai-log-header`, `.ccm-log-entry`, `.ccm-log-prefix` — terminal log styling
  - `.ccm-ai-accordion`, `.ccm-ai-accordion-summary`, `.ccm-ai-accordion-body` — accordion styling
  - `.ccm-ai-remaining-panel`, `.ccm-ai-remaining-item`, `.ccm-ai-remaining-guidance` — recommendations panel
  - Responsive rules for all new components at 768px breakpoint

### v7.12.5
- **Live Settings Update After AI Apply**
  - Fixed `enabled` setting key not mapping to `#perf-master-enable` DOM element (was mapping to non-existent `#perf-enabled`)
  - Added `idOverrides` map in `aiUpdatePageToggles()` for special key-to-DOM-ID mappings
  - Master enable toggle, status text, and detail section visibility now update live after AI applies changes
  - Checkbox change events only fire when the value actually changes (avoids unnecessary toggles)
  - No page refresh required — all Performance Optimizer toggles, textareas, selects, and text inputs update in real time

### v7.12.4
- **Fully Automated One-Click Optimize**
  - Removed manual review/confirmation step — all AI recommendations are applied automatically
  - Removed "Review Fixes" step from progress indicator (now 8 steps: Snapshot → Test → Analyze → Apply → Retest → Compare)
  - Fix summary now shows informational cards (impact + risk badges) instead of checkboxes
  - Removed `aiWaitForConfirmation()` function (dead code)
  - Entire flow runs hands-free: snapshot → baseline test → AI analysis → auto-apply → retest → evaluate → iterate if needed
  - Manual fixes still displayed as informational items for user reference

### v7.12.3
- **Iterative AI Optimization with Rollback**
  - **Hub `ai-optimize.php` — Deep Analysis Rewrite**
    - `runAiAnalysis()` completely rewritten to match the quality of `ai-analyze.php`
    - Now extracts full PSI audit data from stored `full_response`: render-blocking resources, LCP element, third-party summary, unused JS (top 15), unused CSS (top 15), diagnostics
    - Fetches live page resources via `fetchPageResources()`: scripts, stylesheets, images, third-party domains, above-fold HTML, CSS content, inline styles
    - Full detailed system prompt with all 30+ CCM Tools setting keys, CSS generation rules, script analysis rules, preconnect rules
    - Critical CSS generation rules: above-fold only, actual selectors, minified, under 15KB
    - Explicit safety rules: never enable `preload_css` without `critical_css_code`, never enable `delay_js` without confidence
    - Response format includes `risk` field per recommendation and `score_assessment`
  - **Hub `ai-optimize.php` — Score-Drop Aware Retest Logic**
    - Retest now detects score regressions and sends `score_dropped: true` + `rollback: true` flags
    - When scores drop: sends detailed context to AI about WHAT was applied and WHAT happened, instructs conservative approach
    - AI told to NOT re-recommend settings that caused the regression
    - Separate paths for: score dropped (rollback + conservative retry), improved but <90 (iterate for more), good enough (complete)
    - Continuation iterations include full current settings and score change history
  - **Plugin `ai-hub.php` — Snapshot & Rollback**
    - New AJAX handler: `ccm_tools_ai_snapshot_settings` — saves current perf settings to a transient (1 hour TTL) before optimisation
    - New AJAX handler: `ccm_tools_ai_rollback_settings` — restores settings from the saved snapshot
    - Snapshot is taken before any changes are applied and updated after each successful iteration
  - **JS One-Click Flow — Iterative Improvement Loop**
    - New step: "Save Snapshot" before testing (9 steps total)
    - After applying and re-testing, compares scores against original baseline
    - If scores dropped: automatically rolls back to snapshot, notifies user, and retries with a fresh AI analysis (up to 3 iterations)
    - If scores improved but below 90: saves new snapshot as checkpoint, clears fix summary, re-runs AI analysis for additional gains
    - If scores ≥90 or max iterations reached: completes with before/after comparison
    - Step progress UI resets between iterations (analyze → review → apply → retest → compare cycle)
    - Page toggles updated to rolled-back state on rollback
    - `AI_MAX_ITERATIONS` constant (default: 3) controls maximum retry attempts

### v7.12.2
- **AI Performance Optimizer — Button Uniformity & Layout Fixes**
  - Fixed mismatched button heights between "One-Click Optimize" (was 1rem font + large padding) and "Test Only" (default smaller sizing)
  - All buttons in AI controls row now share consistent `height: 2.5rem` with matching padding
  - Hub Connection row replaced inline CSS grid with proper `.ccm-ai-connection-row` flex layout
  - API Key input, Save, and Test buttons now align cleanly at the same height
  - URL input field and action buttons share the same `2.5rem` height via scoped rules
  - Removed inline `style` attributes from HTML in favour of CSS classes
  - Added mobile responsive rules for `.ccm-ai-connection-row` (stacks vertically, full-width buttons)
  - `.ccm-ai-cta` simplified to font-weight and padding override only (no font-size override)

### v7.12.1
- **Deep AI Analysis — Live Page Fetching + Resource-Aware Recommendations**
  - Hub now fetches the actual website HTML during AI analysis to provide concrete, data-driven recommendations
  - New shared `includes/page-analyzer.php` with `fetchPageResources()` — parses scripts, stylesheets, images, inline styles, above-fold HTML, and third-party domains from live page
  - `fetchMainCssContent()` fetches up to 4 CSS files (60KB limit, same-origin priority) for critical CSS generation
  - `extractAboveFoldHtml()` provides first 4KB of cleaned body structure for above-fold analysis
  - `calculateClaudeCost()` with per-model pricing (Sonnet 4, Opus 4, Haiku 3.5)
- **Critical CSS Generation**
  - AI analyzes actual page HTML structure + fetched CSS to generate real critical CSS code
  - Prompt instructs Claude to include only above-fold rules, keep under 15KB, minify output
  - `critical_css_code` applied directly to Performance Optimizer settings
  - Parent toggle `critical_css` auto-enabled when CSS code is set
- **JS Defer/Delay Script Analysis**
  - AI receives full list of page scripts with src, id, defer/async status
  - Detailed prompt rules: jQuery always excluded from defer, analytics/tracking ideal for delay, theme scripts need careful testing
  - Returns concrete `defer_js_excludes` and `delay_js_excludes` arrays
- **Preconnect & DNS Prefetch Domain Identification**
  - Third-party domains extracted from page resources (scripts, stylesheets, images)
  - AI categorizes: fonts/CDN → preconnect, analytics/tracking → DNS prefetch only
  - Returns `preconnect_urls` and `dns_prefetch_urls` arrays
- **LCP Image Preload Detection**
  - AI identifies the Largest Contentful Paint image from PSI data + page images
  - Returns `lcp_preload_url` for the specific image URL to preload
  - Parent toggle `lcp_preload` auto-enabled when URL is set
- **Hub Admin Model Selection**
  - AI tab in hub settings now has model dropdown with pricing info per model
  - Shows capability descriptions (Sonnet 4 — best balance, Opus 4 — highest quality, Haiku 3.5 — fastest)
  - Max tokens default increased to 16,384 (was 4,096) for deep analysis needs
  - Upper limit increased to 65,536
- **Enhanced Plugin Apply Recommendations**
  - `ccm_tools_ai_hub_apply_recommendations()` rewritten for complex value types
  - CSS keys use custom sanitization (strips PHP tags only, preserves CSS syntax)
  - URL arrays sanitized via `esc_url_raw`, string arrays via `sanitize_text_field`
  - `$data_to_toggle` mapping auto-enables parent boolean toggles (e.g., setting `preconnect_urls` enables `preconnect`)
- **Enhanced JS for New Setting Types**
  - `PERF_SETTING_KEYS` expanded with data keys: `critical_css_code`, `preconnect_urls`, `dns_prefetch_urls`, `lcp_preload_url`, exclude lists, etc.
  - New `aiFormatValue()` for displaying arrays and long strings in fix summaries
  - `aiUpdatePageToggles()` handles textarea, select, text/url/number inputs (not just checkboxes)
- **Model**: Claude Sonnet 4.6 (`claude-sonnet-4-6`) as default
- **Both ai-analyze.php and ai-optimize.php** use shared page-analyzer.php utilities

### v7.12.0
- **Combined AI + Performance Pages into One Page**
  - AI Performance section now renders at the top of the Performance Optimizer page
  - Removed separate AI submenu page and AI tab from header navigation
  - AI section uses collapsible Hub Connection details panel
  - `ccm_tools_render_ai_hub_page()` replaced with `ccm_tools_render_ai_section()` (embeddable)
  - `ccm_tools_render_perf_page()` now calls `ccm_tools_render_ai_section()` before master toggle
- **One-Click Optimize with Dual Strategy (Mobile + Desktop)**
  - "One-Click Optimize" button runs full pipeline: test → analyze → review → apply → retest → compare
  - Tests both Mobile AND Desktop in a single run (no strategy dropdown needed)
  - Visual 8-step progress indicator with animated states (pending, active, done, error, skipped)
  - AI analysis based on mobile results (primary strategy) via Claude on the hub
- **Auto-Fix vs Manual Fix Categorization**
  - Recommendations split into auto-fixable (matching perf optimizer setting keys) and manual fixes
  - Auto-fixable items shown as checkboxes — user can select/deselect before applying
  - Manual items shown as informational cards with descriptions
  - "Apply Selected Changes" and "Skip" buttons with Promise-based confirmation flow
- **New AJAX Handler: `ccm_tools_ai_apply_changes`**
  - Accepts JSON array of recommendations, applies via existing `ccm_tools_ai_hub_apply_recommendations()`
  - Returns before/after diff of changed settings for live UI update
- **Live Settings Update Without Page Reload**
  - `aiUpdatePageToggles()` updates on-page checkbox states in real time after applying changes
  - Maps setting keys to DOM IDs (`defer_js` → `#perf-defer-js`) and dispatches change events
- **Before / After Comparison**
  - Side-by-side grid comparing Mobile and Desktop scores before and after optimization
  - Color-coded change indicators (green for improvement, red for regression)
- **Dual Strategy Result Tabs**
  - Results area has Mobile / Desktop tab switcher
  - Each tab has its own scores grid, metrics table, and opportunities list
  - "Test Only" button also runs both strategies without AI analysis
- **New CSS Classes**
  - `.ccm-ai-hero-card` — accent-bordered hero card for AI section
  - `.ccm-ai-steps` / `.ccm-ai-step` — horizontal step progress indicator with `@keyframes ccm-pulse`
  - `.ccm-ai-strategy-tabs` / `.ccm-ai-tab` — tab switcher for Mobile/Desktop results
  - `.ccm-ai-fix-section` / `.ccm-ai-fix-item` — fix summary cards with auto/manual variants
  - `.ccm-ai-comparison` — before/after comparison grid
  - Responsive breakpoints for mobile layout

### v7.11.2
- **Fixed "Hub vunknown" After Test Connection**
  - Root cause: Hub's `jsonSuccess()` merges data flat into the response (no nested `data` wrapper), but `ccm_tools_ajax_ai_hub_test_connection` read `$result['data']['hub_version']` which doesn't exist
  - Fixed all AJAX handlers to read directly from `$result['hub_version']`, `$result['site_name']`, `$result['features']`, `$result['limits']`
  - Now correctly displays "Hub v1.0.0" after successful connection test
- **Fixed "Cannot read properties of undefined (reading 'result_id')" on PageSpeed Test**
  - Added null/non-array guard in `ccm_tools_ai_hub_request()` — returns `WP_Error` if hub response is not valid JSON
  - Previously, a non-JSON hub response (e.g., PHP error page) would return null, causing `wp_send_json_success(null)` which omits the `data` key entirely
  - JS then accessed `res.data.result_id` where `res.data` was `undefined` → TypeError
- **Defensive JS null checks** across all AI Hub AJAX response handlers
  - `res.data || {}` pattern in PageSpeed test, AI analyze, and AI optimize handlers
  - Prevents TypeErrors if response data is unexpectedly null or undefined
- **Cleaned up `$result['data'] ?? $result` pattern** in all AJAX handlers
  - Since hub responses are always flat, removed unnecessary `['data']` accessor with `??` fallback
  - Handlers now pass `$result` directly to `wp_send_json_success()`

### v7.11.1
- **Fixed AI Performance Page Buttons Not Working**
  - Root cause: AI Hub page wrapper used `class="wrap ccm-wrap"` instead of `class="wrap ccm-tools"`
  - The JS DOMContentLoaded guard `document.querySelector('.ccm-tools')` returned null, causing early return before any handlers were initialized
  - Fixed wrapper class to match all other plugin pages
  - Wrapped all page-specific JS initializers in try/catch to prevent cascading failures
- **Removed robot emoji** from nav tab, submenu label, page title, and Analyze button
- **Hidden Hub URL field** — now stored as hidden input (not user-editable)

### v7.11.0
- **AI Performance Hub — Centralized API Management + Plugin Integration**
  - **Hub application** (now private repo: ClickClickMedia/ccm-api-hub) for api.tools.clickclick.media
    - Google SSO restricted to @clickclickmedia.com.au domain
    - AES-256-CBC encrypted settings stored in MySQL (`.env` holds only DB credentials + encryption key)
    - 4-step setup wizard with super-admin bootstrapping (rik@clickclickmedia.com.au)
    - Admin dashboard with overview stats (sites, AI calls, PageSpeed tests, cost)
    - Sites management with full CRUD, API key generation (Argon2ID hashed), feature toggles
    - Usage analytics with filters (period, site, type), per-site breakdown, recent API calls
    - Tabbed settings page (General, OAuth, AI, PageSpeed, Limits, Session, Logging, Maintenance)
    - Dark theme CSS derived from WebWatch (--brand-primary: #94c83e)
    - Vanilla JS: toast notifications, modals, AJAX utilities, table sorting
  - **API v1 endpoints** proxying Claude and Google PageSpeed APIs:
    - `health` — connection test with feature/limit info
    - `pagespeed/test` — run PageSpeed Insights (with caching, force refresh)
    - `pagespeed/results` — retrieve cached results with filters
    - `ai/analyze` — Claude analysis of PageSpeed results with CCM Tools context
    - `ai/optimize` — full optimization session loop (start → retest → complete)
    - Per-site API key authentication, rate limiting, feature access control, usage logging
  - **Plugin-side integration** (`inc/ai-hub.php`):
    - Hub connection settings stored in `wp_options`
    - AJAX handlers: save settings, test connection, run PageSpeed, get results, AI analyze, auto-optimize
    - Auto-apply AI recommendations to Performance Optimizer settings (type-matched)
    - Admin page with score circles, metrics table, opportunities, AI analysis, session log, history
  - **Wired into ccm.php:** `require_once`, submenu page (🤖 AI Performance), nav tab
  - **JS handlers in main.js:** `initAiHubHandlers()` with save, test, PageSpeed, analyze, optimize session, history
  - New files: 35 files in hub (private repo), 1 file `inc/ai-hub.php`
  - Git branch: `feature/ai-performance`

### v7.10.15
- **Performance Optimizer Audit — 6 Bug Fixes**
  - **Fixed Settings Import dropping Async CSS exclude list**
    - `preload_css_excludes` was missing from the `$array_keys` import sanitization array
    - Importing a settings backup would silently reset the CSS exclude list to empty
    - Added `preload_css_excludes` to the import array sanitization alongside `defer_js_excludes` and `delay_js_excludes`
  - **Fixed YouTube Lite Embeds not clickable on non-singular pages**
    - The click handler script (`ccm_tools_perf_youtube_facade_script`) had an `is_singular()` guard
    - YouTube facades on archive pages, home page, and category pages showed the thumbnail but clicking did nothing
    - Removed the `is_singular()` check — click handler now outputs wherever facades are used
  - **Fixed Reduce Heartbeat having no effect (only applied to frontend)**
    - `ccm_tools_perf_init()` returned early for `is_admin()`, so the heartbeat filter was never added in the admin
    - WordPress Heartbeat API primarily runs in the admin (post editor, dashboard), making this setting useless
    - Heartbeat reduction now applies in admin too, even when other optimizations are frontend-only
  - **Added admin test mode for Performance Optimizer**
    - Administrators are bypassed from all frontend optimizations for safety
    - This made it impossible for admins to test or verify optimizations were working
    - Added `?ccm_test_perf=1` URL parameter support — append to any frontend URL to see optimizations as admin
    - Added testing tip notice on the Performance Optimizer settings page
  - **Added static caching to `ccm_tools_perf_get_settings()`**
    - Settings function was called 14+ times per page load (once per filter/action handler)
    - Each call ran `get_option()` + `wp_parse_args()` repeatedly
    - Now uses a static variable cache that persists for the request lifetime
    - Cache is automatically invalidated when settings are saved via `ccm_tools_perf_save_settings()`
  - **Removed dead code in emoji disabling function**
    - `remove_action('admin_print_scripts', ...)` and `remove_action('admin_print_styles', ...)` lines were dead code
    - These never executed because the function is only called on the frontend (init returns early for admin)

### v7.10.14
- **Added AVIF MIME Type Support to .htaccess Rules**
  - Added `AddType` block inside `mod_mime.c` to declare modern image MIME types
  - Ensures `image/avif` (.avif) and `image/avif-sequence` (.avifs) are served with correct Content-Type on older Apache installations (pre-2.4.52)
  - Also declares `image/webp`, `image/heic`, `image/heif`, `font/woff2`, and `application/wasm` for completeness
  - Added `image/avif-sequence` to `ExpiresByType` caching rules (animated AVIF)
  - Added `.avifs` extension to `FilesMatch` Cache-Control header pattern
  - Without these declarations, AVIF files may be served as `application/octet-stream` on hosts with outdated MIME databases

### v7.10.13
- **Fixed Async CSS Conflict Detection + ImageMagick /tmp/ Path Error**
  - **Hardened Async CSS Loading against double-processing by other plugins**
    - Previous check only looked for `media="print"` (double quotes) — WordPress and other optimization plugins (LiteSpeed Cache, WP Rocket, etc.) use single quotes `media='print'`
    - Now checks both single and double quote variants of `media='print'` and `rel='preload'`
    - Also checks for existing `onload=` attribute to prevent conflicts when another plugin has already made a stylesheet async
    - Prevents our filter from re-processing tags that are already non-blocking
  - **Fixed ImageMagick "Path is outside resolved document root" errors during WebP conversion**
    - ImageMagick creates temp files during format conversion; defaults to system `/tmp/` directory
    - Many hosting providers (especially shared hosting with CloudLinux/CageFS) restrict ImageMagick from accessing `/tmp/` via `open_basedir` or ImageMagick `policy.xml`
    - Now sets `MAGICK_TMPDIR` and `MAGICK_TEMPORARY_PATH` to `wp-content/uploads/ccm-webp-temp/` before processing
    - Temp directory is auto-created within the document root where ImageMagick has guaranteed access
    - Fixes errors like: `Path is outside resolved document root. input:/tmp/image-XXXXXX.webp`

### v7.10.12
- **Complete Performance Optimizer Audit — 4 Bug Fixes**
  - **Fixed Font Display Override CSS outputting ~2KB of dead CSS per page**
    - The `ccm_tools_perf_font_display_override_css()` function outputted empty `@font-face` rules (e.g., `@font-face { font-family: "Font Awesome 6 Free"; font-display: swap; }`) for 25+ icon fonts
    - CSS `@font-face` rules are **additive, not cascading** — a second `@font-face` with only `font-family` and `font-display` (no `src`) creates an incomplete entry the browser ignores
    - These overrides **never actually added `font-display: swap`** to external font CSS files like FontAwesome
    - Removed the entire function and its hook — saves ~2KB of useless CSS per page load
    - Note: Google Fonts are handled by URL modification, and self-hosted inline fonts are handled by the output buffer — both continue to work correctly
  - **Fixed Remove Query Strings only catching `?ver=` as first parameter**
    - Previous check used `strpos($src, '?ver=')` which missed URLs like `script.js?id=1&ver=5.0` where `ver` isn't the first query parameter
    - Changed to `strpos($src, 'ver=')` so `remove_query_arg()` is called for all URLs containing a `ver` parameter
  - **Fixed LCP Fetchpriority not working on `wp_get_attachment_image()` calls**
    - Only hooked `the_content` and `post_thumbnail_html` filters
    - Images rendered via direct `wp_get_attachment_image()` calls (common in page builders and theme templates like `front-page.php`) never received `fetchpriority="high"`
    - Added new `ccm_tools_perf_lcp_fetchpriority_attributes` filter on `wp_get_attachment_image_attributes` hook
    - Also removes `loading="lazy"` from LCP candidate images (lazy-loading the LCP image hurts performance)
    - Shares the same global `$ccm_lcp_priority_added` flag so only the first image on the page gets priority
  - **Cleaned up orphaned dead code in LCP section**
    - Lines 719–744 contained an abandoned function body trapped inside a doc comment
    - Was meant to be the `wp_get_attachment_image_attributes` filter but was never properly implemented
    - Replaced with the properly functioning `ccm_tools_perf_lcp_fetchpriority_attributes()` function

### v7.10.11
- **Cleaned Up Remaining libvips References in WebP Converter**
  - Removed stale libvips mention from `ccm_tools_webp_convert_image()` doc comment
  - Updated no-extensions-found help text to only reference GD and ImageMagick
  - Ensures production sites receiving the update have no vips code paths
  - Fixes PHP Fatal error on sites where vips extension returns unexpected array from `vips_image_new_from_file()`

### v7.10.10
- **Fixed Async CSS Loading (Preload CSS) Not Actually Eliminating Render-Blocking CSS**
  - Previous implementation only added a `<link rel="preload">` hint before the original stylesheet — the original `<link rel="stylesheet">` remained fully render-blocking
  - This was the primary reason performance optimizations had zero effect on PageSpeed scores
  - Reimplemented using the **print media trick**: sets `media="print"` (non-blocking) with `onload="this.media='all'"` to apply styles once loaded
  - Added `<noscript>` fallback for users without JavaScript
  - Added **exclude list** (`preload_css_excludes`) to keep critical stylesheets render-blocking
  - Renamed UI from "Preload CSS" to "Async CSS Loading" with accurate description
  - Added FOUC (Flash of Unstyled Content) warning — recommends pairing with Critical CSS
  - New exclude input field with comma-separated stylesheet handle names
  - This fix addresses the **3,720ms render-blocking savings** identified by PageSpeed Insights

### v7.10.9
- **Fixed Detect Scripts Giving Identical Results for Defer and Delay**
  - Both "Detect Scripts" buttons (Defer JS and Delay JS) were calling the same AJAX endpoint with no distinction
  - The PHP handler now accepts a `target` parameter (`defer` or `delay`) and provides different categorization logic
  - Delay mode flags theme/plugin scripts that handle above-the-fold interactivity (navigation, menus, sliders, modals, etc.) as unsafe to delay
  - Third-party scripts are highlighted as ideal delay candidates in delay mode
  - Reason text is now context-specific (e.g., "ideal candidate for delaying" vs "usually safe to defer")
  - UI labels (summary stats, category headings, status tooltips) now reflect the selected mode
  - JS now sends the `target` parameter to the AJAX handler

### v7.10.8
- **Removed libvips Support from WebP Converter**
  - Removed libvips as a WebP conversion option due to causing 500 errors
  - The PHP vips extension has complex API requirements that differ from standard implementations
  - WebP converter now uses ImageMagick (preferred) or GD Library only
  - These extensions are more widely available on WordPress hosting environments
  - Removed `ccm_tools_webp_convert_with_vips()` function
  - Removed vips extension detection from `ccm_tools_webp_get_available_extensions()`
  - Removed vips case from conversion switch in `ccm_tools_webp_convert_image()`

### v7.9.5
- **Fixed WebP Conversion Not Working After Reset**
  - Fixed critical bug where failed conversion transients were not cleared during reset/regenerate
  - Previous fix only cleared `_ccm_webp_conversion_failed` post meta but actual failures use transients
  - Now clears all `ccm_webp_failed_*` transients and the conversion queue during reset
  - Added `/wp-content/uploads/` fallback check to `ccm_tools_webp_queue_for_conversion()` function
  - Added `/wp-content/uploads/` fallback check to `ccm_tools_webp_filter_content_src()` function
  - Ensures URL matching works regardless of site URL configuration or CDN setup

### v7.9.4
- **Fixed WebP Picture Tag Conversion URL Matching**
  - Added `/wp-content/uploads/` fallback check in picture tag conversion function
  - Previous version only checked `$upload_dir['baseurl']` which could fail with CDNs or domain mismatches
  - Fixed path construction for WebP-to-original fallback (when source is already WebP)
  - Now properly handles both full baseurl matches and `/wp-content/uploads/` path patterns
  - Ensures images are correctly identified as local uploads regardless of URL format

### v7.9.3
- **Fixed WebP Not Working on Page Builder/Theme Images**
  - WebP conversion and picture tags now work on ALL images site-wide
  - Previous versions only processed images through `the_content` filter
  - Images in page builders (Elementor, Beaver Builder, etc.) and theme templates were missed
  - Now uses output buffering to process entire HTML output
  - Output buffering enabled when `serve_webp`, `use_picture_tags`, or `convert_bg_images` is active
  - New function: `ccm_tools_webp_process_img_tags()` for src replacement in full HTML
  - Modified `ccm_tools_webp_process_output_buffer()` to handle all conversion types
  - Fixes images like `<img src="...jpg">` not being converted to WebP or `<picture>` tags

### v7.9.2
- **Font Display: Swap for Self-Hosted Fonts**
  - Fixed font-display issue not working on self-hosted theme fonts
  - Previous version only handled Google Fonts URLs via `<link>` tags
  - Now uses output buffering to inject `font-display: swap` into ALL `@font-face` rules
  - Works with self-hosted fonts in inline `<style>` blocks and theme CSS
  - Detects commented-out `/* font-display: swap; */` and adds the property
  - Skips `@font-face` rules that already have `font-display` set
  - New functions: `ccm_tools_perf_font_display_start_buffer()`, `ccm_tools_perf_font_display_process_buffer()`

### v7.9.1
- **Import/Export Performance Settings**
  - Export current settings to a JSON file for backup
  - Import settings from a previously exported JSON file
  - Settings preview panel shows current configuration as JSON
  - Export includes metadata: plugin version, export date, site URL
  - Import validates file format and plugin compatibility
  - Confirmation dialog before import shows source details
  - Page auto-refreshes after successful import
  - New AJAX handlers: `ccm_tools_export_perf_settings`, `ccm_tools_import_perf_settings`

### v7.9.0
- **New Performance Optimizations for PageSpeed Insights**
  - **Font Display: Swap** - Automatically adds `font-display=swap` to Google Fonts URLs
    - Fixes "Ensure text remains visible during webfont load" warning
    - Estimated savings of 500ms+ on font loading
    - Adds preconnect hint for `fonts.gstatic.com`
  - **Speculation Rules API** - Instant page navigation using modern browser prerendering
    - Prerenders same-origin links in the background
    - Pages load instantly when clicked
    - Configurable eagerness: Conservative, Moderate (recommended), Eager
    - Automatically excludes cart, checkout, admin, and external links
    - Supported in Chrome 109+, Edge 109+, gracefully ignored by other browsers
  - **Critical CSS Inlining** - Inline above-the-fold CSS directly in HTML head
    - Eliminates render-blocking CSS for initial content
    - Supports pasting critical CSS generated by external tools
  - **Disable Block Library CSS** - Remove Gutenberg block styles (~36KB savings)
    - Only for sites not using Gutenberg blocks on frontend
  - **Disable jQuery Migrate** - Remove legacy jQuery compatibility script (~10KB)
    - May break older plugins using deprecated jQuery functions
  - **Disable WooCommerce Cart Fragments** - On non-cart/checkout pages
    - Significantly reduces AJAX overhead on WooCommerce sites
  - **Reduce Heartbeat Frequency** - Configurable interval (15-120 seconds)
    - Default WordPress heartbeat causes unnecessary server load
  - **Head Cleanup Options:**
    - Disable XML-RPC (removes X-Pingback header)
    - Remove RSD & WLW Manifest links
    - Remove Shortlink from head and headers
    - Remove REST API discovery link
    - Disable oEmbed discovery links and JavaScript
  - New settings added to Performance Optimizer page
  - All new features have detailed descriptions and safety warnings
  - Settings persist across updates

### v7.8.6
- **WooCommerce Redis Optimization**
  - Added WooCommerce-specific optimization section (appears when WooCommerce is active)
  - New "Cache Cart Fragments" option for faster AJAX cart updates
  - New "Persistent Cart in Redis" option for faster checkout
  - New "Session Data Caching" option (enabled by default)
  - Configurable Product Cache TTL and Session Cache TTL
  - Automatic detection of WooCommerce cache groups
  - Added `ccm_tools_redis_woocommerce_init()` for WooCommerce-specific hooks
  - WooCommerce section has distinctive purple styling to match WooCommerce branding
  - Information panel explains how Redis improves WooCommerce performance

### v7.8.5
- **Improved Redis Cache Statistics**
  - Fixed memory calculation using `MEMORY USAGE` command (Redis 4.0+) with `STRLEN` fallback
  - Restored 4-stat layout with new site-specific metrics
  - New "Cache Groups" stat shows number of distinct WordPress cache groups
  - New "Avg. TTL" stat shows average time-to-live for cached keys
  - Added `ccm_tools_redis_format_duration()` helper function
  - Improved key sampling to calculate all stats in single pass
  - Stats refresh now updates all 4 values

### v7.8.4
- **Site-Specific Redis Cache Statistics**
  - Removed server-wide statistics (hits, misses, hit ratio) for security and accuracy
  - Statistics now only show data for the current site's cache keys
  - New "Cached Keys" stat shows count of keys matching the site's key prefix
  - New "Estimated Memory" stat shows approximate memory usage for site keys
  - Statistics card header updated to "Site Cache Statistics"
  - Added note showing the key prefix being used for filtering
  - Updated `ccm_tools_redis_get_stats()` to calculate site-specific memory using DEBUG OBJECT
  - Added `ccm_tools_redis_format_bytes()` helper function
  - Simplified `refreshRedisStats()` JavaScript function
  - Added `.ccm-stats-grid-2` CSS class for 2-column layout

### v7.8.3
- **Redis Cache Statistics Auto-Refresh**
  - Cache statistics now automatically update after flushing the cache
  - Added `refreshRedisStats()` JavaScript function to fetch and update stats via AJAX
  - Added IDs to stat elements: `redis-stat-hits`, `redis-stat-misses`, `redis-stat-ratio`, `redis-stat-keys`
  - Hit ratio color class dynamically updates based on new value

### v7.8.2
- **Improved Redis Configuration UI**
  - Redesigned form layout using CSS Grid for cleaner, more organized appearance
  - Connection Settings now displays fields in a logical 3-column grid layout
  - Host/Port fields properly sized (2:1 ratio) for better visual hierarchy
  - Database Index field moved next to Connection Type for logical grouping
  - Cache Settings use 2-column layout with Key Prefix and Max TTL side-by-side
  - Selective Flush option redesigned as a checkbox card with description
  - Advanced Settings (timeouts) displayed in 2-column grid with "sec" suffix
  - Added `.ccm-form-grid`, `.ccm-form-field`, `.ccm-field-hint` CSS classes
  - Added `.ccm-input-with-suffix` for inputs with unit labels
  - Added `.ccm-checkbox-label` styling for enhanced checkbox appearance
  - Responsive breakpoints at 900px and 600px for mobile compatibility
  - Form actions section now has top border for visual separation

### v7.8.1
- **Security Improvements for Redis Object Cache**
  - Redis menu and navigation items now only display when PHP Redis extension is installed
  - Added Redis extension availability checks to all AJAX handlers
  - Improved wp-config.php path validation using `realpath()` to prevent path traversal
  - Added secure backup filename generation with `wp_generate_password()`
  - Enhanced input validation for Redis settings (host, port, path, scheme, database, timeout, key_salt)
  - Host validation now checks for valid IP addresses and hostnames
  - Port validation ensures range 1-65535
  - Unix socket path validation with regex pattern matching
  - Scheme whitelist validation (tcp, unix, tls only)
  - Database index validation (0-15 range)
  - Timeout validation (0-30 seconds)
  - Key salt validation (alphanumeric with underscores/hyphens)
  - Backup path in AJAX response now only returns filename (not full path)
  - Added `wp_unslash()` for proper password handling
  - Added `esc_html()` escaping in AJAX JSON responses

### v7.8.0
- **Redis Object Cache - Custom Implementation**
  - New dedicated Redis admin page (CCM Tools → Redis)
  - Custom WordPress object cache drop-in replacing third-party plugins
  - Full WordPress Object Cache API implementation (`wp_cache_add`, `wp_cache_get`, `wp_cache_set`, `wp_cache_delete`, `wp_cache_flush`, etc.)
  - **Connection Management:**
    - Support for tcp, tls, and unix socket connections
    - Configurable host, port, password, and database number
    - Connection timeout settings
    - Real-time connection testing
  - **Settings Storage:**
    - Database-stored settings with wp-config.php override support
    - One-click "Add to wp-config.php" for permanent configuration
    - Automatic detection of existing Redis constants
  - **Drop-in Management:**
    - One-click install/uninstall of object-cache.php drop-in
    - Status indicator showing if drop-in is installed and active
    - Automatic backup of existing drop-in files
  - **Cache Operations:**
    - Selective flush (current site only in multisite)
    - Full Redis flush (clears entire database)
    - Cache statistics display (memory, keys, hit ratio)
  - **Multisite Support:**
    - Separate cache prefixes per blog
    - Global cache groups for network-wide data
    - Compatible with WordPress multisite installations
  - **Reliability:**
    - Automatic fallback to in-memory cache if Redis unavailable
    - Graceful error handling for connection failures
    - Non-persistent local cache layer for performance
  - Dashboard simplified to show Redis status with link to configure page
  - New files: `inc/redis-object-cache.php`, `assets/object-cache.php`

### v7.7.0
- **Reorganized Menu Order**
  - New menu order: System Info, Database, .htaccess, WebP, Performance, WooCommerce, Error Log
  - WooCommerce menu item now only shows if WooCommerce plugin is installed and active
  - Applies to both the header navigation tabs and the WordPress admin sidebar menu
  - Consistent menu across all CCM Tools pages

### v7.6.9
- **Fixed Picture Tag Layout Breaking (Take 3)**
  - Added inline `style="width:100%;height:100%"` to the `<img>` element inside `<picture>`
  - When `<img>` is wrapped in `<picture>`, CSS selectors like `.ratio > *` no longer target it
  - The img was not inheriting the width/height it needs to fill its container
  - Now both the `<picture>` AND the `<img>` have proper sizing
  - Merges with any existing inline styles on the img

### v7.6.8
- **Fixed Picture Tag Layout Breaking (Take 2)**
  - Changed from `display:contents` to `display:block;width:100%;height:100%`
  - `display:contents` was causing issues with Bootstrap's `.ratio` class where `<source>` elements were also getting absolute positioning
  - New approach makes the `<picture>` element a proper block that fills its container
  - Works with Bootstrap ratio containers, flexbox, grid, and absolute positioning
  - The inner `<img>` retains its `object-fit` and `object-position` classes

### v7.6.7
- **Fixed Picture Tag Layout Breaking**
  - Added `display: contents` CSS to `<picture>` elements to prevent layout issues
  - When images are wrapped in `<picture>` tags, CSS rules targeting `<img>` (like flex/grid positioning, object-fit) were breaking because `<picture>` is `display: inline` by default
  - `display: contents` makes the picture element invisible to layout - children are laid out as if picture doesn't exist
  - Fixes issues with themes/page builders that rely on img being direct flex/grid children

### v7.6.1
- **Fixed Background Image Conversion for Theme Templates**
  - Switched from content filters to output buffering to catch ALL HTML output
  - Now catches background images in theme templates, WooCommerce category thumbnails, etc.
  - Background images rendered outside `the_content` filter are now converted
  - Added `ccm_tools_webp_start_output_buffer()` and `ccm_tools_webp_process_output_buffer()` functions
  - Improved URL pattern matching for background-image URLs without quotes
  - Also checks for `/wp-content/uploads/` path pattern (not just full baseurl)

### v7.6.0
- **WebP Background Image Conversion**
  - New option to convert CSS background-image URLs to WebP
  - Handles inline style attributes and `<style>` blocks
  - Works with page builders and custom CSS
  - Only converts images from the uploads folder
  - New `ccm_tools_webp_filter_bg_images()` filter function
  - New `ccm_tools_webp_convert_bg_urls()` helper function
  - Adds dynamic CSS overrides for featured images used as backgrounds

### v7.5.9
- **Consistent Navigation Menu**
  - Added Performance tab to all page navigation menus
  - Created centralized `ccm_tools_render_header_nav()` helper function
  - All pages now display consistent navigation: System Info, Database, .htaccess, WooCommerce, Error Log, WebP (if available), Performance

### v7.5.8
- **Fixed srcset being stripped when WebP serving enabled without picture tags**
  - Removed `wp_get_attachment_image_src` filter that was changing src to WebP BEFORE WordPress calculated srcset
  - WordPress srcset calculation compares src to attachment metadata - WebP src didn't match, causing empty srcset
  - Added new `ccm_tools_webp_filter_content_src()` function that converts img src URLs AFTER WordPress generates srcset
  - Filter runs at priority 1000 on `the_content`, `widget_text_content`, and `wp_get_attachment_image` filters
  - Only activates when picture tags disabled but serve_webp enabled

### v7.5.7
- **Fixed blurry full-width images when using picture tags**
  - Smart detection of full-width CSS classes (full-width, wp-block-cover, hero, banner, etc.)
  - Automatically overrides restrictive sizes attribute with responsive `100vw` for full-width images
  - Preserves original sizes for non-full-width images

### v7.5.6
- **Fixed responsive images not working with picture tags**
  - Now properly extracts and preserves srcset and sizes attributes when converting to picture tags
  - Source elements include sizes attribute for proper responsive image selection

### v7.5.5
- **Fixed picture tag double-wrapping on hero images**
  - Removed `post_thumbnail_html` and `wp_get_attachment_image` hooks that caused double conversion
  - Picture tag conversion now only runs via `the_content` and `widget_text_content` filters

### v7.3.0
- **WebP Image Converter - Stable Release**
  - Automatic WebP conversion on image upload
  - Serve WebP to supported browsers with original fallback
  - On-demand/lazy conversion (converts images as pages are viewed)
  - `<picture>` tag conversion for better browser compatibility
  - Support for GD, ImageMagick, and libvips extensions
  - Configurable compression quality (1-100) with presets
  - Bulk conversion for existing media library images
  - Test conversion feature to verify settings
  - Conversion statistics dashboard
  - WooCommerce product image support

### v7.4.0
- **Performance Optimizer Tool (Experimental)**
  - New tool to eliminate render-blocking resources and improve Lighthouse scores
  - **JavaScript Optimizations:**
    - Defer JavaScript: Add defer attribute to non-critical scripts
    - Delay JavaScript: Delay script execution until user interaction (scroll, click, touch)
    - Configurable exclude lists for both features
    - Fallback timeout option for delayed scripts
  - **CSS Optimizations:**
    - Async CSS Loading: Convert stylesheets to non-blocking using print media trick
    - Critical CSS: Inline above-the-fold CSS to prevent FOUC
    - Configurable exclude list
  - **Resource Hints:**
    - Preconnect: Early connections to important third-party origins
    - DNS Prefetch: Advance DNS lookups for external domains
  - **Additional Optimizations:**
    - Remove query strings from static resources
    - Disable WordPress emoji scripts (~10KB savings)
    - Disable dashicons for logged-out users (~35KB savings)
    - Lazy load iframes with native loading="lazy"
    - YouTube Lite Embeds: Replace iframes with lightweight facade
  - Per-feature toggle controls with detailed explanations
  - Clear experimental warnings and testing guidance
  - Links to external testing tools (PageSpeed, GTmetrix, WebPageTest)
- Menu item shows flask emoji (⚗️) to indicate experimental status

### v7.2.19
- Added on-demand/lazy WebP conversion
  - Images without WebP versions are automatically converted when displayed
  - No need for bulk conversion - images convert as pages are viewed
  - Failed conversions are cached for 1 hour to avoid repeated attempts
  - Conversion metadata stored for tracking
- New toggle: "Convert On-Demand" in WebP settings

### v7.2.18
- Fixed `<picture>` tag conversion not working on WooCommerce product images
  - Added hooks for `woocommerce_product_get_image`, `woocommerce_single_product_image_thumbnail_html`
  - Added hooks for `post_thumbnail_html` and `wp_get_attachment_image`
- Fixed `<picture>` tags not working when source image is already WebP
  - Now detects if source is `.webp` and finds original JPG/PNG for fallback
  - Works both ways: WebP→original fallback and original→WebP upgrade
- Improved picture tag detection to skip already-wrapped images

### v7.2.17
- Added `<picture>` tag conversion option to WebP Converter
  - Converts `<img>` tags to `<picture>` elements with WebP sources
  - Provides automatic fallback for browsers that don't support WebP
  - Applies to post content and widget text
  - Optional toggle in settings (disabled by default)

### v7.2.16
- Added WebP Image Converter tool
  - Automatic WebP conversion on image upload
  - Serve WebP to supported browsers with original fallback
  - Configurable compression quality (1-100) with presets
  - Support for GD, ImageMagick, and libvips extensions
  - Bulk conversion for existing media library images
  - Test conversion feature to verify settings
  - Conversion statistics dashboard
- Menu only appears if a compatible PHP image extension with WebP support is available

### v7.2.15
- Added filtered indicator to AJAX response for debugging
- Improved error handling in get_error_log AJAX handler
- Code cleanup in filter logic

### v7.2.14
- Fixed Show Errors Only filter to properly exclude all non-error entries
- Filter now correctly excludes WordPress notices (_doing_it_wrong), warnings, etc.
- Only PHP Fatal/Parse/Catchable errors and their stack traces are shown

### v7.2.13
- Fixed Show Errors Only filter in Error Log Viewer
- `ccm_tools_ajax_get_error_log()` now properly reads and uses `errors_only` parameter
- When enabled, filters log to show only Fatal/Parse errors and stack traces

### v7.0.3 (Security Release)
- **CRITICAL:** Removed hardcoded GitHub API token from source code
- **HIGH:** Fixed SQL injection vulnerability in table name handling
- Added `ccm_tools_validate_table_name()` for whitelist validation
- Token now loaded from `CCM_GITHUB_TOKEN` constant in wp-config.php

### v7.0.2
- Deferred TTFB loading for faster dashboard render
- TTFB auto-measures via AJAX after page load

### v7.0.1
- Fixed TTFB AJAX action name mismatch
- Fixed memory limit parameter name
- Fixed auto-refresh initialization
- Fixed Highlight Errors toggle
- Added toast notification system (replaced alerts)

### v7.0.0
- Complete UI rewrite with pure CSS/vanilla JS
- Removed jQuery, Bootstrap, FontAwesome dependencies
- Modern CSS custom properties theming
- Improved accessibility and performance
