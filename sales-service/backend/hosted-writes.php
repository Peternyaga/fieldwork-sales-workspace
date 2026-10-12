<?php
declare(strict_types=1);
require_once __DIR__.'/intake.php';
require_once __DIR__.'/projects.php';
require_once __DIR__.'/lead-profile.php';

function hostedWriteId(array $args,string $key): string {
    $id=textField($args,$key,32,true);
    if(!preg_match('/^[a-f0-9]{32}$/',$id))fail('Invalid '.$key,422);
    return $id;
}
function hostedWriteArgs(array $args,array $allowed): void {
    foreach(array_keys($args) as $key)if(!in_array($key,$allowed,true))fail('Unexpected argument',422);
}
function hostedWriteVersion(array $args): int {
    $version=$args['version']??null;
    if(!is_int($version)||$version<1)fail('Invalid lead version',422);
    return $version;
}
function hostedWriteDue(array $args,string $key='due_at'): array {
    $raw=textField($args,$key,40,true);
    if(!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/',$raw))fail('A resolved UTC time is required',422);
    try{$due=new DateTimeImmutable($raw);}catch(Throwable){fail('Invalid due time',422);}
    if($due->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z')!==$raw)fail('Invalid due time',422);
    if($due<=new DateTimeImmutable('+5 minutes',new DateTimeZone('UTC')))fail('Choose a time at least five minutes from now',422);
    return [$raw,$due->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')];
}
function hostedWriteLead(string $id,bool $lock=false): array {
    $lead=query('SELECT * FROM leads WHERE workspace_id=? AND id=?'.($lock?' FOR UPDATE':''),[workspace(),$id])->fetch();
    if(!$lead||!canAccessLead($lead))fail('Lead not found',404);
    return $lead;
}
function hostedWriteTask(string $id): array {
    $task=query('SELECT t.*,l.owner_user_id,l.collector_user_id,l.stage FROM tasks t JOIN leads l ON l.id=t.lead_id AND l.workspace_id=t.workspace_id WHERE t.workspace_id=? AND t.id=? FOR UPDATE',[workspace(),$id])->fetch();
    if(!$task||!canAccessLead($task))fail('Follow-up not found',404);
    return $task;
}
function hostedWriteConflict(string $owner,string $due,string $exclude=''): void {
    $sql="SELECT id FROM tasks WHERE workspace_id=? AND owner=? AND status='pending' AND due_at>=DATE_SUB(?,INTERVAL 20 MINUTE) AND due_at<DATE_ADD(?,INTERVAL 20 MINUTE)";
    $args=[workspace(),$owner,$due,$due];
    if($exclude!==''){$sql.=' AND id<>?';$args[]=$exclude;}
    if(query($sql.' LIMIT 1',$args)->fetch())fail('This person already has a follow-up near that time',409);
}
function hostedWriteAgentItem(string $id): array {
    $work=query('SELECT w.*,l.owner_user_id,l.collector_user_id,l.owner,l.stage FROM agent_work_items w JOIN leads l ON l.id=w.lead_id AND l.workspace_id=w.workspace_id WHERE w.workspace_id=? AND w.id=? FOR UPDATE',[workspace(),$id])->fetch();
    if(!$work||!canAccessLead($work))fail('Agent work item not found',404);
    if($work['status']!=='pending')fail('This work item was already handled',409);
    return $work;
}
function hostedWrite(string $name,array $args): array {
    if(str_starts_with($name,'update_lead_')&&isset(profileFields()[substr($name,12)])){
        hostedWriteArgs($args,['id','version','value','provenance','source_activity_id']);
        $id=hostedWriteId($args,'id');$field=substr($name,12);unset($args['id']);
        $args['changes']=[$field=>$args['value']??null];unset($args['value']);
        return editLeadProfile($id,$args);
    }
    if($name==='create_lead'){
        hostedWriteArgs($args,['name','organisation','email','phone','source','offering','notes','project_id','request_key']);
        $project=resolveProject(isset($args['project_id'])?hostedWriteId($args,'project_id'):null,workspace());
        $result=createLead(leadValues($args),textField($args,'request_key',80,true),$project);
        if(isset($result['matches']))fail('A matching contact exists in this project. Review it before adding another.',409);
        return $result;
    }
    if(!in_array(role(),['owner','manager','salesperson'],true))fail('Your role cannot change leads or follow-ups',403);
    if($name==='update_lead_stage'){
        hostedWriteArgs($args,['id','stage','version']);
        $id=hostedWriteId($args,'id');$stage=textField($args,'stage',30,true);$version=hostedWriteVersion($args);
        if(!in_array($stage,['New','Contacted','Engaged','Qualified','Proposal','Negotiation','Won'],true))fail('Use the Fieldwork website to close a lead',422);
        db()->beginTransaction();hostedWriteLead($id,true);
        $changed=query('UPDATE leads SET stage=?,version=version+1 WHERE workspace_id=? AND id=? AND version=?',[$stage,workspace(),$id,$version]);
        if(!$changed->rowCount())fail('This lead changed. Reload before editing.',409);
        query('UPDATE opportunities SET stage=?,version=version+1 WHERE workspace_id=? AND source_lead_id=?',[$stage,workspace(),$id]);
        audit($id,'updated',['stage'=>$stage]);db()->commit();return ['ok'=>true,'stage'=>$stage,'version'=>$version+1];
    }
    if($name==='assign_lead'){
        hostedWriteArgs($args,['id','user_id','version']);
        requireRole(['owner','manager']);$id=hostedWriteId($args,'id');$target=hostedWriteId($args,'user_id');$version=hostedWriteVersion($args);
        db()->beginTransaction();$lead=query('SELECT * FROM leads WHERE workspace_id=? AND id=? FOR UPDATE',[workspace(),$id])->fetch();
        if(!$lead)fail('Lead not found',404);
        $member=query("SELECT id,name FROM users WHERE id=? AND workspace_id=? AND active=1 AND role IN ('owner','manager','salesperson')",[$target,workspace()])->fetch();
        if(!$member)fail('Choose an active teammate from this workspace',422);
        $changed=query('UPDATE leads SET owner_user_id=?,owner=?,version=version+1 WHERE workspace_id=? AND id=? AND version=?',[$target,$member['name'],workspace(),$id,$version]);
        if(!$changed->rowCount())fail('This lead changed. Reload before assigning.',409);
        query('INSERT INTO assignment_events (id,workspace_id,lead_id,from_user_id,to_user_id,assigned_by) VALUES (?,?,?,?,?,?)',[uid(),workspace(),$id,$lead['owner_user_id'],$target,$_SESSION['user_id']]);
        audit($id,'assigned',['from'=>$lead['owner_user_id'],'to'=>$target,'name'=>$member['name']]);db()->commit();
        return ['ok'=>true,'owner'=>$member['name'],'version'=>$version+1];
    }
    if($name==='add_note'){
        hostedWriteArgs($args,['id','note','outcome']);
        $id=hostedWriteId($args,'id');$note=textField($args,'note',10000,true);$outcome=textField($args,'outcome',30);
        if(!in_array($outcome,['','busy','no_answer','connected','other'],true))fail('Invalid call outcome',422);
        db()->beginTransaction();hostedWriteLead($id,true);$activity=uid();
        query('INSERT INTO activities (id,workspace_id,lead_id,kind,body,actor) VALUES (?,?,?,?,?,?)',[$activity,workspace(),$id,'note',json_encode(['text'=>$note,'outcome'=>$outcome],JSON_THROW_ON_ERROR),$_SESSION['user_name']]);
        query('INSERT INTO agent_work_items (id,workspace_id,lead_id,source_activity_id,kind) VALUES (?,?,?,?,?)',[uid(),workspace(),$id,$activity,$outcome==='busy'?'busy_call':'review_note']);
        db()->commit();return ['ok'=>true,'queued_for_agent'=>true];
    }
    if($name==='create_task'){
        hostedWriteArgs($args,['lead_id','title','owner_user_id','due_at']);
        $leadId=hostedWriteId($args,'lead_id');$title=textField($args,'title',250,true);[$raw,$due]=hostedWriteDue($args);
        $ownerId=isset($args['owner_user_id'])?hostedWriteId($args,'owner_user_id'):$_SESSION['user_id'];
        if(role()==='salesperson'&&$ownerId!==$_SESSION['user_id'])fail('Only managers can schedule another teammate',403);
        db()->beginTransaction();$lead=hostedWriteLead($leadId,true);
        if($lead['stage']==='Lost')fail('Reopen this lead before adding a follow-up',409);
        $member=query("SELECT id,name FROM users WHERE id=? AND workspace_id=? AND active=1 AND role IN ('owner','manager','salesperson')",[$ownerId,workspace()])->fetch();
        if(!$member)fail('Choose an active teammate from this workspace',422);
        hostedWriteConflict($member['name'],$due);$id=uid();
        query('INSERT INTO tasks (id,workspace_id,lead_id,title,owner,due_at) VALUES (?,?,?,?,?,?)',[$id,workspace(),$leadId,$title,$member['name'],$due]);
        audit($leadId,'task_created',['title'=>$title,'due_at'=>$raw,'owner'=>$member['name']]);db()->commit();
        return ['id'=>$id,'due_at'=>$due,'owner'=>$member['name']];
    }
    if($name==='reschedule_task'){
        hostedWriteArgs($args,['id','expected_due_at','due_at']);
        $id=hostedWriteId($args,'id');$expected=textField($args,'expected_due_at',19,true);[$raw,$due]=hostedWriteDue($args);
        if(!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',$expected))fail('Use the current UTC due_at from list_tasks',422);
        db()->beginTransaction();$task=hostedWriteTask($id);
        if($task['status']!=='pending')fail('Only pending follow-ups can be moved',409);
        if($task['stage']==='Lost')fail('Reopen this lead before moving a follow-up',409);
        if($task['due_at']===$due){db()->commit();return ['ok'=>true,'replayed'=>true,'due_at'=>$due];}
        if($task['due_at']!==$expected)fail('This follow-up changed. Reload the schedule.',409);
        hostedWriteConflict($task['owner'],$due,$id);
        query('UPDATE tasks SET due_at=? WHERE workspace_id=? AND id=?',[$due,workspace(),$id]);
        audit($task['lead_id'],'task_rescheduled',['title'=>$task['title'],'from'=>$task['due_at'],'to'=>$raw]);db()->commit();
        return ['ok'=>true,'due_at'=>$due];
    }
    if($name==='complete_task'){
        hostedWriteArgs($args,['id']);$id=hostedWriteId($args,'id');
        db()->beginTransaction();$task=hostedWriteTask($id);
        if($task['status']==='cancelled')fail('A cancelled follow-up cannot be completed',409);
        if($task['status']==='completed'){db()->commit();return ['ok'=>true,'replayed'=>true];}
        query("UPDATE tasks SET status='completed' WHERE workspace_id=? AND id=? AND status='pending'",[workspace(),$id]);
        audit($task['lead_id'],'task_completed',['title'=>$task['title']]);db()->commit();return ['ok'=>true];
    }
    if($name==='schedule_agent_followup'){
        hostedWriteArgs($args,['id','title','due_at','summary']);
        $id=hostedWriteId($args,'id');$title=textField($args,'title',250,true);$summary=textField($args,'summary',2000,true);[$raw,$due]=hostedWriteDue($args);
        db()->beginTransaction();$work=hostedWriteAgentItem($id);
        if($work['kind']==='reverse_work')fail('Use reverse_agent_work for reversal requests',422);
        if($work['stage']==='Lost')fail('Reopen this lead before adding a follow-up',409);
        if(!$work['owner_user_id']||$work['owner']==='')fail('Assign this lead before scheduling a follow-up',422);
        $member=query("SELECT id FROM users WHERE id=? AND workspace_id=? AND active=1 AND role IN ('owner','manager','salesperson')",[$work['owner_user_id'],workspace()])->fetch();
        if(!$member)fail('The assigned teammate is unavailable',422);
        hostedWriteConflict($work['owner'],$due);$taskId=uid();
        query('INSERT INTO tasks (id,workspace_id,lead_id,title,owner,due_at) VALUES (?,?,?,?,?,?)',[$taskId,workspace(),$work['lead_id'],$title,$work['owner'],$due]);
        audit($work['lead_id'],'task_created',['title'=>$title,'due_at'=>$raw,'source_work_item'=>$id]);
        $result=['summary'=>$summary,'actions'=>[['type'=>'create_follow_up','task_id'=>$taskId,'title'=>$title,'due_at'=>$due,'owner'=>$work['owner']]],'task_id'=>$taskId,'due_at'=>$due,'owner'=>$work['owner']];
        query("UPDATE agent_work_items SET status='done',completed_at=UTC_TIMESTAMP(),result=? WHERE workspace_id=? AND id=?",[json_encode($result,JSON_THROW_ON_ERROR),workspace(),$id]);
        db()->commit();return ['ok'=>true]+$result;
    }
    if($name==='resolve_agent_work'){
        hostedWriteArgs($args,['id','decision','reason']);
        $id=hostedWriteId($args,'id');$decision=textField($args,'decision',30,true);$reason=textField($args,'reason',1000,true);
        if(!in_array($decision,['done','needs_review'],true))fail('Invalid decision',422);
        db()->beginTransaction();$work=hostedWriteAgentItem($id);
        if($work['kind']==='reverse_work')fail('Use reverse_agent_work for reversal requests',422);
        $result=['decision'=>$decision,'summary'=>$reason,'reason'=>$reason,'actions'=>[]];
        query('UPDATE agent_work_items SET status=?,completed_at=UTC_TIMESTAMP(),result=? WHERE workspace_id=? AND id=?',[$decision,json_encode($result,JSON_THROW_ON_ERROR),workspace(),$id]);
        db()->commit();return ['ok'=>true]+$result;
    }
    if($name==='reverse_agent_work'){
        hostedWriteArgs($args,['id']);$id=hostedWriteId($args,'id');
        db()->beginTransaction();$work=hostedWriteAgentItem($id);
        if($work['kind']!=='reverse_work'||!$work['parent_work_id'])fail('This is not a reversal request',422);
        $original=query('SELECT * FROM agent_work_items WHERE workspace_id=? AND id=? FOR UPDATE',[workspace(),$work['parent_work_id']])->fetch();
        if(!$original||$original['status']!=='done')fail('Original report is unavailable',409);
        $previous=json_decode($original['result']??'null',true);
        $action=$previous['actions'][0]??null;
        $status='done';$changes=[];
        if(is_array($action)&&($action['type']??'')==='create_follow_up'){
            $task=query('SELECT * FROM tasks WHERE workspace_id=? AND id=? FOR UPDATE',[workspace(),$action['task_id']])->fetch();
            if(!$task||$task['lead_id']!==$work['lead_id']||$task['status']!=='pending'||$task['title']!==$action['title']||$task['due_at']!==$action['due_at']||$task['owner']!==$action['owner']){
                $status='needs_review';$summary='The original follow-up was changed or completed. No automatic reversal was made.';
            }else{
                query("UPDATE tasks SET status='cancelled' WHERE workspace_id=? AND id=? AND status='pending'",[workspace(),$task['id']]);
                audit($work['lead_id'],'task_cancelled',['title'=>$task['title'],'reason'=>'Requested reversal of agent work '.$original['id']]);
                $changes[]=['type'=>'cancel_follow_up','task_id'=>$task['id']];
                $summary='Cancelled the unchanged follow-up created by the earlier agent run.';
            }
        }else{$summary='The earlier report recorded no reversible changes.';}
        $result=['summary'=>$summary,'actions'=>$changes,'original_work_id'=>$original['id']];
        query('UPDATE agent_work_items SET status=?,completed_at=UTC_TIMESTAMP(),result=? WHERE workspace_id=? AND id=?',[$status,json_encode($result,JSON_THROW_ON_ERROR),workspace(),$id]);
        db()->commit();return ['ok'=>true,'status'=>$status]+$result;
    }
    fail('Unknown write tool',404);
}
