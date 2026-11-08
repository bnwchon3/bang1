<?php
declare(strict_types=1);
// Render the original Google Apps Script UI unchanged. Only transport has moved to PHP/MariaDB.
require __DIR__.'/inc/bootstrap.php';
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
readfile(__DIR__.'/legacy-app.html');
