<?php
// Clear buffer
if (ob_get_level()) ob_end_clean();

// CORS Headers
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

require_once 'database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        "status" => "error",
        "message" => "Method not allowed. Use POST."
    ]);
    exit();
}

$data = json_decode(file_get_contents("php://input"));

if (empty($data->name) || empty($data->role)) {
    http_response_code(400);
    echo json_encode([
        "status" => "error",
        "message" => "Name and Role are required to generate auto password."
    ]);
    exit();
}

$empName = trim($data->name);
$role = trim($data->role);

// Allowed roles (same as Dart list)
$allowedRoles = [
    'Website Developer',
    'Video Editor',
    'Social Media Executive',
    'SEO Executive',
    'Data Scrapper',
    'Graphics Designer',
];

// Optional: verify role is in allowed list
// (uncomment to enforce strict role validation)
/*
if (!in_array($role, $allowedRoles)) {
    http_response_code(400);
    echo json_encode([
        "status" => "error",
        "message" => "Invalid role. Must be one of: " . implode(', ', $allowedRoles)
    ]);
    exit();
}
*/

try {
    // ---- Step 1: Verify Name + Role combination against users table ----
    // We check if the name (partial) + role already exists to determine counter    // (This is a soft verification - it doesn't block, just counts)
    $checkStmt = $pdo->prepare("
        SELECT COUNT(*) AS total 
        FROM users 
        WHERE LOWER(name) LIKE LOWER(:name_pattern) 
          AND role = :role
    ");
    $namePattern = '%' . $empName . '%';
    $checkStmt->execute([
        ':name_pattern' => $namePattern,
        ':role' => $role
    ]);
    $row = $checkStmt->fetch(PDO::FETCH_ASSOC);
    $existingCount = isset($row['total']) ? intval($row['total']) : 0;

    // ---- Step 2: Build the clean name portion of the password ----
    // Remove non-alphanumeric chars, lowercase, truncate to 8 chars
    $cleanName = preg_replace('/[^a-zA-Z0-9]/', '', $empName);
    $cleanName = strtolower($cleanName);
    if (empty($cleanName)) $cleanName = 'employee';
    if (strlen($cleanName) > 8) $cleanName = substr($cleanName, 0, 8);

    // ---- Step 3: Counter for the same name + role combo ----
    $counter = $existingCount + 1;
    $counterPadded = str_pad($counter, 2, '0', STR_PAD_LEFT);

    // ---- Step 4: Format password:  GS-{EmpName}:@{01} ----
    $autoPassword = "GS-{$cleanName}:@{$counterPadded}";

    // ---- Step 5: Optional — store a hint in DB for audit ----
    // Uncomment if you have a column like `auto_pwd_hint` in users table
    /*
    $auditStmt = $pdo->prepare("
        INSERT INTO auto_password_audit (emp_name, role, auto_password, created_at) 
        VALUES (:name, :role, :pwd, NOW())
    ");
    $auditStmt->execute([
        ':name' => $empName,
        ':role' => $role,
        ':pwd'  => $autoPassword
    ]);
    */

    // ---- Step 6: Response ----
    http_response_code(200);
    echo json_encode([
        "status"        => "success",
        "message"       => "Auto password generated successfully",
        "auto_password" => $autoPassword,
        "emp_name"      => $empName,
        "role"          => $role,
        "counter"       => $counter,
        "format"        => "GS-{name}:@{counter}"
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "status"  => "error",
        "message" => "Database error: " . $e->getMessage()
    ]);
}
?>