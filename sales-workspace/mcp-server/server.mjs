import readline from 'node:readline';
import fs from 'node:fs/promises';
import {fileURLToPath} from 'node:url';
import path from 'node:path';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'../../sales-service');
const base='http://127.0.0.1:8788/api';
let cookie='',csrf='';
async function request(route,body){const response=await fetch(base+route,{method:body?'POST':'GET',headers:{Cookie:cookie,...(body?{'Content-Type':'application/json','X-CSRF-Token':csrf}:{})},body:body?JSON.stringify(body):undefined});for(const value of response.headers.getSetCookie())cookie=value.split(';')[0];const result=await response.json();if(!response.ok)throw new Error(result.error||'Service request failed');return result;}
async function connect(){if(cookie)return;const s=await request('/session');csrf=s.csrf;const account=JSON.parse(await fs.readFile(path.join(root,'var/local-account.json'),'utf8'));await request('/login',account);}
const string={type:'string'};
const tools=[
 ['list_leads','Find leads in the connected local workspace. No external research.',{},[]],
 ['list_team','List active sales assignment targets in the connected workspace. Requires owner or manager role.',{},[]],
 ['get_lead','Read one lead and its factual history and follow-up tasks.',{id:string},['id']],
 ['assign_lead','Assign a lead to an active workspace member, optionally scheduling first contact at a resolved UTC time. Requires owner or manager role and current record version.',{id:string,user_id:string,version:string,first_contact_at:string},['id','user_id','version']],
 ['create_lead','Record a supplied lead. Does not authorise outreach. Matching endpoints require review.',{name:string,organisation:string,email:string,phone:string,source:string,offering:string,owner:string,collector:string,notes:string,request_key:string},['name','request_key']],
 ['preview_import','Preview CSV lead intake without writing records. Returns duplicates and errors for review.',{csv:string},['csv']],
 ['commit_import','Import ready CSV rows only after the user approves the preview. Reuse batch_key on uncertain retries.',{csv:string,batch_key:string},['csv','batch_key']],
 ['add_note','Record a supplied outcome or correction as a note; do not invent facts.',{id:string,note:string},['id','note']],
 ['list_tasks','Get follow-up tasks for the local workspace.',{},[]],
 ['create_task','Create an internal follow-up at a resolved UTC time. Does not send invitations or ring an alarm yet.',{id:string,title:string,owner:string,due_at:string},['id','title','owner','due_at']],
 ['complete_task','Mark a follow-up completed only when the user reports completion.',{id:string},['id']]
].map(([name,description,properties,required])=>({name,description,inputSchema:{type:'object',properties,required,additionalProperties:false}}));
function id(value){if(typeof value!=='string'||! /^[a-f0-9]{32}$/.test(value))throw new Error('Invalid record ID');return value;}
async function call(name,a){const tool=tools.find(t=>t.name===name);if(!tool)throw new Error('Unknown tool');for(const key of tool.inputSchema.required)if(typeof a[key]!=='string'||!a[key])throw new Error('Missing '+key);for(const [k,v] of Object.entries(a))if(!(k in tool.inputSchema.properties)||typeof v!=='string')throw new Error('Invalid argument '+k);await connect();
 switch(name){case 'list_leads':return request('/leads');case 'list_team':return request('/team');case 'get_lead':return request('/leads/'+id(a.id));case 'assign_lead':{if(!/^\d+$/.test(a.version))throw new Error('Invalid record version');return request('/leads/'+id(a.id)+'/assign',{user_id:id(a.user_id),version:Number(a.version),...(a.first_contact_at?{first_contact_at:a.first_contact_at}:{})})}case 'create_lead':return request('/leads',a);case 'preview_import':return request('/import/preview',{csv:a.csv});case 'commit_import':return request('/import/commit',{csv:a.csv,batch_key:a.batch_key});case 'add_note':return request('/leads/'+id(a.id)+'/notes',{note:a.note});case 'list_tasks':return request('/tasks');case 'create_task':return request('/leads/'+id(a.id)+'/tasks',{title:a.title,owner:a.owner,due_at:a.due_at});case 'complete_task':return request('/tasks/'+id(a.id)+'/complete',{});}}
async function handle(msg){if(msg.id===undefined)return;let result;
 try{switch(msg.method){case 'initialize':result={protocolVersion:'2024-11-05',capabilities:{tools:{}},serverInfo:{name:'sales-workspace',version:'0.1.0'}};break;case 'ping':result={};break;case 'tools/list':result={tools};break;case 'tools/call':try{result={content:[{type:'text',text:JSON.stringify(await call(msg.params.name,msg.params.arguments??{}))}]}}catch(e){result={isError:true,content:[{type:'text',text:e.message}]}}break;default:process.stdout.write(JSON.stringify({jsonrpc:'2.0',id:msg.id,error:{code:-32601,message:'Method not found'}})+'\n');return;}process.stdout.write(JSON.stringify({jsonrpc:'2.0',id:msg.id,result})+'\n');}catch{process.stdout.write(JSON.stringify({jsonrpc:'2.0',id:msg.id,error:{code:-32603,message:'Request failed'}})+'\n');}}
const lines=readline.createInterface({input:process.stdin});let chain=Promise.resolve();lines.on('line',line=>{chain=chain.then(async()=>{try{await handle(JSON.parse(line))}catch{process.stdout.write(JSON.stringify({jsonrpc:'2.0',id:null,error:{code:-32700,message:'Invalid JSON'}})+'\n')}})});
