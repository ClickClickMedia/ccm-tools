<?php
/**
 * Authentication & Authorization
 * 
 * Google SSO authentication with role-based access control.
 * Restricted to @clickclickmedia.com.au domain.
 * 
 * @package CCM_API_Hub
 * @since 1.0.0
 */

// Role constants (hierarchy: viewer < manager < admin)
define('ROLE_VIEWER', 'viewer');
define('ROLE_MANAGER', 'manager');
define('ROLE_ADMIN', 'admin');

$roleHierarchy = [
    ROLE_VIEWER  => 1,
    ROLE_MANAGER => 2,
    ROLE_ADMIN   => 3,
];

/**
 * Check if user is logged in
 */
function isLoggedIn(): bool
{
    return !empty($_SESSION['user_id']) && !empty($_SESSION['user_email']);
}

/**
 * Get current user data from session
 */
function currentUser(): ?array
{
    if (!isLoggedIn()) {
        return null;
    }

    return [
        'id'     => $_SESSION['user_id'],
        'email'  => $_SESSION['user_email'],
        'name'   => $_SESSION['user_name'] ?? '',
        'role'   => $_SESSION['user_role'] ?? ROLE_VIEWER,
        'avatar' => $_SESSION['user_avatar'] ?? '',
    ];
}

/**
 * Check if current user has at least the given role
 */
function hasRole(string $requiredRole): bool
{
    global $roleHierarchy;

    if (!isLoggedIn()) return false;

    $userRole = $_SESSION['user_role'] ?? ROLE_VIEWER;
    $userLevel = $roleHierarchy[$userRole] ?? 0;
    $requiredLevel = $roleHierarchy[$requiredRole] ?? 999;

    return $userLevel >= $requiredLevel;
}

/**
 * Require login – redirect to login page if not authenticated
 */
function requireLogin(): void
{
    if (!isLoggedIn()) {
        redirect(APP_URL . '/login.php');
    }
}

/**
 * Require manager role
 */
function requireManager(): void
{
    requireLogin();
    if (!hasRole(ROLE_MANAGER)) {
        http_response_code(403);
        die('Access denied: Manager role required');
    }
}

/**
 * Require admin role
 */
function requireAdmin(): void
{
    requireLogin();
    if (!hasRole(ROLE_ADMIN)) {
        http_response_code(403);
        die('Access denied: Admin role required');
    }
}

/**
 * Check if Google SSO is configured
 */
function isGoogleSSOEnabled(): bool
{
    return !empty(GOOGLE_CLIENT_ID) && !empty(GOOGLE_CLIENT_SECRET);
}

/**
 * Process Google login callback
 * Creates new user or updates existing, sets session
 */
function processGoogleLogin(array $googleUser): bool
{
    $email = strtolower($googleUser['email']);
    $name = $googleUser['name'] ?? '';
    $googleId = $googleUser['sub'] ?? '';
    $avatarUrl = $googleUser['picture'] ?? '';

    // Check domain restriction
    $domain = substr($email, strpos($email, '@') + 1);
    if ($domain !== GOOGLE_ALLOWED_DOMAIN) {
        return false;
    }

    // Find or create user
    $user = dbFetchOne("SELECT * FROM users WHERE email = ?", [$email]);

    if ($user) {
        // Update existing user
        dbUpdate('users', [
            'name'       => $name,
            'google_id'  => $googleId,
            'avatar_url' => $avatarUrl,
            'last_login' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$user['id']]);

        $user['name'] = $name;
        $user['avatar_url'] = $avatarUrl;
    } else {
        // Determine role
        $wizardEmails = array_map('trim', explode(',', WIZARD_EMAILS));
        $role = in_array($email, $wizardEmails) ? ROLE_ADMIN : ROLE_VIEWER;

        $userId = dbInsert('users', [
            'email'      => $email,
            'name'       => $name,
            'role'       => $role,
            'google_id'  => $googleId,
            'avatar_url' => $avatarUrl,
            'is_active'  => 1,
            'last_login' => date('Y-m-d H:i:s'),
        ]);

        $user = [
            'id'         => $userId,
            'email'      => $email,
            'name'       => $name,
            'role'       => $role,
            'avatar_url' => $avatarUrl,
        ];
    }

    // Check if user is active
    if (isset($user['is_active']) && !$user['is_active']) {
        return false;
    }

    // Set session
    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_email'] = $email;
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['user_avatar'] = $user['avatar_url'] ?? '';
    $_SESSION['login_time'] = time();

    // Log activity
    logActivity('login', 'user', (string)$user['id'], [
        'method' => 'google_sso',
        'email'  => $email,
    ]);

    return true;
}

/**
 * Logout the current user
 */
function logout(): void
{
    if (isLoggedIn()) {
        logActivity('logout', 'user', (string)($_SESSION['user_id'] ?? ''));
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }

    session_destroy();
}

/**
 * Get role display badge class
 */
function roleBadgeClass(string $role): string
{
    return match ($role) {
        ROLE_ADMIN   => 'badge-admin',
        ROLE_MANAGER => 'badge-manager',
        ROLE_VIEWER  => 'badge-viewer',
        default      => 'badge-viewer',
    };
}

/**
 * Get role display label
 */
function roleLabel(string $role): string
{
    return match ($role) {
        ROLE_ADMIN   => 'Admin',
        ROLE_MANAGER => 'Manager',
        ROLE_VIEWER  => 'Viewer',
        default      => 'Viewer',
    };
}
