<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Content-Type: application/json");
require_once "database.php";

try {

    $data = json_decode(file_get_contents("php://input"), true);

    $email          = $data['email'] ?? '';
    $user_type      = $data['user_type'] ?? '';
    $bank_name      = $data['bank_name'] ?? '';
    $account_number = $data['account_number'] ?? '';
    $ifsc           = $data['ifsc'] ?? '';
    $account_type   = $data['account_type'] ?? '';

    if(empty($email) || empty($bank_name)){
        echo json_encode(["status"=>false,"message"=>"Missing Fields"]);
        exit;
    }

    // 🔍 CHECK EXIST (IMPORTANT FIX)
    $check = $conn->prepare("SELECT id FROM bank_details WHERE email=? AND user_type=?");
    $check->execute([$email, $user_type]);

    if($check->rowCount() > 0){

        // UPDATE
        $stmt = $conn->prepare("UPDATE bank_details 
            SET bank_name=?, account_number=?, ifsc=?, account_type=? 
            WHERE email=? AND user_type=?");

        $stmt->execute([
            $bank_name,
            $account_number,
            $ifsc,
            $account_type,
            $email,
            $user_type
        ]);

    } else {

        // INSERT (IMPORTANT FIX)
        $stmt = $conn->prepare("INSERT INTO bank_details 
            (email, user_type, bank_name, account_number, ifsc, account_type) 
            VALUES (?,?,?,?,?,?)");

        $stmt->execute([
            $email,
            $user_type,
            $bank_name,
            $account_number,
            $ifsc,
            $account_type
        ]);
    }

    echo json_encode([
        "status"=>true,
        "message"=>"Bank Saved Successfully"
    ]);

} catch(Exception $e){
    echo json_encode([
        "status"=>false,
        "message"=>$e->getMessage()
    ]);
}
?>