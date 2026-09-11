import React from 'react';
import { createRoot } from 'react-dom/client';
import './style.css';

type Device={deviceId:string;machineCode?:string|null;name?:string|null;status:string};
type Grant={sessionId:string;capability:string;signalingUrl:string;sessionToken:string;iceServers:RTCIceServer[];ttlSec:number};

function App(){
 const [devices,setDevices]=React.useState<Device[]>([]); const [error,setError]=React.useState(''); const [grant,setGrant]=React.useState<Grant|null>(null); const video=React.useRef<HTMLVideoElement>(null); const pc=React.useRef<RTCPeerConnection|null>(null); const ws=React.useRef<WebSocket|null>(null);
 React.useEffect(()=>{fetch('/api/v1/devices').then(async r=>{if(!r.ok)throw Error(`HTTP ${r.status}`);return r.json()}).then(x=>setDevices(x.items??[])).catch(e=>setError(e.message)); return ()=>close();},[]);
 async function view(d:Device){close(); setError(''); try{
   const r=await fetch('/api/v1/sessions',{method:'POST',headers:{'content-type':'application/json'},body:JSON.stringify({deviceId:d.deviceId,capability:'view'})}); if(!r.ok)throw Error(await r.text()); const g:Grant=await r.json(); setGrant(g);
   const peer=new RTCPeerConnection({iceServers:g.iceServers}); pc.current=peer; peer.ontrack=e=>{if(video.current)video.current.srcObject=e.streams[0]};
   const sock=new WebSocket(g.signalingUrl); ws.current=sock;
   sock.onopen=()=>sock.send(JSON.stringify({type:'join',sessionToken:g.sessionToken}));
   sock.onmessage=async e=>{const m=JSON.parse(e.data); if(m.type==='joined'){const offer=await peer.createOffer({offerToReceiveVideo:true}); await peer.setLocalDescription(offer); sock.send(JSON.stringify({type:'offer',sessionId:g.sessionId,sdp:offer.sdp}));} else if(m.type==='answer'){await peer.setRemoteDescription({type:'answer',sdp:m.sdp});} else if(m.type==='candidate'&&m.candidate){await peer.addIceCandidate(m.candidate).catch(()=>{});} else if(m.type==='error'){setError(`Signaling: ${m.code}`)}};
   peer.onicecandidate=e=>{if(e.candidate&&sock.readyState===WebSocket.OPEN)sock.send(JSON.stringify({type:'candidate',sessionId:g.sessionId,candidate:e.candidate.toJSON()}));};
 }catch(e){setError(e instanceof Error?e.message:String(e));}}
 async function close(){if(ws.current){try{ws.current.close()}catch{}ws.current=null} if(pc.current){pc.current.close();pc.current=null} if(grant){await fetch(`/api/v1/sessions/${grant.sessionId}`,{method:'DELETE'}).catch(()=>{});setGrant(null)}}
 return <main><header><h1>VRemote</h1><span>Remote Edge Monitor</span></header>{error&&<div className="error">{error}</div>}<section><h2>Devices</h2><table><thead><tr><th>Device</th><th>Machine</th><th>Status</th><th>Action</th></tr></thead><tbody>{devices.map(d=><tr key={d.deviceId}><td>{d.deviceId}</td><td>{d.machineCode??'—'}</td><td>{d.status}</td><td><button disabled={d.status!=='online'} onClick={()=>view(d)}>View</button></td></tr>)}{!devices.length&&<tr><td colSpan={4}>Chưa có thiết bị.</td></tr>}</tbody></table></section>{grant&&<section><div className="viewer-head"><h2>Session {grant.sessionId}</h2><button onClick={close}>Close</button></div><video ref={video} autoPlay playsInline controls={false}/></section>}</main>
}
createRoot(document.getElementById('root')!).render(<React.StrictMode><App/></React.StrictMode>);
