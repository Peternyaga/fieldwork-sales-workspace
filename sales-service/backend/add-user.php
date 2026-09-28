<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require __DIR__.'/bootstrap.php';
$email=$argv[1]??'';$name=$argv[2]??'';$role=$argv[3]??'';$workspace=$argv[4]??'';$password=getenv('SALES_SETUP_PASSWORD');
if(!filter_var($email,FILTER_VALIDATE_EMAIL)||trim($name)===''||!in_array($role,['owner','manager','salesperson','collector'],true)||!preg_match('/^[a-f0-9]{32}$/',$workspace)||!$password||strlen($password)<12){fwrite(STDERR,"Usage: set SALES_SETUP_PASSWORD (12+ characters); php backend/add-user.php email name role workspace_id\n");exit(1);}
if(!query('SELECT id FROM workspaces WHERE id=?',[$workspace])->fetch()){fwrite(STDERR,"Workspace does not exist.\n");exit(1);}
query('INSERT INTO users (id,workspace_id,name,email,password_hash,role) VALUES (?,?,?,?,?,?)',[uid(),$workspace,trim($name),$email,password_hash($password,PASSWORD_DEFAULT),$role]);
echo "Team member created.\n";
