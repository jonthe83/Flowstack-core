<?php

declare(strict_types=1);

/**
 * Vanlig HTTP ska bli HTTPS i Apache innan PHP körs.
 * Proxyn som redan sagt https får inte loopa. shield.php rörs inte.
 */

$root = dirname(__DIR__);

function hre(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function hre_block(string $htaccess): string
{
    $start = strpos($htaccess, 'RewriteCond %{HTTPS} !=on');
    if ($start === false) {
        return '';
    }
    $end = strpos($htaccess, 'RewriteRule ^', $start);
    if ($end === false) {
        return '';
    }
    $lineEnd = strpos($htaccess, "\n", $end);

    return substr($htaccess, $start, ($lineEnd === false ? strlen($htaccess) : $lineEnd) - $start);
}

$files = [
    $root . '/.htaccess',
    $root . '/bokning/.htaccess',
    $root . '/rot/.htaccess',
];
foreach ($files as $file) {
    $text = (string) file_get_contents($file);
    $block = hre_block($text);
    $name = ltrim(str_replace($root, '', $file), '/');
    hre($block !== '', $name . ' har HTTPS-omdirigering');
    hre(str_contains($block, 'RewriteCond %{HTTP:X-Forwarded-Proto} !https [NC]'), $name . ' loopar inte när proxyn redan är https');
    hre(str_contains($block, 'RewriteCond %{HTTP_HOST} ^[A-Za-z0-9.-]+(?::[0-9]+)?$'), $name . ' tar bara ett vanligt värdnamn');
    hre(str_contains($block, 'RewriteCond %{REQUEST_URI} !^/\\.well-known/acme-challenge/'), $name . ' släpper ACME-utmaningen');
    hre(str_contains($block, 'RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L,QSA]'), $name . ' svarar 301 till https och behåller frågan');
}

$rootHt = (string) file_get_contents($root . '/.htaccess');
$denyAt = strpos($rootHt, 'RewriteRule ^data/ - [F,L]');
$httpsAt = strpos($rootHt, 'RewriteCond %{HTTPS} !=on');
hre($denyAt !== false && $httpsAt !== false && $denyAt < $httpsAt, 'spärrade sökvägar förblir 403 även på HTTP');

$shield = (string) file_get_contents($root . '/src/shield.php');
hre(!str_contains($shield, 'HTTP_HOST') && !str_contains($shield, 'X-Forwarded-Proto'), 'shield.php gör inte omdirigeringen');

echo "https_redirect_htaccess_test: ok\n";
