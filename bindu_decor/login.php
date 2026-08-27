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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    http_response_code(405);

    echo json_encode([
        "status" => false,
        "message" => "Only POST method is allowed"
    ]);

    exit();
}


// =====================================================
// READ JSON
// =====================================================

$rawData = file_get_contents("php://input");

$data = json_decode($rawData, true);

if (!is_array($data)) {
    $data = $_POST;
}


// =====================================================
// INPUT
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


$email = filter_var(
    $email,
    FILTER_SANITIZE_EMAIL
);

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

    http_response_code(422);

    echo json_encode([
        "status" => false,
        "message" => "Please enter a valid email address"
    ]);

    exit();
}


// =====================================================
// FIND USER
// =====================================================

try {

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

    $stmt->execute([
        $email
    ]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);


    // =================================================
    // VERIFY PASSWORD
    // =================================================

    if (!$user || !password_verify($password, $user['password'])) {

        http_response_code(401);

        echo json_encode([
            "status" => false,
            "message" => "Invalid email or password"
        ]);

        exit();
    }


    // =================================================
    // SUCCESS
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

    error_log(
        "Login Database Error: " . $e->getMessage()
    );

    http_response_code(500);

    echo json_encode([
        "status" => false,
        "message" => "Server error. Please try again later."
    ]);

    exit();
}

?>