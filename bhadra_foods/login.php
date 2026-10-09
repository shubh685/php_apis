<?php
// Turn off error display to browser so it never corrupts JSON format
ini_set('display_errors', 0);
error_reporting(0);

// Clean output buffer to wipe out any hosting notices/whitespace
ob_start();

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// Handle browser preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once "data.php";

try {
    $database = new Database();
    $db = $database->getConnection();
} catch (Exception $e) {
    ob_clean();
    echo json_encode([
        "status"  => "error",
        "message" => "Database connection failed"
    ]);
    exit();
}

$rawInput = file_get_contents("php://input");
$data = json_decode($rawInput, true);

if (!is_array($data)) {
    ob_clean();
    echo json_encode([
        "status"  => "error",
        "message" => "Invalid request body"
    ]);
    exit();
}

$username = isset($data['username']) ? trim($data['username']) : '';
$password = isset($data['password']) ? $data['password']        : '';
$role     = isset($data['role'])     ? trim($data['role'])      : '';

if ($username === '' || $password === '' || $role === '') {
    ob_clean();
    echo json_encode([
        "status"  => "error",
        "message" => "Missing required fields"
    ]);
    exit();
}

$roleAliasMap = [
    'Admin'                    => 'Admin',
    'Salesman'                 => 'Salesman',
    'Sales Officer'            => 'Sales Officer',
    'Area Sales Manager'       => 'ASM',
    'Regional Sales Manager'   => 'RSM',
    'Zone Wise Sales Manager'  => 'ZSM',
    'Sales Head'               => 'Sales Head',
    'ASM'                      => 'ASM',
    'RSM'                      => 'RSM',
    'ZSM'                      => 'ZSM',
];

if (isset($roleAliasMap[$role])) {
    $role = $roleAliasMap[$role];
}

try {
    $stmt = $db->prepare(
        "SELECT emp_id, name, email, mobile, password, role 
         FROM users 
         WHERE (email = :u OR mobile = :u OR emp_id = :u) AND role = :role 
         LIMIT 1"
    );
    $stmt->bindParam(":u", $username);
    $stmt->bindParam(":role", $role);
    $stmt->execute();

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    ob_clean(); // Clear any pre-output buffer data

    if ($user) {
        $storedPassword = $user['password'];
        $isValid = false;

        if ($password === $storedPassword) {
            $isValid = true;
        } elseif (password_verify($password, $storedPassword)) {
            $isValid = true;
        }

        if ($isValid) {
            echo json_encode([
                "status"  => "success",
                "message" => "Login successful",
                "user"    => [
                    "emp_id" => (string)$user['emp_id'],
                    "name"   => $user['name'],
                    "email"  => $user['email'],
                    "mobile" => $user['mobile'] ?? '',
                    "role"   => $user['role']
                ]
            ]);
        } else {
            echo json_encode([
                "status"  => "error",
                "message" => "Invalid credentials"
            ]);
        }
    } else {
        echo json_encode([
            "status"  => "error",
            "message" => "User not found or role mismatch"
        ]);
    }

} catch (Exception $e) {
    ob_clean();
    echo json_encode([
        "status"  => "error",
        "message" => "Server error: " . $e->getMessage()
    ]);
}
?>