<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/migrations.php';
if ($argc !== 4) {
    fwrite(STDERR, "Usage: php backend/provision-hosted-owner.php WORKSPACE NAME EMAIL\n");
    exit(1);
}
[$script,$business,$name,$email] = $argv;
$business=trim($business);$name=trim($name);$email=strtolower(trim($email));
if ($business===''||mb_strlen($business)>150||$name===''||mb_strlen($name)>150||!filter_var($email,FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR,"Check the workspace, name, and email.\n");exit(1);
}
applySchema();
if ((int)query('SELECT COUNT(*) FROM workspaces')->fetchColumn() !== 0) {
    fwrite(STDERR,"A workspace already exists. Initial provisioning is disabled.\n");exit(1);
}
if (function_exists('stream_isatty') && stream_isatty(STDIN)) {
    fwrite(STDERR,"Pipe the password from a hidden shell prompt; do not type it as a command argument.\n");exit(1);
}
$password=rtrim((string)fgets(STDIN),"\r\n");
if (strlen($password)<12 || strlen($password)>500) {
    fwrite(STDERR,"Use a password of 12 to 500 characters.\n");exit(1);
}
db()->beginTransaction();
try {
    if (query('SELECT id FROM workspaces LIMIT 1 FOR UPDATE')->fetch()) throw new RuntimeException('Workspace already exists');
    $workspace=uid();$user=uid();
    query('INSERT INTO workspaces (id,name) VALUES (?,?)',[$workspace,$business]);
    query('INSERT INTO users (id,workspace_id,name,email,password_hash,role) VALUES (?,?,?,?,?,?)',[$user,$workspace,$name,$email,password_hash($password,PASSWORD_DEFAULT),'owner']);
    db()->commit();
    fwrite(STDOUT,"Owner account created.\n");
} catch (Throwable $error) {
    db()->rollBack();
    fwrite(STDERR,"Owner setup failed.\n");exit(1);
}
