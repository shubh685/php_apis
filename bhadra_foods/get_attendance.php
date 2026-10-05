<?php
// Hide PHP errors from direct output (log them instead)
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli("localhost", "root", "", "bhadra_foods");
    $conn->set_charset("utf8mb4");

    $emp_id_input = trim($_GET['emp_id'] ?? 'all');

    if ($emp_id_input === 'all' || $emp_id_input === '') {
        // Fetch all records — JOIN users to resolve alphanumeric emp_id
        $sql = "SELECT a.id,
                       a.emp_id AS internal_id,
                       u.emp_id AS emp_id,
                       a.role, a.photo, a.punch_type,
                       a.punch_date, a.punch_time, a.day,
                       a.created_at, a.updated_at
                FROM attendance a
                LEFT JOIN users u ON u.id = a.emp_id
                ORDER BY a.punch_date DESC, a.id DESC";

        $stmt = $conn->prepare($sql);
    } else {
        // 1. Resolve internal user ID from users table as done in punch_attendance.php
        $lookupSql = "SELECT id FROM users WHERE emp_id = ? LIMIT 1";
        $lookupStmt = $conn->prepare($lookupSql);
        $lookupStmt->bind_param("s", $emp_id_input);
        $lookupStmt->execute();
        $lookupRes = $lookupStmt->get_result();

        $internalId = null;
        if ($lookupRes->num_rows > 0) {
            $row = $lookupRes->fetch_assoc();
            $internalId = (string)$row['id'];
        }
        $lookupStmt->close();

        // 2. If user is not found in users table, check if emp_id exists directly in attendance
        if ($internalId === null) {
            $checkAttendanceSql = "SELECT COUNT(*) as count FROM attendance WHERE emp_id = ?";
            $checkStmt = $conn->prepare($checkAttendanceSql);
            $checkStmt->bind_param("s", $emp_id_input);
            $checkStmt->execute();
            $checkRes = $checkStmt->get_result()->fetch_assoc();
            $checkStmt->close();

            if (($checkRes['count'] ?? 0) == 0) {
                echo json_encode([
                    "status"  => true,
                    "message" => "No user or attendance found for emp_id: $emp_id_input",
                    "data"    => [],
                    "history" => [],
                    "count"   => 0
                ]);
                $conn->close();
                exit();
            }
        }

        // 3. Match attendance records by internal user ID or raw emp_id
        $sql = "SELECT a.id,
                       a.emp_id AS internal_id,
                       u.emp_id AS emp_id,
                       a.role, a.photo, a.punch_type,
                       a.punch_date, a.punch_time, a.day,
                       a.created_at, a.updated_at
                FROM attendance a
                LEFT JOIN users u ON u.id = a.emp_id
                WHERE a.emp_id = ? OR a.emp_id = ?
                ORDER BY a.punch_date DESC, a.id DESC";

        $stmt = $conn->prepare($sql);
        $targetId = $internalId ?? $emp_id_input;
        $stmt->bind_param("ss", $targetId, $emp_id_input);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $history = [];
    while ($row = $result->fetch_assoc()) {
        $row['id']         = (int)$row['id'];
        $row['emp_id']     = (string)($row['emp_id'] ?? $row['internal_id'] ?? '');
        $row['photo']      = $row['photo']      ?? '';
        $row['role']       = $row['role']       ?? '';
        $row['punch_type'] = $row['punch_type'] ?? '';
        $row['punch_date'] = $row['punch_date'] ?? '';
        $row['punch_time'] = $row['punch_time'] ?? '';
        $row['day']        = $row['day']        ?? '';
        $row['created_at'] = $row['created_at'] ?? '';
        $row['updated_at'] = $row['updated_at'] ?? '';
        unset($row['internal_id']);

        $history[] = $row;
    }

    $stmt->close();
    $conn->close();

    echo json_encode([
        "status"  => true,
        "message" => "Attendance fetched successfully",
        "data"    => $history,
        "history" => $history,
        "count"   => count($history)
    ]);

} catch (Throwable $e) {
    echo json_encode([
        "status"  => false,
        "message" => "Server error: " . $e->getMessage(),
        "data"    => [],
        "history" => []
    ]);
}
?>