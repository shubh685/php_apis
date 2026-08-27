<?php
ob_clean(); // ✅ remove unwanted output

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Content-Type: application/json");

require_once "database.php";

try {

    $raw = file_get_contents("php://input");

    if (!$raw) {
        echo json_encode([
            "status" => false,
            "message" => "No input received"
        ]);
        exit;
    }

    $data = json_decode($raw, true);

    if (!is_array($data)) {
        echo json_encode([
            "status" => false,
            "message" => "Invalid JSON input"
        ]);
        exit;
    }

    $email = $data['email'] ?? '';
    $user_type = $data['user_type'] ?? '';

    if (empty($email) || empty($user_type)) {
        echo json_encode([
            "status" => false,
            "message" => "Email and user_type required"
        ]);
        exit;
    }

    // ✅ CHECK USER
    if ($user_type == "User") {
        $stmt = $conn->prepare("SELECT id FROM users WHERE email=?");
    } else {
        $stmt = $conn->prepare("SELECT id FROM enterprise WHERE email=?");
    }

    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo json_encode([
            "status" => false,
            "message" => "Email not found"
        ]);
        exit;
    }

    // ✅ FETCH BANK DETAILS
    $stmt = $conn->prepare("
        SELECT bank_name, account_number, ifsc, account_type
        FROM bank_details
        WHERE user_type = ?
        LIMIT 1
    ");

    $stmt->execute([$user_type]);
    $bank = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($bank) {
        echo json_encode([
            "status" => true,
            "data" => $bank
        ]);
    } else {
        echo json_encode([
            "status" => false,
            "message" => "No Bank Found"
        ]);
    }

} catch (Exception $e) {
    echo json_encode([
        "status" => false,
        "message" => "Server Error",
        "error" => $e->getMessage()
    ]);
}

exit;
?>