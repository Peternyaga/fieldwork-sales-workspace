<?php
declare(strict_types=1);

function pollJson(array $body,int $status=200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($body,JSON_THROW_ON_ERROR);
    exit;
}
function pollRateLimit(string $address): void {
    $bucket=hash('sha256','poll-pair|'.$address);
    $cutoff=gmdate('Y-m-d H:i:s',time()-3600);
    query('INSERT INTO oauth_rate_limits (bucket,window_start,attempts) VALUES (?,UTC_TIMESTAMP(),1) ON DUPLICATE KEY UPDATE attempts=IF(window_start<?,1,attempts+1),window_start=IF(window_start<?,UTC_TIMESTAMP(),window_start)',[$bucket,$cutoff,$cutoff]);
    if((int)query('SELECT attempts FROM oauth_rate_limits WHERE bucket=?',[$bucket])->fetchColumn()>20)fail('Too many pairing attempts',429);
}
function pollAuthenticate(): array {
    $auth=$_SERVER['HTTP_AUTHORIZATION']??'';
    if(!preg_match('/^Bearer ([A-Za-z0-9_-]{43})$/',$auth,$matches))fail('Poll credential required',401);
    $token=query('SELECT p.*,u.active,u.auth_version AS current_auth_version FROM agent_poll_tokens p JOIN users u ON u.id=p.user_id AND u.workspace_id=p.workspace_id WHERE p.token_hash=? AND p.revoked_at IS NULL',[hash('sha256',$matches[1])])->fetch();
    if(!$token||(int)$token['active']!==1||(int)$token['auth_version']!==(int)$token['current_auth_version'])fail('Poll credential expired or revoked',401);
    return $token;
}
function pollPublic(string $path,string $method): void {
    if((int)($_SERVER['CONTENT_LENGTH']??0)>2048)fail('Request too large',413);
    if($path==='/api/automation/ready'){
        if($method!=='GET')fail('Method not allowed',405);
        $token=pollAuthenticate();
        query('UPDATE agent_poll_tokens SET last_used_at=UTC_TIMESTAMP() WHERE id=?',[$token['id']]);
        $ready=(bool)query("SELECT id FROM agent_work_items WHERE workspace_id=? AND status='pending' ORDER BY created_at,id LIMIT 1",[$token['workspace_id']])->fetchColumn();
        pollJson(['ready'=>$ready]);
    }
    if($path==='/api/automation/revoke-self'){
        if($method!=='POST')fail('Method not allowed',405);
        $token=pollAuthenticate();
        query('UPDATE agent_poll_tokens SET revoked_at=UTC_TIMESTAMP() WHERE id=?',[$token['id']]);
        pollJson(['ok'=>true]);
    }
    if($method!=='POST')fail('Method not allowed',405);
    $data=json_decode(file_get_contents('php://input'),true,8,JSON_THROW_ON_ERROR);
    if(!is_array($data))fail('Expected an object',400);
    if($path==='/api/automation/pair/start'){
        pollRateLimit((string)($_SERVER['REMOTE_ADDR']??''));
        $challenge=$data['challenge']??null;
        if(!is_string($challenge)||!preg_match('/^[a-f0-9]{64}$/',$challenge))fail('Invalid pairing challenge',422);
        $device=textField($data,'device_name',120,true);
        $id=uid();
        query('INSERT INTO agent_poll_pairings (id,verifier_hash,device_name,expires_at) VALUES (?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 10 MINUTE))',[$id,$challenge,$device]);
        pollJson(['id'=>$id,'approval_url'=>'https://reach.vumbualabs.com/#automation='.$id,'expires_in'=>600]);
    }
    if($path==='/api/automation/pair/finish'){
        $id=$data['id']??null;$verifier=$data['verifier']??null;
        if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/',$id)||!is_string($verifier)||!preg_match('/^[a-f0-9]{64}$/',$verifier))fail('Invalid pairing request',422);
        db()->beginTransaction();
        $pair=query('SELECT * FROM agent_poll_pairings WHERE id=? FOR UPDATE',[$id])->fetch();
        if(!$pair||!hash_equals($pair['verifier_hash'],hash('sha256',$verifier))||$pair['used_at']||$pair['expires_at']<=gmdate('Y-m-d H:i:s'))fail('Pairing expired or invalid',403);
        if(!$pair['approved_at']){db()->commit();pollJson(['status'=>'pending'],202);}
        $user=query('SELECT active,auth_version FROM users WHERE id=? AND workspace_id=?',[$pair['user_id'],$pair['workspace_id']])->fetch();
        if(!$user||(int)$user['active']!==1)fail('Account unavailable',403);
        $token=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
        query('INSERT INTO agent_poll_tokens (id,workspace_id,user_id,token_hash,auth_version) VALUES (?,?,?,?,?)',[uid(),$pair['workspace_id'],$pair['user_id'],hash('sha256',$token),$user['auth_version']]);
        query('UPDATE agent_poll_pairings SET used_at=UTC_TIMESTAMP() WHERE id=?',[$id]);
        db()->commit();pollJson(['status'=>'connected','token'=>$token]);
    }
    fail('Route not found',404);
}
