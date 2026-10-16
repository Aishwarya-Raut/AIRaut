<?php

/*
|--------------------------------------------------------------------------
| OUTPUT BUFFERING
|--------------------------------------------------------------------------
|
| Prevent accidental whitespace/BOM/output from blocking redirects.
|
*/

ob_start();


/*
|--------------------------------------------------------------------------
| SECURE SESSION
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/session.php';


/*
|--------------------------------------------------------------------------
| DATABASE
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../config/database.php';


/*
|--------------------------------------------------------------------------
| REDIRECT HELPER
|--------------------------------------------------------------------------
*/

function login_redirect(string $url): never
{
    header(
        'Location: ' . $url,
        true,
        303
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| ONLY ACCEPT POST LOGIN REQUESTS
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    login_redirect(
        '/student-login.html'
    );
}


/*
|--------------------------------------------------------------------------
| CSRF PROTECTION
|--------------------------------------------------------------------------
*/

require_csrf_token();


/*
|--------------------------------------------------------------------------
| READ FORM DATA
|--------------------------------------------------------------------------
*/

$email =
    trim(
        $_POST['email'] ?? ''
    );

$password =
    $_POST['password'] ?? '';

$requestedRole =
    strtolower(
        trim(
            $_POST['role'] ?? ''
        )
    );


/*
|--------------------------------------------------------------------------
| ALLOWED PLATFORM ROLES
|--------------------------------------------------------------------------
*/

$allowedRoles = [
    'student',
    'faculty',
    'institution',
    'admin'
];


/*
|--------------------------------------------------------------------------
| BASIC VALIDATION
|--------------------------------------------------------------------------
*/

if (
    $email === ''
    || $password === ''
) {

    login_redirect(
        '/student-login.html?error=empty'
        . '&role='
        . urlencode($requestedRole)
    );
}


/*
|--------------------------------------------------------------------------
| ROLE SELECTION REQUIRED
|--------------------------------------------------------------------------
*/

if (
    !in_array(
        $requestedRole,
        $allowedRoles,
        true
    )
) {

    login_redirect(
        '/student-login.html?error=role_required'
    );
}


/*
|--------------------------------------------------------------------------
| FIND USER
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare(
    "SELECT
        id,
        name,
        email,
        password_hash,
        role,
        status
     FROM users
     WHERE email = ?
     LIMIT 1"
);


if (!$stmt) {

    ob_end_clean();

    http_response_code(500);

    exit(
        'Something went wrong while processing your login.'
    );
}


$stmt->bind_param(
    's',
    $email
);


if (!$stmt->execute()) {

    $stmt->close();

    ob_end_clean();

    http_response_code(500);

    exit(
        'Unable to process your login.'
    );
}


$result =
    $stmt->get_result();


$user =
    $result->fetch_assoc();


$stmt->close();


/*
|--------------------------------------------------------------------------
| USER NOT FOUND
|--------------------------------------------------------------------------
*/

if (!$user) {

    login_redirect(
        '/student-login.html?error=invalid'
        . '&role='
        . urlencode($requestedRole)
    );
}


/*
|--------------------------------------------------------------------------
| ACCOUNT STATUS
|--------------------------------------------------------------------------
*/

if (
    $user['status'] !== 'active'
) {

    login_redirect(
        '/student-login.html?error=inactive'
        . '&role='
        . urlencode($requestedRole)
    );
}


/*
|--------------------------------------------------------------------------
| PASSWORD VERIFICATION
|--------------------------------------------------------------------------
*/

if (
    !password_verify(
        $password,
        $user['password_hash']
    )
) {

    login_redirect(
        '/student-login.html?error=invalid'
        . '&role='
        . urlencode($requestedRole)
    );
}


/*
|--------------------------------------------------------------------------
| ROLE MATCH
|--------------------------------------------------------------------------
|
| The selected pathway MUST match the user's actual
| database role.
|
*/

if (
    $user['role'] !== $requestedRole
) {

    login_redirect(
        '/student-login.html?error=role_mismatch'
        . '&role='
        . urlencode($requestedRole)
    );
}


/*
|--------------------------------------------------------------------------
| SUCCESSFUL LOGIN
|--------------------------------------------------------------------------
*/

session_regenerate_id(true);


$_SESSION['user_id'] =
    (int) $user['id'];

$_SESSION['name'] =
    $user['name'];

$_SESSION['email'] =
    $user['email'];

$_SESSION['role'] =
    $user['role'];


/*
|--------------------------------------------------------------------------
| ROTATE CSRF TOKEN AFTER LOGIN
|--------------------------------------------------------------------------
*/

$_SESSION['csrf_token'] =
    bin2hex(
        random_bytes(32)
    );


/*
|--------------------------------------------------------------------------
| ROLE-BASED REDIRECT
|--------------------------------------------------------------------------
*/

switch ($user['role']) {


    case 'student':

        login_redirect(
            '/student-dashboard.php'
        );


    case 'faculty':

        login_redirect(
            '/faculty-dashboard.php'
        );


    case 'institution':

        login_redirect(
            '/institution-dashboard.php'
        );


    case 'admin':

        login_redirect(
            '/admin-dashboard.php'
        );


    default:

        session_unset();

        session_destroy();

        login_redirect(
            '/student-login.html?error=role'
        );
}