<?php

declare(strict_types=1);

/**
 * O3: offert-förhandsgranskning är en slot per tråd. Skick/läsning
 * använder bara texten för samma tråd-id, även i SQL quote-preview.
 * Påhittade tråd-id. Inget skickas.
 */

$tmp = sys_get_temp_dir() . '/jeab-quote-slot-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function qs(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$src = (string) file_get_contents(dirname(__DIR__) . '/src/inkorg.php');
$side = (string) file_get_contents(dirname(__DIR__) . '/src/inkorg_side_store.php');
qs(str_contains($src, 'function jeab_inkorg_quote_preview_doc_put') && str_contains($src, 'by_thread'), 'JSON-sloten är per tråd');
qs(str_contains($side, 'by_thread') && str_contains($side, 'jeab_inkorg_quote_preview_doc_put'), 'SQL quote-preview slår upp per tråd');

jeab_inkorg_quote_preview_save('th-a', '<p>Alpha-offert</p>');
jeab_inkorg_quote_preview_save('th-b', '<p>Beta-offert</p>');
qs(jeab_inkorg_quote_preview_text('th-a') === 'Alpha-offert', 'tråd A behåller sin förhandsvisning');
qs(jeab_inkorg_quote_preview_text('th-b') === 'Beta-offert', 'tråd B har sin egen förhandsvisning');
qs(jeab_inkorg_quote_preview_text('th-c') === '', 'okänd tråd får ingen annan tråds text');

$old = ['thread' => 'th-old', 'text' => 'Gammal slot', 'at' => '2026-01-01T00:00:00+01:00'];
qs(jeab_inkorg_quote_preview_doc_get($old, 'th-old') === 'Gammal slot', 'gammal global slot läses för samma tråd');
qs(jeab_inkorg_quote_preview_doc_get($old, 'th-other') === '', 'gammal global slot läses inte för annan tråd');
$merged = jeab_inkorg_quote_preview_doc_put($old, 'th-new', 'Ny slot');
qs(jeab_inkorg_quote_preview_doc_get($merged, 'th-old') === 'Gammal slot', 'put behåller den gamla tråden');
qs(jeab_inkorg_quote_preview_doc_get($merged, 'th-new') === 'Ny slot', 'put lägger den nya tråden');
$sideMerge = jeab_inkorg_side_merge('quote-preview', $old, ['thread' => 'th-new', 'text' => 'Ny slot', 'at' => '2026-02-02T00:00:00+01:00']);
qs(jeab_inkorg_quote_preview_doc_get($sideMerge, 'th-old') === 'Gammal slot' && jeab_inkorg_quote_preview_doc_get($sideMerge, 'th-new') === 'Ny slot', 'SQL-merge slår ihop slotar per tråd');

echo "OK  quote_preview_slot\n";
