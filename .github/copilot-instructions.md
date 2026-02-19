# CCM Tools - GitHub Copilot Instructions

## Project Overview

**CCM Tools** is a WordPress utility plugin designed for site administrators to monitor and optimize their WordPress installations. It provides comprehensive system information, database management tools, and .htaccess optimization features.

- **Current Version:** 7.12.6
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
   & "C:\Program Files\GitHub CLI\gh.exe" release create vX.Y.Z "archive\ccm-tools-X.Y.Z.zip" --title "vX.Y.Z" --notes "## Changes in vX.Y.Z

   - Change 1
   - Change 2"
   ```

### Release File Structure
- `ccm-tools.zip` - Master zip in root (always latest version, git ignored)
- `archive/` - Contains all versioned zips (git ignored folder)
  - `ccm-tools-X.Y.Z.zip` - Versioned releases for GitHub

## Change Log (Recent)

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
- **Model**: Claude Sonnet 4 (`claude-sonnet-4-20250514`) as default
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
