<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Content-Type: application/json; charset=UTF-8");

require_once "data.php";

$database = new Database();
$db = $database->getConnection();

// Get input data
$data = json_decode(file_get_contents("php://input"), true);

// Debug: Log received data (remove in production)
error_log("Change Password Request: " . print_r($data, true));

// Check if required fields exist
if(empty($data['identifier'])) {
    echo json_encode(["status" => false, "message" => "Identifier (emp_id/mobile/email) is required"]);
    exit();
}

if(empty($data['old_password'])) {
    echo json_encode(["status" => false, "message" => "Current password is required"]);
    exit();
}

if(empty($data['new_password'])) {
    echo json_encode(["status" => false, "message" => "New password is required"]);
    exit();
}

$identifier = $data['identifier'];
$old_pwd = $data['old_password'];
$new_pwd = $data['new_password'];

// Validate new password length
if(strlen($new_pwd) < 4) {
    echo json_encode(["status" => false, "message" => "New password must be at least 4 characters"]);
    exit();
}

try {
    // Find user by emp_id, mobile, or email
    $query = "SELECT id, password FROM users WHERE emp_id = :id OR mobile = :id OR email = :id LIMIT 1";
    $stmt = $db->prepare($query);
    $stmt->bindParam(":id", $identifier);
    $stmt->execute();

    if($stmt->rowCount() > 0) {
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Verify current password (supports bcrypt or plain text)
        $passwordValid = false;
        
        // Check if password is hashed with bcrypt
        if(password_get_info($row['password'])['algo'] !== 0) {
            // Password is hashed with bcrypt
            $passwordValid = password_verify($old_pwd, $row['password']);
        } else {
            // Password is plain text (fallback)
            $passwordValid = ($old_pwd === $row['password']);
        }
        
        if($passwordValid) {
            // Hash new password
            $hashed_new_pwd = password_hash($new_pwd, PASSWORD_BCRYPT);
            
            // Update password
            $updateQuery = "UPDATE users SET password = :new_password, last_updated = NOW() WHERE id = :user_id";
            $updateStmt = $db->prepare($updateQuery);
            $updateStmt->bindParam(":new_password", $hashed_new_pwd);
            $updateStmt->bindParam(":user_id", $row['id']);
            
            if($updateStmt->execute()) {
                echo json_encode(["status" => true, "message" => "Password updated successfully"]);
            } else {
                echo json_encode(["status" => false, "message" => "Failed to update password"]);
            }
        } else {
            echo json_encode(["status" => false, "message" => "Incorrect current password"]);
        }
    } else {
        echo json_encode(["status" => false, "message" => "User record not found with identifier: $identifier"]);
    }
} catch (Exception $e) {
    error_log("Change Password Error: " . $e->getMessage());
    echo json_encode(["status" => false, "message" => "Database error: " . $e->getMessage()]);
}
?>ssssssssss