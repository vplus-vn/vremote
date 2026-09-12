import React from 'react';
import { createRoot } from 'react-dom/client';
import './style.css';

type Device={deviceId:string;machineCode?:string|null;name?:string|null;status:string};
type Grant={sessionId:string;capability:string;signalingUrl:string;sessionToken:string;iceServers:RTCIceServer[];ttlSec:number};
const agentDownloadUrl=import.meta.env.VITE_AGENT_DOWNLOAD_URL??'/downloads/vremote-agent.apk';
const TOKEN_KEY='vremote_token';

// Auth is packaged into VRemote (like the S&B admin portal): the console logs in through
// VRemote's own origin (POST /api/vremote/v1/auth/login proxies to the Platform), stores the
// issued token, and sends it as Bearer on every API call. The backend resolves identity via
// the Platform (introspection), so this works with opaque Platform tokens.
function readToken():string{try{return localStorage.getItem(TOKEN_KEY)??sessionStorage.getItem(TOKEN_KEY)??''}catch{return ''}}
// remember=true -> persist across browser restarts (localStorage); false -> this session only.
function writeToken(t:string,remember=true){try{
 if(!t){localStorage.removeItem(TOKEN_KEY);sessionStorage.removeItem(TOKEN_KEY);return;}
 if(remember){localStorage.setItem(TOKEN_KEY,t);sessionStorage.removeItem(TOKEN_KEY);}
 else{sessionStorage.setItem(TOKEN_KEY,t);localStorage.removeItem(TOKEN_KEY);}
}catch{/* ignore */}}
// The Platform login response shape varies; pull the token from the usual places.
function extractToken(x:any):string{
 if(!x||typeof x!=='object')return '';
 const d=x.data??x.result??x;
 return String(d.accessToken??d.token??d.access_token??d.jwt??x.accessToken??x.token??x.access_token??'')||'';
}

function Login({onToken}:{onToken:(t:string)=>void}){
 const [username,setUsername]=React.useState(''); const [password,setPassword]=React.useState('');
 const [showPw,setShowPw]=React.useState(false); const [remember,setRemember]=React.useState(true);
 const [busy,setBusy]=React.useState(false); const [error,setError]=React.useState('');
 async function submit(e:React.FormEvent){e.preventDefault(); setBusy(true); setError('');
  try{
   const r=await fetch('/api/vremote/v1/auth/login',{method:'POST',headers:{'content-type':'application/json'},body:JSON.stringify({username,email:username,password})});
   const body=await r.json().catch(()=>({}));
   if(!r.ok){setError(body?.error?.message??body?.message??`Đăng nhập thất bại (HTTP ${r.status})`); return;}
   const token=extractToken(body);
   if(!token){setError('Đăng nhập OK nhưng không tìm thấy token trong phản hồi. Field nhận được: '+Object.keys(body?.data??body??{}).join(', ')); return;}
   writeToken(token,remember); onToken(token);
  }catch(err){setError(err instanceof Error?err.message:String(err));}
  finally{setBusy(false);}
 }
 return <main className="login-wrap"><form className="login-card" onSubmit={submit}>
   <h1>VRemote</h1><span className="sub">Remote Edge Monitor</span>
   {error&&<div className="error">{error}</div>}
   <label>Tài khoản<input value={username} onChange={e=>setUsername(e.target.value)} autoFocus autoComplete="username" placeholder="email / tài khoản platform"/></label>
   <label>Mật khẩu
    <div className="pw-field">
     <input type={showPw?'text':'password'} value={password} onChange={e=>setPassword(e.target.value)} autoComplete="current-password"/>
     <button type="button" className="pw-toggle" onClick={()=>setShowPw(v=>!v)} aria-label={showPw?'Ẩn mật khẩu':'Hiện mật khẩu'} title={showPw?'Ẩn mật khẩu':'Hiện mật khẩu'}>{showPw?'🙈':'👁️'}</button>
    </div>
   </label>
   <label className="remember"><input type="checkbox" checked={remember} onChange={e=>setRemember(e.target.checked)}/> Ghi nhớ đăng nhập</label>
   <button type="submit" disabled={busy||!username||!password}>{busy?'Đang đăng nhập…':'Đăng nhập'}</button>
   <p className="hint">Đăng nhập bằng tài khoản VPlus Platform.</p>
 </form></main>;
}

function App(){
 const [token,setToken]=React.useState<string>(readToken());
 const [devices,setDevices]=React.useState<Device[]>([]); const [error,setError]=React.useState(''); const [grant,setGrant]=React.useState<Grant|null>(null);
 const video=React.useRef<HTMLVideoElement>(null); const pc=React.useRef<RTCPeerConnection|null>(null); const ws=React.useRef<WebSocket|null>(null);

 const authedFetch=React.useCallback(async(input:string,init?:RequestInit)=>{
  const headers=new Headers(init?.headers); if(token)headers.set('Authorization',`Bearer ${token}`);
  const r=await fetch(input,{...init,headers});
  if(r.status===401){writeToken(''); setToken(''); throw Error('Phiên đăng nhập hết hạn, vui lòng đăng nhập lại.');}
  return r;
 },[token]);

 function logout(){close(); writeToken(''); setToken(''); setDevices([]);}

 React.useEffect(()=>{if(!token)return;
  authedFetch('/api/vremote/v1/devices').then(async r=>{if(!r.ok)throw Error(`HTTP ${r.status}`);return r.json()})
   .then(x=>setDevices(x.items??[])).catch(e=>setError(e instanceof Error?e.message:String(e)));
  return ()=>close();
 // eslint-disable-next-line react-hooks/exhaustive-deps
 },[token]);

 async function view(d:Device){close(); setError(''); try{
   const r=await authedFetch('/api/vremote/v1/sessions',{method:'POST',headers:{'content-type':'application/json'},body:JSON.stringify({deviceId:d.deviceId,capability:'view'})}); if(!r.ok)throw Error(await r.text()); const g:Grant=await r.json(); setGrant(g);
   const peer=new RTCPeerConnection({iceServers:g.iceServers}); pc.current=peer; peer.ontrack=e=>{if(video.current)video.current.srcObject=e.streams[0]};
   const sock=new WebSocket(g.signalingUrl); ws.current=sock;
   sock.onopen=()=>sock.send(JSON.stringify({type:'join',sessionToken:g.sessionToken}));
   sock.onmessage=async e=>{const m=JSON.parse(e.data); if(m.type==='joined'){const offer=await peer.createOffer({offerToReceiveVideo:true}); await peer.setLocalDescription(offer); if(sock.readyState===WebSocket.OPEN)sock.send(JSON.stringify({type:'offer',sessionId:g.sessionId,sdp:offer.sdp}));} else if(m.type==='answer'){await peer.setRemoteDescription({type:'answer',sdp:m.sdp});} else if(m.type==='candidate'&&m.candidate){await peer.addIceCandidate(m.candidate).catch(()=>{});} else if(m.type==='error'){setError(`Signaling: ${m.code}`)}};
   peer.onicecandidate=e=>{if(e.candidate&&sock.readyState===WebSocket.OPEN)sock.send(JSON.stringify({type:'candidate',sessionId:g.sessionId,candidate:e.candidate.toJSON()}));};
 }catch(e){setError(e instanceof Error?e.message:String(e));}}
 function close(){const currentGrant=grant;if(ws.current){try{ws.current.close()}catch{}ws.current=null} if(pc.current){pc.current.close();pc.current=null} if(currentGrant){void authedFetch(`/api/vremote/v1/sessions/${currentGrant.sessionId}`,{method:'DELETE'}).catch(()=>{});setGrant(null)}}

 if(!token)return <Login onToken={t=>{setError(''); setToken(t);}}/>;

 return <main><header><div><h1>VRemote</h1><span>Remote Edge Monitor</span></div><div className="header-actions"><a className="download" href={agentDownloadUrl}>Download Android Agent</a><button className="logout" onClick={logout}>Đăng xuất</button></div></header>{error&&<div className="error">{error}</div>}<section><h2>Devices</h2><table><thead><tr><th>Device</th><th>Machine</th><th>Status</th><th>Action</th></tr></thead><tbody>{devices.map(d=><tr key={d.deviceId}><td>{d.deviceId}</td><td>{d.machineCode??'—'}</td><td>{d.status}</td><td><button disabled={d.status!=='online'} onClick={()=>void view(d)}>View</button></td></tr>)}{!devices.length&&<tr><td colSpan={4}>Chưa có thiết bị.</td></tr>}</tbody></table></section>{grant&&<section><div className="viewer-head"><h2>Session {grant.sessionId}</h2><button onClick={()=>close()}>Close</button></div><video ref={video} autoPlay playsInline controls={false}/></section>}</main>;
}
createRoot(document.getElementById('root')!).render(<React.StrictMode><App/></React.StrictMode>);
