import { createServer } from 'node:http';
import { createHmac, randomUUID, timingSafeEqual } from 'node:crypto';
import { WebSocketServer, WebSocket } from 'ws';

const port=Number(process.env.PORT??8787), internalPort=Number(process.env.INTERNAL_PORT??8788);
const sessionSecret=process.env.VREMOTE_SESSION_SECRET??'', internalSecret=process.env.VREMOTE_SIGNALING_INTERNAL_SECRET??'';
const devices:Record<string,{deviceToken?:string}>=JSON.parse(process.env.VREMOTE_DEVICES_JSON??'{}');
type Client={socket:WebSocket;role:'viewer'|'agent';sessionId?:string;deviceId?:string;clientId:string};
const sessions=new Map<string,Set<Client>>(),agents=new Map<string,Client>();
const b64=(v:Buffer|string)=>Buffer.from(v).toString('base64url');
function verifyViewer(token:string){if(!sessionSecret)return null;const [body,sig]=token.split('.');if(!body||!sig)return null;const expected=b64(createHmac('sha256',sessionSecret).update(body).digest());if(sig.length!==expected.length||!timingSafeEqual(Buffer.from(sig),Buffer.from(expected)))return null;try{const p=JSON.parse(Buffer.from(body,'base64url').toString());return p.exp>Math.floor(Date.now()/1000)?p:null}catch{return null}}
function verifyAgent(deviceId:string,token:string){const expected=devices[deviceId]?.deviceToken??'';return !!expected&&token.length===expected.length&&timingSafeEqual(Buffer.from(token),Buffer.from(expected))}
function send(c:Client,v:unknown){if(c.socket.readyState===WebSocket.OPEN)c.socket.send(JSON.stringify(v))}
function cleanup(c:Client){if(c.deviceId&&agents.get(c.deviceId)===c)agents.delete(c.deviceId);if(c.sessionId){const s=sessions.get(c.sessionId);s?.delete(c);if(!s?.size)sessions.delete(c.sessionId)}}
const server=createServer();const wss=new WebSocketServer({noServer:true});
server.on('upgrade',(req,socket,head)=>{if(req.url!=='/signaling'&&req.url!=='/agent'){socket.destroy();return}wss.handleUpgrade(req,socket,head,ws=>wss.emit('connection',ws,req))});
wss.on('connection',(socket,req)=>{const path=new URL(req.url??'/',`http://${req.headers.host??'localhost'}`).pathname;const role=path==='/agent'?'agent':'viewer';const c:Client={socket,role,clientId:randomUUID()};
 socket.on('message',raw=>{let m:any;try{m=JSON.parse(raw.toString())}catch{socket.close(1007,'invalid_json');return}
  if(role==='agent'){
   if(!c.deviceId){if(m.type!=='agent.auth'||typeof m.deviceId!=='string'||typeof m.deviceToken!=='string'||!verifyAgent(m.deviceId,m.deviceToken)){socket.close(1008,'unauthorized');return}if(agents.has(m.deviceId))agents.get(m.deviceId)?.socket.close(4001,'replaced');c.deviceId=m.deviceId;agents.set(m.deviceId,c);send(c,{type:'agent.authenticated'});return}
   if(m.type==='agent.hello'){send(c,{type:'agent.hello.ack',deviceId:c.deviceId});return}
   if(m.type==='agent.signaling'&&typeof m.sessionId==='string'&&m.sessionId.length<=128){const s=sessions.get(m.sessionId);if(!s)return;for(const peer of s)if(peer.role==='viewer')send(peer,m.payload);return}
   if(m.type==='telemetry'){return}return;
  }
  if(!c.sessionId){if(m.type!=='join'||typeof m.sessionToken!=='string'){socket.close(1008,'join_required');return}const p=verifyViewer(m.sessionToken);if(!p?.sid||!p?.did||!p?.cap){send(c,{type:'error',code:'unauthorized'});socket.close(1008,'unauthorized');return}c.sessionId=p.sid;c.deviceId=p.did;let s=sessions.get(p.sid);if(!s){s=new Set();sessions.set(p.sid,s)}s.add(c);send(c,{type:'joined',sessionId:p.sid,clientId:c.clientId,capability:p.cap});return}
  if(m.sessionId!==c.sessionId||!['offer','answer','candidate','leave'].includes(m.type))return;const agent=c.deviceId?agents.get(c.deviceId):undefined;if(agent)send(agent,{type:'agent.signaling',sessionId:c.sessionId,payload:m});if(m.type==='leave')cleanup(c);
 });socket.on('close',()=>cleanup(c));
});
const internal=createServer((req,res)=>{if(req.method!=='POST'||req.url!=='/agent/send'){res.statusCode=404;res.end();return}const supplied=String(req.headers['x-vremote-internal-secret']??'');if(!internalSecret||supplied.length!==internalSecret.length||!timingSafeEqual(Buffer.from(supplied),Buffer.from(internalSecret))){res.statusCode=401;res.end(JSON.stringify({ok:false}));return}let body='';req.on('data',d=>{if(body.length<1024*1024)body+=d});req.on('end',()=>{try{const m=JSON.parse(body);if(typeof m.deviceId!=='string'){res.statusCode=400;res.end();return}const a=agents.get(m.deviceId);if(!a){res.statusCode=503;res.end(JSON.stringify({ok:false,reason:'agent_offline'}));return}send(a,m);res.setHeader('content-type','application/json');res.end(JSON.stringify({ok:true}))}catch{res.statusCode=400;res.end(JSON.stringify({ok:false}))}})});
server.listen(port,()=>console.log(`VRemote signaling :${port}`));internal.listen(internalPort,()=>console.log(`VRemote internal :${internalPort}`));
