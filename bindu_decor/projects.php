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

ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error_log.txt');

function api_json($status, $message = '', $data = [], $code = 200) {
    http_response_code($code);
    echo json_encode(
        array_merge(['status' => $status, 'message' => $message], $data),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    exit;
}

function api_base_url() {
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $directory = str_replace('\\', '/', dirname($script));
    $directory = trim($directory, '/');

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? 80) == 443) ? 'https://' : 'http://';

    if ($directory === '' || $directory === '.') {
        return $scheme . $host;
    }
    return $scheme . $host . '/' . $directory;
}

function uploads_dir() {
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

function image_ext($mime, $name = '') {
    $map = ['image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'image/bmp' => 'bmp', 'image/avif' => 'avif'];
    $mime = strtolower(trim((string)$mime));
    if (isset($map[$mime])) return $map[$mime];
    $e = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($e === 'jpeg') $e = 'jpg';
    return in_array($e, ['jpg', 'png', 'webp', 'gif', 'bmp', 'avif'], true) ? $e : 'jpg';
}

function valid_image($path) {
    if (!is_file($path) || filesize($path) <= 0) return [false, 'Image file is empty or missing.'];
    $info = @getimagesize($path);
    if ($info === false) return [false, 'File is not a valid image.'];
    $mime = strtolower((string)($info['mime'] ?? ''));
    $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/bmp', 'image/avif'];
    if (!in_array($mime, $allowed, true)) return [false, 'Unsupported image type: ' . $mime];
    return [true, $mime];
}

function unique_image_name($prefix, $ext) {
    return $prefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
}

function save_uploaded($file, $prefix) {
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        return [false, 'Upload failed. Error code: ' . ($file['error'] ?? 'unknown')];
    }
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return [false, 'Invalid uploaded file.'];
    }
    [$ok, $mime] = valid_image($file['tmp_name']);
    if (!$ok) return [false, $mime];
    $name = unique_image_name($prefix, image_ext($mime, $file['name'] ?? ''));
    $dest = uploads_dir() . $name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return [false, 'Unable to save uploaded image. Check uploads permissions.'];
    }
    @chmod($dest, 0644);
    if (!is_file($dest) || filesize($dest) <= 0) {
        return [false, 'Uploaded image was not saved correctly.'];
    }
    return [true, 'uploads/' . $name];
}

function uploaded_images($prefix) {
    $saved = [];
    $fields = ['image_urls', 'image_urls[]', 'image_url', 'imageUrls', 'imageUrls[]', 'imageUrl', 'images', 'photos', 'photo', 'img_url', 'file', 'files', 'media'];

    foreach ($fields as $field) {
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
    if ($value === '' || $value === 'null' || $value === 'undefined') return '';

    // If it's already a valid absolute URL or data URI, return it clean without rawurlencode!
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

    return rtrim(api_base_url(), '/') . '/' . $value;
}

function resolve_photo_array($photos_json) {
    if (empty($photos_json)) return [];

    $photos = json_decode($photos_json, true);
    if (!is_array($photos)) {
        $photos = [$photos_json];
    }

    $formatted = [];
    foreach ($photos as $img) {
        $url = public_image_url($img);
        if ($url !== '' && !in_array($url, $formatted, true)) {
            $formatted[] = $url;
        }
    }
    return $formatted;
}

function resolve_image_urls($raw) {
    if (empty($raw)) return [];

    $urls = [];
    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $urls = $decoded;
        } else {
            $urls = [$raw];
        }
    } elseif (is_array($raw)) {
        $urls = $raw;
    }

    $result = [];
    foreach ($urls as $item) {
        // Handle double-encoded JSON strings inside arrays
        if (is_string($item) && (strpos($item, '[') === 0 || strpos($item, 'http') !== false)) {
            $subDecoded = json_decode($item, true);
            if (is_array($subDecoded)) {
                foreach ($subDecoded as $subItem) {
                    $formatted = public_image_url($subItem);
                    if ($formatted !== '' && !in_array($formatted, $result, true)) {
                        $result[] = $formatted;
                    }
                }
                continue;
            }
        }
        
        $formatted = public_image_url($item);
        if ($formatted !== '' && !in_array($formatted, $result, true)) {
            $result[] = $formatted;
        }
    }

    return $result;
}


function process_project_images($existing_photos_json = null) {
    $clean_photos = [];

    if (!empty($existing_photos_json)) {
        $existing = json_decode($existing_photos_json, true);
        if (is_array($existing)) {
            foreach ($existing as $ex_photo) {
                $ex_photo = trim((string)$ex_photo);
                if (!empty($ex_photo) && !in_array($ex_photo, $clean_photos, true)) {
                    $clean_photos[] = $ex_photo;
                }
            }
        }
    }

    $uploaded = uploaded_images('project');
    foreach ($uploaded as $upload) {
        if (!in_array($upload, $clean_photos, true)) {
            $clean_photos[] = $upload;
        }
    }

    $input = json_decode(file_get_contents("php://input"), true);
    if (!is_array($input)) {
        $input = [];
    }

    $raw_photos = [];
    $keys = [
        'image_urls', 'image_urls[]', 'image_url',
        'imageUrls', 'imageUrls[]', 'imageUrl',
        'images', 'photos', 'photo', 'img_url', 'url', 'urls', 'media_url',
        'external_urls', 'external_urls[]', 'external_url'
    ];

    $sources = [$_POST, $_GET, $input];
    foreach ($sources as $source) {
        foreach ($keys as $key) {
            if (isset($source[$key])) {
                $val = $source[$key];
                if (is_array($val)) {
                    $raw_photos = array_merge($raw_photos, $val);
                } else if (is_string($val) && trim($val) !== '') {
                    $decoded = json_decode($val, true);
                    if (is_array($decoded)) {
                        $raw_photos = array_merge($raw_photos, $decoded);
                    } else {
                        $parts = preg_split('/[\r\n,;]+/', $val);
                        foreach ($parts as $part) {
                            if (trim($part) !== '') $raw_photos[] = trim($part);
                        }
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

    if (empty($clean_photos)) {
        $clean_photos[] = 'uploads/default_project.jpg';
    }

    $clean_photos = array_slice(array_values(array_unique($clean_photos)), 0, 20);

    return json_encode($clean_photos, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

try {
    $action = strtolower(trim((string)($_REQUEST['action'] ?? '')));

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($action === '' || in_array($action, ['fetch', 'get', 'list'], true))) {
        $rows = $pdo->query(
            "SELECT id, title, sub_title, location, pricing, bhk,
                    scope, property_type, size, description,
                    image_urls, created_at
             FROM projects
             ORDER BY id DESC"
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $urls = resolve_photo_array($row['image_urls'] ?? '');
            $row['image_urls'] = $urls;
            $row['image_url']  = $urls[0] ?? '';
            $row['imageUrl']   = $urls[0] ?? '';
            $row['img_url']    = $urls[0] ?? '';
            $row['image_count'] = count($urls);
        }
        unset($row);

        api_json('success', 'Projects fetched successfully', [
            'data' => $rows,
            'projects' => $rows,
            'count' => count($rows)
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['', 'add', 'create', 'save', 'publish', 'update'], true)) {
        $input = $_POST;
        if (empty($input)) {
            $json = json_decode(file_get_contents('php://input'), true);
            if (is_array($json)) $input = $json;
        }

        $title = trim((string)($input['title'] ?? ''));

        if ($title === '') {
            api_json('error', 'Project title is required.', [], 422);
        }

        $id = (int)($input['id'] ?? 0);
        $existing_json = null;

        if ($id > 0) {
            $stmt = $pdo->prepare("SELECT image_urls FROM projects WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $old = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($old) {
                $existing_json = $old['image_urls'] ?? null;
            }
        }

        $image_json = process_project_images($existing_json);

        $fields = [
            'sub_title'     => trim((string)($input['sub_title'] ?? $input['subTitle'] ?? 'Featured Residence')),
            'location'      => trim((string)($input['location'] ?? 'Mumbai')),
            'pricing'       => trim((string)($input['pricing'] ?? 'N/A')),
            'bhk'           => trim((string)($input['bhk'] ?? '3-BHK')),
            'scope'         => trim((string)($input['scope'] ?? 'Full Interior')),
            'property_type' => trim((string)($input['property_type'] ?? $input['propertyType'] ?? 'Apartment')),
            'size'          => trim((string)($input['size'] ?? '2000 sq ft')),
            'description'   => trim((string)($input['description'] ?? 'No description provided.'))
        ];

        if ($id > 0) {
            $stmt = $pdo->prepare(
                "UPDATE projects SET
                    title = :title,
                    sub_title = :sub_title,
                    location = :location,
                    pricing = :pricing,
                    bhk = :bhk,
                    scope = :scope,
                    property_type = :property_type,
                    size = :size,
                    description = :description,
                    image_urls = :image_urls
                 WHERE id = :id"
            );

            $stmt->execute([
                ':title' => $title,
                ':sub_title' => $fields['sub_title'],
                ':location' => $fields['location'],
                ':pricing' => $fields['pricing'],
                ':bhk' => $fields['bhk'],
                ':scope' => $fields['scope'],
                ':property_type' => $fields['property_type'],
                ':size' => $fields['size'],
                ':description' => $fields['description'],
                ':image_urls' => $image_json,
                ':id' => $id
            ]);

            $message = 'Project updated successfully';
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO projects
                (title, sub_title, location, pricing, bhk, scope, property_type, size, description, image_urls)
                 VALUES
                (:title, :sub_title, :location, :pricing, :bhk, :scope, :property_type, :size, :description, :image_urls)"
            );

            $stmt->execute([
                ':title' => $title,
                ':sub_title' => $fields['sub_title'],
                ':location' => $fields['location'],
                ':pricing' => $fields['pricing'],
                ':bhk' => $fields['bhk'],
                ':scope' => $fields['scope'],
                ':property_type' => $fields['property_type'],
                ':size' => $fields['size'],
                ':description' => $fields['description'],
                ':image_urls' => $image_json
            ]);

            $id = (int)$pdo->lastInsertId();
            $message = 'Project published successfully';
        }

        $stmt = $pdo->prepare(
            "SELECT id, title, sub_title, location, pricing, bhk, scope, property_type, size, description, image_urls, created_at
             FROM projects WHERE id = :id"
        );
        $stmt->execute([':id' => $id]);

        $project = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($project) {
            $urls = resolve_photo_array($project['image_urls'] ?? '');
            $project['image_urls'] = $urls;
            $project['image_url'] = $urls[0] ?? '';
            $project['imageUrl'] = $urls[0] ?? '';
            $project['img_url'] = $urls[0] ?? '';
            $project['image_count'] = count($urls);
        }

        api_json('success', $message, [
            'id' => $id,
            'data' => $project,
            'image_urls' => $urls ?? [],
            'image_url' => ($urls[0] ?? ''),
            'image_count' => count($urls ?? [])
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete') {
        $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
        if ($id <= 0) {
            api_json('error', 'Valid project ID is required.', [], 422);
        }

        $stmt = $pdo->prepare("DELETE FROM projects WHERE id = :id");
        $stmt->execute([':id' => $id]);

        if (!$stmt->rowCount()) {
            api_json('error', 'Project not found.', [], 404);
        }

        api_json('success', 'Project deleted successfully');
    }

    api_json('error', 'Invalid or missing action.', [], 400);

} catch (Throwable $e) {
    error_log('Projects API Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    api_json('error', 'Server error occurred.', [], 500);
}
?>