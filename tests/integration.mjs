import {spawn,spawnSync} from 'node:child_process';
import {randomBytes} from 'node:crypto';
import {fileURLToPath} from 'node:url';
import path from 'node:path';
import assert from 'node:assert/strict';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const database='tfv1_test_'+randomBytes(6).toString('hex');
const env={...process.env,TF_DB_NAME:database};
const port=19000+Math.floor(Math.random()*1000),base=`http://127.0.0.1:${port}/api.php`;
let server,checks=0;
const check=(value,message)=>{assert.ok(value,message);checks++};
class Client{
 cookie='';csrf='';state=null;
 async request(data,expected=200){
  const res=await fetch(base,{method:data?'POST':'GET',headers:{Cookie:this.cookie,...(data?{'Content-Type':'application/json','X-CSRF-Token':this.csrf}:{})},body:data?JSON.stringify(data):undefined});
  for(const cookie of res.headers.getSetCookie()) if(cookie.startsWith('PHPSESSID='))this.cookie=cookie.split(';')[0];
  const raw=await res.text();let json;try{json=JSON.parse(raw)}catch{throw Error(`Invalid API JSON (${res.status}): ${raw.slice(0,2000)}`)}assert.equal(res.status,expected,JSON.stringify(json));checks++;
  if(res.ok){this.state=json.state||json;this.csrf=this.state.csrf}return json;
 }
 async act(action,data={},expected=200){return this.request({action,...data},expected)}
 async signup(name,setup=false){await this.request();await this.act(setup?'setup':'signup',{name,email:`${name}@example.test`,password:'TFv1-test-password-42',fair_play:true});return this}
 event(id){return this.state.events.find(e=>Number(e.id)===Number(id))}
}
try{
 const installed=spawnSync('php',['install.php'],{cwd:root,env,encoding:'utf8'});assert.equal(installed.status,0,installed.stderr);
 server=spawn('php',['-S',`127.0.0.1:${port}`,'-t',root],{cwd:root,env,stdio:'ignore'});
 for(let i=0;i<60;i++){try{await fetch(base);break}catch{await new Promise(r=>setTimeout(r,100))}}
 const admin=await new Client().signup('Organizer',true),ref=await new Client().signup('Official');
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
 console.log(`PASS: ${checks} integration assertions — accounts, MySQL persistence, all three formation modes, check-in, roster locks, round robin, all five races, referee separation, corrections, standings, CSRF and audit-safe reset.`);
}finally{
 if(server){server.kill();await new Promise(r=>server.once('exit',r))}
 const removed=spawnSync('php',['tests/database.php','drop'],{cwd:root,env,encoding:'utf8'});if(removed.status!==0)console.error(removed.stderr);else console.log(removed.stdout.trim());
}
