<?php

declare(strict_types=1);

/** Luckor efter #456: anteckningsmönster, telefon som inte är postnummer, värden som bara finns i anteckningen. */

$tmp = sys_get_temp_dir() . '/jeab-note-gaps-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/layout.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function ng(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$end = 'Tel: E-post: Adress: Exempelgatan 12, 234 56 Lund, Sverige mail: https://flowstack.example.test/index.php?p=inkorg&id=g-end';
$mid = 'Tel: E-post: Adress: Exempelgatan 12, 234 56 Lund, Sverige mail: https://flowstack.example.test/index.php?p=inkorg&amp;id=g-mid och sedan en mening';
$issueEnd = jeab_inkorg_reminder_note_issue($end);
$issueMid = jeab_inkorg_reminder_note_issue($mid);
ng(!empty($issueEnd['hit']) && $issueEnd['url'] === 'https://flowstack.example.test/index.php?p=inkorg&id=g-end', 'mail: i slutet av raden hittas');
ng($issueEnd['address'] === 'Exempelgatan 12, 234 56 Lund', 'adressen före Sverige tas ut');
ng(!empty($issueMid['hit']) && $issueMid['url'] === 'https://flowstack.example.test/index.php?p=inkorg&id=g-mid', 'mail: mitt i raden, med &amp;, hittas');
ng(jeab_inkorg_reminder_has_empty_labels($end), 'tomma Tel och E-post på samma rad räknas');
ng(jeab_inkorg_reminder_field_is_inkorg_mail('mail: https://flowstack.example.test/index.php?p=inkorg&id=g-field'), 'e-postfältet kan vara mail: plus inkorgsadressen');

ng(!jeab_inkorg_skane_text('08-234 56 78'), '08-234 56 78 är inget postnummer');
ng(!jeab_inkorg_skane_text('070-234 56 78'), '070-234 56 78 är inget postnummer');
ng(!jeab_inkorg_meab_sol('jarfalla', 'sol', '08-234 56 78'), 'sol plus riktnummer blir inte MEAB');
ng(!jeab_inkorg_meab_sol('jarfalla', 'sol', '070-234 56 78'), 'sol plus mobil blir inte MEAB');
ng(jeab_inkorg_skane_postcode('234 56'), 'bara postnummerfältet 234 56 räknas');
ng(jeab_inkorg_skane_text('Norrskensvägen 12, 234 56 Lund'), 'postnummer efter gatunummer och före ort räknas');
ng(!jeab_inkorg_skane_text('Malmövägen 1'), 'Malmövägen är ingen Skåne-träff');

$due = '2026-10-14 09:00:00';
$store = jeab_store();
$endRow = jeab_create_reminder($store, 'Kund Slut', $due, null, null, [
    'customer' => 'Kund Slut',
    'company' => 'jarfalla',
    'who' => 'Roberto',
    'note' => $end,
]);
$midRow = jeab_create_reminder($store, 'Kund Mitt', $due, null, null, [
    'customer' => 'Kund Mitt',
    'company' => 'jarfalla',
    'who' => 'Roberto',
    'note' => $mid,
]);
$fieldRow = jeab_create_reminder($store, 'Kund Falt', $due, null, null, [
    'customer' => 'Kund Falt',
    'company' => 'jarfalla',
    'who' => 'Roberto',
    'email' => 'mail: https://flowstack.example.test/index.php?p=inkorg&id=g-field',
    'note' => 'Vill bli uppringd',
]);
$dry = jeab_inkorg_hook(['action' => 'inbox.reminder_epost']);
$ids = array_map('intval', (array) ($dry['ids'] ?? []));
sort($ids);
$want = [(int) $endRow['id'], (int) $midRow['id'], (int) $fieldRow['id']];
sort($want);
ng(($dry['dry'] ?? 0) === 1 && $ids === $want, 'torrkörningen hittar de tre syntetiska mönstren');
ng(!str_contains((string) json_encode($dry), '@'), 'torrkörningen har ingen e-post');

$wet = jeab_inkorg_hook(['action' => 'inbox.reminder_epost', 'dry' => 0]);
$endSaved = jeab_get_reminder($store, (int) $endRow['id']);
$midSaved = jeab_get_reminder($store, (int) $midRow['id']);
$fieldSaved = jeab_get_reminder($store, (int) $fieldRow['id']);
ng((string) ($endSaved['address'] ?? '') === 'Exempelgatan 12, Lund' && (string) ($endSaved['postal'] ?? '') === '23456' && (string) ($endSaved['ort'] ?? '') === 'Lund', 'slutraden fyller adress, postnummer och ort');
ng(!str_contains((string) ($endSaved['note'] ?? ''), 'Tel:') && !str_contains((string) ($endSaved['address'] ?? ''), 'Sverige') && !str_contains((string) ($endSaved['note'] ?? ''), 'mail:'), 'slutradens etiketter, Sverige och mail: är borta');
ng(str_contains((string) ($midSaved['note'] ?? ''), 'och sedan en mening') && !str_contains((string) ($midSaved['note'] ?? ''), 'mail:') && (string) ($midSaved['thread_id'] ?? '') === 'g-mid', 'texten efter länken står kvar och ärendet sparas');
ng(trim((string) ($fieldSaved['email'] ?? '')) === '' && (string) ($fieldSaved['note'] ?? '') === 'Vill bli uppringd', 'trasigt e-postfält töms och anteckningen står kvar');
ng(!str_contains((string) json_encode($wet), 'exempel.test') && !str_contains((string) json_encode($wet), 'mail:'), 'skrivsvaret har ingen kundtext');

$keep = jeab_create_reminder($store, 'Kund Behall', $due, null, null, [
    'customer' => 'Kund Behall',
    'company' => 'jarfalla',
    'who' => 'Roberto',
    'note' => "Tel: 070-111 22 33\nE-post: kvar@exempel.test\nAdress: Exempelgatan 3, 211 22 Malmö\nVill ha offert",
]);
$kept = jeab_update_reminder($store, (int) $keep['id'], ['note' => 'Vill ha offert']);
ng((string) ($kept['phone'] ?? '') === '+46701112233', 'telefonen i anteckningen flyttas till fältet');
ng((string) ($kept['email'] ?? '') === 'kvar@exempel.test', 'e-posten i anteckningen flyttas till fältet');
ng(str_contains((string) ($kept['address'] ?? ''), 'Exempelgatan 3') && !str_contains((string) ($kept['note'] ?? ''), 'Tel:') && (string) ($kept['note'] ?? '') === 'Vill ha offert', 'adressen flyttas och bara anteckningen står kvar');

$phrase = jeab_create_reminder($store, 'Kund Fras', $due, null, null, [
    'customer' => 'Kund Fras',
    'company' => 'jarfalla',
    'who' => 'Roberto',
    'note' => "Tel: ring ikväll\nVill ha offert",
]);
$phrased = jeab_update_reminder($store, (int) $phrase['id'], ['note' => 'Vill ha offert']);
ng(trim((string) ($phrased['phone'] ?? '')) === '' && str_contains((string) ($phrased['note'] ?? ''), 'Tel: ring ikväll'), 'text som inte är ett nummer får stå kvar');

$same = jeab_create_reminder($store, 'Kund Samma', $due, null, null, [
    'customer' => 'Kund Samma',
    'company' => 'jarfalla',
    'who' => 'Roberto',
    'phone' => '070-111 22 33',
    'note' => "Tel: 070-111 22 33\nHej",
]);
$samed = jeab_update_reminder($store, (int) $same['id'], ['note' => 'Hej']);
ng((string) ($samed['phone'] ?? '') === '+46701112233' && !str_contains((string) ($samed['note'] ?? ''), 'Tel:'), 'samma nummer tas bort ur anteckningen och står kvar i fältet');

$empty = jeab_create_reminder($store, 'Kund Tom', $due, null, null, [
    'customer' => 'Kund Tom',
    'company' => 'jarfalla',
    'who' => 'Roberto',
    'note' => "Tel:\nE-post:\nHej",
]);
$emptied = jeab_update_reminder($store, (int) $empty['id'], ['note' => 'Hej']);
ng(trim((string) ($emptied['phone'] ?? '')) === '' && trim((string) ($emptied['email'] ?? '')) === '' && (string) ($emptied['note'] ?? '') === 'Hej', 'tomma etiketter tas bort och inget fält hittas på');

echo "inkorg reminder note gaps ok\n";
