import { createServer } from 'node:http';
import { createHmac, randomUUID, timingSafeEqual } from 'node:crypto';
import { WebSocketServer, WebSocket } from 'ws';

const port = Number(process.env.PORT ?? 8787);
const internalPort = Number(process.env.INTERNAL_PORT ?? 8788);
const sessionSecret = process.env.VREMOTE_SESSION_SECRET ?? '';
const internalSecret = process.env.VREMOTE_SIGNALING_INTERNAL_SECRET ?? '';
const devices: Record<string, { deviceToken?: string }> = JSON.parse(process.env.VREMOTE_DEVICES_JSON ?? '{}');

type Client = { socket: WebSocket; role: 'viewer'|'agent'; sessionId?: string; deviceId?: string; clientId: string };
const sessions = new Map<string, Set<Client>>();
const agents = new Map<string, Client>();

function b64(v: Buffer|string) { return Buffer.from(v).toString('base64url'); }
function verifyViewer(token: string) {
  if (!sessionSecret) return null;
  const [body, sig] = token.split('.'); if (!body || !sig) return null;
  const expected = b64(createHmac('sha256', sessionSecret).update(body).digest());
  if (sig.length !== expected.length || !timingSafeEqual(Buffer.from(sig), Buffer.from(expected))) return null;
  try { const p = JSON.parse(Buffer.from(body, 'base64url').toString()); return p.exp > Math.floor(Date.now()/1000) ? p : null; } catch { return null; }
}
function verifyAgent(deviceId: string, token: string) { return !!devices[deviceId]?.deviceToken && devices[deviceId].deviceToken === token; }
function send(c: Client, value: unknown) { if (c.socket.readyState === WebSocket.OPEN) c.socket.send(JSON.stringify(value)); }
function peers(c: Client) { return c.sessionId ? [...(sessions.get(c.sessionId) ?? [])].filter(x=>x!==c) : []; }

const server = createServer();
const wss = new WebSocketServer({ noServer: true });
server.on('upgrade', (req, socket, head) => {
  if (req.url !== '/signaling' && req.url !== '/agent') { socket.destroy(); return; }
  wss.handleUpgrade(req, socket, head, ws => wss.emit('connection', ws, req));
});

wss.on('connection', (socket, req) => {
  const url = new URL(req.url ?? '/', `http://${req.headers.host ?? 'localhost'}`);
  const role = url.pathname === '/agent' ? 'agent' : 'viewer';
  const client: Client = { socket, role, clientId: randomUUID() };
  socket.on('message', raw => {
    let m: any; try { m=JSON.parse(raw.toString()); } catch { socket.close(1007,'invalid_json'); return; }
    if (role === 'agent') {
      if (!client.deviceId) {
        if (m.type !== 'agent.auth' || typeof m.deviceId !== 'string' || !verifyAgent(m.deviceId, m.deviceToken)) { socket.close(1008,'unauthorized'); return; }
        client.deviceId=m.deviceId; agents.set(m.deviceId, client); send(client,{type:'agent.authenticated'}); return;
      }
      if (m.type === 'agent.hello') { send(client,{type:'agent.hello.ack',deviceId:client.deviceId}); return; }
      if (m.type === 'agent.signaling' && typeof m.sessionId === 'string') {
        const s=sessions.get(m.sessionId) ?? new Set<Client>(); for (const peer of s) if(peer.role==='viewer') send(peer,m.payload); return;
      }
      if (m.type === 'telemetry') return;
      return;
    }
    if (!client.sessionId) {
      if (m.type !== 'join' || typeof m.sessionToken !== 'string') { socket.close(1008,'join_required'); return; }
      const p=verifyViewer(m.sessionToken); if(!p || !p.sid || !p.did) { send(client,{type:'error',code:'unauthorized'}); socket.close(1008,'unauthorized'); return; }
      client.sessionId=p.sid; client.deviceId=p.did; let s=sessions.get(p.sid); if(!s){s=new Set();sessions.set(p.sid,s);} s.add(client);
      send(client,{type:'joined',sessionId:p.sid,clientId:client.clientId}); return;
    }
    if (m.sessionId !== client.sessionId) return;
    if (!['offer','answer','candidate','leave'].includes(m.type)) return;
    const agent=client.deviceId ? agents.get(client.deviceId) : undefined;
    if (agent) send(agent,{type:'agent.signaling',sessionId:client.sessionId,payload:m});
    if (m.type==='leave') cleanup(client);
  });
  socket.on('close',()=>cleanup(client));
});
function cleanup(c:Client){ if(c.deviceId && agents.get(c.deviceId)===c) agents.delete(c.deviceId); if(c.sessionId){const s=sessions.get(c.sessionId);s?.delete(c);if(!s?.size)sessions.delete(c.sessionId);} }

const internal=createServer((req,res)=>{
  if(req.method!=='POST' || req.url!=='/agent/send' || req.headers['x-vremote-internal-secret']!==internalSecret){res.statusCode=404;res.end();return;}
  let body='';req.on('data',d=>body+=d);req.on('end',()=>{try{const m=JSON.parse(body);const a=agents.get(m.deviceId);if(!a){res.statusCode=503;res.end(JSON.stringify({ok:false}));return;}send(a,m);res.setHeader('content-type','application/json');res.end(JSON.stringify({ok:true}));}catch{res.statusCode=400;res.end();}});
});
server.listen(port,()=>console.log(`VRemote signaling listening on :${port}`));
internal.listen(internalPort,()=>console.log(`VRemote internal control listening on :${internalPort}`));
