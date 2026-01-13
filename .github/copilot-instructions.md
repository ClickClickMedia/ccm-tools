# CCM Tools - GitHub Copilot Instructions

## Project Overview

**CCM Tools** is a WordPress utility plugin designed for site administrators to monitor and optimize their WordPress installations. It provides comprehensive system information, database management tools, and .htaccess optimization features.

- **Current Version:** 7.2.13
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
│   ├── system-info.php    # System information gathering (TTFB, disk, etc.)
│   ├── tableconverter.php # Database table conversion (InnoDB/utf8mb4)
│   ├── update.php         # Plugin update checker
│   └── woocommerce-tools.php # WooCommerce-specific utilities
├── img/                   # Image assets
└── assets/               # (Legacy - being phased out)
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
| `ccm_tools_configure_redis` | `ccm_tools_ajax_configure_redis()` | Configure Redis settings |

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
4. **Build release zip**:
   ```powershell
   Compress-Archive -Path "ccm.php", "index.php", "css", "inc", "js", "img", "assets" -DestinationPath "ccm-tools-X.Y.Z.zip" -Force
   ```
5. **Create GitHub release** (required for WordPress auto-updates):
   ```powershell
   & "C:\Program Files\GitHub CLI\gh.exe" release create vX.Y.Z "ccm-tools-X.Y.Z.zip" --title "vX.Y.Z" --notes "## Changes in vX.Y.Z

   - Change 1
   - Change 2"
   ```
6. **Note:** Release zips are in `.gitignore` and should not be committed

## Change Log (Recent)

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
