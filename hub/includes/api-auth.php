<?php
/**
 * API Authentication Middleware
 * 
 * Validates API requests from WordPress plugin instances.
 * Checks API key, site URL, rate limits, and feature access.
 * 
 * @package CCM_API_Hub
 * @since 1.0.0
 */

/**
 * Authenticate an API request from a CCM Tools plugin instance.
 * 
 * Expects headers:
 *   X-CCM-Api-Key: ccm_xxxxx...
 *   X-CCM-Site-Url: https://example.com
 * 
 * Returns the licensed_sites row on success, or sends error response.
 */
function authenticateApiRequest(): array
{
    // Get API key from header
    $apiKey = $_SERVER['HTTP_X_CCM_API_KEY'] ?? '';
    $siteUrl = $_SERVER['HTTP_X_CCM_SITE_URL'] ?? '';

    if (empty($apiKey)) {
        jsonError('Missing API key', 401);
    }

    if (empty($siteUrl)) {
        jsonError('Missing site URL', 401);
    }

    // Extract prefix for lookup
    $prefix = substr($apiKey, 0, 12);

    // Find site by prefix
    $sites = dbFetchAll(
        "SELECT * FROM licensed_sites WHERE api_key_prefix = ? AND is_active = 1",
        [$prefix]
    );

    $authenticatedSite = null;

    foreach ($sites as $site) {
        if (verifySiteApiKey($apiKey, $site['api_key_hash'])) {
            $authenticatedSite = $site;
            break;
        }
    }

    if (!$authenticatedSite) {
        appLog("API auth failed: invalid key with prefix {$prefix} from " . getClientIP(), 'warning');
        jsonError('Invalid API key', 401);
    }

    // Verify site URL matches
    $normalizedRequest = normalizeSiteUrl($siteUrl);
    $normalizedSite = normalizeSiteUrl($authenticatedSite['site_url']);

    if ($normalizedRequest !== $normalizedSite) {
        appLog("API auth failed: URL mismatch. Expected {$normalizedSite}, got {$normalizedRequest}", 'warning');
        jsonError('Site URL mismatch', 403);
    }

    // Check expiry
    if ($authenticatedSite['expires_at'] && strtotime($authenticatedSite['expires_at']) < time()) {
        jsonError('License expired', 403);
    }

    // Update last seen
    dbExecute(
        "UPDATE licensed_sites SET last_seen = NOW() WHERE id = ?",
        [$authenticatedSite['id']]
    );

    return $authenticatedSite;
}

/**
 * Check if a site has access to a specific feature
 */
function checkFeatureAccess(array $site, string $feature): void
{
    switch ($feature) {
        case 'ai':
            if (!$site['ai_enabled']) {
                jsonError('AI features not enabled for this site', 403);
            }
            break;

        case 'pagespeed':
            if (!$site['pagespeed_enabled']) {
                jsonError('PageSpeed features not enabled for this site', 403);
            }
            break;
    }
}

/**
 * Check and enforce rate limiting
 */
function checkRateLimit(int $siteId, string $endpoint, int $maxRequests, int $windowSeconds): void
{
    $identifier = "site_{$siteId}";
    $windowStart = date('Y-m-d H:i:s', time() - $windowSeconds);

    // Clean old entries
    dbExecute("DELETE FROM rate_limits WHERE window_start < ?", [$windowStart]);

    // Count requests in window
    $count = (int)dbFetchValue(
        "SELECT SUM(request_count) FROM rate_limits 
         WHERE identifier = ? AND endpoint = ? AND window_start >= ?",
        [$identifier, $endpoint, $windowStart]
    );

    if ($count >= $maxRequests) {
        jsonError('Rate limit exceeded', 429, [
            'retry_after' => $windowSeconds,
            'limit'       => $maxRequests,
            'window'      => $windowSeconds,
        ]);
    }

    // Record this request
    $currentWindow = date('Y-m-d H:i:00'); // 1-minute windows
    $existing = dbFetchOne(
        "SELECT id, request_count FROM rate_limits 
         WHERE identifier = ? AND endpoint = ? AND window_start = ?",
        [$identifier, $endpoint, $currentWindow]
    );

    if ($existing) {
        dbExecute(
            "UPDATE rate_limits SET request_count = request_count + 1 WHERE id = ?",
            [$existing['id']]
        );
    } else {
        dbInsert('rate_limits', [
            'identifier'    => $identifier,
            'endpoint'      => $endpoint,
            'window_start'  => $currentWindow,
            'request_count' => 1,
        ]);
    }
}

/**
 * Check AI usage against monthly limit
 */
function checkAiUsageLimit(array $site): void
{
    $monthStart = date('Y-m-01 00:00:00');
    $monthlyTokens = (int)dbFetchValue(
        "SELECT COALESCE(SUM(tokens_used), 0) FROM api_usage_log 
         WHERE site_id = ? AND request_type = 'ai' AND created_at >= ?",
        [$site['id'], $monthStart]
    );

    // Limit is in thousands
    $limitTokens = $site['ai_monthly_limit'] * 1000;

    if ($monthlyTokens >= $limitTokens) {
        jsonError('Monthly AI token limit exceeded', 429, [
            'used'  => $monthlyTokens,
            'limit' => $limitTokens,
        ]);
    }
}

/**
 * Check PageSpeed usage against daily limit
 */
function checkPagespeedUsageLimit(array $site): void
{
    $todayStart = date('Y-m-d 00:00:00');
    $dailyCount = (int)dbFetchValue(
        "SELECT COUNT(*) FROM api_usage_log 
         WHERE site_id = ? AND request_type = 'pagespeed' AND created_at >= ?",
        [$site['id'], $todayStart]
    );

    if ($dailyCount >= $site['pagespeed_daily_limit']) {
        jsonError('Daily PageSpeed test limit exceeded', 429, [
            'used'  => $dailyCount,
            'limit' => $site['pagespeed_daily_limit'],
        ]);
    }
}

/**
 * Log an API usage entry
 */
function logApiUsage(int $siteId, string $endpoint, string $requestType, array $extra = []): int
{
    return (int)dbInsert('api_usage_log', array_merge([
        'site_id'      => $siteId,
        'endpoint'     => $endpoint,
        'request_type' => $requestType,
        'request_ip'   => getClientIP(),
    ], $extra));
}
