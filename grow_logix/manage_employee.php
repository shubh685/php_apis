<?php
if (ob_get_level()) ob_end_clean();

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

require_once 'database.php';

$method = $_SERVER['REQUEST_METHOD'];

// FETCH EMPLOYEE PROFILE INFORMATION
if ($method === 'GET') {
    $empId = $_GET['emp_id'] ?? '';
    $userId = $_GET['user_id'] ?? '';

    try {
        if (!empty($userId)) {
            $stmt = $pdo->prepare("SELECT id, emp_id, name, email, mobile, role FROM users WHERE id = :user_id LIMIT 1");
            $stmt->execute([':user_id' => $userId]);
        } elseif (!empty($empId)) {
            $stmt = $pdo->prepare("SELECT id, emp_id, name, email, mobile, role FROM users WHERE emp_id = :emp_id LIMIT 1");
            $stmt->execute([':emp_id' => $empId]);
        } else {
            $stmt = $pdo->prepare("SELECT id, emp_id, name, email, mobile, role FROM users ORDER BY id DESC");
            $stmt->execute();
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(["status" => "success", "data" => $data]);
            exit();
        }

        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            http_response_code(200);
            echo json_encode(["status" => "success", "data" => $user]);
        } else {
            http_response_code(404);
            echo json_encode(["status" => "error", "message" => "Employee not found."]);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
    exit();
}

// VALIDATE EMAIL & PASSWORD FOR EMP DASHBOARD ACTION
if ($method === 'POST') {
    $data = json_decode(file_get_contents("php://input"));

    if (empty($data->email) || empty($data->password)) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Email and password are required."]);
        exit();
    }

    try {
        $stmt = $pdo->prepare("SELECT id, emp_id, name, email, mobile, password, role FROM users WHERE LOWER(email) = LOWER(:email) LIMIT 1");
        $stmt->execute([':email' => trim($data->email)]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($data->password, $user['password'])) {
            unset($user['password']);
            http_response_code(200);
            echo json_encode([
                "status" => "success",
                "message" => "Credentials verified.",
                "data" => $user
            ]);
        } else {
            http_response_code(401);
            echo json_encode(["status" => "error", "message" => "Invalid email or password."]);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
    exit();
}
?>