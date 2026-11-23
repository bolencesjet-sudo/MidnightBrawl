<?php
// Local test adapter for Caddy's explicit asset allowlist and front controller.
declare(strict_types=1);
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
$types=['/assets/style.css'=>'text/css','/assets/app.js'=>'application/javascript','/assets/theme.js'=>'application/javascript','/assets/logo.svg'=>'image/svg+xml'];
if (isset($types[$path])) {
    header('Content-Type: '.$types[$path]);
    readfile(dirname(__DIR__).$path);
    return;
}
require dirname(__DIR__).'/public/index.php';
