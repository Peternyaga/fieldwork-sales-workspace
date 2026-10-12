<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__.'/bootstrap.php';
query('DELETE FROM oauth_codes WHERE expires_at<UTC_TIMESTAMP()');
query('DELETE FROM oauth_grants WHERE refresh_expires_at<UTC_TIMESTAMP()');
query('DELETE FROM oauth_rate_limits WHERE window_start<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY)');
fwrite(STDOUT,"Expired OAuth records removed.\n");
