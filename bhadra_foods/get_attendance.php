<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

$conn = new mysqli("localhost", "root", "", "bhadra_foods");

if ($conn->connect_error) {
    echo json_encode(["status" => false, "message" => "Database connection failed"]);
    exit();
}

$emp_id = $_GET['emp_id'] ?? 'all';

if ($emp_id === 'all' || empty($emp_id)) {
    $sql = "SELECT id, emp_id, role, photo, punch_type, punch_date, punch_time, day, created_at FROM attendance ORDER BY id DESC";
    $stmt = $conn->prepare($sql);
} else {
    $sql = "SELECT id, emp_id, role, photo, punch_type, punch_date, punch_time, day, created_at FROM attendance WHERE emp_id = ? ORDER BY id DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $emp_id);
}

$stmt->execute();
$result = $stmt->get_result();

$history = [];
while ($row = $result->fetch_assoc()) {
    $history[] = $row;
}

echo json_encode([
    "status" => true,
    "data" => $history,
    "history" => $history
]);

$conn->close();
?>