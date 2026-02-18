<?php
/**
 * Core Helper Functions
 * 
 * Utility functions used throughout the CCM API Hub.
 * 
 * @package CCM_API_Hub
 * @since 1.0.0
 */

/**
 * HTML entity escape helper (XSS prevention)
 */
function h(string $string): string
{
    return htmlspecialchars($string, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Generate a cryptographically secure random token
 */
function generateToken(int $length = 32): string
{
    return bin2hex(random_bytes($length));
}

/**
 * Generate a site API key with prefix
 * Format: ccm_XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX (40 chars total)
 */
function generateSiteApiKey(): array
{
    $random = bin2hex(random_bytes(24)); // 48 hex chars
    $key = 'ccm_' . $random;
    $prefix = substr($key, 0, 12);
    $hash = password_hash($key, PASSWORD_ARGON2ID);

    return [
        'key'    => $key,       // Show to admin once, never store in plain text
        'prefix' => $prefix,    // For display/identification
        'hash'   => $hash,      // Store in database
    ];
}

/**
 * Verify a site API key against its hash
 */
function verifySiteApiKey(string $key, string $hash): bool
{
    return password_verify($key, $hash);
}

/**
 * Get client IP address (Cloudflare-aware)
 */
function getClientIP(): string
{
    // Cloudflare
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        return $_SERVER['HTTP_CF_CONNECTING_IP'];
    }
    // Standard proxy
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Send JSON response and exit
 */
function jsonResponse(array $data, int $statusCode = 200): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Send JSON error response and exit
 */
function jsonError(string $message, int $statusCode = 400, array $extra = []): never
{
    jsonResponse(array_merge([
        'success' => false,
        'error'   => $message,
    ], $extra), $statusCode);
}

/**
 * Send JSON success response and exit
 */
function jsonSuccess(array $data = [], string $message = 'OK'): never
{
    jsonResponse(array_merge([
        'success' => true,
        'message' => $message,
    ], $data));
}

/**
 * Log an activity to the activity_log table
 */
function logActivity(string $action, ?string $targetType = null, ?string $targetId = null, ?array $details = null): void
{
    try {
        dbInsert('activity_log', [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'action'      => $action,
            'target_type' => $targetType,
            'target_id'   => $targetId,
            'details'     => $details ? json_encode($details) : null,
            'ip_address'  => getClientIP(),
        ]);
    } catch (Exception $e) {
        error_log("CCM Hub Activity Log Error: " . $e->getMessage());
    }
}

/**
 * Log a message to file.
 * Safe to call before constants are fully defined.
 */
function appLog(string $message, string $level = 'info'): void
{
    $levels = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3, 'critical' => 4];
    $configLevel = $levels[defined('LOG_LEVEL') ? LOG_LEVEL : 'info'] ?? 1;
    $messageLevel = $levels[$level] ?? 1;

    if ($messageLevel < $configLevel) {
        return;
    }

    $logDir = (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__)) . '/logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }

    $timestamp = date('Y-m-d H:i:s');
    $logLine = "[{$timestamp}] [{$level}] {$message}" . PHP_EOL;
    file_put_contents($logDir . '/app.log', $logLine, FILE_APPEND | LOCK_EX);
}

/**
 * Format bytes to human-readable size
 */
function formatBytes(int $bytes, int $precision = 2): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    return round($bytes / (1024 ** $pow), $precision) . ' ' . $units[$pow];
}

/**
 * Format a number as currency (USD)
 */
function formatCost(float $amount): string
{
    return '$' . number_format($amount, 4);
}

/**
 * Format a number with thousands separator
 */
function formatNumber(int|float $number): string
{
    return number_format($number, 0, '.', ',');
}

/**
 * Time ago helper (e.g., "2 hours ago")
 */
function timeAgo(string $datetime): string
{
    $now = new DateTime();
    $then = new DateTime($datetime);
    $diff = $now->diff($then);

    if ($diff->y > 0) return $diff->y . ' year' . ($diff->y > 1 ? 's' : '') . ' ago';
    if ($diff->m > 0) return $diff->m . ' month' . ($diff->m > 1 ? 's' : '') . ' ago';
    if ($diff->d > 0) return $diff->d . ' day' . ($diff->d > 1 ? 's' : '') . ' ago';
    if ($diff->h > 0) return $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
    if ($diff->i > 0) return $diff->i . ' minute' . ($diff->i > 1 ? 's' : '') . ' ago';
    return 'just now';
}

/**
 * Validate URL format
 */
function isValidUrl(string $url): bool
{
    return (bool)filter_var($url, FILTER_VALIDATE_URL);
}

/**
 * Normalize a site URL (remove trailing slash, lowercase host)
 */
function normalizeSiteUrl(string $url): string
{
    $parsed = parse_url($url);
    if (!$parsed || empty($parsed['host'])) {
        return $url;
    }

    $scheme = strtolower($parsed['scheme'] ?? 'https');
    $host = strtolower($parsed['host']);
    $path = rtrim($parsed['path'] ?? '', '/');

    return "{$scheme}://{$host}{$path}";
}

/**
 * Check if a URL matches a licensed site (with wildcard support)
 */
function urlMatchesSite(string $requestUrl, string $siteUrl): bool
{
    $requestUrl = normalizeSiteUrl($requestUrl);
    $siteUrl = normalizeSiteUrl($siteUrl);

    // Exact match
    if ($requestUrl === $siteUrl) {
        return true;
    }

    // Check if request URL starts with site URL
    return str_starts_with($requestUrl, $siteUrl . '/') || $requestUrl === $siteUrl;
}

// getSetting() and saveSetting() have been replaced by:
//   Settings::get($key, $default)
//   Settings::save($key, $value, $encrypt, $category)
// See includes/settings.php

/**
 * CSRF token generation
 */
function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = generateToken();
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF token
 */
function verifyCsrf(): bool
{
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

/**
 * Require valid CSRF token or die
 */
function requireCsrf(): void
{
    if (!verifyCsrf()) {
        if (defined('API_REQUEST')) {
            jsonError('Invalid CSRF token', 403);
        }
        http_response_code(403);
        die('Invalid CSRF token');
    }
}

/**
 * Redirect helper
 */
function redirect(string $url): never
{
    header("Location: {$url}");
    exit;
}

/**
 * Get the current page name from URL
 */
function currentPage(): string
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    return basename($path, '.php');
}

/**
 * Check if current page matches
 */
function isPage(string $page): bool
{
    return currentPage() === $page;
}
