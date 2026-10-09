<?php
// Prevent PHP errors from printing HTML into the JSON response
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/data.php';

try {
    $db = new Database();
    $conn = $db->getConnection();

    if (!$conn) {
        throw new Exception("Database connection failed.");
    }

    $emp_id_input = trim($_GET['emp_id'] ?? $_GET['user_id'] ?? '');

    // Support "all" for admin view
    $fetchAll = (strtolower($emp_id_input) === 'all');

    if (!$fetchAll && $emp_id_input === '') {
        echo json_encode([
            "status" => false,
            "message" => "Employee ID or User ID is required",
            "history" => []
        ]);
        exit();
    }

    if ($fetchAll) {
        // Fetch all records using PDO
        $stmt = $conn->prepare("SELECT * FROM attendance ORDER BY id DESC");
        $stmt->execute();
    } else {
        // Step 1: Resolve internal numeric user ID using the alphanumeric emp_id (e.g., 'BHFSO-01')
        $userStmt = $conn->prepare("SELECT id FROM users WHERE emp_id = :emp_id LIMIT 1");
        $userStmt->execute([':emp_id' => $emp_id_input]);
        $userData = $userStmt->fetch(PDO::FETCH_ASSOC);

        if ($userData) {
            $resolved_id = $userData['id'];
        } else {
            // Fallback in case $emp_id_input was already the internal numeric ID
            $resolved_id = $emp_id_input;
        }

        // Step 2: Query attendance using resolved ID or direct input match (REMOVED user_id)
        $stmt = $conn->prepare(
            "SELECT * FROM attendance 
             WHERE emp_id = :resolved_id 
                OR emp_id = :input_id 
             ORDER BY id DESC"
        );
        $stmt->execute([
            ':resolved_id' => $resolved_id,
            ':input_id'    => $emp_id_input
        ]);
    }

    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "status" => true,
        "message" => "Attendance fetched successfully",
        "count" => count($history),
        "history" => $history
    ]);

} catch (Throwable $e) {
    echo json_encode([
        "status" => false,
        "message" => "Server exception: " . $e->getMessage(),
        "history" => []
    ]);
}
?>