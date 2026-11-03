<?php

declare(strict_types=1);

/**
 * P1: fullmaktens arkiv och secret.php lämnar webbroten.
 * Hemligheten skrivs inte ut.
 */

$root = dirname(__DIR__);
$legacy = $root . '/fullmakt/data';
$token = 'mig' . bin2hex(random_bytes(4));
$marker = 'fm-test-' . bin2hex(random_bytes(8));
$secretBody = "<?php\n\$FULLMAKT_KEY = " . var_export($marker, true) . ";\n";
$pendingBody = json_encode([
    'token' => $token,
    'status' => 'pending',
    'namn' => 'Test',
], JSON_UNESCAPED_UNICODE);
$pdfBody = "%PDF-1.4\n%test\n";

$plantSecret = !is_file($root . '/data/.private/fullmakt/secret.php')
    && !is_file(dirname($root) . '/privat/fullmakt/secret.php');
$cleanup = static function () use ($legacy, $token, &$plantSecret): void {
    $paths = [
        $legacy . '/pending/' . $token . '.json',
        $legacy . '/signed/' . $token . '.pdf',
        $legacy . '/secret.php',
    ];
    if (defined('FULLMAKT_DATA')) {
        $paths[] = FULLMAKT_DATA . '/pending/' . $token . '.json';
        $paths[] = FULLMAKT_DATA . '/signed/' . $token . '.pdf';
        if ($plantSecret) {
            $paths[] = FULLMAKT_DATA . '/secret.php';
        }
    }
    foreach ($paths as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
    foreach ([
        $legacy . '/pending',
        $legacy . '/signed',
        $legacy . '/tmp',
        $legacy . '/originals',
        $legacy,
    ] as $dir) {
        if (is_dir($dir)) {
            @rmdir($dir);
        }
    }
};

register_shutdown_function($cleanup);

foreach (['pending', 'signed'] as $name) {
    $dir = $legacy . '/' . $name;
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        fwrite(STDERR, "FAIL kunde inte skapa testkatalog\n");
        exit(1);
    }
}
file_put_contents($legacy . '/pending/' . $token . '.json', $pendingBody);
file_put_contents($legacy . '/signed/' . $token . '.pdf', $pdfBody);
if (is_file($legacy . '/secret.php')) {
    fwrite(STDERR, "FAIL legacy secret.php fanns redan, avbryter för att inte röra den\n");
    exit(1);
}
if ($plantSecret) {
    file_put_contents($legacy . '/secret.php', $secretBody);
}

require $root . '/fullmakt/lib/bootstrap.php';
require $root . '/fullmakt/lib/store.php';

function fm_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$canonical = str_replace('\\', '/', FULLMAKT_DATA);
$legacyNorm = str_replace('\\', '/', $legacy);
$web = str_replace('\\', '/', $root);
fm_assert($canonical !== $legacyNorm, 'FULLMAKT_DATA är inte fullmakt/data');
fm_assert(!str_starts_with($canonical, $legacyNorm . '/'), 'FULLMAKT_DATA ligger inte under fullmakt/data');
$outside = str_replace('\\', '/', dirname($web) . '/privat/fullmakt');
$dotted = $web . '/data/.private/fullmakt';
fm_assert(
    $canonical === $outside || $canonical === $dotted,
    'arkivet ligger i privat/ eller data/.private/fullmakt'
);
fm_assert(
    !str_starts_with($canonical, $web . '/fullmakt/'),
    'arkivet ligger utanför URL-trädet fullmakt/'
);

fullmakt_boot();

$pending = fullmakt_pending_path($token);
$pdf = fullmakt_signed_pdf_path($token);
fm_assert(is_file($pending), 'väntande json finns efter flytt');
fm_assert(is_file($pdf), 'signerad pdf finns efter flytt');
fm_assert(!is_file($legacy . '/pending/' . $token . '.json'), 'publik pending-json är borta');
fm_assert(!is_file($legacy . '/signed/' . $token . '.pdf'), 'publik pdf är borta');
fm_assert((string) file_get_contents($pending) === $pendingBody, 'väntande json behöll innehållet');
fm_assert((string) file_get_contents($pdf) === $pdfBody, 'pdf behöll innehållet');
fm_assert(str_starts_with(str_replace('\\', '/', $pending), $canonical . '/'), 'pending läses från privat katalog');
fm_assert(str_starts_with(str_replace('\\', '/', $pdf), $canonical . '/'), 'pdf läses från privat katalog');

if ($plantSecret) {
    $key = fullmakt_key();
    fm_assert($key === $marker, 'signingshemligheten följer med och kan läsas');
    fm_assert(!is_file($legacy . '/secret.php'), 'publik secret.php är borta');
    fm_assert(is_file(FULLMAKT_DATA . '/secret.php'), 'secret.php ligger i privat katalog');
    fm_assert((fileperms(FULLMAKT_DATA . '/secret.php') & 0777) === 0600, 'secret.php är 0600');
}
fm_assert((fileperms(FULLMAKT_DATA) & 0777) === 0700, 'datakatalogen är 0700');
fm_assert((fileperms(FULLMAKT_PENDING) & 0777) === 0700, 'pending är 0700');
fm_assert((fileperms(FULLMAKT_SIGNED) & 0777) === 0700, 'signed är 0700');

$doc = fullmakt_load_doc($token);
fm_assert(is_array($doc) && ($doc['token'] ?? '') === $token, 'sign-flödet hittar det flyttade dokumentet');

foreach (glob($root . '/fullmakt/lib/*.php') ?: [] as $file) {
    $src = (string) file_get_contents($file);
    $deny = strpos($src, 'jeab_deny_if_direct(__FILE__)');
    $fn = strpos($src, "\nfunction ");
    fm_assert($deny !== false, basename($file) . ' anropar jeab_deny_if_direct');
    fm_assert($fn === false || $deny < $fn, basename($file) . ' nekar direktträff före funktioner');
}

$srcBoot = (string) file_get_contents($root . '/fullmakt/lib/bootstrap.php');
fm_assert(!str_contains($srcBoot, '0777'), 'bootstrap skapar inte längre kataloger med 0777');

echo "fullmakt private store: ok\n";
