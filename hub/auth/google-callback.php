<?php
/**
 * Google OAuth - Callback Handler
 * 
 * Exchanges auth code for tokens, verifies domain, creates/updates user.
 * 
 * @package CCM_API_Hub
 * @since 1.0.0
 */

require_once dirname(__DIR__) . '/config/config.php';

// Handle errors from Google
if (isset($_GET['error'])) {
    appLog('Google OAuth error: ' . ($_GET['error'] ?? 'unknown'), 'warning');
    redirect(APP_URL . '/login.php?error=oauth_failed');
}

// Verify state (CSRF protection)
$state = $_GET['state'] ?? '';
$sessionState = $_SESSION['oauth_state'] ?? '';
unset($_SESSION['oauth_state']);

if (empty($state) || !hash_equals($sessionState, $state)) {
    appLog('OAuth state mismatch', 'warning');
    redirect(APP_URL . '/login.php?error=invalid_state');
}

// Exchange auth code for tokens
$code = $_GET['code'] ?? '';
if (empty($code)) {
    redirect(APP_URL . '/login.php?error=oauth_failed');
}

$tokenData = [
    'code'          => $code,
    'client_id'     => GOOGLE_CLIENT_ID,
    'client_secret' => GOOGLE_CLIENT_SECRET,
    'redirect_uri'  => GOOGLE_REDIRECT_URI,
    'grant_type'    => 'authorization_code',
];

$ch = curl_init('https://oauth2.googleapis.com/token');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query($tokenData),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200 || !$response) {
    appLog("Token exchange failed: HTTP {$httpCode}", 'error');
    redirect(APP_URL . '/login.php?error=token_failed');
}

$tokens = json_decode($response, true);
if (empty($tokens['id_token'])) {
    appLog('No ID token in response', 'error');
    redirect(APP_URL . '/login.php?error=token_failed');
}

// Decode JWT ID token (header.payload.signature)
$idTokenParts = explode('.', $tokens['id_token']);
if (count($idTokenParts) !== 3) {
    redirect(APP_URL . '/login.php?error=token_failed');
}

$payload = json_decode(base64_decode(strtr($idTokenParts[1], '-_', '+/')), true);

if (!$payload) {
    redirect(APP_URL . '/login.php?error=token_failed');
}

// Verify token claims
$now = time();
if (($payload['iss'] ?? '') !== 'https://accounts.google.com' &&
    ($payload['iss'] ?? '') !== 'accounts.google.com') {
    appLog('Invalid JWT issuer: ' . ($payload['iss'] ?? ''), 'warning');
    redirect(APP_URL . '/login.php?error=token_failed');
}

if (($payload['aud'] ?? '') !== GOOGLE_CLIENT_ID) {
    appLog('Invalid JWT audience', 'warning');
    redirect(APP_URL . '/login.php?error=token_failed');
}

if (isset($payload['exp']) && $payload['exp'] < $now) {
    redirect(APP_URL . '/login.php?error=token_failed');
}

// Verify email domain
$email = strtolower($payload['email'] ?? '');
$domain = substr($email, strpos($email, '@') + 1);

if ($domain !== GOOGLE_ALLOWED_DOMAIN) {
    appLog("Domain not allowed: {$email}", 'warning');
    redirect(APP_URL . '/login.php?error=domain_not_allowed');
}

// Process login
$googleUser = [
    'email'   => $email,
    'name'    => $payload['name'] ?? '',
    'sub'     => $payload['sub'] ?? '',
    'picture' => $payload['picture'] ?? '',
];

if (processGoogleLogin($googleUser)) {
    redirect(APP_URL . '/admin/');
} else {
    redirect(APP_URL . '/login.php?error=user_inactive');
}
