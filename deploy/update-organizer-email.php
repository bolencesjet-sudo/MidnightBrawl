<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/lib.php';
try {
    validateEnvironment();
    $email=strtolower(envValue('TF_DEFAULT_ADMIN_EMAIL'));
    demand((bool)filter_var($email,FILTER_VALIDATE_EMAIL),'Configure a valid organizer email.');
    $db=db();
    demand((int)query($db,"SELECT GET_LOCK('tfv1_mutation',10)")->fetchColumn()===1,'The system is busy.');
    $before=one($db,"SELECT * FROM users WHERE username='tfv1admin' AND role='organizer' AND disabled_at IS NULL");
    demand((bool)$before,'The existing default organizer was not found.');
    demand(!one($db,'SELECT id FROM users WHERE email=? AND id<>?',[$email,$before['id']]),'The requested email belongs to another account.');
    if ($before['email']!==$email) {
        $backup='tfbackup_email_'.gmdate('Ymd_His').'_'.bin2hex(random_bytes(3));
        $db->exec("CREATE TABLE `$backup` LIKE users");
        $db->exec("INSERT INTO `$backup` SELECT * FROM users");
        $db->beginTransaction();
        query($db,'UPDATE users SET email=?,email_verified_at=NOW(),auth_version=auth_version+1 WHERE id=?',[$email,$before['id']]);
        $after=one($db,'SELECT * FROM users WHERE id=?',[$before['id']]);
        demand($after['email']===$email && (int)$after['auth_version']===(int)$before['auth_version']+1,'Organizer email update did not match.');
        foreach (['email','email_verified_at','auth_version'] as $field) { unset($before[$field],$after[$field]); }
        demand($after===$before,'Organizer credentials or permissions changed unexpectedly.');
        audit($db,null,(int)$before['id'],'Updated organizer email through explicit owner-authorized private preparation; previous sessions revoked.');
        $db->commit();
        echo "Organizer email updated with account backup; password, username, name and permissions preserved.\n";
    } else echo "Organizer already uses the requested email; account unchanged.\n";
} catch (Throwable $error) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    fwrite(STDERR,"Organizer email preparation failed. Check the target account and email uniqueness.\n");
    if ($error instanceof DomainException) fwrite(STDERR,$error->getMessage()."\n");
    elseif ($error instanceof PDOException) fwrite(STDERR,'Database category: '.databaseFailureCode($error)."\n");
    exit(1);
} finally {
    if (isset($db)) { try { query($db,"SELECT RELEASE_LOCK('tfv1_mutation')"); } catch (Throwable $ignored) {} }
}
