<?php

declare(strict_types=1);

/**
 * Övrigt autosparas som övriga kontaktfält. Personnummer sparas bara med Spara.
 * Spara får busy/disabled under skick. Påhittade värden, inget giltigt
 * personnummer, inget nummer i utskrift.
 */

$tmp = sys_get_temp_dir() . '/jeab-kontakter-autosave-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = false;
putenv('JEAB_DISABLE_SQLITE=0');
$_ENV['JEAB_DISABLE_SQLITE'] = '0';
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function ka(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$src = (string) file_get_contents(dirname(__DIR__) . '/src/pages/kontakter.php');
ka(preg_match('/var smallNames = \[([^\]]+)\]/', $src, $namesMatch) === 1, 'autosave-listan finns');
$names = $namesMatch[1];
ka(
    str_contains($names, "'street'")
    && str_contains($names, "'email'")
    && str_contains($names, "'ovrigt'"),
    'övrigt ligger i autosave-listan'
);
ka(!str_contains($names, 'owner_pnr'), 'personnummer autosparas INTE (ligger inte i autosave-listan)');
ka(preg_match("/var heavyNames = \\['owner_pnr_1', 'owner_pnr_2'\\]/", $src) === 1, 'personnummer ligger kvar bland fält som bara sparas med Spara');
ka(str_contains($src, 'if (!includeHeavy && heavyDirty()) return;'), 'autosave hoppar över när personnummer är ändrat men osparat');
ka(str_contains($src, 'smallNames.forEach') && str_contains($src, "addEventListener('input', soon)"), 'autosave lyssnar på input för fälten i listan');
ka(str_contains($src, "new FormData(saveForm)") && str_contains($src, 'kontakter.save') && str_contains($src, 'jeab_csrf_field()'), 'autosave skickar samma endpoint och CSRF');
ka(str_contains($src, "function setSaveBusy") && str_contains($src, "setAttribute('data-busy', '1')"), 'Spara sätter data-busy under skick');
ka(str_contains($src, 'saveBtn.disabled = true') && str_contains($src, "setAttribute('aria-busy', 'true')"), 'Spara blir disabled och aria-busy under skick');
ka(str_contains($src, "getAttribute('data-busy') === '1'"), 'Spara skickas inte igen medan den är busy');
ka(str_contains($src, "removeAttribute('data-busy')") && str_contains($src, 'saveBtn.disabled = false'), 'Spara släpps efter skick');

$note = 'TEST-OVRIGT';
$saved = jeab_kontakt_save_input([
    'street' => 'Autosavegatan 3',
    'postal_code' => '12345',
    'city' => 'Teststad',
    'owner_name_1' => 'Auto Save',
    'contact_person' => '1',
    'email' => 'autosave@example.test',
    'phone' => '0700000000',
    'ovrigt' => $note,
    'company' => 'jarfalla',
]);
ka(str_starts_with((string) ($saved['id'] ?? ''), 'kt_'), 'kontakten får ett id');
ka(jeab_kontakt_ovrigt($saved) === $note, 'Övrigt sparas via samma save-input som sidan');

$again = jeab_kontakt_save_input([
    'id' => (string) $saved['id'],
    'street' => 'Autosavegatan 3',
    'postal_code' => '12345',
    'city' => 'Teststad',
    'owner_name_1' => 'Auto Save',
    'owner_pnr_1' => '',
    'contact_person' => '1',
    'email' => 'autosave@example.test',
    'phone' => '0700000000',
    'ovrigt' => $note,
    'company' => 'jarfalla',
]);
ka(jeab_kontakt_ovrigt($again) === $note, 'Övrigt följer med vid ny autosave-liknande sparning');

try {
    jeab_kontakt_save_input([
        'id' => (string) $saved['id'],
        'street' => 'Autosavegatan 3',
        'city' => 'Teststad',
        'owner_name_1' => 'Auto Save',
        'owner_pnr_1' => $note,
        'contact_person' => '1',
        'ovrigt' => $note,
        'company' => 'jarfalla',
    ]);
    ka(false, 'ogiltigt personnummer stoppar sparning');
} catch (InvalidArgumentException $e) {
    ka($e->getMessage() === 'Personnumret är ogiltigt.', 'feltexten nämner inte inmatningen');
}

$store = jeab_store();
$error = null;
$flash = null;
$_GET = ['p' => 'kontakter', 'id' => (string) $saved['id'], 'co' => 'jarfalla'];
$html = require dirname(__DIR__) . '/src/pages/kontakter.php';
ka(is_string($html), 'detaljsidan renderas');
ka(str_contains($html, 'name="_csrf"') && str_contains($html, 'name="action"') && str_contains($html, 'kontakter.save'), 'formuläret har CSRF och kontakter.save');
ka(str_contains($html, 'name="ovrigt"') && str_contains($html, $note), 'Övrigt-fältet visar det sparade värdet');
ka(str_contains($html, 'name="owner_pnr_1"') && str_contains($html, 'name="owner_pnr_2"'), 'personnummerfälten finns i formuläret');
ka(str_contains($html, 'class="kt-save"') && str_contains($html, '>Spara<'), 'Spara-knappen finns');
ka(str_contains($html, 'data-kt-optimistic') && str_contains($html, "'ovrigt'"), 'sidan binder autosave för Övrigt');

echo "kontakter autosave ok\n";
