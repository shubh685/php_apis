<?php
// register.php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// Handle CORS preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'data.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "message" => "Method not allowed. Use POST request."
    ]);
    exit();
}

// Fallback logic: check $_POST first, then read standard stream if empty
$data = $_POST;
if (empty($data)) {
    $rawInput = file_get_contents('php://input');
    $decoded = json_decode($rawInput, true);
    if (is_array($decoded)) {
        $data = $decoded;
    }
}

// Clean input parameters
$name = isset($data['name']) ? trim($data['name']) : '';
$email = isset($data['email']) ? trim($data['email']) : '';
$mobile = isset($data['mobile']) ? trim($data['mobile']) : '';
$password = isset($data['password']) ? $data['password'] : '';
$role = isset($data['role']) ? trim($data['role']) : 'Admin';
$emp_id = isset($data['emp_id']) ? trim($data['emp_id']) : null;

// Validation rules
if (empty($name) || empty($email) || empty($mobile) || empty($password)) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Name, email, mobile, and password are required fields."
    ]);
    exit();
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Invalid email format."
    ]);
    exit();
}

if (!preg_match('/^[0-9]{10}$/', $mobile)) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Mobile number must be exactly 10 digits."
    ]);
    exit();
}

// Restrict registration exclusively to Admin role
if (strtolower($role) !== 'admin') {
    http_response_code(403);
    echo json_encode([
        "success" => false,
        "message" => "Only Admin registration is permitted through this endpoint."
    ]);
    exit();
}

try {
    $db = new Database();
    $conn = $db->getConnection();

    if (!$conn) {
        throw new Exception("Database connection failure.");
    }

    // Check if email or mobile already exists
    $checkQuery = "SELECT id FROM users WHERE email = :email OR mobile = :mobile LIMIT 1";
    $stmt = $conn->prepare($checkQuery);
    $stmt->execute([':email' => $email, ':mobile' => $mobile]);

    if ($stmt->rowCount() > 0) {
        http_response_code(409);
        echo json_encode([
            "success" => false,
            "message" => "An account with this email or mobile number already exists."
        ]);
        exit();
    }

    // Auto-generate emp_id if missing (e.g., ADM1001)
    if (empty($emp_id)) {
        $countQuery = "SELECT COUNT(id) as total FROM users WHERE role = 'Admin'";
        $countStmt = $conn->query($countQuery);
        $totalAdmins = $countStmt->fetch()['total'];
        $emp_id = 'ADM' . (1000 + $totalAdmins + 1);
    }

    // Hash password securely
    $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

    $currentTime = date('Y-m-d H:i:s');

    $insertQuery = "INSERT INTO users 
        (name, emp_id, mobile, email, role, password, reset_otp_hash, reset_otp_expire_at, created_at, updated_at) 
        VALUES 
        (:name, :emp_id, :mobile, :email, :role, :password, NULL, NULL, :created_at, :updated_at)";

    $insertStmt = $conn->prepare($insertQuery);
    $executed = $insertStmt->execute([
        ':name'       => $name,
        ':emp_id'     => $emp_id,
        ':mobile'     => $mobile,
        ':email'      => $email,
        ':role'       => 'Admin',
        ':password'   => $hashedPassword,
        ':created_at' => $currentTime,
        ':updated_at' => $currentTime
    ]);

    if ($executed) {
        http_response_code(201);
        echo json_encode([
            "success" => true,
            "message" => "Admin registered successfully.",
            "data" => [
                "id"     => $conn->lastInsertId(),
                "emp_id" => $emp_id,
                "name"   => $name,
                "email"  => $email,
                "role"   => "Admin"
            ]
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "message" => "Failed to complete registration."
        ]);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database error: " . $e->getMessage()
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Error: " . $e->getMessage()
    ]);
}
?>