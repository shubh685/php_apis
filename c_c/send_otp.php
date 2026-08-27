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
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';
require 'PHPMailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ✅ SAFE INPUT (FIXED)
$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

// fallback for hosting
if (!$data) {
    $data = $_POST;
}

if (!isset($data['input'])) {
    echo json_encode(["status" => false, "message" => "Invalid data"]);
    exit();
}

$email = trim($data['input']);

// ✅ VALIDATION
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(["status" => false, "message" => "Invalid email"]);
    exit();
}

// ✅ CHECK USER
$stmt = $conn->prepare("SELECT * FROM users WHERE email=?");
$stmt->execute([$email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    echo json_encode(["status" => false, "message" => "User not found"]);
    exit();
}

// ✅ OTP
$otp = rand(100000, 999999);
$expiry = date("Y-m-d H:i:s", strtotime("+5 minutes"));

$conn->prepare("UPDATE users SET otp=?, otp_expiry=? WHERE email=?")
     ->execute([$otp, $expiry, $email]);

// ✅ MAIL
$mail = new PHPMailer(true);

try {
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'shahshubham128@gmail.com'; // change
    $mail->Password   = 'gswc cdls hjxu ofuc';             // change
    $mail->SMTPSecure = 'tsl';
    $mail->Port       = 587;
    $mail->Timeout    = 10;

    $mail->setFrom('shahshubham128@gmail.com', 'OTP Service');
    $mail->addAddress($email);

    $mail->Subject = 'Your OTP Code';
    $mail->Body    = "Your OTP is: $otp";

    $mail->send();

    echo json_encode(["status" => true, "message" => "OTP sent"]);

} catch (Exception $e) {
    file_put_contents("mail_error.txt", $mail->ErrorInfo);

    echo json_encode([
        "status" => false,
        "message" => "Mail failed: " . $mail->ErrorInfo
    ]);
}
?>