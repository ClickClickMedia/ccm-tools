<?php
/**
 * PageSpeed Test Endpoint
 * 
 * Runs a Google PageSpeed Insights test for the authenticated site.
 * Results are cached for the configured duration.
 * 
 * POST /api/v1/pagespeed/test
 * Headers: X-CCM-Api-Key, X-CCM-Site-Url
 * Body (JSON):
 *   url       string  URL to test (must be within the site domain)
 *   strategy  string  "mobile" or "desktop" (default: "mobile")
 *   force     bool    Skip cache and run fresh test (default: false)
 * 
 * @package CCM_API_Hub
 * @since 1.0.0
 */

$site = authenticateApiRequest();
checkFeatureAccess($site, 'pagespeed');
checkRateLimit($site['id'], 'pagespeed/test', Settings::int('rate_limit_per_minute', 30), 60);
checkPagespeedUsageLimit($site);

// Parse request body
$input = json_decode(file_get_contents('php://input'), true) ?: [];

$testUrl = $input['url'] ?? $site['site_url'];
$strategy = in_array($input['strategy'] ?? '', ['mobile', 'desktop']) ? $input['strategy'] : 'mobile';
$force = (bool)($input['force'] ?? false);

// Validate URL belongs to the site
if (!urlMatchesSite($testUrl, $site['site_url'])) {
    jsonError('URL does not belong to the authenticated site', 403);
}

$startTime = microtime(true);
$logId = logApiUsage($site['id'], 'pagespeed/test', 'pagespeed');

// Check cache first (unless forced)
if (!$force) {
    $cacheHours = Settings::int('pagespeed_cache_hours', 24);
    $cacheStart = date('Y-m-d H:i:s', strtotime("-{$cacheHours} hours"));

    $cached = dbFetchOne(
        "SELECT * FROM pagespeed_results 
         WHERE site_id = ? AND test_url = ? AND strategy = ? AND created_at >= ?
         ORDER BY created_at DESC LIMIT 1",
        [$site['id'], $testUrl, $strategy, $cacheStart]
    );

    if ($cached) {
        $responseTime = (int)((microtime(true) - $startTime) * 1000);
        dbUpdate('api_usage_log', [
            'status_code'     => 200,
            'response_time_ms' => $responseTime,
            'metadata'        => json_encode(['cached' => true, 'result_id' => $cached['id']]),
        ], 'id = ?', [$logId]);

        jsonSuccess([
            'cached'     => true,
            'result_id'  => (int)$cached['id'],
            'strategy'   => $cached['strategy'],
            'scores'     => [
                'performance'    => (int)$cached['performance_score'],
                'accessibility'  => (int)$cached['accessibility_score'],
                'best_practices' => (int)$cached['best_practices_score'],
                'seo'            => (int)$cached['seo_score'],
            ],
            'metrics'    => [
                'fcp_ms' => (int)$cached['fcp_ms'],
                'lcp_ms' => (int)$cached['lcp_ms'],
                'cls'    => (float)$cached['cls'],
                'tbt_ms' => (int)$cached['tbt_ms'],
                'si_ms'  => (int)$cached['si_ms'],
                'tti_ms' => (int)$cached['tti_ms'],
            ],
            'opportunities' => json_decode($cached['opportunities'] ?? '[]', true),
            'diagnostics'   => json_decode($cached['diagnostics'] ?? '[]', true),
            'tested_at'     => $cached['created_at'],
        ]);
    }
}

// Call Google PageSpeed Insights API
$apiKey = Settings::get('pagespeed_api_key');
if (empty($apiKey)) {
    dbUpdate('api_usage_log', ['status_code' => 500, 'error_message' => 'PageSpeed API key not configured'], 'id = ?', [$logId]);
    jsonError('PageSpeed API key not configured on hub', 500);
}

$psiUrl = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed?' . http_build_query([
    'url'      => $testUrl,
    'strategy' => $strategy,
    'key'      => $apiKey,
    'category' => ['performance', 'accessibility', 'best-practices', 'seo'],
]);

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $psiUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 120, // PSI can take a while
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTPHEADER     => ['Accept: application/json'],
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

$responseTime = (int)((microtime(true) - $startTime) * 1000);

if ($curlError) {
    dbUpdate('api_usage_log', [
        'status_code'      => 0,
        'response_time_ms' => $responseTime,
        'error_message'    => 'cURL error: ' . $curlError,
    ], 'id = ?', [$logId]);
    jsonError('Failed to contact PageSpeed API: ' . $curlError, 502);
}

$data = json_decode($response, true);

if ($httpCode !== 200 || !$data) {
    $errMsg = $data['error']['message'] ?? "HTTP {$httpCode}";
    dbUpdate('api_usage_log', [
        'status_code'      => $httpCode,
        'response_time_ms' => $responseTime,
        'error_message'    => $errMsg,
    ], 'id = ?', [$logId]);
    jsonError('PageSpeed API error: ' . $errMsg, 502);
}

// Extract scores and metrics
$categories = $data['lighthouseResult']['categories'] ?? [];
$audits = $data['lighthouseResult']['audits'] ?? [];

$scores = [
    'performance'    => isset($categories['performance']) ? (int)round($categories['performance']['score'] * 100) : null,
    'accessibility'  => isset($categories['accessibility']) ? (int)round($categories['accessibility']['score'] * 100) : null,
    'best_practices' => isset($categories['best-practices']) ? (int)round($categories['best-practices']['score'] * 100) : null,
    'seo'            => isset($categories['seo']) ? (int)round($categories['seo']['score'] * 100) : null,
];

$metrics = [
    'fcp_ms' => (int)($audits['first-contentful-paint']['numericValue'] ?? 0),
    'lcp_ms' => (int)($audits['largest-contentful-paint']['numericValue'] ?? 0),
    'cls'    => round((float)($audits['cumulative-layout-shift']['numericValue'] ?? 0), 3),
    'tbt_ms' => (int)($audits['total-blocking-time']['numericValue'] ?? 0),
    'si_ms'  => (int)($audits['speed-index']['numericValue'] ?? 0),
    'tti_ms' => (int)($audits['interactive']['numericValue'] ?? 0),
];

// Extract opportunities (audits with savings)
$opportunities = [];
foreach ($audits as $auditId => $audit) {
    if (
        isset($audit['details']['overallSavingsMs']) &&
        $audit['details']['overallSavingsMs'] > 0
    ) {
        $opportunities[] = [
            'id'          => $auditId,
            'title'       => $audit['title'] ?? $auditId,
            'description' => $audit['description'] ?? '',
            'savings_ms'  => (int)$audit['details']['overallSavingsMs'],
            'savings_bytes' => (int)($audit['details']['overallSavingsBytes'] ?? 0),
            'score'       => round($audit['score'] ?? 0, 2),
        ];
    }
}
usort($opportunities, fn($a, $b) => $b['savings_ms'] <=> $a['savings_ms']);

// Extract diagnostics
$diagnostics = [];
$diagnosticIds = [
    'dom-size', 'render-blocking-resources', 'uses-long-cache-ttl',
    'total-byte-weight', 'mainthread-work-breakdown', 'bootup-time',
    'font-display', 'uses-passive-event-listeners', 'third-party-summary',
];
foreach ($diagnosticIds as $did) {
    if (isset($audits[$did])) {
        $diagnostics[] = [
            'id'    => $did,
            'title' => $audits[$did]['title'] ?? $did,
            'score' => round($audits[$did]['score'] ?? 0, 2),
            'value' => $audits[$did]['displayValue'] ?? '',
        ];
    }
}

// Store result
$resultId = dbInsert('pagespeed_results', [
    'site_id'              => $site['id'],
    'test_url'             => $testUrl,
    'strategy'             => $strategy,
    'performance_score'    => $scores['performance'],
    'accessibility_score'  => $scores['accessibility'],
    'best_practices_score' => $scores['best_practices'],
    'seo_score'            => $scores['seo'],
    'fcp_ms'               => $metrics['fcp_ms'],
    'lcp_ms'               => $metrics['lcp_ms'],
    'cls'                  => $metrics['cls'],
    'tbt_ms'               => $metrics['tbt_ms'],
    'si_ms'                => $metrics['si_ms'],
    'tti_ms'               => $metrics['tti_ms'],
    'opportunities'        => json_encode($opportunities),
    'diagnostics'          => json_encode($diagnostics),
    'full_response'        => json_encode($data), // Store full PSI response
]);

// Update usage log
dbUpdate('api_usage_log', [
    'status_code'      => 200,
    'response_time_ms' => $responseTime,
    'metadata'         => json_encode(['result_id' => $resultId, 'cached' => false]),
], 'id = ?', [$logId]);

jsonSuccess([
    'cached'        => false,
    'result_id'     => (int)$resultId,
    'strategy'      => $strategy,
    'scores'        => $scores,
    'metrics'       => $metrics,
    'opportunities' => $opportunities,
    'diagnostics'   => $diagnostics,
    'tested_at'     => date('Y-m-d H:i:s'),
]);
