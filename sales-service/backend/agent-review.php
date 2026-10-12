<?php
declare(strict_types=1);

// Human review is deliberately session-only; it is not an MCP/poller operation.
function reviewAgentWork(string $id,array $data): array {
    requireRole(['owner','manager','salesperson']);
    foreach(array_keys($data) as $key)if(!in_array($key,['action','reason','review_token','request_id'],true))fail('Unexpected review field',422);
    $action=textField($data,'action',30,true);
    $reason=textField($data,'reason',1000,true);
    $token=textField($data,'review_token',64,true);
    $request=textField($data,'request_id',64,true);
    if(!in_array($action,['close','request_correction'],true)||!preg_match('/^[a-f0-9]{64}$/',$token)||!preg_match('/^[a-zA-Z0-9-]{16,64}$/',$request))fail('Invalid review request',422);
    $fingerprint=hash('sha256',json_encode([$action,$reason,$token,$request],JSON_THROW_ON_ERROR));
    db()->beginTransaction();
    try{
        $work=query('SELECT w.*,l.owner_user_id,l.collector_user_id FROM agent_work_items w JOIN leads l ON l.id=w.lead_id AND l.workspace_id=w.workspace_id WHERE w.workspace_id=? AND w.id=? FOR UPDATE',[workspace(),$id])->fetch();
        if(!$work||(!in_array(role(),['owner','manager'],true)&&$work['owner_user_id']!==$_SESSION['user_id']))fail('Agent work item not found',404);
        $result=$work['result']?json_decode($work['result'],true,32,JSON_THROW_ON_ERROR):[];
        if($work['status']==='closed'&&($result['human_review']['fingerprint']??'')===$fingerprint&&($result['human_review']['user_id']??'')===$_SESSION['user_id']){
            db()->commit();return ['ok'=>true,'status'=>'closed','next_work_id'=>$result['human_review']['next_work_id']??null];
        }
        if($work['status']!=='needs_review'||!hash_equals(hash('sha256',$work['result']??''),$token))fail('This review changed or was already closed. Refresh Agent activity.',409);
        $next=null;
        if($action==='request_correction'){
            $next=uid();$activity=uid();
            $context=['reviewed_work_id'=>$id,'instruction'=>$reason,'instructions'=>$reason,'requested_by_user_id'=>$_SESSION['user_id'],'agent_report'=>$result,'original_source_activity_id'=>$work['source_activity_id']];
            query('INSERT INTO activities (id,workspace_id,lead_id,kind,body,actor) VALUES (?,?,?,?,?,?)',[$activity,workspace(),$work['lead_id'],'agent_correction_requested',json_encode($context,JSON_THROW_ON_ERROR),$_SESSION['user_name']]);
            // A correction is new work, not a follow-up task or an automatic retry/reversal.
            query('INSERT INTO agent_work_items (id,workspace_id,lead_id,source_activity_id,kind) VALUES (?,?,?,?,?)',[$next,workspace(),$work['lead_id'],$activity,'user_request']);
        }
        $review=['action'=>$action,'reason'=>$reason,'user_id'=>$_SESSION['user_id'],'actor'=>$_SESSION['user_name'],'at'=>gmdate('Y-m-d H:i:s'),'request_id'=>$request,'fingerprint'=>$fingerprint,'next_work_id'=>$next];
        $result['human_review']=$review;
        query("UPDATE agent_work_items SET status='closed',result=? WHERE workspace_id=? AND id=?",[json_encode($result,JSON_THROW_ON_ERROR),workspace(),$id]);
        audit($work['lead_id'],'agent_work_reviewed',['work_id'=>$id]+$review);
        db()->commit();return ['ok'=>true,'status'=>'closed','next_work_id'=>$next];
    }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
}
