import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
import {randomBytes} from 'node:crypto';
const cwd=new URL('../',import.meta.url);
const env={...process.env,TF_ENV:'development',VERCEL:'',TF_PREPARE_PRODUCTION:'1',TF_DB_HOST:'127.0.0.1',TF_DB_PORT:'3306',TF_DB_NAME:'tfv1_test_'+randomBytes(6).toString('hex'),TF_DB_USER:'root',TF_DB_PASSWORD:''};
function php(args,overrides={}){const result=spawnSync('php',args,{cwd,env:{...env,...overrides},encoding:'utf8'});assert.equal(result.status,0,result.stderr);return result.stdout}
try{
 php(['install.php']);
 php(['-r',`require 'lib.php'; db()->exec('DROP TABLE email_verifications');`]);
 assert.match(php(['deploy/prepare-production.php'],{TF_PREPARE_PRODUCTION:''}),/disabled/);
 assert.match(php(['deploy/prepare-production.php']),/records verified unchanged/);
 assert.match(php(['-r',`require 'lib.php'; $db=db(); query($db,'SELECT user_id FROM email_verifications LIMIT 0'); $u=one($db,"SELECT * FROM users WHERE username='tfv1admin'"); if(!$u||$u['role']!=='organizer'||!$u['email_verified_at']||!password_verify('TFv1-Admin-2026!',$u['password_hash']))exit(1); echo 'verified';`]),/verified/);
 php(['admin.php','--set-password'],{TF_ADMIN_EMAIL:'tfv1-admin@example.invalid',TF_ADMIN_PASSWORD:'Changed-initial-admin-password-42'});
 const originalAccount=php(['-r',`require 'lib.php'; echo json_encode(one(db(),"SELECT * FROM users WHERE username='tfv1admin'"));`]);
 assert.match(php(['deploy/prepare-production.php'],{TF_PREPARE_PRODUCTION:'',TF_DEFAULT_ADMIN_DISPLAY_NAME:'JetVerse_Admin'}),/credentials and permissions preserved/);
 const renamedAccount=php(['-r',`require 'lib.php'; echo json_encode(one(db(),"SELECT * FROM users WHERE username='tfv1admin'"));`]);
 assert.deepEqual(JSON.parse(renamedAccount),{...JSON.parse(originalAccount),name:'JetVerse_Admin'},'Rename must change only the display name.');
 assert.match(php(['deploy/prepare-production.php']),/no password overwritten/);
 php(['-r',`require 'lib.php'; $u=one(db(),"SELECT * FROM users WHERE username='tfv1admin'"); if(!password_verify('Changed-initial-admin-password-42',$u['password_hash']))exit(1);`]);
 console.log('PASS: opt-in production preparation repairs missing schema, backs up and verifies existing data, creates a verified admin and preserves changed passwords on repeat.');
}finally{php(['tests/database.php','drop'])}
