<?php
/**
 * Google OAuth - Initiate Login
 * 
 * Generates CSRF state token and redirects to Google consent screen.
 * 
 * @package CCM_API_Hub
 * @since 1.0.0
 */

require_once dirname(__DIR__) . '/config/config.php';

if (!isGoogleSSOEnabled()) {
    redirect(APP_URL . '/login.php?error=sso_disabled');
}

// Generate CSRF state token
$state = generateToken();
$_SESSION['oauth_state'] = $state;

// Build Google OAuth URL
$params = http_build_query([
    'client_id'     => GOOGLE_CLIENT_ID,
    'redirect_uri'  => GOOGLE_REDIRECT_URI,
    'response_type' => 'code',
    'scope'         => 'openid email profile',
    'state'         => $state,
    'hd'            => GOOGLE_ALLOWED_DOMAIN,
    'prompt'        => 'select_account',
    'access_type'   => 'online',
]);

redirect('https://accounts.google.com/o/oauth2/v2/auth?' . $params);
