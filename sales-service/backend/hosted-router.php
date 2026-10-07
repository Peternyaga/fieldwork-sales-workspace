<?php
declare(strict_types=1);
require_once __DIR__.'/hosted-auth.php';

function hostedTools(): array {
    $empty=['type'=>'object','properties'=>new stdClass(),'additionalProperties'=>false];
    $id=['type'=>'object','properties'=>['id'=>['type'=>'string']],'required'=>['id'],'additionalProperties'=>false];
    $security=[['type'=>'oauth2','scopes'=>['leads:read']]];
    return [
        ['name'=>'get_profile','description'=>'Identify the connected Fieldwork account.','inputSchema'=>$empty,'securitySchemes'=>$security],
        ['name'=>'list_projects','description'=>'List projects in the connected Fieldwork workspace.','inputSchema'=>$empty,'securitySchemes'=>$security],
        ['name'=>'list_leads','description'=>'List leads visible to this account.','inputSchema'=>$empty,'securitySchemes'=>$security],
        ['name'=>'get_lead','description'=>'Read one visible lead and its context.','inputSchema'=>$id,'securitySchemes'=>$security],
        ['name'=>'list_tasks','description'=>'List internal follow-up tasks visible to this account.','inputSchema'=>$empty,'securitySchemes'=>$security],
        ['name'=>'list_agent_work','description'=>'List pending attention items visible to this account.','inputSchema'=>$empty,'securitySchemes'=>$security],
    ];
}
function hostedCall(string $name,array $args,array $identity): array {
    $_SESSION['user_id']=$identity['user_id'];$_SESSION['workspace_id']=$identity['workspace_id'];$_SESSION['user_role']=$identity['role'];
    $workspace=workspace();$user=$identity['user_id'];$role=role();$manager=in_array($role,['owner','manager'],true);
    if($name==='get_profile')return ['id'=>$user,'displayName'=>$identity['name'],'workspaceId'=>$workspace];
    if($name==='list_projects')return query('SELECT id,name FROM projects WHERE workspace_id=? ORDER BY name',[$workspace])->fetchAll();
    if($name==='list_leads'){
        $collector=$role==='collector';$scope=$manager?'':($collector?' AND l.collector_user_id=?':' AND (l.owner_user_id=? OR l.collector_user_id=?)');
        $params=$manager?[$workspace]:($collector?[$workspace,$user]:[$workspace,$user,$user]);
        $fields=$collector?'l.id,l.name,l.organisation,l.stage,l.source,l.offering,l.owner,l.owner_user_id,l.collector,l.collector_user_id,l.version,l.created_at':'l.*';
        return query("SELECT $fields FROM leads l WHERE l.workspace_id=?$scope ORDER BY l.updated_at DESC LIMIT 100",$params)->fetchAll();
    }
    if($name==='get_lead'){
        $id=$args['id']??null;if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/',$id))fail('Invalid lead ID',422);
        $lead=query('SELECT * FROM leads WHERE workspace_id=? AND id=?',[$workspace,$id])->fetch();if(!$lead||!canAccessLead($lead))fail('Lead not found',404);
        if($role==='collector'){
            $fields=['id','name','organisation','stage','source','offering','owner','owner_user_id','collector','collector_user_id','version','created_at'];
            return ['lead'=>array_intersect_key($lead,array_flip($fields)),'activities'=>[],'tasks'=>[]];
        }
        return ['lead'=>$lead,'activities'=>query('SELECT * FROM activities WHERE workspace_id=? AND lead_id=? ORDER BY created_at DESC,id DESC LIMIT 100',[$workspace,$id])->fetchAll(),'tasks'=>query('SELECT * FROM tasks WHERE workspace_id=? AND lead_id=? ORDER BY due_at LIMIT 100',[$workspace,$id])->fetchAll()];
    }
    if($role==='collector')fail('Your role cannot access sales tasks',403);
    $scope=$manager?'':' AND (l.owner_user_id=? OR l.collector_user_id=?)';
    $params=$manager?[$workspace]:[$workspace,$user,$user];
    if($name==='list_tasks')return query("SELECT t.id,t.lead_id,t.title,t.owner,t.due_at,t.status,l.name,l.organisation FROM tasks t JOIN leads l ON l.id=t.lead_id AND l.workspace_id=t.workspace_id WHERE t.workspace_id=?$scope ORDER BY t.due_at LIMIT 100",$params)->fetchAll();
    if($name==='list_agent_work'){
        $workScope=$manager?'':' AND l.owner_user_id=?';$workParams=$manager?[$workspace]:[$workspace,$user];
        return query("SELECT w.id,w.lead_id,w.kind,w.created_at,l.name,l.organisation,a.body AS source_note FROM agent_work_items w JOIN leads l ON l.id=w.lead_id AND l.workspace_id=w.workspace_id JOIN activities a ON a.id=w.source_activity_id WHERE w.workspace_id=? AND w.status='pending'$workScope ORDER BY w.created_at,w.id LIMIT 50",$workParams)->fetchAll();
    }
    fail('Unknown tool',404);
}
function hostedMcp(): never {
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST')fail('Method not allowed',405);
    if((int)($_SERVER['CONTENT_LENGTH']??0)>65536)fail('Request too large',413);
    $message=json_decode(file_get_contents('php://input'),true,20,JSON_THROW_ON_ERROR);
    if(!is_array($message)||($message['jsonrpc']??'')!=='2.0'||!is_string($message['method']??null))fail('Invalid MCP request',400);
    if(!array_key_exists('id',$message)){http_response_code(202);exit;}
    $method=$message['method'];$params=$message['params']??[];$id=$message['id'];
    if($method==='initialize')$result=['protocolVersion'=>'2025-03-26','capabilities'=>['tools'=>new stdClass()],'serverInfo'=>['name'=>'fieldwork-hosted','version'=>'0.1.0']];
    elseif($method==='ping')$result=new stdClass();
    elseif($method==='tools/list')$result=['tools'=>hostedTools()];
    elseif($method==='tools/call'){
        $identity=hostedBearer();
        if(!is_array($params)||!is_string($params['name']??null)||!is_array($params['arguments']??[]))fail('Invalid tool call',400);
        $tool=$params['name'];if(!in_array($tool,array_column(hostedTools(),'name'),true))fail('Unknown tool',404);
        $value=hostedCall($tool,$params['arguments']??[],$identity);
        $result=['content'=>[['type'=>'text','text'=>json_encode($value,JSON_THROW_ON_ERROR)]]];
    }else hostedJson(['jsonrpc'=>'2.0','id'=>$id,'error'=>['code'=>-32601,'message'=>'Method not found']]);
    hostedJson(['jsonrpc'=>'2.0','id'=>$id,'result'=>$result]);
}

$path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);$method=$_SERVER['REQUEST_METHOD']??'GET';
try{
    hostedBase();
    if(in_array($path,['/oauth/authorize','/oauth/decision'],true)){
        $sessionDir=dirname(__DIR__).'/var/sessions';if(!is_dir($sessionDir))mkdir($sessionDir,0700,true);
        session_save_path($sessionDir);session_set_cookie_params(['httponly'=>true,'samesite'=>'Lax','secure'=>str_starts_with(hostedBase(),'https://')]);session_start();
    }
    if(in_array($path,['/.well-known/oauth-protected-resource','/.well-known/oauth-protected-resource/mcp','/.well-known/oauth-authorization-server'],true)&&$method==='GET')hostedMetadata($path);
    if($path==='/oauth/register'&&$method==='POST')hostedRegister();
    if($path==='/oauth/authorize'&&$method==='GET')hostedAuthorize();
    if($path==='/oauth/decision'&&$method==='POST')hostedDecision();
    if($path==='/oauth/token'&&$method==='POST')hostedToken();
    if($path==='/mcp')hostedMcp();
    fail('Route not found',404);
}catch(Throwable $error){
    if(db()->inTransaction())db()->rollBack();
    $status=$error instanceof RuntimeException&&in_array($error->getCode(),[400,401,403,404,405,413,422,503],true)?$error->getCode():500;
    $message=$status===500?'Service unavailable':$error->getMessage();
    if($path==='/oauth/token' && $status!==503){
        $code=$message==='Unsupported grant type'?'unsupported_grant_type':($message==='Unknown client'||$message==='Invalid client'?'invalid_client':'invalid_grant');
        hostedJson(['error'=>$code,'error_description'=>$message],$status);
    }
    hostedJson(['error'=>$message],$status);
}
