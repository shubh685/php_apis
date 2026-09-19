<?php

declare(strict_types=1);

// =====================================================
// CONFIGURATION & TEST MODE SWITCH
// =====================================================

// Set to FALSE in production to send real emails via PHPMailer
$testMode = false;

// Set to FALSE to enforce strict RFC email validation
$allowTestEmails = false; 

// =====================================================
// ERROR HANDLING
// =====================================================

ini_set('display_errors', '1');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// =====================================================
// CORS / HEADERS
// =====================================================

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept');
header('X-Content-Type-Options: nosniff');
header('Content-Type: application/json; charset=UTF-8');

// =====================================================
// PHPMailer
// =====================================================

require_once __DIR__ . '/PHPMailer/src/Exception.php';
require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

// =====================================================
// DATABASE CONNECTION
// =====================================================

$host = 'localhost';
$dbname = 'grow_socialee';
$username = 'root'; 
$password = '';     

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (PDOException $e) {
    sendJson(false, 'Database connection failed: ' . $e->getMessage(), [], 500);
}

// =====================================================
// HELPER FUNCTION
// =====================================================

function sendJson(
    bool $success,
    string $message,
    array $extra = [],
    int $statusCode = 200
): never {
    http_response_code($statusCode);

    echo json_encode(
        array_merge(
            [
                'success' => $success,
                'message' => $message,
            ],
            $extra
        ),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

// =====================================================
// OPTIONS REQUEST
// =====================================================

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// =====================================================
// METHOD CHECK
// =====================================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJson(false, 'Only POST requests are allowed.', [], 405);
}

// =====================================================
// READ REQUEST BODY
// =====================================================

$rawData = file_get_contents('php://input');

error_log('=== Grow Socialee Debug ===');
error_log('REQUEST_METHOD: ' . $_SERVER['REQUEST_METHOD']);
error_log('CONTENT_TYPE: ' . ($_SERVER['CONTENT_TYPE'] ?? 'Not set'));
error_log('RAW DATA: ' . ($rawData ? substr($rawData, 0, 500) : 'EMPTY'));

if ($rawData === false || trim($rawData) === '') {
    sendJson(
        false,
        'No data received. Please ensure you are sending JSON with Content-Type: application/json',
        [
            'debug' => [
                'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'Not set',
                'method' => $_SERVER['REQUEST_METHOD']
            ]
        ],
        400
    );
}

// =====================================================
// JSON DECODE
// =====================================================

$data = json_decode($rawData, true);

if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
    error_log('Grow Socialee Invalid JSON: ' . json_last_error_msg());

    sendJson(
        false,
        'Invalid JSON payload: ' . json_last_error_msg(),
        [
            'debug' => [
                'raw_data_preview' => substr($rawData, 0, 200)
            ]
        ],
        400
    );
}

// =====================================================
// GET INPUT
// =====================================================

$requestId = trim((string)($data['request_id'] ?? ''));
$name = trim((string)($data['name'] ?? ''));
$mobile = trim((string)($data['phone'] ?? $data['mobile'] ?? ''));
$userEmail = trim((string)($data['email'] ?? ''));
$category = trim((string)($data['category'] ?? 'General Inquiry'));
$service = trim((string)($data['service'] ?? ''));
$message = trim((string)($data['message'] ?? ''));

// =====================================================
// BASIC VALIDATION
// =====================================================

if ($name === '' || $mobile === '' || $userEmail === '' || $service === '' || $message === '') {
    sendJson(
        false,
        'All fields are required.',
        [
            'debug' => [
                'name' => empty($name) ? 'missing' : 'ok',
                'mobile' => empty($mobile) ? 'missing' : 'ok',
                'email' => empty($userEmail) ? 'missing' : 'ok',
                'service' => empty($service) ? 'missing' : 'ok',
                'message' => empty($message) ? 'missing' : 'ok'
            ]
        ],
        422
    );
}

// =====================================================
// EMAIL VALIDATION
// =====================================================

$userEmail = filter_var($userEmail, FILTER_SANITIZE_EMAIL);

if (!$allowTestEmails) {
    if (!filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
        sendJson(false, 'Please enter a valid email address.', [], 422);
    }
} else {
    if (!preg_match('/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', $userEmail)) {
        sendJson(false, 'Please enter a valid email address format.', [], 422);
    }
}

// =====================================================
// MOBILE VALIDATION
// =====================================================

$mobileClean = preg_replace('/[^0-9]/', '', $mobile);
if ($mobileClean === null || !preg_match('/^[0-9]{10}$/', $mobileClean)) {
    sendJson(false, 'Please enter a valid 10-digit mobile number.', [], 422);
}

// =====================================================
// DATABASE + EMAIL
// =====================================================

try {
    // 1. CHECK DUPLICATE REQUEST ID
    if ($requestId !== '') {
        $check = $pdo->prepare('SELECT id, request_id FROM inquiries WHERE request_id = :request_id LIMIT 1');
        $check->execute([':request_id' => $requestId]);

        if ($check->fetch()) {
            sendJson(false, 'This inquiry has already been submitted.', ['request_id' => $requestId], 409);
        }
    }

    // 2. PREVENT REPEAT SUBMISSIONS (DEDUPLICATION - 5 MIN WINDOW)
    $dupCheck = $pdo->prepare('
        SELECT request_id 
        FROM inquiries 
        WHERE (email = :email OR mobile = :mobile) 
          AND service = :service 
          AND created_at >= NOW() - INTERVAL 5 MINUTE 
        LIMIT 1
    ');
    $dupCheck->execute([
        ':email' => $userEmail,
        ':mobile' => $mobileClean,
        ':service' => $service
    ]);

    $existingInquiry = $dupCheck->fetch();

    if ($existingInquiry) {
        sendJson(
            false, 
            'A duplicate inquiry was detected recently. Please wait a few minutes before trying again.', 
            ['request_id' => $existingInquiry['request_id']], 
            409
        );
    }

    // Assign request ID if missing
    if ($requestId === '') {
        $requestId = 'GS-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(3)));
    }

    // -------------------------------------------------
    // INSERT INQUIRY
    // -------------------------------------------------

    $insert = $pdo->prepare(
        'INSERT INTO inquiries (
            request_id, name, mobile, email, category, service, message,
            email_status, email_error, created_at, updated_at
        ) VALUES (
            :request_id, :name, :mobile, :email, :category, :service, :message,
            :email_status, NULL, NOW(), NOW()
        )'
    );

    $insert->execute([
        ':request_id' => $requestId,
        ':name' => $name,
        ':mobile' => $mobileClean,
        ':email' => $userEmail,
        ':category' => $category,
        ':service' => $service,
        ':message' => $message,
        ':email_status' => 'pending'
    ]);

    $dbId = (int)$pdo->lastInsertId();

    // -------------------------------------------------
    // FETCH TIMESTAMP
    // -------------------------------------------------

    $timeStmt = $pdo->prepare('SELECT created_at FROM inquiries WHERE id = :id LIMIT 1');
    $timeStmt->execute([':id' => $dbId]);
    $dbRecord = $timeStmt->fetch();

    $createdAtRaw = $dbRecord['created_at'] ?? date('Y-m-d H:i:s');
    $dt = new DateTime($createdAtRaw);

    $submissionTime = $dt->format('Y-m-d H:i:s');
    $submissionDate = $dt->format('l, F j, Y');
    $submissionTime12hr = $dt->format('h:i:s A');

    // -------------------------------------------------
    // SEND EMAILS (ADMIN + USER)
    // -------------------------------------------------
    
    $adminEmailSent = false;
    $userEmailSent = false;
    $emailError = null;

    if ($testMode) {
        $adminEmailSent = true;
        $userEmailSent = true;
        error_log("Test mode enabled: Skipped actual PHPMailer dispatch for request_id: {$requestId}");
    } else {
        try {
            $mail = new PHPMailer(true);

            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = 'shahshubham128@gmail.com';
            $mail->Password = 'gswc cdls hjxu ofuc'; // Gmail App Password
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = 587;

            // =====================================================
            // EMAIL 1: SEND TO ADMIN
            // =====================================================

            $mail->clearAllRecipients();
            $mail->clearAddresses();
            $mail->clearReplyTos();

            $mail->setFrom('shahshubham128@gmail.com', 'Grow Socialee Website');
            $mail->addAddress('growsocialee@gmail.com', 'Admin - Grow Socialee');
            $mail->addReplyTo($userEmail, $name);

            $mail->isHTML(true);
            $mail->Subject = 'NEW INQUIRY - Grow Socialee - ' . $requestId;

            $mail->Body = '
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="UTF-8">
                <title>New Grow Socialee Inquiry</title>
                <style>
                    body { font-family: Arial, sans-serif; }
                    .container { max-width: 650px; margin: 0 auto; padding: 20px; }
                    .header { background: #1E88E5; color: white; padding: 20px; text-align: center; }
                    .content { padding: 20px; background: #f9f9f9; }
                    .detail-row { padding: 12px; border-bottom: 1px solid #ddd; display: flex; }
                    .label { font-weight: bold; color: #1E88E5; width: 150px; }
                    .value { flex: 1; }
                    .time-badge { background: #e3f2fd; padding: 10px; border-left: 4px solid #1E88E5; margin: 10px 0; }
                    .footer { background: #1E88E5; color: white; padding: 10px; text-align: center; font-size: 12px; }
                </style>
            </head>
            <body>
                <div class="container">
                    <div class="header">
                        <h2>📬 New Client Inquiry</h2>
                        <p>Grow Socialee Website</p>
                    </div>
                    <div class="content">
                        <div class="time-badge">
                            <strong>📅 Submitted on:</strong> ' . $submissionDate . ' at ' . $submissionTime12hr . '
                        </div>
                        
                        <h3>Inquiry Details</h3>
                        
                        <div class="detail-row">
                            <span class="label">Request ID:</span>
                            <span class="value">' . htmlspecialchars($requestId, ENT_QUOTES, 'UTF-8') . '</span>
                        </div>
                        <div class="detail-row">
                            <span class="label">Category:</span>
                            <span class="value">' . htmlspecialchars($category, ENT_QUOTES, 'UTF-8') . '</span>
                        </div>
                        <div class="detail-row">
                            <span class="label">Customer Name:</span>
                            <span class="value">' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</span>
                        </div>
                        <div class="detail-row">
                            <span class="label">Mobile Number:</span>
                            <span class="value"><a href="tel:' . htmlspecialchars($mobileClean, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($mobileClean, ENT_QUOTES, 'UTF-8') . '</a></span>
                        </div>
                        <div class="detail-row">
                            <span class="label">Email Address:</span>
                            <span class="value"><a href="mailto:' . htmlspecialchars($userEmail, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($userEmail, ENT_QUOTES, 'UTF-8') . '</a></span>
                        </div>
                        <div class="detail-row">
                            <span class="label">Selected Service:</span>
                            <span class="value">' . htmlspecialchars($service, ENT_QUOTES, 'UTF-8') . '</span>
                        </div>
                        <div class="detail-row">
                            <span class="label">Message:</span>
                            <span class="value">' . nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8')) . '</span>
                        </div>
                        
                        <br>
                        <div style="background: #e3f2fd; padding: 15px; border-left: 4px solid #1E88E5;">
                            <strong>📌 Quick Actions:</strong><br>
                            • <a href="mailto:' . htmlspecialchars($userEmail, ENT_QUOTES, 'UTF-8') . '">Reply to Customer</a><br>
                            • <a href="tel:' . htmlspecialchars($mobileClean, ENT_QUOTES, 'UTF-8') . '">Call Customer</a>
                        </div>
                    </div>
                    <div class="footer">
                        Automated system notification from Grow Socialee
                    </div>
                </div>
            </body>
            </html>
            ';

            if ($mail->send()) {
                $adminEmailSent = true;
            }

            // =====================================================
            // EMAIL 2: SEND CONFIRMATION TO USER
            // =====================================================

            $mail->clearAllRecipients();
            $mail->clearAddresses();
            $mail->clearReplyTos();

            $mail->setFrom('shahshubham128@gmail.com', 'Grow Socialee');
            $mail->addAddress($userEmail, $name);
            $mail->addReplyTo('growsocialee@gmail.com', 'Grow Socialee Support');

            $mail->isHTML(true);
            $mail->Subject = 'Thank you for contacting Grow Socialee!';

            $mail->Body = '
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="UTF-8">
                <title>Thank You - Grow Socialee</title>
                <style>
                    body { font-family: Arial, sans-serif; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background: #1E88E5; color: white; padding: 20px; text-align: center; }
                    .content { padding: 20px; background: #f9f9f9; }
                    .detail-row { padding: 10px; border-bottom: 1px solid #ddd; display: flex; }
                    .label { font-weight: bold; color: #1E88E5; width: 130px; }
                    .value { flex: 1; }
                    .thank-you { font-size: 18px; color: #1E88E5; }
                    .footer { background: #1E88E5; color: white; padding: 10px; text-align: center; font-size: 12px; }
                </style>
            </head>
            <body>
                <div class="container">
                    <div class="header">
                        <h2>🙏 Thank You!</h2>
                        <p>Grow Socialee Agency</p>
                    </div>
                    <div class="content">
                        <p class="thank-you">Dear ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ',</p>
                        
                        <p>Thank you for reaching out to <strong>Grow Socialee</strong>. We have received your inquiry and our team will get back to you shortly.</p>
                        
                        <div style="background: #e3f2fd; padding: 12px; border-left: 4px solid #1E88E5; margin: 15px 0;">
                            <strong>📅 Submitted on:</strong> ' . $submissionDate . ' at ' . $submissionTime12hr . '
                        </div>
                        
                        <h3>Your Inquiry Summary</h3>
                        
                        <div class="detail-row">
                            <span class="label">Request ID:</span>
                            <span class="value">' . htmlspecialchars($requestId, ENT_QUOTES, 'UTF-8') . '</span>
                        </div>
                        <div class="detail-row">
                            <span class="label">Service:</span>
                            <span class="value">' . htmlspecialchars($service, ENT_QUOTES, 'UTF-8') . '</span>
                        </div>
                        <div class="detail-row">
                            <span class="label">Message:</span>
                            <span class="value">' . nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8')) . '</span>
                        </div>
                        
                        <br>
                        <div style="background: #fce4ec; padding: 15px; border-left: 4px solid #e91e63;">
                            <strong>📌 Fast Response Guarantee:</strong><br>
                            • We usually respond within 2 working hours.<br>
                            • Need immediate help? Call us at <strong>+91 94085 18168</strong>
                        </div>
                    </div>
                    <div class="footer">
                        © ' . date('Y') . ' Grow Socialee - All Rights Reserved
                    </div>
                </div>
            </body>
            </html>
            ';

            if ($mail->send()) {
                $userEmailSent = true;
            }

        } catch (Exception $e) {
            $emailError = $e->getMessage();
            error_log('PHPMailer Error: ' . $e->getMessage());
        }
    }

    // -------------------------------------------------
    // UPDATE EMAIL STATUS
    // -------------------------------------------------

    $status = ($adminEmailSent && $userEmailSent) ? 'sent' : ($adminEmailSent ? 'partial' : 'failed');
    $update = $pdo->prepare(
        'UPDATE inquiries SET email_status = :status, email_error = :error, updated_at = NOW() 
         WHERE request_id = :request_id'
    );
    $update->execute([
        ':status' => $status,
        ':error' => $emailError,
        ':request_id' => $requestId
    ]);

    // -------------------------------------------------
    // FINAL RESPONSE
    // -------------------------------------------------

    sendJson(
        true,
        $testMode 
            ? 'Inquiry submitted successfully (TEST MODE: Email delivery bypassed).' 
            : 'Inquiry submitted successfully! We will contact you soon.',
        [
            'request_id' => $requestId,
            'email_status' => $status,
            'created_at' => $submissionTime,
            'test_mode' => $testMode
        ]
    );

} catch (PDOException $e) {
    error_log('Database Error: ' . $e->getMessage());
    sendJson(false, 'Database error occurred. Please try again.', [], 500);
} catch (Throwable $e) {
    error_log('General Error: ' . $e->getMessage());
    sendJson(false, 'An error occurred. Please try again.', [], 500);
}
?>