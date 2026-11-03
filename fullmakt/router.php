<?php
/**
 * Router for `php -S localhost:8080 -t fullmakt router.php`
 * and as a fallback if the host maps unknown paths here.
 */
$uri = urldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
$uri = (string) preg_replace('#^/fullmakt#', '', $uri);
if ($uri === '') {
    $uri = '/';
}

if (preg_match('#^/(?:lib|data|vendor)(?:/|$)#', $uri) === 1) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    echo "Forbidden\n";
    return true;
}

if (preg_match('#^/s/([A-Za-z0-9_-]+)/?$#', $uri, $m)) {
    $_GET['token'] = $m[1];
    require __DIR__ . '/sign.php';
    return true;
}

if ($uri === '/tack' || $uri === '/tack.php') {
    require __DIR__ . '/tack.php';
    return true;
}

if ($uri === '/preview.php' || $uri === '/preview') {
    require __DIR__ . '/preview.php';
    return true;
}

if ($uri === '/api/create.php' || $uri === '/api/create') {
    require __DIR__ . '/api/create.php';
    return true;
}

if ($uri === '/api/invite.php' || $uri === '/api/invite') {
    require __DIR__ . '/api/invite.php';
    return true;
}

$file = __DIR__ . $uri;
if ($uri !== '/' && is_file($file)) {
    return false;
}

require __DIR__ . '/index.php';
return true;
