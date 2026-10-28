
<?php

/**
 * Return the authenticated user or stop with HTTP 401.
 * session.php must be loaded before calling this function.
 */
function requireAuthenticatedUser(): array
{
    if (
        empty($_SESSION['user']) ||
        empty($_SESSION['user']['id']) ||
        !isset($_SESSION['user']['role'])
    ) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');

        echo json_encode([
            'success' => false,
            'message' => 'Unauthenticated. Please log in.'
        ]);

        exit;
    }

    return $_SESSION['user'];
}

/**
 * Allow only admins when an admin-only operation is required.
 */
function requireAdmin(array $user): void
{
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');

        echo json_encode([
            'success' => false,
            'message' => 'Forbidden. Admin access required.'
        ]);

        exit;
    }
}
