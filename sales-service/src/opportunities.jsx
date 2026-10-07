import React,{useState} from 'react';
import {requestKey as newRequestKey} from './request-key.js';
import './opportunities.css';

export function OpportunityPanel({api,detail,stages,onChanged}){
 const [busy,setBusy]=useState(false),[error,setError]=useState(''),[requestKey,setRequestKey]=useState(newRequestKey);
 const opportunities=detail.opportunities||[];
 async function act(action){setError('');setBusy(true);try{await action();await onChanged();return true}catch(e){setError(e.message);return false}finally{setBusy(false)}}
 return <section className="opportunity-panel" aria-label="Sales opportunities">
  <div className="opportunity-heading"><div><span className="eyebrow">ONE CONTACT · MANY POSSIBILITIES</span><h3>Sales opportunities</h3></div><span>{opportunities.length} {opportunities.length===1?'opportunity':'opportunities'}</span></div>
  {opportunities.length?<div className="opportunity-list">{opportunities.map(op=><form key={op.id} onSubmit={e=>{e.preventDefault();act(()=>api('/opportunities/'+op.id+'/update',{stage:new FormData(e.target).get('stage'),version:Number(op.version)}))}}><div><strong>{op.offering}</strong><small>Tracked separately from other offers</small></div><label className="field"><span>Stage for {op.offering}</span><select name="stage" defaultValue={op.stage} key={op.version}>{stages.map(stage=><option key={stage}>{stage}</option>)}</select></label><button disabled={busy}>Save</button></form>)}</div>:<p className="opportunity-empty">No sales opportunity has been recorded. Add one when there is a specific offer to pursue.</p>}
  <form className="opportunity-add" onSubmit={async e=>{e.preventDefault();const form=e.target,offering=new FormData(form).get('offering');if(await act(()=>api('/leads/'+detail.lead.id+'/opportunities',{offering,request_key:requestKey}))){form.reset();setRequestKey(newRequestKey())}}}><label className="field"><span>Another product or service</span><input name="offering" maxLength="150" placeholder="e.g. Annual maintenance" required/></label><button disabled={busy}>Add opportunity</button></form>
  <small className="help">Adding an opportunity does not send a message or schedule a follow-up.</small>{error&&<p className="error" role="alert">{error}</p>}
 </section>
}
