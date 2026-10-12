<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || (function_exists('stream_isatty') && !stream_isatty(STDIN))) {
    fwrite(STDERR, "Run this from an interactive server terminal.\n");
    exit(1);
}

$configFile = dirname(__DIR__).'/config.local.php';
if (is_file($configFile)) {
    fwrite(STDERR, "A private configuration already exists; refusing to replace it.\n");
    exit(1);
}

fwrite(STDERR, 'Database password for supashop_reach (hidden): ');
$hidden = false;
try {
    if (function_exists('system')) {
        system('stty -echo', $status);
        $hidden = $status === 0;
    }
    if (!$hidden) {
        fwrite(STDERR, "\nCould not hide terminal input. No password was read.\n");
        exit(1);
    }
    $password = rtrim((string) fgets(STDIN), "\r\n");
} finally {
    if ($hidden) system('stty echo');
    fwrite(STDERR, "\n");
}

if ($password === '') {
    fwrite(STDERR, "No password entered.\n");
    exit(1);
}

$settings = [
    'dsn' => 'mysql:host=localhost;dbname=supashop_reach;charset=utf8mb4',
    'user' => 'supashop_reach',
    'password' => $password,
    'oauth_base_url' => 'https://reach.vumbualabs.com',
    'oauth_redirect_hosts' => 'chatgpt.com,127.0.0.1,localhost',
];

try {
    $db = new PDO($settings['dsn'], $settings['user'], $settings['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->query('SELECT 1');
} catch (Throwable $error) {
    fwrite(STDERR, "Database login failed. No configuration was saved.\n");
    exit(1);
}

$contents = "<?php\nreturn ".var_export($settings, true).";\n";
if (file_put_contents($configFile, $contents, LOCK_EX) === false || !chmod($configFile, 0600)) {
    fwrite(STDERR, "Could not save the private configuration.\n");
    exit(1);
}
fwrite(STDOUT, "Database connection verified; private configuration saved.\n");
