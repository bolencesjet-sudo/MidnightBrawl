import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';

// Exercise the real bootstrap/mutation code without starting a browser.
const source=readFileSync(new URL('../assets/app.js',import.meta.url),'utf8');
const error={textContent:'',classList:{add(){}}};
const context=vm.createContext({
 document:{querySelector:()=>error,querySelectorAll:()=>[]},
 localStorage:{getItem:()=>null},Response,
});
vm.runInContext(source.slice(0,source.indexOf('function heading(')),context);
for(const [status,body,expected] of [
 [503,{error:'The database needs an update. Run php migrate.php.'},'The database needs an update. Run php migrate.php.'],
 [429,{error:'Too many attempts. Please try again later.'},'Too many attempts. Please try again later.'],
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
console.log('PASS: authentication bootstrap errors, rate limits, invalid server responses, form recovery and CSRF submission.');
