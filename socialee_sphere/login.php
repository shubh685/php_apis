<?php
// login.php

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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => false, "message" => "Only POST method is allowed"]);
    exit();
}

$rawData = file_get_contents("php://input");
$data = json_decode($rawData, true);
if (!is_array($data)) {
    $data = $_POST;
}

$email = strtolower(trim((string)($data['email'] ?? '')));
$password = (string)($data['password'] ?? '');

if (empty($email) || empty($password)) {
    http_response_code(422);
    echo json_encode(["status" => false, "message" => "Email and password are required"]);
    exit();
}

try {
    $stmt = $pdo->prepare("SELECT id, agency_name, owner_name, email, password_hash, is_email_verified FROM agencies WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $agency = $stmt->fetch();

    if (!$agency) {
        http_response_code(401);
        echo json_encode(["status" => false, "message" => "Invalid email or password"]);
        exit();
    }

    if (!$agency['is_email_verified'] || empty($agency['password_hash'])) {
        http_response_code(403);
        echo json_encode(["status" => false, "message" => "Registration is incomplete. Please complete email verification."]);
        exit();
    }

    if (!password_verify($password, $agency['password_hash'])) {
        http_response_code(401);
        echo json_encode(["status" => false, "message" => "Invalid email or password"]);
        exit();
    }

    // Omit sensitive hashes from response
    unset($agency['password_hash']);

    echo json_encode([
        "status" => true,
        "message" => "Login successful",
        "data" => $agency
    ]);
    exit();

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => false, "message" => "Database error: " . $e->getMessage()]);
    exit();
}
?>