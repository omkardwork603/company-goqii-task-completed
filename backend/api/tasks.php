
<?php

require_once __DIR__ . '/../config/cors.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/authMiddleware.php';

$currentUser = requireAuthenticatedUser();

$method = $_SERVER['REQUEST_METHOD'];
$isAdmin = ($currentUser['role'] === 'admin');
$currentUserId = (int) $currentUser['id'];

header('Content-Type: application/json; charset=utf-8');

/*
|--------------------------------------------------------------------------
| JSON response helper
|--------------------------------------------------------------------------
*/

function respond($statusCode, $data)
{
    http_response_code($statusCode);
    echo json_encode($data);
    exit;
}

/*
|--------------------------------------------------------------------------
| Read JSON request body
|--------------------------------------------------------------------------
*/

function readJsonBody()
{
    $rawBody = file_get_contents('php://input');
    $input = json_decode($rawBody, true);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($input)) {
        respond(400, [
            'success' => false,
            'message' => 'Invalid JSON body',
            'details' => json_last_error_msg()
        ]);
    }

    return $input;
}

/*
|--------------------------------------------------------------------------
| Bind prepared statement parameters safely
|--------------------------------------------------------------------------
*/

function bindParams($stmt, $types, &$params)
{
    if (empty($params)) {
        return true;
    }

    $arguments = [$stmt, $types];

    foreach ($params as $key => &$value) {
        $arguments[] = &$value;
    }

    return call_user_func_array('mysqli_stmt_bind_param', $arguments);
}

/*
|--------------------------------------------------------------------------
| Validate due date
|--------------------------------------------------------------------------
*/

function validDueDate($date)
{
    if ($date === null || $date === '') {
        return true;
    }

    if (
        !is_string($date) ||
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
    ) {
        return false;
    }

    return checkdate(
        (int) substr($date, 5, 2),
        (int) substr($date, 8, 2),
        (int) substr($date, 0, 4)
    );
}

/*
|--------------------------------------------------------------------------
| Shared validation rules
|--------------------------------------------------------------------------
*/

$allowedFields = [
    'title',
    'description',
    'status',
    'priority',
    'due_date'
];

$validStatuses = ['todo', 'in-progress', 'done'];
$validPriorities = ['low', 'medium', 'high'];

/*
|--------------------------------------------------------------------------
| Find a task and enforce ownership
|--------------------------------------------------------------------------
*/

function getTaskForUser($conn, $id, $currentUserId, $isAdmin)
{
    if ($isAdmin) {
        $sql = 'SELECT * FROM tasks WHERE id = ? LIMIT 1';
        $params = [$id];
        $types = 'i';
    } else {
        $sql = 'SELECT * FROM tasks
                WHERE id = ? AND user_id = ?
                LIMIT 1';
        $params = [$id, $currentUserId];
        $types = 'ii';
    }

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        respond(500, [
            'success' => false,
            'message' => 'Database query failed'
        ]);
    }

    bindParams($stmt, $types, $params);

    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);

        respond(500, [
            'success' => false,
            'message' => 'Failed to fetch task'
        ]);
    }

    $result = mysqli_stmt_get_result($stmt);
    $task = mysqli_fetch_assoc($result);

    mysqli_stmt_close($stmt);

    if (!$task) {
        // Do not disclose another user's task existence.
        respond(404, [
            'success' => false,
            'message' => 'Task not found'
        ]);
    }

    return $task;
}

/*
|--------------------------------------------------------------------------
| POST: Create a task
|--------------------------------------------------------------------------
*/

if ($method === 'POST') {
    $input = readJsonBody();

    foreach ($input as $field => $value) {
        if (
            !in_array($field, $allowedFields, true) &&
            !($isAdmin && $field === 'user_id')
        ) {
            respond(422, [
                'success' => false,
                'message' => 'Invalid field: ' . $field
            ]);
        }
    }

    $title = $input['title'] ?? null;
    $description = $input['description'] ?? null;
    $status = $input['status'] ?? 'todo';
    $priority = $input['priority'] ?? 'medium';
    $dueDate = $input['due_date'] ?? null;

    if (
        !is_string($title) ||
        trim($title) === '' ||
        strlen(trim($title)) > 150
    ) {
        respond(422, [
            'success' => false,
            'message' => 'Title must be 1-150 characters'
        ]);
    }

    if (
        $description !== null &&
        (
            !is_string($description) ||
            strlen($description) > 10000
        )
    ) {
        respond(422, [
            'success' => false,
            'message' => 'Description must be text up to 10,000 characters or null'
        ]);
    }

    if (
        !is_string($status) ||
        !in_array($status, $validStatuses, true)
    ) {
        respond(422, [
            'success' => false,
            'message' => 'Invalid status'
        ]);
    }

    if (
        !is_string($priority) ||
        !in_array($priority, $validPriorities, true)
    ) {
        respond(422, [
            'success' => false,
            'message' => 'Invalid priority'
        ]);
    }

    if (!validDueDate($dueDate)) {
        respond(422, [
            'success' => false,
            'message' => 'Due date must be a valid YYYY-MM-DD date or null'
        ]);
    }

    if ($dueDate === '') {
        $dueDate = null;
    }

    // Normal users can only create tasks for themselves.
    $userId = $currentUserId;

    if ($isAdmin && array_key_exists('user_id', $input)) {
        $targetUserId = filter_var(
            $input['user_id'],
            FILTER_VALIDATE_INT
        );

        if ($targetUserId === false || $targetUserId < 1) {
            respond(422, [
                'success' => false,
                'message' => 'A valid user_id is required'
            ]);
        }

        $checkUser = mysqli_prepare(
            $conn,
            'SELECT id FROM users WHERE id = ? LIMIT 1'
        );

        if (!$checkUser) {
            respond(500, [
                'success' => false,
                'message' => 'Failed to prepare user query'
            ]);
        }

        mysqli_stmt_bind_param($checkUser, 'i', $targetUserId);
        mysqli_stmt_execute($checkUser);

        $userResult = mysqli_stmt_get_result($checkUser);
        $targetUser = mysqli_fetch_assoc($userResult);

        mysqli_stmt_close($checkUser);

        if (!$targetUser) {
            respond(404, [
                'success' => false,
                'message' => 'Target user not found'
            ]);
        }

        $userId = (int) $targetUser['id'];
    }

    $title = trim($title);

    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO tasks
            (title, description, status, priority, due_date, user_id)
         VALUES (?, ?, ?, ?, ?, ?)'
    );

    if (!$stmt) {
        respond(500, [
            'success' => false,
            'message' => 'Failed to prepare insert query'
        ]);
    }

    mysqli_stmt_bind_param(
        $stmt,
        'sssssi',
        $title,
        $description,
        $status,
        $priority,
        $dueDate,
        $userId
    );

    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);

        respond(500, [
            'success' => false,
            'message' => 'Failed to create task'
        ]);
    }

    $newId = mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);

    respond(201, [
        'success' => true,
        'message' => 'Task created successfully',
        'id' => $newId
    ]);
}

/*
|--------------------------------------------------------------------------
| GET: Fetch one task or search/filter a paginated list
|--------------------------------------------------------------------------
*/

if ($method === 'GET') {

    // Fetch one task by ID.
    if (isset($_GET['id'])) {
        $id = filter_var($_GET['id'], FILTER_VALIDATE_INT);

        if ($id === false || $id < 1) {
            respond(400, [
                'success' => false,
                'message' => 'Valid task ID is required'
            ]);
        }

        $task = getTaskForUser(
            $conn,
            $id,
            $currentUserId,
            $isAdmin
        );

        respond(200, [
            'success' => true,
            'message' => 'Task fetched successfully',
            'data' => $task
        ]);
    }

    /*
     * Search by title:
     * ?search=meeting
     *
     * Filter by status:
     * ?status=done
     *
     * Combine search and status:
     * ?search=meeting&status=done
     */

    $search = trim($_GET['search'] ?? '');
    $status = $_GET['status'] ?? '';

    if (!is_string($search)) {
        respond(422, [
            'success' => false,
            'message' => 'Search must be text'
        ]);
    }

    if (strlen($search) > 150) {
        respond(422, [
            'success' => false,
            'message' => 'Search cannot exceed 150 characters'
        ]);
    }

    if ($status === '') {
        $status = null;
    }

    if (
        $status !== null &&
        (
            !is_string($status) ||
            !in_array($status, $validStatuses, true)
        )
    ) {
        respond(422, [
            'success' => false,
            'message' => 'Invalid status filter'
        ]);
    }

    // Pagination.
    $page = filter_var(
        $_GET['page'] ?? 1,
        FILTER_VALIDATE_INT
    );

    $perPage = filter_var(
        $_GET['per_page'] ?? 5,
        FILTER_VALIDATE_INT
    );

    if (
        $page === false ||
        $page < 1 ||
        $perPage === false ||
        !in_array($perPage, [5, 10, 20], true)
    ) {
        respond(422, [
            'success' => false,
            'message' => 'Page must be positive and per_page must be 5, 10, or 20'
        ]);
    }

    $offset = (int) (($page - 1) * $perPage);

    /*
     * Build shared conditions for both the count query
     * and the list query.
     */

    $conditions = [];
    $params = [];
    $types = '';

    // Restrict normal users to their own tasks.
    if (!$isAdmin) {
        $conditions[] = 'user_id = ?';
        $params[] = $currentUserId;
        $types .= 'i';
    }

    // Optional status filter.
    if ($status !== null) {
        $conditions[] = 'status = ?';
        $params[] = $status;
        $types .= 's';
    }

    // Optional title search.
    if ($search !== '') {
        $conditions[] = 'title LIKE ?';
        $params[] = '%' . $search . '%';
        $types .= 's';
    }

    $where = '';

    if (!empty($conditions)) {
        $where = ' WHERE ' . implode(' AND ', $conditions);
    }

    // Count all results matching the same filters.
    $countStmt = mysqli_prepare(
        $conn,
        'SELECT COUNT(*) AS total FROM tasks' . $where
    );

    if (!$countStmt) {
        respond(500, [
            'success' => false,
            'message' => 'Database count query failed'
        ]);
    }

    if (!bindParams($countStmt, $types, $params)) {
        mysqli_stmt_close($countStmt);

        respond(500, [
            'success' => false,
            'message' => 'Failed to bind count query parameters'
        ]);
    }

    if (!mysqli_stmt_execute($countStmt)) {
        mysqli_stmt_close($countStmt);

        respond(500, [
            'success' => false,
            'message' => 'Failed to count tasks'
        ]);
    }

    $countResult = mysqli_stmt_get_result($countStmt);
    $countRow = mysqli_fetch_assoc($countResult);
    $total = (int) ($countRow['total'] ?? 0);

    mysqli_stmt_close($countStmt);

    // Fetch the matching tasks for this page.
    $sql = 'SELECT * FROM tasks'
         . $where
         . " ORDER BY id DESC LIMIT $perPage OFFSET $offset";

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        respond(500, [
            'success' => false,
            'message' => 'Database query failed'
        ]);
    }

    if (!bindParams($stmt, $types, $params)) {
        mysqli_stmt_close($stmt);

        respond(500, [
            'success' => false,
            'message' => 'Failed to bind task query parameters'
        ]);
    }

    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);

        respond(500, [
            'success' => false,
            'message' => 'Failed to fetch tasks'
        ]);
    }

    $result = mysqli_stmt_get_result($stmt);
    $tasks = [];

    while ($row = mysqli_fetch_assoc($result)) {
        $tasks[] = $row;
    }

    mysqli_stmt_close($stmt);

    $totalPages = (int) ceil($total / $perPage);

    respond(200, [
        'success' => true,
        'message' => 'Tasks fetched successfully',
        'data' => $tasks,
        'pagination' => [
            'page' => (int) $page,
            'per_page' => (int) $perPage,
            'total' => $total,
            'total_pages' => $totalPages
        ]
    ]);
}

/*
|--------------------------------------------------------------------------
| PUT / PATCH: Update a task
|--------------------------------------------------------------------------
*/

if ($method === 'PUT' || $method === 'PATCH') {
    $id = filter_var(
        $_GET['id'] ?? null,
        FILTER_VALIDATE_INT
    );

    if ($id === false || $id === null || $id < 1) {
        respond(400, [
            'success' => false,
            'message' => 'Valid task ID is required'
        ]);
    }

    $input = readJsonBody();

    foreach ($input as $field => $value) {
        if (!in_array($field, $allowedFields, true)) {
            respond(422, [
                'success' => false,
                'message' => 'Invalid field: ' . $field
            ]);
        }
    }

    if ($method === 'PUT') {
        foreach ($allowedFields as $field) {
            if (!array_key_exists($field, $input)) {
                respond(422, [
                    'success' => false,
                    'message' => "$field is required for PUT"
                ]);
            }
        }
    }

    $current = getTaskForUser(
        $conn,
        $id,
        $currentUserId,
        $isAdmin
    );

    $title = $input['title'] ?? $current['title'];
    $description = array_key_exists('description', $input)
        ? $input['description']
        : $current['description'];

    $status = $input['status'] ?? $current['status'];
    $priority = $input['priority'] ?? $current['priority'];

    $dueDate = array_key_exists('due_date', $input)
        ? $input['due_date']
        : $current['due_date'];

    if (
        !is_string($title) ||
        trim($title) === '' ||
        strlen(trim($title)) > 150
    ) {
        respond(422, [
            'success' => false,
            'message' => 'Title must be 1-150 characters'
        ]);
    }

    if (
        $description !== null &&
        (
            !is_string($description) ||
            strlen($description) > 10000
        )
    ) {
        respond(422, [
            'success' => false,
            'message' => 'Description must be text up to 10,000 characters or null'
        ]);
    }

    if (
        !is_string($status) ||
        !in_array($status, $validStatuses, true)
    ) {
        respond(422, [
            'success' => false,
            'message' => 'Invalid status'
        ]);
    }

    if (
        !is_string($priority) ||
        !in_array($priority, $validPriorities, true)
    ) {
        respond(422, [
            'success' => false,
            'message' => 'Invalid priority'
        ]);
    }

    if (!validDueDate($dueDate)) {
        respond(422, [
            'success' => false,
            'message' => 'Due date must be a valid YYYY-MM-DD date or null'
        ]);
    }

    if ($dueDate === '') {
        $dueDate = null;
    }

    $title = trim($title);

    // Enforce ownership in the UPDATE query as well.
    if ($isAdmin) {
        $sql = 'UPDATE tasks
                SET title = ?, description = ?, status = ?,
                    priority = ?, due_date = ?
                WHERE id = ?';

        $params = [
            $title, $description, $status,
            $priority, $dueDate, $id
        ];

        $types = 'sssssi';
    } else {
        $sql = 'UPDATE tasks
                SET title = ?, description = ?, status = ?,
                    priority = ?, due_date = ?
                WHERE id = ? AND user_id = ?';

        $params = [
            $title, $description, $status,
            $priority, $dueDate, $id, $currentUserId
        ];

        $types = 'sssssii';
    }

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        respond(500, [
            'success' => false,
            'message' => 'Failed to prepare update query'
        ]);
    }

    if (!bindParams($stmt, $types, $params)) {
        mysqli_stmt_close($stmt);

        respond(500, [
            'success' => false,
            'message' => 'Failed to bind update parameters'
        ]);
    }

    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);

        respond(500, [
            'success' => false,
            'message' => 'Failed to update task'
        ]);
    }

    mysqli_stmt_close($stmt);

    respond(200, [
        'success' => true,
        'message' => 'Task updated successfully',
        'id' => (int) $id
    ]);
}

/*
|--------------------------------------------------------------------------
| DELETE: Delete a task
|--------------------------------------------------------------------------
*/

if ($method === 'DELETE') {
    $id = filter_var(
        $_GET['id'] ?? null,
        FILTER_VALIDATE_INT
    );

    if ($id === false || $id === null || $id < 1) {
        respond(400, [
            'success' => false,
            'message' => 'Valid task ID is required'
        ]);
    }

    // Verify existence and access permissions first.
    getTaskForUser(
        $conn,
        $id,
        $currentUserId,
        $isAdmin
    );

    if ($isAdmin) {
        $sql = 'DELETE FROM tasks WHERE id = ?';
        $params = [$id];
        $types = 'i';
    } else {
        $sql = 'DELETE FROM tasks WHERE id = ? AND user_id = ?';
        $params = [$id, $currentUserId];
        $types = 'ii';
    }

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        respond(500, [
            'success' => false,
            'message' => 'Failed to prepare delete query'
        ]);
    }

    if (!bindParams($stmt, $types, $params)) {
        mysqli_stmt_close($stmt);

        respond(500, [
            'success' => false,
            'message' => 'Failed to bind delete parameters'
        ]);
    }

    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);

        respond(500, [
            'success' => false,
            'message' => 'Failed to delete task'
        ]);
    }

    $deleted = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);

    if ($deleted === 0) {
        respond(404, [
            'success' => false,
            'message' => 'Task not found'
        ]);
    }

    respond(200, [
        'success' => true,
        'message' => 'Task deleted successfully',
        'id' => (int) $id
    ]);
}

/*
|--------------------------------------------------------------------------
| Unsupported HTTP method
|--------------------------------------------------------------------------
*/

header('Allow: GET, POST, PUT, PATCH, DELETE');

respond(405, [
    'success' => false,
    'message' => 'Method not allowed'
]);
