<?php

declare(strict_types=1);

/**
 * kontakter.relink_case på SQLite. Dry skriver inget. Write kräver backup, skapar ny kontakt,
 * tar bort bara den adressen från fel kontakt, pekar om tråden och inkorgsraden, skriver historik.
 * Andra körningen gör inget. Påhittade personer. Inga utskick.
 */

$tmp = sys_get_temp_dir() . '/jeab-relink-case-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = false;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
putenv('DATA_BACKEND=mysql');
putenv('CONTACTS_SOLE=0');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/inkorg_contacts.php';
require_once dirname(__DIR__) . '/src/kontakter_relink_case.php';

function rc(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$store = jeab_store();
rc($store instanceof JeabPdoStore, 'sqlite-lagret finns');
jeab_ensure_contacts_tables($store->pdo(), 'sqlite');
rc(jeab_contact_store() instanceof JeabPdoContactStore, 'kontakter i SQLite');

$hook = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
rc(str_contains($hook, "'kontakter.relink_case'") && str_contains($hook, 'jeab_kontakter_relink_case_dispatch'), 'hook.php registrerar kontakter.relink_case');

$tokenDir = dirname(jeab_inkorg_token_path());
if (!is_dir($tokenDir)) {
    mkdir($tokenDir, 0775, true);
}
file_put_contents(jeab_inkorg_token_path(), json_encode(['key' => 'tok-relink']));
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_AUTHORIZATION'] = '';
$denied = jeab_kontakter_relink_case_dispatch(['thread_id' => 'x']);
rc(($denied['status'] ?? 0) === 401, 'utan token ger 401');
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-relink';

$wrong = 'carola.prov@example.test';
$ing = jeab_inkorg_ingest([
    'message_id' => 'relink-1@example.test',
    'from' => $wrong,
    'from_name' => 'Carola Prov',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Solceller med batteri',
    'body_text' => "Hej, jag vill ha en offert.\n",
    'source' => 'graph-jarfalla',
    'thread_key' => 'relink-thread',
    'test' => true,
]);
rc(!empty($ing['ok']), 'tråden finns');
$tid = (string) ($ing['thread_id'] ?? '');

$ids = jeab_kontakter_mutate(static function (array &$contacts) use ($wrong): array {
    $a = jeab_kontakt_blank(['name' => 'Mats Prov', 'email' => $wrong, 'phone' => '0701234567', 'street' => 'Provgatan 134', 'street_key' => jeab_kontakt_street_key('Provgatan 134'), 'postal_code' => '19592', 'city' => 'Märsta', 'company' => 'jarfalla']);
    $a['owners'][] = ['name' => 'Mia Prov', 'email' => 'mia.prov@example.test', 'phone' => '', 'contact' => false];
    $contacts[$a['id']] = $a;
    $b = jeab_kontakt_blank(['name' => 'Annan Person', 'email' => 'annan@example.test']);
    $contacts[$b['id']] = $b;
    return [$a['id'], $b['id']];
});
[$from, $other] = $ids;
jeab_inkorg_thread_write($tid, static function (array $t) use ($from): array {
    $t['contact_id'] = $from;
    return $t;
});
jeab_kontakt_inbox_locked(static function (?array $d) use ($from, $wrong): array {
    $d = $d ?? ['contacts' => []];
    $d['contacts']['c-relink'] = ['id' => 'c-relink', 'name' => 'Carola Prov', 'emails' => [$wrong], 'contact_id' => $from];
    $d['contacts']['c-mia'] = ['id' => 'c-mia', 'name' => 'Mia Prov', 'emails' => ['mia.prov@example.test'], 'contact_id' => $from];
    return [null, $d];
});
$fromBefore = jeab_kontakt_get($from);
$otherBefore = jeab_kontakt_get($other);
$countBefore = count(jeab_kontakter_load());

$args = [
    'thread_id' => $tid,
    'from_contact_id' => $from,
    'remove_email' => $wrong,
    'new_name' => 'Carola Prov',
    'new_email' => $wrong,
    'new_address' => 'Provgatan 134',
    'postnummer' => '195 92',
    'ort' => 'Märsta',
];

$bad = jeab_kontakter_relink_case_dispatch(['remove_email' => 'nope@example.test'] + $args);
rc(($bad['ok'] ?? true) === false && str_contains((string) ($bad['error'] ?? ''), 'remove_email'), 'fel adress på from stoppas');
$bad = jeab_kontakter_relink_case_dispatch(['from_contact_id' => $other] + $args);
rc(($bad['ok'] ?? true) === false, 'tråd som inte pekar på from stoppas');
$bad = jeab_kontakter_relink_case_dispatch(['thread_id' => 'g-0000000000000000'] + $args);
rc(($bad['error'] ?? '') === 'not_found', 'okänd tråd stoppas');

$dry = jeab_kontakter_relink_case_dispatch($args);
rc(($dry['status'] ?? 0) === 200 && $dry['dry'] === true && $dry['written'] === false && $dry['would_change'] === 1, 'dry är standard');
rc($dry['contacts']['from']['before']['emails'] === [$wrong, 'mia.prov@example.test'], 'dry visar from före');
rc($dry['contacts']['from']['after']['emails'] === ['mia.prov@example.test'], 'dry visar from efter');
rc($dry['contacts']['new']['after']['name'] === 'Carola Prov' && $dry['contacts']['new']['after']['postal_code'] === '19592', 'dry visar ny kontakt');
rc($dry['thread']['before'] === $from && $dry['inbox_rows'] === ['c-relink'], 'dry visar tråd och inkorgsrad');
rc(jeab_kontakt_get($from) == $fromBefore && count(jeab_kontakter_load()) === $countBefore, 'dry skriver inget');
rc((string) (jeab_inkorg_thread_meta($tid)['contact_id'] ?? '') === $from, 'dry rör inte tråden');
rc(!is_file($tmp . '/kontakter-relink-case.jsonl'), 'dry skriver ingen historik');

file_put_contents($tmp . '/blocker', 'x');
$GLOBALS['_JEAB_PRIVAT_DIR'] = $tmp . '/blocker/privat';
unset($GLOBALS['_JEAB_CUTOVER_TREE_ZIP']);
$noZip = jeab_kontakter_relink_case_dispatch($args + ['write' => 1]);
rc(($noZip['ok'] ?? true) === false && ($noZip['written'] ?? true) === false && str_contains((string) ($noZip['error'] ?? ''), 'Backup'), 'write utan backup-zip stoppas');
rc(jeab_kontakt_get($from) == $fromBefore && count(jeab_kontakter_load()) === $countBefore && (string) (jeab_inkorg_thread_meta($tid)['contact_id'] ?? '') === $from, 'utan zip ändras inget');
$GLOBALS['_JEAB_PRIVAT_DIR'] = $tmp . '/privat';
$zipRoot = jeab_cutover_backup_root();
rc(str_starts_with($zipRoot, $tmp . '/'), 'zipen hamnar i testmappen');
$wet = jeab_kontakter_relink_case_dispatch($args + ['write' => 1]);
rc(($wet['ok'] ?? false) === true && $wet['written'] === true, 'write går igenom: ' . json_encode($wet['error'] ?? ''));
rc(($wet['backup_zip'] ?? '') !== '' && glob($zipRoot . '/*.zip') !== [], 'write kräver och använder backup-zip');
rc(is_file($tmp . '/backups/' . ($wet['backup'] ?? '-')), 'före-bild skriven');
$newId = (string) $wet['new_contact_id'];
rc(str_starts_with($newId, 'kt_') && $newId !== $from, 'ny kontakt skapad');
$new = jeab_kontakt_get($newId);
rc(jeab_kontakt_row_name($new) === 'Carola Prov' && jeab_kontakt_row_email($new) === $wrong, 'ny kontakt har namn och e-post');
rc(($new['street'] ?? '') === 'Provgatan 134' && ($new['postal_code'] ?? '') === '19592' && ($new['city'] ?? '') === 'Märsta', 'ny kontakt har adressen');
$fromAfter = jeab_kontakt_get($from);
rc(jeab_kontakter_relink_case_emails($fromAfter) === ['mia.prov@example.test'], 'bara den adressen borta från from');
rc(jeab_kontakt_row_name($fromAfter) === 'Mats Prov' && ($fromAfter['street'] ?? '') === 'Provgatan 134' && ($fromAfter['city'] ?? '') === 'Märsta', 'from behåller namn och adress');
rc(($fromAfter['owners'][0]['phone'] ?? '') === ($fromBefore['owners'][0]['phone'] ?? 'x') && ($fromAfter['owners'][1]['name'] ?? '') === 'Mia Prov', 'from behåller telefon och andra ägare');
rc(jeab_kontakt_get($other) == $otherBefore && count(jeab_kontakter_load()) === $countBefore + 1, 'övriga kontakter orörda');
$pdo = jeab_contact_store_pdo();
$st = $pdo->prepare('SELECT contact_id FROM contact_emails WHERE email = ?');
$st->execute([$wrong]);
rc($st->fetchAll(PDO::FETCH_COLUMN) === [$newId], 'contact_emails pekar bara på ny kontakt');
rc((string) (jeab_inkorg_thread_meta($tid)['contact_id'] ?? '') === $newId && $wet['thread']['after'] === $newId, 'tråden pekar på ny kontakt');
$inbox = jeab_kontakt_inbox_read();
rc(($inbox['c-relink']['contact_id'] ?? '') === $newId && ($inbox['c-mia']['contact_id'] ?? '') === $from, 'bara ärendets inkorgsrad ompekad');
$audit = (string) @file_get_contents($tmp . '/kontakter-relink-case.jsonl');
rc(substr_count($audit, "\n") === 1 && str_contains($audit, $newId) && str_contains($audit, $tid), 'historikrad skriven');
rc(str_contains((string) @file_get_contents($tmp . '/kontakter-dedupe.log'), '"rule":"relink_case"'), 'dedupe-loggen har raden');

$again = jeab_kontakter_relink_case_dispatch($args + ['write' => 1]);
rc(($again['ok'] ?? false) === true && $again['already'] === 1 && $again['changed'] === 0 && $again['new_contact_id'] === $newId, 'andra körningen gör inget');
rc(count(jeab_kontakter_load()) === $countBefore + 1 && substr_count((string) file_get_contents($tmp . '/kontakter-relink-case.jsonl'), "\n") === 1, 'ingen ny kontakt eller historikrad');
$againDry = jeab_kontakter_relink_case_dispatch($args);
rc($againDry['already'] === 1 && $againDry['would_change'] === 0, 'dry efteråt visar already');

// Halvklar körning: kontakterna klara men tråden pekar fortfarande på from. Write återupptar.
jeab_inkorg_thread_write($tid, static function (array $t) use ($from): array {
    $t['contact_id'] = $from;
    return $t;
});
$resume = jeab_kontakter_relink_case_dispatch($args + ['write' => 1]);
rc(($resume['ok'] ?? false) === true && $resume['reuse_existing'] === 1 && $resume['new_contact_id'] === $newId, 'halvklar körning återanvänder kontakten');
rc((string) (jeab_inkorg_thread_meta($tid)['contact_id'] ?? '') === $newId && count(jeab_kontakter_load()) === $countBefore + 1, 'tråden pekas om utan ny kontakt');

$src = (string) file_get_contents(dirname(__DIR__) . '/src/kontakter_relink_case.php');
rc(!preg_match('/jeab_(mail_send|send_mail|graph_send|sms_send)/', $src), 'källan skickar inget');
echo "Klart\n";
