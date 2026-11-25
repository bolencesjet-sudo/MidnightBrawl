import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';

// Exercise the real modal templates and mutation handoff without a browser dependency.
const source=readFileSync(new URL('../assets/app.js',import.meta.url),'utf8');
const content={innerHTML:''},dialog={showModal(){},close(){}};
const context=vm.createContext({
 document:{querySelector:selector=>selector==='#modal-content'?content:dialog,querySelectorAll:()=>[]},
 localStorage:{getItem:()=>null},Response,AbortController,setTimeout,clearTimeout,
});
vm.runInContext(source.slice(0,source.indexOf("document.addEventListener('click'")),context);
vm.runInContext("authModal('login')",context);
assert.match(content.innerHTML,/data-action="forgot-password"/);
vm.runInContext("authModal('admin_login')",context);
assert.match(content.innerHTML,/Organizer sign in/);
assert.doesNotMatch(content.innerHTML,/data-action="forgot-password"/);
vm.runInContext("authModal('signup')",context);
assert.match(content.innerHTML,/data-submit="signup"/);
assert.doesNotMatch(content.innerHTML,/data-action="forgot-password"/);
vm.runInContext("render=()=>{};toast=()=>{};state={csrf:'test-csrf',events:[]}",context);
context.fetch=async(url,options)=>{
 assert.equal(options.headers['X-CSRF-Token'],'test-csrf');
 const request=JSON.parse(options.body);
 assert.equal(request.action,'forgot_password');
 assert.equal(request.email,'player@example.test');
 return new Response(JSON.stringify({state:{csrf:'test-csrf',events:[],user:null}}));
};
assert.equal(await vm.runInContext("mutate('forgot_password',{email:'player@example.test'})",context),true);
assert.match(content.innerHTML,/Check your inbox and spam folder/);
assert.match(content.innerHTML,/data-action="login"/);
context.fetch=async()=>new Response(JSON.stringify({state:{csrf:'test-csrf',events:[],user:{id:1},passwordChangeRequired:true}}));
assert.equal(await vm.runInContext("mutate('verify_login',{code:'123456'})",context),true);
assert.match(content.innerHTML,/data-submit="change_password"/);
for(const name of ['current_password','new_password','confirm_password'])assert.match(content.innerHTML,new RegExp(`name="${name}"`));
context.fetch=async()=>new Response(JSON.stringify({state:{csrf:'test-csrf',events:[],user:null,loginVerificationRequired:true}}));
assert.equal(await vm.runInContext("mutate('admin_login',{username:'organizer',password:'password'})",context),true);
assert.match(content.innerHTML,/data-submit="verify_login"/);
assert.match(content.innerHTML,/autocomplete="one-time-code"/);
assert.match(content.innerHTML,/data-action="resend-login"/);
context.fetch=async()=>new Response(JSON.stringify({state:{csrf:'test-csrf',events:[],user:{id:1,role:'organizer'},emailChangePending:true}}));
assert.equal(await vm.runInContext("mutate('request_email_change',{email:'new@example.test',admin_password:'password'})",context),true);
assert.match(content.innerHTML,/data-submit="confirm_email_change"/);
let click;
context.document.addEventListener=(name,handler)=>{if(name==='click')click=handler};
vm.runInContext(source.slice(source.indexOf("document.addEventListener('click'"),source.indexOf("document.addEventListener('submit'")),context);
for(const role of ['player','referee','organizer']){
 vm.runInContext(`state.user={id:1,name:'Account',email:'account@example.test',role:'${role}'}`,context);
 await click({target:{closest:selector=>selector==='[data-action]'?{dataset:{action:'account'}}:null},preventDefault(){}});
 assert.match(content.innerHTML,/data-action="change-email"/,`${role} account menu must offer Change email`);
 await click({target:{closest:selector=>selector==='[data-action]'?{dataset:{action:'change-email'}}:null},preventDefault(){}});
 assert.match(content.innerHTML,/data-submit="request_email_change"/,`${role} must be able to open the email change form`);
 assert.match(content.innerHTML,/Confirm your password/);
}
assert.match(readFileSync(new URL('../index.php',import.meta.url),'utf8'),/>Organizer sign in</);
console.log('PASS: player recovery entry, separate organizer sign-in, signup, email confirmation, CSRF submission and temporary-login password-change handoff.');
