<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once "database.php";

ini_set('display_errors', 1); // TEMP DEBUG

try {

    $rawData = file_get_contents("php://input");

    if (!$rawData) {
        echo json_encode(["status"=>false,"message"=>"No data received"]);
        exit();
    }

    $data = json_decode($rawData, true);

    if (!$data) {
        echo json_encode(["status"=>false,"message"=>"Invalid JSON"]);
        exit();
    }

    $type = $data['type'] ?? '';

    if ($type == "User") {

        $stmt = $conn->prepare("INSERT INTO users (name,mobile,email,password) VALUES (?,?,?,?)");

        $stmt->execute([
            $data['name'],
            $data['mobile'],
            $data['email'],
            password_hash($data['password'], PASSWORD_DEFAULT)
        ]);

        echo json_encode(["status"=>true,"message"=>"User Registered"]);

    } elseif ($type == "Enterprise") {

        $stmt = $conn->prepare("INSERT INTO enterprise (company_name,name,mobile,email,password) VALUES (?,?,?,?,?)");

        $stmt->execute([
            $data['company_name'],
            $data['name'],
            $data['mobile'],
            $data['email'],
            password_hash($data['password'], PASSWORD_DEFAULT)
        ]);

        echo json_encode(["status"=>true,"message"=>"Enterprise Registered"]);

    } else {
        echo json_encode(["status"=>false,"message"=>"Invalid type"]);
    }

} catch (Exception $e) {
    echo json_encode([
        "status"=>false,
        "message"=>"Server error",
        "error"=>$e->getMessage()
    ]);
}
?>