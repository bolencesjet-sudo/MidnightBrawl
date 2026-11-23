// Read-only smoke check: confirms the actual public deployment, not just a CI badge.
import assert from 'node:assert/strict';
const base=new URL(process.argv[2]||'https://midnightbrawl.vercel.app/');
if(base.protocol!=='https:')throw Error('Use the HTTPS deployment URL.');
let checks=0;
for(const route of ['/','/index.php','/health','/assets/app.js','/assets/style.css','/config.php','/api.php']){
 const response=await fetch(new URL(route,base),{signal:AbortSignal.timeout(30000)});
 const body=await response.text(),type=response.headers.get('content-type')||'';
 assert.ok(!body.trimStart().startsWith('<?php'),`${route} exposes PHP source`);checks++;
 if(route==='/config.php'){assert.ok([403,404].includes(response.status),'Configuration source must be inaccessible');checks++;continue}
 assert.equal(response.status,200,`${route} must return 200`);checks++;
 if(route==='/'||route==='/index.php'){assert.ok(type.includes('text/html')&&body.includes('<!doctype html>'),'Portal must render HTML');checks++}
 if(route==='/health'){assert.equal(JSON.parse(body).status,'ok');checks++}
 if(route==='/api.php'){const state=JSON.parse(body);assert.ok(Array.isArray(state.events)&&typeof state.csrf==='string'&&!state.error,'API must return usable portal state');checks++}
}
console.log(`PASS: ${checks} live deployment assertions — rendered HTML, runtime, assets, private config and data API.`);
