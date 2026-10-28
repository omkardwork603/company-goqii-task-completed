
<?php

header("Content-Type: application/json; charset=UTF-8");

require_once "../config/db.php";

$method = $_SERVER["REQUEST_METHOD"];

// Send a JSON response and stop execution
function respond($statusCode, $data)
{
    http_response_code($statusCode);
    echo json_encode($data);
    exit;
}

// Read and validate JSON request body
function readJsonBody()
{
    $rawBody = file_get_contents("php://input");
    $input = json_decode($rawBody, true);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($input)) {
        respond(400, [
            "success" => false,
            "message" => "Invalid JSON body",
            "details" => json_last_error_msg()
        ]);
    }

    return $input;
}

// Validate a date in YYYY-MM-DD format, or null
function validDueDate($date)
{
    if ($date === null) {
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

// Editable fields
$allowedFields = [
    "title",
    "description",
    "status",
    "priority",
    "due_date"
];

$validStatuses = ["todo", "in-progress", "done"];
$validPriorities = ["low", "medium", "high"];


/*
|--------------------------------------------------------------------------
| POST: Create a task
|--------------------------------------------------------------------------
*/

if ($method === "POST") {

    $input = readJsonBody();

    foreach ($input as $field => $value) {
        if (!in_array($field, $allowedFields, true)) {
            respond(422, [
                "success" => false,
                "message" => "Invalid field: " . $field
            ]);
        }
    }

    $title = $input["title"] ?? null;
    $description = $input["description"] ?? null;
    $status = $input["status"] ?? "todo";
    $priority = $input["priority"] ?? "medium";
    $due_date = $input["due_date"] ?? null;

    if (
        !is_string($title) ||
        trim($title) === "" ||
        strlen(trim($title)) > 150
    ) {
        respond(422, [
            "success" => false,
            "message" => "Title must be 1-150 characters"
        ]);
    }

    if ($description !== null && !is_string($description)) {
        respond(422, [
            "success" => false,
            "message" => "Description must be text or null"
        ]);
    }

    if (!is_string($status) ||
        !in_array($status, $validStatuses, true)) {
        respond(422, [
            "success" => false,
            "message" => "Invalid status"
        ]);
    }

    if (!is_string($priority) ||
        !in_array($priority, $validPriorities, true)) {
        respond(422, [
            "success" => false,
            "message" => "Invalid priority"
        ]);
    }

    if (!validDueDate($due_date)) {
        respond(422, [
            "success" => false,
            "message" => "Due date must be a valid YYYY-MM-DD date or null"
        ]);
    }

    // Development default; use the authenticated user's ID in production.
    $user_id = 1;
    $title = trim($title);

    $sql = "INSERT INTO tasks
            (title, description, status, priority, due_date, user_id)
            VALUES (?, ?, ?, ?, ?, ?)";

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        respond(500, [
            "success" => false,
            "message" => "Failed to prepare insert query"
        ]);
    }

    mysqli_stmt_bind_param(
        $stmt,
        "sssssi",
        $title,
        $description,
        $status,
        $priority,
        $due_date,
        $user_id
    );

    if (mysqli_stmt_execute($stmt)) {
        $newId = mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);

        respond(201, [
            "success" => true,
            "message" => "Task created successfully",
            "id" => $newId
        ]);
    }

    mysqli_stmt_close($stmt);

    respond(500, [
        "success" => false,
        "message" => "Failed to create task"
    ]);
}


/*
|--------------------------------------------------------------------------
| GET: Fetch one task or a paginated list of tasks
|--------------------------------------------------------------------------
*/

if ($method === "GET") {

    // GET a single task: ?id=1
    if (isset($_GET["id"])) {

        $id = filter_var($_GET["id"], FILTER_VALIDATE_INT);

        if ($id === false || $id < 1) {
            respond(400, [
                "success" => false,
                "message" => "Valid task ID is required"
            ]);
        }

        $stmt = mysqli_prepare(
            $conn,
            "SELECT * FROM tasks WHERE id = ?"
        );

        if (!$stmt) {
            respond(500, [
                "success" => false,
                "message" => "Database query failed"
            ]);
        }

        mysqli_stmt_bind_param($stmt, "i", $id);
        mysqli_stmt_execute($stmt);

        $result = mysqli_stmt_get_result($stmt);
        $task = mysqli_fetch_assoc($result);

        mysqli_stmt_close($stmt);

        if (!$task) {
            respond(404, [
                "success" => false,
                "message" => "Task not found"
            ]);
        }

        respond(200, [
            "success" => true,
            "message" => "Task fetched successfully",
            "data" => $task
        ]);
    }

    // Optional status filter: ?status=todo
    $status = $_GET["status"] ?? null;

    if ($status !== null &&
        !in_array($status, $validStatuses, true)) {
        respond(422, [
            "success" => false,
            "message" => "Invalid status filter"
        ]);
    }

    // Pagination: ?page=1&per_page=10
    $page = filter_var(
        $_GET["page"] ?? 1,
        FILTER_VALIDATE_INT
    );

    $perPage = filter_var(
        $_GET["per_page"] ?? 10,
        FILTER_VALIDATE_INT
    );

    if (
        $page === false || $page < 1 ||
        $perPage === false || $perPage < 1 || $perPage > 100
    ) {
        respond(422, [
            "success" => false,
            "message" => "Page must be positive and per_page must be 1-100"
        ]);
    }

    $offset = ($page - 1) * $perPage;

    // Safe to interpolate these because they are validated integers.
    $limit = (int) $perPage;
    $offset = (int) $offset;

    if ($status !== null) {
        $countStmt = mysqli_prepare(
            $conn,
            "SELECT COUNT(*) AS total FROM tasks WHERE status = ?"
        );

        mysqli_stmt_bind_param($countStmt, "s", $status);
        mysqli_stmt_execute($countStmt);

        $countResult = mysqli_stmt_get_result($countStmt);
        $total = (int) mysqli_fetch_assoc($countResult)["total"];

        mysqli_stmt_close($countStmt);

        $sql = "SELECT * FROM tasks
                WHERE status = ?
                ORDER BY id DESC
                LIMIT $limit OFFSET $offset";

        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "s", $status);
    } else {
        $countResult = mysqli_query(
            $conn,
            "SELECT COUNT(*) AS total FROM tasks"
        );

        if (!$countResult) {
            respond(500, [
                "success" => false,
                "message" => "Database query failed"
            ]);
        }

        $total = (int) mysqli_fetch_assoc($countResult)["total"];

        $sql = "SELECT * FROM tasks
                ORDER BY id DESC
                LIMIT $limit OFFSET $offset";

        $stmt = mysqli_prepare($conn, $sql);
    }

    if (!$stmt) {
        respond(500, [
            "success" => false,
            "message" => "Database query failed"
        ]);
    }

    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);
    $tasks = [];

    while ($row = mysqli_fetch_assoc($result)) {
        $tasks[] = $row;
    }

    mysqli_stmt_close($stmt);

    respond(200, [
        "success" => true,
        "message" => "Tasks fetched successfully",
        "data" => $tasks,
        "pagination" => [
            "page" => (int) $page,
            "per_page" => (int) $perPage,
            "total" => $total,
            "total_pages" => (int) ceil($total / $perPage)
        ]
    ]);
}


/*
|--------------------------------------------------------------------------
| PUT / PATCH: Update a task
|--------------------------------------------------------------------------
*/

if ($method === "PUT" || $method === "PATCH") {

    $id = filter_var(
        $_GET["id"] ?? null,
        FILTER_VALIDATE_INT
    );

    if ($id === false || $id === null || $id < 1) {
        respond(400, [
            "success" => false,
            "message" => "Valid task ID is required"
        ]);
    }

    $input = readJsonBody();

    foreach ($input as $field => $value) {
        if (!in_array($field, $allowedFields, true)) {
            respond(422, [
                "success" => false,
                "message" => "Invalid field: " . $field
            ]);
        }
    }

    if ($method === "PUT") {
        foreach ($allowedFields as $field) {
            if (!array_key_exists($field, $input)) {
                respond(422, [
                    "success" => false,
                    "message" => "$field is required for PUT"
                ]);
            }
        }
    }

    $check = mysqli_prepare(
        $conn,
        "SELECT title, description, status, priority, due_date
         FROM tasks WHERE id = ?"
    );

    if (!$check) {
        respond(500, [
            "success" => false,
            "message" => "Database query failed"
        ]);
    }

    mysqli_stmt_bind_param($check, "i", $id);
    mysqli_stmt_execute($check);

    $result = mysqli_stmt_get_result($check);
    $current = mysqli_fetch_assoc($result);

    mysqli_stmt_close($check);

    if (!$current) {
        respond(404, [
            "success" => false,
            "message" => "Task not found"
        ]);
    }

    // PATCH preserves fields omitted from the request.
    $title = array_key_exists("title", $input)
        ? $input["title"] : $current["title"];

    $description = array_key_exists("description", $input)
        ? $input["description"] : $current["description"];

    $status = array_key_exists("status", $input)
        ? $input["status"] : $current["status"];

    $priority = array_key_exists("priority", $input)
        ? $input["priority"] : $current["priority"];

    $due_date = array_key_exists("due_date", $input)
        ? $input["due_date"] : $current["due_date"];

    if (
        !is_string($title) ||
        trim($title) === "" ||
        strlen(trim($title)) > 150
    ) {
        respond(422, [
            "success" => false,
            "message" => "Title must be 1-150 characters"
        ]);
    }

    if ($description !== null && !is_string($description)) {
        respond(422, [
            "success" => false,
            "message" => "Description must be text or null"
        ]);
    }

    if (!is_string($status) ||
        !in_array($status, $validStatuses, true)) {
        respond(422, [
            "success" => false,
            "message" => "Invalid status"
        ]);
    }

    if (!is_string($priority) ||
        !in_array($priority, $validPriorities, true)) {
        respond(422, [
            "success" => false,
            "message" => "Invalid priority"
        ]);
    }

    if (!validDueDate($due_date)) {
        respond(422, [
            "success" => false,
            "message" => "Due date must be a valid YYYY-MM-DD date or null"
        ]);
    }

    $title = trim($title);

    $sql = "UPDATE tasks
            SET title = ?, description = ?, status = ?,
                priority = ?, due_date = ?
            WHERE id = ?";

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        respond(500, [
            "success" => false,
            "message" => "Failed to prepare update query"
        ]);
    }

    mysqli_stmt_bind_param(
        $stmt,
        "sssssi",
        $title,
        $description,
        $status,
        $priority,
        $due_date,
        $id
    );

    if (mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);

        respond(200, [
            "success" => true,
            "message" => "Task updated successfully",
            "id" => $id
        ]);
    }

    mysqli_stmt_close($stmt);

    respond(500, [
        "success" => false,
        "message" => "Failed to update task"
    ]);
}


/*
|--------------------------------------------------------------------------
| DELETE: Delete a task
|--------------------------------------------------------------------------
*/

if ($method === "DELETE") {

    $id = filter_var(
        $_GET["id"] ?? null,
        FILTER_VALIDATE_INT
    );

    if ($id === false || $id === null || $id < 1) {
        respond(400, [
            "success" => false,
            "message" => "Valid task ID is required"
        ]);
    }

    $stmt = mysqli_prepare(
        $conn,
        "DELETE FROM tasks WHERE id = ?"
    );

    if (!$stmt) {
        respond(500, [
            "success" => false,
            "message" => "Failed to prepare delete query"
        ]);
    }

    mysqli_stmt_bind_param($stmt, "i", $id);

    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);

        respond(500, [
            "success" => false,
            "message" => "Failed to delete task"
        ]);
    }

    $deleted = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);

    if ($deleted === 0) {
        respond(404, [
            "success" => false,
            "message" => "Task not found"
        ]);
    }

    respond(200, [
        "success" => true,
        "message" => "Task deleted successfully",
        "id" => $id
    ]);
}


// Unsupported HTTP method
header("Allow: GET, POST, PUT, PATCH, DELETE");

respond(405, [
    "success" => false,
    "message" => "Method not allowed"
]);
