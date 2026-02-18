<?php
/**
 * Login Page
 * 
 * Google SSO login restricted to @clickclickmedia.com.au accounts.
 * 
 * @package CCM_API_Hub
 * @since 1.0.0
 */

require_once __DIR__ . '/config/config.php';

// Already logged in? Redirect to dashboard
if (isLoggedIn()) {
    redirect(APP_URL . '/admin/');
}

// Error messages from OAuth flow
$errorMessages = [
    'invalid_state'      => 'Security validation failed. Please try again.',
    'domain_not_allowed' => 'Only @clickclickmedia.com.au accounts are allowed.',
    'oauth_failed'       => 'Google authentication failed. Please try again.',
    'token_failed'       => 'Failed to obtain authentication token.',
    'user_inactive'      => 'Your account has been deactivated.',
    'sso_disabled'       => 'Google SSO is not configured.',
];

$error = isset($_GET['error']) ? ($errorMessages[$_GET['error']] ?? 'An unknown error occurred.') : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In — <?= h(APP_NAME) ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body {
            background: var(--bg-secondary);
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
        }
        .login-container {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius-xl);
            padding: 3rem;
            max-width: 420px;
            width: 100%;
            text-align: center;
            box-shadow: var(--shadow-lg);
        }
        .login-logo {
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
        }
        .login-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 0.25rem;
        }
        .login-subtitle {
            color: var(--text-secondary);
            font-size: 0.9rem;
            margin-bottom: 2rem;
        }
        .google-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.75rem;
            background: var(--bg-primary);
            border: 1px solid var(--border-light);
            border-radius: var(--border-radius-md);
            padding: 0.85rem 2rem;
            color: var(--text-primary);
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: all var(--transition-normal);
            text-decoration: none;
            width: 100%;
            justify-content: center;
        }
        .google-btn:hover {
            background: var(--bg-hover);
            border-color: var(--brand-primary);
            color: var(--brand-primary);
        }
        .google-btn svg {
            width: 20px;
            height: 20px;
        }
        .login-domain-notice {
            margin-top: 1.5rem;
            padding: 0.75rem;
            background: rgba(148, 200, 62, 0.05);
            border: 1px solid rgba(148, 200, 62, 0.15);
            border-radius: var(--border-radius-md);
            font-size: 0.8rem;
            color: var(--text-muted);
        }
        .login-domain-notice strong {
            color: var(--brand-primary);
        }
        .login-error {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: var(--accent-danger);
            padding: 0.75rem 1rem;
            border-radius: var(--border-radius-md);
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
        }
        .login-version {
            margin-top: 2rem;
            font-size: 0.75rem;
            color: var(--text-muted);
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-logo">🔒</div>
        <div class="login-title"><?= h(APP_NAME) ?></div>
        <p class="login-subtitle">Centralized API Management Console</p>

        <?php if ($error): ?>
            <div class="login-error"><?= h($error) ?></div>
        <?php endif; ?>

        <?php if (isGoogleSSOEnabled()): ?>
            <a href="auth/google-login.php" class="google-btn">
                <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 01-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z" fill="#4285F4"/>
                    <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
                    <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/>
                    <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
                </svg>
                Sign in with Google
            </a>

            <div class="login-domain-notice">
                Restricted to <strong>@<?= h(GOOGLE_ALLOWED_DOMAIN) ?></strong> accounts
            </div>
        <?php else: ?>
            <div class="login-error">Google SSO is not configured. Please set up OAuth credentials.</div>
        <?php endif; ?>

        <div class="login-version">v<?= h(APP_VERSION) ?></div>
    </div>
</body>
</html>
