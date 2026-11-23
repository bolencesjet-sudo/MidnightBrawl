<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/lib.php';
try {
    // Exercise production transport directly, including on the PHP 8.0 dev host.
    // The separate production PHP version requirement remains in place.
    if (PHP_VERSION_ID>=80200) validateEnvironment();
    $connection=db();
    echo "CONNECTED\n";
} catch (Throwable $e) {
    fwrite(STDERR,$e->getMessage()."\n");
    exit(1);
}
