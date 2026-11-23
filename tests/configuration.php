<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/lib.php';
foreach(['TF_DB_HOST','TF_DB_NAME','TF_DB_USER','TF_DB_PASSWORD','MYSQL_ADDON_HOST','MYSQL_ADDON_DB','MYSQL_ADDON_USER','MYSQL_ADDON_PASSWORD','HOST','DB_NAME','USER','PASSWORD','VERCEL'] as $key)putenv($key);
putenv('TF_ENV=development');
putenv('USER=system-user');
$c=require dirname(__DIR__).'/config.php';
if($c['username']!=='root')throw new RuntimeException('An OS username must not become a database login.');
putenv('HOST=fixture.mysql.example');putenv('DB_NAME=fixture');putenv('USER=fixture-user');putenv('PASSWORD=fixture-only-password');
$c=require dirname(__DIR__).'/config.php';
if($c['host']!=='fixture.mysql.example'||$c['database']!=='fixture'||$c['username']!=='fixture-user'||$c['password']!=='fixture-only-password')throw new RuntimeException('Legacy database settings were not recognized.');
putenv('TF_DB_HOST=canonical.mysql.example');putenv('TF_DB_NAME=canonical');putenv('TF_DB_USER=canonical-user');putenv('TF_DB_PASSWORD=canonical-only-password');
$c=require dirname(__DIR__).'/config.php';
if($c['host']!=='canonical.mysql.example'||$c['database']!=='canonical'||$c['username']!=='canonical-user'||$c['password']!=='canonical-only-password')throw new RuntimeException('Canonical settings must take precedence.');
echo "PASS: legacy environment compatibility, canonical precedence and OS-user isolation.\n";
