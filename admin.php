<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require __DIR__.'/lib.php';
try {
    validateEnvironment();
    $mode=$argv[1]??'';
    demand(in_array($mode,['','--assign-username','--set-password','--create-default'],true),'Unknown command.');
    $defaultAdmin=$mode==='--create-default';
    $username=envValue('TF_ADMIN_USERNAME'); $email=strtolower(envValue('TF_ADMIN_EMAIL')); $password=envValue('TF_ADMIN_PASSWORD');
    if ($defaultAdmin) {
        $username='tfv1admin'; $email='tfv1-admin@example.invalid'; $password='TFv1-Admin-2026!';
        $mode='';
    }
    if ($mode!=='--set-password') demand((bool)preg_match('/^[a-zA-Z0-9_.-]{3,80}$/',$username),'Set TF_ADMIN_USERNAME (3–80 letters, digits, dots, underscores or hyphens).');
    demand((bool)filter_var($email,FILTER_VALIDATE_EMAIL),'Set TF_ADMIN_EMAIL.');
    if ($mode!=='--assign-username') strongPassword($password);
    $db=db(); $db->beginTransaction();
    if ($mode==='--assign-username' || $mode==='--set-password') {
        $account=one($db,'SELECT id,username FROM users WHERE email=? AND role=\'organizer\' AND disabled_at IS NULL FOR UPDATE',[$email]);
        demand((bool)$account,'Existing enabled admin not found.');
        if ($mode==='--assign-username') {
            demand(empty($account['username']),'This admin already has a username.');
            query($db,'UPDATE users SET username=? WHERE id=?',[$username,$account['id']]);
            audit($db,null,(int)$account['id'],'Assigned admin username through CLI; existing password preserved.');
            $db->commit(); echo "Admin username assigned. Existing password preserved.\n";
        } else {
            query($db,'UPDATE users SET password_hash=?,auth_version=auth_version+1 WHERE id=?',[password_hash($password,PASSWORD_DEFAULT),$account['id']]);
            audit($db,null,(int)$account['id'],'Reset administrator password through CLI; previous sessions revoked.');
            $db->commit(); echo "Admin password updated. Previous sessions revoked. Remove TF_ADMIN_PASSWORD from the environment.\n";
        }
        exit;
    }
    query($db,'INSERT INTO users(name,email,username,password_hash,role,email_verified_at) VALUES(?,?,?,?,\'organizer\',NOW())',[$defaultAdmin?'Default administrator':envValue('TF_ADMIN_NAME','Administrator'),$email,$username,password_hash($password,PASSWORD_DEFAULT)]);
    audit($db,null,(int)$db->lastInsertId(),'Created dedicated administrator through CLI.');
    $db->commit();
    echo "Administrator created. Use Admin sign in. Remove TF_ADMIN_PASSWORD from the environment now.\n";
    if ($defaultAdmin) echo "Username: $username\nPassword: $password\nReset email identifier: $email\nChange this initial password after setup using the private CLI reset.\n";
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    $message='Administrator operation failed. Check the PHP runtime and database setup.';
    if ($e instanceof DomainException || $e instanceof ServiceConfigurationException) $message=$e->getMessage();
    elseif ($e instanceof PDOException) {
        $code=databaseFailureCode($e);
        $message='Database operation failed ('.$code.'). Run php deploy/diagnose-database.php with the same database settings.';
        if ((int)($e->errorInfo[1]??0)===1062) $message='An account with that email or username already exists. Use --set-password to reset an existing enabled organizer.';
    }
    fwrite(STDERR,$message."\n"); exit(1);
}
