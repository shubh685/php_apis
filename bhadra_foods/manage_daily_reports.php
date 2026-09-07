<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST");
header("Content-Type: application/json; charset=UTF-8");

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

    $stmt = $conn->prepare("SELECT id, firm_name, mobile, pin_code, category, product_name, price, quantity, total_amount, latitude, longitude, address, created_at FROM daily_reports WHERE emp_id = ? ORDER BY id DESC");
    $stmt->bind_param("s", $emp_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $reports = [];
    while ($row = $result->fetch_assoc()) {
        $reports[] = $row;
    }

    echo json_encode(["status" => "success", "reports" => $reports]);
} elseif ($method === 'POST') {
    $emp_id       = $_POST['emp_id'] ?? '';
    $firm_name    = $_POST['firm_name'] ?? '';
    $mobile       = $_POST['mobile'] ?? '';
    $pin_code     = $_POST['pin_code'] ?? '';
    $category     = $_POST['category'] ?? '';
    $product_name = $_POST['product_name'] ?? '';
    $price        = $_POST['price'] ?? 0;
    $quantity     = $_POST['quantity'] ?? 1;
    $total_amount = $_POST['total_amount'] ?? 0;
    $latitude     = $_POST['latitude'] ?? NULL;
    $longitude    = $_POST['longitude'] ?? NULL;
    $address      = $_POST['address'] ?? '';

    if (empty($emp_id) || empty($firm_name) || empty($mobile) || empty($product_name)) {
        echo json_encode(["status" => "error", "message" => "Required fields missing"]);
        exit();
    }

    $stmt = $conn->prepare("INSERT INTO daily_reports (emp_id, firm_name, mobile, pin_code, category, product_name, price, quantity, total_amount, latitude, longitude, address) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssssdiddss", $emp_id, $firm_name, $mobile, $pin_code, $category, $product_name, $price, $quantity, $total_amount, $latitude, $longitude, $address);

    if ($stmt->execute()) {
        echo json_encode(["status" => "success", "message" => "Daily report logged successfully"]);
    } else {
        echo json_encode(["status" => "error", "message" => "Failed to save daily report: " . $stmt->error]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "Invalid HTTP Method"]);
}

$conn->close();
?>