<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/backend/bootstrap.php';
require dirname(__DIR__).'/backend/projects.php';
require dirname(__DIR__).'/backend/hosted-writes.php';
$base='http://127.0.0.1:8788/api';$cookie='';$csrf='';
function agentCheck(bool $condition,string $label): void {if(!$condition)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label."\n";}
function agentApi(string $path,?array $body=null,?string $bearer=null): array {
    global $base,$cookie,$csrf;
    $h=curl_init($base.$path);$headers=['Accept: application/json'];
    if($cookie)$headers[]='Cookie: '.$cookie;
    if($bearer)$headers[]='Authorization: Bearer '.$bearer;
    if($body!==null){curl_setopt($h,CURLOPT_POST,true);curl_setopt($h,CURLOPT_POSTFIELDS,json_encode($body,JSON_THROW_ON_ERROR));$headers[]='Content-Type: application/json';if($csrf)$headers[]='X-CSRF-Token: '.$csrf;}
    curl_setopt_array($h,[CURLOPT_HTTPHEADER=>$headers,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_TIMEOUT=>10]);
    $raw=curl_exec($h);if($raw===false)throw new RuntimeException(curl_error($h));
    $status=curl_getinfo($h,CURLINFO_RESPONSE_CODE);$length=curl_getinfo($h,CURLINFO_HEADER_SIZE);curl_close($h);
    $head=substr($raw,0,$length);if(preg_match('/^Set-Cookie:\s*([^;\r\n]+)/mi',$head,$matches))$cookie=$matches[1];
    return [$status,json_decode(substr($raw,$length),true)];
}
$workspace=uid();$user=uid();$lead=uid();$email='agent-'.bin2hex(random_bytes(6)).'@local.test';$password=bin2hex(random_bytes(12));
try{
    query('INSERT INTO workspaces (id,name) VALUES (?,?)',[$workspace,'Agent automation test']);
    query('INSERT INTO users (id,workspace_id,name,email,password_hash,role) VALUES (?,?,?,?,?,?)',[$user,$workspace,'Test Owner',$email,password_hash($password,PASSWORD_DEFAULT),'owner']);
    $project=defaultProject($workspace);
    query('INSERT INTO leads (id,workspace_id,project_id,name,organisation,notes,owner,owner_user_id) VALUES (?,?,?,?,?,?,?,?)',[$lead,$workspace,$project,'Automation Lead','Test Co','','Test Owner',$user]);
    $verifier=bin2hex(random_bytes(32));$challenge=hash('sha256',$verifier);
    [$status,$pair]=agentApi('/automation/pair/start',['challenge'=>$challenge,'device_name'=>'Test PC']);
    agentCheck($status===200&&preg_match('/^[a-f0-9]{32}$/',$pair['id']??''),'pairing request starts without model');
    [$status,$waiting]=agentApi('/automation/pair/finish',['id'=>$pair['id'],'verifier'=>$verifier]);
    agentCheck($status===202&&$waiting['status']==='pending','polling token is withheld before owner approval');
    [, $session]=agentApi('/session');$csrf=$session['csrf'];
    [$status]=agentApi('/login',['email'=>$email,'password'=>$password]);agentCheck($status===200,'owner signs in for pairing');
    [$status,$info]=agentApi('/automation/pair/'.$pair['id']);agentCheck($status===200&&$info['device_name']==='Test PC','owner sees the device being paired');
    [$status]=agentApi('/automation/pair/'.$pair['id'].'/approve',[]);agentCheck($status===200,'owner approves narrow poller access');
    [$status,$finished]=agentApi('/automation/pair/finish',['id'=>$pair['id'],'verifier'=>$verifier]);$token=$finished['token']??'';
    agentCheck($status===200&&strlen($token)===43,'paired computer receives its credential');
    [$status,$empty]=agentApi('/automation/ready',null,$token);agentCheck($status===200&&$empty['ready']===false,'empty queue costs no model call');
    $activity=uid();$work=uid();
    query('INSERT INTO activities (id,workspace_id,lead_id,kind,body,actor) VALUES (?,?,?,?,?,?)',[$activity,$workspace,$lead,'note','{"text":"Lead was unavailable"}','Test Owner']);
    query('INSERT INTO agent_work_items (id,workspace_id,lead_id,source_activity_id,kind) VALUES (?,?,?,?,?)',[$work,$workspace,$lead,$activity,'busy_call']);
    [$status,$ready]=agentApi('/automation/ready',null,$token);agentCheck($status===200&&$ready['ready']===true,'new work is visible to deterministic poller');
    $_SESSION=['workspace_id'=>$workspace,'user_id'=>$user,'user_name'=>'Test Owner','user_role'=>'owner'];
    $scheduled=hostedWrite('schedule_agent_followup',['id'=>$work,'title'=>'Call again','due_at'=>gmdate('Y-m-d\TH:i:s',time()+86400).'.000Z','summary'=>'Created a follow-up after the busy note.']);
    [$status,$reports]=agentApi('/agent-reports');agentCheck($status===200&&($reports[0]['result']['actions'][0]['task_id']??'')===$scheduled['task_id'],'user report records the exact action');
    [$status,$reversal]=agentApi('/agent-work/'.$work.'/request-reversal',['reason'=>'No longer needed']);
    agentCheck($status===200&&isset($reversal['work_id']),'user request queues separate reversal work');
    $undone=hostedWrite('reverse_agent_work',['id'=>$reversal['work_id']]);
    agentCheck(($undone['status']??'')==='done'&&query('SELECT status FROM tasks WHERE id=?',[$scheduled['task_id']])->fetchColumn()==='cancelled','agent reverses an unchanged pending follow-up');
    $activity2=uid();$work2=uid();
    query('INSERT INTO activities (id,workspace_id,lead_id,kind,body,actor) VALUES (?,?,?,?,?,?)',[$activity2,$workspace,$lead,'note','{"text":"Another missed call"}','Test Owner']);
    query('INSERT INTO agent_work_items (id,workspace_id,lead_id,source_activity_id,kind) VALUES (?,?,?,?,?)',[$work2,$workspace,$lead,$activity2,'busy_call']);
    $scheduled2=hostedWrite('schedule_agent_followup',['id'=>$work2,'title'=>'Second call','due_at'=>gmdate('Y-m-d\TH:i:s',time()+172800).'.000Z','summary'=>'Made a second internal follow-up.']);
    query('UPDATE tasks SET due_at=DATE_ADD(due_at,INTERVAL 1 HOUR) WHERE id=?',[$scheduled2['task_id']]);
    [$status,$reversal2]=agentApi('/agent-work/'.$work2.'/request-reversal',['reason'=>'Check this changed follow-up']);
    $changed=hostedWrite('reverse_agent_work',['id'=>$reversal2['work_id']]);
    agentCheck(($changed['status']??'')==='needs_review'&&query('SELECT status FROM tasks WHERE id=?',[$scheduled2['task_id']])->fetchColumn()==='pending','changed follow-up is left for human review');
    [$status,$empty]=agentApi('/automation/ready',null,$token);agentCheck($status===200&&$empty['ready']===false,'resolved queue returns to empty');
    [$status]=agentApi('/automation/revoke-self',[],$token);agentCheck($status===200,'local uninstall can revoke polling credential');
    [$status]=agentApi('/automation/ready',null,$token);agentCheck($status===401,'revoked poller cannot check the queue');
}finally{
    query('DELETE FROM agent_poll_tokens WHERE workspace_id=?',[$workspace]);
    query('DELETE FROM agent_poll_pairings WHERE workspace_id=?',[$workspace]);
    query('DELETE FROM agent_work_items WHERE workspace_id=?',[$workspace]);
    query('DELETE FROM activities WHERE workspace_id=?',[$workspace]);
    query('DELETE FROM tasks WHERE workspace_id=?',[$workspace]);
    query('DELETE FROM opportunities WHERE workspace_id=?',[$workspace]);
    query('DELETE FROM contacts WHERE workspace_id=?',[$workspace]);
    query('DELETE FROM organisations WHERE workspace_id=?',[$workspace]);
    query('DELETE FROM leads WHERE workspace_id=?',[$workspace]);
    query('DELETE FROM projects WHERE workspace_id=?',[$workspace]);
    query('DELETE FROM users WHERE workspace_id=?',[$workspace]);
    query('DELETE FROM workspaces WHERE id=?',[$workspace]);
}
