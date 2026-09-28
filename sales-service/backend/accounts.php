<?php
declare(strict_types=1);

function issueAccountToken(array $user,string $purpose): array {
    if(!in_array($purpose,['invite','reset'],true))fail('Invalid account action');
    $token=bin2hex(random_bytes(32));
    $minutes=$purpose==='invite'?2880:30;
    $expires=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->modify("+$minutes minutes")->format('Y-m-d H:i:s');
    query('UPDATE account_tokens SET used_at=UTC_TIMESTAMP() WHERE workspace_id=? AND user_id=? AND purpose=? AND used_at IS NULL',[$user['workspace_id'],$user['id'],$purpose]);
    query('INSERT INTO account_tokens (id,workspace_id,user_id,purpose,token_hash,expires_at,created_by) VALUES (?,?,?,?,?,?,?)',[uid(),$user['workspace_id'],$user['id'],$purpose,hash('sha256',$token),$expires,$_SESSION['user_id']]);
    return ['token'=>$token,'purpose'=>$purpose,'expires_at'=>$expires.' UTC'];
}

function findAccountToken(string $token,bool $lock=false): array {
    if(!preg_match('/^[a-f0-9]{64}$/',$token))fail('This setup link is invalid or expired',422);
    $sql='SELECT a.*,u.name,u.email,u.active,u.setup_pending,u.auth_version,w.name AS workspace_name FROM account_tokens a JOIN users u ON u.id=a.user_id AND u.workspace_id=a.workspace_id JOIN workspaces w ON w.id=a.workspace_id WHERE a.token_hash=?';
    $record=query($sql.($lock?' FOR UPDATE':''),[hash('sha256',$token)])->fetch();
    if(!$record||$record['used_at']!==null||$record['expires_at']<=gmdate('Y-m-d H:i:s'))fail('This setup link is invalid or expired',422);
    if($record['purpose']==='invite'&&(!$record['setup_pending']||$record['active']))fail('This setup link is invalid or expired',422);
    if($record['purpose']==='reset'&&(!$record['active']||$record['setup_pending']))fail('This setup link is invalid or expired',422);
    return $record;
}

function redeemAccountToken(string $token,string $password): void {
    if(strlen($password)<12||strlen($password)>500)fail('Use a password of at least 12 characters');
    db()->beginTransaction();
    $record=findAccountToken($token,true);
    query('UPDATE users SET password_hash=?,active=1,setup_pending=0,auth_version=auth_version+1 WHERE id=? AND workspace_id=?',[password_hash($password,PASSWORD_DEFAULT),$record['user_id'],$record['workspace_id']]);
    query('UPDATE account_tokens SET used_at=UTC_TIMESTAMP() WHERE workspace_id=? AND user_id=? AND used_at IS NULL',[$record['workspace_id'],$record['user_id']]);
    db()->commit();
}

function localBootstrapAllowed(): bool {
    $remote=$_SERVER['REMOTE_ADDR']??'';
    $host=strtolower((string)(parse_url('http://'.($_SERVER['HTTP_HOST']??''),PHP_URL_HOST)??''));
    return in_array($remote,['127.0.0.1','::1'],true)&&in_array($host,['127.0.0.1','localhost','[::1]'],true);
}
