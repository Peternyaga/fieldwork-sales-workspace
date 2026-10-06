import React,{useState} from 'react';
import './projects.css';

export function ProjectSwitch({projects,value,canCreate,onSelect,onCreate}){
 const [creating,setCreating]=useState(false),[busy,setBusy]=useState(false),[error,setError]=useState('');
 async function submit(event){
  event.preventDefault();setError('');setBusy(true);
  try{const name=new FormData(event.currentTarget).get('name').trim();await onCreate(name);setCreating(false)}
  catch(cause){setError(cause.message)}finally{setBusy(false)}
 }
 return <div className="project-switch"><div className="project-switch-heading"><span>PROJECT</span>{canCreate&&<button type="button" aria-label="Create project" title="Create project" onClick={()=>{setError('');setCreating(!creating)}}>＋</button>}</div>
  <select aria-label="Current project" value={value} onChange={event=>onSelect(event.target.value)}>{projects.map(project=><option key={project.id} value={project.id}>{project.name}</option>)}</select>
  {creating&&<form onSubmit={submit}><label className="sr-only" htmlFor="new-project-name">Project name</label><input id="new-project-name" name="name" maxLength="150" placeholder="Project name" required autoFocus/><button disabled={busy}>Create</button></form>}
  {error&&<p className="error" role="alert">{error}</p>}
 </div>
}

export function ProjectMove({projects,current,onMove}){
 const [target,setTarget]=useState(current);
 if(projects.length<2)return null;
 return <form className="project-move" onSubmit={event=>{event.preventDefault();onMove(target)}}><label className="field"><span>Move relationship to another project</span><select value={target} onChange={event=>setTarget(event.target.value)}>{projects.map(project=><option key={project.id} value={project.id}>{project.name}</option>)}</select></label><button disabled={target===current}>Move</button></form>
}
