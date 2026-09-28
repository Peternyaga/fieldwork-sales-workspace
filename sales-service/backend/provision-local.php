<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
$dir=dirname(__DIR__).'/var';if(!is_dir($dir))mkdir($dir,0700,true);
$file=$dir.'/local-account.json';
if(is_file($file)){echo "Local account already provisioned.\n";exit;}
$account=['email'=>'owner@local.test','password'=>bin2hex(random_bytes(16))];
putenv('SALES_SETUP_PASSWORD='.$account['password']);
$argv=[__FILE__,$account['email'],'Owner','My sales workspace'];require __DIR__.'/setup.php';
file_put_contents($file,json_encode($account,JSON_PRETTY_PRINT));chmod($file,0600);
echo "Credentials saved in var/local-account.json; do not publish this file.\n";
