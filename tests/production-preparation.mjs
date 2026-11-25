import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
import {randomBytes} from 'node:crypto';
const cwd=new URL('../',import.meta.url);
const env={...process.env,TF_ENV:'development',VERCEL:'',TF_PREPARE_PRODUCTION:'1',TF_DB_HOST:'127.0.0.1',TF_DB_PORT:process.env.TF_DB_PORT||'3306',TF_DB_NAME:'tfv1_test_'+randomBytes(6).toString('hex'),TF_DB_USER:'root',TF_DB_PASSWORD:''};
function php(args,overrides={}){const result=spawnSync('php',args,{cwd,env:{...env,...overrides},encoding:'utf8'});assert.equal(result.status,0,result.stderr);return result.stdout}
try{
 php(['install.php']);
 php(['-r',`require 'lib.php'; db()->exec('DROP TABLE email_verifications'); db()->exec('DROP TABLE password_resets');`]);
 assert.match(php(['deploy/prepare-production.php'],{TF_PREPARE_PRODUCTION:''}),/disabled/);
 const repaired=php(['deploy/prepare-production.php']);
 assert.match(repaired,/Missing application tables before migration: email_verifications, password_resets/);
 assert.match(repaired,/records verified unchanged/);
 assert.match(php(['-r',`require 'lib.php'; query(db(),'SELECT user_id,password_hash,expires_at FROM password_resets LIMIT 0'); echo 'recovery schema verified';`]),/recovery schema verified/);
 assert.match(php(['-r',`require 'lib.php'; $db=db(); query($db,'SELECT user_id FROM email_verifications LIMIT 0'); $u=one($db,"SELECT * FROM users WHERE username='tfv1admin'"); if(!$u||$u['role']!=='organizer'||!$u['email_verified_at']||!password_verify('TFv1-Admin-2026!',$u['password_hash']))exit(1); echo 'verified';`]),/verified/);
 php(['admin.php','--set-password'],{TF_ADMIN_EMAIL:'tfv1-admin@example.invalid',TF_ADMIN_PASSWORD:'Changed-initial-admin-password-42'});
 const originalAccount=php(['-r',`require 'lib.php'; echo json_encode(one(db(),"SELECT * FROM users WHERE username='tfv1admin'"));`]);
 assert.match(php(['deploy/prepare-production.php'],{TF_PREPARE_PRODUCTION:'',TF_DEFAULT_ADMIN_DISPLAY_NAME:'JetVerse_Admin'}),/credentials and permissions preserved/);
 const renamedAccount=php(['-r',`require 'lib.php'; echo json_encode(one(db(),"SELECT * FROM users WHERE username='tfv1admin'"));`]);
 assert.deepEqual(JSON.parse(renamedAccount),{...JSON.parse(originalAccount),name:'JetVerse_Admin'},'Rename must change only the display name.');
 assert.match(php(['deploy/prepare-production.php']),/no password overwritten/);
 php(['-r',`require 'lib.php'; $u=one(db(),"SELECT * FROM users WHERE username='tfv1admin'"); if(!password_verify('Changed-initial-admin-password-42',$u['password_hash']))exit(1);`]);
 const emailBefore=JSON.parse(php(['-r',`require 'lib.php'; echo json_encode(one(db(),"SELECT * FROM users WHERE username='tfv1admin'"));`]));
 assert.match(php(['deploy/prepare-production.php'],{TF_DEFAULT_ADMIN_EMAIL:'organizer-inbox@example.test'}),/password, username, name and permissions preserved/);
 const emailAfter=JSON.parse(php(['-r',`require 'lib.php'; echo json_encode(one(db(),"SELECT * FROM users WHERE username='tfv1admin'"));`]));
 assert.equal(emailAfter.email,'organizer-inbox@example.test');assert.equal(Number(emailAfter.auth_version),Number(emailBefore.auth_version)+1);
 for(const key of ['email','email_verified_at','auth_version']){delete emailBefore[key];delete emailAfter[key]}
 assert.deepEqual(emailAfter,emailBefore,'Private email preparation must preserve all other organizer fields.');
 assert.match(php(['deploy/prepare-production.php'],{TF_DEFAULT_ADMIN_EMAIL:'organizer-inbox@example.test'}),/account unchanged/);
 assert.match(php(['deploy/prepare-production.php'],{TF_PREPARE_PRODUCTION:'',TF_DEFAULT_ADMIN_DISPLAY_NAME:'Updated Organizer'}),/credentials and permissions preserved/);
 php(['-r',`require 'lib.php'; query(db(),"INSERT INTO users(name,email,password_hash,role,email_verified_at) VALUES('Existing Player','occupied@example.test',?,'player',NOW())",[password_hash('Separate-player-passphrase-72',PASSWORD_DEFAULT)]);`]);
 const beforeConflict=php(['-r',`require 'lib.php'; echo json_encode(rows(db(),'SELECT * FROM users ORDER BY id'));`]);
 const conflict=spawnSync('php',['deploy/prepare-production.php'],{cwd,env:{...env,TF_DEFAULT_ADMIN_EMAIL:'occupied@example.test'},encoding:'utf8'});
 assert.notEqual(conflict.status,0);assert.match(conflict.stderr,/belongs to another account/);
 assert.equal(php(['-r',`require 'lib.php'; echo json_encode(rows(db(),'SELECT * FROM users ORDER BY id'));`]),beforeConflict,'Duplicate email refusal must preserve every account.');
 console.log('PASS: opt-in production preparation repairs missing schema, backs up and verifies existing data, creates a verified admin and preserves changed passwords on repeat.');
}finally{php(['tests/database.php','drop'])}
