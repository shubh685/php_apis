<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/database.php';

// ==========================================
// CORE HELPER FUNCTIONS
// ==========================================

function api_json($status, $message = '', $data = [], $code = 200) {
    http_response_code($code);
    echo json_encode(array_merge(['status'=>$status,'message'=>$message], $data), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

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

function image_ext($mime, $name='') {
    $map = [
        'image/jpeg'=>'jpg', 'image/jpg'=>'jpg', 'image/png'=>'png',
        'image/webp'=>'webp', 'image/gif'=>'gif', 'image/bmp'=>'bmp',
        'image/x-ms-bmp'=>'bmp', 'image/avif'=>'avif'
    ];
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
    $allowed = ['image/jpeg','image/png','image/webp','image/gif','image/bmp','image/x-ms-bmp','image/avif'];
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

function download_image($url,$prefix) {
    $url = trim($url);
    if (strpos($url,'//') === 0) $url = 'https:'.$url;
    if (!preg_match('#^https?://#i',$url)) {
        return [false,'Invalid external image URL: ' . $url];
    }
    $ch = curl_init($url);
    if (!$ch) return [false,'Unable to initialize image download.'];
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_FOLLOWLOCATION=>true,
        CURLOPT_MAXREDIRS=>7,
        CURLOPT_CONNECTTIMEOUT=>30,
        CURLOPT_TIMEOUT=>90,
        CURLOPT_USERAGENT=>'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/151 Safari/537.36',
        CURLOPT_HTTPHEADER=>['Accept: image/avif,image/webp,image/apng,image/*,*/*;q=0.8'],
        CURLOPT_SSL_VERIFYPEER=>false,
        CURLOPT_SSL_VERIFYHOST=>false,
        CURLOPT_REFERER=>$_SERVER['HTTP_HOST'] ?? 'localhost'
    ]);
    $data = curl_exec($ch);
    $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    $err=curl_error($ch);
    curl_close($ch);
    if ($data === false || $code < 200 || $code >= 400 || $data === '') {
        return [false,'Unable to download external image (HTTP '.$code.'). '.$err];
    }
    $tmp = tempnam(sys_get_temp_dir(),'bd_img_');
    if ($tmp === false || file_put_contents($tmp,$data) === false) {
        return [false,'Unable to create temporary image file.'];
    }
    [$ok,$mime] = valid_image($tmp);
    if (!$ok) {
        @unlink($tmp);
        return [false,'External URL did not return a supported image. '.$mime];
    }
    $path = parse_url($url,PHP_URL_PATH) ?: '';
    $ext = image_ext($mime, $path);
    $name = unique_image_name($prefix, $ext);
    $dest = uploads_dir().$name;
    if (!rename($tmp,$dest)) {
        @unlink($tmp);
        return [false,'Unable to save downloaded image.'];
    }
    @chmod($dest, 0644);
    return [true,'uploads/'.$name];
}

function uploaded_image($prefix) {
    foreach (['imageFile','image','image_file','file','media','photo','logo'] as $field) {
        if (isset($_FILES[$field]) && ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            return save_uploaded($_FILES[$field],$prefix);
        }
    }
    return null;
}

function input_image($prefix, $field = 'image_url') {
    $uploaded = uploaded_image($prefix);
    if ($uploaded !== null) return $uploaded;

    $url = '';
    foreach ([$field, 'image_url', 'imageUrl', 'img_url', 'imgUrl', 'url', 'media_url', 'mediaUrl'] as $key) {
        if (isset($_POST[$key]) && trim((string)$_POST[$key]) !== '') {
            $url = trim((string)$_POST[$key]);
            break;
        }
    }

    if ($url === '') return [false, 'Image is required. Please upload a file or provide a URL.'];

    // Direct External URL Support (NO local download to uploads/)
    if (preg_match('#^https?://#i', $url) || strpos($url, '//') === 0) {
        if (strpos($url, '//') === 0) $url = 'https:' . $url;
        return [true, $url];
    }

    // Local file path validation
    $clean_path = ltrim(str_replace('\\', '/', $url), '/');
    if (stripos($clean_path, 'bindu_decor/') === 0) {
        $clean_path = substr($clean_path, strlen('bindu_decor/'));
    }
    if (stripos($clean_path, 'uploads/uploads/') === 0) {
        $clean_path = substr($clean_path, strlen('uploads/'));
    }

    $physical_path = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $clean_path);
    if (file_exists($physical_path)) {
        return [true, $clean_path];
    }

    return [false, 'The image file does not exist on the server: ' . $url];
}

function public_image_url($value) {
    $value = trim((string)$value);
    if ($value === '') return '';

    // Direct HTTP/HTTPS or Base64 Data URIs return untouched
    if (preg_match('#^https?://#i', $value) || strpos($value, 'data:image/') === 0) {
        return $value;
    }

    // Clean relative path
    $value = str_replace('\\', '/', $value);
    $value = ltrim($value, '/');

    // Clean double prefixes
    $prefixes = ['bindu_decor/', 'api/bindu_decor/', 'uploads/uploads/'];
    foreach ($prefixes as $prefix) {
        if (stripos($value, $prefix) === 0) {
            $value = substr($value, strlen($prefix));
            break;
        }
    }

    // Ensure path starts with uploads/ unless it's image.php
    if (stripos($value, 'uploads/') !== 0 && stripos($value, 'image.php') !== 0) {
        $value = 'uploads/' . $value;
    }

    // Format local uploads through image.php proxy for CORS and reliable delivery
    if (stripos($value, 'image.php') !== 0) {
        $value = 'image.php?path=' . rawurlencode($value);
    }

    // Return full absolute URL for Flutter
    return rtrim(api_base_url(), '/') . '/' . $value;
}

function request_action() {
    return strtolower(trim((string)($_REQUEST['action'] ?? '')));
}

function delete_stored_image($value) {
    $value=trim((string)$value);
    if ($value==='' || preg_match('#^https?://#i',$value)) return;
    $value=ltrim(str_replace('\\','/',$value),'/');
    if (stripos($value,'bindu_decor/')===0) {
        $value=substr($value,strlen('bindu_decor/'));
    }
    $root=realpath(__DIR__);
    $file=realpath(__DIR__.DIRECTORY_SEPARATOR.$value);
    if ($file && $root && str_starts_with($file,$root.DIRECTORY_SEPARATOR) && is_file($file)) {
        @unlink($file);
    }
}

// ==========================================
// API ROUTES (products, projects, clients)
// ==========================================

try {
    $action=request_action();

    // PRODUCTS
    if ($_SERVER['REQUEST_METHOD']==='GET' && $action==='fetch_products') {
        $rows=$pdo->query("SELECT id,title,category,image_url,description,material,print_type,created_at FROM products ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as &$r){
            $u=public_image_url($r['image_url']??'');
            $r['image_url']=$u;
            $r['imageUrl']=$u;
            $r['img_url']=$u;
        }
        api_json('success','Products fetched successfully',['data'=>$rows,'products'=>$rows]);
    }

    if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='add_product') {
        $title=trim((string)($_POST['title']??''));
        if($title==='') api_json('error','Product title is required.',[],422);
        [$ok,$image]=input_image('product','image_url');
        if(!$ok) api_json('error',$image,[],422);
        $category=trim((string)($_POST['category']??'HOME DECOR'));
        $description=trim((string)($_POST['description']??'High-quality decor item.'));
        $material=trim((string)($_POST['material']??'Premium Grade Material'));
        $print=trim((string)($_POST['print_type']??$_POST['printType']??'High Definition Digital Print / Finish'));
        $s=$pdo->prepare("INSERT INTO products (title,category,image_url,description,material,print_type) VALUES (:title,:category,:image_url,:description,:material,:print_type)");
        $s->execute([
            ':title'=>$title,
            ':category'=>$category,
            ':image_url'=>$image,
            ':description'=>$description,
            ':material'=>$material,
            ':print_type'=>$print
        ]);
        $id=(int)$pdo->lastInsertId();
        $url=public_image_url($image);
        api_json('success','Product published successfully',[
            'id'=>$id,
            'image_url'=>$url,
            'imageUrl'=>$url,
            'img_url'=>$url,
            'data'=>['id'=>$id,'image_url'=>$url,'imageUrl'=>$url,'img_url'=>$url]
        ],201);
    }

    if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='delete_product') {
        $id=(int)($_POST['id']??$_GET['id']??0);
        if($id<=0) api_json('error','Valid product ID is required.',[],422);
        $q=$pdo->prepare('SELECT image_url FROM products WHERE id=:id');
        $q->execute([':id'=>$id]);
        $old=$q->fetch(PDO::FETCH_ASSOC);
        $d=$pdo->prepare('DELETE FROM products WHERE id=:id');
        $d->execute([':id'=>$id]);
        if(!$d->rowCount()) api_json('error','Product not found.',[],404);
        if($old) delete_stored_image($old['image_url']??'');
        api_json('success','Product deleted successfully');
    }

    // PROJECTS
    if ($_SERVER['REQUEST_METHOD']==='GET' && $action==='fetch_projects') {
        $rows=$pdo->query("SELECT id,title,sub_title,location,pricing,bhk,scope,property_type,size,description,image_url,created_at FROM projects ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as &$r){
            $u=public_image_url($r['image_url']??'');
            $r['image_url']=$u;
            $r['imageUrl']=$u;
            $r['img_url']=$u;
        }
        api_json('success','Projects fetched successfully',['data'=>$rows,'projects'=>$rows]);
    }

    if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='add_project') {
        $title=trim((string)($_POST['title']??''));
        if($title==='') api_json('error','Project title is required.',[],422);
        [$ok,$image]=input_image('project','image_url');
        if(!$ok) api_json('error',$image,[],422);
        $fields=[
            'sub_title'=>trim((string)($_POST['sub_title']??$_POST['subTitle']??'Featured Residence')),
            'location'=>trim((string)($_POST['location']??'Mumbai')),
            'pricing'=>trim((string)($_POST['pricing']??'N/A')),
            'bhk'=>trim((string)($_POST['bhk']??'3-BHK')),
            'scope'=>trim((string)($_POST['scope']??'Full Interior')),
            'property_type'=>trim((string)($_POST['property_type']??$_POST['propertyType']??'Apartment')),
            'size'=>trim((string)($_POST['size']??'2000 sq ft')),
            'description'=>trim((string)($_POST['description']??'No description provided.')),
        ];
        $s=$pdo->prepare("INSERT INTO projects (title,sub_title,location,pricing,bhk,scope,property_type,size,description,image_url) VALUES (:title,:sub_title,:location,:pricing,:bhk,:scope,:property_type,:size,:description,:image_url)");
        $s->execute(array_merge([':title'=>$title,':image_url'=>$image],array_combine(array_map(fn($k)=>':'.$k,array_keys($fields)),array_values($fields))));
        $id=(int)$pdo->lastInsertId();
        $url=public_image_url($image);
        api_json('success','Project published successfully',['id'=>$id,'image_url'=>$url,'imageUrl'=>$url,'img_url'=>$url,'data'=>['id'=>$id,'image_url'=>$url,'imageUrl'=>$url,'img_url'=>$url]],201);
    }

    if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='delete_project') {
        $id=(int)($_POST['id']??$_GET['id']??0);
        if($id<=0) api_json('error','Valid project ID is required.',[],422);
        $q=$pdo->prepare('SELECT image_url FROM projects WHERE id=:id');
        $q->execute([':id'=>$id]);
        $old=$q->fetch(PDO::FETCH_ASSOC);
        $d=$pdo->prepare('DELETE FROM projects WHERE id=:id');
        $d->execute([':id'=>$id]);
        if(!$d->rowCount()) api_json('error','Project not found.',[],404);
        if($old) delete_stored_image($old['image_url']??'');
        api_json('success','Project deleted successfully');
    }

    // CLIENTS
    if ($_SERVER['REQUEST_METHOD']==='GET' && $action==='fetch_clients') {
        $rows=$pdo->query("SELECT id,img_url,created_at FROM clients ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as &$r){
            $u=public_image_url($r['img_url']??'');
            $r['img_url']=$u;
            $r['image_url']=$u;
            $r['imageUrl']=$u;
            $r['imgUrl']=$u;
        }
        api_json('success','Clients fetched successfully',['data'=>$rows,'clients'=>$rows]);
    }

    if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='add_client') {
        [$ok,$image]=input_image('client','image_url');
        if(!$ok) api_json('error',$image,[],422);
        $s=$pdo->prepare('INSERT INTO clients (img_url) VALUES (:img_url)');
        $s->execute([':img_url'=>$image]);
        $id=(int)$pdo->lastInsertId();
        $url=public_image_url($image);
        api_json('success','Client logo published successfully',['id'=>$id,'img_url'=>$url,'image_url'=>$url,'imageUrl'=>$url,'imgUrl'=>$url,'data'=>['id'=>$id,'img_url'=>$url,'image_url'=>$url,'imageUrl'=>$url,'imgUrl'=>$url]],201);
    }

    if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='delete_client') {
        $id=(int)($_POST['id']??$_GET['id']??0);
        if($id<=0) api_json('error','Valid client ID is required.',[],422);
        $q=$pdo->prepare('SELECT img_url FROM clients WHERE id=:id');
        $q->execute([':id'=>$id]);
        $old=$q->fetch(PDO::FETCH_ASSOC);
        $d=$pdo->prepare('DELETE FROM clients WHERE id=:id');
        $d->execute([':id'=>$id]);
        if(!$d->rowCount()) api_json('error','Client not found.',[],404);
        if($old) delete_stored_image($old['img_url']??'');
        api_json('success','Client deleted successfully');
    }

    api_json('error','Invalid action specified.',[],400);

} catch(Throwable $e){
    api_json('error','Server error: '.$e->getMessage(),[],500);
}
?>s