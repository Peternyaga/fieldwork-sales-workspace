<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require __DIR__.'/bootstrap.php';require __DIR__.'/migrations.php';
applySchema();echo "Sales workspace schema is current.\n";
