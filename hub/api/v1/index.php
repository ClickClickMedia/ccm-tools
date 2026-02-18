<?php
/**
 * API v1 Router
 * 
 * Routes incoming API requests to the correct handler.
 * All endpoints require API key authentication via X-CCM-Api-Key header.
 * 
 * Endpoints:
 *   POST /api/v1/health           — Health check / connection test
 *   POST /api/v1/pagespeed/test   — Run PageSpeed test
 *   GET  /api/v1/pagespeed/results — Get cached results
 *   POST /api/v1/ai/analyze       — AI performance analysis
 *   POST /api/v1/ai/optimize      — Full AI optimization session
 * 
 * @package CCM_API_Hub
 * @since 1.0.0
 */

// Don't start session for API requests
define('API_REQUEST', true);
require_once dirname(dirname(__DIR__)) . '/config/config.php';

// CORS headers for API
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// Check maintenance mode
if (Settings::bool('maintenance_mode')) {
    jsonError('Service temporarily unavailable', 503);
}

// Parse the endpoint from REQUEST_URI
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$basePath = '/api/v1/';
$pos = strpos($requestUri, $basePath);

if ($pos === false) {
    jsonError('Invalid endpoint', 404);
}

$endpoint = substr($requestUri, $pos + strlen($basePath));
$endpoint = strtok($endpoint, '?'); // Remove query string
$endpoint = rtrim($endpoint, '/');

$method = $_SERVER['REQUEST_METHOD'];

// Route to handler
switch ($endpoint) {
    case 'health':
        require __DIR__ . '/health.php';
        break;

    case 'pagespeed/test':
        if ($method !== 'POST') jsonError('Method not allowed', 405);
        require __DIR__ . '/pagespeed-test.php';
        break;

    case 'pagespeed/results':
        if ($method !== 'GET' && $method !== 'POST') jsonError('Method not allowed', 405);
        require __DIR__ . '/pagespeed-results.php';
        break;

    case 'ai/analyze':
        if ($method !== 'POST') jsonError('Method not allowed', 405);
        require __DIR__ . '/ai-analyze.php';
        break;

    case 'ai/optimize':
        if ($method !== 'POST') jsonError('Method not allowed', 405);
        require __DIR__ . '/ai-optimize.php';
        break;

    default:
        jsonError('Unknown endpoint: ' . $endpoint, 404);
}
