<?php
/**
 * AI Optimization Session Endpoint
 * 
 * Orchestrates a full PageSpeed → AI → Apply → Retest optimization loop.
 * This is a long-running operation that:
 * 1. Runs initial PageSpeed test
 * 2. Sends results to Claude for analysis
 * 3. Returns recommended settings for the plugin to apply
 * 4. Plugin applies settings and calls back for retest
 * 
 * POST /api/v1/ai/optimize
 * Headers: X-CCM-Api-Key, X-CCM-Site-Url
 * Body (JSON):
 *   action            string  "start", "retest", "complete"
 *   session_id        int     Session ID (required for retest/complete)
 *   url               string  URL to optimize (default: site root)
 *   strategy          string  "mobile" or "desktop" (default: "mobile")
 *   session_type      string  "full_audit", "quick_fix", "targeted"
 *   current_settings  object  Current CCM Tools performance settings
 *   applied_settings  object  Settings that were applied (for retest)
 *   context           string  Optional extra context
 * 
 * @package CCM_API_Hub
 * @since 1.0.0
 */

$site = authenticateApiRequest();
checkFeatureAccess($site, 'ai');

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? 'start';

switch ($action) {

    // ─────────────────────────────────────────
    // START: Create new optimization session
    // ─────────────────────────────────────────
    case 'start':
        checkRateLimit($site['id'], 'ai/optimize', 10, 3600); // 10 sessions per hour max
        checkAiUsageLimit($site);

        $url = $input['url'] ?? $site['site_url'];
        $strategy = in_array($input['strategy'] ?? '', ['mobile', 'desktop']) ? $input['strategy'] : 'mobile';
        $sessionType = in_array($input['session_type'] ?? '', ['full_audit', 'quick_fix', 'targeted'])
            ? $input['session_type'] : 'full_audit';
        $currentSettings = $input['current_settings'] ?? [];
        $context = $input['context'] ?? '';

        // Validate URL
        if (!urlMatchesSite($url, $site['site_url'])) {
            jsonError('URL does not belong to the authenticated site', 403);
        }

        // Create session
        $sessionId = dbInsert('ai_sessions', [
            'site_id'       => $site['id'],
            'session_type'  => $sessionType,
            'status'        => 'running',
            'started_at'    => date('Y-m-d H:i:s'),
        ]);

        $logId = logApiUsage($site['id'], 'ai/optimize', 'ai', [
            'metadata' => json_encode(['session_id' => $sessionId, 'phase' => 'start']),
        ]);

        // Step 1: Run PageSpeed test (reuse pagespeed-test logic inline)
        $psResult = runPageSpeedTest($site, $url, $strategy);

        if (!$psResult['success']) {
            dbUpdate('ai_sessions', [
                'status'    => 'failed',
                'error_log' => 'PageSpeed test failed: ' . $psResult['error'],
            ], 'id = ?', [$sessionId]);

            jsonError('PageSpeed test failed: ' . $psResult['error'], 502);
        }

        // Store initial scores
        $initialScores = $psResult['scores'];
        dbUpdate('ai_sessions', [
            'initial_scores' => json_encode($initialScores),
            'status'         => 'analyzing',
        ], 'id = ?', [$sessionId]);

        // Step 2: Get AI analysis
        $aiResult = runAiAnalysis($site, $psResult, $currentSettings, $context);

        if (!$aiResult['success']) {
            dbUpdate('ai_sessions', [
                'status'    => 'failed',
                'error_log' => 'AI analysis failed: ' . $aiResult['error'],
            ], 'id = ?', [$sessionId]);

            jsonError('AI analysis failed: ' . $aiResult['error'], 502);
        }

        // Update session with recommendations
        dbUpdate('ai_sessions', [
            'status'            => 'applying',
            'recommendations'   => json_encode($aiResult['analysis']),
            'total_tokens_used' => $aiResult['tokens'],
            'total_cost_usd'    => $aiResult['cost'],
            'iterations'        => 1,
        ], 'id = ?', [$sessionId]);

        // Update usage log
        dbUpdate('api_usage_log', [
            'tokens_used'  => $aiResult['tokens'],
            'input_tokens' => $aiResult['input_tokens'],
            'output_tokens' => $aiResult['output_tokens'],
            'cost_usd'     => $aiResult['cost'],
            'status_code'  => 200,
        ], 'id = ?', [$logId]);

        jsonSuccess([
            'session_id'      => (int)$sessionId,
            'status'          => 'applying',
            'initial_scores'  => $initialScores,
            'analysis'        => $aiResult['analysis'],
            'pagespeed_result_id' => $psResult['result_id'],
            'message'         => 'Apply the recommended settings, then call back with action=retest',
        ]);
        break;

    // ─────────────────────────────────────────
    // RETEST: After plugin applied settings
    // ─────────────────────────────────────────
    case 'retest':
        $sessionId = (int)($input['session_id'] ?? 0);
        if ($sessionId <= 0) {
            jsonError('session_id is required', 400);
        }

        $session = dbFetchOne(
            "SELECT * FROM ai_sessions WHERE id = ? AND site_id = ?",
            [$sessionId, $site['id']]
        );

        if (!$session) {
            jsonError('Session not found', 404);
        }

        if (!in_array($session['status'], ['applying', 'testing'])) {
            jsonError('Session is not in a retestable state (status: ' . $session['status'] . ')', 400);
        }

        $maxIterations = Settings::int('max_optimization_iterations', 5);
        if ((int)$session['iterations'] >= $maxIterations) {
            // Max iterations reached, finalize
            dbUpdate('ai_sessions', [
                'status'       => 'completed',
                'completed_at' => date('Y-m-d H:i:s'),
            ], 'id = ?', [$sessionId]);

            jsonSuccess([
                'session_id' => $sessionId,
                'status'     => 'completed',
                'message'    => 'Maximum optimization iterations reached.',
                'iterations' => (int)$session['iterations'],
            ]);
            break;
        }

        checkAiUsageLimit($site);

        $appliedSettings = $input['applied_settings'] ?? [];
        $url = $input['url'] ?? $site['site_url'];
        $strategy = in_array($input['strategy'] ?? '', ['mobile', 'desktop']) ? $input['strategy'] : 'mobile';

        dbUpdate('ai_sessions', ['status' => 'testing'], 'id = ?', [$sessionId]);

        $logId = logApiUsage($site['id'], 'ai/optimize', 'ai', [
            'metadata' => json_encode(['session_id' => $sessionId, 'phase' => 'retest']),
        ]);

        // Run new PageSpeed test
        $psResult = runPageSpeedTest($site, $url, $strategy);

        if (!$psResult['success']) {
            dbUpdate('ai_sessions', [
                'status'    => 'failed',
                'error_log' => $session['error_log'] . "\nRetest PageSpeed failed: " . $psResult['error'],
            ], 'id = ?', [$sessionId]);

            jsonError('Retest PageSpeed failed: ' . $psResult['error'], 502);
        }

        $newScores = $psResult['scores'];
        $initialScores = json_decode($session['initial_scores'] ?? '{}', true);

        // Calculate improvement
        $improvement = [];
        foreach ($newScores as $key => $newVal) {
            $oldVal = $initialScores[$key] ?? 0;
            $improvement[$key] = $newVal - $oldVal;
        }

        $perfImproved = ($improvement['performance'] ?? 0) > 0;

        // If performance didn't improve much, get more AI suggestions
        if (!$perfImproved || ($newScores['performance'] ?? 0) < 90) {
            $context = "This is iteration " . ((int)$session['iterations'] + 1) . ". ";
            $context .= "Previously applied settings: " . json_encode($appliedSettings) . ". ";
            $context .= "Score change: " . json_encode($improvement) . ".";

            $aiResult = runAiAnalysis($site, $psResult, $appliedSettings, $context);

            if ($aiResult['success']) {
                $prevTokens = (int)$session['total_tokens_used'];
                $prevCost = (float)$session['total_cost_usd'];

                dbUpdate('ai_sessions', [
                    'status'            => 'applying',
                    'final_scores'      => json_encode($newScores),
                    'actions_taken'     => json_encode($appliedSettings),
                    'recommendations'   => json_encode($aiResult['analysis']),
                    'total_tokens_used' => $prevTokens + $aiResult['tokens'],
                    'total_cost_usd'    => $prevCost + $aiResult['cost'],
                    'iterations'        => (int)$session['iterations'] + 1,
                ], 'id = ?', [$sessionId]);

                dbUpdate('api_usage_log', [
                    'tokens_used'   => $aiResult['tokens'],
                    'input_tokens'  => $aiResult['input_tokens'],
                    'output_tokens' => $aiResult['output_tokens'],
                    'cost_usd'      => $aiResult['cost'],
                    'status_code'   => 200,
                ], 'id = ?', [$logId]);

                jsonSuccess([
                    'session_id'     => $sessionId,
                    'status'         => 'applying',
                    'iteration'      => (int)$session['iterations'] + 1,
                    'initial_scores' => $initialScores,
                    'current_scores' => $newScores,
                    'improvement'    => $improvement,
                    'analysis'       => $aiResult['analysis'],
                    'message'        => 'Apply updated settings, then call retest again or complete.',
                ]);
            }
        }

        // Performance is good enough or AI analysis failed — complete
        dbUpdate('ai_sessions', [
            'status'        => 'completed',
            'final_scores'  => json_encode($newScores),
            'actions_taken' => json_encode($appliedSettings),
            'iterations'    => (int)$session['iterations'] + 1,
            'completed_at'  => date('Y-m-d H:i:s'),
        ], 'id = ?', [$sessionId]);

        jsonSuccess([
            'session_id'     => $sessionId,
            'status'         => 'completed',
            'iteration'      => (int)$session['iterations'] + 1,
            'initial_scores' => $initialScores,
            'final_scores'   => $newScores,
            'improvement'    => $improvement,
            'message'        => 'Optimization complete!',
        ]);
        break;

    // ─────────────────────────────────────────
    // COMPLETE: Manually close a session
    // ─────────────────────────────────────────
    case 'complete':
        $sessionId = (int)($input['session_id'] ?? 0);
        if ($sessionId <= 0) jsonError('session_id is required', 400);

        $session = dbFetchOne(
            "SELECT * FROM ai_sessions WHERE id = ? AND site_id = ?",
            [$sessionId, $site['id']]
        );

        if (!$session) jsonError('Session not found', 404);

        dbUpdate('ai_sessions', [
            'status'       => 'completed',
            'final_scores' => json_encode($input['final_scores'] ?? null),
            'actions_taken' => json_encode($input['applied_settings'] ?? null),
            'completed_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$sessionId]);

        jsonSuccess([
            'session_id' => $sessionId,
            'status'     => 'completed',
            'message'    => 'Session closed.',
        ]);
        break;

    default:
        jsonError('Invalid action. Use: start, retest, or complete', 400);
}


// ─────────────────────────────────────────────
// Internal helper: Run a PageSpeed test
// ─────────────────────────────────────────────
function runPageSpeedTest(array $site, string $url, string $strategy): array
{
    $apiKey = Settings::get('pagespeed_api_key');
    if (empty($apiKey)) {
        return ['success' => false, 'error' => 'PageSpeed API key not configured'];
    }

    $psiUrl = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed?' . http_build_query([
        'url'      => $url,
        'strategy' => $strategy,
        'key'      => $apiKey,
        'category' => ['performance', 'accessibility', 'best-practices', 'seo'],
    ]);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $psiUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_FOLLOWLOCATION => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['success' => false, 'error' => 'cURL: ' . $curlError];
    }

    $data = json_decode($response, true);
    if ($httpCode !== 200 || !$data) {
        return ['success' => false, 'error' => $data['error']['message'] ?? "HTTP {$httpCode}"];
    }

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

    // Extract opportunities
    $opportunities = [];
    foreach ($audits as $auditId => $audit) {
        if (isset($audit['details']['overallSavingsMs']) && $audit['details']['overallSavingsMs'] > 0) {
            $opportunities[] = [
                'id'         => $auditId,
                'title'      => $audit['title'] ?? $auditId,
                'savings_ms' => (int)$audit['details']['overallSavingsMs'],
            ];
        }
    }

    // Store in DB
    $resultId = dbInsert('pagespeed_results', [
        'site_id'              => $site['id'],
        'test_url'             => $url,
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
        'full_response'        => json_encode($data),
    ]);

    return [
        'success'       => true,
        'result_id'     => $resultId,
        'scores'        => $scores,
        'metrics'       => $metrics,
        'opportunities' => $opportunities,
    ];
}


// ─────────────────────────────────────────────
// Internal helper: Run AI analysis via Claude
// ─────────────────────────────────────────────
function runAiAnalysis(array $site, array $psResult, array $currentSettings, string $context): array
{
    $claudeApiKey = Settings::get('claude_api_key');
    if (empty($claudeApiKey)) {
        return ['success' => false, 'error' => 'Claude API key not configured'];
    }

    $claudeModel = Settings::get('claude_model', 'claude-sonnet-4-20250514');
    $maxTokens = Settings::int('claude_max_tokens', 4096);

    $systemPrompt = "You are a WordPress performance optimization AI. Analyze the PageSpeed results and provide specific CCM Tools plugin settings to improve scores. Respond ONLY with valid JSON matching this schema: {\"summary\": string, \"priority\": \"high|medium|low\", \"recommendations\": [{\"setting_key\": string, \"recommended_value\": mixed, \"reason\": string, \"estimated_impact\": \"high|medium|low\"}], \"additional_notes\": string}";

    $userMessage = "Site: {$site['site_url']}\n"
        . "Performance: {$psResult['scores']['performance']}/100\n"
        . "LCP: {$psResult['metrics']['lcp_ms']}ms, FCP: {$psResult['metrics']['fcp_ms']}ms, TBT: {$psResult['metrics']['tbt_ms']}ms, CLS: {$psResult['metrics']['cls']}\n";

    if (!empty($psResult['opportunities'])) {
        $userMessage .= "\nTop opportunities:\n";
        foreach (array_slice($psResult['opportunities'], 0, 10) as $opp) {
            $userMessage .= "- {$opp['title']}: {$opp['savings_ms']}ms\n";
        }
    }

    if (!empty($currentSettings)) {
        $userMessage .= "\nCurrent settings: " . json_encode($currentSettings) . "\n";
    }

    if ($context) {
        $userMessage .= "\nContext: {$context}\n";
    }

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
        CURLOPT_POSTFIELDS => json_encode([
            'model'      => $claudeModel,
            'max_tokens' => $maxTokens,
            'system'     => $systemPrompt,
            'messages'   => [['role' => 'user', 'content' => $userMessage]],
        ]),
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['success' => false, 'error' => 'cURL: ' . $curlError];
    }

    $data = json_decode($response, true);
    if ($httpCode !== 200 || !$data) {
        return ['success' => false, 'error' => $data['error']['message'] ?? "HTTP {$httpCode}"];
    }

    $usage = $data['usage'] ?? [];
    $inputTokens = (int)($usage['input_tokens'] ?? 0);
    $outputTokens = (int)($usage['output_tokens'] ?? 0);
    $totalTokens = $inputTokens + $outputTokens;
    $cost = ($inputTokens * 3.0 / 1_000_000) + ($outputTokens * 15.0 / 1_000_000);

    $aiText = '';
    foreach ($data['content'] ?? [] as $block) {
        if ($block['type'] === 'text') $aiText .= $block['text'];
    }

    $analysis = json_decode($aiText, true);
    if (!$analysis && preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $aiText, $m)) {
        $analysis = json_decode($m[1], true);
    }
    if (!$analysis) {
        $analysis = ['summary' => 'Analysis completed', 'raw_response' => $aiText, 'recommendations' => []];
    }

    return [
        'success'       => true,
        'analysis'      => $analysis,
        'tokens'        => $totalTokens,
        'input_tokens'  => $inputTokens,
        'output_tokens' => $outputTokens,
        'cost'          => $cost,
    ];
}
