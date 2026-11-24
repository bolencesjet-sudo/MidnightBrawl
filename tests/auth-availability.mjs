import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';

// Exercise the real bootstrap/mutation code without starting a browser.
const source=readFileSync(new URL('../assets/app.js',import.meta.url),'utf8');
const error={textContent:'',classList:{add(){}}};
const context=vm.createContext({
 document:{querySelector:()=>error,querySelectorAll:()=>[]},
 localStorage:{getItem:()=>null},Response,AbortController,setTimeout,clearTimeout,
});
vm.runInContext(source.slice(0,source.indexOf('function heading(')),context);
for(const [status,body,expected] of [
 [503,{error:'The database needs an update. Run php migrate.php.'},'The database needs an update. Run php migrate.php.'],
 [429,{error:'Too many attempts. Please try again later.'},'Too many attempts. Please try again later.'],
 [503,{error:'The service is temporarily unavailable. Contact the administrator.',database_unavailable:true,reference:'0123456789abcdef'},'The service is temporarily unavailable. Contact the administrator. Reference: 0123456789abcdef.'],
 [502,'<html>Bad gateway</html>','The server returned an invalid response. Please try again shortly.'],
]){
 let requests=0;
 context.fetch=async()=>{requests++;return new Response(typeof body==='string'?body:JSON.stringify(body),{status})};
 assert.equal(await vm.runInContext("mutate('login',{email:'player@example.test',password:'example'})",context),false);
 assert.equal(error.textContent,expected);
 assert.equal(requests,1,'A failed session bootstrap must never submit credentials.');
 assert.equal(vm.runInContext('busy',context),false,'The form must be usable after failure.');
}
let requests=0;
context.fetch=async(url,options)=>{
 requests++;
 if(requests===1)return new Response(JSON.stringify({csrf:'fresh-token',events:[]}));
 assert.equal(options.headers['X-CSRF-Token'],'fresh-token');
 assert.equal(JSON.parse(options.body).action,'login');
 return new Response(JSON.stringify({error:'Invalid credentials.'}),{status:401});
};
await vm.runInContext("mutate('login',{email:'player@example.test',password:'example'})",context);
assert.equal(requests,2,'Retrying the form must bootstrap a fresh session and submit once.');
assert.equal(error.textContent,'Invalid credentials.');

// Recover an interrupted session read before sending credentials, exactly once.
vm.runInContext('state=null',context);requests=0;
context.fetch=async(url,options)=>{
 requests++;
 assert.equal(options.credentials,'same-origin');
 assert.equal(options.redirect,'error','Credential requests must not follow redirects.');
 assert.equal(options.cache,'no-store');
 assert.ok(options.signal instanceof AbortSignal);
 if(requests===1)throw new TypeError('Failed to fetch');
 if(requests===2)return new Response(JSON.stringify({csrf:'reconnected',events:[]}));
 assert.equal(options.headers['X-CSRF-Token'],'reconnected');
 return new Response(JSON.stringify({error:'Validation reached.'}),{status:422});
};
await vm.runInContext("mutate('signup',{email:'player@example.test'})",context);
assert.equal(requests,3);
assert.equal(error.textContent,'Validation reached.');

// An unavailable session must never send a registration POST.
vm.runInContext('state=null',context);requests=0;
context.fetch=async(url,options)=>{requests++;assert.notEqual(options.method,'POST');throw new TypeError('Failed to fetch')};
await vm.runInContext("mutate('signup',{email:'player@example.test'})",context);
assert.equal(requests,2);
assert.match(error.textContent,/Unable to reach the tournament server/);
assert.equal(vm.runInContext('busy',context),false);

// A lost registration response must not trigger a duplicate submission or close the form.
vm.runInContext("state={csrf:'existing-token',events:[]}",context);requests=0;
context.fetch=async()=>{requests++;throw new TypeError('Failed to fetch')};
await vm.runInContext("mutate('signup',{email:'player@example.test'})",context);
assert.equal(requests,1);
assert.match(error.textContent,/could not confirm your submission/);
assert.match(error.textContent,/same email/);
assert.equal(vm.runInContext('busy',context),false);
assert.equal(vm.runInContext('state.csrf',context),null,'A lost response may have rotated CSRF: obtain a new session before the next submission.');
requests=0;
context.fetch=async(url,options)=>{
 requests++;
 if(requests===1){assert.notEqual(options.method,'POST');return new Response(JSON.stringify({csrf:'after-lost-signup',events:[]}))}
 assert.equal(options.headers['X-CSRF-Token'],'after-lost-signup');
 return new Response(JSON.stringify({error:'Recovered sign-in validation.'}),{status:401});
};
await vm.runInContext("mutate('login',{email:'player@example.test'})",context);
assert.equal(requests,2);
assert.equal(error.textContent,'Recovered sign-in validation.');

// A timed-out transport restores the form and provides the same safe recovery path.
context.fetch=async()=>{const failure=new Error('Aborted');failure.name='AbortError';throw failure};
await vm.runInContext("mutate('signup',{email:'player@example.test'})",context);
assert.match(error.textContent,/could not confirm your submission/);
assert.equal(vm.runInContext('busy',context),false);

// Exercise the timer rather than relying only on a synthetic AbortError.
vm.runInContext("state={csrf:'timeout-token',events:[]}",context);
const delays=[];
context.setTimeout=(callback,delay)=>{delays.push(delay);queueMicrotask(callback);return 0};
context.clearTimeout=()=>{};
context.fetch=async(url,options)=>new Promise((resolve,reject)=>{
 options.signal.addEventListener('abort',()=>{const failure=new Error('Timed out');failure.name='AbortError';reject(failure)},{once:true});
});
await vm.runInContext("mutate('signup',{email:'player@example.test'})",context);
assert.deepEqual(delays,[55000]);
assert.equal(vm.runInContext('busy',context),false);
vm.runInContext('state=null',context);delays.length=0;
await vm.runInContext("mutate('signup',{email:'player@example.test'})",context);
assert.deepEqual(delays,[15000,15000]);
assert.match(error.textContent,/Unable to reach/);
context.setTimeout=setTimeout;context.clearTimeout=clearTimeout;

// Successful registration still closes the form and opens email verification.
vm.runInContext("state={csrf:'signup-token',events:[]}",context);
let closed=false,rendered=false,verified=false;
context.document.querySelector=()=>({close(){closed=true}});
context.render=()=>{rendered=true};context.toast=()=>{};context.verificationModal=()=>{verified=true};
context.fetch=async()=>new Response(JSON.stringify({ok:true,state:{csrf:'verification-token',events:[],verificationRequired:true}}));
assert.equal(await vm.runInContext("mutate('signup',{email:'player@example.test'})",context),true);
assert.ok(closed&&rendered&&verified,'Successful registration must continue the existing verification flow.');
console.log('PASS: authentication bootstrap, connection recovery, bounded read retries, no duplicate POSTs, timeouts, server errors and CSRF submission.');
