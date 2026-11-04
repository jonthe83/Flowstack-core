<?php

declare(strict_types=1);

/**
 * Nginx-värdar ignorerar .htaccess. När den här filen körs ska
 * /bokning/ alltid till apex index.php med samma query.
 */
require dirname(__DIR__) . '/src/shield.php';

if (PHP_SAPI === 'cli') {
    return;
}

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Location: ' . jeab_bokning_apex_target(), true, 302);
exit;
