import React from 'react';
import {createRoot} from 'react-dom/client';
import {AgentReportsPanel} from '../src/agent-reports.jsx';
import '../src/style.css';
import '../src/agent-reports.css';
const items=[
{id:'review-1',lead_id:'lead-1',name:'Synthetic review lead',organisation:'QA workspace',kind:'review_note',status:'needs_review',created_at:'2026-10-12 12:00:00',review_token:'fixture',source_note:{text:'Please clarify a callback time.'},result:{summary:'No reliable callback time was supplied. Please review.',actions:[]}},
{id:'review-2',lead_id:'lead-2',name:'Synthetic reversal lead',kind:'reverse_work',status:'needs_review',created_at:'2026-10-12 12:05:00',review_token:'fixture',source_note:{reason:'Review changed follow-up'},original_result:{summary:'Created a follow-up.',actions:[{type:'create_follow_up',title:'Discuss requirements',due_at:'2026-10-13 07:00:00'}]},result:{summary:'The follow-up changed. No automatic reversal was made.',actions:[]}}
];
async function api(path,body){
 if(path==='/agent-reports')return [...items];
 const item=items.find(i=>path.includes('/'+i.id+'/'));
 if(!item||!body.reason.trim())throw new Error('Invalid fixture review');
 item.status='closed';item.result={...item.result,human_review:{action:body.action,reason:body.reason,actor:'QA reviewer',at:'2026-10-12 12:30:00'}};
 if(body.action==='request_correction')items.push({...item,id:'next-work',kind:'review_note',status:'pending',result:null,source_note:{instructions:body.reason,reviewed_work_id:item.id}});
 return {ok:true};
}
function Fixture(){const [lead,setLead]=React.useState('');return <><output aria-live="polite">{lead?'Opened synthetic lead profile: '+lead:''}</output><AgentReportsPanel api={api} close={()=>{}} openLead={setLead}/></>}
createRoot(document.getElementById('root')).render(<Fixture/>);
