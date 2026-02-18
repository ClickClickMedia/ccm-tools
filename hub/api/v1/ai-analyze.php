<?php
/**
 * AI Analysis Endpoint
 * 
 * Sends PageSpeed results to Claude AI for performance analysis.
 * Returns actionable recommendations mapped to CCM Tools settings.
 * 
 * POST /api/v1/ai/analyze
 * Headers: X-CCM-Api-Key, X-CCM-Site-Url
 * Body (JSON):
 *   result_id       int     PageSpeed result ID to analyze
 *   current_settings object  Current CCM Tools performance settings on the site
 *   context         string  Optional extra context (e.g. "WooCommerce site")
 * 
 * @package CCM_API_Hub
 * @since 1.0.0
 */

$site = authenticateApiRequest();
checkFeatureAccess($site, 'ai');
checkRateLimit($site['id'], 'ai/analyze', Settings::int('rate_limit_ai_per_hour', 50), 3600);
checkAiUsageLimit($site);

$input = json_decode(file_get_contents('php://input'), true) ?: [];

$resultId = (int)($input['result_id'] ?? 0);
$currentSettings = $input['current_settings'] ?? [];
$context = $input['context'] ?? '';

if ($resultId <= 0) {
    jsonError('result_id is required', 400);
}

// Fetch the PageSpeed result
$psResult = dbFetchOne(
    "SELECT * FROM pagespeed_results WHERE id = ? AND site_id = ?",
    [$resultId, $site['id']]
);

if (!$psResult) {
    jsonError('PageSpeed result not found', 404);
}

$startTime = microtime(true);
$logId = logApiUsage($site['id'], 'ai/analyze', 'ai');

// Prepare the prompt for Claude
$scores = [
    'Performance'    => $psResult['performance_score'],
    'Accessibility'  => $psResult['accessibility_score'],
    'Best Practices' => $psResult['best_practices_score'],
    'SEO'            => $psResult['seo_score'],
];

$metrics = [
    'FCP'  => $psResult['fcp_ms'] . 'ms',
    'LCP'  => $psResult['lcp_ms'] . 'ms',
    'CLS'  => $psResult['cls'],
    'TBT'  => $psResult['tbt_ms'] . 'ms',
    'SI'   => $psResult['si_ms'] . 'ms',
    'TTI'  => $psResult['tti_ms'] . 'ms',
];

$opportunities = json_decode($psResult['opportunities'] ?? '[]', true);
$diagnostics = json_decode($psResult['diagnostics'] ?? '[]', true);

$systemPrompt = <<<PROMPT
You are a WordPress performance optimization expert. You analyze Google PageSpeed Insights results and provide specific, actionable recommendations.

You are integrated with the CCM Tools WordPress plugin which has these optimization features:
- Defer JavaScript (with exclude list)
- Delay JavaScript until user interaction (with exclude list and fallback timeout)
- Async CSS Loading (with exclude list for critical stylesheets)
- Critical CSS inlining
- Remove query strings from static resources
- Disable WordPress emoji scripts
- Disable dashicons for logged-out users
- Lazy load iframes
- YouTube Lite Embeds (facade pattern)
- Font Display: Swap for Google Fonts
- Speculation Rules API for prerendering
- LCP Fetchpriority on first image
- Preconnect and DNS Prefetch hints
- Disable Block Library CSS (Gutenberg)
- Disable jQuery Migrate
- Disable WooCommerce Cart Fragments
- Reduce Heartbeat frequency
- Head Cleanup (XML-RPC, RSD, Shortlink, REST API, oEmbed)
- WebP image conversion
- .htaccess optimization (caching headers, compression)

Your response MUST be valid JSON with this structure:
{
    "summary": "Brief overall assessment (1-2 sentences)",
    "score_assessment": "Description of current score state",
    "priority": "high|medium|low",
    "recommendations": [
        {
            "setting_key": "exact_ccm_setting_key",
            "recommended_value": true/false/"value",
            "reason": "Why this helps",
            "estimated_impact": "high|medium|low",
            "related_opportunity": "PSI opportunity ID if applicable"
        }
    ],
    "exclude_suggestions": {
        "defer_js_excludes": ["script handles to exclude from deferring"],
        "delay_js_excludes": ["script handles to exclude from delaying"],
        "preload_css_excludes": ["stylesheet handles to keep render-blocking"]
    },
    "additional_notes": "Any extra context or warnings",
    "estimated_score_improvement": {
        "performance": "+N points estimated"
    }
}
PROMPT;

$userMessage = "Analyze this PageSpeed Insights result and recommend CCM Tools settings.\n\n";
$userMessage .= "**Site:** {$site['site_url']}\n";
$userMessage .= "**URL Tested:** {$psResult['test_url']}\n";
$userMessage .= "**Strategy:** {$psResult['strategy']}\n\n";

$userMessage .= "**Scores:**\n";
foreach ($scores as $name => $score) {
    $userMessage .= "- {$name}: {$score}/100\n";
}

$userMessage .= "\n**Core Web Vitals:**\n";
foreach ($metrics as $name => $value) {
    $userMessage .= "- {$name}: {$value}\n";
}

if (!empty($opportunities)) {
    $userMessage .= "\n**Opportunities (savings):**\n";
    foreach ($opportunities as $opp) {
        $userMessage .= "- [{$opp['id']}] {$opp['title']}: {$opp['savings_ms']}ms savings\n";
    }
}

if (!empty($diagnostics)) {
    $userMessage .= "\n**Diagnostics:**\n";
    foreach ($diagnostics as $diag) {
        $userMessage .= "- [{$diag['id']}] {$diag['title']}: {$diag['value']}\n";
    }
}

if (!empty($currentSettings)) {
    $userMessage .= "\n**Current CCM Tools Settings:**\n";
    $userMessage .= json_encode($currentSettings, JSON_PRETTY_PRINT) . "\n";
}

if (!empty($context)) {
    $userMessage .= "\n**Additional Context:** {$context}\n";
}

// Call Claude API
$claudeApiKey = Settings::get('claude_api_key');
if (empty($claudeApiKey)) {
    dbUpdate('api_usage_log', ['status_code' => 500, 'error_message' => 'Claude API key not configured'], 'id = ?', [$logId]);
    jsonError('Claude API key not configured on hub', 500);
}

$claudeModel = Settings::get('claude_model', 'claude-sonnet-4-20250514');
$maxTokens = Settings::int('claude_max_tokens', 4096);

$claudePayload = [
    'model'      => $claudeModel,
    'max_tokens' => $maxTokens,
    'system'     => $systemPrompt,
    'messages'   => [
        ['role' => 'user', 'content' => $userMessage],
    ],
];

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'x-api-key: ' . $claudeApiKey,
        'anthropic-version: 2023-06-01',
    ],
    CURLOPT_POSTFIELDS     => json_encode($claudePayload),
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
    jsonError('Failed to contact Claude API: ' . $curlError, 502);
}

$claudeData = json_decode($response, true);

if ($httpCode !== 200 || !$claudeData) {
    $errMsg = $claudeData['error']['message'] ?? "HTTP {$httpCode}";
    dbUpdate('api_usage_log', [
        'status_code'      => $httpCode,
        'response_time_ms' => $responseTime,
        'error_message'    => $errMsg,
    ], 'id = ?', [$logId]);
    jsonError('Claude API error: ' . $errMsg, 502);
}

// Extract token usage
$usage = $claudeData['usage'] ?? [];
$inputTokens = (int)($usage['input_tokens'] ?? 0);
$outputTokens = (int)($usage['output_tokens'] ?? 0);
$totalTokens = $inputTokens + $outputTokens;

// Estimate cost (Claude Sonnet 4: $3/MTok input, $15/MTok output)
$costUsd = ($inputTokens * 3.0 / 1_000_000) + ($outputTokens * 15.0 / 1_000_000);

// Extract AI response text
$aiText = '';
foreach ($claudeData['content'] ?? [] as $block) {
    if ($block['type'] === 'text') {
        $aiText .= $block['text'];
    }
}

// Try to parse JSON from the response
$aiAnalysis = json_decode($aiText, true);
if (!$aiAnalysis) {
    // Try to extract JSON from markdown code block
    if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $aiText, $m)) {
        $aiAnalysis = json_decode($m[1], true);
    }
}

if (!$aiAnalysis) {
    // Wrap raw text as analysis
    $aiAnalysis = [
        'summary'         => 'AI analysis completed (unstructured response).',
        'raw_response'    => $aiText,
        'recommendations' => [],
    ];
}

// Store analysis on the PageSpeed result
dbUpdate('pagespeed_results', [
    'ai_analysis' => json_encode($aiAnalysis),
], 'id = ?', [$resultId]);

// Update usage log
dbUpdate('api_usage_log', [
    'tokens_used'      => $totalTokens,
    'input_tokens'     => $inputTokens,
    'output_tokens'    => $outputTokens,
    'cost_usd'         => $costUsd,
    'status_code'      => 200,
    'response_time_ms' => $responseTime,
    'metadata'         => json_encode([
        'result_id' => $resultId,
        'model'     => $claudeModel,
    ]),
], 'id = ?', [$logId]);

jsonSuccess([
    'result_id'  => $resultId,
    'analysis'   => $aiAnalysis,
    'tokens'     => [
        'input'  => $inputTokens,
        'output' => $outputTokens,
        'total'  => $totalTokens,
    ],
    'cost_usd'   => round($costUsd, 6),
    'model'      => $claudeModel,
]);
