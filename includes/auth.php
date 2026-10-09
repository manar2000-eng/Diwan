<?php
/**
 * Logic Layer - authentication, roles and CSRF.
 * Spec: SPEC-000-shared.md R2.
 *
 * The ONLY file in the project that calls session_start().
 */

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/**
 * Logged in user as stored at login time, or null.
 *
 * @return array{id:int,name:string,role:string,hub_id:int|null}|null
 */
function currentUser(): ?array
{
    return $_SESSION['user'] ?? null;
}

/** True when a user session exists (no redirect). */
function isLoggedIn(): bool
{
    return currentUser() !== null;
}

// ---------------------------------------------------------------------------
// CSRF - POST token for every state change
// ---------------------------------------------------------------------------

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/** Ready to paste inside any POST form. */
function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrfToken()) . '">';
}

/** Constant-time comparison against the session token. */
function csrfCheck(): bool
{
    $sent = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

    return is_string($sent) && $sent !== '' && hash_equals(csrfToken(), $sent);
}

// ---------------------------------------------------------------------------
// Guards - first two lines of every dashboard page
// ---------------------------------------------------------------------------

/**
 * @return array{id:int,name:string,role:string,hub_id:int|null}
 */
function requireLogin(): array
{
    $user = currentUser();

    if ($user === null) {
        setFlash('danger', 'الرجاء تسجيل الدخول أولاً');
        redirect(url('public/login.php'));
    }

    return $user;
}

/**
 * requireLogin() + role whitelist. Wrong role terminates with HTTP 403.
 *
 * @param string[] $roles
 * @return array{id:int,name:string,role:string,hub_id:int|null}
 */
function requireRole(array $roles): array
{
    $user = requireLogin();

    if (!in_array($user['role'], $roles, true)) {
        forbid('ليس لديك صلاحية للوصول إلى هذه الصفحة');
    }

    return $user;
}

/** Terminal HTTP 403 page. */
function forbid(string $message): void
{
    http_response_code(403);

    // The layout partial is reused here so the RTL shell is written once.
    require_once __DIR__ . '/layout.php';
    pageStart('غير مصرح');
    echo '<div class="alert alert-danger mb-0">' . e($message) . '</div>';
    pageEnd();
    exit;
}

/** Drop the session completely (logout.php is a 3 line presentation file). */
function logoutUser(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }

    session_destroy();
}