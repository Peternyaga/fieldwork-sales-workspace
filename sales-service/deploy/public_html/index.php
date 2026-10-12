<?php
declare(strict_types=1);

$private = dirname(__DIR__).'/fieldwork';
$settingsFile = $private.'/config.local.php';
if (!is_file($settingsFile)) {
    http_response_code(503);
    exit('Fieldwork is not configured.');
}
$settings = require $settingsFile;
if (!is_array($settings) || ($settings['oauth_base_url'] ?? '') !== 'https://reach.vumbualabs.com') {
    http_response_code(503);
    exit('Fieldwork production configuration is incomplete.');
}
putenv('FIELDWORK_OAUTH_BASE_URL='.$settings['oauth_base_url']);
putenv('FIELDWORK_OAUTH_REDIRECT_HOSTS='.$settings['oauth_redirect_hosts']);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (str_starts_with($path, '/api/')) {
    require $private.'/backend/router.php';
} elseif ($path === '/mcp' || str_starts_with($path, '/oauth/') || str_starts_with($path, '/.well-known/oauth-')) {
    require $private.'/backend/hosted-router.php';
} else {
    http_response_code(404);
}
