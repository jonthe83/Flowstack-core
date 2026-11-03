<?php

declare(strict_types=1);

$data = getenv('JEAB_DATA_DIR') ?: '';
if ($data !== '') {
    $GLOBALS['_JEAB_DATA_DIR'] = $data;
}
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/layout.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
header('Content-Type: text/html; charset=utf-8');
echo jeab_chrome_company_html('reminders');
