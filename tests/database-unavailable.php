<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
// Force an unreachable local fixture, never the configured real database.
putenv('TF_ENV=development'); putenv('VERCEL');
putenv('TF_DB_HOST=127.0.0.1'); putenv('TF_DB_PORT=1');
putenv('TF_DB_NAME=unreachable_fixture'); putenv('TF_DB_USER=fixture_user');
putenv('TF_DB_PASSWORD=fixture_password');
$_SERVER['REQUEST_METHOD']='GET';
$log=tempnam(sys_get_temp_dir(),'tfv1-db-error-');
ini_set('error_log',$log);
try {
    ob_start(); require dirname(__DIR__).'/api.php'; $body=ob_get_clean();
    $result=json_decode($body,true,512,JSON_THROW_ON_ERROR);
    if (http_response_code()!==503 || ($result['code']??'')!=='database_connection' || ($result['database_unavailable']??null)!==true) throw new RuntimeException('An unavailable database must return a categorized 503.');
    if (!preg_match('/^[a-f0-9]{16}$/',$result['reference']??'')) throw new RuntimeException('Missing error reference.');
    $privateLog=file_get_contents($log);
    if (!str_contains($privateLog,$result['reference']) || !str_contains($privateLog,'stage=connection')) throw new RuntimeException('Private logs must correlate the reference and failing stage.');
    foreach (['fixture_user','fixture_password','127.0.0.1','SQLSTATE'] as $sensitive) if (str_contains($body,$sensitive)) throw new RuntimeException('Public response must not expose database internals.');
    echo "PASS: unreachable database returns safe 503 with a correlated private diagnostic, without exposing credentials.\n";
} finally { unlink($log); }
