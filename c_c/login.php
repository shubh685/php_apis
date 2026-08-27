<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Content-Type: application/json");
require_once "database.php";

$data = json_decode(file_get_contents("php://input"), true);

if(!$data){
    echo json_encode([
        "status"=>false,
        "message"=>"No data received"
    ]);
    exit();
}

$email = $data['email'] ?? "";
$password = $data['password'] ?? "";

if($email == "" || $password == ""){
    echo json_encode([
        "status"=>false,
        "message"=>"Email or password missing"
    ]);
    exit();
}

# USER LOGIN
$sql = "SELECT * FROM users WHERE email=?";
$stmt = $conn->prepare($sql);
$stmt->execute([$email]);

$user = $stmt->fetch(PDO::FETCH_ASSOC);

if($user){
    if(password_verify($password,$user['password'])){
        echo json_encode([
            "status"=>true,
            "type"=>"User",
            "data"=>$user
        ]);
        exit();
    }
}

# ENTERPRISE LOGIN
$sql = "SELECT * FROM enterprise WHERE email=?";
$stmt = $conn->prepare($sql);
$stmt->execute([$email]);

$enterprise = $stmt->fetch(PDO::FETCH_ASSOC);

if($enterprise){
    if(password_verify($password,$enterprise['password'])){
        echo json_encode([
            "status"=>true,
            "type"=>"Enterprise",
            "data"=>$enterprise
        ]);
        exit();
    }
}

echo json_encode([
    "status"=>false,
    "message"=>"Invalid Email or Password"
]);

?>