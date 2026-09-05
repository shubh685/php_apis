<?php
// login.php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Content-Type: application/json");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'data.php';

$database = new Database();
$db = $database->getConnection();

if (!$db) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Database connection failed."
    ]);
    exit();
}

// Receive JSON raw post input
$data = json_decode(file_get_contents("php://input"));

if (!empty($data->role) && !empty($data->username) && !empty($data->password)) {
    $role = trim($data->role);
    $username = trim($data->username);
    $password = trim($data->password);

    try {
        // Query users table matching role and (email OR mobile)
        $query = "SELECT id, emp_id, name, email, mobile, password, role 
                  FROM users 
                  WHERE role = :role AND (email = :username OR mobile = :username) 
                  LIMIT 1";

        $stmt = $db->prepare($query);
        $stmt->bindParam(":role", $role);
        $stmt->bindParam(":username", $username);
        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            $row = $stmt->fetch();

            // Password Verification (Supports hashed or plaintext passwords)
            if (password_verify($password, $row['password']) || $password === $row['password']) {
                http_response_code(200);
                echo json_encode([
                    "status" => "success",
                    "message" => "Login successful",
                    "user" => [
                        "id" => $row['id'],
                        "emp_id" => $row['emp_id'] ?? ("EMP-" . $row['id']), // Returns emp_id for Salesman / Super Stockiest roles
                        "name" => $row['name'],
                        "email" => $row['email'],
                        "mobile" => $row['mobile'],
                        "role" => $row['role']
                    ]
                ]);
            } else {
                http_response_code(401);
                echo json_encode([
                    "status" => "error",
                    "message" => "Invalid credentials. Password incorrect."
                ]);
            }
        } else {
            http_response_code(404);
            echo json_encode([
                "status" => "error",
                "message" => "No account found matching the given role and credentials."
            ]);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            "status" => "error",
            "message" => "Query Error: " . $e->getMessage()
        ]);
    }
} else {
    http_response_code(400);
    echo json_encode([
        "status" => "error",
        "message" => "Incomplete data provided. Role, username/mobile, and password are required."
    ]);
}
?>