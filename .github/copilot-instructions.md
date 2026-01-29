# CCM Tools - GitHub Copilot Instructions

## Project Overview

**CCM Tools** is a WordPress utility plugin designed for site administrators to monitor and optimize their WordPress installations. It provides comprehensive system information, database management tools, and .htaccess optimization features.

- **Current Version:** 7.9.3
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
- **Increment after each edit:**
  - Patch (z): Bug fixes, small changes
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
