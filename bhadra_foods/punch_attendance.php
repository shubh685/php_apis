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

require_once __DIR__ . '/data.php';

try {
    $db = new Database();
    $conn = $db->getConnection();

    if (!$conn) {
        throw new Exception("Database connection failed.");
    }

    // ── Read inputs ──
    // NOTE: latitude, longitude, and address are NOT stored in the
    // attendance table, so we intentionally do NOT read them here.
    $emp_id_str  = trim($_POST['emp_id']     ?? '');
    $role        = trim($_POST['role']       ?? '');
    $punch_type  = strtoupper(trim($_POST['punch_type'] ?? 'PUNCH_IN'));
    $device_date = $_POST['device_date'] ?? date('Y-m-d');
    $device_time = $_POST['device_time'] ?? date('H:i:s');
    $day         = date('l', strtotime($device_date));
    $isAuto      = ($_POST['is_auto'] ?? '0') === '1';

    // ── Validate ──
    if ($emp_id_str === '' || $role === '') {
        echo json_encode(["status" => false, "message" => "emp_id and role are required"]);
        exit();
    }

    if (!in_array($punch_type, ['PUNCH_IN', 'PUNCH_OUT'], true)) {
        echo json_encode(["status" => false, "message" => "Invalid punch_type"]);
        exit();
    }

    // ── 1. Resolve internal user ID from emp_id ──
    $userStmt = $conn->prepare("SELECT id FROM users WHERE emp_id = :emp_id LIMIT 1");
    $userStmt->execute([':emp_id' => $emp_id_str]);
    $userData = $userStmt->fetch();

    if (!$userData) {
        echo json_encode(["status" => false, "message" => "Invalid Employee ID provided"]);
        exit();
    }

    $resolved_emp_id = $userData['id'];

    // ── 2. Time rules ──
    $timeParts            = explode(':', $device_time);
    $currentHour          = (int)($timeParts[0] ?? 0);
    $currentMinute        = (int)($timeParts[1] ?? 0);
    $currentTimeInMinutes = ($currentHour * 60) + $currentMinute;
    $startTime            = 9 * 60;   // 9:00 AM
    $endTime              = 18 * 60;  // 6:00 PM
    $dayOfWeek            = (int)date('N', strtotime($device_date)); // 1=Mon … 7=Sun

    if ($dayOfWeek === 7) {
        echo json_encode(["status" => false, "message" => "Punch not allowed on Sunday"]);
        exit();
    }

    // Check if a row already exists for today
    $existingRowStmt = $conn->prepare(
        "SELECT id, photo, punch_type FROM attendance WHERE emp_id = :emp_id AND punch_date = :punch_date LIMIT 1"
    );
    $existingRowStmt->execute([
        ':emp_id'     => $resolved_emp_id,
        ':punch_date' => $device_date
    ]);
    $existingRow = $existingRowStmt->fetch();

    // ── PUNCH_IN rules ──
    if ($punch_type === 'PUNCH_IN') {
        if ($currentTimeInMinutes >= $endTime) {
            echo json_encode(["status" => false, "message" => "Punch In closed for today (after 6:00 PM)"]);
            exit();
        }

        if ($existingRow && $existingRow['punch_type'] === 'PUNCH_IN') {
            echo json_encode(["status" => false, "message" => "You are already punched in. Punch Out first."]);
            exit();
        }
    }

    // ── PUNCH_OUT rules ──
    if ($punch_type === 'PUNCH_OUT') {
        if (!$existingRow) {
            echo json_encode(["status" => false, "message" => "No Punch In found for today. Please Punch In first."]);
            exit();
        }

        if ($existingRow['punch_type'] === 'PUNCH_OUT') {
            echo json_encode(["status" => false, "message" => "You have already punched out today!"]);
            exit();
        }

        if (!$isAuto && ($currentTimeInMinutes < $startTime || $currentTimeInMinutes > $endTime)) {
            echo json_encode([
                "status"  => false,
                "message" => "Punch Out allowed only between 9:00 AM and 6:00 PM"
            ]);
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
            echo json_encode(["status" => false, "message" => "Photo size exceeds 5MB limit"]);
            exit();
        }

        $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
        $finfo        = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType     = finfo_file($finfo, $_FILES['photo']['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mimeType, $allowedTypes, true)) {
            echo json_encode(["status" => false, "message" => "Invalid photo format. Only JPEG, PNG, WebP allowed"]);
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
            echo json_encode(["status" => false, "message" => "Failed to upload photo"]);
            exit();
        }
    }

    // ── 4. Insert or Update ──
    // NOTE: The attendance table does NOT contain latitude/longitude/address,
    //       so those fields are intentionally omitted from INSERT/UPDATE.
    if ($existingRow) {
        $currentPhotos = ($existingRow['photo'] ?? '') !== ''
            ? explode(',', $existingRow['photo'])
            : [];

        if ($imagePath) {
            $currentPhotos[] = $imagePath;
        }

        $updatedPhotos = implode(',', array_filter(array_map('trim', $currentPhotos)));

        $updateStmt = $conn->prepare(
            "UPDATE attendance
             SET role       = :role,
                 photo      = :photo,
                 punch_type = :punch_type,
                 punch_time = :punch_time,
                 updated_at = NOW()
             WHERE id = :id"
        );

        $updateStmt->execute([
            ':role'       => $role,
            ':photo'      => $updatedPhotos,
            ':punch_type' => $punch_type,
            ':punch_time' => $device_time,
            ':id'         => $existingRow['id']
        ]);

    } else {
        $insertStmt = $conn->prepare(
            "INSERT INTO attendance
                (emp_id, role, photo, punch_type, punch_date, punch_time, day, created_at, updated_at)
             VALUES (:emp_id, :role, :photo, :punch_type, :punch_date, :punch_time, :day, NOW(), NOW())"
        );

        $insertStmt->execute([
            ':emp_id'     => $resolved_emp_id,
            ':role'       => $role,
            ':photo'      => $imagePath,
            ':punch_type' => $punch_type,
            ':punch_date' => $device_date,
            ':punch_time' => $device_time,
            ':day'        => $day
        ]);
    }

    // ── 5. Success response ──
    // Only return fields that exist in the table.
    echo json_encode([
        "status"     => "success",
        "message"    => "Attendance recorded successfully",
        "photo_url"  => $imagePath,
        "punch_type" => $punch_type,
        "punch_date" => $device_date,
        "punch_time" => $device_time,
        "day"        => $day
    ]);

} catch (Throwable $e) {
    echo json_encode([
        "status"  => false,
        "message" => "Server exception: " . $e->getMessage()
    ]);
}
?>