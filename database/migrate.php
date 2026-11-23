<?php
declare(strict_types=1);
// Called by the CLI installer only; existing accounts remain usable.
function migrateSecurity(PDO $db): void {
    $columns=array_column(rows($db,'SHOW COLUMNS FROM users'),'Field');
    foreach ([
        'username'=>'VARCHAR(80) NULL UNIQUE',
        'email_verified_at'=>'DATETIME NULL',
        'disabled_at'=>'DATETIME NULL',
        'auth_version'=>'INT UNSIGNED NOT NULL DEFAULT 1'
    ] as $name=>$type) {
        if (!in_array($name,$columns,true)) {
            $db->exec("ALTER TABLE users ADD COLUMN $name $type");
            // Grandfather existing users during migration; new signups must verify.
            if ($name==='email_verified_at') $db->exec('UPDATE users SET email_verified_at=NOW()');
        }
    }
}
