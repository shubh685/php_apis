<?php
// forgot_pwd.php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json; charset=UTF-8");

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/database.php';

$database = new Database();
$pdo = $database->getConnection();

if (!$pdo) {
    http_response_code(500);
    echo json_encode(["status" => false, "message" => "Database connection failed"]);
    exit();
}

$phpmailerBase = __DIR__ . "/PHPMailer/src/";
require $phpmailerBase . "PHPMailer.php";
require $phpmailerBase . "SMTP.php";
require $phpmailerBase . "Exception.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function getTimestamp($data) {
    $time = trim((string)($data['created_at'] ?? ''));
    if (!empty($time) && strtotime($time) !== false) {
        return date("Y-m-d H:i:s", strtotime($time));
    }
    return date("Y-m-d H:i:s");
}

function sendResetOtpEmail($email, $otp, $agencyName) {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = "smtp.gmail.com";
    $mail->SMTPAuth   = true;
    $mail->Username   = "shahshubham128@gmail.com";
    $mail->Password   = "gswc cdls hjxu ofuc";
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;
    $mail->CharSet    = 'UTF-8';

    $mail->setFrom("shahshubham128@gmail.com", "Socialee Sphere");
    $mail->addAddress($email, $agencyName);

    $mail->isHTML(true);
    $mail->Subject = "Socialee Sphere - Reset Password OTP";
    $mail->Body    = "
        <div style='font-family:Arial,sans-serif;max-width:500px;margin:auto;padding:20px;border:1px solid #e0e0e0;border-radius:10px;'>
            <h2 style='color:#FF6B9D;text-align:center;'>Socialee Sphere</h2>
            <p>Hello <strong>" . htmlspecialchars($agencyName) . "</strong>,</p>
            <p>Use the 5-digit code below to reset your password:</p>
            <div style='background:#fff0f5;padding:18px;text-align:center;font-size:32px;font-weight:bold;letter-spacing:10px;color:#B91C1C;margin:20px 0;border-radius:8px;'>
                {$otp}
            </div>
            <p>This code is valid for <strong>5 minutes</strong>.</p>
        </div>
    ";
    $mail->send();
}

$rawData = file_get_contents("php://input");
$data = json_decode($rawData, true) ?? $_POST;
$action = trim((string)($data['action'] ?? ''));
$createdAt = getTimestamp($data);

// =====================================================
// ACTION 1: SEND RESET OTP
// =====================================================
if ($action === 'send_otp') {
    $email = strtolower(trim((string)($data['email'] ?? '')));

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(422);
        echo json_encode(["status" => false, "message" => "Valid email address required"]);
        exit();
    }

    try {
        $stmt = $pdo->prepare("SELECT id, agency_name FROM agencies WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $agency = $stmt->fetch();

        if (!$agency) {
            http_response_code(404);
            echo json_encode(["status" => false, "message" => "No account found for this email"]);
            exit();
        }

        $otp = (string) random_int(10000, 99999);
        $otpHash = password_hash($otp, PASSWORD_DEFAULT);
        $expiresAt = date("Y-m-d H:i:s", strtotime("+5 minutes", strtotime($createdAt)));
        $purpose = "FORGOT_PASSWORD";

        $insOtp = $pdo->prepare("
            INSERT INTO otp_verifications (email, otp_code, purpose, expires_at, created_at)
            VALUES (?, ?, ?, ?, ?)
        ");
        $insOtp->execute([$email, $otpHash, $purpose, $expiresAt, $createdAt]);

        sendResetOtpEmail($email, $otp, $agency['agency_name']);

        echo json_encode(["status" => true, "message" => "5-digit OTP sent successfully to your email"]);
        exit();

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["status" => false, "message" => "Unable to send OTP: " . $e->getMessage()]);
        exit();
    }
}

// =====================================================
// ACTION 2: VERIFY RESET OTP
// =====================================================
if ($action === 'verify_otp') {
    $email = strtolower(trim((string)($data['email'] ?? '')));
    $otp   = trim((string)($data['otp'] ?? ''));

    if (empty($email) || !preg_match('/^[0-9]{5}$/', $otp)) {
        http_response_code(422);
        echo json_encode(["status" => false, "message" => "Valid email and 5-digit OTP required"]);
        exit();
    }

    try {
        $stmt = $pdo->prepare("
            SELECT id, otp_code, expires_at 
            FROM otp_verifications 
            WHERE email = ? AND purpose = 'FORGOT_PASSWORD' AND is_verified = FALSE
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([$email]);
        $record = $stmt->fetch();

        if (!$record) {
            http_response_code(400);
            echo json_encode(["status" => false, "message" => "No pending OTP request found"]);
            exit();
        }

        if (strtotime($record['expires_at']) < strtotime($createdAt)) {
            http_response_code(400);
            echo json_encode(["status" => false, "message" => "OTP code has expired. Please request a new one."]);
            exit();
        }

        if (!password_verify($otp, $record['otp_code'])) {
            http_response_code(400);
            echo json_encode(["status" => false, "message" => "Invalid OTP code"]);
            exit();
        }

        $pdo->prepare("UPDATE otp_verifications SET is_verified = TRUE WHERE id = ?")->execute([$record['id']]);

        echo json_encode(["status" => true, "message" => "OTP verified successfully"]);
        exit();

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["status" => false, "message" => "Database error: " . $e->getMessage()]);
        exit();
    }
}

// =====================================================
// ACTION 3: RESET PASSWORD
// =====================================================
if ($action === 'reset_password') {
    $email       = strtolower(trim((string)($data['email'] ?? '')));
    $passwordRaw = (string)($data['password'] ?? '');

    if (empty($email) || strlen($passwordRaw) < 6) {
        http_response_code(422);
        echo json_encode(["status" => false, "message" => "Email and password of at least 6 characters required"]);
        exit();
    }

    try {
        $chk = $pdo->prepare("
            SELECT id FROM otp_verifications 
            WHERE email = ? AND purpose = 'FORGOT_PASSWORD' AND is_verified = TRUE AND expires_at >= ?
            ORDER BY id DESC LIMIT 1
        ");
        $chk->execute([$email, $createdAt]);
        $verifiedRecord = $chk->fetch();

        if (!$verifiedRecord) {
            http_response_code(403);
            echo json_encode(["status" => false, "message" => "Verify OTP code before resetting password"]);
            exit();
        }

        $hashedPassword = password_hash($passwordRaw, PASSWORD_DEFAULT);

        $pdo->prepare("
            UPDATE agencies 
            SET password_hash = ?, updated_at = ? 
            WHERE email = ?
        ")->execute([$hashedPassword, $createdAt, $email]);

        $pdo->prepare("DELETE FROM otp_verifications WHERE email = ? AND purpose = 'FORGOT_PASSWORD'")->execute([$email]);

        echo json_encode(["status" => true, "message" => "Password updated successfully"]);
        exit();

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["status" => false, "message" => "Database error: " . $e->getMessage()]);
        exit();
    }
}

http_response_code(400);
echo json_encode(["status" => false, "message" => "Invalid action parameter"]);
exit();
?>