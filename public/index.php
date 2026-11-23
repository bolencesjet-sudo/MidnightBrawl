<?php
declare(strict_types=1);

// The only dynamic HTTP entry point in the container. Root PHP files are private.
$path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);
if ($path==='/' || $path==='/index.php') {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    require dirname(__DIR__).'/index.php';
    exit;
}
if ($path==='/api.php') {
    require dirname(__DIR__).'/api.php';
    exit;
}
require_once dirname(__DIR__).'/security.php';
securityHeaders();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($path==='/health' && ($_SERVER['REQUEST_METHOD']??'GET')==='GET') {
    echo json_encode(['status'=>'ok']);
    exit;
}
http_response_code(404);
echo json_encode(['error'=>'Not found.']);
