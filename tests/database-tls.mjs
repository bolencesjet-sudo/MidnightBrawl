import assert from 'node:assert/strict';
import net from 'node:net';
import tls from 'node:tls';
import {spawn,spawnSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';

const root=fileURLToPath(new URL('../',import.meta.url));
const generated=spawnSync('php',['tests/certificate.php'],{cwd:root,encoding:'utf8'});
assert.equal(generated.status,0,generated.stderr);
const {key,cert}=JSON.parse(generated.stdout);
const secureContext=tls.createSecureContext({key,cert});
const packet=(body,sequence)=>{
 const header=Buffer.alloc(4);header.writeUIntLE(body.length,0,3);header[3]=sequence;
 return Buffer.concat([header,body]);
};
const str=value=>{const b=Buffer.from(value);return Buffer.concat([Buffer.from([b.length]),b])};
const ok=Buffer.from([0,0,0,2,0,0,0]),eof=Buffer.from([0xfe,0,0,2,0]);
function field(name){
 const fixed=Buffer.alloc(13);fixed[0]=12;fixed.writeUInt16LE(45,1);fixed.writeUInt32LE(1024,3);fixed[7]=0xfd;
 return Buffer.concat([str('def'),str(''),str(''),str(''),str(name),str(''),fixed]);
}
async function scenario({supportsTls=true,reportedCipher='TLS_TEST',ca=''}={}){
 const sockets=new Set();let sslRequested=false,loginReceived=false;
 const track=socket=>{sockets.add(socket);socket.on('error',()=>{});socket.on('close',()=>sockets.delete(socket))};
 const server=net.createServer(socket=>{
  track(socket);
  const flags=0x0008a205|(supportsTls?0x800:0);
  const details=Buffer.alloc(18);details.writeUInt32LE(1,0);Buffer.from('12345678').copy(details,4);details.writeUInt16LE(flags&0xffff,13);details[15]=45;details.writeUInt16LE(2,16);
  const upper=Buffer.alloc(13);upper.writeUInt16LE(flags>>>16,0);upper[2]=21;
  socket.write(packet(Buffer.concat([Buffer.from('\x0a8.0.0-test\0'),details,upper,Buffer.from('abcdefghijkl\0mysql_native_password\0')]),0));
  let buffer=Buffer.alloc(0);
  const receive=chunk=>{
   buffer=Buffer.concat([buffer,chunk]);
   while(buffer.length>=4&&buffer.length>=buffer.readUIntLE(0,3)+4){
    const length=buffer.readUIntLE(0,3),body=buffer.subarray(4,length+4);buffer=buffer.subarray(length+4);
    if(body.length===32&&(body.readUInt32LE(0)&0x800)){
     sslRequested=true;socket.removeListener('data',receive);
     const secure=new tls.TLSSocket(socket,{isServer:true,secureContext});track(secure);
     let encrypted=Buffer.alloc(0),authenticated=false;
     secure.on('data',chunk=>{
      encrypted=Buffer.concat([encrypted,chunk]);
      while(encrypted.length>=4&&encrypted.length>=encrypted.readUIntLE(0,3)+4){
       const size=encrypted.readUIntLE(0,3),sequence=encrypted[3],command=encrypted.subarray(4,size+4);encrypted=encrypted.subarray(size+4);
       if(!authenticated){loginReceived=true;authenticated=true;secure.write(packet(ok,sequence+1));continue}
       if(command[0]===1){secure.end();continue}
       if(command[0]===0x19)continue; // COM_STMT_CLOSE has no response.
       if(command[0]===0x16){
        const prepared=Buffer.alloc(12);prepared.writeUInt32LE(1,1);prepared.writeUInt16LE(2,5);
        secure.write(Buffer.concat([packet(prepared,1),packet(field('Variable_name'),2),packet(field('Value'),3),packet(eof,4)]));
        continue;
       }
       const sql=command.subarray(1).toString(),binary=command[0]===0x17;
       if(binary||sql.includes('Ssl_cipher'))secure.write(Buffer.concat([
        packet(Buffer.from([2]),1),packet(field('Variable_name'),2),packet(field('Value'),3),packet(eof,4),
        packet(Buffer.concat([...(binary?[Buffer.from([0,0])]:[]),str('Ssl_cipher'),str(reportedCipher)]),5),packet(eof,6),
       ]));
       else secure.write(packet(ok,1));
      }
     });
    }else if(body.length>32){loginReceived=true;socket.end()}
   }
  };
  socket.on('data',receive);
 });
 await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
 try{
  const result=await new Promise(resolve=>{
   const child=spawn('php',['tests/database-tls.php'],{cwd:root,env:{...process.env,
    TF_ENV:'production',TF_APP_KEY:'fixture-'.repeat(6),TF_APP_URL:'https://fixture.example.test',
    TF_DB_HOST:'127.0.0.1',TF_DB_PORT:String(server.address().port),TF_DB_NAME:'fixture',
    TF_DB_USER:'fixture',TF_DB_PASSWORD:'fixture-only',TF_DB_SSL_CA:'',TF_DB_SSL_CA_PEM:ca,
   },stdio:['ignore','pipe','pipe']});
   let stdout='',stderr='';child.stdout.on('data',b=>stdout+=b);child.stderr.on('data',b=>stderr+=b);
   const timeout=setTimeout(()=>child.kill(),10000);
   child.on('exit',code=>{clearTimeout(timeout);resolve({code,stdout,stderr})});
  });
  return {...result,sslRequested,loginReceived};
 }finally{for(const socket of sockets)socket.destroy();await new Promise(resolve=>server.close(resolve))}
}
const withoutCa=await scenario();
assert.equal(withoutCa.code,0,withoutCa.stderr);
assert.ok(withoutCa.sslRequested&&withoutCa.loginReceived,'A self-signed, hostname-mismatched database must accept login only after upgrading to TLS.');
const noTls=await scenario({supportsTls:false});
assert.notEqual(noTls.code,0,'Production must reject a server without TLS.');
assert.equal(noTls.loginReceived,false,'A server without TLS must not receive login credentials.');
const emptyCipher=await scenario({reportedCipher:''});
assert.notEqual(emptyCipher.code,0,'An unencrypted session must not become a usable connection.');
assert.match(emptyCipher.stderr,/must use TLS encryption/);
const verified=await scenario({ca:cert});
assert.notEqual(verified.code,0,'An explicitly configured CA must still reject a hostname mismatch.');
assert.equal(verified.loginReceived,false,'Failed verification must not retry without verification.');
console.log('PASS: CA-free encrypted login, plaintext rejection before login, cipher enforcement and optional CA verification without fallback.');
