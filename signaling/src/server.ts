import { createServer } from 'node:http';
import { createHmac, randomUUID, timingSafeEqual } from 'node:crypto';
import { WebSocketServer, WebSocket } from 'ws';
import { MongoClient } from 'mongodb';

const port=Number(process.env.PORT??8787), internalPort=Number(process.env.INTERNAL_PORT??8788);
const sessionSecret=process.env.VREMOTE_SESSION_SECRET??'', internalSecret=process.env.VREMOTE_SIGNALING_INTERNAL_SECRET??'';
// Transitional static map (baseline). Device-initiated/enrolled devices are looked up in Mongo.
const devices:Record<string,{deviceToken?:string}>=JSON.parse(process.env.VREMOTE_DEVICES_JSON??'{}');

// ---- Optional MongoDB device-token source (matches the backend `devices` collection) ----
// When MONGODB_* is configured, an agent's deviceToken is verified against the shared registry,
// so devices provisioned by enrollment or by device-initiated pairing can connect. Without it,
// only the static VREMOTE_DEVICES_JSON map is used (unchanged baseline behavior).
// Resolve Laravel-style ${SERVICES} interpolation so the signaling service can read the
// SAME backend .env directly (single source of truth), where MONGODB_DATABASE etc. are
// written as "${SERVICES}". systemd/Node don't expand ${...}; we do it here.
const SVC=process.env.SERVICES??'vremote';
const sub=(v?:string)=>(v??'').replace(/\$\{SERVICES\}/g,SVC);
const mongoDb=sub(process.env.MONGODB_DATABASE)||SVC;
function mongoUri():string|null{
 if(process.env.MONGODB_URI)return process.env.MONGODB_URI;
 const host=process.env.MONGODB_HOST; if(!host)return null;
 const port=process.env.MONGODB_PORT??'27017';
 const user=sub(process.env.MONGODB_USERNAME)||'', pass=process.env.MONGODB_PASSWORD;
 const authDb=sub(process.env.MONGODB_AUTHENTICATION_DATABASE)||mongoDb;
 const cred=user?`${encodeURIComponent(user)}:${encodeURIComponent(pass??'')}@`:'';
 const auth=user?`?authSource=${encodeURIComponent(authDb)}`:'';
 return `mongodb://${cred}${host}:${port}/${encodeURIComponent(mongoDb)}${auth}`;
}
let mongoTokens:{get(id:string):Promise<string|null>}|null=null;
(function initMongo(){
 const uri=mongoUri(); if(!uri){console.log('VRemote signaling: Mongo device lookup disabled (no MONGODB_* configured)');return;}
 // Log the resolved target (host:port/db) with credentials stripped, so a wrong port
 // (e.g. a stale 27017 overriding the backend .env's real port) is obvious in the journal.
 const redacted=uri.replace(/\/\/[^@]*@/,'//');console.log(`VRemote signaling: Mongo target ${redacted}`);
 const client=new MongoClient(uri,{serverSelectionTimeoutMS:3000});
 const cache=new Map<string,{token:string|null;at:number}>(); const TTL=15000;
 let ready=false; client.connect().then(()=>{ready=true;console.log('VRemote signaling: Mongo device lookup enabled')}).catch(e=>console.error('VRemote signaling: Mongo connect failed',e?.message??e));
 mongoTokens={async get(id){
  const hit=cache.get(id); if(hit&&Date.now()-hit.at<TTL)return hit.token;
  if(!ready)return null;
  try{const doc=await client.db(mongoDb).collection('devices').findOne({_id:id as any},{projection:{deviceToken:1}});
   const token=doc&&typeof (doc as any).deviceToken==='string'?(doc as any).deviceToken:null; cache.set(id,{token,at:Date.now()}); return token;}
  catch{return null}
 }};
})();

type Client={socket:WebSocket;role:'viewer'|'agent';sessionId?:string;deviceId?:string;clientId:string};
const sessions=new Map<string,Set<Client>>(),agents=new Map<string,Client>();
const b64=(v:Buffer|string)=>Buffer.from(v).toString('base64url');
function verifyViewer(token:string){if(!sessionSecret)return null;const [body,sig]=token.split('.');if(!body||!sig)return null;const expected=b64(createHmac('sha256',sessionSecret).update(body).digest());if(sig.length!==expected.length||!timingSafeEqual(Buffer.from(sig),Buffer.from(expected)))return null;try{const p=JSON.parse(Buffer.from(body,'base64url').toString());return p.exp>Math.floor(Date.now()/1000)?p:null}catch{return null}}
function eqToken(a:string,b:string){return !!a&&!!b&&a.length===b.length&&timingSafeEqual(Buffer.from(a),Buffer.from(b))}
async function verifyAgent(deviceId:string,token:string){const staticTok=devices[deviceId]?.deviceToken??'';if(staticTok&&eqToken(token,staticTok))return true;const dbTok=mongoTokens?await mongoTokens.get(deviceId):null;return !!dbTok&&eqToken(token,dbTok)}
function send(c:Client,v:unknown){if(c.socket.readyState===WebSocket.OPEN)c.socket.send(JSON.stringify(v))}
function cleanup(c:Client){if(c.deviceId&&agents.get(c.deviceId)===c)agents.delete(c.deviceId);if(c.sessionId){const s=sessions.get(c.sessionId);s?.delete(c);if(!s?.size)sessions.delete(c.sessionId)}}
const server=createServer((req,res)=>{if(req.method==='GET'&&req.url==='/health'){res.setHeader('content-type','application/json');res.end(JSON.stringify({status:'ok',service:'vremote-signaling'}));return}res.statusCode=404;res.end()});
const wss=new WebSocketServer({noServer:true});
server.on('upgrade',(req,socket,head)=>{if(req.url!=='/signaling'&&req.url!=='/agent'){socket.destroy();return}wss.handleUpgrade(req,socket,head,ws=>wss.emit('connection',ws,req))});
wss.on('connection',(socket,req)=>{const path=new URL(req.url??'/',`http://${req.headers.host??'localhost'}`).pathname;const role=path==='/agent'?'agent':'viewer';const c:Client={socket,role,clientId:randomUUID()};
 socket.on('message',raw=>{let m:any;try{m=JSON.parse(raw.toString())}catch{socket.close(1007,'invalid_json');return}
  if(role==='agent'){
   if(!c.deviceId){if(m.type!=='agent.auth'||typeof m.deviceId!=='string'||typeof m.deviceToken!=='string'){socket.close(1008,'unauthorized');return}
    void verifyAgent(m.deviceId,m.deviceToken).then(ok=>{if(!ok){console.log(`agent ${m.deviceId} auth UNAUTHORIZED (token rejected; mongo=${mongoTokens?'on':'off'})`);socket.close(1008,'unauthorized');return}if(agents.has(m.deviceId))agents.get(m.deviceId)?.socket.close(4001,'replaced');c.deviceId=m.deviceId;agents.set(m.deviceId,c);console.log(`agent ${m.deviceId} CONNECTED (agents online=${agents.size})`);send(c,{type:'agent.authenticated'})}).catch(e=>{console.log(`agent ${m.deviceId} auth ERROR ${e?.message??e}`);socket.close(1011,'server_error')});return}
   if(m.type==='agent.hello'){send(c,{type:'agent.hello.ack',deviceId:c.deviceId});return}
   if(m.type==='agent.signaling'&&typeof m.sessionId==='string'&&m.sessionId.length<=128){const s=sessions.get(m.sessionId);if(!s)return;for(const peer of s)if(peer.role==='viewer')send(peer,m.payload);return}
   if(m.type==='telemetry')return;return;
  }
  if(!c.sessionId){if(m.type!=='join'||typeof m.sessionToken!=='string'){socket.close(1008,'join_required');return}const p=verifyViewer(m.sessionToken);if(!p?.sid||!p?.did||!p?.cap){send(c,{type:'error',code:'unauthorized'});socket.close(1008,'unauthorized');return}c.sessionId=p.sid;c.deviceId=p.did;let s=sessions.get(p.sid);if(!s){s=new Set();sessions.set(p.sid,s)}s.add(c);send(c,{type:'joined',sessionId:p.sid,clientId:c.clientId,capability:p.cap});return}
  if(m.sessionId!==c.sessionId||!['offer','answer','candidate','leave'].includes(m.type))return;const agent=c.deviceId?agents.get(c.deviceId):undefined;if(agent)send(agent,{type:'agent.signaling',sessionId:c.sessionId,payload:m});if(m.type==='leave')cleanup(c);
 });socket.on('close',()=>cleanup(c));
});
const internal=createServer((req,res)=>{if(req.method!=='POST'||req.url!=='/agent/send'){res.statusCode=404;res.end();return}const supplied=String(req.headers['x-vremote-internal-secret']??'');if(!internalSecret||supplied.length!==internalSecret.length||!timingSafeEqual(Buffer.from(supplied),Buffer.from(internalSecret))){res.statusCode=401;res.end(JSON.stringify({ok:false}));return}let body='';req.on('data',d=>{if(body.length<1024*1024)body+=d});req.on('end',()=>{try{const m=JSON.parse(body);if(typeof m.deviceId!=='string'){res.statusCode=400;res.end();return}const a=agents.get(m.deviceId);if(!a){console.log(`dispatch ${m.type??''} -> ${m.deviceId}: AGENT OFFLINE (not connected to signaling; agents online=${agents.size})`);res.statusCode=503;res.end(JSON.stringify({ok:false,reason:'agent_offline'}));return}console.log(`dispatch ${m.type??''} -> ${m.deviceId}: sent`);send(a,m);res.setHeader('content-type','application/json');res.end(JSON.stringify({ok:true}))}catch{res.statusCode=400;res.end(JSON.stringify({ok:false}))}})});
server.listen(port,()=>console.log(`VRemote signaling :${port}`));internal.listen(internalPort,()=>console.log(`VRemote internal :${internalPort}`));
