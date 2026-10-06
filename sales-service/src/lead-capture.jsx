import React,{useState} from 'react';
import './lead-capture.css';

const fields=[['Name','name',true],['Organisation','organisation'],['Email','email'],['Phone · include country code','phone'],['Product or service','offering'],['Lead source','source'],['Collected by','collector'],['Suggested owner','owner']];

export function LeadCapture({api,projectId,onClose,onCreated,onOpen}){
 const [busy,setBusy]=useState(false),[error,setError]=useState(''),[matches,setMatches]=useState([]),[requestKey]=useState(()=>crypto.randomUUID());
 async function submit(event){
  event.preventDefault();setBusy(true);setError('');setMatches([]);
  try{const data=Object.fromEntries(new FormData(event.currentTarget));await api('/leads',{...data,project_id:projectId,request_key:requestKey});await onCreated();onClose()}
  catch(cause){setError(cause.message);if(cause.matches)setMatches(cause.matches)}
  finally{setBusy(false)}
 }
 return <div className="overlay"><section className="modal" role="dialog" aria-modal="true" aria-labelledby="add-title"><button className="close" aria-label="Close" onClick={onClose}>×</button><span className="eyebrow">LEAD INTAKE</span><h2 id="add-title">Start a new relationship</h2><p>Keep what you know. Leave unknown details empty.</p>
  <form onSubmit={submit}><div className="form-grid">{fields.map(([label,name,required])=><label className="field" key={name}><span>{label}</span><input name={name} type={name==='email'?'email':'text'} required={required} maxLength={name==='email'?254:name==='phone'?50:150}/></label>)}<label className="field wide"><span>Collection notes</span><textarea name="notes" rows="3" placeholder="What are they interested in? How should we approach them?"/></label></div>
   {error&&<p className="error" role="alert">{error}</p>}
   {matches.length>0&&<div className="duplicate-review"><strong>Possible existing relationship</strong><p>Review the match before adding a separate record. If this is a new sale for the same person, open their profile and add another opportunity.</p>{matches.map((match,index)=>match.id?<button type="button" key={match.id} onClick={()=>onOpen(match.id)}>{match.name}{match.organisation?' · '+match.organisation:''} ↗</button>:<span key={index}>A matching contact exists. Ask a workspace manager to review it.</span>)}</div>}
   <div className="form-actions"><button type="button" className="secondary" onClick={onClose}>Cancel</button><button disabled={busy}>Save lead →</button></div>
  </form>
 </section></div>
}
