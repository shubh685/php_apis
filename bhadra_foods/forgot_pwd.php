<?php
// forgot_pwd.php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Content-Type: application/json");
header("Content-Type: application/json; charset=UTF-8");

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// 1. Include Database class
require_once __DIR__ . '/data.php';

$database = new Database();
$pdo = $database->getConnection();

if (!$pdo) {
    http_response_code(500);
    echo json_encode([
        "status" => false,
        "message" => "Database connection failed"
    ]);
    exit();
}

// 2. Include PHPMailer
$phpmailerBase = __DIR__ . "/PHPMailer/src/";
if (!file_exists($phpmailerBase . "PHPMailer.php")) {
    http_response_code(500);
    echo json_encode([
        "status" => false,
        "message" => "PHPMailer library not found"
    ]);
    exit();
}

require $phpmailerBase . "PHPMailer.php";
require $phpmailerBase . "SMTP.php";
require $phpmailerBase . "Exception.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        "status" => false,
        "message" => "Only POST method is allowed"
    ]);
    exit();
}

$rawData = file_get_contents("php://input");
$data = json_decode($rawData, true);

if (!is_array($data)) {
    $data = $_POST;
}

$action = trim((string)($data['action'] ?? ''));

function cleanInput($val) {
    return strtolower(trim((string)$val));
}

function getDeviceTimestamp($data) {
    $deviceTime = trim((string)($data['device_time'] ?? $data['updated_at'] ?? ''));
    if (!empty($deviceTime) && strtotime($deviceTime) !== false) {
        return date("Y-m-d H:i:s", strtotime($deviceTime));
    }
    return date("Y-m-d H:i:s");
}

// =====================================================
// ACTION 1: SEND OTP (4-DIGIT)
// =====================================================
if ($action === 'send_otp') {
    $input = cleanInput($data['input'] ?? $data['email'] ?? $data['username'] ?? $data['emp_id'] ?? $data['mobile'] ?? '');
    $deviceTime = getDeviceTimestamp($data);

    if (empty($input)) {
        http_response_code(422);
        echo json_encode([
            "status" => false,
            "message" => "Email, Mobile, or Employee ID is required"
        ]);
        exit();
    }

    try {
        // Support searching by email, mobile, or emp_id for all roles
        $stmt = $pdo->prepare("
            SELECT name, emp_id, mobile, email, role 
            FROM users 
            WHERE email = ? OR mobile = ? OR emp_id = ? 
            LIMIT 1
        ");
        $stmt->execute([$input, $input, $input]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            http_response_code(404);
            echo json_encode([
                "status" => false,
                "message" => "No account found matching the provided identity"
            ]);
            exit();
        }

        if (!filter_var($user['email'], FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode([
                "status" => false,
                "message" => "User account does not have a valid email address configured"
            ]);
            exit();
        }

        // Generate 4-Digit OTP and store hash in database
        $otp = (string) random_int(1000, 9999);
        $otpHash = password_hash($otp, PASSWORD_DEFAULT);
        $expiry = date("Y-m-d H:i:s", strtotime("+5 minutes"));

        $update = $pdo->prepare("
            UPDATE users
            SET reset_otp_hash = ?, reset_otp_expires_at = ?, updated_at = ?
            WHERE email = ?
        ");
        $update->execute([$otpHash, $expiry, $deviceTime, $user['email']]);

        // Mail OTP via PHPMailer
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = "smtp.gmail.com";
            $mail->SMTPAuth   = true;
            $mail->Username   = "shahshubham128@gmail.com";
            $mail->Password   = "gswc cdls hjxu ofuc"; // App Password
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;
            $mail->CharSet    = 'UTF-8';

            $mail->setFrom("shahshubham128@gmail.com", "Bhadra Foods");
            $mail->addAddress($user['email'], $user['name'] ?? 'User');

            $mail->isHTML(true);
            $mail->Subject = "Bhadra Foods - Password Reset OTP";

            $mail->Body = "
                <div style='font-family:Arial,sans-serif;max-width:600px;margin:auto;'>
                    <h2 style='color:#3D1F03;'>Bhadra Foods</h2>
                    <p>Hello " . htmlspecialchars($user['name'] ?? 'User') . " (" . htmlspecialchars($user['role'] ?? 'Employee') . "),</p>
                    <p>We received a request to reset your password.</p>
                    <div style='background:#f5f1e8;padding:25px;text-align:center;margin:20px 0;'>
                        <div style='font-size:13px;color:#666;'>Your 4-Digit OTP</div>
                        <div style='font-size:32px;font-weight:bold;letter-spacing:10px;color:#3D1F03;margin-top:10px;'>
                            {$otp}
                        </div>
                    </div>
                    <p>This OTP is valid for <strong>5 minutes</strong>.</p>
                </div>
            ";

            $mail->AltBody = "Your OTP is: {$otp}. Valid for 5 minutes.";
            $mail->send();

            echo json_encode([
                "status" => true,
                "message" => "4-digit OTP sent successfully to your registered email"
            ]);
            exit();

        } catch (Exception $e) {
            $pdo->prepare("UPDATE users SET reset_otp_hash = NULL, reset_otp_expires_at = NULL WHERE email = ?")
                ->execute([$user['email']]);

            http_response_code(500);
            echo json_encode([
                "status" => false,
                "message" => "Unable to send OTP email."
            ]);
            exit();
        }

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            "status" => false,
            "message" => "Database error: " . $e->getMessage()
        ]);
        exit();
    }
}

// =====================================================
// ACTION 2: VERIFY OTP (4-DIGIT)
// =====================================================
if ($action === 'verify_otp') {
    $input = cleanInput($data['input'] ?? $data['email'] ?? $data['username'] ?? $data['emp_id'] ?? $data['mobile'] ?? '');
    $otp   = trim((string)($data['otp'] ?? ''));

    if (empty($input) || !preg_match('/^[0-9]{4}$/', $otp)) {
        http_response_code(422);
        echo json_encode([
            "status" => false,
            "message" => "Valid identifier and a 4-digit OTP are required"
        ]);
        exit();
    }

    try {
        $stmt = $pdo->prepare("
            SELECT email, reset_otp_hash, reset_otp_expires_at 
            FROM users 
            WHERE email = ? OR mobile = ? OR emp_id = ? 
            LIMIT 1
        ");
        $stmt->execute([$input, $input, $input]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            http_response_code(404);
            echo json_encode(["status" => false, "message" => "User not found"]);
            exit();
        }

        if (empty($user['reset_otp_hash']) || empty($user['reset_otp_expires_at'])) {
            http_response_code(400);
            echo json_encode(["status" => false, "message" => "No active OTP found. Please request a new one."]);
            exit();
        }

        if (strtotime($user['reset_otp_expires_at']) < time()) {
            $pdo->prepare("UPDATE users SET reset_otp_hash = NULL, reset_otp_expires_at = NULL WHERE email = ?")
                ->execute([$user['email']]);
            http_response_code(400);
            echo json_encode(["status" => false, "message" => "OTP expired. Please request a new OTP."]);
            exit();
        }

        if (!password_verify($otp, $user['reset_otp_hash'])) {
            http_response_code(400);
            echo json_encode(["status" => false, "message" => "Invalid OTP"]);
            exit();
        }

        echo json_encode([
            "status" => true,
            "message" => "OTP verified successfully",
            "email" => $user['email']
        ]);
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
    $input       = cleanInput($data['input'] ?? $data['email'] ?? $data['username'] ?? $data['emp_id'] ?? $data['mobile'] ?? '');
    $otp         = trim((string)($data['otp'] ?? ''));
    $passwordRaw = (string)($data['password'] ?? '');
    $deviceTime  = getDeviceTimestamp($data);

    if (empty($input) || empty($otp) || strlen($passwordRaw) < 6) {
        http_response_code(422);
        echo json_encode([
            "status" => false,
            "message" => "Identifier, OTP, and a password of at least 6 characters are required"
        ]);
        exit();
    }

    try {
        $stmt = $pdo->prepare("
            SELECT email, reset_otp_hash, reset_otp_expires_at 
            FROM users 
            WHERE email = ? OR mobile = ? OR emp_id = ? 
            LIMIT 1
        ");
        $stmt->execute([$input, $input, $input]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            http_response_code(404);
            echo json_encode(["status" => false, "message" => "User not found"]);
            exit();
        }

        if (empty($user['reset_otp_hash']) || empty($user['reset_otp_expires_at'])) {
            http_response_code(403);
            echo json_encode(["status" => false, "message" => "Please request and verify OTP before resetting password"]);
            exit();
        }

        if (strtotime($user['reset_otp_expires_at']) < time()) {
            $pdo->prepare("UPDATE users SET reset_otp_hash = NULL, reset_otp_expires_at = NULL WHERE email = ?")
                ->execute([$user['email']]);
            http_response_code(400);
            echo json_encode(["status" => false, "message" => "Session expired. Please request a new OTP."]);
            exit();
        }

        if (!password_verify($otp, $user['reset_otp_hash'])) {
            http_response_code(400);
            echo json_encode(["status" => false, "message" => "Invalid OTP verification"]);
            exit();
        }

        $hashedPassword = password_hash($passwordRaw, PASSWORD_DEFAULT);

        $pdo->prepare("
            UPDATE users
            SET password = ?, reset_otp_hash = NULL, reset_otp_expires_at = NULL, updated_at = ?
            WHERE email = ?
        ")->execute([$hashedPassword, $deviceTime, $user['email']]);

        echo json_encode([
            "status" => true,
            "message" => "Password updated successfully"
        ]);
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