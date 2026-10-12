import React,{useState} from 'react';
import {requestKey} from './request-key.js';

export function AgentRequestForm({leadId,api,onQueued}){
  const [instruction,setInstruction]=useState('');
  const [key,setKey]=useState(requestKey);
  const [busy,setBusy]=useState(false);
  const [error,setError]=useState('');
  const [success,setSuccess]=useState('');
  async function submit(event){
    event.preventDefault();
    if(busy||!instruction.trim())return;
    setBusy(true);setError('');setSuccess('');
    try{
      await api('/leads/'+leadId+'/agent-requests',{instruction:instruction.trim(),request_key:key});
      setInstruction('');setKey(requestKey());setSuccess('Request queued. Check Agent activity for its report.');
      await onQueued?.();
    }catch(e){setError(e.message)}finally{setBusy(false)}
  }
  return <section className="agent-request-section"><h3>Ask the agent</h3><p>Give the agent a task for this lead, such as researching public contact information, updating the profile, or planning an internal follow-up. It will report what it changed and what needs your review.</p><form onSubmit={submit}><label className="field"><span>What should the agent do?</span><textarea value={instruction} onChange={e=>setInstruction(e.target.value)} maxLength={4000} required rows={4} placeholder="Research this business’s current website and decision-maker contacts. Update supported profile fields with source links, and flag anything uncertain."/></label><button disabled={busy||!instruction.trim()}>{busy?'Queuing…':'Queue agent task'}</button></form><small className="help">The agent may research public sources and update internal records. It will not send messages, call leads, spend money, or change access.</small>{error&&<p className="error" role="alert">{error}</p>}{success&&<p className="success" role="status">{success}</p>}</section>;
}
