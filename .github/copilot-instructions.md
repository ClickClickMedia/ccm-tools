# CCM Tools - GitHub Copilot Instructions

## Project Overview

**CCM Tools** — WordPress utility plugin for site administrators (system info, DB tools, .htaccess, error log, Redis, WebP, performance optimization, AI performance hub).

- **Version:** 7.30.0 | **WP:** 6.0+ | **PHP:** 7.4+

> See [CHANGELOG.md](../CHANGELOG.md) for full version history.

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

1. **System Info** — PHP/MySQL/WP versions, disk usage, memory limits, TTFB, Redis status
2. **Database Tools** — InnoDB/utf8mb4 converter, table optimizer; per-table AJAX progress prevents timeouts
3. **.htaccess** — Gzip/Brotli, browser caching, security headers, HTTPS redirect, HSTS
4. **Error Log** — Tail/filter PHP error log, Show Errors Only toggle, clear/download
5. **Debug Mode** — Toggle WP_DEBUG, WP_DEBUG_LOG, WP_DEBUG_DISPLAY in wp-config.php
6. **WebP Converter** — GD/ImageMagick bulk convert; picture tags; background images; WooCommerce support
7. **Performance Optimizer** — 30+ toggles: defer/delay/async JS+CSS, lazy load, image dims/srcset, fonts, head cleanup, HTML minify, resource hints, passive listeners, DOM size warning, INP optimizations
8. **WooCommerce Tools** — Admin payment toggle, cart/session tools (when WC active)
9. **Redis Object Cache** — Custom drop-in; TCP/TLS/Unix; serializer/compression; ACL; pipeline bulk ops; SCAN-safe stats; wp-config constants; WooCommerce TTL caching
10. **AI Performance Hub** *(Premium)* — One-click PageSpeed optimization with iterative AI recommendations, per-setting testing, visual regression detection, console error checks, rollback, cross-site learning, AI Troubleshooter chat
11. **Premium Subscription** — Hub API key validation, feature gating; `ccm_tools_is_premium()`; `define('CCM_TOOLS_PREMIUM', true)` for dev override

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

## UI Components

```javascript
showNotification('Message text', 'success'); // success, error, warning, info
```

```html
<div class="ccm-spinner"></div>
<div class="ccm-spinner ccm-spinner-small"></div>
```

```css
.ccm-success { color: var(--ccm-success); }
.ccm-warning { color: var(--ccm-warning); }
.ccm-error  { color: var(--ccm-error); }
.ccm-info   { color: var(--ccm-info); }
```

## Release Process

After completing changes:
1. **Update version numbers** in `ccm.php` (header + constant), `js/main.js`, `css/style.css`
2. **Update `CHANGELOG.md`** with new version entry, and update the version in this file's Project Overview
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

## Change Log

> Full version history is in [CHANGELOG.md](../CHANGELOG.md).