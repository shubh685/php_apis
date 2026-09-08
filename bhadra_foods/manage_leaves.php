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
    echo json_encode(["status" => false, "message" => "Database connection failed"]);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $emp_id = $_GET['emp_id'] ?? 'all';
    
    // Checks if table 'manage_leaves' or 'leaves' exists
    if ($emp_id === 'all' || empty($emp_id)) {
        $query = "SELECT l.id, l.emp_id, COALESCE(s.name, 'Staff') AS emp_name, COALESCE(s.role, 'Salesman') AS emp_role, 
                         l.leave_type, l.start_date, l.end_date, l.reason, l.status, l.created_at 
                  FROM manage_leaves l 
                  LEFT JOIN manage_salesmna s ON l.emp_id = s.emp_id 
                  ORDER BY l.id DESC";
        $stmt = $conn->prepare($query);
    } else {
        $query = "SELECT l.id, l.emp_id, COALESCE(s.name, 'Staff') AS emp_name, COALESCE(s.role, 'Salesman') AS emp_role, 
                         l.leave_type, l.start_date, l.end_date, l.reason, l.status, l.created_at 
                  FROM manage_leaves l 
                  LEFT JOIN manage_salesmna s ON l.emp_id = s.emp_id 
                  WHERE l.emp_id = ? 
                  ORDER BY l.id DESC";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $emp_id);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $leaves = [];
    while ($row = $result->fetch_assoc()) {
        $leaves[] = $row;
    }

    echo json_encode([
        "status" => true,
        "leaves" => $leaves
    ]);
} elseif ($method === 'PUT') {
    $data = json_decode(file_get_contents("php://input"), true);
    $leave_id = $data['leave_id'] ?? '';
    $status = $data['status'] ?? '';

    if (!empty($leave_id) && !empty($status)) {
        $stmt = $conn->prepare("UPDATE manage_leaves SET status = ? WHERE id = ?");
        $stmt->bind_param("si", $status, $leave_id);
        if ($stmt->execute()) {
            echo json_encode(["status" => true, "message" => "Leave updated successfully"]);
        } else {
            echo json_encode(["status" => false, "message" => "Failed to update leave"]);
        }
    } else {
        echo json_encode(["status" => false, "message" => "Invalid parameters"]);
    }
}
$conn->close();
?>