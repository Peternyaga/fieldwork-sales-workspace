import React,{useEffect,useState} from 'react';

export function PluginConnect({api}){
 const [status,setStatus]=useState(null),[message,setMessage]=useState(''),[busy,setBusy]=useState(false);
 useEffect(()=>{let active=true;api('/plugin/status').then(result=>{if(active)setStatus(result)}).catch(error=>{if(active)setMessage(error.message)});return()=>{active=false}},[api]);
 async function connect(){setBusy(true);setMessage('');try{await api('/plugin/connect',{});setStatus(await api('/plugin/status'));setMessage('Access prepared. Install Sales Workspace in Codex, then start a new chat and ask it to set up Fieldwork.')}catch(error){setMessage(error.message)}finally{setBusy(false)}}
 return <div className="plugin-connect"><button className="nav" type="button" disabled={busy} onClick={connect}><span>⌁</span>Connect Codex</button>{status?.state==='verified'?<p role="status">Plugin access verified. Last used {status.last_used_at}. Access expires {status.expires_at}.</p>:status?.state==='prepared'?<p role="status">Access token prepared until {status.expires_at}. The plugin has not used it yet.</p>:status?.state==='not_prepared'?<p>Codex access has not been prepared for this account.</p>:null}{message&&<p role="status">{message}</p>}</div>
}
