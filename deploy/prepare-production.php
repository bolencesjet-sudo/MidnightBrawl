<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/lib.php';
$displayName=envValue('TF_DEFAULT_ADMIN_DISPLAY_NAME');
if ($displayName!=='') {
    try {
        validateEnvironment(); $displayName=field(['name'=>$displayName],'name',80); $db=db();
        demand((int)query($db,"SELECT GET_LOCK('tfv1_mutation',10)")->fetchColumn()===1,'The system is busy.');
        $db->beginTransaction();
        $before=one($db,"SELECT * FROM users WHERE username=? AND email=? AND role='organizer' AND disabled_at IS NULL FOR UPDATE",['tfv1admin','tfv1-admin@example.invalid']);
        demand((bool)$before,'The requested default administrator was not found.');
        if ($before['name']!==$displayName) {
            query($db,'UPDATE users SET name=? WHERE id=?',[$displayName,$before['id']]);
            $after=one($db,'SELECT * FROM users WHERE id=?',[$before['id']]);
            $expected=$before; $expected['name']=$displayName;
            demand($after===$expected,'Administrator fields did not match the requested name-only update.');
            audit($db,null,(int)$before['id'],'Updated default administrator display name through explicit production preparation.');
        }
        $db->commit(); echo "Default administrator display name updated; credentials and permissions preserved.\n";
    } catch (Throwable $error) {
        if(isset($db)&&$db->inTransaction())$db->rollBack();
        fwrite(STDERR,"Default administrator display name update failed.\n"); exit(1);
    } finally {
        if(isset($db)){try{query($db,"SELECT RELEASE_LOCK('tfv1_mutation')");}catch(Throwable $ignored){}}
    }
    exit;
}
// Explicit, temporary build flag. Ordinary builds and HTTP requests do nothing.
if (envValue('TF_PREPARE_PRODUCTION')!=='1') { echo "Production database preparation is disabled.\n"; exit; }
try {
    validateEnvironment();
    $db=db();
    demand((int)query($db,"SELECT GET_LOCK('tfv1_schema_migration',30)")->fetchColumn()===1,'Migration is busy.');
    $tables=['users','events','players','teams','team_members','join_requests','matches','audit_log','rate_limits','email_verifications','password_resets','web_sessions'];
    $existing=query($db,'SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $missing=array_values(array_diff($tables,$existing));
    echo 'Missing application tables before migration: '.($missing?implode(', ',$missing):'none')."\n";
    $prefix='tfbackup_'.gmdate('Ymd_His').'_'.bin2hex(random_bytes(2)).'_';
    $snapshots=[];
    foreach ($tables as $table) if (in_array($table,$existing,true)) {
        $backup=$prefix.$table;
        $db->exec("CREATE TABLE `$backup` LIKE `$table`");
        $snapshots[$table]=$backup;
    }
    $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $db->beginTransaction();
    foreach ($snapshots as $table=>$backup) $db->exec("INSERT INTO `$backup` SELECT * FROM `$table`");
    $db->commit();
    echo 'Hosted database backup completed: '.$prefix."*\n";
    foreach (explode(';',file_get_contents(dirname(__DIR__).'/database/schema.sql')) as $statement) if (trim($statement)) $db->exec($statement);
    require dirname(__DIR__).'/database/migrate.php'; migrateSecurity($db);
    foreach ($snapshots as $table=>$backup) {
        if(in_array($table,['rate_limits','web_sessions','password_resets'],true))continue; // Live requests refresh these operational records.
        // Check every pre-existing record; added user security columns are excluded.
        $columns=array_column(rows($db,"SHOW COLUMNS FROM `$backup`"),'Field');
        $list=implode(',',array_map(fn($column)=>'`'.str_replace('`','``',$column).'`',$columns));
        $before=rows($db,"SELECT $list FROM `$backup`");
        $after=rows($db,"SELECT $list FROM `$table`");
        $normalize=static function(array $data): array { $data=array_map('serialize',$data);sort($data,SORT_STRING);return $data; };
        demand($normalize($before)===$normalize($after),'Existing data verification failed for '.$table.'.');
    }
    $account=one($db,'SELECT id FROM users WHERE username=? OR email=?',['tfv1admin','tfv1-admin@example.invalid']);
    if (!$account) {
        $db->beginTransaction();
        query($db,"INSERT INTO users(name,email,username,password_hash,role,email_verified_at) VALUES(?,?,?,?, 'organizer',NOW())",['Default administrator','tfv1-admin@example.invalid','tfv1admin',password_hash('TFv1-Admin-2026!',PASSWORD_DEFAULT)]);
        audit($db,null,(int)$db->lastInsertId(),'Created requested default administrator during explicit production preparation.');
        $db->commit();
        echo "Requested default administrator created with a hashed password.\n";
    } else echo "Existing default administrator identity preserved; no password overwritten.\n";
    echo "Production schema migration completed; existing application records verified unchanged.\n";
} catch (Throwable $error) {
    if(isset($db)&&$db->inTransaction())$db->rollBack();
    $category=$error instanceof PDOException?databaseFailureCode($error):'preparation_failed';
    fwrite(STDERR,'Production database preparation failed ('.$category."). No existing tables were dropped.\n");
    if($error instanceof DomainException)fwrite(STDERR,$error->getMessage()."\n");
    exit(1);
} finally {
    if(isset($db)){try{query($db,"SELECT RELEASE_LOCK('tfv1_schema_migration')");}catch(Throwable $ignored){}}
}
