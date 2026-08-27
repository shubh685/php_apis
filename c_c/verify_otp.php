<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Content-Type: application/json");

// ✅ HANDLE PREFLIGHT
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once "database.php";

// ✅ SAFE INPUT
$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

if (!$data) {
    $data = $_POST;
}

$input = trim($data['input'] ?? '');
$otp   = trim($data['otp'] ?? '');

if (empty($input) || empty($otp)) {
    echo json_encode([
        "status"=>false,
        "message"=>"Input & OTP required"
    ]);
    exit();
}

// ✅ FIND USER
$stmt = $conn->prepare("SELECT * FROM users WHERE email=? OR mobile=? LIMIT 1");
$stmt->execute([$input,$input]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if(!$user){
    echo json_encode(["status"=>false,"message"=>"User not found"]);
    exit();
}

// ✅ CHECK OTP
if(empty($user['otp']) || empty($user['otp_expiry'])){
    echo json_encode(["status"=>false,"message"=>"Request OTP first"]);
    exit();
}

if(strtotime($user['otp_expiry']) < time()){
    echo json_encode(["status"=>false,"message"=>"OTP expired"]);
    exit();
}

if($user['otp'] != $otp){
    echo json_encode(["status"=>false,"message"=>"Invalid OTP"]);
    exit();
}

// ✅ CLEAR OTP
$conn->prepare("UPDATE users SET otp=NULL, otp_expiry=NULL WHERE id=?")
     ->execute([$user['id']]);

echo json_encode([
    "status"=>true,
    "message"=>"OTP Verified",
    "email"=>$user['email']
]);
?>