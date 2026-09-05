<?php
// register.php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Content-Type: application/json");
header("Content-Type: application/json; charset=UTF-8");

// Handle CORS preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'data.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);

    echo json_encode([
        "success" => false,
        "message" => "Method not allowed. Use POST request."
    ]);

    exit();
}


// ---------------------------------------------------------
// GET REQUEST DATA
// ---------------------------------------------------------

// Fallback logic: check $_POST first, then read standard stream if empty
$data = $_POST;

if (empty($data)) {

    $rawInput = file_get_contents('php://input');

    $decoded = json_decode($rawInput, true);

    if (is_array($decoded)) {
        $data = $decoded;
    }
}


// ---------------------------------------------------------
// CLEAN INPUT PARAMETERS
// ---------------------------------------------------------

$name = isset($data['name'])
    ? trim($data['name'])
    : '';

$email = isset($data['email'])
    ? trim($data['email'])
    : '';

$mobile = isset($data['mobile'])
    ? trim($data['mobile'])
    : '';

$password = isset($data['password'])
    ? $data['password']
    : '';

$role = isset($data['role'])
    ? trim($data['role'])
    : 'Admin';

$emp_id = isset($data['emp_id'])
    ? trim($data['emp_id'])
    : null;


// ---------------------------------------------------------
// VALIDATION
// ---------------------------------------------------------

if (
    empty($name) ||
    empty($email) ||
    empty($mobile) ||
    empty($password)
) {

    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Name, email, mobile, and password are required fields."
    ]);

    exit();
}


// ---------------------------------------------------------
// EMAIL VALIDATION
// ---------------------------------------------------------

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Invalid email format."
    ]);

    exit();
}


// ---------------------------------------------------------
// MOBILE VALIDATION
// ---------------------------------------------------------

if (!preg_match('/^[0-9]{10}$/', $mobile)) {

    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Mobile number must be exactly 10 digits."
    ]);

    exit();
}


// ---------------------------------------------------------
// ADMIN ROLE ONLY
// ---------------------------------------------------------

if (strtolower($role) !== 'admin') {

    http_response_code(403);

    echo json_encode([
        "success" => false,
        "message" => "Only Admin registration is permitted through this endpoint."
    ]);

    exit();
}


try {

    // -----------------------------------------------------
    // DATABASE CONNECTION
    // -----------------------------------------------------

    $db = new Database();

    $conn = $db->getConnection();

    if (!$conn) {
        throw new Exception("Database connection failure.");
    }


    // -----------------------------------------------------
    // CHECK EMAIL OR MOBILE ALREADY EXISTS
    // -----------------------------------------------------

    $checkQuery = "
        SELECT id, emp_id, name, email, mobile, role
        FROM users
        WHERE email = :email
        OR mobile = :mobile
        LIMIT 1
    ";

    $stmt = $conn->prepare($checkQuery);

    $stmt->execute([
        ':email'  => $email,
        ':mobile' => $mobile
    ]);


    $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);


    // -----------------------------------------------------
    // USER ALREADY EXISTS
    // -----------------------------------------------------

    if ($existingUser) {

        // IMPORTANT:
        // Return HTTP 200 instead of 409
        http_response_code(200);

        echo json_encode([
            "success" => false,
            "message" => "An account with this email or mobile number already exists.",
            "data" => [
                "id"     => $existingUser['id'],
                "emp_id" => $existingUser['emp_id'],
                "name"   => $existingUser['name'],
                "email"  => $existingUser['email'],
                "mobile" => $existingUser['mobile'],
                "role"   => $existingUser['role']
            ]
        ]);

        exit();
    }


    // -----------------------------------------------------
    // AUTO-GENERATE EMPLOYEE ID
    // Example: ADM1001
    // -----------------------------------------------------

    if (empty($emp_id)) {

        $countQuery = "
            SELECT COUNT(id) AS total
            FROM users
            WHERE role = 'Admin'
        ";

        $countStmt = $conn->query($countQuery);

        $result = $countStmt->fetch(PDO::FETCH_ASSOC);

        $totalAdmins = isset($result['total'])
            ? (int)$result['total']
            : 0;

        $emp_id = 'ADM' . (1000 + $totalAdmins + 1);
    }


    // -----------------------------------------------------
    // HASH PASSWORD
    // -----------------------------------------------------

    $hashedPassword = password_hash(
        $password,
        PASSWORD_BCRYPT
    );


    // -----------------------------------------------------
    // CURRENT DATE/TIME
    // -----------------------------------------------------

    $currentTime = date('Y-m-d H:i:s');


    // -----------------------------------------------------
    // INSERT ADMIN
    // -----------------------------------------------------

    $insertQuery = "
        INSERT INTO users
        (
            name,
            emp_id,
            mobile,
            email,
            role,
            password,
            reset_otp_hash,
            reset_otp_expires_at,
            created_at,
            updated_at
        )
        VALUES
        (
            :name,
            :emp_id,
            :mobile,
            :email,
            :role,
            :password,
            NULL,
            NULL,
            :created_at,
            :updated_at
        )
    ";


    $insertStmt = $conn->prepare($insertQuery);


    $executed = $insertStmt->execute([

        ':name'       => $name,

        ':emp_id'     => $emp_id,

        ':mobile'     => $mobile,

        ':email'      => $email,

        ':role'       => 'Admin',

        ':password'   => $hashedPassword,

        ':created_at' => $currentTime,

        ':updated_at' => $currentTime

    ]);


    // -----------------------------------------------------
    // REGISTRATION SUCCESS
    // -----------------------------------------------------

    if ($executed) {

        // IMPORTANT:
        // Successful registration also returns HTTP 200
        http_response_code(200);

        echo json_encode([

            "success" => true,

            "message" => "Admin registered successfully.",

            "data" => [

                "id" => $conn->lastInsertId(),

                "emp_id" => $emp_id,

                "name" => $name,

                "email" => $email,

                "mobile" => $mobile,

                "role" => "Admin"

            ]

        ]);

    } else {

        http_response_code(500);

        echo json_encode([

            "success" => false,

            "message" => "Failed to complete registration."

        ]);
    }


} catch (PDOException $e) {

    // -----------------------------------------------------
    // DATABASE ERROR
    // -----------------------------------------------------

    http_response_code(500);

    echo json_encode([

        "success" => false,

        "message" => "Database error: " . $e->getMessage()

    ]);


} catch (Exception $e) {

    // -----------------------------------------------------
    // GENERAL ERROR
    // -----------------------------------------------------

    http_response_code(500);

    echo json_encode([

        "success" => false,

        "message" => "Error: " . $e->getMessage()

    ]);
}

?>