<?php
declare(strict_types=1);
// Local adapter evaluates the checked-in Vercel route table and invokes its PHP entry.
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
$config=json_decode(file_get_contents(dirname(__DIR__).'/vercel.json'),true,512,JSON_THROW_ON_ERROR);
foreach ($config['routes'] as $route) {
    $pattern='~^'.$route['src'].'$~D';
    if (!preg_match($pattern,$path)) continue;
    if (isset($route['status'])) {
        http_response_code($route['status']); header('Content-Type: application/json');
        echo json_encode(['error'=>'Not found.']); return;
    }
    $destination=preg_replace($pattern,$route['dest'],$path);
    $target=parse_url($destination,PHP_URL_PATH);
    if (str_starts_with($target,'/assets/')) {
        $types=['js'=>'application/javascript','css'=>'text/css','svg'=>'image/svg+xml'];
        header('Content-Type: '.$types[pathinfo($target,PATHINFO_EXTENSION)]);
        readfile(dirname(__DIR__).$target); return;
    }
    parse_str(parse_url($destination,PHP_URL_QUERY)??'',$dispatch);
    $_GET=array_merge($_GET,$dispatch);
    require dirname(__DIR__).'/api/entry.php'; return;
}
http_response_code(404);
