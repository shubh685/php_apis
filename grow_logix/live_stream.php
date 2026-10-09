<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// ==================== ERROR REPORTING ====================
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            "status"  => "error",
            "message" => "PHP fatal: " . $err['message'],
            "file"    => basename($err['file'] ?? ''),
            "line"    => $err['line'] ?? 0
        ]);
    }
});

// ==================== CORS ====================
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Accept, Authorization");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Content-Type: application/json; charset=utf-8");

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ==================== HELPERS ====================
function sendResponse($data, $httpCode = 200) {
    http_response_code($httpCode);
    echo json_encode($data);
    exit;
}
function sendError($message, $httpCode = 400) {
    sendResponse(["status" => "error", "message" => $message], $httpCode);
}
function normalizeEmpId($id) {
    if (!is_string($id) && !is_numeric($id)) return '';
    return strtoupper(trim((string)$id));
}
function normalizeAction($a) {
    if (!is_string($a) && !is_numeric($a)) return '';
    return strtolower(trim((string)$a));
}

// ==================== DB CONNECTION ====================
$host     = "localhost";
$dbname   = "u553882912_grow_logix";
$username = "u553882912_grow_logix";
$password = "GrowSoc@2020";

$conn = null;
$dbDriver = 'mysqli';

if (function_exists('mysqli_connect')) {
    mysqli_report(MYSQLI_REPORT_OFF);
    $conn = @new mysqli($host, $username, $password, $dbname);
    if ($conn->connect_error) {
        $conn = null;
    } else {
        $conn->set_charset("utf8mb4");
    }
}

if ($conn === null) {
    $dbDriver = 'pdo';
    try {
        $pdo = new PDO(
            "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
            $username,
            $password,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    } catch (PDOException $e) {
        sendError("Database connection failed: " . $e->getMessage(), 500);
    }
}

if ($dbDriver === 'pdo') {
    class PDOShimStatement {
        private $stmt;
        private $params = [];
        public function __construct($stmt) { $this->stmt = $stmt; }
        public function bind_param($types, &...$vars) {
            $this->params = $vars;
            return true;
        }
        public function execute() {
            return $this->stmt->execute($this->params);
        }
        public function get_result() {
            return new PDOShimResult($this->stmt);
        }
        public function close() { return true; }
        public function __get($name) {
            if ($name === 'error') return $this->stmt->errorInfo()[2] ?? '';
            return null;
        }
    }
    class PDOShimResult {
        private $stmt;
        public function __construct($stmt) { $this->stmt = $stmt; }
        public function fetch_assoc() { return $this->stmt->fetch(PDO::FETCH_ASSOC); }
    }
    class PDOShim {
        private $pdo;
        public $error = '';
        public function __construct($pdo) { $this->pdo = $pdo; }
        public function prepare($sql) {
            try {
                return new PDOShimStatement($this->pdo->prepare($sql));
            } catch (Throwable $e) {
                $this->error = $e->getMessage();
                return false;
            }
        }
        public function query($sql) {
            try {
                return $this->pdo->query($sql);
            } catch (Throwable $e) {
                $this->error = $e->getMessage();
                return false;
            }
        }
        public function real_escape_string($s) {
            return substr($this->pdo->quote($s), 1, -1);
        }
        public function close() { return true; }
        public function __get($name) {
            if ($name === 'connect_error') return '';
            return null;
        }
    }
    $conn = new PDOShim($pdo);
}

try {
    @$conn->query("SET SESSION max_allowed_packet = 67108864");
} catch (Throwable $e) {
    // ignore
}

// ==================== SCHEMA DETECTION (avoid ALTER TABLE on shared hosting) ====================
/**
 * Reads actual columns of a table via SHOW COLUMNS.
 * Returns array of column names (lowercased).
 */
function getTableColumns($conn, $table) {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];

    $cols = [];
    $safeTable = preg_replace('/[^A-Za-z0-9_]/', '', $table);
    $res = @$conn->query("SHOW COLUMNS FROM `$safeTable`");
    if ($res) {
        if ($dbDriverCheck = true) {
            if (method_exists($res, 'fetch_assoc')) {
                while ($row = $res->fetch_assoc()) {
                    if (isset($row['Field'])) {
                        $cols[] = strtolower($row['Field']);
                    }
                }
            } elseif (method_exists($res, 'fetch')) {
                while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
                    if (isset($row['Field'])) {
                        $cols[] = strtolower($row['Field']);
                    }
                }
            }
        }
    }
    $cache[$table] = $cols;
    return $cols;
}

// Safe query helper — never throws
function safeQuery($conn, $sql) {
    try {
        return @$conn->query($sql);
    } catch (Throwable $e) {
        return false;
    }
}

// ==================== SAFE TABLE CREATION (no ALTER) ====================
safeQuery($conn, "
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

safeQuery($conn, "
CREATE TABLE IF NOT EXISTS `live_stream_history` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `emp_id` VARCHAR(50) NOT NULL,
  `image_base64` LONGTEXT NULL,
  `captured_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `window_title` VARCHAR(255) DEFAULT NULL,
  INDEX `idx_emp_time` (`emp_id`, `captured_at`),
  INDEX `idx_captured_at` (`captured_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

safeQuery($conn, "
CREATE TABLE IF NOT EXISTS `devices` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `device_id` VARCHAR(191) NOT NULL UNIQUE,
  `device_name` VARCHAR(191) DEFAULT 'Company PC',
  `pc_number` VARCHAR(50) DEFAULT NULL,
  `emp_id` VARCHAR(50) DEFAULT NULL,
  `assigned_user_id` INT DEFAULT NULL,
  `last_login_at` DATETIME DEFAULT NULL,
  INDEX `idx_device_id` (`device_id`),
  INDEX `idx_emp_id` (`emp_id`),
  INDEX `idx_assigned_user` (`assigned_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

safeQuery($conn, "
CREATE TABLE IF NOT EXISTS `live_requests` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `emp_id` VARCHAR(50) NOT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
  `requested_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_emp` (`emp_id`),
  INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// Detect actual columns once
$liveStreamCols = getTableColumns($conn, 'live_stream');
$usersCols      = getTableColumns($conn, 'users');
$liveReqCols    = getTableColumns($conn, 'live_requests');

// ==================== MONTH-END RETENTION PURGE ====================
function cleanupMonthlyHistory($conn, $empId = null, $force = false) {
    if (!$force) {
        $lockFile = sys_get_temp_dir() . '/gl_history_cleanup.lock';
        if (file_exists($lockFile) && (time() - filemtime($lockFile)) < 3600) {
            return;
        }
        @touch($lockFile);
    }

    $currentDay = (int)date('j');

    if ($currentDay >= 10) {
        $cutoffDate = date('Y-m-01 00:00:00');
        $cutoffDate = date('Y-m-01 00:00:00', strtotime($cutoffDate . ' -1 month'));
    } else {
        $cutoffDate = date('Y-m-01 00:00:00', strtotime('first day of last month'));
        $cutoffDate = date('Y-m-01 00:00:00', strtotime($cutoffDate . ' -1 month'));
    }

    if (!empty($empId)) {
        $stmt = $conn->prepare("DELETE FROM live_stream_history WHERE emp_id = ? AND captured_at < ?");
        if ($stmt) {
            $stmt->bind_param("ss", $empId, $cutoffDate);
            @$stmt->execute();
            $stmt->close();
        }
    } else {
        $stmt = $conn->prepare("DELETE FROM live_stream_history WHERE captured_at < ?");
        if ($stmt) {
            $stmt->bind_param("s", $cutoffDate);
            @$stmt->execute();
            $stmt->close();
        }
    }
}

cleanupMonthlyHistory($conn, null, false);

// ==================== REQUEST PARSING (JSON-first) ====================
$rawInput = '';
if (isset($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > 0) {
    $rawInput = file_get_contents('php://input');
}
if ($rawInput === '' || $rawInput === false) {
    $rawInput = @file_get_contents('php://input');
}

$jsonInput = [];
if (is_string($rawInput) && $rawInput !== '') {
    $decoded = json_decode($rawInput, true);
    if (is_array($decoded)) {
        $jsonInput = $decoded;
    }
}

$input = [];
if (!empty($jsonInput))                          $input = $jsonInput;
if (!empty($_POST) && is_array($_POST))          $input = array_merge($input, $_POST);
if (!empty($_GET)  && is_array($_GET))           $input = array_merge($input, $_GET);

$action = normalizeAction(
    $_GET['action']
    ?? $_POST['action']
    ?? $jsonInput['action']
    ?? $input['action']
    ?? ''
);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ==================== DEVICE RESOLUTION ====================
function fetchDeviceForUser($conn, $userEmpId, $userId) {
    $sql = "
        SELECT device_id, device_name, pc_number, last_login_at
        FROM devices
        WHERE assigned_user_id = ?
           OR emp_id = ?
        ORDER BY last_login_at DESC
        LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return null;
    $empIdVal  = $userEmpId ?? '';
    $userIdVal = (int)$userId;
    $stmt->bind_param("is", $userIdVal, $empIdVal);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $res ?: null;
}

function resolvePcInfo($userRole, $device) {
    $isManager = (strcasecmp($userRole ?? '', 'Manager') === 0);
    $pcNumber  = 'Personal PC';
    $pcType    = 'personal';

    if ($device && !empty($device['pc_number'])) {
        $raw = trim((string)$device['pc_number']);
        if ($raw !== '' && strtolower($raw) !== 'null') {
            $pcNumber = 'PC-' . $raw;
            $pcType   = 'office';
        }
    }
    if ($isManager) {
        $pcType   = 'personal';
        $pcNumber = 'Personal PC';
    }
    return [$pcType, $pcNumber];
}

// ==================== USER LOOKUP (handles NULL emp_id + name fallback) ====================
/**
 * Finds a user by emp_id (case-insensitive). If emp_id is NULL in the DB,
 * falls back to matching by provided name (only when name is given).
 */
function findUser($conn, $emp_id, $nameFallback = '') {
    $stmt = $conn->prepare("
        SELECT u.id, u.emp_id, u.name, u.email, u.mobile, u.role, u.password,
               u.is_active, u.updated_at,
               " . (in_array('last_heartbeat', $GLOBALS['usersCols']) ? "u.last_heartbeat" : "NULL AS last_heartbeat") . ",
               " . (in_array('app_running', $GLOBALS['usersCols'])    ? "u.app_running"    : "0 AS app_running") . ",
               " . (in_array('device_id', $GLOBALS['usersCols'])      ? "u.device_id"      : "NULL AS device_id") . ",
               " . (in_array('computer_name', $GLOBALS['usersCols'])  ? "u.computer_name"  : "NULL AS computer_name") . "
        FROM users u
        WHERE UPPER(u.emp_id) = ?
        LIMIT 1
    ");
    if (!$stmt) return null;
    $stmt->bind_param("s", $emp_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Fallback: match by name when emp_id is NULL in DB
    if (!$user && $nameFallback !== '') {
        $stmt2 = $conn->prepare("
            SELECT u.id, u.emp_id, u.name, u.email, u.mobile, u.role, u.password,
                   u.is_active, u.updated_at,
                   " . (in_array('last_heartbeat', $GLOBALS['usersCols']) ? "u.last_heartbeat" : "NULL AS last_heartbeat") . ",
                   " . (in_array('app_running', $GLOBALS['usersCols'])    ? "u.app_running"    : "0 AS app_running") . ",
                   " . (in_array('device_id', $GLOBALS['usersCols'])      ? "u.device_id"      : "NULL AS device_id") . ",
                   " . (in_array('computer_name', $GLOBALS['usersCols'])  ? "u.computer_name"  : "NULL AS computer_name") . "
            FROM users u
            WHERE LOWER(u.name) = LOWER(?)
            LIMIT 1
        ");
        if ($stmt2) {
            $stmt2->bind_param("s", $nameFallback);
            $stmt2->execute();
            $user = $stmt2->get_result()->fetch_assoc();
            $stmt2->close();
        }
    }
    return $user ?: null;
}

// ==================== HEARTBEAT ====================
if ($action === 'heartbeat' && $method === 'POST') {
    $emp_id = normalizeEmpId($input['emp_id'] ?? '');
    if (empty($emp_id)) sendError("Employee ID required", 400);

    $setParts = [];
    $setParts[] = "updated_at = NOW()";
    if (in_array('last_heartbeat', $usersCols)) $setParts[] = "last_heartbeat = NOW()";
    if (in_array('app_running', $usersCols))    $setParts[] = "app_running = 1";

    $sql = "UPDATE users SET " . implode(", ", $setParts) . " WHERE UPPER(emp_id) = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) sendError("DB prepare failed: " . $conn->error, 500);
    $stmt->bind_param("s", $emp_id);
    $stmt->execute();
    $stmt->close();

    sendResponse(["status" => "success", "message" => "Heartbeat received"]);
}

// ==================== APP SHUTDOWN ====================
if ($action === 'app_shutdown' && $method === 'POST') {
    $emp_id = normalizeEmpId($input['emp_id'] ?? '');
    if (empty($emp_id)) sendError("Employee ID required", 400);

    $setParts = ["updated_at = NOW()"];
    if (in_array('app_running', $usersCols)) $setParts[] = "app_running = 0";

    $sql = "UPDATE users SET " . implode(", ", $setParts) . " WHERE UPPER(emp_id) = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) sendError("DB prepare failed: " . $conn->error, 500);
    $stmt->bind_param("s", $emp_id);
    $stmt->execute();
    $stmt->close();

    sendResponse(["status" => "success", "message" => "App shutdown noted"]);
}

// ==================== VERIFY PC TYPE ====================
if ($action === 'verify_pc_type' && $method === 'POST') {
    $emp_id  = normalizeEmpId($input['emp_id'] ?? '');
    $pc_type = $input['pc_type'] ?? 'personal';
    if (empty($emp_id)) sendError("Employee ID required", 400);

    $user = findUser($conn, $emp_id);

    $pcNumber    = 'Personal PC';
    $finalPcType = 'personal';
    $devicePcNum = null;

    if ($user) {
        $device = fetchDeviceForUser($conn, $user['emp_id'], $user['id']);
        if ($pc_type === 'office') {
            if ($device && !empty($device['pc_number'])) {
                $devicePcNum = trim((string)$device['pc_number']);
            } elseif (!empty($user['computer_name'])) {
                $devicePcNum = $user['computer_name'];
            } elseif (!empty($user['device_id'])) {
                $devicePcNum = substr($user['device_id'], -4);
            }
            if (!empty($devicePcNum) && strtolower($devicePcNum) !== 'null') {
                $pcNumber    = 'PC-' . $devicePcNum;
                $finalPcType = 'office';
            } else {
                $pcNumber    = 'Office-PC';
                $finalPcType = 'office';
            }
        }
    }

    // Build safe INSERT — only set columns that exist
    $cols = ['emp_id', 'status'];
    $vals = ['?', "'idle'"];
    $types = 's';
    $bind = [$emp_id];

    if (in_array('pc_type', $liveStreamCols))   { $cols[] = 'pc_type';   $vals[] = '?'; $types .= 's'; $bind[] = $finalPcType; }
    if (in_array('pc_number', $liveStreamCols)) { $cols[] = 'pc_number'; $vals[] = '?'; $types .= 's'; $bind[] = $pcNumber; }
    if (in_array('updated_at', $liveStreamCols)){ $cols[] = 'updated_at';$vals[] = 'NOW()'; }

    $sql = "INSERT INTO live_stream (" . implode(',', $cols) . ")
            VALUES (" . implode(',', $vals) . ")
            ON DUPLICATE KEY UPDATE ";
    $updates = [];
    if (in_array('pc_type', $liveStreamCols))   $updates[] = "pc_type = VALUES(pc_type)";
    if (in_array('pc_number', $liveStreamCols)) $updates[] = "pc_number = VALUES(pc_number)";
    if (in_array('updated_at', $liveStreamCols))$updates[] = "updated_at = NOW()";
    $sql .= implode(', ', $updates);

    $stmt = $conn->prepare($sql);
    if (!$stmt) sendError("DB prepare failed: " . $conn->error, 500);
    $stmt->bind_param($types, ...$bind);
    @$stmt->execute();
    $stmt->close();

    sendResponse([
        "status"    => "success",
        "pc_type"   => $finalPcType,
        "pc_number" => $pcNumber
    ]);
}

// ==================== 1. SEND LIVE REQUEST ====================
if ($action === 'send_live_request' && $method === 'POST') {
    $emp_id = normalizeEmpId($input['emp_id'] ?? '');
    if (empty($emp_id)) sendError("Employee ID required", 400);

    $userRes = findUser($conn, $emp_id);
    if (!$userRes) sendError("Employee not found in users table", 404);

    $device = fetchDeviceForUser($conn, $userRes['emp_id'], $userRes['id']);
    list($pcType, $displayPc) = resolvePcInfo($userRes['role'], $device);

    // Check existing live_stream row
    $existingStmt = $conn->prepare("SELECT pc_type, pc_number FROM live_stream WHERE emp_id = ? LIMIT 1");
    if ($existingStmt) {
        $existingStmt->bind_param("s", $emp_id);
        $existingStmt->execute();
        $existing = $existingStmt->get_result()->fetch_assoc();
        $existingStmt->close();
        if ($existing && !empty($existing['pc_type'])) {
            $pcType    = $existing['pc_type'];
            $displayPc = $existing['pc_number'];
        }
    }

    // Build safe insert
    $cols = ['emp_id', 'status'];
    $vals = ['?', "'requested'"];
    $types = 's';
    $bind = [$emp_id];

    if (in_array('image_base64', $liveStreamCols))  { $cols[] = 'image_base64';  $vals[] = 'NULL'; }
    if (in_array('captured_at', $liveStreamCols))   { $cols[] = 'captured_at';   $vals[] = 'NOW()'; }
    if (in_array('updated_at', $liveStreamCols))    { $cols[] = 'updated_at';    $vals[] = 'NOW()'; }
    if (in_array('pc_type', $liveStreamCols))       { $cols[] = 'pc_type';       $vals[] = '?'; $types .= 's'; $bind[] = $pcType; }
    if (in_array('pc_number', $liveStreamCols))     { $cols[] = 'pc_number';     $vals[] = '?'; $types .= 's'; $bind[] = $displayPc; }
    if (in_array('frame_counter', $liveStreamCols)) { $cols[] = 'frame_counter'; $vals[] = '0'; }

    $sql = "INSERT INTO live_stream (" . implode(',', $cols) . ")
            VALUES (" . implode(',', $vals) . ")
            ON DUPLICATE KEY UPDATE
                status = 'requested',
                " . (in_array('image_base64', $liveStreamCols)   ? "image_base64 = NULL," : "") . "
                " . (in_array('captured_at', $liveStreamCols)    ? "captured_at = NOW()," : "") . "
                " . (in_array('updated_at', $liveStreamCols)     ? "updated_at = NOW()," : "") . "
                " . (in_array('frame_counter', $liveStreamCols)  ? "frame_counter = 0," : "") . "
                " . (in_array('pc_type', $liveStreamCols)        ? "pc_type = VALUES(pc_type)," : "") . "
                " . (in_array('pc_number', $liveStreamCols)      ? "pc_number = VALUES(pc_number)," : "") . "
                emp_id = emp_id";

    $stmt = $conn->prepare($sql);
    if (!$stmt) sendError("DB prepare failed: " . $conn->error, 500);
    $stmt->bind_param($types, ...$bind);
    $stmt->execute();
    $stmt->close();

    // Insert into live_requests (build safely)
    if (!empty($liveReqCols)) {
        $lrCols = ['emp_id', 'status'];
        $lrVals = ['?', "'pending'"];
        $lrTypes = 's';
        $lrBind = [$emp_id];

        if (in_array('requested_at', $liveReqCols)) { $lrCols[] = 'requested_at'; $lrVals[] = 'NOW()'; }
        if (in_array('updated_at', $liveReqCols))   { $lrCols[] = 'updated_at';   $lrVals[] = 'NOW()'; }

        $lrSql = "INSERT INTO live_requests (" . implode(',', $lrCols) . ")
                  VALUES (" . implode(',', $lrVals) . ")";
        $lrStmt = $conn->prepare($lrSql);
        if ($lrStmt) {
            $lrStmt->bind_param($lrTypes, ...$lrBind);
            @$lrStmt->execute();
            $lrStmt->close();
        }
    }

    sendResponse([
        "status"    => "success",
        "message"   => "Live request sent successfully",
        "emp_id"    => $emp_id,
        "emp_name"  => $userRes['name'],
        "pc_number" => $displayPc,
        "pc_type"   => $pcType
    ]);
}

// ==================== 2. CHECK LIVE REQUEST ====================
if ($action === 'check_live_request' && $method === 'GET') {
    $emp_id = normalizeEmpId($_GET['emp_id'] ?? '');
    if (empty($emp_id)) sendResponse(["status" => "idle"]);

    $stmt = $conn->prepare("SELECT status, updated_at FROM live_stream WHERE emp_id = ? LIMIT 1");
    if (!$stmt) sendError("DB prepare failed: " . $conn->error, 500);
    $stmt->bind_param("s", $emp_id);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($res && $res['status'] === 'requested') {
        $requestTime = strtotime($res['updated_at']);
        if ((time() - $requestTime) > 60) {
            $upStmt = $conn->prepare("UPDATE live_stream SET status = 'idle' WHERE emp_id = ?");
            if ($upStmt) {
                $upStmt->bind_param("s", $emp_id);
                $upStmt->execute();
                $upStmt->close();
            }
            sendResponse(["status" => "idle"]);
        }
    }
    sendResponse(["status" => $res['status'] ?? 'idle']);
}

// ==================== 3. UPLOAD SCREEN FRAME ====================
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

    cleanupMonthlyHistory($conn, $emp_id, false);

    if (strpos($image_base64, 'base64,') !== false) {
        $image_base64 = substr($image_base64, strpos($image_base64, 'base64,') + 7);
    }

    // Build dynamic INSERT
    $cols  = ['emp_id', 'status', 'image_base64'];
    $vals  = ['?', "'streaming'", '?'];
    $types = 'ss';
    $bind  = [$emp_id, $image_base64];

    if (in_array('captured_at', $liveStreamCols))   { $cols[] = 'captured_at';   $vals[] = 'NOW()'; }
    if (in_array('updated_at', $liveStreamCols))    { $cols[] = 'updated_at';    $vals[] = 'NOW()'; }
    if (in_array('window_title', $liveStreamCols))  { $cols[] = 'window_title';  $vals[] = '?'; $types .= 's'; $bind[] = $window_title; }
    if (in_array('pc_type', $liveStreamCols))       { $cols[] = 'pc_type';       $vals[] = '?'; $types .= 's'; $bind[] = $pc_type; }
    if (in_array('pc_number', $liveStreamCols))     { $cols[] = 'pc_number';     $vals[] = '?'; $types .= 's'; $bind[] = $pc_number; }
    if (in_array('frame_counter', $liveStreamCols)) { $cols[] = 'frame_counter'; $vals[] = '1'; }

    $updateParts = ["status = 'streaming'", "image_base64 = VALUES(image_base64)"];
    if (in_array('captured_at', $liveStreamCols))   $updateParts[] = "captured_at = NOW()";
    if (in_array('updated_at', $liveStreamCols))    $updateParts[] = "updated_at = NOW()";
    if (in_array('window_title', $liveStreamCols))  $updateParts[] = "window_title = VALUES(window_title)";
    if (in_array('pc_type', $liveStreamCols))       $updateParts[] = "pc_type = VALUES(pc_type)";
    if (in_array('pc_number', $liveStreamCols))     $updateParts[] = "pc_number = VALUES(pc_number)";
    if (in_array('frame_counter', $liveStreamCols)) $updateParts[] = "frame_counter = frame_counter + 1";

    $sql = "INSERT INTO live_stream (" . implode(',', $cols) . ")
            VALUES (" . implode(',', $vals) . ")
            ON DUPLICATE KEY UPDATE " . implode(', ', $updateParts);

    $stmt = $conn->prepare($sql);
    if (!$stmt) sendError("DB prepare failed: " . $conn->error, 500);
    $stmt->bind_param($types, ...$bind);
    if (!$stmt->execute()) {
        sendError("Failed to save frame: " . ($stmt->error ?? 'unknown'), 500);
    }
    $stmt->close();

    if ($save_history) {
        $histStmt = $conn->prepare("
            INSERT INTO live_stream_history (emp_id, image_base64, captured_at, window_title)
            VALUES (?, ?, NOW(), ?)
        ");
        if ($histStmt) {
            $histStmt->bind_param("sss", $emp_id, $image_base64, $window_title);
            @$histStmt->execute();
            $histStmt->close();
        }

        $cleanupStmt = $conn->prepare("
            DELETE FROM live_stream_history
            WHERE emp_id = ?
              AND id NOT IN (
                SELECT id FROM (
                  SELECT id FROM live_stream_history
                  WHERE emp_id = ?
                  ORDER BY captured_at DESC
                  LIMIT 2000
                ) AS keep_ids
              )
        ");
        if ($cleanupStmt) {
            $cleanupStmt->bind_param("ss", $emp_id, $emp_id);
            @$cleanupStmt->execute();
            $cleanupStmt->close();
        }
    }

    $escaped = $conn->real_escape_string($emp_id);
    @$conn->query("UPDATE users SET updated_at = NOW() WHERE UPPER(emp_id) = '$escaped'");

    $cntStmt = $conn->prepare("SELECT frame_counter FROM live_stream WHERE emp_id = ? LIMIT 1");
    $fc = 0;
    if ($cntStmt) {
        $cntStmt->bind_param("s", $emp_id);
        $cntStmt->execute();
        $cntRes = $cntStmt->get_result()->fetch_assoc();
        $fc = (int)($cntRes['frame_counter'] ?? 0);
        $cntStmt->close();
    }

    sendResponse([
        "status"        => "success",
        "size"          => strlen($image_base64),
        "frame_counter" => $fc,
        "captured_at"   => date('Y-m-d H:i:s')
    ]);
}

// ==================== 4. GET LATEST LIVE STREAM ====================
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
    if (!$stmt) sendError("DB prepare failed: " . $conn->error, 500);
    $stmt->bind_param("s", $emp_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($result && !empty($result['image_base64']) && $result['status'] === 'streaming') {
        $age = time() - strtotime($result['captured_at']);
        if ($age > 180) {
            sendResponse(["status" => "stale", "message" => "Stream is stale", "age" => $age]);
        }
        sendResponse([
            "status"        => "success",
            "id"            => (int)$result['id'],
            "image_base64"  => $result['image_base64'],
            "captured_at"   => $result['captured_at'],
            "updated_at"    => $result['updated_at'],
            "stream_status" => $result['status'],
            "window_title"  => $result['window_title'],
            "pc_type"       => $result['pc_type'],
            "pc_number"     => $result['pc_number'],
            "frame_counter" => (int)($result['frame_counter'] ?? 0),
            "age"           => $age
        ]);
    } else if ($result && $result['status'] === 'requested') {
        sendResponse(["status" => "waiting", "message" => "Waiting for employee to accept..."]);
    } else {
        sendResponse(["status" => "waiting", "message" => "Waiting for live screen..."]);
    }
}

// ==================== 5. GET FRAME HISTORY ====================
if ($action === 'get_frame_history' && $method === 'GET') {
    $emp_id = normalizeEmpId($_GET['emp_id'] ?? '');
    $limit  = intval($_GET['limit'] ?? 100);
    if ($limit < 1 || $limit > 500) $limit = 100;
    if (empty($emp_id)) sendError("Employee ID required", 400);

    cleanupMonthlyHistory($conn, $emp_id, false);

    $stmt = $conn->prepare("
        SELECT id, image_base64, captured_at, window_title
        FROM live_stream_history
        WHERE emp_id = ? AND image_base64 IS NOT NULL
        ORDER BY captured_at DESC
        LIMIT ?
    ");
    if (!$stmt) sendError("DB prepare failed: " . $conn->error, 500);
    $stmt->bind_param("si", $emp_id, $limit);
    $stmt->execute();
    $res = $stmt->get_result();

    $frames = [];
    $idx = 0;
    while ($row = $res->fetch_assoc()) {
        $idx++;
        $frames[] = [
            "id"           => $idx,
            "db_id"        => (int)$row['id'],
            "image_base64" => $row['image_base64'],
            "captured_at"  => $row['captured_at'],
            "window_title" => $row['window_title']
        ];
    }
    $stmt->close();

    $userRow = findUser($conn, $emp_id);
    $deviceId   = 'N/A';
    $deviceName = 'N/A';
    $pcDisplay  = 'Personal PC';
    $pcType     = 'personal';

    if ($userRow) {
        $device = fetchDeviceForUser($conn, $userRow['emp_id'], $userRow['id']);
        if ($device) {
            $deviceId   = $device['device_id']   ?? 'N/A';
            $deviceName = $device['device_name'] ?? 'N/A';
            list($pcType, $pcDisplay) = resolvePcInfo($userRow['role'], $device);
        } elseif (!empty($userRow['device_id'])) {
            $deviceId   = $userRow['device_id'];
            $deviceName = $userRow['computer_name'] ?? 'N/A';
        }
    }

    sendResponse([
        "status" => "success",
        "count"  => count($frames),
        "frames" => $frames,
        "device" => [
            "pc_number"     => $pcDisplay,
            "pc_type"       => $pcType,
            "device_id"     => $deviceId,
            "computer_name" => $deviceName
        ]
    ]);
}

// ==================== 6. GET VIDEO PLAYBACK ====================
if ($action === 'get_video_playback' && $method === 'GET') {
    $emp_id = normalizeEmpId($_GET['emp_id'] ?? '');
    $limit  = intval($_GET['limit'] ?? 500);
    if ($limit < 1 || $limit > 1000) $limit = 500;
    if (empty($emp_id)) sendError("Employee ID required", 400);

    cleanupMonthlyHistory($conn, $emp_id, false);

    $stmt = $conn->prepare("
        SELECT id, image_base64, captured_at, window_title
        FROM live_stream_history
        WHERE emp_id = ? AND image_base64 IS NOT NULL
        ORDER BY captured_at ASC
        LIMIT ?
    ");
    if (!$stmt) sendError("DB prepare failed: " . $conn->error, 500);
    $stmt->bind_param("si", $emp_id, $limit);
    $stmt->execute();
    $res = $stmt->get_result();

    $frames = [];
    $idx = 0;
    while ($row = $res->fetch_assoc()) {
        $idx++;
        $frames[] = [
            "id"           => $idx,
            "db_id"        => (int)$row['id'],
            "image_base64" => $row['image_base64'],
            "captured_at"  => $row['captured_at'],
            "window_title" => $row['window_title']
        ];
    }
    $stmt->close();

    $userRow = findUser($conn, $emp_id);
    $deviceId   = 'N/A';
    $deviceName = 'N/A';
    $pcDisplay  = 'Personal PC';
    $pcType     = 'personal';

    if ($userRow) {
        $device = fetchDeviceForUser($conn, $userRow['emp_id'], $userRow['id']);
        if ($device) {
            $deviceId   = $device['device_id']   ?? 'N/A';
            $deviceName = $device['device_name'] ?? 'N/A';
            list($pcType, $pcDisplay) = resolvePcInfo($userRow['role'], $device);
        } elseif (!empty($userRow['device_id'])) {
            $deviceId   = $userRow['device_id'];
            $deviceName = $userRow['computer_name'] ?? 'N/A';
        }
    }

    $liveStmt = $conn->prepare("
        SELECT image_base64, captured_at, window_title, status
        FROM live_stream
        WHERE emp_id = ? AND status = 'streaming' AND image_base64 IS NOT NULL
        LIMIT 1
    ");
    $liveFrame = null;
    if ($liveStmt) {
        $liveStmt->bind_param("s", $emp_id);
        $liveStmt->execute();
        $liveFrame = $liveStmt->get_result()->fetch_assoc();
        $liveStmt->close();
    }

    if ($liveFrame && !empty($liveFrame['image_base64'])) {
        $lastHistoryTime = !empty($frames) ? end($frames)['captured_at'] : null;
        if ($lastHistoryTime !== $liveFrame['captured_at']) {
            $idx++;
            $frames[] = [
                "id"           => $idx,
                "db_id"        => -1,
                "image_base64" => $liveFrame['image_base64'],
                "captured_at"  => $liveFrame['captured_at'],
                "window_title" => $liveFrame['window_title'],
                "is_live"      => true
            ];
        }
    }

    sendResponse([
        "status" => "success",
        "count"  => count($frames),
        "frames" => $frames,
        "device" => [
            "pc_number"     => $pcDisplay,
            "pc_type"       => $pcType,
            "device_id"     => $deviceId,
            "computer_name" => $deviceName
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
            if ($stmt) {
                $stmt->bind_param("s", $emp_id);
                $stmt->execute();
                $stmt->close();
            }
        } else {
            $stmt = $conn->prepare("DELETE FROM live_stream WHERE emp_id = ?");
            if ($stmt) {
                $stmt->bind_param("s", $emp_id);
                $stmt->execute();
                $stmt->close();
            }
            $stmt2 = $conn->prepare("DELETE FROM live_stream_history WHERE emp_id = ?");
            if ($stmt2) {
                $stmt2->bind_param("s", $emp_id);
                $stmt2->execute();
                $stmt2->close();
            }
        }

        $lr = $conn->prepare("UPDATE live_requests SET status = 'closed', updated_at = NOW() WHERE emp_id = ? AND status = 'pending'");
        if ($lr) {
            $lr->bind_param("s", $emp_id);
            @$lr->execute();
            $lr->close();
        }
    }

    sendResponse(["status" => "success", "message" => "Stream stopped"]);
}

// ==================== 8. CLEAR HISTORY ====================
if ($action === 'clear_history' && $method === 'POST') {
    $emp_id = normalizeEmpId($input['emp_id'] ?? '');
    if (!empty($emp_id)) {
        $stmt = $conn->prepare("DELETE FROM live_stream_history WHERE emp_id = ?");
        if ($stmt) {
            $stmt->bind_param("s", $emp_id);
            $stmt->execute();
            $stmt->close();
        }
    }
    sendResponse(["status" => "success", "message" => "History cleared"]);
}

// ==================== 9. VERIFY EMPLOYEE (GET + POST + JSON) ====================
if ($action === 'verify_employee' && ($method === 'GET' || $method === 'POST')) {

    $emp_id   = normalizeEmpId($input['emp_id'] ?? $_GET['emp_id'] ?? '');
    $emp_name = trim((string)($input['emp_name'] ?? $_GET['emp_name'] ?? ''));
    $email    = trim((string)($input['email']    ?? $_GET['email']    ?? ''));
    $role     = trim((string)($input['role']     ?? $_GET['role']     ?? ''));
    $mobile   = trim((string)($input['mobile']   ?? $_GET['mobile']   ?? ''));
    $pwdPlain = (string)($input['password']      ?? $_GET['password'] ?? '');

    if (empty($emp_id) && empty($emp_name)) sendError("Employee ID required", 400);

    $user = null;
    if (!empty($emp_id))   $user = findUser($conn, $emp_id, $emp_name);
    elseif (!empty($emp_name)) $user = findUser($conn, '', $emp_name);

    if (!$user) {
        sendResponse([
            "status"    => "not_found",
            "message"   => "Employee not found",
            "is_online" => false,
            "is_active" => false
        ]);
    }

    // Password check
    $passwordChecked = false;
    $passwordValid   = false;
    if ($pwdPlain !== '') {
        $passwordChecked = true;
        $storedHash = $user['password'] ?? '';
        if (!empty($storedHash) && password_verify($pwdPlain, $storedHash)) {
            $passwordValid = true;
        }
    }

    // Identity cross-check
    $identityMismatch = false;
    if ($emp_name !== '' && strcasecmp(trim($user['name']), $emp_name) !== 0) $identityMismatch = true;
    if ($email    !== '' && strcasecmp(trim((string)$user['email']), $email) !== 0) $identityMismatch = true;
    if ($role     !== '' && strcasecmp(trim((string)$user['role']), $role)   !== 0) $identityMismatch = true;
    if ($mobile   !== '' && trim((string)$user['mobile']) !== $mobile)             $identityMismatch = true;

    $lastHeartbeat = strtotime($user['last_heartbeat'] ?? '2000-01-01');
    $isAppActive   = (time() - $lastHeartbeat) < 30 && ((int)$user['app_running'] === 1);
    $isUserActive  = ((int)$user['is_active'] === 1);

    // Fetch live_stream + devices
    $device = null;
    if (!empty($user['emp_id'])) {
        $device = fetchDeviceForUser($conn, $user['emp_id'], $user['id']);
    }

    $lsStmt = $conn->prepare("SELECT pc_type, pc_number FROM live_stream WHERE emp_id = ? LIMIT 1");
    $lsRow = null;
    if ($lsStmt) {
        $lsStmt->bind_param("s", $emp_id);
        $lsStmt->execute();
        $lsRow = $lsStmt->get_result()->fetch_assoc();
        $lsStmt->close();
    }

    // Determine pc info
    $pcDisplay = 'Personal PC';
    $pcType    = 'personal';
    if ($lsRow && !empty($lsRow['pc_type']) && !empty($lsRow['pc_number'])) {
        $pcType    = $lsRow['pc_type'];
        $pcDisplay = $lsRow['pc_number'];
    } elseif ($device && !empty($device['pc_number'])) {
        $pcDisplay = 'PC-' . trim((string)$device['pc_number']);
        $pcType    = 'office';
    }

    $finalDeviceId   = !empty($user['device_id'])     ? $user['device_id']
                     : (!empty($device['device_id'])  ? $device['device_id'] : 'N/A');
    $finalDeviceName = !empty($user['computer_name']) ? $user['computer_name']
                     : (!empty($device['device_name']) ? $device['device_name'] : 'N/A');

    sendResponse([
        "status"            => "success",
        "verified"          => true,
        "password_checked"  => $passwordChecked,
        "password_valid"    => $passwordChecked ? $passwordValid : null,
        "identity_mismatch" => $identityMismatch,
        "is_online"         => $isAppActive,
        "is_active"         => $isAppActive,
        "is_enabled"        => $isUserActive,
        "last_heartbeat"    => $user['last_heartbeat'],
        "employee" => [
            "id"             => (int)$user['id'],
            "emp_id"         => $user['emp_id'],
            "name"           => $user['name'],
            "email"          => $user['email'],
            "mobile"         => $user['mobile'],
            "role"           => $user['role'],
            "pc_number"      => $pcDisplay,
            "pc_type"        => $pcType,
            "device_id"      => $finalDeviceId,
            "computer_name"  => $finalDeviceName,
            "is_active"      => (int)$user['is_active'],
            "is_enabled"     => $isUserActive,
            "is_app_active"  => $isAppActive,
            "last_seen"      => $user['updated_at'],
            "last_heartbeat" => $user['last_heartbeat']
        ]
    ]);
}

// ==================== 10. GET ALL ACTIVE STREAMS ====================
if ($action === 'get_active_streams' && $method === 'GET') {
    $stmt = $conn->prepare("
        SELECT ls.emp_id, ls.status, ls.captured_at, ls.updated_at,
               ls.pc_type, ls.pc_number,
               u.name, u.device_id,
               d.pc_number AS office_pc_number
        FROM live_stream ls
        LEFT JOIN users u ON UPPER(u.emp_id) = ls.emp_id
        LEFT JOIN devices d ON d.device_id = u.device_id
        WHERE ls.status IN ('streaming', 'requested')
        ORDER BY ls.updated_at DESC
    ");
    if (!$stmt) sendError("DB prepare failed: " . $conn->error, 500);
    $stmt->execute();
    $res = $stmt->get_result();

    $streams = [];
    while ($row = $res->fetch_assoc()) {
        $pcDisplay = $row['pc_number'] ?? 'Personal PC';
        if ($pcDisplay === 'Personal PC' && !empty($row['office_pc_number'])) {
            $pcDisplay = 'PC-' . $row['office_pc_number'];
        }
        $streams[] = [
            "emp_id"      => $row['emp_id'],
            "status"      => $row['status'],
            "captured_at" => $row['captured_at'],
            "updated_at"  => $row['updated_at'],
            "name"        => $row['name'] ?? 'Unknown',
            "pc_number"   => $pcDisplay,
            "pc_type"     => $row['pc_type'] ?? 'personal'
        ];
    }
    $stmt->close();

    sendResponse(["status" => "success", "streams" => $streams]);
}

// ==================== 11. LIST LIVE REQUESTS ====================
if ($action === 'list_live_requests' && $method === 'GET') {
    $emp_id = normalizeEmpId($_GET['emp_id'] ?? '');
    $limit  = intval($_GET['limit'] ?? 100);
    if ($limit < 1 || $limit > 500) $limit = 100;

    if (!empty($emp_id)) {
        $stmt = $conn->prepare("
            SELECT id, emp_id, status, requested_at, updated_at
            FROM live_requests
            WHERE emp_id = ?
            ORDER BY requested_at DESC
            LIMIT ?
        ");
        if (!$stmt) sendError("DB prepare failed: " . $conn->error, 500);
        $stmt->bind_param("si", $emp_id, $limit);
    } else {
        $stmt = $conn->prepare("
            SELECT id, emp_id, status, requested_at, updated_at
            FROM live_requests
            ORDER BY requested_at DESC
            LIMIT ?
        ");
        if (!$stmt) sendError("DB prepare failed: " . $conn->error, 500);
        $stmt->bind_param("i", $limit);
    }
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = [
            "id"           => (int)$row['id'],
            "emp_id"       => $row['emp_id'],
            "status"       => $row['status'],
            "requested_at" => $row['requested_at'],
            "updated_at"   => $row['updated_at']
        ];
    }
    $stmt->close();

    sendResponse(["status" => "success", "count" => count($rows), "requests" => $rows]);
}

// ==================== 12. UPDATE LIVE REQUEST STATUS ====================
if ($action === 'update_live_request' && $method === 'POST') {
    $id     = intval($input['id'] ?? 0);
    $status = trim((string)($input['status'] ?? ''));

    if ($id <= 0 || $status === '') sendError("id and status required", 400);

    $allowed = ['pending', 'accepted', 'rejected', 'closed'];
    if (!in_array($status, $allowed, true)) {
        sendError("Invalid status. Allowed: " . implode(', ', $allowed), 400);
    }

    $stmt = $conn->prepare("UPDATE live_requests SET status = ?, updated_at = NOW() WHERE id = ?");
    if (!$stmt) sendError("DB prepare failed: " . $conn->error, 500);
    $stmt->bind_param("si", $status, $id);
    $stmt->execute();
    $affected = 0;
    if ($dbDriver === 'mysqli') {
        $affected = $stmt->affected_rows;
    }
    $stmt->close();

    sendResponse([
        "status"   => "success",
        "message"  => "Live request updated",
        "affected" => $affected
    ]);
}

// ==================== FALLBACK ====================
$conn->close();
sendError(
    "Invalid endpoint request. Received action='" . $action . "', method='" . $method . "'. " .
    "Available actions: heartbeat, app_shutdown, verify_pc_type, send_live_request, " .
    "check_live_request, upload_screen_frame, get_live_stream, get_frame_history, " .
    "get_video_playback, stop_live_stream, clear_history, verify_employee, " .
    "get_active_streams, list_live_requests, update_live_request",
    400
);
?>