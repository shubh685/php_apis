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

function uploads_dir(): string {
    $dir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR;
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Unable to create uploads directory."], JSON_UNESCAPED_SLASHES);
        exit();
    }
    if (!is_writable($dir)) {
        @chmod($dir, 0755);
        if (!is_writable($dir)) {
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => "Uploads directory is not writable."], JSON_UNESCAPED_SLASHES);
            exit();
        }
    }
    return $dir;
}

function image_ext(string $mime, string $name = ''): string {
    $map = [
        'image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/png' => 'png',
        'image/webp' => 'webp', 'image/gif' => 'gif', 'image/bmp' => 'bmp',
        'image/avif' => 'avif'
    ];
    $mime = strtolower(trim($mime));
    if (isset($map[$mime])) return $map[$mime];
    $e = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($e === 'jpeg') $e = 'jpg';
    return in_array($e, ['jpg', 'png', 'webp', 'gif', 'bmp', 'avif'], true) ? $e : 'jpg';
}

function valid_image(string $path): array {
    if (!is_file($path) || filesize($path) <= 0) return [false, 'Image file is empty or missing.'];
    $info = @getimagesize($path);
    if ($info === false) return [false, 'File is not a valid image.'];
    $mime = strtolower((string)($info['mime'] ?? ''));
    $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/bmp', 'image/avif'];
    if (!in_array($mime, $allowed, true)) return [false, 'Unsupported image type: ' . $mime];
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

    if (stripos($value, 'uploads/') !== 0) {
        $value = 'uploads/' . $value;
    }

    return rtrim(api_base_url(), '/') . '/' . $value;
}

function resolve_photo_array(?string $photos_json): array {
    $photos = json_decode($photos_json ?? '[]', true) ?: [];
    $formatted = [];
    
    foreach ($photos as $img) {
        $url = public_image_url((string)$img);
        if ($url !== '') {
            $formatted[] = $url;
        }
    }
    return $formatted;
}

function sanitize_status(string $status): string {
    $status = trim($status);
    return in_array($status, ['Draft', 'Published'], true) ? $status : 'Draft';
}

function uploaded_images(string $prefix): array {
    $saved = [];
    foreach (['photos', 'images', 'files', 'photo', 'image'] as $field) {
        if (isset($_FILES[$field]) && is_array($_FILES[$field]['name'])) {
            $count = count($_FILES[$field]['name']);
            for ($i = 0; $i < $count; $i++) {
                if (($_FILES[$field]['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                    $file = [
                        'name' => $_FILES[$field]['name'][$i],
                        'type' => $_FILES[$field]['type'][$i],
                        'tmp_name' => $_FILES[$field]['tmp_name'][$i],
                        'error' => $_FILES[$field]['error'][$i],
                        'size' => $_FILES[$field]['size'][$i]
                    ];
                    [$ok, $path] = save_uploaded_file($file, $prefix);
                    if ($ok) $saved[] = $path;
                }
            }
        } elseif (isset($_FILES[$field]) && ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            [$ok, $path] = save_uploaded_file($_FILES[$field], $prefix);
            if ($ok) $saved[] = $path;
        }
    }
    return $saved;
}

function save_uploaded_file(array $file, string $prefix): array {
    [$ok, $mime] = valid_image($file['tmp_name']);
    if (!$ok) return [false, $mime];
    $ext = image_ext($mime, $file['name'] ?? '');
    $filename = $prefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $dest = uploads_dir() . $filename;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return [false, 'Unable to move file to uploads directory.'];
    }
    @chmod($dest, 0644);
    return [true, 'uploads/' . $filename];
}

function process_blog_photos(?string $existing_photos_json = null): string {
    $clean_photos = [];
    
    if (!empty($existing_photos_json)) {
        $existing = json_decode($existing_photos_json, true);
        if (is_array($existing)) {
            foreach ($existing as $ex_photo) {
                if (!empty($ex_photo) && !in_array($ex_photo, $clean_photos, true)) {
                    $clean_photos[] = $ex_photo;
                }
            }
        }
    }

    $uploaded = uploaded_images('blog');
    foreach ($uploaded as $upload) {
        if (!in_array($upload, $clean_photos, true)) {
            $clean_photos[] = $upload;
        }
    }

    $input = json_decode(file_get_contents("php://input"), true) ?? [];
    $raw_photos = [];
    $sources = [$_POST, $_GET, $input];

    foreach ($sources as $source) {
        foreach (['photos', 'photos[]', 'images', 'images[]', 'photo', 'image'] as $key) {
            if (isset($source[$key])) {
                $val = $source[$key];
                if (is_array($val)) {
                    $raw_photos = array_merge($raw_photos, $val);
                } else if (is_string($val) && trim($val) !== '') {
                    $decoded = json_decode($val, true);
                    if (is_array($decoded)) {
                        $raw_photos = array_merge($raw_photos, $decoded);
                    } else {
                        $raw_photos[] = $val;
                    }
                }
            }
        }
    }

    foreach ($raw_photos as $img) {
        $img = trim((string)$img);
        if ($img === '') continue;

        if (preg_match('#^https?://#i', $img) || strpos($img, 'data:image/') === 0) {
            if (!in_array($img, $clean_photos, true)) {
                $clean_photos[] = $img;
            }
            continue;
        }

        $base = api_base_url();
        if (strpos($img, $base) === 0) {
            $img = substr($img, strlen($base));
            $img = ltrim($img, '/');
        }

        $img = str_replace('\\', '/', $img);
        $img = ltrim($img, '/');

        $prefixes = ['bindu_decor/', 'api/bindu_decor/', 'uploads/uploads/'];
        foreach ($prefixes as $prefix) {
            if (stripos($img, $prefix) === 0) {
                $img = substr($img, strlen($prefix));
                break;
            }
        }

        if ($img !== '' && !in_array($img, $clean_photos, true)) {
            $clean_photos[] = $img;
        }
    }

    return json_encode(array_values(array_unique($clean_photos)), JSON_UNESCAPED_SLASHES);
}

function is_duplicate_title(PDO $pdo, string $title, $exclude_id = null): bool {
    $sql = "SELECT COUNT(*) FROM blogs WHERE title = ?";
    $params = [$title];
    if ($exclude_id !== null) {
        $sql .= " AND id != ?";
        $params[] = $exclude_id;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn() > 0;
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            if (isset($_GET['id'])) {
                $stmt = $pdo->prepare("SELECT * FROM blogs WHERE id = ?");
                $stmt->execute([$_GET['id']]);
                $blog = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($blog) {
                    $blog['photos'] = resolve_photo_array($blog['photos']);
                    echo json_encode(["status" => "success", "data" => $blog], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                } else {
                    http_response_code(404);
                    echo json_encode(["status" => "error", "message" => "Blog not found"]);
                }
            } else {
                $stmt = $pdo->query("SELECT * FROM blogs ORDER BY id DESC");
                $blogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($blogs as &$blog) {
                    $blog['photos'] = resolve_photo_array($blog['photos']);
                }
                echo json_encode(["status" => "success", "data" => $blogs], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            break;

        case 'POST':
            $input = json_decode(file_get_contents("php://input"), true) ?? $_POST;
            $id = $input['id'] ?? ($_GET['id'] ?? null);

            if ($id) {
                $check_stmt = $pdo->prepare("SELECT * FROM blogs WHERE id = ?");
                $check_stmt->execute([$id]);
                $existing = $check_stmt->fetch(PDO::FETCH_ASSOC);

                if (!$existing) {
                    http_response_code(404);
                    echo json_encode(["status" => "error", "message" => "Blog not found"]);
                    exit();
                }

                $title = !empty($input['title']) ? trim((string)$input['title']) : $existing['title'];
                $author_name = !empty($input['author_name']) ? trim((string)$input['author_name']) : $existing['author_name'];
                $subject = isset($input['subject']) ? trim((string)$input['subject']) : $existing['subject'];
                $description = isset($input['description']) ? trim((string)$input['description']) : $existing['description'];

                if (is_duplicate_title($pdo, $title, $id)) {
                    http_response_code(409);
                    echo json_encode(["status" => "error", "message" => "A blog with this title already exists"]);
                    exit();
                }

                $status = sanitize_status($input['status'] ?? $existing['status']);
                $photos = process_blog_photos($existing['photos']);

                $stmt = $pdo->prepare("UPDATE blogs SET title = ?, subject = ?, description = ?, author_name = ?, status = ?, photos = ? WHERE id = ?");
                $stmt->execute([$title, $subject, $description, $author_name, $status, $photos, $id]);

                $updated_stmt = $pdo->prepare("SELECT * FROM blogs WHERE id = ?");
                $updated_stmt->execute([$id]);
                $updated_blog = $updated_stmt->fetch(PDO::FETCH_ASSOC);
                $updated_blog['photos'] = resolve_photo_array($updated_blog['photos']);

                echo json_encode([
                    "status" => "success",
                    "message" => "Blog updated successfully",
                    "id" => (int)$id,
                    "data" => $updated_blog
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                break;
            }

            if (empty($input['title']) || empty($input['author_name'])) {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "Required fields missing: title and author_name are required"]);
                exit();
            }

            if (is_duplicate_title($pdo, $input['title'])) {
                http_response_code(409);
                echo json_encode(["status" => "error", "message" => "A blog with this title already exists"]);
                exit();
            }

            $status = sanitize_status($input['status'] ?? 'Published');
            $photos = process_blog_photos(null);

            $stmt = $pdo->prepare("INSERT INTO blogs (title, subject, description, author_name, status, photos) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $input['title'],
                $input['subject'] ?? '',
                $input['description'] ?? '',
                $input['author_name'],
                $status,
                $photos
            ]);

            $new_id = (int)$pdo->lastInsertId();
            $new_stmt = $pdo->prepare("SELECT * FROM blogs WHERE id = ?");
            $new_stmt->execute([$new_id]);
            $new_blog = $new_stmt->fetch(PDO::FETCH_ASSOC);
            $new_blog['photos'] = resolve_photo_array($new_blog['photos']);

            echo json_encode([
                "status" => "success",
                "message" => "Blog created successfully",
                "id" => $new_id,
                "data" => $new_blog
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            break;

        case 'DELETE':
            $id = $_GET['id'] ?? (json_decode(file_get_contents("php://input"), true)['id'] ?? null);
            if (!$id) {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "Blog ID is required"]);
                exit();
            }
            
            $stmt = $pdo->prepare("DELETE FROM blogs WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(["status" => "success", "message" => "Blog deleted successfully"]);
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