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

// ✅ SAFE INPUT
$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

if (!$data) {
    $data = $_POST;
}

$email = $data['email'] ?? '';
$passwordRaw = $data['password'] ?? '';

if ($email == '' || $passwordRaw == '') {
    echo json_encode([
        "status" => false,
        "message" => "Email & password required"
    ]);
    exit();
}

// ✅ HASH
$password = password_hash($passwordRaw, PASSWORD_DEFAULT);

// ✅ UPDATE
$stmt = $conn->prepare("UPDATE users SET password=? WHERE email=?");

if ($stmt->execute([$password, $email])) {
    echo json_encode([
        "status" => true,
        "message" => "Password Updated"
    ]);
} else {
    echo json_encode([
        "status" => false,
        "message" => "Failed"
    ]);
}
?>