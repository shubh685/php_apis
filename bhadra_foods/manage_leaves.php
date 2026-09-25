<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$conn = new mysqli("localhost", "root", "", "bhadra_foods");

if ($conn->connect_error) {
    echo json_encode(["status" => false, "message" => "Database connection failed: " . $conn->connect_error]);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $emp_id = $_GET['emp_id'] ?? 'all';

    if ($emp_id === 'all' || empty($emp_id)) {
        $query = "SELECT id, emp_id, leave_type, start_date, end_date, reason, status, created_at 
                  FROM leaves 
                  ORDER BY id DESC";
        $stmt = $conn->prepare($query);
    } else {
        $query = "SELECT id, emp_id, leave_type, start_date, end_date, reason, status, created_at 
                  FROM leaves 
                  WHERE emp_id = ? 
                  ORDER BY id DESC";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $emp_id);
    }

    if ($stmt && $stmt->execute()) {
        $result = $stmt->get_result();

        $leaves = [];
        while ($row = $result->fetch_assoc()) {
            $leaves[] = $row;
        }

        echo json_encode([
            "status" => true,
            "leaves" => $leaves,
            "data" => $leaves
        ]);
        $stmt->close();
    } else {
        echo json_encode(["status" => false, "message" => "Query failed: " . $conn->error]);
    }

} elseif ($method === 'POST') {
    $data = json_decode(file_get_contents("php://input"), true);

    $emp_id = $data['emp_id'] ?? '';
    $leave_type = $data['leave_type'] ?? '';
    $start_date = $data['start_date'] ?? '';
    $end_date = $data['end_date'] ?? '';
    $reason = $data['reason'] ?? '';
    $status = $data['status'] ?? 'Pending';

    if (!empty($emp_id) && !empty($leave_type) && !empty($start_date) && !empty($end_date)) {
        $stmt = $conn->prepare("INSERT INTO leaves (emp_id, leave_type, start_date, end_date, reason, status) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssss", $emp_id, $leave_type, $start_date, $end_date, $reason, $status);

        if ($stmt->execute()) {
            echo json_encode(["status" => true, "message" => "Leave applied successfully"]);
        } else {
            echo json_encode(["status" => false, "message" => "Failed to apply leave: " . $stmt->error]);
        }
        $stmt->close();
    } else {
        echo json_encode(["status" => false, "message" => "Missing required fields"]);
    }

} elseif ($method === 'PUT') {
    $data = json_decode(file_get_contents("php://input"), true);
    $leave_id = $data['leave_id'] ?? '';
    $status = $data['status'] ?? '';

    if (!empty($leave_id) && !empty($status)) {
        $stmt = $conn->prepare("UPDATE leaves SET status = ? WHERE id = ?");
        $stmt->bind_param("si", $status, $leave_id);

        if ($stmt->execute()) {
            echo json_encode(["status" => true, "message" => "Leave updated successfully"]);
        } else {
            echo json_encode(["status" => false, "message" => "Failed to update leave: " . $stmt->error]);
        }
        $stmt->close();
    } else {
        echo json_encode(["status" => false, "message" => "Invalid parameters"]);
    }
}

$conn->close();
?>