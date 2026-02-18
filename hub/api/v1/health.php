<?php
/**
 * Health Check Endpoint
 * 
 * Verifies API key is valid, site is active, and hub is operational.
 * Used by the WordPress plugin to test connectivity.
 * 
 * POST /api/v1/health
 * Headers: X-CCM-Api-Key, X-CCM-Site-Url
 * 
 * @package CCM_API_Hub
 * @since 1.0.0
 */

$site = authenticateApiRequest();

jsonSuccess([
    'status'    => 'ok',
    'site_name' => $site['site_name'],
    'features'  => [
        'ai'        => (bool)$site['ai_enabled'],
        'pagespeed' => (bool)$site['pagespeed_enabled'],
    ],
    'limits'    => [
        'ai_monthly'      => $site['ai_monthly_limit'],
        'pagespeed_daily'  => $site['pagespeed_daily_limit'],
    ],
    'hub_version' => APP_VERSION,
    'timestamp'   => time(),
]);
