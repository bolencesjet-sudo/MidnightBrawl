// Start a local headless Chrome with --remote-debugging-port=9227 first.
import assert from 'node:assert/strict';
const pages=await(await fetch('http://127.0.0.1:9227/json/list')).json();
const target=pages.find(p=>p.type==='page');assert.ok(target,'Chrome test page is required.');
const socket=new WebSocket(target.webSocketDebuggerUrl);await new Promise(r=>socket.addEventListener('open',r,{once:true}));
let id=0,checks=0;const pending=new Map(),errors=[];
socket.addEventListener('message',ev=>{const msg=JSON.parse(ev.data);if(msg.id){const job=pending.get(msg.id);if(job){pending.delete(msg.id);msg.error?job.reject(Error(JSON.stringify(msg.error))):job.resolve(msg.result)}}else if(msg.method==='Runtime.exceptionThrown')errors.push(msg.params.exceptionDetails)});
const send=(method,params={})=>new Promise((resolve,reject)=>{const n=++id;pending.set(n,{resolve,reject});socket.send(JSON.stringify({id:n,method,params}))});
const evaluate=async expression=>{const r=await send('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});if(r.exceptionDetails)throw Error(JSON.stringify(r.exceptionDetails));return r.result.value};
const check=(v,message)=>{assert.ok(v,message);checks++};
try{
 await send('Page.enable');await send('Runtime.enable');
 await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});
 await send('Page.navigate',{url:'http://localhost/TFv1/'});
 for(let i=0;i<100;i++){if(await evaluate('typeof state!=="undefined"&&!!state'))break;await new Promise(r=>setTimeout(r,100))}
 check(await evaluate('!!state'),'API state must load.');
 await evaluate('authModal("admin_login")');
 check(await evaluate('!!document.querySelector("form[data-submit=admin_login] input[name=username]")'),'Separate admin sign-in form must accept a username.');
 await evaluate('document.querySelector("#modal").close();authModal("signup")');
 check(await evaluate('document.querySelector("input[name=password]").minLength===15'),'Signup must require a 15-character password.');
 await evaluate('document.querySelector("#modal").close();verificationModal()');
 check(await evaluate('document.querySelector("input[name=code]").pattern==="[0-9]{6}"'),'Verification must request six digits.');
 // In-memory fixture only: no users or events are written to the database.
 await evaluate(`document.querySelector('#modal').close();state.user={id:1,name:'Admin',role:'organizer'};state.users=[state.user,{id:2,name:'<img src=x onerror=alert(1)>',email:'fixture@example.test',role:'player',disabled_at:null,email_verified_at:'2030-01-01'}];location.hash='admin';render()`);
 check(await evaluate('document.querySelector("[data-action=toggle-account]").dataset.id==="2"'),'Account controls must identify the target user.');
 check(await evaluate('!document.querySelector("#main img[src=x]")'),'Account names must be escaped against XSS.');
 await evaluate('document.querySelector("[data-action=toggle-account]").click()');
 check(await evaluate('!!document.querySelector("form[data-submit=set_disabled] input[name=admin_password]")'),'Disabling an account must request the admin password.');
 await evaluate(`document.querySelector('#modal').close();state.events=[{id:999,name:'Fixture <script>alert(1)</script>',game:'Game',team_size:2,max_teams:4,formation:'random',format:'single',race_to:2,deadline:'2030-01-01 12:00:00',roles:['Mid'],locked:0,demo:0,players:[],teams:[],matches:[],standings:[],requests:[],audit:[]}];activeEvent=999;location.hash='settings';render()`);
 check(await evaluate('!!document.querySelector("[data-action=edit-event]")&&!!document.querySelector("[data-action=delete-event]")'),'Admins must see edit/delete event controls.');
 await evaluate('document.querySelector("[data-action=edit-event]").click()');
 check(await evaluate('document.querySelector("input[name=name]").value==="Fixture <script>alert(1)</script>"'),'Event name must remain safe inside edit form attributes.');
 await evaluate('document.querySelector("#modal").close();document.querySelector("[data-action=delete-event]").click()');
 check(await evaluate('!!document.querySelector("input[name=confirmation]")&&!!document.querySelector("input[name=admin_password]")'),'Deletion must request the event name and admin password.');
 await evaluate('document.querySelector("#modal").close();state.user={id:2,role:"player"};render()');
 check(await evaluate('!document.querySelector("[data-action=edit-event]")&&!document.querySelector("[data-action=delete-event]")&&!document.querySelector("a[href=\\"#admin\\"]")'),'Players must not see admin controls.');
 for(const width of [390,1440]){await send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<500});check(await evaluate('document.documentElement.scrollWidth<=innerWidth'),`Settings must fit ${width}px viewport.`)}
 check(errors.length===0,'No browser JavaScript exceptions.');
 console.log(`PASS: ${checks} browser assertions — admin controls, verification, password requirements, XSS escaping, responsive settings and JavaScript execution under CSP.`);
}finally{socket.close()}
