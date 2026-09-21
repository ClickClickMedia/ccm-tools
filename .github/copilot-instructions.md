# CCM Tools — Copilot Instructions

WordPress utility plugin for site administrators. **PHP 7.4+ | WP 6.0+ | Version: 8.0.0**

> **No related services.** The plugin talks only to the Google PageSpeed Insights API (Site Health) and to GitHub (updates). The old `ccm-api-hub` proxy and `ccm-premium` subscription site were retired in v8.0.0.
> **File structure:** [.file-structure.md](../.file-structure.md) *(local-only, git-ignored — update this file whenever files are added or deleted)*

## Features

| Feature | Description |
|---------|-------------|
| System Info | PHP/MySQL/WP versions, disk, memory limits, TTFB, Redis status |
| Database Tools | InnoDB/utf8mb4 converter, table optimizer (per-table AJAX prevents timeouts) |
| .htaccess | Gzip/Brotli, browser caching, security headers, HTTPS redirect, HSTS |
| Error Log | Tail/filter PHP error log, Show Errors Only toggle, clear/download |
| Debug Mode | Toggle WP_DEBUG / WP_DEBUG_LOG / WP_DEBUG_DISPLAY in wp-config.php |
| WebP Converter | GD/ImageMagick bulk convert, on-demand, serves WebP by rewriting `<img>`/`<source>` src+srcset, background images |
| Performance Optimizer | 30+ toggles: defer/delay JS+CSS, lazy load, image dims, fonts, HTML minify |
| WooCommerce Tools | Admin payment toggle, cart/session tools |
| Redis Object Cache | Custom drop-in, TCP/TLS/Unix, pipeline bulk ops, WooCommerce TTL caching |
| Cloudflare | CF detection, API token connection, cache purge, dev mode toggle, zone status dashboard |
| Site Health | Google PageSpeed Insights scores, Core Web Vitals, CrUX field data, findings linked to the settings that address them. Reports only, never applies anything. |

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

**Update version in:** `ccm.php` (header `Version:` + `CCM_HELPER_VERSION` constant) · `js/main.js` · `css/style.css` · `assets/object-cache.php` (`@version`, only when the drop-in itself changes)

## After Every Change

```powershell
# 1. Commit & push
git add -A; git commit -m "Description (vX.Y.Z)"; git push

# 2. Build zips (from repo root)
# The zip MUST contain a top-level ccm-tools/ folder. A flat zip makes WordPress
# install to ccm-tools-X.Y.Z/ alongside the real one, and the plugin deactivates
# itself (see CHANGELOG v7.42.1). Use the build script:
bash build-zip.sh X.Y.Z

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
| Site Health | `sh_save_key`, `sh_run_test`, `sh_clear_history` (registered in `inc/site-health.php`, not in ajax-handlers.php) |
| Cloudflare | `cf_connect`, `cf_disconnect`, `cf_get_status`, `cf_purge_all`, `cf_purge_urls`, `cf_dev_mode` |
