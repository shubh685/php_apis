<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Content-Type: application/json");
error_reporting(0);
ini_set('display_errors', 0);

require_once "database.php";

$raw = file_get_contents("php://input");

if (!$raw) {
    echo json_encode([
        "status"=>false,
        "message"=>"No input received"
    ]);
    exit();
}

$data = json_decode($raw, true);

if (!$data) {
    echo json_encode([
        "status"=>false,
        "message"=>"Invalid JSON"
    ]);
    exit();
}

$email  = $data['email'] ?? '';
$action = $data['action'] ?? '';

if ($email == '') {
    echo json_encode([
        "status"=>false,
        "message"=>"Email required"
    ]);
    exit();
}

try {

    // ================= GET =================
    if ($action == "get") {

        $stmt = $conn->prepare("SELECT * FROM land_details WHERE email=?");
        $stmt->execute([$email]);

        if ($stmt->rowCount() > 0) {
            echo json_encode([
                "status"=>true,
                "data"=>$stmt->fetch(PDO::FETCH_ASSOC)
            ]);
        } else {
            echo json_encode([
                "status"=>false,
                "message"=>"No data found"
            ]);
        }

        exit(); // ✅ VERY IMPORTANT
    }

    // ================= SAVE =================
    if ($action == "save") {

        $check = $conn->prepare("SELECT id FROM land_details WHERE email=?");
        $check->execute([$email]);

        if ($check->rowCount() > 0) {
            echo json_encode([
                "status"=>true,
                "message"=>"Already saved"
            ]);
            exit();
        }

        $stmt = $conn->prepare("INSERT INTO land_details 
        (email, owner_name,address,survey_number,khasra_number,land_type,area,area_unit,soil_type,irrigation,district,state,village,pincode)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

        $insert = $stmt->execute([
            $email,
            $data['owner_name'] ?? '',
            $data['address'] ?? '',
            $data['survey_number'] ?? '',
            $data['khasra_number'] ?? '',
            $data['land_type'] ?? '',
            $data['area'] ?? '',
            $data['area_unit'] ?? '',
            $data['soil_type'] ?? '',
            $data['irrigation'] ?? '',
            $data['district'] ?? '',
            $data['state'] ?? '',
            $data['village'] ?? '',
            $data['pincode'] ?? ''
        ]);

        echo json_encode([
            "status"=>$insert,
            "message"=>$insert ? "Saved successfully" : "Failed"
        ]);

        exit(); // ✅ VERY IMPORTANT
    }

    echo json_encode([
        "status"=>false,
        "message"=>"Invalid action"
    ]);

} catch (Exception $e) {
    echo json_encode([
        "status"=>false,
        "message"=>"Server error"
    ]);
}
?>