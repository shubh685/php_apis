<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Content-Type: application/json");

require_once "database.php";

try {

    $data = json_decode(file_get_contents("php://input"), true);

    $email = $data['email'] ?? '';
    $type  = $data['user_type'] ?? '';

    if (empty($email) || empty($type)) {
        echo json_encode(["status" => false, "message" => "Missing fields"]);
        exit;
    }

    // ✅ GET USER
    if ($type == "User") {
        $stmt = $conn->prepare("SELECT name, mobile, email FROM users WHERE email=?");
    } else {
        $stmt = $conn->prepare("SELECT name, company_name, mobile, email FROM enterprise WHERE email=?");
    }

    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo json_encode(["status" => false, "message" => "User not found"]);
        exit;
    }

    // ✅ BANK CHECK
    $bankCheck = $conn->prepare("SELECT * FROM bank_details WHERE email=? AND user_type=?");
    $bankCheck->execute([$email, $type]);
    $bank = $bankCheck->fetch(PDO::FETCH_ASSOC);

    // ✅ KYC FETCH (IMPORTANT 🔥)
    $kycCheck = $conn->prepare("SELECT aadhar_file, pan_file FROM kyc_details WHERE email=? AND user_type=?");
    $kycCheck->execute([$email, $type]);
    $kyc = $kycCheck->fetch(PDO::FETCH_ASSOC);

    // 🎯 COMPLETION
    $completion = 0;

    if (!empty($user['name']) && !empty($user['mobile']) && !empty($user['email'])) {
        $completion += 40;
    }

    if ($bank) {
        $completion += 40;
    }

    if (!empty($kyc['aadhar_file']) && !empty($kyc['pan_file'])) {
        $completion += 20;
    }

    // ✅ FINAL RESPONSE
    echo json_encode([
        "status" => true,
        "completion" => $completion,
        "data" => [
            "name" => $user['name'] ?? "",
            "company_name" => $user['company_name'] ?? "",
            "mobile" => $user['mobile'] ?? "",
            "email" => $user['email'] ?? "",

            // 🔥 VERY IMPORTANT
            "aadhar_file" => $kyc['aadhar_file'] ?? "",
            "pan_file" => $kyc['pan_file'] ?? ""
        ]
    ]);

} catch (Exception $e) {
    echo json_encode(["status" => false, "message" => $e->getMessage()]);
}
?>