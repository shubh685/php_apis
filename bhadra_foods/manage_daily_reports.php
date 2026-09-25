<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

$conn = new mysqli("localhost", "root", "", "bhadra_foods");

if ($conn->connect_error) {
    echo json_encode(["status" => false, "message" => "Database connection failed"]);
    exit();
}

$emp_id = $_GET['emp_id'] ?? 'all';

// Using table name: daily_reports
if ($emp_id === 'all' || empty($emp_id)) {
    $sql = "SELECT id, emp_id, firm_name, mobile, pin_code, category, product_name, price, quantity, total_amount, latitude, longitude, address, created_at FROM daily_reports ORDER BY id DESC";
    $stmt = $conn->prepare($sql);
} else {
    $sql = "SELECT id, emp_id, firm_name, mobile, pin_code, category, product_name, price, quantity, total_amount, latitude, longitude, address, created_at FROM daily_reports WHERE emp_id = ? ORDER BY id DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $emp_id);
}

if ($stmt && $stmt->execute()) {
    $result = $stmt->get_result();

    $reports = [];
    while ($row = $result->fetch_assoc()) {
        $reports[] = $row;
    }

    echo json_encode([
        "status" => true,
        "reports" => $reports,
        "data" => $reports
    ]);
    $stmt->close();
} else {
    echo json_encode([
        "status" => false,
        "message" => "Query failed: " . $conn->error
    ]);
}

$conn->close();
?>