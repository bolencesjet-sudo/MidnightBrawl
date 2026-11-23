// Optional visual QA: launch Chrome with --remote-debugging-port=9227 first.
import assert from 'node:assert/strict';
import {writeFile} from 'node:fs/promises';
const pages=await(await fetch('http://127.0.0.1:9227/json/list')).json();
const target=pages.find(p=>p.type==='page');assert.ok(target,'A Chrome page is required.');
const socket=new WebSocket(target.webSocketDebuggerUrl);await new Promise(r=>socket.addEventListener('open',r,{once:true}));
let id=0;const pending=new Map(),errors=[];
socket.addEventListener('message',ev=>{const msg=JSON.parse(ev.data);if(msg.id){const job=pending.get(msg.id);if(job){pending.delete(msg.id);msg.error?job.reject(Error(JSON.stringify(msg.error))):job.resolve(msg.result)}}else if(msg.method==='Runtime.exceptionThrown')errors.push(msg.params.exceptionDetails)});
const send=(method,params={})=>new Promise((resolve,reject)=>{const n=++id;pending.set(n,{resolve,reject});socket.send(JSON.stringify({id:n,method,params}))});
const evaluate=async expression=>{const r=await send('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});if(r.exceptionDetails)throw Error(JSON.stringify(r.exceptionDetails));return r.result.value};
const wait=ms=>new Promise(r=>setTimeout(r,ms));
let checks=0;function check(value,message){assert.ok(value,message);checks++}
try{
 await send('Page.enable');await send('Runtime.enable');
 await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});
 await send('Page.navigate',{url:'http://localhost/TFv1/'});
 for(let i=0;i<80;i++){if(await evaluate('!!document.querySelector(".hero")'))break;await wait(150)}
 check(await evaluate('document.querySelector("h1").textContent.includes("overview")'),'Overview should load.');
 check(await evaluate('document.querySelector(".hero img").complete'),'TFv1 logo should load.');
 check(await evaluate('document.querySelector(".hero").getBoundingClientRect().height<350'),'Desktop hero should stay compact.');
 check(await evaluate('Array.from(document.querySelectorAll("svg")).every(s=>s.getBoundingClientRect().width<=45)'),'Interface icons should not expand the layout.');
 for(const page of ['overview','players','teams','matches','standings','settings']){
  await evaluate(`location.hash=${JSON.stringify(page)}`);await wait(100);
  check(await evaluate('document.querySelectorAll("h1").length===1'),`${page} should render a heading.`);
  check(await evaluate('document.documentElement.scrollWidth<=innerWidth'),`${page} desktop should fit viewport.`);
 }
 await evaluate('location.hash="overview"');await wait(100);
 await evaluate('if(document.documentElement.dataset.theme!=="dark")document.querySelector("#theme-toggle").click()');
 let screenshot=await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:true});await writeFile(new URL('../runtime/overview-dark.png',import.meta.url),Buffer.from(screenshot.data,'base64'));
 await evaluate('document.querySelector("#theme-toggle").click()');check(await evaluate('document.documentElement.dataset.theme==="light"'),'Theme toggle should enable white mode.');
 screenshot=await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:true});await writeFile(new URL('../runtime/overview-light.png',import.meta.url),Buffer.from(screenshot.data,'base64'));
 await evaluate('document.querySelector("[data-action=rules]").click()');check(await evaluate('document.querySelector("dialog").open'),'Fair-play dialog should open.');
 await evaluate('document.querySelector("dialog").close();document.querySelector("[data-action=setup]").click()');check(await evaluate('document.querySelector("dialog").open&&document.querySelector("form[data-submit=setup]")!==null'),'Organizer setup should open.');
 check(await evaluate('!document.querySelector("#dialog-form").checkValidity()'),'Empty setup must be rejected by validation.');await evaluate('document.querySelector("dialog").close()');
 await send('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});await evaluate('document.querySelector("#theme-toggle").click()');
 for(const page of ['overview','players','teams','matches','standings','settings']){
  await evaluate(`location.hash=${JSON.stringify(page)}`);await wait(100);
  check(await evaluate('document.documentElement.scrollWidth<=innerWidth'),`${page} mobile should fit viewport.`);
 }
 await evaluate('location.hash="overview";window.scrollTo(0,0)');await wait(100);
 screenshot=await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:true});await writeFile(new URL('../runtime/overview-mobile.png',import.meta.url),Buffer.from(screenshot.data,'base64'));
 await evaluate('document.querySelector("#menu-toggle").click()');check(await evaluate('document.querySelector("#sidebar").classList.contains("open")'),'Mobile menu should open.');
 await evaluate('Array.from(document.querySelectorAll("#sidebar a")).find(a=>a.hash==="#players").click()');await wait(100);check(await evaluate('!document.querySelector("#sidebar").classList.contains("open")'),'Mobile navigation should close menu.');
 await evaluate('window.scrollTo(0,500)');check(await evaluate('document.querySelector(".topbar").getBoundingClientRect().top===0'),'Important header should stay fixed.');
 check(errors.length===0,'No browser JavaScript exceptions: '+JSON.stringify(errors));
 await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});await evaluate('location.hash="overview";window.scrollTo(0,0)');
 console.log(`PASS: ${checks} browser checks — all six pages, dark/light themes, desktop/mobile overflow, setup and fair-play dialogs, mobile navigation, sticky header and JavaScript errors. Screenshots saved in runtime/.`);
}finally{socket.close()}
