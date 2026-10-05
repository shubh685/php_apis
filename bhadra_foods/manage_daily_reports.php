<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Content-Type: application/json; charset=UTF-8");

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$conn = new mysqli("localhost", "root", "", "bhadra_foods");

if ($conn->connect_error) {
    echo json_encode(["status" => false, "message" => "Database connection failed"]);
    exit();
}

$conn->set_charset("utf8mb4");
$method = $_SERVER['REQUEST_METHOD'];

// ============================================================
// POST — Insert new daily report OR update live location
// ============================================================
if ($method === 'POST') {

    // Read input — support both form-data and JSON body
    $input = $_POST;
    if (empty($input)) {
        $raw = file_get_contents("php://input");
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) $input = $decoded;
    }

    // ── LIVE LOCATION UPDATE BRANCH ──
    if (isset($input['update_location_only']) && $input['update_location_only']) {
        $emp_id  = $input['emp_id']    ?? '';
        $lat     = $input['latitude']  ?? 0;
        $lng     = $input['longitude'] ?? 0;
        $addr    = $input['address']   ?? '';
        $live    = $input['live_address'] ?? $addr;
        $city    = $input['city']      ?? '';
        $is_live = $input['is_live']   ?? 1;

        if (empty($emp_id)) {
            echo json_encode(["status" => false, "message" => "emp_id is required for location update"]);
            exit();
        }

        if (empty($city) && !empty($addr)) {
            $city = $addr;
        }

        $sql = "UPDATE users 
                SET latitude = ?, longitude = ?, address = ?, live_address = ?, 
                    city = ?, is_live = ?, last_updated = NOW()
                WHERE emp_id = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            echo json_encode(["status" => false, "message" => "Prepare failed: " . $conn->error]);
            exit();
        }
        $stmt->bind_param("ddsssis", $lat, $lng, $addr, $live, $city, $is_live, $emp_id);
        $stmt->execute();
        echo json_encode(["status" => true, "message" => "Live location updated", "address" => $addr]);
        $stmt->close();
        $conn->close();
        exit();
    }

    // ── STANDARD DAILY REPORT INSERT ──
    $emp_id       = trim($input['emp_id']       ?? '');
    $firm_name    = trim($input['firm_name']    ?? '');
    $address      = trim($input['address']      ?? '');
    $mobile       = trim($input['mobile']       ?? '');
    $pin_code     = trim($input['pin_code']     ?? '');
    $category     = trim($input['category']     ?? '');
    $product_name = trim($input['product_name'] ?? '');
    $price        = $input['price']        ?? 0;
    $quantity     = $input['quantity']     ?? 1;
    $total_amount = $input['total_amount'] ?? 0;

    // ❌ NO latitude/longitude stored for daily reports (per requirement)

    if (empty($emp_id) || empty($firm_name)) {
        echo json_encode(["status" => false, "message" => "emp_id and firm_name are required"]);
        exit();
    }

    // ✅ INSERT (no latitude/longitude columns)
    $sql = "INSERT INTO daily_reports 
            (emp_id, firm_name, address, mobile, pin_code, category, product_name, 
             price, quantity, total_amount, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo json_encode(["status" => false, "message" => "Prepare failed: " . $conn->error]);
        exit();
    }

    // Types: emp_id(s), firm_name(s), address(s), mobile(s), pin_code(s),
    //        category(s), product_name(s), price(d), quantity(d), total(d)
    $stmt->bind_param(
        "sssssssddd",
        $emp_id,
        $firm_name,
        $address,
        $mobile,
        $pin_code,
        $category,
        $product_name,
        $price,
        $quantity,
        $total_amount
    );

    if ($stmt->execute()) {
        echo json_encode([
            "status"  => true,
            "message" => "Daily report saved successfully",
            "id"      => $stmt->insert_id
        ]);
    } else {
        echo json_encode(["status" => false, "message" => "Insert failed: " . $stmt->error]);
    }

    $stmt->close();
    $conn->close();
    exit();
}

// ============================================================
// GET — Verify firm by firm_name + mobile + pin_code + address
// Used by the "Verify Firm" button in Flutter
// ============================================================
if ($method === 'GET' && isset($_GET['action']) && $_GET['action'] === 'verify') {
    $emp_id    = trim($_GET['emp_id']    ?? '');
    $firm_name = trim($_GET['firm_name'] ?? '');
    $mobile    = trim($_GET['mobile']    ?? '');
    $pin_code  = trim($_GET['pin_code']  ?? '');
    $address   = trim($_GET['address']   ?? '');

    if (empty($firm_name)) {
        echo json_encode(["status" => false, "exists" => false,
            "message" => "firm_name is required"]);
        exit();
    }

    // Build query dynamically based on which fields are provided
    $conditions = ["firm_name = ?"];
    $params = [$firm_name];
    $types = "s";

    if (!empty($mobile)) {
        $conditions[] = "mobile = ?";
        $params[] = $mobile;
        $types .= "s";
    }
    if (!empty($pin_code)) {
        $conditions[] = "pin_code = ?";
        $params[] = $pin_code;
        $types .= "s";
    }
    if (!empty($address)) {
        $conditions[] = "address = ?";
        $params[] = $address;
        $types .= "s";
    }
    if (!empty($emp_id)) {
        $conditions[] = "emp_id = ?";
        $params[] = $emp_id;
        $types .= "s";
    }

    $where = implode(" AND ", $conditions);
    $sql = "SELECT id, emp_id, firm_name, address, mobile, pin_code, 
                   category, product_name, price, quantity, total_amount, created_at
            FROM daily_reports 
            WHERE $where 
            ORDER BY id DESC LIMIT 1";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo json_encode(["status" => false, "exists" => false,
            "message" => "Prepare failed: " . $conn->error]);
        exit();
    }

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        echo json_encode([
            "status" => true,
            "exists" => true,
            "id"     => $row['id'],
            "firm"   => $row
        ]);
    } else {
        echo json_encode(["status" => true, "exists" => false, "id" => null]);
    }

    $stmt->close();
    $conn->close();
    exit();
}

// ============================================================
// GET — Check firm exists by name only (used by order save)
// ============================================================
if ($method === 'GET' && isset($_GET['action']) && $_GET['action'] === 'check_firm') {
    $emp_id    = trim($_GET['emp_id']    ?? '');
    $firm_name = trim($_GET['firm_name'] ?? '');

    if (empty($firm_name)) {
        echo json_encode(["status" => false, "exists" => false,
            "message" => "firm_name is required"]);
        exit();
    }

    if (!empty($emp_id)) {
        $sql = "SELECT id, emp_id, firm_name, address, mobile, pin_code, 
                       category, product_name, price, quantity, total_amount, created_at
                FROM daily_reports
                WHERE emp_id = ? AND firm_name = ?
                ORDER BY id DESC LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ss", $emp_id, $firm_name);
    } else {
        $sql = "SELECT id, emp_id, firm_name, address, mobile, pin_code, 
                       category, product_name, price, quantity, total_amount, created_at
                FROM daily_reports
                WHERE firm_name = ?
                ORDER BY id DESC LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $firm_name);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        echo json_encode([
            "status" => true,
            "exists" => true,
            "firm"   => $row
        ]);
    } else {
        echo json_encode([
            "status" => true,
            "exists" => false,
            "firm"   => null
        ]);
    }

    $stmt->close();
    $conn->close();
    exit();
}

// ============================================================
// GET — Fetch ONLY verified firms for dropdown
// ============================================================
if ($method === 'GET' && isset($_GET['action']) && $_GET['action'] === 'verified_firms') {
    $emp_id = trim($_GET['emp_id'] ?? '');

    $sql = "SELECT DISTINCT dr.firm_name, dr.mobile, dr.pin_code, dr.address
            FROM daily_reports dr
            INNER JOIN users u 
                ON u.firm_name = dr.firm_name 
               AND u.mobile = dr.mobile 
               AND u.pin_code = dr.pin_code
               AND (u.address = dr.address OR dr.address IS NULL OR dr.address = '')
            " . (!empty($emp_id) ? "WHERE dr.emp_id = ?" : "") . "
            ORDER BY dr.firm_name ASC";

    $stmt = $conn->prepare($sql);
    if (!empty($emp_id)) {
        $stmt->bind_param("s", $emp_id);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    $firms = [];
    while ($row = $result->fetch_assoc()) {
        $firms[] = $row;
    }

    echo json_encode(["status" => true, "firms" => $firms]);
    $stmt->close();
    $conn->close();
    exit();
}

// ============================================================
// GET — Fetch reports (default: by emp_id)
// Used by the History icon in Daily Report Log card
// ============================================================
$emp_id = $_GET['emp_id'] ?? 'all';

if ($emp_id === 'all' || empty($emp_id)) {
    $sql = "SELECT id, emp_id, firm_name, address, mobile, pin_code, category, 
                   product_name, price, quantity, total_amount, created_at 
            FROM daily_reports ORDER BY id DESC";
    $stmt = $conn->prepare($sql);
} else {
    $sql = "SELECT id, emp_id, firm_name, address, mobile, pin_code, category, 
                   product_name, price, quantity, total_amount, created_at 
            FROM daily_reports WHERE emp_id = ? ORDER BY id DESC";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("s", $emp_id);
    }
}

if ($stmt && $stmt->execute()) {
    $result = $stmt->get_result();
    $reports = [];
    while ($row = $result->fetch_assoc()) {
        $reports[] = $row;
    }
    echo json_encode([
        "status"  => true,
        "reports" => $reports,
        "data"    => $reports
    ]);
    $stmt->close();
} else {
    echo json_encode([
        "status"  => false,
        "message" => "Query failed: " . ($conn->error ?: 'unknown')
    ]);
}

$conn->close();
?>