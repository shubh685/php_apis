<?php

// =====================================================
// CORS HEADERS
// =====================================================

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json; charset=UTF-8");

// =====================================================
// ERROR HANDLING
// =====================================================

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', 'php_errors.log');

// =====================================================
// CORS PREFLIGHT
// =====================================================

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// =====================================================
// CHECK FILES & LOAD LIBRARIES
// =====================================================

$baseDir = __DIR__;

try {
    if (!file_exists($baseDir . "/database.php")) {
        http_response_code(500);
        echo json_encode([
            "status" => false,
            "message" => "Database configuration file not found"
        ]);
        exit();
    }

    require_once $baseDir . "/database.php";

    // Check PHPMailer files
    $phpmailerBase = $baseDir . "/PHPMailer/src/";
    if (!file_exists($phpmailerBase . "PHPMailer.php")) {
        http_response_code(500);
        echo json_encode([
            "status" => false,
            "message" => "PHPMailer library not found. Please check the installation."
        ]);
        exit();
    }

    require_once $phpmailerBase . "PHPMailer.php";
    require_once $phpmailerBase . "SMTP.php";
    require_once $phpmailerBase . "Exception.php";

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "status" => false,
        "message" => "Initialization error: " . $e->getMessage()
    ]);
    exit();
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// =====================================================
// METHOD VALIDATION
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
// READ INPUT
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
        // Check PDO connection
        if (!isset($pdo)) {
            throw new Exception("Database connection not established");
        }

        // Find User
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

        // Generate Secure 6-Digit OTP
        $otp = (string) random_int(100000, 999999);
        $expiry = date("Y-m-d H:i:s", strtotime("+5 minutes"));

        // Save OTP to Database
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

        // Send Mail via PHPMailer
        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host       = "smtp.gmail.com";
            $mail->SMTPAuth   = true;
            $mail->Username   = "shahshubham128@gmail.com"; 
            $mail->Password   = "gswc cdls hjxu ofuc"; // Your App Password
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;
            $mail->CharSet    = 'UTF-8';

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

            // Rollback OTP state on mail failure
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
                "message" => "Unable to send OTP email. Please try again later."
            ]);
            exit();
        }

    } catch (PDOException $e) {
        error_log("Send OTP Database Error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            "status" => false,
            "message" => "Database error: " . $e->getMessage()
        ]);
        exit();
    } catch (Throwable $e) {
        error_log("Send OTP Error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            "status" => false,
            "message" => "Server error: " . $e->getMessage()
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
        if (!isset($pdo)) {
            throw new Exception("Database connection not established");
        }

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

        // Check Expiry
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

        // Secure Hash Comparison
        if (!hash_equals((string)$user['otp'], (string)$otp)) {
            http_response_code(400);
            echo json_encode([
                "status" => false,
                "message" => "Invalid OTP"
            ]);
            exit();
        }

        // Mark as Verified
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
            "message" => "Database error: " . $e->getMessage()
        ]);
        exit();
    } catch (Throwable $e) {
        error_log("Verify OTP Error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            "status" => false,
            "message" => "Server error: " . $e->getMessage()
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
        if (!isset($pdo)) {
            throw new Exception("Database connection not established");
        }

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

        // Check if OTP window is still valid
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

        // Hash New Password, Clear OTP
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
            "message" => "Database error: " . $e->getMessage()
        ]);
        exit();
    } catch (Throwable $e) {
        error_log("Reset Password Error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            "status" => false,
            "message" => "Server error: " . $e->getMessage()
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