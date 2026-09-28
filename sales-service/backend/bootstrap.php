<?php
declare(strict_types=1);
function db(): PDO {
    static $db;
    if ($db) return $db;
    $file=dirname(__DIR__).'/config.local.php';
    $config=require is_file($file)?$file:dirname(__DIR__).'/config.example.php';
    $db=new PDO($config['dsn'],$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    $db->exec("SET time_zone = '+00:00'");
    return $db;
}
function query(string $sql,array $args=[]): PDOStatement {$s=db()->prepare($sql);$s->execute($args);return $s;}
function uid(): string {return bin2hex(random_bytes(16));}
function fail(string $message,int $code=422): never {throw new RuntimeException($message,$code);}
function textField(array $data,string $key,int $max=250,bool $required=false): string {
    if(isset($data[$key])&&!is_string($data[$key])) fail("Invalid $key");
    $value=trim($data[$key]??'');
    if(mb_strlen($value)>$max||($required&&$value==='')) fail("Check $key");
    return $value;
}
function workspace(): string {return $_SESSION['workspace_id'];}
function role(): string {return $_SESSION['user_role']??'collector';}
function requireRole(array $allowed): void {if(!in_array(role(),$allowed,true))fail('Your role cannot perform this action',403);}
function canAccessLead(array $lead): bool {return in_array(role(),['owner','manager'],true)||($lead['owner_user_id']??null)===$_SESSION['user_id']||($lead['collector_user_id']??null)===$_SESSION['user_id'];}
function audit(string $entity,string $action,array $data): void {query('INSERT INTO activities (id,workspace_id,lead_id,kind,body,actor) VALUES (?,?,?,?,?,?)',[uid(),workspace(),$entity,$action,json_encode($data,JSON_THROW_ON_ERROR),$_SESSION['user_name']]);}
