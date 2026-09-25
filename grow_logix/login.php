<?php
// Clear buffer for clean JSON output
if (ob_get_level()) ob_end_clean();

// CORS Headers
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

require_once 'database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        "status" => "error",
        "message" => "Method not allowed. Use POST."
    ]);
    exit();
}

$data = json_decode(file_get_contents("php://input"));

if ((empty($data->email) && empty($data->emp_id)) || empty($data->password) || empty($data->role)) {
    http_response_code(400);
    echo json_encode([
        "status" => "error",
        "message" => "Email/Emp ID, password, and role are required."
    ]);
    exit();
}

$loginInput = trim($data->email ?? $data->emp_id ?? '');
$password = $data->password;
$selectedRole = trim($data->role);
$deviceId = !empty($data->device_id) ? trim($data->device_id) : 'UNKNOWN_DEVICE';
$deviceName = !empty($data->device_name) ? trim($data->device_name) : 'Company PC';

try {
    // 1. Fetch user by email OR emp_id
    $stmt = $pdo->prepare("SELECT id, emp_id, name, email, mobile, password, role FROM users WHERE LOWER(email) = LOWER(:input) OR LOWER(emp_id) = LOWER(:input) LIMIT 1");
    $stmt->execute([':input' => $loginInput]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(401);
        echo json_encode(["status" => "error", "message" => "Invalid credentials."]);
        exit();
    }

    // 2. Verify Password
    if (!password_verify($password, $user['password'])) {
        http_response_code(401);
        echo json_encode(["status" => "error", "message" => "Invalid credentials."]);
        exit();
    }

    // 3. Dynamic Role enforcement check
    $employeeRoles = [
        'Website Developer', 
        'Graphics Designer', 
        'Data Scrapper', 
        'Video Editor', 
        'SEO Executive', 
        'Social Media Executive',
        'Employee'
    ];

    if ($selectedRole === 'Manager') {
        if (strcasecmp($user['role'], 'Manager') !== 0) {
            http_response_code(403);
            echo json_encode([
                "status" => "error",
                "message" => "Account registered as '{$user['role']}'. Cannot log in as Manager."
            ]);
            exit();
        }
    } else {
        // If employee selects a specific role or general Employee, check against allowed employee roles
        if (!in_array($user['role'], $employeeRoles) && strcasecmp($user['role'], $selectedRole) !== 0) {
            http_response_code(403);
            echo json_encode([
                "status" => "error",
                "message" => "Account registered as '{$user['role']}'. Please select your assigned role."
            ]);
            exit();
        }
    }

    // 4. Manage Device Mapping
    if ($deviceId !== 'UNKNOWN_DEVICE') {
        $devStmt = $pdo->prepare("SELECT id, pc_number FROM devices WHERE device_id = :device_id LIMIT 1");
        $devStmt->execute([':device_id' => $deviceId]);
        $deviceRow = $devStmt->fetch(PDO::FETCH_ASSOC);

        if ($deviceRow) {
            $updateDev = $pdo->prepare("
                UPDATE devices 
                SET emp_id = :emp_id, 
                    assigned_user_id = :user_id, 
                    device_name = :device_name, 
                    last_login_at = NOW() 
                WHERE device_id = :device_id
            ");
            $updateDev->execute([
                ':emp_id' => $user['emp_id'],
                ':user_id' => $user['id'],
                ':device_name' => $deviceName,
                ':device_id' => $deviceId
            ]);
            $pcNumber = $deviceRow['pc_number'];
        } else {
            $unassignOld = $pdo->prepare("UPDATE devices SET assigned_user_id = NULL, emp_id = NULL WHERE assigned_user_id = :user_id");
            $unassignOld->execute([':user_id' => $user['id']]);

            $maxPcStmt = $pdo->query("SELECT MAX(pc_number) AS max_pc FROM devices");
            $maxPcRow = $maxPcStmt->fetch(PDO::FETCH_ASSOC);
            $nextPcNum = ($maxPcRow && $maxPcRow['max_pc'] !== null) ? ((int)$maxPcRow['max_pc'] + 1) : 1;

            $insertDev = $pdo->prepare("
                INSERT INTO devices (pc_number, device_id, device_name, emp_id, assigned_user_id, last_login_at) 
                VALUES (:pc_number, :device_id, :device_name, :emp_id, :user_id, NOW())
            ");
            $insertDev->execute([
                ':pc_number' => $nextPcNum,
                ':device_id' => $deviceId,
                ':device_name' => $deviceName,
                ':emp_id' => $user['emp_id'],
                ':user_id' => $user['id']
            ]);
            $pcNumber = $nextPcNum;
        }
    } else {
        $pcNumber = null;
    }

    // 5. Success Response
    http_response_code(200);
    echo json_encode([
        "status" => "success",
        "message" => "Login successful",
        "data" => [
            "id" => (int)$user['id'],
            "emp_id" => $user['emp_id'],
            "name" => $user['name'],
            "email" => $user['email'],
            "mobile" => $user['mobile'] ?? '',
            "role" => $user['role'],
            "pc_number" => $pcNumber,
            "device_id" => $deviceId,
            "device_name" => $deviceName
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Database error: " . $e->getMessage()
    ]);
}
?>