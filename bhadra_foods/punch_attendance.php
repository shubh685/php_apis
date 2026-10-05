<?php
// Hide PHP errors from response (only log them)
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli("localhost", "root", "", "bhadra_foods");
    $conn->set_charset("utf8mb4");

    // ── Read inputs ──
    $emp_id_str  = trim($_POST['emp_id']     ?? '');
    $role        = trim($_POST['role']       ?? '');
    $punch_type  = strtoupper(trim($_POST['punch_type'] ?? 'PUNCH_IN'));
    $device_date = $_POST['device_date'] ?? date('Y-m-d');
    $device_time = $_POST['device_time'] ?? date('H:i:s');
    $day         = date('l', strtotime($device_date));
    $isAuto      = ($_POST['is_auto'] ?? '0') === '1';

    // ── Validate ──
    if ($emp_id_str === '' || $role === '') {
        echo json_encode(["status" => "error", "message" => "emp_id and role are required"]);
        exit();
    }

    if (!in_array($punch_type, ['PUNCH_IN', 'PUNCH_OUT'], true)) {
        echo json_encode(["status" => "error", "message" => "Invalid punch_type"]);
        exit();
    }

    // ── 1. Resolve internal user ID from emp_id ──
    $userStmt = $conn->prepare("SELECT id FROM users WHERE emp_id = ? LIMIT 1");
    $userStmt->bind_param("s", $emp_id_str);
    $userStmt->execute();
    $userResult = $userStmt->get_result();

    if ($userResult->num_rows === 0) {
        echo json_encode(["status" => "error", "message" => "Invalid Employee ID provided"]);
        $conn->close();
        exit();
    }

    $userData        = $userResult->fetch_assoc();
    $resolved_emp_id = $userData['id'];
    $userStmt->close();

    // ── 2. Time rules ──
    $timeParts            = explode(':', $device_time);
    $currentHour          = (int)($timeParts[0] ?? 0);
    $currentMinute        = (int)($timeParts[1] ?? 0);
    $currentTimeInMinutes = ($currentHour * 60) + $currentMinute;
    $startTime            = 9 * 60;   // 9:00 AM
    $endTime              = 18 * 60;  // 6:00 PM
    $dayOfWeek            = (int)date('N', strtotime($device_date)); // 1=Mon … 7=Sun

    if ($dayOfWeek === 7) {
        echo json_encode(["status" => "error", "message" => "Punch not allowed on Sunday"]);
        $conn->close();
        exit();
    }

    // Check if a row already exists for today (single-row-per-day model)
    $existingRowStmt = $conn->prepare(
        "SELECT id, photo, punch_type FROM attendance WHERE emp_id = ? AND punch_date = ? LIMIT 1"
    );
    $existingRowStmt->bind_param("ss", $resolved_emp_id, $device_date);
    $existingRowStmt->execute();
    $existingResult = $existingRowStmt->get_result();
    $existingRow    = $existingResult->num_rows > 0 ? $existingResult->fetch_assoc() : null;
    $existingRowStmt->close();

    // ── PUNCH_IN rules ──
    if ($punch_type === 'PUNCH_IN') {
        if ($currentTimeInMinutes >= $endTime) {
            echo json_encode(["status" => "error", "message" => "Punch In closed for today (after 6:00 PM)"]);
            $conn->close();
            exit();
        }

        if ($existingRow && $existingRow['punch_type'] === 'PUNCH_IN') {
            echo json_encode(["status" => "error", "message" => "You are already punched in. Punch Out first."]);
            $conn->close();
            exit();
        }
    }

    // ── PUNCH_OUT rules ──
    if ($punch_type === 'PUNCH_OUT') {
        if (!$existingRow) {
            echo json_encode(["status" => "error", "message" => "No Punch In found for today. Please Punch In first."]);
            $conn->close();
            exit();
        }

        if ($existingRow['punch_type'] === 'PUNCH_OUT') {
            echo json_encode(["status" => "error", "message" => "You have already punched out today!"]);
            $conn->close();
            exit();
        }

        // Manual punch-out must be within 9 AM – 6 PM. Auto punch-out bypasses.
        if (!$isAuto && ($currentTimeInMinutes < $startTime || $currentTimeInMinutes > $endTime)) {
            echo json_encode([
                "status"  => "error",
                "message" => "Punch Out allowed only between 9:00 AM and 6:00 PM"
            ]);
            $conn->close();
            exit();
        }
    }

    // ── 3. Photo upload ──
    $imagePath = null;

    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . "/uploads/attendance/";

        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0777, true);
        }

        $maxSize = 5 * 1024 * 1024;
        if ($_FILES['photo']['size'] > $maxSize) {
            echo json_encode(["status" => "error", "message" => "Photo size exceeds 5MB limit"]);
            $conn->close();
            exit();
        }

        $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
        $finfo        = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType     = finfo_file($finfo, $_FILES['photo']['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mimeType, $allowedTypes, true)) {
            echo json_encode(["status" => "error", "message" => "Invalid photo format. Only JPEG, PNG, WebP allowed"]);
            $conn->close();
            exit();
        }

        $fileExtension = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        if ($fileExtension === '') {
            $fileExtension = 'jpg';
        }

        $safeEmpId  = preg_replace('/[^A-Za-z0-9\-]/', '', $emp_id_str);
        $fileName   = "punch_" . time() . "_" . $safeEmpId . "_" . bin2hex(random_bytes(3)) . "." . $fileExtension;
        $targetPath = $uploadDir . $fileName;

        if (move_uploaded_file($_FILES['photo']['tmp_name'], $targetPath)) {
            $imagePath = "uploads/attendance/" . $fileName;
        } else {
            echo json_encode(["status" => "error", "message" => "Failed to upload photo"]);
            $conn->close();
            exit();
        }
    }

    // ── 4. Insert or Update the single daily row ──
    if ($existingRow) {
        // Append new photo to comma-separated list
        $currentPhotos = ($existingRow['photo'] ?? '') !== ''
            ? explode(',', $existingRow['photo'])
            : [];

        if ($imagePath) {
            $currentPhotos[] = $imagePath;
        }

        $updatedPhotos = implode(',', array_filter(array_map('trim', $currentPhotos)));

        $updateStmt = $conn->prepare(
            "UPDATE attendance
             SET role       = ?,
                 photo      = ?,
                 punch_type = ?,
                 punch_time = ?,
                 updated_at = NOW()
             WHERE id = ?"
        );

        $updateStmt->bind_param(
            "ssssi",
            $role,
            $updatedPhotos,
            $punch_type,
            $device_time,
            $existingRow['id']
        );

        $updateStmt->execute();
        $updateStmt->close();

    } else {
        // 7 placeholders — all strings
        $insertStmt = $conn->prepare(
            "INSERT INTO attendance
                (emp_id, role, photo, punch_type, punch_date, punch_time, day, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())"
        );

        $insertStmt->bind_param(
            "sssssss",
            $resolved_emp_id,
            $role,
            $imagePath,
            $punch_type,
            $device_date,
            $device_time,
            $day
        );

        $insertStmt->execute();
        $insertStmt->close();
    }

    // ── 5. Success response ──
    echo json_encode([
        "status"     => "success",
        "message"    => "Attendance recorded successfully",
        "photo_url"  => $imagePath,
        "punch_type" => $punch_type,
        "punch_date" => $device_date,
        "punch_time" => $device_time,
        "day"        => $day
    ]);

    $conn->close();

} catch (Throwable $e) {
    echo json_encode([
        "status"  => "error",
        "message" => "Server exception: " . $e->getMessage()
    ]);
}
?>