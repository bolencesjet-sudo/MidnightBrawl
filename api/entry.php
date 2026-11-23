<?php
declare(strict_types=1);

// Only three public routes are dispatched. No request value is used as a file path.
$routes=['page'=>'/','api'=>'/api.php','health'=>'/health'];
$route=$_GET['tf_route']??null;
if (!is_string($route) || !isset($routes[$route])) {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    http_response_code(404);
    echo json_encode(['error'=>'Not found.']);
    exit;
}
$_SERVER['REQUEST_URI']=$routes[$route];
unset($_GET['tf_route']);
require dirname(__DIR__).'/public/index.php';
