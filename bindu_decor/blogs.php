<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { 
    http_response_code(200); 
    exit(); 
}

require_once __DIR__ . '/database.php';

function api_base_url() {
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

function uploads_dir() {
    $dir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR;
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Unable to create uploads directory."]);
        exit();
    }
    if (!is_writable($dir)) {
        @chmod($dir, 0755);
        if (!is_writable($dir)) {
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => "Uploads directory is not writable."]);
            exit();
        }
    }
    return $dir;
}

function image_ext($mime, $name='') {
    $map = ['image/jpeg'=>'jpg','image/jpg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif','image/bmp'=>'bmp','image/avif'=>'avif'];
    $mime = strtolower(trim((string)$mime));
    if (isset($map[$mime])) return $map[$mime];
    $e = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($e === 'jpeg') $e = 'jpg';
    return in_array($e,['jpg','png','webp','gif','bmp','avif'],true) ? $e : 'jpg';
}

function valid_image($path) {
    if (!is_file($path) || filesize($path) <= 0) return [false,'Image file is empty or missing.'];
    $info = @getimagesize($path);
    if ($info === false) return [false,'File is not a valid image.'];
    $mime = strtolower((string)($info['mime'] ?? ''));
    $allowed = ['image/jpeg','image/png','image/webp','image/gif','image/bmp','image/avif'];
    if (!in_array($mime,$allowed,true)) return [false,'Unsupported image type: '.$mime];
    return [true,$mime];
}

function unique_image_name($prefix,$ext) {
    return $prefix.'_'.date('Ymd_His').'_'.bin2hex(random_bytes(8)).'.'.$ext;
}

function save_uploaded($file,$prefix) {
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        return [false,'Upload failed. Error code: '.($file['error'] ?? 'unknown')];
    }
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return [false,'Invalid uploaded file.'];
    }
    [$ok,$mime] = valid_image($file['tmp_name']);
    if (!$ok) return [false,$mime];
    $name = unique_image_name($prefix,image_ext($mime,$file['name'] ?? ''));
    $dest = uploads_dir().$name;
    if (!move_uploaded_file($file['tmp_name'],$dest)) {
        return [false,'Unable to save uploaded image. Check uploads permissions.'];
    }
    @chmod($dest, 0644);
    if (!is_file($dest) || filesize($dest) <= 0) {
        return [false,'Uploaded image was not saved correctly.'];
    }
    return [true,'uploads/'.$name];
}

function uploaded_images($prefix) {
    $saved = [];
    foreach (['photos', 'images', 'files', 'photo', 'image', 'images[]'] as $field) {
        if (isset($_FILES[$field]) && is_array($_FILES[$field]['name'])) {
            $count = count($_FILES[$field]['name']);
            for ($i = 0; $i < $count; $i++) {
                if (($_FILES[$field]['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                    $single_file = [
                        'name' => $_FILES[$field]['name'][$i],
                        'type' => $_FILES[$field]['type'][$i],
                        'tmp_name' => $_FILES[$field]['tmp_name'][$i],
                        'error' => $_FILES[$field]['error'][$i],
                        'size' => $_FILES[$field]['size'][$i]
                    ];
                    [$ok, $path] = save_uploaded($single_file, $prefix);
                    if ($ok) $saved[] = $path;
                }
            }
        } elseif (isset($_FILES[$field]) && ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            [$ok, $path] = save_uploaded($_FILES[$field], $prefix);
            if ($ok) $saved[] = $path;
        }
    }
    return $saved;
}

function public_image_url($value) {
    $value = trim((string)$value);
    if ($value === '') return '';

    // Direct Pass for absolute HTTP/HTTPS URLs or Base64 strings
    if (preg_match('#^https?://#i', $value) || strpos($value, 'data:image/') === 0) {
        return $value;
    }

    $value = str_replace('\\', '/', $value);
    $value = ltrim($value, '/');

    // Clean out unintended path prefixes
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

function resolve_photo_array($photos_json) {
    $photos = json_decode($photos_json ?? '[]', true) ?: [];
    $formatted = [];
    
    foreach ($photos as $img) {
        $url = public_image_url($img);
        if ($url !== '') {
            $formatted[] = $url;
        }
    }
    return $formatted;
}

function sanitize_status($status) {
    $status = trim((string)$status);
    return in_array($status, ['Draft', 'Published'], true) ? $status : 'Draft';
}

function process_blog_photos($existing_photos_json = null) {
    // Process physical uploaded files directly
    $uploaded_paths = uploaded_images('blog');

    $input = json_decode(file_get_contents("php://input"), true) ?? $_POST;
    
    $raw_photos = $input['photos'] ?? $input['external_urls'] ?? [];
    if (isset($input['external_urls[]'])) {
        $ext = $input['external_urls[]'];
        $raw_photos = is_array($ext) ? $ext : [$ext];
    }
    
    if (is_string($raw_photos)) {
        $decoded = json_decode($raw_photos, true);
        $raw_photos = is_array($decoded) ? $decoded : [$raw_photos];
    }

    $clean_photos = $uploaded_paths;

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

        if ($img !== '' && !in_array($img, $clean_photos, true)) {
            $clean_photos[] = $img;
        }
    }

    if (empty($clean_photos) && $existing_photos_json) {
        $existing = json_decode($existing_photos_json, true);
        if (is_array($existing) && !empty($existing)) {
            return $existing_photos_json;
        }
    }

    return json_encode(array_values($clean_photos));
}

function is_duplicate_title($pdo, $title, $exclude_id = null) {
    $sql = "SELECT COUNT(*) FROM blogs WHERE title = ?";
    $params = [$title];
    
    if ($exclude_id !== null) {
        $sql .= " AND id != ?";
        $params[] = $exclude_id;
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn() > 0;
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
                    echo json_encode(["status" => "success", "data" => $blog]);
                } else {
                    http_response_code(404);
                    echo json_encode(["status" => "error", "message" => "Blog not found"]);
                }
            } else {
                $stmt = $pdo->query("SELECT * FROM blogs ORDER BY created_at DESC");
                $blogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($blogs as &$blog) {
                    $blog['photos'] = resolve_photo_array($blog['photos']);
                }
                echo json_encode(["status" => "success", "data" => $blogs]);
            }
            break;

        case 'POST':
            $input = json_decode(file_get_contents("php://input"), true) ?? $_POST;
            $id = $input['id'] ?? ($_GET['id'] ?? null);
            $override_method = strtoupper($input['_method'] ?? '');

            if ($id || $override_method === 'PUT') {
                if (!$id) {
                    http_response_code(400);
                    echo json_encode(["status" => "error", "message" => "Blog ID is required for update"]);
                    exit();
                }

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
                ]);
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

            $status = sanitize_status($input['status'] ?? 'Draft');
            $photos = process_blog_photos();

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
            ]);
            break;

        case 'PUT':
            $input = json_decode(file_get_contents("php://input"), true) ?? $_POST;
            $id = $input['id'] ?? ($_GET['id'] ?? null);

            if (!$id) {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "Blog ID is required for update"]);
                exit();
            }

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
            ]);
            break;

        case 'DELETE':
            $id = $_GET['id'] ?? (json_decode(file_get_contents("php://input"), true)['id'] ?? null);
            if (!$id) {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "Blog ID is required"]);
                exit();
            }
            
            $check_stmt = $pdo->prepare("SELECT id FROM blogs WHERE id = ?");
            $check_stmt->execute([$id]);
            if (!$check_stmt->fetch()) {
                http_response_code(404);
                echo json_encode(["status" => "error", "message" => "Blog not found"]);
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