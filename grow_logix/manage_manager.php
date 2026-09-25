<?php
if (ob_get_level()) ob_end_clean();

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

require_once 'database.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// --- 1. GET NEXT EMPLOYEE ID ---
if ($method === 'GET' && $action === 'get_emp_id') {
    try {
        $query = "SELECT MAX(CAST(SUBSTRING(emp_id, 6) AS UNSIGNED)) AS max_id FROM users WHERE emp_id LIKE 'GS-E-%'";
        $stmt = $pdo->prepare($query);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $next_number = ($row && $row['max_id'] !== null) ? ((int)$row['max_id'] + 1) : 1;
        $formatted_emp_id = sprintf("GS-E-%02d", $next_number);

        http_response_code(200);
        echo json_encode(['status' => 'success', 'next_emp_id' => $formatted_emp_id]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit();
}

// --- 2. FETCH ALL DEVICES ---
if ($method === 'GET' && $action === 'get_devices') {
    try {
        $query = "SELECT d.id, d.pc_number, d.device_id, d.device_name, d.emp_id, d.assigned_user_id, d.last_login_at, u.name as user_name, u.role 
                  FROM devices d 
                  LEFT JOIN users u ON (d.assigned_user_id = u.id OR (d.emp_id IS NOT NULL AND d.emp_id = u.emp_id))
                  GROUP BY d.id
                  ORDER BY d.pc_number ASC";
        $stmt = $pdo->prepare($query);
        $stmt->execute();
        $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);

        http_response_code(200);
        echo json_encode(['status' => 'success', 'data' => $devices]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit();
}

// --- 3. FETCH ALL EMPLOYEES ---
if ($method === 'GET') {
    try {
        $query = "SELECT u.id, u.emp_id, u.name, u.email, u.mobile, u.role, d.pc_number, d.device_id, d.device_name 
                  FROM users u 
                  LEFT JOIN devices d ON (u.id = d.assigned_user_id OR (u.emp_id IS NOT NULL AND u.emp_id = d.emp_id))
                  GROUP BY u.id
                  ORDER BY u.id DESC";
        $stmt = $pdo->prepare($query);
        $stmt->execute();
        $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

        http_response_code(200);
        echo json_encode(['status' => 'success', 'data' => $employees]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit();
}

$data = json_decode(file_get_contents("php://input"));

// --- 4. CREATE EMPLOYEE ---
if ($method === 'POST') {
    if (!empty($data->name) && !empty($data->email) && !empty($data->password) && !empty($data->emp_id)) {
        try {
            $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
            $checkStmt->execute([':email' => trim($data->email)]);
            if ($checkStmt->rowCount() > 0) {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "Email already exists."]);
                exit();
            }

            $query = "INSERT INTO users (emp_id, name, email, mobile, password, role) VALUES (:emp_id, :name, :email, :mobile, :password, :role)";
            $stmt = $pdo->prepare($query);
            $stmt->execute([
                ':emp_id' => trim($data->emp_id),
                ':name' => trim($data->name),
                ':email' => trim($data->email),
                ':mobile' => $data->mobile ?? '',
                ':password' => password_hash($data->password, PASSWORD_BCRYPT),
                ':role' => !empty($data->role) ? trim($data->role) : 'Employee'
            ]);

            http_response_code(201);
            echo json_encode(["status" => "success", "message" => "Employee created successfully."]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
    } else {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Incomplete fields."]);
    }
    exit();
}

// --- 5. UPDATE EMPLOYEE ---
if ($method === 'PUT') {
    if (!empty($data->id) && !empty($data->name) && !empty($data->email)) {
        try {
            if (!empty($data->password)) {
                $query = "UPDATE users SET name = :name, email = :email, mobile = :mobile, role = :role, password = :password WHERE id = :id";
                $params = [
                    ':name' => trim($data->name),
                    ':email' => trim($data->email),
                    ':mobile' => $data->mobile ?? '',
                    ':role' => !empty($data->role) ? trim($data->role) : 'Employee',
                    ':password' => password_hash($data->password, PASSWORD_BCRYPT),
                    ':id' => $data->id
                ];
            } else {
                $query = "UPDATE users SET name = :name, email = :email, mobile = :mobile, role = :role WHERE id = :id";
                $params = [
                    ':name' => trim($data->name),
                    ':email' => trim($data->email),
                    ':mobile' => $data->mobile ?? '',
                    ':role' => !empty($data->role) ? trim($data->role) : 'Employee',
                    ':id' => $data->id
                ];
            }

            $stmt = $pdo->prepare($query);
            $stmt->execute($params);

            // Device update handling
            if (!empty($data->device_id)) {
                $devId = trim($data->device_id);
                $devName = !empty($data->device_name) ? trim($data->device_name) : 'Company PC';
                $userId = $data->id;
                $empId = $data->emp_id ?? null;

                $chkUserDev = $pdo->prepare("SELECT id FROM devices WHERE assigned_user_id = :user_id OR (emp_id IS NOT NULL AND emp_id = :emp_id) LIMIT 1");
                $chkUserDev->execute([':user_id' => $userId, ':emp_id' => $empId]);
                $existingUserDevice = $chkUserDev->fetch(PDO::FETCH_ASSOC);

                if ($existingUserDevice) {
                    $upDev = $pdo->prepare("
                        UPDATE devices 
                        SET device_id = :device_id,
                            emp_id = :emp_id, 
                            assigned_user_id = :user_id,
                            device_name = :device_name,
                            last_login_at = NOW() 
                        WHERE id = :id
                    ");
                    $upDev->execute([
                        ':device_id' => $devId,
                        ':emp_id' => $empId,
                        ':user_id' => $userId,
                        ':device_name' => $devName,
                        ':id' => $existingUserDevice['id']
                    ]);
                } else {
                    $chkDev = $pdo->prepare("SELECT id FROM devices WHERE device_id = :device_id LIMIT 1");
                    $chkDev->execute([':device_id' => $devId]);
                    $devExists = $chkDev->fetch(PDO::FETCH_ASSOC);

                    if ($devExists) {
                        $upDev = $pdo->prepare("
                            UPDATE devices 
                            SET assigned_user_id = :user_id, 
                                emp_id = :emp_id, 
                                device_name = :device_name,
                                last_login_at = NOW() 
                            WHERE device_id = :device_id
                        ");
                        $upDev->execute([
                            ':user_id' => $userId,
                            ':emp_id' => $empId,
                            ':device_name' => $devName,
                            ':device_id' => $devId
                        ]);
                    } else {
                        $maxPcStmt = $pdo->query("SELECT MAX(pc_number) AS max_pc FROM devices");
                        $maxPcRow = $maxPcStmt->fetch(PDO::FETCH_ASSOC);
                        $nextPcNum = ($maxPcRow && $maxPcRow['max_pc'] !== null) ? ((int)$maxPcRow['max_pc'] + 1) : 1;

                        $insDev = $pdo->prepare("
                            INSERT INTO devices (pc_number, device_id, device_name, assigned_user_id, emp_id, last_login_at)
                            VALUES (:pc_number, :device_id, :device_name, :user_id, :emp_id, NOW())
                        ");
                        $insDev->execute([
                            ':pc_number' => $nextPcNum,
                            ':device_id' => $devId,
                            ':device_name' => $devName,
                            ':user_id' => $userId,
                            ':emp_id' => $empId
                        ]);
                    }
                }
            }

            http_response_code(200);
            echo json_encode(["status" => "success", "message" => "Employee updated successfully."]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
    } else {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Incomplete fields for update."]);
    }
    exit();
}

// --- 6. DELETE EMPLOYEE ---
if ($method === 'DELETE') {
    if (!empty($data->id)) {
        try {
            $unassign = $pdo->prepare("UPDATE devices SET assigned_user_id = NULL, emp_id = NULL WHERE assigned_user_id = :id");
            $unassign->execute([':id' => $data->id]);

            $stmt = $pdo->prepare("DELETE FROM users WHERE id = :id");
            $stmt->execute([':id' => $data->id]);

            http_response_code(200);
            echo json_encode(["status" => "success", "message" => "Employee removed & Device unassigned successfully."]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
    } else {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "ID required for deletion."]);
    }
    exit();
}
?>