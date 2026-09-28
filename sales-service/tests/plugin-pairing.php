<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('FIELDWORK_API_BASE')!=='http://127.0.0.1:8790/api'||getenv('FIELDWORK_PLUGIN_ACCESS_FILE')!=='pair-test.json'){
    fwrite(STDERR,"Run only against the isolated local pairing server.\n");exit(1);
}
require dirname(__DIR__).'/backend/bootstrap.php';
$file=dirname(__DIR__).'/var/pair-test.json';
if(file_exists($file)){fwrite(STDERR,"The isolated pairing file already exists; stopping without changing it.\n");exit(1);}
$workspace=uid();$user=uid();$email='pair-'.bin2hex(random_bytes(6)).'@local.test';$password=bin2hex(random_bytes(16));
$cookie='';$csrf='';
function pairRequest(string $route,?array $body=null,array $extra=[]): array {
    global $cookie,$csrf;
    $handle=curl_init('http://127.0.0.1:8790/api'.$route);
    $headers=array_merge(['Accept: application/json'],$extra);
    if($cookie)$headers[]='Cookie: '.$cookie;
    if($body!==null){curl_setopt($handle,CURLOPT_POST,true);curl_setopt($handle,CURLOPT_POSTFIELDS,json_encode($body));$headers[]='Content-Type: application/json';$headers[]='X-CSRF-Token: '.$csrf;}
    curl_setopt_array($handle,[CURLOPT_HTTPHEADER=>$headers,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_TIMEOUT=>10]);
    $raw=curl_exec($handle);if($raw===false)throw new RuntimeException(curl_error($handle));
    $headerSize=curl_getinfo($handle,CURLINFO_HEADER_SIZE);$head=substr($raw,0,$headerSize);$status=curl_getinfo($handle,CURLINFO_RESPONSE_CODE);
    $result=json_decode(substr($raw,$headerSize),true);curl_close($handle);
    if(preg_match('/^Set-Cookie:\s*([^;\r\n]+)/mi',$head,$match))$cookie=$match[1];
    return [$status,$result];
}
function requirePair(bool $condition,string $label): void {if(!$condition)throw new RuntimeException("Pairing check failed: $label");echo "PASS $label\n";}
try {
    query('INSERT INTO workspaces (id,name) VALUES (?,?)',[$workspace,'Pairing test']);
    query('INSERT INTO users (id,workspace_id,name,email,password_hash,role) VALUES (?,?,?,?,?,?)',[$user,$workspace,'Pairing Tester',$email,password_hash($password,PASSWORD_DEFAULT),'owner']);
    [$status,$session]=pairRequest('/session');$csrf=$session['csrf']??'';requirePair($status===200&&strlen($csrf)>20,'isolated session');
    [$status]=pairRequest('/login',['email'=>$email,'password'=>$password]);requirePair($status===200,'disposable owner login');
    [$status,$connected]=pairRequest('/plugin/connect',[]);requirePair($status===200&&($connected['connected']??false)&&file_exists($file),'connection writes isolated credential file');
    $first=json_decode(file_get_contents($file),true)['token']??'';requirePair((bool)preg_match('/^[a-f0-9]{64}$/',$first),'credential file contains a valid token');
    [$status]=pairRequest('/plugin/connect',[]);requirePair($status===200,'reconnecting replaces the credential file');
    $second=json_decode(file_get_contents($file),true)['token']??'';requirePair($second!==$first&&strlen($second)===64,'reconnection rotates the token');
    $cookie='';[$status]=pairRequest('/plugin/auth',null,['Authorization: Bearer '.$first]);requirePair($status===401,'old token is revoked');
    [$status]=pairRequest('/plugin/auth',null,['Authorization: Bearer '.$second]);requirePair($status===200,'new token authenticates');
    exec('node tests/plugin-protocol.mjs 2>&1',$output,$exitCode);requirePair($exitCode===0,'MCP bridge uses browser-paired account');
    echo "All isolated pairing checks passed.\n";
} finally {
    query('DELETE FROM plugin_tokens WHERE workspace_id=?',[$workspace]);
    query('DELETE FROM users WHERE workspace_id=?',[$workspace]);
    query('DELETE FROM workspaces WHERE id=?',[$workspace]);
    if(file_exists($file))unlink($file);
}
