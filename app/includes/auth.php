<?php
// ============================================================
// Authentication & Session Management
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

// Start secure session
if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'secure'   => false, // Set true in production with HTTPS
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

// ---- Core auth functions ----

function isLoggedIn(): bool {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: ' . SITE_URL . '/login?redirect=' . urlencode($_SERVER['REQUEST_URI']));
        exit;
    }
    // Regenerate session ID every 30 minutes to prevent fixation
    if (!isset($_SESSION['last_regenerated']) || time() - $_SESSION['last_regenerated'] > 1800) {
        session_regenerate_id(true);
        $_SESSION['last_regenerated'] = time();
    }
}

function login(string $email, string $password): array {
    $db = getDB();
    $stmt = $db->prepare('SELECT u.*, r.slug AS role_slug, r.name AS role_name, r.permissions,
                                 b.name AS business_name, b.currency AS business_currency
                          FROM users u
                          JOIN roles r ON r.id = u.role_id
                          LEFT JOIN businesses b ON b.id = u.business_id
                          WHERE u.email = ? AND u.is_active = 1
                        AND (u.business_id IS NULL OR b.is_active = 1) LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        return ['success' => false, 'message' => 'Invalid email or password.'];
    }

    // Set session variables
    $_SESSION['user_id']          = $user['id'];
    $_SESSION['user_name']        = $user['name'];
    $_SESSION['user_email']       = $user['email'];
    $_SESSION['user_role']        = $user['role_slug'];
    $_SESSION['user_role_name']   = $user['role_name'];
    $_SESSION['user_avatar']      = $user['avatar'] ?? null;
    $_SESSION['business_id']      = $user['business_id'];
    $_SESSION['business_name']    = $user['business_name'] ?? APP_NAME;
    $_SESSION['business_currency']= $user['business_currency'] ?? CURRENCY;
    $_SESSION['permissions']      = json_decode($user['permissions'] ?? '{}', true);
    $_SESSION['last_regenerated'] = time();

    // Update last login
    $db->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([$user['id']]);

    return ['success' => true];
}

function logout(): void {
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

function currentUser(): array {
    return [
        'id'            => $_SESSION['user_id'] ?? 0,
        'name'          => $_SESSION['user_name'] ?? '',
        'email'         => $_SESSION['user_email'] ?? '',
        'role'          => $_SESSION['user_role'] ?? '',
        'role_name'     => $_SESSION['user_role_name'] ?? '',
        'avatar'        => $_SESSION['user_avatar'] ?? null,
        'business_id'   => $_SESSION['business_id'] ?? null,
        'business_name' => $_SESSION['business_name'] ?? '',
        'permissions'   => $_SESSION['permissions'] ?? [],
    ];
}

function currentBusinessId(): ?int {
    return $_SESSION['business_id'] ?? null;
}

function isAdmin(): bool {
    return ($_SESSION['user_role'] ?? '') === 'admin';
}

function hasPermission(string $permission): bool {
    if (isAdmin()) return true;
    $perms = $_SESSION['permissions'] ?? [];
    return !empty($perms['all']) || !empty($perms[$permission]);
}

function requirePermission(string $permission): void {
    requireLogin();
    if (!hasPermission($permission)) {
        http_response_code(403);
        include APP_PATH . '/includes/403.php';
        exit;
    }
}

// CSRF token
function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf(): void {
    // Accept token from POST body, GET param (for link-based actions), or header
    $token = $_POST['csrf_token']
           ?? $_GET['csrf_token']
           ?? $_GET['csrf']
           ?? $_SERVER['HTTP_X_CSRF_TOKEN']
           ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        die('CSRF token mismatch. Please go back and try again.');
    }
}
