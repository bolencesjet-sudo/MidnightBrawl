import {spawn,spawnSync} from 'node:child_process';
import {randomBytes} from 'node:crypto';
import {fileURLToPath} from 'node:url';
import path from 'node:path';
import assert from 'node:assert/strict';
import {startTestSMTP} from './smtp.mjs';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const database='tfv1_test_'+randomBytes(6).toString('hex');
const smtp=await startTestSMTP(root);
const env={...process.env,TF_ENV:'development',VERCEL:'',TF_DB_NAME:database,TF_SESSION_STORE:'database',...smtp.env};
const port=19000+Math.floor(Math.random()*1000),base=`http://127.0.0.1:${port}/api.php`;
env.TF_APP_URL=`${new URL(base).origin}/TFv1/`;
let server,checks=0;
const check=(value,message)=>{assert.ok(value,message);checks++};
class Client{
 cookie='';csrf='';state=null;
 async request(data,expected=200,origin=new URL(base).origin){
  const res=await fetch(base,{method:data?'POST':'GET',headers:{Cookie:this.cookie,...(data?{'Content-Type':'application/json','X-CSRF-Token':this.csrf,Origin:origin}:{})},body:data?JSON.stringify(data):undefined});
  for(const cookie of res.headers.getSetCookie()) if(cookie.startsWith('PHPSESSID='))this.cookie=cookie.split(';')[0];
  const raw=await res.text();let json;try{json=JSON.parse(raw)}catch{throw Error(`Invalid API JSON (${res.status}): ${raw.slice(0,2000)}`)}assert.equal(res.status,expected,json.error||`Unexpected status for ${data?.action||"state"}`);checks++;
  if(res.ok){this.state=json.state||json;this.csrf=this.state.csrf}return json;
 }
 async act(action,data={},expected=200){
  const result=await this.request({action,...(['set_role','set_disabled','delete_event'].includes(action)?{admin_password:'TFv1-test-password-42'}:{}),...data},expected);
  if(expected===200&&['login','admin_login'].includes(action)&&this.state.loginVerificationRequired){
   check(this.state.user===null,'Password alone must never authenticate.');
   return this.request({action:'verify_login',code:smtp.code(smtp.messages.at(-1).recipient)});
  }
  return result;
 }
 async signup(name,setup=false){await this.request();const email=`${name.toLowerCase()}@example.test`;await this.act(setup?'setup':'signup',{name,email,password:'TFv1-test-password-42',fair_play:true});if(!setup){check(this.state.user===null&&this.state.verificationRequired,'Signup must not authenticate before email verification.');await this.act('verify_email',{code:smtp.code(email)})}return this}
 event(id){return this.state.events.find(e=>Number(e.id)===Number(id))}
}
try{
 const installed=spawnSync('php',['install.php'],{cwd:root,env,encoding:'utf8'});assert.equal(installed.status,0,installed.stderr);
 // PHP buffers larger request bodies in upload_tmp_dir before executing the API.
 // Keep those temporary files inside the writable test workspace.
 server=spawn('php',['-d',`openssl.cafile="${smtp.ca}"`,'-d',`upload_tmp_dir=${path.join(root,'runtime')}`,'-S',`127.0.0.1:${port}`,'-t',root,...(process.argv.includes('--vercel')?['tests/vercel-router.php']:[])],{cwd:root,env,stdio:'ignore'});
 for(let i=0;i<60;i++){try{await (await fetch(base)).text();break}catch{await new Promise(r=>setTimeout(r,100))}}
 const admin=await new Client().signup('Organizer',true),ref=await new Client().signup('Official');
 const rejectedOrigin=await admin.request({action:'logout'},403,'https://foreign.example.test');
 check(rejectedOrigin.error.startsWith('Origin is not allowed.'),'Foreign origins must be rejected even with a valid CSRF token.');
 await admin.request();check(admin.state.user?.role==='organizer','Rejected origin must preserve the signed-in session.');
 const cli=spawnSync('php',['admin.php'],{cwd:root,env:{...env,TF_ADMIN_USERNAME:'test-administrator',TF_ADMIN_EMAIL:'cli-admin@example.test',TF_ADMIN_PASSWORD:'TFv1-test-password-42'},encoding:'utf8'});
 check(cli.status===0,'CLI must create a separate administrator.');
 const cliAdmin=new Client();await cliAdmin.request();await cliAdmin.act('admin_login',{username:'test-administrator',password:'TFv1-test-password-42'});
 check(cliAdmin.state.user.role==='organizer','CLI administrator must sign in with their username.');
 const defaultAdmin=spawnSync('php',['admin.php','--create-default'],{cwd:root,env,encoding:'utf8'});
 check(defaultAdmin.status===0,'Explicit default-admin creation must succeed.');
 const defaultClient=new Client();await defaultClient.request();await defaultClient.act('admin_login',{username:'tfv1admin',password:'TFv1-Admin-2026!'});
 check(defaultClient.state.user.role==='organizer','Default admin must use normal authenticated organizer access.');
 const repeatedDefault=spawnSync('php',['admin.php','--create-default'],{cwd:root,env,encoding:'utf8'});
 check(repeatedDefault.status!==0,'Repeated bootstrap must never overwrite an existing account.');
 await defaultClient.act('logout');await defaultClient.act('admin_login',{username:'tfv1admin',password:'TFv1-Admin-2026!'});
 const assigned=spawnSync('php',['admin.php','--assign-username'],{cwd:root,env:{...env,TF_ADMIN_USERNAME:'original-organizer',TF_ADMIN_EMAIL:'organizer@example.test'},encoding:'utf8'});
 check(assigned.status===0,'CLI must assign a username without changing an existing admin password.');
 const resetAdmin=spawnSync('php',['admin.php','--set-password'],{cwd:root,env:{...env,TF_ADMIN_USERNAME:'',TF_ADMIN_EMAIL:'cli-admin@example.test',TF_ADMIN_PASSWORD:'Changed-test-passphrase-42'},encoding:'utf8'});
 check(resetAdmin.status===0,'CLI password reset must succeed for an existing admin.');
 await cliAdmin.request();check(cliAdmin.state.user===null,'CLI password reset must revoke previous sessions.');
 await cliAdmin.act('admin_login',{username:'test-administrator',password:'Changed-test-passphrase-42'});
 const players=[];for(let i=0;i<6;i++)players.push(await new Client().signup(`Player${i+1}`));
 await admin.request();await admin.act('set_role',{user_id:ref.state.user.id,role:'referee'});await ref.request();
 const create=async(formation='random',format='single',size=3,race=2)=>{
  const deadline=new Date(Date.now()+86400000).toISOString().slice(0,16);
  const result=await admin.act('create_event',{name:`Test ${formation} ${format} ${size} ${race}`,game:'Test game',team_size:size,max_teams:6,formation,format,race_to:race,deadline,roles:'Roam, Jungle, Mid'});return Number(result.created_event_id);
 };
 const register=async(eid)=>{for(let i=0;i<players.length;i++){const c=players[i];await c.act('register_player',{event_id:eid,display_name:`Player ${i+1}`,game_id:`ID-${i+1}`,primary_role:'Mid',secondary_role:'',fair_play:true});const p=c.event(eid).players.find(p=>Number(p.user_id)===Number(c.state.user.id));await c.act('checkin',{event_id:eid,player_id:p.id})}};
 const eid=await create();await register(eid);
 await players[0].act('shuffle',{event_id:eid},403);
 await admin.act('shuffle',{event_id:eid});let e=admin.event(eid);check(e.teams.length===2&&e.players.every(p=>p.team_id),'Shuffle should form exactly full teams.');
 await admin.act('schedule',{event_id:eid},422);await admin.act('lock',{event_id:eid});await admin.act('shuffle',{event_id:eid},422);await admin.act('schedule',{event_id:eid});await admin.act('schedule',{event_id:eid},422);
 e=admin.event(eid);check(e.matches.length===1,'Two teams need one match.');const mid=e.matches[0].id;
 await ref.act('submit_score',{event_id:eid,match_id:mid,score_a:2,score_b:2},422);await ref.act('submit_score',{event_id:eid,match_id:mid,score_a:1,score_b:0},422);
 await ref.act('submit_score',{event_id:eid,match_id:mid,score_a:2,score_b:1});check(ref.event(eid).standings.every(t=>t.points===0),'Pending scores must not count.');
 await ref.act('confirm_score',{event_id:eid,match_id:mid},422);await players[0].act('confirm_score',{event_id:eid,match_id:mid},403);await admin.act('confirm_score',{event_id:eid,match_id:mid});check(admin.event(eid).standings[0].points===1,'Independent confirmation should update standings.');
 await admin.act('return_score',{event_id:eid,match_id:mid,reason:'Score evidence corrected.'});check(admin.event(eid).standings.every(t=>t.points===0),'Returned results must stop counting.');
 await admin.act('submit_score',{event_id:eid,match_id:mid,score_a:0,score_b:2});await ref.act('confirm_score',{event_id:eid,match_id:mid});
 // A participant with referee permissions must not verify their own team match.
 await admin.act('return_score',{event_id:eid,match_id:mid,reason:'Participant permission test.'});await admin.act('submit_score',{event_id:eid,match_id:mid,score_a:2,score_b:0});
 await admin.act('set_role',{user_id:players[0].state.user.id,role:'referee'});await players[0].act('confirm_score',{event_id:eid,match_id:mid},422);await ref.act('confirm_score',{event_id:eid,match_id:mid});
 await admin.act('set_role',{user_id:players[0].state.user.id,role:'player'});
 await admin.act('reset',{event_id:eid,confirmation:'incorrect',reason:'Test'},422);await admin.act('reset',{event_id:eid,confirmation:admin.event(eid).name,reason:'Integration reset verification.'});e=admin.event(eid);check(e.players.length===6&&!e.matches.length&&!e.teams.length&&!Number(e.locked),'Reset must preserve registration only.');check(e.audit.some(a=>a.action.startsWith('RESET:')),'Reset must be audited.');
 // Free teams: independent approval and duplicate membership protection.
 const free=await create('free');await register(free);await players[0].act('create_team',{event_id:free,name:'Alpha',captain_id:players[0].event(free).players[0].id});await players[3].act('create_team',{event_id:free,name:'Beta',captain_id:players[3].event(free).players[3].id});await admin.request();
 const ft=admin.event(free).teams;
 for(const [index,ti] of [[1,0],[2,0],[4,1],[5,1]]){
  await players[index].act('join_team',{event_id:free,team_id:ft[ti].id});await admin.request();const req=admin.event(free).requests.find(r=>Number(r.player_id)===Number(admin.event(free).players[index].id));await players[ti===0?0:3].act('review_request',{event_id:free,request_id:req.id,approve:true});
 }
 await admin.act('lock',{event_id:free});await admin.act('schedule',{event_id:free});check(admin.event(free).matches.length===1,'Free-team tournament should schedule.');
 // Captain mode: three-team snake picks must go A, B, C, C, B, A.
 const draft=await create('captain','double',3);await register(draft);await admin.request();const dp=admin.event(draft).players;
 for(let i=0;i<2;i++)await players[i*3].act('create_team',{event_id:draft,name:`Draft ${i}`,captain_id:dp[i*3].id},403);
 for(let i=0;i<2;i++)await admin.act('create_team',{event_id:draft,name:`Draft ${i}`,captain_id:dp[i*3].id});
 await players[3].act('draft_pick',{event_id:draft,player_id:dp[1].id},403);
 for(const [picker,pi] of [[0,1],[3,4],[3,5],[0,2]])await players[picker].act('draft_pick',{event_id:draft,player_id:dp[pi].id});
 await admin.act('lock',{event_id:draft});await admin.act('schedule',{event_id:draft});check(admin.event(draft).matches.length===2,'Double round robin should create both legs.');
 // Odd teams and every race target.
 for(let race=1;race<=5;race++){
  const id=await create('random','single',2,race);await register(id);await admin.act('shuffle',{event_id:id});await admin.act('lock',{event_id:id});await admin.act('schedule',{event_id:id});const ev=admin.event(id);check(ev.matches.length===3&&new Set(ev.matches.map(m=>m.round_no)).size===3,'Three teams need three rounds with byes.');
  await ref.act('submit_score',{event_id:id,match_id:ev.matches[0].id,score_a:race,score_b:race-1});await admin.act('confirm_score',{event_id:id,match_id:ev.matches[0].id});
 }
 const anon=new Client();await anon.request();await anon.act('reset',{event_id:eid,confirmation:'x',reason:'x'},401);
 const csrf=admin.csrf;admin.csrf='invalid';await admin.act('lock',{event_id:eid},403);admin.csrf=csrf;
 // Security regressions: role separation, input limits, sensitive actions and sessions.
 await players[0].act('edit_event',{event_id:eid,name:'Forbidden',game:'Test',deadline:'2030-01-01T12:00'},403);
 await players[0].act('delete_event',{event_id:eid},403);
 await players[0].act('set_role',{user_id:players[1].state.user.id,role:'organizer'},403);
 await admin.act('register_player',{event_id:eid},403);
 await admin.act('set_disabled',{user_id:admin.state.user.id,disabled:true},422);
 await admin.act('set_disabled',{user_id:players[1].state.user.id,disabled:true});
 await players[1].act('register_player',{event_id:eid},401);
 await admin.act('set_disabled',{user_id:players[1].state?.user?.id||admin.state.users.find(u=>u.email==='player2@example.test').id,disabled:false});
 const target=await create('random','single',1);
 await admin.act('edit_event',{event_id:target,name:'Edited <script>alert(1)</script>',game:'Edited game',deadline:'2030-01-01T12:00'});
 check(admin.event(target).game==='Edited game','Admin event edit must persist.');
 await admin.act('delete_event',{event_id:target,confirmation:admin.event(target).name,reason:'Test deletion',admin_password:'wrong'},403);
 await admin.act('delete_event',{event_id:target,confirmation:'wrong',reason:'Test deletion'},422);
 await admin.act('delete_event',{event_id:target,confirmation:admin.event(target).name,reason:'Test deletion'});
 check(!admin.event(target),'Deleted event must disappear.');
 const outsider=new Client();await outsider.request();
 await outsider.act('login',{email:'organizer@example.test',password:'TFv1-test-password-42'},401);
 await outsider.act('admin_login',{username:'organizer@example.test',password:'TFv1-test-password-42'});
 await outsider.act('logout');check(outsider.state.user===null,'Logout must clear authenticated session.');
 const weak=new Client();await weak.request();await weak.act('signup',{name:'Weak',email:'weak@example.test',password:'short',fair_play:true},422);
 const pendingUser=new Client();await pendingUser.request();
 await pendingUser.act('signup',{name:'Verification Test',email:'verify@example.test',password:'TFv1-test-password-42',fair_play:true});
 const originalCode=smtp.code('verify@example.test');
 await pendingUser.act('register_player',{event_id:eid},401);
 await pendingUser.act('verify_email',{code:'000000'},422);
 await pendingUser.act('resend_verification');
 await pendingUser.act('resend_verification',{},429);
 const replacementCode=smtp.code('verify@example.test');
 if(originalCode!==replacementCode)await pendingUser.act('verify_email',{code:originalCode},422);
 await pendingUser.act('verify_email',{code:replacementCode});
 await pendingUser.act('verify_email',{code:replacementCode},401);
 const invalid=await fetch(base,{method:'POST',headers:{Cookie:pendingUser.cookie,'Content-Type':'application/json','X-CSRF-Token':pendingUser.csrf},body:'{"action":'});await invalid.text();check(invalid.status===400,'Malformed JSON must return 400.');
 const tooLarge=await fetch(base,{method:'POST',headers:{Cookie:pendingUser.cookie,'Content-Type':'application/json','X-CSRF-Token':pendingUser.csrf},body:JSON.stringify({action:'login',padding:'x'.repeat(17000)})});const tooLargeBody=await tooLarge.text();check(tooLarge.status===413,`Oversized JSON must return 413 (received ${tooLarge.status}: ${tooLargeBody}).`);
 await pendingUser.act('login',{email:'verify@example.test',password:[]},422);
 // Every password login requires an expiring, session-bound, single-use email code.
 const gate=new Client();await gate.request();
 await gate.request({action:'admin_login',username:'test-administrator',password:'Changed-test-passphrase-42'});
 check(gate.state.user===null&&gate.state.loginVerificationRequired,'Organizer must remain anonymous until their email code is verified.');
 const firstLoginCode=smtp.code('cli-admin@example.test');
 const stolen=new Client();await stolen.request();await stolen.act('verify_login',{code:firstLoginCode},401);
 await gate.act('verify_login',{code:firstLoginCode==='000000'?'111111':'000000'},422);
 await gate.act('create_event',{},401);
 await gate.act('resend_login');await gate.act('resend_login',{},429);
 const newLoginCode=smtp.code('cli-admin@example.test');
 if(firstLoginCode!==newLoginCode)await gate.act('verify_login',{code:firstLoginCode},422);
 await gate.act('verify_login',{code:newLoginCode});
 check(gate.state.user.role==='organizer','Correct email code must finish organizer login.');
 await gate.act('verify_login',{code:newLoginCode},401);
 await players[0].act('request_email_change',{email:'forbidden@example.test',admin_password:'TFv1-test-password-42'},403);
 await gate.act('request_email_change',{email:'new-admin@example.test',admin_password:'wrong'},403);
 await gate.act('request_email_change',{email:'player1@example.test',admin_password:'Changed-test-passphrase-42'},422);
 await gate.act('request_email_change',{email:'new-admin@example.test',admin_password:'Changed-test-passphrase-42'});
 check(gate.state.user.email==='cli-admin@example.test'&&gate.state.emailChangePending,'Email must stay unchanged before new inbox verification.');
 const emailCode=smtp.code('new-admin@example.test');
 await gate.act('confirm_email_change',{code:'000000'},422);
 await gate.act('confirm_email_change',{code:emailCode});
 check(gate.state.user.email==='new-admin@example.test'&&!gate.state.emailChangePending,'Email update must preserve organizer access.');
 await cliAdmin.request();check(cliAdmin.state.user===null,'Email update must revoke other organizer sessions.');
 await gate.act('confirm_email_change',{code:emailCode},401);
 await gate.act('logout');await gate.request({action:'admin_login',username:'test-administrator',password:'Changed-test-passphrase-42'});
 check(smtp.messages.at(-1).recipient==='new-admin@example.test','Future sign-in codes must go to the verified new inbox.');
 await gate.act('verify_login',{code:smtp.code('new-admin@example.test')});
 const exhausted=new Client();await exhausted.request();await exhausted.request({action:'login',email:'official@example.test',password:'TFv1-test-password-42'});
 const exhaustedCode=smtp.code('official@example.test');
 for(let i=0;i<5;i++)await exhausted.act('verify_login',{code:'000000'},422);
 await exhausted.act('verify_login',{code:exhaustedCode},422);
 const expiring=new Client();await expiring.request();await expiring.request({action:'login',email:'player3@example.test',password:'TFv1-test-password-42'});
 const expiringCode=smtp.code('player3@example.test');
 const expireChallenge=spawnSync('php',['-r',`require 'lib.php'; session_id('${expiring.cookie.split('=')[1]}'); startSecureSession(); $_SESSION['pending_login']['expires']=time()-1; session_write_close();`],{cwd:root,env,encoding:'utf8'});
 check(expireChallenge.status===0,'Test challenge expiry must be saved to the isolated session store.');
 await expiring.act('verify_login',{code:expiringCode},422);
 // Recovery emails do not overwrite the password; temporary credentials expire and are single-use.
 const recovered=await new Client().signup('Recovery');
 const recoveredId=recovered.state.user.id;
 const recoveryRequest=new Client();await recoveryRequest.request();
 await recoveryRequest.act('forgot_password',{email:'invalid'},422);
 await recoveryRequest.act('change_password',{current_password:'x',new_password:'x',confirm_password:'x'},401);
 const mailCount=smtp.messages.length;
 for(const email of ['unknown@example.test','organizer@example.test','weak@example.test'])await recoveryRequest.act('forgot_password',{email});
 check(smtp.messages.length===mailCount,'Unknown and organizer accounts must not receive player recovery mail.');
 await recoveryRequest.act('forgot_password',{email:'recovery@example.test'});
 const temporary=smtp.messages.at(-1).body.match(/temporary password is: ([a-f0-9]{24})/)?.[1];
 check(!!temporary,'Recovery must deliver a random temporary password over SMTP.');
 await recoveryRequest.act('forgot_password',{email:'recovery@example.test'},429);
 const oldSession=new Client();await oldSession.request();await oldSession.act('login',{email:'recovery@example.test',password:'TFv1-test-password-42'});
 await recoveryRequest.act('login',{email:'recovery@example.test',password:temporary});
 check(recoveryRequest.state.passwordChangeRequired,'Temporary sign-in must prompt a password change.');
 await oldSession.request();check(oldSession.state.user===null,'Temporary sign-in must revoke previous sessions.');
 const replay=new Client();await replay.request();await replay.act('login',{email:'recovery@example.test',password:temporary},401);
 const nextPassword='A-new-recovery-passphrase-73';
 await recoveryRequest.act('change_password',{current_password:'wrong',new_password:nextPassword,confirm_password:nextPassword},403);
 await recoveryRequest.act('change_password',{current_password:temporary,new_password:'short',confirm_password:'short'},422);
 await recoveryRequest.act('change_password',{current_password:temporary,new_password:nextPassword,confirm_password:'different'},422);
 await recoveryRequest.act('change_password',{current_password:temporary,new_password:nextPassword,confirm_password:nextPassword});
 check(!recoveryRequest.state.passwordChangeRequired&&Number(recoveryRequest.state.user.id)===Number(recoveredId),'Password change must keep the current account signed in.');
 await recovered.request();check(recovered.state.user===null,'Password change must revoke other sessions.');
 await replay.act('login',{email:'recovery@example.test',password:'TFv1-test-password-42'},401);
 await replay.act('login',{email:'recovery@example.test',password:nextPassword});
 await replay.act('change_password',{current_password:nextPassword,new_password:'Another-unique-passphrase-92',confirm_password:'Another-unique-passphrase-92'});
 await recoveryRequest.request();check(recoveryRequest.state.user===null,'Normal password changes must revoke previous sessions too.');
 const expired=await new Client().signup('Expired');
 await recoveryRequest.act('forgot_password',{email:'expired@example.test'});
 const expiredPassword=smtp.messages.at(-1).body.match(/temporary password is: ([a-f0-9]{24})/)?.[1];
 const expireReset=spawnSync('php',['-r',`require 'lib.php'; $c=require 'config.php'; if(!preg_match('/^tfv1_test_[a-f0-9]{12}$/',$c['database']))exit(1); db()->exec('UPDATE password_resets SET expires_at=DATE_SUB(NOW(), INTERVAL 1 MINUTE)');`],{cwd:root,env,encoding:'utf8'});
 check(expireReset.status===0,'Only isolated test credentials may be expired.');
 await recoveryRequest.act('login',{email:'expired@example.test',password:expiredPassword},401);
 await recoveryRequest.act('login',{email:'expired@example.test',password:'TFv1-test-password-42'});
 check(Number(recoveryRequest.state.user.id)===Number(expired.state.user.id),'Expired recovery must preserve the original password.');
 const limited=new Client();await limited.request();
 for(let i=0;i<10;i++)await limited.act('login',{email:'missing@example.test',password:'wrong'},401);
 await limited.act('login',{email:'missing@example.test',password:'wrong'},429);
 const headers=await fetch(base);await headers.text();check(headers.headers.get('content-security-policy')?.includes("script-src 'self';"),'Scripts must be protected by CSP.');check(!headers.headers.has('access-control-allow-origin'),'API must not allow cross-origin reads.');
 console.log(`PASS: ${checks} integration assertions — accounts, MySQL persistence, all three formation modes, check-in, roster locks, round robin, all five races, referee separation, corrections, standings, CSRF and audit-safe reset.`);
 // Simulate the missing security table behind the reconnect warning in this isolated database.
 const beforeMigration=JSON.stringify((await admin.request()).events);
 const missingTable=spawnSync('php',['-r',`require 'lib.php'; $c=require 'config.php'; if(!preg_match('/^tfv1_test_[a-f0-9]{12}$/',$c['database']))exit(1); db()->exec('DROP TABLE rate_limits');`],{cwd:root,env,encoding:'utf8'});
 check(missingTable.status===0,'Only the isolated test database may simulate an outdated schema.');
 const unavailable=await admin.request(undefined,503);
 check(unavailable.schema_required===true&&unavailable.error.includes('php migrate.php'),'Missing security tables must identify the required migration.');
 const migrated=spawnSync('php',['migrate.php'],{cwd:root,env,encoding:'utf8'});
 check(migrated.status===0,'The additive migration must restore availability.');
 check(JSON.stringify((await admin.request()).events)===beforeMigration,'Migration must preserve all tournament state and the admin session.');
 console.log('PASS: missing security table diagnosis and migration recovery with tournament state preserved.');
}finally{
 if(server){server.kill();await new Promise(r=>server.once('exit',r))}
 await smtp.close();
 const removed=spawnSync('php',['tests/database.php','drop'],{cwd:root,env,encoding:'utf8'});if(removed.status!==0)console.error(removed.stderr);else console.log(removed.stdout.trim());
}
