import React from 'react';
import { createRoot } from 'react-dom/client';
import './style.css';

type Device={deviceId:string;pairingId?:string|null;machineCode?:string|null;name?:string|null;status:string;enrollmentState?:string|null;createdAt?:number|null;lastSeenAt?:number|null;deviceInfo?:string|null;lastEvent?:string|null};
const PIN_KEY='vremote.pins'; const PIN_MAX=5;
function readPins():string[]{try{const v=JSON.parse(localStorage.getItem(PIN_KEY)||'[]'); return Array.isArray(v)?v.filter(x=>typeof x==='string').slice(0,PIN_MAX):[];}catch{return[];}}
// Device timestamps are epoch SECONDS (backend Clock::now():int). Render compact local date-time.
function fmtTime(sec?:number|null):string{ if(!sec||sec<=0)return '—'; try{return new Date(sec*1000).toLocaleString('vi-VN',{hour12:false});}catch{return '—';} }
type Enroll={enrollmentId:string;code:string;token:string;expiresAt:number;qrUri:string;deviceId:string};
type Grant={sessionId:string;capability:string;signalingUrl:string;sessionToken:string;iceServers:RTCIceServer[];ttlSec:number};
// Drop any turn:/turns: entry missing username+credential — the browser rejects the whole
// RTCPeerConnection ("Both username and credential are required...") if even one is malformed.
function iceSafe(servers:RTCIceServer[]):RTCIceServer[]{return (servers||[]).filter(s=>{const urls=Array.isArray(s.urls)?s.urls:[s.urls];const needsCred=urls.some(u=>/^turns?:/i.test(String(u)));return !needsCred||(!!s.username&&!!s.credential);});}
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
 // Pinned devices (up to PIN_MAX) are kept at the top; the rest are newest-first by createdAt.
 // Pins are per-browser (localStorage) — a viewer convenience, not shared state.
 // Resolve the current agent download from the manifest so the file the operator saves is the
 // version-stamped vremote_<version>.apk (distinguishable), and show the version on the button.
 const [agentDl,setAgentDl]=React.useState<{url:string;ver:string}>({url:agentDownloadUrl,ver:''});
 const [diagText,setDiagText]=React.useState<string|null>(null); const [copied,setCopied]=React.useState(false);
 React.useEffect(()=>{fetch('/downloads/vremote-agent.json',{cache:'no-store'}).then(r=>r.ok?r.json():null).then(j=>{if(j&&j.url)setAgentDl({url:String(j.url),ver:String(j.versionName??'')});}).catch(()=>{/* keep default */});},[]);
 const [pins,setPins]=React.useState<string[]>(()=>readPins());
 const [query,setQuery]=React.useState(''); const [statusFilter,setStatusFilter]=React.useState<'all'|'online'|'offline'>('all');
 const [page,setPage]=React.useState(1); const [pageSize,setPageSize]=React.useState(10);
 function togglePin(id:string){setError(''); setPins(prev=>{let next:string[]; if(prev.includes(id))next=prev.filter(x=>x!==id); else if(prev.length>=PIN_MAX){setError(`Chỉ ghim tối đa ${PIN_MAX} thiết bị. Bỏ ghim bớt trước.`); return prev;} else next=[...prev,id]; try{localStorage.setItem(PIN_KEY,JSON.stringify(next));}catch{/* ignore */} return next;});}
 // Pipeline: filter (search + status) -> sort (pinned first, then newest) -> paginate.
 const sortedDevices=React.useMemo(()=>{const q=query.trim().toLowerCase();
  const list=devices.filter(d=>{ if(statusFilter!=='all'&&d.status!==statusFilter)return false; if(!q)return true; return [d.pairingId,d.deviceId,d.machineCode,d.name].some(v=>String(v??'').toLowerCase().includes(q)); });
  const pi=(id:string)=>{const i=pins.indexOf(id); return i<0?Number.POSITIVE_INFINITY:i;};
  return list.sort((a,b)=>{const pa=pi(a.deviceId),pb=pi(b.deviceId); if(pa!==pb)return pa-pb; return (b.createdAt??0)-(a.createdAt??0);});},[devices,pins,query,statusFilter]);
 const totalPages=Math.max(1,Math.ceil(sortedDevices.length/pageSize)); const curPage=Math.min(page,totalPages);
 const pageItems=React.useMemo(()=>sortedDevices.slice((curPage-1)*pageSize,curPage*pageSize),[sortedDevices,curPage,pageSize]);
 React.useEffect(()=>{setPage(1);},[query,statusFilter,pageSize]);
 function showDiag(d:Device){const txt=`Thiết bị: ${d.pairingId??d.deviceId} (${d.machineCode??'—'})\nBreadcrumb/last event: ${d.lastEvent??'—'}\n\n${d.deviceInfo??'(chưa có thông tin thiết bị — mở app trên máy để nó tự gửi lên)'}`; setDiagText(txt); setCopied(false);}
 async function renameDevice(d:Device){setError(''); const cur=d.name??''; const name=window.prompt(`Đặt tên/alias cho thiết bị ${d.pairingId??d.deviceId}\n(Machine: ${d.machineCode??'—'})`,cur); if(name===null)return;
  try{const r=await authedFetch(`/api/vremote/v1/devices/${encodeURIComponent(d.deviceId)}`,{method:'PATCH',headers:{'content-type':'application/json'},body:JSON.stringify({name:name.trim()})}); if(!r.ok){const x=await r.json().catch(()=>({}));throw Error(x?.error?.message??`HTTP ${r.status}`);} await refresh();}
  catch(e){setError(e instanceof Error?e.message:String(e));}}
 const video=React.useRef<HTMLVideoElement>(null); const pc=React.useRef<RTCPeerConnection|null>(null); const ws=React.useRef<WebSocket|null>(null);
 const dc=React.useRef<RTCDataChannel|null>(null); const [wantControl,setWantControl]=React.useState(false); const [controlSession,setControlSession]=React.useState(false); const [textVal,setTextVal]=React.useState(''); const [iceState,setIceState]=React.useState('');
 // Send one normalized input event to the agent over the 'input' data channel (no-op unless open).
 const sendInput=React.useCallback((o:Record<string,unknown>)=>{const d=dc.current; if(d&&d.readyState==='open'){try{d.send(JSON.stringify(o))}catch{/* ignore */}}},[]);

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
 async function connectById(pairingId:string,password:string,capability:'view'|'control'='view'){close(); setError('');
  try{
   const r=await authedFetch('/api/vremote/v1/devices/connect',{method:'POST',headers:{'content-type':'application/json'},body:JSON.stringify({pairingId,password})});
   const d=await r.json().catch(()=>({})); if(!r.ok)throw Error(d?.error?.message??`HTTP ${r.status}`);
   if(d.status==='ready'){await view(String(d.deviceId),String(d.connectGrant),capability); return;}
   // awaiting on-device accept — poll until ready / rejected / timeout.
   setError('Đang chờ thiết bị chấp nhận…');
   const deviceId=String(d.deviceId), requestId=String(d.requestId); const deadline=Date.now()+130000;
   while(Date.now()<deadline){
    await new Promise(res=>setTimeout(res,1500));
    const pr=await authedFetch(`/api/vremote/v1/devices/${encodeURIComponent(deviceId)}/connect/${encodeURIComponent(requestId)}`);
    const pd=await pr.json().catch(()=>({}));
    if(pr.ok&&pd.status==='ready'){setError(''); await view(deviceId,String(pd.connectGrant),capability); return;}
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

 // Mouse+keyboard remote control over the video (only active for a control session). Maps a
 // Windows operator's mouse/keyboard to Android touch/keys via normalized coords on the input DC.
 React.useEffect(()=>{
  if(!grant||!controlSession)return; const v=video.current; if(!v)return;
  v.tabIndex=0; (v.style as CSSStyleDeclaration).touchAction='none'; const ft=setTimeout(()=>{try{v.focus()}catch{/* ignore */}},0);
  const clamp=(n:number)=>Math.min(1,Math.max(0,n));
  // Normalize a client point into the letterboxed (object-fit:contain) video content box; null if outside.
  const norm=(cx:number,cy:number):{x:number;y:number}|null=>{const r=v.getBoundingClientRect();const vw=v.videoWidth||0,vh=v.videoHeight||0;if(!vw||!vh||!r.width||!r.height)return null;const s=Math.min(r.width/vw,r.height/vh),dw=vw*s,dh=vh*s,ox=r.left+(r.width-dw)/2,oy=r.top+(r.height-dh)/2;const x=(cx-ox)/dw,y=(cy-oy)/dh;if(x<0||x>1||y<0||y>1)return null;return {x:clamp(x),y:clamp(y)};};
  let start:{x:number;y:number;t:number}|null=null,moved=false,longTimer:ReturnType<typeof setTimeout>|null=null,lastWheel=0;
  const down=(e:PointerEvent)=>{const c=norm(e.clientX,e.clientY);if(!c)return;try{v.focus()}catch{/* ignore */}start={...c,t:Date.now()};moved=false;try{v.setPointerCapture(e.pointerId)}catch{/* ignore */}if(longTimer)clearTimeout(longTimer);longTimer=setTimeout(()=>{if(start&&!moved){sendInput({t:'long',x:start.x,y:start.y});start=null;}},500);};
  const move=(e:PointerEvent)=>{if(!start)return;const c=norm(e.clientX,e.clientY);if(!c)return;if(Math.abs(c.x-start.x)>0.02||Math.abs(c.y-start.y)>0.02)moved=true;};
  const up=(e:PointerEvent)=>{if(longTimer){clearTimeout(longTimer);longTimer=null;}if(!start)return;const c=norm(e.clientX,e.clientY)||start;const dt=Date.now()-start.t;if(moved)sendInput({t:'swipe',x1:start.x,y1:start.y,x2:c.x,y2:c.y,ms:Math.min(2000,Math.max(50,dt))});else if(dt<500)sendInput({t:'tap',x:start.x,y:start.y});start=null;moved=false;};
  const wheel=(e:WheelEvent)=>{e.preventDefault();const now=Date.now();if(now-lastWheel<60)return;lastWheel=now;const c=norm(e.clientX,e.clientY);if(!c)return;const dir=e.deltaY>0?1:-1;sendInput({t:'swipe',x1:c.x,y1:c.y,x2:c.x,y2:clamp(c.y-0.25*dir),ms:120});};
  const ctx=(e:MouseEvent)=>{e.preventDefault();sendInput({t:'key',k:'back'});};
  const key=(e:KeyboardEvent)=>{if(e.key==='Enter'){e.preventDefault();sendInput({t:'text',s:'\n'});}else if(e.key==='Backspace'){e.preventDefault();sendInput({t:'key',k:'del'});}else if(e.key==='Escape'){e.preventDefault();sendInput({t:'key',k:'back'});}else if(e.key.length===1&&!e.ctrlKey&&!e.metaKey&&!e.altKey){e.preventDefault();sendInput({t:'text',s:e.key});}};
  v.addEventListener('pointerdown',down);v.addEventListener('pointermove',move);v.addEventListener('pointerup',up);v.addEventListener('pointercancel',up);
  v.addEventListener('wheel',wheel,{passive:false});v.addEventListener('contextmenu',ctx);v.addEventListener('keydown',key);
  return ()=>{clearTimeout(ft);if(longTimer)clearTimeout(longTimer);v.removeEventListener('pointerdown',down);v.removeEventListener('pointermove',move);v.removeEventListener('pointerup',up);v.removeEventListener('pointercancel',up);v.removeEventListener('wheel',wheel);v.removeEventListener('contextmenu',ctx);v.removeEventListener('keydown',key);};
 },[grant,controlSession,sendInput]);

 // Row action. Pairing-managed devices (they have a pairingId) can only be viewed via the
 // ID+password connect flow — a direct grant-less session returns CONNECT_REQUIRED — so prompt
 // for the password and go through connectById instead of nuking the current session.
 async function viewRow(d:Device,capOverride?:'view'|'control'){
  const cap:'view'|'control'=capOverride??(wantControl?'control':'view');
  if(d.pairingId){const pw=window.prompt(`Nhập mật khẩu hiển thị trên máy ${d.pairingId} (VRemote Agent)`);if(pw)await connectById(String(d.pairingId),pw,cap);return;}
  await view(d.deviceId,undefined,cap);
 }
 async function view(deviceId:string,connectGrant?:string,capability:'view'|'control'='view'){close(); setError(''); try{
   const body:Record<string,unknown>={deviceId,capability}; if(connectGrant)body.connectGrant=connectGrant;
   const r=await authedFetch('/api/vremote/v1/sessions',{method:'POST',headers:{'content-type':'application/json'},body:JSON.stringify(body)}); if(!r.ok)throw Error(await r.text()); const g:Grant=await r.json(); setGrant(g); setControlSession(capability==='control'); setIceState('mới');
   // Warn early if the server handed us no TURN relay: STUN-only sessions usually show a black screen
   // across the internet (NAT can't be traversed). This is a server config issue (VREMOTE_TURN_*), not a bug.
   const hasTurn=(g.iceServers||[]).some(s=>{const u=(s as RTCIceServer).urls; const arr=Array.isArray(u)?u:[u]; return arr.some(x=>typeof x==='string'&&/^turns?:/i.test(x));}); if(!hasTurn)setError('⚠ Máy chủ chưa cấu hình TURN — xem qua Internet có thể KHÔNG hiện hình (chỉ chạy trong cùng mạng LAN). Cần bật TURN ở server (VREMOTE_TURN_URLS + VREMOTE_TURN_SHARED_SECRET).');
   const peer=new RTCPeerConnection({iceServers:iceSafe(g.iceServers)}); pc.current=peer; peer.ontrack=e=>{if(video.current)video.current.srcObject=e.streams[0]};
   // Surface the live ICE state so a black screen is diagnosable instead of silent.
   peer.oniceconnectionstatechange=()=>{const s=peer.iceConnectionState; setIceState(s); if(s==='failed')setError('Kết nối video THẤT BẠI (ICE failed) — thường do thiếu máy chủ TURN hoặc mạng/tường lửa chặn. Cần bật TURN ở server.'); else if(s==='disconnected')setIceState('mất kết nối tạm thời…');};
   // Viewer is the offerer, so it owns the data channel; create it BEFORE the offer so it lands in the SDP.
   if(capability==='control'){const d=peer.createDataChannel('input',{ordered:true}); dc.current=d;
    // Agent replies on the same channel with its control availability; warn if it can't inject.
    d.onmessage=ev=>{try{const m=JSON.parse(typeof ev.data==='string'?ev.data:'{}'); if(m&&m.t==='agent.status'&&m.control===false)setError('Thiết bị chưa bật quyền Điều khiển (Accessibility) — hiện chỉ XEM được. Trên máy Android: mở app VRemote → “Cho phép điều khiển (Accessibility)”.');}catch{/* ignore */}};}
   const sock=new WebSocket(g.signalingUrl); ws.current=sock;
   sock.onopen=()=>sock.send(JSON.stringify({type:'join',sessionToken:g.sessionToken}));
   sock.onmessage=async e=>{const m=JSON.parse(e.data); if(m.type==='joined'){const offer=await peer.createOffer({offerToReceiveVideo:true}); await peer.setLocalDescription(offer); if(sock.readyState===WebSocket.OPEN)sock.send(JSON.stringify({type:'offer',sessionId:g.sessionId,sdp:offer.sdp}));} else if(m.type==='answer'){await peer.setRemoteDescription({type:'answer',sdp:m.sdp});} else if(m.type==='candidate'&&m.candidate){await peer.addIceCandidate(m.candidate).catch(()=>{});} else if(m.type==='error'){setError(`Signaling: ${m.code}`)}};
   peer.onicecandidate=e=>{if(e.candidate&&sock.readyState===WebSocket.OPEN)sock.send(JSON.stringify({type:'candidate',sessionId:g.sessionId,candidate:e.candidate.toJSON()}));};
 }catch(e){setError(e instanceof Error?e.message:String(e));}}
 function close(){const currentGrant=grant;if(dc.current){try{dc.current.close()}catch{}dc.current=null} if(ws.current){try{ws.current.close()}catch{}ws.current=null} if(pc.current){pc.current.close();pc.current=null} setControlSession(false); if(currentGrant){void authedFetch(`/api/vremote/v1/sessions/${currentGrant.sessionId}`,{method:'DELETE'}).catch(()=>{});setGrant(null)}}

 if(!token)return <Login onToken={t=>{setError(''); setToken(t);}}/>;

 return <main><header><div><h1>VRemote</h1><span>Remote Edge Monitor</span></div><div className="header-actions"><a className="download" href="/webagent.html" target="_blank" rel="noreferrer">Agent Web (máy này)</a><a className="download" href={agentDl.url} download>Download Android Agent{agentDl.ver?` (v${agentDl.ver})`:''}</a><button className="logout" onClick={logout}>Đăng xuất</button></div></header>{error&&<div className="error">{error}</div>}<section><h2>Điều khiển máy khác</h2><p className="hint">Nhập ID và mật khẩu hiển thị trên máy cần xem (VRemote Agent).</p><label className="remember"><input type="checkbox" checked={wantControl} onChange={e=>setWantControl(e.target.checked)}/> Điều khiển (chuột + bàn phím)</label><ConnectByIdForm onConnect={(id,pw)=>void connectById(id,pw,wantControl?'control':'view')}/></section><section><div className="section-head"><h2>Devices</h2><div className="section-actions"><button onClick={()=>void refresh()}>Tải lại</button><button className="ghost" onClick={()=>void cleanupOffline()}>Xóa máy offline</button><button className="primary" onClick={()=>{setEnroll(null); setShowReg(v=>!v);}}>{showReg?'Đóng':'+ Đăng ký thiết bị'}</button></div></div>{showReg&&<RegisterForm onSubmit={registerDevice} onCancel={()=>setShowReg(false)}/>}{enroll&&<EnrollBox enroll={enroll} onClose={()=>setEnroll(null)}/>}<div className="filters"><input className="search" placeholder="Tìm ID / Device / Machine / Tên…" value={query} onChange={e=>setQuery(e.target.value)}/><select value={statusFilter} onChange={e=>setStatusFilter(e.target.value as 'all'|'online'|'offline')}><option value="all">Tất cả trạng thái</option><option value="online">Online</option><option value="offline">Offline</option></select><select value={pageSize} onChange={e=>setPageSize(Number(e.target.value))}>{[10,20,50].map(n=><option key={n} value={n}>{n}/trang</option>)}</select><span className="count">{sortedDevices.length} thiết bị</span></div><table><thead><tr><th>ID</th><th>Device</th><th>Machine</th><th>Tên</th><th>Enrollment</th><th>Status</th><th>Tạo lúc</th><th>Truy cập</th><th>Action</th></tr></thead><tbody>{pageItems.map(d=>{const pinned=pins.includes(d.deviceId); return <tr key={d.deviceId} className={pinned?'pinned':undefined}><td>{pinned&&<span className="pin-dot" title="Đã ghim">📌 </span>}{d.pairingId??'—'}</td><td>{d.deviceId}</td><td>{d.machineCode??'—'}</td><td><span className="alias">{d.name??'—'}</span> <button className="linklike" title="Đặt tên/alias" onClick={()=>void renameDevice(d)}>✎</button></td><td>{d.enrollmentState??'—'}</td><td>{d.status}</td><td className="ts">{fmtTime(d.createdAt)}</td><td className="ts">{fmtTime(d.lastSeenAt)}</td><td className="row-actions"><button className={pinned?'ghost pinned-btn':'ghost'} title={pinned?'Bỏ ghim':'Ghim lên đầu'} onClick={()=>togglePin(d.deviceId)}>{pinned?'📌 Bỏ ghim':'📌 Ghim'}</button><button disabled={d.status!=='online'} onClick={()=>void viewRow(d,'view')}>View</button><button className="primary" disabled={d.status!=='online'} title="Xem + điều khiển bằng chuột/bàn phím" onClick={()=>void viewRow(d,'control')}>Điều khiển</button>{d.enrollmentState!=='ACTIVE'&&<button className="ghost" onClick={()=>void genEnroll(d.deviceId)}>Mã ghi danh</button>}<button className="ghost" title="Chẩn đoán: codec + breadcrumb (copy để gửi kỹ thuật)" onClick={()=>showDiag(d)}>🔧</button><button className="ghost danger" onClick={()=>void deleteDevice(d.deviceId)}>Xóa</button></td></tr>;})}{!sortedDevices.length&&<tr><td colSpan={9}>{devices.length?'Không có thiết bị khớp bộ lọc.':'Chưa có thiết bị. Bấm “+ Đăng ký thiết bị” để thêm.'}</td></tr>}</tbody></table>{totalPages>1&&<div className="pager"><button disabled={curPage<=1} onClick={()=>setPage(curPage-1)}>‹ Trước</button><span>Trang {curPage}/{totalPages}</span><button disabled={curPage>=totalPages} onClick={()=>setPage(curPage+1)}>Sau ›</button></div>}</section>{grant&&<section><div className="viewer-head"><h2>Session {grant.sessionId}</h2>{iceState&&<span className="ts" title="Trạng thái kết nối WebRTC (ICE)">Kết nối: {iceState}</span>}<button onClick={()=>close()}>Close</button></div>{controlSession&&<p className="hint">Điều khiển: chuột trái = chạm/kéo, chuột phải = Back, con lăn = cuộn, gõ bàn phím để nhập (bấm vào màn hình để lấy focus).</p>}<video ref={video} autoPlay playsInline controls={false}/>{controlSession&&<div className="ctl-bar"><button onClick={()=>sendInput({t:'key',k:'back'})}>Back</button><button onClick={()=>sendInput({t:'key',k:'home'})}>Home</button><button onClick={()=>sendInput({t:'key',k:'recents'})}>Recents</button><input value={textVal} onChange={e=>setTextVal(e.target.value)} placeholder="Nhập văn bản" onKeyDown={e=>{if(e.key==='Enter'){e.preventDefault();if(textVal){sendInput({t:'text',s:textVal});setTextVal('')}}}}/><button onClick={()=>{if(textVal){sendInput({t:'text',s:textVal});setTextVal('')}}}>Gửi</button></div>}</section>}{diagText!==null&&<div className="modal-overlay" onClick={()=>setDiagText(null)}><div className="modal" onClick={e=>e.stopPropagation()}><div className="modal-head"><b>Chẩn đoán thiết bị</b><button className="ghost" onClick={()=>setDiagText(null)}>Đóng</button></div><textarea className="modal-text" readOnly value={diagText} onFocus={e=>e.currentTarget.select()}/><div className="modal-actions"><button className="primary" onClick={()=>{try{navigator.clipboard?.writeText(diagText);setCopied(true);}catch{/* ignore */}}}>{copied?'Đã copy ✓':'📋 Copy toàn bộ'}</button></div></div></div>}</main>;
}
createRoot(document.getElementById('root')!).render(<React.StrictMode><App/></React.StrictMode>);
