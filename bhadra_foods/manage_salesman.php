<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: POST, GET, PUT, DELETE, OPTIONS");
header("Content-Type: application/json; charset=UTF-8");

require_once "data.php";

$database = new Database();
$db = $database->getConnection();

$method = $_SERVER['REQUEST_METHOD'];

function generateEmpId($db, $role) {
    $prefixes = [
        'Salesman'     => 'BHFSM',
        'Sales Officer' => 'BHFSO',
        'ASM'           => 'BHFAS',
        'RSM'           => 'BHFRS',
        'ZSM'           => 'BHFZS',
        'Sales Head'    => 'BHFSH'
    ];
    $prefix = isset($prefixes[$role]) ? $prefixes[$role] : 'BHFEMP';

    try {
        $query = "SELECT COUNT(*) as total FROM users WHERE role = :role";
        $stmt = $db->prepare($query);
        $stmt->bindParam(":role", $role);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $total = isset($row['total']) ? intval($row['total']) : 0;
        $nextNumber = str_pad($total + 1, 2, '0', STR_PAD_LEFT);
        return $prefix . ':-' . $nextNumber;
    } catch (Exception $e) {
        $timestamp = time();
        $lastTwoDigits = substr($timestamp, -2);
        return $prefix . ':-' . $lastTwoDigits;
    }
}

switch($method) {
    case 'GET':
        try {
            $query = "SELECT id, name, emp_id, mobile, email, city, assigned_route, is_live, last_updated, role FROM users WHERE role != 'admin' ORDER BY id DESC";
            $stmt = $db->prepare($query);
            $stmt->execute();
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach($users as &$user) {
                if(!isset($user['emp_id']) || empty($user['emp_id']) || $user['emp_id'] === '0') {
                    $prefixes = [
                        'Salesman' => 'BHFSM',
                        'Sales Officer' => 'BHFSO',
                        'ASM' => 'BHFAS',
                        'RSM' => 'BHFRS',
                        'ZSM' => 'BHFZS',
                        'Sales Head' => 'BHFSH'
                    ];
                    $prefix = isset($prefixes[$user['role']]) ? $prefixes[$user['role']] : 'BHFEMP';
                    $id = isset($user['id']) ? intval($user['id']) : 0;
                    $generated_emp_id = $prefix . ':-' . str_pad($id, 2, '0', STR_PAD_LEFT);
                    
                    try {
                        $updateQuery = "UPDATE users SET emp_id = :emp_id WHERE id = :id";
                        $updateStmt = $db->prepare($updateQuery);
                        $updateStmt->bindParam(":emp_id", $generated_emp_id);
                        $updateStmt->bindParam(":id", $user['id']);
                        $updateStmt->execute();
                    } catch (Exception $e) {
                        error_log("Failed to update emp_id for user: " . $user['id']);
                    }
                    
                    $user['emp_id'] = $generated_emp_id;
                }
            }
            
            echo json_encode(["status" => true, "data" => $users]);
        } catch (Exception $e) {
            echo json_encode(["status" => false, "message" => "Error fetching data: " . $e->getMessage()]);
        }
        break;

    case 'POST':
        try {
            $data = json_decode(file_get_contents("php://input"), true);
            
            if(empty($data['name']) || empty($data['mobile']) || empty($data['role'])) {
                echo json_encode(["status" => false, "message" => "Missing required fields: name, mobile, role are required"]);
                break;
            }
            
            if(!preg_match('/^[0-9]{10,15}$/', $data['mobile'])) {
                echo json_encode(["status" => false, "message" => "Invalid mobile number format"]);
                break;
            }
            
            if(!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                echo json_encode(["status" => false, "message" => "Invalid email format"]);
                break;
            }
            
            $emp_id = generateEmpId($db, $data['role']);
            $user_pwd = !empty($data['password']) ? $data['password'] : '123456';
            $hashed_pwd = password_hash($user_pwd, PASSWORD_BCRYPT);
            
            $query = "INSERT INTO users (name, emp_id, mobile, email, city, role, assigned_route, password, is_live, last_updated) 
                      VALUES (:name, :emp_id, :mobile, :email, :city, :role, :assigned_route, :password, 1, NOW())";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(":name", $data['name']);
            $stmt->bindParam(":emp_id", $emp_id);
            $stmt->bindParam(":mobile", $data['mobile']);
            $stmt->bindParam(":email", $data['email']);
            $stmt->bindParam(":city", $data['city']);
            $stmt->bindParam(":role", $data['role']);
            $route = isset($data['assigned_route']) ? $data['assigned_route'] : '';
            $stmt->bindParam(":assigned_route", $route);
            $stmt->bindParam(":password", $hashed_pwd);

            if($stmt->execute()) {
                $insertedId = $db->lastInsertId();
                echo json_encode([
                    "status" => true, 
                    "message" => "Registered successfully", 
                    "emp_id" => $emp_id,
                    "id" => $insertedId
                ]);
            } else {
                $errorInfo = $stmt->errorInfo();
                echo json_encode(["status" => false, "message" => "Registration failed: " . $errorInfo[2]]);
            }
        } catch (Exception $e) {
            echo json_encode(["status" => false, "message" => "Error: " . $e->getMessage()]);
        }
        break;

    case 'PUT':
        try {
            $data = json_decode(file_get_contents("php://input"), true);
            
            if(empty($data['emp_id']) && empty($data['id'])) {
                echo json_encode(["status" => false, "message" => "Employee ID or ID is required"]);
                break;
            }
            
            if(isset($data['assigned_route_only']) && $data['assigned_route_only'] == true) {
                $query = "UPDATE users SET assigned_route = :assigned_route, last_updated = NOW() WHERE emp_id = :emp_id OR id = :id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(":assigned_route", $data['assigned_route']);
                $stmt->bindParam(":emp_id", $data['emp_id']);
                $stmt->bindParam(":id", $data['id']);
            } else {
                $query = "UPDATE users SET name = :name, mobile = :mobile, email = :email, city = :city, role = :role, assigned_route = :assigned_route, last_updated = NOW() WHERE emp_id = :emp_id OR id = :id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(":name", $data['name']);
                $stmt->bindParam(":mobile", $data['mobile']);
                $stmt->bindParam(":email", $data['email']);
                $stmt->bindParam(":city", $data['city']);
                $stmt->bindParam(":role", $data['role']);
                $stmt->bindParam(":assigned_route", $data['assigned_route']);
                $stmt->bindParam(":emp_id", $data['emp_id']);
                $stmt->bindParam(":id", $data['id']);
            }

            if($stmt->execute()) {
                echo json_encode(["status" => true, "message" => "Updated successfully"]);
            } else {
                echo json_encode(["status" => false, "message" => "Update failed"]);
            }
        } catch (Exception $e) {
            echo json_encode(["status" => false, "message" => "Error: " . $e->getMessage()]);
        }
        break;

    case 'DELETE':
        try {
            $emp_id = isset($_GET['emp_id']) ? $_GET['emp_id'] : null;
            $id = isset($_GET['id']) ? $_GET['id'] : null;
            
            if(empty($emp_id) && empty($id)) {
                echo json_encode(["status" => false, "message" => "Employee ID or ID is required"]);
                break;
            }
            
            $query = "DELETE FROM users WHERE emp_id = :emp_id OR id = :id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(":emp_id", $emp_id);
            $stmt->bindParam(":id", $id);
            
            if($stmt->execute()) {
                echo json_encode(["status" => true, "message" => "Deleted successfully"]);
            } else {
                echo json_encode(["status" => false, "message" => "Failed to delete"]);
            }
        } catch (Exception $e) {
            echo json_encode(["status" => false, "message" => "Error: " . $e->getMessage()]);
        }
        break;

    default:
        echo json_encode(["status" => false, "message" => "Invalid HTTP method"]);
        break;
}
?>