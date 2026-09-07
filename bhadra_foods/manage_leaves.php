<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$conn = new mysqli("localhost", "root", "", "bhadra_foods");

if ($conn->connect_error) {
    echo json_encode(["status" => "error", "message" => "Database connection failed"]);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $emp_id = $_GET['emp_id'] ?? '';
    if (empty($emp_id)) {
        echo json_encode(["status" => "error", "message" => "emp_id is required"]);
        exit();
    }

    $stmt = $conn->prepare("SELECT id, emp_id, leave_type, start_date, end_date, reason, status, created_at FROM leaves WHERE emp_id = ? ORDER BY id DESC");
    $stmt->bind_param("s", $emp_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $leaves = [];
    while ($row = $result->fetch_assoc()) {
        $leaves[] = $row;
    }

    echo json_encode(["status" => "success", "leaves" => $leaves]);

} elseif ($method === 'POST') {
    $emp_id = $_POST['emp_id'] ?? '';
    $leave_type = $_POST['leave_type'] ?? '';
    $start_date = $_POST['start_date'] ?? '';
    $end_date = $_POST['end_date'] ?? '';
    $reason = $_POST['reason'] ?? '';

    if (empty($emp_id) || empty($leave_type) || empty($start_date) || empty($end_date) || empty($reason)) {
        echo json_encode(["status" => "error", "message" => "All fields are required"]);
        exit();
    }

    $stmt = $conn->prepare("INSERT INTO leaves (emp_id, leave_type, start_date, end_date, reason, status) VALUES (?, ?, ?, ?, ?, 'Pending')");
    $stmt->bind_param("sssss", $emp_id, $leave_type, $start_date, $end_date, $reason);

    if ($stmt->execute()) {
        echo json_encode(["status" => "success", "message" => "Leave application submitted successfully"]);
    } else {
        echo json_encode(["status" => "error", "message" => "Failed to save leave request: " . $stmt->error]);
    }

} elseif ($method === 'PUT') {
    // For updating leave status (Approve/Reject)
    $input = json_decode(file_get_contents("php://input"), true);
    
    $leave_id = $input['leave_id'] ?? '';
    $status = $input['status'] ?? '';
    $emp_id = $input['emp_id'] ?? '';

    if (empty($leave_id) || empty($status) || empty($emp_id)) {
        echo json_encode(["status" => "error", "message" => "leave_id, emp_id and status are required"]);
        exit();
    }

    if (!in_array($status, ['Approved', 'Rejected'])) {
        echo json_encode(["status" => "error", "message" => "Invalid status. Use 'Approved' or 'Rejected'"]);
        exit();
    }

    // Verify that the leave belongs to the employee
    $verifyStmt = $conn->prepare("SELECT id FROM leaves WHERE id = ? AND emp_id = ?");
    $verifyStmt->bind_param("is", $leave_id, $emp_id);
    $verifyStmt->execute();
    $verifyResult = $verifyStmt->get_result();

    if ($verifyResult->num_rows === 0) {
        echo json_encode(["status" => "error", "message" => "Leave request not found for this employee"]);
        exit();
    }

    $stmt = $conn->prepare("UPDATE leaves SET status = ? WHERE id = ? AND emp_id = ?");
    $stmt->bind_param("sis", $status, $leave_id, $emp_id);

    if ($stmt->execute()) {
        echo json_encode([
            "status" => "success", 
            "message" => "Leave $status successfully",
            "leave_id" => $leave_id,
            "new_status" => $status
        ]);
    } else {
        echo json_encode(["status" => "error", "message" => "Failed to update leave status: " . $stmt->error]);
    }

} else {
    echo json_encode(["status" => "error", "message" => "Invalid HTTP Method"]);
}

$conn->close();
?>