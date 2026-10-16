<?php

$isHttps =
    !empty($_SERVER['HTTPS'])
    && $_SERVER['HTTPS'] !== 'off';

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax'
]);

session_start();


/*
|--------------------------------------------------------------------------
| LOGIN / ROLE ACCESS
|--------------------------------------------------------------------------
*/

function require_login(): void
{
    if (
        !isset($_SESSION['user_id'])
        || !isset($_SESSION['role'])
    ) {
        header('Location: /student-login.html');
        exit;
    }
}


function require_role(string $required_role): void
{
    require_login();

    if (
        !hash_equals(
            $required_role,
            (string) ($_SESSION['role'] ?? '')
        )
    ) {
        http_response_code(403);
        exit('Access denied.');
    }
}


function current_user_id(): ?int
{
    return isset($_SESSION['user_id'])
        ? (int) $_SESSION['user_id']
        : null;
}


function current_user_role(): ?string
{
    return $_SESSION['role'] ?? null;
}


/*
|--------------------------------------------------------------------------
| CSRF PROTECTION
|--------------------------------------------------------------------------
*/

function csrf_token(): string
{
    if (
        empty($_SESSION['csrf_token'])
        || !is_string($_SESSION['csrf_token'])
    ) {
        $_SESSION['csrf_token'] =
            bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}


function require_csrf_token(): void
{
    $sessionToken =
        $_SESSION['csrf_token'] ?? '';

    $submittedToken =
        $_POST['csrf_token'] ?? '';

    if (
        !is_string($sessionToken)
        || $sessionToken === ''
        || !is_string($submittedToken)
        || $submittedToken === ''
        || !hash_equals(
            $sessionToken,
            $submittedToken
        )
    ) {
        http_response_code(403);
        exit('Invalid security token.');
    }
}