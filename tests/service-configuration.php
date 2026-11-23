<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
// An incomplete hosted setup must fail closed without blaming player input.
putenv('TF_ENV=production');
putenv('TF_APP_KEY');
putenv('TF_APP_URL');
$_SERVER['REQUEST_METHOD']='GET';
ob_start();
require dirname(__DIR__).'/api.php';
$body=ob_get_clean();
$response=json_decode($body,true,512,JSON_THROW_ON_ERROR);
if (http_response_code()!==503 || ($response['code']??'')!=='server_configuration') throw new RuntimeException('Configuration failures must return a specific service-unavailable response.');
if (str_contains($body,'TF_APP_KEY') || str_contains($body,'password') || str_contains($body,'Check your input')) throw new RuntimeException('Public errors must not expose configuration details or blame player input.');
echo "PASS: incomplete production configuration fails closed with a safe 503 response.\n";
