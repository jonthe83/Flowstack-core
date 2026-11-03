<?php

declare(strict_types=1);

/**
 * Sök på hela kontakt-id:t, och öppna den även när standardkontoret är ett annat.
 * Påhittade personer.
 */

$tmp = sys_get_temp_dir() . '/jeab-kt-id-search-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = false;
putenv('JEAB_DISABLE_SQLITE=1');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function kid(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$store = jeab_store();
$malmoId = 'kt_malmo' . bin2hex(random_bytes(4));
$jarfallaId = 'kt_jarf' . bin2hex(random_bytes(4));
jeab_kontakter_mutate(static function (array &$contacts) use ($malmoId, $jarfallaId): void {
    $contacts[$malmoId] = [
        'id' => $malmoId,
        'street' => 'Idgatan 4',
        'city' => 'Malmö',
        'company' => 'malmo',
        'owners' => [['name' => 'Ida Malmö', 'contact' => true, 'personnummer' => '', 'email' => '', 'phone' => '']],
    ];
    $contacts[$jarfallaId] = [
        'id' => $jarfallaId,
        'street' => 'Hemgatan 1',
        'city' => 'Järfälla',
        'company' => 'jarfalla',
        'owners' => [['name' => 'Kim Järfälla', 'contact' => true, 'personnummer' => '', 'email' => '', 'phone' => '']],
    ];
});

$plain = jeab_kontakter_view($store, '', 'alla', 'jarfalla', 50);
kid(!isset($plain['rows'][$malmoId]) && isset($plain['rows'][$jarfallaId]), 'tom sökning lämnar Malmö utanför Järfälla');

$prefix = jeab_kontakter_view($store, 'kt_', 'alla', 'jarfalla', 50);
kid(!isset($prefix['rows'][$malmoId]) && !isset($prefix['rows'][$jarfallaId]), 'prefixet kt_ är inte en träff');

$byId = jeab_kontakter_view($store, $malmoId, 'alla', 'jarfalla', 50);
kid(isset($byId['rows'][$malmoId]) && (int) $byId['total'] >= 1, 'exakt id syns trots Järfälla-filter');
kid(!isset($byId['rows'][$jarfallaId]), 'id-sökning tar inte med andra kontor');

$upper = jeab_kontakter_view($store, strtoupper($malmoId), 'alla', 'jarfalla', 50);
kid(isset($upper['rows'][$malmoId]), 'id-sökning tål versaler');

$opened = jeab_kontakter_view($store, '', 'alla', 'jarfalla', 50, $malmoId);
kid(isset($opened['rows'][$malmoId]) && isset($opened['rows'][$jarfallaId]), 'öppnat id visas tillsammans med kontoret');

$catalogIds = array_map(static fn (array $item): string => (string) ($item['id'] ?? ''), $opened['catalog']);
kid(in_array($malmoId, $catalogIds, true), 'öppnat id finns i katalogen');

$_GET = ['p' => 'kontakter', 'co' => 'jarfalla', 'q' => $malmoId];
$flash = '';
$page = require dirname(__DIR__) . '/src/pages/kontakter.php';
kid(is_string($page) && str_contains($page, 'Idgatan 4') && str_contains($page, 'data-kt-empty hidden'), 'sidan listar id-sökningen');

$_GET = ['p' => 'kontakter', 'co' => 'jarfalla', 'id' => $malmoId];
$flash = 'Sparad.';
$page = require dirname(__DIR__) . '/src/pages/kontakter.php';
kid(is_string($page) && str_contains($page, 'Idgatan 4') && str_contains($page, 'data-kt-flash="Sparad."'), 'öppnat id och flash ritas');

$src = (string) file_get_contents(dirname(__DIR__) . '/src/pages/kontakter.php');
kid(str_contains($src, 'SAVE_MS = 2500') && str_contains($src, 'data-kt-save-status'), 'Sparad. sitter vid Spara i 2,5 s');
kid(str_contains($src, 'fold(item.id) === q') && str_contains($src, '/^kt_[a-z0-9]+$/'), 'klientsök matchar exakt id och hämtar resten från servern');

echo "kontakter id search ok\n";
