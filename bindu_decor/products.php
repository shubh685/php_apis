<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, X-Requested-With");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
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

    $is_https = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (($_SERVER['SERVER_PORT'] ?? 80) == 443)
    );

    $scheme = $is_https ? 'https://' : 'http://';

    return ($directory === '' || $directory === '.')
        ? $scheme . $host
        : $scheme . $host . '/' . $directory;
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
    $map = [
        'image/jpeg' => 'jpg',
        'image/jpg'  => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
        'image/bmp'  => 'bmp',
        'image/x-ms-bmp' => 'bmp',
        'image/avif' => 'avif'
    ];

    $mime = strtolower(trim((string)$mime));
    if (isset($map[$mime])) return $map[$mime];

    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($ext === 'jpeg') $ext = 'jpg';

    return in_array($ext, ['jpg','png','webp','gif','bmp','avif'], true) ? $ext : 'jpg';
}

function valid_image($path) {
    if (!is_file($path) || filesize($path) <= 0) {
        return [false, 'Image file is empty or missing.'];
    }

    $info = @getimagesize($path);
    if ($info === false) {
        return [false, 'File is not a valid image.'];
    }

    $mime = strtolower((string)($info['mime'] ?? ''));
    $allowed = [
        'image/jpeg','image/png','image/webp',
        'image/gif','image/bmp','image/x-ms-bmp','image/avif'
    ];

    if (!in_array($mime, $allowed, true)) {
        return [false, 'Unsupported image type: ' . $mime];
    }

    return [true, $mime];
}

function unique_image_name($prefix, $ext) {
    return $prefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
}

function save_uploaded($file, $prefix) {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
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

    return is_file($dest) && filesize($dest) > 0
        ? [true, 'uploads/' . $name]
        : [false, 'Uploaded image was not saved correctly.'];
}

function uploaded_images_array($prefix) {
    $saved = [];
    $fields = ['image_urls', 'image_urls[]', 'image_url', 'imageUrls', 'imageUrls[]', 'imageUrl', 'images', 'photos', 'photo', 'img_url', 'file', 'files', 'media'];

    foreach ($fields as $field) {
        if (!isset($_FILES[$field])) continue;

        $f = $_FILES[$field];

        if (is_array($f['name'])) {
            foreach ($f['name'] as $i => $name) {
                if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;

                $single = [
                    'name' => $f['name'][$i],
                    'type' => $f['type'][$i] ?? '',
                    'tmp_name' => $f['tmp_name'][$i],
                    'error' => $f['error'][$i],
                    'size' => $f['size'][$i] ?? 0
                ];

                [$ok, $path] = save_uploaded($single, $prefix);
                if ($ok) $saved[] = $path;
            }
        } elseif (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            [$ok, $path] = save_uploaded($f, $prefix);
            if ($ok) $saved[] = $path;
        }
    }

    return $saved;
}

function extract_image_urls_from_sources() {
    $result = [];
    $keys = [
        'image_urls','image_urls[]','image_url',
        'imageUrls','imageUrls[]','imageUrl',
        'images','photos','photo','img_url','url','urls','media_url',
        'external_urls','external_urls[]','external_url'
    ];

    $sources = [$_POST, $_GET];

    $rawBody = file_get_contents('php://input');
    $jsonBody = json_decode($rawBody, true);
    if (is_array($jsonBody)) $sources[] = $jsonBody;

    foreach ($sources as $source) {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $source)) continue;

            $values = is_array($source[$key]) ? $source[$key] : [$source[$key]];

            foreach ($values as $value) {
                if (!is_string($value)) continue;

                $value = trim($value);
                if ($value === '') continue;

                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $item) {
                        if (is_string($item) && trim($item) !== '') {
                            $result[] = trim($item);
                        }
                    }
                    continue;
                }

                $parts = preg_split('/[\r\n,;]+/', $value);
                foreach ($parts as $part) {
                    $part = trim($part);
                    if ($part !== '') $result[] = $part;
                }
            }
        }
    }

    return array_values(array_unique($result));
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

function process_multiple_images($prefix, $existing_json = null) {
    $photos = [];

    if (!empty($existing_json)) {
        $existing = json_decode($existing_json, true);
        if (!is_array($existing)) {
            $existing = [$existing_json];
        }

        foreach ($existing as $item) {
            $item = trim((string)$item);
            if ($item !== '' && !in_array($item, $photos, true)) {
                $photos[] = $item;
            }
        }
    }

    foreach (uploaded_images_array($prefix) as $path) {
        if (!in_array($path, $photos, true)) {
            $photos[] = $path;
        }
    }

    $rawUrls = extract_image_urls_from_sources();

    foreach ($rawUrls as $url) {
        $url = trim($url);
        if ($url === '') continue;

        if (preg_match('#^https?://#i', $url) || strpos($url, 'data:image/') === 0) {
            if (!in_array($url, $photos, true)) {
                $photos[] = $url;
            }
            continue;
        }

        $base = api_base_url();
        if (strpos($url, $base) === 0) {
            $url = substr($url, strlen($base));
            $url = ltrim($url, '/');
        }

        $url = str_replace('\\', '/', $url);
        $url = ltrim($url, '/');

        $prefixes = ['bindu_decor/', 'api/bindu_decor/', 'uploads/uploads/'];
        foreach ($prefixes as $p) {
            if (stripos($url, $p) === 0) {
                $url = substr($url, strlen($p));
                break;
            }
        }

        if ($url !== '' && !in_array($url, $photos, true)) {
            $photos[] = $url;
        }
    }

    $photos = array_slice(array_values(array_unique($photos)), 0, 20);

    return json_encode($photos, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

try {
    $action = strtolower(trim((string)($_REQUEST['action'] ?? '')));

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($action === '' || in_array($action, ['fetch','get','list'], true))) {
        $rows = $pdo->query(
            "SELECT id, title, category, image_urls, description, material, print_type, created_at
             FROM products ORDER BY id DESC"
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $urls = resolve_image_urls($row['image_urls'] ?? '');
            $row['image_urls'] = $urls;
            $row['image_url']  = $urls[0] ?? '';
            $row['imageUrl']   = $urls[0] ?? '';
            $row['img_url']    = $urls[0] ?? '';
            $row['image_count'] = count($urls);
        }
        unset($row);

        api_json('success', 'Products fetched successfully', [
            'data' => $rows,
            'products' => $rows,
            'count' => count($rows)
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['', 'add','create','save','publish','update'], true)) {
        $input = $_POST;
        if (empty($input)) {
            $json = json_decode(file_get_contents('php://input'), true);
            if (is_array($json)) $input = $json;
        }

        $id = (int)($input['id'] ?? 0);
        $title = trim((string)($input['title'] ?? ''));

        if ($title === '') {
            api_json('error', 'Product title is required.', [], 422);
        }

        $existing_json = null;
        if ($id > 0) {
            $stmt = $pdo->prepare("SELECT image_urls FROM products WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $old = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($old) {
                $existing_json = $old['image_urls'] ?? null;
            }
        }

        $image_json = process_multiple_images('product', $existing_json);

        $category = trim((string)($input['category'] ?? 'HOME DECOR'));
        $description = trim((string)($input['description'] ?? 'High-quality decor item.'));
        $material = trim((string)($input['material'] ?? 'Premium Grade Material'));
        $print = trim((string)($input['print_type'] ?? $input['printType'] ?? 'High Definition Digital Print / Finish'));

        if ($id > 0) {
            $stmt = $pdo->prepare(
                "UPDATE products SET
                    title = :title,
                    category = :category,
                    image_urls = :image_urls,
                    description = :description,
                    material = :material,
                    print_type = :print_type
                 WHERE id = :id"
            );

            $stmt->execute([
                ':title' => $title,
                ':category' => $category,
                ':image_urls' => $image_json,
                ':description' => $description,
                ':material' => $material,
                ':print_type' => $print,
                ':id' => $id
            ]);

            $message = 'Product updated successfully';
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO products
                (title, category, image_urls, description, material, print_type)
                VALUES
                (:title, :category, :image_urls, :description, :material, :print_type)"
            );

            $stmt->execute([
                ':title' => $title,
                ':category' => $category,
                ':image_urls' => $image_json,
                ':description' => $description,
                ':material' => $material,
                ':print_type' => $print
            ]);

            $id = (int)$pdo->lastInsertId();
            $message = 'Product saved successfully';
        }

        $stmt = $pdo->prepare(
            "SELECT id, title, category, image_urls, description, material, print_type, created_at
             FROM products WHERE id = :id"
        );
        $stmt->execute([':id' => $id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($product) {
            $urls = resolve_image_urls($product['image_urls'] ?? '');
            $product['image_urls'] = $urls;
            $product['image_url'] = $urls[0] ?? '';
            $product['imageUrl'] = $urls[0] ?? '';
            $product['img_url'] = $urls[0] ?? '';
            $product['image_count'] = count($urls);
        }

        api_json('success', $message, [
            'id' => $id,
            'data' => $product,
            'image_urls' => $urls ?? [],
            'image_url' => ($urls[0] ?? ''),
            'image_count' => count($urls ?? [])
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete') {
        $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
        if ($id <= 0) {
            api_json('error', 'Valid product ID is required.', [], 422);
        }

        $stmt = $pdo->prepare("DELETE FROM products WHERE id = :id");
        $stmt->execute([':id' => $id]);

        if (!$stmt->rowCount()) {
            api_json('error', 'Product not found.', [], 404);
        }

        api_json('success', 'Product deleted successfully');
    }

    api_json('error', 'Invalid or missing action.', [], 400);

} catch (Throwable $e) {
    error_log('Products API Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    api_json('error', 'Server error: ' . $e->getMessage(), [], 500);
}
?>