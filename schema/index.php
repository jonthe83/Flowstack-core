<?php

declare(strict_types=1);

http_response_code(403);
header('Content-Type: text/plain; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
echo "Forbidden\n";
exit;
