# CCM Tools — Copilot Instructions

WordPress utility plugin for site administrators. **PHP 7.4+ | WP 6.0+ | Version: 7.30.0**

> **Related projects in this workspace:** `ccm-api-hub` (AI/PageSpeed proxy API) · `ccm-premium` (subscription management)
> **File structure:** [.file-structure.md](../.file-structure.md) *(local-only, git-ignored — update this file whenever files are added or deleted)*

## Features

| Feature | Description |
|---------|-------------|
| System Info | PHP/MySQL/WP versions, disk, memory limits, TTFB, Redis status |
| Database Tools | InnoDB/utf8mb4 converter, table optimizer (per-table AJAX prevents timeouts) |
| .htaccess | Gzip/Brotli, browser caching, security headers, HTTPS redirect, HSTS |
| Error Log | Tail/filter PHP error log, Show Errors Only toggle, clear/download |
| Debug Mode | Toggle WP_DEBUG / WP_DEBUG_LOG / WP_DEBUG_DISPLAY in wp-config.php |
| WebP Converter | GD/ImageMagick bulk convert, `<picture>` tags, background images, WooCommerce |
| Performance Optimizer | 30+ toggles: defer/delay JS+CSS, lazy load, image dims, fonts, HTML minify |
| WooCommerce Tools | Admin payment toggle, cart/session tools |
| Redis Object Cache | Custom drop-in, TCP/TLS/Unix, pipeline bulk ops, WooCommerce TTL caching |
| AI Performance Hub *(Premium)* | PageSpeed AI loop, visual regression, console error check, AI chat, rollback |
| Premium | Feature gating via `ccm_tools_is_premium()`; `define('CCM_TOOLS_PREMIUM', true)` for dev override |

## Stack

- **PHP** 7.4+ WordPress coding standards · **JS** Vanilla ES6+, no jQuery · **CSS** Custom properties, no frameworks
- AJAX via `admin-ajax.php` + Fetch API

## Coding Standards

```php
check_ajax_referer('ccm-tools-nonce', 'nonce');    // Always verify nonces
$val = sanitize_text_field($_POST['param']);         // Sanitize inputs
echo esc_html($variable);                           // Escape output
// Naming: ccm_tools_{action}()  ·  AJAX handlers: ccm_tools_ajax_{action}()
```

```javascript
// No jQuery — vanilla JS only
const res = await ajax('ccm_tools_action', { key: value });
showNotification('Message', 'success'); // success | error | warning | info
```

## Version Numbers

Format: `x.y.z` — patch (z) goes 0–999. **Increment after every change.**

| Change | Bump |
|--------|------|
| Bug fix / small tweak | z (patch) |
| New feature | y (minor) |
| Breaking change | x (major) |

**Update version in:** `ccm.php` (header `Version:` + `CCM_HELPER_VERSION` constant) · `js/main.js` · `css/style.css`

## After Every Change

```powershell
# 1. Commit & push
git add -A; git commit -m "Description (vX.Y.Z)"; git push

# 2. Build zips (from repo root)
Compress-Archive -Path "ccm.php","index.php","css","inc","js","img","assets" -DestinationPath "archive\ccm-tools-X.Y.Z.zip" -Force
Compress-Archive -Path "ccm.php","index.php","css","inc","js","img","assets" -DestinationPath "ccm-tools.zip" -Force

# 3. GitHub release (required for WordPress auto-updates)
& "C:\Program Files\GitHub CLI\gh.exe" release create vX.Y.Z "archive\ccm-tools-X.Y.Z.zip" "ccm-tools.zip" --title "vX.Y.Z" --notes "## Changes in vX.Y.Z`n`n- Change 1"
```

> Always include both zips. Stable update URL: `https://github.com/ClickClickMedia/ccm-tools/releases/latest/download/ccm-tools.zip`

## AJAX Reference

All handlers in `inc/ajax-handlers.php`. Hook pattern: `add_action('wp_ajax_ccm_tools_{action}', 'ccm_tools_ajax_{action}')`.

| Group | Action suffixes |
|-------|-----------------|
| System | `measure_ttfb`, `update_memory_limit` |
| Error Log | `get_error_log`, `clear_error_log` |
| .htaccess | `add_htaccess`, `remove_htaccess` |
| Database | `convert_single_table`, `optimize_single_table`, `optimize_table_task` |
| Debug | `update_debug_mode` |
| Redis | `redis_enable`, `redis_disable`, `redis_flush`, `redis_test`, `redis_save_settings`, `redis_add_config`, `redis_get_stats`, `configure_redis` |
| WebP | `save_webp_settings`, `get_webp_stats`, `get_unconverted_images`, `convert_single_image`, `test_webp_conversion` |
| Performance | `save_perf_settings`, `get_perf_settings` |
| AI Hub | `ai_hub_save_settings`, `ai_hub_test_connection`, `ai_hub_run_pagespeed`, `ai_hub_get_results`, `ai_hub_ai_analyze`, `ai_hub_ai_optimize`, `ai_hub_visual_compare`, `ai_hub_console_check`, `ai_hub_get_latest_scores` |
| AI Session | `ai_apply_changes`, `ai_save_run`, `ai_preflight`, `ai_enable_tool`, `ai_chat` |
| Premium | `premium_refresh` |