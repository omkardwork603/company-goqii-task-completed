<?php

$host = "localhost";
$username = "root";
$password = "";
$database = "task-manager-backend";

$conn = mysqli_connect(
    $host,
    $username,
    $password,
    $database
);

if (!$conn) {
    http_response_code(500);
    die("Database connection failed");
}

mysqli_set_charset($conn, "utf8mb4");

?>