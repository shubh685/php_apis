<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once "database.php";

$rawData = file_get_contents("php://input");
$data = json_decode($rawData, true) ?: $_POST;

$email = trim((string)($data['email'] ?? ''));
$oldPassword = (string)($data['password'] ?? '');
$newPassword = (string)($data['new_password'] ?? '');

if (empty($email) || empty($oldPassword) || empty($newPassword)) {
    http_response_code(422);
    echo json_encode(["status" => false, "message" => "All fields are required"]);
    exit();
}

try {
    $stmt = $pdo->prepare("SELECT id, password FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($oldPassword, $user['password'])) {
        http_response_code(401);
        echo json_encode(["status" => false, "message" => "Incorrect old password"]);
        exit();
    }

    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
    $updateStmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
    $updateStmt->execute([$hashedPassword, $user['id']]);

    echo json_encode(["status" => true, "message" => "Password updated successfully"]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => false, "message" => "Server error: " . $e->getMessage()]);
}
?>