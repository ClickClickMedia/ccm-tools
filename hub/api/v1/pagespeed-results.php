<?php
/**
 * PageSpeed Results Endpoint
 * 
 * Retrieve cached PageSpeed results for the authenticated site.
 * 
 * GET/POST /api/v1/pagespeed/results
 * Headers: X-CCM-Api-Key, X-CCM-Site-Url
 * Params:
 *   strategy  string  "mobile" or "desktop" (default: "mobile")
 *   limit     int     Max results to return (default: 10, max: 50)
 *   url       string  Filter by specific URL (optional)
 * 
 * @package CCM_API_Hub
 * @since 1.0.0
 */

$site = authenticateApiRequest();
checkFeatureAccess($site, 'pagespeed');

// Parse params from query string or POST body
$params = array_merge($_GET, json_decode(file_get_contents('php://input'), true) ?: []);

$strategy = in_array($params['strategy'] ?? '', ['mobile', 'desktop']) ? $params['strategy'] : 'mobile';
$limit = min(max((int)($params['limit'] ?? 10), 1), 50);
$filterUrl = $params['url'] ?? '';

$where = "site_id = ? AND strategy = ?";
$bindings = [$site['id'], $strategy];

if (!empty($filterUrl)) {
    $where .= " AND test_url = ?";
    $bindings[] = $filterUrl;
}

$results = dbFetchAll(
    "SELECT id, test_url, strategy, performance_score, accessibility_score,
            best_practices_score, seo_score, fcp_ms, lcp_ms, cls, tbt_ms, si_ms, tti_ms,
            opportunities, diagnostics, ai_analysis, created_at
     FROM pagespeed_results
     WHERE {$where}
     ORDER BY created_at DESC
     LIMIT {$limit}",
    $bindings
);

$output = [];
foreach ($results as $r) {
    $output[] = [
        'result_id' => (int)$r['id'],
        'url'       => $r['test_url'],
        'strategy'  => $r['strategy'],
        'scores'    => [
            'performance'    => (int)$r['performance_score'],
            'accessibility'  => (int)$r['accessibility_score'],
            'best_practices' => (int)$r['best_practices_score'],
            'seo'            => (int)$r['seo_score'],
        ],
        'metrics'   => [
            'fcp_ms' => (int)$r['fcp_ms'],
            'lcp_ms' => (int)$r['lcp_ms'],
            'cls'    => (float)$r['cls'],
            'tbt_ms' => (int)$r['tbt_ms'],
            'si_ms'  => (int)$r['si_ms'],
            'tti_ms' => (int)$r['tti_ms'],
        ],
        'opportunities' => json_decode($r['opportunities'] ?? '[]', true),
        'diagnostics'   => json_decode($r['diagnostics'] ?? '[]', true),
        'ai_analysis'   => json_decode($r['ai_analysis'] ?? 'null', true),
        'tested_at'     => $r['created_at'],
    ];
}

jsonSuccess([
    'count'   => count($output),
    'results' => $output,
]);
