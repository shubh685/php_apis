<?php
// Prevent PHP from printing HTML warnings/errors inline
ini_set('display_errors', 0);
error_reporting(E_ALL);

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli("localhost", "root", "", "bhadra_foods");

    $emp_id = $_GET['emp_id'] ?? '';
    $date = $_GET['date'] ?? date('Y-m-d');
    $all_history = $_GET['all'] ?? 'false';

    if (empty($emp_id)) {
        echo json_encode(["status" => "error", "message" => "emp_id is required"]);
        exit();
    }

    if ($all_history === 'true') {
        $stmt = $conn->prepare("SELECT id, emp_id, role, photo, punch_type, punch_date, punch_time, day, created_at 
                                FROM attendance 
                                WHERE emp_id = ? 
                                ORDER BY id DESC LIMIT 50");
        $stmt->bind_param("s", $emp_id);
    } else {
        $stmt = $conn->prepare("SELECT id, emp_id, role, photo, punch_type, punch_date, punch_time, day, created_at 
                                FROM attendance 
                                WHERE emp_id = ? AND DATE(created_at) = ? 
                                ORDER BY id DESC");
        $stmt->bind_param("ss", $emp_id, $date);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $attendanceRecords = [];
    while ($row = $result->fetch_assoc()) {
        $attendanceRecords[] = $row;
    }

    if (!empty($attendanceRecords)) {
        echo json_encode([
            "status" => "success",
            "attendance" => $attendanceRecords[0],
            "history" => $attendanceRecords,
            "count" => count($attendanceRecords),
            "punchedIn" => in_array('PUNCH_IN', array_column($attendanceRecords, 'punch_type')),
            "punchedOut" => in_array('PUNCH_OUT', array_column($attendanceRecords, 'punch_type'))
        ]);
    } else {
        echo json_encode([
            "status" => "success",
            "attendance" => null,
            "history" => [],
            "count" => 0,
            "punchedIn" => false,
            "punchedOut" => false
        ]);
    }

    $conn->close();

} catch (Throwable $e) {
    echo json_encode([
        "status" => "error",
        "message" => "Server exception: " . $e->getMessage()
    ]);
}
?>