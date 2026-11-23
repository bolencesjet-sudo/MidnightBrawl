import net from 'node:net';
import tls from 'node:tls';
import {spawnSync} from 'node:child_process';
import {mkdtempSync,writeFileSync,unlinkSync,rmdirSync} from 'node:fs';
import {tmpdir} from 'node:os';
import path from 'node:path';
import assert from 'node:assert/strict';

export async function startTestSMTP(root){
 const generated=spawnSync('php',['tests/certificate.php'],{cwd:root,encoding:'utf8'});
 assert.equal(generated.status,0,generated.stderr);
 const {key,cert}=JSON.parse(generated.stdout),context=tls.createSecureContext({key,cert});
 const dir=mkdtempSync(path.join(tmpdir(),'tfv1-smtp-')),ca=path.join(dir,'localhost.crt');writeFileSync(ca,cert);
 const messages=[],sockets=new Set();
 const attach=socket=>{
  sockets.add(socket);socket.on('close',()=>sockets.delete(socket));socket.on('error',()=>{});
  let buffer='',data=false,body='',recipient='',auth=0;
  const receive=chunk=>{buffer+=chunk.toString();let pos;while((pos=buffer.indexOf('\r\n'))>=0){const line=buffer.slice(0,pos);buffer=buffer.slice(pos+2);
   if(data){if(line==='.') {messages.push({recipient,body});data=false;socket.write('250 Accepted\r\n')}else body+=line+'\n';continue}
   if(auth){auth--;socket.write(auth?'334 UGFzc3dvcmQ6\r\n':'235 Authenticated\r\n');continue}
   if(/^EHLO|^HELO/i.test(line))socket.write('250-localhost\r\n250-STARTTLS\r\n250 AUTH LOGIN\r\n');
   else if(/^STARTTLS/i.test(line)){socket.removeListener('data',receive);socket.write('220 Upgrade TLS\r\n',()=>attach(new tls.TLSSocket(socket,{isServer:true,secureContext:context})));return}
   else if(/^AUTH LOGIN/i.test(line)){auth=2;socket.write('334 VXNlcm5hbWU6\r\n')}
   else if(/^MAIL FROM/i.test(line))socket.write('250 OK\r\n');
   else if(/^RCPT TO/i.test(line)){recipient=line.match(/<([^>]+)>/)?.[1];socket.write('250 OK\r\n')}
   else if(/^DATA/i.test(line)){data=true;body='';socket.write('354 Send message\r\n')}
   else if(/^QUIT/i.test(line))socket.end('221 Bye\r\n');
   else socket.write('250 OK\r\n');
  }};
  socket.on('data',receive);
 };
 const server=net.createServer(socket=>{socket.write('220 localhost test SMTP\r\n');attach(socket)});
 await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
 return {ca,messages,env:{SMTP_HOST:'localhost',SMTP_PORT:String(server.address().port),SMTP_USERNAME:'test',SMTP_PASSWORD:'test-only',SMTP_FROM_EMAIL:'test@example.test',TF_APP_KEY:'test-only-'+ 'a'.repeat(32)},
  code(email){const message=messages.filter(m=>m.recipient===email).at(-1);assert.ok(message,`No email delivered for ${email}`);return message.body.match(/code is: (\d{6})/)?.[1]},
  async close(){for(const socket of sockets)socket.destroy();await new Promise(resolve=>server.close(resolve));unlinkSync(ca);rmdirSync(dir)}
 };
}
