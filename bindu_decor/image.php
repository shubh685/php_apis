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

$path = (string)($_GET['path'] ?? '');
$path = trim($path);
if ($path === '') fail('Missing path parameter.', 400);

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

// Ensure it targets the uploads/ folder
if (stripos($path, 'uploads/') !== 0) {
    $path = 'uploads/' . $path;
}

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
    fail('Invalid path or access forbidden.', 403);
}

if (!is_file($fullPath) || !file_exists($fullPath)) {
    fail('Image not found: ' . $path, 404);
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