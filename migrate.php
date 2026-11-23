<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require __DIR__.'/lib.php';
try {
    validateEnvironment(); $db=db();
    demand((int)query($db,"SELECT GET_LOCK('tfv1_schema_migration',30)")->fetchColumn()===1,'Migration is busy.');
    foreach(explode(';',file_get_contents(__DIR__.'/database/schema.sql')) as $statement) if(trim($statement)) $db->exec($statement);
    require __DIR__.'/database/migrate.php'; migrateSecurity($db);
    echo "Schema updated in the existing database. Tournament data preserved; no sample events added.\n";
} catch(Throwable $e) {
    fwrite(STDERR,"Migration failed. Check the configured database, TLS and migration-user schema privileges.\n"); exit(1);
} finally {
    if(isset($db)) { try { query($db,"SELECT RELEASE_LOCK('tfv1_schema_migration')"); } catch(Throwable $ignored) {} }
}
