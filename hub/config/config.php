<?php
/**
 * Main Configuration Bootstrap
 * 
 * Loads .env for database + encryption key only.
 * All other settings are stored encrypted in the app_settings table
 * and loaded at runtime via the Settings class.
 * 
 * @package CCM_API_Hub
 * @since 1.0.0
 */

// Prevent direct access
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'config.php') {
    http_response_code(403);
    exit('Direct access forbidden');
}

// Load environment parser
require_once __DIR__ . '/env.php';

// Load .env file (database + encryption key only)
Env::load(dirname(__DIR__) . '/.env');

// ─── Bootstrap constants (.env only) ────────────────────────────
define('APP_ROOT', dirname(__DIR__));
define('APP_VERSION', '1.0.0');
define('APP_DEBUG', Env::bool('APP_DEBUG', false));

// Database
define('DB_HOST', Env::string('DB_HOST', 'localhost'));
define('DB_PORT', Env::string('DB_PORT', '3306'));
define('DB_NAME', Env::string('DB_NAME', 'ccm_api_hub'));
define('DB_USER', Env::string('DB_USER'));
define('DB_PASS', Env::string('DB_PASS'));
define('DB_CHARSET', Env::string('DB_CHARSET', 'utf8mb4'));

// Encryption key (must be in .env — needed to decrypt DB values)
define('ENCRYPTION_KEY', Env::string('ENCRYPTION_KEY'));

// ─── Error reporting ────────────────────────────────────────────
if (APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}

// Timezone
date_default_timezone_set('Australia/Sydney');

// ─── Load core dependencies ─────────────────────────────────────
require_once __DIR__ . '/database.php';
require_once APP_ROOT . '/includes/encryption.php';
require_once APP_ROOT . '/includes/functions.php';
require_once APP_ROOT . '/includes/settings.php';
require_once APP_ROOT . '/includes/auth.php';
require_once APP_ROOT . '/includes/api-auth.php';

// ─── Load all settings from database ────────────────────────────
// Settings class caches everything in memory for the request lifetime.
// Individual values accessed via Settings::get('key', 'default').
try {
    Settings::load();
} catch (Exception $e) {
    // Database not yet set up — allow setup page to handle this
    if (!defined('SETUP_MODE')) {
        appLog('Settings load failed: ' . $e->getMessage(), 'critical');
    }
}

// ─── Convenience constants from DB settings ─────────────────────
// These are defined after Settings::load() so they pull from the DB.
define('APP_NAME', Settings::get('app_name', 'CCM API Hub'));
define('APP_URL', Settings::get('app_url', 'https://api.tools.clickclick.media'));
define('APP_SECRET_KEY', Settings::get('app_secret_key', ''));

// Google OAuth
define('GOOGLE_CLIENT_ID', Settings::get('google_client_id', ''));
define('GOOGLE_CLIENT_SECRET', Settings::get('google_client_secret', ''));
define('GOOGLE_REDIRECT_URI', Settings::get('google_redirect_uri', ''));
define('GOOGLE_ALLOWED_DOMAIN', Settings::get('google_allowed_domain', 'clickclickmedia.com.au'));
define('WIZARD_EMAILS', Settings::get('wizard_emails', ''));

// Claude API
define('CLAUDE_API_KEY', Settings::get('claude_api_key', ''));
define('CLAUDE_MODEL', Settings::get('claude_model', 'claude-sonnet-4-20250514'));
define('CLAUDE_MAX_TOKENS', (int)Settings::get('claude_max_tokens', '4096'));

// PageSpeed API
define('PAGESPEED_API_KEY', Settings::get('pagespeed_api_key', ''));

// Rate Limiting
define('RATE_LIMIT_PER_MINUTE', (int)Settings::get('rate_limit_per_minute', '30'));
define('RATE_LIMIT_AI_PER_HOUR', (int)Settings::get('rate_limit_ai_per_hour', '50'));

// Logging
define('LOG_LEVEL', Settings::get('log_level', 'info'));
define('LOG_RETENTION_DAYS', (int)Settings::get('log_retention_days', '90'));

// Session
$sessionSecure = Settings::get('session_secure', '1');
$sessionLifetime = (int)Settings::get('session_lifetime', '7200');

// ─── Session configuration ──────────────────────────────────────
if (!defined('API_REQUEST') && session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', $sessionSecure ? '1' : '0');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.gc_maxlifetime', (string)$sessionLifetime);
    ini_set('session.use_strict_mode', '1');
    session_start();
}

// ─── Security headers (web pages only, not API responses) ───────
if (!defined('API_REQUEST')) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}
