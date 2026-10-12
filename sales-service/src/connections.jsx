import React,{useEffect,useState} from 'react';

export function ConnectionsPanel({api,close}) {
  const [connections,setConnections]=useState([]),[pollers,setPollers]=useState([]),[error,setError]=useState(''),[busy,setBusy]=useState(''),[loading,setLoading]=useState(true);
  useEffect(()=>{Promise.all([api('/oauth/connections'),api('/automation/pollers')]).then(([apps,checks])=>{setConnections(apps);setPollers(checks)}).catch(e=>setError(e.message)).finally(()=>setLoading(false))},[api]);
  async function revoke(id) {
    setBusy(id);setError('');
    try {await api('/oauth/connections/'+id+'/revoke',{});setConnections(items=>items.filter(item=>item.id!==id))}
    catch(e){setError(e.message)}finally{setBusy('')}
  }
  async function revokePoller(id){setBusy(id);setError('');try{await api('/automation/pollers/'+id+'/revoke',{});setPollers(items=>items.filter(item=>item.id!==id))}catch(e){setError(e.message)}finally{setBusy('')}}
  const access=scope=>{const parts=scope.split(' ');return [parts.includes('leads:read')?'Read leads & follow-ups':'',parts.includes('leads:write')?'Write leads & notes':'',parts.includes('tasks:write')?'Write internal schedule':''].filter(Boolean).join(' · ')};
  return <div className="overlay"><section className="drawer team-drawer" role="dialog" aria-modal="true" aria-labelledby="connections-title"><button className="close" aria-label="Close" onClick={close}>×</button><span className="eyebrow">ACCOUNT ACCESS</span><h2 id="connections-title">Connected apps</h2><p>These apps can access your Fieldwork workspace until you disconnect them or their access expires. Permissions are shown for each connection.</p>{error&&<p className="error" role="alert">{error}</p>}{loading?<p>Loading connections…</p>:connections.length?<div className="team-list">{connections.map(item=><article key={item.id}><div><strong>{item.name}</strong><small>{access(item.scope)} · Connected {item.created_at} UTC</small></div><button type="button" className="secondary" disabled={!!busy} onClick={()=>revoke(item.id)}>{busy===item.id?'Disconnecting…':'Disconnect'}</button></article>)}</div>:!error?<p>No active OAuth connections.</p>:null}<h3>Local pollers</h3><p>These credentials can only check whether agent work is waiting. Revoke one to stop that computer from checking. Remove its Windows scheduled task on the computer as well.</p>{pollers.length?<div className="team-list">{pollers.map(item=><article key={item.id}><div><strong>Windows poller</strong><small>Connected {item.created_at} UTC · Last check {item.last_used_at||'never'}</small></div><button type="button" className="secondary" disabled={!!busy} onClick={()=>revokePoller(item.id)}>Revoke</button></article>)}</div>:<p>No local pollers connected.</p>}</section></div>;
}
