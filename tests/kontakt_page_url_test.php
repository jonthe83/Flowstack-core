<?php

declare(strict_types=1);

/**
 * Länkar från ärende och historik går till registret.
 * Finns contact_id öppnas den kontakten, annars söks e-posten.
 */

$tmp = sys_get_temp_dir() . '/jeab-kturl-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = false;
putenv('JEAB_DISABLE_SQLITE=0');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function kturl(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$id = 'kt_urltest00000001';
$row = [
    'id' => $id,
    'street' => 'Exempelgatan 1',
    'postal_code' => '12345',
    'city' => 'Teststad',
    'owners' => [[
        'name' => 'Ada Exempel',
        'email' => 'ada@example.test',
        'phone' => '',
        'contact' => true,
    ]],
];
jeab_kontakter_mutate(static function (array &$contacts) use ($id, $row): void {
    $contacts[$id] = $row;
});

$withId = jeab_kontakt_page_url($id, 'ada@example.test');
kturl(str_contains($withId, 'p=kontakter') && str_contains($withId, 'id=' . $id) && !str_contains($withId, 'q='), 'befintligt contact_id öppnar kontakten');

$stale = jeab_kontakt_page_url('kt_saknas', 'ada@example.test');
kturl(str_contains($stale, 'p=kontakter') && str_contains($stale, 'q=ada%40example.test') && !str_contains($stale, 'id='), 'okänt id söker på e-posten');

$mailOnly = jeab_kontakt_page_url('', 'ada@example.test');
kturl($mailOnly === $stale, 'tomt id söker på e-posten');

$bare = jeab_kontakt_page_url('  ', '  ');
kturl(str_ends_with($bare, 'index.php?p=kontakter') && !str_contains($bare, 'inkorg-kontakter'), 'utan id och e-post blir bara registret');

$hits = jeab_kontakter_sorted('ada@example.test');
kturl(isset($hits[$id]), 'sökningen på e-posten träffar ägaren');

echo "kontakt page url: ok\n";
