import {spawn,spawnSync} from 'node:child_process';
import {randomBytes} from 'node:crypto';
import {fileURLToPath} from 'node:url';
import path from 'node:path';
import assert from 'node:assert/strict';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const env={...process.env,TF_ENV:'development',VERCEL:'',TF_DB_NAME:'tfv1_test_'+randomBytes(6).toString('hex'),TF_SESSION_STORE:'database',TF_ADMIN_USERNAME:'deploy-admin',TF_ADMIN_EMAIL:'deploy@example.test',TF_ADMIN_PASSWORD:'Deployment-test-passphrase-42'};
const ports=[21000+Math.floor(Math.random()*1000),23000+Math.floor(Math.random()*1000)];
const servers=[];let checks=0;
const adapter=process.argv.includes('--vercel')?'tests/vercel-router.php':'tests/container-router.php';
const check=(value,message)=>{assert.ok(value,message);checks++};
async function start(port){
 const p=spawn('php',['-S',`127.0.0.1:${port}`,'-t',root,adapter],{cwd:root,env,stdio:'ignore'});servers.push(p);
 for(let i=0;i<80;i++){try{const r=await fetch(`http://127.0.0.1:${port}/health`);await r.text();if(r.ok)return p}catch{}await new Promise(r=>setTimeout(r,50))}
 throw Error('Test server did not start.');
}
async function stop(p){if(p.exitCode===null&&p.signalCode===null){const exited=new Promise(r=>p.once('exit',r));p.kill();await exited}}
try{
 let r=spawnSync('php',['install.php'],{cwd:root,env,encoding:'utf8'});assert.equal(r.status,0,r.stderr);
 r=spawnSync('php',['migrate.php'],{cwd:root,env,encoding:'utf8'});assert.equal(r.status,0,r.stderr);checks++;
 r=spawnSync('php',['admin.php'],{cwd:root,env,encoding:'utf8'});assert.equal(r.status,0,r.stderr);
 let first=await start(ports[0]);await start(ports[1]);
 const bases=ports.map(p=>`http://127.0.0.1:${p}`);
 for(const suffix of ['/','/index.php']){
  const res=await fetch(bases[0]+suffix),body=await res.text();
  check(res.status===200&&res.headers.get('content-type').includes('text/html'),'Page must be rendered as HTML.');
  check(body.includes('<!doctype html>')&&!body.includes('<?php'),'PHP source must not be returned.');
 }
 for(const suffix of ['/config.php','/lib.php','/security.php','/sessions.php','/admin.php','/install.php','/composer.lock','/.env','/.git/config','/database/schema.sql','/TFv1.session.sql','/runtime/backup.sql','/tests/integration.mjs','/vendor/autoload.php','/public/index.php','/api/entry.php','/api/entry.php?tf_route=api','/missing']){
  const res=await fetch(bases[0]+suffix),body=await res.text();check(res.status===404&&!body.includes('<?php'),`${suffix} must never serve a source file.`);
 }
 for(const suffix of ['/assets/style.css','/assets/app.js','/assets/theme.js','/assets/logo.svg']){const res=await fetch(bases[0]+suffix);await res.text();check(res.status===200,`${suffix} must load.`)}
 let response=await fetch(bases[0]+'/api.php'),state=await response.json();let cookie=response.headers.getSetCookie().find(c=>c.startsWith('PHPSESSID=')).split(';')[0];
 response=await fetch(bases[0]+'/api.php',{method:'POST',headers:{Cookie:cookie,'Content-Type':'application/json','X-CSRF-Token':state.csrf},body:JSON.stringify({action:'admin_login',username:env.TF_ADMIN_USERNAME,password:env.TF_ADMIN_PASSWORD})});
 state=(await response.json()).state;cookie=response.headers.getSetCookie().find(c=>c.startsWith('PHPSESSID=')).split(';')[0];check(state.user.role==='organizer','Admin must sign in.');
 response=await fetch(bases[1]+'/api.php',{headers:{Cookie:cookie}});let second=await response.json();check(second.user?.role==='organizer'&&second.csrf===state.csrf,'Session and CSRF must be shared across instances.');
 await stop(first);first=await start(ports[0]);
 response=await fetch(bases[0]+'/api.php',{headers:{Cookie:cookie}});state=await response.json();check(state.user?.role==='organizer','Login must survive a server restart.');
 response=await fetch(bases[1]+'/api.php',{method:'POST',headers:{Cookie:cookie,'Content-Type':'application/json','X-CSRF-Token':state.csrf},body:JSON.stringify({action:'logout'})});await response.text();
 response=await fetch(bases[0]+'/api.php',{headers:{Cookie:cookie}});state=await response.json();check(!state.user,'Logout on one instance must invalidate the session on the other.');
 response=await fetch(bases[0]+'/api.php',{headers:{Cookie:'PHPSESSID=attacker-selected-session'}});await response.text();check(response.headers.getSetCookie().some(c=>c.startsWith('PHPSESSID=')&&!c.startsWith('PHPSESSID=attacker-selected-session')),'Strict sessions must reject an unknown session ID.');
 console.log(`PASS: ${checks} deployment assertions (${adapter}) — rendered HTML, private-source denials, assets, shared login/CSRF, restart persistence, logout and session fixation protection.`);
}finally{
 for(const p of servers)await stop(p);
 const r=spawnSync('php',['tests/database.php','drop'],{cwd:root,env,encoding:'utf8'});assert.equal(r.status,0,r.stderr);console.log(r.stdout.trim());
}
