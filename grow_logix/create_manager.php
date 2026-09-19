<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// Database Configuration
$host = "localhost";
$db_name = "grow_logix";
$username = "root";
$password = "";

try {
    $conn = new PDO("mysql:host=" . $host . ";dbname=" . $db_name, $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(["status" => "error", "message" => "Connection failed: " . $e->getMessage()]);
    exit();
}

$data = json_decode(file_get_contents("php://input"));

if (!empty($data->manager_name) && !empty($data->email) && !empty($data->password)) {
    $manager_name = trim($data->manager_name);
    $email = trim($data->email);
    $hashed_password = password_hash($data->password, PASSWORD_BCRYPT);
    $role = "Manager";

    // Check if email already exists
    $checkQuery = "SELECT id FROM users WHERE email = :email LIMIT 1";
    $checkStmt = $conn->prepare($checkQuery);
    $checkStmt->bindParam(":email", $email);
    $checkStmt->execute();

    if ($checkStmt->rowCount() > 0) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Email already exists."]);
        exit();
    }

    // Insert Manager Record
    $query = "INSERT INTO users (manager_name, email, password, role) VALUES (:manager_name, :email, :password, :role)";
    $stmt = $conn->prepare($query);

    $stmt->bindParam(":manager_name", $manager_name);
    $stmt->bindParam(":email", $email);
    $stmt->bindParam(":password", $hashed_password);
    $stmt->bindParam(":role", $role);

    if ($stmt->execute()) {
        http_response_code(201);
        echo json_encode([
            "status" => "success",
            "message" => "Manager account created successfully.",
            "data" => [
                "id" => $conn->lastInsertId(),
                "manager_name" => $manager_name,
                "email" => $email,
                "role" => $role
            ]
        ]);
    } else {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Failed to create manager account."]);
    }
} else {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Incomplete data provided. Manager name, email, and password are required."]);
}
?>