<?php
// Clear buffer for clean JSON output
if (ob_get_level()) ob_end_clean();

// ==================== CORS HEADERS ====================
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
        "status"  => "error",
        "message" => "Method not allowed. Use POST."
    ]);
    exit();
}

// ==================== PARSE INPUT ====================
$rawInput = file_get_contents("php://input");
$data = json_decode($rawInput);

if (!$data) {
    http_response_code(400);
    echo json_encode([
        "status"  => "error",
        "message" => "Invalid JSON payload."
    ]);
    exit();
}

$loginInput   = trim($data->email ?? $data->emp_id ?? '');
$password     = $data->password ?? '';
$selectedRole = trim($data->role ?? '');
$deviceId     = !empty($data->device_id)   ? trim($data->device_id)   : 'UNKNOWN_DEVICE';
$deviceName   = !empty($data->device_name) ? trim($data->device_name) : 'Company PC';

if (empty($loginInput) || empty($password) || empty($selectedRole)) {
    http_response_code(400);
    echo json_encode([
        "status"  => "error",
        "message" => "Email/Emp ID, password, and role are required."
    ]);
    exit();
}

try {
    // ==================== 1. FETCH USER ====================
    // users columns: id, emp_id, name, email, mobile, password, role,
    //                reset_otp_hash, reset_otp_expires_at, is_active,
    //                created_at, updated_at, last_heartbeat, app_running
    $stmt = $pdo->prepare("
        SELECT 
            id,
            emp_id,
            name,
            email,
            mobile,
            password,
            role,
            is_active,
            created_at,
            updated_at,
            last_heartbeat,
            app_running
        FROM users
        WHERE LOWER(email) = LOWER(:input) 
           OR LOWER(emp_id) = LOWER(:input)
        LIMIT 1
    ");
    $stmt->execute([':input' => $loginInput]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(401);
        echo json_encode(["status" => "error", "message" => "Invalid credentials."]);
        exit();
    }

    // ==================== 2. VERIFY PASSWORD ====================
    if (!password_verify($password, $user['password'])) {
        http_response_code(401);
        echo json_encode(["status" => "error", "message" => "Invalid credentials."]);
        exit();
    }

    // ==================== 3. CHECK ACTIVE ====================
    if ((int)$user['is_active'] !== 1) {
        http_response_code(403);
        echo json_encode([
            "status"  => "error",
            "message" => "Your account has been disabled. Contact administrator."
        ]);
        exit();
    }

    // ==================== 4. ROLE ENFORCEMENT ====================
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
                "status"  => "error",
                "message" => "Account registered as '{$user['role']}'. Cannot log in as Manager."
            ]);
            exit();
        }
    } else {
        $roleMatches    = (strcasecmp($user['role'], $selectedRole) === 0);
        $isEmployeeRole = in_array($user['role'], $employeeRoles, true);

        if (!$roleMatches && !$isEmployeeRole) {
            http_response_code(403);
            echo json_encode([
                "status"  => "error",
                "message" => "Account registered as '{$user['role']}'. Please select your assigned role."
            ]);
            exit();
        }
    }

    // ==================== 5. DEVICE MAPPING ====================
    // devices columns: id, device_id, device_name, pc_number,
    //                  emp_id, assigned_user_id, last_login_at
    // IMPORTANT: devices.emp_id may refer to users.id (NOT users.emp_id)
    //            because users.emp_id can be NULL for Manager.
    $pcNumber        = null;
    $finalDeviceId   = 'N/A';
    $finalDeviceName = 'N/A';

    if ($deviceId !== 'UNKNOWN_DEVICE' && !empty($deviceId)) {
        // ---- Look up existing device by device_id ----
        $devStmt = $pdo->prepare("
            SELECT id, pc_number, device_id, device_name
            FROM devices
            WHERE device_id = :device_id
            LIMIT 1
        ");
        $devStmt->execute([':device_id' => $deviceId]);
        $deviceRow = $devStmt->fetch(PDO::FETCH_ASSOC);

        if ($deviceRow) {
            // ---- Update existing device row (link to user) ----
            $updateDev = $pdo->prepare("
                UPDATE devices
                SET assigned_user_id = :user_id,
                    device_name      = :device_name,
                    last_login_at    = NOW()
                WHERE device_id = :device_id
            ");
            $updateDev->execute([
                ':user_id'     => $user['id'],
                ':device_name' => $deviceName,
                ':device_id'   => $deviceId
            ]);

            $pcNumber        = $deviceRow['pc_number'];
            $finalDeviceId   = $deviceRow['device_id'];
            $finalDeviceName = !empty($deviceName) ? $deviceName : ($deviceRow['device_name'] ?? 'Company PC');
        } else {
            // ---- Unassign any old device from this user ----
            $unassignOld = $pdo->prepare("
                UPDATE devices
                SET assigned_user_id = NULL
                WHERE assigned_user_id = :user_id
            ");
            $unassignOld->execute([':user_id' => $user['id']]);

            // ---- Compute next PC number (safe numeric cast) ----
            $maxPcStmt = $pdo->query("SELECT MAX(CAST(pc_number AS UNSIGNED)) AS max_pc FROM devices");
            $maxPcRow  = $maxPcStmt->fetch(PDO::FETCH_ASSOC);
            $nextPcNum = ($maxPcRow && $maxPcRow['max_pc'] !== null)
                         ? ((int)$maxPcRow['max_pc'] + 1)
                         : 1;

            // ---- Insert new device row ----
            $insertDev = $pdo->prepare("
                INSERT INTO devices 
                    (pc_number, device_id, device_name, emp_id, assigned_user_id, last_login_at)
                VALUES 
                    (:pc_number, :device_id, :device_name, :emp_id, :user_id, NOW())
            ");
            $insertDev->execute([
                ':pc_number'   => $nextPcNum,
                ':device_id'   => $deviceId,
                ':device_name' => $deviceName,
                ':emp_id'      => $user['emp_id'],   // may be NULL for Manager
                ':user_id'     => $user['id']
            ]);

            $pcNumber        = $nextPcNum;
            $finalDeviceId   = $deviceId;
            $finalDeviceName = $deviceName;
        }
    } else {
        // ---- No device info provided — fallback to devices table ----
        // Match either by assigned_user_id OR by emp_id (which stores users.id)
        $fallbackStmt = $pdo->prepare("
            SELECT pc_number, device_id, device_name
            FROM devices
            WHERE assigned_user_id = :user_id
               OR emp_id = :user_id_str
            ORDER BY last_login_at DESC
            LIMIT 1
        ");
        $fallbackStmt->execute([
            ':user_id'     => $user['id'],
            ':user_id_str' => (string)$user['id']
        ]);
        $fallbackRow = $fallbackStmt->fetch(PDO::FETCH_ASSOC);

        if ($fallbackRow) {
            $pcNumber        = $fallbackRow['pc_number'];
            $finalDeviceId   = $fallbackRow['device_id'];
            $finalDeviceName = $fallbackRow['device_name'];
        }
    }

    // ==================== 6. Determine PC type ====================
    // Office PC = non-Manager role AND device has a real pc_number
    // Personal PC = Manager role OR no pc_number assigned
    $isManagerRole = (strcasecmp($user['role'], 'Manager') === 0);
    $hasOfficeNumber = ($pcNumber !== null && $pcNumber !== '' && $pcNumber !== 'NULL');

    if ($isManagerRole || !$hasOfficeNumber) {
        $finalPcType = 'personal';
    } else {
        $finalPcType = 'office';
    }

    // ==================== 7. SUCCESS RESPONSE ====================
    http_response_code(200);
    echo json_encode([
        "status"  => "success",
        "message" => "Login successful",
        "data" => [
            "id"             => (int)$user['id'],
            "emp_id"         => $user['emp_id'],
            "name"           => $user['name'],
            "email"          => $user['email'],
            "mobile"         => $user['mobile'] ?? '',
            "role"           => $user['role'],
            "is_active"      => (int)$user['is_active'],
            "pc_number"      => $pcNumber,               // NULL → personal
            "pc_type"        => $finalPcType,            // 'office' or 'personal'
            "device_id"      => $finalDeviceId,          // from devices table
            "device_name"    => $finalDeviceName,        // from devices table
            "last_heartbeat" => $user['last_heartbeat'],
            "app_running"    => (int)$user['app_running']
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "status"  => "error",
        "message" => "Database error: " . $e->getMessage()
    ]);
}
?>