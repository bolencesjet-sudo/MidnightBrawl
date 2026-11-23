// Offline, high-confidence scan. Prints locations/categories, never matching values.
import {spawnSync} from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
const patterns=[['GitHub token',/\b(?:github_pat_[a-zA-Z0-9_]{30,}|gh[pousr]_[a-zA-Z0-9]{30,})\b/],['AWS key',/\b(?:AKIA|ASIA)[A-Z0-9]{16}\b/],['Private key',/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/],['Embedded URL password',/https?:\/\/[^\s/:]+:[^\s/@]{8,}@/]];
let blobs=0,files=0,findings=0;
function scan(text,location){for(const [name,pattern]of patterns)if(pattern.test(text)){console.log(`${name}: ${location}`);findings++}}
const git=(args)=>{const p=spawnSync('git',args,{encoding:'utf8',maxBuffer:64*1024*1024});if(p.status!==0)throw Error('Git scan failed');return p.stdout};
const objects=git(['rev-list','--objects','--all']).trim().split('\n').filter(Boolean);
for(const entry of objects){const [oid,...parts]=entry.split(' ');const info=git(['cat-file','-t',oid]).trim();if(info!=='blob')continue;blobs++;const text=git(['cat-file','blob',oid]);scan(text,`history object ${oid.slice(0,12)} ${parts.join(' ')}`)}
function walk(dir){for(const ent of fs.readdirSync(dir,{withFileTypes:true})){if(['.git','vendor','runtime','node_modules'].includes(ent.name))continue;const p=path.join(dir,ent.name);if(ent.isDirectory())walk(p);else if(fs.statSync(p).size<2*1024*1024){files++;scan(fs.readFileSync(p,'utf8'),p)}}}
walk('.');
console.log(`Scanned ${blobs} historical blobs across all local refs and ${files} working files; ${findings} high-confidence findings. This scan does not detect every kind of secret or inspect unfetched remote refs.`);
process.exitCode=findings?1:0;
