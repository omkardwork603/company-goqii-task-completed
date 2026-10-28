<?php

$allowedOrigins = [
    'http://localhost:5173',
    'http://127.0.0.1:5173',
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if ($origin !== '' && !in_array($origin, $allowedOrigins, true)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode([
        'success' => false,
        'message' => 'Origin not allowed.',
    ]);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

if ($origin !== '') {
    header("Access-Control-Allow-Origin: $origin");
    header('Vary: Origin');
}

header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}