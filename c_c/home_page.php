<?php


header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Content-Type: application/json");

require_once "database.php";

$data = json_decode(file_get_contents("php://input"), true);

$email = $data['email'] ?? '';

if(empty($email)){
    echo json_encode(["status"=>false,"message"=>"Email required"]);
    exit;
}

/* =========================
   🔍 AUTO DETECT USER TYPE
========================= */

$name = "";
$company = "";
$user_type = "";

// 🔹 Check USER table
$checkUser = $conn->prepare("SELECT name FROM users WHERE email=?");
$checkUser->execute([$email]);

if($checkUser->rowCount() > 0){
    $row = $checkUser->fetch(PDO::FETCH_ASSOC);

    $name = $row['name'];
    $user_type = "User";
}

// 🔹 Check ENTERPRISE table
$checkEnterprise = $conn->prepare("SELECT name, company_name FROM enterprise WHERE email=?");
$checkEnterprise->execute([$email]);

if($checkEnterprise->rowCount() > 0){
    $row = $checkEnterprise->fetch(PDO::FETCH_ASSOC);

    $name = $row['name']; // ✅ admin name as name
    $company = $row['company_name'];
    $user_type = "Enterprise";
}

/* =========================
   📄 KYC STATUS
========================= */
$kyc = $conn->prepare("SELECT aadhar_file, pan_file FROM kyc_details WHERE email=? AND user_type=?");
$kyc->execute([$email, $user_type]);
$kycData = $kyc->fetch(PDO::FETCH_ASSOC);

$aadharStatus = (!empty($kycData['aadhar_file'])) ? "Uploaded" : "Pending";
$panStatus    = (!empty($kycData['pan_file'])) ? "Uploaded" : "Pending";

/* =========================
   🏦 BANK STATUS
========================= */
$bank = $conn->prepare("SELECT id FROM bank_details WHERE email=? AND user_type=?");
$bank->execute([$email, $user_type]);

$bankStatus = ($bank->rowCount() > 0) ? "Uploaded" : "Pending";

/* =========================
   ✅ FINAL RESPONSE
========================= */
echo json_encode([
    "status" => true,
    "data" => [
        "name" => $name, // ✅ user name OR admin name
        "email" => $email,
        "user_type" => $user_type,
        "company_name" => $company,
        "aadhar_status" => $aadharStatus,
        "pan_status" => $panStatus,
        "bank_status" => $bankStatus
    ]
]);

?>