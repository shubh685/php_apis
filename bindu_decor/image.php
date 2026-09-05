<?php
// image.php
// Serves files from /uploads through PHP instead of direct static access,
// eliminating static CORS restrictions and 403 permission blocks.

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept");
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

// Enable error logging
error_reporting(E_ALL);
ini_set('display_errors', 1);

$path = (string)($_GET['path'] ?? '');
$path = trim($path);
if ($path === '') fail('Missing path parameter.', 400);

// Log the request for debugging
error_log("image.php: Requested path: " . $path);

// Normalize slashes, strip any parent-directory traversal attempts
$path = str_replace('\\', '/', $path);
$path = str_replace('..', '', $path);
$path = ltrim($path, '/');

// Strip known duplicate prefixes
$prefixes = ['bindu_decor/', 'api/bindu_decor/', 'uploads/uploads/'];
foreach ($prefixes as $p) {
    if (stripos($path, $p) === 0) {
        $path = substr($path, strlen($p));
        break;
    }
}

// Remove uploads/ prefix if present (we'll add it back)
if (stripos($path, 'uploads/') === 0) {
    $path = substr($path, 8);
}

// Ensure it targets the uploads/ folder
$path = 'uploads/' . $path;

// URL-decode the filename portion
$parts = explode('/', $path);
$last = array_pop($parts);
$last = rawurldecode($last);
$path = implode('/', array_merge($parts, [$last]));

$root = rtrim(str_replace('\\', '/', realpath(__DIR__)), '/');
$targetFile = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
$fullPath = realpath($targetFile);

// Fallback checking for files on Linux server environments
if (!$fullPath) {
    $fullPath = $targetFile;
}

$normalizedFullPath = str_replace('\\', '/', $fullPath);

// Security: Ensure resolved path resides within the root script directory
if (strpos($normalizedFullPath, $root) !== 0) {
    error_log("image.php: Access denied - path outside root: " . $normalizedFullPath);
    fail('Invalid path or access forbidden.', 403);
}

if (!is_file($fullPath) || !file_exists($fullPath)) {
    error_log("image.php: File not found: " . $fullPath);
    
    // Try alternative paths
    $alternatives = [
        __DIR__ . '/uploads/' . basename($path),
        __DIR__ . '/' . basename($path),
        __DIR__ . '/../uploads/' . basename($path),
    ];
    
    foreach ($alternatives as $alt) {
        if (file_exists($alt) && is_file($alt)) {
            $fullPath = $alt;
            error_log("image.php: Found alternative: " . $fullPath);
            break;
        }
    }
    
    if (!file_exists($fullPath) || !is_file($fullPath)) {
        fail('Image not found: ' . $path, 404);
    }
}

// Ensure file readable permission
@chmod($fullPath, 0644);

$mime = @mime_content_type($fullPath);
if (!$mime) {
    $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
    $map = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'bmp' => 'image/bmp',
        'avif' => 'image/avif'
    ];
    $mime = $map[$ext] ?? 'application/octet-stream';
}

// Clean any accidental output buffers before delivering image streams
if (ob_get_level()) {
    ob_end_clean();
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($fullPath));
header('Cache-Control: public, max-age=604800');
header('X-Content-Type-Options: nosniff');
readfile($fullPath);
exit;
?>