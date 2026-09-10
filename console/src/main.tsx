import React from 'react';
import { createRoot } from 'react-dom/client';
import './style.css';

function App() {
  const [devices, setDevices] = React.useState<{deviceId:string; machineCode?:string|null; status:string}[]>([]);
  const [error, setError] = React.useState('');

  React.useEffect(() => {
    fetch('/api/v1/devices')
      .then(async r => { if (!r.ok) throw new Error(`HTTP ${r.status}`); return r.json(); })
      .then(data => setDevices(data.items ?? []))
      .catch(e => setError(e.message));
  }, []);

  return <main>
    <header><h1>VRemote</h1><span>Remote Edge Monitor</span></header>
    {error && <div className="error">Không tải được registry: {error}</div>}
    <section><h2>Devices</h2><table><thead><tr><th>Device</th><th>Machine</th><th>Status</th><th>Action</th></tr></thead><tbody>
      {devices.map(d => <tr key={d.deviceId}><td>{d.deviceId}</td><td>{d.machineCode ?? '—'}</td><td>{d.status}</td><td><button disabled={d.status !== 'online'}>View</button></td></tr>)}
      {!devices.length && <tr><td colSpan={4}>Chưa có thiết bị.</td></tr>}
    </tbody></table></section>
  </main>;
}

createRoot(document.getElementById('root')!).render(<React.StrictMode><App /></React.StrictMode>);
