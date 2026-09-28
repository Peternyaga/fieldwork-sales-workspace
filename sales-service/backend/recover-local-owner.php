<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/accounts.php';
$email=strtolower(trim($argv[1]??''));
if(!filter_var($email,FILTER_VALIDATE_EMAIL)){
    fwrite(STDERR,"Usage: php backend/recover-local-owner.php owner@example.com\n");exit(1);
}
$owner=query("SELECT id,workspace_id,active,setup_pending FROM users WHERE email=? AND role='owner'",[$email])->fetch();
if(!$owner||!$owner['active']){fwrite(STDERR,"An active owner with that email was not found.\n");exit(1);}
$_SESSION=['user_id'=>$owner['id']];
db()->beginTransaction();$issued=issueAccountToken($owner,'reset');db()->commit();
echo "Open this one-use local recovery link within 30 minutes:\nhttp://127.0.0.1:5178/#account={$issued['token']}\n";
