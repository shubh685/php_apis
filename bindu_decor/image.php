<?php
// image.php
// Serves files from /uploads through PHP instead of direct static access,
// so images get exactly the same CORS headers and connection path that
// your working clients.php / projects.php / products.php already use.
//
// Usage: image.php?path=uploads/client_20260824_121828_xxx.png

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: GET, OPTIONS");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function fail($msg, $code = 404) {
    http_response_code($code);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $msg;
    exit;
}

$path = (string)($_GET['path'] ?? '');
$path = trim($path);
if ($path === '') fail('Missing path parameter.', 400);

// Normalize slashes, strip any parent-directory traversal attempts
$path = str_replace('\\', '/', $path);
$path = str_replace('..', '', $path);
$path = ltrim($path, '/');

// Strip known duplicate prefixes, same logic as your existing public_image_url()
$prefixes = ['bindu_decor/', 'api/bindu_decor/', 'uploads/uploads/'];
foreach ($prefixes as $p) {
    if (stripos($path, $p) === 0) {
        $path = substr($path, strlen($p));
        break;
    }
}

// Ensure it targets the uploads/ folder
if (stripos($path, 'uploads/') !== 0) {
    $path = 'uploads/' . $path;
}

// URL-decode the filename portion (public_image_url() encodes it when returning URLs)
$parts = explode('/', $path);
$last = array_pop($parts);
$last = rawurldecode($last);
$path = implode('/', array_merge($parts, [$last]));

$root = realpath(__DIR__);
$fullPath = realpath(__DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path));

// Security: make sure resolved path is still inside this directory
if (!$fullPath || !$root || strpos($fullPath, $root . DIRECTORY_SEPARATOR) !== 0) {
    fail('Invalid path.', 400);
}

if (!is_file($fullPath)) {
    fail('Image not found: ' . $path, 404);
}

$mime = @mime_content_type($fullPath);
if (!$mime) {
    $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
    $map = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','gif'=>'image/gif','bmp'=>'image/bmp','avif'=>'image/avif'];
    $mime = $map[$ext] ?? 'application/octet-stream';
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($fullPath));
header('Cache-Control: public, max-age=604800');
header('X-Content-Type-Options: nosniff');
readfile($fullPath);
exit;