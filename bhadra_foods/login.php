<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST");

$conn = new mysqli("localhost", "root", "", "bhadra_foods");

if ($conn->connect_error) {
    echo json_encode(["status" => "error", "message" => "Database connection failed"]);
    exit();
}

$data = json_decode(file_get_contents("php://input"), true);

$username = $data['username'] ?? '';
$password = $data['password'] ?? '';
$role     = $data['role'] ?? '';

if (empty($username) || empty($password) || empty($role)) {
    echo json_encode(["status" => "error", "message" => "Missing required fields"]);
    exit();
}

// Ensure you query the column storing the Employee ID (e.g., emp_id or id)
$stmt = $conn->prepare("SELECT id AS emp_id, name, email, password FROM users WHERE (email = ? OR name = ?) LIMIT 1");
$stmt->bind_param("ss", $username, $username);
$stmt->execute();
$result = $stmt->get_result();

if ($user = $result->fetch_assoc()) {
    // Note: Use password_verify($password, $user['password']) in production
    if ($password === $user['password'] || password_verify($password, $user['password'])) {
        echo json_encode([
            "status" => "success",
            "message" => "Login successful",
            "user" => [
                "emp_id" => (string)$user['emp_id'],
                "name" => $user['name'],
                "email" => $user['email'],
                "role" => $role
            ]
        ]);
    } else {
        echo json_encode(["status" => "error", "message" => "Invalid credentials"]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "User not found"]);
}

$conn->close();
?>