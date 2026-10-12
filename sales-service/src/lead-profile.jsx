import React, {useEffect, useRef, useState} from 'react';
import {PhoneActions} from './phone-actions.jsx';
import './lead-profile.css';

export const profileFields = {name:'Contact name', organisation:'Organisation', phone:'Phone', email:'Email', website:'Website', facebook:'Facebook', instagram:'Instagram', linkedin:'LinkedIn', contact_role:'Contact role', location:'Location', source:'Source'};
export function safeLink(value) {
  try { const url=new URL(value); return ['http:','https:'].includes(url.protocol)&&!url.username&&!url.password ? url.href : null; } catch { return null; }
}
export function ProfileDialog({close, children, titleId='detail-title'}) {
  const ref=useRef(null);
  useEffect(()=>{
    const previous=document.activeElement, overflow=document.body.style.overflow;
    document.body.style.overflow='hidden';ref.current.querySelector('button')?.focus();
    function key(event) {
      if(event.key==='Escape'){event.preventDefault();close();}
      if(event.key==='Tab'){
        const items=[...ref.current.querySelectorAll('button,a[href],input,textarea,select,[tabindex="0"]')].filter(el=>!el.disabled&&el.getClientRects().length);
        const first=items[0],last=items.at(-1);
        if(event.shiftKey&&document.activeElement===first){event.preventDefault();last?.focus();}
        else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first?.focus();}
      }
    }
    document.addEventListener('keydown',key);
    return ()=>{document.body.style.overflow=overflow;document.removeEventListener('keydown',key);if(previous?.isConnected)previous.focus();};
  },[]);
  return <div className="overlay profile-overlay"><section ref={ref} className="profile-dialog" role="dialog" aria-modal="true" aria-labelledby={titleId}><div className="profile-top"><span>Lead profile</span><button type="button" onClick={close} aria-label="Close lead profile">Close ×</button></div><div className="profile-body">{children}</div></section></div>;
}
export function ProfileEssentials({lead, api, onChanged}) {
  const [editing,setEditing]=useState(false),[saving,setSaving]=useState(false),[error,setError]=useState('');
  return <section className="profile-card"><div className="profile-card-heading"><h3>Contact essentials</h3><button className="secondary" onClick={()=>{setEditing(!editing);setError('')}}>{editing?'Cancel edit':'Edit profile'}</button></div>
    {!editing ? <><div className="profile-contacts"><div><small>☎ Phone</small><PhoneActions phone={lead.phone}/></div><div><small>✉ Email</small>{lead.email&&/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(lead.email)?<a href={`mailto:${lead.email}`}>{lead.email}</a>:<span>{lead.email||'Not recorded'}</span>}</div>{['website','facebook','instagram','linkedin'].map(field=><div key={field}><small>{field==='website'?'↗':'◎'} {profileFields[field]}</small>{safeLink(lead[field])?<a href={safeLink(lead[field])} target="_blank" rel="noopener noreferrer">{lead[field]} ↗</a>:<span>{lead[field]||'Not recorded'}</span>}</div>)}</div><dl className="profile-facts">{['contact_role','location','source'].map(field=><div key={field}><dt>{profileFields[field]}</dt><dd>{lead[field]||'Not recorded'}</dd></div>)}<div><dt>Collected by</dt><dd>{lead.collector||'Unknown'}</dd></div></dl><small>Contact details may be unverified. Review their provenance in history before outreach.</small></> : <form onSubmit={async event=>{
      event.preventDefault();const data=Object.fromEntries(new FormData(event.target));const provenance=data.provenance;delete data.provenance;
      const changes=Object.fromEntries(Object.entries(data).filter(([key,value])=>value!==(lead[key]||'')));
      if(!Object.keys(changes).length){setEditing(false);return;}
      setSaving(true);setError('');try {await api(`/leads/${lead.id}/profile`,{version:Number(lead.version),changes,provenance});await onChanged();setEditing(false);}catch(error){setError(error.message);}finally{setSaving(false);}
    }}><div className="form-grid">{Object.entries(profileFields).map(([field,label])=><label className="field" key={field}><span>{label}</span><input name={field} defaultValue={lead[field]||''} required={field==='name'} maxLength={['website','facebook','instagram','linkedin'].includes(field)?500:field==='email'?254:field==='phone'?50:field==='location'?250:150} type={field==='email'?'email':['website','facebook','instagram','linkedin'].includes(field)?'url':'text'}/></label>)}</div><label className="field"><span>Where did these corrections come from?</span><textarea name="provenance" required maxLength="2000" rows="3" placeholder="Describe the source of these corrections and any uncertainty."/></label><p className="help">Changes preserve the previous values in history. Supplied details are not independently verified.</p>{error&&<p className="error" role="alert">{error}</p>}<button disabled={saving}>{saving?'Saving…':'Save corrections'}</button></form>}
  </section>;
}
function display(value){return typeof value==='object'&&value!==null?JSON.stringify(value,null,2):String(value??'Not recorded');}
export function RecentContext({activities,date}) {
  const notes=activities.filter(activity=>activity.kind==='note').slice(0,2);
  if(!notes.length)return null;
  return <section className="profile-card"><h3>Latest conversation context</h3><small>User-recorded notes · contact identity and details may need confirmation</small>{notes.map(note=>{
    let body;try{body=JSON.parse(note.body);}catch{body={text:note.body};}
    return <article className="recent-note" key={note.id}><p>{body.text||note.body}</p><small>{note.actor} · {date(note.created_at)}</small></article>;
  })}</section>;
}
export function ProfileHistory({activities,date}) {
  return <div className="timeline profile-timeline">{activities.map(activity=>{
    let body;try{body=JSON.parse(activity.body);}catch{body={text:activity.body};}
    return <article key={activity.id}><span className="timeline-dot"/><header><strong>{activity.kind.replaceAll('_',' ')}</strong><small>{activity.actor} · {date(activity.created_at)}</small></header>{activity.kind==='profile_updated'?<><ul>{Object.entries(body.changes||{}).map(([field,change])=><li key={field}><strong>{profileFields[field]||field}</strong>: <del>{change.from||'Not recorded'}</del> → {change.to||'Cleared'}</li>)}</ul><p>{body.provenance}</p><small>{body.verification}</small>{body.source_activity_id&&<small>Source note: {body.source_activity_id}</small>}</>:<>{body.text&&<p>{body.text}</p>}{activity.kind==='contact_research'&&<p className="research-warning">Historical research — identity, current role and contact details need confirmation.</p>}<dl>{Object.entries(body).filter(([key])=>key!=='text').map(([key,value])=><div key={key}><dt>{key.replaceAll('_',' ')}</dt><dd>{display(value)}</dd></div>)}</dl></>}</article>;
  })}</div>;
}
