<?php
declare(strict_types=1);
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if(!str_starts_with($path,'/api/')) {http_response_code(404);exit;}
require __DIR__.'/bootstrap.php';
require __DIR__.'/projects.php';
require __DIR__.'/intake.php';
require __DIR__.'/accounts.php';
require __DIR__.'/plugin.php';
require __DIR__.'/agent-poll.php';
require_once __DIR__.'/lead-profile.php';
require_once __DIR__.'/agent-review.php';
function cancelPendingLeadTasks(string $leadId,string $reason): int {
    $pending=query("SELECT id,title FROM tasks WHERE workspace_id=? AND lead_id=? AND status='pending' FOR UPDATE",[workspace(),$leadId])->fetchAll();
    foreach($pending as $task){
        query("UPDATE tasks SET status='cancelled' WHERE workspace_id=? AND id=? AND status='pending'",[workspace(),$task['id']]);
        audit($leadId,'task_cancelled',['title'=>$task['title'],'reason'=>$reason]);
    }
    return count($pending);
}
$sessionDir=dirname(__DIR__).'/var/sessions';
if(!is_dir($sessionDir)) mkdir($sessionDir,0700,true);
session_save_path($sessionDir);
session_set_cookie_params(['httponly'=>true,'samesite'=>'Strict','secure'=>isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off']);
session_start();
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
try {
    $method=$_SERVER['REQUEST_METHOD'];
    if(in_array($path,['/api/automation/ready','/api/automation/revoke-self','/api/automation/pair/start','/api/automation/pair/finish'],true))pollPublic($path,$method);
    if(!in_array($method,['GET','POST'],true)) fail('Method not allowed',405);
    if((int)($_SERVER['CONTENT_LENGTH']??0)>1048576) fail('Request too large',413);
    $data=$method==='POST'?json_decode(file_get_contents('php://input'),true,32,JSON_THROW_ON_ERROR):[];
    if(!is_array($data)) fail('Expected an object');
    $_SESSION['csrf']??=bin2hex(random_bytes(24));
    if($method==='POST'&&!hash_equals($_SESSION['csrf'],$_SERVER['HTTP_X_CSRF_TOKEN']??'')) fail('Refresh the page and try again',403);
    if(isset($_SESSION['user_id'])){
        $actor=query('SELECT id,name,workspace_id,role,active,auth_version FROM users WHERE id=?',[$_SESSION['user_id']])->fetch();
        if(!$actor||!$actor['active']||(int)$actor['auth_version']!==(int)($_SESSION['auth_version']??1)){unset($_SESSION['user_id'],$_SESSION['user_name'],$_SESSION['workspace_id'],$_SESSION['user_role'],$_SESSION['auth_version']);}
        else {$_SESSION['user_name']=$actor['name'];$_SESSION['workspace_id']=$actor['workspace_id'];$_SESSION['user_role']=$actor['role'];$_SESSION['auth_version']=(int)$actor['auth_version'];}
    }
    $out=null;
    if($path==='/api/bootstrap-status'&&$method==='GET') {
        $available=false;
        if(localBootstrapAllowed()){
            require_once __DIR__.'/migrations.php';applySchema();
            $available=(int)query('SELECT COUNT(*) FROM workspaces')->fetchColumn()===0;
        }
        $out=['available'=>$available];
    } elseif($path==='/api/bootstrap-owner'&&$method==='POST') {
        if(!localBootstrapAllowed())fail('Local setup is unavailable',403);
        require_once __DIR__.'/migrations.php';applySchema();
        $business=textField($data,'business',150,true);$name=textField($data,'name',150,true);
        $email=strtolower(textField($data,'email',254,true));$password=textField($data,'password',500,true);
        if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($password)<12)fail('Check email and use a password of at least 12 characters');
        db()->beginTransaction();
        if((int)query('SELECT COUNT(*) FROM workspaces')->fetchColumn()!==0)fail('Workspace setup is already complete',409);
        $workspaceId=uid();$userId=uid();
        query('INSERT INTO workspaces (id,name) VALUES (?,?)',[$workspaceId,$business]);
        query('INSERT INTO users (id,workspace_id,name,email,password_hash,role) VALUES (?,?,?,?,?,?)',[$userId,$workspaceId,$name,$email,password_hash($password,PASSWORD_DEFAULT),'owner']);
        db()->commit();
        session_regenerate_id(true);$_SESSION['user_id']=$userId;$_SESSION['user_name']=$name;$_SESSION['workspace_id']=$workspaceId;$_SESSION['user_role']='owner';$_SESSION['auth_version']=1;
        $out=['ok'=>true];
    } elseif($path==='/api/plugin/auth'&&$method==='GET') {
        $out=authenticateLocalPlugin();
    } elseif($path==='/api/session'&&$method==='GET') {
        $out=['csrf'=>$_SESSION['csrf'],'user'=>isset($_SESSION['user_id'])?['id'=>$_SESSION['user_id'],'name'=>$_SESSION['user_name'],'role'=>role(),'workspace'=>query('SELECT name FROM workspaces WHERE id=?',[workspace()])->fetchColumn()]:null];
    } elseif($path==='/api/account/token-info'&&$method==='POST') {
        $record=findAccountToken(textField($data,'token',64,true));
        $out=['name'=>$record['name'],'email'=>$record['email'],'workspace'=>$record['workspace_name'],'purpose'=>$record['purpose']];
    } elseif($path==='/api/account/redeem'&&$method==='POST') {
        redeemAccountToken(textField($data,'token',64,true),textField($data,'password',500,true));
        $out=['ok'=>true];
    } elseif($path==='/api/login'&&$method==='POST') {
        $email=textField($data,'email',254,true);$password=textField($data,'password',500,true);
        $user=query('SELECT * FROM users WHERE email=? AND active=1',[$email])->fetch();
        if(!$user||!password_verify($password,$user['password_hash'])) {usleep(400000);fail('Email or password is incorrect',401);}
        session_regenerate_id(true);$_SESSION['user_id']=$user['id'];$_SESSION['user_name']=$user['name'];$_SESSION['workspace_id']=$user['workspace_id'];$_SESSION['user_role']=$user['role'];$_SESSION['auth_version']=(int)$user['auth_version'];
        $out=['ok'=>true];
    } else {
        if(!isset($_SESSION['user_id'])) fail('Sign in to continue',401);
        if($path==='/api/logout'&&$method==='POST') {$_SESSION=[];session_destroy();$out=['ok'=>true];}
        elseif($path==='/api/plugin/connect'&&$method==='POST') {$out=connectLocalPlugin();}
        elseif($path==='/api/plugin/status'&&$method==='GET') {$out=localPluginStatus();}
        elseif($path==='/api/oauth/connections'&&$method==='GET') {
            $out=query('SELECT g.id,c.name,g.scope,g.created_at,g.access_expires_at,g.refresh_expires_at FROM oauth_grants g JOIN oauth_clients c ON c.id=g.client_id WHERE g.workspace_id=? AND g.user_id=? AND g.revoked_at IS NULL AND g.refresh_expires_at>UTC_TIMESTAMP() ORDER BY g.created_at DESC', [workspace(),$_SESSION['user_id']])->fetchAll();
        }
        elseif(preg_match('#^/api/automation/pair/([a-f0-9]{32})$#',$path,$pairMatch)&&$method==='GET') {
            requireRole(['owner']);
            $pair=query('SELECT id,device_name,approved_at,used_at,expires_at FROM agent_poll_pairings WHERE id=?',[$pairMatch[1]])->fetch();
            if(!$pair||$pair['expires_at']<=gmdate('Y-m-d H:i:s')||$pair['used_at'])fail('Setup request expired',404);
            $out=['id'=>$pair['id'],'device_name'=>$pair['device_name'],'approved'=>$pair['approved_at']!==null,'expires_at'=>$pair['expires_at']];
        }
        elseif(preg_match('#^/api/automation/pair/([a-f0-9]{32})/approve$#',$path,$pairMatch)&&$method==='POST') {
            requireRole(['owner']);
            $changed=query('UPDATE agent_poll_pairings SET workspace_id=?,user_id=?,approved_at=UTC_TIMESTAMP() WHERE id=? AND workspace_id IS NULL AND used_at IS NULL AND expires_at>UTC_TIMESTAMP()',[workspace(),$_SESSION['user_id'],$pairMatch[1]]);
            if(!$changed->rowCount())fail('Setup request expired or already approved',409);
            $out=['ok'=>true];
        }
        elseif($path==='/api/automation/pollers'&&$method==='GET') {
            $out=query('SELECT id,created_at,last_used_at FROM agent_poll_tokens WHERE workspace_id=? AND user_id=? AND revoked_at IS NULL ORDER BY created_at DESC',[workspace(),$_SESSION['user_id']])->fetchAll();
        }
        elseif(preg_match('#^/api/automation/pollers/([a-f0-9]{32})/revoke$#',$path,$pollMatch)&&$method==='POST') {
            requireRole(['owner']);
            $changed=query('UPDATE agent_poll_tokens SET revoked_at=UTC_TIMESTAMP() WHERE id=? AND workspace_id=? AND user_id=? AND revoked_at IS NULL',[$pollMatch[1],workspace(),$_SESSION['user_id']]);
            if(!$changed->rowCount())fail('Poller not found',404);
            $out=['ok'=>true];
        }
        elseif(preg_match('#^/api/oauth/connections/([a-f0-9]{32})/revoke$#',$path,$connectionMatch)&&$method==='POST') {
            $changed=query('UPDATE oauth_grants SET revoked_at=UTC_TIMESTAMP() WHERE id=? AND workspace_id=? AND user_id=? AND revoked_at IS NULL',[$connectionMatch[1],workspace(),$_SESSION['user_id']]);
            if(!$changed->rowCount())fail('Connection not found',404);
            $out=['ok'=>true];
        }
        elseif($path==='/api/team/members'&&$method==='GET') {
            requireRole(['owner']);
            $out=query('SELECT id,name,email,role,active,setup_pending FROM users WHERE workspace_id=? ORDER BY name',[workspace()])->fetchAll();
        }
        elseif($path==='/api/team/invite'&&$method==='POST') {
            requireRole(['owner']);
            $email=strtolower(textField($data,'email',254,true));$name=textField($data,'name',150,true);$memberRole=textField($data,'role',20,true);
            if(!filter_var($email,FILTER_VALIDATE_EMAIL)||!in_array($memberRole,['manager','salesperson','collector'],true))fail('Check member details');
            if(query('SELECT id FROM users WHERE email=?',[$email])->fetch())fail('This email already has an account',409);
            db()->beginTransaction();$id=uid();
            query('INSERT INTO users (id,workspace_id,name,email,password_hash,role,active,setup_pending) VALUES (?,?,?,?,?,?,0,1)',[$id,workspace(),$name,$email,password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT),$memberRole]);
            $out=issueAccountToken(['id'=>$id,'workspace_id'=>workspace()],'invite');
            db()->commit();
        }
        elseif(preg_match('#^/api/team/member/([a-f0-9]{32})/(reset|invite)$#',$path,$memberMatch)&&$method==='POST') {
            requireRole(['owner']);
            $member=query('SELECT id,workspace_id,active,setup_pending FROM users WHERE id=? AND workspace_id=?',[$memberMatch[1],workspace()])->fetch();
            if(!$member)fail('Team member not found',404);
            $purpose=$memberMatch[2];
            if($purpose==='invite'&&(!$member['setup_pending']||$member['active']))fail('Member is not awaiting setup',409);
            if($purpose==='reset'&&(!$member['active']||$member['setup_pending']))fail('Member is not active',409);
            db()->beginTransaction();$out=issueAccountToken($member,$purpose);db()->commit();
        }
        elseif($path==='/api/team'&&$method==='GET') {
            requireRole(['owner','manager']);
            $out=query("SELECT id,name,role FROM users WHERE workspace_id=? AND active=1 AND role IN ('owner','manager','salesperson') ORDER BY name",[workspace()])->fetchAll();
        }
        elseif($path==='/api/projects'&&$method==='GET') {
            defaultProject(workspace());
            $out=query('SELECT id,name,created_at FROM projects WHERE workspace_id=? ORDER BY CASE WHEN name=? THEN 1 ELSE 0 END,name',[workspace(),'General'])->fetchAll();
        }
        elseif($path==='/api/projects'&&$method==='POST') {
            requireRole(['owner','manager']);
            $name=textField($data,'name',150,true);
            if(query('SELECT id FROM projects WHERE workspace_id=? AND name=?',[workspace(),$name])->fetch())fail('A project with this name already exists',409);
            $id=uid();query('INSERT INTO projects (id,workspace_id,name) VALUES (?,?,?)',[$id,workspace(),$name]);$out=['id'=>$id,'name'=>$name];
        }
        elseif($path==='/api/opportunities'&&$method==='GET') {
            requireRole(['owner','manager','salesperson']);
            $scope=in_array(role(),['owner','manager'],true)?'':' AND (l.owner_user_id=? OR l.collector_user_id=?)';
            $args=in_array(role(),['owner','manager'],true)?[workspace()]:[workspace(),$_SESSION['user_id'],$_SESSION['user_id']];
            if($project=requestedProject()){$scope.=' AND l.project_id=?';$args[]=$project;}
            $out=query("SELECT o.id,o.contact_id,o.offering,o.stage,o.version,o.created_at,c.source_lead_id AS lead_id,l.name,l.organisation,l.owner,l.owner_user_id FROM opportunities o JOIN contacts c ON c.id=o.contact_id AND c.workspace_id=o.workspace_id JOIN leads l ON l.id=c.source_lead_id AND l.workspace_id=c.workspace_id WHERE o.workspace_id=?$scope ORDER BY o.updated_at DESC LIMIT 500",$args)->fetchAll();
        }
        elseif(preg_match('#^/api/opportunities/([a-f0-9]{32})/update$#',$path,$opMatch)&&$method==='POST') {
            requireRole(['owner','manager','salesperson']);
            $op=query('SELECT o.*,c.source_lead_id AS lead_id,l.owner_user_id,l.collector_user_id FROM opportunities o JOIN contacts c ON c.id=o.contact_id AND c.workspace_id=o.workspace_id JOIN leads l ON l.id=c.source_lead_id AND l.workspace_id=c.workspace_id WHERE o.workspace_id=? AND o.id=?',[workspace(),$opMatch[1]])->fetch();
            if(!$op||!canAccessLead($op))fail('Opportunity not found',404);
            $stage=textField($data,'stage',30,true);$version=$data['version']??null;
            if(!in_array($stage,['New','Contacted','Engaged','Qualified','Proposal','Negotiation','Won','Lost'],true)||!is_int($version))fail('Invalid stage or version');
            db()->beginTransaction();
            $changed=query('UPDATE opportunities SET stage=?,version=version+1 WHERE workspace_id=? AND id=? AND version=?',[$stage,workspace(),$op['id'],$version]);
            if(!$changed->rowCount())fail('This opportunity changed. Reload before editing.',409);
            if($op['source_lead_id']!==null){
                query('UPDATE leads SET stage=?,version=version+1 WHERE workspace_id=? AND id=?',[$stage,workspace(),$op['lead_id']]);
                if($stage==='Lost')cancelPendingLeadTasks($op['lead_id'],'Initial opportunity marked Lost');
            }
            audit($op['lead_id'],'opportunity_updated',['opportunity_id'=>$op['id'],'stage'=>$stage]);db()->commit();$out=['ok'=>true];
        }
        elseif($path==='/api/leads'&&$method==='GET') {
            $manager=in_array(role(),['owner','manager'],true);$collector=role()==='collector';
            $scope=$manager?'':($collector?' AND l.collector_user_id=?':' AND (l.owner_user_id=? OR l.collector_user_id=?)');
            $args=$manager?[workspace()]:($collector?[workspace(),$_SESSION['user_id']]:[workspace(),$_SESSION['user_id'],$_SESSION['user_id']]);
            if($project=requestedProject()){$scope.=' AND l.project_id=?';$args[]=$project;}
            $fields=$collector?'l.id,l.name,l.organisation,l.stage,l.source,l.offering,l.owner,l.owner_user_id,l.collector,l.collector_user_id,l.version,l.created_at':'l.*';
            $next=$collector?'NULL':"(SELECT MIN(due_at) FROM tasks t WHERE t.workspace_id=l.workspace_id AND t.lead_id=l.id AND t.status='pending')";
            $order=$collector?'l.updated_at DESC':'next_due IS NULL,next_due ASC,l.name ASC';
            $out=query("SELECT $fields, $next AS next_due FROM leads l WHERE l.workspace_id=?$scope ORDER BY $order LIMIT 500",$args)->fetchAll();
        } elseif($path==='/api/leads'&&$method==='POST') {
            $project=resolveProject(isset($data['project_id'])?textField($data,'project_id',32,true):null,workspace());
            $out=createLead(leadValues($data),textField($data,'request_key',80,true),$project);
            if(isset($out['matches']))http_response_code(409);
        } elseif($path==='/api/import/preview'&&$method==='POST') {
            $project=resolveProject(isset($data['project_id'])?textField($data,'project_id',32,true):null,workspace());
            $out=['rows'=>csvRows(textField($data,'csv',900000,true),$project)];
        } elseif($path==='/api/import/commit'&&$method==='POST') {
            $project=resolveProject(isset($data['project_id'])?textField($data,'project_id',32,true):null,workspace());
            $csv=textField($data,'csv',900000,true);$batch=textField($data,'batch_key',40,true);
            if(!preg_match('/^[a-f0-9-]{36}$/',$batch))fail('Invalid batch key');
            $rows=csvRows($csv,$project);$results=[];
            foreach($rows as $row){
                if($row['status']!=='ready'){$results[]=['line'=>$row['line'],'status'=>$row['status'],'reason'=>$row['reason']];continue;}
                $created=createLead($row['values'],hash('sha256',$batch.':'.$row['line']),$project);
                $results[]=['line'=>$row['line'],'status'=>isset($created['matches'])?'duplicate_existing':(isset($created['replayed'])?'already_imported':'imported'),'id'=>$created['id']??null];
            }
            $out=['rows'=>$results];
        } elseif(preg_match('#^/api/leads/([a-f0-9]{32})(?:/(notes|agent-requests|update|close|tasks|assign|project|opportunities|profile))?$#',$path,$match)) {
            $id=$match[1];$lead=query('SELECT * FROM leads WHERE workspace_id=? AND id=?',[workspace(),$id])->fetch();if(!$lead) fail('Lead not found',404);
            $action=$match[2]??'';
            if(in_array($action,['assign','project'],true))requireRole(['owner','manager']);
            elseif(!canAccessLead($lead))fail('Lead not found',404);
            if($method==='GET'&&$action==='') {
                if(role()==='collector'){
                    $public=['id','name','organisation','stage','source','offering','owner','owner_user_id','collector','collector_user_id','version','created_at'];
                    $out=['lead'=>array_intersect_key($lead,array_flip($public)),'activities'=>[],'tasks'=>[]];
                }else $out=['lead'=>$lead,'activities'=>query('SELECT * FROM activities WHERE workspace_id=? AND lead_id=? ORDER BY created_at DESC,id DESC',[workspace(),$id])->fetchAll(),'tasks'=>query('SELECT * FROM tasks WHERE workspace_id=? AND lead_id=? ORDER BY due_at',[workspace(),$id])->fetchAll(),'opportunities'=>query('SELECT o.id,o.offering,o.stage,o.version FROM opportunities o JOIN contacts c ON c.id=o.contact_id AND c.workspace_id=o.workspace_id WHERE o.workspace_id=? AND c.source_lead_id=? ORDER BY o.created_at,o.id',[workspace(),$id])->fetchAll()];
            }
            elseif($method==='POST'&&$action==='profile') {$out=editLeadProfile($id,$data);}
            elseif($method==='POST'&&$action==='project') {
                $projectId=resolveProject(textField($data,'project_id',32,true),workspace());$version=$data['version']??null;
                if(!is_int($version))fail('Invalid lead version');
                if($projectId===$lead['project_id']){$out=['ok'=>true];}
                else {
                    if(matchingLeads(['email'=>$lead['email'],'phone'=>$lead['phone']],$projectId))fail('A matching contact exists in the destination project. Review it before moving.',409);
                    db()->beginTransaction();
                    $changed=query('UPDATE leads SET project_id=?,version=version+1 WHERE workspace_id=? AND id=? AND version=?',[$projectId,workspace(),$id,$version]);
                    if(!$changed->rowCount())fail('This lead changed. Reload before moving.',409);
                    audit($id,'project_moved',['from'=>$lead['project_id'],'to'=>$projectId]);db()->commit();$out=['ok'=>true];
                }
            }
            elseif($method==='POST'&&$action==='opportunities') {
                requireRole(['owner','manager','salesperson']);
                $offering=textField($data,'offering',150,true);$request=textField($data,'request_key',80,true);
                db()->beginTransaction();
                query('SELECT id FROM workspaces WHERE id=? FOR UPDATE',[workspace()]);
                $prior=query('SELECT o.id,o.offering,c.source_lead_id FROM opportunities o JOIN contacts c ON c.id=o.contact_id AND c.workspace_id=o.workspace_id WHERE o.workspace_id=? AND o.request_key=?',[workspace(),$request])->fetch();
                if($prior){if($prior['offering']!==$offering||$prior['source_lead_id']!==$id)fail('Request key already used for another opportunity',409);$out=['id'=>$prior['id'],'replayed'=>true];}
                else {
                    $contact=query('SELECT id FROM contacts WHERE workspace_id=? AND source_lead_id=?',[workspace(),$id])->fetch();
                    if(!$contact)fail('Contact record is unavailable',409);
                    $opportunityId=uid();
                    query("INSERT INTO opportunities (id,workspace_id,contact_id,offering,stage,request_key) VALUES (?,?,?,?,'New',?)",[$opportunityId,workspace(),$contact['id'],$offering,$request]);
                    audit($id,'opportunity_created',['opportunity_id'=>$opportunityId,'offering'=>$offering]);$out=['id'=>$opportunityId];
                }
                db()->commit();
            }
            elseif($method==='POST'&&$action==='agent-requests') {
                requireRole(['owner','manager','salesperson']);
                foreach(array_keys($data) as $key)if(!in_array($key,['instruction','request_key'],true))fail('Unexpected request field',422);
                $instruction=textField($data,'instruction',4000,true);
                $requestKey=textField($data,'request_key',64,true);
                if(!preg_match('/^[a-zA-Z0-9-]{16,64}$/',$requestKey))fail('Invalid request key',422);
                $workId=substr(hash('sha256',workspace().':'.$_SESSION['user_id'].':'.$requestKey),0,32);
                db()->beginTransaction();
                try {
                    $prior=query('SELECT w.id,w.lead_id,a.body FROM agent_work_items w JOIN activities a ON a.id=w.source_activity_id WHERE w.workspace_id=? AND w.id=? FOR UPDATE',[workspace(),$workId])->fetch();
                    if($prior){
                        $body=json_decode($prior['body'],true);
                        if($prior['lead_id']!==$id||($body['instruction']??null)!==$instruction||($body['requested_by_user_id']??null)!==$_SESSION['user_id'])fail('Request key already used',409);
                        $out=['id'=>$workId,'replayed'=>true];
                    }else{
                        $activityId=uid();
                        query('INSERT INTO activities (id,workspace_id,lead_id,kind,body,actor) VALUES (?,?,?,?,?,?)',[$activityId,workspace(),$id,'agent_request',json_encode(['instruction'=>$instruction,'requested_by_user_id'=>$_SESSION['user_id']],JSON_THROW_ON_ERROR),$_SESSION['user_name']]);
                        query('INSERT INTO agent_work_items (id,workspace_id,lead_id,source_activity_id,kind) VALUES (?,?,?,?,?)',[$workId,workspace(),$id,$activityId,'user_request']);
                        $out=['id'=>$workId,'queued'=>true];
                    }
                    db()->commit();
                }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
            }
            elseif($method==='POST'&&$action==='notes') {
                requireRole(['owner','manager','salesperson']);
                $note=textField($data,'note',10000,true);
                $outcome=textField($data,'outcome',30);
                if(!in_array($outcome,['','busy','no_answer','connected','other'],true))fail('Invalid call outcome',422);
                db()->beginTransaction();
                $activityId=uid();
                query('INSERT INTO activities (id,workspace_id,lead_id,kind,body,actor) VALUES (?,?,?,?,?,?)',[$activityId,workspace(),$id,'note',json_encode(['text'=>$note,'outcome'=>$outcome],JSON_THROW_ON_ERROR),$_SESSION['user_name']]);
                query('INSERT INTO agent_work_items (id,workspace_id,lead_id,source_activity_id,kind) VALUES (?,?,?,?,?)',[uid(),workspace(),$id,$activityId,$outcome==='busy'?'busy_call':'review_note']);
                db()->commit();$out=['ok'=>true,'queued_for_agent'=>true];
            }
            elseif($method==='POST'&&$action==='close') {
                requireRole(['owner','manager','salesperson']);
                $reason=textField($data,'reason',1000,true);$version=$data['version']??null;
                if(!is_int($version))fail('Invalid lead version');
                db()->beginTransaction();
                $changed=query("UPDATE leads SET stage='Lost',version=version+1 WHERE workspace_id=? AND id=? AND version=?",[workspace(),$id,$version]);
                if(!$changed->rowCount())fail('This lead changed. Reload before closing.',409);
                query("UPDATE opportunities SET stage='Lost',version=version+1 WHERE workspace_id=? AND source_lead_id=?",[workspace(),$id]);
                $cancelled=cancelPendingLeadTasks($id,$reason);
                audit($id,'lead_closed',['reason'=>$reason,'cancelled_tasks'=>$cancelled]);
                db()->commit();$out=['ok'=>true,'cancelled_tasks'=>$cancelled];
            }
            elseif($method==='POST'&&$action==='update') {
                requireRole(['owner','manager','salesperson']);
                $stage=textField($data,'stage',30,true);$version=$data['version']??null;
                if(!in_array($stage,['New','Contacted','Engaged','Qualified','Proposal','Negotiation','Won','Lost'],true)||!is_int($version)) fail('Invalid stage or version');
                db()->beginTransaction();$s=query('UPDATE leads SET stage=?,version=version+1 WHERE workspace_id=? AND id=? AND version=?',[$stage,workspace(),$id,$version]);
                if(!$s->rowCount()) fail('This lead changed. Reload before editing.',409);
                query('UPDATE opportunities SET stage=?,version=version+1 WHERE workspace_id=? AND source_lead_id=?',[$stage,workspace(),$id]);
                if($stage==='Lost')cancelPendingLeadTasks($id,'Lead marked Lost');
                audit($id,'updated',['stage'=>$stage]);db()->commit();$out=['ok'=>true];
            } elseif($method==='POST'&&$action==='assign') {
                $target=textField($data,'user_id',32,true);$version=$data['version']??null;
                if(!preg_match('/^[a-f0-9]{32}$/',$target)||!is_int($version))fail('Invalid assignee or version');
                $firstContact=textField($data,'first_contact_at',40);
                if($firstContact!==''){
                    if(!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/',$firstContact))fail('A resolved UTC time is required');
                    try{$firstDue=new DateTimeImmutable($firstContact);}catch(Throwable){fail('Invalid first-contact time');}
                }
                db()->beginTransaction();
                $member=query("SELECT id,name FROM users WHERE id=? AND workspace_id=? AND active=1 AND role IN ('owner','manager','salesperson')",[$target,workspace()])->fetch();
                if(!$member)fail('Choose an active salesperson from this workspace',422);
                $s=query('UPDATE leads SET owner_user_id=?,owner=?,version=version+1 WHERE workspace_id=? AND id=? AND version=?',[$member['id'],$member['name'],workspace(),$id,$version]);
                if(!$s->rowCount())fail('This lead changed. Reload before assigning.',409);
                query('INSERT INTO assignment_events (id,workspace_id,lead_id,from_user_id,to_user_id,assigned_by) VALUES (?,?,?,?,?,?)',[uid(),workspace(),$id,$lead['owner_user_id'],$member['id'],$_SESSION['user_id']]);
                audit($id,'assigned',['from'=>$lead['owner_user_id'],'to'=>$member['id'],'name'=>$member['name']]);
                if($firstContact!==''){
                    query('INSERT INTO tasks (id,workspace_id,lead_id,title,owner,due_at) VALUES (?,?,?,?,?,?)',[uid(),workspace(),$id,'Make first contact',$member['name'],$firstDue->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')]);
                    audit($id,'task_created',['title'=>'Make first contact','due_at'=>$firstContact]);
                }
                db()->commit();$out=['ok'=>true,'owner'=>$member['name']];
            } elseif($method==='POST'&&$action==='tasks') {
                requireRole(['owner','manager','salesperson']);
                if($lead['stage']==='Lost')fail('Reopen this lead before adding a follow-up',409);
                $title=textField($data,'title',250,true);$owner=textField($data,'owner',150,true);$date=textField($data,'due_at',40,true);
                if(!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/',$date)) fail('A resolved UTC time is required');
                try{$due=new DateTimeImmutable($date);}catch(Throwable){fail('Invalid date');}
                db()->beginTransaction();$task=uid();query('INSERT INTO tasks (id,workspace_id,lead_id,title,owner,due_at) VALUES (?,?,?,?,?,?)',[$task,workspace(),$id,$title,$owner,$due->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')]);audit($id,'task_created',['title'=>$title,'due_at'=>$date]);db()->commit();$out=['id'=>$task];
            } else fail('Route not found',404);
        } elseif($path==='/api/agent-work'&&$method==='GET') {
            requireRole(['owner','manager','salesperson']);
            $scope=in_array(role(),['owner','manager'],true)?'':" AND l.owner_user_id=?";
            $args=in_array(role(),['owner','manager'],true)?[workspace()]:[workspace(),$_SESSION['user_id']];
            $out=query("SELECT w.id,w.lead_id,w.kind,w.status,w.created_at,l.name,l.organisation,l.project_id,a.body AS source_note FROM agent_work_items w JOIN leads l ON l.id=w.lead_id AND l.workspace_id=w.workspace_id JOIN activities a ON a.id=w.source_activity_id WHERE w.workspace_id=? AND w.status='pending'$scope ORDER BY w.created_at,w.id LIMIT 50",$args)->fetchAll();
        }
        elseif($path==='/api/agent-reports'&&$method==='GET') {
            requireRole(['owner','manager','salesperson']);
            $scope=in_array(role(),['owner','manager'],true)?'':' AND l.owner_user_id=?';
            $params=in_array(role(),['owner','manager'],true)?[workspace()]:[workspace(),$_SESSION['user_id']];
            $rows=query("SELECT w.id,w.lead_id,w.kind,w.status,w.created_at,w.completed_at,w.result,w.parent_work_id,l.name,l.organisation,a.body AS source_note,p.result AS original_result,r.id AS reversal_id,r.status AS reversal_status FROM agent_work_items w JOIN leads l ON l.id=w.lead_id AND l.workspace_id=w.workspace_id JOIN activities a ON a.id=w.source_activity_id AND a.workspace_id=w.workspace_id LEFT JOIN agent_work_items p ON p.id=w.parent_work_id AND p.workspace_id=w.workspace_id LEFT JOIN agent_work_items r ON r.parent_work_id=w.id AND r.workspace_id=w.workspace_id WHERE w.workspace_id=?$scope ORDER BY CASE WHEN w.status='needs_review' THEN 0 WHEN w.status='pending' THEN 1 ELSE 2 END,w.created_at DESC,w.id DESC LIMIT 100",$params)->fetchAll();
            $out=array_map(static function(array $row): array {$row['review_token']=hash('sha256',$row['result']??'');foreach(['result','source_note','original_result'] as $key)$row[$key]=$row[$key]?json_decode($row[$key],true):null;return $row;},$rows);
        }
        elseif(preg_match('#^/api/agent-work/([a-f0-9]{32})/review$#',$path,$reviewMatch)&&$method==='POST') {
            $out=reviewAgentWork($reviewMatch[1],$data);
        }
        elseif(preg_match('#^/api/agent-work/([a-f0-9]{32})/request-reversal$#',$path,$reverseMatch)&&$method==='POST') {
            requireRole(['owner','manager','salesperson']);
            $reason=textField($data,'reason',1000,true);
            db()->beginTransaction();
            $original=query('SELECT w.*,l.owner_user_id,l.collector_user_id FROM agent_work_items w JOIN leads l ON l.id=w.lead_id AND l.workspace_id=w.workspace_id WHERE w.workspace_id=? AND w.id=? FOR UPDATE',[workspace(),$reverseMatch[1]])->fetch();
            if(!$original||!canAccessLead($original)||$original['kind']==='reverse_work')fail('Agent report not found',404);
            if($original['status']!=='done')fail('Only completed agent work can be reviewed for reversal',409);
            if(query('SELECT id FROM agent_work_items WHERE workspace_id=? AND parent_work_id=?',[workspace(),$original['id']])->fetch())fail('A reversal is already queued',409);
            $activity=uid();$workId=uid();
            query('INSERT INTO activities (id,workspace_id,lead_id,kind,body,actor) VALUES (?,?,?,?,?,?)',[$activity,workspace(),$original['lead_id'],'reversal_requested',json_encode(['original_work_id'=>$original['id'],'reason'=>$reason],JSON_THROW_ON_ERROR),$_SESSION['user_name']]);
            query('INSERT INTO agent_work_items (id,workspace_id,lead_id,source_activity_id,parent_work_id,kind) VALUES (?,?,?,?,?,?)',[$workId,workspace(),$original['lead_id'],$activity,$original['id'],'reverse_work']);
            db()->commit();$out=['ok'=>true,'work_id'=>$workId];
        }
        elseif(preg_match('#^/api/agent-work/([a-f0-9]{32})/(schedule-call|resolve)$#',$path,$workMatch)&&$method==='POST') {
            requireRole(['owner','manager','salesperson']);
            db()->beginTransaction();
            $work=query('SELECT w.*,l.owner_user_id,l.collector_user_id,l.owner,l.stage FROM agent_work_items w JOIN leads l ON l.id=w.lead_id AND l.workspace_id=w.workspace_id WHERE w.workspace_id=? AND w.id=? FOR UPDATE',[workspace(),$workMatch[1]])->fetch();
            if(!$work||!canAccessLead($work))fail('Agent work item not found',404);
            if($work['status']!=='pending')fail('This work item was already handled',409);
            if($workMatch[2]==='schedule-call'){
                if($work['kind']!=='busy_call'||$work['stage']==='Lost')fail('This call cannot be rescheduled',409);
                $date=textField($data,'due_at',40,true);
                if(!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/',$date))fail('A resolved UTC time is required',422);
                $due=new DateTimeImmutable($date);$dueUtc=$due->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
                if($due<=new DateTimeImmutable('+5 minutes'))fail('Choose a future call time',422);
                $owner=$work['owner'];if($owner==='')fail('Assign this lead before scheduling a call',422);
                $conflicts=query("SELECT id FROM tasks WHERE workspace_id=? AND owner=? AND status='pending' AND due_at>=DATE_SUB(?,INTERVAL 20 MINUTE) AND due_at<DATE_ADD(?,INTERVAL 20 MINUTE) LIMIT 1",[workspace(),$owner,$dueUtc,$dueUtc])->fetch();
                if($conflicts)fail('This person already has a task near that time',409);
                $taskId=uid();query('INSERT INTO tasks (id,workspace_id,lead_id,title,owner,due_at) VALUES (?,?,?,?,?,?)',[$taskId,workspace(),$work['lead_id'],'Call again after busy line',$owner,$dueUtc]);
                audit($work['lead_id'],'task_created',['title'=>'Call again after busy line','due_at'=>$date,'source_work_item'=>$work['id']]);
                $result=['task_id'=>$taskId,'due_at'=>$date];
            }else{
                $decision=textField($data,'decision',30,true);$reason=textField($data,'reason',1000,true);
                if(!in_array($decision,['done','needs_review'],true))fail('Invalid decision',422);
                $result=['decision'=>$decision,'reason'=>$reason];
            }
            query('UPDATE agent_work_items SET status=?,completed_at=UTC_TIMESTAMP(),result=? WHERE workspace_id=? AND id=?',[$workMatch[2]==='schedule-call'?'done':$decision,json_encode($result,JSON_THROW_ON_ERROR),workspace(),$work['id']]);
            db()->commit();$out=['ok'=>true]+$result;
        }
        elseif($path==='/api/tasks'&&$method==='GET') {
            requireRole(['owner','manager','salesperson']);
            $scope=in_array(role(),['owner','manager'],true)?'':" AND (l.owner_user_id=? OR l.collector_user_id=?)";
            $args=in_array(role(),['owner','manager'],true)?[workspace()]:[workspace(),$_SESSION['user_id'],$_SESSION['user_id']];
            if($project=requestedProject()){$scope.=' AND l.project_id=?';$args[]=$project;}
            $out=query("SELECT t.*,l.name,l.organisation,l.phone FROM tasks t JOIN leads l ON l.id=t.lead_id AND l.workspace_id=t.workspace_id WHERE t.workspace_id=?$scope ORDER BY CASE WHEN t.status='pending' THEN 0 WHEN t.status='completed' THEN 1 ELSE 2 END,t.due_at LIMIT 500",$args)->fetchAll();
        }
        elseif(preg_match('#^/api/tasks/([a-f0-9]{32})/(complete|cancel)$#',$path,$match)&&$method==='POST') {
            requireRole(['owner','manager','salesperson']);
            db()->beginTransaction();$task=query('SELECT t.*,l.owner_user_id,l.collector_user_id FROM tasks t JOIN leads l ON l.id=t.lead_id AND l.workspace_id=t.workspace_id WHERE t.workspace_id=? AND t.id=? FOR UPDATE',[workspace(),$match[1]])->fetch();if(!$task||!canAccessLead($task)) fail('Task not found',404);
            if($match[2]==='cancel'){
                $reason=textField($data,'reason',1000,true);
                if($task['status']==='completed')fail('A completed task cannot be cancelled',409);
                if($task['status']==='pending'){
                    query("UPDATE tasks SET status='cancelled' WHERE workspace_id=? AND id=? AND status='pending'",[workspace(),$match[1]]);
                    audit($task['lead_id'],'task_cancelled',['title'=>$task['title'],'reason'=>$reason]);
                }
            } else {
                if($task['status']==='cancelled')fail('A cancelled task cannot be completed',409);
                if($task['status']==='pending'){query("UPDATE tasks SET status='completed' WHERE workspace_id=? AND id=?",[workspace(),$match[1]]);audit($task['lead_id'],'task_completed',['title'=>$task['title']]);}
            }
            db()->commit();$out=['ok'=>true];
        } else fail('Route not found',404);
    }
    echo json_encode($out,JSON_THROW_ON_ERROR);
} catch(Throwable $e) {
    if(isset($db)&&$db->inTransaction()) $db->rollBack();
    // db() is cached; roll back an interrupted service transaction before returning.
    try{if(db()->inTransaction()) db()->rollBack();}catch(Throwable){}
    $code=$e instanceof RuntimeException&&in_array($e->getCode(),[401,403,404,405,409,413,422,429],true)?$e->getCode():($e instanceof JsonException?422:500);
    http_response_code($code);echo json_encode(['error'=>$code===500?'Service unavailable. Check server logs.':($e instanceof JsonException?'Invalid JSON':$e->getMessage())]);
    if($code===500) error_log($e->getMessage());
}
