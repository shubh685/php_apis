<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Content-Type: application/json");
header("Content-Type: application/json; charset=UTF-8");

require_once "data.php"; //

$database = new Database(); //
$db = $database->getConnection(); //

if (!$db) {
    echo json_encode(["status" => false, "message" => "Database connection failed"]);
    exit();
}

$role = isset($_GET['role']) ? $_GET['role'] : 'admin';

$query = "SELECT id, name, emp_id, mobile, email, city, role, last_updated FROM users WHERE role = :role LIMIT 1";
$stmt = $db->prepare($query);
$stmt->bindParam(":role", $role);
$stmt->execute();

if ($stmt->rowCount() > 0) {
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo json_encode(["status" => true, "data" => $row]);
} else {
    echo json_encode(["status" => false, "message" => "Admin user not found"]);
}
?>