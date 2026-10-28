
<?php

// 1. Load configuration files.
require_once __DIR__ . '/../config/cors.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';

// 2. Helper to return JSON responses.
function sendJson(int $status, array $data): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode($data);
    exit;
}

// 3. Read action and HTTP method.
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// 4. Read JSON request body.
$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    $input = [];
}

// ==================================================
// REGISTER
// POST auth.php?action=register
// ==================================================
if ($action === 'register' && $method === 'POST') {
    $name = trim($input['name'] ?? '');
    $email = strtolower(trim($input['email'] ?? ''));
    $password = $input['password'] ?? '';

    if ($name === '' || strlen($name) > 100) {
        sendJson(422, [
            'success' => false,
            'message' => 'Name is required and must be at most 100 characters.'
        ]);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
        sendJson(422, [
            'success' => false,
            'message' => 'Please provide a valid email address.'
        ]);
    }

    if (!is_string($password) || strlen($password) < 8) {
        sendJson(422, [
            'success' => false,
            'message' => 'Password must be at least 8 characters long.'
        ]);
    }

    $check = $conn->prepare(
        'SELECT id FROM users WHERE email = ? LIMIT 1'
    );

    if (!$check) {
        sendJson(500, [
            'success' => false,
            'message' => 'Database query failed.'
        ]);
    }

    $check->bind_param('s', $email);
    $check->execute();
    $check->store_result();

    if ($check->num_rows > 0) {
        $check->close();

        sendJson(409, [
            'success' => false,
            'message' => 'This email is already registered.'
        ]);
    }

    $check->close();

    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    $role = 'user';

    $stmt = $conn->prepare(
        'INSERT INTO users (name, email, password, role)
         VALUES (?, ?, ?, ?)'
    );

    if (!$stmt) {
        sendJson(500, [
            'success' => false,
            'message' => 'Could not prepare registration query.'
        ]);
    }

    $stmt->bind_param('ssss', $name, $email, $hashedPassword, $role);

    if (!$stmt->execute()) {
        $stmt->close();

        sendJson(500, [
            'success' => false,
            'message' => 'Registration failed. Please try again.'
        ]);
    }

    $userId = (int) $conn->insert_id;
    $stmt->close();

    sendJson(201, [
        'success' => true,
        'message' => 'Registration successful. Please log in.',
        'data' => [
            'id' => $userId,
            'name' => $name,
            'email' => $email,
            'role' => $role
        ]
    ]);
}

// ==================================================
// LOGIN
// POST auth.php?action=login
// ==================================================
if ($action === 'login' && $method === 'POST') {
    $email = strtolower(trim($input['email'] ?? ''));
    $password = $input['password'] ?? '';

    if (
        !filter_var($email, FILTER_VALIDATE_EMAIL) ||
        !is_string($password) ||
        $password === ''
    ) {
        sendJson(422, [
            'success' => false,
            'message' => 'Please provide a valid email and password.'
        ]);
    }

    $stmt = $conn->prepare(
        'SELECT id, name, email, password, role
         FROM users
         WHERE email = ?
         LIMIT 1'
    );

    if (!$stmt) {
        sendJson(500, [
            'success' => false,
            'message' => 'Could not prepare login query.'
        ]);
    }

    $stmt->bind_param('s', $email);
    $stmt->execute();

    $result = $stmt->get_result();
    $dbUser = $result->fetch_assoc();

    $stmt->close();

    if (!$dbUser || !password_verify($password, $dbUser['password'])) {
        sendJson(401, [
            'success' => false,
            'message' => 'Invalid email or password.'
        ]);
    }

    if (!session_regenerate_id(true)) {
        sendJson(500, [
            'success' => false,
            'message' => 'Could not start a secure session.'
        ]);
    }

    // Store only safe user information in the session.
    $_SESSION['user'] = [
        'id' => (int) $dbUser['id'],
        'name' => $dbUser['name'],
        'email' => $dbUser['email'],
        'role' => $dbUser['role']
    ];

    $_SESSION['last_activity'] = time();

    // Return the user at data.user for React.
    sendJson(200, [
        'success' => true,
        'message' => 'Login successful.',
        'data' => [
            'user' => $_SESSION['user']
        ],
        'user' => $_SESSION['user']
    ]);
}

// ==================================================
// CHECK SESSION
// GET auth.php?action=me
// ==================================================
if ($action === 'me' && $method === 'GET') {
    if (empty($_SESSION['user']['id'])) {
        sendJson(401, [
            'success' => false,
            'message' => 'Please log in.'
        ]);
    }

    sendJson(200, [
        'success' => true,
        'message' => 'Session is active.',
        'data' => [
            'user' => $_SESSION['user']
        ],
        'user' => $_SESSION['user']
    ]);
}

// ==================================================
// LOGOUT
// POST auth.php?action=logout
// ==================================================
if ($action === 'logout' && $method === 'POST') {
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $cookie = session_get_cookie_params();

        setcookie(session_name(), '', [
            'expires' => time() - 3600,
            'path' => $cookie['path'],
            'domain' => $cookie['domain'],
            'secure' => $cookie['secure'],
            'httponly' => $cookie['httponly'],
            'samesite' => $cookie['samesite'] ?? 'Lax'
        ]);
    }

    session_destroy();

    sendJson(200, [
        'success' => true,
        'message' => 'Logout successful.'
    ]);
}

// ==================================================
// INVALID ACTION
// ==================================================
sendJson(404, [
    'success' => false,
    'message' => 'Action not found or HTTP method not supported.'
]);
