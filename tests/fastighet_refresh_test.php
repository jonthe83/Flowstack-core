<?php

declare(strict_types=1);

/**
 * order.fastighet_refresh / order.find.
 * Diff bara på ändrade fält. dry=1 skriver inget. dry=0 plus undo.
 * Inget personnummer i svar eller logg. Påhittade siffror skrivs inte ut.
 * apply plus undo skapar ingen fil under data/.
 */

$tmp = sys_get_temp_dir() . '/jeab-fastighet-refresh-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('JEAB_WEBHOOK_CAPTURE=1');
$_GET['co'] = 'jarfalla';
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'not-the-inbox-token',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");

require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/address_lookup.php';

function fr_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function fr_digits(): string
{
    return implode('', ['19', '80', '01', '01', '12', '34']);
}

function fr_digits_b(): string
{
    return implode('', ['19', '75', '02', '02', '43', '21']);
}

/** @return list<string> */
function fr_data_files(string $dir): array
{
    $out = [];
    if (!is_dir($dir)) {
        return $out;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $path = $file->getPathname();
        // SQLite skriver journalen bredvid databasen. Den är ingen undo- eller loggfil.
        if (str_ends_with($path, '-wal') || str_ends_with($path, '-shm') || str_ends_with($path, '-journal')) {
            continue;
        }
        $out[] = $path;
    }
    sort($out);

    return $out;
}

function fr_no_pnr(string $hay, string $label): void
{
    $bad = $hay !== '' && (str_contains($hay, fr_digits()) || str_contains($hay, fr_digits_b()));
    $normA = function_exists('jeab_pnr_normalize') ? jeab_pnr_normalize(fr_digits()) : '';
    $normB = function_exists('jeab_pnr_normalize') ? jeab_pnr_normalize(fr_digits_b()) : '';
    if ($normA !== '') {
        $bad = $bad || str_contains($hay, $normA);
    }
    if ($normB !== '') {
        $bad = $bad || str_contains($hay, $normB);
    }
    fr_assert(!$bad, $label);
}

$rotDir = $tmp . '/rot';
@mkdir($rotDir, 0775, true);
file_put_contents($rotDir . '/data_scraping.php', <<<'PHP'
<?php
class LantmaterietAPI {
    public $data = [];
    public function search($addressText, $uuid = '') {
        $t = (string) $addressText;
        if (stripos($t, 'Flottarestigen') === false) {
            return false;
        }
        $this->data = [[
            'fastighetsbeteckning' => (string) ($GLOBALS['FR_LM_FAST'] ?? ''),
            'matchedAddress' => (string) ($GLOBALS['FR_LM_ADDR'] ?? 'Flottarestigen 6, 191 41 Sollentuna'),
            'postnummer' => (string) ($GLOBALS['FR_LM_POST'] ?? '19141'),
            'objektidentitet' => (string) ($GLOBALS['FR_LM_ID'] ?? ''),
            'postort' => 'Sollentuna',
            'agare' => [
                'namn' => (string) ($GLOBALS['FR_LM_NAME'] ?? ''),
                'personnummer' => (string) ($GLOBALS['FR_LM_PNR'] ?? ''),
                'andel' => '1/1',
            ],
        ]];
        return true;
    }
}
PHP);
$GLOBALS['_JEAB_ROT_DATA_SCRAPING_CANDIDATES'] = [$rotDir . '/data_scraping.php'];
$GLOBALS['FR_LM_FAST'] = 'Sollentuna Flottan 6:1';
$GLOBALS['FR_LM_NAME'] = 'Fel Ägare';
$GLOBALS['FR_LM_PNR'] = fr_digits();

$store = new JeabJsonStore($tmp . '/store.json');
$GLOBALS['_JEAB_ACTIVE_STORE'] = $store;
$undoPdo = new PDO('sqlite::memory:');
$undoPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$undoPdo->exec('CREATE TABLE settings ("key" TEXT PRIMARY KEY, value TEXT NOT NULL)');
$GLOBALS['_JEAB_FASTIGHET_UNDO_PDO'] = $undoPdo;
jeab_ordrar_external_live(false);

$lead = jeab_create_reminder($store, 'Fel Ägare', '2026-10-22 00:00:00', null, 77, [
    'customer' => 'Fel Ägare',
    'company' => 'jarfalla',
    'tags' => ['vunnen'],
    'address' => 'Flottarestigen 6, 191 41 Sollentuna',
    'ort' => 'Sollentuna',
    'contact_id' => 'c-flot',
]);
$id = (int) ($lead['id'] ?? 0);
fr_assert($id > 0, 'påminnelsen finns');

$other = jeab_create_reminder($store, 'Annan', '2026-10-22 00:00:00', null, null, [
    'customer' => 'Annan',
    'company' => 'jarfalla',
    'address' => 'Annanvägen 9, Sollentuna',
    'ort' => 'Sollentuna',
]);
$otherId = (int) ($other['id'] ?? 0);
jeab_purchase_put([
    'reminder_id' => $otherId,
    'kind' => 'laddbox',
    'company' => 'jarfalla',
    'name' => 'Annan',
    'address' => 'Annanvägen 9, Sollentuna',
    'ort' => 'Sollentuna',
    'fastighetsbeteckning' => 'ANNAN 1:1',
    'booking_id' => 0,
    'contact_id' => '',
]);

jeab_kontakter_mutate(function (array &$contacts): void {
    $contacts['c-flot'] = [
        'id' => 'c-flot',
        'street' => 'Flottarestigen 6',
        'postal_code' => '19141',
        'city' => 'Sollentuna',
        'fastighetsbeteckning' => 'FELSTAD 1:1',
        'owners' => [[
            'name' => 'Fel Ägare',
            'personnummer' => fr_digits(),
            'contact' => true,
            'email' => 'fel@example.com',
            'phone' => '0701111111',
        ]],
        'created_at' => '2026-10-22 08:00:00',
        'updated_at' => '2026-10-22 08:00:00',
    ];
});

$pnrStored = jeab_ordrar_format_pnr(fr_digits());
jeab_purchase_put([
    'reminder_id' => $id,
    'kind' => 'laddbox',
    'company' => 'jarfalla',
    'name' => 'Fel Ägare',
    'huvudsokande' => 'Fel Ägare',
    'personnummer' => $pnrStored,
    'address' => 'Flottarestigen 6, 191 41 Sollentuna',
    'ort' => 'Sollentuna',
    'fastighetsbeteckning' => 'FELSTAD 1:1',
    'owners' => [[
        'name' => 'Fel Ägare',
        'personnummer' => $pnrStored,
        'address' => 'Flottarestigen 6, 191 41 Sollentuna',
        'andel' => '1/1',
        'lagfart' => '',
    ]],
    'medsokande' => [],
    'booking_id' => 77,
    'contact_id' => 'c-flot',
    'install_note' => 'lämna mig',
    'fortnox_order' => 'FX-1',
]);

$only = jeab_fastighet_diff(
    ['fastighetsbeteckning' => 'FELSTAD 1:1', 'owners' => [[
        'name' => 'Fel Ägare', 'personnummer' => $pnrStored, 'andel' => '1/1',
    ]]],
    ['fastighetsbeteckning' => 'Sollentuna Flottan 6:1', 'owners' => [[
        'name' => 'Fel Ägare', 'personnummer' => $pnrStored, 'andel' => '1/1',
    ]], 'lookup_empty' => false],
    jeab_fastighet_stored_people(['owners' => [[
        'name' => 'Fel Ägare', 'personnummer' => $pnrStored, 'andel' => '1/1',
    ]]]),
    jeab_fastighet_suggest_people(['owners' => [[
        'name' => 'Fel Ägare', 'personnummer' => $pnrStored, 'andel' => '1/1',
    ]], 'lookup_empty' => false, 'fastighetsbeteckning' => 'Sollentuna Flottan 6:1'])
);
fr_assert(array_keys($only) === ['fastighetsbeteckning'], 'diffen har bara fastighetsbeteckningen när ägaren är samma');
fr_assert(!array_key_exists('owners', $only) && !array_key_exists('personnummer', $only) && !array_key_exists('address', $only), 'oförändrade fält saknas i diffen');
fr_no_pnr((string) json_encode($only, JSON_UNESCAPED_UNICODE), 'diffen utan ägarbyte saknar personnummer');

$found = jeab_order_find(['q' => 'Flottarestigen 6']);
fr_assert(($found['ok'] ?? false) === true && count($found['matches'] ?? []) === 1, 'order.find hittar Flottarestigen 6');
$match = $found['matches'][0];
fr_assert(array_keys($match) === ['id', 'address'], 'order.find lämnar bara id och adress');
fr_assert((int) $match['id'] === $id && str_contains((string) $match['address'], 'Flottarestigen 6'), 'träffen är rätt order');
$foundJson = (string) json_encode($found, JSON_UNESCAPED_UNICODE);
fr_assert(!str_contains($foundJson, 'Fel Ägare') && !str_contains($foundJson, 'fel@example.com') && !str_contains($foundJson, 'FX-1'), 'order.find tar inte med andra fält');
fr_no_pnr($foundJson, 'order.find saknar personnummer');

$beforeOrder = jeab_purchase_get($id);
$beforeContact = jeab_kontakt_get('c-flot');
$beforeReminder = jeab_get_reminder($store, $id);
$beforeLog = jeab_fastighet_log_text();

jeab_ordrar_external_live(true);
$dry = jeab_order_fastighet_refresh($store, ['address' => 'Flottarestigen 6']);
$dryJson = (string) json_encode($dry, JSON_UNESCAPED_UNICODE);
fr_assert(($dry['ok'] ?? false) === true && ($dry['dry'] ?? 0) === 1 && ($dry['written'] ?? 1) === 0, 'dry=1 är standard och skriver inte');
fr_assert(array_keys($dry['diff'] ?? []) === ['fastighetsbeteckning'], 'uppslaget diffar bara ändrad fastighetsbeteckning');
fr_assert(($dry['diff']['fastighetsbeteckning']['old'] ?? '') !== ($dry['diff']['fastighetsbeteckning']['new'] ?? ''), 'diffen visar gammalt och nytt');
fr_assert(!isset($dry['diff']['owners']) && !isset($dry['diff']['install_note']) && !isset($dry['diff']['booking_id']), 'samma ägare och övriga fält ligger inte i diffen');
fr_no_pnr($dryJson, 'dry-svaret saknar personnummer');
fr_assert(json_encode(jeab_purchase_get($id)) === json_encode($beforeOrder), 'dry rör inte ordern');
fr_assert(json_encode(jeab_kontakt_get('c-flot')) === json_encode($beforeContact), 'dry rör inte kontakten');
fr_assert(json_encode(jeab_get_reminder($store, $id)) === json_encode($beforeReminder), 'dry rör inte påminnelsen');
fr_assert(!is_file($tmp . '/fastighet-undo.json'), 'dry sparar ingen undo-fil');
fr_assert(jeab_fastighet_log_text() === $beforeLog, 'dry skriver ingen logg');
$dryUndoRows = 0;
try {
    $dryUndoRows = (int) $undoPdo->query('SELECT COUNT(*) FROM fastighet_undo')->fetchColumn();
} catch (Throwable) {
    $dryUndoRows = 0;
}
fr_assert($dryUndoRows === 0, 'dry sparar ingen undo-rad');
$beforeFiles = fr_data_files($tmp);

$done = jeab_order_fastighet_refresh($store, ['order_id' => $id, 'dry' => 0]);
$doneJson = (string) json_encode($done, JSON_UNESCAPED_UNICODE);
fr_assert(($done['ok'] ?? false) === true && ($done['written'] ?? 0) === 1 && ($done['dry'] ?? 1) === 0, 'dry=0 skriver');
fr_assert(trim((string) ($done['batch_id'] ?? '')) !== '', 'svaret har ett batch-id');
fr_no_pnr($doneJson, 'apply-svaret saknar personnummer');
$saved = jeab_purchase_get($id);
fr_assert(is_array($saved), 'ordern finns kvar');
fr_assert((string) ($saved['fastighetsbeteckning'] ?? '') !== 'FELSTAD 1:1', 'fastighetsbeteckningen uppdaterades');
fr_assert((string) ($saved['install_note'] ?? '') === 'lämna mig', 'anteckningen ligger kvar');
fr_assert((int) ($saved['booking_id'] ?? 0) === 77, 'bokningen ligger kvar');
fr_assert((string) ($saved['contact_id'] ?? '') === 'c-flot', 'kontaktlänken ligger kvar');
fr_assert((string) ($saved['fortnox_order'] ?? '') === 'FX-1', 'fortnox-ordern ligger kvar');
fr_assert((int) ($saved['reminder_id'] ?? 0) === $id, 'påminnelselänken ligger kvar');
fr_assert((string) ($saved['owners'][0]['name'] ?? '') === 'Fel Ägare', 'oförändrad ägare skrevs inte om');
fr_assert((string) ($saved['owners'][0]['personnummer'] ?? '') === $pnrStored, 'oförändrat personnummer skrevs inte om');
$contact = jeab_kontakt_get('c-flot');
fr_assert(is_array($contact) && (string) ($contact['id'] ?? '') === 'c-flot', 'kontakten har samma id');
fr_assert((string) ($contact['street'] ?? '') === 'Flottarestigen 6', 'kontaktens gata ligger kvar');
fr_assert((string) ($contact['owners'][0]['email'] ?? '') === 'fel@example.com', 'kontaktens e-post ligger kvar');
fr_assert((string) ($contact['owners'][0]['name'] ?? '') === 'Fel Ägare', 'kontaktens ägarnamn skrevs inte om');
fr_assert((string) ($contact['fastighetsbeteckning'] ?? '') === (string) ($saved['fastighetsbeteckning'] ?? ''), 'kontakten fick samma fastighetsbeteckning');
$rem = jeab_get_reminder($store, $id);
fr_assert((int) ($rem['booking_id'] ?? 0) === 77 && (string) ($rem['contact_id'] ?? '') === 'c-flot', 'påminnelsens bokning och kontakt ligger kvar');
$log = jeab_fastighet_log_text();
fr_assert(str_contains($log, 'apply') && str_contains($log, (string) $done['batch_id']) && str_contains($log, 'fastighetsbeteckning'), 'loggen har batchen och fältet');
fr_assert(!str_contains($log, 'owners'), 'oförändrade ägare loggas inte');
fr_no_pnr($log, 'loggen saknar personnummer');
$errLog = (string) @file_get_contents($tmp . '/app-errors.jsonl');
fr_no_pnr($errLog, 'felloggen saknar personnummer');

$undone = jeab_order_fastighet_undo($store, ['batch_id' => (string) $done['batch_id']]);
$undoJson = (string) json_encode($undone, JSON_UNESCAPED_UNICODE);
fr_assert(($undone['ok'] ?? false) === true, 'undo gick');
fr_no_pnr($undoJson, 'undo-svaret saknar personnummer');
$back = jeab_purchase_get($id);
fr_assert((string) ($back['fastighetsbeteckning'] ?? '') === 'FELSTAD 1:1', 'undo lade tillbaka fastighetsbeteckningen');
fr_assert((int) ($back['booking_id'] ?? 0) === 77 && (string) ($back['contact_id'] ?? '') === 'c-flot', 'undo rör inte länkarna');
fr_assert((string) ($back['install_note'] ?? '') === 'lämna mig', 'undo rör inte anteckningen');
$contactBack = jeab_kontakt_get('c-flot');
fr_assert((string) ($contactBack['fastighetsbeteckning'] ?? '') === 'FELSTAD 1:1', 'undo lade tillbaka kontakten');
fr_assert((string) ($contactBack['owners'][0]['email'] ?? '') === 'fel@example.com', 'undo behöll e-posten');
fr_assert(json_encode(jeab_get_reminder($store, $id)) === json_encode($beforeReminder), 'undo rör inte påminnelsen');
$log2 = jeab_fastighet_log_text();
fr_no_pnr($log2, 'loggen efter undo saknar personnummer');
$afterFirst = fr_data_files($tmp);
fr_assert(array_values(array_diff($afterFirst, $beforeFiles)) === [], 'första apply+undo skapar ingen fil under data');
$beforeFiles = $afterFirst;

$GLOBALS['FR_LM_NAME'] = 'Rätt Ägare';
$GLOBALS['FR_LM_PNR'] = fr_digits_b();
$both = jeab_order_fastighet_refresh($store, ['order_id' => $id, 'dry' => 1]);
$bothKeys = array_keys($both['diff'] ?? []);
sort($bothKeys);
fr_assert($bothKeys === ['fastighetsbeteckning', 'owners'], 'både fastighet och ägare diffas när båda ändrats');
fr_assert(($both['written'] ?? 1) === 0, 'den andra dry-körningen skriver inte');
$bothJson = (string) json_encode($both, JSON_UNESCAPED_UNICODE);
fr_no_pnr($bothJson, 'ägardiffen saknar personnummer');
fr_assert(!str_contains($bothJson, 'personnummer'), 'svaret har inget personnummerfält');
$ownerNames = array_map(static fn(array $o): string => (string) ($o['name'] ?? ''), $both['diff']['owners']['new'] ?? []);
fr_assert($ownerNames === ['Rätt Ägare'], 'nya ägaren visas med namn');

$beforeFiles = fr_data_files($tmp);
$ownerApply = jeab_order_fastighet_refresh($store, ['order_id' => $id, 'dry' => 0]);
fr_assert(($ownerApply['written'] ?? 0) === 1, 'ägarbytet skrivs');
$withOwner = jeab_purchase_get($id);
fr_assert((string) ($withOwner['owners'][0]['name'] ?? '') === 'Rätt Ägare', 'nya ägaren sparades på ordern');
fr_assert((string) ($withOwner['owners'][0]['personnummer'] ?? '') === jeab_ordrar_format_pnr(fr_digits_b()), 'nytt personnummer sparades på ordern');
fr_assert((int) ($withOwner['booking_id'] ?? 0) === 77 && (string) ($withOwner['contact_id'] ?? '') === 'c-flot', 'ägarbytet rör inte länkarna');
$ownerUndo = jeab_order_fastighet_undo($store, ['batch_id' => (string) ($ownerApply['batch_id'] ?? '')]);
fr_assert(($ownerUndo['ok'] ?? false) === true, 'ägarbytet kan ångras');
$ownerBack = jeab_purchase_get($id);
fr_assert((string) ($ownerBack['owners'][0]['name'] ?? '') === 'Fel Ägare', 'undo lade tillbaka ägarnamnet');
fr_assert((string) ($ownerBack['owners'][0]['personnummer'] ?? '') === $pnrStored, 'undo lade tillbaka personnumret');
fr_assert((string) ($ownerBack['fastighetsbeteckning'] ?? '') === 'FELSTAD 1:1', 'undo lade tillbaka fastigheten efter ägarbyte');
fr_no_pnr((string) json_encode($ownerApply, JSON_UNESCAPED_UNICODE), 'ägarapply-svaret saknar personnummer');
fr_no_pnr((string) json_encode($ownerUndo, JSON_UNESCAPED_UNICODE), 'ägarundo-svaret saknar personnummer');
fr_no_pnr(jeab_fastighet_log_text(), 'loggen efter ägarundo saknar personnummer');
$afterFiles = fr_data_files($tmp);
$added = array_values(array_diff($afterFiles, $beforeFiles));
fr_assert($added === [], 'apply+undo skapar ingen fil under data');
fr_assert(!is_file($tmp . '/fastighet-undo.json') && !is_file($tmp . '/fastighet-refresh.log'), 'undo-filen och loggfilen saknas');
fr_assert(jeab_settings_value($undoPdo, 'sqlite', 'fastighet_undo_schema_v1') === 'ok', 'schemaflaggan är satt');
fr_assert((int) ($GLOBALS['_JEAB_FASTIGHET_UNDO_DDL'] ?? 0) === 1, 'tabellen skapas en gång');
$flagged = new PDO('sqlite::memory:');
$flagged->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$flagged->exec('CREATE TABLE settings ("key" TEXT PRIMARY KEY, value TEXT NOT NULL)');
jeab_settings_put($flagged, 'sqlite', 'fastighet_undo_schema_v1', 'ok');
$ddlBefore = (int) ($GLOBALS['_JEAB_FASTIGHET_UNDO_DDL'] ?? 0);
jeab_ensure_fastighet_undo($flagged, 'sqlite');
jeab_ensure_fastighet_undo($flagged, 'sqlite');
fr_assert((int) ($GLOBALS['_JEAB_FASTIGHET_UNDO_DDL'] ?? 0) === $ddlBefore, 'flaggan hoppar över DDL');
$skipped = false;
try {
    $flagged->query('SELECT 1 FROM fastighet_undo');
} catch (Throwable) {
    $skipped = true;
}
fr_assert($skipped, 'en satt flagga kör inte CREATE TABLE');
$storedUndo = (string) $undoPdo->query('SELECT order_json FROM fastighet_undo ORDER BY created_at')->fetchColumn();
fr_assert($storedUndo !== '' && str_contains($storedUndo, $pnrStored), 'ångringsraden behåller orderns personnummer');
fr_no_pnr(jeab_fastighet_log_text(), 'loggtabellen saknar personnummer');
$src = (string) file_get_contents(dirname(__DIR__) . '/src/fastighet_refresh.php');
fr_assert(!str_contains($src, 'fastighet-undo.json') && !str_contains($src, 'file_put_contents'), 'koden skriver ingen undo-fil');

$hook = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
fr_assert(str_contains($hook, "=== 'order.fastighet_refresh'") && str_contains($hook, "=== 'order.find'") && str_contains($hook, "=== 'order.fastighet_undo'"), 'hook.php tar refresh, find och undo');

$undoBefore = (int) $undoPdo->query('SELECT COUNT(*) FROM fastighet_undo')->fetchColumn();
$badOverride = jeab_order_fastighet_refresh($store, [
    'order_id' => $id,
    'dry' => 0,
    'fastighetsbeteckning' => 'ANNAN 9:9',
    'objektidentitet' => 'id-66',
]);
fr_assert(($badOverride['ok'] ?? true) === false && ($badOverride['error'] ?? '') === 'inte exakt' && ($badOverride['written'] ?? 1) === 0, 'fel beteckning skrivs inte');
fr_assert((int) $undoPdo->query('SELECT COUNT(*) FROM fastighet_undo')->fetchColumn() === $undoBefore, 'avvisad beteckning skapar ingen ångring');
fr_assert((string) (jeab_purchase_get($id)['fastighetsbeteckning'] ?? '') === 'FELSTAD 1:1', 'avvisad beteckning lämnar ordern');

$GLOBALS['FR_LM_ADDR'] = 'Flottarestigen 66, 191 41 Sollentuna';
$prefixOverride = jeab_order_fastighet_refresh($store, [
    'order_id' => $id,
    'dry' => 0,
    'fastighetsbeteckning' => 'Sollentuna Flottan 6:1',
    'objektidentitet' => 'id-66',
]);
fr_assert(($prefixOverride['written'] ?? 1) === 0 && ($prefixOverride['error'] ?? '') === 'inte exakt', '66 mot orderns 6 skrivs inte');
$GLOBALS['FR_LM_ADDR'] = 'Flottarestigen 6, 191 41 Sollentuna';
fr_assert((string) (jeab_purchase_get($id)['fastighetsbeteckning'] ?? '') === 'FELSTAD 1:1', 'prefix-träffen lämnar ordern');

$overrideDry = jeab_order_fastighet_refresh($store, [
    'order_id' => $id,
    'fastighetsbeteckning' => 'Sollentuna Flottan 6:1',
    'objektidentitet' => 'id-6',
]);
fr_assert(($overrideDry['ok'] ?? false) === true && ($overrideDry['dry'] ?? 0) === 1 && ($overrideDry['written'] ?? 1) === 0, 'override i dry skriver inte');
fr_assert(($overrideDry['diff']['fastighetsbeteckning']['new'] ?? '') === 'SOLLENTUNA FLOTTAN 6:1', 'override visar den validerade beteckningen');
fr_assert(($overrideDry['diff']['objektidentitet']['new'] ?? '') === 'id-6', 'override visar objektidentiteten');
$overrideApply = jeab_order_fastighet_refresh($store, [
    'order_id' => $id,
    'dry' => 0,
    'fastighetsbeteckning' => 'Sollentuna Flottan 6:1',
    'objektidentitet' => 'id-6',
]);
fr_assert(($overrideApply['written'] ?? 0) === 1 && trim((string) ($overrideApply['batch_id'] ?? '')) !== '', 'override skrivs med ångringsbatch');
$overrideSaved = jeab_purchase_get($id);
fr_assert((string) ($overrideSaved['fastighetsbeteckning'] ?? '') === 'SOLLENTUNA FLOTTAN 6:1', 'override sparar beteckningen');
fr_assert((string) ($overrideSaved['objektidentitet'] ?? '') === 'id-6', 'override sparar objektidentiteten');
$overrideUndo = jeab_order_fastighet_undo($store, ['batch_id' => (string) $overrideApply['batch_id']]);
fr_assert(($overrideUndo['ok'] ?? false) === true, 'override kan ångras');
$overrideBack = jeab_purchase_get($id);
fr_assert((string) ($overrideBack['fastighetsbeteckning'] ?? '') === 'FELSTAD 1:1', 'undo lade tillbaka beteckningen efter override');
fr_assert(trim((string) ($overrideBack['objektidentitet'] ?? '')) === '', 'undo tog bort objektidentiteten');
fr_no_pnr((string) json_encode($overrideApply, JSON_UNESCAPED_UNICODE), 'override-svaret saknar personnummer');
fr_no_pnr((string) json_encode($overrideUndo, JSON_UNESCAPED_UNICODE), 'override-undo saknar personnummer');

jeab_ordrar_external_live(false);
$open = jeab_purchase_get($id);
$card = jeab_ordrar_v3_overview_html($open, 'jarfalla', $id, $store);
$step = ['id' => 'bekrafta', 'state' => 'todo', 'n' => 1, 'title' => 'Bekräfta', 'short' => '1'];
$bekrafta = jeab_ordrar_v3_bekrafta_html($open, 'jarfalla', $id, $step, $store, jeab_get_reminder($store, $id));
$status = jeab_ordrar_status_html($open, 'jarfalla', $id, $store);
fr_assert(substr_count($card, 'Hämta fastighetsinfo igen') === 1, 'orderkortet har knappen');
fr_assert(substr_count($bekrafta, 'Hämta fastighetsinfo igen') === 1, 'Bekräfta-kortet har knappen');
fr_assert(substr_count($status, 'Hämta fastighetsinfo igen') === 1, 'statuskortet har knappen');
$dialog = jeab_fastighet_refresh_dialog();
fr_assert(str_contains($dialog, 'Spara') && str_contains($dialog, 'Avbryt') && str_contains($dialog, 'data-fastighet-save'), 'dialogen sparar först på Spara');
$js = (string) file_get_contents(dirname(__DIR__) . '/assets/js/fastighet-refresh.js');
fr_assert(str_contains($js, "post(pending, true)") && str_contains($js, "post(pending, false)") && str_contains($js, '→'), 'knappen hämtar först och sparar sedan');
$page = (string) file_get_contents(dirname(__DIR__) . '/src/pages/ordrar.php');
fr_assert(substr_count($page, 'Hämta fastighetsinfo igen') === 0 && str_contains($page, 'jeab_fastighet_refresh_button'), 'Bekräfta-sidan ritar knappen');

echo "fastighet refresh: ok\n";
