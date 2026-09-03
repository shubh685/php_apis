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

    // Direct External HTTP/HTTPS URLs
    if (preg_match('#^https?://#i', $value) || strpos($value, 'data:image/') === 0) {
        return $value;
    }

    // Standardize slashes
    $value = str_replace('\\', '/', $value);
    $value = ltrim($value, '/');

    // Remove redundant base folders
    $prefixes = ['bindu_decor/', 'api/bindu_decor/', 'uploads/uploads/'];
    foreach ($prefixes as $prefix) {
        if (stripos($value, $prefix) === 0) {
            $value = substr($value, strlen($prefix));
            break;
        }
    }

    if (stripos($value, 'uploads/') !== 0) {
        $value = 'uploads/' . $value;
    }

    return rtrim(api_base_url(), '/') . '/' . $value;
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

// Format single client record image paths
function format_client_data(array $client): array {
    $img = $client['img_url'] ?? ($client['image_url'] ?? '');
    $formatted_url = public_image_url($img);
    
    $client['img_url'] = $formatted_url;
    $client['image_url'] = $formatted_url; // Verified dual mapping for backward compatibility
    
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
            $input = json_decode(file_get_contents("php://input"), true) ?? $_POST;
            $id = $input['id'] ?? ($_GET['id'] ?? null);

            $img_path = '';

            // 1. Handle File Uploads
            if (isset($_FILES['img_url']) && $_FILES['img_url']['error'] === UPLOAD_ERR_OK) {
                [$ok, $res] = save_uploaded_client_image($_FILES['img_url']);
                if ($ok) $img_path = $res;
            } elseif (isset($_FILES['image_url']) && $_FILES['image_url']['error'] === UPLOAD_ERR_OK) {
                [$ok, $res] = save_uploaded_client_image($_FILES['image_url']);
                if ($ok) $img_path = $res;
            }

            // 2. Handle Text Inputs if no file uploaded
            if (empty($img_path)) {
                $img_path = $input['img_url'] ?? ($input['image_url'] ?? '');
            }

            // UPDATE CLIENT
            if ($id) {
                $check_stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
                $check_stmt->execute([$id]);
                $existing = $check_stmt->fetch(PDO::FETCH_ASSOC);

                if (!$existing) {
                    http_response_code(404);
                    echo json_encode(["status" => "error", "message" => "Client not found"]);
                    exit();
                }

                $name = !empty($input['name']) ? trim((string)$input['name']) : $existing['name'];
                $final_img = !empty($img_path) ? $img_path : ($existing['img_url'] ?? $existing['image_url'] ?? '');

                $stmt = $pdo->prepare("UPDATE clients SET name = ?, img_url = ? WHERE id = ?");
                $stmt->execute([$name, $final_img, $id]);

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

            // CREATE CLIENT
            $name = trim((string)($input['name'] ?? ''));
            if (empty($name)) {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "Client name is required"]);
                exit();
            }

            $stmt = $pdo->prepare("INSERT INTO clients (name, img_url) VALUES (?, ?)");
            $stmt->execute([$name, $img_path]);

            $new_id = (int)$pdo->lastInsertId();
            $new_stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
            $new_stmt->execute([$new_id]);
            $new_client = $new_stmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                "status" => "success",
                "message" => "Client added successfully",
                "data" => format_client_data($new_client)
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