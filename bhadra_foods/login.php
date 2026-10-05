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

// Check email, mobile, or emp_id along with the selected role
$stmt = $conn->prepare("SELECT emp_id, name, email, mobile, password, role FROM users WHERE (email = ? OR mobile = ? OR emp_id = ?) AND role = ? LIMIT 1");
$stmt->bind_param("ssss", $username, $username, $username, $role);
$stmt->execute();
$result = $stmt->get_result();

if ($user = $result->fetch_assoc()) {
    // Check plaintext or hashed password
    if ($password === $user['password'] || password_verify($password, $user['password'])) {
        echo json_encode([
            "status" => "success",
            "message" => "Login successful",
            "user" => [
                "emp_id" => (string)$user['emp_id'],
                "name"   => $user['name'],
                "email"  => $user['email'],
                "mobile" => $user['mobile'] ?? '',
                "role"   => $user['role']
            ]
        ]);
    } else {
        echo json_encode(["status" => "error", "message" => "Invalid credentials"]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "User not found or role mismatch"]);
}

$conn->close();
?>