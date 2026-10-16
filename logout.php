<?php

require __DIR__ . '/auth/session.php';

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        '',
        [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax'
        ]
    );
}

session_destroy();

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Location: /student-login.html?logged_out=1', true, 303);
exit;