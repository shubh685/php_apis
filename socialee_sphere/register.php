<?php
// register.php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json; charset=UTF-8");

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Standardize PHP execution timezone to UTC
date_default_timezone_set('UTC');

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

try {
    $pdo->exec("SET time_zone = '+00:00'");
} catch (PDOException $e) {
    // ignore
}

$phpmailerBase = __DIR__ . "/PHPMailer/src/";
require $phpmailerBase . "PHPMailer.php";
require $phpmailerBase . "SMTP.php";
require $phpmailerBase . "Exception.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Normalizes incoming device timestamp to "Y-m-d H:i:s".
 * Accepts formats like:
 *   "2026-08-12 16:55:09"
 *   "2026-08-12T16:55:09.000Z"
 *   "2026-08-12 16:55:09.123456"
 * Falls back to server UTC time if parsing fails.
 */
function normalizeDeviceTimestamp(array $data): string {
    $raw = trim((string) (
        $data['device_datetime']
        ?? $data['created_at']
        ?? $data['device_time']
        ?? $data['updated_at']
        ?? ''
    ));

    if ($raw !== '') {
        // Strip microseconds if present (PHP strtotime dislikes >6 digits)
        $raw = preg_replace('/\.\d+/', '', $raw);
        // Handle ISO 'T' separator
        $raw = str_replace('T', ' ', $raw);
        // Remove trailing Z if any
        $raw = rtrim($raw, 'Z');

        $ts = strtotime($raw);
        if ($ts !== false) {
            return date("Y-m-d H:i:s", $ts);
        }
    }
    return date("Y-m-d H:i:s");
}

function sendRegistrationOtpEmail($email, $otp, $agencyName, $purpose) {
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
    $mail->Subject = "Socialee Sphere - Verification Code";
    $mail->Body = "
        <div style='font-family:Arial,sans-serif;max-width:500px;margin:auto;padding:20px;border:1px solid #e0e0e0;border-radius:10px;'>
            <h2 style='color:#A855F7;text-align:center;'>Socialee Sphere</h2>
            <p>Hello <strong>" . htmlspecialchars($agencyName) . "</strong>,</p>
            <p>Your 4-digit verification code for <strong>{$purpose}</strong> is:</p>
            <div style='background:#f4f0fa;padding:18px;text-align:center;font-size:32px;font-weight:bold;letter-spacing:10px;color:#2D1B69;margin:20px 0;border-radius:8px;'>
                {$otp}
            </div>
            <p>This code is valid for <strong>5 minutes</strong>.</p>
        </div>
    ";

    $mail->send();
}

$rawData = file_get_contents("php://input");
$data = json_decode($rawData, true) ?? $_POST;
$action = trim((string) ($data['action'] ?? ''));

$deviceTime  = normalizeDeviceTimestamp($data);   // this becomes created_at
$currentUnix = time();                            // server clock (for expiry check only)

// =====================================================
// ACTION 1: SEND OTP
// =====================================================
if ($action === 'send_otp') {
    $agencyName = trim((string) ($data['agency_name'] ?? ''));
    $ownerName  = trim((string) ($data['owner_name'] ?? ''));
    $email      = strtolower(trim((string) ($data['email'] ?? '')));
    $purpose    = trim((string) ($data['purpose'] ?? 'REGISTRATION_STEP_1'));

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(422);
        echo json_encode(["status" => false, "message" => "Valid email address is required"]);
        exit();
    }

    try {
        // Check if email already exists in agencies
        $stmt = $pdo->prepare("SELECT id, is_email_verified FROM agencies WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing && (int)$existing['is_email_verified'] === 1) {
            http_response_code(409);
            echo json_encode(["status" => false, "message" => "Email is already registered. Please sign in."]);
            exit();
        }

        if (!$existing) {
            // INSERT with AUTO_INCREMENT id (do NOT pass id)
            $ins = $pdo->prepare("
                INSERT INTO agencies (agency_name, owner_name, email, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?)
            ");
            $ins->execute([$agencyName, $ownerName, $email, $deviceTime, $deviceTime]);
        } else {
            $pdo->prepare("
                UPDATE agencies
                SET agency_name = ?, owner_name = ?, updated_at = ?
                WHERE email = ?
            ")->execute([$agencyName, $ownerName, $deviceTime, $email]);
        }

        // Expire older pending OTP attempts for this email + purpose
        $pdo->prepare("
            UPDATE otp_verifications
            SET expires_at = ?
            WHERE email = ? AND purpose = ? AND is_verified = 0
        ")->execute([$deviceTime, $email, $purpose]);

        $otp = (string) random_int(1000, 9999);
        $otpHash = password_hash($otp, PASSWORD_DEFAULT);

        // ✅ expires_at is ALWAYS derived from created_at (= $deviceTime) + 5 minutes.
        //    This guarantees expires_at - created_at == 00:05:00 exactly,
        //    regardless of server clock drift.
        $createdTs  = strtotime($deviceTime);
        $expiresAt  = date("Y-m-d H:i:s", $createdTs + 300); // +5 min

        // INSERT with AUTO_INCREMENT id (do NOT pass id)
        $insOtp = $pdo->prepare("
            INSERT INTO otp_verifications (email, otp_code, purpose, expires_at, created_at)
            VALUES (?, ?, ?, ?, ?)
        ");
        $insOtp->execute([$email, $otpHash, $purpose, $expiresAt, $deviceTime]);

        sendRegistrationOtpEmail($email, $otp, $agencyName ?: "Agency", $purpose);

        echo json_encode([
            "status"     => true,
            "message"    => "4-digit OTP sent successfully to your email",
            "created_at" => $deviceTime,
            "expires_at" => $expiresAt
        ]);
        exit();
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["status" => false, "message" => "Unable to send OTP: " . $e->getMessage()]);
        exit();
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["status" => false, "message" => "Database error: " . $e->getMessage()]);
        exit();
    }
}

// =====================================================
// ACTION 2: VERIFY OTP
// =====================================================
if ($action === 'verify_otp') {
    $email   = strtolower(trim((string) ($data['email'] ?? '')));
    $otp     = trim((string) ($data['otp'] ?? ''));
    $purpose = trim((string) ($data['purpose'] ?? 'REGISTRATION_STEP_1'));

    if (empty($email) || !preg_match('/^[0-9]{4}$/', $otp)) {
        http_response_code(422);
        echo json_encode(["status" => false, "message" => "Valid email and 4-digit OTP code required"]);
        exit();
    }

    try {
        $stmt = $pdo->prepare("
            SELECT id, otp_code, expires_at
            FROM otp_verifications
            WHERE email = ? AND purpose = ? AND is_verified = 0
            ORDER BY created_at DESC, id DESC
            LIMIT 1
        ");
        $stmt->execute([$email, $purpose]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$record) {
            http_response_code(400);
            echo json_encode(["status" => false, "message" => "No pending OTP request found"]);
            exit();
        }

        $expireUnix = strtotime($record['expires_at']);

        if ($expireUnix < $currentUnix) {
            http_response_code(400);
            echo json_encode(["status" => false, "message" => "OTP code has expired. Please request a new one."]);
            exit();
        }

        if (!password_verify($otp, $record['otp_code'])) {
            http_response_code(400);
            echo json_encode(["status" => false, "message" => "Invalid OTP code"]);
            exit();
        }

        $pdo->prepare("
            UPDATE otp_verifications
            SET is_verified = 1
            WHERE id = ?
        ")->execute([$record['id']]);

        echo json_encode([
            "status"     => true,
            "message"    => "OTP verified successfully for " . $purpose,
            "updated_at" => $deviceTime
        ]);
        exit();
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["status" => false, "message" => "Database error: " . $e->getMessage()]);
        exit();
    }
}

// =====================================================
// ACTION 3: COMPLETE REGISTRATION
// =====================================================
if ($action === 'complete_registration') {
    $email    = strtolower(trim((string) ($data['email'] ?? '')));
    $password = (string) ($data['password'] ?? '');

    if (empty($email) || strlen($password) < 6) {
        http_response_code(422);
        echo json_encode(["status" => false, "message" => "Password must be at least 6 characters long"]);
        exit();
    }

    try {
        $chk = $pdo->prepare("
            SELECT id
            FROM otp_verifications
            WHERE email = ? AND is_verified = 1
            ORDER BY created_at DESC, id DESC
            LIMIT 1
        ");
        $chk->execute([$email]);
        $verifiedRecord = $chk->fetch(PDO::FETCH_ASSOC);

        if (!$verifiedRecord) {
            http_response_code(403);
            echo json_encode(["status" => false, "message" => "Email verification required before setting password."]);
            exit();
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        $upd = $pdo->prepare("
            UPDATE agencies
            SET password_hash = ?, is_email_verified = 1, updated_at = ?
            WHERE email = ?
        ");
        $upd->execute([$passwordHash, $deviceTime, $email]);

        if ($upd->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(["status" => false, "message" => "Agency record not found for this email."]);
            exit();
        }

        echo json_encode([
            "status"     => true,
            "message"    => "Agency account successfully registered.",
            "updated_at" => $deviceTime
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