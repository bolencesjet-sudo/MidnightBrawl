<?php
// XAMPP defaults apply only in development. Clever Cloud aliases are supported.
$local=!production();
return [
    'host' => envValue('TF_DB_HOST',$local?'127.0.0.1':'',['MYSQL_ADDON_HOST']),
    'port' => envValue('TF_DB_PORT','3306',['MYSQL_ADDON_PORT']),
    'database' => envValue('TF_DB_NAME',$local?'tfv1':'',['MYSQL_ADDON_DB']),
    'username' => envValue('TF_DB_USER',$local?'root':'',['MYSQL_ADDON_USER']),
    'password' => envValue('TF_DB_PASSWORD','',['MYSQL_ADDON_PASSWORD']),
];
