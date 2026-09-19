<?Php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

require_once 'database.php';

try {
    // Query to find the maximum existing numeric suffix from emp_id
    // CAST extracts the number after 'GS-E-' for correct numeric sorting
    $query = "SELECT MAX(CAST(SUBSTRING(emp_id, 6) AS UNSIGNED)) AS max_id 
              FROM users 
              WHERE role = 'Employee' AND emp_id LIKE 'GS-E-%'";
              
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    // If no employee records exist, start at 1; otherwise, increment max_id
    $next_number = ($row['max_id'] !== null) ? ((int)$row['max_id'] + 1) : 1;

    // Format the number with 2-digit zero padding (e.g., 1 -> GS-E-01, 10 -> GS-E-10)
    $formatted_emp_id = sprintf("GS-E-%02d", $next_number);

    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'next_emp_id' => $formatted_emp_id
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Failed to generate Employee ID: ' . $e->getMessage()
    ]);
}
?> 
