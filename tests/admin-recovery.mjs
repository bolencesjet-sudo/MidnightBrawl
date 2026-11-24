import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
const cwd=new URL('../',import.meta.url);
const env={...process.env,TF_ENV:'development',VERCEL:'',TF_DB_HOST:'127.0.0.1',TF_DB_PORT:'1',TF_DB_NAME:'unreachable_fixture',TF_DB_USER:'fixture_user',TF_DB_PASSWORD:'fixture_secret',TF_ADMIN_USERNAME:'',TF_ADMIN_EMAIL:'fixture@example.test',TF_ADMIN_PASSWORD:'short'};
function run(mode,overrides={}){
 const result=spawnSync('php',['admin.php',mode],{cwd,env:{...env,...overrides},encoding:'utf8'});
 assert.equal(result.status,1);
 assert.ok(!result.stderr.includes('fixture_secret')&&!result.stderr.includes('SQLSTATE'),'Errors must not disclose credentials or raw database exceptions.');
 return result.stderr;
}
assert.match(run('--set-password'),/Use a password of at least 15 characters/,'Password validation must precede database access and require no username.');
assert.match(run('--set-password',{TF_ADMIN_EMAIL:'invalid'}),/Set TF_ADMIN_EMAIL/);
assert.match(run('--unknown'),/Unknown command/);
assert.match(run('--set-password',{TF_ADMIN_PASSWORD:'Fixture-long-passphrase-42'}),/database_connection.*diagnose-database/,'An unreachable database must give actionable diagnostics.');
console.log('PASS: password-reset validation, email-only identification, safe database errors and invalid-command handling.');
