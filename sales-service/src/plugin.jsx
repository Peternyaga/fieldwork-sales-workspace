import React,{useState} from 'react';

export function PluginConnect({api}){
 const [message,setMessage]=useState(''),[busy,setBusy]=useState(false);
 async function connect(){setBusy(true);setMessage('');try{await api('/plugin/connect',{});setMessage('Codex connected to this account for 30 days. Restart the plugin connection to use it.')}catch(error){setMessage(error.message)}finally{setBusy(false)}}
 return <div className="plugin-connect"><button className="nav" type="button" disabled={busy} onClick={connect}><span>⌁</span>Connect Codex</button>{message&&<p role="status">{message}</p>}</div>
}
