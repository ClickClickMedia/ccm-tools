# CCM API Hub — Copilot Instructions

## Project Overview

**CCM API Hub** is a standalone PHP application that centralises API management for the CCM Tools WordPress plugin. It acts as a secure proxy for the Claude AI API and Google PageSpeed Insights API, providing URL-based access control, usage tracking, cost monitoring, and an AI-powered performance optimization loop.

- **Current Version:** 1.0.0
- **Requires PHP:** 8.0+
- **Requires MySQL:** 8.0+
- **Hosting:** Apache on cPanel/WHM (CloudLinux)
- **URL:** `https://api.tools.clickclick.media` (52.62.57.205, Cloudflare-proxied)
- **Timezone:** Australia/Sydney

## Architecture

### Technology Stack

- **Backend:** Vanilla PHP 8.0+ (no frameworks) with PDO/MySQL
- **Frontend (Admin):** Pure vanilla JavaScript (ES6+) — no jQuery or libraries
- **Styling:** Pure CSS with CSS custom properties — dark theme derived from WebWatch
- **Database:** MySQL 8.4 with InnoDB, utf8mb4_unicode_ci
- **Auth:** Google OAuth 2.0 SSO, restricted to `@clickclickmedia.com.au`
- **Encryption:** AES-256-CBC (at rest), HMAC-SHA256 (transit signatures)
- **API Hashing:** Argon2ID for site API keys
- **Server:** Apache with mod_rewrite, .htaccess routing

### Configuration Architecture

The `.env` file holds **ONLY**:
- Database credentials (DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS)
- ENCRYPTION_KEY (needed to decrypt DB-stored values)
- APP_DEBUG flag

All other configuration (OAuth credentials, API keys, rate limits, feature flags, etc.) is stored **encrypted in the `app_settings` database table** and loaded at runtime by the `Settings` class.

### File Structure

```
hub/
├── config/
│   ├── config.php              # Main bootstrap (loads .env → DB → defines constants)
│   ├── env.php                 # .env file parser with typed getters
│   └── database.php            # PDO singleton, query helpers
├── database/
│   └── schema.sql              # Full schema (9 tables) + default settings seed
├── includes/
│   ├── functions.php           # Core utilities (XSS, tokens, CSRF, logging, etc.)
│   ├── encryption.php          # AES-256-CBC + HMAC transit encryption
│   ├── auth.php                # Google SSO, role-based access control
│   ├── api-auth.php            # API key auth middleware for plugin requests
│   └── settings.php            # Settings class (DB → memory cache)
├── auth/
│   ├── google-login.php        # Initiates OAuth flow
│   └── google-callback.php     # Handles OAuth callback
├── admin/
│   ├── layout-header.php       # Admin page header/nav template
│   ├── layout-footer.php       # Admin page footer/scripts template
│   ├── index.php               # Dashboard (stats, recent activity)
│   ├── sites.php               # Licensed sites CRUD
│   ├── usage.php               # API usage analytics
│   └── settings.php            # Settings management (all categories)
├── api/
│   ├── .htaccess               # Routes /api/v1/* to router
│   └── v1/
│       ├── index.php           # API v1 router
│       ├── health.php          # Connection health check
│       ├── pagespeed-test.php  # Run PageSpeed Insights test
│       ├── pagespeed-results.php # Get cached PSI results
│       ├── ai-analyze.php      # AI analysis of PSI results
│       └── ai-optimize.php     # Full optimization session loop
├── assets/
│   ├── css/style.css           # Dark theme CSS
│   └── js/main.js              # Toast, modal, AJAX, table sort utilities
├── .env.example                # Template for minimal .env
├── .htaccess                   # Root Apache config (routing, security, compression)
├── .gitignore                  # Ignores .env, logs, vendor
├── index.php                   # Root redirect (setup or admin)
├── login.php                   # Login page (Google SSO)
├── logout.php                  # Logout handler
└── setup.php                   # First-run setup wizard (4 steps)
```

## Database Schema

9 tables (all InnoDB, utf8mb4_unicode_ci):

| Table | Purpose |
|-------|---------|
| `users` | Admin panel users (via Google SSO) |
| `user_sessions` | Remember-me session tokens |
| `licensed_sites` | WordPress sites with API access (URL + hashed key) |
| `api_usage_log` | Every API call logged (tokens, cost, response time) |
| `pagespeed_results` | Cached PSI results with scores, metrics, opportunities |
| `ai_sessions` | Optimization session tracking (multi-step workflows) |
| `app_settings` | Key-value settings store (encrypted values supported) |
| `activity_log` | Admin audit trail |
| `rate_limits` | Per-site API rate limiting windows |

## Authentication

### Admin Panel (Google SSO)
- Google OAuth 2.0 with ID token verification
- Restricted to `@clickclickmedia.com.au` email domain
- Wizard emails (e.g. `rik@clickclickmedia.com.au`) auto-promoted to Admin on first login
- Roles: `viewer` (level 1), `manager` (level 2), `admin` (level 3)

### API Requests (Plugin → Hub)
- `X-CCM-Api-Key` header: `ccm_` prefixed key, prefix stored for lookup, full key hashed with Argon2ID
- `X-CCM-Site-Url` header: must match the registered site URL
- Rate limiting, feature flags, and usage quotas enforced per-site
- Transit encryption available via `encryptTransit()` / `decryptTransit()`

## API Endpoints

| Method | Endpoint | Handler | Description |
|--------|----------|---------|-------------|
| POST | `/api/v1/health` | `health.php` | Connection test, returns site features/limits |
| POST | `/api/v1/pagespeed/test` | `pagespeed-test.php` | Run PSI test (cached unless `force: true`) |
| GET/POST | `/api/v1/pagespeed/results` | `pagespeed-results.php` | Retrieve cached results |
| POST | `/api/v1/ai/analyze` | `ai-analyze.php` | Send PSI results to Claude for analysis |
| POST | `/api/v1/ai/optimize` | `ai-optimize.php` | Full optimization session (start/retest/complete) |

## Coding Conventions

### PHP Standards
```php
// No framework — vanilla PHP with prepared statements
$result = dbFetchOne("SELECT * FROM users WHERE id = ?", [$id]);

// Settings from DB (never from .env except DB creds + encryption key)
$model = Settings::get('claude_model', 'claude-sonnet-4-20250514');

// Auth guards at top of page
requireAdmin();      // Admin only
requireManager();    // Manager+
requireLogin();      // Any authenticated user

// CSRF protection for forms
csrfToken();         // Generate/get token
requireCsrf();       // Validate on POST

// Output escaping
h($variable);        // Alias for htmlspecialchars()
```

### JavaScript Standards
```javascript
// Vanilla JS only — no jQuery, no libraries
showToast('Message', 'success');    // Toast notification
openModal('modal-id');              // Open modal by ID
closeModal('modal-id');             // Close modal
apiRequest('/api/v1/health', { method: 'POST' }); // AJAX helper

// Data attributes for common interactions
// data-open-modal="id"    — opens modal on click
// data-close-modal="id"   — closes modal on click
// data-copy="text"        — copies text to clipboard
// data-confirm="message"  — confirmation dialog before action
```

### CSS Standards
```css
/* Dark theme with ClickClickMedia brand green */
:root {
    --brand-primary: #94c83e;
    --bg-primary: #101010;
    --bg-secondary: #0a0a0a;
    --bg-card: #1a1a1a;
    --text-primary: #e0e0e0;
}
```

## Settings Categories

| Category | Keys | Encrypted |
|----------|------|-----------|
| `general` | app_name, app_url, app_secret_key, maintenance_mode, setup_complete | app_secret_key only |
| `session` | session_secure, session_lifetime | No |
| `oauth` | google_client_id, google_client_secret, google_redirect_uri, google_allowed_domain, wizard_emails | ID + secret |
| `ai` | claude_api_key, claude_model, claude_max_tokens, max_optimization_iterations | API key only |
| `pagespeed` | pagespeed_api_key, pagespeed_cache_hours | API key only |
| `limits` | rate_limit_per_minute, rate_limit_ai_per_hour, default_ai_monthly_limit, default_pagespeed_daily_limit | No |
| `logging` | log_level, log_retention_days | No |

## Optimization Session Flow

1. **WordPress plugin** calls `POST /api/v1/ai/optimize` with `action=start`
2. Hub runs PageSpeed test → records initial scores
3. Hub sends results to Claude → gets recommendations mapped to CCM Tools settings
4. Hub returns recommendations to plugin (status: `applying`)
5. **Plugin applies** the recommended settings to WordPress
6. Plugin calls `POST /api/v1/ai/optimize` with `action=retest`
7. Hub runs new PageSpeed test → compares scores → optionally gets more AI suggestions
8. Loop continues up to `max_optimization_iterations` or until performance is satisfactory
9. Plugin calls `action=complete` to close the session

## Version Numbering

- **Format:** `x.y.z` (Major.Minor.Patch)
- Increment after each edit (same rules as CCM Tools plugin)
- **File to update:** `hub/config/config.php` (`APP_VERSION` constant)
- **Also update:** This copilot-instructions.md (version in Project Overview + change log)

## Change Log

### v1.0.0
- Initial release
- Admin dashboard with sites CRUD, usage analytics, settings management
- API proxy endpoints: health, pagespeed/test, pagespeed/results, ai/analyze, ai/optimize
- Google OAuth 2.0 SSO (restricted to @clickclickmedia.com.au)
- AES-256-CBC encryption for DB-stored secrets
- Argon2ID API key hashing with prefix lookup
- HMAC-SHA256 transit encryption between hub and plugin
- Settings class with DB-backed key-value store
- Role-based access control (viewer, manager, admin)
- Rate limiting and usage quotas per licensed site
- 4-step setup wizard
- Dark theme admin UI (WebWatch-derived)
