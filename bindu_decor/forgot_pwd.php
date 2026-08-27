<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json; charset=UTF-8");

// =====================================================
// CORS PREFLIGHT
// =====================================================

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once "database.php";

require "PHPMailer/src/PHPMailer.php";
require "PHPMailer/src/SMTP.php";
require "PHPMailer/src/Exception.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// =====================================================
// ONLY POST
// =====================================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        "status" => false,
        "message" => "Only POST method is allowed"
    ]);
    exit();
}

// =====================================================
// READ JSON / POST DATA
// =====================================================

$rawData = file_get_contents("php://input");
$data = json_decode($rawData, true);

if (!is_array($data)) {
    $data = $_POST;
}

$action = trim((string)($data['action'] ?? ''));

// =====================================================
// HELPER FUNCTIONS
// =====================================================

function cleanEmail($email)
{
    return strtolower(trim((string)$email));
}

/**
 * Returns user-provided device time if valid, otherwise falls back to server time.
 */
function getDeviceTimestamp($data)
{
    $deviceTime = trim((string)($data['device_time'] ?? $data['updated_at'] ?? ''));
    if (!empty($deviceTime) && strtotime($deviceTime) !== false) {
        return date("Y-m-d H:i:s", strtotime($deviceTime));
    }
    return date("Y-m-d H:i:s");
}

// =====================================================
// ACTION 1: SEND OTP
// =====================================================

if ($action === 'send_otp') {

    $email = cleanEmail($data['input'] ?? $data['email'] ?? '');
    $deviceTime = getDeviceTimestamp($data);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(422);
        echo json_encode([
            "status" => false,
            "message" => "Invalid email address"
        ]);
        exit();
    }

    try {
        // FIND USER
        $stmt = $pdo->prepare("
            SELECT id, name, email
            FROM users
            WHERE email = ?
            LIMIT 1
        ");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            http_response_code(404);
            echo json_encode([
                "status" => false,
                "message" => "No admin account found with this email"
            ]);
            exit();
        }

        // GENERATE SECURE 6-DIGIT OTP
        $otp = (string) random_int(100000, 999999);
        $expiry = date("Y-m-d H:i:s", strtotime("+5 minutes"));

        // SAVE OTP TO DATABASE AND UPDATE updated_at WITH DEVICE TIME
        $update = $pdo->prepare("
            UPDATE users
            SET
                otp = ?,
                otp_expiry = ?,
                otp_verified = 0,
                updated_at = ?
            WHERE id = ?
        ");
        $update->execute([
            $otp,
            $expiry,
            $deviceTime,
            $user['id']
        ]);

        // SEND MAIL VIA PHPMAILER
        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host       = "smtp.gmail.com";
            $mail->SMTPAuth   = true;
            $mail->Username   = "shahshubham128@gmail.com"; 
            $mail->Password   = "gswc cdls hjxu ofuc"; // Replace with your App Password
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;

            $mail->setFrom("shahshubham128@gmail.com", "Bindu Decor Admin");
            $mail->addAddress($user['email'], $user['name']);

            $mail->isHTML(true);
            $mail->Subject = "Bindu Decor - Password Reset OTP";

            $mail->Body = "
                <div style='font-family:Arial,sans-serif;max-width:600px;margin:auto;'>
                    <h2 style='color:#0F2C23;'>Bindu Decor</h2>
                    <p>Hello " . htmlspecialchars($user['name']) . ",</p>
                    <p>We received a request to reset your admin account password.</p>
                    <div style='background:#f5f1e8;padding:25px;text-align:center;margin:20px 0;'>
                        <div style='font-size:13px;color:#666;'>Your OTP</div>
                        <div style='font-size:32px;font-weight:bold;letter-spacing:8px;color:#0F2C23;margin-top:10px;'>
                            {$otp}
                        </div>
                    </div>
                    <p>This OTP is valid for <strong>5 minutes</strong>.</p>
                    <p>If you did not request a password reset, please ignore this email.</p>
                    <hr>
                    <p style='font-size:12px;color:#777;'>Bindu Decor Admin Security</p>
                </div>
            ";

            $mail->AltBody = "Bindu Decor Password Reset\n\nYour OTP is: {$otp}\n\nOTP is valid for 5 minutes.";

            $mail->send();

            echo json_encode([
                "status" => true,
                "message" => "OTP sent successfully to your email"
            ]);
            exit();

        } catch (Exception $e) {
            error_log("OTP Mail Error: " . $mail->ErrorInfo);

            // Rollback OTP state on mail failure and refresh updated_at with device time
            $pdo->prepare("
                UPDATE users
                SET otp = NULL,
                    otp_expiry = NULL,
                    otp_verified = 0,
                    updated_at = ?
                WHERE id = ?
            ")->execute([$deviceTime, $user['id']]);

            http_response_code(500);
            echo json_encode([
                "status" => false,
                "message" => "Unable to send OTP email. Check server logs."
            ]);
            exit();
        }

    } catch (PDOException $e) {
        error_log("Send OTP Database Error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            "status" => false,
            "message" => "Database server error"
        ]);
        exit();
    }
}

// =====================================================
// ACTION 2: VERIFY OTP
// =====================================================

if ($action === 'verify_otp') {

    $email      = cleanEmail($data['input'] ?? $data['email'] ?? '');
    $otp        = trim((string)($data['otp'] ?? ''));
    $deviceTime = getDeviceTimestamp($data);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^[0-9]{6}$/', $otp)) {
        http_response_code(422);
        echo json_encode([
            "status" => false,
            "message" => "Valid email and a 6-digit OTP are required"
        ]);
        exit();
    }

    try {
        $stmt = $pdo->prepare("
            SELECT id, email, otp, otp_expiry
            FROM users
            WHERE email = ?
            LIMIT 1
        ");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            http_response_code(404);
            echo json_encode([
                "status" => false,
                "message" => "User not found"
            ]);
            exit();
        }

        if (empty($user['otp']) || empty($user['otp_expiry'])) {
            http_response_code(400);
            echo json_encode([
                "status" => false,
                "message" => "No active OTP found. Please request a new one."
            ]);
            exit();
        }

        // CHECK EXPIRY
        if (strtotime($user['otp_expiry']) < time()) {
            $pdo->prepare("
                UPDATE users
                SET otp = NULL,
                    otp_expiry = NULL,
                    otp_verified = 0,
                    updated_at = ?
                WHERE id = ?
            ")->execute([$deviceTime, $user['id']]);

            http_response_code(400);
            echo json_encode([
                "status" => false,
                "message" => "OTP expired. Please request a new OTP."
            ]);
            exit();
        }

        // SECURE HASH COMPARISON
        if (!hash_equals((string)$user['otp'], (string)$otp)) {
            http_response_code(400);
            echo json_encode([
                "status" => false,
                "message" => "Invalid OTP"
            ]);
            exit();
        }

        // MARK AS VERIFIED AND UPDATE updated_at WITH DEVICE TIME
        $update = $pdo->prepare("
            UPDATE users
            SET otp_verified = 1,
                updated_at = ?
            WHERE id = ?
        ");
        $update->execute([$deviceTime, $user['id']]);

        echo json_encode([
            "status" => true,
            "message" => "OTP verified successfully",
            "email" => $user['email']
        ]);
        exit();

    } catch (PDOException $e) {
        error_log("Verify OTP Error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            "status" => false,
            "message" => "Server error"
        ]);
        exit();
    }
}

// =====================================================
// ACTION 3: RESET PASSWORD
// =====================================================

if ($action === 'reset_password') {

    $email       = cleanEmail($data['email'] ?? '');
    $passwordRaw = (string)($data['password'] ?? '');
    $deviceTime  = getDeviceTimestamp($data);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($passwordRaw) < 6) {
        http_response_code(422);
        echo json_encode([
            "status" => false,
            "message" => "Valid email and a password of at least 6 characters are required"
        ]);
        exit();
    }

    try {
        $stmt = $pdo->prepare("
            SELECT id, email, otp_verified, otp_expiry
            FROM users
            WHERE email = ?
            LIMIT 1
        ");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            http_response_code(404);
            echo json_encode([
                "status" => false,
                "message" => "User not found"
            ]);
            exit();
        }

        if ((int)$user['otp_verified'] !== 1) {
            http_response_code(403);
            echo json_encode([
                "status" => false,
                "message" => "Please verify OTP before changing your password"
            ]);
            exit();
        }

        // CHECK IF OTP WINDOW IS STILL VALID
        if (empty($user['otp_expiry']) || strtotime($user['otp_expiry']) < time()) {
            $pdo->prepare("
                UPDATE users
                SET otp = NULL,
                    otp_expiry = NULL,
                    otp_verified = 0,
                    updated_at = ?
                WHERE id = ?
            ")->execute([$deviceTime, $user['id']]);

            http_response_code(400);
            echo json_encode([
                "status" => false,
                "message" => "OTP verification session expired. Please request a new OTP."
            ]);
            exit();
        }

        // HASH NEW PASSWORD, CLEAR OTP, & UPDATE updated_at WITH DEVICE TIME
        $hashedPassword = password_hash($passwordRaw, PASSWORD_DEFAULT);

        $update = $pdo->prepare("
            UPDATE users
            SET
                password = ?,
                otp = NULL,
                otp_expiry = NULL,
                otp_verified = 0,
                updated_at = ?
            WHERE id = ?
        ");
        $update->execute([
            $hashedPassword,
            $deviceTime,
            $user['id']
        ]);

        echo json_encode([
            "status" => true,
            "message" => "Password updated successfully"
        ]);
        exit();

    } catch (PDOException $e) {
        error_log("Reset Password Error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            "status" => false,
            "message" => "Unable to update password"
        ]);
        exit();
    }
}

// =====================================================
// INVALID ACTION FALLBACK
// =====================================================

http_response_code(400);
echo json_encode([
    "status" => false,
    "message" => "Invalid or missing action parameter"
]);
exit();

?>