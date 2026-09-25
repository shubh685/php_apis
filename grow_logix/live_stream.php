<?php
// ==================== ERROR REPORTING ====================
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// ==================== CORS HEADERS ====================
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Accept, Authorization");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function sendResponse($data, $httpCode = 200) {
    http_response_code($httpCode);
    echo json_encode($data);
    exit;
}

function sendError($message, $httpCode = 400) {
    sendResponse(["status" => "error", "message" => $message], $httpCode);
}

// ==================== DB CONNECTION ====================
$host = "localhost";
$user = "root";
$pass = "";
$db   = "grow_logix";

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    sendError("Database connection failed: " . $conn->connect_error, 500);
}

$conn->set_charset("utf8mb4");
@$conn->query("SET SESSION max_allowed_packet = 67108864"); // 64 MB

// ==================== AUTO-MIGRATE USERS TABLE (heartbeat) ====================
@$conn->query("ALTER TABLE `users` ADD COLUMN `last_heartbeat` DATETIME DEFAULT NULL");
@$conn->query("ALTER TABLE `users` ADD COLUMN `app_running` TINYINT(1) DEFAULT 0");

// ==================== AUTO-CREATE / MIGRATE live_stream ====================
$conn->query("
CREATE TABLE IF NOT EXISTS `live_stream` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `emp_id` VARCHAR(50) NOT NULL UNIQUE,
  `status` VARCHAR(20) NOT NULL DEFAULT 'idle',
  `image_base64` LONGTEXT NULL,
  `captured_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `pc_type` VARCHAR(20) DEFAULT 'personal',
  `pc_number` VARCHAR(50) DEFAULT 'Personal PC',
  `window_title` VARCHAR(255) DEFAULT NULL,
  `frame_counter` BIGINT DEFAULT 0,
  INDEX `idx_emp` (`emp_id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_updated` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

@$conn->query("ALTER TABLE `live_stream` ADD COLUMN `pc_type` VARCHAR(20) DEFAULT 'personal'");
@$conn->query("ALTER TABLE `live_stream` ADD COLUMN `pc_number` VARCHAR(50) DEFAULT 'Personal PC'");
@$conn->query("ALTER TABLE `live_stream` ADD COLUMN `window_title` VARCHAR(255) DEFAULT NULL");
@$conn->query("ALTER TABLE `live_stream` ADD COLUMN `captured_at` DATETIME DEFAULT CURRENT_TIMESTAMP");
@$conn->query("ALTER TABLE `live_stream` ADD COLUMN `frame_counter` BIGINT DEFAULT 0");

// ==================== AUTO-CREATE / MIGRATE live_stream_history ====================
$conn->query("
CREATE TABLE IF NOT EXISTS `live_stream_history` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `emp_id` VARCHAR(50) NOT NULL,
  `image_base64` LONGTEXT NULL,
  `captured_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `window_title` VARCHAR(255) DEFAULT NULL,
  INDEX `idx_emp_time` (`emp_id`, `captured_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

@$conn->query("ALTER TABLE `live_stream_history` ADD COLUMN `window_title` VARCHAR(255) DEFAULT NULL");

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

$rawInput = file_get_contents('php://input');
$input = [];
if (!empty($rawInput)) {
    $decoded = json_decode($rawInput, true);
    if (is_array($decoded)) $input = $decoded;
}
if (empty($input) && !empty($_POST)) $input = $_POST;

function normalizeEmpId($id) {
    return strtoupper(trim($id));
}

// ==================== HEARTBEAT ====================
if ($action === 'heartbeat' && $method === 'POST') {
    $emp_id = normalizeEmpId($input['emp_id'] ?? '');
    if (empty($emp_id)) sendError("Employee ID required", 400);

    $stmt = $conn->prepare("
        UPDATE users 
        SET last_heartbeat = NOW(),
            app_running = 1,
            updated_at = NOW()
        WHERE UPPER(emp_id) = ?
    ");
    $stmt->bind_param("s", $emp_id);
    $stmt->execute();

    sendResponse(["status" => "success", "message" => "Heartbeat received"]);
}

// ==================== APP SHUTDOWN ====================
if ($action === 'app_shutdown' && $method === 'POST') {
    $emp_id = normalizeEmpId($input['emp_id'] ?? '');
    if (empty($emp_id)) sendError("Employee ID required", 400);

    $stmt = $conn->prepare("
        UPDATE users 
        SET app_running = 0,
            updated_at = NOW()
        WHERE UPPER(emp_id) = ?
    ");
    $stmt->bind_param("s", $emp_id);
    $stmt->execute();

    sendResponse(["status" => "success", "message" => "App shutdown noted"]);
}

// ==================== VERIFY PC TYPE ====================
if ($action === 'verify_pc_type' && $method === 'POST') {
    $emp_id = normalizeEmpId($input['emp_id'] ?? '');
    $pc_type = $input['pc_type'] ?? 'personal';

    if (empty($emp_id)) sendError("Employee ID required", 400);

    $stmt = $conn->prepare("
        SELECT u.device_id, u.computer_name,
               d.pc_number AS office_pc_number,
               d.device_name AS office_device_name
        FROM users u
        LEFT JOIN devices d ON d.device_id = u.device_id
        WHERE UPPER(u.emp_id) = ?
        LIMIT 1
    ");
    $stmt->bind_param("s", $emp_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    $pcNumber = 'Personal PC';
    $finalPcType = $pc_type;

    if ($pc_type === 'office') {
        if ($user && !empty($user['office_pc_number'])) {
            $pcNumber = 'PC-' . $user['office_pc_number'];
        } elseif ($user && !empty($user['computer_name'])) {
            $pcNumber = $user['computer_name'];
        } elseif ($user && !empty($user['device_id'])) {
            $pcNumber = 'PC-' . substr($user['device_id'], -4);
        } else {
            $pcNumber = 'Office-PC';
        }
        $finalPcType = 'office';
    }

    $upStmt = $conn->prepare("
        INSERT INTO live_stream (emp_id, status, pc_type, pc_number, updated_at)
        VALUES (?, 'idle', ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            pc_type = VALUES(pc_type),
            pc_number = VALUES(pc_number),
            updated_at = NOW()
    ");
    $upStmt->bind_param("sss", $emp_id, $finalPcType, $pcNumber);
    @$upStmt->execute();

    sendResponse([
        "status" => "success",
        "pc_type" => $finalPcType,
        "pc_number" => $pcNumber
    ]);
}

// ==================== 1. SEND LIVE REQUEST (Manager) ====================
if ($action === 'send_live_request' && $method === 'POST') {
    $emp_id = normalizeEmpId($input['emp_id'] ?? '');
    if (empty($emp_id)) sendError("Employee ID required", 400);

    $userStmt = $conn->prepare("
        SELECT u.id, u.name, u.device_id, u.computer_name, u.is_active, u.updated_at,
               d.pc_number AS office_pc_number,
               d.device_name AS office_device_name
        FROM users u
        LEFT JOIN devices d ON d.device_id = u.device_id
        WHERE UPPER(u.emp_id) = ?
        LIMIT 1
    ");
    $userStmt->bind_param("s", $emp_id);
    $userStmt->execute();
    $userRes = $userStmt->get_result()->fetch_assoc();

    if (!$userRes) sendError("Employee not found in users table", 404);

    $displayPc = 'Personal PC';
    $pcType = 'personal';
    if (!empty($userRes['office_pc_number'])) {
        $displayPc = 'PC-' . $userRes['office_pc_number'];
        $pcType = 'office';
    }

    $existingStmt = $conn->prepare("SELECT pc_type, pc_number FROM live_stream WHERE emp_id = ? LIMIT 1");
    $existingStmt->bind_param("s", $emp_id);
    $existingStmt->execute();
    $existing = $existingStmt->get_result()->fetch_assoc();
    
    if ($existing && !empty($existing['pc_type'])) {
        $pcType = $existing['pc_type'];
        $displayPc = $existing['pc_number'];
    }

    $stmt = $conn->prepare("
        INSERT INTO live_stream (emp_id, status, image_base64, captured_at, updated_at, pc_type, pc_number, frame_counter) 
        VALUES (?, 'requested', NULL, NOW(), NOW(), ?, ?, 0) 
        ON DUPLICATE KEY UPDATE 
            status = 'requested', 
            image_base64 = NULL,
            captured_at = NOW(),
            updated_at = NOW(),
            frame_counter = 0
    ");
    $stmt->bind_param("sss", $emp_id, $pcType, $displayPc);
    $stmt->execute();

    sendResponse([
        "status" => "success",
        "message" => "Live request sent successfully",
        "emp_id" => $emp_id,
        "emp_name" => $userRes['name'],
        "pc_number" => $displayPc,
        "pc_type" => $pcType
    ]);
}

// ==================== 2. CHECK LIVE REQUEST (Employee) ====================
if ($action === 'check_live_request' && $method === 'GET') {
    $emp_id = normalizeEmpId($_GET['emp_id'] ?? '');
    if (empty($emp_id)) sendResponse(["status" => "idle"]);

    $stmt = $conn->prepare("SELECT status, updated_at FROM live_stream WHERE emp_id = ? LIMIT 1");
    $stmt->bind_param("s", $emp_id);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();

    if ($res && $res['status'] === 'requested') {
        $requestTime = strtotime($res['updated_at']);
        if ((time() - $requestTime) > 60) {
            $upStmt = $conn->prepare("UPDATE live_stream SET status = 'idle' WHERE emp_id = ?");
            $upStmt->bind_param("s", $emp_id);
            $upStmt->execute();
            sendResponse(["status" => "idle"]);
        }
    }

    sendResponse(["status" => $res['status'] ?? 'idle']);
}

// ==================== 3. UPLOAD SCREEN FRAME (Employee) ====================
if ($action === 'upload_screen_frame' && $method === 'POST') {
    $emp_id       = normalizeEmpId($input['emp_id'] ?? '');
    $image_base64 = $input['image_base64'] ?? '';
    $save_history = $input['save_history'] ?? false;
    $window_title = $input['window_title'] ?? '';
    $pc_type      = $input['pc_type'] ?? 'personal';
    $pc_number    = $input['pc_number'] ?? 'Personal PC';

    if (empty($emp_id) || empty($image_base64)) {
        sendError("Employee ID and Image Data required", 400);
    }

    if (strpos($image_base64, 'base64,') !== false) {
        $image_base64 = substr($image_base64, strpos($image_base64, 'base64,') + 7);
    }

    $stmt = $conn->prepare("
        INSERT INTO live_stream (emp_id, status, image_base64, captured_at, updated_at, window_title, pc_type, pc_number, frame_counter) 
        VALUES (?, 'streaming', ?, NOW(), NOW(), ?, ?, ?, 1) 
        ON DUPLICATE KEY UPDATE 
            status = 'streaming',
            image_base64 = VALUES(image_base64),
            captured_at = NOW(),
            updated_at = NOW(),
            window_title = VALUES(window_title),
            pc_type = VALUES(pc_type),
            pc_number = VALUES(pc_number),
            frame_counter = frame_counter + 1
    ");
    $stmt->bind_param("sssss", $emp_id, $image_base64, $window_title, $pc_type, $pc_number);

    if (!$stmt->execute()) {
        sendError("Failed to save frame: " . $stmt->error, 500);
    }

    if ($save_history) {
        $histStmt = $conn->prepare("
            INSERT INTO live_stream_history (emp_id, image_base64, captured_at, window_title) 
            VALUES (?, ?, NOW(), ?)
        ");
        $histStmt->bind_param("sss", $emp_id, $image_base64, $window_title);
        @$histStmt->execute();

        $cleanupStmt = $conn->prepare("
            DELETE FROM live_stream_history 
            WHERE emp_id = ? 
              AND id NOT IN (
                SELECT id FROM (
                  SELECT id FROM live_stream_history 
                  WHERE emp_id = ?
                  ORDER BY captured_at DESC 
                  LIMIT 500
                ) AS keep_ids
              )
        ");
        $cleanupStmt->bind_param("ss", $emp_id, $emp_id);
        @$cleanupStmt->execute();
    }

    @$conn->query("UPDATE users SET updated_at = NOW() WHERE UPPER(emp_id) = '$emp_id'");

    $cntStmt = $conn->prepare("SELECT frame_counter FROM live_stream WHERE emp_id = ? LIMIT 1");
    $cntStmt->bind_param("s", $emp_id);
    $cntStmt->execute();
    $cntRes = $cntStmt->get_result()->fetch_assoc();

    sendResponse([
        "status" => "success",
        "size" => strlen($image_base64),
        "frame_counter" => (int)($cntRes['frame_counter'] ?? 0),
        "captured_at" => date('Y-m-d H:i:s')
    ]);
}

// ==================== 4. GET LATEST LIVE STREAM (Manager) ====================
if ($action === 'get_live_stream' && $method === 'GET') {
    $emp_id = normalizeEmpId($_GET['emp_id'] ?? '');
    if (empty($emp_id)) sendError("Employee ID required", 400);

    $stmt = $conn->prepare("
        SELECT id, image_base64, captured_at, updated_at, status, window_title, 
               pc_type, pc_number, frame_counter 
        FROM live_stream 
        WHERE emp_id = ? 
        LIMIT 1
    ");
    $stmt->bind_param("s", $emp_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();

    if ($result && !empty($result['image_base64']) && $result['status'] === 'streaming') {
        $age = time() - strtotime($result['captured_at']);

        if ($age > 180) {
            sendResponse(["status" => "stale", "message" => "Stream is stale", "age" => $age]);
        }

        sendResponse([
            "status" => "success",
            "id" => $result['id'],
            "image_base64" => $result['image_base64'],
            "captured_at" => $result['captured_at'],
            "updated_at" => $result['updated_at'],
            "stream_status" => $result['status'],
            "window_title" => $result['window_title'],
            "pc_type" => $result['pc_type'],
            "pc_number" => $result['pc_number'],
            "frame_counter" => (int)($result['frame_counter'] ?? 0),
            "age" => $age
        ]);
    } else if ($result && $result['status'] === 'requested') {
        sendResponse(["status" => "waiting", "message" => "Waiting for employee to accept..."]);
    } else {
        sendResponse(["status" => "waiting", "message" => "Waiting for live screen..."]);
    }
}

// ==================== 5. GET FRAME HISTORY (Manager) ====================
if ($action === 'get_frame_history' && $method === 'GET') {
    $emp_id = normalizeEmpId($_GET['emp_id'] ?? '');
    $limit  = intval($_GET['limit'] ?? 100);
    if ($limit < 1 || $limit > 500) $limit = 100;
    if (empty($emp_id)) sendError("Employee ID required", 400);

    $stmt = $conn->prepare("
        SELECT id, image_base64, captured_at, window_title
        FROM live_stream_history 
        WHERE emp_id = ? AND image_base64 IS NOT NULL
        ORDER BY captured_at DESC 
        LIMIT ?
    ");
    $stmt->bind_param("si", $emp_id, $limit);
    $stmt->execute();
    $res = $stmt->get_result();

    $frames = [];
    while ($row = $res->fetch_assoc()) {
        $frames[] = [
            "id" => (int)$row['id'],
            "image_base64" => $row['image_base64'],
            "captured_at" => $row['captured_at'],
            "window_title" => $row['window_title']
        ];
    }

    $devStmt = $conn->prepare("
        SELECT u.device_id, u.computer_name, d.pc_number AS office_pc_number, d.device_name,
               ls.pc_type, ls.pc_number
        FROM users u
        LEFT JOIN devices d ON d.device_id = u.device_id
        LEFT JOIN live_stream ls ON ls.emp_id = u.emp_id
        WHERE UPPER(u.emp_id) = ?
        LIMIT 1
    ");
    $devStmt->bind_param("s", $emp_id);
    $devStmt->execute();
    $device = $devStmt->get_result()->fetch_assoc();

    $pcDisplay = $device['pc_number'] ?? 'Personal PC';
    $pcType = $device['pc_type'] ?? 'personal';

    if ($pcDisplay === 'Personal PC' && !empty($device['office_pc_number'])) {
        $pcDisplay = 'PC-' . $device['office_pc_number'];
        $pcType = 'office';
    }

    sendResponse([
        "status" => "success",
        "count" => count($frames),
        "frames" => $frames,
        "device" => [
            "pc_number" => $pcDisplay,
            "pc_type" => $pcType,
            "device_id" => $device['device_id'] ?? 'N/A',
            "computer_name" => $device['computer_name'] ?? $device['device_name'] ?? 'N/A'
        ]
    ]);
}

// ==================== 6. GET VIDEO PLAYBACK (Manager) ====================
if ($action === 'get_video_playback' && $method === 'GET') {
    $emp_id = normalizeEmpId($_GET['emp_id'] ?? '');
    $limit  = intval($_GET['limit'] ?? 500);
    if ($limit < 1 || $limit > 1000) $limit = 500;
    if (empty($emp_id)) sendError("Employee ID required", 400);

    $stmt = $conn->prepare("
        SELECT id, image_base64, captured_at, window_title
        FROM live_stream_history 
        WHERE emp_id = ? AND image_base64 IS NOT NULL
        ORDER BY captured_at ASC 
        LIMIT ?
    ");
    $stmt->bind_param("si", $emp_id, $limit);
    $stmt->execute();
    $res = $stmt->get_result();

    $frames = [];
    while ($row = $res->fetch_assoc()) {
        $frames[] = [
            "id" => (int)$row['id'],
            "image_base64" => $row['image_base64'],
            "captured_at" => $row['captured_at'],
            "window_title" => $row['window_title']
        ];
    }

    $devStmt = $conn->prepare("
        SELECT u.device_id, u.computer_name, d.pc_number AS office_pc_number, d.device_name,
               ls.pc_type, ls.pc_number
        FROM users u
        LEFT JOIN devices d ON d.device_id = u.device_id
        LEFT JOIN live_stream ls ON ls.emp_id = u.emp_id
        WHERE UPPER(u.emp_id) = ?
        LIMIT 1
    ");
    $devStmt->bind_param("s", $emp_id);
    $devStmt->execute();
    $device = $devStmt->get_result()->fetch_assoc();

    $pcDisplay = $device['pc_number'] ?? 'Personal PC';
    $pcType = $device['pc_type'] ?? 'personal';

    if ($pcDisplay === 'Personal PC' && !empty($device['office_pc_number'])) {
        $pcDisplay = 'PC-' . $device['office_pc_number'];
        $pcType = 'office';
    }

    // Append current live frame if streaming
    $liveStmt = $conn->prepare("
        SELECT image_base64, captured_at, window_title, status 
        FROM live_stream 
        WHERE emp_id = ? AND status = 'streaming' AND image_base64 IS NOT NULL
        LIMIT 1
    ");
    $liveStmt->bind_param("s", $emp_id);
    $liveStmt->execute();
    $liveFrame = $liveStmt->get_result()->fetch_assoc();

    if ($liveFrame && !empty($liveFrame['image_base64'])) {
        $lastHistoryTime = !empty($frames) ? end($frames)['captured_at'] : null;
        if ($lastHistoryTime !== $liveFrame['captured_at']) {
            $frames[] = [
                "id" => -1,
                "image_base64" => $liveFrame['image_base64'],
                "captured_at" => $liveFrame['captured_at'],
                "window_title" => $liveFrame['window_title'],
                "is_live" => true
            ];
        }
    }

    sendResponse([
        "status" => "success",
        "count" => count($frames),
        "frames" => $frames,
        "device" => [
            "pc_number" => $pcDisplay,
            "pc_type" => $pcType,
            "device_id" => $device['device_id'] ?? 'N/A',
            "computer_name" => $device['computer_name'] ?? $device['device_name'] ?? 'N/A'
        ]
    ]);
}

// ==================== 7. STOP LIVE STREAM ====================
if ($action === 'stop_live_stream' && $method === 'POST') {
    $emp_id = normalizeEmpId($input['emp_id'] ?? '');
    $keep_history = $input['keep_history'] ?? true;

    if (!empty($emp_id)) {
        if ($keep_history) {
            $stmt = $conn->prepare("UPDATE live_stream SET status = 'idle', image_base64 = NULL, updated_at = NOW() WHERE emp_id = ?");
            $stmt->bind_param("s", $emp_id);
            $stmt->execute();
        } else {
            $stmt = $conn->prepare("DELETE FROM live_stream WHERE emp_id = ?");
            $stmt->bind_param("s", $emp_id);
            $stmt->execute();
            $stmt2 = $conn->prepare("DELETE FROM live_stream_history WHERE emp_id = ?");
            $stmt2->bind_param("s", $emp_id);
            $stmt2->execute();
        }
    }

    sendResponse(["status" => "success", "message" => "Stream stopped"]);
}

// ==================== 8. CLEAR HISTORY ====================
if ($action === 'clear_history' && $method === 'POST') {
    $emp_id = normalizeEmpId($input['emp_id'] ?? '');
    if (!empty($emp_id)) {
        $stmt = $conn->prepare("DELETE FROM live_stream_history WHERE emp_id = ?");
        $stmt->bind_param("s", $emp_id);
        $stmt->execute();
    }
    sendResponse(["status" => "success", "message" => "History cleared"]);
}

// ==================== 9. VERIFY EMPLOYEE (with heartbeat) ====================
if ($action === 'verify_employee' && $method === 'GET') {
    $emp_id = normalizeEmpId($_GET['emp_id'] ?? '');
    if (empty($emp_id)) sendError("Employee ID required", 400);

    $stmt = $conn->prepare("
        SELECT u.id, u.emp_id, u.name, u.email, u.mobile, u.role, 
               u.device_id, u.computer_name, u.is_active, u.updated_at,
               u.last_heartbeat, u.app_running,
               d.pc_number AS office_pc_number, 
               d.device_name AS office_device_name,
               ls.pc_type, ls.pc_number
        FROM users u
        LEFT JOIN devices d ON d.device_id = u.device_id
        LEFT JOIN live_stream ls ON ls.emp_id = u.emp_id
        WHERE UPPER(u.emp_id) = ? 
        LIMIT 1
    ");
    $stmt->bind_param("s", $emp_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if (!$user) {
        sendResponse([
            "status" => "not_found",
            "message" => "Employee not found",
            "is_online" => false,
            "is_active" => false
        ]);
    }

    // Active if heartbeat within last 30 seconds
    $lastHeartbeat = strtotime($user['last_heartbeat'] ?? '2000-01-01');
    $isAppActive = (time() - $lastHeartbeat) < 30 && ($user['app_running'] == 1);
    $isUserActive = ($user['is_active'] == 1);

    $pcDisplay = 'Personal PC';
    $pcType = 'personal';

    if (!empty($user['pc_type']) && !empty($user['pc_number'])) {
        $pcType = $user['pc_type'];
        $pcDisplay = $user['pc_number'];
    } elseif (!empty($user['office_pc_number'])) {
        $pcDisplay = 'PC-' . $user['office_pc_number'];
        $pcType = 'office';
    }

    sendResponse([
        "status" => "success",
        "is_online" => $isAppActive,
        "is_active" => $isAppActive,
        "is_enabled" => $isUserActive,
        "last_heartbeat" => $user['last_heartbeat'],
        "employee" => [
            "id" => (int)$user['id'],
            "emp_id" => $user['emp_id'],
            "name" => $user['name'],
            "email" => $user['email'],
            "mobile" => $user['mobile'],
            "role" => $user['role'],
            "pc_number" => $pcDisplay,
            "pc_type" => $pcType,
            "device_id" => $user['device_id'] ?? 'N/A',
            "computer_name" => $user['computer_name'] ?? $user['office_device_name'] ?? 'N/A',
            "is_active" => (int)$user['is_active'],
            "is_enabled" => $isUserActive,
            "is_app_active" => $isAppActive,
            "last_seen" => $user['updated_at'],
            "last_heartbeat" => $user['last_heartbeat']
        ]
    ]);
}

// ==================== 10. GET ALL ACTIVE STREAMS ====================
if ($action === 'get_active_streams' && $method === 'GET') {
    $stmt = $conn->prepare("
        SELECT ls.emp_id, ls.status, ls.captured_at, ls.updated_at, ls.pc_type, ls.pc_number,
               u.name, u.device_id,
               d.pc_number AS office_pc_number
        FROM live_stream ls
        LEFT JOIN users u ON UPPER(u.emp_id) = ls.emp_id
        LEFT JOIN devices d ON d.device_id = u.device_id
        WHERE ls.status IN ('streaming', 'requested')
        ORDER BY ls.updated_at DESC
    ");
    $stmt->execute();
    $res = $stmt->get_result();

    $streams = [];
    while ($row = $res->fetch_assoc()) {
        $pcDisplay = $row['pc_number'] ?? 'Personal PC';
        if ($pcDisplay === 'Personal PC' && !empty($row['office_pc_number'])) {
            $pcDisplay = 'PC-' . $row['office_pc_number'];
        }
        $streams[] = [
            "emp_id" => $row['emp_id'],
            "status" => $row['status'],
            "captured_at" => $row['captured_at'],
            "updated_at" => $row['updated_at'],
            "name" => $row['name'] ?? 'Unknown',
            "pc_number" => $pcDisplay,
            "pc_type" => $row['pc_type'] ?? 'personal'
        ];
    }

    sendResponse(["status" => "success", "streams" => $streams]);
}

$conn->close();
sendError("Invalid endpoint request", 400);
?>