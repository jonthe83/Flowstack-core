<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/layout.php';

$code = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') ? 307 : 302;
jeab_redirect(jeab_manuell_order_url($_GET), $code);
