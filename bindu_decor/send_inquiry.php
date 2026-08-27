<?php

declare(strict_types=1);

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

$db_host = 'localhost';
$db_name = 'bindu_decor';
$db_user = 'root'; 
$db_pass = '';     

try {
    $pdo = new PDO(
        "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4",
        $db_user,
        $db_pass,
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

error_log('=== Bindu Decor Debug ===');
error_log('REQUEST_METHOD: ' . $_SERVER['REQUEST_METHOD']);
error_log('CONTENT_TYPE: ' . ($_SERVER['CONTENT_TYPE'] ?? 'Not set'));
error_log('CONTENT_LENGTH: ' . ($_SERVER['CONTENT_LENGTH'] ?? 'Not set'));
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
    error_log('Bindu Decor Invalid JSON: ' . json_last_error_msg());
    error_log('Raw data: ' . $rawData);

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
$mobile = trim((string)($data['mobile'] ?? ''));
$userEmail = trim((string)($data['email'] ?? ''));
$location = trim((string)($data['location'] ?? ''));
$designType = trim((string)($data['design_type'] ?? ''));

error_log('Received data: ' . json_encode([
    'request_id' => $requestId,
    'name' => $name,
    'mobile' => $mobile,
    'email' => $userEmail,
    'location' => $location,
    'design_type' => $designType
]));

// =====================================================
// BASIC VALIDATION
// =====================================================

if ($name === '' || $mobile === '' || $userEmail === '' || $location === '' || $designType === '') {
    sendJson(
        false,
        'All fields are required.',
        [
            'debug' => [
                'name' => empty($name) ? 'missing' : 'ok',
                'mobile' => empty($mobile) ? 'missing' : 'ok',
                'email' => empty($userEmail) ? 'missing' : 'ok',
                'location' => empty($location) ? 'missing' : 'ok',
                'design_type' => empty($designType) ? 'missing' : 'ok'
            ]
        ],
        422
    );
}

// =====================================================
// EMAIL VALIDATION
// =====================================================

$userEmail = filter_var($userEmail, FILTER_SANITIZE_EMAIL);
if (!filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
    sendJson(false, 'Please enter a valid email address.', [], 422);
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
    // -------------------------------------------------
    // 1. CHECK DUPLICATE REQUEST ID
    // -------------------------------------------------
    if ($requestId !== '') {
        $check = $pdo->prepare('SELECT id, request_id FROM inquiries WHERE request_id = :request_id LIMIT 1');
        $check->execute([':request_id' => $requestId]);

        if ($check->fetch()) {
            sendJson(false, 'This inquiry has already been submitted.', ['request_id' => $requestId], 409);
        }
    }

    // -------------------------------------------------
    // 2. PREVENT REPEAT SUBMISSIONS (DEDUPLICATION)
    // Check if same email/mobile + design_type was submitted in the last 5 minutes (300 seconds)
    // -------------------------------------------------
    $dupCheck = $pdo->prepare('
        SELECT request_id 
        FROM inquiries 
        WHERE (email = :email OR mobile = :mobile) 
          AND design_type = :design_type 
          AND created_at >= NOW() - INTERVAL 5 MINUTE 
        LIMIT 1
    ');
    $dupCheck->execute([
        ':email' => $userEmail,
        ':mobile' => $mobileClean,
        ':design_type' => $designType
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
        $requestId = 'BD-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(3)));
    }

    // -------------------------------------------------
    // INSERT INQUIRY
    // -------------------------------------------------

    $insert = $pdo->prepare(
        'INSERT INTO inquiries (
            request_id, name, mobile, email, location, design_type, 
            email_status, email_error, created_at, updated_at
        ) VALUES (
            :request_id, :name, :mobile, :email, :location, :design_type,
            :email_status, NULL, NOW(), NOW()
        )'
    );

    $insert->execute([
        ':request_id' => $requestId,
        ':name' => $name,
        ':mobile' => $mobileClean,
        ':email' => $userEmail,
        ':location' => $location,
        ':design_type' => $designType,
        ':email_status' => 'pending'
    ]);

    $dbId = (int)$pdo->lastInsertId();

    // -------------------------------------------------
    // FETCH CREATED_AT TIMESTAMP DIRECTLY FROM DATABASE
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

    try {
        $mail = new PHPMailer(true);

        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'shahshubham128@gmail.com';
        $mail->Password = 'gswc cdls hjxu ofuc';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        // =====================================================
        // EMAIL 1: SEND TO ADMIN
        // =====================================================

        $mail->clearAllRecipients();
        $mail->clearAddresses();
        $mail->clearReplyTos();

        $mail->setFrom('shahshubham128@gmail.com', 'Bindu Decor Website');
        $mail->addAddress('info@bindudecor.com', 'Admin - Bindu Decor');
        $mail->addReplyTo($userEmail, $name);

        $mail->isHTML(true);
        $mail->Subject = 'NEW INQUIRY - Bindu Decor - ' . $requestId;

        $mail->Body = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>New Bindu Decor Inquiry</title>
            <style>
                body { font-family: Arial, sans-serif; }
                .container { max-width: 650px; margin: 0 auto; padding: 20px; }
                .header { background: #276B5A; color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; background: #f9f9f9; }
                .detail-row { padding: 12px; border-bottom: 1px solid #ddd; display: flex; }
                .label { font-weight: bold; color: #276B5A; width: 150px; }
                .value { flex: 1; }
                .time-badge { background: #e8f5e9; padding: 10px; border-left: 4px solid #276B5A; margin: 10px 0; }
                .footer { background: #276B5A; color: white; padding: 10px; text-align: center; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h2>📬 New Inquiry Received</h2>
                    <p>Bindu Decor Website</p>
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
                        <span class="label">Database ID:</span>
                        <span class="value">' . htmlspecialchars((string)$dbId, ENT_QUOTES, 'UTF-8') . '</span>
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
                        <span class="label">Location:</span>
                        <span class="value">' . htmlspecialchars($location, ENT_QUOTES, 'UTF-8') . '</span>
                    </div>
                    <div class="detail-row">
                        <span class="label">Design Type:</span>
                        <span class="value">' . htmlspecialchars($designType, ENT_QUOTES, 'UTF-8') . '</span>
                    </div>
                    
                    <br>
                    <div style="background: #e8f5e9; padding: 15px; border-left: 4px solid #276B5A;">
                        <strong>📌 Quick Actions:</strong><br>
                        • <a href="mailto:' . htmlspecialchars($userEmail, ENT_QUOTES, 'UTF-8') . '">Reply to Customer</a><br>
                        • <a href="tel:' . htmlspecialchars($mobileClean, ENT_QUOTES, 'UTF-8') . '">Call Customer</a>
                    </div>
                </div>
                <div class="footer">
                    This is an automated email from Bindu Decor Website Inquiry System
                </div>
            </div>
        </body>
        </html>
        ';

        $mail->AltBody = 
            "NEW INQUIRY - Bindu Decor\n\n" .
            "Submitted on: " . $submissionDate . " at " . $submissionTime12hr . "\n" .
            "Request ID: {$requestId}\n" .
            "Database ID: {$dbId}\n\n" .
            "Customer Details:\n" .
            "-------------------\n" .
            "Name: {$name}\n" .
            "Mobile: {$mobileClean}\n" .
            "Email: {$userEmail}\n" .
            "Location: {$location}\n" .
            "Design Type: {$designType}\n\n" .
            "Quick Actions:\n" .
            "Reply to: {$userEmail}\n" .
            "Call: {$mobileClean}\n";

        if ($mail->send()) {
            $adminEmailSent = true;
            error_log('Admin email sent successfully for request: ' . $requestId);
        }

        // =====================================================
        // EMAIL 2: SEND CONFIRMATION TO USER
        // =====================================================

        $mail->clearAllRecipients();
        $mail->clearAddresses();
        $mail->clearReplyTos();

        $mail->setFrom('shahshubham128@gmail.com', 'Bindu Decor');
        $mail->addAddress($userEmail, $name);
        $mail->addReplyTo('shahshubham128@gmail.com', 'Bindu Decor Support');

        $mail->isHTML(true);
        $mail->Subject = 'Thank you for your inquiry - Bindu Decor';

        $mail->Body = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>Thank You - Bindu Decor</title>
            <style>
                body { font-family: Arial, sans-serif; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #276B5A; color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; background: #f9f9f9; }
                .detail-row { padding: 10px; border-bottom: 1px solid #ddd; display: flex; }
                .label { font-weight: bold; color: #276B5A; width: 130px; }
                .value { flex: 1; }
                .thank-you { font-size: 18px; color: #276B5A; }
                .footer { background: #276B5A; color: white; padding: 10px; text-align: center; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h2>🙏 Thank You!</h2>
                    <p>Bindu Decor</p>
                </div>
                <div class="content">
                    <p class="thank-you">Dear ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ',</p>
                    
                    <p>Thank you for contacting <strong>Bindu Decor</strong>. We have received your inquiry and our team will get back to you shortly.</p>
                    
                    <div style="background: #e8f5e9; padding: 12px; border-left: 4px solid #276B5A; margin: 15px 0;">
                        <strong>📅 Submitted on:</strong> ' . $submissionDate . ' at ' . $submissionTime12hr . '
                    </div>
                    
                    <h3>Your Inquiry Details</h3>
                    
                    <div class="detail-row">
                        <span class="label">Request ID:</span>
                        <span class="value">' . htmlspecialchars($requestId, ENT_QUOTES, 'UTF-8') . '</span>
                    </div>
                    <div class="detail-row">
                        <span class="label">Name:</span>
                        <span class="value">' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</span>
                    </div>
                    <div class="detail-row">
                        <span class="label">Mobile:</span>
                        <span class="value">' . htmlspecialchars($mobileClean, ENT_QUOTES, 'UTF-8') . '</span>
                    </div>
                    <div class="detail-row">
                        <span class="label">Email:</span>
                        <span class="value">' . htmlspecialchars($userEmail, ENT_QUOTES, 'UTF-8') . '</span>
                    </div>
                    <div class="detail-row">
                        <span class="label">Location:</span>
                        <span class="value">' . htmlspecialchars($location, ENT_QUOTES, 'UTF-8') . '</span>
                    </div>
                    <div class="detail-row">
                        <span class="label">Design Type:</span>
                        <span class="value">' . htmlspecialchars($designType, ENT_QUOTES, 'UTF-8') . '</span>
                    </div>
                    
                    <br>
                    <div style="background: #fff3e0; padding: 15px; border-left: 4px solid #ff9800;">
                        <strong>📌 What happens next?</strong><br>
                        • Our team will review your inquiry within 24 hours<br>
                        • We will contact you via phone or email<br>
                        • You can reply to this email for any questions
                    </div>
                    
                    <p style="margin-top: 20px; color: #555;">
                        Have questions? Call us at <strong>+91 9930098219</strong>
                    </p>
                </div>
                <div class="footer">
                    © ' . date('Y') . ' Bindu Decor - All Rights Reserved
                </div>
            </div>
        </body>
        </html>
        ';

        $mail->AltBody = 
            "Thank you for contacting Bindu Decor\n\n" .
            "Dear {$name},\n\n" .
            "Thank you for your inquiry. We have received your details and will contact you shortly.\n\n" .
            "Submitted on: " . $submissionDate . " at " . $submissionTime12hr . "\n" .
            "Request ID: {$requestId}\n\n" .
            "Your Details:\n" .
            "Name: {$name}\n" .
            "Mobile: {$mobileClean}\n" .
            "Email: {$userEmail}\n" .
            "Location: {$location}\n" .
            "Design Type: {$designType}\n\n" .
            "What happens next?\n" .
            "- Our team will review your inquiry within 24 hours\n" .
            "- We will contact you via phone or email\n\n" .
            "Have questions? Call us at +91 9930098219\n\n" .
            "© " . date('Y') . " Bindu Decor - All Rights Reserved";

        if ($mail->send()) {
            $userEmailSent = true;
            error_log('User confirmation email sent successfully to: ' . $userEmail);
        }

    } catch (Exception $e) {
        $emailError = $e->getMessage();
        error_log('PHPMailer Error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    }

    // -------------------------------------------------
    // UPDATE EMAIL STATUS
    // -------------------------------------------------

    $status = ($adminEmailSent && $userEmailSent) ? 'sent' : 'partial';
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

    if ($adminEmailSent && $userEmailSent) {
        sendJson(
            true,
            'Inquiry submitted successfully! We will contact you soon.',
            [
                'request_id' => $requestId,
                'email_status' => 'sent',
                'created_at' => $submissionTime
            ]
        );
    } else if ($adminEmailSent) {
        sendJson(
            true,
            'Inquiry submitted successfully! (User confirmation pending)',
            [
                'request_id' => $requestId,
                'email_status' => 'partial',
                'created_at' => $submissionTime
            ]
        );
    } else {
        sendJson(
            true,
            'Inquiry submitted successfully! (Email notifications pending)',
            [
                'request_id' => $requestId,
                'email_status' => 'failed',
                'created_at' => $submissionTime,
                'debug' => $emailError
            ]
        );
    }

} catch (PDOException $e) {
    error_log('Database Error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    sendJson(false, 'Database error occurred. Please try again.', [], 500);
} catch (Throwable $e) {
    error_log('General Error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    sendJson(false, 'An error occurred. Please try again.', [], 500);
}
?>