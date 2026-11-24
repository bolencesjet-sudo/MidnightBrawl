<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/lib.php';

// Run privately with the SAME environment as the PHP host. No DDL, writes,
// account data, passwords, connection strings or verification codes are printed.
$stage='environment';
try {
    validateEnvironment();
    $stage='connection'; $connection=db();
    echo "PASS: database connection and configured transport.\n";
    $checks=[
        'users'=>'id,name,email,password_hash,role,username,email_verified_at,disabled_at,auth_version',
        'rate_limits'=>'bucket_key,window_start,hits,expires_at',
        'email_verifications'=>'user_id,code_hash,expires_at',
        'web_sessions'=>'id_hash,payload,expires_at',
        'events'=>'id,name,roles,locked,demo',
        'players'=>'id,event_id,user_id,display_name,game_id',
        'teams'=>'id,event_id,name,captain_id',
        'team_members'=>'team_id,player_id',
        'join_requests'=>'id,team_id,player_id',
        'matches'=>'id,event_id,status',
        'audit_log'=>'id,event_id,user_id,action',
    ];
    foreach ($checks as $table=>$columns) {
        $stage='schema:'.$table;
        $connection->query('SELECT '.$columns.' FROM '.$table.' LIMIT 0');
        echo 'PASS: '.$table." schema is readable.\n";
    }
    echo "Database/schema checks passed. This does not test write privileges or SMTP delivery.\n";
} catch (PDOException $error) {
    $code=databaseFailureCode($error);
    $advice=[
        'database_connection'=>'Check database service status, hostname, port and hosting network access.',
        'database_busy'=>'Check provider connection limits and active connections.',
        'database_permissions'=>'Check the configured database credentials and required privileges.',
        'database_schema'=>'Run php migrate.php privately with migration privileges against this existing database.',
        'database_name'=>'Check that TF_DB_NAME matches the provider database name.',
        'database_tls'=>'Check the provider TLS configuration and any configured CA certificate.',
    ];
    fwrite(STDERR,'FAIL: '.$stage.' / '.$code.' / driver '.(int)($error->errorInfo[1]??0)."\n".($advice[$code]??'Inspect the private PHP database error log.')."\n");
    exit(1);
} catch (Throwable $error) {
    fwrite(STDERR,'FAIL: '.$stage.". Check the PHP runtime, required environment settings and transport configuration.\n");
    exit(1);
}
