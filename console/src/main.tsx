import React from 'react';
import { createRoot } from 'react-dom/client';
import './style.css';

type Device={deviceId:string;pairingId?:string|null;machineCode?:string|null;name?:string|null;status:string;enrollmentState?:string|null};
type Enroll={enrollmentId:string;code:string;token:string;expiresAt:number;qrUri:string;deviceId:string};
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

// Server-side device onboarding: an admin creates the device row here, then the agent
// completes enrollment on the box using the one-time code below.
function RegisterForm({onSubmit,onCancel}:{onSubmit:(machineCode:string,name:string,deviceId:string,tenantId:string)=>void;onCancel:()=>void}){
 const [machineCode,setMachineCode]=React.useState(''); const [name,setName]=React.useState(''); const [deviceId,setDeviceId]=React.useState(''); const [tenantId,setTenantId]=React.useState('');
 return <form className="reg-form" onSubmit={e=>{e.preventDefault(); onSubmit(machineCode.trim(),name.trim(),deviceId.trim(),tenantId.trim());}}>
   <div className="reg-grid">
    <label>Machine code *<input value={machineCode} onChange={e=>setMachineCode(e.target.value)} autoFocus placeholder="mã máy / serial thiết bị"/></label>
    <label>Tên<input value={name} onChange={e=>setName(e.target.value)} placeholder="tên hiển thị (tuỳ chọn)"/></label>
    <label>Device ID<input value={deviceId} onChange={e=>setDeviceId(e.target.value)} placeholder="để trống = tự sinh"/></label>
    <label>Tenant ID<input value={tenantId} onChange={e=>setTenantId(e.target.value)} placeholder="để trống = tenant của bạn"/></label>
   </div>
   <div className="reg-actions"><button type="submit" disabled={!machineCode.trim()}>Đăng ký &amp; tạo mã</button><button type="button" className="ghost" onClick={onCancel}>Huỷ</button></div>
 </form>;
}

function EnrollBox({enroll,onClose}:{enroll:Enroll;onClose:()=>void}){
 const expires=new Date(enroll.expiresAt*1000).toLocaleString();
 return <div className="enroll-box"><div className="enroll-head"><h3>Mã ghi danh — {enroll.deviceId}</h3><button className="ghost" onClick={onClose}>Đóng</button></div>
   <p className="hint">Nhập mã này trên app VRemote Agent của thiết bị. Chỉ hiển thị một lần, hết hạn lúc {expires}.</p>
   <dl className="enroll-fields">
    <dt>Enrollment ID</dt><dd><code>{enroll.enrollmentId}</code></dd>
    <dt>Code</dt><dd><code>{enroll.code}</code></dd>
    <dt>Token</dt><dd><code className="wrap">{enroll.token}</code></dd>
    <dt>QR URI</dt><dd><code className="wrap">{enroll.qrUri}</code></dd>
   </dl>
 </div>;
}

// UltraViewer/TeamViewer-style "control another machine": enter the device's ID + password.
function ConnectByIdForm({onConnect}:{onConnect:(pairingId:string,password:string)=>void}){
 const [pairingId,setPairingId]=React.useState(''); const [password,setPassword]=React.useState('');
 return <form className="connect-form" onSubmit={e=>{e.preventDefault(); onConnect(pairingId.trim(),password);}}>
   <label>ID đối tác<input value={pairingId} onChange={e=>setPairingId(e.target.value)} inputMode="numeric" placeholder="VD: 838 824 564"/></label>
   <label>Mật khẩu<input type="password" value={password} onChange={e=>setPassword(e.target.value)} placeholder="mật khẩu phiên / mật khẩu riêng" autoComplete="off"/></label>
   <button type="submit" className="primary" disabled={!pairingId.trim()||!password}>Kết nối</button>
 </form>;
}

function App(){
 const [token,setToken]=React.useState<string>(readToken());
 const [devices,setDevices]=React.useState<Device[]>([]); const [error,setError]=React.useState(''); const [grant,setGrant]=React.useState<Grant|null>(null);
 const [showReg,setShowReg]=React.useState(false); const [enroll,setEnroll]=React.useState<Enroll|null>(null);
 const video=React.useRef<HTMLVideoElement>(null); const pc=React.useRef<RTCPeerConnection|null>(null); const ws=React.useRef<WebSocket|null>(null);

 const authedFetch=React.useCallback(async(input:string,init?:RequestInit)=>{
  const headers=new Headers(init?.headers); if(token)headers.set('Authorization',`Bearer ${token}`);
  const r=await fetch(input,{...init,headers});
  if(r.status===401){writeToken(''); setToken(''); throw Error('Phiên đăng nhập hết hạn, vui lòng đăng nhập lại.');}
  return r;
 },[token]);

 const refresh=React.useCallback(async()=>{
  try{const r=await authedFetch('/api/vremote/v1/devices'); if(!r.ok)throw Error(`HTTP ${r.status}`); const x=await r.json(); setDevices(x.items??[]);}
  catch(e){setError(e instanceof Error?e.message:String(e));}
 },[authedFetch]);

 function logout(){close(); writeToken(''); setToken(''); setDevices([]); setShowReg(false); setEnroll(null);}

 // Register a device from the server, then immediately mint its first enroll code.
 async function registerDevice(machineCode:string,name:string,deviceId:string,tenantId:string){setError('');
  try{
   const body:Record<string,string>={machineCode}; if(name)body.name=name; if(deviceId)body.deviceId=deviceId; if(tenantId)body.tenantId=tenantId;
   const r=await authedFetch('/api/vremote/v1/devices',{method:'POST',headers:{'content-type':'application/json'},body:JSON.stringify(body)});
   const d=await r.json().catch(()=>({})); if(!r.ok)throw Error(d?.error?.message??`HTTP ${r.status}`);
   setShowReg(false); await refresh(); await genEnroll(String(d.deviceId));
  }catch(e){setError(e instanceof Error?e.message:String(e));}
 }
 // Generate a one-time enrollment code+token for the agent (manual method).
 async function genEnroll(deviceId:string){setError('');
  try{
   const r=await authedFetch(`/api/vremote/v1/devices/${encodeURIComponent(deviceId)}/enrollments`,{method:'POST',headers:{'content-type':'application/json'},body:JSON.stringify({method:'manual'})});
   const d=await r.json().catch(()=>({})); if(!r.ok)throw Error(d?.error?.message??`HTTP ${r.status}`);
   setEnroll({...d,deviceId});
  }catch(e){setError(e instanceof Error?e.message:String(e));}
 }

 // Delete a device row (cleanup / de-registration).
 async function deleteDevice(deviceId:string){
  if(!window.confirm(`Xóa thiết bị ${deviceId}? Không thể hoàn tác.`))return;
  setError('');
  try{const r=await authedFetch(`/api/vremote/v1/devices/${encodeURIComponent(deviceId)}`,{method:'DELETE'}); if(!r.ok){const d=await r.json().catch(()=>({}));throw Error(d?.error?.message??`HTTP ${r.status}`);} await refresh();}
  catch(e){setError(e instanceof Error?e.message:String(e));}
 }
 // Bulk-remove offline devices (e.g. leftover junk from the old ID-regeneration bug).
 async function cleanupOffline(){
  const offline=devices.filter(d=>d.status!=='online');
  if(!offline.length){setError('Không có máy offline để xóa.');return;}
  if(!window.confirm(`Xóa ${offline.length} thiết bị đang offline? Không thể hoàn tác.`))return;
  setError('');
  for(const d of offline){try{await authedFetch(`/api/vremote/v1/devices/${encodeURIComponent(d.deviceId)}`,{method:'DELETE'});}catch(e){/* skip */}}
  await refresh();
 }

 // Connect to a device by its ID + password (UltraViewer/TeamViewer style). Waits for the
 // device to accept when it requires accept, then opens the view with the connect grant.
 async function connectById(pairingId:string,password:string){close(); setError('');
  try{
   const r=await authedFetch('/api/vremote/v1/devices/connect',{method:'POST',headers:{'content-type':'application/json'},body:JSON.stringify({pairingId,password})});
   const d=await r.json().catch(()=>({})); if(!r.ok)throw Error(d?.error?.message??`HTTP ${r.status}`);
   if(d.status==='ready'){await view(String(d.deviceId),String(d.connectGrant)); return;}
   // awaiting on-device accept — poll until ready / rejected / timeout.
   setError('Đang chờ thiết bị chấp nhận…');
   const deviceId=String(d.deviceId), requestId=String(d.requestId); const deadline=Date.now()+130000;
   while(Date.now()<deadline){
    await new Promise(res=>setTimeout(res,1500));
    const pr=await authedFetch(`/api/vremote/v1/devices/${encodeURIComponent(deviceId)}/connect/${encodeURIComponent(requestId)}`);
    const pd=await pr.json().catch(()=>({}));
    if(pr.ok&&pd.status==='ready'){setError(''); await view(deviceId,String(pd.connectGrant)); return;}
    if(!pr.ok){throw Error(pd?.error?.message??'Thiết bị từ chối hoặc hết hạn.');}
   }
   throw Error('Hết thời gian chờ thiết bị chấp nhận.');
  }catch(e){setError(e instanceof Error?e.message:String(e));}
 }

 React.useEffect(()=>{if(!token)return;
  void refresh();
  return ()=>close();
 // eslint-disable-next-line react-hooks/exhaustive-deps
 },[token]);

 async function view(deviceId:string,connectGrant?:string){close(); setError(''); try{
   const body:Record<string,unknown>={deviceId,capability:'view'}; if(connectGrant)body.connectGrant=connectGrant;
   const r=await authedFetch('/api/vremote/v1/sessions',{method:'POST',headers:{'content-type':'application/json'},body:JSON.stringify(body)}); if(!r.ok)throw Error(await r.text()); const g:Grant=await r.json(); setGrant(g);
   const peer=new RTCPeerConnection({iceServers:g.iceServers}); pc.current=peer; peer.ontrack=e=>{if(video.current)video.current.srcObject=e.streams[0]};
   const sock=new WebSocket(g.signalingUrl); ws.current=sock;
   sock.onopen=()=>sock.send(JSON.stringify({type:'join',sessionToken:g.sessionToken}));
   sock.onmessage=async e=>{const m=JSON.parse(e.data); if(m.type==='joined'){const offer=await peer.createOffer({offerToReceiveVideo:true}); await peer.setLocalDescription(offer); if(sock.readyState===WebSocket.OPEN)sock.send(JSON.stringify({type:'offer',sessionId:g.sessionId,sdp:offer.sdp}));} else if(m.type==='answer'){await peer.setRemoteDescription({type:'answer',sdp:m.sdp});} else if(m.type==='candidate'&&m.candidate){await peer.addIceCandidate(m.candidate).catch(()=>{});} else if(m.type==='error'){setError(`Signaling: ${m.code}`)}};
   peer.onicecandidate=e=>{if(e.candidate&&sock.readyState===WebSocket.OPEN)sock.send(JSON.stringify({type:'candidate',sessionId:g.sessionId,candidate:e.candidate.toJSON()}));};
 }catch(e){setError(e instanceof Error?e.message:String(e));}}
 function close(){const currentGrant=grant;if(ws.current){try{ws.current.close()}catch{}ws.current=null} if(pc.current){pc.current.close();pc.current=null} if(currentGrant){void authedFetch(`/api/vremote/v1/sessions/${currentGrant.sessionId}`,{method:'DELETE'}).catch(()=>{});setGrant(null)}}

 if(!token)return <Login onToken={t=>{setError(''); setToken(t);}}/>;

 return <main><header><div><h1>VRemote</h1><span>Remote Edge Monitor</span></div><div className="header-actions"><a className="download" href="/webagent.html" target="_blank" rel="noreferrer">Agent Web (máy này)</a><a className="download" href={agentDownloadUrl}>Download Android Agent</a><button className="logout" onClick={logout}>Đăng xuất</button></div></header>{error&&<div className="error">{error}</div>}<section><h2>Điều khiển máy khác</h2><p className="hint">Nhập ID và mật khẩu hiển thị trên máy cần xem (VRemote Agent).</p><ConnectByIdForm onConnect={(id,pw)=>void connectById(id,pw)}/></section><section><div className="section-head"><h2>Devices</h2><div className="section-actions"><button onClick={()=>void refresh()}>Tải lại</button><button className="ghost" onClick={()=>void cleanupOffline()}>Xóa máy offline</button><button className="primary" onClick={()=>{setEnroll(null); setShowReg(v=>!v);}}>{showReg?'Đóng':'+ Đăng ký thiết bị'}</button></div></div>{showReg&&<RegisterForm onSubmit={registerDevice} onCancel={()=>setShowReg(false)}/>}{enroll&&<EnrollBox enroll={enroll} onClose={()=>setEnroll(null)}/>}<table><thead><tr><th>ID</th><th>Device</th><th>Machine</th><th>Enrollment</th><th>Status</th><th>Action</th></tr></thead><tbody>{devices.map(d=><tr key={d.deviceId}><td>{d.pairingId??'—'}</td><td>{d.deviceId}</td><td>{d.machineCode??'—'}</td><td>{d.enrollmentState??'—'}</td><td>{d.status}</td><td className="row-actions"><button disabled={d.status!=='online'} onClick={()=>void view(d.deviceId)}>View</button>{d.enrollmentState!=='ACTIVE'&&<button className="ghost" onClick={()=>void genEnroll(d.deviceId)}>Mã ghi danh</button>}<button className="ghost danger" onClick={()=>void deleteDevice(d.deviceId)}>Xóa</button></td></tr>)}{!devices.length&&<tr><td colSpan={6}>Chưa có thiết bị. Bấm “+ Đăng ký thiết bị” để thêm.</td></tr>}</tbody></table></section>{grant&&<section><div className="viewer-head"><h2>Session {grant.sessionId}</h2><button onClick={()=>close()}>Close</button></div><video ref={video} autoPlay playsInline controls={false}/></section>}</main>;
}
createRoot(document.getElementById('root')!).render(<React.StrictMode><App/></React.StrictMode>);
