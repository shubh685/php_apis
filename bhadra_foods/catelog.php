<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Content-Type: application/json");
header("Content-Type: application/json; charset=UTF-8");

require_once "data.php";

$database = new Database();
$db = $database->getConnection();

$method = $_SERVER['REQUEST_METHOD'];

switch($method) {
    case 'GET':
        $query = "SELECT id, category, sub_category, name, price FROM product_catalog ORDER BY id DESC";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(["status" => true, "data" => $products]);
        break;

    case 'POST':
        $data = json_decode(file_get_contents("php://input"), true);
        if(!empty($data['category']) && !empty($data['name']) && isset($data['price'])) {
            $query = "INSERT INTO product_catalog (category, sub_category, name, price) VALUES (:category, :sub_category, :name, :price)";
            $stmt = $db->prepare($query);
            $stmt->bindParam(":category", $data['category']);
            $sub = isset($data['sub_category']) ? $data['sub_category'] : '';
            $stmt->bindParam(":sub_category", $sub);
            $stmt->bindParam(":name", $data['name']);
            $stmt->bindParam(":price", $data['price']);
            
            if($stmt->execute()) {
                echo json_encode(["status" => true, "message" => "Product added successfully", "id" => $db->lastInsertId()]);
            } else {
                echo json_encode(["status" => false, "message" => "Failed to add product"]);
            }
        } else {
            echo json_encode(["status" => false, "message" => "Incomplete data"]);
        }
        break;

    case 'PUT':
        $data = json_decode(file_get_contents("php://input"), true);
        if(!empty($data['id']) && !empty($data['name']) && isset($data['price'])) {
            $query = "UPDATE product_catalog SET category = :category, sub_category = :sub_category, name = :name, price = :price WHERE id = :id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(":id", $data['id']);
            $category = isset($data['category']) ? $data['category'] : '';
            $sub = isset($data['sub_category']) ? $data['sub_category'] : '';
            $stmt->bindParam(":category", $category);
            $stmt->bindParam(":sub_category", $sub);
            $stmt->bindParam(":name", $data['name']);
            $stmt->bindParam(":price", $data['price']);

            if($stmt->execute()) {
                echo json_encode(["status" => true, "message" => "Product updated successfully"]);
            } else {
                echo json_encode(["status" => false, "message" => "Failed to update product"]);
            }
        } else {
            echo json_encode(["status" => false, "message" => "Incomplete data"]);
        }
        break;

    case 'DELETE':
        $id = isset($_GET['id']) ? $_GET['id'] : null;
        if($id) {
            $query = "DELETE FROM product_catalog WHERE id = :id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(":id", $id);
            if($stmt->execute()) {
                echo json_encode(["status" => true, "message" => "Product deleted successfully"]);
            } else {
                echo json_encode(["status" => false, "message" => "Failed to delete product"]);
            }
        } else {
            echo json_encode(["status" => false, "message" => "Missing product ID"]);
        }
        break;

    default:
        echo json_encode(["status" => false, "message" => "Invalid HTTP method"]);
        break;
}
?>