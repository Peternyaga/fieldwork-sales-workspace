<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('FIELDWORK_OAUTH_BASE_URL')!=='http://127.0.0.1:8791'||getenv('FIELDWORK_OAUTH_ALLOW_HTTP_LOCAL')!=='1')exit("Run only against the isolated local OAuth server.\n");
require dirname(__DIR__).'/backend/bootstrap.php';
require dirname(__DIR__).'/backend/projects.php';
$workspace=uid();$user=uid();$email='oauth-'.bin2hex(random_bytes(6)).'@local.test';$password=bin2hex(random_bytes(16));$clientId=null;$cookie='';
function oauthCheck(bool $condition,string $label): void {if(!$condition)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label."\n";}
function oauthRequest(string $path,?array $body=null,string $type='form',array $extra=[]): array {
    global $cookie;
    $handle=curl_init('http://127.0.0.1:8791'.$path);$headers=array_merge(['Accept: application/json'],$extra);
    if($cookie)$headers[]='Cookie: '.$cookie;
    if($body!==null){curl_setopt($handle,CURLOPT_POST,true);$headers[]='Content-Type: '.($type==='json'?'application/json':'application/x-www-form-urlencoded');curl_setopt($handle,CURLOPT_POSTFIELDS,$type==='json'?json_encode($body):http_build_query($body,'','&',PHP_QUERY_RFC3986));}
    curl_setopt_array($handle,[CURLOPT_HTTPHEADER=>$headers,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>10]);
    $response=curl_exec($handle);if($response===false)throw new RuntimeException(curl_error($handle));
    $length=curl_getinfo($handle,CURLINFO_HEADER_SIZE);$head=substr($response,0,$length);$content=substr($response,$length);$status=curl_getinfo($handle,CURLINFO_RESPONSE_CODE);curl_close($handle);
    if(preg_match('/^Set-Cookie:\s*([^;\r\n]+)/mi',$head,$match))$cookie=$match[1];
    return [$status,json_decode($content,true),$head,$content];
}
try{
    query('INSERT INTO workspaces (id,name) VALUES (?,?)',[$workspace,'OAuth integration test']);
    query('INSERT INTO users (id,workspace_id,name,email,password_hash,role) VALUES (?,?,?,?,?,?)',[$user,$workspace,'OAuth Tester',$email,password_hash($password,PASSWORD_DEFAULT),'owner']);
    $project=defaultProject($workspace);$lead=uid();
    query('INSERT INTO leads (id,workspace_id,project_id,name,organisation,notes,owner,owner_user_id) VALUES (?,?,?,?,?,?,?,?)',[$lead,$workspace,$project,'OAuth Lead','Example Co','','OAuth Tester',$user]);
    [$status,$metadata]=oauthRequest('/.well-known/oauth-protected-resource');oauthCheck($status===200&&$metadata['resource']==='http://127.0.0.1:8791/mcp','resource discovery');
    [$status,$server]=oauthRequest('/.well-known/oauth-authorization-server');oauthCheck($status===200&&in_array('S256',$server['code_challenge_methods_supported'],true),'authorization server discovery');
    [$status,$client]=oauthRequest('/oauth/register',['client_name'=>'Test Codex','redirect_uris'=>['http://127.0.0.1:4567/callback'],'token_endpoint_auth_method'=>'none'],'json');
    $clientId=$client['client_id']??null;oauthCheck($status===201&&is_string($clientId),'public PKCE client registration');
    $verifier=str_repeat('a',43);$challenge=rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'=');
    $params=['response_type'=>'code','client_id'=>$clientId,'redirect_uri'=>'http://127.0.0.1:4567/callback','scope'=>'leads:read','state'=>'roundtrip-test','resource'=>'http://127.0.0.1:8791/mcp','code_challenge'=>$challenge,'code_challenge_method'=>'S256'];
    [$status,,$head,$html]=oauthRequest('/oauth/authorize?'.http_build_query($params,'','&',PHP_QUERY_RFC3986));oauthCheck($status===200&&str_contains($html,'Sign in to connect'),'account sign-in displayed');
    preg_match('/name="csrf" value="([a-f0-9]+)"/',$html,$csrfMatch);$csrf=$csrfMatch[1]??'';
    [$status,,$head,$html]=oauthRequest('/oauth/decision',['csrf'=>$csrf,'action'=>'login','email'=>$email,'password'=>$password]);oauthCheck($status===200&&str_contains($html,'Allow requested access'),'consent displayed after sign-in');
    [$status,,$head]=oauthRequest('/oauth/decision',['csrf'=>$csrf,'action'=>'approve']);preg_match('/^Location:\s*(\S+)/mi',$head,$locationMatch);$location=$locationMatch[1]??'';parse_str(parse_url($location,PHP_URL_QUERY)?:'',$returned);
    oauthCheck($status===302&&str_starts_with($location,'http://127.0.0.1:4567/callback?')&&($returned['state']??'')==='roundtrip-test'&&($returned['iss']??'')==='http://127.0.0.1:8791','consent returns code to exact client redirect');
    $exchange=['grant_type'=>'authorization_code','client_id'=>$clientId,'code'=>$returned['code']??'','redirect_uri'=>'http://127.0.0.1:4567/callback','resource'=>'http://127.0.0.1:8791/mcp','code_verifier'=>$verifier];
    [$status,$invalid]=oauthRequest('/oauth/token',[...$exchange,'code_verifier'=>str_repeat('b',43)]);oauthCheck($status===400&&($invalid['error']??'')==='invalid_grant','wrong PKCE verifier rejected');
    [$status,$tokens]=oauthRequest('/oauth/token',$exchange);$access=$tokens['access_token']??'';$refresh=$tokens['refresh_token']??'';oauthCheck($status===200&&strlen($access)===64&&strlen($refresh)===64,'PKCE code exchange');
    [$status]=oauthRequest('/oauth/token',$exchange);oauthCheck($status===400,'authorization code cannot be replayed');
    [$status,$tools]=oauthRequest('/mcp',['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/list','params'=>new stdClass()],'json');oauthCheck($status===200&&count($tools['result']['tools']??[])>=4,'MCP tool discovery');
    $definitions=array_column($tools['result']['tools'],'inputSchema','name');
    oauthCheck(isset($definitions['update_lead_phone'])&&in_array('provenance',$definitions['update_lead_phone']['required'],true),'MCP discovers field-specific profile edits with provenance');
    $profileCall=['jsonrpc'=>'2.0','id'=>8,'method'=>'tools/call','params'=>['name'=>'update_lead_phone','arguments'=>['id'=>$lead,'version'=>1,'value'=>'0700000002','provenance'=>'Disposable test: user supplies an unverified proprietor candidate']]];
    [$status]=oauthRequest('/mcp',$profileCall,'json',['Authorization: Bearer '.$access]);oauthCheck($status===403,'read-only grant cannot edit contact information');
    $writeAccess=bin2hex(random_bytes(32));
    query("INSERT INTO oauth_grants (id,client_id,user_id,workspace_id,resource,scope,access_hash,refresh_hash,access_expires_at,refresh_expires_at,auth_version) VALUES (?,?,?,?,?,?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 HOUR),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY),1)",[uid(),$clientId,$user,$workspace,'http://127.0.0.1:8791/mcp','leads:read leads:write',hash('sha256',$writeAccess),hash('sha256',uid())]);
    [$status,$edited]=oauthRequest('/mcp',$profileCall,'json',['Authorization: Bearer '.$writeAccess]);oauthCheck($status===200&&query('SELECT phone FROM leads WHERE id=?',[$lead])->fetchColumn()==='0700000002','OAuth MCP profile edit persists validated value');
    [$status]=oauthRequest('/mcp',$profileCall,'json',['Authorization: Bearer '.$writeAccess]);oauthCheck($status===409,'OAuth MCP rejects stale contact edit');
    $call=['jsonrpc'=>'2.0','id'=>2,'method'=>'tools/call','params'=>['name'=>'list_leads','arguments'=>new stdClass()]];
    [$status,$challenge,$head]=oauthRequest('/mcp',$call,'json');oauthCheck($status===401&&str_contains($head,'WWW-Authenticate: Bearer')&&isset($challenge['result']['_meta']['mcp/www_authenticate']),'unauthenticated tool call requests OAuth');
    [$status,$found,$head]=oauthRequest('/mcp',$call,'json',['Authorization: Bearer '.$access]);$rows=json_decode($found['result']['content'][0]['text']??'null',true);
    oauthCheck($status===200&&count($rows??[])===1&&$rows[0]['id']===$lead,'authenticated MCP reads only connected workspace');
    $writeCall=['jsonrpc'=>'2.0','id'=>3,'method'=>'tools/call','params'=>['name'=>'create_task','arguments'=>['lead_id'=>$lead,'title'=>'Test follow-up','due_at'=>'2030-01-01T12:00:00.000Z']]];
    [$status,$denied,$head]=oauthRequest('/mcp',$writeCall,'json',['Authorization: Bearer '.$access]);
    oauthCheck($status===403&&str_contains($denied['result']['_meta']['mcp/www_authenticate'][0]??'','insufficient_scope'),'read-only grant cannot write');
    oauthCheck(!str_contains(strtolower($head),'set-cookie:'),'MCP bearer calls do not create browser sessions');
    [$status,$renewed]=oauthRequest('/oauth/token',['grant_type'=>'refresh_token','client_id'=>$clientId,'refresh_token'=>$refresh,'resource'=>'http://127.0.0.1:8791/mcp']);$newAccess=$renewed['access_token']??'';oauthCheck($status===200&&strlen($newAccess)===64,'refresh token rotates access');
    [$status]=oauthRequest('/mcp',$call,'json',['Authorization: Bearer '.$access]);oauthCheck($status===401,'old access token revoked after refresh');
    [$status]=oauthRequest('/mcp',$call,'json',['Authorization: Bearer '.$newAccess]);oauthCheck($status===200,'new access token works');
    query('UPDATE oauth_grants SET revoked_at=UTC_TIMESTAMP() WHERE access_hash=?',[hash('sha256',$newAccess)]);
    [$status]=oauthRequest('/mcp',$call,'json',['Authorization: Bearer '.$newAccess]);oauthCheck($status===401,'revoked connection loses access');
    echo "Hosted OAuth and read-only MCP checks passed.\n";
}finally{
    query('DELETE FROM oauth_grants WHERE workspace_id=?',[$workspace]);query('DELETE FROM oauth_codes WHERE workspace_id=?',[$workspace]);
    foreach(['activities','opportunities','contacts','organisations'] as $table)query("DELETE FROM $table WHERE workspace_id=?",[$workspace]);
    query('DELETE FROM leads WHERE workspace_id=?',[$workspace]);query('DELETE FROM projects WHERE workspace_id=?',[$workspace]);query('DELETE FROM users WHERE workspace_id=?',[$workspace]);query('DELETE FROM workspaces WHERE id=?',[$workspace]);
    if($clientId)query('DELETE FROM oauth_clients WHERE id=?',[$clientId]);
}
