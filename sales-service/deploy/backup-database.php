<?php
declare(strict_types=1);

// Called only by install.sh on the hosting account. Do not print credentials.
if (PHP_SAPI !== 'cli' || $argc !== 3) {
    fwrite(STDERR, "Usage: php backup-database.php CONFIG BACKUP.sql\n");
    exit(2);
}
[$script, $configPath, $backupPath] = $argv;
if (!is_file($configPath)) {
    fwrite(STDERR, "Private production configuration is missing.\n");
    exit(1);
}
$config = require $configPath;
$dsn = (string)($config['dsn'] ?? '');
if (!preg_match('/^mysql:(.*)$/', $dsn, $match)) {
    fwrite(STDERR, "Only MySQL DSNs are supported.\n");
    exit(1);
}
$parts = [];
foreach (explode(';', $match[1]) as $part) {
    if ($part === '') continue;
    [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
    $parts[$key] = $value;
}
if (empty($parts['dbname']) || empty($config['user']) || !array_key_exists('password', $config)) {
    fwrite(STDERR, "Incomplete database configuration.\n");
    exit(1);
}
$dump = trim((string)shell_exec('command -v mysqldump 2>/dev/null || command -v mariadb-dump 2>/dev/null'));
if ($dump === '' || !function_exists('proc_open')) {
    fwrite(STDERR, "A MySQL dump utility and PHP proc_open are required for a safe backup.\n");
    exit(1);
}
$escape = static function(string $value): string {
    return '"'.str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\\n', '\\r'], $value).'"';
};
$defaults = tempnam(dirname($backupPath), 'mysql-');
if ($defaults === false) throw new RuntimeException('Cannot create temporary database options');
try {
    chmod($defaults, 0600);
    $options = [
        'user' => (string)$config['user'],
        'password' => (string)$config['password'],
    ];
    foreach (['host', 'port', 'unix_socket'] as $key) {
        if (!empty($parts[$key])) $options[$key === 'unix_socket' ? 'socket' : $key] = $parts[$key];
    }
    $contents = "[client]\n";
    foreach ($options as $key => $value) $contents .= $key.'='.$escape($value)."\n";
    if (file_put_contents($defaults, $contents) === false) throw new RuntimeException('Cannot write temporary database options');
    $output = fopen($backupPath, 'wb');
    if ($output === false) throw new RuntimeException('Cannot create database backup');
    $command = [$dump, '--defaults-extra-file='.$defaults, '--single-transaction', '--quick', '--result-file='.$backupPath, $parts['dbname']];
    // The dump writes directly to the backup path. No credentials enter argv.
    fclose($output);
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not start database backup');
    stream_get_contents($pipes[1]); fclose($pipes[1]);
    $errors = trim(stream_get_contents($pipes[2])); fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0 || !is_file($backupPath) || filesize($backupPath) === 0) {
        throw new RuntimeException('Database backup failed'.($errors !== '' ? ': '.$errors : ''));
    }
    chmod($backupPath, 0600);
    echo "Database backup saved.\n";
} catch (Throwable $error) {
    @unlink($backupPath);
    fwrite(STDERR, $error->getMessage()."\n");
    exit(1);
} finally {
    @unlink($defaults);
}
