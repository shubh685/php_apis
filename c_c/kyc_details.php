<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Content-Type: application/json");

require_once "database.php";

$email     = $_POST['email'] ?? '';
$user_type = $_POST['user_type'] ?? '';

$aadharNumber = $_POST['aadhar_number'] ?? '';
$panNumber    = $_POST['pan_number'] ?? '';

$uploadDir = "uploads/kyc/";

if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

$aadharPath = "";
$panPath = "";

/* =========================
   📄 AADHAR LOGIC
========================= */

// If file uploaded → use file
if (isset($_FILES['aadhar']) && $_FILES['aadhar']['name'] != "") {

    $fileName = time() . "_aadhar_" . $_FILES['aadhar']['name'];
    $target = $uploadDir . $fileName;

    if (move_uploaded_file($_FILES['aadhar']['tmp_name'], $target)) {
        $aadharPath = $target;
    }

}
// Else if number entered → convert to filename
else if (!empty($aadharNumber)) {
    $aadharPath = $aadharNumber . ".pdf"; // or .jpg
}


/* =========================
   📄 PAN LOGIC
========================= */

if (isset($_FILES['pan']) && $_FILES['pan']['name'] != "") {

    $fileName = time() . "_pan_" . $_FILES['pan']['name'];
    $target = $uploadDir . $fileName;

    if (move_uploaded_file($_FILES['pan']['tmp_name'], $target)) {
        $panPath = $target;
    }

}
else if (!empty($panNumber)) {
    $panPath = $panNumber . ".jpg"; // or .pdf
}


/* =========================
   🔍 CHECK EXIST
========================= */

$check = $conn->prepare("SELECT id FROM kyc_details WHERE email=? AND user_type=?");
$check->execute([$email, $user_type]);

if ($check->rowCount() > 0) {

    // UPDATE (keep old if empty)
    $stmt = $conn->prepare("UPDATE kyc_details 
        SET 
        aadhar_file = IF(? != '', ?, aadhar_file),
        pan_file    = IF(? != '', ?, pan_file)
        WHERE email=? AND user_type=?");

    $stmt->execute([
        $aadharPath, $aadharPath,
        $panPath, $panPath,
        $email, $user_type
    ]);

} else {

    // INSERT
    $stmt = $conn->prepare("INSERT INTO kyc_details 
        (email, user_type, aadhar_file, pan_file) 
        VALUES (?,?,?,?)");

    $stmt->execute([
        $email,
        $user_type,
        $aadharPath,
        $panPath
    ]);
}


/* =========================
   📥 FETCH UPDATED DATA
========================= */

$get = $conn->prepare("SELECT aadhar_file, pan_file FROM kyc_details WHERE email=? AND user_type=?");
$get->execute([$email, $user_type]);

$data = $get->fetch(PDO::FETCH_ASSOC);

echo json_encode([
    "status" => true,
    "message" => "KYC Saved Successfully",
    "data" => [
        "aadhar_file" => $data['aadhar_file'] ?? '',
        "pan_file"    => $data['pan_file'] ?? ''
    ]
]);

?>