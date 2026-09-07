<?php
// Prevent PHP from printing HTML warnings/errors inline
ini_set('display_errors', 0);
error_reporting(E_ALL);

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli("localhost", "root", "", "bhadra_foods");

    $emp_id     = $_POST['emp_id'] ?? '';
    $role       = $_POST['role'] ?? '';
    $punch_type = $_POST['punch_type'] ?? 'PUNCH_IN';

    if (empty($emp_id) || empty($role)) {
        echo json_encode(["status" => "error", "message" => "emp_id and role are required"]);
        exit();
    }

    // Punch Out restrictions
    if ($punch_type === 'PUNCH_OUT') {
        $currentHour = (int)date('H');
        $currentMinute = (int)date('i');
        $currentTimeInMinutes = ($currentHour * 60) + $currentMinute;
        $startTime = 9 * 60;
        $endTime = 18 * 60;
        $dayOfWeek = (int)date('N');

        if ($dayOfWeek === 7) {
            echo json_encode(["status" => "error", "message" => "Punch Out not allowed on Sunday"]);
            $conn->close();
            exit();
        }

        if ($currentTimeInMinutes < $startTime || $currentTimeInMinutes > $endTime) {
            echo json_encode(["status" => "error", "message" => "Punch Out allowed only between 9:00 AM and 6:00 PM"]);
            $conn->close();
            exit();
        }

        $checkStmt = $conn->prepare("SELECT id FROM attendance WHERE emp_id = ? AND DATE(created_at) = CURDATE() AND punch_type = 'PUNCH_IN' LIMIT 1");
        $checkStmt->bind_param("s", $emp_id);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        
        if ($checkResult->num_rows === 0) {
            echo json_encode(["status" => "error", "message" => "No Punch In found for today. Please Punch In first."]);
            $conn->close();
            exit();
        }

        $checkOutStmt = $conn->prepare("SELECT id FROM attendance WHERE emp_id = ? AND DATE(created_at) = CURDATE() AND punch_type = 'PUNCH_OUT' LIMIT 1");
        $checkOutStmt->bind_param("s", $emp_id);
        $checkOutStmt->execute();
        $checkOutResult = $checkOutStmt->get_result();
        
        if ($checkOutResult->num_rows > 0) {
            echo json_encode(["status" => "error", "message" => "You have already punched out today!"]);
            $conn->close();
            exit();
        }
    }

    $imagePath = NULL;

    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . "/uploads/attendance/";
        
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $maxSize = 5 * 1024 * 1024;
        if ($_FILES['photo']['size'] > $maxSize) {
            echo json_encode(["status" => "error", "message" => "Photo size exceeds 5MB limit"]);
            exit();
        }

        $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $_FILES['photo']['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mimeType, $allowedTypes)) {
            echo json_encode(["status" => "error", "message" => "Invalid photo format. Only JPEG, PNG, WebP allowed"]);
            exit();
        }

        $fileExtension = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        $fileName = "punch_" . time() . "_" . preg_replace('/[^A-Za-z0-9\-]/', '', $emp_id) . "." . $fileExtension;
        $targetFilePath = $uploadDir . $fileName;

        if (move_uploaded_file($_FILES['photo']['tmp_name'], $targetFilePath)) {
            $imagePath = "uploads/attendance/" . $fileName;
        } else {
            echo json_encode(["status" => "error", "message" => "Failed to upload photo"]);
            exit();
        }
    }

    $punchDate = date('Y-m-d');
    $punchTime = date('H:i:s');
    $day = date('l');

    $stmt = $conn->prepare("INSERT INTO attendance (emp_id, role, photo, punch_type, punch_date, punch_time, day, created_at) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
    $stmt->bind_param("sssssss", $emp_id, $role, $imagePath, $punch_type, $punchDate, $punchTime, $day);
    $stmt->execute();

    echo json_encode([
        "status" => "success",
        "message" => "Attendance recorded successfully",
        "photo_url" => $imagePath,
        "punch_type" => $punch_type,
        "punch_date" => $punchDate,
        "punch_time" => $punchTime,
        "day" => $day
    ]);

    $conn->close();

} catch (Throwable $e) {
    echo json_encode([
        "status" => "error",
        "message" => "Server exception: " . $e->getMessage()
    ]);
}
?>