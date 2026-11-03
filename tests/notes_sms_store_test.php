<?php

declare(strict_types=1);

/** Anteckningar och skickade SMS ligger i SQL. JSON är bara läsreserv. */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-notes-sms-store-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function ns(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

ns(jeab_driver() === 'sqlite', 'testet använder sqlite');
$store = jeab_store();
ns($store instanceof JeabPdoStore, 'PDO-hjälparen är öppen');
$pdo = $store->pdo();

$notesSrc = (string) file_get_contents(dirname(__DIR__) . '/src/notes_store.php');
$smsSrc = (string) file_get_contents(dirname(__DIR__) . '/src/sms_store.php');
$dbSrc = (string) file_get_contents(dirname(__DIR__) . '/src/db.php');
$hookSrc = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
$mailSrc = (string) file_get_contents(dirname(__DIR__) . '/src/system_mail.php');
ns(str_contains($notesSrc, 'CREATE TABLE IF NOT EXISTS notes'), 'notes-tabellen skapas i notes_store');
ns(str_contains($smsSrc, 'CREATE TABLE IF NOT EXISTS sms_sent'), 'sms-tabellen skapas i sms_store');
ns(!str_contains($dbSrc, 'CREATE TABLE IF NOT EXISTS notes') && !str_contains($dbSrc, 'sms_sent'), 'db.php har inte de nya tabellerna');
ns(str_contains($hookSrc, "'notes.import'") && str_contains($hookSrc, "'sms.import'"), 'hook registrerar import');
ns(str_contains($mailSrc, 'jeab_sms_sent_store()'), 'system_mail läser samma funktion');

$noteBody = '{"next_id":3,"notes":[{"id":2,"title":"Exempelanteckning","body":"Påhittad rad.","section":"faltnoteringar","event_at":"2026-10-20 09:00:00","created_at":"2026-10-20 09:00:00","updated_at":"2026-10-20 09:00:00","photos":[]}]}';
$notesPath = jeab_notes_path();
file_put_contents($notesPath, $noteBody);
$beforeNotes = (string) file_get_contents($notesPath);
$loaded = jeab_notes_load();
ns(($loaded['notes'][0]['title'] ?? '') === 'Exempelanteckning', 'oläst SQL faller tillbaka på notes.json');
ns((string) file_get_contents($notesPath) === $beforeNotes, 'läsning skriver inte notes.json');
ns((int) $pdo->query('SELECT COUNT(*) FROM notes')->fetchColumn() === 0, 'läsning skapar ingen SQL-rad');

$dryNotes = jeab_notes_import_dispatch([]);
ns(($dryNotes['ok'] ?? false) === true && (int) ($dryNotes['dry'] ?? 0) === 1 && (int) ($dryNotes['inserted'] ?? 0) === 1, 'notes.import saknar dry och är dry=1');
ns((string) file_get_contents($notesPath) === $beforeNotes, 'dry=1 lämnar notes.json');
ns((int) $pdo->query('SELECT COUNT(*) FROM notes')->fetchColumn() === 0, 'dry=1 skriver ingen anteckning');

$runNotes = jeab_notes_import_dispatch(['dry' => 0]);
ns((int) ($runNotes['dry'] ?? 1) === 0 && (int) ($runNotes['inserted'] ?? 0) === 1, 'notes.import dry=0 tar in raden');
ns((string) file_get_contents($notesPath) === $beforeNotes, 'importen skriver inte notes.json');
$fromSql = jeab_notes_load();
ns(($fromSql['notes'][0]['id'] ?? 0) === 2 && ($fromSql['notes'][0]['title'] ?? '') === 'Exempelanteckning', 'importen syns i SQL');

file_put_contents($notesPath, str_replace('Exempelanteckning', 'Andrad i json', $beforeNotes));
ns((jeab_notes_load()['notes'][0]['title'] ?? '') === 'Exempelanteckning', 'efter import är notes.json bara reserv');
$againNotes = jeab_notes_import_dispatch(['dry' => 0]);
ns((int) ($againNotes['inserted'] ?? 1) === 0, 'andra notes-importen sätter in noll');

jeab_notes_delete(2);
ns(jeab_notes_get(2) === null, 'raderad anteckning är borta');
$resurrect = jeab_notes_import_dispatch(['dry' => 0]);
ns((int) ($resurrect['inserted'] ?? 1) === 0 && jeab_notes_get(2) === null, 'ny import väcker inte raderad anteckning');

$created = jeab_notes_create(['title' => 'Ny exempelrad', 'body' => 'Sparad i SQL.']);
ns((int) ($created['id'] ?? 0) > 0 && !str_contains((string) file_get_contents($notesPath), 'Ny exempelrad'), 'ny anteckning skrivs inte till JSON');
jeab_notes_mutate(static function (array &$data) use ($created): null {
    foreach ($data['notes'] as $i => $row) {
        if (is_array($row) && (int) ($row['id'] ?? 0) === (int) $created['id']) {
            $row['contact_id'] = 'kontakt-fran';
            $data['notes'][$i] = $row;
        }
    }
    return null;
});
$linked = jeab_kontakt_repoint_notes('kontakt-fran', 'kontakt-till');
$rawContact = '';
foreach (jeab_notes_load()['notes'] as $row) {
    if (is_array($row) && (int) ($row['id'] ?? 0) === (int) $created['id']) {
        $rawContact = (string) ($row['contact_id'] ?? '');
    }
}
ns($linked === 1 && $rawContact === 'kontakt-till', 'ompekning träffar SQL utan notes.json');

$smsBody = json_encode([
    '4242' => [
        'starts_at' => '2026-10-20 09:00:00',
        'sms' => true,
        'sms_at' => '2026-10-19 12:00:00',
        'email' => false,
    ],
], JSON_UNESCAPED_UNICODE);
$smsPath = jeab_sms_sent_path();
file_put_contents($smsPath, $smsBody);
$beforeSms = (string) file_get_contents($smsPath);
$smsLoaded = jeab_sms_sent_store();
ns(!empty($smsLoaded['4242']['sms']) && (int) $pdo->query('SELECT COUNT(*) FROM sms_sent')->fetchColumn() === 0, 'oläst SQL faller tillbaka på sms-sent.json');
ns((string) file_get_contents($smsPath) === $beforeSms, 'läsning skriver inte sms-sent.json');

$drySms = jeab_sms_import_dispatch([]);
ns(($drySms['ok'] ?? false) === true && (int) ($drySms['dry'] ?? 0) === 1 && (int) ($drySms['inserted'] ?? 0) === 1, 'sms.import saknar dry och är dry=1');
ns((int) $pdo->query('SELECT COUNT(*) FROM sms_sent')->fetchColumn() === 0 && (string) file_get_contents($smsPath) === $beforeSms, 'dry=1 skriver varken SQL eller JSON');

$runSms = jeab_sms_import_dispatch(['dry' => 0]);
ns((int) ($runSms['inserted'] ?? 0) === 1 && (string) file_get_contents($smsPath) === $beforeSms, 'sms.import dry=0 tar in raden och lämnar JSON');
file_put_contents($smsPath, str_replace('2026-10-19 12:00:00', '1999-01-01 00:00:00', $beforeSms));
ns((string) (jeab_sms_sent_store()['4242']['sms_at'] ?? '') === '2026-10-19 12:00:00', 'efter import är sms-sent.json bara reserv');
$againSms = jeab_sms_import_dispatch(['dry' => '0']);
ns((int) ($againSms['inserted'] ?? 1) === 0, 'andra sms-importen sätter in noll');

jeab_sms_save_sent(4243, [
    'starts_at' => '2026-10-21 09:00:00',
    'email' => true,
    'email_at' => '2026-10-20 08:00:00',
    'email_version' => 'v1',
]);
jeab_sms_save_sent(4243, [
    'starts_at' => '2026-10-22 09:00:00',
    'sms' => true,
    'sms_at' => '2026-10-20 10:00:00',
]);
$kept = jeab_sms_sent_store()['4243'] ?? [];
ns(
    !empty($kept['email'])
        && (string) ($kept['email_at'] ?? '') === '2026-10-20 08:00:00'
        && (string) ($kept['email_version'] ?? '') === 'v1'
        && !empty($kept['sms'])
        && (string) ($kept['starts_at'] ?? '') === '2026-10-22 09:00:00',
    'mejlflaggan överlever att SMS skriver om raden'
);
ns(!str_contains((string) file_get_contents($smsPath), '4243'), 'ny sms-rad skrivs inte till JSON');
$names = array_map(static fn (array $col): string => (string) ($col['name'] ?? ''), $pdo->query('PRAGMA table_info(sms_sent)')->fetchAll());
ns(in_array('booking_id', $names, true) && in_array('payload', $names, true), 'sms_sent finns i sqlite');

echo "notes sms store ok\n";
