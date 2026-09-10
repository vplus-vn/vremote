import { WebSocketServer, WebSocket } from 'ws';
import { randomUUID } from 'node:crypto';

const port = Number(process.env.PORT ?? 8787);
const sessions = new Map<string, Set<WebSocket>>();

const server = new WebSocketServer({ port });
server.on('connection', (socket) => {
  let sessionId: string | undefined;
  let clientId = randomUUID();
  socket.on('message', (raw) => {
    let message: { type?: string; sessionId?: string };
    try { message = JSON.parse(raw.toString()) as typeof message; } catch { socket.close(1007, 'invalid_json'); return; }
    if (!message.sessionId || typeof message.type !== 'string') { socket.close(1008, 'invalid_message'); return; }
    if (message.type === 'join') {
      sessionId = message.sessionId;
      let peers = sessions.get(sessionId);
      if (!peers) { peers = new Set(); sessions.set(sessionId, peers); }
      peers.add(socket);
      socket.send(JSON.stringify({ type: 'joined', sessionId, clientId }));
      return;
    }
    if (!sessionId || message.sessionId !== sessionId) return;
    const peers = sessions.get(sessionId);
    peers?.forEach(peer => { if (peer !== socket && peer.readyState === WebSocket.OPEN) peer.send(raw.toString()); });
  });
  socket.on('close', () => { if (sessionId) { const peers = sessions.get(sessionId); peers?.delete(socket); if (!peers?.size) sessions.delete(sessionId); } });
});
console.log(`VRemote signaling listening on :${port}`);
