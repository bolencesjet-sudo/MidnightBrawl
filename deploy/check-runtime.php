<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
if (PHP_VERSION_ID<80200) { fwrite(STDERR,"Deployment requires PHP 8.2 or newer.\n"); exit(1); }
foreach (['pdo_mysql','mbstring','openssl','session'] as $extension) {
    if (!extension_loaded($extension)) { fwrite(STDERR,"Missing PHP extension: $extension\n"); exit(1); }
}
echo "PHP deployment runtime and required extensions verified.\n";
