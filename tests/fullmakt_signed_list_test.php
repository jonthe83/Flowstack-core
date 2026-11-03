<?php

declare(strict_types=1);

/**
 * Listan under Övrigt visar namn, adress och tid.
 * Token, sökväg och signeringslänk får inte nå webbläsaren.
 * Den privata mappen ligger i samma spärr som övriga privata sökvägar.
 */

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/fullmakt_signed.php';

function fm_list_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$token = bin2hex(random_bytes(16));
$root = sys_get_temp_dir() . '/jeab-fm-signed-' . bin2hex(random_bytes(4));
$signed = $root . '/signed';
if (!mkdir($signed, 0700, true) && !is_dir($signed)) {
    fwrite(STDERR, "FAIL kunde inte skapa testkatalog\n");
    exit(1);
}
putenv('FULLMAKT_DATA=' . $root);
$signedAt = '2026-10-04T15:30:00+00:00';
$doc = [
    'token' => $token,
    'status' => 'signed',
    'namn' => 'Ada Testsson',
    'adress' => 'Testvägen 4',
    'postnummer' => '177 31',
    'ort' => 'Järfälla',
    'signed_at' => $signedAt,
];
file_put_contents($signed . '/' . $token . '.json', json_encode($doc, JSON_UNESCAPED_UNICODE));
file_put_contents($signed . '/' . $token . '.pdf', "%PDF-1.4\n%signed-list-test\n");

register_shutdown_function(static function () use ($signed, $token, $root): void {
    @unlink($signed . '/' . $token . '.json');
    @unlink($signed . '/' . $token . '.pdf');
    @rmdir($signed);
    @rmdir($root);
});

$rows = jeab_fullmakt_signed_rows();
fm_list_assert(count($rows) === 1, 'en signerad fullmakt listas');
$row = $rows[0];
fm_list_assert(($row['name'] ?? '') === 'Ada Testsson', 'listan har namn');
fm_list_assert(str_contains((string) ($row['address'] ?? ''), 'Testvägen 4'), 'listan har adress');
fm_list_assert(($row['time'] ?? '') === '2026-10-04 17:30', 'tiden visas i Stockholm, fick ' . ($row['time'] ?? ''));
fm_list_assert(!isset($row['token']), 'raden bär inte token');
$id = (string) ($row['id'] ?? '');
fm_list_assert(preg_match('/^[a-f0-9]{24}$/', $id) === 1, 'visnings-id är inte token');
fm_list_assert($id !== $token, 'visnings-id skiljer sig från token');
fm_list_assert(!str_contains($id, $token), 'visnings-id innehåller inte token');

$html = jeab_fullmakt_signed_list_html($rows);
fm_list_assert(str_contains($html, 'Ada Testsson'), 'HTML visar namn');
fm_list_assert(str_contains($html, 'Testvägen 4'), 'HTML visar adress');
fm_list_assert(str_contains($html, '2026-10-04 17:30'), 'HTML visar tid');
fm_list_assert(str_contains($html, 'vis=' . $id), 'HTML länkar med visnings-id');
fm_list_assert(!str_contains($html, $token), 'HTML skriver inte ut token');
fm_list_assert(!str_contains($html, $signed), 'HTML skriver inte ut sökväg');
fm_list_assert(!str_contains($html, '/fullmakt/s/'), 'HTML skriver inte ut signeringslänk');
fm_list_assert(!str_contains($html, 'sign.php'), 'HTML skriver inte ut sign.php');
fm_list_assert(!str_contains($html, $token . '.pdf'), 'HTML skriver inte ut filnamnet med token');
fm_list_assert(str_contains($html, '<h1>E-signering</h1>'), 'rubriken är E-signering');
fm_list_assert(!str_contains($html, 'Signerade fullmakter'), 'sidan har inte den gamla rubriken');

$pdf = jeab_fullmakt_signed_find_pdf($id);
fm_list_assert(is_string($pdf) && is_file($pdf), 'inloggat uppslag hittar PDF via id');
fm_list_assert(jeab_fullmakt_signed_find_pdf($token) === null, 'token är inte ett visnings-id');
fm_list_assert(jeab_fullmakt_signed_find_pdf(str_repeat('a', 24)) === null, 'okänt id ger ingen fil');

fm_list_assert(jeab_http_path_is_sensitive('/privat/fullmakt/signed/' . $token . '.pdf') === true, 'direktträff på privat/fullmakt/signed är 403');
fm_list_assert(jeab_http_path_is_sensitive('/data/.private/fullmakt/signed/' . $token . '.json') === true, 'direktträff på data/.private/fullmakt/signed är 403');
fm_list_assert(jeab_http_path_is_sensitive('/fullmakt/data/signed/' . $token . '.pdf') === true, 'fullmakt/data förblir spärrad');
fm_list_assert(jeab_http_path_is_sensitive('/fullmakt/lib/store.php') === true, 'fullmakt/lib förblir spärrad');
fm_list_assert(jeab_http_path_is_sensitive('/fullmakt/s/' . $token) === false, 'kundens signeringslänk är inte en data-spärr');

$spec = (string) file_get_contents(dirname(__DIR__) . '/deploy/sensitive-paths.txt');
fm_list_assert(str_contains($spec, 'prefix privat/fullmakt/signed'), 'spärrlistan har privat/fullmakt/signed');
fm_list_assert(str_contains($spec, 'prefix data/.private/fullmakt/signed'), 'spärrlistan har data/.private/fullmakt/signed');
fm_list_assert(str_contains($spec, 'prefix fullmakt/data'), 'spärrlistan har kvar fullmakt/data');
fm_list_assert(str_contains($spec, 'prefix fullmakt/lib'), 'spärrlistan har kvar fullmakt/lib');
$rules = jeab_web_deny_rules();
fm_list_assert(in_array('privat/fullmakt/signed', $rules['prefixes'], true), 'skölden läser privat/fullmakt/signed');
fm_list_assert(in_array('fullmakt/data', $rules['prefixes'], true), 'skölden lämnar fullmakt/data stängd');
fm_list_assert(in_array('fullmakt/lib', $rules['prefixes'], true), 'skölden lämnar fullmakt/lib stängd');

$ht = (string) file_get_contents(dirname(__DIR__) . '/.htaccess');
fm_list_assert(str_contains($ht, 'privat/fullmakt/signed'), 'htaccess spärrar privat/fullmakt/signed');
fm_list_assert(str_contains($ht, 'fullmakt/data'), 'htaccess lämnar fullmakt/data');
fm_list_assert(str_contains($ht, 'fullmakt/lib'), 'htaccess lämnar fullmakt/lib');

$mailer = (string) file_get_contents(dirname(__DIR__) . '/fullmakt/lib/mailer.php');
$start = strpos($mailer, 'function fullmakt_send_signed_pdf');
$end = strpos($mailer, '}', $start !== false ? $start : 0);
$body = $start !== false ? substr($mailer, $start, 1200) : '';
fm_list_assert($start !== false && !str_contains($body, 'fullmakt_staff_email'), 'signerad PDF mejlas inte till personalen');
fm_list_assert(str_contains((string) file_get_contents(dirname(__DIR__) . '/fullmakt/lib/store.php'), 'fullmakt_bokmalen_ellevio_after_signed'), 'Bokmalen-webhooken är kvar');

$links = (string) file_get_contents(dirname(__DIR__) . '/src/links.php');
fm_list_assert(str_contains($links, 'E-signering'), 'Övrigt har posten E-signering');
fm_list_assert(!str_contains($links, 'Signerade fullmakter'), 'Övrigt har inte den gamla posten');
fm_list_assert(str_contains($links, 'p=fullmakter'), 'posten pekar på fullmaktslistan');
$index = (string) file_get_contents(dirname(__DIR__) . '/index.php');
fm_list_assert(str_contains($index, "jeab_layout('E-signering · JEAB', 'links', \$body)"), 'sidtiteln är E-signering');
fm_list_assert(!str_contains($index, 'Signerade fullmakter · JEAB'), 'sidtiteln har inte det gamla namnet');
$shell = (string) file_get_contents(dirname(__DIR__) . '/assets/js/shell.js');
fm_list_assert(str_contains($shell, "fullmakter: 'E-signering'"), 'skalets sidtitel är E-signering');

$emptyRoot = sys_get_temp_dir() . '/jeab-fm-empty-' . bin2hex(random_bytes(4));
$emptySigned = $emptyRoot . '/signed';
if (!mkdir($emptySigned, 0700, true) && !is_dir($emptySigned)) {
    fwrite(STDERR, "FAIL kunde inte skapa tom kanonisk katalog\n");
    exit(1);
}
putenv('FULLMAKT_DATA=' . $emptyRoot);

$tokenLegacy = bin2hex(random_bytes(16));
$tokenPrivate = bin2hex(random_bytes(16));
$tokenPending = bin2hex(random_bytes(16));
$tokenHalf = bin2hex(random_bytes(16));
$archiveFiles = [];
$archiveDirs = [];

$remember = static function (string $path) use (&$archiveFiles): void {
    $archiveFiles[] = $path;
};
$rememberDir = static function (string $path) use (&$archiveDirs): void {
    $archiveDirs[] = $path;
};

register_shutdown_function(static function () use (&$archiveFiles, &$archiveDirs, $emptySigned, $emptyRoot): void {
    foreach ($archiveFiles as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
    foreach (array_reverse($archiveDirs) as $dir) {
        if (is_dir($dir)) {
            @rmdir($dir);
        }
    }
    @rmdir($emptySigned);
    @rmdir($emptyRoot);
});

$writePair = static function (string $dir, string $token, string $name, string $signedAt) use ($remember, $rememberDir): void {
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        fwrite(STDERR, "FAIL kunde inte skapa arkivkatalog för test\n");
        exit(1);
    }
    $rememberDir($dir);
    $doc = [
        'token' => $token,
        'status' => 'signed',
        'namn' => $name,
        'adress' => 'Arkivvägen 1',
        'postnummer' => '177 31',
        'ort' => 'Järfälla',
        'signed_at' => $signedAt,
    ];
    $json = $dir . '/' . $token . '.json';
    $pdf = $dir . '/' . $token . '.pdf';
    file_put_contents($json, json_encode($doc, JSON_UNESCAPED_UNICODE));
    file_put_contents($pdf, "%PDF-1.4\n%signed-list-archive\n");
    $remember($json);
    $remember($pdf);
};

$assertHtml = static function (array $rows, string $name, string $token): void {
    $id = '';
    foreach ($rows as $row) {
        if (($row['name'] ?? '') === $name) {
            $id = (string) ($row['id'] ?? '');
            break;
        }
    }
    fm_list_assert(preg_match('/^[a-f0-9]{24}$/', $id) === 1, $name . ' har 24-teckens id');
    fm_list_assert($id === jeab_fullmakt_signed_public_id($token), $name . ' id är hash av token');
    $html = jeab_fullmakt_signed_list_html($rows);
    fm_list_assert(str_contains($html, $name), 'HTML visar ' . $name);
    fm_list_assert(str_contains($html, $id), 'HTML visar 24-teckens id för ' . $name);
    fm_list_assert(!str_contains($html, $token), 'HTML skriver inte ut token för ' . $name);
    fm_list_assert(!str_contains($html, '/fullmakt/s/'), 'HTML skriver inte ut signeringslänk');
};

$dirs = jeab_fullmakt_signed_dirs();
fm_list_assert(($dirs[0] ?? '') === str_replace('\\', '/', $emptySigned), 'kanonisk signed står först');
fm_list_assert(is_dir($emptySigned), 'kanonisk signed finns');
fm_list_assert((glob($emptySigned . '/*') ?: []) === [], 'kanonisk signed är tom');
$legacyData = null;
$legacyPrivate = null;
foreach ($dirs as $dir) {
    if (str_ends_with($dir, '/fullmakt/data/signed')) {
        $legacyData = $dir;
    }
    if (str_ends_with($dir, '/data/.private/fullmakt/signed')) {
        $legacyPrivate = $dir;
    }
}
fm_list_assert(is_string($legacyData) && $legacyData !== $dirs[0], 'fullmakt/data/signed är med som legacy');
fm_list_assert(is_string($legacyPrivate) && $legacyPrivate !== $dirs[0], 'data/.private/fullmakt/signed är med som legacy');

$writePair($legacyData, $tokenLegacy, 'Legacy Arkivsson', '2026-09-28T08:00:00+00:00');
$pendingDir = dirname($legacyData) . '/pending';
if (!is_dir($pendingDir) && !mkdir($pendingDir, 0700, true) && !is_dir($pendingDir)) {
    fwrite(STDERR, "FAIL kunde inte skapa pending för test\n");
    exit(1);
}
$rememberDir($pendingDir);
$pendingJson = $pendingDir . '/' . $tokenPending . '.json';
file_put_contents($pendingJson, json_encode([
    'token' => $tokenPending,
    'status' => 'pending',
    'namn' => 'Pending Osynlig',
    'signed_at' => '2026-09-29T08:00:00+00:00',
], JSON_UNESCAPED_UNICODE));
$remember($pendingJson);
$halfJson = $legacyData . '/' . $tokenHalf . '.json';
file_put_contents($halfJson, json_encode([
    'token' => $tokenHalf,
    'status' => 'signed',
    'namn' => 'Halv Osynlig',
    'signed_at' => '2026-09-29T09:00:00+00:00',
], JSON_UNESCAPED_UNICODE));
$remember($halfJson);

$rows = jeab_fullmakt_signed_rows();
$names = array_map(static fn (array $row): string => (string) ($row['name'] ?? ''), $rows);
fm_list_assert(in_array('Legacy Arkivsson', $names, true), 'legacy-par i fullmakt/data/signed listas');
fm_list_assert(!in_array('Pending Osynlig', $names, true), 'pending utan par listas inte');
fm_list_assert(!in_array('Halv Osynlig', $names, true), 'json utan pdf listas inte');
fm_list_assert(!in_array('Ada Testsson', $names, true), 'förra kanoniska paret ingår inte när den katalogen inte längre är kanonisk');
$legacyRow = null;
foreach ($rows as $row) {
    if (($row['name'] ?? '') === 'Legacy Arkivsson') {
        $legacyRow = $row;
        break;
    }
}
fm_list_assert(is_array($legacyRow), 'legacy-raden finns');
$legacyId = (string) ($legacyRow['id'] ?? '');
$legacyPdf = jeab_fullmakt_signed_find_pdf($legacyId);
$expectedLegacy = $legacyData . '/' . $tokenLegacy . '.pdf';
fm_list_assert(is_string($legacyPdf) && is_file($legacyPdf) && realpath($legacyPdf) === realpath($expectedLegacy), 'find_pdf returnerar pdf i fullmakt/data/signed');
fm_list_assert((glob($emptySigned . '/*') ?: []) === [], 'läsning flyttar inte legacy-filen till kanonisk signed');
$assertHtml($rows, 'Legacy Arkivsson', $tokenLegacy);

@unlink($legacyData . '/' . $tokenLegacy . '.json');
@unlink($legacyData . '/' . $tokenLegacy . '.pdf');
@unlink($halfJson);
$writePair($legacyPrivate, $tokenPrivate, 'Privat Arkivsson', '2026-09-27T08:00:00+00:00');
$rows = jeab_fullmakt_signed_rows();
$names = array_map(static fn (array $row): string => (string) ($row['name'] ?? ''), $rows);
fm_list_assert($names === ['Privat Arkivsson'], 'bara paret i data/.private/fullmakt/signed listas');
fm_list_assert(!in_array('Legacy Arkivsson', $names, true), 'borttaget legacy-par är borta');
fm_list_assert(!in_array('Pending Osynlig', $names, true), 'pending är fortfarande dold');
$privateRow = $rows[0];
$privateId = (string) ($privateRow['id'] ?? '');
$privatePdf = jeab_fullmakt_signed_find_pdf($privateId);
$expectedPrivate = $legacyPrivate . '/' . $tokenPrivate . '.pdf';
fm_list_assert(is_string($privatePdf) && is_file($privatePdf) && realpath($privatePdf) === realpath($expectedPrivate), 'find_pdf returnerar pdf i data/.private/fullmakt/signed');
fm_list_assert((glob($emptySigned . '/*') ?: []) === [], 'läsning flyttar inte privata arkivfilen');
fm_list_assert(!is_file($emptySigned . '/' . $tokenPrivate . '.pdf'), 'kanonisk pdf skapas inte');
$assertHtml($rows, 'Privat Arkivsson', $tokenPrivate);

echo "OK  signerade fullmakter\n";
