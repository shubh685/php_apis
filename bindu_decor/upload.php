<?php
declare(strict_types=1);

// =====================================================
// CORS & HEADERS
// =====================================================
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// =====================================================
// HELPER FUNCTIONS
// =====================================================
function api_json(string $status, string $message = '', array $data = [], int $code = 200): void {
    http_response_code($code);
    echo json_encode(array_merge(['status' => $status, 'message' => $message], $data), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit();
}

function api_base_url(): string {
    $https = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
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

function uploads_dir(): string {
    $dir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR;
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        api_json('error', 'Unable to create uploads directory.', [], 500);
    }
    if (!is_writable($dir)) {
        @chmod($dir, 0755);
        if (!is_writable($dir)) {
            api_json('error', 'Uploads directory is not writable.', [], 500);
        }
    }
    return $dir;
}

function image_ext(string $mime, string $name = ''): string {
    $map = [
        'image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/png' => 'png',
        'image/webp' => 'webp', 'image/gif' => 'gif', 'image/bmp' => 'bmp',
        'image/x-ms-bmp' => 'bmp', 'image/avif' => 'avif'
    ];
    $mime = strtolower(trim($mime));
    if (isset($map[$mime])) return $map[$mime];
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($ext === 'jpeg') $ext = 'jpg';
    return in_array($ext, ['jpg', 'png', 'webp', 'gif', 'bmp', 'avif'], true) ? $ext : 'jpg';
}

function valid_image(string $path): array {
    if (!is_file($path) || filesize($path) <= 0) {
        return [false, 'Image file is empty or invalid.'];
    }
    $info = @getimagesize($path);
    if ($info === false) {
        return [false, 'File is not a valid image.'];
    }
    $mime = strtolower((string)($info['mime'] ?? ''));
    $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/bmp', 'image/x-ms-bmp', 'image/avif'];
    if (!in_array($mime, $allowed, true)) {
        return [false, 'Unsupported image type: ' . $mime];
    }
    return [true, $mime];
}

function public_image_url(string $value): string {
    $value = trim($value);
    if ($value === '') return '';

    if (preg_match('#^https?://#i', $value) || strpos($value, 'data:image/') === 0) {
        return $value;
    }

    $value = str_replace('\\', '/', $value);
    $value = ltrim($value, '/');

    $prefixes = ['bindu_decor/', 'api/bindu_decor/', 'uploads/uploads/'];
    foreach ($prefixes as $prefix) {
        if (stripos($value, $prefix) === 0) {
            $value = substr($value, strlen($prefix));
            break;
        }
    }

    if (stripos($value, 'uploads/') !== 0 && stripos($value, 'image.php') !== 0) {
        $value = 'uploads/' . $value;
    }

    if (stripos($value, 'image.php') !== 0) {
        $value = 'image.php?path=' . rawurlencode($value);
    }

    return rtrim(api_base_url(), '/') . '/' . $value;
}

// =====================================================
// REQUEST PROCESSING
// =====================================================
try {
    $method = $_SERVER['REQUEST_METHOD'];

    // 1. GET METHOD (API Status / Listing Check)
    if ($method === 'GET') {
        api_json('success', 'Upload API active. Send a POST request with multipart/form-data or Base64 JSON to upload images.');
    }

    // 2. POST METHOD (Image Uploads)
    if ($method === 'POST') {
        $prefix = 'media';
        $dest = '';
        $tmpPath = '';

        // Case A: Multipart Form-Data File Upload
        $field = null;
        foreach (['image', 'file', 'photo', 'media', 'imageFile'] as $f) {
            if (isset($_FILES[$f]) && ($_FILES[$f]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $field = $f;
                break;
            }
        }

        if ($field !== null) {
            $file = $_FILES[$field];
            [$ok, $mime] = valid_image($file['tmp_name']);
            if (!$ok) {
                api_json('error', $mime, [], 422);
            }

            $prefix = trim((string)($_POST['prefix'] ?? 'media'));
            $ext = image_ext($mime, $file['name'] ?? '');
            $filename = $prefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
            $dest = uploads_dir() . $filename;

            if (!move_uploaded_file($file['tmp_name'], $dest)) {
                api_json('error', 'Unable to save uploaded image. Check folder permissions.', [], 500);
            }
        } 
        // Case B: Raw JSON Base64 Payload
        else {
            $rawInput = file_get_contents('php://input');
            $jsonData = json_decode($rawInput, true);

            if (is_array($jsonData) && (!empty($jsonData['image_base64']) || !empty($jsonData['image']))) {
                $base64String = $jsonData['image_base64'] ?? $jsonData['image'];
                $prefix = trim((string)($jsonData['prefix'] ?? 'media'));

                // Strip Base64 Data Scheme Header if present (e.g. data:image/png;base64,)
                if (preg_match('/^data:image\/(\w+);base64,/', $base64String, $type)) {
                    $base64String = substr($base64String, strpos($base64String, ',') + 1);
                }

                $decodedData = base64_decode($base64String, true);
                if ($decodedData === false) {
                    api_json('error', 'Invalid base64 encoded image content.', [], 422);
                }

                $tmpPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tmp_' . bin2hex(random_bytes(4));
                file_put_contents($tmpPath, $decodedData);

                [$ok, $mime] = valid_image($tmpPath);
                if (!$ok) {
                    @unlink($tmpPath);
                    api_json('error', $mime, [], 422);
                }

                $ext = image_ext($mime);
                $filename = $prefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                $dest = uploads_dir() . $filename;

                if (!rename($tmpPath, $dest)) {
                    @copy($tmpPath, $dest);
                    @unlink($tmpPath);
                }
            } else {
                api_json('error', 'No image file found in $_FILES or raw JSON body.', [], 400);
            }
        }

        @chmod($dest, 0644);
        $relativePath = 'uploads/' . basename($dest);
        $fullUrl = public_image_url($relativePath);

        api_json('success', 'Image uploaded successfully', [
            'file_path' => $relativePath,
            'url' => $fullUrl,
            'image_url' => $fullUrl
        ], 201);
    }

    api_json('error', 'Method Not Allowed', [], 405);

} catch (Throwable $e) {
    api_json('error', 'Server error: ' . $e->getMessage(), [], 500);
}