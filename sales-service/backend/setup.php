<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli') exit(1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/migrations.php';
$email=$argv[1]??'';$name=$argv[2]??'Owner';$business=$argv[3]??'My sales workspace';
$password=getenv('SALES_SETUP_PASSWORD');
if(!filter_var($email,FILTER_VALIDATE_EMAIL)||!$password||strlen($password)<12) {fwrite(STDERR,"Usage: set SALES_SETUP_PASSWORD (12+ characters), then php backend/setup.php email name business\n");exit(1);}
applySchema();
if(query('SELECT id FROM users WHERE email=?',[$email])->fetch()) {fwrite(STDERR,"User already exists; no changes made.\n");exit(1);}
db()->beginTransaction();
$workspace=uid();query('INSERT INTO workspaces (id,name) VALUES (?,?)',[$workspace,$business]);
query('INSERT INTO users (id,workspace_id,name,email,password_hash) VALUES (?,?,?,?,?)',[uid(),$workspace,$name,$email,password_hash($password,PASSWORD_DEFAULT)]);
db()->commit();echo "Workspace and owner created. No leads or sample data imported.\n";
