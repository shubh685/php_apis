<?php
declare(strict_types=1);

// =====================================================
// CORS & HEADERS
// =====================================================
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { 
    http_response_code(200); 
    exit(); 
}

require_once __DIR__ . '/database.php';

// =====================================================
// HELPER FUNCTIONS
// =====================================================
function api_base_url(): string {
    $https = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
          || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
          || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
    
    $protocol = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $directory = str_replace('\\', '/', dirname($script));
    $directory = trim($directory, '/');

    if ($directory === '' || $directory === '.') {
        return $protocol . '://' . $host;
    }
    return $protocol . '://' . $host . '/' . $directory;
}

function public_image_url($value): string {
    $value = trim((string)$value);
    if ($value === '') return '';

    // If it's already a full URL, return as is
    if (preg_match('#^https?://#i', $value)) {
        return $value;
    }

    // Clean the path
    $value = str_replace('\\', '/', $value);
    $value = ltrim($value, '/');

    // Remove duplicate prefixes
    $prefixes = ['bindu_decor/', 'api/bindu_decor/', 'uploads/uploads/'];
    foreach ($prefixes as $prefix) {
        if (stripos($value, $prefix) === 0) {
            $value = substr($value, strlen($prefix));
            break;
        }
    }

    // If it already has uploads/ prefix, keep it
    if (stripos($value, 'uploads/') === 0) {
        // Extract just the filename
        $value = substr($value, 8); // Remove 'uploads/'
    }

    // Build full URL using image.php
    $base_url = rtrim(api_base_url(), '/');
    return $base_url . '/image.php?path=' . urlencode($value);
}

function uploads_dir(): string {
    $dir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR;
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Unable to create uploads directory."], JSON_UNESCAPED_SLASHES);
        exit();
    }
    return $dir;
}

function save_uploaded_client_image(array $file): array {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return [false, 'File upload error code: ' . $file['error']];
    }
    
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($ext === 'jpeg') $ext = 'jpg';
    $allowed = ['jpg', 'png', 'webp', 'gif', 'bmp', 'avif'];
    
    if (!in_array($ext, $allowed, true)) {
        return [false, 'Unsupported image type: ' . $ext];
    }

    $filename = 'client_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $dest = uploads_dir() . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return [false, 'Failed to save uploaded file.'];
    }

    return [true, 'uploads/' . $filename];
}

function format_client_data(array $client): array {
    $img = $client['img_url'] ?? '';
    $formatted_url = public_image_url($img);
    
    // Return both the stored path and the full URL
    $client['img_url'] = $formatted_url;          // Full URL for display (via image.php)
    $client['image_url'] = $formatted_url;        // Full URL for display (via image.php)
    $client['img_path'] = $img;                    // Stored path in database
    
    return $client;
}

// =====================================================
// API REQUEST HANDLING
// =====================================================
$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            if (isset($_GET['id'])) {
                $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
                $stmt->execute([$_GET['id']]);
                $client = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($client) {
                    echo json_encode([
                        "status" => "success", 
                        "data" => format_client_data($client)
                    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                } else {
                    http_response_code(404);
                    echo json_encode(["status" => "error", "message" => "Client not found"]);
                }
            } else {
                $stmt = $pdo->query("SELECT * FROM clients ORDER BY id DESC");
                $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $formatted_clients = array_map('format_client_data', $clients);

                echo json_encode([
                    "status" => "success", 
                    "data" => $formatted_clients
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            break;

        case 'POST':
            // Read JSON input or fallback to $_POST for multipart/form-data
            $raw_input = json_decode(file_get_contents("php://input"), true);
            $input = is_array($raw_input) ? array_merge($_POST, $raw_input) : $_POST;

            $action = $_REQUEST['action'] ?? ($input['action'] ?? '');
            $id = $input['id'] ?? ($_GET['id'] ?? null);

            if ($action === 'delete') {
                if (!$id) {
                    http_response_code(400);
                    echo json_encode(["status" => "error", "message" => "Client ID is required"]);
                    exit();
                }

                $stmt = $pdo->prepare("DELETE FROM clients WHERE id = ?");
                $stmt->execute([$id]);

                echo json_encode([
                    "status" => "success", 
                    "message" => "Client deleted successfully"
                ], JSON_UNESCAPED_SLASHES);
                break;
            }

            $img_path = '';

            // Handle file upload
            if (isset($_FILES['imageFile']) && $_FILES['imageFile']['error'] === UPLOAD_ERR_OK) {
                [$ok, $res] = save_uploaded_client_image($_FILES['imageFile']);
                if ($ok) $img_path = $res;
            } elseif (isset($_FILES['img_url']) && $_FILES['img_url']['error'] === UPLOAD_ERR_OK) {
                [$ok, $res] = save_uploaded_client_image($_FILES['img_url']);
                if ($ok) $img_path = $res;
            } elseif (isset($_FILES['image_url']) && $_FILES['image_url']['error'] === UPLOAD_ERR_OK) {
                [$ok, $res] = save_uploaded_client_image($_FILES['image_url']);
                if ($ok) $img_path = $res;
            }

            // Fallback to URL text string if no physical image uploaded
            if (empty($img_path)) {
                $img_path = $input['img_url'] ?? ($input['image_url'] ?? '');
                // Clean the URL if it's a full URL
                if (!empty($img_path) && strpos($img_path, 'http') === 0) {
                    $parsed = parse_url($img_path);
                    if (isset($parsed['path'])) {
                        $img_path = ltrim($parsed['path'], '/');
                    }
                }
                // Ensure it starts with 'uploads/'
                if (!empty($img_path) && strpos($img_path, 'uploads/') !== 0) {
                    $img_path = 'uploads/' . $img_path;
                }
            }

            if ($id) {
                $check_stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
                $check_stmt->execute([$id]);
                $existing = $check_stmt->fetch(PDO::FETCH_ASSOC);

                if (!$existing) {
                    http_response_code(404);
                    echo json_encode(["status" => "error", "message" => "Client not found"]);
                    exit();
                }

                $final_img = !empty($img_path) ? $img_path : ($existing['img_url'] ?? '');

                $stmt = $pdo->prepare("UPDATE clients SET img_url = ? WHERE id = ?");
                $stmt->execute([$final_img, $id]);

                $updated_stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
                $updated_stmt->execute([$id]);
                $updated_client = $updated_stmt->fetch(PDO::FETCH_ASSOC);

                echo json_encode([
                    "status" => "success",
                    "message" => "Client updated successfully",
                    "data" => format_client_data($updated_client)
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                break;
            }

            if (empty($img_path)) {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "Image file or image URL is required"]);
                exit();
            }

            $created_at = $input['created_at'] ?? $input['device_time'] ?? date('Y-m-d H:i:s');

            $stmt = $pdo->prepare("INSERT INTO clients (img_url, created_at) VALUES (?, ?)");
            $stmt->execute([$img_path, $created_at]);

            $new_id = (int)$pdo->lastInsertId();
            $new_stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
            $new_stmt->execute([$new_id]);
            $new_client = $new_stmt->fetch(PDO::FETCH_ASSOC);

            $formatted_new = format_client_data($new_client);

            echo json_encode([
                "status" => "success",
                "message" => "Client added successfully",
                "id" => $new_id,
                "img_url" => $formatted_new['img_url'],
                "image_url" => $formatted_new['img_url'],
                "img_path" => $new_client['img_url'],
                "data" => $formatted_new
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            break;

        case 'DELETE':
            $input = json_decode(file_get_contents("php://input"), true) ?? [];
            $id = $_GET['id'] ?? ($input['id'] ?? null);

            if (!$id) {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "Client ID is required"]);
                exit();
            }

            $stmt = $pdo->prepare("DELETE FROM clients WHERE id = ?");
            $stmt->execute([$id]);

            echo json_encode([
                "status" => "success", 
                "message" => "Client deleted successfully"
            ], JSON_UNESCAPED_SLASHES);
            break;

        default:
            http_response_code(405);
            echo json_encode(["status" => "error", "message" => "Method Not Allowed"]);
            break;
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Server error: " . $e->getMessage()]);
}
?>