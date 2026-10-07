<?php
declare(strict_types=1);

function pluginAccessPath(): string {
    $name=getenv('FIELDWORK_PLUGIN_ACCESS_FILE')?:'plugin-access.json';
    if(!preg_match('/^[a-z0-9-]+\.json$/',$name))fail('Invalid local plugin file',500);
    return dirname(__DIR__).'/var/'.$name;
}

function connectLocalPlugin(): array {
    if(!localBootstrapAllowed())fail('Local plugin connection is unavailable',403);
    $token=bin2hex(random_bytes(32));
    $expires=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->modify('+30 days')->format('Y-m-d H:i:s');
    $payload=json_encode(['token'=>$token],JSON_THROW_ON_ERROR);
    $file=pluginAccessPath();$dir=dirname($file);
    if(!is_dir($dir))mkdir($dir,0700,true);
    $temporary=tempnam($dir,'pair-');
    if($temporary===false||file_put_contents($temporary,$payload,LOCK_EX)===false)fail('Could not save local plugin connection',500);
    if(DIRECTORY_SEPARATOR!=='\\')chmod($temporary,0600);
    db()->beginTransaction();
    query('DELETE FROM plugin_tokens WHERE workspace_id=? AND user_id=?',[workspace(),$_SESSION['user_id']]);
    query('INSERT INTO plugin_tokens (id,workspace_id,user_id,token_hash,auth_version,expires_at) VALUES (?,?,?,?,?,?)',[uid(),workspace(),$_SESSION['user_id'],hash('sha256',$token),(int)$_SESSION['auth_version'],$expires]);
    if(!rename($temporary,$file))fail('Could not save local plugin connection',500);
    db()->commit();
    return ['prepared'=>true,'expires_at'=>$expires.' UTC'];
}

function localPluginStatus(): array {
    $record=query('SELECT p.expires_at,p.last_used_at,p.auth_version,u.auth_version AS current_auth_version FROM plugin_tokens p JOIN users u ON u.id=p.user_id AND u.workspace_id=p.workspace_id WHERE p.workspace_id=? AND p.user_id=? ORDER BY p.created_at DESC LIMIT 1',[workspace(),$_SESSION['user_id']])->fetch();
    if(!$record||$record['expires_at']<=gmdate('Y-m-d H:i:s')||(int)$record['auth_version']!==(int)$record['current_auth_version'])return ['state'=>'not_prepared'];
    return ['state'=>$record['last_used_at']?'verified':'prepared','expires_at'=>$record['expires_at'].' UTC','last_used_at'=>$record['last_used_at']?$record['last_used_at'].' UTC':null];
}

function authenticateLocalPlugin(): array {
    if(!localBootstrapAllowed())fail('Local plugin connection is unavailable',403);
    $header=$_SERVER['HTTP_AUTHORIZATION']??'';
    if(!preg_match('/^Bearer ([a-f0-9]{64})$/',$header,$match))fail('Plugin connection required',401);
    $record=query('SELECT p.*,u.name,u.role,u.active,u.auth_version AS current_auth_version FROM plugin_tokens p JOIN users u ON u.id=p.user_id AND u.workspace_id=p.workspace_id WHERE p.token_hash=?',[hash('sha256',$match[1])])->fetch();
    if(!$record||!$record['active']||(int)$record['auth_version']!==(int)$record['current_auth_version']||$record['expires_at']<=gmdate('Y-m-d H:i:s'))fail('Plugin connection expired. Reconnect from Fieldwork.',401);
    query('UPDATE plugin_tokens SET last_used_at=UTC_TIMESTAMP() WHERE id=?',[$record['id']]);
    session_regenerate_id(true);
    $_SESSION['user_id']=$record['user_id'];$_SESSION['user_name']=$record['name'];$_SESSION['workspace_id']=$record['workspace_id'];$_SESSION['user_role']=$record['role'];$_SESSION['auth_version']=(int)$record['auth_version'];
    return ['csrf'=>$_SESSION['csrf'],'name'=>$record['name'],'role'=>$record['role']];
}
