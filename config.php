<?php
// XAMPP defaults apply only in development. Clever Cloud aliases are supported.
$local=!production();
$legacy=envValue('HOST')!=='' && envValue('DB_NAME')!=='' && envValue('USER')!=='' && envValue('PASSWORD')!=='';
return [
    'host' => envValue('TF_DB_HOST',$local?'127.0.0.1':'',array_merge(['MYSQL_ADDON_HOST'],$legacy?['HOST']:[])),
    'port' => envValue('TF_DB_PORT','3306',['MYSQL_ADDON_PORT']),
    'database' => envValue('TF_DB_NAME',$local?'tfv1':'',array_merge(['MYSQL_ADDON_DB'],$legacy?['DB_NAME']:[])),
    'username' => envValue('TF_DB_USER',$local?'root':'',array_merge(['MYSQL_ADDON_USER'],$legacy?['USER']:[])),
    'password' => envValue('TF_DB_PASSWORD','',array_merge(['MYSQL_ADDON_PASSWORD'],$legacy?['PASSWORD']:[])),
];
