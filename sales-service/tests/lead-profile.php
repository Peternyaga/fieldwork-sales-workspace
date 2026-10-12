<?php
declare(strict_types=1);
require dirname(__DIR__).'/backend/bootstrap.php';
require dirname(__DIR__).'/backend/projects.php';
require dirname(__DIR__).'/backend/hosted-writes.php';
require_once dirname(__DIR__).'/backend/lead-records.php';
$wid=uid();$user=uid();$other=uid();$lead=uid();$password=uid();$cookie='';$csrf='';
function profileApi(string $route,?array $body=null): array {
    global $cookie,$csrf;
    $curl=curl_init('http://127.0.0.1:8788/api'.$route);$headers=['Accept: application/json'];
    if($cookie)$headers[]='Cookie: '.$cookie;
    if($body!==null){curl_setopt($curl,CURLOPT_POST,true);curl_setopt($curl,CURLOPT_POSTFIELDS,json_encode($body));$headers[]='Content-Type: application/json';$headers[]='X-CSRF-Token: '.$csrf;}
    curl_setopt_array($curl,[CURLOPT_HTTPHEADER=>$headers,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_TIMEOUT=>10]);
    $raw=curl_exec($curl);if($raw===false)throw new RuntimeException(curl_error($curl));
    $status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);$length=curl_getinfo($curl,CURLINFO_HEADER_SIZE);curl_close($curl);
    if(preg_match('/^Set-Cookie:\s*([^;\r\n]+)/mi',substr($raw,0,$length),$match))$cookie=$match[1];
    return [$status,json_decode(substr($raw,$length),true)];
}
function profileCheck(bool $ok,string $label): void {if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label."\n";}
function profileReject(callable $call,int $code,string $label): void {
    try{$call();}catch(RuntimeException $e){profileCheck($e->getCode()===$code,$label);return;}
    throw new RuntimeException('FAIL '.$label);
}
try{
    query('INSERT INTO workspaces (id,name) VALUES (?,?)',[$wid,'Disposable profile test']);
    foreach([$user,$other] as $id)query('INSERT INTO users (id,workspace_id,name,email,password_hash,role) VALUES (?,?,?,?,?,?)',[$id,$wid,'Profile tester',$id.'@example.test',password_hash($password,PASSWORD_DEFAULT),'owner']);
    query('INSERT INTO leads (id,workspace_id,project_id,name,organisation,phone,notes,owner_user_id) VALUES (?,?,?,?,?,?,?,?)',[$lead,$wid,defaultProject($wid),'Synthetic profile lead','Test business','+254700000001','',$user]);
    $row=query('SELECT * FROM leads WHERE id=?',[$lead])->fetch();materialiseLeadRecords($row);
    $_SESSION=['workspace_id'=>$wid,'user_id'=>$user,'user_name'=>'Profile tester','user_role'=>'owner'];
    audit($lead,'note',['text'=>'User supplies proprietor candidate 0700000002; not verified']);
    $source=query('SELECT id FROM activities WHERE lead_id=?',[$lead])->fetchColumn();
    $args=['id'=>$lead,'version'=>1,'value'=>'0700000002','provenance'=>'User note gives a proprietor candidate; identity and number not independently verified','source_activity_id'=>$source];
    $result=hostedWrite('update_lead_phone',$args);
    profileCheck($result['version']===2,'MCP phone correction advances lead version');
    profileCheck(query('SELECT phone FROM contacts WHERE source_lead_id=?',[$lead])->fetchColumn()==='0700000002','contact model stays synchronized');
    $history=json_decode(query("SELECT body FROM activities WHERE lead_id=? AND kind='profile_updated'",[$lead])->fetchColumn(),true);
    profileCheck($history['changes']['phone']['from']==='+254700000001'&&$history['changes']['phone']['to']==='0700000002'&&$history['source_activity_id']===$source&&str_contains($history['verification'],'not independently verified'),'old number and unverified provenance retained');
    profileReject(fn()=>hostedWrite('update_lead_phone',$args),409,'stale MCP retry rejected');
    profileReject(fn()=>editLeadProfile($lead,['version'=>2,'changes'=>['website'=>'javascript:alert(1)'],'provenance'=>'test']),422,'unsafe URL rejected');
    profileReject(fn()=>editLeadProfile($lead,['version'=>2,'changes'=>['website'=>'https://user:secret@example.com'],'provenance'=>'test']),422,'URL credentials rejected');
    profileReject(fn()=>editLeadProfile($lead,['version'=>2,'changes'=>['email'=>'invalid'],'provenance'=>'test']),422,'invalid email rejected');
    profileReject(fn()=>editLeadProfile($lead,['version'=>2,'changes'=>['owner_user_id'=>$other],'provenance'=>'test']),422,'profile edit cannot change authorization');
    profileReject(fn()=>editLeadProfile($lead,['version'=>2,'changes'=>['phone'=>'0700000002']]),422,'provenance required even on no-op');
    profileReject(fn()=>editLeadProfile($lead,['version'=>2,'changes'=>['location'=>'Nairobi'],'provenance'=>'test','source_activity_id'=>uid()]),404,'missing source note rejected');
    profileCheck(!db()->inTransaction(),'failed edit rolls back transaction');
    $_SESSION['user_role']='collector';profileReject(fn()=>hostedWrite('update_lead_phone',array_merge($args,['version'=>2])),403,'collector cannot edit profile');
    $_SESSION['user_role']='salesperson';$_SESSION['user_id']=$other;
    profileReject(fn()=>hostedWrite('update_lead_phone',array_merge($args,['version'=>2])),404,'unassigned salesperson cannot edit profile');
    $_SESSION['workspace_id']=uid();$_SESSION['user_role']='owner';
    profileReject(fn()=>hostedWrite('update_lead_phone',array_merge($args,['version'=>2])),404,'cross-workspace edit denied');
    $_SESSION['workspace_id']=$wid;$_SESSION['user_id']=$user;$_SESSION['user_role']='salesperson';
    $result=editLeadProfile($lead,['version'=>2,'changes'=>['email'=>'contact@example.test','website'=>'https://example.test','organisation'=>'Updated business','contact_role'=>'Proprietor candidate'],'provenance'=>'Supplied by user; role unconfirmed']);
    profileCheck($result['version']===3,'assigned salesperson can edit validated details atomically');
    profileCheck(query('SELECT o.name FROM contacts c JOIN organisations o ON o.id=c.organisation_id WHERE c.source_lead_id=?',[$lead])->fetchColumn()==='Updated business','organisation correction synchronized');
    profileCheck(editLeadProfile($lead,['version'=>3,'changes'=>['email'=>'contact@example.test'],'provenance'=>'repeat'])['changed']===false,'no-op preserves version');
    [$status,$session]=profileApi('/session');$csrf=$session['csrf'];
    [$status]=profileApi('/login',['email'=>$user.'@example.test','password'=>$password]);profileCheck($status===200,'web profile test login');
    $edit=['version'=>3,'changes'=>['location'=>'Nairobi'],'provenance'=>'User-supplied location'];
    [$status]=profileApi('/leads/'.$lead.'/profile',$edit);profileCheck($status===200,'session and CSRF protected web profile edit');
    [$status]=profileApi('/leads/'.$lead.'/profile',$edit);profileCheck($status===409,'web edit rejects stale form');
    $csrf='wrong';[$status]=profileApi('/leads/'.$lead.'/profile',array_merge($edit,['version'=>4]));profileCheck($status===403,'web profile edit requires CSRF');
}finally{
    if(db()->inTransaction())db()->rollBack();
    foreach(['activities','opportunities','contacts','organisations','leads','projects','users'] as $table)query("DELETE FROM $table WHERE workspace_id=?",[$wid]);
    query('DELETE FROM workspaces WHERE id=?',[$wid]);
}
