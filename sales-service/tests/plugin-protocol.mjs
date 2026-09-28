import {spawn} from 'node:child_process';
import readline from 'node:readline';
import path from 'node:path';
const child=spawn(process.execPath,[path.resolve('../sales-workspace/mcp-server/server.mjs')],{stdio:['pipe','pipe','pipe']});
const lines=readline.createInterface({input:child.stdout});
let seq=0;const pending=new Map();
lines.on('line',line=>{const msg=JSON.parse(line);const item=pending.get(msg.id);if(item){pending.delete(msg.id);item.resolve(msg);}});
function call(method,params={}){const id=++seq;return new Promise((resolve,reject)=>{const timeout=setTimeout(()=>{pending.delete(id);reject(new Error('MCP response timed out'))},5000);pending.set(id,{resolve:v=>{clearTimeout(timeout);resolve(v)}});child.stdin.write(JSON.stringify({jsonrpc:'2.0',id,method,params})+'\n')})}
try{
 const init=await call('initialize',{protocolVersion:'2024-11-05',capabilities:{},clientInfo:{name:'integration-test',version:'1'}});
 if(init.result?.serverInfo?.name!=='sales-workspace')throw new Error('Handshake failed');
 const listed=await call('tools/list');if(!listed.result?.tools?.some(t=>t.name==='list_leads'))throw new Error('Tool list missing');
 const result=await call('tools/call',{name:'list_leads',arguments:{}});
 if(result.result?.isError||!Array.isArray(JSON.parse(result.result?.content?.[0]?.text||'null')))throw new Error('Lead lookup failed');
 console.log('MCP handshake, discovery and authenticated lead lookup passed.');
}finally{child.kill();}
