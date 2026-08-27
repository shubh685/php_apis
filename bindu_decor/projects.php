<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, X-Requested-With");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/database.php';

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
    $directory = trim(str_replace('\\', '/', dirname($script)), '/');

    $is_https = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (($_SERVER['SERVER_PORT'] ?? 80) == 443)
    );
    $scheme = $is_https ? 'https://' : 'http://';

    return $directory === '' || $directory === '.'
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

    return in_array($ext, ['jpg','png','webp','gif','bmp','avif'], true)
        ? $ext
        : 'jpg';
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
    return $prefix . '_' . date('Ymd_His') . '_' .
        bin2hex(random_bytes(8)) . '.' . $ext;
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
    $fields = ['imageFile','image','images','image_file','file','files','media','photo','photos','logo'];

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
        } else {
            if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                [$ok, $path] = save_uploaded($f, $prefix);
                if ($ok) $saved[] = $path;
            }
        }
    }

    return $saved;
}

/* Reusable extractor (same as used in products/blogs) */
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

function download_external_image($url, $prefix) {
    $url = trim($url);

    if (!preg_match('#^https?://#i', $url)) {
        return [false, 'Only HTTP/HTTPS image URLs are supported.'];
    }

    if (!function_exists('curl_init')) {
        return [false, 'PHP cURL extension is not enabled.'];
    }

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_USERAGENT => 'Mozilla/5.0',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => [
            'Accept: image/avif,image/webp,image/apng,image/*,*/*;q=0.8'
        ]
    ]);

    $body = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false || $http < 200 || $http >= 400) {
        return [false, 'Unable to download image. HTTP ' . $http . ($error ? ': ' . $error : '')];
    }

    $tmp = tempnam(sys_get_temp_dir(), 'img_');
    if ($tmp === false || file_put_contents($tmp, $body) === false) {
        @unlink($tmp);
        return [false, 'Unable to create temporary image file.'];
    }

    [$ok, $mime] = valid_image($tmp);

    if (!$ok) {
        @unlink($tmp);
        return [false, 'Remote URL did not return a valid image.'];
    }

    $ext = image_ext($mime, parse_url($url, PHP_URL_PATH) ?? '');
    $name = unique_image_name($prefix, $ext);
    $dest = uploads_dir() . $name;

    if (!rename($tmp, $dest)) {
        if (!copy($tmp, $dest)) {
            @unlink($tmp);
            return [false, 'Unable to save downloaded image.'];
        }
        @unlink($tmp);
    }

    @chmod($dest, 0644);

    return [true, 'uploads/' . $name];
}

function normalize_stored_path($value) {
    $value = trim((string)$value);
    if ($value === '') return '';

    if (preg_match('#^https?://#i', $value)) return $value;

    $base = rtrim(api_base_url(), '/');
    if (stripos($value, $base . '/') === 0) {
        $value = substr($value, strlen($base) + 1);
    }

    $value = ltrim(str_replace('\\', '/', $value), '/');

    foreach (['bindu_decor/','api/bindu_decor/','uploads/uploads/'] as $prefix) {
        if (stripos($value, $prefix) === 0) {
            $value = substr($value, strlen($prefix));
            break;
        }
    }

    if (stripos($value, 'uploads/') !== 0 &&
        stripos($value, 'image.php') !== 0) {
        $value = 'uploads/' . $value;
    }

    return $value;
}

/* public_image_url: use blogs.php behavior (direct /uploads/... link) */
function public_image_url($value) {
    $value = trim((string)$value);
    if ($value === '') return '';

    if (preg_match('#^https?://#i', $value) ||
        strpos($value, 'data:image/') === 0) {
        return $value;
    }

    $value = ltrim(str_replace('\\', '/', $value), '/');

    foreach (['bindu_decor/','api/bindu_decor/','uploads/uploads/'] as $prefix) {
        if (stripos($value, $prefix) === 0) {
            $value = substr($value, strlen($prefix));
            break;
        }
    }

    if (stripos($value, 'uploads/') !== 0 &&
        stripos($value, 'image.php') !== 0) {
        $value = 'uploads/' . $value;
    }

    return rtrim(api_base_url(), '/') . '/' . ltrim($value, '/');
}

function resolve_photo_array($photos_json) {
    if (empty($photos_json)) return [];

    $photos = json_decode($photos_json, true);
    if (!is_array($photos)) $photos = [$photos_json];

    $result = [];

    foreach ($photos as $photo) {
        $url = public_image_url($photo);
        if ($url !== '' && !in_array($url, $result, true)) {
            $result[] = $url;
        }
    }

    return $result;
}

/*
 * process_project_images now mirrors blogs.php behavior but retains the
 * existing default behavior (adds uploads/default_project.jpg when empty).
 */
function process_project_images($prefix, $existing_json = null) {
    $photos = [];

    if (!empty($existing_json)) {
        $existing = json_decode($existing_json, true);
        if (!is_array($existing)) $existing = [$existing_json];

        foreach ($existing as $item) {
            $item = trim((string)$item);
            if ($item !== '' && !in_array($item, $photos, true)) {
                $photos[] = $item;
            }
        }
    }

    /* Local uploaded files */
    foreach (uploaded_images_array($prefix) as $path) {
        if (!in_array($path, $photos, true)) $photos[] = $path;
    }

    /* External / input URLs */
    $rawUrls = extract_image_urls_from_sources();

    foreach ($rawUrls as $url) {
        if (preg_match('#^https?://#i', $url) || strpos($url, 'data:image/') === 0) {
            if (preg_match('#^https?://#i', $url)) {
                [$ok, $savedPath] = download_external_image($url, $prefix);
                if ($ok) {
                    if (!in_array($savedPath, $photos, true)) $photos[] = $savedPath;
                    continue;
                }
            }
            if (!in_array($url, $photos, true)) $photos[] = $url;
        } else {
            $path = normalize_stored_path($url);
            if ($path !== '' && !in_array($path, $photos, true)) {
                $photos[] = $path;
            }
        }
    }

    if (empty($photos)) {
        $photos[] = 'uploads/default_project.jpg';
    }

    return json_encode(
        array_values(array_unique($photos)),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
}

function request_action() {
    return strtolower(trim((string)($_REQUEST['action'] ?? '')));
}

function delete_stored_image($value) {
    $items = json_decode((string)$value, true);
    if (!is_array($items)) $items = [$value];

    $root = realpath(__DIR__);
    if (!$root) return;

    foreach ($items as $item) {
        $item = trim((string)$item);

        if ($item === '' || preg_match('#^https?://#i', $item)) {
            continue;
        }

        $path = normalize_stored_path($item);

        if (stripos($path, 'uploads/') !== 0) continue;

        $file = realpath(__DIR__ . DIRECTORY_SEPARATOR . $path);

        if ($file &&
            str_starts_with($file, $root . DIRECTORY_SEPARATOR) &&
            is_file($file)) {
            @unlink($file);
        }
    }
}

try {
    $action = request_action();

    /* ========================= GET ========================= */
    if ($_SERVER['REQUEST_METHOD'] === 'GET' &&
        ($action === '' || in_array($action, ['fetch','get','list'], true))) {

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
        }
        unset($row);

        api_json('success', 'Projects fetched successfully', [
            'data' => $rows,
            'projects' => $rows
        ]);
    }

    /* ================= POST SAVE ================= */
    if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
        in_array($action, ['', 'add','create','save','publish','update'], true)) {

        /*
         * For multipart/form-data, $_POST contains the fields.
         * For application/json, read php://input.
         */
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
            $stmt = $pdo->prepare(
                "SELECT image_urls FROM projects WHERE id = :id"
            );
            $stmt->execute([':id' => $id]);
            $old = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($old) {
                $existing_json = $old['image_urls'] ?? null;
            }
        }

        $image_json = process_project_images('project', $existing_json);

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
                (title, sub_title, location, pricing, bhk, scope,
                 property_type, size, description, image_urls)
                 VALUES
                (:title, :sub_title, :location, :pricing, :bhk, :scope,
                 :property_type, :size, :description, :image_urls)"
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
            "SELECT id, title, sub_title, location, pricing, bhk,
                    scope, property_type, size, description,
                    image_urls, created_at
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
        }

        $urls = resolve_photo_array($image_json);

        api_json('success', $message, [
            'id' => $id,
            'image_urls' => $urls,
            'image_url' => $urls[0] ?? '',
            'data' => $project
        ]);
    }

    /* ================= DELETE ================= */
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete') {

        $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);

        if ($id <= 0) {
            api_json('error', 'Valid project ID is required.', [], 422);
        }

        $stmt = $pdo->prepare(
            "SELECT image_urls FROM projects WHERE id = :id"
        );
        $stmt->execute([':id' => $id]);
        $old = $stmt->fetch(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare(
            "DELETE FROM projects WHERE id = :id"
        );
        $stmt->execute([':id' => $id]);

        if (!$stmt->rowCount()) {
            api_json('error', 'Project not found.', [], 404);
        }

        if ($old) {
            delete_stored_image($old['image_urls'] ?? '');
        }

        api_json('success', 'Project deleted successfully');
    }

    api_json('error', 'Invalid or missing action.', [], 400);

} catch (Throwable $e) {
    api_json('error', 'Server error: ' . $e->getMessage(), [], 500);
}
?>