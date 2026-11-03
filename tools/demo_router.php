<?php

declare(strict_types=1);

/**
 * php -S 127.0.0.1:8099 tools/demo_router.php
 * Dokumentrot är arbetskatalogen (jeab-demo).
 */

if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}

$uri = rawurldecode((string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/'));
$root = dirname(__DIR__);
require_once $root . '/src/demo.php';

if ($uri === '/robots.txt' && jeab_demo_mode()) {
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
    echo "User-agent: *\nDisallow: /\n";
    return;
}

$file = $root . $uri;
if ($uri !== '/' && is_file($file)) {
    return false;
}

require $root . '/index.php';
