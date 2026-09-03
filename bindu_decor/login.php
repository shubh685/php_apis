<?php

// =====================================================
// CORS & CONTENT TYPE HEADERS
// =====================================================

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json; charset=UTF-8");

// Prevent PHP error traces from rendering as HTML output
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
// CHECK DATABASE REQUIREMENT
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

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "status" => false,
        "message" => "Initialization error: " . $e->getMessage()
    ]);
    exit();
}

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
// READ JSON / FORM INPUT
// =====================================================

$rawData = file_get_contents("php://input");
$data = json_decode($rawData, true);

if (!is_array($data)) {
    $data = $_POST;
}

// =====================================================
// INPUT EXTRACTION
// =====================================================

$email = trim((string)($data['email'] ?? ''));
$password = (string)($data['password'] ?? '');

// =====================================================
// VALIDATION
// =====================================================

if ($email === '' || $password === '') {
    http_response_code(422);
    echo json_encode([
        "status" => false,
        "message" => "Email and password are required"
    ]);
    exit();
}

$email = filter_var($email, FILTER_SANITIZE_EMAIL);

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode([
        "status" => false,
        "message" => "Please enter a valid email address"
    ]);
    exit();
}

// =====================================================
// FIND USER & VERIFY
// =====================================================

try {
    if (!isset($pdo)) {
        throw new Exception("Database connection not established");
    }

    $stmt = $pdo->prepare("
        SELECT
            id,
            name,
            email,
            password
        FROM users
        WHERE email = ?
        LIMIT 1
    ");

    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Verify Password (supports standard hash or plain text fallback if needed)
    $isPasswordValid = false;
    if ($user) {
        if (password_verify($password, $user['password']) || $password === $user['password']) {
            $isPasswordValid = true;
        }
    }

    if (!$user || !$isPasswordValid) {
        http_response_code(401);
        echo json_encode([
            "status" => false,
            "message" => "Invalid email or password"
        ]);
        exit();
    }

    // =================================================
    // SUCCESS RESPONSE
    // =================================================

    echo json_encode([
        "status" => true,
        "message" => "Sign in successful",
        "user" => [
            "id" => (int)$user['id'],
            "name" => $user['name'],
            "email" => $user['email'],
        ]
    ]);
    exit();

} catch (PDOException $e) {
    error_log("Login Database Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "status" => false,
        "message" => "Server database error: " . $e->getMessage()
    ]);
    exit();
} catch (Throwable $e) {
    error_log("Login General Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "status" => false,
        "message" => "Server error: " . $e->getMessage()
    ]);
    exit();
}

?>