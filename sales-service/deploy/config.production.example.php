<?php
// Copy to fieldwork/config.local.php outside public_html; fill in a restricted database user.
return [
    'dsn' => 'mysql:host=localhost;dbname=YOUR_DATABASE;charset=utf8mb4',
    'user' => 'YOUR_DATABASE_USER',
    'password' => 'YOUR_DATABASE_PASSWORD',
    'oauth_base_url' => 'https://reach.vumbualabs.com',
    'oauth_redirect_hosts' => 'chatgpt.com,127.0.0.1,localhost',
];
