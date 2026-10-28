
<?php

// Use strict session handling.
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');

// Configure the session cookie.
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/Comp/',
    'secure' => false, // Local HTTP development only
    'httponly' => true,
    'samesite' => 'Lax',
]);

// Start the session only if it has not started already.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Expire inactive sessions after 30 minutes.
$timeout = 1800;

if (isset($_SESSION['last_activity'])) {
    if (time() - $_SESSION['last_activity'] > $timeout) {
        $_SESSION = [];
        session_regenerate_id(true);
        session_destroy();

        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');

        echo json_encode([
            'success' => false,
            'message' => 'Session expired. Please log in again.'
        ]);

        exit;
    }
}

$_SESSION['last_activity'] = time();
