import assert from 'node:assert/strict';

// Submit deliberately invalid input: validates the registration route/session/origin
// without creating an account, sending email, or changing any tournament data.
const base=new URL(process.argv[2]||'https://midnightbrawl.vercel.app/');
assert.equal(base.protocol,'https:','Use the official HTTPS website.');
const endpoint=new URL('api.php',base);
const session=await fetch(endpoint,{redirect:'error',signal:AbortSignal.timeout(30000)});
assert.equal(session.status,200,'Session API must be available.');
const state=await session.json();
assert.equal(typeof state.csrf,'string');
const cookie=session.headers.getSetCookie().map(value=>value.split(';')[0]).join('; ');
assert.ok(cookie,'Session API must set a cookie.');
const response=await fetch(endpoint,{
 method:'POST',redirect:'error',signal:AbortSignal.timeout(55000),
 headers:{'Content-Type':'application/json',Origin:base.origin,Cookie:cookie,'X-CSRF-Token':state.csrf},
 body:JSON.stringify({action:'signup',email:'',name:'',password:'',fair_play:false}),
});
const result=await response.json();
assert.equal(response.status,422,result.error||'Invalid registration must be rejected.');
assert.match(result.error,/email|administrator.*setup/i,'Request must reach signup validation.');
console.log('PASS: live registration POST reaches validation with same-origin/session/CSRF checks; no account created or email sent.');
