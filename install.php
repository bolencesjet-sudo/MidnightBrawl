<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Run php install.php from the TFv1 directory.'); }
try {
    $c = require __DIR__ . '/config.php'; $db = db(false);
    $db->exec("CREATE DATABASE IF NOT EXISTS `{$c['database']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $db = db(); $sql = file_get_contents(__DIR__ . '/database/schema.sql');
    foreach (explode(';', $sql) as $statement) if (trim($statement)) $db->exec($statement);
    $db->beginTransaction(); seedDemo($db); $db->commit();
    echo "TFv1 database ready. Open http://localhost/TFv1/ and select Set up organizer.\n";
} catch (Throwable $e) { if (isset($db) && $db->inTransaction()) $db->rollBack(); fwrite(STDERR,"Installation failed: " . $e->getMessage() . "\nStart MySQL in XAMPP and check config.php.\n"); exit(1); }
