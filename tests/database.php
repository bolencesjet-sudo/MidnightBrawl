<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib.php';
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$c = require dirname(__DIR__) . '/config.php';
if (!preg_match('/^tfv1_test_[a-f0-9]{12}$/', $c['database'])) { fwrite(STDERR,"Refusing to touch a non-test database.\n"); exit(1); }
if (($argv[1]??'')==='drop') { db(false)->exec("DROP DATABASE IF EXISTS `{$c['database']}`"); echo "Isolated test database removed.\n"; }
