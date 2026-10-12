<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('FIELDWORK_REMOTE_WRITE_TEST')!=='reach.vumbualabs.com')exit("Explicit remote write test flag required.\n");
require __DIR__.'/fieldwork/backend/bootstrap.php';
require __DIR__.'/fieldwork/backend/projects.php';

$workspace=uid();$user=uid();$client=uid();$readToken=bin2hex(random_bytes(32));$writeToken=bin2hex(random_bytes(32));
function checkWrite(bool $ok,string $label): void {if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label."\n";}
function mcpWrite(string $tool,array $args,string $token): array {
    $body=json_encode(['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/call','params'=>['name'=>$tool,'arguments'=>$args]],JSON_THROW_ON_ERROR);
    $handle=curl_init('https://reach.vumbualabs.com/mcp');
    curl_setopt_array($handle,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json','Authorization: Bearer '.$token],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15]);
    $response=curl_exec($handle);if($response===false)throw new RuntimeException(curl_error($handle));
    $status=curl_getinfo($handle,CURLINFO_RESPONSE_CODE);curl_close($handle);
    $json=json_decode($response,true,20,JSON_THROW_ON_ERROR);
    return [$status,$json,json_decode($json['result']['content'][0]['text']??'null',true)];
}
try{
    query('INSERT INTO workspaces (id,name) VALUES (?,?)',[$workspace,'Remote write integration test']);
    query('INSERT INTO users (id,workspace_id,name,email,password_hash,role) VALUES (?,?,?,?,?,?)',[$user,$workspace,'Test Owner','write-'.bin2hex(random_bytes(6)).'@local.test',password_hash(bin2hex(random_bytes(20)),PASSWORD_DEFAULT),'owner']);
    $project=defaultProject($workspace);$lead=uid();
    query('INSERT INTO leads (id,workspace_id,project_id,name,organisation,notes,owner,owner_user_id) VALUES (?,?,?,?,?,?,?,?)',[$lead,$workspace,$project,'Test Lead','Test Co','','Test Owner',$user]);
    query('INSERT INTO oauth_clients (id,name,redirect_uris) VALUES (?,?,?)',[$client,'Remote test','[]']);
    foreach([[$readToken,'leads:read'],[$writeToken,'leads:read leads:write tasks:write']] as [$token,$scope]){
        query('INSERT INTO oauth_grants (id,client_id,user_id,workspace_id,resource,scope,access_hash,refresh_hash,access_expires_at,refresh_expires_at,auth_version) VALUES (?,?,?,?,?,?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 HOUR),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY),1)',[uid(),$client,$user,$workspace,'https://reach.vumbualabs.com/mcp',$scope,hash('sha256',$token),hash('sha256',bin2hex(random_bytes(32)))]);
    }
    [$status,$denied]=mcpWrite('update_lead_stage',['id'=>$lead,'stage'=>'Contacted','version'=>1],$readToken);
    checkWrite($status===403&&($denied['result']['isError']??false)===true,'existing read-only connection cannot update a lead');
    [$status,$denied]=mcpWrite('create_task',['lead_id'=>$lead,'title'=>'Test','due_at'=>gmdate('Y-m-d\TH:i:s',time()+86400).'.000Z'],$readToken);
    checkWrite($status===403&&($denied['result']['isError']??false)===true,'existing read-only connection cannot update schedule');
    [$status,,$changed]=mcpWrite('update_lead_stage',['id'=>$lead,'stage'=>'Contacted','version'=>1],$writeToken);
    checkWrite($status===200&&($changed['stage']??'')==='Contacted'&&($changed['version']??0)===2,'lead stage updated with write permission');
    [$status]=mcpWrite('update_lead_stage',['id'=>$lead,'stage'=>'Qualified','version'=>1],$writeToken);
    checkWrite($status===409,'stale lead version rejected');
    [$status,,$assigned]=mcpWrite('assign_lead',['id'=>$lead,'user_id'=>$user,'version'=>2],$writeToken);
    checkWrite($status===200&&($assigned['owner']??'')==='Test Owner'&&($assigned['version']??0)===3,'lead assigned with write permission');
    $requestKey='remote-'.bin2hex(random_bytes(8));
    [$status,,$created]=mcpWrite('create_lead',['name'=>'Second Test Lead','project_id'=>$project,'request_key'=>$requestKey],$writeToken);
    checkWrite($status===200&&isset($created['id']),'lead created with write permission');
    [$status,,$replayed]=mcpWrite('create_lead',['name'=>'Second Test Lead','project_id'=>$project,'request_key'=>$requestKey],$writeToken);
    checkWrite($status===200&&($replayed['id']??'')===$created['id']&&($replayed['replayed']??false)===true,'lead creation retry did not duplicate');
    [$status,,$note]=mcpWrite('add_note',['id'=>$lead,'note'=>'Test note'], $writeToken);
    checkWrite($status===200&&($note['ok']??false)===true,'lead note recorded');
    $workId=query("SELECT id FROM agent_work_items WHERE workspace_id=? AND lead_id=? AND status='pending' ORDER BY created_at DESC,id DESC LIMIT 1",[$workspace,$lead])->fetchColumn();
    checkWrite(is_string($workId),'note created a pending agent work item');
    $first=gmdate('Y-m-d\TH:i:s',time()+86400).'.000Z';$second=gmdate('Y-m-d\TH:i:s',time()+172800).'.000Z';$third=gmdate('Y-m-d\TH:i:s',time()+259200).'.000Z';
    [$status,,$agentTask]=mcpWrite('schedule_agent_followup',['id'=>$workId,'title'=>'Follow up after note','due_at'=>$first,'summary'=>'Scheduled an internal follow-up after reviewing the note.'],$writeToken);
    $report=json_decode(query('SELECT result FROM agent_work_items WHERE id=?',[$workId])->fetchColumn(),true);
    checkWrite($status===200&&isset($agentTask['task_id'])&&query('SELECT status FROM agent_work_items WHERE id=?',[$workId])->fetchColumn()==='done'&&($report['actions'][0]['task_id']??'')===$agentTask['task_id'],'agent follow-up and report saved together');
    [$status]=mcpWrite('schedule_agent_followup',['id'=>$workId,'title'=>'Follow up after note','due_at'=>$first,'summary'=>'Retry.'],$writeToken);
    checkWrite($status===409&&(int)query('SELECT COUNT(*) FROM tasks WHERE workspace_id=? AND lead_id=? AND title=?',[$workspace,$lead,'Follow up after note'])->fetchColumn()===1,'retry cannot duplicate agent follow-up');
    $reversalActivity=uid();$reversalId=uid();
    query('INSERT INTO activities (id,workspace_id,lead_id,kind,body,actor) VALUES (?,?,?,?,?,?)',[$reversalActivity,$workspace,$lead,'reversal_requested','{"reason":"Test reversal"}','Test Owner']);
    query('INSERT INTO agent_work_items (id,workspace_id,lead_id,source_activity_id,parent_work_id,kind) VALUES (?,?,?,?,?,?)',[$reversalId,$workspace,$lead,$reversalActivity,$workId,'reverse_work']);
    [$status,,$reversed]=mcpWrite('reverse_agent_work',['id'=>$reversalId],$writeToken);
    checkWrite($status===200&&($reversed['status']??'')==='done'&&query('SELECT status FROM tasks WHERE id=?',[$agentTask['task_id']])->fetchColumn()==='cancelled','reversal cancels only the original pending follow-up');
    [$status]=mcpWrite('add_note',['id'=>$lead,'note'=>'Needs a person to choose a time'],$writeToken);
    $reviewId=query("SELECT id FROM agent_work_items WHERE workspace_id=? AND lead_id=? AND status='pending' ORDER BY created_at DESC,id DESC LIMIT 1",[$workspace,$lead])->fetchColumn();
    [$status,,$review]=mcpWrite('resolve_agent_work',['id'=>$reviewId,'decision'=>'needs_review','reason'=>'No callback time supplied'],$writeToken);
    checkWrite($status===200&&($review['decision']??'')==='needs_review'&&query('SELECT status FROM agent_work_items WHERE id=?',[$reviewId])->fetchColumn()==='needs_review','ambiguous work sent for human review');
    [$status,,$task]=mcpWrite('create_task',['lead_id'=>$lead,'title'=>'Test follow-up','due_at'=>$second],$writeToken);
    checkWrite($status===200&&isset($task['id'],$task['due_at']),'internal follow-up created');
    [$status,,$moved]=mcpWrite('reschedule_task',['id'=>$task['id'],'expected_due_at'=>$task['due_at'],'due_at'=>$third],$writeToken);
    checkWrite($status===200&&($moved['ok']??false)===true,'internal follow-up rescheduled');
    [$status,,$complete]=mcpWrite('complete_task',['id'=>$task['id']],$writeToken);
    checkWrite($status===200&&($complete['ok']??false)===true,'internal follow-up completed');
    echo "Remote write checks passed.\n";
}finally{
    query('DELETE FROM oauth_grants WHERE workspace_id=?',[$workspace]);
    query('DELETE FROM oauth_codes WHERE workspace_id=?',[$workspace]);
    query('DELETE FROM agent_work_items WHERE workspace_id=?',[$workspace]);
    query('DELETE FROM activities WHERE workspace_id=?',[$workspace]);
    query('DELETE FROM tasks WHERE workspace_id=?',[$workspace]);
    query('DELETE FROM assignment_events WHERE workspace_id=?',[$workspace]);
    query('DELETE FROM requests WHERE workspace_id=?',[$workspace]);
    query('DELETE FROM opportunities WHERE workspace_id=?',[$workspace]);
    query('DELETE FROM contacts WHERE workspace_id=?',[$workspace]);
    query('DELETE FROM organisations WHERE workspace_id=?',[$workspace]);
    query('DELETE FROM leads WHERE workspace_id=?',[$workspace]);
    query('DELETE FROM projects WHERE workspace_id=?',[$workspace]);
    query('DELETE FROM users WHERE workspace_id=?',[$workspace]);
    query('DELETE FROM workspaces WHERE id=?',[$workspace]);
    query('DELETE FROM oauth_clients WHERE id=?',[$client]);
}
